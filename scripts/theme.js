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

// ---- Accessibility popover (sidebar "General" section, every appshell
// page -- markup lives in components/appshell_start.php) ----

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

function toggleAccessibilityPanel(button) {
    const panel = document.getElementById('accessibilityPanel');
    const isOpen = panel.classList.contains('open');

    if (isOpen) {
        panel.classList.remove('open');
        return;
    }

    // Move the panel to a direct child of <body> before showing it.
    // .profile-sidebar gets a real `transform` value applied in its
    // off-canvas-drawer breakpoint (max-width: 1024px) -- translateX(-100%)
    // closed, translateX(0) open, and even translateX(0) counts as "a
    // transform" for this purpose -- which makes the sidebar itself become
    // the containing block for any position:fixed descendant instead of
    // the viewport. That silently broke this panel's on-screen math below
    // whenever the sidebar was in drawer mode, since window.innerWidth/
    // innerHeight assume position:fixed is viewport-relative. Body itself
    // never gets a transform, so parenting there sidesteps the issue
    // entirely rather than trying to detect/compensate for it.
    if (panel.parentElement !== document.body) {
        document.body.appendChild(panel);
    }
    panel.classList.add('open');
    positionAccessibilityPanel(button, panel);
}

// .accessibility-panel is position:fixed (see app.css for why), so its
// left/top/bottom have to be computed here against the trigger's actual
// on-screen position instead of relying on a CSS anchor -- recomputed on
// every open, so window resizes between opens are handled for free.
function positionAccessibilityPanel(trigger, panel) {
    const rect = trigger.getBoundingClientRect();
    const panelWidth = panel.offsetWidth || 280;
    const gap = 12;
    const fitsRight = rect.right + gap + panelWidth <= window.innerWidth;

    if (fitsRight) {
        panel.style.left = (rect.right + gap) + 'px';
        panel.style.top = 'auto';
        panel.style.bottom = Math.max(8, window.innerHeight - rect.bottom) + 'px';
    } else {
        // Not enough room to the right (narrow window) -- drop it below the
        // trigger instead, clamped so it never runs off the left edge.
        panel.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - panelWidth - 8)) + 'px';
        panel.style.bottom = 'auto';
        panel.style.top = (rect.bottom + 8) + 'px';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    markSelectedSwatch(getSavedAccentColor());
    document.querySelectorAll('.accent-swatch').forEach(btn => {
        btn.addEventListener('click', () => selectAccentSwatch(btn));
    });

    const darkToggle = document.getElementById('darkModeToggle');
    if (darkToggle) darkToggle.checked = isDarkMode();
});

document.addEventListener('click', function (e) {
    // The panel itself may no longer be inside .accessibility-wrap by the
    // time this fires (see toggleAccessibilityPanel()'s move-to-<body>
    // comment), so a click inside it has to be checked separately from a
    // click on the trigger, not caught by a single closest('.accessibility-wrap').
    if (e.target.closest('.accessibility-trigger') || e.target.closest('#accessibilityPanel')) {
        return;
    }
    const panel = document.getElementById('accessibilityPanel');
    if (panel) panel.classList.remove('open');
});
