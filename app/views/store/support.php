<?php
/**
 * Customer Support Center View
 * Based on _template_reference/storefront_pages/support.html (lines 835-1070)
 */
$pageTitle = 'Customer Support';
$metaDescription = 'Get in touch with customer care at ' . setting('store_name', 'ShopWise Kenya') . '. Reach our Nairobi team via phone, email, or help topics for delivery, returns, and ordering assistance.';
$bodyClass = 'support-page';

$breadcrumbs = [
    ['label' => 'Customer Support', 'url' => null],
];

$supportPhone = setting('support_phone', '+254 700 000 000');
$supportEmail = setting('support_email', 'support@kennedycheroben.co.ke');
$supportHours = (int)setting('support_response_hours', 24);
$businessHours = setting('business_hours', 'Mon-Sat, 8:00 am - 6:00 pm EAT');

include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/breadcrumbs.php';
include __DIR__ . '/partials/flash.php';
?>

<main class="main">

    <!-- Support Section -->
    <section id="support" class="support section">

        <div class="container">

            <!-- Intro Header -->
            <div class="row justify-content-center mb-5">
                <div class="col-lg-8 text-center">
                    <span class="section-badge"><i class="bi bi-headset"></i> Customer Care</span>
                    <h2 class="mt-2 mb-3">How Can We Help You Today?</h2>
                    <p class="text-muted">Our dedicated customer support team in Nairobi is on standby to assist with order status, product inquiries, shipping coordination, and returns.</p>
                </div>
            </div>

            <!-- Contact Channels Cards -->
            <div class="row g-3 mb-5">
                <div class="col-lg-4 col-md-6">
                    <div class="contact-card p-4 border rounded bg-white text-center h-100">
                        <div class="contact-card-header mb-3">
                            <i class="bi bi-telephone text-primary fs-2"></i>
                            <h5 class="mt-2">Telephone &amp; WhatsApp</h5>
                        </div>
                        <p class="text-muted small">Speak directly with our support team during store business hours (<?= e($businessHours) ?>).</p>
                        <a href="tel:<?= e(normalize_phone($supportPhone) ?? '+254700000000') ?>" class="card-action fw-bold text-decoration-none text-primary"><?= e($supportPhone) ?> <i class="bi bi-arrow-right"></i></a>
                    </div>
                </div><!-- End Contact Card -->

                <div class="col-lg-4 col-md-6">
                    <div class="contact-card p-4 border rounded bg-white text-center h-100">
                        <div class="contact-card-header mb-3">
                            <i class="bi bi-envelope text-primary fs-2"></i>
                            <h5 class="mt-2">Email Assistance</h5>
                        </div>
                        <p class="text-muted small">Drop us an email anytime. We review and respond to inquiries within <?= $supportHours ?> hours.</p>
                        <a href="mailto:<?= e($supportEmail) ?>" class="card-action fw-bold text-decoration-none text-primary"><?= e($supportEmail) ?> <i class="bi bi-arrow-right"></i></a>
                    </div>
                </div><!-- End Contact Card -->

                <div class="col-lg-4 col-md-12">
                    <div class="contact-card p-4 border rounded bg-white text-center h-100">
                        <div class="contact-card-header mb-3">
                            <i class="bi bi-chat-text text-primary fs-2"></i>
                            <h5 class="mt-2">Online Contact Form</h5>
                        </div>
                        <p class="text-muted small">Submit a message with your order reference number and our dispatch team will follow up promptly.</p>
                        <a href="<?= e(url('contact')) ?>" class="card-action fw-bold text-decoration-none text-primary">Open Contact Form <i class="bi bi-arrow-right"></i></a>
                    </div>
                </div><!-- End Contact Card -->
            </div><!-- End Contact Channels -->

            <!-- Topics Section -->
            <div class="topics-section mb-5">
                <div class="topics-header text-center mb-4">
                    <h3>Browse by Support Topic</h3>
                    <p class="text-muted">Find quick answers and step-by-step guidance across our store guides</p>
                </div>
                <div class="row g-3">
                    <div class="col-lg-3 col-md-6">
                        <a href="<?= e(url('shipping-info')) ?>" class="topic-card text-decoration-none d-block p-4 border rounded bg-white text-dark h-100">
                            <div class="topic-icon-wrap mb-3">
                                <i class="bi bi-box-seam text-primary fs-3"></i>
                            </div>
                            <div class="topic-body">
                                <h5 class="fw-semibold">Shipping &amp; Delivery</h5>
                                <ul class="list-unstyled text-muted small mb-0">
                                    <li>Delivery zones &amp; rates</li>
                                    <li>Estimated courier transit</li>
                                    <li>Nairobi CBD collection</li>
                                </ul>
                            </div>
                            <span class="topic-arrow text-primary mt-3 d-inline-block"><i class="bi bi-chevron-right"></i> View Shipping Guide</span>
                        </a>
                    </div><!-- End Topic Card -->

                    <div class="col-lg-3 col-md-6">
                        <a href="<?= e(url('faq')) ?>" class="topic-card text-decoration-none d-block p-4 border rounded bg-white text-dark h-100">
                            <div class="topic-icon-wrap mb-3">
                                <i class="bi bi-phone text-primary fs-3"></i>
                            </div>
                            <div class="topic-body">
                                <h5 class="fw-semibold">M-Pesa Payments</h5>
                                <ul class="list-unstyled text-muted small mb-0">
                                    <li>STK Push &amp; Paybill</li>
                                    <li>Payment verification</li>
                                    <li>Digital receipts</li>
                                </ul>
                            </div>
                            <span class="topic-arrow text-primary mt-3 d-inline-block"><i class="bi bi-chevron-right"></i> Payment FAQ</span>
                        </a>
                    </div><!-- End Topic Card -->

                    <div class="col-lg-3 col-md-6">
                        <a href="<?= e(url('return-policy')) ?>" class="topic-card text-decoration-none d-block p-4 border rounded bg-white text-dark h-100">
                            <div class="topic-icon-wrap mb-3">
                                <i class="bi bi-arrow-counterclockwise text-primary fs-3"></i>
                            </div>
                            <div class="topic-body">
                                <h5 class="fw-semibold">Returns &amp; Refunds</h5>
                                <ul class="list-unstyled text-muted small mb-0">
                                    <li><?= (int)setting('return_window_days', 7) ?>-day return window</li>
                                    <li>Product condition rules</li>
                                    <li>M-Pesa refund speed</li>
                                </ul>
                            </div>
                            <span class="topic-arrow text-primary mt-3 d-inline-block"><i class="bi bi-chevron-right"></i> Return Policy</span>
                        </a>
                    </div><!-- End Topic Card -->

                    <div class="col-lg-3 col-md-6">
                        <a href="<?= e(url('faq')) ?>" class="topic-card text-decoration-none d-block p-4 border rounded bg-white text-dark h-100">
                            <div class="topic-icon-wrap mb-3">
                                <i class="bi bi-question-circle text-primary fs-3"></i>
                            </div>
                            <div class="topic-body">
                                <h5 class="fw-semibold">General Store FAQ</h5>
                                <ul class="list-unstyled text-muted small mb-0">
                                    <li>Guest ordering steps</li>
                                    <li>Product sizing &amp; variants</li>
                                    <li>Stock availability</li>
                                </ul>
                            </div>
                            <span class="topic-arrow text-primary mt-3 d-inline-block"><i class="bi bi-chevron-right"></i> Browse All Questions</span>
                        </a>
                    </div><!-- End Topic Card -->
                </div>
            </div><!-- End Topics Section -->

        </div>

    </section><!-- /Support Section -->

</main>

<?php include __DIR__ . '/partials/footer.php'; ?>
