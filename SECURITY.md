# Security Policy

## Supported versions

| Version | Supported |
| --- | --- |
| 1.x | ✅ |

## Reporting a vulnerability

Please report privately, not through a public issue: **jasur.rakhmatov03@gmail.com**.
Include the version, a description and — if you have one — a reproducing snippet. You will
get an acknowledgement within a few days.

## Security model

This package builds SQL and owns database connections. Both are worth being precise about.

### Values are bound; identifiers are not

Everything that travels through `Qb` becomes a bound parameter. Nothing you pass as a
**value** can change the shape of a query:

```php
$repo->where(Qb::eq('id', $input));
// SELECT id FROM users WHERE id = :iqb0     ← the input is a parameter, whatever it contains
```

`orderBy()`, `groupBy()`, `having()`, `select()` and the `$on` of a join take SQL
fragments, and a fragment is inserted **verbatim**:

```php
$repo->orderBy($input);
// SELECT id FROM users ORDER BY id; DROP TABLE users --      ← measured, not hypothetical
```

That is not a defect to be fixed by escaping — a sort clause is code, and there is no
general way to escape code into safety. It is a boundary you have to hold: **never build a
fragment from a request**. Map user input to a fixed set you control:

```php
$sort = match ($request->query('sort')) {
    'newest' => 'created_at DESC',
    'email'  => 'email ASC',
    default  => 'id DESC',
};
$repo->orderBy($sort);
```

The same applies to `Qb::raw()` and to `rawFetch()`: raw means raw. `binding()` exists so
that a raw fragment can still take its values as parameters — use it rather than
interpolating them.

### What the pool guarantees

**One connection, one unit of work.** Under Swoole a coroutine borrows its own connection
and a `defer` returns it; two concurrent requests cannot read each other's results.

**Query state is isolated per coroutine**, so a repository shared as a singleton cannot
leak one request's conditions into another's query.

**Credentials stay out of diagnostics.** The pool logs the config class and the DSN as the
driver reports it; passwords are not read, logged or serialised by this package.

### What the caller is responsible for

**A connection carries session state.** An open transaction, a `SET` you issued by hand,
a temporary table — all of it travels with the connection back to the pool and on to the
next borrower. Finish what you start inside the unit of work that started it.

**Migrations execute DDL.** `call db migrate` applies what the entity attributes describe,
including dropping what is no longer described. Review the plan before applying it in
production, and give the migrating user only the rights it needs — the application's
runtime user rarely needs `DROP`.

**Repositories do not authorise.** A repository returns what the query asks for. Row-level
access control belongs above it, in the code that knows who is asking; adding a `where` in
one place and forgetting it in another is the usual way data leaks.

**The pool is not a rate limiter.** Its ceiling protects the database from too many
connections, not from too much work. A slow query holds its connection for as long as it
runs, and enough of them will exhaust the pool — a self-inflicted denial of service the
pool reports (`PpaPoolException`) but cannot prevent.

### What it is not

Not an authorisation layer, not an audit log, not a sandbox. It executes what the caller
composes, on the connection the config points at, with the rights that user has.
