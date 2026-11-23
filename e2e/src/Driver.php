<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\E2e;

use Flytachi\Winter\Cdo\Connection\CDO;

/**
 * What the run needs to know of one database — the rest ({@see Runner}) is the same for all:
 * how to reach it and empty it, how its schema is read back to compare the variants, its
 * scenario and its data, what its snapshots must refuse.
 */
interface Driver
{
    /** ppa's name of the driver: `pgsql`, `mysql`. */
    public function name(): string;

    /** The server and its version, as the report's title says it. */
    public function title(\PDO $db): string;

    /** The cdo config the generated one extends, a FQCN: `Flytachi\Winter\Cdo\Config\PgDbConfig`. */
    public function configBase(): string;

    /** The environment variable of the connection: `E2E_PG`. */
    public function env(): string;

    public function open(): CDO;

    /** Back to an empty database: no table, no history — what a first start finds. */
    public function reset(\PDO $db): void;

    /**
     * The schema as the server has it — what variants A, B and C must end with alike.
     *
     * @return array<string, list<string>> kind => its lines: `columns`, `indexes`, `constraints`…
     */
    public function dump(\PDO $db): array;

    /** @return list<Step> */
    public function steps(): array;

    /** Stage 2: rows in every table, on the schema of the last version of stage 1. */
    public function fill(\PDO $db): void;

    /**
     * @param int $version The version the database is at.
     * @return array<string, int> table => rows, what stays true through stage 3
     */
    public function counts(int $version): array;

    /**
     * What `snapshot` must refuse on the last state — or, with a null reason, what it must do
     * instead: the fourth element checks the diff and says what it found.
     *
     * @return list<array{0: string, 1: State, 2: ?string, 3?: \Closure(\Flytachi\Winter\Ppa\Schema\SchemaDiff): string}>
     */
    public function refused(State $last): array;

    /** The schema the history table lives in: `public`, or null where there is none. */
    public function historySchema(): ?string;

    /** Whether a version is one transaction — a failed one rolled back, not left half done. */
    public function transactional(): bool;
}
