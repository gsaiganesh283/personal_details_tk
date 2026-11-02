<?php
/**
 * "Send message to phone" endpoint used by the panel under Custom Fields on
 * every add/edit page (see renderNotifBridgeQuickSend() in
 * includes/custom_fields_helper.php). Called with fetch() so the user stays
 * on the same page.
 *
 *   GET  ?action=devices             -> {success, devices:[{id,label}]}
 *   POST action=send (multipart)     -> {success, sent, total, message}
 *        csrf_token, title, body, priority, device_id, module, record_id, attachment (file)
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/notifbridge_client.php';

$user = requireAuth();
ensureNotifBridgeSchema();
$connection = getNotifBridgeConnection($user['id']);
if (!$connection) {
    jsonResponse(['success' => false, 'error' => 'NotifBridge is not set up yet. Configure it on the Notifications page.'], 400);
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if ($action === 'devices') {
    try {
        $out = [];
        foreach (notifbridgeListDevices($connection) as $d) {
            if (empty($d['active'])) continue;
            $out[] = ['id' => (int)$d['id'], 'label' => notifbridgeDeviceLabel($d)];
        }
        jsonResponse(['success' => true, 'devices' => $out]);
    } catch (NotifBridgeError $e) {
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 502);
    }
}

if ($action !== 'send' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Invalid request'], 400);
}

// An oversized upload makes PHP drop the whole POST body, CSRF token included.
if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    jsonResponse(['success' => false, 'error' => 'The file is too large to upload.'], 413);
}
if (!Auth::validateCSRF($_POST['csrf_token'] ?? '')) {
    jsonResponse(['success' => false, 'error' => 'Your session expired. Reload the page and try again.'], 403);
}

$title = trim($_POST['title'] ?? '');
$body = trim($_POST['body'] ?? '');
$priority = ($_POST['priority'] ?? 'default') === 'high' ? 'high' : 'default';
$deviceParam = trim($_POST['device_id'] ?? 'all');
$deviceIds = ($deviceParam === '' || $deviceParam === 'all') ? 'all' : [(int)$deviceParam];
$file = $_FILES['attachment'] ?? null;
$hasFile = $file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

if ($body === '' && !$hasFile) {
    jsonResponse(['success' => false, 'error' => 'Write a message or attach a file.'], 422);
}
if ($title === '') {
    $title = $hasFile ? basename($file['name']) : 'Message';
}
if ($body === '') {
    $body = 'File: ' . basename($file['name']);
}

$extra = ['source' => 'website'];
if (!empty($_POST['module'])) $extra['module'] = substr(preg_replace('/[^\w\-]/', '', $_POST['module']), 0, 64);
if (!empty($_POST['record_id'])) $extra['record_id'] = (string)(int)$_POST['record_id'];

try {
    $result = notifbridgeSendMessage($connection, mb_substr($title, 0, 200), $body, $deviceIds, $priority, $hasFile ? $file : null, $extra);
    $results = $result['results'] ?? [];
    $sent = count(array_filter($results, function ($r) { return !empty($r['success']); }));
    if (function_exists('logActivity')) {
        @logActivity($user['id'], 'notification', 'Sent to phone: ' . $title);
    }
    jsonResponse([
        'success' => $sent > 0,
        'sent' => $sent,
        'total' => count($results),
        'message' => $sent > 0 ? "Sent to $sent of " . count($results) . ' device(s).' : 'Not delivered to any device.',
        'error' => $sent > 0 ? null : implode('; ', array_filter(array_column($results, 'error'))),
    ]);
} catch (NotifBridgeError $e) {
    $msg = $e->getMessage();
    if (strpos($msg, '404:') === 0) {
        $msg = 'No active phone is registered with this API key. Open the NotifBridge app on your phone.';
    }
    jsonResponse(['success' => false, 'error' => $msg], 502);
}
