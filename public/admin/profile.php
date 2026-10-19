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
        $first = trim($_POST['first_name'] ?? '');
        $last = trim($_POST['last_name'] ?? '');
        if ($first === '' || $last === '') {
            $error = 'First and last name are required.';
        } else {
            $pdo->prepare('UPDATE users SET first_name=:first,last_name=:last WHERE id=:id')
                ->execute([':first' => $first, ':last' => $last, ':id' => $adminUser['id']]);
            if (($_POST['new_password'] ?? '') !== '') {
                if (strlen($_POST['new_password']) < 8) {
                    $error = 'Your new password must have at least eight characters.';
                } else {
                    $q = $pdo->prepare('SELECT password_hash FROM users WHERE id=:id');
                    $q->execute([':id' => $adminUser['id']]);
                    if (!password_verify($_POST['current_password'] ?? '', (string)$q->fetchColumn())) {
                        $error = 'Your current password is incorrect.';
                    } else {
                        $pdo->prepare('UPDATE users SET password_hash=:hash,password_changed_at=NOW() WHERE id=:id')
                            ->execute([':hash' => password_hash($_POST['new_password'], PASSWORD_DEFAULT), ':id' => $adminUser['id']]);
                    }
                }
            }
            if (!$error) {
                $q = $pdo->prepare('SELECT id,role,first_name,last_name,email,status,password_changed_at FROM users WHERE id=:id');
                $q->execute([':id' => $adminUser['id']]);
                $_SESSION['admin_user'] = $adminUser = $q->fetch();
                flash_set('success', 'Your profile has been updated.');
                redirect('admin/profile.php');
            }
        }
    }
}

require dirname(__DIR__, 2) . '/app/views/admin/layout.php';
?>
<div class="main-content page-profile">
    <div class="page-header">
        <h1 class="page-title">My profile</h1>
        <ol class="breadcrumb">
            <li class="breadcrumb-item">Account settings</li>
        </ol>
    </div>

    <?php require dirname(__DIR__, 2) . '/app/views/admin/flash.php'; ?>

    <div class="card">
        <div class="card-body">
            <form method="post">
                <?= csrf_field() ?>
                <div class="row">
                    <div class="col-md-6">
                        <label class="form-label">First name</label>
                        <input class="form-control" name="first_name" value="<?= e($adminUser['first_name']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Last name</label>
                        <input class="form-control" name="last_name" value="<?= e($adminUser['last_name']) ?>" required>
                    </div>
                </div>
                <hr>
                <p class="text-muted">Leave the password fields empty to keep your current password.</p>
                <div class="mb-3">
                    <label class="form-label">Current password</label>
                    <input class="form-control" type="password" name="current_password">
                </div>
                <div class="mb-3">
                    <label class="form-label">New password</label>
                    <input class="form-control" type="password" name="new_password" minlength="8">
                </div>
                <button class="btn btn-primary" type="submit">Save profile</button>
            </form>
        </div>
    </div>
</div>
<?php require dirname(__DIR__, 2) . '/app/views/admin/footer.php'; ?>