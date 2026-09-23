<?php
/**
 * Shared constants: table names (dbo.qrs_* — no FK constraints, see
 * sql/schema.sql) and domain vocabulary used across api/ and pages/.
 */

// Table names
define('T_ROLES', 'dbo.qrs_roles');
define('T_USERS', 'dbo.qrs_users');
define('T_LOCATIONS', 'dbo.qrs_locations');
define('T_TASKS', 'dbo.qrs_tasks');
define('T_TASK_LOCATIONS', 'dbo.qrs_task_locations');
define('T_TASK_LOCATION_PHOTOS', 'dbo.qrs_task_location_photos');
define('T_AUDIT_LOG', 'dbo.qrs_audit_log');
define('T_RATE_LIMIT_EVENTS', 'dbo.qrs_rate_limit_events');
define('T_MAINTENANCE_STATE', 'dbo.qrs_maintenance_state');

// Shared expiration policy used by countdown reads and both server-side
// transition paths.
define('TASK_LOCATION_EXPIRATION_SECONDS', 24 * 60 * 60);
define('RESOLVED_LOCATION_SWEEP_INTERVAL_SECONDS', 30);

// The company-wide HR master list this app does NOT own (lives in the same
// LRNPH_OJT database, maintained by another system). qrs_users.employee_id
// matches this table's EmployeeID column (e.g. "2024-40484") -- its
// BiometricsID column (e.g. "40484", shorter, non-unique on its own) is a
// separate field, used only as the bridge from lrnph_users (see below), not
// for matching qrs_users directly. This is the single source of truth for
// a user's real name -- qrs_users itself no longer stores full_name (see
// sql/migrations/0003_drop_full_name.sql).
define('T_MASTER_LIST', 'dbo.lrn_master_list');

// The company-wide login/credentials table this app does NOT own (same
// database, shared across multiple internal apps) -- the single source of
// truth for a user's password going forward. Its username/empcode columns
// hold the person's biometrics number, matching T_MASTER_LIST.BiometricsID
// -- login_handler.php bridges lrnph_users -> T_MASTER_LIST (by
// BiometricsID) -> qrs_users (by EmployeeID/employee_id) to resolve a
// login attempt to this app's own user row. qrs_users.password_hash is no
// longer read anywhere; QRS-specific state (role_id, avatar, lockout
// counters) still lives only in qrs_users.
define('T_LRNPH_USERS', 'dbo.lrnph_users');

// "Lastname, Firstname Middlename" (this app's existing display
// convention) built straight from a T_MASTER_LIST row aliased $alias --
// used directly when master list IS the driving table (e.g.
// api/users/lookup.php), and as the building block for fullNameSql()
// below when it's LEFT JOINed from a qrs_users row instead.
function masterListNameSql(string $alias = 'ml'): string
{
    return "CONCAT($alias.LastName, ', ', $alias.FirstName, "
        . "CASE WHEN $alias.MiddleName IS NOT NULL AND $alias.MiddleName <> '' THEN ' ' + $alias.MiddleName ELSE '' END)";
}

// Same name, but safe for a LEFT JOIN from T_MASTER_LIST aliased $alias --
// falls back to the user's own employee_id if no master-list row matches
// (e.g. a brand-new hire not yet in the list). $userAlias is whatever
// alias the qrs_users row is joined as (e.g. 'u', 'cu').
function fullNameSql(string $alias = 'ml', string $userAlias = 'u'): string
{
    return "CASE WHEN $alias.LastName IS NOT NULL THEN " . masterListNameSql($alias) . " ELSE $userAlias.employee_id END";
}

// Internal HR photos are hosted at the web-server root and filed by
// EmployeeID (e.g. "2024-40484.jpg"). Keep this URL root-relative so it
// automatically follows the current page's scheme and host. A hard-coded
// http:// URL is blocked as mixed content when mobile users open Scan QR over
// HTTPS, which incorrectly triggers the initials fallback despite the photo
// existing. Not every employee has a photo, so callers still retain their
// onerror initials fallback for genuinely missing files.
define('EMP_PHOTO_BASE_URL', '/lrnph/emp_photos/');

function employeePhotoUrl(?string $employeeId): ?string
{
    return $employeeId ? EMP_PHOTO_BASE_URL . rawurlencode($employeeId) . '.jpg' : null;
}

// The existing initials-avatar fallback (placehold.co), reused as the
// onerror target when a real employee photo (above) fails to load or
// doesn't exist for that person.
function initialsAvatarUrl(?string $initials, ?string $colorHex, string $sizePx = '38'): string
{
    $initials = $initials ?: '?';
    $colorHex = ltrim($colorHex ?: '#A7ACD9', '#');
    return "https://placehold.co/{$sizePx}x{$sizePx}/EEF0FB/" . rawurlencode($colorHex) . '?text=' . rawurlencode($initials);
}

// Observation/Recommendation checklist shown on the scan flow's start
// screen: 4 fixed Yes/No/N/A questions, each with a remark required
// (app-level) when answered No or N/A.
define('CHECKLIST_ITEMS', [
    'spot_spray' => 'Spot Spray',
    'misting' => 'Misting',
    'mist_blower' => 'Mist Blower',
    'monitoring' => 'Monitoring',
]);
define('CHECKLIST_ANSWERS', ['Yes', 'No', 'N/A']);

// Icon + short description per checklist item, for the scan flow's card UI
// (pages/scan.php only — validation/reports use CHECKLIST_ITEMS above).
define('CHECKLIST_ITEM_META', [
    'spot_spray' => ['icon' => 'fa-spray-can', 'description' => 'Check if area was spot sprayed.'],
    'misting' => ['icon' => 'fa-cloud-rain', 'description' => 'Check if misting was done.'],
    'mist_blower' => ['icon' => 'fa-fan', 'description' => 'Check if mist blower was used.'],
    'monitoring' => ['icon' => 'fa-eye', 'description' => 'Check if monitoring was conducted.'],
]);

// Task/location type -- Monitoring stations vs Treatment areas are two
// separate location catalogs; a task is fixed to one type at creation and
// can only be assigned locations of that same type.
define('TASK_TYPES', ['Monitoring', 'Treatment']);

// Icon + short description per type, for the Create Task modal's type
// picker cards (pages/tasks.php only).
define('TASK_TYPE_META', [
    'Monitoring' => ['icon' => 'fa-eye', 'description' => 'Check and record the status of locations.'],
    'Treatment' => ['icon' => 'fa-spray-can', 'description' => 'Perform treatment at the selected locations.'],
]);

// Max photos accepted in a single scan-completion submission.
define('UPLOAD_MAX_PHOTOS_PER_SUBMISSION', 3);

// Photo upload rules
define('UPLOAD_ALLOWED_MIME_TYPES', ['image/jpeg', 'image/png']);
define('UPLOAD_MAX_BYTES', 8 * 1024 * 1024); // 8MB per file
define('UPLOAD_DIR', __DIR__ . '/../assets/uploads/photos/');
define('UPLOAD_URL_PATH', '../assets/uploads/photos/'); // relative to pages/*.php

// Login rate limiting
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_MINUTES', 15);

// Idle-timeout auto-logout, and how often an active session's underlying
// id gets rotated. The server enforces both in auth/session.php's
// currentUser(); scripts/session-guard.js receives these values through the
// authenticated page shell so the browser does not maintain a second copy
// of either policy.
define('SESSION_IDLE_TIMEOUT_MINUTES', 15);
define('SESSION_TOKEN_REFRESH_MINUTES', 12);
