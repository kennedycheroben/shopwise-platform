<?php
/**
 * ShopWise Kenya - Checkout Payment Pending / Check Phone View
 */

$pageTitle = 'M-Pesa Payment - ' . e($order['order_number'] ?? '');
$metaDescription = 'Complete your M-Pesa payment on your mobile phone.';

$breadcrumbs = [
    ['label' => 'Shopping Bag', 'url' => '/cart'],
    ['label' => 'Checkout', 'url' => '/checkout'],
    ['label' => 'Payment', 'url' => null],
];

include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/breadcrumbs.php';
include __DIR__ . '/partials/flash.php';

$orderNumber = $order['order_number'] ?? '';
$totalKes = (int)round((float)($order['total_amount'] ?? 0));
$phone = $payment['phone_number'] ?? $order['phone'] ?? '';
$expiresAt = $order['expires_at'] ?? '';
$isPaid = ($order['payment_status'] ?? '') === 'paid';
$isExpired = ($order['status'] ?? '') === 'expired';
$csrfToken = csrf_token();
?>

<main class="main py-5">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8 col-md-10">
                <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                    <div class="card-header bg-success text-white py-4 text-center">
                        <div class="d-inline-flex align-items-center justify-content-center bg-white text-success rounded-circle mb-3 shadow-sm" style="width: 64px; height: 64px;">
                            <i class="bi bi-phone fs-2"></i>
                        </div>
                        <h2 class="h3 fw-bold mb-1">Check Your Phone</h2>
                        <p class="mb-0 opacity-90">An M-Pesa STK prompt has been sent to your mobile phone</p>
                    </div>

                    <div class="card-body p-4 p-md-5">
                        <div class="alert alert-light border p-3 rounded-3 mb-4">
                            <div class="row g-3 text-center text-md-start">
                                <div class="col-6 col-md-3">
                                    <span class="text-muted d-block small">Order Number</span>
                                    <strong class="fs-6 text-dark"><?= e($orderNumber) ?></strong>
                                </div>
                                <div class="col-6 col-md-3">
                                    <span class="text-muted d-block small">Amount Due</span>
                                    <strong class="fs-5 text-success">KES <?= e(number_format($totalKes)) ?></strong>
                                </div>
                                <div class="col-6 col-md-3">
                                    <span class="text-muted d-block small">M-Pesa Phone</span>
                                    <strong class="fs-6 text-dark"><?= e($phone) ?></strong>
                                </div>
                                <div class="col-6 col-md-3">
                                    <span class="text-muted d-block small">Expires At</span>
                                    <span class="badge bg-warning text-dark small" id="expiry-badge"><?= e(date('H:i:s', strtotime($expiresAt))) ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Dynamic Status Container -->
                        <div id="payment-status-box" class="text-center py-4">
                            <div id="status-spinner" class="spinner-border text-success mb-3" style="width: 3.5rem; height: 3.5rem;" role="status">
                                <span class="visually-hidden">Waiting for M-Pesa...</span>
                            </div>
                            <h4 id="status-heading" class="fw-bold mb-2">Waiting for M-Pesa PIN...</h4>
                            <p id="status-message" class="text-muted mb-3">
                                Please check phone <strong><?= e($phone) ?></strong>, enter your M-Pesa PIN, and press OK.
                            </p>
                            <p class="small text-muted mb-0">
                                This page will automatically update once Safaricom confirms your payment.
                            </p>
                        </div>

                        <!-- Still Waiting / Retry Actions (Hidden initially) -->
                        <div id="payment-action-box" class="d-none mt-4 pt-3 border-top">
                            <div class="alert alert-secondary d-flex align-items-center mb-3">
                                <i class="bi bi-info-circle fs-4 me-3 text-primary"></i>
                                <div>
                                    <strong>Still waiting for your prompt or confirmation?</strong>
                                    <p class="mb-0 small text-muted">If you did not see the prompt on your phone or have already entered your PIN, you can check status or request a new prompt below.</p>
                                </div>
                            </div>

                            <div class="d-flex flex-wrap gap-2 justify-content-center">
                                <button type="button" id="btn-manual-check" class="btn btn-outline-success px-4 py-2">
                                    <i class="bi bi-arrow-repeat me-1"></i> Check Payment Status
                                </button>

                                <form method="POST" action="/checkout/retry/<?= e(urlencode($orderNumber)) ?>" id="retry-form" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <button type="submit" id="btn-retry" class="btn btn-success px-4 py-2">
                                        <i class="bi bi-phone me-1"></i> Send STK Prompt Again
                                    </button>
                                </form>

                                <a href="/account/orders/<?= e(urlencode($orderNumber)) ?>" class="btn btn-outline-secondary px-4 py-2">
                                    View Order
                                </a>
                            </div>
                            <div id="action-feedback" class="small text-center mt-2 text-muted"></div>
                        </div>

                        <!-- Instructions Accordion / Steps -->
                        <div class="mt-4 pt-3 border-top">
                            <h6 class="fw-bold mb-3"><i class="bi bi-question-circle me-1"></i> How to complete your payment:</h6>
                            <ol class="small text-muted ps-3 mb-0">
                                <li class="mb-1">Unlock your phone (<strong><?= e($phone) ?></strong>).</li>
                                <li class="mb-1">Wait for the Safaricom SIM Toolkit / M-Pesa prompt showing <strong>KES <?= e(number_format($totalKes)) ?></strong>.</li>
                                <li class="mb-1">Enter your 4-digit M-Pesa PIN and press <strong>Send</strong>.</li>
                                <li>You will receive an SMS confirmation from <strong>M-PESA</strong>. This page will update automatically.</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const orderNumber = <?= json_encode($orderNumber) ?>;
    const statusBox = document.getElementById('payment-status-box');
    const statusHeading = document.getElementById('status-heading');
    const statusMessage = document.getElementById('status-message');
    const statusSpinner = document.getElementById('status-spinner');
    const actionBox = document.getElementById('payment-action-box');
    const btnManualCheck = document.getElementById('btn-manual-check');
    const actionFeedback = document.getElementById('action-feedback');

    let pollInterval = 3000;
    let elapsedSeconds = 0;
    const maxPollSeconds = 120; // 2 minutes before showing action box
    let timerId = null;

    async function checkStatus(isManual = false) {
        if (isManual) {
            btnManualCheck.disabled = true;
            actionFeedback.textContent = 'Querying M-Pesa network...';
        }

        try {
            const url = '/checkout/status/' + encodeURIComponent(orderNumber) + (isManual ? '?query=1' : '');
            const resp = await fetch(url, {
                headers: { 'Accept': 'application/json' }
            });

            if (!resp.ok) {
                if (resp.status === 404) {
                    statusHeading.textContent = 'Order Not Found';
                    statusMessage.textContent = 'Could not locate order details.';
                    statusSpinner.classList.add('d-none');
                    return;
                }
            }

            const data = await resp.json();

            if (data.payment_status === 'paid' || data.status === 'paid') {
                statusSpinner.className = 'bi bi-check-circle-fill text-success fs-1 mb-3';
                statusHeading.textContent = 'Payment Received!';
                statusMessage.innerHTML = 'Thank you! Redirecting to confirmation page...';
                if (timerId) clearInterval(timerId);
                setTimeout(function () {
                    window.location.href = '/checkout/confirmation/' + encodeURIComponent(orderNumber);
                }, 1500);
                return;
            }

            if (data.status === 'cancelled' || data.status === 'expired') {
                statusSpinner.className = 'bi bi-x-circle-fill text-danger fs-1 mb-3';
                statusHeading.textContent = 'Order ' + (data.status === 'expired' ? 'Expired' : 'Cancelled');
                statusMessage.textContent = data.message || 'This order is no longer open for payment.';
                actionBox.classList.remove('d-none');
                if (timerId) clearInterval(timerId);
                return;
            }

            if (data.payment_status === 'failed' || data.payment_status === 'cancelled') {
                statusSpinner.className = 'bi bi-exclamation-triangle-fill text-warning fs-1 mb-3';
                statusHeading.textContent = 'Payment Unsuccessful';
                statusMessage.textContent = data.message || 'M-Pesa prompt was declined or timed out.';
                actionBox.classList.remove('d-none');
            } else {
                if (data.message) {
                    statusMessage.textContent = data.message;
                }
            }

            if (isManual) {
                actionFeedback.textContent = data.message || 'Status updated.';
            }

        } catch (err) {
            console.error('Polling error:', err);
            if (isManual) {
                actionFeedback.textContent = 'Network error checking status. Please try again.';
            }
        } finally {
            if (isManual) {
                btnManualCheck.disabled = false;
            }
        }
    }

    // Polling runner
    function poll() {
        checkStatus(false);
        elapsedSeconds += (pollInterval / 1000);

        if (elapsedSeconds >= maxPollSeconds) {
            actionBox.classList.remove('d-none');
            // Back off polling to every 10 seconds after 2 mins
            pollInterval = 10000;
        }

        timerId = setTimeout(poll, pollInterval);
    }

    // Initial kick-off
    timerId = setTimeout(poll, 3000);

    btnManualCheck.addEventListener('click', function () {
        checkStatus(true);
    });
});
</script>

<?php
include __DIR__ . '/partials/footer.php';
?>
