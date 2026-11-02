<?php
/**
 * Savings & Investment Tracker -- one tabbed page (Dashboard/Daily
 * Summary/Monthly Summary/15 categories/Custom Columns, selected via
 * ?cat=..., following the same bootstrap/self-post/?action=/?edit=/?delete=
 * conventions as bank-details.php for the category tabs), matching the
 * tkinter desktop app's single Savings Tracker screen
 * (app/ui/savings_tracker_view.py) instead of the 3 separate pages this
 * used to be split across. Kept fully separate from the Groww broker
 * integration (groww-dashboard.php, mutual-funds.php).
 */
$pageTitle = 'Savings Tracker';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/custom_fields_helper.php';
require_once __DIR__ . '/includes/savings_helper.php';
require_once __DIR__ . '/includes/savings_ledger_helper.php';
require_once __DIR__ . '/includes/action_buttons_helper.php';
require_once __DIR__ . '/includes/savings_widgets_helper.php';
$user = requireAuth();
$db = Database::getInstance();
ensureSavingsTables($db);
$CATEGORIES = $SAVINGS_CATEGORIES;

// ------------------------------------------------------------- resolve tab
// Everything below one "Savings Tracker" toolbar, on one page, matching the
// tkinter desktop app's single tabbed screen (app/ui/savings_tracker_view.py)
// instead of the 3 separate pages this used to be split across
// (savings-dashboard.php / savings-tracker-advanced.php / savingsmanage.php
// -- both now thin redirects here, see their own files). Dashboard/Daily
// Summary/Monthly Summary/Custom Columns are sentinel $cat values outside
// $CATEGORIES' own key space (which is why they're wrapped in "__"), so
// every existing ?cat=<category> deep link elsewhere in the app keeps
// working unchanged.
$SAVINGS_SPECIAL_TABS = [
    '__dashboard__' => ['label' => 'Dashboard', 'icon' => 'fa-chart-pie'],
    '__daily__'     => ['label' => 'Daily Summary', 'icon' => 'fa-calendar-day'],
    '__monthly__'   => ['label' => 'Monthly Summary', 'icon' => 'fa-calendar-alt'],
    '__custom__'    => ['label' => 'Custom Columns', 'icon' => 'fa-puzzle-piece'],
];

$cat = $_GET['cat'] ?? '__dashboard__';
$isSpecialTab = isset($SAVINGS_SPECIAL_TABS[$cat]);
if (!$isSpecialTab && !isset($CATEGORIES[$cat])) {
    $cat = '__dashboard__';
    $isSpecialTab = true;
}

if (!$isSpecialTab) {
    $config = $CATEGORIES[$cat];
    $table = $config['table'];

    // Defense-in-depth: $table is only ever set from the fixed
    // $SAVINGS_CATEGORIES map above (itself keyed by the already-validated
    // $cat), but it is whitelisted again right here, immediately before
    // use, so every `$table` interpolated into SQL below is provably one of
    // these literal identifiers and never influenced by request input.
    $validSavingsTables = array_column($SAVINGS_CATEGORIES, 'table');
    if (!in_array($table, $validSavingsTables, true) || !preg_match('/^[a-z_]+$/', $table)) {
        http_response_code(400);
        exit('Invalid category.');
    }

    // The user's renamed column names (Custom Columns -> Rename Columns)
    // replace the defaults everywhere on this page: list cards, form, view.
    $config = swApplyColumnLabels($db, (int)$user['id'], $config);
}

// ------------------------------------------------------------------- delete
if (!$isSpecialTab && isset($_GET['delete']) && is_numeric($_GET['delete']) && Auth::validateCSRF($_GET['csrf'] ?? '')) {
    $id = (int)$_GET['delete'];
    purgeActionButtonChildrenForRecord($table, $id, $user['id']);
    deleteCustomFieldFilesForRecord($table, $id);
    if (($config['ledger'] ?? false) === 'bank') {
        purgeBankTransactionsForAccount($db, $user['id'], $id);
    } elseif (!empty($config['ledger'])) {
        purgeLedgerTransactionsForRecord($db, $user['id'], $table, $id);
    }
    $db->delete(sprintf('DELETE FROM `%s` WHERE id = ? AND user_id = ?', $table), [$id, $user['id']]);
    $db->insert(
        "INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
        [$user['id'], 'delete', 'Deleted ' . $config['label'] . ' record', $_SERVER['REMOTE_ADDR'] ?? '']
    );
    setFlash('success', $config['label'] . ' record deleted successfully');
    redirect(savingsTrackerUrl($cat));
}

// ---------------------------------------------------------------- form save
if (!$isSpecialTab && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['cat'] ?? '') === $cat) {
    $editId = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : null;
    $data = [];
    $missingRequired = false;
    foreach ($config['fields'] as $field) {
        // A readonly field (see the transaction-ledger engine,
        // savings_ledger_helper.php) is never written from the form -- its
        // value only ever comes from recompute*() below, exactly like the
        // desktop app's FieldEditDialog skips readonly fields when building
        // the values dict to save. Left out of $data entirely so an
        // INSERT falls back to the column's own DB DEFAULT 0 and an UPDATE
        // leaves the column untouched either way.
        if (!empty($field['readonly'])) {
            continue;
        }
        $raw = $_POST[$field['name']] ?? '';
        if ($field['type'] === 'number') {
            $data[$field['name']] = ($raw === '' ? null : (float)$raw);
        } else {
            $data[$field['name']] = sanitize($raw);
        }
        if (!empty($field['required']) && ($data[$field['name']] === null || $data[$field['name']] === '')) {
            $missingRequired = true;
        }
    }

    if ($missingRequired) {
        setFlash('error', 'Please fill in all required fields.');
    } else {
        $columns = array_keys($data);
        $titleValue = $data[$config['title_field']] ?? '';
        if ($editId) {
            $setClause = implode(', ', array_map(fn($c) => sprintf('`%s` = ?', $c), $columns));
            $db->update(
                sprintf('UPDATE `%s` SET %s WHERE id = ? AND user_id = ?', $table, $setClause),
                array_merge(array_values($data), [$editId, $user['id']])
            );
            $recordId = $editId;
            saveCustomFieldEntries($user['id'], $table, $editId);
            $db->insert(
                "INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
                [$user['id'], 'update', 'Updated ' . $config['label'] . ': ' . $titleValue, $_SERVER['REMOTE_ADDR'] ?? '']
            );
            setFlash('success', $config['label'] . ' updated successfully');
        } else {
            $colList = implode(', ', array_map(fn($c) => sprintf('`%s`', $c), $columns));
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $recordId = $db->insert(
                sprintf('INSERT INTO `%s` (user_id, %s) VALUES (?, %s)', $table, $colList, $placeholders),
                array_merge([$user['id']], array_values($data))
            );
            saveCustomFieldEntries($user['id'], $table, $recordId);
            $db->insert(
                "INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
                [$user['id'], 'create', 'Added ' . $config['label'] . ': ' . $titleValue, $_SERVER['REMOTE_ADDR'] ?? '']
            );
            setFlash('success', $config['label'] . ' added successfully');
        }

        // A saved opening_* seed value only takes effect once the derived
        // field is recomputed -- same as the desktop app's after_save hooks
        // (recompute_bank_balance()/recompute_ledger_totals()), called
        // unconditionally on every save, not just when a transaction changes.
        if (($config['ledger'] ?? false) === 'bank') {
            recomputeBankBalance($db, $user['id'], (int)$recordId);
        } elseif (($config['ledger'] ?? false) === true) {
            recomputeLedgerTotals($db, $user['id'], $table, (int)$recordId);
        }

        redirect(savingsTrackerUrl($cat));
    }
}

// --------------------------------------------------------------------- list
if (!$isSpecialTab) {
    $search = sanitize($_GET['search'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 12;
    $offset = ($page - 1) * $perPage;

    $where = "WHERE user_id = ?";
    $params = [$user['id']];
    if ($search) {
        $searchCols = array_column($config['fields'], 'name');
        $like = array_map(fn($c) => sprintf('`%s` LIKE ?', $c), $searchCols);
        $where .= " AND (" . implode(' OR ', $like) . ")";
        foreach ($searchCols as $_c) {
            $params[] = "%$search%";
        }
    }

    $totalCount = $db->fetch(sprintf('SELECT COUNT(*) as count FROM `%s` %s', $table, $where), $params);
    $totalPages = max(1, ceil($totalCount['count'] / $perPage));
    $rows = $db->fetchAll(
        sprintf('SELECT * FROM `%s` %s ORDER BY %s LIMIT %d OFFSET %d', $table, $where, $config['order_by'], $perPage, $offset),
        $params
    );

    $editData = null;
    $editCustomValues = [];
    if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
        $editData = $db->fetch(sprintf('SELECT * FROM `%s` WHERE id = ? AND user_id = ?', $table), [(int)$_GET['edit'], $user['id']]);
        if ($editData) {
            $editCustomValues = getCustomFieldValues($table, $editData['id']);
        }
    }
    $showForm = (isset($_GET['action']) && $_GET['action'] === 'add') || $editData;
    $customFields = getCustomFieldsFor($table, $user['id']);
}

// -------------------------------------------------------- special tab data
if ($cat === '__dashboard__') {
    $summary = savingsSummaryTotals($db, $user['id']);
    $categoryBreakdown = savingsCategoryBreakdown($db, $user['id']);
    $monthlyForChart = savingsPeriodSummary($db, $user['id'], 7);
} elseif ($cat === '__daily__') {
    $dailySummary = savingsPeriodSummary($db, $user['id'], 10);
} elseif ($cat === '__monthly__') {
    $monthlySummary = savingsPeriodSummary($db, $user['id'], 7);
}
?>

<!-- Category tabs -->
<div class="toolbar" style="flex-wrap: wrap; gap: 8px;">
    <?php foreach ($SAVINGS_SPECIAL_TABS as $key => $c): if ($key === '__custom__') continue; ?>
        <a href="<?php echo url('savingsmanage.php?cat=' . urlencode($key)); ?>"
           class="btn <?php echo $key === $cat ? 'btn-primary' : 'btn-ghost'; ?> btn-sm">
            <i class="fas <?php echo $c['icon']; ?>"></i> <?php echo htmlspecialchars($c['label']); ?>
        </a>
    <?php endforeach; ?>
    <?php foreach ($CATEGORIES as $key => $c): ?>
        <a href="<?php echo savingsTrackerUrl($key); ?>"
           class="btn <?php echo $key === $cat ? 'btn-primary' : 'btn-ghost'; ?> btn-sm">
            <i class="fas <?php echo $c['icon']; ?>"></i> <?php echo htmlspecialchars($c['label']); ?>
        </a>
    <?php endforeach; ?>
    <a href="<?php echo url('savingsmanage.php?cat=__custom__'); ?>"
       class="btn <?php echo $cat === '__custom__' ? 'btn-primary' : 'btn-ghost'; ?> btn-sm">
        <i class="fas <?php echo $SAVINGS_SPECIAL_TABS['__custom__']['icon']; ?>"></i> <?php echo htmlspecialchars($SAVINGS_SPECIAL_TABS['__custom__']['label']); ?>
    </a>
</div>

<?php if (!$isSpecialTab): ?>
<!-- Toolbar -->
<div class="toolbar">
    <div class="toolbar-left">
        <?php if (!$showForm): ?>
        <div class="search-box" style="width: auto;">
            <i class="fas fa-search"></i>
            <form method="GET" style="display:inline;">
                <input type="hidden" name="cat" value="<?php echo htmlspecialchars($cat); ?>">
                <input type="text" name="search" placeholder="Search <?php echo htmlspecialchars($config['label']); ?>..."
                       value="<?php echo htmlspecialchars($search); ?>" style="width: 250px;">
            </form>
        </div>
        <?php else: ?>
        <a href="<?php echo savingsTrackerUrl($cat); ?>" class="btn btn-ghost">
            <i class="fas fa-arrow-left"></i> Back to List
        </a>
        <?php endif; ?>
    </div>
    <div class="toolbar-right">
        <?php if (!$showForm): ?>
        <a href="<?php echo savingsTrackerUrl($cat, '&action=add'); ?>" class="btn btn-primary">
            <i class="fas fa-plus"></i> Add <?php echo htmlspecialchars($config['label']); ?>
        </a>
        <a href="<?php echo url('custom-fields.php?module=' . urlencode($table)); ?>" class="btn btn-ghost">
            <i class="fas fa-puzzle-piece"></i> Custom Fields
        </a>
        <a href="<?php echo url('field-blocks.php?module=' . urlencode($table)); ?>" class="btn btn-ghost">
            <i class="fas fa-th-large"></i> Field Blocks
        </a>
        <a href="<?php echo url('savings-widgets.php?cat=' . urlencode($cat)); ?>" class="btn btn-ghost">
            <i class="fas fa-sliders-h"></i> Widgets &amp; Names
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$showForm): ?>
<!-- Summary widgets (Custom Columns -> Widgets) -->
<?php echo swRenderStrip($db, (int)$user['id'], $cat, $config); ?>
<?php endif; ?>

<?php if ($showForm): ?>
<!-- Add/Edit Form -->
<div class="card animate-fade-in">
    <div class="card-header">
        <h2>
            <i class="fas fa-<?php echo $editData ? 'edit' : 'plus'; ?>" style="margin-right: 8px; color: var(--primary);"></i>
            <?php echo $editData ? 'Edit' : 'Add'; ?> <?php echo htmlspecialchars($config['label']); ?>
        </h2>
    </div>

    <form method="POST" action="<?php echo savingsTrackerUrl($cat); ?>" id="savingsForm">
<?php echo csrf_field(); ?>
        <input type="hidden" name="cat" value="<?php echo htmlspecialchars($cat); ?>">
        <?php if ($editData): ?>
            <input type="hidden" name="edit_id" value="<?php echo $editData['id']; ?>">
        <?php endif; ?>

        <?php foreach ($config['fields'] as $i => $field):
            $value = $editData[$field['name']] ?? '';
            if ($i % 2 === 0) echo '<div class="form-row">';
        ?>
            <div class="form-group">
                <label><?php echo htmlspecialchars($field['label']); ?>
                    <?php if (!empty($field['required'])): ?><span class="required">*</span><?php endif; ?>
                </label>
                <div class="input-wrapper">
                    <?php if ($field['type'] === 'textarea'): ?>
                        <textarea name="<?php echo $field['name']; ?>" rows="3"><?php echo htmlspecialchars($value); ?></textarea>
                    <?php elseif ($field['type'] === 'select'): ?>
                        <select name="<?php echo $field['name']; ?>">
                            <option value="">-- Select --</option>
                            <?php foreach ($field['options'] as $optVal => $optLabel): ?>
                                <option value="<?php echo $optVal; ?>" <?php echo ((string)$value === (string)$optVal) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($optLabel); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="<?php echo $field['type'] === 'number' ? 'number' : ($field['type'] === 'date' ? 'date' : 'text'); ?>"
                               name="<?php echo $field['name']; ?>"
                               step="<?php echo $field['type'] === 'number' ? 'any' : ''; ?>"
                               value="<?php echo htmlspecialchars($value); ?>"
                               <?php echo !empty($field['required']) ? 'required' : ''; ?>
                               <?php echo !empty($field['readonly']) ? 'disabled' : ''; ?>>
                    <?php endif; ?>
                </div>
            </div>
        <?php if ($i % 2 === 1 || $i === count($config['fields']) - 1) echo '</div>'; endforeach; ?>

        <?php renderCustomFields($customFields ?? [], $editCustomValues ?? [], 'Custom Fields', $table, $user['id']); ?>

        <div class="form-actions">
            <a href="<?php echo savingsTrackerUrl($cat); ?>" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> <?php echo $editData ? 'Update' : 'Save'; ?>
            </button>
        </div>
    </form>
</div>

<?php else: ?>
<!-- List -->
<?php if (empty($rows)): ?>
    <div class="empty-state card">
        <i class="fas <?php echo $config['icon']; ?>"></i>
        <h3>No <?php echo htmlspecialchars($config['label']); ?> yet</h3>
        <p>Start by adding your first record</p>
        <a href="<?php echo savingsTrackerUrl($cat, '&action=add'); ?>" class="btn btn-primary">
            <i class="fas fa-plus"></i> Add <?php echo htmlspecialchars($config['label']); ?>
        </a>
    </div>
<?php else: ?>
    <div class="details-grid">
        <?php foreach ($rows as $row):
            $color = randomColor();
            $isLedger = !empty($config['ledger']);
        ?>
        <div class="detail-card animate-fade-in">
            <div class="card-menu">
                <div style="display: flex; gap: 4px; flex-wrap: wrap; justify-content: flex-end;">
                    <?php if ($isLedger): ?>
                    <a href="<?php echo url('savings-ledger.php?cat=' . urlencode($cat) . '&record=' . $row['id']); ?>" class="btn btn-icon btn-ghost" title="Transactions">
                        <i class="fas fa-list-ol"></i>
                    </a>
                    <?php endif; ?>
                    <a href="<?php echo savingsTrackerUrl($cat, '&edit=' . $row['id']); ?>" class="btn btn-icon btn-ghost" title="Edit">
                        <i class="fas fa-pen"></i>
                    </a>
                    <a href="<?php echo savingsTrackerUrl($cat, '&view=' . $row['id']); ?>" class="btn btn-icon btn-ghost" title="View">
                        <i class="fas fa-eye"></i>
                    </a>
                    <button class="btn btn-icon btn-ghost" title="Delete" style="color: var(--danger);"
                            onclick="showConfirm('Delete Record', 'Are you sure you want to delete this record? This action cannot be undone.', function() { window.location.href='<?php echo savingsTrackerUrl($cat, "&delete=" . $row['id'] . "&csrf=" . urlencode(Auth::generateCSRF())); ?>'; })">
                        <i class="fas fa-trash"></i>
                    </button>
                    <?php renderActionButtonsInline($table, $row['id'], $user['id']); ?>
                </div>
            </div>

            <div class="detail-card-header">
                <div class="detail-avatar" style="background: <?php echo $color; ?>">
                    <i class="fas <?php echo $config['icon']; ?>"></i>
                </div>
                <div>
                    <div class="detail-name"><?php echo htmlspecialchars($row[$config['title_field']] ?? ''); ?></div>
                    <div class="detail-email"><?php echo htmlspecialchars($config['label']); ?></div>
                </div>
            </div>

            <div class="detail-info">
                <?php
                $shown = 0;
                foreach ($config['fields'] as $field):
                    if ($shown >= 4 || $field['name'] === $config['title_field'] || $row[$field['name']] === null || $row[$field['name']] === '') continue;
                    $display = $row[$field['name']];
                    if ($field['type'] === 'select' && isset($field['options'][$display])) {
                        $display = $field['options'][$display];
                    }
                    $shown++;
                ?>
                <div class="detail-info-item">
                    <span class="label"><?php echo htmlspecialchars($field['label']); ?></span>
                    <span class="value"><?php echo htmlspecialchars((string)$display); ?></span>
                </div>
                <?php endforeach; ?>
                <div class="detail-info-item">
                    <span class="label">Updated</span>
                    <span class="value"><?php echo timeAgo($row['updated_at']); ?></span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
        <a href="?cat=<?php echo urlencode($cat); ?>&page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>" class="pagination-btn">
            <i class="fas fa-chevron-left"></i>
        </a>
        <?php endif; ?>
        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
        <a href="?cat=<?php echo urlencode($cat); ?>&page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>"
           class="pagination-btn <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
        <a href="?cat=<?php echo urlencode($cat); ?>&page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>" class="pagination-btn">
            <i class="fas fa-chevron-right"></i>
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>
<?php endif; ?>

<?php elseif ($cat === '__dashboard__'): ?>
<!-- ============================================================ Dashboard -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon purple"><i class="fas fa-wallet"></i></div>
        <div class="stat-info">
            <h3>&#8377;<?php echo number_format($summary['total_savings'], 0); ?></h3>
            <p>Total Savings</p>
            <div class="stat-trend up">Flat savings categories</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-chart-line"></i></div>
        <div class="stat-info">
            <h3>&#8377;<?php echo number_format($summary['market_invested'], 0); ?></h3>
            <p>Market Invested</p>
            <div class="stat-trend up">Principal amount</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-coins"></i></div>
        <div class="stat-info">
            <h3>&#8377;<?php echo number_format($summary['market_value'], 0); ?></h3>
            <p>Market Value</p>
            <div class="stat-trend up">Current total value</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon <?php echo $summary['market_gain_loss'] >= 0 ? 'green' : 'red'; ?>">
            <i class="fas fa-<?php echo $summary['market_gain_loss'] >= 0 ? 'arrow-trend-up' : 'arrow-trend-down'; ?>"></i>
        </div>
        <div class="stat-info">
            <h3 style="color: <?php echo $summary['market_gain_loss'] >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;">
                <?php echo $summary['market_gain_loss'] >= 0 ? '+' : ''; ?>&#8377;<?php echo number_format($summary['market_gain_loss'], 0); ?>
            </h3>
            <p>Market Gain/Loss</p>
            <div class="stat-trend <?php echo $summary['market_gain_loss'] >= 0 ? 'up' : 'down'; ?>">Invested vs current value</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon red"><i class="fas fa-file-invoice-dollar"></i></div>
        <div class="stat-info">
            <h3>&#8377;<?php echo number_format($summary['total_liabilities'], 0); ?></h3>
            <p>Total Liabilities</p>
            <div class="stat-trend down">Loans + Credit Cards</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon <?php echo $summary['net_worth'] >= 0 ? 'purple' : 'red'; ?>"><i class="fas fa-scale-balanced"></i></div>
        <div class="stat-info">
            <h3 style="color: <?php echo $summary['net_worth'] >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;">
                &#8377;<?php echo number_format($summary['net_worth'], 0); ?>
            </h3>
            <p>Net Worth</p>
            <div class="stat-trend <?php echo $summary['net_worth'] >= 0 ? 'up' : 'down'; ?>">Savings + market value − liabilities</div>
        </div>
    </div>
</div>

<div class="dashboard-grid">
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-chart-pie" style="margin-right: 8px; color: var(--primary);"></i> Category Breakdown</h2>
        </div>
        <?php if (empty($categoryBreakdown)): ?>
        <div class="empty-state">
            <i class="fas fa-piggy-bank"></i>
            <h3>No savings yet</h3>
            <p>Start by adding a savings record.</p>
        </div>
        <?php else: ?>
        <div class="table-container" style="border: none;">
            <table class="data-table">
                <thead><tr><th>Category</th><th style="text-align: right;">Value</th></tr></thead>
                <tbody>
                    <?php foreach ($categoryBreakdown as $row): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($row['category']); ?></strong></td>
                        <td style="text-align: right; color: <?php echo $row['value'] < 0 ? 'var(--danger)' : 'var(--text-primary)'; ?>;">
                            <?php echo $row['value'] < 0 ? '-' : ''; ?>&#8377;<?php echo number_format(abs($row['value']), 0); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-chart-line" style="margin-right: 8px; color: var(--success);"></i> Cumulative Trend (by month)</h2>
        </div>
        <?php echo renderSavingsTrendChartSvg($monthlyForChart); ?>
    </div>
</div>

<?php elseif ($cat === '__daily__'): ?>
<!-- ============================================================ Daily Summary -->
<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-calendar-day" style="margin-right: 8px; color: var(--primary);"></i> Daily Summary</h2>
    </div>
    <?php if (empty($dailySummary)): ?>
    <div class="empty-state">
        <i class="fas fa-chart-line"></i>
        <h3>No daily data yet</h3>
    </div>
    <?php else: ?>
    <div class="table-container" style="border: none;">
        <table class="data-table">
            <thead><tr><th>Date</th><th style="text-align: right;">Total</th><th style="text-align: right;">Cumulative</th></tr></thead>
            <tbody>
                <?php foreach (array_reverse($dailySummary) as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['period']); ?></td>
                    <td style="text-align: right;">&#8377;<?php echo number_format($row['total'], 0); ?></td>
                    <td style="text-align: right;">&#8377;<?php echo number_format($row['cumulative'], 0); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($cat === '__monthly__'): ?>
<!-- ============================================================ Monthly Summary -->
<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-calendar-alt" style="margin-right: 8px; color: var(--success);"></i> Monthly Summary</h2>
    </div>
    <?php if (empty($monthlySummary)): ?>
    <div class="empty-state">
        <i class="fas fa-chart-area"></i>
        <h3>No monthly data yet</h3>
    </div>
    <?php else: ?>
    <div class="table-container" style="border: none;">
        <table class="data-table">
            <thead><tr><th>Month</th><th style="text-align: right;">Total</th><th style="text-align: right;">Cumulative</th></tr></thead>
            <tbody>
                <?php foreach (array_reverse($monthlySummary) as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['period']); ?></td>
                    <td style="text-align: right;">&#8377;<?php echo number_format($row['total'], 0); ?></td>
                    <td style="text-align: right;">&#8377;<?php echo number_format($row['cumulative'], 0); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($cat === '__custom__'): ?>
<!-- ============================================================ Custom Columns -->
<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-puzzle-piece" style="margin-right: 8px; color: var(--primary);"></i> Custom Columns</h2>
        <p style="margin: 8px 0 0; color: var(--text-secondary); font-size: 0.9rem;">
            Define extra per-category fields (and Field Blocks) for any Savings Tracker category, plus the
            transaction ledgers -- same custom-fields engine every other module uses.
        </p>
    </div>
    <div class="details-grid">
        <?php foreach ($CATEGORIES as $key => $c): ?>
        <div class="detail-card">
            <div class="detail-card-header">
                <div class="detail-avatar" style="background: <?php echo randomColor(); ?>">
                    <i class="fas <?php echo $c['icon']; ?>"></i>
                </div>
                <div>
                    <div class="detail-name"><?php echo htmlspecialchars($c['label']); ?></div>
                </div>
            </div>
            <div style="display: flex; gap: 8px; margin-top: 14px; flex-wrap: wrap;">
                <a href="<?php echo url('custom-fields.php?module=' . urlencode($c['table'])); ?>" class="btn btn-ghost btn-sm">
                    <i class="fas fa-puzzle-piece"></i> Fields
                </a>
                <a href="<?php echo url('field-blocks.php?module=' . urlencode($c['table'])); ?>" class="btn btn-ghost btn-sm">
                    <i class="fas fa-th-large"></i> Blocks
                </a>
                <a href="<?php echo url('savings-widgets.php?cat=' . urlencode($key)); ?>" class="btn btn-ghost btn-sm">
                    <i class="fas fa-sliders-h"></i> Widgets
                </a>
                <a href="<?php echo url('savings-widgets.php?cat=' . urlencode($key) . '#rename'); ?>" class="btn btn-ghost btn-sm">
                    <i class="fas fa-i-cursor"></i> Rename Columns
                </a>
                <?php if (!empty($c['ledger']) && $c['ledger'] !== 'bank'): ?>
                <!-- This category's own transaction fields (shown on its ledger only). -->
                <a href="<?php echo url('custom-fields.php?module=' . urlencode(savingsLedgerModule($c['table']))); ?>" class="btn btn-ghost btn-sm">
                    <i class="fas fa-list-ol"></i> Ledger Fields
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <div class="detail-card">
            <div class="detail-card-header">
                <div class="detail-avatar" style="background: linear-gradient(135deg, #e67e22, #d35400);">
                    <i class="fas fa-university"></i>
                </div>
                <div>
                    <div class="detail-name">Bank Transactions</div>
                </div>
            </div>
            <div style="display: flex; gap: 8px; margin-top: 14px;">
                <a href="<?php echo url('custom-fields.php?module=savings_bank_transactions'); ?>" class="btn btn-ghost btn-sm">
                    <i class="fas fa-puzzle-piece"></i> Fields
                </a>
                <a href="<?php echo url('field-blocks.php?module=savings_bank_transactions'); ?>" class="btn btn-ghost btn-sm">
                    <i class="fas fa-th-large"></i> Blocks
                </a>
            </div>
        </div>
        <div class="detail-card">
            <div class="detail-card-header">
                <div class="detail-avatar" style="background: linear-gradient(135deg, #e67e22, #d35400);">
                    <i class="fas fa-list-ol"></i>
                </div>
                <div>
                    <div class="detail-name">Ledger Transactions (all modules)</div>
                    <div style="color: var(--text-secondary); font-size: 0.8rem;">Older fields shown on every ledger -- use a category's "Ledger Fields" for one category only.</div>
                </div>
            </div>
            <div style="display: flex; gap: 8px; margin-top: 14px;">
                <a href="<?php echo url('custom-fields.php?module=savings_transactions'); ?>" class="btn btn-ghost btn-sm">
                    <i class="fas fa-puzzle-piece"></i> Fields
                </a>
                <a href="<?php echo url('field-blocks.php?module=savings_transactions'); ?>" class="btn btn-ghost btn-sm">
                    <i class="fas fa-th-large"></i> Blocks
                </a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- View Detail Modal -->
<?php
if (!$isSpecialTab && isset($_GET['view']) && is_numeric($_GET['view'])) {
    $viewData = $db->fetch(sprintf('SELECT * FROM `%s` WHERE id = ? AND user_id = ?', $table), [(int)$_GET['view'], $user['id']]);
    if ($viewData) {
?>
<div class="modal view-modal active" id="viewModal">
    <div class="modal-header">
        <h3><i class="fas fa-eye" style="margin-right: 8px; color: var(--primary);"></i> View Details</h3>
        <a href="<?php echo savingsTrackerUrl($cat); ?>" class="modal-close"><i class="fas fa-times"></i></a>
    </div>
    <div class="modal-body" style="padding: 24px 28px;">
        <div style="text-align: center; margin-bottom: 24px;">
            <div class="detail-avatar" style="background: <?php echo randomColor(); ?>; width: 64px; height: 64px; font-size: 1.4rem; margin: 0 auto 12px;">
                <i class="fas <?php echo $config['icon']; ?>"></i>
            </div>
            <h2><?php echo htmlspecialchars($viewData[$config['title_field']] ?? ''); ?></h2>
        </div>

        <div class="preview-section">
            <div class="preview-section-title"><i class="fas fa-info-circle"></i> Details</div>
            <div class="preview-fields-grid">
                <?php foreach ($config['fields'] as $field):
                    $value = $viewData[$field['name']];
                    if ($field['type'] === 'select' && isset($field['options'][$value])) {
                        $value = $field['options'][$value];
                    }
                ?>
                <div class="preview-field<?php echo ($value && (strlen((string)$value) > 60 || $field['type'] === 'textarea')) ? ' full-width' : ''; ?>">
                    <div class="preview-field-label"><?php echo htmlspecialchars($field['label']); ?></div>
                    <div class="preview-field-value<?php echo !$value ? ' empty' : ''; ?>">
                        <?php echo $value ? htmlspecialchars((string)$value) : 'Not provided'; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php renderCustomFieldValues($table, $viewData['id']); ?>
    </div>
    <div class="modal-footer">
        <a href="<?php echo savingsTrackerUrl($cat, '&edit=' . $viewData['id']); ?>" class="btn btn-primary btn-sm">
            <i class="fas fa-edit"></i> Edit
        </a>
        <a href="<?php echo savingsTrackerUrl($cat); ?>" class="btn btn-secondary btn-sm">Close</a>
    </div>
</div>
<script>document.getElementById('modalOverlay').classList.add('active');</script>
<?php } } ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
