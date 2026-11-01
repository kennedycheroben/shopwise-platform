<?php

namespace App\Services;

use App\Database\Database;
use PDO;

final class AdminAuthService
{
    /**
     * Get currently authenticated admin or staff member from session
     */
    public static function user(): ?array
    {
        return $_SESSION['admin_user'] ?? null;
    }

    /**
     * Authenticate an admin or staff user with brute-force protection and timing mitigation
     */
    public static function login(string $email, string $password, string $ip): array
    {
        $pdo = Database::getInstance();
        $email = strtolower(trim($email));

        // 1. Account-specific rate limit: 5 failed attempts per identifier+IP in 15 minutes
        $attempts = $pdo->prepare("
            SELECT COUNT(*) FROM login_attempts
            WHERE identifier = :email AND ip = :ip AND success = 0
              AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $attempts->execute([':email' => $email, ':ip' => $ip]);
        if ((int)$attempts->fetchColumn() >= 5) {
            return [false, 'Too many sign-in attempts. Please wait 15 minutes before trying again.'];
        }

        // 2. Global IP rate limit: 20 failed attempts per IP in 15 minutes (mitigates credential stuffing)
        $ipAttempts = $pdo->prepare("
            SELECT COUNT(*) FROM login_attempts
            WHERE ip = :ip AND success = 0
              AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $ipAttempts->execute([':ip' => $ip]);
        if ((int)$ipAttempts->fetchColumn() >= 20) {
            return [false, 'Too many sign-in attempts. Please wait 15 minutes before trying again.'];
        }

        // 3. Query user
        $stmt = $pdo->prepare("
            SELECT id, role, first_name, last_name, email, password_hash, status, password_changed_at
            FROM users
            WHERE email = :email
            LIMIT 1
        ");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Mitigation against timing attacks for non-existent accounts
        $dummyHash = '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234';
        $hashToVerify = ($user && !empty($user['password_hash'])) ? $user['password_hash'] : $dummyHash;
        $passwordMatches = password_verify($password, $hashToVerify);

        $assignedRoles = [];
        $hasAdminRole = false;
        if ($user) {
            $assignedRoles = Permissions::rolesForUser((int)$user['id'], true);
            foreach ($assignedRoles as $r) {
                if (Permissions::isAdminRole($r)) {
                    $hasAdminRole = true;
                    break;
                }
            }
        }

        $ok = $user
            && $hasAdminRole
            && $user['status'] === 'active'
            && $passwordMatches;

        $pdo->prepare('INSERT INTO login_attempts (identifier, ip, success) VALUES (:email, :ip, :success)')
            ->execute([
                ':email'   => $email,
                ':ip'      => $ip,
                ':success' => $ok ? 1 : 0,
            ]);

        if (!$ok) {
            return [false, 'Those sign-in details do not grant access to ShopWise administration.'];
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        unset($user['password_hash']);
        $user['roles'] = $assignedRoles;
        $user['auth_time'] = time();
        $_SESSION['admin_user'] = $user;

        $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')
            ->execute([':id' => $user['id']]);

        return [true, ''];
    }

    /**
     * Terminate the admin session
     */
    public static function logout(): void
    {
        unset($_SESSION['admin_user']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * Require an authenticated admin or staff session
     */
    public static function requireLogin(): array
    {
        $user = self::user();
        if (!$user) {
            header('Location: ' . url('admin/login.php'));
            exit;
        }
        return $user;
    }

    /**
     * Require admin-only role (super_admin or legacy admin)
     */
    public static function requireAdmin(): array
    {
        $user = self::requireLogin();
        $roles = Permissions::rolesForSessionUser();
        if (!in_array('admin', $roles, true) && !in_array('super_admin', $roles, true)) {
            http_response_code(403);
            require dirname(__DIR__, 2) . '/views/admin/error-403.php';
            exit;
        }
        return $user;
    }

    /**
     * Capability check (delegates to centralized Permissions engine)
     */
    public static function can(string $capability): bool
    {
        $u = self::user();
        if (!$u) {
            return false;
        }
        $map = [
            'dashboard' => 'dashboard.view',
            'products'  => 'products.view',
            'inventory' => 'inventory.adjust',
            'orders'    => 'orders.view',
            'customers' => 'customers.view',
            'reviews'   => 'reviews.moderate',
            'messages'  => 'messages.manage',
        ];
        $ability = $map[$capability] ?? $capability;
        $roles = Permissions::rolesForSessionUser();
        return Permissions::can($ability, $roles);
    }
}
