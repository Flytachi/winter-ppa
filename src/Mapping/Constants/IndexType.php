<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Mapping\Constants;

/**
 * @link https://winterframe.net/docs/entities Entities: indexes
 */
enum IndexType: string
{
    case PRIMARY = 'PRIMARY';
    case INDEX = 'INDEX';
    case UNIQUE = 'UNIQUE';
}
