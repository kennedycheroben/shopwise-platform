<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
http_response_code(404);
$pageTitle = 'Page not found · ShopWise Kenya Admin';
require dirname(__DIR__, 2) . '/app/views/admin/error-404.php';
