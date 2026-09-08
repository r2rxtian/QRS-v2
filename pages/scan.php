<?php
require_once __DIR__ . '/../auth/session.php';
$currentUser = requireLogin();

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../rules/constants.php';
require_once __DIR__ . '/../authz/capabilities.php';
require_once __DIR__ . '/../auth/csrf.php';

$pdo = db();
$isAdmin = $currentUser['role_name'] === ROLE_ADMIN;

$taskId = (int) ($_GET['task_id'] ?? 0);

if ($taskId <= 0) {
    // No task specified (e.g. arrived via the nav link, not from a task's
    // own "Scan" action) -- show a picker of currently-open (not fully
    // completed) tasks instead of just bouncing away. Joins each location
    // to its CURRENT ticket -- most recent row, still on the roster --
    // not a "today" one; see pages/dashboard.php for the same pattern.
    // Missed Out locations are excluded from the join entirely (same
    // reasoning as the scan API endpoints) -- once 24 hours pass they're
    // no longer something to scan, so they shouldn't count toward this
    // task's total/remaining locations here.
    $pickerSql = '
        SELECT t.id, t.name,
               COUNT(tl.id) AS total_locations,
               SUM(CASE WHEN tl.status = \'completed\' THEN 1 ELSE 0 END) AS completed_locations
        FROM ' . T_TASKS . ' t
        LEFT JOIN ' . T_TASK_LOCATIONS . ' tl ON tl.task_id = t.id AND (tl.unassigned_at IS NULL OR tl.unassigned_by IS NULL)
            AND tl.task_date <= CAST(SYSDATETIME() AS DATE)
            AND (tl.status = \'completed\' OR DATEDIFF(SECOND, CASE WHEN tl.task_date > CAST(tl.assigned_at AS DATE) THEN CAST(tl.task_date AS DATETIME2) ELSE tl.assigned_at END, SYSDATETIME()) < ' . TASK_LOCATION_EXPIRATION_SECONDS . ')
            AND tl.id = (
                SELECT MAX(tl2.id) FROM ' . T_TASK_LOCATIONS . ' tl2
                WHERE tl2.task_id = tl.task_id AND tl2.location_id = tl.location_id
            )
        WHERE t.deleted_at IS NULL
        GROUP BY t.id, t.name ORDER BY t.id DESC';
    $pickerStmt = $pdo->prepare($pickerSql);
    $pickerStmt->execute();

    $pickableTasks = [];
    foreach ($pickerStmt->fetchAll() as $row) {
        $total = (int) $row['total_locations'];
        $completed = (int) $row['completed_locations'];
        if ($total > 0 && $completed < $total) {
            $pickableTasks[] = $row;
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Scan QR — QR Task Check</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
        <link rel="stylesheet" href="../styles/app.css?v=15">
        <link rel="stylesheet" href="../styles/scan.css?v=6">
        <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
        <script src="../scripts/theme.js?v=6"></script>
    </head>

    <body>
        <?php include '../components/appshell_start.php'; ?>

        <div class="topbar">
            <div class="topbar-title">
                <h1>Scan QR</h1>
                <p>Choose which task you're checking locations for today.</p>
            </div>
            <?php if (roleHasCapability($currentUser['role_name'], 'task.create')): ?>
                <div class="topbar-actions">
                    <a href="tasks.php" class="btn btn-primary"><i class="fas fa-circle-plus"></i> Create New Task</a>
                </div>
            <?php endif; ?>
        </div>

        <div class="card scan-picker-card">
            <div class="card-header">
                <h2>Tasks With Locations Left to Check</h2>
            </div>
            <div class="card-body">
                <?php if (empty($pickableTasks)): ?>
                    <p style="color: var(--gray-500); text-align: center; padding: 20px 0;">Nothing left to check today — either every location is done, or no task has locations assigned yet.</p>
                <?php else: ?>
                    <div class="task-pick-list">
                        <?php foreach ($pickableTasks as $pt):
                            $total = (int) $pt['total_locations'];
                            $completed = (int) $pt['completed_locations'];
                            $remaining = $total - $completed;
                            $pct = $total > 0 ? round(($completed / $total) * 100) : 0;
                        ?>
                            <div class="task-pick-row">
                                <div class="task-pick-icon"><i class="fas fa-qrcode"></i></div>
                                <div class="task-pick-info">
                                    <div class="task-pick-name">
                                        <?= htmlspecialchars($pt['name']) ?>
                                        <span class="task-pick-done"><?= $completed ?>/<?= $total ?> done</span>
                                    </div>
                                    <div class="task-pick-progress">
                                        <div class="task-pick-progress-track">
                                            <div class="task-pick-progress-fill" style="width: <?= $pct ?>%;"></div>
                                        </div>
                                        <span class="task-pick-remaining"><?= $remaining ?> / <?= $total ?> locations remaining</span>
                                    </div>
                                </div>
                                <a href="scan.php?task_id=<?= (int) $pt['id'] ?>" class="btn btn-secondary task-pick-action"><i class="fas fa-qrcode"></i> Start Scanning</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php include '../components/appshell_end.php'; ?>
        <script src="../scripts/sidebar-drawer.js"></script>
    </body>

    </html>
    <?php
    exit;
}

$taskStmt = $pdo->prepare('
    SELECT t.id, t.name
    FROM ' . T_TASKS . ' t
    WHERE t.id = ? AND t.deleted_at IS NULL
');
$taskStmt->execute([$taskId]);
$task = $taskStmt->fetch();

$myTasksPage = $isAdmin ? 'tasks.php' : 'qradmin.php';

if (!$task) {
    header('Location: ' . $myTasksPage);
    exit;
}

$dbTodayStr = $pdo->query('SELECT CONVERT(varchar, CAST(SYSDATETIME() AS DATE), 23)')->fetchColumn();

// Scheduled tasks stay inactive until their actual scheduled date arrives
$scheduleCheckStmt = $pdo->prepare('
    SELECT MIN(tl.task_date) AS earliest_date
    FROM ' . T_TASK_LOCATIONS . ' tl
    WHERE tl.task_id = ? AND (tl.unassigned_at IS NULL OR tl.unassigned_by IS NULL)
');
$scheduleCheckStmt->execute([$taskId]);
$earliestDate = $scheduleCheckStmt->fetchColumn();
if ($earliestDate && $earliestDate > $dbTodayStr) {
    header('Location: ' . $myTasksPage);
    exit;
}

// Missed Out locations are excluded entirely -- see api/scan/lookup.php's
// reasoning. They simply don't appear in this task's scannable list once
// 24 hours pass, rather than showing up as a tappable row that then fails.
//
// "still relevant" (unassigned_at IS NULL OR unassigned_by IS NULL) -- not
// just unassigned_at IS NULL -- for the same reason dashboard.php/tasks.php/
// qradmin.php all use it (see rules/status.php's sweepResolvedLocations()
// docblock): the sweep auto-unassigns a location the instant its ticket
// completes, on whichever page load happens to run next. Without the OR,
// a location a worker just completed would vanish from this very sidebar
// the moment ANYONE's next page load triggered that sweep -- often within
// the same reload the completing worker's own submit triggered -- which is
// exactly the "completed locations disappear" symptom this fixes. Multiple
// workers on the same task now each see every location's real current
// status (including ones a different worker just finished), not just
// whichever locations haven't been auto-freed yet.
$rowsStmt = $pdo->prepare('
    SELECT tl.id AS task_location_id, l.id AS location_id, l.name AS location_name, tl.status, tl.task_date,
           DATEDIFF(SECOND, SYSDATETIME(), DATEADD(SECOND, ' . TASK_LOCATION_EXPIRATION_SECONDS . ', CASE WHEN tl.task_date > CAST(tl.assigned_at AS DATE) THEN CAST(tl.task_date AS DATETIME2) ELSE tl.assigned_at END)) AS remaining_seconds
    FROM ' . T_TASK_LOCATIONS . ' tl
    JOIN ' . T_LOCATIONS . ' l ON l.id = tl.location_id
    WHERE tl.task_id = ? AND (tl.unassigned_at IS NULL OR tl.unassigned_by IS NULL)
      AND (tl.status = \'completed\' OR DATEDIFF(SECOND, CASE WHEN tl.task_date > CAST(tl.assigned_at AS DATE) THEN CAST(tl.task_date AS DATETIME2) ELSE tl.assigned_at END, SYSDATETIME()) < ' . TASK_LOCATION_EXPIRATION_SECONDS . ')
      AND tl.id = (
          SELECT MAX(tl2.id) FROM ' . T_TASK_LOCATIONS . ' tl2
          WHERE tl2.task_id = tl.task_id AND tl2.location_id = tl.location_id
      )
    ORDER BY l.name
');
$rowsStmt->execute([$taskId]);
$assignedRows = $rowsStmt->fetchAll();

$statTotal = count($assignedRows);
$statInProgress = 0;
$statPending = 0;
$statCompleted = 0;
foreach ($assignedRows as $row) {
    if ($row['status'] === 'in_progress') {
        $statInProgress++;
    } elseif ($row['status'] === 'pending') {
        $statPending++;
    } else {
        $statCompleted++;
    }
}
$allCompleted = $statTotal > 0 && $statCompleted === $statTotal;

$statCompletedPct = $statTotal > 0 ? (int) round(($statCompleted / $statTotal) * 100) : 0;
$statInProgressPct = $statTotal > 0 ? (int) round(($statInProgress / $statTotal) * 100) : 0;
$statPendingPct = $statTotal > 0 ? (int) round(($statPending / $statTotal) * 100) : 0;

// For the accessible manual-entry fallback: today's not-yet-completed
// locations, tappable by name -- resolved server-side by location_id, never
// by free-typed text (see api/scan/lookup.php).
$pendingOrActiveRows = array_values(array_filter($assignedRows, fn($r) => $r['status'] !== 'completed'));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($task['name']) ?> — Scan QR</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/app.css?v=15">
    <link rel="stylesheet" href="../styles/scan.css?v=6">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    <script src="../scripts/theme.js?v=6"></script>
</head>

<body>
    <?php include '../components/appshell_start.php'; ?>

    <div class="topbar">
        <div class="topbar-title">
            <h1><?= htmlspecialchars($task['name']) ?></h1>
            <p>Scan a QR code to complete each assigned location check.</p>
        </div>
        <div class="topbar-actions">
            <a href="scan.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Scan QR</a>
        </div>
    </div>

    <div class="scan-layout">
        <!-- Main check form -->
        <div class="scan-form-col">
            <div class="card">
                <div class="card-header">
                    <div class="scan-landing-header">
                        <div class="scan-landing-header-icon"><i class="fas fa-location-dot"></i></div>
                        <div>
                            <h2>Location Check</h2>
                            <p style="margin: 2px 0 0; font-size: 13px; color: var(--gray-500);">Scan once to start, come back to finish.</p>
                        </div>
                    </div>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="showModal('howItWorksModal')"><i class="fas fa-circle-question"></i> How it works</button>
                </div>
                <div class="card-body">

                    <!-- STATE 1: Landing -->
                    <div id="landingState" style="<?= $allCompleted ? 'display:none;' : '' ?>">
                        <div class="qr-section">
                            <div class="qr-section-visual"><i class="fas fa-qrcode"></i></div>
                            <div class="qr-section-content">
                                <h4>Scan QR Code</h4>
                                <p>Scan the QR code available at the location to begin.</p>
                                <button type="button" class="btn btn-primary btn-block btn-lg" onclick="openQRScanner()">
                                    <i class="fas fa-qrcode"></i> Scan QR Code
                                </button>
                            </div>
                        </div>

                        <div class="scan-or-divider"><span>OR</span></div>

                        <div class="manual-section" data-realtime-region="scan-manual-section">
                            <div class="manual-section-visual"><i class="fas fa-list-check"></i></div>
                            <div class="manual-section-content">
                                <h4>Choose Location Manually</h4>
                                <p>Can't scan right now? Select the location from the list.</p>
                                <?php if (empty($pendingOrActiveRows)): ?>
                                    <p style="color: var(--gray-500); font-size: 13px; margin-top: 8px;">Nothing left to check today.</p>
                                <?php else: ?>
                                    <div class="select-dropdown" data-for="manual_location_select" data-placeholder="Select a location">
                                        <button type="button" class="select-dropdown-trigger" onclick="toggleSelectDropdown(this)">
                                            <i class="fas fa-list-ul"></i>
                                            <span class="select-dropdown-label placeholder">Select a location</span>
                                            <i class="fas fa-chevron-down select-dropdown-caret"></i>
                                        </button>
                                        <div class="select-dropdown-menu">
                                            <?php foreach ($pendingOrActiveRows as $row): ?>
                                                <div class="select-dropdown-option" data-value="<?= (int) $row['location_id'] ?>"><?= htmlspecialchars($row['location_name']) ?><?= $row['status'] === 'in_progress' ? ' (In Progress)' : '' ?></div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <select id="manual_location_select" class="select-dropdown-native" tabindex="-1" aria-hidden="true" onchange="if (this.value) resolveLocation({ location_id: this.value });">
                                        <option value="" disabled selected>Select a location</option>
                                        <?php foreach ($pendingOrActiveRows as $row): ?>
                                            <option value="<?= (int) $row['location_id'] ?>"><?= htmlspecialchars($row['location_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- STATE 2: Observation/Recommendation checklist -->
                    <div id="methodSelectionState" style="display:none;">
                        <button type="button" class="scan-back-link" onclick="returnToLanding()"><i class="fas fa-arrow-left"></i> Back</button>
                        <div class="step-indicator">Step 1 of 2</div>
                        <div class="scan-location-heading"><i class="fas fa-map-marker-alt" style="color: var(--primary);"></i> <span id="methodLocationName"></span></div>

                        <div id="checklistQuestions">
                            <?php foreach (CHECKLIST_ITEMS as $itemKey => $itemLabel): $meta = CHECKLIST_ITEM_META[$itemKey]; ?>
                                <div class="checklist-question" data-item="<?= htmlspecialchars($itemKey) ?>">
                                    <div class="checklist-question-header">
                                        <div class="checklist-question-icon"><i class="fas <?= htmlspecialchars($meta['icon']) ?>"></i></div>
                                        <div class="checklist-question-text">
                                            <div class="checklist-question-title"><?= htmlspecialchars($itemLabel) ?> <span class="required-asterisk">*</span></div>
                                            <div class="checklist-question-desc"><?= htmlspecialchars($meta['description']) ?></div>
                                        </div>
                                    </div>
                                    <div class="answer-btn-group">
                                        <?php foreach (CHECKLIST_ANSWERS as $answer): ?>
                                            <button type="button" class="answer-btn" data-answer="<?= htmlspecialchars($answer) ?>">
                                                <span class="answer-radio"></span><?= htmlspecialchars($answer) ?>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                    <textarea class="form-input item-remark" placeholder="Remark (required for No / N/A)" style="display:none;"></textarea>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="scan-field">
                            <label for="findings_observation" class="form-label">Findings / Observation (optional)</label>
                            <textarea id="findings_observation" class="form-input" placeholder="Any other findings or observations..."></textarea>
                        </div>

                        <input type="hidden" id="method_task_location_id">
                        <button type="button" class="btn btn-primary btn-block btn-lg" id="startCheckBtn" onclick="submitStartCheck()" disabled>
                            <i class="fas fa-play"></i> Start Check
                        </button>
                    </div>

                    <!-- STATE 3: Completion (photos, max 3, + biometrics confirm) -->
                    <div id="completionState" style="display:none;">
                        <button type="button" class="scan-back-link" onclick="returnToLanding()"><i class="fas fa-arrow-left"></i> Back</button>
                        <div class="step-indicator">Step 2 of 2</div>
                        <div class="scan-location-heading">
                            <i class="fas fa-map-marker-alt"></i>
                            <div class="scan-location-heading-text">
                                <div class="scan-location-heading-title"><span id="completionLocationName"></span></div>
                                <div class="scan-location-heading-desc">Finished the work? Attach up to 3 photos to complete this location.</div>
                            </div>
                        </div>

                        <div class="scan-field" id="completionStartedWithBox" style="display:none;">
                            <div class="recorded-start-box">
                                <div class="recorded-start-header">
                                    <div class="recorded-start-icon"><i class="fas fa-clipboard"></i></div>
                                    <div class="recorded-start-title">Recorded at Start</div>
                                </div>
                                <div id="completionStartedChecklist"></div>
                            </div>
                        </div>

                        <div class="scan-field">
                            <label class="form-label">Attach Photos (max 3) <span class="required-asterisk">*</span></label>
                            <p class="scan-field-desc">Add up to 3 photos as proof of completion.</p>
                            <div class="photo-upload-box">
                                <div class="file-input-group">
                                    <label class="file-input-label" id="galleryPhotoLabel">
                                        <i class="fas fa-image"></i>
                                        <span class="file-input-label-text">
                                            <strong>Choose Photos</strong>
                                            <small>Upload from gallery</small>
                                        </span>
                                        <input type="file" id="completion_photos" multiple accept=".jpg,.png">
                                    </label>
                                    <label class="file-input-label" id="cameraPhotoLabel" onclick="openPhotoCamera()">
                                        <i class="fas fa-camera"></i>
                                        <span class="file-input-label-text">
                                            <strong>Take Photo</strong>
                                            <small>Use camera</small>
                                        </span>
                                    </label>
                                </div>
                            </div>
                            <div class="photo-upload-footer"><span id="photoCountLabel">0 / 3 photos</span></div>
                            <div class="photo-container" id="completionPhotoContainer"></div>
                        </div>

                        <div class="scan-field">
                            <label for="completion_remark" class="form-label">Completion Remarks (optional)</label>
                            <p class="scan-field-desc">Add any final notes or observations.</p>
                            <textarea id="completion_remark" class="form-input" placeholder="Any final notes..." maxlength="500"></textarea>
                            <div class="char-counter"><span id="completionRemarkCount">0</span> / 500</div>
                        </div>

                        <div class="scan-field">
                            <label for="confirm_code" class="form-label">Confirm your Staff/Biometrics Code <span class="required-asterisk">*</span></label>
                            <p class="scan-field-desc">Retype your Staff/Biometrics Code to sign off.</p>
                            <div class="input-icon-wrap input-icon-wrap-toggle">
                                <i class="fas fa-lock"></i>
                                <input type="password" id="confirm_code" class="form-input" placeholder="Retype your Staff/Biometrics Code to sign off">
                                <button type="button" class="input-toggle-visibility" onclick="toggleConfirmCodeVisibility()" aria-label="Show code">
                                    <i class="fas fa-eye-slash" id="confirmCodeToggleIcon"></i>
                                </button>
                            </div>
                        </div>

                        <input type="hidden" id="completion_task_location_id">
                        <button type="button" class="btn btn-primary btn-block btn-lg" id="completeCheckBtn" onclick="submitCompleteCheck()" disabled>
                            <i class="fas fa-check-double"></i> Submit & Complete
                        </button>
                    </div>

                    <!-- STATE 4: All done -->
                    <div id="completedPanel" class="completed-panel" style="<?= $allCompleted ? '' : 'display:none;' ?>">
                        <h2><i class="fas fa-circle-check"></i> All Locations Completed!</h2>
                        <p>All assigned locations have been scanned and documented.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Locations status sidebar -->
        <div class="scan-side-col" data-realtime-region="scan-location-status">
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-map-signs"></i> Locations Status</h2>
                    <span class="method-badge"><?= $statTotal ?> location<?= $statTotal === 1 ? '' : 's' ?></span>
                </div>
                <div class="card-body">
                    <?php if ($statTotal > 0): ?>
                        <div class="scan-progress-overview">
                            <div class="scan-progress-overview-header">
                                <span>Overall Progress</span>
                                <span class="scan-progress-fraction"><?= $statCompleted ?> of <?= $statTotal ?> completed</span>
                            </div>
                            <div class="scan-progress-bar-row">
                                <div class="scan-progress-track"><div class="scan-progress-fill" style="width: <?= $statCompletedPct ?>%;"></div></div>
                                <span class="scan-progress-pct"><?= $statCompletedPct ?>%</span>
                            </div>

                            <div class="scan-progress-breakdown">
                                <div class="scan-progress-row">
                                    <span class="scan-progress-dot pending"></span>
                                    <span class="scan-progress-row-label">Pending</span>
                                    <span class="scan-progress-row-count"><?= $statPending ?></span>
                                    <span class="scan-progress-row-pct"><?= $statPendingPct ?>%</span>
                                </div>
                                <div class="scan-progress-row">
                                    <span class="scan-progress-dot current"></span>
                                    <span class="scan-progress-row-label">In Progress</span>
                                    <span class="scan-progress-row-count"><?= $statInProgress ?></span>
                                    <span class="scan-progress-row-pct"><?= $statInProgressPct ?>%</span>
                                </div>
                                <div class="scan-progress-row">
                                    <span class="scan-progress-dot completed"><i class="fas fa-check"></i></span>
                                    <span class="scan-progress-row-label">Completed</span>
                                    <span class="scan-progress-row-count"><?= $statCompleted ?></span>
                                    <span class="scan-progress-row-pct"><?= $statCompletedPct ?>%</span>
                                </div>
                            </div>
                        </div>

                        <!-- Full roster -- every assigned location, each individually
                             tappable to jump straight into its checklist. Scrolls
                             internally past a handful of rows instead of pushing
                             the sidebar arbitrarily tall. -->
                        <div class="scan-location-list">
                            <?php foreach ($assignedRows as $row): ?>
                                <?php
                                $rowStatusClass = $row['status'] === 'completed' ? 'completed' : ($row['status'] === 'in_progress' ? 'current' : 'pending');
                                $rowStatusLabel = $row['status'] === 'completed' ? 'Completed' : ($row['status'] === 'in_progress' ? 'In Progress' : 'Pending');
                                // Highlighted iff this location genuinely has an open,
                                // in-progress checklist right now (i.e. it's actually
                                // been scanned/clicked into) -- not just "whichever
                                // pending location happens to be listed first", which
                                // read as an arbitrary, confusing highlight before
                                // anyone had actually started anything.
                                $rowIsCurrent = $row['status'] === 'in_progress';
                                $rowIsDone = $row['status'] === 'completed';
                                ?>
                                <button type="button"
                                    class="scan-location-list-item <?= $rowStatusClass ?><?= $rowIsCurrent ? ' active' : '' ?>"
                                    data-task-location-id="<?= (int) $row['task_location_id'] ?>"
                                    <?= $rowIsDone ? 'disabled' : 'onclick="resolveLocation({ location_id: ' . (int) $row['location_id'] . ' })"' ?>>
                                    <i class="fas fa-hand-point-right scan-location-list-here-icon" aria-hidden="true"></i>
                                    <span class="scan-location-list-icon"><i class="fas fa-<?= $rowIsDone ? 'check' : ($row['status'] === 'in_progress' ? 'hourglass-half' : 'clock') ?>"></i></span>
                                    <span class="scan-location-list-name"><?= htmlspecialchars($row['location_name']) ?></span>
                                    <?php if (!$rowIsDone && $row['task_date'] <= $dbTodayStr): ?>
                                        <span class="expiration-countdown" data-expiration-countdown data-task-location-id="<?= (int) $row['task_location_id'] ?>" data-remaining-seconds="<?= max(0, (int) $row['remaining_seconds']) ?>">--:--:--</span>
                                    <?php endif; ?>
                                    <span class="scan-location-list-status <?= $rowStatusClass ?>"><?= $rowStatusLabel ?></span>
                                    <?php if (!$rowIsDone): ?><i class="fas fa-chevron-right scan-location-list-chevron"></i><?php endif; ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif (empty($assignedRows)): ?>
                        <p style="color: var(--gray-500); font-size: 14px;">No locations assigned to this task yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php include '../components/appshell_end.php'; ?>

    <!-- Photo Camera Modal -->
    <div id="photoCameraModal" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Take Photo</h3>
                <button type="button" class="modal-close" onclick="closePhotoCamera()">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div class="modal-body">
                <video id="photoCameraVideo" autoplay playsinline></video>
                <canvas id="photoCameraCanvas"></canvas>
                <div id="capturedPhotos"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closePhotoCamera()">Done</button>
                <button type="button" class="btn btn-primary" onclick="capturePhoto()"><i class="fas fa-camera"></i> Capture Photo</button>
            </div>
        </div>
    </div>

    <!-- QR Scanner Modal -->
    <div id="qrScannerModal" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Scan Location QR Code</h3>
                <button type="button" class="modal-close" onclick="closeQRScanner()">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div class="modal-body">
                <div id="qr-reader"></div>
                <p>Hold steady and center QR code in the frame<br>
                    <small>Increase screen brightness if scanning from another phone</small>
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeQRScanner()">Cancel</button>
            </div>
        </div>
    </div>

    <!-- How It Works Modal -->
    <div id="howItWorksModal" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">How Location Check Works</h3>
                <button type="button" class="modal-close" onclick="closeModal('howItWorksModal')">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div class="modal-body">
                <div class="how-it-works-step">
                    <div class="how-it-works-step-num">1</div>
                    <div>
                        <strong>Scan or select a location</strong>
                        <p>Scan its QR code, or pick it from the list if you can't scan right now.</p>
                    </div>
                </div>
                <div class="how-it-works-step">
                    <div class="how-it-works-step-num">2</div>
                    <div>
                        <strong>Answer the checklist</strong>
                        <p>Answer the Observation/Recommendation checklist for the location, then go do the work.</p>
                    </div>
                </div>
                <div class="how-it-works-step">
                    <div class="how-it-works-step-num">3</div>
                    <div>
                        <strong>Come back and finish</strong>
                        <p>Scan (or select) the same location again once you're done, attach up to 3 photos, and confirm your Staff/Biometrics Code to sign off.</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="closeModal('howItWorksModal')">Got it</button>
            </div>
        </div>
    </div>

    <!-- Message Modal -->
    <div id="messageModal" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title" id="msgTitle">Notification</h3>
                <button type="button" class="modal-close" onclick="closeModal('messageModal')">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div class="modal-body" id="msgBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('messageModal')">Close</button>
            </div>
        </div>
    </div>

    <script>
        const QRS_TASK_ID = <?= (int) $taskId ?>;
    </script>
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script src="../scripts/sidebar-drawer.js"></script>
    <script src="../scripts/select-dropdown.js"></script>
    <script src="../scripts/toast.js?v=2"></script>
    <script src="../scripts/scan.js?v=7"></script>
</body>

</html>
