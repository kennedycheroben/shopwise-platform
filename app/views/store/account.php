<?php
$pageTitle = 'My Account - ' . setting('store_name', 'ShopWise Kenya');
$metaDescription = 'Manage your ShopWise account profile, orders, saved delivery addresses, and preferences.';
$breadcrumbs = [
    ['label' => 'Home', 'url' => url('/')],
    ['label' => 'Account']
];

include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/breadcrumbs.php';
include __DIR__ . '/partials/flash.php';

$customer = $customer ?? [];
$addresses = $addresses ?? [];
$orders = $orders ?? [];
$order = $order ?? null;
$stats = $stats ?? [];
$activeTab = $activeTab ?? 'orders';
$fullName = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
if ($fullName === '') {
    $fullName = 'Customer';
}
$isVerified = !empty($customer['email_verified_at']);
?>

<main class="main">

    <!-- Page Title -->
    <div class="page-title light-background">
        <div class="container d-lg-flex justify-content-between align-items-center">
            <h1 class="mb-2 mb-lg-0">My Account</h1>
            <nav class="breadcrumbs">
                <ol>
                    <li><a href="<?= e(url('/')) ?>">Home</a></li>
                    <li class="current">Account</li>
                </ol>
            </nav>
        </div>
    </div><!-- End Page Title -->

    <!-- Account Section -->
    <section id="account" class="account section py-5">
        <div class="container">
            <div class="row g-4">

                <!-- Sidebar Navigation -->
                <div class="col-lg-3">
                    <div class="sidebar-panel border rounded p-3 bg-white shadow-sm">
                        <div class="profile-block text-center mb-4">
                            <div class="profile-avatar mb-2 position-relative d-inline-block">
                                <div class="avatar-circle rounded-circle bg-primary text-white d-flex align-items-center justify-content-center mx-auto shadow-sm" style="width: 72px; height: 72px; font-size: 1.8rem; font-weight: bold;">
                                    <?= e(strtoupper(substr($customer['first_name'] ?? 'C', 0, 1))) ?>
                                </div>
                                <?php if ($isVerified): ?>
                                    <span class="verified-icon position-absolute bottom-0 end-0 text-success bg-white rounded-circle" title="Verified Customer" style="font-size: 1.2rem; line-height: 1;">
                                        <i class="bi bi-patch-check-fill"></i>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <h4 class="profile-name fs-5 fw-bold mb-1"><?= e($fullName) ?></h4>
                            <p class="text-muted small mb-2"><?= e($customer['email'] ?? '') ?></p>

                            <?php if ($isVerified): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 small">
                                    <i class="bi bi-patch-check me-1"></i> Verified Email
                                </span>
                            <?php else: ?>
                                <div class="mb-2">
                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1 small">
                                        <i class="bi bi-exclamation-triangle me-1"></i> Unverified Email
                                    </span>
                                    <form method="POST" action="<?= e(url('verify-email/resend')) ?>" class="d-inline mt-1">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="email" value="<?= e($customer['email'] ?? '') ?>">
                                        <button type="submit" class="btn btn-link btn-sm p-0 text-decoration-none small text-primary d-block mx-auto mt-1">Resend link</button>
                                    </form>
                                </div>
                            <?php endif; ?>

                            <div class="profile-stats d-flex justify-content-around border-top pt-3 mt-3 text-center">
                                <div class="stat-block">
                                    <span class="stat-num fw-bold d-block fs-5"><?= (int)($stats['orders_count'] ?? 0) ?></span>
                                    <span class="stat-label small text-muted">Orders</span>
                                </div>
                                <div class="stat-block">
                                    <span class="stat-num fw-bold d-block fs-5"><?= (int)($stats['wishlist_count'] ?? 0) ?></span>
                                    <span class="stat-label small text-muted">Favorites</span>
                                </div>
                                <div class="stat-block">
                                    <span class="stat-num fw-bold d-block fs-5"><?= (int)($stats['address_count'] ?? 0) ?></span>
                                    <span class="stat-label small text-muted">Addresses</span>
                                </div>
                            </div>
                        </div>

                        <nav class="sidebar-nav mb-4">
                            <ul class="nav flex-column gap-1">
                                <li class="nav-item">
                                    <a class="nav-link rounded <?= in_array($activeTab, ['orders', 'order_details'], true) ? 'active bg-light fw-bold text-primary' : 'text-dark' ?>" href="<?= e(url('account/orders')) ?>">
                                        <i class="bi bi-bag me-2"></i><span>Orders</span>
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link rounded <?= $activeTab === 'addresses' ? 'active bg-light fw-bold text-primary' : 'text-dark' ?>" href="<?= e(url('account/addresses')) ?>">
                                        <i class="bi bi-pin-map me-2"></i><span>Saved Locations</span>
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link rounded text-dark" href="<?= e(url('wishlist')) ?>">
                                        <i class="bi bi-bookmark-heart me-2"></i><span>Favorites</span>
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link rounded <?= in_array($activeTab, ['profile', 'password'], true) ? 'active bg-light fw-bold text-primary' : 'text-dark' ?>" href="<?= e(url('account/profile')) ?>">
                                        <i class="bi bi-sliders me-2"></i><span>Profile &amp; Security</span>
                                    </a>
                                </li>
                            </ul>
                        </nav>

                        <div class="sidebar-footer border-top pt-3">
                            <a href="<?= e(url('support')) ?>" class="sidebar-action d-block text-muted text-decoration-none small mb-2">
                                <i class="bi bi-life-preserver me-2"></i> Customer Support
                            </a>
                            <form method="POST" action="<?= e(url('logout')) ?>" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <button type="submit" class="btn btn-link text-danger text-decoration-none p-0 small fw-bold">
                                    <i class="bi bi-power me-2"></i> Sign Out
                                </button>
                            </form>
                        </div>
                    </div>
                </div><!-- End Sidebar -->

                <!-- Main Content Area -->
                <div class="col-lg-9">
                    <div class="content-area border rounded p-4 bg-white shadow-sm">

                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger py-2 small mb-4" role="alert">
                                <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= e($error) ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($success)): ?>
                            <div class="alert alert-success py-2 small mb-4" role="alert">
                                <i class="bi bi-check-circle-fill me-1"></i> <?= e($success) ?>
                            </div>
                        <?php endif; ?>

                        <!-- Orders List Tab -->
                        <?php if ($activeTab === 'orders'): ?>
                            <div class="content-header d-flex justify-content-between align-items-center mb-4 pb-2 border-bottom">
                                <h3 class="fs-4 fw-bold mb-0">Purchase History</h3>
                                <span class="badge bg-light text-dark"><?= count($orders['items'] ?? []) ?> <?= count($orders['items'] ?? []) === 1 ? 'Order' : 'Orders' ?></span>
                            </div>

                            <?php if (empty($orders['items'])): ?>
                                <div class="text-center py-5">
                                    <i class="bi bi-bag-x text-muted" style="font-size: 3rem;"></i>
                                    <h5 class="fw-bold mt-3">You have not placed an order yet</h5>
                                    <p class="text-muted small">Explore our catalog of genuine essentials and start shopping today.</p>
                                    <a href="<?= e(url('shop')) ?>" class="btn btn-primary px-4 py-2 mt-2">Start Shopping</a>
                                </div>
                            <?php else: ?>
                                <div class="orders-grid d-flex flex-column gap-3">
                                    <?php foreach ($orders['items'] as $ord): ?>
                                        <div class="order-card border rounded p-3 bg-light">
                                            <div class="order-card-top d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2 pb-2 border-bottom">
                                                <div class="order-meta">
                                                    <span class="order-id fw-bold text-dark me-2">#<?= e($ord['order_number']) ?></span>
                                                    <span class="order-date text-muted small"><?= e(date('M j, Y g:i A', strtotime($ord['created_at']))) ?></span>
                                                </div>
                                                <span class="status-tag badge <?= in_array($ord['status'], ['completed', 'delivered'], true) ? 'bg-success' : (in_array($ord['status'], ['cancelled'], true) ? 'bg-danger' : 'bg-primary') ?>">
                                                    <?= e(ucfirst($ord['status'])) ?>
                                                </span>
                                            </div>
                                            <div class="order-card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
                                                <div class="order-summary small text-muted">
                                                    <span><?= (int)$ord['item_count'] ?> <?= (int)$ord['item_count'] === 1 ? 'item' : 'items' ?></span>
                                                    <span class="mx-1">•</span>
                                                    <span class="order-amount fw-bold text-dark fs-6"><?= e(format_money($ord['total_amount'])) ?></span>
                                                    <span class="mx-1">•</span>
                                                    <span>Payment: <?= e(ucfirst($ord['payment_status'] ?? 'pending')) ?></span>
                                                </div>
                                                <div class="order-card-footer">
                                                    <a href="<?= e(url('account/orders/' . $ord['order_number'])) ?>" class="btn btn-sm btn-outline-dark">
                                                        View Order Details
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Pagination -->
                                <?php if (($orders['total_pages'] ?? 1) > 1): ?>
                                    <nav class="mt-4">
                                        <ul class="pagination pagination-sm justify-content-center">
                                            <?php for ($i = 1; $i <= $orders['total_pages']; $i++): ?>
                                                <li class="page-item <?= $i === (int)$orders['page'] ? 'active' : '' ?>">
                                                    <a class="page-link" href="<?= e(url('account/orders?page=' . $i)) ?>"><?= $i ?></a>
                                                </li>
                                            <?php endfor; ?>
                                        </ul>
                                    </nav>
                                <?php endif; ?>
                            <?php endif; ?>

                        <!-- Single Order Details Tab -->
                        <?php elseif ($activeTab === 'order_details' && !empty($order)): ?>
                            <div class="content-header d-flex justify-content-between align-items-center mb-4 pb-2 border-bottom">
                                <div>
                                    <a href="<?= e(url('account/orders')) ?>" class="text-decoration-none small text-muted mb-1 d-inline-block">
                                        <i class="bi bi-arrow-left me-1"></i> Back to All Orders
                                    </a>
                                    <h3 class="fs-4 fw-bold mb-0">Order #<?= e($order['order_number']) ?></h3>
                                    <span class="small text-muted">Placed on <?= e(date('F j, Y \a\t g:i A', strtotime($order['created_at']))) ?></span>
                                </div>
                                <span class="badge fs-6 <?= in_array($order['status'], ['completed', 'delivered'], true) ? 'bg-success' : (in_array($order['status'], ['cancelled'], true) ? 'bg-danger' : 'bg-primary') ?>">
                                    <?= e(ucfirst($order['status'])) ?>
                                </span>
                            </div>

                            <!-- Order Status Timeline -->
                            <?php if (!empty($order['timeline'])): ?>
                                <div class="timeline-card p-3 border rounded mb-4 bg-light">
                                    <h6 class="fw-bold mb-3"><i class="bi bi-clock-history me-1"></i> Status History</h6>
                                    <div class="step-tracker d-flex flex-column gap-2">
                                        <?php foreach ($order['timeline'] as $t): ?>
                                            <div class="d-flex align-items-start gap-2 small">
                                                <i class="bi bi-check-circle-fill text-success mt-1"></i>
                                                <div>
                                                    <span class="fw-bold text-dark"><?= e(ucfirst($t['status'])) ?></span>
                                                    <?php if (!empty($t['notes'])): ?>
                                                        <span class="text-muted">— <?= e($t['notes']) ?></span>
                                                    <?php endif; ?>
                                                    <div class="text-muted" style="font-size: 0.8rem;"><?= e(date('M j, Y g:i A', strtotime($t['created_at']))) ?></div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Items Table -->
                            <div class="table-responsive mb-4">
                                <table class="table align-middle">
                                    <thead class="table-light small">
                                        <tr>
                                            <th>Item</th>
                                            <th>Variant</th>
                                            <th class="text-center">Qty</th>
                                            <th class="text-end">Price</th>
                                            <th class="text-end">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($order['items'] as $item): ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-bold"><?= e($item['product_name']) ?></div>
                                                    <span class="small text-muted">SKU: <?= e($item['sku']) ?></span>
                                                </td>
                                                <td class="small text-muted">
                                                    <?= e(trim(($item['variant_size'] ? 'Size: ' . $item['variant_size'] : '') . ' ' . ($item['variant_color'] ? 'Color: ' . $item['variant_color'] : ''))) ?: 'Standard' ?>
                                                </td>
                                                <td class="text-center"><?= (int)$item['quantity'] ?></td>
                                                <td class="text-end"><?= e(format_money($item['unit_price'])) ?></td>
                                                <td class="text-end fw-bold"><?= e(format_money($item['line_total'])) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="border-top">
                                        <tr>
                                            <td colspan="4" class="text-end small text-muted">Subtotal:</td>
                                            <td class="text-end"><?= e(format_money($order['subtotal'])) ?></td>
                                        </tr>
                                        <?php if ((float)$order['discount_amount'] > 0): ?>
                                            <tr>
                                                <td colspan="4" class="text-end small text-success">Discount:</td>
                                                <td class="text-end text-success">-<?= e(format_money($order['discount_amount'])) ?></td>
                                            </tr>
                                        <?php endif; ?>
                                        <tr>
                                            <td colspan="4" class="text-end small text-muted">Delivery Fee:</td>
                                            <td class="text-end"><?= e(format_money($order['shipping_fee'])) ?></td>
                                        </tr>
                                        <tr class="fw-bold fs-6">
                                            <td colspan="4" class="text-end">Total Amount:</td>
                                            <td class="text-end text-primary"><?= e(format_money($order['total_amount'])) ?></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>

                            <!-- Address Snapshot & Payment Info -->
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="p-3 border rounded bg-light h-100">
                                        <h6 class="fw-bold mb-2"><i class="bi bi-geo-alt me-1 text-primary"></i> Delivery Location</h6>
                                        <div class="small">
                                            <strong><?= e($order['shipping_recipient_name']) ?></strong><br>
                                            Phone: <?= e($order['shipping_phone']) ?><br>
                                            County: <?= e($order['shipping_county']) ?><br>
                                            Area / City: <?= e($order['shipping_area']) ?><br>
                                            Street Details: <?= e($order['shipping_street_address']) ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="p-3 border rounded bg-light h-100">
                                        <h6 class="fw-bold mb-2"><i class="bi bi-credit-card me-1 text-primary"></i> Payment Information</h6>
                                        <div class="small">
                                            Method: <strong><?= e(ucfirst($order['payment_method'] ?? 'M-Pesa')) ?></strong><br>
                                            Status: <span class="badge bg-secondary"><?= e(ucfirst($order['payment_status'] ?? 'pending')) ?></span><br>
                                            <?php if (!empty($order['payment_reference'])): ?>
                                                Transaction Ref: <code><?= e($order['payment_reference']) ?></code><br>
                                            <?php endif; ?>
                                            Paid Amount: <?= e(format_money($order['total_amount'])) ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        <!-- Saved Addresses Tab -->
                        <?php elseif ($activeTab === 'addresses'): ?>
                            <div class="content-header d-flex justify-content-between align-items-center mb-4 pb-2 border-bottom">
                                <div>
                                    <h3 class="fs-4 fw-bold mb-0">Saved Locations</h3>
                                    <span class="small text-muted"><?= count($addresses) ?> of 10 addresses saved</span>
                                </div>
                                <?php if (count($addresses) < 10): ?>
                                    <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#newAddressForm">
                                        <i class="bi bi-plus-lg me-1"></i> New Address
                                    </button>
                                <?php endif; ?>
                            </div>

                            <!-- New Address Form (Collapsible) -->
                            <div class="collapse mb-4 <?= !empty($showAddressForm) ? 'show' : '' ?>" id="newAddressForm">
                                <div class="card card-body bg-light border-0 shadow-sm">
                                    <h5 class="fw-bold mb-3">Add New Address</h5>
                                    <form method="POST" action="<?= e(url('account/addresses')) ?>">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <label class="form-label small fw-bold">Label (e.g. Home, Work)</label>
                                                <input type="text" name="label" class="form-control form-control-sm" placeholder="Home" maxlength="50">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label small fw-bold">Recipient Name <span class="text-danger">*</span></label>
                                                <input type="text" name="recipient_name" class="form-control form-control-sm" required maxlength="100">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label small fw-bold">Phone Number <span class="text-danger">*</span></label>
                                                <input type="tel" name="phone" class="form-control form-control-sm" placeholder="0712 345 678" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold">County <span class="text-danger">*</span></label>
                                                <input type="text" name="county" class="form-control form-control-sm" placeholder="e.g. Nairobi" required maxlength="100">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold">Area / Town / Estate <span class="text-danger">*</span></label>
                                                <input type="text" name="area" class="form-control form-control-sm" placeholder="e.g. Kilimani" required maxlength="100">
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label small fw-bold">Street Details / Landmark <span class="text-danger">*</span></label>
                                                <textarea name="street_details" class="form-control form-control-sm" rows="2" placeholder="e.g. Argwings Kodhek Rd, Plaza Block B, 2nd Floor" required maxlength="255"></textarea>
                                            </div>
                                            <div class="col-12">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="is_default" id="isDefaultCheck" value="1">
                                                    <label class="form-check-label small" for="isDefaultCheck">Set as primary default address</label>
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <button type="submit" class="btn btn-primary btn-sm px-4">Save Address</button>
                                                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="collapse" data-bs-target="#newAddressForm">Cancel</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <?php if (empty($addresses)): ?>
                                <div class="text-center py-5">
                                    <i class="bi bi-pin-map text-muted" style="font-size: 3rem;"></i>
                                    <h5 class="fw-bold mt-3">No Saved Addresses</h5>
                                    <p class="text-muted small">Save your delivery locations to speed up checkout.</p>
                                </div>
                            <?php else: ?>
                                <div class="row g-3">
                                    <?php foreach ($addresses as $addr): ?>
                                        <div class="col-md-6">
                                            <div class="address-card border rounded p-3 h-100 position-relative <?= !empty($addr['is_default']) ? 'border-primary bg-light' : 'bg-white' ?>">
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <h5 class="fw-bold fs-6 mb-0">
                                                        <i class="bi bi-house-door text-primary me-1"></i>
                                                        <?= e($addr['label'] ?: 'Location') ?>
                                                    </h5>
                                                    <?php if (!empty($addr['is_default'])): ?>
                                                        <span class="badge bg-primary">Primary</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="small text-muted mb-3">
                                                    <div class="text-dark fw-bold"><?= e($addr['recipient_name']) ?></div>
                                                    <div><?= e($addr['phone']) ?></div>
                                                    <div><?= e($addr['street_details']) ?></div>
                                                    <div><?= e($addr['area']) ?>, <?= e($addr['county']) ?></div>
                                                </div>
                                                <div class="d-flex gap-2 border-top pt-2">
                                                    <?php if (empty($addr['is_default'])): ?>
                                                        <form method="POST" action="<?= e(url('account/addresses/' . $addr['id'] . '/default')) ?>" class="d-inline">
                                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                            <button type="submit" class="btn btn-sm btn-link p-0 text-decoration-none small text-primary">Make Primary</button>
                                                        </form>
                                                        <span class="text-muted">•</span>
                                                    <?php endif; ?>
                                                    <form method="POST" action="<?= e(url('account/addresses/' . $addr['id'] . '/delete')) ?>" class="d-inline" onsubmit="return confirm('Delete this saved address?');">
                                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                        <button type="submit" class="btn btn-sm btn-link p-0 text-decoration-none small text-danger">Remove</button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                        <!-- Profile & Password Tab -->
                        <?php elseif (in_array($activeTab, ['profile', 'password'], true)): ?>
                            <div class="content-header mb-4 pb-2 border-bottom">
                                <h3 class="fs-4 fw-bold mb-0">Profile &amp; Security</h3>
                                <span class="small text-muted">Update your contact information and manage your password.</span>
                            </div>

                            <!-- Personal Details Form -->
                            <div class="settings-section mb-5">
                                <h5 class="fw-bold mb-3"><i class="bi bi-person-circle text-primary me-2"></i>Personal Details</h5>
                                <form method="POST" action="<?= e(url('account/profile')) ?>" class="border rounded p-3 bg-light">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold">First Name <span class="text-danger">*</span></label>
                                            <input type="text" name="first_name" class="form-control form-control-sm" value="<?= e($customer['first_name'] ?? '') ?>" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold">Last Name <span class="text-danger">*</span></label>
                                            <input type="text" name="last_name" class="form-control form-control-sm" value="<?= e($customer['last_name'] ?? '') ?>" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold">Email Address <span class="text-danger">*</span></label>
                                            <input type="email" name="email" class="form-control form-control-sm" value="<?= e($customer['email'] ?? '') ?>" required>
                                            <span class="form-text small text-muted">Changing your email address requires re-verification.</span>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold">Phone Number</label>
                                            <input type="tel" name="phone" class="form-control form-control-sm" value="<?= e($customer['phone'] ?? '') ?>" placeholder="0712 345 678">
                                        </div>
                                        <div class="col-12 mt-3">
                                            <button type="submit" class="btn btn-primary btn-sm px-4">Save Changes</button>
                                        </div>
                                    </div>
                                </form>
                            </div>

                            <!-- Password Security Form -->
                            <div class="settings-section mb-5">
                                <h5 class="fw-bold mb-3"><i class="bi bi-shield-lock text-primary me-2"></i>Change Password</h5>
                                <form method="POST" action="<?= e(url('account/password')) ?>" class="border rounded p-3 bg-light">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                                    <div class="row g-3">
                                        <?php if (!empty($customer['password_hash'])): ?>
                                            <div class="col-md-12">
                                                <label class="form-label small fw-bold">Current Password <span class="text-danger">*</span></label>
                                                <input type="password" name="current_password" class="form-control form-control-sm" required autocomplete="current-password">
                                            </div>
                                        <?php else: ?>
                                            <div class="col-12">
                                                <div class="alert alert-info py-2 small mb-0">
                                                    You signed up via Google and do not have a password yet. Setting a password enables password sign-in alongside Google.
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold">New Password <span class="text-danger">*</span></label>
                                            <input type="password" name="new_password" class="form-control form-control-sm" required minlength="10" maxlength="72" autocomplete="new-password">
                                            <span class="form-text small text-muted">10–72 chars with at least one letter and one number.</span>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold">Confirm New Password <span class="text-danger">*</span></label>
                                            <input type="password" name="new_password_confirmation" class="form-control form-control-sm" required minlength="10" maxlength="72" autocomplete="new-password">
                                        </div>
                                        <div class="col-12 mt-3">
                                            <button type="submit" class="btn btn-primary btn-sm px-4">Update Password</button>
                                        </div>
                                    </div>
                                </form>
                            </div>

                            <!-- Privacy & Data Deletion Note -->
                            <div class="settings-section">
                                <div class="p-3 border rounded bg-light">
                                    <h6 class="fw-bold mb-1"><i class="bi bi-shield-shaded me-1"></i> Account Privacy &amp; Data Deletion</h6>
                                    <p class="small text-muted mb-0">
                                        Under the Kenya Data Protection Act, 2019, you have the right to request access to or deletion of your personal account data. To request account deletion or data export, please contact our privacy compliance team via our <a href="<?= e(url('support')) ?>" class="text-decoration-none">Support Center</a> or email <code>support@shopwise.co.ke</code>.
                                    </p>
                                </div>
                            </div>
                        <?php endif; ?>

                    </div>
                </div><!-- End Main Content -->

            </div>
        </div>
    </section><!-- /Account Section -->

</main>

<?php include __DIR__ . '/partials/footer.php'; ?>
