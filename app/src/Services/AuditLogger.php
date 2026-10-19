<?php
namespace App\Services;
use App\Database\Database;
final class AuditLogger {
    private const SENSITIVE_KEY_PATTERN = '/(password|token|secret|hash|passkey|authorization|card|cvv|pan)/i';

    /**
     * Recursively strips sensitive keys from audit log details.
     */
    public static function redact(array $data): array {
        $clean = [];
        foreach ($data as $key => $val) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY_PATTERN, $key)) {
                continue; // Strip key completely
            }
            if (is_array($val)) {
                $clean[$key] = self::redact($val);
            } else {
                $clean[$key] = $val;
            }
        }
        return $clean;
    }

    public static function write(int $actorId, string $action, string $type, ?int $entityId, array $details, string $ip): void {
        try {
            $safeDetails = self::redact($details);
            Database::getInstance()->prepare('INSERT INTO audit_log (actor_user_id,action,entity_type,entity_id,details,ip) VALUES (:actor,:action,:type,:entity,:details,:ip)')->execute([
                ':actor'   => $actorId,
                ':action'  => $action,
                ':type'    => $type,
                ':entity'  => $entityId,
                ':details' => json_encode($safeDetails, JSON_THROW_ON_ERROR),
                ':ip'      => $ip,
            ]);
        } catch (\Throwable $e) {
            error_log("AuditLogger write failed: " . $e->getMessage());
        }
    }

    public static function log(int $actorId, string $action, string $type, ?int $entityId, array $details, string $ip): void {
        self::write($actorId, $action, $type, $entityId, $details, $ip);
    }
}
