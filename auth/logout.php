<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../authz/audit.php';

startSession();

// scripts/session-guard.js sends reason=idle when it's the one deciding to
// log out (15 real minutes of no mouse/keyboard/scroll activity) rather
// than a person clicking "Logout" themselves -- kept as a distinct audit
// action (not just a details field on the same 'logout') so the Audit
// Logs UI can tell the two apart at a glance, and carried through to
// login.php so it can greet the person with why they're back here instead
// of silently dropping them at the form.
$isIdle = ($_GET['reason'] ?? '') === 'idle';

if (isset($_SESSION['user_id'])) {
    writeAuditLog((int) $_SESSION['user_id'], $isIdle ? 'logout.idle_timeout' : 'logout', 'user', (int) $_SESSION['user_id']);
}

$_SESSION = [];
session_unset();
session_destroy();

header('Location: ../pages/login.php' . ($isIdle ? '?reason=idle' : ''));
exit;
