<?php
require dirname(__DIR__, 2) . '/app/bootstrap.php';
require dirname(__DIR__, 2) . '/app/views/admin/guard.php';

use App\Database\Database;

$pageTitle = 'My profile';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['_csrf_token'] ?? null)) {
        $error = 'Your form session expired. Please try again.';
    } else {
        $pdo = Database::getInstance();
        $action = $_POST['action'] ?? 'edit_profile';

        if ($action === 'change_password') {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['password_confirmation'] ?? '';

            if ($newPassword === '' || strlen($newPassword) < 8) {
                $error = 'Your new password must be at least 8 characters long.';
            } elseif ($newPassword !== $confirmPassword) {
                $error = 'The new password and confirmation do not match.';
            } else {
                $q = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
                $q->execute([':id' => $adminUser['id']]);
                $hash = (string)$q->fetchColumn();

                if (!password_verify($currentPassword, $hash)) {
                    $error = 'Your current password was entered incorrectly.';
                } else {
                    $upd = $pdo->prepare('UPDATE users SET password_hash = :hash, password_changed_at = NOW() WHERE id = :id');
                    $upd->execute([
                        ':hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                        ':id' => $adminUser['id'],
                    ]);
                    flash_set('success', 'Your password has been changed successfully.');
                    redirect('admin/profile.php');
                }
            }
        } else {
            // Edit profile details
            $first = trim($_POST['first_name'] ?? '');
            $last = trim($_POST['last_name'] ?? '');

            if ($first === '' || $last === '') {
                $error = 'First name and last name are both required.';
            } else {
                $upd = $pdo->prepare('UPDATE users SET first_name = :first, last_name = :last WHERE id = :id');
                $upd->execute([
                    ':first' => $first,
                    ':last' => $last,
                    ':id' => $adminUser['id'],
                ]);

                $q = $pdo->prepare('SELECT id, role, first_name, last_name, email, status, password_changed_at FROM users WHERE id = :id');
                $q->execute([':id' => $adminUser['id']]);
                $_SESSION['admin_user'] = $adminUser = $q->fetch();

                flash_set('success', 'Your profile details have been saved.');
                redirect('admin/profile.php');
            }
        }
    }
}

require dirname(__DIR__, 2) . '/app/views/admin/layout.php';
?>
<div class="main-content page-profile">
    <?php require dirname(__DIR__, 2) . '/app/views/admin/flash.php'; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <!-- Profile Header -->
    <div class="card profile-hero-card mb-4">
        <div class="card-body">
            <div class="profile-hero-body">
                <div class="profile-hero-avatar-wrap">
                    <img src="<?= e(asset('admin/assets/img/profile-img.webp')) ?>" alt="<?= e($adminUser['first_name']) ?>" class="profile-hero-avatar">
                    <span class="profile-hero-status"></span>
                </div>
                <div class="profile-hero-info">
                    <div class="profile-hero-name"><?= e($adminUser['first_name'] . ' ' . $adminUser['last_name']) ?></div>
                    <div class="profile-hero-role"><?= e(ucfirst($adminUser['role'])) ?> · ShopWise Kenya Operations</div>
                    <div class="profile-hero-location">
                        <i class="bi bi-geo-alt me-1"></i> Nairobi, Kenya
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Left Sidebar -->
        <div class="col-xl-4">
            <!-- Account Info -->
            <div class="card mb-4">
                <div class="card-body">
                    <h6 class="profile-card-title">Staff Account</h6>
                    <p class="profile-about-text">
                        Authorized staff member on ShopWise Kenya e-commerce platform. Staff actions and audit trails are logged for accountability and security.
                    </p>
                </div>
            </div>

            <!-- Contact Information -->
            <div class="card">
                <div class="card-body">
                    <h6 class="profile-card-title">Contact & Role</h6>
                    <ul class="profile-contact-list list-unstyled mb-0">
                        <li class="d-flex align-items-center mb-3">
                            <div class="profile-contact-icon me-3"><i class="bi bi-envelope"></i></div>
                            <div>
                                <div class="profile-contact-label text-muted small">Work Email</div>
                                <div class="profile-contact-value fw-medium"><?= e($adminUser['email']) ?></div>
                            </div>
                        </li>
                        <li class="d-flex align-items-center mb-3">
                            <div class="profile-contact-icon me-3"><i class="bi bi-shield-lock"></i></div>
                            <div>
                                <div class="profile-contact-label text-muted small">Assigned Role</div>
                                <div class="profile-contact-value fw-medium text-capitalize"><?= e($adminUser['role']) ?></div>
                            </div>
                        </li>
                        <li class="d-flex align-items-center">
                            <div class="profile-contact-icon me-3"><i class="bi bi-check-circle"></i></div>
                            <div>
                                <div class="profile-contact-label text-muted small">Status</div>
                                <div class="profile-contact-value fw-medium text-success text-capitalize"><?= e($adminUser['status']) ?></div>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Right Content -->
        <div class="col-xl-8">
            <div class="card">
                <div class="card-body">
                    <!-- Tabs -->
                    <ul class="profile-tabs nav nav-tabs mb-4" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="profile-tab nav-link active" data-bs-toggle="tab" data-bs-target="#tab-overview" type="button" role="tab" aria-selected="true">Overview</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="profile-tab nav-link" data-bs-toggle="tab" data-bs-target="#tab-edit" type="button" role="tab" aria-selected="false">Edit Profile</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="profile-tab nav-link" data-bs-toggle="tab" data-bs-target="#tab-password" type="button" role="tab" aria-selected="false">Password</button>
                        </li>
                    </ul>

                    <div class="tab-content">
                        <!-- Overview Tab -->
                        <div class="tab-pane fade show active" id="tab-overview" role="tabpanel">
                            <div class="profile-section-label fw-bold mb-3">Personal Details</div>
                            <div class="profile-detail-grid">
                                <div class="profile-detail-row d-flex justify-content-between py-2 border-bottom">
                                    <span class="profile-detail-key text-muted">First Name</span>
                                    <span class="profile-detail-val fw-medium"><?= e($adminUser['first_name']) ?></span>
                                </div>
                                <div class="profile-detail-row d-flex justify-content-between py-2 border-bottom">
                                    <span class="profile-detail-key text-muted">Last Name</span>
                                    <span class="profile-detail-val fw-medium"><?= e($adminUser['last_name']) ?></span>
                                </div>
                                <div class="profile-detail-row d-flex justify-content-between py-2 border-bottom">
                                    <span class="profile-detail-key text-muted">Email</span>
                                    <span class="profile-detail-val fw-medium"><?= e($adminUser['email']) ?></span>
                                </div>
                                <div class="profile-detail-row d-flex justify-content-between py-2 border-bottom">
                                    <span class="profile-detail-key text-muted">Role</span>
                                    <span class="profile-detail-val fw-medium text-capitalize"><?= e($adminUser['role']) ?></span>
                                </div>
                                <div class="profile-detail-row d-flex justify-content-between py-2 border-bottom">
                                    <span class="profile-detail-key text-muted">Location</span>
                                    <span class="profile-detail-val fw-medium">Nairobi, Kenya</span>
                                </div>
                                <div class="profile-detail-row d-flex justify-content-between py-2">
                                    <span class="profile-detail-key text-muted">Password Changed</span>
                                    <span class="profile-detail-val fw-medium"><?= e($adminUser['password_changed_at'] ?? 'Never') ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Edit Profile Tab -->
                        <div class="tab-pane fade" id="tab-edit" role="tabpanel">
                            <form method="post" action="<?= e(url('admin/profile.php')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="edit_profile">

                                <div class="row g-3 mb-3">
                                    <div class="col-md-6">
                                        <label for="firstNameInput" class="form-label">First Name</label>
                                        <input type="text" class="form-control" id="firstNameInput" name="first_name" value="<?= e($adminUser['first_name']) ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="lastNameInput" class="form-label">Last Name</label>
                                        <input type="text" class="form-control" id="lastNameInput" name="last_name" value="<?= e($adminUser['last_name']) ?>" required>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Work Email</label>
                                    <input type="email" class="form-control bg-light" value="<?= e($adminUser['email']) ?>" readonly disabled>
                                    <div class="form-text">Email address is managed by the system administrator.</div>
                                </div>

                                <div class="d-flex justify-content-end">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-check-lg me-1"></i> Save Changes
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Change Password Tab -->
                        <div class="tab-pane fade" id="tab-password" role="tabpanel">
                            <form method="post" action="<?= e(url('admin/profile.php')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="change_password">

                                <div class="row g-3 mb-3">
                                    <div class="col-md-12">
                                        <label for="currentPasswordInput" class="form-label">Current Password</label>
                                        <input type="password" class="form-control" id="currentPasswordInput" name="current_password" required placeholder="Enter your current password">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="newPasswordInput" class="form-label">New Password</label>
                                        <input type="password" class="form-control" id="newPasswordInput" name="new_password" minlength="8" required placeholder="At least 8 characters">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="confirmPasswordInput" class="form-label">Confirm New Password</label>
                                        <input type="password" class="form-control" id="confirmPasswordInput" name="password_confirmation" minlength="8" required placeholder="Re-enter new password">
                                    </div>
                                </div>

                                <div class="d-flex justify-content-end">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-check-lg me-1"></i> Update Password
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require dirname(__DIR__, 2) . '/app/views/admin/footer.php'; ?>