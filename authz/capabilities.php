<?php
/**
 * Role -> capability matrix. Keyed by role NAME (from qrs_roles.name /
 * the session's role_name) rather than numeric id, so this file doesn't
 * depend on IDENTITY insertion order.
 *
 * Locations have no department_id in the schema (shared cross-department
 * master list, matching the mockup's manage_locations.php) — capabilities
 * that touch locations are therefore not department-scoped by authorize();
 * capabilities that touch tasks/task_locations ARE, via the task's
 * department_id.
 */

// Collapsed to the 2 roles actually used per the client's real access
// matrix: Admin (Task Creator + Task Completer + Report Generation) and
// User (Task Completer + Report Generation only).
const ROLE_ADMIN = 'Admin';
const ROLE_USER = 'User';

const QRS_CAPABILITIES = [
    'task.create' => [ROLE_ADMIN],
    'task.delete' => [ROLE_ADMIN],       // soft delete
    'location.create' => [ROLE_ADMIN],
    'location.delete' => [ROLE_ADMIN],
    'location.import_csv' => [ROLE_ADMIN],
    'task_location.assign' => [ROLE_ADMIN],
    'task_location.unassign' => [ROLE_ADMIN],
    'scan.start' => [ROLE_ADMIN, ROLE_USER],
    'scan.complete' => [ROLE_ADMIN, ROLE_USER],
    'report.view' => [ROLE_ADMIN, ROLE_USER],
];

function roleHasCapability(string $roleName, string $capability): bool
{
    return in_array($roleName, QRS_CAPABILITIES[$capability] ?? [], true);
}
