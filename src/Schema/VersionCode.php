<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Schema;

use Flytachi\Winter\Cdo\Connection\CDO;

/**
 * The code of one schema version, for what its snapshot cannot say: data to move, rows to
 * fix, a change the kernel does not make itself (`"by": "code"` in the snapshot).
 *
 * Within a version: {@see before()} on the old schema, then the steps computed from the
 * snapshot, then {@see after()} on the new one, then a check that the database matches the
 * version's model — a version whose code left it otherwise is not recorded. On PostgreSQL
 * and SQLite the whole version is one transaction; on MySQL and MariaDB a schema change
 * commits at once, so a version that failed half-way is run again from its start: the code
 * must be safe to repeat there.
 *
 * Not run on a fresh database: it is created from the last version's model at once, and
 * has no rows to change.
 *
 * ```
 * #[SchemaVersion('V007')]
 * final class V007_AddOrderStatus extends VersionCode
 * {
 *     public function after(CDO $db): void
 *     {
 *         $db->exec("UPDATE orders SET status = 'done' WHERE created_at < NOW() - INTERVAL '30 days'");
 *     }
 * }
 * ```
 */
abstract class VersionCode
{
    /** Before the version's steps: the schema is still the previous version's. */
    public function before(CDO $db): void
    {
    }

    /** After the version's steps: the schema is this version's; also what is left to code. */
    public function after(CDO $db): void
    {
    }
}
