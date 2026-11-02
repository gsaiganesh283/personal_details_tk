<?php
/**
 * Groww Daily Portfolio Snapshot Cron
 * Records one portfolio-value snapshot per connected user per day, so the
 * dashboard's trend chart has data even on days the user doesn't log in.
 *
 * Run once daily via cron:
 *   0 18 * * * php /path/to/cron-groww-snapshot.php >> /tmp/groww_cron.log 2>&1
 *
 * Or trigger via browser:
 *   https://yourdomain.com/cron-groww-snapshot.php?secret=pds_cron_2025
 */

const CRON_SECRET = 'pds_cron_2025'; // Change to a strong secret

$isCli = php_sapi_name() === 'cli';

if (!$isCli && ($_GET['secret'] ?? '') !== CRON_SECRET) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/crypto.php';
require_once __DIR__ . '/includes/groww-helper.php';
require_once __DIR__ . '/includes/groww-client.php';

[$schemaOk, $schemaMsg] = ensureGrowwSchema();
if (!$schemaOk) {
    echo "Schema error: $schemaMsg\n";
    exit(1);
}

$db = Database::getInstance();
$userIds = $db->fetchAll("SELECT user_id FROM groww_credentials WHERE is_active = 1");

$ok = 0;
$failed = 0;

foreach ($userIds as $row) {
    $userId = (int) $row['user_id'];
    try {
        $client = new GrowwClient($userId);
        $holdings = $client->getHoldings();
        $margin = $client->getMargin();

        $ltpMap = [];
        try {
            $symbols = array_map(fn($h) => 'NSE_' . ($h['trading_symbol'] ?? ''), $holdings);
            $ltpRaw = !empty($symbols) ? $client->getLtp($symbols) : [];
            foreach ($ltpRaw as $key => $val) {
                $ltpMap[$key] = is_array($val) ? (float) ($val['ltp'] ?? $val['last_price'] ?? 0) : (float) $val;
            }
        } catch (GrowwException $e) {
            // Live prices are optional for the trend snapshot — fall back to avg buy price.
        }

        $summary = buildGrowwPortfolioSummary($holdings, $ltpMap);
        recordGrowwSnapshot($userId, [
            'invested_value' => $summary['invested_value'],
            'current_value' => $summary['current_value'],
            'pnl' => $summary['pnl'],
            'pnl_pct' => $summary['pnl_pct'],
            'clear_cash' => $margin['clear_cash'] ?? 0,
            'holdings_count' => $summary['holdings_count'],
        ]);
        $ok++;
        echo "User $userId: snapshot saved (₹{$summary['current_value']})\n";
    } catch (Exception $e) {
        $failed++;
        echo "User $userId: FAILED — " . $e->getMessage() . "\n";
    }
}

echo "Done. $ok saved, $failed failed.\n";
