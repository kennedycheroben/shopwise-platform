<?php
namespace App\Services;

use App\Database\Database;
use DomainException;
use InvalidArgumentException;
use PDO;

final class CouponService
{
    /**
     * Validate coupon code format: A-Z 0-9 and hyphen, 4 to 20 characters.
     */
    public static function isValidCodeFormat(string $code): bool
    {
        return (bool)preg_match('/^[A-Z0-9-]{4,20}$/', strtoupper(trim($code)));
    }

    /**
     * Compute dynamic status for a coupon row.
     */
    public static function computeStatus(array $coupon): string
    {
        if (empty($coupon['is_active'])) {
            return 'inactive';
        }
        $now = date('Y-m-d H:i:s');
        if (!empty($coupon['starts_at']) && $coupon['starts_at'] > $now) {
            return 'scheduled';
        }
        if (!empty($coupon['expires_at']) && $coupon['expires_at'] < $now) {
            return 'expired';
        }
        if ($coupon['usage_limit'] !== null && (int)$coupon['used_count'] >= (int)$coupon['usage_limit']) {
            return 'exhausted';
        }
        return 'active';
    }

    /**
     * List all coupons with filters and calculated metadata.
     */
    public static function list(array $filters = []): array
    {
        $pdo = Database::getInstance();
        $sql = "SELECT c.*, 
                (SELECT COUNT(*) FROM coupon_redemptions r WHERE r.coupon_id = c.id) as redemption_count
                FROM coupons c WHERE 1=1";
        $params = [];

        if (!empty($filters['search'])) {
            $sql .= " AND c.code LIKE :search";
            $params[':search'] = '%' . trim($filters['search']) . '%';
        }

        if (isset($filters['type']) && in_array($filters['type'], ['percent', 'fixed'], true)) {
            $sql .= " AND c.type = :type";
            $params[':type'] = $filters['type'];
        }

        $sql .= " ORDER BY c.created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $coupons = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($coupons as &$c) {
            $c['status_computed'] = self::computeStatus($c);
            if ($c['usage_limit'] !== null) {
                $c['remaining_uses'] = max(0, (int)$c['usage_limit'] - (int)$c['used_count']);
            } else {
                $c['remaining_uses'] = null; // Unlimited
            }
        }
        unset($c);

        if (!empty($filters['status'])) {
            $coupons = array_filter($coupons, fn($c) => $c['status_computed'] === $filters['status']);
        }

        return array_values($coupons);
    }

    /**
     * Find coupon by ID.
     */
    public static function get(int $id): ?array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM coupons WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $coupon = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$coupon) {
            return null;
        }
        $coupon['status_computed'] = self::computeStatus($coupon);
        $coupon['remaining_uses'] = $coupon['usage_limit'] !== null ? max(0, (int)$coupon['usage_limit'] - (int)$coupon['used_count']) : null;
        return $coupon;
    }

    /**
     * Find coupon by code.
     */
    public static function getByCode(string $code): ?array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM coupons WHERE code = :code LIMIT 1");
        $stmt->execute([':code' => strtoupper(trim($code))]);
        $coupon = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$coupon) {
            return null;
        }
        $coupon['status_computed'] = self::computeStatus($coupon);
        return $coupon;
    }

    /**
     * Create a coupon with validation.
     */
    public static function create(array $data, int $actorId, string $ip): int
    {
        $code = strtoupper(trim($data['code'] ?? ''));
        if (!self::isValidCodeFormat($code)) {
            throw new InvalidArgumentException("Coupon code must be 4 to 20 uppercase alphanumeric characters or hyphens.");
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT id FROM coupons WHERE code = :code LIMIT 1");
        $stmt->execute([':code' => $code]);
        if ($stmt->fetch()) {
            throw new InvalidArgumentException("A coupon with code '{$code}' already exists.");
        }

        $type = trim($data['type'] ?? '');
        if (!in_array($type, ['percent', 'fixed'], true)) {
            throw new InvalidArgumentException("Coupon type must be either 'percent' or 'fixed'.");
        }

        $value = (float)($data['value'] ?? 0);
        if ($type === 'percent') {
            if ($value < 1 || $value > 100) {
                throw new InvalidArgumentException("Percentage discount must be between 1% and 100%.");
            }
        } else {
            if ($value <= 0) {
                throw new InvalidArgumentException("Fixed discount amount must be greater than zero.");
            }
        }

        $minSpend = max(0.00, (float)($data['min_spend'] ?? 0));
        $usageLimit = isset($data['usage_limit']) && $data['usage_limit'] !== '' ? (int)$data['usage_limit'] : null;
        if ($usageLimit !== null && $usageLimit < 1) {
            throw new InvalidArgumentException("Usage limit must be at least 1 or left blank for unlimited.");
        }

        $startsAt = !empty($data['starts_at']) ? date('Y-m-d H:i:s', strtotime($data['starts_at'])) : null;
        $expiresAt = !empty($data['expires_at']) ? date('Y-m-d H:i:s', strtotime($data['expires_at'])) : null;

        if ($startsAt && $expiresAt && $expiresAt <= $startsAt) {
            throw new InvalidArgumentException("Expiry date must be after the start date.");
        }

        $isActive = !empty($data['is_active']) ? 1 : 0;

        $stmt = $pdo->prepare("
            INSERT INTO coupons (code, type, value, min_spend, usage_limit, starts_at, expires_at, is_active, created_at)
            VALUES (:code, :type, :value, :min_spend, :usage_limit, :starts_at, :expires_at, :is_active, NOW())
        ");
        $stmt->execute([
            ':code'        => $code,
            ':type'        => $type,
            ':value'       => $value,
            ':min_spend'   => $minSpend,
            ':usage_limit' => $usageLimit,
            ':starts_at'   => $startsAt,
            ':expires_at'  => $expiresAt,
            ':is_active'   => $isActive,
        ]);

        $id = (int)$pdo->lastInsertId();

        AuditLogger::log($actorId, 'coupon_create', 'coupon', $id, [
            'code'        => $code,
            'type'        => $type,
            'value'       => $value,
            'min_spend'   => $minSpend,
            'usage_limit' => $usageLimit,
        ], $ip);

        return $id;
    }

    /**
     * Update an existing coupon.
     */
    public static function update(int $id, array $data, int $actorId, string $ip): void
    {
        $coupon = self::get($id);
        if (!$coupon) {
            throw new DomainException("Coupon not found.");
        }

        $code = strtoupper(trim($data['code'] ?? $coupon['code']));
        if (!self::isValidCodeFormat($code)) {
            throw new InvalidArgumentException("Coupon code must be 4 to 20 uppercase alphanumeric characters or hyphens.");
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT id FROM coupons WHERE code = :code AND id != :id LIMIT 1");
        $stmt->execute([':code' => $code, ':id' => $id]);
        if ($stmt->fetch()) {
            throw new InvalidArgumentException("Another coupon with code '{$code}' already exists.");
        }

        $type = trim($data['type'] ?? $coupon['type']);
        if (!in_array($type, ['percent', 'fixed'], true)) {
            throw new InvalidArgumentException("Coupon type must be 'percent' or 'fixed'.");
        }

        $value = (float)($data['value'] ?? $coupon['value']);
        if ($type === 'percent') {
            if ($value < 1 || $value > 100) {
                throw new InvalidArgumentException("Percentage discount must be between 1% and 100%.");
            }
        } else {
            if ($value <= 0) {
                throw new InvalidArgumentException("Fixed discount amount must be greater than zero.");
            }
        }

        $minSpend = max(0.00, (float)($data['min_spend'] ?? $coupon['min_spend']));
        $usageLimit = isset($data['usage_limit']) && $data['usage_limit'] !== '' ? (int)$data['usage_limit'] : null;
        if ($usageLimit !== null && $usageLimit < 1) {
            throw new InvalidArgumentException("Usage limit must be at least 1 or blank.");
        }

        $startsAt = !empty($data['starts_at']) ? date('Y-m-d H:i:s', strtotime($data['starts_at'])) : null;
        $expiresAt = !empty($data['expires_at']) ? date('Y-m-d H:i:s', strtotime($data['expires_at'])) : null;

        if ($startsAt && $expiresAt && $expiresAt <= $startsAt) {
            throw new InvalidArgumentException("Expiry date must be after the start date.");
        }

        $isActive = isset($data['is_active']) ? (!empty($data['is_active']) ? 1 : 0) : (int)$coupon['is_active'];

        $stmt = $pdo->prepare("
            UPDATE coupons 
            SET code = :code, type = :type, value = :value, min_spend = :min_spend,
                usage_limit = :usage_limit, starts_at = :starts_at, expires_at = :expires_at,
                is_active = :is_active
            WHERE id = :id
        ");
        $stmt->execute([
            ':code'        => $code,
            ':type'        => $type,
            ':value'       => $value,
            ':min_spend'   => $minSpend,
            ':usage_limit' => $usageLimit,
            ':starts_at'   => $startsAt,
            ':expires_at'  => $expiresAt,
            ':is_active'   => $isActive,
            ':id'          => $id,
        ]);

        AuditLogger::log($actorId, 'coupon_update', 'coupon', $id, [
            'code'  => $code,
            'type'  => $type,
            'value' => $value,
        ], $ip);
    }

    /**
     * Toggle coupon active state.
     */
    public static function toggleActive(int $id, int $actorId, string $ip): bool
    {
        $coupon = self::get($id);
        if (!$coupon) {
            throw new DomainException("Coupon not found.");
        }

        $newActive = $coupon['is_active'] ? 0 : 1;
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("UPDATE coupons SET is_active = :active WHERE id = :id");
        $stmt->execute([':active' => $newActive, ':id' => $id]);

        AuditLogger::log($actorId, 'coupon_toggle_active', 'coupon', $id, [
            'code'      => $coupon['code'],
            'is_active' => $newActive,
        ], $ip);

        return (bool)$newActive;
    }

    /**
     * Delete coupon (forbidden if any redemptions exist).
     */
    public static function delete(int $id, int $actorId, string $ip): void
    {
        $coupon = self::get($id);
        if (!$coupon) {
            throw new DomainException("Coupon not found.");
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id = :id");
        $stmt->execute([':id' => $id]);
        $redemptions = (int)$stmt->fetchColumn();

        if ($redemptions > 0) {
            throw new DomainException("Cannot delete coupon '{$coupon['code']}' because it has {$redemptions} recorded redemption(s). Deactivate the coupon instead.");
        }

        $stmt = $pdo->prepare("DELETE FROM coupons WHERE id = :id");
        $stmt->execute([':id' => $id]);

        AuditLogger::log($actorId, 'coupon_delete', 'coupon', $id, [
            'code' => $coupon['code'],
        ], $ip);
    }

    /**
     * Evaluate coupon for a given order subtotal.
     * Returns ['valid' => true, 'discount' => float, 'coupon' => array] or ['valid' => false, 'reason' => string, 'message' => string]
     */
    public static function evaluate(string $code, float $subtotal, ?int $userId = null): array
    {
        if ($subtotal < 0) {
            return [
                'valid'   => false,
                'reason'  => 'invalid_subtotal',
                'message' => 'Order subtotal cannot be negative.',
            ];
        }

        $coupon = self::getByCode($code);
        if (!$coupon) {
            return [
                'valid'   => false,
                'reason'  => 'unknown',
                'message' => 'Coupon code not found.',
            ];
        }

        if (empty($coupon['is_active'])) {
            return [
                'valid'   => false,
                'reason'  => 'inactive',
                'message' => 'This coupon is currently inactive.',
            ];
        }

        $now = date('Y-m-d H:i:s');
        if (!empty($coupon['starts_at']) && $coupon['starts_at'] > $now) {
            return [
                'valid'   => false,
                'reason'  => 'not_started',
                'message' => 'This coupon promotion has not started yet.',
            ];
        }

        if (!empty($coupon['expires_at']) && $coupon['expires_at'] < $now) {
            return [
                'valid'   => false,
                'reason'  => 'expired',
                'message' => 'This coupon has expired.',
            ];
        }

        if ($coupon['usage_limit'] !== null && (int)$coupon['used_count'] >= (int)$coupon['usage_limit']) {
            return [
                'valid'   => false,
                'reason'  => 'used_up',
                'message' => 'This coupon has reached its maximum usage limit.',
            ];
        }

        if ($subtotal < (float)$coupon['min_spend']) {
            return [
                'valid'   => false,
                'reason'  => 'below_min_spend',
                'message' => 'Minimum order spend of KES ' . number_format((float)$coupon['min_spend'], 2) . ' required to use this coupon.',
            ];
        }

        // Calculate discount
        if ($coupon['type'] === 'percent') {
            $rawDiscount = ($subtotal * (float)$coupon['value']) / 100.0;
            $discount = round($rawDiscount, 2);
        } else {
            // Fixed discount never exceeds subtotal
            $discount = min((float)$coupon['value'], $subtotal);
        }

        // Clamp discount between 0.00 and subtotal (never negative, never exceeds subtotal)
        $discount = max(0.00, min($discount, $subtotal));

        return [
            'valid'    => true,
            'discount' => $discount,
            'coupon'   => $coupon,
        ];
    }

    /**
     * Atomically redeem a coupon and record in coupon_redemptions.
     * Can participate in caller's active PDO transaction.
     */
    public static function redeem(int $couponId, int $orderId, ?int $userId, float $discountAmount, ?PDO $pdo = null): void
    {
        $db = $pdo ?? Database::getInstance();
        $ownTx = false;
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $ownTx = true;
        }

        try {
            // Atomic increment with capacity check
            $stmt = $db->prepare("
                UPDATE coupons 
                SET used_count = used_count + 1 
                WHERE id = :id AND is_active = 1 AND (usage_limit IS NULL OR used_count < usage_limit)
            ");
            $stmt->execute([':id' => $couponId]);

            if ($stmt->rowCount() === 0) {
                throw new DomainException("Coupon is exhausted, inactive, or could not be redeemed.");
            }

            // Record redemption
            $rStmt = $db->prepare("
                INSERT INTO coupon_redemptions (coupon_id, order_id, user_id, discount_amount, redeemed_at)
                VALUES (:cid, :oid, :uid, :discount, NOW())
            ");
            $rStmt->execute([
                ':cid'      => $couponId,
                ':oid'      => $orderId,
                ':uid'      => $userId,
                ':discount' => $discountAmount,
            ]);

            if ($ownTx) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}
