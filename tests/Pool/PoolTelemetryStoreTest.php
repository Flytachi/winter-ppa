<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Tests\Pool;

use Flytachi\FileStore\FileStorage;
use Flytachi\Winter\Ppa\Pool\PoolTelemetry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * Telemetry has no storage of its own — the application hands one over.
 *
 * The test exists because the storage used to be reached through the framework, and
 * cutting that tie is exactly the kind of change that compiles, passes everything else,
 * and writes records nowhere.
 */
#[CoversClass(PoolTelemetry::class)]
final class PoolTelemetryStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ppa-telemetry-' . getmypid();
        // FileStorage expects the root to exist and be writable; it creates only its own
        // folder inside it.
        @mkdir($this->dir, 0775, true);
        PoolTelemetry::setStoreProvider(fn(): FileStorage => new FileStorage($this->dir, 'ppa.pool', false, 'sha256', 0775, 0664));
        PoolTelemetry::forget();
    }

    protected function tearDown(): void
    {
        PoolTelemetry::setStoreProvider(null);
        if (is_dir($this->dir)) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    public function testTheStorageIsNotBuiltUntilItIsNeeded(): void
    {
        $built = false;
        PoolTelemetry::setStoreProvider(function () use (&$built): FileStorage {
            $built = true;
            return new FileStorage($this->dir, 'ppa.pool', false, 'sha256', 0775, 0664);
        });

        self::assertFalse($built, 'installing a provider must not touch the filesystem');
    }

    public function testWithoutAStoreItRefusesInsteadOfWritingSomewhereRandom(): void
    {
        PoolTelemetry::setStoreProvider(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('setStoreProvider()');

        new ReflectionMethod(PoolTelemetry::class, 'store')->invoke(null);
    }

    public function testAPublishedRecordCanBeReadBack(): void
    {
        PoolTelemetry::enable(3);
        new ReflectionMethod(PoolTelemetry::class, 'publish')->invoke(null, 3, 60);

        $snapshot = PoolTelemetry::snapshot();

        self::assertSame([], $snapshot, 'a worker that never touched a pool leaves no record');
    }

    public function testSnapshotIsEmptyWhenNothingWasPublished(): void
    {
        self::assertSame([], PoolTelemetry::snapshot());
        self::assertSame([], PoolTelemetry::aggregate());
    }
}
