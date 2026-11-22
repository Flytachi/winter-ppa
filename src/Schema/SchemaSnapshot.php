<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Schema;

use Flytachi\Winter\Ppa\Mapping\Structure\Extension;
use Flytachi\Winter\Ppa\Mapping\Structure\Table;

/**
 * A new version from the entities: the difference between what they declare and what the
 * history folds to, written as `<version>_<name>.json` — and, for a custom version, the
 * skeleton of its code beside it.
 */
final class SchemaSnapshot
{
    private function __construct()
    {
    }

    /**
     * The difference a snapshot would record.
     *
     * @param iterable<Table> $tables The entities' tables for this database.
     * @param list<string> $drop
     * @param list<string> $keep
     * @param iterable<Extension> $extensions The config's `#[Extension]`s.
     */
    public static function diff(
        SchemaDirectory $dir,
        iterable $tables,
        string $driver,
        array $drop = [],
        array $keep = [],
        bool $custom = false,
        iterable $extensions = [],
    ): SchemaDiff {
        $tables = is_array($tables) ? $tables : iterator_to_array($tables, false);
        $extensions = is_array($extensions) ? $extensions : iterator_to_array($extensions, false);
        $versions = $dir->versions($driver);
        $diff = SchemaDiff::compute(
            SchemaModel::fold($versions),
            SchemaModel::declared($tables, $driver, $extensions),
            $versions,
            $drop,
            $keep,
            $custom,
            SchemaModel::objects($versions),
        );
        // What the server would not build as declared: refused here, before it is recorded.
        $check = SchemaCheck::tables($tables, $driver, array_map(fn(Extension $e) => $e->name, $extensions));
        array_push($diff->errors, ...$check['errors']);
        array_push($diff->notices, ...$check['notices']);
        // What the driver cannot do by itself (SQLite: change a column, add a foreign key to a
        // table that exists) is said now, not when a database applies the version: in a custom
        // version it is left to the code, in a plain one it is an error.
        $created = array_column(array_filter($diff->changes, fn(array $c) => $c['op'] === 'createTable'), 'table');
        foreach ($diff->changes as $i => $c) {
            $insideCreate = $c['op'] === 'addConstraint' && in_array($c['table'], $created, true);
            if (($c['by'] ?? null) === 'code' || $insideCreate) {
                continue;
            }
            try {
                SchemaSql::change($c, $driver);
            } catch (SchemaException $e) {
                if ($custom) {
                    $diff->changes[$i]['by'] = 'code';
                } else {
                    $diff->errors[] = $e->getMessage() . ' Record it with `call db snapshot custom <name>`.';
                }
            }
        }
        return $diff;
    }

    /**
     * Writes the version.
     *
     * @return array{version: string, files: list<string>}
     * @throws SchemaException Nothing to record, or something that may not be recorded.
     */
    public static function write(
        SchemaDirectory $dir,
        SchemaDiff $diff,
        string $driver,
        ?string $name = null,
        bool $custom = false,
        ?string $namespace = null,
    ): array {
        if ($diff->errors !== []) {
            throw new SchemaException(implode(' ', $diff->errors));
        }
        if ($diff->isEmpty() && !$custom) {
            // A custom version may change data only: no schema change, its code all of it.
            throw new SchemaException('The entities match the last version: there is nothing to record.');
        }
        if ($diff->needsCode() && !$custom) {
            throw new SchemaException(
                'Some changes are left to code: record them with `call db snapshot custom <name>`.'
            );
        }
        $version = $dir->nextVersion();
        $name ??= self::defaultName($diff->changes);
        $code = $custom ? self::skeleton($version, $name, $diff->changes, $namespace) : null;
        return ['version' => $version, 'files' => $dir->write($version, $name, $driver, $diff->changes, $code)];
    }

    /** A name from the changes: `add_status`, `create_orders`, `rename_note`… — or `update`. */
    public static function defaultName(array $changes): string
    {
        $words = [];
        foreach ($changes as $c) {
            $w = match ($c['op']) {
                'createTable'    => 'create_' . self::short($c['table']),
                'dropTable'      => 'drop_' . self::short($c['table']),
                'addColumn'      => 'add_' . $c['column']['name'],
                'dropColumn'     => 'drop_' . $c['column'],
                'detachColumn'   => 'keep_' . $c['column'],
                'renameColumn'   => 'rename_' . $c['from'],
                'alterColumn'    => 'change_' . $c['column'],
                'setPrimaryKey'  => 'change_' . self::short($c['table']) . '_key',
                'createSchema'   => 'create_schema_' . $c['name'],
                'createExtension' => 'add_' . $c['name'],
                'dropExtension'  => 'drop_' . $c['name'],
                default          => null,
            };
            if ($w !== null && !in_array($w, $words, true)) {
                $words[] = $w;
            }
        }
        if ($words === []) {
            $indexes = array_filter($changes, fn($c) => str_contains($c['op'], 'Index'));
            $words[] = $indexes !== [] ? 'indexes' : 'constraints';
        }
        $name = strtolower(implode('_', array_slice($words, 0, 3)));
        $name = preg_replace('/[^a-z0-9_]+/', '_', $name);
        return strlen($name) > 60 ? 'update' : $name;
    }

    /**
     * The code of a custom version, to fill in: what is left to it, named.
     *
     * @param string|null $namespace Of the class — the config's class name, so that two databases,
     *     or an application and a package, may each have a V003_FillStock in one process.
     */
    public static function skeleton(string $version, string $name, array $changes, ?string $namespace = null): string
    {
        $ns = $namespace !== null && $namespace !== '' ? "\nnamespace " . trim($namespace, '\\') . ";\n" : '';
        // The version in the name: two versions may share a name, a class name may not.
        $class = $version . '_' . str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
        $left = array_filter($changes, fn(array $c) => ($c['by'] ?? null) === 'code');
        $todo = $changes === []
            ? "    // No schema change: this version is its code — change the data in after().\n"
            : ($left === []
            ? "    // The kernel makes every schema change of this version; the code moves the data.\n"
            : "    // Left to this code (\"by\": \"code\") — after() makes them:\n"
                . implode('', array_map(fn(array $c) => '    //   ' . self::describe($c) . "\n", $left)));
        return <<<PHP
            <?php

            declare(strict_types=1);
            {$ns}
            use Flytachi\\Winter\\Cdo\\Connection\\CDO;
            use Flytachi\\Winter\\Ppa\\Schema\\SchemaVersion;
            use Flytachi\\Winter\\Ppa\\Schema\\VersionCode;

            /**
             * {$version} {$name}
             *
             * before(): the schema is still the previous version's.
             * after():  the version's own changes are made; the database is checked against its model next.
             * On MySQL / MariaDB a failed version runs again from its start: keep the code safe to repeat.
             */
            #[SchemaVersion('{$version}')]
            final class {$class} extends VersionCode
            {
            {$todo}
                public function before(CDO \$db): void
                {
                }

                public function after(CDO \$db): void
                {
                }
            }

            PHP;
    }

    /** One line about a change, for a person. */
    public static function describe(array $c): string
    {
        $t = $c['table'] ?? '';
        $col = fn(array $x) => SchemaSql::columnDef($x);
        $by = ($c['by'] ?? null) === 'code' ? '   [by code]' : '';
        return match ($c['op']) {
            'createTable'    => "+ table      {$t} (" . implode(', ', array_column($c['columns'], 'name')) . ')',
            'dropTable'      => "- table      {$t}",
            'addColumn'      => "+ column     {$t}." . $col($c['column']),
            'dropColumn'     => "- column     {$t}.{$c['column']}",
            'detachColumn'   => "~ column     {$t}.{$c['column']} leaves the entity, stays in the database (--keep)",
            'renameColumn'   => "~ column     {$t}.{$c['from']} → {$c['to']}   (#[RenamedFrom])",
            'alterColumn'    => "~ column     {$t}.{$c['column']}: " . $col($c['from']) . ' → ' . $col($c['to']) . $by,
            'setPrimaryKey'  => "~ key        {$t} PRIMARY KEY (" . implode(', ', $c['columns']) . "){$by}",
            'addIndex'       => "+ index      {$c['name']}",
            'dropIndex'      => "- index      {$c['name']}",
            'createSchema'   => "+ schema     {$c['name']}",
            'createExtension' => "+ extension  {$c['name']}",
            'dropExtension'  => "- extension  {$c['name']}",
            'addConstraint'  => "+ {$c['kind']}" . str_repeat(' ', max(1, 11 - strlen($c['kind']))) . $c['name'],
            'dropConstraint' => "- {$c['kind']}" . str_repeat(' ', max(1, 11 - strlen($c['kind']))) . $c['name'],
            default          => $c['op'],
        };
    }

    private static function short(string $table): string
    {
        $dot = strrpos($table, '.');
        return $dot === false ? $table : substr($table, $dot + 1);
    }
}
