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
$isActive = ($_POST['is_active'] ?? '') === '1';

if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request.', 'type' => 'error']);
    exit;
}

$authUser = authorize('user.manage');

// Deactivating your own account would lock you out with no one left able
// to flip it back for you -- same reasoning as blocking self-demotion in
// update_role.php.
if ($userId === (int) $authUser['id'] && !$isActive) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => "You can't deactivate your own account.", 'type' => 'error']);
    exit;
}

$pdo = db();

$userStmt = $pdo->prepare('
    SELECT u.id, ml.Department AS department
    FROM ' . T_USERS . ' u
    LEFT JOIN ' . T_MASTER_LIST . ' ml ON ml.EmployeeID = u.employee_id
    WHERE u.id = ? AND u.deleted_at IS NULL
');
$userStmt->execute([$userId]);
$targetUser = $userStmt->fetch();
if (!$targetUser) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'User not found.', 'type' => 'error']);
    exit;
}

if (isItDepartmentAdmin($targetUser['department'] ?? null)) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'IT administrator access is managed automatically by department.', 'type' => 'error']);
    exit;
}

$pdo->prepare('UPDATE ' . T_USERS . ' SET is_active = ? WHERE id = ?')->execute([$isActive ? 1 : 0, $userId]);

writeAuditLog($authUser['id'], 'user.update_status', 'user', $userId, ['is_active' => $isActive]);

echo json_encode(['success' => true, 'message' => 'Account ' . ($isActive ? 'activated' : 'deactivated') . '.', 'type' => 'success']);
