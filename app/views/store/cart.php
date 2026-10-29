<?php
/**
 * Shopping Cart View
 * Based on cart.html
 */
$pageTitle = 'Shopping Bag';
$metaDescription = 'Review items in your ShopWise Kenya shopping bag.';
$bodyClass = 'cart-page';

$breadcrumbs = [
    ['label' => 'Shopping Bag', 'url' => null],
];

include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/breadcrumbs.php';
include __DIR__ . '/partials/flash.php';

$cart = $cart ?? [];
$items = $cart['items'] ?? [];
$subtotal = (float)($cart['subtotal'] ?? 0.0);
$discount = (float)($cart['discount'] ?? 0.0);
$finalSubtotal = (float)($cart['final_subtotal'] ?? $subtotal);
$coupon = $cart['coupon'] ?? null;
$notices = $cart['notices'] ?? [];
?>

<main class="main py-4">

    <section id="cart" class="cart section">
        <div class="container">

            <!-- Global Cart Notices -->
            <?php if (!empty($notices)): ?>
                <div class="alert alert-warning mb-4">
                    <h6 class="alert-heading fw-bold mb-2"><i class="bi bi-info-circle me-1"></i> Cart Updates:</h6>
                    <ul class="mb-0 ps-3">
                        <?php foreach ($notices as $note): ?>
                            <li><?= e($note) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (empty($items)): ?>
                <div class="text-center py-5 border rounded bg-white my-4">
                    <i class="bi bi-bag-x text-muted display-3 mb-3 d-block"></i>
                    <h3 class="fw-bold mb-2">Your Shopping Bag is Empty</h3>
                    <p class="text-muted mb-4">Explore our catalog of everyday essentials and add items to your bag.</p>
                    <a href="<?= e(url('shop')) ?>" class="btn btn-primary px-4 py-2">
                        <i class="bi bi-bag me-1"></i> Start Shopping
                    </a>
                </div>
            <?php else: ?>

                <div class="row gy-4">

                    <!-- Cart Items Table -->
                    <div class="col-lg-8">
                        <div class="cart-table border rounded bg-white p-3 p-md-4">
                            <div class="table-head d-none d-md-flex row align-items-center pb-2 mb-3 border-bottom text-muted fw-bold small">
                                <div class="col-md-5">Product</div>
                                <div class="col-md-2 text-center">Price</div>
                                <div class="col-md-3 text-center">Quantity</div>
                                <div class="col-md-2 text-end">Total</div>
                            </div>

                            <?php foreach ($items as $item): ?>
                                <div class="cart-item border-bottom py-3 position-relative <?= !$item['is_available'] ? 'opacity-50 bg-light' : '' ?>" data-variant-id="<?= (int)$item['variant_id'] ?>">
                                    <div class="row align-items-center g-3">

                                        <!-- Product Info -->
                                        <div class="col-md-5">
                                            <div class="product-info d-flex align-items-center gap-3">
                                                <div class="product-img" style="width: 72px; height: 72px; flex-shrink: 0;">
                                                    <img src="<?= e(product_image_url($item['image_path'])) ?>" alt="<?= e($item['product_name']) ?>" class="img-fluid rounded w-100 h-100" style="object-fit: cover;">
                                                </div>
                                                <div class="product-meta">
                                                    <h6 class="product-title mb-1 fs-6">
                                                        <a href="<?= e(url('product/' . $item['product_slug'])) ?>" class="text-dark text-decoration-none"><?= e($item['product_name']) ?></a>
                                                    </h6>
                                                    <div class="product-tags small text-muted">
                                                        <?php if (!empty($item['color'])): ?>
                                                            <span class="me-2">Color: <?= e($item['color']) ?></span>
                                                        <?php endif; ?>
                                                        <?php if (!empty($item['size'])): ?>
                                                            <span>Size: <?= e($item['size']) ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if (!empty($item['notice'])): ?>
                                                        <span class="badge bg-danger-subtle text-danger small mt-1 d-inline-block"><?= e($item['notice']) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Unit Price -->
                                        <div class="col-md-2 col-6 text-md-center">
                                            <span class="current-price fw-bold text-dark"><?= e(format_money($item['price'])) ?></span>
                                            <?php if (!empty($item['compare_at_price']) && $item['compare_at_price'] > $item['price']): ?>
                                                <small class="text-decoration-line-through text-muted d-block"><?= e(format_money($item['compare_at_price'])) ?></small>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Quantity Control -->
                                        <div class="col-md-3 col-6 text-md-center">
                                            <?php if ($item['is_available']): ?>
                                                <div class="quantity-selector d-inline-flex align-items-center border rounded">
                                                    <button class="btn btn-sm btn-link text-dark px-2 text-decoration-none quantity-btn decrease" type="button"><i class="bi bi-dash"></i></button>
                                                    <input type="number" class="form-control form-control-sm border-0 text-center quantity-input" style="width: 48px;" value="<?= (int)$item['quantity'] ?>" min="1" max="<?= (int)$item['max_purchasable'] ?>" readonly>
                                                    <button class="btn btn-sm btn-link text-dark px-2 text-decoration-none quantity-btn increase" type="button"><i class="bi bi-plus"></i></button>
                                                </div>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Unavailable</span>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Line Total -->
                                        <div class="col-md-2 col-6 text-md-end">
                                            <span class="row-total fw-bold text-dark">
                                                <?= $item['is_available'] ? e(format_money($item['line_total'])) : 'KES 0' ?>
                                            </span>
                                        </div>

                                    </div>

                                    <!-- Remove Button -->
                                    <button class="btn btn-sm text-danger position-absolute top-0 end-0 m-2 remove-item" type="button" aria-label="Remove item" title="Remove from bag">
                                        <i class="bi bi-trash3"></i>
                                    </button>
                                </div>
                            <?php endforeach; ?>

                            <!-- Actions Row -->
                            <div class="cart-actions d-flex justify-content-between align-items-center pt-3 mt-2">
                                <a href="<?= e(url('shop')) ?>" class="btn btn-outline-dark btn-sm">
                                    <i class="bi bi-arrow-left me-1"></i> Continue Shopping
                                </a>
                                <button class="btn btn-outline-danger btn-sm action-clear" type="button">
                                    <i class="bi bi-trash3 me-1"></i> Clear Entire Bag
                                </button>
                            </div>

                        </div>
                    </div>

                    <!-- Summary & Coupon Card -->
                    <div class="col-lg-4">
                        <div class="summary-card border rounded bg-white p-4">
                            <h5 class="fw-bold mb-3 pb-2 border-bottom">Order Summary</h5>

                            <!-- Coupon Preview Form -->
                            <div class="coupon-section mb-3">
                                <label class="form-label small fw-semibold text-muted">Discount Code</label>
                                <?php if ($coupon): ?>
                                    <div class="d-flex justify-content-between align-items-center p-2 bg-success-subtle rounded border border-success mb-2">
                                        <div>
                                            <strong class="text-success"><?= e($coupon['code']) ?></strong>
                                            <small class="d-block text-muted">Saved <?= e(format_money($coupon['discount_amount'])) ?></small>
                                        </div>
                                        <button id="removeCouponBtn" type="button" class="btn btn-sm btn-outline-danger py-0 px-2">Remove</button>
                                    </div>
                                <?php else: ?>
                                    <form id="cartCouponForm" class="d-flex gap-2">
                                        <input type="text" id="couponCodeInput" class="form-control form-control-sm text-uppercase" placeholder="e.g. WELCOME10" required>
                                        <button type="submit" class="btn btn-sm btn-dark">Apply</button>
                                    </form>
                                <?php endif; ?>
                            </div>

                            <!-- Totals Breakdown -->
                            <div class="summary-lines py-2 border-top border-bottom mb-3">
                                <div class="d-flex justify-content-between py-1">
                                    <span class="text-muted">Subtotal (<?= (int)$cart['items_count'] ?> items)</span>
                                    <span class="fw-semibold"><?= e(format_money($subtotal)) ?></span>
                                </div>

                                <?php if ($discount > 0): ?>
                                    <div class="d-flex justify-content-between py-1 text-success">
                                        <span>Coupon Discount</span>
                                        <span class="fw-semibold">-<?= e(format_money($discount)) ?></span>
                                    </div>
                                <?php endif; ?>

                                <div class="d-flex justify-content-between py-1 text-muted small">
                                    <span>Delivery</span>
                                    <em>Calculated at checkout</em>
                                </div>
                            </div>

                            <!-- Final Subtotal -->
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <span class="fs-5 fw-bold text-dark">Subtotal</span>
                                <span class="fs-4 fw-bold text-primary"><?= e(format_money($finalSubtotal)) ?></span>
                            </div>

                            <!-- Disabled Checkout Button per Brief -->
                            <button class="btn btn-primary w-100 py-2 proceed-btn mb-3" type="button" disabled aria-disabled="true">Checkout opens soon</button>

                            <div class="text-center text-muted small">
                                <i class="bi bi-shield-lock text-success me-1"></i>
                                Delivery fee &amp; M-Pesa prompt are processed at checkout.
                            </div>

                        </div>
                    </div>

                </div>

            <?php endif; ?>

        </div>
    </section>

</main>

<?php include __DIR__ . '/partials/footer.php'; ?>
