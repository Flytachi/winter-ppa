<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Schema;

use Flytachi\Winter\Ppa\Mapping\Constants\IndexType;
use Flytachi\Winter\Ppa\Mapping\Structure\Column;
use Flytachi\Winter\Ppa\Mapping\Structure\Extension;
use Flytachi\Winter\Ppa\Mapping\Structure\Table;

/**
 * A database's schema as plain arrays — what the entities declare, or what the history
 * says once its changes are folded in order. Both are built the same way, so they compare
 * with `===`.
 *
 * ```
 * table => [
 *     'columns'     => name => ['name' => …, 'type' => 'VARCHAR(120)', 'nullable' => bool, 'default' => ?string],
 *     'primaryKey'  => list<column>,
 *     'indexes'     => name => the CREATE INDEX statement,
 *     'constraints' => name => ['kind' => 'check'|'foreign', 'sql' => the ALTER TABLE … ADD CONSTRAINT statement],
 * ]
 * ```
 *
 * Types, indexes and constraints are the SQL ppa generates for the driver — the same text
 * `call db sql` shows — so a snapshot belongs to one driver. A column keeps its place on a
 * rename or a change, as the database does: a fresh database and an upgraded one then list
 * their columns in the same order.
 *
 * A column taken out of the entity with `--keep` stays in the model marked `detached`: the
 * database keeps it and its data, later snapshots leave it alone, a fresh database does
 * not get it.
 *
 * What is not a table — a PostgreSQL schema the tables live in, an extension the config
 * declares — is kept apart, by {@see objects()}: `createSchema` and `createExtension` leave
 * the tables as they are.
 */
final class SchemaModel
{
    private function __construct()
    {
    }

    /**
     * The model the entities declare, with what a snapshot needs beside it.
     *
     * @param iterable<Table> $tables The tables {@see \Flytachi\Winter\Ppa\PPAMapping::declarationFrom()} maps.
     * @param iterable<Extension> $extensions The config's `#[Extension]`s (PostgreSQL).
     * @return array{model: array, renamed: array<string, array<string, string>>, entities: array<string, string>,
     *     schemas: list<string>, extensions: array<string, string>, renamedTables: array<string, string>}
     *     `renamed`: table => column => its name in the last snapshot (#[RenamedFrom]);
     *     `entities`: table => the entity class; `schemas`: the PostgreSQL schemas the tables
     *     live in but `public`; `extensions`: name => its CREATE EXTENSION; `renamedTables`:
     *     table => its name in the last snapshot (#[RenamedFrom] on the entity class).
     */
    public static function declared(iterable $tables, string $driver, iterable $extensions = []): array
    {
        $schemas = [];
        $ext = [];
        $renamedTables = [];
        if ($driver === 'pgsql') {
            foreach ($extensions as $e) {
                $ext[$e->name] = rtrim($e->toSql('pgsql'), ';');
            }
        }
        $model = [];
        $renamed = [];
        $entities = [];
        foreach ($tables as $table) {
            $name = $table->getFullName();
            if (isset($model[$name])) {
                throw new SchemaException(
                    "Table {$name} is mapped twice ("
                    . ($entities[$name] ?? '?') . ', ' . ($table->entity ?? '?') . ').'
                );
            }
            $model[$name] = self::table($table, $driver);
            $schema = (string) $table->schema;
            if ($driver === 'pgsql' && $schema !== '' && $schema !== 'public') {
                $schemas[$schema] = $schema;
            }
            if ($table->entity !== null) {
                $entities[$name] = $table->entity;
            }
            if ($table->renamedFrom !== null && $table->renamedFrom !== '') {
                // A name without a schema is in the schema the table is in now.
                $renamedTables[$name] = str_contains($table->renamedFrom, '.') || $table->schema === null
                    ? $table->renamedFrom
                    : "{$table->schema}.{$table->renamedFrom}";
            }
            foreach ($table->columns as $column) {
                if ($column->renamedFrom !== null) {
                    $renamed[$name][$column->name] = $column->renamedFrom;
                }
            }
        }
        ksort($ext);
        return [
            'model' => $model,
            'renamed' => $renamed,
            'entities' => $entities,
            'schemas' => array_values($schemas),
            'extensions' => $ext,
            'renamedTables' => $renamedTables,
        ];
    }

    /** One table of the model, from its structure. */
    public static function table(Table $table, string $driver): array
    {
        $name = $table->getFullName();
        $t = ['columns' => [], 'primaryKey' => [], 'indexes' => [], 'constraints' => []];
        $indexes = $table->indexes;
        foreach ($table->columns as $column) {
            /** @var Column $column */
            $t['columns'][$column->name] = self::column([
                'name'     => $column->name,
                'type'     => $column->type,
                'nullable' => $column->nullable,
                'default'  => $column->default,
            ]);
            array_push($indexes, ...$column->indexes);
            if ($column->foreignKey !== null) {
                $sql = $column->foreignKey->toSql($name, $column->name, $driver);
                $t['constraints'][self::constraintName($sql)] = ['kind' => 'foreign', 'sql' => $sql];
            }
            foreach ($column->checks as $check) {
                $sql = $check->toSql($name, $driver);
                $t['constraints'][self::constraintName($sql)] = ['kind' => 'check', 'sql' => $sql];
            }
        }
        foreach ($indexes as $index) {
            if ($index->type === IndexType::PRIMARY) {
                array_push($t['primaryKey'], ...array_values($index->columns));
                continue;
            }
            $sql = $index->toSql($name, $driver);
            $t['indexes'][self::indexName($sql)] = $sql;
        }
        foreach ($table->checks as $check) {
            $sql = $check->toSql($name, $driver);
            $t['constraints'][self::constraintName($sql)] = ['kind' => 'check', 'sql' => $sql];
        }
        foreach ($table->foreignKeys as $fk) {
            $sql = $fk->toSql($name, (string) $fk->columnName, $driver);
            $t['constraints'][self::constraintName($sql)] = ['kind' => 'foreign', 'sql' => $sql];
        }
        $t['primaryKey'] = array_values(array_unique($t['primaryKey']));
        ksort($t['indexes']);
        ksort($t['constraints']);
        return $t;
    }

    /** A column as the model and the history keep it: these four keys, in this order. */
    public static function column(array $c): array
    {
        return [
            'name'     => (string) $c['name'],
            'type'     => (string) $c['type'],
            'nullable' => (bool) ($c['nullable'] ?? true),
            'default'  => isset($c['default']) ? (string) $c['default'] : null,
        ] + (($c['detached'] ?? false) ? ['detached' => true] : []);
    }

    /**
     * Applies one change of a version to a model.
     *
     * @throws SchemaException A change that does not fit the model — a history that does not add up.
     */
    public static function apply(array $model, array $change): array
    {
        $op = $change['op'] ?? '';
        if ($op === 'createSchema' || $op === 'createExtension' || $op === 'dropExtension') {
            return $model;          // not a table: see objects()
        }
        if ($op === 'renameTable') {
            return self::renameTable($model, $change);
        }
        $t = $change['table'] ?? null;
        if ($op !== 'createTable' && !isset($model[$t])) {
            throw new SchemaException("{$op}: table {$t} is not in the model.");
        }
        switch ($op) {
            case 'createTable':
                if (isset($model[$t])) {
                    throw new SchemaException("createTable: {$t} is already in the model.");
                }
                $columns = [];
                foreach ($change['columns'] as $c) {
                    $columns[$c['name']] = self::column($c);
                }
                $model[$t] = [
                    'columns' => $columns,
                    'primaryKey' => array_values($change['primaryKey'] ?? []),
                    'indexes' => [],
                    'constraints' => [],
                ];
                break;
            case 'dropTable':
                unset($model[$t]);
                break;
            case 'addColumn':
                $model[$t]['columns'][$change['column']['name']] = self::column($change['column']);
                break;
            case 'dropColumn':
                unset($model[$t]['columns'][$change['column']]);
                break;
            case 'detachColumn':
                $model[$t]['columns'][$change['column']]['detached'] = true;
                break;
            case 'renameColumn':
                $rename = function (array $c) use ($change): array {
                    $c['name'] = $change['to'];
                    return [$change['to'], $c];
                };
                $model[$t]['columns'] = self::replaceColumn($model[$t]['columns'], $change['from'], $rename);
                $model[$t]['primaryKey'] = array_map(
                    fn(string $n) => $n === $change['from'] ? $change['to'] : $n,
                    $model[$t]['primaryKey'],
                );
                break;
            case 'alterColumn':
                $model[$t]['columns'] = self::replaceColumn(
                    $model[$t]['columns'],
                    $change['column'],
                    fn(array $c) => [$change['column'], self::column($change['to'])],
                );
                break;
            case 'setPrimaryKey':
                $model[$t]['primaryKey'] = array_values($change['columns']);
                break;
            case 'addIndex':
                $model[$t]['indexes'][$change['name']] = $change['sql'];
                ksort($model[$t]['indexes']);
                break;
            case 'dropIndex':
                unset($model[$t]['indexes'][$change['name']]);
                break;
            case 'addConstraint':
                $model[$t]['constraints'][$change['name']] = ['kind' => $change['kind'], 'sql' => $change['sql']];
                ksort($model[$t]['constraints']);
                break;
            case 'dropConstraint':
                unset($model[$t]['constraints'][$change['name']]);
                break;
            default:
                throw new SchemaException("Unknown change '{$op}'.");
        }
        return $model;
    }

    /**
     * The model after the given versions, in order.
     *
     * @param iterable<SchemaVersionFile> $versions
     */
    public static function fold(iterable $versions): array
    {
        $model = [];
        foreach ($versions as $version) {
            foreach ($version->changes as $change) {
                $model = self::apply($model, $change);
            }
        }
        return $model;
    }

    /**
     * What the versions created beside the tables: the schemas and the extensions.
     *
     * @param iterable<SchemaVersionFile> $versions
     * @return array{schemas: list<string>, extensions: array<string, string>} extensions: name => its statement
     */
    public static function objects(iterable $versions): array
    {
        $schemas = [];
        $extensions = [];
        foreach ($versions as $version) {
            foreach ($version->changes as $c) {
                if (($c['op'] ?? '') === 'createSchema') {
                    $schemas[$c['name']] = $c['name'];
                } elseif (($c['op'] ?? '') === 'createExtension') {
                    $extensions[$c['name']] = $c['sql'];
                } elseif (($c['op'] ?? '') === 'dropExtension') {
                    unset($extensions[$c['name']]);
                }
            }
        }
        return ['schemas' => array_values($schemas), 'extensions' => $extensions];
    }

    /**
     * A table under its new name: the indexes and constraints the change pairs renamed (their
     * statements on the new name), the others as they were — the diff remakes them; the
     * foreign keys of other tables pointing at it point at the new name, as the server does.
     */
    private static function renameTable(array $model, array $c): array
    {
        [$from, $to] = [$c['from'], $c['to']];
        if (!isset($model[$from])) {
            throw new SchemaException("renameTable: table {$from} is not in the model.");
        }
        if (isset($model[$to])) {
            throw new SchemaException("renameTable: {$to} is already in the model.");
        }
        $t = $model[$from];
        $indexes = [];
        foreach ($t['indexes'] as $name => $sql) {
            $new = $c['indexes'][$name] ?? null;
            $new === null ? $indexes[$name] = $sql : $indexes[$new] = self::renamedSql($sql, $from, $to, $name, $new);
        }
        $constraints = [];
        foreach ($t['constraints'] as $name => $k) {
            $new = $c['constraints'][$name] ?? null;
            $new === null
                ? $constraints[$name] = $k
                : $constraints[$new] = [
                    'kind' => $k['kind'],
                    'sql' => self::renamedSql($k['sql'], $from, $to, $name, $new),
                ];
        }
        ksort($indexes);
        ksort($constraints);
        $t['indexes'] = $indexes;
        $t['constraints'] = $constraints;
        $out = [];
        foreach ($model as $name => $table) {
            $out[$name === $from ? $to : $name] = $name === $from ? $t : $table;
        }
        foreach ($out as $name => $table) {
            foreach ($table['constraints'] as $cn => $k) {
                if ($k['kind'] === 'foreign') {
                    $out[$name]['constraints'][$cn]['sql'] = self::referencesRenamed($k['sql'], $from, $to);
                }
            }
        }
        return $out;
    }

    /**
     * A statement of the table `$from` as the same one of `$to`: the table it is on, and the
     * index's or constraint's name (`$oldName` → `$newName`).
     */
    public static function renamedSql(string $sql, string $from, string $to, ?string $oldName, ?string $newName): string
    {
        $sql = (string) preg_replace('/(?<![\w.])' . preg_quote($from, '/') . '(?![\w])/', $to, $sql);
        if ($oldName !== null && $newName !== null && $oldName !== $newName) {
            $sql = (string) preg_replace('/(?<![\w])' . preg_quote($oldName, '/') . '(?![\w])/', $newName, $sql, 1);
        }
        return $sql;
    }

    /**
     * Whether a foreign key's statement points at `$table` — by its full name, or by its short
     * one where a short one finds it: the schema of the table that has the key, or `public`
     * (the search path PostgreSQL resolves a bare name by), or no schema at all.
     */
    public static function references(string $sql, string $table, ?string $fromTable = null): bool
    {
        $at = fn(string $t) => '/\bREFERENCES\s+' . preg_quote($t, '/') . '\s*\(/i';
        if (preg_match($at($table), $sql)) {
            return true;
        }
        [$schema, $short] = self::splitName($table);
        $own = $fromTable !== null ? self::splitName($fromTable)[0] : $schema;
        $found = $schema === null || $schema === 'public' || $schema === $own;
        return $found && (bool) preg_match($at($short), $sql);
    }

    /** `REFERENCES <from>(…)` → `REFERENCES <to>(…)` — and the short name when the schema stays. */
    private static function referencesRenamed(string $sql, string $from, string $to): string
    {
        $at = fn(string $t) => '/\bREFERENCES\s+' . preg_quote($t, '/') . '\s*\(/i';
        $sql = (string) preg_replace($at($from), "REFERENCES {$to}(", $sql);
        $f = self::splitName($from);
        $t = self::splitName($to);
        if ($f[0] === $t[0]) {
            $sql = (string) preg_replace($at($f[1]), "REFERENCES {$t[1]}(", $sql);
        }
        return $sql;
    }

    /** @return array{?string, string} schema (null: none given), table */
    public static function splitName(string $table): array
    {
        $dot = strrpos($table, '.');
        return $dot === false ? [null, $table] : [substr($table, 0, $dot), substr($table, $dot + 1)];
    }

    /** The model without what only the history keeps: a detached column is no column of it. */
    public static function live(array $model): array
    {
        foreach ($model as $t => $table) {
            $model[$t]['columns'] = array_filter($table['columns'], fn(array $c) => !($c['detached'] ?? false));
        }
        return $model;
    }

    public static function indexName(string $sql): string
    {
        if (!preg_match('/\bINDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?([A-Za-z0-9_."`]+)\s+ON\b/i', $sql, $m)) {
            throw new SchemaException("Cannot read the index name of: {$sql}");
        }
        return trim($m[1], '"`');
    }

    public static function constraintName(string $sql): string
    {
        if (!preg_match('/\bADD\s+CONSTRAINT\s+([A-Za-z0-9_"`]+)/i', $sql, $m)) {
            throw new SchemaException("Cannot read the constraint name of: {$sql}");
        }
        return trim($m[1], '"`');
    }

    /** @param callable(array): array{string, array} $replace */
    private static function replaceColumn(array $columns, string $name, callable $replace): array
    {
        if (!isset($columns[$name])) {
            throw new SchemaException("Column {$name} is not in the model.");
        }
        $out = [];
        foreach ($columns as $n => $c) {
            if ($n === $name) {
                [$key, $value] = $replace($c);
                $out[$key] = $value;
            } else {
                $out[$n] = $c;
            }
        }
        return $out;
    }
}
