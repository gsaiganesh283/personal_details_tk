<?php
/**
 * Test script to verify BASE_PATH detection
 * Place in root directory and run from browser to test
 * Or run: php test_basepath.php from command line with different SCRIPT_NAME values
 */

// Simulate different deployment scenarios
$scenarios = [
    [
        'SCRIPT_NAME' => '/index.php',
        'Server Name' => 'localhost (root)',
        'Expected BASE_PATH' => ''
    ],
    [
        'SCRIPT_NAME' => '/pds/index.php',
        'Server Name' => 'localhost/pds (subdirectory)',
        'Expected BASE_PATH' => '/pds'
    ],
    [
        'SCRIPT_NAME' => '/apps/pds/index.php',
        'Server Name' => 'localhost/apps/pds (nested subdirectory)',
        'Expected BASE_PATH' => '/apps/pds'
    ]
];

echo "=== BASE_PATH Detection Test ===\n\n";

foreach ($scenarios as $scenario) {
    $_SERVER['SCRIPT_NAME'] = $scenario['SCRIPT_NAME'];
    
    $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
    $basePath = ($scriptPath === '/' || $scriptPath === '\\') ? '' : $scriptPath;
    
    echo "Scenario: " . $scenario['Server Name'] . "\n";
    echo "SCRIPT_NAME: " . $_SERVER['SCRIPT_NAME'] . "\n";
    echo "Calculated BASE_PATH: '" . $basePath . "'\n";
    echo "Expected BASE_PATH: '" . $scenario['Expected BASE_PATH'] . "'\n";
    echo "Status: " . ($basePath === $scenario['Expected BASE_PATH'] ? "✓ PASS" : "✗ FAIL") . "\n";
    echo "\n";
}

// Test URL generation function
function url($path = '') {
    $path = ltrim($path, '/');
    $basePath = $GLOBALS['testBasePath'] ?? '';
    return $basePath . '/' . $path;
}

echo "=== URL Generation Test ===\n\n";

$GLOBALS['testBasePath'] = '/pds';
echo "With BASE_PATH = '/pds':\n";
echo "  url('dashboard.php') = '" . url('dashboard.php') . "' (expected: '/pds/dashboard.php')\n";
echo "  url('personal-details.php?action=add') = '" . url('personal-details.php?action=add') . "' (expected: '/pds/personal-details.php?action=add')\n";
echo "  url('assets/css/style.css') = '" . url('assets/css/style.css') . "' (expected: '/pds/assets/css/style.css')\n";

echo "\n✓ All tests completed. Check results above.\n";
?>
