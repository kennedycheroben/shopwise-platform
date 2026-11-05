<?php
declare(strict_types=1);

namespace App\Config;

use RuntimeException;

/**
 * ShopWise Kenya - Centralized Environment Configuration Loader
 *
 * Responsibilities:
 * 1. Discover and parse .env files locally and in production (e.g., cPanel private directories).
 * 2. Support quoted (single/double) and unquoted values, inline comments, and whitespace.
 * 3. Respect existing hosting environment variables without overwriting them.
 * 4. Populate getenv(), $_ENV, and $_SERVER consistently.
 * 5. Provide typed retrieval with safe defaults and production security guards.
 * 6. Never expose secret values in debug output or stack traces.
 */
final class Env
{
    private static array $variables = [];
    private static bool $loaded = false;
    private static ?string $loadedFilePath = null;

    /**
     * Load environment variables from .env file.
     *
     * @param string|null $customPath Explicit path to .env file, or null for auto-discovery
     * @param bool $forceReload Whether to re-parse the file if already loaded
     * @return bool True if a .env file was discovered and loaded, false otherwise
     */
    public static function load(?string $customPath = null, bool $forceReload = false): bool
    {
        if (self::$loaded && !$forceReload && $customPath === null) {
            return true;
        }

        $envFile = self::locateFile($customPath);

        if ($envFile === null || !is_file($envFile) || !is_readable($envFile)) {
            self::$loaded = true;
            return false;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            self::$loaded = true;
            return false;
        }

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);

            // Skip blank lines and full comment lines
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Support optional "export " prefix (e.g., bash-compatible .env files)
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if ($key === '') {
                continue;
            }

            // Parse quoted or unquoted values
            $parsedValue = self::parseValue($value);

            // Protect existing hosting environment: never overwrite existing host-provided vars
            $existingHostVal = getenv($key);
            $hasHostVal = ($existingHostVal !== false);
            if (!$hasHostVal && isset($_ENV[$key])) {
                $hasHostVal = true;
                $existingHostVal = $_ENV[$key];
            }
            if (!$hasHostVal && isset($_SERVER[$key])) {
                $hasHostVal = true;
                $existingHostVal = $_SERVER[$key];
            }

            if ($hasHostVal) {
                self::$variables[$key] = (string)$existingHostVal;
            } else {
                self::$variables[$key] = $parsedValue;
                putenv("{$key}={$parsedValue}");
                $_ENV[$key]    = $parsedValue;
                $_SERVER[$key] = $parsedValue;
            }
        }

        self::$loaded = true;
        self::$loadedFilePath = $envFile;
        return true;
    }

    /**
     * Locate the .env file using prioritized path discovery.
     *
     * Discovery order:
     * 1. Explicitly provided path argument.
     * 2. SHOPWISE_ENV_PATH environment variable (for cPanel / custom hosting).
     * 3. Project root: <app_root>/.env.
     * 4. Dedicated private directory outside web root (cPanel): <project_parent>/shopwise-config/.env.
     * 5. Parent directory outside web root (cPanel): <project_parent>/.env.
     * 6. Sibling private dotfile: <project_parent>/.env.shopwise.
     */
    public static function locateFile(?string $customPath = null): ?string
    {
        if ($customPath !== null && is_file($customPath) && is_readable($customPath)) {
            return realpath($customPath) ?: $customPath;
        }

        // Check custom environment variable
        $envVarPath = getenv('SHOPWISE_ENV_PATH') ?: ($_ENV['SHOPWISE_ENV_PATH'] ?? ($_SERVER['SHOPWISE_ENV_PATH'] ?? null));
        if (!empty($envVarPath) && is_file($envVarPath) && is_readable($envVarPath)) {
            return realpath($envVarPath) ?: $envVarPath;
        }

        $appDir = dirname(__DIR__, 2);
        $projectRoot = dirname(__DIR__, 3);
        $parentOfProject = dirname($projectRoot);

        $candidates = [
            $projectRoot . '/.env',
            $appDir . '/.env',
            $parentOfProject . '/shopwise-config/.env',
            $parentOfProject . '/.env',
            $parentOfProject . '/.env.shopwise',
            dirname($appDir) . '/shopwise-config/.env',
            dirname($appDir) . '/.env',
            dirname($appDir) . '/.env.shopwise',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }

        return null;
    }

    /**
     * Parse and clean a raw variable value from .env.
     */
    private static function parseValue(string $value): string
    {
        $len = strlen($value);

        // Double-quoted string: supports escaped sequences (\n, \r, \t, \", \\)
        if ($len >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
            $inner = substr($value, 1, -1);
            return str_replace(
                ['\n', '\r', '\t', '\"', '\\\\'],
                ["\n", "\r", "\t", '"', '\\'],
                $inner
            );
        }

        // Single-quoted string: literal string, no escape sequences processed
        if ($len >= 2 && str_starts_with($value, "'") && str_ends_with($value, "'")) {
            return substr($value, 1, -1);
        }

        // Unquoted: strip inline comments preceded by whitespace (" # comment")
        $commentPos = strpos($value, ' #');
        if ($commentPos !== false) {
            $value = substr($value, 0, $commentPos);
        }

        return trim($value);
    }

    /**
     * Get an environment variable with typed casting and optional fallback.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (!self::$loaded) {
            self::load();
        }

        // Precedence: getenv() -> $_ENV -> $_SERVER -> static parsed variables
        $val = getenv($key);
        if ($val === false || $val === null) {
            if (array_key_exists($key, $_ENV)) {
                $val = $_ENV[$key];
            } elseif (array_key_exists($key, $_SERVER)) {
                $val = $_SERVER[$key];
            } elseif (array_key_exists($key, self::$variables)) {
                $val = self::$variables[$key];
            } else {
                return $default;
            }
        }

        return self::cast($val);
    }

    /**
     * Check if an environment variable exists and is non-empty.
     */
    public static function has(string $key): bool
    {
        $val = self::get($key);
        return $val !== null && $val !== '';
    }

    /**
     * Set or override an environment variable in runtime (for testing).
     */
    public static function set(string $key, mixed $value): void
    {
        $strValue = is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
        self::$variables[$key] = $strValue;
        putenv("{$key}={$strValue}");
        $_ENV[$key]    = $strValue;
        $_SERVER[$key] = $strValue;
    }

    /**
     * Get a required configuration variable.
     *
     * In development/local environments, throws an informative exception.
     * In production, fails safely with a generic 503 error without leaking secret values or paths.
     *
     * @throws RuntimeException in development mode when key is missing or empty
     */
    public static function require(string $key): mixed
    {
        $val = self::get($key);

        if ($val === null || $val === '') {
            $msg = "Required configuration variable [{$key}] is missing or empty. Please check your .env configuration.";

            if (self::isProduction()) {
                error_log("[ShopWise Security Fatal] {$msg}");
                if (php_sapi_name() !== 'cli' && !headers_sent()) {
                    http_response_code(503);
                    header('Content-Type: text/html; charset=utf-8');
                }
                if (php_sapi_name() === 'cli') {
                    fwrite(STDERR, "Configuration Error: A required service setting is not configured. Check system logs.\n");
                } else {
                    echo "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><title>Service Temporarily Unavailable</title>";
                    echo "<style>body{font-family:sans-serif;text-align:center;padding:80px 20px;background:#f9fafb;color:#1f2937}h1{font-size:2rem;margin-bottom:10px}p{color:#4b5563;max-width:500px;margin:0 auto 20px}</style></head><body>";
                    echo "<h1>We'll be right back</h1>";
                    echo "<p>We are currently experiencing a brief technical glitch. Our Nairobi support team has been notified. Please try again shortly or contact support@kennedycheroben.co.ke.</p>";
                    echo "</body></html>";
                }
                exit(1);
            }

            throw new RuntimeException($msg);
        }

        return $val;
    }

    /**
     * Check if currently running in production environment.
     */
    public static function isProduction(): bool
    {
        $env = strtolower((string)self::get('APP_ENV', 'production'));
        return $env === 'production';
    }

    /**
     * Check if debug mode is enabled.
     */
    public static function isDebug(): bool
    {
        return (bool)self::get('APP_DEBUG', false);
    }

    /**
     * Return path of the loaded .env file, or null if none loaded.
     */
    public static function getLoadedFilePath(): ?string
    {
        return self::$loadedFilePath;
    }

    /**
     * Reset internal cache (strictly for testing).
     */
    public static function reset(): void
    {
        self::$variables = [];
        self::$loaded = false;
        self::$loadedFilePath = null;
    }

    /**
     * Cast string values into native PHP types (bool, int, float, null).
     */
    private static function cast(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);
        $lower = strtolower($trimmed);

        // Booleans
        if ($lower === 'true' || $lower === '(true)') {
            return true;
        }
        if ($lower === 'false' || $lower === '(false)') {
            return false;
        }

        // Null
        if ($lower === 'null' || $lower === '(null)') {
            return null;
        }

        // Empty placeholder
        if ($lower === 'empty' || $lower === '(empty)') {
            return '';
        }

        // Integers (exact digit match with optional leading minus)
        if (preg_match('/^-?[0-9]+$/', $trimmed)) {
            return (int)$trimmed;
        }

        // Floats
        if (is_numeric($trimmed) && str_contains($trimmed, '.')) {
            return (float)$trimmed;
        }

        return $value;
    }
}

// Global helper function for concise syntax: env('KEY', 'default')
if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}

