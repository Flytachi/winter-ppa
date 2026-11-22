<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Tests\Schema\Migration;

use Flytachi\Winter\Cdo\Config\Common\DbConfigInterface;
use Flytachi\Winter\Ppa\Tests\Schema\Fixtures\MysqlTestConfig;
use PHPUnit\Framework\Attributes\Group;

require_once __DIR__ . '/MigrationScenarios.php';

/** The scenarios on MySQL — PPA_MYSQL=host:port:database:user:password. */
#[Group('integration')]
final class MysqlMigrationTest extends MigrationScenarios
{
    protected function config(): DbConfigInterface
    {
        if (getenv('PPA_MYSQL') === false) {
            self::markTestSkipped('MySQL: set PPA_MYSQL=host:port:database:user:password.');
        }
        return new MysqlTestConfig();
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
