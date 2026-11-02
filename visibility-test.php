<?php
/**
 * Visibility Test - Checks if content exists but is hidden
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

?>
<!DOCTYPE html>
<html>
<head>
    <title>Visibility Test - Content Check</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            background: #ffffff;
            color: #000;
            padding: 20px;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: #f9f9f9;
            border: 2px solid #333;
            padding: 20px;
            border-radius: 5px;
        }
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
        code {
            background: #f5f5f5;
            padding: 4px 8px;
            border: 1px solid #ddd;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
        }
        pre {
            background: #f5f5f5;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 3px;
            overflow-x: auto;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.4;
        }
        .asset-list { list-style-position: inside; }
        .asset-list li { margin: 8px 0; padding: 8px; background: white; border-left: 3px solid #ddd; }
        .asset-list li.ok { border-left-color: #388e3c; background: #e8f5e9; }
        .asset-list li.missing { border-left-color: #d32f2f; background: #ffebee; }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }
        th, td {
            padding: 10px;
            text-align: left;
            border: 1px solid #ddd;
        }
        th { background: #f5f5f5; font-weight: bold; }
        .warning { color: #f57c00; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Visibility Test - Content & Asset Check</h1>
        
        <div class="section">
            <h2>What This Test Does</h2>
            <p>This page checks if:</p>
            <ul style="margin-left: 20px;">
                <li>HTML content is rendering (it might be invisible)</li>
                <li>CSS files are loading properly</li>
                <li>JavaScript files are loading properly</li>
                <li>Asset paths are correct</li>
                <li>BASE_PATH is being used correctly</li>
            </ul>
        </div>

        <?php
        
        echo '<div class="section">';
        echo '<h2>1. Configuration Check</h2>';
        
        echo '<table>';
        echo '<tr><th>Setting</th><th>Value</th></tr>';
        echo '<tr><td>BASE_PATH</td><td><code>' . (defined('BASE_PATH') ? BASE_PATH : 'NOT DEFINED') . '</code></td></tr>';
        echo '<tr><td>Current URL Path</td><td><code>' . $_SERVER['SCRIPT_NAME'] . '</code></td></tr>';
        echo '<tr><td>Document Root</td><td><code>' . $_SERVER['DOCUMENT_ROOT'] . '</code></td></tr>';
        echo '<tr><td>File Directory</td><td><code>' . __DIR__ . '</code></td></tr>';
        echo '</table>';
        
        echo '</div>';
        
        echo '<div class="section">';
        echo '<h2>2. Asset Path Testing</h2>';
        
        $assets = [
            'CSS' => [
                'style.css' => 'assets/css/style.css',
                'responsive.css' => 'assets/css/responsive.css',
            ],
            'JavaScript' => [
                'app.js' => 'assets/js/app.js',
            ]
        ];
        
        foreach ($assets as $type => $files) {
            echo '<h3>' . $type . ' Assets</h3>';
            echo '<ul class="asset-list">';
            
            foreach ($files as $name => $path) {
                $fullPath = __DIR__ . '/' . $path;
                $exists = file_exists($fullPath);
                $readable = $exists ? is_readable($fullPath) : false;
                
                $url = url($path);
                
                $class = $exists && $readable ? 'ok' : 'missing';
                $icon = $exists && $readable ? '✓' : '✗';
                
                echo '<li class="' . $class . '">';
                echo $icon . ' <strong>' . $name . '</strong><br>';
                echo 'Local: <code>' . $fullPath . '</code> ';
                echo ($exists && $readable ? '<span class="pass">(EXISTS)</span>' : '<span class="fail">(MISSING)</span>') . '<br>';
                echo 'URL: <code>' . htmlspecialchars($url) . '</code><br>';
                echo 'Size: ' . (file_exists($fullPath) ? number_format(filesize($fullPath)) . ' bytes' : 'N/A') . '<br>';
                echo '</li>';
            }
            
            echo '</ul>';
        }
        
        echo '</div>';
        
        echo '<div class="section">';
        echo '<h2>3. CSS Loading Simulation</h2>';
        echo '<p>Below is a test to see if content can be seen when CSS is applied:</p>';
        echo '<div style="background: white; border: 1px solid #ccc; padding: 20px; margin: 10px 0;">';
        echo '<h3 style="color: #1976d2; margin-bottom: 10px;">Test Content Block</h3>';
        echo '<p style="color: #000;">If you can read this text clearly, <strong>CSS CAN load</strong>.</p>';
        echo '<p style="color: #666;">Gray text test - showing CSS is working properly.</p>';
        echo '<button style="background: #1976d2; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px;">Test Button</button>';
        echo '</div>';
        
        echo '</div>';

        echo '<div class="section">';
        echo '<h2>4. Actual Page Layout Test</h2>';
        echo '<p>Below is a simulation of what your app page should look like:</p>';
        
        ob_start();
        ?>
        
        <div style="display: flex; min-height: 100vh; background: #f5f5f5;">
            <!-- Sidebar -->
            <aside style="width: 250px; background: #1a1a2e; color: white; padding: 20px; min-height: 100vh;">
                <h3 style="margin-bottom: 20px;">SIDEBAR</h3>
                <nav>
                    <a href="#" style="display: block; color: white; text-decoration: none; margin: 10px 0; padding: 10px; background: #0f3460; border-radius: 4px;">Dashboard</a>
                    <a href="#" style="display: block; color: white; text-decoration: none; margin: 10px 0; padding: 10px;">Personal Details</a>
                    <a href="#" style="display: block; color: white; text-decoration: none; margin: 10px 0; padding: 10px;">Bank Details</a>
                    <a href="#" style="display: block; color: white; text-decoration: none; margin: 10px 0; padding: 10px;">Documents</a>
                </nav>
            </aside>

            <!-- Main Content -->
            <main style="flex: 1; padding: 40px;">
                <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <h1 style="color: #1a1a2e; margin-bottom: 20px;">Welcome to Personal Details Saver</h1>
                    <p style="color: #666; margin-bottom: 15px;">If you can see this content with:</p>
                    <ul style="margin-left: 20px; color: #666;">
                        <li>Left sidebar navigation (dark)</li>
                        <li>White content area on right</li>
                        <li>Proper styling and colors</li>
                        <li>Readable fonts and spacing</li>
                    </ul>
                    <p style="color: #388e3c; margin-top: 20px; font-weight: bold;">...Then CSS is loading correctly! ✓</p>
                </div>
            </main>
        </div>
        
        <?php
        $layoutTest = ob_get_clean();
        echo $layoutTest;
        
        echo '</div>';

        echo '<div class="section">';
        echo '<h2>5. Asset Loading Test (JavaScript)</h2>';
        echo '<p>Open browser Developer Tools (F12) and check:</p>';
        echo '<ul style="margin-left: 20px;">';
        echo '<li><strong>Network tab:</strong> Are CSS/JS files loading? (should show 200 status)</li>';
        echo '<li><strong>Console tab:</strong> Any red errors?</li>';
        echo '<li><strong>Sources tab:</strong> Can you see CSS and JS files?</li>';
        echo '</ul>';
        
        echo '<p style="margin-top: 15px; font-weight: bold;">Expected Asset URLs:</p>';
        echo '<pre>';
        echo 'CSS: ' . htmlspecialchars(url('assets/css/style.css')) . PHP_EOL;
        echo 'JS:  ' . htmlspecialchars(url('assets/js/app.js')) . PHP_EOL;
        echo '</pre>';
        
        echo '</div>';

        echo '<div class="section">';
        echo '<h2>6. Diagnosis Summary</h2>';
        
        $cssPath = __DIR__ . '/assets/css/style.css';
        $jsPath = __DIR__ . '/assets/js/app.js';
        $cssExists = file_exists($cssPath);
        $jsExists = file_exists($jsPath);
        
        echo '<table>';
        echo '<tr><th>Component</th><th>Status</th><th>Issue</th></tr>';
        
        if (defined('BASE_PATH')) {
            echo '<tr><td>BASE_PATH</td><td><span class="pass">✓ Defined</span></td><td>-</td></tr>';
        } else {
            echo '<tr><td>BASE_PATH</td><td><span class="fail">✗ Not Defined</span></td><td>Configuration not loaded</td></tr>';
        }
        
        if ($cssExists) {
            echo '<tr><td>CSS File Exists</td><td><span class="pass">✓ Yes</span></td><td>-</td></tr>';
        } else {
            echo '<tr><td>CSS File Exists</td><td><span class="fail">✗ No</span></td><td>assets/css/style.css missing</td></tr>';
        }
        
        if ($jsExists) {
            echo '<tr><td>JS File Exists</td><td><span class="pass">✓ Yes</span></td><td>-</td></tr>';
        } else {
            echo '<tr><td>JS File Exists</td><td><span class="fail">✗ No</span></td><td>assets/js/app.js missing</td></tr>';
        }
        
        echo '</table>';
        
        echo '<p style="margin-top: 20px;"><strong>Most Likely Issue:</strong></p>';
        if (!$cssExists) {
            echo '<p style="color: #d32f2f;"><strong>✗ CSS files are missing!</strong></p>';
            echo '<p>The HTML is rendering but without CSS, the page appears blank.</p>';
            echo '<p><strong>Solution:</strong> Re-upload the <code>assets/</code> directory with all files.</p>';
        } elseif (!$jsExists) {
            echo '<p style="color: #f57c00;"><strong>⚠ JavaScript files are missing!</strong></p>';
            echo '<p>CSS should be loading, but interactive features won\'t work.</p>';
            echo '<p><strong>Solution:</strong> Re-upload the <code>assets/js/</code> directory.</p>';
        } else {
            echo '<p style="color: #388e3c;"><strong>✓ All files present!</strong></p>';
            echo '<p>If page still appears blank, the issue might be:</p>';
            echo '<ul style="margin-left: 20px;">';
            echo '<li>Asset URLs are still incorrect (BASE_PATH issue)</li>';
            echo '<li>Browser cache needs clearing</li>';
            echo '<li>CSS/JS files are corrupted</li>';
            echo '</ul>';
        }
        
        echo '</div>';

        echo '<div class="section" style="background: #fff3cd; border-color: #ffc107;">';
        echo '<h2>📋 Action Items</h2>';
        echo '<ol style="margin-left: 20px;">';
        echo '<li>Take screenshot of this page and share</li>';
        echo '<li>Open F12 Developer Tools in your browser</li>';
        echo '<li>Go to <strong>Network</strong> tab</li>';
        echo '<li>Refresh page (F5)</li>';
        echo '<li>Look for CSS and JS files</li>';
        echo '<li>Check if they show red ✗ (404 Not Found) or green ✓ (200 OK)</li>';
        echo '<li>Share screenshot of Network tab</li>';
        echo '</ol>';
        echo '</div>';

        ?>

    </div>
    
    <script>
        console.log('Page loaded at:', new Date().toLocaleTimeString());
        console.log('BASE_PATH:', '<?php echo defined('BASE_PATH') ? BASE_PATH : 'NOT DEFINED'; ?>');
        console.log('Script URL:', '<?php echo $_SERVER['SCRIPT_NAME']; ?>');
        console.log('CSS should be at:', '<?php echo htmlspecialchars(url('assets/css/style.css')); ?>');
        console.log('JS should be at:', '<?php echo htmlspecialchars(url('assets/js/app.js')); ?>');
    </script>
</body>
</html>
