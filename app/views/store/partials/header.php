<?php
/**
 * Main Storefront Header Partial
 * Builds mega menu from REAL database categories
 */
use App\Services\CatalogService;
use App\Services\CustomerAuthService;
use App\Services\WishlistService;

$catalogService = new CatalogService();
$categoriesTree = $catalogService->getCategoryTree();
$currentRoute = \App\Http\Router::resolveRequestPath();
$currentCustomer = CustomerAuthService::user();
$headerWishlistCount = $currentCustomer ? WishlistService::getCount((int)$currentCustomer['id']) : 0;
?>
<header id="header" class="header position-relative">

    <!-- Top Utility Bar -->
    <div class="utility-bar">
        <div class="container-fluid container-xl">
            <div class="row align-items-center">
                <div class="col-auto">
                    <div class="utility-links">
                        <span class="utility-link">
                            <i class="bi bi-geo-alt"></i>
                            <span><?= e(setting('pickup_address', 'Moi Avenue, Nairobi CBD')) ?></span>
                        </span>
                        <span class="utility-divider"></span>
                        <span class="utility-link">
                            <i class="bi bi-telephone"></i>
                            <span><?= e(setting('support_phone', '+254 700 000 000')) ?></span>
                        </span>
                    </div>
                </div>
                <div class="col text-end">
                    <span class="promo-text">Quality essentials • Delivery across Kenya • M-Pesa accepted</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Header -->
    <div class="main-bar">
        <div class="container-fluid container-xl">
            <div class="row align-items-center gy-2">

                <!-- Logo -->
                <div class="col-auto">
                    <a href="<?= e(url('/')) ?>" class="logo d-flex align-items-center text-decoration-none">
                        <i class="bi bi-cart2 text-primary me-2" style="font-size: 1.8rem;"></i>
                        <h1 class="sitename mb-0"><?= e(setting('store_name', 'ShopWise Kenya')) ?></h1>
                    </a>
                </div>

                <!-- Desktop Search -->
                <div class="col d-none d-lg-block">
                    <form action="<?= e(url('search')) ?>" method="GET" class="search-bar">
                        <i class="bi bi-search search-icon"></i>
                        <input type="text" name="q" class="search-field" placeholder="Search products, categories, SKU..." value="<?= e($searchQuery ?? '') ?>" minlength="2" required autocomplete="off">
                        <button class="search-submit" type="submit">Search</button>
                    </form>
                </div>

                <!-- Actions -->
                <div class="col-auto ms-auto ms-lg-0">
                    <div class="action-group d-flex align-items-center">

                        <!-- Mobile Search Toggle -->
                        <button class="action-btn mobile-search-toggle d-lg-none" type="button" data-bs-toggle="collapse" data-bs-target="#mobileSearch" aria-expanded="false" aria-controls="mobileSearch" aria-label="Toggle search">
                            <i class="bi bi-search"></i>
                        </button>

                        <!-- Account Dropdown -->
                        <div class="dropdown">
                            <button class="action-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account" id="headerAccountBtn">
                                <i class="bi bi-person-circle"></i>
                            </button>
                            <div class="dropdown-menu dropdown-menu-end account-flyout p-3 shadow" aria-labelledby="headerAccountBtn" style="min-width: 250px;">
                                <?php if ($currentCustomer): ?>
                                    <div class="flyout-header mb-2 pb-2 border-bottom">
                                        <h6 class="fw-bold mb-0 text-dark"><?= e($currentCustomer['first_name'] . ' ' . $currentCustomer['last_name']) ?></h6>
                                        <small class="text-muted text-truncate d-block"><?= e($currentCustomer['email']) ?></small>
                                    </div>
                                    <div class="flyout-links d-flex flex-column gap-1 mb-2">
                                        <a href="<?= e(url('account')) ?>" class="dropdown-item py-1 px-2 rounded small text-dark"><i class="bi bi-person me-2 text-primary"></i>My Account</a>
                                        <a href="<?= e(url('account/orders')) ?>" class="dropdown-item py-1 px-2 rounded small text-dark"><i class="bi bi-receipt me-2 text-primary"></i>Order History</a>
                                        <a href="<?= e(url('account/addresses')) ?>" class="dropdown-item py-1 px-2 rounded small text-dark"><i class="bi bi-pin-map me-2 text-primary"></i>Saved Addresses</a>
                                        <a href="<?= e(url('wishlist')) ?>" class="dropdown-item py-1 px-2 rounded small text-dark"><i class="bi bi-bookmark-heart me-2 text-primary"></i>Favorites</a>
                                    </div>
                                    <div class="border-top pt-2">
                                        <form method="POST" action="<?= e(url('logout')) ?>" class="m-0">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger w-100"><i class="bi bi-power me-1"></i>Sign Out</button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <div class="flyout-header mb-3">
                                        <h6 class="fw-bold mb-1 text-dark">Welcome Back</h6>
                                        <p class="small text-muted mb-0">Sign in for personalized shopping and saved orders</p>
                                    </div>
                                    <div class="flyout-actions d-flex gap-2 mb-3">
                                        <a href="<?= e(url('login')) ?>" class="btn btn-primary btn-sm flex-grow-1 fw-bold">Sign In</a>
                                        <a href="<?= e(url('register')) ?>" class="btn btn-outline-primary btn-sm flex-grow-1 fw-bold">Register</a>
                                    </div>
                                    <div class="flyout-links d-flex flex-column gap-1 border-top pt-2">
                                        <a href="<?= e(url('account/orders')) ?>" class="dropdown-item py-1 px-2 rounded small text-dark"><i class="bi bi-receipt me-2 text-muted"></i>Order History</a>
                                        <a href="<?= e(url('wishlist')) ?>" class="dropdown-item py-1 px-2 rounded small text-dark"><i class="bi bi-bookmark-heart me-2 text-muted"></i>Favorites</a>
                                        <a href="<?= e(url('return-policy')) ?>" class="dropdown-item py-1 px-2 rounded small text-dark"><i class="bi bi-arrow-counterclockwise me-2 text-muted"></i>Returns &amp; Exchanges</a>
                                        <a href="<?= e(url('support')) ?>" class="dropdown-item py-1 px-2 rounded small text-dark"><i class="bi bi-life-preserver me-2 text-muted"></i>Support Center</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Wishlist -->
                        <a href="<?= e(url('wishlist')) ?>" class="action-btn d-none d-md-flex position-relative text-decoration-none" aria-label="Wishlist">
                            <i class="bi bi-heart"></i>
                            <span class="badge-count" id="headerWishlistCount"><?= (int)$headerWishlistCount ?></span>
                        </a>

                        <!-- Cart Dropdown & Mini-Cart -->
                        <div class="dropdown">
                            <a href="<?= e(url('cart')) ?>" class="action-btn position-relative" data-bs-toggle="dropdown" aria-label="Cart" id="headerCartBtn">
                                <i class="bi bi-bag"></i>
                                <span class="badge-count" id="headerCartCount"><?= (int)($cart['items_count'] ?? 0) ?></span>
                            </a>
                            <div class="dropdown-menu dropdown-menu-end cart-flyout" id="miniCartContainer" aria-labelledby="headerCartBtn">
                                <?php include __DIR__ . '/mini-cart.php'; ?>
                            </div>
                        </div>

                        <!-- Mobile Navigation Toggle -->
                        <i class="mobile-nav-toggle d-xl-none bi bi-list me-0" aria-label="Toggle navigation"></i>

                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Navigation Bar -->
    <div class="nav-strip">
        <div class="container-fluid container-xl position-relative">
            <nav id="navmenu" class="navmenu">
                <ul>
                    <li><a href="<?= e(url('/')) ?>" class="<?= $currentRoute === '/' ? 'active' : '' ?>">Home</a></li>
                    <li><a href="<?= e(url('shop')) ?>" class="<?= $currentRoute === '/shop' ? 'active' : '' ?>">All Products</a></li>

                    <!-- Categories Dropdown / Mega Menu from Real Categories -->
                    <li class="dropdown"><a href="<?= e(url('shop')) ?>"><span>Categories</span> <i class="bi bi-chevron-down toggle-dropdown"></i></a>
                        <ul>
                            <?php foreach ($categoriesTree as $cat): ?>
                                <?php if (!empty($cat['children'])): ?>
                                    <li class="dropdown"><a href="<?= e(url('category/' . $cat['slug'])) ?>"><span><?= e($cat['name']) ?></span> <i class="bi bi-chevron-down toggle-dropdown"></i></a>
                                        <ul>
                                            <li><a href="<?= e(url('category/' . $cat['slug'])) ?>">All <?= e($cat['name']) ?></a></li>
                                            <?php foreach ($cat['children'] as $child): ?>
                                                <li><a href="<?= e(url('category/' . $child['slug'])) ?>"><?= e($child['name']) ?></a></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </li>
                                <?php else: ?>
                                    <li><a href="<?= e(url('category/' . $cat['slug'])) ?>"><?= e($cat['name']) ?></a></li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                    </li>

                    <?php
                    // Top 3 root categories directly on navigation bar for easy access
                    $topCats = array_slice($categoriesTree, 0, 3);
                    foreach ($topCats as $topCat):
                        $isActive = ($currentRoute === '/category/' . $topCat['slug']);
                    ?>
                        <li><a href="<?= e(url('category/' . $topCat['slug'])) ?>" class="<?= $isActive ? 'active' : '' ?>"><?= e($topCat['name']) ?></a></li>
                    <?php endforeach; ?>

                    <li><a href="<?= e(url('about')) ?>" class="<?= $currentRoute === '/about' ? 'active' : '' ?>">About</a></li>

                    <li class="dropdown"><a href="<?= e(url('support')) ?>" class="<?= in_array($currentRoute, ['/support', '/faq', '/shipping-info', '/return-policy', '/privacy', '/terms'], true) ? 'active' : '' ?>"><span>Help &amp; Policies</span> <i class="bi bi-chevron-down toggle-dropdown"></i></a>
                        <ul>
                            <li><a href="<?= e(url('faq')) ?>" class="<?= $currentRoute === '/faq' ? 'active' : '' ?>">FAQ</a></li>
                            <li><a href="<?= e(url('shipping-info')) ?>" class="<?= $currentRoute === '/shipping-info' ? 'active' : '' ?>">Shipping Info</a></li>
                            <li><a href="<?= e(url('return-policy')) ?>" class="<?= $currentRoute === '/return-policy' ? 'active' : '' ?>">Return Policy</a></li>
                            <li><a href="<?= e(url('support')) ?>" class="<?= $currentRoute === '/support' ? 'active' : '' ?>">Support Center</a></li>
                            <li><a href="<?= e(url('privacy')) ?>" class="<?= $currentRoute === '/privacy' ? 'active' : '' ?>">Privacy Policy</a></li>
                            <li><a href="<?= e(url('terms')) ?>" class="<?= $currentRoute === '/terms' ? 'active' : '' ?>">Terms of Service</a></li>
                        </ul>
                    </li>

                    <li><a href="<?= e(url('contact')) ?>" class="<?= $currentRoute === '/contact' ? 'active' : '' ?>">Contact</a></li>

                    <li><a href="<?= e(url('cart')) ?>" class="<?= $currentRoute === '/cart' ? 'active' : '' ?>">Cart</a></li>
                </ul>
            </nav>
        </div>
    </div>

    <!-- Mobile Search Form -->
    <div class="collapse" id="mobileSearch">
        <div class="container-fluid container-xl">
            <form action="<?= e(url('search')) ?>" method="GET" class="mobile-search">
                <div class="mobile-search-inner">
                    <i class="bi bi-search"></i>
                    <input type="text" name="q" class="form-control" placeholder="Search products, categories, SKU..." value="<?= e($searchQuery ?? '') ?>" minlength="2" required>
                </div>
            </form>
        </div>
    </div>

</header>
