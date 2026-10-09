<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Repository;

use Flytachi\Winter\Cdo\CDOBind;
use Flytachi\Winter\Cdo\Connection\CDO;
use Flytachi\Winter\Cdo\Connection\CDOStatement;
use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\DI\Attribute\Autowired;
use Flytachi\Winter\DI\Attribute\Inject;
use Flytachi\Winter\DI\Container;
use Flytachi\Winter\Ppa\Entity\EntityInterface;
use Flytachi\Winter\Ppa\Entity\RepositoryInterface;
use Flytachi\Winter\Ppa\Mapping\RepositoryMappingInterface;
use Flytachi\Winter\Ppa\Pool\PpaConnectionPool;
use Flytachi\Winter\Base\Runtime;
use PDOStatement;
use ReflectionClass;
use ReflectionProperty;
use stdClass;
use Swoole\Coroutine;
use Throwable;
use ValueError;
use WeakMap;

/**
 * Abstract base class for all repository implementations.
 *
 * Provides a fluent SQL query builder that follows SQL clause order:
 * `WITH [RECURSIVE]` → `SELECT` → `FROM` → alias → `JOIN` → `WHERE` →
 * `GROUP BY` → `HAVING` → `UNION` → `ORDER BY` → `LIMIT / OFFSET` → `FOR`.
 *
 * Subclasses must define {@see $dbConfigClassName} and {@see $table}.
 * Optionally override {@see $entityClassName} for typed result hydration,
 * and {@see $schema} to pin a specific database schema.
 *
 * Typical usage via stereotype:
 * ```
 * class UserRepository extends RepositoryCrud
 * {
 *     protected string $dbConfigClassName = DbConfig::class;
 *     protected string $entityClassName   = UserEntity::class;
 *     public static string $table         = 'users';
 * }
 *
 * $users = UserRepository::instance('u')
 *     ->joinLeft('orders o', 'u.id = o.user_id')
 *     ->where(Qb::eq('u.status', 'active'))
 *     ->orderBy('u.id DESC')
 *     ->limit(20)
 *     ->findAll();
 * ```
 *
 * `TEntity` is the entity class declared by a concrete repository via
 * {@see $entityClassName}. Subclasses bind it through an `@extends` PHPDoc tag
 * pinning the template parameter — see {@see \Flytachi\Winter\Ppa\Stereotype\Repository}
 * for details. When unbound, `TEntity` defaults to {@see stdClass}.
 *
 * @template TEntity of object
 * @see RepositoryCrudTrait  for INSERT / UPDATE / DELETE / UPSERT operations
 * @see RepositoryViewTrait  for SELECT / find / count / exists operations
 *
 * @link https://winterframe.net/docs/repository Repositories: assembling a query
 */
abstract class RepositoryCore implements RepositoryInterface, RepositoryMappingInterface
{
    /** @var class-string $dbConfigClassName dbConfig class name (default => DbConfig::class) */
    protected string $dbConfigClassName;
    /** @var class-string<TEntity> $entityClassName object class name (default => \stdClass::class) */
    protected string $entityClassName = stdClass::class;
    /** @var string|null $schema schema in database */
    protected ?string $schema = null;
    /** @var string $table name of the table in the database */
    public static string $table = '';
    /** @var array $sqlParts sql parameters (FPM backing store; Swoole uses per-coroutine state) */
    protected array $sqlParts = [];
    /** Coroutine-context key under which the per-coroutine {@see WeakMap} of states lives. */
    private const string STATE_CONTEXT_KEY = '__rp_states';
    /**
     * This repository's key in the per-coroutine state map — a private object rather than
     * `$this`, so a clone can find the state it was copied from: {@see __clone()} still
     * sees the original's key in this property before it takes one of its own.
     */
    private ?object $stateKey = null;
    /** Parts that make a repository a query rather than a bare table (all but alias and binds). */
    private const array QUERY_PARTS = [
        'option', 'from', 'join', 'where', 'group', 'having', 'union', 'order', 'limit', 'offset', 'for', 'with',
    ];

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    public function __construct()
    {
        if (!isset($this->dbConfigClassName)) {
            RepositoryException::throw(static::class . ' $dbConfigClassName must be set by the child class');
        }
        $config = PpaConnectionPool::getConfigDb($this->dbConfigClassName);
        if ($this->schema == null) {
            $this->schema = $config->getSchema();
        }
    }

    /**
     * Creates and returns a new repository instance, optionally with a table alias.
     *
     * Deliberately a fresh object rather than a container lookup: the alias lives in
     * per-object state, so joining one table twice needs two distinct handles. Resolving
     * this through the container would return the shared instance for a `#[Singleton]`
     * repository, and the second alias would silently overwrite the first.
     *
     * The container still fills `#[Autowired]` / `#[Inject]` properties, so a repository
     * with dependencies behaves the same however it was obtained. Injection is skipped
     * when no container exists — PPA is usable from a bare script, and that path simply
     * has nothing to inject, exactly as before this was added.
     *
     * @param string|null $as Optional table alias — calls {@see as()} before returning
     * @return static
     *
     * @link https://winterframe.net/docs/repository#instance Getting an instance
     */
    public static function instance(?string $as = null): static
    {
        $repository = new static();

        if (self::hasInjectableProperties(static::class) && Container::isInitialized()) {
            Container::getInstance()->inject($repository);
        }

        if (!empty($as)) {
            $repository->as($as);
        }
        return $repository;
    }

    /**
     * Whether a repository class declares anything for the container to fill.
     *
     * Answered once per class and remembered, because {@see instance()} runs on every
     * join of every request while the answer is almost always no — a repository normally
     * carries a config class name and nothing else. Asking the container regardless cost
     * roughly 2 µs per call, which is the wrong price for a rarely used capability.
     *
     * @param class-string $class
     */
    private static function hasInjectableProperties(string $class): bool
    {
        static $known = [];

        return $known[$class] ??= array_any(
            new ReflectionClass($class)->getProperties(),
            static fn(ReflectionProperty $property): bool =>
                $property->getAttributes(Autowired::class) !== []
                || $property->getAttributes(Inject::class) !== [],
        );
    }

    // -------------------------------------------------------------------------
    // Coroutine-safe state
    // -------------------------------------------------------------------------

    /**
     * Returns the per-coroutine mutable state object.
     *
     * **FPM** (no active coroutine): returns `$this` directly, so that
     * `$this->state()->sqlParts` is identical to `$this->sqlParts` — zero
     * overhead and identical semantics to the original code.
     *
     * **Swoole coroutine**: returns a `stdClass` held in a {@see WeakMap} that lives in the
     * current coroutine's context and is keyed by this repository object. Each coroutine
     * gets its own isolated copy of `sqlParts` and `entityClassName`, preventing
     * cross-coroutine state corruption when the DI container reuses the same Repository
     * singleton across concurrent requests.
     *
     * The map must be keyed by an object, never by `spl_object_id()`: PHP recycles an
     * object id the moment the object is freed, and repositories are overwhelmingly
     * short-lived temporaries (`Repo::instance('c')` handed straight to `joinLeft()`).
     * Keying by id let the next repository to land on that slot inherit the dead one's
     * alias, SELECT, WHERE and entity class. Weak keys also drop each state as soon as its
     * repository is collected, instead of holding every state until the coroutine ends.
     * The key is the repository's own {@see $stateKey} object, which lives exactly as long
     * as the repository and lets {@see __clone()} find the state to copy.
     */
    protected function state(): object
    {
        if (!Runtime::isSwooleCoroutine()) {
            return $this;
        }

        $ctx = Coroutine::getContext();
        $states = $ctx[self::STATE_CONTEXT_KEY] ?? null;
        if (!$states instanceof WeakMap) {
            $states = new WeakMap();
            $ctx[self::STATE_CONTEXT_KEY] = $states;
        }

        $key = $this->stateKey ??= new stdClass();
        if (!isset($states[$key])) {
            $state                  = new stdClass();
            $state->sqlParts        = [];
            $state->entityClassName = $this->entityClassName;
            $states[$key]           = $state;
        }

        return $states[$key];
    }

    /**
     * Gives a clone the same query as its original — inside a coroutine too.
     *
     * Outside a coroutine the state is this object's own properties, and PHP's `clone`
     * copies them. Inside one it lives in the coroutine's state map instead, so without
     * this a clone came out with an empty query: `clone $base` of a repository filtered by
     * user read every row of the table, under Swoole only. The clone takes a key of its own
     * and a copy of the original's state — the same depth PHP's `clone` gives the
     * properties — so from here on the two are independent.
     *
     * A subclass that defines `__clone()` must call `parent::__clone()`.
     */
    public function __clone(): void
    {
        $originalKey    = $this->stateKey;
        $this->stateKey = null;
        if ($originalKey === null || !Runtime::isSwooleCoroutine()) {
            return;
        }

        $states = Coroutine::getContext()[self::STATE_CONTEXT_KEY] ?? null;
        if ($states instanceof WeakMap && isset($states[$originalKey])) {
            $this->stateKey         = new stdClass();
            $states[$this->stateKey] = clone $states[$originalKey];
        }
    }

    // -------------------------------------------------------------------------
    // Getters
    // -------------------------------------------------------------------------

    /**
     * Returns the database configuration class name bound to this repository.
     *
     * @return class-string
     */
    final public function getDbConfigClassName(): string
    {
        return $this->dbConfigClassName;
    }

    /**
     * Returns the entity class name used for hydrating query results.
     *
     * When a custom {@see select()} is active, the effective hydration target
     * is {@see stdClass} regardless of the configured entity — a custom SELECT
     * returns arbitrary columns that may not match the entity's shape.
     *
     * Read-only: the configured `$entityClassName` is never mutated.
     *
     * @return class-string<TEntity>|class-string<stdClass>
     */
    final public function getEntityClassName(): string
    {
        $state = $this->state();
        return isset($state->sqlParts['option']) ? stdClass::class : $state->entityClassName;
    }

    /**
     * @return CDO
     *
     * @link https://winterframe.net/docs/repository#db The connection the repository works on
     */
    public function db(): CDO
    {
        return PpaConnectionPool::db($this->dbConfigClassName);
    }

    /**
     * @return string|null
     */
    public function getSchema(): ?string
    {
        return $this->schema;
    }

    /**
     * @return string
     */
    public function originTable(): string
    {
        if (empty(static::$table)) {
            return '';
        }
        return (($this->schema) ? $this->schema . '.' : '') . static::$table;
    }

    // -------------------------------------------------------------------------
    // SQL management
    // -------------------------------------------------------------------------

    /**
     * Assembles the full SQL query string from accumulated parts.
     *
     * @param string[] $ignoreParts SQL part keys to skip during assembly
     *                              (e.g. `['order', 'limit', 'offset', 'for']`)
     * @throws RepositoryException
     *
     * @link https://winterframe.net/docs/repository#buildsql Inspecting the query
     */
    public function buildSql(array $ignoreParts = []): string
    {
        try {
            $state = $this->state();
            $skip = array_flip($ignoreParts);
            $parts = ['SELECT ' . $this->prepareSelect()];
            if (!empty(($state->sqlParts['from'] ?? $this->originTable()))) {
                $parts[] = 'FROM ' . ($state->sqlParts['from'] ?? $this->originTable());
            }

            foreach (['as', 'join', 'where', 'group', 'having', 'union', 'order'] as $key) {
                if (isset($state->sqlParts[$key]) && !isset($skip[$key])) {
                    $parts[] = trim($state->sqlParts[$key]);
                }
            }
            if (isset($state->sqlParts['limit']) && !isset($skip['limit'])) {
                $parts[] = 'LIMIT ' . $state->sqlParts['limit'];
            }
            if (isset($state->sqlParts['offset']) && !isset($skip['offset'])) {
                $parts[] = 'OFFSET ' . $state->sqlParts['offset'];
            }
            if (isset($state->sqlParts['for']) && !isset($skip['for'])) {
                $parts[] = 'FOR ' . $state->sqlParts['for'];
            }

            if (isset($state->sqlParts['with'])) {
                $withKeyword = isset($state->sqlParts['with_recursive']) ? 'WITH RECURSIVE' : 'WITH';
                array_unshift($parts, $withKeyword . ' ' . $state->sqlParts['with']);
            }

            return implode(' ', $parts);
        } catch (Throwable $th) {
            throw new RepositoryException($th->getMessage(), previous: $th);
        }
    }

    /**
     * Returns a specific SQL part by key, or the full built SQL when $param is null.
     *
     * @param string|null $param Part key (e.g. `'where'`, `'order'`, `'binds'`), or null for full SQL
     * @return mixed SQL part value, or full SQL string
     * @throws RepositoryException
     *
     * @link https://winterframe.net/docs/repository#getsql Inspecting one part
     */
    final public function getSql(?string $param = null): mixed
    {
        if ($param) {
            $state = $this->state();
            return $state->sqlParts[$param] ?? null;
        } else {
            return $this->buildSql();
        }
    }

    /**
     * Returns the number of accumulated SQL parts.
     *
     * Used internally when composing JOIN subqueries to decide whether
     * a sibling repository needs to be rendered as a subquery.
     *
     * @link https://winterframe.net/docs/repository#sqlpartscount Resetting the query
     */
    final public function sqlPartsCount(): int
    {
        return count($this->state()->sqlParts);
    }

    /**
     * Clears one specific SQL part (by key) or all accumulated SQL parts.
     *
     * In Swoole coroutine mode a full reset (`$param === null`) discards the
     * entire per-coroutine state object as the next {@see state()} call
     * re-initialises it from the class-defined defaults — including
     * `entityClassName`.
     *
     * @param string|null $param Part key to remove (e.g. `'where'`, `'order'`), or null to reset all
     * @return void
     *
     * @link https://winterframe.net/docs/repository#cleancache Resetting the query
     */
    final public function cleanCache(?string $param = null): void
    {
        if (Runtime::isSwooleCoroutine()) {
            $states = Coroutine::getContext()[self::STATE_CONTEXT_KEY] ?? null;
            $key    = $this->stateKey;
            if (!$states instanceof WeakMap || $key === null || !isset($states[$key])) {
                return;
            }
            if ($param) {
                $this->dropBindsOf($states[$key], $param);
                unset($states[$key]->sqlParts[$param]);
            } else {
                unset($states[$key]); // full reset: re-init from defaults on next state() call
            }
        } else {
            if ($param) {
                $this->dropBindsOf($this, $param);
                if (isset($this->sqlParts[$param])) {
                    unset($this->sqlParts[$param]);
                }
            } else {
                $this->sqlParts = [];
            }
        }
    }

    /**
     * Removes the binds a part's SQL refers to — before the part itself is replaced or
     * removed. Kept otherwise, they outlive the condition they were made for, and a
     * parameter the statement does not contain is an error on execute.
     *
     * A bind belongs to the part when its placeholder appears there and nowhere else —
     * generated names are unique, but a hand-named one may be shared with a join or a CTE,
     * and then it stays.
     */
    private function dropBindsOf(object $state, string $part): void
    {
        $sql = $state->sqlParts[$part] ?? null;
        if (!is_string($sql) || empty($state->sqlParts['binds'])) {
            return;
        }
        $elsewhere = '';
        foreach ($state->sqlParts as $key => $value) {
            if ($key !== $part && $key !== 'binds' && is_string($value)) {
                $elsewhere .= ' ' . $value;
            }
        }
        foreach (array_keys($state->sqlParts['binds']) as $name) {
            $pattern = '/' . preg_quote(':' . ltrim((string) $name, ':'), '/') . '(?![A-Za-z0-9_])/';
            if (preg_match($pattern, $sql) && !preg_match($pattern, $elsewhere)) {
                unset($state->sqlParts['binds'][$name]);
            }
        }
    }

    /**
     * Resets the query, then throws: a half-built query cannot be run, and kept it would
     * be picked up by the next call on this repository — for the rest of the process, for
     * a long-lived one.
     */
    private function refuse(Throwable $error): never
    {
        $this->cleanCache();
        throw $error;
    }

    // -------------------------------------------------------------------------
    // Query building — WITH
    // -------------------------------------------------------------------------

    /**
     * @param string $name
     * @param RepositoryInterface $repository
     * @param string|null $modifier e.g. 'MATERIALIZED', 'NOT MATERIALIZED'
     * @return static
     *
     * @link https://winterframe.net/docs/repository#with-withrecursive CTEs
     */
    final public function with(string $name, RepositoryInterface $repository, ?string $modifier = null): static
    {
        $state = $this->state();
        try {
            $sql = $repository->buildSql();
        } catch (Throwable $e) {
            $this->refuse($e);
        }
        $this->binding($repository->getSql('binds'));
        $cte = $modifier !== null
            ? $name . ' AS ' . $modifier . ' (' . $sql . ')'
            : $name . ' AS (' . $sql . ')';

        if (isset($state->sqlParts['with'])) {
            $state->sqlParts['with'] .= ', ' . $cte;
        } else {
            $state->sqlParts['with'] = $cte;
        }
        return $this;
    }

    /**
     * @param string $name
     * @param RepositoryInterface $repository
     * @return static
     *
     * @link https://winterframe.net/docs/repository#with-withrecursive Recursive CTEs
     */
    final public function withRecursive(string $name, RepositoryInterface $repository): static
    {
        $this->state()->sqlParts['with_recursive'] = true;
        return $this->with($name, $repository);
    }

    // -------------------------------------------------------------------------
    // Query building — SELECT
    // -------------------------------------------------------------------------

    /**
     * @param string $option
     * @return static
     *
     * @link https://winterframe.net/docs/repository#select Choosing columns
     */
    final public function select(string $option): static
    {
        if (!empty($option)) {
            $this->state()->sqlParts['option'] = $option;
        }
        return $this;
    }

    /**
     * Builds the SELECT-list expression for the current query.
     *
     * Resolution order:
     *  1. Custom `select()` (`sqlParts['option']`) — returned as-is.
     *  2. `$state->entityClassName` — the repository-configured entity.
     *
     * Pure read of state: no mutation. Per-call hydration overrides (e.g.
     * `find(OtherEntity::class)`) do **not** affect the SELECT list; they
     * only change the hydration target. To select a different column set,
     * call {@see select()} explicitly.
     */
    private function prepareSelect(): string
    {
        $state = $this->state();
        if (isset($state->sqlParts['option'])) {
            return $state->sqlParts['option'];
        }
        $entity = $state->entityClassName;
        if ($entity === stdClass::class || is_subclass_of($entity, stdClass::class)) {
            return '*';
        }
        $prefix = isset($state->sqlParts['as']) ? $state->sqlParts['as'] . '.' : '';
        $values = [];
        $selection = [];
        if (is_subclass_of($entity, EntityInterface::class)) {
            $selection = $entity::selection();
        }
        foreach (get_class_vars($entity) as $name => $val) {
            $values[] = $selection[$name] ?? ($prefix . $name);
        }
        return implode(', ', $values);
    }

    // -------------------------------------------------------------------------
    // Query building — FROM
    // -------------------------------------------------------------------------

    /**
     * @param string|RepositoryInterface $repository
     * @return static
     *
     * @link https://winterframe.net/docs/repository#from A subquery as the source
     */
    final public function from(string|RepositoryInterface $repository): static
    {
        $state = $this->state();
        if (isset($state->sqlParts['from'])) {
            $this->refuse(new RepositoryException('FROM clause already set: only one FROM source is allowed'));
        }
        if (is_string($repository)) {
            $state->sqlParts['from'] = $repository;
        } else {
            if (!isset($state->sqlParts['as'])) {
                $this->refuse(new RepositoryException('FROM subquery requires an alias: call ->as() before ->from()'));
            }
            try {
                $sql = $repository->getSql();
            } catch (Throwable $e) {
                $this->refuse($e);
            }
            $this->binding($repository->getSql('binds'));
            $state->sqlParts['from'] = '(' . $sql . ')';
        }
        return $this;
    }

    // -------------------------------------------------------------------------
    // Query building — AS (alias)
    // -------------------------------------------------------------------------

    /**
     * @param string $alias
     * @return static
     *
     * @link https://winterframe.net/docs/repository#as Setting the alias
     */
    final public function as(string $alias): static
    {
        if (!empty($alias)) {
            $this->state()->sqlParts['as'] = $alias;
        }
        return $this;
    }

    // -------------------------------------------------------------------------
    // Query building — JOIN
    // -------------------------------------------------------------------------

    private function joinedContext(string|RepositoryInterface $repository, string|Qb $on): string
    {
        if ($on instanceof Qb) {
            $this->binding($on->getBinds());
            $onSql = $on->getQuery();
        } else {
            $onSql = $on;
        }
        if (is_string($repository)) {
            return $repository . " ON(" . $onSql . ")";
        }
        return $this->joinSource($repository) . " ON(" . $onSql . ")";
    }

    /**
     * What a joined repository becomes in the JOIN: its table (with its alias, if any)
     * when it holds no query of its own, otherwise its whole query as an aliased subquery,
     * whose binds join this repository's.
     *
     * The choice is made on what the repository holds — anything besides an alias — and
     * not on how many parts it has: it used to be "more than one part", with binds counting
     * as a part, so a WHERE without parameters (`IS NULL`), a select() or a limit() on an
     * un-aliased repository was a single part and silently dropped, and a WHERE with a
     * parameter became a subquery with no alias, which PostgreSQL and MySQL reject. A
     * subquery needs an alias the ON clause can refer to, and only the caller knows it, so
     * a query without one is refused.
     *
     * @throws RepositoryException When the repository holds a query but has no alias.
     */
    private function joinSource(RepositoryInterface $repository): string
    {
        $alias = $repository->getSql('as');
        $query = false;
        foreach (self::QUERY_PARTS as $part) {
            if ($repository->getSql($part) !== null) {
                $query = true;
                break;
            }
        }

        if (!$query) {
            return $repository->originTable() . ($alias !== null ? ' ' . $alias : '');
        }
        if ($alias === null) {
            $this->refuse(new RepositoryException(
                $repository::class . ' joined with a query of its own (conditions, select, order, limit…) becomes'
                . ' a subquery, and a subquery needs an alias the ON clause can refer to — name it:'
                . " instance('o') or ->as('o')."
            ));
        }

        // The subquery is built before its binds are taken: a build that fails must not
        // leave them behind as parameters of a statement that never got its subquery.
        try {
            $sql = $repository->getSql();
        } catch (Throwable $e) {
            $this->refuse($e);
        }
        $this->binding($repository->getSql('binds'));
        return '(' . $sql . ') ' . $alias;
    }

    /**
     * @param string|RepositoryInterface $repository
     * @return static
     *
     * @link https://winterframe.net/docs/repository#join-joininner-joinleft-joinright-joincross Joins
     */
    final public function joinCross(string|RepositoryInterface $repository): static
    {
        if (!is_string($repository)) {
            $repository = $this->joinSource($repository);
        }
        $state = $this->state();
        if (isset($state->sqlParts['join'])) {
            $state->sqlParts['join'] .= ' CROSS JOIN ' . $repository;
        } else {
            $state->sqlParts['join'] = 'CROSS JOIN ' . $repository;
        }
        return $this;
    }

    /**
     * @param string|RepositoryInterface $repository
     * @param string|Qb $on
     * @return static
     *
     * @link https://winterframe.net/docs/repository#join-joininner-joinleft-joinright-joincross Joins
     */
    final public function join(string|RepositoryInterface $repository, string|Qb $on): static
    {
        $state = $this->state();
        if (isset($state->sqlParts['join'])) {
            $state->sqlParts['join'] .= ' JOIN ' . $this->joinedContext($repository, $on);
        } else {
            $state->sqlParts['join'] = 'JOIN ' . $this->joinedContext($repository, $on);
        }
        return $this;
    }

    /**
     * @param string|RepositoryInterface $repository
     * @param string|Qb $on
     * @return static
     *
     * @link https://winterframe.net/docs/repository#join-joininner-joinleft-joinright-joincross Joins
     */
    final public function joinInner(string|RepositoryInterface $repository, string|Qb $on): static
    {
        $state = $this->state();
        if (isset($state->sqlParts['join'])) {
            $state->sqlParts['join'] .= ' INNER JOIN ' . $this->joinedContext($repository, $on);
        } else {
            $state->sqlParts['join'] = 'INNER JOIN ' . $this->joinedContext($repository, $on);
        }
        return $this;
    }

    /**
     * @param string|RepositoryInterface $repository
     * @param string|Qb $on
     * @return static
     *
     * @link https://winterframe.net/docs/repository#join-joininner-joinleft-joinright-joincross Joins
     */
    final public function joinLeft(string|RepositoryInterface $repository, string|Qb $on): static
    {
        $state = $this->state();
        if (isset($state->sqlParts['join'])) {
            $state->sqlParts['join'] .= ' LEFT JOIN ' . $this->joinedContext($repository, $on);
        } else {
            $state->sqlParts['join'] = 'LEFT JOIN ' . $this->joinedContext($repository, $on);
        }
        return $this;
    }

    /**
     * @param string|RepositoryInterface $repository
     * @param string|Qb $on
     * @return static
     *
     * @link https://winterframe.net/docs/repository#join-joininner-joinleft-joinright-joincross Joins
     */
    final public function joinRight(string|RepositoryInterface $repository, string|Qb $on): static
    {
        $state = $this->state();
        if (isset($state->sqlParts['join'])) {
            $state->sqlParts['join'] .= ' RIGHT JOIN ' . $this->joinedContext($repository, $on);
        } else {
            $state->sqlParts['join'] = 'RIGHT JOIN ' . $this->joinedContext($repository, $on);
        }
        return $this;
    }

    // -------------------------------------------------------------------------
    // Query building — WHERE
    // -------------------------------------------------------------------------

    /**
     * @param null|Qb $qb
     * @return static
     *
     * @link https://winterframe.net/docs/repository#where-andwhere-orwhere-xorwhere Conditions
     */
    final public function where(?Qb $qb): static
    {
        if (!is_null($qb)) {
            if ($qb->getQuery()) {
                $state = $this->state();
                // Replacing the condition replaces its binds: left behind, the previous
                // condition's values are parameters the new statement does not have.
                $this->dropBindsOf($state, 'where');
                $state->sqlParts['where'] = 'WHERE ' . $qb->getQuery();
                $this->binding($qb->getBinds());
            }
        }
        return $this;
    }

    /**
     * Appends an `AND` condition to the existing `WHERE` clause.
     *
     * If no WHERE clause exists yet, it acts as {@see where()}.
     *
     * @param Qb $qb Condition builder
     * @return static
     *
     * @link https://winterframe.net/docs/repository#where-andwhere-orwhere-xorwhere Conditions
     */
    final public function andWhere(Qb $qb): static
    {
        return $this->addWhere($qb, 'AND');
    }

    /**
     * Appends an `OR` condition to the existing `WHERE` clause.
     *
     * If no WHERE clause exists yet, it acts as {@see where()}.
     *
     * @param Qb $qb Condition builder
     * @return static
     *
     * @link https://winterframe.net/docs/repository#where-andwhere-orwhere-xorwhere Conditions
     */
    final public function orWhere(Qb $qb): static
    {
        return $this->addWhere($qb, 'OR');
    }

    /**
     * Appends an ` XOR ` condition to the existing `WHERE` clause.
     *
     * If no WHERE clause exists yet, it acts as {@see where()}.
     *
     * @param Qb $qb Condition builder
     * @return static
     *
     * @link https://winterframe.net/docs/repository#where-andwhere-orwhere-xorwhere Conditions
     */
    final public function xorWhere(Qb $qb): static
    {
        return $this->addWhere($qb, 'XOR');
    }

    private function addWhere(Qb $qb, string $operator): static
    {
        if ($qb->getQuery()) {
            $state = $this->state();
            if (empty($state->sqlParts['where'])) {
                $state->sqlParts['where'] = 'WHERE ' . $qb->getQuery();
            } else {
                $state->sqlParts['where'] .= " $operator " . $qb->getQuery();
            }
            $this->binding($qb->getBinds());
        }
        return $this;
    }

    // -------------------------------------------------------------------------
    // Query building — GROUP BY / HAVING
    // -------------------------------------------------------------------------

    /**
     * @param string $context
     * @return static
     *
     * @link https://winterframe.net/docs/repository#groupby Grouping and order
     */
    final public function groupBy(string $context): static
    {
        if (!empty($context)) {
            $this->state()->sqlParts['group'] = 'GROUP BY ' . $context;
        }
        return $this;
    }

    /**
     * @param string $context
     * @return static
     *
     * @link https://winterframe.net/docs/repository#having Grouping and order
     */
    final public function having(string $context): static
    {
        if (!empty($context)) {
            $this->state()->sqlParts['having'] = 'HAVING ' . $context;
        }
        return $this;
    }

    // -------------------------------------------------------------------------
    // Query building — UNION
    // -------------------------------------------------------------------------

    /**
     * @param RepositoryInterface $repository
     * @return static
     *
     * @link https://winterframe.net/docs/repository#union-unionall Unions
     */
    final public function union(RepositoryInterface $repository): static
    {
        return $this->addUnion($repository, 'UNION');
    }

    /**
     * @param RepositoryInterface $repository
     * @return static
     *
     * @link https://winterframe.net/docs/repository#union-unionall Unions
     */
    final public function unionAll(RepositoryInterface $repository): static
    {
        return $this->addUnion($repository, 'UNION ALL');
    }

    private function addUnion(RepositoryInterface $repository, string $keyword): static
    {
        $state = $this->state();
        try {
            $sql = $repository->buildSql();
        } catch (Throwable $e) {
            $this->refuse($e);
        }
        $this->binding($repository->getSql('binds'));
        $unionPart = $keyword . ' ' . $sql;

        if (isset($state->sqlParts['union'])) {
            $state->sqlParts['union'] .= ' ' . $unionPart;
        } else {
            $state->sqlParts['union'] = $unionPart;
        }
        return $this;
    }

    // -------------------------------------------------------------------------
    // Query building — ORDER BY / LIMIT / FOR
    // -------------------------------------------------------------------------

    /**
     * @param string $context
     * @return static
     *
     * @link https://winterframe.net/docs/repository#orderby Grouping and order
     */
    final public function orderBy(string $context): static
    {
        if (!empty($context)) {
            $this->state()->sqlParts['order'] = 'ORDER BY ' . $context;
        }
        return $this;
    }

    /**
     * @param int $limit
     * @param int $offset
     * @return static
     *
     * @link https://winterframe.net/docs/repository#limit Limit and offset
     */
    final public function limit(int $limit, int $offset = 0): static
    {
        if ($limit < 1) {
            $this->refuse(new ValueError("LIMIT must be a positive integer (>= 1), got: $limit."));
        }
        if ($offset < 0) {
            $this->refuse(new ValueError("OFFSET must be a non-negative integer (>= 0), got: $offset."));
        }
        $state = $this->state();
        $state->sqlParts['limit'] = $limit;
        if ($offset > 0) {
            $state->sqlParts['offset'] = $offset;
        } else {
            unset($state->sqlParts['offset']); // limit(10) after limit(10, 20) starts from the top
        }
        return $this;
    }

    /**
     * @param string $context
     * @return static
     *
     * @link https://winterframe.net/docs/repository#forby Row locking
     */
    final public function forBy(string $context): static
    {
        $this->state()->sqlParts['for'] = $context;
        return $this;
    }

    // -------------------------------------------------------------------------
    // Binds management
    // -------------------------------------------------------------------------

    /**
     * Merges an array of bind parameters into the accumulated binds for this query.
     *
     * Called internally by `where()`, `join*()`, `with()`, `union*()`, and `from()`
     * to collect all `CDOBind` objects before execution. Can also be called directly
     * to attach custom binds when composing raw SQL fragments.
     *
     * Passing null or an empty array is a safe no-op.
     *
     * @param CDOBind[]|null $binds Array of {@see CDOBind} objects to merge, or null
     * @return static
     *
     * @link https://winterframe.net/docs/repository#binding Adding binds by hand
     */
    final public function binding(?array $binds): static
    {
        if (empty($binds)) {
            return $this;
        }
        $state = $this->state();
        foreach ($binds as $bind) {
            $state->sqlParts['binds'][$bind->getName()] = $bind;
        }
        return $this;
    }

    /**
     * Binds all accumulated parameters to a prepared statement before execution.
     *
     * Uses `bindTypedValue()` when available (CDOStatement), otherwise falls back
     * to `bindValue()` (PDOStatement). Called internally by all fetch methods in
     * {@see RepositoryViewTrait} immediately after `prepare()`.
     *
     * @param CDOStatement|PDOStatement $stmt Prepared statement to bind values onto
     * @return void
     */
    final protected function useBind(CDOStatement|PDOStatement $stmt): void
    {
        $state = $this->state();
        if (empty($state->sqlParts['binds'])) {
            return;
        }
        $method = method_exists($stmt, 'bindTypedValue') ? 'bindTypedValue' : 'bindValue';
        foreach ($state->sqlParts['binds'] as $bind) {
            $stmt->{$method}($bind->getName(), $bind->getValue());
        }
    }

    // -------------------------------------------------------------------------
    // Mapping
    // -------------------------------------------------------------------------

    public function mapIdentifierColumnName(): string
    {
        return 'id';
    }
}
