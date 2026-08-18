<?php
require_once __DIR__ . '/session.php';

function csrfToken(): string
{
    startSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Verifies the CSRF token from either a form POST field or the
 * X-CSRF-Token header (used by fetch() calls). Ends the request with a
 * 403 JSON response if missing/invalid.
 */
function csrfVerify(): void
{
    startSession();
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    $expected = $_SESSION['csrf_token'] ?? null;

    if (!$sent || !$expected || !hash_equals($expected, $sent)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Your session security token is missing or expired. Please refresh the page and try again.', 'type' => 'error']);
        exit;
    }
}
