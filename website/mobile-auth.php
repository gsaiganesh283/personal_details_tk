<?php
/**
 * Login/logout/whoami for the native Android app. Same single-`action`
 * POST-dispatch shape as desktop-sync-api.php, kept separate from it since
 * this issues/manages tokens rather than moving data rows.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mobile-auth-helpers.php';

header('Content-Type: application/json');

function respond($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

/** Strips sensitive columns before a user row ever leaves the server. */
function publicUser(array $user): array {
    return [
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'email' => $user['email'],
        'full_name' => $user['full_name'] ?? null,
        'role' => $user['role'] ?? 'user',
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'error' => 'Method not allowed'], 405);
}

$db = Database::getInstance();
ensureApiTokensTable($db);

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    respond(['success' => false, 'error' => 'Invalid JSON body'], 400);
}

$action = $body['action'] ?? '';

if ($action === 'login') {
    $username = (string)($body['username'] ?? '');
    $password = (string)($body['password'] ?? '');
    $deviceLabel = isset($body['device_label']) ? substr((string)$body['device_label'], 0, 100) : null;

    if ($username === '' || $password === '') {
        respond(['success' => false, 'error' => 'Username and password are required'], 400);
    }

    $auth = new Auth();
    $result = $auth->login($username, $password, false); // rememberMe=false -- no cookie is set

    if (!$result['success']) {
        respond(['success' => false, 'error' => 'Invalid credentials'], 401);
    }

    $user = $result['user'];
    if (!empty($user['totp_enabled'])) {
        // Mobile doesn't support the 2FA step yet -- fail closed rather than
        // silently skipping the check this account opted into.
        respond(['success' => false, 'error' => '2FA accounts are not yet supported on mobile. Use the website to sign in.'], 400);
    }

    $token = issueApiToken($db, (int)$user['id'], $deviceLabel);
    respond(['success' => true, 'token' => $token, 'user' => publicUser($user)]);
}

// Everything below requires a valid bearer token.
$bearer = extractBearerToken();
$userId = $bearer ? resolveApiToken($db, $bearer) : null;
if ($userId === null) {
    respond(['success' => false, 'error' => 'Unauthorized'], 401);
}

if ($action === 'logout') {
    revokeApiToken($db, $bearer);
    respond(['success' => true]);
}

if ($action === 'me') {
    $user = $db->fetch("SELECT * FROM users WHERE id = ?", [$userId]);
    if (!$user) {
        respond(['success' => false, 'error' => 'User not found'], 404);
    }
    respond(['success' => true, 'user' => publicUser($user)]);
}

respond(['success' => false, 'error' => 'Unknown action'], 400);
