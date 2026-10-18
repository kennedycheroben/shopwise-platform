<?php
namespace App\Services;

final class Permissions
{
    private const MAP = [
        'admin' => ['*'],
        'staff' => ['products.view','products.edit','inventory.adjust','orders.view','orders.manage','customers.view','reviews.moderate','messages.manage'],
    ];
    public static function can(string $ability, ?string $role = null): bool
    {
        $role ??= AdminAuthService::user()['role'] ?? '';
        $abilities = self::MAP[$role] ?? [];
        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }
    public static function require(string $ability): void
    {
        if (!self::can($ability)) {
            http_response_code(403);
            require dirname(__DIR__, 2) . '/views/admin/error-403.php';
            exit;
        }
    }
    public static function matrix(): array { return self::MAP; }
}
