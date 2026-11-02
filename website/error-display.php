<?php
/**
 * Error Display Tool
 * This page shows PHP errors that might be hidden
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Set up error file logging
$errorLogFile = __DIR__ . '/php-errors.log';
ini_set('error_log', $errorLogFile);
ini_set('log_errors', 1);

?>
<!DOCTYPE html>
<html>
<head>
    <title>Error Display - Personal Details Saver</title>
    <style>
        body {
            font-family: 'Courier New', monospace;
            background: #1a1a2e;
            color: #eee;
            padding: 20px;
            margin: 0;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: #16213e;
            border: 1px solid #0f3460;
            border-radius: 8px;
            padding: 20px;
        }
        h1 {
            color: #f44336;
            margin-top: 0;
        }
        .section {
            background: #0f3460;
            padding: 15px;
            border-radius: 4px;
            margin: 15px 0;
            border-left: 3px solid #2196F3;
        }
        .error-section {
            border-left-color: #f44336;
        }
        code {
            background: #000;
            padding: 2px 6px;
            border-radius: 3px;
            color: #4caf50;
        }
        pre {
            background: #000;
            padding: 12px;
            border-radius: 4px;
            overflow-x: auto;
            color: #4caf50;
        }
        a {
            color: #6C63FF;
            text-decoration: none;
        }
        a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔴 PHP Error Display</h1>
        
        <div class="section">
            <h2>This page displays PHP errors that might be hidden</h2>
            <p>If you see this page but no errors below, it means:<br>
            • Application is loading correctly, or<br>
            • No PHP errors occurred during this page load</p>
        </div>

        <?php
        
        // Try loading the diagnostic first
        echo '<div class="section">';
        echo '<h2>Attempting to load Application...</h2>';
        
        try {
            echo '<p>Loading config...</p>';
            require_once __DIR__ . '/config/database.php';
            echo '<p style="color: #4caf50;">✓ Config loaded</p>';
            
            echo '<p>Loading functions...</p>';
            require_once __DIR__ . '/includes/functions.php';
            echo '<p style="color: #4caf50;">✓ Functions loaded</p>';
            
            echo '<p>Loading auth...</p>';
            require_once __DIR__ . '/includes/auth.php';
            echo '<p style="color: #4caf50;">✓ Auth loaded</p>';
            
            echo '<p>Connecting to database...</p>';
            $db = Database::getInstance();
            echo '<p style="color: #4caf50;">✓ Database connected</p>';
            
            echo '<p>Testing simple query...</p>';
            $test = @$db->fetch("SELECT 1 as test");
            if ($test) {
                echo '<p style="color: #4caf50;">✓ Queries working</p>';
            } else {
                echo '<p style="color: #ff9800;">⚠ Queries not working</p>';
            }
            
            echo '<p style="color: #4caf50; font-weight: bold; margin-top: 10px;">
                ✓✓✓ Application loaded successfully! ✓✓✓
                </p>';
            echo '<p>If you see this, try accessing: <a href="./login.php">/login.php</a></p>';
            
        } catch (Throwable $e) {
            echo '<div class="section error-section">';
            echo '<h2 style="color: #f44336;">ERROR CAUGHT</h2>';
            echo '<p><strong>Error Type:</strong> ' . get_class($e) . '</p>';
            echo '<p><strong>Message:</strong></p>';
            echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
            echo '<p><strong>File:</strong> ' . htmlspecialchars($e->getFile()) . '</p>';
            echo '<p><strong>Line:</strong> ' . $e->getLine() . '</p>';
            echo '<p><strong>Trace:</strong></p>';
            echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
            echo '</div>';
        }
        
        echo '</div>';
        
        // Check error log file
        if (file_exists($errorLogFile)) {
            echo '<div class="section error-section">';
            echo '<h2 style="color: #f44336;">Error Log</h2>';
            echo '<p>File: <code>' . $errorLogFile . '</code></p>';
            $logContent = file_get_contents($errorLogFile);
            if (!empty(trim($logContent))) {
                echo '<p><strong>Contents (last 50 lines):</strong></p>';
                $lines = explode("\n", $logContent);
                $lines = array_slice($lines, -50);
                echo '<pre>' . htmlspecialchars(implode("\n", $lines)) . '</pre>';
            } else {
                echo '<p style="color: #4caf50;">No errors logged yet</p>';
            }
            echo '</div>';
        }
        
        ?>

        <div class="section">
            <h2>📋 Diagnostic Quick Links</h2>
            <ul style="margin: 10px 0; padding-left: 20px;">
                <li><a href="./diagnostic.php">→ Advanced Diagnostic Tool</a></li>
                <li><a href="./debug.php">→ System Debug Tool</a></li>
                <li><a href="./setup.php">→ Database Setup Tool</a></li>
                <li><a href="./test-startup.php">→ Startup Test</a></li>
            </ul>
        </div>

        <div class="section">
            <h2>📖 Documentation</h2>
            <ul style="margin: 10px 0; padding-left: 20px;">
                <li><a href="./BLANK_PAGE_TROUBLESHOOTING.md">→ Blank Page Troubleshooting</a> (view as text)</li>
                <li><a href="./APPLICATION_NOT_OPENING_FIX.md">→ Application Not Opening Fix</a></li>
                <li><a href="./DEBUGGING_GUIDE.md">→ Comprehensive Debugging Guide</a></li>
            </ul>
        </div>

        <div class="section" style="border-left-color: #ff9800;">
            <h2 style="color: #ff9800;">⚠ Security Warning</h2>
            <p><strong>This page displays sensitive error information!</strong></p>
            <p>Delete this file (<code>error-display.php</code>) after debugging for security.</p>
        </div>
    </div>
</body>
</html>
