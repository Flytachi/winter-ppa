<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Schema;

use PDO;

/**
 * Reads what a live database has: its tables, their columns, primary keys, indexes and
 * constraints — from `information_schema` and `pg_catalog` (PostgreSQL), `information_schema`
 * (MySQL, MariaDB), `sqlite_master` and `PRAGMA` (SQLite).
 *
 * It answers "is it there" before a step runs — nothing is created to find out it exists,
 * so nothing lands in the server's log — and checks a database against a version's model.
 *
 * What the server adds by itself is not the application's and is left out: the index MySQL
 * creates for a foreign key, the CHECK MariaDB puts on a JSON column, the index behind a
 * primary key.
 */
final class SchemaInspector
{
    /** @var array<string, array|null> */
    private array $cache = [];

    public function __construct(private readonly PDO $db, private readonly string $driver)
    {
    }

    /** Forgets what was read: after a step changes the database. */
    public function forget(): void
    {
        $this->cache = [];
    }

    public function hasTable(string $table): bool
    {
        return $this->table($table) !== null;
    }

    /**
     * One table as it is, or null when there is none.
     *
     * @return array{columns: array<string, array{name: string, type: string, nullable: bool}>,
     *     primaryKey: list<string>, indexes: list<string>, constraints: array<string, string>}|null
     *     Types canonical ({@see canonical()}); constraints name => `check`|`foreign`.
     */
    public function table(string $table): ?array
    {
        if (array_key_exists($table, $this->cache)) {
            return $this->cache[$table];
        }
        return $this->cache[$table] = match ($this->driver) {
            'pgsql'  => $this->pgsql($table),
            'mysql'  => $this->mysql($table),
            'sqlite' => $this->sqlite($table),
            default  => throw new SchemaException("No schema inspection for driver '{$this->driver}'."),
        };
    }

    /**
     * Whether one change is already in the database — so a version run again after it
     * failed half-way (MySQL, MariaDB) does not do it twice. A change it cannot tell is not.
     */
    public function isApplied(array $c): bool
    {
        if ($c['op'] === 'createSchema') {
            return $this->exists('SELECT 1 FROM information_schema.schemata WHERE schema_name = ?', $c['name']);
        }
        if ($c['op'] === 'createExtension') {
            return $this->exists('SELECT 1 FROM pg_extension WHERE extname = ?', $c['name']);
        }
        $t = $this->table($c['table']);
        return match ($c['op']) {
            'createTable'    => $t !== null,
            'dropTable'      => $t === null,
            'addColumn'      => $t !== null && isset($t['columns'][$c['column']['name']]),
            'dropColumn'     => $t !== null && !isset($t['columns'][$c['column']]),
            'renameColumn'   => $t !== null && !isset($t['columns'][$c['from']]) && isset($t['columns'][$c['to']]),
            'addIndex'       => $t !== null && in_array($c['name'], $t['indexes'], true),
            'dropIndex'      => $t !== null && !in_array($c['name'], $t['indexes'], true),
            'addConstraint'  => $t !== null && isset($t['constraints'][$c['name']]),
            'dropConstraint' => $t !== null && !isset($t['constraints'][$c['name']]),
            default          => false,
        };
    }

    /**
     * How the database differs from a model — what a version that ran must not leave.
     * Tables of the database that the model does not have are not looked at: they are
     * not the application's.
     *
     * @return array{differences: list<string>, notices: list<string>}
     */
    public function compare(array $model): array
    {
        $diff = [];
        $notes = [];
        foreach (SchemaModel::live($model) as $table => $want) {
            $have = $this->table($table);
            if ($have === null) {
                $diff[] = "table {$table} is missing";
                continue;
            }
            foreach ($want['columns'] as $name => $c) {
                $col = $have['columns'][$name] ?? null;
                if ($col === null) {
                    $diff[] = "{$table}.{$name} is missing";
                    continue;
                }
                $type = self::canonical($c['type'], $this->driver);
                if (!self::sameType($type, $col['type'], $this->driver)) {
                    $diff[] = "{$table}.{$name}: expected {$type}, found {$col['type']}";
                }
                $nullable = $c['nullable'] && !in_array($name, $want['primaryKey'], true);
                if ($nullable !== $col['nullable']) {
                    $diff[] = "{$table}.{$name}: expected " . ($nullable ? 'NULL' : 'NOT NULL')
                        . ', found ' . ($col['nullable'] ? 'NULL' : 'NOT NULL');
                }
            }
            $extra = array_diff(array_keys($have['columns']), array_keys($model[$table]['columns']));
            foreach ($extra as $name) {
                $diff[] = "{$table}.{$name} is there, the model has no such column";
            }
            $order = array_values(array_intersect(array_keys($have['columns']), array_keys($want['columns'])));
            if ($extra === [] && $order !== array_keys($want['columns'])) {
                $notes[] = "{$table}: the columns are in another order than in the model ("
                    . implode(', ', $order) . ')';
            }
            if ($have['primaryKey'] !== $want['primaryKey']) {
                $diff[] = "{$table}: expected primary key (" . implode(', ', $want['primaryKey'])
                    . '), found (' . implode(', ', $have['primaryKey']) . ')';
            }
            foreach (array_keys($want['indexes']) as $name) {
                if (!in_array($name, $have['indexes'], true)) {
                    $diff[] = "{$table}: index {$name} is missing";
                }
            }
            foreach ($have['indexes'] as $name) {
                if (!isset($want['indexes'][$name])) {
                    $notes[] = "{$table}: index {$name} is there, the model has no such index";
                }
            }
            if ($this->driver === 'sqlite') {
                continue;                              // SQLite keeps no constraint names worth trusting
            }
            foreach ($want['constraints'] as $name => $c) {
                if (($have['constraints'][$name] ?? null) !== $c['kind']) {
                    $diff[] = "{$table}: constraint {$name} is missing";
                }
            }
            foreach ($have['constraints'] as $name => $kind) {
                if (!isset($want['constraints'][$name])) {
                    $notes[] = "{$table}: constraint {$name} is there, the model has no such constraint";
                }
            }
        }
        return ['differences' => $diff, 'notices' => $notes];
    }

    /**
     * A type in one spelling for a driver, to compare what ppa declares with what the
     * server reports: `int` for INTEGER / INT4 / INT(11), `varchar(120)` for CHARACTER
     * VARYING(120), `numeric(12,2)` for DECIMAL(12, 2), `bool` for BOOLEAN / TINYINT(1)…
     */
    public static function canonical(string $type, string $driver): string
    {
        // An array: `TEXT[]` as declared, `_text` as PostgreSQL names it (udt_name) — its
        // element canonical, without a length (the server keeps none for an array's).
        $lower = strtolower(trim($type));
        if (str_ends_with($lower, '[]') || ($driver === 'pgsql' && str_starts_with($lower, '_'))) {
            $element = str_ends_with($lower, '[]') ? substr($lower, 0, -2) : substr($lower, 1);
            return preg_replace('/\(.*\)$/', '', self::canonical($element, $driver)) . '[]';
        }
        $t = strtolower(SchemaDiff::base($type)[0]);
        $t = preg_replace('/\s*,\s*/', ',', $t);
        $t = preg_replace('/\s+/', ' ', trim($t));
        $t = preg_replace('/^(character varying|varchar)\s*\((\d+)\)$/', 'varchar($2)', $t);
        $t = preg_replace('/^(character|char)\s*\((\d+)\)$/', 'char($2)', $t);
        $t = preg_replace('/^(numeric|decimal)\s*\((\d+),(\d+)\)$/', 'numeric($2,$3)', $t);
        if ($driver === 'mysql') {
            // BOOLEAN is TINYINT(1) there: told apart before display widths are dropped.
            $t = preg_replace('/^tinyint\(1\)$/', 'bool', $t);
            $t = preg_replace('/^(tinyint|smallint|mediumint|int|integer|bigint)\(\d+\)/', '$1', $t);
            $t = preg_replace('/ unsigned$/', '', $t);
        }
        return match ($t) {
            'integer', 'int4', 'int'                        => 'int',
            'int8', 'bigint'                                => 'bigint',
            'int2', 'smallint'                              => 'smallint',
            'boolean', 'bool'                               => 'bool',
            'timestamp with time zone', 'timestamptz'       => 'timestamptz',
            'timestamp without time zone', 'timestamp'      => 'timestamp',
            'double precision', 'float8', 'double'          => 'double',
            'real', 'float4'                                => 'real',
            'time without time zone', 'time'                => 'time',
            default                                         => $t,
        };
    }

    /** MariaDB keeps JSON as LONGTEXT with a CHECK: the two are one type there. */
    private static function sameType(string $declared, string $found, string $driver): bool
    {
        if ($declared === $found) {
            return true;
        }
        return $driver === 'mysql' && $declared === 'json' && $found === 'longtext';
    }

    /** @return array{0: ?string, 1: string} schema (null — the current one), table */
    private static function split(string $table): array
    {
        $dot = strrpos($table, '.');
        return $dot === false ? [null, $table] : [substr($table, 0, $dot), substr($table, $dot + 1)];
    }

    private function rows(string $sql, array $params = []): array
    {
        $st = $this->db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function pgsql(string $table): ?array
    {
        [$schema, $name] = self::split($table);
        $schema ??= (string) $this->db->query('SELECT current_schema()')->fetchColumn();
        $cols = $this->rows(
            'SELECT column_name AS name, data_type AS type, character_maximum_length AS len,
                    numeric_precision AS prec, numeric_scale AS scale, is_nullable AS nullable, udt_name AS udt
             FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position',
            [$schema, $name],
        );
        if ($cols === []) {
            return null;
        }
        $t = ['columns' => [], 'primaryKey' => [], 'indexes' => [], 'constraints' => []];
        foreach ($cols as $c) {
            $type = match ($c['type']) {
                'character varying' => $c['len'] !== null ? "varchar({$c['len']})" : 'varchar',
                'character'         => "char({$c['len']})",
                'numeric'           => $c['prec'] !== null ? "numeric({$c['prec']},{$c['scale']})" : 'numeric',
                'ARRAY'             => (string) $c['udt'],
                default             => (string) $c['type'],
            };
            $t['columns'][$c['name']] = [
                'name' => $c['name'],
                'type' => self::canonical($type, 'pgsql'),
                'nullable' => $c['nullable'] === 'YES',
            ];
        }
        $qualified = '"' . str_replace('"', '""', $schema) . '"."' . str_replace('"', '""', $name) . '"';
        $t['primaryKey'] = array_column($this->rows(
            'SELECT a.attname AS name FROM pg_index i
             JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
             WHERE i.indrelid = ?::regclass AND i.indisprimary ORDER BY array_position(i.indkey, a.attnum)',
            [$qualified],
        ), 'name');
        $backing = array_column($this->rows(
            'SELECT c.conname AS name FROM pg_constraint c'
            . " WHERE c.conrelid = ?::regclass AND c.contype IN ('p', 'u', 'x')",
            [$qualified],
        ), 'name');
        $indexes = $this->rows(
            'SELECT indexname AS name FROM pg_indexes WHERE schemaname = ? AND tablename = ? ORDER BY 1',
            [$schema, $name],
        );
        foreach ($indexes as $i) {
            if (!in_array($i['name'], $backing, true)) {
                $t['indexes'][] = $i['name'];
            }
        }
        foreach (
            $this->rows(
                'SELECT conname AS name, contype AS type FROM pg_constraint'
                . " WHERE conrelid = ?::regclass AND contype IN ('c', 'f') ORDER BY 1",
                [$qualified],
            ) as $c
        ) {
            $t['constraints'][$c['name']] = $c['type'] === 'f' ? 'foreign' : 'check';
        }
        return $t;
    }

    private function mysql(string $table): ?array
    {
        [$schema, $name] = self::split($table);
        $schema ??= (string) $this->db->query('SELECT DATABASE()')->fetchColumn();
        $cols = $this->rows(
            'SELECT column_name AS name, column_type AS type, is_nullable AS nullable
             FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position',
            [$schema, $name],
        );
        if ($cols === []) {
            return null;
        }
        $t = ['columns' => [], 'primaryKey' => [], 'indexes' => [], 'constraints' => []];
        foreach ($cols as $c) {
            $t['columns'][$c['name']] = [
                'name' => $c['name'],
                'type' => self::canonical((string) $c['type'], 'mysql'),
                'nullable' => $c['nullable'] === 'YES',
            ];
        }
        foreach (
            $this->rows(
                "SELECT constraint_name AS name, constraint_type AS type FROM information_schema.table_constraints
             WHERE table_schema = ? AND table_name = ? AND constraint_type IN ('CHECK', 'FOREIGN KEY') ORDER BY 1",
                [$schema, $name],
            ) as $c
        ) {
            // MariaDB guards a JSON column with a CHECK named after the column.
            if ($c['type'] === 'CHECK' && isset($t['columns'][$c['name']])) {
                continue;
            }
            $t['constraints'][$c['name']] = $c['type'] === 'FOREIGN KEY' ? 'foreign' : 'check';
        }
        $foreign = array_keys(array_filter($t['constraints'], fn(string $k) => $k === 'foreign'));
        foreach (
            $this->rows(
                'SELECT index_name AS name, column_name AS col, seq_in_index AS seq FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? ORDER BY index_name, seq_in_index',
                [$schema, $name],
            ) as $i
        ) {
            if ($i['name'] === 'PRIMARY') {
                $t['primaryKey'][] = $i['col'];
            } elseif (!in_array($i['name'], $foreign, true) && !in_array($i['name'], $t['indexes'], true)) {
                // MySQL creates an index for a foreign key under the key's name.
                $t['indexes'][] = $i['name'];
            }
        }
        return $t;
    }

    private function sqlite(string $table): ?array
    {
        [, $name] = self::split($table);
        $found = $this->rows("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$name]);
        $sql = $found[0]['sql'] ?? null;
        if ($sql === null) {
            return null;
        }
        $t = ['columns' => [], 'primaryKey' => [], 'indexes' => [], 'constraints' => []];
        $pk = [];
        foreach ($this->rows('SELECT name, type, "notnull" AS nn, pk FROM pragma_table_info(?)', [$name]) as $c) {
            $t['columns'][$c['name']] = [
                'name' => $c['name'],
                'type' => self::canonical((string) $c['type'], 'sqlite'),
                'nullable' => !(bool) $c['nn'] && (int) $c['pk'] === 0,
            ];
            if ((int) $c['pk'] > 0) {
                $pk[(int) $c['pk']] = $c['name'];
            }
        }
        ksort($pk);
        $t['primaryKey'] = array_values($pk);
        foreach (
            $this->rows(
                "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = ?"
                . " AND name NOT LIKE 'sqlite_autoindex_%' ORDER BY 1",
                [$name],
            ) as $i
        ) {
            $t['indexes'][] = $i['name'];
        }
        if (preg_match_all('/\bCONSTRAINT\s+["`]?(\w+)["`]?\s+(CHECK|FOREIGN\s+KEY)/i', $sql, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $t['constraints'][$x[1]] = stripos($x[2], 'FOREIGN') === 0 ? 'foreign' : 'check';
            }
        }
        return $t;
    }

    private function exists(string $sql, string $value): bool
    {
        $st = $this->db->prepare($sql);
        $st->execute([$value]);
        return $st->fetchColumn() !== false;
    }
}
