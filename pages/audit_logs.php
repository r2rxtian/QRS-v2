<?php
require_once __DIR__ . '/../auth/session.php';
$currentUser = requireLogin();

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../rules/constants.php';
require_once __DIR__ . '/../authz/capabilities.php';
require_once __DIR__ . '/../authz/audit.php';
require_once __DIR__ . '/../auth/csrf.php';

// Admin-only, same pattern as user_management.php -- audit events include
// other people's IP addresses and action details, not something a User
// role should see (that role has no equivalent page at all, unlike Task
// Manager/qradmin.php's admin/user split).
if ($currentUser['role_name'] !== ROLE_ADMIN) {
    header('Location: dashboard.php');
    exit;
}

$pdo = db();

// Capped at the 500 most recent -- this table only grows, and (like every
// other list in this app) pagination here is client-side, so an unbounded
// SELECT * would mean shipping the entire history to the browser on every
// visit. 500 rows is generous for "recent activity" without that cost;
// there's no date-range picker (yet) to reach further back than that.
$stmt = $pdo->query('
    SELECT TOP 500
        al.id, al.created_at, al.action, al.entity_type, al.user_id,
        CASE WHEN al.user_id IS NULL THEN NULL ELSE ' . fullNameSql('ml', 'u') . ' END AS actor_name
    FROM ' . T_AUDIT_LOG . ' al
    LEFT JOIN ' . T_USERS . ' u ON u.id = al.user_id
    LEFT JOIN ' . T_MASTER_LIST . ' ml ON ml.EmployeeID = u.employee_id
    WHERE al.action <> \'task_location.expire_sweep\'
    ORDER BY al.created_at DESC, al.id DESC
');
$auditRows = $stmt->fetchAll();

$totalEvents = (int) $pdo->query('SELECT COUNT(*) FROM ' . T_AUDIT_LOG . ' WHERE action <> \'task_location.expire_sweep\'')->fetchColumn();

$failedLoginsToday = (int) $pdo->query("
    SELECT COUNT(*) FROM " . T_AUDIT_LOG . "
    WHERE action = 'login.failed' AND created_at >= CAST(SYSDATETIME() AS DATE)
")->fetchColumn();

$activeUsersToday = (int) $pdo->query('
    SELECT COUNT(DISTINCT user_id) FROM ' . T_AUDIT_LOG . '
    WHERE user_id IS NOT NULL AND created_at >= CAST(SYSDATETIME() AS DATE)
')->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Logs — QR Task Check</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/app.css?v=28">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    <script src="../scripts/theme.js?v=6"></script>
</head>

<body>
    <?php include '../components/appshell_start.php'; ?>

    <div class="topbar">
        <div class="topbar-title">
            <h1>Audit Logs</h1>
            <p>System-wide activity: who did what, when, and from where.</p>
        </div>
    </div>

    <div class="stat-tiles" data-realtime-region="audit-stats">
        <div class="stat-tile">
            <div class="stat-tile-top">
                <div class="stat-tile-icon periwinkle"><i class="fas fa-list-check"></i></div>
            </div>
            <div class="stat-tile-value"><?= $totalEvents ?></div>
            <div class="stat-tile-label">Total Events</div>
        </div>
        <div class="stat-tile">
            <div class="stat-tile-top">
                <div class="stat-tile-icon teal"><i class="fas fa-user-check"></i></div>
            </div>
            <div class="stat-tile-value"><?= $activeUsersToday ?></div>
            <div class="stat-tile-label">Active Users Today</div>
        </div>
        <div class="stat-tile">
            <div class="stat-tile-top">
                <div class="stat-tile-icon purple"><i class="fas fa-triangle-exclamation"></i></div>
            </div>
            <div class="stat-tile-value"><?= $failedLoginsToday ?></div>
            <div class="stat-tile-label">Failed Logins Today</div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div style="display:flex; align-items:center; gap:14px;">
                <div class="modal-header-icon"><i class="fas fa-clock-rotate-left"></i></div>
                <div>
                    <h2>All Events</h2>
                    <p style="margin: 2px 0 0; font-size: 13px; color: var(--gray-500);">Most recent <?= count($auditRows) ?> of <?= $totalEvents ?> logged events</p>
                </div>
            </div>
            <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                <input type="text" class="form-input table-search-input" data-target="auditLogTable" placeholder="Search activity…" style="width: 220px;">
                <div class="filter-wrap" data-table="auditLogTable">
                    <button type="button" class="btn btn-secondary filter-btn" onclick="toggleFilterPanel(this)">
                        <i class="fas fa-sliders"></i> Filters <span class="filter-badge"></span>
                    </button>
                    <div class="filter-panel">
                        <div class="filter-group">
                            <div class="filter-group-title">Sort By</div>
                            <div class="sort-dropdown" data-value="">
                                <button type="button" class="sort-dropdown-trigger" onclick="toggleSortDropdown(this)">
                                    <span>Default order</span>
                                    <i class="fas fa-chevron-down"></i>
                                </button>
                                <div class="sort-dropdown-menu">
                                    <div class="sort-dropdown-option selected" data-value="">Default order</div>
                                    <div class="sort-dropdown-group-title">Timestamp</div>
                                    <div class="sort-dropdown-option" data-value="0:text:desc" data-label="Timestamp (Newest first)">Newest first</div>
                                    <div class="sort-dropdown-option" data-value="0:text:asc" data-label="Timestamp (Oldest first)">Oldest first</div>
                                    <div class="sort-dropdown-group-title">User</div>
                                    <div class="sort-dropdown-option" data-value="1:text:asc" data-label="User (A → Z)">A → Z</div>
                                    <div class="sort-dropdown-option" data-value="1:text:desc" data-label="User (Z → A)">Z → A</div>
                                    <div class="sort-dropdown-group-title">Action</div>
                                    <div class="sort-dropdown-option" data-value="2:text:asc" data-label="Action (A → Z)">A → Z</div>
                                    <div class="sort-dropdown-option" data-value="2:text:desc" data-label="Action (Z → A)">Z → A</div>
                                </div>
                            </div>
                        </div>
                        <div class="filter-group">
                            <div class="filter-group-title">Module</div>
                            <?php foreach (['Tasks', 'Locations', 'Assignments', 'Users', 'System'] as $moduleLabel): ?>
                                <label class="filter-option"><input type="checkbox" data-filter="module" value="<?= htmlspecialchars($moduleLabel) ?>"> <?= htmlspecialchars($moduleLabel) ?></label>
                            <?php endforeach; ?>
                        </div>
                        <div class="filter-panel-actions">
                            <button type="button" class="btn btn-sm btn-secondary" onclick="clearFilterPanel(this)">Clear</button>
                            <button type="button" class="btn btn-sm btn-primary" onclick="applyFilterPanel(this)">Apply</button>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label for="auditLogEntries" class="form-label">Show</label>
                    <select id="auditLogEntries" class="form-input" onchange="onAuditLogEntriesChange(this.value)">
                        <option value="10" selected>10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="all">All</option>
                    </select>
                    <span class="form-label">entries</span>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-wrapper">
                <table id="auditLogTable">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Module</th>
                        </tr>
                    </thead>
                    <tbody data-realtime-region="audit-table-body">
                        <?php if (empty($auditRows)): ?>
                            <tr>
                                <td colspan="4" style="text-align:center; padding: 40px; color: var(--gray-500);">No activity logged yet.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($auditRows as $row): ?>
                            <?php
                            $moduleLabel = auditModuleLabel($row['entity_type']);
                            $actionLabel = auditActionLabel($row['action']);
                            $actorName = $row['actor_name'] ?? 'System';
                            $timestamp = new DateTime($row['created_at']);
                            ?>
                            <tr data-module="<?= htmlspecialchars($moduleLabel) ?>">
                                <td data-sort-value="<?= htmlspecialchars($row['created_at']) ?>"><?= htmlspecialchars($timestamp->format('M j, Y g:i:s A')) ?></td>
                                <td><?= htmlspecialchars($actorName) ?></td>
                                <td><span class="<?= auditActionBadgeClass($row['action']) ?>"><?= htmlspecialchars($actionLabel) ?></span></td>
                                <td><?= htmlspecialchars($moduleLabel) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="pagination-container" id="auditLogPagination"></div>
        </div>
    </div>

    <?php include '../components/appshell_end.php'; ?>

    <script src="../scripts/sidebar-drawer.js"></script>
    <script src="../scripts/pagination.js?v=7"></script>
    <script src="../scripts/sort-table.js"></script>
    <script src="../scripts/filters.js?v=3"></script>
    <script src="../scripts/audit_logs.js"></script>
</body>

</html>
