<?php
require_once __DIR__ . '/../auth/session.php';
$currentUser = requireLogin();

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../rules/constants.php';
require_once __DIR__ . '/../authz/capabilities.php';
require_once __DIR__ . '/../auth/csrf.php';

$isAdmin = $currentUser['role_name'] === ROLE_ADMIN;

// Admin-only page -- trigger custom 403 Forbidden page for non-administrators
if (!$isAdmin) {
    require_once __DIR__ . '/403.php';
    exit;
}

// Every QRS account, real name/photo derived live from the master list same
// as everywhere else in the app.
$stmt = db()->query('
    SELECT u.id, u.employee_id, u.role_id, u.is_active, u.last_login_at,
           r.name AS role_name,
           ' . fullNameSql('ml', 'u') . ' AS full_name
    FROM ' . T_USERS . ' u
    LEFT JOIN ' . T_ROLES . ' r ON r.id = u.role_id
    LEFT JOIN ' . T_MASTER_LIST . ' ml ON ml.EmployeeID = u.employee_id
    WHERE u.deleted_at IS NULL
    ORDER BY r.name, full_name
');
$manageableUsers = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management — QR Task Check</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/app.css?v=28">
    <link rel="stylesheet" href="../styles/user_management.css?v=1">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    <script src="../scripts/theme.js?v=6"></script>
</head>

<body>
    <?php include '../components/appshell_start.php'; ?>

    <div class="topbar">
        <div class="topbar-title">
            <h1>User Management</h1>
            <p>Add, promote, or deactivate QRS accounts.</p>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div style="display:flex; align-items:center; gap:14px;">
                <div class="modal-header-icon"><i class="fas fa-users-gear"></i></div>
                <div>
                    <h2>User Management</h2>
                    <p style="margin: 2px 0 0; font-size: 13px; color: var(--gray-500);"><?= count($manageableUsers) ?> QRS account<?= count($manageableUsers) === 1 ? '' : 's' ?></p>
                </div>
            </div>
            <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                <input type="text" class="form-input table-search-input" data-target="usersTable" placeholder="Search users…" style="width: 200px;">
                <div class="filter-wrap" data-table="usersTable">
                    <button type="button" class="btn btn-secondary filter-btn" onclick="toggleFilterPanel(this)">
                        <i class="fas fa-sliders"></i> Filters <span class="filter-badge"></span>
                    </button>
                    <div class="filter-panel">
                        <div class="filter-group">
                            <div class="filter-group-title">Role</div>
                            <label class="filter-option"><input type="checkbox" data-filter="role" value="<?= htmlspecialchars(ROLE_ADMIN) ?>"> Admin</label>
                            <label class="filter-option"><input type="checkbox" data-filter="role" value="<?= htmlspecialchars(ROLE_USER) ?>"> User</label>
                        </div>
                        <div class="filter-group">
                            <div class="filter-group-title">Status</div>
                            <label class="filter-option"><input type="checkbox" data-filter="status" value="Active"> Active</label>
                            <label class="filter-option"><input type="checkbox" data-filter="status" value="Inactive"> Inactive</label>
                        </div>
                        <div class="filter-panel-actions">
                            <button type="button" class="btn btn-sm btn-secondary" onclick="clearFilterPanel(this)">Clear</button>
                            <button type="button" class="btn btn-sm btn-primary" onclick="applyFilterPanel(this)">Apply</button>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label for="usersEntries" class="form-label">Show</label>
                    <select id="usersEntries" class="form-input" onchange="onUsersEntriesChange(this.value)">
                        <option value="10" selected>10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="all">All</option>
                    </select>
                    <span class="form-label">entries</span>
                </div>
                <button type="button" class="btn btn-primary" onclick="showModal('addUserModal')"><i class="fas fa-user-plus"></i> Add User</button>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-wrapper">
                <table id="usersTable">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Biometrics ID</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Last Login</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody data-realtime-region="users-table-body">
                        <?php if (empty($manageableUsers)): ?>
                            <tr>
                                <td colspan="6" style="text-align:center; padding: 40px; color: var(--gray-500);">No users yet.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($manageableUsers as $u): ?>
                            <?php
                            $initials = strtoupper(substr((string) $u['full_name'], 0, 1));
                            $fallbackUrl = initialsAvatarUrl($initials, null, '32');
                            $photoUrl = employeePhotoUrl($u['employee_id']);
                            $isSelf = (int) $u['id'] === (int) $currentUser['id'];
                            $roleName = $u['role_name'] ?? ROLE_USER;
                            $isRoleAdmin = $roleName === ROLE_ADMIN;
                            $isActive = (bool) $u['is_active'];
                            ?>
                            <tr data-user-id="<?= (int) $u['id'] ?>" data-role="<?= htmlspecialchars($roleName) ?>" data-status="<?= $isActive ? 'Active' : 'Inactive' ?>">
                                <td>
                                    <div class="avatar-cell">
                                        <img src="<?= htmlspecialchars($photoUrl ?? $fallbackUrl) ?>" alt="" onerror="this.onerror=null;this.src=<?= htmlspecialchars(json_encode($fallbackUrl), ENT_QUOTES) ?>;">
                                        <span class="avatar-cell-name"><?= htmlspecialchars($u['full_name']) ?><?= $isSelf ? ' <span style="color:var(--gray-400);">(You)</span>' : '' ?></span>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($u['employee_id']) ?></td>
                                <td><span class="role-badge <?= $isRoleAdmin ? 'role-badge-admin' : 'role-badge-user' ?>" data-role-badge><?= htmlspecialchars($roleName) ?></span></td>
                                <td><span class="status-badge <?= $isActive ? 'status-available' : 'status-inactive' ?>" data-status-badge><i class="fas <?= $isActive ? 'fa-circle-check' : 'fa-circle-minus' ?>"></i> <?= $isActive ? 'Active' : 'Inactive' ?></span></td>
                                <td><?= $u['last_login_at'] ? htmlspecialchars((new DateTime($u['last_login_at']))->format('M j, Y g:i A')) : 'Never' ?></td>
                                <td>
                                    <?php if ($isSelf): ?>
                                        <span class="kebab-btn is-disabled tooltip-left" data-tooltip="You can't manage your own account"><i class="fas fa-ellipsis-vertical"></i></span>
                                    <?php else: ?>
                                        <div class="kebab-wrap">
                                            <button type="button" class="kebab-btn" onclick="toggleKebab(this)"><i class="fas fa-ellipsis-vertical"></i></button>
                                            <div class="kebab-menu">
                                                <button type="button" onclick="changeUserRole(this)"><i class="fas fa-user-shield"></i> <span data-role-action-label>Make <?= $isRoleAdmin ? 'User' : 'Admin' ?></span></button>
                                                <button type="button" class="<?= $isActive ? 'kebab-danger' : '' ?>" onclick="changeUserStatus(this)"><i class="fas fa-power-off"></i> <span data-status-action-label><?= $isActive ? 'Deactivate' : 'Activate' ?></span></button>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="pagination-container" id="usersPagination"></div>
        </div>
    </div>

    <?php include '../components/appshell_end.php'; ?>

    <!-- Add User Modal -->
    <div id="addUserModal" class="modal-overlay">
        <div class="modal modal-md">
            <div class="modal-header">
                <div class="modal-header-row">
                    <div class="modal-header-icon"><i class="fas fa-user-plus"></i></div>
                    <div>
                        <h3 class="modal-title">Add New User</h3>
                        <p class="modal-subtitle">Search for a user by biometrics number</p>
                    </div>
                </div>
                <button type="button" class="modal-close" onclick="closeAddUserModal()">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group" style="flex-direction: column; align-items: flex-start; gap: 8px;">
                    <label for="add_user_biometrics_input" class="form-label">Biometrics Number</label>
                    <div style="display:flex; gap:8px; width:100%;">
                        <div class="input-clearable" style="flex:1;">
                            <input type="text" id="add_user_biometrics_input" class="form-input" placeholder="Enter biometrics number..." oninput="onAddUserBiometricsInput(this)" onkeydown="if(event.key==='Enter'){event.preventDefault();lookupUserBiometrics();}">
                            <button type="button" class="input-clear-btn" onclick="clearAddUserBiometrics()" aria-label="Clear"><i class="fas fa-circle-xmark"></i></button>
                        </div>
                        <button type="button" class="btn btn-secondary" id="lookupUserBtn" onclick="lookupUserBiometrics()"><i class="fas fa-magnifying-glass"></i> Look Up</button>
                    </div>
                    <p class="form-hint" id="addUserBiometricsHint">Enter the user's biometrics number and click Look Up.</p>
                </div>

                <div id="addUserResult" class="add-user-result" style="display:none;">
                    <div class="add-user-result-avatar">
                        <img id="addUserPhoto" src="" alt="">
                        <span class="add-user-result-badge"><i class="fas fa-check"></i></span>
                    </div>
                    <div>
                        <div id="addUserName" class="add-user-result-name"></div>
                        <div class="add-user-result-detail"><i class="fas fa-briefcase"></i> <span id="addUserPosition"></span></div>
                        <div class="add-user-result-detail"><i class="fas fa-building"></i> <span id="addUserDepartment"></span></div>
                    </div>
                </div>
                <p id="addUserWarning" style="display:none; color: var(--danger-text); font-size:13px; margin-top:10px;"></p>

                <hr class="add-user-divider" id="addUserDivider" style="display:none;">

                <div class="form-group" id="addUserRoleGroup" style="display:none; flex-direction: column; align-items: flex-start; gap: 8px; margin-top: 18px;">
                    <label class="form-label">Role</label>
                    <div class="select-dropdown" data-for="add_user_role_input">
                        <button type="button" class="select-dropdown-trigger" onclick="toggleSelectDropdown(this)">
                            <i class="fas fa-user-shield"></i>
                            <span class="select-dropdown-label">User</span>
                            <i class="fas fa-chevron-down select-dropdown-caret"></i>
                        </button>
                        <div class="select-dropdown-menu">
                            <div class="select-dropdown-option selected" data-value="User">User</div>
                            <div class="select-dropdown-option" data-value="Admin">Admin</div>
                        </div>
                    </div>
                    <select id="add_user_role_input" class="select-dropdown-native" tabindex="-1" aria-hidden="true">
                        <option value="User" selected>User</option>
                        <option value="Admin">Admin</option>
                    </select>
                    <p class="form-hint">Select a role for this user.</p>
                </div>

                <div class="info-banner" id="addUserInfoBanner" style="display:none;">
                    <i class="fas fa-circle-info"></i>
                    <span>This person's existing company login (via their biometrics number) will work automatically -- there's no separate password to set or invitation to send.</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAddUserModal()">Cancel</button>
                <button type="button" class="btn btn-primary" id="addUserSubmitBtn" onclick="submitAddUser()" disabled><i class="fas fa-circle-plus"></i> Add User</button>
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

    <script src="../scripts/sidebar-drawer.js"></script>
    <script src="../scripts/select-dropdown.js"></script>
    <script src="../scripts/toast.js?v=2"></script>
    <script src="../scripts/kebab.js"></script>
    <script src="../scripts/pagination.js?v=7"></script>
    <script src="../scripts/filters.js?v=3"></script>
    <script src="../scripts/user_management.js?v=2"></script>
</body>

</html>
