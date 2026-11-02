<?php
$pageTitle = 'Notifications';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/custom_fields_helper.php'; // pulls in notifications_helper.php too
require_once __DIR__ . '/includes/notifbridge_client.php';

// ---------------------------------------------------------------- AJAX reads
if (isAjax()) {
    $user = requireAuth();
    ensureNotificationTemplatesSchema();
    $db = Database::getInstance();
    $action = $_GET['action'] ?? '';

    switch ($action) {
        case 'get_fields':
            $module = sanitize($_GET['module'] ?? '');
            $out = [];
            foreach (getNativeFieldsForModule($module) as $col => $label) {
                $out[] = ['name' => $col, 'label' => $label, 'source' => 'native'];
            }
            foreach (getCustomFieldsFor($module, $user['id']) as $f) {
                $out[] = ['name' => $f['field_name'], 'label' => $f['field_label'], 'source' => 'custom', 'type' => $f['field_type']];
            }
            jsonResponse(['success' => true, 'fields' => $out]);
            break;

        case 'for_module':
            // Used by custom-fields.php's "reminder" field type Template dropdown.
            $module = sanitize($_GET['module'] ?? '');
            $templates = $db->fetchAll(
                "SELECT id, name, title FROM notification_templates WHERE user_id = ? AND linked_to = ? AND is_active = 1 ORDER BY name ASC",
                [$user['id'], $module]
            );
            jsonResponse(['success' => true, 'templates' => $templates]);
            break;

        case 'list_devices':
            $connection = getNotifBridgeConnection($user['id']);
            if (!$connection) {
                jsonResponse(['success' => false, 'error' => 'NotifBridge is not configured'], 400);
            }
            try {
                $devices = notifbridgeListDevices($connection);
                jsonResponse(['success' => true, 'devices' => $devices]);
            } catch (NotifBridgeError $e) {
                jsonResponse(['success' => false, 'error' => $e->getMessage()], 502);
            }
            break;

        case 'history':
            $connection = getNotifBridgeConnection($user['id']);
            if (!$connection) {
                jsonResponse(['success' => false, 'error' => 'NotifBridge is not configured'], 400);
            }
            try {
                $rows = notifbridgeOutgoing($connection, 100);
                jsonResponse(['success' => true, 'rows' => $rows]);
            } catch (NotifBridgeError $e) {
                jsonResponse(['success' => false, 'error' => $e->getMessage()], 502);
            }
            break;

        default:
            jsonResponse(['success' => false, 'error' => 'Invalid action'], 400);
    }
    exit;
}

// -------------------------------------------------------------- POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    $user = requireAuth();
    if (!Auth::validateCSRF($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Your session expired or the request could not be verified. Please try again.');
        redirect('/notifications.php');
        exit;
    }
    ensureNotifBridgeSchema();
    ensureNotificationTemplatesSchema();
    $db = Database::getInstance();
    $action = $_POST['action'];

    switch ($action) {
        case 'save_config':
        case 'test_connection':
            $serverUrl = trim($_POST['server_url'] ?? '');
            $apiKey = trim($_POST['api_key'] ?? '');
            $lowBalance = (float)($_POST['low_balance_threshold'] ?? 1000);
            $largeEntry = (float)($_POST['large_entry_threshold'] ?? 10000);
            $maturityDays = (int)($_POST['maturity_days_ahead'] ?? 7);

            if ($serverUrl === '') {
                setFlash('error', 'Server URL is required.');
                redirect('/notifications.php?tab=config');
            }
            $existing = getNotifBridgeConnection($user['id']);
            $keyToSave = $apiKey !== '' ? $apiKey : ($existing['api_key'] ?? '');
            if ($keyToSave === '') {
                setFlash('error', 'API Key is required.');
                redirect('/notifications.php?tab=config');
            }
            saveNotifBridgeCredentials($user['id'], $serverUrl, $keyToSave);
            saveNotifBridgeThresholds($user['id'], $lowBalance, $largeEntry, $maturityDays);

            if ($action === 'test_connection') {
                $ok = notifbridgeTestConnection(getNotifBridgeConnection($user['id']));
                setFlash($ok ? 'success' : 'error', $ok ? 'Saved. Server reachable.' : 'Saved, but could not reach the server. Check URL/network.');
            } else {
                setFlash('success', 'Saved.');
            }
            redirect('/notifications.php?tab=config');
            break;

        case 'disconnect':
            deleteNotifBridgeCredentials($user['id']);
            setFlash('success', 'NotifBridge disconnected.');
            redirect('/notifications.php?tab=config');
            break;

        case 'reminder_save':
            $connection = getNotifBridgeConnection($user['id']);
            if (!$connection) {
                setFlash('error', 'Configure NotifBridge in the Configuration tab first.');
                redirect('/notifications.php?tab=config');
            }
            $editId = isset($_POST['edit_id']) && $_POST['edit_id'] !== '' ? (int)$_POST['edit_id'] : null;
            $title = sanitize($_POST['title'] ?? '');
            $body = sanitize($_POST['body'] ?? '');
            $sendAt = notifbridgeLocalToUtcIso($_POST['send_date'] ?? '', $_POST['send_time'] ?? '');
            $repeatType = in_array($_POST['repeat_type'] ?? 'none', array_keys(NOTIFBRIDGE_REPEAT_LABELS)) ? $_POST['repeat_type'] : 'none';
            $repeatUntil = !empty($_POST['repeat_until']) ? notifbridgeLocalToUtcIso($_POST['repeat_until'], '23:59') : null;
            $priority = ($_POST['priority'] ?? 'default') === 'high' ? 'high' : 'default';

            if (empty($title) || empty($body)) {
                setFlash('error', 'Title and Body are required.');
            } elseif (!$sendAt) {
                setFlash('error', 'Send Date is required.');
            } else {
                $payload = [
                    'title' => $title,
                    'body' => $body,
                    'device_ids' => 'all',
                    'priority' => $priority,
                    'channel_push' => true,
                    'send_at' => $sendAt,
                    'repeat_type' => $repeatType,
                    'repeat_until' => $repeatUntil,
                    'is_active' => true,
                ];
                try {
                    if ($editId) {
                        notifbridgeUpdateReminder($connection, $editId, $payload);
                        setFlash('success', 'Reminder updated.');
                    } else {
                        notifbridgeCreateReminder($connection, $payload);
                        setFlash('success', 'Reminder created.');
                    }
                } catch (NotifBridgeError $e) {
                    setFlash('error', 'NotifBridge: ' . $e->getMessage());
                }
            }
            redirect('/notifications.php?tab=reminders');
            break;

        case 'template_create':
        case 'template_update':
            $name = sanitize($_POST['name'] ?? '');
            $linkedTo = sanitize($_POST['linked_to'] ?? '');
            $title = sanitize($_POST['title'] ?? '');
            $body = trim($_POST['body'] ?? '');
            $priority = ($_POST['priority'] ?? 'default') === 'high' ? 'high' : 'default';
            $reminderDays = sanitize($_POST['reminder_days'] ?? '');

            $channelInapp = isset($_POST['channel_inapp']) ? 1 : 0;
            $channelEmail = isset($_POST['channel_email']) ? 1 : 0;

            if (empty($name) || empty($linkedTo) || empty($title) || empty($body)) {
                setFlash('error', 'Name, Module, Title and Body are all required');
                redirect('/notifications.php?tab=templates');
            }
            if ($reminderDays !== '' && preg_match('/^\s*\d+(\s*[,;]\s*\d+)*\s*$/', $reminderDays) !== 1) {
                setFlash('error', 'Reminder days must be comma-separated whole numbers, for example: 30, 7, 1');
                redirect('/notifications.php?tab=templates');
            }

            if ($action === 'template_create') {
                $key = generateTemplateKey($user['id'], $name);
                $db->insert(
                    "INSERT INTO notification_templates (user_id, `key`, name, linked_to, title, body, priority, reminder_days, channel_inapp, channel_email) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$user['id'], $key, $name, $linkedTo, $title, $body, $priority, $reminderDays ?: null, $channelInapp, $channelEmail]
                );
                setFlash('success', 'Template created successfully');
            } else {
                $templateId = (int)($_POST['template_id'] ?? 0);
                $db->update(
                    "UPDATE notification_templates SET name = ?, linked_to = ?, title = ?, body = ?, priority = ?, reminder_days = ?, channel_inapp = ?, channel_email = ? WHERE id = ? AND user_id = ?",
                    [$name, $linkedTo, $title, $body, $priority, $reminderDays ?: null, $channelInapp, $channelEmail, $templateId, $user['id']]
                );
                setFlash('success', 'Template updated successfully');
            }
            redirect('/notifications.php?tab=templates');
            break;

        case 'template_delete':
            $templateId = (int)($_POST['template_id'] ?? 0);
            $db->delete("DELETE FROM notification_templates WHERE id = ? AND user_id = ?", [$templateId, $user['id']]);
            setFlash('success', 'Template deleted successfully');
            redirect('/notifications.php?tab=templates');
            break;

        case 'template_toggle':
            $templateId = (int)($_POST['template_id'] ?? 0);
            $db->update(
                "UPDATE notification_templates SET is_active = NOT is_active WHERE id = ? AND user_id = ?",
                [$templateId, $user['id']]
            );
            setFlash('success', 'Template status toggled');
            redirect('/notifications.php?tab=templates');
            break;

        case 'send_test':
            $connection = getNotifBridgeConnection($user['id']);
            if (!$connection) {
                setFlash('error', 'Configure NotifBridge in the Configuration tab first.');
                redirect('/notifications.php?tab=config');
            }
            $templateId = (int)($_POST['template_id'] ?? 0);
            $deviceParam = trim($_POST['device_id'] ?? 'all');
            $deviceIds = ($deviceParam === '' || $deviceParam === 'all') ? 'all' : [(int)$deviceParam];

            if ($templateId) {
                $template = $db->fetch("SELECT * FROM notification_templates WHERE id = ? AND user_id = ?", [$templateId, $user['id']]);
                if (!$template) {
                    setFlash('error', 'Template not found.');
                    redirect('/notifications.php?tab=send');
                }
                $title = $template['title'];
                $body = $template['body'];
                foreach (($_POST['placeholder'] ?? []) as $name => $value) {
                    $title = str_replace('{' . $name . '}', $value, $title);
                    $body = str_replace('{' . $name . '}', $value, $body);
                }
                $title = str_replace(['{date}', '{time}'], [date('d M Y'), date('h:i A')], $title);
                $body = str_replace(['{date}', '{time}'], [date('d M Y'), date('h:i A')], $body);
                $priority = $template['priority'] ?: 'default';
            } else {
                $title = trim($_POST['custom_title'] ?? '');
                $body = trim($_POST['custom_body'] ?? '');
                $priority = ($_POST['custom_priority'] ?? 'default') === 'high' ? 'high' : 'default';
                $hasFile = isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE;
                if ($title === '' || ($body === '' && !$hasFile)) {
                    setFlash('error', 'Title and Body (or an attachment) are required.');
                    redirect('/notifications.php?tab=send');
                }
                if ($body === '' && $hasFile) {
                    $body = 'File: ' . basename($_FILES['attachment']['name']);
                }
            }
            $attachment = (!$templateId && isset($_FILES['attachment'])) ? $_FILES['attachment'] : null;

            try {
                notifbridgeSendMessage($connection, $title, $body, $deviceIds, $priority, $attachment, ['source' => 'website']);
                setFlash('success', "Sent: $title — $body" . ($attachment && $attachment['error'] === UPLOAD_ERR_OK ? ' (with ' . basename($attachment['name']) . ')' : ''));
            } catch (NotifBridgeError $e) {
                setFlash('error', 'Failed to send: ' . $e->getMessage());
            }
            redirect('/notifications.php?tab=send');
            break;

        default:
            setFlash('error', 'Invalid action');
            redirect('/notifications.php');
    }
    exit;
}

// ------------------------------------------------------------ Normal page load
require_once __DIR__ . '/includes/header.php';
$user = requireAuth();
$db = Database::getInstance();
ensureNotifBridgeSchema();
ensureNotificationTemplatesSchema();

$connection = getNotifBridgeConnection($user['id']);

// GET-triggered reminder actions (simple confirm()-guarded links, same
// convention as every other module's ?delete=/?send_now= links)
if (isset($_GET['delete']) && is_numeric($_GET['delete']) && Auth::validateCSRF($_GET['csrf'] ?? '') && $connection) {
    try {
        notifbridgeDeleteReminder($connection, (int)$_GET['delete']);
        setFlash('success', 'Reminder deleted.');
    } catch (NotifBridgeError $e) {
        setFlash('error', 'NotifBridge: ' . $e->getMessage());
    }
    redirect('/notifications.php?tab=reminders');
}
if (isset($_GET['send_now']) && is_numeric($_GET['send_now']) && $connection) {
    try {
        notifbridgeSendReminderNow($connection, (int)$_GET['send_now']);
        setFlash('success', 'Reminder sent.');
    } catch (NotifBridgeError $e) {
        setFlash('error', 'NotifBridge: ' . $e->getMessage());
    }
    redirect('/notifications.php?tab=reminders');
}

$activeTab = in_array($_GET['tab'] ?? '', ['config', 'templates', 'reminders', 'send', 'history'], true) ? $_GET['tab'] : 'config';
$moduleLabels = getModuleLabels();
$thresholds = getNotifBridgeThresholds($user['id']);

// ---- Reminders tab data ----
$reminders = [];
$devices = [];
$loadError = null;
if ($connection) {
    try {
        $reminders = notifbridgeListReminders($connection);
    } catch (NotifBridgeError $e) {
        $loadError = $e->getMessage();
    }
    try {
        $devices = notifbridgeListDevices($connection);
    } catch (NotifBridgeError $e) {
        // Non-fatal -- device count is just a nice-to-have in the header.
    }
}
$editReminderData = null;
if ($connection && isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    foreach ($reminders as $r) {
        if ((int)$r['id'] === (int)$_GET['edit']) { $editReminderData = $r; break; }
    }
}
$showReminderForm = $connection && ((isset($_GET['action']) && $_GET['action'] === 'add') || $editReminderData);

[$editDate, $editTime] = $editReminderData ? notifbridgeUtcIsoToLocalDateTime($editReminderData['send_at'] ?? null) : ['', ''];
[$editUntilDate, ] = ($editReminderData && !empty($editReminderData['repeat_until'])) ? notifbridgeUtcIsoToLocalDateTime($editReminderData['repeat_until']) : ['', ''];

// ---- Templates tab data (also feeds the Reminders "start from template"
// picker and the Send Test Message template picker) ----
$templates = $db->fetchAll("SELECT * FROM notification_templates WHERE user_id = ? ORDER BY name ASC", [$user['id']]);
$activeTemplates = array_values(array_filter($templates, function ($t) { return (bool)$t['is_active']; }));
?>

<!-- Tabs -->
<div class="tabs">
    <button class="tab-btn <?php echo $activeTab === 'config' ? 'active' : ''; ?>" data-tab="configTab">Configuration</button>
    <button class="tab-btn <?php echo $activeTab === 'templates' ? 'active' : ''; ?>" data-tab="templatesTab">Templates</button>
    <button class="tab-btn <?php echo $activeTab === 'reminders' ? 'active' : ''; ?>" data-tab="remindersTab">Scheduled Reminders</button>
    <button class="tab-btn <?php echo $activeTab === 'send' ? 'active' : ''; ?>" data-tab="sendTab">Send Test Message</button>
    <button class="tab-btn <?php echo $activeTab === 'history' ? 'active' : ''; ?>" data-tab="historyTab">History</button>
</div>

<!-- ============================================================ Configuration -->
<div class="tab-content <?php echo $activeTab === 'config' ? 'active' : ''; ?>" id="configTab">
    <div class="card animate-fade-in" style="max-width: 640px;">
        <div class="card-header">
            <h2><i class="fas fa-bell" style="margin-right: 8px; color: var(--primary);"></i> Mobile Notifications (NotifBridge)</h2>
        </div>
        <p style="color: var(--text-secondary); margin-bottom: 20px;">
            Pushes alerts to your phone via a NotifBridge server on your network. The API key
            is encrypted at rest with this app's own local key (same as Groww credentials).
        </p>
        <form method="POST" action="<?php echo url('notifications.php'); ?>" id="configForm">
<?php echo csrf_field(); ?>
            <input type="hidden" name="action" id="configActionInput" value="save_config">

            <div class="form-group">
                <label>Server URL <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="url" name="server_url" placeholder="https://your-notifbridge-server.example.com" required
                           value="<?php echo htmlspecialchars($connection['server_url'] ?? ''); ?>">
                    <i class="fas fa-server"></i>
                </div>
            </div>

            <div class="form-group">
                <label>API Key</label>
                <div style="display: flex; gap: 8px;">
                    <input type="password" name="api_key" id="apiKeyInput"
                           placeholder="<?php echo $connection ? 'Leave blank to keep existing' : 'API key'; ?>"
                           style="flex: 1; padding: 10px 14px; background: var(--bg-input); border: 1px solid var(--border); border-radius: var(--radius-sm); color: var(--text-primary); font-family: inherit; font-size: 0.9rem;">
                    <button type="button" class="btn btn-secondary" id="apiKeyToggleBtn" onclick="toggleApiKeyVisibility()">Show</button>
                </div>
                <small style="color: var(--text-muted);">Leave API Key blank to keep its existing saved value.</small>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Low balance threshold (Rs.)</label>
                    <div class="input-wrapper">
                        <input type="number" step="0.01" name="low_balance_threshold" value="<?php echo htmlspecialchars((string)$thresholds['low_balance_threshold']); ?>">
                        <i class="fas fa-rupee-sign"></i>
                    </div>
                </div>
                <div class="form-group">
                    <label>Large savings entry threshold (Rs.)</label>
                    <div class="input-wrapper">
                        <input type="number" step="0.01" name="large_entry_threshold" value="<?php echo htmlspecialchars((string)$thresholds['large_entry_threshold']); ?>">
                        <i class="fas fa-rupee-sign"></i>
                    </div>
                </div>
            </div>
            <div class="form-group" style="max-width: 260px;">
                <label>Maturity reminder (days ahead)</label>
                <div class="input-wrapper">
                    <input type="number" name="maturity_days_ahead" value="<?php echo htmlspecialchars((string)$thresholds['maturity_days_ahead']); ?>">
                    <i class="fas fa-calendar-check"></i>
                </div>
            </div>

            <p style="margin: 12px 0 0; color: var(--text-secondary);">
                <?php echo $connection ? 'Configured.' : 'Not configured.'; ?>
            </p>

            <div class="form-actions" style="justify-content: flex-end;">
                <?php if ($connection): ?>
                <button type="button" class="btn btn-ghost" onclick="disconnectNotifBridge()">Disconnect</button>
                <?php endif; ?>
                <button type="submit" class="btn btn-secondary" onclick="document.getElementById('configActionInput').value='test_connection';">Test Connection</button>
                <button type="submit" class="btn btn-primary" onclick="document.getElementById('configActionInput').value='save_config';">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Hidden form for disconnect -->
<form id="disconnectForm" method="POST" action="<?php echo url('notifications.php'); ?>" style="display:none;">
<?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="disconnect">
</form>

<!-- ============================================================ Templates -->
<div class="tab-content <?php echo $activeTab === 'templates' ? 'active' : ''; ?>" id="templatesTab">
    <div class="toolbar">
        <div class="toolbar-left">
            <span style="color: var(--text-secondary);">
                <strong><?php echo count($templates); ?></strong> template(s) defined
            </span>
        </div>
        <div class="toolbar-right">
            <button class="btn btn-primary" onclick="openModal('addTemplateModal')">
                <i class="fas fa-plus"></i> Add Template
            </button>
        </div>
    </div>

    <?php if (empty($templates)): ?>
    <div class="card">
        <div class="empty-state">
            <i class="fas fa-bell"></i>
            <h3>No Notification Templates Yet</h3>
            <p>Create a reusable message template — with placeholders pulled from a module's fields — for reminders and push notifications</p>
            <button class="btn btn-primary" onclick="openModal('addTemplateModal')">
                <i class="fas fa-plus"></i> Create First Template
            </button>
        </div>
    </div>
    <?php else: ?>
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-bell" style="margin-right: 8px; color: var(--primary);"></i> Notification Templates</h2>
        </div>

        <div class="table-container" style="border: none;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Module</th>
                        <th>Title</th>
                        <th>Priority</th>
                        <th>Channels</th>
                        <th>Status</th>
                        <th style="width: 140px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($templates as $t): ?>
                    <tr class="animate-fade-in">
                        <td><strong><?php echo htmlspecialchars($t['name'] ?: ($t['key'] ?: $t['title'])); ?></strong></td>
                        <td>
                            <span class="badge" style="background: <?php echo getModuleBadgeClass($t['linked_to']); ?>; color: #fff; font-size: 0.72rem;">
                                <?php echo $moduleLabels[$t['linked_to']] ?? ucfirst(str_replace('_', ' ', $t['linked_to'])); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($t['title']); ?></td>
                        <td><?php echo $t['priority'] === 'high' ? '<span class="badge badge-warning">High</span>' : '<span class="badge badge-info">Default</span>'; ?></td>
                        <td>
                            <?php if ($t['channel_inapp']): ?><span class="badge badge-info" style="font-size:0.68rem;">In-app</span><?php endif; ?>
                            <?php if ($t['channel_email']): ?><span class="badge badge-info" style="font-size:0.68rem;">Email</span><?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?php echo $t['is_active'] ? 'badge-success' : 'badge-warning'; ?>">
                                <?php echo $t['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </td>
                        <td>
                            <div class="actions">
                                <button class="btn btn-icon btn-ghost" title="Edit"
                                        onclick="editTemplate(<?php echo htmlspecialchars(json_encode($t)); ?>)">
                                    <i class="fas fa-pen"></i>
                                </button>
                                <button class="btn btn-icon btn-ghost" title="Toggle Status"
                                        onclick="toggleTemplate(<?php echo $t['id']; ?>)">
                                    <i class="fas fa-power-off"></i>
                                </button>
                                <button class="btn btn-icon btn-ghost" title="Delete" style="color: var(--danger);"
                                        onclick="deleteTemplate(<?php echo $t['id']; ?>, '<?php echo htmlspecialchars($t['name'] ?: ($t['key'] ?: $t['title'])); ?>')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ============================================================ Scheduled Reminders -->
<div class="tab-content <?php echo $activeTab === 'reminders' ? 'active' : ''; ?>" id="remindersTab">
    <?php if (!$connection): ?>
    <div class="empty-state card">
        <i class="fas fa-clock"></i>
        <h3>NotifBridge not configured</h3>
        <p>Connect NotifBridge in the Configuration tab first -- reminders are delivered by the same server the desktop app uses.</p>
        <button class="btn btn-primary" onclick="document.querySelector('.tab-btn[data-tab=configTab]').click();">
            <i class="fas fa-cog"></i> Go to Configuration
        </button>
    </div>
    <?php else: ?>

    <div class="toolbar">
        <div class="toolbar-left">
            <?php if ($showReminderForm): ?>
            <a href="<?php echo url('notifications.php?tab=reminders'); ?>" class="btn btn-ghost">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
            <?php else: ?>
            <span class="badge badge-primary"><i class="fas fa-mobile-alt"></i> <?php echo count($devices); ?> device(s)</span>
            <?php endif; ?>
        </div>
        <div class="toolbar-right">
            <?php if (!$showReminderForm): ?>
            <a href="<?php echo url('notifications.php?tab=reminders&action=add'); ?>" class="btn btn-primary">
                <i class="fas fa-plus"></i> Add Reminder
            </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($loadError): ?>
    <div class="alert alert-error">
        <i class="fas fa-triangle-exclamation"></i>
        <span>Couldn't reach NotifBridge: <?php echo htmlspecialchars($loadError); ?></span>
    </div>
    <?php endif; ?>

    <?php if ($showReminderForm): ?>
    <!-- Add/Edit Reminder -->
    <div class="card animate-fade-in">
        <div class="card-header">
            <h2>
                <i class="fas fa-<?php echo $editReminderData ? 'edit' : 'plus'; ?>" style="margin-right: 8px; color: var(--primary);"></i>
                <?php echo $editReminderData ? 'Edit Reminder' : 'Add Reminder'; ?>
            </h2>
        </div>
        <form method="POST" action="<?php echo url('notifications.php'); ?>" id="reminderForm">
<?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="reminder_save">
            <?php if ($editReminderData): ?>
                <input type="hidden" name="edit_id" value="<?php echo (int)$editReminderData['id']; ?>">
            <?php endif; ?>

            <?php if (!empty($activeTemplates)): ?>
            <div class="form-group">
                <label>Start from Template</label>
                <div class="input-wrapper">
                    <select id="templatePicker" onchange="applyReminderTemplate(this.value)">
                        <option value="">-- Custom message (no template) --</option>
                        <?php foreach ($activeTemplates as $t): ?>
                        <option value="<?php echo (int)$t['id']; ?>"
                                data-title="<?php echo htmlspecialchars($t['title'], ENT_QUOTES); ?>"
                                data-body="<?php echo htmlspecialchars($t['body'], ENT_QUOTES); ?>">
                            <?php echo htmlspecialchars($t['name'] ?: $t['key']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <i class="fas fa-file-lines"></i>
                </div>
                <small style="color: var(--text-muted);">Copies the template's title/body as a starting point -- any {placeholder} tokens are left as-is to fill in by hand.</small>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label>Title <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="text" name="title" id="reminderTitle" required
                           value="<?php echo htmlspecialchars($editReminderData['title'] ?? ''); ?>">
                    <i class="fas fa-heading"></i>
                </div>
            </div>

            <div class="form-group">
                <label>Body <span class="required">*</span></label>
                <div class="input-wrapper">
                    <textarea name="body" id="reminderBody" rows="3" required><?php echo htmlspecialchars($editReminderData['body'] ?? ''); ?></textarea>
                    <i class="fas fa-align-left" style="top: 14px;"></i>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Send Date <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <input type="date" name="send_date" required value="<?php echo htmlspecialchars($editDate); ?>">
                        <i class="fas fa-calendar"></i>
                    </div>
                </div>
                <div class="form-group">
                    <label>Send Time (IST)</label>
                    <div class="input-wrapper">
                        <input type="time" name="send_time" value="<?php echo htmlspecialchars($editTime ?: '09:00'); ?>">
                        <i class="fas fa-clock"></i>
                    </div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Repeat</label>
                    <div class="input-wrapper">
                        <select name="repeat_type">
                            <?php foreach (NOTIFBRIDGE_REPEAT_LABELS as $val => $label): ?>
                            <option value="<?php echo $val; ?>" <?php echo ($editReminderData['repeat_type'] ?? 'none') === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <i class="fas fa-rotate"></i>
                    </div>
                </div>
                <div class="form-group">
                    <label>Repeat Until</label>
                    <div class="input-wrapper">
                        <input type="date" name="repeat_until" value="<?php echo htmlspecialchars($editUntilDate); ?>">
                        <i class="fas fa-calendar-xmark"></i>
                    </div>
                    <small style="color: var(--text-muted);">Leave blank to repeat indefinitely.</small>
                </div>
            </div>

            <div class="form-group">
                <label>Priority</label>
                <div class="input-wrapper">
                    <select name="priority">
                        <option value="default" <?php echo ($editReminderData['priority'] ?? 'default') === 'default' ? 'selected' : ''; ?>>Default</option>
                        <option value="high" <?php echo ($editReminderData['priority'] ?? 'default') === 'high' ? 'selected' : ''; ?>>High</option>
                    </select>
                    <i class="fas fa-bolt"></i>
                </div>
            </div>

            <div class="form-actions">
                <a href="<?php echo url('notifications.php?tab=reminders'); ?>" class="btn btn-ghost">Cancel</a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> <?php echo $editReminderData ? 'Update Reminder' : 'Save Reminder'; ?>
                </button>
            </div>
        </form>
    </div>

    <?php else: ?>
    <!-- Reminders List -->
    <?php if (empty($reminders)): ?>
        <div class="empty-state card">
            <i class="fas fa-clock"></i>
            <h3>No reminders yet</h3>
            <p>Scheduled reminders push to every device registered with NotifBridge -- the same ones the desktop app uses.</p>
            <a href="<?php echo url('notifications.php?tab=reminders&action=add'); ?>" class="btn btn-primary">
                <i class="fas fa-plus"></i> Add Reminder
            </a>
        </div>
    <?php else: ?>
        <div class="details-grid">
            <?php foreach ($reminders as $r): ?>
            <div class="detail-card animate-fade-in">
                <div class="card-menu">
                    <div style="display: flex; gap: 4px;">
                        <a href="<?php echo url('notifications.php?tab=reminders&send_now=' . (int)$r['id']); ?>" class="btn btn-icon btn-ghost" title="Send Now"
                           onclick="return confirm('Send this reminder right now?');">
                            <i class="fas fa-paper-plane"></i>
                        </a>
                        <a href="<?php echo url('notifications.php?tab=reminders&edit=' . (int)$r['id']); ?>" class="btn btn-icon btn-ghost" title="Edit">
                            <i class="fas fa-pen"></i>
                        </a>
                        <button class="btn btn-icon btn-ghost" title="Delete" style="color: var(--danger);"
                                onclick="showConfirm('Delete Reminder', 'Delete this reminder?', function() { window.location.href='<?php echo url("notifications.php?tab=reminders&delete=" . (int)$r['id'] . "&csrf=" . urlencode(Auth::generateCSRF())); ?>'; })">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>

                <div class="detail-card-header">
                    <div class="detail-avatar" style="background: linear-gradient(135deg, var(--primary), var(--primary-light));">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div>
                        <div class="detail-name"><?php echo htmlspecialchars($r['title']); ?></div>
                        <div class="detail-email"><?php echo htmlspecialchars(NOTIFBRIDGE_REPEAT_LABELS[$r['repeat_type'] ?? 'none'] ?? 'One-time'); ?></div>
                    </div>
                </div>

                <div style="display: flex; gap: 6px; margin: -8px 0 14px;">
                    <?php if (!empty($r['is_active'])): ?>
                    <span class="badge badge-success"><i class="fas fa-check-circle"></i> Active</span>
                    <?php else: ?>
                    <span class="badge badge-warning"><i class="fas fa-pause-circle"></i> Paused</span>
                    <?php endif; ?>
                    <?php if (($r['priority'] ?? 'default') === 'high'): ?>
                    <span class="badge badge-warning"><i class="fas fa-bolt"></i> High priority</span>
                    <?php endif; ?>
                </div>

                <p style="color: var(--text-secondary); font-size: 0.85rem; line-height: 1.4; margin: 0 0 14px;">
                    <?php echo htmlspecialchars(substr($r['body'] ?? '', 0, 100)); ?><?php echo strlen($r['body'] ?? '') > 100 ? '...' : ''; ?>
                </p>

                <div class="detail-info">
                    <div class="detail-info-item">
                        <span class="label">Next Send</span>
                        <span class="value"><?php echo htmlspecialchars(notifbridgeUtcIsoToLocalDisplay($r['send_at'] ?? null)); ?></span>
                    </div>
                    <div class="detail-info-item">
                        <span class="label">Repeat</span>
                        <span class="value"><?php echo htmlspecialchars(NOTIFBRIDGE_REPEAT_LABELS[$r['repeat_type'] ?? 'none'] ?? 'One-time'); ?></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- ============================================================ Send Test Message -->
<div class="tab-content <?php echo $activeTab === 'send' ? 'active' : ''; ?>" id="sendTab">
    <?php if (!$connection): ?>
    <div class="empty-state card">
        <i class="fas fa-paper-plane"></i>
        <h3>NotifBridge not configured</h3>
        <p>Connect NotifBridge in the Configuration tab first.</p>
        <button class="btn btn-primary" onclick="document.querySelector('.tab-btn[data-tab=configTab]').click();">
            <i class="fas fa-cog"></i> Go to Configuration
        </button>
    </div>
    <?php else: ?>
    <div class="card animate-fade-in">
        <div class="card-header">
            <h2><i class="fas fa-paper-plane" style="margin-right: 8px; color: var(--primary);"></i> Send Test Message</h2>
        </div>
        <form method="POST" action="<?php echo url('notifications.php'); ?>" id="sendTestForm" enctype="multipart/form-data">
<?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="send_test">

            <div class="form-row">
                <div class="form-group">
                    <label>Template</label>
                    <div class="input-wrapper">
                        <select name="template_id" id="sendTemplateSelect" onchange="onSendTemplateChanged()">
                            <option value="">-- Custom message (no template) --</option>
                            <?php foreach ($activeTemplates as $t): ?>
                            <option value="<?php echo (int)$t['id']; ?>"
                                    data-title="<?php echo htmlspecialchars($t['title'], ENT_QUOTES); ?>"
                                    data-body="<?php echo htmlspecialchars($t['body'], ENT_QUOTES); ?>">
                                <?php echo htmlspecialchars($t['name'] ?: $t['key']); ?> — <?php echo htmlspecialchars($t['title']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <i class="fas fa-file-lines"></i>
                    </div>
                </div>
                <div class="form-group">
                    <label>Device</label>
                    <div class="input-wrapper">
                        <select name="device_id" id="sendDeviceSelect">
                            <option value="all">All devices</option>
                        </select>
                        <i class="fas fa-mobile-alt"></i>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm" onclick="refreshDevices()" style="margin-top: 6px;">
                        <i class="fas fa-sync"></i> Refresh Devices
                    </button>
                </div>
            </div>

            <div id="sendCustomFields">
                <div class="form-row">
                    <div class="form-group">
                        <label>Title</label>
                        <div class="input-wrapper">
                            <input type="text" name="custom_title" id="sendCustomTitle">
                            <i class="fas fa-heading"></i>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Priority</label>
                        <div class="input-wrapper">
                            <select name="custom_priority">
                                <option value="default">Default</option>
                                <option value="high">High</option>
                            </select>
                            <i class="fas fa-bolt"></i>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label>Body</label>
                    <div class="input-wrapper no-icon">
                        <textarea name="custom_body" id="sendCustomBody" rows="4"></textarea>
                    </div>
                </div>
                <div class="form-group">
                    <label>Attachment <span style="color: var(--text-muted); font-weight: 400;">(optional image or document, max <?php echo NOTIFBRIDGE_MAX_ATTACHMENT_MB; ?> MB, opens from the phone app)</span></label>
                    <input type="file" name="attachment" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip">
                </div>
            </div>

            <div id="sendPlaceholderFields" style="display: none;"></div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Send Test Message</button>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<!-- ============================================================ History -->
<div class="tab-content <?php echo $activeTab === 'history' ? 'active' : ''; ?>" id="historyTab">
    <?php if (!$connection): ?>
    <div class="empty-state card">
        <i class="fas fa-history"></i>
        <h3>NotifBridge not configured</h3>
        <p>Connect NotifBridge in the Configuration tab first.</p>
        <button class="btn btn-primary" onclick="document.querySelector('.tab-btn[data-tab=configTab]').click();">
            <i class="fas fa-cog"></i> Go to Configuration
        </button>
    </div>
    <?php else: ?>
    <div class="card animate-fade-in">
        <div class="card-header">
            <h2><i class="fas fa-history" style="margin-right: 8px; color: var(--primary);"></i> History</h2>
            <div class="card-actions">
                <button class="btn btn-secondary btn-sm" onclick="refreshHistory()"><i class="fas fa-sync"></i> Refresh</button>
            </div>
        </div>
        <div class="table-container" style="border: none;">
            <table class="data-table" id="historyTable">
                <thead>
                    <tr><th>Sent</th><th>Title</th><th>Message</th><th>Attachment</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <tr><td colspan="5" style="text-align: center; padding: 28px; color: var(--text-muted);">Loading...</td></tr>
                </tbody>
            </table>
        </div>
        <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: 10px;">Click a row to view the full message.</p>
    </div>
    <?php endif; ?>
</div>

<!-- Add Template Modal -->
<div class="modal" id="addTemplateModal">
    <div class="modal-header">
        <h3><i class="fas fa-plus" style="margin-right: 8px; color: var(--primary);"></i> Add Template</h3>
        <button class="modal-close" onclick="closeModal('addTemplateModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
        <form id="addTemplateForm" method="POST" action="<?php echo url('notifications.php'); ?>">
<?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="template_create">

            <div class="form-group">
                <label>Template Name <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="text" name="name" id="addTemplateName" placeholder="e.g., Card Renewal Reminder" required>
                    <i class="fas fa-tag"></i>
                </div>
            </div>

            <div class="form-group">
                <label>Module <span class="required">*</span></label>
                <div class="input-wrapper">
                    <select name="linked_to" id="addTemplateModule">
                        <?php $preselect = $_GET['module'] ?? ''; foreach ($moduleLabels as $val => $lbl): ?>
                        <option value="<?php echo $val; ?>"<?php echo $val === $preselect ? ' selected' : ''; ?>><?php echo $lbl; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <i class="fas fa-layer-group"></i>
                </div>
                <small style="color: var(--text-muted); font-size: 0.78rem;">Placeholders below are drawn from this module's fields.</small>
            </div>

            <div class="form-group">
                <label>Title <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="text" name="title" id="addTemplateTitle" placeholder="e.g., {name} renewal due" required>
                    <i class="fas fa-heading"></i>
                </div>
            </div>

            <div class="form-group">
                <label>Body <span class="required">*</span></label>
                <div class="input-wrapper no-icon">
                    <textarea name="body" id="addTemplateBody" rows="4" placeholder="e.g., Your {name} is due for renewal on {date}." required></textarea>
                </div>
            </div>

            <div class="form-group">
                <label>Insert Placeholder</label>
                <div class="placeholder-chip-list" id="addPlaceholderChips">
                    <span style="color: var(--text-muted); font-size: 0.8rem;">Select a module above to see its fields...</span>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Days before expiry</label>
                    <div class="input-wrapper">
                        <input type="text" name="reminder_days" placeholder="30, 7, 1">
                        <i class="fas fa-calendar-minus"></i>
                    </div>
                    <small style="color: var(--text-muted);">Used by the document_expiry template. Expiry day is always included.</small>
                </div>
                <div class="form-group">
                    <label>Priority</label>
                    <div class="input-wrapper">
                        <select name="priority" id="addTemplatePriority">
                            <option value="default">Default</option>
                            <option value="high">High</option>
                        </select>
                        <i class="fas fa-bolt"></i>
                    </div>
                </div>
                <div class="form-group">
                    <label>Channels</label>
                    <div style="display:flex; gap:16px; padding-top: 10px;">
                        <label class="form-check">
                            <input type="checkbox" name="channel_inapp" id="addChannelInapp" checked>
                            <span class="checkmark"></span>
                            <span class="form-check-label">In-app</span>
                        </label>
                        <label class="form-check">
                            <input type="checkbox" name="channel_email" id="addChannelEmail" checked>
                            <span class="checkmark"></span>
                            <span class="form-check-label">Email</span>
                        </label>
                    </div>
                </div>
            </div>
        </form>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('addTemplateModal')">Cancel</button>
        <button class="btn btn-primary" onclick="document.getElementById('addTemplateForm').submit();">
            <i class="fas fa-plus"></i> Create Template
        </button>
    </div>
</div>

<!-- Edit Template Modal -->
<div class="modal" id="editTemplateModal">
    <div class="modal-header">
        <h3><i class="fas fa-edit" style="margin-right: 8px; color: var(--primary);"></i> Edit Template</h3>
        <button class="modal-close" onclick="closeModal('editTemplateModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
        <form id="editTemplateForm" method="POST" action="<?php echo url('notifications.php'); ?>">
<?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="template_update">
            <input type="hidden" name="template_id" id="editTemplateId">

            <div class="form-group">
                <label>Template Name <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="text" name="name" id="editTemplateName" required>
                    <i class="fas fa-tag"></i>
                </div>
            </div>

            <div class="form-group">
                <label>Module <span class="required">*</span></label>
                <div class="input-wrapper">
                    <select name="linked_to" id="editTemplateModule">
                        <?php foreach ($moduleLabels as $val => $lbl): ?>
                        <option value="<?php echo $val; ?>"><?php echo $lbl; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <i class="fas fa-layer-group"></i>
                </div>
            </div>

            <div class="form-group">
                <label>Title <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="text" name="title" id="editTemplateTitle" required>
                    <i class="fas fa-heading"></i>
                </div>
            </div>

            <div class="form-group">
                <label>Body <span class="required">*</span></label>
                <div class="input-wrapper no-icon">
                    <textarea name="body" id="editTemplateBody" rows="4" required></textarea>
                </div>
            </div>

            <div class="form-group">
                <label>Insert Placeholder</label>
                <div class="placeholder-chip-list" id="editPlaceholderChips">
                    <span style="color: var(--text-muted); font-size: 0.8rem;">Loading fields...</span>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Days before expiry</label>
                    <div class="input-wrapper">
                        <input type="text" name="reminder_days" id="editTemplateReminderDays" placeholder="30, 7, 1">
                        <i class="fas fa-calendar-minus"></i>
                    </div>
                    <small style="color: var(--text-muted);">Used by the document_expiry template. Expiry day is always included.</small>
                </div>
                <div class="form-group">
                    <label>Priority</label>
                    <div class="input-wrapper">
                        <select name="priority" id="editTemplatePriority">
                            <option value="default">Default</option>
                            <option value="high">High</option>
                        </select>
                        <i class="fas fa-bolt"></i>
                    </div>
                </div>
                <div class="form-group">
                    <label>Channels</label>
                    <div style="display:flex; gap:16px; padding-top: 10px;">
                        <label class="form-check">
                            <input type="checkbox" name="channel_inapp" id="editChannelInapp">
                            <span class="checkmark"></span>
                            <span class="form-check-label">In-app</span>
                        </label>
                        <label class="form-check">
                            <input type="checkbox" name="channel_email" id="editChannelEmail">
                            <span class="checkmark"></span>
                            <span class="form-check-label">Email</span>
                        </label>
                    </div>
                </div>
            </div>
        </form>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('editTemplateModal')">Cancel</button>
        <button class="btn btn-primary" onclick="document.getElementById('editTemplateForm').submit();">
            <i class="fas fa-save"></i> Save Changes
        </button>
    </div>
</div>

<!-- Hidden form for template toggle/delete actions -->
<form id="templateActionForm" method="POST" action="<?php echo url('notifications.php'); ?>" style="display:none;">
<?php echo csrf_field(); ?>
    <input type="hidden" name="action" id="templateActionType">
    <input type="hidden" name="template_id" id="templateActionId">
</form>

<!-- History detail modal -->
<div class="modal" id="historyDetailModal">
    <div class="modal-header">
        <h3 id="historyDetailTitle">Notification</h3>
        <button class="modal-close" onclick="closeModal('historyDetailModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
        <p id="historyDetailMeta" style="color: var(--text-muted); margin-bottom: 12px;"></p>
        <div id="historyDetailBody" style="white-space: pre-wrap; line-height: 1.5;"></div>
        <a id="historyDetailAttachment" class="btn btn-ghost btn-sm" target="_blank" rel="noopener" style="display: none; margin-top: 12px;"></a>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('historyDetailModal')">Close</button>
    </div>
</div>

<script>
// ---------------------------------------------------------------- Config tab
function toggleApiKeyVisibility() {
    var input = document.getElementById('apiKeyInput');
    var btn = document.getElementById('apiKeyToggleBtn');
    if (input.type === 'password') {
        input.type = 'text';
        btn.textContent = 'Hide';
    } else {
        input.type = 'password';
        btn.textContent = 'Show';
    }
}

function disconnectNotifBridge() {
    showConfirm(
        'Disconnect NotifBridge',
        'Disconnect NotifBridge? Existing reminders on the server are not affected.',
        function() { document.getElementById('disconnectForm').submit(); }
    );
}

// ------------------------------------------------------------- Templates tab
var lastFocusedTemplateField = { add: 'addTemplateBody', edit: 'editTemplateBody' };

['add', 'edit'].forEach(function(prefix) {
    ['Title', 'Body'].forEach(function(part) {
        var el = document.getElementById(prefix + 'Template' + part);
        if (el) {
            el.addEventListener('focus', function() {
                lastFocusedTemplateField[prefix] = prefix + 'Template' + part;
            });
        }
    });
});

function insertPlaceholder(prefix, token) {
    var targetId = lastFocusedTemplateField[prefix] || (prefix + 'TemplateBody');
    var el = document.getElementById(targetId);
    if (!el) return;
    var start = el.selectionStart != null ? el.selectionStart : el.value.length;
    var end = el.selectionEnd != null ? el.selectionEnd : el.value.length;
    el.value = el.value.slice(0, start) + token + el.value.slice(end);
    el.focus();
    var newPos = start + token.length;
    el.setSelectionRange(newPos, newPos);
}

function renderPlaceholderChips(prefix, fields) {
    var container = document.getElementById(prefix + 'PlaceholderChips');
    if (!container) return;
    container.innerHTML = '';

    var systemChips = [['{date}', 'Date'], ['{time}', 'Time']];
    systemChips.forEach(function(pair) {
        var chip = document.createElement('span');
        chip.className = 'placeholder-chip';
        chip.textContent = pair[0];
        chip.title = pair[1];
        chip.addEventListener('click', function() { insertPlaceholder(prefix, pair[0]); });
        container.appendChild(chip);
    });

    if (!fields || fields.length === 0) {
        var hint = document.createElement('span');
        hint.style.color = 'var(--text-muted)';
        hint.style.fontSize = '0.8rem';
        hint.textContent = 'No fields defined for this module yet.';
        container.appendChild(hint);
        return;
    }

    fields.forEach(function(f) {
        var chip = document.createElement('span');
        chip.className = 'placeholder-chip';
        chip.textContent = '{' + f.name + '}';
        chip.title = f.label;
        chip.addEventListener('click', function() { insertPlaceholder(prefix, '{' + f.name + '}'); });
        container.appendChild(chip);
    });
}

function loadPlaceholderChips(prefix) {
    var module = document.getElementById(prefix + 'TemplateModule').value;
    ajaxRequest('/notifications.php?action=get_fields&module=' + encodeURIComponent(module), 'GET', null, function(response) {
        if (response.success) {
            renderPlaceholderChips(prefix, response.fields);
        }
    });
}

document.getElementById('addTemplateModule').addEventListener('change', function() { loadPlaceholderChips('add'); });
document.getElementById('editTemplateModule').addEventListener('change', function() { loadPlaceholderChips('edit'); });

var origOpenModal = window.openModal;
window.openModal = function(modalId) {
    if (modalId === 'addTemplateModal') {
        document.getElementById('addTemplateForm').reset();
        document.getElementById('addChannelInapp').checked = true;
        document.getElementById('addChannelEmail').checked = true;
        loadPlaceholderChips('add');
    }
    origOpenModal(modalId);
};

document.addEventListener('DOMContentLoaded', function() {
    loadPlaceholderChips('add');
});

function editTemplate(t) {
    document.getElementById('editTemplateId').value = t.id;
    document.getElementById('editTemplateName').value = t.name;
    document.getElementById('editTemplateModule').value = t.linked_to;
    document.getElementById('editTemplateTitle').value = t.title;
    document.getElementById('editTemplateBody').value = t.body;
    document.getElementById('editTemplatePriority').value = t.priority || 'default';
    document.getElementById('editTemplateReminderDays').value = t.reminder_days || '';
    document.getElementById('editChannelInapp').checked = t.channel_inapp == 1;
    document.getElementById('editChannelEmail').checked = t.channel_email == 1;
    loadPlaceholderChips('edit');
    openModal('editTemplateModal');
}

function toggleTemplate(id) {
    document.getElementById('templateActionType').value = 'template_toggle';
    document.getElementById('templateActionId').value = id;
    document.getElementById('templateActionForm').submit();
}

function deleteTemplate(id, name) {
    showConfirm(
        'Delete Template',
        'Are you sure you want to delete "' + name + '"? Reminder fields using it will keep working via their last-saved message.',
        function() {
            document.getElementById('templateActionType').value = 'template_delete';
            document.getElementById('templateActionId').value = id;
            document.getElementById('templateActionForm').submit();
        }
    );
}

<?php if (!empty($_GET['module'])): ?>
document.addEventListener('DOMContentLoaded', function() {
    openModal('addTemplateModal');
});
<?php endif; ?>

// --------------------------------------------------------- Scheduled Reminders
function applyReminderTemplate(templateId) {
    if (!templateId) return;
    var opt = document.querySelector('#templatePicker option[value="' + templateId + '"]');
    if (!opt) return;
    document.getElementById('reminderTitle').value = opt.getAttribute('data-title') || '';
    document.getElementById('reminderBody').value = opt.getAttribute('data-body') || '';
}

// ------------------------------------------------------- Send Test Message
function escapeHtmlNotif(text) {
    var div = document.createElement('div');
    div.textContent = text == null ? '' : text;
    return div.innerHTML;
}

function extractPlaceholders(text) {
    var re = /\{(\w+)\}/g, out = [], seen = {}, m;
    while ((m = re.exec(text)) !== null) {
        if (!seen[m[1]] && m[1] !== 'date' && m[1] !== 'time') {
            seen[m[1]] = true;
            out.push(m[1]);
        }
    }
    return out;
}

function onSendTemplateChanged() {
    var sel = document.getElementById('sendTemplateSelect');
    if (!sel) return;
    var opt = sel.options[sel.selectedIndex];
    var customDiv = document.getElementById('sendCustomFields');
    var phDiv = document.getElementById('sendPlaceholderFields');

    if (!sel.value) {
        customDiv.style.display = '';
        phDiv.style.display = 'none';
        phDiv.innerHTML = '';
        return;
    }

    customDiv.style.display = 'none';
    var title = opt.getAttribute('data-title') || '';
    var body = opt.getAttribute('data-body') || '';
    var tokens = extractPlaceholders(title + ' ' + body);

    if (tokens.length === 0) {
        phDiv.innerHTML = '<p style="color: var(--text-muted); font-size: 0.85rem;">(This template has no {placeholders}.)</p>';
    } else {
        var html = '';
        tokens.forEach(function(name) {
            html += '<div class="form-group"><label>' + escapeHtmlNotif(name) + '</label>' +
                    '<div class="input-wrapper no-icon"><input type="text" name="placeholder[' + escapeHtmlNotif(name) + ']"></div></div>';
        });
        phDiv.innerHTML = html;
    }
    phDiv.style.display = 'block';
}

function refreshDevices() {
    var sel = document.getElementById('sendDeviceSelect');
    if (!sel) return;
    ajaxRequest('/notifications.php?action=list_devices', 'GET', null, function(response) {
        sel.innerHTML = '<option value="all">All devices</option>';
        if (response.success) {
            response.devices.forEach(function(d) {
                var label = (d.api_key_name ? d.api_key_name + ': ' : '') + (d.nickname || d.device_uuid) + ' (#' + d.id +
                            (d.last_seen ? ', seen ' + String(d.last_seen).substring(0, 10) : '') + ')' + (d.active === false ? ' — inactive' : '');
                var opt = document.createElement('option');
                opt.value = d.id;
                opt.textContent = label;
                sel.appendChild(opt);
            });
        } else {
            showToast('Could not list devices: ' + (response.error || 'unknown error'), 'error');
        }
    });
}

// ------------------------------------------------------------------- History
function refreshHistory() {
    var tbody = document.querySelector('#historyTable tbody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 28px; color: var(--text-muted);">Loading...</td></tr>';
    ajaxRequest('/notifications.php?action=history', 'GET', null, function(response) {
        if (!response.success) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 28px; color: var(--danger);">' + escapeHtmlNotif(response.error || 'Failed to load') + '</td></tr>';
            return;
        }
        if (!response.rows.length) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 28px; color: var(--text-muted);">No notifications yet.</td></tr>';
            return;
        }
        window._historyRows = response.rows;
        var html = '';
        response.rows.forEach(function(row, i) {
            var body = (row.body || '').replace(/\s+/g, ' ').trim();
            var preview = body.length > 100 ? body.substring(0, 100) + '...' : body;
            html += '<tr class="row-selectable" onclick="showHistoryDetail(' + i + ')">';
            html += '<td>' + escapeHtmlNotif(row.created_at || '') + '</td>';
            html += '<td>' + escapeHtmlNotif(row.title || '') + '</td>';
            html += '<td>' + escapeHtmlNotif(preview) + '</td>';
            var att = historyAttachment(row);
            html += '<td>' + (att.name ? '<i class="fas fa-paperclip"></i> ' + escapeHtmlNotif(att.name) : '') + '</td>';
            html += '<td>' + escapeHtmlNotif(row.status || '') + '</td>';
            html += '</tr>';
        });
        tbody.innerHTML = html;
    });
}

function showHistoryDetail(i) {
    var row = (window._historyRows || [])[i];
    if (!row) return;
    document.getElementById('historyDetailTitle').textContent = row.title || 'Notification';
    document.getElementById('historyDetailMeta').textContent = 'Sent: ' + (row.created_at || '') + '    Status: ' + (row.status || '');
    document.getElementById('historyDetailBody').textContent = row.body || '';
    var att = historyAttachment(row);
    var link = document.getElementById('historyDetailAttachment');
    if (link) {
        link.style.display = att.url ? '' : 'none';
        link.href = att.url || '#';
        link.textContent = att.url ? 'Open attachment: ' + att.name : '';
    }
    openModal('historyDetailModal');
}

// {name, url} of a sent notification's file (data_json attachment_* or image_url).
function historyAttachment(row) {
    var data = {};
    try { data = JSON.parse(row.data_json || '{}') || {}; } catch (e) {}
    var url = data.attachment_url || row.image_url || '';
    var name = data.attachment_name || (url ? decodeURIComponent(url.split('?')[0].split('/').pop()) : '');
    return { name: name, url: url };
}

// --------------------------------------------------------- Tab activation
// Refresh Devices / History only when their tab actually becomes active,
// mirroring the tkinter app's Notebook <<NotebookTabChanged>> handler
// (app/ui/notifications_view.py's _on_tab_changed) instead of eagerly
// hitting NotifBridge for every tab on every page load.
document.querySelectorAll('.tab-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        if (btn.dataset.tab === 'sendTab') refreshDevices();
        if (btn.dataset.tab === 'historyTab') refreshHistory();
    });
});

document.addEventListener('DOMContentLoaded', function() {
    var active = document.querySelector('.tab-btn.active');
    if (!active) return;
    if (active.dataset.tab === 'sendTab') refreshDevices();
    if (active.dataset.tab === 'historyTab') refreshHistory();
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
