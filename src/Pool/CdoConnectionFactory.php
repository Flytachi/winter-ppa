<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Pool;

use Flytachi\Winter\Cdo\Config\Common\DbConfigInterface;
use Flytachi\Winter\CPool\ConnectionFactory;
use Flytachi\Winter\CPool\ResettableConnectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Adapts a CDO {@see DbConfigInterface} to the driver-agnostic
 * {@see ConnectionFactory} the {@see \Flytachi\Winter\CPool\ConnectionPool}
 * drives.
 *
 * The pooled resource is the **config instance**, not the raw CDO — winter-cdo's
 * config owns the connection (`connection()`/`disconnect()`/`ping()`), so pooling the
 * config lets `close()` deterministically drop the socket and `validate()` reuse the
 * driver's own `SELECT 1` probe. Each {@see create()} builds a fresh config so every
 * pool slot gets an independent socket.
 *
 * @link https://winterframe.net/docs/ppa-pooling Connection pool
 */
final readonly class CdoConnectionFactory implements ResettableConnectionFactory
{
    /**
     * @param class-string<DbConfigInterface> $configClass Config to instantiate per slot.
     * @param LoggerInterface $logger PPA channel logger, injected into each config.
     */
    public function __construct(
        private string $configClass,
        private LoggerInterface $logger,
    ) {
    }

    /** Opens one independent connection (own socket) via a fresh config instance. */
    public function create(): object
    {
        /** @var DbConfigInterface $config */
        $config = new ($this->configClass)();
        $config->setUp();
        $config->setLogger($this->logger);
        $config->connect();
        $this->logger->debug("slot opened: {$this->configClass} dsn={$config->getDns()}");
        return $config;
    }

    /** Liveness probe — `false` when the connection is dead. */
    public function validate(object $connection): bool
    {
        /** @var DbConfigInterface $connection */
        return self::probe($connection);
    }

    /**
     * Round-trips `SELECT 1` and reports whether the connection answered.
     *
     * It deliberately does **not** use `DbConfigInterface::ping()`: that method
     * catches `CDOException` only, while `PDO::query()` raises a `PDOException`
     * (unrelated to it), and its `return` inside `finally` swallows the exception —
     * so it answers `true` for a connection that is already dead. Verified against
     * live PostgreSQL and MariaDB: a killed connection still pinged `true`. Relying
     * on it would silently disable the idle-gate and keepalive, which exist
     * precisely to retire dead connections.
     *
     * Catching `Throwable` is the point: any failure to complete the round trip
     * means the connection cannot be handed out.
     */
    public static function probe(DbConfigInterface $config): bool
    {
        try {
            return $config->connection()->query('SELECT 1') !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Rolls back a transaction the returning unit of work left open.
     *
     * Under FPM the connection died with the request and the driver rolled back on
     * close; a pooled connection outlives the request, so without this the next borrower
     * inherits the transaction — its writes are lost with it and its own
     * `beginTransaction()` fails. The rollback is the only outcome that keeps the next
     * borrower correct: committing would publish work its author never finished.
     *
     * Logged at ERROR, not higher: the pool heals itself and keeps serving, but the
     * application has a defect — a `beginTransaction()` without a `commit()`/`rollBack()`
     * on some path (an exception, an early return, a request timeout). `transaction()`
     * closes on every path; prefer it.
     *
     * The clean case costs no round trip: `inTransaction()` is answered from the
     * driver's own state.
     */
    public function reset(object $connection): bool
    {
        try {
            /** @var DbConfigInterface $connection */
            $cdo = $connection->connection();
            if (!$cdo->inTransaction()) {
                return true;
            }
            $this->logger->error(
                "{$this->configClass}: connection returned to the pool with an open transaction"
                . ' (' . self::unitOfWork() . ') — rolled back; its uncommitted work is discarded.'
                . ' Close every beginTransaction() with commit()/rollBack(), or use transaction().'
            );
            $cdo->rollBack();
            return true;
        } catch (\Throwable $e) {
            $this->logger->error(
                "{$this->configClass}: could not reset a returned connection — retired: {$e->getMessage()}"
            );
            return false;
        }
    }

    /** Which unit of work returned the connection — the coroutine id under Swoole. */
    private static function unitOfWork(): string
    {
        if (extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0) {
            return 'cid=' . \Swoole\Coroutine::getCid();
        }
        return 'pid=' . getmypid();
    }

    /** Drops the CDO reference so its socket is closed. */
    public function close(object $connection): void
    {
        /** @var DbConfigInterface $connection */
        $connection->disconnect();
    }
}
