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
    const IDLE_TIMEOUT_MS = 15 * 60 * 1000;
    const WARNING_BEFORE_MS = 60 * 1000; // heads-up this long before the actual logout
    const HEARTBEAT_INTERVAL_MS = 15 * 60 * 1000;
    const CHECK_INTERVAL_MS = 5000;

    let lastActivityAt = Date.now();
    let warned = false;

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

    // Only pings while genuinely active -- an idle tab shouldn't be able to
    // keep extending its own session out from under the timer above just
    // because this interval is still running in the background.
    setInterval(function () {
        if (Date.now() - lastActivityAt >= IDLE_TIMEOUT_MS) return;

        fetch('../api/auth/heartbeat.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': window.QRS_CSRF_TOKEN || '' },
        }).catch(function () {
            // A missed heartbeat isn't fatal -- currentUser() still catches
            // an actually-expired session on this tab's next real request.
        });
    }, HEARTBEAT_INTERVAL_MS);
})();
