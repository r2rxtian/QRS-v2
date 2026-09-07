<?php
require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../rules/constants.php';

/**
 * Writes one row to the audit log. $details is JSON-encoded with the HEX_*
 * flags so it's safe even if later reflected into an inline <script> block
 * anywhere (defense in depth — output should still be escaped at render time).
 */
function writeAuditLog(?int $userId, string $action, ?string $entityType, ?int $entityId, array $details = []): void
{
    $stmt = db()->prepare('
        INSERT INTO ' . T_AUDIT_LOG . ' (user_id, action, entity_type, entity_id, details, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');

    $stmt->execute([
        $userId,
        $action,
        $entityType,
        $entityId,
        json_encode($details, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);
}

// Human label for entity_type -- see this file's own writeAuditLog() callers
// for the full set of values actually written ('task' | 'location' |
// 'task_location' | 'user' | null). Whatever isn't in this map (nothing
// else exists today) falls back to itself title-cased, so a future new
// entity_type still renders as *something* readable instead of blank.
// Used by pages/audit_logs.php.
const AUDIT_MODULE_LABELS = [
    'task' => 'Tasks',
    'location' => 'Locations',
    'task_location' => 'Assignments',
    'user' => 'Users',
];
function auditModuleLabel(?string $entityType): string
{
    if ($entityType === null) {
        return 'System';
    }
    return AUDIT_MODULE_LABELS[$entityType] ?? ucfirst(str_replace('_', ' ', $entityType));
}

// Simple, non-technical human labels for audit log actions.
const AUDIT_ACTION_LABELS = [
    'logout'                     => 'Log Out',
    'logout.idle_timeout'        => 'Log Out (Timeout)',
    'login.success'              => 'Log In',
    'login.failed'               => 'Failed Login',
    'task.create'                => 'Create Task',
    'task.delete'                => 'Delete Task',
    'task_location.assign'       => 'Assign Location',
    'task_location.unassign'     => 'Unassign Location',
    'task_location.expire'       => 'Task Expired',
    'task_location.expire_sweep' => 'Task Expired',
    'scan.start'                 => 'Start Scan',
    'scan.complete'              => 'Complete Check',
    'location.create'            => 'Create Location',
    'location.update'            => 'Edit Location',
    'location.delete'            => 'Delete Location',
    'location.import_csv'        => 'Import Locations',
    'location.reset_status'      => 'Reset Location',
    'user.create'                => 'Add User',
    'user.update_role'           => 'Change Role',
    'user.update_status'         => 'Change Status',
    'authz.denied'               => 'Access Denied',
];

function auditActionLabel(string $action): string
{
    if (isset(AUDIT_ACTION_LABELS[$action])) {
        return AUDIT_ACTION_LABELS[$action];
    }
    // Fallback: replace separators with spaces and title-case
    return ucwords(str_replace(['.', '_'], ' ', $action));
}

// Loose tone classifier from the action string itself (e.g. 'task.delete',
// 'login.failed', 'authz.denied') rather than an exhaustive per-action
// map -- a new action string added later (see this file's callers) still
// gets a reasonable color instead of needing this list kept in sync.
function auditActionBadgeClass(string $action): string
{
    foreach (['denied', 'failed', 'delete', 'idle_timeout', 'unassign', 'expire'] as $needle) {
        if (str_contains($action, $needle)) {
            return 'status-badge status-missed';
        }
    }
    foreach (['create', 'success', 'assign', 'complete'] as $needle) {
        if (str_contains($action, $needle)) {
            return 'status-badge status-complete';
        }
    }
    return 'method-badge';
}
