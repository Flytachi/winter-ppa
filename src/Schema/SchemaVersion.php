<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Schema;

use Attribute;

/**
 * Binds a {@see VersionCode} class to its schema version — its file,
 * `ppa/<Config>/custom/V<nnn>_<name>.php`, for `ppa/<Config>/V<nnn>_<name>.json`.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class SchemaVersion
{
    public function __construct(public string $version)
    {
    }
}
