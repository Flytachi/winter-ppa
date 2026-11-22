<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Schema;

/**
 * A schema version that cannot be recorded or applied: a snapshot that would lose data, a
 * history file changed after it was applied, a database that differs from its version's
 * model. The message says what and what to do.
 */
final class SchemaException extends \RuntimeException
{
}
