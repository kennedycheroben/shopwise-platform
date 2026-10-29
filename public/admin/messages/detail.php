<?php
require dirname(__DIR__, 3) . '/app/bootstrap.php';
require dirname(__DIR__, 3) . '/app/views/admin/guard.php';

use App\Services\ContactMessageService;
use App\Services\Permissions;
use App\Services\AdminAuthService;

Permissions::require('messages.manage');
$currentUser = AdminAuthService::user();
$actorId = (int)$currentUser['id'];
$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

$rawId = $_GET['id'] ?? null;
if ($rawId === null || !is_numeric($rawId) || (int)$rawId <= 0) {
    http_response_code(404);
    require dirname(__DIR__, 3) . '/app/views/admin/error-404.php';
    exit;
}
$id = (int)$rawId;
$message = ContactMessageService::get($id);

if (!$message) {
    http_response_code(404);
    require dirname(__DIR__, 3) . '/app/views/admin/error-404.php';
    exit;
}

// Automatically mark new message as read upon opening
if ($message['status'] === 'new') {
    try {
        ContactMessageService::updateStatus($id, 'read', $actorId, $ip);
        $message['status'] = 'read';
    } catch (\Throwable $e) {
        // Continue rendering even if status update fails
    }
}

// Handle POST actions (Reply OR Status change)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['_csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Forbidden: Invalid CSRF token.');
    }

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'reply') {
            $replyText = $_POST['reply_body'] ?? '';
            ContactMessageService::reply($id, $replyText, $actorId, $ip);
            flash_set('success', 'Reply email queued and sent to ' . htmlspecialchars($message['email'], ENT_QUOTES, 'UTF-8') . '.');
        } elseif ($action === 'update_status') {
            $newStatus = trim($_POST['status'] ?? '');
            ContactMessageService::updateStatus($id, $newStatus, $actorId, $ip);
            flash_set('success', "Message status updated to '{$newStatus}'.");
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }

    redirect('admin/messages/detail.php?id=' . $id);
}

$pageTitle = 'Inquiry: ' . $message['subject'];
$activeGroup = 'content';

require dirname(__DIR__, 3) . '/app/views/admin/layout.php';
?>

<div class="main-content page-message-detail">
    <!-- Page Header -->
    <div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= e(url('admin/')) ?>">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?= e(url('admin/messages/')) ?>">Messages</a></li>
                    <li class="breadcrumb-item active" aria-current="page">#<?= (int)$message['id'] ?></li>
                </ol>
            </nav>
            <h1 class="page-title h3 mb-0"><?= e($message['subject']) ?></h1>
            <p class="text-muted small mb-0">Received on <?= date('F j, Y \a\t g:i A', strtotime($message['created_at'])) ?></p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= e(url('admin/messages/')) ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Back to Inbox
            </a>
            <?php if ($message['status'] !== 'archived'): ?>
                <form method="POST" action="<?= e(url('admin/messages/detail.php?id=' . $id)) ?>" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="status" value="archived">
                    <button type="submit" class="btn btn-outline-secondary">
                        <i class="bi bi-archive me-1"></i> Archive
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-4">
        <!-- Message Thread and Reply Form -->
        <div class="col-lg-8">
            <!-- Customer Message Card -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center pt-4 pb-2 px-4">
                    <div class="d-flex align-items-center">
                        <div class="avatar bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 44px; height: 44px; font-weight: 600;">
                            <?= strtoupper(substr($message['name'], 0, 1)) ?>
                        </div>
                        <div>
                            <h6 class="mb-0 fw-bold text-dark"><?= e($message['name']) ?></h6>
                            <div class="text-muted small"><a href="mailto:<?= e($message['email']) ?>" class="text-decoration-none text-muted"><?= e($message['email']) ?></a></div>
                        </div>
                    </div>
                    <div>
                        <?php if ($message['status'] === 'replied'): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2">
                                <i class="bi bi-check-all me-1"></i> Replied
                            </span>
                        <?php elseif ($message['status'] === 'archived'): ?>
                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-3 py-2">
                                <i class="bi bi-archive me-1"></i> Archived
                            </span>
                        <?php else: ?>
                            <span class="badge bg-info-subtle text-info border border-info-subtle px-3 py-2">
                                <i class="bi bi-envelope-open me-1"></i> Read
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body px-4 py-3">
                    <div class="p-3 bg-light rounded-3 text-dark mb-2" style="white-space: pre-wrap; font-size: 0.95rem; line-height: 1.6;">
<?= e($message['message']) ?>
                    </div>
                </div>
            </div>

            <!-- Reply Form Card -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent border-0 pt-4 pb-2 px-4">
                    <h5 class="card-title mb-1"><i class="bi bi-reply me-2 text-primary"></i>Send Text-Only Reply</h5>
                    <p class="text-muted small mb-0">
                        Response is delivered directly to the customer's email with store support headers. 
                        HTML is stripped for security; plain paragraphs and line breaks are preserved.
                    </p>
                </div>
                <div class="card-body px-4 pb-4">
                    <form method="POST" action="<?= e(url('admin/messages/detail.php?id=' . $id)) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reply">

                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Reply Message Body <span class="text-danger">*</span></label>
                            <textarea name="reply_body" class="form-control" rows="6" placeholder="Type your professional response to <?= e($message['name']) ?>..." required></textarea>
                            <div class="form-text small">
                                Reply-To will be set to <strong><?= e(setting('support_email', 'support@kennedycheroben.co.ke')) ?></strong>.
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted small">
                                <i class="bi bi-shield-check text-success me-1"></i> Header injection &amp; CRLF protection enabled
                            </span>
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="bi bi-send me-1"></i> Send Customer Reply
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Sender Meta Sidebar -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-0 pt-4 pb-2 px-4">
                    <h5 class="card-title h6 fw-bold mb-0"><i class="bi bi-person-lines-fill me-2 text-info"></i>Sender Contact Card</h5>
                </div>
                <div class="card-body px-4 pb-4">
                    <dl class="row small mb-0">
                        <dt class="col-sm-4 text-muted">Full Name:</dt>
                        <dd class="col-sm-8 fw-semibold text-dark"><?= e($message['name']) ?></dd>

                        <dt class="col-sm-4 text-muted">Email:</dt>
                        <dd class="col-sm-8">
                            <a href="mailto:<?= e($message['email']) ?>" class="text-primary text-decoration-none"><?= e($message['email']) ?></a>
                        </dd>

                        <dt class="col-sm-4 text-muted">Phone:</dt>
                        <dd class="col-sm-8">
                            <?php if (!empty($message['phone'])): ?>
                                <a href="tel:<?= e($message['phone']) ?>" class="text-decoration-none"><?= e($message['phone']) ?></a>
                            <?php else: ?>
                                <span class="text-muted">Not provided</span>
                            <?php endif; ?>
                        </dd>

                        <dt class="col-sm-4 text-muted">Status:</dt>
                        <dd class="col-sm-8">
                            <span class="badge bg-light text-dark border"><?= e(strtoupper($message['status'])) ?></span>
                        </dd>

                        <dt class="col-sm-4 text-muted">Timestamp:</dt>
                        <dd class="col-sm-8 text-muted"><?= date('Y-m-d H:i:s', strtotime($message['created_at'])) ?></dd>
                    </dl>
                </div>
            </div>

            <div class="card border-0 shadow-sm bg-light-subtle">
                <div class="card-body p-3 small text-muted">
                    <i class="bi bi-info-circle text-primary me-1"></i>
                    Replying automatically queues the email via <code>MailerService</code> and marks the inquiry status as <strong>replied</strong> in the audit log.
                </div>
            </div>
        </div>
    </div>
</div>

<?php require dirname(__DIR__, 3) . '/app/views/admin/footer.php'; ?>
