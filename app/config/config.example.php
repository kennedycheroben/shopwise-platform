<?php
/**
 * ShopWise Kenya - Example Configuration
 * Copy this file to config.local.php and update with your local or production credentials.
 * NEVER commit config.local.php to version control.
 */

return [
    'app' => [
        'name'        => 'ShopWise Kenya',
        'environment' => 'local', // 'local' or 'production'
        'debug'       => true,
        // Set explicitly or leave empty for auto-detection
        // Examples:
        // Local:      'http://localhost/shopwise-platform/public'
        // Production: 'https://shop.kennedycheroben.co.ke'
        'base_url'    => '',
        'timezone'    => 'Africa/Nairobi',
    ],

    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'shopwise_db',
        'username' => 'root',
        'password' => '',
        'charset'  => 'utf8mb4',
        'options'  => [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ],
    ],

    'mpesa' => [
        // 'sandbox' or 'live'.
        // NOTE: Safaricom's public Daraja sandbox passkey is bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919
        // which is published in Safaricom's public documentation for testing.
        // Production credentials go ONLY in config.local.php and must never be committed.
        'env'                 => 'sandbox',
        'consumer_key'        => 'YOUR_MPESA_CONSUMER_KEY',
        'consumer_secret'     => 'YOUR_MPESA_CONSUMER_SECRET',
        'shortcode'           => '174379',  // Default Daraja sandbox Business Shortcode
        'passkey'             => 'YOUR_MPESA_PASSKEY', // Use public sandbox passkey for sandbox, live passkey in config.local.php
        'initiator_name'      => 'testapi',
        'callback_url'        => 'https://your-domain.co.ke/api/mpesa/callback.php',
        'transaction_type'    => 'CustomerPayBillOnline',
    ],

    'google_oauth' => [
        'client_id'     => 'YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com',
        'client_secret' => 'YOUR_GOOGLE_CLIENT_SECRET',
        'redirect_uri'  => 'https://your-domain.co.ke/auth/google/callback.php',
    ],

    'mail' => [
        'driver'       => 'smtp', // 'smtp', 'mail', or 'queue_only'
        'host'         => 'mail.kennedycheroben.co.ke',
        'port'         => 465,
        'username'     => 'support@kennedycheroben.co.ke',
        'password'     => 'YOUR_EMAIL_PASSWORD',
        'encryption'   => 'ssl', // 'ssl', 'tls', or null
        'from_address' => 'support@kennedycheroben.co.ke',
        'from_name'    => 'ShopWise Kenya',
    ],

    'uploads' => [
        'max_file_size'      => 5 * 1024 * 1024, // 5 MB
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp'],
        'allowed_mimes'      => ['image/jpeg', 'image/png', 'image/webp'],
        'products_path'      => dirname(__DIR__, 2) . '/public/uploads/products',
    ],

    'rate_limits' => [
        'cart_writes_per_session' => 60,
        'cart_writes_per_ip'      => 300,
        'contact_messages_per_ip' => 5, // 5 per hour per IP
        'storage_dir'             => dirname(__DIR__, 2) . '/app/storage/rate_limits',
    ],

    'google' => [
        'client_id'     => '', // e.g. 'YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com'
        'client_secret' => '', // e.g. 'YOUR_GOOGLE_CLIENT_SECRET'
    ],
];
