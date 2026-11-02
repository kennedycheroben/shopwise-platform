<?php
/**
 * ShopWise Kenya - Storefront Front Controller
 * Plain PHP, No Heavy Framework
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Database\Database;
use App\Http\Router;
use App\Services\CatalogService;
use App\Services\CartService;
use App\Services\CustomerAuthService;
use App\Services\GoogleAuthService;
use App\Services\AccountService;
use App\Services\WishlistService;
use App\Services\ReviewService;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Helper: Detect AJAX or JSON API requests
function is_ajax_request(): bool
{
    return (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));
}

// Helper: Send JSON response
function send_json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

$router = new Router();
$cartService = new CartService();
$catalogService = new CatalogService();

// Set 404 Handler
$router->setNotFound(function () use ($cartService, $catalogService) {
    http_response_code(404);
    $cart = $cartService->getCartDetails();
    include dirname(__DIR__) . '/app/views/store/404.php';
});

/* -------------------------------------------------------------------------
 * ROUTE: GET / (Homepage)
 * ------------------------------------------------------------------------- */
$router->get('/', function () use ($cartService, $catalogService) {
    $cart = $cartService->getCartDetails();
    $featuredProducts = $catalogService->getFeaturedProducts(8);
    $newArrivals = $catalogService->getNewArrivals(8);
    $deals = $catalogService->getDeals(8);
    $testimonials = $catalogService->getApprovedTestimonials(6);

    $currentCustomer = CustomerAuthService::user();
    $wishlistProductIds = $currentCustomer ? WishlistService::getWishlistProductIds((int)$currentCustomer['id']) : [];

    include dirname(__DIR__) . '/app/views/store/home.php';
});

/* -------------------------------------------------------------------------
 * ROUTE: GET /shop (All Products Catalog)
 * ------------------------------------------------------------------------- */
$router->get('/shop', function () use ($cartService, $catalogService) {
    $cart = $cartService->getCartDetails();

    $filters = [];
    if (!empty($_GET['q'])) {
        $filters['search'] = trim((string)$_GET['q']);
    }
    if (isset($_GET['min_price']) && is_numeric($_GET['min_price'])) {
        $filters['min_price'] = (float)$_GET['min_price'];
    }
    if (isset($_GET['max_price']) && is_numeric($_GET['max_price'])) {
        $filters['max_price'] = (float)$_GET['max_price'];
    }
    if (!empty($_GET['size'])) {
        $filters['size'] = trim((string)$_GET['size']);
    }
    if (!empty($_GET['color'])) {
        $filters['color'] = trim((string)$_GET['color']);
    }
    if (!empty($_GET['in_stock'])) {
        $filters['in_stock'] = true;
    }

    $allowedSorts = ['newest', 'featured', 'price_asc', 'price_desc', 'name'];
    $currentSort = in_array($_GET['sort'] ?? '', $allowedSorts, true) ? $_GET['sort'] : 'newest';

    $page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $catalogData = $catalogService->getProducts($filters, $currentSort, $page, 12);

    $products = $catalogData['products'];
    $totalItems = $catalogData['total'];
    $pagination = [
        'current_page' => $catalogData['page'],
        'total_pages'  => $catalogData['total_pages'],
        'per_page'     => $catalogData['per_page'],
        'has_prev'     => $catalogData['page'] > 1,
        'has_next'     => $catalogData['page'] < $catalogData['total_pages'],
        'prev_page'    => $catalogData['page'] - 1,
        'next_page'    => $catalogData['page'] + 1,
        'offset'       => ($catalogData['page'] - 1) * $catalogData['per_page'],
    ];

    $facets = $catalogService->getFilterFacets();
    $currentCategory = null;
    $pageTitle = 'All Products';
    $metaDescription = 'Explore our full catalog of quality everyday essentials.';
    $breadcrumbs = [
        ['label' => 'Shop', 'url' => null],
    ];

    $currentCustomer = CustomerAuthService::user();
    $wishlistProductIds = $currentCustomer ? WishlistService::getWishlistProductIds((int)$currentCustomer['id']) : [];

    include dirname(__DIR__) . '/app/views/store/shop.php';
});

/* -------------------------------------------------------------------------
 * ROUTE: GET /categories (Catalog Categories Browse Alias)
 * ------------------------------------------------------------------------- */
$router->get('/categories', function () use ($cartService, $catalogService) {
    $cart = $cartService->getCartDetails();
    $catalogData = $catalogService->getProducts([], 'newest', 1, 12);
    $products = $catalogData['products'];
    $totalItems = $catalogData['total'];
    $pagination = [
        'current_page' => 1,
        'total_pages'  => $catalogData['total_pages'],
        'per_page'     => $catalogData['per_page'],
        'has_prev'     => false,
        'has_next'     => $catalogData['total_pages'] > 1,
        'prev_page'    => 1,
        'next_page'    => 2,
        'offset'       => 0,
    ];
    $currentSort = 'newest';
    $searchQuery = '';
    $selectedCategory = null;

    include dirname(__DIR__) . '/app/views/store/shop.php';
});

/* -------------------------------------------------------------------------
 * ROUTE: GET /category/{slug} (Category Browse)
 * ------------------------------------------------------------------------- */
$router->get('/category/{slug}', function (array $params) use ($cartService, $catalogService) {
    $slug = trim($params['slug'] ?? '');
    $category = $catalogService->getCategoryBySlug($slug);

    if (!$category) {
        $targetSlug = \App\Services\CategoryService::findCurrentSlugForHistorical($slug);
        if ($targetSlug && $targetSlug !== $slug) {
            redirect('category/' . $targetSlug, 301);
            return;
        }

        http_response_code(404);
        $cart = $cartService->getCartDetails();
        include dirname(__DIR__) . '/app/views/store/404.php';
        return;
    }

    $cart = $cartService->getCartDetails();

    $filters = ['category_id' => $category['id']];
    if (!empty($_GET['q'])) {
        $filters['search'] = trim((string)$_GET['q']);
    }
    if (isset($_GET['min_price']) && is_numeric($_GET['min_price'])) {
        $filters['min_price'] = (float)$_GET['min_price'];
    }
    if (isset($_GET['max_price']) && is_numeric($_GET['max_price'])) {
        $filters['max_price'] = (float)$_GET['max_price'];
    }
    if (!empty($_GET['size'])) {
        $filters['size'] = trim((string)$_GET['size']);
    }
    if (!empty($_GET['color'])) {
        $filters['color'] = trim((string)$_GET['color']);
    }
    if (!empty($_GET['in_stock'])) {
        $filters['in_stock'] = true;
    }

    $allowedSorts = ['newest', 'featured', 'price_asc', 'price_desc', 'name'];
    $currentSort = in_array($_GET['sort'] ?? '', $allowedSorts, true) ? $_GET['sort'] : 'newest';

    $page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $catalogData = $catalogService->getProducts($filters, $currentSort, $page, 12);

    $products = $catalogData['products'];
    $totalItems = $catalogData['total'];
    $pagination = [
        'current_page' => $catalogData['page'],
        'total_pages'  => $catalogData['total_pages'],
        'per_page'     => $catalogData['per_page'],
        'has_prev'     => $catalogData['page'] > 1,
        'has_next'     => $catalogData['page'] < $catalogData['total_pages'],
        'prev_page'    => $catalogData['page'] - 1,
        'next_page'    => $catalogData['page'] + 1,
        'offset'       => ($catalogData['page'] - 1) * $catalogData['per_page'],
    ];

    $facets = $catalogService->getFilterFacets((int)$category['id']);
    $currentCategory = $category;
    $pageTitle = $category['name'];
    $metaDescription = "Shop {$category['name']} at ShopWise Kenya. Quality guaranteed with swift Nairobi and countrywide delivery.";
    $breadcrumbs = [
        ['label' => 'Shop', 'url' => url('shop')],
        ['label' => $category['name'], 'url' => null],
    ];

    include dirname(__DIR__) . '/app/views/store/shop.php';
});

/* -------------------------------------------------------------------------
 * ROUTE: GET /search (Catalog Search)
 * ------------------------------------------------------------------------- */
$router->get('/search', function () use ($cartService, $catalogService) {
    $cart = $cartService->getCartDetails();
    $rawQuery = trim((string)($_GET['q'] ?? ''));

    $filters = ['search' => $rawQuery];
    if (isset($_GET['min_price']) && is_numeric($_GET['min_price'])) {
        $filters['min_price'] = (float)$_GET['min_price'];
    }
    if (isset($_GET['max_price']) && is_numeric($_GET['max_price'])) {
        $filters['max_price'] = (float)$_GET['max_price'];
    }
    if (!empty($_GET['size'])) {
        $filters['size'] = trim((string)$_GET['size']);
    }
    if (!empty($_GET['color'])) {
        $filters['color'] = trim((string)$_GET['color']);
    }
    if (!empty($_GET['in_stock'])) {
        $filters['in_stock'] = true;
    }

    $allowedSorts = ['newest', 'featured', 'price_asc', 'price_desc', 'name'];
    $currentSort = in_array($_GET['sort'] ?? '', $allowedSorts, true) ? $_GET['sort'] : 'newest';
    $page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

    $searchNotice = null;
    if ($rawQuery !== '' && mb_strlen($rawQuery) < 2) {
        $searchNotice = 'Search term must be at least 2 characters.';
        $products = [];
        $totalItems = 0;
        $pagination = [
            'current_page' => 1,
            'total_pages'  => 1,
            'per_page'     => 12,
            'has_prev'     => false,
            'has_next'     => false,
            'prev_page'    => 1,
            'next_page'    => 1,
            'offset'       => 0,
        ];
    } else {
        $catalogData = $catalogService->getProducts($filters, $currentSort, $page, 12);
        $products = $catalogData['products'];
        $totalItems = $catalogData['total'];
        $pagination = [
            'current_page' => $catalogData['page'],
            'total_pages'  => $catalogData['total_pages'],
            'per_page'     => $catalogData['per_page'],
            'has_prev'     => $catalogData['page'] > 1,
            'has_next'     => $catalogData['page'] < $catalogData['total_pages'],
            'prev_page'    => $catalogData['page'] - 1,
            'next_page'    => $catalogData['page'] + 1,
            'offset'       => ($catalogData['page'] - 1) * $catalogData['per_page'],
        ];
    }

    $facets = $catalogService->getFilterFacets();
    $currentCategory = null;
    $searchQuery = $rawQuery;
    $pageTitle = $rawQuery !== '' ? "Search: " . $rawQuery : "Search Products";
    $metaDescription = "Search results for {$rawQuery} on ShopWise Kenya.";
    $breadcrumbs = [
        ['label' => 'Shop', 'url' => url('shop')],
        ['label' => 'Search', 'url' => null],
    ];

    include dirname(__DIR__) . '/app/views/store/shop.php';
});

/* -------------------------------------------------------------------------
 * ROUTE: GET /product/{slug} (Product Details)
 * ------------------------------------------------------------------------- */
$router->get('/product/{slug}', function (array $params) use ($cartService, $catalogService) {
    $slug = trim($params['slug'] ?? '');
    $product = $catalogService->getProductBySlug($slug);

    if (!$product) {
        http_response_code(404);
        $cart = $cartService->getCartDetails();
        include dirname(__DIR__) . '/app/views/store/404.php';
        return;
    }

    $cart = $cartService->getCartDetails();
    $relatedProducts = $catalogService->getRelatedProducts((int)$product['id'], (int)$product['category_id'], 4);

    $currentCustomer = CustomerAuthService::user();
    $isWishlisted = false;
    $canReview = ['can_review' => false, 'reason' => 'Only customers who bought this product can review it.'];
    $myReview = null;
    if ($currentCustomer) {
        $isWishlisted = WishlistService::isWishlisted((int)$currentCustomer['id'], (int)$product['id']);
        $canReview = ReviewService::canCustomerReview((int)$currentCustomer['id'], (int)$product['id']);
        $myReview = ReviewService::getCustomerReview((int)$currentCustomer['id'], (int)$product['id']);
    }

    include dirname(__DIR__) . '/app/views/store/product.php';
});

/* -------------------------------------------------------------------------
 * ROUTE: GET /cart (View Cart)
 * ------------------------------------------------------------------------- */
$router->get('/cart', function () use ($cartService) {
    $cart = $cartService->getCartDetails();
    include dirname(__DIR__) . '/app/views/store/cart.php';
});

/* -------------------------------------------------------------------------
 * ROUTE: GET /cart/mini (Mini-Cart AJAX Partial)
 * ------------------------------------------------------------------------- */
$router->get('/cart/mini', function () use ($cartService) {
    $cart = $cartService->getCartDetails();
    ob_start();
    include dirname(__DIR__) . '/app/views/store/partials/mini-cart.php';
    $html = ob_get_clean();

    send_json_response([
        'item_count'  => (int)($cart['item_count'] ?? 0),
        'items_count' => (int)($cart['item_count'] ?? 0),
        'subtotal'    => (float)($cart['subtotal'] ?? 0.0),
        'html'        => $html,
    ]);
});

/* -------------------------------------------------------------------------
 * ROUTE: POST /cart/add
 * ------------------------------------------------------------------------- */
$router->post('/cart/add', function () use ($cartService) {
    if (!csrf_verify()) {
        if (is_ajax_request()) {
            send_json_response(['success' => false, 'message' => 'Security token invalid or expired. Please refresh the page.'], 403);
        }
        flash_set('error', 'Security token invalid or expired. Please refresh the page.');
        redirect($_SERVER['HTTP_REFERER'] ?? 'cart');
    }

    // Client sends ONLY variant_id and quantity
    $variantId = isset($_POST['variant_id']) && is_numeric($_POST['variant_id']) ? (int)$_POST['variant_id'] : 0;
    $quantity = isset($_POST['quantity']) && is_numeric($_POST['quantity']) ? (int)$_POST['quantity'] : 1;

    try {
        $result = $cartService->addItem($variantId, $quantity);
        if (is_ajax_request()) {
            send_json_response($result, $result['success'] ? 200 : 400);
        }
        flash_set($result['success'] ? 'success' : 'error', $result['message']);
        redirect($_SERVER['HTTP_REFERER'] ?? 'cart');
    } catch (\RuntimeException $e) {
        if (is_ajax_request()) {
            send_json_response(['success' => false, 'message' => $e->getMessage()], 429);
        }
        flash_set('error', $e->getMessage());
        redirect($_SERVER['HTTP_REFERER'] ?? 'cart');
    }
});

/* -------------------------------------------------------------------------
 * ROUTE: POST /cart/update
 * ------------------------------------------------------------------------- */
$router->post('/cart/update', function () use ($cartService) {
    if (!csrf_verify()) {
        if (is_ajax_request()) {
            send_json_response(['success' => false, 'message' => 'Security token invalid or expired. Please refresh the page.'], 403);
        }
        flash_set('error', 'Security token invalid or expired. Please refresh the page.');
        redirect('cart');
    }

    $variantId = isset($_POST['variant_id']) && is_numeric($_POST['variant_id']) ? (int)$_POST['variant_id'] : 0;
    $quantity = isset($_POST['quantity']) && is_numeric($_POST['quantity']) ? (int)$_POST['quantity'] : 1;

    try {
        $result = $cartService->updateItem($variantId, $quantity);
        if (is_ajax_request()) {
            send_json_response($result, $result['success'] ? 200 : 400);
        }
        flash_set($result['success'] ? 'success' : 'error', $result['message']);
        redirect('cart');
    } catch (\RuntimeException $e) {
        if (is_ajax_request()) {
            send_json_response(['success' => false, 'message' => $e->getMessage()], 429);
        }
        flash_set('error', $e->getMessage());
        redirect('cart');
    }
});

/* -------------------------------------------------------------------------
 * ROUTE: POST /cart/remove
 * ------------------------------------------------------------------------- */
$router->post('/cart/remove', function () use ($cartService) {
    if (!csrf_verify()) {
        if (is_ajax_request()) {
            send_json_response(['success' => false, 'message' => 'Security token invalid or expired. Please refresh the page.'], 403);
        }
        flash_set('error', 'Security token invalid or expired. Please refresh the page.');
        redirect('cart');
    }

    $variantId = isset($_POST['variant_id']) && is_numeric($_POST['variant_id']) ? (int)$_POST['variant_id'] : 0;

    try {
        $result = $cartService->removeItem($variantId);
        if (is_ajax_request()) {
            send_json_response($result, $result['success'] ? 200 : 400);
        }
        flash_set($result['success'] ? 'success' : 'error', $result['message']);
        redirect('cart');
    } catch (\RuntimeException $e) {
        if (is_ajax_request()) {
            send_json_response(['success' => false, 'message' => $e->getMessage()], 429);
        }
        flash_set('error', $e->getMessage());
        redirect('cart');
    }
});

/* -------------------------------------------------------------------------
 * ROUTE: POST /cart/clear
 * ------------------------------------------------------------------------- */
$router->post('/cart/clear', function () use ($cartService) {
    if (!csrf_verify()) {
        if (is_ajax_request()) {
            send_json_response(['success' => false, 'message' => 'Security token invalid or expired. Please refresh the page.'], 403);
        }
        flash_set('error', 'Security token invalid or expired. Please refresh the page.');
        redirect('cart');
    }

    try {
        $result = $cartService->clearCart();
        if (is_ajax_request()) {
            send_json_response($result, 200);
        }
        flash_set('success', 'Your cart has been cleared.');
        redirect('cart');
    } catch (\RuntimeException $e) {
        if (is_ajax_request()) {
            send_json_response(['success' => false, 'message' => $e->getMessage()], 429);
        }
        flash_set('error', $e->getMessage());
        redirect('cart');
    }
});

/* -------------------------------------------------------------------------
 * ROUTE: POST /cart/coupon/apply
 * ------------------------------------------------------------------------- */
$router->post('/cart/coupon/apply', function () use ($cartService) {
    if (!csrf_verify()) {
        if (is_ajax_request()) {
            send_json_response(['success' => false, 'message' => 'Security token invalid or expired. Please refresh the page.'], 403);
        }
        flash_set('error', 'Security token invalid or expired. Please refresh the page.');
        redirect('cart');
    }

    $code = trim((string)($_POST['code'] ?? ($_POST['coupon_code'] ?? '')));

    try {
        $result = $cartService->applyCoupon($code);
        if (is_ajax_request()) {
            send_json_response($result, $result['success'] ? 200 : 400);
        }
        flash_set($result['success'] ? 'success' : 'error', $result['message']);
        redirect('cart');
    } catch (\RuntimeException $e) {
        if (is_ajax_request()) {
            send_json_response(['success' => false, 'message' => $e->getMessage()], 429);
        }
        flash_set('error', $e->getMessage());
        redirect('cart');
    }
});

/* -------------------------------------------------------------------------
 * ROUTE: POST /cart/coupon/remove
 * ------------------------------------------------------------------------- */
$router->post('/cart/coupon/remove', function () use ($cartService) {
    if (!csrf_verify()) {
        if (is_ajax_request()) {
            send_json_response(['success' => false, 'message' => 'Security token invalid or expired. Please refresh the page.'], 403);
        }
        flash_set('error', 'Security token invalid or expired. Please refresh the page.');
        redirect('cart');
    }

    try {
        $result = $cartService->removeCoupon();
        if (is_ajax_request()) {
            send_json_response($result, 200);
        }
        flash_set('success', 'Coupon removed from your bag.');
        redirect('cart');
    } catch (\RuntimeException $e) {
        if (is_ajax_request()) {
            send_json_response(['success' => false, 'message' => $e->getMessage()], 429);
        }
        flash_set('error', $e->getMessage());
        redirect('cart');
    }
});

/**
 * Route rate limiter helper using protected storage directory.
 * Fails closed with 503 if storage directory is not writable.
 */
function check_route_rate_limit(string $key, int $maxAttempts, int $windowSeconds): bool {
    $config = require dirname(__DIR__) . '/app/config/config.php';
    $storageDir = $config['rate_limits']['storage_dir'] ?? (dirname(__DIR__) . '/app/storage/rate_limits');
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0755, true);
    }
    if (!is_dir($storageDir) || !is_writable($storageDir)) {
        error_log("Rate limiting storage directory [{$storageDir}] is not writable. Failing closed with 503.");
        http_response_code(503);
        send_json_response(['error' => 'Service temporarily unavailable. Please try again shortly.'], 503);
    }
    $file = $storageDir . '/' . hash('sha256', $key) . '.json';
    $now = time();
    $timestamps = [];
    if (file_exists($file)) {
        $content = file_get_contents($file);
        $timestamps = $content ? json_decode($content, true) : [];
        if (!is_array($timestamps)) $timestamps = [];
    }
    $timestamps = array_filter($timestamps, fn($ts) => ($now - $ts) < $windowSeconds);
    if (count($timestamps) >= $maxAttempts) {
        return false;
    }
    $timestamps[] = $now;
    file_put_contents($file, json_encode(array_values($timestamps)), LOCK_EX);
    return true;
}

/* -------------------------------------------------------------------------
 * CHECKOUT & M-PESA DARAJA ROUTES (Phase 5)
 * ------------------------------------------------------------------------- */

/**
 * Route: GET /checkout
 * Storefront checkout view
 */
$router->get('/checkout', function () use ($cartService) {
    if (!\App\Services\MpesaService::isConfigured()) {
        flash_set('info', 'Checkout opens soon. M-Pesa is not currently configured.');
        redirect('cart');
    }

    $cart = $cartService->getCartDetails();
    if (empty($cart['items'])) {
        flash_set('warning', 'Your shopping bag is empty. Please add items before checking out.');
        redirect('cart');
    }

    include dirname(__DIR__) . '/app/views/store/checkout.php';
});

/**
 * Route: POST /checkout/quote (JSON)
 * Real-time dynamic delivery fee, discount & total calculation for display
 */
$router->post('/checkout/quote', function () use ($cartService) {
    if (!\App\Services\MpesaService::isConfigured()) {
        send_json_response(['success' => false, 'error' => 'Checkout is currently disabled.'], 400);
    }

    $cart = $cartService->getCartDetails();
    $subtotal = (float)($cart['subtotal'] ?? 0);
    if ($subtotal <= 0) {
        send_json_response(['success' => false, 'error' => 'Your shopping bag is empty.'], 400);
    }

    // Accept JSON or form-urlencoded payload
    $rawInput = file_get_contents('php://input');
    $jsonData = [];
    if (!empty($rawInput)) {
        $decoded = json_decode($rawInput, true);
        if (is_array($decoded)) {
            $jsonData = $decoded;
        }
    }
    $input = !empty($jsonData) ? array_merge($_POST, $jsonData) : $_POST;

    $deliveryMethod = trim((string)($input['delivery_method'] ?? 'delivery'));
    if (!in_array($deliveryMethod, ['pickup', 'delivery', 'zone', 'distance'], true)) {
        $deliveryMethod = 'delivery';
    }

    $zoneId = (!empty($input['zone_id']) && is_numeric($input['zone_id'])) ? (int)$input['zone_id'] : null;
    $lat = (isset($input['latitude']) && is_numeric($input['latitude'])) ? (float)$input['latitude'] : ((isset($input['lat']) && is_numeric($input['lat'])) ? (float)$input['lat'] : null);
    $lng = (isset($input['longitude']) && is_numeric($input['longitude'])) ? (float)$input['longitude'] : ((isset($input['lng']) && is_numeric($input['lng'])) ? (float)$input['lng'] : null);
    $couponCode = trim((string)($input['coupon_code'] ?? ($cart['coupon_code'] ?? '')));

    // Validate coordinates if provided
    if ($lat !== null || $lng !== null) {
        if ($lat === null || $lng === null || !\App\Services\DeliveryService::isKenyaCoordinate($lat, $lng)) {
            send_json_response([
                'success' => false,
                'error'   => 'Delivery coordinates must be located within Kenya boundaries (Lat -4.8 to 5.1, Lng 33.9 to 41.9).',
            ], 400);
        }
    }

    // Validate zone if provided
    if ($zoneId !== null) {
        $zone = \App\Services\DeliveryService::getZoneById($zoneId);
        if (!$zone || empty($zone['is_active'])) {
            send_json_response([
                'success' => false,
                'error'   => 'The selected delivery zone is unavailable or inactive.',
            ], 400);
        }
    }

    try {
        $quote = \App\Services\DeliveryService::quote($deliveryMethod, $subtotal, $zoneId, $lat, $lng);
        $fee = (float)$quote['fee'];

        // Whole shilling check on fee
        if ($fee != floor($fee) || $fee < 0) {
            send_json_response(['success' => false, 'error' => 'Delivery fee must be a whole shilling amount.'], 400);
        }

        $currentCustomer = CustomerAuthService::user();
        $userId = $currentCustomer ? (int)$currentCustomer['id'] : null;

        $discount = 0.00;
        if ($couponCode !== '') {
            $couponEval = \App\Services\CouponService::evaluate($couponCode, $subtotal, $userId, $fee);
            if ($couponEval['valid']) {
                $discount = (float)$couponEval['discount'];
            }
        }

        // Whole shilling rule on total
        $total = ($subtotal + $fee) - $discount;
        if (floor($total) != $total || $total < 1.0) {
            send_json_response([
                'success' => false,
                'error'   => 'Payable order total must be a whole shilling amount of at least KES 1.',
            ], 400);
        }

        $estDays = $quote['est_days'] ?? $quote['estimated_days'] ?? 'Standard delivery';
        $feeFormatted = format_money($fee);
        $discFormatted = format_money($discount);
        $subFormatted = format_money($subtotal);
        $totFormatted = format_money($total);

        send_json_response([
            'success'                 => true,
            'fee'                     => (int)round($fee),
            'formatted_fee'           => $feeFormatted,
            'fee_formatted'           => $feeFormatted,
            'estimated_days'          => $estDays,
            'est_days'                => $estDays,
            'discount'                => (int)round($discount),
            'formatted_disc'          => $discFormatted,
            'discount_formatted'      => $discFormatted,
            'subtotal'                => (int)round($subtotal),
            'formatted_sub'           => $subFormatted,
            'subtotal_formatted'      => $subFormatted,
            'total'                   => (int)round($total),
            'formatted_tot'           => $totFormatted,
            'total_formatted'         => $totFormatted,
            'total_integer_formatted' => number_format((int)round($total), 0),
            'distance_km'             => $quote['distance_km'] ?? null,
            'pricing_method'          => $quote['pricing_method'] ?? $quote['method'] ?? $deliveryMethod,
        ]);
    } catch (\Throwable $e) {
        send_json_response(['success' => false, 'error' => $e->getMessage()], 400);
    }
});

/**
 * Route: POST /checkout/place
 * Places order from cart inside transaction and initiates STK Push
 */
$router->post('/checkout/place', function () use ($cartService) {
    if (!\App\Services\MpesaService::isConfigured()) {
        flash_set('error', 'Checkout is currently disabled. M-Pesa is not configured.');
        redirect('cart');
    }

    $customer = CustomerAuthService::user();
    if (!$customer) {
        redirect('login?next=' . urlencode('/checkout'));
    }

    $isVerified = ($customer['email_verified_at'] !== null || ($customer['auth_provider'] ?? '') === 'google');
    if (!$isVerified) {
        flash_set('error', 'Please verify your email address before placing an order.');
        redirect('checkout');
    }

    if (!csrf_verify()) {
        flash_set('error', 'Security token invalid or expired. Please try again.');
        redirect('checkout');
    }

    // Rate limit: 10 checkout placements per hour per customer
    if (!check_route_rate_limit('checkout_place_' . (int)$customer['id'], 10, 3600)) {
        flash_set('error', 'You have reached the checkout limit of 10 orders per hour. Please try again later.');
        redirect('checkout');
    }

    $idempotencyToken = trim((string)($_POST['idempotency_token'] ?? ''));
    if ($idempotencyToken === '') {
        flash_set('error', 'Missing checkout idempotency token. Please refresh and try again.');
        redirect('checkout');
    }

    // Client-supplied monetary fields are strictly ignored. Only choices are extracted:
    $deliveryMethod = trim((string)($_POST['delivery_method'] ?? 'delivery'));
    if (!in_array($deliveryMethod, ['pickup', 'delivery'], true)) {
        $deliveryMethod = 'delivery';
    }

    $addressId = (!empty($_POST['address_id']) && is_numeric($_POST['address_id'])) ? (int)$_POST['address_id'] : null;
    $recipientName = trim((string)($_POST['recipient_name'] ?? ''));
    if ($recipientName === '') {
        $recipientName = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
    }

    $phone = trim((string)($_POST['phone'] ?? ''));
    $normalizedPhone = \App\Services\MpesaService::normalizePhone($phone);
    if (!$normalizedPhone) {
        flash_set('error', 'Please provide a valid Kenyan M-Pesa phone number (e.g., 07XXXXXXXX or 01XXXXXXXX).');
        redirect('checkout');
    }

    $county = null;
    $area = null;
    $streetDetails = null;
    $zoneId = null;
    $lat = null;
    $lng = null;

    if ($deliveryMethod === 'delivery') {
        if ($addressId !== null) {
            $savedAddr = \App\Services\AccountService::getAddressById($addressId, (int)$customer['id']);
            if (!$savedAddr) {
                flash_set('error', 'Selected saved address could not be found.');
                redirect('checkout');
            }
            $recipientName = $savedAddr['recipient_name'] ?? $recipientName;
            $county = $savedAddr['county'] ?? null;
            $area = $savedAddr['area'] ?? null;
            $streetDetails = $savedAddr['street_details'] ?? $savedAddr['address_line_1'] ?? null;
            if (!empty($savedAddr['address_line_2'])) {
                $streetDetails = ($streetDetails ? $streetDetails . ' ' : '') . $savedAddr['address_line_2'];
            }
            // Map county/area to zone
            $zones = \App\Services\DeliveryService::listZones(['county' => $county, 'is_active' => 1]);
            foreach ($zones as $z) {
                if (strcasecmp($z['area'], $area) === 0) {
                    $zoneId = (int)$z['id'];
                    break;
                }
            }
            if ($zoneId === null && !empty($zones)) {
                $zoneId = (int)$zones[0]['id'];
            }
        } else {
            $county = trim((string)($_POST['county'] ?? ''));
            $area = trim((string)($_POST['area'] ?? ''));
            $streetDetails = trim((string)($_POST['street_details'] ?? $_POST['address_line_1'] ?? ''));
            $zoneId = (!empty($_POST['zone_id']) && is_numeric($_POST['zone_id'])) ? (int)$_POST['zone_id'] : null;
            $lat = (isset($_POST['latitude']) && is_numeric($_POST['latitude'])) ? (float)$_POST['latitude'] : ((isset($_POST['lat']) && is_numeric($_POST['lat'])) ? (float)$_POST['lat'] : null);
            $lng = (isset($_POST['longitude']) && is_numeric($_POST['longitude'])) ? (float)$_POST['longitude'] : ((isset($_POST['lng']) && is_numeric($_POST['lng'])) ? (float)$_POST['lng'] : null);

            if ($lat !== null || $lng !== null) {
                if ($lat === null || $lng === null || !\App\Services\DeliveryService::isKenyaCoordinate($lat, $lng)) {
                    flash_set('error', 'Pinpoint map coordinates must be within Kenya boundaries.');
                    redirect('checkout');
                }
            } elseif ($zoneId === null) {
                flash_set('error', 'Please choose a delivery zone or pinpoint your delivery location on the map.');
                redirect('checkout');
            }

            // Save address to address book if requested
            if (!empty($_POST['save_address']) && !empty($streetDetails) && !empty($county) && !empty($area)) {
                try {
                    \App\Services\AccountService::createAddress((int)$customer['id'], [
                        'recipient_name' => $recipientName,
                        'phone'          => $normalizedPhone,
                        'address_line_1' => $streetDetails,
                        'address_line_2' => '',
                        'county'         => $county,
                        'area'           => $area,
                        'is_default'     => 0,
                    ]);
                } catch (\Throwable $e) {
                    // Non-fatal if address saving fails
                }
            }
        }
    }

    $notes = substr(trim((string)($_POST['notes'] ?? $_POST['order_notes'] ?? '')), 0, 500);

    $choices = [
        'delivery_method' => $deliveryMethod,
        'recipient_name'  => $recipientName,
        'phone'           => $normalizedPhone,
        'county'          => $county,
        'area'            => $area,
        'street_details'  => $streetDetails,
        'zone_id'         => $zoneId,
        'latitude'        => $lat,
        'longitude'       => $lng,
        'notes'           => $notes,
    ];

    $cartData = $cartService->getCartDetails();
    if (empty($cartData['items'])) {
        flash_set('error', 'Your shopping bag is empty.');
        redirect('cart');
    }

    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    try {
        $order = \App\Services\OrderService::createFromCart(
            $cartData,
            $choices,
            (int)$customer['id'],
            $idempotencyToken,
            $clientIp
        );

        // Clear cart now that order is safely created in DB transaction
        $cartService->clearCart();

        // Initiate STK Push
        $stkResult = \App\Services\MpesaService::initiateStkPush((int)$order['id'], $normalizedPhone);

        if (!$stkResult['success']) {
            flash_set('warning', $stkResult['message'] ?? 'Could not initiate phone prompt. You can retry on the payment page.');
        }

        redirect('checkout/pay/' . urlencode($order['order_number']));
    } catch (\DomainException $de) {
        flash_set('error', $de->getMessage());
        redirect('checkout');
    } catch (\Throwable $te) {
        error_log("Order placement error: " . $te->getMessage());
        flash_set('error', 'We encountered an issue creating your order. Please try again.');
        redirect('checkout');
    }
});

/**
 * Route: GET /checkout/pay/{order_number}
 * Check your phone / payment pending view
 */
$router->get('/checkout/pay/{order_number}', function (array $params) use ($cartService) {
    if (!\App\Services\MpesaService::isConfigured()) {
        redirect('cart');
    }

    $customer = CustomerAuthService::requireLogin('/checkout');
    $orderNumber = trim($params['order_number'] ?? '');

    $details = \App\Services\AccountService::getOrderDetails((int)$customer['id'], $orderNumber);
    if (!$details) {
        http_response_code(404);
        $cart = $cartService->getCartDetails();
        include dirname(__DIR__) . '/app/views/store/404.php';
        return;
    }

    $order = $details['order'];
    $payment = $details['payment'];

    // If order is already paid, redirect straight to confirmation
    if (($order['payment_status'] ?? '') === 'paid') {
        redirect('checkout/confirmation/' . urlencode($orderNumber));
    }

    $cart = $cartService->getCartDetails();
    include dirname(__DIR__) . '/app/views/store/checkout-pay.php';
});

/**
 * Route: GET /checkout/status/{order_number} (JSON)
 * Owner-only, rate-limited polling status endpoint
 */
$router->get('/checkout/status/{order_number}', function (array $params) {
    $customer = CustomerAuthService::user();
    if (!$customer) {
        send_json_response(['error' => 'Unauthorized'], 401);
    }

    $orderNumber = trim($params['order_number'] ?? '');
    $details = \App\Services\AccountService::getOrderDetails((int)$customer['id'], $orderNumber);
    if (!$details) {
        send_json_response(['error' => 'Order not found'], 404);
    }

    // Rate limiting: 60 polls per minute per session
    $sessId = session_id() ?: ($_SERVER['REMOTE_ADDR'] ?? 'guest');
    if (!check_route_rate_limit('status_poll_' . $sessId, 60, 60)) {
        send_json_response(['error' => 'Too many requests. Please slow down.'], 429);
    }

    // Manual status query button pressed
    if (isset($_GET['query']) && $_GET['query'] == '1') {
        if (!check_route_rate_limit('status_query_' . (int)$customer['id'], 10, 60)) {
            send_json_response(['error' => 'Status check limit exceeded. Please wait a moment.'], 429);
        }

        $order = $details['order'];
        if (($order['payment_status'] ?? '') !== 'paid') {
            \App\Services\MpesaService::queryStkPush((int)$order['id']);
            // Refresh details
            $details = \App\Services\AccountService::getOrderDetails((int)$customer['id'], $orderNumber);
        }
    }

    $order = $details['order'];
    $payment = $details['payment'];

    $resultCode = $payment['result_code'] ?? null;
    $msg = \App\Services\MpesaService::mapResultCodeToMessage($resultCode !== null ? (int)$resultCode : null);

    send_json_response([
        'status'         => $order['status'],
        'payment_status' => $order['payment_status'],
        'message'        => $msg,
    ]);
});

/**
 * Route: POST /checkout/retry/{order_number}
 * Retry sending M-Pesa STK Push
 */
$router->post('/checkout/retry/{order_number}', function (array $params) {
    $customer = CustomerAuthService::requireLogin('/checkout');
    $orderNumber = trim($params['order_number'] ?? '');

    $details = \App\Services\AccountService::getOrderDetails((int)$customer['id'], $orderNumber);
    if (!$details) {
        http_response_code(404);
        include dirname(__DIR__) . '/app/views/store/404.php';
        return;
    }

    if (!csrf_verify()) {
        flash_set('error', 'Security token invalid. Please try again.');
        redirect('checkout/pay/' . urlencode($orderNumber));
    }

    $order = $details['order'];
    $res = \App\Services\MpesaService::retryStkPush((int)$order['id']);

    if ($res['success']) {
        flash_set('success', 'STK prompt sent to your phone! Please enter your M-Pesa PIN.');
    } else {
        flash_set('error', $res['message'] ?? 'Could not retry STK prompt.');
    }

    redirect('checkout/pay/' . urlencode($orderNumber));
});

/**
 * Route: GET /checkout/confirmation/{order_number}
 * Order confirmation view (IDOR-safe owner only)
 */
$router->get('/checkout/confirmation/{order_number}', function (array $params) use ($cartService) {
    $customer = CustomerAuthService::requireLogin('/checkout');
    $orderNumber = trim($params['order_number'] ?? '');

    $details = \App\Services\AccountService::getOrderDetails((int)$customer['id'], $orderNumber);
    if (!$details) {
        http_response_code(404);
        $cart = $cartService->getCartDetails();
        include dirname(__DIR__) . '/app/views/store/404.php';
        return;
    }

    $order = $details['order'];
    $items = $details['items'];
    $payment = $details['payment'];
    $cart = $cartService->getCartDetails();

    include dirname(__DIR__) . '/app/views/store/order-confirmation.php';
});

/**
 * Route: POST /payments/callback/{secret}
 * Safaricom Daraja STK Push Callback (No session, no CSRF)
 * Old /mpesa/callback/{secret} is removed and strictly returns 404
 */
$router->post('/payments/callback/{secret}', function (array $params) {
    $secret = (string)($params['secret'] ?? '');
    $rawBody = file_get_contents('php://input') ?: '';
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    $result = \App\Services\MpesaService::handleCallback($secret, $rawBody, $clientIp);

    http_response_code((int)($result['status_code'] ?? 200));
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result['response'] ?? ['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    exit;
});

/* -------------------------------------------------------------------------
 * CONTENT & POLICY ROUTES (Phase 3B)
 * ------------------------------------------------------------------------- */
$router->get('/about', function () use ($cartService) {
    $cart = $cartService->getCartDetails();
    include dirname(__DIR__) . '/app/views/store/about.php';
});

$router->get('/contact', function () use ($cartService) {
    $cart = $cartService->getCartDetails();
    $formValues = $_SESSION['contact_form_values'] ?? [];
    $formErrors = $_SESSION['contact_form_errors'] ?? [];
    $contactSuccess = $_SESSION['contact_form_success'] ?? null;
    unset($_SESSION['contact_form_values'], $_SESSION['contact_form_errors'], $_SESSION['contact_form_success']);

    include dirname(__DIR__) . '/app/views/store/contact.php';
});

$router->post('/contact', function () {
    $csrf = $_POST['_csrf_token'] ?? '';
    if (!csrf_verify($csrf)) {
        if (is_ajax_request()) {
            send_json_response(['success' => false, 'errors' => ['general' => 'CSRF verification failed. Please reload and try again.']], 403);
        }
        flash_set('error', 'Security check failed. Please refresh the page and try again.');
        redirect('contact');
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $config = require dirname(__DIR__) . '/app/config/config.php';
    if ((($config['app']['environment'] ?? '') === 'local') && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($parts[0]);
    }

    $result = \App\Services\ContactMessageService::submit($_POST, $ip);

    if (is_ajax_request()) {
        $status = $result['success'] ? 200 : (isset($result['rate_limited']) ? 429 : 422);
        send_json_response($result, $status);
    }

    if ($result['success']) {
        $_SESSION['contact_form_success'] = $result['message'];
    } else {
        $_SESSION['contact_form_errors'] = $result['errors'];
        $_SESSION['contact_form_values'] = [
            'name'    => $_POST['name'] ?? '',
            'email'   => $_POST['email'] ?? '',
            'phone'   => $_POST['phone'] ?? '',
            'subject' => $_POST['subject'] ?? '',
            'message' => $_POST['message'] ?? '',
        ];
    }
    redirect('contact');
});

$router->get('/faq', function () use ($cartService) {
    $cart = $cartService->getCartDetails();
    include dirname(__DIR__) . '/app/views/store/faq.php';
});

$router->get('/shipping-info', function () use ($cartService) {
    $cart = $cartService->getCartDetails();
    include dirname(__DIR__) . '/app/views/store/shipping-info.php';
});

$router->get('/return-policy', function () use ($cartService) {
    $cart = $cartService->getCartDetails();
    include dirname(__DIR__) . '/app/views/store/return-policy.php';
});

$router->get('/privacy', function () use ($cartService) {
    $cart = $cartService->getCartDetails();
    include dirname(__DIR__) . '/app/views/store/privacy.php';
});

$router->get('/terms', function () use ($cartService) {
    $cart = $cartService->getCartDetails();
    include dirname(__DIR__) . '/app/views/store/terms.php';
});

$router->get('/support', function () use ($cartService) {
    $cart = $cartService->getCartDetails();
    include dirname(__DIR__) . '/app/views/store/support.php';
});

/* =========================================================================
 * PHASE 4: CUSTOMER ACCOUNTS & AUTHENTICATION ROUTES
 * ========================================================================= */

/* -------------------------------------------------------------------------
 * ROUTE: GET /register
 * ------------------------------------------------------------------------- */
$router->get('/register', function () use ($cartService) {
    if (CustomerAuthService::user()) {
        redirect('account');
    }
    $cart = $cartService->getCartDetails();
    $returnUrl = CustomerAuthService::sanitizeReturnUrl($_GET['next'] ?? null);
    $formData = [];
    $error = null;
    $success = null;
    include dirname(__DIR__) . '/app/views/store/register.php';
});

/* -------------------------------------------------------------------------
 * ROUTE: POST /register
 * ------------------------------------------------------------------------- */
$router->post('/register', function () use ($cartService) {
    if (!csrf_verify()) {
        http_response_code(403);
        $cart = $cartService->getCartDetails();
        $error = 'Security token invalid or expired. Please refresh and try again.';
        $formData = $_POST;
        $returnUrl = CustomerAuthService::sanitizeReturnUrl($_POST['next'] ?? null);
        include dirname(__DIR__) . '/app/views/store/register.php';
        return;
    }

    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $returnUrl = CustomerAuthService::sanitizeReturnUrl($_POST['next'] ?? null);
    [$ok, $msg] = CustomerAuthService::register($_POST, $clientIp);

    if ($ok) {
        flash_set('success', $msg);
        redirect($returnUrl);
    } else {
        $cart = $cartService->getCartDetails();
        $error = $msg;
        $formData = $_POST;
        include dirname(__DIR__) . '/app/views/store/register.php';
    }
});

/* -------------------------------------------------------------------------
 * ROUTE: GET /login
 * ------------------------------------------------------------------------- */
$router->get('/login', function () use ($cartService) {
    if (CustomerAuthService::user()) {
        redirect('account');
    }
    $cart = $cartService->getCartDetails();
    $returnUrl = CustomerAuthService::sanitizeReturnUrl($_GET['next'] ?? null);
    $error = null;
    $info = flash_get('info') ?? flash_get('success');
    include dirname(__DIR__) . '/app/views/store/login.php';
});

/* -------------------------------------------------------------------------
 * ROUTE: POST /login
 * ------------------------------------------------------------------------- */
$router->post('/login', function () use ($cartService) {
    $returnUrl = CustomerAuthService::sanitizeReturnUrl($_POST['next'] ?? null);

    if (!csrf_verify()) {
        http_response_code(403);
        $cart = $cartService->getCartDetails();
        $error = 'Security token invalid or expired. Please refresh and try again.';
        $email = $_POST['email'] ?? '';
        include dirname(__DIR__) . '/app/views/store/login.php';
        return;
    }

    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    [$ok, $msg] = CustomerAuthService::login($email, $password, $clientIp);

    if ($ok) {
        flash_set('success', 'Welcome back!');
        redirect($returnUrl);
    } else {
        $cart = $cartService->getCartDetails();
        $error = $msg;
        include dirname(__DIR__) . '/app/views/store/login.php';
    }
});

/* -------------------------------------------------------------------------
 * ROUTE: POST /logout
 * ------------------------------------------------------------------------- */
$router->post('/logout', function () {
    if (!csrf_verify()) {
        http_response_code(403);
        echo 'Invalid CSRF token.';
        exit;
    }

    CustomerAuthService::logout();
    flash_set('info', 'You have been signed out successfully.');
    redirect('login');
});

/* -------------------------------------------------------------------------
 * ROUTE: GET /forgot-password
 * ------------------------------------------------------------------------- */
$router->get('/forgot-password', function () use ($cartService) {
    $cart = $cartService->getCartDetails();
    $error = null;
    $success = null;
    include dirname(__DIR__) . '/app/views/store/forgot-password.php';
});

/* -------------------------------------------------------------------------
 * ROUTE: POST /forgot-password
 * ------------------------------------------------------------------------- */
$router->post('/forgot-password', function () use ($cartService) {
    if (!csrf_verify()) {
        http_response_code(403);
        $cart = $cartService->getCartDetails();
        $error = 'Security token invalid or expired. Please refresh and try again.';
        include dirname(__DIR__) . '/app/views/store/forgot-password.php';
        return;
    }

    $email = trim((string)($_POST['email'] ?? ''));
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    [$ok, $msg] = CustomerAuthService::requestPasswordReset($email, $clientIp);
    $cart = $cartService->getCartDetails();
    if ($ok) {
        $success = $msg;
        $error = null;
    } else {
        $error = $msg;
        $success = null;
    }
    include dirname(__DIR__) . '/app/views/store/forgot-password.php';
});

/* -------------------------------------------------------------------------
 * ROUTE: GET /reset-password
 * ------------------------------------------------------------------------- */
$router->get('/reset-password', function () use ($cartService) {
    $cart = $cartService->getCartDetails();
    $token = trim((string)($_GET['token'] ?? ''));
    $error = null;
    $success = null;
    include dirname(__DIR__) . '/app/views/store/reset-password.php';
});

/* -------------------------------------------------------------------------
 * ROUTE: POST /reset-password
 * ------------------------------------------------------------------------- */
$router->post('/reset-password', function () use ($cartService) {
    $token = trim((string)($_POST['token'] ?? ''));

    if (!csrf_verify()) {
        http_response_code(403);
        $cart = $cartService->getCartDetails();
        $error = 'Security token invalid or expired. Please refresh and try again.';
        include dirname(__DIR__) . '/app/views/store/reset-password.php';
        return;
    }

    $password = (string)($_POST['password'] ?? '');
    $confirmation = (string)($_POST['password_confirmation'] ?? '');
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    if ($password !== $confirmation) {
        $cart = $cartService->getCartDetails();
        $error = 'Password confirmation does not match.';
        include dirname(__DIR__) . '/app/views/store/reset-password.php';
        return;
    }

    [$ok, $msg] = CustomerAuthService::resetPassword($token, $password, $clientIp);
    $cart = $cartService->getCartDetails();
    if ($ok) {
        $success = $msg;
        $error = null;
    } else {
        $error = $msg;
        $success = null;
    }
    include dirname(__DIR__) . '/app/views/store/reset-password.php';
});

/* -------------------------------------------------------------------------
 * ROUTE: GET /verify-email
 * ------------------------------------------------------------------------- */
$router->get('/verify-email', function () use ($cartService) {
    $cart = $cartService->getCartDetails();
    $token = trim((string)($_GET['token'] ?? ''));
    $user = CustomerAuthService::user();
    $error = null;
    $success = null;
    $info = null;

    if ($token !== '') {
        [$ok, $msg] = CustomerAuthService::verifyEmail($token);
        if ($ok) {
            $success = $msg;
        } else {
            $error = $msg;
        }
    } else {
        $info = 'Please check your email for the verification link.';
    }

    include dirname(__DIR__) . '/app/views/store/verify-email.php';
});

/* -------------------------------------------------------------------------
 * ROUTE: POST /verify-email/resend
 * ------------------------------------------------------------------------- */
$router->post('/verify-email/resend', function () use ($cartService) {
    if (!csrf_verify()) {
        http_response_code(403);
        $cart = $cartService->getCartDetails();
        $error = 'Security token invalid or expired.';
        include dirname(__DIR__) . '/app/views/store/verify-email.php';
        return;
    }

    $email = trim((string)($_POST['email'] ?? ''));
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    [$ok, $msg] = CustomerAuthService::resendVerification($email, $clientIp);
    $cart = $cartService->getCartDetails();
    $user = CustomerAuthService::user();
    if ($ok) {
        $info = $msg;
        $error = null;
    } else {
        $error = $msg;
        $info = null;
    }
    include dirname(__DIR__) . '/app/views/store/verify-email.php';
});

/* -------------------------------------------------------------------------
 * ROUTE: GET /auth/google
 * ------------------------------------------------------------------------- */
$router->get('/auth/google', function () {
    if (!GoogleAuthService::isConfigured()) {
        http_response_code(503);
        echo 'Google Sign-In is not currently configured.';
        exit;
    }

    $returnUrl = CustomerAuthService::sanitizeReturnUrl($_GET['next'] ?? null);
    $authUrl = GoogleAuthService::generateAuthUrl($returnUrl);
    header('Location: ' . $authUrl);
    exit;
});

/* -------------------------------------------------------------------------
 * ROUTE: GET /auth/google/callback
 * ------------------------------------------------------------------------- */
$router->get('/auth/google/callback', function () {
    if (!GoogleAuthService::isConfigured()) {
        http_response_code(503);
        echo 'Google Sign-In is not currently configured.';
        exit;
    }

    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    [$ok, $result] = GoogleAuthService::handleCallback($_GET, $clientIp);

    if ($ok) {
        flash_set('success', 'Successfully signed in with Google!');
        redirect($result['return_url'] ?? 'account');
    } else {
        flash_set('error', $result);
        redirect('login');
    }
});

/* -------------------------------------------------------------------------
 * ROUTE: GET /account
 * ------------------------------------------------------------------------- */
$router->get('/account', function () use ($cartService) {
    $customer = CustomerAuthService::requireLogin('/account');
    $cart = $cartService->getCartDetails();

    $orders = AccountService::getOrders((int)$customer['id'], 1, 5);
    $addresses = AccountService::getAddresses((int)$customer['id']);
    $wishlistCount = WishlistService::getCount((int)$customer['id']);

    $stats = [
        'orders_count'   => (int)($orders['total'] ?? 0),
        'wishlist_count' => $wishlistCount,
        'address_count'  => count($addresses),
    ];

    $activeTab = 'orders';
    $error = flash_get('error');
    $success = flash_get('success');

    include dirname(__DIR__) . '/app/views/store/account.php';
});

/* -------------------------------------------------------------------------
 * ROUTE: GET /account/orders
 * ------------------------------------------------------------------------- */
$router->get('/account/orders', function () use ($cartService) {
    $customer = CustomerAuthService::requireLogin('/account/orders');
    $cart = $cartService->getCartDetails();

    $page = max(1, (int)($_GET['page'] ?? 1));
    $orders = AccountService::getOrders((int)$customer['id'], $page, 10);
    $addresses = AccountService::getAddresses((int)$customer['id']);
    $wishlistCount = WishlistService::getCount((int)$customer['id']);

    $stats = [
        'orders_count'   => (int)($orders['total'] ?? 0),
        'wishlist_count' => $wishlistCount,
        'address_count'  => count($addresses),
    ];

    $activeTab = 'orders';
    $error = flash_get('error');
    $success = flash_get('success');

    include dirname(__DIR__) . '/app/views/store/account.php';
});

/* -------------------------------------------------------------------------
 * ROUTE: GET /account/orders/{order_number}
 * ------------------------------------------------------------------------- */
$router->get('/account/orders/{order_number}', function (array $params) use ($cartService) {
    $customer = CustomerAuthService::requireLogin('/account/orders');
    $orderNumber = trim($params['order_number'] ?? '');

    $order = AccountService::getOrderDetails((int)$customer['id'], $orderNumber);
    if (!$order) {
        http_response_code(404);
        $cart = $cartService->getCartDetails();
        include dirname(__DIR__) . '/app/views/store/404.php';
        return;
    }

    $cart = $cartService->getCartDetails();
    $addresses = AccountService::getAddresses((int)$customer['id']);
    $wishlistCount = WishlistService::getCount((int)$customer['id']);
    $ordersSummary = AccountService::getOrders((int)$customer['id'], 1, 1);

    $stats = [
        'orders_count'   => (int)($ordersSummary['total'] ?? 0),
        'wishlist_count' => $wishlistCount,
        'address_count'  => count($addresses),
    ];

    $activeTab = 'order_details';
    $error = flash_get('error');
    $success = flash_get('success');

    include dirname(__DIR__) . '/app/views/store/account.php';
});

/* -------------------------------------------------------------------------
 * ROUTE: GET /account/addresses
 * ------------------------------------------------------------------------- */
$router->get('/account/addresses', function () use ($cartService) {
    $customer = CustomerAuthService::requireLogin('/account/addresses');
    $cart = $cartService->getCartDetails();

    $addresses = AccountService::getAddresses((int)$customer['id']);
    $orders = AccountService::getOrders((int)$customer['id'], 1, 1);
    $wishlistCount = WishlistService::getCount((int)$customer['id']);

    $stats = [
        'orders_count'   => (int)($orders['total'] ?? 0),
        'wishlist_count' => $wishlistCount,
        'address_count'  => count($addresses),
    ];

    $activeTab = 'addresses';
    $error = flash_get('error');
    $success = flash_get('success');

    include dirname(__DIR__) . '/app/views/store/account.php';
});

/* -------------------------------------------------------------------------
 * ROUTE: POST /account/addresses
 * ------------------------------------------------------------------------- */
$router->post('/account/addresses', function () {
    if (!csrf_verify()) {
        http_response_code(403);
        echo 'Invalid CSRF token.';
        exit;
    }

    $customer = CustomerAuthService::requireLogin('/account/addresses');
    [$ok, $msg] = AccountService::createAddress((int)$customer['id'], $_POST);

    flash_set($ok ? 'success' : 'error', $msg);
    redirect('account/addresses');
});

/* -------------------------------------------------------------------------
 * ROUTE: POST /account/addresses/{id}/delete
 * ------------------------------------------------------------------------- */
$router->post('/account/addresses/{id}/delete', function (array $params) {
    if (!csrf_verify()) {
        http_response_code(403);
        echo 'Invalid CSRF token.';
        exit;
    }

    $customer = CustomerAuthService::requireLogin('/account/addresses');
    $addressId = isset($params['id']) && is_numeric($params['id']) ? (int)$params['id'] : 0;

    [$ok, $msg] = AccountService::deleteAddress((int)$customer['id'], $addressId);
    flash_set($ok ? 'success' : 'error', $msg);
    redirect('account/addresses');
});

/* -------------------------------------------------------------------------
 * ROUTE: POST /account/addresses/{id}/default
 * ------------------------------------------------------------------------- */
$router->post('/account/addresses/{id}/default', function (array $params) {
    if (!csrf_verify()) {
        http_response_code(403);
        echo 'Invalid CSRF token.';
        exit;
    }

    $customer = CustomerAuthService::requireLogin('/account/addresses');
    $addressId = isset($params['id']) && is_numeric($params['id']) ? (int)$params['id'] : 0;

    [$ok, $msg] = AccountService::setDefaultAddress((int)$customer['id'], $addressId);
    flash_set($ok ? 'success' : 'error', $msg);
    redirect('account/addresses');
});

/* -------------------------------------------------------------------------
 * ROUTE: GET /account/profile
 * ------------------------------------------------------------------------- */
$router->get('/account/profile', function () use ($cartService) {
    $customer = CustomerAuthService::requireLogin('/account/profile');
    $cart = $cartService->getCartDetails();

    $addresses = AccountService::getAddresses((int)$customer['id']);
    $orders = AccountService::getOrders((int)$customer['id'], 1, 1);
    $wishlistCount = WishlistService::getCount((int)$customer['id']);

    $stats = [
        'orders_count'   => (int)($orders['total'] ?? 0),
        'wishlist_count' => $wishlistCount,
        'address_count'  => count($addresses),
    ];

    $activeTab = 'profile';
    $error = flash_get('error');
    $success = flash_get('success');

    include dirname(__DIR__) . '/app/views/store/account.php';
});

/* -------------------------------------------------------------------------
 * ROUTE: POST /account/profile
 * ------------------------------------------------------------------------- */
$router->post('/account/profile', function () {
    if (!csrf_verify()) {
        http_response_code(403);
        echo 'Invalid CSRF token.';
        exit;
    }

    $customer = CustomerAuthService::requireLogin('/account/profile');
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    [$ok, $msg] = CustomerAuthService::updateProfile((int)$customer['id'], $_POST, $clientIp);
    flash_set($ok ? 'success' : 'error', $msg);
    redirect('account/profile');
});

/* -------------------------------------------------------------------------
 * ROUTE: GET /account/password
 * ------------------------------------------------------------------------- */
$router->get('/account/password', function () use ($cartService) {
    $customer = CustomerAuthService::requireLogin('/account/password');
    $cart = $cartService->getCartDetails();

    $addresses = AccountService::getAddresses((int)$customer['id']);
    $orders = AccountService::getOrders((int)$customer['id'], 1, 1);
    $wishlistCount = WishlistService::getCount((int)$customer['id']);

    $stats = [
        'orders_count'   => (int)($orders['total'] ?? 0),
        'wishlist_count' => $wishlistCount,
        'address_count'  => count($addresses),
    ];

    $activeTab = 'password';
    $error = flash_get('error');
    $success = flash_get('success');

    include dirname(__DIR__) . '/app/views/store/account.php';
});

/* -------------------------------------------------------------------------
 * ROUTE: POST /account/password
 * ------------------------------------------------------------------------- */
$router->post('/account/password', function () {
    if (!csrf_verify()) {
        http_response_code(403);
        echo 'Invalid CSRF token.';
        exit;
    }

    $customer = CustomerAuthService::requireLogin('/account/password');
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmation = (string)($_POST['new_password_confirmation'] ?? '');
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    if ($newPassword !== $confirmation) {
        flash_set('error', 'New password confirmation does not match.');
        redirect('account/password');
    }

    [$ok, $msg] = CustomerAuthService::changePassword((int)$customer['id'], $currentPassword, $newPassword, $clientIp);
    flash_set($ok ? 'success' : 'error', $msg);
    redirect('account/password');
});

/* -------------------------------------------------------------------------
 * ROUTE: GET /wishlist
 * ------------------------------------------------------------------------- */
$router->get('/wishlist', function () use ($cartService) {
    $customer = CustomerAuthService::requireLogin('/wishlist');
    $cart = $cartService->getCartDetails();
    $items = WishlistService::getWishlist((int)$customer['id']);

    $error = flash_get('error');
    $success = flash_get('success');

    include dirname(__DIR__) . '/app/views/store/wishlist.php';
});

/* -------------------------------------------------------------------------
 * ROUTE: POST /wishlist/toggle
 * ------------------------------------------------------------------------- */
$router->post('/wishlist/toggle', function () {
    if (!csrf_verify()) {
        if (is_ajax_request()) {
            send_json_response(['success' => false, 'message' => 'Invalid CSRF token.'], 403);
        }
        http_response_code(403);
        echo 'Invalid CSRF token.';
        exit;
    }

    $customer = CustomerAuthService::user();
    if (!$customer) {
        $referer = $_SERVER['HTTP_REFERER'] ?? '/shop';
        $redirectUrl = url('login?next=' . urlencode(CustomerAuthService::sanitizeReturnUrl($referer)));
        if (is_ajax_request()) {
            send_json_response([
                'success'  => false,
                'redirect' => $redirectUrl,
                'message'  => 'Please sign in to save items to your wishlist.',
            ], 401);
        }
        redirect($redirectUrl);
    }

    $productId = isset($_POST['product_id']) && is_numeric($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    $result = WishlistService::toggle((int)$customer['id'], $productId, $clientIp);

    if (is_ajax_request()) {
        send_json_response($result, $result['success'] ? 200 : 400);
    }

    flash_set($result['success'] ? 'success' : 'error', $result['message']);
    redirect($_SERVER['HTTP_REFERER'] ?? 'wishlist');
});

/* -------------------------------------------------------------------------
 * ROUTE: POST /wishlist/remove
 * ------------------------------------------------------------------------- */
$router->post('/wishlist/remove', function () {
    if (!csrf_verify()) {
        http_response_code(403);
        echo 'Invalid CSRF token.';
        exit;
    }

    $customer = CustomerAuthService::requireLogin('/wishlist');
    $productId = isset($_POST['product_id']) && is_numeric($_POST['product_id']) ? (int)$_POST['product_id'] : 0;

    $result = WishlistService::remove((int)$customer['id'], $productId);
    flash_set($result['success'] ? 'success' : 'error', $result['message']);
    redirect('wishlist');
});

/* -------------------------------------------------------------------------
 * ROUTE: POST /wishlist/move-to-cart
 * ------------------------------------------------------------------------- */
$router->post('/wishlist/move-to-cart', function () use ($cartService) {
    if (!csrf_verify()) {
        http_response_code(403);
        echo 'Invalid CSRF token.';
        exit;
    }

    $customer = CustomerAuthService::requireLogin('/wishlist');
    $productId = isset($_POST['product_id']) && is_numeric($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
    $variantId = isset($_POST['variant_id']) && is_numeric($_POST['variant_id']) ? (int)$_POST['variant_id'] : 0;

    if ($variantId > 0) {
        try {
            $addRes = $cartService->addItem($variantId, 1);
            if ($addRes['success']) {
                WishlistService::remove((int)$customer['id'], $productId);
                flash_set('success', 'Item moved to your cart!');
                redirect('cart');
            } else {
                flash_set('error', $addRes['message']);
                redirect('wishlist');
            }
        } catch (\RuntimeException $e) {
            flash_set('error', $e->getMessage());
            redirect('wishlist');
        }
    } else {
        flash_set('error', 'Please select an item option.');
        redirect('wishlist');
    }
});

/* -------------------------------------------------------------------------
 * ROUTE: POST /product/{slug}/reviews
 * ------------------------------------------------------------------------- */
$router->post('/product/{slug}/reviews', function (array $params) use ($catalogService) {
    $slug = trim($params['slug'] ?? '');

    if (!csrf_verify()) {
        http_response_code(403);
        echo 'Invalid CSRF token.';
        exit;
    }

    $customer = CustomerAuthService::requireLogin('/product/' . $slug);
    $product = $catalogService->getProductBySlug($slug);

    if (!$product) {
        http_response_code(404);
        echo 'Product not found.';
        exit;
    }

    $rating = isset($_POST['rating']) && is_numeric($_POST['rating']) ? (int)$_POST['rating'] : 5;
    $title = (string)($_POST['title'] ?? '');
    $body = (string)($_POST['body'] ?? '');
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    [$ok, $msg] = ReviewService::submitReview((int)$customer['id'], (int)$product['id'], $rating, $title, $body, $clientIp);
    flash_set($ok ? 'success' : 'error', $msg);
    redirect('product/' . $slug . '#tab-feedback');
});

/* -------------------------------------------------------------------------
 * ROUTE: GET /sitemap.xml
 * Dynamic XML Sitemap
 * ------------------------------------------------------------------------- */
$router->get('/sitemap.xml', function () {
    header('Content-Type: application/xml; charset=utf-8');
    $pdo = Database::getInstance();

    // Active categories
    $stmtCats = $pdo->query("SELECT slug, updated_at FROM categories WHERE is_active = 1 ORDER BY id ASC");
    $categories = $stmtCats->fetchAll(\PDO::FETCH_ASSOC);

    // Visible products (active, not deleted, in active category)
    $stmtProds = $pdo->query("
        SELECT p.slug, p.updated_at 
        FROM products p 
        JOIN categories c ON p.category_id = c.id 
        WHERE p.is_active = 1 AND p.deleted_at IS NULL AND c.is_active = 1 
        ORDER BY p.id ASC
    ");
    $products = $stmtProds->fetchAll(\PDO::FETCH_ASSOC);

    $nowIso = date('Y-m-d');

    $contentPages = [
        ['path' => '',              'priority' => '1.0', 'freq' => 'daily'],
        ['path' => 'shop',          'priority' => '0.9', 'freq' => 'daily'],
        ['path' => 'about',         'priority' => '0.7', 'freq' => 'monthly'],
        ['path' => 'contact',       'priority' => '0.7', 'freq' => 'monthly'],
        ['path' => 'faq',           'priority' => '0.8', 'freq' => 'weekly'],
        ['path' => 'shipping-info', 'priority' => '0.8', 'freq' => 'weekly'],
        ['path' => 'return-policy', 'priority' => '0.7', 'freq' => 'monthly'],
        ['path' => 'privacy',       'priority' => '0.5', 'freq' => 'monthly'],
        ['path' => 'terms',         'priority' => '0.5', 'freq' => 'monthly'],
        ['path' => 'support',       'priority' => '0.7', 'freq' => 'weekly'],
    ];

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    foreach ($contentPages as $cp) {
        $loc = url($cp['path']);
        echo '  <url>' . "\n";
        echo '    <loc>' . htmlspecialchars($loc, ENT_XML1, 'UTF-8') . '</loc>' . "\n";
        echo '    <lastmod>' . $nowIso . '</lastmod>' . "\n";
        echo '    <changefreq>' . $cp['freq'] . '</changefreq>' . "\n";
        echo '    <priority>' . $cp['priority'] . '</priority>' . "\n";
        echo '  </url>' . "\n";
    }

    foreach ($categories as $cat) {
        $loc = url('category/' . $cat['slug']);
        $lastmod = !empty($cat['updated_at']) ? date('Y-m-d', strtotime($cat['updated_at'])) : $nowIso;
        echo '  <url>' . "\n";
        echo '    <loc>' . htmlspecialchars($loc, ENT_XML1, 'UTF-8') . '</loc>' . "\n";
        echo '    <lastmod>' . $lastmod . '</lastmod>' . "\n";
        echo '    <changefreq>weekly</changefreq>' . "\n";
        echo '    <priority>0.8</priority>' . "\n";
        echo '  </url>' . "\n";
    }

    foreach ($products as $prod) {
        $loc = url('product/' . $prod['slug']);
        $lastmod = !empty($prod['updated_at']) ? date('Y-m-d', strtotime($prod['updated_at'])) : $nowIso;
        echo '  <url>' . "\n";
        echo '    <loc>' . htmlspecialchars($loc, ENT_XML1, 'UTF-8') . '</loc>' . "\n";
        echo '    <lastmod>' . $lastmod . '</lastmod>' . "\n";
        echo '    <changefreq>daily</changefreq>' . "\n";
        echo '    <priority>0.8</priority>' . "\n";
        echo '  </url>' . "\n";
    }

    echo '</urlset>' . "\n";
    exit;
});

/* -------------------------------------------------------------------------
 * ROUTE: GET /robots.txt
 * Dynamic Robots Exclusion
 * ------------------------------------------------------------------------- */
$router->get('/robots.txt', function () {
    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\n";
    echo "Disallow: /admin/\n";
    echo "Disallow: /cart\n";
    echo "Disallow: /search\n";
    echo "\n";
    echo "Sitemap: " . url('sitemap.xml') . "\n";
    exit;
});

/* -------------------------------------------------------------------------
 * ROUTE: POST /cart/test/clear-rate-limits
 * Guarded: ONLY registered when SHOPWISE_TESTING_MODE is active
 * ------------------------------------------------------------------------- */
if (defined('SHOPWISE_TESTING_MODE') && SHOPWISE_TESTING_MODE === true) {
    $router->post('/cart/test/clear-rate-limits', function () {
        CartService::clearRateLimits();
        send_json_response(['success' => true, 'message' => 'Rate limits cleared.']);
    });

    $router->get('/test/security-context-entry', function () {
        try {
            \App\Services\SecurityContext::runAsSystem(function () {
                return true;
            }, \App\Services\SecurityContext::JOB_ORDER_EXPIRATION);
            send_json_response(['allowed' => true]);
        } catch (\DomainException $e) {
            send_json_response(['allowed' => false, 'error' => $e->getMessage()], 403);
        }
    });

    $router->post('/test/security-context-entry', function () {
        try {
            \App\Services\SecurityContext::runAsSystem(function () {
                return true;
            }, \App\Services\SecurityContext::JOB_ORDER_EXPIRATION);
            send_json_response(['allowed' => true]);
        } catch (\DomainException $e) {
            send_json_response(['allowed' => false, 'error' => $e->getMessage()], 403);
        }
    });
}

// Dispatch the incoming request
$router->dispatch();
