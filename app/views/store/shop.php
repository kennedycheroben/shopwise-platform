<?php
/**
 * Shop, Category & Search Browse View
 * Based on category.html
 */
$pageTitle = $pageTitle ?? 'Shop';
$metaDescription = $metaDescription ?? 'Browse our catalog of quality essentials at ShopWise Kenya.';
$bodyClass = 'category-page';

include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/breadcrumbs.php';
include __DIR__ . '/partials/flash.php';

$filters = $filters ?? [];
$currentSort = $currentSort ?? 'featured';
$currentCategory = $currentCategory ?? null;
$totalItems = $totalItems ?? 0;
$pagination = $pagination ?? [
    'current_page' => 1,
    'per_page' => 12,
    'total_pages' => 1,
    'offset' => 0,
    'has_prev' => false,
    'has_next' => false,
    'prev_page' => 1,
    'next_page' => 1,
];

// Helper to construct filter URLs while preserving other active filters
$buildFilterUrl = function (array $overrides = [], array $removals = []) use ($filters, $currentSort, $currentCategory): string {
    $params = [
        'sort' => $currentSort,
    ];
    if (!empty($filters['search'])) {
        $params['q'] = $filters['search'];
    }
    if (isset($filters['min_price'])) {
        $params['min_price'] = $filters['min_price'];
    }
    if (isset($filters['max_price'])) {
        $params['max_price'] = $filters['max_price'];
    }
    if (!empty($filters['size'])) {
        $params['size'] = $filters['size'];
    }
    if (!empty($filters['color'])) {
        $params['color'] = $filters['color'];
    }
    if (!empty($filters['in_stock'])) {
        $params['in_stock'] = '1';
    }

    foreach ($overrides as $k => $v) {
        $params[$k] = $v;
    }
    foreach ($removals as $k) {
        unset($params[$k]);
    }

    $baseUrl = !empty($currentCategory) ? url('category/' . $currentCategory['slug']) : url('shop');
    $query = http_build_query($params);
    return $query !== '' ? "{$baseUrl}?{$query}" : $baseUrl;
};

// Active filter chips detection
$activeChips = [];
if (!empty($currentCategory)) {
    $activeChips[] = [
        'label' => 'Category: ' . $currentCategory['name'],
        'url'   => url('shop' . (!empty($_GET['sort']) ? '?sort=' . urlencode($_GET['sort']) : '')),
    ];
}
if (!empty($filters['search'])) {
    $activeChips[] = [
        'label' => 'Search: "' . $filters['search'] . '"',
        'url'   => $buildFilterUrl([], ['q']),
    ];
}
if (isset($filters['min_price']) || isset($filters['max_price'])) {
    $minTxt = isset($filters['min_price']) ? format_money($filters['min_price']) : format_money($facets['min_price'] ?? 0);
    $maxTxt = isset($filters['max_price']) ? format_money($filters['max_price']) : format_money($facets['max_price'] ?? 10000);
    $activeChips[] = [
        'label' => "Price: {$minTxt} - {$maxTxt}",
        'url'   => $buildFilterUrl([], ['min_price', 'max_price']),
    ];
}
if (!empty($filters['size'])) {
    $activeChips[] = [
        'label' => 'Size: ' . $filters['size'],
        'url'   => $buildFilterUrl([], ['size']),
    ];
}
if (!empty($filters['color'])) {
    $activeChips[] = [
        'label' => 'Color: ' . $filters['color'],
        'url'   => $buildFilterUrl([], ['color']),
    ];
}
if (!empty($filters['in_stock'])) {
    $activeChips[] = [
        'label' => 'In-Stock Only',
        'url'   => $buildFilterUrl([], ['in_stock']),
    ];
}
?>

<main class="main py-4">

    <div class="container">
        <div class="row">

            <!-- Sidebar Filters -->
            <div class="col-lg-3 sidebar mb-4">
                <div class="widgets-container p-3 border rounded bg-white">

                    <!-- Product Categories Widget -->
                    <div class="product-categories-widget widget-item mb-4">
                        <h4 class="widget-title pb-2 border-bottom fs-5">Categories</h4>
                        <ul class="category-tree list-unstyled mb-0 pt-2">
                            <li class="category-item mb-2">
                                <a href="<?= e(url('shop')) ?>" class="text-decoration-none <?= empty($currentCategory) ? 'fw-bold text-primary' : 'text-dark' ?>">
                                    All Categories
                                </a>
                            </li>
                            <?php foreach ($categoriesTree as $cat): ?>
                                <li class="category-item mb-2">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <a href="<?= e(url('category/' . $cat['slug'])) ?>" class="text-decoration-none <?= (!empty($currentCategory) && $currentCategory['id'] == $cat['id']) ? 'fw-bold text-primary' : 'text-dark' ?>">
                                            <?= e($cat['name']) ?>
                                        </a>
                                        <?php if (!empty($cat['children'])): ?>
                                            <span class="category-toggle" data-bs-toggle="collapse" data-bs-target="#cat-sub-<?= (int)$cat['id'] ?>" role="button" aria-expanded="false">
                                                <i class="bi bi-chevron-down small"></i>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($cat['children'])): ?>
                                        <ul id="cat-sub-<?= (int)$cat['id'] ?>" class="subcategory-list list-unstyled collapse ps-3 mt-1 <?= (!empty($currentCategory) && ($currentCategory['id'] == $cat['id'] || $currentCategory['parent_id'] == $cat['id'])) ? 'show' : '' ?>">
                                            <?php foreach ($cat['children'] as $child): ?>
                                                <li class="mb-1">
                                                    <a href="<?= e(url('category/' . $child['slug'])) ?>" class="text-decoration-none small <?= (!empty($currentCategory) && $currentCategory['id'] == $child['id']) ? 'fw-bold text-primary' : 'text-muted' ?>">
                                                        <?= e($child['name']) ?>
                                                    </a>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <!-- Price Filter Form -->
                    <div class="pricing-range-widget widget-item mb-4 pt-3 border-top">
                        <h4 class="widget-title pb-2 fs-5">Price Filter</h4>
                        <form action="<?= e(!empty($currentCategory) ? url('category/' . $currentCategory['slug']) : url('shop')) ?>" method="GET">
                            <?php if (!empty($filters['search'])): ?>
                                <input type="hidden" name="q" value="<?= e($filters['search']) ?>">
                            <?php endif; ?>
                            <?php if (!empty($filters['size'])): ?>
                                <input type="hidden" name="size" value="<?= e($filters['size']) ?>">
                            <?php endif; ?>
                            <?php if (!empty($filters['color'])): ?>
                                <input type="hidden" name="color" value="<?= e($filters['color']) ?>">
                            <?php endif; ?>
                            <?php if (!empty($filters['in_stock'])): ?>
                                <input type="hidden" name="in_stock" value="1">
                            <?php endif; ?>
                            <input type="hidden" name="sort" value="<?= e($currentSort) ?>">

                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <label class="small text-muted mb-1">Min (KES)</label>
                                    <input type="number" name="min_price" class="form-control form-control-sm" value="<?= e($filters['min_price'] ?? '') ?>" placeholder="<?= (int)($facets['min_price'] ?? 0) ?>" min="0">
                                </div>
                                <div class="col-6">
                                    <label class="small text-muted mb-1">Max (KES)</label>
                                    <input type="number" name="max_price" class="form-control form-control-sm" value="<?= e($filters['max_price'] ?? '') ?>" placeholder="<?= (int)($facets['max_price'] ?? 10000) ?>" min="0">
                                </div>
                            </div>
                            <button type="submit" class="btn btn-sm btn-outline-dark w-100">Apply Price</button>
                        </form>
                    </div>

                    <!-- Size Filter -->
                    <?php if (!empty($facets['sizes'])): ?>
                        <div class="filter-widget widget-item mb-4 pt-3 border-top">
                            <h4 class="widget-title pb-2 fs-5">Filter by Size</h4>
                            <div class="d-flex flex-wrap gap-1">
                                <?php foreach ($facets['sizes'] as $sz): ?>
                                    <?php $isSzActive = (!empty($filters['size']) && strtolower($filters['size']) === strtolower($sz)); ?>
                                    <a href="<?= e($isSzActive ? $buildFilterUrl([], ['size']) : $buildFilterUrl(['size' => $sz])) ?>" class="btn btn-sm <?= $isSzActive ? 'btn-dark' : 'btn-outline-secondary' ?>">
                                        <?= e($sz) ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Color Filter -->
                    <?php if (!empty($facets['colors'])): ?>
                        <div class="filter-widget widget-item mb-4 pt-3 border-top">
                            <h4 class="widget-title pb-2 fs-5">Filter by Color</h4>
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($facets['colors'] as $clName => $clHex): ?>
                                    <?php $isClActive = (!empty($filters['color']) && strtolower($filters['color']) === strtolower($clName)); ?>
                                    <a href="<?= e($isClActive ? $buildFilterUrl([], ['color']) : $buildFilterUrl(['color' => $clName])) ?>" class="color-dot-btn text-decoration-none <?= $isClActive ? 'active' : '' ?>" style="background-color: <?= e($clHex) ?>;" title="<?= e($clName) ?>">
                                        <?php if ($isClActive): ?>
                                            <i class="bi bi-check" style="color: <?= in_array(strtolower($clHex), ['#ffffff', '#fff', 'white'], true) ? '#000' : '#fff' ?>;"></i>
                                        <?php endif; ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- In-Stock Only Filter -->
                    <div class="filter-widget widget-item pt-3 border-top">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="inStockCheck" <?= !empty($filters['in_stock']) ? 'checked' : '' ?> onchange="window.location.href='<?= e(!empty($filters['in_stock']) ? $buildFilterUrl([], ['in_stock']) : $buildFilterUrl(['in_stock' => '1'])) ?>'">
                            <label class="form-check-label small" for="inStockCheck">
                                In-Stock Only
                            </label>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Main Product Listing Content -->
            <div class="col-lg-9">

                <!-- Header Controls Card -->
                <div class="controls-card p-3 border rounded bg-white mb-4">
                    <div class="row align-items-center g-3">
                        <div class="col-md-6">
                            <span class="text-muted small">
                                Showing <?= $totalItems > 0 ? ($pagination['offset'] + 1) : 0 ?>–<?= min($pagination['offset'] + $pagination['per_page'], $totalItems) ?> of <?= $totalItems ?> items
                            </span>
                        </div>
                        <div class="col-md-6 text-md-end">
                            <div class="d-inline-flex align-items-center">
                                <label for="shopSortSelect" class="small text-muted me-2 mb-0">Sort By:</label>
                                <select id="shopSortSelect" class="form-select form-select-sm" style="width: auto;" onchange="window.location.href=this.value">
                                    <option value="<?= e($buildFilterUrl(['sort' => 'newest'])) ?>" <?= $currentSort === 'newest' ? 'selected' : '' ?>>Newest Arrivals</option>
                                    <option value="<?= e($buildFilterUrl(['sort' => 'featured'])) ?>" <?= $currentSort === 'featured' ? 'selected' : '' ?>>Featured</option>
                                    <option value="<?= e($buildFilterUrl(['sort' => 'price_asc'])) ?>" <?= $currentSort === 'price_asc' ? 'selected' : '' ?>>Price: Low to High</option>
                                    <option value="<?= e($buildFilterUrl(['sort' => 'price_desc'])) ?>" <?= $currentSort === 'price_desc' ? 'selected' : '' ?>>Price: High to Low</option>
                                    <option value="<?= e($buildFilterUrl(['sort' => 'name'])) ?>" <?= $currentSort === 'name' ? 'selected' : '' ?>>Name: A to Z</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Active Filter Chips -->
                    <?php if (!empty($activeChips)): ?>
                        <div class="active-tags d-flex flex-wrap align-items-center gap-2 mt-3 pt-3 border-top">
                            <span class="small text-muted me-1">Active filters:</span>
                            <?php foreach ($activeChips as $chip): ?>
                                <span class="badge bg-light text-dark border d-inline-flex align-items-center px-2 py-1">
                                    <?= e($chip['label']) ?>
                                    <a href="<?= e($chip['url']) ?>" class="text-dark ms-2 text-decoration-none" aria-label="Remove filter"><i class="bi bi-x"></i></a>
                                </span>
                            <?php endforeach; ?>
                            <a href="<?= e(url('shop')) ?>" class="small text-danger ms-2 text-decoration-none">Reset All</a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Search Notice Alert if short query -->
                <?php if (!empty($searchNotice)): ?>
                    <div class="alert alert-info py-3 mb-4 d-flex align-items-center">
                        <i class="bi bi-info-circle me-2 fs-5"></i>
                        <span><?= e($searchNotice) ?></span>
                    </div>
                <?php endif; ?>

                <!-- Products Grid -->
                <?php if (empty($products)): ?>
                    <div class="text-center py-5 border rounded bg-white">
                        <i class="bi bi-search text-muted display-4 mb-3 d-block"></i>
                        <h4 class="mb-2"><?= !empty($searchNotice) ? e($searchNotice) : 'No products found' ?></h4>
                        <?php if (!empty($filters['search']) && empty($searchNotice)): ?>
                            <p class="text-muted mb-4">No products found matching "<?= e($filters['search']) ?>".</p>
                        <?php else: ?>
                            <p class="text-muted mb-4"><?= !empty($searchNotice) ? 'Please enter at least 2 characters to search our catalog.' : 'We could not find any products matching your selected criteria.' ?></p>
                        <?php endif; ?>
                        <a href="<?= e(url('shop')) ?>" class="btn btn-primary px-4">View All Products</a>
                    </div>
                <?php else: ?>
                    <div class="row g-3 g-lg-4 mb-4">
                        <?php foreach ($products as $p): ?>
                            <div class="col-6 col-md-4">
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
                                                <?php if ($p['is_price_range']): ?>
                                                    <?= e(format_money($p['min_price'])) ?> – <?= e(format_money($p['max_price'])) ?>
                                                <?php else: ?>
                                                    <?= e(format_money($p['min_price'])) ?>
                                                <?php endif; ?>
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

                    <!-- Pagination -->
                    <?php if ($pagination['total_pages'] > 1): ?>
                        <nav aria-label="Page navigation" class="mt-4">
                            <ul class="pagination justify-content-center">
                                <li class="page-item <?= !$pagination['has_prev'] ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= e($buildFilterUrl(['page' => $pagination['prev_page']])) ?>" aria-label="Previous">
                                        &laquo; Prev
                                    </a>
                                </li>
                                <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
                                    <li class="page-item <?= $i === $pagination['current_page'] ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= e($buildFilterUrl(['page' => $i])) ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?= !$pagination['has_next'] ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= e($buildFilterUrl(['page' => $pagination['next_page']])) ?>" aria-label="Next">
                                        Next &raquo;
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>

                <?php endif; ?>

            </div>

        </div>
    </div>

</main>

<?php include __DIR__ . '/partials/footer.php'; ?>
