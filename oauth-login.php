<?php
/**
 * OAuth Login Initiator
 * Redirects the user to the chosen OAuth provider for authentication.
 * Usage: oauth-login.php?provider=google|github|apple
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

$provider = strtolower($_GET['provider'] ?? '');
$allowed  = ['google', 'github', 'apple'];

if (!in_array($provider, $allowed, true)) {
    setFlash('error', 'Unknown login provider.');
    redirect('/login.php');
}

$oauth = require __DIR__ . '/config/oauth-config.php';
$cfg   = $oauth[$provider] ?? [];

if (empty($cfg['client_id'])) {
    setFlash('error', ucfirst($provider) . ' login is not configured yet.');
    redirect('/login.php');
}

// CSRF / state token
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state']    = $state;
$_SESSION['oauth_provider'] = $provider;

// Build authorisation URL
$params = [
    'client_id'     => $cfg['client_id'],
    'redirect_uri'  => $cfg['redirect_uri'],
    'response_type' => 'code',
    'scope'         => $cfg['scope'],
    'state'         => $state,
];

// Provider-specific tweaks
if ($provider === 'google') {
    $params['access_type'] = 'offline';
    $params['prompt']      = 'select_account';
}

if ($provider === 'apple') {
    $params['response_mode'] = 'form_post';
}

$authUrl = $cfg['auth_url'] . '?' . http_build_query($params);

header('Location: ' . $authUrl);
exit();
