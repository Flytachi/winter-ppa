<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Mapping\Constants;

/**
 * Migration ordering priority for {@see \Flytachi\Winter\Ppa\Mapping\Attributes\Config\Migratable}.
 *
 * Lower value = earlier in the migration order. Sort ascending.
 */
enum MigratablePriority: int
{
    case High = 0;
    case Normal = 50;
    case Low = 100;
}
