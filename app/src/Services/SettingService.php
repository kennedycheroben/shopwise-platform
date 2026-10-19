<?php
namespace App\Services;

use App\Database\Database;
use InvalidArgumentException;
use PDO;

final class SettingService
{
    /**
     * Get all settings from DB as an associative array with proper PHP types.
     */
    public static function getAll(): array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->query("SELECT setting_key, setting_value, type, description FROM settings");
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $val = $row['setting_value'];
            switch ($row['type']) {
                case 'integer':
                    $val = (int)$val;
                    break;
                case 'float':
                    $val = (float)$val;
                    break;
                case 'boolean':
                    $val = in_array(strtolower((string)$val), ['1', 'true', 'yes', 'on'], true);
                    break;
                case 'json':
                    $val = json_decode((string)$val, true);
                    break;
            }
            $settings[$row['setting_key']] = $val;
        }
        return $settings;
    }

    /**
     * Save a single setting.
     */
    public static function set(string $key, $value, ?string $type = null): void
    {
        $pdo = Database::getInstance();
        $strVal = is_array($value) ? json_encode($value) : (string)$value;
        if ($type) {
            $stmt = $pdo->prepare("
                INSERT INTO settings (setting_key, setting_value, type, updated_at)
                VALUES (:k, :v, :t, NOW())
                ON DUPLICATE KEY UPDATE setting_value = :v2, type = :t2, updated_at = NOW()
            ");
            $stmt->execute([':k' => $key, ':v' => $strVal, ':t' => $type, ':v2' => $strVal, ':t2' => $type]);
        } else {
            $stmt = $pdo->prepare("UPDATE settings SET setting_value = :v, updated_at = NOW() WHERE setting_key = :k");
            $stmt->execute([':v' => $strVal, ':k' => $key]);
        }
        clear_settings_cache();
    }

    /**
     * Update General Store Profile Settings.
     */
    public static function updateStoreSettings(array $data, int $actorId, string $ip): void
    {
        $storeName = trim($data['store_name'] ?? '');
        if ($storeName === '') {
            throw new InvalidArgumentException("Store name cannot be empty.");
        }

        $tagline = trim($data['store_tagline'] ?? '');

        $email = trim($data['support_email'] ?? '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("A valid customer support email address is required.");
        }

        $rawPhone = trim($data['support_phone'] ?? '');
        $normalizedPhone = normalize_phone($rawPhone);
        if (!$normalizedPhone) {
            throw new InvalidArgumentException("Please provide a valid Kenyan support phone number (e.g. 0700000000 or +254700000000).");
        }
        // Format for display: +254 7XX XXX XXX
        $formattedPhone = '+254 ' . substr($normalizedPhone, 3, 3) . ' ' . substr($normalizedPhone, 6, 3) . ' ' . substr($normalizedPhone, 9);

        $pickupAddress = trim($data['pickup_address'] ?? '');
        if ($pickupAddress === '') {
            throw new InvalidArgumentException("Store pickup address cannot be empty.");
        }

        $businessHours = trim($data['business_hours'] ?? '');
        if ($businessHours === '') {
            throw new InvalidArgumentException("Business hours cannot be empty.");
        }

        $current = self::getAll();
        $before = [
            'store_name'     => $current['store_name'] ?? '',
            'store_tagline'  => $current['store_tagline'] ?? '',
            'support_email'  => $current['support_email'] ?? '',
            'support_phone'  => $current['support_phone'] ?? '',
            'pickup_address' => $current['pickup_address'] ?? '',
            'business_hours' => $current['business_hours'] ?? '',
        ];

        $after = [
            'store_name'     => $storeName,
            'store_tagline'  => $tagline,
            'support_email'  => $email,
            'support_phone'  => $formattedPhone,
            'pickup_address' => $pickupAddress,
            'business_hours' => $businessHours,
        ];

        foreach ($after as $k => $v) {
            self::set($k, $v);
        }

        AuditLogger::log($actorId, 'settings_update', 'settings', null, [
            'before' => $before,
            'after'  => $after,
        ], $ip);
    }

    /**
     * Update Delivery Pricing & Distance Calculation Settings.
     */
    public static function updateDeliveryPricing(array $data, int $actorId, string $ip): void
    {
        $lat = (float)($data['store_latitude'] ?? 0);
        $lng = (float)($data['store_longitude'] ?? 0);

        if (!DeliveryService::isKenyaCoordinate($lat, $lng)) {
            throw new InvalidArgumentException("Store coordinates ({$lat}, {$lng}) must be inside Kenya's geographic boundaries (Lat: -4.8 to 5.1, Lng: 33.9 to 41.9).");
        }

        $baseFee = (float)($data['distance_base_fee'] ?? 0);
        if ($baseFee < 0) {
            throw new InvalidArgumentException("Distance base fee cannot be negative.");
        }

        $perKm = (float)($data['distance_per_km'] ?? 0);
        if ($perKm < 0) {
            throw new InvalidArgumentException("Rate per kilometer cannot be negative.");
        }

        $includedKm = (float)($data['distance_included_km'] ?? 0);
        if ($includedKm < 0) {
            throw new InvalidArgumentException("Included base distance cannot be negative.");
        }

        $roadFactor = (float)($data['distance_road_factor'] ?? 1.3);
        if ($roadFactor < 1.0 || $roadFactor > 3.0) {
            throw new InvalidArgumentException("Road winding factor must be between 1.0 and 3.0.");
        }

        $minFee = (float)($data['distance_min_fee'] ?? 0);
        if ($minFee < 0) {
            throw new InvalidArgumentException("Minimum delivery fee cannot be negative.");
        }

        $maxFee = (float)($data['distance_max_fee'] ?? 0);
        if ($maxFee < $minFee) {
            throw new InvalidArgumentException("Maximum delivery fee (KES {$maxFee}) cannot be less than the minimum fee (KES {$minFee}).");
        }

        $rounding = (int)($data['distance_rounding'] ?? 10);
        if (!in_array($rounding, [1, 5, 10, 50], true)) {
            throw new InvalidArgumentException("Rounding step must be 1, 5, 10, or 50.");
        }

        $freeThreshold = (float)($data['free_delivery_threshold'] ?? 0);
        if ($freeThreshold < 0) {
            throw new InvalidArgumentException("Free delivery threshold cannot be negative (set to 0 to disable).");
        }

        $expiryMins = (int)($data['order_expiry_minutes'] ?? 30);
        if ($expiryMins < 5 || $expiryMins > 1440) {
            throw new InvalidArgumentException("Order expiry window must be between 5 and 1440 minutes (24 hours).");
        }

        $current = self::getAll();
        $before = [
            'store_latitude'          => (float)($current['store_latitude'] ?? -1.286389),
            'store_longitude'         => (float)($current['store_longitude'] ?? 36.817223),
            'distance_base_fee'       => (float)($current['distance_base_fee'] ?? 150.00),
            'distance_per_km'         => (float)($current['distance_per_km'] ?? 30.00),
            'distance_included_km'    => (float)($current['distance_included_km'] ?? 3.00),
            'distance_road_factor'    => (float)($current['distance_road_factor'] ?? 1.30),
            'distance_min_fee'        => (float)($current['distance_min_fee'] ?? 150.00),
            'distance_max_fee'        => (float)($current['distance_max_fee'] ?? 1500.00),
            'distance_rounding'       => (int)($current['distance_rounding'] ?? 10),
            'free_delivery_threshold' => (float)($current['free_delivery_threshold'] ?? 5000.00),
            'order_expiry_minutes'    => (int)($current['order_expiry_minutes'] ?? 30),
        ];

        $after = [
            'store_latitude'          => $lat,
            'store_longitude'         => $lng,
            'distance_base_fee'       => $baseFee,
            'distance_per_km'         => $perKm,
            'distance_included_km'    => $includedKm,
            'distance_road_factor'    => $roadFactor,
            'distance_min_fee'        => $minFee,
            'distance_max_fee'        => $maxFee,
            'distance_rounding'       => $rounding,
            'free_delivery_threshold' => $freeThreshold,
            'order_expiry_minutes'    => $expiryMins,
        ];

        foreach ($after as $k => $v) {
            self::set($k, $v);
        }

        AuditLogger::log($actorId, 'delivery_pricing_update', 'settings', null, [
            'before' => $before,
            'after'  => $after,
        ], $ip);
    }
}
