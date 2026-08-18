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

$locationIds = array_values(array_unique(array_filter(array_map('intval', $_POST['location_ids'] ?? []), fn($id) => $id > 0)));
if (empty($locationIds)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No locations selected.', 'type' => 'error']);
    exit;
}

$authUser = authorize('location.delete');

if (!checkRateLimit('delete:location:' . $authUser['id'], 30, 60)) {
    respondRateLimited();
}

$pdo = db();
$placeholders = implode(',', array_fill(0, count($locationIds), '?'));

$locStmt = $pdo->prepare('SELECT id, name FROM ' . T_LOCATIONS . " WHERE id IN ($placeholders) AND deleted_at IS NULL");
$locStmt->execute($locationIds);
$namesById = [];
foreach ($locStmt->fetchAll() as $row) {
    $namesById[(int) $row['id']] = $row['name'];
}

$activeStmt = $pdo->prepare('SELECT DISTINCT location_id FROM ' . T_TASK_LOCATIONS . " WHERE location_id IN ($placeholders) AND unassigned_at IS NULL");
$activeStmt->execute($locationIds);
$activeIds = array_map('intval', array_column($activeStmt->fetchAll(), 'location_id'));

$deletable = [];
foreach ($locationIds as $id) {
    if (isset($namesById[$id]) && !in_array($id, $activeIds, true)) {
        $deletable[] = $id;
    }
}

if (empty($deletable)) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'None of the selected locations can be deleted — they are all currently assigned to an active task.', 'type' => 'error']);
    exit;
}

$delPlaceholders = implode(',', array_fill(0, count($deletable), '?'));
$pdo->prepare('UPDATE ' . T_LOCATIONS . " SET deleted_at = SYSDATETIME() WHERE id IN ($delPlaceholders)")->execute($deletable);

foreach ($deletable as $id) {
    writeAuditLog($authUser['id'], 'location.delete', 'location', $id, $authUser['department_id'], ['name' => $namesById[$id]]);
}

$skipped = count($locationIds) - count($deletable);
$message = count($deletable) . ' location(s) deleted.';
if ($skipped > 0) {
    $message .= ' ' . $skipped . ' skipped (currently assigned to an active task).';
}

echo json_encode(['success' => true, 'message' => $message, 'type' => 'success']);
