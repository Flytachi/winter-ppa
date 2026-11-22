<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Tests\Schema\Migration;

use Flytachi\Winter\Cdo\Config\Common\DbConfigInterface;
use Flytachi\Winter\Ppa\Tests\Schema\Fixtures\MariaTestConfig;
use PHPUnit\Framework\Attributes\Group;

require_once __DIR__ . '/MigrationScenarios.php';

/** The scenarios on MariaDB — PPA_MARIA=host:port:database:user:password. */
#[Group('integration')]
final class MariaMigrationTest extends MigrationScenarios
{
    protected function config(): DbConfigInterface
    {
        if (getenv('PPA_MARIA') === false) {
            self::markTestSkipped('MariaDB: set PPA_MARIA=host:port:database:user:password.');
        }
        return new MariaTestConfig();
    }

    protected function reset(): void
    {
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->db->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $t) {
            $this->db->exec("DROP TABLE `{$t}`");
        }
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
