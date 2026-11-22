<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Tests\Schema\Migration;

use Flytachi\Winter\Cdo\Config\Common\DbConfigInterface;
use Flytachi\Winter\Ppa\Tests\Schema\Fixtures\LiteTestConfig;

require_once __DIR__ . '/MigrationScenarios.php';

/** The scenarios on SQLite — a file of the test, always run. */
final class LiteMigrationTest extends MigrationScenarios
{
    protected function config(): DbConfigInterface
    {
        LiteTestConfig::$file = $this->dir . '/test.sqlite';
        return new LiteTestConfig();
    }

    protected function reset(): void
    {
        $this->db->exec('PRAGMA foreign_keys = ON');
        foreach ($this->db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(\PDO::FETCH_COLUMN) as $t) {
            $this->db->exec("DROP TABLE \"{$t}\"");
        }
    }
}
