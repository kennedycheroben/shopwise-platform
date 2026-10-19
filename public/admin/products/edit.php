<?php
require dirname(__DIR__, 3) . '/app/bootstrap.php';
require dirname(__DIR__, 3) . '/app/views/admin/guard.php';

use App\Services\ProductService;
use App\Services\VariantService;
use App\Services\ProductImageService;
use App\Services\CategoryService;
use App\Services\Permissions;
use App\Services\AdminAuthService;

Permissions::require('products.view');
$currentUser = AdminAuthService::user();
$actorId = (int)$currentUser['id'];
$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

$productId = (int)($_GET['id'] ?? $_POST['product_id'] ?? 0);
$product = ProductService::find($productId);

if (!$product) {
    http_response_code(404);
    require dirname(__DIR__, 3) . '/app/views/admin/error-404.php';
    exit;
}

$activeTab = $_GET['tab'] ?? 'general';
$errors = [];

// Handle all POST actions for product edit, variants, and images
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['_csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Forbidden: Invalid CSRF token.');
    }

    $action = $_POST['action'] ?? '';

    try {
        // --- Action: Update Product Details ---
        if ($action === 'update_product') {
            Permissions::require('products.edit');
            $validation = ProductService::validate($_POST, $productId);
            if (!empty($validation['errors'])) {
                $errors = $validation['errors'];
                $activeTab = 'general';
            } else {
                ProductService::update($productId, $_POST, $actorId, $ip);
                flash_set('success', 'Product details updated successfully.');
                redirect('admin/products/edit.php?id=' . $productId . '&tab=general');
            }
        }

        // --- Action: Add Single Variant ---
        elseif ($action === 'variant_add') {
            Permissions::require('products.edit');
            VariantService::create($productId, $_POST, $actorId, $ip);
            flash_set('success', 'New variant added successfully.');
            redirect('admin/products/edit.php?id=' . $productId . '&tab=variants');
        }

        // --- Action: Update Variant ---
        elseif ($action === 'variant_update') {
            Permissions::require('products.edit');
            $variantId = (int)($_POST['variant_id'] ?? 0);
            VariantService::update($variantId, $_POST, $actorId, $ip, $productId);
            flash_set('success', 'Variant updated successfully.');
            redirect('admin/products/edit.php?id=' . $productId . '&tab=variants');
        }

        // --- Action: Delete Variant ---
        elseif ($action === 'variant_delete') {
            Permissions::require('products.edit');
            $variantId = (int)($_POST['variant_id'] ?? 0);
            VariantService::delete($variantId, $actorId, $ip, $productId);
            flash_set('success', 'Variant deleted successfully.');
            redirect('admin/products/edit.php?id=' . $productId . '&tab=variants');
        }

        // --- Action: Generate Variants Matrix ---
        elseif ($action === 'variant_generate') {
            Permissions::require('products.edit');
            $rawSizes = explode(',', $_POST['sizes'] ?? '');
            $rawColors = [];
            $colorInput = trim($_POST['colors'] ?? '');
            if ($colorInput !== '') {
                $colorItems = explode(',', $colorInput);
                foreach ($colorItems as $ci) {
                    $ci = trim($ci);
                    if ($ci === '') continue;
                    if (str_contains($ci, ':')) {
                        [$cName, $cHex] = explode(':', $ci, 2);
                        $rawColors[] = ['name' => trim($cName), 'hex' => trim($cHex)];
                    } else {
                        $rawColors[] = ['name' => $ci, 'hex' => ''];
                    }
                }
            }

            $generated = VariantService::generateVariants($productId, $rawSizes, $rawColors, $actorId, $ip);
            $count = count($generated);
            if ($count > 0) {
                flash_set('success', "Generated {$count} new variant(s) successfully.");
            } else {
                flash_set('info', "All specified size and color combinations already exist. No new variants were created.");
            }
            redirect('admin/products/edit.php?id=' . $productId . '&tab=variants');
        }

        // --- Action: Upload Images ---
        elseif ($action === 'upload_images') {
            Permissions::require('products.edit');
            $colorAssoc = !empty($_POST['image_color']) ? trim($_POST['image_color']) : null;
            $altText = !empty($_POST['image_alt']) ? trim($_POST['image_alt']) : null;

            if (empty($_FILES['images']) || (is_array($_FILES['images']['error']) && $_FILES['images']['error'][0] === UPLOAD_ERR_NO_FILE)) {
                throw new InvalidArgumentException("Please select at least one image file to upload.");
            }

            $res = ProductImageService::addImages($productId, $_FILES['images'], $colorAssoc, $altText, $actorId, $ip);
            
            $successCount = count($res['saved']);
            $errorCount = count($res['errors']);

            if ($successCount > 0 && $errorCount === 0) {
                flash_set('success', "{$successCount} image(s) uploaded and optimized successfully.");
            } elseif ($successCount > 0 && $errorCount > 0) {
                $errorMsgs = array_map(fn($e) => "{$e['file']}: {$e['message']}", $res['errors']);
                flash_set('warning', "{$successCount} image(s) uploaded. Note: " . implode('; ', $errorMsgs));
            } else {
                $errorMsgs = array_map(fn($e) => "{$e['file']}: {$e['message']}", $res['errors']);
                flash_set('error', "Upload failed: " . implode('; ', $errorMsgs));
            }
            redirect('admin/products/edit.php?id=' . $productId . '&tab=images');
        }

        // --- Action: Set Primary Image ---
        elseif ($action === 'set_primary_image') {
            Permissions::require('products.edit');
            $imageId = (int)($_POST['image_id'] ?? 0);
            ProductImageService::setPrimary($productId, $imageId, $actorId, $ip);
            flash_set('success', 'Primary product image updated.');
            redirect('admin/products/edit.php?id=' . $productId . '&tab=images');
        }

        // --- Action: Reorder Images ---
        elseif ($action === 'reorder_images') {
            Permissions::require('products.edit');
            $orderedIds = $_POST['ordered_ids'] ?? [];
            if (!empty($orderedIds) && is_array($orderedIds)) {
                ProductImageService::reorder($productId, $orderedIds, $actorId, $ip);
                flash_set('success', 'Image display order updated.');
            }
            redirect('admin/products/edit.php?id=' . $productId . '&tab=images');
        }

        // --- Action: Update Image Metadata ---
        elseif ($action === 'update_image') {
            Permissions::require('products.edit');
            $imageId = (int)($_POST['image_id'] ?? 0);
            ProductImageService::update($productId, $imageId, $_POST, $actorId, $ip);
            flash_set('success', 'Image details updated.');
            redirect('admin/products/edit.php?id=' . $productId . '&tab=images');
        }

        // --- Action: Delete Image ---
        elseif ($action === 'delete_image') {
            Permissions::require('products.edit');
            $imageId = (int)($_POST['image_id'] ?? 0);
            ProductImageService::delete($productId, $imageId, $actorId, $ip);
            flash_set('success', 'Image deleted successfully.');
            redirect('admin/products/edit.php?id=' . $productId . '&tab=images');
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
        redirect('admin/products/edit.php?id=' . $productId . '&tab=' . urlencode($activeTab));
    }
}

// Reload product, variants, and images
$product = ProductService::find($productId);
$variants = VariantService::getByProductId($productId);
$images = ProductImageService::getByProductId($productId);
$categories = CategoryService::getAll();

// Get unique variant colors for image color tagging
$variantColors = [];
foreach ($variants as $v) {
    if (!empty($v['color']) && !in_array($v['color'], $variantColors, true)) {
        $variantColors[] = $v['color'];
    }
}

$pageTitle = 'Edit Product: ' . $product['name'];

require dirname(__DIR__, 3) . '/app/views/admin/layout.php';
?>

<div class="main-content page-product-edit">
    <!-- Page Header -->
    <div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h1 class="page-title mb-0"><?= e($product['name']) ?></h1>
                <?php if (!empty($product['deleted_at'])): ?>
                    <span class="badge bg-secondary text-white">Soft Deleted</span>
                <?php elseif ($product['is_active']): ?>
                    <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                <?php else: ?>
                    <span class="badge bg-secondary-subtle text-muted border border-secondary-subtle">Inactive</span>
                <?php endif; ?>
                <?php if ($product['is_featured']): ?>
                    <span class="badge bg-warning-subtle text-warning-emphasis">Featured</span>
                <?php endif; ?>
            </div>
            <nav class="breadcrumb mt-1">
                <a href="<?= e(url('admin/')) ?>" class="breadcrumb-item">Dashboard</a>
                <a href="<?= e(url('admin/products/')) ?>" class="breadcrumb-item">Products</a>
                <span class="breadcrumb-item active"><?= e($product['name']) ?></span>
            </nav>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= e(url('admin/products/')) ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Catalog
            </a>
            <?php if (empty($product['deleted_at'])): ?>
                <a href="<?= e(url('shop/product.php?slug=' . urlencode($product['slug']))) ?>" target="_blank" class="btn btn-outline-primary" title="View on Customer Storefront">
                    <i class="bi bi-box-arrow-up-right me-1"></i> View Live
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

    <?php $flashWarning = flash_get('warning'); if ($flashWarning): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= e($flashWarning) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php $flashInfo = flash_get('info'); if ($flashInfo): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <i class="bi bi-info-circle-fill me-2"></i> <?= e($flashInfo) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php $flashError = flash_get('error'); if ($flashError): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-octagon-fill me-2"></i> <?= e($flashError) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Navigation Tabs -->
    <div class="card mb-4">
        <div class="card-header border-bottom-0 pb-0">
            <ul class="nav nav-tabs card-header-tabs" id="productTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $activeTab === 'general' ? 'active' : '' ?>" id="tab-general-btn" data-bs-toggle="tab" data-bs-target="#tab-general" type="button" role="tab">
                        <i class="bi bi-info-circle me-1"></i> Product Details
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $activeTab === 'variants' ? 'active' : '' ?>" id="tab-variants-btn" data-bs-toggle="tab" data-bs-target="#tab-variants" type="button" role="tab">
                        <i class="bi bi-layers me-1"></i> Variants & Inventory 
                        <span class="badge rounded-pill bg-light text-dark border ms-1"><?= count($variants) ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $activeTab === 'images' ? 'active' : '' ?>" id="tab-images-btn" data-bs-toggle="tab" data-bs-target="#tab-images" type="button" role="tab">
                        <i class="bi bi-images me-1"></i> Product Photos 
                        <span class="badge rounded-pill bg-light text-dark border ms-1"><?= count($images) ?></span>
                    </button>
                </li>
            </ul>
        </div>
    </div>

    <!-- Tab Contents -->
    <div class="tab-content" id="productTabsContent">

        <!-- ========================================== -->
        <!-- TAB 1: PRODUCT DETAILS                     -->
        <!-- ========================================== -->
        <div class="tab-pane fade <?= $activeTab === 'general' ? 'show active' : '' ?>" id="tab-general" role="tabpanel">
            <form action="<?= e(url('admin/products/edit.php?id=' . $productId)) ?>" method="POST" id="productEditForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_product">
                <input type="hidden" name="product_id" value="<?= $productId ?>">

                <div class="row g-4">
                    <div class="col-lg-8">
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">General Details</h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label for="name" class="form-label fw-semibold">Product Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>" id="name" name="name" value="<?= e($product['name']) ?>" required>
                                    <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?= e($errors['name']) ?></div><?php endif; ?>
                                </div>

                                <div class="mb-3">
                                    <label for="slug" class="form-label fw-semibold">URL Slug <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light text-muted" style="font-size: 13px;">/product/</span>
                                        <input type="text" class="form-control font-monospace <?= isset($errors['slug']) ? 'is-invalid' : '' ?>" id="slug" name="slug" value="<?= e($product['slug']) ?>" required>
                                    </div>
                                    <?php if (isset($errors['slug'])): ?><div class="text-danger small mt-1"><?= e($errors['slug']) ?></div><?php endif; ?>
                                </div>

                                <div class="mb-3">
                                    <label for="category_id" class="form-label fw-semibold">Category <span class="text-danger">*</span></label>
                                    <select class="form-select <?= isset($errors['category_id']) ? 'is-invalid' : '' ?>" id="category_id" name="category_id" required>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?= (int)$cat['id'] ?>" <?= ((int)$product['category_id'] === (int)$cat['id']) ? 'selected' : '' ?>>
                                                <?= !empty($cat['parent_name']) ? e($cat['parent_name']) . ' &rsaquo; ' : '' ?><?= e($cat['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (isset($errors['category_id'])): ?><div class="invalid-feedback"><?= e($errors['category_id']) ?></div><?php endif; ?>
                                </div>

                                <div class="mb-3">
                                    <label for="short_description" class="form-label fw-semibold">Short Summary / Excerpt <span class="text-danger">*</span></label>
                                    <textarea class="form-control <?= isset($errors['short_description']) ? 'is-invalid' : '' ?>" id="short_description" name="short_description" rows="2" maxlength="500" required><?= e($product['short_description']) ?></textarea>
                                    <?php if (isset($errors['short_description'])): ?><div class="invalid-feedback"><?= e($errors['short_description']) ?></div><?php endif; ?>
                                </div>

                                <div class="mb-0">
                                    <label class="form-label fw-semibold">Full Product Description <span class="text-danger">*</span></label>
                                    <div id="quillEditor" style="min-height: 220px; background: #fff;">
                                        <?= $product['description'] ?>
                                    </div>
                                    <input type="hidden" name="description" id="hiddenDescription" value="<?= e($product['description']) ?>">
                                    <?php if (isset($errors['description'])): ?><div class="text-danger small mt-1"><?= e($errors['description']) ?></div><?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- SEO Card -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Search Engine Optimization (SEO)</h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label for="meta_title" class="form-label fw-semibold">SEO Meta Title</label>
                                    <input type="text" class="form-control" id="meta_title" name="meta_title" value="<?= e($product['meta_title'] ?? '') ?>" placeholder="Leave blank to use the product name">
                                </div>
                                <div class="mb-0">
                                    <label for="meta_description" class="form-label fw-semibold">SEO Meta Description</label>
                                    <textarea class="form-control" id="meta_description" name="meta_description" rows="2" maxlength="500"><?= e($product['meta_description'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <!-- Pricing Card -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Pricing (KES)</h5>
                                <p class="card-subtitle text-muted mb-0" style="font-size: 13px;">Prices are in Kenyan Shillings (KES).</p>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label for="base_price" class="form-label fw-semibold">Base Price (KES) <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">KES</span>
                                        <input type="number" step="0.01" min="0.01" class="form-control <?= isset($errors['base_price']) ? 'is-invalid' : '' ?>" id="base_price" name="base_price" value="<?= e($product['base_price']) ?>" required>
                                    </div>
                                    <?php if (isset($errors['base_price'])): ?><div class="text-danger small mt-1"><?= e($errors['base_price']) ?></div><?php endif; ?>
                                </div>

                                <div class="mb-0">
                                    <label for="compare_at_price" class="form-label fw-semibold">Compare-At Price (KES)</label>
                                    <div class="input-group">
                                        <span class="input-group-text">KES</span>
                                        <input type="number" step="0.01" min="0.01" class="form-control <?= isset($errors['compare_at_price']) ? 'is-invalid' : '' ?>" id="compare_at_price" name="compare_at_price" value="<?= e($product['compare_at_price'] ?? '') ?>">
                                    </div>
                                    <div class="form-text">Must be strictly greater than base price if set.</div>
                                    <?php if (isset($errors['compare_at_price'])): ?><div class="text-danger small mt-1"><?= e($errors['compare_at_price']) ?></div><?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Visibility Card -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Visibility & Status</h5>
                            </div>
                            <div class="card-body">
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?= $product['is_active'] ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-semibold" for="is_active">Active & Available in Store</label>
                                </div>

                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" id="is_featured" name="is_featured" value="1" <?= $product['is_featured'] ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-semibold" for="is_featured">Featured Product</label>
                                </div>
                            </div>
                        </div>

                        <!-- Save Button Card -->
                        <div class="card">
                            <div class="card-body">
                                <button type="submit" class="btn btn-primary w-100" id="btnUpdateProduct">
                                    <i class="bi bi-save me-1"></i> Save Changes
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- ========================================== -->
        <!-- TAB 2: VARIANTS & INVENTORY                -->
        <!-- ========================================== -->
        <div class="tab-pane fade <?= $activeTab === 'variants' ? 'show active' : '' ?>" id="tab-variants" role="tabpanel">
            <div class="card">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <h5 class="card-title mb-0">Product Variants</h5>
                        <p class="card-subtitle text-muted mb-0" style="font-size: 13px;">Manage SKU identifiers, sizes, colors, price overrides, and stock levels.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#generateVariantsModal">
                            <i class="bi bi-grid-3x3 me-1"></i> Generate Variants Matrix
                        </button>
                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addVariantModal">
                            <i class="bi bi-plus-lg me-1"></i> Add Single Variant
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>SKU</th>
                                    <th>Size</th>
                                    <th>Color</th>
                                    <th>Price Override</th>
                                    <th class="text-center">Stock Quantity</th>
                                    <th class="text-center">Threshold</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-end" style="width: 120px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($variants)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4 text-muted">
                                            No variants exist for this product. Click "Add Single Variant" or "Generate Matrix".
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($variants as $v): ?>
                                        <tr>
                                            <td>
                                                <code class="fw-bold text-dark" style="font-size: 13px;"><?= e($v['sku']) ?></code>
                                            </td>
                                            <td>
                                                <?= !empty($v['size']) ? '<span class="badge bg-light text-dark border">' . e($v['size']) . '</span>' : '<span class="text-muted small">Standard</span>' ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($v['color'])): ?>
                                                    <div class="d-inline-flex align-items-center gap-1">
                                                        <?php if (!empty($v['color_hex'])): ?>
                                                            <span style="display:inline-block; width:14px; height:14px; border-radius:50%; background:<?= e($v['color_hex']) ?>; border:1px solid rgba(0,0,0,0.2);"></span>
                                                        <?php endif; ?>
                                                        <span><?= e($v['color']) ?></span>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted small">Standard</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($v['price_override'] !== null): ?>
                                                    <span class="fw-semibold text-primary"><?= e(format_money($v['price_override'])) ?></span>
                                                    <small class="text-muted d-block" style="font-size: 11px;">Override</small>
                                                <?php else: ?>
                                                    <span class="text-muted"><?= e(format_money($product['base_price'])) ?></span>
                                                    <small class="text-muted d-block" style="font-size: 11px;">Base Price</small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php $stk = (int)$v['stock_qty']; ?>
                                                <?php if ($stk === 0): ?>
                                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle">0 Out of Stock</span>
                                                <?php elseif ($stk <= (int)$v['low_stock_threshold']): ?>
                                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"><?= $stk ?> Low Stock</span>
                                                <?php else: ?>
                                                    <span class="badge bg-success-subtle text-success border border-success-subtle"><?= $stk ?> in stock</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="text-muted small"><?= (int)$v['low_stock_threshold'] ?></span>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($v['is_active']): ?>
                                                    <span class="badge bg-success-subtle text-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary-subtle text-muted">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <div class="d-inline-flex gap-1">
                                                    <button type="button" 
                                                            class="btn btn-outline-secondary btn-sm edit-variant-btn"
                                                            data-id="<?= (int)$v['id'] ?>"
                                                            data-sku="<?= e($v['sku']) ?>"
                                                            data-size="<?= e($v['size'] ?? '') ?>"
                                                            data-color="<?= e($v['color'] ?? '') ?>"
                                                            data-color-hex="<?= e($v['color_hex'] ?? '') ?>"
                                                            data-price-override="<?= e($v['price_override'] ?? '') ?>"
                                                            data-stock-qty="<?= (int)$v['stock_qty'] ?>"
                                                            data-low-stock="<?= (int)$v['low_stock_threshold'] ?>"
                                                            data-is-active="<?= (int)$v['is_active'] ?>"
                                                            title="Edit Variant">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button type="button" 
                                                            class="btn btn-outline-danger btn-sm delete-variant-btn"
                                                            data-id="<?= (int)$v['id'] ?>"
                                                            data-sku="<?= e($v['sku']) ?>"
                                                            title="Delete Variant">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- TAB 3: PHOTOS & MEDIA                      -->
        <!-- ========================================== -->
        <div class="tab-pane fade <?= $activeTab === 'images' ? 'show active' : '' ?>" id="tab-images" role="tabpanel">
            <!-- Upload Box -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Upload New Product Photos</h5>
                    <p class="card-subtitle text-muted mb-0" style="font-size: 13px;">Supports genuine JPG, PNG, and WebP up to 5MB each. Automatically converted to optimized WebP.</p>
                </div>
                <div class="card-body">
                    <form action="<?= e(url('admin/products/edit.php?id=' . $productId)) ?>" method="POST" enctype="multipart/form-data" id="imageUploadForm">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="upload_images">
                        <input type="hidden" name="product_id" value="<?= $productId ?>">

                        <div class="row g-3 align-items-end mb-3">
                            <div class="col-md-6">
                                <label for="imagesInput" class="form-label fw-semibold">Select Image Files <span class="text-danger">*</span></label>
                                <input class="form-control" type="file" id="imagesInput" name="images[]" accept="image/jpeg,image/png,image/webp" multiple required>
                            </div>
                            <div class="col-md-3">
                                <label for="image_color" class="form-label fw-semibold">Variant Color Tag</label>
                                <select class="form-select" id="image_color" name="image_color">
                                    <option value="">All / No specific color</option>
                                    <?php foreach ($variantColors as $vc): ?>
                                        <option value="<?= e($vc) ?>"><?= e($vc) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="image_alt" class="form-label fw-semibold">Image Alt Text</label>
                                <input type="text" class="form-control" id="image_alt" name="image_alt" placeholder="e.g. Front View">
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary" id="btnUploadImages">
                            <i class="bi bi-cloud-arrow-up me-1"></i> Upload & Process Photos
                        </button>
                    </form>
                </div>
            </div>

            <!-- Existing Photos Gallery -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="card-title mb-0">Photo Gallery</h5>
                        <p class="card-subtitle text-muted mb-0" style="font-size: 13px;">Reorder, set primary display thumbnail, or associate photos with variant colors.</p>
                    </div>
                    <span class="badge rounded-pill bg-light text-dark border"><?= count($images) ?> image(s)</span>
                </div>
                <div class="card-body">
                    <?php if (empty($images)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-image text-muted" style="font-size: 3rem;"></i>
                            <h5 class="mt-3">No images uploaded for this product</h5>
                            <p class="text-muted">Upload high-resolution photos above to attract more shoppers.</p>
                        </div>
                    <?php else: ?>
                        <div class="row g-3" id="imageGalleryGrid">
                            <?php foreach ($images as $img): ?>
                                <div class="col-6 col-sm-4 col-md-3 col-xl-2 image-card-wrapper" data-image-id="<?= (int)$img['id'] ?>">
                                    <div class="card h-100 border <?= $img['is_primary'] ? 'border-primary shadow-sm' : '' ?>" style="overflow: hidden;">
                                        <div style="position: relative; height: 160px; background: var(--background-color);">
                                            <img src="<?= e(product_image_url($img['path'])) ?>" alt="<?= e($img['alt_text']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                            
                                            <?php if ($img['is_primary']): ?>
                                                <span class="badge bg-primary position-absolute top-0 start-0 m-2">
                                                    <i class="bi bi-star-fill me-1"></i> Primary
                                                </span>
                                            <?php endif; ?>

                                            <?php if (!empty($img['color'])): ?>
                                                <span class="badge bg-dark bg-opacity-75 position-absolute bottom-0 start-0 m-2">
                                                    <?= e($img['color']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="card-body p-2 d-flex flex-column justify-content-between">
                                            <div class="small text-truncate mb-2" title="<?= e($img['alt_text']) ?>">
                                                <?= e($img['alt_text']) ?>
                                            </div>

                                            <div class="d-flex justify-content-between align-items-center gap-1">
                                                <?php if (!$img['is_primary']): ?>
                                                    <form action="<?= e(url('admin/products/edit.php?id=' . $productId)) ?>" method="POST" class="d-inline">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="set_primary_image">
                                                        <input type="hidden" name="product_id" value="<?= $productId ?>">
                                                        <input type="hidden" name="image_id" value="<?= (int)$img['id'] ?>">
                                                        <button type="submit" class="btn btn-outline-primary btn-sm p-1" title="Set as Primary">
                                                            <i class="bi bi-star"></i>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-primary btn-sm p-1 disabled" title="Current Primary">
                                                        <i class="bi bi-star-fill"></i>
                                                    </button>
                                                <?php endif; ?>

                                                <button type="button" 
                                                        class="btn btn-outline-secondary btn-sm p-1 edit-image-btn"
                                                        data-id="<?= (int)$img['id'] ?>"
                                                        data-alt="<?= e($img['alt_text']) ?>"
                                                        data-color="<?= e($img['color'] ?? '') ?>"
                                                        title="Edit Alt / Color">
                                                    <i class="bi bi-pencil"></i>
                                                </button>

                                                <form action="<?= e(url('admin/products/edit.php?id=' . $productId)) ?>" method="POST" class="d-inline" onsubmit="return confirm('Delete this image?');">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="delete_image">
                                                    <input type="hidden" name="product_id" value="<?= $productId ?>">
                                                    <input type="hidden" name="image_id" value="<?= (int)$img['id'] ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm p-1" title="Delete Image">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Reorder Images Form (Optional buttons) -->
                        <form action="<?= e(url('admin/products/edit.php?id=' . $productId)) ?>" method="POST" id="reorderImagesForm" class="mt-3">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reorder_images">
                            <input type="hidden" name="product_id" value="<?= $productId ?>">
                            <?php foreach ($images as $img): ?>
                                <input type="hidden" name="ordered_ids[]" value="<?= (int)$img['id'] ?>">
                            <?php endforeach; ?>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- ======================================================= -->
<!-- MODALS                                                  -->
<!-- ======================================================= -->

<!-- Add Single Variant Modal -->
<div class="modal fade" id="addVariantModal" tabindex="-1" aria-labelledby="addVariantModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form action="<?= e(url('admin/products/edit.php?id=' . $productId)) ?>" method="POST" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="variant_add">
            <input type="hidden" name="product_id" value="<?= $productId ?>">

            <div class="modal-header">
                <h5 class="modal-title" id="addVariantModalLabel">Add Product Variant</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="add_sku" class="form-label fw-semibold">SKU <span class="text-danger">*</span></label>
                    <input type="text" class="form-control font-monospace" id="add_sku" name="sku" required placeholder="e.g. BOOT-BRN-42">
                    <div class="form-text">Must be unique across all product variants.</div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="add_size" class="form-label fw-semibold">Size</label>
                        <input type="text" class="form-control" id="add_size" name="size" placeholder="e.g. 42 or XL">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="add_color" class="form-label fw-semibold">Color Name</label>
                        <input type="text" class="form-control" id="add_color" name="color" placeholder="e.g. Safari Tan">
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="add_color_hex" class="form-label fw-semibold">Color Hex</label>
                        <div class="input-group">
                            <input type="color" class="form-control form-control-color" id="add_color_picker" value="#964B00" title="Choose color">
                            <input type="text" class="form-control font-monospace" id="add_color_hex" name="color_hex" placeholder="#964B00">
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="add_price_override" class="form-label fw-semibold">Price Override (KES)</label>
                        <input type="number" step="0.01" min="0" class="form-control" id="add_price_override" name="price_override" placeholder="Leave empty for base price">
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="add_stock_qty" class="form-label fw-semibold">Initial Stock</label>
                        <input type="number" min="0" class="form-control" id="add_stock_qty" name="stock_qty" value="0" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="add_low_stock" class="form-label fw-semibold">Low Stock Threshold</label>
                        <input type="number" min="0" class="form-control" id="add_low_stock" name="low_stock_threshold" value="5" required>
                    </div>
                </div>

                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" id="add_is_active" name="is_active" value="1" checked>
                    <label class="form-check-label fw-semibold" for="add_is_active">Active & Purchasable</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Variant</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Variant Modal -->
<div class="modal fade" id="editVariantModal" tabindex="-1" aria-labelledby="editVariantModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form action="<?= e(url('admin/products/edit.php?id=' . $productId)) ?>" method="POST" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="variant_update">
            <input type="hidden" name="product_id" value="<?= $productId ?>">
            <input type="hidden" name="variant_id" id="edit_variant_id">

            <div class="modal-header">
                <h5 class="modal-title" id="editVariantModalLabel">Edit Variant</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="edit_v_sku" class="form-label fw-semibold">SKU <span class="text-danger">*</span></label>
                    <input type="text" class="form-control font-monospace" id="edit_v_sku" name="sku" required>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="edit_v_size" class="form-label fw-semibold">Size</label>
                        <input type="text" class="form-control" id="edit_v_size" name="size">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="edit_v_color" class="form-label fw-semibold">Color Name</label>
                        <input type="text" class="form-control" id="edit_v_color" name="color">
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="edit_v_color_hex" class="form-label fw-semibold">Color Hex</label>
                        <div class="input-group">
                            <input type="color" class="form-control form-control-color" id="edit_v_color_picker" value="#000000">
                            <input type="text" class="form-control font-monospace" id="edit_v_color_hex" name="color_hex">
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="edit_v_price_override" class="form-label fw-semibold">Price Override (KES)</label>
                        <input type="number" step="0.01" min="0" class="form-control" id="edit_v_price_override" name="price_override">
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="edit_v_stock_qty" class="form-label fw-semibold">Stock Quantity</label>
                        <input type="number" min="0" class="form-control" id="edit_v_stock_qty" name="stock_qty" required>
                        <div class="form-text">Adjusting stock here records an inventory movement.</div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="edit_v_low_stock" class="form-label fw-semibold">Low Stock Threshold</label>
                        <input type="number" min="0" class="form-control" id="edit_v_low_stock" name="low_stock_threshold" required>
                    </div>
                </div>

                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" id="edit_v_is_active" name="is_active" value="1">
                    <label class="form-check-label fw-semibold" for="edit_v_is_active">Active & Purchasable</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Variant</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Variant Confirmation Modal -->
<div class="modal fade" id="deleteVariantModal" tabindex="-1" aria-labelledby="deleteVariantModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form action="<?= e(url('admin/products/edit.php?id=' . $productId)) ?>" method="POST" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="variant_delete">
            <input type="hidden" name="product_id" value="<?= $productId ?>">
            <input type="hidden" name="variant_id" id="delete_variant_id">

            <div class="modal-header">
                <h5 class="modal-title text-danger" id="deleteVariantModalLabel">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i> Delete Variant
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete variant <code id="delete_variant_sku" class="fw-bold"></code>?</p>
                <div class="alert alert-warning mb-0" style="font-size: 13px;">
                    <i class="bi bi-shield-lock me-1"></i> Note: A variant linked to past customer orders cannot be deleted (it can only be deactivated). Every product must retain at least one active variant.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger">Delete Variant</button>
            </div>
        </form>
    </div>
</div>

<!-- Generate Variants Matrix Modal -->
<div class="modal fade" id="generateVariantsModal" tabindex="-1" aria-labelledby="generateVariantsModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form action="<?= e(url('admin/products/edit.php?id=' . $productId)) ?>" method="POST" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="variant_generate">
            <input type="hidden" name="product_id" value="<?= $productId ?>">

            <div class="modal-header">
                <h5 class="modal-title" id="generateVariantsModalLabel">Generate Variants Matrix</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">Enter sizes and colors separated by commas. The system will preview combinations and create only missing ones without creating duplicates.</p>
                
                <div class="mb-3">
                    <label for="matrix_sizes" class="form-label fw-semibold">Sizes (comma-separated)</label>
                    <input type="text" class="form-control" id="matrix_sizes" name="sizes" placeholder="e.g. S, M, L, XL">
                </div>

                <div class="mb-3">
                    <label for="matrix_colors" class="form-label fw-semibold">Colors (comma-separated: Name or Name:Hex)</label>
                    <input type="text" class="form-control" id="matrix_colors" name="colors" placeholder="e.g. Black:#000000, Brown:#964B00, Navy:#000080">
                    <div class="form-text">Optionally provide hex codes following a colon.</div>
                </div>

                <div class="card bg-light border p-2">
                    <div class="small fw-semibold text-muted mb-1">Generated SKUs will follow the pattern:</div>
                    <code class="text-primary small"><?= strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $product['slug'])) ?>-[SIZE]-[COLOR]</code>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Generate Combinations</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Image Alt / Color Modal -->
<div class="modal fade" id="editImageModal" tabindex="-1" aria-labelledby="editImageModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form action="<?= e(url('admin/products/edit.php?id=' . $productId)) ?>" method="POST" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_image">
            <input type="hidden" name="product_id" value="<?= $productId ?>">
            <input type="hidden" name="image_id" id="edit_image_id">

            <div class="modal-header">
                <h5 class="modal-title" id="editImageModalLabel">Edit Photo Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="edit_image_alt" class="form-label fw-semibold">Alt Text <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="edit_image_alt" name="alt_text" required>
                    <div class="form-text">Describes the image for screen readers and SEO.</div>
                </div>

                <div class="mb-0">
                    <label for="edit_image_color" class="form-label fw-semibold">Variant Color Tag</label>
                    <select class="form-select" id="edit_image_color" name="color">
                        <option value="">No Color Association</option>
                        <?php foreach ($variantColors as $vc): ?>
                            <option value="<?= e($vc) ?>"><?= e($vc) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<?php
$pageScripts = <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Initialize Quill Editor if on details tab
    if (typeof Quill !== 'undefined' && document.getElementById('quillEditor')) {
        const quill = new Quill('#quillEditor', {
            theme: 'snow',
            modules: {
                toolbar: [
                    [{ 'header': [3, 4, 5, false] }],
                    ['bold', 'italic', 'underline', 'strike'],
                    [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                    ['blockquote', 'link'],
                    ['clean']
                ]
            }
        });

        const form = document.getElementById('productEditForm');
        if (form) {
            form.addEventListener('submit', function () {
                const hiddenDesc = document.getElementById('hiddenDescription');
                if (quill.getText().trim().length > 0) {
                    hiddenDesc.value = quill.root.innerHTML;
                } else {
                    hiddenDesc.value = '';
                }
                const btn = document.getElementById('btnUpdateProduct');
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Saving...';
                }
            });
        }
    }

    // Modal: Edit Variant
    const editVariantModal = new bootstrap.Modal(document.getElementById('editVariantModal'));
    document.querySelectorAll('.edit-variant-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('edit_variant_id').value = this.getAttribute('data-id');
            document.getElementById('edit_v_sku').value = this.getAttribute('data-sku');
            document.getElementById('edit_v_size').value = this.getAttribute('data-size');
            document.getElementById('edit_v_color').value = this.getAttribute('data-color');
            const hex = this.getAttribute('data-color-hex') || '';
            document.getElementById('edit_v_color_hex').value = hex;
            if (hex && /^#[0-9a-fA-F]{6}$/.test(hex)) {
                document.getElementById('edit_v_color_picker').value = hex;
            }
            document.getElementById('edit_v_price_override').value = this.getAttribute('data-price-override');
            document.getElementById('edit_v_stock_qty').value = this.getAttribute('data-stock-qty');
            document.getElementById('edit_v_low_stock').value = this.getAttribute('data-low-stock');
            document.getElementById('edit_v_is_active').checked = this.getAttribute('data-is-active') === '1';

            editVariantModal.show();
        });
    });

    // Color pickers sync
    const addPicker = document.getElementById('add_color_picker');
    const addHex = document.getElementById('add_color_hex');
    if (addPicker && addHex) {
        addPicker.addEventListener('input', () => { addHex.value = addPicker.value; });
        addHex.addEventListener('input', () => {
            if (/^#[0-9a-fA-F]{6}$/.test(addHex.value)) addPicker.value = addHex.value;
        });
    }

    const editPicker = document.getElementById('edit_v_color_picker');
    const editHex = document.getElementById('edit_v_color_hex');
    if (editPicker && editHex) {
        editPicker.addEventListener('input', () => { editHex.value = editPicker.value; });
        editHex.addEventListener('input', () => {
            if (/^#[0-9a-fA-F]{6}$/.test(editHex.value)) editPicker.value = editHex.value;
        });
    }

    // Modal: Delete Variant
    const deleteVariantModal = new bootstrap.Modal(document.getElementById('deleteVariantModal'));
    document.querySelectorAll('.delete-variant-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('delete_variant_id').value = this.getAttribute('data-id');
            document.getElementById('delete_variant_sku').textContent = this.getAttribute('data-sku');
            deleteVariantModal.show();
        });
    });

    // Modal: Edit Image
    const editImageModal = new bootstrap.Modal(document.getElementById('editImageModal'));
    document.querySelectorAll('.edit-image-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('edit_image_id').value = this.getAttribute('data-id');
            document.getElementById('edit_image_alt').value = this.getAttribute('data-alt');
            document.getElementById('edit_image_color').value = this.getAttribute('data-color');
            editImageModal.show();
        });
    });
});
</script>
HTML;

require dirname(__DIR__, 3) . '/app/views/admin/footer.php';
