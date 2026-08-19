<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Tests\Repository\Fixtures;

use Flytachi\Winter\Ppa\Stereotype\RepositoryView;

/**
 * Plain repo with no typed entity — buildSql() will emit `SELECT *`.
 */
final class UsersRepo extends RepositoryView
{
    protected string $dbConfigClassName = RepoTestDbConfig::class;
    public static string $table = 'users';
}
