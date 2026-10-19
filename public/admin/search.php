<?php
require dirname(__DIR__, 2) . '/app/bootstrap.php';
require dirname(__DIR__, 2) . '/app/views/admin/guard.php';

use App\Services\SearchService;
use App\Services\AdminAuthService;
use App\Services\Permissions;

$currentUser = AdminAuthService::user();
$query = trim($_GET['q'] ?? '');

$results = SearchService::search($query, $currentUser['role']);

$pageTitle = 'Search Results: ' . ($query !== '' ? $query : 'Global Search');
$activeGroup = 'dashboard';

require dirname(__DIR__, 2) . '/app/views/admin/layout.php';
?>

<div class="main-content page-search-results">
    <!-- Page Header -->
    <div class="page-header mb-4">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1">
                <li class="breadcrumb-item"><a href="<?= e(url('admin/')) ?>">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">Search Results</li>
            </ol>
        </nav>
        <h1 class="page-title h3 mb-1">
            Global Search
            <?php if ($query !== ''): ?>
                <span class="text-muted fw-normal">for &ldquo;<?= e($query) ?>&rdquo;</span>
            <?php endif; ?>
        </h1>
        <p class="text-muted small mb-0">Cross-entity lookup across orders, product catalog, and customers.</p>
    </div>

    <!-- Search Input Box -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="<?= e(url('admin/search.php')) ?>" class="row g-2 align-items-center">
                <div class="col-md-10">
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" name="q" class="form-control form-control-lg border-start-0" placeholder="Search orders (#ORD-...), products, SKUs, customer names, emails or phones..." value="<?= e($query) ?>" autofocus>
                    </div>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-lg w-100">
                        <i class="bi bi-search me-1"></i> Search
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($query === ''): ?>
        <div class="card border-0 shadow-sm text-center py-5">
            <div class="card-body">
                <i class="bi bi-search fs-1 text-muted mb-3 d-block"></i>
                <h5 class="text-dark fw-bold">Enter a search keyword above</h5>
                <p class="text-muted small">You can search for order numbers, customer details, or product names and SKUs.</p>
            </div>
        </div>
    <?php elseif (!empty($results['too_short'])): ?>
        <div class="alert alert-warning border-0 shadow-sm">
            <i class="bi bi-exclamation-triangle me-2"></i>
            Please enter at least <strong>2 characters</strong> to search the store database.
        </div>
    <?php elseif ($results['total_count'] === 0): ?>
        <div class="card border-0 shadow-sm text-center py-5">
            <div class="card-body">
                <i class="bi bi-emoji-neutral fs-1 text-muted mb-3 d-block"></i>
                <h5 class="text-dark fw-bold">No results found for &ldquo;<?= e($query) ?>&rdquo;</h5>
                <p class="text-muted small mb-0">Try checking the spelling or searching with fewer keywords.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="mb-3 text-muted small">
            Found <strong><?= $results['total_count'] ?></strong> matching item(s) across accessible sections:
        </div>

        <div class="row g-4">
            <!-- 1. Orders Results -->
            <?php if (Permissions::can('orders.view')): ?>
                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center py-3">
                            <h5 class="card-title h6 fw-bold mb-0 text-primary">
                                <i class="bi bi-box-seam me-2"></i>Orders (<?= count($results['orders']) ?>)
                            </h5>
                        </div>
                        <?php if (empty($results['orders'])): ?>
                            <div class="card-body text-muted small py-3">No matching orders found.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Order Number</th>
                                            <th>Customer / Recipient</th>
                                            <th>Phone</th>
                                            <th class="text-end">Total (KES)</th>
                                            <th class="text-center">Status</th>
                                            <th class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($results['orders'] as $ord): ?>
                                            <tr>
                                                <td>
                                                    <a href="<?= e(url('admin/orders/view.php?id=' . $ord['id'])) ?>" class="fw-bold text-decoration-none">
                                                        <?= e($ord['order_number']) ?>
                                                    </a>
                                                </td>
                                                <td><?= e($ord['recipient_name']) ?></td>
                                                <td><?= e($ord['phone']) ?></td>
                                                <td class="text-end fw-bold">KES <?= number_format($ord['total'], 2) ?></td>
                                                <td class="text-center">
                                                    <span class="badge bg-light text-dark border"><?= e(strtoupper($ord['status'])) ?></span>
                                                </td>
                                                <td class="text-end">
                                                    <a href="<?= e(url('admin/orders/view.php?id=' . $ord['id'])) ?>" class="btn btn-sm btn-outline-primary">
                                                        View Order
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 2. Products Results -->
            <?php if (Permissions::can('products.view')): ?>
                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center py-3">
                            <h5 class="card-title h6 fw-bold mb-0 text-success">
                                <i class="bi bi-tag me-2"></i>Products &amp; SKUs (<?= count($results['products']) ?>)
                            </h5>
                        </div>
                        <?php if (empty($results['products'])): ?>
                            <div class="card-body text-muted small py-3">No matching products or variants found.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Product Name</th>
                                            <th class="text-end">Base Price (KES)</th>
                                            <th class="text-center">Status</th>
                                            <th class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($results['products'] as $prod): ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-bold text-dark"><?= e($prod['name']) ?></div>
                                                    <div class="text-muted small">Slug: <?= e($prod['slug']) ?></div>
                                                </td>
                                                <td class="text-end fw-bold">KES <?= number_format($prod['base_price'], 2) ?></td>
                                                <td class="text-center">
                                                    <?php if ((int)$prod['is_active'] === 1): ?>
                                                        <span class="badge bg-success-subtle text-success">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary-subtle text-secondary">Draft</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <a href="<?= e(url('admin/products/edit.php?id=' . $prod['id'])) ?>" class="btn btn-sm btn-outline-success">
                                                        Edit Product
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 3. Customers Results -->
            <?php if (Permissions::can('customers.view')): ?>
                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center py-3">
                            <h5 class="card-title h6 fw-bold mb-0 text-info">
                                <i class="bi bi-people me-2"></i>Customers (<?= count($results['customers']) ?>)
                            </h5>
                        </div>
                        <?php if (empty($results['customers'])): ?>
                            <div class="card-body text-muted small py-3">No matching customer accounts found.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Customer Name</th>
                                            <th>Email Address</th>
                                            <th>Phone</th>
                                            <th class="text-center">Status</th>
                                            <th class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($results['customers'] as $cust): ?>
                                            <tr>
                                                <td class="fw-bold text-dark"><?= e($cust['first_name'] . ' ' . $cust['last_name']) ?></td>
                                                <td><?= e($cust['email']) ?></td>
                                                <td><?= e($cust['phone'] ?: '—') ?></td>
                                                <td class="text-center">
                                                    <span class="badge bg-light text-dark border"><?= e(strtoupper($cust['status'])) ?></span>
                                                </td>
                                                <td class="text-end">
                                                    <a href="<?= e(url('admin/customers/view.php?id=' . $cust['id'])) ?>" class="btn btn-sm btn-outline-info">
                                                        View Profile
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php require dirname(__DIR__, 2) . '/app/views/admin/footer.php'; ?>
