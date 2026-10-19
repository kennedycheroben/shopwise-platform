<?php

namespace App\Services;

use App\Database\Database;
use DomainException;
use InvalidArgumentException;
use PDO;

class VariantService
{
    /**
     * Get all variants for a product
     */
    public static function getByProductId(int $productId): array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM product_variants WHERE product_id = :product_id ORDER BY id ASC");
        $stmt->execute([':product_id' => $productId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find variant by ID
     */
    public static function find(int $variantId): ?array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM product_variants WHERE id = :id");
        $stmt->execute([':id' => $variantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Find variant by SKU
     */
    public static function findBySku(string $sku, ?int $ignoreId = null): ?array
    {
        $pdo = Database::getInstance();
        $sql = "SELECT * FROM product_variants WHERE sku = :sku";
        $params = [':sku' => $sku];
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
     * Validate SKU uniqueness, color hex format, and non-negative numbers
     */
    public static function validateVariantData(int $productId, array $data, ?int $variantId = null): array
    {
        $errors = [];

        $sku = strtoupper(trim($data['sku'] ?? ''));
        if ($sku === '') {
            $errors['sku'] = 'SKU is required.';
        } elseif (self::findBySku($sku, $variantId)) {
            $errors['sku'] = "SKU '{$sku}' is already in use by another variant.";
        }

        $size = trim($data['size'] ?? '');
        $size = $size === '' ? null : $size;

        $color = trim($data['color'] ?? '');
        $color = $color === '' ? null : $color;

        $colorHex = trim($data['color_hex'] ?? '');
        if ($colorHex !== '') {
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $colorHex)) {
                $errors['color_hex'] = 'Color HEX must be a valid 6-digit hex code (e.g. #FF5722).';
            }
        } else {
            $colorHex = null;
        }

        // Check (product_id, size, color) uniqueness
        $pdo = Database::getInstance();
        $checkSql = "SELECT id FROM product_variants WHERE product_id = :product_id";
        $checkParams = [':product_id' => $productId];

        if ($size === null) {
            $checkSql .= " AND size IS NULL";
        } else {
            $checkSql .= " AND size = :size";
            $checkParams[':size'] = $size;
        }

        if ($color === null) {
            $checkSql .= " AND color IS NULL";
        } else {
            $checkSql .= " AND color = :color";
            $checkParams[':color'] = $color;
        }

        if ($variantId !== null) {
            $checkSql .= " AND id <> :id";
            $checkParams[':id'] = $variantId;
        }

        $stmt = $pdo->prepare($checkSql);
        $stmt->execute($checkParams);
        if ($stmt->fetchColumn()) {
            $comb = ($size ?? 'Default') . ' / ' . ($color ?? 'Default');
            $errors['combination'] = "A variant with size/color '{$comb}' already exists for this product.";
        }

        $priceOverride = null;
        if (isset($data['price_override']) && trim((string)$data['price_override']) !== '') {
            $poVal = filter_var($data['price_override'], FILTER_VALIDATE_FLOAT);
            if ($poVal === false || $poVal < 0) {
                $errors['price_override'] = 'Price override must be a positive decimal number or zero.';
            } else {
                $priceOverride = round($poVal, 2);
            }
        }

        $stockQty = 0;
        if (isset($data['stock_qty'])) {
            $sqVal = filter_var($data['stock_qty'], FILTER_VALIDATE_INT);
            if ($sqVal === false || $sqVal < 0) {
                $errors['stock_qty'] = 'Stock quantity must be a non-negative whole number.';
            } else {
                $stockQty = $sqVal;
            }
        }

        $threshold = 5;
        if (isset($data['low_stock_threshold'])) {
            $thVal = filter_var($data['low_stock_threshold'], FILTER_VALIDATE_INT);
            if ($thVal === false || $thVal < 0) {
                $errors['low_stock_threshold'] = 'Low stock threshold must be a non-negative whole number.';
            } else {
                $threshold = $thVal;
            }
        }

        $isActive = !empty($data['is_active']) ? 1 : 0;

        return [
            'errors' => $errors,
            'clean'  => [
                'sku'                 => $sku,
                'size'                => $size,
                'color'               => $color,
                'color_hex'           => $colorHex,
                'price_override'      => $priceOverride,
                'stock_qty'           => $stockQty,
                'low_stock_threshold' => $threshold,
                'is_active'           => $isActive,
            ]
        ];
    }

    /**
     * Add a single variant
     */
    public static function create(int $productId, array $data, int $actorId, string $ip): int
    {
        $validation = self::validateVariantData($productId, $data);
        if (!empty($validation['errors'])) {
            throw new InvalidArgumentException(implode(' ', $validation['errors']));
        }

        $clean = $validation['clean'];
        $pdo = Database::getInstance();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare("INSERT INTO product_variants 
                (product_id, sku, size, color, color_hex, price_override, stock_qty, low_stock_threshold, is_active)
                VALUES 
                (:product_id, :sku, :size, :color, :color_hex, :price_override, :stock_qty, :low_stock_threshold, :is_active)");
            $stmt->execute([
                ':product_id'          => $productId,
                ':sku'                 => $clean['sku'],
                ':size'                => $clean['size'],
                ':color'               => $clean['color'],
                ':color_hex'           => $clean['color_hex'],
                ':price_override'      => $clean['price_override'],
                ':stock_qty'           => $clean['stock_qty'],
                ':low_stock_threshold' => $clean['low_stock_threshold'],
                ':is_active'           => $clean['is_active'],
            ]);

            $variantId = (int)$pdo->lastInsertId();

            // Record initial inventory movement
            $mStmt = $pdo->prepare("INSERT INTO inventory_movements 
                (variant_id, change_qty, reason, actor_user_id, note)
                VALUES (:vid, :qty, 'initial', :actor, 'Initial variant creation')");
            $mStmt->execute([
                ':vid'   => $variantId,
                ':qty'   => $clean['stock_qty'],
                ':actor' => $actorId,
            ]);

            AuditLogger::log($actorId, 'create', 'variant', $variantId, [
                'product_id' => $productId,
                'sku'        => $clean['sku'],
                'size'       => $clean['size'],
                'color'      => $clean['color'],
                'stock'      => $clean['stock_qty'],
            ], $ip);

            $pdo->commit();
            return $variantId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Update an existing variant
     */
    public static function update(int $variantId, array $data, int $actorId, string $ip, ?int $expectedProductId = null): bool
    {
        $variant = self::find($variantId);
        if (!$variant) {
            throw new DomainException("Variant not found.");
        }

        if ($expectedProductId !== null && (int)$variant['product_id'] !== $expectedProductId) {
            throw new DomainException("Variant does not belong to the specified product.");
        }

        $productId = (int)$variant['product_id'];
        $validation = self::validateVariantData($productId, $data, $variantId);
        if (!empty($validation['errors'])) {
            throw new InvalidArgumentException(implode(' ', $validation['errors']));
        }

        $clean = $validation['clean'];
        $pdo = Database::getInstance();

        // Rule: Product must keep at least one active variant
        if ($variant['is_active'] == 1 && $clean['is_active'] == 0) {
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM product_variants WHERE product_id = :pid AND is_active = 1 AND id <> :vid");
            $countStmt->execute([':pid' => $productId, ':vid' => $variantId]);
            if ((int)$countStmt->fetchColumn() === 0) {
                throw new DomainException("Cannot deactivate variant: Every product must keep at least one active variant.");
            }
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE product_variants SET
                sku = :sku,
                size = :size,
                color = :color,
                color_hex = :color_hex,
                price_override = :price_override,
                stock_qty = :stock_qty,
                low_stock_threshold = :low_stock_threshold,
                is_active = :is_active
                WHERE id = :id");
            $stmt->execute([
                ':sku'                 => $clean['sku'],
                ':size'                => $clean['size'],
                ':color'               => $clean['color'],
                ':color_hex'           => $clean['color_hex'],
                ':price_override'      => $clean['price_override'],
                ':stock_qty'           => $clean['stock_qty'],
                ':low_stock_threshold' => $clean['low_stock_threshold'],
                ':is_active'           => $clean['is_active'],
                ':id'                  => $variantId,
            ]);

            // If stock changed, record inventory movement
            $oldStock = (int)$variant['stock_qty'];
            $newStock = (int)$clean['stock_qty'];
            if ($oldStock !== $newStock) {
                $delta = $newStock - $oldStock;
                $mStmt = $pdo->prepare("INSERT INTO inventory_movements 
                    (variant_id, change_qty, reason, actor_user_id, note)
                    VALUES (:vid, :qty, 'admin_adjustment', :actor, 'Stock updated via variant manager')");
                $mStmt->execute([
                    ':vid'   => $variantId,
                    ':qty'   => $delta,
                    ':actor' => $actorId,
                ]);
            }

            AuditLogger::log($actorId, 'update', 'variant', $variantId, [
                'before' => [
                    'sku'   => $variant['sku'],
                    'size'  => $variant['size'],
                    'color' => $variant['color'],
                    'stock' => $variant['stock_qty'],
                    'active'=> $variant['is_active'],
                ],
                'after'  => [
                    'sku'   => $clean['sku'],
                    'size'  => $clean['size'],
                    'color' => $clean['color'],
                    'stock' => $clean['stock_qty'],
                    'active'=> $clean['is_active'],
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
     * Delete variant with safety checks
     */
    public static function delete(int $variantId, int $actorId, string $ip, ?int $expectedProductId = null): bool
    {
        $variant = self::find($variantId);
        if (!$variant) {
            throw new DomainException("Variant not found.");
        }

        if ($expectedProductId !== null && (int)$variant['product_id'] !== $expectedProductId) {
            throw new DomainException("Variant does not belong to the specified product.");
        }

        $productId = (int)$variant['product_id'];
        $pdo = Database::getInstance();

        // Rule 1: A variant referenced in order_items can only be deactivated, never deleted
        $orderCheck = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE variant_id = :vid");
        $orderCheck->execute([':vid' => $variantId]);
        if ((int)$orderCheck->fetchColumn() > 0) {
            throw new DomainException("Variant '{$variant['sku']}' cannot be deleted because it is linked to past customer orders. You may deactivate it instead.");
        }

        // Rule 2: Every product must always keep at least one active variant
        $activeCheck = $pdo->prepare("SELECT COUNT(*) FROM product_variants WHERE product_id = :pid AND is_active = 1 AND id <> :vid");
        $activeCheck->execute([':pid' => $productId, ':vid' => $variantId]);
        if ((int)$activeCheck->fetchColumn() === 0) {
            throw new DomainException("Cannot delete variant: Every product must keep at least one active variant.");
        }

        $pdo->beginTransaction();
        try {
            // Remove associated inventory movements or let cascade handle it
            $delMov = $pdo->prepare("DELETE FROM inventory_movements WHERE variant_id = :vid");
            $delMov->execute([':vid' => $variantId]);

            $delStmt = $pdo->prepare("DELETE FROM product_variants WHERE id = :id");
            $delStmt->execute([':id' => $variantId]);

            AuditLogger::log($actorId, 'delete', 'variant', $variantId, [
                'product_id' => $productId,
                'sku'        => $variant['sku'],
                'size'       => $variant['size'],
                'color'      => $variant['color'],
            ], $ip);

            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Generate variants from matrix of sizes and colors (idempotent)
     */
    public static function generateVariants(int $productId, array $sizes, array $colors, int $actorId, string $ip): array
    {
        $pdo = Database::getInstance();
        $pStmt = $pdo->prepare("SELECT slug, name FROM products WHERE id = :id");
        $pStmt->execute([':id' => $productId]);
        $product = $pStmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            throw new DomainException("Product not found.");
        }

        $productBaseSku = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $product['slug']));
        if ($productBaseSku === '') {
            $productBaseSku = 'PROD' . $productId;
        }

        // Clean lists
        $cleanedSizes = array_values(array_unique(array_filter(array_map('trim', $sizes))));
        if (empty($cleanedSizes)) {
            $cleanedSizes = [null];
        }

        $cleanedColors = [];
        foreach ($colors as $c) {
            if (is_array($c)) {
                $name = trim($c['name'] ?? '');
                $hex = trim($c['hex'] ?? '');
            } else {
                $name = trim((string)$c);
                $hex = '';
            }
            if ($name !== '') {
                $cleanedColors[] = [
                    'name' => $name,
                    'hex'  => preg_match('/^#[0-9a-fA-F]{6}$/', $hex) ? $hex : null,
                ];
            }
        }
        if (empty($cleanedColors)) {
            $cleanedColors = [['name' => null, 'hex' => null]];
        }

        $created = [];
        $pdo->beginTransaction();
        try {
            foreach ($cleanedSizes as $sz) {
                foreach ($cleanedColors as $cl) {
                    $colorName = $cl['name'];
                    $colorHex = $cl['hex'];

                    // Check if combination already exists (idempotent)
                    $chkSql = "SELECT id FROM product_variants WHERE product_id = :pid";
                    $chkParams = [':pid' => $productId];
                    if ($sz === null) {
                        $chkSql .= " AND size IS NULL";
                    } else {
                        $chkSql .= " AND size = :size";
                        $chkParams[':size'] = $sz;
                    }
                    if ($colorName === null) {
                        $chkSql .= " AND color IS NULL";
                    } else {
                        $chkSql .= " AND color = :color";
                        $chkParams[':color'] = $colorName;
                    }

                    $chkStmt = $pdo->prepare($chkSql);
                    $chkStmt->execute($chkParams);
                    if ($chkStmt->fetchColumn()) {
                        // Already exists, skip
                        continue;
                    }

                    // Build deterministic unique SKU
                    $skuParts = [$productBaseSku];
                    if ($sz !== null) {
                        $skuParts[] = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $sz));
                    }
                    if ($colorName !== null) {
                        $skuParts[] = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $colorName));
                    }
                    $candidateSku = implode('-', array_filter($skuParts));
                    
                    // Deduplicate SKU across system
                    $skuSuffix = 1;
                    $finalSku = $candidateSku;
                    while (self::findBySku($finalSku)) {
                        $skuSuffix++;
                        $finalSku = $candidateSku . '-' . $skuSuffix;
                    }

                    $insStmt = $pdo->prepare("INSERT INTO product_variants 
                        (product_id, sku, size, color, color_hex, stock_qty, low_stock_threshold, is_active)
                        VALUES 
                        (:pid, :sku, :size, :color, :color_hex, 0, 5, 1)");
                    $insStmt->execute([
                        ':pid'       => $productId,
                        ':sku'       => $finalSku,
                        ':size'      => $sz,
                        ':color'     => $colorName,
                        ':color_hex' => $colorHex,
                    ]);

                    $varId = (int)$pdo->lastInsertId();

                    // Initial movement with 0 stock
                    $movStmt = $pdo->prepare("INSERT INTO inventory_movements 
                        (variant_id, change_qty, reason, actor_user_id, note)
                        VALUES (:vid, 0, 'initial', :actor, 'Generated variant')");
                    $movStmt->execute([
                        ':vid'   => $varId,
                        ':actor' => $actorId,
                    ]);

                    $created[] = [
                        'id'    => $varId,
                        'sku'   => $finalSku,
                        'size'  => $sz,
                        'color' => $colorName,
                    ];
                }
            }

            if (!empty($created)) {
                AuditLogger::log($actorId, 'generate', 'variant', $productId, [
                    'generated_count' => count($created),
                    'variants'        => $created,
                ], $ip);
            }

            $pdo->commit();
            return $created;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
