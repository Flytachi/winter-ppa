<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Mapping\Attributes\Idx;

use Flytachi\Winter\Ppa\Mapping\Attributes\AttributeDb;
use Flytachi\Winter\Ppa\Mapping\Structure\Index;

/**
 * @link https://winterframe.net/docs/entities Entities: indexes
 */
interface AttributeDbIdx extends AttributeDb
{
    public function columnPreparation(string $columnMain): void;
    public function toObject(string $dialect = 'mysql'): Index;
}
