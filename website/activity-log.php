<?php
$pageTitle = 'Activity Log';
require_once __DIR__ . '/includes/header.php';
$user = requireAuth();
$db = Database::getInstance();

// Handle "Clear Log"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_log'])) {
    $db->delete("DELETE FROM activity_log WHERE user_id = ?", [$user['id']]);
    setFlash('success', 'Activity log cleared.');
    redirect('/activity-log.php');
}

function activityMeta($action) {
    switch ($action) {
        case 'create':
            return ['badge' => 'badge-success', 'icon' => 'fa-plus-circle', 'dot' => 'var(--success)'];
        case 'update':
            return ['badge' => 'badge-info', 'icon' => 'fa-pen', 'dot' => 'var(--info)'];
        case 'delete':
            return ['badge' => 'badge-danger', 'icon' => 'fa-trash', 'dot' => 'var(--danger)'];
        case 'login':
            return ['badge' => 'badge-primary', 'icon' => 'fa-right-to-bracket', 'dot' => 'var(--primary)'];
        default:
            return ['badge' => 'badge-warning', 'icon' => 'fa-circle-info', 'dot' => 'var(--warning)'];
    }
}

// Filters
$search = sanitize($_GET['search'] ?? '');
$filterAction = sanitize($_GET['action_type'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = "WHERE user_id = ?";
$params = [$user['id']];

if ($search) {
    $where .= " AND description LIKE ?";
    $params[] = "%$search%";
}
if ($filterAction && in_array($filterAction, ['create', 'update', 'delete', 'login'])) {
    $where .= " AND action = ?";
    $params[] = $filterAction;
}

$totalCount = $db->fetch("SELECT COUNT(*) as count FROM activity_log $where", $params);
$totalPages = max(1, ceil($totalCount['count'] / $perPage));
$entries = $db->fetchAll("SELECT * FROM activity_log $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset", $params);
?>

<!-- Toolbar -->
<div class="toolbar">
    <div class="toolbar-left">
        <div class="search-box" style="width: auto;">
            <i class="fas fa-search"></i>
            <form method="GET" style="display:flex; gap:8px;">
                <input type="text" name="search" placeholder="Search activity..."
                       value="<?php echo htmlspecialchars($search); ?>" style="width: 220px;">
                <select name="action_type" onchange="this.form.submit()" style="width:auto;">
                    <option value="">All actions</option>
                    <option value="create" <?php echo $filterAction === 'create' ? 'selected' : ''; ?>>Created</option>
                    <option value="update" <?php echo $filterAction === 'update' ? 'selected' : ''; ?>>Updated</option>
                    <option value="delete" <?php echo $filterAction === 'delete' ? 'selected' : ''; ?>>Deleted</option>
                    <option value="login" <?php echo $filterAction === 'login' ? 'selected' : ''; ?>>Login</option>
                </select>
            </form>
        </div>
    </div>
    <div class="toolbar-right">
        <?php if (!empty($entries) || $totalCount['count'] > 0): ?>
        <form method="POST" onsubmit="return confirm('Clear your entire activity log? This cannot be undone.');" style="display:inline;">
<?php echo csrf_field(); ?>
            <input type="hidden" name="clear_log" value="1">
            <button type="submit" class="btn btn-ghost text-danger">
                <i class="fas fa-trash"></i> Clear Log
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php if (empty($entries)): ?>
    <div class="empty-state card">
        <i class="fas fa-history"></i>
        <h3>No activity yet</h3>
        <p>Actions you take across the app -- adding, editing, and deleting records -- will show up here.</p>
    </div>
<?php else: ?>
    <div class="card">
        <div class="activity-list">
            <?php foreach ($entries as $entry): $meta = activityMeta($entry['action']); ?>
            <div class="activity-item">
                <div class="activity-dot" style="background: <?php echo $meta['dot']; ?>;"></div>
                <div class="activity-content">
                    <h4>
                        <span class="badge <?php echo $meta['badge']; ?>" style="margin-right: 8px;">
                            <i class="fas <?php echo $meta['icon']; ?>"></i> <?php echo ucfirst($entry['action']); ?>
                        </span>
                        <?php echo htmlspecialchars($entry['description'] ?? ''); ?>
                    </h4>
                    <p>
                        <?php echo timeAgo($entry['created_at']); ?>
                        <?php if (!empty($entry['ip_address'])): ?>
                            &middot; <?php echo htmlspecialchars($entry['ip_address']); ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
        <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&action_type=<?php echo urlencode($filterAction); ?>" class="pagination-btn"><i class="fas fa-chevron-left"></i></a>
        <?php endif; ?>
        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
        <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&action_type=<?php echo urlencode($filterAction); ?>" class="pagination-btn <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
        <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&action_type=<?php echo urlencode($filterAction); ?>" class="pagination-btn"><i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
