<?php
/**
 * WebAuthn Authentication - Complete
 * Verifies assertion and creates a login session.
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

$challengeEncoded = $_SESSION['webauthn_auth_challenge'] ?? null;
$challenge = is_string($challengeEncoded) ? base64_decode($challengeEncoded, true) : false;
$userId    = $_SESSION['webauthn_auth_user_id']   ?? null;

if (!$challenge || !$userId) {
    http_response_code(400);
    echo json_encode(['error' => 'No authentication challenge in session. Start again.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request body']);
    exit;
}

$clientDataJSON    = base64_decode($body['clientDataJSON']    ?? '');
$authenticatorData = base64_decode($body['authenticatorData'] ?? '');
$signature         = base64_decode($body['signature']         ?? '');
$credentialIdB64   = $body['credentialId'] ?? '';
$credentialId      = base64_decode($credentialIdB64, true);

if ($clientDataJSON === false || $authenticatorData === false || $signature === false || $credentialId === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid passkey assertion payload']);
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
    error_log('[WebAuthn auth complete] table bootstrap failed: ' . $e->getMessage());
}

// Load matching credential
$storedCred = $db->fetch(
    "SELECT * FROM webauthn_credentials WHERE credential_id = ? AND user_id = ?",
    [base64_encode($credentialId), $userId]
);

if (!$storedCred) {
    http_response_code(400);
    echo json_encode(['error' => 'Credential not found for this user']);
    exit;
}

$rpId = $_SESSION['webauthn_auth_rp_id'] ?? null;
if (!$rpId) {
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
            $rpId = $host;
            break;
        }
    }
}
$rpName = 'Personal Details Saver';

try {
    $webAuthn = new \lbuchs\WebAuthn\WebAuthn($rpName, $rpId, ['none'], true);

    $webAuthn->processGet(
        $clientDataJSON,
        $authenticatorData,
        $signature,
        base64_decode($storedCred['public_key']),
        $challenge,
        $storedCred['sign_count'],
        true   // requireUserVerification
    );

    // Update sign count
    $db->update(
        "UPDATE webauthn_credentials SET sign_count = ?, last_used = NOW() WHERE id = ?",
        [$webAuthn->getSignatureCounter() ?? $storedCred['sign_count'], $storedCred['id']]
    );

    // Clear challenge
    unset($_SESSION['webauthn_auth_challenge'], $_SESSION['webauthn_auth_user_id'], $_SESSION['webauthn_auth_rp_id']);

    // Create full login session
    $_SESSION['user_id']       = $userId;
    $_SESSION['logged_in']     = true;
    $_SESSION['login_time']    = time();
    $_SESSION['last_activity'] = time();

    // Log activity
    $db->insert(
        "INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
        [$userId, 'login_fingerprint', 'Logged in with fingerprint/passkey', $_SERVER['REMOTE_ADDR'] ?? '']
    );

    echo json_encode(['success' => true, 'redirect' => BASE_PATH . '/dashboard.php']);
} catch (\lbuchs\WebAuthn\WebAuthnException $e) {
    error_log('[WebAuthn auth complete] ' . $e->getMessage() . ' | rpId=' . $rpId);
    http_response_code(400);
    echo json_encode(['error' => 'Fingerprint verification failed: ' . $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[WebAuthn auth complete] fatal: ' . $e->getMessage() . ' | rpId=' . $rpId);
    http_response_code(500);
    echo json_encode(['error' => 'Fingerprint verification failed. ' . $e->getMessage()]);
}
