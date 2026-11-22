<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Schema;

/**
 * What a snapshot records: the changes from the model the history folds to the model the
 * entities declare — or why it cannot record them.
 *
 * It never guesses. A column gone and another new is a rename only with #[RenamedFrom];
 * a column or a table gone from the entities is dropped only when `--drop` names it (or
 * kept in the database with `--keep`); a type changed beyond widening, or a primary key
 * changed, is a change the code of the version must make (`custom`, `"by": "code"`); a
 * NOT NULL column without a default cannot be added to a table that may have rows.
 *
 * The changes come in an order that executes: constraints and indexes that go are dropped
 * first, then renames, new tables and columns, changed columns, dropped columns and tables,
 * and last new indexes and constraints — a foreign key after the table it points at.
 */
final class SchemaDiff
{
    private const array ORDER = [
        'createSchema' => -3, 'createExtension' => -2, 'renameTable' => -1, 'dropConstraint' => 0, 'dropIndex' => 1,
        'renameColumn' => 2, 'createTable' => 3, 'addColumn' => 4, 'alterColumn' => 5, 'setPrimaryKey' => 6,
        'detachColumn' => 7, 'dropColumn' => 8, 'dropTable' => 9, 'addIndex' => 10, 'addConstraint' => 11,
        'dropExtension' => 12,
    ];

    /** @var list<array> */
    public array $changes = [];
    /** @var list<string> Why nothing can be recorded. */
    public array $errors = [];
    /** @var list<string> Worth knowing; nothing stops. */
    public array $notices = [];
    /** @var list<array{entity: ?string, table: string, property: string, from: string, version: ?string}> */
    public array $stale = [];
    /**
     * @var list<array{entity: ?string, property: string}> The #[RenamedFrom] this database still
     *     needs: recorded by this diff, or refused — not to be cleaned while it is here.
     */
    public array $needed = [];

    /**
     * @param array $history The model the history folds to ({@see SchemaModel::fold()}).
     * @param array{model: array, renamed: array, entities: array} $declared {@see SchemaModel::declared()}.
     * @param list<SchemaVersionFile> $versions The history, to say where a rename was recorded.
     * @param list<string> $drop `table` or `table.column` that may go.
     * @param list<string> $keep `table.column` gone from the entity, kept in the database.
     * @param bool $custom Leave what the kernel cannot do to the version's code.
     * @param array{schemas?: list<string>, extensions?: array<string, string>} $objects What the
     *     history created beside the tables ({@see SchemaModel::objects()}).
     */
    public static function compute(
        array $history,
        array $declared,
        array $versions = [],
        array $drop = [],
        array $keep = [],
        bool $custom = false,
        array $objects = [],
        ?string $driver = null,
    ): self {
        $d = new self();
        $d->objects($objects, $declared, $drop);
        $history = $d->renamedTables($history, $declared, $versions, $driver);
        $to = $declared['model'];
        foreach ($to as $table => $want) {
            if (!isset($history[$table])) {
                $d->createTable($table, $want);
                continue;
            }
            $d->alterTable(
                $table,
                $history[$table],
                $want,
                $declared['renamed'][$table] ?? [],
                $declared['entities'][$table] ?? null,
                $versions,
                $drop,
                $keep,
                $custom,
            );
        }
        foreach ($history as $table => $have) {
            if (isset($to[$table])) {
                continue;
            }
            if (!in_array($table, $drop, true)) {
                $d->errors[] = "Table {$table} has no entity any more: its data would be lost. "
                    . "Pass --drop={$table} to drop it.";
                continue;
            }
            foreach ($have['constraints'] as $name => $c) {
                $d->changes[] = ['op' => 'dropConstraint', 'table' => $table, 'name' => $name, 'kind' => $c['kind']];
            }
            $d->changes[] = ['op' => 'dropTable', 'table' => $table];
        }
        foreach (array_merge($drop, $keep) as $named) {
            if (str_starts_with($named, 'extension:')) {
                if (!isset(($objects['extensions'] ?? [])[substr($named, 10)])) {
                    $d->notices[] = "{$named} (--drop) is not in the history: nothing to do with it.";
                }
                continue;
            }
            if (!self::mentioned($named, $history)) {
                $d->notices[] = "{$named} (--drop / --keep) is not in the history: nothing to do with it.";
            }
        }
        usort($d->changes, fn(array $a, array $b) => self::ORDER[$a['op']] <=> self::ORDER[$b['op']]);
        return $d;
    }

    /**
     * Schemas and extensions: created when declared and missing. An extension no longer
     * declared stays — dropped only when `--drop=extension:<name>` says so, after every index
     * and constraint of the version (what used it goes first). A schema is never dropped.
     */
    private function objects(array $had, array $declared, array $drop = []): void
    {
        foreach ($declared['schemas'] ?? [] as $schema) {
            if (!in_array($schema, $had['schemas'] ?? [], true)) {
                $this->changes[] = ['op' => 'createSchema', 'name' => $schema];
            }
        }
        foreach ($declared['extensions'] ?? [] as $name => $sql) {
            $was = $had['extensions'][$name] ?? null;
            if ($was === null) {
                $this->changes[] = ['op' => 'createExtension', 'name' => $name, 'sql' => $sql];
            } elseif ($was !== $sql) {
                $this->notices[] = "Extension {$name} is declared otherwise than its version recorded ({$was}): "
                    . 'a snapshot does not change an extension — do it in the code of a custom version.';
            }
        }
        foreach (array_keys($had['extensions'] ?? []) as $name) {
            if (isset($declared['extensions'][$name])) {
                continue;
            }
            if (in_array("extension:{$name}", $drop, true)) {
                $this->changes[] = ['op' => 'dropExtension', 'name' => $name];
            } else {
                $this->notices[] = "Extension {$name} is not declared any more: it stays in the database "
                    . "(--drop=extension:{$name} drops it).";
            }
        }
    }

    /**
     * #[RenamedFrom] on an entity class: the table renamed (or moved to another schema) before
     * anything else of the version, with the indexes and constraints named after it — paired
     * by their definition with what the entities declare, and renamed where the server can:
     * indexes on PostgreSQL and MySQL, constraints on PostgreSQL. What is not paired the diff
     * remakes.
     *
     * @return array The history with the renames in it.
     */
    private function renamedTables(array $history, array $declared, array $versions, ?string $driver): array
    {
        foreach ($declared['renamedTables'] ?? [] as $to => $from) {
            $entity = $declared['entities'][$to] ?? null;
            $who = $entity ?? $to;
            $property = '@' . ($entity !== null ? substr($entity, (int) strrpos('\\' . $entity, '\\')) : $to);
            $fromDeclared = isset($declared['model'][$from]);
            if (isset($history[$from]) && !isset($history[$to]) && !$fromDeclared) {
                $want = $declared['model'][$to];
                $change = [
                    'op' => 'renameTable',
                    'from' => $from,
                    'to' => $to,
                    'indexes' => $driver === 'sqlite'
                        ? []
                        : self::pairs($history[$from]['indexes'], $want['indexes'], $from, $to),
                    'constraints' => $driver === null || $driver === 'pgsql'
                        ? self::pairs(
                            array_map(fn(array $c) => $c['sql'], $history[$from]['constraints']),
                            array_map(fn(array $c) => $c['sql'], $want['constraints']),
                            $from,
                            $to,
                        )
                        : [],
                ];
                $this->changes[] = $change;
                $this->needed[] = ['entity' => $entity, 'property' => $property];
                $history = SchemaModel::apply($history, $change);
            } elseif (
                !isset($history[$from]) && isset($history[$to]) && !$fromDeclared
                && ($at = self::tableRecordedIn($versions, $from, $to)) !== null
            ) {
                $this->notices[] = "{$who} — #[RenamedFrom('{$from}')] is already recorded in {$at}: "
                    . 'it can be removed (`call db snapshot clean`).';
                $this->stale[] = [
                    'entity' => $entity, 'table' => $to, 'property' => $property, 'from' => $from, 'version' => $at,
                ];
            } else {
                $this->needed[] = ['entity' => $entity, 'property' => $property];
                $this->errors[] = "{$who} — #[RenamedFrom('{$from}')]: "
                    . ($fromDeclared
                        ? "`{$from}` is a table of the entities too."
                        : (!isset($history[$from])
                            ? "`{$from}` is not a table of the last snapshot."
                            : "{$to} is in the last snapshot already."))
                    . ' Remove the attribute.';
            }
        }
        return $history;
    }

    /**
     * Old name => new name: an index or constraint of the table and one the entities declare
     * that say the same once the table's name and their own are left out.
     *
     * @param array<string, string> $have name => statement
     * @param array<string, string> $want name => statement
     * @return array<string, string>
     */
    private static function pairs(array $have, array $want, string $from, string $to): array
    {
        $strip = fn(string $sql, string $name) => (string) preg_replace(
            '/(?<![\w])' . preg_quote($name, '/') . '(?![\w])/',
            '#',
            $sql,
            1,
        );
        $pairs = [];
        $taken = [];
        foreach ($have as $old => $sql) {
            $mine = $strip(SchemaModel::renamedSql($sql, $from, $to, null, null), $old);
            foreach ($want as $new => $theirs) {
                if (!isset($taken[$new]) && $strip($theirs, $new) === $mine) {
                    $pairs[$old] = $new;
                    $taken[$new] = true;
                    break;
                }
            }
        }
        return $pairs;
    }

    /** @param list<SchemaVersionFile> $versions */
    private static function tableRecordedIn(array $versions, string $from, string $to): ?string
    {
        foreach ($versions as $v) {
            foreach ($v->changes as $c) {
                if (($c['op'] ?? '') === 'renameTable' && $c['from'] === $from && $c['to'] === $to) {
                    return $v->version;
                }
            }
        }
        return null;
    }

    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    /** Whether the version needs code: some change is left to it. */
    public function needsCode(): bool
    {
        foreach ($this->changes as $c) {
            if (($c['by'] ?? null) === 'code') {
                return true;
            }
        }
        return false;
    }

    private function createTable(string $table, array $want): void
    {
        $this->changes[] = [
            'op' => 'createTable',
            'table' => $table,
            'columns' => array_values($want['columns']),
            'primaryKey' => $want['primaryKey'],
        ];
        foreach ($want['indexes'] as $name => $sql) {
            $this->changes[] = ['op' => 'addIndex', 'table' => $table, 'name' => $name, 'sql' => $sql];
        }
        foreach ($want['constraints'] as $name => $c) {
            $this->changes[] = ['op' => 'addConstraint', 'table' => $table, 'name' => $name] + $c;
        }
    }

    private function alterTable(
        string $table,
        array $have,
        array $want,
        array $renamed,
        ?string $entity,
        array $versions,
        array $drop,
        array $keep,
        bool $custom,
    ): void {
        $who = $entity !== null ? "{$entity}::\$" : "{$table}.";

        foreach ($renamed as $to => $from) {
            $fromInHistory = isset($have['columns'][$from]) && !($have['columns'][$from]['detached'] ?? false);
            $toInHistory = isset($have['columns'][$to]);
            $fromDeclared = isset($want['columns'][$from]);
            if ($fromInHistory && !$toInHistory && !$fromDeclared) {
                $change = ['op' => 'renameColumn', 'table' => $table, 'from' => $from, 'to' => $to];
                $this->changes[] = $change;
                $this->needed[] = ['entity' => $entity, 'property' => $to];
                $have = SchemaModel::apply([$table => $have], $change)[$table];
            } elseif (
                !$fromInHistory && $toInHistory && !$fromDeclared
                && ($at = self::recordedIn($versions, $table, $from, $to)) !== null
            ) {
                $this->notices[] = "{$who}{$to} — #[RenamedFrom('{$from}')] is already recorded in {$at}: "
                    . 'it can be removed (`call db snapshot clean`).';
                $this->stale[] = [
                    'entity' => $entity, 'table' => $table, 'property' => $to, 'from' => $from, 'version' => $at,
                ];
            } else {
                $this->needed[] = ['entity' => $entity, 'property' => $to];
                $this->errors[] = "{$who}{$to} — #[RenamedFrom('{$from}')]: "
                    . ($fromDeclared
                        ? "`{$from}` is a column of the entity too."
                        : "`{$from}` is not a column of the last snapshot.")
                    . ' Remove the attribute.';
            }
        }

        foreach ($want['columns'] as $name => $c) {
            if (!isset($have['columns'][$name])) {
                if (!$c['nullable'] && $c['default'] === null && !in_array($name, $want['primaryKey'], true)) {
                    $this->errors[] = "{$table}.{$name} is NOT NULL with no default: the rows already in {$table} "
                        . 'have no value for it. Give it #[DefaultVal] or a PHP default, or make it nullable.';
                }
                $this->changes[] = ['op' => 'addColumn', 'table' => $table, 'column' => $c];
                continue;
            }
            $old = $have['columns'][$name];
            if ($old['detached'] ?? false) {
                $this->errors[] = "{$table}.{$name} was kept in the database with --keep and is declared again: "
                    . 'pass --drop for it first, or rename the property.';
                continue;
            }
            if ($old === $c) {
                continue;
            }
            $change = ['op' => 'alterColumn', 'table' => $table, 'column' => $name, 'from' => $old, 'to' => $c];
            $kernel = $old['type'] === $c['type'] || self::widens($old['type'], $c['type'])
                || self::identityMode($old['type'], $c['type']);
            if ($kernel) {
                if ($old['nullable'] && !$c['nullable']) {
                    $this->notices[] = "{$table}.{$name} becomes NOT NULL: rows where it is NULL make the version "
                        . 'fail — fill them in before() of a custom version if there can be any.';
                }
                $this->changes[] = $change;
            } elseif ($custom) {
                $this->changes[] = $change + ['by' => 'code'];
            } else {
                $this->errors[] = "{$table}.{$name}: {$old['type']} → {$c['type']} cannot be changed by the kernel. "
                    . 'Run `call db snapshot custom <name>` and write in after() how to get there.';
            }
        }

        if ($have['primaryKey'] !== $want['primaryKey']) {
            $change = ['op' => 'setPrimaryKey', 'table' => $table, 'columns' => $want['primaryKey'], 'by' => 'code'];
            if ($custom) {
                $this->changes[] = $change;
            } else {
                $this->errors[] = "{$table}: the primary key changes (" . implode(', ', $have['primaryKey']) . ' → '
                    . implode(', ', $want['primaryKey']) . '). Run `call db snapshot custom <name>`.';
            }
        }

        foreach ($have['columns'] as $name => $c) {
            $dropped = in_array("{$table}.{$name}", $drop, true);
            if (isset($want['columns'][$name]) || (($c['detached'] ?? false) && !$dropped)) {
                continue;
            }
            if (in_array("{$table}.{$name}", $drop, true)) {
                $this->changes[] = ['op' => 'dropColumn', 'table' => $table, 'column' => $name];
            } elseif (in_array("{$table}.{$name}", $keep, true)) {
                $this->changes[] = ['op' => 'detachColumn', 'table' => $table, 'column' => $name];
                if (!$c['nullable'] && $c['default'] === null) {
                    $this->notices[] = "{$table}.{$name} stays in the database NOT NULL with no default: an insert "
                        . 'that does not name it will fail.';
                }
            } else {
                $this->errors[] = "{$table}.{$name} is gone from the entity: its data would be lost. "
                    . "Pass --drop={$table}.{$name} to drop it, --keep={$table}.{$name} to leave it in the database, "
                    . 'or #[RenamedFrom] if it was renamed.';
            }
        }

        foreach ($have['indexes'] as $name => $sql) {
            if (($want['indexes'][$name] ?? null) !== $sql) {
                $this->changes[] = ['op' => 'dropIndex', 'table' => $table, 'name' => $name];
            }
        }
        foreach ($want['indexes'] as $name => $sql) {
            if (($have['indexes'][$name] ?? null) !== $sql) {
                $this->changes[] = ['op' => 'addIndex', 'table' => $table, 'name' => $name, 'sql' => $sql];
            }
        }
        foreach ($have['constraints'] as $name => $c) {
            if (($want['constraints'][$name] ?? null) !== $c) {
                $this->changes[] = ['op' => 'dropConstraint', 'table' => $table, 'name' => $name, 'kind' => $c['kind']];
            }
        }
        foreach ($want['constraints'] as $name => $c) {
            if (($have['constraints'][$name] ?? null) !== $c) {
                $this->changes[] = ['op' => 'addConstraint', 'table' => $table, 'name' => $name] + $c;
            }
        }
    }

    /**
     * Whether a type change keeps every value: a longer VARCHAR, VARCHAR to TEXT, a wider
     * integer, a DECIMAL with as many digits on each side of the point or more.
     */
    public static function widens(string $from, string $to): bool
    {
        [$a, $aRest] = self::base($from);
        [$b, $bRest] = self::base($to);
        if ($aRest !== $bRest) {
            return false;                              // identity / auto-increment changes are not a widening
        }
        if (preg_match('/^VARCHAR\((\d+)\)$/', $a, $x)) {
            return $b === 'TEXT' || (preg_match('/^VARCHAR\((\d+)\)$/', $b, $y) && (int) $y[1] >= (int) $x[1]);
        }
        $ints = ['SMALLINT' => 1, 'INT' => 2, 'INTEGER' => 2, 'BIGINT' => 3];
        if (isset($ints[$a], $ints[$b])) {
            return $ints[$b] >= $ints[$a];
        }
        if (
            preg_match('/^(?:NUMERIC|DECIMAL)\((\d+),\s*(\d+)\)$/', $a, $x)
            && preg_match('/^(?:NUMERIC|DECIMAL)\((\d+),\s*(\d+)\)$/', $b, $y)
        ) {
            return (int) $y[2] >= (int) $x[2] && ((int) $y[1] - (int) $y[2]) >= ((int) $x[1] - (int) $x[2]);
        }
        return false;
    }

    /**
     * The same type — or a wider one — whose identity only changes how it is generated:
     * `GENERATED BY DEFAULT` ↔ `GENERATED ALWAYS` (`#[Id(always: true)]`). No row changes;
     * PostgreSQL does it with SET GENERATED.
     */
    public static function identityMode(string $from, string $to): bool
    {
        [$a, $aRest] = self::base($from);
        [$b, $bRest] = self::base($to);
        $identity = '/^GENERATED (ALWAYS|BY DEFAULT) AS IDENTITY$/';
        return $aRest !== $bRest && preg_match($identity, $aRest) && preg_match($identity, $bRest)
            && ($a === $b || self::widens($a, $b));
    }

    /** @return array{string, string} The type itself, and what follows it (identity, auto-increment). */
    public static function base(string $type): array
    {
        $type = strtoupper(trim($type));
        if (preg_match('/^(.*?)\s+(GENERATED\b.*|AUTO_INCREMENT\b.*)$/', $type, $m)) {
            return [trim($m[1]), trim($m[2])];
        }
        return [$type, ''];
    }

    /** @param list<SchemaVersionFile> $versions */
    private static function recordedIn(array $versions, string $table, string $from, string $to): ?string
    {
        foreach ($versions as $v) {
            foreach ($v->changes as $c) {
                if (
                    ($c['op'] ?? '') === 'renameColumn'
                    && $c['table'] === $table && $c['from'] === $from && $c['to'] === $to
                ) {
                    return $v->version;
                }
            }
        }
        return null;
    }

    private static function mentioned(string $named, array $history): bool
    {
        if (isset($history[$named])) {
            return true;
        }
        $dot = strrpos($named, '.');
        return $dot !== false && isset($history[substr($named, 0, $dot)]['columns'][substr($named, $dot + 1)]);
    }
}
