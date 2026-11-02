<?php
/**
 * Google Drive Helper Functions
 * Provides wrapper functions for documents.php to use Google Drive
 */

/**
 * Handle file upload to Google Drive
 * Returns array with success status and file metadata
 */
function uploadToGoogleDrive($file, $userId) {
    // Check if Google Drive is configured
    $configFile = __DIR__ . '/../config/google-drive-config.php';
    
    if (!file_exists($configFile)) {
        return [
            'success' => false,
            'error' => 'Google Drive not configured. Please run setup-google-drive.php',
            'useFallback' => true // Fall back to local storage
        ];
    }
    
    try {
        require_once __DIR__ . '/google-drive.php';
        $gdrive = new GoogleDriveIntegration();
        
        if (!$gdrive->isConfigured()) {
            return [
                'success' => false,
                'error' => 'Google Drive not authorized. Please run setup-google-drive.php',
                'useFallback' => true
            ];
        }
        
        // Prepare file data
        $allowedTypes = [
            'application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'audio/mpeg', 'audio/wav', 'video/mp4', 'video/quicktime',
            'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain', 'text/csv'
        ];
        
        // Validate file
        if ($file['size'] > MAX_UPLOAD_SIZE) {
            return [
                'success' => false,
                'error' => 'File size exceeds ' . (MAX_UPLOAD_SIZE / 1024 / 1024) . 'MB limit'
            ];
        }
        
        if (!in_array($file['type'], $allowedTypes)) {
            return [
                'success' => false,
                'error' => 'File type not allowed'
            ];
        }
        
        // Upload to Google Drive
        $googleDriveFileId = $gdrive->uploadFile(
            $file['tmp_name'],
            $file['name'],
            $file['type']
        );
        
        if ($googleDriveFileId) {
            return [
                'success' => true,
                'fileName' => $file['name'],
                'fileSize' => $file['size'],
                'mimeType' => $file['type'],
                'googleDriveFileId' => $googleDriveFileId,
                'downloadUrl' => $gdrive->getDownloadUrl($googleDriveFileId)
            ];
        }
        
        return [
            'success' => false,
            'error' => 'Failed to upload to Google Drive',
            'useFallback' => true
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => 'Google Drive Error: ' . $e->getMessage(),
            'useFallback' => true // Fall back to local storage
        ];
    }
}

/**
 * Delete file from Google Drive
 */
function deleteFromGoogleDrive($googleDriveFileId) {
    try {
        require_once __DIR__ . '/google-drive.php';
        $gdrive = new GoogleDriveIntegration();
        return $gdrive->deleteFile($googleDriveFileId);
    } catch (Exception $e) {
        error_log("Failed to delete Google Drive file: " . $e->getMessage());
        return false;
    }
}

/**
 * Get download URL for file
 */
function getGoogleDriveDownloadUrl($googleDriveFileId) {
    try {
        require_once __DIR__ . '/google-drive.php';
        $gdrive = new GoogleDriveIntegration();
        return $gdrive->getDownloadUrl($googleDriveFileId);
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Stream file from Google Drive
 */
function streamFromGoogleDrive($googleDriveFileId, $fileName = null, $inline = false) {
    try {
        require_once __DIR__ . '/google-drive.php';
        $gdrive = new GoogleDriveIntegration();
        $gdrive->streamFile($googleDriveFileId, $fileName, $inline);
    } catch (Exception $e) {
        http_response_code(500);
        echo "Error: " . htmlspecialchars($e->getMessage());
    }
}

/**
 * Check if file is stored on Google Drive (vs local storage)
 */
function isGoogleDriveFile($doc) {
    // If file_path starts with 'gdrive:' it's a Google Drive file
    return isset($doc['file_path']) && strpos($doc['file_path'], 'gdrive:') === 0;
}

/**
 * Extract Google Drive ID from stored path
 */
function getGoogleDriveIdFromPath($filePath) {
    if (strpos($filePath, 'gdrive:') === 0) {
        return substr($filePath, 7); // Remove 'gdrive:' prefix
    }
    return null;
}
