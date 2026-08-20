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

$taskId = (int) ($_POST['task_id'] ?? 0);
if ($taskId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid task.', 'type' => 'error']);
    exit;
}

$authUser = authorize('task.delete', ['task_id' => $taskId, 'entity_type' => 'task', 'entity_id' => $taskId]);

if (!checkRateLimit('delete:task:' . $authUser['id'], 20, 60)) {
    respondRateLimited();
}

$pdo = db();
$taskStmt = $pdo->prepare('SELECT id, name FROM ' . T_TASKS . ' WHERE id = ? AND deleted_at IS NULL');
$taskStmt->execute([$taskId]);
$task = $taskStmt->fetch();

if (!$task) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Task not found.', 'type' => 'error']);
    exit;
}

$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE ' . T_TASKS . ' SET deleted_at = SYSDATETIME() WHERE id = ?')->execute([$taskId]);

    // Release every location this task still holds -- otherwise a deleted
    // task's locations stay "assigned" forever (unassigned_at never gets
    // set), permanently blocking them from being assigned to anything else
    // even though the task holding them no longer exists.
    $pdo->prepare('
        UPDATE ' . T_TASK_LOCATIONS . '
        SET unassigned_at = SYSDATETIME(), unassigned_by = ?
        WHERE task_id = ? AND unassigned_at IS NULL
    ')->execute([$authUser['id'], $taskId]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

writeAuditLog($authUser['id'], 'task.delete', 'task', $taskId, ['name' => $task['name']]);

echo json_encode(['success' => true, 'message' => 'Task deleted.', 'type' => 'success']);
