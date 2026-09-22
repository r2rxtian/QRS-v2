<?php
require_once __DIR__ . '/../authz/capabilities.php';

/**
 * SQL expression for the authoritative start of a task-location's 24-hour
 * window.  A supplied scheduled_at wins when it is later than assignment;
 * a late assignment therefore receives a fresh 24 hours.  NULL scheduled_at
 * keeps the original date-only behavior (future dates begin at midnight,
 * while today's rows begin at assigned_at).
 */
function taskLocationWindowStartSql(string $alias = ''): string
{
    $prefix = $alias === '' ? '' : rtrim($alias, '.') . '.';
    $scheduled = $prefix . 'scheduled_at';
    $assigned = $prefix . 'assigned_at';
    $taskDate = $prefix . 'task_date';

    return "CASE WHEN {$scheduled} IS NOT NULL THEN\n"
        . "             CASE WHEN {$scheduled} > {$assigned} THEN {$scheduled} ELSE {$assigned} END\n"
        . "         ELSE CASE WHEN {$taskDate} > CAST({$assigned} AS DATE)\n"
        . "                   THEN CAST({$taskDate} AS DATETIME2) ELSE {$assigned} END\n"
        . "    END";
}

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
 * Atomically expire due task-location assignments using SQL Server time.
 * Passing IDs scopes the transition for the countdown AJAX endpoint; NULL
 * processes every due assignment for the page-load safety sweep.
 *
 * @return array<int, array{id:int, task_id:int, location_id:int}>
 */
function expireDueTaskLocations(PDO $pdo, ?array $taskLocationIds = null): array
{
    $params = [];
    $idPredicate = '';

    if ($taskLocationIds !== null) {
        $taskLocationIds = array_values(array_unique(array_filter(
            array_map('intval', $taskLocationIds),
            static fn(int $id): bool => $id > 0
        )));
        if (!$taskLocationIds) {
            return [];
        }

        $idPredicate = ' AND id IN (' . implode(',', array_fill(0, count($taskLocationIds), '?')) . ')';
        $params = $taskLocationIds;
    }

    $stmt = $pdo->prepare('
        UPDATE ' . T_TASK_LOCATIONS . '
        SET status = \'missed\',
            unassigned_at = COALESCE(unassigned_at, SYSDATETIME()),
            unassigned_by = NULL,
            updated_at = SYSDATETIME()
        OUTPUT INSERTED.id, INSERTED.task_id, INSERTED.location_id
        WHERE unassigned_at IS NULL
          AND status IN (\'pending\', \'in_progress\')
          AND SYSDATETIME() >= ' . taskLocationWindowStartSql() . '
          AND DATEDIFF(SECOND, ' . taskLocationWindowStartSql() . ', SYSDATETIME()) >= ' . TASK_LOCATION_EXPIRATION_SECONDS .
          $idPredicate . '
    ');
    $stmt->execute($params);

    return array_map(static fn(array $row): array => [
        'id' => (int) $row['id'],
        'task_id' => (int) $row['task_id'],
        'location_id' => (int) $row['location_id'],
    ], $stmt->fetchAll());
}

/**
 * Claim one global sweep interval. The conditional UPDATE is atomic, so
 * simultaneous PHP requests cannot both claim the same 30-second window.
 */
function claimResolvedLocationSweep(PDO $pdo): bool
{
    $stmt = $pdo->prepare('
        UPDATE ' . T_MAINTENANCE_STATE . '
        SET last_run_at = SYSDATETIME()
        OUTPUT INSERTED.job_name
        WHERE job_name = ?
          AND (last_run_at IS NULL
               OR DATEDIFF(SECOND, last_run_at, SYSDATETIME()) >= ?)
    ');
    $stmt->execute(['resolve_task_locations', RESOLVED_LOCATION_SWEEP_INTERVAL_SECONDS]);

    return $stmt->fetchColumn() !== false;
}

/**
 * Role-based action routing: completed tasks always link to the report.
 * Field Workers on a not-started/unassigned task go to the scan flow
 * (their job is fieldwork) -- unless the task has a missed-out location,
 * in which case it's locked the same way a completed task is (see the
 * Amendment 7 lockout used elsewhere for $hasMissed): scan.php itself
 * already refuses to resolve a missed ticket, so routing there would just
 * dead-end into a generic error instead of surfacing what actually
 * happened. Everyone else gets the shared Task Detail popup (see
 * scripts/task-detail-modal.js) instead of a page navigation.
 *
 * An On-going task also routes a Field Worker to View rather than Scan --
 * "on-going" means at least one of its locations is already in_progress,
 * i.e. someone (possibly a different worker) is actively mid-checklist on
 * it right now. Landing a second worker straight into the scan flow for
 * that task risked them scanning a location someone else already started
 * and resuming/overwriting that in-progress check. View still lets them
 * see the task's real progress; scanning a location that's genuinely still
 * pending is one tap away from there.
 *
 * Returns either ['type'=>'link','url'=>...,'label'=>...] for an <a href>,
 * or ['type'=>'popup','label'=>...] for a button that calls
 * openTaskDetailModal(taskId, taskName).
 */
function resolveTaskAction(array $status, int $taskId, string $roleName, bool $hasMissed = false): array
{
    if ($status['code'] === 'completed' || $hasMissed || $status['code'] === 'scheduled') {
        return ['type' => 'popup', 'label' => 'View'];
    }

    if ($roleName === ROLE_USER) {
        if ($status['code'] === 'ongoing') {
            return ['type' => 'popup', 'label' => 'View'];
        }
        return ['type' => 'link', 'url' => 'scan.php?task_id=' . $taskId, 'label' => 'Scan'];
    }

    return ['type' => 'popup', 'label' => $status['code'] === 'assign' ? 'Assign Locations' : 'View'];
}

/**
 * Auto-unassigns any location whose current ticket has resolved -- either
 * Completed, or Missed Out (open 24+ hours, still not completed) -- so it
 * immediately becomes available for a different task, without anyone
 * clicking Unassign. Lazily triggered: called once from
 * components/appshell_start.php on every authenticated page load, so the
 * next person to load any page after a ticket resolves is what fires the
 * cleanup. Calls are globally throttled through qrs_maintenance_state; no
 * cron is required, matching this project's on-demand design.
 *
 * unassigned_by is deliberately left NULL -- every real manual Unassign
 * click (api/task_locations/unassign.php) always records a real user id
 * there, so "unassigned_by IS NULL" becomes a reliable, permanent marker
 * for "the system closed this out because it resolved", distinct from "an
 * Admin manually removed it for an unrelated reason" (e.g. correcting a
 * mistake). Every query that needs a task's real historical totals/badges
 * to survive this auto-unassign relies on that marker (see the "still
 * relevant" join condition used throughout this file and elsewhere).
 */
function sweepResolvedLocations(PDO $pdo): void
{
    if (!claimResolvedLocationSweep($pdo)) {
        return;
    }

    // The countdown endpoint and this fallback now share one transition and
    // one expiration constant.
    expireDueTaskLocations($pdo);

    $pdo->exec('
        UPDATE ' . T_TASK_LOCATIONS . '
        SET unassigned_at = SYSDATETIME(), unassigned_by = NULL
        WHERE unassigned_at IS NULL
          AND status IN (\'completed\', \'missed\')
    ');
}

/**
 * "Missed Out" dashboard stat (Sheet 4 of the client's spec): counted per
 * TASK, not per location -- a task counts as missed once at least one of
 * its locations' CURRENT ticket (its most recent task_locations row) has
 * been open 24 hours or more since it was created (assigned_at), while
 * still not completed. Deliberately elapsed-time-based, not calendar-day-
 * based -- a ticket created at 5pm is only "missed" at 5pm the next day,
 * not at the next midnight.
 *
 * Future-scheduled tasks (their effective date/time is after now) are inactive
 * and cannot be missed.
 */
function countMissedTaskLocations(PDO $pdo, ?string $taskType = null): array
{
    $sql = '
        SELECT
            COUNT(DISTINCT CASE WHEN x.missed_flag = 1 THEN x.task_id END) AS missed,
            COUNT(DISTINCT x.task_id) AS total
        FROM (
            SELECT tl.task_id,
                   CASE WHEN SYSDATETIME() >= ' . taskLocationWindowStartSql('tl') . '
                             AND DATEDIFF(SECOND, ' . taskLocationWindowStartSql('tl') . ', COALESCE(tl.unassigned_at, SYSDATETIME())) >= ' . TASK_LOCATION_EXPIRATION_SECONDS . '
                             AND tl.status <> \'completed\' THEN 1 ELSE 0 END AS missed_flag
            FROM ' . T_TASK_LOCATIONS . ' tl
            JOIN ' . T_TASKS . ' t ON t.id = tl.task_id
            WHERE (tl.unassigned_at IS NULL OR tl.unassigned_by IS NULL) AND t.deleted_at IS NULL
              AND tl.id = (
                  SELECT MAX(tl2.id) FROM ' . T_TASK_LOCATIONS . ' tl2
                  WHERE tl2.task_id = tl.task_id AND tl2.location_id = tl.location_id
              )
    ';
    $params = [];
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
