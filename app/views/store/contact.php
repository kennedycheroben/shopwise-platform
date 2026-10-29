<?php
/**
 * Contact Us View
 * Based on _template_reference/storefront_pages/contact.html (lines 835-971)
 */
$pageTitle = 'Contact Us';
$metaDescription = 'Get in touch with ' . setting('store_name', 'ShopWise Kenya') . '. Reach our Nairobi customer care team by phone, email, or contact form for orders, shipping, and returns.';
$bodyClass = 'contact-page';

$breadcrumbs = [
    ['label' => 'Contact Us', 'url' => null],
];

include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/breadcrumbs.php';
include __DIR__ . '/partials/flash.php';

$storeLat = setting('store_latitude', '-1.286389');
$storeLng = setting('store_longitude', '36.817223');
$hasCoordinates = !empty($storeLat) && !empty($storeLng);
$osmUrl = $hasCoordinates ? "https://www.openstreetmap.org/?mlat=" . rawurlencode($storeLat) . "&mlon=" . rawurlencode($storeLng) . "#map=16/" . rawurlencode($storeLat) . "/" . rawurlencode($storeLng) : null;
$supportHours = (int)setting('support_response_hours', 24);
?>

<main class="main">

    <!-- Contact 2 Section -->
    <section id="contact-2" class="contact-2 section">

        <div class="container">

            <!-- Success / Error General Alert -->
            <?php if (!empty($contactSuccess)): ?>
                <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <?= e($contactSuccess) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (!empty($formErrors['general'])): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?= e($formErrors['general']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Contact Info Cards -->
            <div class="row g-3 mb-5">
                <div class="col-lg-4 col-md-6">
                    <div class="info-card">
                        <div class="info-card-icon">
                            <i class="bi bi-pin-map"></i>
                        </div>
                        <div class="info-card-body">
                            <h5>Pickup &amp; Hub Location</h5>
                            <p><?= e(setting('pickup_address', 'Moi Avenue, Nairobi CBD')) ?></p>
                        </div>
                    </div>
                </div><!-- End Info Card -->

                <div class="col-lg-4 col-md-6">
                    <div class="info-card">
                        <div class="info-card-icon">
                            <i class="bi bi-envelope"></i>
                        </div>
                        <div class="info-card-body">
                            <h5>Email Inquiries</h5>
                            <p><a href="mailto:<?= e(setting('support_email', 'support@kennedycheroben.co.ke')) ?>"><?= e(setting('support_email', 'support@kennedycheroben.co.ke')) ?></a></p>
                            <p><small class="text-muted">Target response time: <?= $supportHours ?> hours</small></p>
                        </div>
                    </div>
                </div><!-- End Info Card -->

                <div class="col-lg-4 col-md-12">
                    <div class="info-card">
                        <div class="info-card-icon">
                            <i class="bi bi-clock"></i>
                        </div>
                        <div class="info-card-body">
                            <h5>Operating Hours</h5>
                            <p><?= e(setting('business_hours', 'Mon-Sat, 8:00 am - 6:00 pm EAT')) ?></p>
                            <p><a href="tel:<?= e(normalize_phone(setting('support_phone')) ?? '+254700000000') ?>"><?= e(setting('support_phone', '+254 700 000 000')) ?></a></p>
                        </div>
                    </div>
                </div><!-- End Info Card -->
            </div><!-- End Contact Info Cards -->

            <div class="row g-4 align-items-start">

                <!-- Form Column -->
                <div class="col-lg-7">
                    <div class="form-card">
                        <div class="form-card-header">
                            <h3>Send Us a Message</h3>
                            <p>Have an inquiry regarding a product, order delivery, or return request? Fill out the form below and our Nairobi support team will respond within <?= $supportHours ?> hours.</p>
                        </div>

                        <form action="<?= e(url('contact')) ?>" method="POST" class="contact-form-post" novalidate>
                            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="form_time" value="<?= time() ?>">

                            <!-- Honeypot Field (Hidden for bots) -->
                            <div style="display:none;" aria-hidden="true">
                                <label for="website_hp">Leave this field empty</label>
                                <input type="text" id="website_hp" name="website" tabindex="-1" autocomplete="off">
                            </div>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="contact_name">Full Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control <?= !empty($formErrors['name']) ? 'is-invalid' : '' ?>" id="contact_name" name="name" placeholder="e.g. Jane Doe" value="<?= e($formValues['name'] ?? '') ?>" required autocomplete="name">
                                    <?php if (!empty($formErrors['name'])): ?>
                                        <div class="invalid-feedback d-block"><?= e($formErrors['name']) ?></div>
                                    <?php endif; ?>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label" for="contact_email">Email Address <span class="text-danger">*</span></label>
                                    <input type="email" class="form-control <?= !empty($formErrors['email']) ? 'is-invalid' : '' ?>" id="contact_email" name="email" placeholder="e.g. jane@example.com" value="<?= e($formValues['email'] ?? '') ?>" required autocomplete="email">
                                    <?php if (!empty($formErrors['email'])): ?>
                                        <div class="invalid-feedback d-block"><?= e($formErrors['email']) ?></div>
                                    <?php endif; ?>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label" for="contact_phone">Kenyan Phone Number <small class="text-muted">(Optional)</small></label>
                                    <input type="tel" class="form-control <?= !empty($formErrors['phone']) ? 'is-invalid' : '' ?>" id="contact_phone" name="phone" placeholder="e.g. 0712345678" value="<?= e($formValues['phone'] ?? '') ?>" autocomplete="tel">
                                    <?php if (!empty($formErrors['phone'])): ?>
                                        <div class="invalid-feedback d-block"><?= e($formErrors['phone']) ?></div>
                                    <?php endif; ?>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label" for="contact_subject">Subject <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control <?= !empty($formErrors['subject']) ? 'is-invalid' : '' ?>" id="contact_subject" name="subject" placeholder="What is this inquiry about?" value="<?= e($formValues['subject'] ?? '') ?>" required>
                                    <?php if (!empty($formErrors['subject'])): ?>
                                        <div class="invalid-feedback d-block"><?= e($formErrors['subject']) ?></div>
                                    <?php endif; ?>
                                </div>

                                <div class="col-12">
                                    <label class="form-label" for="contact_message">Message <span class="text-danger">*</span></label>
                                    <textarea class="form-control <?= !empty($formErrors['message']) ? 'is-invalid' : '' ?>" id="contact_message" name="message" rows="5" placeholder="Please share your questions, order number, or details here..." required><?= e($formValues['message'] ?? '') ?></textarea>
                                    <?php if (!empty($formErrors['message'])): ?>
                                        <div class="invalid-feedback d-block"><?= e($formErrors['message']) ?></div>
                                    <?php endif; ?>
                                    <div class="form-text">Between 10 and 3,000 characters.</div>
                                </div>

                                <div class="col-12">
                                    <button type="submit" class="btn-submit">Send Message</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div><!-- End Form Column -->

                <!-- Sidebar Column -->
                <div class="col-lg-5">
                    <?php if ($osmUrl): ?>
                        <div class="map-card p-3 border rounded bg-light mb-4">
                            <h5 class="mb-2"><i class="bi bi-geo-alt-fill text-primary me-2"></i>Map Location</h5>
                            <p class="small text-muted mb-3">Our Nairobi CBD collection point is centrally situated for convenient order pickup.</p>
                            <a href="<?= e($osmUrl) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary btn-sm w-100">
                                <i class="bi bi-box-arrow-up-right me-1"></i> View on OpenStreetMap
                            </a>
                        </div>
                    <?php endif; ?>

                    <div class="connect-card p-4 border rounded bg-white">
                        <h5>Customer Care Commitment</h5>
                        <p class="text-muted">We take pride in providing responsive, transparent service to all our shoppers across Kenya.</p>
                        <ul class="list-unstyled mb-0">
                            <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i> Target response within <?= $supportHours ?> business hours</li>
                            <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i> Direct phone and email assistance</li>
                            <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i> Order updates sent via email</li>
                        </ul>
                    </div><!-- End Connect Card -->
                </div><!-- End Sidebar Column -->

            </div>

        </div>

    </section><!-- /Contact 2 Section -->

</main>

<?php include __DIR__ . '/partials/footer.php'; ?>
