// theme.js — Accent color + dark mode theming (mockup-only, persisted via
// localStorage). Loaded early in <head> on every page so saved preferences
// apply before first paint, no backend involved.

const ACCENT_STORAGE_KEY = 'qrtc-accent-color';
const DARK_MODE_STORAGE_KEY = 'qrtc-dark-mode';
const DEFAULT_ACCENT = '#A7ACD9';

function hexToRgb(hex) {
    const clean = hex.replace('#', '');
    const num = parseInt(clean, 16);
    return { r: (num >> 16) & 255, g: (num >> 8) & 255, b: num & 255 };
}

function rgbToHex(r, g, b) {
    return '#' + [r, g, b].map(v => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, '0')).join('');
}

// Blends `hex` toward `targetHex` by `amount` (0-1) — used to derive a darker
// shade (gradient end / hover) or a light tint (soft backgrounds) from one accent.
function mix(hex, targetHex, amount) {
    const c1 = hexToRgb(hex);
    const c2 = hexToRgb(targetHex);
    return rgbToHex(
        c1.r + (c2.r - c1.r) * amount,
        c1.g + (c2.g - c1.g) * amount,
        c1.b + (c2.b - c1.b) * amount
    );
}

function isDarkMode() {
    return localStorage.getItem(DARK_MODE_STORAGE_KEY) === 'true';
}

function applyDarkMode(enabled) {
    document.documentElement.setAttribute('data-theme', enabled ? 'dark' : 'light');
}

function saveDarkMode(enabled) {
    localStorage.setItem(DARK_MODE_STORAGE_KEY, String(enabled));
    applyDarkMode(enabled);
    // --primary-light is a light-background tint — what it should tint *toward*
    // depends on the theme, so it has to be recomputed whenever dark mode flips.
    applyAccentColor(getSavedAccentColor());
}

// Every gradient/badge in the app is built from --periwinkle -> --dusty-purple,
// so re-theming just needs to redefine those two custom properties (plus the
// hover/light shades derived from them) — everything downstream already
// references var(--periwinkle) / var(--primary) rather than hardcoded hex.
function applyAccentColor(hex) {
    const root = document.documentElement.style;
    const dark = isDarkMode();
    const darker = mix(hex, '#000000', dark ? 0.12 : 0.2);
    // In dark mode a white-tinted "light" chip would glow — tint toward the
    // dark surface color instead so badges/active states still read as "soft".
    const light = dark ? mix(hex, '#1E212A', 0.78) : mix(hex, '#ffffff', 0.9);

    root.setProperty('--periwinkle', hex);
    root.setProperty('--dusty-purple', darker);
    root.setProperty('--primary-hover', darker);
    root.setProperty('--primary-light', light);
}

function saveAccentColor(hex) {
    localStorage.setItem(ACCENT_STORAGE_KEY, hex);
    applyAccentColor(hex);
}

function getSavedAccentColor() {
    return localStorage.getItem(ACCENT_STORAGE_KEY) || DEFAULT_ACCENT;
}

applyDarkMode(isDarkMode());
applyAccentColor(getSavedAccentColor());
