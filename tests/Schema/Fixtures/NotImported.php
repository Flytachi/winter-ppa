<?php

declare(strict_types=1);

// An entity whose #[RenamedFrom] has no `use`: PHP resolves it to this namespace, a class that
// does not exist — what a file looks like after `snapshot clean` took the last one and its `use`.

namespace Flytachi\Winter\Ppa\Tests\Schema\Fixtures;

use Flytachi\Winter\Ppa\Mapping\Attributes\Entity\Table;
use Flytachi\Winter\Ppa\Mapping\Attributes\Hybrid\Id;
use Flytachi\Winter\Ppa\Mapping\Attributes\Primal\Varchar;

#[Table]
#[RenamedFrom('customers')]
class ClientNotImported
{
    #[Id]
    public ?int $id = null;

    #[Varchar(255)]
    #[RenamedFrom('email')]
    public string $mail = '';
}
