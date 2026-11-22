<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\E2e;

/**
 * The life of an application on PostgreSQL, snapshot by snapshot.
 *
 * Stage 1 (V001–V005): six tables with every attribute, then a column added, renamed,
 * dropped — and back to the first shape in one version.
 * Stage 2: data ({@see Data}).
 * Stage 3 (V006–V022): every index and foreign key remade, types widened and narrowed,
 * NOT NULL over NULLs, a unique index over duplicates, a primary key changed, tables made
 * and dropped, code-only versions — on tables full of rows.
 *
 * A check gets the database and whether it holds the data of stage 2 (variant B has none).
 */
final class Scenario
{
    public const int STAGE_1 = 5;

    /** @return list<Step> */
    public static function steps(): array
    {
        $s = self::initial();
        $steps = [];

        // ── Stage 1 ─────────────────────────────────────────────────────────

        $steps[] = new Step(1, 'init', $s, 'six tables, every attribute', expect: ['createTable' => 6, 'createSchema' => 1, 'createExtension' => 1]);

        $s = $s->with('users', 'phone', ['#[Varchar(32)]'], '?string $phone = null', after: 'email')
            ->with('groups', 'position', ['#[Integer]'], 'int $position = 0')
            ->with('products', 'color', ['#[Varchar(20)]', "#[DefaultVal(\"'black'\")]"], 'string $color = \'black\'')
            ->with('orders', 'discount', ['#[Decimal(5, 2)]'], "string \$discount = '0'");
        $steps[] = new Step(1, 'add_columns', $s, 'a column added to four tables', expect: ['addColumn' => 4]);

        $s = $s->renamed('users', 'phone', 'mobile', ['#[Varchar(32)]', "#[RenamedFrom('phone')]"], '?string $mobile = null')
            ->renamed('products', 'color', 'colour', ['#[Varchar(20)]', "#[DefaultVal(\"'black'\")]", "#[RenamedFrom('color')]"], 'string $colour = \'black\'')
            ->renamed('orders', 'note', 'comment', ['#[Text]', "#[RenamedFrom('note')]"], '?string $comment = null');
        $steps[] = new Step(1, 'rename_columns', $s, 'three columns renamed', expect: ['renameColumn' => 3, 'addColumn' => 0, 'dropColumn' => 0]);

        $s = self::clean($s)
            ->without('users', 'mobile')->without('users', 'born')->without('groups', 'position')->without('products', 'colour');
        $steps[] = new Step(
            1,
            'drop_columns',
            $s,
            'three columns dropped (--drop), one left in the database (--keep)',
            drop: ['public.users.mobile', 'public.users.born', 'public.groups.position'],
            keep: ['shop.products.colour'],
            expect: ['dropColumn' => 3, 'detachColumn' => 1],
        );

        $s = $s->with('users', 'born', ['#[Date]'], '?string $born = null')
            ->renamed('orders', 'comment', 'note', ['#[Text]', "#[RenamedFrom('comment')]"], '?string $note = null')
            ->without('orders', 'discount');
        $steps[] = new Step(
            1,
            'back_to_the_start',
            $s,
            'added, renamed and dropped in one version: the shape of V001 again',
            drop: ['public.orders.discount'],
            expect: ['addColumn' => 1, 'renameColumn' => 1, 'dropColumn' => 1],
            check: static function (\PDO $db): void {
                expect(one($db, "SELECT count(*) FROM information_schema.columns WHERE table_name = 'orders' AND column_name IN ('comment', 'discount')") == 0, 'orders is back to its columns of V001');
            },
        );

        // ── Stage 3 ─────────────────────────────────────────────────────────
        $s = self::clean($s);

        $s = $s->attrs('users', 'status', fn($a) => self::swap($a, "#[Index(where: \"status <> 'deleted'\")]", "#[Index(where: \"status IN ('active', 'new')\")]"))
            ->attrs('orders', 'paid', fn($a) => self::swap($a, "#[Index(where: 'paid = false', name: 'unpaid')]", "#[Index(where: 'paid = false AND total > 0', name: 'unpaid')]"));
        $steps[] = new Step(3, 'partial_indexes', $s, 'the WHERE of two partial indexes changed: dropped and made again', expect: ['dropIndex' => 2, 'addIndex' => 2], check: static function (\PDO $db): void {
            expect(str_contains((string) one($db, "SELECT indexdef FROM pg_indexes WHERE indexname = 'orders_unpaid_idx'"), 'total > '), 'orders_unpaid_idx has its new WHERE');
        });

        $s = $s->withExtension('btree_gist')
            ->attrs('products', 'title', fn($a) => self::swap($a, '#[Index(method: IndexMethod::HASH)]', "#[Index(method: IndexMethod::GIN, opClass: 'gin_trgm_ops')]"))
            ->attrs('audit_log', 'level', fn($a) => [...$a, '#[Index(method: IndexMethod::GIST)]']);
        $steps[] = new Step(3, 'index_methods', $s, 'HASH → GIN with trigrams; btree_gist and a GiST index on an integer', expect: ['createExtension' => 1, 'dropIndex' => 1, 'addIndex' => 2], check: static function (\PDO $db, bool $data): void {
            expect(one($db, "SELECT count(*) FROM pg_extension WHERE extname = 'btree_gist'") == 1, 'btree_gist is there');
            if ($data) {
                expect(one($db, "SELECT count(*) FROM shop.products WHERE title % 'Product 1'") > 0, 'the trigram index answers');
            }
        });

        $s = $s->attrs('users', 'name', fn($a) => [...$a, '#[Unique]']);
        $steps[] = new Step(3, 'unique_over_duplicates', $s, 'a unique index on a column with duplicates: before() makes them distinct', custom: true, expect: ['addIndex' => 1],
            before: "\$db->exec(\"UPDATE public.users u SET name = u.name || ' #' || u.id WHERE u.id NOT IN (SELECT min(id) FROM public.users GROUP BY name)\");",
            check: static function (\PDO $db, bool $data): void {
                if ($data) {
                    expect(one($db, 'SELECT count(DISTINCT name) FROM public.users') == Data::USERS, 'every user keeps a distinct name');
                    expect(one($db, "SELECT count(*) FROM public.users WHERE name LIKE 'Ann #%'") == 2, 'the two later Anns are renamed');
                }
            });

        $s = $s->attrs('products', 'stock', fn($a) => self::swap($a, "#[Index(['title'], include: ['price'], name: 'stock_cover')]", "#[Index(['title'], include: ['price', 'sku'], name: 'stock_cover')]"))
            ->attrs('users', 'profile', fn($a) => self::swap($a, '#[Index(method: IndexMethod::GIN)]', null))
            ->attrs('orders', 'user_id', fn($a) => [...$a, "#[Index(['placed_at'])]"]);
        $steps[] = new Step(3, 'composite_and_covering', $s, 'INCLUDE widened, a GIN index dropped, a composite index added', expect: ['dropIndex' => 2, 'addIndex' => 2]);

        $s = $s->attrs('orders', 'user_id', fn($a) => self::swap($a, "#[ForeignKey('users', 'id', onUpdate: FKAction::CASCADE, onDelete: FKAction::CASCADE)]", "#[ForeignKey('users', 'id', onUpdate: FKAction::CASCADE, onDelete: FKAction::RESTRICT)]"))
            ->attrs('order_items', 'product_id', fn($a) => self::swap($a, "#[ForeignRepo(ProductRepo::class, onDelete: FKAction::RESTRICT, name: 'fk_items_product')]", "#[ForeignRepo(ProductRepo::class, onDelete: FKAction::CASCADE, name: 'fk_items_product')]"));
        $steps[] = new Step(3, 'foreign_key_actions', $s, 'ON DELETE of two foreign keys changed', expect: ['dropConstraint' => 2, 'addConstraint' => 2], check: static function (\PDO $db): void {
            expect(str_contains((string) one($db, "SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'fk_items_product'"), 'ON DELETE CASCADE'), 'fk_items_product cascades');
        });

        $s = $s->attrs('users', 'group_id', fn($a) => self::swap($a, '#[ForeignRepo(GroupRepo::class, onDelete: FKAction::SET_NULL)]', null))
            ->attrs('audit_log', 'user_ref', fn($a) => [...$a, '#[ForeignRepo(UserRepo::class, onDelete: FKAction::SET_NULL)]']);
        $steps[] = new Step(3, 'foreign_key_over_orphans', $s, 'a foreign key dropped, one added over rows that point nowhere: before() clears them', custom: true, expect: ['dropConstraint' => 1, 'addConstraint' => 1],
            before: "\$db->exec('UPDATE public.audit_log SET user_ref = NULL WHERE user_ref IS NOT NULL AND user_ref NOT IN (SELECT id FROM public.users)');",
            check: static function (\PDO $db, bool $data): void {
                if ($data) {
                    expect(one($db, 'SELECT count(*) FROM public.audit_log WHERE user_ref IS NOT NULL') == Data::AUDIT_VALID_REFS, 'the valid references stay');
                }
            });

        $s = $s->attrs('users', 'name', fn($a) => self::swap($a, '#[Varchar(120)]', '#[Varchar(200)]'))
            ->attrs('orders', 'total', fn($a) => self::swap($a, '#[Decimal(12, 2)]', '#[Decimal(14, 2)]'))
            ->attrs('audit_log', 'level', fn($a) => self::swap($a, '#[SmallInteger]', '#[Integer]'));
        $steps[] = new Step(3, 'widen_types', $s, 'VARCHAR, DECIMAL and an integer widened, by the kernel', expect: ['alterColumn' => 3], check: static function (\PDO $db, bool $data): void {
            expect(one($db, "SELECT character_maximum_length FROM information_schema.columns WHERE table_name = 'users' AND column_name = 'name'") == 200, 'users.name is VARCHAR(200)');
            if ($data) {
                expect(one($db, 'SELECT sum(total) FROM public.orders') == Data::ORDERS_TOTAL, 'the totals are untouched');
            }
        });

        $s = $s->attrs('products', 'sku', fn($a) => self::swap($a, '#[Char(12)]', '#[Varchar(10)]'));
        $steps[] = new Step(3, 'narrow_sku', $s, 'CHAR(12) → VARCHAR(10): a narrowing, made by after()', custom: true, expect: ['alterColumn' => 1],
            after: "\$db->exec('ALTER TABLE shop.products ALTER COLUMN sku TYPE VARCHAR(10) USING left(rtrim(sku), 10)');",
            check: static function (\PDO $db, bool $data): void {
                if ($data) {
                    expect(one($db, 'SELECT count(DISTINCT sku) FROM shop.products') == Data::PRODUCTS, 'every sku is still distinct');
                    expect(one($db, "SELECT sku FROM shop.products ORDER BY sku LIMIT 1") === 'SKU-000001', 'the sku keeps its value, without the padding');
                }
            });

        $s = $s->with('orders', 'note', ['#[Text]', "#[DefaultVal(\"''\")]"], "string \$note = ''");
        $steps[] = new Step(3, 'note_required', $s, 'NOT NULL over NULLs: before() fills them', custom: true, expect: ['alterColumn' => 1],
            before: "\$db->exec(\"UPDATE public.orders SET note = '' WHERE note IS NULL\");",
            check: static function (\PDO $db, bool $data): void {
                expect(one($db, "SELECT is_nullable FROM information_schema.columns WHERE table_name = 'orders' AND column_name = 'note'") === 'NO', 'orders.note is NOT NULL');
                if ($data) {
                    expect(one($db, "SELECT count(*) FROM public.orders WHERE note = ''") == Data::ORDERS_WITHOUT_NOTE, 'the empty notes are filled');
                }
            });

        $s = $s->attrs('users', 'status', fn($a) => self::swap(
            self::swap($a, "#[CheckEnum(UserStatus::class, name: 'chk_users_status')]", "#[CheckEnum(UserStatusV2::class, name: 'chk_users_status')]"),
            "#[DefaultVal(\"'active'\")]",
            "#[DefaultVal(\"'new'\")]",
        ))->decl('users', 'status', "string \$status = 'new'");
        $steps[] = new Step(3, 'status_blocked', $s, 'the CHECK of an enum widened, a default changed', expect: ['dropConstraint' => 1, 'addConstraint' => 1, 'alterColumn' => 1], check: static function (\PDO $db): void {
            $db->exec("INSERT INTO public.users (email, name, status) VALUES ('blocked@e2e.test', 'Blocked', 'blocked')");
            expect(one($db, "SELECT column_default FROM information_schema.columns WHERE table_name = 'users' AND column_name = 'status'") === "'new'::character varying", 'the default is new');
            $db->exec("DELETE FROM public.users WHERE email = 'blocked@e2e.test'");
        });

        $userId = $s->tables['orders']['props']['user_id'][0];
        $s = $s->renamed('orders', 'user_id', 'customer_id', [...$userId, "#[RenamedFrom('user_id')]"], 'int $customer_id');
        $steps[] = new Step(3, 'rename_with_key', $s, 'a column with a foreign key and a composite index renamed', expect: ['renameColumn' => 1, 'dropConstraint' => 1, 'addConstraint' => 1, 'dropIndex' => 1, 'addIndex' => 1], check: static function (\PDO $db, bool $data): void {
            expect(one($db, "SELECT count(*) FROM pg_constraint WHERE conname = 'fk_orders_customer_id'") == 1, 'the foreign key follows the column');
            if ($data) {
                expect(one($db, 'SELECT sum(customer_id) FROM public.orders') == Data::ORDERS_USER_SUM, 'every order keeps its customer');
            }
        });

        $s = self::clean($s)->table('payments', [
            'class' => 'Payment', 'repo' => 'PaymentRepo', 'schema' => 'shop', 'attrs' => [],
            'props' => [
                'id' => [['#[Id]'], '?int $id = null'],
                'order_id' => [['#[BigInteger]', '#[ForeignRepo(OrderRepo::class, onDelete: FKAction::CASCADE)]'], 'int $order_id'],
                'amount' => [['#[Decimal(14, 2)]'], "string \$amount = '0'"],
                'paid_at' => [['#[Timestamp]', "#[DefaultVal('CURRENT_TIMESTAMP')]"], "string \$paid_at = ''"],
            ],
        ]);
        $steps[] = new Step(3, 'payments', $s, 'a new table, filled by after() from the paid orders', custom: true, expect: ['createTable' => 1, 'addConstraint' => 1],
            after: "\$db->exec('INSERT INTO shop.payments (order_id, amount, paid_at) SELECT id, total, placed_at FROM public.orders WHERE paid');",
            check: static function (\PDO $db, bool $data): void {
                if ($data) {
                    expect(one($db, 'SELECT count(*) FROM shop.payments') == Data::ORDERS_PAID, 'a payment per paid order');
                }
            });

        $s = $s->attrs('audit_log', 'happened', fn($a) => [...$a, '#[Primary]']);
        $steps[] = new Step(3, 'audit_key', $s, 'the primary key (id) → (id, happened): by after()', custom: true, expect: ['setPrimaryKey' => 1],
            after: "\$db->exec('ALTER TABLE public.audit_log DROP CONSTRAINT audit_log_pkey, ADD PRIMARY KEY (id, happened)');",
            check: static function (\PDO $db): void {
                expect(one($db, "SELECT count(*) FROM pg_index i JOIN pg_class c ON c.oid = i.indrelid WHERE c.relname = 'audit_log' AND i.indisprimary AND i.indnatts = 2") == 1, 'audit_log has a key of two columns');
            });

        $s = $s->withoutTable('groups')->without('products', 'weight');
        $steps[] = new Step(3, 'drop_groups', $s, 'a table dropped, and a column', drop: ['public.groups', 'shop.products.weight'], expect: ['dropTable' => 1, 'dropColumn' => 1], check: static function (\PDO $db): void {
            expect(one($db, "SELECT count(*) FROM information_schema.tables WHERE table_name = 'groups'") == 0, 'groups is gone');
        });

        $steps[] = new Step(3, 'recount_totals', $s, 'a version of code only: totals recounted from the items', custom: true,
            after: "\$db->exec('UPDATE public.orders o SET total = (SELECT coalesce(sum(qty * price), 0) FROM public.order_items i WHERE i.order_id = o.id)');",
            check: static function (\PDO $db, bool $data): void {
                if ($data) {
                    expect(one($db, 'SELECT sum(total) FROM public.orders') == one($db, 'SELECT sum(qty * price) FROM public.order_items'), 'the totals are the items');
                }
            });

        $s = $s->with('audit_log', 'source', ['#[Varchar(20)]', "#[DefaultVal(\"'app'\")]"], "string \$source = 'app'");
        $steps[] = new Step(3, 'colour_gone', $s, 'the column kept by --keep dropped at last; a NOT NULL column with a default added', drop: ['shop.products.colour'], expect: ['dropColumn' => 1, 'addColumn' => 1], check: static function (\PDO $db, bool $data): void {
            expect(one($db, "SELECT count(*) FROM information_schema.columns WHERE table_name = 'products' AND column_name = 'colour'") == 0, 'colour is gone');
            if ($data) {
                expect(one($db, "SELECT count(*) FROM public.audit_log WHERE source = 'app'") == Data::AUDIT, 'every old row has the default');
            }
        });

        $stock = $s->tables['products']['props']['stock'][0];
        $s = $s->with('users', 'locale', ['#[Varchar(10)]'], "string \$locale = 'en'")
            ->renamed('products', 'stock', 'quantity', [...$stock, "#[RenamedFrom('stock')]"], 'int $quantity = 0')
            ->without('orders', 'period');
        $steps[] = new Step(3, 'last_mix', $s, 'added, renamed (with a covering index on it) and dropped (with a GiST index on it) at once', drop: ['public.orders.period'], expect: ['addColumn' => 1, 'renameColumn' => 1, 'dropColumn' => 1], check: static function (\PDO $db, bool $data): void {
            expect(str_contains((string) one($db, "SELECT indexdef FROM pg_indexes WHERE indexname = 'products_stock_cover_idx'"), '(quantity, title) INCLUDE (price, sku)'), 'the covering index is on quantity');
            if ($data) {
                expect(one($db, 'SELECT sum(quantity) FROM shop.products') == Data::STOCK_SUM, 'the stock is in quantity');
            }
        });

        return $steps;
    }

    /** V001: six tables, every attribute ppa has. */
    public static function initial(): State
    {
        return new State([
            'groups' => ['class' => 'Group', 'repo' => 'GroupRepo', 'schema' => null, 'attrs' => [], 'props' => [
                'id' => [['#[SmallId]'], '?int $id = null'],
                'name' => [['#[Varchar(80)]', '#[Unique]'], "string \$name = ''"],
                'description' => [['#[Text]'], '?string $description = null'],
                'active' => [['#[Boolean]', "#[DefaultVal('true')]"], 'bool $active = true'],
                'created_at' => [['#[Timestamp]', "#[DefaultVal('CURRENT_TIMESTAMP')]"], "string \$created_at = ''"],
            ]],
            'users' => ['class' => 'User', 'repo' => 'UserRepo', 'schema' => null, 'attrs' => [], 'props' => [
                'id' => [['#[BigId]'], '?int $id = null'],
                'email' => [['#[Varchar(160)]', '#[Unique]'], "string \$email = ''"],
                'name' => [['#[Varchar(120)]', "#[Index(method: IndexMethod::GIN, opClass: 'gin_trgm_ops')]"], "string \$name = ''"],
                'group_id' => [['#[SmallInteger]', '#[ForeignRepo(GroupRepo::class, onDelete: FKAction::SET_NULL)]'], '?int $group_id = null'],
                'age' => [['#[SmallInteger]', "#[Check('age >= 0')]", "#[DefaultVal('0')]"], 'int $age = 0'],
                'status' => [['#[Varchar(20)]', "#[DefaultVal(\"'active'\")]", "#[CheckEnum(UserStatus::class, name: 'chk_users_status')]", "#[Index(where: \"status <> 'deleted'\")]"], "string \$status = 'active'"],
                'profile' => [['#[Json]', '#[Index(method: IndexMethod::GIN)]'], '?array $profile = null'],
                'rating' => [['#[Double]'], '?float $rating = null'],
                'score' => [['#[FloatType]', '#[NullableIs(true)]'], 'float $score = 0.0'],
                'born' => [['#[Date]'], '?string $born = null'],
                'login_at' => [['#[DateTime]'], '?string $login_at = null'],
                'api_key' => [['#[Uuid]', "#[Unique(where: 'api_key IS NOT NULL')]"], '?string $api_key = null'],
                'created_at' => [['#[Timestamp(withTimeZone: false)]', "#[DefaultVal('CURRENT_TIMESTAMP')]"], "string \$created_at = ''"],
            ]],
            'products' => ['class' => 'Product', 'repo' => 'ProductRepo', 'schema' => 'shop', 'attrs' => [], 'props' => [
                'id' => [['#[UuidPk]'], '?string $id = null'],
                'sku' => [['#[Char(12)]', '#[Unique]'], 'string $sku'],
                'title' => [['#[Varchar(200)]', '#[Index]', '#[Index(method: IndexMethod::HASH)]'], "string \$title = ''"],
                'price' => [['#[Decimal(12, 2)]', "#[Check('price >= 0', name: 'chk_products_price')]"], "string \$price = '0'"],
                'stock' => [['#[Integer]', "#[Index(['title'], include: ['price'], name: 'stock_cover')]"], 'int $stock = 0'],
                'weight' => [['#[FloatType]'], '?float $weight = null'],
                'photo' => [['#[Blob]'], '?string $photo = null'],
                'digest' => [['#[Binary(32)]'], '?string $digest = null'],
                'tags' => [["#[Type('TEXT[]')]", '#[Index(method: IndexMethod::GIN)]'], '?string $tags = null'],
            ]],
            'orders' => ['class' => 'Order', 'repo' => 'OrderRepo', 'schema' => null, 'attrs' => ["#[Check('total >= 0', name: 'chk_orders_total')]"], 'props' => [
                'id' => [['#[BigId(always: true)]'], '?int $id = null'],
                'user_id' => [['#[BigInteger]', "#[ForeignKey('users', 'id', onUpdate: FKAction::CASCADE, onDelete: FKAction::CASCADE)]"], 'int $user_id'],
                'number' => [['#[Varchar(30)]', '#[Unique]'], 'string $number'],
                'total' => [['#[Decimal(12, 2)]', "#[DefaultVal('0')]"], "string \$total = '0'"],
                'note' => [['#[Text]'], '?string $note = null'],
                'paid' => [['#[Boolean]', "#[DefaultVal('false')]", "#[Index(where: 'paid = false', name: 'unpaid')]"], 'bool $paid = false'],
                'period' => [["#[Type('TSRANGE')]", '#[Index(method: IndexMethod::GIST)]'], '?string $period = null'],
                'placed_at' => [['#[Timestamp]', "#[DefaultVal('CURRENT_TIMESTAMP')]"], "string \$placed_at = ''"],
            ]],
            'order_items' => ['class' => 'OrderItem', 'repo' => 'OrderItemRepo', 'schema' => null, 'attrs' => [], 'props' => [
                'order_id' => [['#[BigInteger]', '#[Primary]', '#[ForeignRepo(OrderRepo::class, onDelete: FKAction::CASCADE)]'], 'int $order_id'],
                'product_id' => [['#[Uuid]', '#[Primary]', "#[ForeignRepo(ProductRepo::class, onDelete: FKAction::RESTRICT, name: 'fk_items_product')]"], 'string $product_id'],
                'qty' => [['#[Integer]', "#[Check('qty > 0')]"], 'int $qty = 1'],
                'price' => [['#[Decimal(12, 2)]'], "string \$price = '0'"],
            ]],
            'audit_log' => ['class' => 'AuditLog', 'repo' => 'AuditLogRepo', 'schema' => null, 'attrs' => [], 'props' => [
                'id' => [['#[Integer]', '#[AutoIncrement]', '#[Primary]'], '?int $id = null'],
                'event' => [['#[Varchar(60)]', "#[Index(method: IndexMethod::BTREE, name: 'event_btree')]"], "string \$event = ''"],
                'level' => [['#[SmallInteger]', "#[DefaultVal('1')]"], 'int $level = 1'],
                'user_ref' => [['#[BigInteger]'], '?int $user_ref = null'],
                'payload' => [['#[Json]'], '?array $payload = null'],
                'at' => [['#[Time]'], '?string $at = null'],
                'uid' => [['#[Uuid(asBinary: true)]'], '?string $uid = null'],
                'happened' => [['#[Timestamp]', "#[DefaultVal('CURRENT_TIMESTAMP')]"], "string \$happened = ''"],
            ]],
        ], ['pg_trgm']);
    }

    /** Every #[RenamedFrom] taken out: a version that recorded it, cleaned as `snapshot clean` does. */
    private static function clean(State $s): State
    {
        foreach ($s->tables as $table => $t) {
            foreach ($t['props'] as $prop => [$attrs]) {
                $kept = array_values(array_filter($attrs, fn($a) => !str_starts_with($a, '#[RenamedFrom(')));
                if ($kept !== $attrs) {
                    $s = $s->attrs($table, $prop, fn() => $kept);
                }
            }
        }
        return $s;
    }

    /** @return list<string> `$old` replaced by `$new` — or taken out. */
    private static function swap(array $attrs, string $old, ?string $new): array
    {
        if (!in_array($old, $attrs, true)) {
            throw new \LogicException("No {$old} among " . implode(' ', $attrs));
        }
        return array_values(array_filter(array_map(fn($a) => $a === $old ? $new : $a, $attrs), fn($a) => $a !== null));
    }
}
