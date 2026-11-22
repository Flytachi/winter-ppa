<?php

declare(strict_types=1);

// Entities of an application as it evolves — one class per state of a table. The tests map
// them to tables with SchemaFixtures::tables(), as PPAMapping does for an application.

namespace Flytachi\Winter\Ppa\Tests\Schema\Fixtures;

use Flytachi\Winter\Ppa\Mapping\Attributes\Additive\DefaultVal;
use Flytachi\Winter\Ppa\Mapping\Attributes\Constraint\Check;
use Flytachi\Winter\Ppa\Mapping\Attributes\Constraint\ForeignKey;
use Flytachi\Winter\Ppa\Mapping\Attributes\Entity\Table;
use Flytachi\Winter\Ppa\Mapping\Attributes\Hybrid\BigId;
use Flytachi\Winter\Ppa\Mapping\Attributes\Hybrid\Id;
use Flytachi\Winter\Ppa\Mapping\Attributes\Idx\Index;
use Flytachi\Winter\Ppa\Mapping\Attributes\Idx\Unique;
use Flytachi\Winter\Ppa\Mapping\Attributes\Migration\RenamedFrom;
use Flytachi\Winter\Ppa\Mapping\Attributes\Primal\{BigInteger, Decimal, Integer, Text, Varchar};
use Flytachi\Winter\Ppa\Mapping\Constants\FKAction;

#[Table]
class CustomerV1
{
    #[Id]
    public ?int $id = null;

    #[Varchar(120)]
    public string $name = '';

    #[Varchar(255)]
    public string $email = '';
}

// V2: the e-mail becomes unique.
#[Table]
class CustomerV2
{
    #[Id]
    public ?int $id = null;

    #[Varchar(120)]
    public string $name = '';

    #[Varchar(255)]
    #[Unique]
    public string $email = '';
}

#[Table]
class OrderV1
{
    #[BigId]
    public ?int $id = null;

    #[Varchar(120)]
    public string $customer = '';

    #[Text]
    public ?string $note = null;

    #[Decimal(10, 2)]
    public string $total = '0';
}

// V2: note → comment, a status with an index, a check on the total.
#[Table]
class OrderV2
{
    #[BigId]
    public ?int $id = null;

    #[Varchar(120)]
    public string $customer = '';

    #[Text]
    #[RenamedFrom('note')]
    public ?string $comment = null;

    #[Decimal(10, 2)]
    #[Check('total >= 0')]
    public string $total = '0';

    #[Varchar(20)]
    #[DefaultVal("'new'")]
    #[Index]
    public string $status = 'new';
}

// V3: V2 + a link to customers and a wider total; the RenamedFrom of V2 is still there (already recorded).
#[Table]
class OrderV3
{
    #[BigId]
    public ?int $id = null;

    #[Varchar(120)]
    public string $customer = '';

    #[Text]
    #[RenamedFrom('note')]
    public ?string $comment = null;

    #[Decimal(12, 2)]
    #[Check('total >= 0')]
    public string $total = '0';

    #[Varchar(20)]
    #[DefaultVal("'new'")]
    #[Index]
    public string $status = 'new';

    #[Integer]
    #[ForeignKey('customers', 'id', onDelete: FKAction::CASCADE)]
    public ?int $customer_id = null;
}

// V3 without $customer: a column gone from the entity.
#[Table]
class OrderWithoutCustomer
{
    #[BigId]
    public ?int $id = null;

    #[Text]
    public ?string $comment = null;

    #[Decimal(12, 2)]
    #[Check('total >= 0')]
    public string $total = '0';

    #[Varchar(20)]
    #[DefaultVal("'new'")]
    #[Index]
    public string $status = 'new';

    #[Integer]
    #[ForeignKey('customers', 'id', onDelete: FKAction::CASCADE)]
    public ?int $customer_id = null;
}

// A NOT NULL column with no default — cannot be added to a table with rows.
#[Table]
class OrderWithRequiredCode
{
    #[BigId]
    public ?int $id = null;

    #[Varchar(120)]
    public string $customer = '';

    #[Text]
    public ?string $note = null;

    #[Decimal(10, 2)]
    public string $total = '0';

    #[Varchar(8)]
    public string $code;
}

// The id BIGINT → INTEGER: not a widening.
#[Table]
class OrderNarrowTotal
{
    #[BigId]
    public ?int $id = null;

    #[Varchar(120)]
    public string $customer = '';

    #[Text]
    public ?string $note = null;

    #[BigInteger]
    public int $total = 0;
}

// A rename whose old name is not in the history.
#[Table]
class OrderBadRename
{
    #[BigId]
    public ?int $id = null;

    #[Varchar(120)]
    #[RenamedFrom('buyer')]
    public string $customer = '';

    #[Text]
    public ?string $note = null;

    #[Decimal(10, 2)]
    public string $total = '0';
}

// customers renamed: CustomerV2 under another name — its unique index and its key follow it.
#[Table]
#[RenamedFrom('customers')]
class ClientRenamed
{
    #[Id]
    public ?int $id = null;

    #[Varchar(120)]
    public string $name = '';

    #[Varchar(255)]
    #[Unique]
    public string $email = '';
}

// OrderV3 with its key on the renamed table.
#[Table]
class OrderV3Clients
{
    #[BigId]
    public ?int $id = null;

    #[Varchar(120)]
    public string $customer = '';

    #[Text]
    public ?string $comment = null;

    #[Decimal(12, 2)]
    #[Check('total >= 0')]
    public string $total = '0';

    #[Varchar(20)]
    #[DefaultVal("'new'")]
    #[Index]
    public string $status = 'new';

    #[Integer]
    #[ForeignKey('clients', 'id', onDelete: FKAction::CASCADE)]
    public ?int $customer_id = null;
}
