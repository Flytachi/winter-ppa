<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Mapping\Attributes\Constraint;

use Attribute;
use Flytachi\Winter\Ppa\Mapping\Constants\FKAction;

#[Attribute(Attribute::TARGET_PROPERTY)]
/**
 * @link https://winterframe.net/docs/entities#foreignkey Entities: the #[ForeignKey] attribute
 */
final readonly class ForeignKey implements AttributeDbConstraintForeign
{
    public function __construct(
        public string $referencedTable,
        public string $referencedColumn,
        public FKAction $onUpdate = FKAction::RESTRICT,
        public FKAction $onDelete = FKAction::RESTRICT,
        public ?string $name = null,
    ) {
    }

    public function toObject(
        string $columnName,
        string $dialect = 'mysql'
    ): \Flytachi\Winter\Ppa\Mapping\Structure\ForeignKey {
        return new \Flytachi\Winter\Ppa\Mapping\Structure\ForeignKey(
            referencedTable: $this->referencedTable,
            referencedColumn: $this->referencedColumn,
            onUpdate: $this->onUpdate,
            onDelete: $this->onDelete,
            name: $this->name,
            columnName: $columnName,
        );
    }
}
