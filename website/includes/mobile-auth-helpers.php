<?php
/**
 * Bearer-token auth for the native Android app. Separate from the website's
 * session-cookie login (includes/auth.php) and from desktop-sync-api.php's
 * shared-secret X-Sync-Key scheme -- this issues one opaque token per mobile
 * login, resolved server-side to a real user_id on every request (never
 * trusted from the client), so multiple phones/logins can't collide and a
 * lost/old token can be revoked without touching the desktop app's key.
 */

function ensureApiTokensTable(Database $db): void {
    $db->query("CREATE TABLE IF NOT EXISTS `api_tokens` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        device_label VARCHAR(100) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_used_at TIMESTAMP NULL DEFAULT NULL,
        expires_at TIMESTAMP NULL DEFAULT NULL,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Mints a new token for $userId, returns the raw token -- only ever visible this once. */
function issueApiToken(Database $db, int $userId, ?string $deviceLabel = null): string {
    $token = bin2hex(random_bytes(32));
    $db->insert(
        "INSERT INTO api_tokens (user_id, token_hash, device_label) VALUES (?, ?, ?)",
        [$userId, hash('sha256', $token), $deviceLabel]
    );
    return $token;
}

/** Resolves a raw bearer token to a user_id, or null if missing/expired/unknown. Bumps last_used_at. */
function resolveApiToken(Database $db, string $rawToken): ?int {
    if ($rawToken === '') {
        return null;
    }
    $row = $db->fetch(
        "SELECT id, user_id FROM api_tokens WHERE token_hash = ? AND (expires_at IS NULL OR expires_at > NOW())",
        [hash('sha256', $rawToken)]
    );
    if (!$row) {
        return null;
    }
    $db->update("UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?", [$row['id']]);
    return (int)$row['user_id'];
}

/** Deletes the row for $rawToken (logout). Returns true if a row was actually removed. */
function revokeApiToken(Database $db, string $rawToken): bool {
    if ($rawToken === '') {
        return false;
    }
    return $db->delete("DELETE FROM api_tokens WHERE token_hash = ?", [hash('sha256', $rawToken)]) > 0;
}

/**
 * Reads the bearer token from the Authorization header, with a fallback to
 * apache_request_headers() for hosts that strip HTTP_AUTHORIZATION, and a
 * final ?token= query fallback -- needed because handing a file-download URL
 * to an external viewer Intent on Android can't attach custom headers.
 */
function extractBearerToken(): ?string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (is_string($header) && stripos($header, 'Bearer ') === 0) {
        return trim(substr($header, 7));
    }
    if (!empty($_GET['token']) && is_string($_GET['token'])) {
        return $_GET['token'];
    }
    return null;
}
