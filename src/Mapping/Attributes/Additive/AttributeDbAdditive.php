<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Mapping\Attributes\Additive;

use Flytachi\Winter\Ppa\Mapping\Attributes\AttributeDb;

/**
 * @link https://winterframe.net/docs/entities Entities: nullability and defaults
 */
interface AttributeDbAdditive extends AttributeDb
{
    public function preparation(?bool &$nullable, ?string &$default): void;
}
