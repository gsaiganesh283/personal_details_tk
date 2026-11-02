<?php
/**
 * Global Search API
 * Returns JSON results from all modules matching a query string.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json');

$user = requireAuth();
$db = Database::getInstance();

$query = trim($_GET['q'] ?? '');

if (strlen($query) < 2) {
    echo json_encode(['results' => [], 'total' => 0]);
    exit;
}

$like = '%' . $query . '%';
$results = [];

// Personal Details
$rows = $db->fetchAll(
    "SELECT id, first_name, last_name, phone, email FROM personal_details 
     WHERE user_id = ? AND (first_name LIKE ? OR last_name LIKE ? OR phone LIKE ? OR email LIKE ?)
     ORDER BY updated_at DESC LIMIT 5",
    [$user['id'], $like, $like, $like, $like]
);
foreach ($rows as $r) {
    $results[] = [
        'module' => 'Personal',
        'icon' => 'fas fa-address-card',
        'color' => '#6C63FF',
        'title' => htmlspecialchars($r['first_name'] . ' ' . $r['last_name']),
        'subtitle' => htmlspecialchars($r['phone'] ?: $r['email'] ?: ''),
        'url' => url('personal-details.php?edit=' . $r['id']),
    ];
}

// Bank Details
$rows = $db->fetchAll(
    "SELECT id, bank_name, account_holder, account_number FROM bank_details 
     WHERE user_id = ? AND (bank_name LIKE ? OR account_holder LIKE ? OR account_number LIKE ? OR ifsc_code LIKE ?)
     ORDER BY updated_at DESC LIMIT 5",
    [$user['id'], $like, $like, $like, $like]
);
foreach ($rows as $r) {
    $results[] = [
        'module' => 'Bank',
        'icon' => 'fas fa-university',
        'color' => '#2ecc71',
        'title' => htmlspecialchars($r['bank_name']),
        'subtitle' => htmlspecialchars($r['account_holder']),
        'url' => url('bank-details.php?edit=' . $r['id']),
    ];
}

// Aadhaar Details
$rows = $db->fetchAll(
    "SELECT id, holder_name, aadhaar_number FROM aadhaar_details 
     WHERE user_id = ? AND (holder_name LIKE ? OR aadhaar_number LIKE ?)
     ORDER BY updated_at DESC LIMIT 5",
    [$user['id'], $like, $like]
);
foreach ($rows as $r) {
    $results[] = [
        'module' => 'Aadhaar',
        'icon' => 'fas fa-fingerprint',
        'color' => '#9b59b6',
        'title' => htmlspecialchars($r['holder_name']),
        'subtitle' => htmlspecialchars($r['aadhaar_number']),
        'url' => url('aadhaar-details.php?edit=' . $r['id']),
    ];
}

// PAN Details
$rows = $db->fetchAll(
    "SELECT id, holder_name, pan_number FROM pan_details 
     WHERE user_id = ? AND (holder_name LIKE ? OR pan_number LIKE ?)
     ORDER BY updated_at DESC LIMIT 5",
    [$user['id'], $like, $like]
);
foreach ($rows as $r) {
    $results[] = [
        'module' => 'PAN',
        'icon' => 'fas fa-credit-card',
        'color' => '#f5576c',
        'title' => htmlspecialchars($r['holder_name']),
        'subtitle' => htmlspecialchars($r['pan_number']),
        'url' => url('pan-details.php?edit=' . $r['id']),
    ];
}

// Documents
$rows = $db->fetchAll(
    "SELECT id, document_name, document_type, description FROM documents 
     WHERE user_id = ? AND (document_name LIKE ? OR description LIKE ? OR tags LIKE ?)
     ORDER BY updated_at DESC LIMIT 5",
    [$user['id'], $like, $like, $like]
);
foreach ($rows as $r) {
    $results[] = [
        'module' => 'Documents',
        'icon' => 'fas fa-file-pdf',
        'color' => '#e74c3c',
        'title' => htmlspecialchars($r['document_name']),
        'subtitle' => htmlspecialchars($r['document_type']),
        'url' => url('documents.php?edit=' . $r['id']),
    ];
}

// Other Details
$rows = $db->fetchAll(
    "SELECT id, title, category FROM other_details 
     WHERE user_id = ? AND (title LIKE ? OR category LIKE ? OR detail_value LIKE ? OR tags LIKE ?)
     ORDER BY updated_at DESC LIMIT 5",
    [$user['id'], $like, $like, $like, $like]
);
foreach ($rows as $r) {
    $results[] = [
        'module' => 'Other',
        'icon' => 'fas fa-box-open',
        'color' => '#4ECDC4',
        'title' => htmlspecialchars($r['title']),
        'subtitle' => htmlspecialchars($r['category']),
        'url' => url('other-details.php?edit=' . $r['id']),
    ];
}

echo json_encode([
    'results' => $results,
    'total' => count($results),
]);
