<!-- Mobile Header Menu -->
<div class="mobile-header-menu">
    <div class="mobile-header-menu-content">
        <!-- Theme Toggle -->
        <button class="mobile-menu-item theme-toggle" title="Toggle Theme" type="button">
            <i class="ph ph-moon theme-icon-dark"></i>
            <i class="ph ph-sun theme-icon-light"></i>
            <span class="mobile-menu-label">Theme</span>
        </button>

        <!-- Profile -->
        <a href="<?= e(url('admin/profile.php')) ?>" class="mobile-menu-item">
            <i class="bi bi-person"></i>
            <span class="mobile-menu-label">Profile</span>
        </a>

        <!-- Sign Out -->
        <form method="post" action="<?= e(url('admin/logout.php')) ?>" class="m-0 p-0" style="display:contents;">
            <?= csrf_field() ?>
            <button class="mobile-menu-item mobile-menu-item-danger border-0 bg-transparent text-start w-100" type="submit">
                <i class="bi bi-box-arrow-right"></i>
                <span class="mobile-menu-label">Sign Out</span>
            </button>
        </form>
    </div>
</div>