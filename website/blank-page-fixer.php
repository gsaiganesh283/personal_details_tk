<?php
/**
 * Blank Page Fixer Diagnostic
 * Identifies why pages appear blank when hosted
 */

// Enable all error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if we're in a subdirectory
$scriptName = $_SERVER['SCRIPT_NAME'];
$scriptPath = dirname($scriptName);
$basePath = ($scriptPath === '/' || $scriptPath === '\\') ? '' : $scriptPath;

echo '<style>
body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
.container { max-width: 900px; margin: 0 auto; }
.test { padding: 12px; margin: 8px 0; border: 1px solid #ddd; border-radius: 4px; }
.pass { background: #e8f5e9; border-color: #4caf50; }
.fail { background: #ffebee; border-color: #f44336; }
.warn { background: #fff3e0; border-color: #ff9800; }
h1 { color: #333; }
h2 { color: #666; border-bottom: 2px solid #6C63FF; padding-bottom: 10px; }
.code { background: #f9f9f9; padding: 10px; border-radius: 4px; overflow-x: auto; font-family: monospace; }
</style>
<div class="container">
<h1>🔍 Blank Page Diagnostic Report</h1>';

// Test 1: Check BASE_PATH calculation
echo '<h2>1. URL & Path Analysis</h2>';
echo '<div class="test pass">';
echo '📍 <strong>SCRIPT_NAME:</strong> ' . htmlspecialchars($scriptName) . '<br>';
echo '📍 <strong>Script Path:</strong> ' . htmlspecialchars($scriptPath) . '<br>';
echo '📍 <strong>BASE_PATH (calculated):</strong> ' . ($basePath ?: '(empty - root installation)') . '<br>';
echo '📍 <strong>HTTP_HOST:</strong> ' . htmlspecialchars($_SERVER['HTTP_HOST']) . '<br>';
echo '</div>';

// Test 2: Check if config files load
echo '<h2>2. Configuration Files</h2>';
$configPath = __DIR__ . '/config/database.php';
if (file_exists($configPath)) {
    require_once $configPath;
    echo '<div class="test pass">';
    echo '✓ config/database.php loaded<br>';
    if (defined('BASE_PATH')) {
        echo '✓ BASE_PATH defined: ' . htmlspecialchars(BASE_PATH) . '<br>';
    }
    if (defined('DB_HOST')) {
        echo '✓ Database Host: ' . htmlspecialchars(DB_HOST) . '<br>';
    }
    if (defined('APP_URL')) {
        echo '✓ APP_URL: ' . htmlspecialchars(APP_URL) . '<br>';
    }
    echo '</div>';
} else {
    echo '<div class="test fail">✗ config/database.php NOT FOUND at ' . htmlspecialchars($configPath) . '</div>';
}

// Test 3: Check if functions load
echo '<h2>3. Core Functions</h2>';
$functionsPath = __DIR__ . '/includes/functions.php';
if (file_exists($functionsPath)) {
    require_once $functionsPath;
    echo '<div class="test pass">✓ includes/functions.php loaded<br>';
    if (function_exists('url')) {
        $testUrl = url('personal-details.php');
        echo '✓ url() function works<br>';
        echo '  Test: url("personal-details.php") = ' . htmlspecialchars($testUrl) . '<br>';
    }
    echo '</div>';
} else {
    echo '<div class="test fail">✗ includes/functions.php NOT FOUND</div>';
}

// Test 4: Database connection
echo '<h2>4. Database Connection</h2>';
if (class_exists('Database')) {
    try {
        $db = Database::getInstance();
        echo '<div class="test pass">✓ Database connection successful<br>';
        $tables = $db->fetchAll("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?", [DB_NAME]);
        echo '✓ Found ' . count($tables) . ' tables<br>';
        
        // Check for required tables
        $tableNames = array_column($tables, 'TABLE_NAME');
        $requiredTables = ['users', 'personal_details', 'bank_details', 'documents', 'custom_fields', 'custom_field_blocks'];
        foreach ($requiredTables as $tbl) {
            if (in_array($tbl, $tableNames)) {
                echo '  ✓ Table exists: ' . $tbl . '<br>';
            } else {
                echo '  ✗ Missing table: ' . $tbl . '<br>';
            }
        }
        echo '</div>';
    } catch (Exception $e) {
        echo '<div class="test fail">✗ Database Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
} else {
    echo '<div class="test fail">✗ Database class not found</div>';
}

// Test 5: Check critical files
echo '<h2>5. Critical Files</h2>';
$criticalFiles = [
    'includes/header.php' => 'Header template',
    'includes/footer.php' => 'Footer template',
    'includes/auth.php' => 'Authentication class',
    'includes/custom_fields_helper.php' => 'Custom fields helper',
    'assets/css/style.css' => 'Main CSS',
    'assets/css/responsive.css' => 'Responsive CSS',
    'assets/js/app.js' => 'Main JavaScript'
];

foreach ($criticalFiles as $file => $desc) {
    $fullPath = __DIR__ . '/' . $file;
    if (file_exists($fullPath)) {
        echo '<div class="test pass">✓ ' . $desc . ' (' . $file . ')</div>';
    } else {
        echo '<div class="test fail">✗ ' . $desc . ' (' . $file . ') NOT FOUND</div>';
    }
}

// Test 6: Detailed URL generation
echo '<h2>6. URL Generation Test</h2>';
if (function_exists('url')) {
    $testUrls = [
        'assets/css/style.css',
        'assets/js/app.js',
        'personal-details.php',
        'dashboard.php',
        'logout.php'
    ];
    echo '<div class="test">';
    foreach ($testUrls as $path) {
        $generated = url($path);
        echo htmlspecialchars($path) . ' → ' . htmlspecialchars($generated) . '<br>';
    }
    echo '</div>';
}

// Test 7: Check for common issues
echo '<h2>7. Common Issues Checklist</h2>';
$issues = [];

// Check if files have BOM
$headerFile = __DIR__ . '/includes/header.php';
if (file_exists($headerFile)) {
    $content = file_get_contents($headerFile, false, null, 0, 3);
    if (substr_compare($content, "\xEF\xBB\xBF", 0, 3) === 0) {
        $issues[] = '⚠️ header.php has BOM (Byte Order Mark) - can cause blank pages';
    }
}

// Check for output before header
if (file_exists($headerFile)) {
    $firstPhp = strpos(file_get_contents($headerFile), '<?php');
    if ($firstPhp > 0) {
        $issues[] = '⚠️ Content before PHP opening tag in header.php';
    }
}

// Check index.php redirect
$indexFile = __DIR__ . '/index.php';
if (file_exists($indexFile)) {
    $content = file_get_contents($indexFile);
    if (strpos($content, 'header(\'Location:') === false && strpos($content, 'header("Location:') === false) {
        $issues[] = '⚠️ index.php does not seem to redirect to login';
    }
}

if (empty($issues)) {
    echo '<div class="test pass">✓ No obvious issues detected</div>';
} else {
    foreach ($issues as $issue) {
        echo '<div class="test warn">' . htmlspecialchars($issue) . '</div>';
    }
}

// Test 8: Session handling
echo '<h2>8. Session Check</h2>';
if (session_status() !== PHP_SESSION_NONE) {
    session_start();
}
echo '<div class="test">';
echo '✓ Session status: ' . (session_status() === PHP_SESSION_ACTIVE ? 'ACTIVE' : 'INACTIVE') . '<br>';
echo '✓ Session ID: ' . htmlspecialchars(session_id() ?: '(no session yet)') . '<br>';
echo '</div>';

echo '<h2>📝 Recommended Next Steps</h2>';
echo '<div class="test">';
echo '<ol>';
echo '<li>If database tables are missing, run: <strong>setup.php</strong></li>';
echo '<li>If paths look wrong, check subdirectory configuration in config/database.php</li>';
echo '<li>If files are missing, ensure all files were uploaded correctly</li>';
echo '<li>If CSS/JS paths are wrong, you may need to manually adjust url() function</li>';
echo '<li>Try accessing a page directly and check browser console (F12) for JavaScript errors</li>';
echo '</ol>';
echo '</div>';

echo '</div>';
?>
