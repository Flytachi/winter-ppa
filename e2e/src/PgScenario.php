<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\E2e;

/**
 * The life of an application on PostgreSQL, snapshot by snapshot ({@see Scenario} has the
 * helpers every scenario shares).
 *
 * Stage 1 (V001–V005): six tables with every attribute, then a column added, renamed,
 * dropped — and back to the first shape in one version.
 * Stage 2: data ({@see Data}).
 * Stage 3 (V006–V023), on tables full of rows: every parameter of every index, foreign key,
 * check and id attribute made, changed, renamed, moved and dropped; extensions added (with
 * version, schema, cascade) and dropped; types widened and narrowed; NOT NULL over NULLs; a
 * unique index over duplicates; a primary key changed; tables made and dropped; code-only
 * versions. {@see Runner::coverage()} fails the run when a parameter, an FKAction or an
 * IndexMethod is used by no state.
 *
 * A check gets the database and whether it holds the data of stage 2 (variant B has none).
 */
final class PgScenario
{

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

        $s = Scenario::clean($s)
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
        $s = Scenario::clean($s);

        // V006 — indexes: WHERE changed, a named index renamed, INCLUDE on a unique one, a
        // composite unique index with a name.
        $s = $s->attrs('users', 'status', fn($a) => Scenario::swap($a, "#[Index(where: \"status <> 'deleted'\")]", "#[Index(where: \"status IN ('active', 'new')\")]"))
            ->attrs('orders', 'paid', fn($a) => Scenario::swap($a, "#[Index(where: 'paid = false', name: 'unpaid')]", "#[Index(where: 'paid = false AND total > 0', name: 'unpaid')]"))
            ->attrs('audit_log', 'event', fn($a) => Scenario::swap($a, "#[Index(method: IndexMethod::BTREE, name: 'event_btree')]", "#[Index(method: IndexMethod::BTREE, name: 'audit_event')]"))
            ->attrs('users', 'email', fn($a) => Scenario::swap($a, '#[Unique]', "#[Unique(include: ['name'])]"))
            ->attrs('orders', 'number', fn($a) => [...$a, "#[Unique(['placed_at'], name: 'number_day')]"]);
        $steps[] = new Step(3, 'indexes_remade', $s, 'WHERE changed on two, a named index renamed, INCLUDE on a unique one, a composite unique one', expect: ['dropIndex' => 4, 'addIndex' => 5], check: static function (\PDO $db): void {
            expect(str_contains((string) one($db, "SELECT indexdef FROM pg_indexes WHERE indexname = 'orders_unpaid_idx'"), 'total > '), 'orders_unpaid_idx has its new WHERE');
            expect(one($db, "SELECT count(*) FROM pg_indexes WHERE indexname IN ('audit_log_audit_event_idx', 'orders_number_day_udx')") == 2, 'the renamed and the composite index are there');
            expect(one($db, "SELECT count(*) FROM pg_indexes WHERE indexname = 'audit_log_event_btree_idx'") == 0, 'the old name is gone');
            expect(str_contains((string) one($db, "SELECT indexdef FROM pg_indexes WHERE indexname = 'users_email_udx'"), 'INCLUDE (name)'), 'the unique index covers name');
        });

        // V007 — index methods and extensions: HASH → GIN with trigrams, btree_gist and a GiST
        // index, an extension with version, schema and cascade, a unique index with an
        // operator class.
        $s = $s->withExtension('btree_gist')
            ->withExtension('uuid-ossp', "'uuid-ossp', version: '1.1', schema: 'shop', cascade: true")
            ->attrs('products', 'title', fn($a) => Scenario::swap($a, '#[Index(method: IndexMethod::HASH)]', "#[Index(method: IndexMethod::GIN, opClass: 'gin_trgm_ops')]"))
            ->attrs('audit_log', 'level', fn($a) => [...$a, '#[Index(method: IndexMethod::GIST)]'])
            ->attrs('users', 'email', fn($a) => Scenario::swap($a, "#[Unique(include: ['name'])]", "#[Unique(include: ['name'], opClass: 'varchar_pattern_ops')]"));
        $steps[] = new Step(3, 'index_methods_and_extensions', $s, 'HASH → GIN with trigrams, two extensions (one with version, schema, cascade), a GiST index, a unique one with an operator class', expect: ['createExtension' => 2, 'dropIndex' => 2, 'addIndex' => 3], check: static function (\PDO $db, bool $data): void {
            expect(one($db, "SELECT count(*) FROM pg_extension WHERE extname = 'btree_gist'") == 1, 'btree_gist is there');
            expect(one($db, "SELECT n.nspname || ' ' || e.extversion FROM pg_extension e JOIN pg_namespace n ON n.oid = e.extnamespace WHERE extname = 'uuid-ossp'") === 'shop 1.1', 'uuid-ossp 1.1 in shop');
            expect(str_contains((string) one($db, "SELECT indexdef FROM pg_indexes WHERE indexname = 'users_email_udx'"), 'varchar_pattern_ops'), 'the unique index has its operator class');
            if ($data) {
                expect(one($db, "SELECT count(*) FROM shop.products WHERE title % 'Product 1'") > 0, 'the trigram index answers');
            }
        });

        // V008 — a unique index over duplicates (before() makes them distinct), and a composite
        // one with a name and an explicit method.
        $s = $s->attrs('users', 'name', fn($a) => [...$a, '#[Unique]', "#[Unique(['age'], name: 'name_age', method: IndexMethod::BTREE)]"]);
        $steps[] = new Step(3, 'unique_over_duplicates', $s, 'unique indexes on a column with duplicates: before() makes them distinct', custom: true, expect: ['addIndex' => 2],
            before: "\$db->exec(\"UPDATE public.users u SET name = u.name || ' #' || u.id WHERE u.id NOT IN (SELECT min(id) FROM public.users GROUP BY name)\");",
            check: static function (\PDO $db, bool $data): void {
                if ($data) {
                    expect(one($db, 'SELECT count(DISTINCT name) FROM public.users') == Data::USERS, 'every user keeps a distinct name');
                    expect(one($db, "SELECT count(*) FROM public.users WHERE name LIKE 'Ann #%'") == 2, 'the two later Anns are renamed');
                }
            });

        // V009 — INCLUDE widened, a GIN index dropped, a composite index added, a named index
        // moved to another column.
        $s = $s->attrs('products', 'stock', fn($a) => Scenario::swap($a, "#[Index(['title'], include: ['price'], name: 'stock_cover')]", "#[Index(['title'], include: ['price', 'sku'], name: 'stock_cover')]"))
            ->attrs('users', 'profile', fn($a) => Scenario::swap($a, '#[Index(method: IndexMethod::GIN)]', null))
            ->attrs('orders', 'user_id', fn($a) => [...$a, "#[Index(['placed_at'])]"])
            ->attrs('audit_log', 'event', fn($a) => Scenario::swap($a, "#[Index(method: IndexMethod::BTREE, name: 'audit_event')]", null))
            ->attrs('audit_log', 'user_ref', fn($a) => [...$a, "#[Index(method: IndexMethod::BTREE, name: 'audit_event')]"]);
        $steps[] = new Step(3, 'indexes_moved', $s, 'INCLUDE widened, a GIN index dropped, a composite one added, a named one moved to another column', expect: ['dropIndex' => 3, 'addIndex' => 3], check: static function (\PDO $db): void {
            expect(str_contains((string) one($db, "SELECT indexdef FROM pg_indexes WHERE indexname = 'audit_log_audit_event_idx'"), '(user_ref)'), 'audit_event is on user_ref now');
        });

        // V010 — foreign keys: every action, a key renamed by its name.
        $s = $s->attrs('orders', 'user_id', fn($a) => Scenario::swap($a, "#[ForeignKey('users', 'id', onUpdate: FKAction::CASCADE, onDelete: FKAction::CASCADE)]", "#[ForeignKey('users', 'id', onUpdate: FKAction::NO_ACTION, onDelete: FKAction::RESTRICT, name: 'fk_orders_user')]"))
            ->attrs('order_items', 'order_id', fn($a) => Scenario::swap($a, '#[ForeignRepo(OrderRepo::class, onDelete: FKAction::CASCADE)]', '#[ForeignRepo(OrderRepo::class, onUpdate: FKAction::CASCADE, onDelete: FKAction::CASCADE)]'))
            ->attrs('order_items', 'product_id', fn($a) => Scenario::swap($a, "#[ForeignRepo(ProductRepo::class, onDelete: FKAction::RESTRICT, name: 'fk_items_product')]", "#[ForeignRepo(ProductRepo::class, onDelete: FKAction::CASCADE, name: 'fk_items_product')]"));
        $steps[] = new Step(3, 'foreign_key_actions', $s, 'ON UPDATE / ON DELETE changed on three keys, one renamed by its name', expect: ['dropConstraint' => 3, 'addConstraint' => 3], check: static function (\PDO $db, bool $data): void {
            $def = fn(string $name) => (string) one($db, "SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = '{$name}'");
            expect(str_contains($def('fk_items_product'), 'ON DELETE CASCADE'), 'fk_items_product cascades');
            expect(str_contains($def('fk_order_items_order_id'), 'ON UPDATE CASCADE ON DELETE CASCADE'), 'the items follow their order');
            expect($def('fk_orders_user') !== '' && $def('fk_orders_user_id') === '', 'fk_orders_user_id is now fk_orders_user');
            if ($data) {
                // What the keys do, then undone: no row of the test is lost.
                $db->beginTransaction();
                $product = one($db, 'SELECT id FROM shop.products ORDER BY sku LIMIT 1');
                $items = (int) one($db, "SELECT count(*) FROM public.order_items WHERE product_id = '{$product}'");
                $db->exec("DELETE FROM shop.products WHERE id = '{$product}'");
                expect($items > 0 && one($db, 'SELECT count(*) FROM public.order_items') == Data::ITEMS - $items, 'deleting a product deletes its items');
                $db->rollBack();
                $db->beginTransaction();
                try {
                    $db->exec('DELETE FROM public.users WHERE id = 1');
                    $restricted = false;
                } catch (\PDOException) {
                    $restricted = true;
                }
                $db->rollBack();
                expect($restricted, 'a user with orders cannot be deleted (RESTRICT)');
            }
        });

        // V011 — a foreign key dropped; one added over rows that point nowhere (before() clears
        // them), with SET DEFAULT and SET NULL.
        $s = $s->attrs('users', 'group_id', fn($a) => Scenario::swap($a, '#[ForeignRepo(GroupRepo::class, onDelete: FKAction::SET_NULL)]', null))
            ->attrs('audit_log', 'user_ref', fn($a) => [...$a, '#[ForeignRepo(UserRepo::class, onUpdate: FKAction::SET_DEFAULT, onDelete: FKAction::SET_NULL)]']);
        $steps[] = new Step(3, 'foreign_key_over_orphans', $s, 'a foreign key dropped, one added over rows that point nowhere: before() clears them', custom: true, expect: ['dropConstraint' => 1, 'addConstraint' => 1],
            before: "\$db->exec('UPDATE public.audit_log SET user_ref = NULL WHERE user_ref IS NOT NULL AND user_ref NOT IN (SELECT id FROM public.users)');",
            check: static function (\PDO $db, bool $data): void {
                expect(str_contains((string) one($db, "SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'fk_audit_log_user_ref'"), 'ON UPDATE SET DEFAULT ON DELETE SET NULL'), 'the key has its actions');
                if ($data) {
                    expect(one($db, 'SELECT count(*) FROM public.audit_log WHERE user_ref IS NOT NULL') == Data::AUDIT_VALID_REFS, 'the valid references stay');
                }
            });

        // V012 — widened by the kernel: VARCHAR, DECIMAL, an integer, an identity (SMALLINT → INT).
        $s = $s->attrs('users', 'name', fn($a) => Scenario::swap($a, '#[Varchar(120)]', '#[Varchar(200)]'))
            ->attrs('orders', 'total', fn($a) => Scenario::swap($a, '#[Decimal(12, 2)]', '#[Decimal(14, 2)]'))
            ->attrs('audit_log', 'level', fn($a) => Scenario::swap($a, '#[SmallInteger]', '#[Integer]'))
            ->attrs('groups', 'id', fn($a) => Scenario::swap($a, '#[SmallId]', '#[Id]'));
        $steps[] = new Step(3, 'widen_types', $s, 'VARCHAR, DECIMAL, an integer and an identity widened, by the kernel', expect: ['alterColumn' => 4], check: static function (\PDO $db, bool $data): void {
            expect(one($db, "SELECT character_maximum_length FROM information_schema.columns WHERE table_name = 'users' AND column_name = 'name'") == 200, 'users.name is VARCHAR(200)');
            expect(one($db, "SELECT data_type || ' ' || is_identity FROM information_schema.columns WHERE table_name = 'groups' AND column_name = 'id'") === 'integer YES', 'groups.id is an INT identity');
            if ($data) {
                expect(one($db, 'SELECT sum(total) FROM public.orders') == Data::ORDERS_TOTAL, 'the totals are untouched');
                expect(one($db, 'SELECT count(*) FROM public.groups') == Data::GROUPS, 'the groups are there');
            }
        });

        // V013 — a narrowing by after(), and a check's expression changed.
        $s = $s->attrs('products', 'sku', fn($a) => Scenario::swap($a, '#[Char(12)]', '#[Varchar(10)]'))
            ->attrs('products', 'price', fn($a) => Scenario::swap($a, "#[Check('price >= 0', name: 'chk_products_price')]", "#[Check('price >= 0 AND price < 1000000', name: 'chk_products_price')]"));
        $steps[] = new Step(3, 'narrow_sku', $s, 'CHAR(12) → VARCHAR(10) by after(); a check made stricter', custom: true, expect: ['alterColumn' => 1, 'dropConstraint' => 1, 'addConstraint' => 1],
            after: "\$db->exec('ALTER TABLE shop.products ALTER COLUMN sku TYPE VARCHAR(10) USING left(rtrim(sku), 10)');",
            check: static function (\PDO $db, bool $data): void {
                expect(str_contains((string) one($db, "SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'chk_products_price'"), '1000000'), 'the check is the new one');
                if ($data) {
                    expect(one($db, 'SELECT count(DISTINCT sku) FROM shop.products') == Data::PRODUCTS, 'every sku is still distinct');
                    expect(one($db, 'SELECT sku FROM shop.products ORDER BY sku LIMIT 1') === 'SKU-000001', 'the sku keeps its value, without the padding');
                }
            });

        // V014 — NOT NULL over NULLs (before() fills them), and #[NullableIs(false)] on a
        // nullable property.
        $s = $s->with('orders', 'note', ['#[Text]', "#[DefaultVal(\"''\")]"], "string \$note = ''")
            ->attrs('users', 'rating', fn($a) => [...$a, '#[NullableIs(false)]', "#[DefaultVal('0')]"]);
        $steps[] = new Step(3, 'not_null', $s, 'NOT NULL over NULLs: before() fills them; #[NullableIs(false)] on a ?float', custom: true, expect: ['alterColumn' => 2],
            before: "\$db->exec(\"UPDATE public.orders SET note = '' WHERE note IS NULL\");",
            check: static function (\PDO $db, bool $data): void {
                expect(one($db, "SELECT string_agg(is_nullable, ',' ORDER BY column_name) FROM information_schema.columns WHERE (table_name, column_name) IN (('orders', 'note'), ('users', 'rating'))") === 'NO,NO', 'orders.note and users.rating are NOT NULL');
                if ($data) {
                    expect(one($db, "SELECT count(*) FROM public.orders WHERE note = ''") == Data::ORDERS_WITHOUT_NOTE, 'the empty notes are filled');
                }
            });

        // V015 — checks: an enum's widened, an int enum's added, one dropped, a table-level
        // one renamed; a default changed.
        $s = $s->attrs('users', 'status', fn($a) => Scenario::swap(
            Scenario::swap($a, "#[CheckEnum(UserStatus::class, name: 'chk_users_status')]", "#[CheckEnum(UserStatusV2::class, name: 'chk_users_status')]"),
            "#[DefaultVal(\"'active'\")]",
            "#[DefaultVal(\"'new'\")]",
        ))->decl('users', 'status', "string \$status = 'new'")
            ->attrs('audit_log', 'level', fn($a) => [...$a, '#[CheckEnum(AuditLevel::class)]'])
            ->attrs('users', 'age', fn($a) => Scenario::swap($a, "#[Check('age >= 0')]", null))
            ->table('orders', ['attrs' => ["#[Check('total >= 0', name: 'chk_orders_total_positive')]"]]);
        $steps[] = new Step(3, 'checks', $s, 'a CHECK of a string enum widened, one of an int enum added, one dropped, a table-level one renamed', expect: ['dropConstraint' => 3, 'addConstraint' => 3, 'alterColumn' => 1], check: static function (\PDO $db): void {
            $refused = static function (\PDO $db, string $sql): bool {
                $db->beginTransaction();
                try {
                    $db->exec($sql);
                    return false;
                } catch (\PDOException) {
                    return true;
                } finally {
                    $db->rollBack();
                }
            };
            expect(!$refused($db, "INSERT INTO public.users (email, name, status) VALUES ('blocked@e2e.test', 'Blocked', 'blocked')"), 'blocked is a status now');
            expect($refused($db, "INSERT INTO public.audit_log (event, level) VALUES ('x', 7)"), 'level 7 is no AuditLevel');
            expect(!$refused($db, "INSERT INTO public.users (email, name, age) VALUES ('old@e2e.test', 'Old', -1)"), 'the age check is gone');
            expect(one($db, "SELECT count(*) FROM pg_constraint WHERE conname IN ('chk_orders_total', 'chk_orders_total_positive')") == 1, 'the table check is renamed');
            expect(one($db, "SELECT column_default FROM information_schema.columns WHERE table_name = 'users' AND column_name = 'status'") === "'new'::character varying", 'the default is new');
        });

        // V016 — a column renamed with its foreign key and its composite index.
        $userId = $s->tables['orders']['props']['user_id'][0];
        $s = $s->renamed('orders', 'user_id', 'customer_id', [...$userId, "#[RenamedFrom('user_id')]"], 'int $customer_id');
        $steps[] = new Step(3, 'rename_with_key', $s, 'a column with a foreign key and a composite index renamed', expect: ['renameColumn' => 1, 'dropConstraint' => 1, 'addConstraint' => 1, 'dropIndex' => 1, 'addIndex' => 1], check: static function (\PDO $db, bool $data): void {
            expect(str_contains((string) one($db, "SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'fk_orders_user'"), 'FOREIGN KEY (customer_id)'), 'the foreign key follows the column');
            if ($data) {
                expect(one($db, 'SELECT sum(customer_id) FROM public.orders') == Data::ORDERS_USER_SUM, 'every order keeps its customer');
            }
        });

        // V017 — two new tables (identities always generated), filled by after().
        $s = Scenario::clean($s)->table('payments', [
            'class' => 'Payment', 'repo' => 'PaymentRepo', 'schema' => 'shop', 'attrs' => ["#[Check('amount >= 0', name: 'chk_payments_amount')]"],
            'props' => [
                'id' => [['#[Id(always: true)]'], '?int $id = null'],
                'order_id' => [['#[BigInteger]', '#[ForeignRepo(OrderRepo::class, onDelete: FKAction::CASCADE)]', "#[Unique(['paid_at'], name: 'order_paid')]"], 'int $order_id'],
                'amount' => [['#[Decimal(14, 2)]'], "string \$amount = '0'"],
                'paid_at' => [['#[Timestamp]', "#[DefaultVal('CURRENT_TIMESTAMP')]"], "string \$paid_at = ''"],
            ],
        ])->table('labels', [
            'class' => 'Label', 'repo' => 'LabelRepo', 'schema' => null, 'attrs' => [],
            'props' => [
                'id' => [['#[SmallId(always: true)]'], '?int $id = null'],
                'name' => [['#[Varchar(40)]', "#[Unique(method: IndexMethod::BTREE, name: 'label_name')]"], "string \$name = ''"],
            ],
        ]);
        $steps[] = new Step(3, 'new_tables', $s, 'two new tables with identities always generated, filled by after()', custom: true, expect: ['createTable' => 2, 'addConstraint' => 2, 'addIndex' => 2],
            after: "\$db->exec('INSERT INTO shop.payments (order_id, amount, paid_at) SELECT id, total, placed_at FROM public.orders WHERE paid'); \$db->exec(\"INSERT INTO public.labels (name) VALUES ('red'), ('green'), ('blue')\");",
            check: static function (\PDO $db, bool $data): void {
                expect(one($db, "SELECT string_agg(identity_generation, ',' ORDER BY table_name) FROM information_schema.columns WHERE table_name IN ('labels', 'payments') AND column_name = 'id'") === 'ALWAYS,ALWAYS', 'both ids are GENERATED ALWAYS');
                if ($data) {
                    expect(one($db, 'SELECT count(*) FROM shop.payments') == Data::ORDERS_PAID, 'a payment per paid order');
                    expect(one($db, 'SELECT count(*) FROM public.labels') == 3, 'three labels');
                }
            });

        // V018 — ids: the primary key (id) → (id, happened) by after(); GENERATED ALWAYS on and
        // off, by the kernel.
        $s = $s->attrs('audit_log', 'happened', fn($a) => [...$a, '#[Primary]'])
            ->attrs('audit_log', 'id', fn($a) => Scenario::swap($a, '#[AutoIncrement]', '#[AutoIncrement(always: true)]'))
            ->attrs('orders', 'id', fn($a) => Scenario::swap($a, '#[BigId(always: true)]', '#[BigId]'));
        $steps[] = new Step(3, 'ids', $s, 'a primary key of two columns (by after()), GENERATED ALWAYS turned on and off (by the kernel)', custom: true, expect: ['setPrimaryKey' => 1, 'alterColumn' => 2],
            after: "\$db->exec('ALTER TABLE public.audit_log DROP CONSTRAINT audit_log_pkey, ADD PRIMARY KEY (id, happened)');",
            check: static function (\PDO $db): void {
                expect(one($db, "SELECT count(*) FROM pg_index i JOIN pg_class c ON c.oid = i.indrelid WHERE c.relname = 'audit_log' AND i.indisprimary AND i.indnatts = 2") == 1, 'audit_log has a key of two columns');
                expect(one($db, "SELECT string_agg(table_name || ' ' || identity_generation, ',' ORDER BY table_name) FROM information_schema.columns WHERE table_name IN ('audit_log', 'orders') AND column_name = 'id'") === 'audit_log ALWAYS,orders BY DEFAULT', 'the identities are switched');
            });

        // V019 — dropped: a table, a column, an index, an extension (--drop=extension:…).
        $s = $s->withoutTable('groups')->without('products', 'weight')
            ->attrs('products', 'title', fn($a) => Scenario::swap($a, '#[Index]', null))
            ->withoutExtension('uuid-ossp');
        $steps[] = new Step(3, 'drops', $s, 'a table, a column, an index and an extension dropped', drop: ['public.groups', 'shop.products.weight', 'extension:uuid-ossp'], expect: ['dropTable' => 1, 'dropColumn' => 1, 'dropIndex' => 1, 'dropExtension' => 1], check: static function (\PDO $db): void {
            expect(one($db, "SELECT count(*) FROM information_schema.tables WHERE table_name = 'groups'") == 0, 'groups is gone');
            expect(one($db, "SELECT count(*) FROM pg_extension WHERE extname = 'uuid-ossp'") == 0, 'uuid-ossp is gone');
            expect(one($db, "SELECT count(*) FROM pg_indexes WHERE indexname = 'products_title_idx'") == 0, 'the plain title index is gone');
        });

        // V020 — a version of code only.
        $steps[] = new Step(3, 'recount_totals', $s, 'a version of code only: totals recounted from the items', custom: true,
            after: "\$db->exec('UPDATE public.orders o SET total = (SELECT coalesce(sum(qty * price), 0) FROM public.order_items i WHERE i.order_id = o.id)');",
            check: static function (\PDO $db, bool $data): void {
                if ($data) {
                    expect(one($db, 'SELECT sum(total) FROM public.orders') == one($db, 'SELECT sum(qty * price) FROM public.order_items'), 'the totals are the items');
                }
            });

        // V021 — the kept column dropped at last; a NOT NULL column with a default added; a
        // foreign key of a repository made a named key of a table.
        // Two tables renamed: products → goods (a key of another table points at it), and
        // audit_log moved to the schema shop as audit_events (an identity, a key of two
        // columns, its indexes, checks and foreign key named after it).
        $s = $s->with('audit_log', 'source', ['#[Varchar(20)]', "#[DefaultVal(\"'app'\")]"], "string \$source = 'app'")
            ->attrs('payments', 'order_id', fn($a) => Scenario::swap($a, '#[ForeignRepo(OrderRepo::class, onDelete: FKAction::CASCADE)]', "#[ForeignKey('orders', 'id', onDelete: FKAction::CASCADE, name: 'fk_payments_order')]"))
            // everything renamed: the table, its entity, its repository — and the key that names it
            ->retable('products', 'goods', 'shop', "#[RenamedFrom('products')]")
            ->reclass('goods', 'Good', 'GoodRepo')
            ->attrs('order_items', 'product_id', fn($a) => Scenario::swap($a, "#[ForeignRepo(ProductRepo::class, onDelete: FKAction::CASCADE, name: 'fk_items_product')]", "#[ForeignRepo(GoodRepo::class, onDelete: FKAction::CASCADE, name: 'fk_items_product')]"))
            ->retable('audit_log', 'audit_events', 'shop', "#[RenamedFrom('public.audit_log')]");
        $steps[] = new Step(3, 'tables_renamed', $s, 'two tables renamed, one into another schema, with what is named after them; the kept column dropped at last; a NOT NULL column with a default; a foreign key renamed', drop: ['shop.goods.colour'], expect: ['renameTable' => 2, 'dropColumn' => 1, 'addColumn' => 1, 'dropConstraint' => 1, 'addConstraint' => 1, 'dropIndex' => 0, 'addIndex' => 0], check: static function (\PDO $db, bool $data): void {
            expect(one($db, "SELECT string_agg(table_schema || '.' || table_name, ',' ORDER BY table_name) FROM information_schema.tables WHERE table_name IN ('products', 'goods', 'audit_log', 'audit_events')") === 'shop.audit_events,shop.goods', 'the tables have their new names');
            expect(one($db, "SELECT count(*) FROM pg_constraint WHERE conname IN ('goods_pkey', 'audit_events_pkey', 'fk_audit_events_user_ref')") == 3, 'the keys are named after the new tables');
            expect(one($db, "SELECT count(*) FROM pg_class WHERE relkind = 'S' AND relname = 'audit_events_id_seq'") == 1, 'the identity sequence is renamed');
            expect(one($db, "SELECT count(*) FROM pg_indexes WHERE indexname LIKE 'products\\_%' OR indexname LIKE 'audit\\_log\\_%'") == 0, 'no index keeps an old name');
            expect(str_contains((string) one($db, "SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'fk_items_product'"), 'REFERENCES shop.goods(id)'), 'the key of order_items points at goods');
            expect(one($db, "SELECT count(*) FROM information_schema.columns WHERE table_name = 'goods' AND column_name = 'colour'") == 0, 'colour is gone');
            expect(one($db, "SELECT count(*) FROM pg_constraint WHERE conname = 'fk_payments_order'") == 1, 'the payments key has its name');
            if ($data) {
                expect(one($db, "SELECT count(*) FROM shop.audit_events WHERE source = 'app'") == Data::AUDIT, 'every row moved, with the default');
                expect(one($db, 'SELECT count(*) FROM shop.payments') == Data::ORDERS_PAID, 'no payment lost with its key');
            }
        });

        // V022 — at once: added (with an index of every parameter), renamed (with a covering
        // index on it), dropped (with a GiST index on it), a unique index dropped.
        $s = Scenario::clean($s);
        $stock = $s->tables['goods']['props']['stock'][0];
        $s = $s->with('users', 'locale', ['#[Varchar(10)]', "#[Index(['email'], name: 'locale_full', method: IndexMethod::BTREE, where: \"locale <> ''\", opClass: 'varchar_pattern_ops', include: ['id'])]"], "string \$locale = 'en'")
            ->renamed('goods', 'stock', 'quantity', [...$stock, "#[RenamedFrom('stock')]"], 'int $quantity = 0')
            ->without('orders', 'period')
            ->attrs('labels', 'name', fn($a) => Scenario::swap($a, "#[Unique(method: IndexMethod::BTREE, name: 'label_name')]", null))
            // the entity and the repository renamed, the table not: nothing for the schema
            ->reclass('labels', 'Tag', 'TagRepo');
        $steps[] = new Step(3, 'last_mix', $s, 'added with an index of every parameter, renamed with a covering index, dropped with a GiST index, a unique index dropped; a table\'s classes renamed, the table not', drop: ['public.orders.period'], expect: ['addColumn' => 1, 'renameColumn' => 1, 'dropColumn' => 1, 'dropIndex' => 3, 'addIndex' => 2], check: static function (\PDO $db, bool $data): void {
            expect(str_contains((string) one($db, "SELECT indexdef FROM pg_indexes WHERE indexname = 'goods_stock_cover_idx'"), '(quantity, title) INCLUDE (price, sku)'), 'the covering index is on quantity');
            $full = (string) one($db, "SELECT indexdef FROM pg_indexes WHERE indexname = 'users_locale_full_idx'");
            expect(str_contains($full, 'locale varchar_pattern_ops, email) INCLUDE (id) WHERE'), "the index has every part: {$full}");
            if ($data) {
                expect(one($db, 'SELECT sum(quantity) FROM shop.goods') == Data::STOCK_SUM, 'the stock is in quantity');
            }
        });

        // V023 — before() and after() of one version, one instance: the notes read on the old
        // schema, kept in the instance while the steps move them (a table made, the column
        // dropped), written to the new table by after(). The column is gone by then — only
        // what before() kept has them.
        $s = Scenario::clean($s)->without('orders', 'note')->table('order_notes', [
            'class' => 'OrderNote', 'repo' => 'OrderNoteRepo', 'schema' => null, 'attrs' => [],
            'props' => [
                'id' => [['#[BigId]'], '?int $id = null'],
                'order_id' => [['#[BigInteger]', '#[ForeignRepo(OrderRepo::class, onDelete: FKAction::CASCADE)]'], 'int $order_id'],
                'body' => [['#[Text]'], "string \$body = ''"],
            ],
        ]);
        $steps[] = new Step(3, 'notes_moved', $s, 'before() and after() of one version hand rows over: the notes read before the column is dropped, written to a new table after', drop: ['public.orders.note'], custom: true, expect: ['createTable' => 1, 'dropColumn' => 1],
            props: 'private array $notes = [];',
            before: "\$this->notes = \$db->query(\"SELECT id, note FROM public.orders WHERE note <> '' ORDER BY id\")->fetchAll(\\PDO::FETCH_KEY_PAIR);",
            after: "\$add = \$db->prepare('INSERT INTO public.order_notes (order_id, body) VALUES (?, ?)'); foreach (\$this->notes as \$id => \$note) { \$add->execute([\$id, \$note]); }",
            check: static function (\PDO $db, bool $data): void {
                expect(one($db, "SELECT count(*) FROM information_schema.columns WHERE table_name = 'orders' AND column_name = 'note'") == 0, 'orders.note is gone');
                if ($data) {
                    expect(one($db, 'SELECT count(*) FROM public.order_notes') == Data::ORDERS - Data::ORDERS_WITHOUT_NOTE, 'every note handed over from before() to after()');
                    expect(one($db, "SELECT string_agg(body, ',' ORDER BY order_id) FROM public.order_notes WHERE order_id IN (1, 2, 3, 4)") === 'note 1,note 2,note 4', 'each note at its order');
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
                'nickname' => [['#[Varchar(30)]', '#[NullableIs(false)]', "#[DefaultVal(\"''\")]"], '?string $nickname = null'],
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
                'photo' => [["#[Blob(size: 'medium')]"], '?string $photo = null'],
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
        ], ['pg_trgm' => "'pg_trgm'"]);
    }
}
