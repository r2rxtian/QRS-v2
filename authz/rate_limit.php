<?php
require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../rules/constants.php';

/**
 * Returns true and records the event if under the limit; false (does not
 * record another event) if the limit has already been reached.
 */
function checkRateLimit(string $key, int $maxEvents, int $windowMinutes): bool
{
    $pdo = db();

    // DATEADD's interval argument can't be a bound parameter (SQL Server sends
    // it as nvarchar, which DATEADD rejects) -- compute the threshold in PHP instead.
    $threshold = (new DateTime())->modify("-$windowMinutes minutes")->format('Y-m-d H:i:s');

    $countStmt = $pdo->prepare('
        SELECT COUNT(*) FROM ' . T_RATE_LIMIT_EVENTS . '
        WHERE event_key = ? AND occurred_at > ?
    ');
    $countStmt->execute([$key, $threshold]);

    if ((int) $countStmt->fetchColumn() >= $maxEvents) {
        return false;
    }

    $pdo->prepare('INSERT INTO ' . T_RATE_LIMIT_EVENTS . ' (event_key, ip_address) VALUES (?, ?)')
        ->execute([$key, $_SERVER['REMOTE_ADDR'] ?? null]);

    return true;
}

function respondRateLimited(): void
{
    http_response_code(429);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Too many requests. Please slow down and try again shortly.', 'type' => 'error']);
    exit;
}
