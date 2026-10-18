<?php
namespace App\Services;

use App\Database\Database;

final class AdminAuthService
{
    public static function user(): ?array { return $_SESSION['admin_user'] ?? null; }

    public static function login(string $email, string $password, string $ip): array
    {
        $pdo = Database::getInstance(); $email = strtolower(trim($email));
        $attempts = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE identifier=:email AND ip=:ip AND success=0 AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $attempts->execute([':email'=>$email, ':ip'=>$ip]);
        if ((int)$attempts->fetchColumn() >= 5) return [false, 'Too many sign-in attempts. Please wait 15 minutes before trying again.'];
        $stmt=$pdo->prepare("SELECT id,role,first_name,last_name,email,password_hash,status,password_changed_at FROM users WHERE email=:email LIMIT 1"); $stmt->execute([':email'=>$email]); $user=$stmt->fetch();
        $ok=$user && in_array($user['role'], ['admin','staff'], true) && $user['status']==='active' && $user['password_hash'] && password_verify($password,$user['password_hash']);
        $pdo->prepare('INSERT INTO login_attempts (identifier,ip,success) VALUES (:email,:ip,:success)')->execute([':email'=>$email,':ip'=>$ip,':success'=>$ok?1:0]);
        if (!$ok) return [false, 'Those sign-in details do not grant access to ShopWise administration.'];
        session_regenerate_id(true); unset($user['password_hash']); $_SESSION['admin_user']=$user;
        $pdo->prepare('UPDATE users SET last_login_at=NOW() WHERE id=:id')->execute([':id'=>$user['id']]);
        return [true, ''];
    }
    public static function logout(): void { unset($_SESSION['admin_user']); session_regenerate_id(true); }
    public static function requireLogin(): array { $user=self::user(); if (!$user) { header('Location: '.url('admin/login.php')); exit; } return $user; }
    public static function requireAdmin(): array { $user=self::requireLogin(); if ($user['role'] !== 'admin') { http_response_code(403); exit('Access denied. This task requires an administrator account.'); } return $user; }
    public static function can(string $capability): bool {
        $u=self::user(); if (!$u) return false; if ($u['role']==='admin') return true;
        return in_array($capability,['dashboard','products','inventory','orders','customers','reviews','messages'],true);
    }
}
