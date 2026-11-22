# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Schema versions — a database's schema follows the entities, version by version.** The entities
  stay the only description of the schema; `Schema\SchemaSnapshot` freezes what changed since the
  last version into `ppa/<Config>/V<nnn>_<name>.json` (`V001`, `V002`… — the next one is the last
  number + 1, so the files sort as they apply) — only that version's changes — and
  `Schema\SchemaMigrator` brings a database to the last version: a fresh database is created from
  the last version's model at once, a database made before there was a history gets a baseline
  (the latest version it matches), any other gets the versions it lacks, each in a transaction of
  its own, its steps checked against the live database first (nothing is created to find out it
  exists — no "already exists" errors in the server's log), then the database checked against the
  version's model. Columns are added, renamed, changed (a widening — a longer VARCHAR, a wider
  integer or DECIMAL — by itself), dropped; indexes and constraints follow. It never guesses: a
  rename needs `#[RenamedFrom('old')]` on the property, a dropped column or table `--drop`, a
  column gone from the entity may be kept in the database (`--keep`), a NOT NULL column without a
  default is refused for a table with rows.
- **`VersionCode` — the code of a version** (`#[SchemaVersion('V007')]`, in `custom/` of the folder — the written files apart from the generated ones —
  in the namespace of its config, so a version of the same name in two databases loads in one process): `before()`
  on the old schema, `after()` on the new one and for what the kernel does not change itself
  (`"by": "code"`: a type narrowed, a primary key, a SQLite table rebuild). A version may be code
  only — data moved, no schema change. Not run on a fresh database: it has no rows to change.
- **`winter_ppa_schema_history`** in the database itself (in the config's schema on PostgreSQL),
  one history per owner — `(owner, version)`, the owner being the config's class name — so two
  configs or an application and a package share a database without their V001s meeting:
  version, name, checksum of its files, code, state, when, how long, by which database user, and a
  copy of the JSON it applied. A version edited after it was applied, a version whose files are
  gone, a version older than the last applied one — refused before anything runs.
- **A schema lock** — PostgreSQL advisory lock, MySQL/MariaDB `GET_LOCK` — so two instances
  starting at once migrate once.
- **`Schema\SchemaInspector`** — reads tables, columns, primary keys, indexes and constraints from
  a live database (PostgreSQL, MySQL, MariaDB, SQLite), leaving out what the server adds itself
  (MySQL's index for a foreign key, MariaDB's CHECK on a JSON column).
- **`RenamedFromCleaner`** — removes a `#[RenamedFrom]` the history has recorded, token by token.
- Tested on PostgreSQL 16, MariaDB 10.11, MySQL 8.0 and SQLite 3.54: `PPA_PG`, `PPA_MARIA`,
  `PPA_MYSQL` (`host:port:database:user:password`) enable the live scenarios, SQLite always runs.

- **Schemas and extensions in the versions.** A table in a PostgreSQL schema of its own (`shop.orders`)
  and an `#[Extension]` of the config are recorded by the snapshot (`createSchema`, `createExtension`,
  first in their version) and made first on a fresh database; one already there is not made again.
  Never dropped by a snapshot: an extension no longer declared is said and stays.
- **`Schema\SchemaCheck` — what a server would not build as declared, said before anything runs**:
  `call db snapshot` records nothing with an error, `call db sql` says it, `call db migrate` of 4.x
  creates nothing for that database. Not the mapping: an index made long ago never stops a start.
  Errors: GIN / GiST on MySQL / MariaDB; a partial unique index there (without its WHERE it would be
  unique over every row); a unique index of another method than B-tree; HASH of several columns on
  PostgreSQL; GIN / GiST on a plain column without its operator class or `btree_gin` / `btree_gist`;
  an operator class without its extension (`gin_trgm_ops` → `pg_trgm`); two indexes of one name.
  Notices: what a server leaves out and only costs speed or size.
- **`#[Index(include: [...])]` / `#[Unique(include: [...])]`** — PostgreSQL's covering index
  (`INCLUDE`); left out elsewhere, with a notice.

- **A table renamed — `#[RenamedFrom('old')]` on the entity class** (`'public.orders'` moves it to
  another schema too): one `renameTable` change, first in its version, the rows kept. What is named
  after the table is renamed with it, as a fresh database names it — its indexes and constraints
  (paired by their definition; renamed where the server can: indexes on PostgreSQL and MySQL,
  constraints on PostgreSQL, the rest remade), and on PostgreSQL the primary key, the sequences of
  its identities and the NOT NULL constraints of 18. Foreign keys of other tables pointing at it
  follow it, as the server does. Without the attribute a table renamed is refused, as before — a
  `--drop` would lose its rows. `snapshot clean` removes it once recorded. SQLite renames, moves to
  no schema, and remakes its indexes.
- **`--drop=extension:<name>`** — an extension no longer declared is dropped (`dropExtension`, last in
  its version, without CASCADE); without it, it stays and the snapshot says how to drop it.
- **`GENERATED BY DEFAULT` ↔ `ALWAYS` by the kernel** — `#[Id(always: true)]` turned on or off was
  left to a custom version's code; it changes no row, and PostgreSQL does it with `SET GENERATED`.
- **E2E on GitHub Actions** (`e2e/`, `.github/workflows/e2e.yml`): an application's life on a live
  PostgreSQL — six tables with every attribute, 22 schema versions (columns added, renamed,
  dropped; every index and foreign key remade; types widened and narrowed; NOT NULL over NULLs; a
  primary key changed; tables made and dropped; versions with code) over tables full of rows,
  applied one by one, all at once and from a random stop; the three end with the same schema.
  Then what must be refused. See `e2e/README.md`.

### Fixed

- **SQLite: a CHECK added to a table that exists was `ALTER TABLE … ADD CONSTRAINT`** — new to
  SQLite (3.54 has it; 3.40–3.46, what PHP ships on most servers and CI runners, says "syntax
  error"). A version is recorded once and applied by any SQLite, so it is now refused like a
  foreign key: a custom version rebuilds the table (`"by": "code"`); a table the version creates
  still gets it inside its CREATE TABLE. And the check after a version now compares SQLite's
  constraints too (by name, from its CREATE TABLE): a rebuild that forgot one fails the version.
- **PostgreSQL 18: a renamed column kept its NOT NULL constraint's old name** — 18 keeps NOT NULL as
  a constraint named after the column (`orders_user_id_not_null`) and does not rename it with the
  column, so an upgraded database and a fresh one differed. The rename now renames it too, when it
  is there; on 16 and before there is none and the step does nothing.
- **A PostgreSQL array column (`#[Type('TEXT[]')]`) failed the check after its version**: the
  server names it `_text`, the model `TEXT[]` — "expected text[], found _text". One spelling now.
- **The same version code met twice in a process was a fatal "cannot redeclare class"** — a
  history in another folder (a release beside the old one), or a class an autoloader loaded
  first. The same code is the class already there; other code under a name already taken is
  said, with what to do.
- **An index of another method than B-tree has it in its name** — `orders_note_gin_idx`,
  `users_email_hash_idx` — so a column may have a B-tree and a GIN index side by side: both were
  `<table>_<column>_idx`, the second failed with "already exists" and the schema model kept one.
  B-tree and unique names are as they were. **An existing HASH / GIN / GiST index gets a new
  name**: the next snapshot records it dropped and made again; `call db migrate` of 4.x makes the
  new one beside the old — drop the old by hand.
- **MySQL / MariaDB: `method:` wrote invalid SQL** (`ON t USING HASH (c)`, a syntax error) — and
  with the right one InnoDB builds a B-tree all the same. The index is now a B-tree, said by a
  notice; GIN / GiST are refused. An operator class, written into the column list (a syntax error
  on MySQL, MariaDB and SQLite), is left out there.
- **MySQL / MariaDB: `where:` was dropped without a word** — a partial unique index became unique
  over every row. A plain one is built whole with a notice, a unique one refused.
- **`#[Unique(method: …)]` was turned into a B-tree without a word** — now the method reaches the
  check, which refuses anything but B-tree.
- **`#[CheckEnum(name: …)]` was ignored**: the constraint got a hashed name. It gets the name.
- **`CREATE SCHEMA public`** — the always-there schema was created again by `call db migrate` of
  4.x, an "already exists" error in the server's log at every run. Not created; any other with
  `IF NOT EXISTS`.

### Limits of schema versions

**On MySQL and MariaDB a version is not atomic.** Their schema changes commit at once: a
version that fails half-way is recorded `failed`, and runs again from its start next time — its
done steps are skipped, its code must be safe to repeat.

**On SQLite** a version cannot change a column, add a foreign key to an existing table or drop a
constraint by itself: a snapshot says so and asks for a custom version (a table rebuild in
`after()`).

### Known issues (not fixed yet, to decide)

**`#[Unique(method: …)]` does not work.** The parameter is accepted, but `Unique::toObject()`
always builds a `BTREE` index (`src/Mapping/Attributes/Idx/Unique.php`): asking for `HASH`, `GIN`
or any other method silently gives a B-tree, and nothing says so. A unique index is a B-tree
almost everywhere anyway — PostgreSQL supports `UNIQUE` on btree only, MySQL has HASH indexes
in the MEMORY engine only — so the likely fix is to refuse a non-`BTREE` method with a clear
error, keeping the parameter for those who pass `BTREE` explicitly. The docs (entities page)
already say the parameter is ignored.

**`PpaConnectionPool::showDbConfigs()` docblock** says it serves "diagnostics / health checks";
the kernel's `/actuator/health` does not use it (it scans and builds fresh config instances).
The docblock should say diagnostics only.

## [1.3.0] — 2026-11-21

### Added

- **`PpaConnectionPool::releaseCurrent()` — a coroutine gives its connection back when it is
  done with the database, not only when it ends.** A coroutine kept the connection of its
  first query until it finished: right for a request, wrong for one that lives on long after
  its last query. A Server-Sent Events stream whose middleware read the session held a pooled
  connection for as long as the tab stayed open, and a few open tabs emptied the pool for
  every other request of the worker. Now the connections are returned at once and the next
  query in the coroutine borrows again; the defer that would have returned them does nothing.
  A connection inside a transaction is kept, with a warning: given back, it would hand the
  transaction to the next borrower or roll back writes still meant to be committed. Outside a
  coroutine it does nothing. The kernel calls it when a stream starts (winter-kernel 4.7.1).

### Changed

- Requires **winter-cpool `^1.3`** — a liveness probe that does not answer (a socket dropped
  in silence) is no longer waited for — and **winter-cdo `^4.1`** — PostgreSQL connections
  carry `tcp_user_timeout`, so a query on such a socket fails in 10 s instead of hanging for
  minutes, and a worker's stop no longer waits for it.

## [1.2.1] — 2026-10-08

### Removed

**`#[TextArray]`.** On PostgreSQL it declared a `TEXT[]` column that ppa could not use: a PHP
array is bound as JSON (`["a","b"]`), which `TEXT[]` rejects as a malformed array literal, and
a read hands back `{a,b}` as a string an `array` property cannot take. Elsewhere it was a JSON
column under another name. It has not been used in practice; use `#[Json]` for an array.

### Fixed

**A failed query no longer stays on the repository.** The builder was reset only after a
successful execute. A read that failed — the database briefly unavailable, an SQL error — or
an exception while building (`limit(0)`, a second `from()`, joining a repository without an
alias) left the query in place, and the next call on the repository ran it: a `findAll()`
without conditions read the old `WHERE` and `OFFSET` and silently returned the wrong rows, and
a `findById()` failed on every call, because the old condition's binds were parameters the new
statement did not have. Inside a coroutine this lasted to the end of the request; outside one —
a process, a daemon, the CLI — for the life of the process, since an injected repository is a
singleton. `find()`, `findColumn()`, `findAll()`, `count()` and `exists()` now reset the query
whether they succeed or fail; a builder method that refuses resets it before throwing; and
`Paginator::repo()`, `Paginator::cursor()` and `Wrapper::paginator()` reset the repository they
were given when the page fails — a malformed cursor or `?page=0` comes from a request. Also:
`where()` called again replaced the condition but kept its binds, so `where(a)->where(b)`
failed on execute; the binds go with the condition now, as they do with `cleanCache('where')`.
`limit(10)` after `limit(10, 20)` kept the offset; it starts from the top now. A subquery passed to
`join*()`, `from()`, `with()` or `union()` had its binds taken before its SQL was built, so one
that failed to build left them on the outer repository and every later query failed on a
parameter it did not have; the subquery is built first now, and a failure resets the query.
Verified on PostgreSQL 16, MySQL 8 and SQLite, outside a coroutine and inside one: 34 ways for a
call to fail (reads, writes, building, pagination), each followed by reads and writes on the
same repository. **Behaviour
change:** code that caught a failed read and ran the same repository again, counting on the
conditions still being there, must build the query again.

**Column defaults survive an apostrophe, and an array default works on SQLite.** A string
default was wrapped in quotes as it was, so `public string $publisher = "O'Reilly"` produced
`DEFAULT 'O'Reilly'` and a syntax error — the same inside a JSON default. An apostrophe is now
doubled (`'O''Reilly'`), the standard escape on every dialect. An array default had a form for
PostgreSQL (`'…'::jsonb`) and MySQL (`('…')`) only; SQLite failed with `UnhandledMatchError`
before any DDL came out — it now gets a plain JSON string literal, and an unsupported dialect
gets an exception that names it. Generated DDL verified by execution on PostgreSQL 16,
MySQL 8 and SQLite.

**`#[UuidPk]` creates its table on MySQL and SQLite.** Every dialect but PostgreSQL got
`DEFAULT UUID()`. MySQL accepts a function as a default only in parentheses, so `CREATE TABLE`
failed with a syntax error (1064); SQLite has no UUID function at all and failed the same way.
Only MariaDB, which takes both forms, worked. MySQL and MariaDB now get `DEFAULT (UUID())`, and
SQLite an expression that builds a version 4 UUID from `randomblob()`; a dialect with no known
way to generate one gets a `LogicException` naming it instead of MySQL's syntax. Verified by
execution on PostgreSQL 16, MySQL 8, MariaDB 10.11 and SQLite.

**No CHECK constraint is lost any more — several per column, and on the entity class.** A
column kept one check: `#[CheckEnum(Status::class), Check("status <> ''")]` emitted only the
last one, so the enum check silently disappeared, and `#[Check]` could not be repeated at all.
`#[Check]` is now repeatable, and every check on a property — `#[Check]` and `#[CheckEnum]`
alike — becomes a constraint of its own. `#[Check]` on the entity class was accepted and
ignored, so a rule over several columns (`end_at > start_at`) never reached the database; it is
now a table-level check. `Column` gains `$checks`; its `checkConstraint` parameter still works
(the check is put first in `$checks`) and the property shows the first check. A second foreign
key on one property (`#[ForeignKey]` next to `#[ForeignRepo]`) replaced the first silently; it
is now refused with a `LogicException` — a column references one table, and both keys would get
the same generated name. `#[CheckEnum]` escaped an apostrophe in a value with a backslash, which
PostgreSQL and SQLite read as a literal backslash and a broken statement; it is now doubled.

**Pagination refuses a page below one and a negative offset.** `Wrapper::paginator()` turned
`page: 0` (or a negative page) into a negative offset; `array_slice()` counts that from the
END, so an in-memory list came back from its tail while the meta reported page 0 with a "next"
of 1. `Paginator::array()` did the same with a negative `offset`, and `Paginator::repo()` only
failed inside the repository's own `limit()`. All three now throw `ValueError` up front —
`Page must be a positive integer (>= 1)` / `Offset must be a non-negative integer (>= 0)` —
the same way they already refused a size below one. A page number taken from a request should
be checked in the controller (`#[RequestParam, Min(1)] int $page`), which answers `422`.

**A joined repository's query is no longer dropped — and a nameless subquery is refused.**
Whether a repository passed to `join*()` became a subquery depended on how many parts it held
(more than one), with binds counting as a part. So on a repository without an alias a WHERE
without parameters (`Qb::isNull('deleted_at')`), a `select()` or a `limit()` was a single part
and **silently dropped** — the join read the bare table, soft-deleted rows included — while a
WHERE with a parameter became a subquery with no alias, which PostgreSQL and MySQL reject. The
choice is now made on what the repository holds: no query of its own → its table; a query →
an aliased subquery with its binds. A query without an alias throws `RepositoryException`
before anything reaches the database: the `ON` condition refers to an alias only the caller
knows. **Behaviour change:** code that joined such a repository without an alias — which
either failed on PostgreSQL/MySQL or lost its condition — now gets the exception; name the
repository with `instance('o')` or `->as('o')`. Also: a bare repository without an alias no
longer renders a double space before `ON`.

**`clone` of a repository keeps its query under Swoole.** Inside a coroutine the query state
(`where()`, joins, alias, `select()`…) lives in a per-coroutine map keyed by the repository,
not in its properties — so PHP's `clone` copied nothing and the clone came out empty. `clone
$base` of a repository filtered by user read every row of the table, silently and only under
Swoole: outside a coroutine (tests, CLI) the properties were copied and the filter held. The
map is now keyed by a private per-repository key, and `__clone()` gives the clone a key of its
own and a copy of the original's state, so a clone is the same query in every runtime and
independent of its original from then on. `Paginator::repo()`'s advice to clone a repository
before reusing it is now safe. A subclass that defines `__clone()` must call
`parent::__clone()`.

**`count()` counts the rows the query matches, whatever it is built of.** It used to replace
the SELECT list with `COUNT(…)`, keep every other part and read the first row. With
`groupBy()` that was the size of the first group, not the number of groups; with
`limit(2, 2)` the single count row was skipped by the OFFSET (0); a custom `select('a, b')`
became `COUNT(a, b)` and failed; a union counted its first part only; and on PostgreSQL an
`orderBy()` next to `COUNT(*)` failed outright. Now ORDER BY, LIMIT, OFFSET and FOR never take
part — a paged query counts its total — and a plain filter keeps the same direct SQL as before
(`SELECT COUNT(*) FROM users WHERE …`). A query with its own select, GROUP BY, HAVING or a
union is counted as a subquery, `SELECT COUNT(*) FROM (…) AS tmp`, as `Paginator::repo()`
already did; without a select of its own the inner query selects a constant, so a GROUP BY
over entity columns stays valid on PostgreSQL and strict MySQL. Verified on PostgreSQL 16,
MySQL 8 and SQLite.

### Documentation

The README, the internal `docs/` and the framework's PPA pages were audited against the code
and corrected: failing examples (`upsert()`/`upsertBatch()` with a plain `updateColumns`
list, `insertBatch()` given an array of rows, `select()` given an array), signatures and
return values (`getSql()`, `binding()`, `findById()` / `mapIdentifierColumnName()`,
`*OrThrow` → `EntityException`), per-dialect column types with a SQLite column, the real
limits of migration idempotency, and the fork, pool and telemetry behaviour of 1.2.0.

## [1.2.0] — 2026-10-08

### Fixed

**A lost connection is no longer reported as an open transaction.** pdo_pgsql answers
`inTransaction()` with `true` for a connection whose last query failed because the connection
died (its status is "unknown"). The 1.1.4 return-time reset therefore logged a dead connection
as "returned to the pool with an open transaction" before its rollback failed. It now rolls back
first: a rollback that goes through was a real transaction (ERROR, as before); one that fails is
a lost connection — a WARNING, and the connection is retired. Verified on PostgreSQL 16.

### Added

**`PpaConnectionPool::closeBeforeFork()` — a fork no longer costs the parent its session.**
A forked child gets a copy of every PDO the parent holds, socket included, and cannot let go
of it quietly: when the child drops the copy — in `reset()`, or simply by exiting — the driver
tells the server to close the session (Terminate on PostgreSQL, COM_QUIT on MySQL) over the
socket it shares with the parent. The parent's next query failed with "server closed the
connection unexpectedly" / "MySQL server has gone away". Reproduced on PostgreSQL 16 and
MariaDB 11, with a child that never touched the database as much as with one that did.
`closeBeforeFork()` is called in the parent right before `pcntl_fork()`: it closes the
non-coroutine connections so the child inherits nothing, and both sides reopen lazily. A
connection inside a transaction makes it throw `PpaPoolException` instead — closing would roll
the owner's work back, and keeping it would share the session. Only a connection that still
answers counts: a dead one (which pdo_pgsql also reports as in a transaction) is just closed. The Winter kernel calls it
before every fork it makes. The `reset()` docblock no longer claims that forgetting an
inherited connection leaves the parent's session alone.

**Pool telemetry from processes and daemon workers.** A pool lives in one process, and a web
worker is not the only process that has one: a managed process and every daemon worker keep a
pool of their own. Until now only web workers published, so `call db pool` showed nothing of
them — not even when a process's pool was the one saturated. Each source now publishes under a
kind and a name:

- `PoolTelemetry::enable(int|string $source, string $kind = PoolTelemetry::KIND_WEB)` —
  `KIND_WEB` (worker id), `KIND_PROCESS` (class), `KIND_DAEMON` (daemon and slot). The old
  `enable($workerId)` call is unchanged and still means a web worker.
- Records carry `kind` and `pid`. A web worker keeps its `worker.N` key; a process or a
  daemon worker is stored as `{kind}.{source}`, flattened to one file name. A record from an
  older writer reads as a web worker.
- `snapshot()` orders web workers first, then processes, then daemon workers.
- `aggregate(string|array|null $kinds = null)` — one kind, a list, or every source; the last
  is the number of connections the whole application holds open.
- `stop()` takes no argument any more (the process knows what it published as); the old
  `stop($workerId)` call still works.

The framework enables it for processes and daemon workers and stops the publisher when their
body is done — a repeating timer left behind would keep the process from ending.

## [1.1.4] — 2026-10-07

### Fixed

**A transaction left open no longer leaks into the next request.** A request that called
`beginTransaction()` and never reached `commit()`/`rollBack()` — an exception, an early
return, a request timeout — returned its connection to the pool with the transaction still
open. Every later borrower inherited it: plain writes went into that transaction and were
discarded with it while the request answered 200, and correct code failed on
`beginTransaction()` with "There is already an active transaction". Under FPM this could not
happen — the connection died with the request and the driver rolled back on close.
Reproduced against the real pool and SQLite before the fix.

`CdoConnectionFactory` now implements `ResettableConnectionFactory`: on return it rolls back an
open transaction and logs **ERROR** naming the config and the coroutine; a connection that
cannot be rolled back is retired. Rollback, not commit — committing would publish work its
author never finished. ERROR, not CRITICAL/ALERT — the pool heals itself; what is broken is
one code path in the application. The clean case costs no round trip (`inTransaction()` is
the driver's own state).

Not covered: the non-coroutine path (`SingleConnection` in FPM/CLI/plain processes) has no
return point to reset at.

Requires `flytachi/winter-cpool` `^1.2` (the constraint is raised accordingly).

## [1.1.3] — 2026-09-22

### Changed

**Pool housekeeping is on by default.** `PpaPoolTrait` now answers `keepaliveTime` with
`120.0` (was `0.0`) and `idleTimeout` with `600.0` (was `0.0`); `minimumIdle` stays `0`, and
`poolMaxConnections` / `poolWaitTimeout` are untouched. A config that declares the property
itself is unaffected — the trait only supplies what was not set.

Why it matters here: a pool lives in one worker's memory and is only ever examined when
someone borrows from it, so an application between bursts held its sockets indefinitely and
never noticed the server (or a firewall) dropping them. The first request back paid for that
discovery — probing every dead connection and reopening — and, before the matching
`winter-cpool` fix, failed outright once four or more had died. With these defaults the
background sweep pings idle connections every two minutes and releases them after ten, so
neither the database nor the request is left holding the problem.

The numbers are HikariCP's and keep its ordering, `keepaliveTime < idleTimeout < maxLifetime`.
`minimumIdle` deliberately stays lazy: a warm floor multiplies by `worker_num` here, where
HikariCP's lives once per JVM.

Requires a `flytachi/winter-cpool` carrying the deadline-bounded `borrow()`; older versions
honour these values but still count dead connections against a fixed retry budget.

### Fixed

**Per-coroutine repository state is keyed by the repository object, not by `spl_object_id()`.**
Under Swoole, `RepositoryCore::state()` stored each repository's `sqlParts` and
`entityClassName` in the coroutine context under `'__rp_' . spl_object_id($this)`. PHP hands
that id back out the moment an object is freed, and repositories are overwhelmingly
short-lived temporaries — `Repo::instance('c')` passed straight into `joinLeft()` is dropped
as soon as the join string is built, while its state stayed behind in the context. The next
repository to land on the recycled slot inherited it: a foreign alias, SELECT, WHERE, JOIN,
LIMIT and entity class, with only `FROM` still coming from its own class. The state now lives
in a `WeakMap` held in the coroutine context and keyed by the object itself, so identity is
never recycled and each state is released as soon as its repository is collected — which also
ends the slow growth of the context over the life of a request.

Symptom this fixes, from a real application:

```
SELECT c.id, c.name, c.is_delete FROM dev2.conduct_warehouse_staffs c WHERE staff_id = :iqb0
```

— the right table, another repository's alias and columns. Affected the Swoole coroutine path
only; the FPM path stores state on the object and was never involved. Public behaviour is
unchanged: per-coroutine isolation, `cleanCache($part)` and the full `cleanCache()` reset all
work as documented.

## [1.1.0] — 2026-08-19

### Added

**Pagination** — `Pagination\Paginator` with three ways to cut a result into pages:
`repo()` and `array()` by offset, `cursor()` by a stable key. `Pagination\Wrapper`
re-shapes a page into the page-centric envelope (`current`, `pages`, `previous`, `next`)
that a numbered UI expects. Cursors travel as opaque tokens (`CursorKey`, `CursorToken`),
and a malformed one raises `InvalidCursorException` rather than silently paging from the
start.

It arrives from `flytachi/winter-kernel`, where it lived as `Kernel\Unit\*`. The move is
not administrative: a cursor becomes a `WHERE` and an `ORDER BY`, and a page count needs a
`COUNT` — pagination assembles SQL, so it belongs where the rest of the SQL is.

`Wrapper::paginator()` over an **array** opens no connection, so an in-memory list can be
paginated without a database at all.

### Migration from the kernel

| Was | Now |
| --- | --- |
| `Flytachi\Winter\Kernel\Unit\Wrapper` | `Flytachi\Winter\Ppa\Pagination\Wrapper` |
| `Flytachi\Winter\Kernel\Unit\Pagination\…` | `Flytachi\Winter\Ppa\Pagination\…` |

Requires `flytachi/winter-kernel` 5.0 or newer, which no longer ships these classes.

## [1.0.0] — 2026-08-19

First release as a standalone package. The code is not new: it was the `Ppa` layer of
`flytachi/winter-kernel`, extracted so an application that needs no database does not carry
an ORM, a connection pool and a migration engine it never loads.

### Added

**Repositories** — `RepositoryCore` assembles queries (`where`, `and/or/xorWhere`, the
five `join*`, `with`, `withRecursive`, `union*`, `select`, `from`, `groupBy`, `having`,
`orderBy`, `limit`), `RepositoryViewTrait` reads (`find*`, `count`, `exists`, `rawFetch`,
the `*OrThrow` variants), `RepositoryCrudTrait` writes (`insert`, `insertBatch`, `update`,
`delete`, `upsert`, `upsertBatch`). Four stereotypes: `Repository`, `RepositoryCrud`,
`RepositoryView`, `CteRepo`.

**Entity mapping** — 37 attributes across six contracts (type, sub-type, index, constraint,
additive, hybrid), rendered per dialect for PostgreSQL, MySQL/MariaDB and SQLite, turning
entity classes into a `Declaration` of tables.

**Connection pool** — `PpaConnectionPool` over `flytachi/winter-cpool`: one pool per config
class, a coroutine lease returned by `defer`, a `SingleConnection` path without Swoole,
`ConnectionLoss` classification with a probe for the undecided case, and `PoolTelemetry`
for per-worker statistics.

### Changed from the in-kernel version

**The package no longer reaches for a framework.** Three things it used to fetch are now
installed from outside, each defaulting to inert:

| What | Was | Now |
| --- | --- | --- |
| Logger | `LoggerFactory::getLogger('PPA')` | `PpaConnectionPool::setLogger()`, silent by default |
| Request timezone | `Timezone::current()` | `PpaConnectionPool::setTimezoneProvider()`, no `SET TIMEZONE` by default |
| Telemetry storage | `Kernel::runnable()` | `PoolTelemetry::setStoreProvider()`, nothing published by default |

The Winter kernel installs all three at boot, so applications on the framework see no
change in behaviour.

**Namespace** — `Flytachi\Winter\Kernel\Ppa\…` became `Flytachi\Winter\Ppa\…`.

**Scanning was split off.** `PPAMapping` here maps reflections to a `Declaration`
(`configsFrom()`, `declarationFrom()`); finding the project's classes stays in the kernel,
which keeps its own `PPAMapping` with the same `scanningConfigs()` / `scanningDeclaration()`
signatures. Console commands and applications calling those need no edit.

**The connection pool mechanics** moved to `flytachi/winter-cpool`, where they are shared
with `flytachi/winter-redis`.

[Unreleased]: https://github.com/flytachi/winter-ppa/compare/v1.2.1...HEAD
[1.2.1]: https://github.com/flytachi/winter-ppa/releases/tag/v1.2.1
[1.2.0]: https://github.com/flytachi/winter-ppa/releases/tag/v1.2.0
[1.1.4]: https://github.com/flytachi/winter-ppa/releases/tag/v1.1.4
[1.1.3]: https://github.com/flytachi/winter-ppa/releases/tag/v1.1.3
[1.1.0]: https://github.com/flytachi/winter-ppa/releases/tag/v1.1.0
[1.0.0]: https://github.com/flytachi/winter-ppa/releases/tag/v1.0.0
