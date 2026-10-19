<?php
require dirname(__DIR__, 3) . '/app/bootstrap.php';
require dirname(__DIR__, 3) . '/app/views/admin/guard.php';

use App\Services\CustomerService;
use App\Services\Permissions;
use App\Services\AdminAuthService;

Permissions::require('customers.view');
$currentUser = AdminAuthService::user();
$actorId = (int)$currentUser['id'];
$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$isAdmin = ($currentUser['role'] ?? '') === 'admin';

$customerId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($customerId <= 0) {
    redirect('admin/customers/');
}

// Handle POST actions (Block / Unblock)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_verify($_POST['_csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Forbidden: Invalid CSRF token.');
    }

    $action = $_POST['action'] ?? '';
    $status = trim($_POST['status'] ?? '');

    try {
        if ($action === 'set_status') {
            Permissions::require('customers.manage');
            CustomerService::setStatus($customerId, $status, $actorId, $ip);
            $actionLabel = ($status === 'blocked') ? 'blocked' : 'unblocked';
            flash_set('success', "Customer has been {$actionLabel} successfully.");
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }

    redirect('admin/customers/detail.php?id=' . $customerId);
}

$customer = CustomerService::find($customerId);
if (!$customer) {
    http_response_code(404);
    require dirname(__DIR__, 3) . '/app/views/admin/error-404.php';
    exit;
}

$fullName = trim($customer['first_name'] . ' ' . $customer['last_name']) ?: 'Customer #' . $customer['id'];
$pageTitle = 'Customer: ' . $fullName;
$activeGroup = 'customers';

require dirname(__DIR__, 3) . '/app/views/admin/layout.php';
?>

<div class="main-content page-users-view">
    <!-- Header with Breadcrumbs & Back Button -->
    <div class="pagetitle mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h1>Customer Profile</h1>
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><a href="<?= e(url('admin/')) ?>">Home</a></li>
                        <li class="breadcrumb-item"><a href="<?= e(url('admin/customers/')) ?>">Customers</a></li>
                        <li class="breadcrumb-item active"><?= e($fullName) ?></li>
                    </ol>
                </nav>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= e(url('admin/customers/')) ?>" class="btn btn-sm btn-light border">
                    <i class="bi bi-arrow-left me-1"></i> Back to Customers
                </a>
            </div>
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

    <!-- Customer Hero Card -->
    <div class="card mb-4 shadow-sm border-0">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-primary text-white fs-3 fw-bold d-flex align-items-center justify-content-center" style="width: 64px; height: 64px;">
                        <?= e(strtoupper(substr($customer['first_name'] ?: 'C', 0, 1))) ?>
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <h3 class="h4 fw-bold text-dark mb-0"><?= e($fullName) ?></h3>
                            <?php if ($customer['status'] === 'active'): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">Active Account</span>
                            <?php else: ?>
                                <span class="badge bg-danger text-white px-2 py-1">Blocked Account</span>
                            <?php endif; ?>
                        </div>
                        <div class="text-muted small">
                            Customer ID: #USR-<?= str_pad((string)$customer['id'], 5, '0', STR_PAD_LEFT) ?>
                            &bull; Registered <?= e(date('F j, Y', strtotime($customer['created_at']))) ?>
                            <?php if (!empty($customer['last_login_at'])): ?>
                                &bull; Last Sign-in: <?= e(date('M j, Y H:i', strtotime($customer['last_login_at']))) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Admin Action: Block / Unblock -->
                <?php if ($isAdmin): ?>
                    <div>
                        <?php if ($customer['status'] === 'active'): ?>
                            <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#blockCustomerModal">
                                <i class="bi bi-slash-circle me-1"></i> Block Customer
                            </button>
                        <?php else: ?>
                            <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#unblockCustomerModal">
                                <i class="bi bi-check-circle me-1"></i> Unblock Customer
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Quick Stats Strip -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-body p-3 d-flex align-items-center gap-3">
                    <div class="p-2 rounded bg-primary-subtle text-primary fs-4">
                        <i class="bi bi-cart3"></i>
                    </div>
                    <div>
                        <div class="fs-5 fw-bold text-dark"><?= $customer['total_orders'] ?></div>
                        <div class="text-muted small">Total Orders</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-body p-3 d-flex align-items-center gap-3">
                    <div class="p-2 rounded bg-success-subtle text-success fs-4">
                        <i class="bi bi-cash-stack"></i>
                    </div>
                    <div>
                        <div class="fs-5 fw-bold text-dark"><?= e(format_money((float)$customer['total_spent'])) ?></div>
                        <div class="text-muted small">Total Spent (Paid)</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-body p-3 d-flex align-items-center gap-3">
                    <div class="p-2 rounded bg-info-subtle text-info fs-4">
                        <i class="bi bi-geo-alt"></i>
                    </div>
                    <div>
                        <div class="fs-5 fw-bold text-dark"><?= count($customer['addresses']) ?></div>
                        <div class="text-muted small">Saved Addresses</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-body p-3 d-flex align-items-center gap-3">
                    <div class="p-2 rounded bg-secondary-subtle text-secondary fs-4">
                        <i class="bi bi-shield-check"></i>
                    </div>
                    <div>
                        <div class="fs-6 fw-bold text-dark">
                            <?= !empty($customer['google_id']) ? 'Google OAuth' : 'Password' ?>
                        </div>
                        <div class="text-muted small">Sign-in Method</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Profile & Order History Grid -->
    <div class="row g-4">
        <!-- Left Column: Customer Profile & Addresses (col-lg-5) -->
        <div class="col-lg-5">
            <!-- Account Details Card -->
            <div class="card mb-4 shadow-sm border-0">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="card-title mb-0 d-flex align-items-center gap-2">
                        <i class="bi bi-person-lines-fill text-primary"></i>
                        Contact & Authentication
                    </h5>
                </div>
                <div class="card-body">
                    <div class="mb-3 pb-2 border-bottom">
                        <span class="text-muted small d-block">Full Legal Name:</span>
                        <strong class="text-dark"><?= e($fullName) ?></strong>
                    </div>
                    <div class="mb-3 pb-2 border-bottom">
                        <span class="text-muted small d-block">Email Address:</span>
                        <div class="d-flex align-items-center gap-2">
                            <strong class="text-dark"><?= e($customer['email']) ?></strong>
                            <?php if (!empty($customer['email_verified_at'])): ?>
                                <span class="badge bg-success-subtle text-success small" title="Verified on <?= e($customer['email_verified_at']) ?>">
                                    <i class="bi bi-check-circle-fill me-1"></i> Verified
                                </span>
                            <?php else: ?>
                                <span class="badge bg-warning-subtle text-warning small">
                                    <i class="bi bi-hourglass-split me-1"></i> Pending Verification
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="mb-3 pb-2 border-bottom">
                        <span class="text-muted small d-block">Phone Number:</span>
                        <?php if (!empty($customer['phone'])): ?>
                            <a href="tel:<?= e($customer['phone']) ?>" class="text-decoration-none fw-medium text-dark">
                                <i class="bi bi-telephone me-1 text-muted"></i> <?= e($customer['phone']) ?>
                            </a>
                        <?php else: ?>
                            <span class="text-muted small">No phone number recorded on profile</span>
                        <?php endif; ?>
                    </div>
                    <div class="mb-0">
                        <span class="text-muted small d-block">Authentication Provider:</span>
                        <?php if (!empty($customer['google_id'])): ?>
                            <span class="badge bg-light text-dark border">
                                <i class="bi bi-google text-danger me-1"></i> Google Sign-in Linked
                            </span>
                        <?php else: ?>
                            <span class="badge bg-light text-dark border">
                                <i class="bi bi-key me-1"></i> Standard Email & Password
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Saved Addresses Card -->
            <div class="card mb-4 shadow-sm border-0">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0 d-flex align-items-center gap-2">
                        <i class="bi bi-pin-map text-primary"></i>
                        Saved Addresses
                    </h5>
                    <span class="badge bg-light text-dark border"><?= count($customer['addresses']) ?></span>
                </div>
                <div class="card-body">
                    <?php if (empty($customer['addresses'])): ?>
                        <div class="text-center py-4 text-muted small">
                            <i class="bi bi-geo-alt d-block fs-3 mb-1"></i>
                            No saved addresses on file for this customer.
                        </div>
                    <?php else: ?>
                        <?php foreach ($customer['addresses'] as $addr): ?>
                            <div class="border rounded p-3 mb-3 bg-light position-relative">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="badge bg-secondary-subtle text-secondary small text-uppercase">
                                        <?= e($addr['type'] ?? 'Address') ?>
                                    </span>
                                    <?php if (!empty($addr['is_default'])): ?>
                                        <span class="badge bg-primary-subtle text-primary small">
                                            <i class="bi bi-star-fill me-1"></i> Default Delivery Address
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="fw-semibold text-dark"><?= e($addr['recipient_name'] ?: $fullName) ?></div>
                                <div class="text-muted small">
                                    <i class="bi bi-telephone me-1"></i> <?= e($addr['phone']) ?>
                                </div>
                                <div class="text-dark small mt-1">
                                    <?= e($addr['street_details']) ?><br>
                                    <?= e(implode(', ', array_filter([$addr['area'], $addr['county']]))) ?>
                                </div>
                                <?php if (!empty($addr['delivery_instructions'])): ?>
                                    <div class="text-muted smaller mt-2 fst-italic">
                                        Note: <?= e($addr['delivery_instructions']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right Column: Order History (col-lg-7) -->
        <div class="col-lg-7">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0 d-flex align-items-center gap-2">
                        <i class="bi bi-clock-history text-primary"></i>
                        Customer Order History
                    </h5>
                    <span class="badge bg-light text-dark border"><?= count($customer['orders']) ?> orders</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($customer['orders'])): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="bi bi-bag-x d-block fs-3 mb-2"></i>
                            This customer has not placed any orders yet.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Order #</th>
                                        <th>Date</th>
                                        <th class="text-center">Items</th>
                                        <th>Status</th>
                                        <th>Payment</th>
                                        <th class="text-end">Total (KES)</th>
                                        <th class="text-end pe-3">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($customer['orders'] as $ord): ?>
                                        <?php
                                        $statusClass = match ($ord['status']) {
                                            'pending_payment'  => 'bg-warning-subtle text-warning',
                                            'paid'             => 'bg-success-subtle text-success',
                                            'processing'       => 'bg-primary-subtle text-primary',
                                            'out_for_delivery' => 'bg-info-subtle text-info',
                                            'delivered'        => 'bg-success-subtle text-success',
                                            'cancelled'        => 'bg-danger-subtle text-danger',
                                            'expired'          => 'bg-secondary-subtle text-secondary',
                                            'refunded'         => 'bg-dark-subtle text-dark',
                                            default            => 'bg-light text-muted',
                                        };
                                        ?>
                                        <tr>
                                            <td>
                                                <a href="<?= e(url('admin/orders/detail.php?id=' . $ord['id'])) ?>" class="fw-semibold text-primary">
                                                    <?= e($ord['order_number']) ?>
                                                </a>
                                            </td>
                                            <td class="small text-muted"><?= e(date('d M Y', strtotime($ord['created_at']))) ?></td>
                                            <td class="text-center">
                                                <span class="badge bg-light text-dark border"><?= (int)$ord['items_count'] ?></span>
                                            </td>
                                            <td>
                                                <span class="badge <?= $statusClass ?>">
                                                    <?= e(ucwords(str_replace('_', ' ', $ord['status']))) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark border small">
                                                    <?= e(ucfirst($ord['payment_status'])) ?>
                                                </span>
                                            </td>
                                            <td class="text-end fw-semibold text-dark">
                                                <?= e(format_money((float)$ord['total'])) ?>
                                            </td>
                                            <td class="text-end pe-3">
                                                <a href="<?= e(url('admin/orders/detail.php?id=' . $ord['id'])) ?>" class="btn btn-sm btn-outline-secondary py-0 px-2" title="View Order">
                                                    <i class="bi bi-eye"></i>
                                                </a>
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
    </div>
</div>

<!-- Block Modal -->
<?php if ($isAdmin): ?>
    <div class="modal fade" id="blockCustomerModal" tabindex="-1" aria-labelledby="blockCustomerModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <form action="<?= e(url('admin/customers/detail.php')) ?>" method="POST" class="modal-content">
                <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="set_status">
                <input type="hidden" name="id" value="<?= $customer['id'] ?>">
                <input type="hidden" name="status" value="blocked">

                <div class="modal-header bg-danger-subtle">
                    <h5 class="modal-title text-danger fw-bold" id="blockCustomerModalLabel">Block Customer Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to block <strong><?= e($fullName) ?></strong>?</p>
                    <div class="alert alert-warning small mb-0">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        Blocking revokes this customer's active session, denies access to their account, and prevents placement of any orders.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger btn-sm">Confirm Block</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Unblock Modal -->
    <div class="modal fade" id="unblockCustomerModal" tabindex="-1" aria-labelledby="unblockCustomerModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <form action="<?= e(url('admin/customers/detail.php')) ?>" method="POST" class="modal-content">
                <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="set_status">
                <input type="hidden" name="id" value="<?= $customer['id'] ?>">
                <input type="hidden" name="status" value="active">

                <div class="modal-header bg-success-subtle">
                    <h5 class="modal-title text-success fw-bold" id="unblockCustomerModalLabel">Unblock Customer Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to unblock <strong><?= e($fullName) ?></strong> and restore their shopping and sign-in privileges?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success btn-sm">Confirm Unblock</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php
require dirname(__DIR__, 3) . '/app/views/admin/footer.php';
