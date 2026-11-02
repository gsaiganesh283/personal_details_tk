<?php
/**
 * OAuth Callback Handler
 * Each provider redirects back here after the user authorises.
 * Exchanges the auth code for a token, fetches user info, then logs in.
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$auth = new Auth();

// Already logged in?
if ($auth->isLoggedIn()) {
    redirect('/dashboard.php');
}

$provider = strtolower($_GET['provider'] ?? $_SESSION['oauth_provider'] ?? '');
$allowed  = ['google', 'github', 'apple'];

if (!in_array($provider, $allowed, true)) {
    setFlash('error', 'Unknown login provider.');
    redirect('/login.php');
}

// Validate state (CSRF protection) — Apple uses POST
$stateParam = $_REQUEST['state'] ?? '';
if (empty($stateParam) || !isset($_SESSION['oauth_state']) || !hash_equals($_SESSION['oauth_state'], $stateParam)) {
    setFlash('error', 'Invalid OAuth state. Please try again.');
    redirect('/login.php');
}
unset($_SESSION['oauth_state'], $_SESSION['oauth_provider']);

// Check for errors from provider
if (!empty($_GET['error']) || !empty($_POST['error'])) {
    $err = $_GET['error_description'] ?? $_POST['error_description'] ?? $_GET['error'] ?? $_POST['error'];
    setFlash('error', 'Login cancelled: ' . htmlspecialchars($err));
    redirect('/login.php');
}

$code = $_REQUEST['code'] ?? '';
if (empty($code)) {
    setFlash('error', 'No authorisation code received.');
    redirect('/login.php');
}

$oauth = require __DIR__ . '/config/oauth-config.php';
$cfg   = $oauth[$provider];

// ──────────────────────────────────────────────
//  Exchange code for access token
// ──────────────────────────────────────────────
$tokenPayload = [
    'client_id'     => $cfg['client_id'],
    'client_secret' => $cfg['client_secret'],
    'code'          => $code,
    'redirect_uri'  => $cfg['redirect_uri'],
    'grant_type'    => 'authorization_code',
];

$tokenHeaders = ['Content-Type: application/x-www-form-urlencoded'];
if ($provider === 'github') {
    $tokenHeaders[] = 'Accept: application/json';
}

$tokenResponse = httpPost($cfg['token_url'], http_build_query($tokenPayload), $tokenHeaders);
$tokenData     = json_decode($tokenResponse, true);

if (empty($tokenData['access_token'])) {
    $errMsg = $tokenData['error_description'] ?? $tokenData['error'] ?? 'Token exchange failed';
    setFlash('error', 'Authentication failed: ' . htmlspecialchars($errMsg));
    redirect('/login.php');
}

$accessToken = $tokenData['access_token'];

// ──────────────────────────────────────────────
//  Fetch user profile from provider
// ──────────────────────────────────────────────
$profile = [];

switch ($provider) {
    case 'google':
        $profile = fetchGoogleProfile($accessToken, $cfg);
        break;
    case 'github':
        $profile = fetchGitHubProfile($accessToken, $cfg);
        break;
    case 'apple':
        $profile = fetchAppleProfile($tokenData, $code);
        break;
}

if (empty($profile['id'])) {
    setFlash('error', 'Could not retrieve your profile from ' . ucfirst($provider) . '.');
    redirect('/login.php');
}

// ──────────────────────────────────────────────
//  Log in or register via Auth helper
// ──────────────────────────────────────────────
$result = $auth->handleOAuthLogin($provider, $profile);

if ($result['success']) {
    $msg = isset($result['new_account']) ? 'Account created via ' . ucfirst($provider) . '!' : 'Welcome back!';
    setFlash('success', $msg);
    redirect('/dashboard.php');
} else {
    setFlash('error', 'Social login failed. Please try again.');
    redirect('/login.php');
}

// ══════════════════════════════════════════════
//  Helper functions
// ══════════════════════════════════════════════

/**
 * POST request via cURL
 */
function httpPost($url, $body, $headers = []) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log('OAuth cURL error: ' . curl_error($ch));
    }
    curl_close($ch);
    return $resp;
}

/**
 * GET request via cURL
 */
function httpGet($url, $headers = []) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log('OAuth cURL error: ' . curl_error($ch));
    }
    curl_close($ch);
    return $resp;
}

// ─── Google ──────────────────────────────────

function fetchGoogleProfile($accessToken, $cfg) {
    $raw = httpGet($cfg['userinfo_url'], [
        'Authorization: Bearer ' . $accessToken,
    ]);
    $data = json_decode($raw, true);

    return [
        'id'     => $data['id'] ?? '',
        'email'  => $data['email'] ?? '',
        'name'   => $data['name'] ?? '',
        'avatar' => $data['picture'] ?? '',
    ];
}

// ─── GitHub ──────────────────────────────────

function fetchGitHubProfile($accessToken, $cfg) {
    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'User-Agent: PersonalDetailsSaver',
        'Accept: application/json',
    ];

    $raw  = httpGet($cfg['userinfo_url'], $headers);
    $data = json_decode($raw, true);

    $email = $data['email'] ?? '';

    // GitHub may not return email in the profile — fetch from /user/emails
    if (empty($email)) {
        $rawEmails = httpGet('https://api.github.com/user/emails', $headers);
        $emails    = json_decode($rawEmails, true);
        if (is_array($emails)) {
            foreach ($emails as $e) {
                if (!empty($e['primary']) && !empty($e['verified'])) {
                    $email = $e['email'];
                    break;
                }
            }
            if (empty($email) && !empty($emails[0]['email'])) {
                $email = $emails[0]['email'];
            }
        }
    }

    return [
        'id'     => (string) ($data['id'] ?? ''),
        'email'  => $email,
        'name'   => $data['name'] ?? $data['login'] ?? '',
        'avatar' => $data['avatar_url'] ?? '',
    ];
}

// ─── Apple ───────────────────────────────────

function fetchAppleProfile($tokenData, $code) {
    // Apple sends user info only on the FIRST authorisation, via POST `user` field
    $userJson = $_POST['user'] ?? '';
    $userData = $userJson ? json_decode($userJson, true) : [];

    // Decode the id_token JWT to get the subject (user id) and email
    $idToken = $tokenData['id_token'] ?? '';
    $claims  = decodeJwtPayload($idToken);

    $name = '';
    if (!empty($userData['name'])) {
        $name = trim(($userData['name']['firstName'] ?? '') . ' ' . ($userData['name']['lastName'] ?? ''));
    }

    return [
        'id'     => $claims['sub'] ?? '',
        'email'  => $claims['email'] ?? '',
        'name'   => $name,
        'avatar' => '',
    ];
}

/**
 * Decode a JWT payload without verification (Apple id_token).
 * We trust it because we just received it from Apple's token endpoint over TLS.
 */
function decodeJwtPayload($jwt) {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return [];
    $payload = base64_decode(strtr($parts[1], '-_', '+/'));
    return json_decode($payload, true) ?: [];
}
