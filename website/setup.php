<?php
/**
 * Database Setup and Migration Script
 * Run this from browser to ensure all tables are created: /setup.php
 */

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

// Check authentication - allow setup if no users exist
$db = Database::getInstance();
$userCount = $db->fetch("SELECT COUNT(*) as count FROM users");

if ($userCount['count'] > 0 && !isset($admin)) {
    echo "<h1>⚠️ Database Already Initialized</h1>";
    echo "<p>This database already has users. For security, delete migrations manually if you need to reset.</p>";
    exit;
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Personal Details Saver - Database Setup</title>
    <style>
        body { font-family: Arial; max-width: 800px; margin: 50px auto; padding: 20px; }
        .container { background: #f5f5f5; padding: 20px; border-radius: 5px; }
        .error { color: #d32f2f; padding: 10px; background: #ffebee; border-radius: 3px; margin: 10px 0; }
        .success { color: #388e3c; padding: 10px; background: #e8f5e9; border-radius: 3px; margin: 10px 0; }
        .warning { color: #f57f17; padding: 10px; background: #fff3e0; border-radius: 3px; margin: 10px 0; }
        h2 { color: #333; }
        pre { background: #fff; padding: 10px; border: 1px solid #ddd; border-radius: 3px; overflow-x: auto; }
        button { padding: 10px 20px; background: #6C63FF; color: white; border: none; border-radius: 3px; cursor: pointer; }
        button:hover { background: #5a52d5; }
    </style>
</head>
<body>
    <div class="container">
        <h1>📊 Personal Details Saver - Database Setup</h1>

        <?php
        // Get database info
        $dbInfo = [
            'Host' => DB_HOST,
            'Database' => DB_NAME,
            'User' => DB_USER,
            'Charset' => DB_CHARSET,
        ];

        echo "<h2>Database Configuration</h2>";
        echo "<p><strong>Connected to:</strong></p>";
        foreach ($dbInfo as $key => $value) {
            echo "$key: <code>$value</code><br>";
        }

        // Check required tables
        echo "<h2>Checking Database Tables</h2>";

        $tables = [
            'users' => 'User accounts',
            'personal_details' => 'Personal details records',
            'bank_details' => 'Bank account information',
            'documents' => 'Uploaded documents',
            'aadhaar_details' => 'Aadhaar information',
            'pan_details' => 'PAN information',
            'other_details' => 'Other miscellaneous details',
            'custom_fields' => 'Custom field definitions',
            'custom_field_blocks' => 'Custom field grouping',
            'custom_field_entries' => 'Custom field values for repeatable blocks',
            'custom_field_values' => 'Custom field values',
            'activity_log' => 'User activity logs',
            'user_sessions' => 'User session tokens',
        ];

        $existingTables = [];
        $missingTables = [];

        foreach ($tables as $tableName => $description) {
            $result = $db->fetch(
                "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?",
                [DB_NAME, $tableName]
            );
            if ($result) {
                $existingTables[$tableName] = $description;
                echo "<div class='success'>✓ $tableName - $description</div>";
            } else {
                $missingTables[$tableName] = $description;
                echo "<div class='error'>✗ $tableName - $description (MISSING)</div>";
            }
        }

        if (!empty($missingTables)) {
            echo "<h2>⚠️ Missing Tables Detected</h2>";
            echo "<p>The following tables are missing and need to be created:</p>";
            foreach ($missingTables as $table => $description) {
                echo "- $table<br>";
            }

            echo "<p><strong>You have two options:</strong></p>";
            echo "<ol>";
            echo "<li><strong>Option 1 (Automatic):</strong> Click the button below to create missing tables</li>";
            echo "<li><strong>Option 2 (Manual):</strong> Run the SQL files manually via phpMyAdmin:
                <pre>sql/schema.sql
sql/new_tables.sql</pre>";
            echo "</li></ol>";

            echo "<form method='POST'>";
            echo "<button name='create_tables'>Create Missing Tables</button>";
            echo "</form>";

            if (isset($_POST['create_tables'])) {
                echo "<h2>Creating Missing Tables...</h2>";
                
                // List of SQL statements to create missing tables
                $statements = [];

                if (isset($missingTables['custom_field_blocks'])) {
                    $statements[] = "CREATE TABLE IF NOT EXISTS custom_field_blocks (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        block_name VARCHAR(100) NOT NULL,
                        block_label VARCHAR(150) NOT NULL,
                        linked_to VARCHAR(50) NOT NULL DEFAULT 'personal_details',
                        layout VARCHAR(20) DEFAULT '1-col',
                        block_order INT DEFAULT 0,
                        is_repeatable TINYINT(1) DEFAULT 0,
                        max_instances INT DEFAULT 10,
                        is_active TINYINT(1) DEFAULT 1,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
                }

                if (isset($missingTables['custom_field_entries'])) {
                    $statements[] = "CREATE TABLE IF NOT EXISTS custom_field_entries (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        custom_field_id INT NOT NULL,
                        linked_to VARCHAR(50) NOT NULL,
                        record_id INT NOT NULL,
                        block_instance INT DEFAULT 0,
                        field_value TEXT DEFAULT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        UNIQUE KEY uq_field_entry (custom_field_id, linked_to, record_id, block_instance),
                        INDEX idx_record (linked_to, record_id),
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
                }

                // Execute statements
                $success = 0;
                $errors = [];

                foreach ($statements as $sql) {
                    try {
                        $db->getConnection()->exec($sql);
                        $success++;
                        echo "<div class='success'>✓ Table created successfully</div>";
                    } catch (Exception $e) {
                        $errors[] = $e->getMessage();
                        echo "<div class='error'>✗ Error: " . $e->getMessage() . "</div>";
                    }
                }

                if (count($errors) === 0) {
                    echo "<div class='success'><strong>All tables created successfully!</strong></div>";
                    echo "<meta http-equiv='refresh' content='2'>";
                }
            }
        } else {
            echo "<h2>✓ All Tables Exist</h2>";
            echo "<p>The database is properly configured with all required tables.</p>";
            echo "<div class='success'>Database setup is complete!</div>";
        }

        // Check if custom_fields.field_type needs migration from ENUM to VARCHAR
        echo "<h2>Checking Column Types</h2>";

        // Check if 2FA columns exist in users table
        $totpCol = $db->fetch(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'users' AND COLUMN_NAME = 'totp_secret'",
            [DB_NAME]
        );
        if (!$totpCol) {
            echo "<div class='warning'>Adding 2FA columns to users table...</div>";
            try {
                $db->getConnection()->exec("ALTER TABLE users ADD COLUMN totp_secret VARCHAR(64) DEFAULT NULL COMMENT 'Base32 TOTP secret', ADD COLUMN totp_enabled TINYINT(1) DEFAULT 0 COMMENT '2FA active'");
                echo "<div class='success'>✓ 2FA columns added to users table.</div>";
            } catch (Exception $e) {
                echo "<div class='error'>✗ 2FA migration failed: " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        } else {
            echo "<div class='success'>✓ 2FA columns exist in users table.</div>";
        }

        $colInfo = $db->fetch(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'custom_fields' AND COLUMN_NAME = 'field_type'",
            [DB_NAME]
        );
        if ($colInfo) {
            $colType = strtolower($colInfo['COLUMN_TYPE']);
            if (strpos($colType, 'enum') !== false) {
                echo "<div class='warning'>⚠️ custom_fields.field_type is ENUM (limited types). Migrating to VARCHAR(30)...</div>";
                try {
                    $db->getConnection()->exec("ALTER TABLE custom_fields MODIFY COLUMN field_type VARCHAR(30) NOT NULL DEFAULT 'text'");
                    echo "<div class='success'>✓ custom_fields.field_type migrated to VARCHAR(30) — all field types now supported!</div>";
                } catch (Exception $e) {
                    echo "<div class='error'>✗ Migration failed: " . htmlspecialchars($e->getMessage()) . "</div>";
                    echo "<p>Run this manually in phpMyAdmin:<br><pre>ALTER TABLE custom_fields MODIFY COLUMN field_type VARCHAR(30) NOT NULL DEFAULT 'text';</pre></p>";
                }
            } else {
                echo "<div class='success'>✓ custom_fields.field_type is VARCHAR — all field types supported.</div>";
            }
        }

        echo "<h2>Next Steps</h2>";
        echo "<ol>";
        echo "<li><a href='login.php'>Go to Login Page</a></li>";
        echo "<li><a href='register.php'>Create a New Account</a></li>";
        echo "</ol>";

        echo "<hr>";
        echo "<p><small>Setup Script | Personal Details Saver</small></p>";
        ?>
    </div>
</body>
</html>
