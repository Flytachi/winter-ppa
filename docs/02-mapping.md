# 02 — Mapping

How a PHP class becomes a table, and where to add to it.

## The pipeline

```
ReflectionProperty
   ↓  ColumnMapping::push()
attributes read in declaration order
   ↓
Column (type, nullable, default, indexes, constraints)
   ↓  PPAMapping::declarationFrom()
Table  →  DeclarationItem (per config)  →  Declaration
```

`Declaration` is what a migration compares against a live schema. It is grouped by config
instance rather than by table, because two repositories may describe tables on two
different databases and the comparison happens per connection.

## The attribute contracts

Every mapping attribute implements one of six interfaces, and the interface — not the
class name — is what `ColumnMapping` dispatches on.

| Contract | Answers | Example |
| --- | --- | --- |
| `AttributeDbType` | what SQL type this property is | `#[Varchar(255)]`, `#[Integer]` |
| `AttributeDbSubType` | what to append to the type | `#[AutoIncrement]` |
| `AttributeDbIdx` | an index this column takes part in | `#[Primary]`, `#[Unique]`, `#[Index]` |
| `AttributeDbConstraint` | a table constraint | `#[ForeignKey]`, `#[Check]` |
| `AttributeDbAdditive` | nullability and default | `#[NullableIs]`, `#[DefaultVal]` |
| `AttributeDbHybrid` | several of the above at once | `#[Id]`, `#[BigId]`, `#[UuidPk]` |

A hybrid holds no logic of its own: `getInstances()` returns the ordinary attributes it
stands for, and they go through the same path. `#[Id]` is `Primary` + `AutoIncrement` +
`NullableIs(false)` + `Integer` — which is why documenting it as "expands into" is
accurate rather than a simplification.

## Type resolution

Three sources, in this order:

1. **The PHP type**, when nothing else says otherwise — `Column::getPrimitiveSqlType()`.
2. **`AttributeDbType::toSql($dialect)`**, when the property carries an explicit type.
3. **`AttributeDbSubType::toSql($type, $dialect)`**, wrapping whatever came out of 1 or 2.

`supports()` is the gate: it receives the property's PHP types and answers whether the
attribute may apply. The sub-type variant takes them **by reference** — `AutoIncrement`
strips `null` before deciding, because `?int` is the normal shape of a generated key.

## Dialects

`$dialect` is a plain string (`pgsql`, `mysql`, `sqlite`, …) taken from the config's
driver, and each attribute renders itself with a `match`. There is no dialect object and
no per-dialect subclass hierarchy: the differences are small, local, and easier to read at
the point they occur.

The rule that keeps this honest: **a dialect difference belongs to the attribute that has
it**, not to a translation layer above. `AutoIncrement` is the sharpest example —
PostgreSQL gets `GENERATED … AS IDENTITY`, MySQL `AUTO_INCREMENT`, and SQLite the literal
type `INTEGER`, because only a column spelled exactly that becomes a `rowid` alias. Written
in one `match`, the reason is visible; spread across three dialect classes, it is folklore.

### Adding a dialect

1. Extend the `match` in every attribute whose SQL differs. A missing arm falls to
   `default`, which is MySQL-shaped — so an unhandled dialect produces *plausible* wrong
   DDL rather than an error. Grep for `match ($dialect)` and go through the list.
2. Take a golden DDL snapshot **before** the change, and diff it byte for byte after.
3. Execute the result. A dialect that compiles and does not run is the usual failure.

### Adding an attribute

1. Pick the contract from the table above; implement it.
2. `supports()` must reject what it cannot render, rather than emit something wrong.
3. `toSql()` throws for a type it does not accept — see `AutoIncrement`, which refuses
   anything but `SMALLINT`/`INT`/`BIGINT`.
4. Add it to the user documentation's attribute tables; a mapping attribute nobody
   documents is one nobody uses.

## What the entity is not

The entity describes a **table**, not an object model. There is no lazy loading, no
identity map, no dirty tracking: a repository returns hydrated instances, and what happens
to them afterwards is the application's business. Keeping it that way is deliberate —
each of those features moves the boundary between "your data" and "our state", and this
package is on the far side of that line.
