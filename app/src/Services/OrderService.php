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
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
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
        $statusCounts = [
            'all'               => $totalAllOrders,
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
        Permissions::require('orders.manage');

        if ($toStatus === 'cancelled') {
            self::cancel($orderId, $actorId, (string)$note, $expectedFrom, false, $ip);
            return;
        }

        if ($toStatus === 'expired') {
            self::cancel($orderId, $actorId, (string)$note, $expectedFrom, true, $ip);
            return;
        }

        if ($toStatus === 'refunded') {
            Permissions::require('orders.refund');
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
        Permissions::require('orders.manage');

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
        Permissions::require('orders.refund');

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
        Permissions::require('orders.refund'); // Admin only

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
        Permissions::require('orders.manage');

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
                VALUES (:oid, :status, :status, :note, :actor)
            ");
            $hStmt->execute([
                ':oid'    => $orderId,
                ':status' => $currentStatus,
                ':note'   => $note,
                ':actor'  => $actorId,
            ]);

            $remoteIp = $ip ?: ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
            AuditLogger::log($actorId, 'order_add_note', 'order', $orderId, [
                'note'   => $note,
                'status' => $currentStatus,
                'order'  => $order['order_number'],
            ], $remoteIp);
        });
    }
}
