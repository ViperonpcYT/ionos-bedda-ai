<?php
/**
 * Customers table — Bedda auth columns + IONOS panel (reward_points, phone, etc.)
 */
declare(strict_types=1);

if (!function_exists('onlybikes_customers_columns')) {
    /** @return array<string, true> lowercase column names */
    function onlybikes_customers_columns(PDO $pdo, bool $refresh = false): array
    {
        static $cache = [];
        $id = spl_object_hash($pdo);
        if (!$refresh && isset($cache[$id])) {
            return $cache[$id];
        }
        $cols = [];
        try {
            $stmt = $pdo->query('SHOW COLUMNS FROM customers');
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $cols[strtolower((string) $row['Field'])] = true;
            }
        } catch (PDOException $e) {
            error_log('[OnlyBikes] SHOW COLUMNS customers: ' . $e->getMessage());
        }
        $cache[$id] = $cols;
        return $cols;
    }
}

if (!function_exists('onlybikes_customers_has_column')) {
    function onlybikes_customers_has_column(PDO $pdo, string $column): bool
    {
        $cols = onlybikes_customers_columns($pdo);
        return isset($cols[strtolower($column)]);
    }
}

if (!function_exists('onlybikes_customers_table_exists')) {
    function onlybikes_customers_table_exists(PDO $pdo): bool
    {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'customers'");
            return (bool) $stmt->fetch();
        } catch (PDOException $e) {
            return false;
        }
    }
}

if (!function_exists('onlybikes_customers_points_column')) {
    function onlybikes_customers_points_column(PDO $pdo): string
    {
        if (onlybikes_customers_has_column($pdo, 'points')) {
            return 'points';
        }
        if (onlybikes_customers_has_column($pdo, 'reward_points')) {
            return 'reward_points';
        }
        return 'points';
    }
}

if (!function_exists('onlybikes_customers_add_column')) {
    function onlybikes_customers_add_column(PDO $pdo, string $sql): void
    {
        try {
            $pdo->exec($sql);
            onlybikes_customers_columns($pdo, true);
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'Duplicate column') !== false || stripos($msg, 'already exists') !== false) {
                onlybikes_customers_columns($pdo, true);
                return;
            }
            throw $e;
        }
    }
}

if (!function_exists('onlybikes_ensure_customers_schema')) {
    function onlybikes_ensure_customers_schema(PDO $pdo): void
    {
        static $done = [];
        $id = spl_object_hash($pdo);
        if (!empty($done[$id])) {
            return;
        }

        if (!onlybikes_customers_table_exists($pdo)) {
            $pdo->exec("
                CREATE TABLE customers (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    email VARCHAR(255) NOT NULL,
                    password_hash VARCHAR(255) NOT NULL,
                    first_name VARCHAR(100) NOT NULL DEFAULT '',
                    last_name VARCHAR(100) NOT NULL DEFAULT '',
                    points INT NOT NULL DEFAULT 0,
                    reset_token VARCHAR(255) NULL,
                    reset_expires DATETIME NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_customer_email (email)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            onlybikes_customers_columns($pdo, true);
            $done[$id] = true;
            return;
        }

        if (!onlybikes_customers_has_column($pdo, 'password_hash')) {
            onlybikes_customers_add_column($pdo, 'ALTER TABLE customers ADD COLUMN password_hash VARCHAR(255) NULL');
        }
        if (!onlybikes_customers_has_column($pdo, 'points') && !onlybikes_customers_has_column($pdo, 'reward_points')) {
            onlybikes_customers_add_column($pdo, 'ALTER TABLE customers ADD COLUMN points INT NOT NULL DEFAULT 0');
        }
        if (!onlybikes_customers_has_column($pdo, 'reset_token')) {
            onlybikes_customers_add_column($pdo, 'ALTER TABLE customers ADD COLUMN reset_token VARCHAR(255) NULL');
        }
        if (!onlybikes_customers_has_column($pdo, 'reset_expires')) {
            onlybikes_customers_add_column($pdo, 'ALTER TABLE customers ADD COLUMN reset_expires DATETIME NULL');
        }

        $done[$id] = true;
    }
}

if (!function_exists('onlybikes_customer_points_value')) {
    function onlybikes_customer_points_value(array $row): int
    {
        if (isset($row['points'])) {
            return (int) $row['points'];
        }
        return (int) ($row['reward_points'] ?? 0);
    }
}

if (!function_exists('onlybikes_customers_fetch_points')) {
    function onlybikes_customers_fetch_points(PDO $pdo, int $customerId): ?int
    {
        onlybikes_ensure_customers_schema($pdo);
        $col = onlybikes_customers_points_column($pdo);
        $stmt = $pdo->prepare("SELECT {$col} AS points FROM customers WHERE id = ?");
        $stmt->execute([$customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? onlybikes_customer_points_value($row) : null;
    }
}

if (!function_exists('onlybikes_customers_set_points')) {
    function onlybikes_customers_set_points(PDO $pdo, int $customerId, int $points): void
    {
        onlybikes_ensure_customers_schema($pdo);
        $col = onlybikes_customers_points_column($pdo);
        $stmt = $pdo->prepare("UPDATE customers SET {$col} = ? WHERE id = ?");
        $stmt->execute([$points, $customerId]);
    }
}
