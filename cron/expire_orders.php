<?php
/**
 * ShopWise Kenya - Order Expiration Cron Worker
 *
 * Scans for pending_payment orders that have passed their expires_at timestamp.
 * - Enforces CLI-only invocation.
 * - Non-overlapping execution via flock.
 * - Processes in batches of 100.
 * - Queries Daraja for open STK push requests before expiring (completing paid flow on success).
 * - Expiring restores stock exactly once, records inventory_movements, releases coupon redemptions,
 *   marks open payments as timed out, and queues customer notification emails.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    fwrite(STDERR, "Access Denied: This script may only be executed via the CLI.\n");
    exit(1);
}

// Ensure lock directory exists
$storageDir = dirname(__DIR__) . '/app/storage';
if (!is_dir($storageDir)) {
    mkdir($storageDir, 0755, true);
}

$lockFile = $storageDir . '/expire_orders.lock';
$lockFp = fopen($lockFile, 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo "[" . date('Y-m-d H:i:s') . "] Notice: Another instance of expire_orders is currently running. Skipping.\n";
    exit(0);
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Database\Database;
use App\Services\OrderService;
use App\Services\MpesaService;
use App\Services\SecurityContext;

$pdo = Database::getInstance();
$batchLimit = 100;

SecurityContext::runAsSystem(function () use ($pdo, $batchLimit) {
    $stmt = $pdo->prepare("
        SELECT id, order_number, total, expires_at
        FROM orders
        WHERE status = 'pending_payment' AND expires_at <= NOW()
        ORDER BY expires_at ASC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $batchLimit, PDO::PARAM_INT);
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalOrders = count($orders);
    $expiredCount = 0;
    $recoveredPaidCount = 0;
    $errorCount = 0;

    foreach ($orders as $order) {
        $orderId = (int)$order['id'];
        $orderNum = (string)$order['order_number'];

        try {
            // 1. Check if there is an active/pending STK Push attempt
            $pStmt = $pdo->prepare("
                SELECT * FROM payments 
                WHERE order_id = :oid AND status IN ('pending', 'pending_verification', 'initiated')
                ORDER BY id DESC LIMIT 1
            ");
            $pStmt->execute([':oid' => $orderId]);
            $pendingPayment = $pStmt->fetch(PDO::FETCH_ASSOC);

            $paymentVerified = false;

            if ($pendingPayment && !empty($pendingPayment['checkout_request_id'])) {
                $queryResult = MpesaService::queryStkPush($pendingPayment['checkout_request_id']);
                if ($queryResult['success']) {
                    $raw = $queryResult['raw'] ?? [];
                    $receipt = $raw['MpesaReceiptNumber'] ?? ('QRC' . substr($pendingPayment['checkout_request_id'], -7));
                    OrderService::transitionToPaid($orderId, $receipt, json_encode($raw));
                    $paymentVerified = true;
                    $recoveredPaidCount++;
                }
            }

            // 2. If not verified as paid, expire the order
            if (!$paymentVerified) {
                $expired = OrderService::expire($orderId);
                if ($expired) {
                    $expiredCount++;
                }
            }
        } catch (\Throwable $e) {
            $errorCount++;
            fwrite(STDERR, "[" . date('Y-m-d H:i:s') . "] ERROR processing Order #{$orderNum}: " . $e->getMessage() . "\n");
        }
    }

    echo sprintf(
        "[%s] Order Expiration Worker: Processed: %d, Expired: %d, Recovered Paid: %d, Errors: %d\n",
        date('Y-m-d H:i:s'),
        $totalOrders,
        $expiredCount,
        $recoveredPaidCount,
        $errorCount
    );
}, 'cron_order_expiration');

flock($lockFp, LOCK_UN);
fclose($lockFp);

exit($errorCount > 0 ? 1 : 0);
