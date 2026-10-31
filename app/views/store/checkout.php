<?php
/**
 * ShopWise Kenya - Storefront Checkout Page
 * Based on _template_reference/storefront_pages/checkout.html
 */

$pageTitle = 'Checkout';
$metaDescription = 'Secure checkout with M-Pesa at ' . setting('store_name', 'ShopWise Kenya');

$currentCustomer = \App\Services\CustomerAuthService::user();
$isLoggedIn = ($currentCustomer !== null);
$isVerified = $isLoggedIn && ($currentCustomer['email_verified_at'] !== null || ($currentCustomer['auth_provider'] ?? '') === 'google');

$cartService = $cartService ?? new \App\Services\CartService();
$cart = $cartService->getCartDetails();
$items = $cart['items'] ?? [];
$subtotal = (float)($cart['subtotal'] ?? 0);
$discount = (float)($cart['discount'] ?? 0);
$couponCode = $cart['coupon_code'] ?? '';

// Initial delivery fee and total
$initialDeliveryFee = 0.00;
$initialTotal = max(1.0, floor($subtotal - $discount));

// Form idempotency token
$idempotencyToken = bin2hex(random_bytes(16));

// Saved addresses and zones
$savedAddresses = $isLoggedIn ? \App\Services\AccountService::getAddresses((int)$currentCustomer['id']) : [];
$activeZones = \App\Services\DeliveryService::listZones(['is_active' => 1]);
$counties = \App\Services\DeliveryService::getCounties();

$storeAddress = setting('pickup_address', 'ShopWise Hub, 4th Floor, Bihi Towers, Moi Avenue, Nairobi CBD, Kenya');
$storeHours = setting('business_hours', 'Monday - Saturday: 8:00 AM - 7:00 PM (Closed on Sundays)');

// Format customer details
$customerFirstName = $currentCustomer['first_name'] ?? '';
$customerLastName = $currentCustomer['last_name'] ?? '';
$customerEmail = $currentCustomer['email'] ?? '';
$customerPhone = $currentCustomer['phone'] ?? '';

include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/header.php';
?>

<!-- Leaflet Local Vendor Assets -->
<link rel="stylesheet" href="<?= e(asset('assets/vendor/leaflet/leaflet.css')) ?>">
<script src="<?= e(asset('assets/vendor/leaflet/leaflet.js')) ?>"></script>

<main class="main">

  <!-- Page Title -->
  <div class="page-title light-background py-4">
    <div class="container d-lg-flex justify-content-between align-items-center">
      <h1 class="mb-2 mb-lg-0 fw-bold">Checkout</h1>
      <nav class="breadcrumbs">
        <ol class="breadcrumb mb-0">
          <li class="breadcrumb-item"><a href="<?= e(url('')) ?>">Home</a></li>
          <li class="breadcrumb-item"><a href="<?= e(url('cart')) ?>">Cart</a></li>
          <li class="breadcrumb-item active" aria-current="page">Checkout</li>
        </ol>
      </nav>
    </div>
  </div><!-- End Page Title -->

  <!-- Checkout Section -->
  <section id="checkout" class="checkout section py-5">
    <div class="container">

      <!-- Check for Guest Customer -->
      <?php if (!$isLoggedIn): ?>
        <div class="row justify-content-center">
          <div class="col-lg-8">
            <div class="card border-0 shadow-sm p-4 p-md-5 text-center">
              <div class="mb-4">
                <i class="bi bi-person-lock text-primary" style="font-size: 3.5rem;"></i>
              </div>
              <h3 class="fw-bold mb-2">Sign in to check out</h3>
              <p class="text-muted mb-4">Please log in to your account or create an account to proceed with your order, receive instant M-Pesa prompts, and track delivery progress.</p>
              <div class="d-flex flex-wrap justify-content-center gap-3">
                <a href="<?= e(url('login?next=' . urlencode('/checkout'))) ?>" class="btn btn-primary px-4 py-2 fw-semibold">
                  <i class="bi bi-box-arrow-in-right me-2"></i>Sign In to Checkout
                </a>
                <a href="<?= e(url('register?next=' . urlencode('/checkout'))) ?>" class="btn btn-outline-secondary px-4 py-2">
                  Create Account
                </a>
              </div>
            </div>
          </div>
        </div>

      <!-- Check for Unverified Email -->
      <?php elseif (!$isVerified): ?>
        <div class="row justify-content-center">
          <div class="col-lg-8">
            <div class="alert alert-warning border-2 p-4 p-md-5 shadow-sm rounded-3" role="alert">
              <div class="d-flex align-items-center gap-3 mb-3">
                <i class="bi bi-exclamation-triangle-fill fs-1 text-warning"></i>
                <div>
                  <h4 class="alert-heading mb-1 fw-bold">Email Verification Required</h4>
                  <p class="mb-0 text-muted">For your security and to receive order receipts, please verify your email address before placing an order.</p>
                </div>
              </div>
              <hr>
              <p class="mb-3">We sent a verification email to <strong><?= e($customerEmail) ?></strong>. Please click the link inside that message to activate your account.</p>
              <form method="POST" action="<?= e(url('verify-email/resend')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="email" value="<?= e($customerEmail) ?>">
                <button type="submit" class="btn btn-warning fw-semibold">
                  <i class="bi bi-envelope-arrow-up me-2"></i>Resend Verification Email
                </button>
              </form>
            </div>
          </div>
        </div>

      <!-- Check for Empty Cart -->
      <?php elseif (empty($items)): ?>
        <div class="row justify-content-center">
          <div class="col-lg-8">
            <div class="card border-0 shadow-sm p-5 text-center">
              <div class="mb-3">
                <i class="bi bi-bag-x text-muted" style="font-size: 3.5rem;"></i>
              </div>
              <h3 class="fw-bold mb-2">Your Shopping Cart is Empty</h3>
              <p class="text-muted mb-4">You have no items in your cart to checkout. Explore our catalog of products and discover great deals.</p>
              <div>
                <a href="<?= e(url('shop')) ?>" class="btn btn-primary px-4 py-2 fw-semibold">
                  <i class="bi bi-arrow-left me-2"></i>Continue Shopping
                </a>
              </div>
            </div>
          </div>
        </div>

      <!-- Active Checkout Screen -->
      <?php else: ?>

        <!-- Numbered Step Indicator -->
        <div class="step-indicator d-flex align-items-center justify-content-center mb-5">
          <div class="step-pill active">
            <span class="step-num">1</span>
            <span class="step-text">Information</span>
          </div>
          <div class="step-connector"></div>
          <div class="step-pill active">
            <span class="step-num">2</span>
            <span class="step-text">Delivery</span>
          </div>
          <div class="step-connector"></div>
          <div class="step-pill">
            <span class="step-num">3</span>
            <span class="step-text">Payment</span>
          </div>
          <div class="step-connector"></div>
          <div class="step-pill">
            <span class="step-num">4</span>
            <span class="step-text">Confirmation</span>
          </div>
        </div>

        <?php $flashError = flash_get('error'); if ($flashError): ?>
          <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
            <i class="bi bi-exclamation-octagon-fill me-2"></i> <?= e($flashError) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>

        <div class="row g-4">

          <!-- Checkout Form - Left Column -->
          <div class="col-lg-7 order-lg-1 order-2">
            <div class="checkout-card bg-white p-4 p-md-5 rounded shadow-sm border">
              <form id="checkoutForm" action="<?= e(url('checkout/place')) ?>" method="POST" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="idempotency_token" value="<?= e($idempotencyToken) ?>">

                <!-- 1. Contact Information -->
                <div class="form-section mb-4" id="contact-info">
                  <div class="section-label d-flex align-items-center gap-3 mb-3">
                    <span class="label-icon text-primary fs-4"><i class="bi bi-person-circle"></i></span>
                    <div>
                      <h3 class="h5 fw-bold mb-0">1. Contact Information</h3>
                      <p class="text-muted small mb-0">Details of the person receiving this order and M-Pesa payment prompt.</p>
                    </div>
                  </div>
                  <div class="section-fields">
                    <div class="row g-3">
                      <div class="col-sm-6 field-group">
                        <label for="c-fname" class="form-label small fw-semibold">First Name <span class="text-danger">*</span></label>
                        <input type="text" name="first_name" class="form-control" id="c-fname" value="<?= e($customerFirstName) ?>" required>
                      </div>
                      <div class="col-sm-6 field-group">
                        <label for="c-lname" class="form-label small fw-semibold">Last Name <span class="text-danger">*</span></label>
                        <input type="text" name="last_name" class="form-control" id="c-lname" value="<?= e($customerLastName) ?>" required>
                      </div>
                      <input type="hidden" name="recipient_name" id="c-recipient-name" value="<?= e(trim($customerFirstName . ' ' . $customerLastName)) ?>">
                      <div class="col-sm-6 field-group">
                        <label for="c-email" class="form-label small fw-semibold">Account Email (Verified)</label>
                        <input type="email" class="form-control bg-light" id="c-email" value="<?= e($customerEmail) ?>" readonly>
                      </div>
                      <div class="col-sm-6 field-group">
                        <label for="c-phone" class="form-label small fw-semibold">M-Pesa Phone Number <span class="text-danger">*</span></label>
                        <div class="input-group">
                          <span class="input-group-text"><i class="bi bi-phone"></i></span>
                          <input type="tel" class="form-control" name="phone" id="c-phone" value="<?= e($customerPhone) ?>" placeholder="07XXXXXXXX or 01XXXXXXXX" required>
                        </div>
                        <div class="form-text small text-muted">You will receive an instant Safaricom STK prompt on this number.</div>
                      </div>
                    </div>
                  </div>
                </div><!-- End Contact Information -->

                <hr class="section-divider my-4">

                <!-- 2. Delivery Method -->
                <div class="form-section mb-4" id="delivery-method-section">
                  <div class="section-label d-flex align-items-center gap-3 mb-3">
                    <span class="label-icon text-primary fs-4"><i class="bi bi-truck"></i></span>
                    <div>
                      <h3 class="h5 fw-bold mb-0">2. Delivery Method</h3>
                      <p class="text-muted small mb-0">Choose whether to collect in person or have your items delivered.</p>
                    </div>
                  </div>

                  <div class="row g-3 mb-4">
                    <div class="col-sm-6">
                      <div class="card p-3 h-100 border method-choice cursor-pointer active" id="method-delivery-card" style="cursor: pointer;">
                        <div class="form-check">
                          <input class="form-check-input" type="radio" name="delivery_method" id="dm-delivery" value="delivery" checked>
                          <label class="form-check-label fw-bold d-block" for="dm-delivery">
                            <i class="bi bi-box-seam me-1 text-primary"></i> Courier Delivery
                            <span class="d-block small text-muted fw-normal mt-1">Dispatched directly to your address or dropped pin location in Kenya.</span>
                          </label>
                        </div>
                      </div>
                    </div>
                    <div class="col-sm-6">
                      <div class="card p-3 h-100 border method-choice cursor-pointer" id="method-pickup-card" style="cursor: pointer;">
                        <div class="form-check">
                          <input class="form-check-input" type="radio" name="delivery_method" id="dm-pickup" value="pickup">
                          <label class="form-check-label fw-bold d-block" for="dm-pickup">
                            <i class="bi bi-shop me-1 text-success"></i> Store Pickup
                            <span class="badge bg-success-subtle text-success ms-1">FREE</span>
                            <span class="d-block small text-muted fw-normal mt-1">Collect your package in person at our physical hub in Nairobi CBD.</span>
                          </label>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Store Pickup Details Panel (Hidden when Delivery is active) -->
                  <div id="pickupDetailsPanel" class="p-4 bg-light rounded border mb-3 d-none">
                    <h6 class="fw-bold text-dark mb-2"><i class="bi bi-geo-alt-fill text-danger me-1"></i> Pickup Collection Point:</h6>
                    <p class="mb-2 fw-medium text-dark"><?= e($storeAddress) ?></p>
                    <div class="small text-muted mb-2">
                      <i class="bi bi-clock me-1"></i> <strong>Operating Hours:</strong> <?= e($storeHours) ?>
                    </div>
                    <div class="small text-success">
                      <i class="bi bi-check-circle-fill me-1"></i> No delivery fee applied. We will notify you by email as soon as your package is packed and ready for collection.
                    </div>
                  </div>

                  <!-- Courier Delivery Details Panel -->
                  <div id="deliveryDetailsPanel">
                    
                    <!-- Saved Addresses Selector (if available) -->
                    <?php if (!empty($savedAddresses)): ?>
                      <div class="mb-3">
                        <label for="savedAddressSelect" class="form-label small fw-semibold">Saved Addresses</label>
                        <select class="form-select" id="savedAddressSelect" name="address_id">
                          <option value="">-- Choose a saved address or enter new address below --</option>
                          <?php foreach ($savedAddresses as $addr): ?>
                            <option value="<?= e($addr['id']) ?>"
                                    data-recipient="<?= e($addr['recipient_name']) ?>"
                                    data-phone="<?= e($addr['phone']) ?>"
                                    data-county="<?= e($addr['county']) ?>"
                                    data-area="<?= e($addr['area']) ?>"
                                    data-street="<?= e($addr['street_details']) ?>"
                                    data-lat="<?= e($addr['latitude'] ?? '') ?>"
                                    data-lng="<?= e($addr['longitude'] ?? '') ?>">
                              <?= e($addr['label'] . ' - ' . $addr['recipient_name'] . ' (' . $addr['county'] . ', ' . $addr['area'] . ')') ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                    <?php endif; ?>

                    <div id="standardAddressFields">
                      <div class="row g-3 mb-3">
                        <div class="col-sm-6 field-group">
                          <label for="c-county" class="form-label small fw-semibold">County <span class="text-danger">*</span></label>
                          <select class="form-select" id="c-county" name="county" required>
                            <option value="">Select County</option>
                            <?php foreach ($counties as $co): ?>
                              <option value="<?= e($co) ?>"><?= e($co) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <div class="col-sm-6 field-group">
                          <label for="c-area" class="form-label small fw-semibold">Area / Neighborhood <span class="text-danger">*</span></label>
                          <select class="form-select" id="c-area" name="area" required disabled>
                            <option value="">Select County First</option>
                          </select>
                          <input type="hidden" name="zone_id" id="c-zone-id" value="">
                        </div>
                      </div>
                    </div>

                    <!-- Map / Area Not Listed Checkbox -->
                    <div class="form-check mb-3">
                      <input class="form-check-input" type="checkbox" id="area-not-listed" name="area_not_listed">
                      <label class="form-check-label fw-bold text-primary" for="area-not-listed" style="cursor: pointer;">
                        <i class="bi bi-pin-map me-1"></i> My area is not listed (Pinpoint location on Map)
                      </label>
                    </div>

                    <!-- Map Container Block (Toggled by "My area is not listed") -->
                    <div id="mapBlock" class="p-3 bg-light rounded border mb-3 d-none">
                      <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="small fw-bold text-dark"><i class="bi bi-cursor-fill text-danger me-1"></i> Drop pin at your exact delivery spot:</span>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnUseMyLocation">
                          <i class="bi bi-geo me-1"></i> Use My Current Location
                        </button>
                      </div>
                      <div id="deliveryMap" style="height: 320px; width: 100%; border-radius: 6px;"></div>
                      <div class="d-flex justify-content-between align-items-center mt-2 small text-muted">
                        <div>
                          <span>Coordinates: </span>
                          <code id="coordDisplay">None selected</code>
                        </div>
                        <div id="distanceFeeDisplay" class="fw-semibold text-primary"></div>
                      </div>
                      <input type="hidden" name="latitude" id="c-latitude" value="">
                      <input type="hidden" name="longitude" id="c-longitude" value="">
                    </div>

                    <!-- Street / Landmark Details -->
                    <div class="mb-3 field-group">
                      <label for="c-street" class="form-label small fw-semibold">Street Address / Building / Landmarks <span class="text-danger">*</span></label>
                      <input type="text" class="form-control" name="street_details" id="c-street" placeholder="e.g. Kimathi Street, Eagle House, 3rd Floor, Door 302" required>
                    </div>

                  </div><!-- End deliveryDetailsPanel -->

                </div><!-- End Delivery Method -->

                <hr class="section-divider my-4">

                <!-- 3. Payment Method: M-Pesa Daraja -->
                <div class="form-section mb-4" id="pay-section">
                  <div class="section-label d-flex align-items-center gap-3 mb-3">
                    <span class="label-icon text-success fs-4"><i class="bi bi-phone"></i></span>
                    <div>
                      <h3 class="h5 fw-bold mb-0">3. Payment: Safaricom M-Pesa</h3>
                      <p class="text-muted small mb-0">Instant STK prompt sent directly to your phone.</p>
                    </div>
                  </div>

                  <div class="card p-4 border bg-light">
                    <div class="d-flex align-items-center gap-3 mb-3">
                      <div class="bg-success text-white p-2 rounded-circle fs-4 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                        <i class="bi bi-check2-circle"></i>
                      </div>
                      <div>
                        <h6 class="fw-bold mb-0 text-dark">Lipa Na M-Pesa Online (Daraja STK Push)</h6>
                        <span class="small text-muted">A prompt will automatically appear on your phone asking you to authorize payment.</span>
                      </div>
                    </div>
                    <ul class="small text-muted mb-0 ps-3">
                      <li>Keep your phone unlocked and nearby.</li>
                      <li>Review the transaction details and enter your secret M-Pesa PIN.</li>
                      <li>Payment moves immediately to verified confirmation upon PIN authorization.</li>
                      <li>All transactions are processed in whole Kenyan Shillings (KES).</li>
                    </ul>
                  </div>
                </div><!-- End Payment Method -->

                <hr class="section-divider my-4">

                <!-- 4. Order Notes -->
                <div class="form-section mb-4">
                  <label for="c-notes" class="form-label small fw-semibold">Delivery Instructions or Notes (Optional)</label>
                  <textarea name="notes" id="c-notes" class="form-control" rows="2" maxlength="500" placeholder="Special directions, estate gate pass instructions, or alternative contacts (max 500 characters)"></textarea>
                </div>

                <hr class="section-divider my-4">

                <!-- 5. Confirmation & Terms Agreement -->
                <div class="form-section" id="order-confirm">
                  <div class="form-check mb-4">
                    <input class="form-check-input" type="checkbox" id="accept-terms" name="terms" required>
                    <label class="form-check-label small" for="accept-terms">
                      I have reviewed my order details and agree to the <a href="<?= e(url('terms')) ?>" target="_blank">Terms of Service</a> and <a href="<?= e(url('privacy')) ?>" target="_blank">Privacy Policy</a>.
                    </label>
                  </div>

                  <button type="submit" class="btn btn-primary btn-lg w-100 py-3 fw-bold" id="placeOrderBtn">
                    <i class="bi bi-lock-fill me-2"></i>Place Order &amp; Pay KES <span id="btnTotalText"><?= number_format($initialTotal, 0) ?></span>
                  </button>
                  <div class="text-center text-muted small mt-2">
                    <i class="bi bi-shield-lock-fill text-success me-1"></i> Authorized Safaricom Daraja STK Push Payment Gateway
                  </div>
                </div>

              </form>
            </div>
          </div>

          <!-- Order Summary - Right Column -->
          <div class="col-lg-5 order-lg-2 order-1">
            <div class="order-summary-card bg-white p-4 rounded shadow-sm border sticky-top" style="top: 20px;">
              <div class="summary-header d-flex justify-content-between align-items-center mb-3 pb-3 border-bottom">
                <h3 class="h5 fw-bold mb-0">Order Summary</h3>
                <span class="badge bg-light text-dark border"><?= count($items) ?> item(s)</span>
              </div>

              <!-- Product Lines -->
              <div class="summary-products mb-3" style="max-height: 280px; overflow-y: auto;">
                <?php foreach ($items as $item): ?>
                  <div class="d-flex align-items-center gap-3 py-2 border-bottom">
                    <div class="position-relative flex-shrink-0" style="width: 50px; height: 50px;">
                      <img src="<?= e(asset($item['image_url'] ?? 'assets/img/product/product-1.webp')) ?>"
                           alt="<?= e($item['product_name']) ?>"
                           class="w-100 h-100 object-fit-cover rounded border">
                      <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-primary" style="font-size: 0.7rem;">
                        <?= (int)$item['quantity'] ?>
                      </span>
                    </div>
                    <div class="flex-grow-1 overflow-hidden">
                      <h6 class="small fw-semibold text-truncate mb-0"><?= e($item['product_name']) ?></h6>
                      <div class="smaller text-muted">
                        <?php if (!empty($item['size'])): ?><span>Size: <?= e($item['size']) ?></span><?php endif; ?>
                        <?php if (!empty($item['color'])): ?><span class="ms-1">Color: <?= e($item['color']) ?></span><?php endif; ?>
                      </div>
                    </div>
                    <div class="text-end fw-semibold small">
                      <?= e(format_money((float)$item['line_total'])) ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>

              <!-- Coupon Line if Active -->
              <?php if (!empty($couponCode)): ?>
                <div class="alert alert-success d-flex justify-content-between align-items-center p-2 mb-3 small">
                  <div>
                    <i class="bi bi-tag-fill me-1"></i> Coupon: <strong><?= e($couponCode) ?></strong>
                  </div>
                  <span class="text-success fw-bold">-<?= e(format_money($discount)) ?></span>
                </div>
              <?php endif; ?>

              <!-- Cost Breakdown -->
              <div class="cost-breakdown py-2">
                <div class="d-flex justify-content-between py-1 small">
                  <span class="text-muted">Subtotal</span>
                  <span class="fw-semibold" id="summarySubtotal"><?= e(format_money($subtotal)) ?></span>
                </div>
                <div class="d-flex justify-content-between py-1 small">
                  <span class="text-muted">Delivery Fee</span>
                  <span class="fw-semibold" id="summaryDeliveryFee"><?= e(format_money($initialDeliveryFee)) ?></span>
                </div>
                <div class="d-flex justify-content-between py-1 small">
                  <span class="text-muted">Estimated Delivery</span>
                  <span class="fw-semibold text-muted" id="summaryEstDays">Select location</span>
                </div>
                <?php if ($discount > 0): ?>
                  <div class="d-flex justify-content-between py-1 small text-success">
                    <span>Discount</span>
                    <span class="fw-bold" id="summaryDiscount">-<?= e(format_money($discount)) ?></span>
                  </div>
                <?php endif; ?>
                <hr class="my-2">
                <div class="d-flex justify-content-between align-items-center pt-2">
                  <span class="h6 fw-bold mb-0 text-dark">Order Total</span>
                  <span class="fs-4 fw-bold text-primary" id="summaryTotal"><?= e(format_money($initialTotal)) ?></span>
                </div>
                <div class="text-muted smaller mt-1">
                  * All customer-payable amounts are whole Kenyan Shillings.
                </div>
              </div>

            </div>
          </div>

        </div>

      <?php endif; ?>

    </div>
  </section>

</main>

<!-- Client JavaScript for Dynamic Live Quotes and Map Pinning -->
<script>
document.addEventListener('DOMContentLoaded', function () {
  const checkoutForm = document.getElementById('checkoutForm');
  if (!checkoutForm) return;

  const btnPlaceOrder = document.getElementById('placeOrderBtn');
  const btnTotalText = document.getElementById('btnTotalText');
  const summaryDeliveryFee = document.getElementById('summaryDeliveryFee');
  const summaryEstDays = document.getElementById('summaryEstDays');
  const summaryTotal = document.getElementById('summaryTotal');

  const methodDeliveryCard = document.getElementById('method-delivery-card');
  const methodPickupCard = document.getElementById('method-pickup-card');
  const dmDeliveryRadio = document.getElementById('dm-delivery');
  const dmPickupRadio = document.getElementById('dm-pickup');

  const deliveryDetailsPanel = document.getElementById('deliveryDetailsPanel');
  const pickupDetailsPanel = document.getElementById('pickupDetailsPanel');

  const countySelect = document.getElementById('c-county');
  const areaSelect = document.getElementById('c-area');
  const zoneIdInput = document.getElementById('c-zone-id');
  const savedAddressSelect = document.getElementById('savedAddressSelect');
  const streetInput = document.getElementById('c-street');

  const areaNotListedCheckbox = document.getElementById('area-not-listed');
  const standardAddressFields = document.getElementById('standardAddressFields');
  const mapBlock = document.getElementById('mapBlock');
  const coordDisplay = document.getElementById('coordDisplay');
  const latInput = document.getElementById('c-latitude');
  const lngInput = document.getElementById('c-longitude');
  const btnUseMyLocation = document.getElementById('btnUseMyLocation');

  // Zones data passed from server
  const zonesData = <?= json_encode($activeZones) ?>;

  let map = null;
  let marker = null;

  // Method toggle
  function updateMethodUI() {
    if (dmPickupRadio.checked) {
      pickupDetailsPanel.classList.remove('d-none');
      deliveryDetailsPanel.classList.add('d-none');
      methodPickupCard.classList.add('active', 'border-primary');
      methodDeliveryCard.classList.remove('active', 'border-primary');
      if (countySelect) countySelect.removeAttribute('required');
      if (areaSelect) areaSelect.removeAttribute('required');
      if (streetInput) streetInput.removeAttribute('required');
    } else {
      pickupDetailsPanel.classList.add('d-none');
      deliveryDetailsPanel.classList.remove('d-none');
      methodDeliveryCard.classList.add('active', 'border-primary');
      methodPickupCard.classList.remove('active', 'border-primary');
      if (!areaNotListedCheckbox.checked) {
        if (countySelect) countySelect.setAttribute('required', 'required');
        if (areaSelect) areaSelect.setAttribute('required', 'required');
      }
      if (streetInput) streetInput.setAttribute('required', 'required');
    }
    requestQuote();
  }

  if (methodDeliveryCard) {
    methodDeliveryCard.addEventListener('click', function () {
      dmDeliveryRadio.checked = true;
      updateMethodUI();
    });
  }
  if (methodPickupCard) {
    methodPickupCard.addEventListener('click', function () {
      dmPickupRadio.checked = true;
      updateMethodUI();
    });
  }

  // Populate areas when county changes
  if (countySelect) {
    countySelect.addEventListener('change', function () {
      const selectedCounty = this.value;
      areaSelect.innerHTML = '<option value="">Select Area</option>';
      zoneIdInput.value = '';

      if (!selectedCounty) {
        areaSelect.disabled = true;
        requestQuote();
        return;
      }

      const matchingZones = zonesData.filter(z => z.county === selectedCounty);
      matchingZones.forEach(z => {
        const opt = document.createElement('option');
        opt.value = z.area;
        opt.dataset.zoneId = z.id;
        opt.dataset.fee = z.fee;
        opt.textContent = `${z.area} (KES ${parseInt(z.fee, 10)})`;
        areaSelect.appendChild(opt);
      });

      areaSelect.disabled = false;
      requestQuote();
    });
  }

  if (areaSelect) {
    areaSelect.addEventListener('change', function () {
      const selectedOption = this.options[this.selectedIndex];
      zoneIdInput.value = selectedOption ? (selectedOption.dataset.zoneId || '') : '';
      requestQuote();
    });
  }

  // Saved address select listener
  if (savedAddressSelect) {
    savedAddressSelect.addEventListener('change', function () {
      const opt = this.options[this.selectedIndex];
      if (!opt || !opt.value) return;

      const county = opt.dataset.county;
      const area = opt.dataset.area;
      const street = opt.dataset.street;
      const lat = opt.dataset.lat;
      const lng = opt.dataset.lng;

      if (streetInput) streetInput.value = street || '';

      if (lat && lng && parseFloat(lat) !== 0) {
        areaNotListedCheckbox.checked = true;
        toggleMapUI(true);
        setPinLocation(parseFloat(lat), parseFloat(lng));
      } else if (county) {
        areaNotListedCheckbox.checked = false;
        toggleMapUI(false);
        countySelect.value = county;
        countySelect.dispatchEvent(new Event('change'));
        setTimeout(() => {
          areaSelect.value = area;
          areaSelect.dispatchEvent(new Event('change'));
        }, 100);
      }
    });
  }

  // "My area is not listed" toggle
  function toggleMapUI(showMap) {
    if (showMap) {
      mapBlock.classList.remove('d-none');
      countySelect.removeAttribute('required');
      areaSelect.removeAttribute('required');
      initMapIfNeeded();
    } else {
      mapBlock.classList.add('d-none');
      countySelect.setAttribute('required', 'required');
      areaSelect.setAttribute('required', 'required');
      latInput.value = '';
      lngInput.value = '';
      coordDisplay.textContent = 'None selected';
      requestQuote();
    }
  }

  if (areaNotListedCheckbox) {
    areaNotListedCheckbox.addEventListener('change', function () {
      toggleMapUI(this.checked);
    });
  }

  // Leaflet map initialization
  function initMapIfNeeded() {
    if (map !== null) {
      setTimeout(() => map.invalidateSize(), 150);
      return;
    }

    // Default center on Nairobi (-1.286389, 36.817223)
    const defaultLat = -1.286389;
    const defaultLng = 36.817223;

    map = L.map('deliveryMap').setView([defaultLat, defaultLng], 12);

    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(map);

    map.on('click', function (e) {
      setPinLocation(e.latlng.lat, e.latlng.lng);
    });

    if (latInput.value && lngInput.value) {
      setPinLocation(parseFloat(latInput.value), parseFloat(lngInput.value));
    }
  }

  function setPinLocation(lat, lng) {
    latInput.value = lat.toFixed(6);
    lngInput.value = lng.toFixed(6);
    coordDisplay.textContent = `${lat.toFixed(4)}, ${lng.toFixed(4)}`;

    if (!marker) {
      marker = L.marker([lat, lng], { draggable: true }).addTo(map);
      marker.on('dragend', function (event) {
        const position = marker.getLatLng();
        setPinLocation(position.lat, position.lng);
      });
    } else {
      marker.setLatLng([lat, lng]);
    }

    if (map) {
      map.panTo([lat, lng]);
    }

    requestQuote();
  }

  if (btnUseMyLocation) {
    btnUseMyLocation.addEventListener('click', function () {
      if (!navigator.geolocation) {
        alert('Geolocation is not supported by your browser.');
        return;
      }
      this.disabled = true;
      this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Locating...';

      navigator.geolocation.getCurrentPosition(
        (pos) => {
          this.disabled = false;
          this.innerHTML = '<i class="bi bi-geo me-1"></i> Use My Current Location';
          setPinLocation(pos.coords.latitude, pos.coords.longitude);
        },
        (err) => {
          this.disabled = false;
          this.innerHTML = '<i class="bi bi-geo me-1"></i> Use My Current Location';
          alert('Unable to retrieve location: ' + err.message);
        },
        { enableHighAccuracy: true, timeout: 10000 }
      );
    });
  }

  // Live Quote AJAX Request
  let quoteTimeout = null;
  function requestQuote() {
    clearTimeout(quoteTimeout);
    quoteTimeout = setTimeout(() => {
      const payload = {
        delivery_method: dmPickupRadio && dmPickupRadio.checked ? 'pickup' : 'delivery',
        zone_id: zoneIdInput ? zoneIdInput.value : '',
        latitude: latInput ? latInput.value : '',
        longitude: lngInput ? lngInput.value : '',
        coupon_code: '<?= e($couponCode) ?>',
        _csrf_token: '<?= e(csrf_token()) ?>'
      };

      fetch('<?= e(url('checkout/quote')) ?>', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify(payload)
      })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          if (summaryDeliveryFee) summaryDeliveryFee.textContent = data.fee_formatted || data.formatted_fee || 'KES 0';
          if (summaryEstDays) summaryEstDays.textContent = data.est_days || data.estimated_days || 'Standard delivery';
          if (summaryTotal) summaryTotal.textContent = data.total_formatted || data.formatted_tot || 'KES 0';
          if (btnTotalText) btnTotalText.textContent = data.total_integer_formatted || (data.total ? parseInt(data.total, 10).toLocaleString() : '0');
          const distDisplay = document.getElementById('distanceFeeDisplay');
          if (distDisplay && data.distance_km) {
            distDisplay.textContent = `~${data.distance_km} km (Delivery: ${data.fee_formatted || data.formatted_fee})`;
          }
        }
      })
      .catch(() => {});
    }, 200);
  }

  // Update recipient_name hidden input before submit
  checkoutForm.addEventListener('submit', function (e) {
    const fname = document.getElementById('c-fname').value.trim();
    const lname = document.getElementById('c-lname').value.trim();
    document.getElementById('c-recipient-name').value = (fname + ' ' + lname).trim();

    if (!checkoutForm.checkValidity()) {
      return;
    }

    // Disable button to prevent double submits
    btnPlaceOrder.disabled = true;
    btnPlaceOrder.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Initiating M-Pesa Prompt...';
  });
});
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
