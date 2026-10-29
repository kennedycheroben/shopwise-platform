<?php
/**
 * Header Mini-Cart Partial
 * Rendered server-side and updated via AJAX
 * Expects $cart array from CartService::getCartDetails()
 */
$items = $cart['items'] ?? [];
$itemsCount = (int)($cart['items_count'] ?? 0);
$subtotal = (float)($cart['subtotal'] ?? 0.0);
?>
<div class="flyout-top">
    <h6>Your Bag</h6>
    <span class="items-label"><?= $itemsCount ?> <?= $itemsCount === 1 ? 'item' : 'items' ?></span>
</div>

<div class="flyout-items" id="miniCartItems">
    <?php if (empty($items)): ?>
        <div class="mini-cart-empty">
            <i class="bi bi-bag mb-2" style="font-size: 2rem; display: block; opacity: 0.5;"></i>
            <p class="mb-2">Your shopping bag is empty.</p>
            <a href="<?= e(url('shop')) ?>" class="btn btn-sm btn-outline-dark">Browse Catalog</a>
        </div>
    <?php else: ?>
        <?php foreach ($items as $item): ?>
            <div class="flyout-item" data-variant-id="<?= (int)$item['variant_id'] ?>">
                <div class="flyout-item-thumb">
                    <img src="<?= e(product_image_url($item['image_path'])) ?>" alt="<?= e($item['product_name']) ?>" class="img-fluid">
                </div>
                <div class="flyout-item-details">
                    <h6><a href="<?= e(url('product/' . $item['product_slug'])) ?>" class="text-dark text-decoration-none"><?= e($item['product_name']) ?></a></h6>
                    <?php if (!empty($item['size']) || !empty($item['color'])): ?>
                        <span class="item-option">
                            <?= e(trim(($item['color'] ?? '') . ' / ' . ($item['size'] ?? ''), ' /')) ?>
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($item['notice'])): ?>
                        <small class="text-danger d-block"><?= e($item['notice']) ?></small>
                    <?php endif; ?>
                    <div class="item-bottom">
                        <span class="item-price"><?= e(format_money($item['price'])) ?></span>
                        <span class="item-qty">x<?= (int)$item['quantity'] ?></span>
                    </div>
                </div>
                <button class="item-dismiss remove-cart-item-btn" data-variant-id="<?= (int)$item['variant_id'] ?>" type="button" aria-label="Remove item">
                    <i class="bi bi-trash3"></i>
                </button>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="flyout-bottom" id="miniCartBottom" <?= empty($items) ? 'style="display:none;"' : '' ?>>
    <div class="subtotal-row">
        <span>Subtotal</span>
        <span class="subtotal-value" id="miniCartSubtotal"><?= e(format_money($subtotal)) ?></span>
    </div>
    <button type="button" class="btn btn-proceed w-100" disabled aria-disabled="true">Checkout opens soon</button>
    <a href="<?= e(url('cart')) ?>" class="link-viewbag text-center mt-2 d-block">View full bag →</a>
</div>
