<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\E2e;

/**
 * Stage 2: rows in every table, on the schema of V005 — and the dirt stage 3 has to live
 * with: users of one name (a unique index comes), notes that are NULL (NOT NULL comes),
 * audit rows that point at no user (a foreign key comes).
 *
 * Deterministic: the checks of stage 3 count on these numbers.
 */
final class Data
{
    public const int GROUPS = 3;
    public const int USERS = 40;            // Ann ×3, Bob ×2 — the rest distinct
    public const int PRODUCTS = 12;
    public const int STOCK_SUM = 234;       // 3 × (1 + … + 12)
    public const int ORDERS = 60;
    public const int ORDERS_USER_SUM = 1030; // users 1–40 once, 1–20 twice
    public const float ORDERS_TOTAL = 5947.5; // 3.25 × (1 + … + 60)
    public const int ORDERS_WITHOUT_NOTE = 20; // every third
    public const int ORDERS_PAID = 30;
    public const int ITEMS = 120;
    public const int AUDIT = 30;
    public const int AUDIT_VALID_REFS = 10;   // a third points at a user, a third at none (99999), a third NULL

    /** @return array<string, int> table => rows, what stays true through stage 3 */
    public static function counts(): array
    {
        return [
            'public.users' => self::USERS,
            'shop.products' => self::PRODUCTS,
            'public.orders' => self::ORDERS,
            'public.order_items' => self::ITEMS,
            'public.audit_log' => self::AUDIT,
        ];
    }

    public static function fill(\PDO $db): void
    {
        $db->beginTransaction();
        foreach (['admins', 'staff', 'guests'] as $g) {
            $db->prepare('INSERT INTO public.groups (name, description) VALUES (?, ?)')->execute([$g, "the {$g}"]);
        }

        $user = $db->prepare(
            'INSERT INTO public.users (email, name, group_id, age, status, profile, rating, score, born, login_at, api_key)'
            . ' VALUES (?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?, ' . "CASE WHEN ? THEN gen_random_uuid() END)"
        );
        for ($i = 1; $i <= self::USERS; $i++) {
            $name = match (true) {
                $i <= 3 => 'Ann',
                $i <= 5 => 'Bob',
                default => "User {$i}",
            };
            $user->execute([
                "u{$i}@e2e.test",
                $name,
                $i % 4 === 0 ? null : $i % 3 + 1,
                $i % 60,
                $i % 10 === 0 ? 'deleted' : ($i % 3 === 0 ? 'new' : 'active'),
                json_encode(['i' => $i, 'tags' => ['a', 'b']]),
                $i * 1.5,
                $i / 2,
                date('Y-m-d', strtotime("1990-01-01 +{$i} days")),
                $i % 2 === 0 ? '2026-01-01 10:00:00' : null,
                $i % 2 === 1 ? 'true' : 'false',
            ]);
        }

        $product = $db->prepare(
            'INSERT INTO shop.products (sku, title, price, stock, weight, photo, digest, tags) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        for ($i = 1; $i <= self::PRODUCTS; $i++) {
            $product->bindValue(1, sprintf('SKU-%06d', $i));
            $product->bindValue(2, "Product {$i}");
            $product->bindValue(3, $i * 10.5);
            $product->bindValue(4, $i * 3, \PDO::PARAM_INT);
            $product->bindValue(5, $i * 0.25);
            $product->bindValue(6, random_bytes(16), \PDO::PARAM_LOB);
            $product->bindValue(7, hash('sha256', (string) $i, true), \PDO::PARAM_LOB);
            $product->bindValue(8, '{a,b,p' . $i . '}');
            $product->execute();
        }
        $products = col($db, 'SELECT id FROM shop.products ORDER BY sku');

        $order = $db->prepare(
            'INSERT INTO public.orders (user_id, number, total, note, paid, period) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $item = $db->prepare('INSERT INTO public.order_items (order_id, product_id, qty, price) VALUES (?, ?, ?, ?)');
        for ($i = 1; $i <= self::ORDERS; $i++) {
            $order->execute([
                ($i - 1) % self::USERS + 1,
                sprintf('ORD-%05d', $i),
                $i * 3.25,
                $i % 3 === 0 ? null : "note {$i}",
                $i % 2 === 0 ? 'true' : 'false',
                '[2026-01-01 00:00, 2026-01-0' . ($i % 9 + 1) . ' 00:00)',
            ]);
            $id = (int) $db->lastInsertId();
            foreach ([0, 1] as $k) {
                $item->execute([$id, $products[($i + $k) % self::PRODUCTS], $k + 1, 2.5 * ($k + 1)]);
            }
        }

        $audit = $db->prepare('INSERT INTO public.audit_log (event, level, user_ref, payload, at, uid) VALUES (?, ?, ?, ?::jsonb, ?, gen_random_uuid())');
        for ($i = 1; $i <= self::AUDIT; $i++) {
            $audit->execute([
                "event.{$i}",
                $i % 5,
                match ($i % 3) {
                    0 => 99999,
                    1 => $i % self::USERS + 1,
                    default => null,
                },
                json_encode(['n' => $i]),
                sprintf('%02d:00:00', $i % 24),
            ]);
        }
        $db->commit();
    }
}
