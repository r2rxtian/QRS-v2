<?php
/**
 * Session bootstrap + identity accessors. Every page/api file that needs
 * the current user includes this and calls requireLogin()/currentUser().
 */

// The DB server (LRNPH_OJT) runs on Philippine time; PHP's own clock was
// found to be several hours off (a different OS timezone), which silently
// skewed anything comparing PHP's "now" against DB timestamps set via
// SYSDATETIME() (dashboard date windows, "today" highlights, etc.). Pin
// PHP to the same timezone so they always agree.
date_default_timezone_set('Asia/Manila');

function startSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        // "QRTaskCheck" instead of PHP's default "PHPSESSID" -- cookie names
        // can't contain spaces (RFC 6265), so this is the closest safe form
        // of the app's own name ("QR Task Check").
        session_name('QRTaskCheck');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function isLoggedIn(): bool
{
    // Routed through currentUser() (not a bare isset($_SESSION['user_id'])
    // check) so an idle-expired session reads back as logged-out here too
    // -- otherwise login.php would redirect an already-expired visitor
    // straight to dashboard.php, which would then bounce them right back
    // here anyway once requireLogin() ran the same check a moment later.
    return currentUser() !== null;
}

/**
 * Returns the current session identity, or null if not logged in.
 * Never trust client-submitted creator fields for writes -- always pull
 * identity from here instead.
 *
 * Also where the client-requested idle-timeout and session-token-refresh
 * both actually get enforced -- every authenticated page load and API call
 * goes through here (directly, or via requireLogin() below), so this is
 * the one place both apply everywhere automatically, no per-page wiring.
 * scripts/session-guard.js is the client-side half: it proactively
 * redirects to logout after 15 idle minutes and pings a heartbeat endpoint
 * every 12 active minutes, but even without it (JS disabled, script
 * blocked) the *next* real request still gets caught here regardless --
 * this is the actual security boundary, the client-side timer is just
 * what makes it feel immediate instead of "logged out on your next click".
 */
function currentUser(): ?array
{
    startSession();
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    require_once __DIR__ . '/../rules/constants.php';

    // Idle timeout: a session that hasn't been touched by any request in
    // too long gets torn down here, on whatever request finally comes in
    // after that point (there's no background process in a plain PHP app
    // to do this proactively the instant the clock runs out).
    if (isset($_SESSION['last_activity_at']) && (time() - $_SESSION['last_activity_at']) > SESSION_IDLE_TIMEOUT_MINUTES * 60) {
        $expiredUserId = (int) $_SESSION['user_id'];
        $_SESSION = [];
        session_unset();
        session_destroy();
        // session_destroy() only clears server-side storage -- the browser
        // still needs a session to work with for whatever comes next
        // (e.g. login.php rendering its own "signed out" notice).
        session_start();

        require_once __DIR__ . '/../conn/db.php';
        require_once __DIR__ . '/../authz/audit.php';
        writeAuditLog($expiredUserId, 'logout.idle_timeout', 'user', $expiredUserId);

        return null;
    }
    $_SESSION['last_activity_at'] = time();

    // Session token refresh: rotates the session id (the actual bearer
    // value in the cookie) periodically for any session active enough to
    // still be making requests -- only ever reached once the idle check
    // above has already passed, so "active" is already established by the
    // time this runs. $_SESSION's own data (identity, CSRF token, etc.)
    // survives the rotation untouched; only the id/cookie value changes.
    if (!isset($_SESSION['token_issued_at'])) {
        $_SESSION['token_issued_at'] = time();
    } elseif ((time() - $_SESSION['token_issued_at']) >= SESSION_TOKEN_REFRESH_MINUTES * 60) {
        session_regenerate_id(true);
        $_SESSION['token_issued_at'] = time();
    }

    return [
        'id' => $_SESSION['user_id'],
        'employee_id' => $_SESSION['employee_id'],
        'full_name' => $_SESSION['full_name'],
        'role_id' => $_SESSION['role_id'],
        'role_name' => $_SESSION['role_name'],
        'avatar_initials' => $_SESSION['avatar_initials'] ?? null,
        'avatar_color' => $_SESSION['avatar_color'] ?? null,
    ];
}

/**
 * For pages/*.php: redirects to login.php if not authenticated.
 * For api/*.php: pass $isApi = true to get a 401 JSON response instead.
 */
function requireLogin(bool $isApi = false): array
{
    $user = currentUser();
    if ($user !== null) {
        return $user;
    }

    if ($isApi) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Please log in to continue.', 'type' => 'error']);
        exit;
    }

    header('Location: login.php');
    exit;
}
