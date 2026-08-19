# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] — Unreleased

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

[1.1.0]: https://github.com/flytachi/winter-ppa/releases/tag/v1.1.0
[1.0.0]: https://github.com/flytachi/winter-ppa/releases/tag/v1.0.0
