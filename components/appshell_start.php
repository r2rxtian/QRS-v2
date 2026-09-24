<?php
/**
 * Renovated app-shell chrome (sidebar + persistent appbar + main-content opener).
 * Used only by the renovated list/table pages (tasks, task_list, qradmin,
 * manage_locations, task_report, task_locations, scan, settings).
 *
 * Requires the including page to have already called requireLogin() before
 * any HTML output (this file runs after <body>, too late to redirect) —
 * every page that includes this file does so at the very top, before the
 * DOCTYPE. Include this file itself after the page's own PHP logic, right
 * after <body>.
 */
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../authz/capabilities.php';
require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../rules/constants.php';
require_once __DIR__ . '/../rules/status.php';

// Lazily auto-unassigns any location whose ticket resolved (Completed or
// Missed Out) since the last sweep -- see sweepResolvedLocations(). Every
// appshell load attempts it, while a database-backed claim limits actual
// execution to once per 30 seconds across all PHP processes.
sweepResolvedLocations(db());

$shellUser = currentUser();
$currentPage = basename($_SERVER['PHP_SELF']);
// Only Scan QR uses HTTPS on the LAN. Its links to other pages should return
// to the site's normal HTTP origin instead of inheriting the current scheme.
$scanHttpPageBase = '';
if ($currentPage === 'scan.php' && in_array(strtolower($_SERVER['HTTP_HOST'] ?? ''), ['10.2.0.8', '10.2.0.8:443'], true)) {
    $scanHttpPageBase = 'http://10.2.0.8' . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/QRTS/pages/scan.php'), '/\\') . '/';
}
// Admin gets Task Manager (create/manage); User gets All Tasks (read-only
// browse, routed into scan.php) -- one task-list page per role, not both,
// since tasks.php/qradmin.php each redirect the other role away.
$mainNavItems = [
    ['label' => 'Dashboard', 'icon' => 'fa-house', 'href' => 'dashboard.php'],
    $shellUser['role_name'] === ROLE_ADMIN
        ? ['label' => 'Task Manager', 'icon' => 'fa-clipboard-list', 'href' => 'tasks.php']
        : ['label' => 'All Tasks', 'icon' => 'fa-layer-group', 'href' => 'qradmin.php'],
    $shellUser['role_name'] === ROLE_ADMIN
        ? ['label' => 'Manage Locations', 'icon' => 'fa-location-dot', 'href' => 'manage_locations.php']
        : ['label' => 'Locations', 'icon' => 'fa-location-dot', 'href' => 'manage_locations.php'],
    ['label' => 'Scan QR', 'icon' => 'fa-qrcode', 'href' => 'scan.php'],
    ['label' => 'Task Report', 'icon' => 'fa-file-lines', 'href' => 'task_report.php'],
];

$avatarInitials = $shellUser['avatar_initials'] ?: strtoupper(substr($shellUser['full_name'], 0, 1));
$avatarFallbackUrl = initialsAvatarUrl($avatarInitials, $shellUser['avatar_color'], '38');
$avatarPhotoUrl = employeePhotoUrl($shellUser['employee_id']);
?>
<div class="app-shell">
    <aside class="icon-rail"></aside>

    <div class="sidebar-backdrop" onclick="closeSidebarDrawer()"></div>

    <aside class="profile-sidebar" id="profileSidebar">
        <div class="brand-lockup">
            <div class="brand-lockup-mark"><i class="fas fa-qrcode"></i></div>
            <div class="brand-lockup-name">QR Task Check</div>
        </div>

        <div class="sidebar-nav-scroll">
            <div class="nav-section-label">Main Menu</div>
            <nav class="sidebar-nav">
                <?php foreach ($mainNavItems as $item): ?>
                    <a href="<?= htmlspecialchars($scanHttpPageBase !== '' && $item['href'] !== 'scan.php' ? $scanHttpPageBase . $item['href'] : $item['href']) ?>" class="sidebar-link<?= $currentPage === $item['href'] ? ' active' : '' ?>" title="<?= htmlspecialchars($item['label']) ?>" aria-label="<?= htmlspecialchars($item['label']) ?>">
                        <i class="fas <?= htmlspecialchars($item['icon']) ?>"></i> <?= htmlspecialchars($item['label']) ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php if ($shellUser['role_name'] === ROLE_ADMIN): ?>
                <div class="nav-section-label">General</div>
                <nav class="sidebar-nav">
                    <a href="<?= htmlspecialchars($scanHttpPageBase . 'user_management.php') ?>" class="sidebar-link<?= $currentPage === 'user_management.php' ? ' active' : '' ?>" title="User Management" aria-label="User Management">
                        <i class="fas fa-users-gear"></i> User Management
                    </a>
                    <a href="<?= htmlspecialchars($scanHttpPageBase . 'audit_logs.php') ?>" class="sidebar-link<?= $currentPage === 'audit_logs.php' ? ' active' : '' ?>" title="Audit Logs" aria-label="Audit Logs">
                        <i class="fas fa-clock-rotate-left"></i> Audit Logs
                    </a>
                </nav>
            <?php endif; ?>
        </div>

        <div class="sidebar-footer-card">
            <div class="sidebar-footer-logo">
                <img src="../assets/images/logofooter.png" alt="Powered by Information Technology">
            </div>

            <div class="sidebar-theme-row">
                <span class="sidebar-theme-label"><i class="fas fa-moon"></i> Dark mode</span>
                <label class="theme-switch" aria-label="Toggle dark mode">
                    <input type="checkbox" id="darkModeToggle" class="theme-switch__checkbox" onchange="toggleDarkMode(this)">
                    <span class="theme-switch__container">
                        <span class="theme-switch__clouds"></span>
                        <span class="theme-switch__stars-container" aria-hidden="true">
                            <svg viewBox="0 0 144 55" fill="none" focusable="false">
                                <path fill-rule="evenodd" clip-rule="evenodd" d="M135.831 3.00688C135.055 3.85027 134.111 4.29946 133 4.35447C134.111 4.40947 135.055 4.85867 135.831 5.71123C136.607 6.55462 136.996 7.56303 136.996 8.72727C136.996 7.95722 137.172 7.25134 137.525 6.59129C137.886 5.93124 138.372 5.39954 138.98 5.00535C139.598 4.60199 140.268 4.39114 141 4.35447C139.88 4.2903 138.936 3.85027 138.16 3.00688C137.384 2.16348 136.996 1.16425 136.996 0C136.996 1.16425 136.607 2.16348 135.831 3.00688ZM31 23.3545C32.1114 23.2995 33.0551 22.8503 33.8313 22.0069C34.6075 21.1635 34.9956 20.1642 34.9956 19C34.9956 20.1642 35.3837 21.1635 36.1599 22.0069C36.9361 22.8503 37.8798 23.2903 39 23.3545C38.2679 23.3911 37.5976 23.602 36.9802 24.0053C36.3716 24.3995 35.8864 24.9312 35.5248 25.5913C35.172 26.2513 34.9956 26.9572 34.9956 27.7273C34.9956 26.563 34.6075 25.5546 33.8313 24.7112C33.0551 23.8587 32.1114 23.4095 31 23.3545ZM0 36.3545C1.11136 36.2995 2.05513 35.8503 2.83131 35.0069C3.6075 34.1635 3.99559 33.1642 3.99559 32C3.99559 33.1642 4.38368 34.1635 5.15987 35.0069C5.93605 35.8503 6.87982 36.2903 8 36.3545C7.26792 36.3911 6.59757 36.602 5.98015 37.0053C5.37155 37.3995 4.88644 37.9312 4.52481 38.5913C4.172 39.2513 3.99559 39.9572 3.99559 40.7273C3.99559 39.563 3.6075 38.5546 2.83131 37.7112C2.05513 36.8587 1.11136 36.4095 0 36.3545ZM56.8313 24.0069C56.0551 24.8503 55.1114 25.2995 54 25.3545C55.1114 25.4095 56.0551 25.8587 56.8313 26.7112C57.6075 27.5546 57.9956 28.563 57.9956 29.7273C57.9956 28.9572 58.172 28.2513 58.5248 27.5913C58.8864 26.9312 59.3716 26.3995 59.9802 26.0053C60.5976 25.602 61.2679 25.3911 62 25.3545C60.8798 25.2903 59.9361 24.8503 59.1599 24.0069C58.3837 23.1635 57.9956 22.1642 57.9956 21C57.9956 22.1642 57.6075 23.1635 56.8313 24.0069ZM81 25.3545C82.1114 25.2995 83.0551 24.8503 83.8313 24.0069C84.6075 23.1635 84.9956 22.1642 84.9956 21C84.9956 22.1642 85.3837 23.1635 86.1599 24.0069C86.9361 24.8503 87.8798 25.2903 89 25.3545C88.2679 25.3911 87.5976 25.602 86.9802 26.0053C86.3716 26.3995 85.8864 26.9312 85.5248 27.5913C85.172 28.2513 84.9956 28.9572 84.9956 29.7273C84.9956 28.563 84.6075 27.5546 83.8313 26.7112C83.0551 25.8587 82.1114 25.4095 81 25.3545ZM136 36.3545C137.111 36.2995 138.055 35.8503 138.831 35.0069C139.607 34.1635 139.996 33.1642 139.996 32C139.996 33.1642 140.384 34.1635 141.16 35.0069C141.936 35.8503 142.88 36.2903 144 36.3545C143.268 36.3911 142.598 36.602 141.98 37.0053C141.372 37.3995 140.886 37.9312 140.525 38.5913C140.172 39.2513 139.996 39.9572 139.996 40.7273C139.996 39.563 139.607 38.5546 138.831 37.7112C138.055 36.8587 137.111 36.4095 136 36.3545ZM101.831 49.0069C101.055 49.8503 100.111 50.2995 99 50.3545C100.111 50.4095 101.055 50.8587 101.831 51.7112C102.607 52.5546 102.996 53.563 102.996 54.7273C102.996 53.9572 103.172 53.2513 103.525 52.5913C103.886 51.9312 104.372 51.3995 104.98 51.00535C105.598 50.602 106.268 50.3911 107 50.3545C105.88 50.2903 104.936 49.8503 104.16 49.0069C103.384 48.1635 102.996 47.1642 102.996 46C102.996 47.1642 102.607 48.1635 101.831 49.0069Z" fill="currentColor"></path>
                            </svg>
                        </span>
                        <span class="theme-switch__circle-container">
                            <span class="theme-switch__sun-moon-container">
                                <span class="theme-switch__moon">
                                    <span class="theme-switch__spot"></span>
                                    <span class="theme-switch__spot"></span>
                                    <span class="theme-switch__spot"></span>
                                </span>
                            </span>
                        </span>
                    </span>
                </label>
            </div>

            <div class="topbar-user">
                <div class="topbar-user-avatar-wrap">
                    <img src="<?= htmlspecialchars($avatarPhotoUrl ?? $avatarFallbackUrl) ?>" alt="User avatar" onerror="this.onerror=null;this.src=<?= htmlspecialchars(json_encode($avatarFallbackUrl), ENT_QUOTES) ?>;">
                    <span class="user-status-dot"></span>
                </div>
                <div class="topbar-user-info">
                    <div class="topbar-user-name"><?= htmlspecialchars($shellUser['full_name']) ?></div>
                    <div class="topbar-user-role-badge"><i class="fas fa-shield"></i> <?= htmlspecialchars($shellUser['role_name']) ?></div>
                </div>
            </div>

            <a href="../auth/logout.php" class="logout-link" title="Logout" aria-label="Logout">
                <i class="fas fa-right-from-bracket"></i>
                <span class="logout-divider"></span>
                Logout
            </a>
        </div>
    </aside>

    <main class="main-content">
        <button type="button" class="sidebar-toggle" onclick="toggleSidebarDrawer()" aria-label="Toggle navigation">
            <i class="fas fa-bars"></i>
        </button>
