<?php
/**
 * Notifications API
 * Returns recent activity log entries as notifications.
 * Also supports marking notifications as read.
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

$action = $_GET['action'] ?? 'list';

// Helper: action icons
function getActionIcon($action) {
    $map = [
        'create'   => 'fas fa-plus-circle',
        'update'   => 'fas fa-edit',
        'delete'   => 'fas fa-trash-alt',
        'upload'   => 'fas fa-upload',
        'download' => 'fas fa-download',
        'login'    => 'fas fa-sign-in-alt',
        'settings' => 'fas fa-cog',
        'reminder' => 'fas fa-bell',
    ];
    return $map[$action] ?? 'fas fa-bell';
}

function getActionColor($action) {
    $map = [
        'create'   => '#2ecc71',
        'update'   => '#3498db',
        'delete'   => '#e74c3c',
        'upload'   => '#9b59b6',
        'download' => '#f39c12',
        'login'    => '#6C63FF',
        'settings' => '#95a5a6',
        'reminder' => '#f5a623',
    ];
    return $map[$action] ?? '#6C63FF';
}

switch ($action) {
    case 'list':
        // Get recent activity (last 20 entries)
        $notifications = $db->fetchAll(
            "SELECT id, action, description, ip_address, created_at FROM activity_log 
             WHERE user_id = ? ORDER BY created_at DESC LIMIT 20",
            [$user['id']]
        );

        // Count unread (entries newer than last_read timestamp stored in session)
        $lastRead = $_SESSION['notifications_last_read'] ?? '2000-01-01 00:00:00';
        $unreadCount = 0;
        
        $items = [];
        foreach ($notifications as $n) {
            $isUnread = $n['created_at'] > $lastRead;
            if ($isUnread) $unreadCount++;
            
            $items[] = [
                'id' => (int)$n['id'],
                'action' => $n['action'],
                'icon' => getActionIcon($n['action']),
                'color' => getActionColor($n['action']),
                'description' => htmlspecialchars($n['description']),
                'time' => timeAgo($n['created_at']),
                'timestamp' => $n['created_at'],
                'unread' => $isUnread,
            ];
        }

        echo json_encode([
            'notifications' => $items,
            'unreadCount' => $unreadCount,
        ]);
        break;

    case 'mark_read':
        // Mark all as read by storing current timestamp in session
        $_SESSION['notifications_last_read'] = date('Y-m-d H:i:s');
        echo json_encode(['success' => true]);
        break;

    case 'count':
        $lastRead = $_SESSION['notifications_last_read'] ?? '2000-01-01 00:00:00';
        $count = $db->fetch(
            "SELECT COUNT(*) as cnt FROM activity_log WHERE user_id = ? AND created_at > ?",
            [$user['id'], $lastRead]
        );
        echo json_encode(['unreadCount' => (int)$count['cnt']]);
        break;

    default:
        echo json_encode(['error' => 'Unknown action']);
}
