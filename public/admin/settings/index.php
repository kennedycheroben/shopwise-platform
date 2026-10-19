<?php
require dirname(__DIR__, 3) . '/app/bootstrap.php';
require dirname(__DIR__, 3) . '/app/views/admin/guard.php';

use App\Services\SettingService;
use App\Services\Permissions;
use App\Services\AdminAuthService;

Permissions::require('settings.manage');
$currentUser = AdminAuthService::user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['_csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Forbidden: Invalid CSRF token.');
    }

    $actorId = (int)$currentUser['id'];
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    try {
        SettingService::updateStoreSettings($_POST, $actorId, $ip);
        flash_set('success', 'Store profile and operational settings saved successfully.');
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }

    redirect('admin/settings/');
}

$settings = SettingService::getAll();

$pageTitle = 'Store Settings';
$activeGroup = 'settings';

require dirname(__DIR__, 3) . '/app/views/admin/layout.php';
?>

<div class="main-content page-settings">
    <!-- Page Header -->
    <div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= e(url('admin/')) ?>">Dashboard</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Settings</li>
                </ol>
            </nav>
            <h1 class="page-title h3 mb-0">Store Identity &amp; Operations</h1>
            <p class="text-muted small mb-0">Manage customer-facing store profile, customer service contacts, and order pickup hours.</p>
        </div>
        <div>
            <a href="<?= e(url('admin/delivery/pricing.php')) ?>" class="btn btn-outline-secondary">
                <i class="bi bi-truck me-1"></i> Delivery &amp; Shipping Settings
            </a>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <!-- Store Profile Form -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-0 pt-4 pb-2 px-4">
                    <h5 class="card-title mb-1"><i class="bi bi-shop me-2 text-primary"></i>General Store Profile</h5>
                    <p class="text-muted small mb-0">Information presented on order receipts, customer emails, invoices, and website footer.</p>
                </div>
                <div class="card-body px-4 pb-4">
                    <form method="POST" action="<?= e(url('admin/settings/')) ?>">
                        <?= csrf_field() ?>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Store Brand Name <span class="text-danger">*</span></label>
                                <input type="text" name="store_name" class="form-control" value="<?= e($settings['store_name'] ?? 'ShopWise Kenya') ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Store Tagline / Slogan</label>
                                <input type="text" name="store_tagline" class="form-control" value="<?= e($settings['store_tagline'] ?? 'Kenya\'s Smartest Online Electronics & Lifestyle Shopping Destination') ?>">
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Customer Support Email <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                    <input type="email" name="support_email" class="form-control" value="<?= e($settings['support_email'] ?? 'support@kennedycheroben.co.ke') ?>" required>
                                </div>
                                <div class="form-text small">Used as reply-to for transactional emails and contact inquiries.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Customer Support Phone <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                                    <input type="text" name="support_phone" class="form-control" value="<?= e($settings['support_phone'] ?? '+254 700 000 000') ?>" required>
                                </div>
                                <div class="form-text small">Kenyan format: e.g. 0700000000 or +254700000000.</div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Store Physical Pickup Address <span class="text-danger">*</span></label>
                            <textarea name="pickup_address" class="form-control" rows="2" required><?= e($settings['pickup_address'] ?? "ShopWise Hub, 4th Floor, Bihi Towers, Moi Avenue, Nairobi CBD, Kenya") ?></textarea>
                            <div class="form-text small">Customer collection point for "Store Pickup" checkout method.</div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label small fw-semibold">Store Business Hours <span class="text-danger">*</span></label>
                            <input type="text" name="business_hours" class="form-control" value="<?= e($settings['business_hours'] ?? 'Monday - Saturday: 8:00 AM - 7:00 PM (Closed on Sundays)') ?>" required>
                        </div>

                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="bi bi-save me-1"></i> Save Store Settings
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <!-- Security Architecture Notice Card -->
            <div class="card border-0 shadow-sm mb-4 border-start border-4 border-warning">
                <div class="card-body">
                    <h5 class="card-title h6 fw-bold mb-2">
                        <i class="bi bi-shield-lock-fill text-warning me-2"></i>Security Architecture Notice
                    </h5>
                    <p class="small text-muted mb-2">
                        In accordance with the <strong>ShopWise Constitution</strong>:
                    </p>
                    <ul class="small text-muted ps-3 mb-3">
                        <li class="mb-1"><strong>M-Pesa API Keys &amp; Passkeys</strong></li>
                        <li class="mb-1"><strong>Google OAuth Client Secrets</strong></li>
                        <li class="mb-1"><strong>SMTP Email Server Credentials</strong></li>
                        <li><strong>Database Credentials</strong></li>
                    </ul>
                    <p class="small text-muted mb-0">
                        These server secrets are strictly excluded from the database settings table. They reside exclusively in server-side configuration (<code>config.local.php</code>) and environment secrets, guarded from web exposure or accidental database dumps.
                    </p>
                </div>
            </div>

            <!-- Currency & Region Summary Card -->
            <div class="card border-0 shadow-sm bg-light-subtle">
                <div class="card-body">
                    <h5 class="card-title h6 fw-bold mb-3">
                        <i class="bi bi-globe-africa text-primary me-2"></i>Locale &amp; Regional Defaults
                    </h5>
                    <dl class="row small mb-0">
                        <dt class="col-sm-5 text-muted">Operating Country:</dt>
                        <dd class="col-sm-7 fw-semibold">Kenya (KE)</dd>

                        <dt class="col-sm-5 text-muted">Primary Currency:</dt>
                        <dd class="col-sm-7 fw-semibold">KES (Kenyan Shilling)</dd>

                        <dt class="col-sm-5 text-muted">Timezone:</dt>
                        <dd class="col-sm-7 fw-semibold">Africa/Nairobi (UTC+3)</dd>

                        <dt class="col-sm-5 text-muted">Phone Standard:</dt>
                        <dd class="col-sm-7 fw-semibold">E.164 (+254)</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require dirname(__DIR__, 3) . '/app/views/admin/footer.php'; ?>
