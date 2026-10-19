<?php
/** @var array|null $adminUser */
$adminUser = $adminUser ?? \App\Services\AdminAuthService::user() ?? [
    'first_name' => 'Admin',
    'last_name' => 'User',
];
?>
<!-- Sidebar -->
<aside class="sidebar">

    <!-- Icon Strip -->
    <div class="sidebar-strip">
        <div class="sidebar-strip-logo">
            <a href="<?= e(url('admin/')) ?>">
                <img src="<?= e(asset('admin/assets/img/logo.webp')) ?>" alt="ShopWise Kenya">
            </a>
        </div>

        <nav class="sidebar-strip-nav">
            <ul class="strip-menu">
                <li>
                    <button class="strip-item active" data-panel="dashboards" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Dashboards" type="button">
                        <i class="ph ph-squares-four"></i>
                    </button>
                </li>
            </ul>
        </nav>

        <div class="sidebar-strip-bottom">
            <a href="<?= e(url('admin/profile.php')) ?>" class="sidebar-strip-avatar" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Profile">
                <img src="<?= e(asset('admin/assets/img/profile-img.webp')) ?>" alt="<?= e(trim(($adminUser['first_name'] ?? 'Admin') . ' ' . ($adminUser['last_name'] ?? ''))) ?>">
            </a>
        </div>
    </div>

    <!-- Nav Panel -->
    <div class="sidebar-panel">

        <!-- Panel: Dashboards -->
        <div class="sidebar-panel-section active" data-section="dashboards">
            <div class="sidebar-panel-header">
                <h6>Navigation</h6>
                <button class="sidebar-panel-close" type="button"><i class="bi bi-x-lg"></i></button>
            </div>
            <ul class="panel-nav">
                <li><a class="panel-link<?= ($pageTitle ?? '') === 'Dashboard' ? ' active' : '' ?>" href="<?= e(url('admin/')) ?>">Dashboard</a></li>

                <?php if (\App\Services\Permissions::can('products.view') || \App\Services\Permissions::can('categories.manage') || \App\Services\Permissions::can('inventory.adjust')): ?>
                <li class="panel-nav-group<?= in_array(($activeGroup ?? ''), ['catalog', 'products', 'categories', 'inventory'], true) ? ' open' : '' ?>">
                    <a class="panel-link panel-group-toggle" href="#" aria-expanded="<?= in_array(($activeGroup ?? ''), ['catalog', 'products', 'categories', 'inventory'], true) ? 'true' : 'false' ?>">
                        <span>Catalog</span>
                        <i class="bi bi-chevron-down panel-arrow"></i>
                    </a>
                    <ul class="panel-subnav<?= in_array(($activeGroup ?? ''), ['catalog', 'products', 'categories', 'inventory'], true) ? ' show' : '' ?>">
                        <?php if (\App\Services\Permissions::can('products.view')): ?>
                        <li><a class="panel-link<?= (str_contains(($pageTitle ?? ''), 'Product')) ? ' active' : '' ?>" href="<?= e(url('admin/products/')) ?>">Products</a></li>
                        <?php endif; ?>
                        <?php if (\App\Services\Permissions::can('categories.manage')): ?>
                        <li><a class="panel-link<?= (str_contains(($pageTitle ?? ''), 'Categor')) ? ' active' : '' ?>" href="<?= e(url('admin/categories/')) ?>">Categories</a></li>
                        <?php endif; ?>
                        <?php if (\App\Services\Permissions::can('inventory.adjust')): ?>
                        <li><a class="panel-link<?= (str_contains(($pageTitle ?? ''), 'Inventory') || str_contains(($pageTitle ?? ''), 'Movements')) ? ' active' : '' ?>" href="<?= e(url('admin/inventory/')) ?>">Inventory</a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if (\App\Services\Permissions::can('orders.view') || \App\Services\Permissions::can('orders.manage')): ?>
                <li class="panel-nav-group<?= in_array(($activeGroup ?? ''), ['sales', 'orders'], true) ? ' open' : '' ?>">
                    <a class="panel-link panel-group-toggle" href="#" aria-expanded="<?= in_array(($activeGroup ?? ''), ['sales', 'orders'], true) ? 'true' : 'false' ?>">
                        <span>Sales</span>
                        <i class="bi bi-chevron-down panel-arrow"></i>
                    </a>
                    <ul class="panel-subnav<?= in_array(($activeGroup ?? ''), ['sales', 'orders'], true) ? ' show' : '' ?>">
                        <?php if (\App\Services\Permissions::can('orders.view')): ?>
                        <li><a class="panel-link<?= (str_contains(($pageTitle ?? ''), 'Order') || str_contains(($pageTitle ?? ''), 'Invoice') || str_contains(($pageTitle ?? ''), 'Packing Slip')) ? ' active' : '' ?>" href="<?= e(url('admin/orders/')) ?>">Orders</a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if (\App\Services\Permissions::can('customers.view')): ?>
                <li><a class="panel-link<?= (str_contains(($pageTitle ?? ''), 'Customer')) ? ' active' : '' ?>" href="<?= e(url('admin/customers/')) ?>">Customers</a></li>
                <?php endif; ?>

                <li><a class="panel-link<?= ($pageTitle ?? '') === 'My profile' ? ' active' : '' ?>" href="<?= e(url('admin/profile.php')) ?>">My profile</a></li>
            </ul>
        </div>

    </div>
</aside>

<!-- Sidebar Overlay (Mobile) -->
<div class="sidebar-overlay"></div>
