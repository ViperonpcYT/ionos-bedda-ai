<?php
/**
 * Coupon Codes DB schema helpers — legacy IONOS tables may use
 * discount_type / discount_value / usage_count instead of type / value / used_count,
 * and may lack min_total and deleted.
 */

function couponTableHasColumn(PDO $pdo, string $table, string $column): bool
{
    if (!isset($GLOBALS['coupon_col_cache'])) {
        $GLOBALS['coupon_col_cache'] = [];
    }
    $key = $table . '.' . $column;
    if (array_key_exists($key, $GLOBALS['coupon_col_cache'])) {
        return $GLOBALS['coupon_col_cache'][$key];
    }
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    $GLOBALS['coupon_col_cache'][$key] = ((int) $stmt->fetchColumn()) > 0;
    return $GLOBALS['coupon_col_cache'][$key];
}

function couponTableMarkColumn(string $table, string $column): void
{
    if (!isset($GLOBALS['coupon_col_cache'])) {
        $GLOBALS['coupon_col_cache'] = [];
    }
    $GLOBALS['coupon_col_cache'][$table . '.' . $column] = true;
}

function couponAddColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void
{
    if (couponTableHasColumn($pdo, $table, $column)) {
        return;
    }
    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    couponTableMarkColumn($table, $column);
}

/**
 * Align coupon_codes with api/sql/04-coupons.sql for admin + checkout.
 */
function couponUsesModernSchema(PDO $pdo): bool
{
    return couponTableHasColumn($pdo, 'coupon_codes', 'type')
        && couponTableHasColumn($pdo, 'coupon_codes', 'value');
}

function ensureCouponSchema(PDO $pdo): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    if (!(bool) $pdo->query("SHOW TABLES LIKE 'coupon_codes'")->fetchColumn()) {
        return;
    }

    try {
        couponEnsureSchemaMigrations($pdo);
    } catch (Throwable $e) {
        error_log('[coupon-schema] migration skipped: ' . $e->getMessage());
    }
}

function couponEnsureSchemaMigrations(PDO $pdo): void
{
    $columns = [
        'type' => "ENUM('percent','fixed') NOT NULL DEFAULT 'percent'",
        'value' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
        'min_total' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
        'expires_at' => 'DATETIME NULL',
        'usage_limit' => 'INT UNSIGNED NULL',
        'used_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'active' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'deleted' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ];

    foreach ($columns as $column => $definition) {
        couponAddColumnIfMissing($pdo, 'coupon_codes', $column, $definition);
    }

    if (couponTableHasColumn($pdo, 'coupon_codes', 'discount_type')
        && couponTableHasColumn($pdo, 'coupon_codes', 'type')) {
        $pdo->exec(
            "UPDATE coupon_codes SET type = CASE
                WHEN LOWER(TRIM(discount_type)) IN ('percent','percentage','%') THEN 'percent'
                ELSE 'fixed'
             END
             WHERE discount_type IS NOT NULL AND discount_type != ''
               AND (value IS NULL OR value = 0)"
        );
    }

    if (couponTableHasColumn($pdo, 'coupon_codes', 'discount_value')
        && couponTableHasColumn($pdo, 'coupon_codes', 'value')) {
        $pdo->exec(
            'UPDATE coupon_codes SET value = discount_value
             WHERE discount_value IS NOT NULL AND (value IS NULL OR value = 0)'
        );
    }

    if (couponTableHasColumn($pdo, 'coupon_codes', 'usage_count')
        && couponTableHasColumn($pdo, 'coupon_codes', 'used_count')) {
        $pdo->exec(
            'UPDATE coupon_codes SET used_count = usage_count
             WHERE usage_count IS NOT NULL AND used_count = 0'
        );
    }

    if (couponTableHasColumn($pdo, 'coupon_codes', 'deleted')) {
        $pdo->exec('UPDATE coupon_codes SET deleted = 0 WHERE deleted IS NULL');
    }

    if (couponTableHasColumn($pdo, 'coupon_codes', 'min_total')) {
        $pdo->exec('UPDATE coupon_codes SET min_total = 0.00 WHERE min_total IS NULL');
    }
}

/**
 * @return array<int, array<string, mixed>>
 */
function fetchCouponCodesList(PDO $pdo): array
{
    ensureCouponSchema($pdo);

    if (couponUsesModernSchema($pdo)) {
        $deletedCol = couponTableHasColumn($pdo, 'coupon_codes', 'deleted') ? 'deleted' : '0 AS deleted';
        $usedCol = couponTableHasColumn($pdo, 'coupon_codes', 'used_count') ? 'used_count' : '0 AS used_count';
        $minCol = couponTableHasColumn($pdo, 'coupon_codes', 'min_total') ? 'min_total' : '0.00 AS min_total';

        $stmt = $pdo->query(
            "SELECT id, code, type, value, {$minCol}, expires_at, usage_limit, {$usedCol}, active, {$deletedCol}, created_at
             FROM coupon_codes
             ORDER BY created_at DESC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (!couponTableHasColumn($pdo, 'coupon_codes', 'discount_type')
        || !couponTableHasColumn($pdo, 'coupon_codes', 'discount_value')) {
        return [];
    }

    $usageCount = couponTableHasColumn($pdo, 'coupon_codes', 'usage_count') ? 'usage_count' : '0';
    $stmt = $pdo->query(
        "SELECT id, code,
            CASE
                WHEN LOWER(TRIM(discount_type)) IN ('percent','percentage','%') THEN 'percent'
                ELSE 'fixed'
            END AS type,
            discount_value AS value,
            0.00 AS min_total,
            expires_at,
            usage_limit,
            {$usageCount} AS used_count,
            active,
            0 AS deleted,
            created_at
         FROM coupon_codes
         ORDER BY created_at DESC"
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @return array<string, mixed>|false
 */
function fetchActiveCouponByCode(PDO $pdo, string $code)
{
    ensureCouponSchema($pdo);

    if (couponUsesModernSchema($pdo)) {
        $deletedWhere = couponTableHasColumn($pdo, 'coupon_codes', 'deleted')
            ? ' AND deleted = 0'
            : '';
        $stmt = $pdo->prepare(
            "SELECT id, code, type, value,
                    " . (couponTableHasColumn($pdo, 'coupon_codes', 'min_total') ? 'min_total' : '0.00 AS min_total') . ",
                    expires_at, usage_limit,
                    " . (couponTableHasColumn($pdo, 'coupon_codes', 'used_count') ? 'used_count' : '0 AS used_count') . ",
                    active
             FROM coupon_codes
             WHERE code = ? AND active = 1{$deletedWhere}
             LIMIT 1"
        );
        $stmt->execute([$code]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!couponTableHasColumn($pdo, 'coupon_codes', 'discount_type')) {
        return false;
    }

    $usageCount = couponTableHasColumn($pdo, 'coupon_codes', 'usage_count') ? 'usage_count' : '0';
    $stmt = $pdo->prepare(
        "SELECT id, code,
            CASE
                WHEN LOWER(TRIM(discount_type)) IN ('percent','percentage','%') THEN 'percent'
                ELSE 'fixed'
            END AS type,
            discount_value AS value,
            0.00 AS min_total,
            expires_at,
            usage_limit,
            {$usageCount} AS used_count,
            active
         FROM coupon_codes
         WHERE code = ? AND active = 1
         LIMIT 1"
    );
    $stmt->execute([$code]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function couponLegacyDiscountType(string $type): string
{
    return $type === 'percent' ? 'percent' : 'fixed';
}

function couponIncrementUsage(PDO $pdo, string $code): void
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return;
    }
    ensureCouponSchema($pdo);
    $usedCol = couponTableHasColumn($pdo, 'coupon_codes', 'used_count')
        ? 'used_count'
        : (couponTableHasColumn($pdo, 'coupon_codes', 'usage_count') ? 'usage_count' : null);
    if ($usedCol === null) {
        return;
    }
    $deletedWhere = couponTableHasColumn($pdo, 'coupon_codes', 'deleted') ? ' AND deleted = 0' : '';
    $stmt = $pdo->prepare(
        "UPDATE coupon_codes SET {$usedCol} = {$usedCol} + 1 WHERE code = ? AND active = 1{$deletedWhere}"
    );
    $stmt->execute([$code]);
}

/**
 * @param array{code:string,type:string,value:float,min_total:float,expires_at:?string,usage_limit:?int,active:bool} $data
 */
function couponInsertRow(PDO $pdo, array $data): void
{
    ensureCouponSchema($pdo);

    if (couponUsesModernSchema($pdo)) {
        $stmt = $pdo->prepare(
            'INSERT INTO coupon_codes (code, type, value, min_total, expires_at, usage_limit, active)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['code'],
            $data['type'],
            $data['value'],
            $data['min_total'],
            $data['expires_at'],
            $data['usage_limit'],
            $data['active'] ? 1 : 0,
        ]);
        return;
    }

    if (!couponTableHasColumn($pdo, 'coupon_codes', 'discount_type')) {
        throw new RuntimeException('coupon_codes table is missing discount_type and type columns');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO coupon_codes (code, discount_type, discount_value, usage_limit, expires_at, active)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $data['code'],
        couponLegacyDiscountType($data['type']),
        $data['value'],
        $data['usage_limit'],
        $data['expires_at'],
        $data['active'] ? 1 : 0,
    ]);
}

/**
 * @param array{code:string,type:string,value:float,min_total:float,expires_at:?string,usage_limit:?int,active:bool} $data
 */
function couponUpdateRow(PDO $pdo, int $id, array $data): void
{
    ensureCouponSchema($pdo);
    $deletedClause = couponTableHasColumn($pdo, 'coupon_codes', 'deleted') ? ' AND deleted = 0' : '';

    if (couponUsesModernSchema($pdo)) {
        $stmt = $pdo->prepare(
            "UPDATE coupon_codes
             SET code = ?, type = ?, value = ?, min_total = ?, expires_at = ?, usage_limit = ?, active = ?
             WHERE id = ?{$deletedClause}"
        );
        $stmt->execute([
            $data['code'],
            $data['type'],
            $data['value'],
            $data['min_total'],
            $data['expires_at'],
            $data['usage_limit'],
            $data['active'] ? 1 : 0,
            $id,
        ]);
        return;
    }

    $stmt = $pdo->prepare(
        "UPDATE coupon_codes
         SET code = ?, discount_type = ?, discount_value = ?, usage_limit = ?, expires_at = ?, active = ?
         WHERE id = ?{$deletedClause}"
    );
    $stmt->execute([
        $data['code'],
        couponLegacyDiscountType($data['type']),
        $data['value'],
        $data['usage_limit'],
        $data['expires_at'],
        $data['active'] ? 1 : 0,
        $id,
    ]);
}

/**
 * Attach total_spent (grand_total) and total_saved (discount_amount) from orders DB.
 *
 * @param array<int, array<string, mixed>> $coupons
 * @return array<int, array<string, mixed>>
 */
function couponAttachOrderEconomics(array $coupons, ?PDO $ordersPdo = null): array
{
    if ($coupons === []) {
        return $coupons;
    }

    foreach ($coupons as &$c) {
        $c['total_spent'] = 0.0;
        $c['total_saved'] = 0.0;
        $c['usage_orders'] = 0;
    }
    unset($c);

    if ($ordersPdo === null) {
        if (!function_exists('getOrderDatabase')) {
            return $coupons;
        }
        try {
            $ordersPdo = getOrderDatabase();
        } catch (Throwable $e) {
            error_log('[OnlyBikes] coupon economics: ' . $e->getMessage());
            return $coupons;
        }
    }

    if (!function_exists('ensureOrdersSchema')) {
        require_once __DIR__ . '/orders-schema.php';
    }
    ensureOrdersSchema($ordersPdo);

    if (!ordersTableHasColumn($ordersPdo, 'orders', 'coupon_code')) {
        return $coupons;
    }

    $savedExpr = ordersTableHasColumn($ordersPdo, 'orders', 'discount_amount')
        ? 'COALESCE(SUM(discount_amount), 0)'
        : '0';

    $statusFilter = '';
    if (ordersTableHasColumn($ordersPdo, 'orders', 'stripe_payment_status')) {
        $statusFilter = " AND (stripe_payment_status = 'succeeded' OR stripe_payment_status IS NULL OR stripe_payment_status = '')";
        if (ordersTableHasColumn($ordersPdo, 'orders', 'stripe_payment_intent_id')) {
            $statusFilter = " AND (stripe_payment_status = 'succeeded'"
                . " OR (stripe_payment_intent_id IS NOT NULL AND TRIM(stripe_payment_intent_id) != ''))";
        }
    }

    $stmt = $ordersPdo->query(
        "SELECT UPPER(TRIM(coupon_code)) AS code,
                COUNT(*) AS usage_orders,
                COALESCE(SUM(grand_total), 0) AS total_spent,
                {$savedExpr} AS total_saved
         FROM orders
         WHERE coupon_code IS NOT NULL AND TRIM(coupon_code) != ''
           AND status != 'cancelled'{$statusFilter}
         GROUP BY UPPER(TRIM(coupon_code))"
    );
    $byCode = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $byCode[(string) $row['code']] = [
            'usage_orders' => (int) $row['usage_orders'],
            'total_spent' => (float) $row['total_spent'],
            'total_saved' => (float) $row['total_saved'],
        ];
    }

    foreach ($coupons as &$c) {
        $key = strtoupper(trim((string) ($c['code'] ?? '')));
        if ($key !== '' && isset($byCode[$key])) {
            $c['usage_orders'] = $byCode[$key]['usage_orders'];
            $c['total_spent'] = $byCode[$key]['total_spent'];
            $c['total_saved'] = $byCode[$key]['total_saved'];
        }
        $stored = (int) ($c['used_count'] ?? 0);
        $fromOrders = (int) ($c['usage_orders'] ?? 0);
        $c['used_count_display'] = max($stored, $fromOrders);
    }
    unset($c);

    return $coupons;
}

/**
 * Backfill coupon_code / discount_amount on orders from Stripe PaymentIntent metadata.
 * Helps coupons used before submit-order wrote those columns (used_count may be > 0 while economics show $0).
 *
 * @return array{updated:int, scanned:int, errors:int}
 */
function couponBackfillOrderEconomicsFromStripe(PDO $ordersPdo, int $limit = 150): array
{
    if (!function_exists('ensureOrdersSchema')) {
        require_once __DIR__ . '/orders-schema.php';
    }
    ensureOrdersSchema($ordersPdo);

    if (!ordersTableHasColumn($ordersPdo, 'orders', 'coupon_code')
        || !ordersTableHasColumn($ordersPdo, 'orders', 'stripe_payment_intent_id')) {
        return ['updated' => 0, 'scanned' => 0, 'errors' => 0];
    }

    if (!function_exists('loadStripe') || !loadStripe() || !defined('STRIPE_SECRET_KEY') || STRIPE_SECRET_KEY === '') {
        return ['updated' => 0, 'scanned' => 0, 'errors' => 1];
    }

    $stripe = new \Stripe\StripeClient(STRIPE_SECRET_KEY);
    $stmt = $ordersPdo->prepare(
        "SELECT id, order_number, stripe_payment_intent_id, subtotal, grand_total
         FROM orders
         WHERE (coupon_code IS NULL OR TRIM(coupon_code) = '')
           AND stripe_payment_intent_id IS NOT NULL AND TRIM(stripe_payment_intent_id) != ''
           AND status != 'cancelled'
         ORDER BY id DESC
         LIMIT " . (int) max(1, min(500, $limit))
    );
    $stmt->execute();

    $report = ['updated' => 0, 'scanned' => 0, 'errors' => 0];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $report['scanned']++;
        $piId = trim((string) $row['stripe_payment_intent_id']);
        if ($piId === '') {
            continue;
        }
        try {
            $pi = $stripe->paymentIntents->retrieve($piId);
            $meta = (array) ($pi->metadata->toArray() ?? []);
            $code = strtoupper(trim((string) ($meta['coupon_code'] ?? '')));
            if ($code === '') {
                continue;
            }

            $discount = 0.0;
            if (isset($meta['coupon_discount']) && is_numeric($meta['coupon_discount'])) {
                $discount = (float) $meta['coupon_discount'];
            } elseif (isset($meta['points_discount']) && is_numeric($meta['points_discount'])) {
                // skip — points not coupon
            } else {
                $sub = isset($meta['subtotal']) ? (float) $meta['subtotal'] : (float) ($row['subtotal'] ?? 0);
                if ($sub > 0 && function_exists('getCouponDatabase')) {
                    try {
                        $couponPdo = getCouponDatabase();
                        ensureCouponSchema($couponPdo);
                        $c = fetchActiveCouponByCode($couponPdo, $code);
                        if (!$c) {
                            $list = fetchCouponCodesList($couponPdo);
                            foreach ($list as $cand) {
                                if (strtoupper((string) $cand['code']) === $code) {
                                    $c = $cand;
                                    break;
                                }
                            }
                        }
                        if ($c) {
                            if (($c['type'] ?? '') === 'percent') {
                                $discount = round($sub * ((float) $c['value'] / 100), 2);
                            } else {
                                $discount = min((float) $c['value'], $sub);
                            }
                        }
                    } catch (Throwable $e) {
                        // estimate optional
                    }
                }
            }

            $upd = $ordersPdo->prepare(
                'UPDATE orders SET coupon_code = ?, discount_amount = ? WHERE id = ?'
            );
            $upd->execute([$code, $discount, (int) $row['id']]);
            $report['updated']++;
            couponSyncUsedCountFromOrders($ordersPdo, $code);
        } catch (Throwable $e) {
            $report['errors']++;
            error_log('[OnlyBikes] coupon backfill PI ' . $piId . ': ' . $e->getMessage());
        }
    }

    return $report;
}

/**
 * Align coupon_codes.used_count with paid orders that recorded coupon_code.
 */
function couponCountOrdersWithCode(PDO $ordersPdo, string $code): int
{
    if (!function_exists('ensureOrdersSchema')) {
        require_once __DIR__ . '/orders-schema.php';
    }
    ensureOrdersSchema($ordersPdo);
    if (!ordersTableHasColumn($ordersPdo, 'orders', 'coupon_code')) {
        return 0;
    }
    $code = strtoupper(trim($code));
    if ($code === '') {
        return 0;
    }
    $statusFilter = '';
    if (ordersTableHasColumn($ordersPdo, 'orders', 'stripe_payment_status')
        && ordersTableHasColumn($ordersPdo, 'orders', 'stripe_payment_intent_id')) {
        $statusFilter = " AND (stripe_payment_status = 'succeeded'"
            . " OR (stripe_payment_intent_id IS NOT NULL AND TRIM(stripe_payment_intent_id) != ''))";
    }
    $stmt = $ordersPdo->prepare(
        "SELECT COUNT(*) FROM orders
         WHERE UPPER(TRIM(coupon_code)) = ? AND status != 'cancelled'{$statusFilter}"
    );
    $stmt->execute([$code]);
    return (int) $stmt->fetchColumn();
}

/** Used for limits: max(stored used_count, actual orders with this code). */
function couponEffectiveUsedCount(string $code): int
{
    $code = strtoupper(trim($code));
    $stored = 0;
    try {
        $couponPdo = getCouponDatabase();
        ensureCouponSchema($couponPdo);
        $stmt = $couponPdo->prepare('SELECT used_count FROM coupon_codes WHERE UPPER(TRIM(code)) = ? LIMIT 1');
        if (!couponTableHasColumn($couponPdo, 'coupon_codes', 'used_count')) {
            $stmt = $couponPdo->prepare('SELECT usage_count AS used_count FROM coupon_codes WHERE UPPER(TRIM(code)) = ? LIMIT 1');
        }
        $stmt->execute([$code]);
        $stored = (int) ($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $ordersPdo = getOrderDatabase();
        return max($stored, couponCountOrdersWithCode($ordersPdo, $code));
    } catch (Throwable $e) {
        return $stored;
    }
}

function couponSyncUsedCountFromOrders(PDO $ordersPdo, ?string $onlyCode = null): void
{
    if (!function_exists('ensureOrdersSchema')) {
        require_once __DIR__ . '/orders-schema.php';
    }
    ensureOrdersSchema($ordersPdo);

    if (!ordersTableHasColumn($ordersPdo, 'orders', 'coupon_code')) {
        return;
    }

    if (!function_exists('getCouponDatabase')) {
        return;
    }

    $couponPdo = getCouponDatabase();
    ensureCouponSchema($couponPdo);
    $usedCol = couponTableHasColumn($couponPdo, 'coupon_codes', 'used_count')
        ? 'used_count'
        : (couponTableHasColumn($couponPdo, 'coupon_codes', 'usage_count') ? 'usage_count' : null);
    if ($usedCol === null) {
        return;
    }

    $statusFilter = '';
    if (ordersTableHasColumn($ordersPdo, 'orders', 'stripe_payment_status')
        && ordersTableHasColumn($ordersPdo, 'orders', 'stripe_payment_intent_id')) {
        $statusFilter = " AND (stripe_payment_status = 'succeeded'"
            . " OR (stripe_payment_intent_id IS NOT NULL AND TRIM(stripe_payment_intent_id) != ''))";
    }

    $sql = "SELECT UPPER(TRIM(coupon_code)) AS code, COUNT(*) AS cnt
            FROM orders
            WHERE coupon_code IS NOT NULL AND TRIM(coupon_code) != ''
              AND status != 'cancelled'{$statusFilter}
            GROUP BY UPPER(TRIM(coupon_code))";
    $params = [];
    if ($onlyCode !== null && $onlyCode !== '') {
        $sql = "SELECT UPPER(TRIM(coupon_code)) AS code, COUNT(*) AS cnt
                FROM orders
                WHERE UPPER(TRIM(coupon_code)) = ?
                  AND status != 'cancelled'{$statusFilter}
                GROUP BY UPPER(TRIM(coupon_code))";
        $params = [strtoupper(trim($onlyCode))];
    }

    $stmt = $ordersPdo->prepare($sql);
    $stmt->execute($params);
    $upd = $couponPdo->prepare(
        "UPDATE coupon_codes SET {$usedCol} = ? WHERE UPPER(TRIM(code)) = ?"
    );
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $upd->execute([(int) $row['cnt'], (string) $row['code']]);
    }
}
