<?php
/**
 * ShopWise Kenya - Automated Testing Configuration Override (Template)
 *
 * This configuration is loaded ONLY when app.environment = 'local'
 * AND (the HTTP request host is exactly '127.0.0.1' OR CLI variable SHOPWISE_CONFIG=testing).
 *
 * NEVER loaded in production (environment != 'local').
 * Copy this file to config.testing.php for local test execution.
 */

return [
    'app' => [
        'base_url' => 'http://127.0.0.1/shopwise-platform/public',
        'debug'    => true,
    ],

    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'shopwise_test',
        'username' => 'root',
        'password' => '',
        'charset'  => 'utf8mb4',
    ],

    'uploads' => [
        'products_path' => dirname(__DIR__, 2) . '/public/uploads_test/products',
    ],

    'rate_limits' => [
        'cart_writes_per_session' => 60,
        'cart_writes_per_ip'      => 300,
        'contact_messages_per_ip' => 5, // 5 per hour per IP
        'storage_dir'             => dirname(__DIR__, 2) . '/app/storage/rate_limits_test',
    ],
];
