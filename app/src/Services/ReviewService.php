<?php
namespace App\Services;

use App\Database\Database;
use DomainException;
use InvalidArgumentException;
use PDO;

final class ReviewService
{
    /**
     * List reviews with filtering and pagination support.
     */
    public static function list(array $filters = []): array
    {
        $pdo = Database::getInstance();
        $sql = "
            SELECT r.*, 
                   p.name as product_name, p.slug as product_slug,
                   u.first_name, u.last_name, u.email as user_email,
                   (r.order_id IS NOT NULL) as is_verified
            FROM reviews r
            JOIN products p ON r.product_id = p.id
            JOIN users u ON r.user_id = u.id
            WHERE 1=1
        ";
        $params = [];

        if (!empty($filters['status']) && in_array($filters['status'], ['pending', 'approved', 'rejected'], true)) {
            $sql .= " AND r.status = :status";
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['rating']) && is_numeric($filters['rating'])) {
            $sql .= " AND r.rating = :rating";
            $params[':rating'] = (int)$filters['rating'];
        }

        if (!empty($filters['product_id']) && is_numeric($filters['product_id'])) {
            $sql .= " AND r.product_id = :pid";
            $params[':pid'] = (int)$filters['product_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (r.title LIKE :s1 OR r.body LIKE :s2 OR p.name LIKE :s3 OR CONCAT(u.first_name, ' ', u.last_name) LIKE :s4)";
            $term = '%' . trim($filters['search']) . '%';
            $params[':s1'] = $term;
            $params[':s2'] = $term;
            $params[':s3'] = $term;
            $params[':s4'] = $term;
        }

        $sql .= " ORDER BY r.created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get review by ID.
     */
    public static function get(int $id): ?array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("
            SELECT r.*, 
                   p.name as product_name, p.slug as product_slug,
                   u.first_name, u.last_name, u.email as user_email,
                   (r.order_id IS NOT NULL) as is_verified
            FROM reviews r
            JOIN products p ON r.product_id = p.id
            JOIN users u ON r.user_id = u.id
            WHERE r.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ?: null;
    }

    /**
     * Moderate review status (approve, reject, or mark pending).
     */
    public static function moderate(int $id, string $status, int $actorId, string $ip): void
    {
        if (!in_array($status, ['approved', 'rejected', 'pending'], true)) {
            throw new InvalidArgumentException("Invalid review moderation status: '{$status}'.");
        }

        $review = self::get($id);
        if (!$review) {
            throw new DomainException("Review not found.");
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("UPDATE reviews SET status = :status, updated_at = NOW() WHERE id = :id");
        $stmt->execute([':status' => $status, ':id' => $id]);

        AuditLogger::log($actorId, 'review_moderate', 'review', $id, [
            'previous_status' => $review['status'],
            'new_status'      => $status,
            'product_id'      => $review['product_id'],
            'product_name'    => $review['product_name'],
            'rating'          => $review['rating'],
        ], $ip);
    }

    /**
     * Delete review (Admin only).
     */
    public static function delete(int $id, int $actorId, string $ip): void
    {
        $pdo = Database::getInstance();
        if ($actorId > 0) {
            $actStmt = $pdo->prepare("SELECT role FROM users WHERE id = :id LIMIT 1");
            $actStmt->execute([':id' => $actorId]);
            $actorRole = $actStmt->fetchColumn();
            if ($actorRole !== 'admin') {
                throw new DomainException("Only administrators can delete product reviews.");
            }
        }

        $review = self::get($id);
        if (!$review) {
            throw new DomainException("Review not found.");
        }

        $stmt = $pdo->prepare("DELETE FROM reviews WHERE id = :id");
        $stmt->execute([':id' => $id]);

        AuditLogger::log($actorId, 'review_delete', 'review', $id, [
            'product_id'   => $review['product_id'],
            'product_name' => $review['product_name'],
            'title'        => $review['title'],
            'rating'       => $review['rating'],
        ], $ip);
    }

    /**
     * Get count of reviews awaiting moderation.
     */
    public static function getPendingCount(): int
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->query("SELECT COUNT(*) FROM reviews WHERE status = 'pending'");
        return (int)$stmt->fetchColumn();
    }
}
