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

$authUser = authorize('task.create');

$pdo = db();

// "Today" is anchored to the DB server's own clock (matches dashboard.php)
// rather than PHP's, since the two can run in different timezones.
$dbNow = new DateTime($pdo->query('SELECT CONVERT(varchar, SYSDATETIME(), 120)')->fetchColumn());
$dbToday = clone $dbNow;
$dbToday->setTime(0, 0);

$taskDateRaw = trim($_POST['task_date'] ?? '');
$taskDateObj = DateTime::createFromFormat('Y-m-d', $taskDateRaw);
if (!$taskDateObj || $taskDateObj->format('Y-m-d') !== $taskDateRaw || $taskDateObj < $dbToday) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please pick a valid schedule date (today or later).', 'type' => 'error']);
    exit;
}
$taskDate = $taskDateObj->format('Y-m-d');

// The time is optional so existing date-only task creation keeps its exact
// behavior. When supplied, validate it strictly against the DB-server
// clock. A past minute would be misleading because its effective window
// would immediately fall back to the later assignment timestamp.
$taskTimeRaw = trim($_POST['task_time'] ?? '');
$scheduledAt = null;
if ($taskTimeRaw !== '') {
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $taskTimeRaw)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Please pick a valid schedule time.', 'type' => 'error']);
        exit;
    }
    $scheduledAtObj = DateTime::createFromFormat('!Y-m-d H:i', $taskDate . ' ' . $taskTimeRaw);
    $errors = DateTime::getLastErrors();
    if (!$scheduledAtObj || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Please pick a valid schedule date and time.', 'type' => 'error']);
        exit;
    }
    // Compare at minute precision because the native time input does not
    // submit seconds. The currently displayed minute remains valid even
    // when SQL Server is already several seconds into it.
    if ($scheduledAtObj->format('Y-m-d H:i') < $dbNow->format('Y-m-d H:i')) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'The schedule time cannot be in the past.', 'type' => 'error']);
        exit;
    }
    $scheduledAt = $scheduledAtObj->format('Y-m-d H:i:s');
}

// Only locations not currently on any task's roster, AND matching this
// task's Task Type, can be used -- re-validated here server-side even
// though the picker only shows these. A completed ticket does NOT free
// the location on its own; an Admin has to explicitly Unassign it first.
$availabilityCheck = $pdo->prepare('
    SELECT COUNT(*) FROM ' . T_LOCATIONS . ' l
    WHERE l.id = ? AND l.location_type = ?
      AND NOT EXISTS (
          SELECT 1 FROM ' . T_TASK_LOCATIONS . ' tl WHERE tl.location_id = l.id AND tl.unassigned_at IS NULL
            AND tl.id = (SELECT MAX(tl2.id) FROM ' . T_TASK_LOCATIONS . ' tl2 WHERE tl2.task_id = tl.task_id AND tl2.location_id = tl.location_id)
      )
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
    $stmt = $pdo->prepare('INSERT INTO ' . T_TASKS . ' (name, owner_id, task_type) OUTPUT INSERTED.id VALUES (?, ?, ?)');
    $stmt->execute([$taskName, $authUser['id'], $taskType]);
    $taskId = (int) $stmt->fetchColumn();

    $insertLoc = $pdo->prepare('
        INSERT INTO ' . T_TASK_LOCATIONS . ' (task_id, location_id, task_date, scheduled_at, assigned_by, status)
        VALUES (?, ?, ?, ?, ?, \'pending\')
    ');
    foreach ($locationIds as $locationId) {
        $insertLoc->execute([$taskId, $locationId, $taskDate, $scheduledAt, $authUser['id']]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to create the task. Please try again.', 'type' => 'error']);
    exit;
}

writeAuditLog($authUser['id'], 'task.create', 'task', $taskId, [
    'name' => $taskName,
    'task_type' => $taskType,
    'task_date' => $taskDate,
    'scheduled_at' => $scheduledAt,
    'location_ids' => $locationIds,
]);

$message = 'Task created successfully with ' . count($locationIds) . ' location' . (count($locationIds) === 1 ? '' : 's') . '!';
if (!empty($unavailable)) {
    $message .= ' (' . count($unavailable) . ' selected location(s) had just become unavailable and were skipped.)';
}

echo json_encode(['success' => true, 'message' => $message, 'type' => 'success', 'data' => ['task_id' => $taskId]]);
