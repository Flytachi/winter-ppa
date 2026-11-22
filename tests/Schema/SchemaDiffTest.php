<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Tests\Schema;

use Flytachi\Winter\Ppa\Schema\SchemaDiff;
use Flytachi\Winter\Ppa\Schema\SchemaDirectory;
use Flytachi\Winter\Ppa\Schema\SchemaException;
use Flytachi\Winter\Ppa\Schema\SchemaModel;
use Flytachi\Winter\Ppa\Schema\SchemaSnapshot;
use Flytachi\Winter\Ppa\Tests\Schema\Fixtures as F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures/SchemaFixtures.php';
require_once __DIR__ . '/Fixtures/NotImported.php';

/**
 * What `db snapshot` records, and what it refuses: it never guesses a rename, never drops
 * without being told, never adds a column old rows cannot have.
 */
final class SchemaDiffTest extends TestCase
{
    private string $dir;
    private SchemaDirectory $history;

    protected function setUp(): void
    {
        $this->dir = F\SchemaFixtures::dir();
        $this->history = new SchemaDirectory($this->dir);
    }

    protected function tearDown(): void
    {
        F\SchemaFixtures::rm($this->dir);
    }

    public function test_the_first_snapshot_creates_every_table_then_its_indexes_and_constraints(): void
    {
        $diff = $this->diff(['customers' => F\CustomerV2::class, 'orders' => F\OrderV3::class]);

        self::assertSame([], $diff->errors);
        self::assertSame(
            ['createTable', 'createTable', 'addIndex', 'addIndex', 'addConstraint', 'addConstraint'],
            array_column($diff->changes, 'op'),
        );
        self::assertSame(['id', 'customer', 'comment', 'total', 'status', 'customer_id'], array_column($diff->changes[1]['columns'], 'name'));
    }

    public function test_a_rename_with_the_attribute_keeps_the_column_and_the_rest_follows(): void
    {
        $this->record(['customers' => F\CustomerV1::class, 'orders' => F\OrderV1::class]);

        $diff = $this->diff(['customers' => F\CustomerV2::class, 'orders' => F\OrderV2::class]);

        self::assertSame([], $diff->errors);
        $ops = array_map(fn($c) => $c['op'] . ' ' . ($c['name'] ?? $c['column']['name'] ?? $c['to'] ?? ''), $diff->changes);
        self::assertSame([
            'renameColumn comment',
            'addColumn status',
            'addIndex customers_email_udx',
            'addIndex orders_status_idx',
            'addConstraint ' . $diff->changes[4]['name'],
        ], $ops);
        self::assertSame(['from' => 'note', 'to' => 'comment'], array_intersect_key($diff->changes[0], ['from' => 1, 'to' => 1]));
    }

    public function test_a_rename_already_recorded_is_a_notice_and_can_be_cleaned(): void
    {
        $this->record(['customers' => F\CustomerV1::class, 'orders' => F\OrderV1::class]);
        $this->record(['customers' => F\CustomerV2::class, 'orders' => F\OrderV2::class]);

        $diff = $this->diff(['customers' => F\CustomerV2::class, 'orders' => F\OrderV2::class]);

        self::assertTrue($diff->isEmpty());
        self::assertSame([], $diff->errors);
        self::assertStringContainsString("#[RenamedFrom('note')] is already recorded in V002", $diff->notices[0]);
        self::assertSame(F\OrderV2::class, $diff->stale[0]['entity']);
        self::assertSame('comment', $diff->stale[0]['property']);
    }

    public function test_a_rename_from_a_column_the_history_never_had_is_an_error(): void
    {
        $this->record(['customers' => F\CustomerV1::class, 'orders' => F\OrderV1::class]);

        $diff = $this->diff(['customers' => F\CustomerV1::class, 'orders' => F\OrderBadRename::class]);

        self::assertStringContainsString("#[RenamedFrom('buyer')]", $diff->errors[0]);
        self::assertStringContainsString('Remove the attribute', $diff->errors[0]);
    }

    public function test_a_column_gone_is_refused_unless_dropped_or_kept(): void
    {
        $this->record(['customers' => F\CustomerV2::class, 'orders' => F\OrderV3::class]);
        $tables = ['customers' => F\CustomerV2::class, 'orders' => F\OrderWithoutCustomer::class];

        $refused = $this->diff($tables);
        self::assertStringContainsString('orders.customer is gone from the entity', $refused->errors[0]);
        self::assertStringContainsString('--drop=orders.customer', $refused->errors[0]);

        $dropped = $this->diff($tables, drop: ['orders.customer']);
        self::assertSame([], $dropped->errors);
        self::assertSame([['op' => 'dropColumn', 'table' => 'orders', 'column' => 'customer']], $dropped->changes);

        $kept = $this->diff($tables, keep: ['orders.customer']);
        self::assertSame([['op' => 'detachColumn', 'table' => 'orders', 'column' => 'customer']], $kept->changes);
    }

    public function test_a_kept_column_is_left_alone_afterwards_and_dropped_when_told(): void
    {
        $this->record(['customers' => F\CustomerV2::class, 'orders' => F\OrderV3::class]);
        $tables = ['customers' => F\CustomerV2::class, 'orders' => F\OrderWithoutCustomer::class];
        $this->record($tables, keep: ['orders.customer']);

        self::assertTrue($this->diff($tables)->isEmpty(), 'the history knows it is kept');
        self::assertArrayNotHasKey('customer', SchemaModel::live(SchemaModel::fold($this->history->versions()))['orders']['columns']);
        self::assertSame('dropColumn', $this->diff($tables, drop: ['orders.customer'])->changes[0]['op']);
    }

    public function test_a_table_gone_is_dropped_only_when_told_with_its_constraints_first(): void
    {
        $this->record(['customers' => F\CustomerV2::class, 'orders' => F\OrderV3::class]);

        self::assertStringContainsString('Table orders has no entity any more', $this->diff(['customers' => F\CustomerV2::class])->errors[0]);
        $ops = array_column($this->diff(['customers' => F\CustomerV2::class], drop: ['orders'])->changes, 'op');
        self::assertSame(['dropConstraint', 'dropConstraint', 'dropTable'], $ops);
    }

    public function test_a_not_null_column_without_a_default_cannot_join_a_table_with_rows(): void
    {
        $this->record(['customers' => F\CustomerV1::class, 'orders' => F\OrderV1::class]);

        $diff = $this->diff(['customers' => F\CustomerV1::class, 'orders' => F\OrderWithRequiredCode::class]);

        self::assertStringContainsString('orders.code is NOT NULL with no default', $diff->errors[0]);
    }

    public function test_a_widening_is_the_kernels_and_a_narrowing_is_left_to_code(): void
    {
        $this->record(['customers' => F\CustomerV1::class, 'orders' => F\OrderV1::class]);
        $narrow = ['customers' => F\CustomerV1::class, 'orders' => F\OrderNarrowTotal::class];

        self::assertStringContainsString('cannot be changed by the kernel', $this->diff($narrow)->errors[0]);
        $custom = $this->diff($narrow, custom: true);
        self::assertSame([], $custom->errors);
        self::assertSame('code', $custom->changes[0]['by']);
        self::assertTrue($custom->needsCode());
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function typeChanges(): iterable
    {
        yield 'longer varchar' => ['VARCHAR(20)', 'VARCHAR(50)', true];
        yield 'shorter varchar' => ['VARCHAR(50)', 'VARCHAR(20)', false];
        yield 'varchar to text' => ['VARCHAR(50)', 'TEXT', true];
        yield 'text to varchar' => ['TEXT', 'VARCHAR(50)', false];
        yield 'int to bigint' => ['INT', 'BIGINT', true];
        yield 'bigint to int' => ['BIGINT', 'INT', false];
        yield 'more digits both sides' => ['NUMERIC(10, 2)', 'NUMERIC(12, 2)', true];
        yield 'more scale, fewer integer digits' => ['NUMERIC(10, 2)', 'NUMERIC(10, 4)', false];
        yield 'identity kept' => ['INT GENERATED BY DEFAULT AS IDENTITY', 'BIGINT GENERATED BY DEFAULT AS IDENTITY', true];
        yield 'identity lost' => ['INT GENERATED BY DEFAULT AS IDENTITY', 'BIGINT', false];
        yield 'decimal to bigint' => ['DECIMAL(10, 2)', 'BIGINT', false];
    }

    #[DataProvider('typeChanges')]
    public function test_which_type_changes_keep_every_value(string $from, string $to, bool $widens): void
    {
        self::assertSame($widens, SchemaDiff::widens($from, $to));
    }

    public function test_sqlite_refuses_at_snapshot_what_it_cannot_do_by_itself(): void
    {
        $this->record(['customers' => F\CustomerV1::class, 'orders' => F\OrderV1::class], driver: 'sqlite');
        // V2 adds a CHECK to orders: not by SQLite itself (ALTER TABLE … ADD CONSTRAINT is new
        // to it, and a version is applied by any SQLite) — a custom version's code.
        $v2 = ['customers' => F\CustomerV2::class, 'orders' => F\OrderV2::class];
        self::assertStringContainsString('SQLite cannot add the check', implode("\n", $this->diff($v2, driver: 'sqlite')->errors));
        $custom = $this->diff($v2, driver: 'sqlite', custom: true);
        self::assertSame([], $custom->errors);
        self::assertSame(['addConstraint'], array_column(array_filter($custom->changes, fn($c) => ($c['by'] ?? null) === 'code'), 'op'));
        SchemaSnapshot::write($this->history, $custom, 'sqlite', 'v2', true);

        $diff = $this->diff(['customers' => F\CustomerV2::class, 'orders' => F\OrderV3::class], driver: 'sqlite');

        $all = implode("\n", $diff->errors);
        self::assertStringContainsString('SQLite cannot change a column', $all, 'the wider total');
        self::assertStringContainsString('SQLite cannot add the foreign key', $all);
        self::assertSame([], $this->diff(['customers' => F\CustomerV2::class, 'orders' => F\OrderV3::class], driver: 'sqlite', custom: true)->errors);
    }

    public function test_nothing_is_written_when_there_is_nothing_or_when_it_is_refused(): void
    {
        $this->record(['customers' => F\CustomerV1::class, 'orders' => F\OrderV1::class]);

        try {
            SchemaSnapshot::write($this->history, $this->diff(['customers' => F\CustomerV1::class, 'orders' => F\OrderV1::class]), 'pgsql');
            self::fail('nothing to record');
        } catch (SchemaException $e) {
            self::assertStringContainsString('nothing to record', $e->getMessage());
        }
        $this->expectExceptionMessageMatches('/NOT NULL with no default/');
        SchemaSnapshot::write($this->history, $this->diff(['customers' => F\CustomerV1::class, 'orders' => F\OrderWithRequiredCode::class]), 'pgsql');
    }

    public function test_a_custom_version_gets_its_code_skeleton_naming_what_is_left_to_it(): void
    {
        $this->record(['customers' => F\CustomerV1::class, 'orders' => F\OrderV1::class]);
        $diff = $this->diff(['customers' => F\CustomerV1::class, 'orders' => F\OrderNarrowTotal::class], custom: true);

        $written = SchemaSnapshot::write($this->history, $diff, 'pgsql', 'narrow_total', true);

        self::assertSame('V002', $written['version']);
        self::assertStringEndsWith('/custom/V002_narrow_total.php', $written['files'][1]);
        $php = (string) file_get_contents($written['files'][1]);
        self::assertStringContainsString("#[SchemaVersion('V002')]", $php);
        self::assertStringContainsString('final class V002_NarrowTotal extends VersionCode', $php);
        self::assertStringContainsString('orders.total', $php);
        self::assertInstanceOf(\Flytachi\Winter\Ppa\Schema\VersionCode::class, $this->history->versions()[1]->code());
    }

    public function test_a_custom_version_of_each_database_lives_in_its_config_namespace(): void
    {
        // Two databases with the same entities and the same custom version: one process
        // loads both (db migrate, #[EnablePpaMigration]) — two global V002_Fill would be fatal.
        $code = [];
        foreach (['App\\Config\\PgDb', 'App\\Config\\MariaDb'] as $i => $owner) {
            $dir = new SchemaDirectory($this->dir . "/db{$i}");
            $diff = SchemaSnapshot::diff($dir, F\SchemaFixtures::tables('pgsql', ['customers' => F\CustomerV1::class, 'orders' => F\OrderV1::class]), 'pgsql');
            SchemaSnapshot::write($dir, $diff, 'pgsql');
            $written = SchemaSnapshot::write($dir, SchemaSnapshot::diff($dir, [], 'pgsql', drop: ['customers', 'orders'], custom: true), 'pgsql', 'fill', true, $owner);
            self::assertStringContainsString("namespace {$owner};", (string) file_get_contents($written['files'][1]));
            $code[] = $dir->versions()[1]->code();
        }

        self::assertSame('App\\Config\\PgDb\\V002_Fill', $code[0]::class);
        self::assertSame('App\\Config\\MariaDb\\V002_Fill', $code[1]::class);
    }

    public function test_a_table_renamed_by_its_class_is_one_change_with_its_names(): void
    {
        $this->record(['customers' => F\CustomerV2::class, 'orders' => F\OrderV3::class]);

        $diff = $this->diff(['clients' => F\ClientRenamed::class, 'orders' => F\OrderV3Clients::class]);

        self::assertSame([], $diff->errors);
        self::assertSame(['op' => 'renameTable', 'from' => 'customers', 'to' => 'clients', 'indexes' => ['customers_email_udx' => 'clients_email_udx'], 'constraints' => []], $diff->changes[0]);
        // orders' key on customers follows the table: the model rewrites REFERENCES, nothing to remake.
        self::assertSame(['renameTable'], array_column($diff->changes, 'op'));

        SchemaSnapshot::write($this->history, $diff, 'pgsql');
        $again = $this->diff(['clients' => F\ClientRenamed::class, 'orders' => F\OrderV3Clients::class]);
        self::assertTrue($again->isEmpty());
        self::assertStringContainsString("#[RenamedFrom('customers')] is already recorded in V002", $again->notices[0]);
        self::assertSame('@ClientRenamed', $again->stale[0]['property']);
    }

    public function test_a_key_still_on_the_old_name_of_a_renamed_table_is_an_error(): void
    {
        $this->record(['customers' => F\CustomerV2::class, 'orders' => F\OrderV3::class]);

        // orders still declares ForeignKey('customers', …): the table is clients now.
        $diff = $this->diff(['clients' => F\ClientRenamed::class, 'orders' => F\OrderV3::class]);

        self::assertStringContainsString('the foreign key fk_orders_customer_id points at customers, renamed to clients', implode("\n", $diff->errors));
    }

    public function test_a_renamed_from_written_without_its_use_is_an_error(): void
    {
        // What PHP makes of #[RenamedFrom] in a file without the `use`: another class, unknown.
        $this->record(['customers' => F\CustomerV2::class, 'orders' => F\OrderV1::class]);
        $tables = F\SchemaFixtures::tables('pgsql', ['clients' => F\ClientNotImported::class, 'orders' => F\OrderV1::class]);

        $diff = SchemaSnapshot::diff($this->history, $tables, 'pgsql');

        $all = implode("\n", $diff->errors);
        self::assertStringContainsString('ClientNotImported: #[RenamedFrom] is written without its `use`', $all);
        self::assertStringContainsString('ClientNotImported::$mail: #[RenamedFrom] is written without its `use`', $all);
    }

    public function test_renamed_from_a_table_that_is_not_there_is_an_error(): void
    {
        $this->record(['orders' => F\OrderV1::class]);

        $diff = $this->diff(['clients' => F\ClientRenamed::class, 'orders' => F\OrderV1::class]);

        self::assertStringContainsString("#[RenamedFrom('customers')]: `customers` is not a table of the last snapshot", implode("\n", $diff->errors));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function renameTableSql(): iterable
    {
        $c = ['op' => 'renameTable', 'from' => 'public.order', 'to' => 'shop.orders', 'indexes' => ['order_note_idx' => 'orders_note_idx', 'x_idx' => 'x_idx'], 'constraints' => ['fk_order_user_id' => 'fk_orders_user_id']];
        yield 'mysql' => ['mysql', ['ALTER TABLE public.order RENAME TO shop.orders, RENAME INDEX order_note_idx TO orders_note_idx']];
        yield 'pgsql' => ['pgsql', [
            'ALTER TABLE public.order SET SCHEMA shop',
            'ALTER TABLE shop.order RENAME TO orders',
            'ALTER INDEX shop.order_note_idx RENAME TO orders_note_idx',
            'ALTER TABLE shop.orders RENAME CONSTRAINT fk_order_user_id TO fk_orders_user_id',
        ]];
    }

    #[DataProvider('renameTableSql')]
    public function test_a_table_rename_per_driver(string $driver, array $first): void
    {
        $c = ['op' => 'renameTable', 'from' => 'public.order', 'to' => 'shop.orders', 'indexes' => ['order_note_idx' => 'orders_note_idx', 'x_idx' => 'x_idx'], 'constraints' => ['fk_order_user_id' => 'fk_orders_user_id']];
        $sql = \Flytachi\Winter\Ppa\Schema\SchemaSql::change($c, $driver);

        self::assertSame($first, array_slice($sql, 0, count($first)));
        if ($driver === 'pgsql') {
            self::assertStringStartsWith('DO $$', end($sql), 'the primary key, NOT NULL and sequences the server named');
            self::assertStringContainsString('order_pkey TO orders_pkey', end($sql));
        }
    }

    public function test_sqlite_renames_a_table_but_moves_it_nowhere(): void
    {
        self::assertSame(['ALTER TABLE order RENAME TO orders'], \Flytachi\Winter\Ppa\Schema\SchemaSql::change(['op' => 'renameTable', 'from' => 'order', 'to' => 'orders'], 'sqlite'));
        $this->expectExceptionMessageMatches('/SQLite has no schema to move a table to/');
        \Flytachi\Winter\Ppa\Schema\SchemaSql::change(['op' => 'renameTable', 'from' => 'a.order', 'to' => 'b.order'], 'sqlite');
    }

    public function test_a_default_name_says_what_the_version_does(): void
    {
        self::assertSame('create_orders', SchemaSnapshot::defaultName([['op' => 'createTable', 'table' => 'public.orders']]));
        self::assertSame('rename_note_add_status', SchemaSnapshot::defaultName([
            ['op' => 'renameColumn', 'table' => 'orders', 'from' => 'note', 'to' => 'comment'],
            ['op' => 'addColumn', 'table' => 'orders', 'column' => ['name' => 'status']],
        ]));
        self::assertSame('indexes', SchemaSnapshot::defaultName([['op' => 'addIndex', 'table' => 'orders', 'name' => 'x', 'sql' => '']]));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function diff(array $map, array $drop = [], array $keep = [], bool $custom = false, string $driver = 'pgsql'): SchemaDiff
    {
        return SchemaSnapshot::diff($this->history, F\SchemaFixtures::tables($driver, $map), $driver, $drop, $keep, $custom);
    }

    private function record(array $map, array $keep = [], string $driver = 'pgsql'): void
    {
        $diff = $this->diff($map, keep: $keep, driver: $driver);
        self::assertSame([], $diff->errors);
        SchemaSnapshot::write($this->history, $diff, $driver);
    }
}
