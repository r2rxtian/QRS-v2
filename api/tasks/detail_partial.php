<?php
/**
 * Renders the "task detail" HTML fragment (currently assigned locations,
 * their status/remarks/photos, unassign controls, and an assign-more-
 * locations form) for injection into the shared Task Detail modal
 * (see components/appshell_end.php + scripts/task-detail-modal.js).
 *
 * GET, not POST -- purely a read, no state change, so no CSRF check
 * needed (matches every other read-only page in this app). Still requires
 * login.
 */
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../conn/db.php';
require_once __DIR__ . '/../../rules/constants.php';
require_once __DIR__ . '/../../rules/status.php';
require_once __DIR__ . '/../../authz/capabilities.php';

$currentUser = requireLogin(true);
$isAdmin = $currentUser['role_name'] === ROLE_ADMIN;
$canAssign = roleHasCapability($currentUser['role_name'], 'task_location.assign');

$taskId = (int) ($_GET['task_id'] ?? 0);
if ($taskId <= 0) {
    http_response_code(400);
    echo '<p style="color: var(--danger);">Invalid task.</p>';
    exit;
}

$pdo = db();

$taskStmt = $pdo->prepare('SELECT id, name, task_type FROM ' . T_TASKS . ' WHERE id = ? AND deleted_at IS NULL');
$taskStmt->execute([$taskId]);
$task = $taskStmt->fetch();

if (!$task) {
    http_response_code(404);
    echo '<p style="color: var(--danger);">Task not found.</p>';
    exit;
}

// Task-wide totals -- still-relevant condition (unassigned_at IS NULL OR
// unassigned_by IS NULL, see rules/status.php's sweepResolvedLocations())
// so these stay accurate even once every location has auto-unassigned
// itself after resolving. Matches tasks.php/qradmin.php's own aggregate
// exactly, since this is the same task viewed from its detail modal.
$statusStmt = $pdo->prepare('
    SELECT
        COUNT(tl.id) AS total_locations,
        SUM(CASE WHEN tl.status = \'completed\' THEN 1 ELSE 0 END) AS completed_locations,
        SUM(CASE WHEN tl.status = \'in_progress\' AND DATEDIFF(SECOND, tl.assigned_at, SYSDATETIME()) < 86400 THEN 1 ELSE 0 END) AS in_progress_locations,
        SUM(CASE WHEN tl.status <> \'completed\' AND DATEDIFF(SECOND, tl.assigned_at, COALESCE(tl.unassigned_at, SYSDATETIME())) >= 86400 THEN 1 ELSE 0 END) AS missed_locations
    FROM ' . T_TASK_LOCATIONS . ' tl
    WHERE tl.task_id = ? AND (tl.unassigned_at IS NULL OR tl.unassigned_by IS NULL)
      AND tl.id = (
          SELECT MAX(tl2.id) FROM ' . T_TASK_LOCATIONS . ' tl2
          WHERE tl2.task_id = tl.task_id AND tl2.location_id = tl.location_id
      )
');
$statusStmt->execute([$taskId]);
$statusRow = $statusStmt->fetch();
$totalForStatus = (int) $statusRow['total_locations'];
$completedForStatus = (int) $statusRow['completed_locations'];
$inProgressForStatus = (int) $statusRow['in_progress_locations'];
$missedForStatus = (int) $statusRow['missed_locations'];
// Once every assigned location is completed, the task itself is done --
// unassigning/reassigning locations on a finished task doesn't make sense,
// so those controls are hidden here (same derivation qradmin.php/tasks.php
// use for the task's own status badge). A task with any Missed Out
// location is closed the same way, on purpose: "when the task is done,
// it's done" applies to a miss just as much as a completion -- a task
// whose only location missed would otherwise sit at "Not Started" forever
// (deriveTaskStatus() has no concept of missed), leaving Assign/Unassign
// open indefinitely even though nothing about it can ever be completed.
$isTaskCompleted = deriveTaskStatus($totalForStatus, $completedForStatus, $inProgressForStatus)['code'] === 'completed';
$hasMissed = $missedForStatus > 0;
$canModifyLocations = $canAssign && !$isTaskCompleted && !$hasMissed;

// Currently-active locations only -- genuinely still on the roster
// (pending or in_progress), not yet resolved. Drives the Unassign tag list
// and the detail overview table below. Completed/Missed locations don't
// live here once resolved -- they auto-unassign and move to their own
// permanent-record boxes instead (see $completedRows/$missedRows below).
// is_missed still guards the brief window between a ticket crossing 24
// hours and the next page load's sweep picking it up.
$rowsStmt = $pdo->prepare('
    SELECT tl.id, l.id AS location_id, l.name AS location_name,
           tl.spot_spray_answer, tl.spot_spray_remark,
           tl.misting_answer, tl.misting_remark,
           tl.mist_blower_answer, tl.mist_blower_remark,
           tl.monitoring_answer, tl.monitoring_remark,
           tl.findings_observation,
           tl.start_time, tl.end_time, tl.status,
           CASE WHEN tl.status <> \'completed\' AND DATEDIFF(SECOND, tl.assigned_at, SYSDATETIME()) >= 86400 THEN 1 ELSE 0 END AS is_missed
    FROM ' . T_TASK_LOCATIONS . ' tl
    JOIN ' . T_LOCATIONS . ' l ON l.id = tl.location_id
    WHERE tl.task_id = ? AND tl.unassigned_at IS NULL
      AND tl.id = (
          SELECT MAX(tl2.id) FROM ' . T_TASK_LOCATIONS . ' tl2
          WHERE tl2.task_id = tl.task_id AND tl2.location_id = tl.location_id
      )
    ORDER BY l.name
');
$rowsStmt->execute([$taskId]);
$assignedRows = $rowsStmt->fetchAll();

// Nothing to show in the per-location table until at least one location has
// actually been started -- a table full of "Not Started" / empty
// checklist / no photos rows is noise, not information. Before that point
// this modal's only real job is assigning locations to the task.
$hasStartedProgress = count(array_filter($assignedRows, fn($r) => $r['start_time'] !== null || (int) $r['is_missed'] === 1)) > 0;

// Completed Locations -- permanent record, same still-relevant condition
// as the totals query above, so it keeps showing a location even after it
// auto-unassigns itself once completed.
$completedRowsStmt = $pdo->prepare('
    SELECT l.name AS location_name, ' . fullNameSql('cuml', 'cu') . ' AS completed_by_name, cu.employee_id AS completed_by_code
    FROM ' . T_TASK_LOCATIONS . ' tl
    JOIN ' . T_LOCATIONS . ' l ON l.id = tl.location_id
    LEFT JOIN ' . T_USERS . ' cu ON cu.id = tl.completed_by
    LEFT JOIN ' . T_MASTER_LIST . ' cuml ON cuml.EmployeeID = cu.employee_id
    WHERE tl.task_id = ? AND (tl.unassigned_at IS NULL OR tl.unassigned_by IS NULL)
      AND tl.status = \'completed\'
      AND tl.id = (
          SELECT MAX(tl2.id) FROM ' . T_TASK_LOCATIONS . ' tl2
          WHERE tl2.task_id = tl.task_id AND tl2.location_id = tl.location_id
      )
    ORDER BY l.name
');
$completedRowsStmt->execute([$taskId]);
$completedRows = $completedRowsStmt->fetchAll();

// Missed Locations -- same permanent-record treatment as Completed above,
// using the frozen missed formula so it keeps reading as missed after
// auto-unassign instead of comparing against the live clock.
$missedRowsStmt = $pdo->prepare('
    SELECT l.name AS location_name
    FROM ' . T_TASK_LOCATIONS . ' tl
    JOIN ' . T_LOCATIONS . ' l ON l.id = tl.location_id
    WHERE tl.task_id = ? AND (tl.unassigned_at IS NULL OR tl.unassigned_by IS NULL)
      AND tl.status <> \'completed\'
      AND DATEDIFF(SECOND, tl.assigned_at, COALESCE(tl.unassigned_at, SYSDATETIME())) >= 86400
      AND tl.id = (
          SELECT MAX(tl2.id) FROM ' . T_TASK_LOCATIONS . ' tl2
          WHERE tl2.task_id = tl.task_id AND tl2.location_id = tl.location_id
      )
    ORDER BY l.name
');
$missedRowsStmt->execute([$taskId]);
$missedRows = $missedRowsStmt->fetchAll();

$availableLocations = [];
if ($canModifyLocations) {
    // Once a location's ticket resolves (Completed or Missed), it
    // auto-unassigns itself (see sweepResolvedLocations()) and becomes
    // free for a new task on its own -- no special-case exclusion needed
    // here beyond the plain "is anything currently holding it" check.
    $availableLocationsStmt = $pdo->prepare('
        SELECT l.id, l.name
        FROM ' . T_LOCATIONS . ' l
        WHERE l.deleted_at IS NULL AND l.is_active = 1 AND l.location_type = ?
          AND NOT EXISTS (
              SELECT 1 FROM ' . T_TASK_LOCATIONS . ' tl WHERE tl.location_id = l.id AND tl.unassigned_at IS NULL
                AND tl.id = (SELECT MAX(tl2.id) FROM ' . T_TASK_LOCATIONS . ' tl2 WHERE tl2.task_id = tl.task_id AND tl2.location_id = tl.location_id)
          )
        ORDER BY l.name
    ');
    $availableLocationsStmt->execute([$task['task_type']]);
    $availableLocations = $availableLocationsStmt->fetchAll();
}
?>
<?php if ($isTaskCompleted): ?>
    <div class="completed-task-notice">
        <div class="completed-task-notice-icon"><i class="fas fa-circle-check"></i></div>
        <div class="completed-task-notice-text">
            <h4>This task is complete</h4>
            <p>Every location's checklist, remarks, and photos are recorded in Task Report.</p>
        </div>
        <a href="../pages/task_report.php?q=<?= urlencode($task['name']) ?>" class="btn btn-primary btn-sm">
            <i class="fas fa-file-lines"></i> View in Task Report
        </a>
    </div>
<?php endif; ?>

<?php if (!empty($completedRows)): ?>
    <div class="completed-locations-box">
        <div class="completed-locations-header">
            <div class="completed-locations-title"><i class="fas fa-circle-info"></i> Completed Locations</div>
            <span class="completed-locations-count"><i class="fas fa-check"></i> <?= count($completedRows) ?> Location<?= count($completedRows) === 1 ? '' : 's' ?> Completed</span>
        </div>
        <div class="location-tags">
            <?php foreach ($completedRows as $row): ?>
                <div class="location-tag">
                    <span><?= htmlspecialchars($row['location_name']) ?></span>
                    <span class="location-tag-completed-mark"><i class="fas fa-check"></i> Completed</span>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="note-text">These are the locations completed for this task.</div>
    </div>
<?php endif; ?>

<?php if (!empty($missedRows)): ?>
    <div class="completed-locations-box">
        <div class="completed-locations-header">
            <div class="completed-locations-title"><i class="fas fa-circle-info"></i> Missed Locations</div>
            <span class="completed-locations-count status-missed"><i class="fas fa-triangle-exclamation"></i> <?= count($missedRows) ?> Location<?= count($missedRows) === 1 ? '' : 's' ?> Missed</span>
        </div>
        <div class="location-tags">
            <?php foreach ($missedRows as $row): ?>
                <div class="location-tag">
                    <span><?= htmlspecialchars($row['location_name']) ?></span>
                    <span class="location-tag-completed-mark status-missed"><i class="fas fa-triangle-exclamation"></i> Missed Out</span>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="note-text">These are the locations that were never finished within 24 hours for this task.</div>
    </div>
<?php endif; ?>

<?php if (!$isTaskCompleted): ?>
    <?php if (!empty($assignedRows)): ?>
        <div class="assign-summary">
            <div class="info-box">
                <div class="info-box-title"><i class="fas fa-circle-info"></i> Assigned Locations</div>
                <div class="location-tags">
                    <?php foreach ($assignedRows as $row): ?>
                        <div class="location-tag" data-location-id="<?= (int) $row['location_id'] ?>">
                            <span><?= htmlspecialchars($row['location_name']) ?></span>
                            <?php if ($canModifyLocations): ?>
                                <button type="button" onclick="unassignLocationInModal(this)">✕ Unassign</button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($canModifyLocations): ?>
                    <div class="note-text">Note: Unassigning a location will make it available for assignment to other tasks.</div>
                <?php endif; ?>
            </div>
            <div class="stat-box">
                <i class="fas fa-location-dot"></i>
                <div class="stat-box-value"><?= count($assignedRows) ?></div>
                <div class="stat-box-label">Total Assigned<br>location<?= count($assignedRows) === 1 ? '' : 's' ?></div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($hasStartedProgress): ?>
    <h4 style="margin: 20px 0 12px; font-size: 15px;">Assigned Locations Overview</h4>
    <div class="table-wrapper">
        <table id="taskDetailTable">
            <thead>
                <tr>
                    <th>Location Name</th>
                    <th>Task Started</th>
                    <th>Checklist</th>
                    <th>Findings / Observations</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($assignedRows)): ?>
                    <tr>
                        <td colspan="4" style="text-align:center; padding: 32px; color: var(--gray-500);">No locations assigned for today yet.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($assignedRows as $row): ?>
                    <?php
                    $checklistBadges = [];
                    foreach (CHECKLIST_ITEMS as $key => $label) {
                        if ($row[$key . '_answer']) {
                            $checklistBadges[] = ['label' => $label, 'answer' => $row[$key . '_answer'], 'remark' => $row[$key . '_remark']];
                        }
                    }
                    // Completion Remarks isn't shown here on purpose -- a location
                    // only ever gets one once it's completed, and a completed
                    // location auto-unassigns out of $assignedRows (see the box
                    // above) around the same time, so it belongs to Task Report's
                    // permanent record instead, not this ongoing-progress view.
                    ?>
                    <tr>
                        <td class="location-name"><i class="fas fa-location-dot" style="color: var(--primary-hover); margin-right: 6px;"></i><?= htmlspecialchars($row['location_name']) ?></td>
                        <td>
                            <?php if ((int) $row['is_missed'] === 1): ?>
                                <span class="status-badge status-missed"><i class="fas fa-triangle-exclamation"></i> Missed Out</span>
                            <?php elseif ($row['start_time']): ?>
                                <?= htmlspecialchars((new DateTime($row['start_time']))->format('Y-m-d H:i:s')) ?>
                            <?php else: ?>
                                <span class="status-pill">Not Started</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (empty($checklistBadges)): ?>
                                <span style="color: var(--gray-400);">—</span>
                            <?php else: ?>
                                <div class="checklist-answer-list">
                                    <?php foreach ($checklistBadges as $b): ?>
                                        <?php
                                        $answerClass = $b['answer'] === 'Yes' ? 'yes' : ($b['answer'] === 'No' ? 'no' : 'na');
                                        $answerIcon = $answerClass === 'yes' ? 'fa-check' : ($answerClass === 'no' ? 'fa-xmark' : 'fa-minus');
                                        ?>
                                        <div class="checklist-answer-group">
                                            <div class="checklist-answer-item">
                                                <span class="checklist-answer-icon <?= $answerClass ?>"><i class="fas <?= $answerIcon ?>"></i></span>
                                                <span class="checklist-answer-label"><?= htmlspecialchars($b['label']) ?></span>
                                                <span class="checklist-answer-pill <?= $answerClass ?>"><?= htmlspecialchars($b['answer']) ?></span>
                                            </div>
                                            <?php if (!empty($b['remark'])): ?>
                                                <div class="checklist-answer-remark"><i class="fas fa-comment-dots"></i> <?= htmlspecialchars($b['remark']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="remarks-cell">
                            <?php if ($row['findings_observation']): ?>
                                <?= htmlspecialchars($row['findings_observation']) ?>
                            <?php else: ?>
                                <span class="cell-icon-text"><i class="fas fa-comment"></i> No findings yet</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($canModifyLocations): ?>
    <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--gray-200);">
        <h4 style="margin: 0 0 12px; font-size: 15px;">Assign More Locations</h4>
        <?php if (empty($availableLocations)): ?>
            <p style="color: var(--gray-500); font-size: 14px;">All locations are currently assigned to a task.</p>
        <?php else: ?>
            <div class="checklist-toolbar">
                <div class="search-input-wrap">
                    <i class="fas fa-magnifying-glass"></i>
                    <input type="text" class="form-input location-search-input" data-target="modalLocationSelect" placeholder="Search locations…">
                </div>
                <label class="checklist-select-all">
                    <input type="checkbox" class="checklist-select-all-input" data-target="modalLocationSelect">
                    Select All
                </label>
            </div>
            <div class="checklist-box">
                <div id="modalLocationSelect" class="checkbox-list">
                    <?php foreach ($availableLocations as $loc): ?>
                        <label class="checkbox-list-item" data-label="<?= htmlspecialchars(strtolower($loc['name']), ENT_QUOTES) ?>">
                            <input type="checkbox" value="<?= (int) $loc['id'] ?>">
                            <i class="fas fa-location-dot"></i>
                            <span><?= htmlspecialchars($loc['name']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="checklist-footer">
                    <span class="checklist-selected-count"><i class="fas fa-circle-check"></i> <span data-count-for="modalLocationSelect">0</span> location(s) selected</span>
                    <button type="button" class="btn btn-primary" onclick="submitAssignLocationsInModal()"><i class="fas fa-circle-plus"></i> Assign Selected Locations</button>
                </div>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
