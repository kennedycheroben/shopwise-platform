<?php
$pageTitle = 'Email Verification - ' . setting('store_name', 'ShopWise Kenya');
$metaDescription = 'Verify your email address for your ' . setting('store_name', 'ShopWise Kenya') . ' account.';
$breadcrumbs = [
    ['label' => 'Home', 'url' => url('/')],
    ['label' => 'Email Verification']
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
            <h1 class="mb-2 mb-lg-0">Email Verification</h1>
            <nav class="breadcrumbs">
                <ol>
                    <li><a href="<?= e(url('/')) ?>">Home</a></li>
                    <li class="current">Email Verification</li>
                </ol>
            </nav>
        </div>
    </div><!-- End Page Title -->

    <section class="section py-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-8 col-lg-6">
                    <div class="card border-0 shadow-sm rounded-3 p-4 text-center">

                        <?php if (!empty($success)): ?>
                            <div class="mb-3 text-success">
                                <i class="bi bi-patch-check-fill" style="font-size: 3rem;"></i>
                            </div>
                            <h3 class="fw-bold mb-2">Email Verified Successfully</h3>
                            <p class="text-muted mb-4"><?= e($success) ?></p>
                            <a href="<?= e(url('account')) ?>" class="btn btn-primary px-4 py-2 fw-bold">
                                Continue to Your Account
                            </a>
                        <?php else: ?>
                            <div class="mb-3 text-warning">
                                <i class="bi bi-envelope-exclamation-fill" style="font-size: 3rem;"></i>
                            </div>
                            <h3 class="fw-bold mb-2">Verification Notice</h3>

                            <?php if (!empty($error)): ?>
                                <div class="alert alert-danger py-2 small mb-4" role="alert">
                                    <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= e($error) ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($info)): ?>
                                <div class="alert alert-info py-2 small mb-4" role="alert">
                                    <i class="bi bi-info-circle-fill me-1"></i> <?= e($info) ?>
                                </div>
                            <?php endif; ?>

                            <div class="text-start bg-light p-3 rounded mb-4">
                                <h6 class="fw-bold mb-2">Request a New Verification Link</h6>
                                <p class="small text-muted mb-3">Verification links expire after 24 hours. Enter your account email to receive a new link.</p>

                                <form method="POST" action="<?= e(url('verify-email/resend')) ?>">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <div class="input-group mb-2">
                                        <input type="email" name="email" class="form-control" placeholder="you@example.com" value="<?= e($user['email'] ?? '') ?>" required>
                                        <button class="btn btn-primary fw-bold" type="submit">Resend Link</button>
                                    </div>
                                </form>
                            </div>

                            <a href="<?= e(url('account')) ?>" class="text-decoration-none small text-muted">
                                <i class="bi bi-arrow-left me-1"></i> Return to Account Area
                            </a>
                        <?php endif; ?>

                    </div>
                </div>
            </div>
        </div>
    </section>

</main>

<?php include __DIR__ . '/partials/footer.php'; ?>
