<?php
require_once __DIR__ . '/../auth/session.php';
$currentUser = requireLogin();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings — QR Task Check</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/app.css">
    <link rel="stylesheet" href="../styles/settings.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    <script src="../scripts/theme.js?v=2"></script>
</head>

<body>
    <?php include '../components/appshell_start.php'; ?>

    <div class="topbar">
        <div class="topbar-title">
            <h1>Settings</h1>
            <p>Personalize how QR Task Check looks for you.</p>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h2><i class="fas fa-moon"></i> Dark Mode</h2>
                <p style="margin: 2px 0 0; font-size: 13px; color: var(--gray-500);">Switch between a light and dark appearance</p>
            </div>
            <label class="toggle-switch">
                <input type="checkbox" id="darkModeToggle" onchange="toggleDarkMode(this)">
                <span class="toggle-slider"></span>
            </label>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h2><i class="fas fa-palette"></i> Accent Color</h2>
                <p style="margin: 2px 0 0; font-size: 13px; color: var(--gray-500);">Used for buttons, active states, and highlights across the app</p>
            </div>
        </div>
        <div class="card-body">
            <div class="accent-swatch-grid" id="accentSwatchGrid">
                <button type="button" class="accent-swatch" data-color="#A7ACD9" style="background:#A7ACD9" aria-label="Periwinkle"></button>
                <button type="button" class="accent-swatch" data-color="#C98A94" style="background:#C98A94" aria-label="Dusty Rose"></button>
                <button type="button" class="accent-swatch" data-color="#81A684" style="background:#81A684" aria-label="Sage Green"></button>
                <button type="button" class="accent-swatch" data-color="#6FA8AB" style="background:#6FA8AB" aria-label="Soft Teal"></button>
                <button type="button" class="accent-swatch" data-color="#C4A468" style="background:#C4A468" aria-label="Muted Amber"></button>
                <button type="button" class="accent-swatch" data-color="#7C93B8" style="background:#7C93B8" aria-label="Slate Blue"></button>
                <button type="button" class="accent-swatch" data-color="#BC7A6B" style="background:#BC7A6B" aria-label="Terracotta"></button>
                <button type="button" class="accent-swatch" data-color="#A08966" style="background:#A08966" aria-label="Warm Taupe"></button>
                <button type="button" class="accent-swatch" data-color="#5F7A63" style="background:#5F7A63" aria-label="Forest Green"></button>
                <button type="button" class="accent-swatch" data-color="#B8AEDB" style="background:#B8AEDB" aria-label="Lavender"></button>
            </div>

            <div class="settings-actions">
                <button type="button" class="btn btn-secondary" onclick="resetAccentColor()">Reset to Default</button>
            </div>
        </div>
    </div>

    <?php include '../components/appshell_end.php'; ?>

    <!-- Message Modal -->
    <div id="messageModal" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title" id="msgTitle">Notification</h3>
                <button type="button" class="modal-close" onclick="closeModal('messageModal')">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div class="modal-body" id="msgBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('messageModal')">Close</button>
            </div>
        </div>
    </div>

    <script src="../scripts/sidebar-drawer.js"></script>
    <script src="../scripts/settings.js"></script>
</body>

</html>
