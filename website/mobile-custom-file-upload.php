<?php
/**
 * Set or clear a file-type custom field's value for one record, from the
 * native mobile app -- multipart POST, Bearer auth (same scheme as
 * mobile-file-upload.php). Mirrors saveCustomFieldEntries()'s own file
 * branch in includes/custom_fields_helper.php: upload Drive-first (falling
 * back to local disk), write the entry, and only *then* delete the file it
 * replaced, so a failed upload never loses the existing file.
 *
 * The mobile app calls this from its Save button, after the record itself
 * (and its other custom fields) have been saved through desktop-sync-api.php
 * -- file values aren't written through that generic table API, since it
 * has no way to upload the file or clean up the old one.
 *
 * POST fields:
 *   action           "upload" (default; needs a `file` part) or "remove"
 *   custom_field_id  the file-type field being set
 *   linked_to        module key the entry belongs to (e.g. "other_folder_4")
 *   record_id        the record's id
 *   block_instance   repeatable-block instance (default 0)
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mobile-auth-helpers.php';
require_once __DIR__ . '/includes/custom_fields_helper.php';

header('Content-Type: application/json');

function respond($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'error' => 'Method not allowed'], 405);
}

$db = Database::getInstance();
ensureApiTokensTable($db);

$bearer = extractBearerToken();
$userId = $bearer ? resolveApiToken($db, $bearer) : null;
if ($userId === null) {
    respond(['success' => false, 'error' => 'Unauthorized'], 401);
}

$action = $_POST['action'] ?? 'upload';
$fieldId = (int)($_POST['custom_field_id'] ?? 0);
$linkedTo = trim((string)($_POST['linked_to'] ?? ''));
$recordId = (int)($_POST['record_id'] ?? 0);
$instance = max(0, (int)($_POST['block_instance'] ?? 0));

if (!$fieldId || $linkedTo === '' || !$recordId) {
    respond(['success' => false, 'error' => 'custom_field_id, linked_to and record_id are required'], 400);
}

$field = $db->fetch("SELECT id, field_type FROM custom_fields WHERE id = ? AND user_id = ?", [$fieldId, $userId]);
if (!$field) {
    respond(['success' => false, 'error' => 'Custom field not found'], 404);
}
if (!in_array($field['field_type'], getFileFieldTypes(), true)) {
    respond(['success' => false, 'error' => 'Not a file field'], 400);
}

$existing = $db->fetch(
    "SELECT id, field_value FROM custom_field_entries
     WHERE custom_field_id = ? AND linked_to = ? AND record_id = ? AND block_instance = ? AND user_id = ?",
    [$fieldId, $linkedTo, $recordId, $instance, $userId]
);
$oldValue = $existing['field_value'] ?? '';

if ($action === 'remove') {
    if ($existing) {
        $db->delete("DELETE FROM custom_field_entries WHERE id = ?", [$existing['id']]);
        deleteCustomFieldFileByValue($oldValue);
    }
    respond(['success' => true, 'value' => '']);
}

if ($action !== 'upload') {
    respond(['success' => false, 'error' => 'Unknown action'], 400);
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    respond(['success' => false, 'error' => 'A file is required (field: file)'], 400);
}
$file = $_FILES['file'];
if ($file['size'] > MAX_UPLOAD_SIZE) {
    respond(['success' => false, 'error' => 'File exceeds the upload size limit'], 400);
}
if (!in_array($file['type'], getAllowedMimes($field['field_type']), true)) {
    respond(['success' => false, 'error' => 'File type not allowed for this field (' . getAcceptAttribute($field['field_type']) . ')'], 400);
}

$newValue = handleCustomFieldUploadByKey('file', $fieldId, $userId, $field['field_type']);
if ($newValue === '') {
    respond(['success' => false, 'error' => 'Upload failed'], 502);
}

if ($existing) {
    $db->update("UPDATE custom_field_entries SET field_value = ? WHERE id = ?", [$newValue, $existing['id']]);
} else {
    $db->insert(
        "INSERT INTO custom_field_entries (user_id, custom_field_id, linked_to, record_id, block_instance, field_value) VALUES (?, ?, ?, ?, ?, ?)",
        [$userId, $fieldId, $linkedTo, $recordId, $instance, $newValue]
    );
}

// Only after the entry points at the new file -- same order as the website's own save path.
if ($oldValue !== '' && $oldValue !== $newValue) {
    deleteCustomFieldFileByValue($oldValue);
}

$db->insert(
    "INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
    [$userId, 'update', 'Uploaded custom field file: ' . $file['name'], $_SERVER['REMOTE_ADDR'] ?? '']
);

respond(['success' => true, 'value' => $newValue]);
