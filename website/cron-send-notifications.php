<?php
/**
 * Scheduled Notifications Sender
 * Finds every scheduled_notifications row that is due and delivers it via
 * its enabled channels (in-app activity feed + email), advancing repeating
 * reminders to their next occurrence.
 *
 * Run every few minutes via cron:
 *   5 * * * * php /path/to/cron-send-notifications.php >> /tmp/notify_cron.log 2>&1
 *
 * Or trigger via an external scheduler (e.g. cron-job.org) hitting:
 *   https://yourdomain.com/cron-send-notifications.php?secret=pds_notify_cron_2025
 */

const NOTIFY_CRON_SECRET = 'pds_notify_cron_2025'; // Change to a strong secret

$isCli = php_sapi_name() === 'cli';

if (!$isCli && ($_GET['secret'] ?? '') !== NOTIFY_CRON_SECRET) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/notifications_helper.php';

[$schemaOk, $schemaMsg] = ensureNotificationsSchema();
if (!$schemaOk) {
    echo "Schema error: $schemaMsg\n";
    exit(1);
}

$result = processDueScheduledNotifications();

foreach ($result['log'] as $line) {
    echo $line . "\n";
}

echo "Done. {$result['sent']} sent, {$result['failed']} failed.\n";
