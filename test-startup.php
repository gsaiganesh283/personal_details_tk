<?php
/**
 * Application Startup Test
 * This script tests if the application can start properly
 * Run this file to diagnose startup issues
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html>";
echo "<html><head><style>";
echo "body { font-family: Arial; margin: 20px; background: #f5f5f5; }";
echo ".section { background: white; padding: 15px; margin: 10px 0; border-radius: 5px; border-left: 4px solid #6C63FF; }";
echo ".pass { border-left-color: #4caf50; color: #2e7d32; }";
echo ".fail { border-left-color: #f44336; color: #c62828; }";
echo ".info { border-left-color: #2196F3; color: #1565c0; }";
echo "code { background: #f0f0f0; padding: 2px 5px; border-radius: 3px; }";
echo "</style></head><body>";
echo "<h1>🔧 Application Startup Test</h1>";

$tests = [];
$failures = [];

// Test 1: Database config
echo "<div class='section info'><strong>Step 1: Loading Database Configuration</strong></div>";
try {
    if (file_exists('./config/database.php')) {
        require_once './config/database.php';
        echo "<div class='section pass'>✓ Database configuration loaded successfully</div>";
        echo "<div class='section'>";
        echo "  <strong>Database Config:</strong><br>";
        echo "  DB_HOST: <code>" . DB_HOST . "</code><br>";
        echo "  DB_NAME: <code>" . DB_NAME . "</code><br>";
        echo "  DB_USER: <code>" . DB_USER . "</code><br>";
        echo "  BASE_PATH: <code>" . (defined('BASE_PATH') ? BASE_PATH : 'NOT DEFINED') . "</code><br>";
        echo "</div>";
        $tests[] = true;
    } else {
        echo "<div class='section fail'>✗ Database configuration file not found</div>";
        $failures[] = "Missing config/database.php";
        $tests[] = false;
    }
} catch (Exception $e) {
    echo "<div class='section fail'>✗ Error loading database config: " . $e->getMessage() . "</div>";
    $failures[] = "Database config error: " . $e->getMessage();
    $tests[] = false;
}

// Test 2: Functions include
echo "<div class='section info'><strong>Step 2: Loading Functions Include</strong></div>";
try {
    if (file_exists('./includes/functions.php')) {
        require_once './includes/functions.php';
        echo "<div class='section pass'>✓ Functions include loaded successfully</div>";
        echo "<div class='section'>";
        echo "  <strong>Functions Available:</strong><br>";
        echo "  url(): " . (function_exists('url') ? "✓" : "✗") . "<br>";
        echo "  redirect(): " . (function_exists('redirect') ? "✓" : "✗") . "<br>";
        echo "  sanitize(): " . (function_exists('sanitize') ? "✓" : "✗") . "<br>";
        echo "  requireAuth(): " . (function_exists('requireAuth') ? "✓" : "✗") . "<br>";
        echo "  setFlash(): " . (function_exists('setFlash') ? "✓" : "✗") . "<br>";
        echo "</div>";
        $tests[] = true;
    } else {
        echo "<div class='section fail'>✗ Functions include file not found</div>";
        $failures[] = "Missing includes/functions.php";
        $tests[] = false;
    }
} catch (Exception $e) {
    echo "<div class='section fail'>✗ Error loading functions: " . $e->getMessage() . "</div>";
    $failures[] = "Functions include error: " . $e->getMessage();
    $tests[] = false;
}

// Test 3: Auth include
echo "<div class='section info'><strong>Step 3: Loading Auth Include</strong></div>";
try {
    if (file_exists('./includes/auth.php')) {
        require_once './includes/auth.php';
        echo "<div class='section pass'>✓ Auth include loaded successfully</div>";
        echo "<div class='section'>";
        echo "  <strong>Classes Available:</strong><br>";
        echo "  Auth: " . (class_exists('Auth') ? "✓" : "✗") . "<br>";
        echo "</div>";
        $tests[] = true;
    } else {
        echo "<div class='section fail'>✗ Auth include file not found</div>";
        $failures[] = "Missing includes/auth.php";
        $tests[] = false;
    }
} catch (Exception $e) {
    echo "<div class='section fail'>✗ Error loading auth: " . $e->getMessage() . "</div>";
    $failures[] = "Auth include error: " . $e->getMessage();
    $tests[] = false;
}

// Test 4: Database connection
echo "<div class='section info'><strong>Step 4: Testing Database Connection</strong></div>";
try {
    $db = Database::getInstance();
    echo "<div class='section pass'>✓ Database connection successful</div>";
    $tests[] = true;
} catch (Exception $e) {
    echo "<div class='section fail'>✗ Database connection failed: " . $e->getMessage() . "</div>";
    echo "<div class='section'><strong>Troubleshooting:</strong>";
    echo "<ul>";
    echo "<li>Check if DB_HOST (" . DB_HOST . ") is accessible</li>";
    echo "<li>Check if DB_NAME (" . DB_NAME . ") exists</li>";
    echo "<li>Check if DB_USER (" . DB_USER . ") has proper permissions</li>";
    echo "<li>Check your internet connection (if on online hosting)</li>";
    echo "</ul></div>";
    $failures[] = "Database connection error: " . $e->getMessage();
    $tests[] = false;
}

// Test 5: url() function test
echo "<div class='section info'><strong>Step 5: Testing url() Helper Function</strong></div>";
try {
    if (function_exists('url')) {
        $testUrl1 = url('login.php');
        $testUrl2 = url('/dashboard.php');
        echo "<div class='section pass'>✓ url() function working</div>";
        echo "<div class='section'>";
        echo "  url('login.php') = <code>" . htmlspecialchars($testUrl1) . "</code><br>";
        echo "  url('/dashboard.php') = <code>" . htmlspecialchars($testUrl2) . "</code><br>";
        echo "</div>";
        $tests[] = true;
    } else {
        echo "<div class='section fail'>✗ url() function not available</div>";
        $failures[] = "url() function not found";
        $tests[] = false;
    }
} catch (Exception $e) {
    echo "<div class='section fail'>✗ Error testing url(): " . $e->getMessage() . "</div>";
    $failures[] = "url() function error: " . $e->getMessage();
    $tests[] = false;
}

// Summary
echo "<div class='section info'><h2>Summary</h2>";
$passed = count(array_filter($tests));
$total = count($tests);
echo "Tests Passed: <strong>$passed / $total</strong><br>";

if (empty($failures)) {
    echo "<div class='section pass'>";
    echo "<strong>✓ All tests passed!</strong><br>";
    echo "Your application should be working correctly.<br>";
    echo "Try visiting: <a href='./login.php'>/login.php</a>";
    echo "</div>";
} else {
    echo "<div class='section fail'>";
    echo "<strong>✗ Some tests failed:</strong><br>";
    echo "<ul>";
    foreach ($failures as $failure) {
        echo "<li>" . htmlspecialchars($failure) . "</li>";
    }
    echo "</ul>";
    echo "</div>";
}

echo "</div>";
echo "</body></html>";
