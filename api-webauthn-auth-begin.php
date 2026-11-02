<?php
/**
 * WebAuthn Authentication - Begin
 * Returns PublicKeyCredentialRequestOptions to browser.
 * Can be called before or after password entry (see login.php).
 * Expects POST JSON: { "username": "..." }
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json');

$webauthnLoaded = require __DIR__ . '/includes/webauthn-loader.php';
if (!$webauthnLoaded) {
    http_response_code(500);
    echo json_encode(['error' => 'Server setup incomplete: WebAuthn library missing. Upload includes/webauthn/src or vendor folder.']);
    exit;
}

$body     = json_decode(file_get_contents('php://input'), true);
$username = trim($body['username'] ?? '');
$requestedRpId = strtolower(trim($body['rpId'] ?? ''));

if ($username === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Username required']);
    exit;
}

$db = Database::getInstance();

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
    error_log('[WebAuthn auth begin] table bootstrap failed: ' . $e->getMessage());
}

$user = $db->fetch(
    "SELECT id FROM users WHERE (username = ? OR email = ?) AND is_active = 1",
    [$username, $username]
);

if (!$user) {
    // Return a generic error (do not reveal whether user exists)
    http_response_code(400);
    echo json_encode(['error' => 'No passkey registered for this account']);
    exit;
}

$credentials = $db->fetchAll(
    "SELECT credential_id FROM webauthn_credentials WHERE user_id = ?",
    [$user['id']]
);

if (empty($credentials)) {
    http_response_code(400);
    echo json_encode(['error' => 'No passkey registered for this account. Set one up in Profile > Security.']);
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
    $webAuthn    = new \lbuchs\WebAuthn\WebAuthn($rpName, $rpId, ['none'], true);
    $credIds = [];
    foreach ($credentials as $row) {
        $decoded = base64_decode($row['credential_id'] ?? '', true);
        if ($decoded !== false && $decoded !== '') {
            $credIds[] = $decoded;
        }
    }
    if (empty($credIds)) {
        http_response_code(400);
        echo json_encode(['error' => 'No valid passkey credentials found for this account']);
        exit;
    }
    $getArgs     = $webAuthn->getGetArgs($credIds, 60, true, true, true, true, true, 'required');

    $_SESSION['webauthn_auth_challenge'] = base64_encode($webAuthn->getChallenge()->getBinaryString());
    $_SESSION['webauthn_auth_user_id']   = $user['id'];
    $_SESSION['webauthn_auth_rp_id']     = $rpId;

    echo json_encode($getArgs);
} catch (\lbuchs\WebAuthn\WebAuthnException $e) {
    error_log('[WebAuthn auth begin] ' . $e->getMessage() . ' | rpId=' . $rpId);
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[WebAuthn auth begin] fatal: ' . $e->getMessage() . ' | rpId=' . $rpId);
    http_response_code(500);
    echo json_encode(['error' => 'Passkey login setup failed. ' . $e->getMessage()]);
}
