<?php
namespace App\Services;

use App\Database\Database;
use PDO;

final class SearchService
{
    public const MAX_PER_GROUP = 10;
    public const MIN_QUERY_LENGTH = 2;

    /**
     * Perform permission-aware multi-entity search.
     * Returns results grouped by entity type: ['orders' => [...], 'products' => [...], 'customers' => [...]]
     */
    public static function search(string $query, ?string $userRole = null): array
    {
        $q = trim($query);
        if (mb_strlen($q) < self::MIN_QUERY_LENGTH) {
            return [
                'query'       => $q,
                'too_short'   => true,
                'total_count' => 0,
                'orders'      => [],
                'products'    => [],
                'customers'   => [],
            ];
        }

        $pdo = Database::getInstance();
        $term = '%' . $q . '%';
        $results = [
            'query'       => $q,
            'too_short'   => false,
            'total_count' => 0,
            'orders'      => [],
            'products'    => [],
            'customers'   => [],
        ];

        // 1. Orders Search (requires orders.view)
        if (Permissions::can('orders.view', $userRole)) {
            $stmt = $pdo->prepare("
                SELECT id, order_number, recipient_name, phone, status, payment_status, total, created_at
                FROM orders
                WHERE order_number LIKE :s1 
                   OR recipient_name LIKE :s2 
                   OR phone LIKE :s3
                ORDER BY created_at DESC
                LIMIT :limit
            ");
            $stmt->bindValue(':s1', $term);
            $stmt->bindValue(':s2', $term);
            $stmt->bindValue(':s3', $term);
            $stmt->bindValue(':limit', self::MAX_PER_GROUP, PDO::PARAM_INT);
            $stmt->execute();
            $results['orders'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $results['total_count'] += count($results['orders']);
        }

        // 2. Products Search (requires products.view)
        if (Permissions::can('products.view', $userRole)) {
            $stmt = $pdo->prepare("
                SELECT DISTINCT p.id, p.name, p.slug, p.base_price, p.is_active,
                       (SELECT path FROM product_images pi WHERE pi.product_id = p.id AND pi.is_primary = 1 LIMIT 1) as primary_image
                FROM products p
                LEFT JOIN product_variants pv ON pv.product_id = p.id
                WHERE (p.name LIKE :s1 OR pv.sku LIKE :s2)
                  AND p.deleted_at IS NULL
                ORDER BY p.name ASC
                LIMIT :limit
            ");
            $stmt->bindValue(':s1', $term);
            $stmt->bindValue(':s2', $term);
            $stmt->bindValue(':limit', self::MAX_PER_GROUP, PDO::PARAM_INT);
            $stmt->execute();
            $results['products'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $results['total_count'] += count($results['products']);
        }

        // 3. Customers Search (requires customers.view)
        if (Permissions::can('customers.view', $userRole)) {
            $stmt = $pdo->prepare("
                SELECT id, first_name, last_name, email, phone, status, created_at
                FROM users
                WHERE role = 'customer'
                  AND (first_name LIKE :s1 
                    OR last_name LIKE :s2 
                    OR email LIKE :s3 
                    OR phone LIKE :s4
                    OR CONCAT(first_name, ' ', last_name) LIKE :s5)
                ORDER BY first_name ASC, last_name ASC
                LIMIT :limit
            ");
            $stmt->bindValue(':s1', $term);
            $stmt->bindValue(':s2', $term);
            $stmt->bindValue(':s3', $term);
            $stmt->bindValue(':s4', $term);
            $stmt->bindValue(':s5', $term);
            $stmt->bindValue(':limit', self::MAX_PER_GROUP, PDO::PARAM_INT);
            $stmt->execute();
            $results['customers'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $results['total_count'] += count($results['customers']);
        }

        return $results;
    }
}
