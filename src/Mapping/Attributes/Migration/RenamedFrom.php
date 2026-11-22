<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Mapping\Attributes\Migration;

use Attribute;

/**
 * The column was called `$from` in the last schema snapshot: a rename, not a column dropped
 * and another added — the data stays.
 *
 * ```
 * #[Text]
 * #[RenamedFrom('note')]
 * public ?string $comment = null;
 * ```
 *
 * It is read relative to the last snapshot (`ppa/<Config>/`), so it lives only until the
 * next `call db snapshot` records the rename; after that it says nothing and may go —
 * `call db snapshot clean` removes the ones already recorded. A name that is not a column of
 * the last snapshot, or that is also a column of the entity, is an error, not a guess.
 *
 * It is no column attribute: the schema generator ignores it, and so does a database
 * without snapshots.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class RenamedFrom
{
    public function __construct(public string $from)
    {
    }
}
