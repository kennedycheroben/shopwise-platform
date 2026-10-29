<?php
/**
 * ShopWise Kenya - Abandoned Cart Purge Cron Script
 * Deletes carts and cart items untouched for more than 30 days.
 * CLI Only.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("CLI only\n");
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Database\Database;

$pdo = Database::getInstance();

try {
    // 1. Delete empty carts (0 items) created more than 24 hours ago
    $emptyStmt = $pdo->prepare("DELETE FROM carts WHERE id NOT IN (SELECT DISTINCT cart_id FROM cart_items) AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $emptyStmt->execute();
    $purgedEmpty = $emptyStmt->rowCount();
    echo "[" . date('Y-m-d H:i:s') . "] Successfully purged {$purgedEmpty} empty cart(s) older than 24 hours.\n";

    // 2. Delete abandoned carts (and cascaded items) untouched for > 30 days
    $abandonedStmt = $pdo->prepare("DELETE FROM carts WHERE updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $abandonedStmt->execute();
    $purgedAbandoned = $abandonedStmt->rowCount();
    echo "[" . date('Y-m-d H:i:s') . "] Successfully purged {$purgedAbandoned} abandoned cart(s) untouched for > 30 days.\n";

    // 3. Clean up rate limit tracking files older than 1 hour
    $config = require dirname(__DIR__) . '/app/config/config.php';
    $rateLimitDir = $config['rate_limits']['storage_dir'] ?? (dirname(__DIR__) . '/app/storage/rate_limits');
    if (is_dir($rateLimitDir)) {
        $cleaned = 0;
        foreach (glob($rateLimitDir . '/*.json') as $rf) {
            if (time() - filemtime($rf) > 3600) {
                if (@unlink($rf)) {
                    $cleaned++;
                }
            }
        }
        if ($cleaned > 0) {
            echo "[" . date('Y-m-d H:i:s') . "] Cleaned {$cleaned} expired rate limit tracking file(s).\n";
        }
    }
} catch (\Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] Error purging carts: " . $e->getMessage() . "\n";
    exit(1);
}
