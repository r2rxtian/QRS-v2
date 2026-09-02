<?php
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../auth/csrf.php';
require_once __DIR__ . '/../../conn/db.php';
require_once __DIR__ . '/../../rules/constants.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

csrfVerify();
requireLogin(true);

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
$placeholders = implode(',', array_fill(0, count($ids), '?'));

// The client cannot expire a ticket early: elapsed time is checked again
// against the database server's clock inside the UPDATE.
$sql = '
    UPDATE ' . T_TASK_LOCATIONS . '
    SET status = \'missed\',
        unassigned_at = COALESCE(unassigned_at, SYSDATETIME()),
        unassigned_by = NULL,
        updated_at = SYSDATETIME()
    OUTPUT INSERTED.id, INSERTED.task_id, INSERTED.location_id
    WHERE id IN (' . $placeholders . ')
      AND unassigned_at IS NULL
      AND status IN (\'pending\', \'in_progress\')
      AND DATEDIFF(SECOND, assigned_at, SYSDATETIME()) >= 86400
';

$stmt = $pdo->prepare($sql);
$stmt->execute($ids);
$expired = $stmt->fetchAll();

echo json_encode([
    'success' => true,
    'expired' => array_map(static fn($row) => [
        'task_location_id' => (int) $row['id'],
        'task_id' => (int) $row['task_id'],
        'location_id' => (int) $row['location_id'],
    ], $expired),
]);
