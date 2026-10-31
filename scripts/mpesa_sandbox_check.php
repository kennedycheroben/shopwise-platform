#!/usr/bin/env php
<?php
/**
 * scripts/mpesa_sandbox_check.php
 *
 * READ-ONLY CLI verification tool for Safaricom Daraja Sandbox credentials.
 * Requests an OAuth 2.0 Access Token ONLY (does NOT initiate STK push or charge).
 * Never exposes secrets, keys, or raw bearer tokens in output.
 *
 * Usage:
 *   php scripts/mpesa_sandbox_check.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI execution only.\n";
    exit(1);
}

$rootDir = dirname(__DIR__);
require_once $rootDir . '/app/bootstrap.php';

use App\Services\CurlHttpClient;

echo "==============================================================================\n";
echo "    SHOPWISE PLATFORM - SAFARICOM DARAJA SANDBOX CREDENTIALS CHECK            \n";
echo "==============================================================================\n";

$localConfigFile = $rootDir . '/app/config/config.local.php';
if (!file_exists($localConfigFile)) {
    echo "[-] config.local.php not found. Please create app/config/config.local.php.\n";
    exit(1);
}

$localConfig = require $localConfigFile;
$mpesaConfig = $localConfig['mpesa'] ?? [];

$consumerKey = trim((string)($mpesaConfig['consumer_key'] ?? ''));
$consumerSecret = trim((string)($mpesaConfig['consumer_secret'] ?? ''));
$env = trim((string)($mpesaConfig['env'] ?? 'sandbox'));
$shortcode = trim((string)($mpesaConfig['shortcode'] ?? ''));
$passkey = trim((string)($mpesaConfig['passkey'] ?? ''));

echo "Environment:        " . ($env !== '' ? $env : 'sandbox') . "\n";
echo "Shortcode:          " . ($shortcode !== '' ? $shortcode : '[NOT SET]') . "\n";
echo "Consumer Key:       " . ($consumerKey !== '' ? substr($consumerKey, 0, 4) . '...' . substr($consumerKey, -4) : '[NOT SET]') . "\n";
echo "Consumer Secret:    " . ($consumerSecret !== '' ? '[CONFIGURED - MASKED]' : '[NOT SET]') . "\n";
echo "Passkey:            " . ($passkey !== '' ? '[CONFIGURED - MASKED]' : '[NOT SET]') . "\n";
echo "------------------------------------------------------------------------------\n";

if ($consumerKey === '' || $consumerSecret === '') {
    echo "[-] ERROR: Missing consumer_key or consumer_secret in app/config/config.local.php.\n";
    exit(1);
}

$oauthEndpoint = ($env === 'production')
    ? 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials'
    : 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';

echo "[*] Requesting OAuth token from Safaricom Daraja ({$env})...\n";

$basicAuth = base64_encode($consumerKey . ':' . $consumerSecret);
$client = new CurlHttpClient(15);

try {
    $response = $client->get($oauthEndpoint, [
        'Authorization' => 'Basic ' . $basicAuth,
        'Accept'        => 'application/json',
    ]);
} catch (\Throwable $e) {
    echo "[-] Network Error: Could not reach Safaricom OAuth endpoint.\n";
    echo "    Details: " . $e->getMessage() . "\n";
    exit(1);
}

$status = (int)($response['status'] ?? 0);
$bodyRaw = (string)($response['body'] ?? '');
$body = json_decode($bodyRaw, true);

if ($status === 200 && is_array($body) && !empty($body['access_token'])) {
    $expiresIn = (int)($body['expires_in'] ?? 3599);
    echo "[+] OK: Safaricom Daraja OAuth authentication succeeded!\n";
    echo "    HTTP Status:       200 OK\n";
    echo "    Token Validity:    {$expiresIn} seconds\n";
    echo "    Sandbox Ready:     YES (credentials are valid for STK Push)\n";
    echo "==============================================================================\n";
    exit(0);
} else {
    echo "[-] FAILED: Safaricom Daraja OAuth authentication failed.\n";
    echo "    HTTP Status:       {$status}\n";
    $errorMsg = is_array($body) ? ($body['errorMessage'] ?? $body['error_description'] ?? 'Authentication failed') : 'Unknown response format';
    echo "    Safaricom Message: {$errorMsg}\n";
    echo "    Check your consumer_key and consumer_secret in app/config/config.local.php.\n";
    echo "==============================================================================\n";
    exit(1);
}
