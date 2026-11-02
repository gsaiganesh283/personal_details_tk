<?php
/**
 * WebAuthn Registration - Begin
 * Returns PublicKeyCredentialCreationOptions to browser
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json');

$webauthnLoaded = require __DIR__ . '/includes/webauthn-loader.php';
if (!$webauthnLoaded) {
    http_response_code(500);
    echo json_encode(['error' => 'Server setup incomplete: WebAuthn library missing. Upload includes/webauthn/src or vendor folder.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$requestedRpId = strtolower(trim($body['rpId'] ?? ''));

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$db   = Database::getInstance();
$user = $db->fetch("SELECT id, username, email, full_name FROM users WHERE id = ?", [$_SESSION['user_id']]);

try {
    // Shared-host fallback when migration wasn't executed yet.
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
    error_log('[WebAuthn register begin] table bootstrap failed: ' . $e->getMessage());
}

if (!$user) {
    http_response_code(404);
    echo json_encode(['error' => 'User not found']);
    exit;
}

$rpId = preg_replace('/:\d+$/', '', $requestedRpId);
$rpId = trim($rpId, '. ');
if (!preg_match('/^[a-z0-9.-]+$/', $rpId)) {
    $rpId = '';
}

if ($rpId === '') {
    $rpCandidates = [];
    if (!empty($_SERVER['HTTP_ORIGIN'])) {
        $originHost = parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_HOST);
        if (!empty($originHost)) {
            $rpCandidates[] = $originHost;
        }
    }
    foreach (['HTTP_X_FORWARDED_HOST', 'HTTP_HOST', 'SERVER_NAME'] as $key) {
        if (!empty($_SERVER[$key])) {
            $rpCandidates[] = $_SERVER[$key];
        }
    }
    $rpId = 'localhost';
    foreach ($rpCandidates as $candidate) {
        $host = trim(explode(',', $candidate)[0]);
        $host = preg_replace('/:\d+$/', '', $host);
        $host = trim($host, '. ');
        if ($host !== '') {
            $rpId = strtolower($host);
            break;
        }
    }
}
$rpName = 'Personal Details Saver';

try {
    $webAuthn = new \lbuchs\WebAuthn\WebAuthn($rpName, $rpId, ['none'], true);

    // Collect existing credential IDs for this user to exclude during re-registration
    $existing = $db->fetchAll(
        "SELECT credential_id FROM webauthn_credentials WHERE user_id = ?",
        [$user['id']]
    );
    $excludeIds = [];
    foreach ($existing as $row) {
        $decoded = base64_decode($row['credential_id'] ?? '', true);
        if ($decoded !== false && $decoded !== '') {
            $excludeIds[] = $decoded;
        }
    }

    $createArgs = $webAuthn->getCreateArgs(
        \hex2bin(\str_pad(\dechex($user['id']), 8, '0', STR_PAD_LEFT)),   // user handle (binary)
        $user['username'],
        $user['full_name'] ?: $user['username'],
        60,        // timeout seconds
        'required', // resident key is required for platform passkeys on phone/laptop
        'required', // require biometric or device PIN verification
        false,     // platform authenticator for laptop/mobile fingerprint or Face ID
        $excludeIds
    );

    $_SESSION['webauthn_reg_challenge'] = base64_encode($webAuthn->getChallenge()->getBinaryString());
    $_SESSION['webauthn_reg_rp_id'] = $rpId;

    echo json_encode($createArgs);
} catch (\lbuchs\WebAuthn\WebAuthnException $e) {
    error_log('[WebAuthn register begin] ' . $e->getMessage() . ' | rpId=' . $rpId);
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[WebAuthn register begin] fatal: ' . $e->getMessage() . ' | rpId=' . $rpId);
    http_response_code(500);
    echo json_encode(['error' => 'Registration setup failed. ' . $e->getMessage()]);
}
