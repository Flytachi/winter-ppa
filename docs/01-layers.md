# 01 — Layers

## The stack

```
winter-cdo            the driver: CDO over PDO, Qb, DbConfig
   ↑
CdoConnectionFactory  create / validate / close, for CPool
   ↑
winter-cpool          the pool mechanics: borrow, probe, rotate, cap
   ↑
PpaConnectionPool     one pool per config class + the coroutine lease
   ↑
RepositoryCore        query assembly; CRUD and view traits execute
   ↑
Mapping + Declaration entities → tables, for migrations
```

Each layer knows only the one below it. A repository never touches the pool's internals; a
mapping attribute never learns which connection its table will be created on.

## The boundary with a framework

This package is used by the Winter kernel, and it must not know that. Three things it
deliberately does not fetch:

| What | Installed by | Unset means |
| --- | --- | --- |
| Logger | `PpaConnectionPool::setLogger()` | silence (`NullLogger`) |
| Request timezone | `PpaConnectionPool::setTimezoneProvider()` | no `SET TIMEZONE` is sent at all |
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

## Deciding that a connection died

`ConnectionLoss` classifies a `Throwable` into three answers: lost, healthy, or
undecided. Lost evicts, healthy leaves the connection alone, undecided probes.

The undecided case exists because of PostgreSQL: when the socket is gone there is no
SQLSTATE to read, so PDO reports `HY000` with libpq's generic code `7` — the same shape a
syntax error has. Rather than guess from the message, the pool asks the connection.

Whatever the verdict, the statement is not replayed. See invariant 5 in the
[README](README.md).

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
