<?php
require dirname(__DIR__, 3) . '/app/bootstrap.php';
require dirname(__DIR__, 3) . '/app/views/admin/guard.php';

use App\Services\Permissions;

// Ability 'staff.manage' is restricted to admin role only
Permissions::require('staff.manage');

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'message' => 'Admin authorization verified']);
