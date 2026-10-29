<?php
/**
 * Product Details View
 * Based on product-details.html
 */
$product = $product ?? [];
$pageTitle = $product['name'] ?? 'Product Details';
$metaDescription = $product['meta_description'] ?? ($product['short_description'] ?? '');
$bodyClass = 'product-details-page';

$breadcrumbs = [
    ['label' => $product['category_name'] ?? 'Catalog', 'url' => !empty($product['category_slug']) ? url('category/' . $product['category_slug']) : url('shop')],
    ['label' => $product['name'] ?? 'Product', 'url' => null],
];

include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/breadcrumbs.php';
include __DIR__ . '/partials/flash.php';

$images = $product['images'] ?? [];
$variants = $product['variants'] ?? [];
$firstVariant = $variants[0] ?? null;
$reviewSummary = $product['review_summary'] ?? ['count' => 0, 'average' => 0.0, 'distribution' => []];
?>

<main class="main py-4">

    <!-- Product Details Section -->
    <section id="product-details" class="product-details section" data-compare-at="<?= e((string)($product['compare_at_price'] ?? '')) ?>">
        <div class="container">
            <div class="row g-4">

                <!-- Product Gallery -->
                <div class="col-lg-6">
                    <div class="image-showcase p-3 border rounded bg-white">
                        <div class="main-image-container text-center mb-3 position-relative">
                            <?php if (!empty($product['compare_at_price']) && $product['compare_at_price'] > ($firstVariant['effective_price'] ?? $product['base_price'])): ?>
                                <?php
                                $eff = $firstVariant['effective_price'] ?? $product['base_price'];
                                $pct = round((($product['compare_at_price'] - $eff) / $product['compare_at_price']) * 100);
                                ?>
                                <span class="badge bg-danger position-absolute top-0 start-0 m-2 px-2 py-1 fs-6">-<?= $pct ?>%</span>
                            <?php endif; ?>
                            <img id="main-product-image" src="<?= e(product_image_url($product['primary_image'])) ?>" alt="<?= e($product['name']) ?>" class="img-fluid rounded" width="500" height="440" style="max-height: 440px; width: auto; object-fit: contain;">
                        </div>

                        <!-- Thumbnail Strip -->
                        <?php if (count($images) > 1): ?>
                            <div class="thumb-strip d-flex gap-2 overflow-auto pb-2">
                                <?php foreach ($images as $idx => $img): ?>
                                    <div class="thumb-cell thumbnail-item border rounded p-1 <?= $idx === 0 ? 'active border-primary' : '' ?>" data-image="<?= e(product_image_url($img['path'])) ?>" data-color="<?= e($img['color'] ?? '') ?>" role="button" style="width: 72px; height: 72px; flex-shrink: 0; cursor: pointer;">
                                        <img src="<?= e(product_image_url($img['path'])) ?>" alt="<?= e($img['alt_text'] ?? $product['name']) ?>" class="img-fluid rounded w-100 h-100" width="72" height="72" style="object-fit: cover;">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Product Info & Actions -->
                <div class="col-lg-6">
                    <div class="product-detail-card p-4 border rounded bg-white">

                        <div class="detail-header d-flex justify-content-between align-items-center mb-2">
                            <span class="badge bg-light text-dark border"><?= e($product['category_name']) ?></span>
                            <span class="stock-indicator <?= ($firstVariant['status'] ?? '') === 'out' ? 'stock-badge-out' : (($firstVariant['status'] ?? '') === 'low' ? 'stock-badge-low' : 'stock-badge-in') ?>">
                                <?php if (($firstVariant['status'] ?? '') === 'out'): ?>
                                    <i class="bi bi-x-circle-fill"></i> Sold Out
                                <?php elseif (($firstVariant['status'] ?? '') === 'low'): ?>
                                    <i class="bi bi-exclamation-circle-fill"></i> Only <?= (int)$firstVariant['stock_display'] ?> left in stock - order soon
                                <?php else: ?>
                                    <i class="bi bi-check-circle-fill"></i> In Stock
                                <?php endif; ?>
                            </span>
                        </div>

                        <h1 class="product-heading fs-3 fw-bold mb-2"><?= e($product['name']) ?></h1>

                        <!-- Ratings Summary -->
                        <div class="review-summary d-flex align-items-center gap-2 mb-3">
                            <div class="stars text-warning small">
                                <?php
                                $avg = (float)$reviewSummary['average'];
                                for ($i = 1; $i <= 5; $i++):
                                    if ($avg >= $i): ?>
                                        <i class="bi bi-star-fill"></i>
                                    <?php elseif ($avg >= $i - 0.5): ?>
                                        <i class="bi bi-star-half"></i>
                                    <?php else: ?>
                                        <i class="bi bi-star"></i>
                                    <?php endif;
                                endfor;
                                ?>
                            </div>
                            <span class="fw-bold small"><?= $avg > 0 ? number_format($avg, 1) : 'No ratings yet' ?></span>
                            <?php if ($reviewSummary['count'] > 0): ?>
                                <span class="text-muted small">·</span>
                                <a href="#product-tab-feedback" class="small text-muted text-decoration-none"><?= $reviewSummary['count'] ?> verified <?= $reviewSummary['count'] === 1 ? 'review' : 'reviews' ?></a>
                            <?php endif; ?>
                        </div>

                        <!-- Pricing Area -->
                        <div class="pricing-area mb-3 p-3 bg-light rounded">
                            <div class="d-flex align-items-baseline gap-2">
                                <span class="price-now fs-2 fw-bold text-dark">
                                    <?= e(format_money($firstVariant['effective_price'] ?? $product['base_price'])) ?>
                                </span>
                                <?php if (!empty($product['compare_at_price']) && $product['compare_at_price'] > ($firstVariant['effective_price'] ?? $product['base_price'])): ?>
                                    <span class="price-was text-muted text-decoration-line-through fs-5">
                                        <?= e(format_money($product['compare_at_price'])) ?>
                                    </span>
                                    <span class="badge bg-danger save-tag">
                                        Save <?= e(format_money($product['compare_at_price'] - ($firstVariant['effective_price'] ?? $product['base_price']))) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Short Description -->
                        <p class="summary-text text-muted mb-4"><?= e($product['short_description']) ?></p>

                        <hr>

                        <!-- Add to Cart Form (Server-Processed with Fallback) -->
                        <form id="addToCartForm" action="<?= e(url('cart/add')) ?>" method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="variant_id" id="selectedVariantId" value="<?= (int)($firstVariant['id'] ?? 0) ?>">

                            <!-- Color Selector -->
                            <?php if (!empty($product['colors'])): ?>
                                <div class="variant-picker mb-3">
                                    <label class="form-label fw-bold small mb-2">Color: <span class="chosen-variant fw-normal text-muted"><?= e(array_key_first($product['colors'])) ?></span></label>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php $cIdx = 0; foreach ($product['colors'] as $cName => $cHex): ?>
                                            <button type="button" class="color-dot-btn <?= $cIdx === 0 ? 'active' : '' ?>" data-color="<?= e($cName) ?>" style="background-color: <?= e($cHex) ?>;" title="<?= e($cName) ?>">
                                                <i class="bi bi-check" style="color: <?= in_array(strtolower($cHex), ['#ffffff', '#fff', 'white'], true) ? '#000' : '#fff' ?>;"></i>
                                            </button>
                                        <?php $cIdx++; endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Size Selector -->
                            <?php if (!empty($product['sizes'])): ?>
                                <div class="variant-picker mb-4">
                                    <label class="form-label fw-bold small mb-2">Size</label>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php $sIdx = 0; foreach ($product['sizes'] as $sz): ?>
                                            <button type="button" class="size-btn <?= $sIdx === 0 ? 'active' : '' ?>" data-size="<?= e($sz) ?>">
                                                <?= e($sz) ?>
                                            </button>
                                        <?php $sIdx++; endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Quantity and Add to Bag -->
                            <div class="action-row d-flex gap-3 align-items-center mb-3">
                                <div class="quantity-selector d-flex align-items-center border rounded">
                                    <button class="btn btn-sm btn-link text-dark px-2 text-decoration-none quantity-btn decrease" type="button"><i class="bi bi-dash"></i></button>
                                    <input type="number" name="quantity" class="form-control form-control-sm border-0 text-center quantity-input" style="width: 52px;" value="1" min="1" max="<?= (int)($firstVariant['max_purchasable'] ?? 10) ?>" readonly>
                                    <button class="btn btn-sm btn-link text-dark px-2 text-decoration-none quantity-btn increase" type="button"><i class="bi bi-plus"></i></button>
                                </div>

                                <button id="addToCartBtn" type="submit" class="btn btn-primary flex-grow-1 py-2" <?= empty($firstVariant['max_purchasable']) ? 'disabled' : '' ?>>
                                    <i class="bi bi-bag-plus me-1"></i>
                                    <?= empty($firstVariant['max_purchasable']) ? 'Sold Out' : 'Add to Bag' ?>
                                </button>

                                <button type="button" class="btn btn-outline-secondary wishlist-toggle-btn px-3 py-2 <?= !empty($isWishlisted) ? 'active text-danger border-danger' : '' ?>" data-product-id="<?= (int)$product['id'] ?>" title="Save to Wishlist" aria-label="Save to Wishlist">
                                    <i class="bi <?= !empty($isWishlisted) ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
                                </button>
                            </div>

                            <!-- Disabled Instant Checkout Button per Brief -->
                            <button class="btn btn-outline-secondary w-100 py-2 checkout-now-btn" type="button" disabled aria-disabled="true">
                                <i class="bi bi-lightning-charge me-1"></i> Checkout opens soon
                            </button>
                        </form>

                        <!-- Delivery and Payment Information -->
                        <div class="guarantee-bar mt-4 pt-3 border-top row g-2 text-center text-muted small">
                            <div class="col-4">
                                <i class="bi bi-truck fs-5 d-block text-primary mb-1"></i>
                                <span>Regional Delivery</span>
                            </div>
                            <div class="col-4">
                                <i class="bi bi-shield-check fs-5 d-block text-primary mb-1"></i>
                                <span>Quality Checked</span>
                            </div>
                            <div class="col-4">
                                <i class="bi bi-phone fs-5 d-block text-primary mb-1"></i>
                                <span>M-Pesa STK</span>
                            </div>
                        </div>

                    </div>
                </div>

            </div>

            <!-- Tabbed Product Information -->
            <div class="row mt-5">
                <div class="col-12">
                    <div class="info-tabs border rounded bg-white p-4">
                        <ul class="nav nav-tabs mb-4" id="productTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="desc-tab" data-bs-toggle="tab" data-bs-target="#tab-desc" type="button" role="tab">Description</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="feedback-tab" data-bs-toggle="tab" data-bs-target="#tab-feedback" type="button" role="tab">
                                    Customer Reviews (<?= $reviewSummary['count'] ?>)
                                </button>
                            </li>
                        </ul>

                        <div class="tab-content" id="productTabsContent">
                            <!-- Description Tab -->
                            <div class="tab-pane fade show active" id="tab-desc" role="tabpanel">
                                <div class="product-description lh-lg">
                                    <?= nl2br(e($product['description'])) ?>
                                </div>
                            </div>

                            <!-- Approved Reviews Tab -->
                            <div class="tab-pane fade" id="tab-feedback" role="tabpanel">
                                <?php if (empty($product['reviews'])): ?>
                                    <div class="text-center py-4 text-muted">
                                        <i class="bi bi-chat-square-text fs-2 d-block mb-2"></i>
                                        <p>No customer reviews have been submitted for this product yet.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="reviews-list">
                                        <?php foreach ($product['reviews'] as $rev): ?>
                                            <div class="review-item border-bottom pb-3 mb-3">
                                                <div class="d-flex justify-content-between align-items-center mb-1">
                                                    <div class="stars text-warning small">
                                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                                            <i class="bi <?= $i <= (int)$rev['rating'] ? 'bi-star-fill' : 'bi-star' ?>"></i>
                                                        <?php endfor; ?>
                                                    </div>
                                                    <small class="text-muted"><?= e(date('M j, Y', strtotime($rev['created_at']))) ?></small>
                                                </div>
                                                <h6 class="fw-bold mb-1"><?= e($rev['title']) ?></h6>
                                                <p class="text-muted small mb-1"><?= e($rev['body']) ?></p>
                                                <small class="text-dark fw-semibold">
                                                    <i class="bi bi-person-check text-success me-1"></i>
                                                    <?= e($rev['first_name'] . ' ' . substr($rev['last_name'], 0, 1) . '.') ?> (Verified Buyer)
                                                </small>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Verified Buyer Review Section -->
                                <div class="review-submission-area border-top pt-4 mt-4">
                                    <?php if (!empty($canReview['can_review'])): ?>
                                        <h5 class="fw-bold mb-3"><i class="bi bi-pencil-square me-2 text-primary"></i>Write a Customer Review</h5>
                                        <form method="POST" action="<?= e(url('product/' . $product['slug'] . '/reviews')) ?>" class="border rounded p-3 bg-light">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <div class="row g-3">
                                                <div class="col-md-4">
                                                    <label class="form-label small fw-bold">Your Rating <span class="text-danger">*</span></label>
                                                    <select name="rating" class="form-select form-select-sm" required>
                                                        <option value="5">5 Stars — Excellent</option>
                                                        <option value="4">4 Stars — Very Good</option>
                                                        <option value="3">3 Stars — Average</option>
                                                        <option value="2">2 Stars — Below Average</option>
                                                        <option value="1">1 Star — Poor</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-8">
                                                    <label class="form-label small fw-bold">Review Title <span class="text-danger">*</span></label>
                                                    <input type="text" name="title" class="form-control form-control-sm" placeholder="Summarize your review" required maxlength="100">
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label small fw-bold">Review Details <span class="text-danger">*</span></label>
                                                    <textarea name="body" class="form-control form-control-sm" rows="3" placeholder="Tell other shoppers about the quality, fit, and performance..." required maxlength="2000"></textarea>
                                                </div>
                                                <div class="col-12">
                                                    <button type="submit" class="btn btn-primary btn-sm px-4 fw-bold">Submit Review</button>
                                                    <span class="small text-muted ms-2">Your review will be published upon moderation.</span>
                                                </div>
                                            </div>
                                        </form>
                                    <?php elseif (!empty($myReview) && $myReview['status'] === 'pending'): ?>
                                        <div class="alert alert-info py-2 small">
                                            <i class="bi bi-hourglass-split me-1"></i>
                                            <strong>Your review is pending moderation:</strong>
                                            "<?= e($myReview['title']) ?>" (<?= (int)$myReview['rating'] ?>/5 stars) — <?= e($myReview['body']) ?>
                                        </div>
                                    <?php elseif (!empty($myReview) && $myReview['status'] === 'approved'): ?>
                                        <div class="alert alert-success py-2 small">
                                            <i class="bi bi-check-circle-fill me-1"></i> You have already reviewed this product. Thank you for your feedback!
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-light border py-3 small text-muted">
                                            <i class="bi bi-info-circle me-1 text-primary"></i>
                                            <?= !empty($canReview['reason']) ? e($canReview['reason']) : 'Only verified customers who bought this product can leave a review.' ?>
                                            <?php if (empty($currentCustomer)): ?>
                                                Please <a href="<?= e(url('login?next=' . urlencode('/product/' . $product['slug']))) ?>" class="text-primary text-decoration-none fw-bold">sign in</a> to check your review eligibility.
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Related Products Section -->
            <?php if (!empty($relatedProducts)): ?>
                <div class="related-products mt-5">
                    <h3 class="mb-4">Related Products</h3>
                    <div class="row g-3 g-lg-4">
                        <?php foreach ($relatedProducts as $p): ?>
                            <div class="col-6 col-md-3">
                                <article class="product-tile card h-100 border-0 shadow-sm">
                                    <div class="tile-visual position-relative overflow-hidden">
                                        <a href="<?= e(url('product/' . $p['slug'])) ?>">
                                            <img src="<?= e(product_image_url($p['primary_image'])) ?>" class="img-fluid w-100" alt="<?= e($p['name']) ?>" loading="lazy" style="height: 200px; object-fit: cover;">
                                        </a>
                                        <?php if (!empty($p['badges'])): ?>
                                            <span class="badge position-absolute top-0 start-0 m-2 <?= $p['badges'][0]['type'] === 'sale' ? 'bg-danger' : ($p['badges'][0]['type'] === 'sold_out' ? 'bg-secondary' : 'bg-warning text-dark') ?>">
                                                <?= e($p['badges'][0]['label']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="card-body d-flex flex-column p-3">
                                        <h5 class="card-title fs-6 mb-2 flex-grow-1">
                                            <a href="<?= e(url('product/' . $p['slug'])) ?>" class="text-dark text-decoration-none"><?= e($p['name']) ?></a>
                                        </h5>
                                        <div class="tile-footer d-flex justify-content-between align-items-center mt-auto pt-2 border-top">
                                            <span class="tile-price fw-bold text-dark">
                                                <?= e(format_money($p['min_price'])) ?>
                                            </span>
                                            <a href="<?= e(url('product/' . $p['slug'])) ?>" class="btn btn-sm btn-outline-dark">View</a>
                                        </div>
                                    </div>
                                </article>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </section>

</main>

<!-- Embedded Variants Data for Dynamic Selection Engine -->
<script type="application/json" id="productVariantsJson">
<?= json_encode($product['variants'] ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
