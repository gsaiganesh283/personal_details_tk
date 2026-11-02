<?php
// Buffer all output so header() redirects work even after HTML output
if (!ob_get_level()) {
    ob_start();
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

// Server-side inactivity timeout (1 hour)
$inactivityTimeout = 60 * 60; // seconds
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $inactivityTimeout) {
        // Session expired due to inactivity
        $auth = new Auth();
        $auth->logout();
        setFlash('info', 'You were logged out due to inactivity.');
        redirect('/login.php');
        exit;
    }
    $_SESSION['last_activity'] = time();
}

$auth = new Auth();
$currentUser = $auth->getCurrentUser();
$pageTitle = $pageTitle ?? APP_NAME;

// Reject any state-changing POST request that doesn't carry a valid
// CSRF token, so a third-party page can't submit these forms on a
// logged-in user's behalf.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validateCSRF($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Your session expired or the request could not be verified. Please try again.');
        redirect($_SERVER['REQUEST_URI']);
        exit;
    }
}

// Safety PIN: every Edit / Delete needs the PIN once per login session
// (see includes/safety_pin.php). Runs before the page handles its own actions.
require_once __DIR__ . '/safety_pin.php';
$spState = 'off';
if ($currentUser) {
    try {
        $spDb = Database::getInstance();
        spGuardRequest($spDb, $currentUser);
        if (spIsSet($spDb, (int)$currentUser['id'])) {
            $spState = spIsUnlocked() ? 'unlocked' : 'locked';
        }
    } catch (Exception $e) {
        // A PIN-table problem must never take the whole site down.
        $spState = 'off';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#8b83ff">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="PDS">
    <title><?php echo htmlspecialchars($pageTitle); ?> | <?php echo APP_NAME; ?></title>

    <!-- PWA -->
    <link rel="manifest" href="<?php echo url('manifest.php'); ?>">
    <link rel="apple-touch-icon" href="<?php echo url('assets/icons/apple-touch-icon-180.png'); ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo url('assets/icons/favicon-32.png'); ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo url('assets/icons/favicon-16.png'); ?>">

    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <?php
    // Based on each file's own mtime (not a shared/timed value) so an edit is
    // reflected immediately -- the service worker (service-worker.js) caches
    // /assets/*.css cache-first keyed by this exact URL, so a stale query
    // string means edits stay invisible (even through a hard reload) until
    // the string happens to change.
    $styleVersion = @filemtime(__DIR__ . '/../assets/css/style.css') ?: time();
    $responsiveVersion = @filemtime(__DIR__ . '/../assets/css/responsive.css') ?: time();
    ?>
    <link rel="stylesheet" href="<?php echo url('assets/css/style.css'); ?>?v=<?php echo $styleVersion; ?>">
    <link rel="stylesheet" href="<?php echo url('assets/css/responsive.css'); ?>?v=<?php echo $responsiveVersion; ?>">
    <!-- Theme v2 design layer (fonts, colours, message windows) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="<?php echo url('assets/css/theme-v2.css'); ?>?v=<?php echo @filemtime(__DIR__ . '/../assets/css/theme-v2.css') ?: time(); ?>">
    <script>
        // Apply saved theme + accent before first paint (no light/dark flash).
        (function () {
            try {
                var m = localStorage.getItem('theme') || 'dark';
                if (m === 'auto') m = window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
                document.documentElement.setAttribute('data-theme', m);
                var a = localStorage.getItem('accent');
                if (a && a !== 'violet') document.documentElement.setAttribute('data-accent', a);
            } catch (e) {}
        })();
    </script>
    <?php if (isset($extraCSS)): foreach ($extraCSS as $css): ?>
        <link rel="stylesheet" href="<?php echo $css; ?>">
    <?php endforeach; endif; ?>
    
    <!-- Set BASE_PATH for JavaScript -->
    <script>
        window.BASE_PATH = '<?php echo defined('BASE_PATH') ? BASE_PATH : ''; ?>';
    </script>
    <script>
        (function updateSidebarClock() {
            var days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
            var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            function tick() {
                var now = new Date();
                var dayEl = document.getElementById('sidebarDay');
                var dateEl = document.getElementById('sidebarDate');
                var timeEl = document.getElementById('sidebarTime');
                if (dayEl) dayEl.textContent = days[now.getDay()];
                if (dateEl) dateEl.textContent = now.getDate() + ' ' + months[now.getMonth()] + ' ' + now.getFullYear();
                if (timeEl) {
                    var h = now.getHours(), m = now.getMinutes(), s = now.getSeconds();
                    var ampm = h >= 12 ? 'PM' : 'AM';
                    h = h % 12 || 12;
                    timeEl.textContent = (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s + ' ' + ampm;
                }
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() { tick(); setInterval(tick, 1000); });
            } else { tick(); setInterval(tick, 1000); }
        })();
    </script>
</head>
<body class="<?php echo isset($bodyClass) ? $bodyClass : ''; ?>" data-safety-pin="<?php echo $spState; ?>"
      data-csrf="<?php echo htmlspecialchars(Auth::generateCSRF()); ?>">

<?php if ($currentUser): ?>
<!-- Sidebar Overlay for Mobile -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="this.classList.remove('active');document.getElementById('sidebar').classList.remove('open');"></div>

<!-- Sidebar Navigation -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">
            <div class="logo-icon">
                <i class="fas fa-user-shield"></i>
            </div>
            <span class="logo-text">PDS</span>
        </div>
        <button class="sidebar-toggle" id="sidebarToggle">
            <i class="fas fa-bars"></i>
        </button>
    </div>

    <nav class="sidebar-nav">
        <ul class="nav-list">
            <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : ''; ?>">
                <a href="<?php echo url('dashboard.php'); ?>" class="nav-link">
                    <i class="fas fa-th-large"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'personal-details.php' ? 'active' : ''; ?>">
                <a href="<?php echo url('personal-details.php'); ?>" class="nav-link">
                    <i class="fas fa-address-card"></i>
                    <span>Personal Details</span>
                </a>
            </li>
            <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'bank-details.php' ? 'active' : ''; ?>">
                <a href="<?php echo url('bank-details.php'); ?>" class="nav-link">
                    <i class="fas fa-university"></i>
                    <span>Bank Details</span>
                </a>
            </li>
            <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'documents.php' ? 'active' : ''; ?>">
                <a href="<?php echo url('documents.php'); ?>" class="nav-link">
                    <i class="fas fa-file-pdf"></i>
                    <span>Documents</span>
                </a>
            </li>
            <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'aadhaar-details.php' ? 'active' : ''; ?>">
                <a href="<?php echo url('aadhaar-details.php'); ?>" class="nav-link">
                    <i class="fas fa-fingerprint"></i>
                    <span>Aadhaar Details</span>
                </a>
            </li>
            <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'pan-details.php' ? 'active' : ''; ?>">
                <a href="<?php echo url('pan-details.php'); ?>" class="nav-link">
                    <i class="fas fa-credit-card"></i>
                    <span>PAN Details</span>
                </a>
            </li>
            <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'other-details.php' ? 'active' : ''; ?>">
                <a href="<?php echo url('other-details.php'); ?>" class="nav-link">
                    <i class="fas fa-box-open"></i>
                    <span>Other Details</span>
                </a>
            </li>
            <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'contacts.php' ? 'active' : ''; ?>">
                <a href="<?php echo url('contacts.php'); ?>" class="nav-link">
                    <i class="fas fa-address-book"></i>
                    <span>Contacts</span>
                </a>
            </li>
            <li class="nav-item <?php echo in_array(basename($_SERVER['PHP_SELF']), ['savingsmanage.php', 'savings-dashboard.php', 'savings-tracker-advanced.php', 'savings-ledger.php']) ? 'active' : ''; ?>">
                <a href="<?php echo url('savingsmanage.php'); ?>" class="nav-link">
                    <i class="fas fa-piggy-bank"></i>
                    <span>Savings Tracker</span>
                </a>
            </li>
            <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'custom-fields.php' ? 'active' : ''; ?>">
                <a href="<?php echo url('custom-fields.php'); ?>" class="nav-link">
                    <i class="fas fa-puzzle-piece"></i>
                    <span>Custom Fields</span>
                </a>
            </li>
            <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'field-blocks.php' ? 'active' : ''; ?>">
                <a href="<?php echo url('field-blocks.php'); ?>" class="nav-link">
                    <i class="fas fa-th-large"></i>
                    <span>Field Blocks</span>
                </a>
            </li>
            <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'button-mapping.php' ? 'active' : ''; ?>">
                <a href="<?php echo url('button-mapping.php'); ?>" class="nav-link">
                    <i class="fas fa-sitemap"></i>
                    <span>Mapping</span>
                </a>
            </li>
            <li class="nav-item <?php echo in_array(basename($_SERVER['PHP_SELF']), ['notifications.php', 'notification-templates.php', 'reminders.php']) ? 'active' : ''; ?>">
                <a href="<?php echo url('notifications.php'); ?>" class="nav-link">
                    <i class="fas fa-bell"></i>
                    <span>Notifications</span>
                </a>
            </li>
            <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'activity-log.php' ? 'active' : ''; ?>">
                <a href="<?php echo url('activity-log.php'); ?>" class="nav-link">
                    <i class="fas fa-history"></i>
                    <span>Activity Log</span>
                </a>
            </li>
            <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'profile.php' ? 'active' : ''; ?>">
                <a href="<?php echo url('profile.php'); ?>" class="nav-link">
                    <i class="fas fa-user-cog"></i>
                    <span>Profile</span>
                </a>
            </li>
        </ul>
    </nav>

    <div class="sidebar-footer">
        <div class="user-card">
            <div class="user-avatar" style="background: <?php echo randomColor(); ?>">
                <?php echo getInitials($currentUser['full_name']); ?>
            </div>
            <div class="user-info">
                <span class="user-name"><?php echo htmlspecialchars($currentUser['full_name']); ?></span>
                <span class="user-role"><?php echo ucfirst($currentUser['role']); ?></span>
            </div>
            <a href="<?php echo url('logout.php'); ?>" class="logout-btn" title="Logout">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </div>
</aside>

<!-- Mobile Bottom Nav -- full parity with the sidebar (scrolls horizontally) -->
<nav class="mobile-nav" id="mobileNav">
    <a href="<?php echo url('dashboard.php'); ?>" class="mobile-nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : ''; ?>">
        <i class="fas fa-th-large"></i>
        <span>Home</span>
    </a>
    <a href="<?php echo url('personal-details.php'); ?>" class="mobile-nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'personal-details.php' ? 'active' : ''; ?>">
        <i class="fas fa-address-card"></i>
        <span>Details</span>
    </a>
    <a href="<?php echo url('bank-details.php'); ?>" class="mobile-nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'bank-details.php' ? 'active' : ''; ?>">
        <i class="fas fa-university"></i>
        <span>Bank</span>
    </a>
    <a href="<?php echo url('documents.php'); ?>" class="mobile-nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'documents.php' ? 'active' : ''; ?>">
        <i class="fas fa-file-pdf"></i>
        <span>Docs</span>
    </a>
    <a href="<?php echo url('aadhaar-details.php'); ?>" class="mobile-nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'aadhaar-details.php' ? 'active' : ''; ?>">
        <i class="fas fa-fingerprint"></i>
        <span>Aadhaar</span>
    </a>
    <a href="<?php echo url('pan-details.php'); ?>" class="mobile-nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'pan-details.php' ? 'active' : ''; ?>">
        <i class="fas fa-credit-card"></i>
        <span>PAN</span>
    </a>
    <a href="<?php echo url('other-details.php'); ?>" class="mobile-nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'other-details.php' ? 'active' : ''; ?>">
        <i class="fas fa-box-open"></i>
        <span>Other</span>
    </a>
    <a href="<?php echo url('contacts.php'); ?>" class="mobile-nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'contacts.php' ? 'active' : ''; ?>">
        <i class="fas fa-address-book"></i>
        <span>Contacts</span>
    </a>
    <a href="<?php echo url('savingsmanage.php'); ?>" class="mobile-nav-item <?php echo in_array(basename($_SERVER['PHP_SELF']), ['savingsmanage.php', 'savings-dashboard.php', 'savings-tracker-advanced.php', 'savings-ledger.php']) ? 'active' : ''; ?>">
        <i class="fas fa-piggy-bank"></i>
        <span>Savings</span>
    </a>
    <a href="<?php echo url('custom-fields.php'); ?>" class="mobile-nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'custom-fields.php' ? 'active' : ''; ?>">
        <i class="fas fa-puzzle-piece"></i>
        <span>Fields</span>
    </a>
    <a href="<?php echo url('field-blocks.php'); ?>" class="mobile-nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'field-blocks.php' ? 'active' : ''; ?>">
        <i class="fas fa-th-large"></i>
        <span>Blocks</span>
    </a>
    <a href="<?php echo url('notifications.php'); ?>" class="mobile-nav-item <?php echo in_array(basename($_SERVER['PHP_SELF']), ['notifications.php', 'notification-templates.php', 'reminders.php']) ? 'active' : ''; ?>">
        <i class="fas fa-bell"></i>
        <span>Notify</span>
    </a>
    <a href="<?php echo url('activity-log.php'); ?>" class="mobile-nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'activity-log.php' ? 'active' : ''; ?>">
        <i class="fas fa-history"></i>
        <span>Activity</span>
    </a>
    <a href="<?php echo url('profile.php'); ?>" class="mobile-nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'profile.php' ? 'active' : ''; ?>">
        <i class="fas fa-user"></i>
        <span>Profile</span>
    </a>
</nav>

<!-- Main Content Wrapper -->
<main class="main-content" id="mainContent">
    <!-- Top Bar -->
    <header class="topbar">
        <button class="menu-toggle" id="menuToggle" onclick="var s=document.getElementById('sidebar'),o=document.getElementById('sidebarOverlay');s.classList.toggle('open');if(o)o.classList.toggle('active',s.classList.contains('open'));">
            <i class="fas fa-bars"></i>
        </button>
        <div class="topbar-title">
            <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
        </div>
        <div class="topbar-actions">
            <div class="topbar-datetime" id="topbarDateTime">
                <span id="sidebarDay" class="topbar-day"></span>
                <span id="sidebarDate" class="topbar-date"></span>
                <span id="sidebarTime" class="topbar-time"></span>
            </div>
            <div class="search-box" id="searchBox">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Search..." id="globalSearch" autocomplete="off">
                <div class="search-results-dropdown" id="searchResultsDropdown">
                    <div class="search-results-list" id="searchResultsList"></div>
                </div>
            </div>
            <div class="notification-bell" id="notificationBell">
                <i class="fas fa-bell"></i>
                <span class="notification-badge" id="notificationBadge" style="display:none;">0</span>
                <div class="notification-dropdown" id="notificationDropdown">
                    <div class="notification-dropdown-header">
                        <h4>Notifications</h4>
                        <button class="mark-read-btn" id="markAllRead">Mark all read</button>
                    </div>
                    <div class="notification-list" id="notificationList">
                        <div class="notification-empty">
                            <i class="fas fa-bell-slash"></i>
                            <p>No notifications yet</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div class="content-area">
        <?php displayFlash(); ?>
<?php endif; ?>
