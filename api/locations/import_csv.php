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

// Validate the template before touching the database. A UTF-8 BOM on the
// first header is harmless and commonly added by spreadsheet applications,
// but the column names, order, and count must otherwise match the template.
$requiredHeaders = ['Location Name', 'Type'];
$headerRow = fgetcsv($handle, 0, ',');
if ($headerRow === false) {
    fclose($handle);
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'The CSV is empty. Please use the correct location import template.', 'type' => 'error']);
    exit;
}

if (strtolower(pathinfo((string) $_FILES['csv_file']['name'], PATHINFO_EXTENSION)) !== 'csv') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Only .csv files using the location import template are allowed.', 'type' => 'error']);
    exit;
}

$headerRow = array_map('trim', $headerRow);
$headerRow[0] = ltrim($headerRow[0] ?? '', "\xEF\xBB\xBF");
if ($headerRow !== $requiredHeaders) {
    fclose($handle);
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid CSV template. Please use the correct template with these columns in order: Location Name, Type.',
        'type' => 'error',
    ]);
    exit;
}

$pdo = db();
$knownNames = [];
foreach ($pdo->query('SELECT name FROM ' . T_LOCATIONS . ' WHERE deleted_at IS NULL')->fetchAll(PDO::FETCH_COLUMN) as $name) {
    $knownNames[mb_strtolower(normalizeLocationName($name))] = true;
}

$records = [];
$duplicateRows = [];
$validationErrors = [];
$rowNumber = 1;

while (($row = fgetcsv($handle, 0, ',')) !== false) {
    $rowNumber++;

    // Ignore truly empty lines, but reject partially populated records.
    if (count($row) === 1 && trim((string) ($row[0] ?? '')) === '') {
        continue;
    }
    if (count($row) !== count($requiredHeaders)) {
        $validationErrors[] = "Row $rowNumber must contain exactly 2 columns.";
        continue;
    }

    $rawName = $row[0] ?? '';
    $rawType = trim($row[1] ?? '');

    $name = normalizeLocationName($rawName);
    if ($name === '') {
        $validationErrors[] = "Row $rowNumber is missing a location name.";
        continue;
    }
    if (mb_strlen($name) > 150) {
        $validationErrors[] = "Row $rowNumber has a location name longer than 150 characters.";
        continue;
    }

    $key = mb_strtolower($name);
    if (isset($knownNames[$key])) {
        $duplicateRows[] = "row $rowNumber (\"$name\")";
        continue;
    }

    // Type is required and must be either 'Monitoring' or 'Treatment'.
    // Blank values are not accepted and will not default to Treatment.
    if ($rawType === '') {
        $validationErrors[] = "Row $rowNumber is missing a Type; specify Monitoring or Treatment.";
        continue;
    }

    $matchedType = null;
    foreach (TASK_TYPES as $validType) {
        if (strcasecmp($rawType, $validType) === 0) {
            $matchedType = $validType;
            break;
        }
    }
    if ($matchedType === null) {
        $validationErrors[] = "Row $rowNumber has an invalid Type (\"$rawType\"); use Monitoring or Treatment.";
        continue;
    }

    $knownNames[$key] = true;
    $records[] = ['name' => $name, 'type' => $matchedType];
}
fclose($handle);

if ($duplicateRows || $validationErrors || !$records) {
    $sections = [];
    if ($duplicateRows) {
        $sections[] = "Duplicate locations detected:\n• " . implode("\n• ", $duplicateRows) . '.';
    }
    if ($validationErrors) {
        $sections[] = "Validation errors:\n• " . implode("\n• ", $validationErrors);
    }
    if (!$records && !$duplicateRows && !$validationErrors) {
        $sections[] = 'The CSV does not contain any location records.';
    }
    $sections[] = 'No locations were imported. Please correct the file and try again.';

    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => implode("\n\n", $sections),
        'type' => 'error',
    ]);
    exit;
}

$insert = $pdo->prepare('INSERT INTO ' . T_LOCATIONS . ' (name, location_type, qr_token, created_by) VALUES (?, ?, ?, ?)');

try {
    $pdo->beginTransaction();
    foreach ($records as $record) {
        $insert->execute([
            $record['name'],
            $record['type'],
            bin2hex(random_bytes(8)),
            $authUser['id'],
        ]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($e instanceof PDOException && $e->getCode() === '23000') {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Duplicate locations were detected while importing. No locations were imported; refresh the file and try again.',
            'type' => 'error',
        ]);
        exit;
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'The import could not be completed. No locations were imported.',
        'type' => 'error',
    ]);
    exit;
}

$inserted = count($records);
writeAuditLog($authUser['id'], 'location.import_csv', null, null, [
    'inserted' => $inserted,
]);

echo json_encode([
    'success' => true,
    'message' => "$inserted location(s) imported successfully.",
    'type' => 'success',
]);
