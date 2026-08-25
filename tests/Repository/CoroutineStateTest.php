<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Tests\Repository;

use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\Ppa\Tests\Repository\Fixtures\OrdersRepo;
use Flytachi\Winter\Ppa\Tests\Repository\Fixtures\TypedUsersRepo;
use Flytachi\Winter\Ppa\Tests\Repository\Fixtures\UsersRepo;
use PHPUnit\Framework\TestCase;
use WeakMap;

/**
 * Per-coroutine repository state: it must belong to the repository *object*, not to a
 * number that PHP hands out again.
 *
 * Repositories are overwhelmingly short-lived temporaries — `Repo::instance('c')` passed
 * straight into `joinLeft()` is dropped as soon as the join string is built. `spl_object_id()`
 * recycles that object's id immediately, so keying the coroutine's state store by the id let
 * the next repository to land on the slot inherit the dead one's alias, SELECT, WHERE and
 * entity class, while `FROM` still came from its own class. The result was a query that
 * looked plausible and read the wrong columns.
 */
final class CoroutineStateTest extends TestCase
{
    private const string STATE_CONTEXT_KEY = '__rp_states';

    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('the coroutine path needs Swoole.');
        }
    }

    /** Runs $fn inside a fresh coroutine and returns whatever it produced. */
    private static function inCoroutine(callable $fn): mixed
    {
        $out = null;
        \Swoole\Coroutine\run(static function () use ($fn, &$out): void {
            $out = $fn();
        });

        return $out;
    }

    /** Bind placeholders carry a process-wide counter; the tests care about shape, not its value. */
    private static function norm(string $sql): string
    {
        return preg_replace('/:iqb\d+/', ':v', $sql);
    }

    private static function stateMap(): ?WeakMap
    {
        $map = \Swoole\Coroutine::getContext()[self::STATE_CONTEXT_KEY] ?? null;

        return $map instanceof WeakMap ? $map : null;
    }

    // ── the regression ──────────────────────────────────────────────────────

    public function test_a_repository_does_not_inherit_the_state_of_a_dead_one_on_a_recycled_id(): void
    {
        $out = self::inCoroutine(static function (): array {
            // 1. a typed repo claims an object id and seeds its state
            $first = TypedUsersRepo::instance();
            $first->getSql();
            $idFirst = spl_object_id($first);
            unset($first);

            // 2. a joined repo with an alias — the shape of Repo::instance('c') inside joinLeft()
            $joined = TypedUsersRepo::instance('c');
            $joined->getSql();
            $idJoined = spl_object_id($joined);
            unset($joined);

            // 3. an unrelated repository, no alias, no select
            $fresh = OrdersRepo::instance()->where(Qb::eq('staff_id', 42));

            return [
                'ids' => [$idFirst, $idJoined, spl_object_id($fresh)],
                'sql' => $fresh->getSql(),
            ];
        });

        // The ids really are recycled — otherwise this test proves nothing.
        self::assertSame(
            [$out['ids'][0], $out['ids'][0], $out['ids'][0]],
            $out['ids'],
            'the scenario needs all three objects to land on the same recycled id',
        );

        self::assertSame('SELECT * FROM orders WHERE staff_id = :v', self::norm($out['sql']));
        self::assertStringNotContainsString(' c.', $out['sql'], 'inherited a dead repository alias');
        self::assertStringNotContainsString('email', $out['sql'], 'inherited a dead repository entity');
    }

    public function test_a_recycled_id_does_not_inherit_a_dead_where_clause(): void
    {
        $sql = self::inCoroutine(static function (): string {
            $dead = UsersRepo::instance()->where(Qb::eq('tenant_id', 7));
            $dead->getSql();
            unset($dead);

            // andWhere() appends to whatever WHERE is already in the state
            return OrdersRepo::instance()->andWhere(Qb::eq('id', 1))->getSql();
        });

        self::assertSame('SELECT * FROM orders WHERE id = :v', self::norm($sql));
        self::assertStringNotContainsString('tenant_id', $sql, 'inherited a dead repository predicate');
    }

    // ── what the keying must keep doing ─────────────────────────────────────

    public function test_state_stays_isolated_between_coroutines(): void
    {
        $out = self::inCoroutine(static function (): array {
            $shared = UsersRepo::instance();   // one object, as a DI singleton would be
            $seen = [];

            foreach (['a' => 1, 'b' => 2] as $label => $id) {
                \Swoole\Coroutine::create(static function () use ($shared, $label, $id, &$seen): void {
                    $seen[$label] = $shared->where(Qb::eq('id', $id))->getSql();
                });
            }

            return $seen;
        });

        self::assertStringContainsString('WHERE id = :iqb', $out['a']);
        self::assertStringContainsString('WHERE id = :iqb', $out['b']);
        self::assertSame(
            1,
            substr_count($out['b'], 'WHERE'),
            'a sibling coroutine leaked its WHERE into this one',
        );
    }

    public function test_two_live_repositories_keep_separate_states(): void
    {
        $out = self::inCoroutine(static function (): array {
            $left = UsersRepo::instance('l')->where(Qb::eq('id', 1));
            $right = OrdersRepo::instance('r')->where(Qb::eq('id', 2));

            return [$left->getSql(), $right->getSql()];
        });

        self::assertSame('SELECT * FROM users l WHERE id = :v', self::norm($out[0]));
        self::assertSame('SELECT * FROM orders r WHERE id = :v', self::norm($out[1]));
    }

    public function test_state_is_released_as_soon_as_the_repository_is_collected(): void
    {
        $counts = self::inCoroutine(static function (): array {
            $repo = UsersRepo::instance();
            $repo->getSql();
            $held = count(self::stateMap());

            unset($repo);

            return ['held' => $held, 'afterRelease' => count(self::stateMap())];
        });

        self::assertSame(1, $counts['held']);
        self::assertSame(0, $counts['afterRelease'], 'the state outlived its repository');
    }

    // ── cleanCache() over the new store ─────────────────────────────────────

    public function test_clean_cache_with_a_part_removes_only_that_part(): void
    {
        $sql = self::inCoroutine(static function (): string {
            $repo = UsersRepo::instance('u')->where(Qb::eq('id', 1))->orderBy('u.id DESC');
            $repo->cleanCache('order');

            return $repo->getSql();
        });

        self::assertSame('SELECT * FROM users u WHERE id = :v', self::norm($sql));
    }

    public function test_clean_cache_full_reset_reinitialises_from_class_defaults(): void
    {
        $out = self::inCoroutine(static function (): array {
            $repo = TypedUsersRepo::instance('u')->select('u.id')->where(Qb::eq('id', 1));
            $before = $repo->getSql();
            $repo->cleanCache();

            return ['before' => $before, 'after' => $repo->getSql()];
        });

        self::assertSame('SELECT u.id FROM users u WHERE id = :v', self::norm($out['before']));
        self::assertSame('SELECT id, email, name FROM users', $out['after']);
    }

    public function test_clean_cache_on_an_untouched_repository_is_a_no_op(): void
    {
        $sql = self::inCoroutine(static function (): string {
            $repo = UsersRepo::instance();
            $repo->cleanCache();
            $repo->cleanCache('where');

            return $repo->getSql();
        });

        self::assertSame('SELECT * FROM users', $sql);
    }
}
