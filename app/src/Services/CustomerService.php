<?php

namespace App\Services;

use App\Database\Database;
use DomainException;
use InvalidArgumentException;
use PDO;

class CustomerService
{
    /**
     * Paginate customers (users with role = 'customer')
     */
    public static function paginate(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $pdo = Database::getInstance();
        $page = max(1, $page);
        $perPage = max(5, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = ["u.role = 'customer'"];
        $params = [];

        // Search name, email, phone
        if (!empty($filters['search'])) {
            $search = '%' . trim($filters['search']) . '%';
            $where[] = "(CONCAT(u.first_name, ' ', u.last_name) LIKE :s1 OR u.email LIKE :s2 OR u.phone LIKE :s3)";
            $params[':s1'] = $search;
            $params[':s2'] = $search;
            $params[':s3'] = $search;
        }

        // Filter status
        if (!empty($filters['status']) && in_array($filters['status'], ['active', 'blocked'], true)) {
            $where[] = "u.status = :status";
            $params[':status'] = $filters['status'];
        }

        // Filter auth provider / sign-in method
        if (!empty($filters['auth_provider'])) {
            if ($filters['auth_provider'] === 'google') {
                $where[] = "u.google_id IS NOT NULL";
            } elseif ($filters['auth_provider'] === 'password') {
                $where[] = "u.password_hash IS NOT NULL AND u.google_id IS NULL";
            }
        }

        $whereClause = implode(' AND ', $where);

        // Count total matching
        $countSql = "SELECT COUNT(*) FROM users u WHERE {$whereClause}";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalItems = (int)$countStmt->fetchColumn();

        // Sort options
        $sort = $filters['sort'] ?? 'created_at';
        $order = strtolower($filters['order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        $sortColumn = match ($sort) {
            'name'       => 'u.first_name',
            'email'      => 'u.email',
            'status'     => 'u.status',
            'orders'     => 'orders_count',
            'spent'      => 'total_spent',
            default      => 'u.created_at',
        };

        // Query customers with order statistics
        $sql = "
            SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.status,
                   u.google_id, u.email_verified_at, u.created_at, u.last_login_at,
                   COUNT(o.id) as orders_count,
                   COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN o.total ELSE 0 END), 0) as total_spent
            FROM users u
            LEFT JOIN orders o ON o.user_id = u.id
            WHERE {$whereClause}
            GROUP BY u.id
            ORDER BY {$sortColumn} {$order}, u.id DESC
            LIMIT {$perPage} OFFSET {$offset}
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalPages = (int)ceil($totalItems / $perPage);

        return [
            'items'        => $items,
            'total_items'  => $totalItems,
            'current_page' => $page,
            'per_page'     => $perPage,
            'total_pages'  => max(1, $totalPages),
        ];
    }

    /**
     * Find single customer with addresses and order history
     */
    public static function find(int $customerId): ?array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("
            SELECT id, role, first_name, last_name, email, phone, status,
                   google_id, avatar_url, email_verified_at, last_login_at, created_at, updated_at
            FROM users
            WHERE id = :id AND role = 'customer'
            LIMIT 1
        ");
        $stmt->execute([':id' => $customerId]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$customer) {
            return null;
        }

        // Addresses
        $addrStmt = $pdo->prepare("
            SELECT * FROM addresses
            WHERE user_id = :uid
            ORDER BY is_default DESC, id DESC
        ");
        $addrStmt->execute([':uid' => $customerId]);
        $customer['addresses'] = $addrStmt->fetchAll(PDO::FETCH_ASSOC);

        // Order history
        $ordStmt = $pdo->prepare("
            SELECT o.id, o.order_number, o.status, o.payment_status, o.delivery_method,
                   o.total, o.created_at,
                   (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) as items_count
            FROM orders o
            WHERE o.user_id = :uid
            ORDER BY o.created_at DESC
        ");
        $ordStmt->execute([':uid' => $customerId]);
        $customer['orders'] = $ordStmt->fetchAll(PDO::FETCH_ASSOC);

        // Aggregates
        $customer['total_orders'] = count($customer['orders']);
        $customer['total_spent'] = 0.0;
        foreach ($customer['orders'] as $ord) {
            if ($ord['payment_status'] === 'paid') {
                $customer['total_spent'] += (float)$ord['total'];
            }
        }

        return $customer;
    }

    /**
     * Block or unblock a customer (admin only)
     */
    public static function setStatus(int $customerId, string $status, int $actorId, string $ip): bool
    {
        Permissions::require('customers.manage');

        if (!in_array($status, ['active', 'blocked'], true)) {
            throw new InvalidArgumentException("Invalid status '{$status}'. Allowed: active, blocked.");
        }

        $pdo = Database::getInstance();
        $customer = self::find($customerId);
        if (!$customer) {
            throw new DomainException("Customer not found.");
        }

        if ($customer['status'] === $status) {
            return true; // Idempotent
        }

        $stmt = $pdo->prepare("UPDATE users SET status = :status WHERE id = :id AND role = 'customer'");
        $stmt->execute([':status' => $status, ':id' => $customerId]);

        AuditLogger::log($actorId, $status === 'blocked' ? 'customer_block' : 'customer_unblock', 'user', $customerId, [
            'previous_status' => $customer['status'],
            'new_status'      => $status,
            'email'           => $customer['email'],
        ], $ip);

        return true;
    }
}
