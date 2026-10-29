<?php
/**
 * Return & Refund Policy View
 * Based on _template_reference/storefront_pages/return-policy.html (lines 835-1020)
 */
$pageTitle = 'Return & Refund Policy';
$metaDescription = 'Review our ' . (int)setting('return_window_days', 7) . '-day return and refund policy for clothing, shoes, and everyday essentials at ' . setting('store_name', 'ShopWise Kenya') . '.';
$bodyClass = 'return-policy-page';

$breadcrumbs = [
    ['label' => 'Return Policy', 'url' => null],
];

$returnDays = (int)setting('return_window_days', 7);
$refundDays = (int)setting('refund_processing_days', 7);

include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/breadcrumbs.php';
include __DIR__ . '/partials/flash.php';
?>

<main class="main">

    <!-- Retun Policy Section -->
    <section id="retun-policy" class="retun-policy section">

        <div class="container">

            <!-- Return Guarantee Banner -->
            <div class="row align-items-center policy-hero">
                <div class="col-lg-8">
                    <div class="hero-content">
                        <span class="policy-badge"><i class="bi bi-shield-check"></i> Customer Peace of Mind</span>
                        <h2>Straightforward Returns &amp; Exchanges</h2>
                        <p>We want you to be completely satisfied with every order from <?= e(setting('store_name', 'ShopWise Kenya')) ?>. If an item does not fit or meet your expectations, we offer a simple return and exchange process.</p>
                    </div>
                </div>
                <div class="col-lg-4 text-lg-end text-center">
                    <div class="guarantee-widget">
                        <div class="widget-number"><?= $returnDays ?></div>
                        <div class="widget-label">Day Return Window</div>
                        <div class="widget-sub">From delivery date</div>
                    </div>
                </div>
            </div><!-- End Return Guarantee Banner -->

            <!-- Policy Highlights -->
            <div class="row gy-3 features-grid">
                <div class="col-lg-4 col-md-6">
                    <div class="feature-card">
                        <div class="feature-card-header">
                            <div class="feature-icon">
                                <i class="bi bi-box-seam"></i>
                            </div>
                            <h4>Convenient Drop-Off</h4>
                        </div>
                        <div class="feature-card-body">
                            <p>Drop off returned items at our central Nairobi CBD pickup point or coordinate parcel return via courier services.</p>
                        </div>
                        <div class="feature-card-footer">
                            <a href="<?= e(url('contact')) ?>" class="ghost-link">Contact Support <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4 col-md-6">
                    <div class="feature-card">
                        <div class="feature-card-header">
                            <div class="feature-icon">
                                <i class="bi bi-phone"></i>
                            </div>
                            <h4>Direct M-Pesa Refunds</h4>
                        </div>
                        <div class="feature-card-body">
                            <p>Approved refunds are disbursed directly to your original M-Pesa transaction number within <?= $refundDays ?> business days after item inspection.</p>
                        </div>
                        <div class="feature-card-footer">
                            <a href="<?= e(url('faq')) ?>" class="ghost-link">Refund FAQ <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4 col-md-6">
                    <div class="feature-card">
                        <div class="feature-card-header">
                            <div class="feature-icon">
                                <i class="bi bi-repeat"></i>
                            </div>
                            <h4>Fast Size &amp; Color Swaps</h4>
                        </div>
                        <div class="feature-card-body">
                            <p>Need a different shoe size or clothing fit? Contact us promptly and we will reserve the replacement variant while your return is en route.</p>
                        </div>
                        <div class="feature-card-footer">
                            <a href="<?= e(url('contact')) ?>" class="ghost-link">Request Exchange <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
                </div>
            </div><!-- End Policy Highlights -->

            <!-- Process Steps -->
            <div class="steps-section">
                <div class="section-label">Step-by-Step Guide</div>
                <h3 class="section-heading">How to Initiate a Return</h3>
                <div class="row gy-3">
                    <div class="col-md-6 col-lg-3">
                        <div class="step-card">
                            <div class="step-indicator">
                                <span class="step-num">1</span>
                                <span class="step-line"></span>
                            </div>
                            <h5>Contact Customer Care</h5>
                            <p>Reach out through our <a href="<?= e(url('contact')) ?>">Contact form</a>, email, or telephone with your order reference number and the reason for return.</p>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="step-card">
                            <div class="step-indicator">
                                <span class="step-num">2</span>
                                <span class="step-line"></span>
                            </div>
                            <h5>Pack Your Items Securely</h5>
                            <p>Package the item in its original box or protective packaging with all tags, labels, and accessories attached.</p>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="step-card">
                            <div class="step-indicator">
                                <span class="step-num">3</span>
                                <span class="step-line"></span>
                            </div>
                            <h5>Deliver or Ship to Hub</h5>
                            <p>Drop the package at our Moi Avenue, Nairobi CBD collection point or send via courier to our dispatch hub address.</p>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="step-card">
                            <div class="step-indicator">
                                <span class="step-num">4</span>
                            </div>
                            <h5>Receive Your Refund</h5>
                            <p>Following item verification, your refund is disbursed via M-Pesa within <?= $refundDays ?> business days.</p>
                        </div>
                    </div>
                </div>
            </div><!-- End Process Steps -->

            <!-- Eligibility & Exclusions Grid -->
            <div class="row gy-4 eligibility-grid">
                <div class="col-lg-7">
                    <div class="eligibility-card">
                        <div class="card-head">
                            <h3>Product Condition Requirements</h3>
                            <p>To qualify for a return or exchange, items must meet the following department criteria:</p>
                        </div>
                        <div class="row gy-3">
                            <div class="col-sm-6">
                                <div class="requirement-item">
                                    <div class="req-icon"><i class="bi bi-check-lg"></i></div>
                                    <div class="req-content">
                                        <h6>Clothing &amp; Apparel</h6>
                                        <p>Must be unworn, unwashed, with original brand tags attached and free of stains, scents, or deodorant marks.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="requirement-item">
                                    <div class="req-icon"><i class="bi bi-check-lg"></i></div>
                                    <div class="req-content">
                                        <h6>Footwear &amp; Shoes</h6>
                                        <p>Must show zero outdoor wear; soles must be clean and unblemished, with the original intact shoebox included.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="requirement-item">
                                    <div class="req-icon"><i class="bi bi-check-lg"></i></div>
                                    <div class="req-content">
                                        <h6>Within <?= $returnDays ?> Days</h6>
                                        <p>Notice of return must be received within <?= $returnDays ?> calendar days of parcel delivery confirmation.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="requirement-item">
                                    <div class="req-icon"><i class="bi bi-check-lg"></i></div>
                                    <div class="req-content">
                                        <h6>Proof of Purchase</h6>
                                        <p>Your order reference number or delivery receipt is required to verify the transaction.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="exclusions-card">
                        <div class="card-head">
                            <i class="bi bi-exclamation-circle"></i>
                            <h4>Non-Returnable Items</h4>
                        </div>
                        <ul class="exclusion-list">
                            <li>
                                <i class="bi bi-x"></i>
                                <span>Underwear, socks, and intimate apparel (due to public health &amp; hygiene standards)</span>
                            </li>
                            <li>
                                <i class="bi bi-x"></i>
                                <span>Pierced jewelry and earrings</span>
                            </li>
                            <li>
                                <i class="bi bi-x"></i>
                                <span>Opened personal care and beauty products</span>
                            </li>
                            <li>
                                <i class="bi bi-x"></i>
                                <span>Clearance items specifically designated as final sale</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div><!-- End Eligibility & Exclusions -->

        </div>

    </section><!-- /Retun Policy Section -->

</main>

<?php include __DIR__ . '/partials/footer.php'; ?>
