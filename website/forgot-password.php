<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';

$auth = new Auth();
$mailer = new Mailer();
$db = Database::getInstance();

// Redirect if already logged in
if ($auth->isLoggedIn()) {
    redirect('/dashboard.php');
}

$message = '';
$messageType = '';

// Check if returning from 2FA verification
$twoFAVerified = isset($_GET['2fa_verified']) && !empty($_SESSION['2fa_forgot_verified']);
$pendingEmail = $_SESSION['2fa_forgot_email'] ?? '';

if ($twoFAVerified && !empty($pendingEmail)) {
    // 2FA was verified - now send the reset email
    unset($_SESSION['2fa_forgot_verified']);
    $storedEmail = $pendingEmail;
    unset($_SESSION['2fa_forgot_email']);

    $result = $auth->createPasswordResetToken($storedEmail);
    if ($result['success']) {
        $resetUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
            . '://' . $_SERVER['HTTP_HOST']
            . (defined('BASE_PATH') ? BASE_PATH : '')
            . '/reset-password.php?token=' . $result['token'];

        $mailer->sendPasswordReset(
            $result['user']['email'],
            $result['user']['full_name'],
            $result['token'],
            $resetUrl
        );
        $message = '2FA verified! Password reset link has been sent to ' . htmlspecialchars($storedEmail);
        $messageType = 'success';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize($_POST['email'] ?? '');

    if (empty($email)) {
        $message = 'Please enter your email address';
        $messageType = 'error';
    } else {
        // Check if this user exists and has 2FA enabled
        $userCheck = $db->fetch(
            "SELECT id, email, full_name, totp_enabled, totp_secret FROM users WHERE email = ? AND is_active = 1",
            [$email]
        );

        if (!$userCheck) {
            // Email not found
            $message = 'Email not found. Please check and try again, or create a new account.';
            $messageType = 'error';
        } elseif (!empty($userCheck['totp_enabled']) && !empty($userCheck['totp_secret'])) {
            // User has 2FA enabled - redirect to verify first
            $_SESSION['2fa_forgot_password'] = true;
            $_SESSION['2fa_forgot_user_id'] = $userCheck['id'];
            $_SESSION['2fa_forgot_email'] = $email;
            redirect('/verify-2fa.php');
        } else {
            // No 2FA - send reset email directly
            $result = $auth->createPasswordResetToken($email);

            if ($result['success']) {
                $message = 'Password reset link has been sent to ' . htmlspecialchars($email);
                $messageType = 'success';

                $resetUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                    . '://' . $_SERVER['HTTP_HOST']
                    . (defined('BASE_PATH') ? BASE_PATH : '')
                    . '/reset-password.php?token=' . $result['token'];

                $mailer->sendPasswordReset(
                    $result['user']['email'],
                    $result['user']['full_name'],
                    $result['token'],
                    $resetUrl
                );
            } else {
                $message = 'Email not found. Please check and try again, or create a new account.';
                $messageType = 'error';
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
    <title>Forgot Password | Personal Details Saver</title>
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
                <i class="fas fa-key"></i>
            </div>
            <h1>Forgot Password?</h1>
            <p>We'll send you a link to reset your password</p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?> animate-slide-in">
                <i class="fas fa-<?php echo $messageType === 'error' ? 'exclamation-circle' : 'check-circle'; ?>"></i>
                <span><?php echo htmlspecialchars($message); ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="" style="margin-top: 24px;">
            <div class="form-group">
                <label>Email Address</label>
                <div class="input-wrapper">
                    <input type="email" name="email" placeholder="Enter your registered email" required>
                    <i class="fas fa-envelope"></i>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-full btn-lg">
                <span class="btn-text">Send Reset Link</span>
                <i class="fas fa-envelope"></i>
            </button>
        </form>

        <div style="margin-top: 24px; text-align: center; padding-top: 16px; border-top: 1px solid var(--border);">
            <p style="color: var(--text-secondary); font-size: 0.95rem; margin: 0;">
                Remember your password? <a href="<?php echo url('login.php'); ?>" style="color: var(--primary); text-decoration: none; font-weight: 600;">Sign In</a>
            </p>
        </div>

        <div class="auth-footer">
            Don't have an account? <a href="<?php echo url('register.php'); ?>">Create one</a>
        </div>
    </div>
</div>

<script src="<?php echo url('assets/js/app.js'); ?>"></script>
</body>
</html>
