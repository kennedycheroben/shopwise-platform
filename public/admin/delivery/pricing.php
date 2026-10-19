<?php
require dirname(__DIR__, 3) . '/app/bootstrap.php';
require dirname(__DIR__, 3) . '/app/views/admin/guard.php';

use App\Services\DeliveryService;
use App\Services\SettingService;
use App\Services\Permissions;
use App\Services\AdminAuthService;

Permissions::require('delivery.manage');
$currentUser = AdminAuthService::user();

$quoteResult = null;
$quoteError = null;

// Handle POST actions: Save Settings OR Quote Tester
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['_csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Forbidden: Invalid CSRF token.');
    }

    $action = $_POST['action'] ?? '';
    $actorId = (int)$currentUser['id'];
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    if ($action === 'save_pricing') {
        try {
            SettingService::updateDeliveryPricing($_POST, $actorId, $ip);
            flash_set('success', 'Delivery pricing parameters updated successfully.');
            redirect('admin/delivery/pricing.php');
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
            redirect('admin/delivery/pricing.php');
        }
    } elseif ($action === 'test_quote') {
        try {
            $method = trim($_POST['test_method'] ?? 'distance');
            $subtotal = (float)($_POST['test_subtotal'] ?? 0);
            $zoneId = !empty($_POST['test_zone_id']) ? (int)$_POST['test_zone_id'] : null;
            $lat = isset($_POST['test_lat']) && $_POST['test_lat'] !== '' ? (float)$_POST['test_lat'] : null;
            $lng = isset($_POST['test_lng']) && $_POST['test_lng'] !== '' ? (float)$_POST['test_lng'] : null;

            $quoteResult = DeliveryService::quote($method, $subtotal, $zoneId, $lat, $lng);
        } catch (\Throwable $e) {
            $quoteError = $e->getMessage();
        }
    }
}

$pricing = DeliveryService::getDeliverySettings();
$zones = DeliveryService::listZones();

$pageTitle = 'Delivery Pricing & Formula';
$activeGroup = 'delivery';

require dirname(__DIR__, 3) . '/app/views/admin/layout.php';
?>

<div class="main-content page-delivery-pricing">
    <!-- Page Header -->
    <div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= e(url('admin/')) ?>">Dashboard</a></li>
                    <li class="breadcrumb-item">Delivery</li>
                    <li class="breadcrumb-item active" aria-current="page">Pricing &amp; Formula</li>
                </ol>
            </nav>
            <h1 class="page-title h3 mb-0">Delivery Distance Pricing &amp; Policy</h1>
            <p class="text-muted small mb-0">Configure store coordinates, Haversine distance coefficients, minimum/maximum fee bounds, and free delivery threshold.</p>
        </div>
        <div>
            <a href="<?= e(url('admin/delivery/zones.php')) ?>" class="btn btn-outline-secondary">
                <i class="bi bi-geo-alt me-1"></i> Regional Zones (<?= count($zones) ?>)
            </a>
        </div>
    </div>

    <div class="row g-4">
        <!-- Main Pricing Configuration Form -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent border-0 pt-4 pb-2 px-4">
                    <h5 class="card-title mb-1"><i class="bi bi-calculator me-2 text-primary"></i>Distance Pricing Parameters</h5>
                    <p class="text-muted small mb-0">Formula: <code>Fee = base_fee + per_km &times; max(0, distance &times; road_factor - included_km)</code>, clamped between min and max fee.</p>
                </div>
                <div class="card-body px-4 pb-4">
                    <form method="POST" action="<?= e(url('admin/delivery/pricing.php')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_pricing">

                        <h6 class="text-uppercase text-secondary fs-7 fw-bold mb-3 border-bottom pb-2">1. Store Base Location (Kenya Coordinates)</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Store Latitude <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-compass"></i></span>
                                    <input type="number" step="0.000001" min="-4.8" max="5.1" name="store_latitude" class="form-control" value="<?= e($pricing['store_latitude']) ?>" required>
                                </div>
                                <div class="form-text small">Kenya range: -4.800000 to 5.100000</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Store Longitude <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-compass"></i></span>
                                    <input type="number" step="0.000001" min="33.9" max="41.9" name="store_longitude" class="form-control" value="<?= e($pricing['store_longitude']) ?>" required>
                                </div>
                                <div class="form-text small">Kenya range: 33.900000 to 41.900000</div>
                            </div>
                        </div>

                        <h6 class="text-uppercase text-secondary fs-7 fw-bold mb-3 border-bottom pb-2">2. Distance Pricing Constants</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Base Dispatch Fee <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">KES</span>
                                    <input type="number" step="0.01" min="0" name="distance_base_fee" class="form-control" value="<?= e($pricing['distance_base_fee']) ?>" required>
                                </div>
                                <div class="form-text small">Starting dispatch rate</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Rate Per Kilometer <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">KES/km</span>
                                    <input type="number" step="0.01" min="0" name="distance_per_km" class="form-control" value="<?= e($pricing['distance_per_km']) ?>" required>
                                </div>
                                <div class="form-text small">Charge per excess road km</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Included Distance <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="number" step="0.1" min="0" name="distance_included_km" class="form-control" value="<?= e($pricing['distance_included_km']) ?>" required>
                                    <span class="input-group-text">km</span>
                                </div>
                                <div class="form-text small">Kilometers covered by base fee</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Road Winding Factor <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">&times;</span>
                                    <input type="number" step="0.05" min="1.0" max="3.0" name="distance_road_factor" class="form-control" value="<?= e($pricing['distance_road_factor']) ?>" required>
                                </div>
                                <div class="form-text small">1.0 to 3.0 (default 1.30 for Nairobi roads)</div>
                            </div>
                        </div>

                        <h6 class="text-uppercase text-secondary fs-7 fw-bold mb-3 border-bottom pb-2">3. Bounds &amp; Rounding Rules</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Minimum Fee <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">KES</span>
                                    <input type="number" step="0.01" min="0" name="distance_min_fee" class="form-control" value="<?= e($pricing['distance_min_fee']) ?>" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Maximum Fee <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">KES</span>
                                    <input type="number" step="0.01" min="0" name="distance_max_fee" class="form-control" value="<?= e($pricing['distance_max_fee']) ?>" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Rounding Step <span class="text-danger">*</span></label>
                                <select name="distance_rounding" class="form-select" required>
                                    <option value="1" <?= (int)$pricing['distance_rounding'] === 1 ? 'selected' : '' ?>>KES 1 (Exact)</option>
                                    <option value="5" <?= (int)$pricing['distance_rounding'] === 5 ? 'selected' : '' ?>>KES 5</option>
                                    <option value="10" <?= (int)$pricing['distance_rounding'] === 10 ? 'selected' : '' ?>>KES 10 (Recommended)</option>
                                    <option value="50" <?= (int)$pricing['distance_rounding'] === 50 ? 'selected' : '' ?>>KES 50</option>
                                </select>
                            </div>
                        </div>

                        <h6 class="text-uppercase text-secondary fs-7 fw-bold mb-3 border-bottom pb-2">4. Thresholds &amp; Windows</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Free Delivery Threshold</label>
                                <div class="input-group">
                                    <span class="input-group-text">KES</span>
                                    <input type="number" step="0.01" min="0" name="free_delivery_threshold" class="form-control" value="<?= e($pricing['free_delivery_threshold']) ?>" required>
                                </div>
                                <div class="form-text small">Orders &ge; this amount receive free delivery (0 = disabled).</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Unpaid Order Expiry Window <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="number" min="5" max="1440" name="order_expiry_minutes" class="form-control" value="<?= e($pricing['order_expiry_minutes']) ?>" required>
                                    <span class="input-group-text">minutes</span>
                                </div>
                                <div class="form-text small">5 to 1440 minutes before pending order cancellation.</div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="bi bi-save me-1"></i> Save Pricing Settings
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Quote Tester & Plain Language Guide -->
        <div class="col-lg-5">
            <!-- Plain-Language Formula Card -->
            <div class="card border-0 shadow-sm mb-4 bg-light-subtle">
                <div class="card-body">
                    <h5 class="card-title h6 fw-bold mb-2"><i class="bi bi-info-circle text-info me-2"></i>Plain-Language Pricing Example</h5>
                    <p class="small text-muted mb-2">
                        Assume a customer orders goods worth <strong>KES 3,500</strong> living <strong>10.0 km straight-line</strong> from CBD:
                    </p>
                    <ol class="small text-muted ps-3 mb-3">
                        <li>Estimated road travel = <code>10.0 &times; <?= $pricing['distance_road_factor'] ?> = <?= number_format(10.0 * $pricing['distance_road_factor'], 1) ?> km</code></li>
                        <li>Billable excess distance = <code><?= number_format(10.0 * $pricing['distance_road_factor'], 1) ?> - <?= $pricing['distance_included_km'] ?> = <?= number_format(max(0, 10.0 * $pricing['distance_road_factor'] - $pricing['distance_included_km']), 1) ?> km</code></li>
                        <li>Distance surcharge = <code><?= number_format(max(0, 10.0 * $pricing['distance_road_factor'] - $pricing['distance_included_km']), 1) ?> &times; <?= $pricing['distance_per_km'] ?> = KES <?= number_format(max(0, 10.0 * $pricing['distance_road_factor'] - $pricing['distance_included_km']) * $pricing['distance_per_km'], 2) ?></code></li>
                        <li>Total = Base (KES <?= $pricing['distance_base_fee'] ?>) + Surcharge = <strong>KES <?= number_format($pricing['distance_base_fee'] + max(0, 10.0 * $pricing['distance_road_factor'] - $pricing['distance_included_km']) * $pricing['distance_per_km'], 2) ?></strong></li>
                        <li>Rounded to nearest KES <?= $pricing['distance_rounding'] ?> and clamped between KES <?= $pricing['distance_min_fee'] ?> and KES <?= $pricing['distance_max_fee'] ?>.</li>
                    </ol>
                    <div class="p-2 bg-white rounded border small text-dark">
                        <i class="bi bi-gift text-success me-1"></i> Free delivery threshold: Orders over <strong>KES <?= number_format($pricing['free_delivery_threshold'], 2) ?></strong> receive KES 0.00 delivery automatically!
                    </div>
                </div>
            </div>

            <!-- Quote Tester Card -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent border-0 pt-3 pb-2 px-4">
                    <h5 class="card-title mb-0"><i class="bi bi-speedometer2 text-warning me-2"></i>Live Quote Tester</h5>
                    <p class="text-muted small mb-0">Simulate checkout delivery quotes against live server pricing rules.</p>
                </div>
                <div class="card-body px-4 pb-4">
                    <?php if ($quoteError): ?>
                        <div class="alert alert-danger small py-2">
                            <i class="bi bi-exclamation-triangle me-1"></i> <?= e($quoteError) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($quoteResult): ?>
                        <div class="card bg-success-subtle border-success mb-3">
                            <div class="card-body py-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="badge bg-success text-uppercase"><?= e($quoteResult['method']) ?></span>
                                    <span class="fs-4 fw-bold text-success">
                                        KES <?= number_format($quoteResult['fee'], 2) ?>
                                    </span>
                                </div>
                                <div class="small fw-semibold text-dark"><?= e($quoteResult['label']) ?></div>
                                <div class="text-muted small mt-1">
                                    <?php if ($quoteResult['distance_km'] !== null): ?>
                                        <i class="bi bi-geo me-1"></i> Distance: <?= e($quoteResult['distance_km']) ?> km &bull;
                                    <?php endif; ?>
                                    <i class="bi bi-clock me-1"></i> Transit: <?= e($quoteResult['est_days']) ?> day(s)
                                </div>
                                <?php if (!empty($quoteResult['free_applied'])): ?>
                                    <div class="mt-2 text-success small fw-bold">
                                        <i class="bi bi-check-circle me-1"></i> Free delivery threshold reward applied!
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="<?= e(url('admin/delivery/pricing.php')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="test_quote">

                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Delivery Method</label>
                            <select name="test_method" id="testMethod" class="form-select" onchange="toggleQuoteInputs()">
                                <option value="distance" <?= ($_POST['test_method'] ?? '') === 'distance' ? 'selected' : '' ?>>Distance (GPS Coordinates)</option>
                                <option value="zone" <?= ($_POST['test_method'] ?? '') === 'zone' ? 'selected' : '' ?>>Zone (Regional Flat Rate)</option>
                                <option value="pickup" <?= ($_POST['test_method'] ?? '') === 'pickup' ? 'selected' : '' ?>>Store Pickup (Free)</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Cart Subtotal (KES)</label>
                            <div class="input-group">
                                <span class="input-group-text">KES</span>
                                <input type="number" step="0.01" min="0" name="test_subtotal" class="form-control" value="<?= e($_POST['test_subtotal'] ?? '2500.00') ?>" required>
                            </div>
                        </div>

                        <!-- Zone select input -->
                        <div class="mb-3" id="zoneInputGroup" style="display: none;">
                            <label class="form-label small fw-semibold">Select Regional Zone</label>
                            <select name="test_zone_id" class="form-select">
                                <option value="">-- Choose Delivery Zone --</option>
                                <?php foreach ($zones as $z): ?>
                                    <option value="<?= e($z['id']) ?>" <?= ((int)($_POST['test_zone_id'] ?? 0) === (int)$z['id']) ? 'selected' : '' ?>>
                                        <?= e($z['county']) ?> - <?= e($z['area']) ?> (KES <?= number_format($z['fee'], 2) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Distance Coordinates inputs -->
                        <div id="coordInputGroup">
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="form-label small fw-semibold">Dest. Latitude</label>
                                    <input type="number" step="0.000001" name="test_lat" class="form-control" placeholder="-1.260000" value="<?= e($_POST['test_lat'] ?? '-1.261234') ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-semibold">Dest. Longitude</label>
                                    <input type="number" step="0.000001" name="test_lng" class="form-control" placeholder="36.800000" value="<?= e($_POST['test_lng'] ?? '36.805678') ?>">
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-warning w-100 fw-semibold">
                            <i class="bi bi-play-circle me-1"></i> Calculate Quote
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function toggleQuoteInputs() {
    const method = document.getElementById('testMethod').value;
    const zoneGroup = document.getElementById('zoneInputGroup');
    const coordGroup = document.getElementById('coordInputGroup');

    if (method === 'zone') {
        zoneGroup.style.display = 'block';
        coordGroup.style.display = 'none';
    } else if (method === 'distance') {
        zoneGroup.style.display = 'none';
        coordGroup.style.display = 'block';
    } else {
        zoneGroup.style.display = 'none';
        coordGroup.style.display = 'none';
    }
}
document.addEventListener('DOMContentLoaded', toggleQuoteInputs);
</script>

<?php require dirname(__DIR__, 3) . '/app/views/admin/footer.php'; ?>
