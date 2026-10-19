<?php

use App\Database\Database;

/**
 * HTML Output Escaping
 *
 * @param string|null $value
 * @return string
 */
function e(?string $value): string
{
    if ($value === null) {
        return '';
    }
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Safe HTTP Redirect
 *
 * @param string $path
 * @param int $status
 * @return void
 */
function redirect(string $path, int $status = 302): void
{
    $target = (preg_match('#^https?://#i', $path)) ? $path : url($path);
    header("Location: {$target}", true, $status);
    exit;
}

/**
 * Generate full URL for public routes
 *
 * @param string $path
 * @return string
 */
function url(string $path = ''): string
{
    static $baseUrl = null;
    if ($baseUrl === null) {
        $config = require dirname(__DIR__, 2) . '/config/config.php';
        $baseUrl = rtrim($config['app']['base_url'] ?? '', '/');
    }
    $path = ltrim($path, '/');
    return $path !== '' ? "{$baseUrl}/{$path}" : $baseUrl;
}

/**
 * Generate URL for public assets
 *
 * @param string $path
 * @return string
 */
function asset(string $path = ''): string
{
    return url($path);
}

/**
 * Generate URL for admin routes
 *
 * @param string $path
 * @return string
 */
function admin_url(string $path = ''): string
{
    $path = ltrim($path, '/');
    return url("admin/{$path}");
}

/**
 * Generate URL for product images (supports both seeded assets and user uploads)
 *
 * @param string|null $path
 * @param string $default
 * @return string
 */
function product_image_url(?string $path, string $default = 'assets/img/product/product-1.webp'): string
{
    if (empty($path)) {
        return asset($default);
    }
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        return $path;
    }
    return asset(ltrim($path, '/'));
}

/**
 * CSRF Token Management
 */

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

function csrf_field(): string
{
    $token = e(csrf_token());
    return '<input type="hidden" name="_csrf_token" value="' . $token . '">';
}

function csrf_verify(?string $token = null): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if ($token === null) {
        $token = $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    }
    if (empty($token) || empty($_SESSION['_csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['_csrf_token'], (string)$token);
}

/**
 * Flash Messages
 */

function flash_set(string $type, string $message): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['_flash'][$type][] = $message;
}

function flash_get(string $type): ?string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (!empty($_SESSION['_flash'][$type])) {
        $messages = $_SESSION['_flash'][$type];
        unset($_SESSION['_flash'][$type]);
        return implode('<br>', $messages);
    }
    return null;
}

function flash_has(string $type): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    return !empty($_SESSION['_flash'][$type]);
}

function flash_all(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $all = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $all;
}

/**
 * Session ID Regeneration with Strict Security
 */
function regenerate_session_id(bool $deleteOldSession = true): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id($deleteOldSession);
    }
}

/**
 * Format Currency for Kenya (KES)
 *
 * Keeps decimal database values as strings so currency is never rounded through
 * a binary floating-point conversion.
 *
 * @param string|int|null $amount
 * @param bool $includeDecimals
 * @return string
 */
function format_money(string|int|float|null $amount, bool $includeDecimals = false): string
{
    if ($amount === null || $amount === '') {
        $amount = '0';
    }

    $amount = trim(is_float($amount) ? sprintf('%.2f', $amount) : (string)$amount);
    if (!preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches)) {
        throw new InvalidArgumentException('Money amounts must be a decimal string with at most two decimal places.');
    }

    $sign = $matches[1] === '-' ? '-' : '';
    $whole = ltrim($matches[2], '0');
    $whole = $whole === '' ? '0' : $whole;
    $fraction = str_pad($matches[3] ?? '', 2, '0');
    $groupedWhole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole);

    return 'KES ' . $sign . $groupedWhole . ($includeDecimals || $fraction !== '00' ? '.' . $fraction : '');
}

/**
 * Normalize Kenyan Telephone Numbers
 * Formats:
 * - 07XXXXXXXX -> 2547XXXXXXXX
 * - 01XXXXXXXX -> 2541XXXXXXXX
 * - +2547XXXXXXXX -> 2547XXXXXXXX
 * - +2541XXXXXXXX -> 2541XXXXXXXX
 * - 2547XXXXXXXX -> 2547XXXXXXXX
 * - 2541XXXXXXXX -> 2541XXXXXXXX
 * Rejects invalid prefixes, lengths, and non-numeric characters.
 *
 * @param string|null $phone
 * @return string|null Normalized 12-digit format or null if invalid
 */
function normalize_phone(?string $phone): ?string
{
    if ($phone === null) {
        return null;
    }

    // Strip out spaces, dashes, parentheses, dots
    $clean = preg_replace('/[\s\-\(\)\.]/', '', trim($phone));

    // Handle leading '+'
    if (str_starts_with($clean, '+')) {
        $clean = substr($clean, 1);
    }

    // Convert 07XX or 01XX (10 digits) to 2547XX / 2541XX
    if (preg_match('/^0([17]\d{8})$/', $clean, $matches)) {
        return '254' . $matches[1];
    }

    // Already 2547XX or 2541XX (12 digits)
    if (preg_match('/^254([17]\d{8})$/', $clean)) {
        return $clean;
    }

    // If starts with 7XX or 1XX (9 digits without leading 0)
    if (preg_match('/^([17]\d{8})$/', $clean, $matches)) {
        return '254' . $matches[1];
    }

    return null;
}

/**
 * Generate URL-Safe Slug
 *
 * @param string $text
 * @return string
 */
function slugify(string $text): string
{
    if (function_exists('transliterator_transliterate')) {
        $transliterated = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
        if ($transliterated !== false) {
            $text = $transliterated;
        }
    }
    $text = strtolower($text);
    // Apostrophes join possessives (for example, "Women's" -> "womens").
    $text = str_replace(["'", "’", "`"], '', $text);
    $text = preg_replace('~[^a-z0-9]+~', '-', $text);
    $text = preg_replace('~-+~', '-', $text);
    $text = trim((string)$text, '-');
    return $text === '' ? 'item' : $text;
}

/**
 * Create a catalog slug that is unique in an allowed table. The table name is
 * deliberately allow-listed because SQL identifiers cannot be bound by PDO.
 */
function unique_slug(string $table, string $text, ?int $ignoreId = null): string
{
    $allowedTables = ['products', 'categories'];
    if (!in_array($table, $allowedTables, true)) {
        throw new InvalidArgumentException('Unsupported slug table.');
    }

    $pdo = Database::getInstance();
    $base = slugify($text);
    $candidate = $base;
    $suffix = 2;

    do {
        $sql = "SELECT 1 FROM {$table} WHERE slug = :slug";
        $params = [':slug' => $candidate];
        if ($ignoreId !== null) {
            $sql .= ' AND id <> :id';
            $params[':id'] = $ignoreId;
        }
        $sql .= ' LIMIT 1';

        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        if (!$statement->fetchColumn()) {
            return $candidate;
        }
        $candidate = $base . '-' . $suffix++;
    } while (true);
}

/**
 * Retrieve Stored Application Setting with Memory Cache
 *
 * @param string $key
 * @param mixed $default
 * @return mixed
 */
function setting(string $key, $default = null)
{
    static $settingsCache = null;

    if ($settingsCache === null) {
        $settingsCache = [];
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->query("SELECT setting_key, setting_value, type FROM settings");
            while ($row = $stmt->fetch()) {
                $val = $row['setting_value'];
                switch ($row['type']) {
                    case 'integer':
                        $val = (int)$val;
                        break;
                    case 'float':
                        $val = (float)$val;
                        break;
                    case 'boolean':
                        $val = in_array(strtolower((string)$val), ['1', 'true', 'yes', 'on'], true);
                        break;
                    case 'json':
                        $val = json_decode((string)$val, true);
                        break;
                }
                $settingsCache[$row['setting_key']] = $val;
            }
        } catch (\Throwable $e) {
            error_log("Failed to load settings from DB: " . $e->getMessage());
        }
    }

    return $settingsCache[$key] ?? $default;
}

/**
 * Pagination Calculation Helper
 *
 * @param int $totalItems
 * @param int $currentPage
 * @param int $perPage
 * @param string $baseUrl
 * @return array
 */
function paginate(int $totalItems, int $currentPage, int $perPage, string $baseUrl): array
{
    $perPage = max(1, $perPage);
    $totalPages = (int)ceil($totalItems / $perPage);
    $currentPage = max(1, min($currentPage, max(1, $totalPages)));
    $offset = ($currentPage - 1) * $perPage;

    return [
        'total_items'  => $totalItems,
        'per_page'     => $perPage,
        'current_page' => $currentPage,
        'total_pages'  => $totalPages,
        'offset'       => $offset,
        'has_prev'     => $currentPage > 1,
        'has_next'     => $currentPage < $totalPages,
        'prev_page'    => $currentPage - 1,
        'next_page'    => $currentPage + 1,
        'base_url'     => $baseUrl,
    ];
}
