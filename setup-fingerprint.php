<?php
/**
 * Fingerprint / Passkey Setup Page
 * Lists registered devices and allows adding/removing them.
 */
$pageTitle = 'Setup Fingerprint / Passkey';
require_once __DIR__ . '/includes/header.php';
$user = requireAuth();
$db   = Database::getInstance();
$profileSecurityUrl = url('profile.php');
$webauthnServerReady = is_file(__DIR__ . '/vendor/autoload.php') || is_file(__DIR__ . '/includes/webauthn/src/WebAuthn.php');

// Ensure WebAuthn table exists so this page still opens on hosts missing migrations.
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
  error_log('WebAuthn table bootstrap failed on setup-fingerprint.php: ' . $e->getMessage());
}

// Handle DELETE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_credential') {
    $credId = intval($_POST['cred_id'] ?? 0);
    if ($credId) {
        $db->delete("DELETE FROM webauthn_credentials WHERE id = ? AND user_id = ?", [$credId, $user['id']]);
        $db->insert("INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
            [$user['id'], 'settings', 'Removed fingerprint/passkey credential #' . $credId, $_SERVER['REMOTE_ADDR'] ?? '']);
        setFlash('success', 'Device removed.');
    }
    redirect('/setup-fingerprint.php');
}

try {
  $credentials = $db->fetchAll(
    "SELECT id, device_name, created_at, last_used FROM webauthn_credentials WHERE user_id = ? ORDER BY created_at DESC",
    [$user['id']]
  );
} catch (Exception $e) {
  $credentials = [];
  error_log('Credential list query failed on setup-fingerprint.php: ' . $e->getMessage());
}
?>

<?php displayFlash(); ?>

<div class="content-area">
  <div class="page-header">
    <div>
      <h1><i class="fas fa-fingerprint" style="color:var(--primary);margin-right:8px;"></i> Fingerprint / Passkey Login</h1>
      <p class="text-muted">Register your device's fingerprint or Face ID to log in without typing your password.</p>
    </div>
    <a href="<?php echo htmlspecialchars($profileSecurityUrl, ENT_QUOTES, 'UTF-8'); ?>" id="backToProfileBtn" class="btn btn-secondary" onclick="window.location.href='<?php echo htmlspecialchars($profileSecurityUrl, ENT_QUOTES, 'UTF-8'); ?>'; return false;">
      <i class="fas fa-arrow-left"></i> Back to Profile
    </a>
  </div>

  <?php if (!$webauthnServerReady): ?>
    <div class="alert alert-warning" style="margin-bottom:1rem; display:flex;">
      <i class="fas fa-exclamation-triangle"></i>
      <span>WebAuthn server dependency is missing on hosting. Upload either <strong>includes/webauthn/src</strong> (bundled fallback) or the full <strong>vendor</strong> folder to enable fingerprint registration.</span>
    </div>
  <?php endif; ?>

  <!-- How it works -->
  <div class="card" style="margin-bottom:1.5rem;">
    <div class="card-body">
      <h3 style="margin-bottom:.75rem;"><i class="fas fa-info-circle" style="color:var(--primary);margin-right:6px;"></i>How it works</h3>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;">
        <div style="text-align:center;padding:1rem;">
          <div style="font-size:2rem;margin-bottom:.5rem;">👆</div>
          <strong>Register</strong>
          <p class="text-muted" style="font-size:.85rem;margin-top:.25rem;">Click "Add Device" and touch your fingerprint reader or use Face ID.</p>
        </div>
        <div style="text-align:center;padding:1rem;">
          <div style="font-size:2rem;margin-bottom:.5rem;">🔐</div>
          <strong>Login</strong>
          <p class="text-muted" style="font-size:.85rem;margin-top:.25rem;">On the login page, enter your username and tap "Sign in with Fingerprint".</p>
        </div>
        <div style="text-align:center;padding:1rem;">
          <div style="font-size:2rem;margin-bottom:.5rem;">🛡️</div>
          <strong>Secure</strong>
          <p class="text-muted" style="font-size:.85rem;margin-top:.25rem;">Your biometric never leaves the device. Only a cryptographic key is stored.</p>
        </div>
      </div>
    </div>
  </div>

  <!-- Register new device -->
  <div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
      <h3><i class="fas fa-plus-circle" style="color:var(--primary);margin-right:6px;"></i> Add a New Device</h3>
    </div>
    <div class="card-body">
      <div class="form-group" style="max-width:360px;">
        <label>Device nickname (optional)</label>
        <div class="input-wrapper">
          <input type="text" id="deviceNameInput" placeholder="e.g. My Phone, Work Laptop" maxlength="80">
          <i class="fas fa-tag"></i>
        </div>
      </div>
      <button class="btn btn-primary" id="registerBtn">
        <i class="fas fa-fingerprint"></i> Add Device / Register Fingerprint
      </button>
      <div id="regStatus" style="margin-top:1rem;display:none;" class="alert"></div>
    </div>
  </div>

  <!-- Registered devices -->
  <div class="card">
    <div class="card-header">
      <h3><i class="fas fa-mobile-alt" style="color:var(--primary);margin-right:6px;"></i> Registered Devices
        <span class="badge" style="margin-left:.5rem;background:var(--primary);color:#fff;border-radius:999px;padding:2px 10px;font-size:.8rem;">
          <?php echo count($credentials); ?>
        </span>
      </h3>
    </div>
    <div class="card-body">
      <?php if (empty($credentials)): ?>
        <div style="text-align:center;padding:3rem 1rem;color:var(--text-muted);">
          <i class="fas fa-fingerprint" style="font-size:3rem;margin-bottom:1rem;opacity:.3;"></i>
          <p>No devices registered yet. Add one above.</p>
        </div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table">
            <thead>
              <tr>
                <th>Device</th>
                <th>Registered</th>
                <th>Last Used</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($credentials as $cred): ?>
              <tr>
                <td><i class="fas fa-fingerprint" style="color:var(--primary);margin-right:6px;"></i><?php echo htmlspecialchars($cred['device_name']); ?></td>
                <td><?php echo date('d M Y, H:i', strtotime($cred['created_at'])); ?></td>
                <td><?php echo $cred['last_used'] ? date('d M Y, H:i', strtotime($cred['last_used'])) : '—'; ?></td>
                <td>
                  <form method="POST" onsubmit="return confirm('Remove this device? You will no longer be able to use it for fingerprint login.');">
<?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="delete_credential">
                    <input type="hidden" name="cred_id" value="<?php echo intval($cred['id']); ?>">
                    <button type="submit" class="btn btn-danger btn-sm">
                      <i class="fas fa-trash"></i> Remove
                    </button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
(function () {
  const btn     = document.getElementById('registerBtn');
  const status  = document.getElementById('regStatus');
  const nameInp = document.getElementById('deviceNameInput');

  function showStatus(msg, type) {
    status.className = 'alert alert-' + type + ' animate-slide-in';
    status.innerHTML = '<i class="fas fa-' + (type === 'success' ? 'check-circle' : 'exclamation-circle') + '"></i> ' + msg;
    status.style.display = 'flex';
  }

  var serverReady = <?php echo $webauthnServerReady ? 'true' : 'false'; ?>;
  if (!serverReady) {
    btn.disabled = true;
    showStatus('Server setup incomplete: WebAuthn library missing. Upload includes/webauthn/src or vendor folder.', 'error');
    return;
  }

  if (!window.PublicKeyCredential) {
    btn.disabled = true;
    showStatus('Your browser does not support WebAuthn / passkeys. Try Chrome, Edge, Safari or Firefox 86+.', 'error');
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
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Waiting for fingerprint…';
    status.style.display = 'none';

    try {
      // Step 1 – get options from server
      const optsRes = await fetch('<?php echo url("api-webauthn-register-begin.php"); ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ rpId: window.location.hostname })
      });
      const optsPayload = await parseJsonResponse(optsRes);
      if (!optsRes.ok) {
        throw new Error(
          (optsPayload.data && optsPayload.data.error)
            || ('Server error (' + optsRes.status + ')' + (optsPayload.text ? ': ' + optsPayload.text.slice(0, 180) : ''))
        );
      }
      if (!optsPayload.data) {
        throw new Error('Register-begin API returned invalid JSON (empty/HTML response). ' + (optsPayload.text ? ('Response: ' + optsPayload.text.slice(0, 220)) : 'No response body.'));
      }
      const opts = optsPayload.data;
      const publicKey = opts.publicKey || opts;

      // Decode base64url → ArrayBuffer
      publicKey.challenge = b64ToBuffer(publicKey.challenge);
      publicKey.user.id   = b64ToBuffer(publicKey.user.id);
      if (publicKey.excludeCredentials) {
        publicKey.excludeCredentials = publicKey.excludeCredentials.map(c => ({
          ...c, id: b64ToBuffer(c.id.data ?? c.id)
        }));
      }

      // Step 2 – browser prompts fingerprint
      const credential = await navigator.credentials.create({ publicKey });

      // Step 3 – send result to server
      const payload = {
        clientDataJSON:    bufToB64(credential.response.clientDataJSON),
        attestationObject: bufToB64(credential.response.attestationObject),
        deviceName:        nameInp.value.trim() || 'My Device'
      };

      const verRes = await fetch('<?php echo url("api-webauthn-register-complete.php"); ?>', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const verPayload = await parseJsonResponse(verRes);
      if (!verRes.ok) {
        throw new Error(
          (verPayload.data && verPayload.data.error)
            || ('Registration failed (' + verRes.status + ')' + (verPayload.text ? ': ' + verPayload.text.slice(0, 180) : ''))
        );
      }
      if (!verPayload.data) {
        throw new Error('Register-complete API returned invalid JSON (empty/HTML response). ' + (verPayload.text ? ('Response: ' + verPayload.text.slice(0, 220)) : 'No response body.'));
      }
      const verData = verPayload.data;

      if (verData.success) {
        showStatus('✅ Device registered! You can now use fingerprint login.', 'success');
        setTimeout(() => location.reload(), 1500);
      } else {
        throw new Error(verData.error || 'Registration failed');
      }
    } catch (e) {
      const message = e && e.name === 'NotSupportedError'
        ? 'This device/browser cannot create a passkey here. Use Chrome, Edge, Safari, or a supported mobile browser over HTTPS.'
        : (e && e.name === 'SecurityError'
          ? 'The browser rejected this request because the site domain did not match the WebAuthn relying party. Reload the page from the public app URL and try again.'
        : (e && e.name === 'InvalidStateError'
          ? 'This device is already registered for this account.'
          : (e.message || 'Registration cancelled or failed.')));
      showStatus(message, 'error');
    } finally {
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-fingerprint"></i> Add Device / Register Fingerprint';
    }
  });

  function b64ToBuffer(base64) {
    const normalized = base64.replace(/-/g, '+').replace(/_/g, '/');
    const padded = normalized + '='.repeat((4 - normalized.length % 4) % 4);
    const bin  = atob(padded);
    const buf  = new Uint8Array(bin.length);
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

<?php require_once __DIR__ . '/includes/footer.php'; ?>
