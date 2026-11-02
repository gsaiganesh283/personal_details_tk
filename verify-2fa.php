<?php
/**
 * 2FA Verification Page
 * Shown after successful password login when user has 2FA enabled.
 * Also used for forgot-password 2FA verification.
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/totp.php';

$auth = new Auth();

// If fully logged in already, go to dashboard
if ($auth->isLoggedIn()) {
    redirect('/dashboard.php');
}

// Check if we're in a 2FA-pending state (set by login.php after password OK)
$pending2FA = $_SESSION['2fa_pending'] ?? false;
$pendingUserId = $_SESSION['2fa_user_id'] ?? null;
$pendingRemember = $_SESSION['2fa_remember'] ?? false;

// Check if this is a forgot-password 2FA verification
$forgotPassword2FA = $_SESSION['2fa_forgot_password'] ?? false;
$forgotPasswordEmail = $_SESSION['2fa_forgot_email'] ?? '';
$forgotPasswordUserId = $_SESSION['2fa_forgot_user_id'] ?? null;

if (!$pending2FA && !$forgotPassword2FA) {
    // No 2FA pending - redirect to login
    redirect('/login.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['totp_code'] ?? '');

    if (empty($code) || !preg_match('/^\d{6}$/', $code)) {
        $errors[] = 'Please enter a valid 6-digit code';
    } else {
        $db = Database::getInstance();
        $totp = new TOTP();

        if ($pending2FA && $pendingUserId) {
            // LOGIN 2FA verification
            $userData = $db->fetch("SELECT totp_secret FROM users WHERE id = ?", [$pendingUserId]);

            if ($userData && $totp->verifyCode($userData['totp_secret'], $code)) {
                // 2FA verified! Complete login
                unset($_SESSION['2fa_pending'], $_SESSION['2fa_user_id'], $_SESSION['2fa_remember']);
                
                // Manually create full session (same as Auth::createSession)
                $_SESSION['user_id'] = $pendingUserId;
                $_SESSION['logged_in'] = true;
                $_SESSION['login_time'] = time();
                $_SESSION['last_activity'] = time();

                // Handle remember me
                if ($pendingRemember) {
                    $token = bin2hex(random_bytes(32));
                    $expires = date('Y-m-d H:i:s', time() + 30 * 24 * 60 * 60);
                    $db->insert(
                        "INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, expires_at) VALUES (?, ?, ?, ?, ?)",
                        [$pendingUserId, $token, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '', $expires]
                    );
                    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
                    setcookie('remember_token', $token, time() + 30 * 24 * 60 * 60, '/', '', $isHttps, true);
                }

                // Log activity
                $db->insert(
                    "INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
                    [$pendingUserId, 'login_2fa', 'Logged in with 2FA verification', $_SERVER['REMOTE_ADDR'] ?? '']
                );

                setFlash('success', 'Welcome back! 2FA verified.');
                redirect('/dashboard.php');
            } else {
                $errors[] = 'Invalid code. Check your authenticator app and try again.';
            }

        } elseif ($forgotPassword2FA && $forgotPasswordUserId) {
            // FORGOT PASSWORD 2FA verification
            $userData = $db->fetch("SELECT totp_secret FROM users WHERE id = ?", [$forgotPasswordUserId]);

            if ($userData && $totp->verifyCode($userData['totp_secret'], $code)) {
                // 2FA verified! Proceed with sending reset email
                $_SESSION['2fa_forgot_verified'] = true;
                unset($_SESSION['2fa_forgot_password'], $_SESSION['2fa_forgot_user_id']);
                
                redirect('/forgot-password.php?2fa_verified=1');
            } else {
                $errors[] = 'Invalid code. Check your authenticator app and try again.';
            }
        }
    }
}

// Determine context for display
$isForLogin = $pending2FA;
$isForForgot = $forgotPassword2FA;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="theme-color" content="#6C63FF">
    <title>2FA Verification | Personal Details Saver</title>
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
            <div class="auth-logo" style="background: linear-gradient(135deg, #6C63FF, #5A55E3);">
                <i class="fas fa-shield-alt"></i>
            </div>
            <h1>Two-Factor Authentication</h1>
            <p>
                <?php if ($isForForgot): ?>
                    Enter the code from your authenticator app to verify your identity for password reset.
                <?php else: ?>
                    Enter the 6-digit code from your Google Authenticator app to complete sign-in.
                <?php endif; ?>
            </p>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error animate-slide-in">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo implode('<br>', $errors); ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="" id="twoFactorForm" style="margin-top: 24px;">
            <div class="form-group">
                <label>Authentication Code</label>
                <div class="input-wrapper">
                    <input type="text" name="totp_code" placeholder="000000" 
                           maxlength="6" pattern="[0-9]{6}" inputmode="numeric"
                           autocomplete="one-time-code" required autofocus
                           style="font-size: 2rem; text-align: center; letter-spacing: 12px; font-family: monospace; padding: 14px;">
                    <i class="fas fa-key"></i>
                </div>
                <small style="color: var(--text-muted); margin-top: 8px; display: block;">
                    Open Google Authenticator on your phone and enter the current code
                </small>
            </div>

            <button type="submit" class="btn btn-primary btn-full btn-lg">
                <span class="btn-text">Verify</span>
                <i class="fas fa-arrow-right"></i>
            </button>
        </form>

        <div style="margin-top: 24px; text-align: center; padding-top: 16px; border-top: 1px solid var(--border);">
            <?php if ($isForForgot): ?>
                <p style="color: var(--text-secondary); font-size: 0.9rem; margin: 0;">
                    <a href="<?php echo url('forgot-password.php'); ?>" style="color: var(--primary); text-decoration: none;">
                        <i class="fas fa-arrow-left"></i> Back to Forgot Password
                    </a>
                </p>
            <?php else: ?>
                <p style="color: var(--text-secondary); font-size: 0.9rem; margin: 0;">
                    <a href="<?php echo url('login.php'); ?>" style="color: var(--primary); text-decoration: none;">
                        <i class="fas fa-arrow-left"></i> Back to Login
                    </a>
                </p>
            <?php endif; ?>
        </div>

        <div style="margin-top: 12px; text-align: center;">
            <p style="color: var(--text-muted); font-size: 0.8rem; margin: 0;">
                Lost access to your authenticator? Contact the administrator.
            </p>
        </div>
    </div>
</div>

<script src="<?php echo url('assets/js/app.js'); ?>"></script>
<script>
// Auto-submit when 6 digits are entered
document.querySelector('input[name="totp_code"]').addEventListener('input', function() {
    // Only allow digits
    this.value = this.value.replace(/[^0-9]/g, '');
    if (this.value.length === 6) {
        // Small delay for visual feedback
        setTimeout(() => {
            document.getElementById('twoFactorForm').submit();
        }, 200);
    }
});
</script>
</body>
</html>
