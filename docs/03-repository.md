# 03 — Repository

## Three parts

| Part | Holds |
| --- | --- |
| `RepositoryCore` | query assembly: `where`, `join*`, `with`, `union*`, `limit`, `select`, plus the binds |
| `RepositoryViewTrait` | reads: `find*`, `count`, `exists`, `rawFetch`, the `*OrThrow` variants |
| `RepositoryCrudTrait` | writes: `insert`, `insertBatch`, `update`, `delete`, `upsert`, `upsertBatch` |

The split is by responsibility, not by taste. The stereotypes in `Stereotype/` combine the
parts:

| Stereotype | Parts | For |
| --- | --- | --- |
| `Repository` | core + view + CRUD | the usual table repository |
| `RepositoryView` | core + view | read-only repositories, e.g. over views — must not inherit the write half |
| `RepositoryCrud` | core + CRUD | write-only repositories |
| `CteRepo` | core + view, `final`, config passed to the constructor | ad-hoc queries without a table of their own; hydrates `stdClass` unless a class is passed |

## Query state is per coroutine

`where()` and friends accumulate parts **on the object**, and a repository is usually a
container singleton. Under Swoole that would mean concurrent requests appending to the
same array.

`RepositoryCore::state()` returns:

- **outside a coroutine** — `$this`: plain property access, zero overhead, which is what
  FPM gets.
- **inside** — a `stdClass` held in a `WeakMap` that lives in the coroutine's context and
  is keyed by the repository's private `$stateKey` object, so each coroutine sees its own
  `sqlParts` and `entityClassName` for that repository.

Everything that reads or writes `sqlParts` or `entityClassName` must go through `state()`.
A direct `$this->sqlParts` is the bug this exists to prevent, and it will not show up in a
single-request test.

`clone` needs `__clone()` for the same reason. Outside a coroutine PHP copies the
properties; inside one the state is not a property, and a clone used to come out with an
empty query — `clone $base` of a repository filtered by user read every row, under Swoole
only. `__clone()` gives the clone a key of its own and a copy of the original's state (the
same depth PHP's `clone` gives the properties). A subclass that defines `__clone()` must call
`parent::__clone()`; anything new that keeps per-repository state in the map must be copied
there too.

## Binds

Values never reach SQL as text. `where()`, `join*()`, `with()`, `union*()` and `from()`
collect `CDOBind` objects; `binding()` merges more in, for the case where part of a
condition is a raw fragment; `useBind()` attaches them to the prepared statement, using
`bindTypedValue()` when the driver offers it and `bindValue()` otherwise.

The reason a repository — not a string — is passed to `join*()` and `with()` is exactly
this: a sub-repository brings its own binds along, and a string could not.

## Hydration

`getEntityClassName()` answers the configured entity, **except** when a custom `select()`
is active: an arbitrary column list may not match the entity's shape, so hydration falls
back to `stdClass`. The configured property is never mutated — the override lives in the
per-coroutine state, so a concurrent request is unaffected.

`findAll()` and friends accept an override for the odd case where the caller knows better.

## `*OrThrow`

`findByIdOrThrow()` and `findByOrThrow()` exist so application code stops writing
`if ($x === null) throw`. On a miss they raise `Flytachi\Winter\Ppa\Entity\EntityException`
(not `RepositoryException`, which is reserved for a failed query), with the message and
HTTP status taken from the `$message` and `HttpCode $httpCode = HttpCode::NOT_FOUND`
parameters. `HttpCode` and the exception traits come from `winter-base`, which also
provides `Runtime` — see the dependency table in [01 — Layers](01-layers.md).

## Adding a method

- **Reads go in the view trait, writes in the CRUD trait**, never in the core. The core
  assembles; it does not execute.
- **Every builder-based path calls `useBind()`** immediately after `prepare()`. Forgetting
  it produces a statement with unbound placeholders, which fails loudly — but only when the
  path is exercised, so write the test. `rawFetch()` is the exception: it ignores the
  builder and binds only the `$binds` it is given.
- **Every builder-based path calls `cleanCache()`** after `execute()`, so the next query on
  the object starts from an empty builder.
- **Every catch calls `PpaConnectionPool::reportFailure($this->dbConfigClassName, $th)`**
  before rethrowing as `RepositoryException`. That call is what evicts a dead connection;
  a method that skips it hands the same dead connection to the next query.
- **Return shapes follow the existing ones**: `null` for a miss, `array` for a list. A new
  method that invents its own convention makes the whole surface harder to remember than
  it is large.

## What writes return

| Method | Returns |
| --- | --- |
| `update()`, `delete()` | the number of affected rows |
| `insert()` | the generated id (`mixed`) — on PostgreSQL and MariaDB via `RETURNING` of the entity's first key (so put the id first), `lastInsertId()` elsewhere; `null` when nothing was generated |
| `upsert()` | the same shape, but `RETURNING` only on PostgreSQL (`null` when the conflict was ignored); on SQLite, MySQL and MariaDB it is `lastInsertId()`, which after a conflict is **not** the id of the conflicting row |
| `insertBatch()`, `upsertBatch()` | `void` |

`upsert()`/`upsertBatch()` with no `$updateColumns` (or `[]`) ignore a conflict — `DO
NOTHING` / `INSERT IGNORE`. To update, pass a column ⇒ expression map such as
`['name' => ':new']` (`:new` is the incoming value, `:current` the stored one); a plain
list of names is refused with a `RepositoryException`.
