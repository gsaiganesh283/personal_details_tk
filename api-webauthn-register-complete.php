<?php
/**
 * WebAuthn Registration - Complete
 * Verifies attestation, stores credential in DB
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

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$challengeEncoded = $_SESSION['webauthn_reg_challenge'] ?? null;
$challenge = is_string($challengeEncoded) ? base64_decode($challengeEncoded, true) : false;
if (!$challenge) {
    http_response_code(400);
    echo json_encode(['error' => 'No challenge in session. Start registration again.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request body']);
    exit;
}

$clientDataJSON    = base64_decode($body['clientDataJSON']    ?? '');
$attestationObject = base64_decode($body['attestationObject'] ?? '');
$deviceName        = htmlspecialchars(trim($body['deviceName'] ?? 'My Device'), ENT_QUOTES, 'UTF-8');
if ($deviceName === '') $deviceName = 'My Device';

if ($clientDataJSON === false || $attestationObject === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid passkey payload']);
    exit;
}

$rpId = $_SESSION['webauthn_reg_rp_id'] ?? null;
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
    $data = $webAuthn->processCreate($clientDataJSON, $attestationObject, $challenge, true, true, false, false);

    $credentialId = base64_encode($data->credentialId);
    $publicKey    = base64_encode($data->credentialPublicKey);
    $signCount    = $data->signatureCounter ?? 0;

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
        error_log('[WebAuthn register complete] table bootstrap failed: ' . $e->getMessage());
    }

    // Upsert: if same credential re-registered, update it
    $exists = $db->fetch(
        "SELECT id FROM webauthn_credentials WHERE credential_id = ?",
        [$credentialId]
    );
    if ($exists) {
        $db->update(
            "UPDATE webauthn_credentials SET public_key = ?, sign_count = ?, device_name = ?, last_used = NOW() WHERE credential_id = ?",
            [$publicKey, $signCount, $deviceName, $credentialId]
        );
    } else {
        $db->insert(
            "INSERT INTO webauthn_credentials (user_id, credential_id, public_key, sign_count, device_name) VALUES (?, ?, ?, ?, ?)",
            [$_SESSION['user_id'], $credentialId, $publicKey, $signCount, $deviceName]
        );
    }

    unset($_SESSION['webauthn_reg_challenge'], $_SESSION['webauthn_reg_rp_id']);

    $db->insert(
        "INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
        [$_SESSION['user_id'], 'settings', 'Registered fingerprint/passkey: ' . $deviceName, $_SERVER['REMOTE_ADDR'] ?? '']
    );

    echo json_encode(['success' => true, 'message' => 'Fingerprint registered successfully!']);
} catch (\lbuchs\WebAuthn\WebAuthnException $e) {
    error_log('[WebAuthn register complete] ' . $e->getMessage() . ' | rpId=' . $rpId);
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[WebAuthn register complete] fatal: ' . $e->getMessage() . ' | rpId=' . $rpId);
    http_response_code(500);
    echo json_encode(['error' => 'Registration failed. ' . $e->getMessage()]);
}
