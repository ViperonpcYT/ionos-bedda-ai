<?php
/**
 * Shared checkout total calculation for create-payment-intent and submit-order.
 * Keeps Stripe charge amount and order save amount in sync.
 */
declare(strict_types=1);

function bedda_calculate_handling_cost(array $items, string $fulfillmentMethod = 'shipping'): float
{
    if ($fulfillmentMethod !== 'shipping') {
        return 0.0;
    }

    $soapCount = 0;
    $creamCount = 0;
    $totalItems = 0;

    foreach ($items as $item) {
        if (!empty($item['reward'])) {
            continue;
        }
        $qty = intval($item['quantity'] ?? 1);
        $totalItems += $qty;
        $lower = strtolower((string) ($item['product'] ?? ''));
        if (strpos($lower, 'soap') !== false && strpos($lower, 'loaf') === false) {
            $soapCount += $qty;
        }
        if (strpos($lower, 'balm') !== false || strpos($lower, 'cream') !== false) {
            $creamCount += $qty;
        }
    }

    $isMailer = ($soapCount <= 5 && $creamCount <= 5 && $totalItems <= 5);

    return $isMailer ? 3.50 : 3.90;
}

/**
 * @return array{
 *   subtotal: float,
 *   shipping: float,
 *   handling: float,
 *   item_discount: float,
 *   points_discount: float,
 *   points_used: int,
 *   grand_total: float
 * }
 */

function bedda_lookup_coupon_discount(array $data, float $subtotal): float
{
    if ($subtotal <= 0 || empty($data['coupon']) || !is_array($data['coupon'])) {
        return 0.0;
    }
    $code = strtoupper(trim((string) ($data['coupon']['code'] ?? '')));
    if ($code === '' || !function_exists('getCouponDatabase')) {
        return 0.0;
    }
    try {
        $pdo = getCouponDatabase();
        $stmt = $pdo->prepare('SELECT type, value, min_total, expires_at, usage_limit, used_count FROM coupon_codes WHERE code = ? AND active = TRUE AND deleted = FALSE LIMIT 1');
        $stmt->execute([$code]);
        $coupon = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$coupon) {
            return 0.0;
        }
        if (!empty($coupon['expires_at']) && new DateTime((string) $coupon['expires_at']) < new DateTime()) {
            return 0.0;
        }
        if ($subtotal < (float) $coupon['min_total']) {
            return 0.0;
        }
        if (!empty($coupon['usage_limit']) && (int) $coupon['used_count'] >= (int) $coupon['usage_limit']) {
            return 0.0;
        }
        if (($coupon['type'] ?? '') === 'percent') {
            return round($subtotal * ((float) $coupon['value'] / 100), 2);
        }
        return round(min((float) $coupon['value'], $subtotal), 2);
    } catch (Throwable $e) {
        error_log('[BEDDA] Coupon lookup failed: ' . $e->getMessage());
        return 0.0;
    }
}

function bedda_calculate_order_grand_total(array $data, float $subtotal, ?int $availablePoints = null): array
{
    $fulfillmentMethod = $data['fulfillment_method'] ?? 'shipping';
    $items = $data['items'] ?? [];

    $shipping = 0.0;
    if ($fulfillmentMethod === 'shipping' && !empty($data['shipping_option']) && is_array($data['shipping_option'])) {
        $shipping = floatval($data['shipping_option']['total'] ?? 0);
    }

    $handling = bedda_calculate_handling_cost($items, $fulfillmentMethod);
    // Ignore the discount number sent by the browser. Look the code up.
    $itemDiscount = bedda_lookup_coupon_discount($data, $subtotal);

    $discountedSubtotal = round($subtotal - $itemDiscount, 2);
    $grandTotal = $discountedSubtotal + $shipping + $handling;

    // $availablePoints stays unused on purpose. A reward is a whole item added
    // later. It must not change this charge.
    $pointsDiscount = 0.0;
    $pointsUsed = 0;

    return [
        'subtotal' => round($subtotal, 2),
        'shipping' => round($shipping, 2),
        'handling' => round($handling, 2),
        'item_discount' => round($itemDiscount, 2),
        'points_discount' => $pointsDiscount,
        'points_used' => $pointsUsed,
        'grand_total' => round($grandTotal, 2),
    ];
}

/**
 * Whole-item rewards. Point cost is the posted price divided by $0.05.
 *
 * @return array<string, array{id: string, name: string, points: int, items: list<array{product: string, price: float, quantity: int}>}>
 */
function bedda_reward_catalog(): array
{
    return [
        'minty' => [
            'id' => 'minty',
            'name' => 'Minty Lip Balm',
            'points' => 97,
            'items' => [['product' => 'Minty Lip Balm', 'price' => 0.0, 'quantity' => 1]],
        ],
        'uni' => [
            'id' => 'uni',
            'name' => 'Uni Exfoliating Soap',
            'points' => 112,
            'items' => [['product' => 'Uni Exfoliating Soap', 'price' => 0.0, 'quantity' => 1]],
        ],
        'shera' => [
            'id' => 'shera',
            'name' => 'She-Ra Exfoliating Soap',
            'points' => 115,
            'items' => [['product' => 'She-Ra Exfoliating Soap', 'price' => 0.0, 'quantity' => 1]],
        ],
        'massager' => [
            'id' => 'massager',
            'name' => 'The Massager',
            'points' => 125,
            'items' => [['product' => 'The Massager Soap', 'price' => 0.0, 'quantity' => 1]],
        ],
        'plainjane' => [
            'id' => 'plainjane',
            'name' => 'Plain Jane Balm',
            'points' => 200,
            'items' => [['product' => 'Plain Jane Face Balm', 'price' => 0.0, 'quantity' => 1]],
        ],
        'holygrail' => [
            'id' => 'holygrail',
            'name' => 'Holy Grail Balm',
            'points' => 200,
            'items' => [['product' => 'Holy Grail Balm', 'price' => 0.0, 'quantity' => 1]],
        ],
        'creamsicle' => [
            'id' => 'creamsicle',
            'name' => 'Creamsicle Balm',
            'points' => 200,
            'items' => [['product' => 'Creamsicle Balm', 'price' => 0.0, 'quantity' => 1]],
        ],
        'sensitive' => [
            'id' => 'sensitive',
            'name' => 'Sensitive Skin Set',
            'points' => 754,
            'items' => [
                ['product' => 'Plain Jane Soap', 'price' => 0.0, 'quantity' => 5],
                ['product' => 'Plain Jane Face Balm', 'price' => 0.0, 'quantity' => 2],
                ['product' => 'Soap Saver Exfoliating Bag', 'price' => 0.0, 'quantity' => 1],
            ],
        ],
    ];
}

function bedda_reward_by_id(string $id): ?array
{
    $catalog = bedda_reward_catalog();
    return $catalog[$id] ?? null;
}

/**
 * Free order lines for a catalog reward. Price stays 0 so the charge is unchanged.
 *
 * @param array{items: list<array{product: string, price: float, quantity: int}>} $reward
 * @return list<array{product: string, price: float, quantity: int, reward: true}>
 */
function bedda_reward_lines(array $reward): array
{
    $lines = [];
    foreach ($reward['items'] as $item) {
        $qty = (int) $item['quantity'];
        if ($qty < 1) {
            continue;
        }
        $lines[] = [
            'product' => (string) $item['product'],
            'price' => 0.0,
            'quantity' => $qty,
            'reward' => true,
        ];
    }
    return $lines;
}

/**
 * @param list<array<string, mixed>> $items
 * @return list<array<string, mixed>>
 */
function bedda_strip_reward_lines(array $items): array
{
    $paid = [];
    foreach ($items as $item) {
        if (empty($item['reward'])) {
            $paid[] = $item;
        }
    }
    return $paid;
}

function bedda_reward_lock_name(string $orderNumber): string
{
    $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $orderNumber);
    if (!is_string($safe) || $safe === '') {
        $safe = 'order';
    }
    return 'bedda_rw_' . substr($safe, 0, 54);
}

function bedda_reward_acquire_lock(PDO $db, string $orderNumber): bool
{
    if ((string) $db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        return true;
    }
    $stmt = $db->prepare('SELECT GET_LOCK(?, 8) AS locked');
    $stmt->execute([bedda_reward_lock_name($orderNumber)]);
    $row = $stmt->fetch();
    return is_array($row) && (int) ($row['locked'] ?? 0) === 1;
}

function bedda_reward_release_lock(PDO $db, string $orderNumber): void
{
    if ((string) $db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        return;
    }
    $stmt = $db->prepare('SELECT RELEASE_LOCK(?)');
    $stmt->execute([bedda_reward_lock_name($orderNumber)]);
}

function bedda_order_already_stored(PDO $db, string $orderNumber, string $paymentIntentId): bool
{
    $paymentIntentId = trim($paymentIntentId);
    if ($paymentIntentId !== '') {
        $stmt = $db->prepare(
            'SELECT order_number FROM orders WHERE order_number = ? OR stripe_payment_intent_id = ? LIMIT 1'
        );
        $stmt->execute([$orderNumber, $paymentIntentId]);
    } else {
        $stmt = $db->prepare('SELECT order_number FROM orders WHERE order_number = ? LIMIT 1');
        $stmt->execute([$orderNumber]);
    }
    return (bool) $stmt->fetch();
}

function bedda_read_customer_points(PDO $db, int $customerId): ?int
{
    if ($customerId < 1) {
        return null;
    }
    $stmt = $db->prepare('SELECT points FROM customers WHERE id = ?');
    $stmt->execute([$customerId]);
    $row = $stmt->fetch();
    if (!is_array($row) || !array_key_exists('points', $row)) {
        return null;
    }
    return (int) $row['points'];
}

function bedda_reward_undo_deduct(PDO $db, bool $ownsTransaction): void
{
    if ($ownsTransaction) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return;
    }
    if (!$db->inTransaction()) {
        return;
    }
    $db->exec('ROLLBACK TO SAVEPOINT bedda_reward_deduct');
    $db->exec('RELEASE SAVEPOINT bedda_reward_deduct');
}

/**
 * Subtract the reward cost once. A short balance leaves the row unchanged.
 * When the caller already has a transaction, a failed attempt rolls back only this savepoint.
 */
function bedda_deduct_reward_points(PDO $db, int $customerId, int $cost): bool
{
    if ($customerId < 1 || $cost < 1) {
        return false;
    }

    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->beginTransaction();
    } else {
        $db->exec('SAVEPOINT bedda_reward_deduct');
    }

    try {
        $driver = (string) $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'mysql'
            ? 'SELECT points FROM customers WHERE id = ? FOR UPDATE'
            : 'SELECT points FROM customers WHERE id = ?';
        $sel = $db->prepare($sql);
        $sel->execute([$customerId]);
        $row = $sel->fetch();
        if (!is_array($row) || (int) $row['points'] < $cost) {
            bedda_reward_undo_deduct($db, $ownsTransaction);
            return false;
        }

        $before = (int) $row['points'];
        $upd = $db->prepare('UPDATE customers SET points = points - ? WHERE id = ? AND points >= ?');
        $upd->execute([$cost, $customerId, $cost]);
        $after = bedda_read_customer_points($db, $customerId);
        if ($after !== $before - $cost) {
            bedda_reward_undo_deduct($db, $ownsTransaction);
            return false;
        }

        if ($ownsTransaction) {
            $db->commit();
        } else {
            $db->exec('RELEASE SAVEPOINT bedda_reward_deduct');
        }
        return true;
    } catch (Throwable $e) {
        try {
            bedda_reward_undo_deduct($db, $ownsTransaction);
        } catch (Throwable $undoError) {
            error_log('[BEDDA] Reward deduct undo failed: ' . $undoError->getMessage());
        }
        throw $e;
    }
}

function bedda_restore_reward_points(PDO $db, int $customerId, int $cost): void
{
    if ($customerId < 1 || $cost < 1) {
        return;
    }
    $stmt = $db->prepare('UPDATE customers SET points = points + ? WHERE id = ?');
    $stmt->execute([$cost, $customerId]);
}

/**
 * Add points earned on the paid subtotal.
 * Reads the balance after an earlier deduction, then adds with SQL so a stale
 * pre-redemption total cannot be written back over the spent points.
 */
function bedda_award_points_after_redeem(PDO $db, int $customerId, int $earned): ?int
{
    $earned = max(0, $earned);
    $current = bedda_read_customer_points($db, $customerId);
    if ($current === null) {
        return null;
    }
    if ($earned > 0) {
        $upd = $db->prepare('UPDATE customers SET points = points + ? WHERE id = ?');
        $upd->execute([$earned, $customerId]);
        $current = bedda_read_customer_points($db, $customerId);
    }
    return $current;
}

/**
 * Same cookie settings as customer sign-in, so a reward can only spend that session's points.
 */
function bedda_reward_signed_in_customer_id(): int
{
    if (session_status() === PHP_SESSION_NONE) {
        $secure = false;
        if (!function_exists('beddaIsLocalRequest') || !beddaIsLocalRequest()) {
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        }
        session_set_cookie_params([
            'lifetime' => 86400 * 30,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
    return (int) ($_SESSION['customer_id'] ?? 0);
}
