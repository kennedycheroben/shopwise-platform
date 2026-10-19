<?php

namespace App\Services;

use App\Database\Database;
use DomainException;
use InvalidArgumentException;
use PDO;

class ProductImageService
{
    /**
     * Get all images for a product ordered by sort_order ASC, id ASC
     */
    public static function getByProductId(int $productId): array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM product_images WHERE product_id = :product_id ORDER BY sort_order ASC, id ASC");
        $stmt->execute([':product_id' => $productId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find image by ID
     */
    public static function find(int $imageId): ?array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM product_images WHERE id = :id");
        $stmt->execute([':id' => $imageId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Add uploaded images to product
     *
     * @param int $productId
     * @param array $files Uploaded files ($_FILES['images'])
     * @param string|null $color Optional variant color association
     * @param string|null $altText Optional fallback alt text
     * @param int $actorId
     * @param string $ip
     * @return array [ 'saved' => array, 'errors' => array ]
     */
    public static function addImages(int $productId, array $files, ?string $color, ?string $altText, int $actorId, string $ip): array
    {
        $pdo = Database::getInstance();
        $pStmt = $pdo->prepare("SELECT id, name FROM products WHERE id = :id");
        $pStmt->execute([':id' => $productId]);
        $product = $pStmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            throw new DomainException("Product not found.");
        }

        // Verify color exists in product variants if specified
        if (!empty($color)) {
            $cStmt = $pdo->prepare("SELECT 1 FROM product_variants WHERE product_id = :pid AND color = :color LIMIT 1");
            $cStmt->execute([':pid' => $productId, ':color' => $color]);
            if (!$cStmt->fetchColumn()) {
                throw new InvalidArgumentException("Color '{$color}' does not exist in any variant of this product.");
            }
        } else {
            $color = null;
        }

        $uploader = new ImageUploadService();
        $uploadResult = $uploader->uploadMultiple($files);

        $savedImages = [];
        $pdo->beginTransaction();
        try {
            // Check if product already has a primary image
            $primStmt = $pdo->prepare("SELECT COUNT(*) FROM product_images WHERE product_id = :pid AND is_primary = 1");
            $primStmt->execute([':pid' => $productId]);
            $hasPrimary = ((int)$primStmt->fetchColumn()) > 0;

            // Get max sort_order
            $maxSortStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM product_images WHERE product_id = :pid");
            $maxSortStmt->execute([':pid' => $productId]);
            $currentSort = (int)$maxSortStmt->fetchColumn();

            $insertStmt = $pdo->prepare("INSERT INTO product_images 
                (product_id, color, path, alt_text, sort_order, is_primary)
                VALUES (:pid, :color, :path, :alt_text, :sort_order, :is_primary)");

            foreach ($uploadResult['success'] as $idx => $uploaded) {
                $currentSort++;
                $isPrimary = (!$hasPrimary && $idx === 0) ? 1 : 0;
                if ($isPrimary) {
                    $hasPrimary = true;
                }

                $imageAlt = !empty($altText) ? $altText : ($product['name'] . ' - Image ' . $currentSort);

                $insertStmt->execute([
                    ':pid'        => $productId,
                    ':color'      => $color,
                    ':path'       => $uploaded['path'],
                    ':alt_text'   => $imageAlt,
                    ':sort_order' => $currentSort,
                    ':is_primary' => $isPrimary,
                ]);

                $imageId = (int)$pdo->lastInsertId();
                $savedImages[] = [
                    'id'         => $imageId,
                    'path'       => $uploaded['path'],
                    'alt_text'   => $imageAlt,
                    'is_primary' => $isPrimary,
                ];
            }

            if (!empty($savedImages)) {
                AuditLogger::log($actorId, 'upload_images', 'product_images', $productId, [
                    'count'  => count($savedImages),
                    'images' => $savedImages,
                ], $ip);
            }

            $pdo->commit();

            return [
                'saved'  => $savedImages,
                'errors' => $uploadResult['errors'],
            ];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Set an image as primary (exactly one primary per product)
     */
    public static function setPrimary(int $productId, int $imageId, int $actorId, string $ip): bool
    {
        $image = self::find($imageId);
        if (!$image || (int)$image['product_id'] !== $productId) {
            throw new DomainException("Image does not belong to this product.");
        }

        $pdo = Database::getInstance();
        $pdo->beginTransaction();
        try {
            // Set all images of this product to not primary
            $unsetStmt = $pdo->prepare("UPDATE product_images SET is_primary = 0 WHERE product_id = :pid");
            $unsetStmt->execute([':pid' => $productId]);

            // Set target image to primary
            $setStmt = $pdo->prepare("UPDATE product_images SET is_primary = 1 WHERE id = :id AND product_id = :pid");
            $setStmt->execute([':id' => $imageId, ':pid' => $productId]);

            AuditLogger::log($actorId, 'set_primary', 'product_images', $imageId, [
                'product_id' => $productId,
                'path'       => $image['path'],
            ], $ip);

            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Reorder images via an array of image IDs in new order
     */
    public static function reorder(int $productId, array $orderedIds, int $actorId, string $ip): bool
    {
        $pdo = Database::getInstance();
        $pdo->beginTransaction();
        try {
            $updateStmt = $pdo->prepare("UPDATE product_images SET sort_order = :sort WHERE id = :id AND product_id = :pid");
            foreach ($orderedIds as $index => $id) {
                $updateStmt->execute([
                    ':sort' => $index + 1,
                    ':id'   => (int)$id,
                    ':pid'  => $productId,
                ]);
            }

            AuditLogger::log($actorId, 'reorder', 'product_images', $productId, [
                'ordered_ids' => $orderedIds,
            ], $ip);

            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Update image metadata (alt_text, color)
     */
    public static function update(int $productId, int $imageId, array $data, int $actorId, string $ip): bool
    {
        $image = self::find($imageId);
        if (!$image || (int)$image['product_id'] !== $productId) {
            throw new DomainException("Image does not belong to this product.");
        }

        $pdo = Database::getInstance();

        $altText = trim($data['alt_text'] ?? $image['alt_text']);
        if ($altText === '') {
            $altText = 'Product Image';
        }

        $color = array_key_exists('color', $data) ? trim($data['color'] ?? '') : $image['color'];
        if ($color !== '') {
            // Verify color exists in product variants
            $cStmt = $pdo->prepare("SELECT 1 FROM product_variants WHERE product_id = :pid AND color = :color LIMIT 1");
            $cStmt->execute([':pid' => $productId, ':color' => $color]);
            if (!$cStmt->fetchColumn()) {
                throw new InvalidArgumentException("Color '{$color}' does not exist in any variant of this product.");
            }
        } else {
            $color = null;
        }

        $stmt = $pdo->prepare("UPDATE product_images SET alt_text = :alt, color = :color WHERE id = :id AND product_id = :pid");
        $stmt->execute([
            ':alt'   => $altText,
            ':color' => $color,
            ':id'    => $imageId,
            ':pid'   => $productId,
        ]);

        AuditLogger::log($actorId, 'update', 'product_images', $imageId, [
            'product_id' => $productId,
            'alt_text'   => $altText,
            'color'      => $color,
        ], $ip);

        return true;
    }

    /**
     * Delete image and promote next image if primary was deleted
     */
    public static function delete(int $productId, int $imageId, int $actorId, string $ip): bool
    {
        $image = self::find($imageId);
        if (!$image || (int)$image['product_id'] !== $productId) {
            throw new DomainException("Image does not belong to this product.");
        }

        $pdo = Database::getInstance();
        $pdo->beginTransaction();
        try {
            $wasPrimary = (int)$image['is_primary'] === 1;

            // Delete record
            $delStmt = $pdo->prepare("DELETE FROM product_images WHERE id = :id AND product_id = :pid");
            $delStmt->execute([':id' => $imageId, ':pid' => $productId]);

            // If the primary image was deleted, promote the next image automatically
            if ($wasPrimary) {
                $nextStmt = $pdo->prepare("SELECT id FROM product_images WHERE product_id = :pid ORDER BY sort_order ASC, id ASC LIMIT 1");
                $nextStmt->execute([':pid' => $productId]);
                $nextId = $nextStmt->fetchColumn();
                if ($nextId) {
                    $promStmt = $pdo->prepare("UPDATE product_images SET is_primary = 1 WHERE id = :id");
                    $promStmt->execute([':id' => $nextId]);
                }
            }

            // Remove files from disk if stored in uploads/
            if (str_starts_with($image['path'], 'uploads/')) {
                $mainPath = dirname(__DIR__, 3) . '/public/' . $image['path'];
                if (file_exists($mainPath)) {
                    @unlink($mainPath);
                }
                // Also check thumbnail path
                $thumbPath = preg_replace('/(\.[a-zA-Z0-9]+)$/', '_thumb$1', $mainPath);
                if (file_exists($thumbPath)) {
                    @unlink($thumbPath);
                }
            }

            AuditLogger::log($actorId, 'delete', 'product_images', $imageId, [
                'product_id'  => $productId,
                'path'        => $image['path'],
                'was_primary' => $wasPrimary,
            ], $ip);

            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
