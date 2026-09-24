<?php
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../auth/csrf.php';
require_once __DIR__ . '/../../authz/authz.php';
require_once __DIR__ . '/../../authz/rate_limit.php';
require_once __DIR__ . '/../../conn/db.php';
require_once __DIR__ . '/../../rules/constants.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.', 'type' => 'error']);
    exit;
}

csrfVerify();

$biometricsId = trim($_POST['biometrics_id'] ?? '');
$role = trim($_POST['role'] ?? '');

if ($biometricsId === '' || !in_array($role, [ROLE_ADMIN, ROLE_USER], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please provide a biometrics number and role.', 'type' => 'error']);
    exit;
}

$authUser = authorize('user.manage');

if (!checkRateLimit('create:user:' . $authUser['id'], 20, 60)) {
    respondRateLimited();
}

$pdo = db();

// Never trust a client-supplied employee_id/name -- re-resolve the
// biometrics number server-side against the master list, same as
// api/users/lookup.php did for the modal's auto-detect step. The client
// only ever gets to pick the biometrics number and the role.
$stmt = $pdo->prepare('SELECT ml.EmployeeID AS employee_id, ' . masterListNameSql('ml') . ' AS full_name, ml.Department AS department FROM ' . T_MASTER_LIST . ' ml WHERE ml.BiometricsID = ?');
$stmt->execute([$biometricsId]);
$person = $stmt->fetch();

if (!$person) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'No employee found with that biometrics number.', 'type' => 'error']);
    exit;
}

if (isItDepartmentAdmin($person['department'] ?? null)) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'IT administrator access is provisioned automatically at login and is not managed here.', 'type' => 'error']);
    exit;
}

$existingStmt = $pdo->prepare('SELECT id FROM ' . T_USERS . ' WHERE employee_id = ? AND deleted_at IS NULL');
$existingStmt->execute([$person['employee_id']]);
if ($existingStmt->fetchColumn()) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'This person already has a QRS account.', 'type' => 'error']);
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

$insert = $pdo->prepare('INSERT INTO ' . T_USERS . ' (employee_id, role_id) OUTPUT INSERTED.id VALUES (?, ?)');
$insert->execute([$person['employee_id'], $roleId]);
$newUserId = (int) $insert->fetchColumn();

writeAuditLog($authUser['id'], 'user.create', 'user', $newUserId, ['employee_id' => $person['employee_id'], 'role' => $role]);

echo json_encode(['success' => true, 'message' => $person['full_name'] . ' was added as ' . $role . '.', 'type' => 'success']);
