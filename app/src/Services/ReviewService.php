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

    /**
     * Get customer review for a product (if any exists, regardless of status)
     */
    public static function getCustomerReview(int $userId, int $productId): ?array
    {
        if ($userId <= 0 || $productId <= 0) {
            return null;
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("
            SELECT r.*, (r.order_id IS NOT NULL) as is_verified
            FROM reviews r
            WHERE r.user_id = :uid AND r.product_id = :pid
            LIMIT 1
        ");
        $stmt->execute([':uid' => $userId, ':pid' => $productId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Verify if customer is a verified buyer eligible to review this product:
     * - Must have an order containing this product
     * - Order status must be 'delivered' OR payment_status must be 'paid'
     * - Must not have already reviewed this product
     */
    public static function canCustomerReview(int $userId, int $productId): array
    {
        if ($userId <= 0) {
            return [
                'can_review' => false,
                'reason'     => 'auth_required',
                'message'    => 'Please sign in to write a review. Only customers who bought this product can review it.',
                'order_id'   => null,
                'existing'   => null,
            ];
        }

        if ($productId <= 0) {
            return [
                'can_review' => false,
                'reason'     => 'invalid_product',
                'message'    => 'Invalid product.',
                'order_id'   => null,
                'existing'   => null,
            ];
        }

        $existing = self::getCustomerReview($userId, $productId);
        if ($existing) {
            return [
                'can_review' => false,
                'reason'     => 'already_reviewed',
                'message'    => 'You have already submitted a review for this product.',
                'order_id'   => (int)($existing['order_id'] ?? 0) ?: null,
                'existing'   => $existing,
            ];
        }

        $pdo = Database::getInstance();
        // Check if customer has a delivered order or paid order containing this product
        $stmt = $pdo->prepare("
            SELECT o.id
            FROM orders o
            JOIN order_items oi ON oi.order_id = o.id
            WHERE o.user_id = :uid
              AND oi.product_id = :pid
              AND (o.status IN ('delivered', 'completed') OR o.payment_status = 'paid')
            ORDER BY o.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([':uid' => $userId, ':pid' => $productId]);
        $orderId = $stmt->fetchColumn();

        if (!$orderId) {
            return [
                'can_review' => false,
                'reason'     => 'not_purchased',
                'message'    => 'Only customers who bought this product can review it.',
                'order_id'   => null,
                'existing'   => null,
            ];
        }

        return [
            'can_review' => true,
            'reason'     => 'eligible',
            'message'    => 'You are verified to review this product.',
            'order_id'   => (int)$orderId,
            'existing'   => null,
        ];
    }

    /**
     * Submit a customer review (verified buyers only, status: pending)
     */
    public static function submitReview(int $userId, int $productId, int $rating, string $title, string $body, string $ip): array
    {
        $canReview = self::canCustomerReview($userId, $productId);
        if (!$canReview['can_review']) {
            return [false, $canReview['message']];
        }

        $pdo = Database::getInstance();

        // Rate limit: 5 submissions per hour per user/IP
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM reviews
            WHERE user_id = :uid AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute([':uid' => $userId]);
        if ((int)$stmt->fetchColumn() >= 5) {
            return [false, 'Too many review submissions. Please wait an hour before submitting another review.'];
        }

        if ($rating < 1 || $rating > 5) {
            return [false, 'Rating must be between 1 and 5 stars.'];
        }

        $title = trim($title);
        $body = trim($body);

        if ($title === '') {
            return [false, 'Please provide a review title.'];
        }
        if (mb_strlen($title) > 100) {
            return [false, 'Review title cannot exceed 100 characters.'];
        }
        if ($body === '') {
            return [false, 'Please provide review details.'];
        }
        if (mb_strlen($body) > 2000) {
            return [false, 'Review body cannot exceed 2000 characters.'];
        }

        // Sanitize
        $title = strip_tags($title);
        $body = strip_tags($body);
        $orderId = $canReview['order_id'];

        $stmt = $pdo->prepare("
            INSERT INTO reviews (product_id, user_id, order_id, rating, title, body, status, created_at, updated_at)
            VALUES (:pid, :uid, :oid, :rating, :title, :body, 'pending', NOW(), NOW())
        ");
        $stmt->execute([
            ':pid'    => $productId,
            ':uid'    => $userId,
            ':oid'    => $orderId,
            ':rating' => $rating,
            ':title'  => $title,
            ':body'   => $body,
        ]);
        $reviewId = (int)$pdo->lastInsertId();

        AuditLogger::log($userId, 'review_submit', 'review', $reviewId, [
            'product_id' => $productId,
            'rating'     => $rating,
            'order_id'   => $orderId,
        ], $ip);

        return [true, 'Thank you! Your review has been submitted and will appear once approved by our moderation team.'];
    }
}
