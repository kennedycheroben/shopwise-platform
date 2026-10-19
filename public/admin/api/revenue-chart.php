<?php
require dirname(__DIR__, 3) . '/app/bootstrap.php';
require dirname(__DIR__, 3) . '/app/views/admin/guard.php';

use App\Services\DashboardService;

header('Content-Type: application/json; charset=utf-8');

$period = $_GET['period'] ?? 'monthly';
if (!in_array($period, ['monthly', 'weekly', 'daily'], true)) {
    $period = 'monthly';
}

echo json_encode(DashboardService::chartData($period), JSON_THROW_ON_ERROR);
