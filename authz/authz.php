<?php
/**
 * The single shared authorization check. Every write endpoint in api/
 * calls this after requireLogin(true) and csrfVerify():
 *
 *   authorize('task.delete', ['task_id' => $taskId]);
 *
 * Just a role -> capability check (see authz/capabilities.php) plus an
 * audit-log entry on denial. There's no per-entity ownership/department
 * scoping here -- this app is used by one single QA team (see
 * rules/constants.php's removal of departments), so a capability granted to
 * a role applies to every row of that entity type, no additional IDOR check
 * needed beyond "does this role have this capability at all."
 */

require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/capabilities.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../rules/constants.php';

function authorize(string $capability, array $context = []): array
{
    $user = requireLogin(true);

    if (!roleHasCapability($user['role_name'], $capability)) {
        writeAuditLog($user['id'], 'authz.denied', $context['entity_type'] ?? null, $context['entity_id'] ?? null, [
            'capability' => $capability,
            'reason' => 'role_lacks_capability',
        ]);
        respondForbidden();
    }

    return $user;
}

function respondForbidden(): void
{
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'You do not have permission to perform this action.', 'type' => 'error']);
    exit;
}
