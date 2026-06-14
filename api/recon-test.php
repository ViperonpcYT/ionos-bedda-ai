<?php
/**
 * Bedda Reconciliation Test Runner – Browser output
 * Delete after testing!
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/recon-test.log');

// Security key (use a random string you choose)
define('TEST_KEY', 'bedda-test-789');   // ← change this

// Check key from URL: https://bedda.ca/api/recon-test.php?key=bedda-test-789
if (($_GET['key'] ?? '') !== TEST_KEY) {
    http_response_code(403);
    die('Access denied. Add ?key=' . TEST_KEY . ' to the URL.');
}

// Load dependencies
require_once __DIR__ . '/secure-config.php';
require_once '/homepages/6/d4299539843/htdocs/stripe-php-master/init.php';

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

echo "<pre>=== BEDDA RECONCILIATION TEST ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// ===================== CONFIGURATION =====================
$lookbackHours = 48;
$limit = 100;
// =========================================================

try {
    $pdo = getOrderDatabase();
    echo "[OK] Database connected.\n\n";

    // --- DIAGNOSTIC: fetch ALL invoices (no status filter, no date filter) ---
    echo "=== FETCHING ALL INVOICES (no filter) ===\n";

    echo "\n=== DIRECT FETCH OF RENEWAL INVOICES ===\n";
    $ids = ['in_1TYvunKGzcdqHESC7mWdCo2c', 'in_1TYwZJKGzcdqHESCDvemASpe']; // add the third if you know it
    foreach ($ids as $id) {
        try {
            $inv = \Stripe\Invoice::retrieve($id);
            echo "ID: {$inv->id}\n";
            echo "Status: {$inv->status}\n";
            echo "Billing reason: {$inv->billing_reason}\n";
            $subId = $inv->subscription ?? ($inv->parent->subscription_details->subscription ?? null);
            echo "Subscription: " . ($subId ? $subId : 'NULL') . "\n";
            echo "Customer email: {$inv->customer_email}\n";
            echo "Created: " . date('Y-m-d H:i:s', $inv->created) . "\n";
            echo "\n";
        } catch (Exception $e) {
            echo "Error retrieving $id: " . $e->getMessage() . "\n";
        }
    }
    $allInvoices = \Stripe\Invoice::all(['limit' => 10]);
    $count = 0;
    foreach ($allInvoices->autoPagingIterator() as $inv) {
        $count++;
        echo "Invoice #$count\n";
        echo "  ID: {$inv->id}\n";
        echo "  Status: {$inv->status}\n";
        echo "  Billing reason: " . ($inv->billing_reason ?? 'NULL') . "\n";
        $subId = $inv->subscription ?? ($inv->parent->subscription_details->subscription ?? null);
        echo "  Subscription: " . ($subId ? $subId : 'NULL') . "\n";
        echo "  Customer email: " . ($inv->customer_email ?? 'NULL') . "\n";
        echo "  Created (timestamp): {$inv->created}\n";
        echo "  Created (date): " . date('Y-m-d H:i:s', $inv->created) . "\n";
        echo "\n";
    }
    echo "Total invoices: $count\n\n";

    if ($count === 0) {
        echo "[WARN] No invoices returned at all! Check your Stripe API key.\n";
        echo "Key being used: " . substr(STRIPE_SECRET_KEY, 0, 12) . "...\n";
    }
} catch (Exception $e) {
    echo "\n[ERROR] " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

echo "</pre>";