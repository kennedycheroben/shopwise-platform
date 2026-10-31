<?php
/**
 * ShopWise Kenya - Order Confirmation View
 * Based verbatim on _template_reference/storefront_pages/order-confirmation.html
 */

$pageTitle = 'Order Confirmation - ' . e($order['order_number'] ?? '');
$metaDescription = 'Thank you for your order at ' . setting('store_name', 'ShopWise Kenya');
$bodyClass = 'order-confirmation-page';

$breadcrumbs = [
    ['label' => 'Shopping Bag', 'url' => '/cart'],
    ['label' => 'Order Confirmation', 'url' => null],
];

include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/breadcrumbs.php';
include __DIR__ . '/partials/flash.php';

$orderNumber = $order['order_number'] ?? '';
$createdAt = $order['created_at'] ?? date('Y-m-d H:i:s');
$deliveryMethod = $order['delivery_method'] ?? 'delivery';
$isPaid = ($order['payment_status'] ?? '') === 'paid';
$receiptNumber = $payment['receipt_number'] ?? null;
$phone = $payment['phone_number'] ?? $order['phone'] ?? '';
$items = $items ?? [];
$itemCount = count($items);

$subtotal = (float)($order['subtotal'] ?? 0);
$deliveryFee = (float)($order['delivery_fee'] ?? 0);
$discount = (float)($order['discount_amount'] ?? 0);
$total = (float)($order['total_amount'] ?? 0);

$customerName = trim(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? ''));
if (empty($customerName) && !empty($customer)) {
    $customerName = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
}
if (empty($customerName)) {
    $customerName = 'Valued Customer';
}
$customerEmail = $order['email'] ?? ($customer['email'] ?? '');
$shippingAddress = $order['shipping_address'] ?? null;
?>

  <main class="main">

    <!-- Order Confirmation Section -->
    <section id="order-confirmation" class="order-confirmation section">

      <div class="container">

        <!-- Status Header -->
        <div class="row justify-content-center mb-4">
          <div class="col-lg-10">
            <div class="status-header">
              <div class="row align-items-center">
                <div class="col-md-8">
                  <div class="status-content">
                    <?php if ($isPaid): ?>
                      <span class="status-badge"><i class="bi bi-check-lg"></i> Paid &amp; Confirmed</span>
                      <h2>Order Successfully Placed</h2>
                      <p>Your payment has been received and verified. We'll notify you with delivery updates via SMS and email.</p>
                    <?php else: ?>
                      <span class="status-badge bg-warning text-dark"><i class="bi bi-hourglass-split"></i> Payment Pending</span>
                      <h2>Order Awaiting Payment</h2>
                      <p>Your order has been recorded. Please complete payment using M-Pesa to begin processing.</p>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="col-md-4">
                  <div class="ref-block">
                    <div class="ref-item">
                      <span class="ref-key">Order Number</span>
                      <span class="ref-val"><?= e($orderNumber) ?></span>
                    </div>
                    <div class="ref-item">
                      <span class="ref-key">Date</span>
                      <span class="ref-val"><?= e(date('F d, Y', strtotime($createdAt))) ?></span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Steps -->
        <div class="row justify-content-center mb-4">
          <div class="col-lg-10">
            <div class="step-tracker">
              <div class="row g-0">
                <div class="col-3">
                  <div class="step-item <?= $isPaid ? 'completed' : 'current' ?>">
                    <div class="step-circle"><i class="bi bi-check-lg"></i></div>
                    <span class="step-label">Placed</span>
                  </div>
                </div>
                <div class="col-3">
                  <div class="step-item <?= $isPaid ? 'current' : '' ?>">
                    <div class="step-circle"><?= $isPaid ? '<i class="bi bi-gear-fill"></i>' : '' ?></div>
                    <span class="step-label">Processing</span>
                  </div>
                </div>
                <div class="col-3">
                  <div class="step-item">
                    <div class="step-circle"></div>
                    <span class="step-label">Shipped</span>
                  </div>
                </div>
                <div class="col-3">
                  <div class="step-item">
                    <div class="step-circle"></div>
                    <span class="step-label">Delivered</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Main Content -->
        <div class="row justify-content-center">
          <div class="col-lg-10">
            <div class="row g-4">

              <!-- Left Column: Items + Delivery info -->
              <div class="col-lg-7">

                <!-- Purchased Items -->
                <div class="panel items-panel">
                  <div class="panel-header">
                    <h4>Items Ordered</h4>
                    <span class="item-count"><?= e($itemCount) ?> <?= $itemCount === 1 ? 'item' : 'items' ?></span>
                  </div>
                  <div class="panel-body">
                    <?php foreach ($items as $idx => $item): ?>
                      <?php if ($idx > 0): ?>
                        <div class="item-divider"></div>
                      <?php endif; ?>
                      <div class="order-item">
                        <div class="item-image">
                          <?php if (!empty($item['primary_image'])): ?>
                            <img src="<?= e($item['primary_image']) ?>" alt="<?= e($item['product_name'] ?? $item['name'] ?? 'Product') ?>" loading="lazy">
                          <?php else: ?>
                            <div class="bg-light d-flex align-items-center justify-content-center h-100 w-100 text-muted">
                              <i class="bi bi-bag"></i>
                            </div>
                          <?php endif; ?>
                        </div>
                        <div class="item-details">
                          <h5><?= e($item['product_name'] ?? $item['name'] ?? '') ?></h5>
                          <div class="item-meta">
                            <?php if (!empty($item['size'])): ?>
                              <span class="meta-tag">Size: <?= e($item['size']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($item['color'])): ?>
                              <span class="meta-tag">Color: <?= e($item['color']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($item['sku'])): ?>
                              <span class="meta-tag">SKU: <?= e($item['sku']) ?></span>
                            <?php endif; ?>
                          </div>
                          <div class="item-bottom">
                            <span class="item-qty">Qty: <?= e($item['quantity']) ?></span>
                            <span class="item-price"><?= e(format_money($item['unit_price'] * $item['quantity'])) ?></span>
                          </div>
                        </div>
                      </div><!-- End Order Item -->
                    <?php endforeach; ?>
                  </div>
                </div>

                <!-- Delivery & Help -->
                <div class="row g-4 mt-0">
                  <div class="col-sm-6">
                    <div class="panel compact-panel">
                      <div class="compact-icon">
                        <i class="bi bi-box-seam"></i>
                      </div>
                      <h5>Delivery Details</h5>
                      <?php if ($deliveryMethod === 'pickup'): ?>
                        <p><i class="bi bi-shop"></i> Store Pickup</p>
                        <p class="small text-muted mb-0"><i class="bi bi-geo-alt"></i> <?= e(setting('pickup_address', 'ShopWise Hub, Bihi Towers, Moi Avenue, Nairobi')) ?></p>
                      <?php else: ?>
                        <p><i class="bi bi-truck"></i> Standard Doorstep Delivery</p>
                        <p class="small text-muted mb-0"><i class="bi bi-calendar-event"></i> Estimated: <?= e($order['estimated_days'] ?? 2) ?> business days</p>
                      <?php endif; ?>
                    </div>
                  </div><!-- End Shipping Panel -->
                  <div class="col-sm-6">
                    <div class="panel compact-panel">
                      <div class="compact-icon">
                        <i class="bi bi-life-preserver"></i>
                      </div>
                      <h5>Need Help?</h5>
                      <a href="/contact" class="help-link"><i class="bi bi-chat-dots"></i> Contact Support</a>
                      <a href="/faq" class="help-link"><i class="bi bi-question-circle"></i> View FAQ</a>
                    </div>
                  </div><!-- End Help Panel -->
                </div>

              </div>

              <!-- Right Column: Details -->
              <div class="col-lg-5">

                <!-- Summary -->
                <div class="panel summary-panel">
                  <div class="panel-header">
                    <h4>Order Summary</h4>
                  </div>
                  <div class="panel-body">
                    <ul class="summary-list">
                      <li><span>Subtotal</span><span><?= e(format_money($subtotal)) ?></span></li>
                      <li>
                        <span>Delivery Fee</span>
                        <?php if ($deliveryFee <= 0): ?>
                          <span class="free-tag">Free</span>
                        <?php else: ?>
                          <span><?= e(format_money($deliveryFee)) ?></span>
                        <?php endif; ?>
                      </li>
                      <?php if ($discount > 0): ?>
                        <li><span>Discount</span><span class="text-success">-<?= e(format_money($discount)) ?></span></li>
                      <?php endif; ?>
                    </ul>
                    <div class="total-row">
                      <span>Total</span>
                      <span><?= e(format_money($total)) ?></span>
                    </div>
                  </div>
                </div>

                <!-- Shipping Address -->
                <div class="panel">
                  <div class="panel-header">
                    <h4><?= $deliveryMethod === 'pickup' ? 'Pickup Contact' : 'Delivery Address' ?></h4>
                  </div>
                  <div class="panel-body">
                    <?php if ($deliveryMethod === 'pickup'): ?>
                      <p class="fw-bold mb-1"><?= e($customerName) ?></p>
                      <p class="small text-muted mb-2">Self pickup from store location</p>
                    <?php elseif (!empty($shippingAddress)): ?>
                      <address>
                        <strong><?= e($shippingAddress['recipient_name'] ?? $customerName) ?></strong><br>
                        <?= e($shippingAddress['address_line_1'] ?? '') ?><br>
                        <?php if (!empty($shippingAddress['address_line_2'])): ?>
                          <?= e($shippingAddress['address_line_2']) ?><br>
                        <?php endif; ?>
                        <?= e($shippingAddress['area'] ?? '') ?>, <?= e($shippingAddress['county'] ?? '') ?>
                      </address>
                    <?php else: ?>
                      <p class="text-muted">Direct address on file</p>
                    <?php endif; ?>

                    <div class="contact-info">
                      <?php if (!empty($customerEmail)): ?>
                        <p><i class="bi bi-envelope"></i> <?= e($customerEmail) ?></p>
                      <?php endif; ?>
                      <?php if (!empty($phone)): ?>
                        <p><i class="bi bi-telephone"></i> <?= e($phone) ?></p>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>

                <!-- Payment -->
                <div class="panel">
                  <div class="panel-header">
                    <h4>Payment Information</h4>
                  </div>
                  <div class="panel-body">
                    <div class="pay-method">
                      <i class="bi bi-phone text-success fs-3 me-2"></i>
                      <div>
                        <strong>M-Pesa STK Push</strong>
                        <span><?= e($phone) ?></span>
                      </div>
                    </div>
                    <?php if ($isPaid && !empty($receiptNumber)): ?>
                      <div class="billing-match mt-2 text-success">
                        <i class="bi bi-check-circle-fill"></i>
                        <span>Receipt: <strong><?= e($receiptNumber) ?></strong></span>
                      </div>
                    <?php elseif (!$isPaid): ?>
                      <div class="mt-2 text-warning">
                        <i class="bi bi-clock-history"></i>
                        <span>Status: <strong>Awaiting Payment</strong></span>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

              </div>

            </div>
          </div>
        </div>

        <!-- Actions -->
        <div class="row justify-content-center mt-4">
          <div class="col-lg-10">
            <div class="action-bar">
              <a href="/" class="btn btn-ghost-action">
                <i class="bi bi-arrow-left"></i> Continue Shopping
              </a>
              <?php if (!$isPaid): ?>
                <a href="/checkout/pay/<?= e(urlencode($orderNumber)) ?>" class="btn btn-primary-action">
                  Pay Now with M-Pesa <i class="bi bi-arrow-right"></i>
                </a>
              <?php else: ?>
                <a href="/account/orders/<?= e(urlencode($orderNumber)) ?>" class="btn btn-primary-action">
                  View in My Orders <i class="bi bi-arrow-right"></i>
                </a>
              <?php endif; ?>
            </div>
          </div>
        </div>

      </div>

    </section><!-- End Order Confirmation Section -->

  </main>

<?php
include __DIR__ . '/partials/footer.php';
?>
