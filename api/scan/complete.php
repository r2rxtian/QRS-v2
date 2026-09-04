<?php
/**
 * Completion screen submit (spec Steps 3-4): 1-3 photos required, plus an
 * optional completion remark and a required biometrics confirmation (the
 * completer retypes their Staff/Biometrics Code -- as an e-signature
 * substitute; reports show only the last 3 digits). Marks the location
 * complete.
 *
 * Accepts either the session's employee_id (e.g. "2024-40484" -- what the
 * original 12 real users were given as their "Biometrics Number" and have
 * been typing here already) or the company-wide lrn_master_list.BiometricsID
 * (e.g. "40484" -- a separate, shorter code; not reliably derivable from
 * employee_id, see CA17-3580 -> BiometricsID 3559). A user added later via
 * Settings > User Management only ever knows the latter, since that's the
 * number they were looked up by in Add User -- requiring the former would
 * lock them out of ever completing a task.
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
$remark = sanitizeText($_POST['remark'] ?? '');
$confirmCode = trim($_POST['confirm_code'] ?? '');

if ($taskId <= 0 || $taskLocationId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request.', 'type' => 'error']);
    exit;
}

if ($confirmCode === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please confirm your Staff/Biometrics Code to complete this check.', 'type' => 'error']);
    exit;
}

$photoFiles = $_FILES['photos'] ?? null;
$photoCount = $photoFiles ? count(array_filter($photoFiles['name'])) : 0;
if ($photoCount < 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please attach at least one photo before submitting.', 'type' => 'error']);
    exit;
}
if ($photoCount > UPLOAD_MAX_PHOTOS_PER_SUBMISSION) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please attach at most ' . UPLOAD_MAX_PHOTOS_PER_SUBMISSION . ' photos.', 'type' => 'error']);
    exit;
}

// Validate every photo up front -- all-or-nothing, no partial completion.
for ($i = 0; $i < $photoCount; $i++) {
    $file = [
        'name' => $photoFiles['name'][$i],
        'type' => $photoFiles['type'][$i],
        'tmp_name' => $photoFiles['tmp_name'][$i],
        'error' => $photoFiles['error'][$i],
        'size' => $photoFiles['size'][$i],
    ];
    $error = validateUploadedImage($file);
    if ($error !== null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $error, 'type' => 'error']);
        exit;
    }
}

$authUser = authorize('scan.complete', ['task_id' => $taskId, 'entity_type' => 'task', 'entity_id' => $taskId]);

$pdo = db();

$biometricsStmt = $pdo->prepare('SELECT BiometricsID FROM ' . T_MASTER_LIST . ' WHERE EmployeeID = ?');
$biometricsStmt->execute([$authUser['employee_id']]);
$realBiometricsId = $biometricsStmt->fetchColumn();

$codeMatches = strcasecmp($confirmCode, $authUser['employee_id']) === 0
    || ($realBiometricsId !== false && strcasecmp($confirmCode, $realBiometricsId) === 0);

if (!$codeMatches) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'That Staff/Biometrics Code does not match your account. Please try again.', 'type' => 'error']);
    exit;
}

// Missed Out is a hard cutoff -- see api/scan/lookup.php's identical
// reasoning. Applies even if the check was legitimately started before the
// 24-hour mark: the deadline is 24 hours from assigned_at, not from
// start_time, so work started on time but submitted late still finds
// nothing here and falls into the generic "not found" below -- no special
// Missed Out messaging.
$tlStmt = $pdo->prepare('
    SELECT id, status, task_date
    FROM ' . T_TASK_LOCATIONS . '
    WHERE id = ? AND task_id = ? AND (unassigned_at IS NULL OR unassigned_by IS NULL)
      AND status <> \'missed\'
      AND (status <> \'in_progress\' OR DATEDIFF(SECOND, CASE WHEN task_date > CAST(assigned_at AS DATE) THEN CAST(task_date AS DATETIME2) ELSE assigned_at END, SYSDATETIME()) < ' . TASK_LOCATION_EXPIRATION_SECONDS . ')
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
    echo json_encode(['success' => false, 'message' => 'This location is scheduled for a future date and cannot be completed yet.', 'type' => 'error']);
    exit;
}

if ($taskLocation['status'] === 'pending') {
    echo json_encode(['success' => false, 'message' => 'Please start this check first before submitting photos.', 'type' => 'error']);
    exit;
}

if ($taskLocation['status'] === 'completed') {
    echo json_encode(['success' => false, 'message' => 'This location was already completed.', 'type' => 'info']);
    exit;
}

$storedFiles = [];
for ($i = 0; $i < $photoCount; $i++) {
    $file = [
        'name' => $photoFiles['name'][$i],
        'type' => $photoFiles['type'][$i],
        'tmp_name' => $photoFiles['tmp_name'][$i],
        'error' => $photoFiles['error'][$i],
        'size' => $photoFiles['size'][$i],
    ];
    $stored = storeUploadedImage($file);
    if ($stored === null) {
        // Roll back any files already moved for this request.
        foreach ($storedFiles as $sf) {
            @unlink(UPLOAD_DIR . $sf['stored_filename']);
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to save one of the photos. Please try again.', 'type' => 'error']);
        exit;
    }
    $stored['original_filename'] = $file['name'];
    $storedFiles[] = $stored;
}

$photoInsert = $pdo->prepare('
    INSERT INTO ' . T_TASK_LOCATION_PHOTOS . ' (task_location_id, photo_type, stored_filename, original_filename, mime_type, file_size_bytes, uploaded_by)
    VALUES (?, \'after\', ?, ?, ?, ?, ?)
');
foreach ($storedFiles as $sf) {
    $photoInsert->execute([$taskLocationId, $sf['stored_filename'], $sf['original_filename'], $sf['mime_type'], $sf['file_size_bytes'], $authUser['id']]);
}

$upd = $pdo->prepare('
    UPDATE ' . T_TASK_LOCATIONS . '
    SET end_time = SYSDATETIME(), completed_by = ?, completion_remark = ?, status = \'completed\'
    WHERE id = ?
');
$upd->execute([$authUser['id'], $remark !== '' ? $remark : null, $taskLocationId]);

writeAuditLog($authUser['id'], 'scan.complete', 'task_location', $taskLocationId, [
    'task_id' => $taskId, 'photo_count' => count($storedFiles),
]);

echo json_encode(['success' => true, 'message' => 'Location completed successfully!', 'type' => 'success']);
