<?php
/**
 * ShopWise Kenya - Main Configuration Loader
 * Merges default/example configuration with local environment overrides.
 */

$defaults = require __DIR__ . '/config.example.php';
$local = [];

if (file_exists(__DIR__ . '/config.local.php')) {
    $local = require __DIR__ . '/config.local.php';
}

/**
 * Recursive array merge utility
 */
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

// Dynamic BASE_URL auto-detection if left empty
if (empty($config['app']['base_url'])) {
    if (php_sapi_name() === 'cli') {
        $config['app']['base_url'] = 'http://localhost/shopwise-platform/public';
    } else {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        // Local installations expose /shopwise-platform/public; production
        // subdomains use public as Apache's document root.
        $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $publicPos = strpos($scriptName, '/public');
        if ($publicPos !== false) {
            $basePath = substr($scriptName, 0, $publicPos + 7);
        } elseif (preg_match('#^/admin(?:/|$)#', $scriptName)) {
            $basePath = '';
        } else {
            $dir = dirname($scriptName);
            $basePath = ($dir === '/' || $dir === '\\') ? '' : rtrim($dir, '/');
        }
        
        $config['app']['base_url'] = rtrim("{$scheme}://{$host}{$basePath}", '/');
    }
}

return $config;
