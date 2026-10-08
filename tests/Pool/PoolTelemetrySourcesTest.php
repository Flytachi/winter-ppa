<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Tests\Pool;

use Flytachi\FileStore\FileStorage;
use Flytachi\Winter\Cdo\Config\SqliteDbConfig;
use Flytachi\Winter\Ppa\Pool\PoolTelemetry;
use Flytachi\Winter\Ppa\Pool\PpaConnectionPool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** A pooled SQLite database, so a real pool exists to publish about. */
final class TelemetrySqliteDb extends SqliteDbConfig
{
    public static string $file = '';

    public function setUp(): void
    {
        $this->path = self::$file;
    }
}

/**
 * A pool lives in one process, and a web worker is not the only process that has one:
 * a managed process or a daemon worker keeps its own. Each publishes as a **source** —
 * a kind (`web`, `process`, `daemon`) plus a name — so `call db pool` can show them
 * apart: a saturated process pool says nothing about the web workers, and vice versa.
 */
#[CoversClass(PoolTelemetry::class)]
final class PoolTelemetrySourcesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ppa-telemetry-sources-' . getmypid() . '-' . bin2hex(random_bytes(3));
        @mkdir($this->dir, 0775, true);
        PoolTelemetry::setStoreProvider(fn(): FileStorage => $this->storage());
        PoolTelemetry::forget();
        TelemetrySqliteDb::$file = $this->dir . '/db.sqlite';
    }

    protected function tearDown(): void
    {
        PoolTelemetry::forget();
        PoolTelemetry::setStoreProvider(null);
        if (is_dir($this->dir)) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    private function storage(): FileStorage
    {
        return new FileStorage($this->dir, 'ppa.pool', false, 'sha256', 0775, 0664);
    }

    /** Writes a record the way another process would have published it. */
    private function seed(string $key, array $record): void
    {
        $this->storage()->write($key, $record + ['at' => time()], time() + 60);
    }

    /** @return array{total: int, idle: int, active: int, maximum: int} */
    private static function stat(int $active, int $idle, int $maximum): array
    {
        return ['total' => $active + $idle, 'idle' => $idle, 'active' => $active, 'maximum' => $maximum];
    }

    /** Opens a real pool inside a coroutine and publishes once, as the timer would. */
    private function publishFromAPool(int|string $source, ?string $kind = null): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('a pool, and so a publish, needs a coroutine.');
        }
        $kind === null ? PoolTelemetry::enable($source) : PoolTelemetry::enable($source, $kind);

        \Swoole\Coroutine\run(static function (): void {
            PpaConnectionPool::db(TelemetrySqliteDb::class)->query('SELECT 1');
            new ReflectionMethod(PoolTelemetry::class, 'publish')->invoke(null, 60);
            \Swoole\Timer::clearAll();     // the publisher; the record stays
            PpaConnectionPool::shutdown();
        });
    }

    public function testAWebWorkerStillPublishesUnderItsWorkerId(): void
    {
        $this->publishFromAPool(3);

        $records = PoolTelemetry::snapshot();

        self::assertCount(1, $records);
        self::assertSame(3, $records[0]['worker']);
        self::assertSame(PoolTelemetry::KIND_WEB, $records[0]['kind'], 'the default kind is web — workerStart is unchanged');
        self::assertSame(getmypid(), $records[0]['pid']);
        self::assertArrayHasKey(TelemetrySqliteDb::class, $records[0]['pools']);
    }

    public function testAProcessPublishesUnderItsNameAndKind(): void
    {
        $this->publishFromAPool('ReportProcess', PoolTelemetry::KIND_PROCESS);

        $records = PoolTelemetry::snapshot();

        self::assertCount(1, $records);
        self::assertSame('ReportProcess', $records[0]['worker']);
        self::assertSame(PoolTelemetry::KIND_PROCESS, $records[0]['kind']);
    }

    public function testAnAwkwardSourceNameStaysOneFileInTheStore(): void
    {
        $this->publishFromAPool('App\\Jobs/Import Daemon.2', PoolTelemetry::KIND_DAEMON);

        self::assertCount(1, PoolTelemetry::snapshot(), 'no subdirectory, no lost record');
        self::assertSame(['daemon.App_Jobs_Import_Daemon.2'], $this->storage()->keys());
    }

    public function testStopWithoutAnIdDropsThisProcesssOwnRecord(): void
    {
        $this->publishFromAPool('ReportProcess', PoolTelemetry::KIND_PROCESS);
        $this->seed('worker.0', ['worker' => 0, 'kind' => 'web', 'pools' => []]);

        PoolTelemetry::stop();

        self::assertSame(['worker.0'], $this->storage()->keys(), 'only its own record goes, the others stay');
    }

    public function testAWebWorkerKeepsTheKeyItAlwaysHad(): void
    {
        $this->publishFromAPool(2);

        self::assertSame(['worker.2'], $this->storage()->keys());
    }

    public function testStopWithAWorkerIdStillDropsThatWorkersRecord(): void
    {
        // The server's workerExit passes its id, even where enable() never ran.
        $this->seed('worker.5', ['worker' => 5, 'kind' => 'web', 'pools' => []]);
        new \ReflectionProperty(PoolTelemetry::class, 'published')->setValue(null, true);

        PoolTelemetry::stop(5);

        self::assertSame([], $this->storage()->keys());
    }

    public function testSnapshotOrdersWebThenProcessesThenDaemons(): void
    {
        $db = ['App\\Db' => self::stat(1, 1, 5)];
        $this->seed('daemon.Import.0', ['worker' => 'Import.0', 'kind' => 'daemon', 'pid' => 30, 'pools' => $db]);
        $this->seed('process.Report', ['worker' => 'Report', 'kind' => 'process', 'pid' => 20, 'pools' => $db]);
        $this->seed('web.1', ['worker' => 1, 'kind' => 'web', 'pid' => 11, 'pools' => $db]);
        $this->seed('web.0', ['worker' => 0, 'kind' => 'web', 'pid' => 10, 'pools' => $db]);

        $order = array_map(static fn(array $r): string => $r['kind'] . ':' . $r['worker'], PoolTelemetry::snapshot());

        self::assertSame(['web:0', 'web:1', 'process:Report', 'daemon:Import.0'], $order);
    }

    public function testARecordFromAnOlderWriterReadsAsAWebWorker(): void
    {
        // Written before sources existed: no kind, no pid.
        $this->seed('worker.4', ['worker' => 4, 'pools' => ['App\\Db' => self::stat(0, 2, 5)]]);

        $records = PoolTelemetry::snapshot();

        self::assertSame(PoolTelemetry::KIND_WEB, $records[0]['kind']);
        self::assertNull($records[0]['pid']);
    }

    public function testAggregateKeepsEachKindApart(): void
    {
        $this->seed('web.0', ['worker' => 0, 'kind' => 'web', 'pools' => ['App\\Db' => self::stat(2, 3, 5)]]);
        $this->seed('web.1', ['worker' => 1, 'kind' => 'web', 'pools' => ['App\\Db' => self::stat(1, 4, 5)]]);
        $this->seed('process.Report', ['worker' => 'Report', 'kind' => 'process', 'pools' => ['App\\Db' => self::stat(5, 0, 5)]]);

        $web = PoolTelemetry::aggregate(PoolTelemetry::KIND_WEB)['App\\Db'];
        $processes = PoolTelemetry::aggregate(PoolTelemetry::KIND_PROCESS)['App\\Db'];
        $all = PoolTelemetry::aggregate()['App\\Db'];

        self::assertSame(['total' => 10, 'idle' => 7, 'active' => 3, 'maximum' => 10, 'workers' => 2, 'saturated' => 0], $web);
        self::assertSame(['total' => 5, 'idle' => 0, 'active' => 5, 'maximum' => 5, 'workers' => 1, 'saturated' => 1], $processes);
        self::assertSame(15, $all['total'], 'without a kind: every connection this application holds open');
        self::assertSame(3, $all['workers']);
    }

    public function testAggregateOverAListOfKinds(): void
    {
        $this->seed('web.0', ['worker' => 0, 'kind' => 'web', 'pools' => ['App\\Db' => self::stat(1, 0, 5)]]);
        $this->seed('process.Report', ['worker' => 'Report', 'kind' => 'process', 'pools' => ['App\\Db' => self::stat(1, 0, 5)]]);
        $this->seed('daemon.Import.0', ['worker' => 'Import.0', 'kind' => 'daemon', 'pools' => ['App\\Db' => self::stat(2, 0, 5)]]);

        $background = PoolTelemetry::aggregate([PoolTelemetry::KIND_PROCESS, PoolTelemetry::KIND_DAEMON])['App\\Db'];

        self::assertSame(3, $background['active']);
        self::assertSame(2, $background['workers']);
    }
}
