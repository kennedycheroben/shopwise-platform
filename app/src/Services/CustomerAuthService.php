<?php

namespace App\Services;

use App\Database\Database;
use PDO;

final class CustomerAuthService
{
    public const SESSION_KEY = 'customer';
    public const VERIFICATION_EXPIRY_HOURS = 24;
    public const RESET_EXPIRY_MINUTES = 60;

    /**
     * Get currently authenticated storefront customer with fresh DB re-validation
     */
    public static function user(): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $sessionUser = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($sessionUser) || empty($sessionUser['id'])) {
            return null;
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("
            SELECT id, role, first_name, last_name, email, phone, status,
                   google_id, avatar_url, email_verified_at, password_changed_at, created_at
            FROM users
            WHERE id = :id AND role = 'customer'
            LIMIT 1
        ");
        $stmt->execute([':id' => (int)$sessionUser['id']]);
        $fresh = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$fresh || $fresh['status'] !== 'active') {
            unset($_SESSION[self::SESSION_KEY]);
            return null;
        }

        // Invalidate session if password changed after this session was established
        if (!empty($fresh['password_changed_at']) && !empty($sessionUser['auth_time'])) {
            $changedTs = strtotime($fresh['password_changed_at']);
            if ($changedTs > (int)$sessionUser['auth_time']) {
                unset($_SESSION[self::SESSION_KEY]);
                return null;
            }
        }

        // Keep session data synchronized with database
        $fresh['auth_time'] = $sessionUser['auth_time'] ?? time();
        $_SESSION[self::SESSION_KEY] = $fresh;

        return $fresh;
    }

    /**
     * Require customer authentication for protected routes
     */
    public static function requireLogin(string $returnUrl = '/account'): array
    {
        $user = self::user();
        if (!$user) {
            $safeNext = self::sanitizeReturnUrl($returnUrl);
            header('Location: ' . url('login?next=' . urlencode($safeNext)));
            exit;
        }
        return $user;
    }

    /**
     * Normalize email address
     */
    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Check if password exists in the local common passwords list
     */
    public static function isPasswordCommon(string $password): bool
    {
        $file = dirname(__DIR__, 2) . '/data/common_passwords.txt';
        if (!file_exists($file)) {
            return false;
        }

        $lowerPwd = strtolower(trim($password));
        $handle = @fopen($file, 'r');
        if (!$handle) {
            return false;
        }

        $isCommon = false;
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line !== '' && strtolower($line) === $lowerPwd) {
                $isCommon = true;
                break;
            }
        }
        fclose($handle);

        return $isCommon;
    }

    /**
     * Validate customer password against the comprehensive security policy:
     * - Length 10 to 72 characters (bcrypt safe limit)
     * - At least one letter and at least one number
     * - Not in the 1,000+ common passwords list
     * - Does not contain the email local part
     */
    public static function validatePasswordPolicy(string $password, string $email): ?string
    {
        $len = strlen($password);
        if ($len < 10) {
            return 'Password must be at least 10 characters long.';
        }
        if ($len > 72) {
            return 'Password cannot exceed 72 characters.';
        }
        if (!preg_match('/[a-zA-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
            return 'Password must contain at least one letter and at least one number.';
        }

        // Email local-part check
        $localPart = strtolower(explode('@', $email)[0] ?? '');
        if ($localPart !== '' && strlen($localPart) >= 3 && str_contains(strtolower($password), $localPart)) {
            return 'Password cannot contain your email username.';
        }

        // Common password check
        if (self::isPasswordCommon($password)) {
            return 'This password is too common and easily guessed. Please choose a more unique password.';
        }

        return null;
    }

    /**
     * Register a new customer (enumeration-safe)
     */
    public static function register(array $data, string $ip): array
    {
        $pdo = Database::getInstance();

        $firstName = trim((string)($data['first_name'] ?? ''));
        $lastName  = trim((string)($data['last_name'] ?? ''));
        $email     = self::normalizeEmail((string)($data['email'] ?? ''));
        $phone     = trim((string)($data['phone'] ?? ''));
        $password  = (string)($data['password'] ?? '');
        $confirm   = (string)($data['confirm_password'] ?? $data['password_confirmation'] ?? '');
        $terms     = !empty($data['terms']);

        // Input validation
        if ($firstName === '' || $lastName === '') {
            return [false, 'Please provide both your first and last name.'];
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191) {
            return [false, 'Please provide a valid email address.'];
        }
        if (!$terms) {
            return [false, 'You must accept the Terms of Service and Privacy Policy to create an account.'];
        }
        if ($password !== $confirm) {
            return [false, 'The password confirmation does not match.'];
        }

        // Policy validation
        $policyError = self::validatePasswordPolicy($password, $email);
        if ($policyError !== null) {
            return [false, $policyError];
        }

        // Phone normalization (optional)
        $normalizedPhone = null;
        if ($phone !== '') {
            $normalizedPhone = normalize_phone($phone);
            if (!$normalizedPhone) {
                return [false, 'Please enter a valid Kenyan phone number (e.g. 0712 345 678 or +254 712 345 678).'];
            }
        }

        // Check for existing account (Enumeration-safe handling)
        $stmt = $pdo->prepare("SELECT id, role, email, status FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Enumeration safe: Queue a security notification email instead of leaking user existence
            $storeName = setting('store_name', 'ShopWise Kenya');
            $subject = "Attempted Registration on {$storeName}";
            $bodyHtml = "<p>Hello,</p><p>Someone recently attempted to create a new {$storeName} account using your email address (<strong>" . e($email) . "</strong>).</p><p>If this was you, you already have an account! You can <a href='" . e(url('login')) . "'>sign in here</a> or <a href='" . e(url('forgot-password')) . "'>reset your password</a>.</p><p>If you did not make this request, you can safely ignore this email.</p>";
            $bodyText = "Hello,\nSomeone recently attempted to create an account using your email address.\nIf this was you, sign in at " . url('login') . " or reset your password at " . url('forgot-password') . ".\nIf you did not request this, you can safely ignore this message.";
            
            MailerService::queue($email, $subject, $bodyHtml, $bodyText);

            return [true, 'An account confirmation link has been sent to your email address. Please check your inbox.'];
        }

        // Create new customer account
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $insert = $pdo->prepare("
            INSERT INTO users (role, first_name, last_name, email, phone, password_hash, status, password_changed_at, created_at, updated_at)
            VALUES ('customer', :first_name, :last_name, :email, :phone, :hash, 'active', NOW(), NOW(), NOW())
        ");
        $insert->execute([
            ':first_name' => $firstName,
            ':last_name'  => $lastName,
            ':email'      => $email,
            ':phone'      => $normalizedPhone,
            ':hash'       => $hash,
        ]);
        $newUserId = (int)$pdo->lastInsertId();

        // Generate email verification token (32 random bytes, store SHA-256 hash)
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);

        $pdo->prepare("
            INSERT INTO email_verifications (user_id, token_hash, expires_at, created_at)
            VALUES (:uid, :thash, DATE_ADD(NOW(), INTERVAL " . self::VERIFICATION_EXPIRY_HOURS . " HOUR), NOW())
        ")->execute([
            ':uid'   => $newUserId,
            ':thash' => $tokenHash,
        ]);

        // Queue verification email
        $verifyUrl = url('verify-email?token=' . $rawToken);
        $storeName = setting('store_name', 'ShopWise Kenya');
        $subject = "Verify your email address - {$storeName}";
        $bodyHtml = "<p>Hello " . e($firstName) . ",</p><p>Thank you for creating an account with {$storeName}! Please verify your email address by clicking the link below:</p><p><a href='" . e($verifyUrl) . "' style='display:inline-block;padding:10px 20px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px;'>Verify My Email</a></p><p>Or copy this link into your browser:<br>" . e($verifyUrl) . "</p><p>This link is valid for 24 hours.</p>";
        $bodyText = "Hello {$firstName},\nThank you for creating an account with {$storeName}!\nPlease verify your email address by visiting:\n{$verifyUrl}\n\nThis link is valid for 24 hours.";

        MailerService::queue($email, $subject, $bodyHtml, $bodyText, $firstName . ' ' . $lastName);

        // Audit log registration
        AuditLogger::log($newUserId, 'customer_register', 'user', $newUserId, [
            'email' => $email,
            'phone' => $normalizedPhone,
        ], $ip);

        // Auto-login customer
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION[self::SESSION_KEY] = [
            'id'                => $newUserId,
            'role'              => 'customer',
            'first_name'        => $firstName,
            'last_name'         => $lastName,
            'email'             => $email,
            'phone'             => $normalizedPhone,
            'status'            => 'active',
            'email_verified_at' => null,
            'auth_time'         => time(),
        ];

        // Merge guest cart if available
        $guestToken = $_COOKIE[CartService::COOKIE_NAME] ?? null;
        if (!empty($guestToken) && is_string($guestToken)) {
            $cartService = new CartService($pdo);
            $cartService->mergeGuestCart($newUserId, $guestToken);
        }

        return [true, 'Account created successfully! We sent a verification link to your email.'];
    }

    /**
     * Check login rate limits:
     * - 5 failures per 15 minutes per email + IP (identifier: customer:email)
     * - 20 failures per 15 minutes per IP
     */
    public static function checkLoginRateLimit(string $email, string $ip): ?string
    {
        $pdo = Database::getInstance();
        $ident = 'customer:' . $email;

        // 1. Dual limit: Email + IP (5 attempts)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM login_attempts
            WHERE identifier = :ident AND ip = :ip AND success = 0
              AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $stmt->execute([':ident' => $ident, ':ip' => $ip]);
        if ((int)$stmt->fetchColumn() >= 5) {
            return 'Too many failed login attempts for this account. Please wait 15 minutes before trying again.';
        }

        // 2. Dual limit: Global IP limit (20 attempts)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM login_attempts
            WHERE ip = :ip AND success = 0
              AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $stmt->execute([':ip' => $ip]);
        if ((int)$stmt->fetchColumn() >= 20) {
            return 'Too many failed login attempts from your network. Please wait 15 minutes before trying again.';
        }

        return null;
    }

    /**
     * Authenticate customer with email and password
     */
    public static function login(string $email, string $password, string $ip): array
    {
        $pdo = Database::getInstance();
        $email = self::normalizeEmail($email);
        $ident = 'customer:' . $email;

        // Rate limit check
        $rateLimitError = self::checkLoginRateLimit($email, $ip);
        if ($rateLimitError !== null) {
            return [false, $rateLimitError];
        }

        // Fetch customer record
        $stmt = $pdo->prepare("
            SELECT id, role, first_name, last_name, email, phone, password_hash,
                   google_id, avatar_url, status, email_verified_at, password_changed_at
            FROM users
            WHERE email = :email
            LIMIT 1
        ");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Constant-time mitigation against timing attacks for non-existent emails
        $dummyHash = '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234';
        $hashToVerify = ($user && !empty($user['password_hash'])) ? $user['password_hash'] : $dummyHash;
        $pwdMatch = password_verify($password, $hashToVerify);

        // Eligibility: must exist, role must be customer, status active, and password match
        $validCustomer = $user
            && $user['role'] === 'customer'
            && $user['status'] === 'active'
            && !empty($user['password_hash'])
            && $pwdMatch;

        // Log attempt
        $pdo->prepare("
            INSERT INTO login_attempts (identifier, ip, success, attempted_at)
            VALUES (:ident, :ip, :success, NOW())
        ")->execute([
            ':ident'   => $ident,
            ':ip'      => $ip,
            ':success' => $validCustomer ? 1 : 0,
        ]);

        if (!$validCustomer) {
            return [false, 'The provided credentials do not match our records.'];
        }

        // Establish session
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        unset($user['password_hash']);
        $user['auth_time'] = time();
        $_SESSION[self::SESSION_KEY] = $user;

        // Update last login
        $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = :id")->execute([':id' => $user['id']]);

        // Merge guest cart if available
        $guestToken = $_COOKIE[CartService::COOKIE_NAME] ?? null;
        if (!empty($guestToken) && is_string($guestToken)) {
            $cartService = new CartService($pdo);
            $cartService->mergeGuestCart((int)$user['id'], $guestToken);
        }

        return [true, ''];
    }

    /**
     * Terminate storefront customer session
     */
    public static function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * Verify email with single-use token
     */
    public static function verifyEmail(string $rawToken): array
    {
        $rawToken = trim($rawToken);
        if ($rawToken === '' || strlen($rawToken) < 32) {
            return [false, 'Invalid verification token.'];
        }

        $tokenHash = hash('sha256', $rawToken);
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare("
            SELECT id, user_id, expires_at, used_at
            FROM email_verifications
            WHERE token_hash = :thash
            LIMIT 1
        ");
        $stmt->execute([':thash' => $tokenHash]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            return [false, 'Invalid verification token.'];
        }

        if ($record['used_at'] !== null) {
            return [false, 'This verification link has already been used.'];
        }

        if (strtotime($record['expires_at']) < time()) {
            return [false, 'This verification link has expired. Please request a new verification link.'];
        }

        // Mark token used and update user email_verified_at
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE email_verifications SET used_at = NOW() WHERE id = :id")->execute([':id' => $record['id']]);
            $pdo->prepare("UPDATE users SET email_verified_at = NOW(), updated_at = NOW() WHERE id = :uid")->execute([':uid' => $record['user_id']]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return [false, 'Failed to complete email verification. Please try again.'];
        }

        // Update active session if the verified user is currently logged in
        if (!empty($_SESSION[self::SESSION_KEY]['id']) && (int)$_SESSION[self::SESSION_KEY]['id'] === (int)$record['user_id']) {
            $_SESSION[self::SESSION_KEY]['email_verified_at'] = date('Y-m-d H:i:s');
        }

        return [true, 'Your email address has been successfully verified!'];
    }

    /**
     * Resend verification email with rate limiting (max 3 per hour)
     */
    public static function resendVerification(string $email, string $ip): array
    {
        $email = self::normalizeEmail($email);
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare("
            SELECT id, first_name, last_name, email, email_verified_at
            FROM users
            WHERE email = :email AND role = 'customer'
            LIMIT 1
        ");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return [true, 'If your email is registered and unverified, a verification link has been sent.'];
        }

        if (!empty($user['email_verified_at'])) {
            return [false, 'Your email address is already verified.'];
        }

        // Rate limit: 3 resends per hour per user
        $rateStmt = $pdo->prepare("
            SELECT COUNT(*) FROM email_verifications
            WHERE user_id = :uid AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $rateStmt->execute([':uid' => $user['id']]);
        if ((int)$rateStmt->fetchColumn() >= 3) {
            return [false, 'Too many verification emails requested. Please wait an hour before trying again.'];
        }

        // Invalidate previous unused verification tokens
        $pdo->prepare("UPDATE email_verifications SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL")->execute([':uid' => $user['id']]);

        // Issue new token
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);

        $pdo->prepare("
            INSERT INTO email_verifications (user_id, token_hash, expires_at, created_at)
            VALUES (:uid, :thash, DATE_ADD(NOW(), INTERVAL " . self::VERIFICATION_EXPIRY_HOURS . " HOUR), NOW())
        ")->execute([
            ':uid'   => $user['id'],
            ':thash' => $tokenHash,
        ]);

        $verifyUrl = url('verify-email?token=' . $rawToken);
        $storeName = setting('store_name', 'ShopWise Kenya');
        $subject = "Verify your email address - {$storeName}";
        $bodyHtml = "<p>Hello " . e($user['first_name']) . ",</p><p>You requested a new verification link for your {$storeName} account. Please verify by clicking below:</p><p><a href='" . e($verifyUrl) . "' style='display:inline-block;padding:10px 20px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px;'>Verify My Email</a></p><p>Or copy this link:<br>" . e($verifyUrl) . "</p><p>This link is valid for 24 hours.</p>";
        $bodyText = "Hello {$user['first_name']},\nPlease verify your email address by visiting:\n{$verifyUrl}\n\nThis link is valid for 24 hours.";

        MailerService::queue($email, $subject, $bodyHtml, $bodyText, $user['first_name'] . ' ' . $user['last_name']);

        return [true, 'A new verification link has been sent to your email address.'];
    }

    /**
     * Request password reset link (enumeration-safe, rate limited: 5/hr per email & IP)
     */
    public static function requestPasswordReset(string $email, string $ip): array
    {
        $email = self::normalizeEmail($email);
        $pdo = Database::getInstance();

        // Rate limit: 5 requests per hour per IP
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM password_resets
            WHERE ip = :ip AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute([':ip' => $ip]);
        if ((int)$stmt->fetchColumn() >= 5) {
            return [false, 'Too many password reset requests from this network. Please wait an hour before trying again.'];
        }

        $stmt = $pdo->prepare("
            SELECT id, first_name, last_name, email, role, status
            FROM users
            WHERE email = :email AND role = 'customer'
            LIMIT 1
        ");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Always return generic success message
        $genericSuccess = 'If an account exists with that email address, instructions to reset your password have been sent.';

        if (!$user || $user['status'] !== 'active') {
            return [true, $genericSuccess];
        }

        // Rate limit: 5 requests per hour per user
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM password_resets
            WHERE user_id = :uid AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute([':uid' => $user['id']]);
        if ((int)$stmt->fetchColumn() >= 5) {
            return [true, $genericSuccess]; // Still enumeration-safe
        }

        // Invalidate older unused reset tokens for this user
        $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL")->execute([':uid' => $user['id']]);

        // Generate 32-byte reset token
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);

        $pdo->prepare("
            INSERT INTO password_resets (user_id, token_hash, expires_at, ip, created_at)
            VALUES (:uid, :thash, DATE_ADD(NOW(), INTERVAL " . self::RESET_EXPIRY_MINUTES . " MINUTE), :ip, NOW())
        ")->execute([
            ':uid'   => $user['id'],
            ':thash' => $tokenHash,
            ':ip'    => $ip,
        ]);

        $resetUrl = url('reset-password?token=' . $rawToken);
        $storeName = setting('store_name', 'ShopWise Kenya');
        $subject = "Reset your password - {$storeName}";
        $bodyHtml = "<p>Hello " . e($user['first_name']) . ",</p><p>We received a request to reset your password for {$storeName}. Click the button below to choose a new password:</p><p><a href='" . e($resetUrl) . "' style='display:inline-block;padding:10px 20px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px;'>Reset Password</a></p><p>Or copy this link:<br>" . e($resetUrl) . "</p><p>This link expires in 60 minutes. If you did not make this request, you can safely ignore this email.</p>";
        $bodyText = "Hello {$user['first_name']},\nWe received a request to reset your password for {$storeName}.\nVisit: {$resetUrl}\nThis link expires in 60 minutes.";

        MailerService::queue($email, $subject, $bodyHtml, $bodyText, $user['first_name'] . ' ' . $user['last_name']);

        return [true, $genericSuccess];
    }

    /**
     * Reset password using single-use reset token
     */
    public static function resetPassword(string $rawToken, string $newPassword, string $ip): array
    {
        $rawToken = trim($rawToken);
        if ($rawToken === '' || strlen($rawToken) < 32) {
            return [false, 'Invalid or expired password reset link.'];
        }

        $tokenHash = hash('sha256', $rawToken);
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare("
            SELECT pr.id, pr.user_id, pr.expires_at, pr.used_at, u.email, u.first_name, u.role
            FROM password_resets pr
            JOIN users u ON u.id = pr.user_id
            WHERE pr.token_hash = :thash
            LIMIT 1
        ");
        $stmt->execute([':thash' => $tokenHash]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            return [false, 'Invalid or expired password reset link.'];
        }

        if ($record['used_at'] !== null) {
            return [false, 'This password reset link has already been used. Please request a new one.'];
        }

        if (strtotime($record['expires_at']) < time()) {
            return [false, 'This password reset link has expired. Please request a new one.'];
        }

        // Validate new password policy
        $policyError = self::validatePasswordPolicy($newPassword, $record['email']);
        if ($policyError !== null) {
            return [false, $policyError];
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

        // Update user, mark token used, invalidate other tokens and sessions
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = :id")->execute([':id' => $record['id']]);

            // Invalidate any other outstanding reset or verification tokens for this user
            $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL")->execute([':uid' => $record['user_id']]);
            $pdo->prepare("UPDATE email_verifications SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL")->execute([':uid' => $record['user_id']]);

            // Set new password hash and update password_changed_at (invalidates existing sessions)
            $pdo->prepare("
                UPDATE users
                SET password_hash = :hash, password_changed_at = NOW(), updated_at = NOW()
                WHERE id = :uid
            ")->execute([
                ':hash' => $newHash,
                ':uid'  => $record['user_id'],
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return [false, 'Failed to update password. Please try again.'];
        }

        // Audit log event
        AuditLogger::log((int)$record['user_id'], 'customer_password_reset', 'user', (int)$record['user_id'], [
            'email' => $record['email'],
        ], $ip);

        // Queue security notification email
        $storeName = setting('store_name', 'ShopWise Kenya');
        $subject = "Your password has been changed - {$storeName}";
        $bodyHtml = "<p>Hello " . e($record['first_name']) . ",</p><p>The password for your {$storeName} account was successfully changed. All other active sessions have been signed out.</p><p>If you did not perform this change, please contact customer support immediately at " . e(setting('support_email', 'support@shopwise.co.ke')) . ".</p>";
        $bodyText = "Hello {$record['first_name']},\nThe password for your {$storeName} account was successfully changed.\nIf you did not authorize this, contact support immediately.";

        MailerService::queue($record['email'], $subject, $bodyHtml, $bodyText);

        return [true, 'Your password has been reset successfully. You may now sign in with your new password.'];
    }

    /**
     * Change password for logged-in customer (requires current password if password exists)
     */
    public static function changePassword(int $userId, string $currentPassword, string $newPassword, string $ip): array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT id, email, password_hash FROM users WHERE id = :id AND role = 'customer' LIMIT 1");
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return [false, 'Customer account not found.'];
        }

        // If account has an existing password, verify current password
        if (!empty($user['password_hash'])) {
            if ($currentPassword === '' || !password_verify($currentPassword, $user['password_hash'])) {
                return [false, 'The current password you entered is incorrect.'];
            }
        }

        // Validate new password policy
        $policyError = self::validatePasswordPolicy($newPassword, $user['email']);
        if ($policyError !== null) {
            return [false, $policyError];
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                UPDATE users
                SET password_hash = :hash, password_changed_at = NOW(), updated_at = NOW()
                WHERE id = :id
            ")->execute([
                ':hash' => $newHash,
                ':id'   => $userId,
            ]);

            // Invalidate outstanding password reset tokens
            $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL")->execute([':uid' => $userId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return [false, 'Failed to update password. Please try again.'];
        }

        // Update current session's auth_time so the current user is NOT logged out
        if (isset($_SESSION[self::SESSION_KEY]) && (int)$_SESSION[self::SESSION_KEY]['id'] === $userId) {
            $_SESSION[self::SESSION_KEY]['auth_time'] = time() + 5;
        }

        // Audit log event
        AuditLogger::log($userId, 'customer_password_change', 'user', $userId, [
            'email' => $user['email'],
        ], $ip);

        // Queue notification email
        $storeName = setting('store_name', 'ShopWise Kenya');
        $subject = "Your password has been updated - {$storeName}";
        $bodyHtml = "<p>Hello,</p><p>Your password for {$storeName} was updated successfully. If you did not make this change, please contact customer support immediately.</p>";
        $bodyText = "Hello,\nYour password for {$storeName} was updated successfully.\nIf you did not make this change, contact support.";

        MailerService::queue($user['email'], $subject, $bodyHtml, $bodyText);

        return [true, 'Password updated successfully!'];
    }

    /**
     * Update customer profile details (first name, last name, phone, email)
     */
    public static function updateProfile(int $userId, array $data, string $ip): array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT id, first_name, last_name, email, phone FROM users WHERE id = :id AND role = 'customer' LIMIT 1");
        $stmt->execute([':id' => $userId]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$current) {
            return [false, 'Customer account not found.'];
        }

        $firstName = trim((string)($data['first_name'] ?? $current['first_name']));
        $lastName  = trim((string)($data['last_name'] ?? $current['last_name']));
        $email     = self::normalizeEmail((string)($data['email'] ?? $current['email']));
        $phone     = trim((string)($data['phone'] ?? ''));

        if ($firstName === '' || $lastName === '') {
            return [false, 'First and last name cannot be empty.'];
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191) {
            return [false, 'Please provide a valid email address.'];
        }

        $normalizedPhone = null;
        if ($phone !== '') {
            $normalizedPhone = normalize_phone($phone);
            if (!$normalizedPhone) {
                return [false, 'Please provide a valid Kenyan phone number (e.g. 0712 345 678).'];
            }
        }

        $emailChanged = ($email !== $current['email']);

        if ($emailChanged) {
            // Check if email already used by someone else
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1");
            $checkStmt->execute([':email' => $email, ':id' => $userId]);
            if ($checkStmt->fetch()) {
                return [false, 'That email address is already in use by another account.'];
            }
        }

        $pdo->beginTransaction();
        try {
            if ($emailChanged) {
                // Updating email resets email verification
                $update = $pdo->prepare("
                    UPDATE users
                    SET first_name = :fn, last_name = :ln, email = :email, phone = :phone,
                        email_verified_at = NULL, updated_at = NOW()
                    WHERE id = :id
                ");
                $update->execute([
                    ':fn'    => $firstName,
                    ':ln'    => $lastName,
                    ':email' => $email,
                    ':phone' => $normalizedPhone,
                    ':id'    => $userId,
                ]);

                // Invalidate old verification tokens
                $pdo->prepare("UPDATE email_verifications SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL")->execute([':uid' => $userId]);

                // Generate new verification token
                $rawToken = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $rawToken);
                $pdo->prepare("
                    INSERT INTO email_verifications (user_id, token_hash, expires_at, created_at)
                    VALUES (:uid, :thash, DATE_ADD(NOW(), INTERVAL " . self::VERIFICATION_EXPIRY_HOURS . " HOUR), NOW())
                ")->execute([
                    ':uid'   => $userId,
                    ':thash' => $tokenHash,
                ]);

                $storeName = setting('store_name', 'ShopWise Kenya');

                // 1. Send verification link to new email
                $verifyUrl = url('verify-email?token=' . $rawToken);
                $subject = "Verify your new email address - {$storeName}";
                $bodyHtml = "<p>Hello " . e($firstName) . ",</p><p>You recently updated the email address for your {$storeName} account. Please verify this email address by clicking below:</p><p><a href='" . e($verifyUrl) . "' style='display:inline-block;padding:10px 20px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px;'>Verify Email</a></p><p>This link is valid for 24 hours.</p>";
                $bodyText = "Hello {$firstName},\nPlease verify your updated email address by visiting:\n{$verifyUrl}\n\nThis link is valid for 24 hours.";
                MailerService::queue($email, $subject, $bodyHtml, $bodyText);

                // 2. Send security notice to old email
                $noticeSubject = "Your account email address was changed - {$storeName}";
                $noticeHtml = "<p>Hello,</p><p>The email address associated with your {$storeName} account was changed from this address to <strong>" . e($email) . "</strong>.</p><p>If you did not authorize this change, please contact support immediately at " . e(setting('support_email', 'support@shopwise.co.ke')) . ".</p>";
                $noticeText = "Hello,\nYour account email was changed to {$email}.\nIf you did not authorize this, contact support immediately.";
                MailerService::queue($current['email'], $noticeSubject, $noticeHtml, $noticeText);

                // Audit log email change
                AuditLogger::log($userId, 'customer_email_change', 'user', $userId, [
                    'old_email' => $current['email'],
                    'new_email' => $email,
                ], $ip);
            } else {
                $update = $pdo->prepare("
                    UPDATE users
                    SET first_name = :fn, last_name = :ln, phone = :phone, updated_at = NOW()
                    WHERE id = :id
                ");
                $update->execute([
                    ':fn'    => $firstName,
                    ':ln'    => $lastName,
                    ':phone' => $normalizedPhone,
                    ':id'    => $userId,
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return [false, 'Failed to update profile. Please try again.'];
        }

        // Update active session
        if (isset($_SESSION[self::SESSION_KEY]) && (int)$_SESSION[self::SESSION_KEY]['id'] === $userId) {
            $_SESSION[self::SESSION_KEY]['first_name'] = $firstName;
            $_SESSION[self::SESSION_KEY]['last_name']  = $lastName;
            $_SESSION[self::SESSION_KEY]['email']      = $email;
            $_SESSION[self::SESSION_KEY]['phone']      = $normalizedPhone;
            if ($emailChanged) {
                $_SESSION[self::SESSION_KEY]['email_verified_at'] = null;
            }
        }

        $msg = $emailChanged
            ? 'Profile updated! A verification link has been sent to your new email address.'
            : 'Profile updated successfully!';

        return [true, $msg];
    }

    /**
     * Sanitize return URL to prevent open redirects:
     * - Must begin with a single slash '/'
     * - Must NOT begin with '//' or '/\'
     * - Must NOT contain ':' or control characters
     */
    public static function sanitizeReturnUrl(?string $url, string $default = '/account'): string
    {
        if (empty($url) || !is_string($url)) {
            return $default;
        }

        $trimmed = trim($url);

        // Reject protocol-relative or absolute external URLs
        if (!str_starts_with($trimmed, '/') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/\\')) {
            return $default;
        }

        // Reject any scheme injection (e.g. javascript:, data:, http:)
        if (str_contains($trimmed, ':')) {
            return $default;
        }

        // Ensure path starts with valid storefront path
        return $trimmed;
    }
}
