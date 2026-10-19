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

// Handle POST actions (Block / Unblock)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_verify($_POST['_csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Forbidden: Invalid CSRF token.');
    }

    $action = $_POST['action'] ?? '';
    $customerId = (int)($_POST['id'] ?? 0);
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

    $query = $_POST['redirect_query'] ?? '';
    redirect('admin/customers/' . ($query !== '' ? '?' . ltrim($query, '?') : ''));
}

// Filters & Pagination
$filters = [
    'search'        => trim($_GET['search'] ?? ''),
    'status'        => trim($_GET['status'] ?? ''),
    'auth_provider' => trim($_GET['auth_provider'] ?? ''),
    'sort'          => trim($_GET['sort'] ?? 'created_at'),
    'order'         => trim($_GET['order'] ?? 'desc'),
];

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$result = CustomerService::paginate($filters, $page, $perPage);
$customers = $result['items'];
$totalItems = $result['total_items'];
$totalPages = $result['total_pages'];

$currentQuery = http_build_query(array_filter($_GET));

$pageTitle = 'Customers';
$activeGroup = 'customers';

require dirname(__DIR__, 3) . '/app/views/admin/layout.php';
?>

<div class="main-content page-users">
    <!-- Page Header -->
    <div class="pagetitle mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h1>Customers Management</h1>
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><a href="<?= e(url('admin/')) ?>">Home</a></li>
                        <li class="breadcrumb-item active">Customers</li>
                    </ol>
                </nav>
            </div>
            <div>
                <span class="text-muted small">Showing <?= count($customers) ?> of <?= number_format($totalItems) ?> registered customers</span>
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

    <!-- Filter Card -->
    <div class="card mb-4">
        <div class="card-body py-3">
            <form action="<?= e(url('admin/customers/')) ?>" method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="sort" value="<?= e($filters['sort']) ?>">
                <input type="hidden" name="order" value="<?= e($filters['order']) ?>">

                <!-- Search -->
                <div class="col-12 col-md-5">
                    <label class="form-label small text-muted mb-1">Search Customer</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-transparent text-muted"><i class="bi bi-search"></i></span>
                        <input type="text" name="search" class="form-control" placeholder="Search by name, email, or phone number..." value="<?= e($filters['search']) ?>">
                    </div>
                </div>

                <!-- Status Filter -->
                <div class="col-6 col-md-3">
                    <label class="form-label small text-muted mb-1">Account Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Account Statuses</option>
                        <option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Active Only</option>
                        <option value="blocked" <?= $filters['status'] === 'blocked' ? 'selected' : '' ?>>Blocked Only</option>
                    </select>
                </div>

                <!-- Sign-in Method Filter -->
                <div class="col-6 col-md-3">
                    <label class="form-label small text-muted mb-1">Sign-in Method</label>
                    <select name="auth_provider" class="form-select form-select-sm">
                        <option value="">All Sign-in Methods</option>
                        <option value="password" <?= $filters['auth_provider'] === 'password' ? 'selected' : '' ?>>Email & Password</option>
                        <option value="google" <?= $filters['auth_provider'] === 'google' ? 'selected' : '' ?>>Google Account</option>
                    </select>
                </div>

                <!-- Filter Actions -->
                <div class="col-12 col-md-1 d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-primary w-100" title="Apply filters">
                        <i class="bi bi-filter"></i> Filter
                    </button>
                    <?php if (!empty($filters['search']) || !empty($filters['status']) || !empty($filters['auth_provider'])): ?>
                        <a href="<?= e(url('admin/customers/')) ?>" class="btn btn-sm btn-outline-secondary" title="Clear filters">
                            <i class="bi bi-x-lg"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Customers Table Card -->
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0 d-flex align-items-center gap-2">
                <i class="bi bi-people text-primary"></i>
                Customer Directory
            </h5>
            <span class="badge bg-light text-dark border"><?= number_format($totalItems) ?> customers</span>
        </div>

        <div class="card-body p-0">
            <?php if (empty($customers)): ?>
                <div class="text-center py-5">
                    <div class="mb-3">
                        <i class="bi bi-person-x text-muted" style="font-size: 3rem;"></i>
                    </div>
                    <h5 class="fw-semibold">No Customers Found</h5>
                    <p class="text-muted">No registered customer accounts matched your search criteria.</p>
                    <?php if (!empty($filters['search']) || !empty($filters['status']) || !empty($filters['auth_provider'])): ?>
                        <a href="<?= e(url('admin/customers/')) ?>" class="btn btn-sm btn-primary">
                            <i class="bi bi-arrow-counterclockwise me-1"></i> Reset Filters
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>
                                    <?php
                                    $nextOrder = ($filters['sort'] === 'name' && $filters['order'] === 'asc') ? 'desc' : 'asc';
                                    $sortUrl = url('admin/customers/?' . http_build_query(array_merge($filters, ['sort' => 'name', 'order' => $nextOrder])));
                                    ?>
                                    <a href="<?= e($sortUrl) ?>" class="text-reset text-decoration-none">
                                        Customer Name
                                        <?php if ($filters['sort'] === 'name'): ?>
                                            <i class="bi bi-arrow-<?= $filters['order'] === 'asc' ? 'up' : 'down' ?> small"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <?php
                                    $nextOrder = ($filters['sort'] === 'email' && $filters['order'] === 'asc') ? 'desc' : 'asc';
                                    $sortUrl = url('admin/customers/?' . http_build_query(array_merge($filters, ['sort' => 'email', 'order' => $nextOrder])));
                                    ?>
                                    <a href="<?= e($sortUrl) ?>" class="text-reset text-decoration-none">
                                        Email Address
                                        <?php if ($filters['sort'] === 'email'): ?>
                                            <i class="bi bi-arrow-<?= $filters['order'] === 'asc' ? 'up' : 'down' ?> small"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>Phone</th>
                                <th class="text-center">
                                    <?php
                                    $nextOrder = ($filters['sort'] === 'orders' && $filters['order'] === 'asc') ? 'desc' : 'asc';
                                    $sortUrl = url('admin/customers/?' . http_build_query(array_merge($filters, ['sort' => 'orders', 'order' => $nextOrder])));
                                    ?>
                                    <a href="<?= e($sortUrl) ?>" class="text-reset text-decoration-none">
                                        Orders
                                        <?php if ($filters['sort'] === 'orders'): ?>
                                            <i class="bi bi-arrow-<?= $filters['order'] === 'asc' ? 'up' : 'down' ?> small"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th class="text-end">
                                    <?php
                                    $nextOrder = ($filters['sort'] === 'spent' && $filters['order'] === 'asc') ? 'desc' : 'asc';
                                    $sortUrl = url('admin/customers/?' . http_build_query(array_merge($filters, ['sort' => 'spent', 'order' => $nextOrder])));
                                    ?>
                                    <a href="<?= e($sortUrl) ?>" class="text-reset text-decoration-none">
                                        Total Spent (KES)
                                        <?php if ($filters['sort'] === 'spent'): ?>
                                            <i class="bi bi-arrow-<?= $filters['order'] === 'asc' ? 'up' : 'down' ?> small"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <?php
                                    $nextOrder = ($filters['sort'] === 'created_at' && $filters['order'] === 'asc') ? 'desc' : 'asc';
                                    $sortUrl = url('admin/customers/?' . http_build_query(array_merge($filters, ['sort' => 'created_at', 'order' => $nextOrder])));
                                    ?>
                                    <a href="<?= e($sortUrl) ?>" class="text-reset text-decoration-none">
                                        Joined Date
                                        <?php if ($filters['sort'] === 'created_at'): ?>
                                            <i class="bi bi-arrow-<?= $filters['order'] === 'asc' ? 'up' : 'down' ?> small"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th class="text-center">
                                    <?php
                                    $nextOrder = ($filters['sort'] === 'status' && $filters['order'] === 'asc') ? 'desc' : 'asc';
                                    $sortUrl = url('admin/customers/?' . http_build_query(array_merge($filters, ['sort' => 'status', 'order' => $nextOrder])));
                                    ?>
                                    <a href="<?= e($sortUrl) ?>" class="text-reset text-decoration-none">
                                        Status
                                        <?php if ($filters['sort'] === 'status'): ?>
                                            <i class="bi bi-arrow-<?= $filters['order'] === 'asc' ? 'up' : 'down' ?> small"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th class="text-end pe-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($customers as $c): ?>
                                <?php
                                $fullName = trim($c['first_name'] . ' ' . $c['last_name']) ?: 'Customer #' . $c['id'];
                                $isGoogle = !empty($c['google_id']);
                                ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="rounded-circle bg-light text-primary fw-bold d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; font-size: 13px;">
                                                <?= e(strtoupper(substr($c['first_name'] ?: 'C', 0, 1))) ?>
                                            </div>
                                            <div>
                                                <a href="<?= e(url('admin/customers/detail.php?id=' . $c['id'])) ?>" class="fw-semibold text-primary">
                                                    <?= e($fullName) ?>
                                                </a>
                                                <?php if ($isGoogle): ?>
                                                    <span class="badge bg-light text-muted border ms-1" title="Signed up with Google OAuth">
                                                        <i class="bi bi-google text-danger"></i> Google
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="text-dark"><?= e($c['email']) ?></span>
                                        <?php if (!empty($c['email_verified_at'])): ?>
                                            <i class="bi bi-patch-check-fill text-success ms-1" title="Email Verified"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="text-muted"><?= e($c['phone'] ?: '&mdash;') ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-dark border"><?= (int)$c['orders_count'] ?></span>
                                    </td>
                                    <td class="text-end fw-semibold text-dark">
                                        <?= e(format_money((float)$c['total_spent'])) ?>
                                    </td>
                                    <td>
                                        <span class="text-muted small"><?= e(date('d M Y', strtotime($c['created_at']))) ?></span>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($c['status'] === 'active'): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1">Blocked</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-3">
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                Actions
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                                <li>
                                                    <a class="dropdown-item" href="<?= e(url('admin/customers/detail.php?id=' . $c['id'])) ?>">
                                                        <i class="bi bi-eye me-2 text-primary"></i> View Profile
                                                    </a>
                                                </li>
                                                <?php if ($isAdmin): ?>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <?php if ($c['status'] === 'active'): ?>
                                                        <li>
                                                            <button type="button" class="dropdown-item text-danger" data-bs-toggle="modal" data-bs-target="#blockModal<?= $c['id'] ?>">
                                                                <i class="bi bi-slash-circle me-2"></i> Block Customer
                                                            </button>
                                                        </li>
                                                    <?php else: ?>
                                                        <li>
                                                            <button type="button" class="dropdown-item text-success" data-bs-toggle="modal" data-bs-target="#unblockModal<?= $c['id'] ?>">
                                                                <i class="bi bi-check-circle me-2"></i> Unblock Customer
                                                            </button>
                                                        </li>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </ul>
                                        </div>

                                        <!-- Modals for Block/Unblock -->
                                        <?php if ($isAdmin): ?>
                                            <?php if ($c['status'] === 'active'): ?>
                                                <div class="modal fade" id="blockModal<?= $c['id'] ?>" tabindex="-1" aria-hidden="true">
                                                    <div class="modal-dialog text-start">
                                                        <form action="<?= e(url('admin/customers/')) ?>" method="POST" class="modal-content">
                                                            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
                                                            <input type="hidden" name="action" value="set_status">
                                                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                                            <input type="hidden" name="status" value="blocked">
                                                            <input type="hidden" name="redirect_query" value="<?= e($currentQuery) ?>">

                                                            <div class="modal-header bg-danger-subtle">
                                                                <h5 class="modal-title text-danger fw-bold">Block Customer Account</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <p>Are you sure you want to block <strong><?= e($fullName) ?></strong> (<?= e($c['email']) ?>)?</p>
                                                                <div class="alert alert-warning small">
                                                                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                                                                    Blocked customers will be immediately logged out, prevented from signing in, and cannot place any new orders.
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                                                <button type="submit" class="btn btn-danger btn-sm">Confirm Block</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <div class="modal fade" id="unblockModal<?= $c['id'] ?>" tabindex="-1" aria-hidden="true">
                                                    <div class="modal-dialog text-start">
                                                        <form action="<?= e(url('admin/customers/')) ?>" method="POST" class="modal-content">
                                                            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
                                                            <input type="hidden" name="action" value="set_status">
                                                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                                            <input type="hidden" name="status" value="active">
                                                            <input type="hidden" name="redirect_query" value="<?= e($currentQuery) ?>">

                                                            <div class="modal-header bg-success-subtle">
                                                                <h5 class="modal-title text-success fw-bold">Unblock Customer Account</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <p>Are you sure you want to restore access for <strong><?= e($fullName) ?></strong> (<?= e($c['email']) ?>)?</p>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                                                <button type="submit" class="btn btn-success btn-sm">Confirm Unblock</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="card-footer d-flex flex-wrap justify-content-between align-items-center py-2 px-3 border-top">
                        <div class="text-muted small">
                            Page <?= $page ?> of <?= $totalPages ?> (<?= number_format($totalItems) ?> total customers)
                        </div>
                        <nav aria-label="Customers pagination">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= e(url('admin/customers/?' . http_build_query(array_merge($filters, ['page' => $page - 1])))) ?>">Previous</a>
                                </li>
                                <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= e(url('admin/customers/?' . http_build_query(array_merge($filters, ['page' => $p])))) ?>"><?= $p ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= e(url('admin/customers/?' . http_build_query(array_merge($filters, ['page' => $page + 1])))) ?>">Next</a>
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
