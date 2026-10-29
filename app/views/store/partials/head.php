<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
<?php
$config = require dirname(__DIR__, 3) . '/config/config.php';
$isProduction = (($config['app']['environment'] ?? 'production') === 'production');

$currentReqPath = \App\Http\Router::resolveRequestPath();
$defaultCanonical = ($currentReqPath === '/' || $currentReqPath === '') ? url('/') : url(ltrim($currentReqPath, '/'));
$canonicalUrlFinal = $canonicalUrl ?? $defaultCanonical;

$pageTitleFull = !empty($pageTitle) ? "{$pageTitle} - " . setting('store_name', 'ShopWise Kenya') : setting('store_name', 'ShopWise Kenya') . " - Everyday Essentials";
$metaDescFull = $metaDescription ?? setting('store_tagline', 'Quality everyday essentials, delivered across Kenya');
$ogTypeFinal = $ogType ?? 'website';
$defaultOgImage = asset('assets/img/apple-touch-icon.png');
$ogImageFinal = $ogImage ?? $defaultOgImage;
?>
    <title><?= e($pageTitleFull) ?></title>
    <meta name="description" content="<?= e($metaDescFull) ?>">
    <link rel="canonical" href="<?= e($canonicalUrlFinal) ?>">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="base-url" content="<?= e(url()) ?>">

    <meta name="robots" content="<?= $isProduction ? 'index, follow' : 'noindex, nofollow' ?>">

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="<?= e($ogTypeFinal) ?>">
    <meta property="og:url" content="<?= e($canonicalUrlFinal) ?>">
    <meta property="og:title" content="<?= e($pageTitleFull) ?>">
    <meta property="og:description" content="<?= e($metaDescFull) ?>">
    <meta property="og:site_name" content="<?= e(setting('store_name', 'ShopWise Kenya')) ?>">
    <meta property="og:image" content="<?= e($ogImageFinal) ?>">

    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:url" content="<?= e($canonicalUrlFinal) ?>">
    <meta name="twitter:title" content="<?= e($pageTitleFull) ?>">
    <meta name="twitter:description" content="<?= e($metaDescFull) ?>">
    <meta name="twitter:image" content="<?= e($ogImageFinal) ?>">

    <!-- JSON-LD: Organization -->
    <?php
    $orgSchema = [
        '@context' => 'https://schema.org',
        '@type'    => 'Organization',
        'name'     => setting('store_name', 'ShopWise Kenya'),
        'url'      => url('/'),
        'logo'     => asset('assets/img/logo.webp'),
        'contactPoint' => [
            '@type'             => 'ContactPoint',
            'telephone'         => setting('support_phone', '+254 700 000 000'),
            'contactType'       => 'customer service',
            'areaServed'        => 'KE',
            'availableLanguage' => ['en', 'sw'],
        ],
    ];
    ?>
    <script type="application/ld+json">
<?= json_encode($orgSchema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
    </script>

    <!-- JSON-LD: BreadcrumbList -->
    <?php if (!empty($breadcrumbs) && is_array($breadcrumbs)): ?>
    <?php
    $itemList = [
        [
            '@type'    => 'ListItem',
            'position' => 1,
            'name'     => 'Home',
            'item'     => url('/'),
        ]
    ];
    $pos = 2;
    foreach ($breadcrumbs as $crumb) {
        $listItem = [
            '@type'    => 'ListItem',
            'position' => $pos++,
            'name'     => $crumb['label'] ?? '',
        ];
        if (!empty($crumb['url'])) {
            $listItem['item'] = $crumb['url'];
        }
        $itemList[] = $listItem;
    }
    $breadcrumbSchema = [
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => $itemList,
    ];
    ?>
    <script type="application/ld+json">
<?= json_encode($breadcrumbSchema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
    </script>
    <?php endif; ?>

    <!-- JSON-LD: Product -->
    <?php if (!empty($product) && is_array($product)): ?>
    <?php
    $prodPrice = (float)($product['min_price'] ?? ($product['base_price'] ?? 0));
    $inStock = !empty($product['variants'])
        ? array_sum(array_column($product['variants'], 'stock_quantity')) > 0
        : (int)($product['total_stock'] ?? 0) > 0;
    $primaryImg = !empty($product['images'][0]['image_url']) ? asset($product['images'][0]['image_url']) : $defaultOgImage;

    $productSchema = [
        '@context'    => 'https://schema.org',
        '@type'       => 'Product',
        'name'        => $product['name'] ?? '',
        'description' => strip_tags($product['description'] ?? ''),
        'image'       => $primaryImg,
        'sku'         => $product['sku'] ?? '',
        'offers'      => [
            '@type'         => 'Offer',
            'priceCurrency' => 'KES',
            'price'         => number_format($prodPrice, 2, '.', ''),
            'availability'  => $inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            'url'           => $canonicalUrlFinal,
        ],
    ];
    ?>
    <script type="application/ld+json">
<?= json_encode($productSchema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
    </script>
    <?php endif; ?>

    <!-- JSON-LD: FAQPage -->
    <?php if (!empty($faqItems) && is_array($faqItems)): ?>
    <?php
    $mainEntity = [];
    foreach ($faqItems as $f) {
        $mainEntity[] = [
            '@type'          => 'Question',
            'name'           => $f['q'],
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text'  => $f['a'],
            ],
        ];
    }
    $faqSchema = [
        '@context'   => 'https://schema.org',
        '@type'      => 'FAQPage',
        'mainEntity' => $mainEntity,
    ];
    ?>
    <script type="application/ld+json">
<?= json_encode($faqSchema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
    </script>
    <?php endif; ?>

    <!-- Favicons -->
    <link href="<?= e(asset('assets/img/favicon.png')) ?>" rel="icon">
    <link href="<?= e(asset('assets/img/apple-touch-icon.png')) ?>" rel="apple-touch-icon">

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com" rel="preconnect">
    <link href="https://fonts.gstatic.com" rel="preconnect" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:ital,wght@0,100;0,300;0,400;0,500;0,700;0,900;1,100;1,300;1,400;1,500;1,700;1,900&family=Inter:wght@100;200;300;400;500;600;700;800;900&family=Quicksand:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Vendor CSS Files -->
    <link href="<?= e(asset('assets/vendor/bootstrap/css/bootstrap.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset('assets/vendor/bootstrap-icons/bootstrap-icons.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset('assets/vendor/swiper/swiper-bundle.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset('assets/vendor/drift-zoom/drift-basic.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset('assets/vendor/glightbox/css/glightbox.min.css')) ?>" rel="stylesheet">

    <!-- Main CSS File -->
    <link href="<?= e(asset('assets/css/main.css')) ?>" rel="stylesheet">

    <!-- Custom Shop Styles for Interactive Elements -->
    <style>
        .btn-proceed:disabled, .btn-proceed[disabled], .checkout-now-btn:disabled, .proceed-btn:disabled {
            opacity: 0.65;
            cursor: not-allowed;
            pointer-events: none;
        }
        .mini-cart-empty {
            padding: 1.5rem;
            text-align: center;
            color: #6c757d;
        }
        .stock-badge-low {
            color: #d97706;
            font-weight: 500;
        }
        .stock-badge-out {
            color: #dc2626;
            font-weight: 600;
        }
        .stock-badge-in {
            color: #16a34a;
            font-weight: 500;
        }
        .color-dot-btn {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            border: 2px solid #e5e7eb;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            margin-right: 6px;
            transition: all 0.2s;
        }
        .color-dot-btn.active {
            border-color: #111827;
            transform: scale(1.1);
        }
        .size-btn {
            min-width: 42px;
            height: 38px;
            padding: 0 12px;
            border: 1px solid #d1d5db;
            background: #fff;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        .size-btn:hover:not(:disabled) {
            border-color: #111827;
        }
        .size-btn.active {
            background: #111827;
            color: #fff;
            border-color: #111827;
        }
        .size-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
            text-decoration: line-through;
        }
        .toast-notification {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 9999;
            min-width: 280px;
            max-width: 420px;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body class="<?= e($bodyClass ?? '') ?>">
