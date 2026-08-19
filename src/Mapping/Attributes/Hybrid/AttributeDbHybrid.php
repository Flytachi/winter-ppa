<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Mapping\Attributes\Hybrid;

use Flytachi\Winter\Ppa\Mapping\Attributes\Additive\AttributeDbAdditive;
use Flytachi\Winter\Ppa\Mapping\Attributes\AttributeDb;
use Flytachi\Winter\Ppa\Mapping\Attributes\Idx\AttributeDbIdx;
use Flytachi\Winter\Ppa\Mapping\Attributes\Primal\AttributeDbType;
use Flytachi\Winter\Ppa\Mapping\Attributes\Sub\AttributeDbSubType;

/**
 * @link https://winterframe.net/docs/entities Entities: primary keys
 */
interface AttributeDbHybrid extends AttributeDb
{
    /**
     * @param string $dialect
     * @return array<AttributeDbType|AttributeDbSubType|AttributeDbIdx|AttributeDbAdditive>
     */
    public function getInstances(string $dialect = 'mysql'): array;
}
