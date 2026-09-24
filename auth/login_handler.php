<?php
/**
 * POST target for pages/login.php. Accepts login_id + login_password,
 * returns JSON {success, message, type, data}.
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/password.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../rules/constants.php';
require_once __DIR__ . '/../authz/capabilities.php';
require_once __DIR__ . '/../authz/audit.php';

header('Content-Type: application/json');
startSession();

function jsonError(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message, 'type' => 'error']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

csrfVerify();

$loginId = trim($_POST['login_id'] ?? '');
$password = (string) ($_POST['login_password'] ?? '');

if ($loginId === '' || $password === '') {
    jsonError('Please enter your biometrics number and password.');
}

$pdo = db();

// Credentials now live in the company-wide lrnph_users table, not
// qrs_users -- bridged via T_MASTER_LIST (lrnph_users.username is the
// person's biometrics number, matching T_MASTER_LIST.BiometricsID; its
// EmployeeID then matches qrs_users.employee_id). QRS-specific state
// (role_id, avatar, lockout counters) normally comes from qrs_users.
// Information Technology employees are the exception: their department is
// managed in the HR master list, so a successful company login automatically
// provisions/reactivates the internal QRS actor row and grants an effective
// Admin session without exposing the account in User Management.
$stmt = $pdo->prepare('
    SELECT u.id, ml.EmployeeID AS employee_id, ' . masterListNameSql('ml') . ' AS full_name,
           ml.Department AS department,
           lu.password AS lrnph_password_hash, lu.status AS lrnph_status,
           u.role_id, u.is_active, u.failed_login_attempts, u.locked_until,
           u.avatar_initials, u.avatar_color, u.deleted_at, r.name AS role_name
    FROM ' . T_LRNPH_USERS . ' lu
    JOIN ' . T_MASTER_LIST . ' ml ON ml.BiometricsID = lu.username
    LEFT JOIN ' . T_USERS . ' u ON u.employee_id = ml.EmployeeID
    LEFT JOIN ' . T_ROLES . ' r ON r.id = u.role_id
    WHERE lu.username = ?
');
$stmt->execute([$loginId]);
$user = $stmt->fetch();
$isDepartmentAdmin = $user && isItDepartmentAdmin($user['department'] ?? null);

// Generic failure message throughout (don't reveal whether the account exists).
if (
    !$user
    || $user['lrnph_status'] !== 'active'
    || (!$isDepartmentAdmin && (!$user['id'] || !$user['is_active'] || $user['deleted_at'] !== null))
) {
    writeAuditLog(null, 'login.failed', 'user', null, ['attempted_id' => $loginId, 'reason' => 'not_found_or_inactive']);
    jsonError('Invalid biometrics number or password.', 401);
}

if ($user['id'] && $user['locked_until'] && strtotime($user['locked_until']) > time()) {
    $minutesLeft = max(1, (int) ceil((strtotime($user['locked_until']) - time()) / 60));
    writeAuditLog((int) $user['id'], 'login.failed', 'user', (int) $user['id'], ['reason' => 'locked']);
    jsonError("Too many failed attempts. Please try again in $minutesLeft minute(s).", 429);
}

if (!verifyPassword($password, $user['lrnph_password_hash'])) {
    $attempts = (int) $user['failed_login_attempts'] + 1;
    $lockUntil = null;
    if ($attempts >= LOGIN_MAX_ATTEMPTS) {
        $lockUntil = (new DateTime())->modify('+' . LOGIN_LOCKOUT_MINUTES . ' minutes')->format('Y-m-d H:i:s');
    }

    if ($user['id']) {
        $upd = $pdo->prepare('UPDATE ' . T_USERS . ' SET failed_login_attempts = ?, locked_until = ? WHERE id = ?');
        $upd->execute([$attempts, $lockUntil, $user['id']]);
    }

    $failedUserId = $user['id'] ? (int) $user['id'] : null;
    writeAuditLog($failedUserId, 'login.failed', 'user', $failedUserId, ['reason' => 'bad_password', 'attempts' => $attempts]);
    jsonError('Invalid biometrics number or password.', 401);
}

// Success
if ($isDepartmentAdmin) {
    $adminRoleStmt = $pdo->prepare('SELECT id FROM ' . T_ROLES . ' WHERE name = ?');
    $adminRoleStmt->execute([ROLE_ADMIN]);
    $adminRoleId = $adminRoleStmt->fetchColumn();

    $userRoleStmt = $pdo->prepare('SELECT id FROM ' . T_ROLES . ' WHERE name = ?');
    $userRoleStmt->execute([ROLE_USER]);
    $userRoleId = $userRoleStmt->fetchColumn();

    if (!$adminRoleId || !$userRoleId) {
        jsonError('The application roles are not configured correctly.', 500);
    }

    // MERGE is protected by HOLDLOCK so two simultaneous first logins cannot
    // create duplicate qrs_users rows for the same IT employee. Existing IT
    // rows are reactivated because their access is controlled by HR department
    // membership and the shared company-login status, not this app's UI.
    $provisionStmt = $pdo->prepare('
        MERGE ' . T_USERS . ' WITH (HOLDLOCK) AS target
        USING (SELECT CAST(? AS VARCHAR(20)) AS employee_id) AS source
            ON target.employee_id = source.employee_id
        WHEN MATCHED THEN
            UPDATE SET is_active = 1, deleted_at = NULL,
                       failed_login_attempts = 0, locked_until = NULL,
                       last_login_at = SYSDATETIME(), updated_at = SYSDATETIME()
        WHEN NOT MATCHED THEN
            INSERT (employee_id, role_id, is_active, failed_login_attempts, last_login_at)
            VALUES (source.employee_id, ?, 1, 0, SYSDATETIME())
        OUTPUT INSERTED.id, INSERTED.avatar_initials, INSERTED.avatar_color;
    ');
    $provisionStmt->execute([$user['employee_id'], $userRoleId]);
    $provisioned = $provisionStmt->fetch();

    $user['id'] = (int) $provisioned['id'];
    $user['avatar_initials'] = $provisioned['avatar_initials'];
    $user['avatar_color'] = $provisioned['avatar_color'];
    $user['role_id'] = (int) $adminRoleId;
    $user['role_name'] = ROLE_ADMIN;
} else {
    $upd = $pdo->prepare('UPDATE ' . T_USERS . ' SET failed_login_attempts = 0, locked_until = NULL, last_login_at = SYSDATETIME() WHERE id = ?');
    $upd->execute([$user['id']]);
}

session_regenerate_id(true);
$sessionNow = time();
$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['employee_id'] = $user['employee_id'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['role_id'] = (int) $user['role_id'];
$_SESSION['role_name'] = $user['role_name'];
$_SESSION['avatar_initials'] = $user['avatar_initials'];
$_SESSION['avatar_color'] = $user['avatar_color'];
$_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // rotate on login
$_SESSION['last_activity_at'] = $sessionNow;
$_SESSION['token_issued_at'] = $sessionNow;

writeAuditLog((int) $user['id'], 'login.success', 'user', (int) $user['id'], [
    'department_managed_admin' => $isDepartmentAdmin,
]);

echo json_encode([
    'success' => true,
    'message' => 'Welcome back, ' . $user['full_name'] . '!',
    'type' => 'success',
    'data' => ['redirect' => 'dashboard.php'],
]);
