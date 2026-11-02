<?php
/**
 * Minimal Form Validator
 * Ultra-simple diagnostic that checks if forms can be submitted
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$errors = [];
$success = [];

// Try to initialize basic config
try {
    require_once __DIR__ . '/config/database.php';
    $success[] = '✓ Database config loaded';
} catch (Exception $e) {
    $errors[] = 'Database config error: ' . $e->getMessage();
}

// Try to initialize functions
try {
    require_once __DIR__ . '/includes/functions.php';
    $success[] = '✓ Functions loaded';
} catch (Exception $e) {
    $errors[] = 'Functions error: ' . $e->getMessage();
}

// Try to initialize auth
try {
    require_once __DIR__ . '/includes/auth.php';
    $success[] = '✓ Auth loaded';
} catch (Exception $e) {
    $errors[] = 'Auth error: ' . $e->getMessage();
}

// Check if user is logged in
$user = null;
if (empty($errors)) {
    try {
        if (!isset($_SESSION['user_id'])) {
            header('Location: ' . url('login.php'));
            exit;
        }
        $user = [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'] ?? 'Unknown'
        ];
        $success[] = '✓ User authenticated: ' . $user['username'];
    } catch (Exception $e) {
        $errors[] = 'Auth check error: ' . $e->getMessage();
    }
}

// Try to connect to database
if (empty($errors) && $user) {
    try {
        $db = Database::getInstance();
        $result = $db->fetch("SELECT 1 as test");
        $success[] = '✓ Database connection OK';
        
        // Check personal_details table
        $count = $db->fetch("SELECT COUNT(*) as cnt FROM personal_details WHERE user_id = ?", [$user['id']]);
        $success[] = '✓ Personal details table OK (Your records: ' . $count['cnt'] . ')';
    } catch (Exception $e) {
        $errors[] = 'Database error: ' . $e->getMessage();
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Form Validator</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #0f1419;
            color: #e0e0e0;
            padding: 20px;
        }
        .container { max-width: 600px; margin: 0 auto; }
        h1 { margin-bottom: 24px; color: #fff; }
        .status { 
            padding: 16px; 
            border-radius: 8px; 
            margin-bottom: 12px; 
            border-left: 4px solid;
            background: #1a1f2e;
        }
        .success { border-left-color: #10b981; }
        .error { border-left-color: #ef4444; }
        .info { border-left-color: #3b82f6; }
        code {
            background: #1a1f2e;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: 'Monaco', 'Courier New', monospace;
            font-size: 0.85em;
        }
        .btn {
            display: inline-block;
            padding: 10px 16px;
            background: #6c63ff;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            margin-top: 12px;
            cursor: pointer;
            border: none;
            font-size: 1em;
        }
        .btn:hover { background: #5a51d5; }
        h2 { margin-top: 24px; margin-bottom: 12px; font-size: 1.1em; }
    </style>
</head>
<body>
<div class="container">
    <h1>📋 Form Submission Validator</h1>

    <?php if (!empty($errors)): ?>
        <h2 style="color: #ef4444;">⚠️ Errors Found:</h2>
        <?php foreach ($errors as $error): ?>
            <div class="status error"><?php echo htmlspecialchars($error); ?></div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
        <h2 style="color: #10b981;">✓ System OK:</h2>
        <?php foreach ($success as $msg): ?>
            <div class="status success"><?php echo htmlspecialchars($msg); ?></div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($errors) && empty($success)): ?>
        <div class="status error">
            <strong>Critical Error:</strong> Application cannot start. Please check your configuration.
        </div>
    <?php endif; ?>

    <?php if ($user): ?>
        <h2>📝 Quick Test Form</h2>
        <p style="margin-bottom: 12px; color: #a0a0a0;">
            Try submitting a test record to verify the form works:
        </p>
        <form method="POST" action="<?php echo url('personal-details.php'); ?>">
            <input type="text" name="first_name" placeholder="First Name" value="Test" required 
                   style="width: 100%; padding: 8px; margin-bottom: 8px; background: #1a1f2e; color: #fff; border: 1px solid #333; border-radius: 4px;">
            <input type="text" name="last_name" placeholder="Last Name" value="Record" required
                   style="width: 100%; padding: 8px; margin-bottom: 8px; background: #1a1f2e; color: #fff; border: 1px solid #333; border-radius: 4px;">
            <input type="email" name="detail_email" placeholder="Email" value="test@example.com"
                   style="width: 100%; padding: 8px; margin-bottom: 8px; background: #1a1f2e; color: #fff; border: 1px solid #333; border-radius: 4px;">
            <button type="submit" class="btn">Submit Test Record</button>
        </form>

        <h2>🔗 Quick Links</h2>
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <a href="<?php echo url('personal-details.php'); ?>" class="btn">Go to Personal Details</a>
            <a href="<?php echo url('personal-details.php?action=add'); ?>" class="btn" style="background: #059669;">Add New Record</a>
            <a href="<?php echo url('logout.php'); ?>" class="btn" style="background: #6b7280;">Logout</a>
        </div>
    <?php endif; ?>

    <h2>📞 Debug Info</h2>
    <div class="status info">
        <strong>PHP Version:</strong> <code><?php echo PHP_VERSION; ?></code><br>
        <strong>Base Path:</strong> <code><?php echo defined('BASE_PATH') ? BASE_PATH : '(root)'; ?></code><br>
        <strong>Current URL:</strong> <code><?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?></code>
    </div>
</div>
</body>
</html>
