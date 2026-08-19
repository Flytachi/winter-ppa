<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Mapping\Attributes\Entity;

use Attribute;
use Flytachi\Winter\Ppa\Mapping\Attributes\AttributeDbEntity;

#[Attribute(Attribute::TARGET_CLASS)]
/**
 * @link https://winterframe.net/docs/entities Entities
 */
final class Table implements AttributeDbEntity
{
}
