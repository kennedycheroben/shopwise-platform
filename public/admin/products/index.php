<?php
require dirname(__DIR__, 3) . '/app/bootstrap.php';
require dirname(__DIR__, 3) . '/app/views/admin/guard.php';

use App\Services\ProductService;
use App\Services\CategoryService;
use App\Services\Permissions;
use App\Services\AdminAuthService;

Permissions::require('products.view');
$currentUser = AdminAuthService::user();

// Handle POST actions (status toggle, soft delete, restore, hard delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['_csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Forbidden: Invalid CSRF token.');
    }

    $action = $_POST['action'] ?? '';
    $productId = (int)($_POST['id'] ?? 0);
    $actorId = (int)$currentUser['id'];
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    try {
        if ($action === 'toggle') {
            Permissions::require('products.edit');
            $prod = ProductService::find($productId);
            if ($prod) {
                ProductService::update($productId, ['is_active' => $prod['is_active'] ? 0 : 1], $actorId, $ip);
                flash_set('success', 'Product visibility status updated.');
            }
        } elseif ($action === 'soft_delete') {
            Permissions::require('products.delete');
            ProductService::softDelete($productId, $actorId, $ip);
            flash_set('success', 'Product moved to trash.');
        } elseif ($action === 'restore') {
            Permissions::require('products.delete');
            ProductService::restore($productId, $actorId, $ip);
            flash_set('success', 'Product restored from trash.');
        } elseif ($action === 'hard_delete') {
            Permissions::require('products.delete');
            ProductService::hardDelete($productId, $actorId, $ip);
            flash_set('success', 'Product permanently deleted.');
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }

    // Preserve search/filter query params on redirect
    $query = $_POST['redirect_query'] ?? '';
    redirect('admin/products/' . ($query !== '' ? '?' . ltrim($query, '?') : ''));
}

// Filters & Pagination
$filters = [
    'search'       => trim($_GET['search'] ?? ''),
    'category_id'  => !empty($_GET['category_id']) ? (int)$_GET['category_id'] : null,
    'status'       => trim($_GET['status'] ?? 'all'),
    'stock_status' => trim($_GET['stock_status'] ?? ''),
    'sort'         => trim($_GET['sort'] ?? 'created'),
    'order'        => trim($_GET['order'] ?? 'desc'),
];

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$result = ProductService::paginate($filters, $page, $perPage);
$products = $result['items'];
$totalItems = $result['total_items'];
$totalPages = $result['total_pages'];

$categories = CategoryService::getAll();
$pageTitle = 'Products';

$currentQuery = http_build_query(array_filter($_GET));

require dirname(__DIR__, 3) . '/app/views/admin/layout.php';
?>

<div class="main-content page-products-list">
    <!-- Page Header -->
    <div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="page-title">Products Catalog</h1>
            <nav class="breadcrumb">
                <a href="<?= e(url('admin/')) ?>" class="breadcrumb-item">Dashboard</a>
                <span class="breadcrumb-item">Catalog</span>
                <span class="breadcrumb-item active">Products</span>
            </nav>
        </div>
        <div>
            <?php if (Permissions::can('products.edit')): ?>
                <a href="<?= e(url('admin/products/create.php')) ?>" class="btn btn-primary">
                    <i class="bi bi-plus-lg me-1"></i> Add New Product
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php $flashSuccess = flash_get('success'); if ($flashSuccess): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i> <?= e($flashSuccess) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php $flashError = flash_get('error'); if ($flashError): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= e($flashError) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Filter & Search Card -->
    <div class="card mb-4">
        <div class="card-body py-3">
            <form action="<?= e(url('admin/products/')) ?>" method="GET" class="row g-2 align-items-center">
                <!-- Search -->
                <div class="col-12 col-md-4">
                    <div class="input-group">
                        <span class="input-group-text bg-transparent text-muted"><i class="bi bi-search"></i></span>
                        <input type="text" name="search" class="form-control" placeholder="Search by name, SKU, or slug..." value="<?= e($filters['search']) ?>">
                    </div>
                </div>

                <!-- Category Filter -->
                <div class="col-6 col-md-2">
                    <select name="category_id" class="form-select">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int)$cat['id'] ?>" <?= $filters['category_id'] === (int)$cat['id'] ? 'selected' : '' ?>>
                                <?= e($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Status Filter -->
                <div class="col-6 col-md-2">
                    <select name="status" class="form-select">
                        <option value="all" <?= $filters['status'] === 'all' ? 'selected' : '' ?>>All Status</option>
                        <option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $filters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        <option value="deleted" <?= $filters['status'] === 'deleted' ? 'selected' : '' ?>>Trash (Deleted)</option>
                    </select>
                </div>

                <!-- Stock Filter -->
                <div class="col-6 col-md-2">
                    <select name="stock_status" class="form-select">
                        <option value="" <?= empty($filters['stock_status']) ? 'selected' : '' ?>>All Stock</option>
                        <option value="in_stock" <?= $filters['stock_status'] === 'in_stock' ? 'selected' : '' ?>>In Stock (>5)</option>
                        <option value="low_stock" <?= $filters['stock_status'] === 'low_stock' ? 'selected' : '' ?>>Low Stock (1-5)</option>
                        <option value="out_of_stock" <?= $filters['stock_status'] === 'out_of_stock' ? 'selected' : '' ?>>Out of Stock (0)</option>
                    </select>
                </div>

                <!-- Action Buttons -->
                <div class="col-6 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-outline-primary w-100">Filter</button>
                    <a href="<?= e(url('admin/products/')) ?>" class="btn btn-outline-secondary" title="Reset Filters"><i class="bi bi-arrow-counterclockwise"></i></a>
                </div>
            </form>
        </div>
    </div>

    <!-- Products Table Card -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <h5 class="card-title mb-0">Products List</h5>
                <span class="text-muted" style="font-size: 13px;">Showing <?= count($products) ?> of <?= number_format($totalItems) ?> products</span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="text-muted small">Sort by:</span>
                <div class="btn-group btn-group-sm" role="group">
                    <a href="<?= e(url('admin/products/?' . http_build_query(array_merge($filters, ['sort' => 'name', 'order' => ($filters['sort'] === 'name' && $filters['order'] === 'asc' ? 'desc' : 'asc')])))) ?>" 
                       class="btn btn-outline-secondary <?= $filters['sort'] === 'name' ? 'active' : '' ?>">
                        Name <?= $filters['sort'] === 'name' ? ($filters['order'] === 'asc' ? '↑' : '↓') : '' ?>
                    </a>
                    <a href="<?= e(url('admin/products/?' . http_build_query(array_merge($filters, ['sort' => 'price', 'order' => ($filters['sort'] === 'price' && $filters['order'] === 'asc' ? 'desc' : 'asc')])))) ?>" 
                       class="btn btn-outline-secondary <?= $filters['sort'] === 'price' ? 'active' : '' ?>">
                        Price <?= $filters['sort'] === 'price' ? ($filters['order'] === 'asc' ? '↑' : '↓') : '' ?>
                    </a>
                    <a href="<?= e(url('admin/products/?' . http_build_query(array_merge($filters, ['sort' => 'stock', 'order' => ($filters['sort'] === 'stock' && $filters['order'] === 'asc' ? 'desc' : 'asc')])))) ?>" 
                       class="btn btn-outline-secondary <?= $filters['sort'] === 'stock' ? 'active' : '' ?>">
                        Stock <?= $filters['sort'] === 'stock' ? ($filters['order'] === 'asc' ? '↑' : '↓') : '' ?>
                    </a>
                    <a href="<?= e(url('admin/products/?' . http_build_query(array_merge($filters, ['sort' => 'created', 'order' => ($filters['sort'] === 'created' && $filters['order'] === 'asc' ? 'desc' : 'asc')])))) ?>" 
                       class="btn btn-outline-secondary <?= $filters['sort'] === 'created' ? 'active' : '' ?>">
                        Date <?= $filters['sort'] === 'created' ? ($filters['order'] === 'asc' ? '↑' : '↓') : '' ?>
                    </a>
                </div>
            </div>
        </div>

        <div class="card-body p-0">
            <?php if (empty($products)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-box-seam text-muted" style="font-size: 3rem;"></i>
                    <?php if (!empty($filters['search']) || !empty($filters['category_id']) || $filters['status'] !== 'all' || !empty($filters['stock_status'])): ?>
                        <h5 class="mt-3">No products match your filter criteria</h5>
                        <p class="text-muted">Try adjusting your search terms or clearing active filters.</p>
                        <a href="<?= e(url('admin/products/')) ?>" class="btn btn-outline-secondary btn-sm">Clear All Filters</a>
                    <?php else: ?>
                        <h5 class="mt-3">No products found in catalog</h5>
                        <p class="text-muted">Start creating products to showcase in your online store.</p>
                        <?php if (Permissions::can('products.edit')): ?>
                            <a href="<?= e(url('admin/products/create.php')) ?>" class="btn btn-primary btn-sm">
                                <i class="bi bi-plus-lg me-1"></i> Add Product
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 60px;">Image</th>
                                <th>Product Details</th>
                                <th>Category</th>
                                <th>Price (KES)</th>
                                <th class="text-center" style="width: 100px;">Stock</th>
                                <th class="text-center" style="width: 90px;">Status</th>
                                <th class="text-end" style="width: 150px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $prod): ?>
                                <tr class="<?= !empty($prod['deleted_at']) ? 'table-light text-muted' : '' ?>">
                                    <td>
                                        <div style="width: 44px; height: 44px; border-radius: var(--radius-sm); overflow: hidden; background: var(--background-color); display: flex; align-items: center; justify-content: center; border: 1px solid var(--border-color);">
                                            <img src="<?= e(product_image_url($prod['primary_image'])) ?>" 
                                                 alt="<?= e($prod['name']) ?>" 
                                                 style="width: 100%; height: 100%; object-fit: cover;">
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark">
                                            <a href="<?= e(url('admin/products/edit.php?id=' . $prod['id'])) ?>" class="text-reset text-decoration-none">
                                                <?= e($prod['name']) ?>
                                            </a>
                                            <?php if ($prod['is_featured']): ?>
                                                <span class="badge bg-warning-subtle text-warning-emphasis ms-1" style="font-size: 11px;">Featured</span>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-muted d-block font-monospace" style="font-size: 11px;">
                                            Slug: <?= e($prod['slug']) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border">
                                            <?= e($prod['category_name'] ?? 'Uncategorized') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="fw-semibold">
                                            <?php if (!empty($prod['min_price']) && !empty($prod['max_price']) && (float)$prod['min_price'] < (float)$prod['max_price']): ?>
                                                <?= e(format_money($prod['min_price'])) ?> - <?= e(format_money($prod['max_price'])) ?>
                                            <?php else: ?>
                                                <?= e(format_money($prod['base_price'])) ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($prod['compare_at_price'])): ?>
                                            <small class="text-muted text-decoration-line-through"><?= e(format_money($prod['compare_at_price'])) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php $stock = (int)$prod['total_stock']; ?>
                                        <?php if ($stock === 0): ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle">0 Out of Stock</span>
                                        <?php elseif ($stock <= 5): ?>
                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"><?= $stock ?> Low Stock</span>
                                        <?php else: ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle"><?= $stock ?> in stock</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if (!empty($prod['deleted_at'])): ?>
                                            <span class="badge bg-secondary text-white">Deleted</span>
                                        <?php elseif ($prod['is_active']): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-muted border border-secondary-subtle">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-1">
                                            <a href="<?= e(url('admin/products/edit.php?id=' . $prod['id'])) ?>" class="btn btn-outline-secondary btn-sm" title="Edit Product">
                                                <i class="bi bi-pencil"></i>
                                            </a>

                                            <?php if (empty($prod['deleted_at'])): ?>
                                                <!-- Toggle Active -->
                                                <form action="<?= e(url('admin/products/')) ?>" method="POST" class="d-inline">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="toggle">
                                                    <input type="hidden" name="id" value="<?= (int)$prod['id'] ?>">
                                                    <input type="hidden" name="redirect_query" value="<?= e($currentQuery) ?>">
                                                    <button type="submit" class="btn btn-outline-secondary btn-sm" title="<?= $prod['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                                        <i class="bi <?= $prod['is_active'] ? 'bi-eye-slash' : 'bi-eye' ?>"></i>
                                                    </button>
                                                </form>

                                                <!-- Soft Delete (Admin Only) -->
                                                <?php if (Permissions::can('products.delete')): ?>
                                                    <form action="<?= e(url('admin/products/')) ?>" method="POST" class="d-inline" onsubmit="return confirm('Move this product to trash?');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="soft_delete">
                                                        <input type="hidden" name="id" value="<?= (int)$prod['id'] ?>">
                                                        <input type="hidden" name="redirect_query" value="<?= e($currentQuery) ?>">
                                                        <button type="submit" class="btn btn-outline-danger btn-sm" title="Move to Trash">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <!-- Restore (Admin Only) -->
                                                <?php if (Permissions::can('products.delete')): ?>
                                                    <form action="<?= e(url('admin/products/')) ?>" method="POST" class="d-inline">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="restore">
                                                        <input type="hidden" name="id" value="<?= (int)$prod['id'] ?>">
                                                        <input type="hidden" name="redirect_query" value="<?= e($currentQuery) ?>">
                                                        <button type="submit" class="btn btn-outline-success btn-sm" title="Restore Product">
                                                            <i class="bi bi-arrow-counterclockwise"></i> Restore
                                                        </button>
                                                    </form>

                                                    <form action="<?= e(url('admin/products/')) ?>" method="POST" class="d-inline" onsubmit="return confirm('Permanently delete this product? This action cannot be undone.');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="hard_delete">
                                                        <input type="hidden" name="id" value="<?= (int)$prod['id'] ?>">
                                                        <input type="hidden" name="redirect_query" value="<?= e($currentQuery) ?>">
                                                        <button type="submit" class="btn btn-danger btn-sm" title="Permanently Delete">
                                                            <i class="bi bi-x-lg"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="card-footer d-flex justify-content-between align-items-center py-2">
                        <small class="text-muted">Page <?= $page ?> of <?= $totalPages ?></small>
                        <nav aria-label="Products pagination">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= e(url('admin/products/?' . http_build_query(array_merge($filters, ['page' => $page - 1])))) ?>">Previous</a>
                                </li>
                                <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= e(url('admin/products/?' . http_build_query(array_merge($filters, ['page' => $p])))) ?>"><?= $p ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= e(url('admin/products/?' . http_build_query(array_merge($filters, ['page' => $page + 1])))) ?>">Next</a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
require dirname(__DIR__, 3) . '/app/views/admin/footer.php';
