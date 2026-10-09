<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Mapping;

use Flytachi\Winter\Ppa\Mapping\Attributes\Additive\AttributeDbAdditive;
use Flytachi\Winter\Ppa\Mapping\Attributes\AttributeDb;
use Flytachi\Winter\Ppa\Mapping\Attributes\Constraint\AttributeDbConstraint;
use Flytachi\Winter\Ppa\Mapping\Attributes\Constraint\AttributeDbConstraintCheck;
use Flytachi\Winter\Ppa\Mapping\Attributes\Constraint\AttributeDbConstraintForeign;
use Flytachi\Winter\Ppa\Mapping\Attributes\Hybrid\AttributeDbHybrid;
use Flytachi\Winter\Ppa\Mapping\Attributes\Idx\AttributeDbIdx;
use Flytachi\Winter\Ppa\Mapping\Attributes\Idx\Index;
use Flytachi\Winter\Ppa\Mapping\Attributes\Primal\AttributeDbType;
use Flytachi\Winter\Ppa\Mapping\Attributes\Sub\AttributeDbSubType;
use Flytachi\Winter\Ppa\Mapping\Structure\CheckConstraint;
use Flytachi\Winter\Ppa\Mapping\Structure\Column;
use Flytachi\Winter\Ppa\Mapping\Structure\ForeignKey;
use ReflectionAttribute;
use ReflectionProperty;

/**
 * @link https://winterframe.net/docs/entities Entities: how columns are built
 */
final class ColumnMapping
{
    /** @var Column[]  */
    private array $columns = [];

    public function __construct(
        private string $dialect = 'mysql'
    ) {
    }

    public function push(ReflectionProperty $property): void
    {
        $this->columns[] = $this->toColumn($property);
    }

    private function toColumn(ReflectionProperty $property): Column
    {
        /** @var ?AttributeDbType $attributeType */
        $attributeType = null;
        /** @var ?AttributeDbSubType $attributeTypeSub */
        $attributeTypeSub = null;
        /** @var Index[] $indexes */
        $indexes = [];
        /** @var ?ForeignKey $foreignKey */
        $foreignKey = null;
        /** @var CheckConstraint[] $checks */
        $checks = [];

        $types = $this->checkingType($property);
        $nullable = null;
        $default = null;


        foreach ($property->getAttributes(AttributeDb::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $this->prepareInstance(
                property: $property,
                attribute: $attribute,
                instance: $attribute->newInstance(),
                attributeType: $attributeType,
                attributeTypeSub: $attributeTypeSub,
                indexes: $indexes,
                foreignKey: $foreignKey,
                checks: $checks,
                types: $types,
                nullable: $nullable,
                default: $default,
            );
        }

        // type
        $type = empty($attributeType)
            ? Column::getPrimitiveSqlType($types, $this->dialect)
            : $attributeType->toSql($this->dialect);
        $type = empty($attributeTypeSub) ? $type
            : $attributeTypeSub->toSql($type, $this->dialect);

        $nullable = $nullable ?? in_array('null', $types);
        $default = $default ?? ($property->hasDefaultValue()
            ? ($property->isDefault()
                ? match (getType($property->getDefaultValue())) {
                    'NULL' => in_array('null', $types)
                        ? 'NULL'
                        : null,
                    'boolean' => $property->getDefaultValue() ? 'TRUE' : 'FALSE',
                    'string' => self::quote($property->getDefaultValue()),
                    'array' => $this->arrayDefault($property->getDefaultValue()),
                    default => "{$property->getDefaultValue()}"
                }
                : null
            )
            : null);

        return new Column(
            name: $property->getName(),
            type: $type,
            nullable: $nullable,
            default: $default,
            indexes: $indexes,
            foreignKey: $foreignKey,
            checks: $checks,
        );
    }

    private function prepareInstance(
        ReflectionProperty $property,
        ReflectionAttribute $attribute,
        object $instance,
        ?AttributeDbType &$attributeType,
        ?AttributeDbSubType &$attributeTypeSub,
        array &$indexes,
        ?ForeignKey &$foreignKey,
        array &$checks,
        array &$types,
        ?bool &$nullable,
        ?string &$default,
    ): void {
        if ($instance instanceof AttributeDbHybrid) {
            foreach ($instance->getInstances($this->dialect) as $subInstance) {
                $this->prepareInstance(
                    property: $property,
                    attribute: $attribute,
                    instance: $subInstance,
                    attributeType: $attributeType,
                    attributeTypeSub: $attributeTypeSub,
                    indexes: $indexes,
                    foreignKey: $foreignKey,
                    checks: $checks,
                    types: $types,
                    nullable: $nullable,
                    default: $default,
                );
            }
        } elseif ($instance instanceof AttributeDbType) {
            if (!$instance->supports($types)) {
                throw new \InvalidArgumentException(
                    $property->getName() . " in " . $property->getDeclaringClass()->getName() . " "
                    . $attribute->getName()
                    . " does not support this type: " . json_encode($types)
                );
            };
            if ($attributeType !== null) {
                throw new \RuntimeException(
                    $property->getName() . " in " . $property->getDeclaringClass()->getName() . " "
                    . $attribute->getName()
                    . " is already set to " . $attributeType::class
                );
            }
            $attributeType = $instance;
        } elseif ($instance instanceof AttributeDbSubType) {
            if (!$instance->supports($types)) {
                throw new \InvalidArgumentException(
                    $property->getName() . " in " . $property->getDeclaringClass()->getName() . " "
                    . $attribute->getName()
                    . " does not support this type: " . json_encode($types)
                );
            };
            $attributeTypeSub = $instance;
        } elseif ($instance instanceof AttributeDbIdx) {
            $instance->columnPreparation($property->getName());
            $indexes[] = $instance->toObject($this->dialect);
        } elseif ($instance instanceof AttributeDbConstraint) {
            if ($instance instanceof AttributeDbConstraintForeign) {
                // One column, one reference: a second one is a modelling mistake, and both
                // would get the same generated name. Refused rather than letting the last win.
                if ($foreignKey !== null) {
                    throw new \LogicException(
                        $property->getName() . " in " . $property->getDeclaringClass()->getName() . " "
                        . $attribute->getName()
                        . " — the column already has a foreign key to {$foreignKey->referencedTable};"
                        . " a column can reference one table only"
                    );
                }
                $foreignKey = $instance->toObject($property->getName(), $this->dialect);
            } elseif ($instance instanceof AttributeDbConstraintCheck) {
                $checks[] = $instance->toObject($property->getName(), $this->dialect);
            }
        } elseif ($instance instanceof AttributeDbAdditive) {
            $instance->preparation($nullable, $default);
        }
    }

    public function getColumns(): array
    {
        return $this->columns;
    }

    private function checkingType(ReflectionProperty $property): array
    {
        $types = [];
        if ($property->hasType()) {
            $type = $property->getType();

            if ($type instanceof \ReflectionNamedType) {
                $types[] = $type->getName();
                if ($type->allowsNull()) {
                    $types[] = 'null';
                }
            } elseif ($type instanceof \ReflectionUnionType) {
                foreach ($type->getTypes() as $typeSub) {
                    $types[] = $typeSub->getName();
                }
            }
        } else {
            $types[] = 'null';
        }
        return $types;
    }

    /**
     * A string as an SQL literal: an apostrophe inside is doubled — the standard escape, the
     * same on every supported dialect — so a default such as "O'Reilly" does not end the
     * literal early and break the statement.
     */
    private static function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * An array default, stored as JSON — the only array column the mapper knows.
     *
     * @throws \LogicException On a dialect without a JSON default form — rather than an
     *   UnhandledMatchError that names nothing.
     */
    private function arrayDefault(array $value): string
    {
        $json = self::quote((string) json_encode($value));

        return match ($this->dialect) {
            'pgsql'  => $json . '::jsonb',
            'mysql'  => '(' . $json . ')',
            'sqlite' => $json,
            default  => throw new \LogicException(
                "An array default has no form for dialect '{$this->dialect}' (pgsql, mysql, sqlite are supported)."
            ),
        };
    }
}
