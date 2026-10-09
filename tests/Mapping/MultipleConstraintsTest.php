<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Tests\Mapping;

use Flytachi\Winter\Ppa\Mapping\Attributes\Constraint\Check;
use Flytachi\Winter\Ppa\Mapping\Attributes\Constraint\CheckEnum;
use Flytachi\Winter\Ppa\Mapping\Attributes\Constraint\ForeignKey as ForeignKeyAttr;
use Flytachi\Winter\Ppa\Mapping\Attributes\Constraint\ForeignRepo;
use Flytachi\Winter\Ppa\Mapping\Attributes\Entity\Table as EntityTable;
use Flytachi\Winter\Ppa\Mapping\Attributes\Primal\Integer;
use Flytachi\Winter\Ppa\Mapping\Attributes\Primal\Varchar;
use Flytachi\Winter\Ppa\Mapping\ColumnMapping;
use Flytachi\Winter\Ppa\Mapping\Structure\CheckConstraint;
use Flytachi\Winter\Ppa\Mapping\Structure\Column;
use Flytachi\Winter\Ppa\PPAMapping;
use Flytachi\Winter\Ppa\Stereotype\RepositoryView;
use Flytachi\Winter\Ppa\Tests\Repository\Fixtures\RepoTestDbConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

enum McSize: string
{
    case Small = 'small';
    case Kids = "kid's";
}

final class McEntity
{
    #[Varchar(16), CheckEnum(McSize::class), Check("size <> ''")]
    public string $size;

    #[Integer, Check('qty >= 0'), Check('qty < 1000')]
    public int $qty;

    #[Integer, ForeignKeyAttr('users', 'id'), ForeignRepo(McEntityRepo::class)]
    public int $owner_id;
}

#[EntityTable]
#[Check('end_at > start_at', name: 'chk_booking_period')]
#[Check('seats > 0')]
final class McBookingEntity
{
    #[Integer]
    public int $start_at;
    #[Integer]
    public int $end_at;
    #[Integer]
    public int $seats;
}

final class McEntityRepo extends RepositoryView
{
    protected string $dbConfigClassName = RepoTestDbConfig::class;
    protected string $entityClassName = McBookingEntity::class;
    public static string $table = 'bookings';
}

/**
 * Several constraints on one property, and checks declared on the entity class: none of
 * them may be lost silently.
 */
final class MultipleConstraintsTest extends TestCase
{
    private function column(string $property): Column
    {
        $mapping = new ColumnMapping('pgsql');
        $mapping->push(new ReflectionProperty(McEntity::class, $property));
        return $mapping->getColumns()[0];
    }

    public function test_check_enum_and_check_on_one_property_both_survive(): void
    {
        $col = $this->column('size');

        self::assertSame(
            ["size IN ('small', 'kid''s')", "size <> ''"],
            array_map(fn(CheckConstraint $c) => $c->expression, $col->checks),
        );
        self::assertCount(2, $col->constraintsSql('t', 'pgsql'), 'each check is a constraint of its own');
    }

    public function test_check_is_repeatable_on_a_property(): void
    {
        $col = $this->column('qty');

        self::assertSame(
            ['qty >= 0', 'qty < 1000'],
            array_map(fn(CheckConstraint $c) => $c->expression, $col->checks),
        );
        self::assertSame('qty >= 0', $col->checkConstraint?->expression, 'the legacy property shows the first');
    }

    public function test_a_second_foreign_key_on_one_property_is_refused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/already has a foreign key to users/');

        $this->column('owner_id');
    }

    public function test_check_enum_doubles_an_apostrophe_instead_of_backslashing_it(): void
    {
        $expression = (new CheckEnum(McSize::class))->toObject('size')->expression;

        self::assertStringNotContainsString('\\', $expression);
        self::assertStringContainsString("'kid''s'", $expression);
    }

    public function test_check_on_the_entity_class_becomes_a_table_check(): void
    {
        $tables = PPAMapping::declarationFrom([new ReflectionClass(McEntityRepo::class)])
            ->getItems()[0]
            ->getTables();
        $table = $tables[0];

        self::assertSame(
            ['end_at > start_at', 'seats > 0'],
            array_map(fn(CheckConstraint $c) => $c->expression, $table->checks),
        );
        self::assertStringContainsString(
            'ADD CONSTRAINT chk_booking_period CHECK (end_at > start_at)',
            $table->toSql('pgsql'),
        );
    }

    // ── Column built by hand: the single-check parameter keeps working ──────

    public function test_legacy_check_constraint_parameter_is_still_emitted(): void
    {
        $col = new Column('age', 'INT', checkConstraint: new CheckConstraint('age >= 0', name: 'chk_age'));

        self::assertCount(1, $col->checks);
        self::assertSame(
            ['ALTER TABLE t ADD CONSTRAINT chk_age CHECK (age >= 0)'],
            $col->constraintsSql('t'),
        );
    }

    public function test_legacy_parameter_and_checks_combine_without_duplicates(): void
    {
        $first = new CheckConstraint('age >= 0');
        $second = new CheckConstraint('age < 200');

        $col = new Column('age', 'INT', checkConstraint: $first, checks: [$second]);
        self::assertSame([$first, $second], $col->checks);

        $same = new Column('age', 'INT', checkConstraint: $first, checks: [$first]);
        self::assertSame([$first], $same->checks);
    }
}
