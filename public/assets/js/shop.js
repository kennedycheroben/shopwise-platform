/**
 * ShopWise Kenya - Interactive Storefront Engine
 * Handles variant selection, stock checks, add-to-cart, mini-cart updates, and cart actions.
 */

(function () {
    'use strict';

    // Helper: Format KES money string
    function formatMoney(amount) {
        const num = parseFloat(amount) || 0;
        return 'KES ' + num.toLocaleString('en-KE', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    }

    // Helper: Show brief toast notification
    function showToast(message, type = 'success') {
        let toastContainer = document.getElementById('shopToast');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'shopToast';
            toastContainer.className = 'toast-notification alert alert-dismissible fade show';
            document.body.appendChild(toastContainer);
        }

        const bgClass = type === 'success' ? 'alert-success' : (type === 'danger' ? 'alert-danger' : 'alert-info');
        toastContainer.className = `toast-notification alert ${bgClass} alert-dismissible fade show`;
        toastContainer.innerHTML = `
            <div class="d-flex align-items-center">
                <i class="bi ${type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'} me-2 fs-5"></i>
                <div class="flex-grow-1">${message}</div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        `;

        setTimeout(() => {
            if (toastContainer && toastContainer.parentNode) {
                toastContainer.classList.remove('show');
            }
        }, 4000);
    }

    // Get CSRF Token from meta tag
    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    // Get Base URL from meta tag
    function getBaseUrl() {
        const meta = document.querySelector('meta[name="base-url"]');
        const base = meta ? meta.getAttribute('content') : '';
        return base.replace(/\/+$/, '');
    }

    // Sync Mini-Cart HTML and Badge Count
    async function syncMiniCart() {
        try {
            const resp = await fetch(getBaseUrl() + '/cart/mini', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (resp.ok) {
                const data = await resp.json();
                const countBadge = document.getElementById('headerCartCount');
                if (countBadge) {
                    countBadge.textContent = data.items_count;
                }
                const container = document.getElementById('miniCartContainer');
                if (container && data.html) {
                    container.innerHTML = data.html;
                }
            }
        } catch (e) {
            console.error('Failed to sync mini-cart:', e);
        }
    }

    /* -------------------------------------------------------------------------
     * 1. PRODUCT DETAILS PAGE: VARIANT SELECTOR & GALLERY
     * ------------------------------------------------------------------------- */
    const productDetailSection = document.getElementById('product-details');
    if (productDetailSection) {
        const variantsDataEl = document.getElementById('productVariantsJson');
        let variants = [];
        if (variantsDataEl) {
            try {
                variants = JSON.parse(variantsDataEl.textContent);
            } catch (e) {
                console.error('Could not parse variants data', e);
            }
        }

        const variantIdInput = document.getElementById('selectedVariantId');
        const priceNowEl = document.querySelector('.price-now');
        const priceWasEl = document.querySelector('.price-was');
        const saveTagEl = document.querySelector('.save-tag');
        const stockIndicatorEl = document.querySelector('.stock-indicator');
        const stockUnitsEl = document.querySelector('.units-left');
        const addToCartBtn = document.getElementById('addToCartBtn');
        const qtyInput = document.querySelector('.quantity-selector .quantity-input');
        const mainImage = document.getElementById('main-product-image');

        let selectedColor = null;
        let selectedSize = null;

        // Determine currently active color and size from active buttons
        const activeColorBtn = document.querySelector('.color-dot-btn.active');
        if (activeColorBtn) {
            selectedColor = activeColorBtn.getAttribute('data-color');
        }
        const activeSizeBtn = document.querySelector('.size-btn.active');
        if (activeSizeBtn) {
            selectedSize = activeSizeBtn.getAttribute('data-size');
        }

        function updateVariantState() {
            if (!variants.length) return;

            // Find matching variant
            let matchedVariant = null;

            if (selectedColor && selectedSize) {
                matchedVariant = variants.find(v => v.color === selectedColor && v.size === selectedSize);
            } else if (selectedColor) {
                matchedVariant = variants.find(v => v.color === selectedColor);
            } else if (selectedSize) {
                matchedVariant = variants.find(v => v.size === selectedSize);
            } else {
                matchedVariant = variants[0];
            }

            if (!matchedVariant && variants.length > 0) {
                matchedVariant = variants[0];
            }

            if (matchedVariant) {
                if (variantIdInput) {
                    variantIdInput.value = matchedVariant.id;
                }

                // Pricing
                if (priceNowEl) {
                    priceNowEl.textContent = formatMoney(matchedVariant.effective_price);
                }

                const baseCompareAt = parseFloat(productDetailSection.getAttribute('data-compare-at')) || null;
                if (baseCompareAt && baseCompareAt > matchedVariant.effective_price) {
                    if (priceWasEl) {
                        priceWasEl.textContent = formatMoney(baseCompareAt);
                        priceWasEl.style.display = 'inline';
                    }
                    if (saveTagEl) {
                        const saved = baseCompareAt - matchedVariant.effective_price;
                        saveTagEl.textContent = 'Save ' + formatMoney(saved);
                        saveTagEl.style.display = 'inline-block';
                    }
                } else {
                    if (priceWasEl) priceWasEl.style.display = 'none';
                    if (saveTagEl) saveTagEl.style.display = 'none';
                }

                // Stock indicator & add to cart availability
                if (stockIndicatorEl) {
                    if (matchedVariant.status === 'out') {
                        stockIndicatorEl.className = 'stock-indicator stock-badge-out';
                        stockIndicatorEl.innerHTML = '<i class="bi bi-x-circle-fill"></i> Sold Out';
                    } else if (matchedVariant.status === 'low') {
                        stockIndicatorEl.className = 'stock-indicator stock-badge-low';
                        stockIndicatorEl.innerHTML = `<i class="bi bi-exclamation-circle-fill"></i> Only ${matchedVariant.stock_display} left in stock - order soon`;
                    } else {
                        stockIndicatorEl.className = 'stock-indicator stock-badge-in';
                        stockIndicatorEl.innerHTML = '<i class="bi bi-check-circle-fill"></i> In Stock';
                    }
                }

                if (stockUnitsEl) {
                    if (matchedVariant.status === 'low') {
                        stockUnitsEl.textContent = `${matchedVariant.stock_display} remaining`;
                        stockUnitsEl.style.display = 'inline';
                    } else {
                        stockUnitsEl.style.display = 'none';
                    }
                }

                // Max purchasable quantity
                const maxPurchasable = matchedVariant.max_purchasable || 0;
                if (qtyInput) {
                    qtyInput.max = maxPurchasable;
                    if (parseInt(qtyInput.value) > maxPurchasable) {
                        qtyInput.value = Math.max(1, maxPurchasable);
                    }
                }

                if (addToCartBtn) {
                    if (maxPurchasable <= 0) {
                        addToCartBtn.disabled = true;
                        addToCartBtn.innerHTML = '<i class="bi bi-slash-circle"></i> Sold Out';
                    } else {
                        addToCartBtn.disabled = false;
                        addToCartBtn.innerHTML = '<i class="bi bi-bag-plus"></i> Add to Cart';
                    }
                }

                // Update chosen variant label
                const chosenLabel = document.querySelector('.chosen-variant');
                if (chosenLabel) {
                    const desc = [matchedVariant.color, matchedVariant.size].filter(Boolean).join(' / ');
                    chosenLabel.textContent = desc || 'Default';
                }
            }
        }

        // Color clicks
        document.querySelectorAll('.color-dot-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                document.querySelectorAll('.color-dot-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                selectedColor = this.getAttribute('data-color');
                updateVariantState();

                // If thumbnail matches this color, switch main gallery image
                const targetThumb = document.querySelector(`.thumbnail-item[data-color="${selectedColor}"]`);
                if (targetThumb && mainImage) {
                    const newSrc = targetThumb.getAttribute('data-image');
                    mainImage.src = newSrc;
                    document.querySelectorAll('.thumbnail-item').forEach(t => t.classList.remove('active'));
                    targetThumb.classList.add('active');
                }
            });
        });

        // Size clicks
        document.querySelectorAll('.size-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                if (this.disabled) return;
                document.querySelectorAll('.size-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                selectedSize = this.getAttribute('data-size');
                updateVariantState();
            });
        });

        // Gallery thumbnail clicks
        document.querySelectorAll('.thumbnail-item').forEach(thumb => {
            thumb.addEventListener('click', function () {
                const imgUrl = this.getAttribute('data-image');
                if (mainImage && imgUrl) {
                    mainImage.src = imgUrl;
                    document.querySelectorAll('.thumbnail-item').forEach(t => t.classList.remove('active'));
                    this.classList.add('active');
                }
            });
        });

        // Quantity controls
        const btnDecrease = document.querySelector('.quantity-btn.decrease');
        const btnIncrease = document.querySelector('.quantity-btn.increase');

        if (btnDecrease && qtyInput) {
            btnDecrease.addEventListener('click', () => {
                let v = parseInt(qtyInput.value) || 1;
                if (v > 1) {
                    qtyInput.value = v - 1;
                }
            });
        }

        if (btnIncrease && qtyInput) {
            btnIncrease.addEventListener('click', () => {
                let v = parseInt(qtyInput.value) || 1;
                let max = parseInt(qtyInput.max) || 10;
                if (v < max) {
                    qtyInput.value = v + 1;
                }
            });
        }

        // Add to Cart Form Submission (AJAX)
        const addToCartForm = document.getElementById('addToCartForm');
        if (addToCartForm) {
            addToCartForm.addEventListener('submit', async function (e) {
                e.preventDefault();

                const variantId = variantIdInput ? variantIdInput.value : null;
                const quantity = qtyInput ? qtyInput.value : 1;

                if (!variantId) {
                    showToast('Please select a product variant.', 'danger');
                    return;
                }

                if (addToCartBtn) {
                    addToCartBtn.disabled = true;
                    addToCartBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span> Adding...';
                }

                try {
                    const formData = new FormData();
                    formData.append('variant_id', variantId);
                    formData.append('quantity', quantity);
                    formData.append('_csrf_token', getCsrfToken());

                    const resp = await fetch(getBaseUrl() + '/cart/add', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': getCsrfToken()
                        }
                    });

                    const data = await resp.json();

                    if (data.success) {
                        showToast(data.message, 'success');
                        await syncMiniCart();
                    } else {
                        showToast(data.message || 'Could not add item to bag.', 'danger');
                    }
                } catch (err) {
                    console.error(err);
                    showToast('Network error while adding to bag.', 'danger');
                } finally {
                    updateVariantState();
                }
            });
        }

        // Initialize state
        updateVariantState();
    }

    /* -------------------------------------------------------------------------
     * 2. SHOPPING CART PAGE ACTIONS
     * ------------------------------------------------------------------------- */
    const cartSection = document.getElementById('cart');
    if (cartSection) {
        // Quantity change inside cart table
        cartSection.querySelectorAll('.cart-item').forEach(row => {
            const variantId = row.getAttribute('data-variant-id');
            const qtyField = row.querySelector('.quantity-input');
            const decBtn = row.querySelector('.quantity-btn.decrease');
            const incBtn = row.querySelector('.quantity-btn.increase');
            const removeBtn = row.querySelector('.remove-item');

            async function submitQtyChange(newQty) {
                try {
                    const formData = new FormData();
                    formData.append('variant_id', variantId);
                    formData.append('quantity', newQty);
                    formData.append('_csrf_token', getCsrfToken());

                    const resp = await fetch(getBaseUrl() + '/cart/update', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': getCsrfToken()
                        }
                    });
                    const data = await resp.json();
                    if (data.success) {
                        window.location.reload();
                    } else {
                        showToast(data.message || 'Could not update quantity.', 'danger');
                    }
                } catch (err) {
                    showToast('Network error updating cart.', 'danger');
                }
            }

            if (decBtn && qtyField) {
                decBtn.addEventListener('click', () => {
                    let cur = parseInt(qtyField.value) || 1;
                    if (cur > 1) {
                        submitQtyChange(cur - 1);
                    } else {
                        submitQtyChange(0); // Removes item
                    }
                });
            }

            if (incBtn && qtyField) {
                incBtn.addEventListener('click', () => {
                    let cur = parseInt(qtyField.value) || 1;
                    let max = parseInt(qtyField.max) || 10;
                    if (cur < max) {
                        submitQtyChange(cur + 1);
                    } else {
                        showToast(`Maximum allowed per item is ${max}.`, 'warning');
                    }
                });
            }

            if (qtyField) {
                qtyField.addEventListener('change', () => {
                    let cur = parseInt(qtyField.value) || 1;
                    submitQtyChange(cur);
                });
            }

            if (removeBtn) {
                removeBtn.addEventListener('click', async () => {
                    try {
                        const formData = new FormData();
                        formData.append('variant_id', variantId);
                        formData.append('_csrf_token', getCsrfToken());

                        const resp = await fetch(getBaseUrl() + '/cart/remove', {
                            method: 'POST',
                            body: formData,
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-TOKEN': getCsrfToken()
                            }
                        });
                        const data = await resp.json();
                        if (data.success) {
                            window.location.reload();
                        } else {
                            showToast(data.message || 'Could not remove item.', 'danger');
                        }
                    } catch (err) {
                        showToast('Network error removing item.', 'danger');
                    }
                });
            }
        });

        // Clear All Cart Items Button
        const clearCartBtn = document.querySelector('.action-clear');
        if (clearCartBtn) {
            clearCartBtn.addEventListener('click', async () => {
                if (!confirm('Are you sure you want to remove all items from your bag?')) {
                    return;
                }
                try {
                    const formData = new FormData();
                    formData.append('_csrf_token', getCsrfToken());

                    const resp = await fetch(getBaseUrl() + '/cart/clear', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': getCsrfToken()
                        }
                    });
                    const data = await resp.json();
                    if (data.success) {
                        window.location.reload();
                    }
                } catch (err) {
                    showToast('Network error clearing cart.', 'danger');
                }
            });
        }

        // Apply Coupon Form
        const couponForm = document.getElementById('cartCouponForm');
        if (couponForm) {
            couponForm.addEventListener('submit', async function (e) {
                e.preventDefault();
                const input = document.getElementById('couponCodeInput');
                const code = input ? input.value.trim() : '';
                if (!code) return;

                try {
                    const formData = new FormData();
                    formData.append('code', code);
                    formData.append('_csrf_token', getCsrfToken());

                    const resp = await fetch(getBaseUrl() + '/cart/coupon/apply', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': getCsrfToken()
                        }
                    });
                    const data = await resp.json();
                    if (data.success) {
                        window.location.reload();
                    } else {
                        showToast(data.message || 'Coupon could not be applied.', 'danger');
                    }
                } catch (err) {
                    showToast('Network error applying coupon.', 'danger');
                }
            });
        }

        // Remove Coupon Button
        const removeCouponBtn = document.getElementById('removeCouponBtn');
        if (removeCouponBtn) {
            removeCouponBtn.addEventListener('click', async () => {
                try {
                    const formData = new FormData();
                    formData.append('_csrf_token', getCsrfToken());

                    const resp = await fetch(getBaseUrl() + '/cart/coupon/remove', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': getCsrfToken()
                        }
                    });
                    const data = await resp.json();
                    if (data.success) {
                        window.location.reload();
                    }
                } catch (err) {
                    showToast('Network error removing coupon.', 'danger');
                }
            });
        }
    }

    // Mini-Cart Remove Item Handler (Delegated for dynamically updated mini-carts)
    document.addEventListener('click', async function (e) {
        const removeBtn = e.target.closest('.remove-cart-item-btn');
        if (removeBtn) {
            e.preventDefault();
            const variantId = removeBtn.getAttribute('data-variant-id');
            if (!variantId) return;

            try {
                const formData = new FormData();
                formData.append('variant_id', variantId);
                formData.append('_csrf_token', getCsrfToken());

                const resp = await fetch(getBaseUrl() + '/cart/remove', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': getCsrfToken()
                    }
                });
                const data = await resp.json();
                if (data.success) {
                    await syncMiniCart();
                    showToast(data.message, 'success');
                    // If on /cart page, reload to sync table
                    if (document.getElementById('cart')) {
                        window.location.reload();
                    }
                }
            } catch (err) {
                console.error(err);
            }
        }
    });

    // Wishlist Toggle Handler (AJAX with safe redirect fallback for guests)
    document.addEventListener('click', async function (e) {
        const wishlistBtn = e.target.closest('.wishlist-toggle-btn');
        if (wishlistBtn) {
            e.preventDefault();
            const productId = wishlistBtn.getAttribute('data-product-id');
            if (!productId) return;

            try {
                const formData = new FormData();
                formData.append('product_id', productId);
                formData.append('csrf_token', getCsrfToken());

                const resp = await fetch(getBaseUrl() + '/wishlist/toggle', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': getCsrfToken()
                    }
                });

                if (resp.status === 401) {
                    const data = await resp.json().catch(() => ({}));
                    const redirectUrl = data.redirect || (getBaseUrl() + '/login?next=' + encodeURIComponent(window.location.pathname + window.location.search));
                    window.location.href = redirectUrl;
                    return;
                }

                const data = await resp.json();
                if (data.redirect) {
                    window.location.href = data.redirect;
                    return;
                }

                if (data.success) {
                    const icon = wishlistBtn.querySelector('i');
                    if (data.action === 'added') {
                        wishlistBtn.classList.add('active', 'text-danger');
                        if (icon) {
                            icon.className = 'bi bi-heart-fill text-danger';
                        }
                        showToast(data.message || 'Saved to your wishlist', 'success');
                    } else {
                        wishlistBtn.classList.remove('active', 'text-danger');
                        if (icon) {
                            icon.className = 'bi bi-heart text-secondary';
                        }
                        showToast(data.message || 'Removed from wishlist', 'info');
                    }
                    const countBadge = document.getElementById('headerWishlistCount');
                    if (countBadge && typeof data.count !== 'undefined') {
                        countBadge.textContent = data.count;
                    }
                } else if (data.message) {
                    showToast(data.message, 'warning');
                }
            } catch (err) {
                console.error(err);
            }
        }
    });

})();
