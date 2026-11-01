<?php
namespace App\Services;

use App\Database\Database;
use DomainException;
use InvalidArgumentException;
use PDO;

final class StaffService
{
    /**
     * Check if the normalized user_roles table exists in the active database.
     */
    private static function hasUserRolesTable(PDO $pdo): bool
    {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'user_roles'");
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * List all staff and administrator accounts across all departmental roles.
     */
    public static function list(array $filters = []): array
    {
        $pdo = Database::getInstance();
        $hasUserRoles = self::hasUserRolesTable($pdo);
        $adminRolesPlaceholders = "'" . implode("','", Permissions::ADMIN_ROLES) . "'";
        $params = [];

        if ($hasUserRoles) {
            $sql = "
                SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.status, u.created_at, u.updated_at,
                       GROUP_CONCAT(DISTINCT ur.role ORDER BY ur.role ASC SEPARATOR ',') AS assigned_roles
                FROM users u
                LEFT JOIN user_roles ur ON u.id = ur.user_id
                WHERE (ur.role IN ({$adminRolesPlaceholders}) OR u.role IN ({$adminRolesPlaceholders}))
            ";
        } else {
            $sql = "
                SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.status, u.created_at, u.updated_at,
                       u.role AS assigned_roles
                FROM users u
                WHERE u.role IN ({$adminRolesPlaceholders})
            ";
        }

        if (!empty($filters['role']) && Permissions::isAdminRole($filters['role'])) {
            if ($hasUserRoles) {
                $sql .= " AND (ur.role = :filter_role OR u.role = :filter_role)";
            } else {
                $sql .= " AND u.role = :filter_role";
            }
            $params[':filter_role'] = $filters['role'];
        }

        if (!empty($filters['status']) && in_array($filters['status'], ['active', 'blocked'], true)) {
            $sql .= " AND u.status = :status";
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (u.first_name LIKE :s1 OR u.last_name LIKE :s2 OR u.email LIKE :s3 OR CONCAT(u.first_name, ' ', u.last_name) LIKE :s4)";
            $term = '%' . trim($filters['search']) . '%';
            $params[':s1'] = $term;
            $params[':s2'] = $term;
            $params[':s3'] = $term;
            $params[':s4'] = $term;
        }

        if ($hasUserRoles) {
            $sql .= " GROUP BY u.id";
        }

        $sql .= " ORDER BY (u.role = 'super_admin' OR u.role = 'admin') DESC, u.first_name ASC, u.last_name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($results as &$row) {
            $assigned = $row['assigned_roles'] ?? '';
            $rolesList = array_values(array_filter(array_unique(explode(',', $assigned))));
            if (empty($rolesList) && !empty($row['role'])) {
                $rolesList = [$row['role']];
            }
            $row['roles'] = $rolesList;
        }
        unset($row);

        return $results;
    }

    /**
     * Get user by ID (administrative accounts only).
     */
    public static function get(int $id): ?array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("
            SELECT id, first_name, last_name, email, role, status, created_at, updated_at
            FROM users
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return null;
        }

        $roles = Permissions::rolesForUser($id, true);
        $hasAdminRole = false;
        foreach ($roles as $r) {
            if (Permissions::isAdminRole($r)) {
                $hasAdminRole = true;
                break;
            }
        }

        if (!$hasAdminRole) {
            return null;
        }

        $user['roles'] = $roles;
        return $user;
    }

    /**
     * Create staff or administrator account with departmental roles.
     * Queues secure password setup link through PasswordResetService.
     */
    public static function create(array $data, int $actorId, string $ip): int
    {
        if ($actorId > 0) {
            Permissions::require('staff.manage', $actorId);
        }

        $firstName = trim($data['first_name'] ?? '');
        $lastName  = trim($data['last_name'] ?? '');
        $email     = strtolower(trim($data['email'] ?? ''));

        if ($firstName === '' || $lastName === '') {
            throw new InvalidArgumentException("First and last names are required.");
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("A valid work email address is required.");
        }

        // Resolve requested roles (array of roles or single role string)
        $requestedRoles = [];
        if (!empty($data['roles']) && is_array($data['roles'])) {
            $requestedRoles = $data['roles'];
        } elseif (!empty($data['role'])) {
            $requestedRoles = is_array($data['role']) ? $data['role'] : [$data['role']];
        }

        $requestedRoles = array_values(array_filter(array_unique(array_map('trim', $requestedRoles))));
        if (empty($requestedRoles)) {
            $requestedRoles = ['staff'];
        }

        foreach ($requestedRoles as $r) {
            if (!Permissions::isAdminRole($r)) {
                throw new InvalidArgumentException("Invalid administrative role '{$r}'.");
            }
        }

        // Only an existing super_admin (or CLI/system actor 0) can grant super_admin
        if (in_array('super_admin', $requestedRoles, true) && $actorId > 0) {
            $actorRoles = Permissions::rolesForUser($actorId, true);
            if (!in_array('super_admin', $actorRoles, true) && !in_array('admin', $actorRoles, true)) {
                throw new DomainException("Only Super Administrators can create or assign the Super Administrator role.");
            }
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        if ($stmt->fetch()) {
            throw new InvalidArgumentException("An account with email '{$email}' already exists.");
        }

        $primaryRole = in_array('super_admin', $requestedRoles, true)
            ? 'super_admin'
            : (in_array('admin', $requestedRoles, true) ? 'admin' : $requestedRoles[0]);

        $dummyHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        $userId = 0;

        Database::transaction(function (PDO $pdo) use (
            &$userId, $firstName, $lastName, $email, $dummyHash, $primaryRole, $requestedRoles, $actorId, $ip
        ) {
            $ins = $pdo->prepare("
                INSERT INTO users (first_name, last_name, email, password_hash, role, status, created_at)
                VALUES (:fn, :ln, :email, :hash, :role, 'active', NOW())
            ");
            $ins->execute([
                ':fn'    => $firstName,
                ':ln'    => $lastName,
                ':email' => $email,
                ':hash'  => $dummyHash,
                ':role'  => $primaryRole,
            ]);
            $userId = (int)$pdo->lastInsertId();

            if (self::hasUserRolesTable($pdo)) {
                $roleStmt = $pdo->prepare("
                    INSERT INTO user_roles (user_id, role, assigned_at, assigned_by)
                    VALUES (:uid, :role, NOW(), :actor)
                    ON DUPLICATE KEY UPDATE assigned_at = NOW(), assigned_by = VALUES(assigned_by)
                ");
                foreach ($requestedRoles as $roleName) {
                    $roleStmt->execute([
                        ':uid'   => $userId,
                        ':role'  => $roleName,
                        ':actor' => ($actorId > 0 ? $actorId : null),
                    ]);
                }
            }

            AuditLogger::log($actorId, 'staff_create', 'user', $userId, [
                'name'         => $firstName . ' ' . $lastName,
                'email'        => $email,
                'primary_role' => $primaryRole,
                'roles'        => $requestedRoles,
            ], $ip);
        });

        // Queue password setup link
        PasswordResetService::request($email, $ip);

        return $userId;
    }

    /**
     * Update user details and departmental role assignments.
     * Prevents self-demotion and last active Super Admin demotion.
     */
    public static function update(int $id, array $data, int $actorId, string $ip): void
    {
        if ($actorId > 0) {
            Permissions::require('staff.manage', $actorId);
        }

        $user = self::get($id);
        if (!$user) {
            throw new DomainException("Staff account not found.");
        }

        $firstName = trim($data['first_name'] ?? $user['first_name']);
        $lastName  = trim($data['last_name'] ?? $user['last_name']);

        if ($firstName === '' || $lastName === '') {
            throw new InvalidArgumentException("First and last names are required.");
        }

        // Resolve requested roles
        $requestedRoles = [];
        if (isset($data['roles']) && is_array($data['roles'])) {
            $requestedRoles = $data['roles'];
        } elseif (isset($data['role'])) {
            $requestedRoles = is_array($data['role']) ? $data['role'] : [$data['role']];
        } else {
            $requestedRoles = $user['roles'];
        }

        $requestedRoles = array_values(array_filter(array_unique(array_map('trim', $requestedRoles))));
        if (empty($requestedRoles)) {
            $requestedRoles = ['staff'];
        }

        foreach ($requestedRoles as $r) {
            if (!Permissions::isAdminRole($r)) {
                throw new InvalidArgumentException("Invalid administrative role '{$r}'.");
            }
        }

        $currentRoles = $user['roles'];
        $wasSuperAdmin = in_array('super_admin', $currentRoles, true) || in_array('admin', $currentRoles, true);
        $willBeSuperAdmin = in_array('super_admin', $requestedRoles, true) || in_array('admin', $requestedRoles, true);

        // Self-role demotion check
        if ($actorId === $id && $wasSuperAdmin && !$willBeSuperAdmin) {
            throw new DomainException("You cannot change or demote your own Super Administrator role.");
        }

        Database::transaction(function (PDO $pdo) use (
            $id, $user, $firstName, $lastName, $requestedRoles, $wasSuperAdmin, $willBeSuperAdmin, $actorId, $ip
        ) {
            // Last active Super Admin check with row-level locking
            if ($wasSuperAdmin && !$willBeSuperAdmin) {
                $hasUr = self::hasUserRolesTable($pdo);
                if ($hasUr) {
                    $cntStmt = $pdo->query("
                        SELECT id
                        FROM users
                        WHERE status = 'active'
                          AND (role IN ('super_admin', 'admin') OR id IN (SELECT user_id FROM user_roles WHERE role IN ('super_admin', 'admin')))
                        FOR UPDATE
                    ");
                } else {
                    $cntStmt = $pdo->query("
                        SELECT id
                        FROM users
                        WHERE role IN ('super_admin', 'admin') AND status = 'active'
                        FOR UPDATE
                    ");
                }
                $activeSuperAdmins = count($cntStmt->fetchAll(PDO::FETCH_COLUMN));
                if ($activeSuperAdmins <= 1) {
                    throw new DomainException("Cannot demote the last active Super Administrator.");
                }
            }

            $primaryRole = in_array('super_admin', $requestedRoles, true)
                ? 'super_admin'
                : (in_array('admin', $requestedRoles, true) ? 'admin' : $requestedRoles[0]);

            $uStmt = $pdo->prepare("
                UPDATE users 
                SET first_name = :fn, last_name = :ln, role = :role, password_changed_at = NOW()
                WHERE id = :id
            ");
            $uStmt->execute([
                ':fn'   => $firstName,
                ':ln'   => $lastName,
                ':role' => $primaryRole,
                ':id'   => $id,
            ]);

            if (self::hasUserRolesTable($pdo)) {
                // Synchronize user_roles junction table
                $del = $pdo->prepare("DELETE FROM user_roles WHERE user_id = :id");
                $del->execute([':id' => $id]);

                $ins = $pdo->prepare("
                    INSERT INTO user_roles (user_id, role, assigned_at, assigned_by)
                    VALUES (:uid, :role, NOW(), :actor)
                ");
                foreach ($requestedRoles as $roleName) {
                    $ins->execute([
                        ':uid'   => $id,
                        ':role'  => $roleName,
                        ':actor' => ($actorId > 0 ? $actorId : null),
                    ]);
                }
            }

            AuditLogger::log($actorId, 'staff_update', 'user', $id, [
                'previous_roles' => $user['roles'],
                'new_roles'      => $requestedRoles,
                'primary_role'   => $primaryRole,
                'name'           => $firstName . ' ' . $lastName,
            ], $ip);
        });
    }

    /**
     * Toggle active/blocked status.
     * Prevents deactivating self or the last active Super Administrator.
     */
    public static function toggleStatus(int $id, int $actorId, string $ip): string
    {
        if ($actorId > 0) {
            Permissions::require('staff.manage', $actorId);
        }

        $user = self::get($id);
        if (!$user) {
            throw new DomainException("Staff account not found.");
        }

        if ($actorId === $id) {
            throw new DomainException("You cannot deactivate your own account.");
        }

        $targetStatus = $user['status'] === 'active' ? 'blocked' : 'active';
        $isSuperAdmin = in_array('super_admin', $user['roles'], true) || in_array('admin', $user['roles'], true);

        Database::transaction(function (PDO $pdo) use ($id, $user, $targetStatus, $isSuperAdmin, $actorId, $ip) {
            // If deactivating an active Super Admin, ensure at least one other active Super Admin remains
            if ($isSuperAdmin && $targetStatus === 'blocked') {
                $hasUr = self::hasUserRolesTable($pdo);
                if ($hasUr) {
                    $stmt = $pdo->query("
                        SELECT id
                        FROM users
                        WHERE status = 'active'
                          AND (role IN ('super_admin', 'admin') OR id IN (SELECT user_id FROM user_roles WHERE role IN ('super_admin', 'admin')))
                        FOR UPDATE
                    ");
                } else {
                    $stmt = $pdo->query("
                        SELECT id
                        FROM users
                        WHERE role IN ('super_admin', 'admin') AND status = 'active'
                        FOR UPDATE
                    ");
                }
                $activeSuperAdmins = count($stmt->fetchAll(PDO::FETCH_COLUMN));
                if ($activeSuperAdmins <= 1) {
                    throw new DomainException("Cannot deactivate the last active Super Administrator.");
                }
            }

            $stmt = $pdo->prepare("UPDATE users SET status = :status, password_changed_at = NOW() WHERE id = :id");
            $stmt->execute([':status' => $targetStatus, ':id' => $id]);

            AuditLogger::log($actorId, 'staff_toggle_status', 'user', $id, [
                'previous_status' => $user['status'],
                'new_status'      => $targetStatus,
                'email'           => $user['email'],
                'roles'           => $user['roles'],
            ], $ip);
        });

        return $targetStatus;
    }
}

