<?php
/**
 * Shared constants: table names (dbo.qrs_* — no FK constraints, see
 * sql/schema.sql) and domain vocabulary used across api/ and pages/.
 */

// Table names
define('T_ROLES', 'dbo.qrs_roles');
define('T_DEPARTMENTS', 'dbo.qrs_departments');
define('T_USERS', 'dbo.qrs_users');
define('T_LOCATIONS', 'dbo.qrs_locations');
define('T_TASKS', 'dbo.qrs_tasks');
define('T_TASK_LOCATIONS', 'dbo.qrs_task_locations');
define('T_TASK_LOCATION_PHOTOS', 'dbo.qrs_task_location_photos');
define('T_AUDIT_LOG', 'dbo.qrs_audit_log');
define('T_RATE_LIMIT_EVENTS', 'dbo.qrs_rate_limit_events');

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
define('UPLOAD_ALLOWED_MIME_TYPES', ['image/jpeg', 'image/png', 'image/webp']);
define('UPLOAD_MAX_BYTES', 8 * 1024 * 1024); // 8MB per file
define('UPLOAD_DIR', __DIR__ . '/../assets/uploads/photos/');
define('UPLOAD_URL_PATH', '../assets/uploads/photos/'); // relative to pages/*.php

// Login rate limiting
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_MINUTES', 15);
