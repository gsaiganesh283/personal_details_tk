<?php
/**
 * "Send now" button of the Send-to-phone custom field type.
 *
 * POST action=preview -> what would be sent (nothing is sent)
 * POST action=send    -> sends it
 * Common fields: csrf_token, field_id, record_id, instance, values (JSON of
 * on-screen values keyed by column / "cf:<name>"), upload_keys (JSON
 * {"cf:<name>": "upload_0", ...}) + the files themselves.
 * send also takes: title, body (edited preview), priority, device_id, pin.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/custom_fields_helper.php';
require_once __DIR__ . '/includes/field_send_helper.php';
require_once __DIR__ . '/includes/safety_pin.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$user = requireAuth();
$userId = (int)$user['id'];
$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'POST only'], 405);
}
if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    jsonResponse(['success' => false, 'error' => 'The files are too large to upload.'], 413);
}
if (!Auth::validateCSRF($_POST['csrf_token'] ?? '')) {
    jsonResponse(['success' => false, 'error' => 'Your session expired. Reload the page and try again.'], 403);
}

$action = $_POST['action'] ?? '';
$fieldId = (int)($_POST['field_id'] ?? 0);
$recordId = max(0, (int)($_POST['record_id'] ?? 0));
$instance = max(0, (int)($_POST['instance'] ?? 0));

$field = $db->fetch(
    "SELECT * FROM custom_fields WHERE id = ? AND user_id = ? AND field_type = 'send_now' AND is_active = 1",
    [$fieldId, $userId]
);
if (!$field) {
    jsonResponse(['success' => false, 'error' => 'This Send now field no longer exists.'], 404);
}
$cfg = fsParseConfig($field['field_options']);
if (!$cfg['fields']) {
    jsonResponse(['success' => false, 'error' => 'No fields are selected for sending. Edit this field in Custom Fields.'], 422);
}
$connection = getNotifBridgeConnection($userId);
if (!$connection) {
    jsonResponse(['success' => false, 'error' => 'NotifBridge is not set up yet. Connect it on the Notifications page.'], 400);
}

// On-screen values: only keys that are part of this field's selection are used.
$formValues = [];
$posted = json_decode($_POST['values'] ?? '{}', true);
if (is_array($posted)) {
    foreach ($posted as $k => $v) {
        if (in_array($k, $cfg['fields'], true) && is_scalar($v)) $formValues[$k] = mb_substr((string)$v, 0, 5000);
    }
}
$uploads = [];
$uploadKeys = json_decode($_POST['upload_keys'] ?? '{}', true);
if (is_array($uploadKeys)) {
    foreach ($uploadKeys as $key => $input) {
        if (in_array($key, $cfg['fields'], true) && preg_match('/^upload_\d{1,2}$/', (string)$input) && isset($_FILES[$input])) {
            $uploads[$key] = $_FILES[$input];
        }
    }
}

$maskOverride = isset($_POST['mask']) ? ($_POST['mask'] === '1') : null;

try {
    $msg = fsBuildMessage($db, $field, $userId, $recordId, $formValues, $uploads, $maskOverride, $instance);
} catch (Exception $e) {
    jsonResponse(['success' => false, 'error' => $e->getMessage()], 404);
}

if ($action === 'preview') {
    $last = fsLastSent($db, $userId, $fieldId, $recordId);
    $out = [
        'success' => true,
        'title' => $msg['title'],
        'body' => fsBody($msg),
        'files' => array_map(fn($f) => ['name' => $f[1], 'label' => $f[2], 'size' => @filesize($f[0]) ?: 0], $msg['files']),
        'skipped' => $msg['skipped'],
        'mask' => $maskOverride ?? $cfg['mask'],
        'needs_pin' => $cfg['pin'],
        'confirm' => $cfg['confirm'],
        'priority' => $cfg['priority'],
        'cooldown_left' => fsCooldownLeft($db, $userId, $field, $recordId),
        'last_sent' => $last,
    ];
    fsCleanup($msg);
    $out['devices'] = [];
    try {
        foreach (notifbridgeListDevices($connection) as $d) {
            if (!empty($d['active'])) $out['devices'][] = ['id' => (int)$d['id'], 'label' => notifbridgeDeviceLabel($d)];
        }
    } catch (NotifBridgeError $e) {
        // device list is optional
    }
    jsonResponse($out);
}

if ($action !== 'send') {
    fsCleanup($msg);
    jsonResponse(['success' => false, 'error' => 'Invalid action'], 400);
}

// ---- send: security gates
$wait = fsCooldownLeft($db, $userId, $field, $recordId);
if ($wait > 0) {
    fsCleanup($msg);
    jsonResponse(['success' => false, 'error' => "This was just sent. Try again in $wait s.", 'cooldown_left' => $wait], 429);
}
if ($cfg['pin']) {
    spEnsureTable($db);
    if (!spIsSet($db, $userId)) {
        fsCleanup($msg);
        jsonResponse(['success' => false, 'error' => 'This field needs your Safety PIN, but none is set. Create one in Settings.', 'needs_pin' => true], 403);
    }
    $check = spVerify($db, $userId, (string)($_POST['pin'] ?? ''));
    if (!$check['ok']) {
        spLog($db, $userId, "Wrong Safety PIN for Send now '{$field['field_label']}'");
        fsCleanup($msg);
        jsonResponse(['success' => false, 'error' => $check['error'] ?: 'Wrong PIN.', 'needs_pin' => true], 403);
    }
}
if (!$msg['lines'] && !$msg['files']) {
    fsCleanup($msg);
    jsonResponse(['success' => false, 'error' => 'Nothing to send -- the selected fields are empty.'], 422);
}

$title = trim($_POST['title'] ?? '') !== '' ? mb_substr(trim($_POST['title']), 0, 200) : $msg['title'];
$body = trim($_POST['body'] ?? '') !== '' ? mb_substr(trim($_POST['body']), 0, 8000) : fsBody($msg);
// The preview may drop files; never add any that weren't part of this record.
$keep = json_decode($_POST['keep_files'] ?? 'null', true);
$files = is_array($keep) ? array_values(array_filter($msg['files'], fn($f) => in_array($f[1], $keep, true))) : $msg['files'];
$priority = ($_POST['priority'] ?? $cfg['priority']) === 'high' ? 'high' : 'default';
$deviceParam = trim($_POST['device_id'] ?? 'all');
$deviceIds = ($deviceParam === '' || $deviceParam === 'all') ? 'all' : [(int)$deviceParam];

try {
    $result = fsSend($db, $connection, $field, $userId, $recordId, $title, $body, $files, $priority, $deviceIds);
} catch (NotifBridgeError $e) {
    fsCleanup($msg);
    try {
        $db->insert("INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
            [$userId, 'notification', "Send '{$field['field_label']}' ({$field['linked_to']} #$recordId) failed: " . substr($e->getMessage(), 0, 200),
             $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (Exception $ignored) {}
    $m = $e->getMessage();
    if (strpos($m, '404:') === 0) $m = 'No active phone is registered. Open the NotifBridge app on your phone.';
    jsonResponse(['success' => false, 'error' => $m], 502);
}
fsCleanup($msg);

$results = $result['results'] ?? [];
$ok = count(array_filter($results, fn($r) => !empty($r['success'])));
jsonResponse([
    'success' => $ok > 0,
    'message' => $ok > 0 ? "Sent to $ok of " . count($results) . ' device(s)' . ($files ? ' with ' . count($files) . ' file(s)' : '') . '.' : 'Not delivered to any device.',
    'error' => $ok > 0 ? null : implode('; ', array_filter(array_column($results, 'error'))),
    'last_sent' => fsLastSent($db, $userId, $fieldId, $recordId),
]);
