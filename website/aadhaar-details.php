<?php
$pageTitle = 'Aadhaar Details';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/custom_fields_helper.php';
require_once __DIR__ . '/includes/action_buttons_helper.php';
$user = requireAuth();
$db = Database::getInstance();

// Handle delete
if (isset($_GET['delete']) && is_numeric($_GET['delete']) && Auth::validateCSRF($_GET['csrf'] ?? '')) {
    $id = (int)$_GET['delete'];
    purgeActionButtonChildrenForRecord('aadhaar_details', $id, $user['id']);
    deleteCustomFieldFilesForRecord('aadhaar_details', $id);
    $db->delete("DELETE FROM aadhaar_details WHERE id = ? AND user_id = ?", [$id, $user['id']]);
    $db->insert("INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
        [$user['id'], 'delete', 'Deleted an Aadhaar record', $_SERVER['REMOTE_ADDR'] ?? '']);
    setFlash('success', 'Aadhaar record deleted successfully');
    redirect('/aadhaar-details.php');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $editId = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : null;
    $data = [
        'holder_name'       => sanitize($_POST['holder_name'] ?? ''),
        'aadhaar_number'    => sanitize($_POST['aadhaar_number'] ?? ''),
        'date_of_birth'     => !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null,
        'gender'            => $_POST['gender'] ?? null,
        'address'           => sanitize($_POST['address'] ?? ''),
        'pin_code'          => sanitize($_POST['pin_code'] ?? ''),
        'mobile_linked'     => sanitize($_POST['mobile_linked'] ?? ''),
        'email_linked'      => sanitize($_POST['email_linked'] ?? ''),
        'enrollment_number' => sanitize($_POST['enrollment_number'] ?? ''),
        'vid_number'        => sanitize($_POST['vid_number'] ?? ''),
        'issue_date'        => !empty($_POST['issue_date']) ? $_POST['issue_date'] : null,
        'is_verified'       => isset($_POST['is_verified']) ? 1 : 0,
        'notes'             => sanitize($_POST['notes'] ?? ''),
    ];

    // Format aadhaar number
    $aadhaarClean = preg_replace('/[^0-9]/', '', $data['aadhaar_number']);
    if (strlen($aadhaarClean) === 12) {
        $data['aadhaar_number'] = substr($aadhaarClean, 0, 4) . '-' . substr($aadhaarClean, 4, 4) . '-' . substr($aadhaarClean, 8, 4);
    }

    if (empty($data['holder_name']) || empty($aadhaarClean)) {
        setFlash('error', 'Holder name and Aadhaar number are required');
    } elseif (strlen($aadhaarClean) !== 12) {
        setFlash('error', 'Aadhaar number must be exactly 12 digits');
    } else {
        if ($editId) {
            $db->update(
                "UPDATE aadhaar_details SET holder_name=?, aadhaar_number=?, date_of_birth=?, gender=?,
                 address=?, pin_code=?, mobile_linked=?, email_linked=?, enrollment_number=?, vid_number=?,
                 issue_date=?, is_verified=?, notes=? WHERE id=? AND user_id=?",
                array_merge(array_values($data), [$editId, $user['id']])
            );
            saveCustomFieldEntries($user['id'], 'aadhaar_details', $editId);
            $db->insert("INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
                [$user['id'], 'update', 'Updated Aadhaar for: ' . $data['holder_name'], $_SERVER['REMOTE_ADDR'] ?? '']);
            setFlash('success', 'Aadhaar record updated successfully');
        } else {
            $newId = $db->insert(
                "INSERT INTO aadhaar_details (user_id, holder_name, aadhaar_number, date_of_birth, gender,
                 address, pin_code, mobile_linked, email_linked, enrollment_number, vid_number, issue_date,
                 is_verified, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                array_merge([$user['id']], array_values($data))
            );
            saveCustomFieldEntries($user['id'], 'aadhaar_details', $newId);
            $db->insert("INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
                [$user['id'], 'create', 'Added Aadhaar for: ' . $data['holder_name'], $_SERVER['REMOTE_ADDR'] ?? '']);
            setFlash('success', 'Aadhaar record added successfully');
        }
        redirect('/aadhaar-details.php');
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
    $where .= " AND (holder_name LIKE ? OR aadhaar_number LIKE ? OR mobile_linked LIKE ?)";
    $searchLike = "%$search%";
    $params = array_merge($params, [$searchLike, $searchLike, $searchLike]);
}

$totalCount = $db->fetch("SELECT COUNT(*) as count FROM aadhaar_details $where", $params);
$totalPages = ceil($totalCount['count'] / $perPage);
$records = $db->fetchAll("SELECT * FROM aadhaar_details $where ORDER BY updated_at DESC LIMIT $perPage OFFSET $offset", $params);

// Check if editing
$editData = null;
$editCustomValues = [];
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $editData = $db->fetch("SELECT * FROM aadhaar_details WHERE id = ? AND user_id = ?", [(int)$_GET['edit'], $user['id']]);
    if ($editData) {
        $editCustomValues = getCustomFieldValues('aadhaar_details', $editData['id']);
    }
}
$showForm = (isset($_GET['action']) && $_GET['action'] === 'add') || $editData;
$customFields = getCustomFieldsFor('aadhaar_details', $user['id']);

function maskAadhaar($num) {
    $clean = preg_replace('/[^0-9]/', '', $num);
    if (strlen($clean) === 12) {
        return 'XXXX-XXXX-' . substr($clean, 8, 4);
    }
    return $num;
}
?>

<!-- Toolbar -->
<div class="toolbar">
    <div class="toolbar-left">
        <?php if (!$showForm): ?>
        <div class="search-box" style="width: auto;">
            <i class="fas fa-search"></i>
            <form method="GET" style="display:inline;">
                <input type="text" name="search" placeholder="Search Aadhaar records..." 
                       value="<?php echo htmlspecialchars($search); ?>" style="width: 250px;">
            </form>
        </div>
        <?php else: ?>
        <a href="<?php echo url('aadhaar-details.php'); ?>" class="btn btn-ghost">
            <i class="fas fa-arrow-left"></i> Back to List
        </a>
        <?php endif; ?>
    </div>
    <div class="toolbar-right">
        <?php if (!$showForm): ?>
        <a href="<?php echo url('aadhaar-details.php?action=add'); ?>" class="btn btn-primary">
            <i class="fas fa-plus"></i> Add Aadhaar
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
            <?php echo $editData ? 'Edit Aadhaar Record' : 'Add Aadhaar Record'; ?>
        </h2>
    </div>

    <form method="POST" action="<?php echo url('aadhaar-details.php'); ?>" id="aadhaarForm" enctype="multipart/form-data">
<?php echo csrf_field(); ?>
        <?php if ($editData): ?>
            <input type="hidden" name="edit_id" value="<?php echo $editData['id']; ?>">
        <?php endif; ?>

        <h3 style="font-size: 1rem; color: var(--primary); margin-bottom: 16px;">
            <i class="fas fa-id-card"></i> Aadhaar Information
        </h3>

        <div class="form-row">
            <div class="form-group">
                <label>Name as per Aadhaar <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="text" name="holder_name" placeholder="Full name on Aadhaar card" required
                           value="<?php echo htmlspecialchars($editData['holder_name'] ?? ''); ?>">
                    <i class="fas fa-user"></i>
                </div>
            </div>
            <div class="form-group">
                <label>Aadhaar Number <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="text" name="aadhaar_number" placeholder="XXXX-XXXX-XXXX" required maxlength="14"
                           value="<?php echo htmlspecialchars($editData['aadhaar_number'] ?? ''); ?>"
                           pattern="[0-9\-]{12,14}" title="Enter 12-digit Aadhaar number">
                    <i class="fas fa-fingerprint"></i>
                </div>
                <small style="color: var(--text-muted);">12-digit unique identification number</small>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Date of Birth</label>
                <div class="input-wrapper">
                    <input type="date" name="date_of_birth"
                           value="<?php echo htmlspecialchars($editData['date_of_birth'] ?? ''); ?>">
                    <i class="fas fa-calendar"></i>
                </div>
            </div>
            <div class="form-group">
                <label>Gender</label>
                <div class="input-wrapper">
                    <select name="gender">
                        <option value="">Select Gender</option>
                        <option value="male" <?php echo ($editData['gender'] ?? '') === 'male' ? 'selected' : ''; ?>>Male</option>
                        <option value="female" <?php echo ($editData['gender'] ?? '') === 'female' ? 'selected' : ''; ?>>Female</option>
                        <option value="other" <?php echo ($editData['gender'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                    </select>
                    <i class="fas fa-venus-mars"></i>
                </div>
            </div>
        </div>

        <h3 style="font-size: 1rem; color: var(--success); margin: 24px 0 16px;">
            <i class="fas fa-map-marker-alt"></i> Address & Contact
        </h3>

        <div class="form-group">
            <label>Address</label>
            <div class="input-wrapper">
                <textarea name="address" rows="3" placeholder="Full address as per Aadhaar"><?php echo htmlspecialchars($editData['address'] ?? ''); ?></textarea>
                <i class="fas fa-home" style="top: 14px;"></i>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>PIN Code</label>
                <div class="input-wrapper">
                    <input type="text" name="pin_code" placeholder="6-digit PIN code" maxlength="6"
                           value="<?php echo htmlspecialchars($editData['pin_code'] ?? ''); ?>">
                    <i class="fas fa-map-pin"></i>
                </div>
            </div>
            <div class="form-group">
                <label>Linked Mobile</label>
                <div class="input-wrapper">
                    <input type="tel" name="mobile_linked" placeholder="Mobile number linked to Aadhaar"
                           value="<?php echo htmlspecialchars($editData['mobile_linked'] ?? ''); ?>">
                    <i class="fas fa-mobile-alt"></i>
                </div>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Linked Email</label>
                <div class="input-wrapper">
                    <input type="email" name="email_linked" placeholder="Email linked to Aadhaar"
                           value="<?php echo htmlspecialchars($editData['email_linked'] ?? ''); ?>">
                    <i class="fas fa-envelope"></i>
                </div>
            </div>
            <div class="form-group">
                <label>Enrollment Number</label>
                <div class="input-wrapper">
                    <input type="text" name="enrollment_number" placeholder="Enrollment / Acknowledgement No."
                           value="<?php echo htmlspecialchars($editData['enrollment_number'] ?? ''); ?>">
                    <i class="fas fa-receipt"></i>
                </div>
            </div>
        </div>

        <h3 style="font-size: 1rem; color: var(--warning); margin: 24px 0 16px;">
            <i class="fas fa-shield-halved"></i> Additional Details
        </h3>

        <div class="form-row">
            <div class="form-group">
                <label>Virtual ID (VID)</label>
                <div class="input-wrapper">
                    <input type="text" name="vid_number" placeholder="16-digit Virtual ID"
                           value="<?php echo htmlspecialchars($editData['vid_number'] ?? ''); ?>">
                    <i class="fas fa-key"></i>
                </div>
                <small style="color: var(--text-muted);">Temporary 16-digit number mapped to Aadhaar</small>
            </div>
            <div class="form-group">
                <label>Issue Date</label>
                <div class="input-wrapper">
                    <input type="date" name="issue_date"
                           value="<?php echo htmlspecialchars($editData['issue_date'] ?? ''); ?>">
                    <i class="fas fa-calendar-check"></i>
                </div>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group" style="display: flex; align-items: center; padding-top: 8px;">
                <label class="form-check" style="margin-bottom: 0;">
                    <input type="checkbox" name="is_verified" <?php echo ($editData['is_verified'] ?? 0) ? 'checked' : ''; ?>>
                    <span class="checkmark"></span>
                    <span class="form-check-label">Aadhaar is Verified</span>
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

        <?php renderCustomFields($customFields ?? [], $editCustomValues ?? [], 'Custom Fields', 'aadhaar_details', $user['id']); ?>

        <div class="form-actions">
            <a href="<?php echo url('aadhaar-details.php'); ?>" class="btn btn-ghost">Cancel</a>
            <button type="button" class="btn btn-preview" onclick="showFormPreview('aadhaarForm')">
                <i class="fas fa-eye"></i> Preview
            </button>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> <?php echo $editData ? 'Update Record' : 'Save Record'; ?>
            </button>
        </div>
    </form>
</div>

<?php else: ?>
<!-- Aadhaar Records List -->
<?php if (empty($records)): ?>
    <div class="empty-state card">
        <i class="fas fa-id-card"></i>
        <h3>No Aadhaar records yet</h3>
        <p>Store your Aadhaar card details securely</p>
        <a href="<?php echo url('aadhaar-details.php?action=add'); ?>" class="btn btn-primary">
            <i class="fas fa-plus"></i> Add Aadhaar
        </a>
    </div>
<?php else: ?>
    <div class="details-grid">
        <?php foreach ($records as $record): ?>
        <div class="detail-card animate-fade-in">
            <div class="card-menu">
                <div style="display: flex; gap: 4px;">
                    <a href="<?php echo url('aadhaar-details.php?edit=' . $record['id']); ?>" class="btn btn-icon btn-ghost" title="Edit">
                        <i class="fas fa-pen"></i>
                    </a>
                    <a href="<?php echo url('aadhaar-details.php?view=' . $record['id']); ?>" class="btn btn-icon btn-ghost" title="View">
                        <i class="fas fa-eye"></i>
                    </a>
                    <button class="btn btn-icon btn-ghost" title="Delete" style="color: var(--danger);"
                            onclick="showConfirm('Delete Aadhaar Record', 'Are you sure you want to delete this Aadhaar record? This action cannot be undone.', function() { window.location.href='<?php echo url("aadhaar-details.php?delete=" . $record['id'] . "&csrf=" . urlencode(Auth::generateCSRF())); ?>'; })">
                        <i class="fas fa-trash"></i>
                    </button>
                    <?php renderActionButtonsInline('aadhaar_details', $record['id'], $user['id']); ?>
                </div>
            </div>

            <div class="detail-card-header">
                <div class="detail-avatar" style="background: linear-gradient(135deg, #667eea, #764ba2);">
                    <i class="fas fa-fingerprint"></i>
                </div>
                <div>
                    <div class="detail-name"><?php echo htmlspecialchars($record['holder_name']); ?></div>
                    <div class="detail-email" style="font-family: monospace; letter-spacing: 1px;"><?php echo maskAadhaar($record['aadhaar_number']); ?></div>
                </div>
            </div>

            <div style="display: flex; gap: 6px; margin: -8px 0 14px;">
                <?php if ($record['is_verified']): ?>
                <span class="badge badge-success"><i class="fas fa-check-circle"></i> Verified</span>
                <?php else: ?>
                <span class="badge badge-warning"><i class="fas fa-clock"></i> Unverified</span>
                <?php endif; ?>
            </div>

            <div class="detail-info">
                <div class="detail-info-item">
                    <span class="label">DOB</span>
                    <span class="value"><?php echo $record['date_of_birth'] ? date('d M Y', strtotime($record['date_of_birth'])) : '-'; ?></span>
                </div>
                <div class="detail-info-item">
                    <span class="label">Gender</span>
                    <span class="value"><?php echo $record['gender'] ? ucfirst($record['gender']) : '-'; ?></span>
                </div>
                <div class="detail-info-item">
                    <span class="label">Mobile</span>
                    <span class="value"><?php echo htmlspecialchars($record['mobile_linked'] ?: '-'); ?></span>
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
    $viewData = $db->fetch("SELECT * FROM aadhaar_details WHERE id = ? AND user_id = ?", [(int)$_GET['view'], $user['id']]);
    if ($viewData) {
        try {
            $viewCustomValues = $db->fetchAll(
                "SELECT cf.field_label, cf.field_type, cfe.field_value FROM custom_field_entries cfe 
                 JOIN custom_fields cf ON cf.id = cfe.custom_field_id 
                 WHERE cfe.linked_to = 'aadhaar_details' AND cfe.record_id = ? AND cfe.field_value != ''",
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
        <a href="<?php echo url('aadhaar-details.php'); ?>" class="modal-close"><i class="fas fa-times"></i></a>
    </div>
    <div class="modal-body" style="padding: 24px 28px;">
        <div style="text-align: center; margin-bottom: 24px;">
            <div class="detail-avatar" style="background: linear-gradient(135deg, #667eea, #764ba2); width: 64px; height: 64px; font-size: 1.4rem; margin: 0 auto 12px;">
                <i class="fas fa-fingerprint"></i>
            </div>
            <h2><?php echo htmlspecialchars($viewData['holder_name']); ?></h2>
            <p style="color: var(--text-secondary); font-family: monospace; letter-spacing: 1px;"><?php echo maskAadhaar($viewData['aadhaar_number']); ?></p>
        </div>

        <div class="preview-section">
            <div class="preview-section-title"><i class="fas fa-info-circle"></i> Details</div>
            <div class="preview-fields-grid">
                <?php 
                $fields = [
                    'Aadhaar Number' => $viewData['aadhaar_number'],
                    'Date of Birth' => $viewData['date_of_birth'] ? date('d M Y', strtotime($viewData['date_of_birth'])) : null,
                    'Gender' => $viewData['gender'] ? ucfirst($viewData['gender']) : null,
                    'Address' => $viewData['address'],
                    'PIN Code' => $viewData['pin_code'],
                    'Mobile Linked' => $viewData['mobile_linked'],
                    'Email Linked' => $viewData['email_linked'],
                    'Enrollment No.' => $viewData['enrollment_number'],
                    'VID Number' => $viewData['vid_number'],
                    'Issue Date' => $viewData['issue_date'] ? date('d M Y', strtotime($viewData['issue_date'])) : null,
                    'Verified' => $viewData['is_verified'] ? 'Yes' : 'No',
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
        <a href="<?php echo url('aadhaar-details.php?edit=' . $viewData['id']); ?>" class="btn btn-primary btn-sm">
            <i class="fas fa-edit"></i> Edit
        </a>
        <a href="<?php echo url('aadhaar-details.php'); ?>" class="btn btn-secondary btn-sm">Close</a>
    </div>
</div>
<script>document.getElementById('modalOverlay').classList.add('active');</script>
<?php } } ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
