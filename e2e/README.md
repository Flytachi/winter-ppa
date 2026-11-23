# E2E — the life of an application

Schema versions on a live database, the way an application lives them: entities that
change over 23 snapshots, rows in the tables, clients that update one version at a time or
skip many. Runs on every push and pull request (`.github/workflows/e2e.yml`): PostgreSQL 16
and 18, MariaDB 10.11 and 11.8.

```bash
E2E_PG=127.0.0.1:5432:e2e:postgres:test php e2e/run.php
E2E_MARIA=127.0.0.1:3306:e2e:root:test php e2e/run.php
E2E_STOP=7 …     # variant C stops at stage-3 #7 (random 5–9 when not given; the run prints it)
```

The database is emptied — give it one of its own. On MariaDB a database `shop` beside it is
dropped and made again (the tables of the schema `shop`): ppa makes no database there.

One scenario per database, the same life version for version: `PgScenario`, `MariaScenario`.
MariaDB declares what it has not too — an index's WHERE, INCLUDE and operator class, HASH,
`#[Extension]`, `always:` — and the snapshot must leave it out **and say so**
(`Step::$said`); what it cannot build (GIN, GiST, a partial unique index, `SET DEFAULT`) is
among the errors. Coverage counts both: all 37 attributes on either.

## What it does

| | |
| --- | --- |
| **Stage 1** — V001–V005 | six tables with every attribute ppa has (types, keys, every index kind and parameter, checks, foreign keys, a schema, an extension); a column added to four tables, three renamed, three dropped and one kept (`--keep`); then added, renamed and dropped in one version, back to the shape of V001 |
| **Stage 2** — data | rows in every table, and the dirt stage 3 must live with: duplicate names, NULL notes, references to no row |
| **Stage 3** — V006–V023 | on tables full of rows: **indexes** — WHERE changed, a named index renamed and moved to another column, INCLUDE, composite and named unique ones, an operator class, HASH → GIN with trigrams, GiST, an index of every parameter; **foreign keys** — every ON UPDATE / ON DELETE action, renamed by `name:`, dropped, added over orphans (`before()`), a repository key made a table key, what CASCADE and RESTRICT do (tried and rolled back); **checks** — an expression changed, a string and an int enum, one dropped, a table-level one renamed; **tables** — renamed with their entity and repository, one into another schema, with a key of another table pointing at it; an entity and a repository renamed, the table not (nothing to record); **ids** — SMALLINT → INT identity, GENERATED ALWAYS on and off, `always:` on every id, a primary key of two columns; **extensions** — added with version, schema and cascade, dropped with `--drop=extension:`; types widened and narrowed, NOT NULL over NULLs, new tables filled by code, a table dropped, a version of code only; **code** — `before()` and `after()` of one version handing rows over in the instance (notes read before their column is dropped, written to a new table after) |
| **Errors** | what `snapshot` must refuse (PostgreSQL 17, MariaDB 18 — its own among them) or keep (an extension without `--drop`), and what `migrate` must refuse or roll back (7). Where a version is no transaction (MariaDB): a failed one is recorded `failed` and applied once fixed, in a new process; a process **killed with SIGKILL** in a version's code leaves it `running`, and the next run says so, does what is left and records it |

Each snapshot is taken from the entities — real PHP classes with real attributes, written
for each state (`State::code()`) and mapped by `PPAMapping` — and must record what the step
says (`Step::$expect`); a second snapshot of the same state must record nothing.

## Variants

- **A** — one by one: snapshot, `migrate`, the checks; the data after V005.
- **B** — every version in one `migrate` on an empty database: created at V023 at once.
- **C** — an empty database up to V005 at once, the data, stage 3 up to a version picked at
  random, then the rest: a client that skipped updates, every run applying several versions
  with their code.

After each version: the database matches the version's model, the step's checks hold, no
row is lost. At the end **A, B and C have the same schema** — columns (in order, with types,
NULL, defaults, identity), indexes (their definitions), constraints, extensions, schemas.

## Where things are

| File | |
| --- | --- |
| `src/PgScenario.php`, `src/MariaScenario.php` | the states and the steps of each database — add a step to both |
| `src/Scenario.php` | what they share: where stage 1 ends, `clean()`, `swap()` |
| `src/State.php` | a state as data, and as PHP |
| `src/Data.php`, `src/PgData.php`, `src/MariaData.php` | stage 2: the numbers the checks count on, the rows of each database |
| `src/Driver.php`, `src/PgDriver.php`, `src/MariaDriver.php` | a database: the connection, the reset, the schema dump A / B / C are compared by, what its snapshots refuse |
| `src/Runner.php` | the variants, the errors, the report — the same for all |
| `migrate.php` | `migrate` in a process of its own: a restart, a process to kill |

A new attribute in `src/Mapping/Attributes/`, a new parameter of one, a new `FKAction` or
`IndexMethod` fails the run (Coverage) until a state uses it.
