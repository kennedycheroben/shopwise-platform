<?php

namespace App\Services;

use App\Database\Database;
use PDO;

final class WishlistService
{
    public const MAX_ITEMS = 200;

    /**
     * Check if a product is in customer's wishlist
     */
    public static function isWishlisted(int $userId, int $productId): bool
    {
        if ($userId <= 0 || $productId <= 0) {
            return false;
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT 1 FROM wishlists WHERE user_id = :uid AND product_id = :pid LIMIT 1");
        $stmt->execute([':uid' => $userId, ':pid' => $productId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Get total wishlist count for customer
     */
    public static function getCount(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM wishlists WHERE user_id = :uid");
        $stmt->execute([':uid' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get all wishlisted product IDs for a user
     */
    public static function getWishlistProductIds(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT product_id FROM wishlists WHERE user_id = :uid");
        $stmt->execute([':uid' => $userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Rate limit wishlist actions per IP (60 actions per minute)
     */
    public static function checkRateLimit(string $ip): bool
    {
        $config = require dirname(__DIR__, 2) . '/config/config.php';
        $storageDir = $config['rate_limits']['storage_dir'] ?? (dirname(__DIR__, 2) . '/app/storage/rate_limits');
        $limit = 60;
        $window = 60;
        $now = time();

        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0755, true);
        }
        if (!is_writable($storageDir)) {
            $fallback = sys_get_temp_dir() . '/shopwise_rate_limits';
            if (!is_dir($fallback)) {
                @mkdir($fallback, 0777, true);
            }
            if (is_writable($fallback)) {
                $storageDir = $fallback;
            }
        }

        $cleanIp = trim(str_replace(["\r", "\n"], '', $ip));
        $ipKey = 'wishlist_ip_' . hash('sha256', $cleanIp);
        $ipFile = $storageDir . '/' . $ipKey . '.json';
        $timestamps = [];

        if (file_exists($ipFile)) {
            $content = @file_get_contents($ipFile);
            $parsed = $content ? @json_decode($content, true) : null;
            if (is_array($parsed)) {
                $timestamps = array_filter($parsed, fn($ts) => ($now - (int)$ts) < $window);
            }
        }

        if (count($timestamps) >= $limit) {
            return false;
        }

        $timestamps[] = $now;
        @file_put_contents($ipFile, json_encode(array_values($timestamps)), LOCK_EX);
        return true;
    }

    /**
     * Toggle product wishlist status (add if missing, remove if present)
     */
    public static function toggle(int $userId, int $productId, string $ip = ''): array
    {
        if ($userId <= 0) {
            return [
                'success' => false,
                'message' => 'Please sign in to save items to your wishlist.',
                'action'  => 'auth_required',
                'count'   => 0,
            ];
        }

        if (!empty($ip) && !self::checkRateLimit($ip)) {
            return [
                'success' => false,
                'message' => 'Too many wishlist requests. Please slow down.',
                'action'  => 'rate_limited',
                'count'   => self::getCount($userId),
            ];
        }

        if ($productId <= 0) {
            return [
                'success' => false,
                'message' => 'Invalid product.',
                'action'  => 'error',
                'count'   => self::getCount($userId),
            ];
        }

        $pdo = Database::getInstance();

        // Verify product exists and is active
        $stmt = $pdo->prepare("SELECT id, name, is_active FROM products WHERE id = :pid LIMIT 1");
        $stmt->execute([':pid' => $productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product || (int)$product['is_active'] !== 1) {
            return [
                'success' => false,
                'message' => 'Product is not available.',
                'action'  => 'error',
                'count'   => self::getCount($userId),
            ];
        }

        if (self::isWishlisted($userId, $productId)) {
            return self::remove($userId, $productId);
        }

        return self::add($userId, $productId);
    }

    /**
     * Add product to customer wishlist
     */
    public static function add(int $userId, int $productId): array
    {
        if ($userId <= 0 || $productId <= 0) {
            return ['success' => false, 'message' => 'Invalid parameters.', 'action' => 'error', 'count' => 0];
        }

        $pdo = Database::getInstance();

        // Check current wishlist size
        $count = self::getCount($userId);
        if ($count >= self::MAX_ITEMS) {
            return [
                'success' => false,
                'message' => 'Your wishlist is full (maximum ' . self::MAX_ITEMS . ' items). Please remove some items first.',
                'action'  => 'limit_reached',
                'count'   => $count,
            ];
        }

        if (self::isWishlisted($userId, $productId)) {
            return [
                'success' => true,
                'message' => 'Item is already in your wishlist.',
                'action'  => 'already_exists',
                'count'   => $count,
            ];
        }

        // Insert ignore
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO wishlists (user_id, product_id, created_at)
            VALUES (:uid, :pid, NOW())
        ");
        $stmt->execute([':uid' => $userId, ':pid' => $productId]);

        $newCount = self::getCount($userId);
        return [
            'success' => true,
            'message' => 'Item added to your wishlist.',
            'action'  => 'added',
            'count'   => $newCount,
        ];
    }

    /**
     * Remove product from customer wishlist
     */
    public static function remove(int $userId, int $productId): array
    {
        if ($userId <= 0 || $productId <= 0) {
            return ['success' => false, 'message' => 'Invalid parameters.', 'action' => 'error', 'count' => 0];
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("DELETE FROM wishlists WHERE user_id = :uid AND product_id = :pid");
        $stmt->execute([':uid' => $userId, ':pid' => $productId]);

        $newCount = self::getCount($userId);
        return [
            'success' => true,
            'message' => 'Item removed from your wishlist.',
            'action'  => 'removed',
            'count'   => $newCount,
        ];
    }

    /**
     * Get detailed wishlist items with product information, pricing, variants, and primary image
     */
    public static function getWishlist(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("
            SELECT w.id as wishlist_id, w.created_at as wishlisted_at,
                   p.id, p.name as title, p.name, p.slug, p.base_price, p.is_active,
                   c.name as category_name, c.slug as category_slug,
                   (
                       SELECT pi.image_path 
                       FROM product_images pi 
                       WHERE pi.product_id = p.id 
                       ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC 
                       LIMIT 1
                   ) as primary_image,
                   (
                       SELECT MIN(pv.price) 
                       FROM product_variants pv 
                       WHERE pv.product_id = p.id AND pv.is_active = 1
                   ) as min_variant_price,
                   (
                       SELECT MAX(pv.price) 
                       FROM product_variants pv 
                       WHERE pv.product_id = p.id AND pv.is_active = 1
                   ) as max_variant_price,
                   (
                       SELECT SUM(pv.stock_qty) 
                       FROM product_variants pv 
                       WHERE pv.product_id = p.id AND pv.is_active = 1
                   ) as total_stock,
                   (
                       SELECT pv.id 
                       FROM product_variants pv 
                       WHERE pv.product_id = p.id AND pv.is_active = 1 AND pv.stock_qty > 0 
                       ORDER BY pv.price ASC 
                       LIMIT 1
                   ) as default_variant_id
            FROM wishlists w
            JOIN products p ON p.id = w.product_id
            LEFT JOIN categories c ON c.id = p.category_id
            WHERE w.user_id = :uid AND p.is_active = 1
            ORDER BY w.created_at DESC
        ");
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
