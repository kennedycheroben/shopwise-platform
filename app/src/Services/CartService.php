<?php

namespace App\Services;

use App\Database\Database;
use PDO;

/**
 * Server-Side, DB-Backed Guest and Customer Cart Service
 *
 * Rules:
 * - Session token (32 random bytes hex) stored in HttpOnly, SameSite=Lax cookie.
 * - Client sends ONLY variant_id and quantity.
 * - Server recomputes totals, discounts, availability, and stock clamping on every render.
 * - Rate limit: 60 cart writes per minute per session token and client IP.
 * - Max 50 distinct items per cart.
 */
class CartService
{
    private PDO $pdo;
    private ?string $sessionToken = null;
    private ?array $cartRecord = null;

    public const COOKIE_NAME = 'shopwise_cart';
    public const COOKIE_LIFETIME = 2592000; // 30 days
    public const MAX_CART_LINES = 50;
    public const MAX_QUANTITY_PER_LINE = 10;
    public const RATE_LIMIT_WRITES_PER_MINUTE = 60;
    public const RATE_LIMIT_IP_WRITES_PER_MINUTE = 300;

    public function __construct(?PDO $pdo = null, ?string $sessionToken = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
        $this->sessionToken = $sessionToken;
    }

    /**
     * Get or initialize session token from cookie, parameter, or generate new
     */
    public function getSessionToken(): string
    {
        if ($this->sessionToken !== null && preg_match('/^[a-f0-9]{64}$/', $this->sessionToken)) {
            return $this->sessionToken;
        }

        $cookieToken = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (!empty($cookieToken) && is_string($cookieToken) && preg_match('/^[a-f0-9]{64}$/', $cookieToken)) {
            $this->sessionToken = $cookieToken;
            return $this->sessionToken;
        }

        // Generate 32 bytes (64 hex characters)
        $this->sessionToken = bin2hex(random_bytes(32));
        $this->sendCookie($this->sessionToken);

        return $this->sessionToken;
    }

    /**
     * Set the session cookie with secure flags
     */
    public function sendCookie(string $token): void
    {
        if (headers_sent()) {
            return;
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        setcookie(self::COOKIE_NAME, $token, [
            'expires'  => time() + self::COOKIE_LIFETIME,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE_NAME] = $token;
    }

    /**
     * Find existing cart for the current session token or logged-in customer without creating one
     */
    public function findCart(): ?array
    {
        if ($this->cartRecord !== null) {
            return $this->cartRecord;
        }

        // 1. Check logged-in customer cart first
        $customer = CustomerAuthService::user();
        if ($customer && !empty($customer['id'])) {
            $stmt = $this->pdo->prepare("SELECT * FROM carts WHERE user_id = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([(int)$customer['id']]);
            $cart = $stmt->fetch();
            if ($cart) {
                $this->cartRecord = $cart;
                $this->sessionToken = $cart['session_token'];
                return $cart;
            }
        }

        // 2. Fall back to session token / cookie
        $token = $this->sessionToken;
        if ($token === null) {
            $cookieToken = $_COOKIE[self::COOKIE_NAME] ?? null;
            if (!empty($cookieToken) && is_string($cookieToken) && preg_match('/^[a-f0-9]{64}$/', $cookieToken)) {
                $token = $cookieToken;
            }
        }

        if ($token === null) {
            return null;
        }

        $stmt = $this->pdo->prepare("SELECT * FROM carts WHERE session_token = ? LIMIT 1");
        $stmt->execute([$token]);
        $cart = $stmt->fetch();

        if ($cart) {
            $this->cartRecord = $cart;
            $this->sessionToken = $token;
            return $cart;
        }

        return null;
    }

    /**
     * Get or create the database cart row for the current session token or customer
     */
    public function getOrCreateCart(): array
    {
        $existing = $this->findCart();
        if ($existing !== null) {
            return $existing;
        }

        $token = $this->getSessionToken();
        $customer = CustomerAuthService::user();
        $userId = ($customer && !empty($customer['id'])) ? (int)$customer['id'] : null;

        // Create new cart
        $insertStmt = $this->pdo->prepare(
            "INSERT INTO carts (user_id, session_token, coupon_code, created_at, updated_at) VALUES (?, ?, NULL, NOW(), NOW())"
        );
        $insertStmt->execute([$userId, $token]);
        $newId = (int)$this->pdo->lastInsertId();

        $fetchStmt = $this->pdo->prepare("SELECT * FROM carts WHERE id = ? LIMIT 1");
        $fetchStmt->execute([$newId]);
        $this->cartRecord = $fetchStmt->fetch();

        return $this->cartRecord;
    }

    /**
     * Clear rate limit tracking files (for automated testing isolation)
     */
    public static function clearRateLimits(?string $dir = null): void
    {
        $config = require dirname(__DIR__, 2) . '/config/config.php';
        $storageDir = $dir ?? ($config['rate_limits']['storage_dir'] ?? (dirname(__DIR__, 2) . '/app/storage/rate_limits'));
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0755, true);
        }
        if (is_dir($storageDir)) {
            foreach (glob($storageDir . '/*.json') as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * Check rate limiting: configurable dual limits:
     * - 60 cart writes per minute per session token
     * - 300 cart writes per minute per client IP
     * Prevents cart-stuffing DoS and brute-force coupon/variant probing.
     */
    public function enforceRateLimit(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/config.php';
        $sessionLimit = (int)($config['rate_limits']['cart_writes_per_session'] ?? self::RATE_LIMIT_WRITES_PER_MINUTE);
        $ipLimit = (int)($config['rate_limits']['cart_writes_per_ip'] ?? self::RATE_LIMIT_IP_WRITES_PER_MINUTE);
        $storageDir = $config['rate_limits']['storage_dir'] ?? (dirname(__DIR__, 2) . '/app/storage/rate_limits');

        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0755, true);
        }

        $now = time();
        $window = 60; // 60 seconds

        // 1. Enforce session token rate limit
        $token = $this->getSessionToken();
        $sessKey = 'sess_' . hash('sha256', $token);
        $sessFile = $storageDir . '/' . $sessKey . '.json';
        $sessTimestamps = [];
        if (file_exists($sessFile)) {
            $content = @file_get_contents($sessFile);
            $data = $content ? @json_decode($content, true) : null;
            if (is_array($data)) {
                $sessTimestamps = array_filter($data, fn($ts) => ($now - (int)$ts) < $window);
            }
        }

        if (count($sessTimestamps) >= $sessionLimit) {
            http_response_code(429);
            throw new \RuntimeException("Too many cart requests for this session. Please slow down and try again in a moment.");
        }

        // 2. Enforce client IP rate limit
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $isLocal = (($config['app']['environment'] ?? '') === 'local');
        if ($isLocal && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($parts[0]);
        }
        $ipKey = 'ip_' . hash('sha256', $ip);
        $ipFile = $storageDir . '/' . $ipKey . '.json';
        $ipTimestamps = [];
        if (file_exists($ipFile)) {
            $content = @file_get_contents($ipFile);
            $data = $content ? @json_decode($content, true) : null;
            if (is_array($data)) {
                $ipTimestamps = array_filter($data, fn($ts) => ($now - (int)$ts) < $window);
            }
        }

        if (count($ipTimestamps) >= $ipLimit) {
            http_response_code(429);
            throw new \RuntimeException("Too many cart requests from this IP address. Please slow down and try again in a moment.");
        }

        // Record timestamp for both session and IP
        $sessTimestamps[] = $now;
        @file_put_contents($sessFile, json_encode(array_values($sessTimestamps)), LOCK_EX);

        $ipTimestamps[] = $now;
        @file_put_contents($ipFile, json_encode(array_values($ipTimestamps)), LOCK_EX);
    }

    /**
     * Get the full cart details with server-recomputed prices, availability,
     * stock clamping, and coupon discount evaluation.
     */
    public function getCartDetails(): array
    {
        $cart = $this->findCart();
        if ($cart === null) {
            return [
                'cart' => null,
                'session_token' => $this->sessionToken ?? ($_COOKIE[self::COOKIE_NAME] ?? null),
                'items' => [],
                'subtotal' => 0.0,
                'subtotal_formatted' => format_money(0),
                'item_count' => 0,
                'unique_items_count' => 0,
                'coupon_code' => null,
                'coupon_discount' => 0.0,
                'coupon_discount_formatted' => format_money(0),
                'coupon_error' => null,
                'notices' => [],
                'is_empty' => true,
            ];
        }

        $cartId = (int)$cart['id'];

        $catalogService = new CatalogService($this->pdo);
        $activeCategoriesMap = $catalogService->getActiveCategoriesMap();

        $stmt = $this->pdo->prepare(
            "SELECT ci.id AS item_id, ci.variant_id, ci.quantity,
                    pv.sku, pv.size, pv.color, pv.color_hex, pv.stock_qty, pv.low_stock_threshold,
                    pv.price_override, pv.is_active AS variant_active,
                    p.id AS product_id, p.name AS product_name, p.slug AS product_slug,
                    p.base_price, p.compare_at_price, p.is_active AS product_active, p.deleted_at AS product_deleted,
                    c.id AS category_id, c.name AS category_name, c.slug AS category_slug, c.is_active AS category_active,
                    (SELECT pi.path FROM product_images pi WHERE pi.product_id = p.id ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC LIMIT 1) AS image_path
             FROM cart_items ci
             JOIN product_variants pv ON pv.id = ci.variant_id
             JOIN products p ON p.id = pv.product_id
             JOIN categories c ON c.id = p.category_id
             WHERE ci.cart_id = ?
             ORDER BY ci.id ASC"
        );
        $stmt->execute([$cartId]);
        $rawItems = $stmt->fetchAll();

        $items = [];
        $subtotal = 0.0;
        $totalItemsCount = 0;
        $notices = [];

        foreach ($rawItems as $row) {
            $itemId = (int)$row['item_id'];
            $variantId = (int)$row['variant_id'];
            $reqQty = (int)$row['quantity'];
            $stockQty = (int)$row['stock_qty'];
            $catId = (int)$row['category_id'];

            // Check if product, variant, or category chain is inactive or deleted
            $isCategoryChainActive = isset($activeCategoriesMap[$catId]);
            $isAvailable = (!empty($row['variant_active'])
                && !empty($row['product_active'])
                && empty($row['product_deleted'])
                && $isCategoryChainActive
                && $stockQty > 0);

            $effPrice = $row['price_override'] !== null ? (float)$row['price_override'] : (float)$row['base_price'];
            $itemNotice = null;

            if (!$isAvailable) {
                if ($stockQty <= 0 && !empty($row['variant_active']) && !empty($row['product_active']) && empty($row['product_deleted'])) {
                    $itemNotice = "This item is sold out and excluded from your subtotal.";
                } else {
                    $itemNotice = "This item is no longer available and excluded from your subtotal.";
                }
            } else {
                // Available item: verify and clamp stock
                $maxPurchasable = min($stockQty, self::MAX_QUANTITY_PER_LINE);
                if ($reqQty > $maxPurchasable) {
                    $reqQty = $maxPurchasable;
                    $itemNotice = "Quantity was adjusted to match available stock ({$reqQty}).";
                    // Update clamped quantity in DB
                    $updateStmt = $this->pdo->prepare("UPDATE cart_items SET quantity = ?, updated_at = NOW() WHERE id = ?");
                    $updateStmt->execute([$reqQty, $itemId]);
                }

                $lineTotal = $effPrice * $reqQty;
                $subtotal += $lineTotal;
                $totalItemsCount += $reqQty;
            }

            if ($itemNotice !== null) {
                $notices[] = "{$row['product_name']}" . (!empty($row['size']) ? " ({$row['size']})" : "") . ": {$itemNotice}";
            }

            $items[] = [
                'item_id'          => $itemId,
                'variant_id'       => $variantId,
                'product_id'       => (int)$row['product_id'],
                'product_name'     => $row['product_name'],
                'product_slug'     => $row['product_slug'],
                'sku'              => $row['sku'],
                'size'             => $row['size'],
                'color'            => $row['color'],
                'color_hex'        => $row['color_hex'],
                'image_path'       => $row['image_path'] ?? 'assets/img/product/product-1.webp',
                'price'            => $effPrice,
                'compare_at_price' => $row['compare_at_price'] !== null ? (float)$row['compare_at_price'] : null,
                'quantity'         => $reqQty,
                'line_total'       => $effPrice * $reqQty,
                'is_available'     => $isAvailable,
                'max_purchasable'  => $isAvailable ? min($stockQty, self::MAX_QUANTITY_PER_LINE) : 0,
                'notice'           => $itemNotice,
            ];
        }

        // Coupon preview evaluation
        $couponData = null;
        $discountAmount = 0.0;
        $couponCode = !empty($cart['coupon_code']) ? trim((string)$cart['coupon_code']) : null;

        if ($couponCode !== null) {
            $couponResult = CouponService::evaluate($couponCode, $subtotal, null);
            if ($couponResult['valid']) {
                $discountAmount = (float)$couponResult['discount'];
                $couponData = [
                    'code'            => $couponCode,
                    'discount_type'   => $couponResult['coupon']['type'],
                    'discount_value'  => (float)$couponResult['coupon']['value'],
                    'discount_amount' => $discountAmount,
                ];
            } else {
                // Coupon invalid: remove from cart
                $notices[] = "Applied coupon '{$couponCode}' is no longer valid: {$couponResult['message']}";
                $clearCouponStmt = $this->pdo->prepare("UPDATE carts SET coupon_code = NULL, updated_at = NOW() WHERE id = ?");
                $clearCouponStmt->execute([$cartId]);
                $this->cartRecord['coupon_code'] = null;
            }
        }

        return [
            'id'              => $cartId,
            'session_token'   => $cart['session_token'],
            'items'           => $items,
            'items_count'     => $totalItemsCount,
            'distinct_count'  => count($items),
            'subtotal'        => $subtotal,
            'coupon'          => $couponData,
            'discount'        => $discountAmount,
            'final_subtotal'  => max(0.0, $subtotal - $discountAmount),
            'notices'         => $notices,
        ];
    }

    /**
     * Add item to cart atomically with stock checking, cap enforcement, and ON DUPLICATE KEY UPDATE.
     *
     * @param int $variantId
     * @param int $quantity
     * @return array [success => bool, message => string, cart => array]
     */
    public function addItem(int $variantId, int $quantity): array
    {
        $this->enforceRateLimit();

        if ($variantId <= 0) {
            return ['success' => false, 'message' => 'Invalid product variant.'];
        }

        if ($quantity <= 0) {
            return ['success' => false, 'message' => 'Quantity must be at least 1.'];
        }

        $cart = $this->getOrCreateCart();
        $cartId = (int)$cart['id'];

        // Validate variant, product, and category
        $stmt = $this->pdo->prepare(
            "SELECT pv.id AS variant_id, pv.stock_qty, pv.is_active AS variant_active,
                    p.id AS product_id, p.name AS product_name, p.is_active AS product_active, p.deleted_at AS product_deleted,
                    c.id AS category_id
             FROM product_variants pv
             JOIN products p ON p.id = pv.product_id
             JOIN categories c ON c.id = p.category_id
             WHERE pv.id = ?
             LIMIT 1"
        );
        $stmt->execute([$variantId]);
        $row = $stmt->fetch();

        if (!$row || empty($row['variant_active']) || empty($row['product_active']) || !empty($row['product_deleted'])) {
            return ['success' => false, 'message' => 'This product variant is unavailable.'];
        }

        $catalogService = new CatalogService($this->pdo);
        $activeMap = $catalogService->getActiveCategoriesMap();
        if (!isset($activeMap[(int)$row['category_id']])) {
            return ['success' => false, 'message' => 'This product category is currently inactive.'];
        }

        $stockQty = (int)$row['stock_qty'];
        if ($stockQty <= 0) {
            return ['success' => false, 'message' => 'This item is currently sold out.'];
        }

        $maxPurchasable = min($stockQty, self::MAX_QUANTITY_PER_LINE);

        // Check 50-line distinct item cap
        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM cart_items WHERE cart_id = ?");
        $countStmt->execute([$cartId]);
        $currentLines = (int)$countStmt->fetchColumn();

        $existingStmt = $this->pdo->prepare("SELECT quantity FROM cart_items WHERE cart_id = ? AND variant_id = ?");
        $existingStmt->execute([$cartId, $variantId]);
        $existingQty = $existingStmt->fetchColumn();

        if ($existingQty === false && $currentLines >= self::MAX_CART_LINES) {
            return ['success' => false, 'message' => 'Your cart cannot contain more than 50 distinct items.'];
        }

        // Atomic insertion / update with LEAST constraint
        $cappedAdd = min($quantity, $maxPurchasable);

        $upsertSql = "INSERT INTO cart_items (cart_id, variant_id, quantity, created_at, updated_at)
                      VALUES (?, ?, ?, NOW(), NOW())
                      ON DUPLICATE KEY UPDATE
                          quantity = LEAST(quantity + VALUES(quantity), ?),
                          updated_at = NOW()";

        $upsertStmt = $this->pdo->prepare($upsertSql);
        $upsertStmt->execute([$cartId, $variantId, $cappedAdd, $maxPurchasable]);

        // Touch cart updated_at
        $touchStmt = $this->pdo->prepare("UPDATE carts SET updated_at = NOW() WHERE id = ?");
        $touchStmt->execute([$cartId]);

        $cartDetails = $this->getCartDetails();
        return [
            'success' => true,
            'message' => "Added {$row['product_name']} to your bag.",
            'cart'    => $cartDetails,
        ];
    }

    /**
     * Update item quantity in cart
     */
    public function updateItem(int $variantId, int $quantity): array
    {
        $this->enforceRateLimit();

        if ($variantId <= 0) {
            return ['success' => false, 'message' => 'Invalid product variant.'];
        }

        if ($quantity <= 0) {
            return $this->removeItem($variantId);
        }

        $cart = $this->findCart();
        if (!$cart) {
            return ['success' => false, 'message' => 'Item not found in cart.'];
        }
        $cartId = (int)$cart['id'];

        $checkStmt = $this->pdo->prepare(
            "SELECT ci.id, pv.stock_qty, p.name AS product_name
             FROM cart_items ci
             JOIN product_variants pv ON pv.id = ci.variant_id
             JOIN products p ON p.id = pv.product_id
             WHERE ci.cart_id = ? AND ci.variant_id = ?
             LIMIT 1"
        );
        $checkStmt->execute([$cartId, $variantId]);
        $row = $checkStmt->fetch();

        if (!$row) {
            return ['success' => false, 'message' => 'Item not found in cart.'];
        }

        $stock = (int)$row['stock_qty'];
        $maxAllowed = min($stock, self::MAX_QUANTITY_PER_LINE);
        $clampedQty = min($quantity, $maxAllowed);

        $updateStmt = $this->pdo->prepare("UPDATE cart_items SET quantity = ?, updated_at = NOW() WHERE cart_id = ? AND variant_id = ?");
        $updateStmt->execute([$clampedQty, $cartId, $variantId]);

        $touchStmt = $this->pdo->prepare("UPDATE carts SET updated_at = NOW() WHERE id = ?");
        $touchStmt->execute([$cartId]);

        $notice = ($clampedQty < $quantity)
            ? "Quantity adjusted to match available stock ({$clampedQty})."
            : "Quantity updated.";

        $cartDetails = $this->getCartDetails();
        return [
            'success' => true,
            'message' => $notice,
            'cart'    => $cartDetails,
        ];
    }

    /**
     * Remove item from cart
     */
    public function removeItem(int $variantId): array
    {
        $this->enforceRateLimit();

        $cart = $this->findCart();
        if (!$cart) {
            return [
                'success' => true,
                'message' => 'Item removed from your bag.',
                'cart'    => $this->getCartDetails(),
            ];
        }
        $cartId = (int)$cart['id'];

        $delStmt = $this->pdo->prepare("DELETE FROM cart_items WHERE cart_id = ? AND variant_id = ?");
        $delStmt->execute([$cartId, $variantId]);

        $touchStmt = $this->pdo->prepare("UPDATE carts SET updated_at = NOW() WHERE id = ?");
        $touchStmt->execute([$cartId]);

        $cartDetails = $this->getCartDetails();
        return [
            'success' => true,
            'message' => 'Item removed from your bag.',
            'cart'    => $cartDetails,
        ];
    }

    /**
     * Clear all items from cart
     */
    public function clearCart(): array
    {
        $this->enforceRateLimit();

        $cart = $this->findCart();
        if (!$cart) {
            return [
                'success' => true,
                'message' => 'Cart cleared.',
                'cart'    => $this->getCartDetails(),
            ];
        }
        $cartId = (int)$cart['id'];

        $delStmt = $this->pdo->prepare("DELETE FROM cart_items WHERE cart_id = ?");
        $delStmt->execute([$cartId]);

        $touchStmt = $this->pdo->prepare("UPDATE carts SET coupon_code = NULL, updated_at = NOW() WHERE id = ?");
        $touchStmt->execute([$cartId]);

        $cartDetails = $this->getCartDetails();
        return [
            'success' => true,
            'message' => 'Cart cleared.',
            'cart'    => $cartDetails,
        ];
    }

    /**
     * Apply coupon code for cart preview
     */
    public function applyCoupon(string $code): array
    {
        $this->enforceRateLimit();

        $code = strtoupper(trim($code));
        if ($code === '' || !CouponService::isValidCodeFormat($code)) {
            return ['success' => false, 'message' => 'Invalid coupon code format.'];
        }

        $cartDetails = $this->getCartDetails();
        $subtotal = (float)$cartDetails['subtotal'];

        if ($subtotal <= 0) {
            return ['success' => false, 'message' => 'Add items to your cart before applying a coupon.'];
        }

        $eval = CouponService::evaluate($code, $subtotal, null);
        if (!$eval['valid']) {
            return ['success' => false, 'message' => $eval['message'] ?? 'Coupon could not be applied.'];
        }

        $cart = $this->getOrCreateCart();
        $cartId = (int)$cart['id'];

        $updateStmt = $this->pdo->prepare("UPDATE carts SET coupon_code = ?, updated_at = NOW() WHERE id = ?");
        $updateStmt->execute([$code, $cartId]);
        $this->cartRecord['coupon_code'] = $code;

        $updatedDetails = $this->getCartDetails();
        return [
            'success' => true,
            'message' => "Coupon '{$code}' applied! You save " . format_money($eval['discount']) . ".",
            'cart'    => $updatedDetails,
        ];
    }

    /**
     * Remove applied coupon
     */
    public function removeCoupon(): array
    {
        $this->enforceRateLimit();

        $cart = $this->findCart();
        if (!$cart) {
            return [
                'success' => true,
                'message' => 'Coupon removed.',
                'cart'    => $this->getCartDetails(),
            ];
        }
        $cartId = (int)$cart['id'];

        $updateStmt = $this->pdo->prepare("UPDATE carts SET coupon_code = NULL, updated_at = NOW() WHERE id = ?");
        $updateStmt->execute([$cartId]);
        $this->cartRecord['coupon_code'] = null;

        $updatedDetails = $this->getCartDetails();
        return [
            'success' => true,
            'message' => 'Coupon removed.',
            'cart'    => $updatedDetails,
        ];
    }

    /**
     * Merge guest cart into customer cart in a single transaction
     */
    public function mergeGuestCart(int $userId, string $guestToken): void
    {
        if (empty($guestToken) || !preg_match('/^[a-f0-9]{64}$/', $guestToken)) {
            return;
        }

        // Find the guest cart by session token
        $stmt = $this->pdo->prepare("SELECT * FROM carts WHERE session_token = ? LIMIT 1");
        $stmt->execute([$guestToken]);
        $guestCart = $stmt->fetch();

        if (!$guestCart) {
            return;
        }

        $guestCartId = (int)$guestCart['id'];

        // Find customer's existing cart by user_id
        $stmt = $this->pdo->prepare("SELECT * FROM carts WHERE user_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$userId]);
        $userCart = $stmt->fetch();

        $this->pdo->beginTransaction();
        try {
            if (!$userCart) {
                // If customer has no existing cart, attach user_id to guest cart
                $stmt = $this->pdo->prepare("UPDATE carts SET user_id = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$userId, $guestCartId]);
            } elseif ((int)$userCart['id'] === $guestCartId) {
                if (empty($userCart['user_id'])) {
                    $stmt = $this->pdo->prepare("UPDATE carts SET user_id = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$userId, $guestCartId]);
                }
            } else {
                $userCartId = (int)$userCart['id'];

                // Fetch guest items
                $stmt = $this->pdo->prepare("SELECT * FROM cart_items WHERE cart_id = ?");
                $stmt->execute([$guestCartId]);
                $guestItems = $stmt->fetchAll();

                // Fetch user items
                $stmt = $this->pdo->prepare("SELECT * FROM cart_items WHERE cart_id = ?");
                $stmt->execute([$userCartId]);
                $userItems = $stmt->fetchAll();

                $userItemsByVariant = [];
                foreach ($userItems as $uItem) {
                    $userItemsByVariant[(int)$uItem['variant_id']] = $uItem;
                }

                $currentLineCount = count($userItems);

                foreach ($guestItems as $gItem) {
                    $vId = (int)$gItem['variant_id'];
                    $gQty = (int)$gItem['quantity'];

                    // Check stock
                    $sStmt = $this->pdo->prepare("SELECT stock_qty FROM product_variants WHERE id = ?");
                    $sStmt->execute([$vId]);
                    $stock = (int)$sStmt->fetchColumn();
                    if ($stock <= 0) {
                        continue;
                    }

                    if (isset($userItemsByVariant[$vId])) {
                        $uItem = $userItemsByVariant[$vId];
                        $combinedQty = min((int)$uItem['quantity'] + $gQty, $stock, self::MAX_QUANTITY_PER_LINE);
                        $upStmt = $this->pdo->prepare("UPDATE cart_items SET quantity = ?, updated_at = NOW() WHERE id = ?");
                        $upStmt->execute([$combinedQty, (int)$uItem['id']]);
                    } else {
                        if ($currentLineCount < self::MAX_CART_LINES) {
                            $clampedQty = min($gQty, $stock, self::MAX_QUANTITY_PER_LINE);
                            if ($clampedQty > 0) {
                                $inStmt = $this->pdo->prepare("
                                    INSERT INTO cart_items (cart_id, variant_id, quantity, created_at, updated_at)
                                    VALUES (?, ?, ?, NOW(), NOW())
                                ");
                                $inStmt->execute([$userCartId, $vId, $clampedQty]);
                                $currentLineCount++;
                            }
                        }
                    }
                }

                // Coupon preservation: customer cart coupon wins, else guest cart coupon
                if (empty($userCart['coupon_code']) && !empty($guestCart['coupon_code'])) {
                    $cStmt = $this->pdo->prepare("UPDATE carts SET coupon_code = ?, updated_at = NOW() WHERE id = ?");
                    $cStmt->execute([$guestCart['coupon_code'], $userCartId]);
                }

                // Delete guest items and guest cart
                $this->pdo->prepare("DELETE FROM cart_items WHERE cart_id = ?")->execute([$guestCartId]);
                $this->pdo->prepare("DELETE FROM carts WHERE id = ?")->execute([$guestCartId]);
            }

            $this->pdo->commit();
            $this->cartRecord = null;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("Failed to merge guest cart: " . $e->getMessage());
        }
    }

    /**
     * Backward-compatible alias for mergeUserCart
     */
    public function mergeUserCart(int $userId): void
    {
        $token = $this->getSessionToken();
        $this->mergeGuestCart($userId, $token);
    }
}
