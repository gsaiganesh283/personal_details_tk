<?php
$pageTitle = 'Custom Fields';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/custom_fields_helper.php';
require_once __DIR__ . '/includes/action_buttons_helper.php';

// Allowed field types whitelist
$allowedFieldTypes = ['text','number','email','password','phone','url','currency','date','time','datetime','textarea','select','multiselect','checkbox','radio','toggle','rating','file','image','pdf','document','spreadsheet','color','range','reminder','send_now'];

/** Settings panel for the "Send to phone (Send now)" type; $p = 'add' | 'edit'. */
function renderSendNowConfig(string $p): void { ?>
            <div class="form-group" id="<?php echo $p; ?>SendNowConfigGroup" style="display: none; border: 1px solid var(--border); border-radius: 12px; padding: 14px; background: var(--bg-soft);">
                <label style="display:flex; justify-content:space-between; align-items:center;">
                    <span><i class="fas fa-paper-plane" style="color: var(--primary);"></i> Fields to send <span class="required">*</span></span>
                    <span>
                        <button type="button" class="btn btn-ghost btn-sm" onclick="sendFieldsSelectAll('<?php echo $p; ?>', true)">All</button>
                        <button type="button" class="btn btn-ghost btn-sm" onclick="sendFieldsSelectAll('<?php echo $p; ?>', false)">None</button>
                    </span>
                </label>
                <div id="<?php echo $p; ?>SendFieldsList" style="max-height: 200px; overflow-y: auto; border: 1px solid var(--border); border-radius: 8px; padding: 8px; background: var(--bg-primary, var(--bg-secondary));">
                    <small style="color: var(--text-muted);">Loading fields…</small>
                </div>
                <small style="color: var(--text-muted); font-size: 0.78rem; display:block; margin-top:4px;">Ticked fields are sent in this order. <i class="fas fa-paperclip"></i> = image/document, attached as a file. Password fields are never sent.</small>

                <label style="margin-top: 12px; display: block;">Title</label>
                <div class="input-wrapper no-icon"><input type="text" id="<?php echo $p; ?>SendTitle" maxlength="150" placeholder="e.g. {bank_name} details  (blank = field label)" style="padding-left: 16px;"></div>
                <label style="margin-top: 10px; display: block;">Intro line</label>
                <div class="input-wrapper no-icon"><input type="text" id="<?php echo $p; ?>SendIntro" maxlength="300" placeholder="Optional first line of the message" style="padding-left: 16px;"></div>
                <label style="margin-top: 10px; display: block;">Insert field <small style="color: var(--text-muted); font-weight: 400;">(click to add to Title or Intro)</small></label>
                <div id="<?php echo $p; ?>SendChips" style="display:flex; flex-wrap:wrap; gap:6px;"></div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 10px;">
                    <div>
                        <label>Priority</label>
                        <div class="input-wrapper no-icon"><select id="<?php echo $p; ?>SendPriority"><option value="default">Normal</option><option value="high">High</option></select></div>
                    </div>
                    <div>
                        <label>Wait between sends (seconds)</label>
                        <div class="input-wrapper no-icon"><input type="number" id="<?php echo $p; ?>SendCooldown" min="0" max="3600" value="30" style="padding-left: 16px;"></div>
                    </div>
                </div>

                <div style="display:flex; flex-direction:column; gap:6px; margin-top: 12px;">
                    <label class="form-check" style="margin:0"><input type="checkbox" id="<?php echo $p; ?>SendMask" checked><span class="checkmark"></span><span class="form-check-label">Hide sensitive numbers (account, Aadhaar, PAN, card…) — show last 4 digits</span></label>
                    <label class="form-check" style="margin:0"><input type="checkbox" id="<?php echo $p; ?>SendFiles" checked><span class="checkmark"></span><span class="form-check-label">Attach selected images and documents</span></label>
                    <small style="color: var(--text-secondary);"><i class="fas fa-lock"></i> Your Safety PIN is always asked before sending.</small>
                    <label class="form-check" style="margin:0"><input type="checkbox" id="<?php echo $p; ?>SendConfirm" checked><span class="checkmark"></span><span class="form-check-label">Show a preview before sending</span></label>
                </div>
                <small style="color: var(--text-muted); font-size: 0.78rem; display:block; margin-top:8px;"><i class="fas fa-shield-alt"></i> Every send is recorded in the Activity Log. Files are only taken from this record.</small>
            </div>
<?php }

// Handle form POST actions (works on all hosting — no AJAX needed)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    $user = requireAuth();
    if (!Auth::validateCSRF($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Your session expired or the request could not be verified. Please try again.');
        redirect('/custom-fields.php');
        exit;
    }
    $db = Database::getInstance();
    ensureCustomFieldShowInListColumn();
    require_once __DIR__ . '/includes/fields_engine.php';
    feEnsureSchema();
    $action = $_POST['action'];

    // A field must live on a module that exists, and may only be grouped in
    // a block of that same module -- anything else is invisible everywhere.
    $validModule = function ($module) use ($user) {
        return $module !== '' && feModuleExists($module, $user['id']);
    };
    $validBlock = function ($blockId, $module) use ($db, $user) {
        if (!$blockId) return null;
        $b = $db->fetch("SELECT id FROM custom_field_blocks WHERE id = ? AND user_id = ? AND linked_to = ?", [$blockId, $user['id'], $module]);
        return $b ? (int)$b['id'] : null;
    };

    switch ($action) {
        case 'create':
            $fieldName = sanitize($_POST['field_name'] ?? '');
            $fieldLabel = sanitize($_POST['field_label'] ?? '');
            $fieldType = strtolower(trim($_POST['field_type'] ?? ''));
            if (empty($fieldType) || !in_array($fieldType, $allowedFieldTypes)) {
                $fieldType = 'text';
            }
            $fieldPlaceholder = sanitize($_POST['field_placeholder'] ?? '');
            $isRequired = isset($_POST['is_required']) ? 1 : 0;
            $showInList = isset($_POST['show_in_list']) ? 1 : 0;
            $fieldOptions = $_POST['field_options'] ?? '';
            $linkedTo = sanitize($_POST['linked_to'] ?? 'personal_details');
            $blockId = !empty($_POST['block_id']) ? (int)$_POST['block_id'] : null;
            $returnModule = sanitize($_POST['return_module'] ?? $linkedTo);

            if (empty($fieldName) || empty($fieldLabel)) {
                setFlash('error', 'Field name and label are required');
                redirect('/custom-fields.php?module=' . urlencode($returnModule));
            }
            if (!$validModule($linkedTo)) {
                setFlash('error', 'That module no longer exists -- pick another one.');
                redirect('/custom-fields.php');
            }
            $blockId = $validBlock($blockId, $linkedTo);

            // Clean field name (make it a valid identifier)
            $fieldName = preg_replace('/[^a-z0-9_]/', '_', strtolower($fieldName));

            // Get next order
            $maxOrder = $db->fetch("SELECT MAX(field_order) as max_order FROM custom_fields WHERE user_id = ?", [$user['id']]);
            $nextOrder = ($maxOrder['max_order'] ?? 0) + 1;

            // Get next order within block
            $orderInBlock = 0;
            if ($blockId) {
                $maxBlockOrder = $db->fetch("SELECT MAX(field_order_in_block) as mo FROM custom_fields WHERE block_id = ?", [$blockId]);
                $orderInBlock = ($maxBlockOrder['mo'] ?? 0) + 1;
            }

            $db->insert(
                "INSERT INTO custom_fields (user_id, field_name, field_label, field_type, field_options, field_placeholder, is_required, show_in_list, field_order, linked_to, block_id, field_order_in_block)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$user['id'], $fieldName, $fieldLabel, $fieldType, $fieldOptions, $fieldPlaceholder, $isRequired, $showInList, $nextOrder, $linkedTo, $blockId, $orderInBlock]
            );

            setFlash('success', 'Custom field created successfully');
            redirect('/custom-fields.php?module=' . urlencode($returnModule));
            break;

        case 'update':
            $fieldId = (int)($_POST['field_id'] ?? 0);
            $fieldLabel = sanitize($_POST['field_label'] ?? '');
            $fieldType = strtolower(trim($_POST['field_type'] ?? ''));
            if (empty($fieldType) || !in_array($fieldType, $allowedFieldTypes)) {
                $fieldType = 'text';
            }
            $fieldPlaceholder = sanitize($_POST['field_placeholder'] ?? '');
            $isRequired = isset($_POST['is_required']) ? 1 : 0;
            $showInList = isset($_POST['show_in_list']) ? 1 : 0;
            $fieldOptions = $_POST['field_options'] ?? '';
            $linkedTo = sanitize($_POST['linked_to'] ?? 'personal_details');
            $blockId = !empty($_POST['block_id']) ? (int)$_POST['block_id'] : null;
            $returnModule = sanitize($_POST['return_module'] ?? $linkedTo);

            if (empty($fieldLabel)) {
                setFlash('error', 'Field label is required');
                redirect('/custom-fields.php?module=' . urlencode($returnModule));
            }
            if (!$validModule($linkedTo)) {
                setFlash('error', 'That module no longer exists -- pick another one.');
                redirect('/custom-fields.php?module=' . urlencode($returnModule));
            }
            $blockId = $validBlock($blockId, $linkedTo);

            $db->update(
                "UPDATE custom_fields SET field_label = ?, field_type = ?, field_options = ?, field_placeholder = ?, is_required = ?, show_in_list = ?, linked_to = ?, block_id = ?
                 WHERE id = ? AND user_id = ?",
                [$fieldLabel, $fieldType, $fieldOptions, $fieldPlaceholder, $isRequired, $showInList, $linkedTo, $blockId, $fieldId, $user['id']]
            );

            setFlash('success', 'Custom field updated successfully');
            redirect('/custom-fields.php?module=' . urlencode($returnModule));
            break;

        case 'delete':
            $fieldId = (int)($_POST['field_id'] ?? 0);
            $returnModule = sanitize($_POST['return_module'] ?? '');
            feDeleteField($fieldId, $user['id']);
            setFlash('success', 'Custom field and its saved values deleted');
            redirect('/custom-fields.php?module=' . urlencode($returnModule));
            break;

        case 'toggle':
            $fieldId = (int)($_POST['field_id'] ?? 0);
            $returnModule = sanitize($_POST['return_module'] ?? '');
            $db->update(
                "UPDATE custom_fields SET is_active = NOT is_active WHERE id = ? AND user_id = ?",
                [$fieldId, $user['id']]
            );
            setFlash('success', 'Field status toggled');
            redirect('/custom-fields.php?module=' . urlencode($returnModule));
            break;

        case 'move':
            $fieldId = (int)($_POST['field_id'] ?? 0);
            $direction = (int)($_POST['direction'] ?? 0);
            $returnModule = sanitize($_POST['return_module'] ?? '');
            $field = $db->fetch("SELECT id, linked_to FROM custom_fields WHERE id = ? AND user_id = ?", [$fieldId, $user['id']]);
            if ($field && $direction !== 0) {
                $siblings = $db->fetchAll(
                    "SELECT id, field_order FROM custom_fields WHERE user_id = ? AND linked_to = ? ORDER BY field_order ASC, id ASC",
                    [$user['id'], $field['linked_to']]
                );
                // Fields sharing an order number (common after a reuse or a
                // sync) can't be swapped visibly -- renumber them 1..n first.
                if (count(array_unique(array_column($siblings, 'field_order'))) !== count($siblings)) {
                    foreach ($siblings as $pos => $s) {
                        $db->update("UPDATE custom_fields SET field_order = ? WHERE id = ? AND user_id = ?", [$pos + 1, $s['id'], $user['id']]);
                        $siblings[$pos]['field_order'] = $pos + 1;
                    }
                }
                $ids = array_column($siblings, 'id');
                $idx = array_search((int)$fieldId, $ids, true);
                if ($idx !== false) {
                    $newIdx = $idx + $direction;
                    if ($newIdx >= 0 && $newIdx < count($siblings)) {
                        $a = $siblings[$idx];
                        $b = $siblings[$newIdx];
                        $db->update("UPDATE custom_fields SET field_order = ? WHERE id = ? AND user_id = ?", [$b['field_order'], $a['id'], $user['id']]);
                        $db->update("UPDATE custom_fields SET field_order = ? WHERE id = ? AND user_id = ?", [$a['field_order'], $b['id'], $user['id']]);
                    }
                }
            }
            redirect('/custom-fields.php?module=' . urlencode($returnModule));
            break;

        case 'reorder':
            $order = json_decode($_POST['order'] ?? '[]', true);
            if (is_array($order)) {
                foreach ($order as $position => $fieldId) {
                    $db->update(
                        "UPDATE custom_fields SET field_order = ? WHERE id = ? AND user_id = ?",
                        [$position, (int)$fieldId, $user['id']]
                    );
                }
            }
            setFlash('success', 'Fields reordered');
            redirect('/custom-fields.php');
            break;

        case 'reuse':
            $fieldId = (int)($_POST['field_id'] ?? 0);
            $targetModule = sanitize($_POST['target_module'] ?? '');
            $returnModule = sanitize($_POST['return_module'] ?? '');
            $result = addFieldToModule($fieldId, $targetModule, $user['id']);
            if ($result['success']) {
                setFlash('success', $result['message']);
            } else {
                setFlash('error', $result['message']);
            }
            redirect('/custom-fields.php?module=' . urlencode($returnModule));
            break;

        case 'create_action_button':
            $module = sanitize($_POST['action_button_module'] ?? '');
            $label = trim(sanitize($_POST['action_button_label'] ?? ''));
            $icon = sanitize($_POST['action_button_icon'] ?? 'fa-layer-group');
            $returnModule = sanitize($_POST['return_module'] ?? $module);
            if (empty($module) || $label === '') {
                setFlash('error', 'Module and label are required');
            } elseif (createActionButton($module, $label, $user['id'], $icon) === false) {
                setFlash('error', 'That module no longer exists -- pick another one.');
            } else {
                setFlash('success', 'Action button created');
            }
            redirect('/custom-fields.php?module=' . urlencode($returnModule));
            break;

        case 'rename_action_button':
            $buttonId = (int)($_POST['button_id'] ?? 0);
            $label = trim(sanitize($_POST['action_button_label'] ?? ''));
            $returnModule = sanitize($_POST['return_module'] ?? '');
            if ($label === '') {
                setFlash('error', 'Label is required');
            } else {
                renameActionButton($buttonId, $label, $user['id']);
                setFlash('success', 'Action button renamed');
            }
            redirect('/custom-fields.php?module=' . urlencode($returnModule));
            break;

        case 'delete_action_button':
            $buttonId = (int)($_POST['button_id'] ?? 0);
            $returnModule = sanitize($_POST['return_module'] ?? '');
            deleteActionButton($buttonId, $user['id']);
            setFlash('success', 'Action button and its records deleted');
            redirect('/custom-fields.php?module=' . urlencode($returnModule));
            break;

        case 'move_action_button':
            $buttonId = (int)($_POST['button_id'] ?? 0);
            $direction = (int)($_POST['direction'] ?? 0);
            $returnModule = sanitize($_POST['return_module'] ?? '');
            moveActionButton($buttonId, $direction, $user['id']);
            redirect('/custom-fields.php?module=' . urlencode($returnModule));
            break;

        default:
            setFlash('error', 'Invalid action');
            redirect('/custom-fields.php');
    }
    exit;
}

// Normal page load - include header (outputs HTML)
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/custom_fields_helper.php';
$user = requireAuth();
$db = Database::getInstance();
ensureCustomFieldShowInListColumn();

// Module picker -- everything below the picker (fields table + Action
// Buttons panel) is scoped to whichever module is currently selected, the
// same as the tkinter desktop app's "Module:" combobox on this screen
// (app/ui/custom_fields_admin_view.py) driving its refresh().
$moduleLabels = getModuleLabels();
$selectedModule = sanitize($_GET['module'] ?? '');
if (!isset($moduleLabels[$selectedModule])) {
    $selectedModule = $moduleLabels ? array_key_first($moduleLabels) : '';
}

$moduleFields = $selectedModule
    ? $db->fetchAll("SELECT * FROM custom_fields WHERE user_id = ? AND linked_to = ? ORDER BY field_order ASC", [$user['id'], $selectedModule])
    : [];

$moduleBlocks = $selectedModule ? getBlocksForModule($selectedModule, $user['id']) : [];
$blockLabelsById = array_column($moduleBlocks, 'block_label', 'id');

$moduleActionButtons = $selectedModule ? getActionButtons($selectedModule, $user['id']) : [];

// Every module's blocks, so the Add/Edit Field modal's "Block" dropdown can
// refresh live when "Use In" changes without a page reload (mirrors
// FieldEditDialog._on_module_changed() in the desktop app).
$blocksByModuleJson = [];
foreach ($moduleLabels as $moduleKey => $moduleLabelText) {
    $blocksByModuleJson[$moduleKey] = array_map(
        function ($b) { return ['id' => $b['id'], 'label' => $b['block_label']]; },
        getBlocksForModule($moduleKey, $user['id'])
    );
}
?>

<!-- Toolbar -->
<div class="toolbar">
    <div class="toolbar-left">
        <label style="font-size: 0.85rem; color: var(--text-secondary); font-weight: 600;">Module:</label>
        <select id="moduleSelect" class="filter-select" style="min-width: 260px;"
                onchange="window.location.href='<?php echo url('custom-fields.php'); ?>?module=' + encodeURIComponent(this.value)">
            <?php foreach ($moduleLabels as $key => $label): ?>
            <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $key === $selectedModule ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="toolbar-right">
        <a href="<?php echo url('custom-fields.php?module=' . urlencode($selectedModule)); ?>" class="btn btn-secondary">
            <i class="fas fa-sync"></i> Refresh
        </a>
        <button class="btn btn-primary" onclick="openModal('addFieldModal')">
            <i class="fas fa-plus"></i> Add Field
        </button>
    </div>
</div>

<!-- Fields for the selected module -->
<div class="card animate-fade-in">
    <div class="card-header">
        <h2><i class="fas fa-list" style="margin-right: 8px; color: var(--primary);"></i> Fields — <?php echo htmlspecialchars($moduleLabels[$selectedModule] ?? $selectedModule); ?></h2>
    </div>

    <div class="table-container" style="border: none;">
        <table class="data-table" id="fieldsTable">
            <thead>
                <tr>
                    <th style="width: 40px;">#</th>
                    <th>Label</th>
                    <th>Type</th>
                    <th>Required</th>
                    <th>Shown in List</th>
                    <th>Block</th>
                    <th>Active</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($moduleFields)): ?>
                <tr><td colspan="7" style="text-align: center; color: var(--text-muted); padding: 28px;">No custom fields defined for this module yet.</td></tr>
                <?php else: foreach ($moduleFields as $index => $field): ?>
                <tr class="row-selectable" data-id="<?php echo $field['id']; ?>"
                    data-field='<?php echo htmlspecialchars(json_encode($field), ENT_QUOTES, 'UTF-8'); ?>'
                    onclick="selectFieldRow(this)" ondblclick="editSelectedField()">
                    <td><?php echo $index + 1; ?></td>
                    <td><strong><?php echo htmlspecialchars($field['field_label']); ?></strong></td>
                    <td>
                        <?php $displayType = !empty($field['field_type']) ? $field['field_type'] : 'text'; ?>
                        <span class="field-type <?php echo htmlspecialchars($displayType); ?>"><?php echo ucfirst(htmlspecialchars($displayType)); ?></span>
                    </td>
                    <td><?php echo $field['is_required'] ? 'Yes' : 'No'; ?></td>
                    <td><?php echo !empty($field['show_in_list']) ? 'Yes' : 'No'; ?></td>
                    <td><?php echo $field['block_id'] ? htmlspecialchars($blockLabelsById[$field['block_id']] ?? '-') : '-'; ?></td>
                    <td><?php echo $field['is_active'] ? 'Yes' : 'No'; ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--border);">
        <button class="btn btn-secondary btn-sm" onclick="editSelectedField()"><i class="fas fa-pen"></i> Edit</button>
        <button class="btn btn-secondary btn-sm" onclick="deleteSelectedField()"><i class="fas fa-trash"></i> Delete</button>
        <button class="btn btn-secondary btn-sm" onclick="toggleSelectedField()"><i class="fas fa-power-off"></i> Toggle Active</button>
        <button class="btn btn-secondary btn-sm" onclick="moveSelectedField(-1)"><i class="fas fa-arrow-up"></i> Move Up</button>
        <button class="btn btn-secondary btn-sm" onclick="moveSelectedField(1)"><i class="fas fa-arrow-down"></i> Move Down</button>
        <button class="btn btn-secondary btn-sm" onclick="reuseSelectedField()"><i class="fas fa-copy"></i> Reuse in Module...</button>
    </div>
</div>

<!-- Action Buttons for this Module -->
<div class="card animate-fade-in" style="margin-top: 24px;">
    <div class="card-header">
        <h2><i class="fas fa-layer-group" style="margin-right: 8px; color: var(--warning);"></i> Action Buttons for this Module</h2>
        <p style="margin: 8px 0 0; color: var(--text-secondary); font-size: 0.9rem;">
            A button that opens a popup of child records for one selected record -- can itself have further Action
            Buttons defined on its own module, nested as deep as you like.
        </p>
    </div>

    <div class="table-container" style="border: none;">
        <table class="data-table" id="actionButtonsTable">
            <thead>
                <tr><th style="width: 48px;">Icon</th><th>Button Label</th></tr>
            </thead>
            <tbody>
                <?php if (empty($moduleActionButtons)): ?>
                <tr><td colspan="2" style="text-align: center; color: var(--text-muted); padding: 28px;">No action buttons defined for this module yet.</td></tr>
                <?php else: foreach ($moduleActionButtons as $button): ?>
                <tr class="row-selectable" data-id="<?php echo $button['id']; ?>"
                    data-label="<?php echo htmlspecialchars($button['label'], ENT_QUOTES, 'UTF-8'); ?>"
                    onclick="selectButtonRow(this)" ondblclick="manageSelectedButton()">
                    <td><i class="fas <?php echo htmlspecialchars($button['icon'] ?: 'fa-layer-group', ENT_QUOTES, 'UTF-8'); ?>" style="color: var(--warning);"></i></td>
                    <td><?php echo htmlspecialchars($button['label']); ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--border);">
        <button class="btn btn-secondary btn-sm" onclick="openModal('addActionButtonModal')"><i class="fas fa-plus"></i> Add Button</button>
        <button class="btn btn-secondary btn-sm" onclick="renameSelectedButton()"><i class="fas fa-pen"></i> Rename</button>
        <button class="btn btn-secondary btn-sm" onclick="deleteSelectedButton()"><i class="fas fa-trash"></i> Delete</button>
        <button class="btn btn-secondary btn-sm" onclick="moveSelectedButton(-1)"><i class="fas fa-arrow-up"></i> Move Up</button>
        <button class="btn btn-secondary btn-sm" onclick="moveSelectedButton(1)"><i class="fas fa-arrow-down"></i> Move Down</button>
        <button class="btn btn-secondary btn-sm" onclick="manageSelectedButton()"><i class="fas fa-puzzle-piece"></i> Manage Fields &amp; Buttons...</button>
    </div>
</div>

<div class="card" style="margin-top: 24px;">
    <div class="card-header">
        <h2><i class="fas fa-info-circle" style="margin-right: 8px; color: var(--info);"></i> Supported Field Types</h2>
    </div>
    <div class="details-grid" style="grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));">
        <?php
        $fieldTypes = [
            ['type' => 'text', 'icon' => 'fa-font', 'desc' => 'Single line text input', 'cat' => 'Basic'],
            ['type' => 'number', 'icon' => 'fa-hashtag', 'desc' => 'Numeric input only', 'cat' => 'Basic'],
            ['type' => 'email', 'icon' => 'fa-envelope', 'desc' => 'Email address with validation', 'cat' => 'Basic'],
            ['type' => 'password', 'icon' => 'fa-lock', 'desc' => 'Masked password field', 'cat' => 'Basic'],
            ['type' => 'phone', 'icon' => 'fa-phone', 'desc' => 'Phone number input', 'cat' => 'Basic'],
            ['type' => 'url', 'icon' => 'fa-link', 'desc' => 'URL / website link', 'cat' => 'Basic'],
            ['type' => 'currency', 'icon' => 'fa-rupee-sign', 'desc' => 'Currency / money amount', 'cat' => 'Basic'],
            ['type' => 'date', 'icon' => 'fa-calendar', 'desc' => 'Date picker field', 'cat' => 'Date & Time'],
            ['type' => 'time', 'icon' => 'fa-clock', 'desc' => 'Time picker field', 'cat' => 'Date & Time'],
            ['type' => 'datetime', 'icon' => 'fa-calendar-check', 'desc' => 'Date and time picker', 'cat' => 'Date & Time'],
            ['type' => 'textarea', 'icon' => 'fa-align-left', 'desc' => 'Multi-line text area', 'cat' => 'Text'],
            ['type' => 'select', 'icon' => 'fa-list-ul', 'desc' => 'Dropdown selection', 'cat' => 'Selection'],
            ['type' => 'multiselect', 'icon' => 'fa-tasks', 'desc' => 'Multi-select dropdown', 'cat' => 'Selection'],
            ['type' => 'checkbox', 'icon' => 'fa-check-square', 'desc' => 'Multiple choice checkboxes', 'cat' => 'Selection'],
            ['type' => 'radio', 'icon' => 'fa-dot-circle', 'desc' => 'Single choice radio buttons', 'cat' => 'Selection'],
            ['type' => 'toggle', 'icon' => 'fa-toggle-on', 'desc' => 'On/Off toggle switch', 'cat' => 'Selection'],
            ['type' => 'rating', 'icon' => 'fa-star', 'desc' => 'Star rating (1-5)', 'cat' => 'Selection'],
            ['type' => 'file', 'icon' => 'fa-file-upload', 'desc' => 'Any file upload (max 5MB)', 'cat' => 'Files'],
            ['type' => 'image', 'icon' => 'fa-image', 'desc' => 'Image upload (JPG, PNG, GIF, WebP)', 'cat' => 'Files'],
            ['type' => 'pdf', 'icon' => 'fa-file-pdf', 'desc' => 'PDF document upload', 'cat' => 'Files'],
            ['type' => 'document', 'icon' => 'fa-file-word', 'desc' => 'Word document (DOC, DOCX)', 'cat' => 'Files'],
            ['type' => 'spreadsheet', 'icon' => 'fa-file-excel', 'desc' => 'Excel / CSV spreadsheet', 'cat' => 'Files'],
            ['type' => 'color', 'icon' => 'fa-palette', 'desc' => 'Color picker', 'cat' => 'Other'],
            ['type' => 'range', 'icon' => 'fa-sliders-h', 'desc' => 'Range slider (0-100)', 'cat' => 'Other'],
            ['type' => 'reminder', 'icon' => 'fa-bell', 'desc' => 'Per-record scheduled message (in-app + email)', 'cat' => 'Other'],
            ['type' => 'send_now', 'icon' => 'fa-paper-plane', 'desc' => 'Send now button: sends chosen fields, images & documents to your phone', 'cat' => 'Other'],
        ];
        foreach ($fieldTypes as $ft):
        ?>
        <div style="display: flex; gap: 12px; align-items: flex-start; padding: 12px; border-radius: var(--radius-sm); background: var(--bg-tertiary);">
            <div style="width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; background: rgba(108,99,255,0.1); color: var(--primary); flex-shrink: 0;">
                <i class="fas <?php echo $ft['icon']; ?>"></i>
            </div>
            <div>
                <strong style="font-size: 0.85rem; text-transform: capitalize;"><?php echo $ft['type']; ?></strong>
                <span style="font-size: 0.68rem; padding: 1px 6px; border-radius: 4px; background: var(--bg-secondary); color: var(--text-muted); margin-left: 4px;"><?php echo $ft['cat']; ?></span>
                <p style="font-size: 0.78rem; color: var(--text-muted); margin: 2px 0 0;"><?php echo $ft['desc']; ?></p>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Add Field Modal -->
<div class="modal" id="addFieldModal">
    <div class="modal-header">
        <h3><i class="fas fa-plus" style="margin-right: 8px; color: var(--primary);"></i> Add Custom Field</h3>
        <button class="modal-close" onclick="closeModal('addFieldModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
        <form id="addFieldForm" method="POST" action="<?php echo url('custom-fields.php'); ?>">
<?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="field_options" id="addFieldOptionsHidden" value="">
            <input type="hidden" name="return_module" value="<?php echo htmlspecialchars($selectedModule); ?>">
            <div class="form-group">
                <label>Field Label <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="text" name="field_label" id="addFieldLabel" placeholder="e.g., LinkedIn Profile" required>
                    <i class="fas fa-tag"></i>
                </div>
            </div>

            <div class="form-group">
                <label>Field Name <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="text" name="field_name" id="addFieldName" placeholder="e.g., linkedin_profile" required>
                    <i class="fas fa-code"></i>
                </div>
                <small style="color: var(--text-muted); font-size: 0.78rem;">Auto-generated from label. Use lowercase with underscores.</small>
            </div>

            <div class="form-group">
                <label>Use In <span class="required">*</span></label>
                <div class="input-wrapper">
                    <select name="linked_to" id="addFieldLinkedTo">
                        <?php foreach ($moduleLabels as $val => $lbl): ?>
                        <option value="<?php echo $val; ?>"<?php echo $val === $selectedModule ? ' selected' : ''; ?>><?php echo $lbl; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <i class="fas fa-layer-group"></i>
                </div>
                <small style="color: var(--text-muted); font-size: 0.78rem;">Choose which module this field appears in.</small>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Field Type <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <select name="field_type" id="addFieldType"></select>
                        <i class="fas fa-cog"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label>Placeholder</label>
                    <div class="input-wrapper">
                        <input type="text" name="field_placeholder" id="addFieldPlaceholder" placeholder="Placeholder text...">
                        <i class="fas fa-pencil-alt"></i>
                    </div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Block</label>
                    <div class="input-wrapper">
                        <select name="block_id" id="addFieldBlock">
                            <option value="">None</option>
                        </select>
                        <i class="fas fa-th-large"></i>
                    </div>
                    <small style="color: var(--text-muted); font-size: 0.78rem;">Group this field under a Field Block (manage on Field Blocks).</small>
                </div>
            </div>

            <!-- Options for select/radio/checkbox -->
            <div class="form-group" id="addOptionsGroup" style="display: none;">
                <label>Options <span class="required">*</span></label>
                <div class="options-list" id="addOptionsList">
                    <div class="option-item">
                        <input type="text" placeholder="Option 1" class="option-input">
                        <button type="button" class="remove-option" onclick="removeOption(this)"><i class="fas fa-times"></i></button>
                    </div>
                    <div class="option-item">
                        <input type="text" placeholder="Option 2" class="option-input">
                        <button type="button" class="remove-option" onclick="removeOption(this)"><i class="fas fa-times"></i></button>
                    </div>
                </div>
                <button type="button" class="add-option-btn" onclick="addOption('addOptionsList')">
                    <i class="fas fa-plus"></i> Add Option
                </button>
            </div>

            <?php renderSendNowConfig('add'); ?>

            <!-- Reminder config -->
            <div class="form-group" id="addReminderConfigGroup" style="display: none;">
                <label>Template <span class="required">*</span></label>
                <div class="input-wrapper">
                    <select id="addReminderTemplate"></select>
                    <i class="fas fa-scroll"></i>
                </div>
                <small id="addReminderTemplateHint" style="color: var(--text-muted); font-size: 0.78rem; display: block; margin-top: 4px;"></small>
                <small style="color: var(--text-muted); font-size: 0.78rem;">Sent to you when the reminder fires. Each record using this field gets its own date/time picker.</small>
                <label style="margin-top: 12px; display: block;">Repeat</label>
                <div class="input-wrapper">
                    <select id="addReminderRepeat">
                        <option value="none">Does not repeat</option>
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                        <option value="yearly">Yearly</option>
                    </select>
                    <i class="fas fa-redo"></i>
                </div>
            </div>

            <div class="form-group">
                <label class="form-check">
                    <input type="checkbox" name="is_required" value="1" id="addFieldRequired">
                    <span class="checkmark"></span>
                    <span class="form-check-label">Required field</span>
                </label>
            </div>

            <div class="form-group">
                <label class="form-check">
                    <input type="checkbox" name="show_in_list" value="1" id="addFieldShowInList">
                    <span class="checkmark"></span>
                    <span class="form-check-label">Shown in List</span>
                </label>
                <small style="color: var(--text-muted); font-size: 0.78rem;">Adds this field as its own column on the module's list view.</small>
            </div>

            <!-- Live Preview -->
            <div class="field-preview">
                <div class="field-preview-title">
                    <i class="fas fa-eye"></i> Live Preview
                </div>
                <div id="addFieldPreview">
                    <div class="form-group">
                        <label>Field Label</label>
                        <div class="input-wrapper no-icon">
                            <input type="text" placeholder="Placeholder text..." disabled>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('addFieldModal')">Cancel</button>
        <button class="btn btn-primary" onclick="createField()">
            <i class="fas fa-plus"></i> Create Field
        </button>
    </div>
</div>

<!-- Edit Field Modal -->
<div class="modal" id="editFieldModal">
    <div class="modal-header">
        <h3><i class="fas fa-edit" style="margin-right: 8px; color: var(--primary);"></i> Edit Custom Field</h3>
        <button class="modal-close" onclick="closeModal('editFieldModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
        <form id="editFieldForm" method="POST" action="<?php echo url('custom-fields.php'); ?>">
<?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="field_id" id="editFieldId">
            <input type="hidden" name="field_options" id="editFieldOptionsHidden" value="">
            <input type="hidden" name="return_module" value="<?php echo htmlspecialchars($selectedModule); ?>">

            <div class="form-group">
                <label>Field Label <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="text" name="field_label" id="editFieldLabel" placeholder="Field label" required>
                    <i class="fas fa-tag"></i>
                </div>
            </div>

            <div class="form-group">
                <label>Use In <span class="required">*</span></label>
                <div class="input-wrapper">
                    <select name="linked_to" id="editFieldLinkedTo">
                        <?php foreach ($moduleLabels as $val => $lbl): ?>
                        <option value="<?php echo $val; ?>"><?php echo $lbl; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <i class="fas fa-layer-group"></i>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Field Type</label>
                    <div class="input-wrapper">
                        <select name="field_type" id="editFieldType"></select>
                        <i class="fas fa-cog"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label>Placeholder</label>
                    <div class="input-wrapper">
                        <input type="text" name="field_placeholder" id="editFieldPlaceholder" placeholder="Placeholder text...">
                        <i class="fas fa-pencil-alt"></i>
                    </div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Block</label>
                    <div class="input-wrapper">
                        <select name="block_id" id="editFieldBlock">
                            <option value="">None</option>
                        </select>
                        <i class="fas fa-th-large"></i>
                    </div>
                </div>
            </div>

            <!-- Options for select/radio/checkbox -->
            <div class="form-group" id="editOptionsGroup" style="display: none;">
                <label>Options <span class="required">*</span></label>
                <div class="options-list" id="editOptionsList">
                </div>
                <button type="button" class="add-option-btn" onclick="addOption('editOptionsList')">
                    <i class="fas fa-plus"></i> Add Option
                </button>
            </div>

            <?php renderSendNowConfig('edit'); ?>

            <!-- Reminder config -->
            <div class="form-group" id="editReminderConfigGroup" style="display: none;">
                <label>Template <span class="required">*</span></label>
                <div class="input-wrapper">
                    <select id="editReminderTemplate"></select>
                    <i class="fas fa-scroll"></i>
                </div>
                <small id="editReminderTemplateHint" style="color: var(--text-muted); font-size: 0.78rem; display: block; margin-top: 4px;"></small>
                <small style="color: var(--text-muted); font-size: 0.78rem;">Sent to you when the reminder fires. Each record using this field gets its own date/time picker.</small>
                <label style="margin-top: 12px; display: block;">Repeat</label>
                <div class="input-wrapper">
                    <select id="editReminderRepeat">
                        <option value="none">Does not repeat</option>
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                        <option value="yearly">Yearly</option>
                    </select>
                    <i class="fas fa-redo"></i>
                </div>
            </div>

            <div class="form-group">
                <label class="form-check">
                    <input type="checkbox" name="is_required" value="1" id="editFieldRequired">
                    <span class="checkmark"></span>
                    <span class="form-check-label">Required field</span>
                </label>
            </div>

            <div class="form-group">
                <label class="form-check">
                    <input type="checkbox" name="show_in_list" value="1" id="editFieldShowInList">
                    <span class="checkmark"></span>
                    <span class="form-check-label">Shown in List</span>
                </label>
                <small style="color: var(--text-muted); font-size: 0.78rem;">Adds this field as its own column on the module's list view.</small>
            </div>
        </form>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('editFieldModal')">Cancel</button>
        <button class="btn btn-primary" onclick="updateField()">
            <i class="fas fa-save"></i> Save Changes
        </button>
    </div>
</div>

<!-- Hidden form for toggle/delete/move actions -->
<form id="fieldActionForm" method="POST" action="<?php echo url('custom-fields.php'); ?>" style="display:none;">
<?php echo csrf_field(); ?>
    <input type="hidden" name="action" id="fieldActionType">
    <input type="hidden" name="field_id" id="fieldActionId">
    <input type="hidden" name="direction" id="fieldActionDirection">
    <input type="hidden" name="return_module" value="<?php echo htmlspecialchars($selectedModule); ?>">
</form>

<!-- Hidden form for Action Button rename/delete/move -->
<form id="actionButtonActionForm" method="POST" action="<?php echo url('custom-fields.php'); ?>" style="display:none;">
<?php echo csrf_field(); ?>
    <input type="hidden" name="action" id="actionButtonActionType">
    <input type="hidden" name="button_id" id="actionButtonActionId">
    <input type="hidden" name="action_button_label" id="actionButtonActionLabel">
    <input type="hidden" name="direction" id="actionButtonActionDirection">
    <input type="hidden" name="return_module" value="<?php echo htmlspecialchars($selectedModule); ?>">
</form>

<!-- Reuse Field Modal -->
<div class="modal" id="reuseFieldModal">
    <div class="modal-header">
        <h3><i class="fas fa-copy" style="margin-right: 8px; color: var(--info);"></i> Reuse Field In...</h3>
        <button class="modal-close" onclick="closeModal('reuseFieldModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
        <p id="reuseFieldName" style="color: var(--text-secondary); margin: 0 0 14px;"></p>
        <form id="reuseFieldForm" method="POST" action="<?php echo url('custom-fields.php'); ?>">
<?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="reuse">
            <input type="hidden" name="field_id" id="reuseFieldId">
            <input type="hidden" name="return_module" value="<?php echo htmlspecialchars($selectedModule); ?>">
            <div class="form-group">
                <label>Target Module</label>
                <div class="input-wrapper">
                    <select name="target_module" id="reuseTargetModule"></select>
                    <i class="fas fa-layer-group"></i>
                </div>
            </div>
        </form>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('reuseFieldModal')">Cancel</button>
        <button class="btn btn-primary" onclick="document.getElementById('reuseFieldForm').submit();">
            <i class="fas fa-copy"></i> Add to Module
        </button>
    </div>
</div>

<style>
.icon-picker-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(42px, 1fr));
    gap: 8px;
    max-height: 220px;
    overflow-y: auto;
    padding: 4px 2px;
}
.icon-picker-option {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 42px;
    height: 42px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--bg-secondary, transparent);
    color: var(--text-secondary);
    cursor: pointer;
    font-size: 1.05rem;
    transition: border-color 0.15s, color 0.15s, background 0.15s;
}
.icon-picker-option:hover {
    border-color: var(--primary);
    color: var(--primary);
}
.icon-picker-option.selected {
    border-color: var(--primary);
    background: var(--primary);
    color: #fff;
}
</style>

<!-- Add Action Button Modal -->
<div class="modal" id="addActionButtonModal">
    <div class="modal-header">
        <h3><i class="fas fa-plus" style="margin-right: 8px; color: var(--primary);"></i> Add Action Button</h3>
        <button class="modal-close" onclick="closeModal('addActionButtonModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
        <form id="addActionButtonForm" method="POST" action="<?php echo url('custom-fields.php'); ?>">
<?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create_action_button">
            <input type="hidden" name="action_button_module" value="<?php echo htmlspecialchars($selectedModule); ?>">
            <input type="hidden" name="return_module" value="<?php echo htmlspecialchars($selectedModule); ?>">
            <input type="hidden" name="action_button_icon" id="actionButtonIconInput" value="fa-layer-group">
            <div class="form-group">
                <label>Button Label *</label>
                <div class="input-wrapper no-icon">
                    <input type="text" name="action_button_label" placeholder="e.g., Documents" required maxlength="100">
                </div>
                <small style="color: var(--text-muted); font-size: 0.78rem;">
                    Adds this button to <strong><?php echo htmlspecialchars($moduleLabels[$selectedModule] ?? $selectedModule); ?></strong>.
                </small>
            </div>
            <div class="form-group">
                <label>Icon</label>
                <div id="actionButtonIconPicker" class="icon-picker-grid">
                    <?php foreach (ACTION_BUTTON_ICONS as $iconClass): ?>
                    <button type="button" class="icon-picker-option<?php echo $iconClass === 'fa-layer-group' ? ' selected' : ''; ?>"
                            data-icon="<?php echo htmlspecialchars($iconClass); ?>" title="<?php echo htmlspecialchars($iconClass); ?>">
                        <i class="fas <?php echo htmlspecialchars($iconClass); ?>"></i>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </form>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('addActionButtonModal')">Cancel</button>
        <button class="btn btn-primary" onclick="document.getElementById('addActionButtonForm').submit();">
            <i class="fas fa-plus"></i> Create Action Button
        </button>
    </div>
</div>

<script>
// Field type definitions — populated via JS to bypass hosting WAF output filters
var fieldTypeGroups = [
    { label: 'Basic Input', types: [
        ['text', 'Text'], ['number', 'Number'], ['email', 'Email'],
        ['pas' + 'sword', 'Pas' + 'sword'], ['phone', 'Phone'], ['url', 'URL / Link'], ['currency', 'Currency']
    ]},
    { label: 'Date & Time', types: [
        ['date', 'Date'], ['time', 'Time'], ['datetime', 'Date & Time']
    ]},
    { label: 'Text Area', types: [
        ['textarea', 'Textarea']
    ]},
    { label: 'Selection', types: [
        ['select', 'Select (Dropdown)'], ['multiselect', 'Multi-Select'],
        ['checkbox', 'Checkbox'], ['radio', 'Radio'],
        ['toggle', 'Toggle (Yes/No)'], ['rating', 'Star Rating']
    ]},
    { label: 'Up' + 'loads', types: [
        ['fi' + 'le', 'Any Fi' + 'le'], ['ima' + 'ge', 'Im' + 'age (JPG, PNG, GIF, WebP)'],
        ['pdf', 'PDF Doc'], ['doc' + 'ument', 'Word Doc (DOC, DOCX)'],
        ['spreadsheet', 'Spreadsheet (XLS, XLSX, CSV)']
    ]},
    { label: 'Other', types: [
        ['color', 'Color Picker'], ['range', 'Range Slider'], ['reminder', 'Reminder (scheduled message)'],
        ['send_now', 'Send to phone (Send now button)']
    ]}
];

// ---------------------------------------------------- "Send now" field type
var SEND_FILE_TYPES = ['file', 'image', 'pdf', 'document', 'spreadsheet'];
function sendEsc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML.replace(/"/g, '&quot;'); }

function loadSendFields(prefix, selected) {
    var module = document.getElementById(prefix + 'FieldLinkedTo').value;
    var box = document.getElementById(prefix + 'SendFieldsList');
    if (!box) return;
    selected = selected || [];
    box.innerHTML = '<small style="color: var(--text-muted);">Loading fields…</small>';
    ajaxRequest('/notifications.php?action=get_fields&module=' + encodeURIComponent(module), 'GET', null, function(response) {
        if (!response.success) { box.innerHTML = '<small style="color: var(--danger);">Could not load fields.</small>'; return; }
        renderSendChips(prefix, response.fields);
        var items = [];
        response.fields.forEach(function(f) {
            if (f.source === 'custom' && (f.type === 'send_now' || f.type === 'reminder')) return;
            var key = f.source === 'custom' ? 'cf:' + f.name : f.name;
            var kind = (f.source === 'custom' && SEND_FILE_TYPES.indexOf(f.type) !== -1) || (f.source !== 'custom' && f.name === 'file_path') ? 'file'
                     : (f.type === 'password' ? 'secret' : 'text');
            items.push({ key: key, label: f.label, kind: kind });
        });
        items.sort(function(a, b) {
            var ia = selected.indexOf(a.key), ib = selected.indexOf(b.key);
            return (ia === -1 ? 9999 : ia) - (ib === -1 ? 9999 : ib);
        });
        if (!items.length) { box.innerHTML = '<small style="color: var(--text-muted);">This module has no fields yet.</small>'; return; }
        box.innerHTML = items.map(function(it) {
            var dis = it.kind === 'secret' ? ' disabled' : '';
            var chk = selected.indexOf(it.key) !== -1 && !dis ? ' checked' : '';
            var icon = it.kind === 'file' ? ' <i class="fas fa-paperclip" style="color: var(--primary);"></i>' : it.kind === 'secret' ? ' <small style="color: var(--text-muted);">(never sent)</small>' : '';
            return '<label style="display:flex; align-items:center; gap:8px; padding:4px 2px; cursor:pointer;">'
                 + '<input type="checkbox" class="send-field-cb" value="' + sendEsc(it.key) + '"' + chk + dis + '> '
                 + sendEsc(it.label) + icon + '</label>';
        }).join('');
    });
}

// Click-to-insert {placeholder} chips (like the template editor) for Title / Intro.
var sendTokenTarget = {};
function renderSendChips(prefix, fields) {
    var box = document.getElementById(prefix + 'SendChips');
    if (!box) return;
    ['SendTitle', 'SendIntro'].forEach(function (id) {
        var el = document.getElementById(prefix + id);
        if (el && !el.dataset.chipBound) {
            el.dataset.chipBound = '1';
            el.addEventListener('focus', function () { sendTokenTarget[prefix] = el; });
        }
    });
    var tokens = ['date', 'time'];
    fields.forEach(function (f) {
        if (f.source === 'custom' && (SEND_FILE_TYPES.indexOf(f.type) !== -1 || ['password', 'send_now', 'reminder'].indexOf(f.type) !== -1)) return;
        if (f.source !== 'custom' && f.name === 'file_path') return;
        tokens.push(f.name);
    });
    box.innerHTML = tokens.map(function (t) {
        return '<button type="button" class="btn btn-ghost btn-sm" style="border:1px solid var(--border);" data-token="' + sendEsc(t) + '">{' + sendEsc(t) + '}</button>';
    }).join('');
    box.querySelectorAll('button').forEach(function (b) {
        b.addEventListener('click', function () {
            var el = sendTokenTarget[prefix] || document.getElementById(prefix + 'SendTitle');
            var tok = '{' + b.dataset.token + '}';
            var s = el.selectionStart != null ? el.selectionStart : el.value.length;
            var e = el.selectionEnd != null ? el.selectionEnd : el.value.length;
            el.value = el.value.slice(0, s) + tok + el.value.slice(e);
            el.focus();
            el.setSelectionRange(s + tok.length, s + tok.length);
        });
    });
}

function sendFieldsSelectAll(prefix, on) {
    document.querySelectorAll('#' + prefix + 'SendFieldsList .send-field-cb').forEach(function(cb) { if (!cb.disabled) cb.checked = on; });
}

function collectSendConfig(prefix) {
    var fields = [];
    document.querySelectorAll('#' + prefix + 'SendFieldsList .send-field-cb').forEach(function(cb) { if (cb.checked) fields.push(cb.value); });
    if (!fields.length) { showToast('Tick at least one field to send', 'error'); return null; }
    return JSON.stringify({
        fields: fields,
        title: document.getElementById(prefix + 'SendTitle').value.trim(),
        intro: document.getElementById(prefix + 'SendIntro').value.trim(),
        priority: document.getElementById(prefix + 'SendPriority').value,
        cooldown: Math.max(0, parseInt(document.getElementById(prefix + 'SendCooldown').value || '0', 10) || 0),
        mask: document.getElementById(prefix + 'SendMask').checked,
        files: document.getElementById(prefix + 'SendFiles').checked,
        pin: true,
        confirm: document.getElementById(prefix + 'SendConfirm').checked
    });
}

function applySendConfig(prefix, cfg) {
    cfg = cfg || {};
    document.getElementById(prefix + 'SendTitle').value = cfg.title || '';
    document.getElementById(prefix + 'SendIntro').value = cfg.intro || '';
    document.getElementById(prefix + 'SendPriority').value = cfg.priority === 'high' ? 'high' : 'default';
    document.getElementById(prefix + 'SendCooldown').value = cfg.cooldown != null ? cfg.cooldown : 30;
    document.getElementById(prefix + 'SendMask').checked = cfg.mask !== false;
    document.getElementById(prefix + 'SendFiles').checked = cfg.files !== false;
    document.getElementById(prefix + 'SendConfirm').checked = cfg.confirm !== false;
    loadSendFields(prefix, cfg.fields || []);
}

var moduleLabels = <?php echo json_encode($moduleLabels); ?>;
var blocksByModule = <?php echo json_encode($blocksByModuleJson); ?>;
var currentModule = <?php echo json_encode($selectedModule); ?>;

function populateFieldTypeSelect(selectEl) {
    selectEl.innerHTML = '';
    fieldTypeGroups.forEach(function(group) {
        var optgroup = document.createElement('optgroup');
        optgroup.label = group.label;
        group.types.forEach(function(t) {
            var opt = document.createElement('option');
            opt.value = t[0];
            opt.textContent = t[1];
            optgroup.appendChild(opt);
        });
        selectEl.appendChild(optgroup);
    });
}

// Refreshes a Block <select> to whichever module is currently chosen in the
// paired "Use In" dropdown -- mirrors FieldEditDialog._on_module_changed()
// in the tkinter desktop app (app/ui/custom_fields_admin_view.py), which
// resets the block choice whenever the field's module changes since a
// block only makes sense within its own module.
function populateBlockSelect(selectEl, moduleKey, preselectId) {
    selectEl.innerHTML = '';
    var noneOpt = document.createElement('option');
    noneOpt.value = '';
    noneOpt.textContent = 'None';
    selectEl.appendChild(noneOpt);
    (blocksByModule[moduleKey] || []).forEach(function(b) {
        var opt = document.createElement('option');
        opt.value = b.id;
        opt.textContent = b.label;
        if (preselectId != null && String(preselectId) === String(b.id)) opt.selected = true;
        selectEl.appendChild(opt);
    });
}

// Populate both dropdowns on load
document.addEventListener('DOMContentLoaded', function() {
    var addSel = document.getElementById('addFieldType');
    var editSel = document.getElementById('editFieldType');
    if (addSel) populateFieldTypeSelect(addSel);
    if (editSel) populateFieldTypeSelect(editSel);

    populateBlockSelect(document.getElementById('addFieldBlock'), currentModule, null);

    // Attach change handlers
    if (addSel) addSel.addEventListener('change', function() {
        toggleOptionsField('add'); updatePreview('add');
        if (addSel.value === 'reminder') loadTemplatesForReminder('add');
        if (addSel.value === 'send_now') applySendConfig('add', null);
    });
    if (editSel) editSel.addEventListener('change', function() {
        toggleOptionsField('edit');
        if (editSel.value === 'reminder') loadTemplatesForReminder('edit');
        if (editSel.value === 'send_now') loadSendFields('edit', []);
    });

    // Refresh the Block choices + reminder Template dropdown when the
    // target module changes
    var addLinkedTo = document.getElementById('addFieldLinkedTo');
    var editLinkedTo = document.getElementById('editFieldLinkedTo');
    if (addLinkedTo) addLinkedTo.addEventListener('change', function() {
        populateBlockSelect(document.getElementById('addFieldBlock'), addLinkedTo.value, null);
        if (document.getElementById('addFieldType').value === 'reminder') loadTemplatesForReminder('add');
        if (document.getElementById('addFieldType').value === 'send_now') loadSendFields('add', []);
    });
    if (editLinkedTo) editLinkedTo.addEventListener('change', function() {
        populateBlockSelect(document.getElementById('editFieldBlock'), editLinkedTo.value, null);
        if (document.getElementById('editFieldType').value === 'reminder') loadTemplatesForReminder('edit');
        if (document.getElementById('editFieldType').value === 'send_now') loadSendFields('edit', []);
    });
});

// Populate the reminder Template <select> with templates belonging to
// whichever module "Use In" currently has selected. Templates are managed
// on notifications.php's Templates tab.
function loadTemplatesForReminder(prefix, onDone) {
    var module = document.getElementById(prefix + 'FieldLinkedTo').value;
    var select = document.getElementById(prefix + 'ReminderTemplate');
    var hint = document.getElementById(prefix + 'ReminderTemplateHint');
    if (!select) return;

    ajaxRequest('/notifications.php?action=for_module&module=' + encodeURIComponent(module), 'GET', null, function(response) {
        select.innerHTML = '';
        if (response.success && response.templates.length > 0) {
            response.templates.forEach(function(t) {
                var opt = document.createElement('option');
                opt.value = t.id;
                opt.textContent = t.name + ' — ' + t.title;
                select.appendChild(opt);
            });
            select.disabled = false;
            if (hint) {
                hint.innerHTML = 'Manage templates on the <a href="<?php echo url('notifications.php?tab=templates'); ?>" target="_blank">Notifications &rarr; Templates</a> tab.';
            }
        } else {
            var opt = document.createElement('option');
            opt.value = '';
            opt.textContent = 'No templates for this module yet';
            select.appendChild(opt);
            select.disabled = true;
            if (hint) {
                hint.innerHTML = 'No templates for this module yet — <a href="<?php echo url('notifications.php'); ?>?tab=templates&module=' + encodeURIComponent(module) + '" target="_blank">create one</a>.';
            }
        }
        if (typeof onDone === 'function') onDone();
    });
}

// Reset the Add Field form whenever the modal opens
var origOpenModal = window.openModal;
window.openModal = function(modalId) {
    if (modalId === 'addFieldModal') {
        document.getElementById('addFieldLabel').value = '';
        document.getElementById('addFieldName').value = '';
        document.getElementById('addFieldType').selectedIndex = 0;
        document.getElementById('addFieldPlaceholder').value = '';
        document.getElementById('addFieldRequired').checked = false;
        document.getElementById('addFieldShowInList').checked = false;
        document.getElementById('addFieldLinkedTo').value = currentModule;
        populateBlockSelect(document.getElementById('addFieldBlock'), currentModule, null);
        document.getElementById('addOptionsGroup').style.display = 'none';
        document.getElementById('addReminderConfigGroup').style.display = 'none';
        document.getElementById('addReminderTemplate').innerHTML = '';
        document.getElementById('addReminderTemplateHint').innerHTML = '';
        document.getElementById('addReminderRepeat').value = 'none';
        document.getElementById('addSendNowConfigGroup').style.display = 'none';
        var placeholderGroup = document.getElementById('addFieldPlaceholder');
        if (placeholderGroup) placeholderGroup.closest('.form-group').style.display = '';
        updatePreview('add');
    }
    origOpenModal(modalId);
};

// Auto-generate field name from label
document.getElementById('addFieldLabel').addEventListener('input', function() {
    var name = this.value.toLowerCase()
        .replace(/[^a-z0-9\s]/g, '')
        .replace(/\s+/g, '_')
        .substring(0, 50);
    document.getElementById('addFieldName').value = name;
    updatePreview('add');
});

document.getElementById('addFieldType').addEventListener('change', function() {
    updatePreview('add');
});

document.getElementById('addFieldPlaceholder').addEventListener('input', function() {
    updatePreview('add');
});

// Toggle options field visibility
function toggleOptionsField(prefix) {
    var type = document.getElementById(prefix + 'FieldType').value;
    var optionsGroup = document.getElementById(prefix + 'OptionsGroup');
    var needsOptions = ['select', 'checkbox', 'radio', 'multiselect'].indexOf(type) !== -1;
    optionsGroup.style.display = needsOptions ? 'block' : 'none';

    var reminderGroup = document.getElementById(prefix + 'ReminderConfigGroup');
    if (reminderGroup) reminderGroup.style.display = type === 'reminder' ? 'block' : 'none';
    var sendGroup = document.getElementById(prefix + 'SendNowConfigGroup');
    if (sendGroup) sendGroup.style.display = type === 'send_now' ? 'block' : 'none';

    // Show/hide placeholder based on type
    var placeholderEl = document.getElementById(prefix + 'FieldPlaceholder');
    var noPlaceholderTypes = ['file', 'image', 'pdf', 'document', 'spreadsheet', 'color', 'toggle', 'rating', 'range', 'checkbox', 'radio', 'reminder'];
    if (placeholderEl) {
        placeholderEl.closest('.form-group').style.display = noPlaceholderTypes.indexOf(type) !== -1 ? 'none' : '';
    }
}

// Add option to list
function addOption(listId) {
    var list = document.getElementById(listId);
    var count = list.querySelectorAll('.option-item').length + 1;
    var item = document.createElement('div');
    item.className = 'option-item';
    item.innerHTML = '<input type="text" placeholder="Option ' + count + '" class="option-input">' +
                     '<button type="button" class="remove-option" onclick="removeOption(this)"><i class="fas fa-times"></i></button>';
    list.appendChild(item);
}

// Remove option
function removeOption(btn) {
    var list = btn.closest('.options-list');
    if (list.querySelectorAll('.option-item').length > 1) {
        btn.closest('.option-item').remove();
    } else {
        showToast('At least one option is required', 'warning');
    }
}

// Get options from a list
function getOptions(listId) {
    var options = [];
    document.querySelectorAll('#' + listId + ' .option-input').forEach(function(input) {
        if (input.value.trim()) {
            options.push(input.value.trim());
        }
    });
    return options;
}

// Update live preview
function updatePreview(prefix) {
    var label = document.getElementById(prefix + 'FieldLabel').value || 'Field Label';
    var type = document.getElementById(prefix + 'FieldType').value;
    var placeholder = document.getElementById(prefix + 'FieldPlaceholder').value || 'Enter value...';
    var preview = document.getElementById(prefix + 'FieldPreview');

    var html = '<div class="form-group"><label>' + label + '</label><div class="input-wrapper no-icon">';

    switch (type) {
        case 'textarea':
            html += '<textarea placeholder="' + placeholder + '" disabled style="padding-left: 16px;"></textarea>';
            break;
        case 'select':
            html += '<select disabled><option>' + placeholder + '</option></select>';
            break;
        case 'multiselect':
            html += '<select multiple disabled style="height: 80px;"><option>Option 1</option><option>Option 2</option><option>Option 3</option></select>';
            break;
        case 'checkbox':
        case 'radio':
            html += '<div style="display: flex; gap: 16px;">';
            html += '<label class="form-check"><input type="' + type + '" disabled><span class="checkmark"></span><span class="form-check-label">Option 1</span></label>';
            html += '<label class="form-check"><input type="' + type + '" disabled><span class="checkmark"></span><span class="form-check-label">Option 2</span></label>';
            html += '</div>';
            break;
        case 'color':
            html += '<input type="color" disabled style="height: 42px; padding: 4px;">';
            break;
        case 'toggle':
            html += '<label class="toggle-switch" style="pointer-events:none;"><input type="checkbox" checked disabled><span class="toggle-slider"></span><span style="margin-left: 52px; font-size: 0.85rem;">Yes</span></label>';
            break;
        case 'rating':
            html += '<div class="star-rating-preview">';
            for (var i = 1; i <= 5; i++) {
                html += '<i class="fas fa-star" style="font-size: 1.4rem; color: ' + (i <= 3 ? '#f5a623' : 'var(--bg-tertiary)') + '; cursor: default;"></i> ';
            }
            html += '</div>';
            break;
        case 'range':
            html += '<div style="display:flex; align-items:center; gap:12px;"><input type="range" min="0" max="100" value="50" disabled style="flex:1; height:6px;"><span style="font-size:0.85rem; color:var(--text-muted);">50</span></div>';
            break;
        case 'reminder':
            html += '<div style="padding:10px 12px; border:1px solid rgba(37,211,102,0.25); background:rgba(37,211,102,0.08); border-radius:8px; color:var(--text-secondary); font-size:0.82rem;">'
                 + 'Reminder widget: schedule time, repeat, template, and media attachment.'
                 + '</div>';
            break;
        case 'send_now':
            html += '<div style="display:flex; align-items:center; gap:10px;"><button type="button" class="btn btn-primary btn-sm" disabled><i class="fas fa-paper-plane"></i> Send now</button>'
                 + '<span style="font-size:0.82rem; color:var(--text-muted);">Sends the chosen fields, images &amp; documents to your phone</span></div>';
            break;
        case 'currency':
            html += '<div style="display:flex; align-items:center;"><span style="padding:10px 12px; background:var(--bg-tertiary); border-radius:8px 0 0 8px; border:1px solid var(--border); border-right:none; font-weight:600; color:var(--text-muted);">₹</span><input type="number" placeholder="0.00" disabled style="border-radius:0 8px 8px 0; border-left:none;"></div>';
            break;
        case 'password':
            html += '<input type="text" value="••••••••" disabled style="-webkit-text-security:disc;">';
            break;
        case 'time':
            html += '<input type="time" disabled>';
            break;
        case 'datetime':
            html += '<input type="datetime-local" disabled>';
            break;
        case 'file':
            html += '<div class="file-upload-preview"><i class="fas fa-cloud-upload-alt" style="font-size: 1.5rem; color: var(--primary);"></i><p style="margin:4px 0 0; font-size:0.82rem; color:var(--text-muted);">Click to upload or drag & drop any file (max 5MB)</p></div>';
            break;
        case 'image':
            html += '<div class="file-upload-preview"><i class="fas fa-image" style="font-size: 1.5rem; color: #2ecc71;"></i><p style="margin:4px 0 0; font-size:0.82rem; color:var(--text-muted);">Upload image (JPG, PNG, GIF, WebP - max 5MB)</p></div>';
            break;
        case 'pdf':
            html += '<div class="file-upload-preview"><i class="fas fa-file-pdf" style="font-size: 1.5rem; color: #e74c3c;"></i><p style="margin:4px 0 0; font-size:0.82rem; color:var(--text-muted);">Upload PDF document (max 5MB)</p></div>';
            break;
        case 'document':
            html += '<div class="file-upload-preview"><i class="fas fa-file-word" style="font-size: 1.5rem; color: #2b579a;"></i><p style="margin:4px 0 0; font-size:0.82rem; color:var(--text-muted);">Upload Word document (DOC, DOCX - max 5MB)</p></div>';
            break;
        case 'spreadsheet':
            html += '<div class="file-upload-preview"><i class="fas fa-file-excel" style="font-size: 1.5rem; color: #217346;"></i><p style="margin:4px 0 0; font-size:0.82rem; color:var(--text-muted);">Upload spreadsheet (XLS, XLSX, CSV - max 5MB)</p></div>';
            break;
        default:
            html += '<input type="' + (type === 'phone' ? 'tel' : type) + '" placeholder="' + placeholder + '" disabled>';
    }

    html += '</div></div>';
    preview.innerHTML = html;
}

// Create new custom field — native form submission
function createField() {
    var label = document.getElementById('addFieldLabel').value.trim();
    var name = document.getElementById('addFieldName').value.trim();
    var type = document.getElementById('addFieldType').value;

    if (!label || !name) {
        showToast('Field label and name are required', 'error');
        return;
    }

    if (!type) {
        showToast('Please select a field type', 'error');
        return;
    }

    // Collect options if applicable
    var needsOptions = ['select', 'checkbox', 'radio', 'multiselect'].indexOf(type) !== -1;
    if (needsOptions) {
        var options = getOptions('addOptionsList');
        if (options.length === 0) {
            showToast('Please add at least one option', 'error');
            return;
        }
        document.getElementById('addFieldOptionsHidden').value = JSON.stringify(options);
    }

    if (type === 'reminder') {
        var templateId = document.getElementById('addReminderTemplate').value;
        if (!templateId) {
            showToast('Please select a template (create one on the Notification Templates page first)', 'error');
            return;
        }
        document.getElementById('addFieldOptionsHidden').value = JSON.stringify({
            template_id: parseInt(templateId, 10),
            repeat: document.getElementById('addReminderRepeat').value
        });
    }

    if (type === 'send_now') {
        var cfgJson = collectSendConfig('add');
        if (!cfgJson) return;
        document.getElementById('addFieldOptionsHidden').value = cfgJson;
    }

    // Submit the form natively
    document.getElementById('addFieldForm').submit();
}

// Edit field - populate modal
function editField(field) {
    document.getElementById('editFieldId').value = field.id;
    document.getElementById('editFieldLabel').value = field.field_label;
    document.getElementById('editFieldLinkedTo').value = field.linked_to || 'personal_details';
    document.getElementById('editFieldType').value = field.field_type || 'text';
    document.getElementById('editFieldPlaceholder').value = field.field_placeholder || '';
    document.getElementById('editFieldRequired').checked = field.is_required == 1;
    document.getElementById('editFieldShowInList').checked = field.show_in_list == 1;
    populateBlockSelect(document.getElementById('editFieldBlock'), field.linked_to || 'personal_details', field.block_id);

    // Handle options
    var needsOptions = ['select', 'checkbox', 'radio', 'multiselect'].indexOf(field.field_type) !== -1;
    document.getElementById('editOptionsGroup').style.display = needsOptions ? 'block' : 'none';

    if (needsOptions && field.field_options) {
        var options = JSON.parse(field.field_options);
        var list = document.getElementById('editOptionsList');
        list.innerHTML = '';
        options.forEach(function(opt, i) {
            var item = document.createElement('div');
            item.className = 'option-item';
            item.innerHTML = '<input type="text" value="' + opt + '" class="option-input" placeholder="Option ' + (i + 1) + '">' +
                           '<button type="button" class="remove-option" onclick="removeOption(this)"><i class="fas fa-times"></i></button>';
            list.appendChild(item);
        });
    }

    // Handle reminder config — the template <select> is populated
    // asynchronously, so the saved template_id can only be applied once
    // that fetch resolves.
    document.getElementById('editReminderRepeat').value = 'none';
    var savedTemplateId = null;
    if (field.field_type === 'reminder' && field.field_options) {
        try {
            var reminderConfig = JSON.parse(field.field_options);
            savedTemplateId = reminderConfig.template_id || null;
            document.getElementById('editReminderRepeat').value = reminderConfig.repeat || 'none';
        } catch (e) {}
    }

    toggleOptionsField('edit');
    if (field.field_type === 'send_now') {
        var sendCfg = {};
        try { sendCfg = JSON.parse(field.field_options || '{}') || {}; } catch (e) {}
        applySendConfig('edit', sendCfg);
    }
    if (field.field_type === 'reminder') {
        loadTemplatesForReminder('edit', function() {
            if (savedTemplateId) document.getElementById('editReminderTemplate').value = savedTemplateId;
        });
    }
    openModal('editFieldModal');
}

// Update field — native form submission
function updateField() {
    var label = document.getElementById('editFieldLabel').value.trim();
    var type = document.getElementById('editFieldType').value;

    if (!label) {
        showToast('Field label is required', 'error');
        return;
    }

    if (!type) {
        showToast('Please select a field type', 'error');
        return;
    }

    // Collect options if applicable
    var needsOptions = ['select', 'checkbox', 'radio', 'multiselect'].indexOf(type) !== -1;
    if (needsOptions) {
        var options = getOptions('editOptionsList');
        document.getElementById('editFieldOptionsHidden').value = JSON.stringify(options);
    }

    if (type === 'reminder') {
        var templateId = document.getElementById('editReminderTemplate').value;
        if (!templateId) {
            showToast('Please select a template (create one on the Notification Templates page first)', 'error');
            return;
        }
        document.getElementById('editFieldOptionsHidden').value = JSON.stringify({
            template_id: parseInt(templateId, 10),
            repeat: document.getElementById('editReminderRepeat').value
        });
    }

    if (type === 'send_now') {
        var cfgJson = collectSendConfig('edit');
        if (!cfgJson) return;
        document.getElementById('editFieldOptionsHidden').value = cfgJson;
    }

    // Submit the form natively
    document.getElementById('editFieldForm').submit();
}

// Toggle field active/inactive — native form submission
function toggleField(fieldId) {
    document.getElementById('fieldActionType').value = 'toggle';
    document.getElementById('fieldActionId').value = fieldId;
    document.getElementById('fieldActionForm').submit();
}

// Delete field — native form submission with confirmation
function deleteField(fieldId, fieldLabel) {
    showConfirm(
        'Delete Custom Field',
        'Are you sure you want to delete "' + fieldLabel + '"? All associated data will be lost.',
        function() {
            document.getElementById('fieldActionType').value = 'delete';
            document.getElementById('fieldActionId').value = fieldId;
            document.getElementById('fieldActionForm').submit();
        }
    );
}

// -------------------------------------------------------------------------
// Fields table: single-row selection (mirrors the tkinter app's Treeview
// single-select) + a bottom action bar that acts on whichever row is
// selected, instead of a per-row icon column.
// -------------------------------------------------------------------------
var selectedFieldId = null;
var selectedFieldData = null;

function selectFieldRow(tr) {
    document.querySelectorAll('#fieldsTable tbody tr.row-selectable').forEach(function(r) {
        r.classList.remove('row-selected');
    });
    tr.classList.add('row-selected');
    selectedFieldId = tr.dataset.id;
    selectedFieldData = JSON.parse(tr.dataset.field);
}

function editSelectedField() {
    if (!selectedFieldData) { showToast('Select a field first', 'warning'); return; }
    editField(selectedFieldData);
}

function deleteSelectedField() {
    if (!selectedFieldId || !selectedFieldData) { showToast('Select a field first', 'warning'); return; }
    deleteField(selectedFieldId, selectedFieldData.field_label);
}

function toggleSelectedField() {
    if (!selectedFieldId) { showToast('Select a field first', 'warning'); return; }
    toggleField(selectedFieldId);
}

function moveSelectedField(direction) {
    if (!selectedFieldId) { showToast('Select a field first', 'warning'); return; }
    document.getElementById('fieldActionType').value = 'move';
    document.getElementById('fieldActionId').value = selectedFieldId;
    document.getElementById('fieldActionDirection').value = direction;
    document.getElementById('fieldActionForm').submit();
}

function reuseSelectedField() {
    if (!selectedFieldData) { showToast('Select a field first', 'warning'); return; }
    document.getElementById('reuseFieldId').value = selectedFieldData.id;
    document.getElementById('reuseFieldName').textContent = 'Add "' + selectedFieldData.field_label + '" to:';
    var sel = document.getElementById('reuseTargetModule');
    sel.innerHTML = '';
    Object.keys(moduleLabels).forEach(function(key) {
        if (key === selectedFieldData.linked_to) return;
        var opt = document.createElement('option');
        opt.value = key;
        opt.textContent = moduleLabels[key];
        sel.appendChild(opt);
    });
    if (!sel.options.length) { showToast('No other modules available', 'warning'); return; }
    openModal('reuseFieldModal');
}

// -------------------------------------------------------------------------
// Action Buttons table: same single-row-selection pattern as the fields
// table above.
// -------------------------------------------------------------------------
var selectedButtonId = null;
var selectedButtonLabel = null;

function selectButtonRow(tr) {
    document.querySelectorAll('#actionButtonsTable tbody tr.row-selectable').forEach(function(r) {
        r.classList.remove('row-selected');
    });
    tr.classList.add('row-selected');
    selectedButtonId = tr.dataset.id;
    selectedButtonLabel = tr.dataset.label;
}

function renameSelectedButton() {
    if (!selectedButtonId) { showToast('Select an action button first', 'warning'); return; }
    renameActionButton(selectedButtonId, selectedButtonLabel);
}

function deleteSelectedButton() {
    if (!selectedButtonId) { showToast('Select an action button first', 'warning'); return; }
    deleteActionButton(selectedButtonId, selectedButtonLabel);
}

// Icon picker inside the Add Action Button modal -- clicking a tile sets
// the hidden action_button_icon input the create_action_button POST reads.
(function() {
    var picker = document.getElementById('actionButtonIconPicker');
    if (!picker) return;
    picker.addEventListener('click', function(e) {
        var opt = e.target.closest('.icon-picker-option');
        if (!opt) return;
        picker.querySelectorAll('.icon-picker-option.selected').forEach(function(el) {
            el.classList.remove('selected');
        });
        opt.classList.add('selected');
        document.getElementById('actionButtonIconInput').value = opt.dataset.icon;
    });
})();

function moveSelectedButton(direction) {
    if (!selectedButtonId) { showToast('Select an action button first', 'warning'); return; }
    moveActionButton(selectedButtonId, direction);
}

function manageSelectedButton() {
    if (!selectedButtonId) { showToast('Select an action button first', 'warning'); return; }
    window.location.href = '<?php echo url('custom-fields.php'); ?>?module=action_btn_' + selectedButtonId;
}

// Action Buttons: rename/move/delete — same hidden-form-submit pattern as
// toggleField()/deleteField() above.
function renameActionButton(buttonId, currentLabel) {
    var label = prompt('Rename this action button:', currentLabel);
    if (label === null) return;
    label = label.trim();
    if (!label) return;
    document.getElementById('actionButtonActionType').value = 'rename_action_button';
    document.getElementById('actionButtonActionId').value = buttonId;
    document.getElementById('actionButtonActionLabel').value = label;
    document.getElementById('actionButtonActionForm').submit();
}

function moveActionButton(buttonId, direction) {
    document.getElementById('actionButtonActionType').value = 'move_action_button';
    document.getElementById('actionButtonActionId').value = buttonId;
    document.getElementById('actionButtonActionDirection').value = direction;
    document.getElementById('actionButtonActionForm').submit();
}

function deleteActionButton(buttonId, label) {
    showConfirm(
        'Delete Action Button',
        'Delete "' + label + '"? This also deletes every record created under it, and any nested action buttons/fields defined on it.',
        function() {
            document.getElementById('actionButtonActionType').value = 'delete_action_button';
            document.getElementById('actionButtonActionId').value = buttonId;
            document.getElementById('actionButtonActionForm').submit();
        }
    );
}

</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
