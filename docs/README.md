# Internal documentation

Technical notes for **changing** this package. If you are using it, the documentation you
want is at [winterframe.net/docs/ppa](https://winterframe.net/docs/ppa) — repositories,
entities, migrations, pooling, written for the person building an application.

What lives here is the other half: how the layers fit, what turns an attribute into a
column, why a repository keeps its query state per coroutine, and what must stay true when
any of it changes.

## Where to look

| Page | Read it when… |
| --- | --- |
| [01 — Layers](01-layers.md) | you touch the pool, the boundary with a framework, or wonder what this package may depend on |
| [02 — Mapping](02-mapping.md) | you add an attribute, a SQL dialect, or change how a property becomes a column |
| [03 — Repository](03-repository.md) | you touch query assembly, hydration, binds or the CRUD/view traits |
| [04 — Testing](04-testing.md) | a test needs a real database, or you are about to mock one |

## Invariants

Five things the package promises. A change that breaks one is a change of contract, not a
refactor.

**1. Nothing here reaches for a framework.** No global container, no kernel singletons, no
static configuration read from somewhere else. Everything the package cannot compute — a
logger, the request's timezone, where telemetry publishes — is installed from outside
(`PpaConnectionPool::setLogger()`, `setTimezoneProvider()`, `PoolTelemetry::setStoreProvider()`).
This is what makes the package usable, and testable, on its own; it is also the first thing
that quietly comes back. It came back once already: the pool fetched its logger from a
factory, and the whole suite died the moment it ran outside the kernel.

**2. A connection belongs to one unit of work.** Under Swoole a coroutine borrows on first
use and a `defer` returns it; outside, one self-maintaining connection per config serves
the process. Nothing may hold a `CDO` past its unit of work.

**3. Query state is per coroutine.** A repository may be a singleton while `where()`,
`join()` and the rest accumulate parts on the object. `RepositoryCore::state()` isolates
those parts per coroutine — without it two concurrent requests build each other's queries.

**4. The pooled resource is the config, not the CDO.** The config owns its socket, so
`close()` is deterministic and `validate()` can reuse the driver's own probe. Pooling a
bare connection would hand closing to garbage collection.

**5. A failed query is never retried.** The pool retires a connection it found dead, but
replaying the statement is refused: the break may have happened after the server applied
the write. Retrying is the application's decision, made where the meaning of the write is
known.

## Examples must run

Every code sample here and in the user documentation is expected to work as written
against the databases the integration tests use. When you change behaviour, run the
samples that describe it — a documentation page that lies is worse than a missing one,
because it is believed.
