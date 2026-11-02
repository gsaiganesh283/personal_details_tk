<?php
/**
 * Shared config + auto-migration for the Savings & Investment Tracker.
 * savingsmanage.php is the one consolidated, tabbed page (Dashboard/Daily
 * Summary/Monthly Summary/15 categories/Custom Columns, matching the
 * tkinter desktop app's single Savings Tracker screen) -- savings-dashboard.php
 * and savings-tracker-advanced.php are now thin redirects into its tabs.
 * Kept in one place so nothing can drift out of sync on table/field/label
 * definitions.
 */

/** Shared by savingsmanage.php and savings-ledger.php (which links back to it). */
function savingsTrackerUrl($cat, $extra = '') {
    return url('savingsmanage.php?cat=' . urlencode($cat) . $extra);
}

// field types: text, number, date, textarea, select (needs 'options').
$SAVINGS_CATEGORIES = [
    'daily' => [
        'table' => 'savings_daily', 'label' => 'Daily Savings', 'icon' => 'fa-piggy-bank',
        'fields' => [
            ['name' => 'date', 'label' => 'Date', 'type' => 'date', 'required' => true],
            ['name' => 'description', 'label' => 'Description', 'type' => 'text'],
            ['name' => 'amount', 'label' => 'Amount (Rs.)', 'type' => 'number', 'required' => true],
        ],
        'title_field' => 'description', 'order_by' => 'date DESC',
    ],
    'bank_accounts' => [
        'table' => 'savings_bank_accounts', 'label' => 'Bank Accounts', 'icon' => 'fa-university',
        'fields' => [
            ['name' => 'bank_name', 'label' => 'Bank Name', 'type' => 'text', 'required' => true],
            ['name' => 'account_type', 'label' => 'Account Type', 'type' => 'text'],
            ['name' => 'account_no_last4', 'label' => 'Account No (last 4)', 'type' => 'text'],
            ['name' => 'branch', 'label' => 'Branch', 'type' => 'text'],
            ['name' => 'interest_rate', 'label' => 'Interest Rate (%)', 'type' => 'number'],
            ['name' => 'opening_balance', 'label' => 'Opening Balance (Rs.)', 'type' => 'number'],
            ['name' => 'balance', 'label' => 'Balance (Rs.) — auto-calculated', 'type' => 'number', 'readonly' => true],
        ],
        'title_field' => 'bank_name', 'order_by' => 'bank_name ASC',
        'ledger' => 'bank',
    ],
    'fixed_deposits' => [
        'table' => 'savings_fixed_deposits', 'label' => 'Fixed Deposits', 'icon' => 'fa-certificate',
        'fields' => [
            ['name' => 'bank', 'label' => 'Bank', 'type' => 'text', 'required' => true],
            ['name' => 'fd_number', 'label' => 'FD Number', 'type' => 'text'],
            ['name' => 'opening_principal', 'label' => 'Opening Principal (Rs.)', 'type' => 'number'],
            ['name' => 'principal', 'label' => 'Principal (Rs.) — auto-calculated', 'type' => 'number', 'readonly' => true],
            ['name' => 'interest_rate', 'label' => 'Interest Rate (%)', 'type' => 'number'],
            ['name' => 'start_date', 'label' => 'Start Date', 'type' => 'date'],
            ['name' => 'maturity_date', 'label' => 'Maturity Date', 'type' => 'date'],
            ['name' => 'tenure_months', 'label' => 'Tenure (months)', 'type' => 'number'],
            ['name' => 'maturity_amount', 'label' => 'Maturity Amount (Rs.)', 'type' => 'number'],
        ],
        'title_field' => 'bank', 'order_by' => 'maturity_date ASC',
        'ledger' => true,
    ],
    'recurring_deposits' => [
        'table' => 'savings_recurring_deposits', 'label' => 'Recurring Deposits', 'icon' => 'fa-redo',
        'fields' => [
            ['name' => 'bank', 'label' => 'Bank', 'type' => 'text', 'required' => true],
            ['name' => 'rd_number', 'label' => 'RD Number', 'type' => 'text'],
            ['name' => 'monthly_installment', 'label' => 'Monthly Installment (Rs.)', 'type' => 'number', 'required' => true],
            ['name' => 'interest_rate', 'label' => 'Interest Rate (%)', 'type' => 'number'],
            ['name' => 'start_date', 'label' => 'Start Date', 'type' => 'date'],
            ['name' => 'maturity_date', 'label' => 'Maturity Date', 'type' => 'date'],
            ['name' => 'opening_months_paid', 'label' => 'Opening Months Paid', 'type' => 'number'],
            ['name' => 'opening_total_deposited', 'label' => 'Opening Total Deposited (Rs.)', 'type' => 'number'],
            ['name' => 'months_paid', 'label' => 'Months Paid — auto-calculated', 'type' => 'number', 'readonly' => true],
            ['name' => 'total_deposited', 'label' => 'Total Deposited (Rs.) — auto-calculated', 'type' => 'number', 'readonly' => true],
        ],
        'title_field' => 'bank', 'order_by' => 'maturity_date ASC',
        'ledger' => true,
    ],
    'mutual_funds' => [
        'table' => 'savings_mutual_funds', 'label' => 'Mutual Funds', 'icon' => 'fa-chart-pie',
        'fields' => [
            ['name' => 'fund_name', 'label' => 'Fund Name', 'type' => 'text', 'required' => true],
            ['name' => 'category', 'label' => 'Category', 'type' => 'text'],
            ['name' => 'opening_units', 'label' => 'Opening Units', 'type' => 'number'],
            ['name' => 'opening_invested', 'label' => 'Opening Invested (Rs.)', 'type' => 'number'],
            ['name' => 'units', 'label' => 'Units — auto-calculated', 'type' => 'number', 'readonly' => true],
            ['name' => 'buy_nav', 'label' => 'Buy NAV (Rs.)', 'type' => 'number'],
            ['name' => 'current_nav', 'label' => 'Current NAV (Rs.)', 'type' => 'number'],
            ['name' => 'invested', 'label' => 'Invested (Rs.) — auto-calculated', 'type' => 'number', 'readonly' => true],
            ['name' => 'current_value', 'label' => 'Current Value (Rs.)', 'type' => 'number', 'required' => true],
        ],
        'title_field' => 'fund_name', 'order_by' => 'fund_name ASC',
        'ledger' => true,
    ],
    'sip_investments' => [
        'table' => 'savings_sip_investments', 'label' => 'SIP Investments', 'icon' => 'fa-calendar-check',
        'fields' => [
            ['name' => 'fund_name', 'label' => 'Fund Name', 'type' => 'text', 'required' => true],
            ['name' => 'sip_amount', 'label' => 'SIP Amount (Rs.)', 'type' => 'number', 'required' => true],
            ['name' => 'frequency', 'label' => 'Frequency', 'type' => 'text'],
            ['name' => 'start_date', 'label' => 'Start Date', 'type' => 'date'],
            ['name' => 'opening_installments_paid', 'label' => 'Opening Installments Paid', 'type' => 'number'],
            ['name' => 'opening_total_invested', 'label' => 'Opening Total Invested (Rs.)', 'type' => 'number'],
            ['name' => 'installments_paid', 'label' => 'Installments Paid — auto-calculated', 'type' => 'number', 'readonly' => true],
            ['name' => 'total_invested', 'label' => 'Total Invested (Rs.) — auto-calculated', 'type' => 'number', 'readonly' => true],
            ['name' => 'current_value', 'label' => 'Current Value (Rs.)', 'type' => 'number'],
        ],
        'title_field' => 'fund_name', 'order_by' => 'fund_name ASC',
        'ledger' => true,
    ],
    'stocks' => [
        'table' => 'savings_stocks', 'label' => 'Stocks', 'icon' => 'fa-chart-line',
        'fields' => [
            ['name' => 'stock_name', 'label' => 'Stock Name', 'type' => 'text', 'required' => true],
            ['name' => 'ticker', 'label' => 'Ticker', 'type' => 'text'],
            ['name' => 'opening_quantity', 'label' => 'Opening Quantity', 'type' => 'number'],
            ['name' => 'opening_invested', 'label' => 'Opening Invested (Rs.)', 'type' => 'number'],
            ['name' => 'quantity', 'label' => 'Quantity — auto-calculated', 'type' => 'number', 'readonly' => true],
            ['name' => 'buy_price', 'label' => 'Buy Price (Rs.)', 'type' => 'number'],
            ['name' => 'current_price', 'label' => 'Current Price (Rs.)', 'type' => 'number'],
            ['name' => 'invested', 'label' => 'Invested (Rs.) — auto-calculated', 'type' => 'number', 'readonly' => true],
            ['name' => 'current_value', 'label' => 'Current Value (Rs.)', 'type' => 'number', 'required' => true],
        ],
        'title_field' => 'stock_name', 'order_by' => 'stock_name ASC',
        'ledger' => true,
    ],
    'ppf_epf' => [
        'table' => 'savings_ppf_epf', 'label' => 'PPF & EPF', 'icon' => 'fa-landmark',
        'fields' => [
            ['name' => 'account_type', 'label' => 'Account Type', 'type' => 'select',
                'options' => ['ppf' => 'PPF', 'epf' => 'EPF', 'other' => 'Other']],
            ['name' => 'account_no', 'label' => 'Account No', 'type' => 'text'],
            ['name' => 'opening_balance', 'label' => 'Opening Balance (Rs.)', 'type' => 'number'],
            ['name' => 'annual_contribution', 'label' => 'Annual Contribution (Rs.)', 'type' => 'number'],
            ['name' => 'interest_rate', 'label' => 'Interest Rate (%)', 'type' => 'number'],
            ['name' => 'interest_earned', 'label' => 'Interest Earned (Rs.)', 'type' => 'number'],
            ['name' => 'current_balance', 'label' => 'Current Balance (Rs.) — auto-calculated', 'type' => 'number', 'readonly' => true],
        ],
        'title_field' => 'account_type', 'order_by' => 'account_type ASC',
        'ledger' => true,
    ],
    'gold_metals' => [
        'table' => 'savings_gold_metals', 'label' => 'Gold & Metals', 'icon' => 'fa-coins',
        'fields' => [
            ['name' => 'metal', 'label' => 'Metal', 'type' => 'select',
                'options' => ['gold' => 'Gold', 'silver' => 'Silver', 'platinum' => 'Platinum', 'other' => 'Other']],
            ['name' => 'form', 'label' => 'Form', 'type' => 'text'],
            ['name' => 'opening_quantity_g', 'label' => 'Opening Quantity (g)', 'type' => 'number'],
            ['name' => 'opening_invested', 'label' => 'Opening Invested (Rs.)', 'type' => 'number'],
            ['name' => 'quantity_g', 'label' => 'Quantity (g) — auto-calculated', 'type' => 'number', 'readonly' => true],
            ['name' => 'buy_rate_per_g', 'label' => 'Buy Rate/g (Rs.)', 'type' => 'number'],
            ['name' => 'current_rate_per_g', 'label' => 'Current Rate/g (Rs.)', 'type' => 'number'],
            ['name' => 'invested', 'label' => 'Invested (Rs.) — auto-calculated', 'type' => 'number', 'readonly' => true],
            ['name' => 'current_value', 'label' => 'Current Value (Rs.)', 'type' => 'number', 'required' => true],
        ],
        'title_field' => 'metal', 'order_by' => 'metal ASC',
        'ledger' => true,
    ],
    'insurance_policies' => [
        'table' => 'savings_insurance_policies', 'label' => 'Insurance Policies', 'icon' => 'fa-shield-alt',
        'fields' => [
            ['name' => 'policy_name', 'label' => 'Policy Name', 'type' => 'text', 'required' => true],
            ['name' => 'type', 'label' => 'Type', 'type' => 'text'],
            ['name' => 'policy_no', 'label' => 'Policy No', 'type' => 'text'],
            ['name' => 'premium', 'label' => 'Premium (Rs.)', 'type' => 'number'],
            ['name' => 'frequency', 'label' => 'Frequency', 'type' => 'text'],
            ['name' => 'sum_assured', 'label' => 'Sum Assured (Rs.)', 'type' => 'number'],
            ['name' => 'maturity_value', 'label' => 'Maturity Value (Rs.)', 'type' => 'number'],
            ['name' => 'maturity_date', 'label' => 'Maturity Date', 'type' => 'date'],
        ],
        'title_field' => 'policy_name', 'order_by' => 'maturity_date ASC',
        'ledger' => 'log',
    ],
    'cash_on_hand' => [
        'table' => 'savings_cash_on_hand', 'label' => 'Cash on Hand', 'icon' => 'fa-money-bill-wave',
        'fields' => [
            ['name' => 'location', 'label' => 'Location', 'type' => 'text', 'required' => true],
            ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
            ['name' => 'opening_balance', 'label' => 'Opening Balance (Rs.)', 'type' => 'number'],
            ['name' => 'amount', 'label' => 'Amount (Rs.) — auto-calculated', 'type' => 'number', 'readonly' => true],
        ],
        'title_field' => 'location', 'order_by' => 'location ASC',
        'ledger' => true,
    ],
    'loans' => [
        'table' => 'savings_loans', 'label' => 'Loans', 'icon' => 'fa-file-invoice-dollar',
        'fields' => [
            ['name' => 'loan_type', 'label' => 'Loan Type', 'type' => 'select',
                'options' => ['home' => 'Home Loan', 'personal' => 'Personal Loan', 'car' => 'Car Loan',
                    'education' => 'Education Loan', 'gold' => 'Gold Loan', 'other' => 'Other']],
            ['name' => 'lender', 'label' => 'Lender (Bank/NBFC)', 'type' => 'text', 'required' => true],
            ['name' => 'account_no', 'label' => 'Loan Account No', 'type' => 'text'],
            ['name' => 'interest_rate', 'label' => 'Interest Rate (%)', 'type' => 'number'],
            ['name' => 'emi_amount', 'label' => 'EMI Amount (Rs.)', 'type' => 'number'],
            ['name' => 'tenure_months', 'label' => 'Tenure (months)', 'type' => 'number'],
            ['name' => 'start_date', 'label' => 'Start Date', 'type' => 'date'],
            ['name' => 'opening_amount', 'label' => 'Opening Outstanding (Rs.)', 'type' => 'number'],
            ['name' => 'outstanding_balance', 'label' => 'Outstanding Balance (Rs.) — auto-calculated', 'type' => 'number', 'readonly' => true],
            ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
        ],
        'title_field' => 'lender', 'order_by' => 'lender ASC',
        'ledger' => true,
    ],
    'credit_cards' => [
        'table' => 'savings_credit_cards', 'label' => 'Credit Cards', 'icon' => 'fa-credit-card',
        'fields' => [
            ['name' => 'card_name', 'label' => 'Card Name', 'type' => 'text', 'required' => true],
            ['name' => 'bank', 'label' => 'Bank', 'type' => 'text'],
            ['name' => 'card_number_last4', 'label' => 'Card No (last 4)', 'type' => 'text'],
            ['name' => 'credit_limit', 'label' => 'Credit Limit (Rs.)', 'type' => 'number'],
            ['name' => 'billing_day', 'label' => 'Billing Day (1-31)', 'type' => 'number'],
            ['name' => 'due_day', 'label' => 'Payment Due Day (1-31)', 'type' => 'number'],
            ['name' => 'opening_balance', 'label' => 'Opening Used Amount (Rs.)', 'type' => 'number'],
            ['name' => 'used_amount', 'label' => 'Used Amount (Rs.) — auto-calculated', 'type' => 'number', 'readonly' => true],
            ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
        ],
        'title_field' => 'card_name', 'order_by' => 'card_name ASC',
        'ledger' => true,
    ],
    'lent_money' => [
        'table' => 'savings_lent_money', 'label' => 'Money Lent', 'icon' => 'fa-hand-holding-dollar',
        'fields' => [
            ['name' => 'borrower_name', 'label' => 'Borrower Name', 'type' => 'text', 'required' => true],
            ['name' => 'source_type', 'label' => 'Lent Via', 'type' => 'select',
                'options' => ['bank' => 'Bank Transfer', 'cash' => 'Cash', 'upi' => 'UPI', 'other' => 'Other']],
            ['name' => 'contact_info', 'label' => 'Contact / Relation', 'type' => 'text'],
            ['name' => 'interest_rate', 'label' => 'Interest Rate (%)', 'type' => 'number'],
            ['name' => 'date_lent', 'label' => 'Date Lent', 'type' => 'date'],
            ['name' => 'expected_return_date', 'label' => 'Expected Return Date', 'type' => 'date'],
            ['name' => 'opening_amount', 'label' => 'Opening Outstanding (Rs.)', 'type' => 'number'],
            ['name' => 'outstanding_amount', 'label' => 'Outstanding Amount (Rs.) — auto-calculated', 'type' => 'number', 'readonly' => true],
            ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
        ],
        'title_field' => 'borrower_name', 'order_by' => 'borrower_name ASC',
        'ledger' => true,
    ],
    'documents_registry' => [
        'table' => 'savings_documents_registry', 'label' => 'Documents & Safe Details', 'icon' => 'fa-folder-open',
        'fields' => [
            ['name' => 'category', 'label' => 'Category', 'type' => 'text', 'required' => true],
            ['name' => 'document_name', 'label' => 'Document Name', 'type' => 'text'],
            ['name' => 'reference_no', 'label' => 'Reference / A/C No', 'type' => 'text'],
            ['name' => 'format', 'label' => 'Format', 'type' => 'select',
                'options' => ['physical' => 'Physical', 'digital' => 'Digital']],
            ['name' => 'location', 'label' => 'Location / Where Kept', 'type' => 'text'],
            ['name' => 'nominee', 'label' => 'Nominee', 'type' => 'text'],
            ['name' => 'issue_date', 'label' => 'Issue Date', 'type' => 'date'],
            ['name' => 'expiry_date', 'label' => 'Expiry / Renewal', 'type' => 'date'],
            ['name' => 'status', 'label' => 'Status', 'type' => 'text'],
            ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea'],
        ],
        'title_field' => 'category', 'order_by' => 'category ASC',
    ],
];

// -------------------------------------------------------------- aggregation
// Mirrors app/models/savings_tracker.py's documented best-effort
// interpretation of the spreadsheet's Dashboard tab: "Market" totals cover
// categories with a genuine invested-vs-current-value distinction
// (Fixed Deposits' principal-vs-maturity counts as a proxy); "Total
// Savings" covers the flat/fixed categories (Money Lent counts too -- a
// receivable is still an asset). Insurance Policies and Documents & Safe
// Details are excluded from both, same as the desktop app.

$SAVINGS_MARKET_TABLES = [
    ['savings_fixed_deposits', 'principal', 'maturity_amount'],
    ['savings_mutual_funds', 'invested', 'current_value'],
    ['savings_sip_investments', 'total_invested', 'current_value'],
    ['savings_stocks', 'invested', 'current_value'],
    ['savings_gold_metals', 'invested', 'current_value'],
];

$SAVINGS_FLAT_TABLES = [
    ['savings_daily', 'amount'],
    ['savings_bank_accounts', 'balance'],
    ['savings_recurring_deposits', 'total_deposited'],
    ['savings_ppf_epf', 'current_balance'],
    ['savings_cash_on_hand', 'amount'],
    ['savings_lent_money', 'outstanding_amount'],
];

// What the user still owes -- subtracted from assets for Net Worth, and
// shown as negative entries in $SAVINGS_CATEGORY_VALUE_COLUMNS below so the
// breakdown list sums to Net Worth rather than an inflated assets-only figure.
$SAVINGS_LIABILITY_TABLES = [
    ['savings_loans', 'outstanding_balance'],
    ['savings_credit_cards', 'used_amount'],
];

// (label, table, column, sign) -- sign=-1 for liabilities.
$SAVINGS_CATEGORY_VALUE_COLUMNS = [
    ['Daily Savings', 'savings_daily', 'amount', 1],
    ['Bank Accounts', 'savings_bank_accounts', 'balance', 1],
    ['Fixed Deposits', 'savings_fixed_deposits', 'maturity_amount', 1],
    ['Recurring Deposits', 'savings_recurring_deposits', 'total_deposited', 1],
    ['Mutual Funds', 'savings_mutual_funds', 'current_value', 1],
    ['SIP Investments', 'savings_sip_investments', 'current_value', 1],
    ['Stocks', 'savings_stocks', 'current_value', 1],
    ['PPF & EPF', 'savings_ppf_epf', 'current_balance', 1],
    ['Gold & Metals', 'savings_gold_metals', 'current_value', 1],
    ['Cash on Hand', 'savings_cash_on_hand', 'amount', 1],
    ['Money Lent', 'savings_lent_money', 'outstanding_amount', 1],
    ['Loans', 'savings_loans', 'outstanding_balance', -1],
    ['Credit Cards', 'savings_credit_cards', 'used_amount', -1],
];

// (label, table, date_column, value_column) -- what to attribute to a
// period in the daily/monthly summaries. Falls back to created_at for
// categories with no natural entry date.
$SAVINGS_SUMMARY_SOURCES = [
    ['Daily Savings', 'savings_daily', 'date', 'amount'],
    ['Bank Accounts', 'savings_bank_accounts', 'created_at', 'balance'],
    ['Fixed Deposits', 'savings_fixed_deposits', 'start_date', 'principal'],
    ['Recurring Deposits', 'savings_recurring_deposits', 'start_date', 'monthly_installment'],
    ['Mutual Funds', 'savings_mutual_funds', 'created_at', 'invested'],
    ['SIP Investments', 'savings_sip_investments', 'start_date', 'sip_amount'],
    ['Stocks', 'savings_stocks', 'created_at', 'invested'],
    ['PPF & EPF', 'savings_ppf_epf', 'created_at', 'annual_contribution'],
    ['Gold & Metals', 'savings_gold_metals', 'created_at', 'invested'],
    ['Insurance Policies', 'savings_insurance_policies', 'created_at', 'premium'],
    ['Cash on Hand', 'savings_cash_on_hand', 'created_at', 'amount'],
];

/**
 * Shared by every Savings Tracker screen (previously duplicated three ways
 * across savings-dashboard.php/savings-tracker-advanced.php/savingsmanage.php
 * -- now that the three are one tabbed page, one copy of each).
 */
function savingsCategoryTotal(Database $db, int $userId, string $table, string $column): float {
    $row = $db->fetch("SELECT COALESCE(SUM(`$column`), 0) AS total FROM `$table` WHERE user_id = ?", [$userId]);
    return (float)($row['total'] ?? 0);
}

/** Mirrors app/models/savings_tracker.py's summarize_totals(). */
function savingsSummaryTotals(Database $db, int $userId): array {
    global $SAVINGS_MARKET_TABLES, $SAVINGS_FLAT_TABLES, $SAVINGS_LIABILITY_TABLES;

    $marketInvested = 0.0;
    $marketValue = 0.0;
    foreach ($SAVINGS_MARKET_TABLES as [$table, $investedColumn, $currentColumn]) {
        $marketInvested += savingsCategoryTotal($db, $userId, $table, $investedColumn);
        $marketValue += savingsCategoryTotal($db, $userId, $table, $currentColumn);
    }

    $totalSavings = 0.0;
    foreach ($SAVINGS_FLAT_TABLES as [$table, $column]) {
        $totalSavings += savingsCategoryTotal($db, $userId, $table, $column);
    }

    $totalLiabilities = 0.0;
    foreach ($SAVINGS_LIABILITY_TABLES as [$table, $column]) {
        $totalLiabilities += savingsCategoryTotal($db, $userId, $table, $column);
    }

    return [
        'total_savings' => $totalSavings,
        'market_invested' => $marketInvested,
        'market_value' => $marketValue,
        'market_gain_loss' => $marketValue - $marketInvested,
        'total_liabilities' => $totalLiabilities,
        'net_worth' => $totalSavings + $marketValue - $totalLiabilities,
    ];
}

/** Every monetary category's current total, liabilities negative -- sums to Net Worth. */
function savingsCategoryBreakdown(Database $db, int $userId): array {
    global $SAVINGS_CATEGORY_VALUE_COLUMNS;
    $out = [];
    foreach ($SAVINGS_CATEGORY_VALUE_COLUMNS as [$label, $table, $column, $sign]) {
        $out[] = ['category' => $label, 'value' => $sign * savingsCategoryTotal($db, $userId, $table, $column)];
    }
    return $out;
}

/**
 * period_length=10 groups by exact date (YYYY-MM-DD, "Daily Summary"),
 * period_length=7 groups by month (YYYY-MM, "Monthly Summary"). Mirrors
 * app/models/savings_tracker.py's _period_summary().
 */
function savingsPeriodSummary(Database $db, int $userId, int $periodLength): array {
    global $SAVINGS_SUMMARY_SOURCES;
    $totalsByPeriod = [];
    foreach ($SAVINGS_SUMMARY_SOURCES as [$label, $table, $dateColumn, $valueColumn]) {
        $rows = $db->fetchAll(
            "SELECT SUBSTR(`$dateColumn`, 1, $periodLength) AS period, COALESCE(SUM(`$valueColumn`), 0) AS total
             FROM `$table` WHERE user_id = ? AND `$dateColumn` IS NOT NULL AND `$dateColumn` != ''
             GROUP BY period",
            [$userId]
        );
        foreach ($rows as $row) {
            $period = (string)($row['period'] ?? '');
            if ($period === '') {
                continue;
            }
            $totalsByPeriod[$period] = ($totalsByPeriod[$period] ?? 0.0) + (float)($row['total'] ?? 0);
        }
    }

    ksort($totalsByPeriod);
    $out = [];
    $running = 0.0;
    foreach ($totalsByPeriod as $period => $total) {
        $running += $total;
        $out[] = ['period' => $period, 'total' => $total, 'cumulative' => $running];
    }
    return $out;
}

/**
 * Inline SVG cumulative-trend line chart -- the website's counterpart to
 * app/ui/groww_dashboard_view.py's draw_trend_chart() (a plain Tk Canvas
 * polyline with no axis labels). Kept just as minimal, plus a hover tooltip
 * per point (<title>) as a small, non-regressive addition Canvas can't do.
 * `style=` (not bare stroke=/fill= attributes) is required for var(--x) to
 * resolve reliably in inlined SVG across browsers.
 */
function renderSavingsTrendChartSvg(array $monthlySummary, int $width = 640, int $height = 200): string {
    if (count($monthlySummary) < 2) {
        return '<p style="color: var(--text-muted); text-align: center; padding: 48px 0;">Not enough monthly history yet.</p>';
    }
    $values = array_column($monthlySummary, 'cumulative');
    $labels = array_column($monthlySummary, 'period');
    $minV = min($values);
    $maxV = max($values);
    $span = ($maxV - $minV) ?: 1.0;
    $pad = 24;
    $n = count($values);

    $points = [];
    foreach ($values as $i => $v) {
        $x = $n > 1 ? $pad + ($width - 2 * $pad) * $i / ($n - 1) : $width / 2;
        $y = $height - $pad - ($height - 2 * $pad) * ($v - $minV) / $span;
        $points[] = [$x, $y];
    }
    $polylinePoints = implode(' ', array_map(fn($p) => round($p[0], 1) . ',' . round($p[1], 1), $points));

    $svg = '<svg viewBox="0 0 ' . $width . ' ' . $height . '" style="width: 100%; height: ' . $height . 'px; display: block;" preserveAspectRatio="none">';
    $svg .= '<line x1="' . $pad . '" y1="' . ($height - $pad) . '" x2="' . ($width - $pad) . '" y2="' . ($height - $pad) . '" style="stroke: var(--border); stroke-width: 1;"/>';
    $svg .= '<polyline points="' . htmlspecialchars($polylinePoints) . '" style="fill: none; stroke: var(--primary); stroke-width: 2.5; stroke-linejoin: round; stroke-linecap: round;"/>';
    foreach ($points as $i => [$x, $y]) {
        $svg .= '<circle cx="' . round($x, 1) . '" cy="' . round($y, 1) . '" r="3.5" style="fill: var(--primary);">'
             . '<title>' . htmlspecialchars($labels[$i] . ': Rs ' . number_format($values[$i], 0)) . '</title></circle>';
    }
    $svg .= '</svg>';
    return $svg;
}

/**
 * Auto-migration mirroring other-details.php's shared-hosting-friendly
 * pattern: create every table on first use instead of requiring a manual
 * SQL step. No FK constraints here (only INDEX) since shared-hosting DB
 * users often lack ALTER/FK permissions -- see sql/savings_tracker.sql
 * for the full reference schema with FKs, for manual setup.
 */
function ensureSavingsTables($db) {
    try {
        $db->query("CREATE TABLE IF NOT EXISTS savings_daily (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
            date DATE NOT NULL, description VARCHAR(255) NULL, amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS savings_bank_accounts (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
            bank_name VARCHAR(150) NOT NULL, account_type VARCHAR(50) NULL, account_no_last4 VARCHAR(10) NULL,
            branch VARCHAR(150) NULL, interest_rate DECIMAL(6,3) NULL, balance DECIMAL(14,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS savings_fixed_deposits (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
            bank VARCHAR(150) NOT NULL, fd_number VARCHAR(100) NULL, principal DECIMAL(14,2) NOT NULL DEFAULT 0,
            interest_rate DECIMAL(6,3) NULL, start_date DATE NULL, maturity_date DATE NULL,
            tenure_months INT NULL, maturity_amount DECIMAL(14,2) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS savings_recurring_deposits (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
            bank VARCHAR(150) NOT NULL, rd_number VARCHAR(100) NULL,
            monthly_installment DECIMAL(14,2) NOT NULL DEFAULT 0, interest_rate DECIMAL(6,3) NULL,
            start_date DATE NULL, maturity_date DATE NULL, months_paid INT NULL, total_deposited DECIMAL(14,2) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS savings_mutual_funds (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
            fund_name VARCHAR(150) NOT NULL, category VARCHAR(50) NULL, units DECIMAL(14,4) NULL,
            buy_nav DECIMAL(10,4) NULL, current_nav DECIMAL(10,4) NULL,
            invested DECIMAL(14,2) NOT NULL DEFAULT 0, current_value DECIMAL(14,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS savings_sip_investments (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
            fund_name VARCHAR(150) NOT NULL, sip_amount DECIMAL(14,2) NOT NULL DEFAULT 0, frequency VARCHAR(50) NULL,
            start_date DATE NULL, installments_paid INT NULL,
            total_invested DECIMAL(14,2) NOT NULL DEFAULT 0, current_value DECIMAL(14,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS savings_stocks (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
            stock_name VARCHAR(150) NOT NULL, ticker VARCHAR(30) NULL, quantity DECIMAL(14,4) NULL,
            buy_price DECIMAL(12,2) NULL, current_price DECIMAL(12,2) NULL,
            invested DECIMAL(14,2) NOT NULL DEFAULT 0, current_value DECIMAL(14,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS savings_ppf_epf (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
            account_type VARCHAR(20) NOT NULL DEFAULT 'ppf', account_no VARCHAR(100) NULL,
            opening_balance DECIMAL(14,2) NULL, annual_contribution DECIMAL(14,2) NULL,
            interest_rate DECIMAL(6,3) NULL, interest_earned DECIMAL(14,2) NULL,
            current_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS savings_gold_metals (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
            metal VARCHAR(20) NOT NULL DEFAULT 'gold', form VARCHAR(50) NULL, quantity_g DECIMAL(10,3) NULL,
            buy_rate_per_g DECIMAL(10,2) NULL, current_rate_per_g DECIMAL(10,2) NULL,
            invested DECIMAL(14,2) NOT NULL DEFAULT 0, current_value DECIMAL(14,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS savings_insurance_policies (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
            policy_name VARCHAR(150) NOT NULL, type VARCHAR(50) NULL, policy_no VARCHAR(100) NULL,
            premium DECIMAL(14,2) NULL, frequency VARCHAR(50) NULL, sum_assured DECIMAL(14,2) NULL,
            maturity_value DECIMAL(14,2) NULL, maturity_date DATE NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS savings_cash_on_hand (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
            location VARCHAR(150) NOT NULL, notes TEXT NULL, amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS savings_documents_registry (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
            category VARCHAR(100) NOT NULL, document_name VARCHAR(150) NULL, reference_no VARCHAR(100) NULL,
            format VARCHAR(20) NULL, location VARCHAR(150) NULL, nominee VARCHAR(100) NULL,
            issue_date DATE NULL, expiry_date DATE NULL, status VARCHAR(50) NULL, remarks TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Column set (including the opening_* seed columns, unused by the web
        // form yet -- see the ledger-engine stage) matches desktop-sync-api.php's
        // own CREATE TABLE for these 3 tables exactly, so whichever of the two
        // happens to run first on a given install leaves the same schema either
        // way. IF NOT EXISTS makes the second one a no-op.
        $db->query("CREATE TABLE IF NOT EXISTS savings_loans (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
            loan_type VARCHAR(30) NULL, lender VARCHAR(150) NOT NULL,
            account_no VARCHAR(100) NULL, interest_rate DECIMAL(6,3) NULL, emi_amount DECIMAL(14,2) NULL,
            tenure_months INT NULL, start_date DATE NULL,
            opening_amount DECIMAL(14,2) NOT NULL DEFAULT 0, outstanding_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS savings_credit_cards (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
            card_name VARCHAR(150) NOT NULL, bank VARCHAR(150) NULL, card_number_last4 VARCHAR(10) NULL,
            credit_limit DECIMAL(14,2) NULL, billing_day INT NULL, due_day INT NULL,
            opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0, used_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS savings_lent_money (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
            borrower_name VARCHAR(150) NOT NULL, source_type VARCHAR(30) NULL,
            contact_info VARCHAR(150) NULL, interest_rate DECIMAL(6,3) NULL, date_lent DATE NULL,
            expected_return_date DATE NULL,
            opening_amount DECIMAL(14,2) NOT NULL DEFAULT 0, outstanding_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Bank Accounts' own dedicated deposit/withdrawal ledger (kept
        // separate from the shared savings_transactions table below, same
        // as the desktop app -- it predates the shared ledger and was never
        // migrated in).
        $db->query("CREATE TABLE IF NOT EXISTS savings_bank_transactions (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, bank_account_id INT NOT NULL,
            date DATE NOT NULL, description VARCHAR(255) NULL,
            type VARCHAR(20) NOT NULL DEFAULT 'deposit', amount DECIMAL(14,2) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id), INDEX idx_account (bank_account_id),
            FOREIGN KEY (bank_account_id) REFERENCES savings_bank_accounts(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Shared deposit/withdrawal/installment/buy-sell ledger for every
        // other ledger-eligible category (see $SAVINGS_LEDGER_CONFIG in
        // savings_ledger_helper.php) -- polymorphic across category tables
        // via (category, record_id), same as the desktop app's SQLite
        // schema, so no FK here.
        $db->query("CREATE TABLE IF NOT EXISTS savings_transactions (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
            category VARCHAR(50) NOT NULL, record_id INT NOT NULL,
            date DATE NOT NULL, description VARCHAR(255) NULL,
            type VARCHAR(20) NOT NULL, amount DECIMAL(14,2) NOT NULL, units DECIMAL(14,4) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id), INDEX idx_category_record (category, record_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Renamed built-in ledger columns (Date, Description, Type, Amount,
        // Units) per category -- see savings_ledger_helper.php's
        // savingsLedgerColumnLabels(). No row = default label.
        $db->query("CREATE TABLE IF NOT EXISTS savings_ledger_labels (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
            category VARCHAR(50) NOT NULL, column_name VARCHAR(30) NOT NULL, label VARCHAR(100) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id), INDEX idx_category (category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Renamed category columns + user-built summary widgets.
        require_once __DIR__ . '/savings_widgets_helper.php';
        ensureSavingsWidgetTables($db);
    } catch (Exception $e) {
        // Ignore CREATE failures when the DB user lacks schema permissions --
        // matches other-details.php's fallback behavior.
    }

    ensureSavingsOpeningColumns($db);
}

/**
 * The transaction-ledger engine (savings_ledger_helper.php) derives totals
 * from an opening_* seed + the transaction log for every ledger-eligible
 * category. Some of these tables predate that engine (created above without
 * these columns, or created independently by desktop-sync-api.php before
 * this file's own CREATE TABLE statements existed) -- CREATE TABLE IF NOT
 * EXISTS only helps brand-new installs, so this ALTER-based self-heal
 * (mirroring desktop-sync-api.php's own ensureSavingsOpeningColumns(), which
 * a prior missing-column gap here already turned into a real data-loss
 * incident once) keeps existing installs' schema in sync too. Safe to call
 * on every request -- ALTER TABLE ADD COLUMN on an already-present column
 * fails harmlessly and is caught below.
 */
function ensureSavingsOpeningColumns($db) {
    $columns = [
        ['savings_bank_accounts', 'opening_balance', 'DECIMAL(14,2) NOT NULL DEFAULT 0'],
        ['savings_fixed_deposits', 'opening_principal', 'DECIMAL(14,2) NOT NULL DEFAULT 0'],
        ['savings_recurring_deposits', 'opening_months_paid', 'INT NOT NULL DEFAULT 0'],
        ['savings_recurring_deposits', 'opening_total_deposited', 'DECIMAL(14,2) NOT NULL DEFAULT 0'],
        ['savings_mutual_funds', 'opening_units', 'DECIMAL(14,4) NOT NULL DEFAULT 0'],
        ['savings_mutual_funds', 'opening_invested', 'DECIMAL(14,2) NOT NULL DEFAULT 0'],
        ['savings_sip_investments', 'opening_installments_paid', 'INT NOT NULL DEFAULT 0'],
        ['savings_sip_investments', 'opening_total_invested', 'DECIMAL(14,2) NOT NULL DEFAULT 0'],
        ['savings_stocks', 'opening_quantity', 'DECIMAL(14,4) NOT NULL DEFAULT 0'],
        ['savings_stocks', 'opening_invested', 'DECIMAL(14,2) NOT NULL DEFAULT 0'],
        ['savings_gold_metals', 'opening_quantity_g', 'DECIMAL(10,3) NOT NULL DEFAULT 0'],
        ['savings_gold_metals', 'opening_invested', 'DECIMAL(14,2) NOT NULL DEFAULT 0'],
        ['savings_cash_on_hand', 'opening_balance', 'DECIMAL(14,2) NOT NULL DEFAULT 0'],
        ['savings_loans', 'opening_amount', 'DECIMAL(14,2) NOT NULL DEFAULT 0'],
        ['savings_credit_cards', 'opening_balance', 'DECIMAL(14,2) NOT NULL DEFAULT 0'],
        ['savings_lent_money', 'opening_amount', 'DECIMAL(14,2) NOT NULL DEFAULT 0'],
    ];
    foreach ($columns as [$table, $column, $definition]) {
        try {
            $db->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        } catch (Exception $e) {
            // column already exists -- fine
        }
    }
}
