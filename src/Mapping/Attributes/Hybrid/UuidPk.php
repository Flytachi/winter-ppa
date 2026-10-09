<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Mapping\Attributes\Hybrid;

use Attribute;
use Flytachi\Winter\Ppa\Mapping\Attributes\Additive\DefaultVal;
use Flytachi\Winter\Ppa\Mapping\Attributes\Additive\NullableIs;
use Flytachi\Winter\Ppa\Mapping\Attributes\Idx\Primary;
use Flytachi\Winter\Ppa\Mapping\Attributes\Primal\Uuid;

#[Attribute(Attribute::TARGET_PROPERTY)]
/**
 * @link https://winterframe.net/docs/entities#uuidpk Entities: the #[UuidPk] attribute
 */
final readonly class UuidPk implements AttributeDbHybrid
{
    /**
     * A version 4 UUID built from random bytes — SQLite has no UUID function. The version
     * digit is fixed to 4 and the variant digit drawn from 8, 9, a, b; the parentheses make
     * it an expression default, which SQLite requires for anything but a literal.
     */
    private const string SQLITE_UUID_V4 = "(lower(hex(randomblob(4))) || '-' || lower(hex(randomblob(2)))"
        . " || '-4' || substr(lower(hex(randomblob(2))), 2) || '-'"
        . " || substr('89ab', 1 + (abs(random()) % 4), 1) || substr(lower(hex(randomblob(2))), 2)"
        . " || '-' || lower(hex(randomblob(6))))";

    public function getInstances(string $dialect = 'mysql'): array
    {
        return [
            new Primary(),
            new Uuid(),
            new NullableIs(false),
            new DefaultVal(self::defaultFor($dialect)),
        ];
    }

    /**
     * The column default that generates the key.
     *
     * MySQL accepts a function as a default only in parentheses (8.0.13+): a bare
     * `DEFAULT UUID()` is a syntax error there, while MariaDB takes either form.
     * Its `UUID()` is version 1 — time and node based, not random.
     *
     * @throws \LogicException For a dialect without a known way to generate a UUID.
     */
    private static function defaultFor(string $dialect): string
    {
        return match ($dialect) {
            'pgsql' => 'gen_random_uuid()',
            'mysql' => '(UUID())',
            'sqlite' => self::SQLITE_UUID_V4,
            default => throw new \LogicException(
                "#[UuidPk] does not know how to generate a UUID on '{$dialect}'"
            ),
        };
    }
}
