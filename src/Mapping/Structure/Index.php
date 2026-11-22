<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Mapping\Structure;

use Flytachi\Winter\Ppa\Mapping\Constants\IndexMethod;
use Flytachi\Winter\Ppa\Mapping\Constants\IndexType;

final class Index implements StructureInterface
{
    public function __construct(
        public array $columns,
        public ?string $name = null,
        public IndexType $type = IndexType::INDEX,
        public IndexMethod $method = IndexMethod::BTREE,
        public ?string $where = null,
        public array $includeColumns = [], // PostgreSQL INCLUDE: columns carried by the index, not keyed
        public ?string $opClass = null // of the first column; its extension is checked by SchemaCheck
    ) {
        if ($name) {
            NameValidator::validate($name);
        }
    }

    /**
     * The index's name: `<table>_<name>_<kind>`, the name being the columns unless one is
     * given; an index of another method than B-tree has it in the name too
     * (`orders_note_gin_idx`), so a column may have a B-tree and a GIN index side by side.
     */
    public function nameFor(string $tableName, string $dialect = 'mysql'): string
    {
        $baseName = $this->name;
        if (!$baseName) {
            $cleanColumns = array_map(fn($col) => preg_replace('/[^a-zA-Z0-9_]/', '', $col), $this->columns);
            $baseName = implode('_', $cleanColumns);
            if ($this->method !== IndexMethod::BTREE) {
                $baseName .= '_' . strtolower($this->method->value);
            }
        }

        $cleanTableName = str_replace(['"', '`'], '', basename(str_replace('.', '/', $tableName)));

        $suffix = match ($this->type) {
            IndexType::PRIMARY => '_pkey',
            IndexType::UNIQUE => '_udx',
            IndexType::INDEX => '_idx',
        };
        $nameSql = "{$cleanTableName}_{$baseName}{$suffix}";

        $limit = $dialect === 'pgsql' ? 63 : 64;
        if (strlen($nameSql) > $limit) {
            // crc32b returns 8 hex chars; reserve 8 + 1 (underscore) so the
            // truncated name fits exactly inside the identifier length limit.
            $suffix = '_' . hash('crc32b', $nameSql);
            $nameSql = substr($nameSql, 0, $limit - strlen($suffix)) . $suffix;
        }
        return $nameSql;
    }

    /**
     * The statement for a dialect. What a server does not have is left out rather than
     * written wrong — {@see \Flytachi\Winter\Ppa\Schema\SchemaCheck} says so, or refuses
     * where leaving it out would change what the index means (a partial unique index):
     *
     * - MySQL / MariaDB (InnoDB): B-tree only — no USING; no partial (WHERE), covering
     *   (INCLUDE) or operator class;
     * - SQLite: one kind of index — no USING; partial yes, INCLUDE and operator class no;
     * - PostgreSQL: all of it.
     */
    public function toSql(string $tableName, string $dialect = 'mysql'): string
    {
        $cols = array_values($this->columns);
        if ($this->opClass !== null && $dialect === 'pgsql') {
            $cols[0] .= ' ' . $this->opClass;
        }
        $columnsSql = ' (' . implode(', ', $cols) . ')';
        $nameSql = $this->nameFor($tableName, $dialect);

        if ($dialect === 'mysql') {
            return match ($this->type) {
                IndexType::PRIMARY => "PRIMARY KEY" . $columnsSql,
                IndexType::UNIQUE => "CREATE UNIQUE INDEX {$nameSql} ON {$tableName}{$columnsSql}",
                IndexType::INDEX => "CREATE INDEX {$nameSql} ON {$tableName}{$columnsSql}",
            };
        }

        if ($dialect === 'pgsql') {
            $usingSql = " USING {$this->method->value}"; // В PG принято всегда указывать метод
            $whereSql = $this->where ? " WHERE {$this->where}" : '';
            $includeSql = !empty($this->includeColumns)
                ? ' INCLUDE (' . implode(', ', $this->includeColumns) . ')'
                : '';

            return match ($this->type) {
                IndexType::PRIMARY => "PRIMARY KEY" . $columnsSql,
                IndexType::UNIQUE => "CREATE UNIQUE INDEX {$nameSql} ON {$tableName}{$usingSql}"
                    . "{$columnsSql}{$includeSql}{$whereSql}",
                IndexType::INDEX => "CREATE INDEX {$nameSql} ON {$tableName}{$usingSql}"
                    . "{$columnsSql}{$includeSql}{$whereSql}",
            };
        }

        if ($dialect === 'sqlite') {
            $whereSql = $this->where ? " WHERE {$this->where}" : '';

            return match ($this->type) {
                IndexType::PRIMARY => "PRIMARY KEY" . $columnsSql,
                IndexType::UNIQUE => "CREATE UNIQUE INDEX {$nameSql} ON {$tableName}{$columnsSql}{$whereSql}",
                IndexType::INDEX => "CREATE INDEX {$nameSql} ON {$tableName}{$columnsSql}{$whereSql}",
            };
        }

        throw new \InvalidArgumentException("Unsupported dialect: {$dialect}");
    }
}
