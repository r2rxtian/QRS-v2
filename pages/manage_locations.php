<?php
require_once __DIR__ . '/../auth/session.php';
$currentUser = requireLogin();

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../rules/constants.php';
require_once __DIR__ . '/../rules/status.php';
require_once __DIR__ . '/../authz/capabilities.php';
require_once __DIR__ . '/../auth/csrf.php';

$pdo = db();
$canManage = roleHasCapability($currentUser['role_name'], 'location.create'); // create/update/delete/import share one capability
$hasCheckboxColumn = $canManage; // the row checkbox only serves the bulk actions bar, which needs it too

// "Busy" = the location's CURRENT ticket (its most recent task_locations
// row) hasn't been explicitly unassigned -- regardless of whether that
// ticket is Completed, since finishing a checklist doesn't free the
// location on its own; only an explicit Unassign does (nothing in this
// app auto-releases a location, matching the "no automatic behavior"
// rule tasks themselves follow). Checking only the latest row per
// location matters because a location can still end up with more than
// one historical ticket under the same task -- an Admin unassigning and
// re-assigning it.
$stmt = $pdo->query('
    SELECT l.id, l.name, l.qr_token, l.created_at, l.location_type,
           CASE WHEN EXISTS (
               SELECT 1 FROM ' . T_TASK_LOCATIONS . ' tl
               WHERE tl.location_id = l.id AND tl.unassigned_at IS NULL
                 AND tl.id = (
                     SELECT MAX(tl2.id) FROM ' . T_TASK_LOCATIONS . ' tl2
                     WHERE tl2.task_id = tl.task_id AND tl2.location_id = tl.location_id
                 )
           ) THEN 1 ELSE 0 END AS is_assigned
    FROM ' . T_LOCATIONS . ' l
    WHERE l.deleted_at IS NULL AND l.is_active = 1
    ORDER BY is_assigned DESC, l.name
');

$locations = [];
$statAvailable = 0;
$statAssigned = 0;
foreach ($stmt->fetchAll() as $row) {
    $row['badge'] = locationStatusBadge((bool) $row['is_assigned']);
    $locations[] = $row;
    if ($row['is_assigned']) {
        $statAssigned++;
    } else {
        $statAvailable++;
    }
}
$statTotal = count($locations);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Locations — QR Task Check</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/app.css?v=10">
    <link rel="stylesheet" href="../styles/manage_locations.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    <script src="../scripts/theme.js?v=5"></script>
</head>

<body>
    <?php include '../components/appshell_start.php'; ?>

    <div class="topbar">
        <div class="topbar-title">
            <h1>Manage Locations</h1>
            <p>Add, edit, and generate QR codes for each location.</p>
        </div>
        <div class="topbar-actions">
            <?php if ($canManage): ?>
                <button type="button" class="btn btn-secondary" onclick="showModal('csvModal')"><i class="fas fa-file-csv"></i> Import CSV</button>
                <button type="button" class="btn btn-primary" onclick="showModal('addLocationModal')"><i class="fas fa-circle-plus"></i> Add Location</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="stat-tiles">
        <div class="stat-tile">
            <div class="stat-tile-top">
                <div class="stat-tile-icon periwinkle"><i class="fas fa-location-dot"></i></div>
            </div>
            <div class="stat-tile-value"><?= $statTotal ?></div>
            <div class="stat-tile-label">Total Locations</div>
        </div>
        <div class="stat-tile">
            <div class="stat-tile-top">
                <div class="stat-tile-icon teal"><i class="fas fa-circle-check"></i></div>
            </div>
            <div class="stat-tile-value"><?= $statAvailable ?></div>
            <div class="stat-tile-label">Available</div>
        </div>
        <div class="stat-tile">
            <div class="stat-tile-top">
                <div class="stat-tile-icon purple"><i class="fas fa-clipboard-list"></i></div>
            </div>
            <div class="stat-tile-value"><?= $statAssigned ?></div>
            <div class="stat-tile-label">Currently Assigned</div>
        </div>
    </div>

    <!-- Locations Table -->
    <div class="card">
        <div class="card-header">
            <div style="display:flex; align-items:center; gap:14px;">
                <div class="modal-header-icon"><i class="fas fa-map-location-dot"></i></div>
                <div>
                    <h2>All Locations</h2>
                    <p style="margin: 2px 0 0; font-size: 13px; color: var(--gray-500);"><?= $statTotal ?> QR-coded locations</p>
                </div>
            </div>
            <div style="display:flex; gap:10px; align-items:center;">
                <div class="filter-wrap" data-table="locationsTable">
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
                                    <div class="sort-dropdown-group-title">Location Name</div>
                                    <div class="sort-dropdown-option" data-value="2:text:asc" data-label="Location Name (A → Z)">A → Z</div>
                                    <div class="sort-dropdown-option" data-value="2:text:desc" data-label="Location Name (Z → A)">Z → A</div>
                                    <div class="sort-dropdown-group-title">Type</div>
                                    <div class="sort-dropdown-option" data-value="3:text:asc" data-label="Type (A → Z)">A → Z</div>
                                    <div class="sort-dropdown-option" data-value="3:text:desc" data-label="Type (Z → A)">Z → A</div>
                                    <div class="sort-dropdown-group-title">Status</div>
                                    <div class="sort-dropdown-option" data-value="4:text:asc" data-label="Status (A → Z)">A → Z</div>
                                    <div class="sort-dropdown-option" data-value="4:text:desc" data-label="Status (Z → A)">Z → A</div>
                                    <div class="sort-dropdown-group-title">Date Added</div>
                                    <div class="sort-dropdown-option" data-value="5:text:asc" data-label="Date Added (Oldest first)">Oldest first</div>
                                    <div class="sort-dropdown-option" data-value="5:text:desc" data-label="Date Added (Newest first)">Newest first</div>
                                </div>
                            </div>
                        </div>
                        <div class="filter-group">
                            <div class="filter-group-title">Type</div>
                            <?php foreach (TASK_TYPES as $type): ?>
                                <label class="filter-option"><input type="checkbox" data-filter="loctype" value="<?= htmlspecialchars($type) ?>" checked> <?= htmlspecialchars($type) ?></label>
                            <?php endforeach; ?>
                        </div>
                        <div class="filter-panel-actions">
                            <button type="button" class="btn btn-sm btn-secondary" onclick="clearFilterPanel(this)">Clear</button>
                            <button type="button" class="btn btn-sm btn-primary" onclick="applyFilterPanel(this)">Apply</button>
                        </div>
                    </div>
                </div>
                <input type="text" class="form-input table-search-input" data-target="locationsTable" placeholder="Search locations…" style="width: 220px;">
            </div>
        </div>
        <?php if ($canManage): ?>
            <div class="bulk-actions">
                <div class="bulk-actions-left">
                    <label class="checkbox-label">
                        <input type="checkbox" id="select_all" onclick="toggleSelectAll(this)">
                        Select all
                    </label>
                    <span class="bulk-actions-count" id="locationSelectedCount"></span>
                </div>
                <div class="bulk-actions-right">
                    <button type="button" class="btn btn-danger btn-sm" onclick="deleteSelected()" data-tooltip="Irreversible action: Delete selected entries"><i class="fas fa-trash"></i> Delete Selected</button>
                </div>
            </div>
        <?php endif; ?>
        <div class="card-body p-0">
            <div class="table-wrapper">
                <table id="locationsTable">
                    <thead>
                        <tr>
                            <?php if ($hasCheckboxColumn): ?>
                                <th></th>
                            <?php endif; ?>
                            <th>QR Code</th>
                            <th>Location Name</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Date Added</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($locations)): ?>
                            <tr>
                                <td colspan="<?= $hasCheckboxColumn ? 7 : 6 ?>" style="text-align:center; padding: 40px; color: var(--gray-500);">No locations yet.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($locations as $location): ?>
                            <?php $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' . rawurlencode($location['qr_token']); ?>
                            <tr data-location-id="<?= (int) $location['id'] ?>" data-loctype="<?= htmlspecialchars($location['location_type']) ?>">
                                <?php if ($hasCheckboxColumn): ?>
                                    <td><input type="checkbox" class="row-check" value="<?= (int) $location['id'] ?>"></td>
                                <?php endif; ?>
                                <td><img class="qr-thumb" src="<?= htmlspecialchars($qrUrl) ?>" alt="QR: <?= htmlspecialchars($location['name']) ?>"></td>
                                <td class="location-name">
                                    <div class="row-icon-name">
                                        <div class="row-icon"><i class="fas fa-location-dot"></i></div>
                                        <span><?= htmlspecialchars($location['name']) ?></span>
                                    </div>
                                </td>
                                <td><span class="method-badge"><?= htmlspecialchars($location['location_type']) ?></span></td>
                                <td><span class="status-badge <?= htmlspecialchars($location['badge']['class']) ?>"><?= htmlspecialchars($location['badge']['label']) ?></span></td>
                                <td><?= htmlspecialchars((new DateTime($location['created_at']))->format('Y-m-d')) ?></td>
                                <td>
                                    <div class="kebab-wrap">
                                        <button type="button" class="kebab-btn" onclick="toggleKebab(this)"><i class="fas fa-ellipsis-vertical"></i></button>
                                        <div class="kebab-menu">
                                            <button type="button" onclick="printQR(<?= htmlspecialchars(json_encode($location['name'], JSON_HEX_QUOT | JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES) ?>, this)"><i class="fas fa-print"></i> Print QR</button>
                                            <?php if ($canManage): ?>
                                                <button type="button" class="kebab-danger" onclick="confirmDeleteLocation(this)"><i class="fas fa-trash"></i> Delete</button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="pagination-container" id="locationsPagination"></div>
        </div>
    </div>

    <?php include '../components/appshell_end.php'; ?>

    <!-- Add Location Modal -->
    <div id="addLocationModal" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Add New Location</h3>
                <button type="button" class="modal-close" onclick="closeModal('addLocationModal')">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group" style="flex-direction: column; align-items: flex-start; gap: 8px;">
                    <label for="location_name_input" class="form-label">Location Name</label>
                    <input type="text" id="location_name_input" class="form-input" placeholder="Enter location name..." style="width: 100%;">
                </div>
                <div class="form-group" style="flex-direction: column; align-items: flex-start; gap: 8px; margin-top: 14px;">
                    <label class="form-label">Type</label>
                    <div class="select-dropdown" data-for="location_type_input">
                        <button type="button" class="select-dropdown-trigger" onclick="toggleSelectDropdown(this)">
                            <i class="fas fa-diagram-project"></i>
                            <span class="select-dropdown-label"><?= htmlspecialchars(TASK_TYPES[0]) ?></span>
                            <i class="fas fa-chevron-down select-dropdown-caret"></i>
                        </button>
                        <div class="select-dropdown-menu">
                            <?php foreach (TASK_TYPES as $i => $type): ?>
                                <div class="select-dropdown-option<?= $i === 0 ? ' selected' : '' ?>" data-value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <select id="location_type_input" class="select-dropdown-native" tabindex="-1" aria-hidden="true">
                        <?php foreach (TASK_TYPES as $type): ?>
                            <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addLocationModal')">Cancel</button>
                <button type="button" class="btn btn-primary" id="addLocationSubmitBtn" onclick="submitAddLocation()"><i class="fas fa-circle-plus"></i> Add Location</button>
            </div>
        </div>
    </div>

    <!-- CSV Import Modal -->
    <div id="csvModal" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Import Locations from CSV</h3>
                <button type="button" class="modal-close" onclick="closeModal('csvModal')">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div class="modal-body">
                <div class="csv-upload-row">
                    <label class="file-input-label">
                        <i class="fas fa-file-csv"></i> Choose CSV file
                        <input type="file" id="csv_file_input" accept=".csv">
                    </label>
                </div>
                <p style="font-size:13px; color: var(--gray-500); margin-top: 12px;">Column expected: Location Name</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('csvModal')">Cancel</button>
                <button type="button" class="btn btn-primary" id="csvUploadSubmitBtn" onclick="submitCsvImport()">Upload</button>
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

    <!-- Confirm Modal -->
    <div id="confirmModal" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Confirm Action</h3>
            </div>
            <div class="modal-body" id="confirmBody">Are you sure you want to proceed?</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('confirmModal')">Cancel</button>
                <button type="button" id="confirmBtn" class="btn btn-danger-solid">Yes, Proceed</button>
            </div>
        </div>
    </div>

    <script src="../scripts/sidebar-drawer.js"></script>
    <script src="../scripts/kebab.js"></script>
    <script src="../scripts/pagination.js"></script>
    <script src="../scripts/sort-table.js"></script>
    <script src="../scripts/select-dropdown.js"></script>
    <script src="../scripts/filters.js"></script>
    <script src="../scripts/toast.js"></script>
    <script src="../scripts/manage_locations.js"></script>
</body>

</html>
