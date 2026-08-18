<?php
require_once __DIR__ . '/../auth/session.php';
$currentUser = requireLogin();

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../rules/constants.php';
require_once __DIR__ . '/../rules/status.php';
require_once __DIR__ . '/../authz/capabilities.php';

$pdo = db();
$isAdmin = $currentUser['role_name'] === ROLE_ADMIN;

// Anchor every "today"/"this month" calculation to the DB server's own
// clock, not PHP's -- they can (and here, do) run in different timezones,
// and every timestamp on screen was set via that server's SYSDATETIME().
$dbNow = new DateTime($pdo->query('SELECT CONVERT(varchar, SYSDATETIME(), 120)')->fetchColumn());

// Task Type filter (Sheet 4 of the client's spec): ?task_type=Monitoring|Treatment,
// empty/absent = both. Folded into the same $taskDeptFilter/$taskDeptParams
// pair every query below already uses for department scoping.
$taskTypeFilter = in_array($_GET['task_type'] ?? '', TASK_TYPES, true) ? $_GET['task_type'] : '';

$taskDeptFilter = '';
$taskDeptParams = [];
if (!$isAdmin) {
    $taskDeptFilter = ' AND t.department_id = ?';
    $taskDeptParams[] = $currentUser['department_id'];
}
if ($taskTypeFilter !== '') {
    $taskDeptFilter .= ' AND t.task_type = ?';
    $taskDeptParams[] = $taskTypeFilter;
}

// Date-only version of $dbNow (set above), used below to exclude tasks
// that are purely scheduled for a future date from every "today" widget
// on this page.
$dbToday = clone $dbNow;
$dbToday->setTime(0, 0);

// Today's per-task location aggregates (department-scoped), reused for
// several widgets below. earliest_active_date lets us tell "nothing
// assigned today because it's genuinely unassigned" (still relevant --
// needs setup) apart from "nothing assigned today because it's scheduled
// for later" (not relevant to today at all, filtered out below).
$sql = '
    SELECT t.id, t.name,
           COUNT(tl.id) AS total_locations,
           SUM(CASE WHEN tl.status = \'completed\' THEN 1 ELSE 0 END) AS completed_locations,
           SUM(CASE WHEN tl.status = \'in_progress\' THEN 1 ELSE 0 END) AS in_progress_locations,
           MIN(tl.start_time) AS earliest_start,
           (SELECT MIN(tl2.task_date) FROM ' . T_TASK_LOCATIONS . ' tl2
               WHERE tl2.task_id = t.id AND tl2.unassigned_at IS NULL) AS earliest_active_date
    FROM ' . T_TASKS . ' t
    LEFT JOIN ' . T_TASK_LOCATIONS . ' tl ON tl.task_id = t.id AND tl.task_date = CAST(SYSDATETIME() AS DATE) AND tl.unassigned_at IS NULL
    WHERE t.deleted_at IS NULL' . $taskDeptFilter . '
    GROUP BY t.id, t.name
    ORDER BY t.id DESC
';
$stmt = $pdo->prepare($sql);
$stmt->execute($taskDeptParams);
$todaysTasks = [];
foreach ($stmt->fetchAll() as $row) {
    $earliestActiveDate = $row['earliest_active_date'] ? new DateTime($row['earliest_active_date']) : null;
    $isFutureScheduled = $earliestActiveDate !== null && $earliestActiveDate > $dbToday;

    // A task with nothing active today AND its only activity is scheduled
    // for a future date isn't part of "today" at all -- skip it entirely
    // rather than let it show up as a bogus 0/0 "Assign Locations" row.
    if ((int) $row['total_locations'] === 0 && $isFutureScheduled) {
        continue;
    }

    $row['status'] = deriveTaskStatus((int) $row['total_locations'], (int) $row['completed_locations'], (int) $row['in_progress_locations']);
    $todaysTasks[] = $row;
}

// "Completed Tasks as of Date" (Sheet 4 of the client's spec, shown as
// completed/total): counted over today's tasks, same set/scope as the rest
// of this page's "today" widgets.
$completedTasksToday = count(array_filter($todaysTasks, fn($t) => $t['status']['code'] === 'completed'));
$totalTasksToday = count($todaysTasks);

$recentTasks = array_slice($todaysTasks, 0, 4);

// "Current Task" (Sheet 4 of the client's spec: completed/total sub-tasks in
// the current task) -- the task assigned for today, and its locations are
// the sub-tasks. Prefer whichever of today's tasks isn't fully completed
// yet, most recently started; otherwise fall back to the most recently
// created task that has locations assigned today.
$tasksWithLocationsToday = array_values(array_filter($todaysTasks, fn($t) => (int) $t['total_locations'] > 0));
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

// Pending / completed-today location counts (department-scoped).
$tlSql = '
    SELECT tl.status, tl.start_time, tl.end_time
    FROM ' . T_TASK_LOCATIONS . ' tl
    JOIN ' . T_TASKS . ' t ON t.id = tl.task_id
    WHERE t.deleted_at IS NULL AND tl.task_date = CAST(SYSDATETIME() AS DATE) AND tl.unassigned_at IS NULL' . $taskDeptFilter;
$tlStmt = $pdo->prepare($tlSql);
$tlStmt->execute($taskDeptParams);
$todaysLocationRows = $tlStmt->fetchAll();

$pendingLocations = count(array_filter($todaysLocationRows, fn($r) => $r['status'] === 'pending'));
$completedTodayCount = count(array_filter($todaysLocationRows, fn($r) => $r['status'] === 'completed'));
$inProgressTodayCount = count(array_filter($todaysLocationRows, fn($r) => $r['status'] === 'in_progress'));

// "Missed Out Tasks" (Sheet 4 of the client's spec): locations still on
// the active roster whose task_date has fully passed without completion.
$missedStats = countMissedTaskLocations($pdo, $isAdmin ? null : (int) $currentUser['department_id'], $taskTypeFilter !== '' ? $taskTypeFilter : null);

// All-time insight numbers (department-scoped). The location-side counts
// (total_assignments/total_completed/total_scanned) are scoped to
// tl.unassigned_at IS NULL -- a location's currently active assignment --
// same as Task Manager, the Task Detail modal, and the Sub-Tasks Completed
// tile elsewhere. That filter lives in the JOIN condition, not WHERE, so a
// task whose locations were all later unassigned still counts toward
// total_tasks (it's a real task) instead of disappearing from the query
// entirely because none of its rows would pass a WHERE-clause version of
// the same filter.
$allTimeSql = '
    SELECT
        COUNT(DISTINCT t.id) AS total_tasks,
        COUNT(tl.id) AS total_assignments,
        SUM(CASE WHEN tl.status = \'completed\' THEN 1 ELSE 0 END) AS total_completed,
        SUM(CASE WHEN tl.start_time IS NOT NULL THEN 1 ELSE 0 END) AS total_scanned
    FROM ' . T_TASKS . ' t
    LEFT JOIN ' . T_TASK_LOCATIONS . ' tl ON tl.task_id = t.id AND tl.unassigned_at IS NULL
    WHERE t.deleted_at IS NULL' . $taskDeptFilter;
$allTimeStmt = $pdo->prepare($allTimeSql);
$allTimeStmt->execute($taskDeptParams);
$allTime = $allTimeStmt->fetch();
$totalTasksAllTime = (int) $allTime['total_tasks'];
$totalAssignments = (int) $allTime['total_assignments'];
$totalCompletedAllTime = (int) $allTime['total_completed'];
$totalScannedAllTime = (int) $allTime['total_scanned'];
$completionRate = $totalAssignments > 0 ? round(($totalCompletedAllTime / $totalAssignments) * 100) : 0;

// Calendar: current month, department-scoped days with any task activity.
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
      AND YEAR(tl.task_date) = YEAR(SYSDATETIME()) AND MONTH(tl.task_date) = MONTH(SYSDATETIME())' . $taskDeptFilter;
$calStmt = $pdo->prepare($calSql);
$calStmt->execute($taskDeptParams);
$calDaysWithTasks = array_map('intval', $calStmt->fetchAll(PDO::FETCH_COLUMN));

// Tasks Overview bar chart: completed locations per month, last 6 months.
$barSql = '
    SELECT YEAR(tl.end_time) AS y, MONTH(tl.end_time) AS m, COUNT(*) AS completed_count
    FROM ' . T_TASK_LOCATIONS . ' tl
    JOIN ' . T_TASKS . ' t ON t.id = tl.task_id
    WHERE t.deleted_at IS NULL AND tl.status = \'completed\'
      AND tl.end_time >= DATEADD(MONTH, -5, DATEFROMPARTS(YEAR(SYSDATETIME()), MONTH(SYSDATETIME()), 1))' . $taskDeptFilter . '
    GROUP BY YEAR(tl.end_time), MONTH(tl.end_time)
';
$barStmt = $pdo->prepare($barSql);
$barStmt->execute($taskDeptParams);
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

// Location status donut (locations touched by a department-scoped task
// today, plus overall available/assigned split for locations generally).
$notStartedTodayCount = count(array_filter($todaysLocationRows, fn($r) => $r['status'] === 'pending'));
$hasLocationDataToday = count($todaysLocationRows) > 0;
$donutTotal = max(1, count($todaysLocationRows));
$donutCompletedPct = $hasLocationDataToday ? round(($completedTodayCount / $donutTotal) * 100) : 0;
$donutOngoingPct = $hasLocationDataToday ? round(($inProgressTodayCount / $donutTotal) * 100) : 0;
$donutNotStartedPct = $hasLocationDataToday ? max(0, 100 - $donutCompletedPct - $donutOngoingPct) : 0;

// The ring itself: real conic-gradient stops driven by the percentages
// above (not fixed arcs), so it only ever shows color for statuses that
// actually have locations in them. No data at all -> plain neutral ring,
// not a false "100% Not Started".
if ($hasLocationDataToday) {
    $donutOngoingEnd = $donutCompletedPct + $donutOngoingPct;
    $donutStyle = sprintf(
        'background: conic-gradient(var(--teal-soft) 0%% %1$d%%, var(--sky) %1$d%% %2$d%%, var(--dusty-purple) %2$d%% 100%%);',
        $donutCompletedPct,
        $donutOngoingEnd
    );
} else {
    $donutStyle = 'background: var(--gray-200);';
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
    <link rel="stylesheet" href="../styles/app.css">
    <link rel="stylesheet" href="../styles/dashboard.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    <script src="../scripts/theme.js?v=2"></script>
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
            <section class="stat-tiles">
                <div class="stat-tile">
                    <div class="stat-tile-icon teal"><i class="fas fa-clipboard-list"></i></div>
                    <div>
                        <div class="stat-tile-value"><?= $completedTasksToday ?> / <?= $totalTasksToday ?></div>
                        <div class="stat-tile-label">Completed Tasks as of <?= htmlspecialchars($dbNow->format('M j, Y')) ?></div>
                    </div>
                </div>
                <div class="stat-tile" <?= $currentTaskName ? 'title="' . htmlspecialchars($currentTaskName) . '"' : '' ?>>
                    <div class="stat-tile-icon sky"><i class="fas fa-list-check"></i></div>
                    <div>
                        <div class="stat-tile-value"><?= $currentTaskCompleted ?> / <?= $currentTaskTotal ?></div>
                        <div class="stat-tile-label">Sub-Tasks in Current Task</div>
                        <div class="stat-tile-sublabel"><?= $currentTaskName ? htmlspecialchars($currentTaskName) : 'No active task today' ?></div>
                    </div>
                </div>
                <div class="stat-tile">
                    <div class="stat-tile-icon" style="background: var(--danger-bg); color: var(--danger-text);"><i class="fas fa-triangle-exclamation"></i></div>
                    <div>
                        <div class="stat-tile-value"><?= $missedStats['missed'] ?> / <?= $missedStats['total'] ?></div>
                        <div class="stat-tile-label">Missed Out Tasks</div>
                    </div>
                </div>
            </section>

            <!-- Insights hero (dome) -->
            <section class="card insights-hero">
                <h2>Task Completion Insights</h2>
                <p class="insights-subtitle">Track and analyze your QR task checks with real-time metrics.</p>

                <div class="dome-wrap">
                    <div class="dome"></div>

                    <div class="orbit-card orbit-top-left">
                        <span class="orbit-label">Total Tasks</span>
                        <span class="orbit-value"><?= $totalTasksAllTime ?></span>
                    </div>

                    <div class="orbit-card orbit-top-right">
                        <span class="orbit-label">Completed Locations</span>
                        <span class="orbit-value"><?= $totalCompletedAllTime ?></span>
                    </div>

                    <div class="orbit-card orbit-bottom-left">
                        <span class="orbit-label">Pending Locations</span>
                        <span class="orbit-value"><?= $pendingLocations ?></span>
                    </div>

                    <div class="orbit-card orbit-bottom-right">
                        <span class="orbit-label">Completion Rate</span>
                        <span class="orbit-value"><?= $completionRate ?>%</span>
                    </div>

                    <div class="dome-center">
                        <span class="dome-center-label">Total Locations Scanned</span>
                        <span class="dome-center-value"><?= $totalScannedAllTime ?></span>
                    </div>
                </div>
            </section>

            <!-- Bottom grid -->
            <section class="bottom-grid">

                <!-- Tasks Overview -->
                <div class="card panel">
                    <div class="panel-header">
                        <h3>Tasks Overview</h3>
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
                    <div class="recent-list">
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
                                <span class="status-badge <?= htmlspecialchars($rt['status']['class']) ?>"><?= htmlspecialchars($rt['status']['label']) ?></span>
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
                <div class="timeline">
                    <?php if (empty($todaysTasks)): ?>
                        <p style="color: var(--gray-500); font-size: 14px;">No tasks assigned today.</p>
                    <?php endif; ?>
                    <?php foreach ($todaysTasks as $tt): ?>
                        <?php
                        $isDone = $tt['status']['code'] === 'completed';
                        $timeLabel = $tt['earliest_start'] ? (new DateTime($tt['earliest_start']))->format('g:i A') : 'Not started';
                        ?>
                        <div class="timeline-item<?= $isDone ? ' done' : '' ?>">
                            <div class="timeline-dot"></div>
                            <div class="timeline-content">
                                <div class="timeline-time"><?= htmlspecialchars($timeLabel) ?></div>
                                <div class="timeline-title"><?= htmlspecialchars($tt['name']) ?></div>
                                <div class="timeline-meta"><?= (int) $tt['total_locations'] ?> location<?= (int) $tt['total_locations'] === 1 ? '' : 's' ?></div>
                            </div>
                            <button class="timeline-check"><i class="fas fa-<?= $isDone ? 'check' : 'clock' ?>"></i></button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Location Status Donut -->
            <div class="card rail-card donut-card">
                <div class="panel-header">
                    <h3>Location Status</h3>
                </div>
                <div class="donut-wrap">
                    <div class="donut" style="<?= htmlspecialchars($donutStyle) ?>"></div>
                    <div class="donut-center">
                        <span><?= $donutCompletedPct ?>%</span>
                        <small>Completed</small>
                    </div>
                </div>
                <ul class="donut-legend">
                    <li><span class="dot teal"></span> Completed <b><?= $donutCompletedPct ?>%</b></li>
                    <li><span class="dot sky"></span> On-going <b><?= $donutOngoingPct ?>%</b></li>
                    <li><span class="dot purple"></span> Not Started <b><?= $donutNotStartedPct ?>%</b></li>
                </ul>
            </div>

        </aside>
    </div>

    <script src="../scripts/sidebar-drawer.js"></script>
    <script src="../scripts/select-dropdown.js"></script>
    <script src="../scripts/dashboard.js"></script>
    <script src="../scripts/logout-confirm.js"></script>
</body>

</html>
