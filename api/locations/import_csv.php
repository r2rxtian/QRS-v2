<?php
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

$authUser = authorize('location.import_csv');

if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please choose a CSV file to upload.', 'type' => 'error']);
    exit;
}

$handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
if ($handle === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Could not read the uploaded file.', 'type' => 'error']);
    exit;
}

function normalizeLocationName(string $raw): string
{
    return trim(preg_replace('/\s+/', ' ', $raw));
}

$pdo = db();
$existing = [];
foreach ($pdo->query('SELECT name FROM ' . T_LOCATIONS . ' WHERE deleted_at IS NULL')->fetchAll(PDO::FETCH_COLUMN) as $name) {
    $existing[mb_strtolower(normalizeLocationName($name))] = true;
}

$insert = $pdo->prepare('INSERT INTO ' . T_LOCATIONS . ' (name, qr_token, created_by) VALUES (?, ?, ?)');

$inserted = 0;
$duplicates = 0;
$blank = 0;
$rowNum = 0;

while (($row = fgetcsv($handle, 0, ',')) !== false) {
    $rowNum++;
    $rawName = $row[0] ?? '';

    if ($rowNum === 1) {
        $rawName = ltrim($rawName, "\xEF\xBB\xBF");
        if (strcasecmp(trim($rawName), 'Location Name') === 0) {
            continue; // header row
        }
    }

    $name = normalizeLocationName($rawName);
    if ($name === '') {
        $blank++;
        continue;
    }

    $key = mb_strtolower($name);
    if (isset($existing[$key])) {
        $duplicates++;
        continue;
    }

    $qrToken = bin2hex(random_bytes(8));
    $insert->execute([$name, $qrToken, $authUser['id']]);
    $existing[$key] = true;
    $inserted++;
}
fclose($handle);

writeAuditLog($authUser['id'], 'location.import_csv', null, null, $authUser['department_id'], ['inserted' => $inserted, 'duplicates' => $duplicates, 'blank' => $blank]);

echo json_encode([
    'success' => true,
    'message' => "CSV processed: $inserted inserted, $duplicates duplicates skipped, $blank blank rows skipped.",
    'type' => 'success',
]);
