<?php
/**
 * Custom Field File Download Handler
 * Streams files from Google Drive or serves local files for custom field uploads
 * 
 * Usage: download-custom-file.php?gdrive=FILE_ID&name=FILENAME
 *   or:  download-custom-file.php?local=RELATIVE_PATH
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mobile-auth-helpers.php';

// Accept the mobile app's Bearer/?token= auth first (same scheme
// mobile-file-download.php already uses), falling back to the website's
// session-cookie auth for browser use. Without this, every request from the
// mobile app hit requireAuth()'s session-only check and got redirected to
// login / 401'd instead of streaming the file.
$authDb = Database::getInstance();
ensureApiTokensTable($authDb);
$bearer = extractBearerToken();
$mobileUserId = $bearer ? resolveApiToken($authDb, $bearer) : null;
$user = $mobileUserId !== null ? ['id' => $mobileUserId] : requireAuth();

// Inline preview mode (for in-page viewer)
$inlinePreview = isset($_GET['preview']) && $_GET['preview'] === '1';

// Google Drive file
if (!empty($_GET['gdrive'])) {
    $googleDriveId = $_GET['gdrive'];
    $fileName = $_GET['name'] ?? 'download';
    
    // Sanitize filename
    $fileName = basename($fileName);
    
    // Verify this file belongs to the user by checking custom_field_entries
    $db = Database::getInstance();
    $searchPattern = 'gdrive:' . $googleDriveId . '%';
    $entry = $db->fetch(
        "SELECT cfe.id FROM custom_field_entries cfe
         JOIN custom_fields cf ON cf.id = cfe.custom_field_id
         WHERE cfe.field_value LIKE ? AND cf.user_id = ?
         LIMIT 1",
        [$searchPattern, $user['id']]
    );
    
    if (!$entry) {
        http_response_code(403);
        die("Access denied");
    }
    
    // Log download (skip for preview/inline requests to reduce latency)
    if (!$inlinePreview) {
        $db->insert(
            "INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
            [$user['id'], 'download', 'Downloaded custom field file: ' . $fileName, $_SERVER['REMOTE_ADDR'] ?? '']
        );
    }
    
    // Stream from Google Drive
    require_once __DIR__ . '/includes/google-drive-helper.php';
    streamFromGoogleDrive($googleDriveId, $fileName, $inlinePreview);
    exit;
}

// Local file (fallback)
if (!empty($_GET['local'])) {
    $relativePath = $_GET['local'];
    
    // Security: ensure path is within uploads/custom_fields/
    if (strpos($relativePath, 'uploads/custom_fields/') !== 0) {
        http_response_code(403);
        die("Access denied");
    }
    
    // Prevent directory traversal
    if (strpos($relativePath, '..') !== false) {
        http_response_code(403);
        die("Access denied");
    }
    
    $filePath = __DIR__ . '/' . $relativePath;
    
    if (!file_exists($filePath)) {
        http_response_code(404);
        die("File not found");
    }
    
    // Verify this file belongs to the user
    $db = Database::getInstance();
    $entry = $db->fetch(
        "SELECT cfe.id FROM custom_field_entries cfe
         JOIN custom_fields cf ON cf.id = cfe.custom_field_id
         WHERE cfe.field_value = ? AND cf.user_id = ?
         LIMIT 1",
        [$relativePath, $user['id']]
    );
    
    if (!$entry) {
        http_response_code(403);
        die("Access denied");
    }
    
    $fileName = basename($filePath);
    $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
    $fileSize = filesize($filePath);
    
    // Log download (skip for preview to reduce latency)
    if (!$inlinePreview) {
        $db->insert(
            "INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
            [$user['id'], 'download', 'Downloaded custom field file: ' . $fileName, $_SERVER['REMOTE_ADDR'] ?? '']
        );
    }
    
    // ETag caching - skip re-sending if unchanged
    $etag = '"' . md5($relativePath . $fileSize . filemtime($filePath)) . '"';
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        http_response_code(304);
        exit;
    }
    
    // Clean output buffers
    while (ob_get_level()) ob_end_clean();
    
    header('Content-Disposition: ' . ($inlinePreview ? 'inline' : 'attachment') . '; filename="' . $fileName . '"');
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . $fileSize);
    header('ETag: ' . $etag);
    if ($inlinePreview) {
        header('Cache-Control: private, max-age=86400, immutable');
    }
    readfile($filePath);
    exit;
}

http_response_code(400);
die("Missing file parameter");
