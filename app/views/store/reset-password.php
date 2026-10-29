<?php
$pageTitle = 'Reset Password - ' . setting('store_name', 'ShopWise Kenya');
$metaDescription = 'Set a new password for your ' . setting('store_name', 'ShopWise Kenya') . ' account.';
$breadcrumbs = [
    ['label' => 'Home', 'url' => url('/')],
    ['label' => 'Reset Password']
];

include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/breadcrumbs.php';
include __DIR__ . '/partials/flash.php';
?>

<main class="main">

    <!-- Page Title -->
    <div class="page-title light-background">
        <div class="container d-lg-flex justify-content-between align-items-center">
            <h1 class="mb-2 mb-lg-0">Reset Password</h1>
            <nav class="breadcrumbs">
                <ol>
                    <li><a href="<?= e(url('/')) ?>">Home</a></li>
                    <li class="current">Reset Password</li>
                </ol>
            </nav>
        </div>
    </div><!-- End Page Title -->

    <section class="section py-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-7 col-lg-5">
                    <div class="card border-0 shadow-sm rounded-3 p-4">
                        <div class="text-center mb-4">
                            <div class="mb-3 text-primary">
                                <i class="bi bi-shield-lock-fill" style="font-size: 2.5rem;"></i>
                            </div>
                            <h3 class="fw-bold">Create New Password</h3>
                            <p class="text-muted small">Choose a strong, unique password to secure your account.</p>
                        </div>

                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger py-2 small" role="alert">
                                <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= e($error) ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($success)): ?>
                            <div class="alert alert-success py-2 small" role="alert">
                                <i class="bi bi-check-circle-fill me-1"></i> <?= e($success) ?>
                            </div>
                            <div class="text-center mt-3">
                                <a href="<?= e(url('login')) ?>" class="btn btn-primary w-100 py-2 fw-bold">Sign In to Your Account</a>
                            </div>
                        <?php else: ?>
                            <form method="POST" action="<?= e(url('reset-password')) ?>">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="token" value="<?= e($token ?? '') ?>">

                                <div class="mb-3">
                                    <label for="newPassword" class="form-label small fw-bold">New Password</label>
                                    <input type="password" name="password" id="newPassword" class="form-control" placeholder="10–72 characters" required minlength="10" maxlength="72" autocomplete="new-password" autofocus>
                                    <span class="form-text small text-muted">Must contain 10–72 characters with at least one letter and one number.</span>
                                </div>

                                <div class="mb-3">
                                    <label for="confirmPassword" class="form-label small fw-bold">Confirm New Password</label>
                                    <input type="password" name="password_confirmation" id="confirmPassword" class="form-control" placeholder="Re-enter new password" required minlength="10" maxlength="72" autocomplete="new-password">
                                </div>

                                <button type="submit" class="btn btn-primary w-100 py-2 fw-bold">
                                    Set New Password
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

</main>

<?php include __DIR__ . '/partials/footer.php'; ?>
