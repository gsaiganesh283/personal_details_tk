<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';
$user = requireAuth();

$db = Database::getInstance();

// Get stats
$totalDetails = $db->fetch("SELECT COUNT(*) as count FROM personal_details WHERE user_id = ?", [$user['id']]);
$totalCustomFields = $db->fetch("SELECT COUNT(*) as count FROM custom_fields WHERE user_id = ?", [$user['id']]);
$totalBanks = $db->fetch("SELECT COUNT(*) as count FROM bank_details WHERE user_id = ?", [$user['id']]);
$totalDocuments = $db->fetch("SELECT COUNT(*) as count FROM documents WHERE user_id = ?", [$user['id']]);
$totalAadhaar = $db->fetch("SELECT COUNT(*) as count FROM aadhaar_details WHERE user_id = ?", [$user['id']]);
$totalPAN = $db->fetch("SELECT COUNT(*) as count FROM pan_details WHERE user_id = ?", [$user['id']]);
$totalOther = $db->fetch("SELECT COUNT(*) as count FROM other_details WHERE user_id = ?", [$user['id']]);
$recentDetails = $db->fetchAll(
    "SELECT * FROM personal_details WHERE user_id = ? ORDER BY updated_at DESC LIMIT 5",
    [$user['id']]
);
$recentActivity = $db->fetchAll(
    "SELECT * FROM activity_log WHERE user_id = ? ORDER BY created_at DESC LIMIT 8",
    [$user['id']]
);
?>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon purple">
            <i class="fas fa-address-card"></i>
        </div>
        <div class="stat-info">
            <h3><?php echo $totalDetails['count']; ?></h3>
            <p>Personal Records</p>
            <div class="stat-trend up"><i class="fas fa-arrow-up"></i> Active records</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="fas fa-university"></i>
        </div>
        <div class="stat-info">
            <h3><?php echo $totalBanks['count']; ?></h3>
            <p>Bank Accounts</p>
            <div class="stat-trend up"><i class="fas fa-arrow-up"></i> Linked</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="fas fa-file-pdf"></i>
        </div>
        <div class="stat-info">
            <h3><?php echo $totalDocuments['count']; ?></h3>
            <p>Documents</p>
            <div class="stat-trend up"><i class="fas fa-arrow-up"></i> Uploaded</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon red">
            <i class="fas fa-fingerprint"></i>
        </div>
        <div class="stat-info">
            <h3><?php echo $totalAadhaar['count']; ?></h3>
            <p>Aadhaar Records</p>
            <div class="stat-trend up"><i class="fas fa-check"></i> Stored</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(240, 147, 251, 0.15); color: #f093fb;">
            <i class="fas fa-credit-card"></i>
        </div>
        <div class="stat-info">
            <h3><?php echo $totalPAN['count']; ?></h3>
            <p>PAN Records</p>
            <div class="stat-trend up"><i class="fas fa-check"></i> Stored</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(78, 205, 196, 0.15); color: #4ECDC4;">
            <i class="fas fa-box-open"></i>
        </div>
        <div class="stat-info">
            <h3><?php echo $totalOther['count']; ?></h3>
            <p>Other Details</p>
            <div class="stat-trend up"><i class="fas fa-arrow-up"></i> Entries</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(255, 234, 167, 0.15); color: #FFEAA7;">
            <i class="fas fa-puzzle-piece"></i>
        </div>
        <div class="stat-info">
            <h3><?php echo $totalCustomFields['count']; ?></h3>
            <p>Custom Fields</p>
            <div class="stat-trend up"><i class="fas fa-arrow-up"></i> Active</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(133, 193, 233, 0.15); color: #85C1E9;">
            <i class="fas fa-shield-halved"></i>
        </div>
        <div class="stat-info">
            <h3>Active</h3>
            <p>Account Status</p>
            <div class="stat-trend up"><i class="fas fa-check"></i> Verified</div>
        </div>
    </div>
</div>

<div class="dashboard-grid">
    <!-- Recent Records -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-history" style="margin-right: 8px; color: var(--primary);"></i> Recent Records</h2>
            <a href="<?php echo url('personal-details.php'); ?>" class="btn btn-ghost btn-sm">View All <i class="fas fa-arrow-right"></i></a>
        </div>

        <?php if (empty($recentDetails)): ?>
            <div class="empty-state">
                <i class="fas fa-folder-open"></i>
                <h3>No records yet</h3>
                <p>Start by adding your first personal detail</p>
                <a href="<?php echo url('personal-details.php'); ?>" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus"></i> Add Record
                </a>
            </div>
        <?php else: ?>
            <div class="table-container" style="border: none;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Phone</th>
                            <th>Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentDetails as $detail): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($detail['first_name'] . ' ' . $detail['last_name']); ?></strong>
                            </td>
                            <td><?php echo htmlspecialchars($detail['phone'] ?? '-'); ?></td>
                            <td><span class="badge badge-info"><?php echo timeAgo($detail['updated_at']); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Activity Log -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-stream" style="margin-right: 8px; color: var(--success);"></i> Activity Log</h2>
            <a href="<?php echo url('activity-log.php'); ?>" class="btn btn-ghost btn-sm">View all</a>
        </div>

        <?php if (empty($recentActivity)): ?>
            <div class="empty-state">
                <i class="fas fa-list"></i>
                <h3>No activity yet</h3>
                <p>Your recent actions will appear here</p>
            </div>
        <?php else: ?>
            <div class="activity-list">
                <?php foreach ($recentActivity as $activity): ?>
                <div class="activity-item">
                    <div class="activity-dot"></div>
                    <div class="activity-content">
                        <h4><?php echo htmlspecialchars($activity['description']); ?></h4>
                        <p><?php echo timeAgo($activity['created_at']); ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Quick Actions -->
<div class="card" style="margin-top: 24px;">
    <div class="card-header">
        <h2><i class="fas fa-bolt" style="margin-right: 8px; color: var(--warning);"></i> Quick Actions</h2>
    </div>
    <div style="display: flex; gap: 12px; flex-wrap: wrap;">
        <a href="<?php echo url('personal-details.php?action=add'); ?>" class="btn btn-primary">
            <i class="fas fa-plus"></i> Add Personal Record
        </a>
        <a href="<?php echo url('bank-details.php?action=add'); ?>" class="btn btn-success">
            <i class="fas fa-university"></i> Add Bank Account
        </a>
        <a href="<?php echo url('documents.php?action=add'); ?>" class="btn btn-secondary">
            <i class="fas fa-upload"></i> Upload Document
        </a>
        <a href="<?php echo url('aadhaar-details.php?action=add'); ?>" class="btn btn-primary" style="background: #667eea;">
            <i class="fas fa-fingerprint"></i> Add Aadhaar
        </a>
        <a href="<?php echo url('pan-details.php?action=add'); ?>" class="btn btn-primary" style="background: #f093fb;">
            <i class="fas fa-credit-card"></i> Add PAN
        </a>
        <a href="<?php echo url('other-details.php?action=add'); ?>" class="btn btn-primary" style="background: #4ECDC4;">
            <i class="fas fa-box-open"></i> Add Other
        </a>
        <a href="<?php echo url('groww-dashboard.php'); ?>" class="btn btn-primary" style="background: #6C63FF;">
            <i class="fas fa-chart-line"></i> Groww Dashboard
        </a>
        <a href="<?php echo url('savingsmanage.php'); ?>" class="btn btn-primary" style="background: #2F9E44;">
            <i class="fas fa-piggy-bank"></i> Savings Dashboard
        </a>
        <a href="<?php echo url('savingsmanage.php'); ?>" class="btn btn-ghost">
            <i class="fas fa-list"></i> Savings Tracker
        </a>
        <a href="<?php echo url('custom-fields.php'); ?>" class="btn btn-ghost">
            <i class="fas fa-puzzle-piece"></i> Manage Custom Fields
        </a>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
