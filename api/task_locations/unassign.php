<?php
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../auth/csrf.php';
require_once __DIR__ . '/../../authz/authz.php';
require_once __DIR__ . '/../../conn/db.php';
require_once __DIR__ . '/../../rules/constants.php';
require_once __DIR__ . '/../../rules/status.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.', 'type' => 'error']);
    exit;
}

csrfVerify();

$taskId = (int) ($_POST['task_id'] ?? 0);
$locationId = (int) ($_POST['location_id'] ?? 0);

if ($taskId <= 0 || $locationId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request.', 'type' => 'error']);
    exit;
}

$authUser = authorize('task_location.unassign', ['task_id' => $taskId, 'entity_type' => 'task', 'entity_id' => $taskId]);

$pdo = db();

// A completed task is done -- its locations stay as a historical record and
// can no longer be unassigned, even via a direct API call (the UI already
// hides the Unassign button once every location is completed).
$statusStmt = $pdo->prepare('
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = \'completed\' THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN status = \'in_progress\' THEN 1 ELSE 0 END) AS in_progress
    FROM ' . T_TASK_LOCATIONS . '
    WHERE task_id = ? AND task_date = CAST(SYSDATETIME() AS DATE) AND unassigned_at IS NULL
');
$statusStmt->execute([$taskId]);
$statusRow = $statusStmt->fetch();
$taskStatus = deriveTaskStatus((int) $statusRow['total'], (int) $statusRow['completed'], (int) $statusRow['in_progress']);
if ($taskStatus['code'] === 'completed') {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'This task is already completed and can no longer be modified.', 'type' => 'error']);
    exit;
}

$stmt = $pdo->prepare('
    UPDATE ' . T_TASK_LOCATIONS . '
    SET unassigned_at = SYSDATETIME(), unassigned_by = ?
    WHERE task_id = ? AND location_id = ? AND task_date = CAST(SYSDATETIME() AS DATE) AND unassigned_at IS NULL
');
$stmt->execute([$authUser['id'], $taskId, $locationId]);

if ($stmt->rowCount() === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'That location was not assigned to this task today.', 'type' => 'error']);
    exit;
}

writeAuditLog($authUser['id'], 'task_location.unassign', 'task', $taskId, $authUser['department_id'], ['location_id' => $locationId]);

echo json_encode(['success' => true, 'message' => 'Location unassigned successfully.', 'type' => 'success']);
