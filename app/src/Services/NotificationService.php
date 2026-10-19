<?php
namespace App\Services;

use App\Database\Database;
use PDO;

final class NotificationService
{
    /**
     * Compute real-time admin notification counts based on user role permissions.
     */
    public static function getNotifications(?string $userRole = null): array
    {
        $pdo = Database::getInstance();
        $items = [];
        $totalCount = 0;

        // 1. Orders Paid Awaiting Fulfillment (orders.view)
        if (Permissions::can('orders.view', $userRole)) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'paid'");
            $paidCount = (int)$stmt->fetchColumn();
            if ($paidCount > 0) {
                $items[] = [
                    'type'  => 'orders_paid',
                    'title' => 'Orders Awaiting Processing',
                    'count' => $paidCount,
                    'text'  => "{$paidCount} paid order(s) ready for fulfillment dispatch.",
                    'icon'  => 'bi-box-seam',
                    'color' => 'primary',
                    'link'  => url('admin/orders/?status=paid'),
                ];
                $totalCount += $paidCount;
            }
        }

        // 2. Low-Stock & Out-of-Stock Variants (inventory.adjust)
        if (Permissions::can('inventory.adjust', $userRole)) {
            $stmt = $pdo->query("
                SELECT COUNT(*) FROM product_variants pv
                JOIN products p ON pv.product_id = p.id
                WHERE p.deleted_at IS NULL AND pv.stock_qty <= pv.low_stock_threshold
            ");
            $lowStockCount = (int)$stmt->fetchColumn();
            if ($lowStockCount > 0) {
                $items[] = [
                    'type'  => 'low_stock',
                    'title' => 'Low Stock Alert',
                    'count' => $lowStockCount,
                    'text'  => "{$lowStockCount} product variant(s) at or below reorder threshold.",
                    'icon'  => 'bi-exclamation-triangle',
                    'color' => 'warning',
                    'link'  => url('admin/inventory/?filter=low_stock'),
                ];
                $totalCount += $lowStockCount;
            }
        }

        // 3. New Contact Inquiries (messages.manage)
        if (Permissions::can('messages.manage', $userRole)) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM contact_messages WHERE status = 'new'");
            $newMsgCount = (int)$stmt->fetchColumn();
            if ($newMsgCount > 0) {
                $items[] = [
                    'type'  => 'new_messages',
                    'title' => 'New Customer Inquiries',
                    'count' => $newMsgCount,
                    'text'  => "{$newMsgCount} unread message(s) waiting in contact inbox.",
                    'icon'  => 'bi-chat-left-dots',
                    'color' => 'info',
                    'link'  => url('admin/messages/?status=new'),
                ];
                $totalCount += $newMsgCount;
            }
        }

        // 4. Pending Reviews Moderation (reviews.moderate)
        if (Permissions::can('reviews.moderate', $userRole)) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM reviews WHERE status = 'pending'");
            $pendingReviews = (int)$stmt->fetchColumn();
            if ($pendingReviews > 0) {
                $items[] = [
                    'type'  => 'pending_reviews',
                    'title' => 'Reviews Pending Moderation',
                    'count' => $pendingReviews,
                    'text'  => "{$pendingReviews} product review(s) awaiting approval.",
                    'icon'  => 'bi-star',
                    'color' => 'success',
                    'link'  => url('admin/reviews/?status=pending'),
                ];
                $totalCount += $pendingReviews;
            }
        }

        return [
            'total_count' => $totalCount,
            'items'       => $items,
        ];
    }

    /**
     * Get recent contact messages for header messages dropdown.
     */
    public static function getHeaderMessages(?string $userRole = null): array
    {
        if (!Permissions::can('messages.manage', $userRole)) {
            return ['count' => 0, 'items' => []];
        }

        $pdo = Database::getInstance();
        $countStmt = $pdo->query("SELECT COUNT(*) FROM contact_messages WHERE status = 'new'");
        $unreadCount = (int)$countStmt->fetchColumn();

        $stmt = $pdo->query("
            SELECT id, name, email, subject, message, status, created_at
            FROM contact_messages
            ORDER BY created_at DESC
            LIMIT 5
        ");
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'count' => $unreadCount,
            'items' => $items,
        ];
    }
}
