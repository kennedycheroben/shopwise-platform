<?php
/**
 * ShopWise Kenya - Base Application Configuration
 *
 * Centralizes defaults and environment-driven settings.
 * All sensitive credentials and environment-specific values are loaded
 * from .env via App\Config\Env.
 */

if (!class_exists(\App\Config\Env::class)) {
    require_once dirname(__DIR__) . '/src/Config/Env.php';
}
\App\Config\Env::load();

return [
    'app' => [
        'name'        => (string)\App\Config\Env::get('APP_NAME', 'ShopWise Kenya'),
        'environment' => (string)\App\Config\Env::get('APP_ENV', 'local'), // 'local' or 'production'
        'debug'       => (bool)\App\Config\Env::get('APP_DEBUG', true),
        // Set explicitly or leave empty for auto-detection
        // Examples:
        // Local:      'http://localhost/shopwise-platform/public'
        // Production: 'https://shop.kennedycheroben.co.ke'
        'base_url'    => (string)\App\Config\Env::get('APP_URL', ''),
        'key'         => (string)\App\Config\Env::get('APP_KEY', ''),
        'timezone'    => (string)\App\Config\Env::get('APP_TIMEZONE', 'Africa/Nairobi'),
    ],

    'db' => [
        'host'     => (string)\App\Config\Env::get('DB_HOST', '127.0.0.1'),
        'port'     => (int)\App\Config\Env::get('DB_PORT', 3306),
        'database' => (string)\App\Config\Env::get('DB_DATABASE', 'shopwise_db'),
        'username' => (string)\App\Config\Env::get('DB_USERNAME', 'root'),
        'password' => (string)\App\Config\Env::get('DB_PASSWORD', ''),
        'charset'  => (string)\App\Config\Env::get('DB_CHARSET', 'utf8mb4'),
        'options'  => [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ],
    ],

    'mpesa' => [
        // 'sandbox' or 'production'. NEVER default to production.
        // Safaricom public Daraja sandbox passkey is published in Safaricom documentation:
        // bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919
        'env'                        => (string)\App\Config\Env::get('MPESA_ENV', 'sandbox'),
        'consumer_key'               => (string)\App\Config\Env::get('MPESA_CONSUMER_KEY', ''),
        'consumer_secret'            => (string)\App\Config\Env::get('MPESA_CONSUMER_SECRET', ''),
        'shortcode'                  => (string)\App\Config\Env::get('MPESA_SHORTCODE', '174379'),
        'passkey'                    => (string)\App\Config\Env::get('MPESA_PASSKEY', 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919'),
        'transaction_type'           => (string)\App\Config\Env::get('MPESA_TRANSACTION_TYPE', 'CustomerPayBillOnline'),
        // The callback URL sent to Safaricom is: <callback_base_url>/payments/callback/<callback_secret>
        // Note: Safaricom rejects callback URLs containing "mpesa", "safaricom", or "daraja" in hostname.
        'callback_base_url'          => (string)\App\Config\Env::get('MPESA_CALLBACK_BASE_URL', ''),
        'callback_secret'            => (string)\App\Config\Env::get('MPESA_CALLBACK_SECRET', ''),
        'initiator_name'             => (string)\App\Config\Env::get('MPESA_INITIATOR_NAME', 'testapi'),
        'callback_allowed_ips'       => [], // Optional IP allowlist for callbacks. Empty array allows all.
        'verify_callback_with_query' => true, // Confirm callback success with STK Push Query
        'endpoints'                  => [
            'sandbox' => [
                'oauth'        => 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials',
                'stkpush'      => 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest',
                'stkpushquery' => 'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query',
            ],
            'production' => [
                'oauth'        => 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials',
                'stkpush'      => 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest',
                'stkpushquery' => 'https://api.safaricom.co.ke/mpesa/stkpushquery/v1/query',
            ],
        ],
    ],

    'mail' => [
        'driver'       => (string)\App\Config\Env::get('MAIL_DRIVER', 'queue_only'), // 'smtp', 'mail', or 'queue_only'
        'host'         => (string)\App\Config\Env::get('MAIL_HOST', 'mail.kennedycheroben.co.ke'),
        'port'         => (int)\App\Config\Env::get('MAIL_PORT', 465),
        'username'     => (string)\App\Config\Env::get('MAIL_USERNAME', 'support@kennedycheroben.co.ke'),
        'password'     => (string)\App\Config\Env::get('MAIL_PASSWORD', ''),
        'encryption'   => \App\Config\Env::get('MAIL_ENCRYPTION', 'ssl'), // 'ssl', 'tls', or null
        'from_address' => (string)\App\Config\Env::get('MAIL_FROM_ADDRESS', 'support@kennedycheroben.co.ke'),
        'from_name'    => (string)\App\Config\Env::get('MAIL_FROM_NAME', 'ShopWise Kenya'),
    ],

    'uploads' => [
        'max_file_size'      => (int)\App\Config\Env::get('UPLOAD_MAX_FILE_SIZE_MB', 5) * 1024 * 1024, // 5 MB default
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp'],
        'allowed_mimes'      => ['image/jpeg', 'image/png', 'image/webp'],
        'products_path'      => dirname(__DIR__, 2) . '/public/uploads/products',
    ],

    'rate_limits' => [
        'cart_writes_per_session' => 60,
        'cart_writes_per_ip'      => 300,
        'contact_messages_per_ip' => 5, // 5 per hour per IP
        'storage_dir'             => (string)\App\Config\Env::get('RATE_LIMIT_STORAGE_DIR', '') ?: (dirname(__DIR__, 2) . '/app/storage/rate_limits'),
    ],

    'google' => [
        'client_id'     => (string)\App\Config\Env::get('GOOGLE_CLIENT_ID', ''),
        'client_secret' => (string)\App\Config\Env::get('GOOGLE_CLIENT_SECRET', ''),
        'redirect_uri'  => (string)\App\Config\Env::get('GOOGLE_REDIRECT_URI', ''),
    ],
];
