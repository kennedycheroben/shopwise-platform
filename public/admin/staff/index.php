<?php
require dirname(__DIR__, 3) . '/app/bootstrap.php';
require dirname(__DIR__, 3) . '/app/views/admin/guard.php';

use App\Services\StaffService;
use App\Services\Permissions;
use App\Services\AdminAuthService;

Permissions::require('staff.manage');
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
        if ($action === 'create') {
            StaffService::create($_POST, $actorId, $ip);
            flash_set('success', 'Staff account created successfully. A secure password setup invitation has been queued to their email.');
        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            StaffService::update($id, $_POST, $actorId, $ip);
            flash_set('success', 'Staff details updated successfully.');
        } elseif ($action === 'toggle_status') {
            $id = (int)($_POST['id'] ?? 0);
            $newStatus = StaffService::toggleStatus($id, $actorId, $ip);
            flash_set('success', 'Staff account ' . ($newStatus === 'active' ? 'activated' : 'blocked / deactivated') . ' successfully.');
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }

    redirect('admin/staff/');
}

$filters = [
    'role'   => $_GET['role'] ?? '',
    'status' => $_GET['status'] ?? '',
    'search' => $_GET['search'] ?? '',
];
$staffMembers = StaffService::list($filters);

$pageTitle = 'Team & Staff Management';
$activeGroup = 'team';

require dirname(__DIR__, 3) . '/app/views/admin/layout.php';
?>

<div class="main-content page-staff">
    <!-- Page Header -->
    <div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= e(url('admin/')) ?>">Dashboard</a></li>
                    <li class="breadcrumb-item">Team</li>
                    <li class="breadcrumb-item active" aria-current="page">Staff Members</li>
                </ol>
            </nav>
            <h1 class="page-title h3 mb-0">Staff Members &amp; Administrators</h1>
            <p class="text-muted small mb-0">Manage internal operator accounts, roles, access levels, and security states.</p>
        </div>
        <div>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createStaffModal">
                <i class="bi bi-person-plus-fill me-1"></i> Add Team Member
            </button>
        </div>
    </div>

    <!-- Security Information Alert -->
    <div class="alert alert-info border-0 shadow-sm d-flex align-items-center mb-4">
        <i class="bi bi-shield-check fs-4 me-3 text-primary"></i>
        <div class="small">
            <strong>Passwordless Onboarding:</strong> In accordance with security standards, administrators cannot set or view operator passwords. When an account is created, an unguessable token is queued to their work email with a 60-minute single-use activation window.
        </div>
    </div>

    <!-- Filter Card -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="<?= e(url('admin/staff/')) ?>" class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small fw-semibold">Search Staff</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" name="search" class="form-control" placeholder="Search name or work email..." value="<?= e($filters['search']) ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Role Filter</label>
                    <select name="role" class="form-select">
                        <option value="">All Roles</option>
                        <option value="admin" <?= $filters['role'] === 'admin' ? 'selected' : '' ?>>Administrator</option>
                        <option value="staff" <?= $filters['role'] === 'staff' ? 'selected' : '' ?>>Staff Member</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Statuses</option>
                        <option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="blocked" <?= $filters['status'] === 'blocked' ? 'selected' : '' ?>>Blocked</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-grow-1">
                        <i class="bi bi-filter me-1"></i> Filter
                    </button>
                    <?php if (!empty($filters['search']) || !empty($filters['role']) || !empty($filters['status'])): ?>
                        <a href="<?= e(url('admin/staff/')) ?>" class="btn btn-outline-secondary" title="Clear Filters">
                            <i class="bi bi-x-circle"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Staff Table Card -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center py-3">
            <h5 class="card-title mb-0">Internal Team (<?= count($staffMembers) ?>)</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th scope="col" style="width: 50px;">#</th>
                        <th scope="col">Name &amp; Identity</th>
                        <th scope="col">Role &amp; Privileges</th>
                        <th scope="col" class="text-center">Account Status</th>
                        <th scope="col">Joined Date</th>
                        <th scope="col" class="text-end" style="width: 150px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($staffMembers)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <i class="bi bi-people fs-1 d-block mb-2 text-secondary"></i>
                                No team members found matching your search.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($staffMembers as $idx => $s): ?>
                            <?php $isSelf = ((int)$s['id'] === (int)$currentUser['id']); ?>
                            <tr>
                                <td class="text-muted small"><?= $idx + 1 ?></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="avatar bg-secondary-subtle text-secondary rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px; font-weight: 600;">
                                            <?= strtoupper(substr($s['first_name'], 0, 1) . substr($s['last_name'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark">
                                                <?= e($s['first_name'] . ' ' . $s['last_name']) ?>
                                                <?php if ($isSelf): ?>
                                                    <span class="badge bg-primary-subtle text-primary ms-1" style="font-size: 0.7rem;">You</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-muted small"><?= e($s['email']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($s['role'] === 'admin'): ?>
                                        <span class="badge bg-primary text-uppercase px-2 py-1">
                                            <i class="bi bi-shield-lock me-1"></i> Administrator
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary text-uppercase px-2 py-1">
                                            <i class="bi bi-person-badge me-1"></i> Staff Member
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($s['status'] === 'active'): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Blocked</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-muted">
                                    <?= date('M j, Y', strtotime($s['created_at'])) ?>
                                </td>
                                <td class="text-end">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-light dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            Manage
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                            <li>
                                                <button type="button" class="dropdown-item edit-staff-btn"
                                                        data-id="<?= e($s['id']) ?>"
                                                        data-first="<?= e($s['first_name']) ?>"
                                                        data-last="<?= e($s['last_name']) ?>"
                                                        data-role="<?= e($s['role']) ?>"
                                                        data-self="<?= $isSelf ? '1' : '0' ?>">
                                                    <i class="bi bi-pencil me-2 text-warning"></i> Edit Details
                                                </button>
                                            </li>
                                            <?php if (!$isSelf): ?>
                                                <li>
                                                    <form method="POST" action="<?= e(url('admin/staff/')) ?>" onsubmit="return confirm('Change status for <?= e($s['first_name']) ?>?');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="toggle_status">
                                                        <input type="hidden" name="id" value="<?= e($s['id']) ?>">
                                                        <button type="submit" class="dropdown-item">
                                                            <?php if ($s['status'] === 'active'): ?>
                                                                <i class="bi bi-lock me-2 text-danger"></i> Deactivate / Block
                                                            <?php else: ?>
                                                                <i class="bi bi-unlock me-2 text-success"></i> Reactivate
                                                            <?php endif; ?>
                                                        </button>
                                                    </form>
                                                </li>
                                            <?php endif; ?>
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

<!-- Modal: Create Staff Member -->
<div class="modal fade" id="createStaffModal" tabindex="-1" aria-labelledby="createStaffModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="<?= e(url('admin/staff/')) ?>" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="modal-header">
                <h5 class="modal-title" id="createStaffModalLabel"><i class="bi bi-person-plus me-2 text-primary"></i>Add New Team Member</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold">First Name <span class="text-danger">*</span></label>
                        <input type="text" name="first_name" class="form-control" placeholder="e.g. Victor" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Last Name <span class="text-danger">*</span></label>
                        <input type="text" name="last_name" class="form-control" placeholder="e.g. Omondi" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Work Email <span class="text-danger">*</span></label>
                    <input type="email" name="email" class="form-control" placeholder="e.g. victor.omondi@shopwise.co.ke" required>
                    <div class="form-text small">An invitation with a secure password setup link will be queued immediately.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Role &amp; Permissions <span class="text-danger">*</span></label>
                    <select name="role" class="form-select" required>
                        <option value="staff">Staff Member (Orders, Products, Inventory, Reviews, Messages)</option>
                        <option value="admin">Administrator (Full Access including Settings, Staff, Coupons &amp; Audit Logs)</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Create &amp; Send Setup Link</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit Staff Member -->
<div class="modal fade" id="editStaffModal" tabindex="-1" aria-labelledby="editStaffModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="<?= e(url('admin/staff/')) ?>" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="editStaffId">
            <div class="modal-header">
                <h5 class="modal-title" id="editStaffModalLabel"><i class="bi bi-pencil-square me-2 text-warning"></i>Edit Staff Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold">First Name <span class="text-danger">*</span></label>
                        <input type="text" name="first_name" id="editFirstName" class="form-control" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Last Name <span class="text-danger">*</span></label>
                        <input type="text" name="last_name" id="editLastName" class="form-control" required>
                    </div>
                </div>
                <div class="mb-3" id="editRoleGroup">
                    <label class="form-label small fw-semibold">Role <span class="text-danger">*</span></label>
                    <select name="role" id="editRole" class="form-select" required>
                        <option value="staff">Staff Member</option>
                        <option value="admin">Administrator</option>
                    </select>
                    <div id="selfRoleWarning" class="form-text text-danger small" style="display: none;">
                        You cannot change your own administrative role.
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Update Staff</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const editBtns = document.querySelectorAll('.edit-staff-btn');
    const modalEl = document.getElementById('editStaffModal');
    if (modalEl && typeof bootstrap !== 'undefined') {
        const bsModal = new bootstrap.Modal(modalEl);
        editBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                const isSelf = (this.dataset.self === '1');
                document.getElementById('editStaffId').value = this.dataset.id;
                document.getElementById('editFirstName').value = this.dataset.first;
                document.getElementById('editLastName').value = this.dataset.last;
                const roleSelect = document.getElementById('editRole');
                roleSelect.value = this.dataset.role;

                const selfWarn = document.getElementById('selfRoleWarning');
                if (isSelf) {
                    roleSelect.disabled = true;
                    selfWarn.style.display = 'block';
                } else {
                    roleSelect.disabled = false;
                    selfWarn.style.display = 'none';
                }

                bsModal.show();
            });
        });
    }
});
</script>

<?php require dirname(__DIR__, 3) . '/app/views/admin/footer.php'; ?>
