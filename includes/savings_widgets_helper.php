<?php
/**
 * Custom summary widgets + renamed columns for the Savings Tracker.
 *
 * Website twin of the desktop app's app/models/savings_widgets.py -- the same
 * tables (savings_widgets, savings_column_labels, synced both ways by
 * desktop-sync-api.php), the same calculations, formats, filter rules and
 * formula language, so a widget shows the same number in both apps.
 *
 * Column references: a built-in column is its table column name
 * ("sip_amount"); a custom column is "cf:<field_name>" (field_name, not id --
 * ids differ between the website and the desktop database).
 *
 * Only defines functions: desktop-sync-api.php includes it too, and that file
 * must not pull in savings_helper.php. Callers pass the category's config
 * (an entry of $SAVINGS_CATEGORIES) in.
 */

function ensureSavingsWidgetTables($db): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $db->query("CREATE TABLE IF NOT EXISTS savings_column_labels (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
            category VARCHAR(64) NOT NULL, column_name VARCHAR(120) NOT NULL, label VARCHAR(150) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id), INDEX idx_category (category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->query("CREATE TABLE IF NOT EXISTS savings_widgets (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
            category VARCHAR(64) NOT NULL,
            title VARCHAR(150) NOT NULL,
            agg_func VARCHAR(20) NOT NULL DEFAULT 'sum',
            source_column VARCHAR(120) NULL,
            formula TEXT NULL,
            filter_column VARCHAR(120) NULL,
            filter_op VARCHAR(20) NULL,
            filter_value VARCHAR(255) NULL,
            number_format VARCHAR(20) NULL DEFAULT 'currency',
            decimals INT NULL DEFAULT 0,
            value_prefix VARCHAR(30) NULL,
            value_suffix VARCHAR(30) NULL,
            accent_color VARCHAR(20) NULL,
            color_rule VARCHAR(20) NULL DEFAULT 'none',
            caption VARCHAR(255) NULL,
            widget_order INT NULL DEFAULT 0,
            is_active TINYINT(1) NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id), INDEX idx_category (category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        // DB user without CREATE permission -- the feature just stays empty.
    }
}

// ------------------------------------------------------------------ options

const SW_AGGREGATES = [
    'sum' => 'Sum',
    'avg' => 'Average',
    'min' => 'Minimum',
    'max' => 'Maximum',
    'count' => 'Count of records',
    'filled' => 'Count of filled values',
    'distinct' => 'Count of distinct values',
    'latest' => "Latest record's value",
    'formula' => 'Formula (advanced)',
];
const SW_NO_COLUMN_AGGREGATES = ['count', 'formula'];

const SW_FORMATS = [
    'currency' => 'Currency (Rs 1,234)',
    'compact' => 'Currency, short (Rs 1.2 L / Cr)',
    'number' => 'Number (1,234.5)',
    'percent' => 'Percent (12.5%)',
    'plain' => 'Plain (1234.5)',
];

const SW_COLOR_RULES = [
    'none' => 'Normal text colour',
    'negative' => 'Red when below zero',
    'sign' => 'Green above zero, red below',
];

const SW_FILTER_OPS = [
    'eq' => 'equals',
    'ne' => 'does not equal',
    'gt' => 'greater than',
    'ge' => 'greater than or equal',
    'lt' => 'less than',
    'le' => 'less than or equal',
    'contains' => 'contains',
    'not_contains' => 'does not contain',
    'empty' => 'is empty',
    'not_empty' => 'is not empty',
];
const SW_NO_VALUE_FILTER_OPS = ['empty', 'not_empty'];

const SW_ACCENT_PALETTE = [
    '#6366f1' => 'Indigo', '#10b981' => 'Green', '#f59e0b' => 'Amber', '#ec4899' => 'Pink',
    '#06b6d4' => 'Cyan', '#f97316' => 'Orange', '#3b82f6' => 'Blue', '#a855f7' => 'Purple',
    '#ef4444' => 'Red', '#64748b' => 'Slate',
];
const SW_AUTO_ACCENTS = ['#6366f1', '#10b981', '#f59e0b', '#ec4899', '#06b6d4', '#f97316', '#3b82f6', '#a855f7'];

const SW_WIDGET_COLUMNS = [
    'category', 'title', 'agg_func', 'source_column', 'formula', 'filter_column', 'filter_op',
    'filter_value', 'number_format', 'decimals', 'value_prefix', 'value_suffix', 'accent_color',
    'color_rule', 'caption', 'widget_order', 'is_active',
];

class SwFormulaError extends Exception {}

// ------------------------------------------------------------ column labels

/** Built-in column => label for a category, renamed ones replacing the defaults. */
function swColumnLabels($db, int $userId, array $config): array {
    $labels = [];
    foreach ($config['fields'] as $field) {
        $labels[$field['name']] = $field['label'];
    }
    try {
        $rows = $db->fetchAll(
            "SELECT column_name, label FROM savings_column_labels WHERE user_id = ? AND category = ? ORDER BY updated_at, id",
            [$userId, $config['table']]
        );
    } catch (Exception $e) {
        return $labels;
    }
    foreach ($rows as $row) { // newest last, so it wins over a duplicate from sync
        $label = trim((string)$row['label']);
        if (isset($labels[$row['column_name']]) && $label !== '') {
            $labels[$row['column_name']] = $label;
        }
    }
    return $labels;
}

/** A blank label, or one equal to the default, goes back to the default. */
function swSetColumnLabels($db, int $userId, array $config, array $wantedLabels): void {
    $category = $config['table'];
    $existing = [];
    foreach ($db->fetchAll(
        "SELECT id, column_name, label FROM savings_column_labels WHERE user_id = ? AND category = ? ORDER BY updated_at, id",
        [$userId, $category]
    ) as $row) {
        $existing[$row['column_name']][] = $row;
    }
    foreach ($config['fields'] as $field) {
        $column = $field['name'];
        if (!array_key_exists($column, $wantedLabels)) {
            continue;
        }
        $wanted = trim((string)$wantedLabels[$column]);
        $rows = $existing[$column] ?? [];
        if ($wanted === '' || $wanted === $field['label']) {
            foreach ($rows as $row) {
                $db->delete("DELETE FROM savings_column_labels WHERE id = ? AND user_id = ?", [$row['id'], $userId]);
            }
            continue;
        }
        if (!$rows) {
            $db->insert(
                "INSERT INTO savings_column_labels (user_id, category, column_name, label) VALUES (?, ?, ?, ?)",
                [$userId, $category, $column, mb_substr($wanted, 0, 150)]
            );
            continue;
        }
        $keep = array_pop($rows);
        foreach ($rows as $row) {
            $db->delete("DELETE FROM savings_column_labels WHERE id = ? AND user_id = ?", [$row['id'], $userId]);
        }
        if ($keep['label'] !== $wanted) {
            $db->update("UPDATE savings_column_labels SET label = ? WHERE id = ? AND user_id = ?", [mb_substr($wanted, 0, 150), $keep['id'], $userId]);
        }
    }
}

/** The category config with the user's renamed labels applied. */
function swApplyColumnLabels($db, int $userId, array $config): array {
    $labels = swColumnLabels($db, $userId, $config);
    foreach ($config['fields'] as $i => $field) {
        $config['fields'][$i]['default_label'] = $field['label'];
        $config['fields'][$i]['label'] = $labels[$field['name']] ?? $field['label'];
    }
    return $config;
}

// ----------------------------------------------------------------- columns

function swCustomFields($db, int $userId, string $table): array {
    try {
        $rows = $db->fetchAll(
            "SELECT * FROM custom_fields WHERE user_id = ? AND linked_to = ? AND is_active = 1 ORDER BY field_order, id",
            [$userId, $table]
        );
    } catch (Exception $e) {
        return [];
    }
    $seen = [];
    $out = [];
    foreach ($rows as $row) {
        if (isset($seen[$row['field_name']])) {
            continue;
        }
        $seen[$row['field_name']] = true;
        $out[] = $row;
    }
    return $out;
}

/** [column key => label]: built-in columns, then active custom columns. */
function swAvailableColumns($db, int $userId, array $config): array {
    $columns = swColumnLabels($db, $userId, $config);
    foreach (swCustomFields($db, $userId, $config['table']) as $f) {
        $columns['cf:' . $f['field_name']] = $f['field_label'] . ' (custom)';
    }
    return $columns;
}

// ----------------------------------------------------------------- widgets

function swListWidgets($db, int $userId, string $category, bool $activeOnly = false): array {
    $sql = "SELECT * FROM savings_widgets WHERE user_id = ? AND category = ?";
    if ($activeOnly) {
        $sql .= " AND COALESCE(is_active, 1) = 1";
    }
    $sql .= " ORDER BY COALESCE(widget_order, 0), id";
    try {
        return $db->fetchAll($sql, [$userId, $category]);
    } catch (Exception $e) {
        return [];
    }
}

function swGetWidget($db, int $userId, int $id): ?array {
    $row = $db->fetch("SELECT * FROM savings_widgets WHERE id = ? AND user_id = ?", [$id, $userId]);
    return $row ?: null;
}

function swCleanWidget(array $values): array {
    $out = [];
    foreach (SW_WIDGET_COLUMNS as $col) {
        if (array_key_exists($col, $values)) {
            $out[$col] = $values[$col];
        }
    }
    foreach (['title', 'source_column', 'formula', 'filter_column', 'filter_value', 'value_prefix',
              'value_suffix', 'accent_color', 'caption'] as $col) {
        if (array_key_exists($col, $out)) {
            $v = trim((string)($out[$col] ?? ''));
            $out[$col] = $v === '' ? null : $v;
        }
    }
    if (!empty($out['accent_color']) && !preg_match('/^#[0-9a-fA-F]{6}$/', $out['accent_color'])) {
        $out['accent_color'] = null;
    }
    if (array_key_exists('decimals', $out)) {
        $out['decimals'] = max(0, min(6, (int)$out['decimals']));
    }
    if (isset($out['agg_func']) && !isset(SW_AGGREGATES[$out['agg_func']])) {
        $out['agg_func'] = 'sum';
    }
    if (isset($out['number_format']) && !isset(SW_FORMATS[$out['number_format']])) {
        $out['number_format'] = 'currency';
    }
    if (isset($out['color_rule']) && !isset(SW_COLOR_RULES[$out['color_rule']])) {
        $out['color_rule'] = 'none';
    }
    if (isset($out['filter_op']) && $out['filter_op'] !== null && !isset(SW_FILTER_OPS[$out['filter_op']])) {
        $out['filter_op'] = null;
    }
    return $out;
}

/** An error message, or null when the widget can be saved. */
function swValidateWidget(array $values, array $availableColumns): ?string {
    if (trim((string)($values['title'] ?? '')) === '') {
        return 'Give the widget a title.';
    }
    $agg = $values['agg_func'] ?? '';
    if (!isset(SW_AGGREGATES[$agg])) {
        return 'Choose a calculation.';
    }
    if ($agg === 'formula') {
        if (trim((string)($values['formula'] ?? '')) === '') {
            return 'Write a formula, for example SUM(current_value) - SUM(total_invested).';
        }
        try {
            (new SwFormula($values['formula'], array_keys($availableColumns)))->parse();
        } catch (SwFormulaError $e) {
            return 'Formula: ' . $e->getMessage();
        }
    } elseif (!in_array($agg, SW_NO_COLUMN_AGGREGATES, true) && empty($values['source_column'])) {
        return 'Choose the column to calculate on.';
    }
    if (!empty($values['filter_column']) && !isset(SW_FILTER_OPS[$values['filter_op'] ?? ''])) {
        return 'Choose how the filter compares.';
    }
    return null;
}

function swCreateWidget($db, int $userId, array $values): int {
    $values = swCleanWidget($values);
    if (!isset($values['widget_order'])) {
        $row = $db->fetch(
            "SELECT COALESCE(MAX(widget_order), 0) AS m FROM savings_widgets WHERE user_id = ? AND category = ?",
            [$userId, $values['category']]
        );
        $values['widget_order'] = (int)($row['m'] ?? 0) + 1;
    }
    if (!isset($values['is_active'])) {
        $values['is_active'] = 1;
    }
    $cols = array_keys($values);
    $sql = sprintf(
        'INSERT INTO savings_widgets (user_id, %s) VALUES (?, %s)',
        implode(', ', array_map(fn($c) => "`$c`", $cols)),
        implode(', ', array_fill(0, count($cols), '?'))
    );
    return (int)$db->insert($sql, array_merge([$userId], array_values($values)));
}

function swUpdateWidget($db, int $userId, int $id, array $values): void {
    $values = swCleanWidget($values);
    if (!$values) {
        return;
    }
    $set = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($values)));
    $db->update("UPDATE savings_widgets SET $set WHERE id = ? AND user_id = ?", array_merge(array_values($values), [$id, $userId]));
}

function swDeleteWidget($db, int $userId, int $id): void {
    $db->delete("DELETE FROM savings_widgets WHERE id = ? AND user_id = ?", [$id, $userId]);
}

function swToggleWidget($db, int $userId, int $id): void {
    $w = swGetWidget($db, $userId, $id);
    if ($w) {
        $active = $w['is_active'] === null || (int)$w['is_active'] === 1;
        $db->update("UPDATE savings_widgets SET is_active = ? WHERE id = ? AND user_id = ?", [$active ? 0 : 1, $id, $userId]);
    }
}

function swDuplicateWidget($db, int $userId, int $id): ?int {
    $w = swGetWidget($db, $userId, $id);
    if (!$w) {
        return null;
    }
    $values = [];
    foreach (SW_WIDGET_COLUMNS as $col) {
        $values[$col] = $w[$col] ?? null;
    }
    $values['title'] = ($w['title'] ?: 'Widget') . ' (copy)';
    unset($values['widget_order']);
    return swCreateWidget($db, $userId, $values);
}

function swMoveWidget($db, int $userId, int $id, int $direction): void {
    $w = swGetWidget($db, $userId, $id);
    if (!$w) {
        return;
    }
    $siblings = swListWidgets($db, $userId, $w['category']);
    foreach ($siblings as $pos => $row) { // distinct consecutive positions first (sync can leave ties)
        if ((int)$row['widget_order'] !== $pos + 1) {
            $db->update("UPDATE savings_widgets SET widget_order = ? WHERE id = ? AND user_id = ?", [$pos + 1, $row['id'], $userId]);
        }
        $siblings[$pos]['widget_order'] = $pos + 1;
    }
    $ids = array_map(fn($r) => (int)$r['id'], $siblings);
    $idx = array_search($id, $ids, true);
    $new = $idx + $direction;
    if ($idx === false || $new < 0 || $new >= count($ids)) {
        return;
    }
    $a = $siblings[$idx];
    $b = $siblings[$new];
    $db->update("UPDATE savings_widgets SET widget_order = ? WHERE id = ? AND user_id = ?", [$b['widget_order'], $a['id'], $userId]);
    $db->update("UPDATE savings_widgets SET widget_order = ? WHERE id = ? AND user_id = ?", [$a['widget_order'], $b['id'], $userId]);
}

// -------------------------------------------------------- suggested widgets

function swShortLabel(string $label): string {
    return trim(str_replace([' — auto-calculated', ' (Rs.)'], '', $label));
}

/** A sensible starter set for a category, built from its own columns. */
function swSuggestedWidgets($db, int $userId, array $config): array {
    $category = $config['table'];
    $labels = swColumnLabels($db, $userId, $config);
    $names = array_keys($labels);
    $has = fn($c) => in_array($c, $names, true);
    $w = function ($title, $agg, $column = null, $formula = null, $fmt = 'currency', $rule = 'none', $caption = null) use ($category) {
        return ['category' => $category, 'title' => $title, 'agg_func' => $agg, 'source_column' => $column,
                'formula' => $formula, 'number_format' => $fmt, 'decimals' => $fmt === 'percent' ? 2 : 0,
                'color_rule' => $rule, 'caption' => $caption];
    };
    $out = [$w('Records', 'count', null, null, 'number')];
    $invested = null;
    foreach (['total_invested', 'invested', 'total_deposited', 'principal', 'opening_principal'] as $c) {
        if ($has($c)) { $invested = $c; break; }
    }
    $value = null;
    foreach (['current_value', 'balance', 'current_balance', 'amount', 'maturity_amount',
              'outstanding_balance', 'outstanding_amount', 'used_amount', 'sum_assured'] as $c) {
        if ($has($c)) { $value = $c; break; }
    }
    if ($invested) {
        $out[] = $w(swShortLabel($labels[$invested]), 'sum', $invested);
    }
    if ($value) {
        $out[] = $w(swShortLabel($labels[$value]), 'sum', $value);
    }
    if ($invested && $value === 'current_value') {
        $out[] = $w('Gain / Loss', 'formula', null, "SUM($value) - SUM($invested)", 'currency', 'sign');
        $out[] = $w('Return', 'formula', null, "(SUM($value) - SUM($invested)) / SUM($invested) * 100", 'percent', 'sign');
    }
    if ($has('sip_amount')) { $out[] = $w('Monthly SIP', 'sum', 'sip_amount'); }
    if ($has('emi_amount')) { $out[] = $w('Total EMI', 'sum', 'emi_amount'); }
    if ($has('premium')) { $out[] = $w('Total Premium', 'sum', 'premium'); }
    if ($has('credit_limit')) { $out[] = $w('Credit Limit', 'sum', 'credit_limit'); }
    if ($has('interest_rate')) { $out[] = $w('Avg Interest Rate', 'avg', 'interest_rate', null, 'percent'); }
    if ($category === 'savings_daily') {
        $month = $w('This Month', 'sum', 'amount', null, 'currency', 'none', 'Saved this month');
        $month += ['filter_column' => 'date', 'filter_op' => 'contains', 'filter_value' => '{month}'];
        $out[] = $month;
    }
    return $out;
}

function swAddSuggestedWidgets($db, int $userId, array $config): int {
    $existing = [];
    foreach (swListWidgets($db, $userId, $config['table']) as $w) {
        $existing[mb_strtolower((string)$w['title'])] = true;
    }
    $added = 0;
    foreach (swSuggestedWidgets($db, $userId, $config) as $values) {
        if (isset($existing[mb_strtolower($values['title'])])) {
            continue;
        }
        swCreateWidget($db, $userId, $values);
        $added++;
    }
    return $added;
}

// ------------------------------------------------------------- computation

function swNum($value): ?float {
    if ($value === null || is_bool($value)) {
        return null;
    }
    if (is_int($value) || is_float($value)) {
        return is_nan((float)$value) ? null : (float)$value;
    }
    $text = trim(str_replace([',', '₹', '%'], '', (string)$value));
    if (stripos($text, 'rs') === 0) {
        $text = ltrim(substr($text, 2), '. ');
    }
    $text = trim($text);
    if ($text === '' || !is_numeric($text)) {
        return null;
    }
    return (float)$text;
}

function swIsEmpty($value): bool {
    return $value === null || (is_string($value) && trim($value) === '');
}

/** All of a category's rows plus the custom-column values, loaded once. */
function swLoadRows($db, int $userId, array $config): array {
    $table = $config['table'];
    $data = ['config' => $config, 'rows' => [], 'cfIds' => [], 'cfValues' => []];
    if (!preg_match('/^savings_[a-z_]+$/', $table)) {
        return $data;
    }
    try {
        $data['rows'] = $db->fetchAll("SELECT * FROM `$table` WHERE user_id = ? ORDER BY id", [$userId]);
    } catch (Exception $e) {
        return $data;
    }
    foreach (swCustomFields($db, $userId, $table) as $f) {
        $data['cfIds'][$f['field_name']] = (int)$f['id'];
    }
    if ($data['cfIds'] && $data['rows']) {
        $ids = array_values($data['cfIds']);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        try {
            $entries = $db->fetchAll(
                "SELECT record_id, custom_field_id, field_value FROM custom_field_entries
                 WHERE linked_to = ? AND block_instance = 0 AND custom_field_id IN ($ph)",
                array_merge([$table], $ids)
            );
        } catch (Exception $e) {
            $entries = [];
        }
        foreach ($entries as $e) {
            $data['cfValues'][(int)$e['record_id']][(int)$e['custom_field_id']] = $e['field_value'];
        }
    }
    return $data;
}

function swHasColumn(array $data, string $key): bool {
    if (strpos($key, 'cf:') === 0) {
        return isset($data['cfIds'][substr($key, 3)]);
    }
    foreach ($data['config']['fields'] as $f) {
        if ($f['name'] === $key) {
            return true;
        }
    }
    return false;
}

function swValue(array $data, array $row, string $key) {
    if (strpos($key, 'cf:') === 0) {
        $fid = $data['cfIds'][substr($key, 3)] ?? null;
        return $fid === null ? null : ($data['cfValues'][(int)$row['id']][$fid] ?? null);
    }
    return $row[$key] ?? null;
}

/** A select column filtered by its display label ("Gold") matches the stored value ("gold"). */
function swOptionValue(array $data, string $key, string $text): string {
    if (strpos($key, 'cf:') === 0) {
        return $text;
    }
    foreach ($data['config']['fields'] as $f) {
        if ($f['name'] === $key && !empty($f['options'])) {
            foreach ($f['options'] as $stored => $display) {
                if (mb_strtolower($display) === mb_strtolower($text)) {
                    return (string)$stored;
                }
            }
        }
    }
    return $text;
}

function swExpandTokens(string $text): string {
    return str_replace(['{today}', '{month}', '{year}'], [date('Y-m-d'), date('Y-m'), date('Y')], $text);
}

function swMatches($raw, string $op, string $wanted): bool {
    if ($op === 'empty') {
        return swIsEmpty($raw);
    }
    if ($op === 'not_empty') {
        return !swIsEmpty($raw);
    }
    $text = $raw === null ? '' : trim((string)$raw);
    if ($op === 'contains' || $op === 'not_contains') {
        $found = $wanted === '' || mb_stripos($text, $wanted) !== false;
        return $op === 'contains' ? $found : !$found;
    }
    $a = swNum($raw);
    $b = swNum($wanted);
    if ($a !== null && $b !== null) {
        $cmp = $a <=> $b;
    } else {
        $cmp = strcmp(mb_strtolower($text), mb_strtolower(trim($wanted)));
        $cmp = $cmp <=> 0;
    }
    if ($op === 'eq') {
        return $cmp === 0;
    }
    if ($op === 'ne') {
        return $cmp !== 0;
    }
    if (swIsEmpty($raw)) {
        return false; // an empty value is neither bigger nor smaller than anything
    }
    switch ($op) {
        case 'gt': return $cmp > 0;
        case 'ge': return $cmp >= 0;
        case 'lt': return $cmp < 0;
        case 'le': return $cmp <= 0;
    }
    return true;
}

function swFiltered(array $data, array $widget): array {
    $column = $widget['filter_column'] ?? null;
    $op = $widget['filter_op'] ?? null;
    if (!$column || !$op) {
        return $data['rows'];
    }
    $wanted = swOptionValue($data, $column, swExpandTokens((string)($widget['filter_value'] ?? '')));
    return array_values(array_filter($data['rows'], fn($row) => swMatches(swValue($data, $row, $column), $op, $wanted)));
}

function swAggregate(string $func, array $data, array $rows, ?string $column) {
    $func = strtolower($func);
    if ($func === 'count' && !$column) {
        return (float)count($rows);
    }
    if (!$column) {
        throw new SwFormulaError(strtoupper($func) . '() needs a column.');
    }
    $values = array_map(fn($row) => swValue($data, $row, $column), $rows);
    if ($func === 'count' || $func === 'filled') {
        return (float)count(array_filter($values, fn($v) => !swIsEmpty($v)));
    }
    if ($func === 'distinct') {
        $set = [];
        foreach ($values as $v) {
            if (!swIsEmpty($v)) {
                $set[mb_strtolower(trim((string)$v))] = true;
            }
        }
        return (float)count($set);
    }
    if ($func === 'latest') {
        for ($i = count($values) - 1; $i >= 0; $i--) {
            if (!swIsEmpty($values[$i])) {
                $n = swNum($values[$i]);
                return $n !== null ? $n : (string)$values[$i];
            }
        }
        return null;
    }
    $nums = [];
    foreach ($values as $v) {
        $n = swNum($v);
        if ($n !== null) {
            $nums[] = $n;
        }
    }
    if ($func === 'sum') {
        return (float)array_sum($nums);
    }
    if (!$nums) {
        return null;
    }
    switch ($func) {
        case 'avg': return array_sum($nums) / count($nums);
        case 'min': return min($nums);
        case 'max': return max($nums);
    }
    throw new SwFormulaError('Unknown function ' . strtoupper($func) . '.');
}

/**
 * Tiny safe arithmetic parser: numbers, + - * / ( ), and
 * SUM/AVG/MIN/MAX/COUNT/FILLED/DISTINCT/LATEST(column). Never eval().
 */
class SwFormula {
    const FUNCS = ['sum', 'avg', 'min', 'max', 'count', 'filled', 'distinct', 'latest'];
    private $tokens = [];
    private $pos = 0;
    private $known;

    public function __construct(string $text, array $knownColumns) {
        $this->known = $knownColumns;
        preg_match_all('/\s*(?:(\d+(?:\.\d+)?)|([A-Za-z_][A-Za-z0-9_]*(?::[A-Za-z0-9_]+)?)|(.))/s', $text, $m, PREG_SET_ORDER);
        foreach ($m as $match) {
            if (($match[1] ?? '') !== '') {
                $this->tokens[] = ['num', $match[1]];
            } elseif (($match[2] ?? '') !== '') {
                $this->tokens[] = ['name', $match[2]];
            } elseif (isset($match[3]) && trim($match[3]) !== '') {
                if (strpos('+-*/()', $match[3]) === false) {
                    throw new SwFormulaError("Unexpected character '{$match[3]}'.");
                }
                $this->tokens[] = ['op', $match[3]];
            }
        }
    }

    public function parse() {
        if (!$this->tokens) {
            throw new SwFormulaError('The formula is empty.');
        }
        $tree = $this->expr();
        if ($this->pos !== count($this->tokens)) {
            throw new SwFormulaError("Unexpected '{$this->tokens[$this->pos][1]}'.");
        }
        return $tree;
    }

    private function peek(): array {
        return $this->tokens[$this->pos] ?? [null, null];
    }

    private function take($value = null): array {
        [$kind, $val] = $this->peek();
        if ($kind === null || ($value !== null && $val !== $value)) {
            throw new SwFormulaError($value !== null ? "Expected '$value'." : 'The formula ends too early.');
        }
        $this->pos++;
        return [$kind, $val];
    }

    private function isOp(string ...$ops): bool {
        [$kind, $val] = $this->peek();
        return $kind === 'op' && in_array($val, $ops, true);
    }

    private function expr() {
        $node = $this->term();
        while ($this->isOp('+', '-')) {
            $op = $this->take()[1];
            $node = ['bin', $op, $node, $this->term()];
        }
        return $node;
    }

    private function term() {
        $node = $this->factor();
        while ($this->isOp('*', '/')) {
            $op = $this->take()[1];
            $node = ['bin', $op, $node, $this->factor()];
        }
        return $node;
    }

    private function factor() {
        [$kind, $val] = $this->peek();
        if ($this->isOp('-')) {
            $this->take();
            return ['neg', $this->factor()];
        }
        if ($this->isOp('+')) {
            $this->take();
            return $this->factor();
        }
        if ($this->isOp('(')) {
            $this->take();
            $node = $this->expr();
            $this->take(')');
            return $node;
        }
        if ($kind === 'num') {
            $this->take();
            return ['num', (float)$val];
        }
        if ($kind === 'name') {
            $this->take();
            $func = strtolower($val);
            if (!in_array($func, self::FUNCS, true)) {
                throw new SwFormulaError("'$val' is not a function -- wrap columns in SUM(), AVG(), MIN(), MAX(), COUNT(), FILLED(), DISTINCT() or LATEST().");
            }
            $this->take('(');
            $column = null;
            if (!$this->isOp(')')) {
                [$ckind, $column] = $this->take();
                if ($ckind !== 'name') {
                    throw new SwFormulaError(strtoupper($val) . '() needs a column name inside the brackets.');
                }
                if ($this->known && !in_array($column, $this->known, true)) {
                    throw new SwFormulaError("No column called '$column' in this category.");
                }
            }
            $this->take(')');
            if ($column === null && $func !== 'count') {
                throw new SwFormulaError(strtoupper($val) . '() needs a column name inside the brackets.');
            }
            return ['agg', $func, $column];
        }
        throw new SwFormulaError($kind === null ? 'The formula ends too early.' : "Unexpected '$val'.");
    }
}

function swFormulaColumns(array $node): array {
    switch ($node[0]) {
        case 'agg': return $node[2] ? [$node[2]] : [];
        case 'neg': return swFormulaColumns($node[1]);
        case 'bin': return array_merge(swFormulaColumns($node[2]), swFormulaColumns($node[3]));
    }
    return [];
}

function swEval(array $node, array $data, array $rows): ?float {
    switch ($node[0]) {
        case 'num':
            return $node[1];
        case 'neg':
            $v = swEval($node[1], $data, $rows);
            return $v === null ? null : -$v;
        case 'agg':
            $v = swAggregate($node[1], $data, $rows, $node[2]);
            if ($v === null) {
                return null;
            }
            $n = swNum($v);
            if ($n === null) {
                throw new SwFormulaError(strtoupper($node[1]) . "({$node[2]}) is text, not a number.");
            }
            return $n;
    }
    $left = swEval($node[2], $data, $rows);
    $right = swEval($node[3], $data, $rows);
    if ($left === null || $right === null) {
        return null;
    }
    switch ($node[1]) {
        case '+': return $left + $right;
        case '-': return $left - $right;
        case '*': return $left * $right;
    }
    return $right == 0 ? null : $left / $right;
}

/** [value, error]. value: float, string (LATEST of a text column) or null. */
function swComputeValue(array $widget, array $data): array {
    try {
        $rows = swFiltered($data, $widget);
        $agg = $widget['agg_func'] ?: 'sum';
        if ($agg === 'formula') {
            $tree = (new SwFormula((string)$widget['formula'], []))->parse();
            foreach (swFormulaColumns($tree) as $column) {
                if (!swHasColumn($data, $column)) {
                    throw new SwFormulaError("No column called '$column' in this category.");
                }
            }
            return [swEval($tree, $data, $rows), null];
        }
        $column = $widget['source_column'] ?? null;
        if ($agg !== 'count' && $column && !swHasColumn($data, $column)) {
            return [null, "The column '$column' no longer exists."];
        }
        return [swAggregate($agg, $data, $rows, $agg === 'count' ? null : $column), null];
    } catch (Throwable $e) {
        return [null, $e->getMessage()];
    }
}

function swCompact(float $v, int $decimals): string {
    $d = $decimals ?: 2;
    $size = abs($v);
    if ($size >= 1e7) return number_format($v / 1e7, $d) . ' Cr';
    if ($size >= 1e5) return number_format($v / 1e5, $d) . ' L';
    if ($size >= 1e3) return number_format($v / 1e3, $d) . ' K';
    return number_format($v, $decimals);
}

function swFormatValue(array $widget, $value): string {
    $prefix = (string)($widget['value_prefix'] ?? '');
    $suffix = (string)($widget['value_suffix'] ?? '');
    if ($value === null) {
        return '—';
    }
    if (is_string($value)) {
        return $prefix . $value . $suffix;
    }
    $decimals = (int)($widget['decimals'] ?? 0);
    $v = (float)$value;
    if (round($v, $decimals) == 0) {
        $v = 0.0; // never show "-0"
    }
    switch ($widget['number_format'] ?: 'currency') {
        case 'currency': $text = 'Rs ' . number_format($v, $decimals); break;
        case 'compact': $text = 'Rs ' . swCompact($v, $decimals); break;
        case 'percent': $text = number_format($v, $decimals) . '%'; break;
        case 'plain': $text = number_format($v, $decimals, '.', ''); break;
        default: $text = number_format($v, $decimals);
    }
    return $prefix . $text . $suffix;
}

function swValueTone(array $widget, $value): string {
    if (!is_int($value) && !is_float($value)) {
        return 'normal';
    }
    $rule = $widget['color_rule'] ?: 'none';
    if (($rule === 'negative' || $rule === 'sign') && $value < 0) {
        return 'negative';
    }
    if ($rule === 'sign' && $value > 0) {
        return 'positive';
    }
    return 'normal';
}

function swAccentFor(array $widget, int $index): string {
    $color = (string)($widget['accent_color'] ?? '');
    return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : SW_AUTO_ACCENTS[$index % count(SW_AUTO_ACCENTS)];
}

/** Every widget of a category, ready to draw. */
function swComputeWidgets($db, int $userId, array $config, bool $activeOnly = true): array {
    $widgets = swListWidgets($db, $userId, $config['table'], $activeOnly);
    if (!$widgets) {
        return [];
    }
    $data = swLoadRows($db, $userId, $config);
    $out = [];
    foreach ($widgets as $i => $widget) {
        [$value, $error] = swComputeValue($widget, $data);
        $out[] = [
            'widget' => $widget,
            'value' => $value,
            'text' => $error ? 'Error' : swFormatValue($widget, $value),
            'tone' => $error ? 'error' : swValueTone($widget, $value),
            'accent' => swAccentFor($widget, $i),
            'error' => $error,
        ];
    }
    return $out;
}

/** One line saying what a widget calculates. */
function swDescribe(array $widget, array $columns): string {
    $agg = $widget['agg_func'] ?: 'sum';
    if ($agg === 'formula') {
        $text = (string)$widget['formula'];
    } elseif ($agg === 'count') {
        $text = 'Count of records';
    } else {
        $col = $widget['source_column'] ?? '';
        $text = (SW_AGGREGATES[$agg] ?? $agg) . ' of ' . ($columns[$col] ?? $col);
    }
    if (!empty($widget['filter_column']) && !empty($widget['filter_op'])) {
        $op = SW_FILTER_OPS[$widget['filter_op']] ?? $widget['filter_op'];
        $val = in_array($widget['filter_op'], SW_NO_VALUE_FILTER_OPS, true) ? '' : ' ' . ($widget['filter_value'] ?? '');
        $text .= '  ·  where ' . ($columns[$widget['filter_column']] ?? $widget['filter_column']) . ' ' . $op . $val;
    }
    return $text;
}

// ---------------------------------------------------------------- rendering

/**
 * The widget strip shown above a category's list. $catKey is the
 * $SAVINGS_CATEGORIES key (for links), $config its entry.
 */
function swRenderStrip($db, int $userId, string $catKey, array $config): string {
    $cards = swComputeWidgets($db, $userId, $config);
    $manageUrl = url('savings-widgets.php?cat=' . urlencode($catKey));
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    if (!$cards) {
        return '<div class="sw-empty">'
            . '<div><strong>No widgets yet.</strong> Show totals, averages, counts or your own formulas above this list.</div>'
            . '<div class="sw-empty-actions">'
            . '<a class="btn btn-ghost btn-sm" href="' . $h($manageUrl . '&action=add') . '"><i class="fas fa-plus"></i> Add widget</a>'
            . '<form method="POST" action="' . $h($manageUrl) . '" style="display:inline;">' . csrf_field()
            . '<input type="hidden" name="op" value="suggested"><input type="hidden" name="back" value="list">'
            . '<button class="btn btn-ghost btn-sm" type="submit"><i class="fas fa-magic"></i> Add suggested</button></form>'
            . '</div></div>';
    }
    $columns = swAvailableColumns($db, $userId, $config);
    $html = '<div class="sw-grid">';
    foreach ($cards as $card) {
        $w = $card['widget'];
        $tip = $card['error'] ? $card['error'] : swDescribe($w, $columns);
        $html .= '<a class="sw-card" style="--sw-accent: ' . $h($card['accent']) . ';" title="' . $h($tip) . '"'
            . ' href="' . $h($manageUrl . '&edit=' . (int)$w['id']) . '">'
            . '<div class="sw-title">' . $h($w['title']) . '</div>'
            . '<div class="sw-value sw-tone-' . $h($card['tone']) . '">' . $h($card['text']) . '</div>'
            . (!empty($w['caption']) ? '<div class="sw-caption">' . $h($w['caption']) . '</div>' : '')
            . '</a>';
    }
    $html .= '<a class="sw-card sw-manage" href="' . $h($manageUrl) . '" title="Add, edit or reorder widgets">'
        . '<i class="fas fa-sliders-h"></i><span>Widgets</span></a>';
    return $html . '</div>';
}
