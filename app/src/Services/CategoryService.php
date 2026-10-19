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
     * Create category
     */
    public static function create(array $data, int $actorId, string $ip): int
    {
        $name = trim($data['name'] ?? '');
        if ($name === '') {
            throw new InvalidArgumentException("Category name is required.");
        }

        $slug = trim($data['slug'] ?? '');
        if ($slug === '') {
            $slug = unique_slug('categories', $name);
        } else {
            $slug = slugify($slug);
            if (self::findBySlug($slug)) {
                throw new InvalidArgumentException("Category slug '{$slug}' is already in use.");
            }
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
        $isActive = !empty($data['is_active']) ? 1 : 0;

        $pdo = Database::getInstance();
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

        AuditLogger::log($actorId, 'create', 'category', $id, [
            'name'      => $name,
            'slug'      => $slug,
            'parent_id' => $parentId,
            'is_active' => $isActive,
        ], $ip);

        return $id;
    }

    /**
     * Update category
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

        $slug = trim($data['slug'] ?? $category['slug']);
        if ($slug === '') {
            $slug = unique_slug('categories', $name, $id);
        } else {
            $slug = slugify($slug);
            if (self::findBySlug($slug, $id)) {
                throw new InvalidArgumentException("Category slug '{$slug}' is already in use by another category.");
            }
        }

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
                                   slug = :slug,
                                   description = :description,
                                   image = :image,
                                   sort_order = :sort_order,
                                   is_active = :is_active
                               WHERE id = :id");
        $stmt->execute([
            ':parent_id'   => $parentId,
            ':name'        => $name,
            ':slug'        => $slug,
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
                'slug'      => $slug,
                'parent_id' => $parentId,
                'is_active' => $isActive,
            ],
        ], $ip);

        return true;
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
