# 03 — Repository

## Three parts

| Part | Holds |
| --- | --- |
| `RepositoryCore` | query assembly: `where`, `join*`, `with`, `union*`, `limit`, `select`, plus the binds |
| `RepositoryViewTrait` | reads: `find*`, `count`, `exists`, `rawFetch`, the `*OrThrow` variants |
| `RepositoryCrudTrait` | writes: `insert`, `insertBatch`, `update`, `delete`, `upsert`, `upsertBatch` |

The split is by responsibility, not by taste: `RepositoryView` exists as a stereotype for
read-only repositories over views, and it must not inherit the write half.

## Query state is per coroutine

`where()` and friends accumulate parts **on the object**, and a repository is usually a
container singleton. Under Swoole that would mean concurrent requests appending to the
same array.

`RepositoryCore::state()` returns:

- **outside a coroutine** — `$this`. Identical semantics to plain property access, zero
  overhead, which is what FPM gets.
- **inside** — a `stdClass` in the coroutine's context, keyed by this object's identity.

Everything that reads or writes `sqlParts` or `entityClassName` must go through `state()`.
A direct `$this->sqlParts` is the bug this exists to prevent, and it will not show up in a
single-request test.

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
`if ($x === null) throw`. They raise `RepositoryException`, which carries an HTTP status
through `winter-base`'s exception traits — that is the whole reason the dependency on
`winter-base` is there.

## Adding a method

- **Reads go in the view trait, writes in the CRUD trait**, never in the core. The core
  assembles; it does not execute.
- **Every execution path calls `useBind()`** immediately after `prepare()`. Forgetting it
  produces a statement with unbound placeholders, which fails loudly — but only when the
  path is exercised, so write the test.
- **Return shapes follow the existing ones**: `null` for a miss, `array` for a list, the
  affected count for writes. A new method that invents its own convention makes the whole
  surface harder to remember than it is large.
