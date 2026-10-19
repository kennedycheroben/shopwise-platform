<?php
require dirname(__DIR__, 3) . '/app/bootstrap.php';
require dirname(__DIR__, 3) . '/app/views/admin/guard.php';

use App\Services\OrderService;
use App\Services\Permissions;
use App\Services\AdminAuthService;

Permissions::require('orders.view');
$currentUser = AdminAuthService::user();
$actorId = (int)$currentUser['id'];
$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

$orderId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($orderId <= 0) {
    redirect('admin/orders/');
}

// Handle POST actions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_verify($_POST['_csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Forbidden: Invalid CSRF token.');
    }

    $action = $_POST['action'] ?? '';
    $expectedFrom = trim($_POST['expected_from'] ?? '');

    try {
        if ($action === 'transition') {
            $toStatus = trim($_POST['to_status'] ?? '');
            $note = trim($_POST['note'] ?? '');
            OrderService::transition($orderId, $toStatus, $actorId, $note ?: null, $expectedFrom ?: null, $ip);
            flash_set('success', "Order status successfully updated to " . ucwords(str_replace('_', ' ', $toStatus)) . ".");
        } elseif ($action === 'cancel') {
            $note = trim($_POST['note'] ?? '');
            OrderService::cancel($orderId, $actorId, $note, $expectedFrom ?: null, false, $ip);
            flash_set('success', "Order has been cancelled and stock has been restored.");
        } elseif ($action === 'mark_as_paid') {
            $receipt = trim($_POST['mpesa_receipt'] ?? '');
            $note = trim($_POST['note'] ?? '');
            OrderService::markAsPaid($orderId, $receipt, $note, $actorId, $ip);
            flash_set('success', "Order successfully marked as paid with M-Pesa receipt {$receipt}.");
        } elseif ($action === 'record_refund') {
            $referenceNote = trim($_POST['reference_note'] ?? '');
            OrderService::recordRefund($orderId, $referenceNote, $actorId, $ip);
            flash_set('success', "Refund of order successfully recorded.");
        } elseif ($action === 'add_note') {
            $note = trim($_POST['internal_note'] ?? '');
            OrderService::addInternalNote($orderId, $note, $actorId, $ip);
            flash_set('success', "Internal note recorded in order timeline.");
        } else {
            flash_set('error', 'Unrecognized order action requested.');
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }

    redirect('admin/orders/detail.php?id=' . $orderId);
}

$order = OrderService::find($orderId);
if (!$order) {
    http_response_code(404);
    require dirname(__DIR__, 3) . '/app/views/admin/error-404.php';
    exit;
}

$pageTitle = 'Order #' . $order['order_number'];
$activeGroup = 'sales';

$isAdmin = ($currentUser['role'] ?? '') === 'admin';
$canManage = Permissions::can('orders.manage');
$canRefund = Permissions::can('orders.refund');

require dirname(__DIR__, 3) . '/app/views/admin/layout.php';
?>

<div class="main-content page-invoice">
    <!-- Header with Breadcrumbs & Action Buttons -->
    <div class="pagetitle mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h1>Order #<?= e($order['order_number']) ?></h1>
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><a href="<?= e(url('admin/')) ?>">Home</a></li>
                        <li class="breadcrumb-item"><a href="<?= e(url('admin/orders/')) ?>">Orders</a></li>
                        <li class="breadcrumb-item active"><?= e($order['order_number']) ?></li>
                    </ol>
                </nav>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="<?= e(url('admin/orders/invoice.php?id=' . $order['id'])) ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-printer me-1"></i> Print Invoice
                </a>
                <a href="<?= e(url('admin/orders/packing-slip.php?id=' . $order['id'])) ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-box-seam me-1"></i> Packing Slip
                </a>
                <a href="<?= e(url('admin/orders/')) ?>" class="btn btn-light btn-sm border">
                    <i class="bi bi-arrow-left me-1"></i> Back to Orders
                </a>
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

    <!-- Refund Required Banner for Paid Cancelled Orders -->
    <?php if ($order['status'] === 'cancelled' && $order['payment_status'] === 'paid'): ?>
        <div class="alert alert-danger border-2 d-flex align-items-center justify-content-between p-3 mb-4 shadow-sm" role="alert">
            <div class="d-flex align-items-center gap-3">
                <i class="bi bi-exclamation-octagon-fill text-danger fs-3"></i>
                <div>
                    <h5 class="alert-heading mb-1 text-danger fw-bold">Action Required: Customer Refund Pending</h5>
                    <p class="mb-0 text-dark">This order was cancelled after payment of <strong><?= e(format_money((float)$order['total'])) ?></strong> was captured. An M-Pesa manual refund or reversal must be recorded by an administrator.</p>
                </div>
            </div>
            <?php if ($canRefund): ?>
                <button type="button" class="btn btn-danger btn-sm text-nowrap" data-bs-toggle="modal" data-bs-target="#refundModal">
                    <i class="bi bi-arrow-counterclockwise me-1"></i> Record Refund
                </button>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Status Overview Header Card -->
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="p-3 rounded-circle bg-light text-primary">
                        <i class="bi bi-bag-check fs-2"></i>
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="fs-4 fw-bold text-dark">#<?= e($order['order_number']) ?></span>
                            <?php
                            $statusBadgeClass = match ($order['status']) {
                                'pending_payment'  => 'bg-warning text-dark',
                                'paid'             => 'bg-success text-white',
                                'processing'       => 'bg-primary text-white',
                                'out_for_delivery' => 'bg-info text-dark',
                                'delivered'        => 'bg-success text-white',
                                'cancelled'        => 'bg-danger text-white',
                                'expired'          => 'bg-secondary text-white',
                                'refunded'         => 'bg-dark text-white',
                                default            => 'bg-light text-muted',
                            };
                            $paymentBadgeClass = match ($order['payment_status']) {
                                'paid'     => 'bg-success-subtle text-success border border-success-subtle',
                                'pending'  => 'bg-warning-subtle text-warning border border-warning-subtle',
                                'unpaid'   => 'bg-secondary-subtle text-secondary border border-secondary-subtle',
                                'failed'   => 'bg-danger-subtle text-danger border border-danger-subtle',
                                'refunded' => 'bg-dark-subtle text-dark border border-dark-subtle',
                                default    => 'bg-light text-muted',
                            };
                            ?>
                            <span class="badge <?= $statusBadgeClass ?> px-3 py-1 fs-6">
                                <?= e(ucwords(str_replace('_', ' ', $order['status']))) ?>
                            </span>
                            <span class="badge <?= $paymentBadgeClass ?> px-3 py-1 fs-6">
                                Payment: <?= e(ucfirst($order['payment_status'])) ?>
                            </span>
                        </div>
                        <div class="text-muted small">
                            Placed on <?= e(date('l, F j, Y \a\t g:i A', strtotime($order['created_at']))) ?>
                            <?php if ($order['updated_at'] !== $order['created_at']): ?>
                                &bull; Updated <?= e(date('M j, Y g:i A', strtotime($order['updated_at']))) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Action Panel Buttons -->
                <div class="d-flex flex-wrap gap-2">
                    <?php if ($order['status'] === 'pending_payment'): ?>
                        <?php if ($isAdmin): ?>
                            <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#markPaidModal">
                                <i class="bi bi-cash-stack me-1"></i> Mark as Paid
                            </button>
                        <?php endif; ?>
                        <?php if ($canManage): ?>
                            <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#cancelModal">
                                <i class="bi bi-x-circle me-1"></i> Cancel Order
                            </button>
                        <?php endif; ?>
                    <?php elseif ($order['status'] === 'paid'): ?>
                        <?php if ($canManage): ?>
                            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#transitionModal" data-to="processing" data-label="Processing">
                                <i class="bi bi-gear me-1"></i> Move to Processing
                            </button>
                            <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#cancelModal">
                                <i class="bi bi-x-circle me-1"></i> Cancel Order
                            </button>
                        <?php endif; ?>
                    <?php elseif ($order['status'] === 'processing'): ?>
                        <?php if ($canManage): ?>
                            <button type="button" class="btn btn-info btn-sm text-dark" data-bs-toggle="modal" data-bs-target="#transitionModal" data-to="out_for_delivery" data-label="Out for Delivery">
                                <i class="bi bi-truck me-1"></i> Out for Delivery
                            </button>
                            <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#cancelModal">
                                <i class="bi bi-x-circle me-1"></i> Cancel Order
                            </button>
                        <?php endif; ?>
                    <?php elseif ($order['status'] === 'out_for_delivery'): ?>
                        <?php if ($canManage): ?>
                            <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#transitionModal" data-to="delivered" data-label="Delivered">
                                <i class="bi bi-check2-circle me-1"></i> Mark as Delivered
                            </button>
                        <?php endif; ?>
                    <?php elseif ($order['status'] === 'delivered'): ?>
                        <?php if ($canRefund): ?>
                            <button type="button" class="btn btn-outline-dark btn-sm" data-bs-toggle="modal" data-bs-target="#refundModal">
                                <i class="bi bi-arrow-counterclockwise me-1"></i> Record Refund
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Add Note (Available in all statuses) -->
                    <button type="button" class="btn btn-light btn-sm border" data-bs-toggle="modal" data-bs-target="#addNoteModal">
                        <i class="bi bi-chat-left-text me-1"></i> Add Note
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div class="row g-4">
        <!-- Left Column: Items & Payments (col-lg-8) -->
        <div class="col-lg-8">
            <!-- Order Items Card -->
            <div class="card mb-4 shadow-sm border-0">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="card-title mb-0 d-flex align-items-center gap-2">
                        <i class="bi bi-cart3 text-primary"></i>
                        Purchased Items
                        <span class="badge bg-light text-dark border"><?= count($order['items']) ?> lines</span>
                    </h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Item & Specifications</th>
                                    <th>SKU</th>
                                    <th class="text-end">Unit Price</th>
                                    <th class="text-center">Qty</th>
                                    <th class="text-end pe-3">Line Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($order['items'] as $item): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold text-dark"><?= e($item['product_name']) ?></div>
                                            <div class="text-muted small">
                                                <?php
                                                $specs = [];
                                                if (!empty($item['size'])) $specs[] = 'Size: ' . e($item['size']);
                                                if (!empty($item['color'])) $specs[] = 'Color: ' . e($item['color']);
                                                echo !empty($specs) ? implode(' &bull; ', $specs) : 'Standard variant';
                                                ?>
                                            </div>
                                        </td>
                                        <td><code class="text-secondary"><?= e($item['sku']) ?></code></td>
                                        <td class="text-end"><?= e(format_money((float)$item['unit_price'])) ?></td>
                                        <td class="text-center fw-semibold"><?= (int)$item['quantity'] ?></td>
                                        <td class="text-end pe-3 fw-bold text-dark"><?= e(format_money((float)$item['line_total'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Totals Block -->
                <div class="card-footer bg-light p-3">
                    <div class="row justify-content-end">
                        <div class="col-12 col-md-6 col-lg-5">
                            <div class="d-flex justify-content-between py-1 text-muted small">
                                <span>Subtotal:</span>
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
            </div>

            <!-- Payments History Card -->
            <div class="card mb-4 shadow-sm border-0">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0 d-flex align-items-center gap-2">
                        <i class="bi bi-credit-card text-primary"></i>
                        Payments & M-Pesa Transactions
                    </h5>
                    <span class="badge bg-light text-dark border"><?= count($order['payments']) ?> records</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($order['payments'])): ?>
                        <div class="text-center py-4 text-muted">
                            <i class="bi bi-wallet2 d-block fs-3 mb-1"></i>
                            No payment transactions recorded for this order.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Method</th>
                                        <th>Status</th>
                                        <th>Amount</th>
                                        <th>Phone</th>
                                        <th>M-Pesa Receipt</th>
                                        <th>Result Description</th>
                                        <th>Date & Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($order['payments'] as $pay): ?>
                                        <?php
                                        $payBadge = match ($pay['status']) {
                                            'success'   => 'bg-success-subtle text-success',
                                            'pending'   => 'bg-warning-subtle text-warning',
                                            'initiated' => 'bg-info-subtle text-info',
                                            'failed'    => 'bg-danger-subtle text-danger',
                                            'cancelled' => 'bg-danger-subtle text-danger',
                                            'timeout'   => 'bg-secondary-subtle text-secondary',
                                            default     => 'bg-light text-muted',
                                        };
                                        ?>
                                        <tr>
                                            <td><span class="fw-semibold text-uppercase"><?= e($pay['method']) ?></span></td>
                                            <td><span class="badge <?= $payBadge ?>"><?= e(ucfirst($pay['status'])) ?></span></td>
                                            <td class="fw-bold"><?= e(format_money((float)$pay['amount'])) ?></td>
                                            <td><code><?= e($pay['phone']) ?></code></td>
                                            <td>
                                                <?php if (!empty($pay['mpesa_receipt'])): ?>
                                                    <span class="badge bg-light text-dark border fw-bold"><?= e($pay['mpesa_receipt']) ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted small">&mdash;</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="small text-muted"><?= e($pay['result_desc'] ?: '&mdash;') ?></td>
                                            <td class="small text-muted"><?= e(date('d M Y H:i:s', strtotime($pay['created_at']))) ?></td>
                                        </tr>
                                        <?php if ($isAdmin && !empty($pay['raw_callback'])): ?>
                                            <tr>
                                                <td colspan="7" class="bg-light p-2 ps-4">
                                                    <details>
                                                        <summary class="small text-muted text-decoration-none" style="cursor: pointer;">
                                                            <i class="bi bi-code-slash me-1"></i> View Raw Daraja Callback Payload (Admin Only)
                                                        </summary>
                                                        <pre class="small bg-white p-2 mt-2 border rounded mb-0" style="max-height: 200px; overflow: auto;"><?= e($pay['raw_callback']) ?></pre>
                                                    </details>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Customer Notes -->
            <?php if (!empty($order['customer_notes'])): ?>
                <div class="card mb-4 shadow-sm border-0">
                    <div class="card-header bg-white py-3 border-bottom">
                        <h5 class="card-title mb-0 d-flex align-items-center gap-2">
                            <i class="bi bi-sticky text-warning"></i>
                            Customer Delivery Notes
                        </h5>
                    </div>
                    <div class="card-body">
                        <blockquote class="blockquote mb-0 fs-6 text-muted">
                            <p class="mb-0"><?= nl2br(e($order['customer_notes'])) ?></p>
                        </blockquote>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Right Column: Customer Card, Delivery Card, Timeline (col-lg-4) -->
        <div class="col-lg-4">
            <!-- Customer Card -->
            <div class="card mb-4 shadow-sm border-0">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0 d-flex align-items-center gap-2">
                        <i class="bi bi-person text-primary"></i>
                        Customer Details
                    </h5>
                    <?php if (!empty($order['user_id'])): ?>
                        <a href="<?= e(url('admin/customers/detail.php?id=' . $order['user_id'])) ?>" class="btn btn-sm btn-link p-0 text-decoration-none">
                            View Profile &rarr;
                        </a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div class="mb-2">
                        <span class="text-muted small d-block">Customer Name:</span>
                        <strong class="text-dark fs-6"><?= e($order['recipient_name'] ?: ($order['user_first_name'] . ' ' . $order['user_last_name'])) ?></strong>
                    </div>
                    <div class="mb-2">
                        <span class="text-muted small d-block">Phone Number:</span>
                        <a href="tel:<?= e($order['phone']) ?>" class="text-decoration-none text-dark fw-medium">
                            <i class="bi bi-telephone me-1 text-muted"></i> <?= e($order['phone']) ?>
                        </a>
                    </div>
                    <?php if (!empty($order['user_email'])): ?>
                        <div class="mb-2">
                            <span class="text-muted small d-block">Account Email:</span>
                            <a href="mailto:<?= e($order['user_email']) ?>" class="text-decoration-none text-dark">
                                <i class="bi bi-envelope me-1 text-muted"></i> <?= e($order['user_email']) ?>
                            </a>
                        </div>
                    <?php endif; ?>
                    <div class="mb-0">
                        <span class="text-muted small d-block">Account Status:</span>
                        <?php if (!empty($order['user_id'])): ?>
                            <span class="badge <?= ($order['user_status'] ?? '') === 'active' ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' ?>">
                                <?= e(ucfirst($order['user_status'] ?? 'active')) ?> Customer
                            </span>
                        <?php else: ?>
                            <span class="badge bg-light text-muted border">Guest Checkout</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Delivery Card -->
            <div class="card mb-4 shadow-sm border-0">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="card-title mb-0 d-flex align-items-center gap-2">
                        <i class="bi bi-truck text-primary"></i>
                        Fulfillment & Delivery
                    </h5>
                </div>
                <div class="card-body">
                    <div class="mb-2">
                        <span class="text-muted small d-block">Method:</span>
                        <span class="badge bg-light text-dark border px-2 py-1">
                            <?= $order['delivery_method'] === 'pickup' ? 'Store Pickup' : 'Courier Delivery' ?>
                        </span>
                        <span class="badge bg-light text-muted border ms-1">
                            Pricing: <?= e(ucfirst($order['delivery_pricing_method'] ?? 'zone')) ?>
                        </span>
                    </div>

                    <?php if ($order['delivery_method'] === 'pickup'): ?>
                        <div class="mb-2">
                            <span class="text-muted small d-block">Pickup Location:</span>
                            <div class="fw-medium text-dark"><?= e($order['county'] ?: 'ShopWise Fulfillment Hub') ?></div>
                            <div class="text-muted small"><?= e($order['street_details'] ?: 'Nairobi CBD Main Store') ?></div>
                        </div>
                    <?php else: ?>
                        <div class="mb-2">
                            <span class="text-muted small d-block">Destination Address:</span>
                            <div class="fw-medium text-dark"><?= e($order['street_details'] ?: 'Address not specified') ?></div>
                            <div class="text-muted small">
                                <?= e(implode(', ', array_filter([$order['area'], $order['county']]))) ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($order['delivery_distance_km'])): ?>
                        <div class="mb-2">
                            <span class="text-muted small d-block">Estimated Distance:</span>
                            <span class="fw-medium text-dark"><?= e($order['delivery_distance_km']) ?> km</span>
                        </div>
                    <?php endif; ?>

                    <!-- OpenStreetMap link strictly if coordinates exist -->
                    <?php if (!empty($order['latitude']) && !empty($order['longitude'])): ?>
                        <?php
                        $lat = (float)$order['latitude'];
                        $lon = (float)$order['longitude'];
                        $osmUrl = "https://www.openstreetmap.org/?mlat={$lat}&mlon={$lon}#map=16/{$lat}/{$lon}";
                        ?>
                        <div class="mt-3 pt-2 border-top">
                            <a href="<?= e($osmUrl) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary w-100">
                                <i class="bi bi-geo-alt me-1"></i> Open in OpenStreetMap
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Order Timeline Card -->
            <div class="card mb-4 shadow-sm border-0">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="card-title mb-0 d-flex align-items-center gap-2">
                        <i class="bi bi-clock-history text-primary"></i>
                        Status Timeline
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($order['history'])): ?>
                        <div class="text-muted small text-center py-3">No status history recorded.</div>
                    <?php else: ?>
                        <div class="timeline-stream">
                            <?php foreach ($order['history'] as $hist): ?>
                                <?php
                                $isInternalNote = ($hist['from_status'] === $hist['to_status']);
                                $dotColor = $isInternalNote ? 'warning' : 'primary';
                                ?>
                                <div class="timeline-entry mb-3 d-flex gap-3">
                                    <div class="timeline-track d-flex flex-column align-items-center">
                                        <div class="rounded-circle bg-<?= $dotColor ?> text-white d-flex align-items-center justify-content-center" style="width: 24px; height: 24px; font-size: 11px;">
                                            <i class="bi <?= $isInternalNote ? 'bi-chat-dots' : 'bi-check' ?>"></i>
                                        </div>
                                        <div class="border-start flex-grow-1 my-1" style="width: 1px; min-height: 20px;"></div>
                                    </div>
                                    <div class="timeline-body flex-grow-1 pb-2">
                                        <div class="d-flex justify-content-between align-items-baseline mb-1">
                                            <strong class="text-dark small">
                                                <?php if ($isInternalNote): ?>
                                                    Internal Note
                                                <?php else: ?>
                                                    <?= e(ucwords(str_replace('_', ' ', $hist['from_status'] ?: 'Created'))) ?>
                                                    &rarr;
                                                    <?= e(ucwords(str_replace('_', ' ', $hist['to_status']))) ?>
                                                <?php endif; ?>
                                            </strong>
                                            <span class="text-muted smaller"><?= e(date('d M, H:i', strtotime($hist['created_at']))) ?></span>
                                        </div>
                                        <?php if (!empty($hist['note'])): ?>
                                            <p class="mb-1 text-muted small bg-light p-2 rounded"><?= e($hist['note']) ?></p>
                                        <?php endif; ?>
                                        <div class="text-muted smaller">
                                            By: <?= e($hist['actor_name'] ?: 'System / Customer') ?>
                                            <?php if (!empty($hist['actor_role'])): ?>
                                                (<?= e(ucfirst($hist['actor_role'])) ?>)
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ==================== ACTION MODALS ==================== -->

<!-- Transition Modal -->
<div class="modal fade" id="transitionModal" tabindex="-1" aria-labelledby="transitionModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form action="<?= e(url('admin/orders/detail.php')) ?>" method="POST" class="modal-content">
            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="transition">
            <input type="hidden" name="id" value="<?= $order['id'] ?>">
            <input type="hidden" name="expected_from" value="<?= e($order['status']) ?>">
            <input type="hidden" name="to_status" id="modalToStatus" value="">

            <div class="modal-header">
                <h5 class="modal-title" id="transitionModalLabel">Update Order Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to transition this order to <strong id="modalStatusLabel" class="text-primary"></strong>?</p>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Note for Customer & Timeline (Optional):</label>
                    <textarea name="note" class="form-control" rows="3" placeholder="Add an optional comment or tracking instruction..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm">Confirm Status Change</button>
            </div>
        </form>
    </div>
</div>

<!-- Cancel Modal -->
<div class="modal fade" id="cancelModal" tabindex="-1" aria-labelledby="cancelModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form action="<?= e(url('admin/orders/detail.php')) ?>" method="POST" class="modal-content">
            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="id" value="<?= $order['id'] ?>">
            <input type="hidden" name="expected_from" value="<?= e($order['status']) ?>">

            <div class="modal-header bg-danger-subtle">
                <h5 class="modal-title text-danger fw-bold" id="cancelModalLabel">Cancel Order #<?= e($order['order_number']) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning small">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    Cancelling will restore reserved product inventory back into stock.
                    <?php if ($order['payment_status'] === 'paid'): ?>
                        <br><strong>Note:</strong> Since this order is marked as paid, a refund flag will be raised.
                    <?php endif; ?>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Cancellation Reason / Note: <span class="text-danger">*</span></label>
                    <textarea name="note" class="form-control" rows="3" required placeholder="Specify reason for cancelling this order..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-danger btn-sm">Confirm Cancellation</button>
            </div>
        </form>
    </div>
</div>

<!-- Mark as Paid Modal (Admin Only) -->
<?php if ($isAdmin): ?>
<div class="modal fade" id="markPaidModal" tabindex="-1" aria-labelledby="markPaidModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form action="<?= e(url('admin/orders/detail.php')) ?>" method="POST" class="modal-content">
            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="mark_as_paid">
            <input type="hidden" name="id" value="<?= $order['id'] ?>">
            <input type="hidden" name="expected_from" value="<?= e($order['status']) ?>">

            <div class="modal-header bg-warning-subtle">
                <h5 class="modal-title fw-bold text-dark" id="markPaidModalLabel">Manual Payment Override</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning small mb-3">
                    <i class="bi bi-shield-exclamation me-1"></i>
                    <strong>Caution:</strong> You are manually marking this order as paid without an automated Daraja STK Push callback. This action is permanently audited.
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">M-Pesa Receipt Code (10 characters): <span class="text-danger">*</span></label>
                    <input type="text" name="mpesa_receipt" class="form-control font-monospace text-uppercase" maxlength="10" pattern="[A-Za-z0-9]{10}" required placeholder="e.g. QAA1234567">
                    <div class="form-text small">Must be exactly 10 uppercase alphanumeric characters. Duplicate codes are rejected.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Verification Reason / Note: <span class="text-danger">*</span></label>
                    <textarea name="note" class="form-control" rows="2" required placeholder="e.g. Verified via M-Pesa Statement or Paybill portal..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success btn-sm">Confirm Manual Payment</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Record Refund Modal (Admin Only: orders.refund) -->
<?php if ($canRefund): ?>
<div class="modal fade" id="refundModal" tabindex="-1" aria-labelledby="refundModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form action="<?= e(url('admin/orders/detail.php')) ?>" method="POST" class="modal-content">
            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="record_refund">
            <input type="hidden" name="id" value="<?= $order['id'] ?>">

            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title" id="refundModalLabel">Record Customer Refund</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info small mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    This records a completed manual M-Pesa reversal or customer refund. No external payment gateway reversal request is triggered.
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Refund Amount:</label>
                    <input type="text" class="form-control bg-light" readonly value="<?= e(format_money((float)$order['total'])) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Reference Note / M-Pesa Reversal Code: <span class="text-danger">*</span></label>
                    <textarea name="reference_note" class="form-control" rows="3" required placeholder="e.g. M-Pesa Reversal Reference #QAB9876543, initiated via Safaricom portal"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-dark btn-sm">Save Refund Record</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Add Note Modal -->
<div class="modal fade" id="addNoteModal" tabindex="-1" aria-labelledby="addNoteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form action="<?= e(url('admin/orders/detail.php')) ?>" method="POST" class="modal-content">
            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_note">
            <input type="hidden" name="id" value="<?= $order['id'] ?>">

            <div class="modal-header">
                <h5 class="modal-title" id="addNoteModalLabel">Add Internal Order Note</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">This note will appear in the internal order timeline and audit logs. It does not alter the order status.</p>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Internal Note: <span class="text-danger">*</span></label>
                    <textarea name="internal_note" class="form-control" rows="3" required placeholder="Type internal remarks, rider instructions, or customer call notes..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm">Save Note</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const transitionModal = document.getElementById('transitionModal');
    if (transitionModal) {
        transitionModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const toStatus = button.getAttribute('data-to');
            const label = button.getAttribute('data-label');
            document.getElementById('modalToStatus').value = toStatus;
            document.getElementById('modalStatusLabel').textContent = label;
        });
    }
});
</script>

<?php
require dirname(__DIR__, 3) . '/app/views/admin/footer.php';
