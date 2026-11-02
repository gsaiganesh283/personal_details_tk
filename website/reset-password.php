<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$auth = new Auth();

// Redirect if already logged in
if ($auth->isLoggedIn()) {
    redirect('/dashboard.php');
}

$token = $_GET['token'] ?? '';
$errors = [];
$user = null;

// Validate token exists
if (!empty($token)) {
    $validation = $auth->validatePasswordResetToken($token);
    if ($validation['success']) {
        $user = $validation['user'];
    } else {
        $errors[] = $validation['error'];
    }
} else {
    $errors[] = 'No reset token provided.';
}

// Handle password reset form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($token)) {
        $errors[] = 'Reset token is missing.';
    } else {
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($password !== $confirmPassword) {
            $errors[] = 'Passwords do not match';
        } else {
            $result = $auth->resetPassword($token, $password);
            if ($result['success']) {
                setFlash('success', 'Password reset successfully! Please log in with your new password.');
                redirect('/login.php');
            } else {
                $errors = array_merge($errors, $result['errors']);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="theme-color" content="#6C63FF">
    <title>Reset Password | Personal Details Saver</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo url('assets/css/style.css'); ?>">
    <link rel="stylesheet" href="<?php echo url('assets/css/responsive.css'); ?>">
</head>
<body class="auth-page">

<div class="auth-particles"></div>

<div class="auth-container">
    <div class="auth-card" style="max-width: 450px;">
        <div class="auth-header">
            <div class="auth-logo">
                <i class="fas fa-lock"></i>
            </div>
            <h1>Create New Password</h1>
            <p>Enter a strong password to secure your account</p>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error animate-slide-in">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo implode('<br>', array_map('htmlspecialchars', $errors)); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($user and empty($errors)): ?>
            <form method="POST" action="" id="resetForm">
                <div class="form-group">
                    <label>New Password</label>
                    <div class="input-wrapper">
                        <input type="password" name="password" id="password" placeholder="Create a strong password" required>
                        <i class="fas fa-lock"></i>
                        <span class="toggle-password" tabindex="0" role="button" aria-label="Toggle password visibility"><i class="fas fa-eye"></i></span>
                    </div>
                    <div class="password-strength">
                        <div class="strength-bar">
                            <div class="strength-segment"></div>
                            <div class="strength-segment"></div>
                            <div class="strength-segment"></div>
                            <div class="strength-segment"></div>
                            <div class="strength-segment"></div>
                        </div>
                        <span class="strength-text"></span>
                    </div>
                    <small style="color: var(--text-muted); display: block; margin-top: 8px;">
                        ✓ At least 8 characters<br>
                        ✓ At least 1 uppercase letter<br>
                        ✓ At least 1 lowercase letter<br>
                        ✓ At least 1 number
                    </small>
                </div>

                <div class="form-group">
                    <label>Confirm Password</label>
                    <div class="input-wrapper">
                        <input type="password" name="confirm_password" placeholder="Confirm your password" required>
                        <i class="fas fa-lock"></i>
                        <span class="toggle-password" tabindex="0" role="button" aria-label="Toggle password visibility"><i class="fas fa-eye"></i></span>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-full btn-lg">
                    <span class="btn-text">Reset Password</span>
                    <i class="fas fa-arrow-right"></i>
                </button>
            </form>
        <?php else: ?>
            <div style="padding: 20px; text-align: center; color: var(--text-secondary);">
                <p>This password reset link is invalid or has expired. Please request a new one.</p>
                <a href="<?php echo url('forgot-password.php'); ?>" class="btn btn-primary" style="margin-top: 16px; display: inline-block;">
                    Request New Link
                </a>
            </div>
        <?php endif; ?>

        <div class="auth-footer" style="margin-top: 24px;">
            Back to <a href="<?php echo url('login.php'); ?>">Sign In</a>
        </div>
    </div>
</div>

<script src="<?php echo url('assets/js/app.js'); ?>"></script>
</body>
</html>
