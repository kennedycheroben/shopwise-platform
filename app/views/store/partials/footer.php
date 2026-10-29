<?php
/**
 * Main Storefront Footer Partial
 * Displays store information strictly from dynamic database settings
 * Keeps BootstrapMade credit link intact
 */
use App\Services\CatalogService;

$catalogService = new CatalogService();
$footerCategories = array_slice($catalogService->getCategoryTree(), 0, 6);
?>
<footer id="footer" class="footer">

    <div class="footer-main">
        <div class="container">
            <div class="row gy-4">

                <!-- About & Contact Information from Settings -->
                <div class="col-lg-4 col-md-6 col-sm-12">
                    <div class="footer-widget footer-about">
                        <a href="<?= e(url('/')) ?>" class="logo text-decoration-none">
                            <span class="sitename"><?= e(setting('store_name', 'ShopWise Kenya')) ?></span>
                        </a>
                        <p class="mt-2 text-muted"><?= e(setting('store_tagline', 'Quality everyday essentials, delivered across Kenya.')) ?></p>
                        <div class="footer-contact mt-3">
                            <div class="contact-item d-flex align-items-center mb-2">
                                <i class="bi bi-geo-alt me-2 text-primary"></i>
                                <span><?= e(setting('pickup_address', 'Moi Avenue, Nairobi CBD')) ?></span>
                            </div>
                            <div class="contact-item d-flex align-items-center mb-2">
                                <i class="bi bi-telephone me-2 text-primary"></i>
                                <span><a href="tel:<?= e(normalize_phone(setting('support_phone')) ?? '+254700000000') ?>" class="text-muted text-decoration-none"><?= e(setting('support_phone', '+254 700 000 000')) ?></a></span>
                            </div>
                            <div class="contact-item d-flex align-items-center mb-2">
                                <i class="bi bi-envelope me-2 text-primary"></i>
                                <span><a href="mailto:<?= e(setting('support_email', 'support@kennedycheroben.co.ke')) ?>" class="text-muted text-decoration-none"><?= e(setting('support_email', 'support@kennedycheroben.co.ke')) ?></a></span>
                            </div>
                            <div class="contact-item d-flex align-items-center mb-2">
                                <i class="bi bi-clock me-2 text-primary"></i>
                                <span><?= e(setting('business_hours', 'Mon-Sat, 8:00 am - 6:00 pm EAT')) ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Categories Quick Links -->
                <div class="col-lg-3 col-md-6 col-sm-6">
                    <div class="footer-widget">
                        <h4>Shop Categories</h4>
                        <ul class="footer-links list-unstyled">
                            <li><a href="<?= e(url('shop')) ?>">All Products</a></li>
                            <?php foreach ($footerCategories as $fCat): ?>
                                <li><a href="<?= e(url('category/' . $fCat['slug'])) ?>"><?= e($fCat['name']) ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>

                <!-- Help & Policies -->
                <div class="col-lg-3 col-md-6 col-sm-6">
                    <div class="footer-widget">
                        <h4>Help &amp; Policies</h4>
                        <ul class="footer-links list-unstyled">
                            <li><a href="<?= e(url('about')) ?>">About Us</a></li>
                            <li><a href="<?= e(url('contact')) ?>">Contact Us</a></li>
                            <li><a href="<?= e(url('faq')) ?>">Frequently Asked Questions</a></li>
                            <li><a href="<?= e(url('shipping-info')) ?>">Shipping &amp; Delivery</a></li>
                            <li><a href="<?= e(url('return-policy')) ?>">Returns &amp; Refunds</a></li>
                            <li><a href="<?= e(url('support')) ?>">Support Center</a></li>
                            <li><a href="<?= e(url('privacy')) ?>">Privacy Policy</a></li>
                            <li><a href="<?= e(url('terms')) ?>">Terms of Service</a></li>
                        </ul>
                    </div>
                </div>

                <!-- Customer Assurance -->
                <div class="col-lg-3 col-md-6 col-sm-12">
                    <div class="footer-widget">
                        <h4>Kenya Delivery &amp; Payment</h4>
                        <p class="small text-muted mb-2">Fulfillment and dispatch from our Nairobi CBD store across active delivery zones in 1 to 4 business days.</p>
                        <div class="d-flex align-items-center gap-2 mt-3">
                            <span class="badge bg-success py-2 px-3"><i class="bi bi-phone me-1"></i> M-Pesa Paybill / STK</span>
                        </div>
                        <div class="mt-2">
                            <small class="text-muted"><i class="bi bi-shield-check text-success me-1"></i> Quality Checked Inventory</small>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <div class="footer-bottom">
        <div class="container">

            <div class="payment-methods d-flex align-items-center justify-content-center mb-3">
                <span class="me-2">Accepted Payment Options:</span>
                <div class="payment-icons d-flex align-items-center gap-3">
                    <span class="badge bg-light text-dark border px-2 py-1"><i class="bi bi-phone"></i> M-Pesa</span>
                </div>
            </div>

            <div class="copyright text-center">
                <p class="mb-1">© <span>Copyright</span> <strong class="sitename"><?= e(setting('store_name', 'ShopWise Kenya')) ?></strong>. All Rights Reserved.</p>
            </div>

            <div class="credits text-center">
                <!-- Licensing information: https://bootstrapmade.com/license/ -->
                Designed by <a href="https://bootstrapmade.com/" target="_blank" rel="noopener">BootstrapMade</a>
            </div>

        </div>
    </div>

</footer>

<!-- Scroll Top -->
<a href="#" id="scroll-top" class="scroll-top d-flex align-items-center justify-content-center" aria-label="Scroll to top"><i class="bi bi-arrow-up-short"></i></a>

<!-- Vendor JS Files -->
<script src="<?= e(asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('assets/vendor/swiper/swiper-bundle.min.js')) ?>"></script>
<script src="<?= e(asset('assets/vendor/drift-zoom/Drift.min.js')) ?>"></script>
<script src="<?= e(asset('assets/vendor/glightbox/js/glightbox.min.js')) ?>"></script>
<script src="<?= e(asset('assets/vendor/purecounter/purecounter_vanilla.js')) ?>"></script>

<!-- Main Template JS -->
<script src="<?= e(asset('assets/js/main.js')) ?>"></script>

<!-- Interactive Storefront JS -->
<script src="<?= e(asset('assets/js/shop.js')) ?>"></script>

</body>
</html>
