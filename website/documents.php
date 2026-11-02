<?php
// Handle binary file serving (preview/download) BEFORE any HTML output
// These must run before header.php to avoid HTML contaminating the file stream
if (isset($_GET['preview']) || isset($_GET['download'])) {
    // Minimal bootstrap - no HTML output
    if (session_status() === PHP_SESSION_NONE) session_start();
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/includes/functions.php';
    require_once __DIR__ . '/includes/auth.php';
    require_once __DIR__ . '/includes/google-drive-helper.php';

    $user = requireAuth();
    $db = Database::getInstance();

    if (isset($_GET['preview']) && is_numeric($_GET['preview'])) {
        $id = (int)$_GET['preview'];
        $doc = $db->fetch("SELECT * FROM documents WHERE id = ? AND user_id = ?", [$id, $user['id']]);
        if ($doc) {
            $mime = $doc['mime_type'] ?: 'application/octet-stream';
            if (isGoogleDriveFile($doc)) {
                $gdriveId = getGoogleDriveIdFromPath($doc['file_path']);
                if ($gdriveId) {
                    streamFromGoogleDrive($gdriveId, $doc['file_name'], true);
                    exit;
                }
            } else {
                $filePath = __DIR__ . '/' . $doc['file_path'];
                if (file_exists($filePath)) {
                    header('Content-Type: ' . $mime);
                    header('Content-Disposition: inline; filename="' . basename($doc['file_name']) . '"');
                    header('Content-Length: ' . filesize($filePath));
                    header('Cache-Control: private, max-age=3600');
                    readfile($filePath);
                    exit;
                }
            }
        }
        http_response_code(404);
        echo 'File not found';
        exit;
    }

    if (isset($_GET['download']) && is_numeric($_GET['download'])) {
        $id = (int)$_GET['download'];
        $doc = $db->fetch("SELECT * FROM documents WHERE id = ? AND user_id = ?", [$id, $user['id']]);
        if ($doc) {
            if (isGoogleDriveFile($doc)) {
                $gdriveId = getGoogleDriveIdFromPath($doc['file_path']);
                if ($gdriveId) {
                    streamFromGoogleDrive($gdriveId, $doc['file_name']);
                    exit;
                }
            } else {
                $filePath = __DIR__ . '/' . $doc['file_path'];
                if (file_exists($filePath)) {
                    header('Content-Type: ' . ($doc['mime_type'] ?: 'application/octet-stream'));
                    header('Content-Disposition: attachment; filename="' . basename($doc['file_name']) . '"');
                    header('Content-Length: ' . filesize($filePath));
                    readfile($filePath);
                    exit;
                }
            }
        }
        setFlash('error', 'File not found');
        redirect('/documents.php');
    }
}

$pageTitle = 'Documents';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/custom_fields_helper.php';
require_once __DIR__ . '/includes/google-drive-helper.php';
require_once __DIR__ . '/includes/notifbridge_client.php';
require_once __DIR__ . '/includes/notifications_helper.php';
require_once __DIR__ . '/includes/action_buttons_helper.php';
$user = requireAuth();
$db = Database::getInstance();
ensureNotificationsSchema();
ensureDocumentExpiryRemindersSchema();

// Ensure upload directory exists
$uploadDir = __DIR__ . '/uploads/documents/' . $user['id'] . '/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Handle delete
if (isset($_GET['delete']) && is_numeric($_GET['delete']) && Auth::validateCSRF($_GET['csrf'] ?? '')) {
    $id = (int)$_GET['delete'];
    $doc = $db->fetch("SELECT * FROM documents WHERE id = ? AND user_id = ?", [$id, $user['id']]);
    if ($doc) {
        if (isGoogleDriveFile($doc)) {
            // Delete from Google Drive
            $gdriveId = getGoogleDriveIdFromPath($doc['file_path']);
            if ($gdriveId) {
                deleteFromGoogleDrive($gdriveId);
            }
        } else {
            // Delete local file
            $filePath = __DIR__ . '/' . $doc['file_path'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
        $db->delete("DELETE FROM documents WHERE id = ? AND user_id = ?", [$id, $user['id']]);
        if (function_exists('cancelDocumentExpiryReminders')) {
            cancelDocumentExpiryReminders($user['id'], $id); // the document is gone, so are its expiry alerts
        }
        // Also clean up any custom field files associated with this document
        purgeActionButtonChildrenForRecord('documents', $id, $user['id']);
        deleteCustomFieldFilesForRecord('documents', $id);
        $db->insert("INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
            [$user['id'], 'delete', 'Deleted document: ' . $doc['document_name'], $_SERVER['REMOTE_ADDR'] ?? '']);
        setFlash('success', 'Document deleted successfully');
    }
    redirect('/documents.php');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $editId = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : null;
    $data = [
        'document_name' => sanitize($_POST['document_name'] ?? ''),
        'document_type' => $_POST['document_type'] ?? 'other',
        'description'   => sanitize($_POST['description'] ?? ''),
        'tags'          => sanitize($_POST['tags'] ?? ''),
        'linked_to'     => sanitize($_POST['linked_to'] ?? ''),
        'expiry_date'   => !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null,
    ];

    if (empty($data['document_name'])) {
        setFlash('error', 'Document name is required');
    } elseif ($data['expiry_date'] !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['expiry_date']) !== 1) {
        setFlash('error', 'Expiry date must be a valid date.');
    } else {
        $fileUploaded = false;
        $fileName = '';
        $filePath = '';
        $fileSize = 0;
        $mimeType = '';

        // Handle file upload
        if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['document_file'];
            $allowedTypes = [
                'application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'text/plain', 'text/csv'
            ];

            if ($file['size'] > MAX_UPLOAD_SIZE) {
                setFlash('error', 'File size exceeds 5MB limit');
            } elseif (!in_array($file['type'], $allowedTypes)) {
                setFlash('error', 'File type not allowed. Allowed: PDF, Images, Word, Excel, Text');
            } else {
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $uniqueName = uniqid('doc_') . '_' . time() . '.' . $ext;
                $destPath = $uploadDir . $uniqueName;

                // Try Google Drive first, fall back to local
                $gdriveResult = uploadToGoogleDrive($file, $user['id']);
                
                if ($gdriveResult['success']) {
                    // Uploaded to Google Drive
                    $fileUploaded = true;
                    $fileName = $gdriveResult['fileName'];
                    $filePath = 'gdrive:' . $gdriveResult['googleDriveFileId'];
                    $fileSize = $gdriveResult['fileSize'];
                    $mimeType = $gdriveResult['mimeType'];
                } elseif (!empty($gdriveResult['useFallback'])) {
                    // Fall back to local storage
                    if (move_uploaded_file($file['tmp_name'], $destPath)) {
                        $fileUploaded = true;
                        $fileName = $file['name'];
                        $filePath = 'uploads/documents/' . $user['id'] . '/' . $uniqueName;
                        $fileSize = $file['size'];
                        $mimeType = $file['type'];
                    }
                } else {
                    // Upload error (not fallback-able)
                    setFlash('error', $gdriveResult['error'] ?? 'Upload failed');
                }
            }
        }

        if ($editId) {
            $updateSql = "UPDATE documents SET document_name=?, document_type=?, description=?, tags=?, linked_to=?, expiry_date=?";
            $updateParams = [$data['document_name'], $data['document_type'], $data['description'], $data['tags'], $data['linked_to'], $data['expiry_date']];
            
            if ($fileUploaded) {
                // Delete old file
                $oldDoc = $db->fetch("SELECT file_path FROM documents WHERE id = ? AND user_id = ?", [$editId, $user['id']]);
                if ($oldDoc) {
                    if (isGoogleDriveFile($oldDoc)) {
                        $oldGdriveId = getGoogleDriveIdFromPath($oldDoc['file_path']);
                        if ($oldGdriveId) deleteFromGoogleDrive($oldGdriveId);
                    } elseif (file_exists(__DIR__ . '/' . $oldDoc['file_path'])) {
                        unlink(__DIR__ . '/' . $oldDoc['file_path']);
                    }
                }
                $updateSql .= ", file_name=?, file_path=?, file_size=?, mime_type=?";
                $updateParams = array_merge($updateParams, [$fileName, $filePath, $fileSize, $mimeType]);
            }
            $updateSql .= " WHERE id=? AND user_id=?";
            $updateParams[] = $editId;
            $updateParams[] = $user['id'];
            $db->update($updateSql, $updateParams);
            saveCustomFieldEntries($user['id'], 'documents', $editId);
            $db->insert("INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
                [$user['id'], 'update', 'Updated document: ' . $data['document_name'], $_SERVER['REMOTE_ADDR'] ?? '']);
            $reminderMessage = syncDocumentExpiryReminders($user['id'], $editId, null);
            setFlash('success', 'Document updated successfully');
            if ($reminderMessage) setFlash('error', $reminderMessage);
        } else {
            if (!$fileUploaded) {
                setFlash('error', 'Please upload a file');
            } else {
                $newId = $db->insert(
                    "INSERT INTO documents (user_id, document_name, document_type, file_name, file_path, file_size, mime_type, description, tags, linked_to, expiry_date)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$user['id'], $data['document_name'], $data['document_type'], $fileName, $filePath, $fileSize, $mimeType,
                     $data['description'], $data['tags'], $data['linked_to'], $data['expiry_date']]
                );
                saveCustomFieldEntries($user['id'], 'documents', $newId);
                $db->insert("INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
                    [$user['id'], 'create', 'Uploaded document: ' . $data['document_name'], $_SERVER['REMOTE_ADDR'] ?? '']);
                $reminderMessage = syncDocumentExpiryReminders($user['id'], $newId, null);
                setFlash('success', 'Document uploaded successfully');
                if ($reminderMessage) setFlash('error', $reminderMessage);
            }
        }
        if (!isset($_SESSION['flash']['error'])) {
            redirect('/documents.php');
        }
    }
}

// Get data
$search = sanitize($_GET['search'] ?? '');
$filterType = sanitize($_GET['type'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;
$offset = ($page - 1) * $perPage;

$where = "WHERE user_id = ?";
$params = [$user['id']];

if ($search) {
    $where .= " AND (document_name LIKE ? OR description LIKE ? OR tags LIKE ?)";
    $searchLike = "%$search%";
    $params = array_merge($params, [$searchLike, $searchLike, $searchLike]);
}
if ($filterType) {
    $where .= " AND document_type = ?";
    $params[] = $filterType;
}

$totalCount = $db->fetch("SELECT COUNT(*) as count FROM documents $where", $params);
$totalPages = ceil($totalCount['count'] / $perPage);
$documents = $db->fetchAll("SELECT * FROM documents $where ORDER BY updated_at DESC LIMIT $perPage OFFSET $offset", $params);

// Check if editing
$editData = null;
$editCustomValues = [];
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $editData = $db->fetch("SELECT * FROM documents WHERE id = ? AND user_id = ?", [(int)$_GET['edit'], $user['id']]);
    if ($editData) {
        $editCustomValues = getCustomFieldValues('documents', $editData['id']);
    }
}
$showForm = (isset($_GET['action']) && $_GET['action'] === 'add') || $editData;
$customFields = getCustomFieldsFor('documents', $user['id']);

// Get type counts for filter
$typeCounts = $db->fetchAll("SELECT document_type, COUNT(*) as cnt FROM documents WHERE user_id = ? GROUP BY document_type ORDER BY cnt DESC", [$user['id']]);

function formatFileSize($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

function getDocIcon($type) {
    $icons = [
        'pdf' => 'fa-file-pdf', 'image' => 'fa-file-image', 'certificate' => 'fa-certificate',
        'id_proof' => 'fa-id-card', 'address_proof' => 'fa-map-marker-alt', 'income_proof' => 'fa-money-bill',
        'education' => 'fa-graduation-cap', 'employment' => 'fa-briefcase', 'insurance' => 'fa-shield-halved',
        'legal' => 'fa-gavel', 'medical' => 'fa-heartbeat', 'other' => 'fa-file'
    ];
    return $icons[$type] ?? 'fa-file';
}

function getDocColor($type) {
    $colors = [
        'pdf' => '#e74c3c', 'image' => '#3498db', 'certificate' => '#f39c12',
        'id_proof' => '#9b59b6', 'address_proof' => '#1abc9c', 'income_proof' => '#2ecc71',
        'education' => '#e67e22', 'employment' => '#34495e', 'insurance' => '#16a085',
        'legal' => '#8e44ad', 'medical' => '#e74c3c', 'other' => '#95a5a6'
    ];
    return $colors[$type] ?? '#95a5a6';
}
?>

<!-- Toolbar -->
<div class="toolbar">
    <div class="toolbar-left">
        <?php if (!$showForm): ?>
        <div class="search-box" style="width: auto;">
            <i class="fas fa-search"></i>
            <form method="GET" style="display:inline;">
                <input type="text" name="search" placeholder="Search documents..." 
                       value="<?php echo htmlspecialchars($search); ?>" style="width: 220px;">
                <?php if ($filterType): ?><input type="hidden" name="type" value="<?php echo htmlspecialchars($filterType); ?>"><?php endif; ?>
            </form>
        </div>
        <?php if (!empty($typeCounts)): ?>
        <div style="display: flex; gap: 6px; flex-wrap: wrap; margin-left: 12px;">
            <a href="<?php echo url('documents.php'); ?>" class="badge <?php echo !$filterType ? 'badge-primary' : 'badge-secondary'; ?>" style="cursor: pointer; text-decoration: none;">All</a>
            <?php foreach ($typeCounts as $tc): ?>
            <a href="<?php echo url('documents.php?type=' . urlencode($tc['document_type'])); ?>" 
               class="badge <?php echo $filterType === $tc['document_type'] ? 'badge-primary' : 'badge-secondary'; ?>"
               style="cursor: pointer; text-decoration: none;">
                <?php echo ucfirst(str_replace('_', ' ', $tc['document_type'])); ?> (<?php echo $tc['cnt']; ?>)
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <a href="<?php echo url('documents.php'); ?>" class="btn btn-ghost">
            <i class="fas fa-arrow-left"></i> Back to List
        </a>
        <?php endif; ?>
    </div>
    <div class="toolbar-right">
        <?php if (!$showForm): ?>
        <a href="<?php echo url('documents.php?action=add'); ?>" class="btn btn-primary">
            <i class="fas fa-upload"></i> Upload Document
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($showForm): ?>
<!-- Upload/Edit Form -->
<div class="card animate-fade-in">
    <div class="card-header">
        <h2>
            <i class="fas fa-<?php echo $editData ? 'edit' : 'cloud-upload-alt'; ?>" style="margin-right: 8px; color: var(--primary);"></i>
            <?php echo $editData ? 'Edit Document' : 'Upload Document'; ?>
        </h2>
    </div>

    <form method="POST" action="<?php echo url('documents.php'); ?>" id="docForm" enctype="multipart/form-data">
<?php echo csrf_field(); ?>
        <?php if ($editData): ?>
            <input type="hidden" name="edit_id" value="<?php echo $editData['id']; ?>">
        <?php endif; ?>

        <div class="form-row">
            <div class="form-group">
                <label>Document Name <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="text" name="document_name" placeholder="e.g. Passport Copy" required
                           value="<?php echo htmlspecialchars($editData['document_name'] ?? ''); ?>">
                    <i class="fas fa-file-alt"></i>
                </div>
            </div>
            <div class="form-group">
                <label>Document Type</label>
                <div class="input-wrapper">
                    <select name="document_type">
                        <?php
                        $docTypes = ['pdf'=>'PDF', 'image'=>'Image', 'certificate'=>'Certificate', 'id_proof'=>'ID Proof',
                            'address_proof'=>'Address Proof', 'income_proof'=>'Income Proof', 'education'=>'Education',
                            'employment'=>'Employment', 'insurance'=>'Insurance', 'legal'=>'Legal', 'medical'=>'Medical', 'other'=>'Other'];
                        foreach ($docTypes as $val => $label):
                        ?>
                        <option value="<?php echo $val; ?>" <?php echo ($editData['document_type'] ?? 'other') === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <i class="fas fa-tag"></i>
                </div>
            </div>
        </div>

        <div class="form-group">
            <label><?php echo $editData ? 'Replace File (optional)' : 'Select File'; ?> <?php echo $editData ? '' : '<span class="required">*</span>'; ?></label>
            <div class="input-wrapper" style="position: relative;">
                <input type="file" name="document_file" id="documentFile" <?php echo $editData ? '' : 'required'; ?>
                       accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx,.xls,.xlsx,.txt,.csv"
                       style="padding: 12px; background: var(--bg-secondary); border: 2px dashed var(--border-color); border-radius: 12px; cursor: pointer;">
            </div>
            <small style="color: var(--text-muted); margin-top: 4px; display: block;">
                Max 5MB. Allowed: PDF, Images, Word, Excel, Text files
            </small>
            <?php if ($editData): ?>
            <small style="color: var(--text-secondary); margin-top: 4px; display: block;">
                <i class="fas fa-file"></i> Current: <?php echo htmlspecialchars($editData['file_name']); ?> 
                (<?php echo formatFileSize($editData['file_size']); ?>)
            </small>
            <?php endif; ?>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Tags</label>
                <div class="input-wrapper">
                    <input type="text" name="tags" placeholder="Comma separated: passport, travel, id"
                           value="<?php echo htmlspecialchars($editData['tags'] ?? ''); ?>">
                    <i class="fas fa-tags"></i>
                </div>
            </div>
            <div class="form-group">
                <label>Linked To</label>
                <div class="input-wrapper">
                    <select name="linked_to">
                        <option value="">-- None --</option>
                        <option value="aadhaar" <?php echo ($editData['linked_to'] ?? '') === 'aadhaar' ? 'selected' : ''; ?>>Aadhaar</option>
                        <option value="pan" <?php echo ($editData['linked_to'] ?? '') === 'pan' ? 'selected' : ''; ?>>PAN Card</option>
                        <option value="bank" <?php echo ($editData['linked_to'] ?? '') === 'bank' ? 'selected' : ''; ?>>Bank Account</option>
                        <option value="personal" <?php echo ($editData['linked_to'] ?? '') === 'personal' ? 'selected' : ''; ?>>Personal Details</option>
                    </select>
                    <i class="fas fa-link"></i>
                </div>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Expiry Date</label>
                <div class="input-wrapper">
                    <input type="date" name="expiry_date"
                           value="<?php echo htmlspecialchars($editData['expiry_date'] ?? ''); ?>">
                    <i class="fas fa-calendar-times"></i>
                </div>
            </div>
            <div class="form-group">
                <label>Description</label>
                <div class="input-wrapper">
                    <input type="text" name="description" placeholder="Brief description"
                           value="<?php echo htmlspecialchars($editData['description'] ?? ''); ?>">
                    <i class="fas fa-align-left"></i>
                </div>
            </div>
        </div>

        <?php renderCustomFields($customFields ?? [], $editCustomValues ?? [], 'Custom Fields', 'documents', $user['id']); ?>

        <div class="form-actions">
            <a href="<?php echo url('documents.php'); ?>" class="btn btn-ghost">Cancel</a>
            <button type="button" class="btn btn-preview" onclick="showFormPreview('docForm')">
                <i class="fas fa-eye"></i> Preview
            </button>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-<?php echo $editData ? 'save' : 'upload'; ?>"></i> <?php echo $editData ? 'Update Document' : 'Upload Document'; ?>
            </button>
        </div>
    </form>
</div>

<?php else: ?>
<!-- Documents List -->
<?php if (empty($documents)): ?>
    <div class="empty-state card">
        <i class="fas fa-folder-open"></i>
        <h3>No documents yet</h3>
        <p>Upload your documents, certificates, and important files</p>
        <a href="<?php echo url('documents.php?action=add'); ?>" class="btn btn-primary">
            <i class="fas fa-upload"></i> Upload Document
        </a>
    </div>
<?php else: ?>
    <div class="details-grid">
        <?php foreach ($documents as $doc): ?>
        <div class="detail-card animate-fade-in">
            <div class="card-menu">
                <div style="display: flex; gap: 4px;">
                    <a href="<?php echo url('documents.php?view=' . $doc['id']); ?>" class="btn btn-icon btn-ghost" title="View">
                        <i class="fas fa-eye"></i>
                    </a>
                    <a href="<?php echo url('documents.php?download=' . $doc['id']); ?>" class="btn btn-icon btn-ghost" title="Download">
                        <i class="fas fa-download"></i>
                    </a>
                    <a href="<?php echo url('documents.php?edit=' . $doc['id']); ?>" class="btn btn-icon btn-ghost" title="Edit">
                        <i class="fas fa-pen"></i>
                    </a>
                    <button class="btn btn-icon btn-ghost" title="Delete" style="color: var(--danger);"
                            onclick="showConfirm('Delete Document', 'Are you sure you want to delete this document? The file will be permanently removed.', function() { window.location.href='<?php echo url("documents.php?delete=" . $doc['id'] . "&csrf=" . urlencode(Auth::generateCSRF())); ?>'; })">
                        <i class="fas fa-trash"></i>
                    </button>
                    <?php renderActionButtonsInline('documents', $doc['id'], $user['id']); ?>
                </div>
            </div>

            <div class="detail-card-header">
                <div class="detail-avatar" style="background: <?php echo getDocColor($doc['document_type']); ?>">
                    <i class="fas <?php echo getDocIcon($doc['document_type']); ?>"></i>
                </div>
                <div>
                    <div class="detail-name"><?php echo htmlspecialchars($doc['document_name']); ?></div>
                    <div class="detail-email"><?php echo htmlspecialchars($doc['file_name']); ?></div>
                </div>
            </div>

            <div style="display: flex; gap: 6px; flex-wrap: wrap; margin: -8px 0 14px;">
                <span class="badge badge-info"><?php echo ucfirst(str_replace('_', ' ', $doc['document_type'])); ?></span>
                <?php if (isGoogleDriveFile($doc)): ?>
                <span class="badge badge-success" title="Stored on Google Drive"><i class="fab fa-google-drive"></i> Drive</span>
                <?php endif; ?>
                <?php if ($doc['expiry_date']):
                    $expiry = new DateTime($doc['expiry_date']);
                    $isExpired = $expiry < new DateTime();
                ?>
                <span class="badge badge-<?php echo $isExpired ? 'danger' : 'warning'; ?>">
                    <?php echo $expiry->format('d M Y'); ?><?php echo $isExpired ? ' (Expired)' : ''; ?>
                </span>
                <?php endif; ?>
            </div>

            <div class="detail-info">
                <div class="detail-info-item">
                    <span class="label">Size</span>
                    <span class="value"><?php echo formatFileSize($doc['file_size']); ?></span>
                </div>
                <div class="detail-info-item">
                    <span class="label">Linked To</span>
                    <span class="value"><?php echo $doc['linked_to'] ? ucfirst($doc['linked_to']) : '-'; ?></span>
                </div>
                <div class="detail-info-item">
                    <span class="label">Tags</span>
                    <span class="value"><?php echo $doc['tags'] ? htmlspecialchars($doc['tags']) : '-'; ?></span>
                </div>
                <div class="detail-info-item">
                    <span class="label">Updated</span>
                    <span class="value"><?php echo timeAgo($doc['updated_at']); ?></span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
        <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&type=<?php echo urlencode($filterType); ?>" class="pagination-btn">
            <i class="fas fa-chevron-left"></i>
        </a>
        <?php endif; ?>
        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
        <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&type=<?php echo urlencode($filterType); ?>"
           class="pagination-btn <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
        <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&type=<?php echo urlencode($filterType); ?>" class="pagination-btn">
            <i class="fas fa-chevron-right"></i>
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>
<?php endif; ?>

<!-- View Detail Modal -->
<?php
if (isset($_GET['view']) && is_numeric($_GET['view'])) {
    $viewData = $db->fetch("SELECT * FROM documents WHERE id = ? AND user_id = ?", [(int)$_GET['view'], $user['id']]);
    if ($viewData) {
        try {
            $viewCustomValues = $db->fetchAll(
                "SELECT cf.field_label, cf.field_type, cfe.field_value FROM custom_field_entries cfe 
                 JOIN custom_fields cf ON cf.id = cfe.custom_field_id 
                 WHERE cfe.linked_to = 'documents' AND cfe.record_id = ? AND cfe.field_value != ''",
                [$viewData['id']]
            );
        } catch (Exception $e) {
            $viewCustomValues = [];
        }
        $fileFieldTypes = getFileFieldTypes();
?>
<?php
    $mime = $viewData['mime_type'] ?: '';
    $isPdf = ($mime === 'application/pdf');
    $isImage = (strpos($mime, 'image/') === 0);
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $isMobileUa = preg_match('/Android|iPhone|iPad|iPod|Mobile|Opera Mini|IEMobile/i', $ua) === 1;
    $useInlinePdfPreview = $isPdf && !$isMobileUa;
    $isPreviewable = $useInlinePdfPreview || $isImage;
    $previewUrl = url('documents.php?preview=' . $viewData['id']);
?>
<div class="modal view-modal active" id="viewModal" style="max-width: <?php echo $isPreviewable ? '900px' : '720px'; ?>;">
    <div class="modal-header">
        <h3><i class="fas fa-eye" style="margin-right: 8px; color: var(--primary);"></i> View Document</h3>
        <div style="display: flex; align-items: center; gap: 8px;">
            <?php if ($isPreviewable): ?>
            <button type="button" class="btn btn-ghost btn-sm" id="toggleDocInfo" title="Toggle details">
                <i class="fas fa-info-circle"></i>
            </button>
            <?php endif; ?>
            <a href="<?php echo url('documents.php'); ?>" class="modal-close"><i class="fas fa-times"></i></a>
        </div>
    </div>
    <div class="modal-body" style="padding: 0; display: flex; flex-direction: column; max-height: calc(90vh - 140px); overflow: hidden;">
        <?php if ($useInlinePdfPreview): ?>
        <!-- PDF Viewer -->
        <div id="docPreviewPane" style="flex: 1; min-height: 0; background: #1a1a2e; position: relative;">
            <iframe src="<?php echo $previewUrl; ?>" 
                    style="width: 100%; height: 100%; min-height: 500px; border: none; display: block;"
                    title="PDF Preview"></iframe>
        </div>
        <?php elseif ($isPdf): ?>
        <!-- Mobile-friendly PDF fallback -->
        <div id="docPreviewPane" style="flex: 1; min-height: 0; display: flex; align-items: center; justify-content: center; background: #1a1a2e; padding: 20px; overflow: auto;">
            <div style="text-align:center; max-width: 460px;">
                <div class="detail-avatar" style="background: #e74c3c; width: 72px; height: 72px; font-size: 1.5rem; margin: 0 auto 14px;">
                    <i class="fas fa-file-pdf"></i>
                </div>
                <div style="font-size:0.95rem; color:var(--text-primary); font-weight:600; margin-bottom:6px; word-break:break-word;">
                    <?php echo htmlspecialchars($viewData['file_name'] ?: $viewData['document_name']); ?>
                </div>
                <p style="margin:0 0 14px; color:var(--text-muted); font-size:0.84rem;">
                    Inline PDF preview is limited on some mobile browsers. Open the file directly.
                </p>
                <div style="display:flex; gap:8px; justify-content:center; flex-wrap:wrap;">
                    <a href="<?php echo $previewUrl; ?>" target="_blank" class="btn btn-ghost btn-sm">
                        <i class="fas fa-external-link-alt"></i> Open PDF
                    </a>
                    <a href="<?php echo url('documents.php?download=' . $viewData['id']); ?>" class="btn btn-primary btn-sm">
                        <i class="fas fa-download"></i> Download File
                    </a>
                </div>
            </div>
        </div>
        <?php elseif ($isImage): ?>
        <!-- Image Viewer -->
        <div id="docPreviewPane" style="flex: 1; min-height: 0; display: flex; align-items: center; justify-content: center; background: #1a1a2e; padding: 20px; overflow: auto;">
            <img src="<?php echo $previewUrl; ?>" 
                 alt="<?php echo htmlspecialchars($viewData['document_name']); ?>"
                 style="max-width: 100%; max-height: 500px; border-radius: 8px; object-fit: contain; cursor: zoom-in;"
                 onclick="this.style.maxHeight = this.style.maxHeight === 'none' ? '500px' : 'none'; this.style.cursor = this.style.maxHeight === 'none' ? 'zoom-out' : 'zoom-in';">
        </div>
        <?php else: ?>
        <!-- Non-previewable: show icon + info -->
        <div style="padding: 28px;">
            <div style="text-align: center; margin-bottom: 24px;">
                <div class="detail-avatar" style="background: <?php echo getDocColor($viewData['document_type']); ?>; width: 64px; height: 64px; font-size: 1.4rem; margin: 0 auto 12px;">
                    <i class="fas <?php echo getDocIcon($viewData['document_type']); ?>"></i>
                </div>
                <h2><?php echo htmlspecialchars($viewData['document_name']); ?></h2>
                <p style="color: var(--text-secondary);"><?php echo ucfirst(str_replace('_', ' ', $viewData['document_type'])); ?></p>
            </div>
        <?php endif; ?>

        <!-- Document Info Panel -->
        <div id="docInfoPanel" style="padding: 16px 28px; overflow-y: auto; <?php echo $isPreviewable ? 'display:none;' : ''; ?>">
            <div style="<?php echo $isPreviewable ? '' : 'margin-top: 0;'; ?>">
                <?php if ($isPreviewable): ?>
                <div style="text-align: center; margin-bottom: 16px;">
                    <h3 style="margin: 0;"><?php echo htmlspecialchars($viewData['document_name']); ?></h3>
                    <p style="color: var(--text-secondary); margin: 4px 0 0; font-size: 0.9rem;"><?php echo ucfirst(str_replace('_', ' ', $viewData['document_type'])); ?></p>
                </div>
                <?php endif; ?>
                <div class="preview-section">
                    <div class="preview-section-title"><i class="fas fa-info-circle"></i> Details</div>
                    <div class="preview-fields-grid">
                        <?php 
                        $fields = [
                            'File Name' => $viewData['file_name'],
                            'File Size' => formatFileSize($viewData['file_size']),
                            'MIME Type' => $viewData['mime_type'],
                            'Description' => $viewData['description'],
                            'Tags' => $viewData['tags'],
                            'Linked To' => $viewData['linked_to'] ? ucfirst($viewData['linked_to']) : null,
                            'Expiry Date' => $viewData['expiry_date'] ? date('d M Y', strtotime($viewData['expiry_date'])) : null,
                            'Storage' => isGoogleDriveFile($viewData) ? 'Google Drive' : 'Local',
                        ];
                        foreach ($fields as $label => $value): ?>
                        <div class="preview-field<?php echo (!$value ? '' : (strlen($value) > 60 || $label === 'Description' ? ' full-width' : '')); ?>">
                            <div class="preview-field-label"><?php echo $label; ?></div>
                            <div class="preview-field-value<?php echo !$value ? ' empty' : ''; ?>"><?php echo $value ? htmlspecialchars($value) : 'Not provided'; ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if (!empty($viewCustomValues)): ?>
                <div class="preview-section">
                    <div class="preview-section-title"><i class="fas fa-puzzle-piece"></i> Custom Fields</div>
                    <div class="preview-fields-grid">
                        <?php foreach ($viewCustomValues as $cv): ?>
                        <div class="preview-field<?php echo (in_array($cv['field_type'], $fileFieldTypes) || strlen($cv['field_value']) > 60) ? ' full-width' : ''; ?>">
                            <div class="preview-field-label"><?php echo htmlspecialchars($cv['field_label']); ?></div>
                            <div class="preview-field-value"><?php renderFieldValueDisplay($cv, $fileFieldTypes); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$isPreviewable): ?>
        </div><!-- close non-previewable padding div -->
        <?php endif; ?>
    </div>
    <div class="modal-footer">
        <a href="<?php echo url('documents.php?download=' . $viewData['id']); ?>" class="btn btn-primary btn-sm">
            <i class="fas fa-download"></i> Download
        </a>
        <?php if ($isPdf): ?>
        <a href="<?php echo $previewUrl; ?>" target="_blank" class="btn btn-ghost btn-sm" title="Open in new tab">
            <i class="fas fa-external-link-alt"></i> Open Full
        </a>
        <?php endif; ?>
        <a href="<?php echo url('documents.php?edit=' . $viewData['id']); ?>" class="btn btn-primary btn-sm">
            <i class="fas fa-edit"></i> Edit
        </a>
        <a href="<?php echo url('documents.php'); ?>" class="btn btn-secondary btn-sm">Close</a>
    </div>
</div>
<?php if ($isPreviewable): ?>
<script>
document.getElementById('toggleDocInfo').addEventListener('click', function() {
    var panel = document.getElementById('docInfoPanel');
    var preview = document.getElementById('docPreviewPane');
    if (panel.style.display === 'none') {
        panel.style.display = 'block';
        if (preview) preview.style.display = 'none';
        this.innerHTML = '<i class="fas fa-file"></i>';
        this.title = 'Show preview';
    } else {
        panel.style.display = 'none';
        if (preview) preview.style.display = '';
        this.innerHTML = '<i class="fas fa-info-circle"></i>';
        this.title = 'Toggle details';
    }
});
</script>
<?php endif; ?>
<script>document.getElementById('modalOverlay').classList.add('active');</script>
<?php } } ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
