# 01 — Layers

## The stack

```
winter-cdo            the driver: CDO over PDO, Qb, DbConfig
   ↑
CdoConnectionFactory  create / validate / reset / close, for CPool
   ↑
winter-cpool          the pool mechanics: borrow, probe, rotate, cap
   ↑
PpaConnectionPool     one pool per config class + the coroutine lease
   ↑
RepositoryCore        query assembly; CRUD and view traits execute
   ↑
Pagination            Paginator::repo/array/cursor, Wrapper, CursorKey/CursorToken
                      (InvalidCursorException) — on top of RepositoryViewInterface

Mapping + Declaration entities → tables, for migrations
```

Pagination is a client of the repository, not part of it: it drives a
`RepositoryViewInterface` through its public methods (`limit()`, `buildSql()`,
`findAll()`, …) and holds no state of its own. Mapping sits beside the stack rather than
in it — it reads entity classes and never borrows a connection.

Each layer knows only the one below it. A repository never touches the pool's internals; a
mapping attribute never learns which connection its table will be created on.

## The boundary with a framework

This package is used by the Winter kernel, and it must not know that. Three things it
deliberately does not fetch:

| What | Installed by | Unset means |
| --- | --- | --- |
| Logger | `PpaConnectionPool::setLogger()` | silence (`NullLogger`) |
| Request timezone | `PpaConnectionPool::setTimezoneProvider()` | no `SET TIMEZONE` is sent at all; when set, it is consulted only on the coroutine path (`coroutineDb()` → `syncTimezone()`) |
| Telemetry storage | `PoolTelemetry::setStoreProvider()` | publishing throws, and every caller already treats that as "no records" |

Each default is the inert one on purpose. A library that logs somewhere by itself, or
imposes a timezone on a session, is a library that surprises its host.

Two of these are also lessons, not preferences:

- The **timezone** provider must be per unit of work. Reading PHP's
  `date_default_timezone_get()` means reading an engine global shared by every request in
  a worker: a request that yields on I/O can resume after a concurrent one overwrote it,
  and hand *that* zone to its own session. Measured, not theorised.
- The telemetry **provider**, not a ready storage: building a `FileStorage` creates its
  directory, and an application that never opens a pool must not be left with an empty
  `runnable/ppa.pool/` that reads as "this application uses a database".

## The coroutine lease

`PpaConnectionPool::db()`:

1. outside a coroutine — one `SingleConnection` per config, for the process;
2. inside — looks in `Coroutine::getContext()`, borrows on a miss, wraps the entry in
   `BorrowedConnection`, registers `Coroutine::defer()` to return it.

The defer captures the `BorrowedConnection` **directly** rather than reading it back from
the context, which may already be tearing down. The object carries the `dead` flag, so
`reportFailure()` can turn a release into an eviction after the fact.

### The return-time reset

`CdoConnectionFactory` implements winter-cpool's `ResettableConnectionFactory`, so the pool
calls `reset()` on every connection coming back. A connection still inside a transaction
is rolled back and an ERROR is logged — the application has a `beginTransaction()` without
a `commit()`/`rollBack()` on some path. A connection whose rollback fails is retired with a
WARNING. The clean case costs no round trip: `inTransaction()` is the driver's own state.

## Pool knobs

A config that implements `PpaPoolConfigInterface` (usually via `PpaPoolTrait`) sizes its
pool. The trait supplies only getters with defaults and declares no properties, so to
change a knob declare the property on the config class itself:

| Property | Default | Meaning |
| --- | --- | --- |
| `poolMaxConnections` | 5 | maximum connections per worker |
| `poolWaitTimeout` | 3.0 | seconds to wait for a free slot before `PpaPoolException` |
| `keepaliveTime` | 120 | background probe of idle connections; 0 = off (Swoole only) |
| `idleTimeout` | 600 | close a connection idle this long; 0 = never (Swoole only) |
| `minimumIdle` | 0 | warm connection floor; 0 = fully lazy (Swoole only) |

## Diagnostics and telemetry

`PpaConnectionPool::stats()` returns `total/idle/active/maximum` per config class for the
coroutine pools of **this** worker; the static path has no pool and is not reported.
`showDbConfigs()` lists the registered config instances. `reportFailure($configClass,
$error)` is what every repository catch block calls — it classifies the error and evicts
the connection if it was lost.

A pool lives in one worker's memory, so `PoolTelemetry` lets each worker publish a small
record that another process can read:

- `enable(int|string $source, string $kind = KIND_WEB)` marks the process eligible —
  `KIND_WEB` (worker id), `KIND_PROCESS` or `KIND_DAEMON` (a name). It arms nothing.
- `arm()` starts the publishing timer; the pool calls it when it opens its first pool, so
  a process that never touches a database has no timer and writes nothing.
- `stop()` clears the timer and deletes the record; `forget()` drops the publishing
  identity without touching the store (called from `reset()` in a forked child).
- `snapshot()` reads every live record; `aggregate($kinds)` sums them per config,
  optionally for some kinds only, and counts saturated workers.

`PPA_POOL_TELEMETRY` sets the interval in seconds (default 5, values under 1 are raised to
1, `0` disables); records expire after three intervals. Without Swoole, `enable()` is a
no-op.

## Deciding that a connection died

`ConnectionLoss` classifies a `Throwable` into three answers: lost, healthy, or
undecided. Lost evicts, healthy leaves the connection alone, undecided probes.

The undecided case exists because of PostgreSQL: when the socket is gone there is no
SQLSTATE to read, so PDO reports `HY000` with libpq's generic code `7` — the same shape a
syntax error has. Rather than guess from the message, the pool asks the connection.

Whatever the verdict, the statement is not replayed. See invariant 5 in the
[README](README.md).

## Forking

A fork copies PDO objects together with their sockets, and a child cannot forget an
inherited one quietly: when it drops the copy — in `reset()`, or simply by exiting — the
driver closes the server session (Terminate on PostgreSQL, COM_QUIT on MySQL) over the
socket the parent still uses, and the parent's next query fails. So the work is split:

1. the **parent** calls `PpaConnectionPool::closeBeforeFork()` right before
   `pcntl_fork()`. It closes the process's non-coroutine connections (the only kind a
   forking process holds) and throws `PpaPoolException` if a live connection is inside a
   transaction; a dead one is simply closed. Both sides reopen lazily.
2. the **child** calls `reset()`: it abandons pools and drops caches and the telemetry
   identity. It is safe only because the parent left nothing inherited to drop.

Persistent connections are out of reach of both — do not combine them with forking. The
Winter kernel makes both calls itself; outside it they are the host's job.

## What may be depended on

| Dependency | Why |
| --- | --- |
| `flytachi/winter-cdo` | the driver this layer is built on |
| `flytachi/winter-cpool` | the pool mechanics |
| `flytachi/winter-base` | `Runtime` (is there a coroutine), exception traits, HTTP codes |
| `flytachi/winter-di` | `#[Autowired]`/`#[Inject]` support in repositories |
| `flytachi/file-store` | telemetry records |
| `psr/log` | the injected logger |

`ext-swoole` is a suggestion, never a requirement: without it the package runs the
single-connection path. Adding a dependency on a framework — any framework — is the one
change that would undo the reason this package exists.
