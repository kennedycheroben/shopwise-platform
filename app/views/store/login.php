<?php
use App\Services\GoogleAuthService;

$pageTitle = 'Customer Login - ' . setting('store_name', 'ShopWise Kenya');
$metaDescription = 'Sign in to your ' . setting('store_name', 'ShopWise Kenya') . ' account to manage your orders, saved addresses, and wishlist.';
$breadcrumbs = [
    ['label' => 'Home', 'url' => url('/')],
    ['label' => 'Login']
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
            <h1 class="mb-2 mb-lg-0">Login</h1>
            <nav class="breadcrumbs">
                <ol>
                    <li><a href="<?= e(url('/')) ?>">Home</a></li>
                    <li class="current">Login</li>
                </ol>
            </nav>
        </div>
    </div><!-- End Page Title -->

    <!-- Login Section -->
    <section id="login" class="login section">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-10 col-xl-9">
                    <div class="auth-card">
                        <div class="row g-0">

                            <!-- Side Panel -->
                            <div class="col-lg-5 d-none d-lg-block">
                                <div class="side-panel">
                                    <div class="side-panel-content">
                                        <div class="brand-icon">
                                            <i class="bi bi-shield-lock"></i>
                                        </div>
                                        <h3>Secure Access Portal</h3>
                                        <p>Manage your account with confidence. Your orders, delivery addresses, and saved items are protected.</p>
                                        <ul class="feature-list">
                                            <li>
                                                <i class="bi bi-check-circle"></i>
                                                <span>Encrypted account security</span>
                                            </li>
                                            <li>
                                                <i class="bi bi-check-circle"></i>
                                                <span>Fast checkout with saved addresses</span>
                                            </li>
                                            <li>
                                                <i class="bi bi-check-circle"></i>
                                                <span>Real-time order status tracking</span>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div><!-- End Side Panel -->

                            <!-- Form Panel -->
                            <div class="col-lg-7">
                                <div class="form-panel">

                                    <!-- Tab Navigation -->
                                    <div class="auth-tabs">
                                        <a href="<?= e(url('login' . (!empty($returnUrl) ? '?next=' . urlencode($returnUrl) : ''))) ?>" class="tab-btn active text-decoration-none text-center">Sign In</a>
                                        <a href="<?= e(url('register' . (!empty($returnUrl) ? '?next=' . urlencode($returnUrl) : ''))) ?>" class="tab-btn text-decoration-none text-center">Register</a>
                                    </div>

                                    <!-- Login Form -->
                                    <div class="auth-form login-form active">
                                        <p class="form-subtitle">Enter your credentials to access your account</p>

                                        <?php if (!empty($error)): ?>
                                            <div class="alert alert-danger py-2 small" role="alert">
                                                <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= e($error) ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($info)): ?>
                                            <div class="alert alert-info py-2 small" role="alert">
                                                <i class="bi bi-info-circle-fill me-1"></i> <?= e($info) ?>
                                            </div>
                                        <?php endif; ?>

                                        <form class="auth-form-content" method="POST" action="<?= e(url('login')) ?>">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="next" value="<?= e($returnUrl ?? '/account') ?>">

                                            <div class="field-group mb-3">
                                                <label class="form-label small fw-bold">Email Address</label>
                                                <input type="email" name="email" class="form-control" placeholder="you@example.com" value="<?= e($email ?? '') ?>" required autocomplete="email" autofocus>
                                                <span class="field-hint text-muted small">We'll never share your email</span>
                                            </div>

                                            <div class="field-group mb-3">
                                                <div class="label-row d-flex justify-content-between align-items-center mb-1">
                                                    <label class="form-label small fw-bold mb-0">Password</label>
                                                    <a href="<?= e(url('forgot-password')) ?>" class="forgot-link small text-primary text-decoration-none">Forgot password?</a>
                                                </div>
                                                <div class="input-wrapper position-relative">
                                                    <input type="password" name="password" id="loginPassword" class="form-control" placeholder="Enter your password" required autocomplete="current-password">
                                                    <span class="password-toggle position-absolute end-0 top-50 translate-middle-y pe-3" style="cursor: pointer;" onclick="const el=document.getElementById('loginPassword'); el.type = el.type === 'password' ? 'text' : 'password'; this.querySelector('i').classList.toggle('bi-eye'); this.querySelector('i').classList.toggle('bi-eye-slash');">
                                                        <i class="bi bi-eye-slash"></i>
                                                    </span>
                                                </div>
                                            </div>

                                            <button type="submit" class="btn-primary-auth w-100 py-2 border-0 rounded text-white fw-bold mb-3" style="background-color: var(--accent-color, #0d6efd);">
                                                Sign In
                                            </button>

                                            <?php if (GoogleAuthService::isConfigured()): ?>
                                                <div class="separator text-center my-3 position-relative">
                                                    <span class="bg-white px-2 text-muted small">or continue with</span>
                                                </div>

                                                <div class="social-buttons">
                                                    <a href="<?= e(url('auth/google' . (!empty($returnUrl) ? '?next=' . urlencode($returnUrl) : ''))) ?>" class="btn-social w-100 d-flex align-items-center justify-content-center text-decoration-none border rounded py-2 text-dark">
                                                        <i class="bi bi-google me-2 text-danger"></i>
                                                        <span>Google</span>
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        </form>
                                    </div><!-- End Login Form -->

                                </div>
                            </div><!-- End Form Panel -->

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section><!-- /Login Section -->

</main>

<?php include __DIR__ . '/partials/footer.php'; ?>
