<?php
/**
 * Savings Tracker -> Custom Columns -> Widgets & column names for one
 * category: build the summary cards shown above the category's list, and
 * rename its columns (built-in and custom). Desktop twin: the "Widgets..." and
 * "Rename Columns..." buttons in the Savings Tracker's Custom Columns tab
 * (app/ui/savings_widgets_ui.py). Everything here syncs to the desktop app.
 */
$pageTitle = 'Savings Widgets';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/savings_helper.php';
require_once __DIR__ . '/includes/savings_widgets_helper.php';
$user = requireAuth();
$userId = (int)$user['id'];
$db = Database::getInstance();
ensureSavingsTables($db);
ensureSavingsWidgetTables($db);

$cat = $_GET['cat'] ?? $_POST['cat'] ?? '';
if (!isset($SAVINGS_CATEGORIES[$cat])) {
    $cat = array_key_first($SAVINGS_CATEGORIES);
}
$config = $SAVINGS_CATEGORIES[$cat];
$table = $config['table'];
$selfUrl = url('savings-widgets.php?cat=' . urlencode($cat));
$columns = swAvailableColumns($db, $userId, $config);

/** Widget values from the submitted form. */
function swFormValues(string $table): array {
    $p = $_POST;
    $values = [
        'category' => $table,
        'title' => $p['title'] ?? '',
        'agg_func' => $p['agg_func'] ?? 'sum',
        'source_column' => $p['source_column'] ?? '',
        'formula' => $p['formula'] ?? '',
        'filter_column' => $p['filter_column'] ?? '',
        'filter_op' => $p['filter_op'] ?? '',
        'filter_value' => $p['filter_value'] ?? '',
        'number_format' => $p['number_format'] ?? 'currency',
        'decimals' => (int)($p['decimals'] ?? 0),
        'value_prefix' => $p['value_prefix'] ?? '',
        'value_suffix' => $p['value_suffix'] ?? '',
        'accent_color' => ($p['accent_choice'] ?? '') === 'custom' ? ($p['accent_custom'] ?? '') : ($p['accent_choice'] ?? ''),
        'color_rule' => $p['color_rule'] ?? 'none',
        'caption' => $p['caption'] ?? '',
        'is_active' => isset($p['is_active']) ? 1 : 0,
    ];
    if ($values['agg_func'] === 'formula') {
        $values['source_column'] = '';
    } else {
        $values['formula'] = '';
    }
    if ($values['filter_column'] === '') {
        $values['filter_op'] = '';
        $values['filter_value'] = '';
    } elseif (in_array($values['filter_op'], SW_NO_VALUE_FILTER_OPS, true)) {
        $values['filter_value'] = '';
    }
    return $values;
}

// --------------------------------------------------------------- actions
$action = $_POST['action'] ?? '';

if ($action === 'preview') {
    // Live preview while editing -- nothing is saved.
    $values = swFormValues($table);
    $error = swValidateWidget($values, $columns);
    $out = ['text' => '—', 'tone' => 'normal', 'error' => $error, 'describe' => ''];
    if (!$error) {
        [$value, $err] = swComputeValue($values, swLoadRows($db, $userId, $config));
        $out = [
            'text' => $err ? 'Error' : swFormatValue($values, $value),
            'tone' => $err ? 'error' : swValueTone($values, $value),
            'error' => $err,
            'describe' => swDescribe($values, $columns),
        ];
    }
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode($out);
    exit;
}

if ($action === 'save' || $action === 'update') {
    $values = swFormValues($table);
    $editId = (int)($_POST['widget_id'] ?? 0);
    $error = swValidateWidget($values, $columns);
    if ($error) {
        setFlash('error', $error);
        $_SESSION['sw_form'] = $_POST;
        redirect($selfUrl . ($editId ? '&edit=' . $editId : '&action=add'));
    }
    if ($editId && swGetWidget($db, $userId, $editId)) {
        swUpdateWidget($db, $userId, $editId, $values);
        setFlash('success', 'Widget updated.');
    } else {
        swCreateWidget($db, $userId, $values);
        setFlash('success', 'Widget added.');
    }
    redirect(($_POST['back'] ?? '') === 'list' ? savingsTrackerUrl($cat) : $selfUrl);
}

$widgetId = (int)($_POST['widget_id'] ?? 0);
if ($action === 'delete' && $widgetId) {
    swDeleteWidget($db, $userId, $widgetId);
    setFlash('success', 'Widget deleted.');
    redirect($selfUrl);
}
if ($action === 'toggle' && $widgetId) {
    swToggleWidget($db, $userId, $widgetId);
    redirect($selfUrl);
}
if (($action === 'move_up' || $action === 'move_down') && $widgetId) {
    swMoveWidget($db, $userId, $widgetId, $action === 'move_up' ? -1 : 1);
    redirect($selfUrl);
}
if ($action === 'duplicate' && $widgetId) {
    swDuplicateWidget($db, $userId, $widgetId);
    setFlash('success', 'Widget duplicated.');
    redirect($selfUrl);
}
if ($action === 'suggested' || ($_POST['op'] ?? '') === 'suggested') {
    $added = swAddSuggestedWidgets($db, $userId, $config);
    setFlash('success', $added ? "Added $added suggested widget" . ($added === 1 ? '' : 's') . '.' : 'The suggested widgets are already there.');
    redirect(($_POST['back'] ?? '') === 'list' ? savingsTrackerUrl($cat) : $selfUrl);
}
if ($action === 'rename_columns') {
    swSetColumnLabels($db, $userId, $config, (array)($_POST['builtin'] ?? []));
    // Custom columns are renamed through their own label (syncs to the desktop app too).
    foreach ((array)($_POST['custom'] ?? []) as $fieldId => $label) {
        $label = trim((string)$label);
        if ($label === '') {
            continue;
        }
        $db->update(
            "UPDATE custom_fields SET field_label = ? WHERE id = ? AND user_id = ? AND linked_to = ? AND field_label <> ?",
            [mb_substr($label, 0, 255), (int)$fieldId, $userId, $table, $label]
        );
    }
    setFlash('success', 'Column names saved.');
    redirect($selfUrl . '#rename');
}

// ------------------------------------------------------------------ view
$editWidget = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $editWidget = swGetWidget($db, $userId, (int)$_GET['edit']);
}
$showForm = $editWidget || (($_GET['action'] ?? '') === 'add');
$form = $editWidget ?: [
    'title' => '', 'agg_func' => 'sum', 'source_column' => '', 'formula' => '', 'filter_column' => '',
    'filter_op' => 'eq', 'filter_value' => '', 'number_format' => 'currency', 'decimals' => 0,
    'value_prefix' => '', 'value_suffix' => '', 'accent_color' => '', 'color_rule' => 'none',
    'caption' => '', 'is_active' => 1,
];
if (!empty($_SESSION['sw_form'])) { // re-show what was typed after a validation error
    $typed = $_SESSION['sw_form'];
    unset($_SESSION['sw_form']);
    foreach ($form as $k => $_v) {
        if (array_key_exists($k, $typed)) {
            $form[$k] = $typed[$k];
        }
    }
    $form['is_active'] = isset($typed['is_active']) ? 1 : 0;
    $form['accent_color'] = ($typed['accent_choice'] ?? '') === 'custom' ? ($typed['accent_custom'] ?? '') : ($typed['accent_choice'] ?? '');
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$cards = $showForm ? [] : swComputeWidgets($db, $userId, $config, false);
$builtinLabels = swColumnLabels($db, $userId, $config);
$customFields = swCustomFields($db, $userId, $table);
$accent = (string)($form['accent_color'] ?? '');
$accentIsCustom = $accent !== '' && !isset(SW_ACCENT_PALETTE[strtolower($accent)]);
?>

<div class="toolbar" style="flex-wrap: wrap; gap: 8px;">
    <div class="toolbar-left" style="gap: 8px; flex-wrap: wrap;">
        <a href="<?php echo url('savingsmanage.php?cat=__custom__'); ?>" class="btn btn-ghost btn-sm">
            <i class="fas fa-arrow-left"></i> Custom Columns
        </a>
        <a href="<?php echo savingsTrackerUrl($cat); ?>" class="btn btn-ghost btn-sm">
            <i class="fas <?php echo $h($config['icon']); ?>"></i> Open <?php echo $h($config['label']); ?>
        </a>
        <form method="GET" style="display:inline;">
            <select name="cat" onchange="this.form.submit()" class="form-control" style="min-width: 200px;">
                <?php foreach ($SAVINGS_CATEGORIES as $key => $c): ?>
                <option value="<?php echo $h($key); ?>" <?php echo $key === $cat ? 'selected' : ''; ?>><?php echo $h($c['label']); ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <?php if (!$showForm): ?>
    <div class="toolbar-right" style="gap: 8px;">
        <form method="POST" action="<?php echo $h($selfUrl); ?>" style="display:inline;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="suggested">
            <button type="submit" class="btn btn-ghost"><i class="fas fa-magic"></i> Add suggested</button>
        </form>
        <a href="<?php echo $h($selfUrl . '&action=add'); ?>" class="btn btn-primary"><i class="fas fa-plus"></i> Add Widget</a>
    </div>
    <?php endif; ?>
</div>

<?php if ($showForm): ?>
<!-- ============================================================ Add / Edit -->
<div class="card animate-fade-in">
    <div class="card-header">
        <h2><i class="fas fa-<?php echo $editWidget ? 'edit' : 'plus'; ?>" style="margin-right: 8px; color: var(--primary);"></i>
            <?php echo $editWidget ? 'Edit' : 'Add'; ?> widget — <?php echo $h($config['label']); ?></h2>
    </div>

    <div style="margin-bottom: 16px;">
        <div class="sw-muted" style="margin-bottom: 6px;">Preview</div>
        <div class="sw-card sw-preview" id="swPreviewCard" style="--sw-accent: <?php echo $h($accent ?: SW_AUTO_ACCENTS[0]); ?>;">
            <div class="sw-title" id="swPreviewTitle"><?php echo $h($form['title'] ?: 'Widget title'); ?></div>
            <div class="sw-value" id="swPreviewValue">—</div>
            <div class="sw-caption" id="swPreviewCaption"><?php echo $h($form['caption']); ?></div>
        </div>
        <div class="sw-muted" id="swPreviewInfo" style="margin-top: 6px;"></div>
    </div>

    <form method="POST" action="<?php echo $h($selfUrl); ?>" id="swForm">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="cat" value="<?php echo $h($cat); ?>">
        <input type="hidden" name="action" value="<?php echo $editWidget ? 'update' : 'save'; ?>">
        <?php if ($editWidget): ?><input type="hidden" name="widget_id" value="<?php echo (int)$editWidget['id']; ?>"><?php endif; ?>

        <h3 style="margin: 4px 0 10px;">What to show</h3>
        <div class="form-row">
            <div class="form-group">
                <label>Title <span class="required">*</span></label>
                <input type="text" name="title" maxlength="150" required value="<?php echo $h($form['title']); ?>" placeholder="e.g. Total Invested">
            </div>
            <div class="form-group">
                <label>Calculation</label>
                <select name="agg_func" id="swAgg">
                    <?php foreach (SW_AGGREGATES as $k => $label): ?>
                    <option value="<?php echo $k; ?>" <?php echo $form['agg_func'] === $k ? 'selected' : ''; ?>><?php echo $h($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row" id="swColumnRow">
            <div class="form-group">
                <label>Column</label>
                <select name="source_column">
                    <option value="">-- Select column --</option>
                    <?php foreach ($columns as $k => $label): ?>
                    <option value="<?php echo $h($k); ?>" <?php echo $form['source_column'] === $k ? 'selected' : ''; ?>><?php echo $h($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"></div>
        </div>
        <div class="form-group" id="swFormulaRow">
            <label>Formula</label>
            <textarea name="formula" id="swFormula" rows="3" style="font-family: monospace;" placeholder="SUM(current_value) - SUM(total_invested)"><?php echo $h($form['formula']); ?></textarea>
            <div style="display: flex; gap: 8px; margin-top: 8px; flex-wrap: wrap;">
                <select id="swInsertFunc" style="max-width: 140px;">
                    <?php foreach (['SUM', 'AVG', 'MIN', 'MAX', 'COUNT', 'FILLED', 'DISTINCT', 'LATEST'] as $fn): ?>
                    <option><?php echo $fn; ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="swInsertColumn" style="max-width: 260px;">
                    <?php foreach ($columns as $k => $label): ?>
                    <option value="<?php echo $h($k); ?>"><?php echo $h($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-ghost btn-sm" id="swInsertBtn"><i class="fas fa-plus"></i> Insert</button>
            </div>
            <div class="sw-help sw-muted" style="margin-top: 8px; line-height: 1.6;">
                Use <code>+ - * / ( )</code>, numbers, and <code>SUM()</code> <code>AVG()</code> <code>MIN()</code> <code>MAX()</code>
                <code>COUNT()</code> <code>FILLED()</code> <code>DISTINCT()</code> <code>LATEST()</code> around a column name.
                Custom columns are written <code>cf:name</code>. Example — return %:
                <code>(SUM(current_value) - SUM(total_invested)) / SUM(total_invested) * 100</code>
            </div>
        </div>

        <h3 style="margin: 18px 0 10px;">Only count some records <span class="sw-muted">(optional)</span></h3>
        <div class="form-row">
            <div class="form-group">
                <label>Filter column</label>
                <select name="filter_column" id="swFilterCol">
                    <option value="">No filter — use every record</option>
                    <?php foreach ($columns as $k => $label): ?>
                    <option value="<?php echo $h($k); ?>" <?php echo $form['filter_column'] === $k ? 'selected' : ''; ?>><?php echo $h($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" id="swFilterOpGroup">
                <label>Condition</label>
                <select name="filter_op" id="swFilterOp">
                    <?php foreach (SW_FILTER_OPS as $k => $label): ?>
                    <option value="<?php echo $k; ?>" <?php echo ($form['filter_op'] ?: 'eq') === $k ? 'selected' : ''; ?>><?php echo $h($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-group" id="swFilterValueGroup">
            <label>Value</label>
            <input type="text" name="filter_value" maxlength="255" value="<?php echo $h($form['filter_value']); ?>" placeholder="e.g. Gold, 5000, 2026-01-01">
            <div class="sw-help sw-muted" style="margin-top: 6px;">
                Shortcuts: <code>{today}</code> = today's date, <code>{month}</code> = this month (e.g. "contains {month}"),
                <code>{year}</code> = this year.
            </div>
        </div>

        <h3 style="margin: 18px 0 10px;">How it looks</h3>
        <div class="form-row">
            <div class="form-group">
                <label>Number format</label>
                <select name="number_format">
                    <?php foreach (SW_FORMATS as $k => $label): ?>
                    <option value="<?php echo $k; ?>" <?php echo $form['number_format'] === $k ? 'selected' : ''; ?>><?php echo $h($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Decimal places</label>
                <select name="decimals">
                    <?php for ($d = 0; $d <= 6; $d++): ?>
                    <option value="<?php echo $d; ?>" <?php echo (int)$form['decimals'] === $d ? 'selected' : ''; ?>><?php echo $d; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Text before value</label>
                <input type="text" name="value_prefix" maxlength="30" value="<?php echo $h($form['value_prefix']); ?>" placeholder="e.g. ~">
            </div>
            <div class="form-group">
                <label>Text after value</label>
                <input type="text" name="value_suffix" maxlength="30" value="<?php echo $h($form['value_suffix']); ?>" placeholder="e.g. / month">
            </div>
        </div>
        <div class="form-group">
            <label>Caption under the value</label>
            <input type="text" name="caption" maxlength="255" value="<?php echo $h($form['caption']); ?>" placeholder="e.g. Across all SIPs">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Accent colour</label>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <select name="accent_choice" id="swAccentChoice">
                        <option value="" <?php echo $accent === '' ? 'selected' : ''; ?>>Automatic</option>
                        <?php foreach (SW_ACCENT_PALETTE as $hex => $name): ?>
                        <option value="<?php echo $hex; ?>" <?php echo strtolower($accent) === $hex ? 'selected' : ''; ?>><?php echo $h($name); ?></option>
                        <?php endforeach; ?>
                        <option value="custom" <?php echo $accentIsCustom ? 'selected' : ''; ?>>Custom…</option>
                    </select>
                    <input type="color" name="accent_custom" id="swAccentCustom" value="<?php echo $h($accentIsCustom ? $accent : '#6366f1'); ?>" style="width: 48px; height: 38px; padding: 2px;">
                </div>
            </div>
            <div class="form-group">
                <label>Value colour</label>
                <select name="color_rule">
                    <?php foreach (SW_COLOR_RULES as $k => $label): ?>
                    <option value="<?php echo $k; ?>" <?php echo ($form['color_rule'] ?: 'none') === $k ? 'selected' : ''; ?>><?php echo $h($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                <input type="checkbox" name="is_active" value="1" <?php echo ((int)($form['is_active'] ?? 1) === 1) ? 'checked' : ''; ?> style="width: auto;">
                Show this widget
            </label>
        </div>

        <div class="form-actions">
            <a href="<?php echo $h($selfUrl); ?>" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo $editWidget ? 'Update' : 'Save'; ?></button>
        </div>
    </form>
</div>

<script>
(function () {
    var form = document.getElementById('swForm');
    var agg = document.getElementById('swAgg');
    var filterCol = document.getElementById('swFilterCol');
    var filterOp = document.getElementById('swFilterOp');
    var accentChoice = document.getElementById('swAccentChoice');
    var accentCustom = document.getElementById('swAccentCustom');
    var autoAccent = <?php echo json_encode($editWidget ? swAccentFor($editWidget, 0) : SW_AUTO_ACCENTS[0]); ?>;
    var noValueOps = <?php echo json_encode(SW_NO_VALUE_FILTER_OPS); ?>;

    function sync() {
        var isFormula = agg.value === 'formula';
        document.getElementById('swFormulaRow').style.display = isFormula ? '' : 'none';
        document.getElementById('swColumnRow').style.display = (isFormula || agg.value === 'count') ? 'none' : '';
        var hasFilter = filterCol.value !== '';
        document.getElementById('swFilterOpGroup').style.visibility = hasFilter ? 'visible' : 'hidden';
        document.getElementById('swFilterValueGroup').style.display =
            (hasFilter && noValueOps.indexOf(filterOp.value) === -1) ? '' : 'none';
        accentCustom.style.display = accentChoice.value === 'custom' ? '' : 'none';
        var accent = accentChoice.value === 'custom' ? accentCustom.value : (accentChoice.value || autoAccent);
        document.getElementById('swPreviewCard').style.setProperty('--sw-accent', accent);
        document.getElementById('swPreviewTitle').textContent = form.title.value || 'Widget title';
        document.getElementById('swPreviewCaption').textContent = form.caption.value;
    }

    var timer = null;
    function preview() {
        clearTimeout(timer);
        timer = setTimeout(function () {
            var data = new FormData(form);
            data.set('action', 'preview');
            fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    var el = document.getElementById('swPreviewValue');
                    el.textContent = res.text;
                    el.className = 'sw-value sw-tone-' + res.tone;
                    document.getElementById('swPreviewInfo').textContent = res.error ? res.error : res.describe;
                    document.getElementById('swPreviewInfo').style.color = res.error ? 'var(--danger)' : '';
                })
                .catch(function () {});
        }, 350);
    }

    document.getElementById('swInsertBtn').addEventListener('click', function () {
        var ta = document.getElementById('swFormula');
        var snippet = document.getElementById('swInsertFunc').value + '(' + document.getElementById('swInsertColumn').value + ')';
        var start = ta.selectionStart || ta.value.length, end = ta.selectionEnd || ta.value.length;
        var before = ta.value.slice(0, start), after = ta.value.slice(end);
        var pad = before && !/[\s(]$/.test(before) ? ' ' : '';
        ta.value = before + pad + snippet + after;
        ta.focus();
        ta.selectionStart = ta.selectionEnd = (before + pad + snippet).length;
        preview();
    });

    form.addEventListener('input', function () { sync(); preview(); });
    form.addEventListener('change', function () { sync(); preview(); });
    sync();
    preview();
})();
</script>

<?php else: ?>
<!-- ============================================================ Widget list -->
<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-th-large" style="margin-right: 8px; color: var(--primary);"></i> Widgets — <?php echo $h($config['label']); ?></h2>
        <p class="sw-muted" style="margin: 8px 0 0;">
            Summary cards shown above the <?php echo $h($config['label']); ?> list, in this order. They sync with the desktop app.
        </p>
    </div>
    <?php if (!$cards): ?>
    <div class="empty-state">
        <i class="fas fa-th-large"></i>
        <h3>No widgets yet</h3>
        <p>Add your own, or start with the suggested set and edit from there.</p>
    </div>
    <?php else: ?>
    <div class="table-container" style="border: none;">
        <table class="data-table sw-table">
            <thead>
                <tr><th>Widget</th><th>Calculates</th><th style="text-align: right;">Now</th><th>Shown</th><th style="text-align: right;">Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($cards as $i => $card): $w = $card['widget']; $active = $w['is_active'] === null || (int)$w['is_active'] === 1; ?>
                <tr style="<?php echo $active ? '' : 'opacity: 0.55;'; ?>">
                    <td><span class="sw-swatch" style="background: <?php echo $h($card['accent']); ?>;"></span><strong><?php echo $h($w['title']); ?></strong>
                        <?php if (!empty($w['caption'])): ?><div class="sw-muted"><?php echo $h($w['caption']); ?></div><?php endif; ?></td>
                    <td class="sw-muted" style="max-width: 360px;"><?php echo $h(swDescribe($w, $columns)); ?>
                        <?php if ($card['error']): ?><div style="color: var(--danger);"><?php echo $h($card['error']); ?></div><?php endif; ?></td>
                    <td style="text-align: right; white-space: nowrap;" class="sw-tone-<?php echo $h($card['tone']); ?>"><strong><?php echo $h($card['text']); ?></strong></td>
                    <td><?php echo $active ? 'Yes' : 'Hidden'; ?></td>
                    <td style="text-align: right; white-space: nowrap;">
                        <?php foreach ([
                            ['move_up', 'fa-arrow-up', 'Move up', $i === 0],
                            ['move_down', 'fa-arrow-down', 'Move down', $i === count($cards) - 1],
                            ['toggle', $active ? 'fa-eye-slash' : 'fa-eye', $active ? 'Hide' : 'Show', false],
                            ['duplicate', 'fa-copy', 'Duplicate', false],
                        ] as [$act, $icon, $label, $disabled]): ?>
                        <form method="POST" action="<?php echo $h($selfUrl); ?>" style="display:inline;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="<?php echo $act; ?>">
                            <input type="hidden" name="widget_id" value="<?php echo (int)$w['id']; ?>">
                            <button type="submit" class="btn btn-icon btn-ghost" title="<?php echo $h($label); ?>" <?php echo $disabled ? 'disabled' : ''; ?>><i class="fas <?php echo $icon; ?>"></i></button>
                        </form>
                        <?php endforeach; ?>
                        <a href="<?php echo $h($selfUrl . '&edit=' . (int)$w['id']); ?>" class="btn btn-icon btn-ghost" title="Edit"><i class="fas fa-pen"></i></a>
                        <form method="POST" action="<?php echo $h($selfUrl); ?>" style="display:inline;" id="swDel<?php echo (int)$w['id']; ?>">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="widget_id" value="<?php echo (int)$w['id']; ?>">
                            <button type="button" class="btn btn-icon btn-ghost" title="Delete" style="color: var(--danger);"
                                    onclick="showConfirm('Delete Widget', 'Delete this widget?', function () { document.getElementById('swDel<?php echo (int)$w['id']; ?>').submit(); })">
                                <i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ============================================================ Rename columns -->
<div class="card" id="rename" style="margin-top: 20px;">
    <div class="card-header">
        <h2><i class="fas fa-i-cursor" style="margin-right: 8px; color: var(--primary);"></i> Column names — <?php echo $h($config['label']); ?></h2>
        <p class="sw-muted" style="margin: 8px 0 0;">
            Give any column a new name. It changes the list, the Add/Edit form and the widgets — here and in the desktop app.
            Leave a built-in box empty to go back to its original name.
        </p>
    </div>
    <form method="POST" action="<?php echo $h($selfUrl); ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="rename_columns">
        <div class="table-container" style="border: none;">
            <table class="data-table">
                <thead><tr><th style="width: 40%;">Original name</th><th>New name</th></tr></thead>
                <tbody>
                <?php foreach ($config['fields'] as $field): $current = $builtinLabels[$field['name']] ?? $field['label']; ?>
                    <tr>
                        <td><?php echo $h($field['label']); ?> <span class="sw-muted">· built-in</span></td>
                        <td><input type="text" name="builtin[<?php echo $h($field['name']); ?>]" maxlength="150"
                                   value="<?php echo $current !== $field['label'] ? $h($current) : ''; ?>"
                                   placeholder="<?php echo $h($field['label']); ?>"></td>
                    </tr>
                <?php endforeach; ?>
                <?php foreach ($customFields as $cfRow): ?>
                    <tr>
                        <td><?php echo $h($cfRow['field_label']); ?> <span class="sw-muted">· custom</span></td>
                        <td><input type="text" name="custom[<?php echo (int)$cfRow['id']; ?>]" maxlength="255"
                                   value="<?php echo $h($cfRow['field_label']); ?>"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="form-actions">
            <a href="<?php echo url('custom-fields.php?module=' . urlencode($table)); ?>" class="btn btn-ghost"><i class="fas fa-puzzle-piece"></i> Add custom column</a>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save names</button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
