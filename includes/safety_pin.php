<?php
/**
 * Safety PIN -- one PIN per user, shared by the website, the desktop app and
 * the mobile app, asked before any Edit or Delete.
 *
 * Storage: table `safety_pins` (one row per user). Only a PBKDF2-SHA256 hash
 * is stored, in the same "iterations$salt$digest" format the desktop app's
 * core/crypto.py uses, so either side can set the PIN and both can check it.
 * The desktop keeps a copy in sync through desktop-sync-api.php
 * (safety_pin_get / safety_pin_set); the mobile app checks it there with
 * safety_pin_verify.
 *
 * Web: once the right PIN is entered, edits stay unlocked for the rest of
 * the login session (until logout / session timeout / "Lock edits now").
 * spGuardRequest() -- called from includes/header.php before any page
 * handles its own edit/delete -- refuses protected requests while locked.
 */

const SP_ITERATIONS = 200000;
const SP_MIN_LENGTH = 4;
const SP_MAX_LENGTH = 8;
const SP_MAX_ATTEMPTS = 5;
const SP_LOCK_SECONDS = 60;

/** Pages where nothing is PIN-protected (account/settings/auth pages). */
const SP_EXEMPT_PAGES = [
    'profile.php', 'login.php', 'logout.php', 'register.php', 'forgot-password.php', 'reset-password.php',
    'setup.php', 'setup-2fa.php', 'verify-2fa.php', 'setup-fingerprint.php', 'oauth-login.php',
    'oauth-callback.php', 'setup-google-drive.php', 'groww-settings.php',
];

function spEnsureTable(Database $db): void {
    static $done = false;
    if ($done) return;
    $db->query("CREATE TABLE IF NOT EXISTS safety_pins (
        user_id INT NOT NULL PRIMARY KEY,
        pin_hash VARCHAR(255) NOT NULL,
        updated_at VARCHAR(19) NOT NULL,
        failed_attempts INT NOT NULL DEFAULT 0,
        locked_until INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function spNowUtc(): string {
    return gmdate('Y-m-d H:i:s');
}

function spValidPinFormat(string $pin): bool {
    return ctype_digit($pin) && strlen($pin) >= SP_MIN_LENGTH && strlen($pin) <= SP_MAX_LENGTH;
}

function spHash(string $pin): string {
    $salt = random_bytes(16);
    $digest = hash_pbkdf2('sha256', $pin, $salt, SP_ITERATIONS, 32, true);
    return SP_ITERATIONS . '$' . base64_encode($salt) . '$' . base64_encode($digest);
}

function spValidHash(string $encoded): bool {
    $parts = explode('$', $encoded);
    return count($parts) === 3 && ctype_digit($parts[0]) && (int)$parts[0] >= 10000
        && base64_decode($parts[1], true) !== false && base64_decode($parts[2], true) !== false;
}

function spCheckHash(string $pin, string $encoded): bool {
    if (!spValidHash($encoded)) return false;
    [$iterations, $saltB64, $digestB64] = explode('$', $encoded);
    $salt = base64_decode($saltB64, true);
    $expected = base64_decode($digestB64, true);
    $actual = hash_pbkdf2('sha256', $pin, $salt, (int)$iterations, strlen($expected), true);
    return hash_equals($expected, $actual);
}

function spGet(Database $db, int $userId): ?array {
    spEnsureTable($db);
    $row = $db->fetch("SELECT * FROM safety_pins WHERE user_id = ?", [$userId]);
    return $row ?: null;
}

function spIsSet(Database $db, int $userId): bool {
    return spGet($db, $userId) !== null;
}

/** Store a hash (null removes the PIN). $updatedAt: UTC "Y-m-d H:i:s". */
function spStoreHash(Database $db, int $userId, ?string $hash, ?string $updatedAt = null): void {
    spEnsureTable($db);
    if ($hash === null || $hash === '') {
        $db->delete("DELETE FROM safety_pins WHERE user_id = ?", [$userId]);
        return;
    }
    $updatedAt = $updatedAt ?: spNowUtc();
    $db->query(
        "INSERT INTO safety_pins (user_id, pin_hash, updated_at, failed_attempts, locked_until) VALUES (?, ?, ?, 0, 0)
         ON DUPLICATE KEY UPDATE pin_hash = VALUES(pin_hash), updated_at = VALUES(updated_at), failed_attempts = 0, locked_until = 0",
        [$userId, $hash, $updatedAt]
    );
}

/**
 * Check a PIN, counting wrong tries in the database (so the lock applies
 * across web, desktop and mobile). Returns ['ok' => bool, 'error' => ?string].
 */
function spVerify(Database $db, int $userId, string $pin): array {
    $row = spGet($db, $userId);
    if (!$row) {
        return ['ok' => false, 'error' => 'No Safety PIN is set. Create one in Settings.'];
    }
    $now = time();
    if ((int)$row['locked_until'] > $now) {
        return ['ok' => false, 'error' => 'Too many wrong tries. Wait ' . ((int)$row['locked_until'] - $now) . ' seconds.'];
    }
    if (spCheckHash(trim($pin), $row['pin_hash'])) {
        if ((int)$row['failed_attempts'] !== 0) {
            $db->update("UPDATE safety_pins SET failed_attempts = 0, locked_until = 0 WHERE user_id = ?", [$userId]);
        }
        return ['ok' => true, 'error' => null];
    }
    $attempts = (int)$row['failed_attempts'] + 1;
    if ($attempts >= SP_MAX_ATTEMPTS) {
        $db->update("UPDATE safety_pins SET failed_attempts = 0, locked_until = ? WHERE user_id = ?",
            [$now + SP_LOCK_SECONDS, $userId]);
        return ['ok' => false, 'error' => 'Wrong PIN. Too many tries -- locked for ' . SP_LOCK_SECONDS . ' seconds.'];
    }
    $db->update("UPDATE safety_pins SET failed_attempts = ? WHERE user_id = ?", [$attempts, $userId]);
    return ['ok' => false, 'error' => 'Wrong PIN.'];
}

function spLog(Database $db, int $userId, string $message): void {
    try {
        $db->insert("INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)",
            [$userId, 'security', $message, $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (Exception $e) {
        // logging must never block the change
    }
}

// ------------------------------------------------------------ web session

function spIsUnlocked(): bool {
    return !empty($_SESSION['safety_pin_unlocked']);
}

function spUnlockSession(): void {
    $_SESSION['safety_pin_unlocked'] = true;
}

function spLockSession(): void {
    unset($_SESSION['safety_pin_unlocked']);
}

/** True when this request would change or delete an existing record. */
function spIsProtectedRequest(): bool {
    $page = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (in_array($page, SP_EXEMPT_PAGES, true)) return false;

    foreach (['edit', 'delete', 'edit_id'] as $key) {
        if (isset($_GET[$key]) && $_GET[$key] !== '' && $_GET[$key] !== '0') return true;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return false;

    if (isset($_POST['edit_id']) && $_POST['edit_id'] !== '' && $_POST['edit_id'] !== '0') return true;
    foreach (['action', 'folder_action', 'record_action', 'map_action', 'fix_action'] as $key) {
        $value = $_POST[$key] ?? '';
        if (is_string($value) && preg_match('/(delete|update|edit|rename|remove|toggle|move|reorder)/i', $value)) {
            return true;
        }
    }
    return false;
}

/** Address of this page with the edit/delete parameters taken off. */
function spSafeReturnUrl(): string {
    $params = $_GET;
    foreach (['edit', 'delete', 'edit_id', 'csrf'] as $key) unset($params[$key]);
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    return $path . ($params ? '?' . http_build_query($params) : '');
}

/**
 * Called from includes/header.php for every page, before the page handles
 * its own actions. Stops edit/delete requests until the PIN is entered.
 */
function spGuardRequest(Database $db, ?array $user): void {
    if (!$user || spIsUnlocked() || !spIsProtectedRequest()) return;
    if (!spIsSet($db, (int)$user['id'])) return;

    if (isAjax()) {
        jsonResponse(['success' => false, 'safety_pin_required' => true,
            'error' => 'Enter your Safety PIN to edit or delete.'], 423);
    }
    // After the PIN is entered the browser goes straight to what was asked for
    // (GET links only -- a blocked form's typed data can't be replayed).
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $_SESSION['safety_pin_return'] = $_SERVER['REQUEST_URI'] ?? '';
    }
    setFlash('error', 'Enter your Safety PIN to edit or delete.');
    $return = spSafeReturnUrl();
    $return .= (strpos($return, '?') === false ? '?' : '&') . 'safety_pin=1';
    header('Location: ' . $return);
    exit;
}
