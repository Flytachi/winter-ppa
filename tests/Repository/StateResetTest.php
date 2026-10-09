<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Tests\Repository;

use Flytachi\Winter\Cdo\CDOBind;
use Flytachi\Winter\Cdo\Config\SqliteDbConfig;
use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\Ppa\Entity\EntityInterface;
use Flytachi\Winter\Ppa\Pagination\CursorKey;
use Flytachi\Winter\Ppa\Pagination\InvalidCursorException;
use Flytachi\Winter\Ppa\Pagination\Paginator;
use Flytachi\Winter\Ppa\Pagination\Wrapper;
use Flytachi\Winter\Ppa\Pool\PpaConnectionPool;
use Flytachi\Winter\Ppa\Repository\RepositoryException;
use Flytachi\Winter\Ppa\Stereotype\Repository;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;
use ValueError;

final class ResetSqliteDb extends SqliteDbConfig
{
    public static string $file = '';

    public function setUp(): void
    {
        $this->path = self::$file;
    }
}

final class ResetUser
{
    public ?int $id = null;
    public string $name = '';
    public int $active = 1;
}

final class ResetUsersRepo extends Repository
{
    public static string $table = 'users';
    protected string $dbConfigClassName = ResetSqliteDb::class;
    protected string $entityClassName = ResetUser::class;
}

/** An entity whose column list cannot be built: a repository over it fails to render. */
final class ResetBrokenEntity implements EntityInterface
{
    public ?int $id = null;

    public static function selection(): array
    {
        throw new \RuntimeException('selection() failed');
    }
}

final class ResetBrokenRepo extends Repository
{
    public static string $table = 'users';
    protected string $dbConfigClassName = ResetSqliteDb::class;
    protected string $entityClassName = ResetBrokenEntity::class;
}

/**
 * A query is used once, whatever happens to it.
 *
 * The builder used to be reset only after a successful execute. A failed read — the
 * database briefly gone, a bad column — or an exception while building left the query on
 * the repository, and the next call picked it up: a `findAll()` without conditions read
 * the old WHERE and OFFSET, and a `findById()` failed on every call with the old
 * condition's binds. A repository injected into a process or a daemon lives as long as the
 * process, so did the damage. Every scenario runs outside a coroutine and inside one.
 */
final class StateResetTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        ResetSqliteDb::$file = tempnam(sys_get_temp_dir(), 'ppa-reset-');
        $this->pdo = new PDO('sqlite:' . ResetSqliteDb::$file);
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, active INT)');
        foreach ([['a', 1], ['b', 1], ['c', 1], ['d', 0], ['e', 0]] as [$name, $active]) {
            $this->pdo->exec("INSERT INTO users (name, active) VALUES ('{$name}', {$active})");
        }
        PpaConnectionPool::reset();
    }

    protected function tearDown(): void
    {
        PpaConnectionPool::reset();
        @unlink(ResetSqliteDb::$file);
    }

    public static function runtimes(): array
    {
        return ['outside a coroutine' => [false], 'inside a coroutine' => [true]];
    }

    private function inRuntime(bool $coroutine, callable $fn): void
    {
        if (!$coroutine) {
            $fn();
            return;
        }
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('the coroutine path needs Swoole.');
        }
        $error = null;
        \Swoole\Coroutine\run(static function () use ($fn, &$error): void {
            try {
                $fn();
            } catch (Throwable $e) {
                $error = $e;
            } finally {
                PpaConnectionPool::reset(); // its idle timer would keep the coroutine scheduler running
            }
        });
        if ($error !== null) {
            throw $error;
        }
    }

    /** @param list<ResetUser> $users */
    private static function names(array $users): array
    {
        return array_map(fn(ResetUser $u) => $u->name, $users);
    }

    private static function fails(callable $fn, string $class = RepositoryException::class): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            self::assertInstanceOf($class, $e);
            return;
        }
        self::fail('expected the call to fail');
    }

    #[DataProvider('runtimes')]
    public function testAReadThatFailsInTheDatabaseLeavesNoQueryBehind(bool $coroutine): void
    {
        $this->inRuntime($coroutine, function (): void {
            $repo = new ResetUsersRepo();
            $this->pdo->exec('ALTER TABLE users RENAME TO users_away');   // the database is briefly gone

            self::fails(fn() => $repo->where(Qb::eq('active', 0))->orderBy('id DESC')->limit(10, 3)->findAll());

            $this->pdo->exec('ALTER TABLE users_away RENAME TO users');
            self::assertSame('SELECT id, name, active FROM users', $repo->buildSql());
            self::assertSame(['a', 'b', 'c', 'd', 'e'], self::names($repo->findAll()));
        });
    }

    #[DataProvider('runtimes')]
    public function testAFailedReadDoesNotPoisonTheNextConditionWithItsBinds(bool $coroutine): void
    {
        $this->inRuntime($coroutine, function (): void {
            $repo = new ResetUsersRepo();
            $this->pdo->exec('ALTER TABLE users RENAME TO users_away');
            self::fails(fn() => $repo->where(Qb::eq('active', 0))->findAll());
            $this->pdo->exec('ALTER TABLE users_away RENAME TO users');

            // Kept, the old bind made every later where-query fail ("column index out of range").
            self::assertSame('a', $repo->findById(1)?->name);
            self::assertSame('b', $repo->findById(2)?->name);
        });
    }

    #[DataProvider('runtimes')]
    public function testABadQueryIsNotRepeatedByTheNextCall(bool $coroutine): void
    {
        $this->inRuntime($coroutine, function (): void {
            $repo = new ResetUsersRepo();

            self::fails(fn() => $repo->select('no_such_column')->find());
            self::fails(fn() => $repo->groupBy('no_such_column')->count());
            self::fails(fn() => $repo->where(Qb::eq('no_such_column', 1))->exists());
            self::fails(fn() => $repo->select('no_such_column')->findColumn());

            self::assertCount(5, $repo->findAll());
        });
    }

    #[DataProvider('runtimes')]
    public function testAnExceptionWhileBuildingLeavesNoHalfBuiltQuery(bool $coroutine): void
    {
        $this->inRuntime($coroutine, function (): void {
            $repo = new ResetUsersRepo();

            self::fails(fn() => $repo->where(Qb::eq('active', 0))->limit(0), ValueError::class);
            self::assertCount(5, $repo->findAll(), 'the WHERE given before limit(0) is gone');

            self::fails(fn() => $repo->where(Qb::eq('active', 0))->limit(5, -1), ValueError::class);
            self::assertCount(5, $repo->findAll());

            self::fails(fn() => $repo->where(Qb::eq('active', 0))->from('a')->from('b'));
            self::assertCount(5, $repo->findAll());

            $unnamed = (new ResetUsersRepo())->where(Qb::eq('active', 1));
            self::fails(fn() => $repo->where(Qb::eq('active', 0))->joinLeft($unnamed, 'x.id = users.id'));
            self::assertCount(5, $repo->findAll());
        });
    }

    /** @return array<string, array{bool, string}> */
    public static function composers(): array
    {
        $cases = [];
        foreach (['join', 'from', 'with', 'union'] as $how) {
            $cases["{$how}, outside a coroutine"] = [false, $how];
            $cases["{$how}, inside a coroutine"]  = [true, $how];
        }
        return $cases;
    }

    #[DataProvider('composers')]
    public function testASubqueryThatFailsToBuildLeavesNoBindsBehind(bool $coroutine, string $how): void
    {
        $this->inRuntime($coroutine, function () use ($how): void {
            $repo = ResetUsersRepo::instance('u');
            $inner = ResetBrokenRepo::instance('x')->where(Qb::eq('id', 1));   // carries a bind

            self::fails(fn() => match ($how) {
                'join'  => $repo->where(Qb::eq('u.active', 0))->joinLeft($inner, 'x.id = u.id'),
                'from'  => $repo->where(Qb::eq('active', 0))->from($inner),
                'with'  => $repo->where(Qb::eq('active', 0))->with('w', $inner),
                'union' => $repo->where(Qb::eq('active', 0))->union($inner),
            });

            // The inner bind used to stay: every later query failed on a parameter it lacked.
            self::assertSame([], $repo->getSql('binds') ?? []);
            self::assertCount(5, $repo->findAll());
            self::assertSame('a', $repo->findById(1)?->name);
        });
    }

    #[DataProvider('runtimes')]
    public function testLimitWithoutAnOffsetStartsFromTheTop(bool $coroutine): void
    {
        $this->inRuntime($coroutine, function (): void {
            $repo = new ResetUsersRepo();

            self::assertSame(['a', 'b'], self::names($repo->limit(2, 3)->limit(2)->orderBy('id')->findAll()));
        });
    }

    // ── where() replacing its condition replaces its binds ──────────────────

    #[DataProvider('runtimes')]
    public function testASecondWhereReplacesTheFirstTogetherWithItsBinds(bool $coroutine): void
    {
        $this->inRuntime($coroutine, function (): void {
            $repo = new ResetUsersRepo();

            $users = $repo->where(Qb::eq('active', 0))->where(Qb::eq('name', 'a'))->findAll();

            self::assertSame(['a'], self::names($users));
        });
    }

    #[DataProvider('runtimes')]
    public function testCleaningTheWhereDropsItsBinds(bool $coroutine): void
    {
        $this->inRuntime($coroutine, function (): void {
            $repo = new ResetUsersRepo();

            $repo->where(Qb::eq('active', 0));
            $repo->cleanCache('where');

            self::assertSame([], $repo->getSql('binds') ?? []);
            self::assertCount(1, $repo->where(Qb::eq('name', 'b'))->findAll());
        });
    }

    #[DataProvider('runtimes')]
    public function testABindSharedWithAJoinSurvivesReplacingTheWhere(bool $coroutine): void
    {
        $this->inRuntime($coroutine, function (): void {
            $repo = ResetUsersRepo::instance('u');
            $shared = [new CDOBind('flag', 1)];

            $repo->joinLeft('users p', Qb::custom('p.id = u.id AND p.active = :flag', $shared))
                ->where(Qb::custom('u.active = :flag', $shared))
                ->where(Qb::eq('u.name', 'a'));

            self::assertArrayHasKey(':flag', $repo->getSql('binds'), 'the join still uses it');
            self::assertCount(1, $repo->findAll());
        });
    }

    // ── Pagination refuses before running: the caller's query must not stay ─

    #[DataProvider('runtimes')]
    public function testAMalformedCursorLeavesNoConditionBehind(bool $coroutine): void
    {
        $this->inRuntime($coroutine, function (): void {
            $repo = new ResetUsersRepo();

            self::fails(
                fn() => Paginator::cursor($repo->where(Qb::eq('active', 0)), 2, new CursorKey('id'), 'garbage'),
                InvalidCursorException::class,
            );

            self::assertCount(5, $repo->findAll());
        });
    }

    #[DataProvider('runtimes')]
    public function testARefusedPageLeavesNoConditionBehind(bool $coroutine): void
    {
        $this->inRuntime($coroutine, function (): void {
            $repo = new ResetUsersRepo();

            self::fails(fn() => Wrapper::paginator($repo->where(Qb::eq('active', 0)), 10, page: 0), ValueError::class);
            self::assertCount(5, $repo->findAll());

            self::fails(fn() => Paginator::repo($repo->where(Qb::eq('active', 0)), 0), ValueError::class);
            self::assertCount(5, $repo->findAll());
        });
    }

    #[DataProvider('runtimes')]
    public function testAPageWhoseCountFailsLeavesNoQueryBehind(bool $coroutine): void
    {
        $this->inRuntime($coroutine, function (): void {
            $repo = new ResetUsersRepo();
            $this->pdo->exec('ALTER TABLE users RENAME TO users_away');
            self::fails(fn() => Paginator::repo($repo->where(Qb::eq('active', 0)), 2), Throwable::class);
            $this->pdo->exec('ALTER TABLE users_away RENAME TO users');

            self::assertCount(5, $repo->findAll());
        });
    }
}
