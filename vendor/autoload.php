<?php
/** Minimal PSR-4 loader for the vendored PHPMailer library. */
spl_autoload_register(static function (string $class): void {
    $prefix = 'PHPMailer\\PHPMailer\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/phpmailer/' . substr($class, strlen($prefix)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
