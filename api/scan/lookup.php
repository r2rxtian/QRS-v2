<?php
/**
 * Resolves a scanned/manually-entered QR token against today's roster for
 * a task, and tells the client which screen to show next:
 *   'method_selection' -- pending, not started yet
 *   'completion'        -- in_progress, resume to the after-photos step
 *   'completed'          -- already done today
 */
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

$taskId = (int) ($_POST['task_id'] ?? 0);
$qrToken = trim($_POST['qr_token'] ?? '');
$manualLocationId = (int) ($_POST['location_id'] ?? 0);

if ($taskId <= 0 || ($qrToken === '' && $manualLocationId <= 0)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please scan a QR code or choose a location.', 'type' => 'error']);
    exit;
}

authorize('scan.start', ['task_id' => $taskId, 'entity_type' => 'task', 'entity_id' => $taskId]);

$pdo = db();

if ($qrToken !== '') {
    $locStmt = $pdo->prepare('SELECT id, name FROM ' . T_LOCATIONS . ' WHERE qr_token = ? AND deleted_at IS NULL AND is_active = 1');
    $locStmt->execute([$qrToken]);
} else {
    // Manual fallback: identified by location_id (tapped from today's roster
    // list), never by a free-typed name -- keeps the same qr_token-based
    // identity model, just a different, accessible way to select it.
    $locStmt = $pdo->prepare('SELECT id, name FROM ' . T_LOCATIONS . ' WHERE id = ? AND deleted_at IS NULL AND is_active = 1');
    $locStmt->execute([$manualLocationId]);
}
$location = $locStmt->fetch();

if (!$location) {
    echo json_encode(['success' => false, 'message' => 'That QR code was not recognized.', 'type' => 'error']);
    exit;
}

$tlStmt = $pdo->prepare('
    SELECT id, status,
           spot_spray_answer, spot_spray_remark,
           misting_answer, misting_remark,
           mist_blower_answer, mist_blower_remark,
           monitoring_answer, monitoring_remark,
           findings_observation
    FROM ' . T_TASK_LOCATIONS . '
    WHERE task_id = ? AND location_id = ? AND task_date = CAST(SYSDATETIME() AS DATE) AND unassigned_at IS NULL
');
$tlStmt->execute([$taskId, $location['id']]);
$taskLocation = $tlStmt->fetch();

if (!$taskLocation) {
    echo json_encode(['success' => false, 'message' => $location['name'] . ' is not assigned to this task today.', 'type' => 'error']);
    exit;
}

$stage = match ($taskLocation['status']) {
    'pending' => 'method_selection',
    'in_progress' => 'completion',
    default => 'completed',
};

echo json_encode([
    'success' => true,
    'message' => $stage === 'completed' ? $location['name'] . ' is already completed for today.' : 'Location recognized.',
    'type' => $stage === 'completed' ? 'info' : 'success',
    'data' => [
        'stage' => $stage,
        'task_location_id' => (int) $taskLocation['id'],
        'location_name' => $location['name'],
        'checklist' => [
            'spot_spray' => ['answer' => $taskLocation['spot_spray_answer'], 'remark' => $taskLocation['spot_spray_remark']],
            'misting' => ['answer' => $taskLocation['misting_answer'], 'remark' => $taskLocation['misting_remark']],
            'mist_blower' => ['answer' => $taskLocation['mist_blower_answer'], 'remark' => $taskLocation['mist_blower_remark']],
            'monitoring' => ['answer' => $taskLocation['monitoring_answer'], 'remark' => $taskLocation['monitoring_remark']],
        ],
        'findings_observation' => $taskLocation['findings_observation'],
    ],
]);
