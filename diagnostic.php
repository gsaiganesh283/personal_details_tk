<?php
/**
 * Advanced Diagnostic Tool - Detailed Error Reporting
 * This version captures ALL errors and displays them
 */

// Maximum error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);

// Start output buffering to capture any output
ob_start();

?>
<!DOCTYPE html>
<html>
<head>
    <title>Advanced Diagnostic - Personal Details Saver</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #1a1a2e;
            color: #eee;
            padding: 20px;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { color: #6C63FF; margin: 20px 0; font-size: 28px; }
        h2 { color: #45B7D1; margin: 16px 0 12px; padding-bottom: 8px; border-bottom: 2px solid #45B7D1; }
        h3 { color: #4caf50; margin: 12px 0 8px; }
        .section { 
            background: #16213e;
            border: 1px solid #0f3460;
            border-radius: 8px;
            padding: 20px;
            margin: 16px 0;
        }
        .status-ok { color: #4caf50; font-weight: bold; }
        .status-error { color: #f44336; font-weight: bold; }
        .status-warning { color: #ff9800; font-weight: bold; }
        .status-info { color: #2196F3; font-weight: bold; }
        .detail { margin: 8px 0; padding: 10px; background: #0f3460; border-radius: 4px; border-left: 3px solid #45B7D1; }
        .error-detail { border-left-color: #f44336; background: rgba(244, 67, 54, 0.1); }
        code { 
            background: #0f3460;
            padding: 6px 10px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
            color: #4caf50;
            display: inline-block;
            margin: 2px 0;
            word-break: break-all;
        }
        pre {
            background: #0f3460;
            padding: 12px;
            border-radius: 4px;
            overflow-x: auto;
            color: #4caf50;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            border: 1px solid #2a5f8f;
            margin: 8px 0;
        }
        .alert { 
            padding: 12px;
            margin: 8px 0;
            border-radius: 4px;
            border-left: 4px solid;
        }
        .alert-success { border-color: #4caf50; background: rgba(76, 175, 80, 0.15); }
        .alert-error { border-color: #f44336; background: rgba(244, 67, 54, 0.15); }
        .alert-warning { border-color: #ff9800; background: rgba(255, 152, 0, 0.15); }
        .alert-info { border-color: #2196F3; background: rgba(33, 150, 243, 0.15); }
        table { width: 100%; border-collapse: collapse; margin: 12px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #0f3460; }
        th { background: #0f3460; color: #45B7D1; font-weight: bold; }
        tr:hover { background: #0f3460; }
        .summary { 
            padding: 15px;
            background: #0f3460;
            border-radius: 4px;
            margin: 12px 0;
        }
        .critical { color: #f44336; }
        .success { color: #4caf50; }
        ul { margin-left: 20px; margin-top: 8px; }
        li { margin: 4px 0; }
        .debug-section {
            background: #0f3460;
            padding: 10px;
            border-radius: 4px;
            margin: 8px 0;
            border-left: 3px solid #ff9800;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Advanced Diagnostic Tool</h1>
        <p style="color: #aaa; margin-bottom: 20px;">Comprehensive application diagnostic with detailed error reporting</p>

<?php

// =======================
// PART 1: ENVIRONMENT INFO
// =======================

echo '<div class="section">';
echo '<h2>📊 Environment Information</h2>';

echo '<div class="detail">';
echo '<strong>Server Info:</strong><br>';
echo '• OS: ' . php_uname() . '<br>';
echo '• PHP Version: ' . phpversion() . '<br>';
echo '• Server Name: ' . ($_SERVER['SERVER_NAME'] ?? 'unknown') . '<br>';
echo '• Server Port: ' . ($_SERVER['SERVER_PORT'] ?? 'unknown') . '<br>';
echo '• Script: ' . ($_SERVER['SCRIPT_NAME'] ?? 'unknown') . '<br>';
echo '• Document Root: ' . ($_SERVER['DOCUMENT_ROOT'] ?? 'unknown') . '<br>';
echo '• Current Directory: ' . getcwd() . '<br>';
echo '• Timezone: ' . date_default_timezone_get() . '<br>';
echo '</div>';

echo '<div class="detail">';
echo '<strong>Memory & Limits:</strong><br>';
echo '• Memory Limit: ' . ini_get('memory_limit') . '<br>';
echo '• Max Upload Size: ' . ini_get('upload_max_filesize') . '<br>';
echo '• Max POST Size: ' . ini_get('post_max_size') . '<br>';
echo '• Max Execution Time: ' . ini_get('max_execution_time') . 's<br>';
echo '• Display Errors: ' . (ini_get('display_errors') ? 'ON' : 'OFF') . '<br>';
echo '• Error Reporting: ' . error_reporting() . '<br>';
echo '</div>';

echo '</div>';

// =======================
// PART 2: FILE SYSTEM
// =======================

echo '<div class="section">';
echo '<h2>📁 File System Check</h2>';

$criticalFiles = [
    'config/database.php' => 'Database configuration',
    'includes/functions.php' => 'Helper functions',
    'includes/auth.php' => 'Authentication',
    'includes/header.php' => 'HTML header',
    'includes/footer.php' => 'HTML footer',
    'assets/js/app.js' => 'JavaScript',
    'assets/css/style.css' => 'CSS stylesheet',
    'login.php' => 'Login page',
    'dashboard.php' => 'Dashboard page',
];

$filesOk = true;
foreach ($criticalFiles as $file => $desc) {
    $path = __DIR__ . '/' . $file;
    $exists = file_exists($path);
    $readable = $exists ? is_readable($path) : false;
    $icon = $exists ? '✓' : '✗';
    $color = $exists ? 'status-ok' : 'status-error';
    
    if (!$exists) $filesOk = false;
    
    echo '<div class="detail">';
    echo '<span class="' . $color . '">' . $icon . '</span> ';
    echo '<strong>' . $file . '</strong> - ' . $desc;
    if (!$exists) {
        echo ' <span class="status-error">(MISSING!)</span>';
    } elseif (!$readable) {
        echo ' <span class="status-warning">(Not readable)</span>';
    }
    echo '</div>';
}

if (!$filesOk) {
    echo '<div class="alert alert-error">';
    echo '<span class="status-error">✗ CRITICAL:</span> Some critical files are missing!';
    echo '</div>';
}

echo '</div>';

// =======================
// PART 3: BOOTSTRAP TEST
// =======================

echo '<div class="section">';
echo '<h2>🚀 Application Bootstrap Test</h2>';

$bootstrapErrors = [];

// Test 3.1: Config
echo '<div class="detail"><strong>Loading config/database.php...</strong></div>';
try {
    if (!file_exists(__DIR__ . '/config/database.php')) {
        throw new Exception('File not found: ' . __DIR__ . '/config/database.php');
    }
    require_once __DIR__ . '/config/database.php';
    echo '<div class="alert alert-success">✓ Database config loaded</div>';
    echo '<div class="debug-section">';
    echo 'DB_HOST: <code>' . DB_HOST . '</code><br>';
    echo 'DB_NAME: <code>' . DB_NAME . '</code><br>';
    echo 'BASE_PATH: <code>' . (defined('BASE_PATH') ? BASE_PATH : 'NOT DEFINED') . '</code><br>';
    echo '</div>';
} catch (Exception $e) {
    echo '<div class="alert alert-error"><span class="status-error">✗</span> ' . $e->getMessage() . '</div>';
    $bootstrapErrors[] = $e->getMessage();
}

// Test 3.2: Functions include
echo '<div class="detail"><strong>Loading includes/functions.php...</strong></div>';
try {
    if (!file_exists(__DIR__ . '/includes/functions.php')) {
        throw new Exception('File not found: ' . __DIR__ . '/includes/functions.php');
    }
    require_once __DIR__ . '/includes/functions.php';
    echo '<div class="alert alert-success">✓ Functions loaded</div>';
    echo '<div class="debug-section">';
    echo 'url() exists: ' . (function_exists('url') ? '✓' : '✗') . '<br>';
    echo 'redirect() exists: ' . (function_exists('redirect') ? '✓' : '✗') . '<br>';
    echo 'sanitize() exists: ' . (function_exists('sanitize') ? '✓' : '✗') . '<br>';
    echo '</div>';
} catch (Exception $e) {
    echo '<div class="alert alert-error"><span class="status-error">✗</span> ' . $e->getMessage() . '</div>';
    $bootstrapErrors[] = $e->getMessage();
}

// Test 3.3: Auth include
echo '<div class="detail"><strong>Loading includes/auth.php...</strong></div>';
try {
    if (!file_exists(__DIR__ . '/includes/auth.php')) {
        throw new Exception('File not found: ' . __DIR__ . '/includes/auth.php');
    }
    require_once __DIR__ . '/includes/auth.php';
    echo '<div class="alert alert-success">✓ Auth loaded</div>';
    echo '<div class="debug-section">';
    echo 'Auth class exists: ' . (class_exists('Auth') ? '✓' : '✗') . '<br>';
    echo 'Database class exists: ' . (class_exists('Database') ? '✓' : '✗') . '<br>';
    echo '</div>';
} catch (Exception $e) {
    echo '<div class="alert alert-error"><span class="status-error">✗</span> ' . $e->getMessage() . '</div>';
    $bootstrapErrors[] = $e->getMessage();
}

// Test 3.4: Database connection
echo '<div class="detail"><strong>Testing database connection...</strong></div>';
try {
    if ($bootstrapErrors) {
        echo '<div class="alert alert-warning">⚠ Skipping DB connection test due to bootstrap errors</div>';
    } else {
        $db = Database::getInstance();
        echo '<div class="alert alert-success">✓ Database connected!</div>';
        echo '<div class="debug-section">';
        
        // Test a simple query
        $result = @$db->fetch("SELECT 1 as test");
        if ($result) {
            echo '✓ Can execute queries<br>';
        } else {
            echo '✗ Cannot execute queries<br>';
        }
        
        // Check tables
        try {
            $result = @$db->fetch(
                "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? LIMIT 1",
                [DB_NAME]
            );
            if ($result) {
                echo '✓ Can query table info<br>';
            }
        } catch (Exception $e) {
            echo '⚠ Cannot query information_schema<br>';
        }
        
        echo '</div>';
    }
} catch (Exception $e) {
    echo '<div class="alert alert-error"><span class="status-error">✗</span> ' . htmlspecialchars($e->getMessage()) . '</div>';
    echo '<div class="debug-section error-detail">';
    echo 'Error Details:<br>';
    echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
    echo '</div>';
    $bootstrapErrors[] = $e->getMessage();
}

echo '</div>';

// =======================
// PART 4: WHAT TO DO NEXT
// =======================

echo '<div class="section">';
echo '<h2>📋 Next Steps</h2>';

if (empty($bootstrapErrors)) {
    echo '<div class="alert alert-success">';
    echo '<span class="success">✓ All checks passed!</span><br>';
    echo 'Your application bootstrap is working correctly.';
    echo '<br><br><strong>Next steps:</strong>';
    echo '<ul>';
    echo '<li>Try accessing <a href="./login.php" style="color: #6C63FF; text-decoration: underline;">/login.php</a></li>';
    echo '<li>If blank page appears, check browser console (F12) for JavaScript errors</li>';
    echo '<li>Run <a href="./setup.php" style="color: #6C63FF; text-decoration: underline;">/setup.php</a> to create database tables if needed</li>';
    echo '</ul>';
    echo '</div>';
} else {
    echo '<div class="alert alert-error">';
    echo '<span class="critical">✗ Fixes required:</span><br>';
    echo '<ul>';
    foreach ($bootstrapErrors as $error) {
        echo '<li>' . htmlspecialchars($error) . '</li>';
    }
    echo '</ul>';
    echo '<br><strong>To fix:</strong>';
    echo '<ol>';
    echo '<li>Ensure all files are uploaded correctly</li>';
    echo '<li>Check file permissions (must be readable)</li>';
    echo '<li>Verify config/database.php has correct credentials</li>';
    echo '<li>Clear website cache and browser cache (Ctrl+Shift+Delete)</li>';
    echo '</ol>';
    echo '</div>';
}

echo '</div>';

// =======================
// PART 5: DEBUG INFO
// =======================

echo '<div class="section">';
echo '<h2>🐛 Debug Information</h2>';

echo '<h3>PHP Extensions</h3>';
$extensions = ['pdo', 'mysqli', 'json', 'curl', 'gd', 'mbstring', 'openssl'];
foreach ($extensions as $ext) {
    $loaded = extension_loaded($ext);
    $icon = $loaded ? '✓' : '!';
    $color = $loaded ? 'status-ok' : 'status-warning';
    echo '<span class="' . $color . '">' . $icon . ' ' . strtoupper($ext) . '</span> ';
}
echo '<br><br>';

echo '<h3>PHP Constants</h3>';
echo '<pre>';
echo 'PHP_OS: ' . PHP_OS . PHP_EOL;
echo 'PHP_INT_MAX: ' . PHP_INT_MAX . PHP_EOL;
echo 'PATH_SEPARATOR: ' . PATH_SEPARATOR . PHP_EOL;
echo 'DIRECTORY_SEPARATOR: ' . DIRECTORY_SEPARATOR . PHP_EOL;
echo 'PHP_EOL: ' . (PHP_EOL === "\n" ? '\n' : (PHP_EOL === "\r\n" ? '\r\n' : 'other')) . PHP_EOL;
echo '</pre>';

echo '</div>';

// =======================
// FOOTER
// =======================

echo '<div class="section" style="text-align: center; color: #888;">';
echo '<p>This diagnostic page is for troubleshooting only.</p>';
echo '<p style="font-size: 12px; margin-top: 10px;">Remove this file after diagnosing the issue for security.</p>';
echo '</div>';

?>

    </div>
</body>
</html>
