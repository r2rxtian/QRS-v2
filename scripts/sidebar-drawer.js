// sidebar-drawer.js — Off-canvas sidebar toggle for narrow/minimized browser windows (<1024px)

function toggleSidebarDrawer() {
    const sidebar = document.getElementById('profileSidebar');
    const backdrop = document.querySelector('.sidebar-backdrop');
    if (!sidebar) return;

    const isOpen = sidebar.classList.contains('open');
    if (isOpen) {
        closeSidebarDrawer();
    } else {
        sidebar.classList.add('open');
        if (backdrop) backdrop.classList.add('open');
    }
}

function closeSidebarDrawer() {
    const sidebar = document.getElementById('profileSidebar');
    const backdrop = document.querySelector('.sidebar-backdrop');
    if (sidebar) sidebar.classList.remove('open');
    if (backdrop) backdrop.classList.remove('open');
}

document.addEventListener('DOMContentLoaded', function () {
    // Closing the drawer after tapping a nav link keeps the flow smooth —
    // otherwise it stays open, covering the page you just navigated to.
    document.querySelectorAll('.profile-sidebar .sidebar-link, .profile-sidebar .logout-link').forEach(link => {
        link.addEventListener('click', closeSidebarDrawer);
    });
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeSidebarDrawer();
});

// Resizing the window back past the breakpoint should drop the drawer state
// so it doesn't stay stuck open once the sidebar goes back to fixed.
window.addEventListener('resize', function () {
    if (window.innerWidth > 1024) closeSidebarDrawer();
});
