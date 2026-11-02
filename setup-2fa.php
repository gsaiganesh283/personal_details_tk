<?php
/**
 * 2FA Setup Page - Enable/Disable Google Authenticator
 * Accessible from Profile > Security tab
 */
$pageTitle = 'Setup Two-Factor Authentication';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/totp.php';
$user = requireAuth();
$db = Database::getInstance();

$totp = new TOTP();
$message = '';
$messageType = '';
$step = 'start'; // start, scan, done

// Get current 2FA status
$userData = $db->fetch("SELECT totp_secret, totp_enabled FROM users WHERE id = ?", [$user['id']]);
$is2FAEnabled = !empty($userData['totp_enabled']);

// Handle disable 2FA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'disable_2fa') {
    $code = trim($_POST['totp_code'] ?? '');
    if (!empty($userData['totp_secret']) && $totp->verifyCode($userData['totp_secret'], $code)) {
        $db->update("UPDATE users SET totp_enabled = 0, totp_secret = NULL WHERE id = ?", [$user['id']]);
        setFlash('success', 'Two-factor authentication has been disabled.');
        redirect('/profile.php#securityTab');
        exit;
    } else {
        $message = 'Invalid code. Please try again.';
        $messageType = 'error';
    }
}

// Handle enable 2FA - Step 1: Generate secret
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_secret') {
    $secret = $totp->generateSecret();
    $_SESSION['pending_totp_secret'] = $secret;
    $step = 'scan';
}

// Handle enable 2FA - Step 2: Verify code and activate
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_and_enable') {
    $code = trim($_POST['totp_code'] ?? '');
    $secret = $_SESSION['pending_totp_secret'] ?? '';

    if (empty($secret)) {
        $message = 'Session expired. Please start over.';
        $messageType = 'error';
        $step = 'start';
    } elseif (empty($code)) {
        $message = 'Please enter the 6-digit code from your authenticator app.';
        $messageType = 'error';
        $step = 'scan';
    } elseif ($totp->verifyCode($secret, $code)) {
        // Code verified! Save secret and enable 2FA
        $db->update("UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?", [$secret, $user['id']]);
        unset($_SESSION['pending_totp_secret']);
        setFlash('success', 'Two-factor authentication is now enabled! You will need your authenticator app for every login.');
        redirect('/profile.php#securityTab');
        exit;
    } else {
        $message = 'Invalid code. Make sure the time on your phone is correct and try again.';
        $messageType = 'error';
        $step = 'scan';
    }
}

// If we're on scan step, get pending secret
$pendingSecret = $_SESSION['pending_totp_secret'] ?? '';
if ($step === 'scan' || (!empty($pendingSecret) && !$is2FAEnabled)) {
    $step = 'scan';
}

$qrUrl = '';
$otpUri = '';
if ($step === 'scan' && !empty($pendingSecret)) {
    $account = $user['email'] ?: $user['username'];
    $qrUrl = $totp->getQrCodeUrl($pendingSecret, $account, 250);
    $otpUri = $totp->getOtpAuthUri($pendingSecret, $account);
}
?>

<div class="card" style="max-width: 600px; margin: 0 auto;">
    <div class="card-header">
        <h2><i class="fas fa-shield-alt" style="margin-right: 8px; color: var(--primary);"></i> Two-Factor Authentication</h2>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?> animate-slide-in">
            <i class="fas fa-<?php echo $messageType === 'error' ? 'exclamation-circle' : 'check-circle'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($is2FAEnabled): ?>
        <!-- Already enabled - show disable option -->
        <div style="text-align: center; padding: 20px 0;">
            <div style="width: 80px; height: 80px; border-radius: 50%; background: var(--success); color: white; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 32px;">
                <i class="fas fa-check"></i>
            </div>
            <h3 style="color: var(--success); margin-bottom: 8px;">2FA is Active</h3>
            <p style="color: var(--text-secondary); margin-bottom: 24px;">
                Your account is protected with Google Authenticator. You'll need your authenticator app code for every login.
            </p>
        </div>

        <div style="border-top: 1px solid var(--border); padding-top: 20px;">
            <h4 style="color: var(--danger); margin-bottom: 12px;"><i class="fas fa-exclamation-triangle"></i> Disable 2FA</h4>
            <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 16px;">
                Enter your current authenticator code to disable two-factor authentication.
            </p>
            <form method="POST" action="">
                <input type="hidden" name="action" value="disable_2fa">
                <div class="form-group">
                    <label>Authenticator Code</label>
                    <div class="input-wrapper">
                        <input type="text" name="totp_code" placeholder="Enter 6-digit code" 
                               maxlength="6" pattern="[0-9]{6}" inputmode="numeric" 
                               autocomplete="one-time-code" required
                               style="font-size: 1.5rem; text-align: center; letter-spacing: 8px; font-family: monospace;">
                        <i class="fas fa-key"></i>
                    </div>
                </div>
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-times"></i> Disable 2FA
                </button>
            </form>
        </div>

    <?php elseif ($step === 'scan' && !empty($pendingSecret)): ?>
        <!-- Step 2: Scan QR code and verify -->
        <div style="padding: 10px 0;">
            <div style="background: var(--bg-input); border-radius: var(--radius-md); padding: 16px; margin-bottom: 20px;">
                <h4 style="margin: 0 0 8px 0;"><i class="fas fa-info-circle" style="color: var(--primary);"></i> Step 1: Scan QR Code</h4>
                <p style="color: var(--text-secondary); margin: 0; font-size: 0.9rem;">
                    Open <strong>Google Authenticator</strong> (or any TOTP app) on your phone and scan this QR code.
                </p>
            </div>

            <div style="text-align: center; margin: 20px 0;">
                <div style="background: white; display: inline-block; padding: 16px; border-radius: var(--radius-md); border: 2px solid var(--border);">
                    <img src="<?php echo htmlspecialchars($qrUrl); ?>" alt="QR Code for 2FA Setup" 
                         style="width: 250px; height: 250px; display: block;">
                </div>
            </div>

            <div style="background: var(--bg-input); border-radius: var(--radius-md); padding: 12px; margin-bottom: 20px;">
                <p style="color: var(--text-secondary); font-size: 0.85rem; margin: 0 0 6px 0;">
                    <strong>Can't scan?</strong> Enter this secret key manually:
                </p>
                <code style="background: var(--bg-card); padding: 8px 12px; border-radius: var(--radius-sm); display: block; text-align: center; font-size: 1.1rem; letter-spacing: 3px; word-break: break-all; color: var(--primary); font-weight: 600;">
                    <?php echo htmlspecialchars(implode(' ', str_split($pendingSecret, 4))); ?>
                </code>
            </div>

            <div style="background: var(--bg-input); border-radius: var(--radius-md); padding: 16px; margin-bottom: 20px;">
                <h4 style="margin: 0 0 8px 0;"><i class="fas fa-check-circle" style="color: var(--success);"></i> Step 2: Enter Verification Code</h4>
                <p style="color: var(--text-secondary); margin: 0; font-size: 0.9rem;">
                    Enter the 6-digit code shown in your authenticator app to confirm setup.
                </p>
            </div>

            <form method="POST" action="">
                <input type="hidden" name="action" value="verify_and_enable">
                <div class="form-group">
                    <label>6-Digit Verification Code</label>
                    <div class="input-wrapper">
                        <input type="text" name="totp_code" placeholder="000000" 
                               maxlength="6" pattern="[0-9]{6}" inputmode="numeric"
                               autocomplete="one-time-code" required autofocus
                               style="font-size: 1.8rem; text-align: center; letter-spacing: 10px; font-family: monospace; padding: 12px;">
                        <i class="fas fa-key"></i>
                    </div>
                </div>
                <div style="display: flex; gap: 12px;">
                    <button type="submit" class="btn btn-primary" style="flex: 1;">
                        <i class="fas fa-check"></i> Verify & Enable 2FA
                    </button>
                    <a href="<?php echo url('setup-2fa.php'); ?>" class="btn btn-secondary">
                        <i class="fas fa-redo"></i> Start Over
                    </a>
                </div>
            </form>
        </div>

    <?php else: ?>
        <!-- Step 1: Introduction - Enable 2FA -->
        <div style="text-align: center; padding: 20px 0;">
            <div style="width: 80px; height: 80px; border-radius: 50%; background: var(--bg-input); color: var(--text-secondary); display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 32px;">
                <i class="fas fa-mobile-alt"></i>
            </div>
            <h3 style="margin-bottom: 8px;">Add Extra Security</h3>
            <p style="color: var(--text-secondary); max-width: 400px; margin: 0 auto 24px;">
                Two-factor authentication adds an extra layer of security to your account. 
                After sign-in, you'll need to enter a code from the <strong>Google Authenticator</strong> app on your phone.
            </p>
        </div>

        <div style="background: var(--bg-input); border-radius: var(--radius-md); padding: 16px; margin-bottom: 20px;">
            <h4 style="margin: 0 0 12px 0;">You'll need:</h4>
            <ul style="margin: 0; padding-left: 20px; color: var(--text-secondary); line-height: 2;">
                <li>A smartphone with <strong>Google Authenticator</strong> installed</li>
                <li>Available for <a href="https://play.google.com/store/apps/details?id=com.google.android.apps.authenticator2" target="_blank" style="color: var(--primary);">Android</a> and <a href="https://apps.apple.com/app/google-authenticator/id388497605" target="_blank" style="color: var(--primary);">iOS</a></li>
            </ul>
        </div>

        <form method="POST" action="">
            <input type="hidden" name="action" value="generate_secret">
            <button type="submit" class="btn btn-primary btn-lg btn-full">
                <i class="fas fa-qrcode"></i> Set Up Google Authenticator
            </button>
        </form>
    <?php endif; ?>

    <div style="margin-top: 20px; text-align: center;">
        <a href="<?php echo url('profile.php#securityTab'); ?>" style="color: var(--text-secondary); text-decoration: none;">
            <i class="fas fa-arrow-left"></i> Back to Profile
        </a>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
