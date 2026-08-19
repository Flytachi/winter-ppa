<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Tests\Repository\Fixtures;

use Flytachi\Winter\Ppa\Stereotype\RepositoryView;

final class SelectionMappedRepo extends RepositoryView
{
    protected string $dbConfigClassName = RepoTestDbConfig::class;
    protected string $entityClassName = UserWithSelectionEntity::class;
    public static string $table = 'users';
}
