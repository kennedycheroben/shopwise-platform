<?php

namespace App\Services;

use App\Database\Database;
use PDO;

final class AccountService
{
    public const MAX_ADDRESSES = 10;

    /**
     * Get paginated customer orders
     */
    public static function getOrders(int $userId, int $page = 1, int $perPage = 10): array
    {
        if ($userId <= 0) {
            return ['items' => [], 'total' => 0, 'pages' => 1, 'page' => 1];
        }

        $pdo = Database::getInstance();
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $offset = ($page - 1) * $perPage;

        // Total count
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE user_id = :uid");
        $stmt->execute([':uid' => $userId]);
        $total = (int)$stmt->fetchColumn();

        $pages = max(1, (int)ceil($total / $perPage));

        $stmt = $pdo->prepare("
            SELECT o.*,
                   (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) as item_count
            FROM orders o
            WHERE o.user_id = :uid
            ORDER BY o.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'items'   => $orders,
            'total'   => $total,
            'pages'   => $pages,
            'page'    => $page,
            'perPage' => $perPage,
        ];
    }

    /**
     * Get order details by order ID or order number with strict customer ownership verification (prevents IDOR)
     */
    public static function getOrderDetails(int $userId, string|int $orderIdentifier): ?array
    {
        if ($userId <= 0 || empty($orderIdentifier)) {
            return null;
        }

        $pdo = Database::getInstance();

        // Strict customer ownership check: supports both integer ID or order_number string
        $stmt = $pdo->prepare("
            SELECT * FROM orders
            WHERE (id = :oid OR order_number = :onum) AND user_id = :uid
            LIMIT 1
        ");
        $stmt->execute([
            ':oid'  => is_numeric($orderIdentifier) ? (int)$orderIdentifier : 0,
            ':onum' => (string)$orderIdentifier,
            ':uid'  => $userId,
        ]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            return null;
        }

        $orderId = (int)$order['id'];

        // Fetch order items with product and variant info
        $stmt = $pdo->prepare("
            SELECT oi.*,
                   p.slug as product_slug,
                   (
                       SELECT pi.image_path 
                       FROM product_images pi 
                       WHERE pi.product_id = oi.product_id 
                       ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC 
                       LIMIT 1
                   ) as primary_image
            FROM order_items oi
            LEFT JOIN products p ON p.id = oi.product_id
            WHERE oi.order_id = :oid
            ORDER BY oi.id ASC
        ");
        $stmt->execute([':oid' => $orderId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch status history timeline
        $stmt = $pdo->prepare("
            SELECT * FROM order_status_history
            WHERE order_id = :oid
            ORDER BY created_at ASC, id ASC
        ");
        $stmt->execute([':oid' => $orderId]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch payment transaction if exists
        $stmt = $pdo->prepare("
            SELECT * FROM payments
            WHERE order_id = :oid
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([':oid' => $orderId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'order'   => $order,
            'items'   => $items,
            'history' => $history,
            'payment' => $payment ?: null,
        ];
    }

    /**
     * Get all saved addresses for a customer
     */
    public static function getAddresses(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("
            SELECT * FROM addresses
            WHERE user_id = :uid
            ORDER BY is_default DESC, id DESC
        ");
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get single address with IDOR ownership check
     */
    public static function getAddress(int $userId, int $addressId): ?array
    {
        if ($userId <= 0 || $addressId <= 0) {
            return null;
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("
            SELECT * FROM addresses
            WHERE id = :aid AND user_id = :uid
            LIMIT 1
        ");
        $stmt->execute([':aid' => $addressId, ':uid' => $userId]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ?: null;
    }

    /**
     * Create a new address for a customer (max 10 addresses)
     */
    public static function createAddress(int $userId, array $data): array
    {
        if ($userId <= 0) {
            return [false, 'Unauthorized.'];
        }

        $pdo = Database::getInstance();

        // Check 10 address limit
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM addresses WHERE user_id = :uid");
        $stmt->execute([':uid' => $userId]);
        if ((int)$stmt->fetchColumn() >= self::MAX_ADDRESSES) {
            return [false, 'You have reached the maximum of ' . self::MAX_ADDRESSES . ' saved addresses.'];
        }

        $label = trim((string)($data['label'] ?? 'Home'));
        $recipientName = trim((string)($data['recipient_name'] ?? ''));
        $phone = trim((string)($data['phone'] ?? ''));
        $county = trim((string)($data['county'] ?? ''));
        $area = trim((string)($data['area'] ?? ''));
        $streetDetails = trim((string)($data['street_details'] ?? ''));
        $isDefault = !empty($data['is_default']) ? 1 : 0;

        if ($label === '') $label = 'Home';
        if ($recipientName === '') {
            return [false, 'Please provide the recipient name.'];
        }
        if ($phone === '') {
            return [false, 'Please provide the contact phone number.'];
        }
        $normalizedPhone = normalize_phone($phone);
        if (!$normalizedPhone) {
            return [false, 'Please enter a valid Kenyan phone number (e.g. 0712 345 678).'];
        }
        if ($county === '') {
            return [false, 'Please select or enter the county.'];
        }
        if ($area === '') {
            return [false, 'Please specify the area or estate.'];
        }
        if ($streetDetails === '') {
            return [false, 'Please provide detailed delivery directions or building name.'];
        }

        // If this is the user's first address, force default
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM addresses WHERE user_id = :uid");
        $stmt->execute([':uid' => $userId]);
        if ((int)$stmt->fetchColumn() === 0) {
            $isDefault = 1;
        }

        $pdo->beginTransaction();
        try {
            if ($isDefault === 1) {
                // Clear other default addresses
                $pdo->prepare("UPDATE addresses SET is_default = 0 WHERE user_id = :uid")->execute([':uid' => $userId]);
            }

            $stmt = $pdo->prepare("
                INSERT INTO addresses (user_id, label, recipient_name, phone, county, area, street_details, is_default, created_at, updated_at)
                VALUES (:uid, :label, :name, :phone, :county, :area, :street, :def, NOW(), NOW())
            ");
            $stmt->execute([
                ':uid'    => $userId,
                ':label'  => mb_substr($label, 0, 50),
                ':name'   => mb_substr($recipientName, 0, 100),
                ':phone'  => $normalizedPhone,
                ':county' => mb_substr($county, 0, 100),
                ':area'   => mb_substr($area, 0, 100),
                ':street' => $streetDetails,
                ':def'    => $isDefault,
            ]);
            $newId = (int)$pdo->lastInsertId();
            $pdo->commit();

            return [true, 'Address saved successfully.', $newId];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [false, 'Failed to save address: ' . $e->getMessage()];
        }
    }

    /**
     * Update an address with IDOR protection
     */
    public static function updateAddress(int $userId, int $addressId, array $data): array
    {
        $existing = self::getAddress($userId, $addressId);
        if (!$existing) {
            return [false, 'Address not found.'];
        }

        $label = trim((string)($data['label'] ?? $existing['label']));
        $recipientName = trim((string)($data['recipient_name'] ?? $existing['recipient_name']));
        $phone = trim((string)($data['phone'] ?? $existing['phone']));
        $county = trim((string)($data['county'] ?? $existing['county']));
        $area = trim((string)($data['area'] ?? $existing['area']));
        $streetDetails = trim((string)($data['street_details'] ?? $existing['street_details']));
        $isDefault = isset($data['is_default']) ? (!empty($data['is_default']) ? 1 : 0) : (int)$existing['is_default'];

        if ($label === '') $label = 'Home';
        if ($recipientName === '') {
            return [false, 'Please provide the recipient name.'];
        }
        $normalizedPhone = normalize_phone($phone);
        if (!$normalizedPhone) {
            return [false, 'Please enter a valid Kenyan phone number.'];
        }
        if ($county === '') {
            return [false, 'Please specify the county.'];
        }
        if ($area === '') {
            return [false, 'Please specify the area or estate.'];
        }
        if ($streetDetails === '') {
            return [false, 'Please provide detailed delivery directions or building name.'];
        }

        $pdo = Database::getInstance();
        $pdo->beginTransaction();
        try {
            if ($isDefault === 1) {
                $pdo->prepare("UPDATE addresses SET is_default = 0 WHERE user_id = :uid")->execute([':uid' => $userId]);
            }

            $stmt = $pdo->prepare("
                UPDATE addresses
                SET label = :label,
                    recipient_name = :name,
                    phone = :phone,
                    county = :county,
                    area = :area,
                    street_details = :street,
                    is_default = :def,
                    updated_at = NOW()
                WHERE id = :aid AND user_id = :uid
            ");
            $stmt->execute([
                ':label'  => mb_substr($label, 0, 50),
                ':name'   => mb_substr($recipientName, 0, 100),
                ':phone'  => $normalizedPhone,
                ':county' => mb_substr($county, 0, 100),
                ':area'   => mb_substr($area, 0, 100),
                ':street' => $streetDetails,
                ':def'    => $isDefault,
                ':aid'    => $addressId,
                ':uid'    => $userId,
            ]);
            $pdo->commit();

            return [true, 'Address updated successfully.'];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [false, 'Failed to update address: ' . $e->getMessage()];
        }
    }

    /**
     * Delete an address with IDOR protection
     */
    public static function deleteAddress(int $userId, int $addressId): array
    {
        $existing = self::getAddress($userId, $addressId);
        if (!$existing) {
            return [false, 'Address not found.'];
        }

        $pdo = Database::getInstance();
        $wasDefault = (int)$existing['is_default'] === 1;

        $stmt = $pdo->prepare("DELETE FROM addresses WHERE id = :aid AND user_id = :uid");
        $stmt->execute([':aid' => $addressId, ':uid' => $userId]);

        // If the deleted address was default, promote the newest remaining address
        if ($wasDefault) {
            $stmt = $pdo->prepare("
                UPDATE addresses 
                SET is_default = 1 
                WHERE user_id = :uid 
                ORDER BY id DESC 
                LIMIT 1
            ");
            $stmt->execute([':uid' => $userId]);
        }

        return [true, 'Address deleted successfully.'];
    }

    /**
     * Set default address
     */
    public static function setDefaultAddress(int $userId, int $addressId): array
    {
        $existing = self::getAddress($userId, $addressId);
        if (!$existing) {
            return [false, 'Address not found.'];
        }

        $pdo = Database::getInstance();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE addresses SET is_default = 0 WHERE user_id = :uid")->execute([':uid' => $userId]);
            $pdo->prepare("UPDATE addresses SET is_default = 1, updated_at = NOW() WHERE id = :aid AND user_id = :uid")->execute([
                ':aid' => $addressId,
                ':uid' => $userId,
            ]);
            $pdo->commit();
            return [true, 'Default delivery address updated.'];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [false, 'Failed to update default address: ' . $e->getMessage()];
        }
    }
}
