<?php
require_once __DIR__ . '/../auth/session.php';
$currentUser = requireLogin();

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../rules/constants.php';
require_once __DIR__ . '/../rules/status.php';
require_once __DIR__ . '/../authz/capabilities.php';
require_once __DIR__ . '/../auth/csrf.php';

$pdo = db();
$isAdmin = $currentUser['role_name'] === ROLE_ADMIN;

// Anchor every "today"/"this month" calculation to the DB server's own
// clock, not PHP's -- they can (and here, do) run in different timezones,
// and every timestamp on screen was set via that server's SYSDATETIME().
$dbNow = new DateTime($pdo->query('SELECT CONVERT(varchar, SYSDATETIME(), 120)')->fetchColumn());

// Task Type filter (Sheet 4 of the client's spec): ?task_type=Monitoring|Treatment,
// empty/absent = both. Folded into the same $taskTypeSql/$taskTypeParams pair
// every query below already uses.
$taskTypeFilter = in_array($_GET['task_type'] ?? '', TASK_TYPES, true) ? $_GET['task_type'] : '';

$taskTypeSql = '';
$taskTypeParams = [];
if ($taskTypeFilter !== '') {
    $taskTypeSql = ' AND t.task_type = ?';
    $taskTypeParams[] = $taskTypeFilter;
}

// Date-only version of $dbNow (set above), used below to exclude tasks
// that are purely scheduled for a future date from every "today" widget
// on this page.
$dbToday = clone $dbNow;
$dbToday->setTime(0, 0);
$windowStartSql = taskLocationWindowStartSql('tl');

// Each task's location aggregates, reused for several
// widgets below. Joins each location to its CURRENT ticket -- the most
// recent task_locations row, as long as it's still on the roster OR was
// auto-unassigned by the system once it resolved (see
// rules/status.php's sweepResolvedLocations() -- unassigned_by IS NULL
// marks that case, distinct from a real manual Unassign). This keeps a
// task's totals/badges accurate even after a completed or missed location
// auto-frees itself; pinning to just the latest one also keeps an older,
// already-resolved ticket (from a genuine manual unassign-and-reassign)
// from being double counted alongside the current one. The missed-hours
// calculations compare against unassigned_at once it's frozen (set),
// instead of the live clock, so "was this missed" stays true permanently
// after auto-unassign rather than flipping back once it's no longer live.
// earliest_active_date lets us tell
// "nothing active because it's genuinely unassigned" (still relevant --
// needs setup) apart from "nothing active because its first cycle is
// scheduled for later" (not relevant yet, filtered out below).
$sql = '
    SELECT t.id, t.name,
           COUNT(tl.id) AS total_locations,
           SUM(CASE WHEN tl.status = \'completed\' THEN 1 ELSE 0 END) AS completed_locations,
           SUM(CASE WHEN tl.status = \'in_progress\' AND SYSDATETIME() >= ' . $windowStartSql . ' AND DATEDIFF(SECOND, ' . $windowStartSql . ', SYSDATETIME()) < ' . TASK_LOCATION_EXPIRATION_SECONDS . ' THEN 1 ELSE 0 END) AS in_progress_locations,
           SUM(CASE WHEN tl.status <> \'completed\' AND SYSDATETIME() >= ' . $windowStartSql . ' AND DATEDIFF(SECOND, ' . $windowStartSql . ', COALESCE(tl.unassigned_at, SYSDATETIME())) >= ' . TASK_LOCATION_EXPIRATION_SECONDS . ' THEN 1 ELSE 0 END) AS missed_locations,
           MAX(CASE WHEN tl.status <> \'completed\' AND SYSDATETIME() >= ' . $windowStartSql . ' AND DATEDIFF(SECOND, ' . $windowStartSql . ', COALESCE(tl.unassigned_at, SYSDATETIME())) >= ' . TASK_LOCATION_EXPIRATION_SECONDS . '
                     AND CAST(DATEADD(SECOND, ' . TASK_LOCATION_EXPIRATION_SECONDS . ', ' . $windowStartSql . ') AS DATE) = CAST(SYSDATETIME() AS DATE)
                THEN 1 ELSE 0 END) AS missed_today_flag,
           MIN(tl.start_time) AS earliest_start,
           MIN(CASE WHEN tl.scheduled_at IS NOT NULL THEN tl.scheduled_at ELSE CAST(tl.task_date AS DATETIME2) END) AS earliest_active_date,
           MIN(tl.scheduled_at) AS earliest_scheduled_at
    FROM ' . T_TASKS . ' t
    LEFT JOIN ' . T_TASK_LOCATIONS . ' tl ON tl.task_id = t.id AND (tl.unassigned_at IS NULL OR tl.unassigned_by IS NULL)
        AND tl.id = (
            SELECT MAX(tl2.id) FROM ' . T_TASK_LOCATIONS . ' tl2
            WHERE tl2.task_id = tl.task_id AND tl2.location_id = tl.location_id
        )
    WHERE t.deleted_at IS NULL' . $taskTypeSql . '
    GROUP BY t.id, t.name
    ORDER BY t.id DESC
';
$stmt = $pdo->prepare($sql);
$stmt->execute($taskTypeParams);
$todaysTasks = [];
foreach ($stmt->fetchAll() as $row) {
    $earliestActiveDate = $row['earliest_active_date'] ? new DateTime($row['earliest_active_date']) : null;
    $isFutureScheduled = $earliestActiveDate !== null && $earliestActiveDate > $dbNow;

    // A task scheduled for a future date isn't part of "today" at all -- it
    // appears under Upcoming Scheduled Tasks instead.
    if ($isFutureScheduled) {
        continue;
    }

    $row['status'] = deriveTaskStatus((int) $row['total_locations'], (int) $row['completed_locations'], (int) $row['in_progress_locations']);
    // "Live" = has at least one location that's neither completed nor missed
    // yet -- still has time left, genuinely still actionable. A task with
    // zero locations at all ("Assign Locations") has no clock running
    // against it either, so it's always live too.
    $row['has_live_location'] = (int) $row['total_locations'] === 0
        || ((int) $row['total_locations'] - (int) $row['completed_locations'] - (int) $row['missed_locations']) > 0;
    $row['missed_today'] = (bool) $row['missed_today_flag'];
    // Same "Missed Out replaces the regular status badge" treatment as
    // Task Manager/All Tasks (see pages/tasks.php) -- used by both Recent
    // Task Reports and Today's Tasks below.
    $row['has_missed'] = (int) $row['missed_locations'] > 0;
    $todaysTasks[] = $row;
}

// "Completed Tasks as of Date" (Sheet 4 of the client's spec, shown as
// completed/total). A task counts here if it's still live (not yet
// completed or missed -- you can still realistically finish it today) OR
// it was completed today. Deliberately excludes anything that's gone
// Missed Out, even if it only missed today -- once it expires it drops out
// of this ratio entirely, unlike Today's Tasks below which still gives a
// freshly-missed task one more day of visibility. Also deliberately NOT
// the same (unbounded, no-expiry) scope as $todaysTasks's own list-display
// purpose further down.
$completedTodaySql = '
    SELECT DISTINCT t.id
    FROM ' . T_TASK_LOCATIONS . ' tl
    JOIN ' . T_TASKS . ' t ON t.id = tl.task_id
    WHERE t.deleted_at IS NULL' . $taskTypeSql . '
      AND tl.end_time IS NOT NULL AND CAST(tl.end_time AS DATE) = CAST(SYSDATETIME() AS DATE)
';
$completedTodayStmt = $pdo->prepare($completedTodaySql);
$completedTodayStmt->execute($taskTypeParams);
$completedTodayTaskIds = array_map('intval', array_column($completedTodayStmt->fetchAll(), 'id'));

$todaysActiveTasks = array_filter($todaysTasks, fn($t) => $t['has_live_location'] || in_array((int) $t['id'], $completedTodayTaskIds, true));
$completedTasksToday = count(array_filter($todaysActiveTasks, fn($t) => $t['status']['code'] === 'completed'));
$totalTasksToday = count($todaysActiveTasks);

// "Today's Tasks" / "Recent Task Reports" (below) share this same list. A
// task belongs here if it still has a live location -- something neither
// completed nor missed yet, still genuinely actionable -- regardless of how
// old it is. Once nothing's live anymore (every location is either
// completed or missed), it only stays visible for the day that final
// outcome happened -- reusing $completedTodayTaskIds (completed today) plus
// missed_today (crossed its 24-hour mark today) -- then quietly drops off.
// Missed is a resolved outcome just like completed, not a permanent
// fixture: it still lives forever in Task Report and the Missed Out tile,
// it just stops cluttering "what needs attention right now" once it's had
// its one day of visibility.
$todaysTasks = array_values(array_filter($todaysTasks, fn($t) => $t['has_live_location']
    || in_array((int) $t['id'], $completedTodayTaskIds, true)
    || $t['missed_today']));

$recentTasks = array_slice($todaysTasks, 0, 4);

// "Current Task" (Sheet 4 of the client's spec: completed/total sub-tasks in
// the current task) -- the task assigned for today, and its locations are
// the sub-tasks. Prefer whichever of today's tasks isn't fully completed
// yet, most recently started; otherwise fall back to the most recently
// created task that has locations assigned today.
// Excludes has_missed the same way tasks.php/qradmin.php exclude it from
// their On-going/Completed tiles: a task with a missed location has nothing
// actually in progress, so it shouldn't win "Current Task" over a genuinely
// active one, or be shown at all once it's the only candidate left.
$tasksWithLocationsToday = array_values(array_filter($todaysTasks, fn($t) => (int) $t['total_locations'] > 0 && !$t['has_missed']));
usort($tasksWithLocationsToday, function ($a, $b) {
    $aActive = $a['status']['code'] !== 'completed' ? 1 : 0;
    $bActive = $b['status']['code'] !== 'completed' ? 1 : 0;
    if ($aActive !== $bActive) {
        return $bActive <=> $aActive;
    }
    return strcmp((string) $b['earliest_start'], (string) $a['earliest_start']);
});
$currentTask = $tasksWithLocationsToday[0] ?? null;
$currentTaskName = $currentTask['name'] ?? null;
$currentTaskCompleted = $currentTask ? (int) $currentTask['completed_locations'] : 0;
$currentTaskTotal = $currentTask ? (int) $currentTask['total_locations'] : 0;


// "Missed Out Tasks" (Sheet 4 of the client's spec): locations still on
// the active roster whose current ticket has been open 24+ hours (from
// assigned_at) without completion -- see countMissedTaskLocations().
$missedStats = countMissedTaskLocations($pdo, $taskTypeFilter !== '' ? $taskTypeFilter : null);

// All-time insight numbers. total_scanned is scoped to
// the still-relevant condition (unassigned_at IS NULL OR unassigned_by IS
// NULL) -- still on the roster, or auto-unassigned once resolved -- so a
// completed/missed location keeps counting toward this all-time total even
// after it auto-frees itself. That filter lives in the JOIN condition, not
// WHERE, so a task whose locations were all later unassigned still counts
// toward total_tasks (it's a real task) instead of disappearing from the
// query entirely because none of its rows would pass a WHERE-clause
// version of the same filter.
$allTimeSql = '
    SELECT
        COUNT(DISTINCT t.id) AS total_tasks,
        SUM(CASE WHEN tl.start_time IS NOT NULL THEN 1 ELSE 0 END) AS total_scanned
    FROM ' . T_TASKS . ' t
    LEFT JOIN ' . T_TASK_LOCATIONS . ' tl ON tl.task_id = t.id AND (tl.unassigned_at IS NULL OR tl.unassigned_by IS NULL)
    WHERE t.deleted_at IS NULL' . $taskTypeSql;
$allTimeStmt = $pdo->prepare($allTimeSql);
$allTimeStmt->execute($taskTypeParams);
$allTime = $allTimeStmt->fetch();
$totalTasksAllTime = (int) $allTime['total_tasks'];
$totalScannedAllTime = (int) $allTime['total_scanned'];

// Task-level completed count for the dome -- counts whole *tasks* where
// every one of their locations is completed, matching deriveTaskStatus()'s
// 'completed' code exactly, so "Total Tasks" and "Completed Tasks" in the
// dome are comparable, same-unit numbers.
$completedTasksSql = '
    SELECT COUNT(*) FROM (
        SELECT t.id
        FROM ' . T_TASKS . ' t
        LEFT JOIN ' . T_TASK_LOCATIONS . ' tl ON tl.task_id = t.id AND (tl.unassigned_at IS NULL OR tl.unassigned_by IS NULL)
        WHERE t.deleted_at IS NULL' . $taskTypeSql . '
        GROUP BY t.id
        HAVING COUNT(tl.id) > 0 AND COUNT(tl.id) = SUM(CASE WHEN tl.status = \'completed\' THEN 1 ELSE 0 END)
    ) x
';
$completedTasksStmt = $pdo->prepare($completedTasksSql);
$completedTasksStmt->execute($taskTypeParams);
$totalCompletedTasksAllTime = (int) $completedTasksStmt->fetchColumn();
$completionRate = $totalTasksAllTime > 0 ? round(($totalCompletedTasksAllTime / $totalTasksAllTime) * 100) : 0;

// Calendar: current month, days with any task activity.
$calMonthStart = new DateTime($dbNow->format('Y-m') . '-01');
$calMonthLabel = $calMonthStart->format('F Y');
$calDaysInMonth = (int) $calMonthStart->format('t');
$calFirstDow = (int) $calMonthStart->format('w'); // 0 = Sunday
$calToday = (int) $dbNow->format('j');

$calSql = '
    SELECT DISTINCT DAY(tl.task_date) AS day_num
    FROM ' . T_TASK_LOCATIONS . ' tl
    JOIN ' . T_TASKS . ' t ON t.id = tl.task_id
    WHERE t.deleted_at IS NULL
      AND YEAR(tl.task_date) = YEAR(SYSDATETIME()) AND MONTH(tl.task_date) = MONTH(SYSDATETIME())' . $taskTypeSql;
$calStmt = $pdo->prepare($calSql);
$calStmt->execute($taskTypeParams);
$calDaysWithTasks = array_map('intval', $calStmt->fetchAll(PDO::FETCH_COLUMN));

// Tasks Overview bar chart: completed locations per month, last 6 months.
$barSql = '
    SELECT YEAR(tl.end_time) AS y, MONTH(tl.end_time) AS m, COUNT(*) AS completed_count
    FROM ' . T_TASK_LOCATIONS . ' tl
    JOIN ' . T_TASKS . ' t ON t.id = tl.task_id
    WHERE t.deleted_at IS NULL AND tl.status = \'completed\'
      AND tl.end_time >= DATEADD(MONTH, -5, DATEFROMPARTS(YEAR(SYSDATETIME()), MONTH(SYSDATETIME()), 1))' . $taskTypeSql . '
    GROUP BY YEAR(tl.end_time), MONTH(tl.end_time)
';
$barStmt = $pdo->prepare($barSql);
$barStmt->execute($taskTypeParams);
$barCountsByYm = [];
foreach ($barStmt->fetchAll() as $row) {
    $barCountsByYm[$row['y'] . '-' . $row['m']] = (int) $row['completed_count'];
}

$barMonths = [];
for ($i = 5; $i >= 0; $i--) {
    $m = (new DateTime('first day of this month'))->modify("-$i months");
    $key = $m->format('Y') . '-' . (int) $m->format('n');
    $barMonths[] = ['label' => $m->format('M'), 'count' => $barCountsByYm[$key] ?? 0];
}
$barMax = max(1, ...array_column($barMonths, 'count'));

// Upcoming Tasks preview -- replaces the old Location Status donut
// (client-requested dashboard refactor). Audit activity turned out to be
// an Admin-only concern (see pages/audit_logs.php, the sidebar nav entry),
// so it has no business previewing on a dashboard both roles share --
// this shows the same thing to Admin and User alike instead: whatever
// tasks have a location scheduled after today, soonest first. Same
// $taskTypeSql/$taskTypeParams task-type filter as every other query on
// this page.
$upcomingSql = '
    SELECT TOP 6 t.id, t.name,
           MIN(CASE WHEN tl.scheduled_at IS NOT NULL THEN tl.scheduled_at ELSE CAST(tl.task_date AS DATETIME2) END) AS next_date,
           MIN(tl.scheduled_at) AS next_scheduled_at,
           COUNT(tl.id) AS location_count
    FROM ' . T_TASKS . ' t
    JOIN ' . T_TASK_LOCATIONS . ' tl ON tl.task_id = t.id
    WHERE t.deleted_at IS NULL
      AND (tl.unassigned_at IS NULL OR tl.unassigned_by IS NULL)
      AND tl.id = (
          SELECT MAX(tl2.id) FROM ' . T_TASK_LOCATIONS . ' tl2
          WHERE tl2.task_id = tl.task_id AND tl2.location_id = tl.location_id
      )
      AND (CASE WHEN tl.scheduled_at IS NOT NULL THEN tl.scheduled_at ELSE CAST(tl.task_date AS DATETIME2) END) > SYSDATETIME()' . $taskTypeSql . '
    GROUP BY t.id, t.name
    ORDER BY next_date ASC
';
$upcomingStmt = $pdo->prepare($upcomingSql);
$upcomingStmt->execute($taskTypeParams);
$upcomingTasks = $upcomingStmt->fetchAll();
$nextUpcomingScheduleSeconds = null;
foreach ($upcomingTasks as $upcomingTask) {
    $upcomingDate = new DateTime($upcomingTask['next_date']);
    $secondsUntilSchedule = max(1, $upcomingDate->getTimestamp() - $dbNow->getTimestamp());
    $nextUpcomingScheduleSeconds = $nextUpcomingScheduleSeconds === null
        ? $secondsUntilSchedule
        : min($nextUpcomingScheduleSeconds, $secondsUntilSchedule);
}

// "Tomorrow" reads better than "Aug 29" for the one date most worth
// calling out specially; anything further off just shows as a plain
// month/day (no year -- this list never reaches far enough ahead for that
// to be ambiguous).
function upcomingDateLabel(DateTime $date, DateTime $today, bool $hasSpecificTime): string
{
    $diffDays = (int) $today->diff($date)->format('%r%a');
    $dateLabel = $diffDays === 1 ? 'Tomorrow' : $date->format('M j');
    return $hasSpecificTime ? $dateLabel . ' ' . $date->format('g:i A') : $dateLabel;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Task Check — Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/app.css?v=29">
    <link rel="stylesheet" href="../styles/dashboard.css?v=22">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    <script src="../scripts/theme.js?v=6"></script>
</head>

<body>
    <?php include '../components/appshell_start.php'; ?>


            <!-- Task Type filter -->
            <div style="display:flex; gap:8px; margin: 0 0 16px;">
                <a href="dashboard.php" class="btn btn-sm <?= $taskTypeFilter === '' ? 'btn-primary' : 'btn-secondary' ?>">All Types</a>
                <?php foreach (TASK_TYPES as $type): ?>
                    <a href="dashboard.php?task_type=<?= urlencode($type) ?>" class="btn btn-sm <?= $taskTypeFilter === $type ? 'btn-primary' : 'btn-secondary' ?>"><?= htmlspecialchars($type) ?></a>
                <?php endforeach; ?>
            </div>

            <!-- Stat tiles -->
            <section class="stat-tiles" data-realtime-region="dashboard-stats">
                <div class="stat-tile">
                    <div class="stat-tile-icon teal"><i class="fas fa-clipboard-list"></i></div>
                    <div>
                        <div class="stat-tile-value"><span class="count-up" data-count-to="<?= $completedTasksToday ?>"><?= $completedTasksToday ?></span> / <span class="count-up" data-count-to="<?= $totalTasksToday ?>"><?= $totalTasksToday ?></span></div>
                        <div class="stat-tile-label">Completed Tasks as of <?= htmlspecialchars($dbNow->format('M j, Y')) ?></div>
                    </div>
                </div>
                <div class="stat-tile" <?= $currentTaskName ? 'title="' . htmlspecialchars($currentTaskName) . '"' : '' ?>>
                    <div class="stat-tile-icon sky"><i class="fas fa-list-check"></i></div>
                    <div>
                        <div class="stat-tile-value"><span class="count-up" data-count-to="<?= $currentTaskCompleted ?>"><?= $currentTaskCompleted ?></span> / <span class="count-up" data-count-to="<?= $currentTaskTotal ?>"><?= $currentTaskTotal ?></span></div>
                        <div class="stat-tile-label">Sub-Tasks in Current Task</div>
                        <div class="stat-tile-sublabel"><?= $currentTaskName ? htmlspecialchars($currentTaskName) : 'No active task today' ?></div>
                    </div>
                </div>
                <div class="stat-tile">
                    <div class="stat-tile-icon" style="background: var(--danger-bg); color: var(--danger-text);"><i class="fas fa-triangle-exclamation"></i></div>
                    <div>
                        <div class="stat-tile-value"><span class="count-up" data-count-to="<?= $missedStats['missed'] ?>"><?= $missedStats['missed'] ?></span> / <span class="count-up" data-count-to="<?= $missedStats['total'] ?>"><?= $missedStats['total'] ?></span></div>
                        <div class="stat-tile-label">Missed Out Tasks</div>
                    </div>
                </div>
            </section>

            <!-- Insights hero (dome) -->
            <section class="card insights-hero" data-realtime-region="dashboard-insights">
                <h2>Task Completion Insights</h2>
                <p class="insights-subtitle">All-time totals across every QR task check you've done.</p>

                <div class="dome-wrap">
                    <div class="dome"></div>

                    <div class="orbit-card orbit-top-left">
                        <span class="orbit-label">Total Tasks</span>
                        <span class="orbit-value count-up" data-count-to="<?= $totalTasksAllTime ?>"><?= $totalTasksAllTime ?></span>
                    </div>

                    <div class="orbit-card orbit-top-right">
                        <span class="orbit-label">Completed Tasks</span>
                        <span class="orbit-value count-up" data-count-to="<?= $totalCompletedTasksAllTime ?>"><?= $totalCompletedTasksAllTime ?></span>
                    </div>

                    <div class="orbit-card orbit-bottom-left">
                        <span class="orbit-label">Missed Out Tasks</span>
                        <span class="orbit-value count-up" data-count-to="<?= $missedStats['missed'] ?>"><?= $missedStats['missed'] ?></span>
                    </div>

                    <div class="orbit-card orbit-bottom-right">
                        <span class="orbit-label">Completion Rate</span>
                        <span class="orbit-value count-up" data-count-to="<?= $completionRate ?>" data-suffix="%"><?= $completionRate ?>%</span>
                    </div>

                    <div class="dome-center">
                        <span class="dome-center-label">Total Locations Scanned</span>
                        <span class="dome-center-value count-up" data-count-to="<?= $totalScannedAllTime ?>"><?= $totalScannedAllTime ?></span>
                    </div>
                </div>
            </section>

            <!-- Bottom grid -->
            <section class="bottom-grid">

                <!-- Tasks Overview -->
                <div class="card panel">
                    <div class="panel-header">
                        <h3>Location Checks Completed</h3>
                        <div class="select-dropdown" data-for="barRangeSelect">
                            <button type="button" class="select-dropdown-trigger" onclick="toggleSelectDropdown(this)">
                                <span class="select-dropdown-label">Monthly</span>
                                <i class="fas fa-chevron-down select-dropdown-caret"></i>
                            </button>
                            <div class="select-dropdown-menu">
                                <div class="select-dropdown-option" data-value="daily">Daily</div>
                                <div class="select-dropdown-option" data-value="weekly">Weekly</div>
                                <div class="select-dropdown-option selected" data-value="monthly">Monthly</div>
                            </div>
                        </div>
                        <select id="barRangeSelect" class="select-dropdown-native" tabindex="-1" aria-hidden="true" onchange="loadBarChart(this.value)">
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly" selected>Monthly</option>
                        </select>
                    </div>
                    <div class="bar-chart" id="barChartContainer">
                        <?php foreach ($barMonths as $bm): ?>
                            <div class="bar-col">
                                <div class="bar" style="height: <?= max(4, round(($bm['count'] / $barMax) * 100)) ?>%;" title="<?= (int) $bm['count'] ?> completed"></div>
                                <span><?= htmlspecialchars($bm['label']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Recent Task Reports -->
                <div class="card panel">
                    <div class="panel-header">
                        <h3>Recent Task Reports</h3>
                        <a href="<?= $isAdmin ? 'tasks.php' : 'qradmin.php' ?>" class="panel-menu" style="text-decoration:none;">View All</a>
                    </div>
                    <div class="recent-list" data-realtime-region="dashboard-recent-tasks">
                        <?php if (empty($recentTasks)): ?>
                            <p style="color: var(--gray-500); font-size: 14px;">No tasks yet.</p>
                        <?php endif; ?>
                        <?php foreach ($recentTasks as $rt): ?>
                            <div class="recent-item">
                                <div class="recent-item-icon"><i class="fas fa-file-lines"></i></div>
                                <div>
                                    <div class="recent-item-name"><?= htmlspecialchars($rt['name']) ?></div>
                                    <div class="recent-item-meta"><?= (int) $rt['total_locations'] ?> location<?= (int) $rt['total_locations'] === 1 ? '' : 's' ?></div>
                                </div>
                                <?php if ($rt['has_missed']): ?>
                                    <span class="status-badge status-missed"><i class="fas fa-triangle-exclamation"></i> Missed Out</span>
                                <?php else: ?>
                                    <span class="status-badge <?= htmlspecialchars($rt['status']['class']) ?>"><?= htmlspecialchars($rt['status']['label']) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            </section>

        </main>

        <!-- Right Rail -->
        <aside class="right-rail">

            <!-- Task Calendar -->
            <div class="card rail-card calendar-card">
                <div class="calendar-header">
                    <span class="calendar-month" id="calendarMonthLabel"><?= htmlspecialchars($calMonthLabel) ?></span>
                    <div class="calendar-nav">
                        <button type="button" class="icon-btn-sm" onclick="navigateCalendar(-1)" title="Previous month"><i class="fas fa-chevron-left"></i></button>
                        <button type="button" class="icon-btn-sm" onclick="navigateCalendar(1)" title="Next month"><i class="fas fa-chevron-right"></i></button>
                    </div>
                </div>
                <div class="calendar-grid" id="calendarGrid" data-month="<?= htmlspecialchars((new DateTime('first day of this month'))->format('Y-m')) ?>">
                    <span class="calendar-dow">S</span>
                    <span class="calendar-dow">M</span>
                    <span class="calendar-dow">T</span>
                    <span class="calendar-dow">W</span>
                    <span class="calendar-dow">T</span>
                    <span class="calendar-dow">F</span>
                    <span class="calendar-dow">S</span>

                    <?php for ($i = 0; $i < $calFirstDow; $i++): ?>
                        <span class="calendar-day muted"></span>
                    <?php endfor; ?>
                    <?php for ($d = 1; $d <= $calDaysInMonth; $d++): ?>
                        <?php
                        $dayClasses = 'calendar-day';
                        if (in_array($d, $calDaysWithTasks, true)) $dayClasses .= ' has-task';
                        if ($d === $calToday) $dayClasses .= ' today';
                        ?>
                        <span class="<?= $dayClasses ?>"><?= $d ?></span>
                    <?php endfor; ?>
                </div>
            </div>

            <!-- Today's Task Timeline -->
            <div class="card rail-card timeline-card">
                <div class="panel-header">
                    <h3>Today's Tasks</h3>
                </div>
                <div class="timeline" data-realtime-region="dashboard-timeline">
                    <?php if (empty($todaysTasks)): ?>
                        <p style="color: var(--gray-500); font-size: 14px;">No tasks assigned today.</p>
                    <?php endif; ?>
                    <?php foreach ($todaysTasks as $tt): ?>
                        <?php
                        $isDone = $tt['status']['code'] === 'completed';
                        $isMissed = $tt['has_missed'];
                        if ($isMissed) {
                            $timeLabel = 'Missed Out';
                        } else {
                            $timeLabel = $tt['earliest_start'] ? (new DateTime($tt['earliest_start']))->format('g:i A') : 'Not started';
                        }
                        $itemClass = $isMissed ? ' missed' : ($isDone ? ' done' : '');
                        $checkIcon = $isMissed ? 'triangle-exclamation' : ($isDone ? 'check' : 'clock');
                        ?>
                        <div class="timeline-item<?= $itemClass ?>">
                            <div class="timeline-dot"></div>
                            <div class="timeline-content">
                                <div class="timeline-time"><?= htmlspecialchars($timeLabel) ?></div>
                                <div class="timeline-title"><?= htmlspecialchars($tt['name']) ?></div>
                                <div class="timeline-meta"><?= (int) $tt['total_locations'] ?> location<?= (int) $tt['total_locations'] === 1 ? '' : 's' ?></div>
                            </div>
                            <button class="timeline-check"><i class="fas fa-<?= $checkIcon ?>"></i></button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Upcoming Tasks (replaces the old Location Status donut --
                 Recent Activity briefly lived here instead, but that's an
                 Admin-only concern (see pages/audit_logs.php), and Admin
                 and User share this same dashboard. This is role-neutral. -->
            <div class="card rail-card upcoming-card">
                <div class="panel-header">
                    <h3>Upcoming Tasks</h3>
                </div>
                <div class="recent-list upcoming-list" data-realtime-region="dashboard-upcoming" data-scheduled-wake-seconds="<?= $nextUpcomingScheduleSeconds !== null ? (int) $nextUpcomingScheduleSeconds : 0 ?>">
                    <?php if (empty($upcomingTasks)): ?>
                        <p style="color: var(--gray-500); font-size: 14px;">Nothing scheduled ahead yet.</p>
                    <?php endif; ?>
                    <?php foreach ($upcomingTasks as $ut):
                        $nextDate = new DateTime($ut['next_date']);
                        $locationCount = (int) $ut['location_count'];
                        ?>
                        <div class="recent-item upcoming-item">
                            <div class="recent-item-icon"><i class="fas fa-calendar-days"></i></div>
                            <div>
                                <div class="recent-item-name"><?= htmlspecialchars($ut['name']) ?></div>
                                <div class="recent-item-meta"><?= $locationCount ?> location<?= $locationCount === 1 ? '' : 's' ?></div>
                            </div>
                            <span class="upcoming-item-date"><?= htmlspecialchars(upcomingDateLabel($nextDate, $dbToday, !empty($ut['next_scheduled_at']))) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </aside>
    </div>

    <script>
    const QRS_CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;
    window.QRS_SESSION_CONFIG = <?= json_encode([
        'idleTimeoutMs' => SESSION_IDLE_TIMEOUT_MINUTES * 60 * 1000,
        'heartbeatIntervalMs' => SESSION_TOKEN_REFRESH_MINUTES * 60 * 1000,
        'warningBeforeMs' => 60 * 1000,
    ]) ?>;
    </script>
    <script src="../scripts/sidebar-drawer.js"></script>
    <script src="../scripts/select-dropdown.js"></script>
    <script src="../scripts/motion.js"></script>
    <script src="../scripts/session-guard.js"></script>
    <script src="../scripts/dashboard.js?v=2"></script>
    <script src="../scripts/logout-confirm.js"></script>
</body>

</html>
