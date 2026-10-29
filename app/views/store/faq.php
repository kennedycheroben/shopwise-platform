<?php
/**
 * Frequently Asked Questions View
 * Based on _template_reference/storefront_pages/faq.html (lines 835-970)
 */
$pageTitle = 'Frequently Asked Questions';
$metaDescription = 'Find answers to common questions about ordering, M-Pesa payments, Kenya delivery zones, returns, and support at ' . setting('store_name', 'ShopWise Kenya') . '.';
$bodyClass = 'faq-page';

$breadcrumbs = [
    ['label' => 'FAQ', 'url' => null],
];

$freeThreshold = (float)setting('free_delivery_threshold', 5000);
$returnDays = (int)setting('return_window_days', 7);
$refundDays = (int)setting('refund_processing_days', 7);
$supportHours = (int)setting('support_response_hours', 24);

$activeCounties = \App\Services\DeliveryService::getCounties();
$countiesSummary = !empty($activeCounties) ? implode(', ', $activeCounties) : 'active delivery zones';

$faqItems = [
    [
        'q' => 'How do I place an order on ShopWise Kenya?',
        'a' => 'Browse our catalog or search for items, select your preferred size and color, and click "Add to Bag". When you are ready, proceed to your Shopping Bag to review your items, enter your delivery address and contact information, and complete your purchase.',
    ],
    [
        'q' => 'Do I need to register an account before placing an order?',
        'a' => 'No. You can shop freely as a guest without creating an account. All we require is your name, delivery address, phone number for courier coordination, and email address for order confirmation receipts.',
    ],
    [
        'q' => 'What payment methods are accepted?',
        'a' => 'We accept Safaricom M-Pesa via STK Push and Paybill. Payments are verified instantly and automatically attached to your order reference number.',
    ],
    [
        'q' => 'Where do you deliver across Kenya?',
        'a' => 'We deliver to active delivery zones including ' . $countiesSummary . '. Dispatch is fulfilled from our Nairobi CBD hub with delivery times and rates derived directly from our active delivery zones schedule.',
    ],
    [
        'q' => 'Can I pick up my order in person to save on delivery fees?',
        'a' => 'Yes. Complimentary in-store collection is available at our Nairobi CBD pickup point on Moi Avenue. Once your order is packaged, you will receive notification with collection instructions.',
    ],
    [
        'q' => 'How do I qualify for free delivery?',
        'a' => 'Orders with a subtotal of KES ' . number_format($freeThreshold, 2) . ' or more qualify for complimentary standard delivery within qualifying delivery zones.',
    ],
    [
        'q' => 'How long will it take for my order to arrive?',
        'a' => 'Deliveries within Nairobi CBD and surrounding estates arrive within 0 to 1 business day. Deliveries to neighboring counties and major towns arrive within 1 to 4 business days as detailed on our Shipping Info page.',
    ],
    [
        'q' => 'What is your return policy window?',
        'a' => 'We offer a ' . $returnDays . '-day return window starting from the day your package is delivered. Items must be in their original, unused condition with all tags and packaging intact.',
    ],
    [
        'q' => 'How and when are refunds processed?',
        'a' => 'Once returned items are received and inspected at our Nairobi hub, refunds are approved and disbursed directly to your original M-Pesa payment number within ' . $refundDays . ' business days.',
    ],
    [
        'q' => 'How do I choose the right size and variant?',
        'a' => 'Each product detail page displays available sizes, colors, and real-time inventory counts. Unavailable or sold-out variants are clearly disabled to ensure you only order items in stock.',
    ],
    [
        'q' => 'What happens if a product is out of stock?',
        'a' => 'Products with zero available inventory cannot be added to your shopping bag. Our inventory updates in real time to reflect active warehouse stock.',
    ],
    [
        'q' => 'How can I get in touch with customer support?',
        'a' => 'You can reach our Nairobi customer care team by calling ' . setting('support_phone', '+254 700 000 000') . ', emailing ' . setting('support_email', 'support@kennedycheroben.co.ke') . ', or submitting an inquiry through our Contact page. We respond within ' . $supportHours . ' hours.',
    ],
];

include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/breadcrumbs.php';
include __DIR__ . '/partials/flash.php';
?>

<main class="main">

    <!-- Faq Section -->
    <section id="faq" class="faq section">

        <div class="container">

            <div class="row justify-content-center">
                <div class="col-lg-8">

                    <div class="faq-list">

                        <?php foreach ($faqItems as $idx => $item): ?>
                            <div class="faq-item <?= $idx === 0 ? 'faq-active' : '' ?>">
                                <div class="faq-header">
                                    <div class="faq-label">
                                        <span class="faq-badge">Q<?= $idx + 1 ?></span>
                                        <h3><?= e($item['q']) ?></h3>
                                    </div>
                                    <i class="bi <?= $idx === 0 ? 'bi-dash-lg' : 'bi-plus-lg' ?> faq-toggle"></i>
                                </div>
                                <div class="faq-content">
                                    <p><?= e($item['a']) ?></p>
                                </div>
                            </div><!-- End FAQ Item -->
                        <?php endforeach; ?>

                    </div><!-- End FAQ List -->

                    <div class="faq-cta">
                        <div class="cta-inner">
                            <i class="bi bi-chat-dots"></i>
                            <div class="cta-body">
                                <h4>Still have questions?</h4>
                                <p>Our Nairobi customer care team is available to assist you with order inquiries, product sizing, and delivery coordination.</p>
                            </div>
                            <a href="<?= e(url('contact')) ?>" class="cta-btn">Contact Us</a>
                        </div>
                    </div><!-- End FAQ CTA -->

                </div>
            </div>

        </div>

    </section><!-- /Faq Section -->

</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const faqItems = document.querySelectorAll('.faq-item');
    faqItems.forEach(item => {
        const header = item.querySelector('.faq-header');
        if (header) {
            header.addEventListener('click', () => {
                const isActive = item.classList.contains('faq-active');
                faqItems.forEach(other => {
                    other.classList.remove('faq-active');
                    const icon = other.querySelector('.faq-toggle');
                    if (icon) {
                        icon.classList.remove('bi-dash-lg');
                        icon.classList.add('bi-plus-lg');
                    }
                });
                if (!isActive) {
                    item.classList.add('faq-active');
                    const icon = item.querySelector('.faq-toggle');
                    if (icon) {
                        icon.classList.remove('bi-plus-lg');
                        icon.classList.add('bi-dash-lg');
                    }
                }
            });
        }
    });
});
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
