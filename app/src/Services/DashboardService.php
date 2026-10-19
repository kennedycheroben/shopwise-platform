<?php
namespace App\Services;
use App\Database\Database;

final class DashboardService
{
    private static function scalar($pdo, string $sql): string
    {
        return (string)$pdo->query($sql)->fetchColumn();
    }
    public static function data(): array
    {
        $pdo = Database::getInstance();

        // Helper for scalar queries
        $scalar = fn(string $sql): string => (string)$pdo->query($sql)->fetchColumn();

        // Date ranges
        $now = new \DateTime();
        $last30Start = (clone $now)->modify('-29 days')->format('Y-m-d');
        $prev30Start = (clone $now)->modify('-59 days')->format('Y-m-d');
        $prev30End = (clone $now)->modify('-30 days')->format('Y-m-d');

        // Metric cards data with trends
        $revenue30 = (float)$scalar("SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status='paid' AND created_at >= '$last30Start'");
        $revenuePrev30 = (float)$scalar("SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status='paid' AND created_at >= '$prev30Start' AND created_at <= '$prev30End'");

        $orders30 = (int)$scalar("SELECT COUNT(*) FROM orders WHERE payment_status='paid' AND created_at >= '$last30Start'");
        $ordersPrev30 = (int)$scalar("SELECT COUNT(*) FROM orders WHERE payment_status='paid' AND created_at >= '$prev30Start' AND created_at <= '$prev30End'");

        $customers30 = (int)$scalar("SELECT COUNT(DISTINCT user_id) FROM orders WHERE payment_status='paid' AND created_at >= '$last30Start' AND user_id IS NOT NULL");
        $customersPrev30 = (int)$scalar("SELECT COUNT(DISTINCT user_id) FROM orders WHERE payment_status='paid' AND created_at >= '$prev30Start' AND created_at <= '$prev30End' AND user_id IS NOT NULL");

        $aov30 = $orders30 > 0 ? $revenue30 / $orders30 : 0;
        $aovPrev30 = $ordersPrev30 > 0 ? $revenuePrev30 / $ordersPrev30 : 0;

        // Trend calculation
        $trend = function (float $current, float $previous): array {
            if ($previous <= 0) {
                return ['class' => 'neutral', 'text' => 'No previous data', 'icon' => 'bi-dash'];
            }
            $diff = $current - $previous;
            $pct = $previous > 0 ? round(abs($diff) / $previous * 100, 1) : 0;
            if ($diff > 0) return ['class' => 'up', 'text' => "+{$pct}%", 'icon' => 'bi-arrow-up-short'];
            if ($diff < 0) return ['class' => 'down', 'text' => "-{$pct}%", 'icon' => 'bi-arrow-down-short'];
            return ['class' => 'neutral', 'text' => 'No change', 'icon' => 'bi-dash'];
        };

        // Revenue Overview Chart Data
        // Monthly: last 12 months
        $monthlyLabels = [];
        $monthlyRevenue = [];
        $monthlyOrders = [];
        for ($i = 11; $i >= 0; $i--) {
            $date = (clone $now)->modify("-$i months");
            $monthStart = $date->format('Y-m-01');
            $monthEnd = $date->format('Y-m-t');
            $label = $date->format('M Y');
            $monthlyLabels[] = $label;
            $monthlyRevenue[] = (float)$scalar("SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status='paid' AND created_at >= '$monthStart' AND created_at <= '$monthEnd 23:59:59'");
            $monthlyOrders[] = (int)$scalar("SELECT COUNT(*) FROM orders WHERE payment_status='paid' AND created_at >= '$monthStart' AND created_at <= '$monthEnd 23:59:59'");
        }

        // Weekly: last 12 weeks
        $weeklyLabels = [];
        $weeklyRevenue = [];
        $weeklyOrders = [];
        for ($i = 11; $i >= 0; $i--) {
            $weekStart = (clone $now)->modify("-$i weeks")->modify('monday this week')->format('Y-m-d');
            $weekEnd = (clone $now)->modify("-$i weeks")->modify('sunday this week')->format('Y-m-d');
            $label = 'W' . (clone $now)->modify("-$i weeks")->format('W');
            $weeklyLabels[] = $label;
            $weeklyRevenue[] = (float)$scalar("SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status='paid' AND created_at >= '$weekStart' AND created_at <= '$weekEnd 23:59:59'");
            $weeklyOrders[] = (int)$scalar("SELECT COUNT(*) FROM orders WHERE payment_status='paid' AND created_at >= '$weekStart' AND created_at <= '$weekEnd 23:59:59'");
        }

        // Daily: last 30 days
        $dailyLabels = [];
        $dailyRevenue = [];
        $dailyOrders = [];
        for ($i = 29; $i >= 0; $i--) {
            $day = (clone $now)->modify("-$i days")->format('Y-m-d');
            $label = (clone $now)->modify("-$i days")->format('M j');
            $dailyLabels[] = $label;
            $dailyRevenue[] = (float)$scalar("SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status='paid' AND DATE(created_at) = '$day'");
            $dailyOrders[] = (int)$scalar("SELECT COUNT(*) FROM orders WHERE payment_status='paid' AND DATE(created_at) = '$day'");
        }

        // Sales Breakdown by Category (paid revenue)
        $categoryRevenue = $pdo->query("
            SELECT c.name as category, COALESCE(SUM(oi.line_total),0) as revenue
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            JOIN products p ON p.id = oi.product_id
            JOIN categories c ON c.id = p.category_id
            WHERE o.payment_status = 'paid'
            GROUP BY c.id, c.name
            ORDER BY revenue DESC
        ")->fetchAll();

        $totalCategoryRevenue = array_sum(array_column($categoryRevenue, 'revenue'));
        $topCategories = array_slice($categoryRevenue, 0, 4);
        $otherRevenue = $totalCategoryRevenue;
        foreach ($topCategories as $cat) {
            $otherRevenue -= (float)$cat['revenue'];
        }
        if ($otherRevenue > 0) {
            $topCategories[] = ['category' => 'Other', 'revenue' => $otherRevenue];
        }

        // Top Products with primary image
        $topProducts = $pdo->query("
            SELECT oi.product_id, oi.product_name, SUM(oi.quantity) as units, SUM(oi.line_total) as revenue,
                   (SELECT pi.path FROM product_images pi WHERE pi.product_id = oi.product_id AND pi.is_primary = 1 LIMIT 1) as primary_image
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            WHERE o.payment_status = 'paid'
            GROUP BY oi.product_id, oi.product_name
            ORDER BY units DESC
            LIMIT 10
        ")->fetchAll();

        // Recent Orders (10)
        $recentOrders = $pdo->query("
            SELECT o.order_number, o.status, o.payment_status, o.total, o.created_at,
                   COALESCE(CONCAT(u.first_name,' ',u.last_name), o.recipient_name) as customer
            FROM orders o
            LEFT JOIN users u ON u.id = o.user_id
            ORDER BY o.created_at DESC
            LIMIT 10
        ")->fetchAll();

        // Stock Alerts
        $stockAlerts = $pdo->query("
            SELECT v.id, v.sku, v.size, v.color, v.stock_qty, v.low_stock_threshold, p.name as product_name
            FROM product_variants v
            JOIN products p ON p.id = v.product_id
            WHERE v.stock_qty <= v.low_stock_threshold AND v.is_active = 1 AND p.deleted_at IS NULL
            ORDER BY v.stock_qty ASC
            LIMIT 10
        ")->fetchAll();

        // Order statuses
        $statuses = $pdo->query('SELECT status, COUNT(*) total FROM orders GROUP BY status')->fetchAll();

        // New customers this month
        $newCustomers = (int)$scalar("SELECT COUNT(*) FROM users WHERE role='customer' AND created_at >= DATE_FORMAT(CURDATE(),'%Y-%m-01')");

        // Active products
        $activeProducts = (int)$scalar("SELECT COUNT(*) FROM products WHERE is_active=1 AND deleted_at IS NULL");

        // Sales today
        $salesToday = (float)$scalar("SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status='paid' AND DATE(created_at) = CURDATE()");

        return [
            // Metric cards
            'revenue_30' => $revenue30,
            'revenue_30_trend' => $trend($revenue30, $revenuePrev30),
            'orders_30' => $orders30,
            'orders_30_trend' => $trend($orders30, $ordersPrev30),
            'customers_30' => $customers30,
            'customers_30_trend' => $trend($customers30, $customersPrev30),
            'aov_30' => $aov30,
            'aov_30_trend' => $trend($aov30, $aovPrev30),

            // Revenue Overview Chart Data
            'chart_monthly' => [
                'labels' => $monthlyLabels,
                'revenue' => $monthlyRevenue,
                'orders' => $monthlyOrders,
            ],
            'chart_weekly' => [
                'labels' => $weeklyLabels,
                'revenue' => $weeklyRevenue,
                'orders' => $weeklyOrders,
            ],
            'chart_daily' => [
                'labels' => $dailyLabels,
                'revenue' => $dailyRevenue,
                'orders' => $dailyOrders,
            ],

            // Sales Breakdown
            'category_breakdown' => $topCategories,
            'total_category_revenue' => $totalCategoryRevenue,

            // Top Products
            'top_products' => $topProducts,

            // Recent Orders
            'recent_orders' => $recentOrders,

            // Stock Alerts
            'stock_alerts' => $stockAlerts,

            // Order Statuses
            'statuses' => $statuses,

            // Other
            'sales_today' => $salesToday,
            'new_customers' => $newCustomers,
            'active_products' => $activeProducts,
        ];
    }
}