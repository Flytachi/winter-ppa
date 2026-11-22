<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Mapping\Attributes\Migration;

use Attribute;

/**
 * The column — or, on the entity class, the table — was called `$from` in the last schema
 * snapshot: a rename, not one dropped and another added — the data stays.
 *
 * ```
 * #[Text]
 * #[RenamedFrom('note')]
 * public ?string $comment = null;
 *
 * #[Table]
 * #[RenamedFrom('order')]           // or 'public.orders': moved to another schema
 * class Order { … }
 * ```
 *
 * A table's name without a schema is in the schema the table is in now. Its indexes and
 * constraints named after the table are renamed with it, as a fresh database names them.
 *
 * It is read relative to the last snapshot (`ppa/<Config>/`), so it lives only until the
 * next `call db snapshot` records the rename; after that it says nothing and may go —
 * `call db snapshot clean` removes the ones already recorded. A name that is not a column of
 * the last snapshot, or that is also a column of the entity, is an error, not a guess.
 *
 * It is no column attribute: the schema generator ignores it, and so does a database
 * without snapshots.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_CLASS)]
final readonly class RenamedFrom
{
    public function __construct(public string $from)
    {
    }
}
