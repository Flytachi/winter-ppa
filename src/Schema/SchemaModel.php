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
     *     schemas: list<string>, extensions: array<string, string>}
     *     `renamed`: table => column => its name in the last snapshot (#[RenamedFrom]);
     *     `entities`: table => the entity class; `schemas`: the PostgreSQL schemas the tables
     *     live in but `public`; `extensions`: name => its CREATE EXTENSION.
     */
    public static function declared(iterable $tables, string $driver, iterable $extensions = []): array
    {
        $schemas = [];
        $ext = [];
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
