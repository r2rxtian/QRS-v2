<?php
/**
 * The single shared authorization + IDOR check. Every write endpoint in
 * api/ calls this after requireLogin(true) and csrfVerify():
 *
 *   authorize('task.delete', ['task_id' => $taskId]);
 *
 * $context may carry 'task_id' or 'department_id' — the target's
 * department is loaded FRESH FROM THE DB (never trusted from the request)
 * and compared against the acting user's own session department.
 * Administrators bypass department scoping. Everyone else is denied if the
 * capability doesn't match their role, or if the target belongs to a
 * different department than their own.
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
        writeAuditLog($user['id'], 'authz.denied', $context['entity_type'] ?? null, $context['entity_id'] ?? null, $user['department_id'], [
            'capability' => $capability,
            'reason' => 'role_lacks_capability',
        ]);
        respondForbidden();
    }

    if ($user['role_name'] === ROLE_ADMIN) {
        return $user;
    }

    $targetDepartmentId = resolveContextDepartmentId($context);

    if ($targetDepartmentId !== null && $targetDepartmentId !== (int) $user['department_id']) {
        writeAuditLog($user['id'], 'authz.denied', $context['entity_type'] ?? null, $context['entity_id'] ?? null, $user['department_id'], [
            'capability' => $capability,
            'reason' => 'department_mismatch',
            'target_department_id' => $targetDepartmentId,
        ]);
        respondForbidden();
    }

    return $user;
}

function resolveContextDepartmentId(array $context): ?int
{
    if (isset($context['department_id'])) {
        return (int) $context['department_id'];
    }

    if (isset($context['task_id'])) {
        $stmt = db()->prepare('SELECT department_id FROM ' . T_TASKS . ' WHERE id = ?');
        $stmt->execute([(int) $context['task_id']]);
        $deptId = $stmt->fetchColumn();
        return ($deptId !== false && $deptId !== null) ? (int) $deptId : null;
    }

    // Locations have no department_id (shared cross-department master list —
    // see authz/capabilities.php); nothing further to scope here.
    return null;
}

function respondForbidden(): void
{
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'You do not have permission to perform this action.', 'type' => 'error']);
    exit;
}
