<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Tests\Mapping\Attributes\Hybrid;

use Flytachi\Winter\Ppa\Mapping\Attributes\Additive\DefaultVal;
use Flytachi\Winter\Ppa\Mapping\Attributes\Additive\NullableIs;
use Flytachi\Winter\Ppa\Mapping\Attributes\Hybrid\BigId;
use Flytachi\Winter\Ppa\Mapping\Attributes\Hybrid\Id;
use Flytachi\Winter\Ppa\Mapping\Attributes\Hybrid\SmallId;
use Flytachi\Winter\Ppa\Mapping\Attributes\Hybrid\UuidPk;
use Flytachi\Winter\Ppa\Mapping\Attributes\Idx\Primary;
use Flytachi\Winter\Ppa\Mapping\Attributes\Primal\BigInteger;
use Flytachi\Winter\Ppa\Mapping\Attributes\Primal\Integer;
use Flytachi\Winter\Ppa\Mapping\Attributes\Primal\SmallInteger;
use Flytachi\Winter\Ppa\Mapping\Attributes\Primal\Uuid;
use Flytachi\Winter\Ppa\Mapping\Attributes\Sub\AutoIncrement;
use PHPUnit\Framework\TestCase;

final class HybridTypesTest extends TestCase
{
    // ── Id → [Primary, AutoIncrement, NullableIs(false), Integer] ───────────

    public function test_id_expands_to_four_attributes_in_canonical_order(): void
    {
        $instances = (new Id())->getInstances('mysql');
        self::assertCount(4, $instances);
        self::assertInstanceOf(Primary::class, $instances[0]);
        self::assertInstanceOf(AutoIncrement::class, $instances[1]);
        self::assertInstanceOf(NullableIs::class, $instances[2]);
        self::assertInstanceOf(Integer::class, $instances[3]);
    }

    public function test_id_passes_always_flag_through_to_auto_increment(): void
    {
        // `always: true` propagates: AutoIncrement->toSql produces GENERATED ALWAYS form.
        $always = (new Id(always: true))->getInstances('pgsql');
        /** @var AutoIncrement $ai */
        $ai = $always[1];
        self::assertSame('INT GENERATED ALWAYS AS IDENTITY', $ai->toSql('INT', 'pgsql'));
    }

    // ── BigId — same shape, BigInteger underneath ────────────────────────────

    public function test_big_id_uses_big_integer(): void
    {
        $instances = (new BigId())->getInstances('mysql');
        self::assertInstanceOf(Primary::class, $instances[0]);
        self::assertInstanceOf(AutoIncrement::class, $instances[1]);
        self::assertInstanceOf(NullableIs::class, $instances[2]);
        self::assertInstanceOf(BigInteger::class, $instances[3]);
    }

    public function test_big_id_always_propagates(): void
    {
        $instances = (new BigId(always: true))->getInstances('pgsql');
        /** @var AutoIncrement $ai */
        $ai = $instances[1];
        self::assertSame('BIGINT GENERATED ALWAYS AS IDENTITY', $ai->toSql('BIGINT', 'pgsql'));
    }

    // ── SmallId — same shape, SmallInteger underneath ────────────────────────

    public function test_small_id_uses_small_integer(): void
    {
        $instances = (new SmallId())->getInstances('mysql');
        self::assertInstanceOf(SmallInteger::class, $instances[3]);
    }

    // ── UuidPk — different shape; dialect-aware default ─────────────────────

    public function test_uuid_pk_pgsql_uses_gen_random_uuid_default(): void
    {
        $instances = (new UuidPk())->getInstances('pgsql');
        self::assertCount(4, $instances);
        self::assertInstanceOf(Primary::class, $instances[0]);
        self::assertInstanceOf(Uuid::class, $instances[1]);
        self::assertInstanceOf(NullableIs::class, $instances[2]);
        self::assertInstanceOf(DefaultVal::class, $instances[3]);

        // Use the preparation byref hook to read the default the way ColumnMapping does.
        $nullable = null;
        $default = null;
        $instances[3]->preparation($nullable, $default);
        self::assertSame('gen_random_uuid()', $default);
    }

    private static function uuidDefault(string $dialect): ?string
    {
        $nullable = null;
        $default = null;
        (new UuidPk())->getInstances($dialect)[3]->preparation($nullable, $default);
        return $default;
    }

    public function test_uuid_pk_mysql_wraps_the_function_in_parentheses(): void
    {
        // A bare DEFAULT UUID() is a syntax error on MySQL 8; MariaDB takes both forms.
        self::assertSame('(UUID())', self::uuidDefault('mysql'));
    }

    public function test_uuid_pk_sqlite_builds_a_v4_uuid_from_random_bytes(): void
    {
        $default = self::uuidDefault('sqlite');
        self::assertStringNotContainsString('UUID()', $default, 'SQLite has no UUID function');

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE t (id TEXT NOT NULL DEFAULT {$default}, n INT, PRIMARY KEY (id))");
        for ($i = 0; $i < 200; $i++) {
            $pdo->exec('INSERT INTO t (n) VALUES (1)');
        }
        $ids = $pdo->query('SELECT id FROM t')->fetchAll(\PDO::FETCH_COLUMN);

        self::assertCount(200, array_unique($ids));
        foreach ($ids as $id) {
            self::assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $id,
            );
        }
    }

    public function test_uuid_pk_refuses_an_unknown_dialect(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("'oracle'");

        (new UuidPk())->getInstances('oracle');
    }
}
