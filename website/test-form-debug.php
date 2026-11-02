<?php
/**
 * Personal Details Form Debugger - Simple Version
 * Debugs the form submission and data insertion process
 */

// Start output buffering to catch any errors before they break HTML
ob_start();

// Enable error display
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    $pageTitle = 'Form Debugger';
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/includes/auth.php';
    require_once __DIR__ . '/includes/functions.php';

    $user = requireAuth();
    $db = Database::getInstance();
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    die("Error during initialization: " . htmlspecialchars($e->getMessage()));
}

// Simulate form submission if requested
$testSubmit = $_GET['test_submit'] ?? false;
$results = [];

// Check 1: Verify table exists
try {
    $result = $db->fetch("SELECT COUNT(*) as cnt FROM personal_details WHERE user_id = ?", [$user['id']]);
    $results['table_check'] = [
        'status' => 'success',
        'message' => 'Table exists and is accessible',
        'record_count' => $result['cnt'] ?? 0
    ];
} catch (Exception $e) {
    $results['table_check'] = [
        'status' => 'error',
        'message' => 'Table error: ' . $e->getMessage()
    ];
}

// Check 2: Verify insert capability
if ($testSubmit) {
    try {
        $testData = [
            $user['id'],          // user_id
            'Debug',              // first_name
            'Test-' . time(),     // last_name
            null,                 // date_of_birth
            null,                 // gender
            '9999999999',         // phone
            '',                   // alternate_phone
            'debug@test.com',     // email
            'Test Address',       // address_line1
            '',                   // address_line2
            'Test City',          // city
            'Test State',         // state
            'Test Country',       // country
            '000000',             // zip_code
            '',                   // nationality
            'Debugger',           // occupation
            'Test Corp',          // company
            'Auto-generated test record'  // notes
        ];
        
        $insertId = $db->insert(
            "INSERT INTO personal_details (user_id, first_name, last_name, date_of_birth, gender, phone, 
             alternate_phone, email, address_line1, address_line2, city, state, country, zip_code, 
             nationality, occupation, company, notes) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            $testData
        );
        
        $results['insert_test'] = [
            'status' => 'success',
            'message' => 'Successfully inserted test record',
            'record_id' => $insertId
        ];
    } catch (Exception $e) {
        $results['insert_test'] = [
            'status' => 'error',
            'message' => $e->getMessage()
        ];
    }
}

// Check 3: Verify data can be retrieved
try {
    $count = $db->fetch("SELECT COUNT(*) as count FROM personal_details WHERE user_id = ?", [$user['id']]);
    $latest = $db->fetch("SELECT * FROM personal_details WHERE user_id = ? ORDER BY created_at DESC LIMIT 1", [$user['id']]);
    
    $results['retrieve_test'] = [
        'status' => 'success',
        'total_records' => $count['count'],
        'latest_record' => $latest ? [
            'id' => $latest['id'],
            'name' => $latest['first_name'] . ' ' . $latest['last_name'],
            'created_at' => $latest['created_at']
        ] : null
    ];
} catch (Exception $e) {
    $results['retrieve_test'] = [
        'status' => 'error',
        'message' => $e->getMessage()
    ];
}

ob_end_clean();
?>

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Form Debugger</title>
    <link rel="stylesheet" href="<?php echo url('assets/css/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .debug-section { margin: 20px 0; padding: 16px; border: 1px solid var(--border); border-radius: 8px; background: var(--bg-secondary); }
        .debug-success { border-left: 4px solid var(--success); }
        .debug-error { border-left: 4px solid var(--danger); }
        .debug-info { border-left: 4px solid var(--info); }
        .code-block { background: #1e1e1e; color: #d4d4d4; padding: 12px; border-radius: 4px; overflow-x: auto; margin: 10px 0; font-family: 'Courier New', monospace; font-size: 0.85rem; }
        .status-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 600; }
        .status-success { background: var(--success); color: white; }
        .status-error { background: var(--danger); color: white; }
    </style>
</head>
<body style="background: var(--bg); color: var(--text);">
<div style="max-width: 900px; margin: 0 auto; padding: 20px;">
    <h1 style="margin-bottom: 12px;"><i class="fas fa-wrench"></i> Form Submission Debugger</h1>
    <p style="color: var(--text-secondary); margin-bottom: 20px;">Testing personal details form functionality</p>

    <?php foreach ($results as $key => $result): ?>
    <div class="debug-section debug-<?php echo $result['status']; ?>">
        <h3 style="margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
            <span class="status-badge status-<?php echo $result['status']; ?>">
                <?php echo $result['status']; ?>
            </span>
            <?php echo ucwords(str_replace('_', ' ', $key)); ?>
        </h3>
        
        <?php if (isset($result['message'])): ?>
            <p style="margin: 8px 0;"><?php echo htmlspecialchars($result['message']); ?></p>
        <?php endif; ?>
        
        <?php if (isset($result['columns'])): ?>
            <p style="margin: 8px 0;"><strong>Columns found:</strong> <?php echo $result['columns']; ?></p>
        <?php endif; ?>
        
        <?php if (isset($result['total_records'])): ?>
            <p style="margin: 8px 0;"><strong>Your records:</strong> <?php echo $result['total_records']; ?></p>
            <?php if ($result['latest_record']): ?>
                <div class="code-block">
                    <?php echo htmlspecialchars(json_encode($result['latest_record'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <?php if (isset($result['record_id'])): ?>
            <p style="margin: 8px 0;"><strong>New record ID:</strong> <?php echo $result['record_id']; ?></p>
        <?php endif; ?>
        
        <?php if (isset($result['query'])): ?>
            <p style="margin: 8px 0;"><strong>Query:</strong></p>
            <div class="code-block"><?php echo htmlspecialchars($result['query']); ?></div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <div class="debug-section debug-info">
        <h3>Quick Actions</h3>
        <div style="display: flex; gap: 12px;">
            <a href="<?php echo url('personal-details.php'); ?>" class="btn btn-primary">
                <i class="fas fa-list"></i> Go to Personal Details
            </a>
            <a href="<?php echo url('test-form-submission.php'); ?>" class="btn btn-secondary">
                <i class="fas fa-flask"></i> Test Form Submission
            </a>
            <?php if (!$testSubmit): ?>
                <a href="?test_submit=1" class="btn btn-warning">
                    <i class="fas fa-database"></i> Test Insert Record
                </a>
            <?php else: ?>
                <a href="?" class="btn btn-warning">
                    <i class="fas fa-redo"></i> Refresh
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="debug-section debug-info">
        <h3>User Information</h3>
        <p><strong>User ID:</strong> <?php echo $user['id']; ?></p>
        <p><strong>Username:</strong> <?php echo htmlspecialchars($user['username']); ?></p>
        <p><strong>BASE_PATH:</strong> <code><?php echo htmlspecialchars(BASE_PATH ?: '(empty/root)'); ?></code></p>
    </div>

</div>
</body>
</html>
