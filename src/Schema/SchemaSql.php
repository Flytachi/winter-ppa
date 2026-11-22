<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Schema;

/**
 * The statements one change of a version runs, for a driver: `pgsql`, `mysql` (MySQL and
 * MariaDB) or `sqlite`. A change marked `"by": "code"` runs none — the version's code
 * makes it.
 *
 * SQLite cannot add a constraint (a foreign key, a check) to a table that exists, drop one,
 * or change a column: a new table gets its constraints inside its CREATE TABLE, and the rest is
 * refused here by name — that is a table rebuild, which is the version's code.
 */
final class SchemaSql
{
    private function __construct()
    {
    }

    /**
     * @return list<string>
     * @throws SchemaException What the driver cannot do.
     */
    public static function change(array $c, string $driver, array $model = []): array
    {
        if (($c['by'] ?? null) === 'code') {
            return [];
        }
        if ($c['op'] === 'createSchema') {
            return ["CREATE SCHEMA {$c['name']}"];
        }
        if ($c['op'] === 'createExtension') {
            return [$c['sql']];
        }
        $t = $c['table'];
        return match ($c['op']) {
            'createTable'    => [self::createTable($t, $c['columns'], $c['primaryKey'] ?? [], $driver)],
            'dropTable'      => ["DROP TABLE {$t}"],
            'addColumn'      => ["ALTER TABLE {$t} ADD COLUMN " . self::columnDef($c['column'])],
            'dropColumn'     => ["ALTER TABLE {$t} DROP COLUMN {$c['column']}"],
            'detachColumn'   => [],
            'renameColumn'   => ["ALTER TABLE {$t} RENAME COLUMN {$c['from']} TO {$c['to']}"],
            'alterColumn'    => self::alterColumn($t, $c['from'], $c['to'], $driver),
            'addIndex'       => [$c['sql']],
            'dropIndex'      => [self::dropIndex($t, $c['name'], $driver)],
            'addConstraint'  => self::addConstraint($t, $c, $driver),
            'dropConstraint' => [self::dropConstraint($t, $c['name'], $c['kind'], $driver)],
            'setPrimaryKey'  => throw new SchemaException("{$t}: a primary key is changed by the version's code."),
            default          => throw new SchemaException("Unknown change '{$c['op']}'."),
        };
    }

    /**
     * The statements of a version's changes, change by change. On SQLite the constraints a
     * version adds to a table it also creates go inside that CREATE TABLE.
     *
     * @param list<array> $changes
     * @return list<array{change: array, sql: list<string>}>
     */
    public static function plan(array $changes, string $driver): array
    {
        $inline = [];
        if ($driver === 'sqlite') {
            $created = array_column(array_filter($changes, fn(array $c) => $c['op'] === 'createTable'), 'table');
            foreach ($changes as $i => $c) {
                if ($c['op'] === 'addConstraint' && in_array($c['table'], $created, true)) {
                    $inline[$c['table']][] = $c['sql'];
                    unset($changes[$i]);
                }
            }
        }
        $plan = [];
        foreach ($changes as $c) {
            $sql = $c['op'] === 'createTable' && isset($inline[$c['table']]) && ($c['by'] ?? null) !== 'code'
                ? [self::createTable($c['table'], $c['columns'], $c['primaryKey'] ?? [], $driver, $inline[$c['table']])]
                : self::change($c, $driver);
            $plan[] = ['change' => $c, 'sql' => $sql];
        }
        return $plan;
    }

    /**
     * Statements that create a whole model at once — a fresh database: the schemas and the
     * extensions, every table, then its indexes, then the constraints (on SQLite, inside each
     * CREATE TABLE).
     *
     * @param array{schemas?: list<string>, extensions?: array<string, string>} $objects {@see SchemaModel::objects()}
     * @return list<string>
     */
    public static function create(array $model, string $driver, array $objects = []): array
    {
        $first = [];
        foreach ($objects['schemas'] ?? [] as $schema) {
            $first[] = "CREATE SCHEMA {$schema}";
        }
        array_push($first, ...array_values($objects['extensions'] ?? []));
        $tables = [];
        $indexes = [];
        $constraints = [];
        foreach (SchemaModel::live($model) as $table => $t) {
            $inline = $driver === 'sqlite' ? array_column($t['constraints'], 'sql') : [];
            $tables[] = self::createTable($table, array_values($t['columns']), $t['primaryKey'], $driver, $inline);
            array_push($indexes, ...array_values($t['indexes']));
            if ($driver !== 'sqlite') {
                array_push($constraints, ...array_column($t['constraints'], 'sql'));
            }
        }
        return array_merge($first, $tables, $indexes, $constraints);
    }

    public static function columnDef(array $c): string
    {
        $c = SchemaModel::column($c);
        return trim(implode(' ', array_filter([
            $c['name'],
            $c['type'],
            $c['nullable'] ? '' : 'NOT NULL',
            $c['default'] !== null ? 'DEFAULT ' . $c['default'] : '',
        ])));
    }

    /**
     * @param list<array> $columns
     * @param list<string> $primaryKey
     * @param list<string> $constraints `ALTER TABLE … ADD CONSTRAINT …` to put inside (SQLite).
     */
    public static function createTable(
        string $table,
        array $columns,
        array $primaryKey,
        string $driver,
        array $constraints = [],
    ): string {
        $lines = array_map(fn(array $c) => '    ' . self::columnDef($c), $columns);
        if ($primaryKey !== []) {
            $lines[] = '    PRIMARY KEY (' . implode(', ', $primaryKey) . ')';
        }
        foreach ($constraints as $sql) {
            $lines[] = '    ' . self::inline($sql);
        }
        return "CREATE TABLE {$table} (\n" . implode(",\n", $lines) . "\n)";
    }

    /** `ALTER TABLE t ADD CONSTRAINT …` as a table constraint: `CONSTRAINT …`. */
    private static function inline(string $sql): string
    {
        if (!preg_match('/\bADD\s+(CONSTRAINT\b.*)$/is', $sql, $m)) {
            throw new SchemaException("Not a constraint: {$sql}");
        }
        return $m[1];
    }

    /** @return list<string> */
    private static function alterColumn(string $t, array $from, array $to, string $driver): array
    {
        $name = $from['name'];
        if ($driver === 'sqlite') {
            throw new SchemaException(
                "{$t}.{$name}: SQLite cannot change a column — the table is rebuilt in the version's code."
            );
        }
        if ($driver === 'mysql') {
            return ["ALTER TABLE {$t} MODIFY COLUMN " . self::columnDef($to)];
        }
        $out = [];
        [$typeFrom] = SchemaDiff::base($from['type']);
        [$typeTo] = SchemaDiff::base($to['type']);
        if ($typeFrom !== $typeTo) {
            $out[] = "ALTER TABLE {$t} ALTER COLUMN {$name} TYPE {$typeTo}";
        }
        if ($from['nullable'] !== $to['nullable']) {
            $out[] = "ALTER TABLE {$t} ALTER COLUMN {$name} " . ($to['nullable'] ? 'DROP NOT NULL' : 'SET NOT NULL');
        }
        if ($from['default'] !== $to['default']) {
            $out[] = $to['default'] === null
                ? "ALTER TABLE {$t} ALTER COLUMN {$name} DROP DEFAULT"
                : "ALTER TABLE {$t} ALTER COLUMN {$name} SET DEFAULT {$to['default']}";
        }
        return $out;
    }

    private static function dropIndex(string $t, string $name, string $driver): string
    {
        return match ($driver) {
            // An index lives in its table's schema.
            'pgsql' => 'DROP INDEX ' . (str_contains($t, '.') ? substr($t, 0, strrpos($t, '.') + 1) : '') . $name,
            'mysql' => "DROP INDEX {$name} ON {$t}",
            default => "DROP INDEX {$name}",
        };
    }

    /** @return list<string> */
    private static function addConstraint(string $t, array $c, string $driver): array
    {
        // Not by the SQLite of the machine a version is recorded on: a version is applied by
        // any, and ALTER TABLE … ADD CONSTRAINT is new to SQLite (3.54 has it, the SQLite PHP
        // ships on most servers does not).
        if ($driver === 'sqlite') {
            $what = $c['kind'] === 'foreign' ? 'foreign key' : 'check';
            throw new SchemaException(
                "{$t}: SQLite cannot add the {$what} {$c['name']} to a table that exists — "
                . "the table is rebuilt in the version's code."
            );
        }
        return [$c['sql']];
    }

    private static function dropConstraint(string $t, string $name, string $kind, string $driver): string
    {
        return match ($driver) {
            'pgsql' => "ALTER TABLE {$t} DROP CONSTRAINT {$name}",
            'mysql' => $kind === 'foreign'
                ? "ALTER TABLE {$t} DROP FOREIGN KEY {$name}"
                : "ALTER TABLE {$t} DROP CONSTRAINT {$name}",
            default => throw new SchemaException(
                "{$t}: SQLite cannot drop the constraint {$name} — the table is rebuilt in the version's code."
            ),
        };
    }
}
