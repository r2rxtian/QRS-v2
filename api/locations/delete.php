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

$locationId = (int) ($_POST['location_id'] ?? 0);
if ($locationId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid location.', 'type' => 'error']);
    exit;
}

$authUser = authorize('location.delete');

if (!checkRateLimit('delete:location:' . $authUser['id'], 30, 60)) {
    respondRateLimited();
}

$pdo = db();

$locStmt = $pdo->prepare('SELECT id, name FROM ' . T_LOCATIONS . ' WHERE id = ? AND deleted_at IS NULL');
$locStmt->execute([$locationId]);
$location = $locStmt->fetch();

if (!$location) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Location not found.', 'type' => 'error']);
    exit;
}

$activeCheck = $pdo->prepare('SELECT COUNT(*) FROM ' . T_TASK_LOCATIONS . ' WHERE location_id = ? AND unassigned_at IS NULL');
$activeCheck->execute([$locationId]);
if ((int) $activeCheck->fetchColumn() > 0) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'This location cannot be deleted because it is currently assigned to an active task.', 'type' => 'error']);
    exit;
}

$pdo->prepare('UPDATE ' . T_LOCATIONS . ' SET deleted_at = SYSDATETIME() WHERE id = ?')->execute([$locationId]);

writeAuditLog($authUser['id'], 'location.delete', 'location', $locationId, ['name' => $location['name']]);

echo json_encode(['success' => true, 'message' => 'Location deleted.', 'type' => 'success']);
