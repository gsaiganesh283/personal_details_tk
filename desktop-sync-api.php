<?php
/**
 * HTTP sync endpoint for the desktop app (Personal Details Saver -- Python/
 * Tkinter). Lets the desktop app keep SYNC_ALLOWED_USER_ID's rows in this
 * MySQL database in sync over HTTPS, for hosts (like this one) that block
 * direct external MySQL connections but always allow inbound HTTP to their
 * own site.
 *
 * Auth: two accepted schemes, checked right after the POST-method guard below.
 *   1. X-Sync-Key header -- a shared secret (not a login session, this is
 *      the desktop app's original scheme). The request body's user_id must
 *      equal SYNC_ALLOWED_USER_ID exactly, since the key doesn't itself
 *      identify a user -- the client asserts it and the server checks it
 *      against the one hardcoded allowed value. Fine for a single trusted
 *      desktop device; deliberately NOT extended to the branch below.
 *   2. Authorization: Bearer <token> -- the native Android app's per-login
 *      token (see includes/mobile-auth-helpers.php). Resolved server-side
 *      to a real user_id; a Bearer request's body user_id, if any, is never
 *      trusted or even read.
 * Every table/column name is checked against the hardcoded allowlist below
 * before it ever reaches a query -- the client only ever supplies bound
 * parameter values, never raw SQL identifiers.
 *
 * Keep $syncTables in sync with TABLE_CONFIG in
 * app/services/mysql_sync.py on the desktop side.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/desktop-sync-config.php';
require_once __DIR__ . '/includes/mobile-auth-helpers.php';

header('Content-Type: application/json');

function respond($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function filterColumns(array $values, array $allowedColumns): array {
    $cols = [];
    $params = [];
    foreach ($allowedColumns as $col) {
        if (array_key_exists($col, $values)) {
            $cols[] = $col;
            $params[] = $values[$col];
        }
    }
    return [$cols, $params];
}

function ensureSavingsTables(Database $db): void {
    $db->query("CREATE TABLE IF NOT EXISTS savings_daily (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        date DATE NOT NULL,
        description VARCHAR(255) NULL,
        amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS savings_bank_accounts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        bank_name VARCHAR(150) NOT NULL,
        account_type VARCHAR(50) NULL,
        account_no_last4 VARCHAR(10) NULL,
        branch VARCHAR(150) NULL,
        interest_rate DECIMAL(6,3) NULL,
        balance DECIMAL(14,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS savings_fixed_deposits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        bank VARCHAR(150) NOT NULL,
        fd_number VARCHAR(100) NULL,
        principal DECIMAL(14,2) NOT NULL DEFAULT 0,
        interest_rate DECIMAL(6,3) NULL,
        start_date DATE NULL,
        maturity_date DATE NULL,
        tenure_months INT NULL,
        maturity_amount DECIMAL(14,2) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS savings_recurring_deposits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        bank VARCHAR(150) NOT NULL,
        rd_number VARCHAR(100) NULL,
        monthly_installment DECIMAL(14,2) NOT NULL DEFAULT 0,
        interest_rate DECIMAL(6,3) NULL,
        start_date DATE NULL,
        maturity_date DATE NULL,
        months_paid INT NULL,
        total_deposited DECIMAL(14,2) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS savings_mutual_funds (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        fund_name VARCHAR(150) NOT NULL,
        category VARCHAR(50) NULL,
        units DECIMAL(14,4) NULL,
        buy_nav DECIMAL(10,4) NULL,
        current_nav DECIMAL(10,4) NULL,
        invested DECIMAL(14,2) NOT NULL DEFAULT 0,
        current_value DECIMAL(14,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS savings_sip_investments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        fund_name VARCHAR(150) NOT NULL,
        sip_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        frequency VARCHAR(50) NULL,
        start_date DATE NULL,
        installments_paid INT NULL,
        total_invested DECIMAL(14,2) NOT NULL DEFAULT 0,
        current_value DECIMAL(14,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS savings_stocks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        stock_name VARCHAR(150) NOT NULL,
        ticker VARCHAR(30) NULL,
        quantity DECIMAL(14,4) NULL,
        buy_price DECIMAL(12,2) NULL,
        current_price DECIMAL(12,2) NULL,
        invested DECIMAL(14,2) NOT NULL DEFAULT 0,
        current_value DECIMAL(14,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS savings_ppf_epf (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        account_type VARCHAR(20) NOT NULL DEFAULT 'ppf',
        account_no VARCHAR(100) NULL,
        opening_balance DECIMAL(14,2) NULL,
        annual_contribution DECIMAL(14,2) NULL,
        interest_rate DECIMAL(6,3) NULL,
        interest_earned DECIMAL(14,2) NULL,
        current_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS savings_gold_metals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        metal VARCHAR(20) NOT NULL DEFAULT 'gold',
        form VARCHAR(50) NULL,
        quantity_g DECIMAL(10,3) NULL,
        buy_rate_per_g DECIMAL(10,2) NULL,
        current_rate_per_g DECIMAL(10,2) NULL,
        invested DECIMAL(14,2) NOT NULL DEFAULT 0,
        current_value DECIMAL(14,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS savings_insurance_policies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        policy_name VARCHAR(150) NOT NULL,
        type VARCHAR(50) NULL,
        policy_no VARCHAR(100) NULL,
        premium DECIMAL(14,2) NULL,
        frequency VARCHAR(50) NULL,
        sum_assured DECIMAL(14,2) NULL,
        maturity_value DECIMAL(14,2) NULL,
        maturity_date DATE NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS savings_cash_on_hand (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        location VARCHAR(150) NOT NULL,
        notes TEXT NULL,
        amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS savings_loans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        loan_type VARCHAR(30) NULL,
        lender VARCHAR(150) NOT NULL,
        account_no VARCHAR(100) NULL,
        interest_rate DECIMAL(6,3) NULL,
        emi_amount DECIMAL(14,2) NULL,
        tenure_months INT NULL,
        start_date DATE NULL,
        opening_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        outstanding_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS savings_credit_cards (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        card_name VARCHAR(150) NOT NULL,
        bank VARCHAR(150) NULL,
        card_number_last4 VARCHAR(10) NULL,
        credit_limit DECIMAL(14,2) NULL,
        billing_day INT NULL,
        due_day INT NULL,
        opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
        used_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS savings_lent_money (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        borrower_name VARCHAR(150) NOT NULL,
        source_type VARCHAR(30) NULL,
        contact_info VARCHAR(150) NULL,
        interest_rate DECIMAL(6,3) NULL,
        date_lent DATE NULL,
        expected_return_date DATE NULL,
        opening_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        outstanding_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS savings_documents_registry (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        category VARCHAR(100) NOT NULL,
        document_name VARCHAR(150) NULL,
        reference_no VARCHAR(100) NULL,
        format VARCHAR(20) NULL,
        location VARCHAR(150) NULL,
        nominee VARCHAR(100) NULL,
        issue_date DATE NULL,
        expiry_date DATE NULL,
        status VARCHAR(50) NULL,
        remarks TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Both tables below back per-record transaction lists (not "categories"
    // themselves), so their custom-field entries (linked_to =
    // 'savings_bank_transactions' / 'savings_transactions' on the desktop
    // side) are flat-only -- no repeatable blocks -- see
    // app/models/custom_fields.py's FLAT_ONLY_MODULES.
    $db->query("CREATE TABLE IF NOT EXISTS savings_bank_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        bank_account_id INT NOT NULL,
        date DATE NOT NULL,
        description VARCHAR(255) NULL,
        type VARCHAR(20) NOT NULL DEFAULT 'deposit',
        amount DECIMAL(14,2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_account (bank_account_id),
        FOREIGN KEY (bank_account_id) REFERENCES savings_bank_accounts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // No FK on (category, record_id) -- it's polymorphic across 11 category
    // tables, same as the local SQLite schema (see
    // models/savings_tracker.py's TRANSACTION_LEDGER_CONFIG).
    $db->query("CREATE TABLE IF NOT EXISTS savings_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        category VARCHAR(50) NOT NULL,
        record_id INT NOT NULL,
        date DATE NOT NULL,
        description VARCHAR(255) NULL,
        type VARCHAR(20) NOT NULL,
        amount DECIMAL(14,2) NOT NULL,
        units DECIMAL(14,4) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_category_record (category, record_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Renamed built-in ledger columns per category (desktop:
    // app/models/ledger_modules.py; website: savings_ledger_helper.php).
    $db->query("CREATE TABLE IF NOT EXISTS savings_ledger_labels (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        category VARCHAR(50) NOT NULL,
        column_name VARCHAR(30) NOT NULL,
        label VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_category (category)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Renamed category columns + summary widgets (desktop:
    // app/models/savings_widgets.py; website: includes/savings_widgets_helper.php).
    require_once __DIR__ . '/includes/savings_widgets_helper.php';
    ensureSavingsWidgetTables($db);

    ensureSavingsOpeningColumns($db);
}

/**
 * 'contacts' was wired into $syncTables' allowlist below and the desktop
 * app's own TABLE_CONFIG, but never got a CREATE TABLE here -- every push
 * failed outright with "table doesn't exist" (MySQL error 1146), silently
 * (sync_change() never surfaces a single failure to the UI), so no contact
 * had ever actually reached this database.
 */
function ensureContactsTable(Database $db): void {
    $db->query("CREATE TABLE IF NOT EXISTS contacts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        phone_number VARCHAR(50) NOT NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * The desktop app's transaction-ledger feature added opening_* columns to
 * these tables locally (SQLite) but this endpoint's CREATE TABLE statements
 * above were never updated to match, and neither was $syncTables' allowlist
 * below -- so every push/pull of these columns was silently dropped. Added
 * after that gap caused a real data-loss incident (an empty-opening-value
 * pull, mixed with a partial reconcile, produced local duplicates with
 * zeroed opening_* fields). CREATE TABLE IF NOT EXISTS above only helps
 * brand-new installs; existing remote tables need this ALTER-based
 * self-heal too, same pattern as setup.php's field_type migration.
 */
function ensureSavingsOpeningColumns(Database $db): void {
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
    ];
    foreach ($columns as [$table, $column, $definition]) {
        try {
            $db->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        } catch (Exception $e) {
            // column already exists -- fine
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'error' => 'Method not allowed'], 405);
}

$db = Database::getInstance();

// Bearer token (mobile app) takes priority if present; otherwise fall back
// to the legacy shared-secret scheme (desktop app). $authUserId is null
// under the X-Sync-Key scheme -- that branch resolves user_id from the
// request body further down instead, exactly as it always has.
$authUserId = null;
$bearer = extractBearerToken();
if ($bearer !== null) {
    ensureApiTokensTable($db);
    $authUserId = resolveApiToken($db, $bearer);
    if ($authUserId === null) {
        respond(['success' => false, 'error' => 'Unauthorized'], 401);
    }
} else {
    $providedKey = $_SERVER['HTTP_X_SYNC_KEY'] ?? '';
    if (!is_string($providedKey) || $providedKey === '' || !hash_equals(SYNC_API_KEY, $providedKey)) {
        respond(['success' => false, 'error' => 'Unauthorized'], 401);
    }
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    respond(['success' => false, 'error' => 'Invalid JSON body'], 400);
}

$action = $body['action'] ?? '';

if ($action === 'ping') {
    respond(['success' => true, 'message' => 'pong', 'fs_version' => 2]);
}

// ---- Safety PIN (one PIN shared by web, desktop and mobile; see includes/safety_pin.php)
//   safety_pin_get    -> {set, hash, updated_at}     desktop keeps a copy for offline checks
//   safety_pin_set    {hash|null, updated_at}        desktop set/changed/removed it (newest wins)
//   safety_pin_verify {pin} -> {ok, error}           mobile checks a typed PIN (tries are counted)
if (strpos($action, 'safety_pin_') === 0) {
    require_once __DIR__ . '/includes/functions.php';
    require_once __DIR__ . '/includes/safety_pin.php';
    if ($authUserId !== null) {
        $spUserId = $authUserId;
    } else {
        $spUserId = (int)($body['user_id'] ?? 0);
        if ($spUserId !== SYNC_ALLOWED_USER_ID) {
            respond(['success' => false, 'error' => 'Unauthorized user_id'], 403);
        }
    }
    try {
        $row = spGet($db, $spUserId);
        if ($action === 'safety_pin_get') {
            respond(['success' => true, 'set' => (bool)$row, 'hash' => $row['pin_hash'] ?? null,
                     'updated_at' => $row['updated_at'] ?? null]);
        }
        if ($action === 'safety_pin_set') {
            $hash = $body['hash'] ?? null;
            $updatedAt = (string)($body['updated_at'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $updatedAt)) {
                respond(['success' => false, 'error' => 'updated_at must be "YYYY-MM-DD HH:MM:SS" (UTC)'], 400);
            }
            if ($hash !== null && (!is_string($hash) || !spValidHash($hash))) {
                respond(['success' => false, 'error' => 'Invalid PIN hash'], 400);
            }
            // Newest change wins -- an older desktop copy never overwrites a newer PIN.
            if ($row && strcmp($row['updated_at'], $updatedAt) > 0) {
                respond(['success' => true, 'applied' => false, 'set' => true, 'hash' => $row['pin_hash'],
                         'updated_at' => $row['updated_at']]);
            }
            spStoreHash($db, $spUserId, $hash, $updatedAt);
            spLog($db, $spUserId, $hash === null ? 'Safety PIN removed (desktop app)' : 'Safety PIN set (desktop app)');
            respond(['success' => true, 'applied' => true]);
        }
        if ($action === 'safety_pin_verify') {
            if (!$row) {
                respond(['success' => true, 'ok' => true, 'set' => false]); // no PIN -> nothing to protect
            }
            $result = spVerify($db, $spUserId, (string)($body['pin'] ?? ''));
            respond(['success' => true, 'ok' => $result['ok'], 'set' => true, 'error' => $result['error']]);
        }
        respond(['success' => false, 'error' => 'Unknown safety PIN action'], 400);
    } catch (Exception $e) {
        respond(['success' => false, 'error' => 'Safety PIN error: ' . $e->getMessage()], 500);
    }
}

// ---- Fields engine sync (v2): folders, buttons, blocks, fields, values ----
// uid-based, multi-table -- see includes/fields_sync.php. Handled before the
// single-table allowlist below because one call covers all seven tables.
if (strpos($action, 'fs_') === 0) {
    require_once __DIR__ . '/includes/fields_sync.php';
    if ($authUserId !== null) {
        $fsUserId = $authUserId;
    } else {
        $fsUserId = (int)($body['user_id'] ?? 0);
        if ($fsUserId !== SYNC_ALLOWED_USER_ID) {
            respond(['success' => false, 'error' => 'Unauthorized user_id'], 403);
        }
    }
    try {
        if (!feHasUids()) {
            respond(['success' => false, 'error' => 'Could not add the uid column to the fields tables (the database user needs ALTER permission).'], 500);
        }
        $fs = new FieldsSync($fsUserId);
        if ($action === 'fs_pull') {
            respond(['success' => true] + $fs->pull((string)($body['since'] ?? '')));
        }
        if ($action === 'fs_get') {
            respond(['success' => true, 'rows' => $fs->get((string)($body['table'] ?? ''), (array)($body['uids'] ?? []))]);
        }
        if ($action === 'fs_push') {
            respond(['success' => true, 'results' => $fs->push((array)($body['ops'] ?? []))]);
        }
        respond(['success' => false, 'error' => 'Unknown action'], 400);
    } catch (Throwable $e) {
        respond(['success' => false, 'error' => substr($e->getMessage(), 0, 300)], 500);
    }
}

$syncTables = [
    'personal_details' => [
        'first_name', 'last_name', 'date_of_birth', 'gender', 'phone', 'alternate_phone',
        'email', 'address_line1', 'address_line2', 'city', 'state', 'country', 'zip_code',
        'nationality', 'occupation', 'company', 'notes',
    ],
    'bank_details' => [
        'bank_name', 'account_holder', 'account_number', 'account_type', 'ifsc_code',
        'branch_name', 'branch_address', 'swift_code', 'micr_code', 'upi_id', 'linked_phone',
        'is_primary', 'status', 'notes',
    ],
    'pan_details' => [
        'holder_name', 'pan_number', 'father_name', 'date_of_birth', 'category',
        'mobile_number', 'email', 'address', 'issue_date', 'is_linked_aadhaar', 'status', 'notes',
    ],
    'aadhaar_details' => [
        'holder_name', 'aadhaar_number', 'date_of_birth', 'gender', 'address', 'pin_code',
        'mobile_linked', 'email_linked', 'enrollment_number', 'vid_number', 'issue_date',
        'is_verified', 'notes',
    ],
    'documents' => [
        'document_name', 'document_type', 'file_name', 'file_path',
        'file_size', 'mime_type', 'description', 'tags', 'expiry_date', 'expiry_reminder_days',
    ],
    'other_detail_folders' => [
        'folder_name', 'folder_icon', 'folder_color', 'folder_order', 'description', 'is_active',
    ],
    'other_detail_records' => ['folder_id', 'record_name', 'notes'],
    'custom_field_blocks' => [
        'block_name', 'block_label', 'linked_to', 'layout', 'block_order',
        'is_repeatable', 'max_instances', 'is_active',
    ],
    'custom_fields' => [
        'field_name', 'field_label', 'field_type', 'field_options', 'field_placeholder',
        'is_required', 'field_order', 'block_id', 'field_order_in_block', 'linked_to', 'is_active',
    ],
    'custom_field_entries' => ['linked_to', 'record_id', 'custom_field_id', 'field_value', 'block_instance'],
    'savings_daily' => ['date', 'description', 'amount'],
    'savings_bank_accounts' => [
        'bank_name', 'account_type', 'account_no_last4', 'branch', 'interest_rate',
        'opening_balance', 'balance',
    ],
    'savings_fixed_deposits' => [
        'bank', 'fd_number', 'opening_principal', 'principal', 'interest_rate', 'start_date',
        'maturity_date', 'tenure_months', 'maturity_amount',
    ],
    'savings_recurring_deposits' => [
        'bank', 'rd_number', 'monthly_installment', 'interest_rate', 'start_date', 'maturity_date',
        'opening_months_paid', 'opening_total_deposited', 'months_paid', 'total_deposited',
    ],
    'savings_mutual_funds' => [
        'fund_name', 'category', 'opening_units', 'opening_invested', 'units',
        'buy_nav', 'current_nav', 'invested', 'current_value',
    ],
    'savings_sip_investments' => [
        'fund_name', 'sip_amount', 'frequency', 'start_date',
        'opening_installments_paid', 'opening_total_invested',
        'installments_paid', 'total_invested', 'current_value',
    ],
    'savings_stocks' => [
        'stock_name', 'ticker', 'opening_quantity', 'opening_invested', 'quantity',
        'buy_price', 'current_price', 'invested', 'current_value',
    ],
    'savings_ppf_epf' => [
        'account_type', 'account_no', 'opening_balance', 'annual_contribution',
        'interest_rate', 'interest_earned', 'current_balance',
    ],
    'savings_gold_metals' => [
        'metal', 'form', 'opening_quantity_g', 'opening_invested', 'quantity_g',
        'buy_rate_per_g', 'current_rate_per_g', 'invested', 'current_value',
    ],
    'savings_insurance_policies' => [
        'policy_name', 'type', 'policy_no', 'premium',
        'frequency', 'sum_assured', 'maturity_value', 'maturity_date',
    ],
    'savings_cash_on_hand' => ['location', 'notes', 'opening_balance', 'amount'],
    'savings_loans' => [
        'loan_type', 'lender', 'account_no', 'interest_rate', 'emi_amount', 'tenure_months',
        'start_date', 'opening_amount', 'outstanding_balance', 'notes',
    ],
    'savings_credit_cards' => [
        'card_name', 'bank', 'card_number_last4', 'credit_limit', 'billing_day', 'due_day',
        'opening_balance', 'used_amount', 'notes',
    ],
    'savings_lent_money' => [
        'borrower_name', 'source_type', 'contact_info', 'interest_rate', 'date_lent',
        'expected_return_date', 'opening_amount', 'outstanding_amount', 'notes',
    ],
    'savings_documents_registry' => [
        'category', 'document_name', 'reference_no', 'format', 'location',
        'nominee', 'issue_date', 'expiry_date', 'status', 'remarks',
    ],
    'savings_bank_transactions' => ['bank_account_id', 'date', 'description', 'type', 'amount'],
    'savings_transactions' => ['category', 'record_id', 'date', 'description', 'type', 'amount', 'units'],
    'savings_ledger_labels' => ['category', 'column_name', 'label'],
    'savings_column_labels' => ['category', 'column_name', 'label'],
    'savings_widgets' => [
        'category', 'title', 'agg_func', 'source_column', 'formula', 'filter_column', 'filter_op',
        'filter_value', 'number_format', 'decimals', 'value_prefix', 'value_suffix', 'accent_color',
        'color_rule', 'caption', 'widget_order', 'is_active',
    ],
    // Contacts, exposed for sync with the desktop app's local address book
    'contacts' => ['name', 'phone_number', 'notes'],
    // Keyed by this table's own name (not 'notif_templates') -- the desktop
    // client already sends this exact name as `table`, since its local
    // table is named differently and it resolves the remote name itself
    // (mysql_sync.py's TABLE_CONFIG['notif_templates']['remote_table']).
    'notification_templates' => ['key', 'title', 'body', 'priority', 'reminder_days', 'linked_to', 'channel_inapp', 'channel_email', 'is_active'],
    // Recursive "Action Buttons" feature (includes/action_buttons_helper.php)
    // -- was only ever writable from the website directly; the desktop app's
    // local SQLite copy of these two tables never had a path to this API.
    'action_buttons' => ['parent_module', 'label', 'button_order'],
    'action_button_records' => ['button_id', 'parent_record_id', 'label'],
];

$table = $body['table'] ?? '';
if (!array_key_exists($table, $syncTables)) {
    respond(['success' => false, 'error' => 'Unknown table'], 400);
}
$allowedColumns = $syncTables[$table];

// Desktop builds from before the fields-engine sync pushed folders, buttons,
// blocks, fields and values through these per-row actions, translating ids
// themselves -- that translation is what attached them to the wrong parent.
// Current desktop builds use the fs_* actions above instead, so a per-row
// WRITE to one of those tables from a desktop token can only come from an
// outdated build: refuse it with a clear message rather than let it corrupt
// links. (Reads stay allowed, and the phone app -- which writes with the
// server's own ids -- is unaffected.)
require_once __DIR__ . '/includes/fields_engine.php';
if ($bearer !== null && in_array($action, ['insert', 'update', 'delete'], true) && in_array($table, FE_TABLES, true)) {
    $tokenRow = $db->fetch("SELECT device_label FROM api_tokens WHERE token_hash = ?", [hash('sha256', $bearer)]);
    if ($tokenRow && $tokenRow['device_label'] === 'Desktop app') {
        respond([
            'success' => false,
            'error' => 'This desktop app version is out of date: folders, buttons, blocks and custom fields now sync '
                     . 'through the new sync. Install the updated desktop app -- nothing was changed on the website.',
        ], 409);
    }
}

if ($authUserId !== null) {
    // Bearer scheme: user_id comes from the resolved token, never the body.
    $userId = $authUserId;
} else {
    // Legacy X-Sync-Key scheme: client asserts user_id, checked against the
    // one hardcoded allowed value -- unchanged from before this file added
    // Bearer support.
    $userId = (int)($body['user_id'] ?? 0);
    if ($userId !== SYNC_ALLOWED_USER_ID) {
        respond(['success' => false, 'error' => 'Unauthorized user_id'], 403);
    }
}

ensureSavingsTables($db);
ensureContactsTable($db);

try {
    if ($action === 'exists') {
        $id = (int)($body['id'] ?? 0);
        $row = $db->fetch("SELECT id FROM `$table` WHERE id = ? AND user_id = ?", [$id, $userId]);
        respond(['success' => true, 'exists' => (bool)$row]);
    }

    if ($action === 'get_single_id') {
        $row = $db->fetch("SELECT id FROM `$table` WHERE user_id = ?", [$userId]);
        respond(['success' => true, 'id' => $row ? (int)$row['id'] : null]);
    }

    if ($action === 'insert') {
        $values = is_array($body['values'] ?? null) ? $body['values'] : [];
        [$cols, $params] = filterColumns($values, $allowedColumns);
        $cols[] = 'user_id';
        $params[] = $userId;
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $colList = implode(', ', array_map(fn($c) => "`$c`", $cols));
        $sql = "INSERT INTO `$table` ($colList) VALUES ($placeholders)";

        if ($table === 'custom_field_entries') {
            // This table has a natural UNIQUE (custom_field_id, linked_to,
            // record_id, block_instance) key. The desktop app's local row id
            // isn't a reliable proxy for that identity (a local edit can end up
            // re-inserting under a new local id), so upsert on the real key
            // instead of a plain insert -- otherwise a genuine duplicate 500s.
            $sql .= " ON DUPLICATE KEY UPDATE field_value = VALUES(field_value), id = LAST_INSERT_ID(id)";
        }

        $newId = $db->insert($sql, $params);
        respond(['success' => true, 'id' => (int)$newId]);
    }

    if ($action === 'update') {
        $id = (int)($body['id'] ?? 0);
        // An UPDATE matching 0 rows still "succeeds" -- tell the client when
        // the row is gone (deleted here or by another device) so it can
        // re-insert instead of its edit silently vanishing. Checked up front
        // because rowCount() is also 0 when the values simply didn't change.
        $found = (bool)$db->fetch("SELECT id FROM `$table` WHERE id = ? AND user_id = ?", [$id, $userId]);
        if (!$found) {
            respond(['success' => true, 'found' => false]);
        }
        $values = is_array($body['values'] ?? null) ? $body['values'] : [];
        [$cols, $params] = filterColumns($values, $allowedColumns);
        if (empty($cols)) {
            respond(['success' => true, 'found' => true]);
        }
        $setClause = implode(', ', array_map(fn($c) => "`$c` = ?", $cols));
        $params[] = $id;
        $params[] = $userId;
        $db->update("UPDATE `$table` SET $setClause WHERE id = ? AND user_id = ?", $params);
        respond(['success' => true, 'found' => true]);
    }

    if ($action === 'delete') {
        $id = (int)($body['id'] ?? 0);
        // A deleted record takes its custom field values and Action Button
        // child records with it -- they used to be left behind as orphans
        // nothing could show. (Stored files are kept: this endpoint doesn't
        // load the Drive helper, and keeping a file is the safe side.)
        require_once __DIR__ . '/includes/fields_sync.php';
        if (in_array($table, FS_CORE_RECORD_TABLES, true)
            && $db->fetch("SELECT id FROM `$table` WHERE id = ? AND user_id = ?", [$id, $userId])) {
            feTransaction(function () use ($table, $id, $userId) {
                feDeleteRecordChildren($table, $id, $userId);
            });
        }
        $db->delete("DELETE FROM `$table` WHERE id = ? AND user_id = ?", [$id, $userId]);
        respond(['success' => true]);
    }

    // ---- pull direction: lets the desktop app pick up edits made here on
    // the web site, not just push its own changes out. ----

    if ($action === 'changes') {
        $since = isset($body['since']) && $body['since'] !== '' ? (string)$body['since'] : null;
        if ($since !== null) {
            $rows = $db->fetchAll(
                "SELECT * FROM `$table` WHERE user_id = ? AND updated_at > ? ORDER BY updated_at ASC, id ASC",
                [$userId, $since]
            );
        } else {
            $rows = $db->fetchAll(
                "SELECT * FROM `$table` WHERE user_id = ? ORDER BY updated_at ASC, id ASC",
                [$userId]
            );
        }
        // Only ever echo back id/updated_at plus this table's allowlisted
        // columns -- never leak a column that isn't in $allowedColumns.
        $filtered = array_map(function ($row) use ($allowedColumns) {
            $out = ['id' => (int)$row['id'], 'updated_at' => $row['updated_at']];
            foreach ($allowedColumns as $col) {
                if (array_key_exists($col, $row)) {
                    $out[$col] = $row[$col];
                }
            }
            return $out;
        }, $rows);
        respond(['success' => true, 'rows' => $filtered]);
    }

    if ($action === 'all_ids') {
        $rows = $db->fetchAll("SELECT id FROM `$table` WHERE user_id = ?", [$userId]);
        respond(['success' => true, 'ids' => array_map(fn($r) => (int)$r['id'], $rows)]);
    }

    respond(['success' => false, 'error' => 'Unknown action'], 400);
} catch (Exception $e) {
    respond(['success' => false, 'error' => substr($e->getMessage(), 0, 300)], 500);
}
