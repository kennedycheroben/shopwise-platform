<?php
$pageTitle = 'Access restricted';
require __DIR__ . '/layout.php';
?>
<div class="main-content page-error">
    <div class="container py-5">
        <div class="card">
            <div class="card-body p-5 text-center">
                <h1 class="h3">Access restricted</h1>
                <p>Your ShopWise Kenya account does not have permission to perform that action.</p>
                <a class="btn btn-primary" href="<?= e(url('admin/')) ?>">Return to dashboard</a>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
