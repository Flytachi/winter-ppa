<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Mapping\Structure;

final class Column implements StructureInterface
{
    /**
     * @param Index[] $indexes
     * @param CheckConstraint|null $checkConstraint A single check — kept for code that
     *   builds a column by hand; it is put first in $checks. Read $checks instead: this
     *   property only ever shows the first of them.
     * @param CheckConstraint[] $checks Every CHECK constraint on the column.
     */
    public function __construct(
        public string $name,
        public string $type,
        public bool $nullable = true,
        public ?string $default = null,
        public array $indexes = [],
        public ?ForeignKey $foreignKey = null,
        public ?CheckConstraint $checkConstraint = null,
        public array $checks = [],
    ) {
        NameValidator::validate($name);
        if ($checkConstraint !== null && !in_array($checkConstraint, $this->checks, true)) {
            array_unshift($this->checks, $checkConstraint);
        }
        $this->checkConstraint = $this->checks[0] ?? null;
    }

    public function toSql(string $tableName, string $dialect = 'mysql'): string
    {
        $parts = [
            $this->name,
            $this->type,
            $this->nullable ? '' : 'NOT NULL',
        ];

        if ($this->default !== null) {
            $parts[] = 'DEFAULT ' . $this->default;
        }

        return implode(' ', array_filter($parts));
    }


    public function constraintsSql(string $tableName, string $dialect = 'mysql'): array
    {
        $result = [];

        foreach ($this->indexes as $index) {
            $result[] = $index->toSql($tableName, $dialect);
        }

        if ($this->foreignKey) {
            $result[] = $this->foreignKey->toSql($tableName, $this->name, $dialect);
        }

        foreach ($this->checks as $check) {
            $result[] = $check->toSql($tableName, $dialect);
        }

        return $result;
    }

    public static function getPrimitiveSqlType(array $types, string $dialect = 'mysql'): string
    {
        $types = array_filter($types, fn($type) => $type !== 'null');

        // Normalize types to lowercase for easier comparison
        $types = array_map('strtolower', $types);
        sort($types);

        if (count($types) == 1) {
            return match ($types[0]) {
                'bool' => 'BOOLEAN',
                'int' => 'INT',
                'float' => match ($dialect) {
                    'mysql' => 'FLOAT',
                    'pgsql' => 'REAL',
                    default => 'TEXT', // Fallback for unknown dialects
                },
                'string' => 'VARCHAR(255)',
                default => 'TEXT',
            };
        } elseif (count($types) > 1) {
            // Handle combined types
            if (in_array('float', $types) && in_array('int', $types)) {
                return match ($dialect) {
                    'mysql' => 'DOUBLE',
                    'pgsql' => 'DOUBLE PRECISION',
                    default => 'TEXT',
                };
            }

            // int|string -> TEXT or VARCHAR, depending on expected length
            // For simplicity, let's map to TEXT for now, as it's a common fallback for mixed types
            if (in_array('int', $types) && in_array('string', $types)) {
                return 'TEXT';
            }

            // bool|int -> INT (0 or 1 for bool, plus int range)
            if (in_array('bool', $types) && in_array('int', $types)) {
                return 'INT';
            }

            // Fallback for any other unhandled combinations
            return 'TEXT';
        }

        return 'TEXT'; // Default for empty or unhandled cases
    }
}
