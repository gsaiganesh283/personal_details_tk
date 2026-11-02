<?php
/**
 * Document download for the native Android app -- same shape as
 * documents.php's own `?download=` handler and download-file.php, just with
 * Bearer-token auth (extractBearerToken() accepts a ?token= fallback too,
 * since handing this URL to an external viewer Intent can't attach headers)
 * instead of the website's session cookie.
 *
 * Usage: mobile-file-download.php?id=<document id>[&token=...]
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/mobile-auth-helpers.php';
require_once __DIR__ . '/includes/google-drive-helper.php';

$db = Database::getInstance();
ensureApiTokensTable($db);

$bearer = extractBearerToken();
$userId = $bearer ? resolveApiToken($db, $bearer) : null;
if ($userId === null) {
    http_response_code(401);
    die('Unauthorized');
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    die('Invalid document id');
}

$doc = $db->fetch("SELECT * FROM documents WHERE id = ? AND user_id = ?", [$id, $userId]);
if (!$doc) {
    http_response_code(404);
    die('Document not found');
}

if (isGoogleDriveFile($doc)) {
    $gdriveId = getGoogleDriveIdFromPath($doc['file_path']);
    if (!$gdriveId) {
        http_response_code(400);
        die('Invalid Google Drive file reference');
    }
    streamFromGoogleDrive($gdriveId, $doc['file_name']);
    exit;
}

$filePath = __DIR__ . '/' . $doc['file_path'];
if (!file_exists($filePath)) {
    http_response_code(404);
    die('File not found');
}

header('Content-Type: ' . ($doc['mime_type'] ?: 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . basename($doc['file_name']) . '"');
header('Content-Length: ' . filesize($filePath));
readfile($filePath);
