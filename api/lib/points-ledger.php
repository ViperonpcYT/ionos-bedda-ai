<?php
declare(strict_types=1);

require_once __DIR__ . '/customers-schema.php';

/** Trust 0–100 (100 = best). Based on spam/rate-limit score at checkout. */
function onlybikes_trust_percent(int $spamScore): int
{
    $block = defined('SPAM_BLOCK_THRESHOLD') ? (int) SPAM_BLOCK_THRESHOLD : 10;
    if ($block < 1) {
        $block = 10;
    }
    $risk = min(100, (int) round(($spamScore / $block) * 100));

    return max(0, 100 - $risk);
}

function onlybikes_trust_badge_class(int $trustPercent): string
{
    if ($trustPercent >= 80) {
        return 'bg-green-100 text-green-800';
    }
    if ($trustPercent >= 50) {
        return 'bg-yellow-100 text-yellow-800';
    }

    return 'bg-red-100 text-red-800';
}

function onlybikes_points_session_customer_id(): ?int
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start([
            'cookie_httponly' => true,
            'cookie_secure' => function_exists('beddaSessionCookieSecure') ? beddaSessionCookieSecure() : true,
            'cookie_samesite' => 'Strict',
        ]);
    }
    if (empty($_SESSION['customer_id'])) {
        return null;
    }

    return (int) $_SESSION['customer_id'];
}

/** 1 point = $0.05 off merchandise subtotal at checkout. */
function onlybikes_points_value_per_point(): float
{
    return 0.05;
}

/**
 * @return array{points_used:int,discount_cad:float}
 */
function onlybikes_points_checkout_redeem_calc(int $availablePoints, float $grandTotalBeforePoints): array
{
    $availablePoints = max(0, $availablePoints);
    if ($availablePoints < 1 || $grandTotalBeforePoints <= 1.0) {
        return ['points_used' => 0, 'discount_cad' => 0.0];
    }

    $perPoint = onlybikes_points_value_per_point();
    $maxDiscount = $availablePoints * $perPoint;
    $allowable = max(0.0, $grandTotalBeforePoints - 1.0);
    $discount = round(min($maxDiscount, $allowable), 2);

    if ($discount <= 0) {
        return ['points_used' => 0, 'discount_cad' => 0.0];
    }

    return [
        'points_used' => (int) ceil($discount / $perPoint),
        'discount_cad' => $discount,
    ];
}

function onlybikes_ensure_points_ledger_schema(PDO $pdo): void
{
    static $done = [];
    $id = spl_object_hash($pdo);
    if (!empty($done[$id])) {
        return;
    }

    if (!onlybikes_customers_table_exists($pdo)) {
        onlybikes_ensure_customers_schema($pdo);
    }

    $exists = (bool) $pdo->query("SHOW TABLES LIKE 'points_ledger'")->fetchColumn();
    if (!$exists) {
        $pdo->exec("
            CREATE TABLE points_ledger (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              customer_id INT UNSIGNED NOT NULL,
              delta INT NOT NULL,
              balance_after INT NOT NULL,
              type VARCHAR(32) NOT NULL,
              reference_type VARCHAR(32) NULL,
              reference_id VARCHAR(64) NULL,
              order_number VARCHAR(64) NULL,
              amount_cad DECIMAL(10,2) NULL,
              points_used INT NULL,
              points_earned INT NULL,
              ip_address VARCHAR(45) NULL,
              actor VARCHAR(32) NOT NULL DEFAULT 'system',
              note VARCHAR(255) NULL,
              meta_json JSON NULL,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_pl_customer (customer_id),
              KEY idx_pl_type (type),
              KEY idx_pl_order (order_number),
              KEY idx_pl_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    $done[$id] = true;
}

function onlybikes_points_ledger_balance(PDO $pdo, int $customerId): int
{
    onlybikes_ensure_points_ledger_schema($pdo);
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(delta), 0) FROM points_ledger WHERE customer_id = ?');
    $stmt->execute([$customerId]);

    return (int) $stmt->fetchColumn();
}

/**
 * @param array<string, mixed> $meta
 */
function onlybikes_points_apply_delta(
    PDO $pdo,
    int $customerId,
    int $delta,
    string $type,
    array $meta = []
): array {
    onlybikes_ensure_points_ledger_schema($pdo);
    onlybikes_ensure_customers_schema($pdo);

    if ($customerId < 1) {
        throw new InvalidArgumentException('Invalid customer id');
    }

    $allowedTypes = [
        'order_earned',
        'order_redeemed',
        'coupon_redeemed',
        'signup_bonus',
        'integrity_correction',
        'admin_adjust',
    ];
    if (!in_array($type, $allowedTypes, true)) {
        throw new InvalidArgumentException('Invalid points ledger type');
    }

    if ($delta === 0) {
        return ['success' => true, 'balance' => onlybikes_customers_fetch_points($pdo, $customerId) ?? 0];
    }

    $pdo->beginTransaction();
    try {
        $col = onlybikes_customers_points_column($pdo);
        $lock = $pdo->prepare("SELECT id, {$col} AS points FROM customers WHERE id = ? FOR UPDATE");
        $lock->execute([$customerId]);
        $row = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Customer not found');
        }

        $current = onlybikes_customer_points_value($row);
        $newBalance = $current + $delta;
        if ($newBalance < 0) {
            throw new RuntimeException('Insufficient points balance');
        }

        onlybikes_customers_set_points($pdo, $customerId, $newBalance);

        $ins = $pdo->prepare(
            'INSERT INTO points_ledger
             (customer_id, delta, balance_after, type, reference_type, reference_id, order_number,
              amount_cad, points_used, points_earned, ip_address, actor, note, meta_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $metaJson = !empty($meta['meta']) && is_array($meta['meta'])
            ? json_encode($meta['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        $ins->execute([
            $customerId,
            $delta,
            $newBalance,
            $type,
            $meta['reference_type'] ?? null,
            isset($meta['reference_id']) ? (string) $meta['reference_id'] : null,
            $meta['order_number'] ?? null,
            isset($meta['amount_cad']) ? (float) $meta['amount_cad'] : null,
            isset($meta['points_used']) ? (int) $meta['points_used'] : null,
            isset($meta['points_earned']) ? (int) $meta['points_earned'] : null,
            $meta['ip_address'] ?? null,
            $meta['actor'] ?? 'system',
            $meta['note'] ?? null,
            $metaJson,
        ]);

        $pdo->commit();

        error_log(sprintf(
            '[OnlyBikes][points] customer=%d type=%s delta=%+d balance=%d ref=%s',
            $customerId,
            $type,
            $delta,
            $newBalance,
            (string) ($meta['order_number'] ?? $meta['reference_id'] ?? '')
        ));

        return ['success' => true, 'balance' => $newBalance, 'ledger_id' => (int) $pdo->lastInsertId()];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Verify logged-in customer matches checkout email before points move.
 */
function onlybikes_points_verify_customer_email(PDO $pdo, int $customerId, string $email): bool
{
    $stmt = $pdo->prepare('SELECT email FROM customers WHERE id = ? LIMIT 1');
    $stmt->execute([$customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }

    return strcasecmp(trim($email), trim((string) $row['email'])) === 0;
}

/**
 * @return array{points_used:int,discount_cad:float,balance:int}
 */
function onlybikes_points_checkout_redeem(
    PDO $pdo,
    int $customerId,
    int $pointsToUse,
    float $discountCad,
    string $orderNumber,
    ?string $ip = null
): array {
    if ($pointsToUse < 1 || $discountCad <= 0) {
        return ['points_used' => 0, 'discount_cad' => 0.0, 'balance' => onlybikes_customers_fetch_points($pdo, $customerId) ?? 0];
    }

    $result = onlybikes_points_apply_delta($pdo, $customerId, -$pointsToUse, 'order_redeemed', [
        'reference_type' => 'order',
        'reference_id' => $orderNumber,
        'order_number' => $orderNumber,
        'amount_cad' => $discountCad,
        'points_used' => $pointsToUse,
        'ip_address' => $ip,
        'actor' => 'checkout',
        'note' => 'Points applied at checkout',
    ]);

    return [
        'points_used' => $pointsToUse,
        'discount_cad' => $discountCad,
        'balance' => (int) ($result['balance'] ?? 0),
    ];
}

function onlybikes_points_checkout_earn(
    PDO $pdo,
    int $customerId,
    int $pointsEarned,
    string $orderNumber,
    float $subtotal,
    ?string $ip = null
): int {
    if ($pointsEarned < 1) {
        return onlybikes_customers_fetch_points($pdo, $customerId) ?? 0;
    }

    $result = onlybikes_points_apply_delta($pdo, $customerId, $pointsEarned, 'order_earned', [
        'reference_type' => 'order',
        'reference_id' => $orderNumber,
        'order_number' => $orderNumber,
        'amount_cad' => $subtotal,
        'points_earned' => $pointsEarned,
        'ip_address' => $ip,
        'actor' => 'checkout',
        'note' => 'Points earned from order subtotal',
    ]);

    return (int) ($result['balance'] ?? 0);
}

function onlybikes_points_coupon_redeem(
    PDO $pdo,
    int $customerId,
    int $pointsToDeduct,
    float $discountCad,
    string $couponCode
): int {
    $result = onlybikes_points_apply_delta($pdo, $customerId, -$pointsToDeduct, 'coupon_redeemed', [
        'reference_type' => 'coupon',
        'reference_id' => $couponCode,
        'amount_cad' => $discountCad,
        'points_used' => $pointsToDeduct,
        'actor' => 'customer',
        'note' => 'Points converted to reward coupon',
        'meta' => ['coupon_code' => $couponCode],
    ]);

    return (int) ($result['balance'] ?? 0);
}

/**
 * Expected earned points from paid orders (orders DB).
 */
function onlybikes_points_expected_earned_from_orders(PDO $ordersPdo, int $customerId, string $email): int
{
    if (!function_exists('ordersTableHasColumn')) {
        require_once __DIR__ . '/orders-schema.php';
    }
    ensureOrdersSchema($ordersPdo);

    $total = 0;
    if (ordersTableHasColumn($ordersPdo, 'orders', 'customer_id')) {
        $stmt = $ordersPdo->prepare(
            "SELECT COALESCE(SUM(FLOOR(subtotal)), 0) FROM orders
             WHERE customer_id = ? AND stripe_payment_status = 'succeeded'"
        );
        $stmt->execute([$customerId]);
        $total = (int) $stmt->fetchColumn();
    }

    if ($total < 1 && ordersTableHasColumn($ordersPdo, 'orders', 'customer_email')) {
        $stmt = $ordersPdo->prepare(
            "SELECT COALESCE(SUM(FLOOR(subtotal)), 0) FROM orders
             WHERE LOWER(customer_email) = LOWER(?) AND stripe_payment_status = 'succeeded'"
        );
        $stmt->execute([$email]);
        $total = (int) $stmt->fetchColumn();
    }

    return max(0, $total);
}

/**
 * @return array<int, array<string, mixed>>
 */
function onlybikes_points_audit_customer(PDO $customersPdo, PDO $ordersPdo, int $customerId): array
{
    onlybikes_ensure_points_ledger_schema($customersPdo);
    $col = onlybikes_customers_points_column($customersPdo);
    $stmt = $customersPdo->prepare("SELECT id, email, {$col} AS points FROM customers WHERE id = ?");
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer) {
        return ['ok' => false, 'error' => 'Customer not found'];
    }

    $stored = onlybikes_customer_points_value($customer);
    $ledgerSum = onlybikes_points_ledger_balance($customersPdo, $customerId);
    $expectedEarned = onlybikes_points_expected_earned_from_orders($ordersPdo, $customerId, (string) $customer['email']);

    $redeemedStmt = $customersPdo->prepare(
        "SELECT COALESCE(SUM(ABS(delta)), 0) FROM points_ledger
         WHERE customer_id = ? AND delta < 0"
    );
    $redeemedStmt->execute([$customerId]);
    $totalRedeemed = (int) $redeemedStmt->fetchColumn();

    $maxAllowed = max(0, $expectedEarned + 500); // signup / manual adjustments buffer
    $issues = [];

    if ($stored !== $ledgerSum) {
        $issues[] = "stored_balance_mismatch: customers.points={$stored} ledger_sum={$ledgerSum}";
    }
    if ($stored > $maxAllowed) {
        $issues[] = "balance_exceeds_expected_max: stored={$stored} max≈{$maxAllowed} (earned≈{$expectedEarned})";
    }
    if ($ledgerSum < 0) {
        $issues[] = 'negative_ledger_balance';
    }

    return [
        'ok' => $issues === [],
        'customer_id' => $customerId,
        'email' => $customer['email'],
        'stored_points' => $stored,
        'ledger_balance' => $ledgerSum,
        'expected_earned_orders' => $expectedEarned,
        'total_redeemed' => $totalRedeemed,
        'issues' => $issues,
    ];
}

/**
 * @return array{checked:int,fixed:int,flagged:array<int,array<string,mixed>>}
 */
function onlybikes_points_audit_all(PDO $customersPdo, PDO $ordersPdo, bool $autoFix = false): array
{
    onlybikes_ensure_points_ledger_schema($customersPdo);
    $col = onlybikes_customers_points_column($customersPdo);
    $rows = $customersPdo->query("SELECT id FROM customers ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
    $flagged = [];
    $fixed = 0;

    foreach ($rows as $customerId) {
        $customerId = (int) $customerId;
        $audit = onlybikes_points_audit_customer($customersPdo, $ordersPdo, $customerId);
        if (!empty($audit['ok'])) {
            continue;
        }
        $flagged[] = $audit;

        if ($autoFix && isset($audit['ledger_balance'], $audit['stored_points']) && $audit['stored_points'] !== $audit['ledger_balance']) {
            $target = (int) $audit['ledger_balance'];
            $delta = $target - (int) $audit['stored_points'];
            if ($delta !== 0) {
                onlybikes_points_apply_delta($customersPdo, $customerId, $delta, 'integrity_correction', [
                    'actor' => 'cron',
                    'note' => 'Cron sync: align stored balance with ledger sum',
                ]);
            } else {
                onlybikes_customers_set_points($customersPdo, $customerId, $target);
            }
            $fixed++;
        }
    }

    return ['checked' => count($rows), 'fixed' => $fixed, 'flagged' => $flagged];
}

/**
 * @param array<int, array<string, mixed>> $orders
 * @return array<int, array<string, mixed>>
 */
function onlybikes_orders_enrich_points_trust(array $orders, ?PDO $customersPdo = null): array
{
    $emailMap = [];
    if ($customersPdo !== null && onlybikes_customers_table_exists($customersPdo)) {
        onlybikes_ensure_customers_schema($customersPdo);
        $col = onlybikes_customers_points_column($customersPdo);
        foreach ($customersPdo->query("SELECT id, email, {$col} AS points FROM customers")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $emailMap[strtolower(trim((string) $c['email']))] = [
                'id' => (int) $c['id'],
                'points' => onlybikes_customer_points_value($c),
            ];
        }
    }

    foreach ($orders as &$o) {
        $spam = (int) ($o['spam_score'] ?? 0);
        $o['trust_percent'] = onlybikes_trust_percent($spam);
        $o['points_used'] = (int) ($o['points_used'] ?? 0);
        $o['points_earned'] = (int) ($o['points_earned'] ?? 0);
        $o['points_discount_cad'] = (float) ($o['points_discount_cad'] ?? 0);

        $emailKey = strtolower(trim((string) ($o['customer_email'] ?? '')));
        $o['customer_points_balance'] = $emailMap[$emailKey]['points'] ?? null;
        $o['customer_profile_id'] = $emailMap[$emailKey]['id'] ?? (int) ($o['customer_id'] ?? 0) ?: null;
    }
    unset($o);

    return $orders;
}

/**
 * @return array<int, array<string, mixed>>
 */
function onlybikes_points_leaderboard(PDO $pdo, int $limit = 25): array
{
    onlybikes_ensure_customers_schema($pdo);
    $col = onlybikes_customers_points_column($pdo);
    $limit = max(1, min(100, $limit));
    $stmt = $pdo->query(
        "SELECT id, email, first_name, last_name, {$col} AS points, created_at
         FROM customers
         WHERE {$col} > 0
         ORDER BY {$col} DESC, id ASC
         LIMIT {$limit}"
    );

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array<int, array<string, mixed>>
 */
function onlybikes_points_recent_ledger(PDO $pdo, int $limit = 100, ?int $customerId = null): array
{
    onlybikes_ensure_points_ledger_schema($pdo);
    $limit = max(1, min(500, $limit));

    if ($customerId !== null && $customerId > 0) {
        $stmt = $pdo->prepare(
            'SELECT pl.*, c.email, c.first_name, c.last_name
             FROM points_ledger pl
             JOIN customers c ON c.id = pl.customer_id
             WHERE pl.customer_id = ?
             ORDER BY pl.id DESC
             LIMIT ' . $limit
        );
        $stmt->execute([$customerId]);
    } else {
        $stmt = $pdo->query(
            'SELECT pl.*, c.email, c.first_name, c.last_name
             FROM points_ledger pl
             JOIN customers c ON c.id = pl.customer_id
             ORDER BY pl.id DESC
             LIMIT ' . $limit
        );
    }

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
