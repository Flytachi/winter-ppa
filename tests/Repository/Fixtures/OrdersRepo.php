<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Tests\Repository\Fixtures;

use Flytachi\Winter\Ppa\Stereotype\RepositoryView;

final class OrdersRepo extends RepositoryView
{
    protected string $dbConfigClassName = RepoTestDbConfig::class;
    public static string $table = 'orders';
}
