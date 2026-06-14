<?php
require_once __DIR__ . '/config.php';
session_name(EMAIL_ADMIN_SESSION);
session_start();
require_once __DIR__ . '/includes/functions.php';
requireLogin();

require_once API_PATH . '/lib/points-ledger.php';

$customersPdo = getCustomersDatabase();
onlybikes_ensure_points_ledger_schema($customersPdo);

$pointsFlash = null;
$pointsFlashError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['points_admin_action'] ?? '') !== '') {
    $customerId = (int) ($_POST['customer_id'] ?? 0);
    $action = (string) ($_POST['points_admin_action'] ?? '');
    $amount = (int) ($_POST['points_amount'] ?? 0);
    $setValue = isset($_POST['points_set_value']) ? (int) $_POST['points_set_value'] : null;
    $note = trim((string) ($_POST['points_note'] ?? ''));
    $adminEmail = (string) ($_SESSION['user_email'] ?? 'admin');

    try {
        if ($customerId < 1) {
            throw new RuntimeException('Select a customer account.');
        }
        onlybikes_ensure_customers_schema($customersPdo);
        $current = onlybikes_customers_fetch_points($customersPdo, $customerId);
        if ($current === null) {
            throw new RuntimeException('Customer not found.');
        }

        if ($action === 'set') {
            if ($setValue === null || $setValue < 0) {
                throw new RuntimeException('Enter a valid points balance (0 or higher).');
            }
            $delta = $setValue - $current;
            if ($delta === 0) {
                $pointsFlash = 'Balance unchanged (' . $setValue . ' points).';
            } else {
                onlybikes_points_apply_delta($customersPdo, $customerId, $delta, 'admin_adjust', [
                    'actor' => 'admin',
                    'note' => $note !== '' ? $note : 'Admin set balance to ' . $setValue,
                    'meta' => ['admin_email' => $adminEmail, 'set_to' => $setValue],
                ]);
                $pointsFlash = 'Balance set to ' . $setValue . ' points (was ' . $current . ').';
            }
        } elseif ($action === 'add') {
            if ($amount < 1) {
                throw new RuntimeException('Enter points to add (1 or more).');
            }
            onlybikes_points_apply_delta($customersPdo, $customerId, $amount, 'admin_adjust', [
                'actor' => 'admin',
                'note' => $note !== '' ? $note : 'Admin added points',
                'meta' => ['admin_email' => $adminEmail],
            ]);
            $pointsFlash = 'Added ' . $amount . ' points.';
        } elseif ($action === 'subtract') {
            if ($amount < 1) {
                throw new RuntimeException('Enter points to subtract (1 or more).');
            }
            onlybikes_points_apply_delta($customersPdo, $customerId, -$amount, 'admin_adjust', [
                'actor' => 'admin',
                'note' => $note !== '' ? $note : 'Admin subtracted points',
                'meta' => ['admin_email' => $adminEmail],
            ]);
            $pointsFlash = 'Subtracted ' . $amount . ' points.';
        } else {
            throw new RuntimeException('Unknown action.');
        }
        header('Location: points.php?customer_id=' . $customerId . '&ok=1');
        exit;
    } catch (Throwable $e) {
        $pointsFlashError = $e->getMessage();
    }
}

if (isset($_GET['ok'])) {
    $pointsFlash = 'Points updated successfully.';
}

$searchQ = trim((string) ($_GET['q'] ?? ''));
$filterCustomer = isset($_GET['customer_id']) ? (int) $_GET['customer_id'] : 0;
$leaderboard = onlybikes_points_leaderboard($customersPdo, 30);
$ledger = onlybikes_points_recent_ledger($customersPdo, 150, $filterCustomer > 0 ? $filterCustomer : null);

$pointsColSql = onlybikes_customers_points_column($customersPdo);
$holderSql = 'SELECT id, email, first_name, last_name, ' . $pointsColSql . ' AS points FROM customers';
$holderParams = [];
if ($searchQ !== '') {
    $holderSql .= ' WHERE email LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR CONCAT(first_name, " ", last_name) LIKE ?';
    $like = '%' . $searchQ . '%';
    $holderParams = [$like, $like, $like, $like];
}
$holderSql .= ' ORDER BY ' . $pointsColSql . ' DESC, email ASC LIMIT 200';
$holderStmt = $customersPdo->prepare($holderSql);
$holderStmt->execute($holderParams);
$allHolders = $holderStmt->fetchAll(PDO::FETCH_ASSOC);

$selectedCustomer = null;
if ($filterCustomer > 0) {
    foreach ($allHolders as $row) {
        if ((int) $row['id'] === $filterCustomer) {
            $selectedCustomer = $row;
            break;
        }
    }
    if ($selectedCustomer === null) {
        $one = $customersPdo->prepare(
            'SELECT id, email, first_name, last_name, ' . $pointsColSql . ' AS points FROM customers WHERE id = ?'
        );
        $one->execute([$filterCustomer]);
        $selectedCustomer = $one->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

$totalPoints = 0;
foreach ($allHolders as $row) {
    $totalPoints += onlybikes_customer_points_value($row);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Rewards Points - OnlyBikes Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-stone-50 min-h-screen">
<?php renderNav('points'); ?>
<div class="max-w-7xl mx-auto px-4 py-8 space-y-8">
    <div class="flex flex-wrap justify-between items-center gap-4">
        <div>
            <h1 class="text-2xl font-bold text-stone-800">Rewards Points</h1>
            <p class="text-sm text-stone-600 mt-1">Ledger-backed balances — every earn and redeem is logged.</p>
        </div>
        <div class="text-sm text-stone-600 bg-white border rounded-lg px-4 py-2">
            <strong><?= count($allHolders) ?></strong> accounts ·
            <strong><?= number_format($totalPoints) ?></strong> points in circulation
        </div>
    </div>

    <?php if ($pointsFlash): ?>
        <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg text-sm"><?= htmlspecialchars($pointsFlash) ?></div>
    <?php endif; ?>
    <?php if ($pointsFlashError): ?>
        <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg text-sm"><?= htmlspecialchars($pointsFlashError) ?></div>
    <?php endif; ?>

    <div class="bg-white rounded-lg border shadow-sm p-6">
        <h2 class="font-semibold text-stone-800 mb-4">Adjust customer points</h2>
        <form method="get" class="flex flex-wrap gap-3 mb-4 items-end">
            <?php if ($filterCustomer): ?>
                <input type="hidden" name="customer_id" value="<?= (int) $filterCustomer ?>">
            <?php endif; ?>
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs font-medium text-stone-600 mb-1">Find account (email or name)</label>
                <input type="search" name="q" value="<?= htmlspecialchars($searchQ) ?>"
                    class="w-full px-3 py-2 border border-stone-300 rounded-lg text-sm" placeholder="email@example.com">
            </div>
            <button type="submit" class="px-4 py-2 bg-stone-800 text-white rounded-lg text-sm hover:bg-stone-900">Search</button>
            <?php if ($searchQ !== ''): ?>
                <a href="points.php" class="px-4 py-2 border border-stone-300 rounded-lg text-sm text-stone-600 hover:bg-stone-50">Clear</a>
            <?php endif; ?>
        </form>

        <form method="post" class="grid md:grid-cols-2 lg:grid-cols-4 gap-4 items-end">
            <div class="md:col-span-2">
                <label class="block text-xs font-medium text-stone-600 mb-1">Customer *</label>
                <select name="customer_id" required class="w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                    <option value="">— Select account —</option>
                    <?php foreach ($allHolders as $row):
                        $label = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                        if ($label === '') {
                            $label = $row['email'];
                        } else {
                            $label .= ' · ' . $row['email'];
                        }
                        $pts = onlybikes_customer_points_value($row);
                        $sel = $filterCustomer === (int) $row['id'] ? ' selected' : '';
                    ?>
                        <option value="<?= (int) $row['id'] ?>"<?= $sel ?>><?= htmlspecialchars($label) ?> (<?= $pts ?> pts)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-stone-600 mb-1">Action</label>
                <select name="points_admin_action" id="points-admin-action" class="w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                    <option value="add">Add points</option>
                    <option value="subtract">Subtract points</option>
                    <option value="set">Set exact balance</option>
                </select>
            </div>
            <div id="points-amount-wrap">
                <label class="block text-xs font-medium text-stone-600 mb-1">Amount</label>
                <input type="number" name="points_amount" min="1" step="1" class="w-full px-3 py-2 border border-stone-300 rounded-lg text-sm" placeholder="10">
            </div>
            <div id="points-set-wrap" class="hidden">
                <label class="block text-xs font-medium text-stone-600 mb-1">New balance</label>
                <input type="number" name="points_set_value" min="0" step="1"
                    value="<?= $selectedCustomer ? (int) onlybikes_customer_points_value($selectedCustomer) : 0 ?>"
                    class="w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
            </div>
            <div class="md:col-span-2 lg:col-span-4">
                <label class="block text-xs font-medium text-stone-600 mb-1">Note (optional)</label>
                <input type="text" name="points_note" maxlength="255" class="w-full px-3 py-2 border border-stone-300 rounded-lg text-sm" placeholder="Reason for adjustment">
            </div>
            <div class="md:col-span-2 lg:col-span-4">
                <button type="submit" class="bg-green-600 text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-green-700">Apply adjustment</button>
            </div>
        </form>
        <p class="text-xs text-stone-500 mt-3">All changes are written to the points ledger as <strong>admin_adjust</strong>.</p>
    </div>
    <script>
    (function () {
        const action = document.getElementById('points-admin-action');
        const amountWrap = document.getElementById('points-amount-wrap');
        const setWrap = document.getElementById('points-set-wrap');
        function sync() {
            const isSet = action.value === 'set';
            amountWrap.classList.toggle('hidden', isSet);
            setWrap.classList.toggle('hidden', !isSet);
        }
        action.addEventListener('change', sync);
        sync();
    })();
    </script>

    <div class="grid md:grid-cols-2 gap-6">
        <div class="bg-white rounded-lg border shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b bg-stone-50">
                <h2 class="font-semibold text-stone-800">Points leaders</h2>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-stone-50 text-xs uppercase text-stone-500">
                    <tr>
                        <th class="px-4 py-2 text-left">Customer</th>
                        <th class="px-4 py-2 text-right">Points</th>
                        <th class="px-4 py-2 text-left">View</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                <?php foreach ($leaderboard as $row): ?>
                    <tr class="hover:bg-stone-50">
                        <td class="px-4 py-2">
                            <div class="font-medium"><?= htmlspecialchars(trim($row['first_name'] . ' ' . $row['last_name']) ?: $row['email']) ?></div>
                            <div class="text-xs text-stone-500"><?= htmlspecialchars($row['email']) ?></div>
                        </td>
                        <td class="px-4 py-2 text-right font-mono font-semibold"><?= (int) onlybikes_customer_points_value($row) ?></td>
                        <td class="px-4 py-2">
                            <a class="text-sage-700 hover:underline text-xs" href="?customer_id=<?= (int) $row['id'] ?>">Ledger</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($leaderboard === []): ?>
                    <tr><td colspan="3" class="px-4 py-6 text-center text-stone-400">No points yet</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="bg-white rounded-lg border shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b bg-stone-50">
                <h2 class="font-semibold text-stone-800">All balances</h2>
            </div>
            <div class="max-h-96 overflow-y-auto">
                <table class="w-full text-sm">
                    <tbody class="divide-y">
                    <?php foreach ($allHolders as $row):
                        $pts = onlybikes_customer_points_value($row);
                        if ($pts < 1) continue;
                    ?>
                        <tr class="hover:bg-stone-50">
                            <td class="px-4 py-2 text-xs"><?= htmlspecialchars($row['email']) ?></td>
                            <td class="px-4 py-2 text-right font-mono"><?= $pts ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg border shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b bg-stone-50 flex justify-between items-center">
            <h2 class="font-semibold text-stone-800">
                Transaction ledger
                <?php if ($filterCustomer): ?><span class="text-stone-500 font-normal">(customer #<?= $filterCustomer ?>)</span><?php endif; ?>
            </h2>
            <?php if ($filterCustomer): ?>
                <a href="points.php" class="text-xs text-sage-700 hover:underline">Show all</a>
            <?php endif; ?>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-stone-50 text-xs uppercase text-stone-500">
                    <tr>
                        <th class="px-3 py-2 text-left">When</th>
                        <th class="px-3 py-2 text-left">Customer</th>
                        <th class="px-3 py-2 text-left">Type</th>
                        <th class="px-3 py-2 text-right">Δ</th>
                        <th class="px-3 py-2 text-right">Balance</th>
                        <th class="px-3 py-2 text-left">Order / ref</th>
                        <th class="px-3 py-2 text-left">Note</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                <?php foreach ($ledger as $tx):
                    $delta = (int) $tx['delta'];
                    $deltaClass = $delta >= 0 ? 'text-green-700' : 'text-red-700';
                ?>
                    <tr class="hover:bg-stone-50">
                        <td class="px-3 py-2 text-xs text-stone-500 whitespace-nowrap"><?= htmlspecialchars($tx['created_at']) ?></td>
                        <td class="px-3 py-2 text-xs">
                            <a href="?customer_id=<?= (int) $tx['customer_id'] ?>" class="text-sage-700 hover:underline"><?= htmlspecialchars($tx['email']) ?></a>
                        </td>
                        <td class="px-3 py-2"><span class="pill text-xs px-2 py-0.5 rounded bg-stone-100"><?= htmlspecialchars($tx['type']) ?></span></td>
                        <td class="px-3 py-2 text-right font-mono <?= $deltaClass ?>"><?= $delta >= 0 ? '+' : '' ?><?= $delta ?></td>
                        <td class="px-3 py-2 text-right font-mono"><?= (int) $tx['balance_after'] ?></td>
                        <td class="px-3 py-2 text-xs font-mono"><?= htmlspecialchars($tx['order_number'] ?? $tx['reference_id'] ?? '—') ?></td>
                        <td class="px-3 py-2 text-xs text-stone-500"><?= htmlspecialchars($tx['note'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($ledger === []): ?>
                    <tr><td colspan="7" class="px-4 py-8 text-center text-stone-400">No ledger entries yet — new checkouts will populate this.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-xs text-stone-500">
        Integrity cron: <code class="bg-stone-100 px-1 rounded">/api/cron-reconcile-points.php?key=CRON_SECRET</code>
        (set <code>CRON_SECRET</code> in api/.env). Types: <strong>order_earned</strong>, <strong>order_redeemed</strong>, <strong>coupon_redeemed</strong>.
    </p>
</div>
</body>
</html>
