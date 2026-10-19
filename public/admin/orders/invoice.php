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
$businessHours = setting('business_hours', 'Mon-Sat, 8:00 am - 6:00 pm EAT');

// Latest M-Pesa receipt if available
$mpesaReceipt = null;
foreach ($order['payments'] as $pay) {
    if (!empty($pay['mpesa_receipt'])) {
        $mpesaReceipt = $pay['mpesa_receipt'];
        break;
    }
}

$pageTitle = 'Invoice #' . $order['order_number'];
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
    <!-- Page Header (hidden in print) -->
    <div class="pagetitle mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h1>Invoice #<?= e($order['order_number']) ?></h1>
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><a href="<?= e(url('admin/')) ?>">Home</a></li>
                        <li class="breadcrumb-item"><a href="<?= e(url('admin/orders/')) ?>">Orders</a></li>
                        <li class="breadcrumb-item"><a href="<?= e(url('admin/orders/detail.php?id=' . $order['id'])) ?>"><?= e($order['order_number']) ?></a></li>
                        <li class="breadcrumb-item active">Invoice</li>
                    </ol>
                </nav>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i> Print Invoice
                </button>
                <a href="<?= e(url('admin/orders/packing-slip.php?id=' . $order['id'])) ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-box-seam me-1"></i> View Packing Slip
                </a>
                <a href="<?= e(url('admin/orders/detail.php?id=' . $order['id'])) ?>" class="btn btn-light btn-sm border">
                    <i class="bi bi-arrow-left me-1"></i> Back to Order
                </a>
            </div>
        </div>
    </div>

    <!-- Invoice Document Box -->
    <section class="section">
        <div class="row justify-content-center">
            <div class="col-12 col-xl-10">
                <div class="card invoice-document shadow-sm border">
                    <div class="card-body p-4 p-md-5">

                        <!-- Invoice Masthead -->
                        <div class="d-flex flex-wrap justify-content-between align-items-start border-bottom pb-4 mb-4 gap-3">
                            <div class="invoice-brand">
                                <h2 class="h3 fw-bold text-dark mb-1"><?= e($storeName) ?></h2>
                                <p class="text-muted small mb-0"><?= e($storeTagline) ?></p>
                            </div>
                            <div class="invoice-meta text-md-end">
                                <div class="text-muted small text-uppercase fw-bold letter-spacing">Official Commercial Invoice</div>
                                <div class="fs-4 fw-bold text-primary mb-1">#<?= e($order['order_number']) ?></div>
                                <div>
                                    <?php
                                    $paymentBadge = match ($order['payment_status']) {
                                        'paid'     => 'bg-success text-white',
                                        'pending'  => 'bg-warning text-dark',
                                        'unpaid'   => 'bg-secondary text-white',
                                        'failed'   => 'bg-danger text-white',
                                        'refunded' => 'bg-dark text-white',
                                        default    => 'bg-light text-dark',
                                    };
                                    ?>
                                    <span class="badge <?= $paymentBadge ?> px-3 py-1">
                                        Payment: <?= e(strtoupper($order['payment_status'])) ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Address Block (From / Bill To) -->
                        <div class="row g-4 mb-4">
                            <div class="col-12 col-md-6">
                                <div class="border rounded p-3 h-100 bg-light">
                                    <div class="text-muted small fw-bold text-uppercase mb-2">Issued By:</div>
                                    <h6 class="fw-bold text-dark mb-1"><?= e($storeName) ?></h6>
                                    <div class="text-muted small mb-2">
                                        <?= e($pickupAddress) ?><br>
                                        Nairobi, Kenya
                                    </div>
                                    <div class="small">
                                        <div><i class="bi bi-envelope me-1 text-muted"></i> <?= e($supportEmail) ?></div>
                                        <div><i class="bi bi-telephone me-1 text-muted"></i> <?= e($supportPhone) ?></div>
                                        <div><i class="bi bi-clock me-1 text-muted"></i> <?= e($businessHours) ?></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <div class="border rounded p-3 h-100">
                                    <div class="text-muted small fw-bold text-uppercase mb-2">Billed & Shipped To:</div>
                                    <h6 class="fw-bold text-dark mb-1">
                                        <?= e($order['recipient_name'] ?: ($order['user_first_name'] . ' ' . $order['user_last_name'])) ?>
                                    </h6>
                                    <div class="text-muted small mb-2">
                                        <?php if ($order['delivery_method'] === 'pickup'): ?>
                                            <strong>Store Pickup:</strong> <?= e($order['county'] ?: 'Nairobi CBD Main Hub') ?><br>
                                            <?= e($order['street_details'] ?: 'Collection upon notification') ?>
                                        <?php else: ?>
                                            <?= e($order['street_details'] ?: 'Delivery address provided') ?><br>
                                            <?= e(implode(', ', array_filter([$order['area'], $order['county']]))) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="small">
                                        <div><i class="bi bi-telephone me-1 text-muted"></i> <?= e($order['phone']) ?></div>
                                        <?php if (!empty($order['user_email'])): ?>
                                            <div><i class="bi bi-envelope me-1 text-muted"></i> <?= e($order['user_email']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Invoice Dates & Payment Details -->
                        <div class="row g-3 p-3 bg-light rounded mb-4">
                            <div class="col-6 col-md-3">
                                <span class="text-muted smaller d-block text-uppercase">Invoice Date</span>
                                <strong class="text-dark small"><?= e(date('d M Y', strtotime($order['created_at']))) ?></strong>
                            </div>
                            <div class="col-6 col-md-3">
                                <span class="text-muted smaller d-block text-uppercase">Delivery Method</span>
                                <strong class="text-dark small"><?= $order['delivery_method'] === 'pickup' ? 'Store Pickup' : 'Courier Delivery' ?></strong>
                            </div>
                            <div class="col-6 col-md-3">
                                <span class="text-muted smaller d-block text-uppercase">Payment Method</span>
                                <strong class="text-dark small">M-Pesa Express (Lipa na M-Pesa)</strong>
                            </div>
                            <div class="col-6 col-md-3">
                                <span class="text-muted smaller d-block text-uppercase">M-Pesa Receipt</span>
                                <strong class="text-dark small"><?= e($mpesaReceipt ?: 'Pending / Unpaid') ?></strong>
                            </div>
                        </div>

                        <!-- Line Items Table -->
                        <div class="table-responsive mb-4">
                            <table class="table align-middle border">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 5%;">#</th>
                                        <th style="width: 45%;">Item Description</th>
                                        <th style="width: 15%;">SKU</th>
                                        <th style="width: 10%;" class="text-center">Qty</th>
                                        <th style="width: 12%;" class="text-end">Unit Price</th>
                                        <th style="width: 13%;" class="text-end pe-3">Line Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $idx = 1; foreach ($order['items'] as $item): ?>
                                        <tr>
                                            <td class="text-muted small"><?= $idx++ ?></td>
                                            <td>
                                                <div class="fw-semibold text-dark"><?= e($item['product_name']) ?></div>
                                                <div class="text-muted smaller">
                                                    <?php
                                                    $spec = [];
                                                    if (!empty($item['size'])) $spec[] = 'Size: ' . e($item['size']);
                                                    if (!empty($item['color'])) $spec[] = 'Color: ' . e($item['color']);
                                                    echo !empty($spec) ? implode(' &bull; ', $spec) : 'Standard';
                                                    ?>
                                                </div>
                                            </td>
                                            <td><code class="text-secondary small"><?= e($item['sku']) ?></code></td>
                                            <td class="text-center fw-medium"><?= (int)$item['quantity'] ?></td>
                                            <td class="text-end"><?= e(format_money((float)$item['unit_price'])) ?></td>
                                            <td class="text-end pe-3 fw-bold text-dark"><?= e(format_money((float)$item['line_total'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Totals Section -->
                        <div class="row justify-content-end mb-4">
                            <div class="col-12 col-md-5">
                                <div class="border rounded p-3 bg-light">
                                    <div class="d-flex justify-content-between py-1 text-muted small">
                                        <span>Items Subtotal:</span>
                                        <span class="fw-medium text-dark"><?= e(format_money((float)$order['subtotal'])) ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between py-1 text-muted small">
                                        <span>Delivery Fee:</span>
                                        <span class="fw-medium text-dark"><?= e(format_money((float)$order['delivery_fee'])) ?></span>
                                    </div>
                                    <?php if ((float)$order['discount'] > 0): ?>
                                        <div class="d-flex justify-content-between py-1 text-danger small">
                                            <span>Discount:</span>
                                            <span class="fw-medium">-<?= e(format_money((float)$order['discount'])) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <hr class="my-2">
                                    <div class="d-flex justify-content-between py-1 fs-5 fw-bold text-dark">
                                        <span>Grand Total:</span>
                                        <span class="text-primary"><?= e(format_money((float)$order['total'])) ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Invoice Footer / Support Notes -->
                        <div class="border-top pt-4 text-center text-muted small">
                            <p class="mb-1 fw-medium text-dark">Asante sana for shopping with <?= e($storeName) ?>!</p>
                            <p class="mb-0">
                                For inquiries or return requests within 7 days, please contact our Nairobi customer care team at
                                <a href="mailto:<?= e($supportEmail) ?>" class="text-decoration-none"><?= e($supportEmail) ?></a>
                                or call <strong><?= e($supportPhone) ?></strong>.
                            </p>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php
require dirname(__DIR__, 3) . '/app/views/admin/footer.php';
