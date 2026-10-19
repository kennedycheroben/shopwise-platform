<?php
require dirname(__DIR__, 3) . '/app/bootstrap.php';
require dirname(__DIR__, 3) . '/app/views/admin/guard.php';

use App\Services\InventoryService;
use App\Services\ProductService;
use App\Services\Permissions;

Permissions::require('inventory.adjust');

// Filters & Pagination
$filters = [
    'variant_id' => !empty($_GET['variant_id']) ? (int)$_GET['variant_id'] : null,
    'product_id' => !empty($_GET['product_id']) ? (int)$_GET['product_id'] : null,
    'reason'     => trim($_GET['reason'] ?? ''),
    'date_from'  => trim($_GET['date_from'] ?? ''),
    'date_to'    => trim($_GET['date_to'] ?? ''),
];

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$result = InventoryService::paginateMovements($filters, $page, $perPage);
$movements = $result['items'];
$totalItems = $result['total_items'];
$totalPages = $result['total_pages'];

$pageTitle = 'Stock Movements Log';

require dirname(__DIR__, 3) . '/app/views/admin/layout.php';
?>

<div class="main-content page-inventory-movements">
    <!-- Page Header -->
    <div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="page-title">Stock Movements Log</h1>
            <nav class="breadcrumb">
                <a href="<?= e(url('admin/')) ?>" class="breadcrumb-item">Dashboard</a>
                <a href="<?= e(url('admin/inventory/')) ?>" class="breadcrumb-item">Inventory</a>
                <span class="breadcrumb-item active">Movements</span>
            </nav>
        </div>
        <div>
            <a href="<?= e(url('admin/inventory/')) ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Back to Inventory
            </a>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="card mb-4">
        <div class="card-body py-3">
            <form action="<?= e(url('admin/inventory/movements.php')) ?>" method="GET" class="row g-2 align-items-center">
                <!-- Reason Filter -->
                <div class="col-6 col-md-3">
                    <label class="form-label small text-muted mb-1">Reason</label>
                    <select name="reason" class="form-select form-select-sm">
                        <option value="">All Movement Types</option>
                        <option value="restock" <?= $filters['reason'] === 'restock' ? 'selected' : '' ?>>Restock</option>
                        <option value="admin_adjustment" <?= $filters['reason'] === 'admin_adjustment' ? 'selected' : '' ?>>Admin Adjustment</option>
                        <option value="initial" <?= $filters['reason'] === 'initial' ? 'selected' : '' ?>>Initial Setup</option>
                        <option value="sale" <?= $filters['reason'] === 'sale' ? 'selected' : '' ?>>Customer Order (Sale)</option>
                        <option value="cancel_restore" <?= $filters['reason'] === 'cancel_restore' ? 'selected' : '' ?>>Cancellation Restore</option>
                        <option value="expiry_restore" <?= $filters['reason'] === 'expiry_restore' ? 'selected' : '' ?>>Expiry Restore</option>
                    </select>
                </div>

                <!-- Date From -->
                <div class="col-6 col-md-3">
                    <label class="form-label small text-muted mb-1">Date From</label>
                    <input type="date" name="date_from" class="form-control form-control-sm" value="<?= e($filters['date_from']) ?>">
                </div>

                <!-- Date To -->
                <div class="col-6 col-md-3">
                    <label class="form-label small text-muted mb-1">Date To</label>
                    <input type="date" name="date_to" class="form-control form-control-sm" value="<?= e($filters['date_to']) ?>">
                </div>

                <!-- Action Buttons -->
                <div class="col-12 col-md-3 d-flex gap-2 align-items-end pt-3">
                    <button type="submit" class="btn btn-primary btn-sm w-100">Filter Log</button>
                    <a href="<?= e(url('admin/inventory/movements.php')) ?>" class="btn btn-outline-secondary btn-sm" title="Reset Filters"><i class="bi bi-arrow-counterclockwise"></i></a>
                </div>
            </form>
        </div>
    </div>

    <!-- Movements Table Card -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <h5 class="card-title mb-0">Movement Audit Trail</h5>
                <span class="text-muted" style="font-size: 13px;">Showing <?= count($movements) ?> of <?= number_format($totalItems) ?> movements</span>
            </div>
        </div>

        <div class="card-body p-0">
            <?php if (empty($movements)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-journal-text text-muted" style="font-size: 3rem;"></i>
                    <h5 class="mt-3">No inventory movements recorded</h5>
                    <p class="text-muted">Stock changes, sales, and replenishments will appear here.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 160px;">Date & Time</th>
                                <th>Product / Variant</th>
                                <th>SKU</th>
                                <th class="text-center" style="width: 120px;">Change Qty</th>
                                <th>Movement Type</th>
                                <th>Operational Note</th>
                                <th>Actor</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($movements as $m): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold small text-dark"><?= e(date('d M Y, H:i', strtotime($m['created_at']))) ?></div>
                                        <small class="text-muted"><?= e(date('e', strtotime($m['created_at']))) ?></small>
                                    </td>
                                    <td>
                                        <div class="fw-semibold">
                                            <a href="<?= e(url('admin/products/edit.php?id=' . $m['product_id'] . '&tab=variants')) ?>" class="text-reset text-decoration-none">
                                                <?= e($m['product_name']) ?>
                                            </a>
                                        </div>
                                        <small class="text-muted">
                                            <?= !empty($m['size']) ? 'Size: ' . e($m['size']) : '' ?>
                                            <?= (!empty($m['size']) && !empty($m['color'])) ? ' · ' : '' ?>
                                            <?= !empty($m['color']) ? 'Color: ' . e($m['color']) : '' ?>
                                        </small>
                                    </td>
                                    <td>
                                        <code class="fw-bold text-dark" style="font-size: 12px;"><?= e($m['sku']) ?></code>
                                    </td>
                                    <td class="text-center">
                                        <?php $qty = (int)$m['change_qty']; ?>
                                        <?php if ($qty > 0): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 fs-6">
                                                +<?= $qty ?>
                                            </span>
                                        <?php elseif ($qty < 0): ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1 fs-6">
                                                <?= $qty ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-muted px-2 py-1 fs-6">
                                                0
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $badgeClass = match ($m['reason']) {
                                            'restock'          => 'bg-success text-white',
                                            'admin_adjustment' => 'bg-primary-subtle text-primary border border-primary-subtle',
                                            'initial'          => 'bg-info-subtle text-info-emphasis border border-info-subtle',
                                            'sale'             => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
                                            'cancel_restore', 'expiry_restore' => 'bg-secondary text-white',
                                            default            => 'bg-light text-dark border',
                                        };
                                        $reasonLabel = match ($m['reason']) {
                                            'restock'          => 'Restock',
                                            'admin_adjustment' => 'Adjustment',
                                            'initial'          => 'Initial Entry',
                                            'sale'             => 'Sale',
                                            'cancel_restore'   => 'Cancel Restore',
                                            'expiry_restore'   => 'Expiry Restore',
                                            default            => ucfirst($m['reason']),
                                        };
                                        ?>
                                        <span class="badge <?= $badgeClass ?>"><?= $reasonLabel ?></span>
                                    </td>
                                    <td>
                                        <span class="text-dark small"><?= e($m['note'] ?? '—') ?></span>
                                    </td>
                                    <td>
                                        <?php if (!empty($m['actor_name'])): ?>
                                            <span class="small fw-semibold text-dark"><?= e($m['actor_name']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted small">System</span>
                                        <?php endif; ?>
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
                        <nav aria-label="Movements pagination">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= e(url('admin/inventory/movements.php?' . http_build_query(array_merge($filters, ['page' => $page - 1])))) ?>">Previous</a>
                                </li>
                                <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= e(url('admin/inventory/movements.php?' . http_build_query(array_merge($filters, ['page' => $p])))) ?>"><?= $p ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= e(url('admin/inventory/movements.php?' . http_build_query(array_merge($filters, ['page' => $page + 1])))) ?>">Next</a>
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
