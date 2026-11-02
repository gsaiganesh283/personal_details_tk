<?php
$pageTitle = 'Mutual Funds';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/groww-helper.php';
$user = requireAuth();
$db = Database::getInstance();

[$schemaOk, $schemaMsg] = ensureGrowwSchema();
if (!$schemaOk) {
    setFlash('error', $schemaMsg);
    redirect('/groww-dashboard.php');
}

// Handle delete
if (isset($_GET['delete']) && is_numeric($_GET['delete']) && Auth::validateCSRF($_GET['csrf'] ?? '')) {
    $db->delete("DELETE FROM groww_mutual_funds WHERE id = ? AND user_id = ?", [(int) $_GET['delete'], $user['id']]);
    setFlash('success', 'Mutual fund removed');
    redirect('/mutual-funds.php');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $editId = isset($_POST['edit_id']) ? (int) $_POST['edit_id'] : null;
    $data = [
        'fund_name'       => sanitize($_POST['fund_name'] ?? ''),
        'amc'             => sanitize($_POST['amc'] ?? ''),
        'folio_number'    => sanitize($_POST['folio_number'] ?? ''),
        'category'        => $_POST['category'] ?? '',
        'units'           => (float) ($_POST['units'] ?? 0),
        'invested_amount' => (float) ($_POST['invested_amount'] ?? 0),
        'current_value'   => (float) ($_POST['current_value'] ?? 0),
        'is_sip'          => isset($_POST['is_sip']) ? 1 : 0,
        'sip_amount'      => $_POST['sip_amount'] !== '' ? (float) $_POST['sip_amount'] : null,
        'sip_day'         => $_POST['sip_day'] !== '' ? (int) $_POST['sip_day'] : null,
        'notes'           => sanitize($_POST['notes'] ?? ''),
        'updated_on'      => date('Y-m-d'),
    ];

    if (empty($data['fund_name'])) {
        setFlash('error', 'Fund name is required');
    } else {
        if ($editId) {
            $db->update(
                "UPDATE groww_mutual_funds SET fund_name=?, amc=?, folio_number=?, category=?, units=?, invested_amount=?,
                 current_value=?, is_sip=?, sip_amount=?, sip_day=?, notes=?, updated_on=? WHERE id=? AND user_id=?",
                array_merge(array_values($data), [$editId, $user['id']])
            );
            setFlash('success', 'Mutual fund updated');
        } else {
            $db->insert(
                "INSERT INTO groww_mutual_funds (user_id, fund_name, amc, folio_number, category, units, invested_amount,
                 current_value, is_sip, sip_amount, sip_day, notes, updated_on) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                array_merge([$user['id']], array_values($data))
            );
            setFlash('success', 'Mutual fund added');
        }
        redirect('/mutual-funds.php');
    }
}

$editData = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $editData = $db->fetch("SELECT * FROM groww_mutual_funds WHERE id = ? AND user_id = ?", [(int) $_GET['edit'], $user['id']]);
}
$showForm = (isset($_GET['action']) && $_GET['action'] === 'add') || $editData;

$funds = getGrowwMutualFunds($user['id']);
$summary = summarizeGrowwMutualFunds($funds);

$categories = ['Equity', 'Debt', 'Hybrid', 'Index', 'ELSS', 'Liquid', 'Other'];
?>

<div class="toolbar">
    <div class="toolbar-left">
        <h2 style="margin:0 0 4px;"><i class="fas fa-coins" style="color: var(--primary); margin-right: 8px;"></i>Mutual Funds</h2>
        <p style="color: var(--text-muted); margin:0;">Manually tracked — Groww's API doesn't expose mutual fund data, so enter and update these yourself.</p>
    </div>
    <div class="toolbar-right">
        <?php if (!$showForm): ?>
        <a href="<?php echo url('mutual-funds.php?action=add'); ?>" class="btn btn-primary btn-sm">
            <i class="fas fa-plus"></i> Add Fund
        </a>
        <?php endif; ?>
        <a href="<?php echo url('groww-dashboard.php'); ?>" class="btn btn-ghost btn-sm"><i class="fas fa-chart-line"></i> Groww Dashboard</a>
    </div>
</div>

<?php if (!$showForm && !empty($funds)): ?>
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
            <p>Total Gain/Loss</p>
            <div class="stat-trend <?php echo $summary['pnl'] >= 0 ? 'up' : 'down'; ?>">
                <?php echo $summary['pnl_pct'] >= 0 ? '+' : ''; ?><?php echo $summary['pnl_pct']; ?>%
            </div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-calendar-check"></i></div>
        <div class="stat-info">
            <h3>₹<?php echo number_format($summary['sip_total'], 0); ?></h3>
            <p>Active SIPs / month</p>
            <div class="stat-trend up"><?php echo $summary['count']; ?> funds tracked</div>
        </div>
    </div>
</div>
<div class="alert alert-info" style="margin-top: -4px;">
    <i class="fas fa-info-circle"></i>
    <span>Values are only as fresh as your last update. Revisit periodically and edit "Current Value" against your latest Groww statement.</span>
</div>
<?php endif; ?>

<?php if ($showForm): ?>
<div class="card animate-fade-in" style="margin-top: 20px;">
    <div class="card-header">
        <h2>
            <i class="fas fa-<?php echo $editData ? 'edit' : 'plus'; ?>" style="margin-right: 8px; color: var(--primary);"></i>
            <?php echo $editData ? 'Edit Mutual Fund' : 'Add Mutual Fund'; ?>
        </h2>
    </div>
    <form method="POST" action="<?php echo url('mutual-funds.php'); ?>">
<?php echo csrf_field(); ?>
        <?php if ($editData): ?>
            <input type="hidden" name="edit_id" value="<?php echo $editData['id']; ?>">
        <?php endif; ?>

        <div class="form-row">
            <div class="form-group">
                <label>Fund Name <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="text" name="fund_name" placeholder="e.g. Parag Parikh Flexi Cap Fund" required
                           value="<?php echo htmlspecialchars($editData['fund_name'] ?? ''); ?>">
                    <i class="fas fa-coins"></i>
                </div>
            </div>
            <div class="form-group">
                <label>AMC</label>
                <div class="input-wrapper">
                    <input type="text" name="amc" placeholder="e.g. PPFAS Mutual Fund"
                           value="<?php echo htmlspecialchars($editData['amc'] ?? ''); ?>">
                    <i class="fas fa-building"></i>
                </div>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Folio Number</label>
                <div class="input-wrapper">
                    <input type="text" name="folio_number" placeholder="Folio no."
                           value="<?php echo htmlspecialchars($editData['folio_number'] ?? ''); ?>">
                    <i class="fas fa-hashtag"></i>
                </div>
            </div>
            <div class="form-group">
                <label>Category</label>
                <div class="input-wrapper">
                    <select name="category">
                        <option value="">Select category</option>
                        <?php foreach ($categories as $c): ?>
                        <option value="<?php echo $c; ?>" <?php echo ($editData['category'] ?? '') === $c ? 'selected' : ''; ?>><?php echo $c; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <i class="fas fa-list"></i>
                </div>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Units Held</label>
                <div class="input-wrapper">
                    <input type="number" step="0.001" name="units" placeholder="0.000"
                           value="<?php echo htmlspecialchars($editData['units'] ?? ''); ?>">
                    <i class="fas fa-layer-group"></i>
                </div>
            </div>
            <div class="form-group">
                <label>Invested Amount (₹)</label>
                <div class="input-wrapper">
                    <input type="number" step="0.01" name="invested_amount" placeholder="0.00"
                           value="<?php echo htmlspecialchars($editData['invested_amount'] ?? ''); ?>">
                    <i class="fas fa-indian-rupee-sign"></i>
                </div>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Current Value (₹)</label>
                <div class="input-wrapper">
                    <input type="number" step="0.01" name="current_value" placeholder="0.00"
                           value="<?php echo htmlspecialchars($editData['current_value'] ?? ''); ?>">
                    <i class="fas fa-indian-rupee-sign"></i>
                </div>
                <small style="color: var(--text-muted);">Update this from your Groww statement/app periodically.</small>
            </div>
            <div class="form-group">
                <label style="display:flex; align-items:center; gap: 8px;">
                    <input type="checkbox" name="is_sip" id="isSip" style="width:auto;" onchange="mfToggleSip()"
                           <?php echo !empty($editData['is_sip']) ? 'checked' : ''; ?>>
                    This is an active SIP
                </label>
                <div id="sipFields" style="display: <?php echo !empty($editData['is_sip']) ? 'flex' : 'none'; ?>; gap: 12px; margin-top: 8px;">
                    <div class="input-wrapper">
                        <input type="number" step="0.01" name="sip_amount" placeholder="Monthly amount"
                               value="<?php echo htmlspecialchars($editData['sip_amount'] ?? ''); ?>">
                        <i class="fas fa-indian-rupee-sign"></i>
                    </div>
                    <div class="input-wrapper">
                        <input type="number" min="1" max="28" name="sip_day" placeholder="Day of month"
                               value="<?php echo htmlspecialchars($editData['sip_day'] ?? ''); ?>">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="form-group">
            <label>Notes</label>
            <div class="input-wrapper">
                <textarea name="notes" rows="2" placeholder="Optional notes"><?php echo htmlspecialchars($editData['notes'] ?? ''); ?></textarea>
                <i class="fas fa-sticky-note"></i>
            </div>
        </div>

        <div style="margin-top: 12px; display: flex; gap: 10px;">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo $editData ? 'Update' : 'Save'; ?> Fund</button>
            <a href="<?php echo url('mutual-funds.php'); ?>" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
<script>
function mfToggleSip() {
    document.getElementById('sipFields').style.display = document.getElementById('isSip').checked ? 'flex' : 'none';
}
</script>

<?php else: ?>

<div class="card" style="margin-top: 20px;">
    <?php if (empty($funds)): ?>
        <div class="empty-state">
            <i class="fas fa-coins"></i>
            <h3>No mutual funds tracked yet</h3>
            <p>Add your holdings manually to see them alongside your Groww equity portfolio.</p>
            <a href="<?php echo url('mutual-funds.php?action=add'); ?>" class="btn btn-primary btn-sm">
                <i class="fas fa-plus"></i> Add Fund
            </a>
        </div>
    <?php else: ?>
    <div class="table-container" style="border: none;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Fund</th><th>Category</th><th>Units</th><th>Invested</th><th>Current Value</th><th>Gain/Loss</th><th>Updated</th><th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($funds as $f):
                    $pnl = (float) $f['current_value'] - (float) $f['invested_amount'];
                    $pnlPct = (float) $f['invested_amount'] > 0 ? ($pnl / (float) $f['invested_amount']) * 100 : 0;
                ?>
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars($f['fund_name']); ?></strong>
                        <?php if ($f['is_sip']): ?><span class="badge badge-info" style="margin-left:6px;">SIP ₹<?php echo number_format($f['sip_amount'], 0); ?></span><?php endif; ?>
                        <?php if ($f['amc']): ?><div style="color: var(--text-muted); font-size: 0.78rem;"><?php echo htmlspecialchars($f['amc']); ?></div><?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($f['category'] ?: '-'); ?></td>
                    <td><?php echo $f['units'] > 0 ? number_format($f['units'], 3) : '-'; ?></td>
                    <td>₹<?php echo number_format($f['invested_amount'], 2); ?></td>
                    <td>₹<?php echo number_format($f['current_value'], 2); ?></td>
                    <td>
                        <span class="badge badge-<?php echo $pnl >= 0 ? 'success' : 'danger'; ?>">
                            <?php echo $pnl >= 0 ? '+' : ''; ?>₹<?php echo number_format($pnl, 0); ?> (<?php echo round($pnlPct, 1); ?>%)
                        </span>
                    </td>
                    <td><?php echo $f['updated_on'] ? htmlspecialchars(formatDate($f['updated_on'], 'M d, Y')) : '-'; ?></td>
                    <td>
                        <a href="<?php echo url('mutual-funds.php?edit=' . $f['id']); ?>" class="btn btn-ghost btn-sm" title="Edit"><i class="fas fa-edit"></i></a>
                        <a href="<?php echo url('mutual-funds.php?delete=' . $f['id'] . '&csrf=' . urlencode(Auth::generateCSRF())); ?>" class="btn btn-ghost btn-sm" title="Delete"
                           onclick="return confirm('Remove this fund from tracking?');"><i class="fas fa-trash-alt"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
