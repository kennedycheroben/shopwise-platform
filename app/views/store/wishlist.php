<?php
$pageTitle = 'My Wishlist - ' . setting('store_name', 'ShopWise Kenya');
$metaDescription = 'View and manage your saved favorite products on ' . setting('store_name', 'ShopWise Kenya') . '.';
$breadcrumbs = [
    ['label' => 'Home', 'url' => url('/')],
    ['label' => 'Account', 'url' => url('account')],
    ['label' => 'Wishlist']
];

include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/breadcrumbs.php';
include __DIR__ . '/partials/flash.php';

$items = $items ?? [];
$error = $error ?? null;
$success = $success ?? null;
?>

<main class="main">

    <!-- Page Title -->
    <div class="page-title light-background">
        <div class="container d-lg-flex justify-content-between align-items-center">
            <h1 class="mb-2 mb-lg-0">My Wishlist</h1>
            <nav class="breadcrumbs">
                <ol>
                    <li><a href="<?= e(url('/')) ?>">Home</a></li>
                    <li><a href="<?= e(url('account')) ?>">Account</a></li>
                    <li class="current">Wishlist</li>
                </ol>
            </nav>
        </div>
    </div><!-- End Page Title -->

    <section class="section py-5">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center mb-4 pb-2 border-bottom">
                <h3 class="fs-4 fw-bold mb-0">Saved Favorites</h3>
                <span class="badge bg-light text-dark fs-6"><?= count($items) ?> / 200 saved</span>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger py-2 small mb-4" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= e($error) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success py-2 small mb-4" role="alert">
                    <i class="bi bi-check-circle-fill me-1"></i> <?= e($success) ?>
                </div>
            <?php endif; ?>

            <?php if (empty($items)): ?>
                <div class="text-center py-5 border rounded bg-white shadow-sm">
                    <i class="bi bi-heart text-muted" style="font-size: 3.5rem;"></i>
                    <h4 class="fw-bold mt-3">Your Wishlist is Empty</h4>
                    <p class="text-muted small">Explore our collection and click the heart icon on any product to save it for later.</p>
                    <a href="<?= e(url('shop')) ?>" class="btn btn-primary px-4 py-2 mt-2">Explore Products</a>
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($items as $item): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="card h-100 border rounded shadow-sm overflow-hidden wishlist-card">
                                <div class="position-relative">
                                    <a href="<?= e(url('product/' . $item['slug'])) ?>">
                                        <img src="<?= e(product_image_url($item['primary_image'])) ?>" alt="<?= e($item['name']) ?>" class="card-img-top" style="height: 220px; object-fit: cover;" loading="lazy">
                                    </a>
                                    <form method="POST" action="<?= e(url('wishlist/remove')) ?>" class="position-absolute top-0 end-0 m-2">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="product_id" value="<?= (int)$item['product_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-light rounded-circle shadow-sm" title="Remove from wishlist" style="width: 32px; height: 32px; padding: 0;">
                                            <i class="bi bi-x-lg text-danger"></i>
                                        </button>
                                    </form>
                                </div>
                                <div class="card-body d-flex flex-column p-3">
                                    <span class="text-muted small mb-1"><?= e($item['category_name']) ?></span>
                                    <h5 class="card-title fs-6 fw-bold mb-2 flex-grow-1">
                                        <a href="<?= e(url('product/' . $item['slug'])) ?>" class="text-dark text-decoration-none"><?= e($item['name']) ?></a>
                                    </h5>

                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <span class="fw-bold text-dark fs-6"><?= e(format_money($item['base_price'])) ?></span>
                                        <?php if ((int)$item['in_stock'] > 0): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle small">In Stock</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle small">Sold Out</span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="mt-auto">
                                        <?php if ((int)$item['variant_count'] > 1): ?>
                                            <a href="<?= e(url('product/' . $item['slug'])) ?>" class="btn btn-sm btn-outline-primary w-100">
                                                Select Options
                                            </a>
                                        <?php elseif ((int)$item['in_stock'] > 0 && !empty($item['first_variant_id'])): ?>
                                            <form method="POST" action="<?= e(url('wishlist/move-to-cart')) ?>">
                                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="product_id" value="<?= (int)$item['product_id'] ?>">
                                                <input type="hidden" name="variant_id" value="<?= (int)$item['first_variant_id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-primary w-100">
                                                    <i class="bi bi-bag-plus me-1"></i> Move to Cart
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-sm btn-secondary w-100" disabled>Sold Out</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

</main>

<?php include __DIR__ . '/partials/footer.php'; ?>
