<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\E2e;

use Flytachi\Winter\Cdo\Config\PgDbConfig;
use Flytachi\Winter\Cdo\Connection\CDO;
use Flytachi\Winter\Ppa\Schema\SchemaDiff;

/** PostgreSQL: E2E_PG=host:port:database:user:password. */
final class PgDriver implements Driver
{
    public function name(): string
    {
        return 'pgsql';
    }

    public function title(\PDO $db): string
    {
        return 'PostgreSQL ' . $db->query('SHOW server_version')->fetchColumn();
    }

    public function configBase(): string
    {
        return PgDbConfig::class;
    }

    public function env(): string
    {
        return 'E2E_PG';
    }

    public function open(): CDO
    {
        $config = new class extends PgDbConfig {
            public function setUp(): void
            {
                [$this->host, $port, $this->database, $this->username, $this->password] = explode(':', (string) getenv('E2E_PG'));
                $this->port = (int) $port;
            }
        };
        $config->setUp();
        return $config->connection();
    }

    /** Back to an empty database: no tables, no schema `shop`, no extension, no history. */
    public function reset(\PDO $db): void
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
    public function dump(\PDO $db): array
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

    public function steps(): array
    {
        return PgScenario::steps();
    }

    public function fill(\PDO $db): void
    {
        PgData::fill($db);
    }

    public function counts(int $version): array
    {
        return PgData::counts($version);
    }

    public function refused(State $last): array
    {
        return [
            ['a column gone, no --drop', $last->without('users', 'locale'), '/users\.locale is gone from the entity/'],
            ['a table gone, no --drop', $last->withoutTable('audit_events'), '/audit_events has no entity any more/'],
            ['a table renamed without #[RenamedFrom]', $last->retable('labels', 'tags', null, null), '/public\.labels has no entity any more/'],
            ['a key on the old name of a renamed table', $last->retable('labels', 'tags', null, "#[RenamedFrom('labels')]")->with('payments', 'label_id', ['#[SmallInteger]', "#[ForeignKey('labels', 'id')]"], '?int $label_id = null'), '/the foreign key fk_payments_label_id points at public\.labels, renamed to public\.tags/'],
            ['#[RenamedFrom] of no table', $last->table('labels', ['attrs' => ["#[RenamedFrom('nope')]"]]), '/`public\.nope` is not a table of the last snapshot/'],
            ['#[RenamedFrom] of a table declared too', $last->table('labels', ['attrs' => ["#[RenamedFrom('users')]"]]), '/`public\.users` is a table of the entities too/'],
            ['a rename without #[RenamedFrom]', $last->renamed('users', 'email', 'mail', ['#[Varchar(160)]', '#[Unique]'], "string \$mail = ''"), '/users\.email is gone from the entity/'],
            ['#[RenamedFrom] of no column', $last->with('users', 'nick', ['#[Varchar(20)]', "#[RenamedFrom('nope')]"], '?string $nick = null'), '/`nope` is not a column of the last snapshot/'],
            ['NOT NULL without a default', $last->with('users', 'code', ['#[Varchar(8)]'], 'string $code'), '/users\.code is NOT NULL with no default/'],
            ['a narrowing, not custom', $last->attrs('users', 'name', fn($a) => Scenario::swap($a, '#[Varchar(200)]', '#[Varchar(50)]')), '/users\.name/'],
            ['a primary key changed, not custom', $last->attrs('audit_events', 'happened', fn($a) => Scenario::swap($a, '#[Primary]', null)), '/primary key changes/'],
            ['a unique HASH index', $last->attrs('users', 'locale', fn($a) => [...$a, '#[Unique(method: IndexMethod::HASH)]']), '/a unique index is a B-tree/'],
            ['an extension dropped without --drop', $last->withoutExtension('btree_gist')->attrs('audit_events', 'level', fn($a) => Scenario::swap($a, '#[Index(method: IndexMethod::GIST)]', null)), null, static function (SchemaDiff $diff): string {
                // Not refused — not done either: an extension leaves only by --drop.
                expect(!in_array('dropExtension', array_column($diff->changes, 'op'), true), 'the extension would be dropped');
                expect((bool) preg_grep('/--drop=extension:btree_gist drops it/', $diff->notices), 'no notice says how to drop it');
                return 'kept, said';
            }],
            ['GIN on a VARCHAR', $last->attrs('users', 'locale', fn($a) => [...$a, '#[Index(method: IndexMethod::GIN)]']), '/no GIN operator class for locale/'],
            ['HASH of two columns', $last->attrs('users', 'locale', fn($a) => [...$a, "#[Index(['email'], method: IndexMethod::HASH)]"]), '/a HASH index has one column/'],
            ['trigrams without pg_trgm', $last->withoutExtension('pg_trgm'), '/comes with the extension pg_trgm/'],
            ['an index of a column there is not', $last->attrs('users', 'locale', fn($a) => [...$a, "#[Index(['nick'])]"]), '/nick is no column of public\.users/'],
            ['two indexes of one name', $last->attrs('users', 'locale', fn($a) => [...$a, '#[Index]', '#[Index]']), '/two indexes are named public\.users_locale_idx|two indexes are named users_locale_idx/'],
        ];
    }

    public function historySchema(): ?string
    {
        return 'public';
    }

    public function transactional(): bool
    {
        return true;
    }
}
