<?php
require_once __DIR__ . '/../auth/session.php';
$currentUser = requireLogin();

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../rules/constants.php';
require_once __DIR__ . '/../authz/capabilities.php';

$pdo = db();
$isAdmin = $currentUser['role_name'] === ROLE_ADMIN;

$sql = '
    SELECT tl.id, t.name AS task_name, l.name AS location_name, tl.task_date, tl.start_time, tl.end_time, tl.status,
           tl.spot_spray_answer, tl.spot_spray_remark,
           tl.misting_answer, tl.misting_remark,
           tl.mist_blower_answer, tl.mist_blower_remark,
           tl.monitoring_answer, tl.monitoring_remark,
           tl.findings_observation, tl.completion_remark,
           cu.full_name AS completed_by_name, cu.employee_id AS completed_by_code
    FROM ' . T_TASK_LOCATIONS . ' tl
    JOIN ' . T_TASKS . ' t ON t.id = tl.task_id
    JOIN ' . T_LOCATIONS . ' l ON l.id = tl.location_id
    LEFT JOIN ' . T_USERS . ' cu ON cu.id = tl.completed_by
    WHERE t.deleted_at IS NULL AND tl.status = \'completed\'';
$params = [];
if (!$isAdmin) {
    $sql .= ' AND t.department_id = ?';
    $params[] = $currentUser['department_id'];
}
$sql .= ' ORDER BY tl.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

$photosByTaskLocation = [];
if (!empty($records)) {
    $tlIds = array_column($records, 'id');
    $placeholders = implode(',', array_fill(0, count($tlIds), '?'));
    $photoStmt = $pdo->prepare('
        SELECT task_location_id, photo_type, stored_filename
        FROM ' . T_TASK_LOCATION_PHOTOS . '
        WHERE task_location_id IN (' . $placeholders . ') AND deleted_at IS NULL
    ');
    $photoStmt->execute($tlIds);
    foreach ($photoStmt->fetchAll() as $photo) {
        $photosByTaskLocation[$photo['task_location_id']][$photo['photo_type']][] = $photo['stored_filename'];
    }
}

$taskNames = [];
$withAttachments = 0;
foreach ($records as $r) {
    $taskNames[$r['task_name']] = true;
    if (!empty($photosByTaskLocation[$r['id']])) {
        $withAttachments++;
    }
}
$taskNames = array_keys($taskNames);
sort($taskNames);

$statTotal = count($records);
$statTasksCovered = count($taskNames);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task Report — QR Task Check</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/app.css">
    <link rel="stylesheet" href="../styles/task_report.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    <script src="../scripts/theme.js?v=2"></script>
</head>

<body>
    <?php include '../components/appshell_start.php'; ?>

    <div class="topbar">
        <div class="topbar-title">
            <h1>Task Report</h1>
            <p>Full attachment history across every task and location.</p>
        </div>
        <div class="topbar-actions">
            <button type="button" class="btn btn-primary" id="exportPdfBtn" onclick="exportReportToPDF()"><i class="fas fa-file-pdf"></i> Export to PDF</button>
        </div>
    </div>

    <div class="stat-tiles">
        <div class="stat-tile">
            <div class="stat-tile-top">
                <div class="stat-tile-icon periwinkle"><i class="fas fa-file-lines"></i></div>
            </div>
            <div class="stat-tile-value"><?= $statTotal ?></div>
            <div class="stat-tile-label">Total Records</div>
        </div>
        <div class="stat-tile">
            <div class="stat-tile-top">
                <div class="stat-tile-icon teal"><i class="fas fa-camera"></i></div>
            </div>
            <div class="stat-tile-value"><?= $withAttachments ?></div>
            <div class="stat-tile-label">With Attachments</div>
        </div>
        <div class="stat-tile">
            <div class="stat-tile-top">
                <div class="stat-tile-icon purple"><i class="fas fa-clipboard-list"></i></div>
            </div>
            <div class="stat-tile-value"><?= $statTasksCovered ?></div>
            <div class="stat-tile-label">Tasks Covered</div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div style="display:flex; align-items:center; gap:14px;">
                <div class="modal-header-icon"><i class="fas fa-file-lines"></i></div>
                <div>
                    <h2>All Task Records</h2>
                    <p style="margin: 2px 0 0; font-size: 13px; color: var(--gray-500);">View and manage all task records.</p>
                </div>
            </div>
            <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                <input type="text" class="form-input table-search-input" data-target="reportTable" placeholder="Search records…" style="width: 220px;" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
                <div class="filter-wrap" data-table="reportTable">
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
                                    <div class="sort-dropdown-group-title">Task</div>
                                    <div class="sort-dropdown-option" data-value="0:text:asc" data-label="Task (A → Z)">A → Z</div>
                                    <div class="sort-dropdown-option" data-value="0:text:desc" data-label="Task (Z → A)">Z → A</div>
                                    <div class="sort-dropdown-group-title">Area</div>
                                    <div class="sort-dropdown-option" data-value="1:text:asc" data-label="Area (A → Z)">A → Z</div>
                                    <div class="sort-dropdown-option" data-value="1:text:desc" data-label="Area (Z → A)">Z → A</div>
                                    <div class="sort-dropdown-group-title">Scheduled Date</div>
                                    <div class="sort-dropdown-option" data-value="2:text:asc" data-label="Scheduled Date (Oldest first)">Oldest first</div>
                                    <div class="sort-dropdown-option" data-value="2:text:desc" data-label="Scheduled Date (Newest first)">Newest first</div>
                                    <div class="sort-dropdown-group-title">Start Time</div>
                                    <div class="sort-dropdown-option" data-value="5:text:asc" data-label="Start Time (Earliest first)">Earliest first</div>
                                    <div class="sort-dropdown-option" data-value="5:text:desc" data-label="Start Time (Latest first)">Latest first</div>
                                    <div class="sort-dropdown-group-title">End Time</div>
                                    <div class="sort-dropdown-option" data-value="6:text:asc" data-label="End Time (Earliest first)">Earliest first</div>
                                    <div class="sort-dropdown-option" data-value="6:text:desc" data-label="End Time (Latest first)">Latest first</div>
                                </div>
                            </div>
                        </div>
                        <div class="filter-group">
                            <div class="filter-group-title">Attachments</div>
                            <label class="filter-option"><input type="checkbox" data-filter="attachments" value="yes" checked> With Photos</label>
                            <label class="filter-option"><input type="checkbox" data-filter="attachments" value="no" checked> No Photos</label>
                        </div>
                        <div class="filter-group">
                            <div class="filter-group-title">Task</div>
                            <?php foreach ($taskNames as $taskName): ?>
                                <label class="filter-option"><input type="checkbox" data-filter="task" value="<?= htmlspecialchars($taskName) ?>" checked> <?= htmlspecialchars($taskName) ?></label>
                            <?php endforeach; ?>
                        </div>
                        <div class="filter-panel-actions">
                            <button type="button" class="btn btn-sm btn-secondary" onclick="clearFilterPanel(this)">Clear</button>
                            <button type="button" class="btn btn-sm btn-primary" onclick="applyFilterPanel(this)">Apply</button>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label for="entries" class="form-label">Show</label>
                    <select id="entries" class="form-input" onchange="onEntriesChange(this.value)">
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
                <table id="reportTable">
                    <thead>
                        <tr>
                            <th>Task</th>
                            <th>Area</th>
                            <th>Scheduled Date</th>
                            <th>Biometrics</th>
                            <th>User</th>
                            <th>Start Time</th>
                            <th>End Time</th>
                            <th>Remarks</th>
                            <th>Status</th>
                            <th>Images</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($records)): ?>
                            <tr>
                                <td colspan="10" style="text-align:center; padding: 40px; color: var(--gray-500);">No records yet.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($records as $r): ?>
                            <?php
                            $photos = $photosByTaskLocation[$r['id']] ?? [];
                            $hasAttachments = !empty($photos) ? 'yes' : 'no';
                            $checklistRemarks = [];
                            foreach (CHECKLIST_ITEMS as $key => $label) {
                                if (!empty($r[$key . '_remark'])) {
                                    $checklistRemarks[] = $label . ': ' . $r[$key . '_remark'];
                                }
                            }
                            $remarkParts = array_filter(array_merge($checklistRemarks, [$r['findings_observation'], $r['completion_remark']]));
                            $remarks = $remarkParts ? implode(' / ', $remarkParts) : 'No remarks';
                            // Same class/label convention as Task Manager's status badges
                            // (rules/status.php) so a location's status reads identically
                            // wherever it's shown, colors included.
                            $statusMeta = [
                                'pending' => ['label' => 'Not Started', 'class' => 'status-not-started'],
                                'in_progress' => ['label' => 'On-going', 'class' => 'status-ongoing'],
                                'completed' => ['label' => 'Completed', 'class' => 'status-complete'],
                            ][$r['status']] ?? ['label' => $r['status'], 'class' => ''];
                            $biometricsLast3 = $r['completed_by_code'] ? substr($r['completed_by_code'], -3) : '—';
                            $taskDateObj = new DateTime($r['task_date']);
                            ?>
                            <tr data-task="<?= htmlspecialchars($r['task_name']) ?>" data-attachments="<?= $hasAttachments ?>">
                                <td>
                                    <div class="row-icon-name">
                                        <div class="row-icon"><i class="fas fa-location-dot"></i></div>
                                        <span><?= htmlspecialchars($r['task_name']) ?></span>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($r['location_name']) ?></td>
                                <td data-sort-value="<?= htmlspecialchars($r['task_date']) ?>">
                                    <span class="schedule-date-cell"><i class="fas fa-calendar-days"></i> <?= htmlspecialchars($taskDateObj->format('M j, Y')) ?> <span class="schedule-date-weekday">(<?= htmlspecialchars($taskDateObj->format('D')) ?>)</span></span>
                                </td>
                                <td><?= htmlspecialchars($biometricsLast3) ?></td>
                                <td><?= htmlspecialchars($r['completed_by_name'] ?? '—') ?></td>
                                <td><?= $r['start_time'] ? htmlspecialchars((new DateTime($r['start_time']))->format('Y-m-d H:i:s')) : 'Not Started' ?></td>
                                <td><?= $r['end_time'] ? htmlspecialchars((new DateTime($r['end_time']))->format('Y-m-d H:i:s')) : 'Not Completed' ?></td>
                                <td><?= htmlspecialchars($remarks) ?></td>
                                <td><span class="status-badge <?= htmlspecialchars($statusMeta['class']) ?>"><?= htmlspecialchars($statusMeta['label']) ?></span></td>
                                <td>
                                    <?php if (empty($photos['after'])): ?>
                                        <span class="no-attachments"><i class="fas fa-image"></i> No photo</span>
                                    <?php else: ?>
                                        <ul class="attachment-list">
                                            <?php foreach ($photos['after'] as $filename): ?>
                                                <li><img src="<?= htmlspecialchars(UPLOAD_URL_PATH . $filename) ?>" alt="Photo" onclick="zoomPhoto(this)"></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="pagination-container" id="reportPagination"></div>
        </div>
    </div>

    <div style="text-align: center; margin-top: 24px;">
        <a href="<?= $isAdmin ? 'tasks.php' : 'qradmin.php' ?>" class="btn btn-secondary"><i class="fas fa-chart-column"></i> Back to <?= $isAdmin ? 'Task Manager' : 'All Tasks' ?></a>
    </div>

    <?php include '../components/appshell_end.php'; ?>

    <script src="../scripts/sidebar-drawer.js"></script>
    <script src="../scripts/pagination.js"></script>
    <script src="../scripts/sort-table.js"></script>
    <script src="../scripts/filters.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
    <script src="../scripts/task_report.js"></script>
</body>

</html>
