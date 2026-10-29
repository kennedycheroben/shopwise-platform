<?php
/**
 * Shipping & Delivery Information View
 * Based on _template_reference/storefront_pages/shiping-info.html (lines 835-1021)
 */
use App\Database\Database;

$pageTitle = 'Shipping & Delivery Information';
$metaDescription = 'Explore delivery zones, courier rates, estimated delivery times, and free shipping thresholds across Kenya at ' . setting('store_name', 'ShopWise Kenya') . '.';
$bodyClass = 'shipping-info-page';

$breadcrumbs = [
    ['label' => 'Shipping Information', 'url' => null],
];

// Fetch active delivery zones directly from the database
$pdo = Database::getInstance();
$stmt = $pdo->query("SELECT * FROM delivery_zones WHERE is_active = 1 ORDER BY county ASC, fee ASC, area ASC");
$activeZones = $stmt->fetchAll(PDO::FETCH_ASSOC);

$freeThreshold = (float)setting('free_delivery_threshold', 5000.00);
$pickupAddress = setting('pickup_address', 'Moi Avenue, Nairobi CBD');
$baseFee = (float)setting('distance_base_fee', 150.00);
$includedKm = (float)setting('distance_included_km', 3.00);
$perKm = (float)setting('distance_per_km', 30.00);
$roadFactor = (float)setting('distance_road_factor', 1.30);
$minFee = (float)setting('distance_min_fee', 150.00);
$maxFee = (float)setting('distance_max_fee', 1500.00);
$rounding = (int)setting('distance_rounding', 10);

include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/breadcrumbs.php';
include __DIR__ . '/partials/flash.php';
?>

<main class="main">

    <!-- Shipping Info Section -->
    <section id="shipping-info" class="shipping-info section">

        <div class="container">

            <!-- Shipping Methods Overview -->
            <div class="row gy-4 mb-5">
                <div class="col-lg-4 col-md-6">
                    <div class="method-card">
                        <div class="method-icon">
                            <i class="bi bi-geo-alt"></i>
                        </div>
                        <div class="method-content">
                            <h4>Nairobi CBD Pickup</h4>
                            <p>Collect your packaged order in person from our central Nairobi collection point at no additional fee.</p>
                            <span class="eta-label"><i class="bi bi-clock"></i> Same Day Ready</span>
                        </div>
                    </div>
                </div><!-- End Method Card -->

                <div class="col-lg-4 col-md-6">
                    <div class="method-card">
                        <div class="method-icon">
                            <i class="bi bi-lightning-charge"></i>
                        </div>
                        <div class="method-content">
                            <h4>Nairobi Metro Courier</h4>
                            <p>Fast, direct courier dispatch to homes and offices throughout Nairobi county and bordering estates.</p>
                            <span class="eta-label"><i class="bi bi-clock"></i> Same Day to 1 Day</span>
                        </div>
                    </div>
                </div><!-- End Method Card -->

                <div class="col-lg-4 col-md-6">
                    <div class="method-card">
                        <div class="method-icon">
                            <i class="bi bi-box-seam"></i>
                        </div>
                        <div class="method-content">
                            <h4>Upcountry Kenya Delivery</h4>
                            <p>Dependable overland courier fulfillment to major towns and counties across Kenya.</p>
                            <span class="eta-label"><i class="bi bi-clock"></i> 1 to 2 Business Days</span>
                        </div>
                    </div>
                </div><!-- End Method Card -->
            </div><!-- End Shipping Methods -->

            <!-- Rate Tier Cards -->
            <div class="row gy-4 mb-5">
                <div class="col-12">
                    <div class="rates-wrapper">
                        <div class="rates-header">
                            <span class="section-badge">Shipping Rates &amp; Tiers</span>
                            <p>Transparent delivery pricing calculated from live store settings and active delivery zones</p>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="tier-card">
                                    <span class="tier-name">In-Store Pickup</span>
                                    <div class="tier-amount"><?= format_kes(0) ?></div>
                                    <p class="tier-note"><?= e($pickupAddress) ?></p>
                                </div>
                            </div><!-- End Tier Card -->

                            <div class="col-md-4">
                                <div class="tier-card highlighted">
                                    <span class="popular-tag">Qualifying Orders</span>
                                    <span class="tier-name">Free Delivery</span>
                                    <div class="tier-amount"><?= format_kes(0) ?></div>
                                    <p class="tier-note">Complimentary on orders of <?= format_kes($freeThreshold) ?> or more</p>
                                </div>
                            </div><!-- End Tier Card -->

                            <div class="col-md-4">
                                <div class="tier-card">
                                    <span class="tier-name">Zone Rates</span>
                                    <div class="tier-amount">From <?= format_kes($minFee) ?></div>
                                    <p class="tier-note">Based on active delivery zone table below</p>
                                </div>
                            </div><!-- End Tier Card -->
                        </div>
                    </div>
                </div>
            </div><!-- End Rate Tier Cards -->

            <!-- Active Delivery Zones Table -->
            <div class="row gy-4 mb-5">
                <div class="col-12">
                    <div class="p-4 border rounded bg-white shadow-sm">
                        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                            <div>
                                <h3 class="h4 mb-1">Standard Delivery Zones</h3>
                                <p class="text-muted small mb-0">Active delivery coverage areas and fixed courier rates across Kenyan counties.</p>
                            </div>
                            <span class="badge bg-primary px-3 py-2"><?= count($activeZones) ?> Active Zones</span>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col" style="width: 20%;">County</th>
                                        <th scope="col" style="width: 45%;">Coverage Area</th>
                                        <th scope="col" style="width: 15%;" class="text-end">Delivery Fee</th>
                                        <th scope="col" style="width: 20%;" class="text-end">Estimated Days</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($activeZones)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center py-4 text-muted">No active delivery zones found. Contact support for customized dispatch rates.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($activeZones as $zone): ?>
                                            <?php
                                            $minD = (int)$zone['est_days_min'];
                                            $maxD = (int)$zone['est_days_max'];
                                            if ($minD === 0 && $maxD === 0) {
                                                $estText = 'Same day';
                                            } elseif ($minD === $maxD) {
                                                $estText = $minD . ' business day' . ($minD > 1 ? 's' : '');
                                            } else {
                                                $estText = $minD . ' - ' . $maxD . ' business days';
                                            }
                                            ?>
                                            <tr>
                                                <td class="fw-semibold"><?= e($zone['county']) ?></td>
                                                <td><?= e($zone['area']) ?></td>
                                                <td class="text-end fw-bold text-primary"><?= format_kes((float)$zone['fee']) ?></td>
                                                <td class="text-end text-muted small"><i class="bi bi-clock me-1"></i><?= e($estText) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div><!-- End Delivery Zones Table -->

            <!-- Distance-Based Delivery Explanation Panel -->
            <div class="row gy-4 mb-5">
                <div class="col-12">
                    <div class="worldwide-panel p-4 border rounded bg-light">
                        <div class="row align-items-center">
                            <div class="col-lg-5 mb-3 mb-lg-0">
                                <span class="section-badge"><i class="bi bi-compass"></i> Dynamic Delivery</span>
                                <h3>Distance-Based Calculation</h3>
                                <p class="text-muted mb-0">For deliveries where exact map coordinates are provided, our dispatch system computes the route fee directly from our Nairobi CBD fulfillment hub.</p>
                            </div>
                            <div class="col-lg-7">
                                <div class="row g-3">
                                    <div class="col-sm-4">
                                        <div class="info-tile p-3 bg-white rounded border">
                                            <i class="bi bi-flag-fill text-primary fs-4"></i>
                                            <h5 class="mt-2 mb-1">Base Fee</h5>
                                            <p class="small text-muted mb-0"><?= format_kes($baseFee) ?> including initial <?= number_format($includedKm, 1) ?> km</p>
                                        </div>
                                    </div>
                                    <div class="col-sm-4">
                                        <div class="info-tile p-3 bg-white rounded border">
                                            <i class="bi bi-speedometer2 text-primary fs-4"></i>
                                            <h5 class="mt-2 mb-1">Rate Per Km</h5>
                                            <p class="small text-muted mb-0"><?= format_kes($perKm) ?>/km (&times;<?= number_format($roadFactor, 2) ?> road factor)</p>
                                        </div>
                                    </div>
                                    <div class="col-sm-4">
                                        <div class="info-tile p-3 bg-white rounded border">
                                            <i class="bi bi-shield-check text-primary fs-4"></i>
                                            <h5 class="mt-2 mb-1">Fee Limits</h5>
                                            <p class="small text-muted mb-0">Min <?= format_kes($minFee) ?> &bull; Max <?= format_kes($maxFee) ?> (rounded to <?= format_kes($rounding) ?>)</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div><!-- End Distance-Based Explanation -->

            <!-- Shipping FAQ Accordion -->
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="faq-header text-center mb-4">
                        <span class="section-badge"><i class="bi bi-question-circle"></i> Quick Answers</span>
                        <h4 class="mt-2">Delivery Policy Common Questions</h4>
                    </div>

                    <div class="faq-list">
                        <div class="faq-item faq-active mb-3 p-3 border rounded bg-white">
                            <h5 class="fw-semibold mb-2">How do I track my order once dispatched?</h5>
                            <p class="text-muted small mb-0">When your parcel is packaged and dispatched from our Nairobi hub, you will receive an email confirmation with your courier waybill or parcel collection reference.</p>
                        </div>
                        <div class="faq-item mb-3 p-3 border rounded bg-white">
                            <h5 class="fw-semibold mb-2">What happens if I miss the delivery?</h5>
                            <p class="text-muted small mb-0">The courier will contact you via your provided phone number to arrange a second delivery attempt or direct you to the nearest local parcel depot for pickup.</p>
                        </div>
                        <div class="faq-item mb-3 p-3 border rounded bg-white">
                            <h5 class="fw-semibold mb-2">Do you deliver on Saturdays and Sundays?</h5>
                            <p class="text-muted small mb-0">Deliveries operate Monday through Saturday. Sunday deliveries are not scheduled, but orders placed over the weekend are prioritized for Monday morning dispatch.</p>
                        </div>
                    </div>
                </div>
            </div><!-- End Shipping FAQ Accordion -->

        </div>

    </section><!-- /Shipping Info Section -->

</main>

<?php include __DIR__ . '/partials/footer.php'; ?>
