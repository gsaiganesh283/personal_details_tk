-- Scheduled Notifications (Reminders) schema
-- Auto-created at runtime by includes/notifications_helper.php::ensureNotificationsSchema()
-- This file is provided for manual/offline setup only — it is not required for the app to work.

CREATE TABLE IF NOT EXISTS scheduled_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    linked_to VARCHAR(50) DEFAULT NULL COMMENT 'Optional related module tag',
    custom_field_id INT DEFAULT NULL COMMENT 'Set when created via the reminder custom field type',
    record_id INT DEFAULT NULL COMMENT 'The record this reminder belongs to, for field-type reminders',
    block_instance INT NOT NULL DEFAULT 0 COMMENT 'Repeatable block instance index, for field-type reminders',
    channel_inapp TINYINT(1) NOT NULL DEFAULT 1,
    channel_email TINYINT(1) NOT NULL DEFAULT 1,
    send_at DATETIME NOT NULL,
    repeat_type ENUM('none','daily','weekly','monthly','yearly') NOT NULL DEFAULT 'none',
    repeat_until DATE DEFAULT NULL,
    status ENUM('pending','sent','cancelled') NOT NULL DEFAULT 'pending',
    last_sent_at DATETIME DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_due (is_active, send_at),
    INDEX idx_field_record (custom_field_id, record_id, block_instance)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Delivery:
--   cron-send-notifications.php delivers due rows:
--     - in-app: inserts into activity_log (action='reminder'), which the existing
--       notification bell (api-notifications.php) already reads and displays.
--     - email: sends via includes/mailer.php Mailer::sendNotification()
--
-- Trigger it every few minutes with an external scheduler (e.g. cron-job.org) hitting:
--   https://yourdomain.com/cron-send-notifications.php?secret=pds_notify_cron_2025
-- or via a real crontab entry:
--   */5 * * * * php /path/to/cron-send-notifications.php >> /tmp/notify_cron.log 2>&1
