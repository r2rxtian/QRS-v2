<?php
/**
 * CLI-only importer for the client's real location catalog (from
 * "QR Task Check_08132026.xlsx", Sheet 3) -> dbo.qrs_locations, tagged
 * with location_type. Run once, after migration 0002 (which adds the
 * location_type column): php sql/import_real_locations.php
 *
 * Two source files, one per Task Type:
 *  - sql/locations_monitoring.csv -> location_type = 'Monitoring'
 *    (numbered pest monitoring stations/traps, e.g. "...ILT #1...")
 *  - sql/locations_treatment.csv  -> location_type = 'Treatment'
 *    (open treatment areas/rooms, e.g. "PESTCON-Treatment, P1, Egg Room")
 *
 * Reuses the same normalization/dedup approach as sql/import_locations.php:
 * whitespace (including embedded newlines from the source spreadsheet) is
 * collapsed to single spaces; only exact (trimmed, case-insensitive)
 * duplicate names are skipped -- near-duplicate typos are left as-is for
 * manual cleanup via manage_locations.php.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script is CLI-only.');
}

require_once __DIR__ . '/../conn/db.php';

function normalizeLocationName(string $raw): string
{
    return trim(preg_replace('/\s+/', ' ', $raw));
}

function importFile(PDO $pdo, PDOStatement $insert, array &$existing, string $csvPath, string $locationType, int $adminId): array
{
    $handle = fopen($csvPath, 'r');
    if ($handle === false) {
        fwrite(STDERR, "Could not open $csvPath\n");
        exit(1);
    }

    $inserted = 0;
    $duplicates = 0;
    $blank = 0;

    while (($row = fgetcsv($handle, 0, ',')) !== false) {
        $rawName = $row[0] ?? '';
        $rawName = ltrim($rawName, "\xEF\xBB\xBF"); // strip possible UTF-8 BOM

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
        $insert->execute([$name, $qrToken, $adminId, $locationType]);

        $existing[$key] = true;
        $inserted++;
    }

    fclose($handle);
    return [$inserted, $duplicates, $blank];
}

$pdo = db();

// Prefer a real Admin-role user as the created_by owner. Explicitly
// excludes EMP* test accounts -- a prior version of this query picked
// whichever Admin-role user had the lowest id with no such exclusion,
// which silently attributed the entire real location catalog to the old
// EMP0001 mockup test account (it got the Admin role too, after migration
// 0004 remapped every role) and caused it to be swept up by a later
// EMP*-scoped test-data purge. Never repeat that.
$adminId = $pdo->query("
    SELECT TOP 1 u.id FROM dbo.qrs_users u
    JOIN dbo.qrs_roles r ON r.id = u.role_id
    WHERE r.name = 'Admin' AND u.employee_id NOT LIKE 'EMP%'
    ORDER BY u.id
")->fetchColumn();
if (!$adminId) {
    fwrite(STDERR, "No non-test Admin-role user found -- run sql/seed.sql first.\n");
    exit(1);
}

$existing = [];
foreach ($pdo->query('SELECT name FROM dbo.qrs_locations')->fetchAll(PDO::FETCH_COLUMN) as $name) {
    $existing[mb_strtolower(normalizeLocationName($name))] = true;
}

$insert = $pdo->prepare('INSERT INTO dbo.qrs_locations (name, qr_token, created_by, location_type) VALUES (?, ?, ?, ?)');

[$mIns, $mDup, $mBlank] = importFile($pdo, $insert, $existing, __DIR__ . '/locations_monitoring.csv', 'Monitoring', (int) $adminId);
[$tIns, $tDup, $tBlank] = importFile($pdo, $insert, $existing, __DIR__ . '/locations_treatment.csv', 'Treatment', (int) $adminId);

echo "Monitoring: $mIns inserted, $mDup duplicates skipped, $mBlank blank rows skipped.\n";
echo "Treatment:  $tIns inserted, $tDup duplicates skipped, $tBlank blank rows skipped.\n";
echo "Total inserted: " . ($mIns + $tIns) . "\n";
