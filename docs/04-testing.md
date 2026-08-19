# 04 — Testing

## Two kinds of test

**Unit** — the mapping, the declaration, the query builder, the pool's decisions. These
run everywhere and need nothing: 443 of them, in under a second.

**Integration** — everything that only a real database can answer: DDL a dialect actually
accepts, an `upsert` that really upserts, a migration applied end to end, a pooled
connection surviving a server restart. They live in the kernel's suite today, against
PostgreSQL, MySQL and MariaDB in containers.

That split is the reason a package suite stays fast while the risky things still get
checked. It is also the reason a change to a dialect is not proven by unit tests alone —
see [02 — Mapping](02-mapping.md).

## Running

```bash
XDEBUG_MODE=off composer test        # phpunit
XDEBUG_MODE=off composer test-ci     # the same, but skips fail the run
composer cs-check                    # phpcs, PSR-12
composer validate --no-check-publish
```

`XDEBUG_MODE=off` matters wherever coroutines run: Xdebug's function observers do not
survive coroutine stacks, and the symptom is a green report followed by exit code 139.

## What a test should pin

**Behaviour the code promises in a docblock.** If the pool says a dead connection is
retired and the statement is not replayed, both halves deserve a test — the second one
especially, because nothing else would notice a helpful retry being added.

**Concurrency with real coroutines.** `Coroutine\run()` with several coroutines, not a
simulation. Per-coroutine query state is the thing most likely to be broken by an
innocent-looking refactor, and it cannot fail in a single-request test.

**The framework boundary.** The three injections
([01 — Layers](01-layers.md)) have unset defaults, and those defaults are a contract: a
test that installs a logger everywhere would hide a package that had started fetching one
by itself.

**Dialect output, byte for byte.** Take the DDL snapshot before the change and diff it
after. "Looks right" is how a `SMALLINT` becomes an `INTEGER` in one dialect and nobody
notices for a release.

## Regression tests

A test added with a fix must fail **before** the fix — check it by reverting the fix, not
by reasoning. A test that passes against the broken code is worse than no test, because it
will be trusted.
