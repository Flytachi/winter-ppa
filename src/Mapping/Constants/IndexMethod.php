<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Mapping\Constants;

/**
 * @link https://winterframe.net/docs/entities#index Entities: index methods
 */
enum IndexMethod: string
{
    case BTREE = 'BTREE';
    case HASH = 'HASH';
    case GIST = 'GIST';
    case GIN = 'GIN';
}
