<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\E2e;

use Flytachi\Winter\Cdo\Config\MySqlDbConfig;
use Flytachi\Winter\Cdo\Connection\CDO;

/**
 * MariaDB: E2E_MARIA=host:port:database:user:password. The tables of no schema live in that
 * database; those of the schema `shop` in a database `shop` beside it — made by the reset, as
 * a DBA makes it: on MySQL / MariaDB ppa creates no database.
 *
 * A schema change commits at once there: a version is no transaction ({@see transactional()}).
 */
class MariaDriver implements Driver
{
    public function name(): string
    {
        return 'mysql';
    }

    public function title(\PDO $db): string
    {
        return 'MariaDB ' . preg_replace('/-MariaDB.*$/', '', (string) $db->query('SELECT VERSION()')->fetchColumn());
    }

    public function configBase(): string
    {
        return MySqlDbConfig::class;
    }

    public function env(): string
    {
        return 'E2E_MARIA';
    }

    public function open(): CDO
    {
        $config = new class extends MySqlDbConfig {
            public string $env = '';

            public function setUp(): void
            {
                [$this->host, $port, $this->database, $this->username, $this->password] = explode(':', (string) getenv($this->env));
                $this->port = (int) $port;
            }
        };
        $config->env = $this->env();
        $config->setUp();
        return $config->connection();
    }

    public function reset(\PDO $db): void
    {
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (col($db, 'SHOW TABLES') as $t) {
            $db->exec("DROP TABLE `{$t}`");
        }
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
        $db->exec('DROP DATABASE IF EXISTS shop');
        $db->exec('CREATE DATABASE shop');
    }

    /**
     * Columns (in order, with their full type, NULL, default, extra — auto_increment), indexes
     * (name, unique, kind, columns in order), constraints (checks with their expression,
     * foreign keys with their columns, target and actions). The application's database is
     * named `main` here: A, B and C run in the same one, but the dump says what, not where.
     */
    public function dump(\PDO $db): array
    {
        $main = (string) one($db, 'SELECT DATABASE()');
        $in = "IN ('{$main}', 'shop')";
        $where = fn(string $alias = '') => "{$alias}TABLE_SCHEMA {$in} AND {$alias}TABLE_NAME <> 'winter_ppa_schema_history'";
        $table = fn(string $alias = '') => "CONCAT(IF({$alias}TABLE_SCHEMA = 'shop', 'shop', 'main'), '.', {$alias}TABLE_NAME)";
        return [
            'columns' => col($db, "SELECT CONCAT_WS(' | ', {$table()}, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, IFNULL(COLUMN_DEFAULT, ''), EXTRA)"
                . " FROM information_schema.COLUMNS WHERE {$where()} ORDER BY TABLE_SCHEMA = 'shop', TABLE_NAME, ORDINAL_POSITION"),
            'indexes' => col($db, "SELECT CONCAT_WS(' ', {$table()}, INDEX_NAME, IF(NON_UNIQUE, 'index', 'unique'), INDEX_TYPE,"
                . " GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX))"
                . " FROM information_schema.STATISTICS WHERE {$where()} GROUP BY TABLE_SCHEMA, TABLE_NAME, INDEX_NAME, NON_UNIQUE, INDEX_TYPE ORDER BY 1"),
            'constraints' => [
                // CHECK_CONSTRAINTS has no table on MySQL: TABLE_CONSTRAINTS says it.
                ...col($db, "SELECT CONCAT_WS(' ', CONCAT(IF(t.TABLE_SCHEMA = 'shop', 'shop', 'main'), '.', t.TABLE_NAME), c.CONSTRAINT_NAME, 'CHECK', c.CHECK_CLAUSE)"
                    . ' FROM information_schema.CHECK_CONSTRAINTS c JOIN information_schema.TABLE_CONSTRAINTS t'
                    . " ON t.CONSTRAINT_SCHEMA = c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME = c.CONSTRAINT_NAME AND t.CONSTRAINT_TYPE = 'CHECK'"
                    . " WHERE c.CONSTRAINT_SCHEMA {$in} AND t.TABLE_NAME <> 'winter_ppa_schema_history' ORDER BY 1"),
                ...col($db, "SELECT CONCAT_WS(' ', {$table('k.')}, k.CONSTRAINT_NAME, 'FOREIGN KEY',"
                    . ' GROUP_CONCAT(k.COLUMN_NAME ORDER BY k.ORDINAL_POSITION), \'→\','
                    . " CONCAT(IF(k.REFERENCED_TABLE_SCHEMA = 'shop', 'shop', 'main'), '.', k.REFERENCED_TABLE_NAME),"
                    . " GROUP_CONCAT(k.REFERENCED_COLUMN_NAME ORDER BY k.ORDINAL_POSITION), 'ON UPDATE', r.UPDATE_RULE, 'ON DELETE', r.DELETE_RULE)"
                    . ' FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r'
                    . ' ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME'
                    . " WHERE {$where('k.')} AND k.REFERENCED_TABLE_NAME IS NOT NULL"
                    . ' GROUP BY k.TABLE_SCHEMA, k.TABLE_NAME, k.CONSTRAINT_NAME, k.REFERENCED_TABLE_SCHEMA, k.REFERENCED_TABLE_NAME,'
                    . ' r.UPDATE_RULE, r.DELETE_RULE ORDER BY 1'),
            ],
        ];
    }

    public function steps(): array
    {
        return MariaScenario::steps();
    }

    public function fill(\PDO $db): void
    {
        MariaData::fill($db);
    }

    public function counts(int $version): array
    {
        return MariaData::counts($version);
    }

    public function refused(State $last): array
    {
        return MariaScenario::refused($last);
    }

    public function historySchema(): ?string
    {
        return null;
    }

    public function transactional(): bool
    {
        return false;
    }
}
