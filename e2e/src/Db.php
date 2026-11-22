<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\E2e;

use Flytachi\Winter\Cdo\Config\PgDbConfig;
use Flytachi\Winter\Cdo\Connection\CDO;

/** The database the scenario runs on: E2E_PG=host:port:database:user:password. */
final class Db extends PgDbConfig
{
    public function setUp(): void
    {
        [$this->host, $port, $this->database, $this->username, $this->password] = explode(':', (string) getenv('E2E_PG'));
        $this->port = (int) $port;
    }

    public static function open(): CDO
    {
        $config = new self();
        $config->setUp();
        return $config->connection();
    }

    /** Back to an empty database: no tables, no schema `shop`, no extension, no history. */
    public static function reset(\PDO $db): void
    {
        $db->exec('DROP EXTENSION IF EXISTS pg_trgm CASCADE');
        $db->exec('DROP EXTENSION IF EXISTS btree_gist CASCADE');
        $db->exec('DROP SCHEMA IF EXISTS shop CASCADE');
        $db->exec('DROP SCHEMA IF EXISTS public CASCADE');
        $db->exec('CREATE SCHEMA public');
    }

    /**
     * The schema as the server has it — what variants A, B and C must end with alike:
     * columns (by order, not by position: a dropped column leaves a gap in PostgreSQL's
     * numbering), indexes, constraints, extensions, schemas.
     *
     * @return array<string, list<string>>
     */
    public static function dump(\PDO $db): array
    {
        $in = "IN ('public', 'shop')";
        $history = "'winter_ppa_schema_history'";
        return [
            'schemas' => col($db, "SELECT schema_name FROM information_schema.schemata WHERE schema_name {$in} ORDER BY 1"),
            'extensions' => col($db, "SELECT extname FROM pg_extension WHERE extname <> 'plpgsql' ORDER BY 1"),
            'columns' => array_map(fn(array $r) => implode(' | ', $r), $db->query(
                "SELECT table_schema || '.' || table_name, column_name, data_type, coalesce(character_maximum_length::text, ''),"
                . " coalesce(numeric_precision::text, '') || ',' || coalesce(numeric_scale::text, ''), is_nullable,"
                . " coalesce(column_default, ''), is_identity, coalesce(identity_generation, '')"
                . " FROM information_schema.columns WHERE table_schema {$in} AND table_name <> {$history}"
                . ' ORDER BY table_schema, table_name, ordinal_position'
            )->fetchAll(\PDO::FETCH_NUM)),
            'indexes' => col($db, "SELECT indexdef FROM pg_indexes WHERE schemaname {$in} AND tablename <> {$history} ORDER BY indexname"),
            'constraints' => col($db, "SELECT n.nspname || '.' || c.relname || ' ' || k.conname || ' ' || pg_get_constraintdef(k.oid)"
                . ' FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid JOIN pg_namespace n ON n.oid = c.relnamespace'
                . " WHERE n.nspname {$in} AND c.relname <> {$history} ORDER BY 1"),
        ];
    }
}
