<?php
/** @var array|null $adminUser */
$adminUser = $adminUser ?? \App\Services\AdminAuthService::user() ?? [
    'first_name' => 'Admin',
    'last_name' => 'User',
    'email' => 'admin@shopwise.co.ke',
    'role' => 'staff',
];

$userRole = $adminUser['role'] ?? 'staff';
$notifData = \App\Services\NotificationService::getNotifications($userRole);
$notifCount = $notifData['total_count'];
$notifItems = $notifData['items'];

$msgData = \App\Services\NotificationService::getHeaderMessages($userRole);
$msgCount = $msgData['count'];
$msgItems = $msgData['items'];
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

    <!-- Header Search (Desktop) -->
    <div class="header-search">
        <form class="search-form" action="<?= e(url('admin/search.php')) ?>" method="GET">
            <i class="bi bi-search search-icon"></i>
            <input type="search" name="q" placeholder="Search orders, products, customers..." autocomplete="off" value="<?= e($_GET['q'] ?? '') ?>">
            <kbd class="search-shortcut">/</kbd>
        </form>
    </div>

    <!-- Header Right -->
    <div class="header-right">
        <!-- Desktop Actions -->
        <div class="header-actions-desktop">

            <!-- Theme Toggle -->
            <button class="header-action theme-toggle" title="Toggle Theme" type="button">
                <i class="ph ph-moon theme-icon-dark"></i>
                <i class="ph ph-sun theme-icon-light"></i>
            </button>

            <!-- Header Divider -->
            <div class="header-divider"></div>

            <?php if (\App\Services\Permissions::can('messages.manage', $userRole)): ?>
            <!-- Messages Dropdown -->
            <div class="header-action dropdown messages-dropdown">
                <button class="dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="Messages" type="button">
                    <i class="bi bi-chat-left-text"></i>
                    <?php if ($msgCount > 0): ?>
                        <span class="badge bg-danger"><?= e($msgCount) ?></span>
                    <?php endif; ?>
                </button>
                <div class="dropdown-menu dropdown-menu-end shadow border-0" style="min-width: 320px;">
                    <div class="messages-header d-flex justify-content-between align-items-center p-3 border-bottom">
                        <h6 class="mb-0 fw-semibold">Customer Inquiries</h6>
                        <a href="<?= e(url('admin/messages/')) ?>" class="small text-decoration-none">View All</a>
                    </div>
                    <div class="messages-list p-2" style="max-height: 280px; overflow-y: auto;">
                        <?php if (empty($msgItems)): ?>
                            <div class="p-3 text-center text-muted small">No contact messages received.</div>
                        <?php else: ?>
                            <?php foreach ($msgItems as $m): ?>
                                <a href="<?= e(url('admin/messages/detail.php?id=' . $m['id'])) ?>" class="d-flex align-items-start gap-2 p-2 rounded text-decoration-none text-dark hover-bg mb-1 <?= $m['status'] === 'new' ? 'bg-light fw-medium' : '' ?>">
                                    <div class="rounded-circle bg-primary-subtle text-primary p-2 d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; font-size: 13px;">
                                        <i class="bi bi-envelope"></i>
                                    </div>
                                    <div class="flex-grow-1 overflow-hidden" style="font-size: 13px;">
                                        <div class="d-flex justify-content-between">
                                            <span class="text-truncate fw-semibold"><?= e($m['name']) ?></span>
                                            <span class="text-muted smaller"><?= e(date('d M', strtotime($m['created_at']))) ?></span>
                                        </div>
                                        <div class="text-truncate text-muted smaller"><?= e($m['subject']) ?></div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div class="messages-footer p-2 text-center border-top">
                        <a href="<?= e(url('admin/messages/')) ?>" class="small text-decoration-none fw-medium">See all inquiries &rarr;</a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Notifications Dropdown -->
            <div class="header-action dropdown notification-dropdown">
                <button class="dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="Notifications" type="button">
                    <i class="bi bi-bell"></i>
                    <?php if ($notifCount > 0): ?>
                        <span class="badge bg-warning text-dark"><?= e($notifCount) ?></span>
                    <?php endif; ?>
                </button>
                <div class="dropdown-menu dropdown-menu-end shadow border-0" style="min-width: 320px;">
                    <div class="notification-header d-flex justify-content-between align-items-center p-3 border-bottom">
                        <h6 class="mb-0 fw-semibold">Notifications</h6>
                        <?php if ($notifCount > 0): ?>
                            <span class="badge bg-primary-subtle text-primary"><?= e($notifCount) ?> pending</span>
                        <?php endif; ?>
                    </div>
                    <div class="notification-list p-2" style="max-height: 280px; overflow-y: auto;">
                        <?php if (empty($notifItems)): ?>
                            <div class="p-3 text-center text-muted small">
                                <i class="bi bi-check-circle text-success fs-5 d-block mb-1"></i>
                                All operational queues are clear!
                            </div>
                        <?php else: ?>
                            <?php foreach ($notifItems as $n): ?>
                                <a href="<?= e($n['link']) ?>" class="d-flex align-items-start gap-2 p-2 rounded text-decoration-none text-dark hover-bg mb-1">
                                    <div class="rounded-circle bg-<?= e($n['color']) ?>-subtle text-<?= e($n['color']) ?> p-2 d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; font-size: 14px;">
                                        <i class="bi <?= e($n['icon']) ?>"></i>
                                    </div>
                                    <div class="flex-grow-1" style="font-size: 13px;">
                                        <div class="fw-semibold"><?= e($n['title']) ?></div>
                                        <div class="text-muted smaller"><?= e($n['text']) ?></div>
                                    </div>
                                    <span class="badge bg-<?= e($n['color']) ?> text-white"><?= e($n['count']) ?></span>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div class="notification-footer p-2 text-center border-top">
                        <a href="<?= e(url('admin/')) ?>" class="small text-decoration-none fw-medium">View Operations Dashboard</a>
                    </div>
                </div>
            </div>

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

        <!-- Mobile Actions -->
        <div class="header-actions-mobile">
            <button class="header-action mobile-menu-toggle" title="More" type="button">
                <i class="bi bi-three-dots-vertical"></i>
            </button>
        </div>
    </div>
</header>