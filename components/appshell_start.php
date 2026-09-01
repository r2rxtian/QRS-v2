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
// Missed Out) since the last page load -- see sweepResolvedLocations()'s
// own docblock. Runs once per page load, on every appshell page, so the
// first person to load anything after a ticket resolves is what triggers it.
sweepResolvedLocations(db());

$shellUser = currentUser();
$currentPage = basename($_SERVER['PHP_SELF']);
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
                    <a href="<?= htmlspecialchars($item['href']) ?>" class="sidebar-link<?= $currentPage === $item['href'] ? ' active' : '' ?>">
                        <i class="fas <?= htmlspecialchars($item['icon']) ?>"></i> <?= htmlspecialchars($item['label']) ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="nav-section-label">General</div>
            <nav class="sidebar-nav">
                <?php if ($shellUser['role_name'] === ROLE_ADMIN): ?>
                    <a href="user_management.php" class="sidebar-link<?= $currentPage === 'user_management.php' ? ' active' : '' ?>">
                        <i class="fas fa-users-gear"></i> User Management
                    </a>
                    <a href="audit_logs.php" class="sidebar-link<?= $currentPage === 'audit_logs.php' ? ' active' : '' ?>">
                        <i class="fas fa-clock-rotate-left"></i> Audit Logs
                    </a>
                <?php endif; ?>
            </nav>
        </div>

        <div class="sidebar-footer-card">
            <div class="sidebar-footer-logo">
                <img src="../assets/images/logofooter.png" alt="Powered by Information Technology">
            </div>

            <div class="sidebar-theme-row">
                <span class="sidebar-theme-label"><i class="fas fa-moon"></i> Dark mode</span>
                <label class="toggle-switch" aria-label="Toggle dark mode">
                    <input type="checkbox" id="darkModeToggle" onchange="toggleDarkMode(this)">
                    <span class="toggle-slider"></span>
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

            <a href="../auth/logout.php" class="logout-link">
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
