<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Tests\Pool;

use Flytachi\Winter\Cdo\Config\SqliteDbConfig;
use Flytachi\Winter\Ppa\Pool\PpaConnectionPool;
use Flytachi\Winter\Ppa\Pool\PpaPoolException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** A file-backed SQLite config for the non-coroutine connection. */
final class ForkSqliteDb extends SqliteDbConfig
{
    public static string $file = '';

    public function setUp(): void
    {
        $this->path = self::$file;
    }
}

/**
 * The parent closes its own connections before a fork, so the child inherits nothing it
 * could close the parent's session through. A connection mid-transaction cannot be
 * closed without rolling its owner's work back, so the fork is refused instead.
 *
 * What the fork itself does to a session is proven against live PostgreSQL and MariaDB in
 * the kernel's process integration tests; this covers the contract.
 */
#[CoversClass(PpaConnectionPool::class)]
final class CloseBeforeForkTest extends TestCase
{
    protected function setUp(): void
    {
        ForkSqliteDb::$file = tempnam(sys_get_temp_dir(), 'ppa-fork-');
        PpaConnectionPool::reset();
    }

    protected function tearDown(): void
    {
        PpaConnectionPool::reset();
        @unlink(ForkSqliteDb::$file);
    }

    public function testAnIdleConnectionIsClosedAndReopensOnNextUse(): void
    {
        $before = PpaConnectionPool::db(ForkSqliteDb::class);

        PpaConnectionPool::closeBeforeFork();
        $after = PpaConnectionPool::db(ForkSqliteDb::class);

        self::assertNotSame($before, $after, 'a fresh connection, not the one a child could have inherited');
        self::assertSame('1', (string) $after->query('SELECT 1')->fetchColumn());
    }

    public function testAConnectionInATransactionRefusesTheFork(): void
    {
        $db = PpaConnectionPool::db(ForkSqliteDb::class);
        $db->beginTransaction();

        try {
            PpaConnectionPool::closeBeforeFork();
            self::fail('forking mid-transaction must be refused');
        } catch (PpaPoolException $e) {
            self::assertStringContainsString(ForkSqliteDb::class, $e->getMessage());
            self::assertStringContainsString('open transaction', $e->getMessage());
        }

        self::assertTrue($db->inTransaction(), 'the refusal leaves the transaction alone');
        self::assertSame($db, PpaConnectionPool::db(ForkSqliteDb::class), 'and the connection too');
        $db->rollBack();
    }

    public function testNothingOpenIsNothingToDo(): void
    {
        PpaConnectionPool::closeBeforeFork();

        $this->addToAssertionCount(1);
    }
}
