<?php
$pageTitle = 'Index';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
header('Location: ' . url('login.php'));
exit;
