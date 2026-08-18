<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../authz/audit.php';

startSession();

if (isset($_SESSION['user_id'])) {
    writeAuditLog((int) $_SESSION['user_id'], 'logout', 'user', (int) $_SESSION['user_id'], $_SESSION['department_id'] ?? null);
}

$_SESSION = [];
session_unset();
session_destroy();

header('Location: ../pages/login.php');
exit;
