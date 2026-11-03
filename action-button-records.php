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

        // Per-button Filters & Dashboard (mirrors the desktop app). Only load
        // the custom fields + values when it is actually enabled.
        $cfg = getActionButtonFilterConfig($buttonId, $user['id']);
        $filtersOn = !empty($cfg['enabled']);
        $customFields = getCustomFieldsFor($childModule, $user['id']);
        $fieldsById = [];
        foreach ($customFields as $f) { $fieldsById[(int)$f['id']] = $f; }
        $listFields = array_values(array_filter($customFields, function ($f) { return !empty($f['show_in_list']); }));
        $recordValues = [];
        if ($filtersOn) {
            foreach ($records as $rec) {
                $recordValues[(int)$rec['id']] = getCustomFieldValues($childModule, (int)$rec['id']);
            }
        }
        $map = $cfg['map'];
        $builtin = $cfg['builtin'];
        $show = function ($key) use ($builtin, $map) {
            $roleFor = ['type' => 'type_field', 'month' => 'date_field', 'dates' => 'date_field',
                        'amount' => 'amount_field', 'group' => 'date_field'];
            return !empty($builtin[$key]) && !empty($map[$roleFor[$key]]);
        };

        ob_start();
        ?>
        <div class="toolbar" style="margin-bottom: 12px;">
            <div class="toolbar-left"><strong><?php echo count($records); ?></strong> record(s)</div>
            <div class="toolbar-right">
                <button type="button" class="btn btn-ghost btn-sm" data-ab-config="1" title="Filters & Dashboard">
                    <i class="fas fa-sliders-h"></i> Filters
                </button>
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
        <?php else:
            // Column count = Label + list fields + Actions, for the grouped
            // month-header rows that span the whole table.
            $colCount = 2 + count($listFields);
        ?>
        <?php if ($filtersOn): ?>
            <?php
            // Distinct Type values + Months present, for the pickers.
            $typesPresent = [];
            $monthsPresent = [];
            foreach ($records as $rec) {
                $cv = $recordValues[(int)$rec['id']];
                if (!empty($map['type_field'])) {
                    $tv = (string)($cv[$map['type_field']] ?? '');
                    if ($tv !== '') $typesPresent[$tv] = true;
                }
                if (!empty($map['date_field'])) {
                    $mk = substr((string)($cv[$map['date_field']] ?? ''), 0, 7);
                    if ($mk !== '') $monthsPresent[$mk] = date('M Y', strtotime($mk . '-01'));
                }
            }
            ksort($typesPresent);
            krsort($monthsPresent);
            $bd = $cfg['builtin_dashboard'];
            $anyDash = $bd['count'] || $bd['in'] || $bd['out'] || $bd['net'];
            ?>
            <?php if ($anyDash): ?>
            <div class="card" style="margin-bottom: 12px;">
                <div class="stat-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; padding: 14px;">
                    <?php if ($bd['count']): ?><div><div style="color: var(--text-secondary); font-size: 0.8rem;">Transactions</div><div id="abStatCount" style="font-size: 1.2rem; font-weight: 700;">0</div></div><?php endif; ?>
                    <?php if ($bd['in']): ?><div><div style="color: var(--text-secondary); font-size: 0.8rem;">Total In</div><div id="abStatIn" style="font-size: 1.2rem; font-weight: 700; color: var(--success, #2e9e5b);">&#8377;0.00</div></div><?php endif; ?>
                    <?php if ($bd['out']): ?><div><div style="color: var(--text-secondary); font-size: 0.8rem;">Total Out</div><div id="abStatOut" style="font-size: 1.2rem; font-weight: 700; color: var(--danger);">&#8377;0.00</div></div><?php endif; ?>
                    <?php if ($bd['net']): ?><div><div style="color: var(--text-secondary); font-size: 0.8rem;">Net</div><div id="abStatNet" style="font-size: 1.2rem; font-weight: 700; color: var(--primary-light);">&#8377;0.00</div></div><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="card" style="margin-bottom: 12px;">
                <div style="display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; padding:14px;">
                    <?php if (!empty($cfg['text_search'])): ?>
                    <div class="form-group" style="margin:0;"><label>Search</label><input type="text" id="abFltSearch" placeholder="Label / text"></div>
                    <?php endif; ?>
                    <?php if ($show('type')): ?>
                    <div class="form-group" style="margin:0;"><label>Type</label>
                        <select id="abFltType"><option value="">All Types</option>
                            <?php foreach (array_keys($typesPresent) as $tv): ?><option value="<?php echo htmlspecialchars($tv); ?>"><?php echo htmlspecialchars($tv); ?></option><?php endforeach; ?>
                        </select></div>
                    <?php endif; ?>
                    <?php if ($show('month')): ?>
                    <div class="form-group" style="margin:0;"><label>Month</label>
                        <select id="abFltMonth"><option value="">All Months</option>
                            <?php foreach ($monthsPresent as $mk => $ml): ?><option value="<?php echo htmlspecialchars($mk); ?>"><?php echo htmlspecialchars($ml); ?></option><?php endforeach; ?>
                        </select></div>
                    <?php endif; ?>
                    <?php if ($show('dates')): ?>
                    <div class="form-group" style="margin:0;"><label>From</label><input type="date" id="abFltFrom"></div>
                    <div class="form-group" style="margin:0;"><label>To</label><input type="date" id="abFltTo"></div>
                    <?php endif; ?>
                    <?php if ($show('amount')): ?>
                    <div class="form-group" style="margin:0;"><label>Min Amount</label><input type="number" step="any" id="abFltMin" style="width:100px;"></div>
                    <div class="form-group" style="margin:0;"><label>Max Amount</label><input type="number" step="any" id="abFltMax" style="width:100px;"></div>
                    <?php endif; ?>
                    <?php foreach ($cfg['filter_fields'] as $ffid):
                        $ff = $fieldsById[(int)$ffid] ?? null; if (!$ff) continue; ?>
                    <div class="form-group" style="margin:0;"><label><?php echo htmlspecialchars($ff['field_label']); ?></label><input type="text" class="ab-cf-flt" data-fid="<?php echo (int)$ffid; ?>" style="width:120px;"></div>
                    <?php endforeach; ?>
                    <?php if ($show('group')): ?>
                    <label style="display:flex; align-items:center; gap:6px; margin:0 0 6px;"><input type="checkbox" id="abFltGroup"> Group by month</label>
                    <?php endif; ?>
                    <?php if (!empty($cfg['presets'])): ?>
                    <div class="form-group" style="margin:0;"><label>Preset</label>
                        <select id="abFltPreset"><option value="">—</option>
                            <?php foreach ($cfg['presets'] as $pi => $p): ?><option value="<?php echo (int)$pi; ?>"><?php echo htmlspecialchars($p['name']); ?></option><?php endforeach; ?>
                        </select></div>
                    <?php endif; ?>
                    <button type="button" class="btn btn-ghost" id="abFltSave" data-ab-save-preset="<?php echo (int)$buttonId; ?>"><i class="fas fa-bookmark"></i> Save Preset</button>
                    <button type="button" class="btn btn-ghost" id="abFltClear"><i class="fas fa-times"></i> Clear</button>
                </div>
            </div>
            <script type="application/json" id="abPresetData"><?php echo json_encode(array_map(function ($p) { return $p['state'] ?? []; }, $cfg['presets'])); ?></script>
        <?php endif; ?>
        <div class="table-container" style="border: none;">
            <table class="data-table" id="abRecordsTable" data-cols="<?php echo (int)$colCount; ?>" data-filters="<?php echo $filtersOn ? '1' : '0'; ?>">
                <thead><tr>
                    <th>Label</th>
                    <?php foreach ($listFields as $lf): ?><th><?php echo htmlspecialchars($lf['field_label']); ?></th><?php endforeach; ?>
                    <th style="width: 140px;">Actions</th>
                </tr></thead>
                <tbody id="abRecordsBody">
                    <?php foreach ($records as $rec):
                        $rid = (int)$rec['id'];
                        $cv = $recordValues[$rid] ?? [];
                        $dataAttrs = '';
                        if ($filtersOn) {
                            $dateVal = !empty($map['date_field']) ? (string)($cv[$map['date_field']] ?? '') : '';
                            $amtVal = !empty($map['amount_field']) ? (string)($cv[$map['amount_field']] ?? '') : '';
                            $typeVal = !empty($map['type_field']) ? (string)($cv[$map['type_field']] ?? '') : '';
                            $isOut = (!empty($map['dir_field']) && trim((string)($map['out_value'] ?? '')) !== ''
                                      && trim((string)($cv[$map['dir_field']] ?? '')) === trim((string)$map['out_value'])) ? '1' : '0';
                            $searchText = strtolower($rec['label']);
                            foreach ($listFields as $lf) { $searchText .= ' ' . strtolower((string)($cv[(int)$lf['id']] ?? '')); }
                            $dataAttrs = ' data-date="' . htmlspecialchars($dateVal, ENT_QUOTES) . '"'
                                . ' data-month="' . htmlspecialchars(substr($dateVal, 0, 7), ENT_QUOTES) . '"'
                                . ' data-type="' . htmlspecialchars($typeVal, ENT_QUOTES) . '"'
                                . ' data-amount="' . htmlspecialchars((string)(float)preg_replace('/[^0-9.\-]/', '', $amtVal), ENT_QUOTES) . '"'
                                . ' data-out="' . $isOut . '"'
                                . ' data-desc="' . htmlspecialchars($searchText, ENT_QUOTES) . '"';
                            foreach ($cfg['filter_fields'] as $ffid) {
                                $dataAttrs .= ' data-cf' . (int)$ffid . '="' . htmlspecialchars(strtolower((string)($cv[(int)$ffid] ?? '')), ENT_QUOTES) . '"';
                            }
                        }
                    ?>
                    <tr class="ab-rec-row"<?php echo $dataAttrs; ?>>
                        <td><?php echo htmlspecialchars($rec['label'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <?php foreach ($listFields as $lf):
                            $raw = (string)($cv[(int)$lf['id']] ?? '');
                            if ($raw !== '' && $lf['field_type'] === 'toggle') $raw = $raw === '1' ? 'Yes' : 'No';
                        ?><td><?php echo htmlspecialchars($raw !== '' ? $raw : '-', ENT_QUOTES, 'UTF-8'); ?></td><?php endforeach; ?>
                        <td>
                            <button type="button" class="btn btn-icon btn-ghost" data-ab-view="<?php echo $rid; ?>" title="View"><i class="fas fa-eye"></i></button>
                            <button type="button" class="btn btn-icon btn-ghost" data-ab-edit="<?php echo $rid; ?>" title="Edit"><i class="fas fa-pen"></i></button>
                            <button type="button" class="btn btn-icon btn-ghost" data-ab-delete="<?php echo $rid; ?>" title="Delete" style="color: var(--danger);"><i class="fas fa-trash-alt"></i></button>
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

    case 'filter-config': {
        // Returns the Filters & Dashboard config form for this button.
        $buttonId = (int)($_GET['button_id'] ?? 0);
        $button = getActionButton($buttonId, $user['id']);
        if (!$button) {
            jsonResponse(['success' => false, 'error' => 'Button not found'], 404);
        }
        $childModule = actionButtonModule($buttonId);
        $cfg = getActionButtonFilterConfig($buttonId, $user['id']);
        $fields = getCustomFieldsFor($childModule, $user['id']);
        $map = $cfg['map'];
        // Prefill mapping defaults by field type -- only before the first save,
        // so a deliberately-saved "(none)" is never overridden afterwards.
        $rawCfg = $db->fetch("SELECT filter_config FROM action_buttons WHERE id = ? AND user_id = ?", [$buttonId, $user['id']]);
        $neverSaved = empty($rawCfg['filter_config']);
        $autoMap = $neverSaved ? actionButtonAutoMap($fields) : [];
        $mapRoles = [
            'date_field' => 'Date field (Month / Date range / Group)',
            'amount_field' => 'Amount field (Amount / In / Out / Net)',
            'type_field' => 'Type field (Type dropdown)',
            'dir_field' => 'Direction field (marks money-out rows)',
        ];
        $builtinKeys = ['type' => 'Type', 'month' => 'Month', 'dates' => 'Date range', 'amount' => 'Amount range', 'group' => 'Group by month'];
        $dashKeys = ['count' => 'Transactions', 'in' => 'Total In', 'out' => 'Total Out', 'net' => 'Net'];

        ob_start();
        ?>
        <input type="hidden" name="button_id" value="<?php echo (int)$buttonId; ?>">
        <label style="display:flex; align-items:center; gap:8px; margin-bottom:12px; font-weight:600;">
            <input type="checkbox" name="enabled" value="1" <?php echo !empty($cfg['enabled']) ? 'checked' : ''; ?>> Enable filters &amp; dashboard for this button
        </label>
        <label style="display:flex; align-items:center; gap:8px; margin-bottom:12px;">
            <input type="checkbox" name="text_search" value="1" <?php echo !empty($cfg['text_search']) ? 'checked' : ''; ?>> Show text search box
        </label>
        <?php if (empty($fields)): ?>
        <p style="color: var(--text-muted);">This button has no custom fields yet. Add fields first, then come back to map and filter them.</p>
        <?php else: ?>
        <h4 style="margin:14px 0 6px;">Built-in filters</h4>
        <p style="color: var(--text-secondary); font-size:0.82rem; margin:0 0 6px;">Tick a control, then map it to a field below.</p>
        <?php foreach ($builtinKeys as $k => $label): ?>
        <label style="display:flex; align-items:center; gap:8px; margin-bottom:4px;"><input type="checkbox" name="builtin[<?php echo $k; ?>]" value="1" <?php echo !empty($cfg['builtin'][$k]) ? 'checked' : ''; ?>> <?php echo $label; ?></label>
        <?php endforeach; ?>

        <h4 style="margin:14px 0 6px;">Field mapping</h4>
        <?php foreach ($mapRoles as $role => $label):
            $sel = $map[$role] ?? null;
            if ($sel === null && $neverSaved) $sel = $autoMap[$role] ?? null;
        ?>
        <div class="form-group" style="margin:0 0 8px;">
            <label><?php echo $label; ?></label>
            <select name="map[<?php echo $role; ?>]">
                <option value="">(none)</option>
                <?php foreach ($fields as $f): ?><option value="<?php echo (int)$f['id']; ?>" <?php echo ($sel !== null && (int)$sel === (int)$f['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($f['field_label']); ?> (<?php echo htmlspecialchars($f['field_type']); ?>)</option><?php endforeach; ?>
            </select>
        </div>
        <?php endforeach; ?>
        <div class="form-group" style="margin:0 0 8px;">
            <label>Direction value that means "out"</label>
            <input type="text" name="map[out_value]" value="<?php echo htmlspecialchars((string)($map['out_value'] ?? ''), ENT_QUOTES); ?>">
        </div>

        <h4 style="margin:14px 0 6px;">Built-in dashboard tiles</h4>
        <?php foreach ($dashKeys as $k => $label): ?>
        <label style="display:flex; align-items:center; gap:8px; margin-bottom:4px;"><input type="checkbox" name="builtin_dashboard[<?php echo $k; ?>]" value="1" <?php echo !empty($cfg['builtin_dashboard'][$k]) ? 'checked' : ''; ?>> <?php echo $label; ?></label>
        <?php endforeach; ?>

        <h4 style="margin:14px 0 6px;">Filterable fields</h4>
        <?php foreach ($fields as $f): ?>
        <label style="display:flex; align-items:center; gap:8px; margin-bottom:4px;"><input type="checkbox" name="filter_fields[]" value="<?php echo (int)$f['id']; ?>" <?php echo in_array((int)$f['id'], array_map('intval', $cfg['filter_fields']), true) ? 'checked' : ''; ?>> <?php echo htmlspecialchars($f['field_label']); ?> (<?php echo htmlspecialchars($f['field_type']); ?>)</label>
        <?php endforeach; ?>
        <?php endif; ?>
        <?php
        $html = ob_get_clean();
        jsonResponse(['success' => true, 'html' => $html, 'label' => $button['label']]);
        break;
    }

    case 'filter-config-save': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(['success' => false, 'error' => 'POST required'], 405);
        }
        $buttonId = (int)($_POST['button_id'] ?? 0);
        $button = getActionButton($buttonId, $user['id']);
        if (!$button) {
            jsonResponse(['success' => false, 'error' => 'Button not found'], 404);
        }
        $cfg = getActionButtonFilterConfig($buttonId, $user['id']);
        $cfg['enabled'] = !empty($_POST['enabled']);
        $cfg['text_search'] = !empty($_POST['text_search']);
        $cfg['filter_fields'] = array_map('intval', $_POST['filter_fields'] ?? []);
        foreach (['type', 'month', 'dates', 'amount', 'group'] as $k) {
            $cfg['builtin'][$k] = !empty($_POST['builtin'][$k]);
        }
        foreach (['count', 'in', 'out', 'net'] as $k) {
            $cfg['builtin_dashboard'][$k] = !empty($_POST['builtin_dashboard'][$k]);
        }
        $postMap = $_POST['map'] ?? [];
        foreach (['date_field', 'amount_field', 'type_field', 'dir_field'] as $role) {
            $val = $postMap[$role] ?? '';
            $cfg['map'][$role] = ($val === '' ? null : (int)$val);
        }
        $cfg['map']['out_value'] = trim((string)($postMap['out_value'] ?? ''));
        setActionButtonFilterConfig($buttonId, $user['id'], $cfg);
        jsonResponse(['success' => true]);
        break;
    }

    case 'preset-save': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(['success' => false, 'error' => 'POST required'], 405);
        }
        $buttonId = (int)($_POST['button_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $button = getActionButton($buttonId, $user['id']);
        if (!$button) {
            jsonResponse(['success' => false, 'error' => 'Button not found'], 404);
        }
        if ($name === '') {
            jsonResponse(['success' => false, 'error' => 'Name is required'], 422);
        }
        $state = json_decode((string)($_POST['state'] ?? '{}'), true);
        if (!is_array($state)) $state = [];
        $cfg = getActionButtonFilterConfig($buttonId, $user['id']);
        $cfg['presets'] = array_values(array_filter($cfg['presets'], function ($p) use ($name) { return ($p['name'] ?? '') !== $name; }));
        $cfg['presets'][] = ['name' => $name, 'state' => $state];
        setActionButtonFilterConfig($buttonId, $user['id'], $cfg);
        jsonResponse(['success' => true]);
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
