<?php

namespace App\Services;

use App\Database\Database;
use LogicException;
use PDO;
use RuntimeException;

final class GoogleAuthService
{
    private static ?HttpClientInterface $httpClient = null;
    private static ?array $jwksCache = null;
    private static int $jwksCacheTime = 0;

    public const STATE_SESSION_KEY = 'google_oauth';
    public const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    public const JWKS_ENDPOINT = 'https://www.googleapis.com/oauth2/v3/certs';
    public const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const STATE_EXPIRY_SECONDS = 600; // 10 minutes

    /**
     * Check if Google OAuth credentials are configured
     */
    public static function isConfigured(): bool
    {
        return !empty(self::getClientId()) && !empty(self::getClientSecret());
    }

    public static function getClientId(): string
    {
        $id = setting('google_client_id');
        if (!empty($id)) {
            return (string)$id;
        }
        $config = require dirname(__DIR__, 2) . '/config/config.php';
        return (string)($config['google']['client_id'] ?? '');
    }

    public static function getClientSecret(): string
    {
        $secret = setting('google_client_secret');
        if (!empty($secret)) {
            return (string)$secret;
        }
        $config = require dirname(__DIR__, 2) . '/config/config.php';
        return (string)($config['google']['client_secret'] ?? '');
    }

    public static function getRedirectUri(): string
    {
        return url('auth/google/callback');
    }

    /**
     * Testing seam: inject an HTTP client stub only in testing mode
     */
    public static function setHttpClient(HttpClientInterface $client): void
    {
        if (!defined('SHOPWISE_TESTING_MODE') || !SHOPWISE_TESTING_MODE) {
            throw new LogicException('HttpClient injection is strictly forbidden outside of testing mode.');
        }
        self::$httpClient = $client;
    }

    public static function resetHttpClient(): void
    {
        self::$httpClient = null;
        self::$jwksCache = null;
        self::$jwksCacheTime = 0;
    }

    public static function getHttpClient(): HttpClientInterface
    {
        if (self::$httpClient !== null) {
            return self::$httpClient;
        }
        return new CurlHttpClient(10);
    }

    /**
     * Base64Url encode utility
     */
    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64Url decode utility
     */
    public static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }

    /**
     * Generate Google authorization URL with PKCE (S256), state, and nonce
     */
    public static function generateAuthUrl(string $returnUrl = '/account'): string
    {
        if (!self::isConfigured()) {
            throw new RuntimeException('Google sign-in is not configured with client credentials.');
        }

        $state = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));
        $codeVerifier = self::base64UrlEncode(random_bytes(64));
        $codeChallenge = self::base64UrlEncode(hash('sha256', $codeVerifier, true));

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION[self::STATE_SESSION_KEY] = [
            'state'         => $state,
            'nonce'         => $nonce,
            'code_verifier' => $codeVerifier,
            'return_url'    => CustomerAuthService::sanitizeReturnUrl($returnUrl),
            'created_at'    => time(),
        ];

        $params = [
            'client_id'             => self::getClientId(),
            'redirect_uri'          => self::getRedirectUri(),
            'response_type'         => 'code',
            'scope'                 => 'openid email profile',
            'state'                 => $state,
            'nonce'                 => $nonce,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
            'prompt'                => 'select_account',
        ];

        return self::AUTH_ENDPOINT . '?' . http_build_query($params);
    }

    /**
     * Convert an RSA JWK (n, e) to an OpenSSL-compatible PEM public key
     */
    public static function jwkToPem(array $jwk): ?string
    {
        if (($jwk['kty'] ?? '') !== 'RSA' || empty($jwk['n']) || empty($jwk['e'])) {
            return null;
        }

        $n = self::base64UrlDecode($jwk['n']);
        $e = self::base64UrlDecode($jwk['e']);

        $encodeLength = function (int $len): string {
            if ($len <= 0x7f) {
                return chr($len);
            }
            $bytes = '';
            while ($len > 0) {
                $bytes = chr($len & 0xff) . $bytes;
                $len >>= 8;
            }
            return chr(0x80 | strlen($bytes)) . $bytes;
        };

        $encodeInt = function (string $bytes) use ($encodeLength): string {
            if (ord($bytes[0]) > 0x7f) {
                $bytes = "\x00" . $bytes;
            }
            return "\x02" . $encodeLength(strlen($bytes)) . $bytes;
        };

        $modulus = $encodeInt($n);
        $exponent = $encodeInt($e);
        $rsaKey = "\x30" . $encodeLength(strlen($modulus . $exponent)) . $modulus . $exponent;
        $algId = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $bitString = "\x03" . $encodeLength(strlen($rsaKey) + 1) . "\x00" . $rsaKey;
        $seq = "\x30" . $encodeLength(strlen($algId . $bitString)) . $algId . $bitString;

        return "-----BEGIN PUBLIC KEY-----\r\n" . chunk_split(base64_encode($seq), 64, "\r\n") . "-----END PUBLIC KEY-----\r\n";
    }

    /**
     * Fetch Google JWKS public keys with caching
     */
    public static function getJwks(): array
    {
        $now = time();
        if (self::$jwksCache !== null && ($now - self::$jwksCacheTime) < 3600) {
            return self::$jwksCache;
        }

        $client = self::getHttpClient();
        $res = $client->get(self::JWKS_ENDPOINT);
        if ($res['status'] !== 200 || empty($res['body'])) {
            throw new RuntimeException("Failed to fetch Google JWKS keys (HTTP {$res['status']}).");
        }

        $data = json_decode($res['body'], true);
        if (!is_array($data) || empty($data['keys'])) {
            throw new RuntimeException("Invalid JWKS JSON received from Google.");
        }

        self::$jwksCache = $data['keys'];
        self::$jwksCacheTime = $now;

        return self::$jwksCache;
    }

    /**
     * Verify Google ID token and return decoded claims
     */
    public static function verifyIdToken(string $idToken, string $expectedNonce): array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed ID token: must contain 3 segments.');
        }

        [$rawHeader, $rawPayload, $rawSignature] = $parts;
        $header = json_decode(self::base64UrlDecode($rawHeader), true);
        $payload = json_decode(self::base64UrlDecode($rawPayload), true);
        $signature = self::base64UrlDecode($rawSignature);

        if (!is_array($header) || !is_array($payload)) {
            throw new RuntimeException('Malformed ID token JSON.');
        }

        // Verify header
        if (($header['alg'] ?? '') !== 'RS256') {
            throw new RuntimeException('Unsupported ID token algorithm: expected RS256.');
        }
        $kid = $header['kid'] ?? '';
        if ($kid === '') {
            throw new RuntimeException('ID token header missing key ID (kid).');
        }

        // Find corresponding public key in JWKS
        $keys = self::getJwks();
        $matchedKey = null;
        foreach ($keys as $k) {
            if (($k['kid'] ?? '') === $kid) {
                $matchedKey = $k;
                break;
            }
        }

        if (!$matchedKey) {
            throw new RuntimeException("Public key with kid '{$kid}' not found in Google JWKS.");
        }

        $pem = self::jwkToPem($matchedKey);
        if (!$pem) {
            throw new RuntimeException("Failed to construct PEM public key for kid '{$kid}'.");
        }

        // Verify RS256 signature
        $dataToVerify = "{$rawHeader}.{$rawPayload}";
        $pubKey = openssl_pkey_get_public($pem);
        if (!$pubKey) {
            throw new RuntimeException("Invalid PEM public key generated.");
        }

        $verified = openssl_verify($dataToVerify, $signature, $pubKey, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            throw new RuntimeException('ID token signature verification failed.');
        }

        // Verify claims
        $iss = $payload['iss'] ?? '';
        if ($iss !== 'https://accounts.google.com' && $iss !== 'accounts.google.com') {
            throw new RuntimeException("Invalid token issuer '{$iss}'.");
        }

        $aud = $payload['aud'] ?? '';
        if ($aud !== self::getClientId()) {
            throw new RuntimeException("Audience mismatch: expected '" . self::getClientId() . "', got '{$aud}'.");
        }

        $now = time();
        $exp = (int)($payload['exp'] ?? 0);
        if ($exp < ($now - 60)) { // 60s clock skew leeway
            throw new RuntimeException("ID token has expired (exp: {$exp}, now: {$now}).");
        }

        $tokenNonce = (string)($payload['nonce'] ?? '');
        if ($tokenNonce !== $expectedNonce) {
            throw new RuntimeException("Token nonce mismatch.");
        }

        $sub = (string)($payload['sub'] ?? '');
        if ($sub === '') {
            throw new RuntimeException("ID token missing subject (sub).");
        }

        $email = CustomerAuthService::normalizeEmail((string)($payload['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException("ID token missing or invalid email address.");
        }

        if (empty($payload['email_verified'])) {
            throw new RuntimeException("Google account email is not verified.");
        }

        return $payload;
    }

    /**
     * Handle OAuth Callback from Google
     */
    public static function handleCallback(array $queryParams, string $ip): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $savedSession = $_SESSION[self::STATE_SESSION_KEY] ?? null;
        unset($_SESSION[self::STATE_SESSION_KEY]);

        if (!is_array($savedSession)) {
            return [false, 'Sign-in session expired or invalid. Please try again.'];
        }

        if (!empty($savedSession['expires_at']) && time() > (int)$savedSession['expires_at']) {
            return [false, 'Google sign-in attempt expired. Please try again.'];
        }
        if (!empty($savedSession['created_at']) && (time() - (int)$savedSession['created_at']) > self::STATE_EXPIRY_SECONDS) {
            return [false, 'Google sign-in attempt expired. Please try again.'];
        }

        $incomingState = (string)($queryParams['state'] ?? '');
        if ($incomingState === '' || !hash_equals($savedSession['state'], $incomingState)) {
            return [false, 'Invalid state parameter. Sign-in request could not be verified.'];
        }

        $error = $queryParams['error'] ?? null;
        if (!empty($error)) {
            return [false, 'Google sign-in was canceled or declined.'];
        }

        $code = (string)($queryParams['code'] ?? '');
        if ($code === '') {
            return [false, 'Missing authorization code from Google.'];
        }

        // Exchange code for tokens
        $client = self::getHttpClient();
        $postData = [
            'code'          => $code,
            'client_id'     => self::getClientId(),
            'client_secret' => self::getClientSecret(),
            'redirect_uri'  => self::getRedirectUri(),
            'grant_type'    => 'authorization_code',
            'code_verifier' => $savedSession['code_verifier'],
        ];

        try {
            $response = $client->post(self::TOKEN_ENDPOINT, $postData, [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept'       => 'application/json',
            ]);
        } catch (\Throwable $e) {
            return [false, 'Network connection to Google token service failed: ' . $e->getMessage()];
        }

        if ($response['status'] !== 200) {
            return [false, "Google token exchange failed (HTTP {$response['status']})."];
        }

        $tokenData = json_decode($response['body'], true);
        if (!is_array($tokenData) || empty($tokenData['id_token'])) {
            return [false, 'Google did not return an ID token.'];
        }

        // Verify ID token
        try {
            $claims = self::verifyIdToken($tokenData['id_token'], $savedSession['nonce']);
        } catch (\Throwable $e) {
            return [false, 'Google ID token verification failed: ' . $e->getMessage()];
        }

        $googleId = (string)$claims['sub'];
        $email = CustomerAuthService::normalizeEmail((string)$claims['email']);
        $firstName = trim((string)($claims['given_name'] ?? ''));
        $lastName = trim((string)($claims['family_name'] ?? ''));

        if ($firstName === '' && !empty($claims['name'])) {
            $nameParts = explode(' ', trim((string)$claims['name']), 2);
            $firstName = $nameParts[0];
            $lastName = $nameParts[1] ?? 'Customer';
        }
        if ($firstName === '') {
            $firstName = 'Google';
            $lastName = 'User';
        }

        $avatarUrl = !empty($claims['picture']) && filter_var($claims['picture'], FILTER_VALIDATE_URL) ? (string)$claims['picture'] : null;

        $pdo = Database::getInstance();

        // 1. Check if user already exists with this google_id
        $stmt = $pdo->prepare("
            SELECT id, role, first_name, last_name, email, phone, status, google_id, avatar_url, email_verified_at
            FROM users
            WHERE google_id = :gid
            LIMIT 1
        ");
        $stmt->execute([':gid' => $googleId]);
        $existingByGoogle = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingByGoogle) {
            // Role guard: Administrative or staff accounts CANNOT authenticate through storefront Google OAuth
            if ($existingByGoogle['role'] !== 'customer') {
                return [false, 'Administrative and staff accounts cannot sign in through the storefront customer portal.'];
            }
            if ($existingByGoogle['status'] !== 'active') {
                return [false, 'Your account is currently inactive or suspended. Please contact support.'];
            }

            // Update last login
            $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = :id")->execute([':id' => $existingByGoogle['id']]);

            // Establish customer session
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            $existingByGoogle['auth_time'] = time();
            $_SESSION[CustomerAuthService::SESSION_KEY] = $existingByGoogle;

            // Merge guest cart if available
            $guestToken = $_COOKIE[CartService::COOKIE_NAME] ?? null;
            if (!empty($guestToken) && is_string($guestToken)) {
                $cartService = new CartService($pdo);
                $cartService->mergeGuestCart((int)$existingByGoogle['id'], $guestToken);
            }

            AuditLogger::log((int)$existingByGoogle['id'], 'customer_login_google', 'user', (int)$existingByGoogle['id'], [
                'email' => $email,
            ], $ip);

            return [true, 'Welcome back!', $existingByGoogle, $savedSession['return_url'] ?? '/account'];
        }

        // 2. Check if user exists with this email address
        $stmt = $pdo->prepare("
            SELECT id, role, first_name, last_name, email, phone, status, google_id, avatar_url, email_verified_at, password_hash
            FROM users
            WHERE email = :email
            LIMIT 1
        ");
        $stmt->execute([':email' => $email]);
        $existingByEmail = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingByEmail) {
            if ($existingByEmail['role'] !== 'customer') {
                return [false, 'Administrative and staff accounts cannot sign in through the storefront customer portal.'];
            }
            if ($existingByEmail['status'] !== 'active') {
                return [false, 'Your account is currently inactive or suspended. Please contact support.'];
            }

            $userId = (int)$existingByEmail['id'];
            $wasUnverified = empty($existingByEmail['email_verified_at']);

            $pdo->beginTransaction();
            try {
                if ($wasUnverified) {
                    // Pre-registration account takeover prevention:
                    // If existing account was not verified, wipe password hash, invalidate tokens and sessions
                    $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL")->execute([':uid' => $userId]);
                    $pdo->prepare("UPDATE email_verifications SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL")->execute([':uid' => $userId]);

                    $pdo->prepare("
                        UPDATE users
                        SET google_id = :gid,
                            avatar_url = COALESCE(avatar_url, :avatar),
                            password_hash = NULL,
                            password_changed_at = NOW(),
                            email_verified_at = NOW(),
                            last_login_at = NOW(),
                            updated_at = NOW()
                        WHERE id = :id
                    ")->execute([
                        ':gid'    => $googleId,
                        ':avatar' => $avatarUrl,
                        ':id'     => $userId,
                    ]);
                } else {
                    $pdo->prepare("
                        UPDATE users
                        SET google_id = :gid,
                            avatar_url = COALESCE(avatar_url, :avatar),
                            last_login_at = NOW(),
                            updated_at = NOW()
                        WHERE id = :id
                    ")->execute([
                        ':gid'    => $googleId,
                        ':avatar' => $avatarUrl,
                        ':id'     => $userId,
                    ]);
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return [false, 'Failed to link Google account: ' . $e->getMessage()];
            }

            $existingByEmail['google_id'] = $googleId;
            if ($wasUnverified) {
                $existingByEmail['password_hash'] = null;
                $existingByEmail['email_verified_at'] = date('Y-m-d H:i:s');
            }

            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            $existingByEmail['auth_time'] = time() + 5;
            $_SESSION[CustomerAuthService::SESSION_KEY] = $existingByEmail;

            $guestToken = $_COOKIE[CartService::COOKIE_NAME] ?? null;
            if (!empty($guestToken) && is_string($guestToken)) {
                $cartService = new CartService($pdo);
                $cartService->mergeGuestCart($userId, $guestToken);
            }

            AuditLogger::log($userId, 'customer_linked_google', 'user', $userId, [
                'email' => $email,
            ], $ip);

            return [true, 'Your Google account has been linked successfully.', $existingByEmail, $savedSession['return_url'] ?? '/account'];
        }

        // 3. New customer account creation
        $insertStmt = $pdo->prepare("
            INSERT INTO users (role, first_name, last_name, email, google_id, avatar_url, status, email_verified_at, last_login_at, created_at, updated_at)
            VALUES ('customer', :first, :last, :email, :gid, :avatar, 'active', NOW(), NOW(), NOW(), NOW())
        ");
        $insertStmt->execute([
            ':first'  => $firstName,
            ':last'   => $lastName,
            ':email'  => $email,
            ':gid'    => $googleId,
            ':avatar' => $avatarUrl,
        ]);
        $newId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("
            SELECT id, role, first_name, last_name, email, phone, status, google_id, avatar_url, email_verified_at, created_at
            FROM users
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $newId]);
        $newUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $newUser['auth_time'] = time();
        $_SESSION[CustomerAuthService::SESSION_KEY] = $newUser;

        $guestToken = $_COOKIE[CartService::COOKIE_NAME] ?? null;
        if (!empty($guestToken) && is_string($guestToken)) {
            $cartService = new CartService($pdo);
            $cartService->mergeGuestCart($newId, $guestToken);
        }

        AuditLogger::log($newId, 'customer_registered_google', 'user', $newId, [
            'email' => $email,
        ], $ip);

        return [true, 'Account created successfully with Google.', $newUser, $savedSession['return_url'] ?? '/account'];
    }
}
