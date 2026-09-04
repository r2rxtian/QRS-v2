<?php
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../conn/db.php';
require_once __DIR__ . '/../../rules/constants.php';

// Authenticate once, then release PHP's session-file lock. Holding that lock
// for the life of an SSE response would block this user's AJAX requests.
startSession();
$previousActivity = $_SESSION['last_activity_at'] ?? null;
requireLogin(true);
if ($previousActivity !== null) {
    $_SESSION['last_activity_at'] = $previousActivity;
}
session_write_close();

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-transform');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

set_time_limit(35);
ignore_user_abort(true);

while (ob_get_level() > 0) {
    ob_end_flush();
}

$cursor = max(
    0,
    (int) ($_GET['cursor'] ?? 0),
    (int) ($_SERVER['HTTP_LAST_EVENT_ID'] ?? 0)
);
$pdo = db();

// If no cursor or 0 was passed, start from the latest audit log entry
// so fresh connections do NOT replay historical audit records on page load.
if ($cursor <= 0) {
    $cursor = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM ' . T_AUDIT_LOG)->fetchColumn();
}
$eventsStmt = $pdo->prepare('
    SELECT TOP 200 id, action, entity_type, entity_id
    FROM ' . T_AUDIT_LOG . '
    WHERE id > ?
    ORDER BY id ASC
');

echo "retry: 2000\n\n";
flush();

$startedAt = time();
$lastHeartbeat = 0;
while (!connection_aborted() && time() - $startedAt < 30) {
    $eventsStmt->execute([$cursor]);
    $events = $eventsStmt->fetchAll();

    if ($events) {
        $cursor = (int) end($events)['id'];
        $scopes = ['audit'];
        foreach ($events as $event) {
            $entityType = $event['entity_type'];
            if ($entityType === 'user') {
                $scopes[] = 'users';
            } elseif ($entityType === 'location') {
                $scopes[] = 'locations';
                $scopes[] = 'tasks';
            } elseif ($entityType === 'task' || $entityType === 'task_location') {
                array_push($scopes, 'tasks', 'locations', 'dashboard', 'reports', 'scan');
            } else {
                $scopes[] = 'all';
            }
        }

        echo 'id: ' . $cursor . "\n";
        echo "event: sync\n";
        echo 'data: ' . json_encode([
            'cursor' => $cursor,
            'scopes' => array_values(array_unique($scopes)),
        ]) . "\n\n";
        flush();
    }

    if (time() - $lastHeartbeat >= 10) {
        echo ': heartbeat ' . time() . "\n\n";
        flush();
        $lastHeartbeat = time();
    }

    sleep(1);
}
