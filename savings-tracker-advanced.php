<?php
/**
 * Removed as a standalone page -- folded into savingsmanage.php (one
 * tabbed Savings Tracker screen, matching the tkinter desktop app, instead
 * of 3 separate pages). Kept as a thin redirect so any existing bookmark
 * still lands somewhere useful. This page's own "page"/"cat" query params
 * map onto savingsmanage.php's single "cat" tab selector.
 */
require_once __DIR__ . '/includes/functions.php';

$pageToTab = ['dashboard' => '__dashboard__', 'daily' => '__daily__', 'monthly' => '__monthly__'];
$query = $_GET;
$page = $query['page'] ?? 'dashboard';
unset($query['page']);
// An explicit ?cat= (this page's own "category snapshot" selector) wins --
// it names a real category, so it's a more specific destination than the
// page selector. Only fall back to mapping ?page= when no ?cat= was given.
if (empty($query['cat'])) {
    $query['cat'] = $pageToTab[$page] ?? '__dashboard__';
}
redirect('/savingsmanage.php?' . http_build_query($query));
