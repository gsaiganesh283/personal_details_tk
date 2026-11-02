<?php
/**
 * Login Test Page - Simplified without Auth checking for diagnosis
 * This shows if pages render correctly when auth is skipped
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Don't start session or load auth - just test HTML rendering
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#6C63FF">
    <title>Login Test | Personal Details Saver</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo url('assets/css/style.css'); ?>">
    <link rel="stylesheet" href="<?php echo url('assets/css/responsive.css'); ?>">
</head>
<body class="auth-page">

<div class="auth-particles"></div>

<div class="auth-container">
    <div class="auth-card">
        <div class="auth-header">
            <div class="auth-logo">
                <i class="fas fa-user-shield"></i>
            </div>
            <h1>Personal Details Saver</h1>
            <p>Secure Information Management</p>
        </div>

        <div class="auth-body">
            <h2>Login</h2>
            
            <div class="alert alert-info" style="margin-bottom: 20px;">
                <strong>ℹ Test Page:</strong> This is a test login page without authentication checking.
                <br>If you can see this with styling, the page rendering works!
            </div>

            <form method="POST" action="<?php echo url('login.php'); ?>" class="auth-form">
                <div class="form-group">
                    <label for="username">Username or Email</label>
                    <input 
                        type="text" 
                        id="username" 
                        name="username" 
                        placeholder="Enter your username or email"
                        required
                        style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; box-sizing: border-box;"
                    >
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        placeholder="Enter your password"
                        required
                        style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; box-sizing: border-box;"
                    >
                </div>

                <div class="form-group">
                    <label style="display: flex; align-items: center; cursor: pointer;">
                        <input type="checkbox" name="remember_me" style="margin-right: 8px;">
                        Remember me for 30 days
                    </label>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; background: #6C63FF; color: white; border: none; border-radius: 4px; font-size: 16px; cursor: pointer; font-weight: bold;">
                    Login
                </button>
            </form>

            <div class="auth-footer" style="margin-top: 20px; text-align: center;">
                <p style="margin: 0; color: #666;">Don't have an account? <a href="<?php echo url('register.php'); ?>" style="color: #6C63FF; text-decoration: none; font-weight: bold;">Register here</a></p>
            </div>
        </div>

        <div style="margin-top: 20px; padding: 15px; background: #e8f5e9; border-radius: 4px; border-left: 3px solid #4caf50;">
            <h3 style="margin-top: 0; color: #2e7d32;">✓ Test Result</h3>
            <p style="margin: 0; color: #558b2f;">
                <strong>If you can see this page with:</strong>
            </p>
            <ul style="margin: 10px 0 0; color: #558b2f;">
                <li>A styled login form (not blank)</li>
                <li>Colored buttons and text</li>
                <li>Proper spacing and layout</li>
            </ul>
            <p style="margin: 10px 0 0; color: #2e7d32;">
                <strong>Then: Page rendering works! 👍</strong>
                <br>The issue is with authentication checking in the real login.php
            </p>
        </div>
    </div>
</div>

<script>
    console.log('Test Login Page Loaded');
    console.log('BASE_PATH:', '<?php echo defined('BASE_PATH') ? BASE_PATH : '(empty)'; ?>');
    console.log('CSS should be loading from:', '<?php echo htmlspecialchars(url('assets/css/style.css')); ?>');
</script>

</body>
</html>
