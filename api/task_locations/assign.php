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
$locationIds = $_POST['location_ids'] ?? [];

if ($taskId <= 0 || !is_array($locationIds) || empty($locationIds)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please select at least one location.', 'type' => 'error']);
    exit;
}

$authUser = authorize('task_location.assign', ['task_id' => $taskId, 'entity_type' => 'task', 'entity_id' => $taskId]);

$pdo = db();

$taskStmt = $pdo->prepare('SELECT id, task_type FROM ' . T_TASKS . ' WHERE id = ? AND deleted_at IS NULL');
$taskStmt->execute([$taskId]);
$task = $taskStmt->fetch();
if (!$task) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Task not found.', 'type' => 'error']);
    exit;
}
$taskType = $task['task_type'];

// A completed task is done -- reopening it by sneaking in a fresh location
// via a direct API call (bypassing the UI, which already hides this) isn't
// allowed either. Aggregates over each location's CURRENT ticket -- still
// on the roster OR auto-unassigned by the system once resolved (see
// rules/status.php's sweepResolvedLocations() -- unassigned_by IS NULL
// marks that case) -- so this still correctly detects "already completed"
// even after every one of the task's locations has auto-freed itself.
// Latest-ticket pinning still guards a location that was manually
// unassigned and re-assigned to this same task from being double counted
// alongside the current one.
$statusStmt = $pdo->prepare('
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = \'completed\' THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN status = \'in_progress\' THEN 1 ELSE 0 END) AS in_progress
    FROM ' . T_TASK_LOCATIONS . ' tl
    WHERE task_id = ? AND (unassigned_at IS NULL OR unassigned_by IS NULL)
      AND id = (
          SELECT MAX(tl2.id) FROM ' . T_TASK_LOCATIONS . ' tl2
          WHERE tl2.task_id = tl.task_id AND tl2.location_id = tl.location_id
      )
');
$statusStmt->execute([$taskId]);
$statusRow = $statusStmt->fetch();
$taskStatus = deriveTaskStatus((int) $statusRow['total'], (int) $statusRow['completed'], (int) $statusRow['in_progress']);
if ($taskStatus['code'] === 'completed') {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'This task is already completed and can no longer be modified.', 'type' => 'error']);
    exit;
}

$locationIds = array_values(array_unique(array_filter(array_map('intval', $locationIds), fn($id) => $id > 0)));

// "Already active on this task" / "active elsewhere" both mean the
// location's CURRENT ticket (latest row) hasn't been explicitly
// unassigned -- a completed ticket does NOT free the location on its own;
// an Admin has to explicitly Unassign it before it's offered elsewhere.
$checkActiveForTask = $pdo->prepare('
    SELECT COUNT(*) FROM ' . T_TASK_LOCATIONS . ' tl
    WHERE task_id = ? AND location_id = ? AND unassigned_at IS NULL
      AND id = (SELECT MAX(id) FROM ' . T_TASK_LOCATIONS . ' WHERE task_id = ? AND location_id = ?)
');
$checkAssignedElsewhere = $pdo->prepare('
    SELECT COUNT(*) FROM ' . T_TASK_LOCATIONS . ' tl
    WHERE location_id = ? AND unassigned_at IS NULL
      AND id = (
          SELECT MAX(tl2.id) FROM ' . T_TASK_LOCATIONS . ' tl2
          WHERE tl2.task_id = tl.task_id AND tl2.location_id = tl.location_id
      )
');
$checkLocationType = $pdo->prepare('SELECT location_type FROM ' . T_LOCATIONS . ' WHERE id = ? AND deleted_at IS NULL AND is_active = 1');
$insert = $pdo->prepare('
    INSERT INTO ' . T_TASK_LOCATIONS . ' (task_id, location_id, task_date, assigned_by, status)
    VALUES (?, ?, CAST(SYSDATETIME() AS DATE), ?, \'pending\')
');

$assigned = 0;
$duplicates = 0;
$alreadyElsewhere = 0;
$wrongType = 0;

foreach ($locationIds as $locationId) {
    $checkActiveForTask->execute([$taskId, $locationId, $taskId, $locationId]);
    if ((int) $checkActiveForTask->fetchColumn() > 0) {
        $duplicates++;
        continue;
    }

    $checkLocationType->execute([$locationId]);
    $locationType = $checkLocationType->fetchColumn();
    if ($locationType === false || $locationType !== $taskType) {
        $wrongType++;
        continue;
    }

    $checkAssignedElsewhere->execute([$locationId]);
    if ((int) $checkAssignedElsewhere->fetchColumn() > 0) {
        $alreadyElsewhere++;
        continue;
    }

    $insert->execute([$taskId, $locationId, $authUser['id']]);
    $assigned++;
}

$parts = [];
if ($assigned > 0) {
    $parts[] = "$assigned location(s) assigned";
}
if ($duplicates > 0) {
    $parts[] = "$duplicates already assigned to this task";
}
if ($alreadyElsewhere > 0) {
    $parts[] = "$alreadyElsewhere already assigned to another task";
}
if ($wrongType > 0) {
    $parts[] = "$wrongType not a $taskType location";
}
$message = $parts ? implode(', ', $parts) . '.' : 'No locations were assigned.';

writeAuditLog($authUser['id'], 'task_location.assign', 'task', $taskId, [
    'location_ids' => $locationIds, 'assigned' => $assigned, 'duplicates' => $duplicates, 'already_elsewhere' => $alreadyElsewhere,
]);

echo json_encode(['success' => $assigned > 0, 'message' => $message, 'type' => $assigned > 0 ? 'success' : 'info']);
