<?php
/**
 * Renders the "task detail" HTML fragment (today's assigned locations,
 * their status/remarks/photos, unassign controls, and an assign-more-
 * locations form) for injection into the shared Task Detail modal
 * (see components/appshell_end.php + scripts/task-detail-modal.js).
 *
 * GET, not POST -- purely a read, no state change, so no CSRF check
 * needed (matches every other read-only page in this app). Still
 * requires login and does the same department-scoped IDOR check every
 * other task_id-bound page does.
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

$taskStmt = $pdo->prepare('SELECT id, name, department_id, task_type FROM ' . T_TASKS . ' WHERE id = ? AND deleted_at IS NULL');
$taskStmt->execute([$taskId]);
$task = $taskStmt->fetch();

if (!$task) {
    http_response_code(404);
    echo '<p style="color: var(--danger);">Task not found.</p>';
    exit;
}

if (!$isAdmin && (int) $task['department_id'] !== (int) $currentUser['department_id']) {
    http_response_code(403);
    echo '<p style="color: var(--danger);">You do not have access to this task.</p>';
    exit;
}

$rowsStmt = $pdo->prepare('
    SELECT tl.id, l.id AS location_id, l.name AS location_name,
           tl.spot_spray_answer, tl.spot_spray_remark,
           tl.misting_answer, tl.misting_remark,
           tl.mist_blower_answer, tl.mist_blower_remark,
           tl.monitoring_answer, tl.monitoring_remark,
           tl.findings_observation, tl.completion_remark,
           tl.start_time, tl.end_time, tl.status,
           cu.full_name AS completed_by_name, cu.employee_id AS completed_by_code
    FROM ' . T_TASK_LOCATIONS . ' tl
    JOIN ' . T_LOCATIONS . ' l ON l.id = tl.location_id
    LEFT JOIN ' . T_USERS . ' cu ON cu.id = tl.completed_by
    WHERE tl.task_id = ? AND tl.task_date = CAST(SYSDATETIME() AS DATE) AND tl.unassigned_at IS NULL
    ORDER BY l.name
');
$rowsStmt->execute([$taskId]);
$assignedRows = $rowsStmt->fetchAll();

// Once every assigned location is completed, the task itself is done --
// unassigning/reassigning locations on a finished task doesn't make sense,
// so those controls are hidden here (same derivation qradmin.php/tasks.php
// use for the task's own status badge).
$totalForStatus = count($assignedRows);
$completedForStatus = count(array_filter($assignedRows, fn($r) => $r['status'] === 'completed'));
$inProgressForStatus = count(array_filter($assignedRows, fn($r) => $r['status'] === 'in_progress'));
$isTaskCompleted = deriveTaskStatus($totalForStatus, $completedForStatus, $inProgressForStatus)['code'] === 'completed';
$canModifyLocations = $canAssign && !$isTaskCompleted;
// Nothing to show in the per-location table until at least one location has
// actually been started -- a table full of "Not Started" / empty checklist /
// no photos rows is noise, not information. Before that point this modal's
// only real job is assigning locations to the task.
$hasStartedProgress = $inProgressForStatus > 0 || $completedForStatus > 0;

$photosByTaskLocation = [];
if (!empty($assignedRows)) {
    $tlIds = array_column($assignedRows, 'id');
    $placeholders = implode(',', array_fill(0, count($tlIds), '?'));
    $photoStmt = $pdo->prepare('
        SELECT task_location_id, photo_type, stored_filename
        FROM ' . T_TASK_LOCATION_PHOTOS . '
        WHERE task_location_id IN (' . $placeholders . ') AND deleted_at IS NULL
    ');
    $photoStmt->execute($tlIds);
    foreach ($photoStmt->fetchAll() as $photo) {
        $photosByTaskLocation[$photo['task_location_id']][$photo['photo_type']][] = $photo['stored_filename'];
    }
}

$availableLocations = [];
if ($canModifyLocations) {
    $availableLocationsStmt = $pdo->prepare('
        SELECT l.id, l.name
        FROM ' . T_LOCATIONS . ' l
        WHERE l.deleted_at IS NULL AND l.is_active = 1 AND l.location_type = ?
          AND NOT EXISTS (SELECT 1 FROM ' . T_TASK_LOCATIONS . ' tl WHERE tl.location_id = l.id AND tl.unassigned_at IS NULL AND tl.status <> \'completed\')
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

    <?php if (!empty($assignedRows)): ?>
        <div class="completed-locations-box">
            <div class="completed-locations-header">
                <div class="completed-locations-title"><i class="fas fa-circle-info"></i> Completed Locations</div>
                <span class="completed-locations-count"><i class="fas fa-check"></i> <?= count($assignedRows) ?> Location<?= count($assignedRows) === 1 ? '' : 's' ?> Completed</span>
            </div>
            <div class="location-tags">
                <?php foreach ($assignedRows as $row): ?>
                    <div class="location-tag">
                        <span><?= htmlspecialchars($row['location_name']) ?></span>
                        <span class="location-tag-completed-mark"><i class="fas fa-check"></i> Completed</span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="note-text">These are the locations completed for this task.</div>
        </div>
    <?php endif; ?>
<?php else: ?>
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
                    <th>Findings / Remarks</th>
                    <th>Photos</th>
                    <th>Completed By</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($assignedRows)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center; padding: 32px; color: var(--gray-500);">No locations assigned for today yet.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($assignedRows as $row): ?>
                    <?php
                    $photos = $photosByTaskLocation[$row['id']] ?? [];
                    $checklistBadges = [];
                    foreach (CHECKLIST_ITEMS as $key => $label) {
                        if ($row[$key . '_answer']) {
                            $checklistBadges[] = ['label' => $label, 'answer' => $row[$key . '_answer'], 'remark' => $row[$key . '_remark']];
                        }
                    }
                    $remarkParts = array_filter([$row['findings_observation'], $row['completion_remark']]);
                    ?>
                    <tr>
                        <td class="location-name"><i class="fas fa-location-dot" style="color: var(--primary-hover); margin-right: 6px;"></i><?= htmlspecialchars($row['location_name']) ?></td>
                        <td><?= $row['start_time'] ? htmlspecialchars((new DateTime($row['start_time']))->format('Y-m-d H:i:s')) : '<span class="status-pill">Not Started</span>' ?></td>
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
                                        <div class="checklist-answer-item" title="<?= htmlspecialchars($b['remark'] ?? '') ?>">
                                            <span class="checklist-answer-icon <?= $answerClass ?>"><i class="fas <?= $answerIcon ?>"></i></span>
                                            <span class="checklist-answer-label"><?= htmlspecialchars($b['label']) ?></span>
                                            <span class="checklist-answer-pill <?= $answerClass ?>"><?= htmlspecialchars($b['answer']) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?php if ($remarkParts): ?><?= htmlspecialchars(implode(' / ', $remarkParts)) ?><?php else: ?><span class="cell-icon-text"><i class="fas fa-comment"></i> No remarks yet</span><?php endif; ?></td>
                        <td>
                            <?php if (empty($photos['after'])): ?>
                                <span class="cell-icon-text"><i class="fas fa-image"></i> No photos</span>
                            <?php else: ?>
                                <?php foreach ($photos['after'] as $filename): ?>
                                    <img src="<?= htmlspecialchars(UPLOAD_URL_PATH . $filename) ?>" alt="Photo" class="photo" onclick="zoomPhoto(this)">
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($row['completed_by_name'] ?? '') ?><?= $row['completed_by_code'] ? ' <span style="color: var(--gray-400); font-size:12px;">(' . htmlspecialchars(substr($row['completed_by_code'], -3)) . ')</span>' : '' ?></td>
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
