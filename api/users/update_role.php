<?php
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../auth/csrf.php';
require_once __DIR__ . '/../../authz/authz.php';
require_once __DIR__ . '/../../conn/db.php';
require_once __DIR__ . '/../../rules/constants.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.', 'type' => 'error']);
    exit;
}

csrfVerify();

$userId = (int) ($_POST['user_id'] ?? 0);
$role = trim($_POST['role'] ?? '');

if ($userId <= 0 || !in_array($role, [ROLE_ADMIN, ROLE_USER], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request.', 'type' => 'error']);
    exit;
}

$authUser = authorize('user.manage');

// An Admin flipping their OWN toggle to User would lock themselves out --
// nothing else in this app grants Admin back once nobody with the
// capability is left to do it.
if ($userId === (int) $authUser['id'] && $role !== ROLE_ADMIN) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => "You can't remove your own Admin access.", 'type' => 'error']);
    exit;
}

$pdo = db();

$userStmt = $pdo->prepare('SELECT id FROM ' . T_USERS . ' WHERE id = ? AND deleted_at IS NULL');
$userStmt->execute([$userId]);
if (!$userStmt->fetchColumn()) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'User not found.', 'type' => 'error']);
    exit;
}

$roleStmt = $pdo->prepare('SELECT id FROM ' . T_ROLES . ' WHERE name = ?');
$roleStmt->execute([$role]);
$roleId = $roleStmt->fetchColumn();
if (!$roleId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid role.', 'type' => 'error']);
    exit;
}

$pdo->prepare('UPDATE ' . T_USERS . ' SET role_id = ? WHERE id = ?')->execute([$roleId, $userId]);

writeAuditLog($authUser['id'], 'user.update_role', 'user', $userId, ['role' => $role]);

echo json_encode(['success' => true, 'message' => 'Role updated to ' . $role . '.', 'type' => 'success']);
