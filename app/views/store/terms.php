<?php
/**
 * Terms of Service View
 * Based on _template_reference/storefront_pages/tos.html (lines 835-1096)
 * Governed by the Laws of Kenya
 */
$pageTitle = 'Terms of Service';
$metaDescription = 'Review the terms of service, ordering provisions, KES pricing, M-Pesa payment rules, customer accounts, and delivery conditions for ' . setting('store_name', 'ShopWise Kenya') . '.';
$bodyClass = 'tos-page';

$breadcrumbs = [
    ['label' => 'Terms of Service', 'url' => null],
];

$orderExpiryMinutes = (int)setting('order_expiry_minutes', 30);
$returnDays = (int)setting('return_window_days', 7);
$refundDays = (int)setting('refund_processing_days', 7);

include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/breadcrumbs.php';
include __DIR__ . '/partials/flash.php';
?>

<main class="main">

    <!-- Terms Of Service Section -->
    <section id="terms-of-service" class="terms-of-service section">

        <div class="container">

            <!-- Intro Header -->
            <div class="row justify-content-center">
                <div class="col-lg-8 text-center">
                    <div class="status-badge">
                        <i class="bi bi-clock-history"></i>
                        <span>Effective: 2026</span>
                    </div>
                    <h2 class="mt-2 mb-3">General Terms of Service</h2>
                    <p class="lead-text">Please carefully read these terms and conditions before placing orders or using services provided by <?= e(setting('store_name', 'ShopWise Kenya')) ?>.</p>
                </div>
            </div>

            <!-- Quick Navigation Pills -->
            <div class="row justify-content-center mt-4">
                <div class="col-lg-10">
                    <div class="quick-nav d-flex flex-wrap justify-content-center gap-2">
                        <a href="#block-agreement" class="nav-pill">Agreement</a>
                        <a href="#block-accounts" class="nav-pill">Accounts &amp; Reviews</a>
                        <a href="#block-pricing" class="nav-pill">Pricing (KES)</a>
                        <a href="#block-stock" class="nav-pill">Stock &amp; Orders</a>
                        <a href="#block-payment" class="nav-pill">M-Pesa Payment</a>
                        <a href="#block-delivery" class="nav-pill">Delivery</a>
                        <a href="#block-cancellation" class="nav-pill">Cancellation</a>
                        <a href="#block-returns" class="nav-pill">Returns</a>
                        <a href="#block-ownership" class="nav-pill">Proprietary Rights</a>
                        <a href="#block-liability" class="nav-pill">Liability</a>
                        <a href="#block-governing-law" class="nav-pill">Governing Law</a>
                    </div>
                </div>
            </div>

            <!-- Terms Content Articles -->
            <div class="row justify-content-center mt-5">
                <div class="col-lg-8">
                    <div class="terms-content">

                        <!-- Agreement -->
                        <article id="block-agreement" class="term-card mb-4 p-4 border rounded bg-white">
                            <div class="term-header mb-3">
                                <span class="term-label text-primary fw-bold small">Section 01</span>
                                <h3 class="h4">Agreement to Conditions</h3>
                            </div>
                            <div class="term-body">
                                <p>By accessing <?= e(setting('store_name', 'ShopWise Kenya')) ?> or placing an order through our storefront, you agree to be legally bound by these Terms of Service and all related policies referenced herein. If you do not accept these provisions, please do not use our services.</p>
                                <div class="info-banner p-3 bg-light rounded border-start border-4 border-primary">
                                    <i class="bi bi-info-circle text-primary me-2"></i>
                                    <span>These conditions apply to all customers, visitors, and users interacting with our catalog, shopping bag, and checkout facilities.</span>
                                </div>
                            </div>
                        </article>

                        <!-- Accounts & Reviews -->
                        <article id="block-accounts" class="term-card mb-4 p-4 border rounded bg-white">
                            <div class="term-header mb-3">
                                <span class="term-label text-primary fw-bold small">Section 02</span>
                                <h3 class="h4">Customer Accounts &amp; Verified Reviews</h3>
                            </div>
                            <div class="term-body">
                                <p>You may create an account using your email address and password, or by authenticating with Google OAuth 2.0. You are solely responsible for maintaining the confidentiality of your login credentials and for all activities that occur under your account.</p>
                                <ul class="item-list list-unstyled ps-2">
                                    <li class="mb-2"><i class="bi bi-check-lg text-success me-2"></i> <strong>Account Security:</strong> If you suspect unauthorized access to your account, you must immediately change your password or notify customer support.</li>
                                    <li class="mb-2"><i class="bi bi-check-lg text-success me-2"></i> <strong>Verified Buyer Reviews:</strong> To maintain integrity and prevent fraudulent ratings, reviews may only be submitted by verified customers who have a delivered or paid order containing that product. All reviews are moderated before publication.</li>
                                    <li class="mb-2"><i class="bi bi-check-lg text-success me-2"></i> <strong>Account Deletion:</strong> Customers may request account closure and personal data deletion at any time by emailing customer support at <a href="mailto:<?= e(setting('support_email', 'support@shopwise.co.ke')) ?>"><?= e(setting('support_email', 'support@shopwise.co.ke')) ?></a>.</li>
                                </ul>
                            </div>
                        </article>

                        <!-- Pricing in KES -->
                        <article id="block-pricing" class="term-card mb-4 p-4 border rounded bg-white">
                            <div class="term-header mb-3">
                                <span class="term-label text-primary fw-bold small">Section 02</span>
                                <h3 class="h4">Product Pricing &amp; Currency</h3>
                            </div>
                            <div class="term-body">
                                <p>All product prices displayed on the store are stated strictly in <strong>Kenyan Shillings (KES)</strong> and are inclusive of applicable Value Added Tax (VAT) under Kenyan revenue legislation unless specifically stated otherwise.</p>
                                <ul class="item-list list-unstyled ps-2">
                                    <li class="mb-2"><i class="bi bi-check-lg text-success me-2"></i> Prices do not include delivery fees, which are calculated based on your destination zone or delivery method at checkout.</li>
                                    <li class="mb-2"><i class="bi bi-check-lg text-success me-2"></i> We reserve the right to correct pricing errors before order fulfillment. If an obvious pricing error occurs, we will contact you before dispatch or cancel and refund the order.</li>
                                </ul>
                            </div>
                        </article>

                        <!-- Stock Availability -->
                        <article id="block-stock" class="term-card mb-4 p-4 border rounded bg-white">
                            <div class="term-header mb-3">
                                <span class="term-label text-primary fw-bold small">Section 03</span>
                                <h3 class="h4">Stock Availability &amp; Order Acceptance</h3>
                            </div>
                            <div class="term-body">
                                <p>All products listed in our store are subject to physical warehouse availability. Adding an item to your shopping bag does not reserve stock until your order is submitted and confirmed.</p>
                                <p>In the unlikely event that an item becomes unavailable after payment due to concurrent purchasing, we will notify you immediately and issue a full refund via M-Pesa within 24 hours.</p>
                            </div>
                        </article>

                        <!-- Payment via M-Pesa -->
                        <article id="block-payment" class="term-card mb-4 p-4 border rounded bg-white">
                            <div class="term-header mb-3">
                                <span class="term-label text-primary fw-bold small">Section 04</span>
                                <h3 class="h4">M-Pesa Payment Settlement</h3>
                            </div>
                            <div class="term-body">
                                <p>Payments must be settled through Safaricom M-Pesa using our automated STK Push prompt or store Paybill shortcode. Unpaid pending orders are held for <strong><?= $orderExpiryMinutes ?> minutes</strong> before automatic expiration to release stock back to other shoppers.</p>
                                <div class="alert-box p-3 bg-light rounded border">
                                    <div class="alert-title d-flex align-items-center mb-2">
                                        <i class="bi bi-shield-lock text-primary me-2"></i>
                                        <h5 class="mb-0 fs-6">Payment Verification</h5>
                                    </div>
                                    <p class="small text-muted mb-0">Never send money to personal numbers. Authorized store transactions are conducted only through our automated storefront M-Pesa integration.</p>
                                </div>
                            </div>
                        </article>

                        <!-- Delivery & Collection -->
                        <article id="block-delivery" class="term-card mb-4 p-4 border rounded bg-white">
                            <div class="term-header mb-3">
                                <span class="term-label text-primary fw-bold small">Section 05</span>
                                <h3 class="h4">Delivery &amp; Collection</h3>
                            </div>
                            <div class="term-body">
                                <p>Deliveries are fulfilled across our active delivery zones in accordance with our <a href="<?= e(url('shipping-info')) ?>">Shipping Information</a> terms. Delivery schedules are estimates provided by logistics partners and may be affected by adverse weather, traffic, or remote regional access.</p>
                                <p>In-store pickup orders must be collected within 7 business days of notification from our Moi Avenue collection point.</p>
                            </div>
                        </article>

                        <!-- Order Cancellation -->
                        <article id="block-cancellation" class="term-card mb-4 p-4 border rounded bg-white">
                            <div class="term-header mb-3">
                                <span class="term-label text-primary fw-bold small">Section 06</span>
                                <h3 class="h4">Order Cancellation</h3>
                            </div>
                            <div class="term-body">
                                <p>You may cancel an order free of charge by contacting customer support within 30 minutes of placement or at any time before the package has been handed over to couriers for dispatch. Once dispatched, cancellations are handled under our Return Policy.</p>
                            </div>
                        </article>

                        <!-- Returns & Refunds -->
                        <article id="block-returns" class="term-card mb-4 p-4 border rounded bg-white">
                            <div class="term-header mb-3">
                                <span class="term-label text-primary fw-bold small">Section 07</span>
                                <h3 class="h4">Returns &amp; Refunds Policy Reference</h3>
                            </div>
                            <div class="term-body">
                                <p>Returns and refunds are governed strictly by our <a href="<?= e(url('return-policy')) ?>">Return &amp; Refund Policy</a>. Qualifying items may be returned within <strong><?= $returnDays ?> days</strong> of delivery, and approved refunds are disbursed within <strong><?= $refundDays ?> business days</strong> via M-Pesa.</p>
                            </div>
                        </article>

                        <!-- Content Ownership -->
                        <article id="block-ownership" class="term-card mb-4 p-4 border rounded bg-white">
                            <div class="term-header mb-3">
                                <span class="term-label text-primary fw-bold small">Section 08</span>
                                <h3 class="h4">Intellectual Property &amp; Acceptable Use</h3>
                            </div>
                            <div class="term-body">
                                <p>All site design, product photography, text, logos, code, and interface assets are the property of <?= e(setting('store_name', 'ShopWise Kenya')) ?>. Unauthorized scraping, automated probing, bulk extraction, or reverse-engineering is strictly prohibited.</p>
                            </div>
                        </article>

                        <!-- Limitation of Liability -->
                        <article id="block-liability" class="term-card mb-4 p-4 border rounded bg-white">
                            <div class="term-header mb-3">
                                <span class="term-label text-primary fw-bold small">Section 09</span>
                                <h3 class="h4">Limitation of Liability</h3>
                            </div>
                            <div class="term-body">
                                <p>To the maximum extent permitted under Kenyan commercial law, our total liability for any claim arising out of or related to your purchase shall not exceed the actual amount paid for the specific goods in question.</p>
                            </div>
                        </article>

                        <!-- Governing Law -->
                        <article id="block-governing-law" class="term-card mb-4 p-4 border rounded bg-white">
                            <div class="term-header mb-3">
                                <span class="term-label text-primary fw-bold small">Section 10</span>
                                <h3 class="h4">Governing Law &amp; Jurisdiction</h3>
                            </div>
                            <div class="term-body">
                                <p>These Terms of Service and any contractual disputes arising between <?= e(setting('store_name', 'ShopWise Kenya')) ?> and customers shall be governed by and construed in accordance with the <strong>laws of the Republic of Kenya</strong>. The parties submit to the jurisdiction of the competent courts of Nairobi, Kenya.</p>
                            </div>
                        </article>

                    </div>
                </div>
            </div>

        </div>

    </section><!-- /Terms Of Service Section -->

</main>

<?php include __DIR__ . '/partials/footer.php'; ?>
