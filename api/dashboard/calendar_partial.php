<?php
/**
 * Dashboard calendar month partial -- returns the day grid + month label
 * for an arbitrary month, so the dashboard's prev/next arrows can page
 * through months without a full page reload. GET, read-only, no CSRF
 * (matches api/tasks/detail_partial.php's convention).
 */
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../conn/db.php';
require_once __DIR__ . '/../../rules/constants.php';

requireLogin(true);

header('Content-Type: application/json');

$pdo = db();

// Anchor "today" to the DB server's own clock, not PHP's -- they can (and
// here, do) run in different timezones, and task_date values are set via
// SYSDATETIME() on that server.
$dbNow = new DateTime($pdo->query('SELECT CONVERT(varchar, SYSDATETIME(), 120)')->fetchColumn());

$monthParam = $_GET['month'] ?? '';
if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $monthParam, $m)) {
    $year = (int) $m[1];
    $month = (int) $m[2];
} else {
    $year = (int) $dbNow->format('Y');
    $month = (int) $dbNow->format('n');
}

// Clamp to a sane range -- no legitimate reason for a QR task app's
// calendar to page more than a few years either direction.
$currentYear = (int) $dbNow->format('Y');
$year = max($currentYear - 5, min($currentYear + 5, $year));

$monthStart = DateTime::createFromFormat('Y-n-j', $year . '-' . $month . '-1');
$monthLabel = $monthStart->format('F Y');
$daysInMonth = (int) $monthStart->format('t');
$firstDow = (int) $monthStart->format('w');

$isCurrentMonth = ((int) $dbNow->format('Y') === $year && (int) $dbNow->format('n') === $month);
$todayDay = $isCurrentMonth ? (int) $dbNow->format('j') : 0;
$sql = '
    SELECT DISTINCT DAY(tl.task_date) AS day_num
    FROM ' . T_TASK_LOCATIONS . ' tl
    JOIN ' . T_TASKS . ' t ON t.id = tl.task_id
    WHERE t.deleted_at IS NULL AND YEAR(tl.task_date) = ? AND MONTH(tl.task_date) = ?';
$params = [$year, $month];
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$daysWithTasks = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

ob_start();
?>
<span class="calendar-dow">S</span>
<span class="calendar-dow">M</span>
<span class="calendar-dow">T</span>
<span class="calendar-dow">W</span>
<span class="calendar-dow">T</span>
<span class="calendar-dow">F</span>
<span class="calendar-dow">S</span>
<?php for ($i = 0; $i < $firstDow; $i++): ?>
    <span class="calendar-day muted"></span>
<?php endfor; ?>
<?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
    <?php
    $dayClasses = 'calendar-day';
    if (in_array($d, $daysWithTasks, true)) {
        $dayClasses .= ' has-task';
    }
    if ($d === $todayDay) {
        $dayClasses .= ' today';
    }
    ?>
    <span class="<?= $dayClasses ?>"><?= $d ?></span>
<?php endfor; ?>
<?php
$gridHtml = ob_get_clean();

echo json_encode([
    'success' => true,
    'data' => [
        'month_label' => $monthLabel,
        'month_key' => sprintf('%04d-%02d', $year, $month),
        'grid_html' => $gridHtml,
    ],
]);
