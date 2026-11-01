<?php

namespace App\Services;

use App\Database\Database;
use DomainException;
use PDO;

/**
 * ShopWise Centralized RBAC Permissions Registry & Engine
 *
 * Implements strict departmental separation for:
 * 1. super_admin     - Universal oversight & administrative authority
 * 2. finance_manager - Payments, M-Pesa reconciliation, refunds, financial reports
 * 3. orders_manager  - Order fulfillment, status transitions, dispatch, packing slips
 * 4. inventory_manager - Stock adjustments, levels, restocking, movements
 * 5. catalog_manager - Products, descriptions, categories, variants, images
 * 6. support_agent   - Customer inquiries, review moderation, customer assistance
 * 7. marketing_manager - Coupons, promotions, merchandising campaigns
 *
 * Plus legacy compatibility roles:
 * - admin            - Universal platform privileges (legacy super_admin alias)
 * - staff            - Baseline operational access during migration window
 * - customer         - Zero administrative access
 */
final class Permissions
{
    /**
     * Canonical Role-to-Permissions Mapping
     */
    private const MAP = [
        'super_admin' => ['*'],
        'admin'       => ['*'],

        'finance_manager' => [
            'dashboard.financial',
            'payments.view',
            'payments.export',
            'payments.reconcile',
            'orders.view',
            'orders.invoice',
            'orders.mark_as_paid',
            'orders.refund',
            'reports.financial',
            'customers.view',
        ],

        'orders_manager' => [
            'orders.view',
            'orders.manage',
            'orders.status_transition',
            'orders.cancel',
            'orders.packing_slip',
            'orders.invoice',
            'orders.notes',
            'delivery.view',
            'delivery.manage',
            'inventory.view',
            'customers.view',
        ],

        'inventory_manager' => [
            'inventory.view',
            'inventory.adjust',
            'inventory.movements',
            'products.view',
        ],

        'catalog_manager' => [
            'products.view',
            'products.create',
            'products.edit',
            'products.delete',
            'categories.view',
            'categories.manage',
            'inventory.view',
            'reviews.view',
        ],

        'support_agent' => [
            'customers.view',
            'customers.manage',
            'messages.view',
            'messages.reply',
            'messages.manage',
            'reviews.view',
            'reviews.moderate',
            'orders.view',
            'orders.notes',
            'coupons.view',
            'products.view',
        ],

        'marketing_manager' => [
            'coupons.view',
            'coupons.manage',
            'coupons.delete',
            'promotions.manage',
            'reports.campaigns',
            'products.view',
            'categories.view',
        ],

        'staff' => [
            'products.view',
            'products.edit',
            'categories.manage',
            'inventory.adjust',
            'orders.view',
            'orders.manage',
            'customers.view',
            'reviews.moderate',
            'messages.manage',
        ],

        'customer' => [],
    ];

    /**
     * Check if a set of roles has a specific ability.
     *
     * @param string $ability Required permission (e.g. 'orders.mark_as_paid')
     * @param array|string|null $roles Role string, array of roles, or null to resolve active context
     * @return bool
     */
    public static function can(string $ability, array|string|null $roles = null): bool
    {
        // 1. If explicit roles were not provided, resolve execution context
        if ($roles === null) {
            // Check explicit system execution context (cron/workers)
            // System jobs only possess their declared, least-privilege capabilities
            if (SecurityContext::isSystem()) {
                return SecurityContext::hasPermission($ability);
            }

            // In CLI without system context, deny execution (no ambient bypass)
            if (php_sapi_name() === 'cli' && !defined('STDIN')) {
                return false;
            }

            // Web context: resolve roles for authenticated session user
            $user = AdminAuthService::user();
            if (!$user) {
                return false;
            }
            $roles = self::rolesForSessionUser();
        }

        $rolesList = is_array($roles) ? $roles : [$roles];
        if (empty($rolesList)) {
            return false;
        }

        foreach ($rolesList as $role) {
            $abilities = self::MAP[$role] ?? [];
            if (in_array('*', $abilities, true) || in_array($ability, $abilities, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Require a specific ability or immediately abort.
     *
     * @param string $ability Required capability
     * @param int|null $actorId Optional actor ID for CLI/service invocations
     * @throws DomainException In CLI when unauthorized
     */
    public static function require(string $ability, ?int $actorId = null): void
    {
        // 1. Explicit system context: verify that the active job specifically has this capability
        if (SecurityContext::isSystem()) {
            if (!SecurityContext::hasPermission($ability)) {
                $job = SecurityContext::getJobName() ?? 'unknown_job';
                throw new DomainException("System Job '{$job}' is not authorized for capability '{$ability}'.");
            }
            return;
        }

        // 2. If actor ID is explicitly passed, check actor permissions
        if ($actorId !== null && $actorId > 0) {
            if (self::canForActor($ability, $actorId)) {
                return;
            }
            if (php_sapi_name() === 'cli') {
                throw new DomainException("Actor #{$actorId} lacks required permission '{$ability}'.");
            }
            http_response_code(403);
            require dirname(__DIR__, 2) . '/views/admin/error-403.php';
            exit;
        }

        // 3. Check active session or environment
        if (self::can($ability)) {
            return;
        }

        if (php_sapi_name() === 'cli') {
            throw new DomainException("CLI process lacks required permission '{$ability}'. Must execute in SecurityContext or provide authorized actor.");
        }

        http_response_code(403);
        require dirname(__DIR__, 2) . '/views/admin/error-403.php';
        exit;
    }

    /**
     * Check if a specific user by ID has an ability.
     */
    public static function canForActor(string $ability, int $actorId): bool
    {
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare("SELECT status FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $actorId]);
            $status = $stmt->fetchColumn();
            if ($status !== 'active') {
                return false;
            }

            $roles = self::rolesForUser($actorId);
            return self::can($ability, $roles);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Resolve all roles assigned to a user (supporting normalized multi-role table).
     *
     * @param array|int $user User record or User ID
     * @return array List of assigned role names
     */
    public static function rolesForUser(array|int $user): array
    {
        if (is_array($user)) {
            if (!empty($user['roles']) && is_array($user['roles'])) {
                return array_unique($user['roles']);
            }
            $userId = (int)($user['id'] ?? 0);
            $sessionRole = $user['role'] ?? null;
        } else {
            $userId = (int)$user;
            $sessionRole = null;
        }

        if ($userId <= 0) {
            return $sessionRole ? [$sessionRole] : [];
        }

        try {
            $pdo = Database::getInstance();
            // 1. Check junction table user_roles
            $stmt = $pdo->prepare("SELECT role FROM user_roles WHERE user_id = :uid ORDER BY role ASC");
            $stmt->execute([':uid' => $userId]);
            $roles = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // Include session role if simulated in tests or pending migration
            if ($sessionRole && !in_array($sessionRole, $roles, true)) {
                $roles[] = $sessionRole;
            }

            if (!empty($roles)) {
                return array_unique($roles);
            }

            // 2. Fallback to primary column on users table
            $stmt2 = $pdo->prepare("SELECT role FROM users WHERE id = :uid LIMIT 1");
            $stmt2->execute([':uid' => $userId]);
            $primaryRole = $stmt2->fetchColumn();

            if ($primaryRole) {
                return [$primaryRole];
            }
        } catch (\Throwable $e) {
            if ($sessionRole) {
                return [$sessionRole];
            }
        }

        return $sessionRole ? [$sessionRole] : [];
    }

    /**
     * Resolve roles for currently authenticated session user.
     */
    public static function rolesForSessionUser(): array
    {
        $sessionUser = AdminAuthService::user();
        if (!$sessionUser) {
            return [];
        }

        if (!empty($_SESSION['admin_user']['roles']) && is_array($_SESSION['admin_user']['roles'])) {
            return $_SESSION['admin_user']['roles'];
        }

        $roles = self::rolesForUser($sessionUser);
        $_SESSION['admin_user']['roles'] = $roles;
        return $roles;
    }

    /**
     * Check if a given role is an administrative role.
     */
    public static function isAdminRole(string $role): bool
    {
        return in_array($role, [
            'super_admin',
            'finance_manager',
            'orders_manager',
            'inventory_manager',
            'catalog_manager',
            'support_agent',
            'marketing_manager',
            'admin',
            'staff',
        ], true);
    }

    /**
     * Return the complete permission map.
     */
    public static function matrix(): array
    {
        return self::MAP;
    }
}
