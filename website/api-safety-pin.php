<?php
/**
 * Safety PIN for the logged-in web session (see includes/safety_pin.php).
 * POST JSON {action: "unlock", pin} | {action: "lock"} | {action: "status"}
 * with the CSRF token in the X-CSRF-Token header.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/safety_pin.php';

header('Content-Type: application/json');

$auth = new Auth();
$user = $auth->getCurrentUser();
if (!$user) {
    jsonResponse(['success' => false, 'error' => 'Please log in again.'], 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !Auth::validateCSRF($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    jsonResponse(['success' => false, 'error' => 'The request could not be verified. Reload the page.'], 403);
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';
$db = Database::getInstance();
$userId = (int)$user['id'];

if ($action === 'status') {
    jsonResponse(['success' => true, 'set' => spIsSet($db, $userId), 'unlocked' => spIsUnlocked()]);
}

if ($action === 'lock') {
    spLockSession();
    jsonResponse(['success' => true]);
}

if ($action === 'unlock') {
    $result = spVerify($db, $userId, (string)($body['pin'] ?? ''));
    if (!$result['ok']) {
        jsonResponse(['success' => false, 'error' => $result['error']]);
    }
    spUnlockSession();
    $return = $_SESSION['safety_pin_return'] ?? '';
    unset($_SESSION['safety_pin_return']);
    jsonResponse(['success' => true, 'return_url' => $return]);
}

jsonResponse(['success' => false, 'error' => 'Unknown action.'], 400);
