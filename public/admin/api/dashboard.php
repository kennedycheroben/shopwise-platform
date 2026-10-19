<?php
require dirname(__DIR__, 3) . '/app/bootstrap.php';
require dirname(__DIR__, 3) . '/app/views/admin/guard.php';

use App\Services\DashboardService;

header('Content-Type: application/json; charset=utf-8');

echo json_encode(DashboardService::data(), JSON_THROW_ON_ERROR);
