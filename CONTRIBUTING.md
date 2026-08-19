# Contributing

Thanks for taking the time. Three rules here are unusual enough to state up front: the
package must not learn about any framework, dialect changes are proven by diffing DDL, and
concurrency is tested with real coroutines.

## Environment

| Requirement | For | Why |
| --- | --- | --- |
| PHP **8.4+** | using and developing | the package's floor |
| `ext-pdo` | using and developing | the driver underneath CDO |
| `ext-swoole` | **developing** | the pool and the per-coroutine query state are tested with live coroutines |
| PostgreSQL / MySQL / MariaDB | integration | dialect output and migrations are checked against real servers |

The integration suites live in the framework repository today, against containers. The
package's own suite is unit-level and needs nothing.

## Running the checks

```bash
XDEBUG_MODE=off composer test        # phpunit
XDEBUG_MODE=off composer test-ci     # the same, but a skipped test fails the run
composer test-detail                 # phpunit --testdox
composer cs-check                    # phpcs, PSR-12
composer cs-fix                      # phpcbf
composer validate --no-check-publish
```

`XDEBUG_MODE=off` is not a style preference. Xdebug's function observers do not survive
coroutine stacks: the report says `OK` and the process then exits **139**. The tests were
green; the process was not.

## The rule that matters most

**Nothing here may reach for a framework.** No global container, no kernel singleton, no
configuration read from somewhere else. Everything the package cannot compute is installed
from outside:

```php
PpaConnectionPool::setLogger($logger);
PpaConnectionPool::setTimezoneProvider(fn() => $zone);
PoolTelemetry::setStoreProvider(fn() => $storage);
```

Every one of those defaults to inert — silence, no `SET TIMEZONE`, nothing published —
and that is a contract, not a convenience. It is also the first thing that quietly comes
back: this package once fetched its logger from a factory, and the entire suite died the
moment it ran outside the kernel. If your change needs something from the host, add a
setter with an inert default and document it in [`docs/01-layers.md`](docs/01-layers.md).

## Tests

**Concurrency with real coroutines.** `Coroutine\run()` with several coroutines, not a
simulation of one. Per-coroutine query state is what an innocent-looking refactor breaks,
and it cannot fail in a single-request test.

**Dialect output, byte for byte.** Take a DDL snapshot before the change, diff it after,
then execute the result. A `match ($dialect)` without an arm for your dialect falls through
to the MySQL-shaped default — producing plausible wrong DDL rather than an error, which is
the worst possible failure mode and the reason for the snapshot.

**A regression test must fail before the fix.** Check it by reverting the fix, not by
reasoning about it. A test that passes against the broken code is worse than no test,
because it will be trusted.

**The empty case.** Every reader is asked about a row that is not there: `null`, `[]` or
`0` — never an exception.

## Adding to the mapping

An attribute implements exactly one of the six contracts, and `ColumnMapping` dispatches on
the interface rather than the class. `supports()` must refuse what it cannot render instead
of emitting something wrong, and `toSql()` throws for a type it does not accept — see
`AutoIncrement`, which rejects everything but `SMALLINT`/`INT`/`BIGINT`. Details in
[`docs/02-mapping.md`](docs/02-mapping.md).

An attribute nobody documents is one nobody uses: add it to the attribute tables in the
user documentation in the same change.

## Documentation

- **Internal notes** live in [`docs/`](docs/README.md) — layering, mapping, the repository,
  testing. They are for people changing the code.
- **User documentation** lives in the framework docs site under `docs/ppa*`,
  `docs/repository`, `docs/entities`, `docs/migrations`. RU and EN pages are kept in step.
- **`@link` in code** points at the language-agnostic URL without a version
  (`https://winterframe.net/docs/repository`). Use an anchor only when it is **identical in
  both locales** — a translated heading produces a different slug, and the link then breaks
  in exactly one language.

Every example is expected to run as written. The README's quick start was wrong about a
property's visibility until it was executed; run yours.

## Style

PSR-12, enforced by phpcs. Beyond that:

- **Comments explain the decision, not the syntax.** Why the pool probes instead of reading
  an error message; why the position of a value is a bind and the position of a column is
  not.
- **Docblocks in English**, one space between type, name and description.
- **Reads go in the view trait, writes in the CRUD trait, assembly in the core.** A method
  in the wrong one of the three is the kind of thing that is never moved later.
