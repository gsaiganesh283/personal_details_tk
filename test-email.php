<?php
/**
 * Email Diagnostic Test Page
 * Upload this to your hosted server and visit it in browser.
 * DELETE this file after testing (security risk).
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo "<h2>Please login first, then visit this page.</h2>";
    echo "<a href='" . url('login.php') . "'>Login</a>";
    exit;
}

$results = [];
$testEmail = $_POST['test_email'] ?? '';

function addResult(&$results, $test, $status, $detail = '') {
    $results[] = ['test' => $test, 'status' => $status, 'detail' => $detail];
}

// Run diagnostic tests
addResult($results, 'PHP Version', 'info', phpversion());
addResult($results, 'Server', 'info', $_SERVER['HTTP_HOST'] ?? 'unknown');

// Test 1: Check if mail() function exists
if (function_exists('mail')) {
    addResult($results, 'PHP mail() function', 'pass', 'Available');
} else {
    addResult($results, 'PHP mail() function', 'fail', 'DISABLED - mail() not available');
}

// Test 2: Check fsockopen
if (function_exists('fsockopen')) {
    addResult($results, 'fsockopen() function', 'pass', 'Available');
} else {
    addResult($results, 'fsockopen() function', 'fail', 'DISABLED - cannot use SMTP');
}

// Test 3: Check stream_socket_enable_crypto
if (function_exists('stream_socket_enable_crypto')) {
    addResult($results, 'TLS/SSL support', 'pass', 'Available');
} else {
    addResult($results, 'TLS/SSL support', 'fail', 'DISABLED - cannot use encrypted SMTP');
}

// Test 4: Check openssl
if (extension_loaded('openssl')) {
    addResult($results, 'OpenSSL extension', 'pass', 'Loaded - ' . OPENSSL_VERSION_TEXT);
} else {
    addResult($results, 'OpenSSL extension', 'fail', 'NOT loaded');
}

// Test 5: Config file check
$configFile = __DIR__ . '/config/email-setup.php';
$config = [];
if (file_exists($configFile)) {
    $config = @include $configFile;
    if (!is_array($config)) $config = [];
}
$smtpHost = $config['smtp_host'] ?? getenv('MAIL_SMTP_HOST');
$smtpPort = $config['smtp_port'] ?? getenv('MAIL_SMTP_PORT');
$smtpUser = $config['smtp_user'] ?? getenv('MAIL_SMTP_USER');
$smtpPass = $config['smtp_pass'] ?? getenv('MAIL_SMTP_PASS');

addResult($results, 'putenv() function', !in_array('putenv', explode(',', ini_get('disable_functions'))) ? 'pass' : 'fail',
    in_array('putenv', explode(',', ini_get('disable_functions'))) ? 'DISABLED by hosting' : 'Available');

addResult($results, 'Config File', file_exists($configFile) ? 'pass' : 'fail',
    file_exists($configFile) ? 'Found: config/email-setup.php' : 'MISSING: config/email-setup.php');

addResult($results, 'SMTP Config', $smtpHost ? 'pass' : 'warn',
    $smtpHost ? "Host: $smtpHost, Port: $smtpPort, User: $smtpUser, Pass: " . ($smtpPass ? str_repeat('*', 8) : 'EMPTY') : 'Not configured - will use PHP mail()');

// Test 6: Try connecting to SMTP ports
foreach ([465, 587] as $port) {
    $prefix = ($port == 465) ? 'ssl://' : '';
    $errno = 0; $errstr = '';
    $conn = @fsockopen($prefix . 'smtp.gmail.com', $port, $errno, $errstr, 10);
    if ($conn) {
        $greeting = @fgets($conn, 4096);
        fclose($conn);
        addResult($results, "Connect smtp.gmail.com:$port", 'pass', "Connected OK - Greeting: " . trim($greeting));
    } else {
        addResult($results, "Connect smtp.gmail.com:$port", 'fail', "BLOCKED - $errstr ($errno)");
    }
}

// Test 7: DNS resolution
$ip = @gethostbyname('smtp.gmail.com');
if ($ip && $ip !== 'smtp.gmail.com') {
    addResult($results, 'DNS Resolution', 'pass', "smtp.gmail.com → $ip");
} else {
    addResult($results, 'DNS Resolution', 'fail', 'Cannot resolve smtp.gmail.com');
}

// Test 8: Actually send email if requested
$sendResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($testEmail)) {
    // Method 1: PHP mail()
    $from = 'noreply@' . preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: PDS Test <{$from}>\r\n";
    
    $mailResult = @mail($testEmail, 'PDS Test - PHP mail()', '<h1>Test email from PDS</h1><p>PHP mail() is working on your server!</p>', $headers);
    addResult($results, 'Send via PHP mail()', $mailResult ? 'pass' : 'fail', 
        $mailResult ? "Sent to $testEmail (check inbox + spam)" : 'Failed - ' . (error_get_last()['message'] ?? 'unknown'));

    // Method 2: SMTP via Mailer class
    $mailer = new Mailer();
    $smtpResult = $mailer->sendPasswordReset($testEmail, 'Test User', 'test_diag_token', 
        'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/reset-password.php?token=test_diag_token');
    addResult($results, 'Send via SMTP (Mailer)', $smtpResult ? 'pass' : 'fail',
        $smtpResult ? "Sent to $testEmail via SMTP (check inbox + spam)" : 'Failed - check error.log for details');
    
    $sendResult = $mailResult || $smtpResult;
}

// Check recent error log entries
$errorLogEntries = [];
if (file_exists(__DIR__ . '/error.log')) {
    $lines = file(__DIR__ . '/error.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $emailLines = array_filter($lines, function($l) { return stripos($l, 'mail') !== false || stripos($l, 'smtp') !== false; });
    $errorLogEntries = array_slice($emailLines, -10);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Email Diagnostic - PDS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f3f4f6; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; }
        h1 { color: #1f2937; margin-bottom: 20px; }
        .card { background: white; border-radius: 8px; padding: 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .card h2 { font-size: 18px; color: #374151; margin-bottom: 12px; }
        .result { display: flex; align-items: center; padding: 8px 0; border-bottom: 1px solid #f3f4f6; }
        .result:last-child { border-bottom: none; }
        .badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; min-width: 50px; text-align: center; }
        .badge.pass { background: #d1fae5; color: #065f46; }
        .badge.fail { background: #fee2e2; color: #991b1b; }
        .badge.warn { background: #fef3c7; color: #92400e; }
        .badge.info { background: #dbeafe; color: #1e40af; }
        .test-name { font-weight: 600; min-width: 200px; margin: 0 12px; }
        .detail { color: #6b7280; font-size: 14px; word-break: break-all; }
        form { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        input[type="email"] { padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; flex: 1; min-width: 200px; }
        button { padding: 8px 20px; background: #6C63FF; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; }
        button:hover { background: #5a55e3; }
        .log { background: #1f2937; color: #d1d5db; padding: 12px; border-radius: 6px; font-family: monospace; font-size: 12px; max-height: 300px; overflow-y: auto; }
        .log p { margin: 2px 0; }
        .warning { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px; border-radius: 4px; margin-bottom: 16px; font-size: 14px; }
    </style>
</head>
<body>
<div class="container">
    <h1>📧 Email Diagnostic</h1>
    
    <div class="warning">⚠️ <strong>Delete this file after testing!</strong> It exposes server configuration details.</div>

    <div class="card">
        <h2>Server Capabilities</h2>
        <?php foreach ($results as $r): ?>
        <div class="result">
            <span class="badge <?= $r['status'] ?>"><?= strtoupper($r['status']) ?></span>
            <span class="test-name"><?= htmlspecialchars($r['test']) ?></span>
            <span class="detail"><?= htmlspecialchars($r['detail']) ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <h2>Send Test Email</h2>
        <form method="POST">
            <input type="email" name="test_email" placeholder="Enter your email to test" value="<?= htmlspecialchars($testEmail) ?>" required>
            <button type="submit">Send Test</button>
        </form>
        <?php if ($sendResult !== null): ?>
        <p style="margin-top: 12px; color: <?= $sendResult ? '#065f46' : '#991b1b' ?>; font-weight: 600;">
            <?= $sendResult ? '✓ At least one method sent successfully! Check your inbox and spam folder.' : '✗ All methods failed. See log below.' ?>
        </p>
        <?php endif; ?>
    </div>

    <?php if (!empty($errorLogEntries)): ?>
    <div class="card">
        <h2>Recent Email Log Entries</h2>
        <div class="log">
            <?php foreach ($errorLogEntries as $entry): ?>
            <p><?= htmlspecialchars($entry) ?></p>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2>Recommended Fix Based on Results</h2>
        <?php
        $port465 = false; $port587 = false; $phpmail = false;
        foreach ($results as $r) {
            if (strpos($r['test'], ':465') !== false && $r['status'] === 'pass') $port465 = true;
            if (strpos($r['test'], ':587') !== false && $r['status'] === 'pass') $port587 = true;
            if ($r['test'] === 'PHP mail() function' && $r['status'] === 'pass') $phpmail = true;
        }
        ?>
        <?php if ($port465 || $port587): ?>
            <p style="color: #065f46;">✓ <strong>Gmail SMTP should work!</strong> Use port <?= $port465 ? '465 (SSL)' : '587 (STARTTLS)' ?>. The mailer will try both automatically.</p>
        <?php elseif ($phpmail): ?>
            <p style="color: #92400e;">⚠️ <strong>SMTP ports are blocked</strong> but PHP mail() is available. Remove SMTP config to use server's built-in mail. Emails will come from noreply@<?= $_SERVER['HTTP_HOST'] ?></p>
            <p style="margin-top: 8px; font-size: 14px;">To use PHP mail() only: delete or comment out all putenv() lines in config/email-setup.php</p>
        <?php else: ?>
            <p style="color: #991b1b;">✗ <strong>Both SMTP and PHP mail() are unavailable.</strong> Contact your hosting provider or use an external email API (Mailgun, SendGrid).</p>
        <?php endif; ?>
    </div>

    <p style="text-align: center; margin-top: 16px;">
        <a href="<?= url('dashboard.php') ?>" style="color: #6C63FF;">← Back to Dashboard</a>
    </p>
</div>
</body>
</html>
