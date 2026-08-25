<?php
require_once __DIR__ . '/../auth/session.php';
$currentUser = requireLogin();

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../rules/constants.php';
require_once __DIR__ . '/../rules/status.php';
require_once __DIR__ . '/../authz/capabilities.php';
require_once __DIR__ . '/../auth/csrf.php';

$isAdmin = $currentUser['role_name'] === ROLE_ADMIN;

// Task Manager is Admin's canonical task-list page (create/manage); the
// User role's equivalent is the read-only All Tasks / scan-routed list --
// keeping one page per role instead of two overlapping views of the same data.
if (!$isAdmin) {
    header('Location: qradmin.php');
    exit;
}

$pdo = db();
$canCreateTask = roleHasCapability($currentUser['role_name'], 'task.create');
$canDeleteTask = roleHasCapability($currentUser['role_name'], 'task.delete');

// A location stays unavailable as long as it's on any task's roster
// (unassigned_at IS NULL), regardless of whether that ticket is already
// Completed -- finishing a checklist doesn't free the location on its
// own; an Admin has to explicitly Unassign it first.
$availableLocationsForCreate = $pdo->query('
    SELECT l.id, l.name, l.location_type
    FROM ' . T_LOCATIONS . ' l
    WHERE l.deleted_at IS NULL AND l.is_active = 1
      AND NOT EXISTS (
          SELECT 1 FROM ' . T_TASK_LOCATIONS . ' tl WHERE tl.location_id = l.id AND tl.unassigned_at IS NULL
            AND tl.id = (SELECT MAX(tl2.id) FROM ' . T_TASK_LOCATIONS . ' tl2 WHERE tl2.task_id = tl.task_id AND tl2.location_id = tl.location_id)
      )
    ORDER BY l.name
')->fetchAll();

// A task is fixed to one Task Type at creation and can only be assigned
// locations of that same type -- split the picker into one checklist per
// type, toggled client-side by the Task Type selector.
$availableLocationsByType = ['Monitoring' => [], 'Treatment' => []];
foreach ($availableLocationsForCreate as $loc) {
    $availableLocationsByType[$loc['location_type']][] = $loc;
}

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
        t.id, t.name, t.owner_id,
        ' . fullNameSql('uml', 'u') . ' AS creator_name, u.employee_id AS creator_employee_id, u.avatar_initials, u.avatar_color,
        COUNT(tl.id) AS total_locations,
        SUM(CASE WHEN tl.status = \'completed\' THEN 1 ELSE 0 END) AS completed_locations,
        SUM(CASE WHEN tl.status = \'in_progress\' AND DATEDIFF(SECOND, tl.assigned_at, SYSDATETIME()) < 86400 THEN 1 ELSE 0 END) AS in_progress_locations,
        SUM(CASE WHEN tl.status <> \'completed\' AND DATEDIFF(SECOND, tl.assigned_at, COALESCE(tl.unassigned_at, SYSDATETIME())) >= 86400 THEN 1 ELSE 0 END) AS missed_locations,
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
    GROUP BY t.id, t.name, t.owner_id, u.employee_id, uml.LastName, uml.FirstName, uml.MiddleName, u.avatar_initials, u.avatar_color
    ORDER BY t.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute();

$tasks = [];
$statTotal = 0;
$statOngoing = 0;
$statCompleted = 0;
$statSubTasksTotal = 0;
$statSubTasksCompleted = 0;

foreach ($stmt->fetchAll() as $row) {
    $earliestActiveDate = $row['earliest_active_date'] ? new DateTime($row['earliest_active_date']) : null;
    $isFutureScheduled = $earliestActiveDate !== null && $earliestActiveDate > $dbToday;
    $scheduledDateLabel = $isFutureScheduled ? $earliestActiveDate->format('M j, Y') : null;

    $status = deriveTaskStatus((int) $row['total_locations'], (int) $row['completed_locations'], (int) $row['in_progress_locations'], $isFutureScheduled, $scheduledDateLabel);
    $row['status'] = $status;
    // Separate, additive indicator layered next to the status badge rather
    // than folded into deriveTaskStatus() itself -- "missed" isn't a
    // replacement for Not Started/On-going, a task can be either of those
    // AND have a location that's blown past its 24-hour window at the same
    // time. A completed location can never be missed (see the SQL's
    // status <> 'completed' condition), so this only ever applies to a
    // task that isn't already fully Completed.
    $row['has_missed'] = (int) $row['missed_locations'] > 0;
    $row['schedule_label'] = $earliestActiveDate ? $earliestActiveDate->format('M j, Y') : null;
    $row['schedule_weekday'] = $earliestActiveDate ? $earliestActiveDate->format('D') : null;
    $tasks[] = $row;

    $statTotal++;
    // A task with a missed location is badged "Missed Out" instead of its
    // base status in the table below (see the has_missed check on the
    // Status column) -- kept out of both tiles here too, so a task that
    // never visibly reads "On-going" in the list isn't still counted as one.
    if ($row['has_missed']) {
        // neither tile
    } elseif ($status['code'] === 'ongoing') {
        $statOngoing++;
    } elseif ($status['code'] === 'completed') {
        $statCompleted++;
    }

    // Location-level (not task-level) completion -- a task with 8 of 10
    // locations done still counts those 8, even though the task itself
    // isn't "Completed" yet. Gives a real progress figure across
    // everything assigned, instead of only counting fully-finished tasks.
    $statSubTasksTotal += (int) $row['total_locations'];
    $statSubTasksCompleted += (int) $row['completed_locations'];
}

// Assigned location names per task, for the "Location(s)" column (merged in
// from the former qradmin.php "All Tasks" page, which User now uses instead
// -- see the top-of-file redirect). Same still-relevant + "current ticket
// per location" scoping as above -- without the latter, a location that
// was unassigned and re-assigned to the same task would list its name
// twice instead of once.
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
    <title>Task Manager — QR Task Check</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/app.css?v=10">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    <script src="../scripts/theme.js?v=5"></script>
</head>

<body>
    <?php include '../components/appshell_start.php'; ?>

    <div class="topbar">
        <div class="topbar-title">
            <h1>Task Manager</h1>
            <p>Create and manage your tasks and their locations.</p>
        </div>
        <div class="topbar-actions">
            <?php if ($canCreateTask): ?>
                <button type="button" class="btn btn-primary" onclick="openCreateTaskModal()"><i class="fas fa-circle-plus"></i> Create Task</button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Stat Tiles -->
    <div class="stat-tiles">
        <div class="stat-tile">
            <div class="stat-tile-top">
                <div class="stat-tile-icon periwinkle"><i class="fas fa-clipboard-list"></i></div>
            </div>
            <div class="stat-tile-value"><?= $statTotal ?></div>
            <div class="stat-tile-label">Total Tasks</div>
            <div class="stat-tile-meta">Across every task</div>
        </div>
        <div class="stat-tile">
            <div class="stat-tile-top">
                <div class="stat-tile-icon sky"><i class="fas fa-hourglass-half"></i></div>
            </div>
            <div class="stat-tile-value"><?= $statOngoing ?></div>
            <div class="stat-tile-label">On-going</div>
            <div class="stat-tile-meta">Currently being worked on</div>
        </div>
        <div class="stat-tile">
            <div class="stat-tile-top">
                <div class="stat-tile-icon teal"><i class="fas fa-circle-check"></i></div>
            </div>
            <div class="stat-tile-value"><?= $statCompleted ?></div>
            <div class="stat-tile-label">Completed</div>
            <div class="stat-tile-meta">Finished today</div>
        </div>
        <div class="stat-tile">
            <div class="stat-tile-top">
                <div class="stat-tile-icon purple"><i class="fas fa-circle-check"></i></div>
            </div>
            <div class="stat-tile-value"><?= $statSubTasksCompleted ?> / <?= $statSubTasksTotal ?></div>
            <div class="stat-tile-label">Sub-Tasks Completed</div>
            <div class="stat-tile-meta">Locations completed across all tasks</div>
        </div>
    </div>

    <!-- Task List Card -->
    <div class="card">
        <div class="card-header">
            <div style="display:flex; align-items:center; gap:14px;">
                <div class="modal-header-icon"><i class="fas fa-clipboard-list"></i></div>
                <div>
                    <h2>All Tasks</h2>
                    <p style="margin: 2px 0 0; font-size: 13px; color: var(--gray-500);">Manage and track all your tasks</p>
                </div>
            </div>
            <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                <input type="text" class="form-input table-search-input" data-target="tasksTable" placeholder="Search tasks…" style="width: 220px;">
                <div class="filter-wrap" data-table="tasksTable">
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
                                    <div class="sort-dropdown-option" data-value="1:text:asc" data-label="Task (A → Z)">A → Z</div>
                                    <div class="sort-dropdown-option" data-value="1:text:desc" data-label="Task (Z → A)">Z → A</div>
                                    <div class="sort-dropdown-group-title">Assigned By</div>
                                    <div class="sort-dropdown-option" data-value="2:text:asc" data-label="Assigned By (A → Z)">A → Z</div>
                                    <div class="sort-dropdown-option" data-value="2:text:desc" data-label="Assigned By (Z → A)">Z → A</div>
                                    <div class="sort-dropdown-group-title">Completion</div>
                                    <div class="sort-dropdown-option" data-value="4:number:asc" data-label="Completion (Least done first)">Least done first</div>
                                    <div class="sort-dropdown-option" data-value="4:number:desc" data-label="Completion (Most done first)">Most done first</div>
                                    <div class="sort-dropdown-group-title">Schedule</div>
                                    <div class="sort-dropdown-option" data-value="5:text:asc" data-label="Schedule (Oldest first)">Oldest first</div>
                                    <div class="sort-dropdown-option" data-value="5:text:desc" data-label="Schedule (Newest first)">Newest first</div>
                                    <div class="sort-dropdown-group-title">Status</div>
                                    <div class="sort-dropdown-option" data-value="6:text:asc" data-label="Status (A → Z)">A → Z</div>
                                    <div class="sort-dropdown-option" data-value="6:text:desc" data-label="Status (Z → A)">Z → A</div>
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
                            <label class="filter-option"><input type="checkbox" data-filter="missed" value="Yes"> Missed Out</label>
                        </div>
                        <div class="filter-panel-actions">
                            <button type="button" class="btn btn-sm btn-secondary" onclick="clearFilterPanel(this)">Clear</button>
                            <button type="button" class="btn btn-sm btn-primary" onclick="applyFilterPanel(this)">Apply</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php if ($canDeleteTask): ?>
            <div class="bulk-actions">
                <div class="bulk-actions-left">
                    <label class="checkbox-label">
                        <input type="checkbox" id="select_all" onclick="toggleSelectAll(this)">
                        Select all
                    </label>
                    <span class="bulk-actions-count" id="taskSelectedCount"></span>
                </div>
                <div class="bulk-actions-right">
                    <button type="button" class="btn btn-danger btn-sm" onclick="deleteSelected()" data-tooltip="Irreversible action: Delete selected entries"><i class="fas fa-trash"></i> Delete Selected</button>
                </div>
            </div>
        <?php endif; ?>
        <div class="card-body p-0">
            <div class="table-wrapper">
                <table id="tasksTable">
                    <thead>
                        <tr>
                            <?php if ($canDeleteTask): ?>
                                <th></th>
                            <?php endif; ?>
                            <th>Task</th>
                            <th>Assigned By</th>
                            <th>Locations</th>
                            <th>Completion</th>
                            <th>Schedule</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($tasks)): ?>
                            <tr>
                                <td colspan="<?= $canDeleteTask ? 8 : 7 ?>" style="text-align:center; padding: 40px; color: var(--gray-500);">No tasks yet.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($tasks as $task): ?>
                            <?php
                            $total = (int) $task['total_locations'];
                            $completed = (int) $task['completed_locations'];
                            $completionLabel = $total === 0 ? 'No locations' : "$completed/$total Complete";
                            $locNames = $locationNamesByTask[$task['id']] ?? [];
                            $locationsCountLabel = $total === 0 ? 'No locations' : ($total === 1 ? '1 Location' : "$total Locations");
                            $avatarInitials = $task['avatar_initials'] ?: strtoupper(substr((string) $task['creator_name'], 0, 1));
                            $avatarFallbackUrl = initialsAvatarUrl($avatarInitials, $task['avatar_color'], '28');
                            $avatarPhotoUrl = employeePhotoUrl($task['creator_employee_id']);
                            // Missed Out gets the same read-only treatment as Completed -- both
                            // have Unassign/Assign locked out in the detail modal (see
                            // api/tasks/detail_partial.php), so "Manage" would be misleading.
                            $isReadOnly = $task['status']['code'] === 'completed' || $task['has_missed'];
                            ?>
                            <tr data-status="<?= htmlspecialchars(taskStatusFilterLabel($task['status'])) ?>" data-missed="<?= $task['has_missed'] ? 'Yes' : 'No' ?>" data-task-id="<?= (int) $task['id'] ?>">
                                <?php if ($canDeleteTask): ?>
                                    <td><input type="checkbox" class="row-check" value="<?= (int) $task['id'] ?>"></td>
                                <?php endif; ?>
                                <td><span class="location-name"><?= htmlspecialchars($task['name']) ?></span></td>
                                <td>
                                    <div class="avatar-cell">
                                        <img src="<?= htmlspecialchars($avatarPhotoUrl ?? $avatarFallbackUrl) ?>" alt="" onerror="this.onerror=null;this.src=<?= htmlspecialchars(json_encode($avatarFallbackUrl), ENT_QUOTES) ?>;">
                                        <span class="avatar-cell-name"><?= htmlspecialchars($task['creator_name'] ?? 'Unknown') ?></span>
                                    </div>
                                </td>
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
                                        <span class="status-badge <?= htmlspecialchars($task['status']['class']) ?>"><?= htmlspecialchars($task['status']['label']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="kebab-wrap">
                                        <button type="button" class="kebab-btn" onclick="toggleKebab(this)"><i class="fas fa-ellipsis-vertical"></i></button>
                                        <div class="kebab-menu">
                                            <?php if ($isReadOnly): ?>
                                                <button type="button" onclick="closeAllKebabs(); openTaskDetailModal(<?= (int) $task['id'] ?>, <?= htmlspecialchars(json_encode($task['name']), ENT_QUOTES) ?>)"><i class="fas fa-eye"></i> View Task</button>
                                            <?php else: ?>
                                                <button type="button" onclick="closeAllKebabs(); openTaskDetailModal(<?= (int) $task['id'] ?>, <?= htmlspecialchars(json_encode($task['name']), ENT_QUOTES) ?>)"><i class="fas fa-map-location-dot"></i> Manage Task</button>
                                            <?php endif; ?>
                                            <?php if ($canDeleteTask): ?>
                                                <button type="button" class="kebab-danger" onclick="confirmDelete(this)"><i class="fas fa-trash"></i> Delete</button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="pagination-container" id="tasksPagination"></div>
        </div>
    </div>

    <?php include '../components/appshell_end.php'; ?>

    <!-- Create Task Modal -->
    <div id="createTaskModal" class="modal-overlay">
        <div class="modal modal-split">
            <div class="modal-header">
                <div class="modal-header-row">
                    <div class="modal-header-icon"><i class="fas fa-clipboard-list"></i></div>
                    <div>
                        <h3 class="modal-title">Create New Task</h3>
                        <p class="modal-subtitle">Fill in the details to create a new task. All fields are required.</p>
                    </div>
                </div>
                <button type="button" class="modal-close" onclick="closeModal('createTaskModal')">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div class="modal-body modal-body-split">
                <div class="modal-body-left">
                    <div class="modal-panel-header">
                        <div class="modal-panel-header-icon"><i class="fas fa-file-lines"></i></div>
                        <div>
                            <h4>Task Details</h4>
                            <p>Fill in the basic info for this task.</p>
                        </div>
                    </div>
                    <div class="form-group" style="flex-direction: column; align-items: flex-start; gap: 8px;">
                        <label for="task_name" class="form-label">Task Name</label>
                        <div class="input-icon-wrap">
                            <i class="fas fa-file-lines"></i>
                            <input type="text" id="task_name" class="form-input" placeholder="Enter task name">
                        </div>
                    </div>
                    <div class="form-group" style="flex-direction: column; align-items: flex-start; gap: 6px; margin-top: 8px;">
                        <label class="form-label">Task Type</label>
                        <div class="task-type-cards">
                            <?php foreach (TASK_TYPES as $type): $meta = TASK_TYPE_META[$type]; ?>
                                <button type="button" class="task-type-card" data-value="<?= htmlspecialchars($type) ?>" onclick="selectTaskTypeCard(this)">
                                    <span class="task-type-card-check"><i class="fas fa-check"></i></span>
                                    <div class="task-type-card-icon"><i class="fas <?= htmlspecialchars($meta['icon']) ?>"></i></div>
                                    <div class="task-type-card-title"><?= htmlspecialchars($type) ?></div>
                                    <div class="task-type-card-desc"><?= htmlspecialchars($meta['description']) ?></div>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <select id="task_type" class="select-dropdown-native" tabindex="-1" aria-hidden="true" onchange="onTaskTypeChange()">
                            <option value="" disabled selected>-- Select Task Type --</option>
                            <?php foreach (TASK_TYPES as $type): ?>
                                <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex-direction: column; align-items: flex-start; gap: 6px; margin-top: 8px;">
                        <label class="form-label">Schedule Date</label>
                        <div class="date-field">
                            <button type="button" class="select-dropdown-trigger" onclick="toggleDatePicker(this)">
                                <i class="fas fa-calendar-days"></i>
                                <span id="task_date_label"><?= htmlspecialchars((new DateTime())->format('m/d/Y')) ?></span>
                            </button>
                            <div class="date-picker-panel" data-for="task_date">
                                <div class="date-picker-header">
                                    <button type="button" class="date-picker-nav" onclick="navigateDatePicker(this, -1)"><i class="fas fa-chevron-left"></i></button>
                                    <span class="date-picker-month-label"></span>
                                    <button type="button" class="date-picker-nav" onclick="navigateDatePicker(this, 1)"><i class="fas fa-chevron-right"></i></button>
                                </div>
                                <div class="date-picker-weekdays">
                                    <span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span>
                                </div>
                                <div class="date-picker-grid"></div>
                                <div class="date-picker-footer">
                                    <button type="button" class="date-picker-today-btn" onclick="goToToday(this)">Today</button>
                                </div>
                            </div>
                            <input type="date" id="task_date" tabindex="-1" aria-hidden="true" min="<?= htmlspecialchars((new DateTime())->format('Y-m-d')) ?>" value="<?= htmlspecialchars((new DateTime())->format('Y-m-d')) ?>">
                        </div>
                        <p style="color: var(--gray-500); font-size: 12.5px; margin: -2px 0 0;">Defaults to today — pick a future date to schedule this task's locations for that day instead.</p>
                    </div>
                    <div class="form-group" style="flex-direction: column; align-items: flex-start; gap: 6px; margin-top: 8px;">
                        <label class="form-label">Locations</label>
                        <div class="task-locations-summary">
                            <div class="task-locations-summary-icon"><i class="fas fa-location-dot"></i></div>
                            <div class="task-locations-summary-text"><span id="taskLocationsSummaryCount" data-count-for="">0</span> location(s) selected</div>
                            <p class="task-locations-summary-desc">Selected locations will be assigned to this task.</p>
                        </div>
                    </div>
                </div>
                <div class="modal-body-right" id="taskLocationsPanel">
                    <div class="modal-panel-header">
                        <div class="modal-panel-header-icon"><i class="fas fa-location-dot"></i></div>
                        <div>
                            <h4>Select Locations</h4>
                            <p id="taskLocationsPanelSubtitle">Choose the locations for this task based on the selected type.</p>
                        </div>
                    </div>

                    <?php if (empty($availableLocationsForCreate)): ?>
                        <p style="color: var(--gray-500); font-size: 13px; margin: 0;">No locations are currently available — free one up in Manage Locations first.</p>
                    <?php else: ?>
                        <div class="task-locations-empty-state" id="taskLocationsEmptyState">
                            <div class="task-locations-empty-icon"><i class="fas fa-arrow-left"></i></div>
                            <p>Select a Task Type on the left to see its available locations here.</p>
                        </div>
                        <?php foreach (TASK_TYPES as $type): ?>
                            <?php $listId = 'task_locations_select_' . $type; ?>
                            <div id="taskTypeLocations_<?= htmlspecialchars($type) ?>" class="task-type-locations" style="display:none; width:100%;">
                                <?php if (empty($availableLocationsByType[$type])): ?>
                                    <p style="color: var(--gray-500); font-size: 13px; margin: 0;">No <?= htmlspecialchars($type) ?> locations are currently available.</p>
                                <?php else: ?>
                                    <div class="checklist-toolbar" style="width:100%;">
                                        <div class="search-input-wrap">
                                            <i class="fas fa-magnifying-glass"></i>
                                            <input type="text" class="form-input location-search-input" data-target="<?= htmlspecialchars($listId) ?>" placeholder="Search locations…">
                                        </div>
                                        <label class="checklist-select-all">
                                            <input type="checkbox" class="checklist-select-all-input" data-target="<?= htmlspecialchars($listId) ?>">
                                            Select All
                                        </label>
                                    </div>
                                    <div class="task-locations-meta-row">
                                        <span class="task-locations-meta-pill"><?= count($availableLocationsByType[$type]) ?> location<?= count($availableLocationsByType[$type]) === 1 ? '' : 's' ?> found</span>
                                        <span class="task-locations-meta-pill"><span data-count-for="<?= htmlspecialchars($listId) ?>">0</span><span>selected</span></span>
                                    </div>
                                    <div class="checklist-box">
                                        <div id="<?= htmlspecialchars($listId) ?>" class="checkbox-list">
                                            <?php foreach ($availableLocationsByType[$type] as $loc): ?>
                                                <label class="checkbox-list-item" data-label="<?= htmlspecialchars(strtolower($loc['name']), ENT_QUOTES) ?>">
                                                    <input type="checkbox" value="<?= (int) $loc['id'] ?>">
                                                    <i class="fas fa-location-dot"></i>
                                                    <span><?= htmlspecialchars($loc['name']) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('createTaskModal')">Cancel</button>
                <button type="button" class="btn btn-primary" id="createTaskSubmitBtn" onclick="submitCreateTask()"><i class="fas fa-circle-plus"></i> Create Task</button>
            </div>
        </div>
    </div>

    <!-- Message Modal -->
    <div id="messageModal" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title" id="msgTitle">Notification</h3>
                <button type="button" class="modal-close" onclick="closeModal('messageModal')">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div class="modal-body" id="msgBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('messageModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- Confirm Modal -->
    <div id="confirmModal" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Confirm Action</h3>
            </div>
            <div class="modal-body" id="confirmBody">Are you sure you want to proceed?</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('confirmModal')">Cancel</button>
                <button type="button" id="confirmBtn" class="btn btn-danger-solid">Yes, Proceed</button>
            </div>
        </div>
    </div>

    <script src="../scripts/sidebar-drawer.js"></script>
    <script src="../scripts/kebab.js"></script>
    <script src="../scripts/pagination.js"></script>
    <script src="../scripts/sort-table.js"></script>
    <script src="../scripts/date-picker.js"></script>
    <script src="../scripts/filters.js"></script>
    <script src="../scripts/toast.js"></script>
    <script src="../scripts/tasks.js"></script>
</body>

</html>
