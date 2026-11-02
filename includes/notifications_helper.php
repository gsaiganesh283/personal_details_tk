<?php
/**
 * Scheduled notifications: custom message templates that fire at a chosen
 * date/time (optionally repeating), delivered in-app and/or via email.
 *
 * Used by:
 *   - cron-send-notifications.php (the actual sender, run on a schedule)
 *
 * The custom-fields.php 'reminder' field type does NOT use this system --
 * see syncReminderFieldEntry() below, which pushes to NotifBridge instead
 * (the same server the desktop app's reminder-type custom fields and
 * reminders.php use). It used to write into scheduled_notifications here,
 * making a "reminder" field on the website a completely different,
 * disconnected thing from the same field type on the desktop app: two
 * separate systems, neither aware of the other, so a value set on one side
 * never showed up on the other and desktop's "Scheduled Reminders" tab
 * never saw anything the website created. Unified onto NotifBridge so the
 * exact same field behaves identically -- and is the exact same reminder --
 * on both.
 */

const NOTIFICATION_REPEAT_TYPES = ['none', 'daily', 'weekly', 'monthly', 'yearly'];

/**
 * Create the scheduled_notifications table if it doesn't exist yet.
 * Safe to call on every request — mirrors the ensureGrowwSchema() pattern.
 */
function ensureNotificationsSchema(): array {
    if (!class_exists('Database')) return [false, 'Database class not loaded'];

    try {
        $db = Database::getInstance();

        if (!dbTableExists('scheduled_notifications')) {
            $db->query(
                "CREATE TABLE scheduled_notifications (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    title VARCHAR(200) NOT NULL,
                    message TEXT NOT NULL,
                    linked_to VARCHAR(50) DEFAULT NULL,
                    custom_field_id INT DEFAULT NULL,
                    record_id INT DEFAULT NULL,
                    block_instance INT NOT NULL DEFAULT 0,
                    template_id INT DEFAULT NULL,
                    channel_inapp TINYINT(1) NOT NULL DEFAULT 1,
                    channel_email TINYINT(1) NOT NULL DEFAULT 1,
                    send_at DATETIME NOT NULL,
                    repeat_type ENUM('none','daily','weekly','monthly','yearly') NOT NULL DEFAULT 'none',
                    repeat_until DATE DEFAULT NULL,
                    status ENUM('pending','sent','cancelled') NOT NULL DEFAULT 'pending',
                    last_sent_at DATETIME DEFAULT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    INDEX idx_due (is_active, send_at),
                    INDEX idx_field_record (custom_field_id, record_id, block_instance)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } elseif (!dbColumnExists('scheduled_notifications', 'template_id')) {
            // Added later — self-heal existing installs the same way
            // custom_fields.field_type was migrated in setup.php.
            $db->getConnection()->exec("ALTER TABLE scheduled_notifications ADD COLUMN template_id INT DEFAULT NULL");
        }

        function ensureDocumentExpiryRemindersSchema(): array {
            if (!class_exists('Database')) return [false, 'Database class not loaded'];
            try {
                $db = Database::getInstance();
                if (!dbColumnExists('documents', 'expiry_reminder_days')) {
                    $db->getConnection()->exec("ALTER TABLE documents ADD COLUMN expiry_reminder_days VARCHAR(200) NULL");
                }
                if (!dbTableExists('document_expiry_reminders')) {
                    $db->query(
                        "CREATE TABLE document_expiry_reminders (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            user_id INT NOT NULL,
                            document_id INT NOT NULL,
                            days_before INT NOT NULL,
                            send_at DATETIME NOT NULL,
                            notifbridge_reminder_id INT DEFAULT NULL,
                            status ENUM('scheduled','pending','failed') NOT NULL DEFAULT 'pending',
                            last_error VARCHAR(300) DEFAULT NULL,
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            UNIQUE KEY uq_document_expiry_day (document_id, days_before),
                            INDEX idx_document_expiry_due (user_id, send_at, status),
                            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
                    );
                }
                return [true, 'ok'];
            } catch (Exception $e) {
                return [false, $e->getMessage()];
            }
        }

        function parseDocumentExpiryReminderDays(?string $value): array {
            $days = [];
            foreach (preg_split('/[,;]+/', (string)$value) as $part) {
                $part = trim($part);
                if ($part !== '' && ctype_digit($part) && (int)$part <= 3650) {
                    $days[(int)$part] = true;
                }
            }
            $days[0] = true;
            $result = array_keys($days);
            rsort($result, SORT_NUMERIC);
            return $result;
        }

        function syncDocumentExpiryReminders(int $userId, int $documentId, ?string $daysText): string {
            [$schemaOk, $schemaMessage] = ensureDocumentExpiryRemindersSchema();
            if (!$schemaOk) return $schemaMessage;

            $db = Database::getInstance();
            $oldRows = $db->fetchAll(
                "SELECT notifbridge_reminder_id FROM document_expiry_reminders WHERE user_id = ? AND document_id = ?",
                [$userId, $documentId]
            );
            $connection = getNotifBridgeConnection($userId);
            if ($connection) {
                foreach ($oldRows as $old) {
                    if (!empty($old['notifbridge_reminder_id'])) {
                        try {
                            notifbridgeDeleteReminder($connection, (int)$old['notifbridge_reminder_id']);
                        } catch (Exception $e) {
                            // The reminder may already have been removed remotely.
                        }
                    }
                }
            }
            $db->delete("DELETE FROM document_expiry_reminders WHERE user_id = ? AND document_id = ?", [$userId, $documentId]);

            $document = $db->fetch(
                "SELECT document_name, expiry_date FROM documents WHERE id = ? AND user_id = ?",
                [$documentId, $userId]
            );
            if (!$document || empty($document['expiry_date']) || !$connection) {
                return $connection ? '' : 'Document saved, but expiry notifications need a NotifBridge connection.';
            }

            $errors = [];
            $now = new DateTime('now', new DateTimeZone('+05:30'));
            try {
                $expiryDate = new DateTime($document['expiry_date'] . ' 09:00', new DateTimeZone('+05:30'));
            } catch (Exception $e) {
                return 'Document saved, but its expiry date is invalid.';
            }
            $template = $db->fetch(
                "SELECT reminder_days FROM notification_templates WHERE user_id = ? AND `key` = 'document_expiry' LIMIT 1",
                [$userId]
            );
            $configuredDays = $template['reminder_days'] ?? $daysText ?: '30, 7, 1';
            foreach (parseDocumentExpiryReminderDays($configuredDays) as $before) {
                $sendAt = clone $expiryDate;
                $sendAt->modify("-{$before} days");
                if ($sendAt <= $now) continue;

                $rowId = (int)$db->insert(
                    "INSERT INTO document_expiry_reminders (user_id, document_id, days_before, send_at) VALUES (?, ?, ?, ?)",
                    [$userId, $documentId, $before, $sendAt->format('Y-m-d H:i:s')]
                );
                try {
                    $when = $before === 0 ? 'today' : ($before === 1 ? 'tomorrow' : "in {$before} days");
                    $result = notifbridgeCreateReminder($connection, [
                        'title' => $document['document_name'] . ' expires ' . $when,
                        'body' => 'Your document "' . $document['document_name'] . '" expires on '
                            . (new DateTime($document['expiry_date']))->format('d M Y') . '. Renew or replace it before then.',
                        'device_ids' => 'all',
                        'priority' => 'high',
                        'channel_push' => true,
                        'send_at' => notifbridgeLocalToUtcIso($sendAt->format('Y-m-d'), '09:00'),
                        'repeat_type' => 'none',
                        'repeat_until' => null,
                        'is_active' => true,
                    ]);
                    $db->query(
                        "UPDATE document_expiry_reminders SET status = 'scheduled', notifbridge_reminder_id = ? WHERE id = ?",
                        [$result['id'] ?? null, $rowId]
                    );
                } catch (Exception $e) {
                    $errors[] = $e->getMessage();
                    $db->query(
                        "UPDATE document_expiry_reminders SET status = 'failed', last_error = ? WHERE id = ?",
                        [substr($e->getMessage(), 0, 300), $rowId]
                    );
                }
            }
            return $errors ? 'Some expiry notifications could not be scheduled: ' . implode('; ', $errors) : '';
        }

        function cancelDocumentExpiryReminders(int $userId, int $documentId): void {
            [$schemaOk] = ensureDocumentExpiryRemindersSchema();
            if (!$schemaOk) return;
            $db = Database::getInstance();
            $connection = getNotifBridgeConnection($userId);
            $rows = $db->fetchAll(
                "SELECT notifbridge_reminder_id FROM document_expiry_reminders WHERE user_id = ? AND document_id = ?",
                [$userId, $documentId]
            );
            if ($connection) {
                foreach ($rows as $row) {
                    if (empty($row['notifbridge_reminder_id'])) continue;
                    try {
                        notifbridgeDeleteReminder($connection, (int)$row['notifbridge_reminder_id']);
                    } catch (Exception $e) {
                        // The reminder may already have been removed remotely.
                    }
                }
            }
            $db->delete("DELETE FROM document_expiry_reminders WHERE user_id = ? AND document_id = ?", [$userId, $documentId]);
        }

        return [true, 'ok'];
    } catch (Exception $e) {
        return [false, $e->getMessage()];
    }
}

/**
 * Create the notification_templates table if it doesn't exist yet.
 * Safe to call on every request — mirrors ensureNotificationsSchema().
 */
function ensureNotificationTemplatesSchema(): array {
    if (!class_exists('Database')) return [false, 'Database class not loaded'];

    try {
        $db = Database::getInstance();

        if (!dbTableExists('notification_templates')) {
            // key/priority: shared with the desktop app's notif_templates
            // table via mysql_sync.py -- see the migration note where `key`
            // is generated in the create-template handler below for why it's
            // always populated rather than left nullable.
            $db->query(
                "CREATE TABLE notification_templates (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    `key` VARCHAR(100) NULL,
                    name VARCHAR(150) NULL,
                    linked_to VARCHAR(50) NOT NULL,
                    title VARCHAR(200) NOT NULL,
                    body TEXT NOT NULL,
                    priority ENUM('default','high') NOT NULL DEFAULT 'default',
                    reminder_days VARCHAR(200) DEFAULT NULL,
                    channel_inapp TINYINT(1) NOT NULL DEFAULT 1,
                    channel_email TINYINT(1) NOT NULL DEFAULT 1,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    INDEX idx_module (user_id, linked_to)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } elseif (!dbColumnExists('notification_templates', 'priority')) {
            // Added later (exposed in the Notifications > Templates tab
            // alongside the desktop app's own Priority field) -- self-heal
            // existing installs the same way scheduled_notifications.template_id
            // was migrated above.
            $db->getConnection()->exec("ALTER TABLE notification_templates ADD COLUMN priority ENUM('default','high') NOT NULL DEFAULT 'default' AFTER body");
        }
        if (!dbColumnExists('notification_templates', 'reminder_days')) {
            $db->getConnection()->exec("ALTER TABLE notification_templates ADD COLUMN reminder_days VARCHAR(200) NULL AFTER priority");
        }

        return [true, 'ok'];
    } catch (Exception $e) {
        return [false, $e->getMessage()];
    }
}

/**
 * Auto-generates a slug for a new template's `key` column, unique among
 * this user's templates. The desktop app's notif_templates.key is NOT NULL
 * UNIQUE and templates created there always go through its own validation
 * (app/models/notif_templates.py's _validate_key) -- a website-created
 * template with no key would pull down fine as far as mysql_sync.py's
 * generic mechanism is concerned, but the INSERT into desktop's own SQLite
 * table would then fail that constraint outright. Generating one here up
 * front means every template, regardless of which side created it, always
 * has one -- matching constraints on both sides rather than relaxing either.
 */
function generateTemplateKey(int $userId, string $name): string {
    $db = Database::getInstance();
    $base = strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim($name)));
    $base = trim($base, '_');
    if ($base === '' || !preg_match('/^[a-z]/', $base)) {
        $base = 't_' . ($base !== '' ? $base : substr(md5(uniqid('', true)), 0, 8));
    }
    $key = $base;
    $suffix = 2;
    while ($db->fetch("SELECT id FROM notification_templates WHERE user_id = ? AND `key` = ?", [$userId, $key])) {
        $key = $base . '_' . $suffix;
        $suffix++;
    }
    return $key;
}

/**
 * Modules whose fields are plain table columns (as opposed to entries in
 * custom_field_entries). Module keys equal table names 1:1 for every base
 * and savings module; folder-based modules ("other_folder_{id}") are the
 * one exception, backing onto other_detail_records filtered by folder_id.
 * Returns [tableName, extraWhereCol, extraWhereVal] or null if $module
 * isn't a recognized native-backed module.
 */
function _moduleToTable(string $module): ?array {
    if (strpos($module, 'other_folder_') === 0) {
        $folderId = (int)substr($module, strlen('other_folder_'));
        return $folderId > 0 ? ['other_detail_records', 'folder_id', $folderId] : null;
    }

    $knownTables = [
        'personal_details', 'bank_details', 'aadhaar_details', 'pan_details',
        'other_details', 'documents',
        'savings_daily', 'savings_bank_accounts', 'savings_fixed_deposits',
        'savings_recurring_deposits', 'savings_mutual_funds', 'savings_sip_investments',
        'savings_stocks', 'savings_ppf_epf', 'savings_gold_metals',
        'savings_insurance_policies', 'savings_cash_on_hand', 'savings_documents_registry',
    ];
    return in_array($module, $knownTables, true) ? [$module, null, null] : null;
}

const NATIVE_FIELD_EXCLUDE = ['id', 'user_id', 'folder_id', 'created_at', 'updated_at'];

/**
 * Built-in (non-custom-field) columns available as placeholders for a
 * module, introspected from the schema rather than hand-maintained per
 * module. Returns ['column_name' => 'Human Label'].
 */
function getNativeFieldsForModule(string $module): array {
    $mapped = _moduleToTable($module);
    if (!$mapped || !class_exists('Database')) return [];
    [$table] = $mapped;
    if (!dbTableExists($table)) return [];

    try {
        $db = Database::getInstance();
        $cols = $db->fetchAll(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION",
            [DB_NAME, $table]
        );
    } catch (Exception $e) {
        return [];
    }

    $out = [];
    foreach ($cols as $c) {
        $name = $c['COLUMN_NAME'];
        if (in_array($name, NATIVE_FIELD_EXCLUDE, true)) continue;
        $out[$name] = ucwords(str_replace('_', ' ', $name));
    }
    return $out;
}

/**
 * Fetch a module's native column values for one record, keyed by column
 * name — the counterpart to getNativeFieldsForModule().
 */
function getNativeFieldValues(string $module, int $recordId): array {
    $mapped = _moduleToTable($module);
    if (!$mapped) return [];
    [$table, $extraCol, $extraVal] = $mapped;

    try {
        $db = Database::getInstance();
        $sql = "SELECT * FROM `$table` WHERE id = ?";
        $params = [$recordId];
        if ($extraCol) {
            $sql .= " AND `$extraCol` = ?";
            $params[] = $extraVal;
        }
        $row = $db->fetch($sql, $params);
    } catch (Exception $e) {
        return [];
    }
    if (!$row) return [];

    foreach (NATIVE_FIELD_EXCLUDE as $ex) unset($row[$ex]);
    return $row;
}

/**
 * Replace {placeholder} tokens in a template string with a record's live
 * field values -- native columns first, then custom fields (so a
 * same-named custom field wins), plus the always-available {date}/{time}.
 * Called at delivery time (not at template/field save time) so edits to
 * the template or the record are always reflected in what actually sends.
 */
function resolveTemplatePlaceholders(string $text, string $module, int $userId, int $recordId, int $blockInstance = 0): string {
    $replacements = ['{date}' => date('d M Y'), '{time}' => date('h:i A')];

    foreach (getNativeFieldValues($module, $recordId) as $col => $val) {
        $replacements['{' . $col . '}'] = (string)($val ?? '');
    }

    if (function_exists('getCustomFieldsFor') && function_exists('getCustomFieldValues')) {
        $fields = getCustomFieldsFor($module, $userId);
        $values = getCustomFieldValues($module, $recordId);
        foreach ($fields as $f) {
            $val = $values[$f['id']] ?? '';
            if (is_array($val)) $val = $val[$blockInstance] ?? '';
            $replacements['{' . $f['field_name'] . '}'] = (string)$val;
        }
    }

    return strtr($text, $replacements);
}

/**
 * Given the current send_at (Y-m-d H:i:s) and a repeat type, compute the next occurrence.
 */
function computeNextSendAt(string $currentSendAt, string $repeatType): ?string {
    $intervalMap = [
        'daily'   => '+1 day',
        'weekly'  => '+1 week',
        'monthly' => '+1 month',
        'yearly'  => '+1 year',
    ];
    if (!isset($intervalMap[$repeatType])) return null;

    try {
        $dt = new DateTime($currentSendAt);
        $dt->modify($intervalMap[$repeatType]);
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Deliver one notification now via its enabled channels.
 * Returns true if at least one channel was attempted successfully.
 */
function deliverScheduledNotification(array $notif, array $user): bool {
    $db = Database::getInstance();
    $delivered = false;

    // Template-backed, per-record reminders resolve their placeholders
    // fresh here (not at creation time) so edits to the template or to the
    // record's own data are reflected in what actually sends.
    if (!empty($notif['template_id']) && !empty($notif['linked_to']) && !empty($notif['record_id'])) {
        try {
            $template = $db->fetch("SELECT * FROM notification_templates WHERE id = ?", [(int)$notif['template_id']]);
            if ($template) {
                $notif['title'] = resolveTemplatePlaceholders(
                    $template['title'], $notif['linked_to'], (int)$notif['user_id'],
                    (int)$notif['record_id'], (int)($notif['block_instance'] ?? 0)
                );
                $notif['message'] = resolveTemplatePlaceholders(
                    $template['body'], $notif['linked_to'], (int)$notif['user_id'],
                    (int)$notif['record_id'], (int)($notif['block_instance'] ?? 0)
                );
            }
        } catch (Exception $e) {
            error_log('Scheduled notification: template resolution failed: ' . $e->getMessage());
        }
    }

    if (!empty($notif['channel_inapp'])) {
        try {
            $db->insert(
                "INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, 'reminder', ?, NULL)",
                [$notif['user_id'], $notif['title'] . ': ' . $notif['message']]
            );
            $delivered = true;
        } catch (Exception $e) {
            error_log('Scheduled notification: in-app insert failed: ' . $e->getMessage());
        }
    }

    if (!empty($notif['channel_email']) && !empty($user['email'])) {
        try {
            require_once __DIR__ . '/mailer.php';
            $mailer = new Mailer();
            $sent = $mailer->sendNotification($user['email'], $user['full_name'] ?? '', $notif['title'], $notif['message']);
            $delivered = $delivered || $sent;
        } catch (Exception $e) {
            error_log('Scheduled notification: email send failed: ' . $e->getMessage());
        }
    }

    return $delivered;
}

/**
 * Send a single scheduled notification immediately, without affecting its schedule.
 * Used by the "Send test" action in reminders.php.
 */
function sendScheduledNotificationNow(int $notificationId, int $userId): bool {
    $db = Database::getInstance();
    $notif = $db->fetch("SELECT * FROM scheduled_notifications WHERE id = ? AND user_id = ?", [$notificationId, $userId]);
    if (!$notif) return false;

    $user = $db->fetch("SELECT id, email, full_name FROM users WHERE id = ?", [$userId]);
    if (!$user) return false;

    return deliverScheduledNotification($notif, $user);
}

/**
 * Find and deliver every notification whose send_at has arrived, advancing
 * repeating ones to their next occurrence. Called from cron-send-notifications.php.
 */
function processDueScheduledNotifications(): array {
    $db = Database::getInstance();
    $due = $db->fetchAll(
        "SELECT sn.*, u.email, u.full_name
         FROM scheduled_notifications sn
         JOIN users u ON u.id = sn.user_id
         WHERE sn.is_active = 1 AND sn.status = 'pending' AND sn.send_at <= NOW()"
    );

    $sent = 0;
    $failed = 0;
    $log = [];

    foreach ($due as $notif) {
        $ok = deliverScheduledNotification($notif, ['email' => $notif['email'], 'full_name' => $notif['full_name']]);
        $now = date('Y-m-d H:i:s');

        if (!$ok) {
            $failed++;
            $log[] = "Notification #{$notif['id']} (user {$notif['user_id']}): delivery FAILED";
            continue;
        }
        $sent++;

        if ($notif['repeat_type'] === 'none') {
            $db->update(
                "UPDATE scheduled_notifications SET status = 'sent', is_active = 0, last_sent_at = ? WHERE id = ?",
                [$now, $notif['id']]
            );
            $log[] = "Notification #{$notif['id']} (user {$notif['user_id']}): sent (one-time)";
            continue;
        }

        $next = computeNextSendAt($notif['send_at'], $notif['repeat_type']);
        $reachedEnd = $next === null || (!empty($notif['repeat_until']) && $next > $notif['repeat_until'] . ' 23:59:59');

        if ($reachedEnd) {
            $db->update(
                "UPDATE scheduled_notifications SET status = 'sent', is_active = 0, last_sent_at = ? WHERE id = ?",
                [$now, $notif['id']]
            );
            $log[] = "Notification #{$notif['id']} (user {$notif['user_id']}): sent (final occurrence)";
        } else {
            $db->update(
                "UPDATE scheduled_notifications SET send_at = ?, last_sent_at = ? WHERE id = ?",
                [$next, $now, $notif['id']]
            );
            $log[] = "Notification #{$notif['id']} (user {$notif['user_id']}): sent, next occurrence {$next}";
        }
    }

    return ['sent' => $sent, 'failed' => $failed, 'log' => $log];
}

/**
 * Create or update the scheduled_notifications row backing a 'reminder'-type
 * custom field value for one record instance. Deletes it if the value is cleared.
 */
/**
 * Ensures the local (per-website-account) table that tracks which
 * NotifBridge reminder backs which (custom_field, record, block_instance)
 * -- the PHP counterpart to the desktop's custom_field_reminders SQLite
 * table (app/db/schema.sql), same purpose: a later save updates the same
 * remote reminder instead of creating a duplicate.
 */
function ensureCustomFieldRemindersSchema(): array {
    if (!class_exists('Database')) return [false, 'Database class not loaded'];
    try {
        $db = Database::getInstance();
        if (!dbTableExists('custom_field_reminders')) {
            $db->query(
                "CREATE TABLE custom_field_reminders (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    custom_field_id INT NOT NULL,
                    linked_to VARCHAR(50) NOT NULL,
                    record_id INT NOT NULL,
                    block_instance INT NOT NULL DEFAULT 0,
                    notifbridge_reminder_id INT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_cfr (custom_field_id, linked_to, record_id, block_instance),
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        }
        return [true, 'OK'];
    } catch (Exception $e) {
        return [false, $e->getMessage()];
    }
}

function syncReminderFieldEntry($userId, $module, array $field, $recordId, $blockInstance, $value) {
    require_once __DIR__ . '/notifbridge_client.php';
    ensureNotificationTemplatesSchema();
    ensureCustomFieldRemindersSchema();
    $db = Database::getInstance();

    $existing = $db->fetch(
        "SELECT * FROM custom_field_reminders WHERE user_id = ? AND custom_field_id = ? AND linked_to = ? AND record_id = ? AND block_instance = ?",
        [$userId, $field['id'], $module, $recordId, $blockInstance]
    );
    // A pre-fix save may have left a row in the old, disconnected
    // scheduled_notifications system for this exact field/record -- clean
    // it up on the next save so it can't also fire independently.
    $db->delete(
        "DELETE FROM scheduled_notifications WHERE custom_field_id = ? AND linked_to = ? AND record_id = ? AND block_instance = ?",
        [$field['id'], $module, $recordId, $blockInstance]
    );

    $connection = getNotifBridgeConnection($userId);

    if (empty($value)) {
        if ($existing) {
            if ($existing['notifbridge_reminder_id'] && $connection) {
                try {
                    notifbridgeDeleteReminder($connection, $existing['notifbridge_reminder_id']);
                } catch (NotifBridgeError $e) {
                    _logReminderFieldError($userId, $field['field_label'], $e);
                }
            }
            $db->delete("DELETE FROM custom_field_reminders WHERE id = ?", [$existing['id']]);
        }
        return;
    }

    if (!$connection) {
        // Mirrors the desktop's own "not this function's call to make noise
        // about" stance -- the value is still saved as a normal custom field
        // either way; the caller decides whether/how to tell the user their
        // NotifBridge isn't connected.
        return;
    }

    $config = [];
    if (!empty($field['field_options'])) {
        $decoded = json_decode($field['field_options'], true);
        if (is_array($decoded)) $config = $decoded;
    }

    $templateId = !empty($config['template_id']) ? (int)$config['template_id'] : null;
    $template = $templateId ? $db->fetch("SELECT * FROM notification_templates WHERE id = ? AND user_id = ?", [$templateId, $userId]) : null;

    $title = $field['field_label'];
    $body = $field['field_label'] . ' reminder';
    if ($template) {
        $title = resolveTemplatePlaceholders($template['title'], $module, $userId, $recordId, $blockInstance);
        $body = resolveTemplatePlaceholders($template['body'], $module, $userId, $recordId, $blockInstance);
    }

    $repeatType = $config['repeat'] ?? 'none';
    if (!in_array($repeatType, NOTIFICATION_REPEAT_TYPES, true)) $repeatType = 'none';

    // datetime-local values come as 'Y-m-d\TH:i' (IST, same as the desktop
    // FieldWidget's date+time picker) -- normalize and convert to UTC the
    // same explicit-source-timezone way notifbridgeLocalToUtcIso() does.
    $normalized = str_replace('T', ' ', $value);
    if (strlen($normalized) === 16) $normalized .= ':00';
    try {
        $dt = new DateTime($normalized, new DateTimeZone('+05:30'));
        $dt->setTimezone(new DateTimeZone('UTC'));
        $sendAt = $dt->format('Y-m-d\TH:i:s\Z');
    } catch (Exception $e) {
        return;
    }

    $payload = [
        'title' => $title,
        'body' => $body,
        'device_ids' => 'all',
        'priority' => 'default',
        'channel_push' => true,
        'send_at' => $sendAt,
        'repeat_type' => $repeatType,
        'repeat_until' => null,
        'is_active' => true,
    ];

    try {
        if ($existing && $existing['notifbridge_reminder_id']) {
            notifbridgeUpdateReminder($connection, $existing['notifbridge_reminder_id'], $payload);
            return;
        }

        $result = notifbridgeCreateReminder($connection, $payload);
        $remoteId = $result['id'] ?? null;
        if ($existing) {
            $db->query("UPDATE custom_field_reminders SET notifbridge_reminder_id = ? WHERE id = ?", [$remoteId, $existing['id']]);
        } else {
            $db->insert(
                "INSERT INTO custom_field_reminders (user_id, custom_field_id, linked_to, record_id, block_instance, notifbridge_reminder_id) VALUES (?, ?, ?, ?, ?, ?)",
                [$userId, $field['id'], $module, $recordId, $blockInstance, $remoteId]
            );
        }
    } catch (NotifBridgeError $e) {
        _logReminderFieldError($userId, $field['field_label'], $e);
    }
}

/**
 * Durable failure record -- mirrors the desktop's reminder_field_sync.py fix:
 * this used to be `catch (Exception $e) {}` at every call site (see
 * custom_fields_helper.php), so any failure here vanished with no trace
 * anywhere. activity_log survives regardless of what the calling request
 * does next.
 */
function _logReminderFieldError($userId, string $fieldLabel, Exception $e): void {
    try {
        $db = Database::getInstance();
        $db->insert(
            "INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
            [$userId, 'error', "Reminder sync failed for '$fieldLabel': " . $e->getMessage(), $_SERVER['REMOTE_ADDR'] ?? '']
        );
    } catch (Exception $inner) {
        // Logging itself failing shouldn't raise past this point.
    }
}
