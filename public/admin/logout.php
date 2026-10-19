<?php
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Services\AdminAuthService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

if (!csrf_verify($_POST['_csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Forbidden: Invalid CSRF token.');
}

AdminAuthService::logout();
flash_set('success', 'You have signed out securely.');
redirect('admin/login.php');
