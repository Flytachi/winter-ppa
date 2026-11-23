<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\E2e;

/**
 * The life of an application on MariaDB — the life {@see PgScenario} tells on PostgreSQL, as
 * far as the server goes, version for version.
 *
 * What MariaDB has not is declared all the same, where ppa leaves it out and says so: an
 * index's WHERE, INCLUDE and operator class, HASH (built as a B-tree), `#[Extension]`,
 * `always:` on an id. What it cannot build as declared is in {@see refused()}: GIN, GiST, a
 * partial unique index, SET DEFAULT on a foreign key. {@see Runner::coverage()} counts both.
 *
 * The tables of no schema live in the database of the connection; `shop` is a database
 * beside it.
 */
final class MariaScenario
{
    /** @return list<Step> */
    public static function steps(): array
    {
        $s = self::initial();
        $steps = [];

        // ── Stage 1 ─────────────────────────────────────────────────────────

        $steps[] = new Step(1, 'init', $s, 'six tables, every attribute; the extension is PostgreSQL\'s — none here', expect: ['createTable' => 6, 'addIndex' => 12, 'addConstraint' => 9, 'createSchema' => 0, 'createExtension' => 0], said: [
            '/users_name_hash_idx: HASH is built as a B-tree/',
            '/users_name_hash_idx: the operator class varchar_pattern_ops is PostgreSQL\'s/',
            "/users_status_idx: .*no partial index — built over every row, without WHERE status <> 'deleted'/",
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
            keep: ['shop.products.colour'],
            expect: ['dropColumn' => 3, 'detachColumn' => 1],
        );

        $s = $s->with('users', 'born', ['#[Date]'], '?string $born = null')
            ->renamed('orders', 'comment', 'note', ['#[Text]', "#[RenamedFrom('comment')]"], '?string $note = null')
            ->without('orders', 'discount');
        $steps[] = new Step(1, 'back_to_the_start', $s, 'added, renamed and dropped in one version: the shape of V001 again',
            drop: ['orders.discount'],
            expect: ['addColumn' => 1, 'renameColumn' => 1, 'dropColumn' => 1],
            check: static function (\PDO $db): void {
                expect(one($db, "SELECT count(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME IN ('comment', 'discount')") == 0, 'orders is back to its columns of V001');
            },
        );

        // ── Stage 3 ─────────────────────────────────────────────────────────
        $s = Scenario::clean($s);

        // V006 — indexes: a named index renamed, a composite unique index with a name; a WHERE
        // and an INCLUDE declared (left out here, said).
        $s = $s->attrs('users', 'status', fn($a) => Scenario::swap($a, "#[Index(where: \"status <> 'deleted'\")]", "#[Index(where: \"status IN ('active', 'new')\")]"))
            ->attrs('audit_log', 'event', fn($a) => Scenario::swap($a, "#[Index(method: IndexMethod::BTREE, name: 'event_btree')]", "#[Index(method: IndexMethod::BTREE, name: 'audit_event')]"))
            ->attrs('users', 'email', fn($a) => Scenario::swap($a, '#[Unique]', "#[Unique(include: ['name'])]"))
            ->attrs('orders', 'number', fn($a) => [...$a, "#[Unique(['placed_at'], name: 'number_day')]"]);
        $steps[] = new Step(3, 'indexes_remade', $s, 'a named index renamed, a composite unique one; a WHERE changed and an INCLUDE — left out here', expect: ['dropIndex' => 1, 'addIndex' => 2], said: ['/users_email_udx: INCLUDE \(name\) is PostgreSQL\'s/', "/without WHERE status IN \\('active', 'new'\\)/"], check: static function (\PDO $db): void {
            expect(self::index($db, 'orders_number_day_udx') === 'unique number,placed_at', 'the composite unique index is there');
            expect(self::index($db, 'audit_log_audit_event_idx') === 'index event', 'the renamed index is there');
            expect(self::index($db, 'audit_log_event_btree_idx') === null, 'the old name is gone');
            expect(self::index($db, 'users_email_udx') === 'unique email', 'the unique index is on email alone: no INCLUDE here');
        });

        // V007 — index methods: HASH (a B-tree here) named and made explicit, an operator class
        // (left out), a composite index.
        $s = $s->attrs('products', 'title', fn($a) => Scenario::swap($a, '#[Index(method: IndexMethod::HASH)]', "#[Index(method: IndexMethod::BTREE, name: 'title_b')]"))
            ->attrs('products', 'price', fn($a) => [...$a, "#[Index(['stock'], name: 'price_stock')]"])
            ->attrs('users', 'email', fn($a) => Scenario::swap($a, "#[Unique(include: ['name'])]", "#[Unique(include: ['name'], opClass: 'varchar_pattern_ops')]"));
        $steps[] = new Step(3, 'index_methods', $s, 'HASH (a B-tree here) → a named B-tree, a composite index; an operator class — left out here', expect: ['dropIndex' => 1, 'addIndex' => 2], said: ['/users_email_udx: the operator class varchar_pattern_ops is PostgreSQL\'s/'], check: static function (\PDO $db): void {
            expect(self::index($db, 'products_title_b_idx') === 'index title', 'the named B-tree is there');
            expect(self::index($db, 'products_title_hash_idx') === null, 'the HASH one is gone');
            expect(self::index($db, 'products_price_stock_idx') === 'index price,stock', 'the composite index is there');
        });

        // V008 — a unique index over duplicates (before() makes them distinct), and a composite
        // one with a name and an explicit method.
        $s = $s->attrs('users', 'name', fn($a) => [...$a, '#[Unique]', "#[Unique(['age'], name: 'name_age', method: IndexMethod::BTREE)]"]);
        $steps[] = new Step(3, 'unique_over_duplicates', $s, 'unique indexes on a column with duplicates: before() makes them distinct', custom: true, expect: ['addIndex' => 2],
            before: "\$db->exec(\"UPDATE users SET name = CONCAT(name, ' #', id) WHERE id NOT IN (SELECT id FROM (SELECT min(id) AS id FROM users GROUP BY name) AS firsts)\");",
            check: static function (\PDO $db, bool $data): void {
                if ($data) {
                    expect(one($db, 'SELECT count(DISTINCT name) FROM users') == Data::USERS, 'every user keeps a distinct name');
                    expect(one($db, "SELECT count(*) FROM users WHERE name LIKE 'Ann #%'") == 2, 'the two later Anns are renamed');
                }
            });

        // V009 — a composite index added, a named index moved to another column; INCLUDE
        // widened (left out here).
        $s = $s->attrs('products', 'stock', fn($a) => Scenario::swap($a, "#[Index(['title'], include: ['price'], name: 'stock_cover')]", "#[Index(['title'], include: ['price', 'sku'], name: 'stock_cover')]"))
            ->attrs('orders', 'user_id', fn($a) => [...$a, "#[Index(['placed_at'])]"])
            ->attrs('audit_log', 'event', fn($a) => Scenario::swap($a, "#[Index(method: IndexMethod::BTREE, name: 'audit_event')]", null))
            ->attrs('audit_log', 'user_ref', fn($a) => [...$a, "#[Index(method: IndexMethod::BTREE, name: 'audit_event')]"]);
        $steps[] = new Step(3, 'indexes_moved', $s, 'a composite index added, a named one moved to another column', expect: ['dropIndex' => 1, 'addIndex' => 2], said: ['/products_stock_cover_idx: INCLUDE \(price, sku\) is PostgreSQL\'s/'], check: static function (\PDO $db): void {
            expect(self::index($db, 'audit_log_audit_event_idx') === 'index user_ref', 'audit_event is on user_ref now');
            expect(self::index($db, 'orders_user_id_placed_at_idx') === 'index user_id,placed_at', 'the composite index is there');
        });

        // V010 — foreign keys: every action InnoDB keeps, a key renamed by its name.
        $s = $s->attrs('orders', 'user_id', fn($a) => Scenario::swap($a, "#[ForeignKey('users', 'id', onUpdate: FKAction::CASCADE, onDelete: FKAction::CASCADE)]", "#[ForeignKey('users', 'id', onUpdate: FKAction::NO_ACTION, onDelete: FKAction::RESTRICT, name: 'fk_orders_user')]"))
            ->attrs('order_items', 'order_id', fn($a) => Scenario::swap($a, '#[ForeignRepo(OrderRepo::class, onDelete: FKAction::CASCADE)]', '#[ForeignRepo(OrderRepo::class, onUpdate: FKAction::CASCADE, onDelete: FKAction::CASCADE)]'))
            ->attrs('order_items', 'product_id', fn($a) => Scenario::swap($a, "#[ForeignRepo(ProductRepo::class, onDelete: FKAction::RESTRICT, name: 'fk_items_product')]", "#[ForeignRepo(ProductRepo::class, onDelete: FKAction::CASCADE, name: 'fk_items_product')]"));
        $steps[] = new Step(3, 'foreign_key_actions', $s, 'ON UPDATE / ON DELETE changed on three keys, one renamed by its name', expect: ['dropConstraint' => 3, 'addConstraint' => 3], check: static function (\PDO $db, bool $data): void {
            expect(self::key($db, 'fk_items_product') === 'product_id → shop.products(id) ON UPDATE RESTRICT ON DELETE CASCADE', 'fk_items_product cascades');
            expect(self::key($db, 'fk_order_items_order_id') === 'order_id → orders(id) ON UPDATE CASCADE ON DELETE CASCADE', 'the items follow their order');
            expect(self::key($db, 'fk_orders_user') !== null && self::key($db, 'fk_orders_user_id') === null, 'fk_orders_user_id is now fk_orders_user');
            if ($data) {
                // What the keys do, then undone: no row of the test is lost.
                $db->beginTransaction();
                $product = one($db, 'SELECT id FROM shop.products ORDER BY sku LIMIT 1');
                $items = (int) one($db, "SELECT count(*) FROM order_items WHERE product_id = '{$product}'");
                $db->exec("DELETE FROM shop.products WHERE id = '{$product}'");
                expect($items > 0 && one($db, 'SELECT count(*) FROM order_items') == Data::ITEMS - $items, 'deleting a product deletes its items');
                $db->rollBack();
                expect(self::refusedSql($db, 'DELETE FROM users WHERE id = 1'), 'a user with orders cannot be deleted (RESTRICT)');
            }
        });

        // V011 — a foreign key dropped; one added over rows that point nowhere (before() clears
        // them), with SET NULL — SET DEFAULT is not InnoDB's ({@see refused()}).
        $s = $s->attrs('users', 'group_id', fn($a) => Scenario::swap($a, '#[ForeignRepo(GroupRepo::class, onDelete: FKAction::SET_NULL)]', null))
            ->attrs('audit_log', 'user_ref', fn($a) => [...$a, '#[ForeignRepo(UserRepo::class, onUpdate: FKAction::CASCADE, onDelete: FKAction::SET_NULL)]']);
        $steps[] = new Step(3, 'foreign_key_over_orphans', $s, 'a foreign key dropped, one added over rows that point nowhere: before() clears them', custom: true, expect: ['dropConstraint' => 1, 'addConstraint' => 1],
            before: "\$db->exec('UPDATE audit_log SET user_ref = NULL WHERE user_ref IS NOT NULL AND user_ref NOT IN (SELECT id FROM users)');",
            check: static function (\PDO $db, bool $data): void {
                expect(self::key($db, 'fk_audit_log_user_ref') === 'user_ref → users(id) ON UPDATE CASCADE ON DELETE SET NULL', 'the key has its actions');
                if ($data) {
                    expect(one($db, 'SELECT count(*) FROM audit_log WHERE user_ref IS NOT NULL') == Data::AUDIT_VALID_REFS, 'the valid references stay');
                }
            });

        // V012 — widened by the kernel: VARCHAR, DECIMAL, an integer, an id (SMALLINT → INT).
        $s = $s->attrs('users', 'name', fn($a) => Scenario::swap($a, '#[Varchar(120)]', '#[Varchar(200)]'))
            ->attrs('orders', 'total', fn($a) => Scenario::swap($a, '#[Decimal(12, 2)]', '#[Decimal(14, 2)]'))
            ->attrs('audit_log', 'level', fn($a) => Scenario::swap($a, '#[SmallInteger]', '#[Integer]'))
            ->attrs('groups', 'id', fn($a) => Scenario::swap($a, '#[SmallId]', '#[Id]'));
        $steps[] = new Step(3, 'widen_types', $s, 'VARCHAR, DECIMAL, an integer and an id widened, by the kernel', expect: ['alterColumn' => 4], check: static function (\PDO $db, bool $data): void {
            expect(self::column($db, 'users', 'name') === 'varchar(200) NO', 'users.name is VARCHAR(200)');
            expect(self::column($db, 'groups', 'id') === 'int(11) NO auto_increment', 'groups.id is an INT auto_increment');
            if ($data) {
                expect(one($db, 'SELECT sum(total) FROM orders') == Data::ORDERS_TOTAL, 'the totals are untouched');
                expect(one($db, 'SELECT count(*) FROM `groups`') == Data::GROUPS, 'the groups are there');
            }
        });

        // V013 — a narrowing by after(), and a check's expression changed.
        $s = $s->attrs('products', 'sku', fn($a) => Scenario::swap($a, '#[Char(12)]', '#[Varchar(10)]'))
            ->attrs('products', 'price', fn($a) => Scenario::swap($a, "#[Check('price >= 0', name: 'chk_products_price')]", "#[Check('price >= 0 AND price < 1000000', name: 'chk_products_price')]"));
        $steps[] = new Step(3, 'narrow_sku', $s, 'CHAR(12) → VARCHAR(10) by after(); a check made stricter', custom: true, expect: ['alterColumn' => 1, 'dropConstraint' => 1, 'addConstraint' => 1],
            after: "\$db->exec('ALTER TABLE shop.products MODIFY COLUMN sku VARCHAR(10) NOT NULL');",
            check: static function (\PDO $db, bool $data): void {
                expect(str_contains((string) self::check($db, 'chk_products_price'), '1000000'), 'the check is the new one');
                if ($data) {
                    expect(one($db, 'SELECT count(DISTINCT sku) FROM shop.products') == Data::PRODUCTS, 'every sku is still distinct');
                    expect(one($db, 'SELECT sku FROM shop.products ORDER BY sku LIMIT 1') === 'SKU-000001', 'the sku keeps its value');
                }
            });

        // V014 — NOT NULL over NULLs (before() fills them), and #[NullableIs(false)] on a
        // nullable property.
        $s = $s->with('orders', 'note', ['#[Text]', "#[DefaultVal(\"''\")]"], "string \$note = ''")
            ->attrs('users', 'rating', fn($a) => [...$a, '#[NullableIs(false)]', "#[DefaultVal('0')]"]);
        $steps[] = new Step(3, 'not_null', $s, 'NOT NULL over NULLs: before() fills them; #[NullableIs(false)] on a ?float', custom: true, expect: ['alterColumn' => 2],
            before: "\$db->exec(\"UPDATE orders SET note = '' WHERE note IS NULL\");",
            check: static function (\PDO $db, bool $data): void {
                expect(str_ends_with((string) self::column($db, 'orders', 'note'), ' NO') && str_ends_with((string) self::column($db, 'users', 'rating'), ' NO'), 'orders.note and users.rating are NOT NULL');
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
            expect(self::check($db, 'chk_orders_total') === null && self::check($db, 'chk_orders_total_positive') !== null, 'the table check is renamed');
            expect(one($db, "SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'status'") === "'new'", 'the default is new');
        });

        // V016 — a column renamed with its foreign key and its composite index.
        $userId = $s->tables['orders']['props']['user_id'][0];
        $s = $s->renamed('orders', 'user_id', 'customer_id', [...$userId, "#[RenamedFrom('user_id')]"], 'int $customer_id');
        $steps[] = new Step(3, 'rename_with_key', $s, 'a column with a foreign key and a composite index renamed', expect: ['renameColumn' => 1, 'dropConstraint' => 1, 'addConstraint' => 1, 'dropIndex' => 1, 'addIndex' => 1], check: static function (\PDO $db, bool $data): void {
            expect(str_starts_with((string) self::key($db, 'fk_orders_user'), 'customer_id → users(id)'), 'the foreign key follows the column');
            if ($data) {
                expect(one($db, 'SELECT sum(customer_id) FROM orders') == Data::ORDERS_USER_SUM, 'every order keeps its customer');
            }
        });

        // V017 — two new tables (`always:` — no effect here: AUTO_INCREMENT), filled by after().
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
        $steps[] = new Step(3, 'new_tables', $s, 'two new tables, one in the database shop, filled by after()', custom: true, expect: ['createTable' => 2, 'addConstraint' => 2, 'addIndex' => 2],
            after: "\$db->exec('INSERT INTO shop.payments (order_id, amount, paid_at) SELECT id, total, placed_at FROM orders WHERE paid'); \$db->exec(\"INSERT INTO labels (name) VALUES ('red'), ('green'), ('blue')\");",
            check: static function (\PDO $db, bool $data): void {
                expect(self::column($db, 'labels', 'id') === 'smallint(6) NO auto_increment', 'labels.id is a SMALLINT auto_increment');
                if ($data) {
                    expect(one($db, 'SELECT count(*) FROM shop.payments') == Data::ORDERS_PAID, 'a payment per paid order');
                    expect(one($db, 'SELECT count(*) FROM labels') == 3, 'three labels');
                }
            });

        // V018 — ids: the primary key (id) → (id, happened) by after(); `always:` turned on and
        // off — nothing for MariaDB.
        $s = $s->attrs('audit_log', 'happened', fn($a) => [...$a, '#[Primary]'])
            ->attrs('audit_log', 'id', fn($a) => Scenario::swap($a, '#[AutoIncrement]', '#[AutoIncrement(always: true)]'))
            ->attrs('orders', 'id', fn($a) => Scenario::swap($a, '#[BigId(always: true)]', '#[BigId]'));
        $steps[] = new Step(3, 'ids', $s, 'a primary key of two columns (by after()); `always:` on and off — nothing here', custom: true, expect: ['setPrimaryKey' => 1, 'alterColumn' => 0],
            after: "\$db->exec('ALTER TABLE audit_log DROP PRIMARY KEY, ADD PRIMARY KEY (id, happened)');",
            check: static function (\PDO $db): void {
                expect(self::index($db, 'PRIMARY', 'audit_log') === 'unique id,happened', 'audit_log has a key of two columns');
            });

        // V019 — dropped: a table, a column, an index. The extension leaves the config: nothing
        // here to drop.
        $s = $s->withoutTable('groups')->without('products', 'weight')
            ->attrs('products', 'title', fn($a) => Scenario::swap($a, '#[Index]', null))
            ->withoutExtension('pg_trgm');
        $steps[] = new Step(3, 'drops', $s, 'a table, a column and an index dropped; an extension gone from the config — nothing here', drop: ['groups', 'shop.products.weight'], expect: ['dropTable' => 1, 'dropColumn' => 1, 'dropIndex' => 1, 'dropExtension' => 0], check: static function (\PDO $db): void {
            expect(one($db, "SELECT count(*) FROM information_schema.TABLES WHERE TABLE_NAME = 'groups'") == 0, 'groups is gone');
            expect(self::index($db, 'products_title_idx') === null, 'the plain title index is gone');
        });

        // V020 — a version of code only.
        $steps[] = new Step(3, 'recount_totals', $s, 'a version of code only: totals recounted from the items', custom: true,
            after: "\$db->exec('UPDATE orders o SET total = (SELECT coalesce(sum(qty * price), 0) FROM order_items i WHERE i.order_id = o.id)');",
            check: static function (\PDO $db, bool $data): void {
                if ($data) {
                    expect(one($db, 'SELECT sum(total) FROM orders') == one($db, 'SELECT sum(qty * price) FROM order_items'), 'the totals are the items');
                }
            });

        // V021 — the kept column dropped at last; a NOT NULL column with a default added; a
        // foreign key of a repository made a named key of a table.
        // Two tables renamed: products → goods (a key of another table points at it), and
        // audit_log moved to the database shop as audit_events (with its key of two columns,
        // its indexes, checks and foreign key named after it).
        $s = $s->with('audit_log', 'source', ['#[Varchar(20)]', "#[DefaultVal(\"'app'\")]"], "string \$source = 'app'")
            ->attrs('payments', 'order_id', fn($a) => Scenario::swap($a, '#[ForeignRepo(OrderRepo::class, onDelete: FKAction::CASCADE)]', "#[ForeignKey('orders', 'id', onDelete: FKAction::CASCADE, name: 'fk_payments_order')]"))
            ->retable('products', 'goods', 'shop', "#[RenamedFrom('products')]")
            ->reclass('goods', 'Good', 'GoodRepo')
            ->attrs('order_items', 'product_id', fn($a) => Scenario::swap($a, "#[ForeignRepo(ProductRepo::class, onDelete: FKAction::CASCADE, name: 'fk_items_product')]", "#[ForeignRepo(GoodRepo::class, onDelete: FKAction::CASCADE, name: 'fk_items_product')]"))
            ->retable('audit_log', 'audit_events', 'shop', "#[RenamedFrom('audit_log')]");
        $steps[] = new Step(3, 'tables_renamed', $s, 'two tables renamed, one into another database, with what is named after them; the kept column dropped at last; a NOT NULL column with a default; a foreign key renamed', drop: ['shop.goods.colour'], expect: ['renameTable' => 2, 'dropColumn' => 1, 'addColumn' => 1, 'dropConstraint' => 4, 'addConstraint' => 4, 'dropIndex' => 0, 'addIndex' => 0], check: static function (\PDO $db, bool $data): void {
            expect(one($db, "SELECT GROUP_CONCAT(CONCAT(TABLE_SCHEMA, '.', TABLE_NAME) ORDER BY TABLE_NAME) FROM information_schema.TABLES WHERE TABLE_NAME IN ('products', 'goods', 'audit_log', 'audit_events')") === 'shop.audit_events,shop.goods', 'the tables have their new names');
            expect(one($db, "SELECT count(*) FROM information_schema.STATISTICS WHERE TABLE_NAME IN ('goods', 'audit_events') AND (INDEX_NAME LIKE 'products\\_%' OR INDEX_NAME LIKE 'audit\\_log\\_%')") == 0, 'no index keeps an old name');
            expect(self::key($db, 'fk_items_product') === 'product_id → shop.goods(id) ON UPDATE RESTRICT ON DELETE CASCADE', 'the key of order_items points at goods');
            expect(self::column($db, 'goods', 'colour') === null, 'colour is gone');
            expect(self::key($db, 'fk_payments_order') !== null, 'the payments key has its name');
            if ($data) {
                expect(one($db, "SELECT count(*) FROM shop.audit_events WHERE source = 'app'") == Data::AUDIT, 'every row moved, with the default');
                expect(one($db, 'SELECT count(*) FROM shop.payments') == Data::ORDERS_PAID, 'no payment lost with its key');
            }
        });

        // V022 — at once: added (with an index of every parameter — WHERE, operator class and
        // INCLUDE left out here), renamed (with an index on it), dropped, a unique index dropped.
        $s = Scenario::clean($s);
        $stock = $s->tables['goods']['props']['stock'][0];
        $s = $s->with('users', 'locale', ['#[Varchar(10)]', "#[Index(['email'], name: 'locale_full', method: IndexMethod::BTREE, where: \"locale <> ''\", opClass: 'varchar_pattern_ops', include: ['id'])]"], "string \$locale = 'en'")
            ->renamed('goods', 'stock', 'quantity', [...$stock, "#[RenamedFrom('stock')]"], 'int $quantity = 0')
            // an index of another column naming it: named anew with it
            ->attrs('goods', 'price', fn($a) => Scenario::swap($a, "#[Index(['stock'], name: 'price_stock')]", "#[Index(['quantity'], name: 'price_stock')]"))
            ->without('orders', 'period')
            ->attrs('labels', 'name', fn($a) => Scenario::swap($a, "#[Unique(method: IndexMethod::BTREE, name: 'label_name')]", null))
            ->reclass('labels', 'Tag', 'TagRepo');
        $steps[] = new Step(3, 'last_mix', $s, 'added with an index of every parameter, renamed with an index on it, dropped, a unique index dropped; a table\'s classes renamed, the table not', drop: ['orders.period'], expect: ['addColumn' => 1, 'renameColumn' => 1, 'dropColumn' => 1, 'dropIndex' => 3, 'addIndex' => 3], said: [
            "/users_locale_full_idx: .*without WHERE locale <> ''/",
            '/users_locale_full_idx: the operator class varchar_pattern_ops is PostgreSQL\'s/',
            '/users_locale_full_idx: INCLUDE \(id\) is PostgreSQL\'s/',
        ], check: static function (\PDO $db, bool $data): void {
            expect(self::index($db, 'goods_stock_cover_idx') === 'index quantity,title', 'the index is on quantity');
            expect(self::index($db, 'users_locale_full_idx') === 'index locale,email', 'the index has its columns, nothing PostgreSQL\'s');
            expect(self::index($db, 'labels_label_name_udx') === null, 'the unique index is gone');
            expect(self::index($db, 'goods_price_stock_idx') === 'index price,quantity', 'the index naming stock names quantity');
            if ($data) {
                expect(one($db, 'SELECT sum(quantity) FROM shop.goods') == Data::STOCK_SUM, 'the stock is in quantity');
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
                    expect(one($db, 'SELECT GROUP_CONCAT(body ORDER BY order_id) FROM order_notes WHERE order_id IN (1, 2, 3, 4)') === 'note 1,note 2,note 4', 'each note at its order');
                }
            });

        return $steps;
    }

    /** V001: six tables, every attribute ppa has — what MariaDB has not, declared and left out. */
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
                'name' => [['#[Varchar(120)]', "#[Index(method: IndexMethod::HASH, opClass: 'varchar_pattern_ops')]"], "string \$name = ''"],
                'nickname' => [['#[Varchar(30)]', '#[NullableIs(false)]', "#[DefaultVal(\"''\")]"], '?string $nickname = null'],
                'group_id' => [['#[SmallInteger]', '#[ForeignRepo(GroupRepo::class, onDelete: FKAction::SET_NULL)]'], '?int $group_id = null'],
                'age' => [['#[SmallInteger]', "#[Check('age >= 0')]", "#[DefaultVal('0')]"], 'int $age = 0'],
                'status' => [['#[Varchar(20)]', "#[DefaultVal(\"'active'\")]", "#[CheckEnum(UserStatus::class, name: 'chk_users_status')]", "#[Index(where: \"status <> 'deleted'\")]"], "string \$status = 'active'"],
                'profile' => [['#[Json]'], '?array $profile = null'],
                'rating' => [['#[Double]'], '?float $rating = null'],
                'score' => [['#[FloatType]', '#[NullableIs(true)]'], 'float $score = 0.0'],
                'born' => [['#[Date]'], '?string $born = null'],
                'login_at' => [['#[DateTime]'], '?string $login_at = null'],
                'api_key' => [['#[Uuid]', '#[Unique]'], '?string $api_key = null'],
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
                'released' => [["#[Type('YEAR')]"], '?int $released = null'],
            ]],
            'orders' => ['class' => 'Order', 'repo' => 'OrderRepo', 'schema' => null, 'attrs' => ["#[Check('total >= 0', name: 'chk_orders_total')]"], 'props' => [
                'id' => [['#[BigId(always: true)]'], '?int $id = null'],
                'user_id' => [['#[BigInteger]', "#[ForeignKey('users', 'id', onUpdate: FKAction::CASCADE, onDelete: FKAction::CASCADE)]"], 'int $user_id'],
                'number' => [['#[Varchar(30)]', '#[Unique]'], 'string $number'],
                'total' => [['#[Decimal(12, 2)]', "#[DefaultVal('0')]"], "string \$total = '0'"],
                'note' => [['#[Text]'], '?string $note = null'],
                'paid' => [['#[Boolean]', "#[DefaultVal('false')]", "#[Index(where: 'paid = false', name: 'unpaid')]"], 'bool $paid = false'],
                'period' => [["#[Type('VARCHAR(40)')]"], '?string $period = null'],
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
     * MariaDB cannot build as declared.
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
            ['a primary key changed, not custom', $last->attrs('audit_events', 'happened', fn($a) => Scenario::swap($a, '#[Primary]', null)), '/primary key changes/'],
            ['a unique HASH index', $last->attrs('users', 'locale', fn($a) => [...$a, '#[Unique(method: IndexMethod::HASH)]']), '/a unique index is a B-tree/'],
            ['two indexes of one name', $last->attrs('users', 'locale', fn($a) => [...$a, '#[Index]', '#[Index]']), '/two indexes are named users_locale_idx/'],
            ['an index of a column there is not', $last->attrs('users', 'locale', fn($a) => [...$a, "#[Index(['nick'])]"]), '/nick is no column of users/'],
            // What MariaDB has not.
            ['a GIN index', $last->attrs('users', 'profile', fn($a) => [...$a, '#[Index(method: IndexMethod::GIN)]']), '/MySQL \/ MariaDB have no GIN index/'],
            ['a GiST index', $last->attrs('audit_events', 'level', fn($a) => [...$a, '#[Index(method: IndexMethod::GIST)]']), '/MySQL \/ MariaDB have no GIST index/'],
            ['a partial unique index', $last->attrs('users', 'api_key', fn($a) => Scenario::swap($a, '#[Unique]', "#[Unique(where: 'api_key IS NOT NULL')]")), '/no partial index — without WHERE api_key IS NOT NULL the unique index would hold over every row/'],
            ['ON UPDATE SET DEFAULT', $last->attrs('audit_events', 'user_ref', fn($a) => Scenario::swap($a, '#[ForeignRepo(UserRepo::class, onUpdate: FKAction::CASCADE, onDelete: FKAction::SET_NULL)]', '#[ForeignRepo(UserRepo::class, onUpdate: FKAction::SET_DEFAULT, onDelete: FKAction::SET_NULL)]')), '/ON UPDATE SET DEFAULT is not kept by MySQL \/ MariaDB/'],
        ];
    }

    // ── What the checks read ─────────────────────────────────────────────────

    /** An index: `unique|index col,col`; null when there is none. In `$table` when given. */
    private static function index(\PDO $db, string $name, ?string $table = null): ?string
    {
        $in = $table !== null ? " AND TABLE_NAME = '{$table}'" : '';
        $row = one($db, "SELECT CONCAT(IF(MIN(NON_UNIQUE), 'index', 'unique'), ' ', GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX))"
            . " FROM information_schema.STATISTICS WHERE TABLE_SCHEMA IN (DATABASE(), 'shop') AND INDEX_NAME = '{$name}'{$in}");
        return is_string($row) ? $row : null;
    }

    /** A foreign key: `col → table(col) ON UPDATE … ON DELETE …`, the table of `shop` with it. */
    private static function key(\PDO $db, string $name): ?string
    {
        $row = one($db, "SELECT CONCAT(GROUP_CONCAT(k.COLUMN_NAME ORDER BY k.ORDINAL_POSITION), ' → ',"
            . " IF(k.REFERENCED_TABLE_SCHEMA = 'shop', 'shop.', ''), k.REFERENCED_TABLE_NAME,"
            . " '(', GROUP_CONCAT(k.REFERENCED_COLUMN_NAME ORDER BY k.ORDINAL_POSITION), ')',"
            . " ' ON UPDATE ', r.UPDATE_RULE, ' ON DELETE ', r.DELETE_RULE)"
            . ' FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r'
            . ' ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME'
            . " WHERE k.CONSTRAINT_SCHEMA IN (DATABASE(), 'shop') AND k.CONSTRAINT_NAME = '{$name}'"
            . ' GROUP BY k.REFERENCED_TABLE_SCHEMA, k.REFERENCED_TABLE_NAME, r.UPDATE_RULE, r.DELETE_RULE');
        return is_string($row) ? $row : null;
    }

    /** A check's expression; null when there is none. */
    private static function check(\PDO $db, string $name): ?string
    {
        $row = one($db, "SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA IN (DATABASE(), 'shop') AND CONSTRAINT_NAME = '{$name}'");
        return is_string($row) ? $row : null;
    }

    /** A column: `type NULL? extra`; null when there is none. */
    private static function column(\PDO $db, string $table, string $column): ?string
    {
        $row = one($db, "SELECT TRIM(CONCAT_WS(' ', COLUMN_TYPE, IS_NULLABLE, EXTRA)) FROM information_schema.COLUMNS"
            . " WHERE TABLE_SCHEMA IN (DATABASE(), 'shop') AND TABLE_NAME = '{$table}' AND COLUMN_NAME = '{$column}'");
        return is_string($row) ? $row : null;
    }

    /** Whether the server refuses the statement — tried in a transaction, undone either way. */
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
