<?php

namespace App\Services;

use App\Database\Database;
use DomainException;
use InvalidArgumentException;
use PDO;

class CategoryService
{
    /**
     * Get all categories flattened with product counts and depth
     */
    public static function getAll(): array
    {
        $pdo = Database::getInstance();
        $sql = "SELECT c.*, 
                       (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.deleted_at IS NULL) as product_count,
                       parent.name as parent_name
                FROM categories c
                LEFT JOIN categories parent ON c.parent_id = parent.id
                ORDER BY c.sort_order ASC, c.name ASC";
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get categories structured as a tree
     */
    public static function getTree(): array
    {
        $all = self::getAll();
        $byParent = [];
        foreach ($all as $cat) {
            $parentId = $cat['parent_id'] ?? 0;
            $byParent[$parentId][] = $cat;
        }

        $buildTree = function ($parentId) use (&$buildTree, &$byParent) {
            $branch = [];
            if (!empty($byParent[$parentId])) {
                foreach ($byParent[$parentId] as $cat) {
                    $cat['children'] = $buildTree($cat['id']);
                    $branch[] = $cat;
                }
            }
            return $branch;
        };

        return $buildTree(0);
    }

    /**
     * Find category by ID
     */
    public static function find(int $id): ?array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Find category by Slug
     */
    public static function findBySlug(string $slug, ?int $ignoreId = null): ?array
    {
        $pdo = Database::getInstance();
        $sql = "SELECT * FROM categories WHERE slug = :slug";
        $params = [':slug' => $slug];
        if ($ignoreId !== null) {
            $sql .= " AND id <> :id";
            $params[':id'] = $ignoreId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Get all descendant category IDs for a given category (to prevent cycle selection)
     */
    public static function getDescendantIds(int $categoryId): array
    {
        $pdo = Database::getInstance();
        $all = $pdo->query("SELECT id, parent_id FROM categories")->fetchAll(PDO::FETCH_ASSOC);

        $descendants = [];
        $findChildren = function ($parentId) use (&$findChildren, &$all, &$descendants) {
            foreach ($all as $item) {
                if ((int)($item['parent_id'] ?? 0) === $parentId) {
                    $descendants[] = (int)$item['id'];
                    $findChildren((int)$item['id']);
                }
            }
        };

        $findChildren($categoryId);
        return $descendants;
    }

    /**
     * Create category with automatic system-generated unique slug.
     * Custom slugs in $data are ignored to guarantee SEO consistency and prevent tampering.
     */
    public static function create(array $data, int $actorId, string $ip): int
    {
        $name = trim($data['name'] ?? '');
        if ($name === '') {
            throw new InvalidArgumentException("Category name is required.");
        }

        $baseSlug = slugify($name);
        if ($baseSlug === '' || ($baseSlug === 'item' && preg_match('/[a-z0-9]/i', $name) === 0)) {
            throw new InvalidArgumentException("Category name must contain alphanumeric characters to generate a valid URL slug.");
        }

        $parentId = !empty($data['parent_id']) ? (int)$data['parent_id'] : null;
        if ($parentId !== null) {
            $parent = self::find($parentId);
            if (!$parent) {
                throw new InvalidArgumentException("Selected parent category does not exist.");
            }
        }

        $description = trim($data['description'] ?? '');
        $image = !empty($data['image']) ? trim($data['image']) : null;
        $sortOrder = (int)($data['sort_order'] ?? 0);
        $isActive = isset($data['is_active']) ? (!empty($data['is_active']) ? 1 : 0) : 1;

        $pdo = Database::getInstance();
        $maxAttempts = 5;
        $id = null;
        $finalSlug = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $slug = unique_slug('categories', $name);
            try {
                $stmt = $pdo->prepare("INSERT INTO categories (parent_id, name, slug, description, image, sort_order, is_active)
                                       VALUES (:parent_id, :name, :slug, :description, :image, :sort_order, :is_active)");
                $stmt->execute([
                    ':parent_id'   => $parentId,
                    ':name'        => $name,
                    ':slug'        => $slug,
                    ':description' => $description !== '' ? $description : null,
                    ':image'       => $image,
                    ':sort_order'  => $sortOrder,
                    ':is_active'   => $isActive,
                ]);

                $id = (int)$pdo->lastInsertId();
                $finalSlug = $slug;
                break;
            } catch (\PDOException $e) {
                $isDuplicate = ($e->getCode() === '23000' || ($e->errorInfo[1] ?? 0) === 1062)
                    && (str_contains($e->getMessage(), 'uq_categories_slug') || str_contains($e->getMessage(), 'slug'));
                if ($isDuplicate) {
                    if ($attempt === $maxAttempts) {
                        throw new \RuntimeException("Unable to generate a unique category slug after {$maxAttempts} concurrent attempts. Please try again.", 0, $e);
                    }
                    usleep(random_int(10000, 40000));
                    continue;
                }
                throw $e;
            }
        }

        AuditLogger::log($actorId, 'create', 'category', $id, [
            'name'      => $name,
            'slug'      => $finalSlug,
            'parent_id' => $parentId,
            'is_active' => $isActive,
        ], $ip);

        return $id;
    }

    /**
     * Update category attributes.
     * Category slugs are permanently immutable: name, description, parent, sort order,
     * and status modifications do not alter the existing slug. Any submitted 'slug' is ignored.
     */
    public static function update(int $id, array $data, int $actorId, string $ip): bool
    {
        $category = self::find($id);
        if (!$category) {
            throw new DomainException("Category not found.");
        }

        $name = trim($data['name'] ?? $category['name']);
        if ($name === '') {
            throw new InvalidArgumentException("Category name cannot be empty.");
        }

        // Slug is strictly immutable during normal category updates
        $slug = $category['slug'];

        $parentId = array_key_exists('parent_id', $data)
            ? (!empty($data['parent_id']) ? (int)$data['parent_id'] : null)
            : $category['parent_id'];

        if ($parentId !== null) {
            if ($parentId === $id) {
                throw new InvalidArgumentException("A category cannot be set as its own parent.");
            }
            $descendants = self::getDescendantIds($id);
            if (in_array($parentId, $descendants, true)) {
                throw new InvalidArgumentException("A category cannot have one of its own descendants as a parent.");
            }
            $parent = self::find($parentId);
            if (!$parent) {
                throw new InvalidArgumentException("Selected parent category does not exist.");
            }
        }

        $description = array_key_exists('description', $data) ? trim($data['description'] ?? '') : $category['description'];
        $image = array_key_exists('image', $data) ? (!empty($data['image']) ? trim($data['image']) : null) : $category['image'];
        $sortOrder = array_key_exists('sort_order', $data) ? (int)$data['sort_order'] : (int)$category['sort_order'];
        $isActive = array_key_exists('is_active', $data) ? (!empty($data['is_active']) ? 1 : 0) : (int)$category['is_active'];

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("UPDATE categories 
                               SET parent_id = :parent_id,
                                   name = :name,
                                   description = :description,
                                   image = :image,
                                   sort_order = :sort_order,
                                   is_active = :is_active
                               WHERE id = :id");
        $stmt->execute([
            ':parent_id'   => $parentId,
            ':name'        => $name,
            ':description' => $description !== '' ? $description : null,
            ':image'       => $image,
            ':sort_order'  => $sortOrder,
            ':is_active'   => $isActive,
            ':id'          => $id,
        ]);

        AuditLogger::log($actorId, 'update', 'category', $id, [
            'before' => [
                'name'      => $category['name'],
                'slug'      => $category['slug'],
                'parent_id' => $category['parent_id'],
                'is_active' => $category['is_active'],
            ],
            'after'  => [
                'name'      => $name,
                'slug'      => $category['slug'],
                'parent_id' => $parentId,
                'is_active' => $isActive,
            ],
        ], $ip);

        return true;
    }

    /**
     * Explicitly authorized, system-managed slug migration.
     * Only to be invoked by authorized administrative maintenance operations.
     */
    public static function migrateSlug(int $id, string $newSlugCandidate, int $actorId, string $ip): string
    {
        $category = self::find($id);
        if (!$category) {
            throw new DomainException("Category not found.");
        }

        $oldSlug = $category['slug'];
        $newSlug = slugify($newSlugCandidate);

        if ($newSlug === '' || ($newSlug === 'item' && preg_match('/[a-z0-9]/i', $newSlugCandidate) === 0)) {
            throw new InvalidArgumentException("New slug must contain alphanumeric characters.");
        }

        if ($newSlug === $oldSlug) {
            return $oldSlug;
        }

        if (self::findBySlug($newSlug, $id)) {
            throw new InvalidArgumentException("Category slug '{$newSlug}' is already in use.");
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("UPDATE categories SET slug = :slug WHERE id = :id");
        $stmt->execute([':slug' => $newSlug, ':id' => $id]);

        // Record historical redirect
        self::recordHistoricalSlug($oldSlug, $newSlug);

        AuditLogger::log($actorId, 'migrate_slug', 'category', $id, [
            'old_slug' => $oldSlug,
            'new_slug' => $newSlug,
        ], $ip);

        return $newSlug;
    }

    /**
     * Record a historical slug redirect, updating chains and avoiding cycles.
     */
    public static function recordHistoricalSlug(string $oldSlug, string $newSlug): void
    {
        if ($oldSlug === '' || $newSlug === '' || $oldSlug === $newSlug) {
            return;
        }

        $raw = setting('category_slug_redirects', '[]');
        $redirects = is_array($raw) ? $raw : (json_decode((string)$raw, true) ?: []);

        // Flatten any existing redirects pointing to $oldSlug to now point directly to $newSlug
        foreach ($redirects as $source => $target) {
            if ($target === $oldSlug) {
                $redirects[$source] = $newSlug;
            }
        }

        // Map $oldSlug to $newSlug
        $redirects[$oldSlug] = $newSlug;

        // Prevent loop if $newSlug had previously pointed somewhere
        unset($redirects[$newSlug]);

        SettingService::set('category_slug_redirects', $redirects, 'json');
    }

    /**
     * Find active category slug for a historical slug (if migrated).
     * Prevents loops and ensures target exists and is active.
     */
    public static function findCurrentSlugForHistorical(string $slug): ?string
    {
        $raw = setting('category_slug_redirects', '[]');
        $redirects = is_array($raw) ? $raw : (json_decode((string)$raw, true) ?: []);

        if (empty($redirects[$slug])) {
            return null;
        }

        $visited = [$slug];
        $current = $redirects[$slug];

        while (isset($redirects[$current])) {
            if (in_array($current, $visited, true)) {
                return null; // Loop detected
            }
            $visited[] = $current;
            $current = $redirects[$current];
        }

        $cat = self::findBySlug($current);
        if ($cat && !empty($cat['is_active'])) {
            return $current;
        }

        return null;
    }

    /**
     * Delete category
     */
    public static function delete(int $id, int $actorId, string $ip): bool
    {
        $category = self::find($id);
        if (!$category) {
            throw new DomainException("Category not found.");
        }

        $pdo = Database::getInstance();

        // Check if category has products
        $prodStmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE category_id = :id AND deleted_at IS NULL");
        $prodStmt->execute([':id' => $id]);
        $productCount = (int)$prodStmt->fetchColumn();
        if ($productCount > 0) {
            throw new DomainException("Cannot delete category '{$category['name']}' because it is assigned to {$productCount} product(s). Please reassign or deactivate the category instead.");
        }

        // Check if category has child categories
        $childStmt = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE parent_id = :id");
        $childStmt->execute([':id' => $id]);
        $childCount = (int)$childStmt->fetchColumn();
        if ($childCount > 0) {
            throw new DomainException("Cannot delete category '{$category['name']}' because it has {$childCount} subcategory/subcategories. Please move or delete subcategories first.");
        }

        $stmt = $pdo->prepare("DELETE FROM categories WHERE id = :id");
        $stmt->execute([':id' => $id]);

        AuditLogger::log($actorId, 'delete', 'category', $id, [
            'name' => $category['name'],
            'slug' => $category['slug'],
        ], $ip);

        return true;
    }
}
