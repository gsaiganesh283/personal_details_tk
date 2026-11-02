<?php
/**
 * Migration: Change custom_fields.field_type from ENUM to VARCHAR(30)
 * 
 * The ENUM only allows 11 types, but the app supports 24 types.
 * When MySQL receives a value not in the ENUM (like 'pdf', 'password', 'file'),
 * it silently stores the default 'text' instead.
 * 
 * Run this once on your hosted site, then delete the file.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/database.php';

echo "<h1>Custom Fields Migration</h1>";

try {
    $db = Database::getInstance();
    
    // 1. Check current column type
    $colInfo = $db->fetch(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'custom_fields' AND COLUMN_NAME = 'field_type'",
        [DB_NAME]
    );
    
    if (!$colInfo) {
        echo "<p style='color:red;'>ERROR: custom_fields table or field_type column not found!</p>";
        exit;
    }
    
    $currentType = $colInfo['COLUMN_TYPE'];
    echo "<p><strong>Current column type:</strong> <code>$currentType</code></p>";
    
    if (stripos($currentType, 'enum') !== false) {
        echo "<p style='color:orange;'>ENUM detected — this limits which field types can be saved. Migrating...</p>";
        
        // 2. Show current data before migration
        $fields = $db->fetchAll("SELECT id, field_name, field_type FROM custom_fields ORDER BY id");
        if ($fields) {
            echo "<h3>Current field data:</h3><table border='1' cellpadding='5'><tr><th>ID</th><th>Name</th><th>Type</th></tr>";
            foreach ($fields as $f) {
                echo "<tr><td>{$f['id']}</td><td>{$f['field_name']}</td><td>{$f['field_type']}</td></tr>";
            }
            echo "</table>";
        }
        
        // 3. Run the ALTER TABLE
        $db->getConnection()->exec("ALTER TABLE custom_fields MODIFY COLUMN field_type VARCHAR(30) NOT NULL DEFAULT 'text'");
        
        // 4. Verify
        $newColInfo = $db->fetch(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'custom_fields' AND COLUMN_NAME = 'field_type'",
            [DB_NAME]
        );
        
        echo "<p style='color:green;'><strong>SUCCESS!</strong> Column type changed to: <code>{$newColInfo['COLUMN_TYPE']}</code></p>";
        echo "<p>All 24 field types are now supported: text, number, email, password, phone, url, currency, date, time, datetime, textarea, select, multiselect, checkbox, radio, toggle, rating, file, image, pdf, document, spreadsheet, color, range</p>";
        
    } else {
        echo "<p style='color:green;'>Column is already <code>$currentType</code> — no migration needed!</p>";
    }
    
    echo "<hr><p><strong>IMPORTANT:</strong> Delete this file after running it.</p>";
    echo "<p><a href='custom-fields.php'>Go to Custom Fields</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color:red;'>ERROR: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>You can run this manually in phpMyAdmin:</p>";
    echo "<pre>ALTER TABLE custom_fields MODIFY COLUMN field_type VARCHAR(30) NOT NULL DEFAULT 'text';</pre>";
}
