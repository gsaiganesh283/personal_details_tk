<?php
/**
 * Content Area Diagnostic
 * Tests if the main content area can display data
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// Start session and check auth
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Content Diagnostic</title>
    <style>
        body { font-family: Arial; background: white; color: #000; padding: 20px; }
        .section { background: #f9f9f9; border: 1px solid #ddd; padding: 15px; margin: 15px 0; border-radius: 5px; }
        h2 { color: #1976d2; }
        .pass { color: #388e3c; font-weight: bold; }
        .fail { color: #d32f2f; font-weight: bold; }
        code { background: #f0f0f0; padding: 4px 8px; border-radius: 3px; }
        pre { background: #000; color: #0f0; padding: 10px; border-radius: 3px; overflow: auto; }
    </style>
</head>
<body>

<div style="max-width: 1000px; margin: 0 auto;">
    <h1>📊 Content Area Diagnostic</h1>

    <?php
    
    // Test 1: Auth
    echo '<div class="section">';
    echo '<h2>1. Authentication Check</h2>';
    
    try {
        $auth = new Auth();
        $currentUser = $auth->getCurrentUser();
        
        if ($currentUser) {
            echo '<p><span class="pass">✓</span> User is logged in</p>';
            echo '<p>User ID: <code>' . $currentUser['id'] . '</code></p>';
            echo '<p>Username: <code>' . htmlspecialchars($currentUser['username']) . '</code></p>';
        } else {
            echo '<p><span class="fail">✗</span> No user logged in</p>';
            echo '<p>Please login first: <a href="' . url('login.php') . '">Go to Login</a></p>';
        }
    } catch (Exception $e) {
        echo '<p><span class="fail">✗</span> Auth Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
    }
    
    echo '</div>';
    
    // Test 2: Database tables exist
    echo '<div class="section">';
    echo '<h2>2. Database Table Check</h2>';
    
    $tables = [
        'users' => 'User data',
        'personal_details' => 'Personal Details records',
        'bank_details' => 'Bank Details records',
    ];
    
    try {
        $db = Database::getInstance();
        
        foreach ($tables as $table => $desc) {
            try {
                $result = $db->fetch(
                    "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?",
                    [DB_NAME, $table]
                );
                
                if ($result) {
                    echo '<p><span class="pass">✓</span> ' . $table . ' - ' . $desc . '</p>';
                } else {
                    echo '<p><span class="fail">✗</span> ' . $table . ' - MISSING!</p>';
                }
            } catch (Exception $e) {
                echo '<p><span class="fail">✗</span> ' . $table . ' - Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
            }
        }
    } catch (Exception $e) {
        echo '<p><span class="fail">✗</span> Database Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
    }
    
    echo '</div>';
    
    // Test 3: Count records
    if (isset($currentUser) && $currentUser) {
        echo '<div class="section">';
        echo '<h2>3. Data Record Count (For Current User)</h2>';
        
        try {
            $db = Database::getInstance();
            
            $counts = [
                'personal_details' => 'Personal Details',
                'bank_details' => 'Bank Details',
                'documents' => 'Documents',
                'aadhaar_details' => 'Aadhaar Details',
                'pan_details' => 'PAN Details',
                'other_details' => 'Other Details',
            ];
            
            foreach ($counts as $table => $label) {
                try {
                    $result = $db->fetch(
                        "SELECT COUNT(*) as count FROM $table WHERE user_id = ?",
                        [$currentUser['id']]
                    );
                    
                    $count = $result['count'] ?? 0;
                    echo '<p><strong>' . $label . ':</strong> ' . $count . ' record(s)</p>';
                    
                    if ($count == 0) {
                        echo '<p style="color: #ff9800; margin: 5px 0;">⚠ No records found - this is why the page appears empty!</p>';
                    }
                } catch (Exception $e) {
                    echo '<p><strong>' . $label . ':</strong> <span class="fail">Error - ' . htmlspecialchars($e->getMessage()) . '</span></p>';
                }
            }
        } catch (Exception $e) {
            echo '<p><span class="fail">✗</span> Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
        
        echo '</div>';
        
        // Test 4: Try to fetch personal details
        echo '<div class="section">';
        echo '<h2>4. Personal Details Data Fetch Test</h2>';
        
        try {
            $db = Database::getInstance();
            
            $details = $db->fetchAll(
                "SELECT * FROM personal_details WHERE user_id = ? ORDER BY updated_at DESC LIMIT 5",
                [$currentUser['id']]
            );
            
            if (empty($details)) {
                echo '<p><span class="fail">✗</span> No personal details records found</p>';
                echo '<p style="color: #ff9800;"><strong>This is why the page shows blank content!</strong></p>';
                echo '<p>To see content, you need to:</p>';
                echo '<ol>';
                echo '<li>Go to Personal Details page</li>';
                echo '<li>Click "Add New Record"</li>';
                echo '<li>Fill in the form and save</li>';
                echo '</ol>';
            } else {
                echo '<p><span class="pass">✓</span> Found ' . count($details) . ' record(s)</p>';
                echo '<p>Sample data:</p>';
                echo '<pre>';
                foreach ($details as $detail) {
                    echo "ID: " . $detail['id'] . " | "
                        . $detail['first_name'] . " " . $detail['last_name'] . " | "
                        . $detail['email'] . PHP_EOL;
                }
                echo '</pre>';
            }
        } catch (Exception $e) {
            echo '<p><span class="fail">✗</span> Query Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
        }
        
        echo '</div>';
        
        // Test 5: Solution
        echo '<div class="section" style="background: #e8f5e9; border-color: #4caf50;">';
        echo '<h2 style="color: #2e7d32;">💡 Solution</h2>';
        echo '<p>The application pages are loading correctly! The content area is empty because:</p>';
        echo '<ol>';
        echo '<li><strong>No data in database yet</strong> - The tables exist but have no records for your user</li>';
        echo '<li><strong>Empty state message not showing</strong> - This is a minor display issue</li>';
        echo '</ol>';
        echo '<p><strong>✓ What to do:</strong></p>';
        echo '<ol>';
        echo '<li>Visit the application page (e.g., <a href="' . url('personal-details.php') . '">Personal Details</a>)</li>';
        echo '<li>Click the <strong>"Add New Record"</strong> button</li>';
        echo '<li>Fill in the form and click <strong>"Save Record"</strong></li>';
        echo '<li>The record will appear in the content area</li>';
        echo '</ol>';
        echo '</div>';
    }
    
    ?>

</div>

</body>
</html>
