<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Mapping\Attributes\Primal;

use Flytachi\Winter\Ppa\Mapping\Attributes\AttributeDb;

/**
 * @link https://winterframe.net/docs/entities Entities: column types
 */
interface AttributeDbType extends AttributeDb
{
    public function supports(array $phpTypes): bool;
    public function toSql(string $dialect = 'mysql'): string;
}
