<?php
/**
 * Removed as a standalone page -- folded into notifications.php's
 * "Templates" tab (matching the tkinter desktop app's single Notifications
 * screen with Configuration/Templates/Scheduled Reminders/Send Test
 * Message/History tabs). Kept as a thin redirect so any existing bookmark,
 * or the AJAX endpoints other pages still call by this URL, keep working.
 */
require_once __DIR__ . '/includes/functions.php';

// Every query param this page used (?module=, and the AJAX ?action=get_fields
// / ?action=for_module reads) means the exact same thing on
// notifications.php -- just pass them through as-is, plus the tab selector.
$query = $_GET;
$query['tab'] = 'templates';
redirect('/notifications.php?' . http_build_query($query));
