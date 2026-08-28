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

let _accessibilityOutsideClickBound = false;

function toggleAccessibilityPanel(button) {
    const panel = document.getElementById('accessibilityPanel');
    const isOpen = panel.classList.contains('open');

    if (isOpen) {
        closeAccessibilityPanel();
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
    // Positioned BEFORE .open is added, not after -- .open is what starts
    // the opacity/transform transition (see app.css), so the very first
    // painted frame needs to already be at the final coordinates. Doing it
    // the other way round would animate in from wherever the panel's
    // stale left/top happened to be left over from its last opening (or
    // its default position, the first time), a visible jump on top of the
    // intended fade+scale.
    positionAccessibilityPanel(button, panel);
    panel.classList.add('open');
    panel._trigger = button;

    // Bound once globally rather than per-open/close -- window resize can
    // happen while the panel is open just as easily as while it's closed,
    // and re-running the same collision math on resize is what keeps it
    // from drifting off-screen if the window shrinks under it.
    if (!_accessibilityOutsideClickBound) {
        _accessibilityOutsideClickBound = true;
        window.addEventListener('resize', function () {
            const p = document.getElementById('accessibilityPanel');
            if (p && p.classList.contains('open') && p._trigger) {
                positionAccessibilityPanel(p._trigger, p);
            }
        });
    }
}

function closeAccessibilityPanel() {
    const panel = document.getElementById('accessibilityPanel');
    if (panel) panel.classList.remove('open');
}

// .accessibility-panel is position:fixed (see app.css for why), so its
// left/top have to be computed here against the trigger's actual on-screen
// position instead of relying on a CSS anchor -- recomputed on every open
// (and on resize, see above), so layout changes between opens are handled
// for free. Same algorithm regardless of viewport size (desktop's wide
// sidebar-rail vs. tablet's off-canvas drawer) -- both just want "opening
// out of the sidebar's own column, never spilling out over the page next
// to it", the collision checks below are what keep it on-screen either way
// rather than needing separate breakpoint-specific positioning logic.
function positionAccessibilityPanel(trigger, panel) {
    const rect = trigger.getBoundingClientRect();
    const sidebar = document.querySelector('.profile-sidebar');
    const sidebarRect = sidebar ? sidebar.getBoundingClientRect() : rect;
    // Not display:none while closed (see app.css) specifically so these
    // read the panel's real size instead of a guessed fallback -- a
    // fallback can't account for the accent-swatch grid wrapping onto an
    // extra row on a narrower panel, etc.
    const panelWidth = panel.offsetWidth || 272;
    const panelHeight = panel.offsetHeight || 240;
    const gap = 8;
    const margin = 8; // minimum breathing room from any viewport edge

    // Horizontal: centered within the sidebar's own column (the popover is
    // a few px narrower than the sidebar itself, see app.css), not out to
    // the trigger's right -- opening "beside" it read fine in isolation
    // but in practice landed right on top of whatever dashboard card
    // happened to sit next to the sidebar at that height. Centered under
    // the sidebar instead, it stays inside the sidebar's own footprint
    // and never reaches into the page next to it at all. Only clamped for
    // the pathological case of a viewport narrower than the panel itself.
    let left = sidebarRect.left + (sidebarRect.width - panelWidth) / 2;
    left = Math.max(margin, Math.min(left, window.innerWidth - panelWidth - margin));

    // Vertical: opens directly below the trigger (reads as "coming out of
    // this menu item"), flipping to above it instead if there's no room
    // below -- e.g. the trigger sitting near the bottom of a short window.
    let top;
    const fitsBelow = rect.bottom + gap + panelHeight <= window.innerHeight - margin;
    if (fitsBelow) {
        top = rect.bottom + gap;
    } else {
        top = rect.top - gap - panelHeight;
    }
    top = Math.max(margin, Math.min(top, window.innerHeight - panelHeight - margin));

    panel.style.left = left + 'px';
    panel.style.top = top + 'px';
    panel.style.bottom = 'auto';
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
    closeAccessibilityPanel();
});

document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    const panel = document.getElementById('accessibilityPanel');
    if (panel && panel.classList.contains('open')) {
        closeAccessibilityPanel();
        // Sends focus back to the trigger, same as a native <details>/menu
        // would on Escape -- otherwise it silently lands on <body>, and
        // keyboard users lose their place entirely.
        if (panel._trigger) panel._trigger.focus();
    }
});
