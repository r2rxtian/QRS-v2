<?php
/**
 * Role -> capability matrix. Keyed by role NAME (from qrs_roles.name /
 * the session's role_name) rather than numeric id, so this file doesn't
 * depend on IDENTITY insertion order.
 *
 * No department scoping -- this app is used by a single QA team, so a
 * capability granted to a role applies to every row of that entity type
 * (see authz/authz.php).
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
    'location.update' => [ROLE_ADMIN],
    'location.delete' => [ROLE_ADMIN],
    'location.import_csv' => [ROLE_ADMIN],
    'task_location.assign' => [ROLE_ADMIN],
    'task_location.unassign' => [ROLE_ADMIN],
    'scan.start' => [ROLE_ADMIN, ROLE_USER],
    'scan.complete' => [ROLE_ADMIN, ROLE_USER],
    'report.view' => [ROLE_ADMIN, ROLE_USER],
    'user.manage' => [ROLE_ADMIN],        // create/update_role/delete share one capability
];

function roleHasCapability(string $roleName, string $capability): bool
{
    return in_array($roleName, QRS_CAPABILITIES[$capability] ?? [], true);
}
