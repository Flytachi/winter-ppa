<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Tests\Schema\Migration;

use Flytachi\Winter\Cdo\Config\Common\DbConfigInterface;
use Flytachi\Winter\Cdo\Connection\CDO;
use Flytachi\Winter\Ppa\Schema\SchemaDirectory;
use Flytachi\Winter\Ppa\Schema\SchemaException;
use Flytachi\Winter\Ppa\Schema\SchemaHistory;
use Flytachi\Winter\Ppa\Schema\SchemaInspector;
use Flytachi\Winter\Ppa\Schema\SchemaMigrator;
use Flytachi\Winter\Ppa\Schema\SchemaModel;
use Flytachi\Winter\Ppa\Schema\SchemaSnapshot;
use Flytachi\Winter\Ppa\Schema\SchemaSql;
use Flytachi\Winter\Ppa\Schema\SchemaVersionFile;
use Flytachi\Winter\Ppa\Tests\Schema\Fixtures as F;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

require_once __DIR__ . '/../Fixtures/SchemaFixtures.php';
require_once __DIR__ . '/../Fixtures/Configs.php';
require_once __DIR__ . '/../Fixtures/IndexEntities.php';

/**
 * Versions applied to a live database, the same scenarios on every driver: a fresh database,
 * an upgrade with data in it, a version that fails, a history that does not add up, a
 * database made before there was a history, two instances at once.
 *
 * The history is made the way an application makes it — `db snapshot` of evolving entities
 * — and every claim is checked in the database itself: its rows, its schema, its history.
 */
abstract class MigrationScenarios extends TestCase
{
    /** Whose history it is: the config's class name, as the kernel gives it. */
    protected const string OWNER = 'App\\Config\\MainDb';

    protected CDO $db;
    protected DbConfigInterface $config;
    protected string $driver;
    protected string $dir;
    protected SchemaDirectory $history;
    /** @var list<array{string, string}> */
    protected array $log = [];

    /** The database's config — or markTestSkipped() when there is no such server. */
    abstract protected function config(): DbConfigInterface;

    /** Empties the database: no tables, no history. */
    abstract protected function reset(): void;

    protected function setUp(): void
    {
        $this->dir = F\SchemaFixtures::dir();
        $this->config = $this->config();
        $this->config->setUp();             // as ppa and the kernel do before they connect
        $this->db = $this->config->connection();
        $this->driver = $this->config->getDriver();
        $this->reset();
        $this->history = new SchemaDirectory($this->dir . '/main');
    }

    protected function tearDown(): void
    {
        F\SchemaFixtures::rm($this->dir);
    }

    // ── The scenarios ───────────────────────────────────────────────────────

    public function test_a_fresh_database_is_created_from_the_last_version_at_once(): void
    {
        $this->v1();
        $this->v2();
        $last = $this->supportsV3() ? $this->v3() : null;

        $done = $this->migrator()->migrate();

        self::assertSame(array_fill(0, $last ? 3 : 2, 'created'), array_column($done, 'action'));
        self::assertSame([], $this->differences(), 'the database is the model of the last version');
        self::assertSame(array_fill(0, $last ? 3 : 2, 'created'), array_column($this->rows(), 'state'));
        self::assertFileExists($this->dir . '/main/custom/' . $this->history->versions()[1]->label() . '.php');
        self::assertSame(0, $this->rowCount('customers'), 'no version code ran: there were no rows');

        self::assertSame([], $this->migrator()->migrate(), 'run again: nothing to do');
        self::assertContains('up to date', array_map(fn($l) => str_contains($l[1], 'up to date') ? 'up to date' : '', $this->log));
    }

    public function test_an_upgrade_keeps_the_data_and_runs_the_version_code_around_its_steps(): void
    {
        $this->v1();
        $this->migrator()->migrate();
        $this->db->exec("INSERT INTO customers (name, email) VALUES ('Ann', 'ann@example.com'), ('Bob', 'bob@example.com'), ('Ann again', 'ann@example.com')");
        $this->db->exec("INSERT INTO orders (customer, note, total) VALUES ('Ann', 'first order', 50), ('Bob', 'big order', 500)");

        $this->v2();
        $done = $this->migrator()->migrate();

        self::assertSame([['version' => 'V002', 'action' => 'applied']], array_map(
            fn($d) => ['version' => $d['version'], 'action' => $d['action']],
            $done,
        ));
        self::assertSame(['Ann', 'Bob'], $this->col('SELECT name FROM customers ORDER BY id'), 'before(): the duplicate went first');
        self::assertSame(['first order', 'big order'], $this->col('SELECT comment FROM orders ORDER BY id'), 'note → comment kept the text');
        self::assertSame(['new', 'done'], $this->col('SELECT status FROM orders ORDER BY id'), 'after(): the default, then the code');
        self::assertSame([], $this->differences());
        $this->assertRefused("INSERT INTO customers (name, email) VALUES ('Eve', 'ann@example.com')", 'the e-mail is unique now');
        $this->assertRefused("INSERT INTO orders (customer, total) VALUES ('Eve', -1)", 'the total has a CHECK now');

        if ($this->supportsV3()) {
            $this->v3();
            $this->migrator()->migrate();
            self::assertSame([], $this->differences());
            self::assertSame(['50.00', '500.00'], array_map(fn($v) => number_format((float) $v, 2, '.', ''), $this->col('SELECT total FROM orders ORDER BY id')));
            $this->assertRefused("INSERT INTO orders (customer, customer_id) VALUES ('Eve', 999)", 'a foreign key now');
        }
        $states = array_column($this->rows(), 'state');
        self::assertSame('created', $states[0], 'V1 made the empty database');
        self::assertSame(array_fill(0, count($states) - 1, 'applied'), array_slice($states, 1));
    }

    public function test_a_fresh_database_and_an_upgraded_one_have_the_same_schema(): void
    {
        $this->v1();
        $this->migrator()->migrate();
        $this->db->exec("INSERT INTO orders (customer, note, total) VALUES ('Ann', 'x', 1)");
        $this->v2();
        if ($this->supportsV3()) {
            $this->v3();
        }
        $this->migrator()->migrate();
        $upgraded = $this->schema();

        $this->reset();
        $this->migrator()->migrate();
        $fresh = $this->schema();

        self::assertSame($fresh, $upgraded);
    }

    public function test_a_version_that_fails_leaves_the_versions_before_it_and_runs_again_once_fixed(): void
    {
        $this->v1();
        $this->migrator()->migrate();
        $this->db->exec("INSERT INTO orders (customer, note, total) VALUES ('Ann', 'kept', 5)");
        $v2 = $this->v2(after: "throw new \\RuntimeException('the code of V2 broke');");

        try {
            $this->migrator()->migrate();
            self::fail('the version failed');
        } catch (SchemaException $e) {
            self::assertStringContainsString('V002_v2 failed', $e->getMessage());
            self::assertStringContainsString('the code of V2 broke', $e->getMessage());
        }

        $orders = new SchemaInspector($this->db, $this->driver)->table('orders');
        if ($this->driver === 'mysql') {
            // A schema change commits at once: the steps are in, the version is "failed".
            self::assertArrayHasKey('comment', $orders['columns']);
            self::assertSame('failed', $this->rows()['V002']['state']);
        } else {
            self::assertArrayHasKey('note', $orders['columns'], 'rolled back: the old schema');
            self::assertArrayNotHasKey('V002', $this->rows());
        }
        self::assertSame(['kept'], $this->col('SELECT ' . ($this->driver === 'mysql' ? 'comment' : 'note') . ' FROM orders'));

        $this->code($v2, after: $this->v2After($v2));
        $done = $this->migrator()->migrate();

        self::assertSame('applied', $done[0]['action']);
        self::assertSame('applied', $this->rows()['V002']['state']);
        self::assertSame(['kept'], $this->col('SELECT comment FROM orders'));
        self::assertSame([], $this->differences());
    }

    public function test_code_that_leaves_the_database_otherwise_than_its_model_is_not_recorded(): void
    {
        $this->v1();
        $this->migrator()->migrate();
        // total DECIMAL → BIGINT is left to the code ("by": "code"), and the code does nothing.
        $v = $this->snapshot(['customers' => F\CustomerV1::class, 'orders' => F\OrderNarrowTotal::class], 'narrow_total', custom: true);
        $this->code($v);                                // after() makes nothing of what is left to it

        if ($this->driver === 'sqlite') {
            // SQLite rebuilds the table to its model: the type is the model's without the code.
            self::assertSame('applied', $this->migrator()->migrate()[0]['action']);
            self::assertSame([], $this->differences());
            return;
        }
        try {
            $this->migrator()->migrate();
            self::fail('the database differs from the model');
        } catch (SchemaException $e) {
            self::assertStringContainsString('differs from its model', $e->getMessage());
            self::assertStringContainsString('orders.total', $e->getMessage());
        }
        self::assertArrayNotHasKey('V002', array_filter($this->rows(), fn($r) => $r['state'] === 'applied'));
    }

    public function test_a_version_changed_after_it_was_applied_is_refused(): void
    {
        $v1 = $this->v1();
        $this->migrator()->migrate();
        file_put_contents($v1->jsonPath, file_get_contents($v1->jsonPath) . ' ');

        $this->expectExceptionMessageMatches('/changed after this database applied it/');
        $this->migrator()->migrate();
    }

    public function test_a_version_whose_files_are_gone_is_refused(): void
    {
        $v1 = $this->v1();
        $this->migrator()->migrate();
        $this->v2();
        unlink($v1->jsonPath);

        $this->expectExceptionMessageMatches('/its files are gone/');
        $this->migrator()->migrate();
    }

    public function test_a_version_that_arrived_late_is_refused(): void
    {
        $this->v1();
        $this->migrator()->migrate();
        $orders = ($this->tableSchema() !== null ? $this->tableSchema() . '.' : '') . 'orders';
        // Two branches took V002; the one merged first was renumbered V003 and applied…
        $this->history->write('V003', 'later', $this->driver, [
            ['op' => 'addIndex', 'table' => $orders, 'name' => 'orders_total_idx', 'sql' => "CREATE INDEX orders_total_idx ON {$orders} (total)"],
        ]);
        $this->migrator()->migrate();
        // …and the other one arrives with V002, older than what is applied.
        $this->history->write('V002', 'late', $this->driver, [
            ['op' => 'addIndex', 'table' => $orders, 'name' => 'orders_customer_idx', 'sql' => "CREATE INDEX orders_customer_idx ON {$orders} (customer)"],
        ]);

        $this->expectExceptionMessageMatches('/V002_late is older than V003.*arrived late/');
        $this->migrator()->migrate();
    }

    public function test_a_database_made_before_there_was_a_history_gets_a_baseline(): void
    {
        $this->v1();
        // What `call db migrate` of 4.x made: the tables, no history.
        foreach (SchemaSql::create(SchemaModel::fold($this->history->versions()), $this->driver) as $sql) {
            $this->db->exec($sql);
        }
        $this->db->exec("INSERT INTO orders (customer, note, total) VALUES ('Ann', 'from before', 5)");
        $this->v2();

        // What `db migrate --dry` shows: V001 is there already — read, nothing written.
        self::assertSame(['matched' => 1, 'differences' => []], $this->migrator()->baselineMatch());
        self::assertSame([], $this->rows());

        $done = $this->migrator()->migrate();

        self::assertSame(['baseline', 'applied'], array_column($done, 'action'));
        self::assertSame(['from before'], $this->col('SELECT comment FROM orders'));
        self::assertSame([], $this->differences());
    }

    public function test_tables_of_no_version_are_refused_a_baseline_and_said_why(): void
    {
        $this->v1();
        // Tables of no version: what V001 makes, less a column.
        foreach (SchemaSql::create(SchemaModel::fold($this->history->versions()), $this->driver) as $sql) {
            $this->db->exec($sql);
        }
        $this->db->exec('ALTER TABLE orders DROP COLUMN note');

        $match = $this->migrator()->baselineMatch();
        self::assertSame(0, $match['matched']);
        self::assertNotSame([], $match['differences']);

        $this->expectExceptionMessageMatches('/matches none of its versions/');
        $this->migrator()->migrate();
    }

    public function test_the_history_keeps_a_copy_of_what_each_version_applied(): void
    {
        $v1 = $this->v1();
        $this->migrator()->migrate();

        $history = new SchemaHistory($this->db, $this->driver, self::OWNER, $this->config->getSchema());
        self::assertSame(file_get_contents($v1->jsonPath), $history->changesOf($v1->version));
        self::assertSame($v1->checksum, $this->rows()[$v1->version]['checksum']);
    }

    public function test_a_renamed_table_keeps_its_rows_and_is_named_as_a_fresh_one(): void
    {
        $prefix = $this->tableSchema() !== null ? $this->tableSchema() . '.' : '';
        $this->snapshot(['customers' => F\CustomerV2::class, 'orders' => F\OrderV1::class], 'v1');
        $this->migrator()->migrate();
        $this->db->exec("INSERT INTO {$prefix}customers (name, email) VALUES ('Ann', 'ann@x'), ('Bob', 'bob@x')");

        // customers → clients: #[RenamedFrom('customers')] on the class.
        $v2 = $this->snapshot(['clients' => F\ClientRenamed::class, 'orders' => F\OrderV1::class], 'clients');
        self::assertSame('renameTable', $v2->changes[0]['op']);
        self::assertNotContains('createTable', array_column($v2->changes, 'op'), 'a rename, not a new table');
        $this->migrator()->migrate();

        self::assertSame([], $this->differences());
        self::assertSame(['Ann', 'Bob'], $this->col("SELECT name FROM {$prefix}clients ORDER BY id"));
        $upgraded = new SchemaInspector($this->db, $this->driver)->table($prefix . 'clients');
        self::assertNull(new SchemaInspector($this->db, $this->driver)->table($prefix . 'customers'));
        self::assertSame(['clients_email_udx'], array_values($upgraded['indexes']));

        // A fresh database of the same history: the same table, named alike.
        $this->reset();
        $this->migrator()->migrate();
        self::assertEquals($upgraded, new SchemaInspector($this->db, $this->driver)->table($prefix . 'clients'));
    }

    public function test_a_version_stopped_midway_runs_again_from_where_it_stood(): void
    {
        if ($this->driver !== 'mysql') {
            self::markTestSkipped('Only where a schema change commits at once: elsewhere a stopped version rolls back.');
        }
        $this->snapshot(['customers' => F\CustomerV2::class, 'orders' => F\OrderV1::class], 'v1');
        $this->migrator()->migrate();
        $this->db->exec("INSERT INTO customers (name, email) VALUES ('Ann', 'ann@x'), ('Bob', 'bob@x')");
        $v2 = $this->snapshot(['clients' => F\ClientRenamed::class, 'orders' => F\OrderV1::class], 'clients');

        // What a process killed after its first step leaves: the row "running", that step done.
        $history = new SchemaHistory($this->db, $this->driver, self::OWNER, $this->config->getSchema());
        $history->record($v2, 'running');
        foreach (SchemaSql::plan($v2->changes, $this->driver)[0]['sql'] as $sql) {
            $this->db->exec($sql);
        }

        $done = $this->migrator()->migrate();

        self::assertSame([['V002', 'applied']], array_map(fn($d) => [$d['version'], $d['action']], $done));
        self::assertSame('applied', $this->rows()['V002']['state']);
        self::assertSame([], $this->differences());
        self::assertSame(['Ann', 'Bob'], $this->col('SELECT name FROM clients ORDER BY id'));
        self::assertStringContainsString('V002_clients was stopped midway the last time', implode("\n", array_column($this->log, 1)));
    }

    public function test_two_histories_in_one_database_keep_apart(): void
    {
        $this->v1();
        $this->migrator()->migrate();
        // A package (or a second config) on the same database: its own folder, its own V001.
        $audit = ($this->tableSchema() !== null ? $this->tableSchema() . '.' : '') . 'audit';
        $pkg = new SchemaDirectory($this->dir . '/pkg');
        $pkg->write('V001', 'audit', $this->driver, [
            ['op' => 'createTable', 'table' => $audit, 'primaryKey' => ['id'], 'columns' => [
                ['name' => 'id', 'type' => 'INT', 'nullable' => false],
                ['name' => 'what', 'type' => 'VARCHAR(80)', 'nullable' => true],
            ]],
        ]);

        $done = $this->migrator($pkg, 'Acme\\Billing\\Config\\PgDb')->migrate();

        self::assertSame('created', $done[0]['action']);              // fresh for its owner
        self::assertSame(0, $this->rowCount($audit));                // its table is there
        self::assertSame(['V001'], array_keys($this->rows()));
        $other = new SchemaHistory($this->db, $this->driver, 'Acme\\Billing\\Config\\PgDb', $this->config->getSchema());
        self::assertSame(['V001'], array_keys($other->rows()));
        self::assertNotSame($this->rows()['V001']['name'], $other->rows()['V001']['name']);
        self::assertSame([], $this->migrator()->migrate());          // neither sees the other's V001
    }

    public function test_every_portable_index_is_built_on_a_fresh_database_and_on_an_upgrade(): void
    {
        $articles = ($this->tableSchema() !== null ? $this->tableSchema() . '.' : '') . 'articles';
        $map = fn(string $class) => ['articles' => $class];

        // Upgrade: the table first, with rows, then every index at once.
        $this->snapshot($map(F\ArticleBare::class), 'bare');
        $this->migrator()->migrate();
        $this->db->exec("INSERT INTO {$articles} (title, views, slug) VALUES ('a', 1, 'a'), ('b', 0, 'b')");
        $this->snapshot($map(F\ArticlePortable::class), 'indexes');
        $done = $this->migrator()->migrate();

        self::assertSame('applied', $done[0]['action']);
        self::assertSame([], $this->differences());
        $built = $this->indexNames($articles);
        foreach (['articles_title_idx', 'articles_title_hash_idx', 'articles_views_idx', 'articles_views_cover_idx', 'articles_slug_udx'] as $name) {
            self::assertContains($name, $built, "{$name} on {$this->driver}");
        }
        self::assertSame(2, $this->rowCount($articles));

        // Fresh: the same history, an empty database.
        $this->reset();
        $this->migrator()->migrate();
        self::assertSame([], $this->differences());
        self::assertEqualsCanonicalizing($built, $this->indexNames($articles));
    }

    /** @return list<string> */
    protected function indexNames(string $table): array
    {
        $t = new SchemaInspector($this->db, $this->driver)->table($table);
        return $t === null ? [] : array_values($t['indexes']);
    }

    public function test_a_second_instance_waits_for_the_first(): void
    {
        if ($this->driver === 'sqlite') {
            self::markTestSkipped('SQLite: the writer takes the file, there is no schema lock.');
        }
        $this->v1();
        $other = $this->config::class;
        $second = new $other();
        $second->setUp();
        $holder = new SchemaHistory($second->connection(), $this->driver, self::OWNER, $this->config->getSchema());
        $holder->ensure();
        $holder->lock();
        try {
            $this->expectExceptionMessageMatches('/lock was not released within 1 s/');
            $this->migrator()->migrate(lockTimeout: 1);
        } finally {
            $holder->unlock();
            self::assertStringContainsString('waiting for the schema lock', implode("\n", array_column($this->log, 1)));
        }
    }

    public function test_with_no_limit_it_waits_and_says_so_until_the_holder_dies(): void
    {
        if ($this->driver === 'sqlite') {
            self::markTestSkipped('SQLite: the writer takes the file, there is no schema lock.');
        }
        $history = new SchemaHistory($this->db, $this->driver, self::OWNER, $this->config->getSchema());
        $history->ensure();
        $held = sys_get_temp_dir() . '/ppa_lock_' . getmypid() . '_' . bin2hex(random_bytes(4));
        $other = $this->config::class;

        $pid = pcntl_fork();
        if ($pid === 0) {
            // Another instance: takes the lock and dies holding it — no unlock, a SIGKILL.
            $second = new $other();
            $second->setUp();
            new SchemaHistory($second->connection(), $this->driver, self::OWNER, $this->config->getSchema())->lock();
            touch($held);
            sleep(2);
            posix_kill(getmypid(), SIGKILL);
        }
        try {
            for ($i = 0; $i < 100 && !is_file($held); $i++) {
                usleep(50_000);
            }
            self::assertFileExists($held, 'the other instance took the lock');

            $said = [];
            $t0 = microtime(true);
            $history->lock(timeout: 0, waiting: function (int $waited) use (&$said): void {
                $said[] = $waited;
            }, every: 1);
            $history->unlock();

            self::assertGreaterThanOrEqual(1.0, microtime(true) - $t0, 'it waited');
            self::assertSame(0, $said[0], 'said at once');
            self::assertGreaterThanOrEqual(2, count($said), 'and again while it waited');
            self::assertSame(range(0, count($said) - 1), $said, 'once a second');
        } finally {
            pcntl_waitpid($pid, $status);
            @unlink($held);
        }
    }

    public function test_a_limit_is_reported_every_slice_and_then_refused(): void
    {
        if ($this->driver === 'sqlite') {
            self::markTestSkipped('SQLite: the writer takes the file, there is no schema lock.');
        }
        $other = $this->config::class;
        $second = new $other();
        $second->setUp();
        $holder = new SchemaHistory($second->connection(), $this->driver, self::OWNER, $this->config->getSchema());
        $holder->ensure();
        $holder->lock();
        $said = [];
        try {
            new SchemaHistory($this->db, $this->driver, self::OWNER, $this->config->getSchema())
                ->lock(timeout: 2, waiting: function (int $waited) use (&$said): void {
                    $said[] = $waited;
                }, every: 1);
            self::fail('refused after 2 s');
        } catch (SchemaException $e) {
            self::assertStringContainsString('not released within 2 s', $e->getMessage());
        } finally {
            $holder->unlock();
        }
        self::assertSame([0, 1], $said);
    }

    // ── The application's history: three versions ───────────────────────────

    protected function v1(): SchemaVersionFile
    {
        return $this->snapshot(['customers' => F\CustomerV1::class, 'orders' => F\OrderV1::class], 'v1');
    }

    /** V2: unique e-mail (before() removes duplicates), note → comment, status, a CHECK. */
    protected function v2(?string $after = null): SchemaVersionFile
    {
        $v = $this->snapshot(['customers' => F\CustomerV2::class, 'orders' => F\OrderV2::class], 'v2', custom: true);
        $this->code(
            $v,
            before: '$db->exec("DELETE FROM customers WHERE id NOT IN (SELECT id FROM (SELECT MIN(id) AS id FROM customers GROUP BY email) k)");',
            after: $after ?? $this->v2After($v),
        );
        return $v;
    }

    /**
     * What V2's after() does: the status of the big orders.
     */
    protected function v2After(SchemaVersionFile $v): string
    {
        $code = "\$db->exec(\"UPDATE orders SET status = 'done' WHERE total > 100\");";
        return $code;       // SQLite's CHECK too: the migrator rebuilds the table
    }

    /** V3: a foreign key to customers, a wider total — not on SQLite (it cannot alter either). */
    protected function v3(): SchemaVersionFile
    {
        return $this->snapshot(['customers' => F\CustomerV2::class, 'orders' => F\OrderV3::class], 'v3');
    }

    protected function supportsV3(): bool
    {
        return $this->driver !== 'sqlite';
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    protected function snapshot(array $map, string $name, bool $custom = false): SchemaVersionFile
    {
        $diff = SchemaSnapshot::diff($this->history, F\SchemaFixtures::tables($this->driver, $map, $this->tableSchema()), $this->driver, custom: $custom);
        self::assertSame([], $diff->errors, "snapshot {$name}");
        SchemaSnapshot::write($this->history, $diff, $this->driver, $name, $custom);
        $versions = $this->history->versions();
        return end($versions);
    }

    /** Writes the version's code: a class of its own name — classes cannot be declared twice. */
    protected function code(SchemaVersionFile $v, string $before = '', string $after = ''): void
    {
        $class = 'Code' . bin2hex(random_bytes(5));
        file_put_contents((string) $v->phpPath, <<<PHP
            <?php
            use Flytachi\\Winter\\Cdo\\Connection\\CDO;
            use Flytachi\\Winter\\Ppa\\Schema\\SchemaVersion;
            use Flytachi\\Winter\\Ppa\\Schema\\VersionCode;

            #[SchemaVersion('{$v->version}')]
            final class {$class} extends VersionCode
            {
                public function before(CDO \$db): void { {$before} }
                public function after(CDO \$db): void { {$after} }
            }
            PHP);
    }

    protected function tableSchema(): ?string
    {
        return null;
    }

    protected function migrator(?SchemaDirectory $dir = null, string $owner = self::OWNER): SchemaMigrator
    {
        $test = $this;
        $logger = new class ($test) extends AbstractLogger {
            public function __construct(private MigrationScenarios $test)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->test->collect((string) $level, (string) $message);
            }
        };
        return new SchemaMigrator($this->db, $this->driver, $dir ?? $this->history, $owner, $this->config->getSchema(), $logger, 'MainDbConfig');
    }

    public function collect(string $level, string $message): void
    {
        $this->log[] = [$level, $message];
    }

    /** @return list<string> */
    protected function differences(): array
    {
        $model = SchemaModel::fold($this->history->versions());
        return new SchemaInspector($this->db, $this->driver)->compare($model)['differences'];
    }

    protected function schema(): array
    {
        $inspector = new SchemaInspector($this->db, $this->driver);
        $out = [];
        foreach (array_keys(SchemaModel::fold($this->history->versions())) as $t) {
            $out[$t] = $inspector->table($t);
        }
        return $out;
    }

    protected function rows(): array
    {
        return new SchemaHistory($this->db, $this->driver, self::OWNER, $this->config->getSchema())->rows();
    }

    protected function col(string $sql): array
    {
        return array_map(fn($v) => is_string($v) || $v === null ? $v : (string) $v, $this->db->query($sql)->fetchAll(\PDO::FETCH_COLUMN));
    }

    protected function rowCount(string $table): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    protected function assertRefused(string $sql, string $why): void
    {
        try {
            $this->db->exec($sql);
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
            return;
        }
        self::fail("accepted, but {$why}: {$sql}");
    }
}
