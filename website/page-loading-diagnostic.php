<?php
/**
 * Page Loading Diagnostic - Tests each page component
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

?>
<!DOCTYPE html>
<html>
<head>
    <title>Page Loading Diagnostic</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            background: white;
            color: #000;
            padding: 20px;
        }
        .container { max-width: 1000px; margin: 0 auto; }
        h1 { color: #d32f2f; margin: 20px 0; }
        h2 { color: #1976d2; margin: 15px 0 10px; }
        .section {
            background: white;
            border: 2px solid #ddd;
            padding: 15px;
            margin: 15px 0;
            border-radius: 5px;
        }
        .pass { color: #388e3c; font-weight: bold; }
        .fail { color: #d32f2f; font-weight: bold; }
        .test { background: #f5f5f5; padding: 10px; margin: 10px 0; border-left: 3px solid #1976d2; }
        code { background: #f5f5f5; padding: 4px 8px; border: 1px solid #ddd; border-radius: 3px; font-family: 'Courier New'; }
        pre { background: #000; color: #0f0; padding: 12px; border-radius: 3px; overflow-x: auto; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Page Loading Diagnostic</h1>
        
        <div class="section">
            <h2>Step-by-Step Page Component Test</h2>
            <p>This test loads each application component step-by-step to find where pages are failing.</p>
        </div>

        <?php
        
        // Test 1: Config Load
        echo '<div class="section">';
        echo '<h2>Test 1: Configuration</h2>';
        
        $test1 = false;
        try {
            require_once __DIR__ . '/config/database.php';
            echo '<div class="test"><span class="pass">✓</span> config/database.php loaded</div>';
            echo '<div class="test">BASE_PATH: <code>' . (defined('BASE_PATH') ? BASE_PATH : '(empty)') . '</code></div>';
            echo '<div class="test">DB_NAME: <code>' . DB_NAME . '</code></div>';
            $test1 = true;
        } catch (Exception $e) {
            echo '<div class="test"><span class="fail">✗</span> Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
        echo '</div>';
        
        // Test 2: Functions Load
        echo '<div class="section">';
        echo '<h2>Test 2: Functions Include</h2>';
        
        $test2 = false;
        try {
            require_once __DIR__ . '/includes/functions.php';
            echo '<div class="test"><span class="pass">✓</span> includes/functions.php loaded</div>';
            echo '<div class="test">Functions available:';
            echo '<ul style="margin-left: 20px;">';
            echo '<li>url(): ' . (function_exists('url') ? '<span class="pass">✓</span>' : '<span class="fail">✗</span>') . '</li>';
            echo '<li>redirect(): ' . (function_exists('redirect') ? '<span class="pass">✓</span>' : '<span class="fail">✗</span>') . '</li>';
            echo '<li>requireAuth(): ' . (function_exists('requireAuth') ? '<span class="pass">✓</span>' : '<span class="fail">✗</span>') . '</li>';
            echo '</ul>';
            echo '</div>';
            $test2 = true;
        } catch (Exception $e) {
            echo '<div class="test"><span class="fail">✗</span> Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
        echo '</div>';
        
        // Test 3: Auth Load
        echo '<div class="section">';
        echo '<h2>Test 3: Auth Include</h2>';
        
        $test3 = false;
        try {
            require_once __DIR__ . '/includes/auth.php';
            echo '<div class="test"><span class="pass">✓</span> includes/auth.php loaded</div>';
            echo '<div class="test">Classes available:';
            echo '<ul style="margin-left: 20px;">';
            echo '<li>Auth: ' . (class_exists('Auth') ? '<span class="pass">✓</span>' : '<span class="fail">✗</span>') . '</li>';
            echo '<li>Database: ' . (class_exists('Database') ? '<span class="pass">✓</span>' : '<span class="fail">✗</span>') . '</li>';
            echo '</ul>';
            echo '</div>';
            $test3 = true;
        } catch (Exception $e) {
            echo '<div class="test"><span class="fail">✗</span> Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
        echo '</div>';
        
        // Test 4: Database Connection
        echo '<div class="section">';
        echo '<h2>Test 4: Database Connection</h2>';
        
        $test4 = false;
        try {
            $db = Database::getInstance();
            echo '<div class="test"><span class="pass">✓</span> Database connected</div>';
            
            // Try a simple query
            $result = $db->fetch("SELECT 1 as test");
            if ($result) {
                echo '<div class="test"><span class="pass">✓</span> Can execute queries</div>';
            }
            $test4 = true;
        } catch (Exception $e) {
            echo '<div class="test"><span class="fail">✗</span> Database Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
        echo '</div>';
        
        // Test 5: Header Include
        echo '<div class="section">';
        echo '<h2>Test 5: Header Include (THE CRITICAL TEST)</h2>';
        
        $test5 = false;
        $headerOutput = '';
        
        if ($test1 && $test2 && $test3) {
            echo '<p style="color: #ff9800; font-weight: bold;">⚠ Warning: This will start a session and include HTML</p>';
            
            try {
                // Capture header output
                ob_start();
                
                // Simulate what happens on protected pages
                if (session_status() === PHP_SESSION_NONE) {
                    session_start();
                }
                
                // Try loading header
                include __DIR__ . '/includes/header.php';
                $headerOutput = ob_get_clean();
                
                // Check if output was generated
                if (!empty($headerOutput)) {
                    echo '<div class="test"><span class="pass">✓</span> Header loads and produces output</div>';
                    echo '<div class="test">Header output size: ' . strlen($headerOutput) . ' bytes</div>';
                    
                    // Check if it contains HTML
                    if (strpos($headerOutput, '<html') !== false || strpos($headerOutput, '<!DOCTYPE') !== false) {
                        echo '<div class="test"><span class="pass">✓</span> Header contains HTML structure</div>';
                    } else {
                        echo '<div class="test"><span class="fail">✗</span> Header output does not contain HTML</div>';
                    }
                    
                    // Check for common structural elements
                    $checks = [
                        '<body' => 'Body tag',
                        '<header' => 'Header tag',
                        '<main' => 'Main tag',
                        '<sidebar' => 'Sidebar',
                    ];
                    
                    echo '<div class="test">Header structure check:';
                    echo '<ul style="margin-left: 20px;">';
                    foreach ($checks as $tag => $name) {
                        $found = strpos($headerOutput, $tag) !== false;
                        echo '<li>' . $name . ': ' . ($found ? '<span class="pass">✓</span>' : '·') . '</li>';
                    }
                    echo '</ul></div>';
                    
                    $test5 = true;
                } else {
                    echo '<div class="test"><span class="fail">✗</span> Header produces NO output!</div>';
                    echo '<div class="test" style="color: #d32f2f;"><strong>This is the problem!</strong></div>';
                    echo '<div class="test">The header.php file includes or does something that prevents output.</div>';
                }
                
            } catch (Exception $e) {
                echo '<div class="test"><span class="fail">✗</span> Header Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        } else {
            echo '<div class="test">Skipped - previous tests must pass first</div>';
        }
        echo '</div>';
        
        // Test 6: Summary
        echo '<div class="section">';
        echo '<h2>Diagnosis Summary</h2>';
        
        $allPass = $test1 && $test2 && $test3 && $test4 && $test5;
        
        if ($allPass) {
            echo '<div class="test" style="background: #e8f5e9;">';
            echo '<h3 style="color: #388e3c; margin: 0;">✓ ALL TESTS PASSED!</h3>';
            echo '<p>If this shows up but your application pages are blank, the issue might be:</p>';
            echo '<ul style="margin-left: 20px;">';
            echo '<li>Session issues preventing proper auth checking</li>';
            echo '<li>Redirect loops in authentication</li>';
            echo '<li>requireAuth() causing blank output</li>';
            echo '</ul>';
            echo '</div>';
        } else if (!$test5) {
            echo '<div class="test" style="background: #ffebee;">';
            echo '<h3 style="color: #d32f2f; margin: 0;">✗ FOUND THE ISSUE: Header Not Outputting</h3>';
            echo '<p>The includes/header.php file is not producing any HTML output.</p>';
            echo '<p><strong>Likely causes:</strong></p>';
            echo '<ul style="margin-left: 20px;">';
            echo '<li>redirect() is being called in header.php</li>';
            echo '<li>requireAuth() is redirecting to login</li>';
            echo '<li>HTML before PHP close tag being suppressed</li>';
            echo '<li>Output buffering clearing content</li>';
            echo '</ul>';
            echo '</div>';
        } else {
            echo '<div class="test" style="background: #ffebee;">';
            echo '<h3 style="color: #d32f2f; margin: 0;">✗ Bootstrap Issue</h3>';
            echo '<p>One of the core components failed to load.</p>';
            echo '<ul style="margin-left: 20px;">';
            if (!$test1) echo '<li>Config not loading</li>';
            if (!$test2) echo '<li>Functions not loading</li>';
            if (!$test3) echo '<li>Auth not loading</li>';
            if (!$test4) echo '<li>Database not connecting</li>';
            echo '</ul>';
            echo '</div>';
        }
        
        echo '</div>';

        // Test 7: Try to actually load login page
        echo '<div class="section">';
        echo '<h2>Test 6: Actual Page Load Test</h2>';
        echo '<p>Attempting to load login.php...</p>';
        
        try {
            $pageOutput = '';
            ob_start();
            
            // Create a mock environment to test if login page works
            // But don't actually execute redirects
            define('TEST_MODE', true);
            
            // Check if login.php exists
            if (file_exists(__DIR__ . '/login.php')) {
                echo '<div class="test"><span class="pass">✓</span> login.php file exists</div>';
                
                // Check file size (not empty)
                $loginSize = filesize(__DIR__ . '/login.php');
                echo '<div class="test">File size: ' . number_format($loginSize) . ' bytes</div>';
                
                if ($loginSize > 100) {
                    echo '<div class="test"><span class="pass">✓</span> File contains code (not empty)</div>';
                } else {
                    echo '<div class="test"><span class="fail">✗</span> File is too small or empty</div>';
                }
            } else {
                echo '<div class="test"><span class="fail">✗</span> login.php file not found!</div>';
            }
            
            ob_end_clean();
        } catch (Exception $e) {
            ob_end_clean();
            echo '<div class="test"><span class="fail">✗</span> Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
        
        echo '</div>';

        // Test 8: Recommendations
        echo '<div class="section" style="background: #fff9c4; border-color: #fbc02d;">';
        echo '<h2>📋 Next Steps</h2>';
        
        if ($test5) {
            echo '<p><strong>Since header loads:</strong></p>';
            echo '<ol style="margin-left: 20px;">';
            echo '<li>Edit includes/header.php</li>';
            echo '<li>Look for requireAuth() calls</li>';
            echo '<li>Make sure they don\'t redirect during page load</li>';
            echo '<li>Check <code>session_start()</code> placement</li>';
            echo '</ol>';
        } else {
            echo '<p><strong>Issue is in header.php:</strong></p>';
            echo '<ol style="margin-left: 20px;">';
            echo '<li>Check includes/header.php line 1</li>';
            echo '<li>Make sure it starts with <code>&lt;?php</code></li>';
            echo '<li>Ensure all includes can load</li>';
            echo '<li>Remove any require/require_once that might fail</li>';
            echo '<li>Try adding <code>echo "TEST";</code> at start to verify it runs</li>';
            echo '</ol>';
        }
        
        echo '</div>';
        
        ?>

    </div>
</body>
</html>
