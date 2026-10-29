<?php
/**
 * Storefront Home View
 * Based on index.html
 */
$pageTitle = setting('store_name', 'ShopWise Kenya');
$metaDescription = setting('store_tagline', 'Quality everyday essentials, delivered across Kenya.');
$bodyClass = 'index-page';

include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/flash.php';
?>

<main class="main">

    <!-- Hero Section -->
    <section id="hero" class="hero section light-background py-5">
        <div class="container">
            <div class="row align-items-center gy-4">
                <div class="col-lg-6">
                    <span class="badge bg-primary-subtle text-primary mb-3 px-3 py-2 fs-6 rounded-pill">
                        <i class="bi bi-geo-alt-fill me-1"></i> Nairobi CBD Store &amp; Kenya Delivery Zones
                    </span>
                    <h1 class="display-4 fw-bold mb-3">Quality Essentials, Dispatched Across Kenya</h1>
                    <p class="lead text-muted mb-4">Discover verified clothing, footwear, everyday electronics, and home goods. Swift delivery within Nairobi and active delivery zones with seamless M-Pesa payments.</p>
                    <div class="d-flex flex-wrap gap-3">
                        <a href="<?= e(url('shop')) ?>" class="btn btn-primary btn-lg px-4 py-2">
                            <i class="bi bi-bag me-2"></i> Shop All Products
                        </a>
                        <a href="<?= e(url('category/' . ($categoriesTree[0]['slug'] ?? 'womens-clothing'))) ?>" class="btn btn-outline-dark btn-lg px-4 py-2">
                            Explore Categories
                        </a>
                    </div>
                </div>
                <div class="col-lg-6 text-center">
                    <img src="<?= e(asset('assets/img/product/product-showcase-2.webp')) ?>" alt="ShopWise Kenya Catalog" class="img-fluid rounded shadow-sm" width="600" height="440" style="max-height: 440px; object-fit: cover;">
                </div>
            </div>
        </div>
    </section>

    <!-- Category Tiles Section from Database -->
    <section id="featured-categories" class="featured-categories section py-5">
        <div class="container">
            <div class="section-title text-center mb-5">
                <h2>Browse by Category</h2>
                <p class="text-muted">Explore our curated collections of everyday essentials</p>
            </div>

            <div class="row g-4 justify-content-center">
                <?php foreach ($categoriesTree as $cat): ?>
                    <div class="col-6 col-md-4 col-lg-3">
                        <a href="<?= e(url('category/' . $cat['slug'])) ?>" class="text-decoration-none">
                            <div class="card h-100 border text-center p-3 category-card hover-shadow transition">
                                <div class="category-icon mb-3 text-primary">
                                    <i class="bi bi-grid-fill fs-1"></i>
                                </div>
                                <h5 class="card-title text-dark mb-1"><?= e($cat['name']) ?></h5>
                                <small class="text-muted">Explore Collection →</small>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Featured Products Section (disappears when empty) -->
    <?php if (!empty($featuredProducts)): ?>
        <section id="featured-products" class="featured-products section py-5 bg-light">
            <div class="container">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2 class="mb-1">Featured Products</h2>
                        <p class="text-muted mb-0">Handpicked items chosen for quality and reliability</p>
                    </div>
                    <a href="<?= e(url('shop?sort=featured')) ?>" class="btn btn-outline-primary btn-sm">View All →</a>
                </div>

                <div class="row g-3 g-lg-4">
                    <?php foreach ($featuredProducts as $p): ?>
                        <div class="col-6 col-md-4 col-lg-3">
                            <article class="product-tile card h-100 border-0 shadow-sm">
                                <div class="tile-visual position-relative overflow-hidden">
                                    <a href="<?= e(url('product/' . $p['slug'])) ?>">
                                        <img src="<?= e(product_image_url($p['primary_image'])) ?>" class="img-fluid w-100" alt="<?= e($p['name']) ?>" loading="lazy" style="height: 220px; object-fit: cover;">
                                    </a>
                                    <?php if (!empty($p['badges'])): ?>
                                        <span class="badge position-absolute top-0 start-0 m-2 <?= $p['badges'][0]['type'] === 'sale' ? 'bg-danger' : ($p['badges'][0]['type'] === 'sold_out' ? 'bg-secondary' : 'bg-warning text-dark') ?>">
                                            <?= e($p['badges'][0]['label']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-light wishlist-toggle-btn position-absolute top-0 end-0 m-2 rounded-circle shadow-sm <?= in_array((int)$p['id'], $wishlistProductIds ?? [], true) ? 'active text-danger' : 'text-secondary' ?>" data-product-id="<?= (int)$p['id'] ?>" title="Save to Wishlist" aria-label="Save to Wishlist" style="width: 32px; height: 32px; padding: 0; z-index: 2;">
                                        <i class="bi <?= in_array((int)$p['id'], $wishlistProductIds ?? [], true) ? 'bi-heart-fill text-danger' : 'bi-heart' ?>"></i>
                                    </button>
                                </div>
                                <div class="card-body d-flex flex-column p-3">
                                    <span class="text-muted small mb-1"><?= e($p['category_name']) ?></span>
                                    <h5 class="card-title fs-6 mb-2 flex-grow-1">
                                        <a href="<?= e(url('product/' . $p['slug'])) ?>" class="text-dark text-decoration-none"><?= e($p['name']) ?></a>
                                    </h5>
                                    <div class="tile-footer d-flex justify-content-between align-items-center mt-auto pt-2 border-top">
                                        <span class="tile-price fw-bold text-dark">
                                            <?= e(format_money($p['min_price'])) ?>
                                            <?php if (!empty($p['compare_at_price']) && $p['compare_at_price'] > $p['min_price']): ?>
                                                <small class="text-decoration-line-through text-muted ms-1"><?= e(format_money($p['compare_at_price'])) ?></small>
                                            <?php endif; ?>
                                        </span>
                                        <?php if ($p['is_sold_out']): ?>
                                            <button type="button" class="btn btn-sm btn-secondary disabled" disabled>Sold Out</button>
                                        <?php else: ?>
                                            <a href="<?= e(url('product/' . $p['slug'])) ?>" class="btn btn-sm btn-outline-dark">View</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </article>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <!-- New Arrivals Section (disappears when empty) -->
    <?php if (!empty($newArrivals)): ?>
        <section id="new-arrivals" class="new-arrivals section py-5">
            <div class="container">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2 class="mb-1">New Arrivals</h2>
                        <p class="text-muted mb-0">Freshly stocked everyday essentials</p>
                    </div>
                    <a href="<?= e(url('shop?sort=newest')) ?>" class="btn btn-outline-primary btn-sm">View All →</a>
                </div>

                <div class="row g-3 g-lg-4">
                    <?php foreach ($newArrivals as $p): ?>
                        <div class="col-6 col-md-4 col-lg-3">
                            <article class="product-tile card h-100 border-0 shadow-sm">
                                <div class="tile-visual position-relative overflow-hidden">
                                    <a href="<?= e(url('product/' . $p['slug'])) ?>">
                                        <img src="<?= e(product_image_url($p['primary_image'])) ?>" class="img-fluid w-100" alt="<?= e($p['name']) ?>" loading="lazy" style="height: 220px; object-fit: cover;">
                                    </a>
                                    <?php if (!empty($p['badges'])): ?>
                                        <span class="badge position-absolute top-0 start-0 m-2 <?= $p['badges'][0]['type'] === 'sale' ? 'bg-danger' : ($p['badges'][0]['type'] === 'sold_out' ? 'bg-secondary' : 'bg-warning text-dark') ?>">
                                            <?= e($p['badges'][0]['label']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-light wishlist-toggle-btn position-absolute top-0 end-0 m-2 rounded-circle shadow-sm <?= in_array((int)$p['id'], $wishlistProductIds ?? [], true) ? 'active text-danger' : 'text-secondary' ?>" data-product-id="<?= (int)$p['id'] ?>" title="Save to Wishlist" aria-label="Save to Wishlist" style="width: 32px; height: 32px; padding: 0; z-index: 2;">
                                        <i class="bi <?= in_array((int)$p['id'], $wishlistProductIds ?? [], true) ? 'bi-heart-fill text-danger' : 'bi-heart' ?>"></i>
                                    </button>
                                </div>
                                <div class="card-body d-flex flex-column p-3">
                                    <span class="text-muted small mb-1"><?= e($p['category_name']) ?></span>
                                    <h5 class="card-title fs-6 mb-2 flex-grow-1">
                                        <a href="<?= e(url('product/' . $p['slug'])) ?>" class="text-dark text-decoration-none"><?= e($p['name']) ?></a>
                                    </h5>
                                    <div class="tile-footer d-flex justify-content-between align-items-center mt-auto pt-2 border-top">
                                        <span class="tile-price fw-bold text-dark">
                                            <?= e(format_money($p['min_price'])) ?>
                                            <?php if (!empty($p['compare_at_price']) && $p['compare_at_price'] > $p['min_price']): ?>
                                                <small class="text-decoration-line-through text-muted ms-1"><?= e(format_money($p['compare_at_price'])) ?></small>
                                            <?php endif; ?>
                                        </span>
                                        <?php if ($p['is_sold_out']): ?>
                                            <button type="button" class="btn btn-sm btn-secondary disabled" disabled>Sold Out</button>
                                        <?php else: ?>
                                            <a href="<?= e(url('product/' . $p['slug'])) ?>" class="btn btn-sm btn-outline-dark">View</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </article>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <!-- Deals Section (disappears when empty) -->
    <?php if (!empty($deals)): ?>
        <section id="deals" class="deals section py-5 bg-light">
            <div class="container">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2 class="mb-1">Deals &amp; Discounts</h2>
                        <p class="text-muted mb-0">Special offers on select essentials</p>
                    </div>
                    <a href="<?= e(url('shop')) ?>" class="btn btn-outline-primary btn-sm">View All Deals →</a>
                </div>

                <div class="row g-3 g-lg-4">
                    <?php foreach ($deals as $p): ?>
                        <div class="col-6 col-md-4 col-lg-3">
                            <article class="product-tile card h-100 border-0 shadow-sm">
                                <div class="tile-visual position-relative overflow-hidden">
                                    <a href="<?= e(url('product/' . $p['slug'])) ?>">
                                        <img src="<?= e(product_image_url($p['primary_image'])) ?>" class="img-fluid w-100" alt="<?= e($p['name']) ?>" loading="lazy" style="height: 220px; object-fit: cover;">
                                    </a>
                                    <?php if (!empty($p['badges'])): ?>
                                        <span class="badge position-absolute top-0 start-0 m-2 <?= $p['badges'][0]['type'] === 'sale' ? 'bg-danger' : 'bg-warning text-dark' ?>">
                                            <?= e($p['badges'][0]['label']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="card-body d-flex flex-column p-3">
                                    <span class="text-muted small mb-1"><?= e($p['category_name']) ?></span>
                                    <h5 class="card-title fs-6 mb-2 flex-grow-1">
                                        <a href="<?= e(url('product/' . $p['slug'])) ?>" class="text-dark text-decoration-none"><?= e($p['name']) ?></a>
                                    </h5>
                                    <div class="tile-footer d-flex justify-content-between align-items-center mt-auto pt-2 border-top">
                                        <span class="tile-price fw-bold text-dark">
                                            <?= e(format_money($p['min_price'])) ?>
                                            <?php if (!empty($p['compare_at_price']) && $p['compare_at_price'] > $p['min_price']): ?>
                                                <small class="text-decoration-line-through text-muted ms-1"><?= e(format_money($p['compare_at_price'])) ?></small>
                                            <?php endif; ?>
                                        </span>
                                        <?php if ($p['is_sold_out']): ?>
                                            <button type="button" class="btn btn-sm btn-secondary disabled" disabled>Sold Out</button>
                                        <?php else: ?>
                                            <a href="<?= e(url('product/' . $p['slug'])) ?>" class="btn btn-sm btn-outline-dark">View</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </article>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <!-- Honest Trust Points -->
    <section class="trust-points section py-5">
        <div class="container">
            <div class="row g-4 text-center">
                <div class="col-md-3">
                    <div class="p-3">
                        <i class="bi bi-truck text-primary display-5 mb-2"></i>
                        <h5>Regional Delivery Zones</h5>
                        <p class="text-muted small mb-0">Same-day to 1 day in Nairobi; 1-4 business days across regional zones.</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3">
                        <i class="bi bi-phone text-primary display-5 mb-2"></i>
                        <h5>M-Pesa Payment</h5>
                        <p class="text-muted small mb-0">Instant checkout via Safaricom M-Pesa STK Push.</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3">
                        <i class="bi bi-patch-check text-primary display-5 mb-2"></i>
                        <h5>Quality Checked</h5>
                        <p class="text-muted small mb-0">Carefully inspected stock with clear descriptions.</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3">
                        <i class="bi bi-clock-history text-primary display-5 mb-2"></i>
                        <h5>Support &amp; Pickup</h5>
                        <p class="text-muted small mb-0">Convenient Nairobi CBD pickup point with dedicated support.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Testimonials Section (only from real approved reviews in DB) -->
    <?php if (!empty($testimonials)): ?>
        <section id="testimonials" class="testimonials section py-5 bg-light">
            <div class="container">
                <div class="section-title text-center mb-5">
                    <h2>Customer Feedback</h2>
                    <p class="text-muted">Real reviews from verified Kenyan shoppers</p>
                </div>

                <div class="row g-4 justify-content-center">
                    <?php foreach ($testimonials as $t): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="card h-100 border-0 shadow-sm p-4">
                                <div class="stars text-warning mb-2">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="bi <?= $i <= (int)$t['rating'] ? 'bi-star-fill' : 'bi-star' ?>"></i>
                                    <?php endfor; ?>
                                </div>
                                <h6 class="fw-bold mb-1"><?= e($t['title']) ?></h6>
                                <p class="text-muted small flex-grow-1">“<?= e($t['body']) ?>”</p>
                                <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                                    <strong class="small"><?= e($t['first_name'] . ' ' . substr($t['last_name'], 0, 1) . '.') ?></strong>
                                    <small class="text-muted"><a href="<?= e(url('product/' . $t['product_slug'])) ?>" class="text-decoration-none text-muted"><?= e($t['product_name']) ?></a></small>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

</main>

<?php include __DIR__ . '/partials/footer.php'; ?>
