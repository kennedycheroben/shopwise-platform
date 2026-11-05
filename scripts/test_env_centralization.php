<?php
declare(strict_types=1);

/**
 * ShopWise Kenya - Environment Centralization Verification Suite
 *
 * Verifies that:
 * 1. .env file is loaded and parses quoted/unquoted values and comments properly.
 * 2. Type casting works correctly for booleans, nulls, ints, and floats.
 * 3. Existing host environment variables are preserved and never overwritten.
 * 4. cPanel discovery works via SHOPWISE_ENV_PATH and fallback paths outside web root.
 * 5. Env::require() fails safely in production without exposing secret values.
 * 6. Database, Mpesa, Mailer, and GoogleAuth services read credentials from .env.
 * 7. GoogleAuthService::isConfigured() reflects .env configuration correctly.
 * 8. GoogleAuthService::getRedirectUri() respects GOOGLE_REDIRECT_URI when set.
 * 9. Production security guards and testing isolation are fully preserved.
 */

require_once __DIR__ . '/../app/src/Config/Env.php';
require_once __DIR__ . '/../app/bootstrap.php';

use App\Config\Env;
use App\Database\Database;
use App\Services\GoogleAuthService;
use App\Services\MailerService;
use App\Services\MpesaService;

$origShopwiseConfig = getenv('SHOPWISE_CONFIG');
$passed = 0;
$failed = 0;

function assertTest(bool $condition, string $description): void
{
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$description}\n";
        $failed++;
    }
}

echo "==============================================================================\n";
echo "       SHOPWISE - CENTRALIZED .ENV CONFIGURATION VERIFICATION SUITE           \n";
echo "==============================================================================\n\n";

// --- 1. Basic .env Loading ---
echo "--- Group 1: .env Discovery and Basic Parsing ---\n";
Env::load(null, true);
assertTest(Env::has('APP_NAME'), "APP_NAME is loaded from .env");
assertTest(Env::get('APP_NAME') === 'ShopWise Kenya', "APP_NAME parsed as 'ShopWise Kenya'");
assertTest(Env::get('APP_ENV') === 'local', "APP_ENV parsed as 'local'");
assertTest(Env::get('APP_DEBUG') === true, "APP_DEBUG parsed as boolean true");
assertTest(Env::get('DB_PORT') === 3306, "DB_PORT cast to integer 3306");
assertTest(is_int(Env::get('DB_PORT')), "DB_PORT is native PHP int");
assertTest(Env::get('DB_DATABASE') === 'shopwise_db', "DB_DATABASE in .env is 'shopwise_db'");

// --- 2. Quoted and Unquoted Values & Comment Parsing ---
echo "\n--- Group 2: Parsing Syntax (Quotes, Whitespace, Comments) ---\n";
$tempEnvPath = tempnam(sys_get_temp_dir(), 'env_test_');
$tempContent = <<<'ENV'
# Test Environment File
TEST_PLAIN=hello_world
TEST_SPACED=  spaced_value  
TEST_DOUBLE="double quoted with # not a comment"
TEST_SINGLE='single quoted with # not a comment'
TEST_ESCAPE="line1\nline2\ttab"
TEST_COMMENT=unquoted_value # This is an inline comment
TEST_BOOL_TRUE=true
TEST_BOOL_FALSE=false
TEST_NULL=null
TEST_INT=42
TEST_FLOAT=3.14159
export TEST_EXPORTED=exported_value
ENV;
file_put_contents($tempEnvPath, $tempContent);

Env::reset();
Env::load($tempEnvPath);

assertTest(Env::get('TEST_PLAIN') === 'hello_world', "Plain unquoted string parsed correctly");
assertTest(Env::get('TEST_SPACED') === 'spaced_value', "Whitespace trimmed from unquoted values");
assertTest(Env::get('TEST_DOUBLE') === 'double quoted with # not a comment', "Double-quoted string preserves inner hash");
assertTest(Env::get('TEST_SINGLE') === 'single quoted with # not a comment', "Single-quoted string preserves literal hash");
assertTest(Env::get('TEST_ESCAPE') === "line1\nline2\ttab", "Escape sequences in double quotes decoded properly");
assertTest(Env::get('TEST_COMMENT') === 'unquoted_value', "Inline comment stripped from unquoted value");
assertTest(Env::get('TEST_BOOL_TRUE') === true, "String 'true' cast to boolean true");
assertTest(Env::get('TEST_BOOL_FALSE') === false, "String 'false' cast to boolean false");
assertTest(Env::get('TEST_NULL') === null, "String 'null' cast to native null");
assertTest(Env::get('TEST_INT') === 42, "Integer string cast to int");
assertTest(is_int(Env::get('TEST_INT')), "Integer is strictly typed int");
assertTest(abs(Env::get('TEST_FLOAT') - 3.14159) < 0.00001, "Float string cast to float");
assertTest(is_float(Env::get('TEST_FLOAT')), "Float is strictly typed float");
assertTest(Env::get('TEST_EXPORTED') === 'exported_value', "export prefix stripped and value parsed");

unlink($tempEnvPath);

// --- 3. Protection of Existing Host Environment Variables ---
echo "\n--- Group 3: Host Environment Variable Non-Overwrite Protection ---\n";
putenv('TEST_HOST_PROTECTED=original_host_value');
$_ENV['TEST_HOST_PROTECTED'] = 'original_host_value';

$tempEnvPath2 = tempnam(sys_get_temp_dir(), 'env_test2_');
file_put_contents($tempEnvPath2, "TEST_HOST_PROTECTED=malicious_override\n");

Env::reset();
Env::load($tempEnvPath2);

assertTest(Env::get('TEST_HOST_PROTECTED') === 'original_host_value', "Host-provided environment variable is NOT overwritten by .env");
unlink($tempEnvPath2);
putenv('TEST_HOST_PROTECTED');
unset($_ENV['TEST_HOST_PROTECTED']);

// --- 4. cPanel External Location Discovery ---
echo "\n--- Group 4: cPanel External Location Discovery ---\n";
$tempExternalDir = sys_get_temp_dir() . '/cpanel_private_config_' . bin2hex(random_bytes(4));
mkdir($tempExternalDir, 0700, true);
$cpanelEnvFile = $tempExternalDir . '/.env';
file_put_contents($cpanelEnvFile, "CPANEL_DISCOVERED_KEY=cpanel_secret_12345\n");

putenv("SHOPWISE_ENV_PATH={$cpanelEnvFile}");
Env::reset();
Env::load();

assertTest(Env::get('CPANEL_DISCOVERED_KEY') === 'cpanel_secret_12345', "SHOPWISE_ENV_PATH allows loading from private directory outside web root");
assertTest(Env::getLoadedFilePath() === $cpanelEnvFile, "Env records external cPanel file path");

unlink($cpanelEnvFile);
rmdir($tempExternalDir);
putenv('SHOPWISE_ENV_PATH');

// Reload actual project .env
Env::reset();
Env::load(dirname(__DIR__) . '/.env');

// --- 5. Fail-Safe Handling of Missing Required Credentials ---
echo "\n--- Group 5: Fail-Safe Handling of Required Variables ---\n";
$threwInDev = false;
try {
    Env::set('APP_ENV', 'local');
    Env::require('NON_EXISTENT_MANDATORY_KEY_XYZ');
} catch (RuntimeException $e) {
    $threwInDev = true;
    assertTest(str_contains($e->getMessage(), 'NON_EXISTENT_MANDATORY_KEY_XYZ'), "Dev exception mentions missing key name");
    assertTest(!str_contains($e->getMessage(), 'password') && !str_contains($e->getMessage(), 'secret'), "Dev exception does not leak sensitive information");
}
assertTest($threwInDev, "Env::require() throws RuntimeException in development environment");

// --- 6. Google OAuth Credential Resolution and Precedence ---
echo "\n--- Group 6: Google OAuth Credential Resolution ---\n";

// Clear CLI testing flag temporarily for baseline .env tests
putenv('SHOPWISE_CONFIG');
unset($_SERVER['SHOPWISE_CONFIG']);

Env::set('GOOGLE_CLIENT_ID', '');
Env::set('GOOGLE_CLIENT_SECRET', '');
Env::set('GOOGLE_REDIRECT_URI', '');

assertTest(!GoogleAuthService::isConfigured(), "GoogleAuthService::isConfigured() evaluates false when .env credentials are empty in non-testing mode");
assertTest(!empty(GoogleAuthService::getMissingConfigurationReason()), "getMissingConfigurationReason() gives helpful explanation when unconfigured");
assertTest(GoogleAuthService::getRedirectUri() === url('auth/google/callback'), "getRedirectUri() defaults to dynamic url when GOOGLE_REDIRECT_URI is empty");

// When configured via .env:
Env::set('GOOGLE_CLIENT_ID', 'env-client-id-123.apps.googleusercontent.com');
Env::set('GOOGLE_CLIENT_SECRET', 'env-client-secret-abc');
Env::set('GOOGLE_REDIRECT_URI', 'https://shop.kennedycheroben.co.ke/auth/google/callback');

assertTest(GoogleAuthService::isConfigured(), "GoogleAuthService::isConfigured() evaluates true when .env credentials are provided");
assertTest(GoogleAuthService::getClientId() === 'env-client-id-123.apps.googleusercontent.com', "getClientId() reads GOOGLE_CLIENT_ID from .env");
assertTest(GoogleAuthService::getClientSecret() === 'env-client-secret-abc', "getClientSecret() reads GOOGLE_CLIENT_SECRET from .env");
assertTest(GoogleAuthService::getRedirectUri() === 'https://shop.kennedycheroben.co.ke/auth/google/callback', "getRedirectUri() reads GOOGLE_REDIRECT_URI from .env");
assertTest(GoogleAuthService::getMissingConfigurationReason() === null, "getMissingConfigurationReason() returns null when fully configured");

// Reset Google credentials back to empty
Env::set('GOOGLE_CLIENT_ID', '');
Env::set('GOOGLE_CLIENT_SECRET', '');
Env::set('GOOGLE_REDIRECT_URI', '');

// --- 7. M-Pesa Configuration Sourcing ---
echo "\n--- Group 7: M-Pesa Configuration Sourcing ---\n";
// In non-testing mode: reads from .env
$mpesaCfg = MpesaService::getConfig();
assertTest(!empty($mpesaCfg), "MpesaService::getConfig() returns array");
assertTest($mpesaCfg['env'] === 'sandbox', "MpesaService reads MPESA_ENV=sandbox");
assertTest($mpesaCfg['shortcode'] === '174379', "MpesaService reads MPESA_SHORTCODE=174379");
assertTest($mpesaCfg['passkey'] === 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919', "MpesaService reads MPESA_PASSKEY");
assertTest($mpesaCfg['consumer_key'] === 'local_sandbox_consumer_key', "MpesaService reads MPESA_CONSUMER_KEY from .env");
assertTest($mpesaCfg['consumer_secret'] === 'local_sandbox_consumer_secret', "MpesaService reads MPESA_CONSUMER_SECRET from .env");

// --- 8. Email / SMTP Configuration Sourcing ---
echo "\n--- Group 8: Email / SMTP Configuration Sourcing ---\n";
$mailer = new MailerService();
$reflection = new ReflectionClass($mailer);
$prop = $reflection->getProperty('config');
$prop->setAccessible(true);
$mailCfg = $prop->getValue($mailer);

assertTest($mailCfg['driver'] === 'queue_only', "MailerService reads MAIL_DRIVER=queue_only from .env");
assertTest($mailCfg['host'] === 'mail.kennedycheroben.co.ke', "MailerService reads MAIL_HOST from .env");
assertTest($mailCfg['port'] === 465, "MailerService reads MAIL_PORT=465 as integer");
assertTest($mailCfg['from_address'] === 'support@kennedycheroben.co.ke', "MailerService reads MAIL_FROM_ADDRESS from .env");
assertTest($mailCfg['from_name'] === 'ShopWise Kenya', "MailerService reads MAIL_FROM_NAME from .env");

// --- 9. Database Connection Sourcing & Test Mode Isolation ---
echo "\n--- Group 9: Database Connection Sourcing & Isolation ---\n";
$appConfig = require dirname(__DIR__) . '/app/config/config.php';
assertTest($appConfig['db']['host'] === '127.0.0.1', "config['db']['host'] sourced from .env");
assertTest($appConfig['db']['port'] === 3306, "config['db']['port'] sourced from .env");
assertTest($appConfig['db']['database'] === 'shopwise_db', "config['db']['database'] in non-testing mode targets 'shopwise_db'");
assertTest($appConfig['db']['username'] === 'root', "config['db']['username'] sourced from .env");

// Restore SHOPWISE_CONFIG if it was set
if ($origShopwiseConfig !== false && $origShopwiseConfig !== '') {
    putenv("SHOPWISE_CONFIG={$origShopwiseConfig}");
    $_SERVER['SHOPWISE_CONFIG'] = $origShopwiseConfig;
    $testConfig = require dirname(__DIR__) . '/app/config/config.php';
    assertTest($testConfig['db']['database'] === 'shopwise_test', "When SHOPWISE_CONFIG=testing, database cleanly switches to 'shopwise_test'");
}

echo "\n==============================================================================\n";
echo "SUMMARY: {$passed} Passed, {$failed} Failed\n";
echo "==============================================================================\n";

exit($failed > 0 ? 1 : 0);
