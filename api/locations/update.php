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

$locationId = (int) ($_POST['location_id'] ?? 0);
if ($locationId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid location.', 'type' => 'error']);
    exit;
}

$authUser = authorize('location.update');

$name = preg_replace('/\s+/', ' ', sanitizeText($_POST['location_name'] ?? ''));

if ($name === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter a location name.', 'type' => 'error']);
    exit;
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

// Same case-insensitive uniqueness rule as create.php, just excluding this
// location's own row so saving a name back unchanged doesn't trip it.
$dupeCheck = $pdo->prepare('SELECT id FROM ' . T_LOCATIONS . ' WHERE LOWER(name) = LOWER(?) AND id != ? AND deleted_at IS NULL');
$dupeCheck->execute([$name, $locationId]);
if ($dupeCheck->fetchColumn()) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'A location with that name already exists.', 'type' => 'error']);
    exit;
}

$pdo->prepare('UPDATE ' . T_LOCATIONS . ' SET name = ? WHERE id = ?')->execute([$name, $locationId]);

writeAuditLog($authUser['id'], 'location.update', 'location', $locationId, ['old_name' => $location['name'], 'new_name' => $name]);

echo json_encode(['success' => true, 'message' => 'Location updated successfully!', 'type' => 'success', 'data' => [
    'location_id' => $locationId,
    'name' => $name,
]]);
