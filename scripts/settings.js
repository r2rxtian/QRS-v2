// settings.js — Accent color picker interactions (theme.js does the actual re-theming)

function showModal(id) {
    document.getElementById(id).classList.add('active');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

function showMessage(message, type = 'info') {
    const titleEl = document.getElementById('msgTitle');
    const bodyEl = document.getElementById('msgBody');
    titleEl.textContent = type === 'success' ? 'Success' : type === 'error' ? 'Error' : 'Notification';
    bodyEl.textContent = message;
    showModal('messageModal');
}

function toggleDarkMode(checkbox) {
    saveDarkMode(checkbox.checked);
}

function markSelectedSwatch(color) {
    document.querySelectorAll('.accent-swatch').forEach(btn => {
        btn.classList.toggle('selected', btn.dataset.color.toLowerCase() === color.toLowerCase());
    });
}

function selectAccentSwatch(button) {
    const color = button.dataset.color;
    markSelectedSwatch(color);
    saveAccentColor(color);
}

function resetAccentColor() {
    markSelectedSwatch(DEFAULT_ACCENT);
    saveAccentColor(DEFAULT_ACCENT);
}

document.addEventListener('DOMContentLoaded', function () {
    markSelectedSwatch(getSavedAccentColor());
    document.querySelectorAll('.accent-swatch').forEach(btn => {
        btn.addEventListener('click', () => selectAccentSwatch(btn));
    });

    const darkToggle = document.getElementById('darkModeToggle');
    if (darkToggle) darkToggle.checked = isDarkMode();
});
