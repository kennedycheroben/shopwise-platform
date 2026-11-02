<?php

namespace App\Services;

use App\Database\Database;
use DomainException;
use InvalidArgumentException;
use PDO;

final class OrderService
{
    /**
     * Permitted order status transitions.
     */
    public const ALLOWED_TRANSITIONS = [
        'pending_payment'   => ['paid', 'cancelled', 'expired'],
        'paid'              => ['processing', 'cancelled'],
        'processing'        => ['out_for_delivery', 'cancelled'],
        'out_for_delivery'  => ['delivered'],
        'delivered'         => ['refunded'],
    ];

    /**
     * Paginate orders with search, filters, and status count chips.
     */
    public static function paginate(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $pdo = Database::getInstance();
        $page = max(1, $page);
        $perPage = max(5, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = ['1=1'];
        $params = [];
        $isFiltered = false;

        // Search by order number, customer name, email, phone
        if (!empty($filters['search'])) {
            $search = '%' . trim($filters['search']) . '%';
            $where[] = "(o.order_number LIKE :s1 OR o.recipient_name LIKE :s2 OR o.phone LIKE :s3 OR u.email LIKE :s4 OR CONCAT(u.first_name, ' ', u.last_name) LIKE :s5)";
            $params[':s1'] = $search;
            $params[':s2'] = $search;
            $params[':s3'] = $search;
            $params[':s4'] = $search;
            $params[':s5'] = $search;
            $isFiltered = true;
        }

        // Filter status
        if (!empty($filters['attention']) || (isset($filters['status']) && $filters['status'] === 'attention')) {
            $where[] = "o.attention_reason IS NOT NULL AND o.attention_reason <> ''";
            $isFiltered = true;
        } elseif (!empty($filters['status']) && $filters['status'] !== 'all') {
            $where[] = "o.status = :status";
            $params[':status'] = $filters['status'];
            $isFiltered = true;
        }

        // Filter payment status
        if (!empty($filters['payment_status']) && $filters['payment_status'] !== 'all') {
            $where[] = "o.payment_status = :payment_status";
            $params[':payment_status'] = $filters['payment_status'];
            $isFiltered = true;
        }

        // Filter delivery method
        if (!empty($filters['delivery_method']) && in_array($filters['delivery_method'], ['pickup', 'delivery'], true)) {
            $where[] = "o.delivery_method = :delivery_method";
            $params[':delivery_method'] = $filters['delivery_method'];
            $isFiltered = true;
        }

        // Filter date range
        if (!empty($filters['start_date'])) {
            $where[] = "o.created_at >= :start_date";
            $params[':start_date'] = $filters['start_date'] . ' 00:00:00';
            $isFiltered = true;
        }
        if (!empty($filters['end_date'])) {
            $where[] = "o.created_at <= :end_date";
            $params[':end_date'] = $filters['end_date'] . ' 23:59:59';
            $isFiltered = true;
        }

        $whereClause = implode(' AND ', $where);

        // Calculate status counts for filter chips
        $statusCountsSql = "
            SELECT status, COUNT(*) as cnt
            FROM orders
            GROUP BY status
        ";
        $scStmt = $pdo->query($statusCountsSql);
        $scRows = $scStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        $totalAllOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
        $attentionCount = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE attention_reason IS NOT NULL AND attention_reason <> ''")->fetchColumn();
        $statusCounts = [
            'all'               => $totalAllOrders,
            'attention'         => $attentionCount,
            'pending_payment'   => (int)($scRows['pending_payment'] ?? 0),
            'paid'              => (int)($scRows['paid'] ?? 0),
            'processing'        => (int)($scRows['processing'] ?? 0),
            'out_for_delivery'  => (int)($scRows['out_for_delivery'] ?? 0),
            'delivered'         => (int)($scRows['delivered'] ?? 0),
            'cancelled'         => (int)($scRows['cancelled'] ?? 0),
            'expired'           => (int)($scRows['expired'] ?? 0),
            'refunded'          => (int)($scRows['refunded'] ?? 0),
        ];

        // Total count matching current filters
        $countSql = "
            SELECT COUNT(*)
            FROM orders o
            LEFT JOIN users u ON u.id = o.user_id
            WHERE {$whereClause}
        ";
        $cStmt = $pdo->prepare($countSql);
        $cStmt->execute($params);
        $totalItems = (int)$cStmt->fetchColumn();

        // Sort configuration
        $sort = $filters['sort'] ?? 'created_at';
        $order = strtolower($filters['order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        $sortColumn = match ($sort) {
            'order_number' => 'o.order_number',
            'customer'     => 'o.recipient_name',
            'total'        => 'o.total',
            'status'       => 'o.status',
            'payment'      => 'o.payment_status',
            default        => 'o.created_at',
        };

        $sql = "
            SELECT o.id, o.order_number, o.status, o.payment_status, o.delivery_method,
                   o.recipient_name, o.phone, o.total, o.created_at,
                   u.email as customer_email,
                   CONCAT(u.first_name, ' ', u.last_name) as user_customer_name,
                   (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) as items_count
            FROM orders o
            LEFT JOIN users u ON u.id = o.user_id
            WHERE {$whereClause}
            ORDER BY {$sortColumn} {$order}, o.id DESC
            LIMIT {$perPage} OFFSET {$offset}
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalPages = (int)ceil($totalItems / $perPage);

        return [
            'items'         => $items,
            'total_items'   => $totalItems,
            'current_page'  => $page,
            'per_page'      => $perPage,
            'total_pages'   => max(1, $totalPages),
            'status_counts' => $statusCounts,
            'is_filtered'   => $isFiltered,
            'total_orders'  => $totalAllOrders,
        ];
    }

    /**
     * Find order by ID with all related items, customer details, payments, and status history.
     */
    public static function find(int $orderId): ?array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("
            SELECT o.*,
                   u.first_name as user_first_name, u.last_name as user_last_name,
                   u.email as user_email, u.phone as user_phone, u.status as user_status
            FROM orders o
            LEFT JOIN users u ON u.id = o.user_id
            WHERE o.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            return null;
        }

        // Items
        $iStmt = $pdo->prepare("
            SELECT id, product_id, variant_id, product_name, size, color, sku,
                   unit_price, quantity, line_total, created_at
            FROM order_items
            WHERE order_id = :oid
            ORDER BY id ASC
        ");
        $iStmt->execute([':oid' => $orderId]);
        $order['items'] = $iStmt->fetchAll(PDO::FETCH_ASSOC);

        // Payments
        $pStmt = $pdo->prepare("
            SELECT id, method, status, amount, phone, merchant_request_id, checkout_request_id,
                   mpesa_receipt, result_code, result_desc, raw_callback, created_at, updated_at
            FROM payments
            WHERE order_id = :oid
            ORDER BY id DESC
        ");
        $pStmt->execute([':oid' => $orderId]);
        $order['payments'] = $pStmt->fetchAll(PDO::FETCH_ASSOC);

        // Status history
        $hStmt = $pdo->prepare("
            SELECT h.id, h.from_status, h.to_status, h.note, h.actor_user_id, h.created_at,
                   CONCAT(u.first_name, ' ', u.last_name) as actor_name,
                   u.role as actor_role
            FROM order_status_history h
            LEFT JOIN users u ON u.id = h.actor_user_id
            WHERE h.order_id = :oid
            ORDER BY h.id ASC
        ");
        $hStmt->execute([':oid' => $orderId]);
        $order['history'] = $hStmt->fetchAll(PDO::FETCH_ASSOC);

        return $order;
    }

    /**
     * Transition order status via the strict state machine.
     *
     * @param int $orderId
     * @param string $toStatus
     * @param int $actorId
     * @param string|null $note
     * @param string|null $expectedFrom The status the admin saw on their screen.
     * @param string|null $ip
     * @return void
     * @throws DomainException
     */
    public static function transition(
        int $orderId,
        string $toStatus,
        int $actorId,
        ?string $note = null,
        ?string $expectedFrom = null,
        ?string $ip = null
    ): void {
        Permissions::require('orders.manage', $actorId);

        if ($toStatus === 'cancelled') {
            self::cancel($orderId, $actorId, (string)$note, $expectedFrom, false, $ip);
            return;
        }

        if ($toStatus === 'expired') {
            self::cancel($orderId, $actorId, (string)$note, $expectedFrom, true, $ip);
            return;
        }

        if ($toStatus === 'refunded') {
            Permissions::require('orders.refund', $actorId);
        }

        Database::transaction(function (PDO $pdo) use ($orderId, $toStatus, $actorId, $note, $expectedFrom, $ip) {
            // SELECT ... FOR UPDATE
            $stmt = $pdo->prepare('SELECT id, status, payment_status, total, order_number FROM orders WHERE id = :id FOR UPDATE');
            $stmt->execute([':id' => $orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                throw new DomainException("Order not found.");
            }

            // Stale-state protection
            if ($expectedFrom !== null && $order['status'] !== $expectedFrom) {
                throw new DomainException("This order was updated by someone else, please refresh");
            }

            // Check permitted transitions
            $allowed = self::ALLOWED_TRANSITIONS[$order['status']] ?? [];
            if (!in_array($toStatus, $allowed, true)) {
                throw new DomainException("Transition from '{$order['status']}' to '{$toStatus}' is not permitted.");
            }

            // Execute status update
            $currentStatus = $order['status'];
            if ($toStatus === 'refunded') {
                $up = $pdo->prepare("UPDATE orders SET status = 'refunded', payment_status = 'refunded' WHERE id = :id AND status = :from");
            } else {
                $up = $pdo->prepare("UPDATE orders SET status = :to WHERE id = :id AND status = :from");
            }

            $upParams = [':id' => $orderId, ':from' => $currentStatus];
            if ($toStatus !== 'refunded') {
                $upParams[':to'] = $toStatus;
            }
            $up->execute($upParams);

            if ($up->rowCount() !== 1) {
                throw new DomainException("Order status update failed. Please refresh and try again.");
            }

            // Write status history
            $hStmt = $pdo->prepare("
                INSERT INTO order_status_history (order_id, from_status, to_status, note, actor_user_id)
                VALUES (:oid, :from, :to, :note, :actor)
            ");
            $hStmt->execute([
                ':oid'   => $orderId,
                ':from'  => $currentStatus,
                ':to'    => $toStatus,
                ':note'  => $note,
                ':actor' => $actorId,
            ]);

            // Write audit log
            $remoteIp = $ip ?: ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
            AuditLogger::log($actorId, 'order_status_transition', 'order', $orderId, [
                'from'  => $currentStatus,
                'to'    => $toStatus,
                'note'  => $note,
                'order' => $order['order_number'],
            ], $remoteIp);

            // Queue customer email notification
            MailerService::sendOrderStatusEmail($orderId, $toStatus, $note);
        });
    }

    /**
     * Cancel an order and restore stock idempotently.
     */
    public static function cancel(
        int $orderId,
        int $actorId,
        string $note = '',
        ?string $expectedFrom = null,
        bool $isExpiry = false,
        ?string $ip = null
    ): void {
        Permissions::require('orders.manage', $actorId);

        Database::transaction(function (PDO $pdo) use ($orderId, $actorId, $note, $expectedFrom, $isExpiry, $ip) {
            $stmt = $pdo->prepare('SELECT id, status, payment_status, stock_released, total, order_number FROM orders WHERE id = :id FOR UPDATE');
            $stmt->execute([':id' => $orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                throw new DomainException("Order not found.");
            }

            // Stale-state verification
            if ($expectedFrom !== null && $order['status'] !== $expectedFrom) {
                throw new DomainException("This order was updated by someone else, please refresh");
            }

            // Disallow cancelling orders that are already terminal or delivered
            if (in_array($order['status'], ['cancelled', 'expired', 'delivered', 'refunded'], true)) {
                throw new DomainException("This order cannot be cancelled (current status: {$order['status']}).");
            }

            $allowedStatuses = $isExpiry ? ['pending_payment'] : ['pending_payment', 'paid', 'processing'];
            if (!in_array($order['status'], $allowedStatuses, true)) {
                throw new DomainException("Order in status '{$order['status']}' cannot be cancelled.");
            }

            $currentStatus = $order['status'];
            $newStatus = $isExpiry ? 'expired' : 'cancelled';

            // Idempotent stock restoration: restore stock ONLY if not already released
            if ((int)$order['stock_released'] === 0) {
                $iStmt = $pdo->prepare("SELECT variant_id, quantity FROM order_items WHERE order_id = :oid");
                $iStmt->execute([':oid' => $orderId]);
                $items = $iStmt->fetchAll(PDO::FETCH_ASSOC);

                $reason = $isExpiry ? 'expiry_restore' : 'cancel_restore';
                $defaultNote = $isExpiry ? 'Order expired auto-restore' : 'Order cancelled stock restore';

                foreach ($items as $item) {
                    $vStmt = $pdo->prepare("UPDATE product_variants SET stock_qty = stock_qty + :qty WHERE id = :vid");
                    $vStmt->execute([':qty' => $item['quantity'], ':vid' => $item['variant_id']]);

                    $mStmt = $pdo->prepare("
                        INSERT INTO inventory_movements (variant_id, change_qty, reason, order_id, actor_user_id, note)
                        VALUES (:vid, :qty, :reason, :oid, :actor, :note)
                    ");
                    $mStmt->execute([
                        ':vid'    => $item['variant_id'],
                        ':qty'    => $item['quantity'],
                        ':reason' => $reason,
                        ':oid'    => $orderId,
                        ':actor'  => $actorId,
                        ':note'   => $note !== '' ? $note : $defaultNote,
                    ]);
                }
            }

            // Idempotent coupon release for unpaid or expired orders (paid orders keep coupon redemption)
            if ($order['payment_status'] !== 'paid' || $isExpiry) {
                CouponService::release($orderId, $pdo);
            }

            // Update order status. If order was paid, payment_status stays 'paid' (requires manual refund)
            $upStmt = $pdo->prepare("
                UPDATE orders
                SET status = :to, stock_released = 1
                WHERE id = :id AND status = :from
            ");
            $upStmt->execute([
                ':to'   => $newStatus,
                ':id'   => $orderId,
                ':from' => $currentStatus,
            ]);

            if ($upStmt->rowCount() !== 1) {
                throw new DomainException("Order cancellation failed. Status was changed concurrently.");
            }

            // Insert status history
            $hStmt = $pdo->prepare("
                INSERT INTO order_status_history (order_id, from_status, to_status, note, actor_user_id)
                VALUES (:oid, :from, :to, :note, :actor)
            ");
            $hStmt->execute([
                ':oid'   => $orderId,
                ':from'  => $currentStatus,
                ':to'    => $newStatus,
                ':note'  => $note !== '' ? $note : ($isExpiry ? 'Order expired' : 'Order cancelled'),
                ':actor' => $actorId,
            ]);

            // Audit
            $remoteIp = $ip ?: ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
            AuditLogger::log($actorId, $isExpiry ? 'order_expire' : 'order_cancel', 'order', $orderId, [
                'from'  => $currentStatus,
                'to'    => $newStatus,
                'note'  => $note,
                'order' => $order['order_number'],
            ], $remoteIp);

            // Queue customer email
            MailerService::sendOrderStatusEmail($orderId, $newStatus, $note);
        });
    }

    /**
     * Record a manual refund (Admin only: orders.refund).
     */
    public static function recordRefund(int $orderId, string $referenceNote, int $actorId, ?string $ip = null): void
    {
        Permissions::require('orders.refund', $actorId);

        $referenceNote = trim($referenceNote);
        if ($referenceNote === '') {
            throw new InvalidArgumentException("Reference note is required to record a refund (e.g. M-Pesa reversal code).");
        }

        Database::transaction(function (PDO $pdo) use ($orderId, $referenceNote, $actorId, $ip) {
            $stmt = $pdo->prepare('SELECT id, status, payment_status, total, order_number FROM orders WHERE id = :id FOR UPDATE');
            $stmt->execute([':id' => $orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                throw new DomainException("Order not found.");
            }

            // Allowed where the map permits:
            // 1. Delivered orders (delivered -> refunded)
            // 2. Paid cancelled orders awaiting refund
            $canRefund = ($order['status'] === 'delivered') ||
                         ($order['status'] === 'cancelled' && $order['payment_status'] === 'paid');

            if (!$canRefund) {
                throw new DomainException("Refund cannot be recorded for order in status '{$order['status']}' with payment status '{$order['payment_status']}'.");
            }

            $currentStatus = $order['status'];

            // Update order status and payment_status to 'refunded'
            $upStmt = $pdo->prepare("UPDATE orders SET status = 'refunded', payment_status = 'refunded' WHERE id = :id");
            $upStmt->execute([':id' => $orderId]);

            // Insert status history
            $hStmt = $pdo->prepare("
                INSERT INTO order_status_history (order_id, from_status, to_status, note, actor_user_id)
                VALUES (:oid, :from, 'refunded', :note, :actor)
            ");
            $hStmt->execute([
                ':oid'   => $orderId,
                ':from'  => $currentStatus,
                ':note'  => "Refund recorded: " . $referenceNote,
                ':actor' => $actorId,
            ]);

            // Audit
            $remoteIp = $ip ?: ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
            AuditLogger::log($actorId, 'order_record_refund', 'order', $orderId, [
                'from_status'    => $currentStatus,
                'to_status'      => 'refunded',
                'reference_note' => $referenceNote,
                'amount'         => $order['total'],
                'order'          => $order['order_number'],
            ], $remoteIp);

            // Queue customer email
            MailerService::sendOrderStatusEmail($orderId, 'refunded', $referenceNote);
        });
    }

    /**
     * Mark a pending_payment order as paid manually with an M-Pesa receipt code (Admin only).
     */
    public static function markAsPaid(int $orderId, string $mpesaReceipt, string $note, int $actorId, ?string $ip = null): void
    {
        Permissions::require('orders.mark_as_paid', $actorId);

        $mpesaReceipt = strtoupper(trim($mpesaReceipt));
        if (!preg_match('/^[A-Z0-9]{10}$/', $mpesaReceipt)) {
            throw new InvalidArgumentException("M-Pesa receipt must be exactly 10 uppercase alphanumeric characters (e.g. QAA1234567).");
        }

        $note = trim($note);
        if ($note === '') {
            throw new InvalidArgumentException("A reason or note is required for manual payment verification.");
        }

        Database::transaction(function (PDO $pdo) use ($orderId, $mpesaReceipt, $note, $actorId, $ip) {
            $stmt = $pdo->prepare('SELECT id, status, payment_status, total, phone, user_id, order_number FROM orders WHERE id = :id FOR UPDATE');
            $stmt->execute([':id' => $orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                throw new DomainException("Order not found.");
            }

            if ($order['status'] !== 'pending_payment') {
                throw new DomainException("Only orders in 'pending_payment' status can be marked as paid. Current status: {$order['status']}.");
            }

            // Check uniqueness of M-Pesa receipt code
            $dupStmt = $pdo->prepare("SELECT id, order_id FROM payments WHERE mpesa_receipt = :rcpt LIMIT 1");
            $dupStmt->execute([':rcpt' => $mpesaReceipt]);
            $dup = $dupStmt->fetch(PDO::FETCH_ASSOC);

            if ($dup && (int)$dup['order_id'] !== $orderId) {
                throw new DomainException("M-Pesa receipt '{$mpesaReceipt}' is already linked to order ID #{$dup['order_id']}. Duplicate receipts are rejected.");
            }

            // Update order
            $upStmt = $pdo->prepare("UPDATE orders SET status = 'paid', payment_status = 'paid' WHERE id = :id AND status = 'pending_payment'");
            $upStmt->execute([':id' => $orderId]);

            if ($upStmt->rowCount() !== 1) {
                throw new DomainException("Failed to update order status. Please refresh.");
            }

            // Update or create payment row
            $pStmt = $pdo->prepare("SELECT id FROM payments WHERE order_id = :oid LIMIT 1");
            $pStmt->execute([':oid' => $orderId]);
            $paymentId = $pStmt->fetchColumn();

            if ($paymentId) {
                $pUp = $pdo->prepare("
                    UPDATE payments
                    SET status = 'success', method = 'mpesa', amount = :amt, phone = :phone,
                        mpesa_receipt = :rcpt, result_desc = :desc, updated_at = NOW()
                    WHERE id = :pid
                ");
                $pUp->execute([
                    ':amt'   => $order['total'],
                    ':phone' => $order['phone'],
                    ':rcpt'  => $mpesaReceipt,
                    ':desc'  => "Manual override: " . $note,
                    ':pid'   => $paymentId,
                ]);
            } else {
                $pIn = $pdo->prepare("
                    INSERT INTO payments (order_id, method, status, amount, phone, mpesa_receipt, result_desc, created_at, updated_at)
                    VALUES (:oid, 'mpesa', 'success', :amt, :phone, :rcpt, :desc, NOW(), NOW())
                ");
                $pIn->execute([
                    ':oid'   => $orderId,
                    ':amt'   => $order['total'],
                    ':phone' => $order['phone'],
                    ':rcpt'  => $mpesaReceipt,
                    ':desc'  => "Manual override: " . $note,
                ]);
            }

            // Insert status history
            $hStmt = $pdo->prepare("
                INSERT INTO order_status_history (order_id, from_status, to_status, note, actor_user_id)
                VALUES (:oid, 'pending_payment', 'paid', :note, :actor)
            ");
            $hStmt->execute([
                ':oid'   => $orderId,
                ':note'  => "Manual payment recorded: {$mpesaReceipt}. Note: {$note}",
                ':actor' => $actorId,
            ]);

            // Audit
            $remoteIp = $ip ?: ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
            AuditLogger::log($actorId, 'order_mark_as_paid', 'order', $orderId, [
                'receipt' => $mpesaReceipt,
                'note'    => $note,
                'amount'  => $order['total'],
                'order'   => $order['order_number'],
            ], $remoteIp);

            // Queue customer email
            MailerService::sendOrderStatusEmail($orderId, 'paid', $note);
        });
    }

    /**
     * Add an internal note to an order without changing status.
     */
    public static function addInternalNote(int $orderId, string $note, int $actorId, ?string $ip = null): void
    {
        Permissions::require('orders.manage', $actorId);

        $note = trim($note);
        if ($note === '') {
            throw new InvalidArgumentException("Internal note cannot be empty.");
        }

        Database::transaction(function (PDO $pdo) use ($orderId, $note, $actorId, $ip) {
            $stmt = $pdo->prepare('SELECT status, order_number FROM orders WHERE id = :id');
            $stmt->execute([':id' => $orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                throw new DomainException("Order not found.");
            }

            $currentStatus = $order['status'];

            $hStmt = $pdo->prepare("
                INSERT INTO order_status_history (order_id, from_status, to_status, note, actor_user_id)
                VALUES (:oid, :from_status, :to_status, :note, :actor)
            ");
            $hStmt->execute([
                ':oid'         => $orderId,
                ':from_status' => $currentStatus,
                ':to_status'   => $currentStatus,
                ':note'        => $note,
                ':actor'       => $actorId,
            ]);

            $remoteIp = $ip ?: ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
            AuditLogger::log($actorId, 'order_add_note', 'order', $orderId, [
                'note'   => $note,
                'status' => $currentStatus,
                'order'  => $order['order_number'],
            ], $remoteIp);
        });
    }

    /**
     * Generate unique order number in SW-YYYYMMDD-XXXXXX format with collision retry.
     */
    public static function generateOrderNumber(PDO $pdo): string
    {
        $prefix = 'SW-' . date('Ymd') . '-';
        $chars = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
        for ($i = 0; $i < 10; $i++) {
            $suffix = '';
            $bytes = random_bytes(6);
            for ($j = 0; $j < 6; $j++) {
                $suffix .= $chars[ord($bytes[$j]) % strlen($chars)];
            }
            $orderNum = $prefix . $suffix;
            $stmt = $pdo->prepare("SELECT id FROM orders WHERE order_number = :num LIMIT 1");
            $stmt->execute([':num' => $orderNum]);
            if (!$stmt->fetchColumn()) {
                return $orderNum;
            }
        }
        throw new \RuntimeException("Failed to generate a unique order number.");
    }

    /**
     * Find order by order number.
     */
    public static function findByOrderNumber(string $orderNumber): ?array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT id FROM orders WHERE order_number = :num LIMIT 1");
        $stmt->execute([':num' => $orderNumber]);
        $id = $stmt->fetchColumn();
        if (!$id) {
            return null;
        }
        return self::find((int)$id);
    }

    /**
     * Create an order from current cart inside a single database transaction.
     * Retries up to 3 times on deadlock or lock wait timeout.
     */
    public static function createFromCart(
        array $cartData,
        array $choices,
        int $userId,
        string $idempotencyToken,
        string $ip
    ): array {
        $idempotencyToken = trim($idempotencyToken);
        if ($idempotencyToken === '') {
            throw new InvalidArgumentException("Idempotency token is required.");
        }

        $pdo = Database::getInstance();

        // Idempotency fast-path check
        $checkStmt = $pdo->prepare("SELECT order_number FROM orders WHERE idempotency_token = :token LIMIT 1");
        $checkStmt->execute([':token' => $idempotencyToken]);
        $existingOrderNum = $checkStmt->fetchColumn();
        if ($existingOrderNum) {
            $existing = self::findByOrderNumber((string)$existingOrderNum);
            if ($existing) {
                return $existing;
            }
        }

        // Limit open pending orders per customer to 5
        $pendingStmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE user_id = :uid AND status = 'pending_payment'");
        $pendingStmt->execute([':uid' => $userId]);
        if ((int)$pendingStmt->fetchColumn() >= 5) {
            throw new DomainException("You currently have 5 pending orders awaiting payment. Please complete payment or wait for expiry before placing a new order.");
        }

        // Limit checkout placement to 10 per hour per customer
        $hourlyStmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE user_id = :uid AND created_at >= NOW() - INTERVAL 1 HOUR");
        $hourlyStmt->execute([':uid' => $userId]);
        if ((int)$hourlyStmt->fetchColumn() >= 10) {
            throw new DomainException("You have reached the checkout limit of 10 orders per hour. Please try again later.");
        }

        $items = $cartData['items'] ?? [];
        if (empty($items)) {
            throw new DomainException("Cannot place order: your cart is empty.");
        }

        $maxRetries = 3;
        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                return self::executeCreateOrder($cartData, $choices, $userId, $idempotencyToken, $ip);
            } catch (\PDOException $e) {
                $errCode = $e->errorInfo[1] ?? (int)$e->getCode();
                if (($errCode === 1213 || $errCode === 1205 || str_contains($e->getMessage(), 'Deadlock')) && $attempt < $maxRetries) {
                    usleep(rand(50000, 150000));
                    continue;
                }
                if ($errCode === 1062 && str_contains($e->getMessage(), 'uq_orders_idempotency')) {
                    $cStmt = $pdo->prepare("SELECT order_number FROM orders WHERE idempotency_token = :token LIMIT 1");
                    $cStmt->execute([':token' => $idempotencyToken]);
                    $orderNum = $cStmt->fetchColumn();
                    if ($orderNum) {
                        $ex = self::findByOrderNumber((string)$orderNum);
                        if ($ex) {
                            return $ex;
                        }
                    }
                }
                throw $e;
            }
        }
    }

    /**
     * Internal execution of order creation within transaction.
     */
    private static function executeCreateOrder(
        array $cartData,
        array $choices,
        int $userId,
        string $idempotencyToken,
        string $ip
    ): array {
        $pdo = Database::getInstance();
        $pdo->beginTransaction();

        try {
            $cartItems = $cartData['items'];
            $variantIds = array_map(fn($item) => (int)$item['variant_id'], $cartItems);
            sort($variantIds);

            // 1. Lock variant rows with SELECT ... FOR UPDATE in ascending order
            $placeholders = implode(',', array_fill(0, count($variantIds), '?'));
            $vLockStmt = $pdo->prepare("
                SELECT pv.id, pv.product_id, pv.size, pv.color, pv.sku, pv.stock_qty, pv.price_override, pv.is_active as variant_is_active,
                       p.name as product_name, p.base_price, p.is_active as product_is_active, p.deleted_at as product_deleted_at
                FROM product_variants pv
                JOIN products p ON p.id = pv.product_id
                WHERE pv.id IN ($placeholders)
                ORDER BY pv.id ASC
                FOR UPDATE
            ");
            $vLockStmt->execute($variantIds);
            $variants = [];
            while ($row = $vLockStmt->fetch(PDO::FETCH_ASSOC)) {
                $variants[(int)$row['id']] = $row;
            }

            // Verify availability and active status for each cart item
            $lineSnapshots = [];
            $computedSubtotal = 0.00;

            foreach ($cartItems as $item) {
                $vid = (int)$item['variant_id'];
                $qty = (int)$item['quantity'];

                if (!isset($variants[$vid])) {
                    throw new DomainException("An item in your cart is no longer available.");
                }

                $variant = $variants[$vid];
                if ((int)$variant['product_is_active'] !== 1 || $variant['product_deleted_at'] !== null || (int)$variant['variant_is_active'] !== 1) {
                    throw new DomainException("Item '{$variant['product_name']}' is currently unavailable.");
                }

                if ((int)$variant['stock_qty'] < $qty) {
                    throw new DomainException("Insufficient stock for '{$variant['product_name']}'. Only {$variant['stock_qty']} unit(s) remaining.");
                }

                // Determine official unit price from database snapshot
                $unitPrice = $variant['price_override'] !== null ? (float)$variant['price_override'] : (float)$variant['base_price'];
                $lineTotal = round($unitPrice * $qty, 2);
                $computedSubtotal += $lineTotal;

                $lineSnapshots[] = [
                    'product_id'   => (int)$variant['product_id'],
                    'variant_id'   => $vid,
                    'product_name' => (string)$variant['product_name'],
                    'size'         => $variant['size'],
                    'color'        => $variant['color'],
                    'sku'          => (string)$variant['sku'],
                    'unit_price'   => $unitPrice,
                    'quantity'     => $qty,
                    'line_total'   => $lineTotal,
                ];
            }

            // 2. Decrement stock for all lines
            foreach ($lineSnapshots as $line) {
                $dStmt = $pdo->prepare("
                    UPDATE product_variants 
                    SET stock_qty = stock_qty - ? 
                    WHERE id = ? AND stock_qty >= ?
                ");
                $dStmt->execute([$line['quantity'], $line['variant_id'], $line['quantity']]);
                if ($dStmt->rowCount() !== 1) {
                    throw new DomainException("Stock for '{$line['product_name']}' was reserved by another customer. Please review your cart.");
                }
            }

            // 3. Compute delivery fee
            $deliveryMethod = ($choices['delivery_method'] ?? 'delivery') === 'pickup' ? 'pickup' : 'delivery';
            $deliveryPricingMethod = 'pickup';
            $deliveryFee = 0.00;
            $distanceKm = null;
            $lat = null;
            $lng = null;

            if ($deliveryMethod === 'pickup') {
                $deliveryFee = 0.00;
                $deliveryPricingMethod = 'pickup';
            } else {
                // Check if distance-based (coordinates supplied) or zone-based
                if (isset($choices['latitude']) && isset($choices['longitude']) && $choices['latitude'] !== '' && $choices['longitude'] !== '') {
                    $lat = (float)$choices['latitude'];
                    $lng = (float)$choices['longitude'];
                    if (!DeliveryService::isKenyaCoordinate($lat, $lng)) {
                        throw new DomainException("Selected map coordinates are outside of Kenya delivery boundaries.");
                    }
                    $distQuote = DeliveryService::quoteDistance($lat, $lng, $computedSubtotal);
                    $deliveryFee = (float)$distQuote['fee'];
                    $distanceKm = (float)$distQuote['distance_km'];
                    $deliveryPricingMethod = 'distance';
                } elseif (!empty($choices['zone_id'])) {
                    try {
                        $zoneQuote = DeliveryService::quoteZone((int)$choices['zone_id'], $computedSubtotal);
                    } catch (\InvalidArgumentException $iae) {
                        throw new DomainException($iae->getMessage());
                    }
                    $deliveryFee = (float)$zoneQuote['fee'];
                    $deliveryPricingMethod = 'zone';
                } elseif (!empty($choices['county']) && !empty($choices['area'])) {
                    $matchedZone = DeliveryService::matchZoneByLocation((string)$choices['county'], (string)$choices['area']);
                    if (!$matchedZone) {
                        throw new DomainException("Delivery is not available for '" . $choices['county'] . " - " . $choices['area'] . "'. Please select a supported delivery zone.");
                    }
                    try {
                        $zoneQuote = DeliveryService::quoteZone((int)$matchedZone['id'], $computedSubtotal);
                    } catch (\InvalidArgumentException $iae) {
                        throw new DomainException($iae->getMessage());
                    }
                    $deliveryFee = (float)$zoneQuote['fee'];
                    $deliveryPricingMethod = 'zone';
                } else {
                    throw new DomainException("Please select a valid delivery zone or pinpoint your delivery location.");
                }
            }

            // 4. Coupon evaluation & redemption inside transaction
            $couponCode = trim((string)($cartData['coupon_code'] ?? ($choices['coupon_code'] ?? '')));
            $discount = 0.00;
            $couponId = null;

            if ($couponCode !== '') {
                $eval = CouponService::evaluate($couponCode, $computedSubtotal, $userId, $deliveryFee);
                if (!$eval['valid']) {
                    throw new DomainException($eval['message'] ?? 'Coupon code cannot be applied to this order.');
                }
                $discount = (float)$eval['discount'];
                $couponId = (int)$eval['coupon']['id'];
            }

            // 5. Compute final order total and enforce whole-shilling rule
            $orderTotal = ($computedSubtotal + $deliveryFee) - $discount;
            if (floor($orderTotal) != $orderTotal || $orderTotal < 1.0) {
                throw new DomainException("Final order total must be a whole shilling amount of at least KES 1.");
            }

            // 6. Generate order number and expiry timestamp
            $orderNumber = self::generateOrderNumber($pdo);
            $expiryMinutes = (int)setting('order_expiry_minutes', 30);
            $expiresAt = date('Y-m-d H:i:s', time() + ($expiryMinutes * 60));

            // Customer details
            $recipientName = trim((string)($choices['recipient_name'] ?? ''));
            if ($recipientName === '') {
                throw new DomainException("Recipient name is required.");
            }

            $rawPhone = (string)($choices['phone'] ?? '');
            $normalizedPhone = MpesaService::normalizePhone($rawPhone);
            if (!$normalizedPhone) {
                throw new DomainException("A valid Kenyan M-Pesa phone number is required (e.g. 07XXXXXXXX or 01XXXXXXXX).");
            }

            $county = !empty($choices['county']) ? trim((string)$choices['county']) : null;
            $area = !empty($choices['area']) ? trim((string)$choices['area']) : null;
            $streetDetails = !empty($choices['street_details']) ? trim((string)$choices['street_details']) : null;
            $customerNotes = !empty($choices['notes']) ? substr(trim((string)$choices['notes']), 0, 500) : null;

            // 7. Insert into orders table
            $oStmt = $pdo->prepare("
                INSERT INTO orders (
                    order_number, user_id, status, payment_status, subtotal, delivery_fee, discount, total,
                    delivery_method, delivery_pricing_method, delivery_distance_km, recipient_name, phone,
                    county, area, street_details, latitude, longitude, customer_notes, stock_released,
                    idempotency_token, expires_at, created_at, updated_at
                ) VALUES (
                    :order_number, :user_id, 'pending_payment', 'unpaid', :subtotal, :delivery_fee, :discount, :total,
                    :delivery_method, :delivery_pricing_method, :delivery_distance_km, :recipient_name, :phone,
                    :county, :area, :street_details, :latitude, :longitude, :customer_notes, 0,
                    :idempotency_token, :expires_at, NOW(), NOW()
                )
            ");
            $oStmt->execute([
                ':order_number'            => $orderNumber,
                ':user_id'                 => $userId,
                ':subtotal'                => $computedSubtotal,
                ':delivery_fee'            => $deliveryFee,
                ':discount'                => $discount,
                ':total'                   => $orderTotal,
                ':delivery_method'         => $deliveryMethod,
                ':delivery_pricing_method' => $deliveryPricingMethod,
                ':delivery_distance_km'    => $distanceKm,
                ':recipient_name'          => $recipientName,
                ':phone'                   => $normalizedPhone,
                ':county'                  => $county,
                ':area'                    => $area,
                ':street_details'          => $streetDetails,
                ':latitude'                => $lat,
                ':longitude'               => $lng,
                ':customer_notes'          => $customerNotes,
                ':idempotency_token'       => $idempotencyToken,
                ':expires_at'              => $expiresAt,
            ]);

            $orderId = (int)$pdo->lastInsertId();

            // 8. Insert order items & record inventory movement
            $oiStmt = $pdo->prepare("
                INSERT INTO order_items (
                    order_id, product_id, variant_id, product_name, size, color, sku,
                    unit_price, quantity, line_total, created_at
                ) VALUES (
                    :oid, :pid, :vid, :name, :size, :color, :sku,
                    :price, :qty, :total, NOW()
                )
            ");

            $imStmt = $pdo->prepare("
                INSERT INTO inventory_movements (variant_id, change_qty, reason, order_id, actor_user_id, note)
                VALUES (:vid, :change, 'sale', :oid, :actor, 'Customer checkout placement')
            ");

            foreach ($lineSnapshots as $line) {
                $oiStmt->execute([
                    ':oid'   => $orderId,
                    ':pid'   => $line['product_id'],
                    ':vid'   => $line['variant_id'],
                    ':name'  => $line['product_name'],
                    ':size'  => $line['size'],
                    ':color' => $line['color'],
                    ':sku'   => $line['sku'],
                    ':price' => $line['unit_price'],
                    ':qty'   => $line['quantity'],
                    ':total' => $line['line_total'],
                ]);

                $imStmt->execute([
                    ':vid'    => $line['variant_id'],
                    ':change' => -((int)$line['quantity']),
                    ':oid'    => $orderId,
                    ':actor'  => $userId,
                ]);
            }

            // 9. Redeem coupon if applicable
            if ($couponId !== null && $discount > 0) {
                CouponService::redeem($couponId, $orderId, $userId, $discount, $pdo);
            }

            // 10. Insert order status history
            $hStmt = $pdo->prepare("
                INSERT INTO order_status_history (order_id, from_status, to_status, note, actor_user_id, created_at)
                VALUES (:oid, NULL, 'pending_payment', 'Order created awaiting M-Pesa payment', :actor, NOW())
            ");
            $hStmt->execute([':oid' => $orderId, ':actor' => $userId]);

            // 11. Insert initial payment record
            $pStmt = $pdo->prepare("
                INSERT INTO payments (order_id, method, status, amount, phone, created_at, updated_at)
                VALUES (:oid, 'mpesa', 'initiated', :amt, :phone, NOW(), NOW())
            ");
            $pStmt->execute([
                ':oid'   => $orderId,
                ':amt'   => $orderTotal,
                ':phone' => $normalizedPhone,
            ]);

            // 12. Audit log
            AuditLogger::log($userId, 'order_created', 'order', $orderId, [
                'order_number' => $orderNumber,
                'total'        => $orderTotal,
            ], $ip);

            // 13. Queue customer order placed email
            MailerService::sendOrderPlacedEmail($orderId);

            $pdo->commit();

            return self::find($orderId);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Mark an order as paid from verified M-Pesa callback or query.
     */
    public static function transitionToPaid(int $orderId, string $mpesaReceipt, ?string $rawCallback = null, ?string $ip = null, ?int $paymentId = null): void
    {
        Database::transaction(function (PDO $pdo) use ($orderId, $mpesaReceipt, $rawCallback, $ip, $paymentId) {
            $stmt = $pdo->prepare("SELECT id, status, payment_status, total, order_number, user_id FROM orders WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$order) {
                throw new DomainException("Order not found.");
            }

            if ($order['status'] === 'paid') {
                return; // Idempotent no-op
            }

            if ($order['status'] !== 'pending_payment') {
                throw new DomainException("Cannot mark order paid from status '{$order['status']}'.");
            }

            // Update order
            $up = $pdo->prepare("UPDATE orders SET status = 'paid', payment_status = 'paid' WHERE id = :id AND status = 'pending_payment'");
            $up->execute([':id' => $orderId]);

            // Update payment record (target specific payment row or latest pending attempt)
            if ($paymentId !== null) {
                $pUp = $pdo->prepare("
                    UPDATE payments 
                    SET status = 'success', mpesa_receipt = :rcpt, raw_callback = COALESCE(:raw, raw_callback), updated_at = NOW()
                    WHERE id = :pid
                ");
                $pUp->execute([
                    ':rcpt' => $mpesaReceipt,
                    ':raw'  => $rawCallback,
                    ':pid'  => $paymentId,
                ]);
            } else {
                $pUp = $pdo->prepare("
                    UPDATE payments 
                    SET status = 'success', mpesa_receipt = :rcpt, raw_callback = COALESCE(:raw, raw_callback), updated_at = NOW()
                    WHERE order_id = :oid AND status IN ('pending', 'pending_verification', 'initiated')
                    ORDER BY id DESC LIMIT 1
                ");
                $pUp->execute([
                    ':rcpt' => $mpesaReceipt,
                    ':raw'  => $rawCallback,
                    ':oid'  => $orderId,
                ]);
            }

            // Insert status history
            $hStmt = $pdo->prepare("
                INSERT INTO order_status_history (order_id, from_status, to_status, note, actor_user_id, created_at)
                VALUES (:oid, 'pending_payment', 'paid', :note, NULL, NOW())
            ");
            $hStmt->execute([
                ':oid'  => $orderId,
                ':note' => "M-Pesa payment confirmed: {$mpesaReceipt}",
            ]);

            // Audit
            $remoteIp = $ip ?: ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
            AuditLogger::log(null, 'order_paid_mpesa', 'order', $orderId, [
                'order_number' => $order['order_number'],
                'receipt'      => $mpesaReceipt,
                'amount'       => $order['total'],
            ], $remoteIp);

            // Queue notifications
            MailerService::sendOrderPaidEmail($orderId, $mpesaReceipt);
            MailerService::sendAdminNewPaidOrderEmail($orderId, $mpesaReceipt);
        });
    }

    /**
     * Handle late payment success after order has already expired or been cancelled.
     * Attempts to re-reserve stock. If unavailable, sets attention_reason and alerts admin.
     */
    public static function handleLateSuccessAfterExpiry(int $orderId, string $mpesaReceipt, ?string $rawCallback = null, ?string $ip = null): array
    {
        return Database::transaction(function (PDO $pdo) use ($orderId, $mpesaReceipt, $rawCallback, $ip) {
            $stmt = $pdo->prepare("SELECT id, status, payment_status, total, order_number, user_id, stock_released FROM orders WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$order) {
                throw new DomainException("Order not found.");
            }

            // Always mark payment as success with receipt
            $pUp = $pdo->prepare("
                UPDATE payments 
                SET status = 'success', mpesa_receipt = :rcpt, raw_callback = COALESCE(:raw, raw_callback), updated_at = NOW()
                WHERE order_id = :oid
            ");
            $pUp->execute([
                ':rcpt' => $mpesaReceipt,
                ':raw'  => $rawCallback,
                ':oid'  => $orderId,
            ]);

            // Fetch order items to check availability
            $iStmt = $pdo->prepare("SELECT variant_id, quantity FROM order_items WHERE order_id = :oid");
            $iStmt->execute([':oid' => $orderId]);
            $items = $iStmt->fetchAll(PDO::FETCH_ASSOC);

            $allAvailable = true;
            foreach ($items as $item) {
                $vStmt = $pdo->prepare("SELECT stock_qty FROM product_variants WHERE id = :vid FOR UPDATE");
                $vStmt->execute([':vid' => $item['variant_id']]);
                $currentStock = (int)$vStmt->fetchColumn();
                if ($currentStock < (int)$item['quantity']) {
                    $allAvailable = false;
                    break;
                }
            }

            if ($allAvailable && !empty($items)) {
                // Re-reserve stock
                foreach ($items as $item) {
                    $dStmt = $pdo->prepare("UPDATE product_variants SET stock_qty = stock_qty - ? WHERE id = ? AND stock_qty >= ?");
                    $dStmt->execute([$item['quantity'], $item['variant_id'], $item['quantity']]);

                    $mStmt = $pdo->prepare("
                        INSERT INTO inventory_movements (variant_id, change_qty, reason, order_id, actor_user_id, note)
                        VALUES (:vid, :change, 'sale', :oid, NULL, 'Late payment re-reserved stock')
                    ");
                    $mStmt->execute([
                        ':vid'    => $item['variant_id'],
                        ':change' => -((int)$item['quantity']),
                        ':oid'    => $orderId,
                    ]);
                }

                // Move order to paid
                $up = $pdo->prepare("UPDATE orders SET status = 'paid', payment_status = 'paid', stock_released = 0, attention_reason = NULL WHERE id = :id");
                $up->execute([':id' => $orderId]);

                $hStmt = $pdo->prepare("
                    INSERT INTO order_status_history (order_id, from_status, to_status, note, actor_user_id, created_at)
                    VALUES (:oid, :from, 'paid', :note, NULL, NOW())
                ");
                $hStmt->execute([
                    ':oid'  => $orderId,
                    ':from' => $order['status'],
                    ':note' => "Late payment confirmed after {$order['status']}. Stock successfully re-reserved. Receipt: {$mpesaReceipt}",
                ]);

                MailerService::sendOrderPaidEmail($orderId, $mpesaReceipt);
                MailerService::sendAdminNewPaidOrderEmail($orderId, $mpesaReceipt);

                return ['status' => 'paid', 'stock_re_reserved' => true];
            } else {
                // Stock unavailable: set attention_reason and alert admin
                $reason = 'Paid but stock unavailable, refund or fulfil';
                $up = $pdo->prepare("UPDATE orders SET payment_status = 'paid', attention_reason = :reason WHERE id = :id");
                $up->execute([':reason' => $reason, ':id' => $orderId]);

                $hStmt = $pdo->prepare("
                    INSERT INTO order_status_history (order_id, from_status, to_status, note, actor_user_id, created_at)
                    VALUES (:oid, :from, :to, :note, NULL, NOW())
                ");
                $hStmt->execute([
                    ':oid'  => $orderId,
                    ':from' => $order['status'],
                    ':to'   => $order['status'],
                    ':note' => "Late payment received ({$mpesaReceipt}), but stock was no longer available. Requires admin refund or fulfillment.",
                ]);

                MailerService::sendAdminLatePaymentStockAlertEmail($orderId, $mpesaReceipt);

                return ['status' => $order['status'], 'stock_re_reserved' => false, 'attention_reason' => $reason];
            }
        });
    }

    /**
     * Expire an unpaid order, restore reserved stock, release coupon redemption and mark payments timed out.
     */
    public static function expire(int $orderId, ?string $note = null): bool
    {
        return Database::transaction(function (PDO $pdo) use ($orderId, $note) {
            $stmt = $pdo->prepare("SELECT id, status, stock_released, order_number, user_id FROM orders WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$order || $order['status'] !== 'pending_payment') {
                return false;
            }

            // 1. Restore stock if not already released
            if ((int)$order['stock_released'] === 0) {
                $iStmt = $pdo->prepare("SELECT variant_id, quantity FROM order_items WHERE order_id = :oid");
                $iStmt->execute([':oid' => $orderId]);
                $items = $iStmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($items as $item) {
                    $vStmt = $pdo->prepare("UPDATE product_variants SET stock_qty = stock_qty + :q WHERE id = :vid");
                    $vStmt->execute([':q' => $item['quantity'], ':vid' => $item['variant_id']]);

                    $mStmt = $pdo->prepare("
                        INSERT INTO inventory_movements (variant_id, change_qty, reason, order_id, actor_user_id, note)
                        VALUES (:vid, :q, 'expiry_restore', :oid, NULL, 'Order expired auto-restore')
                    ");
                    $mStmt->execute([
                        ':vid' => $item['variant_id'],
                        ':q'   => $item['quantity'],
                        ':oid' => $orderId,
                    ]);
                }
            }

            // 2. Release coupon redemption if any
            CouponService::release($orderId, $pdo);

            // 3. Mark pending or initiated payments as timeout
            $pStmt = $pdo->prepare("
                UPDATE payments 
                SET status = 'timeout', result_desc = 'Order expired before payment was completed', updated_at = NOW()
                WHERE order_id = :oid AND status IN ('initiated', 'pending')
            ");
            $pStmt->execute([':oid' => $orderId]);

            // 4. Update order status
            $up = $pdo->prepare("UPDATE orders SET status = 'expired', stock_released = 1 WHERE id = :id AND status = 'pending_payment'");
            $up->execute([':id' => $orderId]);

            // 5. History & Audit
            $hStmt = $pdo->prepare("
                INSERT INTO order_status_history (order_id, from_status, to_status, note, actor_user_id, created_at)
                VALUES (:oid, 'pending_payment', 'expired', :note, NULL, NOW())
            ");
            $hStmt->execute([
                ':oid'  => $orderId,
                ':note' => $note ?: 'Order expired due to non-payment within timeout window',
            ]);

            AuditLogger::log(null, 'order_expired', 'order', $orderId, [
                'order_number' => $order['order_number'],
                'note'         => $note ?: 'Order expired',
            ], '127.0.0.1');

            // 6. Queue customer expiry notification email
            MailerService::sendOrderStatusEmail($orderId, 'expired', 'Your order was cancelled because payment was not completed before expiry.');

            return true;
        });
    }
}

