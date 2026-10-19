<?php
/** @var array|null $adminUser */
$adminUser = $adminUser ?? \App\Services\AdminAuthService::user() ?? [
    'first_name' => 'Admin',
    'last_name' => 'User',
    'email' => 'admin@shopwise.co.ke',
    'role' => 'staff',
];
?>
<header class="header">
    <!-- Header Left -->
    <div class="header-left">
        <a href="<?= e(url('admin/')) ?>" class="header-logo">
            <img src="<?= e(asset('admin/assets/img/logo.webp')) ?>" alt="ShopWise Kenya">
            <span>ShopWise Kenya</span>
        </a>
        <button class="sidebar-toggle" title="Toggle Sidebar" type="button">
            <i class="bi bi-list"></i>
        </button>
    </div>

    <!-- Header Right -->
    <div class="header-right">
        <!-- Desktop Actions (hidden on mobile, shown in mobile menu) -->
        <div class="header-actions-desktop">

            <!-- Theme Toggle -->
            <button class="header-action theme-toggle" title="Toggle Theme" type="button">
                <i class="ph ph-moon theme-icon-dark"></i>
                <i class="ph ph-sun theme-icon-light"></i>
            </button>

            <!-- Header Divider -->
            <div class="header-divider"></div>

            <!-- User Dropdown -->
            <div class="header-action dropdown user-dropdown">
                <button class="dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" type="button">
                    <img src="<?= e(asset('admin/assets/img/profile-img.webp')) ?>" alt="User" class="avatar">
                    <div class="user-info">
                        <span class="user-name"><?= e(trim(($adminUser['first_name'] ?? 'Admin') . ' ' . ($adminUser['last_name'] ?? ''))) ?></span>
                        <span class="user-role"><?= e(ucfirst($adminUser['role'] ?? 'Staff')) ?></span>
                    </div>
                    <i class="bi bi-chevron-down user-arrow"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-end">
                    <div class="user-dropdown-header">
                        <img src="<?= e(asset('admin/assets/img/profile-img.webp')) ?>" alt="User" class="user-dropdown-avatar">
                        <div class="user-dropdown-info">
                            <div class="user-dropdown-name"><?= e(trim(($adminUser['first_name'] ?? 'Admin') . ' ' . ($adminUser['last_name'] ?? ''))) ?></div>
                            <div class="user-dropdown-email"><?= e($adminUser['email'] ?? '') ?></div>
                        </div>
                        <span class="user-dropdown-status"></span>
                    </div>
                    <div class="user-dropdown-body">
                        <a class="user-dropdown-item" href="<?= e(url('admin/profile.php')) ?>">
                            <i class="bi bi-person"></i>
                            <span>My Profile</span>
                        </a>
                    </div>
                    <div class="user-dropdown-footer">
                        <form method="post" action="<?= e(url('admin/logout.php')) ?>" class="m-0 p-0">
                            <?= csrf_field() ?>
                            <button class="user-dropdown-item user-dropdown-logout w-100 text-start border-0 bg-transparent" type="submit">
                                <i class="bi bi-box-arrow-right"></i>
                                <span>Sign Out</span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mobile Actions (visible only on mobile) -->
        <div class="header-actions-mobile">
            <!-- Mobile Menu Toggle -->
            <button class="header-action mobile-menu-toggle" title="More" type="button">
                <i class="bi bi-three-dots-vertical"></i>
            </button>
        </div>
    </div>
</header>