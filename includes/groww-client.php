<?php
/**
 * Groww Trading API client.
 * Docs: https://groww.in/trade-api/docs
 *
 * Supports three ways of authenticating a stored connection:
 *  - access_token : user pastes a token generated on groww.in (expires daily ~6am IST)
 *  - api_secret   : API key + secret, token auto-refreshed via SHA-256 checksum
 *  - totp         : API key + TOTP secret, token auto-refreshed using a generated code
 *
 * Groww requires the underlying API key to be approved (once daily) on
 * https://groww.in/trade-api/api-keys regardless of method — if a refresh
 * fails, callers should treat it as "reconnect required", not a hard error.
 */

class GrowwException extends Exception {}
class GrowwAuthException extends GrowwException {}

class GrowwClient {
    const BASE_URL = 'https://api.groww.in';

    private $userId;
    private $db;
    private $creds; // decrypted row from groww_credentials, or null

    public function __construct(int $userId) {
        $this->userId = $userId;
        $this->db = Database::getInstance();
        $this->loadCredentials();
    }

    public function isConnected(): bool {
        return $this->creds !== null && !empty($this->creds['is_active']);
    }

    public function getAuthMethod(): ?string {
        return $this->creds['auth_method'] ?? null;
    }

    public function getLastError(): ?string {
        return $this->creds['last_error'] ?? null;
    }

    private function loadCredentials(): void {
        $row = $this->db->fetch("SELECT * FROM groww_credentials WHERE user_id = ?", [$this->userId]);
        if (!$row) {
            $this->creds = null;
            return;
        }
        $row['api_key'] = pds_decrypt($row['api_key_enc']);
        $row['api_secret'] = pds_decrypt($row['api_secret_enc']);
        $row['totp_secret'] = pds_decrypt($row['totp_secret_enc']);
        $row['access_token'] = pds_decrypt($row['access_token_enc']);
        $this->creds = $row;
    }

    /**
     * Persist a fresh access token + expiry for this user.
     */
    private function storeToken(string $token, ?string $expiryIso): void {
        $expiresAt = $expiryIso ? date('Y-m-d H:i:s', strtotime($expiryIso)) : $this->nextDailyExpiry();
        $this->db->query(
            "UPDATE groww_credentials SET access_token_enc = ?, token_expires_at = ?, last_error = NULL, updated_at = NOW() WHERE user_id = ?",
            [pds_encrypt($token), $expiresAt, $this->userId]
        );
        $this->creds['access_token'] = $token;
        $this->creds['token_expires_at'] = $expiresAt;
    }

    private function storeError(string $message): void {
        $this->db->query(
            "UPDATE groww_credentials SET last_error = ?, updated_at = NOW() WHERE user_id = ?",
            [substr($message, 0, 250), $this->userId]
        );
        if ($this->creds) $this->creds['last_error'] = $message;
    }

    private function nextDailyExpiry(): string {
        // Groww tokens expire daily at 6:00 AM IST.
        $tz = new DateTimeZone('Asia/Kolkata');
        $now = new DateTime('now', $tz);
        $expiry = new DateTime($now->format('Y-m-d') . ' 06:00:00', $tz);
        if ($now >= $expiry) $expiry->modify('+1 day');
        return $expiry->format('Y-m-d H:i:s');
    }

    /**
     * Return a currently-valid access token, refreshing it first if needed.
     * Throws GrowwAuthException if no valid token could be obtained.
     */
    public function getAccessToken(): string {
        if (!$this->isConnected()) {
            throw new GrowwAuthException('Groww is not connected. Add your API credentials first.');
        }

        $expiresAt = $this->creds['token_expires_at'] ?? null;
        $stillValid = !empty($this->creds['access_token']) && $expiresAt && strtotime($expiresAt) > time() + 30;

        if ($stillValid) {
            return $this->creds['access_token'];
        }

        return $this->refreshToken();
    }

    private function refreshToken(): string {
        $method = $this->creds['auth_method'];

        if ($method === 'access_token') {
            if (empty($this->creds['access_token'])) {
                throw new GrowwAuthException('No access token saved. Paste a fresh token from groww.in.');
            }
            // User-supplied token — we can't auto-refresh it, just use it and
            // let the caller surface a reconnect prompt on 401.
            return $this->creds['access_token'];
        }

        if (empty($this->creds['api_key'])) {
            throw new GrowwAuthException('Missing Groww API key.');
        }

        $body = [];
        if ($method === 'api_secret') {
            if (empty($this->creds['api_secret'])) {
                throw new GrowwAuthException('Missing Groww API secret.');
            }
            $timestamp = (string) time();
            $checksum = hash('sha256', $this->creds['api_secret'] . $timestamp);
            $body = ['key_type' => 'approval', 'checksum' => $checksum, 'timestamp' => $timestamp];
        } elseif ($method === 'totp') {
            if (empty($this->creds['totp_secret'])) {
                throw new GrowwAuthException('Missing Groww TOTP secret.');
            }
            require_once __DIR__ . '/totp.php';
            $totp = new TOTP('Groww API', 6, 30);
            $code = $totp->getCode($this->creds['totp_secret']);
            $body = ['key_type' => 'totp', 'totp' => $code];
        } else {
            throw new GrowwAuthException('Unknown Groww auth method.');
        }

        try {
            [$status, $data] = $this->rawRequest('POST', '/v1/token/api/access', [], $body, [
                'Authorization: Bearer ' . $this->creds['api_key'],
                'Content-Type: application/json',
            ]);
        } catch (Exception $e) {
            $this->storeError('Token refresh failed: ' . $e->getMessage());
            throw new GrowwAuthException('Could not refresh Groww access token: ' . $e->getMessage());
        }

        $token = $this->findValue($data, ['token', 'access_token']);
        $expiry = $this->findValue($data, ['expiry', 'expires_at']);

        if ($status >= 400 || !$token) {
            $msg = $this->extractErrorMessage($data, $status);
            $this->storeError('Token refresh rejected: ' . $msg);
            throw new GrowwAuthException(
                'Groww rejected the token request (' . $msg . '). ' .
                'You may need to approve this API key today at groww.in/trade-api/api-keys.'
            );
        }

        $this->storeToken($token, $expiry);
        return $token;
    }

    /**
     * Authenticated GET/POST against the Groww API. Auto-retries once after
     * a forced token refresh if the first attempt gets a 401.
     */
    private function apiRequest(string $method, string $path, array $query = [], ?array $body = null): array {
        $token = $this->getAccessToken();
        [$status, $data] = $this->rawRequest($method, $path, $query, $body, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'X-API-VERSION: 1.0',
            'Content-Type: application/json',
        ]);

        if ($status === 401 && $this->creds['auth_method'] !== 'access_token') {
            // Token likely stale despite our expiry bookkeeping — force one refresh.
            $this->creds['token_expires_at'] = null;
            $token = $this->refreshToken();
            [$status, $data] = $this->rawRequest($method, $path, $query, $body, [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
                'X-API-VERSION: 1.0',
                'Content-Type: application/json',
            ]);
        }

        if ($status >= 400) {
            $msg = $this->extractErrorMessage($data, $status);
            throw new GrowwException('Groww API error on ' . $path . ': ' . $msg);
        }

        return $data;
    }

    /** Groww error responses look like {"errorCode":401,"errorMessage":{"message":"..."}} — but be tolerant of other shapes too. */
    private function extractErrorMessage(array $data, int $status): string {
        $em = $data['errorMessage'] ?? null;
        if (is_array($em)) return $em['message'] ?? json_encode($em);
        if (is_string($em) && $em !== '') return $em;

        $errField = $data['error'] ?? null;
        if (is_array($errField)) {
            if (!empty($errField['errorMessage']) && is_string($errField['errorMessage'])) return $errField['errorMessage'];
            if (!empty($errField['message'])) return $errField['message'];
        }
        if (is_string($errField) && $errField !== '') return $errField;

        if (!empty($data['message'])) return $data['message'];

        // No recognizable field — surface the raw body so the real cause isn't hidden behind "HTTP 400".
        $dump = json_encode($data);
        return 'HTTP ' . $status . ($dump && $dump !== '[]' && $dump !== '{}' ? ' — ' . substr($dump, 0, 200) : '');
    }

    private function rawRequest(string $method, string $path, array $query = [], ?array $body = null, array $headers = []): array {
        $url = self::BASE_URL . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        if (function_exists('curl_init')) {
            [$status, $response] = $this->rawRequestViaCurl($method, $url, $body, $headers);
        } else {
            // Some hosts (incl. this project's PHP 8.3 dev server) ship without
            // the cURL extension — fall back to a stream-based HTTP client.
            [$status, $response] = $this->rawRequestViaStream($method, $url, $body, $headers);
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            $data = ['message' => substr((string) $response, 0, 200)];
        }

        return [$status, $data];
    }

    private function rawRequestViaCurl(string $method, string $url, ?array $body, array $headers): array {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new GrowwException('Network error contacting Groww: ' . $error);
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, $response];
    }

    private function rawRequestViaStream(string $method, string $url, ?array $body, array $headers): array {
        $opts = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body !== null ? json_encode($body) : '',
                'timeout' => 20,
                'ignore_errors' => true, // let us read error response bodies too
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ];

        $context = stream_context_create($opts);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $error = error_get_last();
            throw new GrowwException('Network error contacting Groww: ' . ($error['message'] ?? 'unknown error'));
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m)) {
                $status = (int) $m[1];
            }
        }

        return [$status, $response];
    }

    // ------------------------------------------------------------------
    // Public data endpoints
    // ------------------------------------------------------------------

    /** Unwrap a possible {"payload": {...}} or {"data": {...}} envelope. */
    private function unwrap(array $data): array {
        if (isset($data['payload']) && is_array($data['payload'])) return $data['payload'];
        if (isset($data['data']) && is_array($data['data'])) return $data['data'];
        return $data;
    }

    /** Look up the first present key, checking top-level then inside payload/data envelopes. */
    private function findValue(array $data, array $keys) {
        foreach ($keys as $k) {
            if (isset($data[$k])) return $data[$k];
        }
        foreach (['payload', 'data'] as $wrapper) {
            if (isset($data[$wrapper]) && is_array($data[$wrapper])) {
                foreach ($keys as $k) {
                    if (isset($data[$wrapper][$k])) return $data[$wrapper][$k];
                }
            }
        }
        return null;
    }

    public function getHoldings(): array {
        $data = $this->unwrap($this->apiRequest('GET', '/v1/holdings/user'));
        return $data['holdings'] ?? [];
    }

    public function getPositions(?string $segment = null): array {
        $query = $segment ? ['segment' => $segment] : [];
        $data = $this->unwrap($this->apiRequest('GET', '/v1/positions/user', $query));
        return $data['positions'] ?? [];
    }

    public function getOrders(?string $segment = null, int $page = 0, int $pageSize = 50): array {
        $query = ['page' => $page, 'page_size' => $pageSize];
        if ($segment) $query['segment'] = $segment;
        $data = $this->unwrap($this->apiRequest('GET', '/v1/order/list', $query));
        return $data['order_list'] ?? [];
    }

    public function getMargin(): array {
        return $this->unwrap($this->apiRequest('GET', '/v1/margins/detail/user'));
    }

    /** @param string[] $exchangeSymbols e.g. ["NSE_RELIANCE", "NSE_TCS"] (max 50) */
    public function getLtp(array $exchangeSymbols, string $segment = 'CASH'): array {
        if (empty($exchangeSymbols)) return [];
        return $this->unwrap($this->apiRequest('GET', '/v1/live-data/ltp', [
            'segment' => $segment,
            'exchange_symbols' => implode(',', array_slice($exchangeSymbols, 0, 50)),
        ]));
    }

    public function getQuote(string $exchange, string $segment, string $tradingSymbol): array {
        return $this->unwrap($this->apiRequest('GET', '/v1/live-data/quote', [
            'exchange' => $exchange,
            'segment' => $segment,
            'trading_symbol' => $tradingSymbol,
        ]));
    }

    /**
     * Lightweight credential check used by the settings page — attempts a
     * token refresh (or a holdings call for access_token mode) and reports
     * success/failure without throwing.
     */
    public function testConnection(): array {
        try {
            $this->getAccessToken();
            $this->getHoldings();
            return ['success' => true, 'message' => 'Connected successfully.'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
