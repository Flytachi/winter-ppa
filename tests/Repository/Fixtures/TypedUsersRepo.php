<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Tests\Repository\Fixtures;

use Flytachi\Winter\Ppa\Stereotype\RepositoryView;

final class TypedUsersRepo extends RepositoryView
{
    protected string $dbConfigClassName = RepoTestDbConfig::class;
    protected string $entityClassName = UserEntity::class;
    public static string $table = 'users';
}
