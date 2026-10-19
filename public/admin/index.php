<?php
require dirname(__DIR__, 2) . '/app/bootstrap.php';
require dirname(__DIR__, 2) . '/app/views/admin/guard.php';

use App\Services\DashboardService;

$dashboard = DashboardService::data();

// Support JSON response if requested
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($dashboard, JSON_THROW_ON_ERROR);
    exit;
}

$pageTitle = 'Dashboard';

ob_start(); ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const accentColor = getComputedStyle(document.documentElement).getPropertyValue('--accent-color').trim() || '#e07826';
    const successColor = getComputedStyle(document.documentElement).getPropertyValue('--success-color').trim() || '#0a7c3e';
    const warningColor = getComputedStyle(document.documentElement).getPropertyValue('--warning-color').trim() || '#f59e0b';
    const infoColor = getComputedStyle(document.documentElement).getPropertyValue('--info-color').trim() || '#0284c7';
    const borderColor = getComputedStyle(document.documentElement).getPropertyValue('--border-color').trim() || '#e2e8f0';
    const mutedColor = getComputedStyle(document.documentElement).getPropertyValue('--muted-color').trim() || '#64748b';

    // Revenue Overview Chart (Area + Line on Dual Y-Axes)
    const revenueOptions = {
        series: [
            {
                name: 'Paid Revenue (KES)',
                type: 'area',
                data: <?= json_encode($dashboard['chart_monthly']['revenue']) ?>
            },
            {
                name: 'Orders',
                type: 'line',
                data: <?= json_encode($dashboard['chart_monthly']['orders']) ?>
            }
        ],
        chart: {
            type: 'line',
            height: 340,
            fontFamily: 'inherit',
            toolbar: { show: false },
            zoom: { enabled: false },
            animations: { enabled: true, easing: 'easeinout', speed: 400 },
            dropShadow: { enabled: true, top: 4, left: 0, blur: 8, opacity: 0.15, color: accentColor }
        },
        colors: [accentColor, successColor],
        stroke: { curve: 'smooth', width: [3, 2] },
        fill: {
            type: ['gradient', 'solid'],
            gradient: {
                shadeIntensity: 1,
                opacityFrom: 0.35,
                opacityTo: 0.05,
                stops: [0, 90, 100]
            }
        },
        dataLabels: { enabled: false },
        xaxis: {
            categories: <?= json_encode($dashboard['chart_monthly']['categories']) ?>,
            axisBorder: { show: false },
            axisTicks: { show: false },
            labels: { style: { colors: mutedColor, fontSize: '12px' } }
        },
        yaxis: [
            {
                title: { text: 'Revenue (KES)', style: { color: mutedColor, fontSize: '12px' } },
                labels: {
                    style: { colors: mutedColor, fontSize: '12px' },
                    formatter: function(val) {
                        return 'KES ' + Number(val).toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 0});
                    }
                }
            },
            {
                opposite: true,
                title: { text: 'Orders', style: { color: mutedColor, fontSize: '12px' } },
                labels: {
                    style: { colors: mutedColor, fontSize: '12px' },
                    formatter: function(val) {
                        return Math.round(val);
                    }
                }
            }
        ],
        grid: { borderColor: borderColor, strokeDashArray: 4, xaxis: { lines: { show: false } } },
        tooltip: {
            shared: true,
            intersect: false,
            y: [
                {
                    formatter: function(val) {
                        return 'KES ' + Number(val).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                    }
                },
                {
                    formatter: function(val) {
                        return Math.round(val) + ' orders';
                    }
                }
            ]
        },
        legend: {
            position: 'top',
            horizontalAlign: 'right',
            offsetY: -10,
            markers: { radius: 12 }
        }
    };

    const revenueChart = new ApexCharts(document.querySelector('#revenueChart'), revenueOptions);
    revenueChart.render();

    // Chart Tabs click handler (fetching JSON from server)
    const tabs = document.querySelectorAll('.chart-tab');
    tabs.forEach(tab => {
        tab.addEventListener('click', function () {
            const period = this.getAttribute('data-period');
            tabs.forEach(t => t.classList.remove('active'));
            this.classList.add('active');

            fetch('<?= e(url('admin/api/revenue-chart.php')) ?>?period=' + encodeURIComponent(period))
                .then(res => res.json())
                .then(data => {
                    revenueChart.updateOptions({
                        xaxis: { categories: data.categories }
                    });
                    revenueChart.updateSeries([
                        { name: 'Paid Revenue (KES)', type: 'area', data: data.revenue },
                        { name: 'Orders', type: 'line', data: data.orders }
                    ]);

                    if (data.totals) {
                        document.querySelector('#totalRevenue').textContent = data.totals.revenue_formatted;
                        document.querySelector('#totalOrders').textContent = data.totals.orders_formatted;
                        document.querySelector('#avgOrderValue').textContent = data.totals.avg_order_formatted;
                    }
                })
                .catch(err => console.error('Error fetching chart data:', err));
        });
    });

    // Sales Breakdown Donut Chart
    const breakdownCategories = <?= json_encode(array_column($dashboard['category_breakdown'], 'category')) ?>;
    const breakdownRevenue = <?= json_encode(array_map('floatval', array_column($dashboard['category_breakdown'], 'revenue'))) ?>;

    if (breakdownRevenue.length > 0) {
        const breakdownOptions = {
            series: breakdownRevenue,
            labels: breakdownCategories,
            chart: {
                type: 'donut',
                height: 240,
                fontFamily: 'inherit'
            },
            colors: [accentColor, successColor, warningColor, infoColor, '#64748b'],
            plotOptions: {
                pie: {
                    donut: {
                        size: '75%',
                        labels: {
                            show: true,
                            name: {
                                show: true,
                                fontSize: '12px',
                                color: mutedColor
                            },
                            value: {
                                show: true,
                                fontSize: '1.25rem',
                                fontWeight: 700,
                                color: getComputedStyle(document.documentElement).getPropertyValue('--heading-color').trim(),
                                formatter: function(val) {
                                    return 'KES ' + Number(val).toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 0});
                                }
                            },
                            total: {
                                show: true,
                                label: 'Total',
                                fontSize: '11px',
                                color: mutedColor,
                                formatter: function(w) {
                                    const total = w.globals.seriesTotals.reduce((a, b) => a + b, 0);
                                    return 'KES ' + Number(total).toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 0});
                                }
                            }
                        }
                    }
                }
            },
            dataLabels: { enabled: false },
            legend: { show: false },
            stroke: { width: 3, colors: ['var(--card-bg)'] }
        };

        const breakdownChart = new ApexCharts(document.querySelector('#salesBreakdownChart'), breakdownOptions);
        breakdownChart.render();
    } else {
        document.querySelector('#salesBreakdownChart').innerHTML = '<p class="text-muted text-center py-5 mb-0">No sales recorded yet</p>';
    }

    // Theme changed listener for charts
    document.addEventListener('themeChanged', function() {
        const newBorderColor = getComputedStyle(document.documentElement).getPropertyValue('--border-color').trim();
        const newMutedColor = getComputedStyle(document.documentElement).getPropertyValue('--muted-color').trim();
        revenueChart.updateOptions({
            grid: { borderColor: newBorderColor },
            xaxis: { labels: { style: { colors: newMutedColor } } },
            yaxis: [
                { labels: { style: { colors: newMutedColor } }, title: { style: { color: newMutedColor } } },
                { labels: { style: { colors: newMutedColor } }, title: { style: { color: newMutedColor } } }
            ]
        });
    });
});
</script>
<?php
$pageScripts = ob_get_clean();
require dirname(__DIR__, 2) . '/app/views/admin/layout.php';
?>
<div class="main-content page-dashboard">
    <!-- Metric Cards -->
    <div class="metric-cards">
        <div class="metric-card">
            <div class="metric-card-icon accent">
                <i class="bi bi-cart3"></i>
            </div>
            <div class="metric-card-body">
                <div class="metric-card-label">Revenue (30 days)</div>
                <div class="metric-card-value"><?= e(format_money($dashboard['revenue_30'])) ?></div>
                <div class="metric-card-trend <?= e($dashboard['revenue_30_trend']['class']) ?>">
                    <i class="bi <?= e($dashboard['revenue_30_trend']['icon']) ?>"></i>
                    <?= e($dashboard['revenue_30_trend']['text']) ?>
                </div>
            </div>
        </div>
        <div class="metric-card">
            <div class="metric-card-icon success">
                <i class="bi bi-box-seam"></i>
            </div>
            <div class="metric-card-body">
                <div class="metric-card-label">Orders (30 days)</div>
                <div class="metric-card-value"><?= e(number_format($dashboard['orders_30'])) ?></div>
                <div class="metric-card-trend <?= e($dashboard['orders_30_trend']['class']) ?>">
                    <i class="bi <?= e($dashboard['orders_30_trend']['icon']) ?>"></i>
                    <?= e($dashboard['orders_30_trend']['text']) ?>
                </div>
            </div>
        </div>
        <div class="metric-card">
            <div class="metric-card-icon warning">
                <i class="bi bi-people"></i>
            </div>
            <div class="metric-card-body">
                <div class="metric-card-label">New Customers (30 days)</div>
                <div class="metric-card-value"><?= e(number_format($dashboard['customers_30'])) ?></div>
                <div class="metric-card-trend <?= e($dashboard['customers_30_trend']['class']) ?>">
                    <i class="bi <?= e($dashboard['customers_30_trend']['icon']) ?>"></i>
                    <?= e($dashboard['customers_30_trend']['text']) ?>
                </div>
            </div>
        </div>
        <div class="metric-card">
            <div class="metric-card-icon info">
                <i class="bi bi-calculator"></i>
            </div>
            <div class="metric-card-body">
                <div class="metric-card-label">Avg. Order Value (30 days)</div>
                <div class="metric-card-value"><?= e(format_money($dashboard['aov_30'])) ?></div>
                <div class="metric-card-trend <?= e($dashboard['aov_30_trend']['class']) ?>">
                    <i class="bi <?= e($dashboard['aov_30_trend']['icon']) ?>"></i>
                    <?= e($dashboard['aov_30_trend']['text']) ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="dashboard-charts">
        <!-- Revenue Overview Chart -->
        <div class="card">
            <div class="card-body">
                <div class="chart-header">
                    <h5 class="chart-header-title">Revenue Overview</h5>
                    <div class="chart-tabs">
                        <button class="chart-tab active" data-period="monthly" type="button">Monthly</button>
                        <button class="chart-tab" data-period="weekly" type="button">Weekly</button>
                        <button class="chart-tab" data-period="daily" type="button">Daily</button>
                    </div>
                </div>
                <div class="revenue-stats">
                    <div class="revenue-stat">
                        <span class="revenue-stat-dot accent"></span>
                        <div class="revenue-stat-body">
                            <div class="revenue-stat-label">Total Revenue</div>
                            <div class="revenue-stat-value" id="totalRevenue"><?= e($dashboard['chart_monthly']['totals']['revenue_formatted']) ?></div>
                        </div>
                    </div>
                    <div class="revenue-stat">
                        <span class="revenue-stat-dot success"></span>
                        <div class="revenue-stat-body">
                            <div class="revenue-stat-label">Total Orders</div>
                            <div class="revenue-stat-value" id="totalOrders"><?= e($dashboard['chart_monthly']['totals']['orders_formatted']) ?></div>
                        </div>
                    </div>
                    <div class="revenue-stat">
                        <span class="revenue-stat-dot warning"></span>
                        <div class="revenue-stat-body">
                            <div class="revenue-stat-label">Avg. Order</div>
                            <div class="revenue-stat-value" id="avgOrderValue"><?= e($dashboard['chart_monthly']['totals']['avg_order_formatted']) ?></div>
                        </div>
                    </div>
                </div>
                <div class="chart-container" id="revenueChart"></div>
            </div>
        </div>

        <!-- Sales Breakdown -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">Sales Breakdown</h5>
            </div>
            <div class="card-body">
                <div class="chart-container" id="salesBreakdownChart"></div>
                <div class="breakdown-legend">
                    <?php if (empty($dashboard['category_breakdown'])): ?>
                        <p class="text-muted text-center py-2 mb-0">No sales by category yet</p>
                    <?php else: ?>
                        <?php
                        $dots = ['accent', 'success', 'warning', 'info', 'primary'];
                        foreach ($dashboard['category_breakdown'] as $idx => $cat):
                            $dot = $dots[$idx % count($dots)];
                        ?>
                            <div class="breakdown-legend-item">
                                <span class="breakdown-dot <?= $dot ?>"></span>
                                <span class="breakdown-label"><?= e($cat['category']) ?></span>
                                <span class="breakdown-value"><?= e(format_money($cat['revenue'])) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Middle Row -->
    <div class="dashboard-middle">
        <!-- Recent Orders -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">Recent Orders</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>Customer</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($dashboard['recent_orders'])): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">No customer orders recorded yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($dashboard['recent_orders'] as $order): ?>
                                    <tr>
                                        <td>
                                            <a href="<?= e(url('admin/orders/detail.php?id=' . $order['id'])) ?>" class="order-id text-primary fw-semibold">
                                                <?= e($order['order_number']) ?>
                                            </a>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <img src="<?= e(asset('admin/assets/img/avatars/avatar-1.webp')) ?>" alt="" class="order-avatar">
                                                <span><?= e($order['customer']) ?></span>
                                            </div>
                                        </td>
                                        <td class="fw-semibold"><?= e(format_money($order['total'])) ?></td>
                                        <td><span class="order-status <?= e($order['status_class']) ?>"><?= e(ucfirst(str_replace('_', ' ', $order['status']))) ?></span></td>
                                        <td><?= e(date('M j, Y', strtotime($order['created_at']))) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Stock Alerts -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">Stock Alerts</h5>
                <a href="<?= e(url('admin/inventory/')) ?>" class="btn btn-sm btn-outline-primary">View Inventory</a>
            </div>
            <div class="card-body">
                <div class="activity-feed">
                    <?php if (empty($dashboard['stock_alerts'])): ?>
                        <div class="activity-feed-item">
                            <div class="activity-feed-icon success">
                                <i class="bi bi-check-circle"></i>
                            </div>
                            <div class="activity-feed-content">
                                <div class="activity-feed-title">All product variants are sufficiently stocked.</div>
                                <div class="activity-feed-time">No active inventory alerts</div>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($dashboard['stock_alerts'] as $alert): ?>
                            <?php $isOut = (int)$alert['stock_qty'] <= 0; ?>
                            <div class="activity-feed-item">
                                <div class="activity-feed-icon <?= $isOut ? 'danger' : 'warning' ?>">
                                    <i class="bi <?= $isOut ? 'bi-x-circle' : 'bi-exclamation-triangle' ?>"></i>
                                </div>
                                <div class="activity-feed-content">
                                    <div class="activity-feed-title">
                                        <a href="<?= e(url('admin/inventory/?filter=' . ($isOut ? 'out_of_stock' : 'low_stock'))) ?>" class="text-reset text-decoration-none">
                                            <strong><?= $isOut ? 'Out of stock:' : 'Low stock:' ?></strong>
                                            <?= e($alert['product_name']) ?>
                                            <span class="text-muted">(<?= e($alert['sku'] . ' · ' . $alert['size'] . ' · ' . $alert['color']) ?>)</span>
                                        </a>
                                    </div>
                                    <div class="activity-feed-time">
                                        <span class="badge <?= $isOut ? 'bg-danger-subtle text-danger' : 'bg-warning-subtle text-warning' ?>">
                                            <?= e((string)$alert['stock_qty']) ?> in stock
                                        </span>
                                        (Threshold: <?= e((string)$alert['low_stock_threshold']) ?>)
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Bottom Row -->
    <div class="dashboard-bottom" style="grid-template-columns: 1fr;">
        <!-- Top Products -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">Top Products by Units Sold</h5>
                <a href="<?= e(url('admin/products/')) ?>" class="btn btn-sm btn-outline-primary">View Products</a>
            </div>
            <div class="card-body">
                <div class="product-list">
                    <?php if (empty($dashboard['top_products'])): ?>
                        <p class="text-muted text-center py-4 mb-0">No product sales recorded yet.</p>
                    <?php else: ?>
                        <?php foreach ($dashboard['top_products'] as $idx => $prod): ?>
                            <div class="product-item">
                                <div class="product-rank<?= $idx < 3 ? ' top' : '' ?>"><?= e((string)($idx + 1)) ?></div>
                                <?php if (!empty($prod['primary_image'])): ?>
                                    <a href="<?= e(url('admin/products/edit.php?id=' . $prod['product_id'])) ?>">
                                        <img src="<?= e(asset($prod['primary_image'])) ?>" alt="<?= e($prod['product_name']) ?>" style="width:36px; height:36px; object-fit:cover; border-radius:var(--radius-sm); flex-shrink:0;">
                                    </a>
                                <?php else: ?>
                                    <div style="width:36px; height:36px; background:var(--background-color); border-radius:var(--radius-sm); display:flex; align-items:center; justify-content:center; flex-shrink:0; color:var(--muted-color); font-size:14px;"><i class="bi bi-image"></i></div>
                                <?php endif; ?>
                                <div class="product-info">
                                    <div class="product-name">
                                        <a href="<?= e(url('admin/products/edit.php?id=' . $prod['product_id'])) ?>" class="text-reset text-decoration-none">
                                            <?= e($prod['product_name']) ?>
                                        </a>
                                    </div>
                                    <div class="product-category"><?= e(number_format($prod['units'])) ?> units sold</div>
                                </div>
                                <div class="product-sales">
                                    <div class="product-sales-value"><?= e(format_money($prod['revenue'])) ?></div>
                                    <div class="product-sales-count">Paid sales</div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require dirname(__DIR__, 2) . '/app/views/admin/footer.php'; ?>