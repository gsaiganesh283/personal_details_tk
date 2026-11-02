<?php
$pageTitle = 'PAN Details';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/custom_fields_helper.php';
require_once __DIR__ . '/includes/action_buttons_helper.php';
$user = requireAuth();
$db = Database::getInstance();

// Handle delete
if (isset($_GET['delete']) && is_numeric($_GET['delete']) && Auth::validateCSRF($_GET['csrf'] ?? '')) {
    $id = (int)$_GET['delete'];
    purgeActionButtonChildrenForRecord('pan_details', $id, $user['id']);
    deleteCustomFieldFilesForRecord('pan_details', $id);
    $db->delete("DELETE FROM pan_details WHERE id = ? AND user_id = ?", [$id, $user['id']]);
    $db->insert("INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
        [$user['id'], 'delete', 'Deleted a PAN record', $_SERVER['REMOTE_ADDR'] ?? '']);
    setFlash('success', 'PAN record deleted successfully');
    redirect('/pan-details.php');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $editId = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : null;
    $data = [
        'holder_name'       => sanitize($_POST['holder_name'] ?? ''),
        'pan_number'        => strtoupper(sanitize($_POST['pan_number'] ?? '')),
        'father_name'       => sanitize($_POST['father_name'] ?? ''),
        'date_of_birth'     => !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null,
        'category'          => $_POST['category'] ?? 'individual',
        'mobile_number'     => sanitize($_POST['mobile_number'] ?? ''),
        'email'             => sanitize($_POST['email'] ?? ''),
        'address'           => sanitize($_POST['address'] ?? ''),
        'issue_date'        => !empty($_POST['issue_date']) ? $_POST['issue_date'] : null,
        'is_linked_aadhaar' => isset($_POST['is_linked_aadhaar']) ? 1 : 0,
        'status'            => $_POST['status'] ?? 'active',
        'notes'             => sanitize($_POST['notes'] ?? ''),
    ];

    if (empty($data['holder_name']) || empty($data['pan_number'])) {
        setFlash('error', 'Holder name and PAN number are required');
    } elseif (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $data['pan_number'])) {
        setFlash('error', 'Invalid PAN format. Expected format: ABCDE1234F');
    } else {
        if ($editId) {
            $db->update(
                "UPDATE pan_details SET holder_name=?, pan_number=?, father_name=?, date_of_birth=?,
                 category=?, mobile_number=?, email=?, address=?, issue_date=?, is_linked_aadhaar=?,
                 status=?, notes=? WHERE id=? AND user_id=?",
                array_merge(array_values($data), [$editId, $user['id']])
            );
            saveCustomFieldEntries($user['id'], 'pan_details', $editId);
            $db->insert("INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
                [$user['id'], 'update', 'Updated PAN for: ' . $data['holder_name'], $_SERVER['REMOTE_ADDR'] ?? '']);
            setFlash('success', 'PAN record updated successfully');
        } else {
            $newId = $db->insert(
                "INSERT INTO pan_details (user_id, holder_name, pan_number, father_name, date_of_birth,
                 category, mobile_number, email, address, issue_date, is_linked_aadhaar, status, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                array_merge([$user['id']], array_values($data))
            );
            saveCustomFieldEntries($user['id'], 'pan_details', $newId);
            $db->insert("INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
                [$user['id'], 'create', 'Added PAN for: ' . $data['holder_name'], $_SERVER['REMOTE_ADDR'] ?? '']);
            setFlash('success', 'PAN record added successfully');
        }
        redirect('/pan-details.php');
    }
}

// Get data
$search = sanitize($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;
$offset = ($page - 1) * $perPage;

$where = "WHERE user_id = ?";
$params = [$user['id']];

if ($search) {
    $where .= " AND (holder_name LIKE ? OR pan_number LIKE ? OR father_name LIKE ?)";
    $searchLike = "%$search%";
    $params = array_merge($params, [$searchLike, $searchLike, $searchLike]);
}

$totalCount = $db->fetch("SELECT COUNT(*) as count FROM pan_details $where", $params);
$totalPages = ceil($totalCount['count'] / $perPage);
$records = $db->fetchAll("SELECT * FROM pan_details $where ORDER BY updated_at DESC LIMIT $perPage OFFSET $offset", $params);

// Check if editing
$editData = null;
$editCustomValues = [];
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $editData = $db->fetch("SELECT * FROM pan_details WHERE id = ? AND user_id = ?", [(int)$_GET['edit'], $user['id']]);
    if ($editData) {
        $editCustomValues = getCustomFieldValues('pan_details', $editData['id']);
    }
}
$showForm = (isset($_GET['action']) && $_GET['action'] === 'add') || $editData;
$customFields = getCustomFieldsFor('pan_details', $user['id']);

function maskPAN($pan) {
    if (strlen($pan) === 10) {
        return substr($pan, 0, 2) . 'XXXXX' . substr($pan, 7, 3);
    }
    return $pan;
}

function getPanCategory($code) {
    $cats = [
        'individual' => 'Individual', 'huf' => 'HUF', 'company' => 'Company',
        'firm' => 'Partnership Firm', 'aop' => 'Association of Persons',
        'trust' => 'Trust', 'government' => 'Government', 'other' => 'Other'
    ];
    return $cats[$code] ?? ucfirst($code);
}
?>

<!-- Toolbar -->
<div class="toolbar">
    <div class="toolbar-left">
        <?php if (!$showForm): ?>
        <div class="search-box" style="width: auto;">
            <i class="fas fa-search"></i>
            <form method="GET" style="display:inline;">
                <input type="text" name="search" placeholder="Search PAN records..." 
                       value="<?php echo htmlspecialchars($search); ?>" style="width: 250px;">
            </form>
        </div>
        <?php else: ?>
        <a href="<?php echo url('pan-details.php'); ?>" class="btn btn-ghost">
            <i class="fas fa-arrow-left"></i> Back to List
        </a>
        <?php endif; ?>
    </div>
    <div class="toolbar-right">
        <?php if (!$showForm): ?>
        <a href="<?php echo url('pan-details.php?action=add'); ?>" class="btn btn-primary">
            <i class="fas fa-plus"></i> Add PAN
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($showForm): ?>
<!-- Add/Edit Form -->
<div class="card animate-fade-in">
    <div class="card-header">
        <h2>
            <i class="fas fa-<?php echo $editData ? 'edit' : 'plus'; ?>" style="margin-right: 8px; color: var(--primary);"></i>
            <?php echo $editData ? 'Edit PAN Record' : 'Add PAN Record'; ?>
        </h2>
    </div>

    <form method="POST" action="<?php echo url('pan-details.php'); ?>" id="panForm" enctype="multipart/form-data">
<?php echo csrf_field(); ?>
        <?php if ($editData): ?>
            <input type="hidden" name="edit_id" value="<?php echo $editData['id']; ?>">
        <?php endif; ?>

        <h3 style="font-size: 1rem; color: var(--primary); margin-bottom: 16px;">
            <i class="fas fa-id-card-alt"></i> PAN Card Information
        </h3>

        <div class="form-row">
            <div class="form-group">
                <label>Name as per PAN <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="text" name="holder_name" placeholder="Full name on PAN card" required
                           value="<?php echo htmlspecialchars($editData['holder_name'] ?? ''); ?>">
                    <i class="fas fa-user"></i>
                </div>
            </div>
            <div class="form-group">
                <label>PAN Number <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="text" name="pan_number" placeholder="ABCDE1234F" required maxlength="10"
                           value="<?php echo htmlspecialchars($editData['pan_number'] ?? ''); ?>"
                           style="text-transform: uppercase; font-family: monospace; letter-spacing: 2px;"
                           pattern="[A-Za-z]{5}[0-9]{4}[A-Za-z]" title="Format: ABCDE1234F">
                    <i class="fas fa-credit-card"></i>
                </div>
                <small style="color: var(--text-muted);">Format: 5 letters + 4 digits + 1 letter (e.g. ABCDE1234F)</small>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Father's Name</label>
                <div class="input-wrapper">
                    <input type="text" name="father_name" placeholder="Father's name"
                           value="<?php echo htmlspecialchars($editData['father_name'] ?? ''); ?>">
                    <i class="fas fa-user-tie"></i>
                </div>
            </div>
            <div class="form-group">
                <label>Date of Birth</label>
                <div class="input-wrapper">
                    <input type="date" name="date_of_birth"
                           value="<?php echo htmlspecialchars($editData['date_of_birth'] ?? ''); ?>">
                    <i class="fas fa-calendar"></i>
                </div>
            </div>
        </div>

        <h3 style="font-size: 1rem; color: var(--success); margin: 24px 0 16px;">
            <i class="fas fa-info-circle"></i> Classification & Contact
        </h3>

        <div class="form-row">
            <div class="form-group">
                <label>Category</label>
                <div class="input-wrapper">
                    <select name="category">
                        <?php
                        $categories = ['individual'=>'Individual (P)', 'huf'=>'Hindu Undivided Family (H)', 'company'=>'Company (C)',
                            'firm'=>'Partnership Firm (F)', 'aop'=>'Association of Persons (A)', 'trust'=>'Trust (T)',
                            'government'=>'Government (G)', 'other'=>'Other'];
                        foreach ($categories as $val => $label):
                        ?>
                        <option value="<?php echo $val; ?>" <?php echo ($editData['category'] ?? 'individual') === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <i class="fas fa-layer-group"></i>
                </div>
            </div>
            <div class="form-group">
                <label>Status</label>
                <div class="input-wrapper">
                    <select name="status">
                        <option value="active" <?php echo ($editData['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo ($editData['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="surrendered" <?php echo ($editData['status'] ?? '') === 'surrendered' ? 'selected' : ''; ?>>Surrendered</option>
                    </select>
                    <i class="fas fa-toggle-on"></i>
                </div>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Mobile Number</label>
                <div class="input-wrapper">
                    <input type="tel" name="mobile_number" placeholder="Registered mobile"
                           value="<?php echo htmlspecialchars($editData['mobile_number'] ?? ''); ?>">
                    <i class="fas fa-mobile-alt"></i>
                </div>
            </div>
            <div class="form-group">
                <label>Email</label>
                <div class="input-wrapper">
                    <input type="email" name="email" placeholder="Registered email"
                           value="<?php echo htmlspecialchars($editData['email'] ?? ''); ?>">
                    <i class="fas fa-envelope"></i>
                </div>
            </div>
        </div>

        <div class="form-group">
            <label>Address</label>
            <div class="input-wrapper">
                <textarea name="address" rows="2" placeholder="Address on PAN card"><?php echo htmlspecialchars($editData['address'] ?? ''); ?></textarea>
                <i class="fas fa-home" style="top: 14px;"></i>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Issue Date</label>
                <div class="input-wrapper">
                    <input type="date" name="issue_date"
                           value="<?php echo htmlspecialchars($editData['issue_date'] ?? ''); ?>">
                    <i class="fas fa-calendar-check"></i>
                </div>
            </div>
            <div class="form-group" style="display: flex; align-items: center; padding-top: 28px;">
                <label class="form-check" style="margin-bottom: 0;">
                    <input type="checkbox" name="is_linked_aadhaar" <?php echo ($editData['is_linked_aadhaar'] ?? 0) ? 'checked' : ''; ?>>
                    <span class="checkmark"></span>
                    <span class="form-check-label">Linked with Aadhaar</span>
                </label>
            </div>
        </div>

        <div class="form-group">
            <label>Notes</label>
            <div class="input-wrapper">
                <textarea name="notes" rows="2" placeholder="Any additional notes..."><?php echo htmlspecialchars($editData['notes'] ?? ''); ?></textarea>
                <i class="fas fa-sticky-note" style="top: 14px;"></i>
            </div>
        </div>

        <?php renderCustomFields($customFields ?? [], $editCustomValues ?? [], 'Custom Fields', 'pan_details', $user['id']); ?>

        <div class="form-actions">
            <a href="<?php echo url('pan-details.php'); ?>" class="btn btn-ghost">Cancel</a>
            <button type="button" class="btn btn-preview" onclick="showFormPreview('panForm')">
                <i class="fas fa-eye"></i> Preview
            </button>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> <?php echo $editData ? 'Update Record' : 'Save Record'; ?>
            </button>
        </div>
    </form>
</div>

<?php else: ?>
<!-- PAN Records List -->
<?php if (empty($records)): ?>
    <div class="empty-state card">
        <i class="fas fa-credit-card"></i>
        <h3>No PAN records yet</h3>
        <p>Store your PAN card details securely</p>
        <a href="<?php echo url('pan-details.php?action=add'); ?>" class="btn btn-primary">
            <i class="fas fa-plus"></i> Add PAN
        </a>
    </div>
<?php else: ?>
    <div class="details-grid">
        <?php foreach ($records as $record): ?>
        <div class="detail-card animate-fade-in">
            <div class="card-menu">
                <div style="display: flex; gap: 4px;">
                    <a href="<?php echo url('pan-details.php?edit=' . $record['id']); ?>" class="btn btn-icon btn-ghost" title="Edit">
                        <i class="fas fa-pen"></i>
                    </a>
                    <a href="<?php echo url('pan-details.php?view=' . $record['id']); ?>" class="btn btn-icon btn-ghost" title="View">
                        <i class="fas fa-eye"></i>
                    </a>
                    <button class="btn btn-icon btn-ghost" title="Delete" style="color: var(--danger);"
                            onclick="showConfirm('Delete PAN Record', 'Are you sure you want to delete this PAN record? This action cannot be undone.', function() { window.location.href='<?php echo url("pan-details.php?delete=" . $record['id'] . "&csrf=" . urlencode(Auth::generateCSRF())); ?>'; })">
                        <i class="fas fa-trash"></i>
                    </button>
                    <?php renderActionButtonsInline('pan_details', $record['id'], $user['id']); ?>
                </div>
            </div>

            <div class="detail-card-header">
                <div class="detail-avatar" style="background: linear-gradient(135deg, #f093fb, #f5576c);">
                    <i class="fas fa-credit-card"></i>
                </div>
                <div>
                    <div class="detail-name"><?php echo htmlspecialchars($record['holder_name']); ?></div>
                    <div class="detail-email" style="font-family: monospace; letter-spacing: 2px; font-weight: 600;"><?php echo maskPAN($record['pan_number']); ?></div>
                </div>
            </div>

            <div style="display: flex; gap: 6px; flex-wrap: wrap; margin: -8px 0 14px;">
                <span class="badge badge-<?php echo $record['status'] === 'active' ? 'success' : ($record['status'] === 'inactive' ? 'warning' : 'danger'); ?>">
                    <?php echo ucfirst($record['status']); ?>
                </span>
                <?php if ($record['is_linked_aadhaar']): ?>
                <span class="badge badge-success"><i class="fas fa-check"></i> Aadhaar Linked</span>
                <?php else: ?>
                <span class="badge badge-warning"><i class="fas fa-times"></i> Aadhaar Not Linked</span>
                <?php endif; ?>
            </div>

            <div class="detail-info">
                <div class="detail-info-item">
                    <span class="label">Category</span>
                    <span class="value"><?php echo getPanCategory($record['category']); ?></span>
                </div>
                <div class="detail-info-item">
                    <span class="label">Father</span>
                    <span class="value"><?php echo htmlspecialchars($record['father_name'] ?: '-'); ?></span>
                </div>
                <div class="detail-info-item">
                    <span class="label">Mobile</span>
                    <span class="value"><?php echo htmlspecialchars($record['mobile_number'] ?: '-'); ?></span>
                </div>
                <div class="detail-info-item">
                    <span class="label">Updated</span>
                    <span class="value"><?php echo timeAgo($record['updated_at']); ?></span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
        <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>" class="pagination-btn"><i class="fas fa-chevron-left"></i></a>
        <?php endif; ?>
        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
        <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>" class="pagination-btn <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
        <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>" class="pagination-btn"><i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>
<?php endif; ?>

<!-- View Detail Modal -->
<?php
if (isset($_GET['view']) && is_numeric($_GET['view'])) {
    $viewData = $db->fetch("SELECT * FROM pan_details WHERE id = ? AND user_id = ?", [(int)$_GET['view'], $user['id']]);
    if ($viewData) {
        try {
            $viewCustomValues = $db->fetchAll(
                "SELECT cf.field_label, cf.field_type, cfe.field_value FROM custom_field_entries cfe 
                 JOIN custom_fields cf ON cf.id = cfe.custom_field_id 
                 WHERE cfe.linked_to = 'pan_details' AND cfe.record_id = ? AND cfe.field_value != ''",
                [$viewData['id']]
            );
        } catch (Exception $e) {
            $viewCustomValues = [];
        }
        $fileFieldTypes = getFileFieldTypes();
?>
<div class="modal view-modal active" id="viewModal">
    <div class="modal-header">
        <h3><i class="fas fa-eye" style="margin-right: 8px; color: var(--primary);"></i> View Details</h3>
        <a href="<?php echo url('pan-details.php'); ?>" class="modal-close"><i class="fas fa-times"></i></a>
    </div>
    <div class="modal-body" style="padding: 24px 28px;">
        <div style="text-align: center; margin-bottom: 24px;">
            <div class="detail-avatar" style="background: linear-gradient(135deg, #f093fb, #f5576c); width: 64px; height: 64px; font-size: 1.4rem; margin: 0 auto 12px;">
                <i class="fas fa-credit-card"></i>
            </div>
            <h2><?php echo htmlspecialchars($viewData['holder_name']); ?></h2>
            <p style="color: var(--text-secondary); font-family: monospace; letter-spacing: 2px; font-weight: 600;"><?php echo maskPAN($viewData['pan_number']); ?></p>
        </div>

        <div class="preview-section">
            <div class="preview-section-title"><i class="fas fa-info-circle"></i> Details</div>
            <div class="preview-fields-grid">
                <?php 
                $fields = [
                    'PAN Number' => $viewData['pan_number'],
                    'Father\'s Name' => $viewData['father_name'],
                    'Date of Birth' => $viewData['date_of_birth'] ? date('d M Y', strtotime($viewData['date_of_birth'])) : null,
                    'Category' => getPanCategory($viewData['category']),
                    'Mobile Number' => $viewData['mobile_number'],
                    'Email' => $viewData['email'],
                    'Address' => $viewData['address'],
                    'Issue Date' => $viewData['issue_date'] ? date('d M Y', strtotime($viewData['issue_date'])) : null,
                    'Aadhaar Linked' => $viewData['is_linked_aadhaar'] ? 'Yes' : 'No',
                    'Status' => ucfirst($viewData['status']),
                    'Notes' => $viewData['notes'],
                ];
                foreach ($fields as $label => $value): ?>
                <div class="preview-field<?php echo (!$value ? '' : (strlen($value) > 60 || $label === 'Notes' || $label === 'Address' ? ' full-width' : '')); ?>">
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
    <div class="modal-footer">
        <a href="<?php echo url('pan-details.php?edit=' . $viewData['id']); ?>" class="btn btn-primary btn-sm">
            <i class="fas fa-edit"></i> Edit
        </a>
        <a href="<?php echo url('pan-details.php'); ?>" class="btn btn-secondary btn-sm">Close</a>
    </div>
</div>
<script>document.getElementById('modalOverlay').classList.add('active');</script>
<?php } } ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
