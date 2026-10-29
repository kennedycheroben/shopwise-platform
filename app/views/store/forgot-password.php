<?php
$pageTitle = 'Forgot Password - ' . setting('store_name', 'ShopWise Kenya');
$metaDescription = 'Reset your password for your ' . setting('store_name', 'ShopWise Kenya') . ' account.';
$breadcrumbs = [
    ['label' => 'Home', 'url' => url('/')],
    ['label' => 'Login', 'url' => url('login')],
    ['label' => 'Forgot Password']
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
            <h1 class="mb-2 mb-lg-0">Forgot Password</h1>
            <nav class="breadcrumbs">
                <ol>
                    <li><a href="<?= e(url('/')) ?>">Home</a></li>
                    <li><a href="<?= e(url('login')) ?>">Login</a></li>
                    <li class="current">Forgot Password</li>
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
                                <i class="bi bi-key-fill" style="font-size: 2.5rem;"></i>
                            </div>
                            <h3 class="fw-bold">Password Assistance</h3>
                            <p class="text-muted small">Enter the email address associated with your ShopWise account to receive a secure password reset link.</p>
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
                        <?php endif; ?>

                        <form method="POST" action="<?= e(url('forgot-password')) ?>">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                            <div class="mb-3">
                                <label for="resetEmail" class="form-label small fw-bold">Email Address</label>
                                <input type="email" name="email" id="resetEmail" class="form-control" placeholder="you@example.com" value="<?= e($email ?? '') ?>" required autocomplete="email" autofocus>
                                <span class="form-text small text-muted">A reset link valid for 60 minutes will be sent if an account exists.</span>
                            </div>

                            <button type="submit" class="btn btn-primary w-100 py-2 fw-bold">
                                Send Reset Link
                            </button>

                            <div class="text-center mt-3">
                                <a href="<?= e(url('login')) ?>" class="text-decoration-none small text-muted">
                                    <i class="bi bi-arrow-left me-1"></i> Back to Sign In
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>

</main>

<?php include __DIR__ . '/partials/footer.php'; ?>
