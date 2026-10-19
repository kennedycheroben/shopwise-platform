<?php
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Services\AdminAuthService;

if (AdminAuthService::user()) {
    redirect('admin/');
}

$error = null;
$return = $_GET['return'] ?? ($_POST['return'] ?? '/admin/');
if (!str_starts_with($return, '/') || str_starts_with($return, '//')) {
    $return = '/admin/';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['_csrf_token'] ?? null)) {
        $error = 'Your form session expired. Please try again.';
    } else {
        [$ok, $message] = AdminAuthService::login(
            $_POST['email'] ?? '',
            $_POST['password'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
        );
        if ($ok) {
            redirect($return);
        }
        $error = $message;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign In · ShopWise Kenya Admin</title>
  <meta name="robots" content="noindex, nofollow">
  <meta name="description" content="ShopWise Kenya Admin Sign In">

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
            <li><i class="bi bi-check-circle-fill"></i> Real-time orders & sales monitoring</li>
            <li><i class="bi bi-check-circle-fill"></i> Secure role-based staff access</li>
            <li><i class="bi bi-check-circle-fill"></i> Daraja M-Pesa automated tracking</li>
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
            <h1 class="auth-title">Welcome back</h1>
            <p class="auth-subtitle">Sign in to your administration account</p>
          </div>

          <?php if ($error): ?>
            <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
          <?php endif; ?>

          <form class="auth-form" method="post" action="<?= e(url('admin/login.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="return" value="<?= e($return) ?>">

            <div class="form-group mb-3">
              <label for="email" class="form-label">Email address</label>
              <input type="email" class="form-control" id="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" placeholder="staff@shopwise.co.ke" required autocomplete="email" autofocus>
            </div>

            <div class="form-group mb-3">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <label for="password" class="form-label mb-0">Password</label>
                <a href="<?= e(url('admin/forgot-password.php')) ?>" class="auth-link small">Forgot password?</a>
              </div>
              <div class="input-group">
                <input type="password" class="form-control" id="password" name="password" placeholder="Enter your password" required autocomplete="current-password">
                <button class="btn btn-outline-secondary" type="button" data-toggle-password aria-label="Toggle password visibility">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
            </div>

            <button type="submit" class="btn btn-primary w-100">Sign in securely</button>
          </form>
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
