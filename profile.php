<?php
$pageTitle = 'Profile Settings';
require_once __DIR__ . '/includes/header.php';
$user = requireAuth();
$db = Database::getInstance();
$auth = new Auth();

// Ensure WebAuthn table exists so profile pages do not fail on hosts missing this migration.
try {
    $db->query("CREATE TABLE IF NOT EXISTS webauthn_credentials (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        credential_id TEXT NOT NULL,
        public_key LONGTEXT NOT NULL,
        sign_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
        device_name VARCHAR(120) NOT NULL DEFAULT 'My Device',
        last_used TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_credential_id (credential_id(255)),
        KEY idx_webauthn_user_id (user_id),
        CONSTRAINT fk_webauthn_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    error_log('WebAuthn table bootstrap failed on profile.php: ' . $e->getMessage());
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $data = [
            'full_name' => sanitize($_POST['full_name'] ?? ''),
            'email' => sanitize($_POST['email'] ?? ''),
        ];

        $result = $auth->updateProfile($user['id'], $data);
        if ($result['success']) {
            setFlash('success', 'Profile updated successfully');
        } else {
            setFlash('error', implode(', ', $result['errors']));
        }
        redirect('/profile.php');
    }

    if ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($newPassword !== $confirmPassword) {
            setFlash('error', 'New passwords do not match');
        } else {
            $result = $auth->changePassword($user['id'], $currentPassword, $newPassword);
            if ($result['success']) {
                setFlash('success', 'Password changed successfully');
            } else {
                setFlash('error', implode(', ', $result['errors']));
            }
        }
        redirect('/profile.php#googleDriveTab');
    }

    // ---- Safety PIN (asked before every Edit / Delete; shared with the desktop and mobile apps)
    if (in_array($action, ['safety_pin_save', 'safety_pin_remove', 'safety_pin_lock'], true)) {
        $spDb = Database::getInstance();
        $uid = (int)$user['id'];
        if ($action === 'safety_pin_lock') {
            spLockSession();
            setFlash('success', 'Edits locked. The Safety PIN will be asked again before the next edit or delete.');
            redirect('/profile.php#securityTab');
        }
        // Changing or removing an existing PIN needs the current PIN -- or the
        // account password, for a forgotten PIN.
        $current = (string)($_POST['sp_current'] ?? '');
        $authorised = !spIsSet($spDb, $uid);
        if (!$authorised) {
            $pwRow = $spDb->fetch("SELECT password FROM users WHERE id = ?", [$uid]);
            $authorised = ($pwRow && $current !== '' && password_verify($current, $pwRow['password']))
                || ($current !== '' && spVerify($spDb, $uid, $current)['ok']);
        }
        if (!$authorised) {
            setFlash('error', 'The current PIN (or account password) is wrong.');
        } elseif ($action === 'safety_pin_remove') {
            spStoreHash($spDb, $uid, null);
            spLockSession();
            spLog($spDb, $uid, 'Safety PIN removed');
            setFlash('success', 'Safety PIN removed. Edits and deletes no longer ask for a PIN.');
        } else {
            $newPin = trim((string)($_POST['sp_new'] ?? ''));
            if (!spValidPinFormat($newPin)) {
                setFlash('error', 'The PIN must be ' . SP_MIN_LENGTH . ' to ' . SP_MAX_LENGTH . ' digits.');
            } elseif ($newPin !== trim((string)($_POST['sp_confirm'] ?? ''))) {
                setFlash('error', 'The new PINs do not match.');
            } else {
                spStoreHash($spDb, $uid, spHash($newPin));
                spUnlockSession(); // you just proved you know it
                spLog($spDb, $uid, 'Safety PIN set');
                setFlash('success', 'Safety PIN saved. It is now asked before every edit or delete -- on the website, '
                    . 'the desktop app (after its next sync) and the mobile app.');
            }
        }
        redirect('/profile.php#securityTab');
    }

    if ($action === 'save_google_drive') {
        $clientId = trim($_POST['google_client_id'] ?? '');
        $clientSecret = trim($_POST['google_client_secret'] ?? '');
        $driveFolderId = trim($_POST['google_drive_folder_id'] ?? '') ?: 'root';

        if (empty($clientId) || empty($clientSecret)) {
            setFlash('error', 'Client ID and Client Secret are required');
        } else {
            $oldConfigFile = __DIR__ . '/config/google-drive-config.php';
            $oldConfig = file_exists($oldConfigFile) ? (require $oldConfigFile) : [];
            if (($oldConfig['client_id'] ?? '') !== $clientId) {
                // A login belongs to one OAuth client and folders made under the old
                // client aren't visible to the new one -- start fresh.
                @unlink(__DIR__ . '/config/.google-drive-tokens');
                $driveFolderId = 'root';
            }
            $config = [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => 'http://127.0.0.1:53682/',
                'drive_folder_id' => $driveFolderId
            ];
            $configContent = '<?php return ' . var_export($config, true) . ';' . "\n";
            $configFile = __DIR__ . '/config/google-drive-config.php';

            if (file_put_contents($configFile, $configContent)) {
                setFlash('success', 'Google Drive API credentials saved successfully. Now click "Authorize with Google" and paste the code below.');
                $db->insert("INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
                    [$user['id'], 'settings', 'Updated Google Drive API credentials', $_SERVER['REMOTE_ADDR'] ?? '']);
            } else {
                setFlash('error', 'Failed to save configuration file. Check folder permissions on config/');
            }
        }
        redirect('/profile.php#googleDriveTab');
    }

    if ($action === 'submit_auth_code') {
        $authCode = trim($_POST['google_auth_code'] ?? '');
        if (empty($authCode)) {
            setFlash('error', 'Please paste the authorization code from Google');
        } else {
            require_once __DIR__ . '/includes/google-drive.php';
            try {
                $gdrive = new GoogleDriveIntegration();
                if ($gdrive->handleAuthorizationCode($authCode)) {
                    $folder = $gdrive->ensureAppFolder();
                    setFlash('success', 'Google Drive connected! Files will upload to the "' . $folder['name'] . '" folder in your Drive.');
                    $db->insert("INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
                        [$user['id'], 'settings', 'Connected Google Drive account', $_SERVER['REMOTE_ADDR'] ?? '']);
                } else {
                    setFlash('error', 'Failed to authorize with Google. The code may be expired — try again.');
                }
            } catch (Exception $e) {
                setFlash('error', 'Google Drive Error: ' . $e->getMessage());
            }
        }
        redirect('/profile.php#googleDriveTab');
    }

    if ($action === 'disconnect_google_drive') {
        $tokenFile = __DIR__ . '/config/.google-drive-tokens';
        if (file_exists($tokenFile)) {
            unlink($tokenFile);
        }
        setFlash('success', 'Google Drive disconnected. You can re-authorize anytime.');
        $db->insert("INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
            [$user['id'], 'settings', 'Disconnected Google Drive', $_SERVER['REMOTE_ADDR'] ?? '']);
        redirect('/profile.php#googleDriveTab');
    }

    if ($action === 'select_drive_folder') {
        $folderId = trim($_POST['folder_id'] ?? '') ?: 'root';
        $folderName = trim($_POST['folder_name'] ?? '') ?: 'My Drive (Root)';

        $configFile = __DIR__ . '/config/google-drive-config.php';
        if (file_exists($configFile)) {
            $config = require $configFile;
            $config['drive_folder_id'] = $folderId;
            $config['drive_folder_name'] = $folderName;
            $configContent = '<?php return ' . var_export($config, true) . ';' . "\n";
            if (file_put_contents($configFile, $configContent)) {
                setFlash('success', 'Files will now be saved to: ' . htmlspecialchars($folderName));
                $db->insert("INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
                    [$user['id'], 'settings', 'Changed Google Drive folder to: ' . $folderName, $_SERVER['REMOTE_ADDR'] ?? '']);
            } else {
                setFlash('error', 'Failed to save folder selection.');
            }
        } else {
            setFlash('error', 'Google Drive not configured yet.');
        }
        redirect('/profile.php#googleDriveTab');
    }

    // Handle OAuth Credentials Save
    if (isset($_POST['save_oauth'])) {
        $oauthConfig = require __DIR__ . '/config/oauth-config.php';

        // Update Google
        if (!empty($_POST['oauth_google_id'])) {
            $oauthConfig['google']['client_id'] = trim($_POST['oauth_google_id']);
        }
        if (!empty($_POST['oauth_google_secret'])) {
            $oauthConfig['google']['client_secret'] = trim($_POST['oauth_google_secret']);
        }

        // Update GitHub
        if (!empty($_POST['oauth_github_id'])) {
            $oauthConfig['github']['client_id'] = trim($_POST['oauth_github_id']);
        }
        if (!empty($_POST['oauth_github_secret'])) {
            $oauthConfig['github']['client_secret'] = trim($_POST['oauth_github_secret']);
        }

        // Update Apple
        if (!empty($_POST['oauth_apple_team'])) {
            $oauthConfig['apple']['team_id'] = trim($_POST['oauth_apple_team']);
        }
        if (!empty($_POST['oauth_apple_id'])) {
            $oauthConfig['apple']['client_id'] = trim($_POST['oauth_apple_id']);
        }
        if (!empty($_POST['oauth_apple_key'])) {
            $oauthConfig['apple']['key_id'] = trim($_POST['oauth_apple_key']);
        }

        // Write config file
        $configFile = __DIR__ . '/config/oauth-config.php';
        $configContent = '<?php' . "\n" . '/**' . "\n" . ' * OAuth Social Login Configuration (Auto-generated)' . "\n" . ' * DO NOT commit this file to version control!' . "\n" . ' */' . "\n" . 'return ' . var_export($oauthConfig, true) . ';' . "\n";

        if (file_put_contents($configFile, $configContent)) {
            setFlash('success', 'OAuth credentials saved successfully!');
            $db->insert("INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
                [$user['id'], 'settings', 'Updated OAuth social login credentials', $_SERVER['REMOTE_ADDR'] ?? '']);
        } else {
            setFlash('error', 'Failed to save OAuth configuration. Check file permissions on config/oauth-config.php');
        }
        redirect('/profile.php#oauthTab');
    }
}

// Refresh user data
$user = $auth->getCurrentUser();
$stats = [
    'records' => $db->fetch("SELECT COUNT(*) as count FROM personal_details WHERE user_id = ?", [$user['id']])['count'],
    'fields' => $db->fetch("SELECT COUNT(*) as count FROM custom_fields WHERE user_id = ?", [$user['id']])['count'],
    'activities' => $db->fetch("SELECT COUNT(*) as count FROM activity_log WHERE user_id = ?", [$user['id']])['count'],
];
?>

<!-- Profile Header -->
<div class="profile-header">
    <div class="profile-avatar-lg" style="background: <?php echo randomColor(); ?>">
        <?php echo getInitials($user['full_name']); ?>
    </div>
    <div class="profile-info">
        <h2><?php echo htmlspecialchars($user['full_name']); ?></h2>
        <p>@<?php echo htmlspecialchars($user['username']); ?> &bull; <?php echo htmlspecialchars($user['email']); ?></p>
        <div class="profile-meta">
            <span><i class="fas fa-calendar"></i> Joined <?php echo formatDate($user['created_at']); ?></span>
            <span><i class="fas fa-address-card"></i> <?php echo $stats['records']; ?> Records</span>
            <span><i class="fas fa-puzzle-piece"></i> <?php echo $stats['fields']; ?> Custom Fields</span>
        </div>
    </div>
</div>

<!-- Tabs -->
<div class="tabs">
    <button class="tab-btn active" data-tab="profileTab">Profile</button>
    <button class="tab-btn" data-tab="securityTab">Security</button>
    <!-- <button class="tab-btn" data-tab="oauthTab"><i class="fas fa-passport" style="margin-right: 6px;"></i>Social Login</button> -->
    <button class="tab-btn" data-tab="googleDriveTab"><i class="fab fa-google-drive" style="margin-right: 6px;"></i>Google Drive</button>
    <button class="tab-btn" data-tab="activityTab">Activity Log</button>
</div>

<!-- Profile Tab -->
<div class="tab-content active" id="profileTab">
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-user-edit" style="margin-right: 8px; color: var(--primary);"></i> Edit Profile</h2>
        </div>

        <form method="POST" action="<?php echo url('profile.php'); ?>">
<?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="update_profile">

            <div class="form-row">
                <div class="form-group">
                    <label>Full Name <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <input type="text" name="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                        <i class="fas fa-user"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label>Username</label>
                    <div class="input-wrapper">
                        <input type="text" value="<?php echo htmlspecialchars($user['username']); ?>" disabled 
                               style="opacity: 0.6; cursor: not-allowed;">
                        <i class="fas fa-at"></i>
                    </div>
                    <small style="color: var(--text-muted);">Username cannot be changed</small>
                </div>
            </div>

            <div class="form-group">
                <label>Email Address <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                    <i class="fas fa-envelope"></i>
                </div>
            </div>

            <div style="margin-top: 20px;">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Security Tab -->
<div class="tab-content" id="securityTab">
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-shield-alt" style="margin-right: 8px; color: var(--success);"></i> Change Password</h2>
        </div>

        <form method="POST" action="<?php echo url('profile.php'); ?>">
<?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="change_password">

            <div class="form-group">
                <label>Current Password <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="password" name="current_password" placeholder="Enter current password" required>
                    <i class="fas fa-lock"></i>
                    <span class="toggle-password" tabindex="0" role="button" aria-label="Toggle password visibility"><i class="fas fa-eye"></i></span>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>New Password <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <input type="password" name="new_password" id="password" placeholder="Enter new password" required>
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
                </div>

                <div class="form-group">
                    <label>Confirm New Password <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <input type="password" name="confirm_password" placeholder="Confirm new password" required>
                        <i class="fas fa-lock"></i>
                        <span class="toggle-password" tabindex="0" role="button" aria-label="Toggle password visibility"><i class="fas fa-eye"></i></span>
                    </div>
                </div>
            </div>

            <div style="margin-top: 20px;">
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-key"></i> Change Password
                </button>
            </div>
        </form>
    </div>

    <?php $spIsSetForUser = spIsSet(Database::getInstance(), (int)$user['id']); ?>
    <div class="card" style="margin-top: 24px;" id="safetyPinCard">
        <div class="card-header">
            <h2><i class="fas fa-user-lock" style="margin-right: 8px; color: var(--warning);"></i> Safety PIN</h2>
        </div>
        <p style="color: var(--text-secondary); margin-bottom: 16px;">
            Asked before every <strong>Edit</strong> or <strong>Delete</strong> -- here, in the desktop app and in the
            mobile app (one PIN for all three). After you enter it once, edits stay unlocked until you log out.
            <?php if ($spIsSetForUser): ?>
                <br><span style="color: var(--success);"><i class="fas fa-check-circle"></i> A Safety PIN is set</span>
                &middot; edits are currently <strong><?php echo spIsUnlocked() ? 'unlocked' : 'locked'; ?></strong>.
            <?php else: ?>
                <br><span style="color: var(--warning);"><i class="fas fa-exclamation-circle"></i> No Safety PIN yet -- edits and deletes are not protected.</span>
            <?php endif; ?>
        </p>

        <form method="POST" action="<?php echo url('profile.php'); ?>" autocomplete="off">
            <?php echo csrf_field(); ?>
            <?php if ($spIsSetForUser): ?>
            <div class="form-group">
                <label>Current PIN or account password <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="password" name="sp_current" placeholder="Current PIN (forgot it? use your password)" autocomplete="off">
                    <i class="fas fa-lock"></i>
                </div>
            </div>
            <?php endif; ?>
            <div class="form-row">
                <div class="form-group">
                    <label>New PIN (<?php echo SP_MIN_LENGTH; ?>-<?php echo SP_MAX_LENGTH; ?> digits)</label>
                    <div class="input-wrapper">
                        <input type="password" name="sp_new" inputmode="numeric" pattern="\d{<?php echo SP_MIN_LENGTH; ?>,<?php echo SP_MAX_LENGTH; ?>}" maxlength="<?php echo SP_MAX_LENGTH; ?>" autocomplete="new-password">
                        <i class="fas fa-key"></i>
                    </div>
                </div>
                <div class="form-group">
                    <label>Confirm new PIN</label>
                    <div class="input-wrapper">
                        <input type="password" name="sp_confirm" inputmode="numeric" maxlength="<?php echo SP_MAX_LENGTH; ?>" autocomplete="new-password">
                        <i class="fas fa-key"></i>
                    </div>
                </div>
            </div>
            <div style="margin-top: 16px; display: flex; gap: 10px; flex-wrap: wrap;">
                <button type="submit" name="action" value="safety_pin_save" class="btn btn-warning">
                    <i class="fas fa-save"></i> <?php echo $spIsSetForUser ? 'Change PIN' : 'Create PIN'; ?>
                </button>
                <?php if ($spIsSetForUser): ?>
                <button type="submit" name="action" value="safety_pin_remove" class="btn btn-danger"
                        onclick="return confirm('Remove the Safety PIN? Edits and deletes will no longer be protected.');">
                    <i class="fas fa-trash"></i> Remove PIN
                </button>
                <?php if (spIsUnlocked()): ?>
                <button type="submit" name="action" value="safety_pin_lock" class="btn btn-secondary" formnovalidate>
                    <i class="fas fa-lock"></i> Lock edits now
                </button>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card" style="margin-top: 24px;">
        <div class="card-header">
            <h2><i class="fas fa-mobile-alt" style="margin-right: 8px; color: var(--primary);"></i> Two-Factor Authentication (2FA)</h2>
        </div>

        <?php
        $twoFAData = $db->fetch("SELECT totp_enabled FROM users WHERE id = ?", [$user['id']]);
        $is2FAActive = !empty($twoFAData['totp_enabled']);
        ?>

        <div style="display: flex; align-items: center; justify-content: space-between; padding: 16px 0;">
            <div>
                <h4 style="margin: 0 0 4px 0;">Google Authenticator</h4>
                <p style="color: var(--text-secondary); margin: 0; font-size: 0.9rem;">
                    <?php if ($is2FAActive): ?>
                        <span style="color: var(--success);"><i class="fas fa-check-circle"></i> Enabled</span> — 
                        A verification code is required after every sign-in.
                    <?php else: ?>
                        <span style="color: var(--text-muted);"><i class="fas fa-times-circle"></i> Not enabled</span> — 
                        Add an extra layer of security to your account.
                    <?php endif; ?>
                </p>
            </div>
            <a href="<?php echo url('setup-2fa.php'); ?>" class="btn <?php echo $is2FAActive ? 'btn-secondary' : 'btn-primary'; ?>">
                <i class="fas fa-<?php echo $is2FAActive ? 'cog' : 'shield-alt'; ?>"></i>
                <?php echo $is2FAActive ? 'Manage 2FA' : 'Enable 2FA'; ?>
            </a>
        </div>
    </div>

    <!-- Fingerprint / Passkey card -->
    <?php
    try {
        $fpCount = $db->fetch("SELECT COUNT(*) AS cnt FROM webauthn_credentials WHERE user_id = ?", [$user['id']]);
    } catch (Exception $e) {
        $fpCount = ['cnt' => 0];
        error_log('Fingerprint count query failed on profile.php: ' . $e->getMessage());
    }
    $fpActive = ($fpCount['cnt'] ?? 0) > 0;
    ?>
    <div class="card" style="margin-top: 24px;">
        <div class="card-header">
            <h2><i class="fas fa-fingerprint" style="margin-right: 8px; color: var(--primary);"></i> Fingerprint / Passkey Login</h2>
        </div>
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 16px 0;">
            <div>
                <h4 style="margin: 0 0 4px 0;">Biometric &amp; Passkey Authentication</h4>
                <p style="color: var(--text-secondary); margin: 0; font-size: 0.9rem;">
                    <?php if ($fpActive): ?>
                        <span style="color: var(--success);"><i class="fas fa-check-circle"></i> Active</span> —
                        <?php echo intval($fpCount['cnt']); ?> device(s) registered. You can log in with your fingerprint or Face ID.
                    <?php else: ?>
                        <span style="color: var(--text-muted);"><i class="fas fa-times-circle"></i> Not set up</span> —
                        Register your fingerprint or Face ID for one-tap login.
                    <?php endif; ?>
                </p>
            </div>
            <a href="<?php echo url('setup-fingerprint.php'); ?>" class="btn <?php echo $fpActive ? 'btn-secondary' : 'btn-primary'; ?>">
                <i class="fas fa-fingerprint"></i>
                <?php echo $fpActive ? 'Manage Devices' : 'Set Up Fingerprint'; ?>
            </a>
        </div>
    </div>

    <div class="card" style="margin-top: 24px; border-color: var(--danger);">
        <div class="card-header">
            <h2 style="color: var(--danger);"><i class="fas fa-exclamation-triangle" style="margin-right: 8px;"></i> Danger Zone</h2>
        </div>
        <p style="color: var(--text-secondary); margin-bottom: 16px;">
            Once you delete your account, all of your data will be permanently removed. This action cannot be undone.
        </p>
        <button class="btn btn-danger" onclick="showConfirm('Delete Account', 'This will permanently delete your account and all associated data. Are you sure?', function() { showToast('Account deletion is disabled in demo', 'info'); })">
            <i class="fas fa-trash"></i> Delete Account
        </button>
    </div>
</div>

<!-- OAuth Social Login Tab -->
<div class="tab-content" id="oauthTab">
    <div class="card">
        <h3><i class="fas fa-passport"></i> Social Login Configuration</h3>
        <p style="color: var(--text-secondary); margin-bottom: 20px;">
            Set up Google, GitHub, and Apple sign-in options for your users. Each OAuth provider requires setting up credentials on their platform.
        </p>

        <form method="POST" action="" class="form-group" style="max-width: 800px;">
<?php echo csrf_field(); ?>
            <?php
            // Load current OAuth config to populate form
            $oauthConfigFile = __DIR__ . '/config/oauth-config.php';
            $oauthCfg = [];
            if (file_exists($oauthConfigFile)) {
                $oauthCfg = require $oauthConfigFile;
            }
            $googleId = $oauthCfg['google']['client_id'] ?? '';
            $googleSecret = $oauthCfg['google']['client_secret'] ?? '';
            $githubId = $oauthCfg['github']['client_id'] ?? '';
            $githubSecret = $oauthCfg['github']['client_secret'] ?? '';
            $appleTeamId = $oauthCfg['apple']['team_id'] ?? '';
            $appleClientId = $oauthCfg['apple']['client_id'] ?? '';
            $appleKeyId = $oauthCfg['apple']['key_id'] ?? '';
            ?>
            <!-- Google Section -->
            <div style="border: 1px solid var(--border); border-radius: var(--radius-md); padding: 16px; margin-bottom: 16px; background: rgba(251,188,5,0.05);">
                <label style="font-weight: 600; color: var(--text-primary); display: flex; align-items: center; margin-bottom: 12px;">
                    <i class="fab fa-google" style="margin-right: 8px; color: #1F2937;"></i>
                    Google OAuth Setup
                </label>
                <div class="form-group">
                    <label>Google Client ID</label>
                    <input type="text" name="oauth_google_id" placeholder="1234567890-abc...apps.googleusercontent.com" value="<?php echo htmlspecialchars($googleId); ?>">
                </div>
                <div class="form-group">
                    <label>Google Client Secret</label>
                    <input type="password" name="oauth_google_secret" placeholder="GOCSPX-..." value="<?php echo htmlspecialchars($googleSecret); ?>">
                </div>
                <small style="color: var(--text-muted);">
                    Get these from <a href="https://console.cloud.google.com/apis/credentials" target="_blank" style="color: var(--primary);">Google Cloud Console</a>.
                    Create an OAuth 2.0 Desktop app, add <code style="background: var(--bg-input); padding: 2px 4px; border-radius: 3px;"><?php echo (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . (defined('BASE_PATH') ? BASE_PATH : '') . '/oauth-callback.php?provider=google'; ?></code> as Authorized Redirect URI.
                </small>
            </div>

            <!-- GitHub Section -->
            <div style="border: 1px solid var(--border); border-radius: var(--radius-md); padding: 16px; margin-bottom: 16px; background: rgba(0,0,0,0.05);">
                <label style="font-weight: 600; color: var(--text-primary); display: flex; align-items: center; margin-bottom: 12px;">
                    <i class="fab fa-github" style="margin-right: 8px; color: #1F2937;"></i>
                    GitHub OAuth Setup
                </label>
                <div class="form-group">
                    <label>GitHub Client ID</label>
                    <input type="text" name="oauth_github_id" placeholder="Iv1.abc..." value="<?php echo htmlspecialchars($githubId); ?>">
                </div>
                <div class="form-group">
                    <label>GitHub Client Secret</label>
                    <input type="password" name="oauth_github_secret" placeholder="gho_16C..." value="<?php echo htmlspecialchars($githubSecret); ?>">
                </div>
                <small style="color: var(--text-muted);">
                    Get these from <a href="https://github.com/settings/developers" target="_blank" style="color: var(--primary);">GitHub Developer Settings</a>.
                    Register a new OAuth app, use <code style="background: var(--bg-input); padding: 2px 4px; border-radius: 3px;"><?php echo (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . (defined('BASE_PATH') ? BASE_PATH : '') . '/oauth-callback.php?provider=github'; ?></code> as Authorization callback URL.
                </small>
            </div>

            <!-- Apple Section -->
            <div style="border: 1px solid var(--border); border-radius: var(--radius-md); padding: 16px; margin-bottom: 16px; background: rgba(0,0,0,0.05);">
                <label style="font-weight: 600; color: var(--text-primary); display: flex; align-items: center; margin-bottom: 12px;">
                    <i class="fab fa-apple" style="margin-right: 8px; color: #1F2937;"></i>
                    Apple Sign-In Setup
                    <span style="font-size: 0.8rem; background: var(--info); color: white; padding: 2px 6px; border-radius: 3px; margin-left: 8px;">Advanced</span>
                </label>
                <div class="form-group">
                    <label>Apple Team ID</label>
                    <input type="text" name="oauth_apple_team" placeholder="ABC1234567" value="<?php echo htmlspecialchars($appleTeamId); ?>">
                </div>
                <div class="form-group">
                    <label>Apple Service ID (Identifier)</label>
                    <input type="text" name="oauth_apple_id" placeholder="com.yourapp.signin" value="<?php echo htmlspecialchars($appleClientId); ?>">
                </div>
                <div class="form-group">
                    <label>Apple Key ID</label>
                    <input type="text" name="oauth_apple_key" placeholder="ABC1234DEF" value="<?php echo htmlspecialchars($appleKeyId); ?>">
                </div>
                <small style="color: var(--text-muted); display: block; margin-bottom: 8px;">
                    Requires <a href="https://developer.apple.com/account" target="_blank" style="color: var(--primary);">Apple Developer Account</a> ($99/year).
                    See <a href="https://developer.apple.com/sign-in-with-apple/" target="_blank" style="color: var(--primary);">Sign In with Apple documentation</a>.
                </small>
            </div>

            <button type="submit" name="save_oauth" class="btn btn-primary">
                <i class="fas fa-save"></i> Save OAuth Credentials
            </button>
            <small style="display: block; margin-top: 12px; color: var(--text-muted);">
                ⚠️ Credentials are saved in <code>config/oauth-config.php</code>. Do not commit this file to version control.
            </small>
        </form>

        <div style="margin-top: 32px; padding-top: 16px; border-top: 1px solid var(--border);">
            <h4 style="color: var(--text-primary); margin-bottom: 12px;"><i class="fas fa-info-circle" style="color: var(--info);"></i> How It Works</h4>
            <ul style="color: var(--text-secondary); line-height: 1.8;">
                <li><strong>User clicks "Sign in with [Provider]"</strong> on login/register page</li>
                <li><strong>Redirects to provider's login</strong> to authenticate</li>
                <li><strong>Provider redirects back</strong> with authorization code</li>
                <li><strong>We exchange code for access token</strong> and fetch user profile</li>
                <li><strong>Auto-creates account or logs in</strong> existing user</li>
            </ul>
        </div>
    </div>
</div>

<!-- Google Drive Tab -->
<div class="tab-content" id="googleDriveTab">
<?php
// Load current Google Drive config if it exists
$gdriveConfigFile = __DIR__ . '/config/google-drive-config.php';
$gdriveTokenFile = __DIR__ . '/config/.google-drive-tokens';
$gdriveConfig = file_exists($gdriveConfigFile) ? require $gdriveConfigFile : null;
$gdriveTokens = file_exists($gdriveTokenFile) ? json_decode(file_get_contents($gdriveTokenFile), true) : null;
$gdriveAuthError = $gdriveTokens['auth_error'] ?? null;
$gdriveConnected = !empty($gdriveTokens['access_token']) && empty($gdriveAuthError);
?>
<?php if ($gdriveAuthError): ?>
    <div class="card" style="margin-bottom: 16px; border-color: var(--danger);">
        <strong style="color: var(--danger);"><i class="fas fa-exclamation-triangle"></i> Google ended the Drive authorization on <?php echo htmlspecialchars($gdriveAuthError); ?>.</strong>
        <p style="color: var(--text-secondary); margin: 6px 0 0;">
            Authorize with Google again below. If this happens about every 7 days, your Google Cloud OAuth app is in
            <b>Testing</b> mode, where Google expires logins after 7 days. Fix it once in Google Cloud Console &gt;
            Google Auth Platform &gt; Audience &gt; <b>Publish app</b>, then authorize again &mdash; it will then stay connected.
        </p>
    </div>
<?php endif; ?>

    <!-- Connection Status -->
    <div class="card" style="margin-bottom: 24px; border-color: <?php echo $gdriveConnected ? 'var(--success)' : ($gdriveConfig ? 'var(--warning)' : 'var(--text-muted)'); ?>;">
        <div style="display: flex; align-items: center; gap: 16px; flex-wrap: wrap;">
            <div style="width: 56px; height: 56px; border-radius: var(--radius-md); display: flex; align-items: center; justify-content: center; font-size: 1.6rem;
                background: <?php echo $gdriveConnected ? 'rgba(78,205,196,0.15)' : ($gdriveConfig ? 'rgba(255,217,61,0.15)' : 'rgba(107,109,138,0.15)'); ?>;
                color: <?php echo $gdriveConnected ? 'var(--success)' : ($gdriveConfig ? 'var(--warning)' : 'var(--text-muted)'); ?>;">
                <i class="fab fa-google-drive"></i>
            </div>
            <div style="flex: 1;">
                <h3 style="margin-bottom: 4px;">
                    <?php if ($gdriveConnected): ?>
                        <span style="color: var(--success);"><i class="fas fa-check-circle"></i> Google Drive Connected</span>
                    <?php elseif ($gdriveConfig): ?>
                        <span style="color: var(--warning);"><i class="fas fa-exclamation-circle"></i> Credentials Saved &mdash; Authorization Needed</span>
                    <?php else: ?>
                        <span style="color: var(--text-muted);"><i class="fas fa-times-circle"></i> Google Drive Not Configured</span>
                    <?php endif; ?>
                </h3>
                <p style="color: var(--text-secondary); font-size: 0.9rem; margin: 0;">
                    <?php if ($gdriveConnected): ?>
                        Files will be uploaded to Google Drive instead of local storage. 15 GB free storage.
                    <?php elseif ($gdriveConfig): ?>
                        API credentials are saved. Click &ldquo;Authorize with Google&rdquo; below to connect your account.
                    <?php else: ?>
                        Set up Google Drive to store uploaded files in the cloud with 15 GB free storage.
                    <?php endif; ?>
                </p>
            </div>
            <?php if ($gdriveConnected): ?>
            <form method="POST" action="<?php echo url('profile.php'); ?>" style="margin: 0;">
<?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="disconnect_google_drive">
                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Disconnect Google Drive? Existing uploaded files will remain on Drive.');">
                    <i class="fas fa-unlink"></i> Disconnect
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- How to Get API Credentials -->
    <div class="card" style="margin-bottom: 24px;">
        <div class="card-header">
            <h2><i class="fas fa-key" style="margin-right: 8px; color: var(--warning);"></i> How to Get Google API Credentials</h2>
        </div>

        <div style="color: var(--text-secondary); line-height: 1.8;">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px;">

                <div style="background: var(--bg-input); border-radius: var(--radius-md); padding: 18px;">
                    <h4 style="color: var(--primary); margin-bottom: 10px;"><span style="background: var(--primary); color: white; width: 24px; height: 24px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 0.8rem; margin-right: 8px;">1</span> Create Google Cloud Project</h4>
                    <ol style="padding-left: 20px; margin: 0;">
                        <li>Go to <a href="https://console.cloud.google.com" target="_blank" style="color: var(--primary);">console.cloud.google.com</a></li>
                        <li>Click <strong>Select a Project</strong> &rarr; <strong>New Project</strong></li>
                        <li>Name it <code style="background: var(--bg-hover); padding: 2px 6px; border-radius: 4px;">PDS File Storage</code></li>
                        <li>Click <strong>Create</strong></li>
                    </ol>
                </div>

                <div style="background: var(--bg-input); border-radius: var(--radius-md); padding: 18px;">
                    <h4 style="color: var(--success); margin-bottom: 10px;"><span style="background: var(--success); color: white; width: 24px; height: 24px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 0.8rem; margin-right: 8px;">2</span> Enable Drive API</h4>
                    <ol style="padding-left: 20px; margin: 0;">
                        <li>In Cloud Console go to <strong>APIs &amp; Services</strong> &rarr; <strong>Library</strong></li>
                        <li>Search <code style="background: var(--bg-hover); padding: 2px 6px; border-radius: 4px;">Google Drive API</code></li>
                        <li>Click on it, then click <strong>Enable</strong></li>
                    </ol>
                </div>

                <div style="background: var(--bg-input); border-radius: var(--radius-md); padding: 18px;">
                    <h4 style="color: var(--info); margin-bottom: 10px;"><span style="background: var(--info); color: white; width: 24px; height: 24px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 0.8rem; margin-right: 8px;">3</span> Create OAuth Credentials</h4>
                    <ol style="padding-left: 20px; margin: 0;">
                        <li>Go to <strong>APIs &amp; Services</strong> &rarr; <strong>Credentials</strong></li>
                        <li>Click <strong>Create Credentials</strong> &rarr; <strong>OAuth client ID</strong></li>
                        <li>If asked, configure Consent Screen (External, add your email as test user)</li>
                        <li>Choose type: <strong style="color: var(--warning);">Desktop app</strong> <em>(not Web application)</em></li>
                        <li>Give it a name, e.g. <code style="background: var(--bg-hover); padding: 2px 6px; border-radius: 4px;">PDS Desktop</code></li>
                        <li>Click <strong>Create</strong> and copy <strong>Client ID</strong> &amp; <strong>Client Secret</strong></li>
                    </ol>
                    <div style="margin-top: 10px; padding: 8px 12px; background: rgba(255,217,61,0.1); border-left: 3px solid var(--warning); border-radius: 4px; font-size: 0.85rem;">
                        <i class="fas fa-info-circle" style="color: var(--warning);"></i>
                        <strong>Important:</strong> You must choose <strong>Desktop app</strong> — not &ldquo;Web application&rdquo;. Web app requires a verified domain which free hosting doesn&rsquo;t support.
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- API Configuration Form -->
    <div class="card" style="margin-bottom: 24px;">
        <div class="card-header">
            <h2><i class="fas fa-cogs" style="margin-right: 8px; color: var(--primary);"></i> Google Drive API Configuration</h2>
        </div>

        <form method="POST" action="<?php echo url('profile.php'); ?>">
<?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_google_drive">

            <div class="form-group">
                <label>Client ID <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="text" name="google_client_id" placeholder="e.g. 123456789-abc.apps.googleusercontent.com"
                           value="<?php echo htmlspecialchars($gdriveConfig['client_id'] ?? ''); ?>" required>
                    <i class="fas fa-id-badge"></i>
                </div>
                <small style="color: var(--text-muted);">From Google Cloud Console &rarr; Credentials &rarr; OAuth 2.0 Client IDs</small>
            </div>

            <div class="form-group">
                <label>Client Secret <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="password" name="google_client_secret" placeholder="Your client secret"
                           value="<?php echo htmlspecialchars($gdriveConfig['client_secret'] ?? ''); ?>" required>
                    <i class="fas fa-lock"></i>
                    <span class="toggle-password" tabindex="0" role="button" aria-label="Toggle password visibility"><i class="fas fa-eye"></i></span>
                </div>
                <small style="color: var(--text-muted);">Keep this secret. Never share it publicly.</small>
            </div>

            <div class="form-group">
                <label>Google Drive Folder ID <small style="color: var(--text-muted);">(optional)</small></label>
                <div class="input-wrapper">
                    <input type="text" name="google_drive_folder_id" placeholder="Leave blank (a PDS Backup folder is created automatically)"
                           value="<?php echo htmlspecialchars(($gdriveConfig['drive_folder_id'] ?? '') === 'root' ? '' : ($gdriveConfig['drive_folder_id'] ?? '')); ?>">
                    <i class="fas fa-folder"></i>
                </div>
                <small style="color: var(--text-muted);">Usually leave this blank. The app can only use folders it created itself (that's what removes Google's "unverified app" warning), so a folder you made by hand in Drive won't work here. Pick or create folders with the folder picker after connecting.</small>
            </div>

            <div style="display: flex; gap: 12px; margin-top: 20px; flex-wrap: wrap;">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Credentials
                </button>

            </div>
        </form>
    </div>

    <!-- Step 2: Authorize & Paste Code -->
    <?php if ($gdriveConfig && !$gdriveConnected): ?>
    <?php
        try {
            require_once __DIR__ . '/includes/google-drive.php';
            $gdriveObj = new GoogleDriveIntegration();
            $authUrl = $gdriveObj->getAuthorizationUrl();
        } catch (Exception $e) {
            $authUrl = null;
        }
    ?>
    <div class="card" style="margin-bottom: 24px; border-color: var(--primary);">
        <div class="card-header">
            <h2><i class="fab fa-google" style="margin-right: 8px; color: var(--primary);"></i> Authorize &amp; Connect</h2>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 20px;">
            <div style="background: var(--bg-input); border-radius: var(--radius-md); padding: 18px;">
                <h4 style="color: var(--primary); margin-bottom: 10px;">
                    <span style="background: var(--primary); color: white; width: 24px; height: 24px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 0.8rem; margin-right: 8px;">A</span>
                    Open Google Authorization
                </h4>
                <p style="color: var(--text-secondary); margin-bottom: 12px; font-size: 0.9rem;">Click the button below. Sign in with your Google account and click <strong>Allow</strong>. The browser then opens a <code>127.0.0.1</code> page that says it can't connect &mdash; that's expected.</p>
                <?php if ($authUrl): ?>
                <a href="<?php echo htmlspecialchars($authUrl); ?>" target="_blank" class="btn btn-success" style="width: 100%;">
                    <i class="fab fa-google"></i> Open Google Sign-In
                </a>
                <?php else: ?>
                <p style="color: var(--danger);"><i class="fas fa-exclamation-triangle"></i> Could not generate auth URL. Check your Client ID.</p>
                <?php endif; ?>
            </div>

            <div style="background: var(--bg-input); border-radius: var(--radius-md); padding: 18px;">
                <h4 style="color: var(--success); margin-bottom: 10px;">
                    <span style="background: var(--success); color: white; width: 24px; height: 24px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 0.8rem; margin-right: 8px;">B</span>
                    Paste the Code Here
                </h4>
                <p style="color: var(--text-secondary); margin-bottom: 12px; font-size: 0.9rem;">Copy the <strong>whole address</strong> from that page's address bar (it starts with <code>http://127.0.0.1:53682/?code=</code>), paste it below, then click <strong>Connect</strong>.</p>
                <form method="POST" action="<?php echo url('profile.php'); ?>">
<?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="submit_auth_code">
                    <div class="input-wrapper" style="margin-bottom: 12px;">
                        <input type="text" name="google_auth_code" placeholder="Paste the http://127.0.0.1:53682/?code=... address (or just the code)" required
                               style="font-family: monospace; font-size: 0.95rem;">
                        <i class="fas fa-clipboard"></i>
                    </div>
                    <button type="submit" class="btn btn-success" style="width: 100%;">
                        <i class="fas fa-plug"></i> Connect Google Drive
                    </button>
                </form>
            </div>
        </div>

        <div style="padding: 12px 16px; background: rgba(99,102,241,0.08); border-radius: var(--radius-md); color: var(--text-secondary); font-size: 0.85rem;">
            <i class="fas fa-shield-alt" style="color: var(--primary); margin-right: 6px;"></i>
            <strong>Why paste a code?</strong> Since free hosting domains aren't verified by Google, we use the "Desktop app" flow. Your credentials never leave your server — they're stored securely in <code style="background: var(--bg-hover); padding: 2px 6px; border-radius: 4px;">config/</code>.
        </div>
    </div>
    <?php endif; ?>

    <!-- Folder Picker (only when connected) -->
    <?php if ($gdriveConnected): ?>
    <?php
        $currentFolderId = $gdriveConfig['drive_folder_id'] ?? 'root';
        $currentFolderName = $gdriveConfig['drive_folder_name'] ?? ($currentFolderId === 'root' ? 'My Drive (Root)' : $currentFolderId);
    ?>
    <div class="card" style="margin-bottom: 24px; border-color: var(--primary);">
        <div class="card-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
            <h2><i class="fas fa-folder-open" style="margin-right: 8px; color: var(--warning);"></i> Upload Folder</h2>
            <div style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem;">
                <span style="color: var(--text-muted);">Current:</span>
                <span id="gdrive-current-folder" style="background: var(--bg-input); padding: 4px 12px; border-radius: var(--radius-sm); font-weight: 600; color: var(--primary);">
                    <i class="fas fa-folder" style="margin-right: 4px;"></i>
                    <?php echo htmlspecialchars($currentFolderName); ?>
                </span>
            </div>
        </div>
        
        <p style="color: var(--text-secondary); margin-bottom: 16px; font-size: 0.92rem;">
            Choose which Google Drive folder to save uploaded files into. Browse your folders below and click <strong>Select This Folder</strong>.
        </p>

        <!-- Breadcrumb Navigation -->
        <div id="gdrive-breadcrumb" style="display: flex; align-items: center; gap: 4px; margin-bottom: 12px; padding: 8px 12px; background: var(--bg-input); border-radius: var(--radius-sm); flex-wrap: wrap; font-size: 0.88rem;">
            <a href="#" onclick="gdriveBrowse('root'); return false;" style="color: var(--primary); text-decoration: none; font-weight: 600;">
                <i class="fas fa-home" style="margin-right: 2px;"></i> My Drive
            </a>
        </div>

        <!-- Folder List -->
        <div id="gdrive-folder-list" style="border: 1px solid var(--border-color); border-radius: var(--radius-md); max-height: 350px; overflow-y: auto; margin-bottom: 16px;">
            <div style="padding: 40px; text-align: center; color: var(--text-muted);">
                <i class="fas fa-spinner fa-spin" style="font-size: 1.5rem; margin-bottom: 8px; display: block;"></i>
                Loading folders...
            </div>
        </div>

        <!-- Actions Row -->
        <div style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
            <!-- Select current folder -->
            <form method="POST" action="<?php echo url('profile.php'); ?>" id="gdrive-select-form" style="margin: 0;">
<?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="select_drive_folder">
                <input type="hidden" name="folder_id" id="gdrive-select-folder-id" value="root">
                <input type="hidden" name="folder_name" id="gdrive-select-folder-name" value="My Drive (Root)">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-check-circle"></i> Select This Folder
                </button>
            </form>

            <!-- Create new folder -->
            <button type="button" class="btn btn-success" onclick="gdriveCreateFolder()" id="gdrive-create-btn">
                <i class="fas fa-folder-plus"></i> New Folder
            </button>

            <!-- Use root -->
            <form method="POST" action="<?php echo url('profile.php'); ?>" style="margin: 0;">
<?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="select_drive_folder">
                <input type="hidden" name="folder_id" value="root">
                <input type="hidden" name="folder_name" value="My Drive (Root)">
                <button type="submit" class="btn btn-outline" style="border: 1px solid var(--border-color); background: transparent; color: var(--text-secondary);">
                    <i class="fas fa-home"></i> Use Root Folder
                </button>
            </form>
        </div>

        <div style="margin-top: 12px; padding: 10px 14px; background: rgba(255,217,61,0.08); border-radius: var(--radius-sm); color: var(--text-secondary); font-size: 0.83rem;">
            <i class="fas fa-info-circle" style="color: var(--warning); margin-right: 4px;"></i>
            <strong>Note:</strong> After changing the scope, you may need to <strong>disconnect and re-authorize</strong> Google Drive for folder browsing to work.
        </div>
    </div>

    <script>
    (function() {
        var apiUrl = '<?php echo url("api-gdrive-folders.php"); ?>';
        var currentParent = 'root';
        var breadcrumb = [{ id: 'root', name: 'My Drive' }];

        window.gdriveBrowse = function(folderId, folderName) {
            currentParent = folderId;
            
            // Update breadcrumb
            if (folderId === 'root') {
                breadcrumb = [{ id: 'root', name: 'My Drive' }];
            } else if (folderName) {
                // Check if we're going back in breadcrumb
                var idx = breadcrumb.findIndex(function(b) { return b.id === folderId; });
                if (idx >= 0) {
                    breadcrumb = breadcrumb.slice(0, idx + 1);
                } else {
                    breadcrumb.push({ id: folderId, name: folderName });
                }
            }
            
            renderBreadcrumb();
            
            // Update hidden form values
            var lastName = breadcrumb[breadcrumb.length - 1].name;
            document.getElementById('gdrive-select-folder-id').value = folderId;
            document.getElementById('gdrive-select-folder-name').value = folderId === 'root' ? 'My Drive (Root)' : lastName;
            
            // Show loading
            var listEl = document.getElementById('gdrive-folder-list');
            listEl.innerHTML = '<div style="padding: 40px; text-align: center; color: var(--text-muted);"><i class="fas fa-spinner fa-spin" style="font-size: 1.5rem; margin-bottom: 8px; display: block;"></i>Loading folders...</div>';
            
            fetch(apiUrl + '?action=list&parent=' + encodeURIComponent(folderId))
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data.error) {
                        listEl.innerHTML = '<div style="padding: 20px; text-align: center; color: var(--danger);"><i class="fas fa-exclamation-triangle"></i> ' + data.error + '</div>';
                        return;
                    }
                    renderFolders(data.folders || []);
                })
                .catch(function(err) {
                    listEl.innerHTML = '<div style="padding: 20px; text-align: center; color: var(--danger);"><i class="fas fa-exclamation-triangle"></i> Failed to load folders. Check connection.</div>';
                });
        };

        function renderBreadcrumb() {
            var el = document.getElementById('gdrive-breadcrumb');
            var html = '';
            breadcrumb.forEach(function(item, idx) {
                if (idx > 0) html += '<i class="fas fa-chevron-right" style="color: var(--text-muted); font-size: 0.7rem; margin: 0 2px;"></i>';
                if (idx === breadcrumb.length - 1) {
                    html += '<span style="color: var(--text-primary); font-weight: 600;">' + escapeHtml(item.name) + '</span>';
                } else {
                    html += '<a href="#" onclick="gdriveBrowse(\'' + item.id + '\', \'' + escapeHtml(item.name) + '\'); return false;" style="color: var(--primary); text-decoration: none;">' + escapeHtml(item.name) + '</a>';
                }
            });
            el.innerHTML = html;
        }

        function renderFolders(folders) {
            var listEl = document.getElementById('gdrive-folder-list');
            if (folders.length === 0) {
                listEl.innerHTML = '<div style="padding: 32px; text-align: center; color: var(--text-muted);"><i class="fas fa-folder-open" style="font-size: 2rem; margin-bottom: 8px; display: block; opacity: 0.4;"></i>No subfolders here.<br><small>You can create a new folder or select this folder.</small></div>';
                return;
            }
            
            var html = '';
            folders.forEach(function(folder) {
                var isSelected = folder.id === '<?php echo addslashes($currentFolderId); ?>';
                html += '<div onclick="gdriveBrowse(\'' + folder.id + '\', \'' + escapeHtml(folder.name) + '\');" '
                    + 'style="display: flex; align-items: center; gap: 12px; padding: 12px 16px; cursor: pointer; border-bottom: 1px solid var(--border-color); transition: background 0.15s;'
                    + (isSelected ? ' background: rgba(108,99,255,0.08);' : '')
                    + '" onmouseover="this.style.background=\'var(--bg-hover)\'" onmouseout="this.style.background=\'' + (isSelected ? 'rgba(108,99,255,0.08)' : '') + '\'">'
                    + '<i class="fas fa-folder" style="font-size: 1.3rem; color: var(--warning);"></i>'
                    + '<div style="flex: 1; font-weight: 500;">' + escapeHtml(folder.name) + '</div>'
                    + (isSelected ? '<span style="font-size: 0.75rem; background: var(--primary); color: white; padding: 2px 8px; border-radius: 10px;">Current</span>' : '')
                    + '<i class="fas fa-chevron-right" style="color: var(--text-muted); font-size: 0.8rem;"></i>'
                    + '</div>';
            });
            listEl.innerHTML = html;
        }

        window.gdriveCreateFolder = function() {
            var name = prompt('Enter folder name:');
            if (!name || !name.trim()) return;
            
            var btn = document.getElementById('gdrive-create-btn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
            
            fetch(apiUrl + '?action=create', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name: name.trim(), parent: currentParent })
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-folder-plus"></i> New Folder';
                if (data.success && data.folder) {
                    // Refresh the folder list
                    gdriveBrowse(currentParent, breadcrumb[breadcrumb.length - 1].name);
                } else {
                    alert('Error: ' + (data.error || 'Failed to create folder'));
                }
            })
            .catch(function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-folder-plus"></i> New Folder';
                alert('Failed to create folder. Check your connection.');
            });
        };

        function escapeHtml(str) {
            var div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        // Auto-load folders on page load
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() { gdriveBrowse('root'); });
        } else {
            gdriveBrowse('root');
        }
    })();
    </script>
    <?php endif; ?>

    <!-- Storage Info -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-info-circle" style="margin-right: 8px; color: var(--info);"></i> Storage Information</h2>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
            <div style="background: var(--bg-input); padding: 16px; border-radius: var(--radius-md); text-align: center;">
                <div style="font-size: 2rem; color: var(--primary); margin-bottom: 4px;"><i class="fas fa-cloud"></i></div>
                <div style="font-size: 1.3rem; font-weight: 700;">15 GB</div>
                <div style="color: var(--text-muted); font-size: 0.85rem;">Free Storage</div>
            </div>
            <div style="background: var(--bg-input); padding: 16px; border-radius: var(--radius-md); text-align: center;">
                <div style="font-size: 2rem; color: var(--success); margin-bottom: 4px;"><i class="fas fa-file-alt"></i></div>
                <div style="font-size: 1.3rem; font-weight: 700;">All Types</div>
                <div style="color: var(--text-muted); font-size: 0.85rem;">PDF, Images, Audio, Video</div>
            </div>
            <div style="background: var(--bg-input); padding: 16px; border-radius: var(--radius-md); text-align: center;">
                <div style="font-size: 2rem; color: var(--warning); margin-bottom: 4px;"><i class="fas fa-shield-alt"></i></div>
                <div style="font-size: 1.3rem; font-weight: 700;">Encrypted</div>
                <div style="color: var(--text-muted); font-size: 0.85rem;">At Rest &amp; In Transit</div>
            </div>
            <div style="background: var(--bg-input); padding: 16px; border-radius: var(--radius-md); text-align: center;">
                <div style="font-size: 2rem; color: var(--accent); margin-bottom: 4px;"><i class="fas fa-sync"></i></div>
                <div style="font-size: 1.3rem; font-weight: 700;">Auto Backup</div>
                <div style="color: var(--text-muted); font-size: 0.85rem;">By Google</div>
            </div>
        </div>

        <div style="margin-top: 20px; padding: 14px; background: var(--bg-input); border-radius: var(--radius-md); color: var(--text-secondary); font-size: 0.9rem;">
            <i class="fas fa-lightbulb" style="color: var(--warning); margin-right: 6px;"></i>
            <strong>Tip:</strong> Need more than 15 GB? Upgrade via <a href="https://one.google.com/about/plans" target="_blank" style="color: var(--primary);">Google One</a>: 100 GB for $1.99/mo, 2 TB for $9.99/mo.
        </div>
    </div>
</div>

<!-- Activity Tab -->
<div class="tab-content" id="activityTab">
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-history" style="margin-right: 8px; color: var(--info);"></i> Recent Activity</h2>
        </div>

        <?php
        $activities = $db->fetchAll(
            "SELECT * FROM activity_log WHERE user_id = ? ORDER BY created_at DESC LIMIT 20",
            [$user['id']]
        );
        ?>

        <?php if (empty($activities)): ?>
            <div class="empty-state">
                <i class="fas fa-history"></i>
                <h3>No Activity</h3>
                <p>Your actions will be logged here</p>
            </div>
        <?php else: ?>
            <div class="activity-list">
                <?php foreach ($activities as $activity): ?>
                <div class="activity-item">
                    <div class="activity-dot"></div>
                    <div class="activity-content">
                        <h4><?php echo htmlspecialchars($activity['description']); ?></h4>
                        <p>
                            <i class="fas fa-clock"></i> <?php echo timeAgo($activity['created_at']); ?>
                            <?php if ($activity['ip_address']): ?>
                                &bull; <i class="fas fa-globe"></i> <?php echo htmlspecialchars($activity['ip_address']); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Open tab from URL hash (e.g., profile.php#googleDriveTab)
(function() {
    function openTabFromHash() {
        var hash = window.location.hash.replace('#', '');
        if (!hash) return;
        var tabBtn = document.querySelector('.tab-btn[data-tab="' + hash + '"]');
        if (tabBtn) tabBtn.click();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() { setTimeout(openTabFromHash, 50); });
    } else {
        setTimeout(openTabFromHash, 50);
    }
    window.addEventListener('hashchange', openTabFromHash);

    // Toggle password visibility for Client Secret
    document.querySelectorAll('.toggle-password').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var input = this.closest('.input-wrapper').querySelector('input');
            var icon = this.querySelector('i') || this;
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        });
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
