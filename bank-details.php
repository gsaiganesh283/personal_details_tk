<?php
$pageTitle = 'Bank Details';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/custom_fields_helper.php';
require_once __DIR__ . '/includes/action_buttons_helper.php';
$user = requireAuth();
$db = Database::getInstance();

// Handle delete
if (isset($_GET['delete']) && is_numeric($_GET['delete']) && Auth::validateCSRF($_GET['csrf'] ?? '')) {
    $id = (int)$_GET['delete'];
    purgeActionButtonChildrenForRecord('bank_details', $id, $user['id']);
    deleteCustomFieldFilesForRecord('bank_details', $id);
    $db->delete("DELETE FROM bank_details WHERE id = ? AND user_id = ?", [$id, $user['id']]);
    $db->insert("INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
        [$user['id'], 'delete', 'Deleted a bank account record', $_SERVER['REMOTE_ADDR'] ?? '']);
    setFlash('success', 'Bank account deleted successfully');
    redirect('/bank-details.php');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $editId = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : null;
    $data = [
        'bank_name'      => sanitize($_POST['bank_name'] ?? ''),
        'account_holder'  => sanitize($_POST['account_holder'] ?? ''),
        'account_number'  => sanitize($_POST['account_number'] ?? ''),
        'account_type'    => $_POST['account_type'] ?? 'savings',
        'ifsc_code'       => strtoupper(sanitize($_POST['ifsc_code'] ?? '')),
        'branch_name'     => sanitize($_POST['branch_name'] ?? ''),
        'branch_address'  => sanitize($_POST['branch_address'] ?? ''),
        'swift_code'      => strtoupper(sanitize($_POST['swift_code'] ?? '')),
        'micr_code'       => sanitize($_POST['micr_code'] ?? ''),
        'upi_id'          => sanitize($_POST['upi_id'] ?? ''),
        'linked_phone'    => sanitize($_POST['linked_phone'] ?? ''),
        'is_primary'      => isset($_POST['is_primary']) ? 1 : 0,
        'status'          => $_POST['status'] ?? 'active',
        'notes'           => sanitize($_POST['notes'] ?? ''),
    ];

    if (empty($data['bank_name']) || empty($data['account_holder']) || empty($data['account_number'])) {
        setFlash('error', 'Bank name, Account holder, and Account number are required');
    } else {
        if ($editId) {
            $db->update(
                "UPDATE bank_details SET bank_name=?, account_holder=?, account_number=?, account_type=?,
                 ifsc_code=?, branch_name=?, branch_address=?, swift_code=?, micr_code=?, upi_id=?,
                 linked_phone=?, is_primary=?, status=?, notes=? WHERE id=? AND user_id=?",
                array_merge(array_values($data), [$editId, $user['id']])
            );
            saveCustomFieldEntries($user['id'], 'bank_details', $editId);
            $db->insert("INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
                [$user['id'], 'update', 'Updated bank account: ' . $data['bank_name'], $_SERVER['REMOTE_ADDR'] ?? '']);
            setFlash('success', 'Bank account updated successfully');
        } else {
            // If marked primary, unmark others
            if ($data['is_primary']) {
                $db->update("UPDATE bank_details SET is_primary = 0 WHERE user_id = ?", [$user['id']]);
            }
            $newId = $db->insert(
                "INSERT INTO bank_details (user_id, bank_name, account_holder, account_number, account_type,
                 ifsc_code, branch_name, branch_address, swift_code, micr_code, upi_id, linked_phone,
                 is_primary, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                array_merge([$user['id']], array_values($data))
            );
            saveCustomFieldEntries($user['id'], 'bank_details', $newId);
            $db->insert("INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
                [$user['id'], 'create', 'Added bank account: ' . $data['bank_name'], $_SERVER['REMOTE_ADDR'] ?? '']);
            setFlash('success', 'Bank account added successfully');
        }
        redirect('/bank-details.php');
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
    $where .= " AND (bank_name LIKE ? OR account_holder LIKE ? OR account_number LIKE ? OR ifsc_code LIKE ? OR upi_id LIKE ?)";
    $searchLike = "%$search%";
    $params = array_merge($params, [$searchLike, $searchLike, $searchLike, $searchLike, $searchLike]);
}

$totalCount = $db->fetch("SELECT COUNT(*) as count FROM bank_details $where", $params);
$totalPages = ceil($totalCount['count'] / $perPage);
$banks = $db->fetchAll("SELECT * FROM bank_details $where ORDER BY is_primary DESC, updated_at DESC LIMIT $perPage OFFSET $offset", $params);

// Check if editing
$editData = null;
$editCustomValues = [];
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $editData = $db->fetch("SELECT * FROM bank_details WHERE id = ? AND user_id = ?", [(int)$_GET['edit'], $user['id']]);
    if ($editData) {
        $editCustomValues = getCustomFieldValues('bank_details', $editData['id']);
    }
}
$showForm = (isset($_GET['action']) && $_GET['action'] === 'add') || $editData;
$customFields = getCustomFieldsFor('bank_details', $user['id']);
?>

<!-- Toolbar -->
<div class="toolbar">
    <div class="toolbar-left">
        <?php if (!$showForm): ?>
        <div class="search-box" style="width: auto;">
            <i class="fas fa-search"></i>
            <form method="GET" style="display:inline;">
                <input type="text" name="search" placeholder="Search banks..." 
                       value="<?php echo htmlspecialchars($search); ?>" style="width: 250px;">
            </form>
        </div>
        <?php else: ?>
        <a href="<?php echo url('bank-details.php'); ?>" class="btn btn-ghost">
            <i class="fas fa-arrow-left"></i> Back to List
        </a>
        <?php endif; ?>
    </div>
    <div class="toolbar-right">
        <?php if (!$showForm): ?>
        <a href="<?php echo url('bank-details.php?action=add'); ?>" class="btn btn-primary">
            <i class="fas fa-plus"></i> Add Bank Account
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
            <?php echo $editData ? 'Edit Bank Account' : 'Add Bank Account'; ?>
        </h2>
    </div>

    <form method="POST" action="<?php echo url('bank-details.php'); ?>" id="bankForm" enctype="multipart/form-data">
<?php echo csrf_field(); ?>
        <?php if ($editData): ?>
            <input type="hidden" name="edit_id" value="<?php echo $editData['id']; ?>">
        <?php endif; ?>

        <h3 style="font-size: 1rem; color: var(--primary); margin-bottom: 16px;">
            <i class="fas fa-university"></i> Bank Information
        </h3>

        <div class="form-row">
            <div class="form-group">
                <label>Bank Name <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="text" name="bank_name" placeholder="e.g. State Bank of India" required
                           value="<?php echo htmlspecialchars($editData['bank_name'] ?? ''); ?>">
                    <i class="fas fa-university"></i>
                </div>
            </div>
            <div class="form-group">
                <label>Account Holder Name <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="text" name="account_holder" placeholder="Full name as per bank" required
                           value="<?php echo htmlspecialchars($editData['account_holder'] ?? ''); ?>">
                    <i class="fas fa-user"></i>
                </div>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Account Number <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="text" name="account_number" placeholder="Enter account number" required
                           value="<?php echo htmlspecialchars($editData['account_number'] ?? ''); ?>">
                    <i class="fas fa-hashtag"></i>
                </div>
            </div>
            <div class="form-group">
                <label>Account Type</label>
                <div class="input-wrapper">
                    <select name="account_type">
                        <?php
                        $types = ['savings'=>'Savings', 'current'=>'Current', 'salary'=>'Salary', 'fixed_deposit'=>'Fixed Deposit', 'recurring'=>'Recurring Deposit', 'nri'=>'NRI', 'other'=>'Other'];
                        foreach ($types as $val => $label):
                        ?>
                        <option value="<?php echo $val; ?>" <?php echo ($editData['account_type'] ?? 'savings') === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <i class="fas fa-list"></i>
                </div>
            </div>
        </div>

        <h3 style="font-size: 1rem; color: var(--success); margin: 24px 0 16px;">
            <i class="fas fa-code-branch"></i> Branch & Code Details
        </h3>

        <div class="form-row">
            <div class="form-group">
                <label>IFSC Code</label>
                <div class="input-wrapper">
                    <input type="text" name="ifsc_code" placeholder="e.g. SBIN0001234" maxlength="11"
                           value="<?php echo htmlspecialchars($editData['ifsc_code'] ?? ''); ?>" style="text-transform: uppercase;">
                    <i class="fas fa-barcode"></i>
                </div>
            </div>
            <div class="form-group">
                <label>Branch Name</label>
                <div class="input-wrapper">
                    <input type="text" name="branch_name" placeholder="Branch name"
                           value="<?php echo htmlspecialchars($editData['branch_name'] ?? ''); ?>">
                    <i class="fas fa-map-marker-alt"></i>
                </div>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>SWIFT Code</label>
                <div class="input-wrapper">
                    <input type="text" name="swift_code" placeholder="e.g. SBININBB" maxlength="11"
                           value="<?php echo htmlspecialchars($editData['swift_code'] ?? ''); ?>" style="text-transform: uppercase;">
                    <i class="fas fa-globe"></i>
                </div>
            </div>
            <div class="form-group">
                <label>MICR Code</label>
                <div class="input-wrapper">
                    <input type="text" name="micr_code" placeholder="9-digit MICR code" maxlength="9"
                           value="<?php echo htmlspecialchars($editData['micr_code'] ?? ''); ?>">
                    <i class="fas fa-fingerprint"></i>
                </div>
            </div>
        </div>

        <div class="form-group">
            <label>Branch Address</label>
            <div class="input-wrapper">
                <textarea name="branch_address" rows="2" placeholder="Complete branch address"><?php echo htmlspecialchars($editData['branch_address'] ?? ''); ?></textarea>
                <i class="fas fa-map" style="top: 14px;"></i>
            </div>
        </div>

        <h3 style="font-size: 1rem; color: var(--warning); margin: 24px 0 16px;">
            <i class="fas fa-mobile-alt"></i> Linked Details
        </h3>

        <div class="form-row">
            <div class="form-group">
                <label>UPI ID</label>
                <div class="input-wrapper">
                    <input type="text" name="upi_id" placeholder="e.g. name@upi"
                           value="<?php echo htmlspecialchars($editData['upi_id'] ?? ''); ?>">
                    <i class="fas fa-at"></i>
                </div>
            </div>
            <div class="form-group">
                <label>Linked Phone Number</label>
                <div class="input-wrapper">
                    <input type="tel" name="linked_phone" placeholder="Mobile linked to account"
                           value="<?php echo htmlspecialchars($editData['linked_phone'] ?? ''); ?>">
                    <i class="fas fa-phone"></i>
                </div>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Status</label>
                <div class="input-wrapper">
                    <select name="status">
                        <option value="active" <?php echo ($editData['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="closed" <?php echo ($editData['status'] ?? '') === 'closed' ? 'selected' : ''; ?>>Closed</option>
                        <option value="dormant" <?php echo ($editData['status'] ?? '') === 'dormant' ? 'selected' : ''; ?>>Dormant</option>
                    </select>
                    <i class="fas fa-toggle-on"></i>
                </div>
            </div>
            <div class="form-group" style="display: flex; align-items: center; padding-top: 28px;">
                <label class="form-check" style="margin-bottom: 0;">
                    <input type="checkbox" name="is_primary" <?php echo ($editData['is_primary'] ?? 0) ? 'checked' : ''; ?>>
                    <span class="checkmark"></span>
                    <span class="form-check-label">Set as Primary Account</span>
                </label>
            </div>
        </div>

        <div class="form-group">
            <label>Notes</label>
            <div class="input-wrapper">
                <textarea name="notes" rows="3" placeholder="Any additional notes..."><?php echo htmlspecialchars($editData['notes'] ?? ''); ?></textarea>
                <i class="fas fa-sticky-note" style="top: 14px;"></i>
            </div>
        </div>

        <?php renderCustomFields($customFields ?? [], $editCustomValues ?? [], 'Custom Fields', 'bank_details', $user['id']); ?>

        <div class="form-actions">
            <a href="<?php echo url('bank-details.php'); ?>" class="btn btn-ghost">Cancel</a>
            <button type="button" class="btn btn-preview" onclick="showFormPreview('bankForm')">
                <i class="fas fa-eye"></i> Preview
            </button>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> <?php echo $editData ? 'Update Account' : 'Save Account'; ?>
            </button>
        </div>
    </form>
</div>

<?php else: ?>
<!-- Bank Accounts List -->
<?php if (empty($banks)): ?>
    <div class="empty-state card">
        <i class="fas fa-university"></i>
        <h3>No bank accounts yet</h3>
        <p>Start by adding your bank account details</p>
        <a href="<?php echo url('bank-details.php?action=add'); ?>" class="btn btn-primary">
            <i class="fas fa-plus"></i> Add Bank Account
        </a>
    </div>
<?php else: ?>
    <div class="details-grid">
        <?php foreach ($banks as $bank):
            $color = randomColor();
        ?>
        <div class="detail-card animate-fade-in">
            <div class="card-menu">
                <div style="display: flex; gap: 4px;">
                    <a href="<?php echo url('bank-details.php?edit=' . $bank['id']); ?>" class="btn btn-icon btn-ghost" title="Edit">
                        <i class="fas fa-pen"></i>
                    </a>
                    <a href="<?php echo url('bank-details.php?view=' . $bank['id']); ?>" class="btn btn-icon btn-ghost" title="View">
                        <i class="fas fa-eye"></i>
                    </a>
                    <button class="btn btn-icon btn-ghost" title="Delete" style="color: var(--danger);"
                            onclick="showConfirm('Delete Bank Account', 'Are you sure you want to delete this bank account? This action cannot be undone.', function() { window.location.href='<?php echo url("bank-details.php?delete=" . $bank['id'] . "&csrf=" . urlencode(Auth::generateCSRF())); ?>'; })">
                        <i class="fas fa-trash"></i>
                    </button>
                    <?php renderActionButtonsInline('bank_details', $bank['id'], $user['id']); ?>
                </div>
            </div>

            <div class="detail-card-header">
                <div class="detail-avatar" style="background: <?php echo $color; ?>">
                    <i class="fas fa-university"></i>
                </div>
                <div>
                    <div class="detail-name"><?php echo htmlspecialchars($bank['bank_name']); ?></div>
                    <div class="detail-email"><?php echo htmlspecialchars($bank['account_holder']); ?></div>
                </div>
            </div>

            <?php if ($bank['is_primary'] || $bank['status'] !== 'active'): ?>
            <div style="display: flex; gap: 6px; margin: -8px 0 14px;">
                <?php if ($bank['is_primary']): ?>
                <span class="badge badge-success"><i class="fas fa-star"></i> Primary</span>
                <?php endif; ?>
                <?php if ($bank['status'] !== 'active'): ?>
                <span class="badge badge-<?php echo $bank['status'] === 'closed' ? 'danger' : 'warning'; ?>">
                    <?php echo ucfirst($bank['status']); ?>
                </span>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="detail-info">
                <div class="detail-info-item">
                    <span class="label">Account No.</span>
                    <span class="value"><?php echo htmlspecialchars('XXXX' . substr($bank['account_number'], -4)); ?></span>
                </div>
                <div class="detail-info-item">
                    <span class="label">Type</span>
                    <span class="value"><?php echo ucfirst(str_replace('_', ' ', $bank['account_type'])); ?></span>
                </div>
                <div class="detail-info-item">
                    <span class="label">IFSC</span>
                    <span class="value"><?php echo htmlspecialchars($bank['ifsc_code'] ?: '-'); ?></span>
                </div>
                <div class="detail-info-item">
                    <span class="label">Updated</span>
                    <span class="value"><?php echo timeAgo($bank['updated_at']); ?></span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
        <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>" class="pagination-btn">
            <i class="fas fa-chevron-left"></i>
        </a>
        <?php endif; ?>
        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
        <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>"
           class="pagination-btn <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
        <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>" class="pagination-btn">
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
    $viewData = $db->fetch("SELECT * FROM bank_details WHERE id = ? AND user_id = ?", [(int)$_GET['view'], $user['id']]);
    if ($viewData) {
?>
<div class="modal view-modal active" id="viewModal">
    <div class="modal-header">
        <h3><i class="fas fa-eye" style="margin-right: 8px; color: var(--primary);"></i> View Details</h3>
        <a href="<?php echo url('bank-details.php'); ?>" class="modal-close"><i class="fas fa-times"></i></a>
    </div>
    <div class="modal-body" style="padding: 24px 28px;">
        <div style="text-align: center; margin-bottom: 24px;">
            <div class="detail-avatar" style="background: <?php echo randomColor(); ?>; width: 64px; height: 64px; font-size: 1.4rem; margin: 0 auto 12px;">
                <i class="fas fa-university"></i>
            </div>
            <h2><?php echo htmlspecialchars($viewData['bank_name']); ?></h2>
            <p style="color: var(--text-secondary);"><?php echo htmlspecialchars($viewData['account_holder']); ?></p>
        </div>

        <div class="preview-section">
            <div class="preview-section-title"><i class="fas fa-info-circle"></i> Details</div>
            <div class="preview-fields-grid">
                <?php 
                $fields = [
                    'Account Number' => $viewData['account_number'],
                    'Account Type' => ucfirst(str_replace('_', ' ', $viewData['account_type'])),
                    'IFSC Code' => $viewData['ifsc_code'],
                    'Branch' => $viewData['branch_name'],
                    'Branch Address' => $viewData['branch_address'],
                    'SWIFT Code' => $viewData['swift_code'],
                    'MICR Code' => $viewData['micr_code'],
                    'UPI ID' => $viewData['upi_id'],
                    'Linked Phone' => $viewData['linked_phone'],
                    'Status' => ucfirst($viewData['status']),
                    'Primary' => $viewData['is_primary'] ? 'Yes' : 'No',
                    'Notes' => $viewData['notes'],
                ];
                foreach ($fields as $label => $value): ?>
                <div class="preview-field<?php echo (!$value ? '' : (strlen($value) > 60 || $label === 'Notes' || $label === 'Branch Address' ? ' full-width' : '')); ?>">
                    <div class="preview-field-label"><?php echo $label; ?></div>
                    <div class="preview-field-value<?php echo !$value ? ' empty' : ''; ?>"><?php echo $value ? htmlspecialchars($value) : 'Not provided'; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php renderCustomFieldValues('bank_details', $viewData['id']); ?>
    </div>
    <div class="modal-footer">
        <a href="<?php echo url('bank-details.php?edit=' . $viewData['id']); ?>" class="btn btn-primary btn-sm">
            <i class="fas fa-edit"></i> Edit
        </a>
        <a href="<?php echo url('bank-details.php'); ?>" class="btn btn-secondary btn-sm">Close</a>
    </div>
</div>
<script>document.getElementById('modalOverlay').classList.add('active');</script>
<?php } } ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
