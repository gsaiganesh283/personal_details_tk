<?php
/**
 * Fields engine: the single place that creates, saves and deletes everything
 * in the user-defined data system --
 *
 *   other_detail_folders / other_detail_records   (Other Details folders)
 *   action_buttons / action_button_records        (Action Buttons, nestable)
 *   custom_field_blocks / custom_fields           (field definitions)
 *   custom_field_entries                          (saved values)
 *
 * These tables point at each other through plain strings and ids with no
 * database foreign keys ("other_folder_18", "action_btn_5", block_id,
 * record_id ...), so every delete has to cascade by hand. Doing that ad hoc
 * on each page is what left orphaned fields/buttons/values behind -- every
 * page now calls these functions instead.
 *
 * Every row also carries a permanent `uid` (32 hex chars) shared with the
 * desktop app's copy of the same row -- see includes/fields_sync.php. The
 * numeric ids and "action_btn_<id>" style keys stay exactly as they were, so
 * every page and the mobile apps keep working unchanged.
 */

require_once __DIR__ . '/../config/database.php';

const FE_TABLES = [
    'other_detail_folders', 'other_detail_records',
    'action_buttons', 'action_button_records',
    'custom_field_blocks', 'custom_fields', 'custom_field_entries',
];

const FE_FILE_TYPES = ['file', 'image', 'pdf', 'document', 'spreadsheet'];

// ------------------------------------------------------------------ schema

/**
 * Idempotent self-heal of everything this engine relies on. Cheap after the
 * first run (one information_schema query per request, cached in a static).
 */
function feEnsureSchema() {
    static $done = false;
    if ($done) return;
    $done = true;
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    // action_buttons / action_button_records are otherwise only created lazily
    // by action_buttons_helper.php on first page use -- the sync API may well
    // run before that on a fresh install. Same DDL as that helper.
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS action_buttons (
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
        $pdo->exec("CREATE TABLE IF NOT EXISTS action_button_records (
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
        // no CREATE rights -- the tables exist already on any install that used buttons
    }

    try {
        $have = [];
        foreach ($db->fetchAll(
            "SELECT table_name AS t, column_name AS c FROM information_schema.columns
             WHERE table_schema = ? AND table_name IN ('" . implode("','", FE_TABLES) . "')",
            [DB_NAME]
        ) as $row) {
            $have[strtolower($row['t'])][strtolower($row['c'])] = true;
        }
    } catch (Exception $e) {
        return; // no information_schema access -- nothing more we can safely do
    }

    $add = function ($table, $column, $definition) use ($pdo, $have) {
        if (!isset($have[$table]) || isset($have[$table][$column])) return;
        try {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        } catch (Exception $e) {
            // best effort -- hosting user may lack ALTER; features degrade gracefully
        }
    };
    foreach (FE_TABLES as $table) {
        $add($table, 'uid', 'CHAR(32) NULL DEFAULT NULL');
    }
    $add('custom_fields', 'show_in_list', 'TINYINT(1) NOT NULL DEFAULT 0');
    $add('action_buttons', 'icon', "VARCHAR(50) NOT NULL DEFAULT 'fa-layer-group'");

    foreach (FE_TABLES as $table) {
        if (!isset($have[$table])) continue;
        try {
            $idx = $db->fetch(
                "SELECT COUNT(*) AS n FROM information_schema.statistics
                 WHERE table_schema = ? AND table_name = ? AND index_name = 'uq_fe_uid'",
                [DB_NAME, $table]
            );
            if ((int)($idx['n'] ?? 0) === 0) {
                $pdo->exec("ALTER TABLE `$table` ADD UNIQUE KEY `uq_fe_uid` (`user_id`, `uid`)");
            }
        } catch (Exception $e) {
            // e.g. duplicate uids from a manual copy -- feAssignUids() repairs them below
        }
    }
}

/** True once every engine table has its uid column (false if ALTER was refused). */
function feHasUids() {
    static $result = null;
    if ($result !== null) return $result;
    feEnsureSchema();
    try {
        $row = Database::getInstance()->fetch(
            "SELECT COUNT(*) AS n FROM information_schema.columns
             WHERE table_schema = ? AND column_name = 'uid' AND table_name IN ('" . implode("','", FE_TABLES) . "')",
            [DB_NAME]
        );
        $result = (int)($row['n'] ?? 0) === count(FE_TABLES);
    } catch (Exception $e) {
        $result = false;
    }
    return $result;
}

/**
 * Gives every row of this user that doesn't have a uid yet (created by a
 * page, the mobile app, or before this engine existed) a fresh one.
 * `updated_at = updated_at` keeps the row's real last-changed time --
 * otherwise MySQL's ON UPDATE would make every row look freshly edited.
 */
function feAssignUids($userId) {
    feEnsureSchema();
    $db = Database::getInstance();
    foreach (FE_TABLES as $table) {
        try {
            $db->update(
                "UPDATE `$table` SET uid = REPLACE(UUID(), '-', ''), updated_at = updated_at
                 WHERE user_id = ? AND (uid IS NULL OR uid = '')",
                [$userId]
            );
            // Two rows sharing one uid (only possible from a manual table copy)
            // would be indistinguishable to sync -- re-key all but the oldest.
            $dupes = $db->fetchAll(
                "SELECT t.id FROM `$table` t
                 JOIN (SELECT uid, MIN(id) AS keep_id FROM `$table` WHERE user_id = ? GROUP BY uid HAVING COUNT(*) > 1) d
                   ON d.uid = t.uid AND t.id <> d.keep_id
                 WHERE t.user_id = ?",
                [$userId, $userId]
            );
            foreach ($dupes as $d) {
                $db->update(
                    "UPDATE `$table` SET uid = REPLACE(UUID(), '-', ''), updated_at = updated_at WHERE id = ?",
                    [$d['id']]
                );
            }
        } catch (Exception $e) {
            // column missing (no ALTER rights) -- sync reports it, pages keep working
        }
    }
}

// ------------------------------------------------------------ module keys

/**
 * "other_folder_18" -> ['folder', 18], "action_btn_5" -> ['button', 5],
 * "orphan_..." -> ['orphan', key] (quarantined, see feQuarantineModule),
 * else ['core', key].
 */
function feParseModule($module) {
    if (preg_match('/^other_folder_(\d+)$/', (string)$module, $m)) return ['folder', (int)$m[1]];
    if (preg_match('/^action_btn_(\d+)$/', (string)$module, $m)) return ['button', (int)$m[1]];
    if (strpos((string)$module, 'orphan_') === 0) return ['orphan', (string)$module];
    return ['core', (string)$module];
}

/**
 * Call right after creating a folder or button: anything still pointing at
 * its brand-new module key ("action_btn_7") was left behind by an older
 * folder/button with that same id, and would otherwise silently attach to
 * the new one. It's renamed to "orphan_action_btn_7" instead -- kept, never
 * deleted, and listed on the Mapping page for you to move or delete.
 */
function feQuarantineModule($module, $userId) {
    $db = Database::getInstance();
    $quarantined = 'orphan_' . $module;
    $n = 0;
    foreach (['custom_fields', 'custom_field_blocks', 'custom_field_entries'] as $table) {
        $n += $db->update("UPDATE `$table` SET linked_to = ? WHERE linked_to = ? AND user_id = ?", [$quarantined, $module, $userId]);
    }
    $n += $db->update("UPDATE action_buttons SET parent_module = ? WHERE parent_module = ? AND user_id = ?", [$quarantined, $module, $userId]);
    return $n;
}

function feFolderModule($folderId) { return 'other_folder_' . (int)$folderId; }
function feButtonModule($buttonId) { return 'action_btn_' . (int)$buttonId; }

/** Table holding the records of a module (where custom_field_entries.record_id points). */
function feRecordTable($module) {
    [$kind, $key] = feParseModule($module);
    if ($kind === 'folder') return 'other_detail_records';
    if ($kind === 'button') return 'action_button_records';
    if ($kind === 'orphan') return null;
    return preg_match('/^[a-z_]+$/', $key) ? $key : null;
}

/** True if the folder/button a module key names still exists (core modules always do). */
function feModuleExists($module, $userId) {
    [$kind, $id] = feParseModule($module);
    $db = Database::getInstance();
    if ($kind === 'folder') {
        return (bool)$db->fetch("SELECT id FROM other_detail_folders WHERE id = ? AND user_id = ?", [$id, $userId]);
    }
    if ($kind === 'button') {
        return (bool)$db->fetch("SELECT id FROM action_buttons WHERE id = ? AND user_id = ?", [$id, $userId]);
    }
    return $kind === 'core' && $id !== '';
}

// --------------------------------------------------------------- transactions

/** Runs $fn inside one transaction (nested calls join the outer one). */
function feTransaction(callable $fn) {
    $pdo = Database::getInstance()->getConnection();
    if ($pdo->inTransaction()) {
        return $fn();
    }
    $pdo->beginTransaction();
    try {
        $result = $fn();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ------------------------------------------------------------------ files

function feIsFileType($fieldType) {
    return in_array($fieldType, FE_FILE_TYPES, true);
}

/**
 * Deletes stored files (Drive or local uploads/) for the given values, but
 * only those no other entry still references -- a value can be shared (e.g.
 * a repeatable block re-saved with its existing file kept), and deleting a
 * file something still points at is exactly the data loss to avoid.
 * Call AFTER the referencing rows were removed/changed.
 */
function feDeleteUnreferencedFiles(array $values) {
    $values = array_unique(array_filter(array_map('strval', $values), 'strlen'));
    if (!$values) return;
    $db = Database::getInstance();
    foreach ($values as $value) {
        if (strpos($value, 'gdrive:') !== 0 && strpos($value, 'uploads/') !== 0) continue;
        try {
            $still = $db->fetch("SELECT id FROM custom_field_entries WHERE field_value = ? LIMIT 1", [$value]);
        } catch (Exception $e) {
            continue; // can't prove it's unused -- keep the file
        }
        if ($still) continue;
        if (function_exists('deleteCustomFieldFileByValue')) {
            try { deleteCustomFieldFileByValue($value); } catch (Throwable $e) {}
        }
    }
}

/** File-type values among a set of entries rows (each needs field_value + field_type). */
function feFileValues(array $rows) {
    $out = [];
    foreach ($rows as $r) {
        if (!empty($r['field_value']) && feIsFileType($r['field_type'] ?? '')) {
            $out[] = $r['field_value'];
        }
    }
    return $out;
}

function feDeleteReminderNotifications($where, array $params) {
    try {
        Database::getInstance()->delete("DELETE FROM scheduled_notifications WHERE $where AND custom_field_id IS NOT NULL", $params);
    } catch (Exception $e) {
        // table doesn't exist on this install -- nothing to clean up
    }
}

// --------------------------------------------------------------- deletes

/** Deletes one record's saved values (and their now-unused files). */
function feDeleteRecordEntries($module, $recordId, $userId) {
    $db = Database::getInstance();
    $rows = $db->fetchAll(
        "SELECT e.field_value, f.field_type FROM custom_field_entries e
         LEFT JOIN custom_fields f ON f.id = e.custom_field_id
         WHERE e.linked_to = ? AND e.record_id = ? AND e.user_id = ?",
        [$module, $recordId, $userId]
    );
    $db->delete("DELETE FROM custom_field_entries WHERE linked_to = ? AND record_id = ? AND user_id = ?", [$module, $recordId, $userId]);
    feDeleteReminderNotifications('linked_to = ? AND record_id = ?', [$module, $recordId]);
    feDeleteUnreferencedFiles(feFileValues($rows));
}

/**
 * Call when ($module, $recordId) is being deleted: removes, depth-first, every
 * Action Button child record hanging off it (with their own values and
 * children), then the record's own values. Does not delete the record row
 * itself -- that's the caller's own table.
 */
function feDeleteRecordChildren($module, $recordId, $userId) {
    $db = Database::getInstance();
    foreach ($db->fetchAll("SELECT id FROM action_buttons WHERE parent_module = ? AND user_id = ?", [$module, $userId]) as $button) {
        $childModule = feButtonModule($button['id']);
        $children = $db->fetchAll(
            "SELECT id FROM action_button_records WHERE button_id = ? AND parent_record_id = ? AND user_id = ?",
            [$button['id'], $recordId, $userId]
        );
        foreach ($children as $child) {
            feDeleteRecordChildren($childModule, $child['id'], $userId);
            $db->delete("DELETE FROM action_button_records WHERE id = ? AND user_id = ?", [$child['id'], $userId]);
        }
    }
    feDeleteRecordEntries($module, $recordId, $userId);
}

/**
 * Removes everything defined on a module: its buttons (recursively, with
 * their records), its fields, blocks and every saved value. Used when a
 * folder or button -- whose module this is -- goes away.
 */
function feDeleteModuleContents($module, $userId) {
    $db = Database::getInstance();
    foreach ($db->fetchAll("SELECT id FROM action_buttons WHERE parent_module = ? AND user_id = ?", [$module, $userId]) as $button) {
        feDeleteButton($button['id'], $userId);
    }
    $rows = $db->fetchAll(
        "SELECT e.field_value, f.field_type FROM custom_field_entries e
         LEFT JOIN custom_fields f ON f.id = e.custom_field_id
         WHERE e.linked_to = ? AND e.user_id = ?",
        [$module, $userId]
    );
    $db->delete("DELETE FROM custom_field_entries WHERE linked_to = ? AND user_id = ?", [$module, $userId]);
    feDeleteReminderNotifications('linked_to = ?', [$module]);
    $db->delete("DELETE FROM custom_fields WHERE linked_to = ? AND user_id = ?", [$module, $userId]);
    $db->delete("DELETE FROM custom_field_blocks WHERE linked_to = ? AND user_id = ?", [$module, $userId]);
    feDeleteUnreferencedFiles(feFileValues($rows));
}

function feDeleteButton($buttonId, $userId) {
    return feTransaction(function () use ($buttonId, $userId) {
        $db = Database::getInstance();
        if (!$db->fetch("SELECT id FROM action_buttons WHERE id = ? AND user_id = ?", [$buttonId, $userId])) {
            return false;
        }
        $childModule = feButtonModule($buttonId);
        foreach ($db->fetchAll("SELECT id FROM action_button_records WHERE button_id = ? AND user_id = ?", [$buttonId, $userId]) as $rec) {
            feDeleteRecordChildren($childModule, $rec['id'], $userId);
        }
        $db->delete("DELETE FROM action_button_records WHERE button_id = ? AND user_id = ?", [$buttonId, $userId]);
        feDeleteModuleContents($childModule, $userId);
        $db->delete("DELETE FROM action_buttons WHERE id = ? AND user_id = ?", [$buttonId, $userId]);
        return true;
    });
}

function feDeleteButtonRecord($recordId, $userId) {
    return feTransaction(function () use ($recordId, $userId) {
        $db = Database::getInstance();
        $rec = $db->fetch("SELECT id, button_id FROM action_button_records WHERE id = ? AND user_id = ?", [$recordId, $userId]);
        if (!$rec) return false;
        feDeleteRecordChildren(feButtonModule($rec['button_id']), $recordId, $userId);
        $db->delete("DELETE FROM action_button_records WHERE id = ? AND user_id = ?", [$recordId, $userId]);
        return true;
    });
}

function feDeleteFolder($folderId, $userId) {
    return feTransaction(function () use ($folderId, $userId) {
        $db = Database::getInstance();
        if (!$db->fetch("SELECT id FROM other_detail_folders WHERE id = ? AND user_id = ?", [$folderId, $userId])) {
            return false;
        }
        $module = feFolderModule($folderId);
        foreach ($db->fetchAll("SELECT id FROM other_detail_records WHERE folder_id = ? AND user_id = ?", [$folderId, $userId]) as $rec) {
            feDeleteRecordChildren($module, $rec['id'], $userId);
        }
        $db->delete("DELETE FROM other_detail_records WHERE folder_id = ? AND user_id = ?", [$folderId, $userId]);
        feDeleteModuleContents($module, $userId);
        $db->delete("DELETE FROM other_detail_folders WHERE id = ? AND user_id = ?", [$folderId, $userId]);
        return true;
    });
}

function feDeleteFolderRecord($recordId, $userId) {
    return feTransaction(function () use ($recordId, $userId) {
        $db = Database::getInstance();
        $rec = $db->fetch("SELECT id, folder_id FROM other_detail_records WHERE id = ? AND user_id = ?", [$recordId, $userId]);
        if (!$rec) return false;
        feDeleteRecordChildren(feFolderModule($rec['folder_id']), $recordId, $userId);
        $db->delete("DELETE FROM other_detail_records WHERE id = ? AND user_id = ?", [$recordId, $userId]);
        return true;
    });
}

function feDeleteField($fieldId, $userId) {
    return feTransaction(function () use ($fieldId, $userId) {
        $db = Database::getInstance();
        $field = $db->fetch("SELECT id, field_type FROM custom_fields WHERE id = ? AND user_id = ?", [$fieldId, $userId]);
        if (!$field) return false;
        $values = feIsFileType($field['field_type'])
            ? array_column($db->fetchAll("SELECT field_value FROM custom_field_entries WHERE custom_field_id = ? AND user_id = ?", [$fieldId, $userId]), 'field_value')
            : [];
        $db->delete("DELETE FROM custom_field_entries WHERE custom_field_id = ? AND user_id = ?", [$fieldId, $userId]);
        feDeleteReminderNotifications('custom_field_id = ?', [$fieldId]);
        $db->delete("DELETE FROM custom_fields WHERE id = ? AND user_id = ?", [$fieldId, $userId]);
        feDeleteUnreferencedFiles($values);
        return true;
    });
}

/** Deletes a block; its fields stay, just no longer grouped. */
function feDeleteBlock($blockId, $userId) {
    return feTransaction(function () use ($blockId, $userId) {
        $db = Database::getInstance();
        if (!$db->fetch("SELECT id FROM custom_field_blocks WHERE id = ? AND user_id = ?", [$blockId, $userId])) {
            return false;
        }
        $db->update("UPDATE custom_fields SET block_id = NULL, field_order_in_block = 0 WHERE block_id = ? AND user_id = ?", [$blockId, $userId]);
        $db->delete("DELETE FROM custom_field_blocks WHERE id = ? AND user_id = ?", [$blockId, $userId]);
        return true;
    });
}

// ----------------------------------------------------------------- entries

/**
 * Writes one record's values from a {field_id: {instance: value}} map,
 * changing rows in place: unchanged values are left alone, changed ones are
 * updated (same row, same uid), new ones inserted, and only the (field,
 * instance) pairs that are really gone get deleted. Fields not present in
 * $values at all are left untouched. Files no longer referenced by anything
 * are cleaned up afterwards.
 *
 * The previous code deleted every value of a repeatable block and
 * re-inserted it on each save -- deleting the stored file first, even when
 * the same file was being kept, which left records pointing at deleted files.
 */
function feWriteEntries($userId, $module, $recordId, array $values, array $fieldsById) {
    return feTransaction(function () use ($userId, $module, $recordId, $values, $fieldsById) {
        $db = Database::getInstance();
        if (!$values) return;
        $fieldIds = array_map('intval', array_keys($values));
        $placeholders = implode(',', array_fill(0, count($fieldIds), '?'));
        $existing = [];
        foreach ($db->fetchAll(
            "SELECT id, custom_field_id, block_instance, field_value FROM custom_field_entries
             WHERE user_id = ? AND linked_to = ? AND record_id = ? AND custom_field_id IN ($placeholders)",
            array_merge([$userId, $module, $recordId], $fieldIds)
        ) as $row) {
            $existing[(int)$row['custom_field_id'] . ':' . (int)$row['block_instance']] = $row;
        }

        $replacedFiles = [];
        foreach ($values as $fieldId => $instances) {
            $fieldId = (int)$fieldId;
            $isFile = feIsFileType($fieldsById[$fieldId]['field_type'] ?? '');
            foreach ($instances as $instance => $value) {
                $instance = (int)$instance;
                $value = $value === null ? '' : (string)$value;
                $key = "$fieldId:$instance";
                $old = $existing[$key] ?? null;
                unset($existing[$key]);
                if ($value === '') {
                    if ($old) {
                        $db->delete("DELETE FROM custom_field_entries WHERE id = ?", [$old['id']]);
                        if ($isFile) $replacedFiles[] = $old['field_value'];
                    }
                    continue;
                }
                if ($old) {
                    if ((string)$old['field_value'] !== $value) {
                        $db->update("UPDATE custom_field_entries SET field_value = ? WHERE id = ?", [$value, $old['id']]);
                        if ($isFile) $replacedFiles[] = $old['field_value'];
                    }
                } else {
                    // ON DUPLICATE KEY: a concurrent save (or the desktop sync)
                    // may have written the same (field, record, instance) a
                    // moment ago -- update it instead of failing the save.
                    $uidCol = feHasUids() ? ', uid' : '';
                    $uidVal = feHasUids() ? ", REPLACE(UUID(), '-', '')" : '';
                    $db->insert(
                        "INSERT INTO custom_field_entries (user_id, custom_field_id, linked_to, record_id, block_instance, field_value$uidCol)
                         VALUES (?, ?, ?, ?, ?, ?$uidVal)
                         ON DUPLICATE KEY UPDATE field_value = VALUES(field_value)",
                        [$userId, $fieldId, $module, $recordId, $instance, $value]
                    );
                }
            }
        }
        // Instances present before but missing from this save (a removed
        // repeatable-block row) -- only for fields this save covered.
        foreach ($existing as $old) {
            $db->delete("DELETE FROM custom_field_entries WHERE id = ?", [$old['id']]);
            $fid = (int)$old['custom_field_id'];
            if (feIsFileType($fieldsById[$fid]['field_type'] ?? '')) $replacedFiles[] = $old['field_value'];
        }
        feDeleteUnreferencedFiles($replacedFiles);
    });
}

// --------------------------------------------------------- leftover values

/**
 * Saved values nothing can show any more, grouped as
 * [linked_to => ['field_gone' => n, 'record_gone' => n]]: their field was
 * deleted, or the record they belong to was. Only modules whose record
 * table is known are checked for missing records.
 */
function feFindLeftoverValues($userId) {
    $db = Database::getInstance();
    $out = [];
    foreach ($db->fetchAll(
        "SELECT e.linked_to, COUNT(*) AS n FROM custom_field_entries e
         LEFT JOIN custom_fields f ON f.id = e.custom_field_id AND f.user_id = e.user_id
         WHERE e.user_id = ? AND f.id IS NULL GROUP BY e.linked_to",
        [$userId]
    ) as $r) {
        $out[$r['linked_to']]['field_gone'] = (int)$r['n'];
    }
    foreach ($db->fetchAll("SELECT DISTINCT linked_to FROM custom_field_entries WHERE user_id = ?", [$userId]) as $r) {
        $module = $r['linked_to'];
        $table = feRecordTable($module);
        if (!$table || !feTableExists($table)) continue;
        [$kind, $key] = feParseModule($module);
        $join = "LEFT JOIN `$table` t ON t.id = e.record_id";
        $params = [];
        // A folder/button record only counts if it belongs to that folder/button.
        if ($kind === 'folder') { $join .= ' AND t.folder_id = ?'; $params[] = $key; }
        if ($kind === 'button') { $join .= ' AND t.button_id = ?'; $params[] = $key; }
        $row = $db->fetch(
            "SELECT COUNT(*) AS n FROM custom_field_entries e $join
             WHERE e.user_id = ? AND e.linked_to = ? AND t.id IS NULL",
            array_merge($params, [$userId, $module])
        );
        if ((int)($row['n'] ?? 0) > 0) {
            $out[$module]['record_gone'] = (int)$row['n'];
        }
    }
    ksort($out);
    return $out;
}

/** Deletes the leftover values feFindLeftoverValues() reports for one module. */
function feDeleteLeftoverValues($userId, $module) {
    return feTransaction(function () use ($userId, $module) {
        $db = Database::getInstance();
        $ids = array_column($db->fetchAll(
            "SELECT e.id FROM custom_field_entries e
             LEFT JOIN custom_fields f ON f.id = e.custom_field_id AND f.user_id = e.user_id
             WHERE e.user_id = ? AND e.linked_to = ? AND f.id IS NULL",
            [$userId, $module]
        ), 'id');
        $table = feRecordTable($module);
        if ($table && feTableExists($table)) {
            [$kind, $key] = feParseModule($module);
            $join = "LEFT JOIN `$table` t ON t.id = e.record_id";
            $params = [];
            if ($kind === 'folder') { $join .= ' AND t.folder_id = ?'; $params[] = $key; }
            if ($kind === 'button') { $join .= ' AND t.button_id = ?'; $params[] = $key; }
            $ids = array_merge($ids, array_column($db->fetchAll(
                "SELECT e.id FROM custom_field_entries e $join WHERE e.user_id = ? AND e.linked_to = ? AND t.id IS NULL",
                array_merge($params, [$userId, $module])
            ), 'id'));
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        foreach (array_chunk($ids, 200) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $db->delete("DELETE FROM custom_field_entries WHERE user_id = ? AND id IN ($marks)", array_merge([$userId], $chunk));
        }
        return count($ids);
    });
}

function feTableExists($table) {
    static $cache = [];
    if (!preg_match('/^[a-z0-9_]+$/', (string)$table)) return false;
    if (!array_key_exists($table, $cache)) {
        try {
            $row = Database::getInstance()->fetch(
                "SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = ? AND table_name = ?",
                [DB_NAME, $table]
            );
            $cache[$table] = (int)($row['n'] ?? 0) > 0;
        } catch (Exception $e) {
            $cache[$table] = false;
        }
    }
    return $cache[$table];
}

/** Ungroups fields whose block is gone or belongs to another module. */
function feUngroupMisgroupedFields($userId) {
    $db = Database::getInstance();
    return $db->update(
        "UPDATE custom_fields f LEFT JOIN custom_field_blocks b ON b.id = f.block_id AND b.user_id = f.user_id
         SET f.block_id = NULL, f.field_order_in_block = 0
         WHERE f.user_id = ? AND f.block_id IS NOT NULL AND (b.id IS NULL OR b.linked_to <> f.linked_to)",
        [$userId]
    );
}

/** Moves the fields and blocks of a missing folder/button onto an existing module. */
function feReassignModule($userId, $oldModule, $newModule) {
    return feTransaction(function () use ($userId, $oldModule, $newModule) {
        $db = Database::getInstance();
        // Values aren't moved: they belong to records of the missing module.
        $n = $db->update("UPDATE custom_fields SET linked_to = ? WHERE linked_to = ? AND user_id = ?", [$newModule, $oldModule, $userId]);
        $n += $db->update("UPDATE custom_field_blocks SET linked_to = ? WHERE linked_to = ? AND user_id = ?", [$newModule, $oldModule, $userId]);
        return $n;
    });
}

// ----------------------------------------------------------- integrity scan

/**
 * Everything whose link points at something that no longer exists, for the
 * Mapping page. Each list holds full rows plus a human 'problem' string.
 */
function feFindBrokenLinks($userId) {
    $db = Database::getInstance();
    $folders = array_flip(array_map('intval', array_column($db->fetchAll("SELECT id FROM other_detail_folders WHERE user_id = ?", [$userId]), 'id')));
    $buttons = [];
    foreach ($db->fetchAll("SELECT * FROM action_buttons WHERE user_id = ?", [$userId]) as $b) {
        $buttons[(int)$b['id']] = $b;
    }
    $moduleOk = function ($module) use ($folders, $buttons) {
        [$kind, $id] = feParseModule($module);
        if ($kind === 'folder') return isset($folders[$id]);
        if ($kind === 'button') return isset($buttons[$id]);
        return $kind === 'core' && $id !== '';
    };

    $out = ['buttons' => [], 'fields' => [], 'blocks' => [], 'field_blocks' => [], 'entries' => 0, 'button_records' => 0];

    foreach ($buttons as $b) {
        if (!$moduleOk($b['parent_module'])) {
            $out['buttons'][] = $b + ['problem' => 'attached to missing ' . $b['parent_module']];
        }
    }
    foreach ($db->fetchAll("SELECT * FROM custom_fields WHERE user_id = ? ORDER BY linked_to, field_order", [$userId]) as $f) {
        if (!$moduleOk($f['linked_to'])) {
            $out['fields'][] = $f;
        }
    }
    foreach ($db->fetchAll("SELECT * FROM custom_field_blocks WHERE user_id = ? ORDER BY linked_to, block_order", [$userId]) as $bl) {
        if (!$moduleOk($bl['linked_to'])) {
            $out['blocks'][] = $bl;
        }
    }
    // Fields grouped into a block that's gone or belongs to another module.
    foreach ($db->fetchAll(
        "SELECT f.id, f.field_label, f.linked_to, f.block_id, b.id AS bid, b.linked_to AS block_module, b.block_label
         FROM custom_fields f LEFT JOIN custom_field_blocks b ON b.id = f.block_id AND b.user_id = f.user_id
         WHERE f.user_id = ? AND f.block_id IS NOT NULL",
        [$userId]
    ) as $f) {
        if ($f['bid'] === null) {
            $out['field_blocks'][] = $f + ['problem' => 'block #' . $f['block_id'] . ' no longer exists'];
        } elseif ($f['block_module'] !== $f['linked_to']) {
            $out['field_blocks'][] = $f + ['problem' => 'block "' . $f['block_label'] . '" belongs to another module'];
        }
    }
    $row = $db->fetch(
        "SELECT COUNT(*) AS n FROM custom_field_entries e LEFT JOIN custom_fields f ON f.id = e.custom_field_id
         WHERE e.user_id = ? AND f.id IS NULL",
        [$userId]
    );
    $out['entries'] = (int)($row['n'] ?? 0);
    $row = $db->fetch(
        "SELECT COUNT(*) AS n FROM action_button_records r LEFT JOIN action_buttons b ON b.id = r.button_id
         WHERE r.user_id = ? AND b.id IS NULL",
        [$userId]
    );
    $out['button_records'] = (int)($row['n'] ?? 0);
    return $out;
}
