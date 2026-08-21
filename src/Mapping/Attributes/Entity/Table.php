<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Mapping\Attributes\Entity;

use Attribute;
use Flytachi\Winter\Ppa\Mapping\Attributes\AttributeDbEntity;

#[Attribute(Attribute::TARGET_CLASS)]
/**
 * @link https://winterframe.net/docs/entities#table Entities: the #[Table] attribute
 */
final class Table implements AttributeDbEntity
{
}
