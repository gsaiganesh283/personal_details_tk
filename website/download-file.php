<?php
/**
 * Google Drive Download Handler
 * Streams files directly from Google Drive
 * 
 * Usage: download-file.php?file_id=DOCUMENT_ID
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/google-drive-helper.php';

// Require authentication
$user = requireAuth();

// Get file ID
$fileId = (int)($_GET['file_id'] ?? 0);

if (!$fileId) {
    http_response_code(400);
    die("Invalid file ID");
}

// Get document from database
$db = Database::getInstance();
$doc = $db->fetch(
    "SELECT * FROM documents WHERE id = ? AND user_id = ?",
    [$fileId, $user['id']]
);

if (!$doc) {
    http_response_code(404);
    die("Document not found");
}

// Log download activity
$db->insert(
    "INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
    [$user['id'], 'download', 'Downloaded: ' . $doc['document_name'], $_SERVER['REMOTE_ADDR'] ?? '']
);

// Check if file is on Google Drive
if (isGoogleDriveFile($doc)) {
    // Stream from Google Drive
    $googleDriveId = getGoogleDriveIdFromPath($doc['file_path']);
    
    if (!$googleDriveId) {
        http_response_code(400);
        die("Invalid Google Drive file ID");
    }
    
    streamFromGoogleDrive($googleDriveId, $doc['document_name']);
} else {
    // Stream from local storage (fallback for old files)
    $filePath = __DIR__ . '/' . $doc['file_path'];
    
    if (!file_exists($filePath)) {
        http_response_code(404);
        die("File not found");
    }
    
    header('Content-Disposition: attachment; filename="' . basename($doc['document_name']) . '"');
    header('Content-Type: ' . $doc['mime_type']);
    header('Content-Length: ' . filesize($filePath));
    
    readfile($filePath);
}
?>
