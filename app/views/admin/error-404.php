<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Page Not Found · ShopWise Kenya Admin</title>
  <meta name="robots" content="noindex, nofollow">

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
  <div class="error-page">
    <div class="error-wrapper">
      <!-- Decorative background shapes -->
      <div class="error-bg-shapes">
        <div class="shape shape-1"></div>
        <div class="shape shape-2"></div>
        <div class="shape shape-3"></div>
      </div>

      <div class="error-content">
        <!-- Large stylized error code -->
        <div class="error-code-display">
          <span class="error-digit">4</span>
          <span class="error-digit accent">
            <span class="digit-circle">
              <i class="bi bi-search"></i>
            </span>
          </span>
          <span class="error-digit">4</span>
        </div>

        <h1 class="error-title">Page Not Found</h1>
        <p class="error-message">
          The administration page you requested does not exist or may have been moved. Let's get you back to the store dashboard.
        </p>

        <div class="error-actions">
          <a href="<?= e(url('admin/')) ?>" class="btn btn-primary btn-lg">
            <i class="bi bi-house-door me-2"></i>Back to Dashboard
          </a>
          <a href="#" class="btn btn-outline-secondary btn-lg" onclick="history.back(); return false;">
            <i class="bi bi-arrow-left me-2"></i>Go Back
          </a>
        </div>
      </div>
    </div>
  </div>

  <script src="<?= e(asset('admin/assets/vendor/bootstrap/js/bootstrap.bundle.min.js')) ?>"></script>
  <script src="<?= e(asset('admin/assets/js/theme.js')) ?>"></script>
  <script src="<?= e(asset('admin/assets/js/main.js')) ?>"></script>
</body>

</html>
