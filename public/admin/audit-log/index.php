<?php
require dirname(__DIR__, 3) . '/app/bootstrap.php';
require dirname(__DIR__, 3) . '/app/views/admin/guard.php';

use App\Services\AuditLogService;
use App\Services\Permissions;
use App\Services\AdminAuthService;

Permissions::require('audit.view');
$currentUser = AdminAuthService::user();

// Validate tampered ID on GET request
if (isset($_GET['id'])) {
    $rawId = $_GET['id'];
    if (!is_numeric($rawId) || (int)$rawId <= 0 || !AuditLogService::get((int)$rawId)) {
        http_response_code(404);
        require dirname(__DIR__, 3) . '/app/views/admin/error-404.php';
        exit;
    }
}

$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$filters = [
    'action'        => $_GET['action'] ?? '',
    'entity_type'   => $_GET['entity_type'] ?? '',
    'entity_id'     => $_GET['entity_id'] ?? '',
    'actor_user_id' => $_GET['actor_user_id'] ?? '',
    'ip'            => $_GET['ip'] ?? '',
    'date_from'     => $_GET['date_from'] ?? '',
    'date_to'       => $_GET['date_to'] ?? '',
];

$result = AuditLogService::list($filters, $page, 50);
$logs = $result['items'];
$totalItems = $result['total_items'];
$currentPage = $result['current_page'];
$totalPages = $result['total_pages'];

$actions = AuditLogService::getDistinctActions();
$entityTypes = AuditLogService::getDistinctEntityTypes();

$pageTitle = 'Audit Log Viewer';
$activeGroup = 'system';

require dirname(__DIR__, 3) . '/app/views/admin/layout.php';
?>

<div class="main-content page-audit-log">
    <!-- Page Header -->
    <div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= e(url('admin/')) ?>">Dashboard</a></li>
                    <li class="breadcrumb-item">System</li>
                    <li class="breadcrumb-item active" aria-current="page">Audit Log</li>
                </ol>
            </nav>
            <h1 class="page-title h3 mb-0">System Security &amp; Audit Trail</h1>
            <p class="text-muted small mb-0">Immutable record of administrative operations, catalog changes, order actions, and system modifications.</p>
        </div>
        <div>
            <span class="badge bg-light text-dark border px-3 py-2 fs-6">
                <i class="bi bi-shield-check text-success me-1"></i> Read-Only Audit Archive
            </span>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="<?= e(url('admin/audit-log/')) ?>" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Action</label>
                    <select name="action" class="form-select">
                        <option value="">All Actions</option>
                        <?php foreach ($actions as $act): ?>
                            <option value="<?= e($act) ?>" <?= $filters['action'] === $act ? 'selected' : '' ?>><?= e($act) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Entity Type</label>
                    <select name="entity_type" class="form-select">
                        <option value="">All Entities</option>
                        <?php foreach ($entityTypes as $ent): ?>
                            <option value="<?= e($ent) ?>" <?= $filters['entity_type'] === $ent ? 'selected' : '' ?>><?= e($ent) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Entity ID</label>
                    <input type="number" name="entity_id" class="form-control" placeholder="e.g. 101" value="<?= e($filters['entity_id']) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Date From</label>
                    <input type="date" name="date_from" class="form-control" value="<?= e($filters['date_from']) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Date To</label>
                    <input type="date" name="date_to" class="form-control" value="<?= e($filters['date_to']) ?>">
                </div>
                <div class="col-md-1 d-flex gap-1">
                    <button type="submit" class="btn btn-primary w-100" title="Apply Filter">
                        <i class="bi bi-filter"></i>
                    </button>
                    <a href="<?= e(url('admin/audit-log/')) ?>" class="btn btn-outline-secondary" title="Clear">
                        <i class="bi bi-x-circle"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Audit Log Table Card -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center py-3">
            <h5 class="card-title mb-0">Audit Records (<?= number_format($totalItems) ?> total events)</h5>
            <div class="text-muted small">Page <?= $currentPage ?> of <?= $totalPages ?></div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th scope="col" style="width: 70px;">ID</th>
                        <th scope="col" style="width: 170px;">Timestamp</th>
                        <th scope="col" style="width: 180px;">Actor</th>
                        <th scope="col">Action &amp; Target</th>
                        <th scope="col" style="width: 120px;">IP Address</th>
                        <th scope="col" class="text-end" style="width: 110px;">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <i class="bi bi-journal-x fs-1 d-block mb-2 text-secondary"></i>
                                No audit log records match the selected criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td class="text-muted small">#<?= (int)$log['id'] ?></td>
                                <td class="small text-nowrap">
                                    <div class="text-dark fw-semibold"><?= date('M j, Y', strtotime($log['created_at'])) ?></div>
                                    <div class="text-muted"><?= date('H:i:s T', strtotime($log['created_at'])) ?></div>
                                </td>
                                <td>
                                    <?php if (!empty($log['actor_user_id'])): ?>
                                        <div class="fw-bold text-dark"><?= e($log['first_name'] . ' ' . $log['last_name']) ?></div>
                                        <div class="small text-muted"><?= e($log['actor_email']) ?></div>
                                        <span class="badge bg-light text-dark border" style="font-size: 0.65rem;"><?= e(strtoupper($log['actor_role'] ?? 'USER')) ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary">SYSTEM / ANONYMOUS</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace">
                                            <?= e($log['action']) ?>
                                        </span>
                                        <?php if (!empty($log['entity_type'])): ?>
                                            <span class="text-muted small">
                                                on <strong><?= e($log['entity_type']) ?></strong>
                                                <?php if (!empty($log['entity_id'])): ?>
                                                    #<?= (int)$log['entity_id'] ?>
                                                <?php endif; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="font-monospace small text-muted">
                                    <?= e($log['ip'] ?: '127.0.0.1') ?>
                                </td>
                                <td class="text-end">
                                    <?php if (!empty($log['details']) && $log['details'] !== '[]' && $log['details'] !== '{}'): ?>
                                        <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#details-<?= (int)$log['id'] ?>" aria-expanded="false">
                                            <i class="bi bi-code-slash me-1"></i> View
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted small">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if (!empty($log['details']) && $log['details'] !== '[]' && $log['details'] !== '{}'): ?>
                                <tr class="collapse bg-light" id="details-<?= (int)$log['id'] ?>">
                                    <td colspan="6" class="p-3">
                                        <div class="card border border-secondary-subtle shadow-none">
                                            <div class="card-header bg-dark text-light py-1 px-3 d-flex justify-content-between align-items-center">
                                                <span class="small font-monospace"><i class="bi bi-braces me-1"></i> Audit Payload #<?= (int)$log['id'] ?> (Redacted &amp; Escaped)</span>
                                                <span class="badge bg-secondary" style="font-size: 0.65rem;">JSON</span>
                                            </div>
                                            <div class="card-body p-0">
                                                <?php
                                                $decoded = json_decode($log['details'], true);
                                                $prettyJson = is_array($decoded) 
                                                    ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                                                    : $log['details'];
                                                ?>
                                                <pre class="bg-dark text-light p-3 rounded-bottom small mb-0 font-monospace" style="max-height: 300px; overflow-y: auto;"><code><?= e($prettyJson) ?></code></pre>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Controls -->
        <?php if ($totalPages > 1): ?>
            <div class="card-footer bg-transparent border-0 d-flex justify-content-between align-items-center py-3">
                <div class="text-muted small">
                    Showing 50 per page
                </div>
                <nav aria-label="Audit log pagination">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($filters, ['page' => $currentPage - 1])) ?>">Previous</a>
                        </li>
                        <?php for ($p = max(1, $currentPage - 3); $p <= min($totalPages, $currentPage + 3); $p++): ?>
                            <li class="page-item <?= $p === $currentPage ? 'active' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge($filters, ['page' => $p])) ?>"><?= $p ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($filters, ['page' => $currentPage + 1])) ?>">Next</a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require dirname(__DIR__, 3) . '/app/views/admin/footer.php'; ?>
