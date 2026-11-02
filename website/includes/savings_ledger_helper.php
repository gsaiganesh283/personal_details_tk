<?php
/**
 * The Savings Tracker's transaction-ledger engine -- the website's
 * counterpart to app/models/savings_tracker.py's TRANSACTION_LEDGER_CONFIG,
 * recompute_bank_balance()/recompute_ledger_totals(), and the
 * create/update/delete_*_transaction() functions.
 *
 * Two ledgers exist, same as the desktop app:
 *   - Bank Accounts has its own dedicated table (savings_bank_transactions,
 *     FK'd to savings_bank_accounts) -- it predates the shared ledger below
 *     and was never migrated in.
 *   - Every other ledger-eligible category shares one polymorphic table
 *     (savings_transactions, keyed by category+record_id), config-driven by
 *     $SAVINGS_LEDGER_CONFIG below so one engine covers all of them instead
 *     of one bespoke table+handler per category.
 *
 * "kind" decides which fields get recomputed and how, exactly mirroring the
 * desktop app:
 *   balance     -- opening_field + net(deposit - withdrawal) -> balance_field
 *   installment -- opening_total/opening_count + SUM(amount)/COUNT(*) -> total_field/count_field
 *   buy_sell    -- opening_invested/opening_units + net(buy - sell) -> invested_field/units_field
 *   log         -- history only, nothing recomputed (Insurance Policies)
 *
 * A derived field (balance_field/total_field/count_field/invested_field/
 * units_field) is NEVER written directly from a category's own Add/Edit
 * form -- see the 'readonly' flag on those FieldSpec-equivalents in
 * $SAVINGS_CATEGORIES (savings_helper.php) and savingsmanage.php's save
 * handler, which skips readonly fields the same way FieldEditDialog does.
 * The only writers are the recompute*() functions below, always called
 * after a transaction is created/updated/deleted, from scratch (never
 * incremented/decremented in place) so editing or deleting a past
 * transaction can't leave the total drifted out of sync.
 */

$SAVINGS_LEDGER_CONFIG = [
    'savings_ppf_epf' => [
        'kind' => 'balance', 'opening_field' => 'opening_balance', 'balance_field' => 'current_balance',
        'type_labels' => ['deposit' => 'Contribution', 'withdrawal' => 'Withdrawal'], 'name_field' => 'account_no',
    ],
    'savings_cash_on_hand' => [
        'kind' => 'balance', 'opening_field' => 'opening_balance', 'balance_field' => 'amount',
        'type_labels' => ['deposit' => 'Add', 'withdrawal' => 'Remove'], 'name_field' => 'location',
    ],
    'savings_fixed_deposits' => [
        'kind' => 'balance', 'opening_field' => 'opening_principal', 'balance_field' => 'principal',
        'type_labels' => ['deposit' => 'Top-up', 'withdrawal' => 'Partial Withdrawal'], 'name_field' => 'bank',
    ],
    'savings_recurring_deposits' => [
        'kind' => 'installment', 'total_field' => 'total_deposited', 'count_field' => 'months_paid',
        'opening_total_field' => 'opening_total_deposited', 'opening_count_field' => 'opening_months_paid',
        'type_labels' => ['installment' => 'Installment'], 'name_field' => 'bank',
    ],
    'savings_sip_investments' => [
        'kind' => 'installment', 'total_field' => 'total_invested', 'count_field' => 'installments_paid',
        'opening_total_field' => 'opening_total_invested', 'opening_count_field' => 'opening_installments_paid',
        'type_labels' => ['installment' => 'Installment'], 'name_field' => 'fund_name',
    ],
    'savings_mutual_funds' => [
        'kind' => 'buy_sell', 'units_field' => 'units', 'invested_field' => 'invested',
        'opening_units_field' => 'opening_units', 'opening_invested_field' => 'opening_invested',
        'type_labels' => ['buy' => 'Buy', 'sell' => 'Sell'], 'name_field' => 'fund_name',
    ],
    'savings_stocks' => [
        'kind' => 'buy_sell', 'units_field' => 'quantity', 'invested_field' => 'invested',
        'opening_units_field' => 'opening_quantity', 'opening_invested_field' => 'opening_invested',
        'type_labels' => ['buy' => 'Buy', 'sell' => 'Sell'], 'name_field' => 'stock_name',
    ],
    'savings_gold_metals' => [
        'kind' => 'buy_sell', 'units_field' => 'quantity_g', 'invested_field' => 'invested',
        'opening_units_field' => 'opening_quantity_g', 'opening_invested_field' => 'opening_invested',
        'type_labels' => ['buy' => 'Buy', 'sell' => 'Sell'], 'name_field' => 'metal',
    ],
    'savings_insurance_policies' => [
        'kind' => 'log', 'type_labels' => ['payment' => 'Premium Payment'], 'name_field' => 'policy_name',
    ],
    'savings_loans' => [
        'kind' => 'balance', 'opening_field' => 'opening_amount', 'balance_field' => 'outstanding_balance',
        'type_labels' => ['deposit' => 'Additional Borrowing', 'withdrawal' => 'EMI / Repayment'], 'name_field' => 'lender',
    ],
    'savings_credit_cards' => [
        'kind' => 'balance', 'opening_field' => 'opening_balance', 'balance_field' => 'used_amount',
        'type_labels' => ['deposit' => 'Purchase / Charge', 'withdrawal' => 'Payment'], 'name_field' => 'card_name',
    ],
    'savings_lent_money' => [
        'kind' => 'balance', 'opening_field' => 'opening_amount', 'balance_field' => 'outstanding_amount',
        'type_labels' => ['deposit' => 'Additional Lending', 'withdrawal' => 'Repayment Received'],
        'name_field' => 'borrower_name',
    ],
];

function savingsLedgerConfigFor(string $category): ?array {
    global $SAVINGS_LEDGER_CONFIG;
    return $SAVINGS_LEDGER_CONFIG[$category] ?? null;
}

// ------------------------------------------- per-category fields & labels
//
// Every category's ledger has its own custom-fields module
// ("savings_transactions__savings_sip_investments"), so a field added to the
// SIP ledger shows on SIP transactions only. Fields on the plain
// "savings_transactions" module (created before this split) still show on
// every ledger. Each category can also rename its built-in columns. Same
// rules as the desktop's app/models/ledger_modules.py.

const SAVINGS_LEDGER_SHARED_MODULE = 'savings_transactions';

const SAVINGS_LEDGER_DEFAULT_LABELS = [
    'date' => 'Date',
    'description' => 'Description',
    'type' => 'Type',
    'amount' => 'Amount (Rs.)',
    'units' => 'Units',
];

function savingsLedgerModule(string $category): string {
    return 'savings_transactions__' . $category;
}

/** Modules whose fields show on this category's transactions: its own, then the shared ones. */
function savingsLedgerFieldModules(string $category): array {
    return [savingsLedgerModule($category), SAVINGS_LEDGER_SHARED_MODULE];
}

/** Built-in column => label for one category (renamed ones replace the defaults). */
function savingsLedgerColumnLabels(Database $db, int $userId, string $category): array {
    $labels = SAVINGS_LEDGER_DEFAULT_LABELS;
    try {
        $rows = $db->fetchAll(
            "SELECT column_name, label FROM savings_ledger_labels WHERE user_id = ? AND category = ? ORDER BY updated_at, id",
            [$userId, $category]
        );
    } catch (Exception $e) {
        return $labels; // table not created yet
    }
    foreach ($rows as $row) { // newest last, so it wins over a duplicate from sync
        $label = trim((string)$row['label']);
        if (isset($labels[$row['column_name']]) && $label !== '') {
            $labels[$row['column_name']] = $label;
        }
    }
    return $labels;
}

/** Saves renamed labels; a blank label (or the default) goes back to the default. */
function saveSavingsLedgerColumnLabels(Database $db, int $userId, string $category, array $labels): void {
    $existing = [];
    foreach ($db->fetchAll(
        "SELECT id, column_name, label FROM savings_ledger_labels WHERE user_id = ? AND category = ? ORDER BY updated_at, id",
        [$userId, $category]
    ) as $row) {
        $existing[$row['column_name']][] = $row;
    }
    foreach (SAVINGS_LEDGER_DEFAULT_LABELS as $column => $default) {
        if (!array_key_exists($column, $labels)) continue;
        $wanted = mb_substr(trim((string)$labels[$column]), 0, 100);
        $rows = $existing[$column] ?? [];
        if ($wanted === '' || $wanted === $default) {
            foreach ($rows as $row) {
                $db->delete("DELETE FROM savings_ledger_labels WHERE id = ? AND user_id = ?", [$row['id'], $userId]);
            }
            continue;
        }
        if (!$rows) {
            $db->insert(
                "INSERT INTO savings_ledger_labels (user_id, category, column_name, label) VALUES (?, ?, ?, ?)",
                [$userId, $category, $column, $wanted]
            );
            continue;
        }
        $keep = array_pop($rows);
        foreach ($rows as $row) { // duplicates can only come from both sides creating one
            $db->delete("DELETE FROM savings_ledger_labels WHERE id = ? AND user_id = ?", [$row['id'], $userId]);
        }
        if ($keep['label'] !== $wanted) {
            $db->update("UPDATE savings_ledger_labels SET label = ? WHERE id = ? AND user_id = ?", [$wanted, $keep['id'], $userId]);
        }
    }
}

/** Custom-field values (and their files) of the given transactions, in every module they can live in. */
function deleteLedgerTransactionFieldValues(Database $db, int $userId, ?string $category, array $transactionIds): void {
    if (!$transactionIds) return;
    $modules = $category ? savingsLedgerFieldModules($category) : [SAVINGS_LEDGER_SHARED_MODULE];
    $placeholders = implode(',', array_fill(0, count($transactionIds), '?'));
    foreach ($modules as $module) {
        foreach ($transactionIds as $id) {
            deleteCustomFieldFilesForRecord($module, $id);
        }
        $db->delete(
            "DELETE FROM custom_field_entries WHERE linked_to = ? AND record_id IN ($placeholders) AND user_id = ?",
            [$module, ...$transactionIds, $userId]
        );
    }
}

// ---------------------------------------------------------- bank accounts

function getBankTransactions(Database $db, int $userId, int $bankAccountId): array {
    return $db->fetchAll(
        "SELECT * FROM savings_bank_transactions WHERE bank_account_id = ? AND user_id = ? ORDER BY date DESC, id DESC",
        [$bankAccountId, $userId]
    );
}

function recomputeBankBalance(Database $db, int $userId, int $bankAccountId): float {
    $account = $db->fetch("SELECT opening_balance FROM savings_bank_accounts WHERE id = ? AND user_id = ?", [$bankAccountId, $userId]);
    $opening = (float)($account['opening_balance'] ?? 0);
    $totals = $db->fetch(
        "SELECT COALESCE(SUM(CASE WHEN type = 'deposit' THEN amount ELSE 0 END), 0) AS deposits,
                COALESCE(SUM(CASE WHEN type = 'withdrawal' THEN amount ELSE 0 END), 0) AS withdrawals
         FROM savings_bank_transactions WHERE bank_account_id = ? AND user_id = ?",
        [$bankAccountId, $userId]
    );
    $balance = $opening + (float)($totals['deposits'] ?? 0) - (float)($totals['withdrawals'] ?? 0);
    $db->update("UPDATE savings_bank_accounts SET balance = ? WHERE id = ? AND user_id = ?", [$balance, $bankAccountId, $userId]);
    return $balance;
}

function createBankTransaction(Database $db, int $userId, array $values): int {
    $newId = $db->insert(
        "INSERT INTO savings_bank_transactions (user_id, bank_account_id, date, description, type, amount) VALUES (?, ?, ?, ?, ?, ?)",
        [$userId, $values['bank_account_id'], $values['date'], $values['description'], $values['type'], $values['amount']]
    );
    recomputeBankBalance($db, $userId, (int)$values['bank_account_id']);
    return $newId;
}

function updateBankTransaction(Database $db, int $userId, int $transactionId, array $values): void {
    $db->update(
        "UPDATE savings_bank_transactions SET date = ?, description = ?, type = ?, amount = ? WHERE id = ? AND user_id = ?",
        [$values['date'], $values['description'], $values['type'], $values['amount'], $transactionId, $userId]
    );
    recomputeBankBalance($db, $userId, (int)$values['bank_account_id']);
}

function deleteBankTransaction(Database $db, int $userId, int $transactionId): void {
    $existing = $db->fetch("SELECT bank_account_id FROM savings_bank_transactions WHERE id = ? AND user_id = ?", [$transactionId, $userId]);
    deleteCustomFieldFilesForRecord('savings_bank_transactions', $transactionId);
    $db->delete("DELETE FROM savings_bank_transactions WHERE id = ? AND user_id = ?", [$transactionId, $userId]);
    $db->delete("DELETE FROM custom_field_entries WHERE linked_to = 'savings_bank_transactions' AND record_id = ? AND user_id = ?", [$transactionId, $userId]);
    if ($existing) {
        recomputeBankBalance($db, $userId, (int)$existing['bank_account_id']);
    }
}

/** Call before deleting a Bank Account record itself. */
function purgeBankTransactionsForAccount(Database $db, int $userId, int $bankAccountId): void {
    $rows = $db->fetchAll("SELECT id FROM savings_bank_transactions WHERE bank_account_id = ? AND user_id = ?", [$bankAccountId, $userId]);
    $ids = array_map(fn($row) => (int)$row['id'], $rows);
    foreach ($ids as $id) {
        deleteCustomFieldFilesForRecord('savings_bank_transactions', $id);
    }
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $db->delete(
            "DELETE FROM custom_field_entries WHERE linked_to = 'savings_bank_transactions' AND record_id IN ($placeholders) AND user_id = ?",
            [...$ids, $userId]
        );
    }
    $db->delete("DELETE FROM savings_bank_transactions WHERE bank_account_id = ? AND user_id = ?", [$bankAccountId, $userId]);
}

// -------------------------------------------------------- shared ledger

function getLedgerTransactions(Database $db, int $userId, string $category, int $recordId): array {
    return $db->fetchAll(
        "SELECT * FROM savings_transactions WHERE category = ? AND record_id = ? AND user_id = ? ORDER BY date DESC, id DESC",
        [$category, $recordId, $userId]
    );
}

/**
 * Recomputes and persists whatever fields $SAVINGS_LEDGER_CONFIG says are
 * derived from the transaction log for this category, from scratch. Returns
 * the values written, [] for "log" kind (Insurance Policies).
 */
function recomputeLedgerTotals(Database $db, int $userId, string $category, int $recordId): array {
    $config = savingsLedgerConfigFor($category);
    if ($config === null) {
        return [];
    }
    $kind = $config['kind'];

    if ($kind === 'balance') {
        $openingField = $config['opening_field'];
        $account = $db->fetch("SELECT `$openingField` FROM `$category` WHERE id = ? AND user_id = ?", [$recordId, $userId]);
        $opening = (float)($account[$openingField] ?? 0);
        $totals = $db->fetch(
            "SELECT COALESCE(SUM(CASE WHEN type = 'deposit' THEN amount ELSE 0 END), 0) AS deposits,
                    COALESCE(SUM(CASE WHEN type = 'withdrawal' THEN amount ELSE 0 END), 0) AS withdrawals
             FROM savings_transactions WHERE category = ? AND record_id = ? AND user_id = ?",
            [$category, $recordId, $userId]
        );
        $balance = $opening + (float)($totals['deposits'] ?? 0) - (float)($totals['withdrawals'] ?? 0);
        $values = [$config['balance_field'] => $balance];
    } elseif ($kind === 'installment') {
        $openingTotalField = $config['opening_total_field'];
        $openingCountField = $config['opening_count_field'];
        $account = $db->fetch("SELECT `$openingTotalField`, `$openingCountField` FROM `$category` WHERE id = ? AND user_id = ?", [$recordId, $userId]);
        $openingTotal = (float)($account[$openingTotalField] ?? 0);
        $openingCount = (int)($account[$openingCountField] ?? 0);
        $totals = $db->fetch(
            "SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS cnt FROM savings_transactions
             WHERE category = ? AND record_id = ? AND user_id = ? AND type = 'installment'",
            [$category, $recordId, $userId]
        );
        $values = [
            $config['total_field'] => $openingTotal + (float)($totals['total'] ?? 0),
            $config['count_field'] => $openingCount + (int)($totals['cnt'] ?? 0),
        ];
    } elseif ($kind === 'buy_sell') {
        $openingInvestedField = $config['opening_invested_field'];
        $openingUnitsField = $config['opening_units_field'];
        $account = $db->fetch("SELECT `$openingInvestedField`, `$openingUnitsField` FROM `$category` WHERE id = ? AND user_id = ?", [$recordId, $userId]);
        $openingInvested = (float)($account[$openingInvestedField] ?? 0);
        $openingUnits = (float)($account[$openingUnitsField] ?? 0);
        $totals = $db->fetch(
            "SELECT
                COALESCE(SUM(CASE WHEN type = 'buy' THEN amount ELSE 0 END), 0)
                - COALESCE(SUM(CASE WHEN type = 'sell' THEN amount ELSE 0 END), 0) AS net_invested,
                COALESCE(SUM(CASE WHEN type = 'buy' THEN units ELSE 0 END), 0)
                - COALESCE(SUM(CASE WHEN type = 'sell' THEN units ELSE 0 END), 0) AS net_units
             FROM savings_transactions WHERE category = ? AND record_id = ? AND user_id = ?",
            [$category, $recordId, $userId]
        );
        $values = [
            $config['invested_field'] => $openingInvested + (float)($totals['net_invested'] ?? 0),
            $config['units_field'] => $openingUnits + (float)($totals['net_units'] ?? 0),
        ];
    } else {
        return []; // "log" -- history only
    }

    $setClause = implode(', ', array_map(fn($col) => "`$col` = ?", array_keys($values)));
    $db->update("UPDATE `$category` SET $setClause WHERE id = ? AND user_id = ?", [...array_values($values), $recordId, $userId]);
    return $values;
}

function createLedgerTransaction(Database $db, int $userId, string $category, array $values): int {
    $newId = $db->insert(
        "INSERT INTO savings_transactions (user_id, category, record_id, date, description, type, amount, units) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
        [$userId, $category, $values['record_id'], $values['date'], $values['description'], $values['type'], $values['amount'], $values['units']]
    );
    recomputeLedgerTotals($db, $userId, $category, (int)$values['record_id']);
    return $newId;
}

function updateLedgerTransaction(Database $db, int $userId, int $transactionId, array $values): void {
    $existing = $db->fetch("SELECT category, record_id FROM savings_transactions WHERE id = ? AND user_id = ?", [$transactionId, $userId]);
    $db->update(
        "UPDATE savings_transactions SET date = ?, description = ?, type = ?, amount = ?, units = ? WHERE id = ? AND user_id = ?",
        [$values['date'], $values['description'], $values['type'], $values['amount'], $values['units'], $transactionId, $userId]
    );
    if ($existing) {
        recomputeLedgerTotals($db, $userId, $existing['category'], (int)$existing['record_id']);
    }
}

function deleteLedgerTransaction(Database $db, int $userId, int $transactionId): void {
    $existing = $db->fetch("SELECT category, record_id FROM savings_transactions WHERE id = ? AND user_id = ?", [$transactionId, $userId]);
    deleteLedgerTransactionFieldValues($db, $userId, $existing['category'] ?? null, [$transactionId]);
    $db->delete("DELETE FROM savings_transactions WHERE id = ? AND user_id = ?", [$transactionId, $userId]);
    if ($existing) {
        recomputeLedgerTotals($db, $userId, $existing['category'], (int)$existing['record_id']);
    }
}

/** Call before deleting a ledger-eligible category record itself. */
function purgeLedgerTransactionsForRecord(Database $db, int $userId, string $category, int $recordId): void {
    $rows = $db->fetchAll("SELECT id FROM savings_transactions WHERE category = ? AND record_id = ? AND user_id = ?", [$category, $recordId, $userId]);
    $ids = array_map(fn($row) => (int)$row['id'], $rows);
    deleteLedgerTransactionFieldValues($db, $userId, $category, $ids);
    $db->delete("DELETE FROM savings_transactions WHERE category = ? AND record_id = ? AND user_id = ?", [$category, $recordId, $userId]);
}
