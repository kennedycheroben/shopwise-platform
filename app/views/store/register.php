<?php
use App\Services\GoogleAuthService;

$pageTitle = 'Create Customer Account - ' . setting('store_name', 'ShopWise Kenya');
$metaDescription = 'Register for a ' . setting('store_name', 'ShopWise Kenya') . ' customer account to track orders, save delivery addresses, and save items to your wishlist.';
$breadcrumbs = [
    ['label' => 'Home', 'url' => url('/')],
    ['label' => 'Register']
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
            <h1 class="mb-2 mb-lg-0">Register</h1>
            <nav class="breadcrumbs">
                <ol>
                    <li><a href="<?= e(url('/')) ?>">Home</a></li>
                    <li class="current">Register</li>
                </ol>
            </nav>
        </div>
    </div><!-- End Page Title -->

    <!-- Register Section -->
    <section id="register" class="register section">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-10 col-xl-8">
                    <div class="register-card p-4 p-md-5 border rounded bg-white shadow-sm">

                        <div class="card-heading text-center mb-4">
                            <span class="status-badge badge bg-light text-primary px-3 py-2 rounded-pill mb-2"><i class="bi bi-sparkle"></i> Create Your Account</span>
                            <h3 class="fw-bold">Start Shopping Smarter</h3>
                            <p class="text-muted">Enjoy faster checkout, saved delivery addresses, and order history tracking.</p>
                        </div>

                        <?php if (GoogleAuthService::isConfigured()): ?>
                            <div class="social-auth text-center mb-4">
                                <a href="<?= e(url('auth/google' . (!empty($returnUrl) ? '?next=' . urlencode($returnUrl) : ''))) ?>" class="auth-btn auth-google btn btn-outline-dark w-100 py-2 d-inline-flex align-items-center justify-content-center text-decoration-none">
                                    <i class="bi bi-google me-2 text-danger"></i>
                                    <span>Continue with Google</span>
                                </a>
                            </div>

                            <div class="divider-line text-center my-3 position-relative">
                                <span class="bg-white px-3 text-muted small">or continue with email</span>
                            </div>
                        <?php endif; ?>

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

                        <form action="<?= e(url('register')) ?>" method="POST" class="auth-form-content">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="next" value="<?= e($returnUrl ?? '/account') ?>">

                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <div class="field-group">
                                        <label for="registerFirstName" class="form-label small fw-bold">First Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="registerFirstName" name="first_name" placeholder="First name" value="<?= e($formData['first_name'] ?? '') ?>" required autocomplete="given-name">
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="field-group">
                                        <label for="registerLastName" class="form-label small fw-bold">Last Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="registerLastName" name="last_name" placeholder="Last name" value="<?= e($formData['last_name'] ?? '') ?>" required autocomplete="family-name">
                                    </div>
                                </div>

                                <div class="col-12">
                                    <div class="field-group">
                                        <label for="registerEmail" class="form-label small fw-bold">Email Address <span class="text-danger">*</span></label>
                                        <input type="email" class="form-control" id="registerEmail" name="email" placeholder="you@example.com" value="<?= e($formData['email'] ?? '') ?>" required autocomplete="email">
                                        <span class="field-hint text-muted small">A verification link will be sent to this email</span>
                                    </div>
                                </div>

                                <div class="col-12">
                                    <div class="field-group">
                                        <label for="registerPhone" class="form-label small fw-bold">Phone Number <span class="text-muted fw-normal">(Optional)</span></label>
                                        <input type="tel" class="form-control" id="registerPhone" name="phone" placeholder="e.g. 0712 345 678" value="<?= e($formData['phone'] ?? '') ?>" autocomplete="tel">
                                        <span class="field-hint text-muted small">Used for delivery updates and courier coordination</span>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="field-group">
                                        <label for="registerPassword" class="form-label small fw-bold">Password <span class="text-danger">*</span></label>
                                        <div class="input-wrapper position-relative">
                                            <input type="password" class="form-control" id="registerPassword" name="password" placeholder="10–72 characters" required minlength="10" maxlength="72" autocomplete="new-password">
                                            <span class="password-toggle position-absolute end-0 top-50 translate-middle-y pe-3" style="cursor: pointer;" onclick="const el=document.getElementById('registerPassword'); el.type = el.type === 'password' ? 'text' : 'password'; this.querySelector('i').classList.toggle('bi-eye'); this.querySelector('i').classList.toggle('bi-eye-slash');">
                                                <i class="bi bi-eye-slash"></i>
                                            </span>
                                        </div>
                                        <span class="field-hint text-muted small">Must be 10–72 chars with at least 1 letter and 1 number</span>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="field-group">
                                        <label for="registerConfirm" class="form-label small fw-bold">Confirm Password <span class="text-danger">*</span></label>
                                        <div class="input-wrapper position-relative">
                                            <input type="password" class="form-control" id="registerConfirm" name="password_confirmation" placeholder="Re-enter password" required minlength="10" maxlength="72" autocomplete="new-password">
                                            <span class="password-toggle position-absolute end-0 top-50 translate-middle-y pe-3" style="cursor: pointer;" onclick="const el=document.getElementById('registerConfirm'); el.type = el.type === 'password' ? 'text' : 'password'; this.querySelector('i').classList.toggle('bi-eye'); this.querySelector('i').classList.toggle('bi-eye-slash');">
                                                <i class="bi bi-eye-slash"></i>
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-12 mt-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="terms" id="termsCheck" value="1" required>
                                        <label class="form-check-label small" for="termsCheck">
                                            I agree to the <a href="<?= e(url('terms')) ?>" target="_blank" class="text-primary text-decoration-none">Terms of Service</a> and <a href="<?= e(url('privacy')) ?>" target="_blank" class="text-primary text-decoration-none">Privacy Policy</a> <span class="text-danger">*</span>
                                        </label>
                                    </div>
                                </div>

                                <div class="col-12 mt-4">
                                    <button type="submit" class="btn-primary-auth w-100 py-2 border-0 rounded text-white fw-bold" style="background-color: var(--accent-color, #0d6efd);">
                                        Create Account
                                    </button>
                                </div>

                                <div class="col-12 text-center mt-3">
                                    <span class="small text-muted">Already have an account? </span>
                                    <a href="<?= e(url('login' . (!empty($returnUrl) ? '?next=' . urlencode($returnUrl) : ''))) ?>" class="small text-primary fw-bold text-decoration-none">Sign in here</a>
                                </div>
                            </div>
                        </form>

                    </div>
                </div>
            </div>
        </div>
    </section><!-- /Register Section -->

</main>

<?php include __DIR__ . '/partials/footer.php'; ?>
