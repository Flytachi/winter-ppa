<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Mapping\Attributes\Constraint;

use Attribute;
use Flytachi\Winter\Ppa\Mapping\Structure\CheckConstraint;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
/**
 * A CHECK constraint, repeatable.
 *
 * On a property it guards that column; several of them, or one next to a
 * {@see CheckEnum}, each become a constraint of their own. On the entity class it is a
 * table-level check — the place for a rule over several columns, such as
 * `end_at > start_at`.
 *
 * @link https://winterframe.net/docs/entities#check Entities: the #[Check] attribute
 */
final readonly class Check implements AttributeDbConstraintCheck
{
    public function __construct(
        public string $expression,
        public ?string $name = null
    ) {
    }

    public function toObject(string $columnName, string $dialect = 'mysql'): CheckConstraint
    {
        return new CheckConstraint(
            expression: $this->expression,
            name: $this->name
        );
    }
}
