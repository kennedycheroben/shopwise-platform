<?php
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Services\PasswordResetService;

$token = $_GET['token'] ?? ($_POST['token'] ?? '');
$error = null;
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['_csrf_token'] ?? null)) {
        $error = 'Your form session expired. Please try again.';
    } elseif (($_POST['password'] ?? '') !== ($_POST['password_confirmation'] ?? '')) {
        $error = 'The password confirmation does not match.';
    } elseif (strlen($_POST['password'] ?? '') < 8) {
        $error = 'Your new password must be at least 8 characters.';
    } elseif (!PasswordResetService::reset($token, $_POST['password'] ?? '')) {
        $error = 'This recovery link is invalid, expired, or has already been used.';
    } else {
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Password · ShopWise Kenya Admin</title>
  <meta name="robots" content="noindex, nofollow">
  <meta name="description" content="ShopWise Kenya Admin Password Reset">

  <!-- Favicons -->
  <link href="<?= e(asset('admin/assets/img/favicon.png')) ?>" rel="icon">
  <link href="<?= e(asset('admin/assets/img/apple-touch-icon.png')) ?>" rel="apple-touch-icon">

  <!-- Google Fonts - Plus Jakarta Sans + JetBrains Mono -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

  <!-- Vendor CSS Files -->
  <link href="<?= e(asset('admin/assets/vendor/bootstrap/css/bootstrap.min.css')) ?>" rel="stylesheet">
  <link href="<?= e(asset('admin/assets/vendor/bootstrap-icons/bootstrap-icons.css')) ?>" rel="stylesheet">
  <link href="<?= e(asset('admin/assets/vendor/remixicon/remixicon.css')) ?>" rel="stylesheet">
  <link href="<?= e(asset('admin/assets/vendor/fontawesome-free/css/all.min.css')) ?>" rel="stylesheet">
  <link href="<?= e(asset('admin/assets/vendor/phosphor-icons/phosphor-icons.css')) ?>" rel="stylesheet">
  <link href="<?= e(asset('admin/assets/vendor/lucide-icons/lucide.css')) ?>" rel="stylesheet">

  <!-- Template Main CSS File -->
  <link href="<?= e(asset('admin/assets/css/main.css')) ?>" rel="stylesheet">
</head>

<body>
  <div class="auth-layout">
    <!-- Brand Panel -->
    <div class="auth-brand">
      <div class="auth-brand-inner">
        <a href="<?= e(url('admin/login.php')) ?>" class="auth-brand-logo">
          <img src="<?= e(asset('admin/assets/img/logo.webp')) ?>" alt="ShopWise Kenya">
          <span>ShopWise Kenya</span>
        </a>
        <div class="auth-brand-content">
          <h2 class="auth-brand-heading">Store Administration</h2>
          <p class="auth-brand-desc">Manage products, inventory, orders, customer fulfilment, and store analytics across Kenya.</p>
          <ul class="auth-brand-features">
            <li><i class="bi bi-check-circle-fill"></i> Secure credential reset</li>
            <li><i class="bi bi-check-circle-fill"></i> Strict password complexity</li>
            <li><i class="bi bi-check-circle-fill"></i> Immediate session invalidation</li>
          </ul>
        </div>
        <div class="auth-brand-footer">
          &copy; <?= e(date('Y')) ?> ShopWise Kenya. All Rights Reserved.
        </div>
      </div>
    </div>

    <!-- Form Area -->
    <div class="auth-main">
      <div class="auth-container">
        <div class="auth-card">
          <div class="auth-card-header">
            <div class="auth-icon">
              <i class="bi bi-shield-lock"></i>
            </div>
            <h1 class="auth-title">Choose a new password</h1>
            <p class="auth-subtitle">Your new password must be at least 8 characters long.</p>
          </div>

          <?php if ($done): ?>
            <div class="alert alert-success" role="alert">
              Your administrator password has been updated securely. You can now sign in with your new credentials.
            </div>
            <a class="btn btn-primary w-100" href="<?= e(url('admin/login.php')) ?>">Sign in now</a>
          <?php else: ?>
            <?php if ($error): ?>
              <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <form class="auth-form" method="post" action="<?= e(url('admin/reset-password.php')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="token" value="<?= e($token) ?>">

              <div class="form-group mb-3">
                <label for="password" class="form-label">New password</label>
                <div class="input-group">
                  <input type="password" class="form-control" id="password" name="password" minlength="8" required placeholder="At least 8 characters" autofocus autocomplete="new-password">
                  <button class="btn btn-outline-secondary" type="button" data-toggle-password aria-label="Toggle password visibility">
                    <i class="bi bi-eye"></i>
                  </button>
                </div>
              </div>

              <div class="form-group mb-3">
                <label for="password_confirmation" class="form-label">Confirm new password</label>
                <div class="input-group">
                  <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" minlength="8" required placeholder="Re-enter new password" autocomplete="new-password">
                  <button class="btn btn-outline-secondary" type="button" data-toggle-password aria-label="Toggle password visibility">
                    <i class="bi bi-eye"></i>
                  </button>
                </div>
              </div>

              <button type="submit" class="btn btn-primary w-100">Update password</button>
            </form>

            <p class="auth-footer-text mt-3 mb-0 text-center">
              <a href="<?= e(url('admin/login.php')) ?>" class="auth-link">
                <i class="bi bi-arrow-left me-1"></i> Return to sign in
              </a>
            </p>
          <?php endif; ?>
        </div>
      </div>
      <footer class="auth-footer">
        <div class="auth-footer-copyright">
          &copy; <?= e(date('Y')) ?> ShopWise Kenya · Nairobi
        </div>
      </footer>
    </div>
  </div>

  <script src="<?= e(asset('admin/assets/vendor/bootstrap/js/bootstrap.bundle.min.js')) ?>"></script>
  <script src="<?= e(asset('admin/assets/js/theme.js')) ?>"></script>
  <script src="<?= e(asset('admin/assets/js/main.js')) ?>"></script>
  <script>
    document.querySelectorAll('[data-toggle-password]').forEach(function(btn) {
      btn.addEventListener('click', function() {
        var input = this.closest('.input-group').querySelector('input');
        var icon = this.querySelector('i');
        if (input.type === 'password') {
          input.type = 'text';
          icon.classList.remove('bi-eye');
          icon.classList.add('bi-eye-slash');
        } else {
          input.type = 'password';
          icon.classList.remove('bi-eye-slash');
          icon.classList.add('bi-eye');
        }
      });
    });
  </script>
</body>

</html>
