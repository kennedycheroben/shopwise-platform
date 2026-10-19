<?php
require dirname(__DIR__, 3) . '/app/bootstrap.php';
require dirname(__DIR__, 3) . '/app/views/admin/guard.php';

use App\Services\OrderService;
use App\Services\Permissions;
use App\Services\AdminAuthService;

Permissions::require('orders.view');
$currentUser = AdminAuthService::user();

// Filters & Pagination
$filters = [
    'search'          => trim($_GET['search'] ?? ''),
    'status'          => trim($_GET['status'] ?? 'all'),
    'payment_status'  => trim($_GET['payment_status'] ?? 'all'),
    'delivery_method' => trim($_GET['delivery_method'] ?? ''),
    'start_date'      => trim($_GET['start_date'] ?? ''),
    'end_date'        => trim($_GET['end_date'] ?? ''),
    'sort'            => trim($_GET['sort'] ?? 'created_at'),
    'order'           => trim($_GET['order'] ?? 'desc'),
];

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$result = OrderService::paginate($filters, $page, $perPage);
$orders = $result['items'];
$totalItems = $result['total_items'];
$totalPages = $result['total_pages'];
$statusCounts = $result['status_counts'];
$isFiltered = $result['is_filtered'];
$totalOrders = $result['total_orders'];

$pageTitle = 'Orders';
$activeGroup = 'sales';

require dirname(__DIR__, 3) . '/app/views/admin/layout.php';
?>

<div class="main-content page-invoice-list">
    <!-- Page Title & Header -->
    <div class="pagetitle mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h1>Orders Management</h1>
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><a href="<?= e(url('admin/')) ?>">Home</a></li>
                        <li class="breadcrumb-item">Sales</li>
                        <li class="breadcrumb-item active">Orders</li>
                    </ol>
                </nav>
            </div>
            <div>
                <span class="text-muted small">Showing <?= count($orders) ?> of <?= number_format($totalItems) ?> orders</span>
            </div>
        </div>
    </div>

    <?php $flashSuccess = flash_get('success'); if ($flashSuccess): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i> <?= e($flashSuccess) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php $flashError = flash_get('error'); if ($flashError): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= e($flashError) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Status Chips Strip -->
    <div class="card mb-3">
        <div class="card-body py-2 px-3">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="text-muted small fw-semibold me-2"><i class="bi bi-funnel me-1"></i> Status:</span>
                <?php
                $chipList = [
                    'all'              => ['label' => 'All Orders', 'count' => $statusCounts['all'], 'badge' => 'bg-secondary'],
                    'pending_payment'  => ['label' => 'Pending Payment', 'count' => $statusCounts['pending_payment'], 'badge' => 'bg-warning text-dark'],
                    'paid'             => ['label' => 'Paid', 'count' => $statusCounts['paid'], 'badge' => 'bg-success'],
                    'processing'       => ['label' => 'Processing', 'count' => $statusCounts['processing'], 'badge' => 'bg-primary'],
                    'out_for_delivery' => ['label' => 'Out for Delivery', 'count' => $statusCounts['out_for_delivery'], 'badge' => 'bg-info text-dark'],
                    'delivered'        => ['label' => 'Delivered', 'count' => $statusCounts['delivered'], 'badge' => 'bg-success'],
                    'cancelled'        => ['label' => 'Cancelled', 'count' => $statusCounts['cancelled'], 'badge' => 'bg-danger'],
                    'expired'          => ['label' => 'Expired', 'count' => $statusCounts['expired'], 'badge' => 'bg-secondary'],
                    'refunded'         => ['label' => 'Refunded', 'count' => $statusCounts['refunded'], 'badge' => 'bg-dark'],
                ];
                foreach ($chipList as $sKey => $sInfo):
                    $isActive = ($filters['status'] === $sKey) || ($sKey === 'all' && ($filters['status'] === '' || $filters['status'] === 'all'));
                    $chipQuery = array_merge($filters, ['status' => $sKey, 'page' => 1]);
                ?>
                    <a href="<?= e(url('admin/orders/?' . http_build_query($chipQuery))) ?>"
                       class="btn btn-sm <?= $isActive ? 'btn-primary' : 'btn-outline-secondary' ?> rounded-pill py-1 px-3 d-inline-flex align-items-center gap-2">
                        <span><?= e($sInfo['label']) ?></span>
                        <span class="badge <?= $isActive ? 'bg-white text-primary' : $sInfo['badge'] ?> rounded-pill"><?= $sInfo['count'] ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Filter & Search Toolbar Card -->
    <div class="card mb-4">
        <div class="card-body py-3">
            <form action="<?= e(url('admin/orders/')) ?>" method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
                <input type="hidden" name="sort" value="<?= e($filters['sort']) ?>">
                <input type="hidden" name="order" value="<?= e($filters['order']) ?>">

                <!-- Search -->
                <div class="col-12 col-md-3">
                    <label class="form-label small text-muted mb-1">Search Orders</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-transparent text-muted"><i class="bi bi-search"></i></span>
                        <input type="text" name="search" class="form-control" placeholder="Order #, name, phone..." value="<?= e($filters['search']) ?>">
                    </div>
                </div>

                <!-- Payment Status -->
                <div class="col-6 col-md-2">
                    <label class="form-label small text-muted mb-1">Payment Status</label>
                    <select name="payment_status" class="form-select form-select-sm">
                        <option value="all">All Payments</option>
                        <option value="unpaid" <?= $filters['payment_status'] === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                        <option value="pending" <?= $filters['payment_status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="paid" <?= $filters['payment_status'] === 'paid' ? 'selected' : '' ?>>Paid</option>
                        <option value="failed" <?= $filters['payment_status'] === 'failed' ? 'selected' : '' ?>>Failed</option>
                        <option value="refunded" <?= $filters['payment_status'] === 'refunded' ? 'selected' : '' ?>>Refunded</option>
                    </select>
                </div>

                <!-- Delivery Method -->
                <div class="col-6 col-md-2">
                    <label class="form-label small text-muted mb-1">Delivery Method</label>
                    <select name="delivery_method" class="form-select form-select-sm">
                        <option value="">All Methods</option>
                        <option value="delivery" <?= $filters['delivery_method'] === 'delivery' ? 'selected' : '' ?>>Courier Delivery</option>
                        <option value="pickup" <?= $filters['delivery_method'] === 'pickup' ? 'selected' : '' ?>>Store Pickup</option>
                    </select>
                </div>

                <!-- Start Date -->
                <div class="col-6 col-md-2">
                    <label class="form-label small text-muted mb-1">From Date</label>
                    <input type="date" name="start_date" class="form-control form-control-sm" value="<?= e($filters['start_date']) ?>">
                </div>

                <!-- End Date -->
                <div class="col-6 col-md-2">
                    <label class="form-label small text-muted mb-1">To Date</label>
                    <input type="date" name="end_date" class="form-control form-control-sm" value="<?= e($filters['end_date']) ?>">
                </div>

                <!-- Filter Actions -->
                <div class="col-12 col-md-1 d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-primary w-100" title="Apply filters">
                        <i class="bi bi-filter"></i> Filter
                    </button>
                    <?php if ($isFiltered): ?>
                        <a href="<?= e(url('admin/orders/')) ?>" class="btn btn-sm btn-outline-secondary" title="Clear filters">
                            <i class="bi bi-x-lg"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Orders Table Card -->
    <div class="card invoice-list-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0">Orders List</h5>
            <span class="badge rounded-pill bg-light text-dark border"><?= number_format($totalItems) ?> records</span>
        </div>

        <div class="card-body p-0">
            <?php if (empty($orders)): ?>
                <?php if ($totalOrders === 0): ?>
                    <!-- Real Empty State: No orders in system -->
                    <div class="text-center py-5">
                        <div class="mb-3">
                            <i class="bi bi-cart-x text-muted" style="font-size: 3rem;"></i>
                        </div>
                        <h5 class="fw-semibold">No Orders Recorded Yet</h5>
                        <p class="text-muted max-w-sm mx-auto">Customer orders will appear here once purchases are placed on the platform.</p>
                    </div>
                <?php else: ?>
                    <!-- No Results State: Filter matched nothing -->
                    <div class="text-center py-5">
                        <div class="mb-3">
                            <i class="bi bi-search text-muted" style="font-size: 2.5rem;"></i>
                        </div>
                        <h5 class="fw-semibold">No Orders Matched Your Filters</h5>
                        <p class="text-muted mb-3">Try adjusting your search criteria, clearing the date range, or removing status filters.</p>
                        <a href="<?= e(url('admin/orders/')) ?>" class="btn btn-sm btn-primary">
                            <i class="bi bi-arrow-counterclockwise me-1"></i> Reset All Filters
                        </a>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table invoice-table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>
                                    <?php
                                    $nextOrder = ($filters['sort'] === 'order_number' && $filters['order'] === 'asc') ? 'desc' : 'asc';
                                    $sortUrl = url('admin/orders/?' . http_build_query(array_merge($filters, ['sort' => 'order_number', 'order' => $nextOrder])));
                                    ?>
                                    <a href="<?= e($sortUrl) ?>" class="text-reset text-decoration-none">
                                        Order #
                                        <?php if ($filters['sort'] === 'order_number'): ?>
                                            <i class="bi bi-arrow-<?= $filters['order'] === 'asc' ? 'up' : 'down' ?> small"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <?php
                                    $nextOrder = ($filters['sort'] === 'created_at' && $filters['order'] === 'asc') ? 'desc' : 'asc';
                                    $sortUrl = url('admin/orders/?' . http_build_query(array_merge($filters, ['sort' => 'created_at', 'order' => $nextOrder])));
                                    ?>
                                    <a href="<?= e($sortUrl) ?>" class="text-reset text-decoration-none">
                                        Date
                                        <?php if ($filters['sort'] === 'created_at'): ?>
                                            <i class="bi bi-arrow-<?= $filters['order'] === 'asc' ? 'up' : 'down' ?> small"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <?php
                                    $nextOrder = ($filters['sort'] === 'customer' && $filters['order'] === 'asc') ? 'desc' : 'asc';
                                    $sortUrl = url('admin/orders/?' . http_build_query(array_merge($filters, ['sort' => 'customer', 'order' => $nextOrder])));
                                    ?>
                                    <a href="<?= e($sortUrl) ?>" class="text-reset text-decoration-none">
                                        Customer
                                        <?php if ($filters['sort'] === 'customer'): ?>
                                            <i class="bi bi-arrow-<?= $filters['order'] === 'asc' ? 'up' : 'down' ?> small"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th class="text-center">Items</th>
                                <th class="text-end">
                                    <?php
                                    $nextOrder = ($filters['sort'] === 'total' && $filters['order'] === 'asc') ? 'desc' : 'asc';
                                    $sortUrl = url('admin/orders/?' . http_build_query(array_merge($filters, ['sort' => 'total', 'order' => $nextOrder])));
                                    ?>
                                    <a href="<?= e($sortUrl) ?>" class="text-reset text-decoration-none">
                                        Total (KES)
                                        <?php if ($filters['sort'] === 'total'): ?>
                                            <i class="bi bi-arrow-<?= $filters['order'] === 'asc' ? 'up' : 'down' ?> small"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th class="text-center">Payment</th>
                                <th class="text-center">Order Status</th>
                                <th class="text-center">Delivery</th>
                                <th class="text-end pe-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                                <?php
                                $statusBadgeClass = match ($order['status']) {
                                    'pending_payment'  => 'bg-warning-subtle text-warning border border-warning-subtle',
                                    'paid'             => 'bg-success-subtle text-success border border-success-subtle',
                                    'processing'       => 'bg-primary-subtle text-primary border border-primary-subtle',
                                    'out_for_delivery' => 'bg-info-subtle text-info border border-info-subtle',
                                    'delivered'        => 'bg-success-subtle text-success border border-success-subtle',
                                    'cancelled'        => 'bg-danger-subtle text-danger border border-danger-subtle',
                                    'expired'          => 'bg-secondary-subtle text-secondary border border-secondary-subtle',
                                    'refunded'         => 'bg-dark-subtle text-dark border border-dark-subtle',
                                    default            => 'bg-light text-muted',
                                };

                                $paymentBadgeClass = match ($order['payment_status']) {
                                    'paid'     => 'bg-success-subtle text-success',
                                    'pending'  => 'bg-warning-subtle text-warning',
                                    'unpaid'   => 'bg-secondary-subtle text-secondary',
                                    'failed'   => 'bg-danger-subtle text-danger',
                                    'refunded' => 'bg-dark-subtle text-dark',
                                    default    => 'bg-light text-muted',
                                };
                                ?>
                                <tr>
                                    <td>
                                        <a href="<?= e(url('admin/orders/detail.php?id=' . $order['id'])) ?>" class="fw-semibold text-primary">
                                            <?= e($order['order_number']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <div class="small fw-semibold"><?= e(date('d M Y', strtotime($order['created_at']))) ?></div>
                                        <div class="text-muted smaller"><?= e(date('H:i', strtotime($order['created_at']))) ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-medium text-dark"><?= e($order['recipient_name'] ?: ($order['user_customer_name'] ?: 'Guest Customer')) ?></div>
                                        <div class="text-muted small"><?= e($order['phone'] ?: ($order['customer_email'] ?: '')) ?></div>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge rounded-pill bg-light text-dark border"><?= (int)$order['items_count'] ?></span>
                                    </td>
                                    <td class="text-end fw-bold text-dark">
                                        <?= e(format_money((float)$order['total'])) ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?= $paymentBadgeClass ?> px-2 py-1">
                                            <?= e(ucfirst($order['payment_status'])) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?= $statusBadgeClass ?> px-2 py-1">
                                            <?= e(ucwords(str_replace('_', ' ', $order['status']))) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($order['delivery_method'] === 'pickup'): ?>
                                            <span class="badge bg-light text-secondary border" title="Store Pickup">
                                                <i class="bi bi-shop me-1"></i> Pickup
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-primary border" title="Courier Delivery">
                                                <i class="bi bi-truck me-1"></i> Delivery
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-3">
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                Actions
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                                <li>
                                                    <a class="dropdown-item" href="<?= e(url('admin/orders/detail.php?id=' . $order['id'])) ?>">
                                                        <i class="bi bi-eye me-2 text-primary"></i> View Order
                                                    </a>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item" href="<?= e(url('admin/orders/invoice.php?id=' . $order['id'])) ?>" target="_blank">
                                                        <i class="bi bi-receipt me-2 text-secondary"></i> Invoice
                                                    </a>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item" href="<?= e(url('admin/orders/packing-slip.php?id=' . $order['id'])) ?>" target="_blank">
                                                        <i class="bi bi-box-seam me-2 text-secondary"></i> Packing Slip
                                                    </a>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="card-footer d-flex flex-wrap justify-content-between align-items-center py-2 px-3 border-top">
                        <div class="text-muted small">
                            Page <?= $page ?> of <?= $totalPages ?> (<?= number_format($totalItems) ?> total orders)
                        </div>
                        <nav aria-label="Orders pagination">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= e(url('admin/orders/?' . http_build_query(array_merge($filters, ['page' => $page - 1])))) ?>">Previous</a>
                                </li>
                                <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= e(url('admin/orders/?' . http_build_query(array_merge($filters, ['page' => $p])))) ?>"><?= $p ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= e(url('admin/orders/?' . http_build_query(array_merge($filters, ['page' => $page + 1])))) ?>">Next</a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
require dirname(__DIR__, 3) . '/app/views/admin/footer.php';
