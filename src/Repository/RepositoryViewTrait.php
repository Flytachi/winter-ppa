<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Repository;

use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\Cdo\CDOBind;
use Flytachi\Winter\Cdo\Connection\CDOStatement;
use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\Ppa\Entity\EntityException;
use Flytachi\Winter\Ppa\Entity\RepositoryViewInterface;
use Flytachi\Winter\Ppa\Pool\PpaConnectionPool;
use PDO;
use Throwable;

/**
 * Provides concrete read-operation implementations for repository classes.
 *
 * Implements {@see RepositoryViewInterface} by building SQL via {@see RepositoryCore},
 * executing it through CDO, and hydrating results into the configured entity class.
 * Every method that runs the built query resets it afterwards with {@see cleanCache()} —
 * when it fails too. A query is used once: kept after a failure, its conditions, offset
 * and binds would be picked up by the next call on the repository, and a repository
 * injected into a process or a daemon lives as long as the process.
 *
 * Mix into any {@see RepositoryCore} subclass that needs read access:
 * ```
 * class UserRepository extends RepositoryCore implements RepositoryViewInterface
 * {
 *     use RepositoryViewTrait;
 * }
 * ```
 *
 * `TEntity` is bound by the consuming class via an `@use` PHPDoc tag pinning
 * the template parameter (or transitively through a stereotype's `@extends`).
 *
 * @template TEntity of object
 * @mixin RepositoryViewInterface<TEntity>
 *
 * @link https://winterframe.net/docs/repository Repositories: reading
 */
trait RepositoryViewTrait
{
    /**
     * Executes a raw SQL query with explicit binds and returns hydrated objects.
     *
     * @template TOverride of object
     * @param string $sql Raw SQL string with named placeholders.
     * @param CDOBind[] $binds Array of {@see CDOBind} objects.
     * @param class-string<TOverride>|null $entityClassName Override entity class for hydration;
     *                                                      `null` uses the repository default.
     * @return ($entityClassName is null ? list<TEntity> : list<TOverride>) Array of hydrated objects.
     * @throws RepositoryException
     *
     * @link https://winterframe.net/docs/repository#rawfetch Running raw SQL
     */
    final public function rawFetch(string $sql, array $binds = [], ?string $entityClassName = null): array
    {
        try {
            $stmt = new CDOStatement($this->db()->prepare($sql));
            foreach ($binds as $bind) {
                $stmt->bindTypedValue($bind->getName(), $bind->getValue());
            }
            $stmt->getStmt()->execute();
            return $stmt->getStmt()->fetchAll(
                PDO::FETCH_CLASS,
                $entityClassName ?: $this->state()->entityClassName
            );
        } catch (Throwable $th) {
            PpaConnectionPool::reportFailure($this->dbConfigClassName, $th);
            throw new RepositoryException($th->getMessage(), previous: $th);
        }
    }

    /**
     * Executes the built query and returns the first matching row, or null.
     *
     * Automatically applies `LIMIT 1`. Resets the query afterwards, on failure too.
     *
     * @template TOverride of object
     * @param class-string<TOverride>|null $entityClassName Override entity class for hydration.
     * @return ($entityClassName is null ? TEntity|null : TOverride|null) First matching entity, or `null`.
     * @throws RepositoryException
     *
     * @link https://winterframe.net/docs/repository#find Reading the first row
     */
    final public function find(?string $entityClassName = null): ?object
    {
        try {
            $this->limit(1);
            $resolvedClass = $entityClassName ?: $this->getEntityClassName();
            $stmt = new CDOStatement($this->db()->prepare($this->buildSql()));
            $this->useBind($stmt);
            $stmt->getStmt()->execute();
            return $stmt->getStmt()->fetchObject($resolvedClass) ?: null;
        } catch (Throwable $th) {
            PpaConnectionPool::reportFailure($this->dbConfigClassName, $th);
            throw new RepositoryException($th->getMessage(), previous: $th);
        } finally {
            $this->cleanCache();
        }
    }

    /**
     * Executes the built query and returns a single column value from the first row.
     *
     * Automatically applies `LIMIT 1`. Resets the query afterwards, on failure too.
     *
     * @param int $column Zero-based column index (default 0)
     * @return mixed Column value, or false if no row found
     * @throws RepositoryException
     *
     * @link https://winterframe.net/docs/repository#findcolumn Reading one value
     */
    final public function findColumn(int $column = 0): mixed
    {
        try {
            $this->limit(1);
            $stmt = new CDOStatement($this->db()->prepare($this->buildSql()));
            $this->useBind($stmt);
            $stmt->getStmt()->execute();
            return $stmt->getStmt()->fetchColumn($column);
        } catch (Throwable $th) {
            PpaConnectionPool::reportFailure($this->dbConfigClassName, $th);
            throw new RepositoryException($th->getMessage(), previous: $th);
        } finally {
            $this->cleanCache();
        }
    }

    /**
     * Executes the built query and returns all matching rows.
     *
     * Resets the query afterwards, on failure too.
     *
     * @template TOverride of object
     * @param class-string<TOverride>|null $entityClassName Override entity class for hydration.
     * @return ($entityClassName is null ? list<TEntity> : list<TOverride>) Array of hydrated objects.
     * @throws RepositoryException
     *
     * @link https://winterframe.net/docs/repository#findall Reading every row
     */
    final public function findAll(?string $entityClassName = null): array
    {
        try {
            $resolvedClass = $entityClassName ?: $this->getEntityClassName();
            $stmt = new CDOStatement($this->db()->prepare($this->buildSql()));
            $this->useBind($stmt);
            $stmt->getStmt()->execute();
            return $stmt->getStmt()->fetchAll(PDO::FETCH_CLASS, $resolvedClass);
        } catch (Throwable $th) {
            PpaConnectionPool::reportFailure($this->dbConfigClassName, $th);
            throw new RepositoryException($th->getMessage(), previous: $th);
        } finally {
            $this->cleanCache();
        }
    }

    /**
     * Returns how many rows the built query matches — ORDER BY, LIMIT, OFFSET and FOR
     * never take part, so a paged query counts its total, not its page.
     *
     * A plain filter keeps the direct form, `SELECT COUNT(*) FROM … WHERE …`. A query whose
     * rows are not plain table rows — a custom {@see select()}, GROUP BY, HAVING or a union —
     * is counted as a subquery, `SELECT COUNT(*) FROM (…) AS tmp`, the way
     * {@see \Flytachi\Winter\Ppa\Pagination\Paginator::repo()} counts: a grouped query
     * counts its groups, a union both parts, `select('a, b')` its rows.
     * Resets the query afterwards, on failure too.
     *
     * @return int Row count
     * @throws RepositoryException
     *
     * @link https://winterframe.net/docs/repository#count Counting rows
     */
    final public function count(): int
    {
        try {
            $stmt = new CDOStatement($this->db()->prepare($this->countSql()));
            $this->useBind($stmt);
            $stmt->getStmt()->execute();
            return (int) $stmt->getStmt()->fetchColumn();
        } catch (Throwable $th) {
            PpaConnectionPool::reportFailure($this->dbConfigClassName, $th);
            throw new RepositoryException($th->getMessage(), previous: $th);
        } finally {
            $this->cleanCache();
        }
    }

    /**
     * The SQL {@see count()} runs: the direct `COUNT(*)` for a plain filter, the query
     * wrapped as a subquery otherwise. Leaves the builder state as it found it.
     */
    private function countSql(): string
    {
        $parts  = $this->state()->sqlParts;
        $ignore = ['order', 'limit', 'offset', 'for'];

        $plain = !isset($parts['option']) && !isset($parts['group'])
            && !isset($parts['having']) && !isset($parts['union']);
        if ($plain) {
            $this->state()->sqlParts['option'] = 'COUNT(*)';
            try {
                return $this->buildSql($ignore);
            } finally {
                unset($this->state()->sqlParts['option']);
            }
        }

        // Without a select of its own the inner query would list every entity column, and
        // with GROUP BY that is invalid on PostgreSQL and strict MySQL. Counting needs no
        // columns, so the inner query selects a constant — except in a union, whose parts
        // must keep matching column lists.
        if (!isset($parts['option']) && !isset($parts['union'])) {
            $this->state()->sqlParts['option'] = '1';
            try {
                return 'SELECT COUNT(*) FROM (' . $this->buildSql($ignore) . ') AS tmp';
            } finally {
                unset($this->state()->sqlParts['option']);
            }
        }

        return 'SELECT COUNT(*) FROM (' . $this->buildSql($ignore) . ') AS tmp';
    }

    /**
     * Returns true if at least one row matches the built query.
     *
     * Uses `SELECT 1 LIMIT 1` internally for efficiency.
     * Resets the query afterwards, on failure too.
     *
     * @return bool
     * @throws RepositoryException
     *
     * @link https://winterframe.net/docs/repository#exists Checking existence
     */
    final public function exists(): bool
    {
        try {
            $state = $this->state();
            $state->sqlParts['option'] = '1';
            $this->limit(1);
            $stmt = new CDOStatement($this->db()->prepare($this->buildSql()));
            $this->useBind($stmt);
            $stmt->getStmt()->execute();
            return (bool) $stmt->getStmt()->fetchColumn();
        } catch (Throwable $th) {
            PpaConnectionPool::reportFailure($this->dbConfigClassName, $th);
            throw new RepositoryException($th->getMessage(), previous: $th);
        } finally {
            $this->cleanCache();
        }
    }

    /**
     * Finds a single record by its primary key.
     *
     * Uses {@see mapIdentifierColumnName()} to determine the PK column (default: `'id'`).
     *
     * @template TOverride of object
     * @param int|string $id Primary key value.
     * @param class-string<TOverride>|null $entityClassName Override entity class for hydration.
     * @return ($entityClassName is null ? TEntity|null : TOverride|null) Matching entity, or `null`.
     * @throws RepositoryException
     *
     * @link https://winterframe.net/docs/repository#findbyid Reading by key or condition
     */
    final public function findById(int|string $id, ?string $entityClassName = null): ?object
    {
        return $this->where(Qb::eq($this->mapIdentifierColumnName(), $id))
            ->find($entityClassName);
    }

    /**
     * Finds a single record matching the given condition.
     *
     * @template TOverride of object
     * @param Qb $qb WHERE condition.
     * @param class-string<TOverride>|null $entityClassName Override entity class for hydration.
     * @return ($entityClassName is null ? TEntity|null : TOverride|null) Matching entity, or `null`.
     * @throws RepositoryException
     *
     * @link https://winterframe.net/docs/repository#findby Reading by key or condition
     */
    final public function findBy(Qb $qb, ?string $entityClassName = null): ?object
    {
        return $this->where($qb)->find($entityClassName);
    }

    /**
     * Finds all records matching the given condition, or all rows when `$qb` is `null`.
     *
     * @template TOverride of object
     * @param Qb|null $qb WHERE condition, or `null` to fetch all rows.
     * @param class-string<TOverride>|null $entityClassName Override entity class for hydration.
     * @return ($entityClassName is null ? list<TEntity> : list<TOverride>) Matching entities.
     * @throws RepositoryException
     *
     * @link https://winterframe.net/docs/repository#findallby Reading by key or condition
     */
    final public function findAllBy(?Qb $qb = null, ?string $entityClassName = null): array
    {
        return $this->where($qb)->findAll($entityClassName);
    }

    /**
     * Finds a record by its primary key, or throws if not found.
     *
     * @template TOverride of object
     * @param int|string $id Primary key value.
     * @param class-string<TOverride>|null $entityClassName Override entity class for hydration.
     * @param string $message Exception message when not found.
     * @param HttpCode $httpCode HTTP status code when not found.
     * @return ($entityClassName is null ? TEntity : TOverride) Matching entity (never `null`).
     * @throws EntityException When the record is not found.
     * @throws RepositoryException
     *
     * @link https://winterframe.net/docs/repository#findbyidorthrow Reading, or failing loudly
     */
    final public function findByIdOrThrow(
        int|string $id,
        ?string $entityClassName = null,
        string $message = 'Entity not found',
        HttpCode $httpCode = HttpCode::NOT_FOUND
    ): object {
        $obj = $this->findById($id, $entityClassName);
        if (!$obj) {
            throw new EntityException($message, $httpCode->value);
        }
        return $obj;
    }

    /**
     * Finds a record matching the given condition, or throws if not found.
     *
     * @template TOverride of object
     * @param Qb $qb WHERE condition.
     * @param class-string<TOverride>|null $entityClassName Override entity class for hydration.
     * @param string $message Exception message when not found.
     * @param HttpCode $httpCode HTTP status code when not found.
     * @return ($entityClassName is null ? TEntity : TOverride) Matching entity (never `null`).
     * @throws EntityException When no record matches the condition.
     * @throws RepositoryException
     *
     * @link https://winterframe.net/docs/repository#findbyorthrow Reading, or failing loudly
     */
    final public function findByOrThrow(
        Qb $qb,
        ?string $entityClassName = null,
        string $message = 'Entity not found',
        HttpCode $httpCode = HttpCode::NOT_FOUND
    ): object {
        $obj = $this->findBy($qb, $entityClassName);
        if (!$obj) {
            throw new EntityException($message, $httpCode->value);
        }
        return $obj;
    }
}
