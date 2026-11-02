<?php
/**
 * Diagnostic & Debugging Tool
 * Visit: /debug.php
 * 
 * Shows current system state, configuration, and common issues
 */

// Show all errors for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if we can load database config
$configExists = file_exists(__DIR__ . '/config/database.php');

if (!$configExists) {
    echo "<h1>Error: Database config not found</h1>";
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

?>
<!DOCTYPE html>
<html>
<head>
    <title>Diagnostic Tool - Personal Details Saver</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #1a1a2e;
            color: #eee;
            padding: 20px;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { color: #6C63FF; margin: 20px 0; }
        h2 { color: #45B7D1; margin: 16px 0 12px; padding-bottom: 8px; border-bottom: 2px solid #45B7D1; }
        .section { 
            background: #16213e;
            border: 1px solid #0f3460;
            border-radius: 8px;
            padding: 20px;
            margin: 16px 0;
        }
        .status-ok { color: #4caf50; }
        .status-error { color: #f44336; }
        .status-warning { color: #ff9800; }
        .status-info { color: #2196F3; }
        .detail { margin: 8px 0; padding: 8px; background: #0f3460; border-radius: 4px; }
        .detail strong { color: #45B7D1; }
        code { 
            background: #0f3460;
            padding: 4px 8px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
            color: #4caf50;
        }
        table { width: 100%; border-collapse: collapse; margin: 12px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #0f3460; }
        th { background: #0f3460; color: #45B7D1; font-weight: bold; }
        tr:hover { background: #0f3460; }
        .alert { 
            padding: 12px;
            margin: 8px 0;
            border-radius: 4px;
            border-left: 4px solid;
        }
        .alert-success { border-color: #4caf50; background: rgba(76, 175, 80, 0.1); }
        .alert-error { border-color: #f44336; background: rgba(244, 67, 54, 0.1); }
        .alert-warning { border-color: #ff9800; background: rgba(255, 152, 0, 0.1); }
        .alert-info { border-color: #2196F3; background: rgba(33, 150, 243, 0.1); }
        .copy-btn { 
            float: right;
            padding: 4px 12px;
            background: #6C63FF;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .copy-btn:hover { background: #5a52d5; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Personal Details Saver - Diagnostic Tool</h1>
        
        <?php
        $issues = [];
        $warnings = [];
        $passed = [];
        
        // ======================
        // 1. PHP VERSION
        // ======================
        echo '<div class="section">';
        echo '<h2>PHP Environment</h2>';
        
        $phpVersion = phpversion();
        echo '<div class="detail"><strong>PHP Version:</strong> ' . $phpVersion . '</div>';
        
        if (version_compare($phpVersion, '7.2.0', '>=')) {
            echo '<div class="alert alert-success"><span class="status-ok">✓</span> PHP version is compatible</div>';
            $passed[] = 'PHP version';
        } else {
            echo '<div class="alert alert-error"><span class="status-error">✗</span> PHP 7.2+ required, you have ' . $phpVersion . '</div>';
            $issues[] = 'PHP version too old';
        }
        
        // Extensions
        $extensions = ['pdo', 'mysqli', 'json', 'curl', 'gd'];
        echo '<div class="detail"><strong>Required Extensions:</strong></div>';
        foreach ($extensions as $ext) {
            $loaded = extension_loaded($ext);
            $status = $loaded ? '<span class="status-ok">✓</span>' : '<span class="status-error">✗</span>';
            echo '<div style="margin-left: 20px; margin-top: 4px;">' . $status . ' ' . strtoupper($ext) . '</div>';
            if (!$loaded) $issues[] = "Extension $ext not loaded";
            else $passed[] = "Extension $ext";
        }
        echo '</div>';
        
        // ======================
        // 2. DATABASE CONNECTION
        // ======================
        echo '<div class="section">';
        echo '<h2>Database Configuration</h2>';
        
        echo '<table>';
        echo '<tr><th>Setting</th><th>Value</th><th>Status</th></tr>';
        echo '<tr><td>DB Host</td><td><code>' . DB_HOST . '</code></td><td><span class="status-info">ℹ</span></td></tr>';
        echo '<tr><td>DB Name</td><td><code>' . DB_NAME . '</code></td><td><span class="status-info">ℹ</span></td></tr>';
        echo '<tr><td>DB User</td><td><code>' . DB_USER . '</code></td><td><span class="status-info">ℹ</span></td></tr>';
        echo '<tr><td>Base Path</td><td><code>' . (defined('BASE_PATH') ? BASE_PATH : '(empty)') . '</code></td><td><span class="status-info">ℹ</span></td></tr>';
        echo '</table>';
        
        // Try to connect
        try {
            $db = Database::getInstance();
            echo '<div class="alert alert-success"><span class="status-ok">✓</span> Database connection successful</div>';
            $passed[] = 'Database connection';
            
            // Check tables
            echo '<div class="detail"><strong>Required Tables:</strong></div>';
            $requiredTables = [
                'users', 'personal_details', 'bank_details', 'documents',
                'aadhaar_details', 'pan_details', 'other_details',
                'custom_fields', 'custom_field_blocks', 'custom_field_entries',
                'custom_field_values', 'activity_log', 'user_sessions'
            ];
            
            $missingTables = [];
            foreach ($requiredTables as $table) {
                $result = $db->fetch(
                    "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?",
                    [DB_NAME, $table]
                );
                $icon = $result ? '<span class="status-ok">✓</span>' : '<span class="status-error">✗</span>';
                echo '<div style="margin-left: 20px; margin-top: 4px;">' . $icon . ' ' . $table . '</div>';
                if (!$result) {
                    $missingTables[] = $table;
                    $issues[] = "Table $table missing";
                } else {
                    $passed[] = "Table $table";
                }
            }
            
            if (empty($missingTables)) {
                echo '<div class="alert alert-success"><span class="status-ok">✓</span> All required tables exist</div>';
            } else {
                echo '<div class="alert alert-error"><span class="status-error">✗</span> Missing ' . count($missingTables) . ' table(s). Run <code>/setup.php</code> to create them.</div>';
            }
            
        } catch (Exception $e) {
            echo '<div class="alert alert-error"><span class="status-error">✗</span> Database connection failed: ' . $e->getMessage() . '</div>';
            $issues[] = 'Database connection: ' . $e->getMessage();
        }
        echo '</div>';
        
        // ======================
        // 3. FILE SYSTEM
        // ======================
        echo '<div class="section">';
        echo '<h2>File System</h2>';
        
        $dirs = [
            'uploads' => __DIR__ . '/uploads',
            'assets' => __DIR__ . '/assets',
            'config' => __DIR__ . '/config',
            'includes' => __DIR__ . '/includes',
            'sql' => __DIR__ . '/sql',
        ];
        
        foreach ($dirs as $name => $path) {
            $exists = is_dir($path);
            $writable = $exists ? is_writable($path) : false;
            $icon = $exists ? '<span class="status-ok">✓</span>' : '<span class="status-error">✗</span>';
            $writeIcon = $writable ? '<span class="status-ok">✓</span>' : '<span class="status-warning">!</span>';
            
            echo '<div class="detail">' . $icon . ' <strong>' . $name . ':</strong> ' . ($exists ? 'exists' : 'missing');
            if ($exists) echo ' | Writable: ' . $writeIcon;
            echo '</div>';
            
            if (!$exists) $issues[] = "Directory $name missing";
            if ($exists && !$writable) $warnings[] = "Directory $name not writable";
        }
        
        echo '</div>';
        
        // ======================
        // 4. CONFIGURATION
        // ======================
        echo '<div class="section">';
        echo '<h2>Application Configuration</h2>';
        
        $configs = [
            'APP_NAME' => APP_NAME,
            'APP_VERSION' => APP_VERSION,
            'APP_TIMEZONE' => APP_TIMEZONE,
            'MAX_UPLOAD_SIZE' => MAX_UPLOAD_SIZE . ' bytes (' . (MAX_UPLOAD_SIZE / 1024 / 1024) . ' MB)',
        ];
        
        foreach ($configs as $key => $value) {
            echo '<div class="detail"><strong>' . $key . ':</strong> <code>' . $value . '</code></div>';
        }
        echo '</div>';
        
        // ======================
        // 5. SESSION & AUTH
        // ======================
        echo '<div class="section">';
        echo '<h2>Session & Authentication</h2>';
        
        if (session_status() === PHP_SESSION_ACTIVE) {
            echo '<div class="alert alert-success"><span class="status-ok">✓</span> Session is active</div>';
            $passed[] = 'Session active';
        } else if (session_status() === PHP_SESSION_NONE) {
            echo '<div class="alert alert-warning"><span class="status-warning">!</span> Session not started (normal for unauthenticated pages)</div>';
        }
        
        echo '<div class="detail"><strong>Session Configuration:</strong></div>';
        echo '<div style="margin-left: 20px;">';
        echo '• Session lifetime: ' . SESSION_LIFETIME . ' seconds<br>';
        echo '• Remember me lifetime: ' . REMEMBER_ME_LIFETIME . ' seconds<br>';
        echo '</div>';
        echo '</div>';
        
        // ======================
        // SUMMARY
        // ======================
        echo '<div class="section">';
        echo '<h2>Diagnostic Summary</h2>';
        
        if (empty($issues)) {
            echo '<div class="alert alert-success"><span class="status-ok">✓ All Critical Systems OK</span></div>';
        } else {
            echo '<div class="alert alert-error"><span class="status-error">✗ ' . count($issues) . ' Critical Issue(s) Found</span></div>';
            echo '<div style="background: #0f3460; padding: 12px; border-radius: 4px; margin-top: 8px;">';
            foreach ($issues as $issue) {
                echo '• ' . $issue . '<br>';
            }
            echo '</div>';
        }
        
        if (!empty($warnings)) {
            echo '<div class="alert alert-warning" style="margin-top: 12px;"><span class="status-warning">!</span> ' . count($warnings) . ' Warning(s)</div>';
            echo '<div style="background: #0f3460; padding: 12px; border-radius: 4px; margin-top: 8px;">';
            foreach ($warnings as $warning) {
                echo '• ' . $warning . '<br>';
            }
            echo '</div>';
        }
        
        echo '<div style="margin-top: 16px;">';
        echo '<strong>Checks Passed:</strong> ' . count($passed) . '<br>';
        echo '<strong>Critical Issues:</strong> ' . count($issues) . '<br>';
        echo '<strong>Warnings:</strong> ' . count($warnings) . '<br>';
        echo '</div>';
        
        echo '</div>';
        
        // ======================
        // NEXT STEPS
        // ======================
        echo '<div class="section">';
        echo '<h2>Next Steps</h2>';
        
        if (!empty($issues)) {
            echo '<ol style="margin-left: 20px;">';
            foreach ($issues as $issue) {
                echo '<li>Fix: ' . $issue . '</li>';
            }
            echo '</ol>';
        }
        
        echo '<div class="alert alert-info">';
        echo '<strong>Useful Links:</strong><br>';
        echo '• <a href="setup.php" style="color: #45B7D1; text-decoration: none;">Database Setup Tool</a><br>';
        echo '• <a href="login.php" style="color: #45B7D1; text-decoration: none;">Login Page</a><br>';
        echo '• <a href="register.php" style="color: #45B7D1; text-decoration: none;">Register Page</a><br>';
        echo '</div>';
        
        echo '</div>';
        ?>
        
        <div class="section" style="margin-top: 40px; border-top: 2px solid #6C63FF; padding-top: 20px;">
            <p style="color: #999; font-size: 0.9rem;">
                Diagnostic Tool | Personal Details Saver<br>
                For security, remove or password-protect this page after debugging.
            </p>
        </div>
    </div>
</body>
</html>
