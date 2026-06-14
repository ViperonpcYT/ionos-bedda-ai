<?php
/**
 * Newsletter DB schema helpers — legacy IONOS tables may lack subscriber `status`
 * and email_queue columns (`send_after`, `status`, etc.).
 */

function newsletterTableHasColumn(PDO $pdo, string $table, string $column): bool
{
    if (!isset($GLOBALS['newsletter_col_cache'])) {
        $GLOBALS['newsletter_col_cache'] = [];
    }
    $key = $table . '.' . $column;
    if (array_key_exists($key, $GLOBALS['newsletter_col_cache'])) {
        return $GLOBALS['newsletter_col_cache'][$key];
    }
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    $GLOBALS['newsletter_col_cache'][$key] = ((int) $stmt->fetchColumn()) > 0;
    return $GLOBALS['newsletter_col_cache'][$key];
}

function newsletterTableMarkColumn(string $table, string $column): void
{
    if (!isset($GLOBALS['newsletter_col_cache'])) {
        $GLOBALS['newsletter_col_cache'] = [];
    }
    $GLOBALS['newsletter_col_cache'][$table . '.' . $column] = true;
}

function newsletterAddColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void
{
    if (newsletterTableHasColumn($pdo, $table, $column)) {
        return;
    }
    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    newsletterTableMarkColumn($table, $column);
}

/**
 * Add `status` / `confirmed_at` on legacy newsletter_subscribers tables (IONOS Bedda-era).
 */
function ensureNewsletterSubscriberSchema(PDO $pdo): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    if (!(bool) $pdo->query("SHOW TABLES LIKE 'newsletter_subscribers'")->fetchColumn()) {
        return;
    }

    $columns = [
        'email' => 'VARCHAR(255) NOT NULL',
        'name' => 'VARCHAR(255) NULL',
        'token' => 'VARCHAR(64) NOT NULL DEFAULT \'\'',
        'unsubscribe_token' => 'VARCHAR(64) NOT NULL DEFAULT \'\'',
        'status' => "ENUM('pending','confirmed','unsubscribed') NOT NULL DEFAULT 'pending'",
        'confirmed_at' => 'DATETIME NULL',
        'source' => "VARCHAR(64) NOT NULL DEFAULT 'website'",
        'ip_address' => 'VARCHAR(45) NULL',
        'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ];

    foreach ($columns as $column => $definition) {
        try {
            newsletterAddColumnIfMissing($pdo, 'newsletter_subscribers', $column, $definition);
        } catch (PDOException $e) {
            error_log('[newsletter-schema] add column ' . $column . ': ' . $e->getMessage());
        }
    }

    if (newsletterTableHasColumn($pdo, 'newsletter_subscribers', 'status')) {
        if (newsletterTableHasColumn($pdo, 'newsletter_subscribers', 'unsubscribed')) {
            $pdo->exec("UPDATE newsletter_subscribers SET status = 'unsubscribed' WHERE unsubscribed = 1");
            $pdo->exec(
                "UPDATE newsletter_subscribers SET status = 'confirmed'
                 WHERE status = 'pending' AND (unsubscribed = 0 OR unsubscribed IS NULL)"
            );
        } elseif (newsletterTableHasColumn($pdo, 'newsletter_subscribers', 'confirmed_at')) {
            $pdo->exec(
                "UPDATE newsletter_subscribers SET status = 'confirmed'
                 WHERE confirmed_at IS NOT NULL AND status = 'pending'"
            );
        }
    }

    if (
        newsletterTableHasColumn($pdo, 'newsletter_subscribers', 'confirmed_at')
        && newsletterTableHasColumn($pdo, 'newsletter_subscribers', 'updated_at')
    ) {
        try {
            $pdo->exec(
                "UPDATE newsletter_subscribers
                 SET confirmed_at = updated_at
                 WHERE status = 'confirmed' AND confirmed_at IS NULL"
            );
        } catch (PDOException $e) {
            error_log('[newsletter-schema] confirmed_at backfill: ' . $e->getMessage());
        }
    }
}

/**
 * Align email_queue with api/sql/03-newsletter.sql (legacy tables often lack send_after).
 */
function ensureEmailQueueSchema(PDO $pdo): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    $tableExists = (bool) $pdo->query("SHOW TABLES LIKE 'email_queue'")->fetchColumn();
    if (!$tableExists) {
        $pdo->exec(
            "CREATE TABLE email_queue (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                recipient_email VARCHAR(255) NOT NULL,
                recipient_name VARCHAR(255) NULL,
                subject VARCHAR(255) NOT NULL,
                html_body MEDIUMTEXT NOT NULL,
                text_body MEDIUMTEXT NULL,
                unsubscribe_token VARCHAR(64) NULL,
                send_after DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
                retry_count INT UNSIGNED NOT NULL DEFAULT 0,
                last_error VARCHAR(255) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                sent_at DATETIME NULL,
                PRIMARY KEY (id),
                KEY idx_email_queue_pending (status, send_after)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        foreach (
            [
                'recipient_email',
                'recipient_name',
                'subject',
                'html_body',
                'text_body',
                'unsubscribe_token',
                'send_after',
                'status',
                'retry_count',
                'last_error',
                'created_at',
                'sent_at',
            ] as $col
        ) {
            newsletterTableMarkColumn('email_queue', $col);
        }
        return;
    }

    if (
        !newsletterTableHasColumn($pdo, 'email_queue', 'recipient_email')
        && newsletterTableHasColumn($pdo, 'email_queue', 'email')
    ) {
        $pdo->exec('ALTER TABLE email_queue CHANGE COLUMN email recipient_email VARCHAR(255) NOT NULL');
        newsletterTableMarkColumn('email_queue', 'recipient_email');
    } else {
        newsletterAddColumnIfMissing($pdo, 'email_queue', 'recipient_email', 'VARCHAR(255) NOT NULL');
    }

    if (
        !newsletterTableHasColumn($pdo, 'email_queue', 'html_body')
        && newsletterTableHasColumn($pdo, 'email_queue', 'html')
    ) {
        $pdo->exec('ALTER TABLE email_queue CHANGE COLUMN html html_body MEDIUMTEXT NOT NULL');
        newsletterTableMarkColumn('email_queue', 'html_body');
    }

    newsletterAddColumnIfMissing($pdo, 'email_queue', 'recipient_name', 'VARCHAR(255) NULL');
    newsletterAddColumnIfMissing($pdo, 'email_queue', 'subject', 'VARCHAR(255) NOT NULL DEFAULT \'\'');
    newsletterAddColumnIfMissing($pdo, 'email_queue', 'html_body', 'MEDIUMTEXT NOT NULL');
    newsletterAddColumnIfMissing($pdo, 'email_queue', 'text_body', 'MEDIUMTEXT NULL');
    newsletterAddColumnIfMissing($pdo, 'email_queue', 'unsubscribe_token', 'VARCHAR(64) NULL');
    newsletterAddColumnIfMissing($pdo, 'email_queue', 'send_after', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
    newsletterAddColumnIfMissing($pdo, 'email_queue', 'status', "ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending'");
    newsletterAddColumnIfMissing($pdo, 'email_queue', 'retry_count', 'INT UNSIGNED NOT NULL DEFAULT 0');
    newsletterAddColumnIfMissing($pdo, 'email_queue', 'last_error', 'VARCHAR(255) NULL');
    newsletterAddColumnIfMissing($pdo, 'email_queue', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
    newsletterAddColumnIfMissing($pdo, 'email_queue', 'sent_at', 'DATETIME NULL');

    if (newsletterTableHasColumn($pdo, 'email_queue', 'send_after')) {
        $pdo->exec(
            'UPDATE email_queue SET send_after = COALESCE(created_at, NOW())
             WHERE send_after IS NULL OR send_after = \'0000-00-00 00:00:00\''
        );
    }
    if (newsletterTableHasColumn($pdo, 'email_queue', 'status')) {
        $pdo->exec("UPDATE email_queue SET status = 'pending' WHERE status IS NULL OR status = ''");
    }
}

function ensureNewsletterDatabaseSchema(PDO $pdo): void
{
    ensureNewsletterSubscriberSchema($pdo);
    ensureEmailQueueSchema($pdo);
}

function fetchEmailQueueByStatus(PDO $pdo, string $status, int $limit = 100): array
{
    try {
        ensureNewsletterDatabaseSchema($pdo);
    } catch (Throwable $e) {
        error_log('[newsletter-schema] email_queue ensure failed: ' . $e->getMessage());
        return [];
    }

    if (!newsletterTableHasColumn($pdo, 'email_queue', 'status')) {
        return [];
    }

    $order = match ($status) {
        'sent' => newsletterTableHasColumn($pdo, 'email_queue', 'sent_at') ? 'sent_at DESC' : 'id DESC',
        'failed' => newsletterTableHasColumn($pdo, 'email_queue', 'created_at') ? 'created_at DESC' : 'id DESC',
        default => newsletterTableHasColumn($pdo, 'email_queue', 'send_after') ? 'send_after ASC' : 'id ASC',
    };

    $stmt = $pdo->prepare(
        "SELECT * FROM email_queue WHERE status = ? ORDER BY {$order} LIMIT " . (int) $limit
    );
    $stmt->execute([$status]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function countConfirmedNewsletterSubscribers(PDO $pdo): int
{
    try {
        ensureNewsletterDatabaseSchema($pdo);
    } catch (Throwable $e) {
        error_log('[newsletter-schema] ensure failed: ' . $e->getMessage());
    }

    if (newsletterTableHasColumn($pdo, 'newsletter_subscribers', 'status')) {
        return (int) $pdo
            ->query("SELECT COUNT(*) FROM newsletter_subscribers WHERE status = 'confirmed'")
            ->fetchColumn();
    }

    if (newsletterTableHasColumn($pdo, 'newsletter_subscribers', 'unsubscribed')) {
        return (int) $pdo
            ->query('SELECT COUNT(*) FROM newsletter_subscribers WHERE unsubscribed = 0')
            ->fetchColumn();
    }

    return (int) $pdo->query('SELECT COUNT(*) FROM newsletter_subscribers')->fetchColumn();
}

function getEmailQueueDashboardStats(PDO $pdo): array
{
    $empty = ['pending' => 0, 'sentToday' => 0, 'failed' => 0, 'recent' => []];

    try {
        ensureEmailQueueSchema($pdo);
    } catch (Throwable $e) {
        error_log('[newsletter-schema] queue stats ensure failed: ' . $e->getMessage());
        return $empty;
    }

    if (!newsletterTableHasColumn($pdo, 'email_queue', 'status')) {
        return $empty;
    }

    $pending = (int) $pdo
        ->query("SELECT COUNT(*) FROM email_queue WHERE status = 'pending'")
        ->fetchColumn();
    $sentToday = (int) $pdo
        ->query("SELECT COUNT(*) FROM email_queue WHERE status = 'sent' AND DATE(sent_at) = CURDATE()")
        ->fetchColumn();
    $failed = (int) $pdo
        ->query("SELECT COUNT(*) FROM email_queue WHERE status = 'failed'")
        ->fetchColumn();
    $recent = $pdo
        ->query('SELECT recipient_email AS email, status, sent_at FROM email_queue ORDER BY id DESC LIMIT 10')
        ->fetchAll(PDO::FETCH_ASSOC);

    return [
        'pending' => $pending,
        'sentToday' => $sentToday,
        'failed' => $failed,
        'recent' => $recent,
    ];
}
