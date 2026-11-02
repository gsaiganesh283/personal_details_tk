<?php
/**
 * Groww dashboard: schema bootstrap, credential storage, and the
 * analytics/insights engine. All insights here are computed directly from
 * the user's own holdings/positions/margin data — no market predictions or
 * personalized trade advice.
 */

require_once __DIR__ . '/crypto.php';

/**
 * Ensure Groww tables exist. Self-healing migration: creates/updates the
 * schema on demand rather than requiring a separate migration step.
 */
function ensureGrowwSchema(): array {
    if (!class_exists('Database')) return [false, 'Database class not loaded'];

    try {
        $db = Database::getInstance();

        if (!dbTableExists('groww_credentials')) {
            $db->query(
                "CREATE TABLE groww_credentials (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    auth_method ENUM('access_token','api_secret','totp') NOT NULL DEFAULT 'access_token',
                    api_key_enc TEXT NULL,
                    api_secret_enc TEXT NULL,
                    totp_secret_enc TEXT NULL,
                    access_token_enc TEXT NULL,
                    token_expires_at DATETIME NULL,
                    last_error VARCHAR(255) NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_groww_user (user_id),
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        }

        if (!dbTableExists('groww_portfolio_snapshots')) {
            $db->query(
                "CREATE TABLE groww_portfolio_snapshots (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    snapshot_date DATE NOT NULL,
                    invested_value DECIMAL(15,2) NOT NULL DEFAULT 0,
                    current_value DECIMAL(15,2) NOT NULL DEFAULT 0,
                    pnl DECIMAL(15,2) NOT NULL DEFAULT 0,
                    pnl_pct DECIMAL(8,3) NOT NULL DEFAULT 0,
                    clear_cash DECIMAL(15,2) NOT NULL DEFAULT 0,
                    holdings_count INT NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_groww_snap (user_id, snapshot_date),
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        }

        if (!dbTableExists('groww_watchlist')) {
            $db->query(
                "CREATE TABLE groww_watchlist (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    trading_symbol VARCHAR(50) NOT NULL,
                    exchange VARCHAR(10) NOT NULL DEFAULT 'NSE',
                    segment VARCHAR(20) NOT NULL DEFAULT 'CASH',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_groww_watch (user_id, trading_symbol, segment),
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        }

        if (!dbTableExists('groww_mutual_funds')) {
            $db->query(
                "CREATE TABLE groww_mutual_funds (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    fund_name VARCHAR(150) NOT NULL,
                    amc VARCHAR(100) NULL,
                    folio_number VARCHAR(50) NULL,
                    category VARCHAR(50) NULL,
                    units DECIMAL(14,3) NOT NULL DEFAULT 0,
                    invested_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
                    current_value DECIMAL(15,2) NOT NULL DEFAULT 0,
                    is_sip TINYINT(1) NOT NULL DEFAULT 0,
                    sip_amount DECIMAL(12,2) NULL,
                    sip_day TINYINT NULL COMMENT 'Day of month SIP is debited, 1-28',
                    notes TEXT NULL,
                    updated_on DATE NULL COMMENT 'Date the user last refreshed current_value',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_groww_mf_user (user_id),
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        }

        return [true, 'ok'];
    } catch (Exception $e) {
        return [false, 'Groww schema auto-fix failed: ' . substr($e->getMessage(), 0, 180)];
    }
}

function getGrowwConnection(int $userId): ?array {
    $db = Database::getInstance();
    $row = $db->fetch("SELECT * FROM groww_credentials WHERE user_id = ?", [$userId]);
    if (!$row) return null;
    return [
        'auth_method' => $row['auth_method'],
        'api_key_masked' => pds_mask_secret(pds_decrypt($row['api_key_enc'])),
        'has_secret' => !empty($row['api_secret_enc']),
        'has_totp' => !empty($row['totp_secret_enc']),
        'has_access_token' => !empty($row['access_token_enc']),
        'token_expires_at' => $row['token_expires_at'],
        'last_error' => $row['last_error'],
        'is_active' => (bool) $row['is_active'],
        'updated_at' => $row['updated_at'],
    ];
}

function saveGrowwCredentials(int $userId, string $authMethod, array $fields): void {
    $db = Database::getInstance();
    $existing = $db->fetch("SELECT id FROM groww_credentials WHERE user_id = ?", [$userId]);

    $apiKeyEnc = array_key_exists('api_key', $fields) ? pds_encrypt($fields['api_key']) : null;
    $apiSecretEnc = array_key_exists('api_secret', $fields) ? pds_encrypt($fields['api_secret']) : null;
    $totpSecretEnc = array_key_exists('totp_secret', $fields) ? pds_encrypt($fields['totp_secret']) : null;
    $accessTokenEnc = array_key_exists('access_token', $fields) ? pds_encrypt($fields['access_token']) : null;

    if ($existing) {
        $sets = ['auth_method = ?', 'is_active = 1', 'last_error = NULL', 'updated_at = NOW()'];
        $params = [$authMethod];
        foreach ([
            'api_key' => $apiKeyEnc, 'api_secret' => $apiSecretEnc,
            'totp_secret' => $totpSecretEnc, 'access_token' => $accessTokenEnc,
        ] as $field => $encVal) {
            if (array_key_exists($field, $fields)) {
                $sets[] = "{$field}_enc = ?";
                $params[] = $encVal;
            }
        }
        if (array_key_exists('access_token', $fields)) {
            $sets[] = 'token_expires_at = NULL'; // force a validity check on next use
        }
        $params[] = $userId;
        $db->query("UPDATE groww_credentials SET " . implode(', ', $sets) . " WHERE user_id = ?", $params);
    } else {
        $db->insert(
            "INSERT INTO groww_credentials (user_id, auth_method, api_key_enc, api_secret_enc, totp_secret_enc, access_token_enc)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$userId, $authMethod, $apiKeyEnc, $apiSecretEnc, $totpSecretEnc, $accessTokenEnc]
        );
    }
}

function deleteGrowwCredentials(int $userId): void {
    $db = Database::getInstance();
    $db->query("DELETE FROM groww_credentials WHERE user_id = ?", [$userId]);
}

/**
 * Persist (or update) today's portfolio snapshot for trend charting.
 */
function recordGrowwSnapshot(int $userId, array $summary): void {
    $db = Database::getInstance();
    $db->query(
        "INSERT INTO groww_portfolio_snapshots (user_id, snapshot_date, invested_value, current_value, pnl, pnl_pct, clear_cash, holdings_count)
         VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE invested_value = VALUES(invested_value), current_value = VALUES(current_value),
             pnl = VALUES(pnl), pnl_pct = VALUES(pnl_pct), clear_cash = VALUES(clear_cash), holdings_count = VALUES(holdings_count)",
        [
            $userId,
            $summary['invested_value'], $summary['current_value'], $summary['pnl'], $summary['pnl_pct'],
            $summary['clear_cash'] ?? 0, $summary['holdings_count'] ?? 0,
        ]
    );
}

/**
 * Manually-tracked mutual fund holdings — Groww's Trading API doesn't expose
 * MF/SIP data, so these are user-entered and self-reported.
 */
function getGrowwMutualFunds(int $userId): array {
    $db = Database::getInstance();
    return $db->fetchAll("SELECT * FROM groww_mutual_funds WHERE user_id = ? ORDER BY fund_name ASC", [$userId]);
}

function summarizeGrowwMutualFunds(array $funds): array {
    $invested = 0.0;
    $current = 0.0;
    foreach ($funds as $f) {
        $invested += (float) $f['invested_amount'];
        $current += (float) $f['current_value'];
    }
    $pnl = $current - $invested;
    return [
        'invested_value' => round($invested, 2),
        'current_value' => round($current, 2),
        'pnl' => round($pnl, 2),
        'pnl_pct' => $invested > 0 ? round(($pnl / $invested) * 100, 2) : 0,
        'count' => count($funds),
        'sip_total' => array_sum(array_map(fn($f) => $f['is_sip'] ? (float) $f['sip_amount'] : 0, $funds)),
    ];
}

function getGrowwSnapshotHistory(int $userId, int $days = 90): array {
    $db = Database::getInstance();
    return $db->fetchAll(
        "SELECT * FROM groww_portfolio_snapshots WHERE user_id = ? AND snapshot_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY) ORDER BY snapshot_date ASC",
        [$userId, $days]
    );
}

// ------------------------------------------------------------------
// Analytics engine — pure functions over holdings/positions/margin
// ------------------------------------------------------------------

/**
 * Merge holdings with live LTP data and compute per-holding + portfolio
 * level metrics used across the dashboard and insights panel.
 */
function buildGrowwPortfolioSummary(array $holdings, array $ltpMap): array {
    $rows = [];
    $investedTotal = 0.0;
    $currentTotal = 0.0;

    foreach ($holdings as $h) {
        $symbol = $h['trading_symbol'] ?? $h['isin'] ?? 'UNKNOWN';
        $qty = (float) ($h['quantity'] ?? 0);
        $avgPrice = (float) ($h['average_price'] ?? 0);
        $ltpKey = 'NSE_' . $symbol;
        $ltp = (float) ($ltpMap[$ltpKey] ?? $ltpMap[$symbol] ?? $avgPrice);

        $invested = $qty * $avgPrice;
        $current = $qty * $ltp;
        $pnl = $current - $invested;
        $pnlPct = $invested > 0 ? ($pnl / $invested) * 100 : 0;

        $rows[] = [
            'symbol' => $symbol,
            'quantity' => $qty,
            'avg_price' => $avgPrice,
            'ltp' => $ltp,
            'invested' => $invested,
            'current_value' => $current,
            'pnl' => $pnl,
            'pnl_pct' => $pnlPct,
        ];

        $investedTotal += $invested;
        $currentTotal += $current;
    }

    // Largest first — used for allocation chart and concentration metrics.
    usort($rows, fn($a, $b) => $b['current_value'] <=> $a['current_value']);

    $pnlTotal = $currentTotal - $investedTotal;
    $pnlTotalPct = $investedTotal > 0 ? ($pnlTotal / $investedTotal) * 100 : 0;

    return [
        'rows' => $rows,
        'invested_value' => round($investedTotal, 2),
        'current_value' => round($currentTotal, 2),
        'pnl' => round($pnlTotal, 2),
        'pnl_pct' => round($pnlTotalPct, 2),
        'holdings_count' => count($rows),
    ];
}

/**
 * Herfindahl-Hirschman Index based diversification score (0-100, higher = more diversified).
 */
function growwDiversificationScore(array $rows, float $totalValue): array {
    if ($totalValue <= 0 || empty($rows)) {
        return ['score' => 0, 'hhi' => 0, 'label' => 'No holdings'];
    }

    $hhi = 0.0;
    foreach ($rows as $r) {
        $share = $r['current_value'] / $totalValue;
        $hhi += $share * $share;
    }
    // HHI ranges 1/n (perfectly even) .. 1 (single stock). Map to a 0-100 score.
    $n = count($rows);
    $minHhi = 1 / $n;
    $score = $n > 1 ? max(0, min(100, (1 - ($hhi - $minHhi) / (1 - $minHhi)) * 100)) : 0;

    if ($score >= 75) $label = 'Well diversified';
    elseif ($score >= 45) $label = 'Moderately concentrated';
    else $label = 'Highly concentrated';

    return ['score' => round($score), 'hhi' => round($hhi, 3), 'label' => $label];
}

/**
 * Data-driven portfolio insights. Every insight here is a direct
 * observation about the user's own data — not a buy/sell signal.
 */
function buildGrowwInsights(array $summary, array $margin): array {
    $rows = $summary['rows'];
    $total = $summary['current_value'];
    $insights = [];

    if (empty($rows)) {
        return [['type' => 'info', 'icon' => 'fa-info-circle', 'title' => 'No holdings yet', 'body' => 'Connect and sync to see portfolio insights.']];
    }

    $div = growwDiversificationScore($rows, $total);
    $insights[] = [
        'type' => $div['score'] >= 60 ? 'success' : ($div['score'] >= 35 ? 'warning' : 'danger'),
        'icon' => 'fa-diagram-project',
        'title' => "Diversification score: {$div['score']}/100",
        'body' => $div['label'] . ' — based on how evenly your holdings are spread by value.',
    ];

    $top = $rows[0];
    $topPct = $total > 0 ? ($top['current_value'] / $total) * 100 : 0;
    if ($topPct >= 25) {
        $insights[] = [
            'type' => $topPct >= 40 ? 'danger' : 'warning',
            'icon' => 'fa-triangle-exclamation',
            'title' => round($topPct) . '% of your portfolio is in ' . $top['symbol'],
            'body' => 'A single-stock swing in ' . $top['symbol'] . ' will move your whole portfolio noticeably. Consider whether this concentration is intentional.',
        ];
    }

    if (count($rows) >= 3) {
        $top3Value = $rows[0]['current_value'] + $rows[1]['current_value'] + $rows[2]['current_value'];
        $top3Pct = $total > 0 ? ($top3Value / $total) * 100 : 0;
        if ($top3Pct >= 60) {
            $insights[] = [
                'type' => 'warning',
                'icon' => 'fa-layer-group',
                'title' => 'Top 3 holdings = ' . round($top3Pct) . '% of portfolio',
                'body' => 'Your top 3 positions (' . $rows[0]['symbol'] . ', ' . $rows[1]['symbol'] . ', ' . $rows[2]['symbol'] . ') dominate your allocation.',
            ];
        }
    }

    $clearCash = (float) ($margin['clear_cash'] ?? 0);
    $grandTotal = $total + $clearCash;
    if ($grandTotal > 0) {
        $cashPct = ($clearCash / $grandTotal) * 100;
        if ($cashPct >= 20) {
            $insights[] = [
                'type' => 'info',
                'icon' => 'fa-sack-dollar',
                'title' => round($cashPct) . '% of tracked funds sitting idle',
                'body' => '₹' . number_format($clearCash, 0) . ' in available cash isn\'t deployed. Idle cash earns no market returns.',
            ];
        }
    }

    $winners = array_values(array_filter($rows, fn($r) => $r['pnl'] > 0));
    $losers = array_values(array_filter($rows, fn($r) => $r['pnl'] < 0));
    usort($winners, fn($a, $b) => $b['pnl_pct'] <=> $a['pnl_pct']);
    usort($losers, fn($a, $b) => $a['pnl_pct'] <=> $b['pnl_pct']);

    if (!empty($winners)) {
        $w = $winners[0];
        $insights[] = [
            'type' => 'success',
            'icon' => 'fa-arrow-trend-up',
            'title' => 'Best performer: ' . $w['symbol'] . ' (+' . round($w['pnl_pct'], 1) . '%)',
            'body' => 'Unrealised gain of ₹' . number_format($w['pnl'], 0) . ' on this position.',
        ];
    }
    if (!empty($losers)) {
        $l = $losers[0];
        $insights[] = [
            'type' => 'danger',
            'icon' => 'fa-arrow-trend-down',
            'title' => 'Worst performer: ' . $l['symbol'] . ' (' . round($l['pnl_pct'], 1) . '%)',
            'body' => 'Unrealised loss of ₹' . number_format(abs($l['pnl']), 0) . ' on this position.',
        ];
    }

    if (count($rows) === 1) {
        $insights[] = [
            'type' => 'warning',
            'icon' => 'fa-shield-halved',
            'title' => 'Single-holding portfolio',
            'body' => 'Everything is riding on one instrument. Spreading across a few more names/sectors reduces single-event risk.',
        ];
    }

    return $insights;
}
