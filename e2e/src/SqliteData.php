<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\E2e;

/**
 * Stage 2 on SQLite: the rows of {@see PgData}, by the numbers of {@see Data} — the same dirt
 * (users of one name, NULL notes, audit rows pointing at no user: written before the key
 * that would refuse them).
 */
final class SqliteData
{
    /** @return array<string, int> table => rows, what stays true through stage 3 */
    public static function counts(int $version): array
    {
        $renamed = $version >= 21;
        return [
            'users' => Data::USERS,
            $renamed ? 'goods' : 'products' => Data::PRODUCTS,
            'orders' => Data::ORDERS,
            'order_items' => Data::ITEMS,
            $renamed ? 'audit_events' : 'audit_log' => Data::AUDIT,
        ];
    }

    public static function fill(\PDO $db): void
    {
        $db->beginTransaction();
        foreach (['admins', 'staff', 'guests'] as $g) {
            $db->prepare('INSERT INTO groups (name, description) VALUES (?, ?)')->execute([$g, "the {$g}"]);
        }

        $user = $db->prepare(
            'INSERT INTO users (email, name, group_id, age, status, profile, rating, score, born, login_at, api_key)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        for ($i = 1; $i <= Data::USERS; $i++) {
            $user->execute([
                "u{$i}@e2e.test",
                match (true) {
                    $i <= 3 => 'Ann',
                    $i <= 5 => 'Bob',
                    default => "User {$i}",
                },
                $i % 4 === 0 ? null : $i % 3 + 1,
                $i % 60,
                $i % 10 === 0 ? 'deleted' : ($i % 3 === 0 ? 'new' : 'active'),
                json_encode(['i' => $i, 'tags' => ['a', 'b']]),
                $i * 1.5,
                $i / 2,
                date('Y-m-d', strtotime("1990-01-01 +{$i} days")),
                $i % 2 === 0 ? '2026-01-01 10:00:00' : null,
                $i % 2 === 1 ? sprintf('%08x-0000-4000-8000-%012x', $i, $i) : null,
            ]);
        }

        // The id is the column's default (a v4 UUID of randomblob()): read back by the sku.
        $product = $db->prepare(
            'INSERT INTO products (sku, title, price, stock, weight, photo, digest, released) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        for ($i = 1; $i <= Data::PRODUCTS; $i++) {
            $product->bindValue(1, sprintf('SKU-%06d', $i));
            $product->bindValue(2, "Product {$i}");
            $product->bindValue(3, $i * 10.5);
            $product->bindValue(4, $i * 3, \PDO::PARAM_INT);
            $product->bindValue(5, $i * 0.25);
            $product->bindValue(6, random_bytes(16), \PDO::PARAM_LOB);
            $product->bindValue(7, hash('sha256', (string) $i, true), \PDO::PARAM_LOB);
            $product->bindValue(8, 2000 + $i, \PDO::PARAM_INT);
            $product->execute();
        }
        $products = col($db, 'SELECT id FROM products ORDER BY sku');

        $order = $db->prepare('INSERT INTO orders (user_id, number, total, note, paid, period) VALUES (?, ?, ?, ?, ?, ?)');
        $item = $db->prepare('INSERT INTO order_items (order_id, product_id, qty, price) VALUES (?, ?, ?, ?)');
        for ($i = 1; $i <= Data::ORDERS; $i++) {
            $order->execute([
                ($i - 1) % Data::USERS + 1,
                sprintf('ORD-%05d', $i),
                $i * 3.25,
                $i % 3 === 0 ? null : "note {$i}",
                $i % 2 === 0 ? 1 : 0,
                '2026-01-01/2026-01-0' . ($i % 9 + 1),
            ]);
            $id = (int) $db->lastInsertId();
            foreach ([0, 1] as $k) {
                $item->execute([$id, $products[($i + $k) % Data::PRODUCTS], $k + 1, 2.5 * ($k + 1)]);
            }
        }

        $audit = $db->prepare('INSERT INTO audit_log (event, level, user_ref, payload, at, uid) VALUES (?, ?, ?, ?, ?, randomblob(16))');
        for ($i = 1; $i <= Data::AUDIT; $i++) {
            $audit->execute([
                "event.{$i}",
                $i % 5,
                match ($i % 3) {
                    0 => 99999,
                    1 => $i % Data::USERS + 1,
                    default => null,
                },
                json_encode(['n' => $i]),
                sprintf('%02d:00:00', $i % 24),
            ]);
        }
        $db->commit();
    }
}
