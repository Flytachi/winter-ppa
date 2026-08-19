<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Mapping\Attributes\Constraint;

use Flytachi\Winter\Ppa\Mapping\Structure\CheckConstraint;

/**
 * @link https://winterframe.net/docs/entities Entities: constraints
 */
interface AttributeDbConstraintCheck extends AttributeDbConstraint
{
    public function toObject(string $columnName, string $dialect = 'mysql'): CheckConstraint;
}
