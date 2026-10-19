<?php
require dirname(__DIR__, 3) . '/app/bootstrap.php';
require dirname(__DIR__, 3) . '/app/views/admin/guard.php';

use App\Services\OrderService;
use App\Services\Permissions;

Permissions::require('orders.view');

$rawId = $_GET['id'] ?? null;
if ($rawId === null || !is_numeric($rawId) || (int)$rawId <= 0) {
    http_response_code(404);
    require dirname(__DIR__, 3) . '/app/views/admin/error-404.php';
    exit;
}
$orderId = (int)$rawId;

$order = OrderService::find($orderId);
if (!$order) {
    http_response_code(404);
    require dirname(__DIR__, 3) . '/app/views/admin/error-404.php';
    exit;
}

$storeName = setting('store_name', 'ShopWise Kenya');
$storeTagline = setting('store_tagline', 'Quality everyday essentials, delivered across Kenya');
$supportEmail = setting('support_email', 'support@kennedycheroben.co.ke');
$supportPhone = setting('support_phone', '+254 700 000 000');
$pickupAddress = setting('pickup_address', 'Moi Avenue, Nairobi CBD');

$pageTitle = 'Packing Slip #' . $order['order_number'];
$activeGroup = 'sales';

require dirname(__DIR__, 3) . '/app/views/admin/layout.php';
?>

<style>
@media print {
    .header, .sidebar, .sidebar-overlay, .pagetitle, .btn, .breadcrumb, footer, .mobile-header-menu, .mobile-search {
        display: none !important;
    }
    .main {
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
    }
    .main-content {
        padding: 0 !important;
    }
    .card {
        border: none !important;
        box-shadow: none !important;
    }
    body {
        background: #ffffff !important;
        color: #000000 !important;
    }
}
</style>

<div class="main-content page-invoice">
    <!-- Header (hidden in print) -->
    <div class="pagetitle mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h1>Packing Slip #<?= e($order['order_number']) ?></h1>
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><a href="<?= e(url('admin/')) ?>">Home</a></li>
                        <li class="breadcrumb-item"><a href="<?= e(url('admin/orders/')) ?>">Orders</a></li>
                        <li class="breadcrumb-item"><a href="<?= e(url('admin/orders/detail.php?id=' . $order['id'])) ?>"><?= e($order['order_number']) ?></a></li>
                        <li class="breadcrumb-item active">Packing Slip</li>
                    </ol>
                </nav>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i> Print Packing Slip
                </button>
                <a href="<?= e(url('admin/orders/invoice.php?id=' . $order['id'])) ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-receipt me-1"></i> View Invoice
                </a>
                <a href="<?= e(url('admin/orders/detail.php?id=' . $order['id'])) ?>" class="btn btn-light btn-sm border">
                    <i class="bi bi-arrow-left me-1"></i> Back to Order
                </a>
            </div>
        </div>
    </div>

    <!-- Packing Slip Document Box -->
    <section class="section">
        <div class="row justify-content-center">
            <div class="col-12 col-xl-10">
                <div class="card invoice-document shadow-sm border">
                    <div class="card-body p-4 p-md-5">

                        <!-- Slip Masthead -->
                        <div class="d-flex flex-wrap justify-content-between align-items-start border-bottom pb-4 mb-4 gap-3">
                            <div class="invoice-brand">
                                <h2 class="h3 fw-bold text-dark mb-1"><?= e($storeName) ?></h2>
                                <p class="text-muted small mb-0"><?= e($storeTagline) ?></p>
                            </div>
                            <div class="invoice-meta text-md-end">
                                <div class="text-muted small text-uppercase fw-bold letter-spacing">Warehouse Packing Slip</div>
                                <div class="fs-4 fw-bold text-dark mb-1">#<?= e($order['order_number']) ?></div>
                                <span class="badge bg-light text-dark border px-3 py-1">
                                    Status: <?= e(ucwords(str_replace('_', ' ', $order['status']))) ?>
                                </span>
                            </div>
                        </div>

                        <!-- Address Block -->
                        <div class="row g-4 mb-4">
                            <div class="col-12 col-md-6">
                                <div class="border rounded p-3 h-100 bg-light">
                                    <div class="text-muted small fw-bold text-uppercase mb-2">Fulfillment Origin:</div>
                                    <h6 class="fw-bold text-dark mb-1"><?= e($storeName) ?> Dispatch Center</h6>
                                    <div class="text-muted small mb-2">
                                        <?= e($pickupAddress) ?><br>
                                        Nairobi, Kenya
                                    </div>
                                    <div class="small text-muted">
                                        <div>Support: <?= e($supportPhone) ?></div>
                                        <div>Email: <?= e($supportEmail) ?></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <div class="border rounded p-3 h-100">
                                    <div class="text-muted small fw-bold text-uppercase mb-2">Recipient & Delivery Destination:</div>
                                    <h6 class="fw-bold text-dark mb-1">
                                        <?= e($order['recipient_name'] ?: ($order['user_first_name'] . ' ' . $order['user_last_name'])) ?>
                                    </h6>
                                    <div class="text-muted small mb-2">
                                        <?php if ($order['delivery_method'] === 'pickup'): ?>
                                            <strong>Store Pickup Destination:</strong> <?= e($order['county'] ?: 'Nairobi CBD Main Hub') ?><br>
                                            <?= e($order['street_details'] ?: 'Collection upon notification') ?>
                                        <?php else: ?>
                                            <?= e($order['street_details'] ?: 'Address specified by recipient') ?><br>
                                            <?= e(implode(', ', array_filter([$order['area'], $order['county']]))) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="small">
                                        <div><i class="bi bi-telephone me-1 text-muted"></i> <strong>Recipient Phone:</strong> <?= e($order['phone']) ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Logistics Details -->
                        <div class="row g-3 p-3 bg-light rounded mb-4">
                            <div class="col-6 col-md-4">
                                <span class="text-muted smaller d-block text-uppercase">Order Date</span>
                                <strong class="text-dark small"><?= e(date('d M Y H:i', strtotime($order['created_at']))) ?></strong>
                            </div>
                            <div class="col-6 col-md-4">
                                <span class="text-muted smaller d-block text-uppercase">Fulfillment Method</span>
                                <strong class="text-dark small"><?= $order['delivery_method'] === 'pickup' ? 'Store Pickup' : 'Courier Dispatch' ?></strong>
                            </div>
                            <div class="col-12 col-md-4">
                                <span class="text-muted smaller d-block text-uppercase">Total Item Units</span>
                                <?php
                                $totalUnits = 0;
                                foreach ($order['items'] as $itm) {
                                    $totalUnits += (int)$itm['quantity'];
                                }
                                ?>
                                <strong class="text-dark small"><?= $totalUnits ?> units across <?= count($order['items']) ?> line items</strong>
                            </div>
                        </div>

                        <!-- Packing Checklist Table (No prices shown) -->
                        <div class="table-responsive mb-4">
                            <table class="table align-middle border">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 8%;" class="text-center">Packed</th>
                                        <th style="width: 5%;">#</th>
                                        <th style="width: 47%;">Product Name & Options</th>
                                        <th style="width: 25%;">SKU</th>
                                        <th style="width: 15%;" class="text-center">Qty to Pack</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $idx = 1; foreach ($order['items'] as $item): ?>
                                        <tr>
                                            <td class="text-center">
                                                <input class="form-check-input" type="checkbox" style="width: 1.25rem; height: 1.25rem;">
                                            </td>
                                            <td class="text-muted small"><?= $idx++ ?></td>
                                            <td>
                                                <div class="fw-semibold text-dark"><?= e($item['product_name']) ?></div>
                                                <div class="text-muted smaller">
                                                    <?php
                                                    $specs = [];
                                                    if (!empty($item['size'])) $specs[] = 'Size: ' . e($item['size']);
                                                    if (!empty($item['color'])) $specs[] = 'Color: ' . e($item['color']);
                                                    echo !empty($specs) ? implode(' &bull; ', $specs) : 'Standard item';
                                                    ?>
                                                </div>
                                            </td>
                                            <td><code class="text-secondary small"><?= e($item['sku']) ?></code></td>
                                            <td class="text-center fw-bold fs-6 text-dark"><?= (int)$item['quantity'] ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Special Customer Notes / Handling Instructions -->
                        <?php if (!empty($order['customer_notes'])): ?>
                            <div class="border rounded p-3 mb-4 bg-light">
                                <div class="text-muted small fw-bold text-uppercase mb-1">
                                    <i class="bi bi-info-circle me-1"></i> Customer Handling / Delivery Instructions:
                                </div>
                                <div class="text-dark small"><?= nl2br(e($order['customer_notes'])) ?></div>
                            </div>
                        <?php endif; ?>

                        <!-- Verification Sign-off Box -->
                        <div class="row g-4 pt-3 border-top">
                            <div class="col-6">
                                <div class="border-bottom pb-4 mb-1"></div>
                                <span class="text-muted smaller text-uppercase">Packed By (Warehouse Staff Signature & Date)</span>
                            </div>
                            <div class="col-6">
                                <div class="border-bottom pb-4 mb-1"></div>
                                <span class="text-muted smaller text-uppercase">Inspected / Dispatched By (Rider / Courier Sign)</span>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php
require dirname(__DIR__, 3) . '/app/views/admin/footer.php';
