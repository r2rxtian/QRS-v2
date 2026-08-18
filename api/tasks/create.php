<?php
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../auth/csrf.php';
require_once __DIR__ . '/../../authz/authz.php';
require_once __DIR__ . '/../../conn/db.php';
require_once __DIR__ . '/../../rules/constants.php';
require_once __DIR__ . '/../../rules/validation.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.', 'type' => 'error']);
    exit;
}

csrfVerify();

$taskName = sanitizeText($_POST['task_name'] ?? '');
if ($taskName === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter a task name.', 'type' => 'error']);
    exit;
}

$taskType = trim($_POST['task_type'] ?? '');
if (!in_array($taskType, TASK_TYPES, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please select a Task Type.', 'type' => 'error']);
    exit;
}

$locationIds = $_POST['location_ids'] ?? [];
$locationIds = is_array($locationIds)
    ? array_values(array_unique(array_filter(array_map('intval', $locationIds), fn($id) => $id > 0)))
    : [];

if (empty($locationIds)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please assign at least one location — a task can\'t be created without one.', 'type' => 'error']);
    exit;
}

// Department is always the acting user's own — always session-derived,
// never client-submitted (there's only ever one department, but this keeps
// the IDOR-safe pattern intact regardless).
$user = requireLogin(true);
$departmentId = $user['department_id'];

if ($departmentId === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A department is required to create a task.', 'type' => 'error']);
    exit;
}

$authUser = authorize('task.create', ['department_id' => $departmentId]);

$pdo = db();

// "Today" is anchored to the DB server's own clock (matches dashboard.php)
// rather than PHP's, since the two can run in different timezones.
$dbToday = new DateTime($pdo->query('SELECT CONVERT(varchar, SYSDATETIME(), 120)')->fetchColumn());
$dbToday->setTime(0, 0);

$taskDateRaw = trim($_POST['task_date'] ?? '');
$taskDateObj = DateTime::createFromFormat('Y-m-d', $taskDateRaw);
if (!$taskDateObj || $taskDateObj->format('Y-m-d') !== $taskDateRaw || $taskDateObj < $dbToday) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please pick a valid schedule date (today or later).', 'type' => 'error']);
    exit;
}
$taskDate = $taskDateObj->format('Y-m-d');

$deptCheck = $pdo->prepare('SELECT id FROM ' . T_DEPARTMENTS . ' WHERE id = ? AND is_active = 1');
$deptCheck->execute([$departmentId]);
if (!$deptCheck->fetchColumn()) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid department.', 'type' => 'error']);
    exit;
}

// Only locations not currently assigned to any active task, AND matching
// this task's Task Type, can be used -- re-validated here server-side even
// though the picker only shows these.
$availabilityCheck = $pdo->prepare('
    SELECT COUNT(*) FROM ' . T_LOCATIONS . ' l
    WHERE l.id = ? AND l.location_type = ?
      AND NOT EXISTS (SELECT 1 FROM ' . T_TASK_LOCATIONS . ' tl WHERE tl.location_id = l.id AND tl.unassigned_at IS NULL AND tl.status <> \'completed\')
');
$unavailable = [];
foreach ($locationIds as $locationId) {
    $availabilityCheck->execute([$locationId, $taskType]);
    if ((int) $availabilityCheck->fetchColumn() === 0) {
        $unavailable[] = $locationId;
    }
}
$locationIds = array_values(array_diff($locationIds, $unavailable));

if (empty($locationIds)) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'The selected location(s) are no longer available. Please choose different ones.', 'type' => 'error']);
    exit;
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('INSERT INTO ' . T_TASKS . ' (name, department_id, owner_id, task_type, is_recurring) OUTPUT INSERTED.id VALUES (?, ?, ?, ?, 1)');
    $stmt->execute([$taskName, $departmentId, $authUser['id'], $taskType]);
    $taskId = (int) $stmt->fetchColumn();

    $insertLoc = $pdo->prepare('
        INSERT INTO ' . T_TASK_LOCATIONS . ' (task_id, location_id, task_date, assigned_by, status)
        VALUES (?, ?, ?, ?, \'pending\')
    ');
    foreach ($locationIds as $locationId) {
        $insertLoc->execute([$taskId, $locationId, $taskDate, $authUser['id']]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to create the task. Please try again.', 'type' => 'error']);
    exit;
}

writeAuditLog($authUser['id'], 'task.create', 'task', $taskId, $departmentId, ['name' => $taskName, 'task_type' => $taskType, 'location_ids' => $locationIds]);

$message = 'Task created successfully with ' . count($locationIds) . ' location' . (count($locationIds) === 1 ? '' : 's') . '!';
if (!empty($unavailable)) {
    $message .= ' (' . count($unavailable) . ' selected location(s) had just become unavailable and were skipped.)';
}

echo json_encode(['success' => true, 'message' => $message, 'type' => 'success', 'data' => ['task_id' => $taskId]]);
