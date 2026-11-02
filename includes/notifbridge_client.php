<?php
/**
 * NotifBridge REST client + credential storage -- the website's counterpart
 * to app/services/notif_client.py + notif_config.py. Talking to the same
 * external NotifBridge server (not MySQL) means reminders created here and
 * on the desktop app are the exact same reminders, delivered to the exact
 * same registered devices -- not a parallel/duplicate system.
 *
 * Schema bootstrap mirrors includes/groww-helper.php's ensureGrowwSchema()
 * pattern (self-healing CREATE TABLE IF NOT EXISTS, no separate migration
 * step needed). Credentials are per-user, encrypted at rest via crypto.php's
 * pds_encrypt()/pds_decrypt() -- the same approach the desktop app uses via
 * app/core/crypto.py, just never stored in plaintext on the server either.
 */

require_once __DIR__ . '/crypto.php';

class NotifBridgeError extends Exception {}

function ensureNotifBridgeSchema(): array {
    if (!class_exists('Database')) return [false, 'Database class not loaded'];

    try {
        $db = Database::getInstance();

        if (!dbTableExists('notifbridge_credentials')) {
            $db->query(
                "CREATE TABLE notifbridge_credentials (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    server_url VARCHAR(255) NOT NULL,
                    api_key_enc TEXT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_notifbridge_user (user_id),
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        }

        return [true, 'OK'];
    } catch (Exception $e) {
        return [false, $e->getMessage()];
    }
}

/**
 * Threshold settings for the automatic savings alerts the desktop app fires
 * via app/services/notif_triggers.py (low balance, large entry, upcoming
 * maturity). Stored independently of notifbridge_credentials (a settings
 * row can exist -- and be edited -- before NotifBridge is ever connected,
 * same as the desktop app's app_settings-backed notif_triggers module).
 *
 * Note: saving these here only persists the values for the Configuration
 * tab to display/edit -- the website does not yet run the corresponding
 * automatic checks against savings entries the way the desktop app's
 * after_save hooks do.
 */
function ensureNotifBridgeSettingsTable(): void {
    if (!class_exists('Database')) return;
    try {
        $db = Database::getInstance();
        if (!dbTableExists('notifbridge_settings')) {
            $db->query(
                "CREATE TABLE notifbridge_settings (
                    user_id INT PRIMARY KEY,
                    low_balance_threshold DECIMAL(14,2) NOT NULL DEFAULT 1000.00,
                    large_entry_threshold DECIMAL(14,2) NOT NULL DEFAULT 10000.00,
                    maturity_days_ahead INT NOT NULL DEFAULT 7,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        }
    } catch (Exception $e) {
        // Best-effort self-heal, same tolerance as ensureNotifBridgeSchema()
    }
}

function getNotifBridgeThresholds(int $userId): array {
    ensureNotifBridgeSettingsTable();
    $defaults = ['low_balance_threshold' => 1000.0, 'large_entry_threshold' => 10000.0, 'maturity_days_ahead' => 7];
    try {
        $row = Database::getInstance()->fetch("SELECT * FROM notifbridge_settings WHERE user_id = ?", [$userId]);
    } catch (Exception $e) {
        return $defaults;
    }
    if (!$row) return $defaults;
    return [
        'low_balance_threshold' => (float)$row['low_balance_threshold'],
        'large_entry_threshold' => (float)$row['large_entry_threshold'],
        'maturity_days_ahead' => (int)$row['maturity_days_ahead'],
    ];
}

function saveNotifBridgeThresholds(int $userId, float $lowBalance, float $largeEntry, int $maturityDays): void {
    ensureNotifBridgeSettingsTable();
    $db = Database::getInstance();
    $existing = $db->fetch("SELECT user_id FROM notifbridge_settings WHERE user_id = ?", [$userId]);
    if ($existing) {
        $db->query(
            "UPDATE notifbridge_settings SET low_balance_threshold = ?, large_entry_threshold = ?, maturity_days_ahead = ? WHERE user_id = ?",
            [$lowBalance, $largeEntry, $maturityDays, $userId]
        );
    } else {
        $db->insert(
            "INSERT INTO notifbridge_settings (user_id, low_balance_threshold, large_entry_threshold, maturity_days_ahead) VALUES (?, ?, ?, ?)",
            [$userId, $lowBalance, $largeEntry, $maturityDays]
        );
    }
}

function getNotifBridgeConnection(int $userId): ?array {
    $db = Database::getInstance();
    $row = $db->fetch("SELECT * FROM notifbridge_credentials WHERE user_id = ?", [$userId]);
    if (!$row) return null;
    return [
        'server_url' => $row['server_url'],
        'api_key' => pds_decrypt($row['api_key_enc']),
    ];
}

function saveNotifBridgeCredentials(int $userId, string $serverUrl, string $apiKey): void {
    $db = Database::getInstance();
    $existing = $db->fetch("SELECT id FROM notifbridge_credentials WHERE user_id = ?", [$userId]);
    $serverUrl = rtrim(trim($serverUrl), '/');
    $apiKeyEnc = pds_encrypt($apiKey);

    if ($existing) {
        $db->query(
            "UPDATE notifbridge_credentials SET server_url = ?, api_key_enc = ? WHERE user_id = ?",
            [$serverUrl, $apiKeyEnc, $userId]
        );
    } else {
        $db->insert(
            "INSERT INTO notifbridge_credentials (user_id, server_url, api_key_enc) VALUES (?, ?, ?)",
            [$userId, $serverUrl, $apiKeyEnc]
        );
    }
}

function deleteNotifBridgeCredentials(int $userId): void {
    $db = Database::getInstance();
    $db->delete("DELETE FROM notifbridge_credentials WHERE user_id = ?", [$userId]);
}

/**
 * Raw request against the NotifBridge REST API. Mirrors
 * NotifBridgeClient._raise_for_status()'s error shape: throws
 * NotifBridgeError("{status}: {detail}") on any non-2xx response, and on
 * transport failure (unreachable server, timeout).
 */
function notifbridgeRequest(array $connection, string $method, string $path, ?array $body = null, int $timeoutSeconds = 8): array {
    $url = $connection['server_url'] . $path;
    $ch = curl_init($url);
    $headers = ['X-API-Key: ' . $connection['api_key'], 'Content-Type: application/json'];

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new NotifBridgeError("Could not reach NotifBridge server: $error");
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($response, true);
    if ($status < 200 || $status >= 300) {
        $detail = is_array($decoded) && isset($decoded['detail']) ? $decoded['detail'] : $response;
        throw new NotifBridgeError("$status: $detail");
    }
    return is_array($decoded) ? $decoded : [];
}

function notifbridgeTestConnection(array $connection): bool {
    try {
        $ch = curl_init($connection['server_url'] . '/health');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $status >= 200 && $status < 300;
    } catch (Exception $e) {
        return false;
    }
}

function notifbridgeListDevices(array $connection): array {
    return notifbridgeRequest($connection, 'GET', '/api/v1/devices');
}

function notifbridgeOutgoing(array $connection, int $limit = 50): array {
    return notifbridgeRequest($connection, 'GET', '/api/v1/notifications/outgoing?limit=' . $limit);
}

function notifbridgeSend(array $connection, string $title, string $body, $deviceIds = 'all', string $priority = 'default',
                         ?string $imageUrl = null, ?array $data = null, ?array $actions = null): array {
    $payload = [
        'device_ids' => $deviceIds,
        'title' => $title,
        'body' => $body,
        'priority' => $priority,
    ];
    if ($imageUrl) $payload['image_url'] = $imageUrl;
    if ($data) $payload['data'] = array_map('strval', $data);
    if ($actions) $payload['actions'] = $actions;
    return notifbridgeRequest($connection, 'POST', '/api/v1/notifications/send', $payload);
}

/** Max size of a file sent with a push (matches the server's attachment_max_mb). */
const NOTIFBRIDGE_MAX_ATTACHMENT_MB = 20;

/**
 * Uploads a local file (e.g. $_FILES[...]['tmp_name']) to NotifBridge so it
 * can be opened from the phone. Returns the server's attachment info with
 * 'url' built from this user's configured server URL.
 */
function notifbridgeUploadAttachment(array $connection, string $tmpPath, string $fileName, ?string $mime = null): array {
    $ch = curl_init($connection['server_url'] . '/api/v1/attachments');
    $mime = $mime ?: (function_exists('mime_content_type') ? (mime_content_type($tmpPath) ?: 'application/octet-stream') : 'application/octet-stream');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['X-API-Key: ' . $connection['api_key']],
        CURLOPT_POSTFIELDS => ['file' => new CURLFile($tmpPath, $mime, $fileName)],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new NotifBridgeError("Could not reach NotifBridge server: $error");
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode($response, true);
    if ($status === 404) {
        throw new NotifBridgeError('This NotifBridge server cannot receive files yet. Update the server to the latest version.');
    }
    if ($status < 200 || $status >= 300) {
        $detail = is_array($decoded) && isset($decoded['detail']) ? $decoded['detail'] : $response;
        throw new NotifBridgeError("$status: $detail");
    }
    if (!empty($decoded['path'])) {
        $decoded['url'] = $connection['server_url'] . $decoded['path'];
    }
    return $decoded;
}

/**
 * Custom message with an optional uploaded file ($file = one $_FILES entry).
 * Images become the notification picture; any file is also passed as
 * attachment_* data so the phone app shows an Open button.
 */
function notifbridgeSendMessage(array $connection, string $title, string $body, $deviceIds = 'all', string $priority = 'default',
                                ?array $file = null, array $extraData = []): array {
    $data = $extraData;
    $imageUrl = null;
    if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new NotifBridgeError('The file could not be uploaded (error ' . (int)$file['error'] . ').');
        }
        if ($file['size'] > NOTIFBRIDGE_MAX_ATTACHMENT_MB * 1024 * 1024) {
            throw new NotifBridgeError('Files must be ' . NOTIFBRIDGE_MAX_ATTACHMENT_MB . ' MB or smaller.');
        }
        $info = notifbridgeUploadAttachment($connection, $file['tmp_name'], basename($file['name']), $file['type'] ?? null);
        $data['attachment_url'] = $info['url'];
        $data['attachment_name'] = $info['filename'] ?? basename($file['name']);
        $data['attachment_type'] = $info['content_type'] ?? '';
        $data['attachment_size'] = (string)($info['size'] ?? $file['size']);
        if (!empty($info['is_image'])) $imageUrl = $info['url'];
    }
    return notifbridgeSend($connection, $title, $body, $deviceIds, $priority, $imageUrl, $data ?: null);
}

/** "Pixel 8 (#3)" style label used by every device picker. */
function notifbridgeDeviceLabel(array $d): string {
    $name = $d['nickname'] ?: ($d['device_uuid'] ?? 'Phone');
    return $name . ' (#' . (int)$d['id'] . ')' . (empty($d['active']) ? ' — inactive' : '');
}

function notifbridgeListReminders(array $connection): array {
    return notifbridgeRequest($connection, 'GET', '/api/v1/reminders');
}

function notifbridgeCreateReminder(array $connection, array $payload): array {
    return notifbridgeRequest($connection, 'POST', '/api/v1/reminders', $payload);
}

function notifbridgeUpdateReminder(array $connection, int $reminderId, array $payload): array {
    return notifbridgeRequest($connection, 'PUT', '/api/v1/reminders/' . $reminderId, $payload);
}

function notifbridgeDeleteReminder(array $connection, int $reminderId): void {
    notifbridgeRequest($connection, 'DELETE', '/api/v1/reminders/' . $reminderId);
}

function notifbridgeSendReminderNow(array $connection, int $reminderId): array {
    return notifbridgeRequest($connection, 'POST', '/api/v1/reminders/' . $reminderId . '/send-now', null, 18);
}

// --------------------------------------------------------- IST <-> UTC
//
// Mirrors reminder_scheduler_panel.py's IST handling exactly: a fixed
// UTC+5:30 offset (not a tz database lookup -- India has no DST, and this
// avoids depending on the server's own tzdata being installed/current).

function notifbridgeLocalToUtcIso(?string $dateStr, ?string $timeStr): ?string {
    $dateStr = trim($dateStr ?? '');
    $timeStr = trim($timeStr ?? '') ?: '09:00';
    if ($dateStr === '') return null;
    try {
        $dt = new DateTime("$dateStr $timeStr", new DateTimeZone('+05:30'));
        $dt->setTimezone(new DateTimeZone('UTC'));
        return $dt->format('Y-m-d\TH:i:s\Z');
    } catch (Exception $e) {
        return null;
    }
}

/**
 * NotifBridge returns send_at/last_sent_at as a bare ISO string with no 'Z'
 * or offset -- UTC by convention, not by self-description. `new DateTime($s)`
 * with no second argument parses that against PHP's *runtime default*
 * timezone (APP_TIMEZONE = 'Asia/Kolkata', set in config/database.php), not
 * UTC -- so a later setTimezone('+05:30') is a no-op and the raw UTC clock
 * time gets displayed as if it were already IST. Passing UTC explicitly as
 * the constructor's timezone fixes this: PHP only uses that argument when
 * the string itself doesn't already carry an offset, so a string that *does*
 * specify one is still respected.
 */
function notifbridgeParseUtc(string $isoValue): DateTime {
    return new DateTime($isoValue, new DateTimeZone('UTC'));
}

function notifbridgeUtcIsoToLocalDisplay(?string $isoValue): string {
    if (!$isoValue) return '';
    try {
        $dt = notifbridgeParseUtc($isoValue);
        $dt->setTimezone(new DateTimeZone('+05:30'));
        return $dt->format('d M Y, h:i A') . ' IST';
    } catch (Exception $e) {
        return $isoValue;
    }
}

function notifbridgeUtcIsoToLocalDateTime(?string $isoValue): array {
    if (!$isoValue) return ['', ''];
    try {
        $dt = notifbridgeParseUtc($isoValue);
        $dt->setTimezone(new DateTimeZone('+05:30'));
        return [$dt->format('Y-m-d'), $dt->format('H:i')];
    } catch (Exception $e) {
        return ['', ''];
    }
}

const NOTIFBRIDGE_REPEAT_LABELS = [
    'none' => 'One-time',
    'daily' => 'Daily',
    'weekly' => 'Weekly',
    'monthly' => 'Monthly',
    'yearly' => 'Yearly',
];
