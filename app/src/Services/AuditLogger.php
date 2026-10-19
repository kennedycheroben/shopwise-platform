<?php
namespace App\Services;
use App\Database\Database;
final class AuditLogger {
    public static function write(int $actorId, string $action, string $type, ?int $entityId, array $details, string $ip): void {
        try {
            Database::getInstance()->prepare('INSERT INTO audit_log (actor_user_id,action,entity_type,entity_id,details,ip) VALUES (:actor,:action,:type,:entity,:details,:ip)')->execute([':actor'=>$actorId,':action'=>$action,':type'=>$type,':entity'=>$entityId,':details'=>json_encode($details,JSON_THROW_ON_ERROR),':ip'=>$ip]);
        } catch (\Throwable $e) {
            error_log("AuditLogger write failed: " . $e->getMessage());
        }
    }

    public static function log(int $actorId, string $action, string $type, ?int $entityId, array $details, string $ip): void {
        self::write($actorId, $action, $type, $entityId, $details, $ip);
    }
}
