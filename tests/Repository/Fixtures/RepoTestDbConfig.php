<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Tests\Repository\Fixtures;

use Flytachi\Winter\Ppa\Tests\Fixtures\StubDbConfig;

/**
 * Shared no-op DbConfig used by every Repository test. PpaConnectionPool
 * caches instances by class name, so the same config is reused across tests.
 */
final class RepoTestDbConfig extends StubDbConfig
{
    protected string $driver = 'pgsql';
}
