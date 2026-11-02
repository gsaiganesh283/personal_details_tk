<?php
/**
 * Removed as a standalone page -- folded into notifications.php's
 * "Scheduled Reminders" tab (matching the tkinter desktop app's single
 * Notifications screen with Configuration/Templates/Scheduled
 * Reminders/Send Test Message/History tabs). Kept as a thin redirect so any
 * existing bookmark or link still lands somewhere useful.
 */
require_once __DIR__ . '/includes/functions.php';

// Every query param this page used (?action=add, ?edit=, ?delete=,
// ?send_now=) means the exact same thing on notifications.php -- just pass
// them through as-is, plus the tab selector.
$query = $_GET;
$query['tab'] = 'reminders';
redirect('/notifications.php?' . http_build_query($query));
