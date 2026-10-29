<?php
/**
 * Breadcrumbs Partial
 * Expects $breadcrumbs array of ['label' => string, 'url' => ?string]
 */
?>
<div class="page-title light-background py-3">
    <div class="container d-lg-flex justify-content-between align-items-center">
        <h1 class="mb-2 mb-lg-0 fs-3"><?= e($pageTitle ?? 'Shop') ?></h1>
        <nav class="breadcrumbs">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?= e(url('/')) ?>">Home</a></li>
                <?php if (!empty($breadcrumbs)): ?>
                    <?php foreach ($breadcrumbs as $crumb): ?>
                        <?php if (!empty($crumb['url'])): ?>
                            <li class="breadcrumb-item"><a href="<?= e($crumb['url']) ?>"><?= e($crumb['label']) ?></a></li>
                        <?php else: ?>
                            <li class="breadcrumb-item active" aria-current="page"><?= e($crumb['label']) ?></li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <li class="breadcrumb-item active" aria-current="page"><?= e($pageTitle ?? 'Shop') ?></li>
                <?php endif; ?>
            </ol>
        </nav>
    </div>
</div>
