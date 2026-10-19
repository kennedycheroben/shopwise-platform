<?php
require dirname(__DIR__, 3) . '/app/bootstrap.php';
require dirname(__DIR__, 3) . '/app/views/admin/guard.php';

use App\Services\CouponService;
use App\Services\Permissions;
use App\Services\AdminAuthService;

Permissions::require('coupons.manage');
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
            CouponService::create($_POST, $actorId, $ip);
            flash_set('success', 'Coupon created successfully.');
        } elseif ($action === 'update') {
            $couponId = (int)($_POST['id'] ?? 0);
            CouponService::update($couponId, $_POST, $actorId, $ip);
            flash_set('success', 'Coupon updated successfully.');
        } elseif ($action === 'toggle') {
            $couponId = (int)($_POST['id'] ?? 0);
            $newStatus = CouponService::toggleActive($couponId, $actorId, $ip);
            flash_set('success', 'Coupon ' . ($newStatus ? 'activated' : 'deactivated') . ' successfully.');
        } elseif ($action === 'delete') {
            $couponId = (int)($_POST['id'] ?? 0);
            CouponService::delete($couponId, $actorId, $ip);
            flash_set('success', 'Coupon deleted successfully.');
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }

    redirect('admin/coupons/');
}

$filters = [
    'search' => $_GET['search'] ?? '',
    'type'   => $_GET['type'] ?? '',
    'status' => $_GET['status'] ?? '',
];
$coupons = CouponService::list($filters);

$pageTitle = 'Coupons & Discounts';
$activeGroup = 'marketing';

require dirname(__DIR__, 3) . '/app/views/admin/layout.php';
?>

<div class="main-content page-coupons">
    <!-- Page Header -->
    <div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= e(url('admin/')) ?>">Dashboard</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Coupons</li>
                </ol>
            </nav>
            <h1 class="page-title h3 mb-0">Coupons &amp; Discounts</h1>
            <p class="text-muted small mb-0">Manage customer promotional coupon codes, percentage discounts, and fixed vouchers.</p>
        </div>
        <div>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createCouponModal">
                <i class="bi bi-plus-lg me-1"></i> Create Coupon
            </button>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body p-3">
            <form method="GET" action="<?= e(url('admin/coupons/')) ?>" class="row g-2 align-items-center">
                <div class="col-md-5">
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                        <input type="text" name="search" class="form-control" placeholder="Search by coupon code..." value="<?= e($filters['search']) ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <select name="type" class="form-select">
                        <option value="">All Discount Types</option>
                        <option value="percent" <?= $filters['type'] === 'percent' ? 'selected' : '' ?>>Percentage (%)</option>
                        <option value="fixed" <?= $filters['type'] === 'fixed' ? 'selected' : '' ?>>Fixed Amount (KES)</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="status" class="form-select">
                        <option value="">All Statuses</option>
                        <option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="scheduled" <?= $filters['status'] === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                        <option value="expired" <?= $filters['status'] === 'expired' ? 'selected' : '' ?>>Expired</option>
                        <option value="exhausted" <?= $filters['status'] === 'exhausted' ? 'selected' : '' ?>>Exhausted</option>
                        <option value="inactive" <?= $filters['status'] === 'inactive' ? 'selected' : '' ?>>Disabled</option>
                    </select>
                </div>
                <div class="col-md-1 d-flex gap-1">
                    <button type="submit" class="btn btn-outline-primary w-100" title="Apply Filters"><i class="bi bi-funnel"></i></button>
                    <?php if (!empty($filters['search']) || !empty($filters['type']) || !empty($filters['status'])): ?>
                        <a href="<?= e(url('admin/coupons/')) ?>" class="btn btn-outline-secondary" title="Clear Filters"><i class="bi bi-x-lg"></i></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Coupons Table Card -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Coupon Code</th>
                            <th>Discount</th>
                            <th>Min. Spend</th>
                            <th>Usage / Limit</th>
                            <th>Remaining</th>
                            <th>Validity Window</th>
                            <th>Status</th>
                            <th class="text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($coupons)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">
                                    <i class="bi bi-ticket-perforated fs-2 d-block mb-2 text-muted"></i>
                                    No coupons found matching your filter criteria.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($coupons as $c): ?>
                                <tr>
                                    <td class="ps-3">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge bg-light text-dark border font-monospace fs-6 px-2 py-1"><?= e($c['code']) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($c['type'] === 'percent'): ?>
                                            <span class="fw-semibold text-primary"><?= (float)$c['value'] ?>% Off</span>
                                        <?php else: ?>
                                            <span class="fw-semibold text-success">KES <?= number_format($c['value'], 2) ?> Off</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((float)$c['min_spend'] > 0): ?>
                                            KES <?= number_format($c['min_spend'], 2) ?>
                                        <?php else: ?>
                                            <span class="text-muted">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="small">
                                            <strong><?= (int)$c['used_count'] ?></strong>
                                            <span class="text-muted">/ <?= $c['usage_limit'] !== null ? (int)$c['usage_limit'] : '&infin;' ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($c['remaining_uses'] !== null): ?>
                                            <span class="badge bg-light text-dark border"><?= $c['remaining_uses'] ?> left</span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted border">Unlimited</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="small">
                                            <div>From: <?= $c['starts_at'] ? date('d M Y, H:i', strtotime($c['starts_at'])) : '<span class="text-muted">Immediate</span>' ?></div>
                                            <div>Until: <?= $c['expires_at'] ? date('d M Y, H:i', strtotime($c['expires_at'])) : '<span class="text-muted">No expiry</span>' ?></div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php
                                        $statusClass = match ($c['status_computed']) {
                                            'active'    => 'bg-success',
                                            'scheduled' => 'bg-info',
                                            'expired'   => 'bg-danger',
                                            'exhausted' => 'bg-warning text-dark',
                                            default     => 'bg-secondary',
                                        };
                                        ?>
                                        <span class="badge <?= $statusClass ?>"><?= e(ucfirst($c['status_computed'])) ?></span>
                                    </td>
                                    <td class="text-end pe-3">
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                                Actions
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                                <li>
                                                    <button type="button" class="dropdown-item" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#editCouponModal<?= $c['id'] ?>">
                                                        <i class="bi bi-pencil me-2"></i> Edit Coupon
                                                    </button>
                                                </li>
                                                <li>
                                                    <form method="POST" action="<?= e(url('admin/coupons/')) ?>" class="d-inline">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="toggle">
                                                        <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                                        <button type="submit" class="dropdown-item">
                                                            <i class="bi bi-power me-2"></i> <?= $c['is_active'] ? 'Deactivate' : 'Activate' ?>
                                                        </button>
                                                    </form>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <?php if ((int)$c['redemption_count'] > 0): ?>
                                                        <button type="button" class="dropdown-item text-muted" disabled title="Coupons with redemptions cannot be deleted. Deactivate instead.">
                                                            <i class="bi bi-trash me-2"></i> Delete (<?= (int)$c['redemption_count'] ?> used)
                                                        </button>
                                                    <?php else: ?>
                                                        <button type="button" class="dropdown-item text-danger" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#deleteCouponModal<?= $c['id'] ?>">
                                                            <i class="bi bi-trash me-2"></i> Delete Coupon
                                                        </button>
                                                    <?php endif; ?>
                                                </li>
                                            </ul>
                                        </div>

                                        <!-- Edit Modal -->
                                        <div class="modal fade text-start" id="editCouponModal<?= $c['id'] ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <form method="POST" action="<?= e(url('admin/coupons/')) ?>">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="update">
                                                        <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Edit Coupon: <?= e($c['code']) ?></h5>
                                                            <button type="button" class="btn-close" data-bs-toggle="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <div class="mb-3">
                                                                <label class="form-label fw-semibold">Coupon Code</label>
                                                                <input type="text" name="code" class="form-control font-monospace" required pattern="[A-Za-z0-9-]{4,20}" value="<?= e($c['code']) ?>">
                                                                <div class="form-text">4 to 20 uppercase alphanumeric chars or hyphens.</div>
                                                            </div>
                                                            <div class="row g-2 mb-3">
                                                                <div class="col-md-6">
                                                                    <label class="form-label fw-semibold">Discount Type</label>
                                                                    <select name="type" class="form-select" required>
                                                                        <option value="percent" <?= $c['type'] === 'percent' ? 'selected' : '' ?>>Percentage (%)</option>
                                                                        <option value="fixed" <?= $c['type'] === 'fixed' ? 'selected' : '' ?>>Fixed Amount (KES)</option>
                                                                    </select>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <label class="form-label fw-semibold">Discount Value</label>
                                                                    <input type="number" step="0.01" name="value" class="form-control" required value="<?= (float)$c['value'] ?>">
                                                                </div>
                                                            </div>
                                                            <div class="row g-2 mb-3">
                                                                <div class="col-md-6">
                                                                    <label class="form-label fw-semibold">Minimum Spend (KES)</label>
                                                                    <input type="number" step="0.01" min="0" name="min_spend" class="form-control" value="<?= (float)$c['min_spend'] ?>">
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <label class="form-label fw-semibold">Usage Limit</label>
                                                                    <input type="number" min="1" name="usage_limit" class="form-control" placeholder="Leave empty for unlimited" value="<?= e($c['usage_limit'] ?? '') ?>">
                                                                </div>
                                                            </div>
                                                            <div class="row g-2 mb-3">
                                                                <div class="col-md-6">
                                                                    <label class="form-label fw-semibold">Start Date &amp; Time</label>
                                                                    <input type="datetime-local" name="starts_at" class="form-control" value="<?= $c['starts_at'] ? date('Y-m-d\TH:i', strtotime($c['starts_at'])) : '' ?>">
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <label class="form-label fw-semibold">Expiry Date &amp; Time</label>
                                                                    <input type="datetime-local" name="expires_at" class="form-control" value="<?= $c['expires_at'] ? date('Y-m-d\TH:i', strtotime($c['expires_at'])) : '' ?>">
                                                                </div>
                                                            </div>
                                                            <div class="form-check form-switch">
                                                                <input class="form-check-input" type="checkbox" name="is_active" value="1" id="editActive<?= $c['id'] ?>" <?= $c['is_active'] ? 'checked' : '' ?>>
                                                                <label class="form-check-label" for="editActive<?= $c['id'] ?>">Coupon Active</label>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-primary">Save Changes</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Delete Modal -->
                                        <div class="modal fade text-start" id="deleteCouponModal<?= $c['id'] ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <form method="POST" action="<?= e(url('admin/coupons/')) ?>">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title text-danger">Delete Coupon</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <p>Are you sure you want to permanently delete coupon <strong><?= e($c['code']) ?></strong>?</p>
                                                            <p class="text-muted small mb-0">This action cannot be undone. Only coupons with zero customer redemptions can be deleted.</p>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-danger">Confirm Delete</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
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

<!-- Create Coupon Modal -->
<div class="modal fade" id="createCouponModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= e(url('admin/coupons/')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Coupon</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Coupon Code <span class="text-danger">*</span></label>
                        <input type="text" name="code" class="form-control font-monospace" required pattern="[A-Za-z0-9-]{4,20}" placeholder="e.g. SHOPWISE20" style="text-transform: uppercase;">
                        <div class="form-text">4 to 20 uppercase alphanumeric characters or hyphens.</div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Discount Type <span class="text-danger">*</span></label>
                            <select name="type" class="form-select" required>
                                <option value="percent">Percentage (%)</option>
                                <option value="fixed">Fixed Amount (KES)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Discount Value <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="value" class="form-control" required placeholder="e.g. 10 or 250">
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Minimum Spend (KES)</label>
                            <input type="number" step="0.01" min="0" name="min_spend" class="form-control" value="0.00">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Usage Limit</label>
                            <input type="number" min="1" name="usage_limit" class="form-control" placeholder="Blank for unlimited">
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Start Date &amp; Time</label>
                            <input type="datetime-local" name="starts_at" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Expiry Date &amp; Time</label>
                            <input type="datetime-local" name="expires_at" class="form-control">
                        </div>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="createActive" checked>
                        <label class="form-check-label" for="createActive">Activate Immediately</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Coupon</button>
                </div>
            </form>
        </div>
    </div>
</div>
