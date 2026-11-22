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

        $done = $this->migrator()->migrate();

        self::assertSame(['baseline', 'applied'], array_column($done, 'action'));
        self::assertSame(['from before'], $this->col('SELECT comment FROM orders'));
        self::assertSame([], $this->differences());
    }

    public function test_the_history_keeps_a_copy_of_what_each_version_applied(): void
    {
        $v1 = $this->v1();
        $this->migrator()->migrate();

        $history = new SchemaHistory($this->db, $this->driver, self::OWNER, $this->config->getSchema());
        self::assertSame(file_get_contents($v1->jsonPath), $history->changesOf($v1->version));
        self::assertSame($v1->checksum, $this->rows()[$v1->version]['checksum']);
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
     * What V2's after() does: the status of the big orders — and on SQLite the CHECK on the
     * total, which it cannot add to a table that exists ("by": "code"): the table rebuilt.
     */
    protected function v2After(SchemaVersionFile $v): string
    {
        $code = "\$db->exec(\"UPDATE orders SET status = 'done' WHERE total > 100\");";
        return $this->driver === 'sqlite' ? $code . $this->sqliteRebuild('orders') : $code;
    }

    /**
     * A table rebuilt the SQLite way, as a version's code does it: the new table from the
     * model (its constraints inside the CREATE TABLE, named), the rows copied, the old
     * dropped, its indexes made again.
     */
    protected function sqliteRebuild(string $table): string
    {
        $model = [$table => SchemaModel::fold($this->history->versions())[$table]];
        $sql = SchemaSql::create($model, 'sqlite');
        $columns = implode(', ', array_keys(SchemaModel::live($model)[$table]['columns']));
        $steps = [
            "ALTER TABLE {$table} RENAME TO {$table}_old",
            $sql[0],
            "INSERT INTO {$table} ({$columns}) SELECT {$columns} FROM {$table}_old",
            "DROP TABLE {$table}_old",
            ...array_slice($sql, 1),
        ];
        return implode('', array_map(fn(string $q) => ' $db->exec(' . var_export($q, true) . ');', $steps));
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
