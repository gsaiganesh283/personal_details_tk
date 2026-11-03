<?php
/**
 * Recursive, self-service "Action Buttons" engine: a user-defined button on
 * any custom_fields module that, for one selected record, opens a popup
 * listing that record's own child records (see action-button-records.php +
 * assets/js/action-buttons.js). Each button's synthesized module key
 * ("action_btn_{id}") doubles as the custom_fields linked_to key for its
 * child records AND as the parent_module for further buttons defined on it
 * -- that symmetry is what makes nesting recursive with no special-casing
 * per depth. Mirrors other-details.php's folder_module()/folder pattern.
 *
 * Usage:
 *   $buttons = getActionButtons('bank_details', $user['id']);
 *   renderActionButtonsInline('bank_details', $record['id'], $user['id']);
 *   purgeActionButtonChildrenForRecord('bank_details', $record['id'], $user['id']); // before deleting the record
 */

require_once __DIR__ . '/custom_fields_helper.php';
require_once __DIR__ . '/fields_engine.php';

// Shared hosting compatibility: same belt-and-suspenders self-creation
// other-details.php already uses for its own tables.
function ensureActionButtonTables() {
    $db = Database::getInstance();
    try {
        $db->query("CREATE TABLE IF NOT EXISTS action_buttons (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            parent_module VARCHAR(50) NOT NULL,
            label VARCHAR(100) NOT NULL,
            icon VARCHAR(50) NOT NULL DEFAULT 'fa-layer-group',
            button_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_parent (user_id, parent_module)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        ensureActionButtonIconColumn();
        ensureActionButtonFilterConfigColumn();

        $db->query("CREATE TABLE IF NOT EXISTS action_button_records (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            button_id INT NOT NULL,
            parent_record_id INT NOT NULL,
            label VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_button_parent (button_id, parent_record_id),
            FOREIGN KEY (button_id) REFERENCES action_buttons(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        // Ignore CREATE failures when DB user lacks schema permissions --
        // same tolerance other-details.php's own table creation has.
    }
}

function actionButtonModule($buttonId) {
    return 'action_btn_' . (int)$buttonId;
}

// Self-heal the filter_config column -- mirrors the desktop app's per-button
// Filters & Dashboard config (app/models/action_buttons.py). Stored as a JSON
// blob so the whole config travels in one column.
function ensureActionButtonFilterConfigColumn() {
    try {
        if (!dbColumnExists('action_buttons', 'filter_config')) {
            Database::getInstance()->getConnection()->exec(
                "ALTER TABLE action_buttons ADD COLUMN filter_config TEXT NULL"
            );
        }
    } catch (Exception $e) {
        // Best-effort (hosting DB user may lack ALTER) -- buttons just behave
        // as if no filters are configured.
    }
}

// The same default shape the desktop app uses (DEFAULT_FILTER_CONFIG). A
// generic module has no inherent date/amount/type, so each built-in control is
// wired to a custom field chosen in "map".
function actionButtonFilterDefaults() {
    return [
        'enabled' => false,
        'text_search' => true,
        'filter_fields' => [],
        'dashboard' => [],
        'builtin' => ['type' => false, 'month' => false, 'dates' => false, 'amount' => false, 'group' => false],
        'map' => ['date_field' => null, 'amount_field' => null, 'type_field' => null, 'dir_field' => null, 'out_value' => ''],
        'builtin_dashboard' => ['count' => false, 'in' => false, 'out' => false, 'net' => false],
        'presets' => [],
    ];
}

// First field matching each role's natural type -- a form-only default offered
// before the first save (date->Date, number/currency->Amount, select->Type,
// toggle/checkbox->Direction). Mirrors the desktop dialog's _auto_map().
function actionButtonAutoMap($fields) {
    $picks = ['date_field' => null, 'amount_field' => null, 'type_field' => null, 'dir_field' => null];
    foreach ($fields as $f) {
        $ft = $f['field_type'];
        $id = (int)$f['id'];
        if ($picks['date_field'] === null && in_array($ft, ['date', 'datetime'], true)) $picks['date_field'] = $id;
        if ($picks['amount_field'] === null && in_array($ft, ['number', 'currency'], true)) $picks['amount_field'] = $id;
        if ($picks['type_field'] === null && $ft === 'select') $picks['type_field'] = $id;
        if ($picks['dir_field'] === null && in_array($ft, ['toggle', 'checkbox'], true)) $picks['dir_field'] = $id;
    }
    return $picks;
}

function getActionButtonFilterConfig($buttonId, $userId) {
    ensureActionButtonFilterConfigColumn();
    $db = Database::getInstance();
    $row = $db->fetch("SELECT filter_config FROM action_buttons WHERE id = ? AND user_id = ?", [$buttonId, $userId]);
    $cfg = actionButtonFilterDefaults();
    if ($row && !empty($row['filter_config'])) {
        $stored = json_decode($row['filter_config'], true);
        if (is_array($stored)) {
            foreach ($stored as $k => $v) {
                // Merge (don't replace) the nested dicts so a config stored
                // before a sub-key existed still gets the new default.
                if (in_array($k, ['builtin', 'map', 'builtin_dashboard'], true) && is_array($v)) {
                    $cfg[$k] = array_merge($cfg[$k], $v);
                } else {
                    $cfg[$k] = $v;
                }
            }
        }
    }
    return $cfg;
}

function setActionButtonFilterConfig($buttonId, $userId, $cfg) {
    ensureActionButtonFilterConfigColumn();
    $db = Database::getInstance();
    $db->update(
        "UPDATE action_buttons SET filter_config = ? WHERE id = ? AND user_id = ?",
        [json_encode($cfg), $buttonId, $userId]
    );
}

// Belt-and-suspenders self-heal for installs where action_buttons already
// existed before the icon column was added -- CREATE TABLE IF NOT EXISTS
// above only takes effect for brand-new tables, mirroring
// ensureCustomFieldShowInListColumn()'s pattern for custom_fields.show_in_list.
function ensureActionButtonIconColumn() {
    try {
        if (!dbColumnExists('action_buttons', 'icon')) {
            Database::getInstance()->getConnection()->exec(
                "ALTER TABLE action_buttons ADD COLUMN icon VARCHAR(50) NOT NULL DEFAULT 'fa-layer-group'"
            );
        }
    } catch (Exception $e) {
        // Best-effort (e.g. hosting DB user lacks ALTER TABLE) -- buttons
        // just keep rendering the default icon.
    }
}

// Curated FontAwesome (Free, solid) icon choices offered by the Add Action
// Button picker -- kept as an allowlist so a request can't smuggle an
// arbitrary class string into the rendered <i class="fas ..."> markup.
define('ACTION_BUTTON_ICONS', [
    'fa-layer-group', 'fa-folder', 'fa-folder-open', 'fa-file-alt', 'fa-file-invoice',
    'fa-file-invoice-dollar', 'fa-clipboard-list', 'fa-list-alt', 'fa-tasks', 'fa-briefcase',
    'fa-box', 'fa-boxes', 'fa-archive', 'fa-book', 'fa-bookmark',
    'fa-tag', 'fa-tags', 'fa-star', 'fa-heart', 'fa-flag',
    'fa-bell', 'fa-calendar-alt', 'fa-clock', 'fa-money-bill-wave', 'fa-wallet',
    'fa-credit-card', 'fa-piggy-bank', 'fa-chart-line', 'fa-chart-pie', 'fa-receipt',
    'fa-university', 'fa-landmark', 'fa-building', 'fa-home', 'fa-car',
    'fa-shopping-cart', 'fa-gift', 'fa-images', 'fa-paperclip', 'fa-shield-alt',
    'fa-id-card', 'fa-address-card', 'fa-users', 'fa-phone', 'fa-envelope',
    'fa-map-marker-alt', 'fa-globe', 'fa-cog', 'fa-tools', 'fa-puzzle-piece',
    'fa-sitemap', 'fa-database', 'fa-cloud', 'fa-graduation-cap', 'fa-gem',
    'fa-coins', 'fa-hand-holding-usd', 'fa-balance-scale', 'fa-passport', 'fa-key',
]);

function sanitizeActionButtonIcon($icon) {
    return in_array($icon, ACTION_BUTTON_ICONS, true) ? $icon : 'fa-layer-group';
}

function getActionButtons($module, $userId) {
    ensureActionButtonTables();
    $db = Database::getInstance();
    return $db->fetchAll(
        "SELECT * FROM action_buttons WHERE user_id = ? AND parent_module = ? ORDER BY button_order ASC, id ASC",
        [$userId, $module]
    );
}

function getActionButton($buttonId, $userId) {
    ensureActionButtonTables();
    $db = Database::getInstance();
    return $db->fetch("SELECT * FROM action_buttons WHERE id = ? AND user_id = ?", [$buttonId, $userId]);
}

function createActionButton($module, $label, $userId, $icon = 'fa-layer-group') {
    ensureActionButtonTables();
    if (!feModuleExists($module, $userId)) {
        return false; // the folder/button it would sit on is gone
    }
    $db = Database::getInstance();
    $icon = sanitizeActionButtonIcon($icon);
    $maxOrder = $db->fetch("SELECT MAX(button_order) as mo FROM action_buttons WHERE user_id = ? AND parent_module = ?", [$userId, $module]);
    return feTransaction(function () use ($db, $userId, $module, $label, $icon, $maxOrder) {
        $newId = $db->insert(
            "INSERT INTO action_buttons (user_id, parent_module, label, icon, button_order) VALUES (?, ?, ?, ?, ?)",
            [$userId, $module, $label, $icon, ($maxOrder['mo'] ?? 0) + 1]
        );
        // Stale fields of an older button with this same id must not attach to the new one.
        feQuarantineModule(actionButtonModule($newId), $userId);
        return $newId;
    });
}

function renameActionButton($buttonId, $label, $userId) {
    $db = Database::getInstance();
    $db->update("UPDATE action_buttons SET label = ? WHERE id = ? AND user_id = ?", [$label, $buttonId, $userId]);
}

function moveActionButton($buttonId, $direction, $userId) {
    $db = Database::getInstance();
    $button = getActionButton($buttonId, $userId);
    if (!$button) return;
    $siblings = getActionButtons($button['parent_module'], $userId);
    $ids = array_column($siblings, 'id');
    $idx = array_search((int)$buttonId, $ids, true);
    if ($idx === false) return;
    $newIdx = $idx + $direction;
    if ($newIdx < 0 || $newIdx >= count($siblings)) return;
    $a = $siblings[$idx];
    $b = $siblings[$newIdx];
    $db->update("UPDATE action_buttons SET button_order = ? WHERE id = ? AND user_id = ?", [$b['button_order'], $a['id'], $userId]);
    $db->update("UPDATE action_buttons SET button_order = ? WHERE id = ? AND user_id = ?", [$a['button_order'], $b['id'], $userId]);
}

/**
 * Deletes every Action Button defined on $module, recursively -- for
 * retiring a whole logical module outright (e.g. a deleted Other Details
 * folder), as opposed to purgeActionButtonChildrenForRecord() below, which
 * only removes one record's own children within a module that keeps
 * existing.
 */
function deleteAllActionButtonsForModule($module, $userId) {
    foreach (getActionButtons($module, $userId) as $button) {
        feDeleteButton($button['id'], $userId);
    }
}

/**
 * Deletes the button with everything that hangs off it -- its records (and
 * their values and nested children), the fields/blocks defined on it, and
 * every button nested under it -- in one transaction (fields_engine.php).
 */
function deleteActionButton($buttonId, $userId) {
    return feDeleteButton($buttonId, $userId);
}

/** Moves a button (with everything on it) to another module; false if not allowed. */
function attachActionButton($buttonId, $targetModule, $userId) {
    $db = Database::getInstance();
    $button = getActionButton($buttonId, $userId);
    if (!$button || !feModuleExists($targetModule, $userId)) return false;
    // Not onto itself or anything nested inside it.
    $blocked = [actionButtonModule($buttonId)];
    for ($i = 0; $i < count($blocked); $i++) {
        foreach (getActionButtons($blocked[$i], $userId) as $child) {
            $blocked[] = actionButtonModule($child['id']);
        }
    }
    if (in_array($targetModule, $blocked, true)) return false;
    if ($targetModule === $button['parent_module']) return true;
    $maxOrder = $db->fetch("SELECT MAX(button_order) AS mo FROM action_buttons WHERE user_id = ? AND parent_module = ?", [$userId, $targetModule]);
    $db->update(
        "UPDATE action_buttons SET parent_module = ?, button_order = ? WHERE id = ? AND user_id = ?",
        [$targetModule, ($maxOrder['mo'] ?? 0) + 1, $buttonId, $userId]
    );
    return true;
}

function getActionButtonRecords($buttonId, $parentRecordId, $userId) {
    ensureActionButtonTables();
    $db = Database::getInstance();
    return $db->fetchAll(
        "SELECT * FROM action_button_records WHERE button_id = ? AND parent_record_id = ? AND user_id = ? ORDER BY label ASC",
        [$buttonId, $parentRecordId, $userId]
    );
}

function getActionButtonRecord($recordId, $userId) {
    $db = Database::getInstance();
    return $db->fetch("SELECT * FROM action_button_records WHERE id = ? AND user_id = ?", [$recordId, $userId]);
}

function saveActionButtonRecord($buttonId, $parentRecordId, $label, $userId, $recordId = null) {
    $db = Database::getInstance();
    if ($recordId) {
        // Scoped to this button: a stale form can't relabel another button's record.
        $db->update(
            "UPDATE action_button_records SET label = ? WHERE id = ? AND user_id = ? AND button_id = ?",
            [$label, $recordId, $userId, $buttonId]
        );
        return (int)$recordId;
    }
    return $db->insert(
        "INSERT INTO action_button_records (user_id, button_id, parent_record_id, label) VALUES (?, ?, ?, ?)",
        [$userId, $buttonId, $parentRecordId, $label]
    );
}

/**
 * Call when ($module, $recordId) itself is being deleted: recursively
 * removes every Action-Button child record hanging off that specific
 * record -- across every button defined on $module -- along with each
 * child's own custom-field files/entries and its own nested children.
 * Depth-first (grandchildren before children) so nothing is orphaned.
 * Does NOT touch button *definitions* or sibling records under the same
 * button -- those persist independently of any one parent record.
 */
function purgeActionButtonChildrenForRecord($module, $recordId, $userId) {
    // Children depth-first, plus the record's own values (fields_engine.php).
    feTransaction(function () use ($module, $recordId, $userId) {
        feDeleteRecordChildren($module, $recordId, $userId);
    });
}

/**
 * Echoes one small icon button per Action Button defined on $module -- the
 * one-liner every existing page's action-icons block calls, next to its
 * existing Edit/View/Delete buttons. Picked up by one delegated click
 * listener registered once in assets/js/action-buttons.js -- no
 * page-specific JS needed anywhere this is called from.
 */
function renderActionButtonsInline($module, $recordId, $userId) {
    foreach (getActionButtons($module, $userId) as $button) {
        ?>
        <button type="button" class="btn btn-icon btn-ghost" data-action-btn="1"
                data-button-id="<?php echo (int)$button['id']; ?>"
                data-record-id="<?php echo (int)$recordId; ?>"
                data-label="<?php echo htmlspecialchars($button['label'], ENT_QUOTES, 'UTF-8'); ?>"
                title="<?php echo htmlspecialchars($button['label'], ENT_QUOTES, 'UTF-8'); ?>">
            <i class="fas <?php echo htmlspecialchars($button['icon'] ?: 'fa-layer-group', ENT_QUOTES, 'UTF-8'); ?>"></i>
        </button>
        <?php
    }
}
