<?php
$pageTitle = 'Other Details';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/custom_fields_helper.php';
require_once __DIR__ . '/includes/action_buttons_helper.php';
$user = requireAuth();
$db = Database::getInstance();

// Shared hosting compatibility: older imports may miss folder tables.
try {
    $db->query("CREATE TABLE IF NOT EXISTS other_detail_folders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        folder_name VARCHAR(100) NOT NULL,
        folder_icon VARCHAR(50) DEFAULT 'fa-folder',
        folder_color VARCHAR(20) DEFAULT '#6C63FF',
        folder_order INT DEFAULT 0,
        description TEXT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS other_detail_records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        folder_id INT NOT NULL,
        record_name VARCHAR(255) NOT NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user_folder (user_id, folder_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    // Ignore CREATE failures when DB user lacks schema permissions.
}

// ── Determine current view ──
$folderId    = isset($_GET['folder']) ? (int)$_GET['folder'] : null;
$viewId      = isset($_GET['view']) ? (int)$_GET['view'] : null;
$editId      = isset($_GET['edit']) ? (int)$_GET['edit'] : null;
$showAddForm = isset($_GET['action']) && $_GET['action'] === 'add';

// ── FOLDER CRUD ACTIONS ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['folder_action'])) {
    $fa = $_POST['folder_action'];

    if ($fa === 'create_folder') {
        $name  = sanitize($_POST['folder_name'] ?? '');
        $icon  = sanitize($_POST['folder_icon'] ?? 'fa-folder');
        $color = sanitize($_POST['folder_color'] ?? '#6C63FF');
        $desc  = sanitize($_POST['folder_description'] ?? '');
        if (empty($name)) {
            setFlash('error', 'Folder name is required');
        } else {
            $maxOrder = $db->fetch("SELECT MAX(folder_order) as mo FROM other_detail_folders WHERE user_id = ?", [$user['id']]);
            $newFolderId = $db->insert(
                "INSERT INTO other_detail_folders (user_id, folder_name, folder_icon, folder_color, folder_order, description) VALUES (?, ?, ?, ?, ?, ?)",
                [$user['id'], $name, $icon, $color, ($maxOrder['mo'] ?? 0) + 1, $desc]
            );
            // AUTO_INCREMENT can reuse a deleted folder's id (e.g. after a DB
            // restart, or a folder removed outside the app). Stale fields/
            // blocks/values/buttons of the old folder would then silently
            // attach to this new one -- they're quarantined instead (kept,
            // shown on the Mapping page), never deleted.
            feQuarantineModule(feFolderModule($newFolderId), $user['id']);
            setFlash('success', 'Folder created successfully');
        }
        redirect('/other-details.php');
    }

    if ($fa === 'update_folder') {
        $fid   = (int)($_POST['folder_id'] ?? 0);
        $name  = sanitize($_POST['folder_name'] ?? '');
        $icon  = sanitize($_POST['folder_icon'] ?? 'fa-folder');
        $color = sanitize($_POST['folder_color'] ?? '#6C63FF');
        $desc  = sanitize($_POST['folder_description'] ?? '');
        if ($fid && $name) {
            $db->update(
                "UPDATE other_detail_folders SET folder_name=?, folder_icon=?, folder_color=?, description=? WHERE id=? AND user_id=?",
                [$name, $icon, $color, $desc, $fid, $user['id']]
            );
            setFlash('success', 'Folder updated');
        }
        redirect('/other-details.php');
    }

    if ($fa === 'delete_folder') {
        $fid = (int)($_POST['folder_id'] ?? 0);
        // Records (with their buttons' child records and every value/file),
        // the folder's fields, blocks and buttons -- all in one transaction.
        if (feDeleteFolder($fid, $user['id'])) {
            setFlash('success', 'Folder and all its records deleted');
        } else {
            setFlash('error', 'Folder not found');
        }
        redirect('/other-details.php');
    }
}

// ── RECORD CRUD ACTIONS (inside a folder) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['record_action']) && $folderId) {
    $ra = $_POST['record_action'];
    $moduleName = 'other_folder_' . $folderId;

    $folder = $db->fetch("SELECT * FROM other_detail_folders WHERE id = ? AND user_id = ?", [$folderId, $user['id']]);
    if (!$folder) {
        setFlash('error', 'Folder not found');
        redirect('/other-details.php');
    }

    if ($ra === 'save_record') {
        $recEditId   = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : null;
        $recordName  = sanitize($_POST['record_name'] ?? '');
        $notes       = sanitize($_POST['notes'] ?? '');

        if (empty($recordName)) {
            setFlash('error', 'Record name is required');
            redirect('/other-details.php?folder=' . $folderId . ($recEditId ? '&edit=' . $recEditId : '&action=add'));
        }

        if ($recEditId) {
            $db->update(
                "UPDATE other_detail_records SET record_name=?, notes=? WHERE id=? AND user_id=? AND folder_id=?",
                [$recordName, $notes, $recEditId, $user['id'], $folderId]
            );
            saveCustomFieldEntries($user['id'], $moduleName, $recEditId);
            $db->insert("INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
                [$user['id'], 'update', 'Updated record in ' . $folder['folder_name'] . ': ' . $recordName, $_SERVER['REMOTE_ADDR'] ?? '']);
            setFlash('success', 'Record updated');
        } else {
            $newId = $db->insert(
                "INSERT INTO other_detail_records (user_id, folder_id, record_name, notes) VALUES (?, ?, ?, ?)",
                [$user['id'], $folderId, $recordName, $notes]
            );
            saveCustomFieldEntries($user['id'], $moduleName, $newId);
            $db->insert("INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
                [$user['id'], 'create', 'Added record in ' . $folder['folder_name'] . ': ' . $recordName, $_SERVER['REMOTE_ADDR'] ?? '']);
            setFlash('success', 'Record added');
        }
        redirect('/other-details.php?folder=' . $folderId);
    }

    if ($ra === 'delete_record') {
        $recId = (int)($_POST['record_id'] ?? 0);
        $rec = $db->fetch("SELECT id FROM other_detail_records WHERE id = ? AND user_id = ? AND folder_id = ?", [$recId, $user['id'], $folderId]);
        if ($rec && feDeleteFolderRecord($recId, $user['id'])) {
            setFlash('success', 'Record deleted');
        } else {
            setFlash('error', 'Record not found');
        }
        redirect('/other-details.php?folder=' . $folderId);
    }
}

// ── Handle old-style "flat" Other Details CRUD (backwards compat) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action']) && $_POST['action'] === 'save' && !$folderId) {
    $editIdLegacy = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : null;
    $data = [
        'category'      => sanitize($_POST['category'] ?? 'General'),
        'title'         => sanitize($_POST['title'] ?? ''),
        'detail_type'   => $_POST['detail_type'] ?? 'text',
        'detail_value'  => sanitize($_POST['detail_value'] ?? ''),
        'is_sensitive'  => isset($_POST['is_sensitive']) ? 1 : 0,
        'tags'          => sanitize($_POST['tags'] ?? ''),
        'expiry_date'   => !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null,
        'notes'         => sanitize($_POST['notes'] ?? ''),
    ];
    if (empty($data['title']) || empty($data['detail_value'])) {
        setFlash('error', 'Title and Value are required');
    } else {
        if ($editIdLegacy) {
            $db->update(
                "UPDATE other_details SET category=?, title=?, detail_type=?, detail_value=?, is_sensitive=?, tags=?, expiry_date=?, notes=? WHERE id=? AND user_id=?",
                array_merge(array_values($data), [$editIdLegacy, $user['id']])
            );
            saveCustomFieldEntries($user['id'], 'other_details', $editIdLegacy);
            setFlash('success', 'Record updated');
        } else {
            $newId = $db->insert(
                "INSERT INTO other_details (user_id, category, title, detail_type, detail_value, is_sensitive, tags, expiry_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                array_merge([$user['id']], array_values($data))
            );
            saveCustomFieldEntries($user['id'], 'other_details', $newId);
            setFlash('success', 'Record added');
        }
        redirect('/other-details.php');
    }
}

// Handle legacy delete
if (!$folderId && isset($_GET['delete']) && is_numeric($_GET['delete']) && Auth::validateCSRF($_GET['csrf'] ?? '')) {
    $id = (int)$_GET['delete'];
    deleteCustomFieldFilesForRecord('other_details', $id);
    $db->delete("DELETE FROM other_details WHERE id = ? AND user_id = ?", [$id, $user['id']]);
    setFlash('success', 'Record deleted');
    redirect('/other-details.php');
}

// =============================================
// VIEW RENDERING
// =============================================

// Search term (shared: filters the folder grid when browsing, or records when inside a folder)
$search = sanitize($_GET['search'] ?? '');

// Get all folders
$folderWhere = "WHERE f.user_id = ? AND f.is_active = 1";
$folderParams = [$user['id']];
if ($search && !$folderId) {
    $folderWhere .= " AND (f.folder_name LIKE ? OR f.description LIKE ?)";
    $folderSearchLike = "%$search%";
    $folderParams[] = $folderSearchLike;
    $folderParams[] = $folderSearchLike;
}
$folders = $db->fetchAll(
    "SELECT f.*, (SELECT COUNT(*) FROM other_detail_records r WHERE r.folder_id = f.id) as record_count
     FROM other_detail_folders f
     $folderWhere
     ORDER BY f.folder_order ASC",
    $folderParams
);

// Get legacy record count
$legacyCount = $db->fetch("SELECT COUNT(*) as cnt FROM other_details WHERE user_id = ?", [$user['id']]);

// ── INSIDE A FOLDER VIEW ──
if ($folderId):
    $folder = $db->fetch("SELECT * FROM other_detail_folders WHERE id = ? AND user_id = ?", [$folderId, $user['id']]);
    if (!$folder) {
        setFlash('error', 'Folder not found');
        redirect('/other-details.php');
    }
    $moduleName = 'other_folder_' . $folderId;

    $recordWhere = "WHERE folder_id = ? AND user_id = ?";
    $recordParams = [$folderId, $user['id']];
    if ($search) {
        $recordWhere .= " AND (record_name LIKE ? OR notes LIKE ?)";
        $recordSearchLike = "%$search%";
        $recordParams[] = $recordSearchLike;
        $recordParams[] = $recordSearchLike;
    }
    $records = $db->fetchAll(
        "SELECT * FROM other_detail_records $recordWhere ORDER BY updated_at DESC",
        $recordParams
    );

    $customFields = getCustomFieldsFor($moduleName, $user['id']);

    $editData = null;
    $editCustomValues = [];
    if ($editId) {
        $editData = $db->fetch("SELECT * FROM other_detail_records WHERE id = ? AND user_id = ? AND folder_id = ?", [$editId, $user['id'], $folderId]);
        if ($editData) {
            $editCustomValues = getCustomFieldValues($moduleName, $editData['id']);
        }
    }
    $showRecordForm = $showAddForm || $editData;
?>

<!-- Toolbar -->
<div class="toolbar">
    <div class="toolbar-left">
        <a href="<?php echo url('other-details.php'); ?>" class="btn btn-ghost">
            <i class="fas fa-arrow-left"></i> Back to Folders
        </a>
        <div style="display:flex; align-items:center; gap:10px; margin-left:12px;">
            <div style="width:36px;height:36px;border-radius:10px;background:<?php echo htmlspecialchars($folder['folder_color']); ?>;display:flex;align-items:center;justify-content:center;">
                <i class="fas <?php echo htmlspecialchars($folder['folder_icon']); ?>" style="color:#fff;font-size:1rem;"></i>
            </div>
            <div>
                <h2 style="margin:0;font-size:1.1rem;"><?php echo htmlspecialchars($folder['folder_name']); ?></h2>
                <?php if ($folder['description']): ?>
                <p style="margin:0;font-size:0.78rem;color:var(--text-muted);"><?php echo htmlspecialchars($folder['description']); ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="toolbar-right">
        <?php if (!$showRecordForm): ?>
        <div class="search-box" style="width: auto;">
            <i class="fas fa-search"></i>
            <form method="GET" style="display:inline;">
                <input type="hidden" name="folder" value="<?php echo $folderId; ?>">
                <input type="text" name="search" placeholder="Search records..."
                       value="<?php echo htmlspecialchars($search); ?>" style="width: 180px;">
            </form>
        </div>
        <a href="<?php echo url('custom-fields.php?module=' . $moduleName); ?>" class="btn btn-secondary btn-sm" title="Manage custom fields for this folder">
            <i class="fas fa-puzzle-piece"></i> Fields
        </a>
        <a href="<?php echo url('field-blocks.php?module=' . $moduleName); ?>" class="btn btn-secondary btn-sm" title="Manage blocks for this folder">
            <i class="fas fa-th-large"></i> Blocks
        </a>
        <a href="<?php echo url('other-details.php?folder=' . $folderId . '&action=add'); ?>" class="btn btn-primary">
            <i class="fas fa-plus"></i> Add Record
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($showRecordForm): ?>
<!-- Add/Edit Record Form -->
<div class="card animate-fade-in">
    <div class="card-header">
        <h2>
            <i class="fas fa-<?php echo $editData ? 'edit' : 'plus'; ?>" style="margin-right:8px;color:var(--primary);"></i>
            <?php echo $editData ? 'Edit Record' : 'Add New Record'; ?>
        </h2>
    </div>
    <form method="POST" action="<?php echo url('other-details.php?folder=' . $folderId); ?>" id="folderRecordForm" enctype="multipart/form-data">
<?php echo csrf_field(); ?>
        <input type="hidden" name="record_action" value="save_record">
        <?php if ($editData): ?>
            <input type="hidden" name="edit_id" value="<?php echo $editData['id']; ?>">
        <?php endif; ?>

        <div class="form-row">
            <div class="form-group" style="flex:2;">
                <label>Record Name <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="text" name="record_name" placeholder="e.g. Driving License, University Marksheet" required
                           value="<?php echo htmlspecialchars($editData['record_name'] ?? ''); ?>">
                    <i class="fas fa-heading"></i>
                </div>
            </div>
        </div>

        <div class="form-group">
            <label>Notes</label>
            <div class="input-wrapper">
                <textarea name="notes" rows="2" placeholder="Any additional notes..."><?php echo htmlspecialchars($editData['notes'] ?? ''); ?></textarea>
                <i class="fas fa-sticky-note" style="top:14px;"></i>
            </div>
        </div>

        <?php renderCustomFields($customFields ?? [], $editCustomValues ?? [], 'Custom Fields', $moduleName, $user['id']); ?>

        <div class="form-actions">
            <a href="<?php echo url('other-details.php?folder=' . $folderId); ?>" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> <?php echo $editData ? 'Update' : 'Save'; ?>
            </button>
        </div>
    </form>
</div>

<?php elseif (empty($records) && $search): ?>
<!-- No search results inside folder -->
<div class="empty-state card">
    <i class="fas fa-search" style="color:var(--text-muted);"></i>
    <h3>No records match "<?php echo htmlspecialchars($search); ?>"</h3>
    <p>Try a different search term.</p>
    <a href="<?php echo url('other-details.php?folder=' . $folderId); ?>" class="btn btn-secondary">
        <i class="fas fa-times"></i> Clear Search
    </a>
</div>

<?php elseif (empty($records)): ?>
<!-- Empty state inside folder -->
<div class="empty-state card">
    <i class="fas <?php echo htmlspecialchars($folder['folder_icon']); ?>" style="color:<?php echo htmlspecialchars($folder['folder_color']); ?>;"></i>
    <h3>No records in this folder</h3>
    <p>Add your first record and customize the fields using the Fields/Blocks buttons above.</p>
    <a href="<?php echo url('other-details.php?folder=' . $folderId . '&action=add'); ?>" class="btn btn-primary">
        <i class="fas fa-plus"></i> Add Record
    </a>
</div>

<?php else: ?>
<!-- Records list -->
<div class="card animate-fade-in">
    <div class="table-container" style="border:none;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Record Name</th>
                    <th>Notes</th>
                    <th>Updated</th>
                    <th style="width:140px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $record): ?>
                <tr>
                    <td><strong><i class="fas <?php echo htmlspecialchars($folder['folder_icon']); ?>" style="color:<?php echo htmlspecialchars($folder['folder_color']); ?>;margin-right:6px;"></i><?php echo htmlspecialchars($record['record_name']); ?></strong></td>
                    <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;color:var(--text-muted);"><?php echo $record['notes'] ? htmlspecialchars(substr($record['notes'], 0, 80)) : '-'; ?></td>
                    <td><span style="font-size:0.8rem;color:var(--text-muted);"><?php echo timeAgo($record['updated_at']); ?></span></td>
                    <td>
                        <div style="display:flex;gap:4px;">
                            <a href="<?php echo url('other-details.php?folder=' . $folderId . '&view=' . $record['id']); ?>" class="btn btn-ghost btn-sm" title="View"><i class="fas fa-eye"></i></a>
                            <a href="<?php echo url('other-details.php?folder=' . $folderId . '&edit=' . $record['id']); ?>" class="btn btn-ghost btn-sm" title="Edit"><i class="fas fa-edit"></i></a>
                            <form method="POST" action="<?php echo url('other-details.php?folder=' . $folderId); ?>" style="display:inline;" onsubmit="return confirm('Delete this record?');">
<?php echo csrf_field(); ?>
                                <input type="hidden" name="record_action" value="delete_record">
                                <input type="hidden" name="record_id" value="<?php echo $record['id']; ?>">
                                <button type="submit" class="btn btn-ghost btn-sm text-danger" title="Delete"><i class="fas fa-trash"></i></button>
                            </form>
                            <?php renderActionButtonsInline('other_folder_' . $folderId, $record['id'], $user['id']); ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- View Record Modal -->
<?php
if ($viewId && $folderId) {
    $viewData = $db->fetch("SELECT * FROM other_detail_records WHERE id = ? AND user_id = ? AND folder_id = ?", [$viewId, $user['id'], $folderId]);
    if ($viewData) {
?>
<div class="modal view-modal active" id="viewModal">
    <div class="modal-header">
        <h3><i class="fas fa-eye" style="margin-right:8px;color:var(--primary);"></i> <?php echo htmlspecialchars($viewData['record_name']); ?></h3>
        <a href="<?php echo url('other-details.php?folder=' . $folderId); ?>" class="modal-close"><i class="fas fa-times"></i></a>
    </div>
    <div class="modal-body" style="padding:24px 28px;">
        <div class="preview-section">
            <div class="preview-section-title"><i class="fas fa-info-circle"></i> Details</div>
            <div class="preview-fields-grid">
                <div class="preview-field">
                    <div class="preview-field-label">Record Name</div>
                    <div class="preview-field-value"><?php echo htmlspecialchars($viewData['record_name']); ?></div>
                </div>
                <?php if ($viewData['notes']): ?>
                <div class="preview-field full-width">
                    <div class="preview-field-label">Notes</div>
                    <div class="preview-field-value"><?php echo nl2br(htmlspecialchars($viewData['notes'])); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php renderCustomFieldValues($moduleName, $viewData['id']); ?>
    </div>
    <div class="modal-footer">
        <a href="<?php echo url('other-details.php?folder=' . $folderId . '&edit=' . $viewData['id']); ?>" class="btn btn-primary btn-sm"><i class="fas fa-edit"></i> Edit</a>
        <a href="<?php echo url('other-details.php?folder=' . $folderId); ?>" class="btn btn-secondary btn-sm">Close</a>
    </div>
</div>
<script>document.getElementById('modalOverlay').classList.add('active');</script>
<?php } } ?>

<?php else: ?>
<!-- ========================================= -->
<!-- FOLDER GRID VIEW (Main landing) -->
<!-- ========================================= -->
<?php
// Get legacy records for backward compat display
$filterCat = sanitize($_GET['category'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;
$where = "WHERE user_id = ?";
$params = [$user['id']];
if ($search) {
    $where .= " AND (title LIKE ? OR detail_value LIKE ? OR category LIKE ? OR tags LIKE ?)";
    $searchLike = "%$search%";
    $params = array_merge($params, [$searchLike, $searchLike, $searchLike, $searchLike]);
}
if ($filterCat) {
    $where .= " AND category = ?";
    $params[] = $filterCat;
}
$totalCount = $db->fetch("SELECT COUNT(*) as count FROM other_details $where", $params);
$totalPages = ceil(($totalCount['count'] ?: 1) / $perPage);
$legacyRecords = $db->fetchAll("SELECT * FROM other_details $where ORDER BY category, updated_at DESC LIMIT $perPage OFFSET $offset", $params);
$categories = $db->fetchAll("SELECT DISTINCT category, COUNT(*) as cnt FROM other_details WHERE user_id = ? GROUP BY category ORDER BY category", [$user['id']]);

// Edit for legacy
$editData = null; $editCustomValues = [];
if (isset($_GET['edit']) && is_numeric($_GET['edit']) && !$folderId) {
    $editData = $db->fetch("SELECT * FROM other_details WHERE id = ? AND user_id = ?", [(int)$_GET['edit'], $user['id']]);
    if ($editData) { $editCustomValues = getCustomFieldValues('other_details', $editData['id']); }
}
$showLegacyForm = (isset($_GET['action']) && $_GET['action'] === 'add_legacy') || $editData;
$customFields = getCustomFieldsFor('other_details', $user['id']);
$presetCategories = ['General', 'Subscription', 'Social Media', 'WiFi / Network', 'License Key', 'Insurance', 'Membership', 'Travel', 'Medical', 'Government', 'Education', 'Vehicle', 'Property', 'Investment', 'Utility', 'Emergency', 'Other'];

function getTypeIcon($type) {
    $icons = ['text'=>'fa-font','number'=>'fa-hashtag','date'=>'fa-calendar','url'=>'fa-link','email'=>'fa-envelope','phone'=>'fa-phone','password'=>'fa-key','note'=>'fa-sticky-note'];
    return $icons[$type] ?? 'fa-font';
}
function getCategoryColor($cat) {
    $hash = crc32($cat);
    $colors = ['#6C63FF','#FF6B6B','#4ECDC4','#45B7D1','#96CEB4','#FFEAA7','#DDA0DD','#98D8C8','#F7DC6F','#BB8FCE','#85C1E9','#F1948A'];
    return $colors[abs($hash) % count($colors)];
}
?>

<!-- Toolbar -->
<div class="toolbar">
    <div class="toolbar-left">
        <?php if (!$showLegacyForm): ?>
        <div class="search-box" style="width: auto;">
            <i class="fas fa-search"></i>
            <form method="GET" style="display:inline;">
                <input type="text" name="search" placeholder="Search folders &amp; details..."
                       value="<?php echo htmlspecialchars($search); ?>" style="width: 220px;">
            </form>
        </div>
        <?php endif; ?>
        <span style="color:var(--text-secondary);margin-left:12px;"><strong><?php echo count($folders); ?></strong> folder(s)</span>
        <?php if ($legacyCount['cnt'] > 0): ?>
        <span style="color:var(--text-muted);margin-left:12px;">+ <?php echo $legacyCount['cnt']; ?> flat record(s)</span>
        <?php endif; ?>
    </div>
    <div class="toolbar-right">
        <button type="button" class="btn btn-primary" id="createFolderBtn">
            <i class="fas fa-folder-plus"></i> New Folder
        </button>
        <?php if ($legacyCount['cnt'] > 0 || !empty($customFields)): ?>
        <a href="<?php echo url('other-details.php?action=add_legacy'); ?>" class="btn btn-secondary">
            <i class="fas fa-plus"></i> Add Flat Detail
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Folders Grid -->
<?php if (!empty($folders)): ?>
<div class="folder-grid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:20px; margin-bottom:24px;">
    <?php foreach ($folders as $f): ?>
    <div class="folder-card card animate-fade-in" style="position:relative; overflow:hidden; transition:transform 0.2s, box-shadow 0.2s;">
        <a href="<?php echo url('other-details.php?folder=' . $f['id']); ?>" style="text-decoration:none; color:inherit; display:block;">
            <div style="padding:24px; text-align:center;">
                <div style="width:64px;height:64px;border-radius:16px;background:<?php echo htmlspecialchars($f['folder_color']); ?>;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;box-shadow:0 4px 12px <?php echo htmlspecialchars($f['folder_color']); ?>40;">
                    <i class="fas <?php echo htmlspecialchars($f['folder_icon']); ?>" style="font-size:1.6rem;color:#fff;"></i>
                </div>
                <h3 style="margin:0 0 4px;font-size:1.05rem;"><?php echo htmlspecialchars($f['folder_name']); ?></h3>
                <?php if ($f['description']): ?>
                <p style="margin:0 0 8px;font-size:0.78rem;color:var(--text-muted);max-height:40px;overflow:hidden;"><?php echo htmlspecialchars($f['description']); ?></p>
                <?php endif; ?>
                <span class="badge badge-secondary" style="font-size:0.72rem;"><?php echo $f['record_count']; ?> record(s)</span>
            </div>
        </a>
        <!-- Edit/Delete buttons -->
        <div style="position:absolute;top:8px;right:8px;display:flex;gap:4px;">
            <button type="button" class="btn btn-icon btn-ghost btn-sm edit-folder-btn" title="Edit Folder" 
                data-folder='<?php echo htmlspecialchars(json_encode($f), ENT_QUOTES, 'UTF-8'); ?>'
                style="width:28px;height:28px;padding:0;">
                <i class="fas fa-pen" style="font-size:0.7rem;"></i>
            </button>
            <form method="POST" action="<?php echo url('other-details.php'); ?>" style="display:inline;" onsubmit="return confirm('Delete this folder and all its records?');">
<?php echo csrf_field(); ?>
                <input type="hidden" name="folder_action" value="delete_folder">
                <input type="hidden" name="folder_id" value="<?php echo $f['id']; ?>">
                <button type="submit" class="btn btn-icon btn-ghost btn-sm text-danger" title="Delete Folder" style="width:28px;height:28px;padding:0;">
                    <i class="fas fa-trash" style="font-size:0.7rem;"></i>
                </button>
            </form>
            <?php renderActionButtonsInline('other_detail_folders', $f['id'], $user['id']); ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php elseif ($search && $totalCount['count'] == 0): ?>
<div class="empty-state card">
    <i class="fas fa-search"></i>
    <h3>No results for "<?php echo htmlspecialchars($search); ?>"</h3>
    <p>No folders or details matched your search. Try a different term.</p>
    <a href="<?php echo url('other-details.php'); ?>" class="btn btn-secondary">
        <i class="fas fa-times"></i> Clear Search
    </a>
</div>
<?php elseif (!$search && $legacyCount['cnt'] == 0 && !$showLegacyForm): ?>
<div class="empty-state card">
    <i class="fas fa-folder-open"></i>
    <h3>No folders yet</h3>
    <p>Create folders to organize your details — License Details, Fee Details, Mark Sheets, and more. Each folder gets its own custom fields and blocks.</p>
    <button type="button" class="btn btn-primary" id="createFolderBtnEmpty">
        <i class="fas fa-folder-plus"></i> Create First Folder
    </button>
</div>
<?php endif; ?>

<!-- Legacy Flat Records (backward compat) -->
<?php if ($showLegacyForm): ?>
<div class="card animate-fade-in" style="margin-top:20px;">
    <div class="card-header">
        <h2><i class="fas fa-<?php echo $editData ? 'edit' : 'plus'; ?>" style="margin-right:8px;color:var(--primary);"></i><?php echo $editData ? 'Edit Detail' : 'Add New Detail'; ?></h2>
        <a href="<?php echo url('other-details.php'); ?>" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
    <form method="POST" action="<?php echo url('other-details.php'); ?>" id="otherForm" enctype="multipart/form-data">
<?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save">
        <?php if ($editData): ?>
            <input type="hidden" name="edit_id" value="<?php echo $editData['id']; ?>">
        <?php endif; ?>
        <div class="form-row">
            <div class="form-group">
                <label>Category <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="text" name="category" list="categoryList" placeholder="Select or type a category"
                           value="<?php echo htmlspecialchars($editData['category'] ?? 'General'); ?>" required>
                    <i class="fas fa-folder"></i>
                </div>
                <datalist id="categoryList">
                    <?php foreach ($presetCategories as $cat): ?><option value="<?php echo htmlspecialchars($cat); ?>"><?php endforeach; ?>
                </datalist>
            </div>
            <div class="form-group">
                <label>Title <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="text" name="title" placeholder="e.g. Netflix Password, Driving License No." required value="<?php echo htmlspecialchars($editData['title'] ?? ''); ?>">
                    <i class="fas fa-heading"></i>
                </div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Value Type</label>
                <div class="input-wrapper">
                    <select name="detail_type" id="detailType">
                        <?php
                        $types = ['text'=>'Text','number'=>'Number','date'=>'Date','url'=>'URL','email'=>'Email','phone'=>'Phone','password'=>'Password/Secret','note'=>'Long Note'];
                        foreach ($types as $val => $label): ?>
                        <option value="<?php echo $val; ?>" <?php echo ($editData['detail_type'] ?? 'text') === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <i class="fas fa-list"></i>
                </div>
            </div>
            <div class="form-group">
                <label>Value <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="text" name="detail_value" placeholder="Enter the value" required value="<?php echo htmlspecialchars($editData['detail_value'] ?? ''); ?>">
                    <i class="fas fa-pen"></i>
                </div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Tags</label>
                <div class="input-wrapper">
                    <input type="text" name="tags" placeholder="Comma separated" value="<?php echo htmlspecialchars($editData['tags'] ?? ''); ?>">
                    <i class="fas fa-tags"></i>
                </div>
            </div>
            <div class="form-group">
                <label>Expiry Date</label>
                <div class="input-wrapper">
                    <input type="date" name="expiry_date" value="<?php echo htmlspecialchars($editData['expiry_date'] ?? ''); ?>">
                    <i class="fas fa-calendar-times"></i>
                </div>
            </div>
        </div>
        <div class="form-group" style="display:flex;align-items:center;padding-top:8px;">
            <label class="form-check" style="margin-bottom:0;">
                <input type="checkbox" name="is_sensitive" <?php echo ($editData['is_sensitive'] ?? 0) ? 'checked' : ''; ?>>
                <span class="checkmark"></span>
                <span class="form-check-label">Mark as sensitive</span>
            </label>
        </div>
        <div class="form-group">
            <label>Notes</label>
            <div class="input-wrapper">
                <textarea name="notes" rows="2" placeholder="Any additional notes..."><?php echo htmlspecialchars($editData['notes'] ?? ''); ?></textarea>
                <i class="fas fa-sticky-note" style="top:14px;"></i>
            </div>
        </div>
        <?php renderCustomFields($customFields ?? [], $editCustomValues ?? [], 'Custom Fields', 'other_details', $user['id']); ?>
        <div class="form-actions">
            <a href="<?php echo url('other-details.php'); ?>" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo $editData ? 'Update' : 'Save'; ?></button>
        </div>
    </form>
</div>

<?php elseif (!empty($legacyRecords)): ?>
<?php
$grouped = [];
foreach ($legacyRecords as $r) { $grouped[$r['category']][] = $r; }
foreach ($grouped as $catName => $catRecords):
?>
<div class="card animate-fade-in" style="margin-bottom:20px;">
    <div class="card-header">
        <h2>
            <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:<?php echo getCategoryColor($catName); ?>;margin-right:8px;"></span>
            <?php echo htmlspecialchars($catName); ?>
            <span class="badge badge-secondary" style="margin-left:8px;"><?php echo count($catRecords); ?></span>
        </h2>
    </div>
    <div class="table-container" style="border:none;">
        <table class="data-table">
            <thead><tr><th>Title</th><th>Type</th><th>Value</th><th>Tags</th><th>Expiry</th><th>Updated</th><th style="width:100px;">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($catRecords as $record): ?>
            <tr>
                <td><strong><i class="fas <?php echo getTypeIcon($record['detail_type']); ?>" style="color:var(--primary);margin-right:6px;"></i><?php echo htmlspecialchars($record['title']); ?></strong>
                <?php if ($record['is_sensitive']): ?><span class="badge badge-warning" style="font-size:0.65rem;margin-left:4px;"><i class="fas fa-lock"></i></span><?php endif; ?>
                </td>
                <td><span class="badge badge-info"><?php echo ucfirst($record['detail_type']); ?></span></td>
                <td style="max-width:250px;overflow:hidden;text-overflow:ellipsis;">
                    <?php if ($record['is_sensitive']): ?>
                        <span class="sensitive-value" data-value="<?php echo htmlspecialchars($record['detail_value']); ?>" style="cursor:pointer;" title="Click to reveal">&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;&#8226; <i class="fas fa-eye" style="margin-left:6px;font-size:0.8rem;color:var(--text-muted);"></i></span>
                    <?php elseif ($record['detail_type'] === 'url'): ?>
                        <a href="<?php echo htmlspecialchars($record['detail_value']); ?>" target="_blank" style="color:var(--primary);"><?php echo htmlspecialchars(substr($record['detail_value'], 0, 40)); ?><?php echo strlen($record['detail_value']) > 40 ? '...' : ''; ?></a>
                    <?php else: ?>
                        <?php echo htmlspecialchars(substr($record['detail_value'], 0, 60)); ?><?php echo strlen($record['detail_value']) > 60 ? '...' : ''; ?>
                    <?php endif; ?>
                </td>
                <td><?php if ($record['tags']): foreach(array_slice(explode(',', $record['tags']), 0, 3) as $tag): ?><span class="badge badge-secondary" style="font-size:0.65rem;margin:1px;"><?php echo htmlspecialchars(trim($tag)); ?></span><?php endforeach; else: ?>-<?php endif; ?></td>
                <td><?php if ($record['expiry_date']): $exp = new DateTime($record['expiry_date']); $isExp = $exp < new DateTime(); ?><span class="badge badge-<?php echo $isExp ? 'danger' : 'success'; ?>" style="font-size:0.7rem;"><?php echo $exp->format('d M Y'); ?></span><?php else: ?>-<?php endif; ?></td>
                <td><span style="font-size:0.8rem;color:var(--text-muted);"><?php echo timeAgo($record['updated_at']); ?></span></td>
                <td>
                    <div style="display:flex;gap:4px;">
                        <a href="<?php echo url('other-details.php?edit=' . $record['id']); ?>" class="btn btn-ghost btn-sm" title="Edit"><i class="fas fa-edit"></i></a>
                        <a href="<?php echo url('other-details.php?delete=' . $record['id'] . '&csrf=' . urlencode(Auth::generateCSRF())); ?>" class="btn btn-ghost btn-sm text-danger" title="Delete" onclick="return confirm('Delete this record?');"><i class="fas fa-trash"></i></a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>
<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php if ($page > 1): ?><a href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&category=<?php echo urlencode($filterCat); ?>" class="pagination-btn"><i class="fas fa-chevron-left"></i></a><?php endif; ?>
    <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
    <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&category=<?php echo urlencode($filterCat); ?>" class="pagination-btn <?php echo $i===$page?'active':''; ?>"><?php echo $i; ?></a>
    <?php endfor; ?>
    <?php if ($page < $totalPages): ?><a href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&category=<?php echo urlencode($filterCat); ?>" class="pagination-btn"><i class="fas fa-chevron-right"></i></a><?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- View Legacy Detail Modal -->
<?php
if (isset($_GET['view']) && is_numeric($_GET['view']) && !$folderId) {
    $viewData = $db->fetch("SELECT * FROM other_details WHERE id = ? AND user_id = ?", [(int)$_GET['view'], $user['id']]);
    if ($viewData) {
        $viewCustomValues = [];
        try { $viewCustomValues = $db->fetchAll("SELECT cf.field_label, cf.field_type, cfe.field_value FROM custom_field_entries cfe JOIN custom_fields cf ON cf.id = cfe.custom_field_id WHERE cfe.linked_to = 'other_details' AND cfe.record_id = ? AND cfe.field_value != ''", [$viewData['id']]); } catch (Exception $e) {}
        $fileFieldTypes = getFileFieldTypes();
?>
<div class="modal view-modal active" id="viewModal">
    <div class="modal-header">
        <h3><i class="fas fa-eye" style="margin-right:8px;color:var(--primary);"></i> View Details</h3>
        <a href="<?php echo url('other-details.php'); ?>" class="modal-close"><i class="fas fa-times"></i></a>
    </div>
    <div class="modal-body" style="padding:24px 28px;">
        <div class="preview-section">
            <div class="preview-section-title"><i class="fas fa-info-circle"></i> Details</div>
            <div class="preview-fields-grid">
                <?php
                $fields = ['Category'=>$viewData['category'],'Type'=>ucfirst($viewData['detail_type']),'Value'=>$viewData['is_sensitive']?str_repeat('&bull;',8):$viewData['detail_value'],'Tags'=>$viewData['tags'],'Expiry Date'=>$viewData['expiry_date']?date('d M Y',strtotime($viewData['expiry_date'])):null,'Notes'=>$viewData['notes']];
                foreach ($fields as $label => $value): if ($value): ?>
                <div class="preview-field<?php echo (strlen($value)>60||$label==='Notes'||$label==='Value'?' full-width':''); ?>">
                    <div class="preview-field-label"><?php echo $label; ?></div>
                    <div class="preview-field-value"><?php echo $label==='Value'&&$viewData['is_sensitive']?$value:htmlspecialchars($value); ?></div>
                </div>
                <?php endif; endforeach; ?>
            </div>
        </div>
        <?php if (!empty($viewCustomValues)): ?>
        <div class="preview-section">
            <div class="preview-section-title"><i class="fas fa-puzzle-piece"></i> Custom Fields</div>
            <div class="preview-fields-grid">
                <?php foreach ($viewCustomValues as $cv): ?>
                <div class="preview-field<?php echo (in_array($cv['field_type'], $fileFieldTypes)||strlen($cv['field_value'])>60)?' full-width':''; ?>">
                    <div class="preview-field-label"><?php echo htmlspecialchars($cv['field_label']); ?></div>
                    <div class="preview-field-value"><?php renderFieldValueDisplay($cv, $fileFieldTypes); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <div class="modal-footer">
        <a href="<?php echo url('other-details.php?edit=' . $viewData['id']); ?>" class="btn btn-primary btn-sm"><i class="fas fa-edit"></i> Edit</a>
        <a href="<?php echo url('other-details.php'); ?>" class="btn btn-secondary btn-sm">Close</a>
    </div>
</div>
<script>document.getElementById('modalOverlay').classList.add('active');</script>
<?php } } ?>

<?php endif; /* end main if/else for folder vs grid view */ ?>

<!-- ========================================= -->
<!-- ADD/EDIT FOLDER MODAL -->
<!-- ========================================= -->
<div class="modal" id="addFolderModal" style="max-width:520px;">
    <div class="modal-header">
        <h3><i class="fas fa-folder-plus" style="margin-right:8px;color:var(--primary);"></i> <span id="folderModalTitle">Create Folder</span></h3>
        <button type="button" class="modal-close" onclick="closeModal('addFolderModal')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" action="<?php echo url('other-details.php'); ?>" id="folderForm">
<?php echo csrf_field(); ?>
        <input type="hidden" name="folder_action" id="folderActionInput" value="create_folder">
        <input type="hidden" name="folder_id" id="folderIdInput" value="">
        <div class="modal-body" style="padding:20px 24px;">
            <div class="form-group">
                <label>Folder Name <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="text" name="folder_name" id="folderNameInput" placeholder="e.g. License Details, Fee Details, Mark Sheets" required>
                    <i class="fas fa-folder"></i>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Icon</label>
                    <div class="input-wrapper">
                        <select name="folder_icon" id="folderIconInput">
                            <option value="fa-folder">📁 Folder</option>
                            <option value="fa-id-card">🪪 ID Card</option>
                            <option value="fa-graduation-cap">🎓 Education</option>
                            <option value="fa-file-invoice-dollar">💵 Fees/Invoice</option>
                            <option value="fa-car">🚗 Vehicle</option>
                            <option value="fa-certificate">📜 Certificate</option>
                            <option value="fa-heartbeat">❤️ Medical</option>
                            <option value="fa-home">🏠 Property</option>
                            <option value="fa-shield-alt">🛡️ Insurance</option>
                            <option value="fa-briefcase">💼 Work</option>
                            <option value="fa-passport">🛂 Passport</option>
                            <option value="fa-landmark">🏛️ Government</option>
                            <option value="fa-wallet">👛 Finance</option>
                            <option value="fa-plane">✈️ Travel</option>
                            <option value="fa-key">🔑 Keys/Access</option>
                            <option value="fa-star">⭐ Favourites</option>
                        </select>
                        <i class="fas fa-icons"></i>
                    </div>
                </div>
                <div class="form-group">
                    <label>Color</label>
                    <input type="color" name="folder_color" id="folderColorInput" value="#6C63FF" style="width:100%;height:42px;border:1px solid var(--border);border-radius:var(--radius-md);cursor:pointer;">
                </div>
            </div>
            <div class="form-group">
                <label>Description</label>
                <div class="input-wrapper">
                    <input type="text" name="folder_description" id="folderDescInput" placeholder="Optional short description">
                    <i class="fas fa-align-left"></i>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" onclick="closeModal('addFolderModal')">Cancel</button>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <span id="folderSubmitText">Create</span></button>
        </div>
    </form>
</div>

<script>
// Sensitive value toggle (legacy records)
document.querySelectorAll('.sensitive-value').forEach(function(el) {
    el.addEventListener('click', function() {
        var realValue = this.getAttribute('data-value');
        if (this.dataset.revealed === 'true') {
            this.innerHTML = '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022 <i class="fas fa-eye" style="margin-left:6px;font-size:0.8rem;color:var(--text-muted);"></i>';
            this.dataset.revealed = 'false';
        } else {
            this.textContent = realValue;
            var hideIcon = document.createElement('i');
            hideIcon.className = 'fas fa-eye-slash';
            hideIcon.style.cssText = 'margin-left:6px;font-size:0.8rem;color:var(--text-muted);';
            this.appendChild(hideIcon);
            this.dataset.revealed = 'true';
        }
    });
});

// Folder modal helpers
function resetFolderModal() {
    document.getElementById('folderModalTitle').textContent = 'Create Folder';
    document.getElementById('folderSubmitText').textContent = 'Create';
    document.getElementById('folderActionInput').value = 'create_folder';
    document.getElementById('folderIdInput').value = '';
    document.getElementById('folderNameInput').value = '';
    document.getElementById('folderIconInput').value = 'fa-folder';
    document.getElementById('folderColorInput').value = '#6C63FF';
    document.getElementById('folderDescInput').value = '';
}

// Create folder buttons
var createBtn = document.getElementById('createFolderBtn');
if (createBtn) createBtn.addEventListener('click', function() { resetFolderModal(); openModal('addFolderModal'); });
var createBtnEmpty = document.getElementById('createFolderBtnEmpty');
if (createBtnEmpty) createBtnEmpty.addEventListener('click', function() { resetFolderModal(); openModal('addFolderModal'); });

// Edit folder buttons
document.querySelectorAll('.edit-folder-btn').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var folderData = JSON.parse(this.getAttribute('data-folder'));
        document.getElementById('folderModalTitle').textContent = 'Edit Folder';
        document.getElementById('folderSubmitText').textContent = 'Update';
        document.getElementById('folderActionInput').value = 'update_folder';
        document.getElementById('folderIdInput').value = folderData.id;
        document.getElementById('folderNameInput').value = folderData.folder_name;
        document.getElementById('folderIconInput').value = folderData.folder_icon;
        document.getElementById('folderColorInput').value = folderData.folder_color;
        document.getElementById('folderDescInput').value = folderData.description || '';
        openModal('addFolderModal');
    });
});
</script>

<style>
.folder-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.15) !important;
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
