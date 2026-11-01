<?php
use App\Database\Database;
use App\Services\AdminAuthService;
use App\Services\Permissions;

header('Cache-Control: no-store, private');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

$return = $_SERVER['REQUEST_URI'] ?? '/admin/';
if (!str_starts_with($return, '/') || str_starts_with($return, '//')) {
    $return = '/admin/';
}

$sessionUser = AdminAuthService::user();
if (!$sessionUser) {
    header('Location: ' . url('admin/login.php?return=' . rawurlencode($return)));
    exit;
}

$stmt = Database::getInstance()->prepare('SELECT id, role, first_name, last_name, email, status, password_changed_at FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $sessionUser['id']]);
$current = $stmt->fetch();

// 1. Account must exist and remain active
if (!$current || $current['status'] !== 'active') {
    AdminAuthService::logout();
    header('Location: ' . url('admin/login.php'));
    exit;
}

// 2. Terminate session immediately if password has changed
if (($sessionUser['password_changed_at'] ?? null) !== $current['password_changed_at']) {
    AdminAuthService::logout();
    header('Location: ' . url('admin/login.php'));
    exit;
}

// 3. Resolve authoritative fresh roles from database (user_roles with fallback to users.role)
$freshRoles = Permissions::rolesForUser((int)$current['id'], true);

// 4. Must hold at least one administrative role
$hasAdminRole = false;
foreach ($freshRoles as $r) {
    if (Permissions::isAdminRole($r)) {
        $hasAdminRole = true;
        break;
    }
}

if (!$hasAdminRole) {
    AdminAuthService::logout();
    header('Location: ' . url('admin/login.php'));
    exit;
}

// 5. Refresh session with current database state and authoritative roles
$current['roles'] = $freshRoles;
$_SESSION['admin_user'] = $current;
$adminUser = $current;

