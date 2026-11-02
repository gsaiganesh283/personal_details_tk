<?php
$pageTitle = 'Personal Details';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/custom_fields_helper.php';
require_once __DIR__ . '/includes/action_buttons_helper.php';
$user = requireAuth();
$db = Database::getInstance();

// Handle delete
if (isset($_GET['delete']) && is_numeric($_GET['delete']) && Auth::validateCSRF($_GET['csrf'] ?? '')) {
    $id = (int)$_GET['delete'];
    purgeActionButtonChildrenForRecord('personal_details', $id, $user['id']);
    deleteCustomFieldFilesForRecord('personal_details', $id);
    $db->delete("DELETE FROM personal_details WHERE id = ? AND user_id = ?", [$id, $user['id']]);
    setFlash('success', 'Record deleted successfully');
    redirect('/personal-details.php');
}

// Handle form submission (add/edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $editId = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : null;

    $data = [
        'first_name' => sanitize($_POST['first_name'] ?? ''),
        'last_name' => sanitize($_POST['last_name'] ?? ''),
        'date_of_birth' => !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null,
        'gender' => $_POST['gender'] ?? null,
        'phone' => sanitize($_POST['phone'] ?? ''),
        'alternate_phone' => sanitize($_POST['alternate_phone'] ?? ''),
        'email' => sanitize($_POST['detail_email'] ?? ''),
        'address_line1' => sanitize($_POST['address_line1'] ?? ''),
        'address_line2' => sanitize($_POST['address_line2'] ?? ''),
        'city' => sanitize($_POST['city'] ?? ''),
        'state' => sanitize($_POST['state'] ?? ''),
        'country' => sanitize($_POST['country'] ?? ''),
        'zip_code' => sanitize($_POST['zip_code'] ?? ''),
        'nationality' => sanitize($_POST['nationality'] ?? ''),
        'occupation' => sanitize($_POST['occupation'] ?? ''),
        'company' => sanitize($_POST['company'] ?? ''),
        'notes' => sanitize($_POST['notes'] ?? ''),
    ];

    if (empty($data['first_name']) || empty($data['last_name'])) {
        setFlash('error', 'First name and Last name are required');
    } else {
        if ($editId) {
            // Update
            $db->update(
                "UPDATE personal_details SET first_name=?, last_name=?, date_of_birth=?, gender=?, phone=?, 
                 alternate_phone=?, email=?, address_line1=?, address_line2=?, city=?, state=?, country=?, 
                 zip_code=?, nationality=?, occupation=?, company=?, notes=? 
                 WHERE id=? AND user_id=?",
                [
                    $data['first_name'], $data['last_name'], $data['date_of_birth'], $data['gender'],
                    $data['phone'], $data['alternate_phone'], $data['email'],
                    $data['address_line1'], $data['address_line2'], $data['city'], $data['state'],
                    $data['country'], $data['zip_code'], $data['nationality'],
                    $data['occupation'], $data['company'], $data['notes'],
                    $editId, $user['id']
                ]
            );
            $detailId = $editId;
            setFlash('success', 'Record updated successfully');
        } else {
            // Insert
            $detailId = $db->insert(
                "INSERT INTO personal_details (user_id, first_name, last_name, date_of_birth, gender, phone, 
                 alternate_phone, email, address_line1, address_line2, city, state, country, zip_code, 
                 nationality, occupation, company, notes) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $user['id'], $data['first_name'], $data['last_name'], $data['date_of_birth'],
                    $data['gender'], $data['phone'], $data['alternate_phone'], $data['email'],
                    $data['address_line1'], $data['address_line2'], $data['city'], $data['state'],
                    $data['country'], $data['zip_code'], $data['nationality'],
                    $data['occupation'], $data['company'], $data['notes']
                ]
            );
            setFlash('success', 'Record added successfully');
        }

        // Save custom field values
        saveCustomFieldEntries($user['id'], 'personal_details', $detailId);

        redirect('/personal-details.php');
    }
}

// Get data for listing
$search = sanitize($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;
$offset = ($page - 1) * $perPage;

$where = "WHERE user_id = ?";
$params = [$user['id']];

if ($search) {
    $where .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $searchLike = "%$search%";
    $params = array_merge($params, [$searchLike, $searchLike, $searchLike, $searchLike]);
}

$totalCount = $db->fetch("SELECT COUNT(*) as count FROM personal_details $where", $params);
$totalPages = ceil($totalCount['count'] / $perPage);

$details = $db->fetchAll(
    "SELECT * FROM personal_details $where ORDER BY updated_at DESC LIMIT $perPage OFFSET $offset",
    $params
);

// Get custom fields for personal_details module
$customFields = getCustomFieldsFor('personal_details', $user['id']);

// Check if editing
$editData = null;
$editCustomValues = [];
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $editData = $db->fetch("SELECT * FROM personal_details WHERE id = ? AND user_id = ?", [(int)$_GET['edit'], $user['id']]);
    if ($editData) {
        $editCustomValues = getCustomFieldValues('personal_details', $editData['id']);
    }
}

$showForm = isset($_GET['action']) && $_GET['action'] === 'add' || $editData;
?>

<!-- Toolbar -->
<div class="toolbar">
    <div class="toolbar-left">
        <?php if (!$showForm): ?>
        <div class="search-box" style="width: auto;">
            <i class="fas fa-search"></i>
            <form method="GET" style="display:inline;">
                <input type="text" name="search" placeholder="Search records..." 
                       value="<?php echo htmlspecialchars($search); ?>" style="width: 250px;">
            </form>
        </div>
        <?php else: ?>
        <a href="<?php echo url('personal-details.php'); ?>" class="btn btn-ghost">
            <i class="fas fa-arrow-left"></i> Back to List
        </a>
        <?php endif; ?>
    </div>
    <div class="toolbar-right">
        <?php if (!$showForm): ?>
        <a href="<?php echo url('personal-details.php?action=add'); ?>" class="btn btn-primary">
            <i class="fas fa-plus"></i> Add New Record
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
            <?php echo $editData ? 'Edit Record' : 'Add New Record'; ?>
        </h2>
    </div>

    <form method="POST" action="<?php echo url('personal-details.php'); ?>" id="detailForm" enctype="multipart/form-data">
<?php echo csrf_field(); ?>
        <?php if ($editData): ?>
            <input type="hidden" name="edit_id" value="<?php echo $editData['id']; ?>">
        <?php endif; ?>

        <h3 style="font-size: 1rem; color: var(--primary); margin-bottom: 16px;">
            <i class="fas fa-user"></i> Basic Information
        </h3>

        <div class="form-row">
            <div class="form-group">
                <label>First Name <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="text" name="first_name" placeholder="First name" required
                           value="<?php echo htmlspecialchars($editData['first_name'] ?? ''); ?>">
                    <i class="fas fa-user"></i>
                </div>
            </div>
            <div class="form-group">
                <label>Last Name <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="text" name="last_name" placeholder="Last name" required
                           value="<?php echo htmlspecialchars($editData['last_name'] ?? ''); ?>">
                    <i class="fas fa-user"></i>
                </div>
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
            <i class="fas fa-phone"></i> Contact Information
        </h3>

        <div class="form-row">
            <div class="form-group">
                <label>Phone</label>
                <div class="input-wrapper">
                    <input type="tel" name="phone" placeholder="Phone number"
                           value="<?php echo htmlspecialchars($editData['phone'] ?? ''); ?>">
                    <i class="fas fa-phone"></i>
                </div>
            </div>
            <div class="form-group">
                <label>Alternate Phone</label>
                <div class="input-wrapper">
                    <input type="tel" name="alternate_phone" placeholder="Alternate number"
                           value="<?php echo htmlspecialchars($editData['alternate_phone'] ?? ''); ?>">
                    <i class="fas fa-phone-alt"></i>
                </div>
            </div>
        </div>

        <div class="form-group">
            <label>Email</label>
            <div class="input-wrapper">
                <input type="email" name="detail_email" placeholder="Email address"
                       value="<?php echo htmlspecialchars($editData['email'] ?? ''); ?>">
                <i class="fas fa-envelope"></i>
            </div>
        </div>

        <h3 style="font-size: 1rem; color: var(--info); margin: 24px 0 16px;">
            <i class="fas fa-map-marker-alt"></i> Address
        </h3>

        <div class="form-group">
            <label>Address Line 1</label>
            <div class="input-wrapper">
                <input type="text" name="address_line1" placeholder="Street address"
                       value="<?php echo htmlspecialchars($editData['address_line1'] ?? ''); ?>">
                <i class="fas fa-home"></i>
            </div>
        </div>

        <div class="form-group">
            <label>Address Line 2</label>
            <div class="input-wrapper">
                <input type="text" name="address_line2" placeholder="Apartment, suite, etc."
                       value="<?php echo htmlspecialchars($editData['address_line2'] ?? ''); ?>">
                <i class="fas fa-building"></i>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>City</label>
                <div class="input-wrapper">
                    <input type="text" name="city" placeholder="City"
                           value="<?php echo htmlspecialchars($editData['city'] ?? ''); ?>">
                    <i class="fas fa-city"></i>
                </div>
            </div>
            <div class="form-group">
                <label>State / Province</label>
                <div class="input-wrapper">
                    <input type="text" name="state" placeholder="State"
                           value="<?php echo htmlspecialchars($editData['state'] ?? ''); ?>">
                    <i class="fas fa-map"></i>
                </div>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Country</label>
                <div class="input-wrapper">
                    <input type="text" name="country" placeholder="Country"
                           value="<?php echo htmlspecialchars($editData['country'] ?? ''); ?>">
                    <i class="fas fa-globe"></i>
                </div>
            </div>
            <div class="form-group">
                <label>ZIP / Postal Code</label>
                <div class="input-wrapper">
                    <input type="text" name="zip_code" placeholder="ZIP Code"
                           value="<?php echo htmlspecialchars($editData['zip_code'] ?? ''); ?>">
                    <i class="fas fa-mail-bulk"></i>
                </div>
            </div>
        </div>

        <h3 style="font-size: 1rem; color: var(--warning); margin: 24px 0 16px;">
            <i class="fas fa-briefcase"></i> Professional Details
        </h3>

        <div class="form-row">
            <div class="form-group">
                <label>Nationality</label>
                <div class="input-wrapper">
                    <input type="text" name="nationality" placeholder="Nationality"
                           value="<?php echo htmlspecialchars($editData['nationality'] ?? ''); ?>">
                    <i class="fas fa-flag"></i>
                </div>
            </div>
            <div class="form-group">
                <label>Occupation</label>
                <div class="input-wrapper">
                    <input type="text" name="occupation" placeholder="Occupation"
                           value="<?php echo htmlspecialchars($editData['occupation'] ?? ''); ?>">
                    <i class="fas fa-briefcase"></i>
                </div>
            </div>
        </div>

        <div class="form-group">
            <label>Company / Organization</label>
            <div class="input-wrapper">
                <input type="text" name="company" placeholder="Company name"
                       value="<?php echo htmlspecialchars($editData['company'] ?? ''); ?>">
                <i class="fas fa-building"></i>
            </div>
        </div>

        <div class="form-group">
            <label>Notes</label>
            <div class="input-wrapper">
                <textarea name="notes" placeholder="Additional notes..." style="padding-left: 16px;"><?php echo htmlspecialchars($editData['notes'] ?? ''); ?></textarea>
            </div>
        </div>

        <?php if (!empty($customFields)): ?>
        <?php renderCustomFields($customFields, $editCustomValues, 'Custom Fields', 'personal_details', $user['id']); ?>
        <?php endif; ?>

        <div style="display: flex; gap: 12px; margin-top: 24px; flex-wrap: wrap;">
            <button type="button" class="btn btn-preview btn-lg" onclick="showFormPreview('detailForm')">
                <i class="fas fa-eye"></i>
                <span class="btn-text">Preview</span>
            </button>
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="fas fa-save"></i>
                <span class="btn-text"><?php echo $editData ? 'Update Record' : 'Save Record'; ?></span>
            </button>
            <a href="<?php echo url('personal-details.php'); ?>" class="btn btn-secondary btn-lg">Cancel</a>
        </div>
    </form>
</div>

<?php else: ?>
<!-- Records List -->
<?php if (empty($details)): ?>
    <div class="card">
        <div class="empty-state">
            <i class="fas fa-address-book"></i>
            <h3>No Personal Details Found</h3>
            <p>Start saving personal information by adding your first record</p>
            <a href="<?php echo url('personal-details.php?action=add'); ?>" class="btn btn-primary">
                <i class="fas fa-plus"></i> Add First Record
            </a>
        </div>
    </div>
<?php else: ?>
    <div class="details-grid">
        <?php foreach ($details as $detail): 
            $color = randomColor();
            $initials = strtoupper(substr($detail['first_name'], 0, 1) . substr($detail['last_name'], 0, 1));
        ?>
        <div class="detail-card animate-fade-in">
            <div class="card-menu">
                <div style="display: flex; gap: 4px;">
                    <a href="<?php echo url('personal-details.php?edit=' . $detail['id']); ?>" class="btn btn-icon btn-ghost" title="Edit">
                        <i class="fas fa-pen"></i>
                    </a>
                    <a href="<?php echo url('personal-details.php?view=' . $detail['id']); ?>" class="btn btn-icon btn-ghost" title="View">
                        <i class="fas fa-eye"></i>
                    </a>
                    <button class="btn btn-icon btn-ghost" title="Delete" style="color: var(--danger);"
                            onclick="showConfirm('Delete Record', 'Are you sure you want to delete this record? This action cannot be undone.', function() { window.location.href='<?php echo url("personal-details.php?delete=" . $detail['id'] . "&csrf=" . urlencode(Auth::generateCSRF())); ?>'; })">
                        <i class="fas fa-trash"></i>
                    </button>
                    <?php renderActionButtonsInline('personal_details', $detail['id'], $user['id']); ?>
                </div>
            </div>

            <div class="detail-card-header">
                <div class="detail-avatar" style="background: <?php echo $color; ?>">
                    <?php echo $initials; ?>
                </div>
                <div>
                    <div class="detail-name"><?php echo htmlspecialchars($detail['first_name'] . ' ' . $detail['last_name']); ?></div>
                    <div class="detail-email"><?php echo htmlspecialchars($detail['email'] ?: 'No email'); ?></div>
                </div>
            </div>

            <div class="detail-info">
                <div class="detail-info-item">
                    <span class="label">Phone</span>
                    <span class="value"><?php echo htmlspecialchars($detail['phone'] ?: '-'); ?></span>
                </div>
                <div class="detail-info-item">
                    <span class="label">City</span>
                    <span class="value"><?php echo htmlspecialchars($detail['city'] ?: '-'); ?></span>
                </div>
                <div class="detail-info-item">
                    <span class="label">Occupation</span>
                    <span class="value"><?php echo htmlspecialchars($detail['occupation'] ?: '-'); ?></span>
                </div>
                <div class="detail-info-item">
                    <span class="label">Updated</span>
                    <span class="value"><?php echo timeAgo($detail['updated_at']); ?></span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>" class="page-btn">
                <i class="fas fa-chevron-left"></i>
            </a>
        <?php endif; ?>
        
        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>" 
               class="page-btn <?php echo $i === $page ? 'active' : ''; ?>">
                <?php echo $i; ?>
            </a>
        <?php endfor; ?>
        
        <?php if ($page < $totalPages): ?>
            <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>" class="page-btn">
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
    $viewData = $db->fetch("SELECT * FROM personal_details WHERE id = ? AND user_id = ?", [(int)$_GET['view'], $user['id']]);
    if ($viewData) {
        try {
            $viewCustomValues = $db->fetchAll(
                "SELECT cf.field_label, cf.field_type, cfe.field_value FROM custom_field_entries cfe 
                 JOIN custom_fields cf ON cf.id = cfe.custom_field_id 
                 WHERE cfe.linked_to = 'personal_details' AND cfe.record_id = ? AND cfe.field_value != ''",
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
        <a href="<?php echo url('personal-details.php'); ?>" class="modal-close"><i class="fas fa-times"></i></a>
    </div>
    <div class="modal-body" style="padding: 24px 28px;">
        <div style="text-align: center; margin-bottom: 24px;">
            <div class="detail-avatar" style="background: <?php echo randomColor(); ?>; width: 64px; height: 64px; font-size: 1.4rem; margin: 0 auto 12px;">
                <?php echo strtoupper(substr($viewData['first_name'], 0, 1) . substr($viewData['last_name'], 0, 1)); ?>
            </div>
            <h2><?php echo htmlspecialchars($viewData['first_name'] . ' ' . $viewData['last_name']); ?></h2>
            <p style="color: var(--text-secondary);"><?php echo htmlspecialchars($viewData['occupation'] ?? 'N/A'); ?></p>
        </div>

        <div class="preview-section">
            <div class="preview-section-title"><i class="fas fa-info-circle"></i> Details</div>
            <div class="preview-fields-grid">
                <?php
                // Same fields, in the same order, as the tkinter desktop app's
                // Personal Details form (app/models/personal_details.py FIELDS).
                $fields = [
                    'First Name' => $viewData['first_name'],
                    'Last Name' => $viewData['last_name'],
                    'Date of Birth' => $viewData['date_of_birth'] ? formatDate($viewData['date_of_birth']) : null,
                    'Gender' => $viewData['gender'] ? ucfirst($viewData['gender']) : null,
                    'Phone' => $viewData['phone'],
                    'Alternate Phone' => $viewData['alternate_phone'],
                    'Email' => $viewData['email'],
                    'Address Line 1' => $viewData['address_line1'],
                    'Address Line 2' => $viewData['address_line2'],
                    'City' => $viewData['city'],
                    'State' => $viewData['state'],
                    'Country' => $viewData['country'],
                    'ZIP Code' => $viewData['zip_code'],
                    'Nationality' => $viewData['nationality'],
                    'Occupation' => $viewData['occupation'],
                    'Company' => $viewData['company'],
                    'Notes' => $viewData['notes'],
                ];
                foreach ($fields as $label => $value): ?>
                <div class="preview-field<?php echo (!$value ? '' : (strlen($value) > 60 || $label === 'Notes' ? ' full-width' : '')); ?>">
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
        <a href="<?php echo url('personal-details.php?edit=' . $viewData['id']); ?>" class="btn btn-primary btn-sm">
            <i class="fas fa-edit"></i> Edit
        </a>
        <a href="<?php echo url('personal-details.php'); ?>" class="btn btn-secondary btn-sm">Close</a>
    </div>
</div>
<script>document.getElementById('modalOverlay').classList.add('active');</script>
<?php }} ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
