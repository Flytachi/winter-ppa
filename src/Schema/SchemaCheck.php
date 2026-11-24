<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Schema;

use Flytachi\Winter\Ppa\Mapping\Constants\FKAction;
use Flytachi\Winter\Ppa\Mapping\Constants\IndexMethod;
use Flytachi\Winter\Ppa\Mapping\Constants\IndexType;
use Flytachi\Winter\Ppa\Mapping\Structure\ForeignKey;
use Flytachi\Winter\Ppa\Mapping\Structure\Index;
use Flytachi\Winter\Ppa\Mapping\Structure\Table;

/**
 * What the entities declare that a server will not build as declared — said before anything
 * runs: by `call db snapshot` (an error records nothing), `call db sql` and `call db migrate`.
 * Not by the mapping itself: that runs at every start of the application, and an index
 * created long ago must not stop it.
 *
 * An error is what would fail on the server, or be built with another meaning (a partial
 * unique index without its WHERE is unique over every row). A notice is what is left out
 * and only costs speed or size: a HASH index is a B-tree on InnoDB, an operator class is
 * PostgreSQL's — the same entities stay good for every driver.
 */
final class SchemaCheck
{
    /** PostgreSQL types with no default operator class for GIN / GiST: they need an extension. */
    private const string SCALAR = '/^(SMALLINT|INT|INTEGER|BIGINT|SERIAL|BIGSERIAL|VARCHAR|CHAR|CHARACTER|TEXT|NUMERIC|'
        . 'DECIMAL|REAL|DOUBLE|FLOAT|BOOLEAN|BOOL|DATE|TIME|TIMESTAMP|TIMESTAMPTZ|UUID)\b/i';

    /** Operator classes and the extension that brings them. */
    private const array OP_CLASS_EXTENSION = [
        'gin_trgm_ops' => 'pg_trgm',
        'gist_trgm_ops' => 'pg_trgm',
    ];

    private function __construct()
    {
    }

    /**
     * @param iterable<Table> $tables
     * @param list<string> $extensions The extensions the config declares (`#[Extension]`), by name.
     * @return array{errors: list<string>, notices: list<string>}
     */
    public static function tables(iterable $tables, string $driver, array $extensions = []): array
    {
        $errors = [];
        $notices = [];
        foreach ($tables as $table) {
            $name = $table->getFullName();
            array_push($errors, ...self::reserved($table, $name, $driver));
            $types = [];
            $indexes = $table->indexes;
            foreach ($table->columns as $column) {
                $types[$column->name] = (string) $column->type;
                array_push($indexes, ...$column->indexes);
            }
            $seen = [];
            foreach ($indexes as $index) {
                if ($index->type === IndexType::PRIMARY) {
                    continue;
                }
                $indexName = $index->nameFor($name, $driver);
                if (isset($seen[$indexName])) {
                    $errors[] = "{$name}: two indexes are named {$indexName} ({$seen[$indexName]} and "
                        . self::what($index) . '): give one of them a name: `#[Index(name: …)]`.';
                    continue;
                }
                $seen[$indexName] = self::what($index);
                $named = [...array_values($index->columns), ...$index->includeColumns];
                $unknown = array_diff($named, array_keys($types));
                if ($unknown !== []) {
                    $errors[] = "{$indexName}: " . implode(', ', $unknown) . ' is no column of ' . $name
                        . ' — renamed or gone? Name a column the entity has.';
                    continue;
                }
                [$e, $n] = self::index($index, $indexName, $types, $driver, $extensions);
                array_push($errors, ...$e);
                array_push($notices, ...$n);
            }
            if ($driver === 'mysql') {
                array_push($errors, ...self::setDefault($table, $name));
            }
        }
        return ['errors' => $errors, 'notices' => $notices];
    }

    /** @return array{list<string>, list<string>} */
    private static function index(Index $i, string $name, array $types, string $driver, array $extensions): array
    {
        $errors = [];
        $notices = [];
        $unique = $i->type === IndexType::UNIQUE;
        if ($unique && $i->method !== IndexMethod::BTREE) {
            $errors[] = "{$name}: a unique index is a B-tree — #[Unique(method: {$i->method->name})] is not built "
                . 'by any server here. Leave the method out.';
        }
        switch ($driver) {
            case 'mysql':
                if ($unique) {
                    // its method is refused above
                } elseif ($i->method === IndexMethod::HASH) {
                    $notices[] = "{$name}: HASH is built as a B-tree on MySQL / MariaDB (InnoDB has no hash index).";
                } elseif (in_array($i->method, [IndexMethod::GIN, IndexMethod::GIST], true)) {
                    $errors[] = "{$name}: MySQL / MariaDB have no {$i->method->name} index — keep it to a PostgreSQL "
                        . 'database, or use a B-tree.';
                }
                if ($i->where !== null) {
                    $unique
                        ? $errors[] = "{$name}: MySQL / MariaDB have no partial index — without WHERE {$i->where} the "
                            . 'unique index would hold over every row. Make it unique by the columns, or check it '
                            . 'in code.'
                        : $notices[] = "{$name}: MySQL / MariaDB have no partial index — built over every row, "
                            . "without WHERE {$i->where}.";
                }
                break;
            case 'sqlite':
                if ($i->method !== IndexMethod::BTREE && !$unique) {
                    $notices[] = "{$name}: SQLite has one kind of index — {$i->method->name} is built as its B-tree.";
                }
                break;
            case 'pgsql':
                if ($i->method === IndexMethod::HASH && count($i->columns) > 1) {
                    $errors[] = "{$name}: a HASH index has one column on PostgreSQL ("
                        . implode(', ', $i->columns) . '): use a B-tree, or one column.';
                }
                if (in_array($i->method, [IndexMethod::GIN, IndexMethod::GIST], true)) {
                    array_push($errors, ...self::ginGist($i, $name, $types, $extensions));
                }
                break;
        }
        if ($i->opClass !== null && $driver !== 'pgsql') {
            $notices[] = "{$name}: the operator class {$i->opClass} is PostgreSQL's — left out on {$driver}.";
        }
        if ($i->opClass !== null && $driver === 'pgsql') {
            $ext = self::OP_CLASS_EXTENSION[strtolower($i->opClass)] ?? null;
            if ($ext !== null && !in_array($ext, $extensions, true)) {
                $errors[] = "{$name}: the operator class {$i->opClass} comes with the extension {$ext}: "
                    . "declare #[Extension('{$ext}')] on the database's config.";
            }
        }
        if ($i->includeColumns !== [] && $driver !== 'pgsql') {
            $notices[] = "{$name}: INCLUDE (" . implode(', ', $i->includeColumns) . ") is PostgreSQL's — left out on "
                . "{$driver}.";
        }
        return [$errors, $notices];
    }

    /**
     * GIN and GiST index what has an operator class for them — JSONB, arrays, full text,
     * geometry. A plain column needs one: `pg_trgm`'s for text, or `btree_gin` / `btree_gist`.
     *
     * @return list<string>
     */
    private static function ginGist(Index $i, string $name, array $types, array $extensions): array
    {
        $method = $i->method->name;
        $btreeExt = $i->method === IndexMethod::GIN ? 'btree_gin' : 'btree_gist';
        $errors = [];
        foreach (array_values($i->columns) as $k => $column) {
            if ($k === 0 && $i->opClass !== null) {
                continue;           // its operator class is given (and its extension checked)
            }
            $type = $types[$column] ?? '';
            // An array (`TEXT[]`) has its operator class: GIN is what it is for.
            $array = str_contains($type, '[]') || preg_match('/\bARRAY\b/i', $type);
            if ($type === '' || $array || !preg_match(self::SCALAR, $type) || in_array($btreeExt, $extensions, true)) {
                continue;
            }
            $hint = preg_match('/^(VARCHAR|CHAR|CHARACTER|TEXT)\b/i', $type)
                ? "for text search give it opClass: '" . strtolower($method) . "_trgm_ops' with "
                    . "#[Extension('pg_trgm')], or declare #[Extension('{$btreeExt}')]"
                : "declare #[Extension('{$btreeExt}')] on the database's config, or use a B-tree";
            $errors[] = "{$name}: PostgreSQL has no {$method} operator class for {$column} ({$type}) — {$hint}.";
        }
        return $errors;
    }

    /**
     * A name the server takes for its own: ppa writes names unquoted, the server refuses the
     * statement — `CREATE TABLE groups` on MySQL 8 — at migrate, after the snapshot is written.
     *
     * @return list<string>
     */
    private static function reserved(Table $table, string $name, string $driver): array
    {
        $server = match ($driver) {
            'mysql' => 'MySQL / MariaDB',
            'pgsql' => 'PostgreSQL',
            default => $driver,
        };
        $errors = [];
        if (ReservedWords::is($table->name, $driver)) {
            $errors[] = "{$name}: `{$table->name}` is a word of {$server} — the server refuses it as a table name. "
                . 'Name the table otherwise (the repository\'s `$table`).';
        }
        foreach ($table->columns as $column) {
            if (ReservedWords::is($column->name, $driver)) {
                $errors[] = "{$name}.{$column->name}: `{$column->name}` is a word of {$server} — the server refuses "
                    . 'it as a column name. Name the property otherwise.';
            }
        }
        return $errors;
    }

    /**
     * InnoDB takes `SET DEFAULT` and drops it without a word: the key is made, its action is
     * not — a row whose parent changes keeps a reference to nothing the entities allow.
     *
     * @return list<string>
     */
    private static function setDefault(Table $table, string $name): array
    {
        $keys = array_map(fn(ForeignKey $k) => [$k->columnName, $k], $table->foreignKeys);
        foreach ($table->columns as $column) {
            if ($column->foreignKey !== null) {
                $keys[] = [$column->name, $column->foreignKey];
            }
        }
        $errors = [];
        foreach ($keys as [$column, $key]) {
            foreach (['UPDATE' => $key->onUpdate, 'DELETE' => $key->onDelete] as $on => $action) {
                if ($action === FKAction::SET_DEFAULT) {
                    $errors[] = "{$name}.{$column}: ON {$on} SET DEFAULT is not kept by MySQL / MariaDB (InnoDB "
                        . 'makes the key without it) — use SET NULL, CASCADE or RESTRICT.';
                }
            }
        }
        return $errors;
    }

    private static function what(Index $i): string
    {
        $kind = $i->type === IndexType::UNIQUE ? 'Unique' : 'Index';
        return "{$kind} " . $i->method->name . ' (' . implode(', ', $i->columns) . ')';
    }
}
