<?php
$pageTitle = 'Contacts';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/custom_fields_helper.php';
require_once __DIR__ . '/includes/action_buttons_helper.php';
$user = requireAuth();
$db = Database::getInstance();

// Mirrors app/core/validators.py's normalize_phone_number(): E.164 shape,
// leading '+', country code + number, 8-15 digits total.
function normalizePhoneNumber($raw) {
    $cleaned = preg_replace('/[\s\-().]/', '', $raw ?? '');
    if (!preg_match('/^\+[1-9]\d{7,14}$/', $cleaned)) {
        return null;
    }
    return $cleaned;
}

// Mirrors app/ui/module_list_frame.py::_import_vcf()'s parsing: unfold
// folded lines, split BEGIN:VCARD/END:VCARD blocks, pull FN (or N as a
// fallback), TEL, and NOTE per card.
function parseVcf($text) {
    $rawLines = preg_split('/\r\n|\r|\n/', $text);
    $lines = [];
    foreach ($rawLines as $raw) {
        if ($raw !== '' && ($raw[0] === ' ' || $raw[0] === "\t")) {
            if (!empty($lines)) {
                $lines[count($lines) - 1] .= ltrim($raw);
            }
        } else {
            $lines[] = $raw;
        }
    }

    $vcards = [];
    $current = [];
    foreach ($lines as $line) {
        $upper = strtoupper(trim($line));
        if ($upper === 'BEGIN:VCARD') {
            $current = [];
        } elseif ($upper === 'END:VCARD') {
            $vcards[] = $current;
            $current = [];
        } else {
            $current[] = $line;
        }
    }

    $contacts = [];
    foreach ($vcards as $card) {
        $name = null;
        $phone = null;
        $notes = null;
        foreach ($card as $line) {
            $upperLine = strtoupper($line);
            if (strpos($upperLine, 'FN:') === 0) {
                $name = trim(substr($line, strpos($line, ':') + 1));
            } elseif (strpos($upperLine, 'N:') === 0 && !$name) {
                $parts = explode(';', substr($line, strpos($line, ':') + 1));
                $name = trim(implode(' ', array_filter(array_reverse($parts))));
            } elseif (strpos($upperLine, 'TEL') === 0) {
                if (strpos($line, ':') !== false) {
                    $candidate = trim(substr($line, strpos($line, ':') + 1));
                    if ($candidate !== '') {
                        $phone = $candidate;
                    }
                }
            } elseif (strpos($upperLine, 'NOTE:') === 0) {
                $notes = trim(substr($line, strpos($line, ':') + 1));
            }
        }
        if (!$name && !$phone) {
            continue;
        }
        $cleanedPhone = normalizePhoneNumber($phone ?? '');
        $contacts[] = [
            'name' => $name ?? '',
            'phone_number' => $cleanedPhone ?? ($phone ?? ''),
            'notes' => $notes ?? '',
        ];
    }
    return $contacts;
}

// Handle VCF import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['vcf_file'])) {
    $file = $_FILES['vcf_file'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        $text = file_get_contents($file['tmp_name']);
        $parsed = parseVcf($text);
        $added = 0;
        foreach ($parsed as $c) {
            try {
                $db->insert(
                    "INSERT INTO contacts (user_id, name, phone_number, notes) VALUES (?, ?, ?, ?)",
                    [$user['id'], $c['name'], $c['phone_number'], $c['notes']]
                );
                $added++;
            } catch (Exception $e) {
                continue;
            }
        }
        if ($added > 0) {
            $db->insert("INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
                [$user['id'], 'create', "Imported $added contact(s) from VCF", $_SERVER['REMOTE_ADDR'] ?? '']);
        }
        setFlash('success', "Imported $added contact(s) from VCF.");
    } else {
        setFlash('error', 'Failed to read the uploaded VCF file.');
    }
    redirect('/contacts.php');
}

// Handle delete
if (isset($_GET['delete']) && is_numeric($_GET['delete']) && Auth::validateCSRF($_GET['csrf'] ?? '')) {
    $id = (int)$_GET['delete'];
    purgeActionButtonChildrenForRecord('contacts', $id, $user['id']);
    deleteCustomFieldFilesForRecord('contacts', $id);
    $db->delete("DELETE FROM contacts WHERE id = ? AND user_id = ?", [$id, $user['id']]);
    $db->insert("INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
        [$user['id'], 'delete', 'Deleted a contact', $_SERVER['REMOTE_ADDR'] ?? '']);
    setFlash('success', 'Contact deleted successfully');
    redirect('/contacts.php');
}

// Handle add/edit form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['name'])) {
    $editId = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : null;
    $name = sanitize($_POST['name'] ?? '');
    $phoneRaw = trim($_POST['phone_number'] ?? '');
    $notes = sanitize($_POST['notes'] ?? '');

    if (empty($name)) {
        setFlash('error', 'Name is required');
    } else {
        $phone = $phoneRaw !== '' ? (normalizePhoneNumber($phoneRaw) ?? $phoneRaw) : '';
        if ($editId) {
            $db->update(
                "UPDATE contacts SET name=?, phone_number=?, notes=? WHERE id=? AND user_id=?",
                [$name, $phone, $notes, $editId, $user['id']]
            );
            saveCustomFieldEntries($user['id'], 'contacts', $editId);
            $db->insert("INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
                [$user['id'], 'update', 'Updated contact: ' . $name, $_SERVER['REMOTE_ADDR'] ?? '']);
            setFlash('success', 'Contact updated successfully');
        } else {
            $newId = $db->insert(
                "INSERT INTO contacts (user_id, name, phone_number, notes) VALUES (?, ?, ?, ?)",
                [$user['id'], $name, $phone, $notes]
            );
            saveCustomFieldEntries($user['id'], 'contacts', $newId);
            $db->insert("INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
                [$user['id'], 'create', 'Added contact: ' . $name, $_SERVER['REMOTE_ADDR'] ?? '']);
            setFlash('success', 'Contact added successfully');
        }
        redirect('/contacts.php');
    }
}

// Get data
$search = sanitize($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPageOptions = [12, 25, 50, 100];
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 12;
if (!in_array($perPage, $perPageOptions, true)) {
    $perPage = 12;
}
$offset = ($page - 1) * $perPage;

$where = "WHERE user_id = ?";
$params = [$user['id']];

if ($search) {
    $where .= " AND (name LIKE ? OR phone_number LIKE ? OR notes LIKE ?)";
    $searchLike = "%$search%";
    $params = array_merge($params, [$searchLike, $searchLike, $searchLike]);
}

$totalCount = $db->fetch("SELECT COUNT(*) as count FROM contacts $where", $params);
$totalPages = max(1, ceil($totalCount['count'] / $perPage));
$records = $db->fetchAll("SELECT * FROM contacts $where ORDER BY name ASC LIMIT $perPage OFFSET $offset", $params);

// Check if editing
$editData = null;
$editCustomValues = [];
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $editData = $db->fetch("SELECT * FROM contacts WHERE id = ? AND user_id = ?", [(int)$_GET['edit'], $user['id']]);
    if ($editData) {
        $editCustomValues = getCustomFieldValues('contacts', $editData['id']);
    }
}
$showForm = (isset($_GET['action']) && $_GET['action'] === 'add') || $editData;
$showImport = isset($_GET['action']) && $_GET['action'] === 'import';
$customFields = getCustomFieldsFor('contacts', $user['id']);

function contactInitials($name) {
    $parts = preg_split('/\s+/', trim($name));
    $parts = array_filter($parts);
    if (empty($parts)) return '?';
    if (count($parts) === 1) return strtoupper(substr($parts[0], 0, 2));
    return strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1));
}
?>

<!-- Toolbar -->
<div class="toolbar">
    <div class="toolbar-left">
        <?php if (!$showForm && !$showImport): ?>
        <form method="GET" style="display: flex; align-items: center; gap: 12px;">
            <div class="search-box" style="width: auto;">
                <i class="fas fa-search"></i>
                <input type="text" name="search" placeholder="Search contacts..."
                       value="<?php echo htmlspecialchars($search); ?>" style="width: 250px;">
            </div>
            <select name="per_page" class="filter-select" title="Records per page" onchange="this.form.submit()">
                <?php foreach ($perPageOptions as $opt): ?>
                <option value="<?php echo $opt; ?>" <?php echo $perPage === $opt ? 'selected' : ''; ?>><?php echo $opt; ?> / page</option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php else: ?>
        <a href="<?php echo url('contacts.php'); ?>" class="btn btn-ghost">
            <i class="fas fa-arrow-left"></i> Back to List
        </a>
        <?php endif; ?>
    </div>
    <div class="toolbar-right">
        <?php if (!$showForm && !$showImport): ?>
        <a href="<?php echo url('contacts.php?action=import'); ?>" class="btn btn-secondary">
            <i class="fas fa-file-import"></i> Import VCF
        </a>
        <a href="<?php echo url('contacts.php?action=add'); ?>" class="btn btn-primary">
            <i class="fas fa-plus"></i> Add Contact
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($showImport): ?>
<!-- Import VCF -->
<div class="card animate-fade-in">
    <div class="card-header">
        <h2><i class="fas fa-file-import" style="margin-right: 8px; color: var(--primary);"></i> Import Contacts from VCF</h2>
    </div>
    <form method="POST" action="<?php echo url('contacts.php'); ?>" enctype="multipart/form-data">
<?php echo csrf_field(); ?>
        <div class="form-group">
            <label>VCF File (.vcf)</label>
            <input type="file" name="vcf_file" accept=".vcf" required>
            <small style="color: var(--text-muted);">Exported from your phone's contacts app, Google Contacts, or Outlook.</small>
        </div>
        <div class="form-actions">
            <a href="<?php echo url('contacts.php'); ?>" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary"><i class="fas fa-upload"></i> Import</button>
        </div>
    </form>
</div>

<?php elseif ($showForm): ?>
<!-- Add/Edit Form -->
<div class="card animate-fade-in">
    <div class="card-header">
        <h2>
            <i class="fas fa-<?php echo $editData ? 'edit' : 'plus'; ?>" style="margin-right: 8px; color: var(--primary);"></i>
            <?php echo $editData ? 'Edit Contact' : 'Add Contact'; ?>
        </h2>
    </div>

    <form method="POST" action="<?php echo url('contacts.php'); ?>" id="contactForm" enctype="multipart/form-data">
<?php echo csrf_field(); ?>
        <?php if ($editData): ?>
            <input type="hidden" name="edit_id" value="<?php echo $editData['id']; ?>">
        <?php endif; ?>

        <div class="form-row">
            <div class="form-group">
                <label>Name <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="text" name="name" placeholder="Full name" required
                           value="<?php echo htmlspecialchars($editData['name'] ?? ''); ?>">
                    <i class="fas fa-user"></i>
                </div>
            </div>
            <div class="form-group">
                <label>Phone Number</label>
                <div class="input-wrapper">
                    <input type="tel" name="phone_number" placeholder="+919876543210"
                           value="<?php echo htmlspecialchars($editData['phone_number'] ?? ''); ?>">
                    <i class="fas fa-phone"></i>
                </div>
                <small style="color: var(--text-muted);">Include the country code, e.g. +91...</small>
            </div>
        </div>

        <div class="form-group">
            <label>Notes</label>
            <div class="input-wrapper">
                <textarea name="notes" rows="3" placeholder="Any additional notes..."><?php echo htmlspecialchars($editData['notes'] ?? ''); ?></textarea>
                <i class="fas fa-sticky-note" style="top: 14px;"></i>
            </div>
        </div>

        <?php renderCustomFields($customFields ?? [], $editCustomValues ?? [], 'Custom Fields', 'contacts', $user['id']); ?>

        <div class="form-actions">
            <a href="<?php echo url('contacts.php'); ?>" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> <?php echo $editData ? 'Update Contact' : 'Save Contact'; ?>
            </button>
        </div>
    </form>
</div>

<?php else: ?>
<!-- Contacts List -->
<?php if (empty($records)): ?>
    <div class="empty-state card">
        <i class="fas fa-address-book"></i>
        <h3>No contacts yet</h3>
        <p>Add contacts one at a time, or import them all from a VCF file</p>
        <a href="<?php echo url('contacts.php?action=add'); ?>" class="btn btn-primary">
            <i class="fas fa-plus"></i> Add Contact
        </a>
    </div>
<?php else: ?>
    <div class="details-grid">
        <?php foreach ($records as $record): ?>
        <div class="detail-card animate-fade-in">
            <div class="card-menu">
                <div style="display: flex; gap: 4px;">
                    <a href="<?php echo url('contacts.php?edit=' . $record['id']); ?>" class="btn btn-icon btn-ghost" title="Edit">
                        <i class="fas fa-pen"></i>
                    </a>
                    <a href="<?php echo url('contacts.php?view=' . $record['id']); ?>" class="btn btn-icon btn-ghost" title="View">
                        <i class="fas fa-eye"></i>
                    </a>
                    <button class="btn btn-icon btn-ghost" title="Delete" style="color: var(--danger);"
                            onclick="showConfirm('Delete Contact', 'Are you sure you want to delete this contact? This action cannot be undone.', function() { window.location.href='<?php echo url("contacts.php?delete=" . $record['id'] . "&csrf=" . urlencode(Auth::generateCSRF())); ?>'; })">
                        <i class="fas fa-trash"></i>
                    </button>
                    <?php renderActionButtonsInline('contacts', $record['id'], $user['id']); ?>
                </div>
            </div>

            <div class="detail-card-header">
                <div class="detail-avatar" style="background: linear-gradient(135deg, var(--primary), var(--primary-light));">
                    <?php echo htmlspecialchars(contactInitials($record['name'])); ?>
                </div>
                <div>
                    <div class="detail-name"><?php echo htmlspecialchars($record['name']); ?></div>
                    <div class="detail-email"><?php echo htmlspecialchars($record['phone_number'] ?: 'No phone'); ?></div>
                </div>
            </div>

            <?php if ($record['notes']): ?>
            <p style="color: var(--text-secondary); font-size: 0.85rem; line-height: 1.4; margin: 0 0 14px;">
                <?php echo htmlspecialchars(substr($record['notes'], 0, 100)); ?><?php echo strlen($record['notes']) > 100 ? '...' : ''; ?>
            </p>
            <?php endif; ?>

            <div class="detail-info">
                <div class="detail-info-item">
                    <span class="label">Phone</span>
                    <span class="value"><?php echo htmlspecialchars($record['phone_number'] ?: '-'); ?></span>
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
        <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&per_page=<?php echo $perPage; ?>" class="pagination-btn"><i class="fas fa-chevron-left"></i></a>
        <?php endif; ?>
        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
        <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&per_page=<?php echo $perPage; ?>" class="pagination-btn <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
        <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&per_page=<?php echo $perPage; ?>" class="pagination-btn"><i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>
<?php endif; ?>

<!-- View Detail Modal -->
<?php
if (isset($_GET['view']) && is_numeric($_GET['view'])) {
    $viewData = $db->fetch("SELECT * FROM contacts WHERE id = ? AND user_id = ?", [(int)$_GET['view'], $user['id']]);
    if ($viewData) {
        try {
            $viewCustomValues = $db->fetchAll(
                "SELECT cf.field_label, cf.field_type, cfe.field_value FROM custom_field_entries cfe
                 JOIN custom_fields cf ON cf.id = cfe.custom_field_id
                 WHERE cfe.linked_to = 'contacts' AND cfe.record_id = ? AND cfe.field_value != ''",
                [$viewData['id']]
            );
        } catch (Exception $e) {
            $viewCustomValues = [];
        }
        $fileFieldTypes = getFileFieldTypes();
?>
<div class="modal view-modal active" id="viewModal">
    <div class="modal-header">
        <h3><i class="fas fa-eye" style="margin-right: 8px; color: var(--primary);"></i> View Contact</h3>
        <a href="<?php echo url('contacts.php'); ?>" class="modal-close"><i class="fas fa-times"></i></a>
    </div>
    <div class="modal-body" style="padding: 24px 28px;">
        <div style="text-align: center; margin-bottom: 24px;">
            <div class="detail-avatar" style="background: linear-gradient(135deg, var(--primary), var(--primary-light)); width: 64px; height: 64px; font-size: 1.4rem; margin: 0 auto 12px;">
                <?php echo htmlspecialchars(contactInitials($viewData['name'])); ?>
            </div>
            <h2><?php echo htmlspecialchars($viewData['name']); ?></h2>
        </div>

        <div class="preview-section">
            <div class="preview-section-title"><i class="fas fa-info-circle"></i> Details</div>
            <div class="preview-fields-grid">
                <?php
                $fields = ['Phone Number' => $viewData['phone_number'], 'Notes' => $viewData['notes']];
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
        <a href="<?php echo url('contacts.php?edit=' . $viewData['id']); ?>" class="btn btn-primary btn-sm">
            <i class="fas fa-edit"></i> Edit
        </a>
        <a href="<?php echo url('contacts.php'); ?>" class="btn btn-secondary btn-sm">Close</a>
    </div>
</div>
<script>document.getElementById('modalOverlay').classList.add('active');</script>
<?php } } ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
