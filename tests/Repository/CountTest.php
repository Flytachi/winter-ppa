<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Tests\Repository;

use Flytachi\Winter\Cdo\Config\SqliteDbConfig;
use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\Ppa\Pool\PpaConnectionPool;
use Flytachi\Winter\Ppa\Stereotype\Repository;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** A file-backed SQLite database for counting against real rows. */
final class CountSqliteDb extends SqliteDbConfig
{
    public static string $file = '';

    public function setUp(): void
    {
        $this->path = self::$file;
    }
}

final class CountUser
{
    public ?int $id = null;
    public string $name = '';
    public int $active = 1;
}

final class CountUsersRepo extends Repository
{
    public static string $table = 'users';
    protected string $dbConfigClassName = CountSqliteDb::class;
    protected string $entityClassName = CountUser::class;
}

/**
 * `count()` answers "how many rows match", whatever the query is built of.
 *
 * It used to replace the SELECT list with `COUNT(…)` and keep everything else, then read
 * the first row: with GROUP BY that is the size of the first group, with LIMIT/OFFSET the
 * single count row was skipped (0), and a custom `select('a, b')` became `COUNT(a, b)`. A
 * query that is not a plain filter is now counted as a subquery; ORDER BY / LIMIT / OFFSET /
 * FOR never take part.
 */
final class CountTest extends TestCase
{
    protected function setUp(): void
    {
        CountSqliteDb::$file = tempnam(sys_get_temp_dir(), 'ppa-count-');
        $pdo = new PDO('sqlite:' . CountSqliteDb::$file);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, active INT)');
        $pdo->exec("CREATE TABLE archived (id INTEGER PRIMARY KEY, name TEXT, active INT)");
        foreach ([['a', 1], ['b', 1], ['c', 1], ['d', 0], ['e', 0]] as [$name, $active]) {
            $pdo->exec("INSERT INTO users (name, active) VALUES ('{$name}', {$active})");
        }
        $pdo->exec("INSERT INTO archived (name, active) VALUES ('x', 1), ('y', 1)");
        PpaConnectionPool::reset();
    }

    protected function tearDown(): void
    {
        PpaConnectionPool::reset();
        @unlink(CountSqliteDb::$file);
    }

    private static function countSql(CountUsersRepo $repo): string
    {
        return preg_replace('/:iqb\d+/', ':v', new ReflectionMethod($repo, 'countSql')->invoke($repo));
    }

    public function testAPlainFilterCountsWithTheSameSqlAsBefore(): void
    {
        $repo = CountUsersRepo::instance()->where(Qb::eq('active', 1));

        self::assertSame('SELECT COUNT(*) FROM users WHERE active = :v', self::countSql($repo));
        self::assertSame(3, CountUsersRepo::instance()->where(Qb::eq('active', 1))->count());
    }

    public function testOrderLimitOffsetAndForDoNotTakePart(): void
    {
        $repo = CountUsersRepo::instance()->where(Qb::eq('active', 1))->orderBy('name')->limit(2, 2);

        self::assertSame('SELECT COUNT(*) FROM users WHERE active = :v', self::countSql($repo));
        self::assertSame(3, $repo->count(), 'the total, not the page');
    }

    public function testAGroupedQueryCountsItsGroups(): void
    {
        $repo = CountUsersRepo::instance()->select('active, COUNT(*) AS n')->groupBy('active');

        self::assertSame(
            'SELECT COUNT(*) FROM (SELECT active, COUNT(*) AS n FROM users GROUP BY active) AS tmp',
            self::countSql($repo),
        );
        self::assertSame(2, $repo->count());
    }

    public function testAGroupedQueryWithoutASelectCountsByAConstant(): void
    {
        // Without a select the inner query would list every entity column — invalid with
        // GROUP BY on PostgreSQL and strict MySQL (SQLite would let it pass).
        $repo = CountUsersRepo::instance()->groupBy('active');

        self::assertSame('SELECT COUNT(*) FROM (SELECT 1 FROM users GROUP BY active) AS tmp', self::countSql($repo));
        self::assertSame(2, $repo->count());
    }

    public function testHavingIsHonoured(): void
    {
        $repo = CountUsersRepo::instance()->select('active')->groupBy('active')->having('COUNT(*) > 2');

        self::assertSame(1, $repo->count());
    }

    public function testACustomSelectOfSeveralColumnsCountsRows(): void
    {
        self::assertSame(5, CountUsersRepo::instance()->select('id, name')->count());
    }

    public function testADistinctSelectCountsDistinctRows(): void
    {
        self::assertSame(2, CountUsersRepo::instance()->select('DISTINCT active')->count());
    }

    public function testAUnionCountsBothParts(): void
    {
        $repo = CountUsersRepo::instance()->select('name')
            ->unionAll(CountUsersRepo::instance()->select('name')->from('archived'));

        self::assertSame(7, $repo->count());
    }

    public function testACteFeedsTheCount(): void
    {
        $repo = CountUsersRepo::instance()
            ->with('act', CountUsersRepo::instance()->where(Qb::eq('active', 1)))
            ->from('act');

        self::assertSame(3, $repo->count());
    }

    public function testTheRepositoryIsResetAfterwards(): void
    {
        $repo = CountUsersRepo::instance()->groupBy('active');
        $repo->count();

        self::assertSame(0, $repo->sqlPartsCount());
    }
}
