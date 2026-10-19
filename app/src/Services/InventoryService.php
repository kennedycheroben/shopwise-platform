<?php

namespace App\Services;

use App\Database\Database;
use DomainException;
use InvalidArgumentException;
use PDO;

class InventoryService
{
    /**
     * Get summary metrics for inventory dashboard
     */
    public static function getSummaryMetrics(): array
    {
        $pdo = Database::getInstance();
        $sql = "SELECT 
                    COALESCE(SUM(pv.stock_qty), 0) as total_units,
                    COUNT(CASE WHEN pv.stock_qty = 0 THEN 1 END) as out_of_stock_count,
                    COUNT(CASE WHEN pv.stock_qty > 0 AND pv.stock_qty <= pv.low_stock_threshold THEN 1 END) as low_stock_count,
                    COUNT(pv.id) as total_variants
                FROM product_variants pv
                JOIN products p ON pv.product_id = p.id
                WHERE p.deleted_at IS NULL AND pv.is_active = 1";
        $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);

        return [
            'total_units'        => (int)($row['total_units'] ?? 0),
            'out_of_stock_count' => (int)($row['out_of_stock_count'] ?? 0),
            'low_stock_count'    => (int)($row['low_stock_count'] ?? 0),
            'total_variants'     => (int)($row['total_variants'] ?? 0),
        ];
    }

    /**
     * Paginate variants with search and filter
     */
    public static function paginateVariants(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $pdo = Database::getInstance();
        $page = max(1, $page);
        $perPage = max(5, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = ["p.deleted_at IS NULL"];
        $params = [];

        // Search by product name, SKU, or slug
        if (!empty($filters['search'])) {
            $search = '%' . trim($filters['search']) . '%';
            $where[] = "(p.name LIKE :s1 OR pv.sku LIKE :s2 OR p.slug LIKE :s3)";
            $params[':s1'] = $search;
            $params[':s2'] = $search;
            $params[':s3'] = $search;
        }

        // Category filter
        if (!empty($filters['category_id'])) {
            $where[] = "p.category_id = :cat_id";
            $params[':cat_id'] = (int)$filters['category_id'];
        }

        // Stock status filter
        if (!empty($filters['stock_status'])) {
            if ($filters['stock_status'] === 'out_of_stock') {
                $where[] = "pv.stock_qty = 0";
            } elseif ($filters['stock_status'] === 'low_stock') {
                $where[] = "pv.stock_qty > 0 AND pv.stock_qty <= pv.low_stock_threshold";
            } elseif ($filters['stock_status'] === 'in_stock') {
                $where[] = "pv.stock_qty > pv.low_stock_threshold";
            }
        }

        $whereClause = implode(' AND ', $where);

        // Count total matching
        $countSql = "SELECT COUNT(*) FROM product_variants pv JOIN products p ON pv.product_id = p.id WHERE {$whereClause}";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalItems = (int)$countStmt->fetchColumn();

        // Sort options
        $sort = $filters['sort'] ?? 'product_name';
        $order = strtolower($filters['order'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
        $sortColumn = match ($sort) {
            'sku'        => 'pv.sku',
            'stock'      => 'pv.stock_qty',
            'threshold'  => 'pv.low_stock_threshold',
            'created'    => 'pv.created_at',
            default      => 'p.name',
        };

        $sql = "SELECT pv.*, 
                       p.name as product_name, 
                       p.slug as product_slug,
                       p.base_price,
                       c.name as category_name,
                       (SELECT pi.path FROM product_images pi WHERE pi.product_id = p.id AND pi.is_primary = 1 LIMIT 1) as primary_image
                FROM product_variants pv
                JOIN products p ON pv.product_id = p.id
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE {$whereClause}
                ORDER BY {$sortColumn} {$order}, pv.id ASC
                LIMIT {$perPage} OFFSET {$offset}";

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
     * Quick stock adjustment for a variant
     *
     * @param int $variantId
     * @param string $mode 'delta' or 'set'
     * @param int $value Signed delta (e.g. +5 or -2) or new absolute quantity (>= 0)
     * @param string $reason restock, correction, damaged, returned, other
     * @param string|null $note Required if reason is correction or other
     * @param int $actorId
     * @param string $ip
     * @return array [ 'variant_id' => int, 'new_stock' => int, 'change_qty' => int ]
     */
    public static function adjustStock(int $variantId, string $mode, int $value, string $reason, ?string $note, int $actorId, string $ip): array
    {
        $allowedReasons = ['restock', 'correction', 'damaged', 'returned', 'other'];
        if (!in_array($reason, $allowedReasons, true)) {
            throw new InvalidArgumentException("Invalid adjustment reason '{$reason}'. Allowed: " . implode(', ', $allowedReasons));
        }

        $note = trim($note ?? '');
        if (in_array($reason, ['correction', 'other'], true) && $note === '') {
            throw new InvalidArgumentException("A note explaining the adjustment is required when reason is '" . ucfirst($reason) . "'.");
        }

        $pdo = Database::getInstance();
        $pdo->beginTransaction();

        try {
            // Lock variant row to inspect current stock and prevent race conditions
            $lockStmt = $pdo->prepare("SELECT id, product_id, sku, stock_qty FROM product_variants WHERE id = :id FOR UPDATE");
            $lockStmt->execute([':id' => $variantId]);
            $variant = $lockStmt->fetch(PDO::FETCH_ASSOC);

            if (!$variant) {
                throw new DomainException("Variant not found.");
            }

            $currentStock = (int)$variant['stock_qty'];

            if ($mode === 'delta') {
                if ($value < 0) {
                    $abs = abs($value);
                    if ($currentStock < $abs) {
                        throw new DomainException("Only {$currentStock} units in stock. Cannot reduce stock by {$abs}.");
                    }

                    $updateStmt = $pdo->prepare("UPDATE product_variants 
                                                 SET stock_qty = stock_qty - :abs1 
                                                 WHERE id = :id AND stock_qty >= :abs2");
                    $updateStmt->execute([
                        ':abs1' => $abs,
                        ':abs2' => $abs,
                        ':id'   => $variantId,
                    ]);

                    if ($updateStmt->rowCount() === 0) {
                        $currStmt = $pdo->prepare("SELECT stock_qty FROM product_variants WHERE id = :id");
                        $currStmt->execute([':id' => $variantId]);
                        $liveStock = (int)$currStmt->fetchColumn();
                        throw new DomainException("Only {$liveStock} units in stock. Cannot reduce stock by {$abs}.");
                    }

                    $delta = $value;
                    $newStock = $currentStock - $abs;
                } elseif ($value > 0) {
                    $updateStmt = $pdo->prepare("UPDATE product_variants 
                                                 SET stock_qty = stock_qty + :add_qty 
                                                 WHERE id = :id");
                    $updateStmt->execute([
                        ':add_qty' => $value,
                        ':id'      => $variantId,
                    ]);

                    $delta = $value;
                    $newStock = $currentStock + $value;
                } else {
                    throw new InvalidArgumentException("Adjustment quantity cannot be zero.");
                }
            } elseif ($mode === 'set') {
                if ($value < 0) {
                    throw new InvalidArgumentException("Stock quantity cannot be negative.");
                }
                $updateStmt = $pdo->prepare("UPDATE product_variants 
                                             SET stock_qty = :new_stock 
                                             WHERE id = :id");
                $updateStmt->execute([
                    ':new_stock' => $value,
                    ':id'        => $variantId,
                ]);

                $delta = $value - $currentStock;
                $newStock = $value;
            } else {
                throw new InvalidArgumentException("Invalid adjustment mode '{$mode}'. Use 'delta' or 'set'.");
            }

            // Map UI reason to database ENUM
            $dbReason = ($reason === 'restock') ? 'restock' : 'admin_adjustment';
            $logNote = "[Reason: " . ucfirst($reason) . "] " . $note;

            // Record inventory movement
            $movStmt = $pdo->prepare("INSERT INTO inventory_movements 
                (variant_id, change_qty, reason, actor_user_id, note)
                VALUES (:vid, :qty, :reason, :actor, :note)");
            $movStmt->execute([
                ':vid'    => $variantId,
                ':qty'    => $delta,
                ':reason' => $dbReason,
                ':actor'  => $actorId,
                ':note'   => $logNote,
            ]);

            AuditLogger::log($actorId, 'adjust_stock', 'inventory', $variantId, [
                'sku'          => $variant['sku'],
                'mode'         => $mode,
                'delta'        => $delta,
                'before_stock' => $currentStock,
                'after_stock'  => $newStock,
                'reason'       => $reason,
                'note'         => $note,
            ], $ip);

            $pdo->commit();

            return [
                'variant_id' => $variantId,
                'new_stock'  => $newStock,
                'change_qty' => $delta,
            ];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Paginate inventory movements log
     */
    public static function paginateMovements(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $pdo = Database::getInstance();
        $page = max(1, $page);
        $perPage = max(5, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = ["1=1"];
        $params = [];

        if (!empty($filters['variant_id'])) {
            $where[] = "im.variant_id = :vid";
            $params[':vid'] = (int)$filters['variant_id'];
        }

        if (!empty($filters['product_id'])) {
            $where[] = "pv.product_id = :pid";
            $params[':pid'] = (int)$filters['product_id'];
        }

        if (!empty($filters['reason'])) {
            $where[] = "im.reason = :reason";
            $params[':reason'] = $filters['reason'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = "im.created_at >= :d_from";
            $params[':d_from'] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where[] = "im.created_at <= :d_to";
            $params[':d_to'] = $filters['date_to'] . ' 23:59:59';
        }

        $whereClause = implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) 
                     FROM inventory_movements im
                     JOIN product_variants pv ON im.variant_id = pv.id
                     JOIN products p ON pv.product_id = p.id
                     WHERE {$whereClause}";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalItems = (int)$countStmt->fetchColumn();

        $sql = "SELECT im.*, 
                       pv.sku, 
                       pv.size, 
                       pv.color, 
                       pv.stock_qty as current_stock,
                       p.id as product_id,
                       p.name as product_name,
                       CONCAT(u.first_name, ' ', u.last_name) as actor_name,
                       u.email as actor_email
                FROM inventory_movements im
                JOIN product_variants pv ON im.variant_id = pv.id
                JOIN products p ON pv.product_id = p.id
                LEFT JOIN users u ON im.actor_user_id = u.id
                WHERE {$whereClause}
                ORDER BY im.created_at DESC, im.id DESC
                LIMIT {$perPage} OFFSET {$offset}";

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
}
