<?php
/**
 * Document upload for the native Android app -- multipart POST, Bearer
 * auth. Mirrors documents.php's own upload branch (Drive-first, falling
 * back to local disk) so both surfaces write identically-shaped `documents`
 * rows and files land in the same place either way. Custom field values for
 * the record are written afterward via desktop-sync-api.php's
 * custom_field_entries table, same as every other module.
 *
 * With an `id` field this edits that existing document instead: the
 * metadata is always updated, and the file is replaced only if a new one
 * is attached -- the old file is deleted only after the row points at the
 * new one, so a failed upload never loses the existing file.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mobile-auth-helpers.php';
require_once __DIR__ . '/includes/google-drive-helper.php';

header('Content-Type: application/json');

function respond($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

/** Deletes a replaced document's file -- "gdrive:{id}" / "gdrive:{id}:{name}" (desktop uploads) or a local path under this user's own folder. */
function deleteDocumentFile(string $filePath, int $userId): void {
    if (strpos($filePath, 'gdrive:') === 0) {
        $gdriveId = explode(':', $filePath, 3)[1] ?? '';
        if ($gdriveId !== '') {
            deleteFromGoogleDrive($gdriveId);
        }
        return;
    }
    $prefix = 'uploads/documents/' . $userId . '/';
    if (strpos($filePath, $prefix) === 0 && strpos($filePath, '..') === false) {
        $local = __DIR__ . '/' . $filePath;
        if (is_file($local)) {
            unlink($local);
        }
    }
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

$documentName = sanitize($_POST['document_name'] ?? '');
$documentType = $_POST['document_type'] ?? 'other';
$description = sanitize($_POST['description'] ?? '');
$tags = sanitize($_POST['tags'] ?? '');
$expiryDate = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;

$editId = (int)($_POST['id'] ?? 0);

if ($documentName === '') {
    respond(['success' => false, 'error' => 'document_name is required'], 400);
}

$existingDoc = null;
if ($editId) {
    $existingDoc = $db->fetch("SELECT * FROM documents WHERE id = ? AND user_id = ?", [$editId, $userId]);
    if (!$existingDoc) {
        respond(['success' => false, 'error' => 'Document not found'], 404);
    }
}

$hasFile = isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK;

if ($existingDoc && !$hasFile) {
    $db->update(
        "UPDATE documents SET document_name = ?, document_type = ?, description = ?, tags = ?, expiry_date = ? WHERE id = ? AND user_id = ?",
        [$documentName, $documentType, $description, $tags, $expiryDate, $editId, $userId]
    );
    $db->insert(
        "INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
        [$userId, 'update', 'Updated document: ' . $documentName, $_SERVER['REMOTE_ADDR'] ?? '']
    );
    respond([
        'success' => true, 'id' => $editId, 'file_name' => $existingDoc['file_name'], 'file_path' => $existingDoc['file_path'],
        'file_size' => (int)$existingDoc['file_size'], 'mime_type' => $existingDoc['mime_type'],
    ]);
}

if (!$hasFile) {
    respond(['success' => false, 'error' => 'A file is required (field: document_file)'], 400);
}

$file = $_FILES['document_file'];
$allowedTypes = [
    'application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'text/plain', 'text/csv',
];

if ($file['size'] > MAX_UPLOAD_SIZE) {
    respond(['success' => false, 'error' => 'File exceeds the upload size limit'], 400);
}
if (!in_array($file['type'], $allowedTypes, true)) {
    respond(['success' => false, 'error' => 'File type not allowed. Allowed: PDF, Images, Word, Excel, Text'], 400);
}

$uploadDir = __DIR__ . '/uploads/documents/' . $userId . '/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$ext = pathinfo($file['name'], PATHINFO_EXTENSION);
$uniqueName = uniqid('doc_') . '_' . time() . '.' . $ext;
$destPath = $uploadDir . $uniqueName;

$fileUploaded = false;
$fileName = '';
$filePath = '';
$fileSize = 0;
$mimeType = '';

$gdriveResult = uploadToGoogleDrive($file, $userId);
if ($gdriveResult['success']) {
    $fileUploaded = true;
    $fileName = $gdriveResult['fileName'];
    $filePath = 'gdrive:' . $gdriveResult['googleDriveFileId'];
    $fileSize = $gdriveResult['fileSize'];
    $mimeType = $gdriveResult['mimeType'];
} elseif (!empty($gdriveResult['useFallback'])) {
    if (move_uploaded_file($file['tmp_name'], $destPath)) {
        $fileUploaded = true;
        $fileName = $file['name'];
        $filePath = 'uploads/documents/' . $userId . '/' . $uniqueName;
        $fileSize = $file['size'];
        $mimeType = $file['type'];
    }
} else {
    respond(['success' => false, 'error' => $gdriveResult['error'] ?? 'Upload failed'], 502);
}

if (!$fileUploaded) {
    respond(['success' => false, 'error' => 'Upload failed'], 500);
}

if ($existingDoc) {
    $db->update(
        "UPDATE documents SET document_name = ?, document_type = ?, file_name = ?, file_path = ?, file_size = ?, mime_type = ?,
                description = ?, tags = ?, expiry_date = ? WHERE id = ? AND user_id = ?",
        [$documentName, $documentType, $fileName, $filePath, $fileSize, $mimeType, $description, $tags, $expiryDate, $editId, $userId]
    );
    deleteDocumentFile($existingDoc['file_path'] ?? '', $userId);
    $newId = $editId;
} else {
    $newId = $db->insert(
        "INSERT INTO documents (user_id, document_name, document_type, file_name, file_path, file_size, mime_type, description, tags, expiry_date)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [$userId, $documentName, $documentType, $fileName, $filePath, $fileSize, $mimeType, $description, $tags, $expiryDate]
    );
}

$db->insert(
    "INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
    [$userId, $existingDoc ? 'update' : 'create', ($existingDoc ? 'Replaced document file: ' : 'Uploaded document: ') . $documentName, $_SERVER['REMOTE_ADDR'] ?? '']
);

respond(['success' => true, 'id' => (int)$newId, 'file_name' => $fileName, 'file_path' => $filePath, 'file_size' => $fileSize, 'mime_type' => $mimeType]);
