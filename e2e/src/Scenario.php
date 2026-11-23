<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\E2e;

/** What every scenario shares: where stage 1 ends, and the edits a developer makes to entities. */
final class Scenario
{
    /** The last version of stage 1: the data of stage 2 comes on it. */
    public const int STAGE_1 = 5;

    /** Every #[RenamedFrom] taken out: a version that recorded it, cleaned as `snapshot clean` does. */
    public static function clean(State $s): State
    {
        foreach ($s->tables as $table => $t) {
            $kept = array_values(array_filter($t['attrs'], fn($a) => !str_starts_with($a, '#[RenamedFrom(')));
            if ($kept !== $t['attrs']) {
                $s = $s->table($table, ['attrs' => $kept]);
            }
        }
        foreach ($s->tables as $table => $t) {
            foreach ($t['props'] as $prop => [$attrs]) {
                $kept = array_values(array_filter($attrs, fn($a) => !str_starts_with($a, '#[RenamedFrom(')));
                if ($kept !== $attrs) {
                    $s = $s->attrs($table, $prop, fn() => $kept);
                }
            }
        }
        return $s;
    }

    /** @return list<string> `$old` replaced by `$new` — or taken out; `$old` must be there. */
    public static function swap(array $attrs, string $old, ?string $new): array
    {
        if (!in_array($old, $attrs, true)) {
            throw new \LogicException("No {$old} among " . implode(' ', $attrs));
        }
        return array_values(array_filter(array_map(fn($a) => $a === $old ? $new : $a, $attrs), fn($a) => $a !== null));
    }
}
