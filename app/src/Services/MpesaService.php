<?php

namespace App\Services;

use App\Config\Env;
use App\Database\Database;
use DateTime;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use LogicException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Service for Safaricom Daraja M-Pesa API integrations:
 * - OAuth 2.0 Client Credentials Token Generation & Caching
 * - STK Push (Lipa Na M-Pesa Online)
 * - STK Push Query
 * - Callback Validation & Processing
 *
 * SAFETY RULES:
 * - Test mode uses HttpClientInterface stub injection (SHOPWISE_TESTING_MODE only).
 * - Real API calls use CurlHttpClient.
 * - Sensitive credentials (passkeys, secrets, access tokens) are never logged or exposed.
 * - All amounts are strictly whole Kenyan Shillings (KES).
 */
final class MpesaService
{
    private static ?HttpClientInterface $httpClient = null;

    /**
     * Inject custom HTTP client for testing.
     */
    public static function setHttpClient(HttpClientInterface $client): void
    {
        if (!defined('SHOPWISE_TESTING_MODE') || !SHOPWISE_TESTING_MODE) {
            throw new LogicException('HttpClient injection is strictly forbidden outside of testing mode.');
        }
        self::$httpClient = $client;
    }

    /**
     * Reset HTTP client.
     */
    public static function resetHttpClient(): void
    {
        self::$httpClient = null;
    }

    /**
     * Retrieve HTTP client instance.
     */
    public static function getHttpClient(): HttpClientInterface
    {
        if (self::$httpClient !== null) {
            return self::$httpClient;
        }

        if (defined('SHOPWISE_TESTING_MODE') && SHOPWISE_TESTING_MODE === true) {
            $mockFile = dirname(__DIR__, 2) . '/storage/mpesa_mock_responses.json';
            if (file_exists($mockFile)) {
                return new class($mockFile) implements HttpClientInterface {
                    private string $mockFile;
                    public function __construct(string $file) {
                        $this->mockFile = $file;
                    }

                    public function get(string $url, array $headers = []): array
                    {
                        return $this->handle('GET', $url, $headers, null);
                    }

                    public function post(string $url, array|string $data = [], array $headers = []): array
                    {
                        $body = is_array($data) ? json_encode($data) : (string)$data;
                        return $this->handle('POST', $url, $headers, $body);
                    }

                    private function handle(string $method, string $url, array $headers = [], ?string $body = null): array
                    {
                        $data = file_exists($this->mockFile) ? json_decode(file_get_contents($this->mockFile), true) : [];
                        $sapiSuffix = (php_sapi_name() === 'cli') ? '_cli' : '';
                        $logFile = dirname($this->mockFile) . '/mpesa_mock_requests' . $sapiSuffix . '.log';
                        @file_put_contents($logFile, json_encode([
                            'method'  => $method,
                            'url'     => $url,
                            'headers' => $headers,
                            'body'    => (is_string($body) && ($decoded = json_decode($body, true)) !== null) ? $decoded : $body,
                            'time'    => time(),
                        ]) . "\n", FILE_APPEND | LOCK_EX);

                        if (str_contains($url, 'oauth')) {
                            return [
                                'status'  => 200,
                                'body'    => json_encode(['access_token' => 'mock_access_token_testing_abc123', 'expires_in' => '3599']),
                                'headers' => ['content-type' => 'application/json'],
                            ];
                        }

                        if (str_contains($url, 'query')) {
                            $queryResp = $data['query_response'] ?? [
                                'ResponseCode'        => '0',
                                'ResponseDescription' => 'The service request has been accepted successfully',
                                'MerchantRequestID'   => 'mock-merchant-query-id',
                                'CheckoutRequestID'   => 'ws_CO_TEST_QUERY',
                                'ResultCode'          => '0',
                                'ResultDesc'          => 'The service request is processed successfully.',
                            ];
                            $status = (int)($data['query_status'] ?? 200);
                            return [
                                'status'  => $status,
                                'body'    => json_encode($queryResp),
                                'headers' => ['content-type' => 'application/json'],
                            ];
                        }

                        // STK Push Request
                        $stkResp = $data['stk_response'] ?? [
                            'MerchantRequestID'   => 'mock-merchant-stk-' . time(),
                            'CheckoutRequestID'   => 'ws_CO_TEST_' . time() . '_' . rand(1000, 9999),
                            'ResponseCode'        => '0',
                            'ResponseDescription' => 'Success. Request accepted for processing',
                            'CustomerMessage'     => 'Success. Request accepted for processing',
                        ];
                        $status = (int)($data['stk_status'] ?? 200);

                        return [
                            'status'  => $status,
                            'body'    => json_encode($stkResp),
                            'headers' => ['content-type' => 'application/json'],
                        ];
                    }
                };
            }
        }

        return new CurlHttpClient(20);
    }

    /**
     * Load M-Pesa configuration array.
     */
    public static function getConfig(): array
    {
        $allConfig = require dirname(__DIR__, 2) . '/config/config.php';
        $cfg = $allConfig['mpesa'] ?? [];

        // Ensure .env variables provide fallback if not populated in array
        $cfg['env']                 = $cfg['env'] ?? (string)Env::get('MPESA_ENV', 'sandbox');
        $cfg['consumer_key']        = $cfg['consumer_key'] ?? (string)Env::get('MPESA_CONSUMER_KEY', '');
        $cfg['consumer_secret']     = $cfg['consumer_secret'] ?? (string)Env::get('MPESA_CONSUMER_SECRET', '');
        $cfg['shortcode']           = $cfg['shortcode'] ?? (string)Env::get('MPESA_SHORTCODE', '174379');
        $cfg['passkey']             = $cfg['passkey'] ?? (string)Env::get('MPESA_PASSKEY', '');
        $cfg['transaction_type']    = $cfg['transaction_type'] ?? (string)Env::get('MPESA_TRANSACTION_TYPE', 'CustomerPayBillOnline');
        $cfg['callback_base_url']   = $cfg['callback_base_url'] ?? (string)Env::get('MPESA_CALLBACK_BASE_URL', '');
        $cfg['callback_secret']     = $cfg['callback_secret'] ?? (string)Env::get('MPESA_CALLBACK_SECRET', '');
        $cfg['initiator_name']      = $cfg['initiator_name'] ?? (string)Env::get('MPESA_INITIATOR_NAME', 'testapi');

        return $cfg;
    }

    /**
     * Check if M-Pesa integration is configured with required keys.
     */
    public static function isConfigured(): bool
    {
        $cfg = self::getConfig();
        $required = ['consumer_key', 'consumer_secret', 'shortcode', 'passkey', 'callback_base_url', 'callback_secret'];
        foreach ($required as $key) {
            if (empty($cfg[$key]) || !is_string($cfg[$key]) || trim($cfg[$key]) === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * Normalize Kenyan phone number to 254XXXXXXXXX format.
     * Supports formats: +254712345678, 254712345678, 0712345678, 712345678, 0112345678, etc.
     */
    public static function normalizePhone(string $phone): ?string
    {
        $clean = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($clean, '0') && (strlen($clean) === 10)) {
            $clean = '254' . substr($clean, 1);
        } elseif (str_starts_with($clean, '7') && (strlen($clean) === 9)) {
            $clean = '254' . $clean;
        } elseif (str_starts_with($clean, '1') && (strlen($clean) === 9)) {
            $clean = '254' . $clean;
        }

        // Must match 254 followed by 7 or 1 and 8 digits (total 12 digits)
        if (preg_match('/^254[71][0-9]{8}$/', $clean)) {
            return $clean;
        }
        return null;
    }

    /**
     * Get OAuth token endpoint URL based on environment.
     */
    public static function getOAuthEndpoint(): string
    {
        $cfg = self::getConfig();
        $env = strtolower($cfg['env'] ?? 'sandbox');
        return $cfg['endpoints'][$env]['oauth']
            ?? ($env === 'production'
                ? 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials'
                : 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials');
    }

    /**
     * Get STK Push endpoint URL based on environment.
     */
    public static function getStkPushEndpoint(): string
    {
        $cfg = self::getConfig();
        $env = strtolower($cfg['env'] ?? 'sandbox');
        return $cfg['endpoints'][$env]['stkpush']
            ?? ($env === 'production'
                ? 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest'
                : 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest');
    }

    /**
     * Get STK Push Query endpoint URL based on environment.
     */
    public static function getStkPushQueryEndpoint(): string
    {
        $cfg = self::getConfig();
        $env = strtolower($cfg['env'] ?? 'sandbox');
        return $cfg['endpoints'][$env]['stkpushquery']
            ?? ($env === 'production'
                ? 'https://api.safaricom.co.ke/mpesa/stkpushquery/v1/query'
                : 'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query');
    }

    /**
     * Generate current timestamp in East Africa Time (EAT, UTC+3) as YYYYMMDDHHMMSS.
     */
    public static function getEatTimestamp(): string
    {
        $dt = new DateTime('now', new DateTimeZone('Africa/Nairobi'));
        return $dt->format('YmdHis');
    }

    /**
     * Compute Daraja password = base64(shortcode . passkey . timestamp).
     */
    public static function computePassword(string $shortcode, string $passkey, string $timestamp): string
    {
        return base64_encode($shortcode . $passkey . $timestamp);
    }

    /**
     * Fetch OAuth 2.0 Access Token with local cache support.
     */
    /**
     * Get and ensure protected payment storage directory exists with mode 0750.
     * Fails closed if not writable.
     */
    public static function getPaymentsStorageDir(): string
    {
        $baseDir = dirname(__DIR__, 2) . '/storage';
        $sapiSuffix = (defined('SHOPWISE_TESTING_MODE') && SHOPWISE_TESTING_MODE && php_sapi_name() === 'cli') ? '_cli' : '';
        $dir = $baseDir . '/payments' . $sapiSuffix;
        if (!is_dir($dir)) {
            $created = @mkdir($dir, 0750, true);
            if (!$created && !is_dir($dir)) {
                error_log("[ShopWise Security] Cannot create payment storage directory: " . $dir);
                throw new RuntimeException("Payment storage directory is unavailable.");
            }
            @chmod($dir, 0750);
        }
        if (!is_writable($dir)) {
            error_log("[ShopWise Security] Payment storage directory is not writable: " . $dir);
            throw new RuntimeException("Payment storage is unavailable.");
        }
        return $dir;
    }

    /**
     * Fetch OAuth 2.0 Access Token with local cache support.
     */
    public static function getAccessToken(): string
    {
        $cfg = self::getConfig();
        if (!self::isConfigured()) {
            throw new RuntimeException('M-Pesa credentials are not configured.');
        }

        try {
            $storageDir = self::getPaymentsStorageDir();
        } catch (\Throwable $e) {
            error_log("[ShopWise Security] Token cache directory error: " . $e->getMessage());
            throw new RuntimeException("Payment service is temporarily unavailable. Please try again later.");
        }

        $sapiSuffix = (php_sapi_name() === 'cli') ? '_cli' : '';
        $cacheFile = $storageDir . '/mpesa_token' . $sapiSuffix . '.json';

        // Check valid cached token
        if (file_exists($cacheFile)) {
            $cached = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($cached) && !empty($cached['access_token']) && !empty($cached['expires_at'])) {
                if (time() < ($cached['expires_at'] - 60)) {
                    return (string)$cached['access_token'];
                }
            }
        }

        $consumerKey = trim($cfg['consumer_key']);
        $consumerSecret = trim($cfg['consumer_secret']);
        $basicAuth = base64_encode($consumerKey . ':' . $consumerSecret);

        $client = self::getHttpClient();
        $endpoint = self::getOAuthEndpoint();

        $response = $client->get($endpoint, [
            'Authorization' => 'Basic ' . $basicAuth,
            'Accept'        => 'application/json',
        ]);

        $statusCode = (int)($response['status'] ?? 0);
        if ($statusCode !== 200) {
            throw new RuntimeException("M-Pesa OAuth failed with HTTP status " . $statusCode);
        }

        $body = json_decode((string)($response['body'] ?? ''), true);
        if (!is_array($body) || empty($body['access_token'])) {
            throw new RuntimeException("Invalid response format from M-Pesa OAuth.");
        }

        $token = (string)$body['access_token'];
        $expiresIn = (int)($body['expires_in'] ?? 3599);

        // Save token to secure cache file with mode 0640
        $cacheData = [
            'access_token' => $token,
            'expires_at'   => time() + $expiresIn,
        ];
        $wrote = @file_put_contents($cacheFile, json_encode($cacheData), LOCK_EX);
        if ($wrote === false) {
            error_log("[ShopWise Security] Failed writing payment token to " . $cacheFile);
            throw new RuntimeException("Payment service is temporarily unavailable. Please try again later.");
        }
        @chmod($cacheFile, 0640);

        return $token;
    }

    /**
     * Build public callback URL for M-Pesa Daraja.
     * Route is renamed to /payments/callback/{secret} to avoid Safaricom keywords.
     */
    public static function getCallbackUrl(): string
    {
        $cfg = self::getConfig();
        $baseUrl = rtrim((string)($cfg['callback_base_url'] ?? ''), '/');
        $secret = (string)($cfg['callback_secret'] ?? '');
        $url = $baseUrl . '/payments/callback/' . rawurlencode($secret);

        // Safaricom has been reported to reject callback URLs containing words such as "mpesa", "safaricom", or "daraja"
        if (preg_match('/(mpesa|safaricom|daraja)/i', $url, $m)) {
            throw new InvalidArgumentException("Safaricom rejects callback URLs containing '" . $m[0] . "'. Check callback_base_url, host, and path.");
        }

        return $url;
    }

    /**
     * Map Daraja result codes to friendly messages.
     */
    public static function mapResultCode(?int $code, string $default = 'Payment could not be completed.'): string
    {
        if ($code === null) {
            return $default;
        }

        return match ($code) {
            0    => 'Payment received successfully.',
            1    => 'Your M-Pesa balance is insufficient for this payment.',
            1032 => 'Payment prompt was cancelled on your phone.',
            1037 => 'Payment prompt timed out without PIN entry. Please try again.',
            2001 => 'The M-Pesa PIN entered was incorrect.',
            default => $default,
        };
    }

    /**
     * Alias for mapResultCode()
     */
    public static function mapResultCodeToMessage(?int $code, string $default = 'Payment could not be completed.'): string
    {
        return self::mapResultCode($code, $default);
    }

    /**
     * Initiate STK Push via Daraja API for an order.
     * Records payment attempt and returns status.
     */
    public static function initiateStkPush(int $orderId, string $phone, ?int $amount = null, ?string $orderNumber = null): array
    {
        if (!self::isConfigured()) {
            return [
                'success' => false,
                'message' => 'M-Pesa payment gateway is not configured.',
            ];
        }

        if ($amount === null || $orderNumber === null) {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare("SELECT total, order_number FROM orders WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $orderId]);
            $ord = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($ord) {
                $amount = (int)floor((float)$ord['total']);
                $orderNumber = (string)$ord['order_number'];
            } else {
                return [
                    'success' => false,
                    'message' => 'Order not found.',
                ];
            }
        }

        $normalizedPhone = self::normalizePhone($phone);
        if (!$normalizedPhone) {
            return [
                'success' => false,
                'message' => 'Please provide a valid Kenyan phone number (e.g. 07XXXXXXXX or 01XXXXXXXX).',
            ];
        }

        if ($amount < 1) {
            return [
                'success' => false,
                'message' => 'Payable amount must be at least KES 1.',
            ];
        }

        $cfg = self::getConfig();
        $shortcode = trim((string)$cfg['shortcode']);
        $passkey = trim((string)$cfg['passkey']);
        $txType = (string)($cfg['transaction_type'] ?? 'CustomerPayBillOnline');
        if (!in_array($txType, ['CustomerPayBillOnline', 'CustomerBuyGoodsOnline'], true)) {
            $txType = 'CustomerPayBillOnline';
        }

        $timestamp = self::getEatTimestamp();
        $password = self::computePassword($shortcode, $passkey, $timestamp);
        $callbackUrl = self::getCallbackUrl();

        // AccountReference must not exceed 12 characters (mark UNKNOWN until user confirms Daraja spec)
        $cleanRef = preg_replace('/[^A-Za-z0-9]/', '', $orderNumber);
        $accRef = substr($cleanRef, -12);
        if ($accRef === '') {
            $accRef = 'ORDER' . $orderId;
        }
        $accRef = substr($accRef, 0, 12);

        // TransactionDesc must not exceed 13 characters (mark UNKNOWN until user confirms Daraja spec)
        $txDesc = substr('StorePurchase', 0, 13);

        $payload = [
            'BusinessShortCode' => $shortcode,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'TransactionType'   => $txType,
            'Amount'            => (int)$amount,
            'PartyA'            => $normalizedPhone,
            'PartyB'            => $shortcode,
            'PhoneNumber'       => $normalizedPhone,
            'CallBackURL'       => $callbackUrl,
            'AccountReference'  => $accRef,
            'TransactionDesc'   => $txDesc,
        ];

        $pdo = Database::getInstance();

        try {
            $token = self::getAccessToken();
            $client = self::getHttpClient();
            $endpoint = self::getStkPushEndpoint();

            $response = $client->post($endpoint, json_encode($payload), [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ]);

            $statusCode = (int)($response['status'] ?? 0);
            $resBody = json_decode((string)($response['body'] ?? ''), true);

            if ($statusCode === 200 && is_array($resBody) && isset($resBody['ResponseCode']) && (string)$resBody['ResponseCode'] === '0') {
                $merchantReqId = (string)($resBody['MerchantRequestID'] ?? '');
                $checkoutReqId = (string)($resBody['CheckoutRequestID'] ?? '');
                $customerMessage = (string)($resBody['CustomerMessage'] ?? 'Success. Request accepted for processing');

                // Record or update payment record as 'pending'
                $checkInit = $pdo->prepare("SELECT id FROM payments WHERE order_id = :oid AND status = 'initiated' AND checkout_request_id IS NULL ORDER BY id ASC LIMIT 1");
                $checkInit->execute([':oid' => $orderId]);
                $initId = $checkInit->fetchColumn();

                if ($initId) {
                    $pStmt = $pdo->prepare("
                        UPDATE payments 
                        SET status = 'pending', phone = :phone, merchant_request_id = :mrid, checkout_request_id = :crid, result_desc = :desc, updated_at = NOW()
                        WHERE id = :id
                    ");
                    $pStmt->execute([
                        ':phone' => $normalizedPhone,
                        ':mrid'  => $merchantReqId,
                        ':crid'  => $checkoutReqId,
                        ':desc'  => $customerMessage,
                        ':id'    => $initId,
                    ]);
                } else {
                    $pStmt = $pdo->prepare("
                        INSERT INTO payments (order_id, method, status, amount, phone, merchant_request_id, checkout_request_id, result_desc, created_at, updated_at)
                        VALUES (:oid, 'mpesa', 'pending', :amt, :phone, :mrid, :crid, :desc, NOW(), NOW())
                    ");
                    $pStmt->execute([
                        ':oid'   => $orderId,
                        ':amt'   => $amount,
                        ':phone' => $normalizedPhone,
                        ':mrid'  => $merchantReqId,
                        ':crid'  => $checkoutReqId,
                        ':desc'  => $customerMessage,
                    ]);
                }

                self::logMpesaEvent('STK_PUSH_SENT', $checkoutReqId, 0, 'STK Push sent successfully');

                return [
                    'success'             => true,
                    'checkout_request_id' => $checkoutReqId,
                    'merchant_request_id' => $merchantReqId,
                    'message'             => $customerMessage,
                ];
            } else {
                $errDesc = is_array($resBody) ? ($resBody['errorMessage'] ?? $resBody['ResponseDescription'] ?? 'STK push rejected by gateway') : 'STK Push failed';
                
                // Record failed payment attempt
                $checkInit = $pdo->prepare("SELECT id FROM payments WHERE order_id = :oid AND status = 'initiated' AND checkout_request_id IS NULL ORDER BY id ASC LIMIT 1");
                $checkInit->execute([':oid' => $orderId]);
                $initId = $checkInit->fetchColumn();

                if ($initId) {
                    $pdo->prepare("UPDATE payments SET status = 'failed', result_desc = :desc, updated_at = NOW() WHERE id = :id")
                        ->execute([':desc' => $errDesc, ':id' => $initId]);
                } else {
                    $pdo->prepare("
                        INSERT INTO payments (order_id, method, status, amount, phone, result_desc, created_at, updated_at)
                        VALUES (:oid, 'mpesa', 'failed', :amt, :phone, :desc, NOW(), NOW())
                    ")->execute([
                        ':oid'   => $orderId,
                        ':amt'   => $amount,
                        ':phone' => $normalizedPhone,
                        ':desc'  => $errDesc,
                    ]);
                }

                self::logMpesaEvent('STK_PUSH_FAILED', null, null, $errDesc);

                return [
                    'success' => false,
                    'message' => 'M-Pesa payment prompt could not be initiated: ' . $errDesc,
                ];
            }
        } catch (Throwable $e) {
            $errDesc = 'Network error or timeout while contacting M-Pesa gateway.';
            $checkInit = $pdo->prepare("SELECT id FROM payments WHERE order_id = :oid AND status = 'initiated' AND checkout_request_id IS NULL ORDER BY id ASC LIMIT 1");
            $checkInit->execute([':oid' => $orderId]);
            $initId = $checkInit->fetchColumn();

            if ($initId) {
                $pdo->prepare("UPDATE payments SET status = 'failed', result_desc = :desc, updated_at = NOW() WHERE id = :id")
                    ->execute([':desc' => $errDesc, ':id' => $initId]);
            } else {
                $pdo->prepare("
                    INSERT INTO payments (order_id, method, status, amount, phone, result_desc, created_at, updated_at)
                    VALUES (:oid, 'mpesa', 'failed', :amt, :phone, :desc, NOW(), NOW())
                ")->execute([
                    ':oid'   => $orderId,
                    ':amt'   => $amount,
                    ':phone' => $normalizedPhone,
                    ':desc'  => $errDesc,
                ]);
            }

            self::logMpesaEvent('STK_PUSH_ERROR', null, null, $e->getMessage());

            return [
                'success' => false,
                'message' => 'Unable to connect to M-Pesa. Please check your phone or retry in a few moments.',
            ];
        }
    }

    /**
     * Query STK Push status from Daraja.
     * Supports either string CheckoutRequestID or int orderId.
     */
    public static function queryStkPush(string|int $target): array
    {
        $checkoutRequestId = (string)$target;
        if (is_numeric($target) && !str_starts_with((string)$target, 'ws_')) {
            $pdo = Database::getInstance();
            $pStmt = $pdo->prepare("
                SELECT checkout_request_id FROM payments 
                WHERE order_id = :oid AND checkout_request_id IS NOT NULL 
                ORDER BY id DESC LIMIT 1
            ");
            $pStmt->execute([':oid' => (int)$target]);
            $found = $pStmt->fetchColumn();
            if ($found) {
                $checkoutRequestId = (string)$found;
            } else {
                return ['success' => false, 'message' => 'No CheckoutRequestID recorded for this order.'];
            }
        }

        $cfg = self::getConfig();
        $shortcode = trim((string)$cfg['shortcode']);
        $passkey = trim((string)$cfg['passkey']);
        $timestamp = self::getEatTimestamp();
        $password = self::computePassword($shortcode, $passkey, $timestamp);

        $payload = [
            'BusinessShortCode' => $shortcode,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId,
        ];

        try {
            $token = self::getAccessToken();
            $client = self::getHttpClient();
            $endpoint = self::getStkPushQueryEndpoint();

            $response = $client->post($endpoint, json_encode($payload), [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ]);

            $body = json_decode((string)($response['body'] ?? ''), true);
            if (!is_array($body)) {
                return ['success' => false, 'message' => 'Invalid response from M-Pesa query service.'];
            }

            $resultCode = isset($body['ResultCode']) ? (int)$body['ResultCode'] : null;
            $resultDesc = (string)($body['ResultDesc'] ?? $body['ResponseDescription'] ?? '');

            return [
                'success'     => ($resultCode === 0),
                'result_code' => $resultCode,
                'result_desc' => $resultDesc,
                'raw'         => $body,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'Failed to reach M-Pesa query service: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Safely log M-Pesa metadata without leaking phone numbers, secrets or card numbers.
     * Logs to app/storage/payments/payments.log with permissions 0640.
     */
    public static function logMpesaEvent(string $event, ?string $checkoutRequestId, ?int $resultCode, string $outcome): void
    {
        try {
            $logDir = self::getPaymentsStorageDir();
            $logFile = $logDir . '/payments.log';

            $entry = sprintf(
                "[%s] EVENT=%s CHECKOUT_ID=%s RESULT_CODE=%s OUTCOME=%s\n",
                date('Y-m-d H:i:s'),
                $event,
                $checkoutRequestId ?: 'N/A',
                $resultCode !== null ? (string)$resultCode : 'N/A',
                substr(preg_replace('/[\r\n\t]+/', ' ', $outcome), 0, 200)
            );

            $res = @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
            if ($res === false) {
                error_log("[ShopWise Security] Cannot write to payment log file: " . $logFile);
            } else {
                @chmod($logFile, 0640);
            }
        } catch (\Throwable $e) {
            error_log("[ShopWise Security] Payment logging failed: " . $e->getMessage());
        }
    }

    /**
     * Check if an order is eligible for an STK Push retry.
     * Returns [bool $canRetry, string $message].
     */
    public static function canRetryStkPush(mixed $order): array
    {
        if (is_int($order) || (is_string($order) && ctype_digit($order))) {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare("SELECT id, status, expires_at FROM orders WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => (int)$order]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$order || !is_array($order)) {
            return [false, "Order not found."];
        }

        if (($order['status'] ?? '') !== 'pending_payment') {
            return [false, "This order is not awaiting payment (current status: " . ($order['status'] ?? 'unknown') . ")."];
        }

        if (strtotime($order['expires_at']) <= time()) {
            return [false, "This order has expired and cannot be paid."];
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM payments WHERE order_id = :oid ORDER BY id DESC");
        $stmt->execute([':oid' => $order['id']]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $attemptsCount = 0;
        foreach ($payments as $p) {
            if ($p['checkout_request_id'] !== null || in_array($p['status'], ['pending', 'pending_verification', 'success', 'failed', 'cancelled', 'timeout'], true)) {
                $attemptsCount++;
            }
        }

        if ($attemptsCount >= 3) {
            return [false, "Maximum M-Pesa payment attempts (3) reached for this order."];
        }

        if (!empty($payments)) {
            $latest = $payments[0];
            $secondsSinceLatest = time() - strtotime($latest['created_at']);
            if ($secondsSinceLatest < 60) {
                $remaining = 60 - $secondsSinceLatest;
                return [false, "Please wait {$remaining} more second(s) (cooldown period) before requesting another M-Pesa prompt."];
            }

            if (!empty($latest['checkout_request_id'])) {
                $queryResult = self::queryStkPush($latest['checkout_request_id']);
                if ($queryResult['success']) {
                    $receipt = $queryResult['raw']['MpesaReceiptNumber'] ?? ('QRC' . substr($latest['checkout_request_id'], -7));
                    OrderService::transitionToPaid((int)$order['id'], $receipt, json_encode($queryResult['raw']));
                    return [false, "Payment for this order has already succeeded! Receipt: {$receipt}."];
                }
                $resCode = $queryResult['result_code'] ?? null;
                $resDesc = strtolower($queryResult['result_desc'] ?? '');
                if ($resCode === null && (str_contains($resDesc, 'process') || str_contains($resDesc, 'pending'))) {
                    return [false, "A payment prompt is still awaiting authorization on your phone. Please check your phone."];
                }
            }
        }

        return [true, "Retry permitted."];
    }

    /**
     * Retry STK Push for an order.
     */
    public static function retryStkPush(int $orderId, ?string $phone = null): array
    {
        $order = OrderService::find($orderId);
        if (!$order) {
            return ['success' => false, 'message' => 'Order not found.'];
        }

        [$canRetry, $reason] = self::canRetryStkPush($order);
        if (!$canRetry) {
            return ['success' => false, 'message' => $reason];
        }

        $targetPhone = !empty($phone) ? $phone : $order['phone'];
        return self::initiateStkPush($orderId, $targetPhone, (int)$order['total'], $order['order_number']);
    }

    /**
     * Process Safaricom Daraja STK Push Callback.
     * Protected by secret in route, strict body parsing, query verification, and late success recovery.
     */
    public static function handleCallback(string $providedSecret, string $rawBody, string $clientIp): array
    {
        $cfg = self::getConfig();
        $expectedSecret = (string)($cfg['callback_secret'] ?? '');

        if ($expectedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
            return [
                'status_code' => 404,
                'response'    => ['error' => 'Not Found'],
            ];
        }

        // Optional IP allowlist check
        $allowedIps = $cfg['callback_allowed_ips'] ?? [];
        if (!empty($allowedIps) && !in_array($clientIp, (array)$allowedIps, true)) {
            self::logMpesaEvent('CALLBACK_IP_REJECTED', null, null, "IP {$clientIp} not in allowlist");
            return [
                'status_code' => 403,
                'response'    => ['error' => 'Forbidden'],
            ];
        }

        // Enforce 64 KB maximum payload limit
        if (strlen($rawBody) > 65536) {
            self::logMpesaEvent('CALLBACK_OVERSIZED', null, null, 'Payload exceeds 64KB');
            return [
                'status_code' => 400,
                'response'    => ['ResultCode' => 1, 'ResultDesc' => 'Payload exceeds 64KB limit'],
            ];
        }

        // Strict JSON parsing
        try {
            $data = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            self::logMpesaEvent('CALLBACK_MALFORMED_JSON', null, null, $e->getMessage());
            return [
                'status_code' => 400,
                'response'    => ['ResultCode' => 1, 'ResultDesc' => 'Malformed JSON'],
            ];
        }

        if (!is_array($data) || !isset($data['Body']['stkCallback'])) {
            self::logMpesaEvent('CALLBACK_INVALID_STRUCTURE', null, null, 'Missing stkCallback object');
            return [
                'status_code' => 400,
                'response'    => ['ResultCode' => 1, 'ResultDesc' => 'Invalid callback structure'],
            ];
        }

        $stk = $data['Body']['stkCallback'];
        $checkoutRequestId = (string)($stk['CheckoutRequestID'] ?? '');
        $merchantRequestId = (string)($stk['MerchantRequestID'] ?? '');
        $resultCode = isset($stk['ResultCode']) ? (int)$stk['ResultCode'] : null;
        $resultDesc = (string)($stk['ResultDesc'] ?? '');

        if ($checkoutRequestId === '' || $resultCode === null) {
            self::logMpesaEvent('CALLBACK_INCOMPLETE', null, null, 'Missing CheckoutRequestID or ResultCode');
            return [
                'status_code' => 400,
                'response'    => ['ResultCode' => 1, 'ResultDesc' => 'Incomplete callback parameters'],
            ];
        }

        $pdo = Database::getInstance();

        // Lock payment and order row inside transaction
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                SELECT p.*, o.id as order_id, o.status as order_status, o.total as order_total, 
                       o.expires_at, o.stock_released, o.order_number
                FROM payments p
                JOIN orders o ON o.id = p.order_id
                WHERE p.checkout_request_id = :crid
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([':crid' => $checkoutRequestId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$payment) {
                $pdo->rollBack();
                self::logMpesaEvent('CALLBACK_UNKNOWN_CHECKOUT_ID', $checkoutRequestId, $resultCode, 'Payment not found in database');
                // Always answer authenticated requests with 200 without leaking details
                return [
                    'status_code' => 200,
                    'response'    => ['ResultCode' => 0, 'ResultDesc' => 'Accepted'],
                ];
            }

            $orderId = (int)$payment['order_id'];

            // Idempotent check: if payment is already final
            if (in_array($payment['status'], ['success', 'cancelled', 'timeout'], true)) {
                $pdo->rollBack();
                self::logMpesaEvent('CALLBACK_ALREADY_FINAL', $checkoutRequestId, $resultCode, "Payment status already {$payment['status']}");
                return [
                    'status_code' => 200,
                    'response'    => ['ResultCode' => 0, 'ResultDesc' => 'Accepted'],
                ];
            }

            // Failure Callback (ResultCode != 0)
            if ($resultCode !== 0) {
                $status = match ($resultCode) {
                    1032 => 'cancelled',
                    1037 => 'timeout',
                    default => 'failed',
                };
                $friendlyDesc = self::mapResultCode($resultCode, $resultDesc);

                $uStmt = $pdo->prepare("
                    UPDATE payments 
                    SET status = :status, result_code = :code, result_desc = :desc, raw_callback = :raw, updated_at = NOW()
                    WHERE id = :id
                ");
                $uStmt->execute([
                    ':status' => $status,
                    ':code'   => $resultCode,
                    ':desc'   => $friendlyDesc,
                    ':raw'    => $rawBody,
                    ':id'     => $payment['id'],
                ]);

                AuditLogger::log(0, 'mpesa_callback_failed', 'payment', $payment['id'], [
                    'checkout_request_id' => $checkoutRequestId,
                    'result_code'         => $resultCode,
                    'outcome'             => $status,
                ], $clientIp);

                $pdo->commit();

                self::logMpesaEvent('CALLBACK_FAILED', $checkoutRequestId, $resultCode, $friendlyDesc);
                return [
                    'status_code' => 200,
                    'response'    => ['ResultCode' => 0, 'ResultDesc' => 'Accepted'],
                ];
            }

            // Success Callback (ResultCode === 0)
            $metadataItems = $stk['CallbackMetadata']['Item'] ?? [];
            $itemsMap = [];
            foreach ($metadataItems as $item) {
                if (isset($item['Name'])) {
                    $itemsMap[$item['Name']] = $item['Value'] ?? null;
                }
            }

            $callbackAmount = isset($itemsMap['Amount']) ? (float)$itemsMap['Amount'] : null;
            $mpesaReceipt = isset($itemsMap['MpesaReceiptNumber']) ? strtoupper(trim((string)$itemsMap['MpesaReceiptNumber'])) : '';

            // 1. Receipt format validation: ^[A-Z0-9]{10}$
            if ($mpesaReceipt === '' || !preg_match('/^[A-Z0-9]{10}$/', $mpesaReceipt)) {
                $reason = "Invalid M-Pesa receipt format: '{$mpesaReceipt}'";
                $pdo->prepare("
                    UPDATE payments 
                    SET status = 'failed', result_desc = :desc, raw_callback = :raw, updated_at = NOW()
                    WHERE id = :id
                ")->execute([':desc' => $reason, ':raw' => $rawBody, ':id' => $payment['id']]);

                $pdo->prepare("UPDATE orders SET attention_reason = :reason WHERE id = :oid")
                    ->execute([':reason' => $reason, ':oid' => $orderId]);

                AuditLogger::log(0, 'mpesa_callback_invalid_receipt', 'payment', $payment['id'], [
                    'checkout_request_id' => $checkoutRequestId,
                    'result_code'         => 0,
                    'outcome'             => 'invalid_receipt',
                ], $clientIp);

                $pdo->commit();
                MailerService::sendAdminAttentionAlert($orderId, $reason);
                self::logMpesaEvent('CALLBACK_INVALID_RECEIPT', $checkoutRequestId, 0, $reason);

                return [
                    'status_code' => 200,
                    'response'    => ['ResultCode' => 0, 'ResultDesc' => 'Accepted'],
                ];
            }

            // 2. Receipt uniqueness check: not used by any other payment
            $dupStmt = $pdo->prepare("SELECT id FROM payments WHERE mpesa_receipt = :rcpt AND id <> :id LIMIT 1");
            $dupStmt->execute([':rcpt' => $mpesaReceipt, ':id' => $payment['id']]);
            if ($dupStmt->fetch()) {
                $reason = "Duplicate M-Pesa receipt detected: '{$mpesaReceipt}'";
                $pdo->prepare("
                    UPDATE payments 
                    SET status = 'failed', result_desc = :desc, raw_callback = :raw, updated_at = NOW()
                    WHERE id = :id
                ")->execute([':desc' => $reason, ':raw' => $rawBody, ':id' => $payment['id']]);

                $pdo->prepare("UPDATE orders SET attention_reason = :reason WHERE id = :oid")
                    ->execute([':reason' => $reason, ':oid' => $orderId]);

                AuditLogger::log(0, 'mpesa_callback_duplicate_receipt', 'payment', $payment['id'], [
                    'checkout_request_id' => $checkoutRequestId,
                    'result_code'         => 0,
                    'outcome'             => 'duplicate_receipt',
                ], $clientIp);

                $pdo->commit();
                MailerService::sendAdminAttentionAlert($orderId, $reason);
                self::logMpesaEvent('CALLBACK_DUPLICATE_RECEIPT', $checkoutRequestId, 0, $reason);

                return [
                    'status_code' => 200,
                    'response'    => ['ResultCode' => 0, 'ResultDesc' => 'Accepted'],
                ];
            }

            // 3. Amount validation: Amount in metadata equals payment amount
            $expectedAmount = (float)$payment['amount'];
            if ($callbackAmount === null || abs($callbackAmount - $expectedAmount) >= 0.01) {
                $reason = "Callback amount mismatch: expected KES {$expectedAmount}, received KES " . ($callbackAmount ?? 'NULL');
                $pdo->prepare("
                    UPDATE payments 
                    SET status = 'failed', result_desc = :desc, raw_callback = :raw, updated_at = NOW()
                    WHERE id = :id
                ")->execute([':desc' => $reason, ':raw' => $rawBody, ':id' => $payment['id']]);

                $pdo->prepare("UPDATE orders SET attention_reason = :reason WHERE id = :oid")
                    ->execute([':reason' => $reason, ':oid' => $orderId]);

                AuditLogger::log(0, 'mpesa_callback_amount_mismatch', 'payment', $payment['id'], [
                    'checkout_request_id' => $checkoutRequestId,
                    'result_code'         => 0,
                    'outcome'             => 'amount_mismatch',
                ], $clientIp);

                $pdo->commit();
                MailerService::sendAdminAttentionAlert($orderId, $reason);
                self::logMpesaEvent('CALLBACK_AMOUNT_MISMATCH', $checkoutRequestId, 0, $reason);

                return [
                    'status_code' => 200,
                    'response'    => ['ResultCode' => 0, 'ResultDesc' => 'Accepted'],
                ];
            }

            // 4. STK Push Query Verification
            $verifyWithQuery = (bool)($cfg['verify_callback_with_query'] ?? true);
            if ($verifyWithQuery) {
                $queryResult = self::queryStkPush($checkoutRequestId);
                if (!$queryResult['success']) {
                    // Gateway query did not confirm success: keep in pending_verification
                    $pdo->prepare("
                        UPDATE payments 
                        SET status = 'pending_verification', result_desc = 'Callback received, pending STK query confirmation', updated_at = NOW()
                        WHERE id = :id
                    ")->execute([':id' => $payment['id']]);

                    AuditLogger::log(0, 'mpesa_callback_pending_verification', 'payment', $payment['id'], [
                        'checkout_request_id' => $checkoutRequestId,
                        'result_code'         => 0,
                        'outcome'             => 'pending_verification',
                    ], $clientIp);

                    $pdo->commit();
                    self::logMpesaEvent('CALLBACK_PENDING_VERIFICATION', $checkoutRequestId, 0, 'Query verification failed or pending');

                    return [
                        'status_code' => 200,
                        'response'    => ['ResultCode' => 0, 'ResultDesc' => 'Accepted'],
                    ];
                }
            }

            // Release lock before transitioning order
            $pdo->commit();

            // Order state update based on current order status
            if ($payment['order_status'] === 'pending_payment') {
                OrderService::transitionToPaid($orderId, $mpesaReceipt, $rawBody, $clientIp, (int)$payment['id']);
            } elseif (in_array($payment['order_status'], ['expired', 'cancelled'], true)) {
                OrderService::handleLateSuccessAfterExpiry($orderId, $mpesaReceipt, $rawBody, $clientIp);
            }

            self::logMpesaEvent('CALLBACK_SUCCESS_PROCESSED', $checkoutRequestId, 0, "Receipt {$mpesaReceipt} applied to Order #{$payment['order_number']}");

            return [
                'status_code' => 200,
                'response'    => ['ResultCode' => 0, 'ResultDesc' => 'Accepted'],
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            self::logMpesaEvent('CALLBACK_PROCESSING_ERROR', $checkoutRequestId, $resultCode, $e->getMessage());
            return [
                'status_code' => 200,
                'response'    => ['ResultCode' => 0, 'ResultDesc' => 'Accepted'],
            ];
        }
    }
}

