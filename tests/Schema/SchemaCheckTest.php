<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Tests\Schema;

use Flytachi\Winter\Ppa\Mapping\Structure\Extension;
use Flytachi\Winter\Ppa\Schema\SchemaCheck;
use Flytachi\Winter\Ppa\Schema\SchemaDirectory;
use Flytachi\Winter\Ppa\Schema\SchemaModel;
use Flytachi\Winter\Ppa\Schema\SchemaSnapshot;
use Flytachi\Winter\Ppa\Schema\SchemaSql;
use Flytachi\Winter\Ppa\Tests\Schema\Fixtures as F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures/SchemaFixtures.php';
require_once __DIR__ . '/Fixtures/IndexEntities.php';

/**
 * What a server would not build as declared, said before a snapshot records it — and the
 * schemas and extensions a version creates beside the tables.
 */
final class SchemaCheckTest extends TestCase
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

    // ── Indexes ─────────────────────────────────────────────────────────────

    public function test_portable_indexes_are_no_error_anywhere(): void
    {
        foreach (['pgsql', 'mysql', 'sqlite'] as $driver) {
            self::assertSame([], $this->check(F\ArticlePortable::class, $driver)['errors'], $driver);
        }
        self::assertSame([], $this->check(F\ArticlePortable::class, 'pgsql')['notices']);
    }

    public function test_what_mysql_leaves_out_is_said(): void
    {
        $notices = implode("\n", $this->check(F\ArticlePortable::class, 'mysql')['notices']);

        self::assertStringContainsString('articles_title_hash_idx: HASH is built as a B-tree', $notices);
        self::assertStringContainsString('articles_views_idx: MySQL / MariaDB have no partial index — built over every row', $notices);
        self::assertStringContainsString('INCLUDE (id) is PostgreSQL\'s — left out on mysql', $notices);
    }

    public function test_sqlite_builds_one_kind_of_index_and_says_so(): void
    {
        $notices = implode("\n", $this->check(F\ArticlePortable::class, 'sqlite')['notices']);

        self::assertStringContainsString('articles_title_hash_idx: SQLite has one kind of index', $notices);
        self::assertStringNotContainsString('partial', $notices);           // SQLite has them
    }

    /** @return iterable<string, array{class-string, string, string}> */
    public static function refused(): iterable
    {
        yield 'GIN on MySQL' => [F\ArticleSearch::class, 'mysql', 'have no GIN index'];
        yield 'GIN on a VARCHAR' => [F\ArticleGinText::class, 'pgsql', "no GIN operator class for title (VARCHAR(100)) — for text search give it opClass: 'gin_trgm_ops'"];
        yield 'GiST on an INT' => [F\ArticleGistInt::class, 'pgsql', "declare #[Extension('btree_gist')]"];
        yield 'trigram without pg_trgm' => [F\ArticleSearch::class, 'pgsql', "comes with the extension pg_trgm: declare #[Extension('pg_trgm')]"];
        yield 'HASH of two columns' => [F\ArticleHashTwoColumns::class, 'pgsql', 'a HASH index has one column on PostgreSQL'];
        yield 'unique HASH, PostgreSQL' => [F\ArticleUniqueHash::class, 'pgsql', 'a unique index is a B-tree'];
        yield 'unique HASH, MySQL' => [F\ArticleUniqueHash::class, 'mysql', 'a unique index is a B-tree'];
        yield 'partial unique on MySQL' => [F\ArticleUniqueWhere::class, 'mysql', 'the unique index would hold over every row'];
        yield 'two indexes of one name' => [F\ArticleTwinIndexes::class, 'pgsql', 'two indexes are named articles_title_idx'];
        yield 'an index of no column' => [F\ArticleIndexOfNoColumn::class, 'pgsql', 'slug_old is no column of articles — renamed or gone?'];
        yield 'SET DEFAULT on MySQL' => [F\ArticleSetDefault::class, 'mysql', 'articles.author_id: ON UPDATE SET DEFAULT is not kept by MySQL / MariaDB'];
    }

    public function test_set_default_is_kept_by_postgresql(): void
    {
        self::assertSame([], $this->check(F\ArticleSetDefault::class, 'pgsql')['errors']);
    }

    #[DataProvider('refused')]
    public function test_what_a_server_would_not_build_is_an_error(string $class, string $driver, string $why): void
    {
        self::assertStringContainsString($why, implode("\n", $this->check($class, $driver)['errors']));
    }

    public function test_an_extension_declared_lets_gin_and_gist_through(): void
    {
        self::assertSame([], $this->check(F\ArticleSearch::class, 'pgsql', ['pg_trgm'])['errors']);
        self::assertSame([], $this->check(F\ArticleGistInt::class, 'pgsql', ['btree_gist'])['errors']);
        self::assertSame([], $this->check(F\ArticleGinText::class, 'pgsql', ['btree_gin'])['errors']);
    }

    public function test_gin_over_an_array_needs_no_extension(): void
    {
        $tables = F\SchemaFixtures::tables('pgsql', ['articles' => F\ArticleSearch::class]);
        $tables[0]->columns[2]->type = 'TEXT[]';         // tags: an array, not JSONB

        $errors = SchemaCheck::tables($tables, 'pgsql', ['pg_trgm'])['errors'];

        self::assertSame([], $errors);
    }

    public function test_a_partial_unique_index_is_fine_where_it_exists(): void
    {
        self::assertSame([], $this->check(F\ArticleUniqueWhere::class, 'pgsql')['errors']);
        self::assertSame([], $this->check(F\ArticleUniqueWhere::class, 'sqlite')['errors']);
    }

    public function test_a_snapshot_refuses_what_the_check_refuses(): void
    {
        $diff = SchemaSnapshot::diff(new SchemaDirectory($this->dir), F\SchemaFixtures::tables('mysql', ['articles' => F\ArticleUniqueWhere::class]), 'mysql');

        self::assertStringContainsString('would hold over every row', implode("\n", $diff->errors));
    }

    // ── Schemas and extensions ──────────────────────────────────────────────

    public function test_a_version_creates_the_schema_and_the_extension_first(): void
    {
        $dir = new SchemaDirectory($this->dir);
        $tables = F\SchemaFixtures::tables('pgsql', ['articles' => F\ArticleSearch::class], 'shop');
        $diff = SchemaSnapshot::diff($dir, $tables, 'pgsql', extensions: [new Extension('pg_trgm')]);

        self::assertSame([], $diff->errors);
        self::assertSame(['createSchema', 'createExtension', 'createTable'], array_slice(array_column($diff->changes, 'op'), 0, 3));
        self::assertSame('CREATE EXTENSION IF NOT EXISTS "pg_trgm"', $diff->changes[1]['sql']);

        SchemaSnapshot::write($dir, $diff, 'pgsql');
        $again = SchemaSnapshot::diff($dir, $tables, 'pgsql', extensions: [new Extension('pg_trgm')]);
        self::assertTrue($again->isEmpty(), 'recorded once');

        $fresh = SchemaSql::create(SchemaModel::fold($dir->versions()), 'pgsql', SchemaModel::objects($dir->versions()));
        self::assertSame(['CREATE SCHEMA shop', 'CREATE EXTENSION IF NOT EXISTS "pg_trgm"'], array_slice($fresh, 0, 2));
        self::assertStringStartsWith('CREATE TABLE shop.articles', $fresh[2]);
    }

    public function test_public_is_no_schema_to_create(): void
    {
        $tables = F\SchemaFixtures::tables('pgsql', ['articles' => F\ArticlePortable::class], 'public');
        $diff = SchemaSnapshot::diff(new SchemaDirectory($this->dir), $tables, 'pgsql');

        self::assertNotContains('createSchema', array_column($diff->changes, 'op'));
    }

    public function test_an_extension_no_longer_declared_stays_and_is_said(): void
    {
        $dir = new SchemaDirectory($this->dir);
        $tables = F\SchemaFixtures::tables('pgsql', ['articles' => F\ArticlePortable::class]);
        SchemaSnapshot::write($dir, SchemaSnapshot::diff($dir, $tables, 'pgsql', extensions: [new Extension('pg_trgm')]), 'pgsql');

        $diff = SchemaSnapshot::diff($dir, $tables, 'pgsql');

        self::assertTrue($diff->isEmpty());
        self::assertStringContainsString('Extension pg_trgm is not declared any more: it stays', implode("\n", $diff->notices));
    }

    public function test_extensions_and_schemas_are_postgresql_s(): void
    {
        $tables = F\SchemaFixtures::tables('mysql', ['articles' => F\ArticlePortable::class], 'shop');
        $diff = SchemaSnapshot::diff(new SchemaDirectory($this->dir), $tables, 'mysql', extensions: [new Extension('pg_trgm')]);

        self::assertNotContains('createSchema', array_column($diff->changes, 'op'));
        self::assertNotContains('createExtension', array_column($diff->changes, 'op'));
    }

    public function test_an_extension_is_dropped_only_by_drop_and_last(): void
    {
        $dir = new SchemaDirectory($this->dir);
        $tables = F\SchemaFixtures::tables('pgsql', ['articles' => F\ArticlePortable::class]);
        SchemaSnapshot::write($dir, SchemaSnapshot::diff($dir, $tables, 'pgsql', extensions: [new Extension('pg_trgm')]), 'pgsql');
        $bare = F\SchemaFixtures::tables('pgsql', ['articles' => F\ArticleBare::class]);

        $diff = SchemaSnapshot::diff($dir, $bare, 'pgsql', drop: ['extension:pg_trgm']);

        self::assertSame('dropExtension', end($diff->changes)['op'], 'after the indexes that may use it');
        self::assertSame(['DROP EXTENSION IF EXISTS "pg_trgm"'], SchemaSql::change(end($diff->changes), 'pgsql'));
        SchemaSnapshot::write($dir, $diff, 'pgsql');
        self::assertSame([], SchemaModel::objects($dir->versions())['extensions']);
        self::assertStringContainsString('extension:nope (--drop) is not in the history', implode("\n", SchemaSnapshot::diff($dir, $bare, 'pgsql', drop: ['extension:nope'])->notices));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function identities(): iterable
    {
        yield 'BY DEFAULT → ALWAYS' => ['INT GENERATED BY DEFAULT AS IDENTITY', 'INT GENERATED ALWAYS AS IDENTITY', true];
        yield 'ALWAYS → BY DEFAULT, wider' => ['SMALLINT GENERATED ALWAYS AS IDENTITY', 'BIGINT GENERATED BY DEFAULT AS IDENTITY', true];
        yield 'narrower' => ['BIGINT GENERATED BY DEFAULT AS IDENTITY', 'INT GENERATED ALWAYS AS IDENTITY', false];
        yield 'identity added' => ['INT', 'INT GENERATED ALWAYS AS IDENTITY', false];
        yield 'the same mode' => ['INT GENERATED ALWAYS AS IDENTITY', 'INT GENERATED ALWAYS AS IDENTITY', false];
    }

    #[DataProvider('identities')]
    public function test_how_an_identity_is_generated_is_changed_by_the_kernel(string $from, string $to, bool $kernel): void
    {
        self::assertSame($kernel, \Flytachi\Winter\Ppa\Schema\SchemaDiff::identityMode($from, $to));
    }

    public function test_an_identity_mode_is_set_generated(): void
    {
        $sql = SchemaSql::change(['op' => 'alterColumn', 'table' => 'audit_log', 'column' => 'id',
            'from' => ['name' => 'id', 'type' => 'SMALLINT GENERATED BY DEFAULT AS IDENTITY', 'nullable' => false],
            'to' => ['name' => 'id', 'type' => 'INT GENERATED ALWAYS AS IDENTITY', 'nullable' => false]], 'pgsql');

        self::assertSame([
            'ALTER TABLE audit_log ALTER COLUMN id TYPE INT',
            'ALTER TABLE audit_log ALTER COLUMN id SET GENERATED ALWAYS',
        ], $sql);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @return array{errors: list<string>, notices: list<string>} */
    private function check(string $class, string $driver, array $extensions = []): array
    {
        return SchemaCheck::tables(F\SchemaFixtures::tables($driver, ['articles' => $class]), $driver, $extensions);
    }
}
