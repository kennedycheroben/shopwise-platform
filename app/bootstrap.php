<?php
/**
 * ShopWise Kenya - Core Application Bootstrap
 */

// 1. PSR-4 Compliant Autoloader for App\ namespace
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// 2. Load Optional Composer Autoloader
$composerAutoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

// 3. Load Helpers
require_once __DIR__ . '/src/Helpers/helpers.php';

// 4. Load Configuration
$appConfig = require __DIR__ . '/config/config.php';

// Set Application Timezone
date_default_timezone_set($appConfig['app']['timezone'] ?? 'Africa/Nairobi');

// 5. Central Error & Exception Handler
$logDir = __DIR__ . '/storage/logs';
$logFile = $logDir . '/app.log';
$logAvailable = false;

if (is_dir($logDir) || @mkdir($logDir, 0755, true)) {
    if (is_writable($logDir) && (!file_exists($logFile) || is_writable($logFile))) {
        $logAvailable = true;
    }
}

$environment = $appConfig['app']['environment'] ?? 'production';
$isDebug = !empty($appConfig['app']['debug']) && $environment === 'local';

if ($isDebug) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');
if ($logAvailable) {
    ini_set('error_log', $logFile);
}

set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline) use ($logAvailable, $logFile): bool {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    $timestamp = date('Y-m-d H:i:s T');
    $logMsg = "[{$timestamp}] PHP Error [{$errno}]: {$errstr} in {$errfile}:{$errline}\n";
    if ($logAvailable && @file_put_contents($logFile, $logMsg, FILE_APPEND | LOCK_EX) !== false) {
        // successfully logged to file
    } else {
        error_log($logMsg);
    }
    return false;
});

set_exception_handler(function (Throwable $e) use ($environment, $logAvailable, $logFile): void {
    $timestamp = date('Y-m-d H:i:s T');
    $logMsg = "[{$timestamp}] Uncaught Exception: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}\nStack Trace:\n{$e->getTraceAsString()}\n";
    if ($logAvailable && @file_put_contents($logFile, $logMsg, FILE_APPEND | LOCK_EX) !== false) {
        // successfully logged to file
    } else {
        error_log($logMsg);
    }

    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, "Error: " . $e->getMessage() . " (" . $e->getFile() . ":" . $e->getLine() . ")\n");
        exit(1);
    }

    if (!headers_sent()) {
        http_response_code(500);
    }

    if ($environment === 'local') {
        echo "<!DOCTYPE html><html><head><title>Application Exception</title>";
        echo "<style>body{font-family:sans-serif;padding:30px;background:#fef2f2;color:#991b1b}pre{background:#fff;padding:15px;border-radius:6px;overflow:auto;border:1px solid #fecaca}</style></head><body>";
        echo "<h2>Application Exception (Local Debug Mode)</h2>";
        echo "<p><strong>Message:</strong> " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</p>";
        echo "<p><strong>Location:</strong> " . htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8') . " : Line " . $e->getLine() . "</p>";
        echo "<h3>Stack Trace:</h3><pre>" . htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8') . "</pre>";
        echo "</body></html>";
    } else {
        echo "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><title>Service Temporarily Unavailable</title>";
        echo "<style>body{font-family:sans-serif;text-align:center;padding:80px 20px;background:#f9fafb;color:#1f2937}h1{font-size:2rem;margin-bottom:10px}p{color:#4b5563;max-width:500px;margin:0 auto 20px}</style></head><body>";
        echo "<h1>We'll be right back</h1>";
        echo "<p>We are currently experiencing a brief technical glitch. Our Nairobi support team has been notified. Please try again shortly or contact support@kennedycheroben.co.ke.</p>";
        echo "<a href='/' style='color:#2563eb;text-decoration:none;font-weight:bold;'>Return to Store</a>";
        echo "</body></html>";
    }
    exit(1);
});

// 6. Secure Session Initialization
if (session_status() === PHP_SESSION_NONE && php_sapi_name() !== 'cli') {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_cookies', '1');
    ini_set('session.use_only_cookies', '1');

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
               (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

// 7. Search Engine Indexing Protection for Non-Production Environments
if (php_sapi_name() !== 'cli' && !headers_sent()) {
    $currentEnv = $appConfig['app']['environment'] ?? 'production';
    if ($currentEnv !== 'production') {
        header('X-Robots-Tag: noindex, nofollow', false);
    }
}

