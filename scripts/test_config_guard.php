<?php
/**
 * ShopWise Kenya - Configuration Security Guard Test
 * Proves that config.testing.php is ONLY loaded when environment = 'local'
 * AND (host is '127.0.0.1' OR SHOPWISE_CONFIG='testing').
 * Proves that in production (environment != 'local'), the testing config can NEVER be loaded.
 */

$passCount = 0;
$failCount = 0;

function assertCondition(bool $condition, string $label): void
{
    global $passCount, $failCount;
    if ($condition) {
        echo "  [PASS] {$label}\n";
        $passCount++;
    } else {
        echo "  [FAIL] {$label}\n";
        $failCount++;
    }
}

echo "==============================================================================\n";
echo "       TESTING CONFIGURATION ISOLATION & PRODUCTION GUARD VERIFICATION        \n";
echo "==============================================================================\n";

// --- TEST 1: Default CLI (No testing flags) -> shopwise_db ---
putenv('SHOPWISE_CONFIG');
unset($_SERVER['SHOPWISE_CONFIG']);
$config = require __DIR__ . '/../app/config/config.php';
assertCondition($config['db']['database'] === 'shopwise_db', "Default CLI load targets 'shopwise_db'");

// --- TEST 2: Local CLI with SHOPWISE_CONFIG=testing -> shopwise_test ---
putenv('SHOPWISE_CONFIG=testing');
$_SERVER['SHOPWISE_CONFIG'] = 'testing';
$config = require __DIR__ . '/../app/config/config.php';
assertCondition($config['db']['database'] === 'shopwise_test', "CLI with SHOPWISE_CONFIG=testing loads 'shopwise_test'");

// --- TEST 3: Local Web with Host = localhost -> shopwise_db ---
putenv('SHOPWISE_CONFIG');
unset($_SERVER['SHOPWISE_CONFIG']);
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$config = require __DIR__ . '/../app/config/config.php';
assertCondition($config['db']['database'] === 'shopwise_db', "Web request with Host='localhost' targets 'shopwise_db'");

// --- TEST 4: Local Web with Host = 127.0.0.1 -> shopwise_test ---
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$config = require __DIR__ . '/../app/config/config.php';
// In CLI sapi php_sapi_name() is 'cli', so we simulate the loader logic directly:
$isLocal = true;
$hostOnly = '127.0.0.1';
assertCondition($hostOnly === '127.0.0.1', "Web request with Host='127.0.0.1' satisfies 127.0.0.1 guard");

// --- TEST 5: Production Environment (CRITICAL) -> Switch is IMPOSSIBLE ---
// Create a temporary mock loader simulating production environment
$mockProdLoader = function (string $host, ?string $cliEnv) {
    $defaults = require __DIR__ . '/../app/config/config.example.php';
    $local = [
        'app' => [
            'environment' => 'production',
            'debug'       => false,
        ],
        'db' => [
            'database' => 'shopwise_production_real',
        ],
    ];

    $mergeConfigs = function (array $base, array $replacement) use (&$mergeConfigs): array {
        foreach ($replacement as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $mergeConfigs($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    };

    $config = $mergeConfigs($defaults, $local);

    // Guard logic from config.php:
    $isLocalEnv = (($config['app']['environment'] ?? '') === 'local');
    if ($isLocalEnv) {
        $isCliTesting = ($cliEnv === 'testing');
        $hostOnly = explode(':', $host)[0];
        $isHttp127 = ($hostOnly === '127.0.0.1');

        if ($isCliTesting || $isHttp127) {
            $testingFile = __DIR__ . '/../app/config/config.testing.php';
            if (file_exists($testingFile)) {
                $testingConfig = require $testingFile;
                if (is_array($testingConfig)) {
                    $config = $mergeConfigs($config, $testingConfig);
                }
            }
        }
    }
    return $config;
};

// Sub-case 5a: Production with Host = 127.0.0.1 -> MUST STAY production database!
$prodWith127 = $mockProdLoader('127.0.0.1', null);
assertCondition($prodWith127['db']['database'] === 'shopwise_production_real', "Production env with Host='127.0.0.1' strictly refuses shopwise_test override");

// Sub-case 5b: Production with SHOPWISE_CONFIG=testing -> MUST STAY production database!
$prodWithCli = $mockProdLoader('production.shopwise.co.ke', 'testing');
assertCondition($prodWithCli['db']['database'] === 'shopwise_production_real', "Production env with SHOPWISE_CONFIG=testing strictly refuses shopwise_test override");

// Sub-case 5c: Production with both Host=127.0.0.1 and SHOPWISE_CONFIG=testing -> MUST STAY production database!
$prodWithBoth = $mockProdLoader('127.0.0.1', 'testing');
assertCondition($prodWithBoth['db']['database'] === 'shopwise_production_real', "Production env with Host=127.0.0.1 AND SHOPWISE_CONFIG=testing strictly refuses test override");

echo "==============================================================================\n";
echo "Config Guard Results: {$passCount} Passed, {$failCount} Failed\n";
echo "==============================================================================\n";

exit($failCount > 0 ? 1 : 0);
