<?php
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../authz/authz.php';
require_once __DIR__ . '/../../conn/db.php';
require_once __DIR__ . '/../../rules/constants.php';

authorize('location.export_csv');

$stmt = db()->query('
    SELECT name, location_type
    FROM ' . T_LOCATIONS . '
    WHERE deleted_at IS NULL AND is_active = 1
    ORDER BY name
');

$filename = 'locations_' . date('Y-m-d_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$output = fopen('php://output', 'w');
// The BOM keeps location names readable when the CSV is opened in Excel.
fwrite($output, "\xEF\xBB\xBF");
fputcsv($output, ['Location Name', 'Type']);

while ($location = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($output, [$location['name'], $location['location_type']]);
}

fclose($output);
exit;
