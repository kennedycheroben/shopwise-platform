<?php
require dirname(__DIR__, 3) . '/app/bootstrap.php';
require dirname(__DIR__, 3) . '/app/views/admin/guard.php';

use App\Services\CategoryService;
use App\Services\Permissions;
use App\Services\AdminAuthService;

Permissions::require('categories.manage');
$currentUser = AdminAuthService::user();

// Process POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['_csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Forbidden: Invalid CSRF token.');
    }

    $action = $_POST['action'] ?? '';
    $actorId = (int)$currentUser['id'];
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    try {
        if ($action === 'create') {
            CategoryService::create($_POST, $actorId, $ip);
            flash_set('success', 'Category created successfully.');
        } elseif ($action === 'update') {
            $catId = (int)($_POST['id'] ?? 0);
            CategoryService::update($catId, $_POST, $actorId, $ip);
            flash_set('success', 'Category updated successfully.');
        } elseif ($action === 'delete') {
            // Delete requires categories.delete permission (Admin only)
            Permissions::require('categories.delete');
            $catId = (int)($_POST['id'] ?? 0);
            CategoryService::delete($catId, $actorId, $ip);
            flash_set('success', 'Category deleted successfully.');
        } elseif ($action === 'toggle') {
            $catId = (int)($_POST['id'] ?? 0);
            $cat = CategoryService::find($catId);
            if ($cat) {
                CategoryService::update($catId, ['is_active' => $cat['is_active'] ? 0 : 1], $actorId, $ip);
                flash_set('success', 'Category status updated.');
            }
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }

    redirect('admin/categories/');
}

$categories = CategoryService::getAll();
$pageTitle = 'Categories';

require dirname(__DIR__, 3) . '/app/views/admin/layout.php';
?>

<div class="main-content page-categories">
    <!-- Page Header -->
    <div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="page-title">Category Management</h1>
            <nav class="breadcrumb">
                <a href="<?= e(url('admin/')) ?>" class="breadcrumb-item">Dashboard</a>
                <span class="breadcrumb-item">Catalog</span>
                <span class="breadcrumb-item active">Categories</span>
            </nav>
        </div>
        <div>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createCategoryModal">
                <i class="bi bi-plus-lg me-1"></i> Add Category
            </button>
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

    <!-- Categories Card -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <h5 class="card-title mb-0">Product Categories</h5>
                <p class="card-subtitle mb-0 text-muted" style="font-size: 13px;">Manage category hierarchy, display ordering, and visibility across the storefront.</p>
            </div>
            <span class="badge rounded-pill bg-light text-dark border"><?= count($categories) ?> total</span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($categories)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-folder2-open text-muted" style="font-size: 3rem;"></i>
                    <h5 class="mt-3">No categories found</h5>
                    <p class="text-muted">Get started by creating your first product category.</p>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createCategoryModal">
                        <i class="bi bi-plus-lg me-1"></i> Add Category
                    </button>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 50px;">Order</th>
                                <th>Category Name</th>
                                <th>URL Slug</th>
                                <th>Parent</th>
                                <th class="text-center" style="width: 110px;">Products</th>
                                <th class="text-center" style="width: 100px;">Status</th>
                                <th class="text-end" style="width: 140px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categories as $cat): ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-secondary-subtle text-secondary-emphasis"><?= (int)$cat['sort_order'] ?></span>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark">
                                            <?= e($cat['name']) ?>
                                        </div>
                                        <?php if (!empty($cat['description'])): ?>
                                            <small class="text-muted text-truncate d-inline-block" style="max-width: 320px;"><?= e($cat['description']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <code class="text-muted" style="font-size: 12px;"><?= e($cat['slug']) ?></code>
                                    </td>
                                    <td>
                                        <?php if (!empty($cat['parent_name'])): ?>
                                            <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">
                                                <i class="bi bi-arrow-return-right me-1"></i><?= e($cat['parent_name']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size: 12px;">Top Level</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge rounded-pill bg-light text-dark border"><?= (int)$cat['product_count'] ?></span>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($cat['is_active']): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-muted border border-secondary-subtle">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-1">
                                            <button type="button" 
                                                    class="btn btn-outline-secondary btn-sm edit-cat-btn"
                                                    data-id="<?= (int)$cat['id'] ?>"
                                                    data-name="<?= e($cat['name']) ?>"
                                                    data-slug="<?= e($cat['slug']) ?>"
                                                    data-parent-id="<?= (int)($cat['parent_id'] ?? 0) ?>"
                                                    data-description="<?= e($cat['description'] ?? '') ?>"
                                                    data-sort-order="<?= (int)$cat['sort_order'] ?>"
                                                    data-is-active="<?= (int)$cat['is_active'] ?>"
                                                    title="Edit Category">
                                                <i class="bi bi-pencil"></i>
                                            </button>

                                            <?php if (Permissions::can('categories.delete')): ?>
                                                <button type="button" 
                                                        class="btn btn-outline-danger btn-sm delete-cat-btn"
                                                        data-id="<?= (int)$cat['id'] ?>"
                                                        data-name="<?= e($cat['name']) ?>"
                                                        data-product-count="<?= (int)$cat['product_count'] ?>"
                                                        title="Delete Category">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Create Category Modal -->
<div class="modal fade" id="createCategoryModal" tabindex="-1" aria-labelledby="createCategoryModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form action="<?= e(url('admin/categories/')) ?>" method="POST" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="modal-header">
                <h5 class="modal-title" id="createCategoryModalLabel">Add New Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="create_name" class="form-label">Category Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="create_name" name="name" required placeholder="e.g. Traditional Fabrics">
                </div>
                <div class="mb-3">
                    <label for="create_slug" class="form-label">URL Slug</label>
                    <input type="text" class="form-control" id="create_slug" name="slug" placeholder="Auto-generated if left blank">
                    <div class="form-text">Used in website links (e.g. /category/traditional-fabrics).</div>
                </div>
                <div class="mb-3">
                    <label for="create_parent_id" class="form-label">Parent Category</label>
                    <select class="form-select" id="create_parent_id" name="parent_id">
                        <option value="">None (Top-Level Category)</option>
                        <?php foreach ($categories as $pCat): ?>
                            <option value="<?= (int)$pCat['id'] ?>"><?= e($pCat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label for="create_description" class="form-label">Description</label>
                    <textarea class="form-control" id="create_description" name="description" rows="3" placeholder="Brief summary of items in this category"></textarea>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="create_sort_order" class="form-label">Sort Order</label>
                        <input type="number" class="form-control" id="create_sort_order" name="sort_order" value="0">
                        <div class="form-text">Lower numbers appear first.</div>
                    </div>
                    <div class="col-md-6 mb-3 d-flex align-items-center pt-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="create_is_active" name="is_active" value="1" checked>
                            <label class="form-check-label" for="create_is_active">Active & Visible</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Category</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Category Modal -->
<div class="modal fade" id="editCategoryModal" tabindex="-1" aria-labelledby="editCategoryModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form action="<?= e(url('admin/categories/')) ?>" method="POST" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="edit_id">
            <div class="modal-header">
                <h5 class="modal-title" id="editCategoryModalLabel">Edit Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="edit_name" class="form-label">Category Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="edit_name" name="name" required>
                </div>
                <div class="mb-3">
                    <label for="edit_slug" class="form-label">URL Slug <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="edit_slug" name="slug" required>
                </div>
                <div class="mb-3">
                    <label for="edit_parent_id" class="form-label">Parent Category</label>
                    <select class="form-select" id="edit_parent_id" name="parent_id">
                        <option value="">None (Top-Level Category)</option>
                        <?php foreach ($categories as $pCat): ?>
                            <option value="<?= (int)$pCat['id'] ?>" class="parent-opt-<?= (int)$pCat['id'] ?>"><?= e($pCat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label for="edit_description" class="form-label">Description</label>
                    <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="edit_sort_order" class="form-label">Sort Order</label>
                        <input type="number" class="form-control" id="edit_sort_order" name="sort_order">
                    </div>
                    <div class="col-md-6 mb-3 d-flex align-items-center pt-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="edit_is_active" name="is_active" value="1">
                            <label class="form-check-label" for="edit_is_active">Active & Visible</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteCategoryModal" tabindex="-1" aria-labelledby="deleteCategoryModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form action="<?= e(url('admin/categories/')) ?>" method="POST" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="delete_id">
            <div class="modal-header">
                <h5 class="modal-title text-danger" id="deleteCategoryModalLabel">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i> Delete Category
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to permanently delete category <strong id="delete_name"></strong>?</p>
                <div class="alert alert-warning mb-0" style="font-size: 13px;">
                    <i class="bi bi-info-circle me-1"></i> Note: A category cannot be deleted if it contains active products or child subcategories.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger">Delete Category</button>
            </div>
        </form>
    </div>
</div>

<?php
$pageScripts = <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Edit category modal population
    const editModal = new bootstrap.Modal(document.getElementById('editCategoryModal'));
    document.querySelectorAll('.edit-cat-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const id = this.getAttribute('data-id');
            const name = this.getAttribute('data-name');
            const slug = this.getAttribute('data-slug');
            const parentId = this.getAttribute('data-parent-id');
            const desc = this.getAttribute('data-description');
            const sort = this.getAttribute('data-sort-order');
            const active = this.getAttribute('data-is-active') === '1';

            document.getElementById('edit_id').value = id;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_slug').value = slug;
            document.getElementById('edit_parent_id').value = parentId && parentId !== '0' ? parentId : '';
            document.getElementById('edit_description').value = desc;
            document.getElementById('edit_sort_order').value = sort;
            document.getElementById('edit_is_active').checked = active;

            // Prevent selecting itself as parent
            document.querySelectorAll('#edit_parent_id option').forEach(opt => {
                opt.disabled = (opt.value === id);
            });

            editModal.show();
        });
    });

    // Delete category modal
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteCategoryModal'));
    document.querySelectorAll('.delete-cat-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const id = this.getAttribute('data-id');
            const name = this.getAttribute('data-name');
            document.getElementById('delete_id').value = id;
            document.getElementById('delete_name').textContent = name;
            deleteModal.show();
        });
    });
});
</script>
HTML;

require dirname(__DIR__, 3) . '/app/views/admin/footer.php';
