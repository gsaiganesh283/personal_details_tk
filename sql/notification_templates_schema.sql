-- Notification Templates schema
-- Auto-created at runtime by includes/notifications_helper.php::ensureNotificationTemplatesSchema()
-- This file is provided for manual/offline setup only — it is not required for the app to work.

CREATE TABLE IF NOT EXISTS notification_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    linked_to VARCHAR(50) NOT NULL COMMENT 'Module/folder this template belongs to, same values as custom_fields.linked_to',
    title VARCHAR(200) NOT NULL COMMENT 'May contain {placeholder} tokens',
    body TEXT NOT NULL COMMENT 'May contain {placeholder} tokens',
    channel_inapp TINYINT(1) NOT NULL DEFAULT 1,
    channel_email TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_module (user_id, linked_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- scheduled_notifications also gains a nullable `template_id` column (added
-- via ensureNotificationsSchema()'s self-heal, same pattern used for the
-- custom_fields.field_type ENUM->VARCHAR migration in setup.php):
--
--   ALTER TABLE scheduled_notifications ADD COLUMN template_id INT DEFAULT NULL;
--
-- When set, includes/notifications_helper.php::deliverScheduledNotification()
-- re-renders the notification's title/message from the template with the
-- record's current field values right before sending (see
-- resolveTemplatePlaceholders()), rather than using the static snapshot
-- stored on the row at creation time.
