<?php
/**
 * Google Drive Integration
 * Handles file uploads to Google Drive instead of local storage
 * 
 * Setup Instructions:
 * 1. Go to Google Cloud Console: https://console.cloud.google.com
 * 2. Create a new project
 * 3. Enable Google Drive API
 * 4. Create OAuth 2.0 credentials (Service Account or Desktop app)
 * 5. Download JSON credentials file
 * 6. Save as config/google-drive-config.json
 */

class GoogleDriveIntegration {
    const SCOPE = 'https://www.googleapis.com/auth/drive.file';
    const APP_FOLDER_NAME = 'PDS Backup'; // same name the desktop app uses
    const LOOPBACK_REDIRECT = 'http://127.0.0.1:53682/';

    private $accessToken;
    private $refreshToken;
    private $clientId;
    private $clientSecret;
    private $redirectUri;
    private $driveFolderId; // Root folder ID for storing files
    private $authError = null; // set when Google rejected the saved login (invalid_grant)
    
    public function __construct() {
        // Load configuration
        $configFile = __DIR__ . '/../config/google-drive-config.php';
        
        if (!file_exists($configFile)) {
            throw new Exception("Google Drive config not found. Run setup-google-drive.php first.");
        }
        
        $config = require $configFile;
        $this->clientId = $config['client_id'] ?? null;
        $this->clientSecret = $config['client_secret'] ?? null;
        $this->redirectUri = $config['redirect_uri'] ?? null;
        $this->driveFolderId = $config['drive_folder_id'] ?? 'root';
        
        // Try to load stored tokens
        $this->loadStoredTokens();
    }
    
    /**
     * Get authorization URL for user to grant access
     * Uses 'Desktop app' flow — no redirect URI domain validation required.
     * User will see the auth code on screen and paste it back.
     */
    /**
     * Google retired the copy-paste "oob" redirect, so Desktop-app clients use the
     * loopback address instead: after "Allow", the browser lands on a
     * http://localhost/?code=... page (it won't load -- that's expected) and the
     * user pastes that address (or just the code) back into Profile.
     */
    private function effectiveRedirectUri() {
        $uri = rtrim((string) $this->redirectUri, '/');
        // Plain http://localhost hits any local web server (XAMPP redirects it to
        // /dashboard/ and the code is lost), so use a loopback port nothing
        // listens on: the page fails to load and the code stays in the address bar.
        // Desktop-app OAuth clients accept any loopback port without registering it.
        if (!$uri || strpos($uri, 'urn:ietf:wg:oauth:2.0:oob') === 0 || $uri === 'http://localhost') {
            return self::LOOPBACK_REDIRECT;
        }
        return $this->redirectUri;
    }

    public function getAuthorizationUrl() {
        $redirectUri = $this->effectiveRedirectUri();
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            // drive.file = only files/folders this app created. Unlike full "drive"
            // access it's a non-sensitive scope, so Google shows no
            // "hasn't verified this app" warning and needs no paid review.
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent'
        ];
        
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }
    
    /**
     * Exchange authorization code for tokens
     */
    public function handleAuthorizationCode($code) {
        $redirectUri = $this->effectiveRedirectUri();
        // Accept the whole pasted address (http://localhost/?code=...&scope=...) or just the code.
        $code = trim($code);
        if (strpos($code, 'code=') !== false) {
            parse_str((string) parse_url($code, PHP_URL_QUERY) ?: $code, $q);
            $code = $q['code'] ?? $code;
        }
        $postData = [
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code'
        ];
        
        $response = $this->makeRequest('https://oauth2.googleapis.com/token', $postData);

        if (isset($response['access_token'])) {
            $this->accessToken = $response['access_token'];
            // Keep the previous refresh token if Google didn't send a new one.
            $this->refreshToken = $response['refresh_token'] ?? $this->refreshToken;
            $this->authError = null;
            $this->saveTokens();
            return true;
        }
        
        return false;
    }
    
    /**
     * Refresh access token using refresh token
     */
    private function refreshAccessToken() {
        if (!$this->refreshToken) {
            throw new Exception("No refresh token available. Re-authorize the application.");
        }
        
        $postData = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $this->refreshToken,
            'grant_type' => 'refresh_token'
        ];
        
        try {
            $response = $this->makeRequest('https://oauth2.googleapis.com/token', $postData);
        } catch (Exception $e) {
            // invalid_grant = Google permanently rejected the saved login (revoked, or the
            // 7-day limit of an OAuth app left in "Testing" mode). Anything else (network,
            // 5xx) is temporary: keep the tokens so the next request simply retries.
            if (strpos($e->getMessage(), 'invalid_grant') !== false) {
                $this->authError = date('d M Y h:i A');
                $this->saveTokens();
                throw new Exception("Google ended the Drive authorization (expired or revoked). Re-authorize in Profile > Google Drive. "
                    . "If this happens every 7 days, publish your OAuth app (Google Cloud Console > Google Auth Platform > Audience > Publish app).");
            }
            throw new Exception("Couldn't refresh the Google Drive login right now: " . $e->getMessage());
        }

        if (isset($response['access_token'])) {
            $this->accessToken = $response['access_token'];
            // Google may rotate the refresh token; keep the newest one.
            if (!empty($response['refresh_token'])) {
                $this->refreshToken = $response['refresh_token'];
            }
            $this->authError = null;
            $this->saveTokens();
            return true;
        }

        throw new Exception("Failed to refresh access token");
    }
    
    /**
     * Upload file to Google Drive
     */
    public function uploadFile($filePath, $fileName, $mimeType = 'application/octet-stream') {
        if (!file_exists($filePath)) {
            throw new Exception("File not found: $filePath");
        }
        
        $fileSize = filesize($filePath);
        $fileContent = file_get_contents($filePath);
        
        // Prepare metadata
        $metadata = [
            'name' => $fileName,
            'parents' => [$this->driveFolderId],
            'mimeType' => $mimeType
        ];
        
        // Create multipart upload
        $boundary = '===============' . md5(uniqid()) . '==============';
        $eol = "\r\n";
        
        $body = '';
        $body .= '--' . $boundary . $eol;
        $body .= 'Content-Type: application/json; charset=UTF-8' . $eol . $eol;
        $body .= json_encode($metadata) . $eol;
        $body .= '--' . $boundary . $eol;
        $body .= 'Content-Type: ' . $mimeType . $eol;
        $body .= 'Content-Transfer-Encoding: binary' . $eol . $eol;
        $body .= $fileContent . $eol;
        $body .= '--' . $boundary . '--';
        
        $headers = [
            'Content-Type: multipart/related; boundary=' . $boundary,
            'Authorization: Bearer ' . $this->accessToken
        ];
        
        try {
            $response = $this->makeMultipartRequest(
                'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart',
                $body,
                $headers
            );
            
            if (isset($response['id'])) {
                return $response['id']; // Return Google Drive file ID
            }
        } catch (Exception $e) {
            // Try to refresh token and retry
            if (strpos($e->getMessage(), '401') !== false) {
                $this->refreshAccessToken();
                return $this->uploadFile($filePath, $fileName, $mimeType);
            }
            throw $e;
        }
        
        throw new Exception("Failed to upload file to Google Drive");
    }
    
    /**
     * Get the currently configured folder ID
     */
    public function getCurrentFolderId() {
        return $this->driveFolderId ?: 'root';
    }
    
    /**
     * List folders inside a given parent folder
     * Returns array of ['id' => ..., 'name' => ...] sorted by name
     */
    public function listFolders($parentId = null) {
        if (!$parentId) {
            $parentId = 'root';
        }
        
        $query = "mimeType = 'application/vnd.google-apps.folder' AND '" . $parentId . "' in parents AND trashed = false";
        $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query([
            'q' => $query,
            'fields' => 'files(id,name)',
            'orderBy' => 'name',
            'pageSize' => 100
        ]);
        
        [$httpCode, $response] = $this->httpGet($url, ['Authorization: Bearer ' . $this->accessToken]);
        
        if ($httpCode === 401) {
            // Token expired — refresh and retry
            $this->refreshAccessToken();
            return $this->listFolders($parentId);
        }
        
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            return $data['files'] ?? [];
        }
        
        throw new Exception("Failed to list folders: HTTP $httpCode");
    }
    
    /**
     * Get folder name by ID
     */
    public function getFolderName($folderId) {
        if (!$folderId || $folderId === 'root') {
            return 'My Drive (Root)';
        }
        
        $url = 'https://www.googleapis.com/drive/v3/files/' . urlencode($folderId) . '?fields=name';
        
        [$httpCode, $response] = $this->httpGet($url, ['Authorization: Bearer ' . $this->accessToken]);
        
        if ($httpCode === 401) {
            $this->refreshAccessToken();
            return $this->getFolderName($folderId);
        }
        
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            return $data['name'] ?? 'Unknown Folder';
        }
        
        return 'Unknown Folder';
    }
    
    /**
     * Create a new folder in Google Drive
     * Returns the new folder ID
     */
    public function createFolder($folderName, $parentId = null) {
        if (!$parentId) {
            $parentId = $this->driveFolderId ?: 'root';
        }
        
        $metadata = json_encode([
            'name' => $folderName,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => [$parentId]
        ]);
        
        [$httpCode, $response] = $this->httpPost(
            'https://www.googleapis.com/drive/v3/files',
            $metadata,
            ['Authorization: Bearer ' . $this->accessToken, 'Content-Type: application/json']
        );
        
        if ($httpCode === 401) {
            $this->refreshAccessToken();
            return $this->createFolder($folderName, $parentId);
        }
        
        if (in_array($httpCode, [200, 201])) {
            $data = json_decode($response, true);
            return $data['id'] ?? null;
        }
        
        throw new Exception("Failed to create folder: HTTP $httpCode");
    }

    /**
     * With drive.file access the app can only use folders it created, so make sure
     * uploads go to one: keep the saved folder if it's still reachable, else reuse
     * an existing "PDS Backup" folder made by this app (or the desktop app, which
     * shares the same OAuth client), else create it. Saves the choice to config.
     * Returns ['id' => ..., 'name' => ...].
     */
    public function ensureAppFolder() {
        $current = $this->driveFolderId;
        if ($current && $current !== 'root' && ($info = $this->getFileInfo($current))) {
            return ['id' => $current, 'name' => $info['name'] ?? self::APP_FOLDER_NAME];
        }

        $query = "mimeType = 'application/vnd.google-apps.folder' AND name = '" . self::APP_FOLDER_NAME
            . "' AND 'root' in parents AND trashed = false";
        $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query(['q' => $query, 'fields' => 'files(id,name)', 'pageSize' => 1]);
        [$httpCode, $response] = $this->httpGet($url, ['Authorization: Bearer ' . $this->accessToken]);
        if ($httpCode === 401) {
            $this->refreshAccessToken();
            [$httpCode, $response] = $this->httpGet($url, ['Authorization: Bearer ' . $this->accessToken]);
        }
        $found = $httpCode === 200 ? (json_decode($response, true)['files'][0]['id'] ?? null) : null;
        $folderId = $found ?: $this->createFolder(self::APP_FOLDER_NAME, 'root');
        if (!$folderId) {
            throw new Exception("Couldn't create the " . self::APP_FOLDER_NAME . " folder in Google Drive.");
        }

        $configFile = __DIR__ . '/../config/google-drive-config.php';
        $config = file_exists($configFile) ? (require $configFile) : [];
        $config['drive_folder_id'] = $folderId;
        $config['drive_folder_name'] = self::APP_FOLDER_NAME;
        file_put_contents($configFile, '<?php return ' . var_export($config, true) . ';' . "\n");
        $this->driveFolderId = $folderId;
        return ['id' => $folderId, 'name' => self::APP_FOLDER_NAME];
    }

    /**
     * Get download URL for file
     */
    public function getDownloadUrl($googleDriveFileId) {
        return 'https://drive.google.com/uc?export=download&id=' . urlencode($googleDriveFileId);
    }
    
    /**
     * Stream file directly from Google Drive (optimized for speed)
     */
    /**
     * Download a Drive file to a local path (used to attach it to a push).
     * Returns true on success. Refreshes the access token once on 401.
     */
    public function downloadToFile($googleDriveFileId, $destPath, $retried = false) {
        $url = 'https://www.googleapis.com/drive/v3/files/' . urlencode($googleDriveFileId) . '?alt=media';
        $fh = fopen($destPath, 'wb');
        if (!$fh) return false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->accessToken],
            CURLOPT_FILE => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 120,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fh);
        if ($code === 401 && !$retried) {
            $this->refreshAccessToken();
            return $this->downloadToFile($googleDriveFileId, $destPath, true);
        }
        if (($code === 403 || $code === 404) && $this->downloadPublic($googleDriveFileId, $destPath)) {
            return true;
        }
        if ($code < 200 || $code >= 300) {
            @unlink($destPath);
            return false;
        }
        return true;
    }

    /**
     * Public URL of an "anyone with the link" file. Files uploaded before the
     * switch to drive.file access aren't visible to this app's login any more,
     * but they were shared that way, so they still open through this link.
     */
    private function publicDownloadUrl($googleDriveFileId) {
        return 'https://drive.usercontent.google.com/download?' . http_build_query(['id' => $googleDriveFileId, 'export' => 'download', 'confirm' => 't']);
    }

    private function downloadPublic($googleDriveFileId, $destPath) {
        $fh = fopen($destPath, 'wb');
        if (!$fh) return false;
        $ch = curl_init($this->publicDownloadUrl($googleDriveFileId));
        curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 120]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        fclose($fh);
        if ($code !== 200 || stripos($type, 'text/html') === 0) { // login/error page, not the file
            @unlink($destPath);
            return false;
        }
        return true;
    }

    public function streamFile($googleDriveFileId, $fileName = null, $inline = false, $retried = false) {
        $url = 'https://www.googleapis.com/drive/v3/files/' . urlencode($googleDriveFileId) . '?alt=media';

        // Older files this app can't see: stream them from their public link instead.
        if (!$retried && $this->getFileInfo($googleDriveFileId) === null) {
            $tmp = tempnam(sys_get_temp_dir(), 'gdp');
            if ($tmp && $this->downloadPublic($googleDriveFileId, $tmp)) {
                while (ob_get_level()) ob_end_clean();
                $baseName = basename($fileName ?: 'download');
                header('Content-Type: ' . ($inline ? $this->guessMimeType($baseName) : 'application/octet-stream'));
                header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $baseName . '"');
                header('Content-Length: ' . filesize($tmp));
                readfile($tmp);
                @unlink($tmp);
                return;
            }
            if ($tmp) @unlink($tmp);
        }

        // Clean ALL output buffers for fastest streaming
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Disable time limit for large files
        set_time_limit(0);
        
        // Set response headers early
        if ($fileName) {
            $baseName = basename($fileName);
            if ($inline) {
                $mime = $this->guessMimeType($fileName);
                header('Content-Type: ' . $mime);
                header('Content-Disposition: inline; filename="' . $baseName . '"');
                header('Cache-Control: private, max-age=86400, immutable');
                header('X-Content-Type-Options: nosniff');
            } else {
                header('Content-Disposition: attachment; filename="' . $baseName . '"');
                header('Content-Type: application/octet-stream');
                header('Cache-Control: no-cache');
            }
        }
        
        // Stream via cURL (preferred — callbacks allow chunk-by-chunk output) or file_get_contents fallback
        if (function_exists('curl_init')) {
            $httpCode = 0;
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $this->accessToken]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_BUFFERSIZE, 65536);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1024);
            curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 30);
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $headerLine) use (&$httpCode) {
                if ($httpCode === 0) {
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                }
                if (stripos($headerLine, 'Content-Length:') === 0) {
                    header(trim($headerLine));
                }
                return strlen($headerLine);
            });
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) {
                echo $data;
                if (ob_get_level()) ob_flush();
                flush();
                return strlen($data);
            });
            curl_exec($ch);
            $finalCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            if ($finalCode === 401 && !$retried) {
                header_remove();
                $this->refreshAccessToken();
                return $this->streamFile($googleDriveFileId, $fileName, $inline, true);
            }
            if ($curlError) {
                error_log("Google Drive stream error: " . $curlError);
            }
        } else {
            // Fallback: loads entire file into memory then outputs it
            $opts = [
                'http' => [
                    'method'          => 'GET',
                    'header'          => 'Authorization: Bearer ' . $this->accessToken,
                    'ignore_errors'   => true,
                    'follow_location' => 1,
                ],
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
            ];
            $data = @file_get_contents($url, false, stream_context_create($opts));
            $fallbackCode = $this->parseHttpCode($http_response_header ?? []);
            if ($fallbackCode === 401 && !$retried) {
                header_remove();
                $this->refreshAccessToken();
                return $this->streamFile($googleDriveFileId, $fileName, $inline, true);
            }
            if ($data !== false) {
                echo $data;
            }
        }
    }

    private function guessMimeType($fileName) {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $mimeMap = [
            'pdf' => 'application/pdf',
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'txt' => 'text/plain', 'csv' => 'text/csv',
        ];
        return $mimeMap[$ext] ?? 'application/octet-stream';
    }
    
    /**
     * Delete file from Google Drive
     */
    public function deleteFile($googleDriveFileId, $retried = false) {
        $url = 'https://www.googleapis.com/drive/v3/files/' . urlencode($googleDriveFileId);
        
        [$httpCode] = $this->httpPost($url, '', ['Authorization: Bearer ' . $this->accessToken], 'DELETE');
        
        // Retry once after refreshing token on 401
        if ($httpCode === 401 && !$retried) {
            $this->refreshAccessToken();
            return $this->deleteFile($googleDriveFileId, true);
        }
        
        return $httpCode === 204;
    }
    
    /**
     * Get file metadata from Google Drive
     */
    public function getFileInfo($googleDriveFileId) {
        $url = 'https://www.googleapis.com/drive/v3/files/' . urlencode($googleDriveFileId) . '?fields=name,size,mimeType,createdTime,modifiedTime';
        
        [$httpCode, $response] = $this->httpGet($url, ['Authorization: Bearer ' . $this->accessToken]);

        if ($httpCode === 401) {
            $this->refreshAccessToken();
            [$httpCode, $response] = $this->httpGet($url, ['Authorization: Bearer ' . $this->accessToken]);
        }
        if ($httpCode === 200) {
            return json_decode($response, true);
        }

        return null;
    }
    
    /**
     * Make HTTP request with curl
     */
    private function makeRequest($url, $postData) {
        [$httpCode, $response] = $this->httpPost($url, http_build_query($postData));
        
        if ($httpCode === 200) {
            return json_decode($response, true);
        }
        
        throw new Exception("HTTP Error: $httpCode - $response");
    }
    
    /**
     * Make multipart request
     */
    private function makeMultipartRequest($url, $body, $headers) {
        [$httpCode, $response] = $this->httpPost($url, $body, $headers);
        
        if (in_array($httpCode, [200, 201])) {
            return json_decode($response, true);
        }
        
        throw new Exception("HTTP Error: $httpCode - $response");
    }
    
    /**
     * HTTP GET via cURL (preferred) or file_get_contents fallback.
     * Returns [$httpCode, $responseBody].
     */
    private function httpGet($url, array $headers = []) {
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return [$httpCode, $response];
        }
        $opts = [
            'http' => [
                'method'        => 'GET',
                'header'        => implode("\r\n", $headers),
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ];
        $response = @file_get_contents($url, false, stream_context_create($opts));
        return [$this->parseHttpCode($http_response_header ?? []), $response];
    }

    /**
     * HTTP POST/DELETE/PUT via cURL (preferred) or file_get_contents fallback.
     * Returns [$httpCode, $responseBody].
     */
    private function httpPost($url, $body, array $headers = [], $method = 'POST') {
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            if ($method === 'DELETE') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            } else {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return [$httpCode, $response];
        }

        $hasContentType = false;
        foreach ($headers as $headerLine) {
            if (stripos($headerLine, 'Content-Type:') === 0) {
                $hasContentType = true;
                break;
            }
        }
        if (!$hasContentType && $method !== 'DELETE') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        $opts = [
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", $headers),
                'content'       => $body,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ];
        $response = @file_get_contents($url, false, stream_context_create($opts));
        return [$this->parseHttpCode($http_response_header ?? []), $response];
    }

    /**
     * Extract HTTP status code from $http_response_header array.
     */
    private function parseHttpCode(array $responseHeaders) {
        foreach ($responseHeaders as $h) {
            if (preg_match('/HTTP\/\S+\s+(\d+)/', $h, $m)) {
                return (int)$m[1];
            }
        }
        return 0;
    }

    /**
     * Save tokens to file
     */
    private function saveTokens() {
        $tokenFile = __DIR__ . '/../config/.google-drive-tokens';
        $tokens = [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'timestamp' => time()
        ];
        if (!empty($this->authError)) {
            $tokens['auth_error'] = $this->authError; // shown on Profile > Google Drive
        }
        file_put_contents($tokenFile, json_encode($tokens));
        @chmod($tokenFile, 0600); // Secure permissions (no-op on some hosts)
    }
    
    /**
     * Load saved tokens
     */
    private function loadStoredTokens() {
        $tokenFile = __DIR__ . '/../config/.google-drive-tokens';
        
        if (file_exists($tokenFile)) {
            $tokens = json_decode(file_get_contents($tokenFile), true);
            $this->accessToken = $tokens['access_token'] ?? null;
            $this->refreshToken = $tokens['refresh_token'] ?? null;
            $this->authError = $tokens['auth_error'] ?? null;
        }
    }
    
    /**
     * Check if properly configured
     */
    public function isConfigured() {
        return !empty($this->accessToken) || (!empty($this->clientId) && !empty($this->clientSecret));
    }
}
