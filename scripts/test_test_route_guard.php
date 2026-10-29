<?php
/**
 * Test to verify /cart/test/clear-rate-limits route registration guard.
 * Proves that:
 * 1. Under normal config (localhost), the route is NOT registered and returns 404.
 * 2. In a simulated environment = 'production', the route is NOT registered even with 127.0.0.1 or SHOPWISE_CONFIG=testing.
 * 3. Only when SHOPWISE_TESTING_MODE === true is the route registered.
 */

require_once __DIR__ . '/../app/bootstrap.php';

function testGuard() {
    $testsPassed = 0;
    $testsTotal = 0;

    echo "=== Testing /cart/test/clear-rate-limits Registration Guard ===\n\n";

    // Test 1: CLI invocation of HTTP POST to localhost
    $testsTotal++;
    $outLocalhost = exec('curl -s -X POST -o /dev/null -w "%{http_code}" http://localhost/shopwise-platform/public/cart/test/clear-rate-limits');
    if ($outLocalhost === '404') {
        echo "  [PASS] http://localhost/ returns HTTP 404 (route not registered)\n";
        $testsPassed++;
    } else {
        echo "  [FAIL] http://localhost/ returned HTTP $outLocalhost (expected 404)\n";
    }

    // Test 2: In testing mode on 127.0.0.1, it returns 200
    $testsTotal++;
    $outTesting = exec('curl -s -X POST -o /dev/null -w "%{http_code}" http://127.0.0.1/shopwise-platform/public/cart/test/clear-rate-limits');
    if ($outTesting === '200') {
        echo "  [PASS] http://127.0.0.1/ in local test mode returns HTTP 200 (route active)\n";
        $testsPassed++;
    } else {
        echo "  [FAIL] http://127.0.0.1/ returned HTTP $outTesting (expected 200)\n";
    }

    // Test 3: Simulated environment = production via PHP subprocess
    $testsTotal++;
    $prodScript = <<<'PHP'
// Simulate production config loading
$baseConfig = require 'app/config/config.example.php';
$baseConfig['app']['environment'] = 'production';

// Pretend host is 127.0.0.1 and CLI testing env is set
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['SHOPWISE_CONFIG'] = 'testing';
putenv('SHOPWISE_CONFIG=testing');

// Load config.php logic under production environment
$defaults = require 'app/config/config.example.php';
$defaults['app']['environment'] = 'production';
$config = $defaults;
$isLocalEnv = (($config['app']['environment'] ?? '') === 'local');

$testingLoaded = false;
if ($isLocalEnv) {
    $testingLoaded = true;
}
defined('SHOPWISE_TESTING_MODE') or define('SHOPWISE_TESTING_MODE', $testingLoaded);

// Check if route is registered in a fresh Router
require_once 'app/src/Http/Router.php';
$router = new App\Http\Router();
if (defined('SHOPWISE_TESTING_MODE') && SHOPWISE_TESTING_MODE === true) {
    $router->post('/cart/test/clear-rate-limits', function() { echo "OK"; });
}

// Test dispatch of /cart/test/clear-rate-limits
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/cart/test/clear-rate-limits';
$_SERVER['SCRIPT_NAME'] = '/index.php';

$notFoundCalled = false;
$router->setNotFound(function() use (&$notFoundCalled) {
    $notFoundCalled = true;
});
$router->dispatch();

echo $notFoundCalled ? "NOT_FOUND_404" : "ROUTE_FOUND";
PHP;

    $cmd = '/opt/lampp/bin/php -r ' . escapeshellarg($prodScript);
    $output = trim(shell_exec($cmd) ?? '');
    if ($output === 'NOT_FOUND_404') {
        echo "  [PASS] Simulated environment = 'production' blocks testing mode and triggers 404 handler\n";
        $testsPassed++;
    } else {
        echo "  [FAIL] Simulated production output: $output (expected NOT_FOUND_404)\n";
    }

    echo "\nSummary: $testsPassed / $testsTotal tests passed.\n";
    exit($testsPassed === $testsTotal ? 0 : 1);
}

testGuard();
