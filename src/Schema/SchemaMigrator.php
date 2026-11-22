<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Schema;

use Flytachi\Winter\Cdo\Connection\CDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Brings one database to the last version of its history (`ppa/<Config>/`).
 *
 * - **A fresh database** — none of the history's tables, no history — is created from the
 *   last version's model at once; every version is recorded `created`, no version code runs
 *   (there are no rows to change).
 * - **A database made before there was a history** — its tables, no history: the latest
 *   version it matches is recorded `baseline`, with the ones before it; the rest are applied.
 * - **Otherwise** the versions it does not have are applied in order, each in a transaction
 *   of its own: before() → the steps → after() → the database checked against the version's
 *   model → its row in the history. A step already in the database is not run again.
 *
 * On MySQL and MariaDB a schema change commits at once: a version that fails is recorded
 * `failed` and runs again from its start the next time, its done steps skipped.
 *
 * Refused before anything runs: a version applied here whose files changed since, a version
 * applied here whose files are gone, a version older than the last applied one that was
 * never applied (it arrived late — give it a newer version).
 *
 * The whole run holds the schema lock ({@see SchemaHistory::lock()}).
 */
final class SchemaMigrator
{
    private readonly SchemaHistory $history;
    private readonly SchemaInspector $inspector;

    public function __construct(
        private readonly CDO $db,
        private readonly string $driver,
        private readonly SchemaDirectory $directory,
        string $owner,
        ?string $schema = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly string $label = 'database',
    ) {
        $this->history = new SchemaHistory($db, $driver, $owner, $schema);
        $this->inspector = new SchemaInspector($db, $driver);
    }

    public function history(): SchemaHistory
    {
        return $this->history;
    }

    /**
     * What a run would do, without doing it or taking the lock.
     *
     * @return array{state: string, versions: list<SchemaVersionFile>, rows: array, pending: list<SchemaVersionFile>,
     *     problems: list<string>} `state`: `fresh` (nothing there), `unversioned` (tables, no history),
     *     `behind` (versions pending), `current`, `empty` (no versions).
     */
    public function status(): array
    {
        $versions = $this->directory->versions($this->driver);
        $rows = $this->history->rows();
        $problems = $this->problems($versions, $rows);
        if ($versions === []) {
            $state = 'empty';
        } elseif ($rows === []) {
            $state = $this->anyTable($versions) ? 'unversioned' : 'fresh';
        } else {
            $state = $this->pending($versions, $rows) === [] ? 'current' : 'behind';
        }
        return [
            'state' => $state,
            'versions' => $versions,
            'rows' => $rows,
            'pending' => $rows === [] ? $versions : $this->pending($versions, $rows),
            'problems' => $problems,
        ];
    }

    /**
     * @return list<array{version: string, action: string, ms: int}> What was done: `created`,
     *     `baseline` or `applied` per version.
     * @throws SchemaException The run was refused, or a version failed — its message names the
     *     version and why; versions before it stay applied.
     */
    public function migrate(int $lockTimeout = 300): array
    {
        $versions = $this->directory->versions($this->driver);
        if ($versions === []) {
            return [];
        }
        $this->history->ensure();
        $this->history->lock($lockTimeout, fn() => $this->logger->info(
            "{$this->label}: waiting for the schema lock — another instance is migrating"
        ));
        try {
            $rows = $this->history->rows();
            $problems = $this->problems($versions, $rows);
            if ($problems !== []) {
                throw new SchemaException("{$this->label}: " . implode(' ', $problems));
            }
            $done = [];
            if ($rows === []) {
                if (!$this->anyTable($versions)) {
                    return $this->createFresh($versions);
                }
                $done = $this->baseline($versions);
                $rows = $this->history->rows();
            }
            foreach ($this->pending($versions, $rows) as $v) {
                $done[] = $this->apply($v, $versions);
            }
            if ($done === []) {
                // Debug: a start with nothing to apply says nothing.
                $this->logger->debug("{$this->label}: up to date at " . end($versions)->version);
            }
            return $done;
        } finally {
            $this->history->unlock();
        }
    }

    /** @return list<array{version: string, action: string, ms: int}> */
    private function createFresh(array $versions): array
    {
        $t0 = hrtime(true);
        $model = SchemaModel::fold($versions);
        $this->transaction(function () use ($model, $versions): void {
            foreach (SchemaSql::create($model, $this->driver, SchemaModel::objects($versions)) as $sql) {
                $this->db->exec($sql);
            }
            $this->inspector->forget();
            $this->verify($model, end($versions));
            foreach ($versions as $v) {
                $this->history->record($v, 'created');
            }
        });
        $ms = self::ms($t0);
        $last = end($versions);
        $this->logger->info(
            "{$this->label}: fresh database — created at {$last->version} in {$ms} ms, "
            . count($versions) . ' version(s) recorded'
        );
        return self::actions($versions, 'created');
    }

    /**
     * A database with the history's tables and no history: the latest version it matches.
     *
     * @return list<array{version: string, action: string, ms: int}>
     */
    private function baseline(array $versions): array
    {
        $firstDiff = null;
        for ($i = count($versions) - 1; $i >= 0; $i--) {
            $model = SchemaModel::fold(array_slice($versions, 0, $i + 1));
            $cmp = $this->inspector->compare($model);
            if ($cmp['differences'] === []) {
                $marked = array_slice($versions, 0, $i + 1);
                foreach ($marked as $v) {
                    $this->history->record($v, 'baseline');
                }
                $this->logger->info(
                    "{$this->label}: a database without history matches {$versions[$i]->version} — "
                    . count($marked) . ' version(s) recorded as baseline'
                );
                return self::actions($marked, 'baseline');
            }
            if ($i === count($versions) - 1) {
                $firstDiff = $cmp['differences'];
            }
        }
        throw new SchemaException(
            "{$this->label}: the database has tables of the history but no history, and matches none of its "
            . 'versions. Against the last one: ' . implode('; ', array_slice($firstDiff ?? [], 0, 10)) . '.'
        );
    }

    /** @return array{version: string, action: string, ms: int} */
    private function apply(SchemaVersionFile $v, array $versions): array
    {
        $after = SchemaModel::fold(array_slice($versions, 0, array_search($v, $versions, true) + 1));
        $t0 = hrtime(true);
        $run = function () use ($v, $after, $t0): void {
            $plan = SchemaSql::plan($v->changes, $this->driver);
            $code = $v->code();
            $code?->before($this->db);
            $this->inspector->forget();
            foreach ($plan as ['change' => $change, 'sql' => $sql]) {
                if ($sql === []) {
                    continue;
                }
                if ($this->inspector->isApplied($change)) {
                    $this->logger->debug(
                        "{$this->label}: {$v->version} {$change['op']} " . ($change['table'] ?? $change['name'] ?? '')
                        . ' — already there'
                    );
                    continue;
                }
                foreach ($sql as $statement) {
                    $this->db->exec($statement);
                }
                $this->inspector->forget();
            }
            $code?->after($this->db);
            $this->inspector->forget();
            $this->verify($after, $v);
            $this->history->record($v, 'applied', self::ms($t0));
        };
        try {
            if ($this->transactional()) {
                $this->transaction($run);
            } else {
                $this->history->record($v, 'running');
                $run();
            }
        } catch (\Throwable $e) {
            if (!$this->transactional()) {
                $this->history->record($v, 'failed', self::ms($t0));
            }
            $this->inspector->forget();
            $how = $this->transactional()
                ? 'rolled back'
                : 'recorded as failed; it runs again from its start next time';
            throw new SchemaException(
                "{$this->label}: {$v->label()} failed and was {$how}: " . $e->getMessage(),
                previous: $e,
            );
        }
        $ms = self::ms($t0);
        $this->logger->info("{$this->label}: {$v->label()} applied in {$ms} ms");
        return ['version' => $v->version, 'action' => 'applied', 'ms' => $ms];
    }

    /** @throws SchemaException The database is not what the version says. */
    private function verify(array $model, SchemaVersionFile $v): void
    {
        $cmp = $this->inspector->compare($model);
        foreach ($cmp['notices'] as $n) {
            $this->logger->notice("{$this->label}: {$v->version}: {$n}");
        }
        if ($cmp['differences'] !== []) {
            throw new SchemaException(
                "after {$v->version} the database differs from its model — " . implode('; ', $cmp['differences'])
            );
        }
    }

    /** @return list<string> */
    private function problems(array $versions, array $rows): array
    {
        $problems = [];
        $byVersion = [];
        foreach ($versions as $v) {
            $byVersion[$v->version] = $v;
        }
        $lastApplied = null;
        foreach ($rows as $version => $r) {
            $v = $byVersion[$version] ?? null;
            if ($v === null) {
                $problems[] = "{$version}_{$r['name']} is in the database's history, but its files are gone from "
                    . 'the project — restore them.';
                continue;
            }
            if (!in_array($r['state'], ['failed', 'running'], true)) {
                $lastApplied = $version;
                if ($r['checksum'] !== $v->checksum) {
                    $problems[] = "{$v->label()} changed after this database applied it (checksum {$r['checksum']} → "
                        . "{$v->checksum}): a version once applied is not edited — "
                        . 'record the change as a new snapshot.';
                }
            }
        }
        if ($lastApplied !== null) {
            foreach ($versions as $v) {
                $older = SchemaVersionFile::number($v->version) < SchemaVersionFile::number($lastApplied);
                if ($older && !isset($rows[$v->version])) {
                    $problems[] = "{$v->label()} is older than {$lastApplied}, the last version applied here, and was "
                        . 'never applied: it arrived late — record it again as a new snapshot.';
                }
            }
        }
        return $problems;
    }

    /** @return list<SchemaVersionFile> Versions not applied, a failed one first. */
    private function pending(array $versions, array $rows): array
    {
        return array_values(array_filter(
            $versions,
            fn(SchemaVersionFile $v) => !isset($rows[$v->version])
                || in_array($rows[$v->version]['state'], ['failed', 'running'], true),
        ));
    }

    /** Whether the database has any table the history ever had. */
    private function anyTable(array $versions): bool
    {
        $tables = [];
        foreach ($versions as $v) {
            foreach ($v->changes as $c) {
                if ($c['op'] === 'createTable') {
                    $tables[$c['table']] = true;
                }
            }
        }
        foreach (array_keys($tables) as $t) {
            if ($this->inspector->hasTable($t)) {
                return true;
            }
        }
        return false;
    }

    /** PostgreSQL and SQLite roll a schema change back; MySQL and MariaDB commit it at once. */
    private function transactional(): bool
    {
        return $this->driver !== 'mysql';
    }

    private function transaction(callable $work): void
    {
        if (!$this->transactional()) {
            $work();
            return;
        }
        $this->db->beginTransaction();
        try {
            $work();
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** @return list<array{version: string, action: string, ms: int}> */
    private static function actions(array $versions, string $action): array
    {
        return array_map(
            fn(SchemaVersionFile $v) => ['version' => $v->version, 'action' => $action, 'ms' => 0],
            $versions,
        );
    }

    private static function ms(int $t0): int
    {
        return (int) round((hrtime(true) - $t0) / 1e6);
    }
}
