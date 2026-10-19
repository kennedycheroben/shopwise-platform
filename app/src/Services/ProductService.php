<?php

namespace App\Services;

use App\Database\Database;
use DomainException;
use InvalidArgumentException;
use PDO;

class ProductService
{
    private static array $reservedSlugs = [
        'admin', 'api', 'login', 'logout', 'cart', 'checkout', 'orders',
        'products', 'categories', 'inventory', 'account', 'register',
        'search', 'assets', 'uploads', 'dashboard', 'settings', 'profile'
    ];

    /**
     * Paginate products with search, filtering, and sorting
     */
    public static function paginate(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $pdo = Database::getInstance();
        $page = max(1, $page);
        $perPage = max(5, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        // Status filter: active, inactive, deleted, or all
        $status = $filters['status'] ?? 'all';
        if ($status === 'deleted') {
            $where[] = "p.deleted_at IS NOT NULL";
        } elseif ($status === 'active') {
            $where[] = "p.deleted_at IS NULL AND p.is_active = 1";
        } elseif ($status === 'inactive') {
            $where[] = "p.deleted_at IS NULL AND p.is_active = 0";
        } else {
            // Default: hide soft deleted unless explicitly requested
            $where[] = "p.deleted_at IS NULL";
        }

        // Search: name, slug, or variant SKU
        if (!empty($filters['search'])) {
            $search = '%' . trim($filters['search']) . '%';
            $where[] = "(p.name LIKE :s1 OR p.slug LIKE :s2 OR EXISTS (
                SELECT 1 FROM product_variants pv_search 
                WHERE pv_search.product_id = p.id AND pv_search.sku LIKE :s3
            ))";
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
                $where[] = "COALESCE((SELECT SUM(pv_stk.stock_qty) FROM product_variants pv_stk WHERE pv_stk.product_id = p.id AND pv_stk.is_active = 1), 0) = 0";
            } elseif ($filters['stock_status'] === 'low_stock') {
                $where[] = "COALESCE((SELECT SUM(pv_stk.stock_qty) FROM product_variants pv_stk WHERE pv_stk.product_id = p.id AND pv_stk.is_active = 1), 0) > 0 
                            AND COALESCE((SELECT SUM(pv_stk.stock_qty) FROM product_variants pv_stk WHERE pv_stk.product_id = p.id AND pv_stk.is_active = 1), 0) <= 5";
            } elseif ($filters['stock_status'] === 'in_stock') {
                $where[] = "COALESCE((SELECT SUM(pv_stk.stock_qty) FROM product_variants pv_stk WHERE pv_stk.product_id = p.id AND pv_stk.is_active = 1), 0) > 5";
            }
        }

        $whereClause = implode(' AND ', $where);

        // Count total items
        $countSql = "SELECT COUNT(*) FROM products p WHERE {$whereClause}";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalItems = (int)$countStmt->fetchColumn();

        // Sort configuration
        $sort = $filters['sort'] ?? 'created';
        $order = strtolower($filters['order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

        $sortExpr = match ($sort) {
            'name'  => 'p.name',
            'price' => 'p.base_price',
            'stock' => 'total_stock',
            default => 'p.created_at',
        };

        $sql = "SELECT p.*,
                       c.name as category_name,
                       COALESCE((
                           SELECT SUM(pv.stock_qty) 
                           FROM product_variants pv 
                           WHERE pv.product_id = p.id AND pv.is_active = 1
                       ), 0) as total_stock,
                       (
                           SELECT MIN(COALESCE(pv.price_override, p.base_price))
                           FROM product_variants pv
                           WHERE pv.product_id = p.id AND pv.is_active = 1
                       ) as min_price,
                       (
                           SELECT MAX(COALESCE(pv.price_override, p.base_price))
                           FROM product_variants pv
                           WHERE pv.product_id = p.id AND pv.is_active = 1
                       ) as max_price,
                       (
                           SELECT pi.path 
                           FROM product_images pi 
                           WHERE pi.product_id = p.id AND pi.is_primary = 1 
                           LIMIT 1
                       ) as primary_image,
                       (
                           SELECT pi.alt_text 
                           FROM product_images pi 
                           WHERE pi.product_id = p.id AND pi.is_primary = 1 
                           LIMIT 1
                       ) as primary_image_alt
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE {$whereClause}
                ORDER BY {$sortExpr} {$order}, p.id DESC
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
     * Find product by ID
     */
    public static function find(int $id, bool $includeDeleted = true): ?array
    {
        $pdo = Database::getInstance();
        $sql = "SELECT p.*, c.name as category_name 
                FROM products p 
                LEFT JOIN categories c ON p.category_id = c.id 
                WHERE p.id = :id";
        if (!$includeDeleted) {
            $sql .= " AND p.deleted_at IS NULL";
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Validate product input fields
     */
    public static function validate(array $data, ?int $productId = null): array
    {
        $errors = [];

        $name = trim($data['name'] ?? '');
        if ($name === '') {
            $errors['name'] = 'Product name is required.';
        } elseif (mb_strlen($name) > 255) {
            $errors['name'] = 'Product name cannot exceed 255 characters.';
        }

        $slug = trim($data['slug'] ?? '');
        if ($slug === '') {
            $slug = unique_slug('products', $name, $productId);
        } else {
            $slug = slugify($slug);
            if (in_array($slug, self::$reservedSlugs, true)) {
                $slug .= '-product';
            }
            $pdo = Database::getInstance();
            $slugSql = "SELECT id FROM products WHERE slug = :slug";
            $slugParams = [':slug' => $slug];
            if ($productId !== null) {
                $slugSql .= " AND id <> :id";
                $slugParams[':id'] = $productId;
            }
            $slugStmt = $pdo->prepare($slugSql);
            $slugStmt->execute($slugParams);
            if ($slugStmt->fetchColumn()) {
                $errors['slug'] = "The URL slug '{$slug}' is already taken by another product.";
            }
        }

        $categoryId = (int)($data['category_id'] ?? 0);
        if ($categoryId <= 0) {
            $errors['category_id'] = 'Please select a valid category.';
        } else {
            $cat = CategoryService::find($categoryId);
            if (!$cat) {
                $errors['category_id'] = 'Selected category does not exist.';
            }
        }

        $shortDesc = trim($data['short_description'] ?? '');
        if ($shortDesc === '') {
            $errors['short_description'] = 'Short description is required for catalog preview.';
        } elseif (mb_strlen($shortDesc) > 500) {
            $errors['short_description'] = 'Short description cannot exceed 500 characters.';
        }

        $rawDesc = $data['description'] ?? '';
        $cleanDesc = HtmlSanitizer::clean($rawDesc);
        if ($cleanDesc === '') {
            $errors['description'] = 'Product long description is required.';
        }

        $basePrice = null;
        if (!isset($data['base_price']) || trim((string)$data['base_price']) === '') {
            $errors['base_price'] = 'Base price (KES) is required.';
        } else {
            $bpVal = filter_var($data['base_price'], FILTER_VALIDATE_FLOAT);
            if ($bpVal === false || $bpVal <= 0) {
                $errors['base_price'] = 'Base price must be a positive number greater than 0.';
            } else {
                $basePrice = round($bpVal, 2);
            }
        }

        $compareAtPrice = null;
        if (isset($data['compare_at_price']) && trim((string)$data['compare_at_price']) !== '') {
            $capVal = filter_var($data['compare_at_price'], FILTER_VALIDATE_FLOAT);
            if ($capVal === false || $capVal <= 0) {
                $errors['compare_at_price'] = 'Compare-at price must be a valid positive number.';
            } elseif ($basePrice !== null && $capVal <= $basePrice) {
                $errors['compare_at_price'] = 'Compare-at price must be strictly greater than the base price.';
            } else {
                $compareAtPrice = round($capVal, 2);
            }
        }

        $metaTitle = trim($data['meta_title'] ?? '');
        $metaDescription = trim($data['meta_description'] ?? '');

        $isActive = !empty($data['is_active']) ? 1 : 0;
        $isFeatured = !empty($data['is_featured']) ? 1 : 0;

        return [
            'errors' => $errors,
            'clean'  => [
                'name'              => $name,
                'slug'              => $slug,
                'category_id'       => $categoryId,
                'short_description' => $shortDesc,
                'description'       => $cleanDesc,
                'base_price'        => $basePrice,
                'compare_at_price'  => $compareAtPrice,
                'meta_title'        => $metaTitle !== '' ? $metaTitle : null,
                'meta_description'  => $metaDescription !== '' ? $metaDescription : null,
                'is_active'         => $isActive,
                'is_featured'       => $isFeatured,
            ]
        ];
    }

    /**
     * Create a new product with automatic default variant
     */
    public static function create(array $data, int $actorId, string $ip): int
    {
        $validation = self::validate($data);
        if (!empty($validation['errors'])) {
            throw new InvalidArgumentException(implode(' ', $validation['errors']));
        }

        $clean = $validation['clean'];
        $pdo = Database::getInstance();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare("INSERT INTO products 
                (category_id, name, slug, short_description, description, base_price, compare_at_price, is_active, is_featured, meta_title, meta_description)
                VALUES 
                (:category_id, :name, :slug, :short_description, :description, :base_price, :compare_at_price, :is_active, :is_featured, :meta_title, :meta_description)");
            $stmt->execute([
                ':category_id'       => $clean['category_id'],
                ':name'              => $clean['name'],
                ':slug'              => $clean['slug'],
                ':short_description' => $clean['short_description'],
                ':description'       => $clean['description'],
                ':base_price'        => $clean['base_price'],
                ':compare_at_price'  => $clean['compare_at_price'],
                ':is_active'         => $clean['is_active'],
                ':is_featured'       => $clean['is_featured'],
                ':meta_title'        => $clean['meta_title'],
                ':meta_description'  => $clean['meta_description'],
            ]);

            $productId = (int)$pdo->lastInsertId();

            // Automatic default variant: size and color NULL
            $baseSku = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $clean['slug']));
            if ($baseSku === '') {
                $baseSku = 'PROD' . $productId;
            }
            $defaultSku = $baseSku . '-DEF';
            $skuSuffix = 1;
            while (VariantService::findBySku($defaultSku)) {
                $skuSuffix++;
                $defaultSku = $baseSku . '-DEF-' . $skuSuffix;
            }

            $vStmt = $pdo->prepare("INSERT INTO product_variants 
                (product_id, sku, size, color, color_hex, price_override, stock_qty, low_stock_threshold, is_active)
                VALUES 
                (:pid, :sku, NULL, NULL, NULL, NULL, 0, 5, 1)");
            $vStmt->execute([
                ':pid' => $productId,
                ':sku' => $defaultSku,
            ]);

            $defaultVariantId = (int)$pdo->lastInsertId();

            // Record initial movement for default variant
            $mStmt = $pdo->prepare("INSERT INTO inventory_movements 
                (variant_id, change_qty, reason, actor_user_id, note)
                VALUES (:vid, 0, 'initial', :actor, 'Initial default variant')");
            $mStmt->execute([
                ':vid'   => $defaultVariantId,
                ':actor' => $actorId,
            ]);

            AuditLogger::log($actorId, 'create', 'product', $productId, [
                'name'        => $clean['name'],
                'slug'        => $clean['slug'],
                'base_price'  => $clean['base_price'],
                'default_sku' => $defaultSku,
            ], $ip);

            $pdo->commit();
            return $productId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Update an existing product
     */
    public static function update(int $id, array $data, int $actorId, string $ip): bool
    {
        $product = self::find($id);
        if (!$product) {
            throw new DomainException("Product not found.");
        }

        $validation = self::validate($data, $id);
        if (!empty($validation['errors'])) {
            throw new InvalidArgumentException(implode(' ', $validation['errors']));
        }

        $clean = $validation['clean'];
        $pdo = Database::getInstance();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare("UPDATE products SET
                category_id = :category_id,
                name = :name,
                slug = :slug,
                short_description = :short_description,
                description = :description,
                base_price = :base_price,
                compare_at_price = :compare_at_price,
                is_active = :is_active,
                is_featured = :is_featured,
                meta_title = :meta_title,
                meta_description = :meta_description
                WHERE id = :id");
            $stmt->execute([
                ':category_id'       => $clean['category_id'],
                ':name'              => $clean['name'],
                ':slug'              => $clean['slug'],
                ':short_description' => $clean['short_description'],
                ':description'       => $clean['description'],
                ':base_price'        => $clean['base_price'],
                ':compare_at_price'  => $clean['compare_at_price'],
                ':is_active'         => $clean['is_active'],
                ':is_featured'       => $clean['is_featured'],
                ':meta_title'        => $clean['meta_title'],
                ':meta_description'  => $clean['meta_description'],
                ':id'                => $id,
            ]);

            AuditLogger::log($actorId, 'update', 'product', $id, [
                'before' => [
                    'name'       => $product['name'],
                    'slug'       => $product['slug'],
                    'base_price' => $product['base_price'],
                    'is_active'  => $product['is_active'],
                ],
                'after'  => [
                    'name'       => $clean['name'],
                    'slug'       => $clean['slug'],
                    'base_price' => $clean['base_price'],
                    'is_active'  => $clean['is_active'],
                ]
            ], $ip);

            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Soft delete product
     */
    public static function softDelete(int $id, int $actorId, string $ip): bool
    {
        $product = self::find($id);
        if (!$product) {
            throw new DomainException("Product not found.");
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("UPDATE products SET deleted_at = NOW(), is_active = 0 WHERE id = :id");
        $stmt->execute([':id' => $id]);

        AuditLogger::log($actorId, 'soft_delete', 'product', $id, [
            'name' => $product['name'],
            'slug' => $product['slug'],
        ], $ip);

        return true;
    }

    /**
     * Restore soft-deleted product
     */
    public static function restore(int $id, int $actorId, string $ip): bool
    {
        $product = self::find($id);
        if (!$product) {
            throw new DomainException("Product not found.");
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("UPDATE products SET deleted_at = NULL, is_active = 1 WHERE id = :id");
        $stmt->execute([':id' => $id]);

        AuditLogger::log($actorId, 'restore', 'product', $id, [
            'name' => $product['name'],
            'slug' => $product['slug'],
        ], $ip);

        return true;
    }

    /**
     * Hard delete product with order items verification
     */
    public static function hardDelete(int $id, int $actorId, string $ip): bool
    {
        $product = self::find($id);
        if (!$product) {
            throw new DomainException("Product not found.");
        }

        $pdo = Database::getInstance();

        // Never hard delete a product that has order_items
        $oStmt = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE product_id = :id");
        $oStmt->execute([':id' => $id]);
        $orderCount = (int)$oStmt->fetchColumn();
        if ($orderCount > 0) {
            throw new DomainException("Product '{$product['name']}' cannot be permanently deleted because it has {$orderCount} order item record(s). Use soft-delete instead.");
        }

        $pdo->beginTransaction();
        try {
            // Delete associated variants movements
            $movStmt = $pdo->prepare("DELETE im FROM inventory_movements im 
                                     JOIN product_variants pv ON im.variant_id = pv.id 
                                     WHERE pv.product_id = :id");
            $movStmt->execute([':id' => $id]);

            // Delete associated product images from disk
            $imgStmt = $pdo->prepare("SELECT path FROM product_images WHERE product_id = :id");
            $imgStmt->execute([':id' => $id]);
            $images = $imgStmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($images as $imgPath) {
                if (str_starts_with($imgPath, 'uploads/')) {
                    $full = dirname(__DIR__, 3) . '/public/' . $imgPath;
                    if (file_exists($full)) @unlink($full);
                    $thumb = preg_replace('/(\.[a-zA-Z0-9]+)$/', '_thumb$1', $full);
                    if (file_exists($thumb)) @unlink($thumb);
                }
            }

            // Delete product (cascades to product_variants and product_images)
            $delStmt = $pdo->prepare("DELETE FROM products WHERE id = :id");
            $delStmt->execute([':id' => $id]);

            AuditLogger::log($actorId, 'hard_delete', 'product', $id, [
                'name' => $product['name'],
                'slug' => $product['slug'],
            ], $ip);

            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
