# ShopWise Platform - PHP Modernization & Compatibility Assessment

## 1. Scope & Objectives

The engineering mandate requires:
- **Primary Runtime Target:** PHP 8.5.x for local development and modern environments.
- **Hosting Compatibility Baseline:** PHP 8.4.x as Truehost cPanel shared hosting fallback.
- **Supported Legacy Runtime:** PHP 8.2.x (XAMPP bundled runtime for local Apache server).

---

## 2. Runtime Environment Inventory

| Runtime | Version | SAPI | Role | Verification Status |
| :--- | :--- | :--- | :--- | :--- |
| **System CLI** | PHP 8.5.4 (NTS) | CLI (`/usr/bin/php`) | Modern development CLI, acceptance testing | **VERIFIED - 802/802 tests pass** |
| **Apache Web Server** | PHP 8.2.12 (NTS) | Apache 2.4.58 Handler (`mod_php`) | Local web request processing | **VERIFIED - 802/802 tests pass** |
| **Target Shared Hosting** | PHP 8.4.x (ea-php84) | Truehost cPanel MultiPHP (FastCGI / FPM) | Production target deployment | **COMPATIBLE (Zero blocker features)** |

---

## 3. Required PHP Extensions Audit

The application's dependencies were verified against both PHP 8.5 and PHP 8.2 runtimes:

| Extension | Required Purpose | Available in PHP 8.5 | Available in PHP 8.2 | Truehost cPanel Availability |
| :--- | :--- | :--- | :--- | :--- |
| **`pdo`** | Database abstraction layer | Yes (Core) | Yes (Core) | Yes (Default) |
| **`pdo_mysql`** | MySQL/MariaDB driver | Yes | Yes | Yes (Default) |
| **`curl`** | Safaricom Daraja M-Pesa API & Google OAuth | Yes | Yes | Yes (Default) |
| **`openssl`** | Cryptographic token generation (`random_bytes`, HMAC SHA256) | Yes | Yes | Yes (Default) |
| **`fileinfo`** | MIME-type validation for image uploads (`finfo_file`) | Yes | Yes | Yes (Default) |
| **`gd`** | Image resizing, thumbnail generation & WebP re-encoding | Yes (with WebP) | Yes (JPEG/PNG only) | Yes (Default) |
| **`mbstring`** | Multibyte string operations, UTF-8 safety | Yes | Yes | Yes (Default) |
| **`json`** | API request/response encoding & storage | Yes (Core) | Yes (Core) | Yes (Default) |
| **`dom` / `libxml`** | HTML sanitization allowlist parsing (`HtmlSanitizer`) | Yes | Yes | Yes (Default) |
| **`sodium`** | Cryptographic primitives | Yes | Yes | Yes (Default) |

> [!NOTE]
> GD WebP support is present in system PHP 8.5 (`imagewebp() === true`), while the local XAMPP bundled PHP 8.2 binary lacks WebP compilation. In `ImageUploadService`, fallback to JPEG is dynamically handled when `function_exists('imagewebp')` returns false. On Truehost cPanel, `ea-php84-php-gd` includes libwebp.

---

## 4. PHP 8.4 & 8.5 Compatibility Inspection

### 4.1 Implicitly Nullable Parameter Types (PHP 8.4 Deprecation)
In PHP 8.4+, parameter signatures of the form `function foo(string $arg = null)` emit `E_DEPRECATED`. All parameters with null defaults MUST be explicitly declared with `?Type` or union `Type|null`.
- **Audit Tool:** AST and token-based parameter scanner across all 118 PHP files.
- **Finding:** All parameter signatures in ShopWise use explicit nullable types (`?string $x = null`, `string|int|null $y = null`). Zero implicit nullable deprecations exist.

### 4.2 DOMDocument & HTML Parsing
- In PHP 8.4/8.5, `DOMDocument::loadHTML()` continues to be supported for backward compatibility, while new `\Dom\HTMLDocument` was added.
- `HtmlSanitizer::clean()` was tested on PHP 8.5.4 with arbitrary rich-text payloads: executed cleanly with zero warnings or notices.

### 4.3 Passing Null to Non-Nullable Internal Functions (PHP 8.1+)
- All string utilities (`trim`, `substr`, `strlen`, `htmlspecialchars`, `preg_match`) are guarded with null checks, type casts, or the null-safe `e(?string $value): string` helper.

### 4.4 PHP Syntax Linter Verification
All 118 PHP files were linted with `/usr/bin/php -l`:
```bash
find app public -path "app/storage" -prune -o -type f -name "*.php" -exec /usr/bin/php -l {} +
```
**Result:** 0 syntax errors across 118 files.

---

## 5. Dual-Runtime Test Suite Verification

Test scripts were upgraded to allow testing under both runtimes via `PHP_CMD`:
- **Under PHP 8.2.12:** `bash scripts/run_all_tests.sh` -> **802 Passed, 0 Failed, 0 Checksum Drift**
- **Under PHP 8.5.4:** `PHP_CMD=/usr/bin/php bash scripts/run_all_tests.sh` -> **802 Passed, 0 Failed, 0 Checksum Drift**

---

## 6. Hosting Compatibility Matrix (Truehost cPanel)

| Host PHP Version | Status | Recommended Settings |
| :--- | :--- | :--- |
| **PHP 8.4 (Target)** | **Full Support** | `max_execution_time = 60`, `memory_limit = 256M`, `upload_max_filesize = 10M`, `post_max_size = 12M` |
| **PHP 8.3** | **Full Support** | Compatible with all existing features. |
| **PHP 8.2** | **Full Support** | Verified locally via XAMPP. |
| **PHP 8.5** | **Full Support** | Verified locally via Ubuntu PHP 8.5 CLI. |
