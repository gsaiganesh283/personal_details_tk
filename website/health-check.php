<?php
/**
 * Quick Health Check
 * Visit: https://your-domain.com/health-check.php
 */

// Minimal dependencies - just check the basics
error_reporting(E_ALL);
ini_set('display_errors', 1);

$status = [
    'timestamp' => date('Y-m-d H:i:s'),
    'domain' => $_SERVER['HTTP_HOST'] ?? 'unknown',
    'php_version' => phpversion(),
    'checks' => []
];

// Check 1: Config file
$configFile = __DIR__ . '/config/database.php';
if (file_exists($configFile)) {
    $status['checks'][] = ['name' => 'Config File', 'status' => '✓', 'message' => 'config/database.php exists'];
    
    // Try to include it
    try {
        require_once $configFile;
        require_once __DIR__ . '/includes/functions.php';
        if (defined('DB_HOST')) {
            $status['checks'][] = ['name' => 'DB Configuration', 'status' => '✓', 'message' => 'Database config loaded'];
            $status['config'] = [
                'host' => DB_HOST,
                'database' => DB_NAME,
                'user' => DB_USER,
                'basepath' => (defined('BASE_PATH') ? BASE_PATH : 'empty')
            ];
        }
    } catch (Exception $e) {
        $status['checks'][] = ['name' => 'Config Load', 'status' => '✗', 'message' => $e->getMessage()];
    }
} else {
    $status['checks'][] = ['name' => 'Config File', 'status' => '✗', 'message' => 'config/database.php NOT FOUND'];
}

// Check 2: Database Connection
if (class_exists('Database')) {
    try {
        $db = Database::getInstance();
        $result = $db->fetch("SELECT 1 as test");
        if ($result) {
            $status['checks'][] = ['name' => 'Database Connection', 'status' => '✓', 'message' => 'Connected successfully'];
            
            // Check tables
            $tables = $db->fetchAll(
                "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?",
                [DB_NAME]
            );
            $status['database'] = [
                'connected' => true,
                'table_count' => count($tables),
                'tables' => array_column($tables, 'TABLE_NAME')
            ];
        }
    } catch (Exception $e) {
        $status['checks'][] = ['name' => 'Database Connection', 'status' => '✗', 'message' => $e->getMessage()];
        $status['database'] = ['connected' => false, 'error' => $e->getMessage()];
    }
} else {
    $status['checks'][] = ['name' => 'Database Class', 'status' => '✗', 'message' => 'Database class not found'];
}

// Check 3: Critical Files
$requiredFiles = [
    'includes/functions.php',
    'includes/auth.php',
    'includes/header.php',
    'includes/footer.php',
    'includes/custom_fields_helper.php',
    'assets/css/style.css',
    'assets/js/app.js'
];

foreach ($requiredFiles as $file) {
    $fullPath = __DIR__ . '/' . $file;
    if (file_exists($fullPath)) {
        $status['checks'][] = ['name' => "File: $file", 'status' => '✓'];
    } else {
        $status['checks'][] = ['name' => "File: $file", 'status' => '✗', 'message' => 'NOT FOUND'];
    }
}

// Check 4: Functions
if (function_exists('url')) {
    $status['checks'][] = ['name'=> 'url() function', 'status' => '✓'];
    $status['url_test'] = url('test.php');
} else {
    $status['checks'][] = ['name' => 'url() function', 'status' => '✗', 'message' => 'Function not defined'];
}

// Output as JSON for API or pretty HTML for browser
$wantJson = isset($_GET['json']);

if ($wantJson || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
    header('Content-Type: application/json');
    echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} else {
    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html>
<head>
    <title>Health Check - Personal Details Saver</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; }
        h1 { color: #333; }
        .check { padding: 12px; margin: 8px 0; background: white; border-left: 4px solid #ddd; border-radius: 4px; }
        .check.pass { border-left-color: #4caf50; background: #f1f8f6; }
        .check.fail { border-left-color: #f44336; background: #ffebee; }
        .check-name { font-weight: bold; color: #333; }
        .check-message { color: #666; font-size: 0.9em; margin-top: 4px; }
        .status { font-size: 1.2em; margin-right: 8px; }
        .section { background: white; padding: 16px; margin: 16px 0; border-radius: 4px; border: 1px solid #ddd; }
        .section h2 { margin-top: 0; color: #6C63FF; }
        table { width: 100%; border-collapse: collapse; }
        table td { padding: 8px; border-bottom: 1px solid #eee; }
        table strong { color: #333; }
        code { background: #f9f9f9; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🏥 Health Check Report</h1>
        <p><strong>Timestamp:</strong> <?php echo htmlspecialchars($status['timestamp']); ?></p>
        <p><strong>Domain:</strong> <?php echo htmlspecialchars($status['domain']); ?></p>
        <p><strong>PHP Version:</strong> <?php echo htmlspecialchars($status['php_version']); ?></p>

        <div class="section">
            <h2>System Checks</h2>
            <?php foreach ($status['checks'] as $check): ?>
                <div class="check <?php echo $check['status'] === '✓' ? 'pass' : 'fail'; ?>">
                    <div class="check-name">
                        <span class="status"><?php echo $check['status']; ?></span>
                        <?php echo htmlspecialchars($check['name']); ?>
                    </div>
                    <?php if (isset($check['message'])): ?>
                        <div class="check-message"><?php echo htmlspecialchars($check['message']); ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (isset($status['config'])): ?>
        <div class="section">
            <h2>Configuration</h2>
            <table>
                <tr><td><strong>Host:</strong></td><td><code><?php echo htmlspecialchars($status['config']['host']); ?></code></td></tr>
                <tr><td><strong>Database:</strong></td><td><code><?php echo htmlspecialchars($status['config']['database']); ?></code></td></tr>
                <tr><td><strong>User:</strong></td><td><code><?php echo htmlspecialchars($status['config']['user']); ?></code></td></tr>
                <tr><td><strong>BASE_PATH:</strong></td><td><code><?php echo htmlspecialchars($status['config']['basepath'] ?: '(empty)'); ?></code></td></tr>
            </table>
        </div>
        <?php endif; ?>

        <?php if (isset($status['database'])): ?>
        <div class="section">
            <h2>Database</h2>
            <?php if ($status['database']['connected']): ?>
                <p style="color: #4caf50;"><strong>✓ Connected</strong></p>
                <p><strong><?php echo $status['database']['table_count']; ?> tables found:</strong></p>
                <code style="display: block; padding: 12px; background: #f9f9f9; border-radius: 4px; overflow-x: auto;">
                    <?php echo htmlspecialchars(implode(', ', $status['database']['tables'])); ?>
                </code>
            <?php else: ?>
                <p style="color: #f44336;"><strong>✗ Not Connected</strong></p>
                <p><?php echo htmlspecialchars($status['database']['error'] ?? 'Unknown error'); ?></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (isset($status['url_test'])): ?>
        <div class="section">
            <h2>URL Generation Test</h2>
            <p><code>url('test.php')</code> generates:</p>
            <code style="display: block; padding: 12px; background: #f9f9f9; border-radius: 4px;"><?php echo htmlspecialchars($status['url_test']); ?></code>
        </div>
        <?php endif; ?>

        <div class="section">
            <h2>Next Steps</h2>
            <ul>
                <li><a href="login.php">Go to Login Page</a></li>
                <li><a href="health-check.php?json=1">View as JSON</a></li>
                <li><a href="blank-page-fixer.php">Run Blank Page Fixer</a></li>
                <li><a href="setup.php">Run Setup (Create Tables)</a></li>
            </ul>
        </div>
    </div>
</body>
</html>
    <?php
}
?>
