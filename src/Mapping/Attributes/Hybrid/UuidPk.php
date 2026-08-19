<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Mapping\Attributes\Hybrid;

use Attribute;
use Flytachi\Winter\Ppa\Mapping\Attributes\Additive\DefaultVal;
use Flytachi\Winter\Ppa\Mapping\Attributes\Additive\NullableIs;
use Flytachi\Winter\Ppa\Mapping\Attributes\Idx\Primary;
use Flytachi\Winter\Ppa\Mapping\Attributes\Primal\Uuid;

#[Attribute(Attribute::TARGET_PROPERTY)]
/**
 * @link https://winterframe.net/docs/entities Entities: primary keys
 */
final readonly class UuidPk implements AttributeDbHybrid
{
    public function getInstances(string $dialect = 'mysql'): array
    {
        return [
            new Primary(),
            new Uuid(),
            new NullableIs(false),
            new DefaultVal($dialect === 'pgsql'
                ? "gen_random_uuid()"
                : "UUID()")
        ];
    }
}
