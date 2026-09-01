// theme.js - Global light/dark appearance, persisted locally and applied
// before first paint. The application uses one fixed calm-green brand theme.

const DARK_MODE_STORAGE_KEY = 'qrtc-dark-mode';

function isDarkMode() {
    return localStorage.getItem(DARK_MODE_STORAGE_KEY) === 'true';
}

function applyDarkMode(enabled) {
    document.documentElement.setAttribute('data-theme', enabled ? 'dark' : 'light');
}

function saveDarkMode(enabled) {
    localStorage.setItem(DARK_MODE_STORAGE_KEY, String(enabled));
    applyDarkMode(enabled);
}

function toggleDarkMode(checkbox) {
    saveDarkMode(checkbox.checked);
}

applyDarkMode(isDarkMode());

document.addEventListener('DOMContentLoaded', function () {
    const darkToggle = document.getElementById('darkModeToggle');
    if (darkToggle) darkToggle.checked = isDarkMode();
});
