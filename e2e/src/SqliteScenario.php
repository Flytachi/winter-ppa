<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\E2e;

/**
 * The life of an application on SQLite — the life {@see PgScenario} tells, version for
 * version, as far as SQLite goes.
 *
 * What ALTER TABLE cannot do there — a column changed, a check or a foreign key added or
 * dropped, the primary key — the migrator does by rebuilding the table (foreign keys off,
 * checked after): no version's code for it, the code only moves data. SQLite has partial
 * indexes and SET DEFAULT; it has one kind of index (GIN, GiST, HASH are built as its
 * B-tree, said), no INCLUDE, no operator class, no extension, no schema — declared all the
 * same, left out and said. What it cannot build is in {@see refused()}.
 */
final class SqliteScenario
{
    /** @return list<Step> */
    public static function steps(): array
    {
        $s = self::initial();
        $steps = [];

        // ── Stage 1 ─────────────────────────────────────────────────────────

        $steps[] = new Step(1, 'init', $s, 'six tables, every attribute; the extension and the methods are not SQLite\'s — left out, said', expect: ['createTable' => 6, 'addIndex' => 14, 'addConstraint' => 9, 'createSchema' => 0, 'createExtension' => 0], said: [
            '/users_name_gin_idx: SQLite has one kind of index — GIN is built as its B-tree/',
            '/users_name_gin_idx: the operator class gin_trgm_ops is PostgreSQL\'s/',
            '/orders_period_gist_idx: SQLite has one kind of index — GIST/',
            '/products_stock_cover_idx: INCLUDE \(price\) is PostgreSQL\'s/',
        ]);

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
        $steps[] = new Step(1, 'drop_columns', $s, 'three columns dropped (--drop), one left in the database (--keep)',
            drop: ['users.mobile', 'users.born', 'groups.position'],
            keep: ['products.colour'],
            expect: ['dropColumn' => 3, 'detachColumn' => 1],
        );

        $s = $s->with('users', 'born', ['#[Date]'], '?string $born = null')
            ->renamed('orders', 'comment', 'note', ['#[Text]', "#[RenamedFrom('comment')]"], '?string $note = null')
            ->without('orders', 'discount');
        $steps[] = new Step(1, 'back_to_the_start', $s, 'added, renamed and dropped in one version: the shape of V001 again',
            drop: ['orders.discount'],
            expect: ['addColumn' => 1, 'renameColumn' => 1, 'dropColumn' => 1],
            check: static function (\PDO $db): void {
                expect(self::column($db, 'orders', 'comment') === null && self::column($db, 'orders', 'discount') === null, 'orders is back to its columns of V001');
            },
        );

        // ── Stage 3 ─────────────────────────────────────────────────────────
        $s = Scenario::clean($s);

        // V006 — indexes: WHERE changed on two (SQLite has partial indexes), a named index
        // renamed, a composite unique one; an INCLUDE declared (left out, said).
        $s = $s->attrs('users', 'status', fn($a) => Scenario::swap($a, "#[Index(where: \"status <> 'deleted'\")]", "#[Index(where: \"status IN ('active', 'new')\")]"))
            ->attrs('orders', 'paid', fn($a) => Scenario::swap($a, "#[Index(where: 'paid = 0', name: 'unpaid')]", "#[Index(where: 'paid = 0 AND total > 0', name: 'unpaid')]"))
            ->attrs('audit_log', 'event', fn($a) => Scenario::swap($a, "#[Index(method: IndexMethod::BTREE, name: 'event_btree')]", "#[Index(method: IndexMethod::BTREE, name: 'audit_event')]"))
            ->attrs('users', 'email', fn($a) => Scenario::swap($a, '#[Unique]', "#[Unique(include: ['name'])]"))
            ->attrs('orders', 'number', fn($a) => [...$a, "#[Unique(['placed_at'], name: 'number_day')]"]);
        $steps[] = new Step(3, 'indexes_remade', $s, 'WHERE changed on two, a named index renamed, a composite unique one; an INCLUDE — left out', expect: ['dropIndex' => 3, 'addIndex' => 4], said: ['/users_email_udx: INCLUDE \(name\) is PostgreSQL\'s/'], check: static function (\PDO $db): void {
            expect(str_contains((string) self::indexWhere($db, 'orders_unpaid_idx'), 'total > 0'), 'orders_unpaid_idx has its new WHERE');
            expect(self::index($db, 'orders_number_day_udx') === 'unique number,placed_at', 'the composite unique index is there');
            expect(self::index($db, 'audit_log_audit_event_idx') === 'index event', 'the renamed index is there');
            expect(self::index($db, 'audit_log_event_btree_idx') === null, 'the old name is gone');
        });

        // V007 — index methods: GIN → a named B-tree, a composite index; an operator class on a
        // unique one (left out).
        $s = $s->attrs('users', 'name', fn($a) => Scenario::swap($a, "#[Index(method: IndexMethod::GIN, opClass: 'gin_trgm_ops')]", "#[Index(method: IndexMethod::BTREE, name: 'name_b')]"))
            ->attrs('products', 'price', fn($a) => [...$a, "#[Index(['stock'], name: 'price_stock')]"])
            ->attrs('users', 'email', fn($a) => Scenario::swap($a, "#[Unique(include: ['name'])]", "#[Unique(include: ['name'], opClass: 'varchar_pattern_ops')]"));
        $steps[] = new Step(3, 'index_methods', $s, 'GIN (a B-tree here) → a named B-tree, a composite index; an operator class — left out', expect: ['dropIndex' => 1, 'addIndex' => 2], said: ['/users_email_udx: the operator class varchar_pattern_ops is PostgreSQL\'s/'], check: static function (\PDO $db): void {
            expect(self::index($db, 'users_name_b_idx') === 'index name', 'the named B-tree is there');
            expect(self::index($db, 'users_name_gin_idx') === null, 'the GIN one is gone');
            expect(self::index($db, 'products_price_stock_idx') === 'index price,stock', 'the composite index is there');
        });

        // V008 — a unique index over duplicates (before() makes them distinct), and a composite
        // one with a name and an explicit method.
        $s = $s->attrs('users', 'name', fn($a) => [...$a, '#[Unique]', "#[Unique(['age'], name: 'name_age', method: IndexMethod::BTREE)]"]);
        $steps[] = new Step(3, 'unique_over_duplicates', $s, 'unique indexes on a column with duplicates: before() makes them distinct', custom: true, expect: ['addIndex' => 2],
            before: "\$db->exec(\"UPDATE users SET name = name || ' #' || id WHERE id NOT IN (SELECT min(id) FROM users GROUP BY name)\");",
            check: static function (\PDO $db, bool $data): void {
                if ($data) {
                    expect(one($db, 'SELECT count(DISTINCT name) FROM users') == Data::USERS, 'every user keeps a distinct name');
                    expect(one($db, "SELECT count(*) FROM users WHERE name LIKE 'Ann #%'") == 2, 'the two later Anns are renamed');
                }
            });

        // V009 — a composite index added, a named index moved to another column, a GIN one
        // dropped; INCLUDE widened (left out).
        $s = $s->attrs('products', 'stock', fn($a) => Scenario::swap($a, "#[Index(['title'], include: ['price'], name: 'stock_cover')]", "#[Index(['title'], include: ['price', 'sku'], name: 'stock_cover')]"))
            ->attrs('users', 'profile', fn($a) => Scenario::swap($a, '#[Index(method: IndexMethod::GIN)]', null))
            ->attrs('orders', 'user_id', fn($a) => [...$a, "#[Index(['placed_at'])]"])
            ->attrs('audit_log', 'event', fn($a) => Scenario::swap($a, "#[Index(method: IndexMethod::BTREE, name: 'audit_event')]", null))
            ->attrs('audit_log', 'user_ref', fn($a) => [...$a, "#[Index(method: IndexMethod::BTREE, name: 'audit_event')]"]);
        $steps[] = new Step(3, 'indexes_moved', $s, 'a composite index added, a named one moved to another column, a GIN one dropped', expect: ['dropIndex' => 2, 'addIndex' => 2], check: static function (\PDO $db): void {
            expect(self::index($db, 'audit_log_audit_event_idx') === 'index user_ref', 'audit_event is on user_ref now');
            expect(self::index($db, 'orders_user_id_placed_at_idx') === 'index user_id,placed_at', 'the composite index is there');
        });

        // V010 — foreign keys: every action changed, a key renamed by its name — the tables
        // rebuilt, the rows kept; what CASCADE and RESTRICT do, tried and undone.
        $s = $s->attrs('orders', 'user_id', fn($a) => Scenario::swap($a, "#[ForeignKey('users', 'id', onUpdate: FKAction::CASCADE, onDelete: FKAction::CASCADE)]", "#[ForeignKey('users', 'id', onUpdate: FKAction::NO_ACTION, onDelete: FKAction::RESTRICT, name: 'fk_orders_user')]"))
            ->attrs('order_items', 'order_id', fn($a) => Scenario::swap($a, '#[ForeignRepo(OrderRepo::class, onDelete: FKAction::CASCADE)]', '#[ForeignRepo(OrderRepo::class, onUpdate: FKAction::CASCADE, onDelete: FKAction::CASCADE)]'))
            ->attrs('order_items', 'product_id', fn($a) => Scenario::swap($a, "#[ForeignRepo(ProductRepo::class, onDelete: FKAction::RESTRICT, name: 'fk_items_product')]", "#[ForeignRepo(ProductRepo::class, onDelete: FKAction::CASCADE, name: 'fk_items_product')]"));
        $steps[] = new Step(3, 'foreign_key_actions', $s, 'ON UPDATE / ON DELETE changed on three keys, one renamed by its name — two tables rebuilt', expect: ['dropConstraint' => 3, 'addConstraint' => 3], check: static function (\PDO $db, bool $data): void {
            expect(($k = self::key($db, 'order_items', 'product_id')) === 'products(id) ON UPDATE RESTRICT ON DELETE CASCADE', 'fk_items_product cascades: ' . var_export($k, true));
            expect(($k = self::key($db, 'order_items', 'order_id')) === 'orders(id) ON UPDATE CASCADE ON DELETE CASCADE', 'the items follow their order: ' . var_export($k, true));
            expect(self::named($db, 'orders', 'fk_orders_user') && !self::named($db, 'orders', 'fk_orders_user_id'), 'fk_orders_user_id is now fk_orders_user');
            if ($data) {
                $db->beginTransaction();
                $product = one($db, 'SELECT id FROM products ORDER BY sku LIMIT 1');
                $items = (int) one($db, "SELECT count(*) FROM order_items WHERE product_id = '{$product}'");
                $db->exec("DELETE FROM products WHERE id = '{$product}'");
                expect($items > 0 && one($db, 'SELECT count(*) FROM order_items') == Data::ITEMS - $items, 'deleting a product deletes its items');
                $db->rollBack();
                expect(self::refusedSql($db, 'DELETE FROM users WHERE id = 1'), 'a user with orders cannot be deleted (RESTRICT)');
            }
        });

        // V011 — a foreign key dropped; one added over rows that point nowhere (before() clears
        // them), with SET DEFAULT and SET NULL — both SQLite's.
        $s = $s->attrs('users', 'group_id', fn($a) => Scenario::swap($a, '#[ForeignRepo(GroupRepo::class, onDelete: FKAction::SET_NULL)]', null))
            ->attrs('audit_log', 'user_ref', fn($a) => [...$a, '#[ForeignRepo(UserRepo::class, onUpdate: FKAction::SET_DEFAULT, onDelete: FKAction::SET_NULL)]']);
        $steps[] = new Step(3, 'foreign_key_over_orphans', $s, 'a foreign key dropped, one added over rows that point nowhere: before() clears them', custom: true, expect: ['dropConstraint' => 1, 'addConstraint' => 1],
            before: "\$db->exec('UPDATE audit_log SET user_ref = NULL WHERE user_ref IS NOT NULL AND user_ref NOT IN (SELECT id FROM users)');",
            check: static function (\PDO $db, bool $data): void {
                expect(($k = self::key($db, 'audit_log', 'user_ref')) === 'users(id) ON UPDATE SET DEFAULT ON DELETE SET NULL', 'the key has its actions: ' . var_export($k, true));
                if ($data) {
                    expect(one($db, 'SELECT count(*) FROM audit_log WHERE user_ref IS NOT NULL') == Data::AUDIT_VALID_REFS, 'the valid references stay');
                }
            });

        // V012 — widened: VARCHAR, DECIMAL, an integer — the tables rebuilt by the kernel; the id
        // SMALLINT → INT is the same INTEGER here (one integer width): nothing.
        $s = $s->attrs('users', 'name', fn($a) => Scenario::swap($a, '#[Varchar(120)]', '#[Varchar(200)]'))
            ->attrs('orders', 'total', fn($a) => Scenario::swap($a, '#[Decimal(12, 2)]', '#[Decimal(14, 2)]'))
            ->attrs('audit_log', 'level', fn($a) => Scenario::swap($a, '#[SmallInteger]', '#[Integer]'))
            ->attrs('groups', 'id', fn($a) => Scenario::swap($a, '#[SmallId]', '#[Id]'));
        $steps[] = new Step(3, 'widen_types', $s, 'VARCHAR, DECIMAL and an integer widened — three tables rebuilt; SMALLINT → INT id is one INTEGER here', expect: ['alterColumn' => 3], check: static function (\PDO $db, bool $data): void {
            expect(self::column($db, 'users', 'name') === 'VARCHAR(200) NOT NULL', 'users.name is VARCHAR(200)');
            if ($data) {
                expect(one($db, 'SELECT sum(total) FROM orders') == Data::ORDERS_TOTAL, 'the totals are untouched');
                expect(one($db, 'SELECT count(*) FROM groups') == Data::GROUPS, 'the groups are there');
            }
        });

        // V013 — a narrowing: the table rebuilt, the values cut by after() (SQLite keeps any
        // length); a check's expression changed.
        $s = $s->attrs('products', 'sku', fn($a) => Scenario::swap($a, '#[Char(12)]', '#[Varchar(10)]'))
            ->attrs('products', 'price', fn($a) => Scenario::swap($a, "#[Check('price >= 0', name: 'chk_products_price')]", "#[Check('price >= 0 AND price < 1000000', name: 'chk_products_price')]"));
        $steps[] = new Step(3, 'narrow_sku', $s, 'CHAR(12) → VARCHAR(10): the table rebuilt, the values cut by after(); a check made stricter', custom: true, expect: ['alterColumn' => 1, 'dropConstraint' => 1, 'addConstraint' => 1],
            after: "\$db->exec('UPDATE products SET sku = substr(rtrim(sku), 1, 10)');",
            check: static function (\PDO $db, bool $data): void {
                expect(str_contains((string) self::check($db, 'products', 'chk_products_price'), '1000000'), 'the check is the new one');
                if ($data) {
                    expect(one($db, 'SELECT count(DISTINCT sku) FROM products') == Data::PRODUCTS, 'every sku is still distinct');
                    expect(one($db, 'SELECT sku FROM products ORDER BY sku LIMIT 1') === 'SKU-000001', 'the sku keeps its value');
                }
            });

        // V014 — NOT NULL over NULLs (before() fills them), and #[NullableIs(false)] on a
        // nullable property: the tables rebuilt.
        $s = $s->with('orders', 'note', ['#[Text]', "#[DefaultVal(\"''\")]"], "string \$note = ''")
            ->attrs('users', 'rating', fn($a) => [...$a, '#[NullableIs(false)]', "#[DefaultVal('0')]"]);
        $steps[] = new Step(3, 'not_null', $s, 'NOT NULL over NULLs: before() fills them; #[NullableIs(false)] on a ?float', custom: true, expect: ['alterColumn' => 2],
            before: "\$db->exec(\"UPDATE orders SET note = '' WHERE note IS NULL\");",
            check: static function (\PDO $db, bool $data): void {
                expect(str_ends_with((string) self::column($db, 'orders', 'note'), 'NOT NULL') && str_ends_with((string) self::column($db, 'users', 'rating'), 'NOT NULL'), 'orders.note and users.rating are NOT NULL');
                if ($data) {
                    expect(one($db, "SELECT count(*) FROM orders WHERE note = ''") == Data::ORDERS_WITHOUT_NOTE, 'the empty notes are filled');
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
            expect(!self::refusedSql($db, "INSERT INTO users (email, name, status) VALUES ('blocked@e2e.test', 'Blocked', 'blocked')"), 'blocked is a status now');
            expect(self::refusedSql($db, "INSERT INTO audit_log (event, level) VALUES ('x', 7)"), 'level 7 is no AuditLevel');
            expect(!self::refusedSql($db, "INSERT INTO users (email, name, age) VALUES ('old@e2e.test', 'Old', -1)"), 'the age check is gone');
            expect(!self::named($db, 'orders', 'chk_orders_total') && self::named($db, 'orders', 'chk_orders_total_positive'), 'the table check is renamed');
            expect(self::defaultOf($db, 'users', 'status') === "'new'", 'the default is new');
        });

        // V016 — a column renamed with its foreign key and its composite index.
        $userId = $s->tables['orders']['props']['user_id'][0];
        $s = $s->renamed('orders', 'user_id', 'customer_id', [...$userId, "#[RenamedFrom('user_id')]"], 'int $customer_id');
        $steps[] = new Step(3, 'rename_with_key', $s, 'a column with a foreign key and a composite index renamed', expect: ['renameColumn' => 1, 'dropConstraint' => 1, 'addConstraint' => 1, 'dropIndex' => 1, 'addIndex' => 1], check: static function (\PDO $db, bool $data): void {
            expect(($k = self::key($db, 'orders', 'customer_id')) === 'users(id) ON UPDATE NO ACTION ON DELETE RESTRICT', 'the foreign key follows the column: ' . var_export($k, true));
            if ($data) {
                expect(one($db, 'SELECT sum(customer_id) FROM orders') == Data::ORDERS_USER_SUM, 'every order keeps its customer');
            }
        });

        // V017 — two new tables (`always:` — nothing here), filled by after().
        $s = Scenario::clean($s)->table('payments', [
            'class' => 'Payment', 'repo' => 'PaymentRepo', 'schema' => null, 'attrs' => ["#[Check('amount >= 0', name: 'chk_payments_amount')]"],
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
        $steps[] = new Step(3, 'new_tables', $s, 'two new tables, their checks and keys inside the CREATE TABLE, filled by after()', custom: true, expect: ['createTable' => 2, 'addConstraint' => 2, 'addIndex' => 2],
            after: "\$db->exec('INSERT INTO payments (order_id, amount, paid_at) SELECT id, total, placed_at FROM orders WHERE paid'); \$db->exec(\"INSERT INTO labels (name) VALUES ('red'), ('green'), ('blue')\");",
            check: static function (\PDO $db, bool $data): void {
                expect(self::autoincrement($db, 'labels') && self::autoincrement($db, 'payments'), 'both ids are AUTOINCREMENT');
                if ($data) {
                    expect(one($db, 'SELECT count(*) FROM payments') == Data::ORDERS_PAID, 'a payment per paid order');
                    expect(one($db, 'SELECT count(*) FROM labels') == 3, 'three labels');
                }
            });

        // V018 — a primary key changed: the table rebuilt by the kernel, no code but the
        // acknowledgement (custom); `always:` on and off — nothing here. An id in a key of two
        // columns is no SQLite id ({@see refused()}).
        $s = $s->attrs('order_items', 'qty', fn($a) => [...$a, '#[Primary]'])
            ->attrs('audit_log', 'id', fn($a) => Scenario::swap($a, '#[AutoIncrement]', '#[AutoIncrement(always: true)]'))
            ->attrs('orders', 'id', fn($a) => Scenario::swap($a, '#[BigId(always: true)]', '#[BigId]'));
        $steps[] = new Step(3, 'ids', $s, 'a primary key of three columns — the table rebuilt; `always:` on and off — nothing here', custom: true, expect: ['setPrimaryKey' => 1, 'alterColumn' => 0],
            check: static function (\PDO $db, bool $data): void {
                expect(self::primaryKey($db, 'order_items') === 'order_id,product_id,qty', 'order_items has a key of three columns');
                if ($data) {
                    expect(one($db, 'SELECT count(*) FROM order_items') == Data::ITEMS, 'every item kept');
                }
            });

        // V019 — dropped: a table, a column, an index. The extension leaves the config: nothing.
        $s = $s->withoutTable('groups')->without('products', 'weight')
            ->attrs('products', 'title', fn($a) => Scenario::swap($a, '#[Index]', null))
            ->withoutExtension('pg_trgm');
        $steps[] = new Step(3, 'drops', $s, 'a table, a column and an index dropped; an extension gone from the config — nothing here', drop: ['groups', 'products.weight'], expect: ['dropTable' => 1, 'dropColumn' => 1, 'dropIndex' => 1, 'dropExtension' => 0], check: static function (\PDO $db): void {
            expect(one($db, "SELECT count(*) FROM sqlite_master WHERE type = 'table' AND name = 'groups'") == 0, 'groups is gone');
            expect(self::index($db, 'products_title_idx') === null, 'the plain title index is gone');
        });

        // V020 — a version of code only.
        $steps[] = new Step(3, 'recount_totals', $s, 'a version of code only: totals recounted from the items', custom: true,
            after: "\$db->exec('UPDATE orders SET total = (SELECT coalesce(sum(qty * price), 0) FROM order_items i WHERE i.order_id = orders.id)');",
            check: static function (\PDO $db, bool $data): void {
                if ($data) {
                    expect(one($db, 'SELECT sum(total) FROM orders') == one($db, 'SELECT sum(qty * price) FROM order_items'), 'the totals are the items');
                }
            });

        // V021 — the kept column dropped at last; a NOT NULL column with a default added; a
        // foreign key of a repository made a named key of a table. Two tables renamed:
        // products → goods (a key of another table points at it), audit_log → audit_events
        // (its indexes, checks and foreign key named after it). No schema to move to here.
        $s = $s->with('audit_log', 'source', ['#[Varchar(20)]', "#[DefaultVal(\"'app'\")]"], "string \$source = 'app'")
            ->attrs('payments', 'order_id', fn($a) => Scenario::swap($a, '#[ForeignRepo(OrderRepo::class, onDelete: FKAction::CASCADE)]', "#[ForeignKey('orders', 'id', onDelete: FKAction::CASCADE, name: 'fk_payments_order')]"))
            ->retable('products', 'goods', null, "#[RenamedFrom('products')]")
            ->reclass('goods', 'Good', 'GoodRepo')
            ->attrs('order_items', 'product_id', fn($a) => Scenario::swap($a, "#[ForeignRepo(ProductRepo::class, onDelete: FKAction::CASCADE, name: 'fk_items_product')]", "#[ForeignRepo(GoodRepo::class, onDelete: FKAction::CASCADE, name: 'fk_items_product')]"))
            ->retable('audit_log', 'audit_events', null, "#[RenamedFrom('audit_log')]");
        $steps[] = new Step(3, 'tables_renamed', $s, 'two tables renamed with what is named after them; the kept column dropped at last; a NOT NULL column with a default; a foreign key renamed', drop: ['goods.colour'], expect: ['renameTable' => 2, 'dropColumn' => 1, 'addColumn' => 1, 'dropConstraint' => 4, 'addConstraint' => 4, 'dropIndex' => 5, 'addIndex' => 5], check: static function (\PDO $db, bool $data): void {
            expect(one($db, "SELECT group_concat(name, ',') FROM (SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('products', 'goods', 'audit_log', 'audit_events') ORDER BY name)") === 'audit_events,goods', 'the tables have their new names');
            expect(one($db, "SELECT count(*) FROM sqlite_master WHERE type = 'index' AND (name LIKE 'products\\_%' ESCAPE '\\' OR name LIKE 'audit\\_log\\_%' ESCAPE '\\')") == 0, 'no index keeps an old name');
            expect(($k = self::key($db, 'order_items', 'product_id')) === 'goods(id) ON UPDATE RESTRICT ON DELETE CASCADE', 'the key of order_items points at goods: ' . var_export($k, true));
            expect(self::column($db, 'goods', 'colour') === null, 'colour is gone');
            expect(self::named($db, 'payments', 'fk_payments_order'), 'the payments key has its name');
            if ($data) {
                expect(one($db, "SELECT count(*) FROM audit_events WHERE source = 'app'") == Data::AUDIT, 'every row moved, with the default');
                expect(one($db, 'SELECT count(*) FROM payments') == Data::ORDERS_PAID, 'no payment lost with its key');
            }
        });

        // V022 — at once: added (with an index of every parameter — WHERE kept, operator class
        // and INCLUDE left out), renamed (with an index on it), dropped (with a GiST one), a
        // unique index dropped.
        $s = Scenario::clean($s);
        $stock = $s->tables['goods']['props']['stock'][0];
        $s = $s->with('users', 'locale', ['#[Varchar(10)]', "#[Index(['email'], name: 'locale_full', method: IndexMethod::BTREE, where: \"locale <> ''\", opClass: 'varchar_pattern_ops', include: ['id'])]"], "string \$locale = 'en'")
            ->renamed('goods', 'stock', 'quantity', [...$stock, "#[RenamedFrom('stock')]"], 'int $quantity = 0')
            ->attrs('goods', 'price', fn($a) => Scenario::swap($a, "#[Index(['stock'], name: 'price_stock')]", "#[Index(['quantity'], name: 'price_stock')]"))
            ->without('orders', 'period')
            ->attrs('labels', 'name', fn($a) => Scenario::swap($a, "#[Unique(method: IndexMethod::BTREE, name: 'label_name')]", null))
            ->reclass('labels', 'Tag', 'TagRepo');
        $steps[] = new Step(3, 'last_mix', $s, 'added with an index of every parameter, renamed with an index on it, dropped with a GiST one, a unique index dropped; a table\'s classes renamed, the table not', drop: ['orders.period'], expect: ['addColumn' => 1, 'renameColumn' => 1, 'dropColumn' => 1, 'dropIndex' => 4, 'addIndex' => 3], said: [
            '/users_locale_full_idx: the operator class varchar_pattern_ops is PostgreSQL\'s/',
            '/users_locale_full_idx: INCLUDE \(id\) is PostgreSQL\'s/',
        ], check: static function (\PDO $db, bool $data): void {
            expect(self::index($db, 'goods_stock_cover_idx') === 'index quantity,title', 'the index is on quantity');
            expect(self::index($db, 'users_locale_full_idx') === 'index locale,email', 'the index has its columns');
            expect(str_contains((string) self::indexWhere($db, 'users_locale_full_idx'), "locale <> ''"), 'and its WHERE');
            expect(self::index($db, 'goods_price_stock_idx') === 'index price,quantity', 'the index naming stock names quantity');
            if ($data) {
                expect(one($db, 'SELECT sum(quantity) FROM goods') == Data::STOCK_SUM, 'the stock is in quantity');
            }
        });

        // V023 — before() and after() of one version, one instance: the notes kept in the
        // instance while the steps make a table and drop the column.
        $s = Scenario::clean($s)->without('orders', 'note')->table('order_notes', [
            'class' => 'OrderNote', 'repo' => 'OrderNoteRepo', 'schema' => null, 'attrs' => [],
            'props' => [
                'id' => [['#[BigId]'], '?int $id = null'],
                'order_id' => [['#[BigInteger]', '#[ForeignRepo(OrderRepo::class, onDelete: FKAction::CASCADE)]'], 'int $order_id'],
                'body' => [['#[Text]'], "string \$body = ''"],
            ],
        ]);
        $steps[] = new Step(3, 'notes_moved', $s, 'before() and after() of one version hand rows over: the notes read before the column is dropped, written to a new table after', drop: ['orders.note'], custom: true, expect: ['createTable' => 1, 'dropColumn' => 1, 'addConstraint' => 1],
            props: 'private array $notes = [];',
            before: "\$this->notes = \$db->query(\"SELECT id, note FROM orders WHERE note <> '' ORDER BY id\")->fetchAll(\\PDO::FETCH_KEY_PAIR);",
            after: "\$add = \$db->prepare('INSERT INTO order_notes (order_id, body) VALUES (?, ?)'); foreach (\$this->notes as \$id => \$note) { \$add->execute([\$id, \$note]); }",
            check: static function (\PDO $db, bool $data): void {
                expect(self::column($db, 'orders', 'note') === null, 'orders.note is gone');
                if ($data) {
                    expect(one($db, 'SELECT count(*) FROM order_notes') == Data::ORDERS - Data::ORDERS_WITHOUT_NOTE, 'every note handed over from before() to after()');
                    expect(one($db, "SELECT group_concat(body, ',') FROM (SELECT body FROM order_notes WHERE order_id IN (1, 2, 3, 4) ORDER BY order_id)") === 'note 1,note 2,note 4', 'each note at its order');
                }
            });

        return $steps;
    }

    /** V001: six tables, every attribute ppa has — what SQLite has not, declared and left out. */
    public static function initial(): State
    {
        return new State([
            'groups' => ['class' => 'Group', 'repo' => 'GroupRepo', 'schema' => null, 'attrs' => [], 'props' => [
                'id' => [['#[SmallId]'], '?int $id = null'],
                'name' => [['#[Varchar(80)]', '#[Unique]'], "string \$name = ''"],
                'description' => [['#[Text]'], '?string $description = null'],
                'active' => [['#[Boolean]', "#[DefaultVal('1')]"], 'bool $active = true'],
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
            'products' => ['class' => 'Product', 'repo' => 'ProductRepo', 'schema' => null, 'attrs' => [], 'props' => [
                'id' => [['#[UuidPk]'], '?string $id = null'],
                'sku' => [['#[Char(12)]', '#[Unique]'], 'string $sku'],
                'title' => [['#[Varchar(200)]', '#[Index]', '#[Index(method: IndexMethod::HASH)]'], "string \$title = ''"],
                'price' => [['#[Decimal(12, 2)]', "#[Check('price >= 0', name: 'chk_products_price')]"], "string \$price = '0'"],
                'stock' => [['#[Integer]', "#[Index(['title'], include: ['price'], name: 'stock_cover')]"], 'int $stock = 0'],
                'weight' => [['#[FloatType]'], '?float $weight = null'],
                'photo' => [["#[Blob(size: 'medium')]"], '?string $photo = null'],
                'digest' => [['#[Binary(32)]'], '?string $digest = null'],
                'released' => [["#[Type('NUMERIC')]"], '?int $released = null'],
            ]],
            'orders' => ['class' => 'Order', 'repo' => 'OrderRepo', 'schema' => null, 'attrs' => ["#[Check('total >= 0', name: 'chk_orders_total')]"], 'props' => [
                'id' => [['#[BigId(always: true)]'], '?int $id = null'],
                'user_id' => [['#[BigInteger]', "#[ForeignKey('users', 'id', onUpdate: FKAction::CASCADE, onDelete: FKAction::CASCADE)]"], 'int $user_id'],
                'number' => [['#[Varchar(30)]', '#[Unique]'], 'string $number'],
                'total' => [['#[Decimal(12, 2)]', "#[DefaultVal('0')]"], "string \$total = '0'"],
                'note' => [['#[Text]'], '?string $note = null'],
                'paid' => [['#[Boolean]', "#[DefaultVal('0')]", "#[Index(where: 'paid = 0', name: 'unpaid')]"], 'bool $paid = false'],
                'period' => [["#[Type('TEXT')]", '#[Index(method: IndexMethod::GIST)]'], '?string $period = null'],
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
        ], ['pg_trgm' => "'pg_trgm', version: '1.6', schema: 'shop', cascade: true"]);
    }

    /**
     * What `snapshot` must refuse on the last state: what every driver refuses, and what
     * SQLite cannot do.
     *
     * @return list<array{0: string, 1: State, 2: ?string}>
     */
    public static function refused(State $last): array
    {
        return [
            ['a column gone, no --drop', $last->without('users', 'locale'), '/users\.locale is gone from the entity/'],
            ['a table gone, no --drop', $last->withoutTable('audit_events'), '/audit_events has no entity any more/'],
            ['a table renamed without #[RenamedFrom]', $last->retable('labels', 'tags', null, null), '/labels has no entity any more/'],
            ['a key on the old name of a renamed table', $last->retable('labels', 'tags', null, "#[RenamedFrom('labels')]")->with('payments', 'label_id', ['#[SmallInteger]', "#[ForeignKey('labels', 'id')]"], '?int $label_id = null'), '/the foreign key fk_payments_label_id points at labels, renamed to tags/'],
            ['#[RenamedFrom] of no table', $last->table('labels', ['attrs' => ["#[RenamedFrom('nope')]"]]), '/`nope` is not a table of the last snapshot/'],
            ['#[RenamedFrom] of a table declared too', $last->table('labels', ['attrs' => ["#[RenamedFrom('users')]"]]), '/`users` is a table of the entities too/'],
            ['a rename without #[RenamedFrom]', $last->renamed('users', 'email', 'mail', ['#[Varchar(160)]', '#[Unique]'], "string \$mail = ''"), '/users\.email is gone from the entity/'],
            ['#[RenamedFrom] of no column', $last->with('users', 'nick', ['#[Varchar(20)]', "#[RenamedFrom('nope')]"], '?string $nick = null'), '/`nope` is not a column of the last snapshot/'],
            ['NOT NULL without a default', $last->with('users', 'code', ['#[Varchar(8)]'], 'string $code'), '/users\.code is NOT NULL with no default/'],
            ['a narrowing, not custom', $last->attrs('users', 'name', fn($a) => Scenario::swap($a, '#[Varchar(200)]', '#[Varchar(50)]')), '/users\.name/'],
            ['a primary key changed, not custom', $last->attrs('order_items', 'qty', fn($a) => Scenario::swap($a, '#[Primary]', null)), '/primary key changes/'],
            ['a unique HASH index', $last->attrs('users', 'locale', fn($a) => [...$a, '#[Unique(method: IndexMethod::HASH)]']), '/a unique index is a B-tree/'],
            ['two indexes of one name', $last->attrs('users', 'locale', fn($a) => [...$a, '#[Index]', '#[Index]']), '/two indexes are named users_locale_idx/'],
            ['an index of a column there is not', $last->attrs('users', 'locale', fn($a) => [...$a, "#[Index(['nick'])]"]), '/nick is no column of users/'],
            ['a column named by a word of the server', $last->with('users', 'order', ['#[Integer]'], 'int $order = 0'), '/users\.order: `order` is a word of SQLite/'],
            // What SQLite has not.
            ['a table moved into a schema', $last->retable('labels', 'labels', 'shop', "#[RenamedFrom('labels')]"), '/SQLite has no schema to move a table to/'],
            ['an id in a key of two columns', $last->attrs('audit_events', 'happened', fn($a) => [...$a, '#[Primary]']), '/audit_events\.id: SQLite counts an id only as the whole primary key/'],
        ];
    }

    // ── What the checks read ─────────────────────────────────────────────────

    /** An index: `unique|index col,col`; null when there is none. */
    private static function index(\PDO $db, string $name): ?string
    {
        $table = one($db, "SELECT tbl_name FROM sqlite_master WHERE type = 'index' AND name = '{$name}'");
        if (!is_string($table)) {
            return null;
        }
        $unique = 0;
        foreach ($db->query("PRAGMA index_list(\"{$table}\")")->fetchAll(\PDO::FETCH_ASSOC) as $i) {
            $unique = $i['name'] === $name ? (int) $i['unique'] : $unique;
        }
        $cols = array_column($db->query("PRAGMA index_info(\"{$name}\")")->fetchAll(\PDO::FETCH_ASSOC), 'name');
        return ($unique ? 'unique ' : 'index ') . implode(',', $cols);
    }

    /** The WHERE of a partial index; null when it has none. */
    private static function indexWhere(\PDO $db, string $name): ?string
    {
        $sql = (string) one($db, "SELECT sql FROM sqlite_master WHERE type = 'index' AND name = '{$name}'");
        return preg_match('/\bWHERE\b(.*)$/is', $sql, $m) ? trim($m[1]) : null;
    }

    /** The foreign key of a column: `table(col) ON UPDATE … ON DELETE …`; null when none. */
    private static function key(\PDO $db, string $table, string $column): ?string
    {
        foreach ($db->query("PRAGMA foreign_key_list(\"{$table}\")")->fetchAll(\PDO::FETCH_ASSOC) as $k) {
            if ($k['from'] === $column) {
                return "{$k['table']}({$k['to']}) ON UPDATE {$k['on_update']} ON DELETE {$k['on_delete']}";
            }
        }
        return null;
    }

    /** Whether the table's CREATE TABLE names a constraint so. */
    private static function named(\PDO $db, string $table, string $name): bool
    {
        $sql = (string) one($db, "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = '{$table}'");
        return (bool) preg_match('/\bCONSTRAINT\s+' . preg_quote($name, '/') . '\b/i', $sql);
    }

    /** A named check's expression; null when there is none. */
    private static function check(\PDO $db, string $table, string $name): ?string
    {
        $sql = (string) one($db, "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = '{$table}'");
        return preg_match('/\bCONSTRAINT\s+' . preg_quote($name, '/') . '\s+CHECK\s*\((.*?)\)\s*(,|\)\s*$)/is', $sql, $m) ? $m[1] : null;
    }

    /** A column: `TYPE` and ` NOT NULL` when it is; null when there is none. */
    private static function column(\PDO $db, string $table, string $column): ?string
    {
        foreach ($db->query("PRAGMA table_info(\"{$table}\")")->fetchAll(\PDO::FETCH_ASSOC) as $c) {
            if ($c['name'] === $column) {
                return strtoupper((string) $c['type']) . ($c['notnull'] ? ' NOT NULL' : '');
            }
        }
        return null;
    }

    private static function defaultOf(\PDO $db, string $table, string $column): ?string
    {
        foreach ($db->query("PRAGMA table_info(\"{$table}\")")->fetchAll(\PDO::FETCH_ASSOC) as $c) {
            if ($c['name'] === $column) {
                return $c['dflt_value'];
            }
        }
        return null;
    }

    private static function primaryKey(\PDO $db, string $table): string
    {
        $key = array_filter($db->query("PRAGMA table_info(\"{$table}\")")->fetchAll(\PDO::FETCH_ASSOC), fn($c) => $c['pk'] > 0);
        usort($key, fn($a, $b) => $a['pk'] <=> $b['pk']);
        return implode(',', array_column($key, 'name'));
    }

    private static function autoincrement(\PDO $db, string $table): bool
    {
        return stripos((string) one($db, "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = '{$table}'"), 'AUTOINCREMENT') !== false;
    }

    /** Whether SQLite refuses the statement — tried in a transaction, undone either way. */
    private static function refusedSql(\PDO $db, string $sql): bool
    {
        $db->beginTransaction();
        try {
            $db->exec($sql);
            return false;
        } catch (\PDOException) {
            return true;
        } finally {
            $db->rollBack();
        }
    }
}
