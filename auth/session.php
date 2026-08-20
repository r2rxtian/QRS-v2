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
    startSession();
    return isset($_SESSION['user_id']);
}

/**
 * Returns the current session identity, or null if not logged in.
 * Never trust client-submitted creator fields for writes -- always pull
 * identity from here instead.
 */
function currentUser(): ?array
{
    startSession();
    if (!isset($_SESSION['user_id'])) {
        return null;
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
