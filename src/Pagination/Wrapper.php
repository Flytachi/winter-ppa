<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Pagination;

use Flytachi\Winter\Ppa\Entity\RepositoryViewInterface;
use ValueError;

/**
 * Class Wrapper
 *
 * Thin wrapper around {@see Paginator} that produces a page-centric response
 * shape (`current`, `pages`, `previous`, `next`) suited for traditional
 * numbered-page UIs. New code that does not need page-based navigation should
 * prefer {@see Paginator::repo()} / {@see Paginator::array()} directly —
 * they return a typed `PaginationResult` with the offset-centric
 * `PaginationMeta` and native `JsonSerializable` support.
 *
 * Stateless. Safe for concurrent calls in Swoole.
 *
 * @version 6.0
 * @author Flytachi
 *
 * @link https://winterframe.net/docs/pagination#wrapper Pagination: numbered pages
 */
final class Wrapper
{
    /**
     * Paginate an in-memory array or a repository query.
     *
     * Meta is page-centric (`current`, `pages`, `previous`, `next`) — that is the
     * difference from the offset-centric {@see PaginationMeta}, which exposes
     * `{offset, size, total}`. Code without page-numbered UI requirements should prefer
     * {@see Paginator} directly.
     *
     * The array path is handled here rather than delegated to {@see Paginator::array()}:
     * that one answers with the offset-centric envelope, and re-shaping it into a
     * page-centric one costs more than slicing the list. Nothing here touches a database
     * — an in-memory list is paginated without opening a connection.
     *
     * @template TItem
     *
     * @param array<TItem>|RepositoryViewInterface<TItem> $repo Source — repository or in-memory list.
     * @param int $limit Page size. Must be `>= 1`.
     * @param int $page 1-based page number. Defaults to `1` (first page).
     * @param class-string|null $entityClassName Hydration override for repositories
     *                                           (ignored for array input).
     * @param callable|null $mapper Optional per-item transformer applied to the
     *                              fetched page before assembly.
     *
     * @return WrapResult<TItem> Typed page-centric response (`JsonSerializable`).
     *
     * @throws ValueError When `$limit < 1`.
     *
     * @link https://winterframe.net/docs/pagination#wrapperpaginator Numbered pages
     */
    final public static function paginator(
        array|RepositoryViewInterface $repo,
        int $limit,
        int $page = 1,
        ?string $entityClassName = null,
        ?callable $mapper = null,
    ): WrapResult {
        if ($limit < 1) {
            throw new ValueError("Size must be a positive integer (>= 1), got: $limit.");
        }

        $offset = $limit * ($page - 1);

        if (is_array($repo)) {
            $total = count($repo);
            $data  = array_slice($repo, $offset, $limit);
            $data  = $mapper === null ? $data : array_map($mapper, $data);
        } else {
            $result = Paginator::repo($repo, $limit, $offset, $entityClassName, $mapper);
            $total  = $result->meta->total;
            $data   = $result->data;
        }

        $pages = $total > 0 ? (int) ceil($total / $limit) : 0;

        return new WrapResult(
            meta: new WrapMeta(
                current: $page,
                size: $limit,
                total: $total,
                pages: $pages,
                previous: $page > 1 ? $page - 1 : null,
                next: $pages > $page ? $page + 1 : null,
            ),
            data: $data,
        );
    }
}
