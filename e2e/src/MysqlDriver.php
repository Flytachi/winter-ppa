<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\E2e;

/**
 * MySQL: E2E_MYSQL=host:port:database:user:password — the scenario of MariaDB
 * ({@see MariaScenario}): one driver to ppa, `mysql`, one life.
 */
final class MysqlDriver extends MariaDriver
{
    public function title(\PDO $db): string
    {
        return 'MySQL ' . $db->query('SELECT VERSION()')->fetchColumn();
    }

    public function env(): string
    {
        return 'E2E_MYSQL';
    }
}
