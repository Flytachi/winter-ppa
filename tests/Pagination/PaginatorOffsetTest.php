<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Tests\Pagination;

use Flytachi\Winter\Ppa\Pagination\Paginator;
use Flytachi\Winter\Ppa\Tests\Repository\Fixtures\UsersRepo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ValueError;

/**
 * An offset is where a page starts, so it cannot be negative. A negative one used to reach
 * array_slice(), which counts it from the END — `Paginator::array()` answered with the tail of
 * the list — and on a repository it surfaced as the repository's own LIMIT error.
 */
#[CoversClass(Paginator::class)]
final class PaginatorOffsetTest extends TestCase
{
    public function test_a_negative_offset_is_refused_for_an_array(): void
    {
        $this->expectException(ValueError::class);
        $this->expectExceptionMessage('Offset must be a non-negative integer (>= 0), got: -5.');

        Paginator::array(range(1, 10), size: 5, offset: -5);
    }

    public function test_a_negative_offset_is_refused_for_a_repository_before_any_query(): void
    {
        // RepoTestDbConfig is a stub with no connection: reaching the database would fail
        // differently, so the message proves the paginator refused first.
        $this->expectException(ValueError::class);
        $this->expectExceptionMessage('Offset must be a non-negative integer (>= 0), got: -1.');

        Paginator::repo(UsersRepo::instance(), size: 5, offset: -1);
    }

    public function test_offset_zero_is_still_the_first_page(): void
    {
        $page = Paginator::array(range(1, 10), size: 5, offset: 0);

        self::assertSame([1, 2, 3, 4, 5], $page->data);
    }
}
