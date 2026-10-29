<?php
/**
 * Storefront 404 Error View
 * Based on 404.html
 */
http_response_code(404);

$pageTitle = 'Page Not Found (404)';
$metaDescription = 'The page or product you requested could not be found on ShopWise Kenya.';
$bodyClass = 'error-404-page';

$breadcrumbs = [
    ['label' => '404 Not Found', 'url' => null],
];

include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/breadcrumbs.php';
?>

<main class="main py-5">

    <section id="error-404" class="error-404 section">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-6 col-md-8 text-center">

                    <div class="error-card p-4 p-md-5 border rounded bg-white shadow-sm">
                        <div class="error-indicator mb-3">
                            <span class="status-code display-1 fw-bold text-primary">404</span>
                        </div>

                        <div class="error-body">
                            <span class="badge bg-danger-subtle text-danger px-3 py-2 rounded-pill mb-3">
                                <i class="bi bi-exclamation-triangle me-1"></i> Page or Item Not Found
                            </span>

                            <h2 class="error-heading fw-bold mb-3">This Item is Unavailable</h2>

                            <p class="error-text text-muted mb-4">
                                The page, category, or product you requested could not be located. It may have been removed, renamed, or is currently inactive.
                            </p>

                            <!-- Search Field -->
                            <form action="<?= e(url('search')) ?>" method="GET" class="mb-4">
                                <div class="input-group">
                                    <input type="text" name="q" class="form-control" placeholder="Search catalog..." minlength="2" required>
                                    <button class="btn btn-primary" type="submit">
                                        <i class="bi bi-search me-1"></i> Search
                                    </button>
                                </div>
                            </form>

                            <div class="btn-group-actions d-flex justify-content-center gap-3">
                                <a href="<?= e(url('/')) ?>" class="btn btn-outline-dark">
                                    <i class="bi bi-house me-1"></i> Homepage
                                </a>
                                <a href="<?= e(url('shop')) ?>" class="btn btn-primary">
                                    <i class="bi bi-bag me-1"></i> Browse Catalog
                                </a>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </section>

</main>

<?php include __DIR__ . '/partials/footer.php'; ?>
