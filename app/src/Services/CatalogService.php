<?php

namespace App\Services;

use App\Database\Database;
use PDO;

/**
 * Storefront Catalog Service
 * Handles product browsing, search, category navigation, filtering,
 * batch-loading without N+1 queries, and stock masking.
 */
class CatalogService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
    }

    /**
     * Get all active category IDs that have an active ancestor chain
     *
     * @return array<int, array> keyed by category ID
     */
    public function getActiveCategoriesMap(): array
    {
        $stmt = $this->pdo->query("SELECT id, name, slug, parent_id, sort_order, is_active FROM categories ORDER BY sort_order ASC, name ASC");
        $all = [];
        while ($row = $stmt->fetch()) {
            $all[(int)$row['id']] = $row;
        }

        $activeMap = [];
        foreach ($all as $id => $cat) {
            if ($this->isCategoryChainActive($id, $all)) {
                $activeMap[$id] = $cat;
            }
        }

        return $activeMap;
    }

    /**
     * Verify that a category and all its ancestors are active
     */
    private function isCategoryChainActive(int $categoryId, array $allCategories): bool
    {
        $curr = $allCategories[$categoryId] ?? null;
        if (!$curr || empty($curr['is_active'])) {
            return false;
        }

        $parentId = $curr['parent_id'] !== null ? (int)$curr['parent_id'] : null;
        while ($parentId !== null) {
            $parent = $allCategories[$parentId] ?? null;
            if (!$parent || empty($parent['is_active'])) {
                return false;
            }
            $parentId = $parent['parent_id'] !== null ? (int)$parent['parent_id'] : null;
        }

        return true;
    }

    /**
     * Get category hierarchy for mega menu and sidebar navigation
     */
    public function getCategoryTree(): array
    {
        $activeMap = $this->getActiveCategoriesMap();
        $tree = [];
        $children = [];

        foreach ($activeMap as $id => $cat) {
            $parentId = $cat['parent_id'] !== null ? (int)$cat['parent_id'] : null;
            if ($parentId === null || !isset($activeMap[$parentId])) {
                $tree[$id] = $cat;
                $tree[$id]['children'] = [];
            } else {
                $children[$parentId][] = $cat;
            }
        }

        foreach ($children as $parentId => $subCats) {
            if (isset($tree[$parentId])) {
                $tree[$parentId]['children'] = $subCats;
            }
        }

        return array_values($tree);
    }

    /**
     * Get Category by slug if active
     */
    public function getCategoryBySlug(string $slug): ?array
    {
        $activeMap = $this->getActiveCategoriesMap();
        foreach ($activeMap as $cat) {
            if ($cat['slug'] === $slug) {
                return $cat;
            }
        }
        return null;
    }

    /**
     * Get all active descendant category IDs for a given category
     */
    public function getDescendantCategoryIds(int $categoryId): array
    {
        $activeMap = $this->getActiveCategoriesMap();
        if (!isset($activeMap[$categoryId])) {
            return [];
        }

        $ids = [$categoryId];
        $added = true;

        while ($added) {
            $added = false;
            foreach ($activeMap as $id => $cat) {
                if ($cat['parent_id'] !== null && in_array((int)$cat['parent_id'], $ids, true) && !in_array($id, $ids, true)) {
                    $ids[] = $id;
                    $added = true;
                }
            }
        }

        return $ids;
    }

    /**
     * Fetch products with filtering, sorting, pagination, and batch loading
     *
     * @param array $filters [category_id, search, min_price, max_price, size, color, in_stock]
     * @param string $sort [newest, featured, price_asc, price_desc, name]
     * @param int $page 1-based page number
     * @param int $perPage Products per page (default 12)
     * @return array [products => array, total => int, page => int, per_page => int, total_pages => int]
     */
    public function getProducts(array $filters = [], string $sort = 'newest', int $page = 1, int $perPage = 12): array
    {
        $activeCategoryMap = $this->getActiveCategoriesMap();
        $activeCategoryIds = array_keys($activeCategoryMap);

        if (empty($activeCategoryIds)) {
            return [
                'products'    => [],
                'total'       => 0,
                'page'        => 1,
                'per_page'    => $perPage,
                'total_pages' => 1,
            ];
        }

        $where = [
            "p.is_active = 1",
            "p.deleted_at IS NULL",
            "EXISTS (SELECT 1 FROM product_variants pv WHERE pv.product_id = p.id AND pv.is_active = 1)",
        ];
        $params = [];

        // Category filter
        if (!empty($filters['category_id'])) {
            $catId = (int)$filters['category_id'];
            $targetCategoryIds = array_intersect($this->getDescendantCategoryIds($catId), $activeCategoryIds);
            if (empty($targetCategoryIds)) {
                return [
                    'products'    => [],
                    'total'       => 0,
                    'page'        => 1,
                    'per_page'    => $perPage,
                    'total_pages' => 1,
                ];
            }
            $catPlaceholders = implode(',', array_fill(0, count($targetCategoryIds), '?'));
            $where[] = "p.category_id IN ({$catPlaceholders})";
            foreach ($targetCategoryIds as $cid) {
                $params[] = $cid;
            }
        } else {
            $catPlaceholders = implode(',', array_fill(0, count($activeCategoryIds), '?'));
            $where[] = "p.category_id IN ({$catPlaceholders})";
            foreach ($activeCategoryIds as $cid) {
                $params[] = $cid;
            }
        }

        // Search filter (min 2 chars, wildcards escaped)
        $searchOrderSql = null;
        if (!empty($filters['search']) && mb_strlen(trim((string)$filters['search'])) >= 2) {
            $rawTerm = trim((string)$filters['search']);
            $escapedTerm = addcslashes($rawTerm, '%_\\');
            $likeWildcard = '%' . $escapedTerm . '%';
            $likePrefix = $escapedTerm . '%';

            $where[] = "(p.name LIKE ? OR p.short_description LIKE ? OR c.name LIKE ? OR EXISTS (SELECT 1 FROM product_variants pv_s WHERE pv_s.product_id = p.id AND pv_s.is_active = 1 AND pv_s.sku LIKE ?))";
            $params[] = $likeWildcard;
            $params[] = $likeWildcard;
            $params[] = $likeWildcard;
            $params[] = $likeWildcard;

            // Search rank: exact/prefix match on product name first
            $searchOrderSql = "CASE WHEN p.name = " . $this->pdo->quote($rawTerm) . " THEN 1 WHEN p.name LIKE " . $this->pdo->quote($likePrefix) . " THEN 2 ELSE 3 END, ";
        }

        // Price filters (effective variant price: COALESCE(price_override, base_price))
        if (isset($filters['min_price']) && is_numeric($filters['min_price'])) {
            $minPrice = (float)$filters['min_price'];
            $where[] = "EXISTS (SELECT 1 FROM product_variants pv_min WHERE pv_min.product_id = p.id AND pv_min.is_active = 1 AND COALESCE(pv_min.price_override, p.base_price) >= ?)";
            $params[] = $minPrice;
        }

        if (isset($filters['max_price']) && is_numeric($filters['max_price'])) {
            $maxPrice = (float)$filters['max_price'];
            $where[] = "EXISTS (SELECT 1 FROM product_variants pv_max WHERE pv_max.product_id = p.id AND pv_max.is_active = 1 AND COALESCE(pv_max.price_override, p.base_price) <= ?)";
            $params[] = $maxPrice;
        }

        // Size filter
        if (!empty($filters['size'])) {
            $where[] = "EXISTS (SELECT 1 FROM product_variants pv_sz WHERE pv_sz.product_id = p.id AND pv_sz.is_active = 1 AND LOWER(pv_sz.size) = LOWER(?))";
            $params[] = trim((string)$filters['size']);
        }

        // Color filter
        if (!empty($filters['color'])) {
            $where[] = "EXISTS (SELECT 1 FROM product_variants pv_cl WHERE pv_cl.product_id = p.id AND pv_cl.is_active = 1 AND LOWER(pv_cl.color) = LOWER(?))";
            $params[] = trim((string)$filters['color']);
        }

        // In-stock only filter
        if (!empty($filters['in_stock'])) {
            $where[] = "EXISTS (SELECT 1 FROM product_variants pv_stk WHERE pv_stk.product_id = p.id AND pv_stk.is_active = 1 AND pv_stk.stock_qty > 0)";
        }

        $whereClause = implode(' AND ', $where);

        // 1. Total items count
        $countSql = "SELECT COUNT(DISTINCT p.id) FROM products p JOIN categories c ON c.id = p.category_id WHERE {$whereClause}";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalItems = (int)$countStmt->fetchColumn();

        if ($totalItems === 0) {
            return [
                'products'    => [],
                'total'       => 0,
                'page'        => 1,
                'per_page'    => $perPage,
                'total_pages' => 1,
            ];
        }

        // Pagination bounds checking
        $perPage = max(1, min($perPage, 100));
        $totalPages = (int)ceil($totalItems / $perPage);
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;

        // Sorting
        $allowedSorts = [
            'newest'     => 'p.created_at DESC, p.id DESC',
            'featured'   => 'p.is_featured DESC, p.created_at DESC, p.id DESC',
            'price_asc'  => 'effective_min_price ASC, p.id DESC',
            'price_desc' => 'effective_min_price DESC, p.id DESC',
            'name'       => 'p.name ASC, p.id DESC',
        ];

        $sortClause = $allowedSorts[$sort] ?? $allowedSorts['newest'];
        if ($searchOrderSql !== null) {
            $sortClause = $searchOrderSql . $sortClause;
        }

        // 2. Fetch products page
        $sql = "SELECT p.*, c.name AS category_name, c.slug AS category_slug,
                       (SELECT MIN(COALESCE(pv_p.price_override, p.base_price)) FROM product_variants pv_p WHERE pv_p.product_id = p.id AND pv_p.is_active = 1) AS effective_min_price
                FROM products p
                JOIN categories c ON c.id = p.category_id
                WHERE {$whereClause}
                ORDER BY {$sortClause}
                LIMIT {$perPage} OFFSET {$offset}";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll();

        // 3. Batch load images and variants (0 N+1)
        $hydratedProducts = $this->hydrateProductsWithImagesAndVariants($products);

        return [
            'products'    => $hydratedProducts,
            'total'       => $totalItems,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * Batch load images and variants for a collection of products (prevents N+1)
     */
    public function hydrateProductsWithImagesAndVariants(array $products): array
    {
        if (empty($products)) {
            return [];
        }

        $productIds = array_map(fn($p) => (int)$p['id'], $products);
        $idPlaceholders = implode(',', array_fill(0, count($productIds), '?'));

        // Batch 1: Images
        $imgStmt = $this->pdo->prepare(
            "SELECT * FROM product_images WHERE product_id IN ({$idPlaceholders}) ORDER BY is_primary DESC, sort_order ASC, id ASC"
        );
        $imgStmt->execute($productIds);
        $imagesByProduct = [];
        while ($img = $imgStmt->fetch()) {
            $imagesByProduct[(int)$img['product_id']][] = $img;
        }

        // Batch 2: Active Variants
        $varStmt = $this->pdo->prepare(
            "SELECT * FROM product_variants WHERE product_id IN ({$idPlaceholders}) AND is_active = 1 ORDER BY id ASC"
        );
        $varStmt->execute($productIds);
        $variantsByProduct = [];
        while ($variant = $varStmt->fetch()) {
            $variantsByProduct[(int)$variant['product_id']][] = $variant;
        }

        // Compute pricing, availability, and badges per product
        foreach ($products as &$p) {
            $pid = (int)$p['id'];
            $pImages = $imagesByProduct[$pid] ?? [];
            $pVariants = $variantsByProduct[$pid] ?? [];

            $p['images'] = $pImages;
            $p['variants'] = $pVariants;

            // Primary image path
            $primaryImg = null;
            foreach ($pImages as $img) {
                if (!empty($img['is_primary'])) {
                    $primaryImg = $img['path'];
                    break;
                }
            }
            if ($primaryImg === null && !empty($pImages[0])) {
                $primaryImg = $pImages[0]['path'];
            }
            $p['primary_image'] = $primaryImg ?? 'assets/img/product/product-1.webp';

            // Variant pricing & stock summary
            $minPrice = null;
            $maxPrice = null;
            $totalStock = 0;
            $hasLowStock = false;
            $allSoldOut = true;

            foreach ($pVariants as $v) {
                $effPrice = $v['price_override'] !== null ? (float)$v['price_override'] : (float)$p['base_price'];
                if ($minPrice === null || $effPrice < $minPrice) {
                    $minPrice = $effPrice;
                }
                if ($maxPrice === null || $effPrice > $maxPrice) {
                    $maxPrice = $effPrice;
                }

                $qty = (int)$v['stock_qty'];
                $threshold = (int)$v['low_stock_threshold'];
                $totalStock += $qty;

                if ($qty > 0) {
                    $allSoldOut = false;
                    if ($qty <= $threshold) {
                        $hasLowStock = true;
                    }
                }
            }

            if ($minPrice === null) {
                $minPrice = (float)$p['base_price'];
                $maxPrice = (float)$p['base_price'];
            }

            $p['min_price'] = $minPrice;
            $p['max_price'] = $maxPrice;
            $p['is_price_range'] = ($maxPrice > $minPrice);
            $p['total_stock'] = $totalStock;

            // Badges
            $badges = [];
            $compareAt = $p['compare_at_price'] !== null ? (float)$p['compare_at_price'] : null;

            if ($allSoldOut || empty($pVariants)) {
                $badges[] = ['type' => 'sold_out', 'label' => 'Sold Out'];
            } else {
                if ($compareAt !== null && $compareAt > $minPrice) {
                    $discountPct = round((($compareAt - $minPrice) / $compareAt) * 100);
                    $badges[] = ['type' => 'sale', 'label' => "Sale -{$discountPct}%"];
                }
                if ($hasLowStock) {
                    $badges[] = ['type' => 'low_stock', 'label' => 'Low Stock'];
                }
            }

            $p['badges'] = $badges;
            $p['is_sold_out'] = $allSoldOut || empty($pVariants);
        }
        unset($p);

        return $products;
    }

    /**
     * Get Product detail data by slug with stock masking and approved reviews
     */
    public function getProductBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.*, c.name AS category_name, c.slug AS category_slug, c.id AS category_id
             FROM products p
             JOIN categories c ON c.id = p.category_id
             WHERE p.slug = ? AND p.is_active = 1 AND p.deleted_at IS NULL
             LIMIT 1"
        );
        $stmt->execute([$slug]);
        $product = $stmt->fetch();

        if (!$product) {
            return null;
        }

        // Verify active category chain
        $activeCategoryMap = $this->getActiveCategoriesMap();
        if (!isset($activeCategoryMap[(int)$product['category_id']])) {
            return null;
        }

        $pid = (int)$product['id'];

        // Images
        $imgStmt = $this->pdo->prepare(
            "SELECT * FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, sort_order ASC, id ASC"
        );
        $imgStmt->execute([$pid]);
        $images = $imgStmt->fetchAll();

        // Active Variants
        $varStmt = $this->pdo->prepare(
            "SELECT * FROM product_variants WHERE product_id = ? AND is_active = 1 ORDER BY id ASC"
        );
        $varStmt->execute([$pid]);
        $variants = $varStmt->fetchAll();

        if (empty($variants)) {
            return null; // Product has no active variants, considered not visible
        }

        // Stock Masking & Variant Options
        $maskedVariants = [];
        $sizes = [];
        $colors = [];
        $allSoldOut = true;

        foreach ($variants as $v) {
            $stock = (int)$v['stock_qty'];
            $threshold = (int)$v['low_stock_threshold'];
            $effPrice = $v['price_override'] !== null ? (float)$v['price_override'] : (float)$product['base_price'];

            if ($stock <= 0) {
                $status = 'out';
                $stockMsg = 'Sold out';
                $maxPurchasable = 0;
                $stockDisplay = 0;
            } elseif ($stock <= $threshold) {
                $status = 'low';
                $stockMsg = "Only {$stock} left in stock - order soon";
                $maxPurchasable = min($stock, 10);
                $stockDisplay = $stock;
                $allSoldOut = false;
            } else {
                $status = 'in_stock';
                $stockMsg = 'In stock';
                $maxPurchasable = min($stock, 10);
                $stockDisplay = null; // Do NOT expose exact count above threshold
                $allSoldOut = false;
            }

            $maskedVariants[] = [
                'id'                  => (int)$v['id'],
                'sku'                 => $v['sku'],
                'size'                => $v['size'],
                'color'               => $v['color'],
                'color_hex'           => $v['color_hex'],
                'price_override'      => $v['price_override'],
                'effective_price'     => $effPrice,
                'status'              => $status,
                'stock_message'       => $stockMsg,
                'max_purchasable'     => $maxPurchasable,
                'stock_display'       => $stockDisplay,
            ];

            if (!empty($v['size']) && !in_array($v['size'], $sizes, true)) {
                $sizes[] = $v['size'];
            }
            if (!empty($v['color'])) {
                $colors[$v['color']] = $v['color_hex'] ?? '#000000';
            }
        }

        $product['images'] = $images;
        $product['variants'] = $maskedVariants;
        $product['sizes'] = $sizes;
        $product['colors'] = $colors;
        $product['is_sold_out'] = $allSoldOut;

        // Primary image
        $primary = null;
        foreach ($images as $img) {
            if (!empty($img['is_primary'])) {
                $primary = $img['path'];
                break;
            }
        }
        $product['primary_image'] = $primary ?? ($images[0]['path'] ?? 'assets/img/product/product-1.webp');

        // Approved Reviews
        $revStmt = $this->pdo->prepare(
            "SELECT r.id, r.rating, r.title, r.body, r.created_at, u.first_name, u.last_name
             FROM reviews r
             JOIN users u ON u.id = r.user_id
             WHERE r.product_id = ? AND r.status = 'approved'
             ORDER BY r.created_at DESC"
        );
        $revStmt->execute([$pid]);
        $reviews = $revStmt->fetchAll();

        $reviewCount = count($reviews);
        $avgRating = 0.0;
        $ratingDistribution = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];

        if ($reviewCount > 0) {
            $totalStars = 0;
            foreach ($reviews as $rev) {
                $star = (int)$rev['rating'];
                $totalStars += $star;
                if (isset($ratingDistribution[$star])) {
                    $ratingDistribution[$star]++;
                }
            }
            $avgRating = round($totalStars / $reviewCount, 1);
        }

        $product['reviews'] = $reviews;
        $product['review_summary'] = [
            'count'        => $reviewCount,
            'average'      => $avgRating,
            'distribution' => $ratingDistribution,
        ];

        return $product;
    }

    /**
     * Get Related Products (same category, visible, excluding current)
     */
    public function getRelatedProducts(int $productId, int $categoryId, int $limit = 4): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.*, c.name AS category_name, c.slug AS category_slug
             FROM products p
             JOIN categories c ON c.id = p.category_id
             WHERE p.category_id = ? AND p.id != ? AND p.is_active = 1 AND p.deleted_at IS NULL
               AND EXISTS (SELECT 1 FROM product_variants pv WHERE pv.product_id = p.id AND pv.is_active = 1)
             ORDER BY p.id DESC
             LIMIT {$limit}"
        );
        $stmt->execute([$categoryId, $productId]);
        $products = $stmt->fetchAll();

        return $this->hydrateProductsWithImagesAndVariants($products);
    }

    /**
     * Get Featured Products
     */
    public function getFeaturedProducts(int $limit = 8): array
    {
        $activeMap = $this->getActiveCategoriesMap();
        if (empty($activeMap)) {
            return [];
        }
        $catIds = implode(',', array_keys($activeMap));

        $stmt = $this->pdo->query(
            "SELECT p.*, c.name AS category_name, c.slug AS category_slug
             FROM products p
             JOIN categories c ON c.id = p.category_id
             WHERE p.is_featured = 1 AND p.is_active = 1 AND p.deleted_at IS NULL
               AND p.category_id IN ({$catIds})
               AND EXISTS (SELECT 1 FROM product_variants pv WHERE pv.product_id = p.id AND pv.is_active = 1)
             ORDER BY p.created_at DESC
             LIMIT {$limit}"
        );
        $products = $stmt->fetchAll();

        return $this->hydrateProductsWithImagesAndVariants($products);
    }

    /**
     * Get New Arrivals
     */
    public function getNewArrivals(int $limit = 8): array
    {
        $activeMap = $this->getActiveCategoriesMap();
        if (empty($activeMap)) {
            return [];
        }
        $catIds = implode(',', array_keys($activeMap));

        $stmt = $this->pdo->query(
            "SELECT p.*, c.name AS category_name, c.slug AS category_slug
             FROM products p
             JOIN categories c ON c.id = p.category_id
             WHERE p.is_active = 1 AND p.deleted_at IS NULL
               AND p.category_id IN ({$catIds})
               AND EXISTS (SELECT 1 FROM product_variants pv WHERE pv.product_id = p.id AND pv.is_active = 1)
             ORDER BY p.created_at DESC
             LIMIT {$limit}"
        );
        $products = $stmt->fetchAll();

        return $this->hydrateProductsWithImagesAndVariants($products);
    }

    /**
     * Get Deals (products with compare_at_price > base_price)
     */
    public function getDeals(int $limit = 8): array
    {
        $activeMap = $this->getActiveCategoriesMap();
        if (empty($activeMap)) {
            return [];
        }
        $catIds = implode(',', array_keys($activeMap));

        $stmt = $this->pdo->query(
            "SELECT p.*, c.name AS category_name, c.slug AS category_slug
             FROM products p
             JOIN categories c ON c.id = p.category_id
             WHERE p.is_active = 1 AND p.deleted_at IS NULL
               AND p.compare_at_price IS NOT NULL AND p.compare_at_price > p.base_price
               AND p.category_id IN ({$catIds})
               AND EXISTS (SELECT 1 FROM product_variants pv WHERE pv.product_id = p.id AND pv.is_active = 1)
             ORDER BY (p.compare_at_price - p.base_price) DESC
             LIMIT {$limit}"
        );
        $products = $stmt->fetchAll();

        return $this->hydrateProductsWithImagesAndVariants($products);
    }

    /**
     * Get Approved Testimonials from real database reviews
     */
    public function getApprovedTestimonials(int $limit = 6): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.id, r.rating, r.title, r.body, r.created_at,
                    p.name AS product_name, p.slug AS product_slug,
                    u.first_name, u.last_name
             FROM reviews r
             JOIN products p ON p.id = r.product_id
             JOIN users u ON u.id = r.user_id
             WHERE r.status = 'approved' AND r.rating >= 4 AND p.is_active = 1 AND p.deleted_at IS NULL
             ORDER BY r.created_at DESC
             LIMIT {$limit}"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get available filter facets (sizes, colors, price min/max) for catalog
     */
    public function getFilterFacets(?int $categoryId = null): array
    {
        $activeMap = $this->getActiveCategoriesMap();
        if (empty($activeMap)) {
            return ['sizes' => [], 'colors' => [], 'min_price' => 0, 'max_price' => 10000];
        }

        $catIds = !empty($categoryId)
            ? array_intersect($this->getDescendantCategoryIds($categoryId), array_keys($activeMap))
            : array_keys($activeMap);

        if (empty($catIds)) {
            return ['sizes' => [], 'colors' => [], 'min_price' => 0, 'max_price' => 10000];
        }

        $idPlaceholders = implode(',', array_fill(0, count($catIds), '?'));

        $sql = "SELECT pv.size, pv.color, pv.color_hex,
                       COALESCE(pv.price_override, p.base_price) AS effective_price
                FROM product_variants pv
                JOIN products p ON p.id = pv.product_id
                WHERE p.is_active = 1 AND p.deleted_at IS NULL AND pv.is_active = 1
                  AND p.category_id IN ({$idPlaceholders})";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($catIds);

        $sizes = [];
        $colors = [];
        $minPrice = null;
        $maxPrice = null;

        while ($row = $stmt->fetch()) {
            if (!empty($row['size']) && !in_array($row['size'], $sizes, true)) {
                $sizes[] = $row['size'];
            }
            if (!empty($row['color']) && !isset($colors[$row['color']])) {
                $colors[$row['color']] = $row['color_hex'] ?? '#000000';
            }
            $p = (float)$row['effective_price'];
            if ($minPrice === null || $p < $minPrice) {
                $minPrice = $p;
            }
            if ($maxPrice === null || $p > $maxPrice) {
                $maxPrice = $p;
            }
        }

        return [
            'sizes'     => $sizes,
            'colors'    => $colors,
            'min_price' => $minPrice !== null ? floor($minPrice) : 0,
            'max_price' => $maxPrice !== null ? ceil($maxPrice) : 10000,
        ];
    }
}
