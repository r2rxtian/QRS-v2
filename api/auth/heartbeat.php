<?php
/**
 * Client-requested active-session refresh (scripts/session-guard.js pings
 * this every 15 minutes, only while the tab is actually active). All the
 * real work -- checking idle timeout, touching last_activity_at, rotating
 * the session id if it's due -- already happens inside requireLogin() ->
 * currentUser() on every authenticated request; this endpoint's only job
 * is to guarantee one such request happens on a predictable schedule even
 * during a long stretch of passive reading with no other page/API calls.
 */
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../auth/csrf.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.', 'type' => 'error']);
    exit;
}

csrfVerify();

requireLogin(true);

echo json_encode(['success' => true]);
