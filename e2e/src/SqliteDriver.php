<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\E2e;

use Flytachi\Winter\Cdo\Config\SqliteDbConfig;
use Flytachi\Winter\Cdo\Connection\CDO;

/**
 * SQLite: E2E_SQLITE=/path/to/e2e.sqlite — a file of its own, emptied. Foreign keys on, as cdo
 * (4.2) opens a connection: what a version rebuilds must keep every row pointing at one.
 */
final class SqliteDriver implements Driver
{
    public function name(): string
    {
        return 'sqlite';
    }

    public function title(\PDO $db): string
    {
        return 'SQLite ' . $db->query('SELECT sqlite_version()')->fetchColumn();
    }

    public function configBase(): string
    {
        return SqliteDbConfig::class;
    }

    public function env(): string
    {
        return 'E2E_SQLITE';
    }

    public function open(): CDO
    {
        $config = new class extends SqliteDbConfig {
            public function setUp(): void
            {
                $this->path = (string) getenv('E2E_SQLITE');
            }
        };
        $config->setUp();
        $db = $config->connection();
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        // Foreign keys on by cdo itself (4.2): checked, not assumed.
        expect((int) $db->query('PRAGMA foreign_keys')->fetchColumn() === 1, 'cdo left SQLite foreign keys off');
        return $db;
    }

    public function reset(\PDO $db): void
    {
        $db->exec('PRAGMA foreign_keys = OFF');
        foreach (col($db, "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\'") as $t) {
            $db->exec("DROP TABLE \"{$t}\"");
        }
        if (one($db, "SELECT count(*) FROM sqlite_master WHERE name = 'sqlite_sequence'")) {
            $db->exec('DELETE FROM sqlite_sequence');
        }
        $db->exec('PRAGMA foreign_keys = ON');
    }

    /**
     * Read from the PRAGMAs, not from the CREATE TABLE text: SQLite keeps the statement as it
     * was given and edits it on ALTER (a rename quotes the name), so a table rebuilt and one
     * made fresh say the same with other words. Columns (in order, with type, NOT NULL,
     * default, key), AUTOINCREMENT, indexes (unique, partial — with their WHERE —, columns),
     * foreign keys (columns, target, actions), the named constraints and their checks.
     */
    public function dump(\PDO $db): array
    {
        $out = ['tables' => [], 'columns' => [], 'indexes' => [], 'constraints' => []];
        $tables = col($db, "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\'"
            . " AND name <> 'winter_ppa_schema_history' ORDER BY name");
        foreach ($tables as $t) {
            $sql = (string) one($db, "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = '{$t}'");
            $out['tables'][] = $t . (stripos($sql, 'AUTOINCREMENT') !== false ? ' AUTOINCREMENT' : '');
            foreach ($db->query("PRAGMA table_info(\"{$t}\")")->fetchAll(\PDO::FETCH_ASSOC) as $c) {
                $out['columns'][] = implode(' | ', [$t, $c['name'], strtoupper((string) $c['type']), $c['notnull'], (string) $c['dflt_value'], $c['pk']]);
            }
            foreach ($db->query("PRAGMA index_list(\"{$t}\")")->fetchAll(\PDO::FETCH_ASSOC) as $i) {
                $cols = implode(',', array_column($db->query("PRAGMA index_info(\"{$i['name']}\")")->fetchAll(\PDO::FETCH_ASSOC), 'name'));
                $def = (string) one($db, "SELECT sql FROM sqlite_master WHERE type = 'index' AND name = '{$i['name']}'");
                $where = preg_match('/\bWHERE\b(.*)$/is', $def, $m) ? ' WHERE ' . preg_replace('/\s+/', ' ', trim($m[1])) : '';
                $name = str_starts_with($i['name'], 'sqlite_autoindex_') ? "({$i['origin']})" : $i['name'];
                $out['indexes'][] = "{$t} {$name} " . ($i['unique'] ? 'unique' : 'index') . " {$cols}{$where}";
            }
            foreach ($db->query("PRAGMA foreign_key_list(\"{$t}\")")->fetchAll(\PDO::FETCH_ASSOC) as $k) {
                $out['constraints'][] = "{$t} FOREIGN KEY {$k['from']} → {$k['table']}({$k['to']}) ON UPDATE {$k['on_update']} ON DELETE {$k['on_delete']}";
            }
            preg_match_all('/CONSTRAINT\s+(\w+)\s+(CHECK\s*\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)|FOREIGN KEY)/i', $sql, $m, PREG_SET_ORDER);
            foreach ($m as [, $cname, $what]) {
                $out['constraints'][] = "{$t} {$cname} " . preg_replace('/\s+/', ' ', $what);
            }
        }
        foreach ($out as &$list) {
            sort($list);
        }
        return $out;
    }

    public function steps(): array
    {
        return SqliteScenario::steps();
    }

    public function fill(\PDO $db): void
    {
        SqliteData::fill($db);
    }

    public function counts(int $version): array
    {
        return SqliteData::counts($version);
    }

    public function refused(State $last): array
    {
        return SqliteScenario::refused($last);
    }

    public function historySchema(): ?string
    {
        return null;
    }

    public function transactional(): bool
    {
        return true;
    }
}
