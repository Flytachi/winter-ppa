<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Mapping\Attributes\Additive;

use Flytachi\Winter\Ppa\Mapping\Attributes\AttributeDb;

interface AttributeDbAdditive extends AttributeDb
{
    public function preparation(?bool &$nullable, ?string &$default): void;
}
