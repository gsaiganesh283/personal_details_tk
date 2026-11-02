<?php
/**
 * Public "what's the latest desktop app version" endpoint for the desktop
 * app's Settings > Updates section (app/services/update_checker.py). No
 * auth -- this is non-sensitive release metadata, not user data, and has to
 * be checkable without anyone necessarily being logged in anywhere.
 *
 * To ship a new desktop release: edit config/app-version.json with the new
 * version/download_url/notes. Nothing else reads this file.
 */
header('Content-Type: application/json');

$configPath = __DIR__ . '/config/app-version.json';
if (!file_exists($configPath)) {
    http_response_code(404);
    echo json_encode(['error' => 'No version info published yet.']);
    exit;
}

$data = json_decode((string)file_get_contents($configPath), true);
if (!is_array($data) || empty($data['version'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Version info is misconfigured.']);
    exit;
}

echo json_encode([
    'version' => (string)$data['version'],
    'download_url' => (string)($data['download_url'] ?? ''),
    'notes' => (string)($data['notes'] ?? ''),
    'published_at' => (string)($data['published_at'] ?? ''),
]);
