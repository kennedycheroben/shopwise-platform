<?php
require dirname(__DIR__, 3) . '/app/bootstrap.php';
require dirname(__DIR__, 3) . '/app/views/admin/guard.php';

use App\Services\ReviewService;
use App\Services\Permissions;
use App\Services\AdminAuthService;

Permissions::require('reviews.moderate');
$currentUser = AdminAuthService::user();
$isAdmin = ($currentUser['role'] === 'admin');

// Validate tampered ID on GET request
if (isset($_GET['id'])) {
    $rawId = $_GET['id'];
    if (!is_numeric($rawId) || (int)$rawId <= 0 || !ReviewService::get((int)$rawId)) {
        http_response_code(404);
        require dirname(__DIR__, 3) . '/app/views/admin/error-404.php';
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['_csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Forbidden: Invalid CSRF token.');
    }

    if (isset($_POST['id'])) {
        $rawPostId = $_POST['id'];
        if (!is_numeric($rawPostId) || (int)$rawPostId <= 0 || !ReviewService::get((int)$rawPostId)) {
            http_response_code(404);
            require dirname(__DIR__, 3) . '/app/views/admin/error-404.php';
            exit;
        }
    }

    $action = $_POST['action'] ?? '';
    $actorId = (int)$currentUser['id'];
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    try {
        if ($action === 'moderate') {
            $id = (int)($_POST['id'] ?? 0);
            $status = trim($_POST['status'] ?? '');
            ReviewService::moderate($id, $status, $actorId, $ip);
            flash_set('success', "Review marked as '{$status}' successfully.");
        } elseif ($action === 'delete') {
            if (!$isAdmin) {
                http_response_code(403);
                exit('Forbidden: Only administrators can delete reviews.');
            }
            $id = (int)($_POST['id'] ?? 0);
            ReviewService::delete($id, $actorId, $ip);
            flash_set('success', 'Review deleted permanently.');
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }

    $redirectUrl = 'admin/reviews/';
    if (!empty($_GET['status'])) {
        $redirectUrl .= '?status=' . urlencode($_GET['status']);
    }
    redirect($redirectUrl);
}

$filters = [
    'status'     => $_GET['status'] ?? '',
    'rating'     => $_GET['rating'] ?? '',
    'product_id' => $_GET['product_id'] ?? '',
    'search'     => $_GET['search'] ?? '',
];
$reviews = ReviewService::list($filters);
$pendingCount = ReviewService::getPendingCount();

$pageTitle = 'Product Reviews Moderation';
$activeGroup = 'content';

require dirname(__DIR__, 3) . '/app/views/admin/layout.php';
?>

<div class="main-content page-reviews">
    <!-- Page Header -->
    <div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= e(url('admin/')) ?>">Dashboard</a></li>
                    <li class="breadcrumb-item">Content</li>
                    <li class="breadcrumb-item active" aria-current="page">Reviews</li>
                </ol>
            </nav>
            <h1 class="page-title h3 mb-0">Customer Reviews &amp; Ratings</h1>
            <p class="text-muted small mb-0">Moderate buyer product feedback, verify authentic purchases, and safeguard store integrity.</p>
        </div>
        <div>
            <?php if ($pendingCount > 0): ?>
                <a href="<?= e(url('admin/reviews/?status=pending')) ?>" class="btn btn-warning position-relative">
                    <i class="bi bi-clock-history me-1"></i> Moderation Queue
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                        <?= $pendingCount ?>
                    </span>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="<?= e(url('admin/reviews/')) ?>" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Search Content or Reviewer</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" name="search" class="form-control" placeholder="Search title, review, product..." value="<?= e($filters['search']) ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Moderation Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Statuses</option>
                        <option value="pending" <?= $filters['status'] === 'pending' ? 'selected' : '' ?>>Pending Review (<?= $pendingCount ?>)</option>
                        <option value="approved" <?= $filters['status'] === 'approved' ? 'selected' : '' ?>>Approved (Public)</option>
                        <option value="rejected" <?= $filters['status'] === 'rejected' ? 'selected' : '' ?>>Rejected (Hidden)</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Rating</label>
                    <select name="rating" class="form-select">
                        <option value="">All Ratings</option>
                        <option value="5" <?= $filters['rating'] === '5' ? 'selected' : '' ?>>5 Stars &starf;&starf;&starf;&starf;&starf;</option>
                        <option value="4" <?= $filters['rating'] === '4' ? 'selected' : '' ?>>4 Stars &starf;&starf;&starf;&starf;</option>
                        <option value="3" <?= $filters['rating'] === '3' ? 'selected' : '' ?>>3 Stars &starf;&starf;&starf;</option>
                        <option value="2" <?= $filters['rating'] === '2' ? 'selected' : '' ?>>2 Stars &starf;&starf;</option>
                        <option value="1" <?= $filters['rating'] === '1' ? 'selected' : '' ?>>1 Star &starf;</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-grow-1">
                        <i class="bi bi-filter me-1"></i> Filter
                    </button>
                    <?php if (!empty($filters['search']) || !empty($filters['status']) || !empty($filters['rating'])): ?>
                        <a href="<?= e(url('admin/reviews/')) ?>" class="btn btn-outline-secondary" title="Clear Filters">
                            <i class="bi bi-x-circle"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Reviews Table Card -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center py-3">
            <h5 class="card-title mb-0">Reviews List (<?= count($reviews) ?>)</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th scope="col" style="width: 50px;">#</th>
                        <th scope="col" style="min-width: 180px;">Product</th>
                        <th scope="col" style="min-width: 150px;">Customer</th>
                        <th scope="col" style="width: 120px;">Rating</th>
                        <th scope="col">Review Content</th>
                        <th scope="col" class="text-center" style="width: 120px;">Status</th>
                        <th scope="col" class="text-end" style="width: 150px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reviews)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="bi bi-chat-square-quote fs-1 d-block mb-2 text-secondary"></i>
                                No product reviews found matching criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($reviews as $idx => $r): ?>
                            <tr>
                                <td class="text-muted small"><?= $idx + 1 ?></td>
                                <td>
                                    <div class="fw-semibold text-dark"><?= e($r['product_name']) ?></div>
                                    <div class="text-muted small">ID: #<?= (int)$r['product_id'] ?></div>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark"><?= e($r['first_name'] . ' ' . $r['last_name']) ?></div>
                                    <div class="text-muted small"><?= e($r['user_email']) ?></div>
                                    <?php if (!empty($r['is_verified'])): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle mt-1" style="font-size: 0.7rem;">
                                            <i class="bi bi-patch-check-fill me-1"></i> Verified Buyer
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="text-warning text-nowrap">
                                        <?php for ($s = 1; $s <= 5; $s++): ?>
                                            <i class="bi bi-star<?= $s <= (int)$r['rating'] ? '-fill' : '' ?>"></i>
                                        <?php endfor; ?>
                                    </div>
                                    <span class="text-muted small"><?= (int)$r['rating'] ?> of 5</span>
                                </td>
                                <td>
                                    <?php if (!empty($r['title'])): ?>
                                        <div class="fw-bold text-dark mb-1"><?= e($r['title']) ?></div>
                                    <?php endif; ?>
                                    <div class="small text-secondary mb-1" style="white-space: pre-wrap; max-width: 450px;"><?= e($r['body']) ?></div>
                                    <div class="text-muted small" style="font-size: 0.75rem;">
                                        <i class="bi bi-calendar3 me-1"></i> <?= date('M j, Y g:i A', strtotime($r['created_at'])) ?>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <?php if ($r['status'] === 'approved'): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle">Approved</span>
                                    <?php elseif ($r['status'] === 'rejected'): ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Rejected</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning-subtle text-warning border border-warning-subtle">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <?php if ($r['status'] !== 'approved'): ?>
                                            <form method="POST" action="<?= e(url('admin/reviews/')) ?>" class="d-inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="moderate">
                                                <input type="hidden" name="id" value="<?= e($r['id']) ?>">
                                                <input type="hidden" name="status" value="approved">
                                                <button type="submit" class="btn btn-outline-success" title="Approve Review">
                                                    <i class="bi bi-check-lg"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($r['status'] !== 'rejected'): ?>
                                            <form method="POST" action="<?= e(url('admin/reviews/')) ?>" class="d-inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="moderate">
                                                <input type="hidden" name="id" value="<?= e($r['id']) ?>">
                                                <input type="hidden" name="status" value="rejected">
                                                <button type="submit" class="btn btn-outline-warning" title="Reject Review">
                                                    <i class="bi bi-slash-circle"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($isAdmin): ?>
                                            <form method="POST" action="<?= e(url('admin/reviews/')) ?>" class="d-inline" onsubmit="return confirm('Permanently delete this review?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= e($r['id']) ?>">
                                                <button type="submit" class="btn btn-outline-danger" title="Delete Review">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
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

<?php require dirname(__DIR__, 3) . '/app/views/admin/footer.php'; ?>
