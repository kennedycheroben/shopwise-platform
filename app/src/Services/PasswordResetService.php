<?php
namespace App\Services;
use App\Database\Database;

final class PasswordResetService
{
 public static function request(string $email,string $ip): void {
  $pdo=Database::getInstance(); $email=strtolower(trim($email));
  $q=$pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE identifier=:identifier AND ip=:ip AND attempted_at>=DATE_SUB(NOW(),INTERVAL 15 MINUTE)");$q->execute([':identifier'=>'password_reset:'.$email,':ip'=>$ip]);
  if((int)$q->fetchColumn()>=5)return;
  $pdo->prepare('INSERT INTO login_attempts(identifier,ip,success) VALUES(:identifier,:ip,1)')->execute([':identifier'=>'password_reset:'.$email,':ip'=>$ip]);
  $q=$pdo->prepare("SELECT id,first_name,email FROM users WHERE email=:email AND status='active' AND role IN ('admin','staff') LIMIT 1");$q->execute([':email'=>$email]);$user=$q->fetch(); if(!$user)return;
  $raw=bin2hex(random_bytes(32));$hash=hash('sha256',$raw);
  $pdo->prepare('UPDATE password_resets SET used_at=NOW() WHERE user_id=:id AND used_at IS NULL')->execute([':id'=>$user['id']]);
  $pdo->prepare('INSERT INTO password_resets(user_id,token_hash,expires_at,ip) VALUES(:id,:hash,DATE_ADD(NOW(),INTERVAL 60 MINUTE),:ip)')->execute([':id'=>$user['id'],':hash'=>$hash,':ip'=>$ip]);
  $link=url('admin/reset-password.php?token='.$raw);
  (new MailerService())->queueEmail($user['email'],$user['first_name'],'Reset your ShopWise Kenya administrator password',"<p>Use this secure link within 60 minutes: <a href=\"".e($link)."\">Reset password</a>.</p>","Reset your ShopWise Kenya administrator password within 60 minutes: $link");
 }
 public static function reset(string $token,string $password): bool {
  if(!preg_match('/^[a-f0-9]{64}$/',$token)||strlen($password)<8)return false; $pdo=Database::getInstance();$hash=hash('sha256',$token);
  return Database::transaction(function($pdo)use($hash,$password){$q=$pdo->prepare('SELECT id,user_id FROM password_resets WHERE token_hash=:hash AND used_at IS NULL AND expires_at>NOW() FOR UPDATE');$q->execute([':hash'=>$hash]);$row=$q->fetch();if(!$row)return false;$pdo->prepare('UPDATE users SET password_hash=:password,password_changed_at=NOW(),remember_token_hash=NULL WHERE id=:id')->execute([':password'=>password_hash($password,PASSWORD_DEFAULT),':id'=>$row['user_id']]);$pdo->prepare('UPDATE password_resets SET used_at=NOW() WHERE id=:id')->execute([':id'=>$row['id']]);return true;});
 }
}
