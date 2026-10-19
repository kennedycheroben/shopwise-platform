<?php
namespace App\Services;

use App\Database\Database;
use PDO;

final class AuditLogService
{
    /**
     * Fetch paginated audit log entries with filters.
     */
    public static function list(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $pdo = Database::getInstance();
        $where = ["1=1"];
        $params = [];

        if (!empty($filters['actor_user_id']) && is_numeric($filters['actor_user_id'])) {
            $where[] = "a.actor_user_id = :actor";
            $params[':actor'] = (int)$filters['actor_user_id'];
        }

        if (!empty($filters['action'])) {
            $where[] = "a.action = :action";
            $params[':action'] = trim($filters['action']);
        }

        if (!empty($filters['entity_type'])) {
            $where[] = "a.entity_type = :entity_type";
            $params[':entity_type'] = trim($filters['entity_type']);
        }

        if (!empty($filters['entity_id']) && is_numeric($filters['entity_id'])) {
            $where[] = "a.entity_id = :entity_id";
            $params[':entity_id'] = (int)$filters['entity_id'];
        }

        if (!empty($filters['ip'])) {
            $where[] = "a.ip LIKE :ip";
            $params[':ip'] = '%' . trim($filters['ip']) . '%';
        }

        if (!empty($filters['date_from'])) {
            $where[] = "a.created_at >= :date_from";
            $params[':date_from'] = trim($filters['date_from']) . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where[] = "a.created_at <= :date_to";
            $params[':date_to'] = trim($filters['date_to']) . ' 23:59:59';
        }

        $whereClause = implode(" AND ", $where);

        // Count total matching items
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_log a WHERE {$whereClause}");
        $countStmt->execute($params);
        $totalItems = (int)$countStmt->fetchColumn();

        $totalPages = max(1, (int)ceil($totalItems / $perPage));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;

        // Fetch paginated rows
        $sql = "
            SELECT a.*, 
                   u.first_name, u.last_name, u.email as actor_email, u.role as actor_role
            FROM audit_log a
            LEFT JOIN users u ON a.actor_user_id = u.id
            WHERE {$whereClause}
            ORDER BY a.created_at DESC, a.id DESC
            LIMIT :limit OFFSET :offset
        ";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'items'        => $rows,
            'total_items'  => $totalItems,
            'current_page' => $page,
            'total_pages'  => $totalPages,
            'per_page'     => $perPage,
        ];
    }

    /**
     * Get distinct actions for dropdown filter.
     */
    public static function getDistinctActions(): array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->query("SELECT DISTINCT action FROM audit_log ORDER BY action ASC");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Get distinct entity types for dropdown filter.
     */
    public static function getDistinctEntityTypes(): array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->query("SELECT DISTINCT entity_type FROM audit_log ORDER BY entity_type ASC");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
