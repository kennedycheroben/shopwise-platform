<?php
namespace App\Services;

use App\Database\Database;

final class DashboardService
{
    /**
     * Return revenue & order chart series and totals for the given period.
     * Supported periods: 'monthly' (12 months), 'weekly' (12 weeks), 'daily' (30 days).
     */
    public static function chartData(string $period = 'monthly'): array
    {
        $pdo = Database::getInstance();
        $now = new \DateTime();

        $categories = [];
        $revenue = [];
        $orders = [];
        $totalRev = 0.0;
        $totalOrders = 0;

        if ($period === 'weekly') {
            // Last 12 weeks: calculate start of earliest week (Monday)
            $earliestWeekStart = (clone $now)->modify('-11 weeks')->modify('monday this week')->format('Y-m-d 00:00:00');

            $stmt = $pdo->prepare("
                SELECT created_at, total
                FROM orders
                WHERE payment_status = 'paid' AND created_at >= :start
            ");
            $stmt->execute([':start' => $earliestWeekStart]);
            $orderRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // 12 weeks buckets
            for ($i = 11; $i >= 0; $i--) {
                $weekStart = (clone $now)->modify("-$i weeks")->modify('monday this week')->format('Y-m-d 00:00:00');
                $weekEnd = (clone $now)->modify("-$i weeks")->modify('sunday this week')->format('Y-m-d 23:59:59');
                $label = 'W' . (clone $now)->modify("-$i weeks")->format('W');

                $wRev = 0.0;
                $wOrders = 0;
                foreach ($orderRows as $row) {
                    if ($row['created_at'] >= $weekStart && $row['created_at'] <= $weekEnd) {
                        $wRev += (float)$row['total'];
                        $wOrders++;
                    }
                }

                $categories[] = $label;
                $revenue[] = round($wRev, 2);
                $orders[] = $wOrders;
                $totalRev += $wRev;
                $totalOrders += $wOrders;
            }
        } elseif ($period === 'daily') {
            // Last 30 days
            $startDate = (clone $now)->modify('-29 days')->format('Y-m-d 00:00:00');
            $stmt = $pdo->prepare("
                SELECT DATE_FORMAT(created_at, '%Y-%m-%d') as d,
                       COALESCE(SUM(total), 0) as rev,
                       COUNT(*) as cnt
                FROM orders
                WHERE payment_status = 'paid' AND created_at >= :start
                GROUP BY d
            ");
            $stmt->execute([':start' => $startDate]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $dayData = [];
            foreach ($rows as $r) {
                $dayData[$r['d']] = $r;
            }

            for ($i = 29; $i >= 0; $i--) {
                $dObj = (clone $now)->modify("-$i days");
                $ymd = $dObj->format('Y-m-d');
                $label = $dObj->format('M j');

                $rev = isset($dayData[$ymd]) ? (float)$dayData[$ymd]['rev'] : 0.0;
                $cnt = isset($dayData[$ymd]) ? (int)$dayData[$ymd]['cnt'] : 0;

                $categories[] = $label;
                $revenue[] = round($rev, 2);
                $orders[] = $cnt;
                $totalRev += $rev;
                $totalOrders += $cnt;
            }
        } else {
            // Monthly: Last 12 months
            $startDate = (clone $now)->modify('-11 months')->format('Y-m-01 00:00:00');
            $stmt = $pdo->prepare("
                SELECT DATE_FORMAT(created_at, '%Y-%m') as ym,
                       COALESCE(SUM(total), 0) as rev,
                       COUNT(*) as cnt
                FROM orders
                WHERE payment_status = 'paid' AND created_at >= :start
                GROUP BY ym
            ");
            $stmt->execute([':start' => $startDate]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $monthData = [];
            foreach ($rows as $r) {
                $monthData[$r['ym']] = $r;
            }

            for ($i = 11; $i >= 0; $i--) {
                $mObj = (clone $now)->modify("-$i months");
                $ym = $mObj->format('Y-m');
                $label = $mObj->format('M Y');

                $rev = isset($monthData[$ym]) ? (float)$monthData[$ym]['rev'] : 0.0;
                $cnt = isset($monthData[$ym]) ? (int)$monthData[$ym]['cnt'] : 0;

                $categories[] = $label;
                $revenue[] = round($rev, 2);
                $orders[] = $cnt;
                $totalRev += $rev;
                $totalOrders += $cnt;
            }
        }

        $avgOrder = $totalOrders > 0 ? $totalRev / $totalOrders : 0.0;

        return [
            'period' => $period,
            'categories' => $categories,
            'labels' => $categories, // alias for compatibility
            'revenue' => $revenue,
            'orders' => $orders,
            'totals' => [
                'revenue' => $totalRev,
                'revenue_formatted' => format_money($totalRev),
                'orders' => $totalOrders,
                'orders_formatted' => number_format($totalOrders),
                'avg_order' => $avgOrder,
                'avg_order_formatted' => format_money($avgOrder),
            ],
        ];
    }

    /**
     * Full aggregated dashboard data payload.
     */
    public static function data(): array
    {
        $pdo = Database::getInstance();
        $scalar = fn(string $sql): string => (string)$pdo->query($sql)->fetchColumn();

        $now = new \DateTime();
        $last30Start = (clone $now)->modify('-29 days')->format('Y-m-d 00:00:00');
        $prev30Start = (clone $now)->modify('-59 days')->format('Y-m-d 00:00:00');
        $prev30End = (clone $now)->modify('-30 days')->format('Y-m-d 23:59:59');

        // Metric cards: last 30 days vs previous 30 days
        $stmtRev = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status='paid' AND created_at >= :start");
        $stmtRev->execute([':start' => $last30Start]);
        $revenue30 = (float)$stmtRev->fetchColumn();

        $stmtPrevRev = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status='paid' AND created_at >= :start AND created_at <= :end");
        $stmtPrevRev->execute([':start' => $prev30Start, ':end' => $prev30End]);
        $revenuePrev30 = (float)$stmtPrevRev->fetchColumn();

        $stmtOrd = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE payment_status='paid' AND created_at >= :start");
        $stmtOrd->execute([':start' => $last30Start]);
        $orders30 = (int)$stmtOrd->fetchColumn();

        $stmtPrevOrd = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE payment_status='paid' AND created_at >= :start AND created_at <= :end");
        $stmtPrevOrd->execute([':start' => $prev30Start, ':end' => $prev30End]);
        $ordersPrev30 = (int)$stmtPrevOrd->fetchColumn();

        $stmtCust = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM orders WHERE payment_status='paid' AND created_at >= :start AND user_id IS NOT NULL");
        $stmtCust->execute([':start' => $last30Start]);
        $customers30 = (int)$stmtCust->fetchColumn();

        $stmtPrevCust = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM orders WHERE payment_status='paid' AND created_at >= :start AND created_at <= :end AND user_id IS NOT NULL");
        $stmtPrevCust->execute([':start' => $prev30Start, ':end' => $prev30End]);
        $customersPrev30 = (int)$stmtPrevCust->fetchColumn();

        $aov30 = $orders30 > 0 ? $revenue30 / $orders30 : 0.0;
        $aovPrev30 = $ordersPrev30 > 0 ? $revenuePrev30 / $ordersPrev30 : 0.0;

        // Trend calculation: 'up', 'down', or '' for no change / no previous data
        $trend = function (float $current, float $previous): array {
            if ($previous <= 0.0) {
                return ['class' => '', 'text' => '0.0%', 'icon' => 'bi-dash'];
            }
            $diff = $current - $previous;
            $pct = round(abs($diff) / $previous * 100, 1);
            if ($diff > 0.001) {
                return ['class' => 'up', 'text' => "+{$pct}%", 'icon' => 'bi-arrow-up-short'];
            }
            if ($diff < -0.001) {
                return ['class' => 'down', 'text' => "-{$pct}%", 'icon' => 'bi-arrow-down-short'];
            }
            return ['class' => '', 'text' => '0.0%', 'icon' => 'bi-dash'];
        };

        // Revenue Overview Chart Data
        $chartMonthly = self::chartData('monthly');
        $chartWeekly = self::chartData('weekly');
        $chartDaily = self::chartData('daily');

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
        ")->fetchAll(\PDO::FETCH_ASSOC);

        $totalCategoryRevenue = array_sum(array_column($categoryRevenue, 'revenue'));
        $topCategories = array_slice($categoryRevenue, 0, 4);
        $otherRevenue = (float)$totalCategoryRevenue;
        foreach ($topCategories as $cat) {
            $otherRevenue -= (float)$cat['revenue'];
        }
        if ($otherRevenue > 0.01) {
            $topCategories[] = ['category' => 'Other', 'revenue' => round($otherRevenue, 2)];
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
        ")->fetchAll(\PDO::FETCH_ASSOC);

        // Recent Orders (10)
        $recentOrdersRaw = $pdo->query("
            SELECT o.id, o.order_number, o.status, o.payment_status, o.total, o.created_at,
                   COALESCE(CONCAT(u.first_name,' ',u.last_name), o.recipient_name) as customer
            FROM orders o
            LEFT JOIN users u ON u.id = o.user_id
            ORDER BY o.created_at DESC
            LIMIT 10
        ")->fetchAll(\PDO::FETCH_ASSOC);

        $recentOrders = [];
        foreach ($recentOrdersRaw as $ord) {
            $st = $ord['status'];
            $stClass = match ($st) {
                'delivered', 'paid' => 'completed',
                'processing', 'shipped' => 'processing',
                'cancelled' => 'cancelled',
                default => 'pending',
            };
            $ord['status_class'] = $stClass;
            $recentOrders[] = $ord;
        }

        // Stock Alerts (low or zero stock)
        $stockAlerts = $pdo->query("
            SELECT v.id, v.sku, v.size, v.color, v.stock_qty, v.low_stock_threshold, p.name as product_name
            FROM product_variants v
            JOIN products p ON p.id = v.product_id
            WHERE v.stock_qty <= v.low_stock_threshold AND v.is_active = 1 AND p.deleted_at IS NULL
            ORDER BY v.stock_qty ASC
            LIMIT 10
        ")->fetchAll(\PDO::FETCH_ASSOC);

        // Order statuses count
        $statuses = $pdo->query('SELECT status, COUNT(*) total FROM orders GROUP BY status')->fetchAll(\PDO::FETCH_ASSOC);

        // Auxiliary stats
        $newCustomers = (int)$scalar("SELECT COUNT(*) FROM users WHERE role='customer' AND created_at >= DATE_FORMAT(CURDATE(),'%Y-%m-01')");
        $activeProducts = (int)$scalar("SELECT COUNT(*) FROM products WHERE is_active=1 AND deleted_at IS NULL");
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

            // Charts
            'chart_monthly' => $chartMonthly,
            'chart_weekly' => $chartWeekly,
            'chart_daily' => $chartDaily,

            // Sales Breakdown
            'category_breakdown' => $topCategories,
            'total_category_revenue' => $totalCategoryRevenue,

            // Lists
            'top_products' => $topProducts,
            'recent_orders' => $recentOrders,
            'stock_alerts' => $stockAlerts,

            // Extra
            'statuses' => $statuses,
            'sales_today' => $salesToday,
            'new_customers' => $newCustomers,
            'active_products' => $activeProducts,
        ];
    }
}