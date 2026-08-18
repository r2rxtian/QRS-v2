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

$taskIds = array_values(array_unique(array_filter(array_map('intval', $_POST['task_ids'] ?? []), fn($id) => $id > 0)));
if (empty($taskIds)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No tasks selected.', 'type' => 'error']);
    exit;
}

$authUser = authorize('task.delete');

if (!checkRateLimit('delete:task:' . $authUser['id'], 20, 60)) {
    respondRateLimited();
}

$pdo = db();
$placeholders = implode(',', array_fill(0, count($taskIds), '?'));

$taskStmt = $pdo->prepare('SELECT id, name, department_id FROM ' . T_TASKS . " WHERE id IN ($placeholders) AND deleted_at IS NULL");
$taskStmt->execute($taskIds);
$tasks = $taskStmt->fetchAll();

if (empty($tasks)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'None of the selected tasks were found.', 'type' => 'error']);
    exit;
}

$foundIds = array_map(fn($t) => (int) $t['id'], $tasks);
$foundPlaceholders = implode(',', array_fill(0, count($foundIds), '?'));

$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE ' . T_TASKS . " SET deleted_at = SYSDATETIME() WHERE id IN ($foundPlaceholders)")->execute($foundIds);

    // Same as the single-task delete: release every location these tasks
    // still hold, otherwise a deleted task's locations stay "assigned"
    // forever (unassigned_at never gets set).
    $releaseStmt = $pdo->prepare('
        UPDATE ' . T_TASK_LOCATIONS . "
        SET unassigned_at = SYSDATETIME(), unassigned_by = ?
        WHERE task_id IN ($foundPlaceholders) AND unassigned_at IS NULL
    ");
    $releaseStmt->execute([$authUser['id'], ...$foundIds]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

foreach ($tasks as $task) {
    writeAuditLog($authUser['id'], 'task.delete', 'task', (int) $task['id'], $task['department_id'], ['name' => $task['name']]);
}

$skipped = count($taskIds) - count($foundIds);
$message = count($foundIds) . ' task(s) deleted.';
if ($skipped > 0) {
    $message .= ' ' . $skipped . ' skipped (already deleted or not found).';
}

echo json_encode(['success' => true, 'message' => $message, 'type' => 'success']);
