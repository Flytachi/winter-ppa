<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Tests\Pagination;

use Flytachi\Winter\Ppa\Pagination\Wrapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ValueError;

/**
 * Page-centric pagination: `{current, pages, previous, next}` around the data.
 *
 * The array path opens no connection, and these tests run without a database for that
 * reason — the helper is useful over an in-memory list, and nothing about slicing one
 * needs a server.
 */
#[CoversClass(Wrapper::class)]
final class WrapperTest extends TestCase
{
    public function test_an_array_is_paginated_without_touching_a_database(): void
    {
        $page = Wrapper::paginator(range(1, 25), limit: 10, page: 2);

        self::assertSame([11, 12, 13, 14, 15, 16, 17, 18, 19, 20], $page->data);
        self::assertSame(2, $page->meta->current);
        self::assertSame(25, $page->meta->total);
        self::assertSame(3, $page->meta->pages);
        self::assertSame(1, $page->meta->previous);
        self::assertSame(3, $page->meta->next);
    }

    public function test_the_first_and_last_pages_report_no_neighbour(): void
    {
        $first = Wrapper::paginator(range(1, 25), limit: 10);
        $last  = Wrapper::paginator(range(1, 25), limit: 10, page: 3);

        self::assertNull($first->meta->previous);
        self::assertSame(2, $first->meta->next);
        self::assertSame(2, $last->meta->previous);
        self::assertNull($last->meta->next);
        self::assertSame([21, 22, 23, 24, 25], $last->data, 'the last page is short, not padded');
    }

    public function test_an_empty_source_is_a_page_of_nothing(): void
    {
        $page = Wrapper::paginator([], limit: 10);

        self::assertSame([], $page->data);
        self::assertSame(0, $page->meta->total);
        self::assertSame(0, $page->meta->pages, 'no items, no pages — not one empty page');
    }

    public function test_a_page_past_the_end_is_empty_rather_than_an_error(): void
    {
        $page = Wrapper::paginator(range(1, 5), limit: 10, page: 4);

        self::assertSame([], $page->data);
        self::assertSame(5, $page->meta->total);
    }

    public function test_the_mapper_transforms_only_the_page(): void
    {
        $calls = 0;
        $page  = Wrapper::paginator(range(1, 25), limit: 5, page: 2, mapper: function (int $n) use (&$calls): int {
            $calls++;
            return $n * 10;
        });

        self::assertSame([60, 70, 80, 90, 100], $page->data);
        self::assertSame(5, $calls, 'the mapper runs on the page, not on the whole list');
    }

    public function test_a_limit_below_one_is_refused(): void
    {
        $this->expectException(ValueError::class);

        Wrapper::paginator(range(1, 5), limit: 0);
    }

    public function test_without_the_package_the_signature_is_the_guard(): void
    {
        // Nothing can satisfy RepositoryViewInterface unless the package that declares it
        // is installed, so the argument is rejected before any check of ours could run —
        // and the message names the interface, which names the package. Verified against
        // a real install without flytachi/winter-ppa.
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('RepositoryViewInterface');

        // @phpstan-ignore-next-line — passing the wrong shape is the point
        Wrapper::paginator(new \stdClass(), limit: 10);
    }
}
