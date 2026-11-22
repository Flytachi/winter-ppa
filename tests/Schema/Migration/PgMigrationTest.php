<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Tests\Schema\Migration;

use Flytachi\Winter\Cdo\Config\Common\DbConfigInterface;
use Flytachi\Winter\Ppa\Mapping\Structure\Extension;
use Flytachi\Winter\Ppa\Schema\SchemaSnapshot;
use Flytachi\Winter\Ppa\Tests\Schema\Fixtures as F;
use Flytachi\Winter\Ppa\Tests\Schema\Fixtures\PgTestConfig;
use PHPUnit\Framework\Attributes\Group;

require_once __DIR__ . '/MigrationScenarios.php';

/** The scenarios on PostgreSQL — PPA_PG=host:port:database:user:password. */
#[Group('integration')]
final class PgMigrationTest extends MigrationScenarios
{
    protected function config(): DbConfigInterface
    {
        if (getenv('PPA_PG') === false) {
            self::markTestSkipped('PostgreSQL: set PPA_PG=host:port:database:user:password.');
        }
        return new PgTestConfig();
    }

    protected function reset(): void
    {
        $this->db->exec('DROP EXTENSION IF EXISTS pg_trgm CASCADE');
        $this->db->exec('DROP SCHEMA IF EXISTS shop CASCADE');
        $this->db->exec('DROP SCHEMA IF EXISTS public CASCADE');
        $this->db->exec('CREATE SCHEMA public');
    }

    // As an application's repositories: the tables in the config's schema.
    protected function tableSchema(): ?string
    {
        return 'public';
    }

    // ── PostgreSQL's own ────────────────────────────────────────────────────

    public function test_a_schema_and_an_extension_come_with_their_versions(): void
    {
        $trgm = [new Extension('pg_trgm')];
        // V001: a table in its own schema. V002: text search on it — pg_trgm and a GIN index.
        $this->record(['articles' => F\ArticleSearchBare::class], [], 'articles');
        $this->migrator()->migrate();
        $this->db->exec("INSERT INTO shop.articles (title) VALUES ('winter'), ('summer')");
        $this->record(['articles' => F\ArticleSearch::class], $trgm, 'search');

        $done = $this->migrator()->migrate();

        self::assertSame([['version' => 'V002', 'action' => 'applied']], array_map(
            fn(array $d) => ['version' => $d['version'], 'action' => $d['action']],
            $done,
        ));
        self::assertSame([], $this->differences());
        self::assertSame(['pg_trgm'], $this->col("SELECT extname FROM pg_extension WHERE extname = 'pg_trgm'"));
        self::assertSame(['winter'], $this->col("SELECT title FROM shop.articles WHERE title % 'wintr'"));

        // Fresh: the schema and the extension first, from the same history.
        $this->reset();
        $this->migrator()->migrate();
        self::assertSame([], $this->differences());
        self::assertSame(['shop'], $this->col("SELECT schema_name FROM information_schema.schemata WHERE schema_name = 'shop'"));
        self::assertSame(['pg_trgm'], $this->col("SELECT extname FROM pg_extension WHERE extname = 'pg_trgm'"));
    }

    public function test_an_extension_already_there_is_not_created_again(): void
    {
        $this->record(['articles' => F\ArticleSearchBare::class], [], 'articles');
        $this->migrator()->migrate();
        $this->db->exec('CREATE EXTENSION pg_trgm');     // a DBA was faster
        $this->record(['articles' => F\ArticleSearch::class], [new Extension('pg_trgm')], 'search');

        self::assertSame('applied', $this->migrator()->migrate()[0]['action']);
        self::assertSame([], $this->differences());
    }

    /** A version of the entities in the schema `shop`, with the config's extensions. */
    private function record(array $map, array $extensions, string $name): void
    {
        $diff = SchemaSnapshot::diff(
            $this->history,
            F\SchemaFixtures::tables('pgsql', $map, 'shop'),
            'pgsql',
            extensions: $extensions,
        );
        self::assertSame([], $diff->errors, "snapshot {$name}");
        SchemaSnapshot::write($this->history, $diff, 'pgsql', $name);
    }
}
