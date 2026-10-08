<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Tests\Pool;

use Flytachi\Winter\Cdo\Config\SqliteDbConfig;
use Flytachi\Winter\Ppa\Pool\CdoConnectionFactory;
use Flytachi\Winter\Ppa\Pool\PpaConnectionPool;
use Flytachi\Winter\Ppa\Tests\Fixtures\StubDbConfig;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

/** A file-backed SQLite config, so an independent connection can see what was committed. */
final class ResetSqliteDb extends SqliteDbConfig
{
    public static string $file = '';

    public function setUp(): void
    {
        $this->path = self::$file;
    }
}

/** Keeps every record, so a test can assert on level and message. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
    }

    /** @return list<string> */
    public function messagesAt(string $level): array
    {
        $messages = [];
        foreach ($this->records as $record) {
            if ($record['level'] === $level) {
                $messages[] = $record['message'];
            }
        }
        return $messages;
    }
}

/**
 * A connection must not go back to the pool with a transaction still open on it.
 * Otherwise the next borrower inherits it: its own writes land inside that transaction
 * and are lost with it, and its `beginTransaction()` fails with "already active".
 */
final class ConnectionResetTest extends TestCase
{
    protected function setUp(): void
    {
        ResetSqliteDb::$file = tempnam(sys_get_temp_dir(), 'ppa-reset-');
        new PDO('sqlite:' . ResetSqliteDb::$file)->exec('CREATE TABLE t (who TEXT)');
    }

    protected function tearDown(): void
    {
        @unlink(ResetSqliteDb::$file);
    }

    /** What actually reached the database, read over a connection outside the pool. */
    private static function committed(): array
    {
        return new PDO('sqlite:' . ResetSqliteDb::$file)
            ->query('SELECT who FROM t ORDER BY rowid')
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    public function test_a_clean_connection_is_kept_without_a_word(): void
    {
        $log = new RecordingLogger();
        $factory = new CdoConnectionFactory(ResetSqliteDb::class, $log);
        $config = $factory->create();

        self::assertTrue($factory->reset($config));
        self::assertSame([], $log->messagesAt('error'));
    }

    public function test_an_open_transaction_is_rolled_back_and_reported(): void
    {
        $log = new RecordingLogger();
        $factory = new CdoConnectionFactory(ResetSqliteDb::class, $log);
        $config = $factory->create();
        $cdo = $config->connection();
        $cdo->beginTransaction();
        $cdo->exec("INSERT INTO t VALUES ('left open')");

        self::assertTrue($factory->reset($config), 'rolled back cleanly — the connection is reusable');
        self::assertFalse($cdo->inTransaction());
        self::assertSame([], self::committed(), 'the unfinished work is discarded, never committed');

        $errors = $log->messagesAt('error');
        self::assertCount(1, $errors);
        self::assertStringContainsString(ResetSqliteDb::class, $errors[0]);
        self::assertStringContainsString('open transaction', $errors[0]);
    }

    public function test_a_connection_that_cannot_be_inspected_is_retired(): void
    {
        $log = new RecordingLogger();
        $factory = new CdoConnectionFactory(StubDbConfig::class, $log);

        self::assertFalse($factory->reset(new class extends StubDbConfig {
        }));
        self::assertCount(1, $log->messagesAt('warning'), 'a connection that cannot be inspected is reported as lost');
        self::assertSame([], $log->messagesAt('error'), 'not as an open transaction');
    }

    /** The scenario end to end: requests are coroutines sharing one pooled connection. */
    public function test_the_next_request_does_not_inherit_a_forgotten_transaction(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('the pool path needs Swoole.');
        }

        $log = new RecordingLogger();
        $out = [];
        \Swoole\Coroutine\run(static function () use ($log, &$out): void {
            PpaConnectionPool::setLogger($log);
            $request = static function (callable $body): void {
                $done = new \Swoole\Coroutine\WaitGroup(1);
                go(static function () use ($body, $done): void {
                    $body(PpaConnectionPool::db(ResetSqliteDb::class));
                    $done->done();
                });
                $done->wait();
            };

            $request(static function ($db): void {
                $db->beginTransaction();
                $db->exec("INSERT INTO t VALUES ('A')");   // and never commits
            });
            $request(static function ($db) use (&$out): void {
                $out['inheritedTransaction'] = $db->inTransaction();
                $db->exec("INSERT INTO t VALUES ('B')");
            });
            $request(static function ($db): void {
                $db->transaction(static fn() => $db->exec("INSERT INTO t VALUES ('C')"));
            });

            $out['stats'] = PpaConnectionPool::stats()[ResetSqliteDb::class];
            PpaConnectionPool::shutdown();
        });

        self::assertFalse($out['inheritedTransaction']);
        self::assertSame(['B', 'C'], self::committed(), 'B autocommits, C opens its own transaction');
        self::assertSame(1, $out['stats']['total'], 'the rolled-back connection stays in service');
        self::assertCount(1, $log->messagesAt('error'));
    }
}
