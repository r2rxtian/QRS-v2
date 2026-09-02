<?php
require_once __DIR__ . '/../auth/session.php';
$currentUser = requireLogin();

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../rules/constants.php';
require_once __DIR__ . '/../authz/capabilities.php';

$pdo = db();
$isAdmin = $currentUser['role_name'] === ROLE_ADMIN;

$sql = '
    SELECT tl.id, t.name AS task_name, l.name AS location_name, tl.task_date,
           COALESCE(scan_start.actual_start_time, tl.start_time) AS start_time,
           tl.end_time, tl.status,
           tl.spot_spray_answer, tl.spot_spray_remark,
           tl.misting_answer, tl.misting_remark,
           tl.mist_blower_answer, tl.mist_blower_remark,
           tl.monitoring_answer, tl.monitoring_remark,
           tl.findings_observation, tl.completion_remark,
           ' . fullNameSql('cuml', 'cu') . ' AS completed_by_name, cu.employee_id AS completed_by_code
    FROM ' . T_TASK_LOCATIONS . ' tl
    JOIN ' . T_TASKS . ' t ON t.id = tl.task_id
    JOIN ' . T_LOCATIONS . ' l ON l.id = tl.location_id
    LEFT JOIN ' . T_USERS . ' cu ON cu.id = tl.completed_by
    LEFT JOIN ' . T_MASTER_LIST . ' cuml ON cuml.EmployeeID = cu.employee_id
    -- The scan.start audit event is the authoritative user action that began
    -- the check. Prefer it over any legacy/migrated start_time value that may
    -- have inherited the assignment creation timestamp. The column remains a
    -- fallback for older records created before scan-start auditing existed.
    OUTER APPLY (
        SELECT TOP 1 al.created_at AS actual_start_time
        FROM ' . T_AUDIT_LOG . ' al
        WHERE al.action = \'scan.start\'
          AND al.entity_type = \'task_location\'
          AND al.entity_id = tl.id
        ORDER BY al.created_at ASC
    ) scan_start
    WHERE t.deleted_at IS NULL AND (
        tl.status = \'completed\'
        OR (tl.status <> \'completed\' AND DATEDIFF(SECOND, tl.assigned_at, COALESCE(tl.unassigned_at, SYSDATETIME())) >= 86400)
    )
    -- Grouped by task (so a Missed Out row sits next to its Completed rows
    -- instead of scattering across the list), but the task groups themselves
    -- are still ordered by recency -- most recently active task first -- via
    -- a window MAX() over each task group\'s own resolution times, rather
    -- than by t.id/insertion order which would not reflect real activity.
    -- Within a task, rows are then ordered by recency too.
    -- end_time is the real completion moment; unassigned_at (when the sweep
    -- closed out a missed ticket) covers the Missed Out case, which has no
    -- end_time.
    ORDER BY MAX(COALESCE(tl.end_time, tl.unassigned_at, tl.assigned_at)) OVER (PARTITION BY t.id) DESC,
             t.id,
             COALESCE(tl.end_time, tl.unassigned_at, tl.assigned_at) DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute();
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
    <link rel="stylesheet" href="../styles/app.css?v=13">
    <link rel="stylesheet" href="../styles/task_report.css?v=6">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    <script src="../scripts/theme.js?v=6"></script>
</head>

<body>
    <?php include '../components/appshell_start.php'; ?>

    <div class="topbar">
        <div class="topbar-title">
            <h1>Task Report</h1>
            <p>Review completed and missed checks across every task.</p>
        </div>
        <div class="topbar-actions">
            <button type="button" class="btn btn-primary" id="exportPdfBtn" onclick="exportReportToPDF()"><i class="fas fa-file-pdf"></i> Export to PDF</button>
        </div>
    </div>

    <!-- Stat Tiles -->
    <div class="stat-tiles">
        <div class="stat-tile">
            <div class="stat-tile-top">
                <div class="stat-tile-icon periwinkle"><i class="fas fa-file-lines"></i></div>
            </div>
            <div class="stat-tile-value"><?= $statTotal ?></div>
            <div class="stat-tile-label">Total Records</div>
            <div class="stat-tile-meta">Completed &amp; missed checks</div>
        </div>
        <div class="stat-tile">
            <div class="stat-tile-top">
                <div class="stat-tile-icon sky"><i class="fas fa-diagram-project"></i></div>
            </div>
            <div class="stat-tile-value"><?= $statTasksCovered ?></div>
            <div class="stat-tile-label">Tasks Covered</div>
            <div class="stat-tile-meta">Distinct tasks in this report</div>
        </div>
        <div class="stat-tile">
            <div class="stat-tile-top">
                <div class="stat-tile-icon purple"><i class="fas fa-paperclip"></i></div>
            </div>
            <div class="stat-tile-value"><?= $withAttachments ?></div>
            <div class="stat-tile-label">With Attachments</div>
            <div class="stat-tile-meta">Records that include photos</div>
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
                            <div class="filter-group-title">Status</div>
                            <label class="filter-option"><input type="checkbox" data-filter="status" value="Completed" checked> Completed</label>
                            <label class="filter-option"><input type="checkbox" data-filter="status" value="Missed Out" checked> Missed Out</label>
                        </div>
                        <div class="filter-group">
                            <div class="filter-group-title">Attachments</div>
                            <label class="filter-option"><input type="checkbox" data-filter="attachments" value="yes" checked> With Photos</label>
                            <label class="filter-option"><input type="checkbox" data-filter="attachments" value="no" checked> No Photos</label>
                        </div>
                        <div class="filter-group">
                            <div class="filter-group-title">Task</div>
                            <div class="filter-task-list">
                                <?php foreach ($taskNames as $taskName): ?>
                                    <label class="filter-option"><input type="checkbox" data-filter="task" value="<?= htmlspecialchars($taskName) ?>" checked> <?= htmlspecialchars($taskName) ?></label>
                                <?php endforeach; ?>
                            </div>
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
                            // Every answered item (Yes/No/N/A), not just the ones with a
                            // remark -- a plain "Yes" is still relevant information for
                            // the record, not just the reasons behind a No/N/A.
                            $checklistLines = [];
                            foreach (CHECKLIST_ITEMS as $key => $label) {
                                if (empty($r[$key . '_answer'])) {
                                    continue;
                                }
                                $line = $label . ': ' . $r[$key . '_answer'];
                                if (!empty($r[$key . '_remark'])) {
                                    $line .= ' - ' . $r[$key . '_remark'];
                                }
                                $checklistLines[] = $line;
                            }
                            $hasAnyRemark = $checklistLines || $r['findings_observation'] || $r['completion_remark'];
                            // The query above only ever returns a completed row or a missed
                            // one (its current ticket open 24+ hours from assigned_at, still
                            // not completed) -- so any non-completed status reaching this
                            // point is, by construction, a missed one, regardless of whether
                            // it was left pending or in_progress when its 24 hours ran out.
                            $statusMeta = $r['status'] === 'completed'
                                ? ['label' => 'Completed', 'class' => 'status-complete']
                                : ['label' => 'Missed Out', 'class' => 'status-missed'];
                            $biometricsLast3 = $r['completed_by_code'] ? substr($r['completed_by_code'], -3) : '—';
                            $taskDateObj = new DateTime($r['task_date']);
                            ?>
                            <tr data-task="<?= htmlspecialchars($r['task_name']) ?>" data-attachments="<?= $hasAttachments ?>" data-status="<?= htmlspecialchars($statusMeta['label']) ?>">
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
                                <td<?= $r['start_time'] ? ' data-sort-value="' . htmlspecialchars((new DateTime($r['start_time']))->format('Y-m-d H:i:s')) . '"' : '' ?>>
                                    <?php if ($r['start_time']): $startTimeObj = new DateTime($r['start_time']); ?>
                                        <span class="datetime-stack"><span><?= htmlspecialchars($startTimeObj->format('Y-m-d')) ?></span> <span class="datetime-stack-time"><?= htmlspecialchars($startTimeObj->format('H:i:s')) ?></span></span>
                                    <?php else: ?>
                                        Not Started
                                    <?php endif; ?>
                                </td>
                                <td<?= $r['end_time'] ? ' data-sort-value="' . htmlspecialchars((new DateTime($r['end_time']))->format('Y-m-d H:i:s')) . '"' : '' ?>>
                                    <?php if ($r['end_time']): $endTimeObj = new DateTime($r['end_time']); ?>
                                        <span class="datetime-stack"><span><?= htmlspecialchars($endTimeObj->format('Y-m-d')) ?></span> <span class="datetime-stack-time"><?= htmlspecialchars($endTimeObj->format('H:i:s')) ?></span></span>
                                    <?php else: ?>
                                        Not Completed
                                    <?php endif; ?>
                                </td>
                                <td class="remarks-cell">
                                    <?php if (!$hasAnyRemark): ?>
                                        <span class="cell-icon-text"><i class="fas fa-comment"></i> No remarks</span>
                                    <?php else: ?>
                                        <button type="button" class="remarks-view-btn" onclick="openRemarksModal(this, <?= htmlspecialchars(json_encode($r['task_name'] . ' — ' . $r['location_name']), ENT_QUOTES) ?>)">
                                            <i class="fas fa-comment-dots"></i> View
                                        </button>
                                    <?php endif; ?>
                                    <!-- Full remark blocks stay in the DOM (just hidden) rather than
                                         being removed -- exportReportToPDF() still reads them straight
                                         out of each row via .remark-block, so the PDF keeps showing
                                         everything inline exactly as before; only the on-screen table
                                         gets the compact "View Remarks" button instead of a crowded cell. -->
                                    <div class="remarks-source" hidden>
                                        <?php if ($checklistLines): ?>
                                            <div class="remark-block">
                                                <div class="remark-block-label"><i class="fas fa-list-check"></i> Checklist</div>
                                                <?php foreach ($checklistLines as $line): ?>
                                                    <div class="remark-block-text"><?= htmlspecialchars($line) ?></div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($r['findings_observation']): ?>
                                            <div class="remark-block">
                                                <div class="remark-block-label"><i class="fas fa-magnifying-glass"></i> Findings / Observation</div>
                                                <div class="remark-block-text"><?= htmlspecialchars($r['findings_observation']) ?></div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($r['completion_remark']): ?>
                                            <div class="remark-block">
                                                <div class="remark-block-label"><i class="fas fa-flag-checkered"></i> Completion Remarks</div>
                                                <div class="remark-block-text"><?= htmlspecialchars($r['completion_remark']) ?></div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
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

    <!-- Remarks Modal -->
    <div id="remarksModal" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title"><i class="fas fa-comment-dots"></i> Remarks</h3>
                    <p class="modal-subtitle" id="remarksModalSubtitle"></p>
                </div>
                <button type="button" class="modal-close" onclick="closeModal('remarksModal')">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div class="modal-body" id="remarksModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('remarksModal')">Close</button>
            </div>
        </div>
    </div>

    <?php include '../components/appshell_end.php'; ?>

    <script src="../scripts/sidebar-drawer.js"></script>
    <script src="../scripts/pagination.js"></script>
    <script src="../scripts/sort-table.js"></script>
    <script src="../scripts/filters.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
    <script src="../scripts/task_report.js?v=8"></script>
</body>

</html>
