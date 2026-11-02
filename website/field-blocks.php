<?php
$pageTitle = 'Field Blocks';

// Handle AJAX requests BEFORE including header
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/custom_fields_helper.php';

if (isAjax()) {
    $user = requireAuth();
    $db = Database::getInstance();
    $action = $_GET['action'] ?? '';

    // These are GET-based AJAX reads — no POST data needed, WAF won't block
    switch ($action) {
        case 'get_block_fields':
            $blockId = (int)($_GET['block_id'] ?? 0);
            $fields = $db->fetchAll(
                "SELECT * FROM custom_fields WHERE block_id = ? AND user_id = ? ORDER BY field_order_in_block ASC",
                [$blockId, $user['id']]
            );
            jsonResponse(['success' => true, 'fields' => $fields]);
            break;

        case 'get_available_fields':
            $linkedTo = sanitize($_GET['linked_to'] ?? 'personal_details');
            $blockId = isset($_GET['block_id']) ? (int)$_GET['block_id'] : null;
            if ($blockId) {
                $fields = $db->fetchAll(
                    "SELECT * FROM custom_fields WHERE user_id = ? AND linked_to = ? AND (block_id IS NULL OR block_id = ?) ORDER BY field_order ASC",
                    [$user['id'], $linkedTo, $blockId]
                );
            } else {
                $fields = $db->fetchAll(
                    "SELECT * FROM custom_fields WHERE user_id = ? AND linked_to = ? AND block_id IS NULL ORDER BY field_order ASC",
                    [$user['id'], $linkedTo]
                );
            }
            jsonResponse(['success' => true, 'fields' => $fields]);
            break;

        case 'get_block_preview':
            $blockId = (int)($_GET['block_id'] ?? 0);
            $block = $db->fetch("SELECT * FROM custom_field_blocks WHERE id = ? AND user_id = ?", [$blockId, $user['id']]);
            if (!$block) {
                jsonResponse(['success' => false, 'error' => 'Block not found'], 404);
            }
            $fields = $db->fetchAll(
                "SELECT * FROM custom_fields WHERE block_id = ? AND user_id = ? ORDER BY field_order_in_block ASC",
                [$blockId, $user['id']]
            );
            jsonResponse(['success' => true, 'block' => $block, 'fields' => $fields]);
            break;

        default:
            jsonResponse(['success' => false, 'error' => 'Invalid action'], 400);
    }
    exit;
}

// Handle form POST actions (native form submission — works on all hosting)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    $user = requireAuth();
    if (!Auth::validateCSRF($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Your session expired or the request could not be verified. Please try again.');
        redirect('/field-blocks.php');
        exit;
    }
    $db = Database::getInstance();
    require_once __DIR__ . '/includes/fields_engine.php';
    feEnsureSchema();
    $action = $_POST['action'];

    switch ($action) {
        case 'create_block':
            $blockName = sanitize($_POST['block_name'] ?? '');
            $blockLabel = sanitize($_POST['block_label'] ?? '');
            $linkedTo = sanitize($_POST['linked_to'] ?? 'personal_details');
            $layout = sanitize($_POST['layout'] ?? '1-col');
            $returnModule = sanitize($_POST['return_module'] ?? $linkedTo);

            if (empty($blockName) || empty($blockLabel)) {
                setFlash('error', 'Block name and label are required');
                redirect('/field-blocks.php?module=' . urlencode($returnModule));
            }

            $blockName = preg_replace('/[^a-z0-9_]/', '_', strtolower($blockName));
            $validLayouts = ['1-col', '2-col', '3-col', '4-col', '2-1', '1-2'];
            if (!in_array($layout, $validLayouts)) $layout = '1-col';

            $isRepeatable = (int)($_POST['is_repeatable'] ?? 0);
            $maxInstances = max(1, min(50, (int)($_POST['max_instances'] ?? 10)));

            if (!feModuleExists($linkedTo, $user['id'])) {
                setFlash('error', 'That module no longer exists -- pick another one.');
                redirect('/field-blocks.php');
            }

            // Ordered within its own module (was numbered across all modules).
            $maxOrder = $db->fetch("SELECT MAX(block_order) as mo FROM custom_field_blocks WHERE user_id = ? AND linked_to = ?", [$user['id'], $linkedTo]);
            $nextOrder = ($maxOrder['mo'] ?? 0) + 1;

            $db->insert(
                "INSERT INTO custom_field_blocks (user_id, block_name, block_label, linked_to, layout, block_order, is_repeatable, max_instances) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$user['id'], $blockName, $blockLabel, $linkedTo, $layout, $nextOrder, $isRepeatable, $maxInstances]
            );

            setFlash('success', 'Block created successfully');
            redirect('/field-blocks.php?module=' . urlencode($returnModule));
            break;

        case 'update_block':
            $blockId = (int)($_POST['block_id'] ?? 0);
            $blockLabel = sanitize($_POST['block_label'] ?? '');
            $layout = sanitize($_POST['layout'] ?? '1-col');
            $returnModule = sanitize($_POST['return_module'] ?? '');

            if (empty($blockLabel)) {
                setFlash('error', 'Block label is required');
                redirect('/field-blocks.php?module=' . urlencode($returnModule));
            }

            $validLayouts = ['1-col', '2-col', '3-col', '4-col', '2-1', '1-2'];
            if (!in_array($layout, $validLayouts)) $layout = '1-col';

            $isRepeatable = (int)($_POST['is_repeatable'] ?? 0);
            $maxInstances = max(1, min(50, (int)($_POST['max_instances'] ?? 10)));

            // Note: a block's module (linked_to) is never editable here --
            // same as the tkinter desktop app's BlockEditDialog, which has no
            // Module field at all (it's fixed to whichever module was
            // selected in the parent view when Add/Edit was opened).
            $db->update(
                "UPDATE custom_field_blocks SET block_label = ?, layout = ?, is_repeatable = ?, max_instances = ? WHERE id = ? AND user_id = ?",
                [$blockLabel, $layout, $isRepeatable, $maxInstances, $blockId, $user['id']]
            );

            setFlash('success', 'Block updated successfully');
            redirect('/field-blocks.php?module=' . urlencode($returnModule));
            break;

        case 'delete_block':
            $blockId = (int)($_POST['block_id'] ?? 0);
            $returnModule = sanitize($_POST['return_module'] ?? '');
            // Only the block goes: its fields -- and every value and file saved
            // in them -- stay, just no longer grouped. (This used to delete all
            // of those fields' saved values and files while keeping the fields.)
            feDeleteBlock($blockId, $user['id']);
            setFlash('success', 'Block deleted -- its fields and their saved values were kept');
            redirect('/field-blocks.php?module=' . urlencode($returnModule));
            break;

        case 'toggle_block':
            $blockId = (int)($_POST['block_id'] ?? 0);
            $returnModule = sanitize($_POST['return_module'] ?? '');
            $db->update("UPDATE custom_field_blocks SET is_active = NOT is_active WHERE id = ? AND user_id = ?", [$blockId, $user['id']]);
            setFlash('success', 'Block status toggled');
            redirect('/field-blocks.php?module=' . urlencode($returnModule));
            break;

        case 'move_block':
            $blockId = (int)($_POST['block_id'] ?? 0);
            $direction = (int)($_POST['direction'] ?? 0);
            $returnModule = sanitize($_POST['return_module'] ?? '');
            $block = $db->fetch("SELECT id, linked_to FROM custom_field_blocks WHERE id = ? AND user_id = ?", [$blockId, $user['id']]);
            if ($block && $direction !== 0) {
                $siblings = $db->fetchAll(
                    "SELECT id, block_order FROM custom_field_blocks WHERE user_id = ? AND linked_to = ? ORDER BY block_order ASC, id ASC",
                    [$user['id'], $block['linked_to']]
                );
                // Blocks sharing an order number can't be swapped visibly -- renumber first.
                if (count(array_unique(array_column($siblings, 'block_order'))) !== count($siblings)) {
                    foreach ($siblings as $pos => $s) {
                        $db->update("UPDATE custom_field_blocks SET block_order = ? WHERE id = ? AND user_id = ?", [$pos + 1, $s['id'], $user['id']]);
                        $siblings[$pos]['block_order'] = $pos + 1;
                    }
                }
                $ids = array_column($siblings, 'id');
                $idx = array_search((int)$blockId, $ids, true);
                if ($idx !== false) {
                    $newIdx = $idx + $direction;
                    if ($newIdx >= 0 && $newIdx < count($siblings)) {
                        $a = $siblings[$idx];
                        $b = $siblings[$newIdx];
                        $db->update("UPDATE custom_field_blocks SET block_order = ? WHERE id = ? AND user_id = ?", [$b['block_order'], $a['id'], $user['id']]);
                        $db->update("UPDATE custom_field_blocks SET block_order = ? WHERE id = ? AND user_id = ?", [$a['block_order'], $b['id'], $user['id']]);
                    }
                }
            }
            redirect('/field-blocks.php?module=' . urlencode($returnModule));
            break;

        case 'assign_fields':
            $blockId = (int)($_POST['block_id'] ?? 0);
            $fieldIds = json_decode($_POST['field_ids'] ?? '[]', true);
            $returnModule = sanitize($_POST['return_module'] ?? '');

            if (!is_array($fieldIds)) {
                setFlash('error', 'Invalid field IDs');
                redirect('/field-blocks.php?module=' . urlencode($returnModule));
            }

            $block = $db->fetch("SELECT * FROM custom_field_blocks WHERE id = ? AND user_id = ?", [$blockId, $user['id']]);
            if (!$block) {
                setFlash('error', 'Block not found');
                redirect('/field-blocks.php?module=' . urlencode($returnModule));
            }

            feTransaction(function () use ($db, $user, $block, $blockId, $fieldIds) {
                $db->update("UPDATE custom_fields SET block_id = NULL, field_order_in_block = 0 WHERE block_id = ? AND user_id = ?", [$blockId, $user['id']]);
                foreach (array_values($fieldIds) as $order => $fieldId) {
                    // Only fields of the block's own module can be grouped in it.
                    $db->update(
                        "UPDATE custom_fields SET block_id = ?, field_order_in_block = ? WHERE id = ? AND user_id = ? AND linked_to = ?",
                        [$blockId, $order, (int)$fieldId, $user['id'], $block['linked_to']]
                    );
                }
            });

            setFlash('success', 'Fields assigned to block');
            redirect('/field-blocks.php?module=' . urlencode($returnModule));
            break;

        default:
            setFlash('error', 'Invalid action');
            redirect('/field-blocks.php');
    }
    exit;
}

// Normal page load
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/custom_fields_helper.php';
$user = requireAuth();
$db = Database::getInstance();

// Module picker -- everything below it is scoped to one module at a time,
// the same as the tkinter desktop app's "Module:" combobox on this screen
// (app/ui/field_blocks_admin_view.py) driving its refresh().
$moduleLabels = getModuleLabels();
$selectedModule = sanitize($_GET['module'] ?? '');
if (!isset($moduleLabels[$selectedModule])) {
    $selectedModule = $moduleLabels ? array_key_first($moduleLabels) : '';
}

$blocks = $selectedModule
    ? $db->fetchAll(
        "SELECT b.*, (SELECT COUNT(*) FROM custom_fields cf WHERE cf.block_id = b.id) as field_count
         FROM custom_field_blocks b
         WHERE b.user_id = ? AND b.linked_to = ?
         ORDER BY b.block_order ASC",
        [$user['id'], $selectedModule]
    )
    : [];

// Layout display labels
$layoutLabels = [
    '1-col' => '1 Column',
    '2-col' => '2 Columns',
    '3-col' => '3 Columns',
    '4-col' => '4 Columns',
    '2-1'   => '2/3 + 1/3',
    '1-2'   => '1/3 + 2/3',
];
?>

<!-- Toolbar -->
<div class="toolbar">
    <div class="toolbar-left">
        <label style="font-size: 0.85rem; color: var(--text-secondary); font-weight: 600;">Module:</label>
        <select id="moduleSelect" class="filter-select" style="min-width: 260px;"
                onchange="window.location.href='<?php echo url('field-blocks.php'); ?>?module=' + encodeURIComponent(this.value)">
            <?php foreach ($moduleLabels as $key => $label): ?>
            <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $key === $selectedModule ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="toolbar-right">
        <a href="<?php echo url('custom-fields.php?module=' . urlencode($selectedModule)); ?>" class="btn btn-secondary">
            <i class="fas fa-puzzle-piece"></i> Manage Fields
        </a>
        <a href="<?php echo url('field-blocks.php?module=' . urlencode($selectedModule)); ?>" class="btn btn-secondary">
            <i class="fas fa-sync"></i> Refresh
        </a>
        <button class="btn btn-primary" onclick="openModal('addBlockModal')">
            <i class="fas fa-plus"></i> Add Block
        </button>
    </div>
</div>

<!-- Blocks for the selected module -->
<div class="card animate-fade-in">
    <div class="card-header">
        <h2><i class="fas fa-th-large" style="margin-right: 8px; color: var(--primary);"></i> Field Blocks — <?php echo htmlspecialchars($moduleLabels[$selectedModule] ?? $selectedModule); ?></h2>
    </div>

    <div class="table-container" style="border: none;">
        <table class="data-table" id="blocksTable">
            <thead>
                <tr>
                    <th>Label</th>
                    <th>Layout</th>
                    <th>Repeatable</th>
                    <th>Max Instances</th>
                    <th>Active</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($blocks)): ?>
                <tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 28px;">No field blocks defined for this module yet.</td></tr>
                <?php else: foreach ($blocks as $block): ?>
                <tr class="row-selectable" data-id="<?php echo $block['id']; ?>"
                    data-block='<?php echo htmlspecialchars(json_encode($block), ENT_QUOTES, 'UTF-8'); ?>'
                    onclick="selectBlockRow(this)" ondblclick="previewSelectedBlock()">
                    <td><strong><?php echo htmlspecialchars($block['block_label']); ?></strong></td>
                    <td><?php echo htmlspecialchars($layoutLabels[$block['layout']] ?? $block['layout']); ?></td>
                    <td><?php echo $block['is_repeatable'] ? 'Yes' : 'No'; ?></td>
                    <td><?php echo (int)$block['max_instances']; ?></td>
                    <td><?php echo $block['is_active'] ? 'Yes' : 'No'; ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--border);">
        <button class="btn btn-secondary btn-sm" onclick="previewSelectedBlock()"><i class="fas fa-eye"></i> Preview</button>
        <button class="btn btn-secondary btn-sm" onclick="manageFieldsSelectedBlock()"><i class="fas fa-puzzle-piece"></i> Manage Fields</button>
        <button class="btn btn-secondary btn-sm" onclick="editSelectedBlock()"><i class="fas fa-pen"></i> Edit</button>
        <button class="btn btn-secondary btn-sm" onclick="deleteSelectedBlock()"><i class="fas fa-trash"></i> Delete</button>
        <button class="btn btn-secondary btn-sm" onclick="toggleSelectedBlock()"><i class="fas fa-power-off"></i> Toggle Active</button>
        <button class="btn btn-secondary btn-sm" onclick="moveSelectedBlock(-1)"><i class="fas fa-arrow-up"></i> Move Up</button>
        <button class="btn btn-secondary btn-sm" onclick="moveSelectedBlock(1)"><i class="fas fa-arrow-down"></i> Move Down</button>
    </div>
</div>

<!-- Layout Guide -->
<div class="card" style="margin-top: 24px;">
    <div class="card-header">
        <h2><i class="fas fa-info-circle" style="margin-right: 8px; color: var(--info);"></i> Available Layouts</h2>
    </div>
    <div class="details-grid" style="grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));">
        <?php
        $layoutGuide = [
            ['layout' => '1-col', 'label' => '1 Column', 'desc' => 'Fields stacked vertically, full width', 'icon' => 'fa-bars'],
            ['layout' => '2-col', 'label' => '2 Columns', 'desc' => 'Fields side by side, equal width', 'icon' => 'fa-columns'],
            ['layout' => '3-col', 'label' => '3 Columns', 'desc' => 'Three equal columns', 'icon' => 'fa-th'],
            ['layout' => '4-col', 'label' => '4 Columns', 'desc' => 'Four equal columns - compact', 'icon' => 'fa-th-large'],
            ['layout' => '2-1', 'label' => '2/3 + 1/3', 'desc' => 'Wide left column, narrow right', 'icon' => 'fa-indent'],
            ['layout' => '1-2', 'label' => '1/3 + 2/3', 'desc' => 'Narrow left column, wide right', 'icon' => 'fa-outdent'],
        ];
        foreach ($layoutGuide as $lg):
        ?>
        <div style="display: flex; gap: 12px; align-items: flex-start; padding: 12px; border-radius: var(--radius-sm); background: var(--bg-tertiary);">
            <div style="width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; background: rgba(108,99,255,0.1); color: var(--primary); flex-shrink: 0;">
                <i class="fas <?php echo $lg['icon']; ?>"></i>
            </div>
            <div>
                <strong style="font-size: 0.85rem;"><?php echo $lg['label']; ?></strong>
                <p style="font-size: 0.78rem; color: var(--text-muted); margin: 2px 0 0;"><?php echo $lg['desc']; ?></p>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Add Block Modal -->
<div class="modal" id="addBlockModal">
    <div class="modal-header">
        <h3><i class="fas fa-plus" style="margin-right: 8px; color: var(--primary);"></i> Add Field Block</h3>
        <button class="modal-close" onclick="closeModal('addBlockModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
        <form id="addBlockForm" method="POST" action="<?php echo url('field-blocks.php'); ?>">
<?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create_block">
            <input type="hidden" name="linked_to" value="<?php echo htmlspecialchars($selectedModule); ?>">
            <input type="hidden" name="return_module" value="<?php echo htmlspecialchars($selectedModule); ?>">
            <p style="margin: 0 0 14px; color: var(--text-secondary); font-size: 0.85rem;">
                Adding to <strong><?php echo htmlspecialchars($moduleLabels[$selectedModule] ?? $selectedModule); ?></strong>
                (switch the Module dropdown on the page behind this to add a block elsewhere).
            </p>
            <div class="form-group">
                <label>Block Label <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="text" id="addBlockLabel" name="block_label" placeholder="e.g., Contact Information" required>
                    <i class="fas fa-tag"></i>
                </div>
            </div>
            <div class="form-group">
                <label>Block Name <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="text" id="addBlockName" name="block_name" placeholder="e.g., contact_information" required>
                    <i class="fas fa-code"></i>
                </div>
                <small style="color: var(--text-muted); font-size: 0.78rem;">Auto-generated from label. Use lowercase with underscores.</small>
            </div>
            <div class="form-group">
                <label>Layout <span class="required">*</span></label>
                <div class="input-wrapper">
                    <select id="addBlockLayout" name="layout" onchange="updateLayoutPreview('add')">
                        <option value="1-col">1 Column (Stacked)</option>
                        <option value="2-col">2 Columns (Side by Side)</option>
                        <option value="3-col">3 Columns</option>
                        <option value="4-col">4 Columns</option>
                        <option value="2-1">2/3 + 1/3 (Wide + Narrow)</option>
                        <option value="1-2">1/3 + 2/3 (Narrow + Wide)</option>
                    </select>
                    <i class="fas fa-columns"></i>
                </div>
            </div>

            <!-- Repeatable Settings -->
            <div class="form-group">
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                    <input type="checkbox" id="addBlockRepeatable" name="is_repeatable" value="1" onchange="toggleMaxInstances('add')" style="width: auto;">
                    <i class="fas fa-clone" style="color: #e67e22;"></i> Make this block repeatable
                </label>
                <small style="color: var(--text-muted); font-size: 0.78rem;">Users can add multiple sets of these fields (e.g., multiple phone numbers, addresses)</small>
            </div>
            <div class="form-group" id="addMaxInstancesGroup" style="display: none;">
                <label>Max Instances</label>
                <div class="input-wrapper">
                    <input type="number" id="addMaxInstances" name="max_instances" value="10" min="1" max="50" placeholder="Max repetitions">
                    <i class="fas fa-hashtag"></i>
                </div>
                <small style="color: var(--text-muted); font-size: 0.78rem;">Maximum number of times users can repeat this block (1-50)</small>
            </div>

            <!-- Layout Preview in Modal -->
            <div class="field-preview">
                <div class="field-preview-title">
                    <i class="fas fa-eye"></i> Layout Preview
                </div>
                <div id="addLayoutPreview" class="layout-preview-box">
                    <div class="layout-preview-grid layout-1-col">
                        <div class="layout-preview-field"><span class="lpf-label">Field 1</span><span class="lpf-input"></span></div>
                        <div class="layout-preview-field"><span class="lpf-label">Field 2</span><span class="lpf-input"></span></div>
                        <div class="layout-preview-field"><span class="lpf-label">Field 3</span><span class="lpf-input"></span></div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('addBlockModal')">Cancel</button>
        <button class="btn btn-primary" onclick="createBlock()">
            <i class="fas fa-plus"></i> Create Block
        </button>
    </div>
</div>

<!-- Edit Block Modal -->
<div class="modal" id="editBlockModal">
    <div class="modal-header">
        <h3><i class="fas fa-edit" style="margin-right: 8px; color: var(--primary);"></i> Edit Field Block</h3>
        <button class="modal-close" onclick="closeModal('editBlockModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
        <form id="editBlockForm" method="POST" action="<?php echo url('field-blocks.php'); ?>">
<?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="update_block">
            <input type="hidden" id="editBlockId" name="block_id">
            <input type="hidden" name="return_module" value="<?php echo htmlspecialchars($selectedModule); ?>">
            <p style="margin: 0 0 14px; color: var(--text-secondary); font-size: 0.85rem;">
                Module: <strong id="editBlockModuleLabel"></strong>
                <span style="display:block; margin-top:2px;">A block's module can't be changed after creation -- delete and recreate it under a different module instead.</span>
            </p>
            <div class="form-group">
                <label>Block Label <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="text" id="editBlockLabel" name="block_label" placeholder="Block label" required>
                    <i class="fas fa-tag"></i>
                </div>
            </div>
            <div class="form-group">
                <label>Layout <span class="required">*</span></label>
                <div class="input-wrapper">
                    <select id="editBlockLayout" name="layout" onchange="updateLayoutPreview('edit')">
                        <option value="1-col">1 Column (Stacked)</option>
                        <option value="2-col">2 Columns (Side by Side)</option>
                        <option value="3-col">3 Columns</option>
                        <option value="4-col">4 Columns</option>
                        <option value="2-1">2/3 + 1/3 (Wide + Narrow)</option>
                        <option value="1-2">1/3 + 2/3 (Narrow + Wide)</option>
                    </select>
                    <i class="fas fa-columns"></i>
                </div>
            </div>
            <div class="field-preview">
                <div class="field-preview-title">
                    <i class="fas fa-eye"></i> Layout Preview
                </div>
                <div id="editLayoutPreview" class="layout-preview-box">
                    <div class="layout-preview-grid layout-1-col">
                        <div class="layout-preview-field"><span class="lpf-label">Field 1</span><span class="lpf-input"></span></div>
                        <div class="layout-preview-field"><span class="lpf-label">Field 2</span><span class="lpf-input"></span></div>
                    </div>
                </div>
            </div>

            <!-- Repeatable Settings -->
            <div class="form-group">
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                    <input type="checkbox" id="editBlockRepeatable" name="is_repeatable" value="1" onchange="toggleMaxInstances('edit')" style="width: auto;">
                    <i class="fas fa-clone" style="color: #e67e22;"></i> Make this block repeatable
                </label>
                <small style="color: var(--text-muted); font-size: 0.78rem;">Users can add multiple sets of these fields</small>
            </div>
            <div class="form-group" id="editMaxInstancesGroup" style="display: none;">
                <label>Max Instances</label>
                <div class="input-wrapper">
                    <input type="number" id="editMaxInstances" name="max_instances" value="10" min="1" max="50" placeholder="Max repetitions">
                    <i class="fas fa-hashtag"></i>
                </div>
                <small style="color: var(--text-muted); font-size: 0.78rem;">Maximum number of times users can repeat this block (1-50)</small>
            </div>
        </form>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('editBlockModal')">Cancel</button>
        <button class="btn btn-primary" onclick="updateBlock()">
            <i class="fas fa-save"></i> Save Changes
        </button>
    </div>
</div>

<!-- Hidden form for block toggle/delete/move actions -->
<form id="blockActionForm" method="POST" action="<?php echo url('field-blocks.php'); ?>" style="display:none;">
<?php echo csrf_field(); ?>
    <input type="hidden" name="action" id="blockActionType">
    <input type="hidden" name="block_id" id="blockActionId">
    <input type="hidden" name="direction" id="blockActionDirection">
    <input type="hidden" name="field_ids" id="blockActionFieldIds">
    <input type="hidden" name="return_module" value="<?php echo htmlspecialchars($selectedModule); ?>">
</form>

<!-- Manage Block Fields Modal -->
<div class="modal modal-lg" id="manageFieldsModal">
    <div class="modal-header">
        <h3><i class="fas fa-puzzle-piece" style="margin-right: 8px; color: var(--primary);"></i> Manage Block Fields: <span id="manageFieldsBlockName"></span></h3>
        <button class="modal-close" onclick="closeModal('manageFieldsModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
        <input type="hidden" id="manageFieldsBlockId">
        <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 16px;">
            <i class="fas fa-info-circle"></i> Select and reorder fields to include in this block. Only fields from the same module that are not in other blocks are shown.
        </p>

        <div class="fields-assign-container">
            <!-- Available fields (left) -->
            <div class="fields-assign-panel">
                <h4><i class="fas fa-list"></i> Available Fields</h4>
                <div id="availableFieldsList" class="fields-sortable-list">
                    <div class="fields-loading"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
                </div>
            </div>

            <!-- Arrow -->
            <div class="fields-assign-arrows">
                <button class="btn btn-icon btn-primary" onclick="moveFieldsRight()" title="Add to Block"><i class="fas fa-chevron-right"></i></button>
                <button class="btn btn-icon btn-secondary" onclick="moveFieldsLeft()" title="Remove from Block"><i class="fas fa-chevron-left"></i></button>
            </div>

            <!-- Assigned fields (right) -->
            <div class="fields-assign-panel">
                <h4><i class="fas fa-th-large"></i> Fields in Block</h4>
                <div id="assignedFieldsList" class="fields-sortable-list">
                    <div class="fields-loading"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
                </div>
            </div>
        </div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('manageFieldsModal')">Cancel</button>
        <button class="btn btn-primary" onclick="saveFieldAssignments()">
            <i class="fas fa-save"></i> Save Assignments
        </button>
    </div>
</div>

<!-- Block Preview Modal -->
<div class="modal modal-lg" id="previewBlockModal">
    <div class="modal-header">
        <h3><i class="fas fa-eye" style="margin-right: 8px; color: var(--primary);"></i> Block Preview: <span id="previewBlockName"></span></h3>
        <button class="modal-close" onclick="closeModal('previewBlockModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
        <div id="blockPreviewContent">
            <div class="fields-loading"><i class="fas fa-spinner fa-spin"></i> Loading preview...</div>
        </div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('previewBlockModal')">Close</button>
    </div>
</div>

<script>
var currentModule = <?php echo json_encode($selectedModule); ?>;

// Auto-generate block name from label
document.getElementById('addBlockLabel').addEventListener('input', function() {
    var name = this.value.toLowerCase()
        .replace(/[^a-z0-9\s]/g, '')
        .replace(/\s+/g, '_')
        .substring(0, 100);
    document.getElementById('addBlockName').value = name;
});

// Reset the Add Block form whenever the modal opens
var origOpenModal = window.openModal;
window.openModal = function(modalId) {
    if (modalId === 'addBlockModal') {
        document.getElementById('addBlockLabel').value = '';
        document.getElementById('addBlockName').value = '';
        document.getElementById('addBlockLayout').selectedIndex = 0;
        document.getElementById('addBlockRepeatable').checked = false;
        document.getElementById('addMaxInstances').value = 10;
        toggleMaxInstances('add');
        updateLayoutPreview('add');
    }
    origOpenModal(modalId);
};

// Update layout preview in modal
function updateLayoutPreview(prefix) {
    var layout = document.getElementById(prefix + 'BlockLayout').value;
    var container = document.getElementById(prefix + 'LayoutPreview');

    var fieldCount = 4;
    var gridClass = 'layout-' + layout;

    var html = '<div class="layout-preview-grid ' + gridClass + '">';
    for (var i = 1; i <= fieldCount; i++) {
        html += '<div class="layout-preview-field">';
        html += '<span class="lpf-label">Field ' + i + '</span>';
        html += '<span class="lpf-input"></span>';
        html += '</div>';
    }
    html += '</div>';
    container.innerHTML = html;
}

// Toggle max instances visibility
function toggleMaxInstances(prefix) {
    var checkbox = document.getElementById(prefix + 'BlockRepeatable');
    var group = document.getElementById(prefix + 'MaxInstancesGroup');
    group.style.display = checkbox.checked ? 'block' : 'none';
}

// Create block - native form submit
function createBlock() {
    var label = document.getElementById('addBlockLabel').value.trim();
    var name = document.getElementById('addBlockName').value.trim();

    if (!label || !name) {
        showToast('Block label and name are required', 'error');
        return;
    }

    document.getElementById('addBlockForm').submit();
}

// Edit block
function editBlock(block) {
    document.getElementById('editBlockId').value = block.id;
    document.getElementById('editBlockLabel').value = block.block_label;
    document.getElementById('editBlockLayout').value = block.layout;
    document.getElementById('editBlockModuleLabel').textContent = moduleLabels[block.linked_to] || block.linked_to;

    // Set repeatable fields
    var repeatableCheckbox = document.getElementById('editBlockRepeatable');
    repeatableCheckbox.checked = parseInt(block.is_repeatable) === 1;
    toggleMaxInstances('edit');
    document.getElementById('editMaxInstances').value = block.max_instances || 10;

    updateLayoutPreview('edit');
    openModal('editBlockModal');
}

// Update block - native form submit
function updateBlock() {
    var label = document.getElementById('editBlockLabel').value.trim();

    if (!label) {
        showToast('Block label is required', 'error');
        return;
    }

    document.getElementById('editBlockForm').submit();
}

// -------------------------------------------------------------------------
// Blocks table: single-row selection (mirrors the tkinter app's Treeview
// single-select) + a bottom action bar that acts on whichever row is
// selected, instead of a per-card icon column and free-drag reordering.
// -------------------------------------------------------------------------
var moduleLabels = <?php echo json_encode($moduleLabels); ?>;
var selectedBlockId = null;
var selectedBlockData = null;

function selectBlockRow(tr) {
    document.querySelectorAll('#blocksTable tbody tr.row-selectable').forEach(function(r) {
        r.classList.remove('row-selected');
    });
    tr.classList.add('row-selected');
    selectedBlockId = tr.dataset.id;
    selectedBlockData = JSON.parse(tr.dataset.block);
}

function editSelectedBlock() {
    if (!selectedBlockData) { showToast('Select a block first', 'warning'); return; }
    editBlock(selectedBlockData);
}

function deleteSelectedBlock() {
    if (!selectedBlockId || !selectedBlockData) { showToast('Select a block first', 'warning'); return; }
    deleteBlock(selectedBlockId, selectedBlockData.block_label);
}

function toggleSelectedBlock() {
    if (!selectedBlockId) { showToast('Select a block first', 'warning'); return; }
    toggleBlock(selectedBlockId);
}

function moveSelectedBlock(direction) {
    if (!selectedBlockId) { showToast('Select a block first', 'warning'); return; }
    document.getElementById('blockActionType').value = 'move_block';
    document.getElementById('blockActionId').value = selectedBlockId;
    document.getElementById('blockActionDirection').value = direction;
    document.getElementById('blockActionForm').submit();
}

function previewSelectedBlock() {
    if (!selectedBlockId) { showToast('Select a block first', 'warning'); return; }
    previewBlock(parseInt(selectedBlockId, 10));
}

function manageFieldsSelectedBlock() {
    if (!selectedBlockId || !selectedBlockData) { showToast('Select a block first', 'warning'); return; }
    manageBlockFields(parseInt(selectedBlockId, 10), selectedBlockData.block_label, selectedBlockData.linked_to);
}

// Toggle block - native form submit
function toggleBlock(blockId) {
    document.getElementById('blockActionType').value = 'toggle_block';
    document.getElementById('blockActionId').value = blockId;
    document.getElementById('blockActionForm').submit();
}

// Delete block - native form submit
function deleteBlock(blockId, blockLabel) {
    showConfirm(
        'Delete Block',
        'Are you sure you want to delete "' + blockLabel + '"? Fields will be unassigned but not deleted.',
        function() {
            document.getElementById('blockActionType').value = 'delete_block';
            document.getElementById('blockActionId').value = blockId;
            document.getElementById('blockActionForm').submit();
        }
    );
}

// Manage block fields - open assign modal
function manageBlockFields(blockId, blockLabel, linkedTo) {
    document.getElementById('manageFieldsBlockId').value = blockId;
    document.getElementById('manageFieldsBlockName').textContent = blockLabel;

    var availableList = document.getElementById('availableFieldsList');
    var assignedList = document.getElementById('assignedFieldsList');
    availableList.innerHTML = '<div class="fields-loading"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
    assignedList.innerHTML = '<div class="fields-loading"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';

    openModal('manageFieldsModal');

    // Load available fields and assigned fields
    ajaxRequest('/field-blocks.php?action=get_available_fields&linked_to=' + linkedTo + '&block_id=' + blockId, 'GET', null, function(response) {
        if (response.success) {
            var available = [];
            var assigned = [];
            response.fields.forEach(function(f) {
                if (f.block_id && parseInt(f.block_id) === blockId) {
                    assigned.push(f);
                } else {
                    available.push(f);
                }
            });

            // Sort assigned by field_order_in_block
            assigned.sort(function(a, b) { return (a.field_order_in_block || 0) - (b.field_order_in_block || 0); });

            renderFieldList(availableList, available, 'available');
            renderFieldList(assignedList, assigned, 'assigned');
        }
    });
}

function renderFieldList(container, fields, type) {
    if (fields.length === 0) {
        container.innerHTML = '<div class="fields-empty-msg"><i class="fas fa-info-circle"></i> ' +
            (type === 'available' ? 'No unassigned fields for this module' : 'No fields assigned yet') + '</div>';
        return;
    }

    var html = '';
    fields.forEach(function(f) {
        html += '<div class="field-assign-item" data-field-id="' + f.id + '">';
        html += '<label class="field-assign-check">';
        html += '<input type="checkbox" value="' + f.id + '">';
        html += '<span class="checkmark"></span>';
        html += '</label>';
        html += '<div class="field-assign-info">';
        html += '<span class="field-assign-label">' + escapeHtml(f.field_label) + '</span>';
        html += '<span class="field-type ' + f.field_type + '" style="font-size: 0.7rem;">' + f.field_type + '</span>';
        html += '</div>';
        if (type === 'assigned') {
            html += '<i class="fas fa-grip-vertical field-drag" style="cursor: move; color: var(--text-muted);"></i>';
        }
        html += '</div>';
    });
    container.innerHTML = html;

    // Make assigned list sortable (simple drag reorder)
    if (type === 'assigned') {
        initSimpleDrag(container);
    }
}

function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Move fields right (available -> assigned)
function moveFieldsRight() {
    var available = document.getElementById('availableFieldsList');
    var assigned = document.getElementById('assignedFieldsList');
    var emptyMsg = assigned.querySelector('.fields-empty-msg');
    if (emptyMsg) emptyMsg.remove();

    var checked = available.querySelectorAll('input[type="checkbox"]:checked');
    checked.forEach(function(cb) {
        var item = cb.closest('.field-assign-item');
        item.querySelector('input[type="checkbox"]').checked = false;
        // Add drag handle if not present
        if (!item.querySelector('.field-drag')) {
            var grip = document.createElement('i');
            grip.className = 'fas fa-grip-vertical field-drag';
            grip.style.cssText = 'cursor: move; color: var(--text-muted);';
            item.appendChild(grip);
        }
        assigned.appendChild(item);
    });

    // Check if available is empty
    if (available.querySelectorAll('.field-assign-item').length === 0) {
        available.innerHTML = '<div class="fields-empty-msg"><i class="fas fa-info-circle"></i> No unassigned fields for this module</div>';
    }

    initSimpleDrag(assigned);
}

// Move fields left (assigned -> available)
function moveFieldsLeft() {
    var available = document.getElementById('availableFieldsList');
    var assigned = document.getElementById('assignedFieldsList');
    var emptyMsg = available.querySelector('.fields-empty-msg');
    if (emptyMsg) emptyMsg.remove();

    var checked = assigned.querySelectorAll('input[type="checkbox"]:checked');
    checked.forEach(function(cb) {
        var item = cb.closest('.field-assign-item');
        item.querySelector('input[type="checkbox"]').checked = false;
        var grip = item.querySelector('.field-drag');
        if (grip) grip.remove();
        available.appendChild(item);
    });

    if (assigned.querySelectorAll('.field-assign-item').length === 0) {
        assigned.innerHTML = '<div class="fields-empty-msg"><i class="fas fa-info-circle"></i> No fields assigned yet</div>';
    }
}

// Save field assignments - native form submit
function saveFieldAssignments() {
    var blockId = document.getElementById('manageFieldsBlockId').value;
    var assignedItems = document.querySelectorAll('#assignedFieldsList .field-assign-item');
    var fieldIds = [];
    assignedItems.forEach(function(item) {
        fieldIds.push(parseInt(item.getAttribute('data-field-id')));
    });

    document.getElementById('blockActionType').value = 'assign_fields';
    document.getElementById('blockActionId').value = blockId;
    document.getElementById('blockActionFieldIds').value = JSON.stringify(fieldIds);
    document.getElementById('blockActionForm').submit();
}

// Simple drag & drop reorder for assigned fields
function initSimpleDrag(container) {
    var items = container.querySelectorAll('.field-assign-item');
    var dragItem = null;

    items.forEach(function(item) {
        item.setAttribute('draggable', 'true');

        item.addEventListener('dragstart', function(e) {
            dragItem = this;
            this.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
        });

        item.addEventListener('dragend', function() {
            this.classList.remove('dragging');
            dragItem = null;
            container.querySelectorAll('.field-assign-item').forEach(function(el) {
                el.classList.remove('drag-over');
            });
        });

        item.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            if (this !== dragItem) {
                this.classList.add('drag-over');
            }
        });

        item.addEventListener('dragleave', function() {
            this.classList.remove('drag-over');
        });

        item.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('drag-over');
            if (dragItem && this !== dragItem) {
                var allItems = Array.from(container.querySelectorAll('.field-assign-item'));
                var fromIdx = allItems.indexOf(dragItem);
                var toIdx = allItems.indexOf(this);
                if (fromIdx < toIdx) {
                    container.insertBefore(dragItem, this.nextSibling);
                } else {
                    container.insertBefore(dragItem, this);
                }
            }
        });
    });
}

// Preview block with its fields
function previewBlock(blockId) {
    document.getElementById('blockPreviewContent').innerHTML = '<div class="fields-loading"><i class="fas fa-spinner fa-spin"></i> Loading preview...</div>';
    openModal('previewBlockModal');

    ajaxRequest('/field-blocks.php?action=get_block_preview&block_id=' + blockId, 'GET', null, function(response) {
        if (response.success) {
            var block = response.block;
            var fields = response.fields;
            document.getElementById('previewBlockName').textContent = block.block_label;
            renderBlockPreview(block, fields);
        } else {
            document.getElementById('blockPreviewContent').innerHTML = '<p style="color: var(--danger);">Failed to load preview.</p>';
        }
    });
}

function renderBlockPreview(block, fields) {
    var container = document.getElementById('blockPreviewContent');

    if (fields.length === 0) {
        container.innerHTML = '<div class="fields-empty-msg" style="text-align: center; padding: 40px;">' +
            '<i class="fas fa-puzzle-piece" style="font-size: 2rem; color: var(--text-muted); margin-bottom: 12px; display: block;"></i>' +
            '<p style="color: var(--text-muted);">No fields in this block yet. Add fields using the <strong>Manage Fields</strong> button.</p></div>';
        return;
    }

    var layoutMap = {
        '1-col':  'block-preview-1-col',
        '2-col':  'block-preview-2-col',
        '3-col':  'block-preview-3-col',
        '4-col':  'block-preview-4-col',
        '2-1':    'block-preview-2-1',
        '1-2':    'block-preview-1-2'
    };

    var layoutClass = layoutMap[block.layout] || 'block-preview-1-col';
    var layoutLabel = {'1-col':'1 Column','2-col':'2 Columns','3-col':'3 Columns','4-col':'4 Columns','2-1':'2/3 + 1/3','1-2':'1/3 + 2/3'};

    var html = '';
    // Block info bar
    html += '<div class="block-preview-info">';
    html += '<span><i class="fas fa-th-large"></i> <strong>' + escapeHtml(block.block_label) + '</strong></span>';
    html += '<span class="block-layout-badge"><i class="fas fa-columns"></i> ' + (layoutLabel[block.layout] || block.layout) + '</span>';
    html += '<span><i class="fas fa-puzzle-piece"></i> ' + fields.length + ' field(s)</span>';
    if (parseInt(block.is_repeatable) === 1) {
        html += '<span class="badge" style="background: #e67e22; color: #fff; font-size: 0.75rem;"><i class="fas fa-clone"></i> Repeatable (max ' + (block.max_instances || 10) + ')</span>';
    }
    html += '</div>';

    // Preview with actual field inputs
    html += '<div class="block-preview-section">';
    html += '<h4 style="margin-bottom: 16px; color: var(--accent);"><i class="fas fa-eye"></i> Live Preview</h4>';
    html += '<div class="block-preview-form ' + layoutClass + '">';

    fields.forEach(function(field) {
        html += '<div class="form-group block-preview-field">';
        html += '<label>' + escapeHtml(field.field_label);
        if (field.is_required == 1) html += ' <span class="required">*</span>';
        html += '</label>';
        html += '<div class="input-wrapper no-icon">';
        html += renderFieldInput(field);
        html += '</div>';
        html += '</div>';
    });

    html += '</div>';
    html += '</div>';

    // Wireframe view
    html += '<div class="block-preview-section" style="margin-top: 20px;">';
    html += '<h4 style="margin-bottom: 16px; color: var(--text-muted);"><i class="fas fa-vector-square"></i> Wireframe View</h4>';
    html += '<div class="block-preview-wireframe ' + layoutClass + '">';
    fields.forEach(function(field) {
        html += '<div class="wireframe-field">';
        html += '<span class="wf-label">' + escapeHtml(field.field_label) + '</span>';
        html += '<span class="wf-type">' + field.field_type + '</span>';
        html += '</div>';
    });
    html += '</div>';
    html += '</div>';

    container.innerHTML = html;
}

// Render a field input for preview
function renderFieldInput(field) {
    var type = field.field_type;
    var placeholder = field.field_placeholder || 'Enter value...';
    var options = [];
    try { options = field.field_options ? JSON.parse(field.field_options) : []; } catch(e) {}

    switch (type) {
        case 'textarea':
            return '<textarea placeholder="' + escapeHtml(placeholder) + '" disabled style="padding-left:16px;"></textarea>';
        case 'select':
            var h = '<select disabled><option>Select...</option>';
            options.forEach(function(o) { h += '<option>' + escapeHtml(o) + '</option>'; });
            h += '</select>';
            return h;
        case 'multiselect':
            var h = '<select multiple disabled style="height:80px;">';
            options.forEach(function(o) { h += '<option>' + escapeHtml(o) + '</option>'; });
            h += '</select>';
            return h;
        case 'checkbox':
        case 'radio':
            var h = '<div style="display:flex;gap:12px;flex-wrap:wrap;">';
            (options.length ? options : ['Option 1','Option 2']).forEach(function(o) {
                h += '<label class="form-check"><input type="' + type + '" disabled><span class="checkmark"></span><span class="form-check-label">' + escapeHtml(o) + '</span></label>';
            });
            h += '</div>';
            return h;
        case 'color':
            return '<input type="color" disabled style="height:42px;padding:4px;">';
        case 'toggle':
            return '<label class="toggle-switch" style="pointer-events:none;"><input type="checkbox" checked disabled><span class="toggle-slider"></span><span style="margin-left:52px;font-size:0.85rem;">Yes</span></label>';
        case 'rating':
            var h = '<div class="star-rating-preview">';
            for (var i = 1; i <= 5; i++) {
                h += '<i class="fas fa-star" style="font-size:1.4rem;color:' + (i <= 3 ? '#f5a623' : 'var(--bg-tertiary)') + ';cursor:default;"></i> ';
            }
            h += '</div>';
            return h;
        case 'range':
            return '<div style="display:flex;align-items:center;gap:12px;"><input type="range" min="0" max="100" value="50" disabled style="flex:1;height:6px;"><span style="font-size:0.85rem;color:var(--text-muted);">50</span></div>';
        case 'currency':
            return '<div style="display:flex;align-items:stretch;"><span style="padding:10px 12px;background:var(--bg-tertiary);border-radius:8px 0 0 8px;border:1px solid var(--border);border-right:none;font-weight:600;color:var(--text-muted);display:flex;align-items:center;">₹</span><input type="number" placeholder="0.00" disabled style="border-radius:0 8px 8px 0;border-left:none;"></div>';
        case 'password':
            return '<input type="text" value="••••••••" disabled style="-webkit-text-security:disc;">';
        case 'time':
            return '<input type="time" disabled>';
        case 'datetime':
            return '<input type="datetime-local" disabled>';
        case 'reminder':
            return '<input type="datetime-local" disabled>';
        case 'file':
            return '<div class="file-upload-preview"><i class="fas fa-cloud-upload-alt" style="font-size:1.5rem;color:var(--primary);"></i><p style="margin:4px 0 0;font-size:0.82rem;color:var(--text-muted);">Click to upload (max 5MB)</p></div>';
        case 'image':
            return '<div class="file-upload-preview"><i class="fas fa-image" style="font-size:1.5rem;color:#2ecc71;"></i><p style="margin:4px 0 0;font-size:0.82rem;color:var(--text-muted);">Upload image</p></div>';
        case 'pdf':
            return '<div class="file-upload-preview"><i class="fas fa-file-pdf" style="font-size:1.5rem;color:#e74c3c;"></i><p style="margin:4px 0 0;font-size:0.82rem;color:var(--text-muted);">Upload PDF</p></div>';
        case 'document':
            return '<div class="file-upload-preview"><i class="fas fa-file-word" style="font-size:1.5rem;color:#2b579a;"></i><p style="margin:4px 0 0;font-size:0.82rem;color:var(--text-muted);">Upload document</p></div>';
        case 'spreadsheet':
            return '<div class="file-upload-preview"><i class="fas fa-file-excel" style="font-size:1.5rem;color:#217346;"></i><p style="margin:4px 0 0;font-size:0.82rem;color:var(--text-muted);">Upload spreadsheet</p></div>';
        default:
            var inputType = type === 'phone' ? 'tel' : type;
            return '<input type="' + inputType + '" placeholder="' + escapeHtml(placeholder) + '" disabled>';
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
