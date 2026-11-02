# 📋 Google Drive Implementation Reference

## How to Integrate into documents.php

This guide shows how to modify the documents upload handler to use Google Drive.

## Original Upload Code (Local Storage)

```php
// Handle file upload
if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['document_file'];
    
    if ($file['size'] > MAX_UPLOAD_SIZE) {
        setFlash('error', 'File size exceeds 5MB limit');
    } else {
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $uniqueName = uniqid('doc_') . '_' . time() . '.' . $ext;
        $destPath = $uploadDir . $uniqueName;

        if (move_uploaded_file($file['tmp_name'], $destPath)) {
            $fileUploaded = true;
            $fileName = $file['name'];
            $filePath = 'uploads/documents/' . $user['id'] . '/' . $uniqueName;
            $fileSize = $file['size'];
            $mimeType = $file['type'];
        }
    }
}
```

## Updated Upload Code (Google Drive)

```php
// Load Google Drive helper
require_once __DIR__ . '/includes/google-drive-helper.php';

// Handle file upload
if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['document_file'];
    
    // Try Google Drive first
    $result = uploadToGoogleDrive($file, $user['id']);
    
    if ($result['success']) {
        // File uploaded to Google Drive successfully
        $fileUploaded = true;
        $fileName = $result['fileName'];
        $filePath = 'gdrive:' . $result['googleDriveFileId']; // Store Google Drive ID with prefix
        $fileSize = $result['fileSize'];
        $mimeType = $result['mimeType'];
    } else if ($result['useFallback'] ?? false) {
        // Google Drive unavailable, fall back to local storage
        setFlash('warning', 'Google Drive unavailable, saving locally: ' . $result['error']);
        
        // Use original local upload code here
        // ... (original code)
    } else {
        // Validation error
        setFlash('error', $result['error']);
    }
}
```

## Database Schema Update

The `documents` table now stores Google Drive file IDs with a prefix:

```sql
-- file_path column stores:
-- Local file: "uploads/documents/1/doc_15825636_1629.pdf"
-- Google Drive: "gdrive:1A5vdBs_kQc7xYz9pL2m_nH8rQ9vW6sT0"
-- Easy to identify which storage system was used!
```

## Download Link Implementation

In your template, update download links:

**Before:**
```php
<a href="<?php echo url('uploads/documents/' . $user['id'] . '/' . urlencode($doc['file_name'])); ?>" download>
    Download
</a>
```

**After:**
```php
<?php
if (isGoogleDriveFile($doc)) {
    $downloadUrl = getGoogleDriveDownloadUrl(getGoogleDriveIdFromPath($doc['file_path']));
} else {
    $downloadUrl = url('uploads/documents/' . $user['id'] . '/' . urlencode($doc['file_name']));
}
?>
<a href="<?php echo $downloadUrl; ?>" download="<?php echo basename($doc['document_name']); ?>">
    Download
</a>
```

**Or use the built-in handler:**
```php
<a href="<?php echo url('download-file.php?file_id=' . $doc['id']); ?>" download>
    Download
</a>
```

## Delete Handler Update

**Before:**
```php
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    
    // Delete local file
    $doc = $db->fetch("SELECT file_path FROM documents WHERE id = ? AND user_id = ?", [$id, $user['id']]);
    if ($doc && file_exists(__DIR__ . '/' . $doc['file_path'])) {
        unlink(__DIR__ . '/' . $doc['file_path']);
    }
    
    $db->delete("DELETE FROM documents WHERE id = ? AND user_id = ?", [$id, $user['id']]);
    setFlash('success', 'Document deleted');
    redirect('/documents.php');
}
```

**After:**
```php
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    
    $doc = $db->fetch("SELECT file_path FROM documents WHERE id = ? AND user_id = ?", [$id, $user['id']]);
    
    if ($doc) {
        // Handle Google Drive files
        if (isGoogleDriveFile($doc)) {
            $googleDriveId = getGoogleDriveIdFromPath($doc['file_path']);
            deleteFromGoogleDrive($googleDriveId);
        } else {
            // Handle local files
            if (file_exists(__DIR__ . '/' . $doc['file_path'])) {
                unlink(__DIR__ . '/' . $doc['file_path']);
            }
        }
    }
    
    $db->delete("DELETE FROM documents WHERE id = ? AND user_id = ?", [$id, $user['id']]);
    setFlash('success', 'Document deleted');
    redirect('/documents.php');
}
```

## Complete Implementation Checklist

- [ ] Run `setup-google-drive.php`
- [ ] Get Google credentials
- [ ] Authorize with Google
- [ ] Add `require_once` for google-drive-helper.php
- [ ] Update upload handler with Google Drive code
- [ ] Update delete handler with Google Drive delete code
- [ ] Update download links to use `download-file.php`
- [ ] Test file upload
- [ ] Verify file appears in Google Drive
- [ ] Test file download
- [ ] Test file deletion
- [ ] Verify deleted file removed from Google Drive

## Helper Functions Reference

### Check if Google Drive is configured
```php
$configFile = __DIR__ . '/config/google-drive-config.php';
$isConfigured = file_exists($configFile);
```

### Upload file to Google Drive
```php
require_once __DIR__ . '/includes/google-drive-helper.php';

$result = uploadToGoogleDrive($_FILES['document_file'], $user['id']);

if ($result['success']) {
    echo "File ID: " . $result['googleDriveFileId'];
    echo "Download URL: " . $result['downloadUrl'];
} else {
    echo "Error: " . $result['error'];
}
```

### Check if file is on Google Drive
```php
if (isGoogleDriveFile($doc)) {
    echo "This file is on Google Drive";
} else {
    echo "This file is stored locally";
}
```

### Get Google Drive ID from storage path
```php
$googleDriveId = getGoogleDriveIdFromPath($doc['file_path']);
// If file_path = "gdrive:1A5vdBs_kQc7xYz9pL2m_nH8rQ9vW6sT0"
// Returns: "1A5vdBs_kQc7xYz9pL2m_nH8rQ9vW6sT0"
```

### Get download URL
```php
$url = getGoogleDriveDownloadUrl($googleDriveId);
// Returns direct Google Drive download link
```

### Delete from Google Drive
```php
deleteFromGoogleDrive($googleDriveId);
// Removes file from Google Drive
```

### Stream file directly
```php
streamFromGoogleDrive($googleDriveId, 'document.pdf');
// User sees download dialog for file
```

## Testing Checklist

After implementation, test these scenarios:

### Upload Test
```
1. Go to Documents page
2. Click "Add Document"
3. Select a PDF file
4. Click "Upload"
5. ✓ Success message appears
6. ✓ File appears in Google Drive folder
7. ✓ Database shows "gdrive:..." in file_path
```

### Download Test
```
1. Click download on uploaded document
2. ✓ File downloads to computer
3. ✓ File is readable (open it)
```

### Delete Test
```
1. Click delete on document
2. ✓ Confirm deletion
3. ✓ Document removed from list
4. ✓ File removed from Google Drive folder
5. ✓ Database record deleted
```

### Edit Test
```
1. Edit a document (change metadata)
2. Upload a new file with edit
3. ✓ Old file deleted from Google Drive
4. ✓ New file uploaded to Google Drive
5. ✓ Database updated correctly
```

## Error Handling

The system automatically handles these errors:

1. **Google Drive not configured**
   - Shows message: "Please run setup-google-drive.php"
   - Continues with local storage fallback

2. **Authorization expired**
   - Shows message: "Please re-authorize with Google"
   - Redirects to setup-google-drive.php

3. **Network error**
   - Shows message: "Connection error, please try again"
   - User can retry

4. **File too large**
   - Shows message: "File exceeds size limit"
   - Suggests upgrading

5. **Unsupported file type**
   - Shows message: "File type not allowed"
   - Lists allowed types

## Performance Optimization

### For Large Files
```php
// Set appropriate timeout for large files
set_time_limit(300); // 5 minutes
```

### For Many Users
```php
// Consider rate limiting uploads
$recentUploads = $db->fetch(
    "SELECT COUNT(*) as count FROM documents 
     WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)",
    [$user['id']]
);

if ($recentUploads['count'] > 5) {
    setFlash('error', 'Too many uploads. Please wait a minute.');
}
```

## Fallback to Local Storage

If Google Drive is not available:

```php
if (!$result['success'] && ($result['useFallback'] ?? false)) {
    // Use local storage as fallback
    // System remains functional
    // User sees warning about Google Drive
}
```

This ensures your app never completely breaks if Google Drive is temporarily unavailable.

---

## Installation Summary

1. **Files Created:**
   - `includes/google-drive.php` - Main Google Drive class
   - `includes/google-drive-helper.php` - Helper functions
   - `setup-google-drive.php` - OAuth setup wizard
   - `download-file.php` - File download handler
   - `GOOGLE_DRIVE_SETUP.md` - User documentation

2. **To Enable:**
   - Visit `setup-google-drive.php`
   - Complete OAuth flow
   - Start uploading files to Google Drive

3. **Configuration:**
   - All credentials stored in `config/google-drive-config.php`
   - Tokens stored in `config/.google-drive-tokens`
   - Auto-renewed when expired

---

**Ready to use Google Drive!** 🚀
