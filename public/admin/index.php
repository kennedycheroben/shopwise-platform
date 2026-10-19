<?php
require dirname(__DIR__, 2) . '/app/bootstrap.php';
require dirname(__DIR__, 2) . '/app/views/admin/guard.php';
use App\Services\DashboardService;
$pageTitle = 'Dashboard';
$dashboard = DashboardService::data();

ob_start(); ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  // Chart colors from CSS variables
  const accentColor = getComputedStyle(document.documentElement).getPropertyValue('--accent-color').trim();
  const successColor = getComputedStyle(document.documentElement).getPropertyValue('--success-color').trim();
  const warningColor = getComputedStyle(document.documentElement).getPropertyValue('--warning-color').trim();
  const infoColor = getComputedStyle(document.documentElement).getPropertyValue('--info-color').trim();
  const borderColor = getComputedStyle(document.documentElement).getPropertyValue('--border-color').trim();
  const mutedColor = getComputedStyle(document.documentElement).getPropertyValue('--muted-color').trim();

  // Chart data from server
  const chartData = {
    monthly: {
      labels: <?= json_encode($dashboard['chart_monthly']['labels']) ?>,
      revenue: <?= json_encode($dashboard['chart_monthly']['revenue']) ?>,
      orders: <?= json_encode($dashboard['chart_monthly']['orders']) ?>
    },
    weekly: {
      labels: <?= json_encode($dashboard['chart_weekly']['labels']) ?>,
      revenue: <?= json_encode($dashboard['chart_weekly']['revenue']) ?>,
      orders: <?= json_encode($dashboard['chart_weekly']['orders']) ?>
    },
    daily: {
      labels: <?= json_encode($dashboard['chart_daily']['labels']) ?>,
      revenue: <?= json_encode($dashboard['chart_daily']['revenue']) ?>,
      orders: <?= json_encode($dashboard['chart_daily']['orders']) ?>
    }
  };

  let currentPeriod = 'monthly';

  // Revenue Overview Chart (ApexCharts Area)
  const revenueOptions = {
    series: [
      {
        name: 'Revenue (KES)',
        data: chartData.monthly.revenue
      },
      {
        name: 'Orders',
        data: chartData.monthly.orders
      }
    ],
    chart: {
      type: 'area',
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
      type: 'gradient',
      gradient: {
        shadeIntensity: 1,
        opacityFrom: 0.35,
        opacityTo: 0.05,
        stops: [0, 90, 100]
      }
    },
    dataLabels: { enabled: false },
    xaxis: {
      categories: chartData.monthly.labels,
      axisBorder: { show: false },
      axisTicks: { show: false },
      labels: { style: { colors: mutedColor, fontSize: '12px' } }
    },
    yaxis: [
      {
        title: { text: 'Revenue (KES)', style: { colors: mutedColor } },
        labels: {
          style: { colors: mutedColor, fontSize: '12px' },
          formatter: function(value) {
            return 'KES ' + (value / 1000).toFixed(1) + 'k';
          }
        }
      },
      {
        opposite: true,
        title: { text: 'Orders', style: { colors: mutedColor } },
        labels: {
          style: { colors: mutedColor, fontSize: '12px' },
          formatter: function(value) {
            return value.toLocaleString();
          }
        }
      }
    ],
    grid: { borderColor: borderColor, strokeDashArray: 4, xaxis: { lines: { show: false } } },
    legend: { show: true, position: 'top', horizontalAlign: 'right' },
    tooltip: {
      shared: true,
      y: {
        formatter: function(value, { seriesIndex }) {
          if (seriesIndex === 0) return 'KES ' + value.toLocaleString();
          return value + ' orders';
        }
      }
    }
  };
  const revenueChart = new ApexCharts(document.querySelector('#revenueChart'), revenueOptions);
  revenueChart.render();

  // Chart tab switching
  document.querySelectorAll('.chart-tab[data-period]').forEach(function(tab) {
    tab.addEventListener('click', function() {
      document.querySelectorAll('.chart-tab[data-period]').forEach(function(t) {
        t.classList.remove('active');
      });
      tab.classList.add('active');
      var period = tab.getAttribute('data-period');
      currentPeriod = period;
      var data = chartData[period];
      revenueChart.updateOptions({
        xaxis: { categories: data.labels },
        series: [
          { name: 'Revenue (KES)', data: data.revenue },
          { name: 'Orders', data: data.orders }
        ]
      }, true, true);

      // Update totals row
      const totalRevenue = data.revenue.reduce((a, b) => a + b, 0);
      const totalOrders = data.orders.reduce((a, b) => a + b, 0);
      const avgOrder = totalOrders > 0 ? totalRevenue / totalOrders : 0;
      document.getElementById('totalRevenue').textContent = 'KES ' + totalRevenue.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
      document.getElementById('totalOrders').textContent = totalOrders.toLocaleString();
      document.getElementById('avgOrderValue').textContent = 'KES ' + avgOrder.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    });
  });

  // Initial totals row
  const monthlyRevenue = chartData.monthly.revenue.reduce((a, b) => a + b, 0);
  const monthlyOrders = chartData.monthly.orders.reduce((a, b) => a + b, 0);
  const monthlyAvg = monthlyOrders > 0 ? monthlyRevenue / monthlyOrders : 0;
  document.getElementById('totalRevenue').textContent = 'KES ' + monthlyRevenue.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
  document.getElementById('totalOrders').textContent = monthlyOrders.toLocaleString();
  document.getElementById('avgOrderValue').textContent = 'KES ' + monthlyAvg.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});

  // Sales Breakdown Donut Chart
  const categoryData = <?= json_encode($dashboard['category_breakdown']) ?>;
  const breakdownOptions = {
    series: categoryData.map(c => parseFloat(c.revenue)),
    chart: { type: 'donut', height: 280, fontFamily: 'inherit' },
    colors: [accentColor, successColor, warningColor, infoColor, '#8b5cf6', '#ec4899', '#14b8a6', '#f97316'],
    labels: categoryData.map(c => c.category),
    plotOptions: {
      pie: {
        donut: {
          size: '72%',
          labels: {
            show: true,
            name: { fontSize: '13px', color: mutedColor },
            value: {
              fontSize: '1.5rem',
              fontWeight: 700,
              formatter: function(val) {
                return 'KES ' + Number(val).toLocaleString();
              }
            },
            total: {
              show: true,
              label: 'Total Revenue',
              color: mutedColor,
              fontSize: '13px',
              formatter: function(w) {
                return 'KES ' + w.globals.seriesTotals.reduce((a, b) => a + b, 0).toLocaleString();
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

  // Update charts on theme change
  document.addEventListener('themeChanged', function() {
    const newAccentColor = getComputedStyle(document.documentElement).getPropertyValue('--accent-color').trim();
    const newSuccessColor = getComputedStyle(document.documentElement).getPropertyValue('--success-color').trim();
    const newWarningColor = getComputedStyle(document.documentElement).getPropertyValue('--warning-color').trim();
    const newInfoColor = getComputedStyle(document.documentElement).getPropertyValue('--info-color').trim();
    const newBorderColor = getComputedStyle(document.documentElement).getPropertyValue('--border-color').trim();
    const newMutedColor = getComputedStyle(document.documentElement).getPropertyValue('--muted-color').trim();

    revenueChart.updateOptions({
      colors: [newAccentColor, newSuccessColor],
      grid: { borderColor: newBorderColor },
      xaxis: { labels: { style: { colors: newMutedColor } } },
      yaxis: [
        { labels: { style: { colors: newMutedColor } } },
        { labels: { style: { colors: newMutedColor } } }
      ],
      dropShadow: { color: newAccentColor }
    });

    breakdownChart.updateOptions({
      colors: [newAccentColor, newSuccessColor, newWarningColor, newInfoColor, '#8b5cf6', '#ec4899', '#14b8a6', '#f97316'],
      plotOptions: {
        pie: {
          donut: {
            labels: {
              name: { color: newMutedColor },
              total: { color: newMutedColor }
            }
          }
        }
      },
      stroke: { colors: ['var(--card-bg)'] }
    });
  });
});
</script>
<?php $pageScripts = ob_get_clean(); require dirname(__DIR__, 2) . '/app/views/admin/layout.php'; ?>
<div class="main-content page-dashboard">
    <!-- Metric Cards -->
    <div class="metric-cards">
        <div class="metric-card">
            <div class="metric-card-icon accent">
                <i class="bi bi-currency-dollar"></i>
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
                        <button class="chart-tab active" data-period="monthly">Monthly</button>
                        <button class="chart-tab" data-period="weekly">Weekly</button>
                        <button class="chart-tab" data-period="daily">Daily</button>
                    </div>
                </div>
                <div class="revenue-stats">
                    <div class="revenue-stat">
                        <span class="revenue-stat-dot accent"></span>
                        <div class="revenue-stat-body">
                            <div class="revenue-stat-label">Total Revenue</div>
                            <div class="revenue-stat-value" id="totalRevenue">KES 0.00</div>
                        </div>
                    </div>
                    <div class="revenue-stat">
                        <span class="revenue-stat-dot success"></span>
                        <div class="revenue-stat-body">
                            <div class="revenue-stat-label">Total Orders</div>
                            <div class="revenue-stat-value" id="totalOrders">0</div>
                        </div>
                    </div>
                    <div class="revenue-stat">
                        <span class="revenue-stat-dot warning"></span>
                        <div class="revenue-stat-body">
                            <div class="revenue-stat-label">Avg. Order Value</div>
                            <div class="revenue-stat-value" id="avgOrderValue">KES 0.00</div>
                        </div>
                    </div>
                </div>
                <div class="chart-container" id="revenueChart"></div>
            </div>
        </div>

        <!-- Sales Breakdown -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">Sales Breakdown by Category</h5>
            </div>
            <div class="card-body">
                <div class="chart-container" id="salesBreakdownChart"></div>
                <div class="breakdown-legend">
                    <?php foreach ($dashboard['category_breakdown'] as $cat): ?>
                        <div class="breakdown-legend-item">
                            <span class="breakdown-dot"></span>
                            <span class="breakdown-label"><?= e($cat['category']) ?></span>
                            <span class="breakdown-value"><?= e(format_money($cat['revenue'])) ?></span>
                        </div>
                    <?php endforeach; ?>
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
                <div class="card-actions">
                    <a href="#" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
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
                            <?php if (!$dashboard['recent_orders']): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">No orders yet</td>
                                </tr>
                            <?php else: foreach ($dashboard['recent_orders'] as $order): ?>
                                <tr>
                                    <td><span class="order-id"><?= e($order['order_number']) ?></span></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <img src="<?= e(asset('admin/assets/img/avatars/avatar-1.webp')) ?>" alt="" class="order-avatar">
                                            <span><?= e($order['customer']) ?></span>
                                        </div>
                                    </td>
                                    <td class="fw-semibold"><?= e(format_money($order['total'])) ?></td>
                                    <td><span class="order-status <?= e($order['status']) ?>"><?= e(ucfirst(str_replace('_', ' ', $order['status']))) ?></span></td>
                                    <td><?= e(date('M j, Y', strtotime($order['created_at']))) ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Stock Alerts -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">Stock Alerts</h5>
                <div class="card-actions">
                    <a href="#" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
            </div>
            <div class="card-body">
                <div class="activity-feed">
                    <?php if (!$dashboard['stock_alerts']): ?>
                        <div class="activity-feed-item">
                            <div class="activity-feed-icon success">
                                <i class="bi bi-check-circle"></i>
                            </div>
                            <div class="activity-feed-content">
                                <div class="activity-feed-title">All variants are above their low-stock thresholds</div>
                                <div class="activity-feed-time">Just now</div>
                            </div>
                        </div>
                    <?php else: foreach ($dashboard['stock_alerts'] as $alert): ?>
                        <div class="activity-feed-item">
                            <div class="activity-feed-icon warning">
                                <i class="bi bi-exclamation-triangle"></i>
                            </div>
                            <div class="activity-feed-content">
                                <div class="activity-feed-title">Low stock: <strong><?= e($alert['product_name']) ?></strong> (<?= e($alert['sku']) ?>)</div>
                                <div class="activity-feed-time"><?= e($alert['stock_qty']) ?> in stock (threshold: <?= e($alert['low_stock_threshold']) ?>)</div>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Bottom Row -->
    <div class="dashboard-bottom">
        <!-- Top Products -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">Top Products by Units Sold</h5>
                <div class="card-actions">
                    <a href="#" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
            </div>
            <div class="card-body">
                <div class="product-list">
                    <?php if (!$dashboard['top_products']): ?>
                        <p class="text-muted mb-0">No sales data yet</p>
                    <?php else: foreach ($dashboard['top_products'] as $index => $product): ?>
                        <div class="product-item">
                            <div class="product-rank<?= $index < 3 ? ' top' : '' ?>"><?= e((string) ($index + 1)) ?></div>
                            <div class="product-info">
                                <div class="product-name"><?= e($product['product_name']) ?></div>
                                <div class="product-category"><?= e((string) $product['units']) ?> units sold</div>
                            </div>
                            <div class="product-sales">
                                <div class="product-sales-value"><?= e(format_money($product['revenue'])) ?></div>
                                <div class="product-sales-count">Revenue</div>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Extra Row removed: Traffic Sources, Recent Transactions, Team Members (no real data) -->
</div>
<?php require dirname(__DIR__, 2) . '/app/views/admin/footer.php'; ?>