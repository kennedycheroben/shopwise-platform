<?php
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Services\PasswordResetService;

$sent = false;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['_csrf_token'] ?? null)) {
        $error = 'Your form session expired. Please try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid work email address.';
        } else {
            PasswordResetService::request($email, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
            $sent = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Forgot Password · ShopWise Kenya Admin</title>
  <meta name="robots" content="noindex, nofollow">
  <meta name="description" content="ShopWise Kenya Admin Password Recovery">

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
            <li><i class="bi bi-check-circle-fill"></i> Secure administrator account recovery</li>
            <li><i class="bi bi-check-circle-fill"></i> Single-use cryptographic reset tokens</li>
            <li><i class="bi bi-check-circle-fill"></i> Encrypted session protection</li>
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
              <i class="bi bi-key"></i>
            </div>
            <h1 class="auth-title">Forgot password?</h1>
            <p class="auth-subtitle">No worries, we will send you password reset instructions.</p>
          </div>

          <?php if ($sent): ?>
            <div class="alert alert-success" role="alert">
              If an active account exists for that email, recovery instructions have been dispatched. For security reasons, we do not confirm whether an address is registered.
            </div>
          <?php else: ?>
            <?php if ($error): ?>
              <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <form class="auth-form" method="post" action="<?= e(url('admin/forgot-password.php')) ?>">
              <?= csrf_field() ?>
              <div class="form-group mb-3">
                <label for="email" class="form-label">Work email address</label>
                <input type="email" class="form-control" id="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" placeholder="staff@shopwise.co.ke" required autocomplete="email" autofocus>
              </div>

              <button type="submit" class="btn btn-primary w-100">Send recovery instructions</button>
            </form>
          <?php endif; ?>

          <p class="auth-footer-text mt-3 mb-0 text-center">
            <a href="<?= e(url('admin/login.php')) ?>" class="auth-link">
              <i class="bi bi-arrow-left me-1"></i> Return to sign in
            </a>
          </p>
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
</body>

</html>
