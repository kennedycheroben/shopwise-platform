<?php
require dirname(__DIR__, 3) . '/app/bootstrap.php';
require dirname(__DIR__, 3) . '/app/views/admin/guard.php';

use App\Services\ProductService;
use App\Services\CategoryService;
use App\Services\Permissions;
use App\Services\AdminAuthService;

Permissions::require('products.edit');
$currentUser = AdminAuthService::user();

$errors = [];
$formData = [
    'name'              => '',
    'slug'              => '',
    'category_id'       => '',
    'short_description' => '',
    'description'       => '',
    'base_price'        => '',
    'compare_at_price'  => '',
    'meta_title'        => '',
    'meta_description'  => '',
    'is_active'         => '1',
    'is_featured'       => '0',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['_csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Forbidden: Invalid CSRF token.');
    }

    $formData = array_merge($formData, $_POST);
    $actorId = (int)$currentUser['id'];
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    $validation = ProductService::validate($_POST);
    if (!empty($validation['errors'])) {
        $errors = $validation['errors'];
    } else {
        try {
            $productId = ProductService::create($_POST, $actorId, $ip);
            flash_set('success', 'Product created successfully! A default variant was generated. You can now manage variants and upload photos below.');
            redirect('admin/products/edit.php?id=' . $productId);
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }
    }
}

$categories = CategoryService::getAll();
$pageTitle = 'Add New Product';

require dirname(__DIR__, 3) . '/app/views/admin/layout.php';
?>

<div class="main-content page-product-create">
    <!-- Page Header -->
    <div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="page-title">Add New Product</h1>
            <nav class="breadcrumb">
                <a href="<?= e(url('admin/')) ?>" class="breadcrumb-item">Dashboard</a>
                <a href="<?= e(url('admin/products/')) ?>" class="breadcrumb-item">Products</a>
                <span class="breadcrumb-item active">New Product</span>
            </nav>
        </div>
        <div>
            <a href="<?= e(url('admin/products/')) ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Back to Catalog
            </a>
        </div>
    </div>

    <?php $flashError = flash_get('error'); if ($flashError): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= e($flashError) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger" role="alert">
            <h6 class="alert-heading fw-bold mb-2"><i class="bi bi-exclamation-octagon-fill me-1"></i> Please correct the following errors:</h6>
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $field => $msg): ?>
                    <li><?= e($msg) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form action="<?= e(url('admin/products/create.php')) ?>" method="POST" id="productCreateForm" novalidate>
        <?= csrf_field() ?>

        <div class="row g-4">
            <!-- Left Column: Primary Product Information -->
            <div class="col-lg-8">
                <!-- General Info Card -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">General Information</h5>
                        <p class="card-subtitle text-muted mb-0" style="font-size: 13px;">Name, category assignment, and product descriptions.</p>
                    </div>
                    <div class="card-body">
                        <!-- Product Name -->
                        <div class="mb-3">
                            <label for="name" class="form-label fw-semibold">Product Name <span class="text-danger">*</span></label>
                            <input type="text" 
                                   class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>" 
                                   id="name" 
                                   name="name" 
                                   value="<?= e($formData['name']) ?>" 
                                   required 
                                   placeholder="e.g. Handmade Kenyan Leather Safari Boots">
                            <?php if (isset($errors['name'])): ?>
                                <div class="invalid-feedback"><?= e($errors['name']) ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- URL Slug -->
                        <div class="mb-3">
                            <label for="slug" class="form-label fw-semibold">URL Slug</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted" style="font-size: 13px;">/product/</span>
                                <input type="text" 
                                       class="form-control font-monospace <?= isset($errors['slug']) ? 'is-invalid' : '' ?>" 
                                       id="slug" 
                                       name="slug" 
                                       value="<?= e($formData['slug']) ?>" 
                                       placeholder="safari-boots-kenya">
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnAutoSlug">Auto</button>
                            </div>
                            <div class="form-text">Unique URL-friendly slug. Auto-generated from name if left empty.</div>
                            <?php if (isset($errors['slug'])): ?>
                                <div class="text-danger small mt-1"><?= e($errors['slug']) ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- Category -->
                        <div class="mb-3">
                            <label for="category_id" class="form-label fw-semibold">Category <span class="text-danger">*</span></label>
                            <select class="form-select <?= isset($errors['category_id']) ? 'is-invalid' : '' ?>" id="category_id" name="category_id" required>
                                <option value="">Select a category...</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= (int)$cat['id'] ?>" <?= ((string)$formData['category_id'] === (string)$cat['id']) ? 'selected' : '' ?>>
                                        <?= !empty($cat['parent_name']) ? e($cat['parent_name']) . ' &rsaquo; ' : '' ?><?= e($cat['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['category_id'])): ?>
                                <div class="invalid-feedback"><?= e($errors['category_id']) ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- Short Description -->
                        <div class="mb-3">
                            <label for="short_description" class="form-label fw-semibold">Short Summary / Excerpt <span class="text-danger">*</span></label>
                            <textarea class="form-control <?= isset($errors['short_description']) ? 'is-invalid' : '' ?>" 
                                      id="short_description" 
                                      name="short_description" 
                                      rows="2" 
                                      maxlength="500" 
                                      required 
                                      placeholder="Brief overview shown on catalog and search result cards (max 500 characters)"><?= e($formData['short_description']) ?></textarea>
                            <?php if (isset($errors['short_description'])): ?>
                                <div class="invalid-feedback"><?= e($errors['short_description']) ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- Long Description (Quill) -->
                        <div class="mb-0">
                            <label class="form-label fw-semibold">Full Product Description <span class="text-danger">*</span></label>
                            <div id="quillEditor" style="min-height: 220px; background: #fff;">
                                <?= $formData['description'] ?>
                            </div>
                            <input type="hidden" name="description" id="hiddenDescription" value="<?= e($formData['description']) ?>">
                            <div class="form-text">Provide detailed product specifications, materials, sizing advice, and care instructions.</div>
                            <?php if (isset($errors['description'])): ?>
                                <div class="text-danger small mt-1"><?= e($errors['description']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Search Engine Optimization (SEO) Card -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Search Engine Optimization (SEO)</h5>
                        <p class="card-subtitle text-muted mb-0" style="font-size: 13px;">Customize how this product appears on Google and social media previews.</p>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="meta_title" class="form-label fw-semibold">SEO Meta Title</label>
                            <input type="text" class="form-control" id="meta_title" name="meta_title" value="<?= e($formData['meta_title']) ?>" placeholder="Leave blank to use the product title">
                        </div>
                        <div class="mb-0">
                            <label for="meta_description" class="form-label fw-semibold">SEO Meta Description</label>
                            <textarea class="form-control" id="meta_description" name="meta_description" rows="2" maxlength="500" placeholder="Brief search preview summary"><?= e($formData['meta_description']) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Pricing & Visibility -->
            <div class="col-lg-4">
                <!-- Pricing Card -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Pricing (KES)</h5>
                        <p class="card-subtitle text-muted mb-0" style="font-size: 13px;">Prices are in Kenyan Shillings (KES).</p>
                    </div>
                    <div class="card-body">
                        <!-- Base Price -->
                        <div class="mb-3">
                            <label for="base_price" class="form-label fw-semibold">Base Price (KES) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">KES</span>
                                <input type="number" 
                                       step="0.01" 
                                       min="0.01" 
                                       class="form-control <?= isset($errors['base_price']) ? 'is-invalid' : '' ?>" 
                                       id="base_price" 
                                       name="base_price" 
                                       value="<?= e($formData['base_price']) ?>" 
                                       required 
                                       placeholder="2500.00">
                            </div>
                            <?php if (isset($errors['base_price'])): ?>
                                <div class="text-danger small mt-1"><?= e($errors['base_price']) ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- Compare At Price -->
                        <div class="mb-0">
                            <label for="compare_at_price" class="form-label fw-semibold">Compare-At Price (KES)</label>
                            <div class="input-group">
                                <span class="input-group-text">KES</span>
                                <input type="number" 
                                       step="0.01" 
                                       min="0.01" 
                                       class="form-control <?= isset($errors['compare_at_price']) ? 'is-invalid' : '' ?>" 
                                       id="compare_at_price" 
                                       name="compare_at_price" 
                                       value="<?= e($formData['compare_at_price']) ?>" 
                                       placeholder="3000.00">
                            </div>
                            <div class="form-text">Original price before discount. Must be strictly higher than the base price.</div>
                            <?php if (isset($errors['compare_at_price'])): ?>
                                <div class="text-danger small mt-1"><?= e($errors['compare_at_price']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Status & Organization Card -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Visibility & Status</h5>
                    </div>
                    <div class="card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?= $formData['is_active'] ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="is_active">Active & Available in Store</label>
                            <div class="form-text">When deactivated, customers cannot view or purchase this item.</div>
                        </div>

                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" id="is_featured" name="is_featured" value="1" <?= $formData['is_featured'] ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="is_featured">Featured Product</label>
                            <div class="form-text">Display on homepage highlights and featured collections.</div>
                        </div>
                    </div>
                </div>

                <!-- Submit Button Card -->
                <div class="card">
                    <div class="card-body">
                        <button type="submit" class="btn btn-primary w-100 mb-2" id="btnSubmit">
                            <i class="bi bi-check-lg me-1"></i> Create Product
                        </button>
                        <a href="<?= e(url('admin/products/')) ?>" class="btn btn-outline-secondary w-100">Cancel</a>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<?php
$pageScripts = <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Initialize Quill Editor
    if (typeof Quill !== 'undefined') {
        const quill = new Quill('#quillEditor', {
            theme: 'snow',
            placeholder: 'Enter rich product details, material specifications, size guides, and care instructions...',
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

        const form = document.getElementById('productCreateForm');
        form.addEventListener('submit', function () {
            const hiddenDesc = document.getElementById('hiddenDescription');
            // If quill has text, save innerHTML
            if (quill.getText().trim().length > 0) {
                hiddenDesc.value = quill.root.innerHTML;
            } else {
                hiddenDesc.value = '';
            }
            const submitBtn = document.getElementById('btnSubmit');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Saving...';
        });
    }

    // Auto slug helper
    const nameInput = document.getElementById('name');
    const slugInput = document.getElementById('slug');
    const btnAutoSlug = document.getElementById('btnAutoSlug');

    function generateSlug(text) {
        return text.toString().toLowerCase()
            .replace(/['’`]/g, '')
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    btnAutoSlug.addEventListener('click', function () {
        if (nameInput.value.trim() !== '') {
            slugInput.value = generateSlug(nameInput.value.trim());
        }
    });

    nameInput.addEventListener('blur', function () {
        if (slugInput.value.trim() === '' && nameInput.value.trim() !== '') {
            slugInput.value = generateSlug(nameInput.value.trim());
        }
    });
});
</script>
HTML;

require dirname(__DIR__, 3) . '/app/views/admin/footer.php';
