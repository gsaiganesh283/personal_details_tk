<?php
/**
 * Removed as a standalone page -- folded into savingsmanage.php's
 * "Dashboard" tab (matching the tkinter desktop app's single Savings
 * Tracker screen with Dashboard/Daily Summary/Monthly Summary/15 category
 * tabs/Custom Columns, instead of 3 separate pages). Kept as a thin
 * redirect so any existing bookmark still lands somewhere useful.
 */
require_once __DIR__ . '/includes/functions.php';

$query = $_GET;
$query['cat'] = '__dashboard__';
redirect('/savingsmanage.php?' . http_build_query($query));
