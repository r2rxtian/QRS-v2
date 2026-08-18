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

$authUser = authorize('location.create');

$name = preg_replace('/\s+/', ' ', sanitizeText($_POST['location_name'] ?? ''));
$locationType = trim($_POST['location_type'] ?? '');

if ($name === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter a location name.', 'type' => 'error']);
    exit;
}

if (!in_array($locationType, TASK_TYPES, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please select a location type.', 'type' => 'error']);
    exit;
}

$pdo = db();

$dupeCheck = $pdo->prepare('SELECT id FROM ' . T_LOCATIONS . ' WHERE LOWER(name) = LOWER(?) AND deleted_at IS NULL');
$dupeCheck->execute([$name]);
if ($dupeCheck->fetchColumn()) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'A location with that name already exists.', 'type' => 'error']);
    exit;
}

$qrToken = bin2hex(random_bytes(8));
$stmt = $pdo->prepare('INSERT INTO ' . T_LOCATIONS . ' (name, qr_token, created_by, location_type) OUTPUT INSERTED.id VALUES (?, ?, ?, ?)');
$stmt->execute([$name, $qrToken, $authUser['id'], $locationType]);
$locationId = (int) $stmt->fetchColumn();

writeAuditLog($authUser['id'], 'location.create', 'location', $locationId, $authUser['department_id'], ['name' => $name, 'location_type' => $locationType]);

echo json_encode(['success' => true, 'message' => 'Location added successfully!', 'type' => 'success', 'data' => ['location_id' => $locationId]]);
