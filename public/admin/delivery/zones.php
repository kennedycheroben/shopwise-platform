<?php
require dirname(__DIR__, 3) . '/app/bootstrap.php';
require dirname(__DIR__, 3) . '/app/views/admin/guard.php';

use App\Services\DeliveryService;
use App\Services\Permissions;
use App\Services\AdminAuthService;

Permissions::require('delivery.manage');
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
            DeliveryService::createZone($_POST, $actorId, $ip);
            flash_set('success', 'Delivery zone created successfully.');
        } elseif ($action === 'update') {
            $zoneId = (int)($_POST['id'] ?? 0);
            DeliveryService::updateZone($zoneId, $_POST, $actorId, $ip);
            flash_set('success', 'Delivery zone updated successfully.');
        } elseif ($action === 'toggle') {
            $zoneId = (int)($_POST['id'] ?? 0);
            $newStatus = DeliveryService::toggleZoneActive($zoneId, $actorId, $ip);
            flash_set('success', 'Delivery zone ' . ($newStatus ? 'activated' : 'deactivated') . ' successfully.');
        } elseif ($action === 'delete') {
            $zoneId = (int)($_POST['id'] ?? 0);
            DeliveryService::deleteZone($zoneId, $actorId, $ip);
            flash_set('success', 'Delivery zone deleted successfully.');
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }

    redirect('admin/delivery/zones.php');
}

$filters = [
    'county' => $_GET['county'] ?? '',
    'search' => $_GET['search'] ?? '',
];
$zones = DeliveryService::listZones($filters);
$counties = DeliveryService::getCounties();

$pageTitle = 'Delivery Zones';
$activeGroup = 'delivery';

require dirname(__DIR__, 3) . '/app/views/admin/layout.php';
?>

<div class="main-content page-delivery-zones">
    <!-- Page Header -->
    <div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= e(url('admin/')) ?>">Dashboard</a></li>
                    <li class="breadcrumb-item">Delivery</li>
                    <li class="breadcrumb-item active" aria-current="page">Zones</li>
                </ol>
            </nav>
            <h1 class="page-title h3 mb-0">Delivery Zones &amp; Coverage</h1>
            <p class="text-muted small mb-0">Manage regional delivery flat fees and estimated transit times across Kenya.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= e(url('admin/delivery/pricing.php')) ?>" class="btn btn-outline-secondary">
                <i class="bi bi-sliders me-1"></i> Distance Pricing Settings
            </a>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createZoneModal">
                <i class="bi bi-plus-lg me-1"></i> Add New Zone
            </button>
        </div>
    </div>

    <!-- Stats Overview -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="flex-shrink-0 bg-primary-subtle text-primary rounded-3 p-3 me-3">
                        <i class="bi bi-geo-alt fs-3"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Total Zones</div>
                        <div class="fs-4 fw-bold"><?= count($zones) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="flex-shrink-0 bg-success-subtle text-success rounded-3 p-3 me-3">
                        <i class="bi bi-check-circle fs-3"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Active Zones</div>
                        <div class="fs-4 fw-bold">
                            <?= count(array_filter($zones, fn($z) => (int)$z['is_active'] === 1)) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="flex-shrink-0 bg-info-subtle text-info rounded-3 p-3 me-3">
                        <i class="bi bi-pin-map fs-3"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Counties Covered</div>
                        <div class="fs-4 fw-bold"><?= count($counties) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="flex-shrink-0 bg-warning-subtle text-warning rounded-3 p-3 me-3">
                        <i class="bi bi-truck fs-3"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Average Fee</div>
                        <div class="fs-4 fw-bold">
                            <?php 
                            $avgFee = count($zones) > 0 ? array_sum(array_column($zones, 'fee')) / count($zones) : 0;
                            echo 'KES ' . number_format($avgFee, 0);
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="<?= e(url('admin/delivery/zones.php')) ?>" class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small fw-semibold">Search County or Area</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" name="search" class="form-control" placeholder="e.g. Westlands, Mombasa, Kilimani..." value="<?= e($filters['search']) ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">County Filter</label>
                    <select name="county" class="form-select">
                        <option value="">All Counties</option>
                        <?php foreach ($counties as $c): ?>
                            <option value="<?= e($c) ?>" <?= $filters['county'] === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-grow-1">
                        <i class="bi bi-filter me-1"></i> Filter
                    </button>
                    <?php if (!empty($filters['search']) || !empty($filters['county'])): ?>
                        <a href="<?= e(url('admin/delivery/zones.php')) ?>" class="btn btn-outline-secondary" title="Clear Filters">
                            <i class="bi bi-x-circle"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Zones Table Card -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center py-3">
            <h5 class="card-title mb-0">Delivery Zones (<?= count($zones) ?>)</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th scope="col" style="width: 50px;">#</th>
                        <th scope="col">County</th>
                        <th scope="col">Area / Town</th>
                        <th scope="col" class="text-end">Standard Fee</th>
                        <th scope="col" class="text-center">Transit Estimate</th>
                        <th scope="col" class="text-center">Past Orders</th>
                        <th scope="col" class="text-center">Status</th>
                        <th scope="col" class="text-end" style="width: 140px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($zones)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="bi bi-geo fs-1 d-block mb-2 text-secondary"></i>
                                No delivery zones found matching your search.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($zones as $idx => $z): ?>
                            <tr>
                                <td class="text-muted small"><?= $idx + 1 ?></td>
                                <td>
                                    <span class="fw-semibold text-dark"><?= e($z['county']) ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border"><?= e($z['area']) ?></span>
                                </td>
                                <td class="text-end fw-bold text-primary">
                                    KES <?= number_format($z['fee'], 2) ?>
                                </td>
                                <td class="text-center">
                                    <span class="text-muted small">
                                        <i class="bi bi-clock me-1"></i>
                                        <?= (int)$z['est_days_min'] ?> - <?= (int)$z['est_days_max'] ?> business day(s)
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?php if ((int)$z['order_count'] > 0): ?>
                                        <span class="badge bg-secondary-subtle text-secondary" title="<?= (int)$z['order_count'] ?> order(s) placed for this zone">
                                            <?= (int)$z['order_count'] ?> orders
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted small">0</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ((int)$z['is_active'] === 1): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-light dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            Actions
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                            <li>
                                                <button type="button" class="dropdown-item edit-zone-btn"
                                                        data-id="<?= e($z['id']) ?>"
                                                        data-county="<?= e($z['county']) ?>"
                                                        data-area="<?= e($z['area']) ?>"
                                                        data-fee="<?= e($z['fee']) ?>"
                                                        data-min="<?= e($z['est_days_min']) ?>"
                                                        data-max="<?= e($z['est_days_max']) ?>"
                                                        data-active="<?= e($z['is_active']) ?>">
                                                    <i class="bi bi-pencil me-2 text-warning"></i> Edit Details
                                                </button>
                                            </li>
                                            <li>
                                                <form method="POST" action="<?= e(url('admin/delivery/zones.php')) ?>" onsubmit="return confirm('Change status for <?= e($z['area']) ?>?');">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="toggle">
                                                    <input type="hidden" name="id" value="<?= e($z['id']) ?>">
                                                    <button type="submit" class="dropdown-item">
                                                        <?php if ((int)$z['is_active'] === 1): ?>
                                                            <i class="bi bi-eye-slash me-2 text-secondary"></i> Deactivate
                                                        <?php else: ?>
                                                            <i class="bi bi-check-circle me-2 text-success"></i> Activate
                                                        <?php endif; ?>
                                                    </button>
                                                </form>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <?php if ((int)$z['order_count'] > 0): ?>
                                                    <button type="button" class="dropdown-item text-muted" disabled title="Referenced by past orders. Deactivate instead.">
                                                        <i class="bi bi-trash me-2"></i> Delete (In Use)
                                                    </button>
                                                <?php else: ?>
                                                    <form method="POST" action="<?= e(url('admin/delivery/zones.php')) ?>" onsubmit="return confirm('Permanently delete <?= e($z['county']) ?> - <?= e($z['area']) ?>?');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?= e($z['id']) ?>">
                                                        <button type="submit" class="dropdown-item text-danger">
                                                            <i class="bi bi-trash me-2"></i> Delete Zone
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </li>
                                        </ul>
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

<!-- Modal: Create Zone -->
<div class="modal fade" id="createZoneModal" tabindex="-1" aria-labelledby="createZoneModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="<?= e(url('admin/delivery/zones.php')) ?>" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="modal-header">
                <h5 class="modal-title" id="createZoneModalLabel"><i class="bi bi-plus-circle me-2 text-primary"></i>Add New Delivery Zone</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">County <span class="text-danger">*</span></label>
                    <input type="text" name="county" class="form-control" placeholder="e.g. Nairobi, Mombasa, Kisumu, Nakuru" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Area / Town / Neighborhood <span class="text-danger">*</span></label>
                    <input type="text" name="area" class="form-control" placeholder="e.g. Westlands, Nyali, Milimani, CBD" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Standard Delivery Fee (KES) <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text">KES</span>
                        <input type="number" step="0.01" min="0" name="fee" class="form-control" placeholder="150.00" required>
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Min Est. Days <span class="text-danger">*</span></label>
                        <input type="number" min="1" max="30" name="est_days_min" class="form-control" value="1" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Max Est. Days <span class="text-danger">*</span></label>
                        <input type="number" min="1" max="30" name="est_days_max" class="form-control" value="2" required>
                    </div>
                </div>
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="is_active" value="1" id="createActive" checked>
                    <label class="form-check-label" for="createActive">Active and available for checkout quotes</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Zone</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit Zone -->
<div class="modal fade" id="editZoneModal" tabindex="-1" aria-labelledby="editZoneModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="<?= e(url('admin/delivery/zones.php')) ?>" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="editZoneId">
            <div class="modal-header">
                <h5 class="modal-title" id="editZoneModalLabel"><i class="bi bi-pencil-square me-2 text-warning"></i>Edit Delivery Zone</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">County <span class="text-danger">*</span></label>
                    <input type="text" name="county" id="editCounty" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Area / Town / Neighborhood <span class="text-danger">*</span></label>
                    <input type="text" name="area" id="editArea" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Standard Delivery Fee (KES) <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text">KES</span>
                        <input type="number" step="0.01" min="0" name="fee" id="editFee" class="form-control" required>
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Min Est. Days <span class="text-danger">*</span></label>
                        <input type="number" min="1" max="30" name="est_days_min" id="editMinDays" class="form-control" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Max Est. Days <span class="text-danger">*</span></label>
                        <input type="number" min="1" max="30" name="est_days_max" id="editMaxDays" class="form-control" required>
                    </div>
                </div>
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="is_active" value="1" id="editActive">
                    <label class="form-check-label" for="editActive">Active and available for checkout quotes</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Update Zone</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const editBtns = document.querySelectorAll('.edit-zone-btn');
    const modalEl = document.getElementById('editZoneModal');
    if (modalEl && typeof bootstrap !== 'undefined') {
        const bsModal = new bootstrap.Modal(modalEl);
        editBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById('editZoneId').value = this.dataset.id;
                document.getElementById('editCounty').value = this.dataset.county;
                document.getElementById('editArea').value = this.dataset.area;
                document.getElementById('editFee').value = this.dataset.fee;
                document.getElementById('editMinDays').value = this.dataset.min;
                document.getElementById('editMaxDays').value = this.dataset.max;
                document.getElementById('editActive').checked = (this.dataset.active === '1');
                bsModal.show();
            });
        });
    }
});
</script>

<?php require dirname(__DIR__, 3) . '/app/views/admin/footer.php'; ?>
