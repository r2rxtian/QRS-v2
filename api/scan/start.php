<?php
/**
 * Observation/Recommendation screen submit (spec Step 2): records the
 * 4-item Yes/No/N/A checklist (Spot Spray, Misting, Mist Blower,
 * Monitoring -- each requiring a remark when answered No or N/A) plus an
 * optional general Findings/Observation note, then starts the check. No
 * photo requirement here -- photos are captured separately once the
 * physical work is done (see api/scan/complete.php).
 */
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

$taskId = (int) ($_POST['task_id'] ?? 0);
$taskLocationId = (int) ($_POST['task_location_id'] ?? 0);
$findingsObservation = sanitizeText($_POST['findings_observation'] ?? '');

if ($taskId <= 0 || $taskLocationId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request.', 'type' => 'error']);
    exit;
}

// Validate the 4-item checklist: each must be answered, and a remark is
// required (per spec) whenever the answer is No or N/A.
$checklist = [];
foreach (CHECKLIST_ITEMS as $key => $label) {
    $answer = trim($_POST[$key . '_answer'] ?? '');
    $remark = sanitizeText($_POST[$key . '_remark'] ?? '');

    if (!in_array($answer, CHECKLIST_ANSWERS, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Please answer \"$label\".", 'type' => 'error']);
        exit;
    }
    if (($answer === 'No' || $answer === 'N/A') && $remark === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Please add a remark for \"$label\" since it was answered \"$answer\".", 'type' => 'error']);
        exit;
    }

    $checklist[$key] = ['answer' => $answer, 'remark' => $remark !== '' ? $remark : null];
}

$authUser = authorize('scan.start', ['task_id' => $taskId, 'entity_type' => 'task', 'entity_id' => $taskId]);

$pdo = db();

// Missed Out is a hard cutoff -- see api/scan/lookup.php's identical
// reasoning. An expired ticket simply stops matching here, falling into
// the same generic "not found" as any other invalid task_location_id.
$tlStmt = $pdo->prepare('
    SELECT id, task_id, status, task_date
    FROM ' . T_TASK_LOCATIONS . '
    WHERE id = ? AND task_id = ? AND unassigned_at IS NULL
      AND (status <> \'pending\' OR DATEDIFF(SECOND, CASE WHEN task_date > CAST(assigned_at AS DATE) THEN CAST(task_date AS DATETIME2) ELSE assigned_at END, SYSDATETIME()) < ' . TASK_LOCATION_EXPIRATION_SECONDS . ')
');
$tlStmt->execute([$taskLocationId, $taskId]);
$taskLocation = $tlStmt->fetch();

if (!$taskLocation) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'This location assignment was not found.', 'type' => 'error']);
    exit;
}

$dbTodayStr = $pdo->query('SELECT CONVERT(varchar, CAST(SYSDATETIME() AS DATE), 23)')->fetchColumn();
if (!empty($taskLocation['task_date']) && $taskLocation['task_date'] > $dbTodayStr) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'This location is scheduled for ' . (new DateTime($taskLocation['task_date']))->format('M j, Y') . ' and cannot be started yet.', 'type' => 'error']);
    exit;
}

if ($taskLocation['status'] !== 'pending') {
    echo json_encode(['success' => false, 'message' => 'This location check was already started.', 'type' => 'info']);
    exit;
}

$upd = $pdo->prepare('
    UPDATE ' . T_TASK_LOCATIONS . '
    SET start_time = SYSDATETIME(), scanned_by = ?, status = \'in_progress\',
        spot_spray_answer = ?, spot_spray_remark = ?,
        misting_answer = ?, misting_remark = ?,
        mist_blower_answer = ?, mist_blower_remark = ?,
        monitoring_answer = ?, monitoring_remark = ?,
        findings_observation = ?
    WHERE id = ?
');
$upd->execute([
    $authUser['id'],
    $checklist['spot_spray']['answer'], $checklist['spot_spray']['remark'],
    $checklist['misting']['answer'], $checklist['misting']['remark'],
    $checklist['mist_blower']['answer'], $checklist['mist_blower']['remark'],
    $checklist['monitoring']['answer'], $checklist['monitoring']['remark'],
    $findingsObservation !== '' ? $findingsObservation : null,
    $taskLocationId,
]);

writeAuditLog($authUser['id'], 'scan.start', 'task_location', $taskLocationId, [
    'task_id' => $taskId,
    'checklist' => array_map(fn($c) => $c['answer'], $checklist),
]);

echo json_encode(['success' => true, 'message' => 'Check started! Go ahead and do the work, then come back to submit photos.', 'type' => 'success']);
