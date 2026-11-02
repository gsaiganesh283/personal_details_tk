<?php
/**
 * PWA manifest, served dynamically (not a static manifest.json) because
 * start_url/scope/icon paths must be BASE_PATH-aware -- BASE_PATH is only
 * known per-request (see includes/functions.php's url()), since this app
 * can be deployed at the domain root or in a subdirectory.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/manifest+json');

$manifest = [
    'name' => APP_NAME,
    'short_name' => 'PDS',
    'description' => 'Personal details, documents, and savings -- kept in sync with the desktop app.',
    'start_url' => url('index.php'),
    'scope' => url('/'),
    'display' => 'standalone',
    'background_color' => '#1b1c22',
    'theme_color' => '#8b83ff',
    'orientation' => 'portrait-primary',
    'icons' => [
        ['src' => url('assets/icons/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => url('assets/icons/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => url('assets/icons/icon-maskable-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
        ['src' => url('assets/icons/icon-maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
];

echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
