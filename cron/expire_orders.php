<?php
/**
 * ShopWise Kenya - Order Expiration Cron Script
 * Phase 1 placeholder for the future order-expiration worker.
 * It deliberately performs no order or inventory writes until checkout and
 * order-lifecycle handling are implemented in a later phase.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("CLI only\n");
}

echo "[" . date('Y-m-d H:i:s') . "] Order-expiration worker is reserved for a later phase. No action taken.\n";
