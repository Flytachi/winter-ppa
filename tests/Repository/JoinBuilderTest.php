<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Tests\Repository;

use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\Ppa\Tests\Repository\Fixtures\OrdersRepo;
use Flytachi\Winter\Ppa\Repository\RepositoryException;
use Flytachi\Winter\Ppa\Tests\Repository\Fixtures\UsersRepo;
use PHPUnit\Framework\TestCase;

final class JoinBuilderTest extends TestCase
{
    // ── String form — table+alias passed verbatim, ON wrapped in parens ─────

    public function test_join_left_string_form(): void
    {
        $sql = UsersRepo::instance('u')
            ->joinLeft('orders o', 'u.id = o.user_id')
            ->buildSql();
        self::assertSame('SELECT * FROM users u LEFT JOIN orders o ON(u.id = o.user_id)', $sql);
    }

    public function test_join_right_string_form(): void
    {
        $sql = UsersRepo::instance('u')
            ->joinRight('orders o', 'u.id = o.user_id')
            ->buildSql();
        self::assertStringContainsString('RIGHT JOIN orders o ON(u.id = o.user_id)', $sql);
    }

    public function test_join_inner_string_form(): void
    {
        $sql = UsersRepo::instance('u')
            ->joinInner('orders o', 'u.id = o.user_id')
            ->buildSql();
        self::assertStringContainsString('INNER JOIN orders o ON(u.id = o.user_id)', $sql);
    }

    public function test_plain_join_string_form(): void
    {
        $sql = UsersRepo::instance('u')
            ->join('orders o', 'u.id = o.user_id')
            ->buildSql();
        self::assertStringContainsString(' JOIN orders o ON(u.id = o.user_id)', $sql);
        self::assertStringNotContainsString('LEFT JOIN', $sql);
        self::assertStringNotContainsString('INNER JOIN', $sql);
    }

    // ── Cross join — no ON clause ────────────────────────────────────────────

    public function test_cross_join_string_form(): void
    {
        $sql = UsersRepo::instance('u')->joinCross('categories c')->buildSql();
        self::assertSame('SELECT * FROM users u CROSS JOIN categories c', $sql);
    }

    public function test_cross_join_with_repo_uses_originTable_plus_alias(): void
    {
        $sql = UsersRepo::instance('u')
            ->joinCross(OrdersRepo::instance('o'))
            ->buildSql();
        self::assertSame('SELECT * FROM users u CROSS JOIN orders o', $sql);
    }

    // ── Repo form — simple repo with only alias renders as originTable alias ─

    public function test_join_left_with_repo_renders_origin_table_when_no_other_parts(): void
    {
        $sql = UsersRepo::instance('u')
            ->joinLeft(OrdersRepo::instance('o'), 'u.id = o.user_id')
            ->buildSql();
        self::assertSame('SELECT * FROM users u LEFT JOIN orders o ON(u.id = o.user_id)', $sql);
    }

    // ── Repo form — repo with other parts (where/limit) becomes subquery ────

    public function test_join_with_filtered_repo_renders_as_subquery(): void
    {
        $subquery = OrdersRepo::instance('o')->where(Qb::eq('o.status', 'paid'));
        $sql = UsersRepo::instance('u')
            ->joinLeft($subquery, 'u.id = o.user_id')
            ->buildSql();
        // The aliased subquery is wrapped in parens, with the alias appearing
        // both inside the inner SELECT (as alias of orders) and after the parens.
        self::assertStringContainsString('LEFT JOIN (SELECT * FROM orders o WHERE o.status = :', $sql);
        self::assertStringContainsString(') o ON(u.id = o.user_id)', $sql);
    }

    // ── Multiple joins are chained ───────────────────────────────────────────

    public function test_multiple_joins_chained_in_call_order(): void
    {
        $sql = UsersRepo::instance('u')
            ->joinLeft('orders o', 'u.id = o.user_id')
            ->joinInner('payments p', 'p.order_id = o.id')
            ->buildSql();
        // Both LEFT JOIN and INNER JOIN appear, in chained order.
        self::assertStringContainsString('LEFT JOIN orders o ON(u.id = o.user_id) INNER JOIN payments p ON(p.order_id = o.id)', $sql);
    }

    // ── Qb-based ON propagates binds ────────────────────────────────────────

    public function test_join_with_Qb_on_propagates_binds(): void
    {
        $r = UsersRepo::instance('u')
            ->joinLeft('orders o', Qb::eq('o.user_id', 'u.id')); // contrived but exercises Qb path
        $sql = $r->buildSql();

        self::assertStringContainsString('LEFT JOIN orders o ON(o.user_id = :', $sql);

        $binds = $r->getSql('binds');
        self::assertIsArray($binds);
        self::assertNotEmpty($binds);
    }

    // ── a repository's query is joined whole, or the join is refused ─────────
    //
    // Whether a joined repository became a subquery used to depend on how many parts it
    // held (more than one), not on what they were — and binds count as a part. So a
    // WHERE without parameters (IS NULL), a select() or a limit() on an un-aliased
    // repository was one part and silently dropped: the join read the bare table. A WHERE
    // with a parameter made two parts and became a subquery with no alias, which
    // PostgreSQL and MySQL reject.

    private static function norm(string $sql): string
    {
        return preg_replace('/:iqb\d+/', ':v', $sql);
    }

    public function test_a_where_without_parameters_on_an_aliased_repository_is_kept(): void
    {
        $sql = UsersRepo::instance('u')
            ->joinLeft(OrdersRepo::instance('o')->where(Qb::isNull('o.deleted_at')), 'o.user_id = u.id')
            ->buildSql();

        self::assertSame(
            'SELECT * FROM users u LEFT JOIN (SELECT * FROM orders o WHERE o.deleted_at IS NULL) o ON(o.user_id = u.id)',
            $sql,
        );
    }

    public function test_a_select_on_an_aliased_repository_is_kept(): void
    {
        $sql = UsersRepo::instance('u')
            ->joinLeft(OrdersRepo::instance('o')->select('o.user_id'), 'o.user_id = u.id')
            ->buildSql();

        self::assertSame('SELECT * FROM users u LEFT JOIN (SELECT o.user_id FROM orders o) o ON(o.user_id = u.id)', $sql);
    }

    /** @return iterable<string, array{callable(): OrdersRepo}> */
    public static function unaliasedQueries(): iterable
    {
        yield 'where with a parameter' => [static fn() => OrdersRepo::instance()->where(Qb::gt('total', 100))];
        yield 'where without parameters' => [static fn() => OrdersRepo::instance()->where(Qb::isNull('deleted_at'))];
        yield 'select' => [static fn() => OrdersRepo::instance()->select('user_id')];
        yield 'limit' => [static fn() => OrdersRepo::instance()->limit(1)];
        yield 'order' => [static fn() => OrdersRepo::instance()->orderBy('id')];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unaliasedQueries')]
    public function test_a_query_without_an_alias_is_refused_instead_of_dropped(callable $make): void
    {
        try {
            UsersRepo::instance('u')->joinLeft($make(), 'o.user_id = u.id');
            self::fail('a joined query without an alias must be refused');
        } catch (RepositoryException $e) {
            self::assertStringContainsString('alias', $e->getMessage());
            self::assertStringContainsString(OrdersRepo::class, $e->getMessage());
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unaliasedQueries')]
    public function test_cross_join_refuses_a_query_without_an_alias_too(callable $make): void
    {
        $this->expectException(RepositoryException::class);
        UsersRepo::instance('u')->joinCross($make());
    }

    public function test_a_bare_repository_without_an_alias_still_joins_by_table_name(): void
    {
        $sql = UsersRepo::instance('u')->joinLeft(OrdersRepo::instance(), 'orders.user_id = u.id')->buildSql();

        self::assertSame('SELECT * FROM users u LEFT JOIN orders ON(orders.user_id = u.id)', $sql);
    }

    public function test_a_where_with_a_parameter_brings_its_bind_along(): void
    {
        $r = UsersRepo::instance('u')->joinLeft(OrdersRepo::instance('o')->where(Qb::gt('o.total', 100)), 'o.user_id = u.id');

        self::assertSame(
            'SELECT * FROM users u LEFT JOIN (SELECT * FROM orders o WHERE o.total > :v) o ON(o.user_id = u.id)',
            self::norm($r->buildSql()),
        );
        self::assertCount(1, $r->getSql('binds'));
    }
}
