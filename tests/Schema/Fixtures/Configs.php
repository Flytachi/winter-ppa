<?php

declare(strict_types=1);

// Database configs of the schema tests. PostgreSQL, MariaDB and MySQL come from the
// environment — PPA_PG, PPA_MARIA, PPA_MYSQL as host:port:database:user:password —
// and their tests are skipped without it. SQLite is a file of the test.

namespace Flytachi\Winter\Ppa\Tests\Schema\Fixtures;

use Flytachi\Winter\Cdo\Config\MySqlDbConfig;
use Flytachi\Winter\Cdo\Config\PgDbConfig;
use Flytachi\Winter\Cdo\Config\SqliteDbConfig;

final class LiteTestConfig extends SqliteDbConfig
{
    public static string $file = ':memory:';

    public function setUp(): void
    {
        $this->path = self::$file;
    }
}

final class PgTestConfig extends PgDbConfig
{
    public function setUp(): void
    {
        [$this->host, $port, $this->database, $this->username, $this->password] = explode(':', (string) getenv('PPA_PG'));
        $this->port = (int) $port;
    }
}

final class MariaTestConfig extends MySqlDbConfig
{
    public function setUp(): void
    {
        [$this->host, $port, $this->database, $this->username, $this->password] = explode(':', (string) getenv('PPA_MARIA'));
        $this->port = (int) $port;
    }
}

final class MysqlTestConfig extends MySqlDbConfig
{
    public function setUp(): void
    {
        [$this->host, $port, $this->database, $this->username, $this->password] = explode(':', (string) getenv('PPA_MYSQL'));
        $this->port = (int) $port;
    }
}
