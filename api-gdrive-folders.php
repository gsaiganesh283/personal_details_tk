<?php
/**
 * API endpoint for Google Drive folder operations.
 * Used by the folder picker UI in profile.php
 * 
 * GET  ?action=list&parent=FOLDER_ID   — List subfolders
 * GET  ?action=info&id=FOLDER_ID       — Get folder name
 * POST ?action=create&parent=ID&name=X — Create new folder
 */
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Require authentication
$auth = new Auth();
$user = $auth->getCurrentUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Require Google Drive to be connected
$tokenFile = __DIR__ . '/config/.google-drive-tokens';
if (!file_exists($tokenFile)) {
    http_response_code(400);
    echo json_encode(['error' => 'Google Drive not connected']);
    exit;
}

require_once __DIR__ . '/includes/google-drive.php';

try {
    $gdrive = new GoogleDriveIntegration();
    
    if (!$gdrive->isConfigured()) {
        http_response_code(400);
        echo json_encode(['error' => 'Google Drive not configured']);
        exit;
    }
    
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    switch ($action) {
        case 'list':
            $parentId = $_GET['parent'] ?? 'root';
            $folders = $gdrive->listFolders($parentId);
            
            // Get parent folder name for breadcrumb
            $parentName = ($parentId === 'root') ? 'My Drive' : $gdrive->getFolderName($parentId);
            
            echo json_encode([
                'success' => true,
                'parent' => [
                    'id' => $parentId,
                    'name' => $parentName
                ],
                'folders' => $folders,
                'currentFolder' => $gdrive->getCurrentFolderId()
            ]);
            break;
            
        case 'info':
            $folderId = $_GET['id'] ?? 'root';
            $name = $gdrive->getFolderName($folderId);
            echo json_encode([
                'success' => true,
                'id' => $folderId,
                'name' => $name
            ]);
            break;
            
        case 'create':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'POST required']);
                exit;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $folderName = trim($input['name'] ?? $_POST['name'] ?? '');
            $parentId = $input['parent'] ?? $_POST['parent'] ?? 'root';
            
            if (empty($folderName)) {
                http_response_code(400);
                echo json_encode(['error' => 'Folder name is required']);
                exit;
            }
            
            $newFolderId = $gdrive->createFolder($folderName, $parentId);
            
            if ($newFolderId) {
                echo json_encode([
                    'success' => true,
                    'folder' => [
                        'id' => $newFolderId,
                        'name' => $folderName
                    ]
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to create folder']);
            }
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action. Use: list, info, create']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
