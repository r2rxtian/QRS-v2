<?php
require_once __DIR__ . '/../authz/capabilities.php';

/**
 * Canonical task-status vocabulary (see the approved plan, §5) — derived
 * from a task's active (unassigned_at IS NULL) qrs_task_locations rows,
 * never stored on qrs_tasks itself.
 *
 * $total/$completed/$inProgress reflect the task's real current
 * assignment -- callers that only care about "today" (e.g. dashboard
 * widgets) should scope their own query to task_date = today before
 * calling this; callers that want the task's full real state regardless
 * of which day its locations are scheduled for (e.g. Task Manager/All
 * Tasks, so a future-scheduled task still shows its real location count
 * and progress) should not date-scope their query at all.
 *
 * $isFutureScheduled + $scheduledDateLabel: when nothing has started yet
 * AND the task's earliest active location is scheduled for a date after
 * today, label it "Scheduled: <date>" instead of "Not Started" -- it
 * isn't overdue, it's just not due yet.
 */
function deriveTaskStatus(int $total, int $completed, int $inProgress, bool $isFutureScheduled = false, ?string $scheduledDateLabel = null): array
{
    if ($total === 0) {
        return ['code' => 'assign', 'label' => 'Assign Locations', 'class' => 'status-assign'];
    }

    if ($completed === 0 && $inProgress === 0) {
        if ($isFutureScheduled && $scheduledDateLabel !== null) {
            return ['code' => 'scheduled', 'label' => 'Scheduled: ' . $scheduledDateLabel, 'class' => 'status-scheduled'];
        }
        return ['code' => 'not_started', 'label' => 'Not Started', 'class' => 'status-not-started'];
    }

    if ($completed === $total) {
        return ['code' => 'completed', 'label' => 'Completed', 'class' => 'status-complete'];
    }

    return ['code' => 'ongoing', 'label' => 'On-going', 'class' => 'status-ongoing'];
}

/**
 * Stable, date-independent label for the "Status" checkbox filter in
 * tasks.php/qradmin.php's Filters panel -- $status['label'] itself isn't
 * usable there for a 'scheduled' task, since it embeds that task's own
 * date (e.g. "Scheduled: Aug 20, 2026") and would never match a fixed
 * checkbox value.
 */
function taskStatusFilterLabel(array $status): string
{
    $labels = [
        'completed' => 'Completed',
        'ongoing' => 'On-going',
        'not_started' => 'Not Started',
        'scheduled' => 'Scheduled',
        'assign' => 'Assign Locations',
    ];

    return $labels[$status['code']] ?? $status['label'];
}

function locationStatusBadge(bool $isAssigned): array
{
    return $isAssigned
        ? ['label' => 'Location Assigned', 'class' => 'status-assigned']
        : ['label' => 'Available', 'class' => 'status-available'];
}

/**
 * Role-based action routing: completed tasks always link to the report.
 * Field Workers on a not-started/on-going/unassigned task go to the scan
 * flow (their job is fieldwork). Everyone else gets the shared Task Detail
 * popup (see scripts/task-detail-modal.js) instead of a page navigation.
 *
 * Returns either ['type'=>'link','url'=>...,'label'=>...] for an <a href>,
 * or ['type'=>'popup','label'=>...] for a button that calls
 * openTaskDetailModal(taskId, taskName).
 */
function resolveTaskAction(array $status, int $taskId, string $roleName): array
{
    if ($status['code'] === 'completed') {
        return ['type' => 'popup', 'label' => 'View'];
    }

    if ($roleName === ROLE_USER) {
        return ['type' => 'link', 'url' => 'scan.php?task_id=' . $taskId, 'label' => 'Scan'];
    }

    return ['type' => 'popup', 'label' => $status['code'] === 'assign' ? 'Assign Locations' : 'View'];
}

/**
 * "Missed Out" dashboard stat (Sheet 4 of the client's spec): counted per
 * TASK, not per location -- a task counts as missed once at least one of
 * its active locations has a task_date that's fully passed while still not
 * completed. Returns ['missed' => int, 'total' => int], where total is the
 * number of distinct tasks with at least one active location assignment
 * (unassigned_at IS NULL), e.g. 2 tasks with 2 locations each -> total 2,
 * not 4.
 */
function countMissedTaskLocations(PDO $pdo, ?int $departmentId = null, ?string $taskType = null): array
{
    $sql = '
        SELECT
            COUNT(DISTINCT CASE WHEN x.missed_flag = 1 THEN x.task_id END) AS missed,
            COUNT(DISTINCT x.task_id) AS total
        FROM (
            SELECT tl.task_id,
                   CASE WHEN tl.task_date < CAST(SYSDATETIME() AS DATE) AND tl.status <> \'completed\' THEN 1 ELSE 0 END AS missed_flag
            FROM ' . T_TASK_LOCATIONS . ' tl
            JOIN ' . T_TASKS . ' t ON t.id = tl.task_id
            WHERE tl.unassigned_at IS NULL AND t.deleted_at IS NULL
    ';
    $params = [];
    if ($departmentId !== null) {
        $sql .= ' AND t.department_id = ?';
        $params[] = $departmentId;
    }
    if ($taskType !== null) {
        $sql .= ' AND t.task_type = ?';
        $params[] = $taskType;
    }
    $sql .= ') x';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    return ['missed' => (int) ($row['missed'] ?? 0), 'total' => (int) ($row['total'] ?? 0)];
}
