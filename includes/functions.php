<?php
/**
 * Common functions and utilities
 */

/**
 * Generate a URL with proper base path
 * Handles subdirectory installations correctly
 */
function url($path = '') {
    $path = ltrim($path, '/');
    $basePath = defined('BASE_PATH') ? BASE_PATH : '';
    return $basePath . '/' . $path;
}

/**
 * Output a hidden CSRF token field for a POST form.
 */
function csrf_field() {
    $token = Auth::generateCSRF();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Sanitize input
 */
function sanitize($input) {
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Redirect to a URL
 * Automatically prepends base path for relative URLs -- but only when the
 * given path doesn't already have it (e.g. the result of a helper like
 * savingsTrackerUrl()/ledgerUrl() that already calls url() itself). Without
 * this check, redirect(url('x')) or redirect(someUrlHelper()) double up the
 * base path into /pds/pds/x.php on any subdirectory deployment.
 */
function redirect($url) {
    // If URL starts with /, prepend base path (unless it's already there).
    if (!filter_var($url, FILTER_VALIDATE_URL) && strpos($url, '/') === 0) {
        $basePath = defined('BASE_PATH') ? BASE_PATH : '';
        $alreadyPrefixed = $basePath !== '' && strpos($url, $basePath . '/') === 0;
        if (!$alreadyPrefixed) {
            $url = url(ltrim($url, '/'));
        }
    }

    if (!headers_sent()) {
        header("Location: $url");
        exit();
    }

    // Fallback for pages that already started output.
    $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    echo '<script>window.location.href=' . json_encode($url) . ';</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . $safeUrl . '"></noscript>';
    exit();
}

/**
 * Set flash message
 */
function setFlash($type, $message) {
    $_SESSION['flash'][$type] = $message;
}

/**
 * Get and clear flash message
 */
function getFlash($type) {
    if (isset($_SESSION['flash'][$type])) {
        $message = $_SESSION['flash'][$type];
        unset($_SESSION['flash'][$type]);
        return $message;
    }
    return null;
}

/**
 * Display flash messages
 */
function displayFlash() {
    $types = ['success', 'error', 'warning', 'info'];
    $icons = [
        'success' => 'fas fa-check-circle',
        'error' => 'fas fa-exclamation-circle',
        'warning' => 'fas fa-exclamation-triangle',
        'info' => 'fas fa-info-circle'
    ];
    
    foreach ($types as $type) {
        $message = getFlash($type);
        if ($message) {
            $icon = $icons[$type];
            echo "<div class='alert alert-{$type} animate-slide-in'>
                    <i class='{$icon}'></i>
                    <span>{$message}</span>
                    <button class='alert-close' onclick='this.parentElement.remove()'>&times;</button>
                  </div>";
        }
    }
}

/**
 * Check whether a database table exists in the current schema.
 */
function dbTableExists(string $tableName): bool {
    if (!class_exists('Database')) return false;
    try {
        $db = Database::getInstance();
        $row = $db->fetch("SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = ? AND table_name = ?", [DB_NAME, $tableName]);
        return !empty($row) && (int)$row['cnt'] > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Check whether a table column exists in the current schema.
 */
function dbColumnExists(string $tableName, string $columnName): bool {
    if (!class_exists('Database')) return false;
    try {
        $db = Database::getInstance();
        $row = $db->fetch("SELECT COUNT(*) AS cnt FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?", [DB_NAME, $tableName, $columnName]);
        return !empty($row) && (int)$row['cnt'] > 0;
    } catch (Exception $e) {
        return false;
    }
}


/**
 * Format date
 */
function formatDate($date, $format = 'M d, Y') {
    return date($format, strtotime($date));
}

/**
 * Generate random color
 */
function randomColor() {
    $colors = ['#6C63FF', '#FF6B6B', '#4ECDC4', '#45B7D1', '#96CEB4', '#FFEAA7', '#DDA0DD', '#98D8C8'];
    return $colors[array_rand($colors)];
}

/**
 * Get user initials
 */
function getInitials($name) {
    $parts = explode(' ', $name);
    $initials = '';
    foreach ($parts as $part) {
        $initials .= strtoupper(substr($part, 0, 1));
    }
    return substr($initials, 0, 2);
}

/**
 * Check if request is AJAX
 */
function isAjax() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * Send JSON response
 */
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

/**
 * Require authentication
 */
function requireAuth() {
    $auth = new Auth();
    if (!$auth->isLoggedIn()) {
        if (isAjax()) {
            jsonResponse(['error' => 'Unauthorized'], 401);
        }
        setFlash('error', 'Please login to continue');
        redirect('/login.php');
    }
    return $auth->getCurrentUser();
}

/**
 * Time ago helper
 */
function timeAgo($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    if ($diff->y > 0) return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
    if ($diff->m > 0) return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
    if ($diff->d > 0) return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    if ($diff->h > 0) return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    if ($diff->i > 0) return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
    return 'Just now';
}
