<?php

declare(strict_types=1);

// The small things the scenario needs beside its states: the enums the entities check
// against, and the failure a check throws.

namespace Flytachi\Winter\Ppa\E2e;

enum UserStatus: string
{
    case Active = 'active';
    case New = 'new';
    case Deleted = 'deleted';
}

/** UserStatus with one case more: the CHECK changes with it. */
enum UserStatusV2: string
{
    case Active = 'active';
    case New = 'new';
    case Deleted = 'deleted';
    case Blocked = 'blocked';
}

final class Failure extends \RuntimeException
{
}

/** What a check says when it does not hold. */
function expect(bool $holds, string $what): void
{
    if (!$holds) {
        throw new Failure($what);
    }
}

/** @return list<mixed> */
function col(\PDO $db, string $sql): array
{
    return $db->query($sql)->fetchAll(\PDO::FETCH_COLUMN);
}

function one(\PDO $db, string $sql): mixed
{
    return $db->query($sql)->fetchColumn();
}

/** A check failed and the variant cannot go on: what comes next stands on it. */
final class Stopped extends \RuntimeException
{
}
