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
    jsonError('Please enter your employee ID/username and password.');
}

$pdo = db();

$stmt = $pdo->prepare('
    SELECT u.id, u.employee_id, u.full_name, u.password_hash, u.role_id, u.department_id,
           u.is_active, u.failed_login_attempts, u.locked_until, u.avatar_initials, u.avatar_color,
           r.name AS role_name, d.name AS department_name
    FROM ' . T_USERS . ' u
    LEFT JOIN ' . T_ROLES . ' r ON r.id = u.role_id
    LEFT JOIN ' . T_DEPARTMENTS . ' d ON d.id = u.department_id
    WHERE (u.employee_id = ? OR u.username = ?) AND u.deleted_at IS NULL
');
$stmt->execute([$loginId, $loginId]);
$user = $stmt->fetch();

// Generic failure message throughout (don't reveal whether the account exists).
if (!$user || !$user['is_active']) {
    writeAuditLog(null, 'login.failed', 'user', null, null, ['attempted_id' => $loginId, 'reason' => 'not_found_or_inactive']);
    jsonError('Invalid employee ID/username or password.', 401);
}

if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
    $minutesLeft = max(1, (int) ceil((strtotime($user['locked_until']) - time()) / 60));
    writeAuditLog((int) $user['id'], 'login.failed', 'user', (int) $user['id'], (int) $user['department_id'] ?: null, ['reason' => 'locked']);
    jsonError("Too many failed attempts. Please try again in $minutesLeft minute(s).", 429);
}

if (!verifyPassword($password, $user['password_hash'])) {
    $attempts = (int) $user['failed_login_attempts'] + 1;
    $lockUntil = null;
    if ($attempts >= LOGIN_MAX_ATTEMPTS) {
        $lockUntil = (new DateTime())->modify('+' . LOGIN_LOCKOUT_MINUTES . ' minutes')->format('Y-m-d H:i:s');
    }

    $upd = $pdo->prepare('UPDATE ' . T_USERS . ' SET failed_login_attempts = ?, locked_until = ? WHERE id = ?');
    $upd->execute([$attempts, $lockUntil, $user['id']]);

    writeAuditLog((int) $user['id'], 'login.failed', 'user', (int) $user['id'], (int) $user['department_id'] ?: null, ['reason' => 'bad_password', 'attempts' => $attempts]);
    jsonError('Invalid employee ID/username or password.', 401);
}

// Success
$upd = $pdo->prepare('UPDATE ' . T_USERS . ' SET failed_login_attempts = 0, locked_until = NULL, last_login_at = SYSDATETIME() WHERE id = ?');
$upd->execute([$user['id']]);

session_regenerate_id(true);
$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['employee_id'] = $user['employee_id'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['role_id'] = (int) $user['role_id'];
$_SESSION['role_name'] = $user['role_name'];
$_SESSION['department_id'] = $user['department_id'] !== null ? (int) $user['department_id'] : null;
$_SESSION['department_name'] = $user['department_name'];
$_SESSION['avatar_initials'] = $user['avatar_initials'];
$_SESSION['avatar_color'] = $user['avatar_color'];
$_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // rotate on login

writeAuditLog((int) $user['id'], 'login.success', 'user', (int) $user['id'], (int) $user['department_id'] ?: null);

echo json_encode([
    'success' => true,
    'message' => 'Welcome back, ' . $user['full_name'] . '!',
    'type' => 'success',
    'data' => ['redirect' => 'dashboard.php'],
]);
