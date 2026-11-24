<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Tests\Pool;

use Flytachi\Winter\Cdo\Config\SqliteDbConfig;
use Flytachi\Winter\Ppa\Pool\PpaConnectionPool;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

/** A file-backed SQLite database for the coroutine pool. */
final class ReleaseSqliteDb extends SqliteDbConfig
{
    public static string $file = '';

    public function setUp(): void
    {
        $this->path = self::$file;
    }
}

/** Keeps each record's level and message. */
final class ReleaseLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $lines = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->lines[] = "{$level} {$message}";
    }
}

/**
 * A coroutine gives its connection back when it says so, not only when it ends — a
 * Server-Sent Events stream lives for hours on the coroutine whose middleware read the
 * session, and held a pooled connection all that time.
 */
final class ReleaseCurrentTest extends TestCase
{
    private ReleaseLogger $log;

    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('the coroutine path needs Swoole.');
        }
        ReleaseSqliteDb::$file = tempnam(sys_get_temp_dir(), 'ppa-release-');
        $this->log = new ReleaseLogger();
        PpaConnectionPool::setLogger($this->log);
    }

    protected function tearDown(): void
    {
        PpaConnectionPool::reset();
        @unlink(ReleaseSqliteDb::$file);
    }

    /** @return array{total: int, idle: int, active: int, maximum: int} */
    private static function stats(): array
    {
        return PpaConnectionPool::stats()[ReleaseSqliteDb::class];
    }

    /**
     * The coroutine is over for the test only once the pool has its connection back: a
     * defer registered first runs last, after the pool's. Without waiting, the outer
     * coroutine reads the stats — or shuts the pool — while the inner one is still in a
     * query, wherever SQLite yields (swoole's coroutine_sqlite).
     */
    private static function afterRelease(\Swoole\Coroutine\WaitGroup $done): void
    {
        \Swoole\Coroutine::defer(static fn() => $done->done());
    }

    public function test_the_connection_goes_back_now_and_comes_again_on_the_next_query(): void
    {
        $out = [];
        \Swoole\Coroutine\run(function () use (&$out): void {
            $done = new \Swoole\Coroutine\WaitGroup(1);
            \Swoole\Coroutine\go(function () use (&$out, $done): void {
                self::afterRelease($done);
                PpaConnectionPool::db(ReleaseSqliteDb::class)->query('SELECT 1');
                $out['before'] = self::stats()['active'];
                PpaConnectionPool::releaseCurrent();
                $out['after'] = self::stats()['active'];
                PpaConnectionPool::db(ReleaseSqliteDb::class)->query('SELECT 1');
                $out['again'] = self::stats()['active'];
            });
            $done->wait();
            $out['ended'] = self::stats();
            PpaConnectionPool::shutdown();
        });

        self::assertSame(1, $out['before']);
        self::assertSame(0, $out['after'], 'given back at once');
        self::assertSame(1, $out['again'], 'a later query in the same coroutine borrows again');
        self::assertSame(['total' => 1, 'idle' => 1, 'active' => 0], array_intersect_key($out['ended'], ['total' => 0, 'idle' => 0, 'active' => 0]), 'released once at the end, not twice');
    }

    public function test_a_connection_inside_a_transaction_is_kept_with_a_warning(): void
    {
        $out = [];
        \Swoole\Coroutine\run(function () use (&$out): void {
            $done = new \Swoole\Coroutine\WaitGroup(1);
            \Swoole\Coroutine\go(function () use (&$out, $done): void {
                self::afterRelease($done);
                $db = PpaConnectionPool::db(ReleaseSqliteDb::class);
                $db->beginTransaction();
                PpaConnectionPool::releaseCurrent();
                $out['held'] = self::stats()['active'];
                $out['same'] = PpaConnectionPool::db(ReleaseSqliteDb::class) === $db;
                $db->rollBack();
            });
            $done->wait();
            $out['ended'] = self::stats()['active'];
            PpaConnectionPool::shutdown();
        });

        self::assertSame(1, $out['held'], 'giving it back would hand the transaction to the next borrower');
        self::assertTrue($out['same']);
        self::assertSame(0, $out['ended']);
        $warnings = array_values(array_filter($this->log->lines, static fn(string $l): bool => str_starts_with($l, 'warning')));
        self::assertCount(1, $warnings);
        self::assertStringContainsString(ReleaseSqliteDb::class, $warnings[0]);
        self::assertStringContainsString('transaction', $warnings[0]);
    }

    public function test_it_is_a_no_op_without_a_connection_or_outside_a_coroutine(): void
    {
        PpaConnectionPool::releaseCurrent();
        \Swoole\Coroutine\run(static function (): void {
            PpaConnectionPool::releaseCurrent();
        });
        self::assertSame([], $this->log->lines);
    }
}
