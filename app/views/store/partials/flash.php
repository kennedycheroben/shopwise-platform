<?php
/**
 * Flash Notification Messages Partial
 */
$allFlashes = flash_all();
?>
<?php if (!empty($allFlashes)): ?>
    <div class="container mt-3">
        <?php foreach ($allFlashes as $type => $messages): ?>
            <?php
            $bsClass = match ($type) {
                'success' => 'alert-success',
                'error', 'danger' => 'alert-danger',
                'warning' => 'alert-warning',
                default => 'alert-info',
            };
            ?>
            <div class="alert <?= e($bsClass) ?> alert-dismissible fade show" role="alert">
                <?= implode('<br>', array_map('e', (array)$messages)) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
