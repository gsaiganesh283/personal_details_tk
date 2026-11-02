<?php
/**
 * TOTP (Time-based One-Time Password) Implementation
 * Compatible with Google Authenticator, Authy, Microsoft Authenticator, etc.
 * 
 * RFC 6238 compliant - no external dependencies required.
 */

class TOTP {
    private $issuer;
    private $digits;
    private $period;
    private $algorithm;

    /**
     * @param string $issuer    App name shown in authenticator app
     * @param int    $digits    OTP length (default 6)
     * @param int    $period    Time step in seconds (default 30)
     * @param string $algorithm Hash algorithm (default sha1)
     */
    public function __construct($issuer = 'Personal Details Saver', $digits = 6, $period = 30, $algorithm = 'sha1') {
        $this->issuer = $issuer;
        $this->digits = $digits;
        $this->period = $period;
        $this->algorithm = $algorithm;
    }

    /**
     * Generate a random base32-encoded secret key
     * 
     * @param int $length Number of bytes (default 20 = 160 bits, recommended by RFC)
     * @return string Base32-encoded secret
     */
    public function generateSecret($length = 20) {
        $bytes = random_bytes($length);
        return $this->base32Encode($bytes);
    }

    /**
     * Generate TOTP code for current time
     * 
     * @param string $secret Base32-encoded secret
     * @param int|null $timestamp Unix timestamp (null = current time)
     * @return string OTP code (zero-padded)
     */
    public function getCode($secret, $timestamp = null) {
        if ($timestamp === null) {
            $timestamp = time();
        }

        $timeCounter = floor($timestamp / $this->period);
        $binaryKey = $this->base32Decode($secret);

        // Pack time counter as 8-byte big-endian
        $timeBytes = pack('N*', 0, $timeCounter);

        // HMAC-SHA1
        $hash = hash_hmac($this->algorithm, $timeBytes, $binaryKey, true);

        // Dynamic truncation (RFC 4226 §5.4)
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        $otp = $binary % pow(10, $this->digits);
        return str_pad($otp, $this->digits, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a TOTP code with time window tolerance
     * 
     * @param string $secret Base32-encoded secret
     * @param string $code   User-provided OTP code
     * @param int    $window Number of periods to check before/after (default 1 = ±30s)
     * @return bool True if code is valid
     */
    public function verifyCode($secret, $code, $window = 1) {
        $code = str_pad(trim($code), $this->digits, '0', STR_PAD_LEFT);
        $now = time();

        for ($i = -$window; $i <= $window; $i++) {
            $checkTime = $now + ($i * $this->period);
            $expected = $this->getCode($secret, $checkTime);
            if (hash_equals($expected, $code)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Generate otpauth:// URI for QR code generation
     * 
     * @param string $secret  Base32-encoded secret
     * @param string $account User's email or username
     * @return string otpauth URI
     */
    public function getOtpAuthUri($secret, $account) {
        $params = [
            'secret' => $secret,
            'issuer' => $this->issuer,
            'algorithm' => strtoupper($this->algorithm),
            'digits' => $this->digits,
            'period' => $this->period,
        ];

        $label = rawurlencode($this->issuer) . ':' . rawurlencode($account);
        return 'otpauth://totp/' . $label . '?' . http_build_query($params);
    }

    /**
     * Get QR code image URL using Google Charts API
     * 
     * @param string $secret  Base32-encoded secret
     * @param string $account User's email or username
     * @param int    $size    QR code size in pixels
     * @return string URL to QR code image
     */
    public function getQrCodeUrl($secret, $account, $size = 200) {
        $uri = $this->getOtpAuthUri($secret, $account);
        return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size . '&data=' . urlencode($uri);
    }

    /**
     * Base32 encode binary data
     * @param string $data Binary data
     * @return string Base32-encoded string
     */
    private function base32Encode($data) {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binary = '';
        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        $chunks = str_split($binary, 5);
        foreach ($chunks as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $encoded .= $alphabet[bindec($chunk)];
        }

        return $encoded;
    }

    /**
     * Base32 decode to binary data
     * @param string $data Base32-encoded string
     * @return string Binary data
     */
    private function base32Decode($data) {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $data = strtoupper(rtrim($data, '='));

        $binary = '';
        foreach (str_split($data) as $char) {
            $pos = strpos($alphabet, $char);
            if ($pos === false) continue;
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        $bytes = str_split($binary, 8);
        foreach ($bytes as $byte) {
            if (strlen($byte) < 8) break;
            $decoded .= chr(bindec($byte));
        }

        return $decoded;
    }
}
