<?php
/**
 * ShopWise Kenya - Outbound Email Queue Dispatcher
 * Dispatches pending emails enqueued in email_queue table.
 * Recommended Schedule: * / 2 * * * * (Every 2 minutes via cPanel Cron)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("CLI only\n");
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\MailerService;

echo "[" . date('Y-m-d H:i:s') . "] Starting email queue dispatcher...\n";

try {
    $mailer = new MailerService();
    $sent = $mailer->processQueue(25);
    echo "[" . date('Y-m-d H:i:s') . "] Successfully processed {$sent} queued email(s).\n";
} catch (Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] Error dispatching email queue: " . $e->getMessage() . "\n";
    exit(1);
}
