# Winter PPA

[![Latest Version on Packagist](https://img.shields.io/packagist/v/flytachi/winter-ppa.svg)](https://packagist.org/packages/flytachi/winter-ppa)
[![PHP Version Require](https://img.shields.io/packagist/php-v/flytachi/winter-ppa.svg?style=flat-square)](https://packagist.org/packages/flytachi/winter-ppa)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)

📖 **[Documentation](https://winterframe.net/docs/ppa)** · [Repositories](https://winterframe.net/docs/repository) · [Entities](https://winterframe.net/docs/entities) · [Connection pool](https://winterframe.net/docs/ppa-pooling)

**PHP Persistence API** — the data layer for PHP that stays resident.

Two problems, one package. Writing SQL by hand means the same query rebuilt in every place
it is needed, parameters bound by hand, and a renamed column discovered at runtime. And a
worker that lives for weeks cannot simply open a connection and keep it: the connection
dies, the database restarts, the firewall drops idle sockets — while under Swoole one
socket cannot be shared by concurrent requests at all.

PPA answers both: repositories that assemble queries as method calls, entities that
describe tables as attributes, migrations built from those attributes — over a connection
pool that keeps its connections **working** rather than merely reused.

---

## Installation

```bash
composer require flytachi/winter-ppa
```

Requires PHP **8.4+** and `ext-pdo`. `ext-swoole` is optional: with it every coroutine gets
its own connection from a pool, without it one self-maintaining connection serves the
process — the calling code is identical.

Inside the Winter framework it is an optional package: the kernel wires it up when it is
installed and works without it when it is not.

---

## Quick start

Describe the table:

```php
use Flytachi\Winter\Ppa\Mapping\Attributes\Entity\Table;
use Flytachi\Winter\Ppa\Mapping\Attributes\Hybrid\Id;
use Flytachi\Winter\Ppa\Mapping\Attributes\Primal\Varchar;

#[Table('users')]
class UserEntity
{
    #[Id] public ?int $id = null;
    #[Varchar(255)] public string $email;
    #[Varchar(64)] public string $status;
}
```

Bind a repository to it:

```php
use Flytachi\Winter\Ppa\Stereotype\Repository;

/** @extends Repository<UserEntity> */
class UserRepository extends Repository
{
    public static string $table         = 'users';
    protected string $entityClassName   = UserEntity::class;
    protected string $dbConfigClassName = MainDbConfig::class;
}
```

The `@extends` line is what makes `findById()` return `?UserEntity` instead of `object`;
the code works without it, the editor does not.

Ask it questions:

```php
use Flytachi\Winter\Cdo\Qb;

$user  = UserRepository::instance()->findById(42);
$users = UserRepository::instance('u')
    ->where(Qb::eq('u.status', 'active'))
    ->orderBy('u.created_at DESC')
    ->limit(20)
    ->findAll();

$id = UserRepository::instance()->insert(['email' => $email, 'status' => 'active']);
```

No connection to obtain, none to return. The connection is taken on the first query and
goes back to the pool when the request ends.

---

## What you get from it

**A query built from methods, not strings.** `where()`, `join*()`, `with()`, `union*()`
accumulate parts; values travel as bound parameters, never as text. A sub-repository can be
joined, used as a source or as a CTE — and brings its own binds along, which a string
could not.

**A schema that comes from the code.** Attributes on entity properties describe types,
keys, indexes and constraints; `call db migrate` compares that description with the live
database and shows what it would change before doing it.

**Connections that heal.** An idle connection is probed before it is handed over, an aged
one is rotated ahead of time, and the number is capped per worker. A database restart stops
poisoning a worker for the rest of its life.

**Correctness under concurrency.** A repository may be a container singleton while serving
concurrent coroutines: its query state is isolated per coroutine, so two requests cannot
build each other's conditions.

**Failures that are classified, not guessed.** A dead connection is retired; a constraint
violation leaves the connection alone. Where the driver's verdict is uninformative — as
PostgreSQL's is when the socket is gone — the pool probes instead of parsing the message.

---

## A taste of the API

```php
// reading
$repo->find();                      $repo->findAll();
$repo->findById($id);               $repo->findBy(Qb::eq('email', $email));
$repo->count();                     $repo->exists();
$repo->findByIdOrThrow($id);        // instead of the null check

// writing
$repo->insert($entity);             $repo->insertBatch(...$entities);
$repo->update($entity, Qb::eq('id', $id));
$repo->delete(Qb::lt('created_at', $cutoff));
$repo->upsert($entity, ['email']);  $repo->upsertBatch(['email'], ...$entities);

// assembling
UserRepository::instance('u')
    ->select(['u.id', 'COUNT(o.id) AS orders'])
    ->joinLeft(OrderRepository::instance('o'), 'o.user_id = u.id')
    ->where(Qb::eq('u.status', 'active'))
    ->groupBy('u.id')
    ->having('COUNT(o.id) > 3')
    ->findAll();

// inspecting, before anything reaches the database
$repo->buildSql();                  $repo->getSql('binds');
```

---

## Outside a framework

The package fetches nothing from its host. Three things it takes, and what happens when
they are not given:

```php
use Flytachi\Winter\Ppa\Pool\PoolTelemetry;
use Flytachi\Winter\Ppa\Pool\PpaConnectionPool;

PpaConnectionPool::setLogger($logger);                       // unset: silent
PpaConnectionPool::setTimezoneProvider(fn() => $zone);       // unset: no SET TIMEZONE at all
PoolTelemetry::setStoreProvider(fn() => $storage);           // unset: nothing is published
```

Each default is the inert one deliberately: a library that logs somewhere by itself, or
imposes a timezone on your session, is a library that surprises you. Fork safety and
shutdown are yours to call as well — `PpaConnectionPool::reset()` in a forked child (which
forgets sockets **without** closing them, since the descriptors are shared with the
parent), `shutdown()` when a worker exits.

Inside the Winter kernel all of this is installed at boot.

---

## Documentation

The user-facing documentation lives at
**[winterframe.net/docs/ppa](https://winterframe.net/docs/ppa)** (the link picks your
language; RU and EN are both complete).

**Start here**

| Page | What it answers |
|------|-----------------|
| [PHP Persistence API](https://winterframe.net/docs/ppa) | What the layer is, what it gives over a plain connection, query examples |
| [Database configuration](https://winterframe.net/docs/db-configuration) | Credentials, drivers, several databases at once |
| [Repositories](https://winterframe.net/docs/repository) | Assembling queries, reading, writing, transactions, debugging |

**Schema**

| Page | What it answers |
|------|-----------------|
| [Entities](https://winterframe.net/docs/entities) | Attributes, types, keys, indexes, constraints |
| [Migrations](https://winterframe.net/docs/migrations) | Comparing the described schema with the live one |

**Operating it**

| Page | What it answers |
|------|-----------------|
| [Connection pool](https://winterframe.net/docs/ppa-pooling) | Borrow rules, failures, sizing, watching it |
| [Pagination](https://winterframe.net/docs/pagination) | Page and cursor pagination over repositories |

---

## Contributing

Internal technical notes — the layering, how an attribute becomes a column, why query
state is per coroutine, and the rules for adding to any of it — live in
[`docs/`](docs/README.md). Read those before changing the pool or the mapping.

```bash
XDEBUG_MODE=off composer test   # phpunit
composer test-detail            # phpunit --testdox
composer cs-check               # phpcs
composer cs-fix                 # phpcbf
```

- Setup, the testing philosophy and the documentation rules: [CONTRIBUTING.md](CONTRIBUTING.md)
- Changes and upgrade notes: [CHANGELOG.md](CHANGELOG.md)
- Reporting a vulnerability, and what the package does and does not guarantee: [SECURITY.md](SECURITY.md)

---

## License

MIT License. See [LICENSE](LICENSE).
