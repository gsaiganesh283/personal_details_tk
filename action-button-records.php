<?php
/**
 * AJAX backend for the recursive "Action Buttons" feature (see
 * includes/action_buttons_helper.php). Only reachable via AJAX (mirrors
 * field-blocks.php's AJAX half) -- everything here is driven from
 * assets/js/action-buttons.js's modal, never loaded as a normal page.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/custom_fields_helper.php';
require_once __DIR__ . '/includes/action_buttons_helper.php';

if (!isAjax()) {
    jsonResponse(['success' => false, 'error' => 'AJAX only'], 400);
}

$user = requireAuth();
$db = Database::getInstance();
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

switch ($action) {

    case 'list': {
        $buttonId = (int)($_GET['button_id'] ?? 0);
        $recordId = (int)($_GET['record_id'] ?? 0);
        $button = getActionButton($buttonId, $user['id']);
        if (!$button) {
            jsonResponse(['success' => false, 'error' => 'Button not found'], 404);
        }
        $childModule = actionButtonModule($buttonId);
        $records = getActionButtonRecords($buttonId, $recordId, $user['id']);

        ob_start();
        ?>
        <div class="toolbar" style="margin-bottom: 12px;">
            <div class="toolbar-left"><strong><?php echo count($records); ?></strong> record(s)</div>
            <div class="toolbar-right">
                <button type="button" class="btn btn-primary btn-sm" data-ab-add="1">
                    <i class="fas fa-plus"></i> Add
                </button>
            </div>
        </div>
        <?php if (empty($records)): ?>
        <div class="empty-state">
            <i class="fas fa-layer-group"></i>
            <h3>No records yet</h3>
            <p>Click "Add" to create the first one.</p>
        </div>
        <?php else: ?>
        <div class="table-container" style="border: none;">
            <table class="data-table">
                <thead><tr><th>Label</th><th style="width: 140px;">Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($records as $rec): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($rec['label'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <button type="button" class="btn btn-icon btn-ghost" data-ab-view="<?php echo (int)$rec['id']; ?>" title="View"><i class="fas fa-eye"></i></button>
                            <button type="button" class="btn btn-icon btn-ghost" data-ab-edit="<?php echo (int)$rec['id']; ?>" title="Edit"><i class="fas fa-pen"></i></button>
                            <button type="button" class="btn btn-icon btn-ghost" data-ab-delete="<?php echo (int)$rec['id']; ?>" title="Delete" style="color: var(--danger);"><i class="fas fa-trash-alt"></i></button>
                            <?php renderActionButtonsInline($childModule, $rec['id'], $user['id']); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <?php
        $html = ob_get_clean();
        jsonResponse(['success' => true, 'label' => $button['label'], 'html' => $html]);
        break;
    }

    case 'view': {
        // Read-only details of one child record: its label, every custom
        // field value (files open in the viewer), and its own nested buttons.
        $buttonId = (int)($_GET['button_id'] ?? 0);
        $viewId = (int)($_GET['view_id'] ?? 0);
        $button = getActionButton($buttonId, $user['id']);
        $record = $viewId ? getActionButtonRecord($viewId, $user['id']) : null;
        if (!$button || !$record || (int)$record['button_id'] !== $buttonId) {
            jsonResponse(['success' => false, 'error' => 'Record not found'], 404);
        }
        $childModule = actionButtonModule($buttonId);

        ob_start();
        ?>
        <div class="detail-grid" style="display: grid; grid-template-columns: 160px 1fr; gap: 10px 16px; margin-bottom: 8px;">
            <div style="color: var(--text-secondary); font-size: 0.85rem;">Label</div>
            <div><strong><?php echo htmlspecialchars($record['label'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div style="color: var(--text-secondary); font-size: 0.85rem;">Created</div>
            <div><?php echo htmlspecialchars($record['created_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
            <div style="color: var(--text-secondary); font-size: 0.85rem;">Last updated</div>
            <div><?php echo htmlspecialchars($record['updated_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <?php
        $before = ob_get_length();
        renderCustomFieldValues($childModule, $viewId);
        if (ob_get_length() === $before) {
            echo '<p style="color: var(--text-muted); margin: 12px 0 0;">No custom field values saved for this record.</p>';
        }
        $nested = getActionButtons($childModule, $user['id']);
        if ($nested) {
            echo '<div style="margin-top: 16px; display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">';
            echo '<span style="color: var(--text-secondary); font-size: 0.85rem; margin-right: 4px;">Open:</span>';
            renderActionButtonsInline($childModule, $viewId, $user['id']);
            echo '</div>';
        }
        $html = ob_get_clean();
        jsonResponse(['success' => true, 'html' => $html, 'label' => $record['label']]);
        break;
    }

    case 'form': {
        $buttonId = (int)($_GET['button_id'] ?? 0);
        $recordId = (int)($_GET['record_id'] ?? 0);
        $editId = isset($_GET['edit_id']) && $_GET['edit_id'] !== '' ? (int)$_GET['edit_id'] : null;
        $button = getActionButton($buttonId, $user['id']);
        if (!$button) {
            jsonResponse(['success' => false, 'error' => 'Button not found'], 404);
        }
        $childModule = actionButtonModule($buttonId);
        $editData = $editId ? getActionButtonRecord($editId, $user['id']) : null;
        $customFields = getCustomFieldsFor($childModule, $user['id']);
        $customValues = $editId ? getCustomFieldValues($childModule, $editId) : [];

        ob_start();
        ?>
        <input type="hidden" name="button_id" value="<?php echo (int)$buttonId; ?>">
        <input type="hidden" name="record_id" value="<?php echo (int)$recordId; ?>">
        <input type="hidden" name="edit_id" value="<?php echo $editId ? (int)$editId : ''; ?>">
        <div class="form-group">
            <label>Label *</label>
            <div class="input-wrapper no-icon">
                <input type="text" name="label" required maxlength="255"
                       value="<?php echo $editData ? htmlspecialchars($editData['label'], ENT_QUOTES, 'UTF-8') : ''; ?>">
            </div>
        </div>
        <?php renderCustomFields($customFields, $customValues, 'Custom Fields', $childModule, $user['id']); ?>
        <?php
        $html = ob_get_clean();
        jsonResponse(['success' => true, 'html' => $html]);
        break;
    }

    case 'save': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(['success' => false, 'error' => 'POST required'], 405);
        }
        $buttonId = (int)($_POST['button_id'] ?? 0);
        $recordId = (int)($_POST['record_id'] ?? 0);
        $editId = isset($_POST['edit_id']) && $_POST['edit_id'] !== '' ? (int)$_POST['edit_id'] : null;
        $label = trim(sanitize($_POST['label'] ?? ''));
        $button = getActionButton($buttonId, $user['id']);
        if (!$button) {
            jsonResponse(['success' => false, 'error' => 'Button not found'], 404);
        }
        if ($label === '') {
            jsonResponse(['success' => false, 'error' => 'Label is required'], 422);
        }
        // Editing an existing record: confirm it actually belongs to this
        // button/user before touching it (don't trust the client-supplied id alone).
        if ($editId) {
            $existingRecord = getActionButtonRecord($editId, $user['id']);
            if (!$existingRecord || (int)$existingRecord['button_id'] !== $buttonId) {
                jsonResponse(['success' => false, 'error' => 'Record not found'], 404);
            }
        }
        $savedId = saveActionButtonRecord($buttonId, $recordId, $label, $user['id'], $editId);
        saveCustomFieldEntries($user['id'], actionButtonModule($buttonId), $savedId);
        jsonResponse(['success' => true]);
        break;
    }

    case 'delete': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(['success' => false, 'error' => 'POST required'], 405);
        }
        $recordId = (int)($_POST['edit_id'] ?? 0);
        $record = getActionButtonRecord($recordId, $user['id']);
        if (!$record) {
            jsonResponse(['success' => false, 'error' => 'Record not found'], 404);
        }
        // Its values, files nothing else uses, and nested children -- one transaction.
        feDeleteButtonRecord($recordId, $user['id']);
        jsonResponse(['success' => true]);
        break;
    }

    default:
        jsonResponse(['success' => false, 'error' => 'Invalid action'], 400);
}
