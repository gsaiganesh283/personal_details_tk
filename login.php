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

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usernameOrEmail = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $rememberMe = isset($_POST['remember_me']);

    if (empty($usernameOrEmail) || empty($password)) {
        $errors[] = 'Please fill in all fields';
    } else {
        // First: verify credentials without creating full session
        $db = Database::getInstance();
        $user = $db->fetch(
            "SELECT * FROM users WHERE (username = ? OR email = ?) AND is_active = 1",
            [$usernameOrEmail, $usernameOrEmail]
        );

        if (!$user || !password_verify($password, $user['password'])) {
            $errors[] = 'Invalid credentials';
        } else {
            // Check if user has 2FA enabled
            if (!empty($user['totp_enabled']) && !empty($user['totp_secret'])) {
                // Don't log them in yet - store pending state and redirect to 2FA
                $_SESSION['2fa_pending'] = true;
                $_SESSION['2fa_user_id'] = $user['id'];
                $_SESSION['2fa_remember'] = $rememberMe;
                redirect('/verify-2fa.php');
            } else {
                // No 2FA - proceed with normal login
                $result = $auth->login($usernameOrEmail, $password, $rememberMe);
                if ($result['success']) {
                    setFlash('success', 'Welcome back!');
                    redirect('/dashboard.php');
                } else {
                    $errors = $result['errors'];
                }
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
    <title>Login | Personal Details Saver</title>
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
            <h1>Welcome Back</h1>
            <p>Sign in to your account to continue</p>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error animate-slide-in">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo implode('<br>', $errors); ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="" id="loginForm">
            <div class="form-group">
                <label>Username or Email</label>
                <div class="input-wrapper">
                    <input type="text" name="username" placeholder="Enter your username or email" 
                           value="<?php echo htmlspecialchars($usernameOrEmail ?? ''); ?>" required>
                    <i class="fas fa-user"></i>
                </div>
            </div>

            <div class="form-group">
                <label>Password</label>
                <div class="input-wrapper">
                    <input type="password" name="password" placeholder="Enter your password" required>
                    <i class="fas fa-lock"></i>
                    <span class="toggle-password" tabindex="0" role="button" aria-label="Toggle password visibility"><i class="fas fa-eye"></i></span>
                </div>
            </div>

            <div class="auth-links">
                <label class="form-check">
                    <input type="checkbox" name="remember_me">
                    <span class="checkmark"></span>
                    <span class="form-check-label">Remember me</span>
                </label>
                <a href="<?php echo url('forgot-password.php'); ?>" style="color: var(--primary); text-decoration: none; font-weight: 500;">Forgot password?</a>
            </div>

            <button type="submit" class="btn btn-primary btn-full btn-lg">
                <span class="btn-text">Sign In</span>
                <i class="fas fa-arrow-right"></i>
            </button>
        </form>

        <div class="auth-divider">or</div>

        <!-- Fingerprint / Passkey login -->
        <button class="btn btn-secondary btn-full btn-lg" id="fingerprintBtn" style="gap:.6rem;">
            <i class="fas fa-fingerprint" style="font-size:1.2rem;"></i>
            <span>Sign in with Fingerprint / Passkey</span>
        </button>
        <div id="fpStatus" style="display:none;margin-top:.75rem;" class="alert"></div>

        <div class="auth-footer" style="margin-top:1.25rem;">
            Don't have an account? <a href="<?php echo url('register.php'); ?>">Create one</a>
        </div>
    </div>
</div>

<script src="<?php echo url('assets/js/app.js'); ?>"></script>
<script>
(function () {
    const btn    = document.getElementById('fingerprintBtn');
    const status = document.getElementById('fpStatus');
    const form   = document.getElementById('loginForm');

    function showStatus(msg, type) {
        status.className = 'alert alert-' + type;
        status.innerHTML = '<i class="fas fa-' + (type === 'success' ? 'check-circle' : 'exclamation-circle') + '"></i> ' + msg;
        status.style.display = 'flex';
    }

    if (!window.PublicKeyCredential) {
        btn.style.display = 'none'; // hide silently if browser unsupported
        return;
    }

    async function parseJsonResponse(response) {
        const text = await response.text();
        let data = null;
        if (text) {
            try {
                data = JSON.parse(text);
            } catch (e) {
                data = null;
            }
        }
        return { data, text };
    }

    btn.addEventListener('click', async () => {
        // Get username from the form field
        const usernameInput = form.querySelector('input[name="username"]');
        const username = usernameInput ? usernameInput.value.trim() : '';
        if (!username) {
            showStatus('Please enter your username or email first, then tap the fingerprint button.', 'error');
            usernameInput && usernameInput.focus();
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Waiting for fingerprint…';
        status.style.display = 'none';

        try {
            // Step 1 – get authentication options
            const optsRes = await fetch('<?php echo url("api-webauthn-auth-begin.php"); ?>', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username, rpId: window.location.hostname })
            });
            const optsPayload = await parseJsonResponse(optsRes);
            if (!optsRes.ok || !optsPayload.data) {
                throw new Error(
                    (optsPayload.data && optsPayload.data.error)
                        || ('Server error (' + optsRes.status + ')' + (optsPayload.text ? ': ' + optsPayload.text.slice(0, 180) : ''))
                );
            }
            const opts = optsPayload.data;
            const publicKey = opts.publicKey || opts;

            publicKey.challenge = b64ToBuffer(publicKey.challenge);
            if (publicKey.allowCredentials) {
                publicKey.allowCredentials = publicKey.allowCredentials.map(c => ({
                    ...c, id: b64ToBuffer(c.id.data ?? c.id)
                }));
            }

            // Step 2 – browser prompts fingerprint
            const assertion = await navigator.credentials.get({ publicKey });

            // Step 3 – verify on server
            const payload = {
                credentialId:    bufToB64(assertion.rawId),
                clientDataJSON:  bufToB64(assertion.response.clientDataJSON),
                authenticatorData: bufToB64(assertion.response.authenticatorData),
                signature:       bufToB64(assertion.response.signature)
            };

            const verRes  = await fetch('<?php echo url("api-webauthn-auth-complete.php"); ?>', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const verPayload = await parseJsonResponse(verRes);
            if (!verRes.ok || !verPayload.data) {
                throw new Error(
                    (verPayload.data && verPayload.data.error)
                        || ('Verification failed (' + verRes.status + ')' + (verPayload.text ? ': ' + verPayload.text.slice(0, 180) : ''))
                );
            }
            const verData = verPayload.data;

            if (verData.success) {
                showStatus('✅ Fingerprint verified! Redirecting…', 'success');
                window.location.href = verData.redirect || '<?php echo url("dashboard.php"); ?>';
            } else {
                throw new Error(verData.error || 'Verification failed');
            }
        } catch (e) {
            if (e.name === 'NotAllowedError') {
                showStatus('Fingerprint prompt was cancelled or timed out.', 'error');
            } else {
                showStatus(e.message || 'Fingerprint login failed.', 'error');
            }
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-fingerprint" style="font-size:1.2rem;"></i> Sign in with Fingerprint / Passkey';
        }
    });

    function b64ToBuffer(base64) {
        const normalized = base64.replace(/-/g, '+').replace(/_/g, '/');
        const padded = normalized + '='.repeat((4 - normalized.length % 4) % 4);
        const bin = atob(padded);
        const buf = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
        return buf.buffer;
    }

    function bufToB64(buffer) {
        const bytes = new Uint8Array(buffer);
        let binary  = '';
        bytes.forEach(b => binary += String.fromCharCode(b));
        return btoa(binary);
    }
})();
</script>
</body>
</html>
