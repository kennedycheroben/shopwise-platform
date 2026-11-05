<?php

namespace App\Services;

use App\Database\Database;
use PDO;
use Throwable;

/**
 * Mail Delivery & Email Queue Service
 */
class MailerService
{
    private array $config;

    public function __construct(?array $config = null)
    {
        if ($config === null) {
            $appConfig = require dirname(__DIR__, 2) . '/config/config.php';
            $this->config = $appConfig['mail'] ?? [];
        } else {
            $this->config = $config;
        }
    }

    /**
     * Enqueue an email to be sent asynchronously via cron
     */
    public function queueEmail(string $toEmail, ?string $toName, string $subject, string $htmlBody, string $textBody): int
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("
            INSERT INTO email_queue (to_email, to_name, subject, body_html, body_text, status, attempts, created_at)
            VALUES (:to_email, :to_name, :subject, :body_html, :body_text, 'pending', 0, NOW())
        ");
        $cleanEmail = trim(str_replace(["\r", "\n"], '', $toEmail));
        $cleanName = $toName !== null ? trim(str_replace(["\r", "\n"], ' ', $toName)) : null;
        $cleanSubject = trim(str_replace(["\r", "\n"], ' ', $subject));

        $stmt->execute([
            ':to_email'  => $cleanEmail,
            ':to_name'   => $cleanName,
            ':subject'   => $cleanSubject,
            ':body_html' => $htmlBody,
            ':body_text' => $textBody,
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Static helper to enqueue an email
     */
    public static function queue(string $toEmail, string $subject, string $htmlBody, string $textBody, ?string $toName = null): int
    {
        return (new self())->queueEmail($toEmail, $toName, $subject, $htmlBody, $textBody);
    }

    /**
 * Send an email immediately through authenticated SMTP using vendored PHPMailer.
     */
    public function sendDirect(string $toEmail, ?string $toName, string $subject, string $htmlBody, string $textBody): bool
    {
        $driver = $this->config['driver'] ?? 'queue_only';

        if ($driver === 'queue_only') {
            $this->queueEmail($toEmail, $toName, $subject, $htmlBody, $textBody);
            return true;
        }

        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            throw new \RuntimeException('PHPMailer is not available. Restore the committed vendor directory.');
        }
        return $this->sendViaPHPMailer($toEmail, $toName, $subject, $htmlBody, $textBody);
    }

    /**
     * Send email using PHPMailer library
     */
    private function sendViaPHPMailer(string $toEmail, ?string $toName, string $subject, string $htmlBody, string $textBody): bool
    {
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $this->config['host'] ?? 'mail.kennedycheroben.co.ke';
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->config['username'] ?? '';
            $mail->Password   = $this->config['password'] ?? '';
            $mail->SMTPSecure = $this->config['encryption'] ?? 'ssl';
            $mail->Port       = (int)($this->config['port'] ?? 465);

            $mail->setFrom($this->config['from_address'] ?? 'support@kennedycheroben.co.ke', $this->config['from_name'] ?? 'ShopWise Kenya');
            $mail->addAddress($toEmail, $toName ?? '');

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = $textBody;

            return $mail->send();
        } catch (Throwable $e) {
            error_log("PHPMailer error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Process pending queued emails (invoked by cPanel cron runner)
     */
    public function processQueue(int $limit = 20): int
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("
            SELECT id, to_email, to_name, subject, body_html, body_text, attempts
            FROM email_queue
            WHERE status = 'pending' AND attempts < 5
            ORDER BY id ASC
            LIMIT :lim
        ");
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        $processed = 0;

        foreach ($rows as $row) {
            $success = false;
            $error = null;

            try {
                // If in queue_only mode, simulate transmission or attempt native mail
                if (($this->config['driver'] ?? '') === 'queue_only') {
                    $success = true; // Mark as delivered in local mock queue
                } else {
                    $success = $this->sendDirect($row['to_email'], $row['to_name'], $row['subject'], $row['body_html'], $row['body_text']);
                }
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }

            if ($success) {
                $update = $pdo->prepare("UPDATE email_queue SET status = 'sent', sent_at = NOW(), attempts = attempts + 1 WHERE id = :id");
                $update->execute([':id' => $row['id']]);
                $processed++;
            } else {
                $update = $pdo->prepare("
                    UPDATE email_queue
                    SET status = CASE WHEN attempts + 1 >= 5 THEN 'failed' ELSE 'pending' END,
                        attempts = attempts + 1,
                        last_error = :err
                    WHERE id = :id
                ");
                $update->execute([':id' => $row['id'], ':err' => $error ?? 'Delivery failed']);
            }
        }

        return $processed;
    }

    /**
     * Future order tracking URL helper.
     * The storefront is currently in development (Phase 3+), so this returns null
     * to prevent linking to customer-facing pages that do not exist yet.
     * Documented in docs/PRE_DEPLOY_CLEANUP.md.
     */
    public static function getTrackingUrl(string $orderNumber): ?string
    {
        return null;
    }

    /**
     * Generate and queue a Kenyan context status change email to the customer.
     */
    public static function sendOrderStatusEmail(int $orderId, string $status, ?string $note = null): int
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("
            SELECT o.*, u.email as user_email, u.first_name, u.last_name
            FROM orders o
            LEFT JOIN users u ON u.id = o.user_id
            WHERE o.id = :id
        ");
        $stmt->execute([':id' => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            return 0;
        }

        $recipientEmail = $order['user_email'] ?? null;
        if (empty($recipientEmail)) {
            $recipientEmail = 'customer@example.com';
        }

        $customerName = trim(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? ''));
        if ($customerName === '') {
            $customerName = $order['recipient_name'] ?: 'Valued Customer';
        }

        // Fetch order items
        $iStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = :oid ORDER BY id ASC");
        $iStmt->execute([':oid' => $orderId]);
        $items = $iStmt->fetchAll(PDO::FETCH_ASSOC);

        $storeName = setting('store_name', 'ShopWise Kenya');
        $supportEmail = setting('support_email', 'support@kennedycheroben.co.ke');
        $supportPhone = setting('support_phone', '+254 700 000 000');

        // Build item lists
        $itemsHtml = '<table style="width:100%; border-collapse:collapse; margin-top:15px; margin-bottom:15px;">';
        $itemsHtml .= '<thead><tr style="border-bottom:1px solid #ddd; text-align:left;">';
        $itemsHtml .= '<th style="padding:8px;">Item</th><th style="padding:8px;">Qty</th><th style="padding:8px;">Price</th><th style="padding:8px; text-align:right;">Total</th>';
        $itemsHtml .= '</tr></thead><tbody>';

        $itemsText = "";
        foreach ($items as $item) {
            $attr = [];
            if (!empty($item['size'])) $attr[] = "Size: " . $item['size'];
            if (!empty($item['color'])) $attr[] = "Color: " . $item['color'];
            $attrStr = !empty($attr) ? ' (' . implode(', ', $attr) . ')' : '';

            $pName = htmlspecialchars($item['product_name'] . $attrStr, ENT_QUOTES, 'UTF-8');
            $pQty = (int)$item['quantity'];
            $pPrice = format_money((float)$item['unit_price']);
            $pTotal = format_money((float)$item['line_total']);

            $itemsHtml .= "<tr style='border-bottom:1px solid #eee;'>";
            $itemsHtml .= "<td style='padding:8px;'>{$pName}<br><small style='color:#666;'>SKU: " . htmlspecialchars($item['sku'], ENT_QUOTES, 'UTF-8') . "</small></td>";
            $itemsHtml .= "<td style='padding:8px;'>{$pQty}</td>";
            $itemsHtml .= "<td style='padding:8px;'>{$pPrice}</td>";
            $itemsHtml .= "<td style='padding:8px; text-align:right;'>{$pTotal}</td>";
            $itemsHtml .= "</tr>";

            $itemsText .= "- {$item['product_name']}{$attrStr} x {$pQty} @ {$pPrice} = {$pTotal}\n";
        }
        $itemsHtml .= '</tbody></table>';

        $totalsHtml = '<div style="margin-top:10px; text-align:right; font-size:14px;">';
        $totalsHtml .= '<div>Subtotal: ' . format_money((float)$order['subtotal']) . '</div>';
        if ((float)$order['delivery_fee'] > 0) {
            $totalsHtml .= '<div>Delivery Fee: ' . format_money((float)$order['delivery_fee']) . '</div>';
        }
        if ((float)$order['discount'] > 0) {
            $totalsHtml .= '<div>Discount: -' . format_money((float)$order['discount']) . '</div>';
        }
        $totalsHtml .= '<div style="font-size:16px; font-weight:bold; margin-top:5px;">Grand Total: ' . format_money((float)$order['total']) . '</div>';
        $totalsHtml .= '</div>';

        $totalsText = "Subtotal: " . format_money((float)$order['subtotal']) . "\n";
        if ((float)$order['delivery_fee'] > 0) {
            $totalsText .= "Delivery Fee: " . format_money((float)$order['delivery_fee']) . "\n";
        }
        if ((float)$order['discount'] > 0) {
            $totalsText .= "Discount: -" . format_money((float)$order['discount']) . "\n";
        }
        $totalsText .= "Total: " . format_money((float)$order['total']) . "\n";

        $safeNote = !empty($note) ? htmlspecialchars($note, ENT_QUOTES, 'UTF-8') : '';
        $noteHtml = $safeNote !== '' ? "<p style='background:#f8f9fa; padding:10px; border-left:3px solid #ea580c;'><strong>Note:</strong> {$safeNote}</p>" : '';
        $noteText = $safeNote !== '' ? "\nNote: " . strip_tags($note) . "\n" : '';

        // Status content mapping
        switch ($status) {
            case 'paid':
                $subject = "Payment Confirmed: Order #{$order['order_number']} - {$storeName}";
                $heading = "Payment Confirmed";
                $message = "We have received and verified your payment of " . format_money((float)$order['total']) . " for order #{$order['order_number']}. Our dispatch team has begun preparing your package.";
                break;
            case 'processing':
                $subject = "Order Being Processed: #{$order['order_number']} - {$storeName}";
                $heading = "Your Order is in Progress";
                $message = "Your order #{$order['order_number']} is currently being packed with care at our Nairobi fulfillment center. We will notify you as soon as it is handed over to our delivery rider.";
                break;
            case 'out_for_delivery':
                $subject = "Out for Delivery: Order #{$order['order_number']} - {$storeName}";
                $heading = "Out for Delivery";
                $deliveryDesc = $order['delivery_method'] === 'pickup' ? "ready for collection at our pickup point ({$order['county']})" : "dispatched with our courier rider";
                $message = "Great news! Your order #{$order['order_number']} is {$deliveryDesc}. The courier will call you on " . htmlspecialchars($order['phone'], ENT_QUOTES, 'UTF-8') . " upon arrival.";
                break;
            case 'delivered':
                $subject = "Order Delivered: #{$order['order_number']} - {$storeName}";
                $heading = "Package Delivered";
                $message = "Your order #{$order['order_number']} has been successfully delivered. Asante sana for shopping with {$storeName}! If you have any inquiries regarding your package, please let us know.";
                break;
            case 'cancelled':
                $subject = "Order Cancelled: #{$order['order_number']} - {$storeName}";
                $heading = "Order Cancelled";
                $refundNotice = ($order['payment_status'] === 'paid')
                    ? "<p style='color:#b02a37; font-weight:bold;'>Since your payment was already verified, our accounts team has queued this order for an immediate M-Pesa refund. Our team will contact you once the reversal is complete.</p>"
                    : "<p>No payment had been captured for this order.</p>";
                $message = "Your order #{$order['order_number']} has been cancelled. {$refundNotice}";
                break;
            case 'refunded':
                $subject = "Refund Processed: Order #{$order['order_number']} - {$storeName}";
                $heading = "Refund Completed";
                $message = "We have successfully processed a refund of " . format_money((float)$order['total']) . " for order #{$order['order_number']}. The funds should reflect in your M-Pesa account shortly.";
                break;
            default:
                $subject = "Order Status Update: #{$order['order_number']} - {$storeName}";
                $heading = "Order Status Update";
                $message = "The status of your order #{$order['order_number']} has been updated to " . ucfirst(str_replace('_', ' ', $status)) . ".";
                break;
        }

        $safeCustomerName = htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 20px; background: #f4f6f9;">
    <div style="max-width: 600px; margin: 0 auto; background: #ffffff; padding: 30px; border-radius: 8px; border: 1px solid #e5e7eb;">
        <div style="border-bottom: 2px solid #ea580c; padding-bottom: 15px; margin-bottom: 20px;">
            <h2 style="margin: 0; color: #111827;">{$storeName}</h2>
            <small style="color: #6b7280;">Order #{$order['order_number']}</small>
        </div>
        <h3 style="color: #1f2937; margin-top: 0;">{$heading}</h3>
        <p>Habari <strong>{$safeCustomerName}</strong>,</p>
        <p>{$message}</p>
        {$noteHtml}
        <h4 style="margin-top: 25px; margin-bottom: 5px; color: #374151;">Order Summary</h4>
        {$itemsHtml}
        {$totalsHtml}
        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb; font-size: 13px; color: #6b7280;">
            <p style="margin: 0;">Need help? Contact our Nairobi support team at <a href="mailto:{$supportEmail}" style="color: #ea580c;">{$supportEmail}</a> or call <strong>{$supportPhone}</strong>.</p>
            <p style="margin: 5px 0 0 0;">Asante sana for shopping with {$storeName}.</p>
        </div>
    </div>
</body>
</html>
HTML;

        $text = "Habari {$customerName},\n\n";
        $text .= strip_tags($message) . "\n";
        if (!empty($noteText)) {
            $text .= $noteText . "\n";
        }
        $text .= "\nORDER SUMMARY (#{$order['order_number']})\n";
        $text .= "----------------------------------------\n";
        $text .= $itemsText;
        $text .= "----------------------------------------\n";
        $text .= $totalsText . "\n";
        $text .= "Need help? Contact {$supportEmail} or {$supportPhone}.\n";
        $text .= "Asante sana for choosing {$storeName}.\n";

        $instance = new self();
        return $instance->queueEmail($recipientEmail, $customerName, $subject, $html, $text);
    }

    /**
     * Send order placed email (pending payment, how to pay, expiry window)
     */
    public static function sendOrderPlacedEmail(int $orderId): int
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("
            SELECT o.*, u.email as user_email, u.first_name, u.last_name
            FROM orders o
            LEFT JOIN users u ON u.id = o.user_id
            WHERE o.id = :id
        ");
        $stmt->execute([':id' => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            return 0;
        }

        $recipientEmail = !empty($order['user_email']) ? $order['user_email'] : '';
        if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            return 0;
        }

        $customerName = trim(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? ''));
        if ($customerName === '') {
            $customerName = $order['recipient_name'] ?: 'Customer';
        }

        $storeName = setting('store_name', 'ShopWise Kenya');
        $supportEmail = setting('support_email', 'support@kennedycheroben.co.ke');
        $supportPhone = setting('support_phone', '+254 700 000 000');
        $expiryMinutes = (int)setting('order_expiry_minutes', 30);

        $subject = "Order Placed - Pending M-Pesa Payment: #{$order['order_number']} - {$storeName}";
        $safeName = htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8');
        $orderNum = htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8');
        $formattedTotal = format_money((float)$order['total']);
        $phone = htmlspecialchars($order['phone'], ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 20px; background: #f4f6f9;">
    <div style="max-width: 600px; margin: 0 auto; background: #ffffff; padding: 30px; border-radius: 8px; border: 1px solid #e5e7eb;">
        <div style="border-bottom: 2px solid #ea580c; padding-bottom: 15px; margin-bottom: 20px;">
            <h2 style="margin: 0; color: #111827;">{$storeName}</h2>
            <small style="color: #6b7280;">Order #{$orderNum}</small>
        </div>
        <h3 style="color: #1f2937; margin-top: 0;">Order Placed - Action Required</h3>
        <p>Habari <strong>{$safeName}</strong>,</p>
        <p>Your order <strong>#{$orderNum}</strong> for <strong>{$formattedTotal}</strong> has been received and reserved for <strong>{$expiryMinutes} minutes</strong>.</p>
        <div style="background: #fff7ed; border-left: 4px solid #ea580c; padding: 15px; margin: 20px 0; border-radius: 4px;">
            <h4 style="margin: 0 0 10px 0; color: #9a3412;">How to Complete Your Payment</h4>
            <ol style="margin: 0; padding-left: 20px; color: #431407;">
                <li>An M-Pesa STK Push prompt was sent to your phone <strong>{$phone}</strong>.</li>
                <li>Enter your M-Pesa secret PIN on your handset when prompted.</li>
                <li>Once Safaricom confirms your transaction, your order will automatically be confirmed for dispatch.</li>
            </ol>
            <p style="margin: 10px 0 0 0; font-size: 13px; color: #7c2d12;">If your prompt did not appear or timed out, you can retry from your order payment page before the {$expiryMinutes}-minute window expires.</p>
        </div>
        <p style="font-size: 13px; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 15px; margin-top: 25px;">
            Need help? Contact customer service at <a href="mailto:{$supportEmail}" style="color: #ea580c;">{$supportEmail}</a> or call {$supportPhone}.
        </p>
    </div>
</body>
</html>
HTML;

        $text = "Habari {$customerName},\n\n";
        $text .= "Your order #{$order['order_number']} for {$formattedTotal} has been placed.\n";
        $text .= "Reserved window: {$expiryMinutes} minutes.\n\n";
        $text .= "An M-Pesa prompt has been sent to {$order['phone']}. Enter your PIN to complete payment.\n";
        $text .= "Need help? Contact {$supportEmail} or {$supportPhone}.\n";

        return self::queue($recipientEmail, $subject, $html, $text, $customerName);
    }

    /**
     * Send payment received email with M-Pesa receipt and delivery details
     */
    public static function sendOrderPaidEmail(int $orderId, string $receipt): int
    {
        $note = "M-Pesa Receipt: " . $receipt;
        return self::sendOrderStatusEmail($orderId, 'paid', $note);
    }

    /**
     * Send admin notification email for a newly paid order
     */
    public static function sendAdminNewPaidOrderEmail(int $orderId, string $receipt): int
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("
            SELECT o.*, u.email as user_email, u.first_name, u.last_name
            FROM orders o
            LEFT JOIN users u ON u.id = o.user_id
            WHERE o.id = :id
        ");
        $stmt->execute([':id' => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            return 0;
        }

        $adminEmail = setting('order_notify_email', setting('support_email', 'support@kennedycheroben.co.ke'));
        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $adminEmail = 'support@kennedycheroben.co.ke';
        }

        $storeName = setting('store_name', 'ShopWise Kenya');
        $subject = "[ADMIN ALERT] New Paid Order: #{$order['order_number']} ({$receipt})";
        $orderNum = htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8');
        $safeReceipt = htmlspecialchars($receipt, ENT_QUOTES, 'UTF-8');
        $formattedTotal = format_money((float)$order['total']);
        $custName = htmlspecialchars($order['recipient_name'], ENT_QUOTES, 'UTF-8');
        $phone = htmlspecialchars($order['phone'], ENT_QUOTES, 'UTF-8');
        $delivery = htmlspecialchars($order['delivery_method'] === 'pickup' ? 'Store Pickup' : ($order['county'] . ' - ' . $order['area']), ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
<!DOCTYPE html>
<html>
<body style="font-family: sans-serif; line-height: 1.5; color: #111;">
    <h3>New Order Paid via M-Pesa</h3>
    <table cellpadding="6" style="border-collapse: collapse; width: 100%; max-width: 500px;">
        <tr><td><strong>Order Number:</strong></td><td>#{$orderNum}</td></tr>
        <tr><td><strong>M-Pesa Receipt:</strong></td><td>{$safeReceipt}</td></tr>
        <tr><td><strong>Amount Paid:</strong></td><td>{$formattedTotal}</td></tr>
        <tr><td><strong>Customer:</strong></td><td>{$custName}</td></tr>
        <tr><td><strong>Phone:</strong></td><td>{$phone}</td></tr>
        <tr><td><strong>Delivery:</strong></td><td>{$delivery}</td></tr>
    </table>
    <p>Please log in to the admin panel to review and prepare this order for dispatch.</p>
</body>
</html>
HTML;

        $text = "New Paid Order #{$order['order_number']}\n";
        $text .= "Receipt: {$receipt}\nAmount: {$formattedTotal}\nCustomer: {$order['recipient_name']}\nPhone: {$order['phone']}\n";

        return self::queue($adminEmail, $subject, $html, $text, 'Store Admin');
    }

    /**
     * Send admin alert for late payment where stock was unavailable
     */
    public static function sendAdminLatePaymentStockAlertEmail(int $orderId, string $receipt): int
    {
        $adminEmail = setting('order_notify_email', setting('support_email', 'support@kennedycheroben.co.ke'));
        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $adminEmail = 'support@kennedycheroben.co.ke';
        }

        $subject = "[ATTENTION REQUIRED] Late M-Pesa Payment on Expired Order #{$orderId}";
        $safeReceipt = htmlspecialchars($receipt, ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
<!DOCTYPE html>
<html>
<body style="font-family: sans-serif; line-height: 1.5; color: #111;">
    <h3 style="color: #b02a37;">ATTENTION: Late M-Pesa Payment Received - Stock Unavailable</h3>
    <p>A payment of M-Pesa receipt <strong>{$safeReceipt}</strong> was received for order ID <strong>#{$orderId}</strong> after the order had already expired.</p>
    <p>The system attempted to re-reserve inventory, but one or more variants are out of stock.</p>
    <p><strong>Action Required:</strong> Please open Order #{$orderId} in the admin orders panel to inspect the inventory and either fulfill manually or issue a refund to the customer.</p>
</body>
</html>
HTML;

        $text = "ATTENTION: Late M-Pesa Payment on Expired Order #{$orderId}\nReceipt: {$receipt}\nStock could not be re-reserved. Manual refund or fulfillment required.\n";

        return self::queue($adminEmail, $subject, $html, $text, 'Store Admin');
    }

    /**
     * Send admin alert for payment attention reasons (amount mismatch, invalid receipt, etc.).
     */
    public static function sendAdminAttentionAlert(int $orderId, string $reason): int
    {
        $adminEmail = setting('order_notify_email', setting('support_email', 'support@kennedycheroben.co.ke'));
        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $adminEmail = 'support@kennedycheroben.co.ke';
        }

        $subject = "[ATTENTION REQUIRED] M-Pesa Payment Anomaly on Order #{$orderId}";
        $safeReason = htmlspecialchars($reason, ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
<!DOCTYPE html>
<html>
<body style="font-family: sans-serif; line-height: 1.5; color: #111;">
    <h3 style="color: #b02a37;">ATTENTION: M-Pesa Payment Flagged</h3>
    <p>A payment callback for order ID <strong>#{$orderId}</strong> was received with an anomaly:</p>
    <p style="background: #fff3cd; padding: 10px; border-left: 4px solid #ffc107;"><strong>Reason:</strong> {$safeReason}</p>
    <p><strong>Action Required:</strong> Please review Order #{$orderId} in the admin orders panel to inspect the transaction details.</p>
</body>
</html>
HTML;

        $text = "ATTENTION: M-Pesa Payment Flagged on Order #{$orderId}\nReason: {$reason}\nPlease review in admin panel.\n";

        return self::queue($adminEmail, $subject, $html, $text, 'Store Admin');
    }
}

