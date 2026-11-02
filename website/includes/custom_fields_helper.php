<?php
/**
 * Helper functions for rendering and saving custom fields across all modules.
 *
 * Usage:
 *   $customFields = getCustomFieldsFor('bank_details', $user['id']);
 *   renderCustomFields($customFields, $editCustomValues);
 *   saveCustomFieldEntries($user['id'], 'bank_details', $recordId);
 */

require_once __DIR__ . '/notifications_helper.php';

/**
 * Module labels for display (including dynamic folder-based modules)
 */
function getModuleLabels() {
    $labels = [
        'personal_details' => 'Personal Details',
        'bank_details'     => 'Bank Details',
        'aadhaar_details'  => 'Aadhaar Details',
        'pan_details'      => 'PAN Details',
        'other_details'    => 'Other Details',
        'documents'        => 'Documents',
        'savings_daily'              => 'Savings - Daily Savings',
        'savings_bank_accounts'      => 'Savings - Bank Accounts',
        'savings_fixed_deposits'     => 'Savings - Fixed Deposits',
        'savings_recurring_deposits' => 'Savings - Recurring Deposits',
        'savings_mutual_funds'       => 'Savings - Mutual Funds',
        'savings_sip_investments'    => 'Savings - SIP Investments',
        'savings_stocks'             => 'Savings - Stocks',
        'savings_ppf_epf'            => 'Savings - PPF & EPF',
        'savings_gold_metals'        => 'Savings - Gold & Metals',
        'savings_insurance_policies' => 'Savings - Insurance Policies',
        'savings_cash_on_hand'       => 'Savings - Cash on Hand',
        'savings_loans'              => 'Savings - Loans',
        'savings_credit_cards'       => 'Savings - Credit Cards',
        'savings_lent_money'         => 'Savings - Money Lent',
        'savings_documents_registry' => 'Savings - Documents & Safe Details',
        'savings_bank_transactions'  => 'Savings - Bank Transactions',
        'savings_transactions'       => 'Savings - Ledger Transactions (all modules)',
    ];

    // Each ledger category's own transaction fields (module
    // "savings_transactions__<category>"), so a column added to the SIP
    // ledger shows on SIP transactions only -- see savingsLedgerModule() in
    // savings_ledger_helper.php and the desktop's app/models/ledger_modules.py.
    foreach (savingsLedgerCategoryTables() as $ledgerTable) {
        $categoryLabel = str_replace('Savings - ', '', $labels[$ledgerTable] ?? $ledgerTable);
        $labels['savings_transactions__' . $ledgerTable] = 'Savings - ' . $categoryLabel . ' › Ledger Transactions';
    }

    // Add folder-based modules dynamically
    try {
        $db = Database::getInstance();
        $auth = new Auth();
        if ($auth->isLoggedIn()) {
            $currentUser = $auth->getCurrentUser();
            $folders = $db->fetchAll(
                "SELECT id, folder_name FROM other_detail_folders WHERE user_id = ? AND is_active = 1 ORDER BY folder_order ASC",
                [$currentUser['id']]
            );
            foreach ($folders as $folder) {
                $labels['other_folder_' . $folder['id']] = '📁 ' . $folder['folder_name'];
            }

            // Action Buttons' own synthesized child modules (see
            // action_buttons_helper.php) -- same dynamic-append pattern as
            // folders above, so a nested button's module shows a friendly
            // label instead of the raw "action_btn_{id}" key.
            $buttons = $db->fetchAll(
                "SELECT id, label, parent_module FROM action_buttons WHERE user_id = ? ORDER BY button_order ASC, id ASC",
                [$currentUser['id']]
            );
            foreach ($buttons as $button) {
                $labels['action_btn_' . $button['id']] = '🔘 ' . $button['label'];
            }
            // Show which module a button sits on, so two buttons with the same
            // name ("Files" on two folders) can be told apart.
            foreach ($buttons as $button) {
                $parent = $labels[$button['parent_module']] ?? null;
                if ($parent !== null) {
                    $labels['action_btn_' . $button['id']] .= ' — on ' . $parent;
                }
            }
        }
    } catch (Exception $e) {
        // Silently fail if DB not available
    }

    // Any label still shared by two modules gets its key appended.
    $counts = array_count_values($labels);
    foreach ($labels as $key => $label) {
        if ($counts[$label] > 1) $labels[$key] = $label . ' [' . $key . ']';
    }
    return $labels;
}

/**
 * Savings categories that keep a transaction ledger in savings_transactions
 * (same list as $SAVINGS_LEDGER_CONFIG in savings_ledger_helper.php, kept
 * here so this file doesn't need that one loaded).
 */
function savingsLedgerCategoryTables(): array {
    return [
        'savings_fixed_deposits', 'savings_recurring_deposits', 'savings_mutual_funds',
        'savings_sip_investments', 'savings_stocks', 'savings_ppf_epf', 'savings_gold_metals',
        'savings_insurance_policies', 'savings_cash_on_hand', 'savings_loans',
        'savings_credit_cards', 'savings_lent_money',
    ];
}

/**
 * Get module badge color
 */
function getModuleBadgeClass($module) {
    $map = [
        'personal_details' => '#6C63FF',
        'bank_details'     => '#2ecc71',
        'aadhaar_details'  => '#9b59b6',
        'pan_details'      => '#f5576c',
        'other_details'    => '#45B7D1',
        'documents'        => '#e74c3c',
    ];
    if (isset($map[$module])) return $map[$module];
    // For folder-based modules, try to get the folder color
    if (strpos($module, 'other_folder_') === 0) {
        $folderId = (int)str_replace('other_folder_', '', $module);
        try {
            $db = Database::getInstance();
            $folder = $db->fetch("SELECT folder_color FROM other_detail_folders WHERE id = ?", [$folderId]);
            if ($folder) return $folder['folder_color'];
        } catch (Exception $e) {}
    }
    // Action Buttons' own synthesized child modules -- no per-button color
    // concept, just a fixed badge color distinct from the others above.
    if (strpos($module, 'action_btn_') === 0) {
        return '#f0b429';
    }
    return '#95a5a6';
}

/**
 * Self-heals the `show_in_list` column on custom_fields -- added so the
 * Custom Fields admin screen (custom-fields.php) can match the tkinter
 * desktop app's "Shown in List" flag, which already existed in its SQLite
 * schema. Same belt-and-suspenders pattern as notifications_helper.php's
 * template_id column and setup.php's field_type migration, so an existing
 * hosted install picks it up on its next request with no manual SQL step.
 */
function ensureCustomFieldShowInListColumn() {
    try {
        if (!dbColumnExists('custom_fields', 'show_in_list')) {
            Database::getInstance()->getConnection()->exec(
                "ALTER TABLE custom_fields ADD COLUMN show_in_list TINYINT(1) NOT NULL DEFAULT 0"
            );
        }
    } catch (Exception $e) {
        // Best-effort self-heal (e.g. hosting DB user lacks ALTER TABLE) --
        // getCustomFieldsFor()/admin screen just treat the field as unset.
    }
}

/**
 * Get active custom fields for a specific module
 */
function getCustomFieldsFor($module, $userId) {
    $db = Database::getInstance();
    return $db->fetchAll(
        "SELECT * FROM custom_fields WHERE user_id = ? AND linked_to = ? AND is_active = 1 ORDER BY field_order",
        [$userId, $module]
    );
}

/**
 * Get modules where a specific field is used (reused)
 */
function getFieldModules($fieldId) {
    $db = Database::getInstance();
    try {
        $modules = $db->fetchAll(
            "SELECT DISTINCT linked_to FROM custom_fields WHERE id = ? AND is_active = 1",
            [$fieldId]
        );
        return array_column($modules, 'linked_to');
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Add a field to an additional module (reuse field)
 */
function addFieldToModule($fieldId, $targetModule, $userId) {
    $db = Database::getInstance();
    try {
        // Get the field details
        $field = $db->fetch(
            "SELECT * FROM custom_fields WHERE id = ? AND user_id = ?",
            [$fieldId, $userId]
        );
        
        if (!$field) {
            return ['success' => false, 'message' => 'Field not found'];
        }
        
        // Check if already linked
        $existing = $db->fetch(
            "SELECT id FROM custom_fields WHERE id = ? AND linked_to = ?",
            [$fieldId, $targetModule]
        );
        
        if ($existing) {
            return ['success' => false, 'message' => 'Field already in this module'];
        }
        
        require_once __DIR__ . '/fields_engine.php';
        if (!feModuleExists($targetModule, $userId)) {
            return ['success' => false, 'message' => 'That module no longer exists'];
        }
        // Clone the field for the new module. Its block belongs to the source
        // module, so the copy starts ungrouped (it used to keep the source
        // block_id -- a block of a different module, which nothing can show).
        $maxOrder = $db->fetch("SELECT MAX(field_order) AS mo FROM custom_fields WHERE user_id = ? AND linked_to = ?", [$userId, $targetModule]);
        $newFieldId = $db->insert(
            "INSERT INTO custom_fields (user_id, field_name, field_label, field_type, field_options, field_placeholder, is_required, show_in_list, field_order, linked_to, block_id, field_order_in_block, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 0, 1)",
            [$userId, $field['field_name'], $field['field_label'], $field['field_type'],
             $field['field_options'], $field['field_placeholder'], $field['is_required'], (int)($field['show_in_list'] ?? 0),
             ($maxOrder['mo'] ?? 0) + 1, $targetModule]
        );
        
        return ['success' => true, 'message' => 'Field reused successfully', 'newFieldId' => $newFieldId];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Get available fields that can be reused (fields from other modules)
 */
function getReuseableFields($currentModule, $userId) {
    $db = Database::getInstance();
    try {
        $fields = $db->fetchAll(
            "SELECT DISTINCT field_name, field_label, field_type, id, linked_to 
             FROM custom_fields 
             WHERE user_id = ? AND is_active = 1 AND linked_to != ? 
             GROUP BY field_name 
             ORDER BY field_label",
            [$userId, $currentModule]
        );
        return $fields;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get active blocks for a module with their fields
 */
function getBlocksForModule($module, $userId) {
    $db = Database::getInstance();
    return $db->fetchAll(
        "SELECT * FROM custom_field_blocks WHERE user_id = ? AND linked_to = ? AND is_active = 1 ORDER BY block_order ASC",
        [$userId, $module]
    );
}

/**
 * Get fields for a specific block
 */
function getBlockFields($blockId, $userId) {
    $db = Database::getInstance();
    return $db->fetchAll(
        "SELECT * FROM custom_fields WHERE block_id = ? AND user_id = ? AND is_active = 1 ORDER BY field_order_in_block ASC",
        [$blockId, $userId]
    );
}

/**
 * Get CSS grid style for a layout type
 */
function getBlockLayoutCSS($layout) {
    $map = [
        '1-col' => 'grid-template-columns: 1fr;',
        '2-col' => 'grid-template-columns: 1fr 1fr;',
        '3-col' => 'grid-template-columns: 1fr 1fr 1fr;',
        '4-col' => 'grid-template-columns: 1fr 1fr 1fr 1fr;',
        '2-1'   => 'grid-template-columns: 2fr 1fr;',
        '1-2'   => 'grid-template-columns: 1fr 2fr;',
    ];
    return $map[$layout] ?? 'grid-template-columns: 1fr;';
}

/**
 * Get custom field values for a specific record
 * Returns: ['field_id' => 'value'] for non-repeatable fields
 *          ['field_id' => ['0' => 'value', '1' => 'value']] for repeatable block fields
 */
function getCustomFieldValues($module, $recordId) {
    $db = Database::getInstance();
    try {
        $rows = $db->fetchAll(
            "SELECT cfe.custom_field_id, cfe.field_value, cfe.block_instance,
                    cf.block_id
             FROM custom_field_entries cfe
             JOIN custom_fields cf ON cf.id = cfe.custom_field_id
             WHERE cfe.linked_to = ? AND cfe.record_id = ?",
            [$module, $recordId]
        );
    } catch (Exception $e) {
        // Table may have wrong column names on hosted DB — return empty gracefully
        return [];
    }
    
    // Determine which blocks are repeatable
    $repeatableBlocks = [];
    if (!empty($rows)) {
        $blockIds = array_unique(array_filter(array_column($rows, 'block_id')));
        if (!empty($blockIds)) {
            $placeholders = implode(',', array_fill(0, count($blockIds), '?'));
            $blockRows = $db->fetchAll(
                "SELECT id, is_repeatable FROM custom_field_blocks WHERE id IN ($placeholders)",
                array_values($blockIds)
            );
            foreach ($blockRows as $br) {
                if ($br['is_repeatable']) {
                    $repeatableBlocks[$br['id']] = true;
                }
            }
        }
    }
    
    $values = [];
    foreach ($rows as $row) {
        $fieldId = $row['custom_field_id'];
        $blockId = $row['block_id'];
        $instance = (int)$row['block_instance'];
        
        if ($blockId && isset($repeatableBlocks[$blockId])) {
            // Repeatable block field — store as array indexed by instance
            if (!isset($values[$fieldId]) || !is_array($values[$fieldId])) {
                $values[$fieldId] = [];
            }
            $values[$fieldId][$instance] = $row['field_value'];
        } else {
            // Non-repeatable — flat value
            $values[$fieldId] = $row['field_value'];
        }
    }
    return $values;
}

/**
 * File upload field types
 */
function getFileFieldTypes() {
    return ['file', 'image', 'pdf', 'document', 'spreadsheet'];
}

/**
 * Get allowed MIME types for file-type fields
 */
function getAllowedMimes($fieldType) {
    $map = [
        'file'        => [
            'application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain', 'text/csv', 'application/zip', 'application/x-rar-compressed',
            'application/json', 'application/xml', 'text/xml'
        ],
        'image'       => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'],
        'pdf'         => ['application/pdf'],
        'document'    => ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/rtf', 'text/plain'],
        'spreadsheet' => ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/csv'],
    ];
    return $map[$fieldType] ?? $map['file'];
}

/**
 * Get accepted file extensions for HTML accept attribute
 */
function getAcceptAttribute($fieldType) {
    $map = [
        'file'        => '.pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx,.xls,.xlsx,.csv,.txt,.zip,.rar,.json,.xml',
        'image'       => '.jpg,.jpeg,.png,.gif,.webp,.svg',
        'pdf'         => '.pdf',
        'document'    => '.doc,.docx,.rtf,.txt',
        'spreadsheet' => '.xls,.xlsx,.csv',
    ];
    return $map[$fieldType] ?? $map['file'];
}

/**
 * Handle file upload for a custom field, return stored path or empty string.
 * Tries Google Drive first, falls back to local storage.
 * Google Drive paths are stored as 'gdrive:{fileId}:{originalName}'
 */
function handleCustomFieldUpload($fieldId, $userId, $fieldType) {
    $fileKey = 'custom_field_' . $fieldId;
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
        return '';
    }

    $file = $_FILES[$fileKey];
    $allowedMimes = getAllowedMimes($fieldType);

    if ($file['size'] > MAX_UPLOAD_SIZE) {
        return ''; // Silently skip oversized files
    }
    if (!in_array($file['type'], $allowedMimes)) {
        return ''; // Silently skip disallowed types
    }

    // Try Google Drive upload first
    $gdrivePath = _tryGoogleDriveUpload($file);
    if ($gdrivePath) {
        return $gdrivePath;
    }

    // Fallback to local storage
    $uploadDir = __DIR__ . '/../uploads/custom_fields/' . $userId . '/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $uniqueName = 'cf_' . $fieldId . '_' . uniqid() . '_' . time() . '.' . $ext;
    $destPath = $uploadDir . $uniqueName;

    if (move_uploaded_file($file['tmp_name'], $destPath)) {
        return 'uploads/custom_fields/' . $userId . '/' . $uniqueName;
    }
    return '';
}

/**
 * Try to upload a file to Google Drive.
 * Returns 'gdrive:{fileId}:{originalName}' on success, or empty string on failure.
 */
function _tryGoogleDriveUpload($file) {
    $configFile = __DIR__ . '/../config/google-drive-config.php';
    if (!file_exists($configFile)) {
        return '';
    }

    try {
        require_once __DIR__ . '/google-drive.php';
        $gdrive = new GoogleDriveIntegration();

        if (!$gdrive->isConfigured()) {
            return '';
        }

        $googleDriveFileId = $gdrive->uploadFile(
            $file['tmp_name'],
            $file['name'],
            $file['type']
        );

        if ($googleDriveFileId) {
            // Store as gdrive:{id}:{originalFilename} so we can display the name later
            return 'gdrive:' . $googleDriveFileId . ':' . $file['name'];
        }
    } catch (Exception $e) {
        error_log('Google Drive upload failed for custom field: ' . $e->getMessage());
    }

    return '';
}

/**
 * Check if a custom field value is a Google Drive file
 */
function isCustomFieldGDriveFile($value) {
    return strpos($value, 'gdrive:') === 0;
}

/**
 * Parse a Google Drive custom field value into [id, filename]
 */
function parseGDrivePath($value) {
    // Format: gdrive:{fileId}:{originalName}
    $parts = explode(':', $value, 3);
    return [
        'id' => $parts[1] ?? '',
        'name' => $parts[2] ?? 'download',
    ];
}
/**
 * Delete a single file stored in a custom field value (Google Drive or local).
 * Handles formats: 'gdrive:{id}:{name}', 'gdrive:{id}', or local path.
 */
function deleteCustomFieldFileByValue($value) {
    if (empty($value)) return;

    if (isCustomFieldGDriveFile($value)) {
        // Google Drive file — format: gdrive:{fileId} or gdrive:{fileId}:{name}
        $parsed = parseGDrivePath($value);
        if (!empty($parsed['id'])) {
            require_once __DIR__ . '/google-drive-helper.php';
            deleteFromGoogleDrive($parsed['id']);
        }
    } elseif (strpos($value, 'gdrive:') === 0) {
        // Fallback for documents-style gdrive:{id} without name
        $gdriveId = substr($value, 7);
        if ($gdriveId) {
            require_once __DIR__ . '/google-drive-helper.php';
            deleteFromGoogleDrive($gdriveId);
        }
    } elseif (strpos($value, 'uploads/') === 0) {
        // Local file
        $localPath = __DIR__ . '/../' . $value;
        if (file_exists($localPath)) {
            unlink($localPath);
        }
    }
}

/**
 * Delete all custom field file entries (Google Drive + local) for a specific record.
 * Call this BEFORE deleting the record from the main table.
 */
function deleteCustomFieldFilesForRecord($module, $recordId) {
    require_once __DIR__ . '/fields_engine.php';
    $db = Database::getInstance();
    try {
        $owner = $db->fetch(
            "SELECT user_id FROM custom_field_entries WHERE linked_to = ? AND record_id = ? LIMIT 1",
            [$module, $recordId]
        );
        if (!$owner) {
            $owner = $db->fetch(
                "SELECT user_id FROM action_buttons WHERE parent_module = ? LIMIT 1", [$module]
            );
        }
        if ($owner) {
            // Values, files nothing else uses, reminders, and any Action
            // Button child records hanging off this record.
            feDeleteRecordChildren($module, $recordId, $owner['user_id']);
        }
    } catch (Exception $e) {
        error_log('deleteCustomFieldFilesForRecord(' . $module . ', ' . $recordId . '): ' . $e->getMessage());
    }
}

/**
 * Deletes a field's saved values (and files nothing else uses) -- call right
 * before deleting the field itself. Pages should prefer feDeleteField().
 */
function deleteCustomFieldFilesByFieldId($fieldId) {
    require_once __DIR__ . '/fields_engine.php';
    $db = Database::getInstance();
    $field = $db->fetch("SELECT field_type, user_id FROM custom_fields WHERE id = ?", [$fieldId]);
    if (!$field) return;
    $values = feIsFileType($field['field_type'])
        ? array_column($db->fetchAll("SELECT field_value FROM custom_field_entries WHERE custom_field_id = ?", [$fieldId]), 'field_value')
        : [];
    $db->delete("DELETE FROM custom_field_entries WHERE custom_field_id = ? AND user_id = ?", [$fieldId, $field['user_id']]);
    feDeleteReminderNotifications('custom_field_id = ?', [$fieldId]);
    feDeleteUnreferencedFiles($values);
}

/**
 * Saves the custom field values posted by a record's form.
 *
 * Mirrors exactly what renderCustomFields() put on the form: a field is
 * treated as part of a repeatable block only when that block is active and
 * belongs to this module (otherwise the form showed it as a plain field),
 * and a repeatable block whose instance counter wasn't posted is left
 * completely untouched -- a form that didn't show it can't wipe it.
 *
 * Values are written in place by feWriteEntries() (unchanged values stay
 * as they are, a kept file is never deleted, only files nothing references
 * any more are removed). Returns false and logs if saving failed, instead
 * of silently losing the values like the previous version did.
 */
function saveCustomFieldEntries($userId, $module, $recordId) {
    require_once __DIR__ . '/fields_engine.php';
    $db = Database::getInstance();
    $fields = getCustomFieldsFor($module, $userId);
    if (empty($fields)) return true;
    $fileTypes = getFileFieldTypes();

    $renderedBlocks = [];
    foreach (getBlocksForModule($module, $userId) as $b) {
        $renderedBlocks[(int)$b['id']] = $b;
    }

    $values = [];
    $fieldsById = [];
    $reminders = [];
    foreach ($fields as $field) {
        $fieldId = (int)$field['id'];
        $fieldsById[$fieldId] = $field;
        $isFile = in_array($field['field_type'], $fileTypes, true);
        $postKey = 'custom_field_' . $fieldId;
        $block = !empty($field['block_id']) ? ($renderedBlocks[(int)$field['block_id']] ?? null) : null;

        if ($block && !empty($block['is_repeatable'])) {
            $countKey = 'block_' . (int)$block['id'] . '_instance_count';
            if (!isset($_POST[$countKey])) continue; // block not on this form
            $count = max(0, min((int)$_POST[$countKey], (int)$block['max_instances']));
            $values[$fieldId] = [];
            for ($i = 0; $i < $count; $i++) {
                if ($isFile) {
                    $fileKey = 'custom_file_' . $fieldId . '_' . $i;
                    $value = '';
                    if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
                        $value = handleCustomFieldUploadByKey($fileKey, $fieldId, $userId, $field['field_type']);
                    }
                    if ($value === '' || $value === null) {
                        $value = sanitize($_POST['existing_file_' . $fieldId . '_' . $i] ?? '');
                    }
                } elseif (in_array($field['field_type'], ['checkbox', 'multiselect'], true)) {
                    $val = $_POST[$postKey][$i] ?? [];
                    $value = is_array($val) ? implode(',', array_map('sanitize', $val)) : sanitize($val);
                } elseif ($field['field_type'] === 'toggle') {
                    $value = isset($_POST[$postKey][$i]) ? '1' : '0';
                } else {
                    $value = sanitize($_POST[$postKey][$i] ?? '');
                }
                $values[$fieldId][$i] = $value;
                if ($field['field_type'] === 'reminder') $reminders[] = [$field, $i, $value];
            }
        } else {
            if ($isFile) {
                $uploaded = handleCustomFieldUpload($fieldId, $userId, $field['field_type']);
                if (!$uploaded) continue; // no new file: keep whatever is stored
                $value = $uploaded;
            } elseif (in_array($field['field_type'], ['checkbox', 'multiselect'], true)) {
                $val = $_POST[$postKey] ?? [];
                $value = is_array($val) ? implode(',', array_map('sanitize', $val)) : sanitize($val);
            } elseif ($field['field_type'] === 'toggle') {
                $value = isset($_POST[$postKey]) ? '1' : '0';
            } else {
                $value = sanitize($_POST[$postKey] ?? '');
            }
            // Instance 0 only; a flat field never had other instances, but if
            // it used to sit in a repeatable block its other rows are kept.
            $values[$fieldId] = [0 => $value];
            if ($field['field_type'] === 'reminder') $reminders[] = [$field, 0, $value];
        }
    }

    try {
        // Flat fields only cover instance 0: keep their other instances by
        // merging them back in, so feWriteEntries doesn't treat them as removed.
        foreach (array_keys($values) as $fieldId) {
            $field = $fieldsById[$fieldId];
            $block = !empty($field['block_id']) ? ($renderedBlocks[(int)$field['block_id']] ?? null) : null;
            if ($block && !empty($block['is_repeatable'])) continue;
            foreach ($db->fetchAll(
                "SELECT block_instance, field_value FROM custom_field_entries
                 WHERE user_id = ? AND custom_field_id = ? AND linked_to = ? AND record_id = ? AND block_instance <> 0",
                [$userId, $fieldId, $module, $recordId]
            ) as $other) {
                $values[$fieldId][(int)$other['block_instance']] = $other['field_value'];
            }
        }
        feWriteEntries($userId, $module, $recordId, $values, $fieldsById);
    } catch (Throwable $e) {
        error_log('saveCustomFieldEntries failed for ' . $module . ' #' . $recordId . ': ' . $e->getMessage());
        if (function_exists('setFlash')) {
            setFlash('error', 'The record was saved, but its custom fields could not be saved: ' . $e->getMessage());
        }
        return false;
    }

    foreach ($reminders as [$field, $instance, $value]) {
        // Broad catch is deliberate (matches syncReminderFieldEntry's own
        // internal handling) -- a reminder failure must not undo the save.
        try { syncReminderFieldEntry($userId, $module, $field, $recordId, $instance, $value); }
        catch (Exception $e) { if (function_exists('_logReminderFieldError')) _logReminderFieldError($userId, $field['field_label'], $e); }
    }
    return true;
}

/**
 * Handle file upload by custom key name (for repeatable blocks).
 * Tries Google Drive first, falls back to local storage.
 */
function handleCustomFieldUploadByKey($fileKey, $fieldId, $userId, $fieldType) {
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
        return '';
    }

    $file = $_FILES[$fileKey];
    $allowedMimes = getAllowedMimes($fieldType);

    if ($file['size'] > MAX_UPLOAD_SIZE) {
        return '';
    }
    if (!in_array($file['type'], $allowedMimes)) {
        return '';
    }

    // Try Google Drive upload first
    $gdrivePath = _tryGoogleDriveUpload($file);
    if ($gdrivePath) {
        return $gdrivePath;
    }

    // Fallback to local storage
    $uploadDir = __DIR__ . '/../uploads/custom_fields/' . $userId . '/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $uniqueName = 'cf_' . $fieldId . '_' . uniqid() . '_' . time() . '.' . $ext;
    $destPath = $uploadDir . $uniqueName;

    if (move_uploaded_file($file['tmp_name'], $destPath)) {
        return 'uploads/custom_fields/' . $userId . '/' . $uniqueName;
    }
    return '';
}

/**
 * Render custom fields in a form (block-aware)
 */
function renderCustomFields($fields, $values = [], $sectionTitle = 'Custom Fields', $module = '', $userId = null) {
    if (empty($fields)) {
        ?>
        <div style="margin: 24px 0 16px; padding: 16px; border: 1px dashed var(--border); border-radius: 12px; background: var(--bg-soft);">
            <h3 style="font-size: 1rem; color: var(--accent); margin: 0 0 8px;">
                <i class="fas fa-puzzle-piece"></i> <?php echo htmlspecialchars($sectionTitle); ?>
            </h3>
            <p style="margin: 0 0 12px; color: var(--text-secondary);">
                No custom fields are defined for this module yet.
            </p>
            <?php if ($module): ?>
            <a class="btn btn-ghost btn-sm" href="<?php echo url('custom-fields.php?module=' . urlencode($module)); ?>">
                <i class="fas fa-plus"></i> Add Custom Fields
            </a>
            <a class="btn btn-ghost btn-sm" href="<?php echo url('field-blocks.php?module=' . urlencode($module)); ?>" style="margin-left: 8px;">
                <i class="fas fa-th-large"></i> Manage Field Blocks
            </a>
            <?php endif; ?>
        </div>
        <?php
        renderNotifBridgeQuickSend($module, $userId);
        return;
    }
    
    // Check if any file-type fields exist – if so, inject enctype on parent form
    $fileTypes = getFileFieldTypes();
    $hasFileFields = false;
    foreach ($fields as $f) {
        if (in_array($f['field_type'], $fileTypes)) { $hasFileFields = true; break; }
    }
    ?>
    <h3 style="font-size: 1rem; color: var(--accent); margin: 24px 0 16px;">
        <i class="fas fa-puzzle-piece"></i> <?php echo htmlspecialchars($sectionTitle); ?>
    </h3>

    <?php if ($hasFileFields): ?>
    <script>
    (function(){
        var form = document.querySelector('form[method="POST"]');
        if (form && !form.getAttribute('enctype')) {
            form.setAttribute('enctype', 'multipart/form-data');
        }
    })();
    </script>
    <?php endif; ?>

    <?php
    // Group fields by block
    $blocks = [];
    $unblocked = [];
    
    if ($module && $userId) {
        $moduleBlocks = getBlocksForModule($module, $userId);
        $blockMap = [];
        foreach ($moduleBlocks as $b) {
            $blockMap[$b['id']] = $b;
            $blocks[$b['id']] = [];
        }
    }
    
    foreach ($fields as $field) {
        if (!empty($field['block_id']) && isset($blocks[$field['block_id']])) {
            $blocks[$field['block_id']][] = $field;
        } else {
            $unblocked[] = $field;
        }
    }
    
    // Render blocks first
    if ($module && $userId) {
        foreach ($moduleBlocks as $block) {
            $blockFields = $blocks[$block['id']] ?? [];
            if (empty($blockFields)) continue;
            
            // Sort by field_order_in_block
            usort($blockFields, function($a, $b) {
                return ($a['field_order_in_block'] ?? 0) - ($b['field_order_in_block'] ?? 0);
            });
            
            $layoutCSS = getBlockLayoutCSS($block['layout']);
            $isRepeatable = !empty($block['is_repeatable']);
            $maxInstances = (int)($block['max_instances'] ?? 10);
            
            if ($isRepeatable) {
                // Determine existing instances
                $existingInstances = 0;
                foreach ($blockFields as $f) {
                    $val = $values[$f['id']] ?? '';
                    if (is_array($val)) {
                        $existingInstances = max($existingInstances, count($val));
                    }
                }
                $instanceCount = max(1, $existingInstances); // At least 1 instance
                ?>
                <div class="custom-field-block repeater-block" data-block-id="<?php echo $block['id']; ?>" data-max-instances="<?php echo $maxInstances; ?>">
                    <div class="custom-block-header">
                        <span>
                            <i class="fas fa-clone"></i> <?php echo htmlspecialchars($block['block_label']); ?>
                            <span class="repeater-count-badge">(<span class="repeater-count"><?php echo $instanceCount; ?></span> / <?php echo $maxInstances; ?>)</span>
                        </span>
                    </div>
                    <input type="hidden" name="block_<?php echo $block['id']; ?>_instance_count" value="<?php echo $instanceCount; ?>" class="repeater-instance-count">
                    
                    <div class="repeater-instances">
                        <?php for ($inst = 0; $inst < $instanceCount; $inst++): ?>
                        <div class="repeater-instance" data-instance="<?php echo $inst; ?>">
                            <div class="repeater-instance-header">
                                <span class="repeater-instance-label"><i class="fas fa-layer-group"></i> #<?php echo $inst + 1; ?></span>
                                <?php if ($inst > 0): ?>
                                <button type="button" class="btn btn-icon btn-ghost repeater-remove-btn" title="Remove this entry">
                                    <i class="fas fa-trash-alt" style="color: var(--danger);"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                            <div class="custom-block-body" style="display: grid; <?php echo $layoutCSS; ?> gap: 16px;">
                                <?php foreach ($blockFields as $field): 
                                    $fieldValRaw = $values[$field['id']] ?? '';
                                    $fieldValue = is_array($fieldValRaw) ? ($fieldValRaw[$inst] ?? '') : ($inst === 0 ? $fieldValRaw : '');
                                    $options = $field['field_options'] ? json_decode($field['field_options'], true) : [];
                                ?>
                                <div class="form-group">
                                    <label>
                                        <?php echo htmlspecialchars($field['field_label']); ?>
                                        <?php if ($field['is_required']): ?><span class="required">*</span><?php endif; ?>
                                    </label>
                                    <div class="input-wrapper no-icon">
                                        <?php renderSingleFieldInput($field, $fieldValue, $options, $inst); ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endfor; ?>
                    </div>
                    
                    <div class="repeater-actions">
                        <button type="button" class="btn btn-secondary repeater-add-btn">
                            <i class="fas fa-plus-circle"></i> Add More
                        </button>
                    </div>
                </div>
                <?php
            } else {
                // Non-repeatable block — original rendering
                ?>
                <div class="custom-field-block" style="margin-bottom: 20px;">
                    <div class="custom-block-header">
                        <i class="fas fa-th-large"></i> <?php echo htmlspecialchars($block['block_label']); ?>
                    </div>
                    <div class="custom-block-body" style="display: grid; <?php echo $layoutCSS; ?> gap: 16px;">
                        <?php foreach ($blockFields as $field): 
                            $fieldValue = $values[$field['id']] ?? '';
                            if (is_array($fieldValue)) $fieldValue = $fieldValue[0] ?? '';
                            $options = $field['field_options'] ? json_decode($field['field_options'], true) : [];
                        ?>
                        <div class="form-group">
                            <label>
                                <?php echo htmlspecialchars($field['field_label']); ?>
                                <?php if ($field['is_required']): ?><span class="required">*</span><?php endif; ?>
                            </label>
                            <div class="input-wrapper no-icon">
                                <?php renderSingleFieldInput($field, $fieldValue, $options); ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php
            }
        }
    }
    
    // Render unblocked fields in the default form-row layout
    if (!empty($unblocked)):
    ?>
    <div class="form-row">
        <?php foreach ($unblocked as $field): 
            $fieldValue = $values[$field['id']] ?? '';
            $options = $field['field_options'] ? json_decode($field['field_options'], true) : [];
        ?>
        <div class="form-group">
            <label>
                <?php echo htmlspecialchars($field['field_label']); ?>
                <?php if ($field['is_required']): ?><span class="required">*</span><?php endif; ?>
            </label>
            <div class="input-wrapper no-icon">
                <?php renderSingleFieldInput($field, $fieldValue, $options); ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <script>
    // === Event delegation for custom field controls ===
    // Avoids inline onclick/onchange/oninput which hosting WAFs strip

    document.addEventListener('click', function(e) {
        // Star rating click
        var starBtn = e.target.closest('.star-btn');
        if (starBtn) {
            var v = starBtn.getAttribute('data-value');
            var c = starBtn.closest('.star-rating');
            c.querySelector('.rating-value').value = v;
            c.querySelectorAll('.star-btn').forEach(function(s) {
                s.classList.toggle('active', parseInt(s.getAttribute('data-value')) <= parseInt(v));
            });
            return;
        }

        // Password toggle click
        var pwToggle = e.target.closest('.cf-pw-toggle');
        if (pwToggle) {
            var targetId = pwToggle.getAttribute('data-target');
            var f = document.getElementById(targetId);
            if (f) {
                var isMasked = f.style.webkitTextSecurity === 'disc' || f.style.textSecurity === 'disc';
                if (isMasked) {
                    f.style.webkitTextSecurity = 'none';
                    f.style.textSecurity = 'none';
                } else {
                    f.style.webkitTextSecurity = 'disc';
                    f.style.textSecurity = 'disc';
                }
                var icon = pwToggle.querySelector('i') || pwToggle;
                icon.classList.toggle('fa-eye');
                icon.classList.toggle('fa-eye-slash');
            }
            return;
        }

        // File upload area click
        var fileUpload = e.target.closest('.custom-file-upload');
        if (fileUpload) {
            var fileInput = fileUpload.querySelector('input[type=file]');
            if (fileInput && e.target !== fileInput) fileInput.click();
            return;
        }

        // Repeater add button
        var addBtn = e.target.closest('.repeater-add-btn');
        if (addBtn) {
            addRepeaterInstance(addBtn);
            return;
        }

        // Repeater remove button
        var removeBtn = e.target.closest('.repeater-remove-btn');
        if (removeBtn) {
            removeRepeaterInstance(removeBtn);
            return;
        }
    });

    // Range slider input event
    document.addEventListener('input', function(e) {
        if (e.target.classList.contains('cf-range-input')) {
            var span = e.target.nextElementSibling;
            if (span) span.textContent = e.target.value;
        }
    });

    // File input change event
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('cf-file-input')) {
            var label = e.target.closest('.custom-file-upload');
            if (label) {
                var fileLabel = label.querySelector('.file-label');
                if (fileLabel && e.target.files.length > 0) {
                    fileLabel.textContent = e.target.files[0].name + ' (' + (e.target.files[0].size / 1024 / 1024).toFixed(2) + ' MB)';
                    fileLabel.style.color = 'var(--success)';
                }
            }
        }
    });

    // Legacy function name kept for any remaining references
    function updateFileLabel(input) {
        var label = input.closest('.custom-file-upload').querySelector('.file-label');
        if (input.files.length > 0) {
            label.textContent = input.files[0].name + ' (' + (input.files[0].size / 1024 / 1024).toFixed(2) + ' MB)';
            label.style.color = 'var(--success)';
        }
    }
    
    // Repeater: Add new instance
    function addRepeaterInstance(btn) {
        var block = btn.closest('.repeater-block');
        var maxInstances = parseInt(block.getAttribute('data-max-instances')) || 10;
        var instancesContainer = block.querySelector('.repeater-instances');
        var currentInstances = instancesContainer.querySelectorAll('.repeater-instance');
        var currentCount = currentInstances.length;
        
        if (currentCount >= maxInstances) {
            if (typeof showToast === 'function') {
                showToast('Maximum ' + maxInstances + ' entries allowed', 'warning');
            } else {
                alert('Maximum ' + maxInstances + ' entries allowed');
            }
            return;
        }
        
        var newIndex = currentCount;
        
        // Clone the first instance as template
        var template = currentInstances[0].cloneNode(true);
        template.setAttribute('data-instance', newIndex);
        
        // Update instance header
        var headerLabel = template.querySelector('.repeater-instance-label');
        if (headerLabel) {
            headerLabel.innerHTML = '<i class="fas fa-layer-group"></i> #' + (newIndex + 1);
        }
        
        // Add remove button if not present
        var header = template.querySelector('.repeater-instance-header');
        var existingRemove = header.querySelector('.repeater-remove-btn');
        if (!existingRemove) {
            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn btn-icon btn-ghost repeater-remove-btn';
            removeBtn.title = 'Remove this entry';
            removeBtn.innerHTML = '<i class="fas fa-trash-alt" style="color: var(--danger);"></i>';
            header.appendChild(removeBtn);
        }
        
        // Clear field values and update names/IDs
        var inputs = template.querySelectorAll('input, textarea, select');
        inputs.forEach(function(input) {
            var name = input.getAttribute('name');
            if (!name) return;
            
            var newName = name.replace(/\[\d+\]/, '[' + newIndex + ']');
            newName = newName.replace(/(custom_file_\d+_)\d+/, '$1' + newIndex);
            newName = newName.replace(/(existing_file_\d+_)\d+/, '$1' + newIndex);
            input.setAttribute('name', newName);
            
            var id = input.getAttribute('id');
            if (id) {
                input.setAttribute('id', id.replace(/_\d+$/, '_' + newIndex));
            }
            
            if (input.type === 'checkbox' || input.type === 'radio') {
                input.checked = false;
            } else if (input.type === 'file') {
                var newInput = input.cloneNode(true);
                newInput.value = '';
                input.parentNode.replaceChild(newInput, input);
            } else if (input.type === 'hidden' && name.indexOf('existing_file_') === 0) {
                input.remove();
            } else if (input.type === 'hidden' && input.classList.contains('rating-value')) {
                input.value = '0';
            } else {
                input.value = '';
            }
        });
        
        // Clear existing file previews
        var filePreviews = template.querySelectorAll('.custom-file-upload + div');
        filePreviews.forEach(function(el) { el.remove(); });
        
        // Reset file upload labels
        var fileLabels = template.querySelectorAll('.file-label');
        fileLabels.forEach(function(label) {
            label.textContent = 'Click to upload (max 100MB)';
            label.style.color = 'var(--text-muted)';
        });
        
        // Reset star ratings
        var stars = template.querySelectorAll('.star-btn');
        stars.forEach(function(s) { s.classList.remove('active'); });
        
        // Reset toggles
        var toggleLabels = template.querySelectorAll('.toggle-switch .toggle-value-label');
        toggleLabels.forEach(function(l) { 
            if (l.style) l.textContent = 'No';
        });
        
        instancesContainer.appendChild(template);
        
        // Update count
        var countInput = block.querySelector('.repeater-instance-count');
        countInput.value = newIndex + 1;
        var countBadge = block.querySelector('.repeater-count');
        if (countBadge) countBadge.textContent = newIndex + 1;
        
        // Hide add button if at max
        if (newIndex + 1 >= maxInstances) {
            btn.style.display = 'none';
        }
        
        // Scroll to new instance
        template.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    
    // Repeater: Remove an instance
    function removeRepeaterInstance(btn) {
        var instance = btn.closest('.repeater-instance');
        var block = btn.closest('.repeater-block');
        var instancesContainer = block.querySelector('.repeater-instances');
        
        instance.style.transition = 'opacity 0.3s, transform 0.3s';
        instance.style.opacity = '0';
        instance.style.transform = 'translateX(-20px)';
        
        setTimeout(function() {
            instance.remove();
            
            var remaining = instancesContainer.querySelectorAll('.repeater-instance');
            remaining.forEach(function(inst, idx) {
                inst.setAttribute('data-instance', idx);
                
                var label = inst.querySelector('.repeater-instance-label');
                if (label) {
                    label.innerHTML = '<i class="fas fa-layer-group"></i> #' + (idx + 1);
                }
                
                var header = inst.querySelector('.repeater-instance-header');
                var removeBtn = header.querySelector('.repeater-remove-btn');
                if (idx === 0 && removeBtn) {
                    removeBtn.remove();
                } else if (idx > 0 && !removeBtn) {
                    var newRemoveBtn = document.createElement('button');
                    newRemoveBtn.type = 'button';
                    newRemoveBtn.className = 'btn btn-icon btn-ghost repeater-remove-btn';
                    newRemoveBtn.title = 'Remove this entry';
                    newRemoveBtn.innerHTML = '<i class="fas fa-trash-alt" style="color: var(--danger);"></i>';
                    header.appendChild(newRemoveBtn);
                }
                
                var inputs = inst.querySelectorAll('input, textarea, select');
                inputs.forEach(function(input) {
                    var name = input.getAttribute('name');
                    if (!name) return;
                    name = name.replace(/\[\d+\]/, '[' + idx + ']');
                    name = name.replace(/(custom_file_\d+_)\d+/, '$1' + idx);
                    name = name.replace(/(existing_file_\d+_)\d+/, '$1' + idx);
                    input.setAttribute('name', name);
                });
            });
            
            var newCount = remaining.length;
            var countInput = block.querySelector('.repeater-instance-count');
            countInput.value = newCount;
            var countBadge = block.querySelector('.repeater-count');
            if (countBadge) countBadge.textContent = newCount;
            
            var addBtn = block.querySelector('.repeater-add-btn');
            if (addBtn) addBtn.style.display = '';
        }, 300);
    }
    </script>
    <?php
    renderNotifBridgeQuickSend($module, $userId);
}

/**
 * "Send message to phone" panel shown under Custom Fields on every add/edit
 * page: a custom message plus an optional image/document, sent through
 * NotifBridge with fetch() (api-notifbridge-send.php) so the page stays put.
 * Inputs have no name attributes, so the record form never submits them.
 */
function renderNotifBridgeQuickSend($module = '', $userId = null) {
    static $rendered = false;
    if ($rendered || !$userId) return;
    $rendered = true;

    require_once __DIR__ . '/notifbridge_client.php';
    $configured = false;
    try {
        ensureNotifBridgeSchema();
        $configured = getNotifBridgeConnection((int)$userId) !== null;
    } catch (Throwable $e) {
        $configured = false;
    }
    $labels = function_exists('getModuleLabels') ? getModuleLabels() : [];
    $defaultTitle = $labels[$module] ?? ucwords(str_replace('_', ' ', (string)$module));
    ?>
    <details class="nb-quick-send" style="margin: 20px 0 8px; border: 1px solid var(--border); border-radius: 12px; background: var(--bg-soft);">
        <summary style="cursor: pointer; padding: 12px 16px; font-weight: 600; color: var(--accent); list-style: none;">
            <i class="fas fa-mobile-alt"></i> Send message to phone
            <span style="font-weight: 400; color: var(--text-muted); font-size: 0.85rem;"> &middot; text, image or document via NotifBridge</span>
        </summary>
        <div style="padding: 4px 16px 16px;">
        <?php if (!$configured): ?>
            <p style="margin: 0; color: var(--text-secondary);">
                NotifBridge isn't set up yet.
                <a href="<?php echo url('notifications.php?tab=config'); ?>">Connect it on the Notifications page</a>, then come back here.
            </p>
        <?php else: ?>
            <div class="form-row">
                <div class="form-group">
                    <label>Title</label>
                    <div class="input-wrapper no-icon">
                        <input type="text" class="nb-title" value="<?php echo htmlspecialchars($defaultTitle); ?>" maxlength="200" style="padding-left: 16px;">
                    </div>
                </div>
                <div class="form-group">
                    <label>Send to</label>
                    <div class="input-wrapper no-icon">
                        <select class="nb-device"><option value="all">All devices</option></select>
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label>Message</label>
                <div class="input-wrapper no-icon">
                    <textarea class="nb-body" rows="3" placeholder="Type the message to send to your phone" style="padding-left: 16px;"></textarea>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Attachment <span style="color: var(--text-muted); font-weight: 400;">(optional image or document, max <?php echo NOTIFBRIDGE_MAX_ATTACHMENT_MB; ?> MB)</span></label>
                    <input type="file" class="nb-file" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip">
                </div>
                <div class="form-group">
                    <label>Priority</label>
                    <div class="input-wrapper no-icon">
                        <select class="nb-priority"><option value="default">Normal</option><option value="high">High</option></select>
                    </div>
                </div>
            </div>
            <label style="display: flex; align-items: center; gap: 8px; margin: 4px 0 12px; font-weight: 400;">
                <input type="checkbox" class="nb-include"> Include this record's custom field values in the message
            </label>
            <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                <button type="button" class="btn btn-primary nb-send"><i class="fas fa-paper-plane"></i> Send to phone</button>
                <span class="nb-status" style="font-size: 0.9rem;"></span>
            </div>
        <?php endif; ?>
        </div>
    </details>
    <?php if ($configured): ?>
    <script>
    (function () {
        var panel = document.currentScript.previousElementSibling;
        var endpoint = <?php echo json_encode(url('api-notifbridge-send.php')); ?>;
        var csrf = <?php echo json_encode(Auth::generateCSRF()); ?>;
        var module = <?php echo json_encode((string)$module); ?>;
        var maxBytes = <?php echo NOTIFBRIDGE_MAX_ATTACHMENT_MB; ?> * 1024 * 1024;
        var q = function (sel) { return panel.querySelector(sel); };
        var devicesLoaded = false;

        function setStatus(text, ok) {
            var s = q('.nb-status');
            s.textContent = text;
            s.style.color = ok === true ? 'var(--success, #16a34a)' : ok === false ? 'var(--danger, #dc2626)' : 'var(--text-muted)';
        }

        panel.addEventListener('toggle', function () {
            if (!panel.open || devicesLoaded) return;
            devicesLoaded = true;
            fetch(endpoint + '?action=devices', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.success) return;
                    var sel = q('.nb-device');
                    res.devices.forEach(function (d) {
                        var o = document.createElement('option');
                        o.value = d.id; o.textContent = d.label; sel.appendChild(o);
                    });
                }).catch(function () {});
        });

        // Label/value pairs of this form's custom fields (skips files and passwords).
        function fieldValues() {
            var form = panel.closest('form') || document;
            var seen = {}, lines = [];
            form.querySelectorAll('[name^="custom_field_"]').forEach(function (el) {
                if (el.type === 'file' || el.type === 'hidden' || el.type === 'password') return;
                if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) return;
                var val = el.multiple ? Array.from(el.selectedOptions).map(function (o) { return o.value; }).join(', ') : (el.value || '');
                if (!val.trim()) return;
                var group = el.closest('.form-group');
                var label = group && group.querySelector('label') ? group.querySelector('label').textContent.replace('*', '').trim() : el.name;
                var key = el.name.replace(/\[\]$/, '');
                if (seen[key]) { seen[key].value += ', ' + val; return; }
                seen[key] = { label: label, value: val };
                lines.push(seen[key]);
            });
            return lines.map(function (l) { return l.label + ': ' + l.value; });
        }

        q('.nb-send').addEventListener('click', function () {
            var btn = this;
            var file = q('.nb-file').files[0];
            var body = q('.nb-body').value.trim();
            if (q('.nb-include').checked) {
                var lines = fieldValues();
                if (lines.length) body = (body ? body + '\n\n' : '') + lines.join('\n');
            }
            if (!body && !file) { setStatus('Write a message or attach a file.', false); return; }
            if (file && file.size > maxBytes) { setStatus('That file is too large.', false); return; }

            var form = panel.closest('form');
            var idInput = form && form.querySelector('input[name="id"], input[name="edit_id"], input[name="record_id"]');
            var fd = new FormData();
            fd.append('action', 'send');
            fd.append('csrf_token', csrf);
            fd.append('title', q('.nb-title').value.trim());
            fd.append('body', body);
            fd.append('priority', q('.nb-priority').value);
            fd.append('device_id', q('.nb-device').value);
            fd.append('module', module);
            if (idInput && idInput.value) fd.append('record_id', idInput.value);
            if (file) fd.append('attachment', file);

            btn.disabled = true;
            setStatus(file ? 'Uploading file and sending…' : 'Sending…');
            fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json().catch(function () { return { success: false, error: 'Server error (' + r.status + ')' }; }); })
                .then(function (res) {
                    btn.disabled = false;
                    if (res.success) {
                        setStatus('✓ ' + res.message, true);
                        q('.nb-body').value = '';
                        q('.nb-file').value = '';
                        if (window.showToast) showToast('Sent to your phone', 'success');
                    } else {
                        setStatus(res.error || res.message || 'Failed to send.', false);
                    }
                })
                .catch(function () { btn.disabled = false; setStatus('Network error. Check your connection.', false); });
        });
    })();
    </script>
    <?php endif;
}

/**
 * "Send to phone (Send now)" field: a button that sends the configured fields
 * (with their images/documents) via api-field-send.php. The map tells the
 * browser which form input holds each selected field's current value.
 */
function renderSendNowButton(array $field, $instance = null): void {
    require_once __DIR__ . '/field_send_helper.php';
    $cfg = fsParseConfig($field['field_options'] ?? null);
    $inst = $instance === null ? null : (int)$instance;
    $customByName = [];
    foreach (getCustomFieldsFor($field['linked_to'], (int)$field['user_id']) as $f) $customByName[$f['field_name']] = $f;

    $map = [];
    foreach ($cfg['fields'] as $key) {
        if (strpos($key, FS_CUSTOM_PREFIX) === 0) {
            $f = $customByName[substr($key, strlen(FS_CUSTOM_PREFIX))] ?? null;
            if (!$f) continue;
            $isFile = in_array($f['field_type'], FS_FILE_TYPES, true);
            $sameBlock = $inst !== null && (int)$f['block_id'] === (int)$field['block_id'];
            if ($isFile) {
                $names = $sameBlock ? ['custom_file_' . $f['id'] . '_' . $inst, 'custom_field_' . $f['id']] : ['custom_field_' . $f['id']];
            } else {
                $names = $sameBlock ? ['custom_field_' . $f['id'] . '[' . $inst . ']'] : ['custom_field_' . $f['id'], 'custom_field_' . $f['id'] . '[0]'];
            }
            $map[] = ['key' => $key, 'names' => $names, 'file' => $isFile];
        } else {
            $map[] = ['key' => $key, 'names' => [$key], 'file' => $key === 'file_path'];
        }
    }
    $hint = trim($field['field_placeholder'] ?? '') ?: 'Sends ' . count($cfg['fields']) . ' selected field(s) to your phone';
    ?>
    <div class="send-now-field" data-field-id="<?php echo (int)$field['id']; ?>" data-instance="<?php echo (int)($inst ?? 0); ?>"
         data-map="<?php echo htmlspecialchars(json_encode($map), ENT_QUOTES); ?>"
         style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; padding:4px 0;">
        <button type="button" class="btn btn-primary send-now-btn"><i class="fas fa-paper-plane"></i> Send now</button>
        <span class="send-now-status" style="font-size:0.84rem; color:var(--text-muted);"><?php echo htmlspecialchars($hint); ?><?php echo $cfg['pin'] ? ' · <i class="fas fa-lock"></i> PIN' : ''; ?></span>
    </div>
    <?php
    renderSendNowAssets();
}

/** Preview dialog + script for every Send now button on the page (printed once). */
function renderSendNowAssets(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    ?>
    <script>
    (function () {
        var endpoint = <?php echo json_encode(url('api-field-send.php')); ?>;
        var csrf = <?php echo json_encode(Auth::generateCSRF()); ?>;

        function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
        function fmtSize(n) { return n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }
        function ago(ts) { var m = Math.round((Date.now() / 1000 - ts) / 60); return m < 1 ? 'just now' : m < 60 ? m + ' min ago' : m < 1440 ? Math.round(m / 60) + ' h ago' : Math.round(m / 1440) + ' d ago'; }

        // Current on-screen values + newly picked files for this button's selected fields.
        function collect(box, fd) {
            var form = box.closest('form') || document;
            var map = JSON.parse(box.dataset.map || '[]');
            var values = {}, uploadKeys = {}, n = 0;
            map.forEach(function (m) {
                for (var i = 0; i < m.names.length; i++) {
                    var name = m.names[i];
                    var els = form.querySelectorAll('[name="' + name + '"], [name="' + name + '[]"]');
                    if (!els.length) continue;
                    if (m.file) {
                        var fileEl = Array.prototype.find.call(els, function (e) { return e.type === 'file' && e.files && e.files[0]; });
                        if (fileEl) { var key = 'upload_' + (n++); fd.append(key, fileEl.files[0]); uploadKeys[m.key] = key; }
                        break;
                    }
                    var parts = [];
                    els.forEach(function (e) {
                        if (e.type === 'file' || e.type === 'password') return;
                        if ((e.type === 'checkbox' || e.type === 'radio') && !e.checked) return;
                        if (e.multiple) { Array.prototype.forEach.call(e.selectedOptions, function (o) { parts.push(o.value); }); return; }
                        parts.push(e.value);
                    });
                    values[m.key] = parts.join(', ');
                    break;
                }
            });
            fd.append('values', JSON.stringify(values));
            fd.append('upload_keys', JSON.stringify(uploadKeys));
        }

        function baseForm(box, action) {
            var fd = new FormData();
            var form = box.closest('form');
            var idInput = form && form.querySelector('input[name="id"], input[name="edit_id"], input[name="record_id"]');
            fd.append('csrf_token', csrf);
            fd.append('action', action);
            fd.append('field_id', box.dataset.fieldId);
            fd.append('instance', box.dataset.instance || '0');
            fd.append('record_id', idInput && idInput.value ? idInput.value : '0');
            collect(box, fd);
            return fd;
        }

        function post(fd) {
            return fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json().catch(function () { return { success: false, error: 'Server error (' + r.status + ')' }; }); });
        }

        function setStatus(box, text, ok) {
            var s = box.querySelector('.send-now-status');
            s.textContent = text;
            s.style.color = ok === true ? 'var(--success, #16a34a)' : ok === false ? 'var(--danger, #dc2626)' : 'var(--text-muted)';
        }

        function openDialog(box, p, mask) {
            var old = document.getElementById('sendNowOverlay'); if (old) old.remove();
            var ov = document.createElement('div');
            ov.id = 'sendNowOverlay';
            ov.className = 'msgw-overlay';
            var fileIcon = function (n) {
                var e = (n.split('.').pop() || '').toLowerCase();
                return /^(png|jpe?g|gif|webp|bmp|svg)$/.test(e) ? 'fa-image' : e === 'pdf' ? 'fa-file-pdf' : /^(xlsx?|csv)$/.test(e) ? 'fa-file-excel' : /^(docx?|rtf|txt)$/.test(e) ? 'fa-file-word' : 'fa-file';
            };
            var files = (p.files || []).map(function (f) {
                return '<label class="msgw-file"><input type="checkbox" class="sn-file" value="' + esc(f.name) + '" checked>'
                     + '<span class="f-ic"><i class="fas ' + fileIcon(f.name) + '"></i></span>'
                     + '<span style="min-width:0"><span class="f-name">' + esc(f.name) + '</span><br><span class="f-meta">' + esc(f.label) + ' · ' + fmtSize(f.size) + '</span></span></label>';
            }).join('');
            var devices = '<option value="all">All devices</option>' + (p.devices || []).map(function (d) { return '<option value="' + d.id + '">' + esc(d.label) + '</option>'; }).join('');
            var preview = p.confirm !== false;
            var nFiles = (p.files || []).length;
            ov.innerHTML =
                '<div class="msgw" role="dialog" aria-modal="true" aria-label="Send to phone">'
              + '<div class="msgw-head"><span class="msgw-icon"><i class="fas fa-paper-plane"></i></span>'
              + '<div><h3>Send to phone</h3><p>Check it, edit it, then send. ' + (p.last_sent ? 'Last sent ' + ago(p.last_sent.at) + '.' : '') + '</p></div>'
              + '<button type="button" class="msgw-x sn-close" aria-label="Close"><i class="fas fa-times"></i></button></div>'
              + '<div class="msgw-body">'
              + (p.cooldown_left > 0 ? '<div class="msgw-warn"><i class="fas fa-hourglass-half"></i> Just sent — you can send again in ' + p.cooldown_left + ' s.</div>' : '')
              + '<div class="msgw-phone"><div class="msgw-notif"><div class="n-top"><span class="n-app"></span> NotifBridge · now'
              + '<span class="sn-prio-chip" style="margin-left:auto;color:#e11d48;font-weight:800;' + (p.priority === 'high' ? '' : 'display:none') + '">HIGH</span></div>'
              + '<div class="n-title sn-pv-title"></div><div class="n-body sn-pv-body"></div>'
              + (nFiles ? '<div class="n-top" style="margin-top:6px;color:#4f46e5"><i class="fas fa-paperclip"></i> <span class="sn-pv-files">' + nFiles + '</span> file(s)</div>' : '')
              + '</div></div>'
              + (preview
                  ? '<div><span class="msgw-label">Title</span><input type="text" class="sn-title" maxlength="200" value="' + esc(p.title) + '"></div>'
                  + '<div><span class="msgw-label">Message</span><textarea class="sn-body" rows="8">' + esc(p.body) + '</textarea></div>'
                  + '<label class="msgw-switch"><input type="checkbox" class="sn-mask"' + (mask ? ' checked' : '') + '> Hide sensitive numbers (show last 4 digits only)</label>'
                  + (nFiles ? '<div><span class="msgw-label">Attachments</span><div class="msgw-files">' + files + '</div></div>' : '')
                  : '')
              + '<div class="msgw-row"><div><span class="msgw-label">Send to</span><select class="sn-device">' + devices + '</select></div>'
              + '<div><span class="msgw-label">Priority</span><div class="msgw-seg">'
              + '<label><input type="radio" name="sn-prio" value="default"' + (p.priority !== 'high' ? ' checked' : '') + '>Normal</label>'
              + '<label><input type="radio" name="sn-prio" value="high"' + (p.priority === 'high' ? ' checked' : '') + '><i class="fas fa-bolt"></i> High</label></div></div></div>'
              + (p.needs_pin ? '<div class="msgw-pinbox"><span class="msgw-label"><i class="fas fa-lock"></i> Safety PIN — required to send</span>'
                  + '<input type="password" class="sn-pin msgw-pin" inputmode="numeric" maxlength="8" autocomplete="off" placeholder="••••"></div>' : '')
              + ((p.skipped || []).length ? '<div class="msgw-note"><i class="fas fa-circle-info"></i><span><b>Not included:</b> ' + p.skipped.map(esc).join('; ') + '</span></div>' : '')
              + '<div class="msgw-note"><i class="fas fa-shield-halved"></i><span>Files are kept on your NotifBridge server for 30 days, reachable only through their private links. This send is recorded in the Activity Log.</span></div>'
              + '<div class="msgw-error sn-error" role="alert"></div>'
              + '</div><div class="msgw-foot">'
              + '<span class="msgw-chip"><i class="fas fa-mobile-screen"></i> <span class="sn-dev-label">All devices</span></span><span class="spacer"></span>'
              + '<button type="button" class="btn btn-secondary sn-close">Cancel</button>'
              + '<button type="button" class="btn btn-primary sn-send"' + (p.cooldown_left > 0 ? ' disabled' : '') + '><i class="fas fa-paper-plane"></i> Send now</button></div></div>';
            document.body.appendChild(ov);
            document.body.style.overflow = 'hidden';
            var close = function () { ov.remove(); document.body.style.overflow = ''; document.removeEventListener('keydown', onKey); };
            var onKey = function (e) { if (e.key === 'Escape') close(); };
            document.addEventListener('keydown', onKey);
            ov.querySelectorAll('.sn-close').forEach(function (b) { b.onclick = close; });
            ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
            // live lock-screen preview
            var tIn = ov.querySelector('.sn-title'), bIn = ov.querySelector('.sn-body');
            var syncPreview = function () {
                ov.querySelector('.sn-pv-title').textContent = tIn ? tIn.value : p.title;
                ov.querySelector('.sn-pv-body').textContent = bIn ? bIn.value : p.body;
                var pf = ov.querySelector('.sn-pv-files');
                if (pf) pf.textContent = ov.querySelectorAll('.sn-file:checked').length || (preview ? 0 : nFiles);
                var hi = ov.querySelector('input[name="sn-prio"]:checked').value === 'high';
                ov.querySelector('.sn-prio-chip').style.display = hi ? '' : 'none';
                var dev = ov.querySelector('.sn-device');
                ov.querySelector('.sn-dev-label').textContent = dev.options[dev.selectedIndex].text;
            };
            ov.addEventListener('input', syncPreview);
            ov.addEventListener('change', syncPreview);
            syncPreview();
            var maskEl = ov.querySelector('.sn-mask');
            if (maskEl) maskEl.onchange = function () { preview_(box, maskEl.checked); };
            var pin = ov.querySelector('.sn-pin');
            if (pin) {
                setTimeout(function () { pin.focus({ preventScroll: true }); }, 60); // keep the live preview in view
                pin.addEventListener('keydown', function (e) { if (e.key === 'Enter') ov.querySelector('.sn-send').click(); });
            }
            ov.querySelector('.sn-send').onclick = function () {
                var btn = this, err = ov.querySelector('.sn-error');
                var fd = baseForm(box, 'send');
                if (preview) {
                    fd.append('title', ov.querySelector('.sn-title').value);
                    fd.append('body', ov.querySelector('.sn-body').value);
                    fd.append('mask', ov.querySelector('.sn-mask').checked ? '1' : '0');
                    fd.append('keep_files', JSON.stringify(Array.prototype.filter.call(ov.querySelectorAll('.sn-file'), function (c) { return c.checked; }).map(function (c) { return c.value; })));
                }
                fd.append('device_id', ov.querySelector('.sn-device').value);
                fd.append('priority', ov.querySelector('input[name="sn-prio"]:checked').value);
                if (pin) {
                    if (!pin.value.trim()) { err.textContent = 'Enter your Safety PIN.'; return; }
                    fd.append('pin', pin.value);
                }
                btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…'; err.textContent = '';
                post(fd).then(function (res) {
                    if (res.success) {
                        close();
                        setStatus(box, '✓ ' + res.message, true);
                        if (window.showToast) showToast(res.message, 'success');
                    } else {
                        btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send now';
                        err.textContent = res.error || 'Send failed.';
                        if (pin && res.needs_pin) { pin.value = ''; pin.focus(); }
                    }
                }).catch(function () { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send now'; err.textContent = 'Network error.'; });
            };
        }

        function preview_(box, mask) {
            var fd = baseForm(box, 'preview');
            if (mask !== undefined) fd.append('mask', mask ? '1' : '0');
            setStatus(box, 'Preparing…');
            post(fd).then(function (p) {
                if (!p.success) { setStatus(box, p.error || 'Failed.', false); return; }
                setStatus(box, p.last_sent ? 'Last sent ' + ago(p.last_sent.at) + ' · ' + p.last_sent.count + ' time(s)' : 'Ready');
                openDialog(box, p, mask !== undefined ? mask : p.mask);
            }).catch(function () { setStatus(box, 'Network error.', false); });
        }

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.send-now-btn');
            if (!btn) return;
            e.preventDefault();
            preview_(btn.closest('.send-now-field'));
        });
    })();
    </script>
    <?php
}

/**
 * Render a single field input (extracted for reuse in block and non-block rendering)
 * $instance: null = non-repeatable, 0+ = instance index for repeatable blocks
 */
function renderSingleFieldInput($field, $fieldValue, $options, $instance = null) {
    $isRepeater = $instance !== null;
    // Ensure field_type is never empty
    if (empty($field['field_type'])) $field['field_type'] = 'text';
    // Field naming: normal = custom_field_{id}, repeater = custom_field_{id}[{instance}]
    $fieldName = 'custom_field_' . $field['id'];
    $fieldNameAttr = $isRepeater ? $fieldName . '[' . $instance . ']' : $fieldName;
    // For file uploads in repeaters, use distinct flat names
    $fileFieldName = $isRepeater ? 'custom_file_' . $field['id'] . '_' . $instance : $fieldName;
    // Unique ID suffix for elements that need unique IDs
    $idSuffix = $isRepeater ? '_' . $field['id'] . '_' . $instance : '_' . $field['id'];
    
    switch ($field['field_type']) {
        case 'send_now':
            renderSendNowButton($field, $instance);
            break;

        case 'textarea':
            echo '<textarea name="' . $fieldNameAttr . '"
                    placeholder="' . htmlspecialchars($field['field_placeholder'] ?? '') . '"
                    ' . ($field['is_required'] ? 'required' : '') . '
                    style="padding-left: 16px;">' . htmlspecialchars($fieldValue) . '</textarea>';
            break;

        case 'select':
            echo '<select name="' . $fieldNameAttr . '" ' . ($field['is_required'] ? 'required' : '') . '>';
            echo '<option value="">Select...</option>';
            if (is_array($options)) {
                foreach ($options as $opt) {
                    $selected = $fieldValue === $opt ? 'selected' : '';
                    echo '<option value="' . htmlspecialchars($opt) . '" ' . $selected . '>' . htmlspecialchars($opt) . '</option>';
                }
            }
            echo '</select>';
            break;

        case 'multiselect':
            echo '<select name="' . $fieldNameAttr . '[]" multiple ' . ($field['is_required'] ? 'required' : '') . ' style="height: auto; min-height: 80px;">';
            $selectedValues = $fieldValue ? explode(',', $fieldValue) : [];
            if (is_array($options)) {
                foreach ($options as $opt) {
                    $selected = in_array($opt, $selectedValues) ? 'selected' : '';
                    echo '<option value="' . htmlspecialchars($opt) . '" ' . $selected . '>' . htmlspecialchars($opt) . '</option>';
                }
            }
            echo '</select>';
            echo '<small style="color:var(--text-muted); font-size:0.75rem;">Hold Ctrl/Cmd to select multiple</small>';
            break;

        case 'checkbox':
            if (is_array($options)) {
                foreach ($options as $opt) {
                    $checked = in_array($opt, explode(',', $fieldValue)) ? 'checked' : '';
                    echo '<label class="form-check" style="margin-bottom: 8px;">
                            <input type="checkbox" name="' . $fieldNameAttr . '[]" value="' . htmlspecialchars($opt) . '" ' . $checked . '>
                            <span class="checkmark"></span>
                            <span class="form-check-label">' . htmlspecialchars($opt) . '</span>
                          </label>';
                }
            }
            break;

        case 'radio':
            if (is_array($options)) {
                $radioName = $fieldNameAttr;
                foreach ($options as $opt) {
                    $checked = $fieldValue === $opt ? 'checked' : '';
                    echo '<label class="form-check" style="margin-bottom: 8px;">
                            <input type="radio" name="' . $radioName . '" value="' . htmlspecialchars($opt) . '" ' . $checked . '>
                            <span class="checkmark"></span>
                            <span class="form-check-label">' . htmlspecialchars($opt) . '</span>
                          </label>';
                }
            }
            break;

        case 'toggle':
            $isOn = $fieldValue === '1';
            echo '<label class="toggle-switch">
                    <input type="checkbox" name="' . $fieldNameAttr . '" value="1" ' . ($isOn ? 'checked' : '') . '>
                    <span class="toggle-slider"></span>
                                        <span class="toggle-value-label" style="margin-left: 60px; font-size: 0.85rem;">' . ($isOn ? 'Yes' : 'No') . '</span>
                  </label>';
            break;

        case 'rating':
            $currentRating = (int)$fieldValue;
            $ratingId = 'rating' . $idSuffix;
            echo '<div class="star-rating" data-field="' . $fieldNameAttr . '">';
            echo '<input type="hidden" name="' . $fieldNameAttr . '" value="' . $currentRating . '" class="rating-value">';
            for ($i = 1; $i <= 5; $i++) {
                $active = $i <= $currentRating ? ' active' : '';
                echo '<i class="fas fa-star star-btn' . $active . '" data-value="' . $i . '" 
                         style="font-size: 1.4rem; cursor: pointer; transition: color 0.2s;"></i> ';
            }
            echo '</div>';
            // setRating is attached via event delegation in the global script block below
            break;

        case 'range':
            $rangeVal = $fieldValue !== '' ? (int)$fieldValue : 50;
            echo '<div style="display:flex; align-items:center; gap:12px;">
                    <input type="range" name="' . $fieldNameAttr . '" min="0" max="100" value="' . $rangeVal . '" 
                           class="cf-range-input" style="flex:1;">
                    <span style="min-width:32px; font-size:0.9rem; font-weight:600; color:var(--primary);">' . $rangeVal . '</span>
                  </div>';
            break;

        case 'currency':
            echo '<div style="display:flex; align-items:stretch;">
                    <span style="padding:10px 12px; background:var(--bg-tertiary); border-radius:8px 0 0 8px; border:1px solid var(--border); border-right:none; font-weight:600; color:var(--text-muted); display:flex; align-items:center;">₹</span>
                    <input type="number" step="0.01" name="' . $fieldNameAttr . '" 
                           placeholder="' . htmlspecialchars($field['field_placeholder'] ?? '0.00') . '"
                           value="' . htmlspecialchars($fieldValue) . '"
                           ' . ($field['is_required'] ? 'required' : '') . '
                           style="border-radius:0 8px 8px 0; border-left:none;">
                  </div>';
            break;

        case 'password':
            $pwId = 'pwField' . $idSuffix;
            echo '<div style="position:relative; display:flex; align-items:center;">
                    <input type="text" name="' . $fieldNameAttr . '" 
                           class="cf-pw-field"
                           placeholder="' . htmlspecialchars($field['field_placeholder'] ?? 'Enter value...') . '"
                           value="' . htmlspecialchars($fieldValue) . '"
                           ' . ($field['is_required'] ? 'required' : '') . '
                           id="' . $pwId . '"
                           style="-webkit-text-security:disc; -moz-text-security:disc; text-security:disc; padding-right:40px;">
                    <span class="cf-pw-toggle" data-target="' . $pwId . '" tabindex="0" role="button" aria-label="Toggle password visibility"><i class="fas fa-eye"></i></span>
                  </div>';
            break;

        case 'time':
            echo '<input type="time" name="' . $fieldNameAttr . '" 
                    value="' . htmlspecialchars($fieldValue) . '"
                    ' . ($field['is_required'] ? 'required' : '') . '>';
            break;

        case 'datetime':
            echo '<input type="datetime-local" name="' . $fieldNameAttr . '"
                    value="' . htmlspecialchars($fieldValue) . '"
                    ' . ($field['is_required'] ? 'required' : '') . '>';
            break;

        case 'reminder':
            echo '<input type="datetime-local" name="' . $fieldNameAttr . '"
                    value="' . htmlspecialchars($fieldValue) . '">';
            echo '<small style="display:block;margin-top:6px;color:var(--text-muted);font-size:0.78rem;">
                    <i class="fas fa-bell"></i> Pick when to be reminded. Leave blank to disable this reminder.
                  </small>';
            break;

        // File upload types
        case 'file':
        case 'image':
        case 'pdf':
        case 'document':
        case 'spreadsheet':
            $accept = getAcceptAttribute($field['field_type']);
            $iconMap = ['file' => 'fa-cloud-upload-alt', 'image' => 'fa-image', 'pdf' => 'fa-file-pdf', 'document' => 'fa-file-word', 'spreadsheet' => 'fa-file-excel'];
            $colorMap = ['file' => 'var(--primary)', 'image' => '#2ecc71', 'pdf' => '#e74c3c', 'document' => '#2b579a', 'spreadsheet' => '#217346'];
            $icon = $iconMap[$field['field_type']] ?? 'fa-file';
            $color = $colorMap[$field['field_type']] ?? 'var(--primary)';
            
            echo '<div class="custom-file-upload" style="border:2px dashed var(--border); border-radius:8px; padding:16px; text-align:center; cursor:pointer; transition: border-color 0.3s;">';
            echo '<input type="file" name="' . $fileFieldName . '" accept="' . $accept . '" 
                         style="display:none;" class="cf-file-input" ' . ($field['is_required'] && !$fieldValue ? 'required' : '') . '>';
            echo '<i class="fas ' . $icon . '" style="font-size:1.5rem; color:' . $color . ';"></i>';
            echo '<p class="file-label" style="margin:4px 0 0; font-size:0.82rem; color:var(--text-muted);">Click to upload (max 100MB)</p>';
            echo '</div>';
            
            if ($fieldValue) {
                // Hidden input to preserve existing file for repeater
                if ($isRepeater) {
                    echo '<input type="hidden" name="existing_file_' . $field['id'] . '_' . $instance . '" value="' . htmlspecialchars($fieldValue) . '">';
                }
                
                if (isCustomFieldGDriveFile($fieldValue)) {
                    // Google Drive file
                    $gd = parseGDrivePath($fieldValue);
                    $downloadUrl = url('download-custom-file.php') . '?gdrive=' . urlencode($gd['id']) . '&name=' . urlencode($gd['name']);
                    $previewUrl = htmlspecialchars($downloadUrl . '&preview=1');
                    $dlUrl = htmlspecialchars($downloadUrl);
                    $safeName = htmlspecialchars($gd['name'], ENT_QUOTES);
                    echo '<div style="margin-top:8px; font-size:0.78rem; color:var(--text-muted);">
                            <i class="fas fa-cloud" style="color:#4285f4;"></i>
                            <i class="fas ' . $icon . '" style="color:' . $color . ';"></i> 
                            Current: <a href="#" onclick="openFileViewer(\'' . $previewUrl . '\', \'' . $dlUrl . '\', \'' . $safeName . '\'); return false;" style="color:var(--primary); cursor:pointer;">' . htmlspecialchars($gd['name']) . '</a>
                            <span style="color:#4285f4; font-size:0.7rem;">(Google Drive)</span>
                          </div>';
                } else {
                    // Local file
                    $fileName = basename($fieldValue);
                    $localPreviewUrl = url('download-custom-file.php') . '?local=' . urlencode($fieldValue) . '&preview=1';
                    $localDlUrl = url('download-custom-file.php') . '?local=' . urlencode($fieldValue);
                    if ($field['field_type'] === 'image' && file_exists(__DIR__ . '/../' . $fieldValue)) {
                        echo '<div style="margin-top:8px; display:flex; align-items:center; gap:8px; cursor:pointer;" onclick="openFileViewer(\'' . htmlspecialchars($localPreviewUrl, ENT_QUOTES) . '\', \'' . htmlspecialchars($localDlUrl, ENT_QUOTES) . '\', \'' . htmlspecialchars($fileName, ENT_QUOTES) . '\')">
                                <img src="/' . htmlspecialchars($fieldValue) . '" style="width:60px; height:60px; object-fit:cover; border-radius:6px; border:1px solid var(--border);">
                                <span style="font-size:0.78rem; color:var(--text-muted);">' . htmlspecialchars($fileName) . '</span>
                              </div>';
                    } else {
                        echo '<div style="margin-top:8px; font-size:0.78rem; color:var(--text-muted);">
                                <i class="fas ' . $icon . '" style="color:' . $color . ';"></i> 
                                Current: <a href="#" onclick="openFileViewer(\'' . htmlspecialchars($localPreviewUrl, ENT_QUOTES) . '\', \'' . htmlspecialchars($localDlUrl, ENT_QUOTES) . '\', \'' . htmlspecialchars($fileName, ENT_QUOTES) . '\'); return false;" style="color:var(--primary); cursor:pointer;">' . htmlspecialchars($fileName) . '</a>
                              </div>';
                    }
                }
            }
            break;

        default:
            $inputType = $field['field_type'];
            if ($inputType === 'phone') $inputType = 'tel';
            echo '<input type="' . $inputType . '" 
                    name="' . $fieldNameAttr . '"
                    placeholder="' . htmlspecialchars($field['field_placeholder'] ?? '') . '"
                    value="' . htmlspecialchars($fieldValue) . '"
                    ' . ($field['is_required'] ? 'required' : '') . '>';
            break;
    }
}

/**
 * Render custom field values for display (view mode) -- the read-only
 * counterpart to renderCustomFields()'s add/edit form. Fields that belong to
 * a Field Block (see field-blocks.php) are grouped into the same
 * .custom-field-block / .repeater-instance markup the edit form uses (with
 * "#1 / #2 / ..." instance labels for repeatable blocks), just with static
 * values instead of input widgets, so a record's View modal visually matches
 * its Add/Edit form instead of dumping every value into one flat list.
 */
function renderCustomFieldValues($module, $recordId) {
    $db = Database::getInstance();
    $rows = $db->fetchAll(
        "SELECT cf.id as field_id, cf.field_label, cf.field_type, cf.block_id, cf.field_order_in_block,
                cfe.field_value, cfe.block_instance
         FROM custom_field_entries cfe
         JOIN custom_fields cf ON cf.id = cfe.custom_field_id
         WHERE cfe.linked_to = ? AND cfe.record_id = ? AND cfe.field_value != ''
         ORDER BY cf.block_id, cfe.block_instance ASC, cf.field_order_in_block ASC, cf.field_order ASC",
        [$module, $recordId]
    );

    if (empty($rows)) {
        return;
    }

    // Block metadata (label, repeatable?, column layout, display order).
    $blockInfo = [];
    $blockIds = array_unique(array_filter(array_column($rows, 'block_id')));
    if (!empty($blockIds)) {
        $placeholders = implode(',', array_fill(0, count($blockIds), '?'));
        $blockRows = $db->fetchAll(
            "SELECT id, block_label, is_repeatable, layout, block_order FROM custom_field_blocks WHERE id IN ($placeholders)",
            array_values($blockIds)
        );
        foreach ($blockRows as $br) {
            $blockInfo[$br['id']] = $br;
        }
    }

    // Group: [block_id][instance][] = row; fields with no block (or whose
    // block was since deleted) fall back to the plain fields grid.
    $blocked = [];
    $normalRows = [];
    foreach ($rows as $row) {
        $blockId = $row['block_id'];
        if ($blockId && isset($blockInfo[$blockId])) {
            $blocked[$blockId][(int)$row['block_instance']][] = $row;
        } else {
            $normalRows[] = $row;
        }
    }
    uksort($blocked, fn($a, $b) => ($blockInfo[$a]['block_order'] ?? 0) - ($blockInfo[$b]['block_order'] ?? 0));

    $fileTypes = getFileFieldTypes();
    ?>
    <div class="preview-section">
        <div class="preview-section-title"><i class="fas fa-puzzle-piece"></i> Custom Fields</div>

        <?php foreach ($blocked as $blockId => $instances):
            $block = $blockInfo[$blockId];
            $isRepeatable = !empty($block['is_repeatable']);
            $layoutCSS = getBlockLayoutCSS($block['layout']);
            ksort($instances);
        ?>
        <div class="custom-field-block<?php echo $isRepeatable ? ' repeater-block' : ''; ?>" style="margin-bottom: 20px;">
            <div class="custom-block-header">
                <span><i class="fas fa-<?php echo $isRepeatable ? 'clone' : 'th-large'; ?>"></i> <?php echo htmlspecialchars($block['block_label']); ?></span>
                <?php if ($isRepeatable): ?>
                <span class="repeater-count-badge"><?php echo count($instances); ?> <?php echo count($instances) === 1 ? 'entry' : 'entries'; ?></span>
                <?php endif; ?>
            </div>
            <?php if ($isRepeatable): ?>
            <div class="repeater-instances">
                <?php foreach ($instances as $instIdx => $instRows): ?>
                <div class="repeater-instance">
                    <div class="repeater-instance-header">
                        <span class="repeater-instance-label"><i class="fas fa-layer-group"></i> #<?php echo $instIdx + 1; ?></span>
                    </div>
                    <div class="custom-block-body" style="display: grid; <?php echo $layoutCSS; ?> gap: 16px; padding: 0;">
                        <?php foreach ($instRows as $row): ?>
                        <div class="preview-field">
                            <div class="preview-field-label"><?php echo htmlspecialchars($row['field_label']); ?></div>
                            <div class="preview-field-value"><?php renderFieldValueDisplay($row, $fileTypes); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="custom-block-body" style="display: grid; <?php echo $layoutCSS; ?> gap: 16px;">
                <?php foreach (($instances[0] ?? []) as $row): ?>
                <div class="preview-field">
                    <div class="preview-field-label"><?php echo htmlspecialchars($row['field_label']); ?></div>
                    <div class="preview-field-value"><?php renderFieldValueDisplay($row, $fileTypes); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php if (!empty($normalRows)): ?>
        <div class="preview-fields-grid">
            <?php foreach ($normalRows as $row): ?>
            <div class="preview-field<?php echo (in_array($row['field_type'], $fileTypes) || strlen($row['field_value']) > 60) ? ' full-width' : ''; ?>">
                <div class="preview-field-label"><?php echo htmlspecialchars($row['field_label']); ?></div>
                <div class="preview-field-value"><?php renderFieldValueDisplay($row, $fileTypes); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Render a single field value for display
 */
function renderFieldValueDisplay($row, $fileTypes) {
    if (in_array($row['field_type'], $fileTypes)) {
        if (isCustomFieldGDriveFile($row['field_value'])) {
            // Google Drive file
            $gd = parseGDrivePath($row['field_value']);
            $downloadUrl = url('download-custom-file.php') . '?gdrive=' . urlencode($gd['id']) . '&name=' . urlencode($gd['name']);
            $previewUrl = htmlspecialchars($downloadUrl . '&preview=1', ENT_QUOTES);
            $dlUrl = htmlspecialchars($downloadUrl, ENT_QUOTES);
            $safeName = htmlspecialchars($gd['name'], ENT_QUOTES);
            echo '<span style="display:inline-flex; align-items:center; gap:4px;">';
            echo '<i class="fas fa-cloud" style="color:#4285f4;"></i> ';
            echo '<a href="#" onclick="openFileViewer(\'' . $previewUrl . '\', \'' . $dlUrl . '\', \'' . $safeName . '\'); return false;" style="color:var(--primary); cursor:pointer;">';
            echo '<i class="fas fa-eye"></i> ' . htmlspecialchars($gd['name']);
            echo '</a>';
            echo '<span style="color:#4285f4; font-size:0.7rem;">(Drive)</span>';
            echo '</span>';
        } else {
            // Local file
            $fileName = basename($row['field_value']);
            $localPreviewUrl = htmlspecialchars(url('download-custom-file.php') . '?local=' . urlencode($row['field_value']) . '&preview=1', ENT_QUOTES);
            $localDlUrl = htmlspecialchars(url('download-custom-file.php') . '?local=' . urlencode($row['field_value']), ENT_QUOTES);
            $safeFileName = htmlspecialchars($fileName, ENT_QUOTES);
            if ($row['field_type'] === 'image') {
                echo '<img src="/' . htmlspecialchars($row['field_value']) . '" style="width:80px; height:80px; object-fit:cover; border-radius:6px; border:1px solid var(--border); cursor:pointer;" onclick="openFileViewer(\'' . $localPreviewUrl . '\', \'' . $localDlUrl . '\', \'' . $safeFileName . '\')">';
                echo '<br>';
            }
            echo '<a href="#" onclick="openFileViewer(\'' . $localPreviewUrl . '\', \'' . $localDlUrl . '\', \'' . $safeFileName . '\'); return false;" style="color:var(--primary); cursor:pointer;">';
            echo '<i class="fas fa-eye"></i> ' . htmlspecialchars($fileName);
            echo '</a>';
        }
    } elseif ($row['field_type'] === 'toggle') {
        echo $row['field_value'] === '1' 
            ? '<span class="badge badge-success">Yes</span>' 
            : '<span class="badge badge-warning">No</span>';
    } elseif ($row['field_type'] === 'rating') {
        $rating = (int)$row['field_value'];
        for ($i = 1; $i <= 5; $i++) {
            echo '<i class="fas fa-star" style="color:' . ($i <= $rating ? '#f5a623' : 'var(--bg-tertiary)') . ';"></i>';
        }
    } elseif ($row['field_type'] === 'color') {
        echo '<span style="display:inline-flex; align-items:center; gap:6px;">';
        echo '<span style="width:18px; height:18px; border-radius:4px; background:' . htmlspecialchars($row['field_value']) . '; border:1px solid var(--border); display:inline-block;"></span>';
        echo htmlspecialchars($row['field_value']);
        echo '</span>';
    } elseif ($row['field_type'] === 'url') {
        echo '<a href="' . htmlspecialchars($row['field_value']) . '" target="_blank" style="color:var(--primary);">';
        echo '<i class="fas fa-external-link-alt"></i> ' . htmlspecialchars($row['field_value']);
        echo '</a>';
    } elseif ($row['field_type'] === 'email') {
        echo '<a href="mailto:' . htmlspecialchars($row['field_value']) . '" style="color:var(--primary);">';
        echo htmlspecialchars($row['field_value']);
        echo '</a>';
    } elseif ($row['field_type'] === 'reminder') {
        $ts = strtotime(str_replace('T', ' ', $row['field_value']));
        echo $ts
            ? '<i class="fas fa-bell" style="color:#f5a623;"></i> ' . htmlspecialchars(date('M d, Y g:i A', $ts))
            : htmlspecialchars($row['field_value']);
    } elseif ($row['field_type'] === 'currency') {
        echo '₹ ' . htmlspecialchars(number_format((float)$row['field_value'], 2));
    } elseif ($row['field_type'] === 'range') {
        echo '<div style="display:flex; align-items:center; gap:8px;">';
        echo '<div style="flex:1; height:6px; background:var(--bg-tertiary); border-radius:3px; overflow:hidden;">';
        echo '<div style="width:' . (int)$row['field_value'] . '%; height:100%; background:var(--primary); border-radius:3px;"></div>';
        echo '</div>';
        echo '<span>' . (int)$row['field_value'] . '%</span>';
        echo '</div>';
    } elseif ($row['field_type'] === 'password') {
        $pwId = 'vpw_' . bin2hex(random_bytes(4));
        echo '<span style="display:inline-flex;align-items:center;gap:8px;width:100%;">';
        echo '<span id="' . $pwId . '_masked" style="letter-spacing:3px;">••••••••</span>';
        echo '<span id="' . $pwId . '_plain" style="display:none;">' . htmlspecialchars($row['field_value']) . '</span>';
        echo '<button type="button" onclick="togglePreviewPassword(\'' . $pwId . '\', this)" ';
        echo 'style="background:none;border:none;color:var(--text-muted);cursor:pointer;padding:2px 4px;margin-left:auto;font-size:0.9rem;" title="Show password">';
        echo '<i class="fas fa-eye"></i></button></span>';
    } else {
        echo htmlspecialchars($row['field_value']);
    }
}

