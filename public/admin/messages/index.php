<?php
require dirname(__DIR__, 3) . '/app/bootstrap.php';
require dirname(__DIR__, 3) . '/app/views/admin/guard.php';

use App\Services\ContactMessageService;
use App\Services\Permissions;
use App\Services\AdminAuthService;

Permissions::require('messages.manage');
$currentUser = AdminAuthService::user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['_csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Forbidden: Invalid CSRF token.');
    }

    $action = $_POST['action'] ?? '';
    $actorId = (int)$currentUser['id'];
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    try {
        if ($action === 'update_status') {
            $id = (int)($_POST['id'] ?? 0);
            $status = trim($_POST['status'] ?? '');
            ContactMessageService::updateStatus($id, $status, $actorId, $ip);
            flash_set('success', "Message marked as '{$status}'.");
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }

    redirect('admin/messages/');
}

$filters = [
    'status' => $_GET['status'] ?? '',
    'search' => $_GET['search'] ?? '',
];
$messages = ContactMessageService::list($filters);
$newCount = ContactMessageService::getNewCount();

$pageTitle = 'Customer Contact Inquiries';
$activeGroup = 'content';

require dirname(__DIR__, 3) . '/app/views/admin/layout.php';
?>

<div class="main-content page-messages">
    <!-- Page Header -->
    <div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= e(url('admin/')) ?>">Dashboard</a></li>
                    <li class="breadcrumb-item">Content</li>
                    <li class="breadcrumb-item active" aria-current="page">Messages</li>
                </ol>
            </nav>
            <h1 class="page-title h3 mb-0">Customer Inquiries &amp; Support Inbox</h1>
            <p class="text-muted small mb-0">Manage incoming pre-sales questions, feedback, and support inquiries submitted through the contact portal.</p>
        </div>
        <div>
            <?php if ($newCount > 0): ?>
                <span class="badge bg-danger fs-6 px-3 py-2">
                    <i class="bi bi-envelope-exclamation me-1"></i> <?= $newCount ?> New Inquiries
                </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="<?= e(url('admin/messages/')) ?>" class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small fw-semibold">Search Sender or Message</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" name="search" class="form-control" placeholder="Search name, email, phone, subject..." value="<?= e($filters['search']) ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Status Filter</label>
                    <select name="status" class="form-select">
                        <option value="">All Inquiries</option>
                        <option value="new" <?= $filters['status'] === 'new' ? 'selected' : '' ?>>New / Unread (<?= $newCount ?>)</option>
                        <option value="read" <?= $filters['status'] === 'read' ? 'selected' : '' ?>>Read</option>
                        <option value="replied" <?= $filters['status'] === 'replied' ? 'selected' : '' ?>>Replied</option>
                        <option value="archived" <?= $filters['status'] === 'archived' ? 'selected' : '' ?>>Archived</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-grow-1">
                        <i class="bi bi-filter me-1"></i> Filter
                    </button>
                    <?php if (!empty($filters['search']) || !empty($filters['status'])): ?>
                        <a href="<?= e(url('admin/messages/')) ?>" class="btn btn-outline-secondary" title="Clear Filters">
                            <i class="bi bi-x-circle"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Messages Table Card -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center py-3">
            <h5 class="card-title mb-0">Inbox (<?= count($messages) ?>)</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th scope="col" style="width: 50px;">#</th>
                        <th scope="col" style="min-width: 180px;">Sender</th>
                        <th scope="col">Subject &amp; Snippet</th>
                        <th scope="col" class="text-center" style="width: 120px;">Status</th>
                        <th scope="col" class="text-nowrap" style="width: 150px;">Date Received</th>
                        <th scope="col" class="text-end" style="width: 130px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($messages)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox fs-1 d-block mb-2 text-secondary"></i>
                                No customer messages found in this view.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($messages as $idx => $m): ?>
                            <tr class="<?= $m['status'] === 'new' ? 'table-warning bg-opacity-10 fw-semibold' : '' ?>">
                                <td class="text-muted small"><?= $idx + 1 ?></td>
                                <td>
                                    <div class="text-dark"><?= e($m['name']) ?></div>
                                    <div class="small text-muted"><?= e($m['email']) ?></div>
                                    <?php if (!empty($m['phone'])): ?>
                                        <div class="small text-secondary"><i class="bi bi-telephone me-1"></i><?= e($m['phone']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?= e(url('admin/messages/detail.php?id=' . $m['id'])) ?>" class="text-decoration-none text-dark fw-bold d-block">
                                        <?= e($m['subject']) ?>
                                    </a>
                                    <div class="text-muted small text-truncate" style="max-width: 480px;">
                                        <?= e(mb_substr($m['message'], 0, 110)) ?>...
                                    </div>
                                </td>
                                <td class="text-center">
                                    <?php if ($m['status'] === 'new'): ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle">New</span>
                                    <?php elseif ($m['status'] === 'read'): ?>
                                        <span class="badge bg-info-subtle text-info border border-info-subtle">Read</span>
                                    <?php elseif ($m['status'] === 'replied'): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle">Replied</span>
                                    <?php elseif ($m['status'] === 'archived'): ?>
                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Archived</span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-dark border"><?= e($m['status']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-nowrap small text-muted">
                                    <?= date('M j, Y g:i A', strtotime($m['created_at'])) ?>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <a href="<?= e(url('admin/messages/detail.php?id=' . $m['id'])) ?>" class="btn btn-primary" title="View &amp; Reply">
                                            <i class="bi bi-reply-fill"></i>
                                        </a>
                                        <?php if ($m['status'] !== 'archived'): ?>
                                            <form method="POST" action="<?= e(url('admin/messages/')) ?>" class="d-inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="id" value="<?= e($m['id']) ?>">
                                                <input type="hidden" name="status" value="archived">
                                                <button type="submit" class="btn btn-outline-secondary" title="Archive Message">
                                                    <i class="bi bi-archive"></i>
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
