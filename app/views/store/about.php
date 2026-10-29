<?php
/**
 * About Us View
 * Based on _template_reference/storefront_pages/about.html (lines 835-923)
 */
$pageTitle = 'About Us';
$metaDescription = 'Learn about ' . setting('store_name', 'ShopWise Kenya') . ', your online shopping destination for quality everyday essentials dispatched from Nairobi.';
$bodyClass = 'about-page';

$breadcrumbs = [
    ['label' => 'About Us', 'url' => null],
];

include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/breadcrumbs.php';
include __DIR__ . '/partials/flash.php';
?>

<main class="main">

    <!-- About 2 Section -->
    <section id="about-2" class="about-2 section">

        <div class="container">

            <div class="row gy-5">
                <div class="col-lg-5">
                    <div class="intro-block">
                        <span class="label-badge"><i class="bi bi-shop"></i> About <?= e(setting('store_name', 'ShopWise Kenya')) ?></span>
                        <h2 class="section-heading">Quality Everyday Essentials, Dispatched from Nairobi</h2>
                        <p class="section-desc"><?= e(setting('store_name', 'ShopWise Kenya')) ?> is an established Kenyan online retailer operating from Nairobi. We provide individuals, families, and businesses with curated, genuine essentials backed by fast dispatch, fair transparent pricing in Kenyan Shillings, and secure M-Pesa payment.</p>
                        <a href="<?= e(url('shop')) ?>" class="btn-primary-action">Explore Catalog <i class="bi bi-arrow-right"></i></a>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="video-card">
                        <img src="<?= e(asset('assets/img/about/about-wide-3.webp')) ?>" class="img-fluid" alt="<?= e(setting('store_name', 'ShopWise Kenya')) ?> Dispatch Operations" width="800" height="450" loading="eager">
                    </div>
                    <div class="row mt-3 gy-3">
                        <div class="col-sm-6">
                            <p class="supporting-text">Every product in our inventory is thoroughly inspected for authenticity and quality before dispatch from our Nairobi fulfillment center.</p>
                        </div>
                        <div class="col-sm-6">
                            <p class="supporting-text">We coordinate direct deliveries and parcel pickups across our active delivery zones and offer in-person pickup from our central Nairobi CBD depot.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row gy-4 mt-4">
                <div class="col-lg-4 col-md-6">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-truck"></i>
                        </div>
                        <div class="feature-content">
                            <h3><a href="<?= e(url('shipping-info')) ?>" class="stretched-link">Regional Zone Delivery</a></h3>
                            <p>Same-day to 1 business day dispatch within Nairobi CBD; 1 to 4 business days for regional and upcountry zones.</p>
                        </div>
                    </div><!-- End Feature Card -->
                </div>

                <div class="col-lg-4 col-md-6">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-phone"></i>
                        </div>
                        <div class="feature-content">
                            <h3><a href="<?= e(url('faq')) ?>" class="stretched-link">M-Pesa Verified Payments</a></h3>
                            <p>Seamless checkout via Safaricom M-Pesa STK Push and Paybill with immediate payment confirmations.</p>
                        </div>
                    </div><!-- End Feature Card -->
                </div>

                <div class="col-lg-4 col-md-6">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </div>
                        <div class="feature-content">
                            <h3><a href="<?= e(url('return-policy')) ?>" class="stretched-link"><?= (int)setting('return_window_days', 7) ?>-Day Return Window</a></h3>
                            <p>Simple return requests with <?= (int)setting('refund_processing_days', 7) ?> business days refund processing for eligible items.</p>
                        </div>
                    </div><!-- End Feature Card -->
                </div>
            </div>

        </div>

    </section><!-- /About 2 Section -->

</main>

<?php include __DIR__ . '/partials/footer.php'; ?>
