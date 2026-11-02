<?php
/**
 * At-rest encryption for sensitive secrets (broker API keys/tokens).
 * AES-256-GCM, keyed from APP_ENCRYPTION_KEY (config/database.php).
 */

function pds_encrypt(?string $plaintext): ?string {
    if ($plaintext === null || $plaintext === '') return null;

    $key = hash('sha256', APP_ENCRYPTION_KEY, true);
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ciphertext === false) return null;

    return base64_encode($iv . $tag . $ciphertext);
}

function pds_decrypt(?string $encoded): ?string {
    if ($encoded === null || $encoded === '') return null;

    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 28) return null;

    $key = hash('sha256', APP_ENCRYPTION_KEY, true);
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);

    $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $plaintext === false ? null : $plaintext;
}

/**
 * Mask a secret for display, e.g. "GRWK1234...9F2A" -> "GRWK1***…***9F2A"
 */
function pds_mask_secret(?string $value, int $head = 4, int $tail = 4): string {
    if ($value === null || $value === '') return '';
    $len = strlen($value);
    if ($len <= $head + $tail) return str_repeat('*', $len);
    return substr($value, 0, $head) . str_repeat('*', min(8, $len - $head - $tail)) . substr($value, -$tail);
}
