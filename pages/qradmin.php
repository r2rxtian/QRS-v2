<?php
require_once __DIR__ . '/../auth/session.php';
$currentUser = requireLogin();

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../rules/constants.php';
require_once __DIR__ . '/../rules/status.php';
require_once __DIR__ . '/../authz/capabilities.php';

// All Tasks is now the User role's canonical task-list page (read-only
// browse, routed into scan.php for their own work); Admin's equivalent is
// tasks.php (Task Manager) -- keeping one page per role instead of two
// overlapping views of the same data.
if ($currentUser['role_name'] === ROLE_ADMIN) {
    header('Location: tasks.php');
    exit;
}

$pdo = db();

// "Today" is anchored to the DB server's own clock (matches dashboard.php
// and api/tasks/create.php), used below to tell a genuinely-overdue
// not-started task apart from one that's simply scheduled for later.
$dbToday = new DateTime($pdo->query('SELECT CONVERT(varchar, SYSDATETIME(), 120)')->fetchColumn());
$dbToday->setTime(0, 0);

// Locations/Progress reflect a task's REAL total assignment -- each
// location's CURRENT ticket (its most recent task_locations row), still on
// the roster OR auto-unassigned by the system once it resolved (see
// rules/status.php's sweepResolvedLocations() -- unassigned_by IS NULL
// marks that case). This keeps a task's counts/badges intact even after a
// completed or missed location auto-frees itself for reuse elsewhere.
// Pinning to just the latest ticket per (task, location) pair still guards
// against an Admin unassigning and re-assigning the same location to the
// same task (e.g. correcting a mistake), which would otherwise double
// count an old, already-resolved ticket alongside the current one. The
// missed-hours calculation compares against unassigned_at once frozen
// (set), not the live clock, so "was this missed" stays true permanently.
$sql = '
    SELECT
        t.id, t.name,
        ' . fullNameSql('uml', 'u') . ' AS creator_name, u.employee_id AS creator_employee_id, u.avatar_initials, u.avatar_color,
        COUNT(tl.id) AS total_locations,
        SUM(CASE WHEN tl.status = \'completed\' THEN 1 ELSE 0 END) AS completed_locations,
        SUM(CASE WHEN tl.status = \'in_progress\' AND (CAST(SYSDATETIME() AS DATE) >= tl.task_date AND DATEDIFF(SECOND, CASE WHEN tl.task_date > CAST(tl.assigned_at AS DATE) THEN CAST(tl.task_date AS DATETIME2) ELSE tl.assigned_at END, SYSDATETIME()) < ' . TASK_LOCATION_EXPIRATION_SECONDS . ') THEN 1 ELSE 0 END) AS in_progress_locations,
        SUM(CASE WHEN tl.status <> \'completed\' AND CAST(SYSDATETIME() AS DATE) >= tl.task_date AND DATEDIFF(SECOND, CASE WHEN tl.task_date > CAST(tl.assigned_at AS DATE) THEN CAST(tl.task_date AS DATETIME2) ELSE tl.assigned_at END, COALESCE(tl.unassigned_at, SYSDATETIME())) >= ' . TASK_LOCATION_EXPIRATION_SECONDS . ' THEN 1 ELSE 0 END) AS missed_locations,
        (SELECT MIN(tl2.task_date) FROM ' . T_TASK_LOCATIONS . ' tl2
            WHERE tl2.task_id = t.id AND (tl2.unassigned_at IS NULL OR tl2.unassigned_by IS NULL)
              AND tl2.id = (
                  SELECT MAX(tl3.id) FROM ' . T_TASK_LOCATIONS . ' tl3
                  WHERE tl3.task_id = tl2.task_id AND tl3.location_id = tl2.location_id
              )) AS earliest_active_date
    FROM ' . T_TASKS . ' t
    LEFT JOIN ' . T_USERS . ' u ON u.id = t.owner_id
    LEFT JOIN ' . T_MASTER_LIST . ' uml ON uml.EmployeeID = u.employee_id
    LEFT JOIN ' . T_TASK_LOCATIONS . ' tl ON tl.task_id = t.id AND (tl.unassigned_at IS NULL OR tl.unassigned_by IS NULL)
        AND tl.id = (
            SELECT MAX(tl4.id) FROM ' . T_TASK_LOCATIONS . ' tl4
            WHERE tl4.task_id = tl.task_id AND tl4.location_id = tl.location_id
        )
    WHERE t.deleted_at IS NULL
    GROUP BY t.id, t.name, u.employee_id, uml.LastName, uml.FirstName, uml.MiddleName, u.avatar_initials, u.avatar_color
    ORDER BY t.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute();

$tasks = [];
$statTotal = 0;
$statOngoing = 0;
$statCompleted = 0;
$statNotStarted = 0;

foreach ($stmt->fetchAll() as $row) {
    $earliestActiveDate = $row['earliest_active_date'] ? new DateTime($row['earliest_active_date']) : null;
    $isFutureScheduled = $earliestActiveDate !== null && $earliestActiveDate > $dbToday;
    $scheduledDateLabel = $isFutureScheduled ? $earliestActiveDate->format('M j, Y') : null;

    $status = deriveTaskStatus((int) $row['total_locations'], (int) $row['completed_locations'], (int) $row['in_progress_locations'], $isFutureScheduled, $scheduledDateLabel);
    $row['status'] = $status;
    // Separate, additive indicator layered next to the status badge -- see
    // pages/tasks.php's identical comment for the reasoning.
    $row['has_missed'] = (int) $row['missed_locations'] > 0;
    $row['action'] = resolveTaskAction($status, (int) $row['id'], $currentUser['role_name'], $row['has_missed']);
    $row['schedule_label'] = $earliestActiveDate ? $earliestActiveDate->format('M j, Y') : null;
    $row['schedule_weekday'] = $earliestActiveDate ? $earliestActiveDate->format('D') : null;
    $tasks[] = $row;

    $statTotal++;
    // A task with a missed location is badged "Missed Out" instead of its
    // base status in the table below (see the has_missed check on the
    // Status column) -- kept out of these tiles too, so a task that never
    // visibly reads "On-going"/"Completed"/"Not Started" in the list isn't
    // still counted as one.
    if ($row['has_missed']) {
        // none of the tiles below
    } elseif ($status['code'] === 'ongoing') {
        $statOngoing++;
    } elseif ($status['code'] === 'completed') {
        $statCompleted++;
    } elseif ($status['code'] === 'not_started') {
        $statNotStarted++;
    }
}

// Assigned location names per task, for the "Locations" column -- same
// "current ticket per location" scoping as above, so a location that was
// unassigned and re-assigned to the same task lists its name once, not twice.
$locationNamesByTask = [];
if (!empty($tasks)) {
    $taskIds = array_column($tasks, 'id');
    $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
    $locNameStmt = $pdo->prepare('
        SELECT tl.task_id, l.name AS location_name
        FROM ' . T_TASK_LOCATIONS . ' tl
        JOIN ' . T_LOCATIONS . ' l ON l.id = tl.location_id
        WHERE tl.task_id IN (' . $placeholders . ') AND (tl.unassigned_at IS NULL OR tl.unassigned_by IS NULL)
          AND tl.id = (
              SELECT MAX(tl2.id) FROM ' . T_TASK_LOCATIONS . ' tl2
              WHERE tl2.task_id = tl.task_id AND tl2.location_id = tl.location_id
          )
        ORDER BY l.name
    ');
    $locNameStmt->execute($taskIds);
    foreach ($locNameStmt->fetchAll() as $row) {
        $locationNamesByTask[$row['task_id']][] = $row['location_name'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Tasks — QR Task Check</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/app.css?v=15">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    <script src="../scripts/theme.js?v=6"></script>
</head>

<body>
    <?php include '../components/appshell_start.php'; ?>

    <div class="topbar">
        <div class="topbar-title">
            <h1>All Tasks</h1>
            <p>View every task and jump into scanning.</p>
        </div>
    </div>

    <div class="stat-tiles" data-realtime-region="all-task-stats">
        <div class="stat-tile">
            <div class="stat-tile-top">
                <div class="stat-tile-icon periwinkle"><i class="fas fa-clipboard-list"></i></div>
            </div>
            <div class="stat-tile-value"><?= $statTotal ?></div>
            <div class="stat-tile-label">Total Tasks</div>
        </div>
        <div class="stat-tile">
            <div class="stat-tile-top">
                <div class="stat-tile-icon sky"><i class="fas fa-hourglass-half"></i></div>
            </div>
            <div class="stat-tile-value"><?= $statOngoing ?></div>
            <div class="stat-tile-label">On-going</div>
        </div>
        <div class="stat-tile">
            <div class="stat-tile-top">
                <div class="stat-tile-icon teal"><i class="fas fa-circle-check"></i></div>
            </div>
            <div class="stat-tile-value"><?= $statCompleted ?></div>
            <div class="stat-tile-label">Completed</div>
        </div>
        <div class="stat-tile">
            <div class="stat-tile-top">
                <div class="stat-tile-icon purple"><i class="fas fa-triangle-exclamation"></i></div>
            </div>
            <div class="stat-tile-value"><?= $statNotStarted ?></div>
            <div class="stat-tile-label">Not Started</div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div style="display:flex; align-items:center; gap:14px;">
                <div class="modal-header-icon"><i class="fas fa-clipboard-list"></i></div>
                <div>
                    <h2>Task List</h2>
                    <p style="margin: 2px 0 0; font-size: 13px; color: var(--gray-500);">Browse and search every task</p>
                </div>
            </div>
            <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                <input type="text" class="form-input table-search-input" data-target="allTasksTable" placeholder="Search tasks…" style="width: 220px;">
                <div class="filter-wrap" data-table="allTasksTable">
                    <button type="button" class="btn btn-secondary filter-btn" onclick="toggleFilterPanel(this)">
                        <i class="fas fa-sliders"></i> Filters <span class="filter-badge"></span>
                    </button>
                    <div class="filter-panel">
                        <div class="filter-group">
                            <div class="filter-group-title">Sort By</div>
                            <div class="sort-dropdown" data-value="">
                                <button type="button" class="sort-dropdown-trigger" onclick="toggleSortDropdown(this)">
                                    <span>Default order</span>
                                    <i class="fas fa-chevron-down"></i>
                                </button>
                                <div class="sort-dropdown-menu">
                                    <div class="sort-dropdown-option selected" data-value="">Default order</div>
                                    <div class="sort-dropdown-group-title">Task</div>
                                    <div class="sort-dropdown-option" data-value="0:text:asc" data-label="Task (A → Z)">A → Z</div>
                                    <div class="sort-dropdown-option" data-value="0:text:desc" data-label="Task (Z → A)">Z → A</div>
                                    <div class="sort-dropdown-group-title">Assigned By</div>
                                    <div class="sort-dropdown-option" data-value="1:text:asc" data-label="Assigned By (A → Z)">A → Z</div>
                                    <div class="sort-dropdown-option" data-value="1:text:desc" data-label="Assigned By (Z → A)">Z → A</div>
                                    <div class="sort-dropdown-group-title">Completion</div>
                                    <div class="sort-dropdown-option" data-value="3:number:asc" data-label="Completion (Least done first)">Least done first</div>
                                    <div class="sort-dropdown-option" data-value="3:number:desc" data-label="Completion (Most done first)">Most done first</div>
                                    <div class="sort-dropdown-group-title">Schedule</div>
                                    <div class="sort-dropdown-option" data-value="4:text:asc" data-label="Schedule (Oldest first)">Oldest first</div>
                                    <div class="sort-dropdown-option" data-value="4:text:desc" data-label="Schedule (Newest first)">Newest first</div>
                                    <div class="sort-dropdown-group-title">Status</div>
                                    <div class="sort-dropdown-option" data-value="5:text:asc" data-label="Status (A → Z)">A → Z</div>
                                    <div class="sort-dropdown-option" data-value="5:text:desc" data-label="Status (Z → A)">Z → A</div>
                                </div>
                            </div>
                        </div>
                        <div class="filter-group">
                            <div class="filter-group-title">Status</div>
                            <label class="filter-option"><input type="checkbox" data-filter="status" value="Completed" checked> Completed</label>
                            <label class="filter-option"><input type="checkbox" data-filter="status" value="On-going" checked> On-going</label>
                            <label class="filter-option"><input type="checkbox" data-filter="status" value="Not Started" checked> Not Started</label>
                            <label class="filter-option"><input type="checkbox" data-filter="status" value="Scheduled" checked> Scheduled</label>
                            <label class="filter-option"><input type="checkbox" data-filter="status" value="Assign Locations" checked> Assign Locations</label>
                        </div>
                        <div class="filter-group">
                            <div class="filter-group-title">Missed Out</div>
                            <label class="filter-option"><input type="checkbox" data-filter="missed" value="Yes" checked> Has Missed Location</label>
                            <label class="filter-option"><input type="checkbox" data-filter="missed" value="No" checked> None Missed</label>
                        </div>
                        <div class="filter-panel-actions">
                            <button type="button" class="btn btn-sm btn-secondary" onclick="clearFilterPanel(this)">Clear</button>
                            <button type="button" class="btn btn-sm btn-primary" onclick="applyFilterPanel(this)">Apply</button>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label for="allTasksEntries" class="form-label">Show</label>
                    <select id="allTasksEntries" class="form-input" onchange="onAllTasksEntriesChange(this.value)">
                        <option value="10" selected>10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="all">All</option>
                    </select>
                    <span class="form-label">entries</span>
                </div>
            </div>
        </div>
        <div class="table-wrapper">
            <table id="allTasksTable">
                <thead>
                    <tr>
                        <th>Task</th>
                        <th>Assigned By</th>
                        <th>Locations</th>
                        <th>Completion</th>
                        <th>Schedule</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody data-realtime-region="all-tasks-table-body">
                    <?php if (empty($tasks)): ?>
                        <tr>
                            <td colspan="7" style="text-align:center; padding: 40px; color: var(--gray-500);">No tasks found.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($tasks as $task): ?>
                        <?php
                        $avatarInitials = $task['avatar_initials'] ?: strtoupper(substr((string) $task['creator_name'], 0, 1));
                        $avatarFallbackUrl = initialsAvatarUrl($avatarInitials, $task['avatar_color'], '28');
                        $avatarPhotoUrl = employeePhotoUrl($task['creator_employee_id']);
                        $total = (int) $task['total_locations'];
                        $completed = (int) $task['completed_locations'];
                        $completionLabel = $total === 0 ? 'No locations' : "$completed/$total Complete";
                        $locNames = $locationNamesByTask[$task['id']] ?? [];
                        $locationsCountLabel = $total === 0 ? 'No locations' : ($total === 1 ? '1 Location' : "$total Locations");
                        ?>
                        <tr data-status="<?= htmlspecialchars(taskStatusFilterLabel($task['status'])) ?>" data-missed="<?= $task['has_missed'] ? 'Yes' : 'No' ?>">
                            <td>
                                <div class="row-icon-name">
                                    <div class="row-icon"><i class="fas fa-clipboard-check"></i></div>
                                    <span><?= htmlspecialchars($task['name']) ?></span>
                                </div>
                            </td>
                            <td><div class="avatar-cell"><img src="<?= htmlspecialchars($avatarPhotoUrl ?? $avatarFallbackUrl) ?>" alt="" onerror="this.onerror=null;this.src=<?= htmlspecialchars(json_encode($avatarFallbackUrl), ENT_QUOTES) ?>;"><span class="avatar-cell-name"><?= htmlspecialchars($task['creator_name'] ?? 'Unknown') ?></span></div></td>
                            <td>
                                <?php if (empty($locNames)): ?>
                                    <span style="color: var(--gray-400);"><?= htmlspecialchars($locationsCountLabel) ?></span>
                                <?php else: ?>
                                    <span class="tag" title="<?= htmlspecialchars(implode(', ', $locNames)) ?>"><?= htmlspecialchars($locationsCountLabel) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><span class="tag"><?= htmlspecialchars($completionLabel) ?></span></td>
                            <td>
                                <?php if ($task['schedule_label']): ?>
                                    <span class="schedule-date-cell"><i class="fas fa-calendar-days"></i> <?= htmlspecialchars($task['schedule_label']) ?> <span class="schedule-date-weekday">(<?= htmlspecialchars($task['schedule_weekday']) ?>)</span></span>
                                <?php else: ?>
                                    <span style="color: var(--gray-400);">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($task['has_missed']): ?>
                                    <span class="status-badge status-missed" title="At least one location has been open 24+ hours without completion -- open the task to see which"><i class="fas fa-triangle-exclamation"></i> Missed Out</span>
                                <?php else: ?>
                                    <span class="status-badge <?= htmlspecialchars($task['status']['class']) ?>" title="<?= htmlspecialchars($task['status']['label']) ?>"><?= htmlspecialchars($task['status']['label']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($task['action']['type'] === 'link'): ?>
                                    <a href="<?= htmlspecialchars($task['action']['url']) ?>" class="view-btn"><?= htmlspecialchars($task['action']['label']) ?></a>
                                <?php else: ?>
                                    <button type="button" class="view-btn" onclick="openTaskDetailModal(<?= (int) $task['id'] ?>, <?= htmlspecialchars(json_encode($task['name']), ENT_QUOTES) ?>)"><?= htmlspecialchars($task['action']['label']) ?></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="pagination-container" id="allTasksPagination"></div>
    </div>

    <?php include '../components/appshell_end.php'; ?>

    <script src="../scripts/sidebar-drawer.js"></script>
    <script src="../scripts/pagination.js?v=7"></script>
    <script src="../scripts/sort-table.js"></script>
    <script src="../scripts/filters.js?v=2"></script>
    <script src="../scripts/qradmin.js"></script>
</body>

</html>
