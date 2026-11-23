<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\E2e;

/**
 * The numbers of stage 2, whatever the database: the rows each driver's data writes, and
 * what the checks of stage 3 count on. Deterministic.
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
}
