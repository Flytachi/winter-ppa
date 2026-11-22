# E2E — the life of an application

Schema versions on a live database, the way an application lives them: entities that
change over 22 snapshots, rows in the tables, clients that update one version at a time or
skip many. Runs on every push and pull request (`.github/workflows/e2e.yml`); PostgreSQL
for now.

```bash
E2E_PG=127.0.0.1:5432:e2e:postgres:test php e2e/run.php
E2E_STOP=7 …     # variant C stops at stage-3 #7 (random 5–9 when not given; the run prints it)
```

The database is emptied — give it one of its own.

## What it does

| | |
| --- | --- |
| **Stage 1** — V001–V005 | six tables with every attribute ppa has (types, keys, every index kind and parameter, checks, foreign keys, a schema, an extension); a column added to four tables, three renamed, three dropped and one kept (`--keep`); then added, renamed and dropped in one version, back to the shape of V001 |
| **Stage 2** — data | rows in every table, and the dirt stage 3 must live with: duplicate names, NULL notes, references to no row |
| **Stage 3** — V006–V022 | partial indexes changed, HASH → GIN with trigrams, a new extension and a GiST index, a unique index over duplicates (`before()`), INCLUDE and composite indexes, ON DELETE changed, a foreign key over orphans (`before()`), types widened, a narrowing (`after()`), NOT NULL over NULLs, an enum's CHECK widened, a column with its key and index renamed, a new table filled by code, a primary key changed, a table dropped, a version of code only, a kept column dropped at last, a last mix |
| **Errors** | what `snapshot` must refuse (12 cases) and `migrate` must refuse or roll back (7) |

Each snapshot is taken from the entities — real PHP classes with real attributes, written
for each state (`State::code()`) and mapped by `PPAMapping` — and must record what the step
says (`Step::$expect`); a second snapshot of the same state must record nothing.

## Variants

- **A** — one by one: snapshot, `migrate`, the checks; the data after V005.
- **B** — every version in one `migrate` on an empty database: created at V022 at once.
- **C** — an empty database up to V005 at once, the data, stage 3 up to a version picked at
  random, then the rest: a client that skipped updates, every run applying several versions
  with their code.

After each version: the database matches the version's model, the step's checks hold, no
row is lost. At the end **A, B and C have the same schema** — columns (in order, with types,
NULL, defaults, identity), indexes (their definitions), constraints, extensions, schemas.

## Where things are

| File | |
| --- | --- |
| `src/Scenario.php` | the states and the steps — add a step here |
| `src/State.php` | a state as data, and as PHP |
| `src/Data.php` | stage 2, and the numbers the checks count on |
| `src/Runner.php` | the variants, the errors, the report |
| `src/Db.php` | the connection, the reset, the schema dump A / B / C are compared by |

A new attribute in `src/Mapping/Attributes/` fails the run until a state uses it.
