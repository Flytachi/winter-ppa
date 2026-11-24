<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Tests\Schema;

use Flytachi\Winter\Ppa\Schema\RenamedFromCleaner;
use Flytachi\Winter\Ppa\Schema\SchemaDirectory;
use Flytachi\Winter\Ppa\Schema\SchemaException;
use Flytachi\Winter\Ppa\Schema\SchemaInspector;
use Flytachi\Winter\Ppa\Schema\SchemaModel;
use Flytachi\Winter\Ppa\Schema\SchemaSql;
use Flytachi\Winter\Ppa\Schema\SchemaVersionFile;
use Flytachi\Winter\Ppa\Tests\Schema\Fixtures as F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures/SchemaFixtures.php';

/** The model, the SQL per driver, the version files, the cleaner, the type spellings. */
final class SchemaPartsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = F\SchemaFixtures::dir();
    }

    protected function tearDown(): void
    {
        F\SchemaFixtures::rm($this->dir);
    }

    // ── The model ───────────────────────────────────────────────────────────

    public function test_a_rename_and_a_change_keep_the_column_in_its_place(): void
    {
        $m = SchemaModel::apply([], ['op' => 'createTable', 'table' => 't', 'primaryKey' => ['id'], 'columns' => [
            ['name' => 'id', 'type' => 'INT', 'nullable' => false],
            ['name' => 'note', 'type' => 'TEXT'],
            ['name' => 'total', 'type' => 'INT', 'nullable' => false],
        ]]);
        $m = SchemaModel::apply($m, ['op' => 'renameColumn', 'table' => 't', 'from' => 'note', 'to' => 'comment']);
        $m = SchemaModel::apply($m, ['op' => 'alterColumn', 'table' => 't', 'column' => 'id', 'to' => ['name' => 'id', 'type' => 'BIGINT', 'nullable' => false]]);

        self::assertSame(['id', 'comment', 'total'], array_keys($m['t']['columns']));
        self::assertSame('BIGINT', $m['t']['columns']['id']['type']);
    }

    public function test_a_history_that_does_not_add_up_is_said(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/table orders is not in the model/');
        SchemaModel::apply([], ['op' => 'addColumn', 'table' => 'orders', 'column' => ['name' => 'x', 'type' => 'INT']]);
    }

    public function test_the_same_entities_give_the_same_model(): void
    {
        $tables = fn() => F\SchemaFixtures::tables('pgsql', ['customers' => F\CustomerV2::class, 'orders' => F\OrderV3::class]);
        self::assertSame(SchemaModel::declared($tables(), 'pgsql'), SchemaModel::declared($tables(), 'pgsql'));
        self::assertSame(['comment' => 'note'], SchemaModel::declared($tables(), 'pgsql')['renamed']['orders']);
    }

    // ── SQL per driver ──────────────────────────────────────────────────────

    /** @return iterable<string, array{string, array, list<string>}> */
    public static function statements(): iterable
    {
        $from = ['name' => 'total', 'type' => 'NUMERIC(10, 2)', 'nullable' => true, 'default' => null];
        $to = ['name' => 'total', 'type' => 'NUMERIC(12, 2)', 'nullable' => false, 'default' => "'0'"];
        $alter = ['op' => 'alterColumn', 'table' => 'orders', 'column' => 'total', 'from' => $from, 'to' => $to];
        yield 'pgsql alter: type, NOT NULL, default — each its own' => ['pgsql', $alter, [
            'ALTER TABLE orders ALTER COLUMN total TYPE NUMERIC(12, 2)',
            'ALTER TABLE orders ALTER COLUMN total SET NOT NULL',
            "ALTER TABLE orders ALTER COLUMN total SET DEFAULT '0'",
        ]];
        yield 'mysql alter: one MODIFY' => ['mysql', $alter, ["ALTER TABLE orders MODIFY COLUMN total NUMERIC(12, 2) NOT NULL DEFAULT '0'"]];
        yield 'pgsql drop index in the table schema' => ['pgsql', ['op' => 'dropIndex', 'table' => 'shop.orders', 'name' => 'orders_x_idx'], ['DROP INDEX shop.orders_x_idx']];
        yield 'mysql drop index on its table' => ['mysql', ['op' => 'dropIndex', 'table' => 'orders', 'name' => 'orders_x_idx'], ['DROP INDEX orders_x_idx ON orders']];
        yield 'mysql drop a foreign key' => ['mysql', ['op' => 'dropConstraint', 'table' => 'orders', 'name' => 'fk_x', 'kind' => 'foreign'], ['ALTER TABLE orders DROP FOREIGN KEY fk_x']];
        yield 'mysql drop a check' => ['mysql', ['op' => 'dropConstraint', 'table' => 'orders', 'name' => 'chk_x', 'kind' => 'check'], ['ALTER TABLE orders DROP CONSTRAINT chk_x']];
        yield 'a rename' => ['sqlite', ['op' => 'renameColumn', 'table' => 'orders', 'from' => 'a', 'to' => 'b'], ['ALTER TABLE orders RENAME COLUMN a TO b']];
        yield 'left to code' => ['pgsql', $alter + ['by' => 'code'], []];
        yield 'detached: nothing in the database' => ['pgsql', ['op' => 'detachColumn', 'table' => 'orders', 'column' => 'a'], []];
    }

    #[DataProvider('statements')]
    public function test_the_statements_of_a_change(string $driver, array $change, array $sql): void
    {
        self::assertSame($sql, SchemaSql::change($change, $driver));
    }

    public function test_sqlite_puts_a_new_tables_constraints_inside_its_create_table(): void
    {
        $plan = SchemaSql::plan([
            ['op' => 'createTable', 'table' => 'orders', 'primaryKey' => ['id'], 'columns' => [['name' => 'id', 'type' => 'INTEGER', 'nullable' => false], ['name' => 'c', 'type' => 'INT']]],
            ['op' => 'addConstraint', 'table' => 'orders', 'name' => 'fk_c', 'kind' => 'foreign', 'sql' => 'ALTER TABLE orders ADD CONSTRAINT fk_c FOREIGN KEY (c) REFERENCES customers(id)'],
        ], 'sqlite');

        self::assertCount(1, $plan);
        self::assertStringContainsString('CONSTRAINT fk_c FOREIGN KEY (c) REFERENCES customers(id)', $plan[0]['sql'][0]);
    }

    public function test_what_sqlite_cannot_alter_is_a_rebuild_of_its_table(): void
    {
        $changes = [
            ['op' => 'createTable', 'table' => 'tags', 'columns' => [], 'primaryKey' => []],
            ['op' => 'addConstraint', 'table' => 'tags', 'name' => 'chk_t', 'kind' => 'check', 'sql' => '…'],
            ['op' => 'addConstraint', 'table' => 'orders', 'name' => 'fk_c', 'kind' => 'foreign', 'sql' => '…'],
            ['op' => 'addIndex', 'table' => 'orders', 'name' => 'orders_x_idx', 'sql' => '…'],
            ['op' => 'renameColumn', 'table' => 'orders', 'from' => 'a', 'to' => 'b'],
        ];
        // tags is made by the version: its check goes inside the CREATE TABLE, nothing rebuilt.
        self::assertSame(['orders'], SchemaSql::rebuilt($changes, 'sqlite'));
        self::assertSame([], SchemaSql::rebuilt($changes, 'pgsql'));
        self::assertSame([], SchemaSql::change($changes[2], 'sqlite'), 'no statement of its own');
        self::assertTrue(SchemaSql::byRebuild($changes[3], ['orders']), 'the rebuild makes the indexes');
        self::assertFalse(SchemaSql::byRebuild($changes[4], ['orders']), 'a rename runs before it');

        $model = ['columns' => [
            'id' => ['name' => 'id', 'type' => 'INTEGER AUTOINCREMENT', 'nullable' => false, 'default' => null],
            'b' => ['name' => 'b', 'type' => 'TEXT', 'nullable' => true, 'default' => null],
            'c' => ['name' => 'c', 'type' => 'TEXT', 'nullable' => true, 'default' => null],
        ], 'primaryKey' => ['id'], 'indexes' => ['orders_b_idx' => 'CREATE INDEX orders_b_idx ON orders (b)'], 'constraints' => [
            'chk_b' => ['kind' => 'check', 'sql' => "ALTER TABLE orders ADD CONSTRAINT chk_b CHECK (b <> '')"],
        ]];
        self::assertSame([
            "CREATE TABLE orders__ppa_new (\n    id INTEGER PRIMARY KEY AUTOINCREMENT,\n    b TEXT,\n    c TEXT,\n    CONSTRAINT chk_b CHECK (b <> '')\n)",
            'INSERT INTO orders__ppa_new (id, b) SELECT id, b FROM orders',   // c is new: its default
            'DROP TABLE orders',
            'ALTER TABLE orders__ppa_new RENAME TO orders',
            'CREATE INDEX orders_b_idx ON orders (b)',
        ], SchemaSql::rebuild('orders', $model, ['id', 'a_dropped', 'b']));
    }

    public function test_a_fresh_database_gets_tables_then_indexes_then_constraints_without_detached_columns(): void
    {
        $model = SchemaModel::declared(F\SchemaFixtures::tables('pgsql', ['customers' => F\CustomerV2::class, 'orders' => F\OrderV3::class]), 'pgsql')['model'];
        $model['orders']['columns']['old'] = ['name' => 'old', 'type' => 'TEXT', 'nullable' => true, 'default' => null, 'detached' => true];

        $sql = SchemaSql::create($model, 'pgsql');

        self::assertStringStartsWith('CREATE TABLE customers', $sql[0]);
        self::assertStringStartsWith('CREATE TABLE orders', $sql[1]);
        self::assertStringNotContainsString(' old ', $sql[1]);
        self::assertStringStartsWith('CREATE UNIQUE INDEX', $sql[2]);
        self::assertStringStartsWith('ALTER TABLE orders ADD CONSTRAINT', end($sql));
    }

    // ── Version files ───────────────────────────────────────────────────────

    public function test_a_version_file_must_agree_with_its_name_and_its_code(): void
    {
        $dir = new SchemaDirectory($this->dir);
        $dir->write('V001', 'init', 'pgsql', []);
        file_put_contents($this->dir . '/V002_wrong.json', json_encode(['version' => 'V003', 'name' => 'wrong', 'driver' => 'pgsql', 'changes' => []]));

        $this->expectExceptionMessageMatches('/the file name and its content differ/');
        $dir->versions();
    }

    public function test_a_code_file_without_its_version_or_a_version_without_its_code_is_refused(): void
    {
        $dir = new SchemaDirectory($this->dir);
        $dir->write('V001', 'init', 'pgsql', [], code: '<?php');
        unlink($this->dir . '/custom/V001_init.php');

        $this->expectExceptionMessageMatches('/has code .* but custom\/V001_init\.php is missing/');
        $dir->versions();
    }

    public function test_a_version_of_another_driver_is_refused(): void
    {
        $dir = new SchemaDirectory($this->dir);
        $dir->write('V001', 'init', 'mysql', []);

        $this->expectExceptionMessageMatches('/recorded for mysql, the database is pgsql/');
        $dir->versions('pgsql');
    }

    public function test_the_checksum_covers_the_json_and_the_code(): void
    {
        $dir = new SchemaDirectory($this->dir);
        $dir->write('V001', 'init', 'pgsql', [], code: "<?php // a\n");
        $before = $dir->versions()[0]->checksum;
        file_put_contents($this->dir . '/custom/V001_init.php', "<?php // b\n");

        self::assertNotSame($before, $dir->versions()[0]->checksum);
        self::assertSame(64, strlen($before));
    }

    public function test_the_next_version_is_the_last_number_plus_one(): void
    {
        $dir = new SchemaDirectory($this->dir);
        self::assertSame('V001', $dir->nextVersion());
        $dir->write('V001', 'a', 'pgsql', []);
        $dir->write('V002', 'b', 'pgsql', [], code: '<?php');
        self::assertSame('V003', $dir->nextVersion());
        self::assertFileExists($this->dir . '/V002_b.json');
        self::assertFileExists($this->dir . '/custom/V002_b.php');
        self::assertFileDoesNotExist($this->dir . '/V002_b.php');
    }

    public function test_versions_go_by_their_number_past_v999(): void
    {
        $dir = new SchemaDirectory($this->dir);
        $dir->write('V998', 'a', 'pgsql', []);
        $dir->write('V999', 'b', 'pgsql', []);
        self::assertSame('V1000', $dir->nextVersion());
        $dir->write('V1000', 'c', 'pgsql', []);

        self::assertSame(['V998', 'V999', 'V1000'], array_map(fn($v) => $v->version, $dir->versions()));
        self::assertSame('V1001', $dir->nextVersion());
    }

    /** @return iterable<string, array{string}> */
    public static function notVersions(): iterable
    {
        yield 'no padding' => ['V1'];
        yield 'two digits' => ['V07'];
        yield 'padded past three' => ['V0007'];
        yield 'the old dated form' => ['V2026_11_21_001'];
        yield 'lower-case v' => ['v001'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notVersions')]
    public function test_a_version_is_v_and_three_digits_at_least(string $version): void
    {
        file_put_contents("{$this->dir}/{$version}_x.json", json_encode(['version' => $version, 'name' => 'x', 'driver' => 'pgsql', 'changes' => []]));

        $this->expectExceptionMessageMatches('/is no version \(V001, V002/');
        new SchemaDirectory($this->dir)->versions();
    }

    public function test_code_beside_the_versions_is_refused(): void
    {
        $dir = new SchemaDirectory($this->dir);
        $dir->write('V001', 'init', 'pgsql', []);
        file_put_contents($this->dir . '/V001_init.php', '<?php');

        $this->expectExceptionMessageMatches('/V001_init\.php is beside the versions: the code of a version is in custom\//');
        $dir->versions();
    }

    public function test_code_in_custom_without_its_version_is_refused(): void
    {
        $dir = new SchemaDirectory($this->dir);
        $dir->write('V001', 'init', 'pgsql', []);
        mkdir($this->dir . '/custom');
        file_put_contents($this->dir . '/custom/V002_gone.php', '<?php');

        $this->expectExceptionMessageMatches('/custom\/V002_gone\.php has no version/');
        $dir->versions();
    }

    public function test_a_name_is_lower_case_letters_digits_and_underscores(): void
    {
        $this->expectExceptionMessageMatches('/cannot name a version/');
        new SchemaDirectory($this->dir)->write('V001', 'Add Status', 'pgsql', []);
    }

    public function test_the_same_code_in_another_folder_is_the_class_already_loaded(): void
    {
        // A history copied, a release beside the old one: one class, met twice in a process.
        $class = 'V001_Same' . bin2hex(random_bytes(3));
        $code = "<?php\nnamespace E2eSame;\nuse Flytachi\\Winter\\Ppa\\Schema\\{SchemaVersion, VersionCode};\n"
            . "#[SchemaVersion('V001')]\nfinal class {$class} extends VersionCode {}\n";
        $one = new SchemaDirectory($this->dir . '/one');
        $two = new SchemaDirectory($this->dir . '/two');
        $one->write('V001', 'same', 'pgsql', [], code: $code);
        $two->write('V001', 'same', 'pgsql', [], code: $code);

        self::assertSame("E2eSame\\{$class}", $one->versions()[0]->code()::class);
        self::assertSame("E2eSame\\{$class}", $two->versions()[0]->code()::class);

        $three = new SchemaDirectory($this->dir . '/three');
        $three->write('V001', 'same', 'pgsql', [], code: str_replace('{}', '{ /* other */ }', $code));
        $this->expectExceptionMessageMatches('/is loaded already, from .*one.*, with other code/');
        $three->versions()[0]->code();
    }

    // ── snapshot clean ──────────────────────────────────────────────────────

    public function test_clean_removes_a_recorded_attribute_on_a_class_and_only_there(): void
    {
        $src = <<<'PHP'
            <?php
            use Flytachi\Winter\Ppa\Mapping\Attributes\Entity\Table;
            use Flytachi\Winter\Ppa\Mapping\Attributes\Migration\RenamedFrom;

            #[Table]
            #[RenamedFrom('customers')]
            final class Client
            {
                #[RenamedFrom('mail')]
                public string $email = '';
            }

            #[Table, RenamedFrom('order')]
            class Order {}

            #[Table]
            #[RenamedFrom('goods')]
            #[Check("price >= 0", name: 'chk_price')]
            class Product
            {
                #[RenamedFrom('qty')]
                #[Varchar(32)]
                public string $quantity = '';
            }
            PHP;

        $out = RenamedFromCleaner::clean($src, ['@Client']);

        self::assertSame(1, $out['removed']);
        self::assertStringNotContainsString("RenamedFrom('customers')", $out['source']);
        self::assertStringContainsString("#[RenamedFrom('mail')]", $out['source'], 'the property keeps its own');
        self::assertStringContainsString("#[Table, RenamedFrom('order')]", $out['source'], 'another class keeps its own');
        self::assertSame(1, RenamedFromCleaner::clean($out['source'], ['@Order'])['removed']);

        // Another attribute with arguments after it — on a class and on a property.
        $product = RenamedFromCleaner::clean($out['source'], ['@Product', 'quantity']);
        self::assertSame(2, $product['removed']);
        self::assertStringNotContainsString("RenamedFrom('goods')", $product['source']);
        self::assertStringNotContainsString("RenamedFrom('qty')", $product['source']);
        self::assertStringContainsString("#[Check(\"price >= 0\", name: 'chk_price')]", $product['source']);
    }

    public function test_clean_removes_a_recorded_attribute_on_its_own_line_and_its_use(): void
    {
        $src = <<<'PHP'
            <?php
            use Flytachi\Winter\Ppa\Mapping\Attributes\Migration\RenamedFrom;
            use Flytachi\Winter\Ppa\Mapping\Attributes\Primal\Text;

            class Order
            {
                #[Text]
                #[RenamedFrom('note')]          // renamed in V2
                public ?string $comment = null;

                #[Text]
                public ?string $other = null;
            }
            PHP;

        $out = RenamedFromCleaner::clean($src, ['comment']);

        self::assertSame(1, $out['removed']);
        self::assertSame(str_replace([
            "use Flytachi\\Winter\\Ppa\\Mapping\\Attributes\\Migration\\RenamedFrom;\n",
            "    #[RenamedFrom('note')]          // renamed in V2\n",
        ], '', $src), $out['source']);
    }

    public function test_clean_takes_one_element_out_of_a_group_and_leaves_other_properties_alone(): void
    {
        $src = <<<'PHP'
            <?php
            use Flytachi\Winter\Ppa\Mapping\Attributes\Migration\RenamedFrom;
            class Order
            {
                #[Text, RenamedFrom('note')] public ?string $comment = null;
                #[RenamedFrom('x'), Text]
                public ?string $kept = null;
            }
            PHP;

        $out = RenamedFromCleaner::clean($src, ['comment']);

        self::assertStringContainsString("#[Text] public ?string \$comment", $out['source']);
        self::assertStringContainsString("#[RenamedFrom('x'), Text]", $out['source'], 'not asked for');
        self::assertStringContainsString('use Flytachi\Winter\Ppa\Mapping\Attributes\Migration\RenamedFrom;', $out['source'], 'still used');

        $first = RenamedFromCleaner::clean($src, ['kept']);
        self::assertStringContainsString("    #[Text]\n    public ?string \$kept", $first['source']);
    }

    // ── Type spellings ──────────────────────────────────────────────────────

    /** @return iterable<string, array{string, string, string}> */
    public static function spellings(): iterable
    {
        yield ['INT GENERATED BY DEFAULT AS IDENTITY', 'pgsql', 'int'];
        yield ['integer', 'pgsql', 'int'];
        yield ['character varying(120)', 'pgsql', 'varchar(120)'];
        yield ['NUMERIC(12, 2)', 'pgsql', 'numeric(12,2)'];
        yield ['timestamp with time zone', 'pgsql', 'timestamptz'];
        yield ['TIMESTAMP WITH TIME ZONE', 'pgsql', 'timestamptz'];
        yield ['double precision', 'pgsql', 'double'];
        yield ['bigint(20)', 'mysql', 'bigint'];
        yield ['BIGINT AUTO_INCREMENT', 'mysql', 'bigint'];
        yield ['tinyint(1)', 'mysql', 'bool'];
        yield ['BOOLEAN', 'mysql', 'bool'];
        yield ['decimal(12,2)', 'mysql', 'numeric(12,2)'];
        yield ['DECIMAL(12, 2)', 'mysql', 'numeric(12,2)'];
        yield ['int(10) unsigned', 'mysql', 'int'];
        // Arrays: as declared, and as PostgreSQL's catalogue names them (udt_name).
        yield ['TEXT[]', 'pgsql', 'text[]'];
        yield ['_text', 'pgsql', 'text[]'];
        yield ['INTEGER[]', 'pgsql', 'int[]'];
        yield ['_int4', 'pgsql', 'int[]'];
        yield ['VARCHAR(20)[]', 'pgsql', 'varchar[]'];
        yield ['_varchar', 'pgsql', 'varchar[]'];
    }

    #[DataProvider('spellings')]
    public function test_a_type_has_one_spelling_per_driver(string $type, string $driver, string $canonical): void
    {
        self::assertSame($canonical, SchemaInspector::canonical($type, $driver));
    }

    public function test_a_version_without_its_class_in_the_code_file_is_said(): void
    {
        $dir = new SchemaDirectory($this->dir);
        $dir->write('V001', 'init', 'pgsql', [], code: "<?php\nfinal class NoAttr" . bin2hex(random_bytes(3)) . " {}\n");

        $this->expectExceptionMessageMatches("/declares no class with #\\[SchemaVersion\\('V001'\\)\\]/");
        $dir->versions()[0]->code();
    }

    public function test_reading_a_file_that_is_not_json_says_which(): void
    {
        file_put_contents($this->dir . '/V001_init.json', '{nope');

        $this->expectExceptionMessageMatches('/V001_init\.json is not valid JSON/');
        SchemaVersionFile::read($this->dir . '/V001_init.json');
    }

    public function test_a_long_text_default_is_an_expression_on_mysql(): void
    {
        $note = ['name' => 'note', 'type' => 'TEXT', 'nullable' => false, 'default' => "''"];

        // MySQL has no literal default for TEXT, BLOB, JSON (error 1101); MariaDB takes both.
        self::assertSame("note TEXT NOT NULL DEFAULT ('')", SchemaSql::columnDef($note, 'mysql'));
        self::assertSame("note TEXT NOT NULL DEFAULT ''", SchemaSql::columnDef($note, 'pgsql'));
        self::assertSame("DEFAULT ('{}')", 'DEFAULT ' . SchemaSql::defaultValue('JSON', "'{}'", 'mysql'));
        self::assertSame('(UUID())', SchemaSql::defaultValue('MEDIUMBLOB', '(UUID())', 'mysql'), 'an expression already');
        self::assertSame("'x'", SchemaSql::defaultValue('VARCHAR(10)', "'x'", 'mysql'), 'a literal where one is taken');
        self::assertSame(
            ["ALTER TABLE orders ADD COLUMN note TEXT NOT NULL DEFAULT ('')"],
            SchemaSql::change(['op' => 'addColumn', 'table' => 'orders', 'column' => $note], 'mysql'),
        );
    }

    public function test_a_sqlite_id_is_autoincrement_as_the_primary_key(): void
    {
        $id = ['name' => 'id', 'type' => 'INTEGER AUTOINCREMENT', 'nullable' => false, 'default' => null];
        $name = ['name' => 'name', 'type' => 'TEXT', 'nullable' => true, 'default' => null];

        self::assertSame(
            "CREATE TABLE t (\n    id INTEGER PRIMARY KEY AUTOINCREMENT,\n    name TEXT\n)",
            SchemaSql::createTable('t', [$id, $name], ['id'], 'sqlite'),
        );
        // The mark is SQLite's type only: never written as it is.
        self::assertSame('id INTEGER NOT NULL', SchemaSql::columnDef($id, 'sqlite'));
        self::assertSame(['INTEGER', 'AUTOINCREMENT'], \Flytachi\Winter\Ppa\Schema\SchemaDiff::base('INTEGER AUTOINCREMENT'));
    }
}
