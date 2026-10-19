<?php
namespace App\Services;

use App\Database\Database;
use DomainException;
use InvalidArgumentException;
use PDO;

final class StaffService
{
    /**
     * List all staff and admin accounts.
     */
    public static function list(array $filters = []): array
    {
        $pdo = Database::getInstance();
        $sql = "
            SELECT id, first_name, last_name, email, role, status, created_at, updated_at
            FROM users
            WHERE role IN ('admin', 'staff')
        ";
        $params = [];

        if (!empty($filters['role']) && in_array($filters['role'], ['admin', 'staff'], true)) {
            $sql .= " AND role = :role";
            $params[':role'] = $filters['role'];
        }

        if (!empty($filters['status']) && in_array($filters['status'], ['active', 'blocked'], true)) {
            $sql .= " AND status = :status";
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (first_name LIKE :s1 OR last_name LIKE :s2 OR email LIKE :s3 OR CONCAT(first_name, ' ', last_name) LIKE :s4)";
            $term = '%' . trim($filters['search']) . '%';
            $params[':s1'] = $term;
            $params[':s2'] = $term;
            $params[':s3'] = $term;
            $params[':s4'] = $term;
        }

        $sql .= " ORDER BY (role = 'admin') DESC, first_name ASC, last_name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get user by ID (admin or staff only).
     */
    public static function get(int $id): ?array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT id, first_name, last_name, email, role, status, created_at, updated_at FROM users WHERE id = :id AND role IN ('admin', 'staff') LIMIT 1");
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    }

    /**
     * Create staff or admin account without an admin-typed password,
     * then queue a secure password set link through PasswordResetService.
     */
    public static function create(array $data, int $actorId, string $ip): int
    {
        $pdo = Database::getInstance();
        if ($actorId > 0) {
            $actStmt = $pdo->prepare("SELECT role FROM users WHERE id = :id LIMIT 1");
            $actStmt->execute([':id' => $actorId]);
            $actorRole = $actStmt->fetchColumn();
            if ($actorRole !== 'admin') {
                throw new DomainException("Only administrators can create staff or administrator accounts.");
            }
        }

        $firstName = trim($data['first_name'] ?? '');
        $lastName = trim($data['last_name'] ?? '');
        $email = strtolower(trim($data['email'] ?? ''));
        $role = trim($data['role'] ?? '');

        if ($firstName === '' || $lastName === '') {
            throw new InvalidArgumentException("First and last names are required.");
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("A valid work email address is required.");
        }

        if (!in_array($role, ['admin', 'staff'], true)) {
            throw new InvalidArgumentException("Role must be 'admin' or 'staff'.");
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        if ($stmt->fetch()) {
            throw new InvalidArgumentException("An account with email '{$email}' already exists.");
        }

        // Generate throwaway unguessable password hash so the account cannot be logged into directly
        // until the user sets their password via the emailed token
        $dummyHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("
            INSERT INTO users (first_name, last_name, email, password_hash, role, status, created_at)
            VALUES (:fn, :ln, :email, :hash, :role, 'active', NOW())
        ");
        $stmt->execute([
            ':fn'    => $firstName,
            ':ln'    => $lastName,
            ':email' => $email,
            ':hash'  => $dummyHash,
            ':role'  => $role,
        ]);

        $userId = (int)$pdo->lastInsertId();

        AuditLogger::log($actorId, 'staff_create', 'user', $userId, [
            'name'  => $firstName . ' ' . $lastName,
            'email' => $email,
            'role'  => $role,
        ], $ip);

        // Queue password setup link for the new user
        PasswordResetService::request($email, $ip);

        return $userId;
    }

    /**
     * Update user details and role.
     * Prevents self-demotion and last active admin demotion.
     */
    public static function update(int $id, array $data, int $actorId, string $ip): void
    {
        $user = self::get($id);
        if (!$user) {
            throw new DomainException("Staff account not found.");
        }

        $firstName = trim($data['first_name'] ?? $user['first_name']);
        $lastName = trim($data['last_name'] ?? $user['last_name']);
        $newRole = trim($data['role'] ?? $user['role']);

        if ($firstName === '' || $lastName === '') {
            throw new InvalidArgumentException("First and last names are required.");
        }

        if (!in_array($newRole, ['admin', 'staff'], true)) {
            throw new InvalidArgumentException("Role must be 'admin' or 'staff'.");
        }

        // Self-role alteration check
        if ($actorId === $id && $newRole !== $user['role']) {
            throw new DomainException("You cannot change your own administrative role.");
        }

        Database::transaction(function (PDO $pdo) use ($id, $user, $firstName, $lastName, $newRole, $actorId, $ip) {
            // Last admin check if demoting an admin to staff
            if ($user['role'] === 'admin' && $newRole === 'staff') {
                $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active' FOR UPDATE");
                $activeAdmins = (int)$stmt->fetchColumn();
                if ($activeAdmins <= 1) {
                    throw new DomainException("Cannot demote the last active administrator.");
                }
            }

            $uStmt = $pdo->prepare("
                UPDATE users 
                SET first_name = :fn, last_name = :ln, role = :role, password_changed_at = NOW()
                WHERE id = :id
            ");
            $uStmt->execute([
                ':fn'   => $firstName,
                ':ln'   => $lastName,
                ':role' => $newRole,
                ':id'   => $id,
            ]);

            AuditLogger::log($actorId, 'staff_update', 'user', $id, [
                'previous_role' => $user['role'],
                'new_role'      => $newRole,
                'name'          => $firstName . ' ' . $lastName,
            ], $ip);
        });
    }

    /**
     * Toggle active/blocked status.
     * Prevents deactivating self or the last active admin.
     */
    public static function toggleStatus(int $id, int $actorId, string $ip): string
    {
        $user = self::get($id);
        if (!$user) {
            throw new DomainException("Staff account not found.");
        }

        if ($actorId === $id) {
            throw new DomainException("You cannot deactivate your own account.");
        }

        $targetStatus = $user['status'] === 'active' ? 'blocked' : 'active';

        Database::transaction(function (PDO $pdo) use ($id, $user, $targetStatus, $actorId, $ip) {
            // If deactivating an active admin, ensure they are not the last active admin
            if ($user['role'] === 'admin' && $targetStatus === 'blocked') {
                $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active' FOR UPDATE");
                $activeAdmins = (int)$stmt->fetchColumn();
                if ($activeAdmins <= 1) {
                    throw new DomainException("Cannot deactivate the last active administrator.");
                }
            }

            $stmt = $pdo->prepare("UPDATE users SET status = :status, password_changed_at = NOW() WHERE id = :id");
            $stmt->execute([':status' => $targetStatus, ':id' => $id]);

            AuditLogger::log($actorId, 'staff_toggle_status', 'user', $id, [
                'previous_status' => $user['status'],
                'new_status'      => $targetStatus,
                'email'           => $user['email'],
                'role'            => $user['role'],
            ], $ip);
        });

        return $targetStatus;
    }
}
