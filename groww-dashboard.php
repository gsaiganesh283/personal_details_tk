<?php
$pageTitle = 'Groww Dashboard';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/groww-helper.php';
require_once __DIR__ . '/includes/groww-client.php';
$user = requireAuth();
$db = Database::getInstance();

[$schemaOk, $schemaMsg] = ensureGrowwSchema();
if (!$schemaOk) {
    setFlash('error', $schemaMsg);
    redirect('/dashboard.php');
}

$connection = getGrowwConnection($user['id']);

// Mutual funds are manually tracked and independent of the Groww API connection.
$mfFunds = getGrowwMutualFunds($user['id']);
$mfSummary = summarizeGrowwMutualFunds($mfFunds);

// Handle watchlist add/remove (simple GET actions from this same page)
if ($connection && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['watch_symbol'])) {
    $symbol = strtoupper(sanitize($_POST['watch_symbol']));
    $exchange = strtoupper(sanitize($_POST['watch_exchange'] ?? 'NSE'));
    if ($symbol !== '') {
        $db->query(
            "INSERT IGNORE INTO groww_watchlist (user_id, trading_symbol, exchange, segment) VALUES (?, ?, ?, 'CASH')",
            [$user['id'], $symbol, $exchange]
        );
    }
    redirect('/groww-dashboard.php');
}
if ($connection && isset($_GET['unwatch'])) {
    $db->query("DELETE FROM groww_watchlist WHERE id = ? AND user_id = ?", [(int) $_GET['unwatch'], $user['id']]);
    redirect('/groww-dashboard.php');
}

$connError = null;
$ltpWarning = null;
$summary = null;
$insights = [];
$margin = [];
$positions = [];
$orders = [];
$watchlistRows = [];
$history = [];
$divScore = ['score' => 0, 'label' => ''];

if ($connection) {
    $client = new GrowwClient($user['id']);
    try {
        $holdings = $client->getHoldings();
        $margin = $client->getMargin();
        $positions = array_values(array_filter($client->getPositions(), function ($p) {
            $qty = (float) ($p['quantity'] ?? $p['net_carry_forward_quantity'] ?? 0);
            return $qty != 0;
        }));
        $orders = $client->getOrders(null, 0, 15);

        // Live prices are "nice to have" — some API plans don't include live-data
        // access, so a failure here shouldn't take down the whole dashboard.
        $ltpMap = [];
        try {
            $symbols = array_map(fn($h) => 'NSE_' . ($h['trading_symbol'] ?? ''), $holdings);
            $ltpRaw = !empty($symbols) ? $client->getLtp($symbols) : [];
            foreach ($ltpRaw as $key => $val) {
                $ltpMap[$key] = is_array($val) ? (float) ($val['ltp'] ?? $val['last_price'] ?? 0) : (float) $val;
            }
        } catch (GrowwException $e) {
            $ltpWarning = 'Live prices unavailable (' . $e->getMessage() . '). Showing values at average buy price.';
        }

        $summary = buildGrowwPortfolioSummary($holdings, $ltpMap);
        $divScore = growwDiversificationScore($summary['rows'], $summary['current_value']);
        $insights = buildGrowwInsights($summary, $margin);

        recordGrowwSnapshot($user['id'], [
            'invested_value' => $summary['invested_value'],
            'current_value' => $summary['current_value'],
            'pnl' => $summary['pnl'],
            'pnl_pct' => $summary['pnl_pct'],
            'clear_cash' => $margin['clear_cash'] ?? 0,
            'holdings_count' => $summary['holdings_count'],
        ]);
        $history = getGrowwSnapshotHistory($user['id'], 90);

        // Watchlist
        $watchlistRows = $db->fetchAll("SELECT * FROM groww_watchlist WHERE user_id = ? ORDER BY created_at DESC", [$user['id']]);
        if (!empty($watchlistRows)) {
            try {
                $wSymbols = array_map(fn($w) => $w['exchange'] . '_' . $w['trading_symbol'], $watchlistRows);
                $wLtpRaw = $client->getLtp($wSymbols);
                foreach ($watchlistRows as &$w) {
                    $key = $w['exchange'] . '_' . $w['trading_symbol'];
                    $v = $wLtpRaw[$key] ?? null;
                    $w['ltp'] = is_array($v) ? (float) ($v['ltp'] ?? $v['last_price'] ?? 0) : (float) ($v ?? 0);
                }
                unset($w);
            } catch (GrowwException $e) {
                $ltpWarning ??= 'Live prices unavailable (' . $e->getMessage() . ').';
            }
        }
    } catch (GrowwAuthException $e) {
        $connError = $e->getMessage();
    } catch (GrowwException $e) {
        $connError = $e->getMessage();
    }
}
?>

<div class="toolbar">
    <div class="toolbar-left">
        <h2 style="margin:0 0 4px;"><i class="fas fa-chart-line" style="color: var(--primary); margin-right: 8px;"></i>Groww Dashboard</h2>
        <p style="color: var(--text-muted); margin:0;">Live holdings, positions &amp; portfolio insights from your Groww account.</p>
    </div>
    <div class="toolbar-right">
        <a href="<?php echo url('mutual-funds.php'); ?>" class="btn btn-ghost btn-sm"><i class="fas fa-coins"></i> Mutual Funds</a>
        <a href="<?php echo url('groww-dashboard.php'); ?>" class="btn btn-ghost btn-sm"><i class="fas fa-rotate"></i> Refresh</a>
        <a href="<?php echo url('groww-settings.php'); ?>" class="btn btn-ghost btn-sm"><i class="fas fa-gear"></i> Settings</a>
    </div>
</div>

<?php if (!empty($mfFunds)): ?>
<div class="card animate-fade-in" style="margin-bottom: 24px;">
    <div class="card-header">
        <h2><i class="fas fa-coins" style="margin-right: 8px; color: var(--warning);"></i> Mutual Funds <span style="color: var(--text-muted); font-weight: 400; font-size: 0.8rem;">(manually tracked)</span></h2>
        <a href="<?php echo url('mutual-funds.php'); ?>" class="btn btn-ghost btn-sm">Manage <i class="fas fa-arrow-right"></i></a>
    </div>
    <div style="display: flex; flex-wrap: wrap; gap: 28px;">
        <div>
            <div style="color: var(--text-muted); font-size: 0.85rem;">Current Value</div>
            <div style="font-weight: 700; font-size: 1.2rem;">₹<?php echo number_format($mfSummary['current_value'], 0); ?></div>
        </div>
        <div>
            <div style="color: var(--text-muted); font-size: 0.85rem;">Invested</div>
            <div style="font-weight: 700; font-size: 1.2rem;">₹<?php echo number_format($mfSummary['invested_value'], 0); ?></div>
        </div>
        <div>
            <div style="color: var(--text-muted); font-size: 0.85rem;">Gain/Loss</div>
            <div style="font-weight: 700; font-size: 1.2rem; color: <?php echo $mfSummary['pnl'] >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;">
                <?php echo $mfSummary['pnl'] >= 0 ? '+' : ''; ?>₹<?php echo number_format($mfSummary['pnl'], 0); ?> (<?php echo $mfSummary['pnl_pct']; ?>%)
            </div>
        </div>
        <div>
            <div style="color: var(--text-muted); font-size: 0.85rem;">Active SIPs</div>
            <div style="font-weight: 700; font-size: 1.2rem;">₹<?php echo number_format($mfSummary['sip_total'], 0); ?>/mo</div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!$connection): ?>
    <div class="card empty-state animate-fade-in">
        <i class="fas fa-chart-line"></i>
        <h3>Connect your Groww account</h3>
        <p>Add your Groww Trading API credentials to see live holdings, P&amp;L, and portfolio insights.</p>
        <a href="<?php echo url('groww-settings.php'); ?>" class="btn btn-primary btn-sm">
            <i class="fas fa-plug"></i> Connect Groww
        </a>
    </div>

<?php elseif ($connError): ?>
    <div class="alert alert-error animate-fade-in">
        <i class="fas fa-exclamation-circle"></i>
        <span><?php echo htmlspecialchars($connError); ?></span>
    </div>
    <div class="card">
        <p style="margin: 0 0 12px; color: var(--text-muted);">
            Groww access tokens expire daily, and API keys sometimes need re-approval on Groww's site.
        </p>
        <a href="<?php echo url('groww-settings.php'); ?>" class="btn btn-primary btn-sm"><i class="fas fa-plug"></i> Reconnect</a>
        <a href="https://groww.in/trade-api/api-keys" target="_blank" rel="noopener" class="btn btn-ghost btn-sm"><i class="fas fa-external-link-alt"></i> Approve key on Groww</a>
    </div>

<?php else: ?>

    <?php if ($ltpWarning): ?>
    <div class="alert alert-warning animate-fade-in">
        <i class="fas fa-exclamation-triangle"></i>
        <span><?php echo htmlspecialchars($ltpWarning); ?></span>
    </div>
    <?php endif; ?>

    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon purple"><i class="fas fa-wallet"></i></div>
            <div class="stat-info">
                <h3>₹<?php echo number_format($summary['current_value'], 0); ?></h3>
                <p>Current Value</p>
                <div class="stat-trend up">Invested ₹<?php echo number_format($summary['invested_value'], 0); ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon <?php echo $summary['pnl'] >= 0 ? 'green' : 'red'; ?>">
                <i class="fas fa-<?php echo $summary['pnl'] >= 0 ? 'arrow-trend-up' : 'arrow-trend-down'; ?>"></i>
            </div>
            <div class="stat-info">
                <h3 style="color: <?php echo $summary['pnl'] >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;">
                    <?php echo $summary['pnl'] >= 0 ? '+' : ''; ?>₹<?php echo number_format($summary['pnl'], 0); ?>
                </h3>
                <p>Total P&amp;L</p>
                <div class="stat-trend <?php echo $summary['pnl'] >= 0 ? 'up' : 'down'; ?>">
                    <?php echo $summary['pnl_pct'] >= 0 ? '+' : ''; ?><?php echo $summary['pnl_pct']; ?>%
                </div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon blue"><i class="fas fa-sack-dollar"></i></div>
            <div class="stat-info">
                <h3>₹<?php echo number_format($margin['clear_cash'] ?? 0, 0); ?></h3>
                <p>Available Cash</p>
                <div class="stat-trend up">Ready to deploy</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: rgba(78, 205, 196, 0.15); color: #4ECDC4;"><i class="fas fa-layer-group"></i></div>
            <div class="stat-info">
                <h3><?php echo $summary['holdings_count']; ?></h3>
                <p>Holdings</p>
                <div class="stat-trend up"><?php echo count($positions); ?> open positions</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: rgba(108, 99, 255, 0.15); color: #6C63FF;"><i class="fas fa-diagram-project"></i></div>
            <div class="stat-info">
                <h3><?php echo $divScore['score']; ?>/100</h3>
                <p>Diversification Score</p>
                <div class="stat-trend up"><?php echo htmlspecialchars($divScore['label']); ?></div>
            </div>
        </div>
    </div>

    <!-- Insights -->
    <?php if (!empty($insights)): ?>
    <div class="card animate-fade-in" style="margin-top: 24px;">
        <div class="card-header">
            <h2><i class="fas fa-lightbulb" style="margin-right: 8px; color: var(--warning);"></i> Insights &amp; Recommendations</h2>
        </div>
        <p style="color: var(--text-muted); margin: -8px 0 16px; font-size: 0.85rem;">
            Automated observations computed from your own portfolio data — not financial advice.
        </p>
        <div class="groww-insights-grid">
            <?php foreach ($insights as $ins): ?>
            <div class="groww-insight groww-insight-<?php echo htmlspecialchars($ins['type']); ?>">
                <i class="fas <?php echo htmlspecialchars($ins['icon']); ?>"></i>
                <div>
                    <h4><?php echo htmlspecialchars($ins['title']); ?></h4>
                    <p><?php echo htmlspecialchars($ins['body']); ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Charts -->
    <div class="dashboard-grid" style="margin-top: 24px;">
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-chart-area" style="margin-right: 8px; color: var(--primary);"></i> Portfolio Value Trend</h2>
            </div>
            <?php if (count($history) < 2): ?>
                <div class="empty-state" style="padding: 30px 10px;">
                    <i class="fas fa-chart-line"></i>
                    <h3>Building history</h3>
                    <p>Check back after a few days to see your portfolio trend — each visit records a daily snapshot.</p>
                </div>
            <?php else: ?>
                <canvas id="growwTrendChart" height="220" role="img" aria-label="Portfolio value over time"></canvas>
            <?php endif; ?>
        </div>
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-chart-pie" style="margin-right: 8px; color: var(--success);"></i> Allocation</h2>
            </div>
            <?php if (empty($summary['rows'])): ?>
                <div class="empty-state" style="padding: 30px 10px;">
                    <i class="fas fa-chart-pie"></i>
                    <h3>No holdings</h3>
                </div>
            <?php else: ?>
                <canvas id="growwAllocChart" height="220" role="img" aria-label="Portfolio allocation by holding"></canvas>
            <?php endif; ?>
        </div>
    </div>

    <!-- Holdings Table -->
    <div class="card" style="margin-top: 24px;">
        <div class="card-header">
            <h2><i class="fas fa-list" style="margin-right: 8px; color: var(--primary);"></i> Holdings</h2>
        </div>
        <?php if (empty($summary['rows'])): ?>
            <div class="empty-state">
                <i class="fas fa-folder-open"></i>
                <h3>No holdings found</h3>
                <p>Your Groww holdings will appear here once you own shares.</p>
            </div>
        <?php else: ?>
        <div class="table-container" style="border: none;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Symbol</th><th>Qty</th><th>Avg Price</th><th>LTP</th><th>Current Value</th><th>P&amp;L</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($summary['rows'] as $r): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($r['symbol']); ?></strong></td>
                        <td><?php echo number_format($r['quantity'], 0); ?></td>
                        <td>₹<?php echo number_format($r['avg_price'], 2); ?></td>
                        <td>₹<?php echo number_format($r['ltp'], 2); ?></td>
                        <td>₹<?php echo number_format($r['current_value'], 2); ?></td>
                        <td>
                            <span class="badge badge-<?php echo $r['pnl'] >= 0 ? 'success' : 'danger'; ?>">
                                <?php echo $r['pnl'] >= 0 ? '+' : ''; ?>₹<?php echo number_format($r['pnl'], 0); ?>
                                (<?php echo $r['pnl_pct'] >= 0 ? '+' : ''; ?><?php echo round($r['pnl_pct'], 1); ?>%)
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Positions Table -->
    <?php if (!empty($positions)): ?>
    <div class="card" style="margin-top: 24px;">
        <div class="card-header">
            <h2><i class="fas fa-bolt" style="margin-right: 8px; color: var(--warning);"></i> Open Positions (F&amp;O / Commodity / Intraday)</h2>
        </div>
        <div class="table-container" style="border: none;">
            <table class="data-table">
                <thead>
                    <tr><th>Symbol</th><th>Exchange</th><th>Product</th><th>Net Qty</th><th>Realised P&amp;L</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($positions as $p): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($p['trading_symbol'] ?? '-'); ?></strong></td>
                        <td><?php echo htmlspecialchars($p['exchange'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($p['product'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($p['net_carry_forward_quantity'] ?? $p['quantity'] ?? 0)); ?></td>
                        <td>
                            <?php $rp = (float) ($p['realised_pnl'] ?? 0); ?>
                            <span class="badge badge-<?php echo $rp >= 0 ? 'success' : 'danger'; ?>">₹<?php echo number_format($rp, 0); ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Orders / Trade History -->
    <div class="card" style="margin-top: 24px;">
        <div class="card-header">
            <h2><i class="fas fa-receipt" style="margin-right: 8px; color: var(--info);"></i> Recent Orders</h2>
        </div>
        <?php if (empty($orders)): ?>
            <div class="empty-state">
                <i class="fas fa-receipt"></i>
                <h3>No recent orders</h3>
            </div>
        <?php else: ?>
        <div class="table-container" style="border: none;">
            <table class="data-table">
                <thead>
                    <tr><th>Symbol</th><th>Side</th><th>Qty</th><th>Price</th><th>Status</th><th>Placed</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $o):
                        $side = strtoupper($o['transaction_type'] ?? '-');
                        $status = $o['order_status'] ?? '-';
                        $statusClass = in_array(strtoupper($status), ['EXECUTED', 'COMPLETE']) ? 'success'
                            : (in_array(strtoupper($status), ['REJECTED', 'CANCELLED', 'FAILED']) ? 'danger' : 'info');
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($o['trading_symbol'] ?? '-'); ?></strong></td>
                        <td><span class="badge badge-<?php echo $side === 'BUY' ? 'success' : 'danger'; ?>"><?php echo htmlspecialchars($side); ?></span></td>
                        <td><?php echo htmlspecialchars((string) ($o['quantity'] ?? '-')); ?></td>
                        <td>₹<?php echo htmlspecialchars((string) ($o['price'] ?? '-')); ?></td>
                        <td><span class="badge badge-<?php echo $statusClass; ?>"><?php echo htmlspecialchars($status); ?></span></td>
                        <td><?php echo htmlspecialchars($o['created_at'] ?? ($o['trade_date'] ?? '-')); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Watchlist -->
    <div class="card" style="margin-top: 24px;">
        <div class="card-header">
            <h2><i class="fas fa-eye" style="margin-right: 8px; color: var(--primary-light);"></i> Watchlist</h2>
        </div>
        <form method="POST" action="<?php echo url('groww-dashboard.php'); ?>" style="display:flex; gap: 10px; margin-bottom: 16px; flex-wrap: wrap;">
<?php echo csrf_field(); ?>
            <input type="text" name="watch_symbol" placeholder="e.g. RELIANCE" required style="max-width: 200px;">
            <select name="watch_exchange" style="max-width: 120px;">
                <option value="NSE">NSE</option>
                <option value="BSE">BSE</option>
            </select>
            <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-plus"></i> Add</button>
        </form>
        <?php if (empty($watchlistRows)): ?>
            <div class="empty-state">
                <i class="fas fa-eye"></i>
                <h3>Watchlist is empty</h3>
                <p>Track instruments you don't hold yet.</p>
            </div>
        <?php else: ?>
        <div class="table-container" style="border: none;">
            <table class="data-table">
                <thead><tr><th>Symbol</th><th>Exchange</th><th>LTP</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($watchlistRows as $w): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($w['trading_symbol']); ?></strong></td>
                        <td><?php echo htmlspecialchars($w['exchange']); ?></td>
                        <td>₹<?php echo number_format($w['ltp'] ?? 0, 2); ?></td>
                        <td>
                            <a href="<?php echo url('groww-dashboard.php?unwatch=' . $w['id']); ?>" class="btn btn-ghost btn-sm" title="Remove"
                               onclick="return confirm('Remove from watchlist?');">
                                <i class="fas fa-times"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script>
    (function () {
        var muted = '#8B8DA8', grid = 'rgba(255,255,255,0.06)', surface = '#1a1a2e';

        <?php if (count($history) >= 2): ?>
        var trendCtx = document.getElementById('growwTrendChart');
        if (trendCtx) {
            new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_map(fn($h) => date('M d', strtotime($h['snapshot_date'])), $history)); ?>,
                    datasets: [
                        {
                            label: 'Invested',
                            data: <?php echo json_encode(array_map(fn($h) => (float) $h['invested_value'], $history)); ?>,
                            borderColor: muted,
                            borderDash: [4, 4],
                            borderWidth: 1.5,
                            pointRadius: 0,
                            fill: false,
                            tension: 0.25,
                        },
                        {
                            label: 'Current Value',
                            data: <?php echo json_encode(array_map(fn($h) => (float) $h['current_value'], $history)); ?>,
                            borderColor: '#6C63FF',
                            backgroundColor: 'rgba(108, 99, 255, 0.10)',
                            borderWidth: 2,
                            pointRadius: 3,
                            pointBackgroundColor: '#6C63FF',
                            pointBorderColor: surface,
                            pointBorderWidth: 2,
                            fill: true,
                            tension: 0.25,
                        },
                    ],
                },
                options: {
                    responsive: true,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: true, labels: { color: muted, usePointStyle: true } },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) { return ctx.dataset.label + ': ₹' + Number(ctx.parsed.y).toLocaleString('en-IN'); }
                            }
                        },
                    },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: muted } },
                        y: { grid: { color: grid }, ticks: { color: muted, callback: v => '₹' + Number(v).toLocaleString('en-IN') } },
                    },
                },
            });
        }
        <?php endif; ?>

        <?php if (!empty($summary['rows'])):
            $allocRows = $summary['rows'];
            $top = array_slice($allocRows, 0, 5);
            $othersValue = 0;
            foreach (array_slice($allocRows, 5) as $r) { $othersValue += $r['current_value']; }
            $allocLabels = array_map(fn($r) => $r['symbol'], $top);
            $allocValues = array_map(fn($r) => round($r['current_value'], 2), $top);
            if ($othersValue > 0) { $allocLabels[] = 'Others'; $allocValues[] = round($othersValue, 2); }
        ?>
        var allocCtx = document.getElementById('growwAllocChart');
        if (allocCtx) {
            new Chart(allocCtx, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode($allocLabels); ?>,
                    datasets: [{
                        data: <?php echo json_encode($allocValues); ?>,
                        backgroundColor: ['#6C63FF', '#4ECDC4', '#FFD93D', '#FF6B6B', '#45B7D1', '#8B83FF'],
                        borderColor: surface,
                        borderWidth: 2,
                        hoverOffset: 6,
                    }],
                },
                options: {
                    responsive: true,
                    cutout: '62%',
                    plugins: {
                        legend: { position: 'bottom', labels: { color: muted, usePointStyle: true, padding: 14 } },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    var total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                    var pct = total > 0 ? (ctx.parsed / total * 100).toFixed(1) : 0;
                                    return ctx.label + ': ₹' + Number(ctx.parsed).toLocaleString('en-IN') + ' (' + pct + '%)';
                                }
                            }
                        },
                    },
                },
            });
        }
        <?php endif; ?>
    })();
    </script>

<?php endif; ?>

<style>
.groww-insights-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; }
.groww-insight { display: flex; gap: 12px; padding: 14px; border-radius: var(--radius-sm, 10px); background: var(--bg-input, rgba(255,255,255,0.03)); border: 1px solid var(--border, rgba(255,255,255,0.08)); }
.groww-insight i { font-size: 1.1rem; margin-top: 2px; }
.groww-insight h4 { margin: 0 0 4px; font-size: 0.9rem; }
.groww-insight p { margin: 0; font-size: 0.82rem; color: var(--text-muted); }
.groww-insight-success i { color: var(--success); }
.groww-insight-warning i { color: var(--warning); }
.groww-insight-danger i { color: var(--danger); }
.groww-insight-info i { color: var(--info); }
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
