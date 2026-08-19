<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Mapping\Attributes\Constraint;

use Flytachi\Winter\Ppa\Mapping\Attributes\AttributeDb;
use Flytachi\Winter\Ppa\Mapping\Structure\StructureInterface;

/**
 * @link https://winterframe.net/docs/entities Entities: constraints
 */
interface AttributeDbConstraint extends AttributeDb
{
    public function toObject(string $columnName, string $dialect = 'mysql'): StructureInterface;
}
