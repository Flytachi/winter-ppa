<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Pool;

use Flytachi\FileStore\FileStorage;
use RuntimeException;

/**
 * Publishes each worker's pool utilisation to the shared runnable store so the CLI
 * can read it — the same pattern the framework's process runtime uses for
 * `call process status`.
 *
 * A **source** is whatever process owns a pool: a web worker, and just as well a
 * managed process or a daemon worker, each of which keeps a pool of its own. It
 * publishes under a kind ({@see KIND_WEB}, {@see KIND_PROCESS}, {@see KIND_DAEMON}) and
 * a name, so a reader can keep them apart — a saturated process pool says nothing
 * about the web workers, and the other way round.
 *
 * A connection pool lives in **one worker's memory**. The CLI is a separate process,
 * so it can never inspect a running server's pool directly, and `/actuator/health`
 * only ever reports the single worker that served the request. Each worker therefore
 * writes a small record on a timer; `call db pool` reads every record and aggregates
 * them into a fleet-wide picture — and it works whether or not the actuator is enabled.
 *
 * ## Cost
 * The write happens on a timer coroutine, **never in the request path**, so it adds
 * nothing to request latency. The payload is a handful of integers per config
 * (a few hundred bytes) written once per {@see interval()} per worker. Records carry
 * a TTL of three intervals, so a worker that dies (or is killed) simply stops
 * refreshing and its record expires — no cleanup pass and no stale numbers. A worker
 * holding no pool writes nothing at all, so an application that never touches PPA
 * pays exactly zero.
 *
 * Nothing is armed until there is something to report: {@see enable()} only marks the
 * worker as eligible, and the timer starts on the first pool ({@see arm()}, called by
 * {@see PpaConnectionPool}) — the same lazy shape the pool uses for its own housekeeper.
 * An application with no datasource therefore has no timer and leaves no directory.
 *
 * Set `PPA_POOL_TELEMETRY` to the publish interval in seconds, or `0` to disable.
 *
 * @link https://winterframe.net/docs/ppa-pooling Connection pool: watching it
 */
final class PoolTelemetry
{
    /** A web server worker — named by its worker id. */
    public const string KIND_WEB = 'web';

    /** A managed process — named by its class. */
    public const string KIND_PROCESS = 'process';

    /** A daemon worker — named by its daemon and slot. */
    public const string KIND_DAEMON = 'daemon';

    /** Readers order sources this way: the server first, then what runs beside it. */
    private const array KIND_ORDER = [self::KIND_WEB => 0, self::KIND_PROCESS => 1, self::KIND_DAEMON => 2];

    /** Store folder holding one record per worker. */
    private const string STORE = 'ppa.pool';

    /** Publish interval when `PPA_POOL_TELEMETRY` is unset, in seconds. */
    private const float DEFAULT_INTERVAL = 5.0;

    /** Swoole timer id of the publisher in this worker, or null when not running. */
    private static ?int $timerId = null;

    /** Builds the storage on first use; the framework installs it at boot. @var (callable(): FileStorage)|null */
    private static $storeProvider = null;

    /** The built storage, kept so it is built once. */
    private static ?FileStorage $store = null;

    /** The source this process publishes as, or null where telemetry does not apply. */
    private static int|string|null $source = null;

    /** The kind of that source. */
    private static string $kind = self::KIND_WEB;

    /**
     * Whether a record was ever written from this process — the only thing that
     * justifies touching the store on the way out. Reaching for it otherwise would
     * create the store directory in an application that has no pool at all.
     */
    private static bool $published = false;

    /**
     * Publish interval in seconds from `PPA_POOL_TELEMETRY`; `0.0` disables telemetry.
     * Values below one second are raised to one — this is telemetry, not a heartbeat.
     */
    public static function interval(): float
    {
        $raw = env('PPA_POOL_TELEMETRY');
        $val = $raw === null || $raw === '' ? self::DEFAULT_INTERVAL : (float) $raw;

        return $val <= 0.0 ? 0.0 : max(1.0, $val);
    }

    /**
     * Marks this process as eligible to publish. Call once per process — the server's
     * `workerStart` with the worker id, a managed process or a daemon worker with its
     * name and kind; a no-op when telemetry is disabled or Swoole is absent.
     *
     * This arms nothing. Only a process that goes on to open a pool has anything to
     * report, and only that process should own a timer — see {@see arm()}.
     *
     * @param int|string $source The worker id, or the name of the process.
     * @param string $kind One of the `KIND_*` constants.
     */
    public static function enable(int|string $source, string $kind = self::KIND_WEB): void
    {
        if (self::interval() <= 0.0 || !extension_loaded('swoole')) {
            return;
        }

        self::$source = $source;
        self::$kind     = $kind;
    }

    /**
     * Starts the publisher, if this process is an eligible worker and is not already
     * publishing. Called by {@see PpaConnectionPool} when it opens its first pool —
     * the first moment there is anything to report.
     */
    public static function arm(): void
    {
        $interval = self::interval();
        if (
            self::$timerId !== null
            || self::$source === null
            || $interval <= 0.0
            || !extension_loaded('swoole')
        ) {
            return;
        }

        $ttl = (int) ceil($interval * 3);
        self::$timerId = \Swoole\Timer::tick(
            (int) ($interval * 1000),
            static fn() => self::publish($ttl),
        );
    }

    /**
     * Stops publishing and drops this process's record.
     *
     * The timer has to go before a process can end: a repeating timer keeps
     * `Coroutine\run()` open just as a pool's housekeeper does.
     *
     * @param int|string|null $workerId The server's `workerExit` passes its worker id; a
     *   process that called {@see enable()} already knows what it published as.
     */
    public static function stop(int|string|null $workerId = null): void
    {
        if (self::$timerId !== null && extension_loaded('swoole')) {
            \Swoole\Timer::clear(self::$timerId);
        }
        $source = self::$source ?? $workerId;
        $kind   = self::$source !== null ? self::$kind : self::KIND_WEB;
        self::$timerId  = null;
        self::$source = null;
        self::$kind     = self::KIND_WEB;

        if (!self::$published || $source === null) {
            self::$published = false;
            return;
        }
        self::$published = false;

        try {
            self::store()->del(self::recordKey($kind, $source));
        } catch (\Throwable) {
            // Telemetry must never break a shutdown.
        }
    }

    /**
     * Installs how the storage of per-worker records is obtained.
     *
     * Telemetry is inert until this is called: workers publish nothing and
     * {@see snapshot()} answers empty. That is deliberate — the records have to land
     * where the reader is looking, and only the application knows where that is. The
     * framework points it at its own runtime directory at boot.
     *
     * A provider rather than a storage, because building one creates its directory: an
     * application that never touches a pool would otherwise leave an empty
     * `runnable/ppa.pool/` behind, which reads to anyone looking as "this application
     * uses PPA". Nothing is built until something is actually published.
     *
     * Keys are stored unhashed so {@see FileStorage::keys()} round-trips back into
     * {@see FileStorage::read()}.
     */
    public static function setStoreProvider(?callable $provider): void
    {
        self::$storeProvider = $provider;
        self::$store         = null;
    }

    /**
     * Drops this process's publishing identity without touching the store — the
     * fork-safe counterpart of {@see stop()}, called from
     * {@see PpaConnectionPool::reset()}.
     *
     * A forked child inherits these statics, so without this it would publish under its
     * parent's source and overwrite the parent's record with its own numbers.
     */
    public static function forget(): void
    {
        self::$timerId   = null;
        self::$source  = null;
        self::$kind      = self::KIND_WEB;
        self::$published = false;
    }

    /**
     * Reads every worker record still alive, newest state as each worker last published
     * it. Expired records (dead workers) are skipped by the store's TTL.
     *
     * Ordered web workers first, then processes, then daemon workers. A record written
     * before sources existed reads as a web worker.
     *
     * @return list<array{worker: int|string, kind: string, pid: int|null, at: int,
     *     pools: array<string, array{total: int, idle: int, active: int, maximum: int}>}>
     */
    public static function snapshot(): array
    {
        try {
            $store = self::store();
        } catch (\Throwable) {
            return [];
        }

        $records = [];
        foreach ($store->keys() as $key) {
            $record = $store->read($key);
            if (is_array($record) && isset($record['worker'], $record['pools'])) {
                $record['kind'] ??= self::KIND_WEB;
                $record['pid']  ??= null;
                $records[] = $record;
            }
        }

        usort($records, static fn(array $a, array $b): int => [
            self::KIND_ORDER[$a['kind']] ?? PHP_INT_MAX,
            $a['kind'],
            is_int($a['worker']) ? $a['worker'] : PHP_INT_MAX,
            (string) $a['worker'],
        ] <=> [
            self::KIND_ORDER[$b['kind']] ?? PHP_INT_MAX,
            $b['kind'],
            is_int($b['worker']) ? $b['worker'] : PHP_INT_MAX,
            (string) $b['worker'],
        ]);

        return $records;
    }

    /**
     * Aggregates {@see snapshot()} across workers, per config — the fleet-wide view a
     * single actuator response cannot give.
     *
     * `saturated` counts the workers whose pool for that config is fully handed out.
     * It is deliberately **not** derived from the summed totals: a borrow queues on
     * its own worker's pool, so one saturated worker is a real stall even while the
     * fleet as a whole looks to have slack.
     *
     * Without `$kinds` every source counts — the number of connections the whole
     * application holds open, the one to hold against the database's own limit. With
     * them, only those kinds: a process's pool queues on its own, so it is read apart.
     *
     * @param string|list<string>|null $kinds A `KIND_*` constant, a list of them, or null
     *   for every source.
     * @return array<string, array{total: int, idle: int, active: int, maximum: int, workers: int, saturated: int}>
     */
    public static function aggregate(string|array|null $kinds = null): array
    {
        $kinds = is_string($kinds) ? [$kinds] : $kinds;
        $out   = [];
        foreach (self::snapshot() as $record) {
            if ($kinds !== null && !in_array($record['kind'], $kinds, true)) {
                continue;
            }
            foreach ($record['pools'] as $config => $stat) {
                $acc = $out[$config] ??= [
                    'total' => 0, 'idle' => 0, 'active' => 0, 'maximum' => 0, 'workers' => 0, 'saturated' => 0,
                ];
                $saturated = $stat['maximum'] > 0 && $stat['active'] >= $stat['maximum'];
                $out[$config] = [
                    'total'     => $acc['total'] + $stat['total'],
                    'idle'      => $acc['idle'] + $stat['idle'],
                    'active'    => $acc['active'] + $stat['active'],
                    'maximum'   => $acc['maximum'] + $stat['maximum'],
                    'workers'   => $acc['workers'] + 1,
                    'saturated' => $acc['saturated'] + ($saturated ? 1 : 0),
                ];
            }
        }

        return $out;
    }

    // ── internals ──────────────────────────────────────────────────────────────

    /**
     * Writes this process's current utilisation. A process holding no pool writes
     * nothing — an application that never touches PPA leaves no records behind.
     */
    private static function publish(int $ttl): void
    {
        $source = self::$source;
        if ($source === null) {
            return;
        }

        try {
            $pools = PpaConnectionPool::stats();
            if ($pools === []) {
                return;
            }

            self::store()->write(
                self::recordKey(self::$kind, $source),
                ['worker' => $source, 'kind' => self::$kind, 'pid' => getmypid(), 'at' => time(), 'pools' => $pools],
                time() + $ttl,
            );
            self::$published = true;
        } catch (\Throwable) {
            // Telemetry is best-effort: a failed write must never disturb the worker.
        }
    }

    /**
     * One flat file name per source. Keys are stored unhashed, so a class name's
     * separators would otherwise turn into directories the reader never walks. A web
     * worker keeps the `worker.N` it always had.
     */
    private static function recordKey(string $kind, int|string $source): string
    {
        $prefix = $kind === self::KIND_WEB ? 'worker' : $kind;

        return preg_replace('/[^A-Za-z0-9._-]/', '_', $prefix . '.' . $source);
    }

    /**
     * The storage, or a failure the callers already handle.
     *
     * @throws RuntimeException When no storage was installed — telemetry cannot invent a
     *   location, and every caller of this already treats a throw as "no records".
     */
    private static function store(): FileStorage
    {
        if (self::$store !== null) {
            return self::$store;
        }

        $provider = self::$storeProvider ?? throw new RuntimeException(
            'PoolTelemetry has no storage: call PoolTelemetry::setStoreProvider() at application boot.',
        );

        return self::$store = $provider();
    }
}
