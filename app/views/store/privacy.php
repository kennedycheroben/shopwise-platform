<?php
/**
 * Privacy Policy View
 * Based on _template_reference/storefront_pages/privacy.html (lines 835-1105)
 * Compliant with Kenya Data Protection Act, 2019
 */
$pageTitle = 'Privacy Policy';
$metaDescription = 'Read our privacy policy under the Kenya Data Protection Act, 2019. Learn how ' . setting('store_name', 'ShopWise Kenya') . ' collects, protects, and handles personal data and M-Pesa details.';
$bodyClass = 'privacy-page';

$breadcrumbs = [
    ['label' => 'Privacy Policy', 'url' => null],
];

include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/breadcrumbs.php';
include __DIR__ . '/partials/flash.php';
?>

<main class="main">

    <!-- Privacy Section -->
    <section id="privacy" class="privacy section">

        <div class="container">

            <!-- Policy Header -->
            <div class="row justify-content-center mb-5">
                <div class="col-lg-8 text-center">
                    <div class="policy-header">
                        <span class="policy-badge"><i class="bi bi-calendar3"></i> In Effect: 2026</span>
                        <h2>Privacy Policy &amp; Data Protection</h2>
                        <p><?= e(setting('store_name', 'ShopWise Kenya')) ?> is committed to safeguarding the personal privacy of our shoppers in accordance with the <strong>Kenya Data Protection Act, 2019</strong> and applicable data protection regulations. This document outlines how we collect, handle, store, and protect your information when using our online store.</p>
                    </div>
                </div>
            </div>

            <div class="row justify-content-center mb-5">
                <div class="col-lg-8">
                    <div class="trust-badges d-flex flex-wrap justify-content-center gap-3">
                        <div class="trust-badge-item">
                            <i class="bi bi-shield-check"></i>
                            <span>Kenya DPA 2019 Principles</span>
                        </div>
                        <div class="trust-badge-item">
                            <i class="bi bi-lock"></i>
                            <span>Encrypted Data Transmission</span>
                        </div>
                        <div class="trust-badge-item">
                            <i class="bi bi-person-check"></i>
                            <span>Data Subject Rights Respected</span>
                        </div>
                    </div>
                </div>
            </div><!-- End Policy Header -->

            <!-- Data Practices Grid -->
            <div class="row justify-content-center mb-5">
                <div class="col-lg-10">
                    <div class="practices-section">
                        <h3 class="section-label">Data Practices</h3>
                        <p class="section-desc">Clear information on what personal data we collect, why we collect it, and who receives access</p>
                        <div class="practices-grid row g-3 mt-3">
                            <div class="col-lg-4">
                                <div class="practice-card">
                                    <div class="practice-card-header">
                                        <i class="bi bi-inbox"></i>
                                        <h4>Information Gathered</h4>
                                    </div>
                                    <div class="practice-card-content">
                                        <ul>
                                            <li><i class="bi bi-check"></i> Customer name, telephone number, and email address</li>
                                            <li><i class="bi bi-check"></i> Account credentials (email, hashed password, or Google profile ID for Google OAuth)</li>
                                            <li><i class="bi bi-check"></i> Safaricom M-Pesa number for payments &amp; refunds</li>
                                            <li><i class="bi bi-check"></i> Physical delivery location, county, and nearest estate</li>
                                            <li><i class="bi bi-check"></i> Order history, saved addresses, wishlist items, and transaction references</li>
                                            <li><i class="bi bi-check"></i> Essential cookies: <code>shopwise_cart</code> (guest cart token) and <code>PHPSESSID</code> (session security)</li>
                                            <li><i class="bi bi-check"></i> Customer care inquiries and feedback logs</li>
                                        </ul>
                                    </div>
                                </div>
                            </div><!-- End Practice Card -->

                            <div class="col-lg-4">
                                <div class="practice-card">
                                    <div class="practice-card-header">
                                        <i class="bi bi-gear"></i>
                                        <h4>How We Use Your Data</h4>
                                    </div>
                                    <div class="practice-card-content">
                                        <ul>
                                            <li><i class="bi bi-check"></i> Authenticate and secure customer accounts</li>
                                            <li><i class="bi bi-check"></i> Process, package, and dispatch your retail orders</li>
                                            <li><i class="bi bi-check"></i> Verify M-Pesa transactions and disburse approved refunds</li>
                                            <li><i class="bi bi-check"></i> Coordinate delivery with parcel courier and dispatch services</li>
                                            <li><i class="bi bi-check"></i> Send order confirmation receipts, verification links, and shipping notices</li>
                                            <li><i class="bi bi-check"></i> Respond to customer support inquiries and service requests</li>
                                            <li><i class="bi bi-check"></i> Satisfy statutory Kenyan tax and accounting requirements</li>
                                        </ul>
                                    </div>
                                </div>
                            </div><!-- End Practice Card -->

                            <div class="col-lg-4">
                                <div class="practice-card">
                                    <div class="practice-card-header">
                                        <i class="bi bi-shield-lock"></i>
                                        <h4>Third-Party Disclosures</h4>
                                    </div>
                                    <div class="practice-card-content">
                                        <ul>
                                            <li><i class="bi bi-check"></i> Parcel courier and delivery services for package transit only</li>
                                            <li><i class="bi bi-check"></i> Safaricom M-Pesa platform for payment settlement</li>
                                            <li><i class="bi bi-check"></i> Google OAuth API (strictly when customer elects to sign in with Google)</li>
                                            <li><i class="bi bi-check"></i> Never sold, rented, or traded to marketing networks</li>
                                            <li><i class="bi bi-check"></i> Disclosed only when mandated by Kenyan statutory law</li>
                                            <li><i class="bi bi-check"></i> Staff access restricted by role-based authorization</li>
                                        </ul>
                                    </div>
                                </div>
                            </div><!-- End Practice Card -->
                        </div>
                    </div>
                </div>
            </div><!-- End Data Practices Grid -->

            <!-- Data Retention Section -->
            <div class="row justify-content-center mb-5">
                <div class="col-lg-10">
                    <div class="security-section">
                        <h3 class="section-label">Data Retention Periods</h3>
                        <p class="section-desc">How long personal records are retained in compliance with statutory obligations</p>
                        <div class="row g-3 mt-3">
                            <div class="col-md-4">
                                <div class="security-item p-3 border rounded bg-white">
                                    <div class="security-icon"><i class="bi bi-receipt text-primary fs-3"></i></div>
                                    <div class="security-info mt-2">
                                        <h4>Financial &amp; Tax Records</h4>
                                        <p class="small text-muted mb-0">Retained for <strong>7 years</strong> in compliance with Section 23 of the Kenya Tax Procedures Act and commercial revenue laws.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="security-item p-3 border rounded bg-white">
                                    <div class="security-icon"><i class="bi bi-chat-left-dots text-primary fs-3"></i></div>
                                    <div class="security-info mt-2">
                                        <h4>Customer Service Messages</h4>
                                        <p class="small text-muted mb-0">Contact form submissions and support correspondence are kept for <strong>2 years</strong> to ensure order continuity and dispute resolution.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="security-item p-3 border rounded bg-white">
                                    <div class="security-icon"><i class="bi bi-person-badge text-primary fs-3"></i></div>
                                    <div class="security-info mt-2">
                                        <h4>Customer Accounts</h4>
                                        <p class="small text-muted mb-0">Active account profiles, saved addresses, and wishlists are retained while active. Customers may request account closure and data deletion at any time by contacting customer support.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="security-item p-3 border rounded bg-white">
                                    <div class="security-icon"><i class="bi bi-basket text-primary fs-3"></i></div>
                                    <div class="security-info mt-2">
                                        <h4>Cart Session Tokens</h4>
                                        <p class="small text-muted mb-0">Temporary guest cart session identifiers (<code>shopwise_cart</code>) are stored in client cookies and purged automatically after 30 days of inactivity.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div><!-- End Data Retention -->

            <!-- Your Rights Under Kenya DPA 2019 -->
            <div class="row justify-content-center mb-5">
                <div class="col-lg-10">
                    <div class="rights-section">
                        <div class="row align-items-start">
                            <div class="col-lg-5 mb-4 mb-lg-0">
                                <h3 class="section-label">Your Rights Under Kenya Law</h3>
                                <p class="section-desc">Under Part IV of the Kenya Data Protection Act, 2019, you possess specific legal rights concerning your personal information. You may exercise these rights at any time by contacting our support team.</p>
                                <a href="mailto:<?= e(setting('support_email', 'support@kennedycheroben.co.ke')) ?>" class="rights-link">Submit a Data Request <i class="bi bi-arrow-right"></i></a>
                            </div>
                            <div class="col-lg-7">
                                <div class="rights-list">
                                    <div class="rights-list-item">
                                        <div class="rights-list-icon"><i class="bi bi-eye"></i></div>
                                        <div class="rights-list-info">
                                            <h4>Right to Access</h4>
                                            <p>Request confirmation of whether we hold your personal data and obtain a readable copy of those records.</p>
                                        </div>
                                    </div><!-- End Rights Item -->
                                    <div class="rights-list-item">
                                        <div class="rights-list-icon"><i class="bi bi-pencil"></i></div>
                                        <div class="rights-list-info">
                                            <h4>Right to Rectification</h4>
                                            <p>Request prompt correction or completion of inaccurate, misleading, or outdated personal information.</p>
                                        </div>
                                    </div><!-- End Rights Item -->
                                    <div class="rights-list-item">
                                        <div class="rights-list-icon"><i class="bi bi-trash"></i></div>
                                        <div class="rights-list-info">
                                            <h4>Right to Erasure</h4>
                                            <p>Request deletion of personal data where there is no ongoing statutory or legal obligation requiring its retention.</p>
                                        </div>
                                    </div><!-- End Rights Item -->
                                    <div class="rights-list-item">
                                        <div class="rights-list-icon"><i class="bi bi-slash-circle"></i></div>
                                        <div class="rights-list-info">
                                            <h4>Right to Object</h4>
                                            <p>Object at any time to the processing of personal data for direct marketing or automated profiling.</p>
                                        </div>
                                    </div><!-- End Rights Item -->
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div><!-- End Your Rights -->

            <!-- Contact CTA -->
            <div class="row justify-content-center">
                <div class="col-lg-10">
                    <div class="contact-cta">
                        <div class="row align-items-center">
                            <div class="col-lg-8 mb-3 mb-lg-0">
                                <h4>Data Protection Inquiries</h4>
                                <p>To submit a data access or deletion request, contact our support team at <a href="mailto:<?= e(setting('support_email', 'support@kennedycheroben.co.ke')) ?>"><?= e(setting('support_email', 'support@kennedycheroben.co.ke')) ?></a>.</p>
                            </div>
                            <div class="col-lg-4 text-lg-end">
                                <a href="<?= e(url('contact')) ?>" class="cta-btn">Contact Us <i class="bi bi-arrow-right"></i></a>
                            </div>
                        </div>
                    </div>
                </div>
            </div><!-- End Contact CTA -->

        </div>

    </section><!-- /Privacy Section -->

</main>

<?php include __DIR__ . '/partials/footer.php'; ?>
