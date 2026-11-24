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

    /** The connection's database, on MySQL / MariaDB: asked once, when needed. */
    private ?string $database = null;

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
     * @param int $lockTimeout Seconds to wait for another instance's run, at most; 0: as long
     *     as it runs — the start of an application, which must not fail because another
     *     instance migrates for minutes (the lock dies with its holder's connection).
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
        $this->history->lock($lockTimeout, fn(int $waited) => $this->logger->info($waited === 0
            ? "{$this->label}: waiting for the schema lock — another instance is migrating"
            : "{$this->label}: still waiting for the schema lock — " . self::seconds($waited)));
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
                if (($rows[$v->version]['state'] ?? null) === 'running') {
                    // Only where a schema change commits at once: the run before was stopped
                    // midway (killed, a restart) — what it did is there, the rest is not.
                    $this->logger->warning(
                        "{$this->label}: {$v->label()} was stopped midway the last time — it runs again, "
                        . 'its done steps skipped and its code run again'
                    );
                }
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
                $this->run($sql);
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
            $rebuilt = SchemaSql::rebuilt($v->changes, $this->driver);
            $code = $v->code();
            $code?->before($this->db);
            $this->inspector->forget();
            foreach ($plan as ['change' => $change, 'sql' => $sql]) {
                if ($sql === [] || SchemaSql::byRebuild($change, $rebuilt)) {
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
                    $this->run($statement);
                }
                $this->inspector->forget();
            }
            $this->dropKeyIndexes($v, $after);
            foreach ($rebuilt as $table) {
                $have = array_keys($this->inspector->table($table)['columns'] ?? []);
                foreach (SchemaSql::rebuild($table, $after[$table], $have) as $statement) {
                    $this->run($statement);
                }
                $this->inspector->forget();
                $this->logger->debug("{$this->label}: {$v->version} {$table} rebuilt");
            }
            $code?->after($this->db);
            $this->inspector->forget();
            $this->verify($after, $v);
            if ($rebuilt !== []) {
                $this->foreignKeysHold();
            }
            $this->history->record($v, 'applied', self::ms($t0));
        };
        try {
            if (SchemaSql::rebuilt($v->changes, $this->driver) !== []) {
                $this->withoutForeignKeys(fn() => $this->transaction($run));
            } elseif ($this->transactional()) {
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

    /**
     * MySQL / MariaDB give a foreign key an index of its name when no index has its columns —
     * and keep it when the key is dropped: an upgraded database kept `fk_users_group_id`, one
     * created fresh never had it. Dropped with its key, unless the model has an index of that
     * name. After the steps, every time: a version stopped between the two finds it here.
     */
    private function dropKeyIndexes(SchemaVersionFile $v, array $model): void
    {
        if ($this->driver !== 'mysql') {
            return;
        }
        foreach ($v->changes as $c) {
            if ($c['op'] !== 'dropConstraint' || $c['kind'] !== 'foreign') {
                continue;
            }
            $have = $this->inspector->table($c['table']);
            $left = $have !== null && in_array($c['name'], $have['indexes'], true);
            if ($left && !isset($model[$c['table']]['indexes'][$c['name']])) {
                try {
                    $this->db->exec("ALTER TABLE {$c['table']} DROP INDEX {$c['name']}");
                } catch (\PDOException $e) {
                    if (($e->errorInfo[1] ?? null) !== 1553) {
                        throw $e;
                    }
                    // 1553: another foreign key uses it — the server keeps it, and so do we.
                }
                $this->inspector->forget();
            }
        }
    }

    /**
     * One statement of a version. On MySQL / MariaDB a table name without a database is in the
     * database of the table at hand — a key from `shop.payments` to `orders` was looked for in
     * `shop` and refused (errno 150). Such a reference gets the connection's database, which
     * a snapshot cannot know; PostgreSQL finds it by its search_path.
     */
    private function run(string $statement): void
    {
        $elsewhere = '/^\s*(?:CREATE|ALTER)\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?\w+\./i';
        if ($this->driver === 'mysql' && preg_match($elsewhere, $statement)) {
            $this->database ??= (string) $this->db->query('SELECT DATABASE()')->fetchColumn();
            $statement = (string) preg_replace(
                '/\bREFERENCES\s+(\w+)\s*\(/i',
                "REFERENCES `{$this->database}`.$1(",
                $statement,
            );
        }
        $this->db->exec($statement);
    }

    /**
     * SQLite, a table rebuilt: foreign keys off around the version's transaction — inside one
     * the pragma does nothing, and a DROP TABLE with them on deletes the rows that point at it
     * (or cascades into them). Back as they were after.
     */
    private function withoutForeignKeys(callable $work): void
    {
        $on = (bool) $this->db->query('PRAGMA foreign_keys')->fetchColumn();
        if ($on) {
            $this->db->exec('PRAGMA foreign_keys = OFF');
        }
        try {
            $work();
        } finally {
            if ($on) {
                $this->db->exec('PRAGMA foreign_keys = ON');
            }
        }
    }

    /**
     * After a rebuild, with foreign keys off: every row still points at a row — or the version
     * rolls back, saying where.
     *
     * @throws SchemaException
     */
    private function foreignKeysHold(): void
    {
        $broken = $this->db->query('PRAGMA foreign_key_check')->fetchAll(\PDO::FETCH_ASSOC);
        if ($broken === []) {
            return;
        }
        $where = [];
        foreach ($broken as $row) {
            $key = "{$row['table']} → {$row['parent']}";
            $where[$key] = ($where[$key] ?? 0) + 1;
        }
        throw new SchemaException('rows point at no row after the rebuild: ' . implode(', ', array_map(
            fn(string $k, int $n) => "{$k} ({$n})",
            array_keys($where),
            $where,
        )) . ' — clear them in before().');
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

    /** 150 → "2m30s". */
    private static function seconds(int $s): string
    {
        return $s >= 60 ? intdiv($s, 60) . 'm' . ($s % 60) . 's' : "{$s}s";
    }

    private static function ms(int $t0): int
    {
        return (int) round((hrtime(true) - $t0) / 1e6);
    }
}
