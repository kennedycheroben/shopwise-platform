<?php
require dirname(__DIR__, 3) . '/app/bootstrap.php';
require dirname(__DIR__, 3) . '/app/views/admin/guard.php';

use App\Services\InventoryService;
use App\Services\Permissions;
use App\Services\AdminAuthService;

Permissions::require('inventory.adjust');
$currentUser = AdminAuthService::user();

// Handle quick stock adjustment POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $wantsJson = (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
        || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

    if (!csrf_verify($_POST['_csrf_token'] ?? null)) {
        if ($wantsJson) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Forbidden: Invalid CSRF token.']);
            exit;
        }
        http_response_code(403);
        exit('Forbidden: Invalid CSRF token.');
    }

    $variantId = (int)($_POST['variant_id'] ?? 0);
    $mode = $_POST['mode'] ?? 'delta'; // 'delta' or 'set'
    $value = (int)($_POST['value'] ?? 0);
    $reason = $_POST['reason'] ?? 'restock';
    $note = $_POST['note'] ?? '';
    $actorId = (int)$currentUser['id'];
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    try {
        $res = InventoryService::adjustStock($variantId, $mode, $value, $reason, $note, $actorId, $ip);
        if ($wantsJson) {
            header('Content-Type: application/json');
            echo json_encode([
                'success'    => true,
                'new_stock'  => $res['new_stock'],
                'change_qty' => $res['change_qty'],
                'message'    => "Stock updated successfully. New quantity: {$res['new_stock']}."
            ]);
            exit;
        }
        flash_set('success', "Stock updated successfully. New quantity: {$res['new_stock']}.");
    } catch (\Throwable $e) {
        if ($wantsJson) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error'   => $e->getMessage(),
                'message' => $e->getMessage()
            ]);
            exit;
        }
        flash_set('error', $e->getMessage());
    }

    $query = $_POST['redirect_query'] ?? '';
    redirect('admin/inventory/' . ($query !== '' ? '?' . ltrim($query, '?') : ''));
}

// Filters & Pagination
$filters = [
    'search'       => trim($_GET['search'] ?? ''),
    'stock_status' => trim($_GET['stock_status'] ?? ''),
    'sort'         => trim($_GET['sort'] ?? 'product_name'),
    'order'        => trim($_GET['order'] ?? 'asc'),
];

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$metrics = InventoryService::getSummaryMetrics();
$result = InventoryService::paginateVariants($filters, $page, $perPage);
$variants = $result['items'];
$totalItems = $result['total_items'];
$totalPages = $result['total_pages'];

$currentQuery = http_build_query(array_filter($_GET));
$pageTitle = 'Inventory Management';

require dirname(__DIR__, 3) . '/app/views/admin/layout.php';
?>

<div class="main-content page-inventory">
    <!-- Page Header -->
    <div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="page-title">Inventory Management</h1>
            <nav class="breadcrumb">
                <a href="<?= e(url('admin/')) ?>" class="breadcrumb-item">Dashboard</a>
                <span class="breadcrumb-item">Catalog</span>
                <span class="breadcrumb-item active">Inventory</span>
            </nav>
        </div>
        <div>
            <a href="<?= e(url('admin/inventory/movements.php')) ?>" class="btn btn-outline-primary">
                <i class="bi bi-clock-history me-1"></i> Stock Movements Log
            </a>
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
            <i class="bi bi-exclamation-octagon-fill me-2"></i> <?= e($flashError) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Summary Metrics Cards -->
    <div class="row g-3 mb-4">
        <!-- Total Stock Units -->
        <div class="col-12 col-sm-6 col-xl-4">
            <div class="card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div style="width: 50px; height: 50px; border-radius: var(--radius-sm); background: color-mix(in srgb, var(--accent-color), transparent 88%); color: var(--accent-color); display: flex; align-items: center; justify-content: center; font-size: 24px;">
                        <i class="bi bi-box-seam"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Total Units in Stock</div>
                        <h3 class="fw-bold mb-0"><?= number_format($metrics['total_units']) ?></h3>
                        <small class="text-muted"><?= number_format($metrics['total_variants']) ?> active variants tracked</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Low Stock Items -->
        <div class="col-12 col-sm-6 col-xl-4">
            <div class="card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div style="width: 50px; height: 50px; border-radius: var(--radius-sm); background: var(--warning-color-light); color: var(--warning-color); display: flex; align-items: center; justify-content: center; font-size: 24px;">
                        <i class="bi bi-exclamation-triangle"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Low Stock Warnings</div>
                        <h3 class="fw-bold mb-0 text-warning"><?= number_format($metrics['low_stock_count']) ?></h3>
                        <small class="text-muted">At or below reorder threshold</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Out of Stock Items -->
        <div class="col-12 col-sm-6 col-xl-4">
            <div class="card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div style="width: 50px; height: 50px; border-radius: var(--radius-sm); background: var(--danger-color-light); color: var(--danger-color); display: flex; align-items: center; justify-content: center; font-size: 24px;">
                        <i class="bi bi-x-circle"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Out of Stock Variants</div>
                        <h3 class="fw-bold mb-0 text-danger"><?= number_format($metrics['out_of_stock_count']) ?></h3>
                        <small class="text-muted">Zero units remaining</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Bar Card -->
    <div class="card mb-4">
        <div class="card-body py-3">
            <form action="<?= e(url('admin/inventory/')) ?>" method="GET" class="row g-2 align-items-center">
                <div class="col-12 col-md-6">
                    <div class="input-group">
                        <span class="input-group-text bg-transparent text-muted"><i class="bi bi-search"></i></span>
                        <input type="text" name="search" class="form-control" placeholder="Search by SKU or product name..." value="<?= e($filters['search']) ?>">
                    </div>
                </div>
                <div class="col-6 col-md-4">
                    <select name="stock_status" class="form-select">
                        <option value="" <?= empty($filters['stock_status']) ? 'selected' : '' ?>>All Stock Levels</option>
                        <option value="low_stock" <?= $filters['stock_status'] === 'low_stock' ? 'selected' : '' ?>>Low Stock (1-5)</option>
                        <option value="out_of_stock" <?= $filters['stock_status'] === 'out_of_stock' ? 'selected' : '' ?>>Out of Stock (0)</option>
                        <option value="in_stock" <?= $filters['stock_status'] === 'in_stock' ? 'selected' : '' ?>>Healthy Stock (>5)</option>
                    </select>
                </div>
                <div class="col-6 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100">Filter</button>
                    <a href="<?= e(url('admin/inventory/')) ?>" class="btn btn-outline-secondary" title="Reset Filters"><i class="bi bi-arrow-counterclockwise"></i></a>
                </div>
            </form>
        </div>
    </div>

    <!-- Variants Stock Table Card -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <h5 class="card-title mb-0">Stock Levels by Variant</h5>
                <span class="text-muted" style="font-size: 13px;">Showing <?= count($variants) ?> of <?= number_format($totalItems) ?> variants</span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="text-muted small">Sort:</span>
                <div class="btn-group btn-group-sm" role="group">
                    <a href="<?= e(url('admin/inventory/?' . http_build_query(array_merge($filters, ['sort' => 'product_name', 'order' => ($filters['sort'] === 'product_name' && $filters['order'] === 'asc' ? 'desc' : 'asc')])))) ?>" 
                       class="btn btn-outline-secondary <?= $filters['sort'] === 'product_name' ? 'active' : '' ?>">Product</a>
                    <a href="<?= e(url('admin/inventory/?' . http_build_query(array_merge($filters, ['sort' => 'sku', 'order' => ($filters['sort'] === 'sku' && $filters['order'] === 'asc' ? 'desc' : 'asc')])))) ?>" 
                       class="btn btn-outline-secondary <?= $filters['sort'] === 'sku' ? 'active' : '' ?>">SKU</a>
                    <a href="<?= e(url('admin/inventory/?' . http_build_query(array_merge($filters, ['sort' => 'stock', 'order' => ($filters['sort'] === 'stock' && $filters['order'] === 'asc' ? 'desc' : 'asc')])))) ?>" 
                       class="btn btn-outline-secondary <?= $filters['sort'] === 'stock' ? 'active' : '' ?>">Stock</a>
                </div>
            </div>
        </div>

        <div class="card-body p-0">
            <?php if (empty($variants)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-boxes text-muted" style="font-size: 3rem;"></i>
                    <h5 class="mt-3">No inventory items matched your filter</h5>
                    <p class="text-muted">Try clearing the search filters.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 50px;">Image</th>
                                <th>Product Name</th>
                                <th>SKU</th>
                                <th>Size & Color</th>
                                <th class="text-center">Stock</th>
                                <th class="text-center">Threshold</th>
                                <th class="text-center">Status</th>
                                <th class="text-end" style="width: 140px;">Quick Adjust</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($variants as $v): ?>
                                <tr>
                                    <td>
                                        <div style="width: 38px; height: 38px; border-radius: var(--radius-sm); overflow: hidden; background: var(--background-color); border: 1px solid var(--border-color); display:flex; align-items:center; justify-content:center;">
                                            <img src="<?= e(product_image_url($v['primary_image'])) ?>" alt="<?= e($v['product_name']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold">
                                            <a href="<?= e(url('admin/products/edit.php?id=' . $v['product_id'])) ?>" class="text-reset text-decoration-none">
                                                <?= e($v['product_name']) ?>
                                            </a>
                                        </div>
                                        <small class="text-muted"><?= e($v['category_name'] ?? 'General') ?></small>
                                    </td>
                                    <td>
                                        <code class="fw-bold text-dark" style="font-size: 13px;"><?= e($v['sku']) ?></code>
                                    </td>
                                    <td>
                                        <div class="d-inline-flex align-items-center gap-1">
                                            <?php if (!empty($v['size'])): ?>
                                                <span class="badge bg-light text-dark border"><?= e($v['size']) ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($v['color'])): ?>
                                                <span class="d-inline-flex align-items-center gap-1 small ms-1">
                                                    <?php if (!empty($v['color_hex'])): ?>
                                                        <span style="display:inline-block; width:12px; height:12px; border-radius:50%; background:<?= e($v['color_hex']) ?>; border:1px solid rgba(0,0,0,0.2);"></span>
                                                    <?php endif; ?>
                                                    <?= e($v['color']) ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if (empty($v['size']) && empty($v['color'])): ?>
                                                <span class="text-muted small">Default</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <?php $stk = (int)$v['stock_qty']; ?>
                                        <?php if ($stk === 0): ?>
                                            <span class="badge bg-danger text-white px-2 py-1">0 Out of Stock</span>
                                        <?php elseif ($stk <= (int)$v['low_stock_threshold']): ?>
                                            <span class="badge bg-warning text-dark px-2 py-1"><?= $stk ?> Low Stock</span>
                                        <?php else: ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1"><?= $stk ?></span>
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
                                        <button type="button" 
                                                class="btn btn-outline-primary btn-sm quick-adjust-btn"
                                                data-id="<?= (int)$v['id'] ?>"
                                                data-sku="<?= e($v['sku']) ?>"
                                                data-name="<?= e($v['product_name']) ?>"
                                                data-stock="<?= (int)$v['stock_qty'] ?>"
                                                data-size-color="<?= e((!empty($v['size']) ? $v['size'] : 'Standard') . ' / ' . (!empty($v['color']) ? $v['color'] : 'Standard')) ?>"
                                                title="Quick Adjust Stock">
                                            <i class="bi bi-sliders me-1"></i> Adjust
                                        </button>
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
                        <nav aria-label="Inventory pagination">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= e(url('admin/inventory/?' . http_build_query(array_merge($filters, ['page' => $page - 1])))) ?>">Previous</a>
                                </li>
                                <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= e(url('admin/inventory/?' . http_build_query(array_merge($filters, ['page' => $p])))) ?>"><?= $p ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= e(url('admin/inventory/?' . http_build_query(array_merge($filters, ['page' => $page + 1])))) ?>">Next</a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Quick Adjust Stock Modal -->
<div class="modal fade" id="quickAdjustModal" tabindex="-1" aria-labelledby="quickAdjustModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form action="<?= e(url('admin/inventory/')) ?>" method="POST" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="variant_id" id="modal_variant_id">
            <input type="hidden" name="redirect_query" value="<?= e($currentQuery) ?>">

            <div class="modal-header">
                <h5 class="modal-title" id="quickAdjustModalLabel">Quick Stock Adjustment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="card bg-light border p-3 mb-3">
                    <div class="fw-semibold text-dark" id="modal_product_name"></div>
                    <div class="d-flex justify-content-between align-items-center mt-1">
                        <small class="text-muted">SKU: <code id="modal_sku" class="text-dark"></code></small>
                        <small class="text-muted" id="modal_size_color"></small>
                    </div>
                    <div class="mt-2 pt-2 border-top d-flex justify-content-between align-items-center">
                        <span class="small text-muted">Current Stock:</span>
                        <span class="badge bg-primary fs-6" id="modal_current_stock"></span>
                    </div>
                </div>

                <!-- Adjustment Mode Radio -->
                <div class="mb-3">
                    <label class="form-label fw-semibold">Adjustment Type</label>
                    <div class="d-flex gap-4">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="mode" id="mode_delta" value="delta" checked>
                            <label class="form-check-label" for="mode_delta">Add / Deduct (+/- change)</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="mode" id="mode_set" value="set">
                            <label class="form-check-label" for="mode_set">Set Exact Count</label>
                        </div>
                    </div>
                </div>

                <!-- Quantity Value -->
                <div class="mb-3">
                    <label for="modal_value" class="form-label fw-semibold" id="modal_value_label">Change Quantity (e.g. +10 or -3) <span class="text-danger">*</span></label>
                    <input type="number" class="form-control" id="modal_value" name="value" required placeholder="+10 or -2">
                    <div class="form-text" id="modal_value_help">Enter positive number to add stock, or negative number to remove units.</div>
                </div>

                <!-- Reason Selection -->
                <div class="mb-3">
                    <label for="modal_reason" class="form-label fw-semibold">Reason for Adjustment <span class="text-danger">*</span></label>
                    <select class="form-select" id="modal_reason" name="reason" required>
                        <option value="restock">Restock / Shipment Arrival</option>
                        <option value="correction">Inventory Correction (Count reconciliation)</option>
                        <option value="damaged">Damaged / Expired Goods</option>
                        <option value="returned">Customer Return / Exchange</option>
                        <option value="other">Other Operational Adjustment</option>
                    </select>
                </div>

                <!-- Note / Explanation -->
                <div class="mb-0">
                    <label for="modal_note" class="form-label fw-semibold" id="modal_note_label">Operational Note</label>
                    <textarea class="form-control" id="modal_note" name="note" rows="2" placeholder="Required when reason is Correction or Other"></textarea>
                    <div class="form-text" id="modal_note_help">A clear explanation is required for audit compliance.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSubmitAdjust">Apply Stock Adjustment</button>
            </div>
        </form>
    </div>
</div>

<?php
$pageScripts = <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function () {
    const adjustModal = new bootstrap.Modal(document.getElementById('quickAdjustModal'));
    const modeDelta = document.getElementById('mode_delta');
    const modeSet = document.getElementById('mode_set');
    const valueLabel = document.getElementById('modal_value_label');
    const valueInput = document.getElementById('modal_value');
    const valueHelp = document.getElementById('modal_value_help');
    const reasonSelect = document.getElementById('modal_reason');
    const noteInput = document.getElementById('modal_note');
    const noteLabel = document.getElementById('modal_note_label');

    function updateModeUI() {
        if (modeSet.checked) {
            valueLabel.innerHTML = 'New Exact Stock Level <span class="text-danger">*</span>';
            valueInput.placeholder = 'e.g. 50';
            valueInput.min = 0;
            valueHelp.textContent = 'Enter the verified physical stock count (must be 0 or greater).';
        } else {
            valueLabel.innerHTML = 'Change Quantity (+/- units) <span class="text-danger">*</span>';
            valueInput.placeholder = 'e.g. +10 or -3';
            valueInput.removeAttribute('min');
            valueHelp.textContent = 'Enter positive number to add stock, or negative number to remove units.';
        }
    }

    function updateNoteUI() {
        const r = reasonSelect.value;
        if (r === 'correction' || r === 'other') {
            noteLabel.innerHTML = 'Operational Note <span class="text-danger">*</span>';
            noteInput.required = true;
        } else {
            noteLabel.innerHTML = 'Operational Note';
            noteInput.required = false;
        }
    }

    modeDelta.addEventListener('change', updateModeUI);
    modeSet.addEventListener('change', updateModeUI);
    reasonSelect.addEventListener('change', updateNoteUI);

    document.querySelectorAll('.quick-adjust-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('modal_variant_id').value = this.getAttribute('data-id');
            document.getElementById('modal_product_name').textContent = this.getAttribute('data-name');
            document.getElementById('modal_sku').textContent = this.getAttribute('data-sku');
            document.getElementById('modal_size_color').textContent = this.getAttribute('data-size-color');
            document.getElementById('modal_current_stock').textContent = this.getAttribute('data-stock') + ' units';
            valueInput.value = '';
            noteInput.value = '';
            modeDelta.checked = true;
            reasonSelect.value = 'restock';
            updateModeUI();
            updateNoteUI();
            adjustModal.show();
        });
    });
});
</script>
HTML;

require dirname(__DIR__, 3) . '/app/views/admin/footer.php';
