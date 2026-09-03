// session-guard.js — client-side half of the 15-minute idle auto-logout
// and active-session refresh. The server-side half (the actual security
// boundary) lives in auth/session.php's currentUser(), which every
// authenticated page/API request already goes through -- this file exists
// so a genuinely idle tab gets logged out right when the clock runs out,
// rather than only finding out on whatever request the person happens to
// make next, and so an active-but-quiet user (reading a long report,
// no clicks) still gets their session refreshed on schedule even if they
// haven't happened to trigger a qualifying request themselves.
//
// Degrades to nothing if this ever loads somewhere the rest of the app's
// usual globals (QRS_CSRF_TOKEN, showToast) aren't set up -- the idle
// redirect itself has no such dependency and still works regardless.
(function () {
    const sessionConfig = window.QRS_SESSION_CONFIG || {};
    const IDLE_TIMEOUT_MS = Number(sessionConfig.idleTimeoutMs);
    const HEARTBEAT_INTERVAL_MS = Number(sessionConfig.heartbeatIntervalMs);
    const WARNING_BEFORE_MS = Number(sessionConfig.warningBeforeMs);
    if (!Number.isFinite(IDLE_TIMEOUT_MS) || IDLE_TIMEOUT_MS <= 0
        || !Number.isFinite(HEARTBEAT_INTERVAL_MS) || HEARTBEAT_INTERVAL_MS <= 0
        || !Number.isFinite(WARNING_BEFORE_MS) || WARNING_BEFORE_MS < 0) {
        return;
    }
    const CHECK_INTERVAL_MS = 5000;

    let lastActivityAt = Date.now();
    let warned = false;
    let heartbeatInFlight = false;
    let redirecting = false;

    function markActive() {
        lastActivityAt = Date.now();
        warned = false;
    }

    ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll'].forEach(function (evt) {
        window.addEventListener(evt, markActive, { passive: true });
    });

    setInterval(function () {
        const idleFor = Date.now() - lastActivityAt;

        if (idleFor >= IDLE_TIMEOUT_MS) {
            window.location.href = '../auth/logout.php?reason=idle';
            return;
        }

        if (!warned && idleFor >= IDLE_TIMEOUT_MS - WARNING_BEFORE_MS) {
            warned = true;
            if (typeof showToast === 'function') {
                showToast('You will be signed out in 1 minute due to inactivity.', 'error');
            }
        }
    }, CHECK_INTERVAL_MS);

    function redirectToLogin() {
        if (redirecting) return;
        redirecting = true;
        window.location.href = '../auth/logout.php?reason=idle';
    }

    // Only pings while genuinely active -- an idle tab shouldn't be able to
    // keep extending its own session out from under the timer above just
    // because this interval is still running in the background. The in-flight
    // guard prevents a slow request from overlapping the next scheduled ping.
    async function sendHeartbeat() {
        if (heartbeatInFlight || redirecting || Date.now() - lastActivityAt >= IDLE_TIMEOUT_MS) return;

        heartbeatInFlight = true;
        try {
            const response = await fetch('../api/auth/heartbeat.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-Token': typeof QRS_CSRF_TOKEN !== 'undefined'
                        ? QRS_CSRF_TOKEN
                        : (window.QRS_CSRF_TOKEN || ''),
                },
            });
            let data = null;
            try {
                data = await response.json();
            } catch (parseError) {
                // A transient/non-JSON response is handled as a missed ping.
            }

            if (response.status === 401 || (data && data.success === false)) {
                redirectToLogin();
            }
        } catch (error) {
            // A network failure isn't proof the session expired. The next
            // heartbeat or real request can retry, while the local idle timer
            // remains the source of truth for inactivity logout.
        } finally {
            heartbeatInFlight = false;
        }
    }

    setInterval(sendHeartbeat, HEARTBEAT_INTERVAL_MS);
})();
