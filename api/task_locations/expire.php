<?php
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../auth/csrf.php';
require_once __DIR__ . '/../../conn/db.php';
require_once __DIR__ . '/../../rules/constants.php';
require_once __DIR__ . '/../../rules/status.php';
require_once __DIR__ . '/../../authz/audit.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

csrfVerify();
$authUser = requireLogin(true);

$ids = $_POST['task_location_ids'] ?? [];
$ids = is_array($ids)
    ? array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)))
    : [];

if (empty($ids) || count($ids) > 100) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No valid task locations supplied.']);
    exit;
}

$pdo = db();

// The shared transition rechecks elapsed time against SQL Server's clock, so
// a browser can never force an assignment to expire early.
$expired = expireDueTaskLocations($pdo, $ids);

if ($expired) {
    writeAuditLog($authUser['id'], 'task_location.expire', 'task_location', null, [
        'task_location_ids' => array_column($expired, 'id'),
    ]);
}

echo json_encode([
    'success' => true,
    'expired' => array_map(static fn($row) => [
        'task_location_id' => $row['id'],
        'task_id' => $row['task_id'],
        'location_id' => $row['location_id'],
    ], $expired),
]);
