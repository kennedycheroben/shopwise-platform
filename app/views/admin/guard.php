<?php
use App\Database\Database;
use App\Services\AdminAuthService;
header('Cache-Control: no-store, private'); header('X-Frame-Options: DENY'); header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: strict-origin-when-cross-origin');
$return=$_SERVER['REQUEST_URI'] ?? '/admin/'; if (!str_starts_with($return,'/') || str_starts_with($return,'//')) $return='/admin/';
$sessionUser=AdminAuthService::user(); if (!$sessionUser) { header('Location: '.url('admin/login.php?return='.rawurlencode($return))); exit; }
$stmt=Database::getInstance()->prepare('SELECT id,role,first_name,last_name,email,status,password_changed_at FROM users WHERE id=:id LIMIT 1'); $stmt->execute([':id'=>$sessionUser['id']]); $current=$stmt->fetch();
if (!$current || !in_array($current['role'],['admin','staff'],true) || $current['status']!=='active' || (($sessionUser['password_changed_at'] ?? null) !== $current['password_changed_at'])) { AdminAuthService::logout(); header('Location: '.url('admin/login.php')); exit; }
$_SESSION['admin_user']=$current; $adminUser=$current;
