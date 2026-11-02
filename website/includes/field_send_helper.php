<?php
/**
 * "Send to phone (Send now)" custom field type -- website twin of the desktop
 * app's app/services/field_send.py. A field of this type stores, in its
 * field_options JSON, which of the record's fields to send and how; its
 * "Send now" button posts to api-field-send.php, which uses these helpers.
 *
 * Security: CSRF + login, the field and record must belong to the user,
 * files are only taken from this record's own entries, password fields are
 * never sent, sensitive numbers are masked by default, an optional Safety
 * PIN is checked on every send, a per-record cooldown blocks double sends,
 * and every send is written to activity_log (names/counts only, no values).
 */

require_once __DIR__ . '/notifbridge_client.php';
require_once __DIR__ . '/notifications_helper.php';

const FS_MAX_FILES = 10;
const FS_CUSTOM_PREFIX = 'cf:';
const FS_FILE_TYPES = ['file', 'image', 'pdf', 'document', 'spreadsheet'];
const FS_SENSITIVE = '/(account|acc_no|a\/c|aadhaar|aadhar|pan_?(no|number)|card|cvv|cvc|vid|upi|policy_?(no|number)|enrol|folio|demat|passport|licen[cs]e|\bpin\b|otp|secret)/i';

function fsDefaultConfig(): array {
    return ['fields' => [], 'title' => '', 'intro' => '', 'priority' => 'default', 'mask' => true,
            'files' => true, 'pin' => false, 'confirm' => true, 'cooldown' => 30];
}

function fsParseConfig(?string $raw): array {
    $cfg = fsDefaultConfig();
    $data = $raw ? json_decode($raw, true) : null;
    if (is_array($data)) {
        foreach ($cfg as $k => $_) {
            if (array_key_exists($k, $data)) $cfg[$k] = $data[$k];
        }
    }
    $cfg['fields'] = array_values(array_filter((array)$cfg['fields'], 'is_string'));
    $cfg['cooldown'] = max(0, (int)$cfg['cooldown']);
    $cfg['priority'] = $cfg['priority'] === 'high' ? 'high' : 'default';
    foreach (['mask', 'files', 'pin', 'confirm'] as $b) $cfg[$b] = (bool)$cfg[$b];
    $cfg['pin'] = true; // the Safety PIN is always required to send -- not optional
    return $cfg;
}

function fsIsSensitive(string $key, string $label = ''): bool {
    return (bool)(preg_match(FS_SENSITIVE, $key) || preg_match(FS_SENSITIVE, $label));
}

function fsMask(string $value): string {
    $v = preg_replace('/\s+/', '', $value);
    return mb_strlen($v) <= 4 ? '••••' : '•••• ' . mb_substr($v, -4);
}

function fsEnsureTable(Database $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if (!dbTableExists('custom_field_sends')) {
            $db->query("CREATE TABLE custom_field_sends (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                custom_field_id INT NOT NULL,
                linked_to VARCHAR(100) NOT NULL,
                record_id INT NOT NULL DEFAULT 0,
                sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                ok_count INT NOT NULL DEFAULT 0,
                device_count INT NOT NULL DEFAULT 0,
                file_count INT NOT NULL DEFAULT 0,
                ip_address VARCHAR(64) NULL,
                INDEX idx_fs_lookup (user_id, custom_field_id, record_id, sent_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    } catch (Exception $e) {
        // history is best-effort; sending still works without it
    }
}

/** ['at' => unix, 'count' => n] or null */
function fsLastSent(Database $db, int $userId, int $fieldId, int $recordId): ?array {
    fsEnsureTable($db);
    try {
        $row = $db->fetch(
            "SELECT UNIX_TIMESTAMP(MAX(sent_at)) AS at, COUNT(*) AS cnt FROM custom_field_sends
             WHERE user_id = ? AND custom_field_id = ? AND record_id = ?",
            [$userId, $fieldId, $recordId]
        );
    } catch (Exception $e) {
        return null;
    }
    return ($row && $row['cnt']) ? ['at' => (int)$row['at'], 'count' => (int)$row['cnt']] : null;
}

function fsCooldownLeft(Database $db, int $userId, array $field, int $recordId): int {
    $cfg = fsParseConfig($field['field_options'] ?? null);
    if (!$cfg['cooldown']) return 0;
    $last = fsLastSent($db, $userId, (int)$field['id'], $recordId);
    return $last ? max(0, $last['at'] + $cfg['cooldown'] - time()) : 0;
}

/** Saved row of a native-backed module, only if it belongs to the user. */
function fsOwnedNativeRow(Database $db, string $module, int $userId, int $recordId): ?array {
    $mapped = _moduleToTable($module);
    if (!$mapped || $recordId <= 0) return null;
    [$table, $extraCol, $extraVal] = $mapped;
    $sql = "SELECT * FROM `$table` WHERE id = ? AND user_id = ?";
    $params = [$recordId, $userId];
    if ($extraCol) {
        $sql .= " AND `$extraCol` = ?";
        $params[] = $extraVal;
    }
    try {
        $row = $db->fetch($sql, $params);
    } catch (Exception $e) {
        return null;
    }
    return $row ?: null;
}

/**
 * Copies a stored file value to a temp file. Returns [path, name] or throws.
 * Accepts gdrive:{id}[:{name}] and website-relative uploads/... paths only.
 */
function fsMaterializeFile(string $value, int $userId): array {
    if (strpos($value, 'gdrive:') === 0) {
        $g = parseGDrivePath($value);
        require_once __DIR__ . '/google-drive.php';
        $gdrive = new GoogleDriveIntegration();
        $tmp = tempnam(sys_get_temp_dir(), 'fs_');
        if (!$gdrive->downloadToFile($g['id'], $tmp)) {
            throw new Exception('could not download it from Google Drive');
        }
        return [$tmp, basename($g['name'] ?: 'file')];
    }
    if (strpos($value, 'local:') === 0) {
        throw new Exception('it is stored only on the desktop app');
    }
    if (strpos($value, '..') !== false || strpos($value, 'uploads/') !== 0) {
        throw new Exception('file path not allowed');
    }
    $path = realpath(__DIR__ . '/../' . $value);
    $root = realpath(__DIR__ . '/../uploads');
    if (!$path || !$root || strpos($path, $root) !== 0 || !is_file($path)) {
        throw new Exception('file not found on the server');
    }
    return [$path, basename($path)];
}

/**
 * Builds the message. $formValues = current on-screen values keyed by native
 * column name or "cf:<field_name>"; $uploads = new files from the form keyed
 * the same way (one $_FILES entry each). Returns
 * ['title','lines'=>[[label,value]],'files'=>[[path,name,label,temp]],'skipped'=>[], 'intro'].
 */
function fsBuildMessage(Database $db, array $field, int $userId, int $recordId, array $formValues, array $uploads,
                        ?bool $maskOverride = null, int $instance = 0): array {
    $module = $field['linked_to'];
    $cfg = fsParseConfig($field['field_options'] ?? null);
    $mask = $maskOverride ?? $cfg['mask'];

    $native = $recordId ? (fsOwnedNativeRow($db, $module, $userId, $recordId) ?? []) : [];
    if ($recordId && !$native && _moduleToTable($module)) {
        throw new Exception('Record not found.');
    }
    $nativeLabels = getNativeFieldsForModule($module);
    $customDefs = [];
    foreach (getCustomFieldsFor($module, $userId) as $f) $customDefs[$f['field_name']] = $f;
    $savedCustom = $recordId ? getCustomFieldValues($module, $recordId) : [];

    $msg = ['title' => '', 'intro' => '', 'lines' => [], 'files' => [], 'skipped' => []];
    $placeholders = [];
    foreach ($native as $k => $v) $placeholders[$k] = (string)($v ?? '');

    foreach ($cfg['fields'] as $key) {
        if (strpos($key, FS_CUSTOM_PREFIX) === 0) {
            $name = substr($key, strlen(FS_CUSTOM_PREFIX));
            $f = $customDefs[$name] ?? null;
            if (!$f) { $msg['skipped'][] = "'$name' no longer exists"; continue; }
            $label = $f['field_label'];
            $type = $f['field_type'];
            $saved = $savedCustom[$f['id']] ?? '';
            if (is_array($saved)) $saved = $saved[$instance] ?? ($saved[0] ?? '');

            if (in_array($type, FS_FILE_TYPES, true)) {
                if (!$cfg['files']) continue;
                $up = $uploads[$key] ?? null;
                if ($up && ($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    $msg['files'][] = [$up['tmp_name'], basename($up['name']), $label, false];
                } elseif ($saved !== '') {
                    try {
                        [$p, $n] = fsMaterializeFile((string)$saved, $userId);
                        $msg['files'][] = [$p, $n, $label, strpos((string)$saved, 'gdrive:') === 0];
                    } catch (Exception $e) {
                        $msg['skipped'][] = "$label: " . $e->getMessage();
                    }
                }
                continue;
            }
            if ($type === 'password') { $msg['skipped'][] = "$label (password fields are never sent)"; continue; }
            if (in_array($type, ['send_now', 'reminder'], true)) continue;
            $value = array_key_exists($key, $formValues) ? (string)$formValues[$key] : (string)$saved;
            $placeholders[$name] = $value;
        } else {
            $label = $nativeLabels[$key] ?? ucwords(str_replace('_', ' ', $key));
            if (!isset($nativeLabels[$key])) { $msg['skipped'][] = "'$key' is not a field of this module"; continue; }
            if ($key === 'file_path') {
                if ($cfg['files'] && !empty($native['file_path'])) {
                    try {
                        [$p, $n] = fsMaterializeFile((string)$native['file_path'], $userId);
                        $n = $native['file_name'] ?? $n;
                        $msg['files'][] = [$p, $n, $label, strpos((string)$native['file_path'], 'gdrive:') === 0];
                    } catch (Exception $e) {
                        $msg['skipped'][] = "$label: " . $e->getMessage();
                    }
                }
                continue;
            }
            $value = array_key_exists($key, $formValues) ? (string)$formValues[$key] : (string)($native[$key] ?? '');
            $placeholders[$key] = $value;
        }
        $value = trim($value);
        if ($value === '') continue;
        if ($mask && fsIsSensitive($key, $label)) $value = fsMask($value);
        $msg['lines'][] = [$label, $value];
    }

    $resolve = function (string $t) use ($placeholders): string {
        $map = ['{date}' => date('d M Y'), '{time}' => date('h:i A')];
        foreach ($placeholders as $k => $v) $map['{' . $k . '}'] = $v;
        return strtr($t, $map);
    };
    $first = $msg['lines'][0][1] ?? '';
    $default = $field['field_label'] . (($first !== '' && strpos($first, '•') === false) ? ' — ' . $first : '');
    $msg['title'] = trim($cfg['title'] !== '' ? $resolve($cfg['title']) : $default) ?: $field['field_label'];
    $msg['intro'] = $cfg['intro'] !== '' ? $resolve($cfg['intro']) : '';

    foreach ($msg['files'] as $i => $f) {
        $size = @filesize($f[0]) ?: 0;
        if ($size > NOTIFBRIDGE_MAX_ATTACHMENT_MB * 1024 * 1024) {
            $msg['skipped'][] = $f[1] . ' is larger than ' . NOTIFBRIDGE_MAX_ATTACHMENT_MB . ' MB';
            if ($f[3]) @unlink($f[0]);
            unset($msg['files'][$i]);
        }
    }
    $msg['files'] = array_values($msg['files']);
    if (count($msg['files']) > FS_MAX_FILES) {
        foreach (array_slice($msg['files'], FS_MAX_FILES) as $f) if ($f[3]) @unlink($f[0]);
        $msg['files'] = array_slice($msg['files'], 0, FS_MAX_FILES);
        $msg['skipped'][] = 'Only the first ' . FS_MAX_FILES . ' files are sent';
    }
    return $msg;
}

function fsBody(array $msg): string {
    $parts = [];
    if ($msg['intro'] !== '') $parts[] = $msg['intro'];
    if ($msg['lines']) $parts[] = implode("\n", array_map(fn($l) => $l[0] . ': ' . $l[1], $msg['lines']));
    return implode("\n\n", $parts) ?: $msg['title'];
}

function fsCleanup(array $msg): void {
    foreach ($msg['files'] as $f) if (!empty($f[3])) @unlink($f[0]);
}

/** Uploads files, sends, records history + audit. Returns the NotifBridge response. */
function fsSend(Database $db, array $connection, array $field, int $userId, int $recordId, string $title, string $body,
                array $files, string $priority, $deviceIds): array {
    $data = ['source' => 'website', 'module' => $field['linked_to'], 'field' => $field['field_name']];
    if ($recordId) $data['record_id'] = (string)$recordId;
    $imageUrl = null;
    $links = [];
    foreach ($files as $i => $f) {
        $mime = function_exists('mime_content_type') ? (mime_content_type($f[0]) ?: null) : null;
        $info = notifbridgeUploadAttachment($connection, $f[0], $f[1], $mime);
        $links[] = '• ' . $f[2] . ': ' . $f[1] . "\n  " . $info['url'];
        if ($i === 0) {
            $data['attachment_url'] = $info['url'];
            $data['attachment_name'] = $info['filename'] ?? $f[1];
            $data['attachment_type'] = $info['content_type'] ?? '';
            $data['attachment_size'] = (string)($info['size'] ?? '');
        }
        if (!empty($info['is_image']) && $imageUrl === null) $imageUrl = $info['url'];
    }
    if ($files) $data['attachment_count'] = (string)count($files);
    $fullBody = $body . ($links ? "\n\n📎 Files:\n" . implode("\n", $links) : '');

    $result = notifbridgeSend($connection, mb_substr($title, 0, 200), $fullBody, $deviceIds, $priority, $imageUrl, $data);
    $results = $result['results'] ?? [];
    $ok = count(array_filter($results, fn($r) => !empty($r['success'])));

    fsEnsureTable($db);
    try {
        $db->insert(
            "INSERT INTO custom_field_sends (user_id, custom_field_id, linked_to, record_id, ok_count, device_count, file_count, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$userId, (int)$field['id'], $field['linked_to'], $recordId, $ok, count($results), count($files), $_SERVER['REMOTE_ADDR'] ?? '']
        );
        $db->insert(
            "INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
            [$userId, 'notification', "Sent '{$field['field_label']}' ({$field['linked_to']} #$recordId) to phone: "
                . (substr_count($body, "\n") + 1) . ' line(s), ' . count($files) . " file(s), $ok/" . count($results) . ' device(s)',
             $_SERVER['REMOTE_ADDR'] ?? '']
        );
    } catch (Exception $e) {
        // audit is best-effort
    }
    return $result;
}
