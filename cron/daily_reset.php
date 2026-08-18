<?php
/**
 * CLI-only. Run once daily (e.g. via Windows Task Scheduler, just after
 * midnight): php cron/daily_reset.php
 *
 * For every recurring task, finds locations still "on the roster" -- their
 * most recent task_locations row has unassigned_at IS NULL, meaning nobody
 * explicitly removed them -- and, if that location doesn't already have a
 * row for today (e.g. because it was just re-assigned this morning), rolls
 * it forward into a fresh 'pending' row for today.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script is CLI-only.');
}

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../rules/constants.php';

$pdo = db();

$activePairs = $pdo->query('
    SELECT tl.task_id, tl.location_id, tl.assigned_by
    FROM ' . T_TASK_LOCATIONS . ' tl
    JOIN ' . T_TASKS . ' t ON t.id = tl.task_id
    WHERE t.is_recurring = 1 AND t.deleted_at IS NULL
      AND tl.unassigned_at IS NULL
      AND tl.id = (
          SELECT MAX(tl2.id) FROM ' . T_TASK_LOCATIONS . ' tl2
          WHERE tl2.task_id = tl.task_id AND tl2.location_id = tl.location_id
      )
')->fetchAll();

$checkToday = $pdo->prepare('
    SELECT COUNT(*) FROM ' . T_TASK_LOCATIONS . '
    WHERE task_id = ? AND location_id = ? AND task_date = CAST(SYSDATETIME() AS DATE)
');
$insert = $pdo->prepare('
    INSERT INTO ' . T_TASK_LOCATIONS . ' (task_id, location_id, task_date, assigned_by, status)
    VALUES (?, ?, CAST(SYSDATETIME() AS DATE), ?, \'pending\')
');

$rolled = 0;
$skipped = 0;

foreach ($activePairs as $pair) {
    $checkToday->execute([$pair['task_id'], $pair['location_id']]);
    if ((int) $checkToday->fetchColumn() > 0) {
        $skipped++;
        continue;
    }

    $insert->execute([$pair['task_id'], $pair['location_id'], $pair['assigned_by']]);
    $rolled++;
}

echo "Daily reset complete: $rolled location(s) rolled forward, $skipped already had a row for today.\n";
