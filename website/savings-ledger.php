<?php
/**
 * Transactions ledger for one Savings Tracker record -- the website's
 * counterpart to app/ui/savings_ledger_dialog.py's LedgerTransactionsDialog
 * and app/ui/savings_tracker_view.py's BankTransactionsDialog. One page
 * handles both: Bank Accounts uses its own dedicated table
 * (savings_bank_transactions) with a fixed deposit/withdrawal type set;
 * every other ledger-eligible category shares savings_transactions,
 * config-driven by $SAVINGS_LEDGER_CONFIG (savings_ledger_helper.php) for
 * its type set and which field(s) get recomputed.
 */
$pageTitle = 'Transactions';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/custom_fields_helper.php';
require_once __DIR__ . '/includes/savings_helper.php';
require_once __DIR__ . '/includes/savings_ledger_helper.php';
$user = requireAuth();
$db = Database::getInstance();
ensureSavingsTables($db);

$cat = $_GET['cat'] ?? '';
if (!isset($SAVINGS_CATEGORIES[$cat]) || empty($SAVINGS_CATEGORIES[$cat]['ledger'])) {
    http_response_code(400);
    exit('Invalid category.');
}
$config = $SAVINGS_CATEGORIES[$cat];
$table = $config['table'];
$isBank = $config['ledger'] === 'bank';
$isLog = $config['ledger'] === 'log';
$ledgerConfig = $isBank || $isLog ? null : savingsLedgerConfigFor($table);

$recordId = (int)($_GET['record'] ?? 0);
$record = $db->fetch(sprintf('SELECT * FROM `%s` WHERE id = ? AND user_id = ?', $table), [$recordId, $user['id']]);
if (!$record) {
    http_response_code(404);
    exit('Record not found.');
}
$recordName = $record[$config['title_field']] ?? '';

function ledgerUrl(string $cat, int $recordId, string $extra = ''): string {
    return url('savings-ledger.php?cat=' . urlencode($cat) . '&record=' . $recordId . $extra);
}

// Fixed type set for Bank Accounts; category-specific for the shared ledger;
// 'payment' only for the "log" kind (Insurance Policies -- history, no recompute).
// Custom fields: Bank Accounts has its own module; every other category has
// its own ledger module first, then the shared "all modules" one.
if ($isBank) {
    $typeLabels = ['deposit' => 'Deposit', 'withdrawal' => 'Withdrawal'];
    $ledgerModules = ['savings_bank_transactions'];
} elseif ($isLog) {
    $typeLabels = ['payment' => 'Premium Payment'];
    $ledgerModules = savingsLedgerFieldModules($table);
} else {
    $typeLabels = $ledgerConfig['type_labels'];
    $ledgerModules = savingsLedgerFieldModules($table);
}
$ledgerModule = $ledgerModules[0];
$needsUnits = !$isBank && !$isLog && $ledgerConfig['kind'] === 'buy_sell';
$colLabels = $isBank ? SAVINGS_LEDGER_DEFAULT_LABELS : savingsLedgerColumnLabels($db, (int)$user['id'], $table);

// --------------------------------------------------------- column labels
if (!$isBank && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'column_labels') {
    if (!Auth::validateCSRF($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Your session expired. Please try again.');
    } else {
        $posted = [];
        foreach (array_keys(SAVINGS_LEDGER_DEFAULT_LABELS) as $column) {
            if (isset($_POST['label_' . $column])) {
                $posted[$column] = sanitize($_POST['label_' . $column]);
            }
        }
        saveSavingsLedgerColumnLabels($db, (int)$user['id'], $table, $posted);
        setFlash('success', 'Column labels saved.');
    }
    redirect(ledgerUrl($cat, $recordId));
}

// ------------------------------------------------------------------- delete
if (isset($_GET['delete']) && is_numeric($_GET['delete']) && Auth::validateCSRF($_GET['csrf'] ?? '')) {
    $txnId = (int)$_GET['delete'];
    if ($isBank) {
        deleteBankTransaction($db, $user['id'], $txnId);
    } else {
        deleteLedgerTransaction($db, $user['id'], $txnId);
    }
    setFlash('success', 'Transaction deleted.');
    redirect(ledgerUrl($cat, $recordId));
}

// --------------------------------------------------------------- form save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['record_id'] ?? '') == $recordId) {
    $editId = isset($_POST['edit_id']) && $_POST['edit_id'] !== '' ? (int)$_POST['edit_id'] : null;
    $date = sanitize($_POST['date'] ?? '');
    $description = sanitize($_POST['description'] ?? '');
    $type = sanitize($_POST['type'] ?? '');
    $amountRaw = trim($_POST['amount'] ?? '');
    $unitsRaw = trim($_POST['units'] ?? '');

    if (empty($date) || !isset($typeLabels[$type]) || $amountRaw === '' || (float)$amountRaw <= 0) {
        setFlash('error', $colLabels['date'] . ', ' . $colLabels['type'] . ', and a positive ' . $colLabels['amount'] . ' are required.');
    } else {
        $values = [
            'date' => $date,
            'description' => $description,
            'type' => $type,
            'amount' => (float)$amountRaw,
        ];
        if ($isBank) {
            $values['bank_account_id'] = $recordId;
            if ($editId) {
                updateBankTransaction($db, $user['id'], $editId, $values);
            } else {
                $editId = createBankTransaction($db, $user['id'], $values);
            }
        } else {
            $values['record_id'] = $recordId;
            $values['units'] = ($needsUnits && $unitsRaw !== '') ? (float)$unitsRaw : null;
            if ($editId) {
                updateLedgerTransaction($db, $user['id'], $editId, $values);
            } else {
                $editId = createLedgerTransaction($db, $user['id'], $table, $values);
            }
        }
        foreach ($ledgerModules as $module) {
            saveCustomFieldEntries($user['id'], $module, $editId);
        }
        setFlash('success', 'Transaction saved.');
        redirect(ledgerUrl($cat, $recordId));
    }
}

$transactions = $isBank
    ? getBankTransactions($db, $user['id'], $recordId)
    : getLedgerTransactions($db, $user['id'], $table, $recordId);

// [module => fields] -- this ledger's own fields, then the shared ones.
$customFieldsByModule = [];
foreach ($ledgerModules as $module) {
    $customFieldsByModule[$module] = getCustomFieldsFor($module, $user['id']);
}
$editData = null;
$editCustomValues = [];
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    foreach ($transactions as $t) {
        if ((int)$t['id'] === (int)$_GET['edit']) { $editData = $t; break; }
    }
    if ($editData) {
        foreach ($ledgerModules as $module) {
            $editCustomValues[$module] = getCustomFieldValues($module, $editData['id']);
        }
    }
}
// "Show in List" fields as extra table columns, with their values.
$listFields = [];
$listValues = [];
foreach ($customFieldsByModule as $module => $fields) {
    foreach ($fields as $f) {
        if (!empty($f['show_in_list'])) $listFields[] = ['module' => $module, 'field' => $f];
    }
}
if ($listFields && $transactions) {
    $txnIds = array_map(fn($t) => (int)$t['id'], $transactions);
    $placeholders = implode(',', array_fill(0, count($txnIds), '?'));
    foreach ($ledgerModules as $module) {
        foreach ($db->fetchAll(
            "SELECT record_id, custom_field_id, field_value FROM custom_field_entries
             WHERE user_id = ? AND linked_to = ? AND block_instance = 0 AND record_id IN ($placeholders)",
            [$user['id'], $module, ...$txnIds]
        ) as $row) {
            $listValues[(int)$row['record_id']][(int)$row['custom_field_id']] = $row['field_value'];
        }
    }
}
$showForm = (isset($_GET['action']) && $_GET['action'] === 'add') || $editData;

// Re-fetch the record for a fresh derived-total display after any save above.
$record = $db->fetch(sprintf('SELECT * FROM `%s` WHERE id = ? AND user_id = ?', $table), [$recordId, $user['id']]);
?>

<div class="toolbar">
    <div class="toolbar-left">
        <a href="<?php echo savingsTrackerUrl($cat); ?>" class="btn btn-ghost">
            <i class="fas fa-arrow-left"></i> Back to <?php echo htmlspecialchars($config['label']); ?>
        </a>
    </div>
    <div class="toolbar-right">
        <?php if (!$showForm): ?>
        <?php if (!$isBank): ?>
        <a href="<?php echo url('custom-fields.php?module=' . urlencode($ledgerModule)); ?>" class="btn btn-ghost">
            <i class="fas fa-puzzle-piece"></i> Ledger Fields
        </a>
        <a href="<?php echo ledgerUrl($cat, $recordId, '&action=labels'); ?>" class="btn btn-ghost">
            <i class="fas fa-tag"></i> Column Labels
        </a>
        <?php endif; ?>
        <a href="<?php echo ledgerUrl($cat, $recordId, '&action=add'); ?>" class="btn btn-primary">
            <i class="fas fa-plus"></i> Add Transaction
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="card animate-fade-in" style="margin-bottom: 24px;">
    <div class="card-header" style="display: flex; align-items: center; justify-content: space-between;">
        <h2><i class="fas fa-list-ol" style="margin-right: 8px; color: var(--primary);"></i> <?php echo htmlspecialchars($recordName); ?></h2>
        <?php if (!$isLog):
            $balanceLabel = 'Balance';
            $balanceValue = null;
            if ($isBank) {
                $balanceValue = (float)($record['balance'] ?? 0);
            } elseif ($ledgerConfig['kind'] === 'balance') {
                $balanceValue = (float)($record[$ledgerConfig['balance_field']] ?? 0);
            } elseif ($ledgerConfig['kind'] === 'installment') {
                $balanceLabel = (int)($record[$ledgerConfig['count_field']] ?? 0) . ' installment(s), Total';
                $balanceValue = (float)($record[$ledgerConfig['total_field']] ?? 0);
            } elseif ($ledgerConfig['kind'] === 'buy_sell') {
                $balanceLabel = 'Invested (' . number_format((float)($record[$ledgerConfig['units_field']] ?? 0), 4) . ' units)';
                $balanceValue = (float)($record[$ledgerConfig['invested_field']] ?? 0);
            }
        ?>
        <span style="font-size: 1.1rem; font-weight: 700; color: var(--primary-light);">
            <?php echo htmlspecialchars($balanceLabel); ?>: &#8377;<?php echo number_format($balanceValue, 2); ?>
        </span>
        <?php endif; ?>
    </div>
</div>

<?php if (!$isBank && ($_GET['action'] ?? '') === 'labels'): ?>
<!-- Rename this ledger's built-in columns -->
<div class="card animate-fade-in">
    <div class="card-header">
        <h2><i class="fas fa-tag" style="margin-right: 8px; color: var(--primary);"></i> Column Labels — <?php echo htmlspecialchars($config['label']); ?></h2>
        <p style="margin: 8px 0 0; color: var(--text-secondary); font-size: 0.9rem;">
            Rename the built-in columns of this ledger. Leave a box empty to use the default name.
            The names also show in the desktop app's Custom Columns and Calculations.
        </p>
    </div>
    <form method="POST" action="<?php echo ledgerUrl($cat, $recordId); ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="column_labels">
        <?php foreach (SAVINGS_LEDGER_DEFAULT_LABELS as $column => $default):
            if ($column === 'units' && !$needsUnits) continue; ?>
        <div class="form-group">
            <label><?php echo htmlspecialchars($default); ?></label>
            <div class="input-wrapper">
                <input type="text" name="label_<?php echo $column; ?>" maxlength="100" placeholder="<?php echo htmlspecialchars($default); ?>"
                       value="<?php echo htmlspecialchars($colLabels[$column] !== $default ? $colLabels[$column] : ''); ?>">
                <i class="fas fa-tag"></i>
            </div>
        </div>
        <?php endforeach; ?>
        <div class="form-actions">
            <a href="<?php echo ledgerUrl($cat, $recordId); ?>" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
        </div>
    </form>
</div>

<?php elseif ($showForm): ?>
<!-- Add/Edit Transaction -->
<div class="card animate-fade-in">
    <div class="card-header">
        <h2>
            <i class="fas fa-<?php echo $editData ? 'edit' : 'plus'; ?>" style="margin-right: 8px; color: var(--primary);"></i>
            <?php echo $editData ? 'Edit' : 'Add'; ?> Transaction
        </h2>
    </div>
    <form method="POST" action="<?php echo ledgerUrl($cat, $recordId); ?>" id="ledgerTxnForm">
<?php echo csrf_field(); ?>
        <input type="hidden" name="record_id" value="<?php echo $recordId; ?>">
        <?php if ($editData): ?>
            <input type="hidden" name="edit_id" value="<?php echo $editData['id']; ?>">
        <?php endif; ?>

        <div class="form-row">
            <div class="form-group">
                <label><?php echo htmlspecialchars($colLabels['date']); ?> <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="date" name="date" required value="<?php echo htmlspecialchars($editData['date'] ?? date('Y-m-d')); ?>">
                    <i class="fas fa-calendar"></i>
                </div>
            </div>
            <div class="form-group">
                <label><?php echo htmlspecialchars($colLabels['type']); ?> <span class="required">*</span></label>
                <div class="input-wrapper">
                    <select name="type" <?php echo count($typeLabels) <= 1 ? '' : 'required'; ?>>
                        <?php foreach ($typeLabels as $val => $label): ?>
                        <option value="<?php echo $val; ?>" <?php echo ($editData['type'] ?? array_key_first($typeLabels)) === $val ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($label); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <i class="fas fa-rotate"></i>
                </div>
            </div>
        </div>

        <div class="form-group">
            <label><?php echo htmlspecialchars($colLabels['description']); ?></label>
            <div class="input-wrapper">
                <input type="text" name="description" value="<?php echo htmlspecialchars($editData['description'] ?? ''); ?>">
                <i class="fas fa-align-left"></i>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label><?php echo htmlspecialchars($colLabels['amount']); ?> <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="number" step="any" name="amount" required min="0.01"
                           value="<?php echo htmlspecialchars($editData['amount'] ?? ''); ?>">
                    <i class="fas fa-rupee-sign"></i>
                </div>
            </div>
            <?php if ($needsUnits): ?>
            <div class="form-group">
                <label><?php echo htmlspecialchars($colLabels['units']); ?></label>
                <div class="input-wrapper">
                    <input type="number" step="any" name="units" value="<?php echo htmlspecialchars($editData['units'] ?? ''); ?>">
                    <i class="fas fa-layer-group"></i>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php foreach ($customFieldsByModule as $module => $fields):
            $isShared = $module === SAVINGS_LEDGER_SHARED_MODULE;
            if ($isShared && empty($fields)) continue; // nothing left in the old shared set
            renderCustomFields($fields, $editCustomValues[$module] ?? [],
                $isShared ? 'Custom Fields (all ledgers)' : 'Custom Fields', $module, $user['id']);
        endforeach; ?>

        <div class="form-actions">
            <a href="<?php echo ledgerUrl($cat, $recordId); ?>" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> <?php echo $editData ? 'Update' : 'Save'; ?>
            </button>
        </div>
    </form>
</div>

<?php else: ?>
<!-- Transactions List -->
<?php if (empty($transactions)): ?>
    <div class="empty-state card">
        <i class="fas fa-list-ol"></i>
        <h3>No transactions yet</h3>
        <p>Add the first transaction to start tracking this record's history.</p>
        <a href="<?php echo ledgerUrl($cat, $recordId, '&action=add'); ?>" class="btn btn-primary">
            <i class="fas fa-plus"></i> Add Transaction
        </a>
    </div>
<?php else:
    // Column span used by the injected monthly-subtotal header rows.
    $colCount = 4 + ($needsUnits ? 1 : 0) + count($listFields) + 1;
    // Distinct months present, newest first, for the Month filter.
    $monthsPresent = [];
    foreach ($transactions as $t) {
        $mk = substr((string)$t['date'], 0, 7);
        if ($mk !== '') $monthsPresent[$mk] = date('M Y', strtotime($t['date']));
    }
    // Always offer the current month (even with no transactions) so it can be
    // the default selection below.
    $currentMonthKey = date('Y-m');
    if (!isset($monthsPresent[$currentMonthKey])) $monthsPresent[$currentMonthKey] = date('M Y');
    krsort($monthsPresent);
?>
    <!-- Dashboard summary (recomputed client-side as filters change) -->
    <div class="card" style="margin-bottom: 16px;">
        <div class="stat-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; padding: 16px;">
            <div><div style="color: var(--text-secondary); font-size: 0.8rem;">Transactions</div><div id="statCount" style="font-size: 1.3rem; font-weight: 700;">0</div></div>
            <div><div style="color: var(--text-secondary); font-size: 0.8rem;">Total In</div><div id="statIn" style="font-size: 1.3rem; font-weight: 700; color: var(--success, #2e9e5b);">&#8377;0.00</div></div>
            <div><div style="color: var(--text-secondary); font-size: 0.8rem;">Total Out</div><div id="statOut" style="font-size: 1.3rem; font-weight: 700; color: var(--danger);">&#8377;0.00</div></div>
            <div><div style="color: var(--text-secondary); font-size: 0.8rem;">Net</div><div id="statNet" style="font-size: 1.3rem; font-weight: 700; color: var(--primary-light);">&#8377;0.00</div></div>
        </div>
    </div>

    <!-- Filter toolbar -->
    <div class="card" style="margin-bottom: 16px;">
        <div style="display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; padding:16px;">
            <div class="form-group" style="margin:0;"><label>Search</label>
                <input type="text" id="fltSearch" placeholder="Description / type"></div>
            <div class="form-group" style="margin:0;"><label><?php echo htmlspecialchars($colLabels['type']); ?></label>
                <select id="fltType"><option value="">All Types</option>
                    <?php foreach ($typeLabels as $val => $label): ?><option value="<?php echo htmlspecialchars($label); ?>"><?php echo htmlspecialchars($label); ?></option><?php endforeach; ?>
                </select></div>
            <div class="form-group" style="margin:0;"><label>Month</label>
                <select id="fltMonth"><option value="">All Months</option>
                    <?php foreach ($monthsPresent as $mk => $mlabel): ?><option value="<?php echo htmlspecialchars($mk); ?>" <?php echo $mk === $currentMonthKey ? 'selected' : ''; ?>><?php echo htmlspecialchars($mlabel); ?></option><?php endforeach; ?>
                </select></div>
            <div class="form-group" style="margin:0;"><label>From</label><input type="date" id="fltFrom"></div>
            <div class="form-group" style="margin:0;"><label>To</label><input type="date" id="fltTo"></div>
            <div class="form-group" style="margin:0;"><label>Min <?php echo htmlspecialchars($colLabels['amount']); ?></label><input type="number" step="any" id="fltMin" style="width:100px;"></div>
            <div class="form-group" style="margin:0;"><label>Max <?php echo htmlspecialchars($colLabels['amount']); ?></label><input type="number" step="any" id="fltMax" style="width:100px;"></div>
            <label style="display:flex; align-items:center; gap:6px; margin:0 0 6px;"><input type="checkbox" id="fltGroup"> Group by month</label>
            <button type="button" class="btn btn-ghost" id="fltClear"><i class="fas fa-times"></i> Clear</button>
            <button type="button" class="btn btn-ghost" id="fltExport"><i class="fas fa-file-csv"></i> Export CSV</button>
            <button type="button" class="btn btn-ghost" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
        </div>
    </div>

    <div class="card">
        <div class="table-container" style="border: none;">
            <table class="data-table" id="ledgerTable" data-cols="<?php echo $colCount; ?>">
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars($colLabels['date']); ?></th>
                        <th><?php echo htmlspecialchars($colLabels['description']); ?></th>
                        <th><?php echo htmlspecialchars($colLabels['type']); ?></th>
                        <th style="text-align: right;"><?php echo htmlspecialchars($colLabels['amount']); ?></th>
                        <?php if ($needsUnits): ?><th style="text-align: right;"><?php echo htmlspecialchars($colLabels['units']); ?></th><?php endif; ?>
                        <?php foreach ($listFields as $lf): ?><th><?php echo htmlspecialchars($lf['field']['field_label']); ?></th><?php endforeach; ?>
                        <th style="width: 100px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="ledgerBody">
                    <?php foreach ($transactions as $t):
                        $isOut = ($t['type'] === 'withdrawal' || $t['type'] === 'sell');
                        $sign = $isOut ? '-' : '+';
                    ?>
                    <tr class="txn-row"
                        data-date="<?php echo htmlspecialchars($t['date']); ?>"
                        data-month="<?php echo htmlspecialchars(substr((string)$t['date'], 0, 7)); ?>"
                        data-type="<?php echo htmlspecialchars($typeLabels[$t['type']] ?? ucfirst($t['type'])); ?>"
                        data-desc="<?php echo htmlspecialchars(strtolower((string)$t['description'])); ?>"
                        data-amount="<?php echo htmlspecialchars((string)$t['amount']); ?>"
                        data-out="<?php echo $isOut ? '1' : '0'; ?>">
                        <td><?php echo htmlspecialchars($t['date']); ?></td>
                        <td><?php echo htmlspecialchars($t['description'] ?: '-'); ?></td>
                        <td><?php echo htmlspecialchars($typeLabels[$t['type']] ?? ucfirst($t['type'])); ?></td>
                        <td style="text-align: right; font-family: monospace;"><?php echo $sign; ?>&#8377;<?php echo number_format($t['amount'], 2); ?></td>
                        <?php if ($needsUnits): ?><td style="text-align: right; font-family: monospace;"><?php echo $t['units'] !== null ? number_format($t['units'], 4) : '-'; ?></td><?php endif; ?>
                        <?php foreach ($listFields as $lf):
                            $raw = $listValues[(int)$t['id']][(int)$lf['field']['id']] ?? '';
                            if ($raw !== '' && $lf['field']['field_type'] === 'toggle') $raw = $raw === '1' ? 'Yes' : 'No';
                            if ($raw !== '' && in_array($lf['field']['field_type'], getFileFieldTypes(), true)) {
                                $parts = explode(':', $raw, 3);
                                $raw = count($parts) === 3 ? $parts[2] : $raw;
                            }
                        ?><td><?php echo htmlspecialchars($raw !== '' ? $raw : '-'); ?></td><?php endforeach; ?>
                        <td>
                            <div class="actions">
                                <a href="<?php echo ledgerUrl($cat, $recordId, '&edit=' . $t['id']); ?>" class="btn btn-icon btn-ghost" title="Edit">
                                    <i class="fas fa-pen"></i>
                                </a>
                                <button class="btn btn-icon btn-ghost" title="Delete" style="color: var(--danger);"
                                        onclick="showConfirm('Delete Transaction', 'Delete this transaction?', function() { window.location.href='<?php echo ledgerUrl($cat, $recordId, "&delete=" . $t['id'] . "&csrf=" . urlencode(Auth::generateCSRF())); ?>'; })">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<script>
(function () {
    var table = document.getElementById('ledgerTable');
    if (!table) return;
    var body = document.getElementById('ledgerBody');
    var cols = parseInt(table.getAttribute('data-cols'), 10) || 1;
    var rows = Array.prototype.slice.call(body.querySelectorAll('tr.txn-row'));
    var f = {
        search: document.getElementById('fltSearch'),
        type: document.getElementById('fltType'),
        month: document.getElementById('fltMonth'),
        from: document.getElementById('fltFrom'),
        to: document.getElementById('fltTo'),
        min: document.getElementById('fltMin'),
        max: document.getElementById('fltMax'),
        group: document.getElementById('fltGroup')
    };
    var money = function (n) { return '₹' + n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };

    function monthLabel(mk) {
        var p = mk.split('-');
        if (p.length < 2) return mk || '(no date)';
        var d = new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, 1);
        return d.toLocaleString('en-US', { month: 'short', year: 'numeric' });
    }

    function matches(r) {
        var s = f.search.value.trim().toLowerCase();
        if (s && r.getAttribute('data-desc').indexOf(s) === -1 && r.getAttribute('data-type').toLowerCase().indexOf(s) === -1) return false;
        if (f.type.value && r.getAttribute('data-type') !== f.type.value) return false;
        if (f.month.value && r.getAttribute('data-month') !== f.month.value) return false;
        var date = r.getAttribute('data-date');
        if (f.from.value && date < f.from.value) return false;
        if (f.to.value && date > f.to.value) return false;
        var amt = parseFloat(r.getAttribute('data-amount')) || 0;
        if (f.min.value !== '' && amt < parseFloat(f.min.value)) return false;
        if (f.max.value !== '' && amt > parseFloat(f.max.value)) return false;
        return true;
    }

    function removeHeaders() {
        Array.prototype.slice.call(body.querySelectorAll('tr.month-header')).forEach(function (h) { h.remove(); });
    }

    function apply() {
        removeHeaders();
        var visible = rows.filter(matches);
        var totalIn = 0, totalOut = 0;
        visible.forEach(function (r) {
            var amt = parseFloat(r.getAttribute('data-amount')) || 0;
            if (r.getAttribute('data-out') === '1') totalOut += amt; else totalIn += amt;
        });
        rows.forEach(function (r) { r.style.display = 'none'; });

        if (f.group.checked) {
            var groups = {};
            visible.forEach(function (r) {
                var mk = r.getAttribute('data-month') || '';
                (groups[mk] = groups[mk] || []).push(r);
            });
            Object.keys(groups).sort().reverse().forEach(function (mk) {
                var members = groups[mk];
                var net = 0;
                members.forEach(function (r) {
                    var amt = parseFloat(r.getAttribute('data-amount')) || 0;
                    net += (r.getAttribute('data-out') === '1' ? -amt : amt);
                });
                var hdr = document.createElement('tr');
                hdr.className = 'month-header';
                hdr.innerHTML = '<td colspan="' + cols + '" style="background: var(--bg-secondary, rgba(127,127,127,.12)); font-weight:700;">'
                    + monthLabel(mk) + ' &middot; Net ' + money(net) + ' &middot; ' + members.length + ' txn</td>';
                body.appendChild(hdr);
                members.forEach(function (r) { r.style.display = ''; body.appendChild(r); });
            });
        } else {
            visible.forEach(function (r) { r.style.display = ''; });
        }

        document.getElementById('statCount').textContent = visible.length;
        document.getElementById('statIn').textContent = money(totalIn);
        document.getElementById('statOut').textContent = money(totalOut);
        document.getElementById('statNet').textContent = money(totalIn - totalOut);
    }

    function exportCsv() {
        var visible = rows.filter(matches);
        var heads = Array.prototype.slice.call(table.querySelectorAll('thead th')).slice(0, -1).map(function (th) { return th.textContent.trim(); });
        var lines = [heads];
        visible.forEach(function (r) {
            var cells = Array.prototype.slice.call(r.querySelectorAll('td')).slice(0, -1).map(function (td) { return td.textContent.trim(); });
            lines.push(cells);
        });
        var csv = lines.map(function (row) {
            return row.map(function (c) { return '"' + String(c).replace(/"/g, '""') + '"'; }).join(',');
        }).join('\r\n');
        var blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'transactions_' + new Date().toISOString().slice(0, 10) + '.csv';
        document.body.appendChild(a); a.click(); a.remove();
    }

    ['input', 'change'].forEach(function (ev) {
        Object.keys(f).forEach(function (k) { if (f[k]) f[k].addEventListener(ev, apply); });
    });
    document.getElementById('fltClear').addEventListener('click', function () {
        f.search.value = ''; f.type.value = ''; f.month.value = '';
        f.from.value = ''; f.to.value = ''; f.min.value = ''; f.max.value = '';
        f.group.checked = false; apply();
    });
    document.getElementById('fltExport').addEventListener('click', exportCsv);
    apply();
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
