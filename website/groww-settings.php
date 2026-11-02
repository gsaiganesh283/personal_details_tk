<?php
$pageTitle = 'Groww API Settings';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/groww-helper.php';
require_once __DIR__ . '/includes/groww-client.php';
$user = requireAuth();

[$schemaOk, $schemaMsg] = ensureGrowwSchema();
if (!$schemaOk) {
    setFlash('error', $schemaMsg);
}

// Handle disconnect
if (isset($_GET['disconnect'])) {
    deleteGrowwCredentials($user['id']);
    setFlash('success', 'Disconnected your Groww account. Stored credentials were deleted.');
    redirect('/groww-settings.php');
}

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $schemaOk) {
    $authMethod = $_POST['auth_method'] ?? 'access_token';
    if (!in_array($authMethod, ['access_token', 'api_secret', 'totp'], true)) {
        $authMethod = 'access_token';
    }

    $fields = [];
    if ($authMethod === 'access_token') {
        $accessToken = trim($_POST['access_token'] ?? '');
        if ($accessToken === '') {
            setFlash('error', 'Access token is required.');
            redirect('/groww-settings.php');
        }
        $fields['access_token'] = $accessToken;
        $fields['api_key'] = null;
        $fields['api_secret'] = null;
        $fields['totp_secret'] = null;
    } elseif ($authMethod === 'api_secret') {
        $apiKey = trim($_POST['api_key'] ?? '');
        $apiSecret = trim($_POST['api_secret'] ?? '');
        if ($apiKey === '' || $apiSecret === '') {
            setFlash('error', 'API key and secret are both required.');
            redirect('/groww-settings.php');
        }
        $fields['api_key'] = $apiKey;
        $fields['api_secret'] = $apiSecret;
        $fields['totp_secret'] = null;
        $fields['access_token'] = null;
    } else { // totp
        $apiKey = trim($_POST['api_key'] ?? '');
        $totpSecret = trim(str_replace(' ', '', $_POST['totp_secret'] ?? ''));
        if ($apiKey === '' || $totpSecret === '') {
            setFlash('error', 'API key and TOTP secret are both required.');
            redirect('/groww-settings.php');
        }
        $fields['api_key'] = $apiKey;
        $fields['totp_secret'] = $totpSecret;
        $fields['api_secret'] = null;
        $fields['access_token'] = null;
    }

    saveGrowwCredentials($user['id'], $authMethod, $fields);

    $client = new GrowwClient($user['id']);
    $test = $client->testConnection();

    $db = Database::getInstance();
    $db->insert("INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
        [$user['id'], 'update', 'Updated Groww API connection (' . $authMethod . ')', $_SERVER['REMOTE_ADDR'] ?? '']);

    if ($test['success']) {
        setFlash('success', 'Groww connected successfully! Redirecting to your dashboard…');
        redirect('/groww-dashboard.php');
    } else {
        setFlash('warning', 'Credentials saved, but the test connection failed: ' . $test['message']);
        redirect('/groww-settings.php');
    }
}

$connection = $schemaOk ? getGrowwConnection($user['id']) : null;
?>

<div class="toolbar" style="margin-bottom: 20px;">
    <div class="toolbar-left">
        <h2 style="margin:0 0 4px;"><i class="fas fa-chart-line" style="color: var(--primary); margin-right: 8px;"></i>Groww API Settings</h2>
        <p style="color: var(--text-muted); margin:0;">Connect your Groww trading account to power your investment dashboard.</p>
    </div>
    <div class="toolbar-right">
        <?php if ($connection): ?>
        <a href="<?php echo url('groww-dashboard.php'); ?>" class="btn btn-primary">
            <i class="fas fa-chart-pie"></i> Go to Dashboard
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($connection): ?>
<div class="card animate-fade-in" style="margin-bottom: 20px;">
    <div class="card-header">
        <h2><i class="fas fa-plug-circle-check" style="margin-right: 8px; color: var(--success);"></i> Current Connection</h2>
    </div>
    <div style="display: flex; flex-wrap: wrap; gap: 24px; padding: 4px 0;">
        <div>
            <div style="color: var(--text-muted); font-size: 0.85rem;">Auth method</div>
            <div style="font-weight: 600;"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $connection['auth_method']))); ?></div>
        </div>
        <?php if ($connection['api_key_masked']): ?>
        <div>
            <div style="color: var(--text-muted); font-size: 0.85rem;">API key</div>
            <div style="font-weight: 600; font-family: monospace;"><?php echo htmlspecialchars($connection['api_key_masked']); ?></div>
        </div>
        <?php endif; ?>
        <div>
            <div style="color: var(--text-muted); font-size: 0.85rem;">Token expiry</div>
            <div style="font-weight: 600;"><?php echo $connection['token_expires_at'] ? htmlspecialchars(formatDate($connection['token_expires_at'], 'M d, Y H:i')) : '—'; ?></div>
        </div>
        <div>
            <div style="color: var(--text-muted); font-size: 0.85rem;">Last updated</div>
            <div style="font-weight: 600;"><?php echo htmlspecialchars(timeAgo($connection['updated_at'])); ?></div>
        </div>
    </div>
    <?php if ($connection['last_error']): ?>
    <div class="alert alert-error" style="margin-top: 16px;">
        <i class="fas fa-exclamation-circle"></i>
        <span>Last error: <?php echo htmlspecialchars($connection['last_error']); ?></span>
    </div>
    <?php endif; ?>
    <div style="margin-top: 16px; display: flex; gap: 10px;">
        <a href="<?php echo url('groww-settings.php?disconnect=1'); ?>" class="btn btn-ghost btn-sm"
           onclick="return confirm('Disconnect Groww and delete stored credentials?');">
            <i class="fas fa-unlink"></i> Disconnect
        </a>
    </div>
</div>
<?php endif; ?>

<div class="card animate-fade-in">
    <div class="card-header">
        <h2><i class="fas fa-key" style="margin-right: 8px; color: var(--primary);"></i> <?php echo $connection ? 'Reconnect / Update Credentials' : 'Connect Your Groww Account'; ?></h2>
    </div>

    <div class="alert alert-info" style="margin-bottom: 20px;">
        <i class="fas fa-shield-halved"></i>
        <span>
            Your API key/secret are encrypted (AES-256-GCM) before being stored and are never shown again in full.
            Generate credentials at <a href="https://groww.in/trade-api/api-keys" target="_blank" rel="noopener">groww.in/trade-api/api-keys</a>.
            Groww requires each API key to be approved on their site periodically — if syncing suddenly fails, re-approve it there first.
        </span>
    </div>

    <form method="POST" action="<?php echo url('groww-settings.php'); ?>" id="growwForm">
<?php echo csrf_field(); ?>
        <div class="form-group">
            <label>Authentication Method</label>
            <div class="input-wrapper">
                <select name="auth_method" id="authMethod" onchange="growwToggleAuthFields()">
                    <option value="api_secret" <?php echo ($connection['auth_method'] ?? '') === 'api_secret' ? 'selected' : ''; ?>>API Key + Secret (auto-refreshing, recommended)</option>
                    <option value="totp" <?php echo ($connection['auth_method'] ?? '') === 'totp' ? 'selected' : ''; ?>>API Key + TOTP secret (auto-refreshing)</option>
                    <option value="access_token" <?php echo ($connection['auth_method'] ?? 'access_token') === 'access_token' ? 'selected' : ''; ?>>Access Token (manual, paste daily)</option>
                </select>
                <i class="fas fa-list"></i>
            </div>
        </div>

        <div id="fields-api_secret" class="groww-auth-fields">
            <div class="form-row">
                <div class="form-group">
                    <label>API Key</label>
                    <div class="input-wrapper">
                        <input type="text" name="api_key" placeholder="Groww API key" autocomplete="off">
                        <i class="fas fa-hashtag"></i>
                    </div>
                </div>
                <div class="form-group">
                    <label>API Secret</label>
                    <div class="input-wrapper">
                        <input type="password" name="api_secret" placeholder="Groww API secret" autocomplete="off">
                        <i class="fas fa-lock"></i>
                    </div>
                </div>
            </div>
        </div>

        <div id="fields-totp" class="groww-auth-fields" style="display:none;">
            <div class="form-row">
                <div class="form-group">
                    <label>API Key</label>
                    <div class="input-wrapper">
                        <input type="text" name="api_key" placeholder="Groww API key" autocomplete="off">
                        <i class="fas fa-hashtag"></i>
                    </div>
                </div>
                <div class="form-group">
                    <label>TOTP Secret</label>
                    <div class="input-wrapper">
                        <input type="password" name="totp_secret" placeholder="Base32 secret shown when enabling TOTP" autocomplete="off">
                        <i class="fas fa-mobile-screen"></i>
                    </div>
                </div>
            </div>
        </div>

        <div id="fields-access_token" class="groww-auth-fields" style="display:none;">
            <div class="form-group">
                <label>Access Token</label>
                <div class="input-wrapper">
                    <input type="password" name="access_token" placeholder="Paste today's access token" autocomplete="off">
                    <i class="fas fa-key"></i>
                </div>
                <small style="color: var(--text-muted);">Expires daily at 6:00 AM IST — you'll need to paste a new one each day.</small>
            </div>
        </div>

        <div style="margin-top: 12px;">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-plug"></i> Save &amp; Test Connection
            </button>
        </div>
    </form>
</div>

<script>
function growwToggleAuthFields() {
    var method = document.getElementById('authMethod').value;
    document.querySelectorAll('.groww-auth-fields').forEach(function (el) { el.style.display = 'none'; });
    var target = document.getElementById('fields-' + method);
    if (target) target.style.display = '';
}
document.addEventListener('DOMContentLoaded', growwToggleAuthFields);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
