<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Mapping\Attributes\Primal;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
/**
 * @link https://winterframe.net/docs/entities Entities: column types
 */
final readonly class Time extends DateTime implements AttributeDbType
{
    public function toSql(string $dialect = 'mysql'): string
    {
        return 'TIME';
    }
}
