<?php
/**
 * GET-only lookup: resolves a biometrics number to the person's real name
 * (via T_MASTER_LIST) and flags whether they already have company login
 * access provisioned (T_LRNPH_USERS) and whether they're already a
 * qrs_users account -- feeds the Settings > User Management "Add User"
 * modal's auto-detect step. Read-only, no CSRF needed (matches
 * api/tasks/detail_partial.php's convention), but still gated to Admins
 * only via authorize().
 */
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../authz/authz.php';
require_once __DIR__ . '/../../conn/db.php';
require_once __DIR__ . '/../../rules/constants.php';

header('Content-Type: application/json');

authorize('user.manage');

$biometricsId = trim($_GET['biometrics_id'] ?? '');
if ($biometricsId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter a biometrics number.', 'type' => 'error']);
    exit;
}

$pdo = db();

$stmt = $pdo->prepare('
    SELECT ml.EmployeeID AS employee_id, ' . masterListNameSql('ml') . ' AS full_name,
           ml.Department AS department, ml.PositionTitle AS position,
           lu.status AS lrnph_status
    FROM ' . T_MASTER_LIST . ' ml
    LEFT JOIN ' . T_LRNPH_USERS . ' lu ON lu.username = ml.BiometricsID
    WHERE ml.BiometricsID = ?
');
$stmt->execute([$biometricsId]);
$person = $stmt->fetch();

if (!$person) {
    echo json_encode(['success' => false, 'message' => 'No employee found with that biometrics number.', 'type' => 'error']);
    exit;
}

$existingStmt = $pdo->prepare('SELECT id FROM ' . T_USERS . ' WHERE employee_id = ? AND deleted_at IS NULL');
$existingStmt->execute([$person['employee_id']]);
$alreadyExists = (bool) $existingStmt->fetchColumn();
$departmentManaged = isItDepartmentAdmin($person['department'] ?? null);

echo json_encode([
    'success' => true,
    'data' => [
        'employee_id' => $person['employee_id'],
        'full_name' => $person['full_name'],
        'department' => $person['department'],
        'position' => $person['position'],
        'has_login' => $person['lrnph_status'] === 'active',
        'already_exists' => $alreadyExists,
        'department_managed' => $departmentManaged,
        'photo_url' => employeePhotoUrl($person['employee_id']),
    ],
]);
