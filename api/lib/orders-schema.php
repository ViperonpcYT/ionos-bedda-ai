<?php
/**
 * Orders DB schema helpers — legacy IONOS tables may use created_at only (no order_date).
 */

function ordersTableHasColumn(PDO $pdo, string $table, string $column): bool
{
    if (!isset($GLOBALS['orders_col_cache'])) {
        $GLOBALS['orders_col_cache'] = [];
    }
    $key = $table . '.' . $column;
    if (array_key_exists($key, $GLOBALS['orders_col_cache'])) {
        return $GLOBALS['orders_col_cache'][$key];
    }
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    $GLOBALS['orders_col_cache'][$key] = ((int) $stmt->fetchColumn()) > 0;
    return $GLOBALS['orders_col_cache'][$key];
}

function ordersTableMarkColumn(string $table, string $column): void
{
    if (!isset($GLOBALS['orders_col_cache'])) {
        $GLOBALS['orders_col_cache'] = [];
    }
    $GLOBALS['orders_col_cache'][$table . '.' . $column] = true;
}

function ordersAddColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void
{
    if (ordersTableHasColumn($pdo, $table, $column)) {
        return;
    }
    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    ordersTableMarkColumn($table, $column);
}

/**
 * Align orders table with api/sql/02-orders-mail-in.sql for shipping admin + checkout.
 */
function ensureOrdersSchema(PDO $pdo): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    if (!(bool) $pdo->query("SHOW TABLES LIKE 'orders'")->fetchColumn()) {
        return;
    }

    $columns = [
        'order_number' => "VARCHAR(64) NOT NULL DEFAULT ''",
        'stripe_payment_intent_id' => 'VARCHAR(255) NULL',
        'stripe_payment_status' => 'VARCHAR(32) NULL',
        'stripe_subscription_id' => 'VARCHAR(255) NULL',
        'chitchats_shipment_id' => 'VARCHAR(64) NULL',
        'customer_name' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'customer_email' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'phone_number' => 'VARCHAR(64) NULL',
        'items' => 'LONGTEXT NULL',
        'subtotal' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
        'shipping_address' => 'TEXT NULL',
        'shipping_street' => 'VARCHAR(255) NULL',
        'shipping_address2' => 'VARCHAR(255) NULL',
        'shipping_city' => 'VARCHAR(128) NULL',
        'province' => 'VARCHAR(16) NULL',
        'postal_code' => 'VARCHAR(32) NULL',
        'order_date' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'ip_address' => 'VARCHAR(45) NULL',
        'spam_score' => 'INT NOT NULL DEFAULT 0',
        'fulfillment_method' => "VARCHAR(32) NOT NULL DEFAULT 'shipping'",
        'pickup_location' => 'VARCHAR(255) NULL',
        'pickup_date' => 'DATE NULL',
        'shipping_cost' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
        'shipping_carrier' => 'VARCHAR(64) NULL',
        'chitchats_postage_type' => 'VARCHAR(64) NULL',
        'grand_total' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
        'is_subscription' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'subscription_interval' => 'VARCHAR(32) NULL',
        'payment_status' => 'VARCHAR(32) NULL',
        'status' => "VARCHAR(32) NOT NULL DEFAULT 'queued'",
        'tracking_number' => 'VARCHAR(128) NULL',
        'label_pdf_url' => 'TEXT NULL',
        'label_created_at' => 'DATETIME NULL',
        'shipped_at' => 'DATETIME NULL',
        'inventory_synced' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'customer_id' => 'INT UNSIGNED NULL',
        'points_used' => 'INT NOT NULL DEFAULT 0',
        'points_earned' => 'INT NOT NULL DEFAULT 0',
        'points_discount_cad' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
        'coupon_code' => 'VARCHAR(64) NULL',
        'discount_amount' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
        'late_confirm_notified' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
    ];

    foreach ($columns as $column => $definition) {
        ordersAddColumnIfMissing($pdo, 'orders', $column, $definition);
    }

    if (ordersTableHasColumn($pdo, 'orders', 'order_date')) {
        if (ordersTableHasColumn($pdo, 'orders', 'created_at')) {
            $pdo->exec(
                "UPDATE orders SET order_date = created_at
                 WHERE order_date IS NULL OR order_date = '0000-00-00 00:00:00'"
            );
        } else {
            $pdo->exec(
                "UPDATE orders SET order_date = NOW()
                 WHERE order_date IS NULL OR order_date = '0000-00-00 00:00:00'"
            );
        }
    }

    if (ordersTableHasColumn($pdo, 'orders', 'status')) {
        $pdo->exec("UPDATE orders SET status = 'queued' WHERE status IS NULL OR status = ''");
        // Legacy IONOS tables used status=pending for new paid orders.
        $pdo->exec(
            "UPDATE orders SET status = 'queued'
             WHERE status = 'pending'
               AND (stripe_payment_status = 'succeeded' OR stripe_payment_intent_id IS NOT NULL)"
        );
    }
}

/** Statuses shown in the admin "Queued" fulfillment tab. */
function ordersQueuedStatuses(): array
{
    return ['queued', 'pending'];
}

function ordersIsQueuedStatus(?string $status): bool
{
    return in_array((string) $status, ordersQueuedStatuses(), true);
}

function ordersQueuedWhereSql(): string
{
    return "status IN ('queued', 'pending')";
}

/** Whitelisted ORDER BY for admin order lists. */
function ordersListOrderBySql(PDO $pdo): string
{
    ensureOrdersSchema($pdo);
    if (ordersTableHasColumn($pdo, 'orders', 'order_date')) {
        return 'order_date DESC';
    }
    if (ordersTableHasColumn($pdo, 'orders', 'created_at')) {
        return 'created_at DESC';
    }
    return 'id DESC';
}

function ordersFormatDisplayDate(array $row): string
{
    $raw = $row['order_date'] ?? $row['created_at'] ?? null;
    if (empty($raw)) {
        return '—';
    }
    $ts = strtotime((string) $raw);
    return $ts ? date('M j, Y g:i A', $ts) : '—';
}

/**
 * @param array<int, mixed> $params
 * @return array<int, array<string, mixed>>
 */
function fetchOrdersForAdmin(PDO $pdo, string $where, array $params, int $limit = 200): array
{
    ensureOrdersSchema($pdo);
    $orderBy = ordersListOrderBySql($pdo);
    $stmt = $pdo->prepare("SELECT * FROM orders {$where} ORDER BY {$orderBy} LIMIT " . (int) $limit);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Persist points metadata on a saved order (columns added via migration). */
function onlybikes_orders_update_points_fields(PDO $pdo, string $orderNumber, array $fields): void
{
    ensureOrdersSchema($pdo);
    $allowed = ['customer_id', 'points_used', 'points_earned', 'points_discount_cad'];
    $sets = [];
    $params = [];

    foreach ($allowed as $col) {
        if (!array_key_exists($col, $fields) || !ordersTableHasColumn($pdo, 'orders', $col)) {
            continue;
        }
        $sets[] = "`{$col}` = ?";
        $params[] = $fields[$col];
    }

    if ($sets === []) {
        return;
    }

    $params[] = $orderNumber;
    $pdo->prepare('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE order_number = ? LIMIT 1')->execute($params);
}
