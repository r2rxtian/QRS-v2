<?php
/**
 * Dashboard "Tasks Overview" bar chart partial -- completed-location counts
 * bucketed by day/week/month, so the Daily/Weekly/Monthly select can swap
 * the chart without a full page reload. GET, read-only, no CSRF (matches
 * api/tasks/detail_partial.php's convention). Department-scoped like every
 * other dashboard query.
 */
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../conn/db.php';
require_once __DIR__ . '/../../rules/constants.php';
require_once __DIR__ . '/../../authz/capabilities.php';

$currentUser = requireLogin(true);
$isAdmin = $currentUser['role_name'] === ROLE_ADMIN;

header('Content-Type: application/json');

$range = $_GET['range'] ?? 'monthly';
if (!in_array($range, ['daily', 'weekly', 'monthly'], true)) {
    $range = 'monthly';
}

function isoWeekStart(DateTime $dt): DateTime
{
    $d = clone $dt;
    $dow = (int) $d->format('N'); // 1 (Mon) .. 7 (Sun)
    $d->modify('-' . ($dow - 1) . ' days');
    $d->setTime(0, 0, 0);
    return $d;
}

$pdo = db();

// Anchor every date calculation to the DB server's own clock, not PHP's --
// they can (and here, do) run in different timezones, and comparing a
// PHP-local "now" against DB timestamps set via SYSDATETIME() silently
// skews the window boundaries.
$dbNow = new DateTime($pdo->query('SELECT CONVERT(varchar, SYSDATETIME(), 120)')->fetchColumn());

$bucketCount = 6;
switch ($range) {
    case 'daily':
        $bucketCount = 7;
        $windowStart = (clone $dbNow)->modify('-' . ($bucketCount - 1) . ' days')->setTime(0, 0, 0);
        break;
    case 'weekly':
        $bucketCount = 6;
        $windowStart = isoWeekStart($dbNow)->modify('-' . ($bucketCount - 1) . ' weeks');
        break;
    default: // monthly
        $bucketCount = 6;
        $windowStart = (new DateTime($dbNow->format('Y-m') . '-01'))->modify('-' . ($bucketCount - 1) . ' months');
}
$sql = '
    SELECT tl.end_time
    FROM ' . T_TASK_LOCATIONS . ' tl
    JOIN ' . T_TASKS . ' t ON t.id = tl.task_id
    WHERE t.deleted_at IS NULL AND tl.status = \'completed\' AND tl.end_time >= ?';
$params = [$windowStart->format('Y-m-d H:i:s')];
if (!$isAdmin) {
    $sql .= ' AND t.department_id = ?';
    $params[] = $currentUser['department_id'];
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$buckets = [];
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $endTime) {
    $dt = new DateTime($endTime);
    switch ($range) {
        case 'daily':
            $key = $dt->format('Y-m-d');
            break;
        case 'weekly':
            $key = isoWeekStart($dt)->format('Y-m-d');
            break;
        default:
            $key = $dt->format('Y-n');
    }
    $buckets[$key] = ($buckets[$key] ?? 0) + 1;
}

$bars = [];
for ($i = $bucketCount - 1; $i >= 0; $i--) {
    switch ($range) {
        case 'daily':
            $d = (clone $dbNow)->modify("-$i days");
            $key = $d->format('Y-m-d');
            $label = $d->format('D');
            break;
        case 'weekly':
            $weekStart = isoWeekStart($dbNow)->modify("-$i weeks");
            $key = $weekStart->format('Y-m-d');
            $label = $weekStart->format('M j');
            break;
        default:
            $m = (new DateTime($dbNow->format('Y-m') . '-01'))->modify("-$i months");
            $key = $m->format('Y-n');
            $label = $m->format('M');
    }
    $bars[] = ['label' => $label, 'count' => $buckets[$key] ?? 0];
}

$max = max(1, ...array_column($bars, 'count'));
foreach ($bars as &$bar) {
    $bar['height'] = max(4, round(($bar['count'] / $max) * 100));
}
unset($bar);

echo json_encode([
    'success' => true,
    'data' => [
        'range' => $range,
        'bars' => $bars,
    ],
]);
