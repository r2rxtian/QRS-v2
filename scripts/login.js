// login.js — Mascot reacts to the password field, plus a plain show/hide toggle.
// Eyes closed while typing (hidden), one eye open (wink) when revealed.

function updateMascotState() {
    const mascot = document.getElementById('mascot');
    const input = document.getElementById('login_password');
    if (!mascot || !input) return;

    const isRevealed = input.type === 'text';
    const isFocused = document.activeElement === input;

    mascot.classList.remove('closed', 'wink');
    if (isRevealed) {
        mascot.classList.add('wink');
    } else if (isFocused) {
        mascot.classList.add('closed');
    }
}

function togglePasswordVisibility() {
    const input = document.getElementById('login_password');
    const btn = document.getElementById('eyeToggleBtn');
    const isHidden = input.type === 'password';

    input.type = isHidden ? 'text' : 'password';
    btn.querySelector('i').className = isHidden ? 'fas fa-eye-slash' : 'fas fa-eye';
    btn.setAttribute('aria-pressed', String(isHidden));
    btn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');

    updateMascotState();
}

// Ties the floating "Today's Tasks" preview card to the actual form —
// typing a username/password checks off the matching task in real time.
function bindTaskPreviewCheck(inputId, rowId) {
    const input = document.getElementById(inputId);
    const row = document.getElementById(rowId);
    if (!input || !row) return;

    input.addEventListener('input', () => {
        row.classList.toggle('done', input.value.trim().length > 0);
    });
}

function showAuthMessage(text, type) {
    const el = document.getElementById('authMessage');
    if (!el) return;
    el.textContent = text;
    el.className = 'auth-message visible ' + type;

    // display can't be transitioned, so the visibility toggle above is
    // instant either way -- GSAP only adds the motion on top of it.
    if (typeof gsap === 'undefined' || (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches)) {
        return;
    }

    gsap.fromTo(el, { opacity: 0, y: -6 }, { opacity: 1, y: 0, duration: 0.3, ease: 'power2.out' });

    if (type === 'error') {
        gsap.fromTo('#loginForm', { x: 0 }, { x: -8, duration: 0.06, repeat: 5, yoyo: true, ease: 'power1.inOut', clearProps: 'x' });
    }
}

async function handleLoginSubmit(event) {
    event.preventDefault();

    const form = document.getElementById('loginForm');
    const btn = document.getElementById('loginSubmitBtn');
    const messageEl = document.getElementById('authMessage');

    messageEl.className = 'auth-message';
    btn.disabled = true;
    btn.textContent = 'Logging in…';

    try {
        const response = await fetch('../auth/login_handler.php', {
            method: 'POST',
            body: new FormData(form),
        });
        const data = await response.json();

        if (data.success) {
            showAuthMessage(data.message, 'success');
            window.location.href = data.data && data.data.redirect ? data.data.redirect : 'dashboard.php';
            return;
        }

        showAuthMessage(data.message || 'Login failed. Please try again.', 'error');
    } catch (err) {
        showAuthMessage('Could not reach the server. Please try again.', 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = 'Log In';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const passwordInput = document.getElementById('login_password');
    if (passwordInput) {
        passwordInput.addEventListener('focus', updateMascotState);
        passwordInput.addEventListener('blur', updateMascotState);
    }

    bindTaskPreviewCheck('login_id', 'taskRowUsername');
    bindTaskPreviewCheck('login_password', 'taskRowPassword');

    const form = document.getElementById('loginForm');
    if (form) {
        form.addEventListener('submit', handleLoginSubmit);
    }
});
