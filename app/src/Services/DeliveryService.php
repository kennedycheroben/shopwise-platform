<?php
namespace App\Services;

use App\Database\Database;
use DomainException;
use InvalidArgumentException;
use PDO;

final class DeliveryService
{
    public const KENYA_LAT_MIN = -4.8;
    public const KENYA_LAT_MAX = 5.1;
    public const KENYA_LNG_MIN = 33.9;
    public const KENYA_LNG_MAX = 41.9;

    /**
     * Compute Haversine distance in kilometers between two lat/lng points.
     */
    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371.0; // km

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Check if coordinates lie inside Kenya bounding box.
     */
    public static function isKenyaCoordinate(float $lat, float $lng): bool
    {
        return ($lat >= self::KENYA_LAT_MIN && $lat <= self::KENYA_LAT_MAX &&
                $lng >= self::KENYA_LNG_MIN && $lng <= self::KENYA_LNG_MAX);
    }

    /**
     * Round a value to the nearest step (e.g. 1, 5, 10, 50).
     */
    public static function roundToStep(float $value, int $step): float
    {
        $step = max(1, $step);
        return (float)round(round($value / $step) * $step);
    }

    /**
     * Fetch all delivery settings as an associative key-value map.
     */
    public static function getDeliverySettings(): array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->query("SELECT setting_key, setting_value, type FROM settings");
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $val = $row['setting_value'];
            if ($row['type'] === 'float') {
                $val = (float)$val;
            } elseif ($row['type'] === 'integer') {
                $val = (int)$val;
            } elseif ($row['type'] === 'boolean') {
                $val = (bool)$val;
            }
            $settings[$row['setting_key']] = $val;
        }

        return [
            'store_latitude'          => (float)($settings['store_latitude'] ?? -1.286389),
            'store_longitude'         => (float)($settings['store_longitude'] ?? 36.817223),
            'distance_base_fee'       => (float)($settings['distance_base_fee'] ?? 150.00),
            'distance_per_km'         => (float)($settings['distance_per_km'] ?? 30.00),
            'distance_included_km'    => (float)($settings['distance_included_km'] ?? 3.00),
            'distance_road_factor'    => (float)($settings['distance_road_factor'] ?? 1.30),
            'distance_min_fee'        => (float)($settings['distance_min_fee'] ?? 150.00),
            'distance_max_fee'        => (float)($settings['distance_max_fee'] ?? 1500.00),
            'distance_rounding'       => (int)($settings['distance_rounding'] ?? 10),
            'free_delivery_threshold' => (float)($settings['free_delivery_threshold'] ?? 5000.00),
            'order_expiry_minutes'    => (int)($settings['order_expiry_minutes'] ?? 30),
        ];
    }

    /**
     * Compute delivery quote.
     * Pure function returning:
     *   [method, fee, distance_km, est_days, label, free_applied]
     */
    public static function quote(
        string $method,
        float $subtotal,
        ?int $zoneId = null,
        ?float $lat = null,
        ?float $lng = null
    ): array {
        if ($subtotal < 0) {
            throw new InvalidArgumentException("Order subtotal cannot be negative.");
        }

        if ($method === 'delivery') {
            if ($lat !== null && $lng !== null) {
                $method = 'distance';
            } elseif ($zoneId !== null && $zoneId > 0) {
                $method = 'zone';
            } else {
                return [
                    'method'       => 'delivery',
                    'fee'          => 0.00,
                    'distance_km'  => null,
                    'est_days'     => 'Select location',
                    'label'        => 'Courier Delivery',
                    'free_applied' => false,
                ];
            }
        }

        if (!in_array($method, ['pickup', 'zone', 'distance'], true)) {
            throw new InvalidArgumentException("Invalid delivery method: '{$method}'. Must be pickup, zone, or distance.");
        }

        $config = self::getDeliverySettings();
        $freeThreshold = $config['free_delivery_threshold'];
        $freeApplied = ($freeThreshold > 0 && $subtotal >= $freeThreshold);

        // 1. Pickup Method
        if ($method === 'pickup') {
            return [
                'method'       => 'pickup',
                'fee'          => 0.00,
                'distance_km'  => null,
                'est_days'     => 'Same day',
                'label'        => 'Store Pickup (Moi Avenue CBD)',
                'free_applied' => false,
            ];
        }

        // 2. Zone Delivery Method
        if ($method === 'zone') {
            if (!$zoneId || $zoneId <= 0) {
                throw new InvalidArgumentException("A valid delivery zone ID is required for zone delivery.");
            }

            $pdo = Database::getInstance();
            $stmt = $pdo->prepare("SELECT * FROM delivery_zones WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $zoneId]);
            $zone = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$zone) {
                throw new InvalidArgumentException("Delivery zone #{$zoneId} does not exist.");
            }
            if (empty($zone['is_active'])) {
                throw new InvalidArgumentException("Selected delivery zone '{$zone['county']} - {$zone['area']}' is currently inactive.");
            }

            $rawFee = (float)$zone['fee'];
            $fee = $freeApplied ? 0.00 : $rawFee;
            $label = "{$zone['county']} - {$zone['area']}" . ($freeApplied ? ' (Free delivery applied)' : '');
            $estDays = "{$zone['est_days_min']}-{$zone['est_days_max']} business days";

            return [
                'method'       => 'zone',
                'fee'          => $fee,
                'distance_km'  => null,
                'est_days'     => $estDays,
                'label'        => $label,
                'free_applied' => $freeApplied,
            ];
        }

        // 3. Distance Delivery Method
        if ($lat === null || $lng === null) {
            throw new InvalidArgumentException("Delivery coordinates (latitude and longitude) are required for distance delivery.");
        }

        if (!self::isKenyaCoordinate($lat, $lng)) {
            throw new InvalidArgumentException("Provided delivery coordinates ({$lat}, {$lng}) are outside Kenya's geographic boundaries.");
        }

        $distanceKm = self::haversineKm($config['store_latitude'], $config['store_longitude'], $lat, $lng);
        $roadFactor = max(1.0, min(3.0, (float)$config['distance_road_factor']));
        $includedKm = max(0.0, (float)$config['distance_included_km']);
        $baseFee = max(0.0, (float)$config['distance_base_fee']);
        $perKm = max(0.0, (float)$config['distance_per_km']);

        // Formula: fee = base_fee + per_km * max(0, distance_km * road_factor - included_km)
        $chargeableKm = max(0.0, ($distanceKm * $roadFactor) - $includedKm);
        $calculatedFee = $baseFee + ($perKm * $chargeableKm);

        // Step rounding
        $roundedFee = self::roundToStep($calculatedFee, $config['distance_rounding']);

        // Clamping between min_fee and max_fee
        $minFee = (float)$config['distance_min_fee'];
        $maxFee = max($minFee, (float)$config['distance_max_fee']);
        $clampedFee = min($maxFee, max($minFee, $roundedFee));

        $fee = $freeApplied ? 0.00 : $clampedFee;
        $formattedKm = round($distanceKm, 1);
        $label = "Direct Courier Delivery (~{$formattedKm} km)" . ($freeApplied ? ' (Free delivery applied)' : '');
        $estDays = ($distanceKm <= 25.0) ? 'Same day' : '1-2 business days';

        return [
            'method'       => 'distance',
            'fee'          => $fee,
            'distance_km'  => round($distanceKm, 2),
            'est_days'     => $estDays,
            'label'        => $label,
            'free_applied' => $freeApplied,
        ];
    }

    /**
     * Convenience method for distance-based delivery quote.
     */
    public static function quoteDistance(float $lat, float $lng, float $subtotal): array
    {
        return self::quote('distance', $subtotal, null, $lat, $lng);
    }

    /**
     * Convenience method for zone-based delivery quote.
     */
    public static function quoteZone(int $zoneId, float $subtotal): array
    {
        return self::quote('zone', $subtotal, $zoneId);
    }

    // =========================================================================
    // Zones CRUD
    // =========================================================================

    public static function listZones(array $filters = []): array
    {
        $pdo = Database::getInstance();
        $sql = "SELECT z.*, 
                (SELECT COUNT(*) FROM orders o WHERE o.county = z.county AND o.area = z.area) as order_count
                FROM delivery_zones z WHERE 1=1";
        $params = [];

        if (!empty($filters['county'])) {
            $sql .= " AND z.county = :county";
            $params[':county'] = trim($filters['county']);
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (z.county LIKE :search OR z.area LIKE :search)";
            $params[':search'] = '%' . trim($filters['search']) . '%';
        }

        if (isset($filters['is_active'])) {
            $sql .= " AND z.is_active = :active";
            $params[':active'] = (int)$filters['is_active'];
        }

        $sql .= " ORDER BY z.county ASC, z.fee ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getCounties(): array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->query("SELECT DISTINCT county FROM delivery_zones ORDER BY county ASC");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function getZone(int $id): ?array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM delivery_zones WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $zone = $stmt->fetch(PDO::FETCH_ASSOC);
        return $zone ?: null;
    }

    /**
     * Alias for getZone()
     */
    public static function getZoneById(int $id): ?array
    {
        return self::getZone($id);
    }

    /**
     * Check if a customer-supplied county matches a delivery zone county definition.
     */
    public static function countyMatchesZone(string $customerCounty, array $zone): bool
    {
        $custCounty = mb_strtolower(trim(preg_replace('/\s+County$/i', '', $customerCounty)));
        if ($custCounty === '') {
            return false;
        }

        $zoneCounty = mb_strtolower(trim($zone['county']));
        if ($zoneCounty === $custCounty) {
            return true;
        }

        // Split composite county names by commas, '&', 'and'
        $parts = preg_split('/[,&]|\band\b/', $zoneCounty);
        foreach ($parts as $p) {
            $cleanP = mb_strtolower(trim(preg_replace('/\s+County$/i', '', $p)));
            if ($cleanP !== '' && ($cleanP === $custCounty || str_contains($custCounty, $cleanP) || str_contains($cleanP, $custCounty))) {
                return true;
            }
        }

        // If zone is "Other Major Towns", check if customer county is explicitly that or one of its covered towns
        if (str_contains($zoneCounty, 'other major towns')) {
            if ($custCounty === 'other major towns' || $custCounty === 'major towns' || $custCounty === 'upcountry') {
                return true;
            }
            $coveredCounties = ['nakuru', 'mombasa', 'kisumu', 'eldoret', 'uasin gishu', 'nyeri', 'kisii'];
            foreach ($coveredCounties as $c) {
                if ($custCounty === $c || str_contains($custCounty, $c) || str_contains($c, $custCounty)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if a customer-supplied area matches a delivery zone area definition.
     */
    public static function areaMatchesZone(string $customerArea, array $zone): bool
    {
        $custArea = mb_strtolower(trim($customerArea));
        if ($custArea === '') {
            return false;
        }

        $zoneArea = mb_strtolower(trim($zone['area']));
        if ($zoneArea === $custArea) {
            return true;
        }

        // Build token list from zone area string
        $tokens = [];
        foreach (explode(',', $zoneArea) as $t) {
            $t = trim($t);
            if ($t !== '') {
                $tokens[] = $t;
                $clean = trim(preg_replace('/\s*\(.*?\)/', '', $t));
                if ($clean !== '' && $clean !== $t) {
                    $tokens[] = $clean;
                }
            }
        }

        // Extract parenthetical sub-areas, e.g. (Embakasi, Donholm, Umoja, Kayole)
        if (preg_match_all('/\((.*?)\)/', $zoneArea, $matches)) {
            foreach ($matches[1] as $inner) {
                if (str_contains($inner, 'via courier')) {
                    continue;
                }
                foreach (explode(',', $inner) as $innerT) {
                    $innerT = trim($innerT);
                    if ($innerT !== '') {
                        $tokens[] = $innerT;
                    }
                }
            }
        }

        // Add common aliases for major centers
        if (str_contains($zoneArea, 'cbd')) {
            $tokens[] = 'cbd';
            $tokens[] = 'nairobi cbd';
            $tokens[] = 'central business district';
        }

        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === $custArea) {
                return true;
            }
            if (strlen($custArea) >= 3 && strlen($token) >= 3) {
                if (str_contains($token, $custArea) || str_contains($custArea, $token)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Find matching zones for a given county and area pair.
     * County and area are ALWAYS validated together.
     *
     * @return array List of matching zone rows
     */
    public static function findMatchingZones(string $county, string $area, bool $activeOnly = true): array
    {
        $county = trim($county);
        $area = trim($area);
        if ($county === '' || $area === '') {
            return [];
        }

        $pdo = Database::getInstance();
        $sql = "SELECT * FROM delivery_zones";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY id ASC";
        $zones = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $matches = [];
        foreach ($zones as $zone) {
            if (self::countyMatchesZone($county, $zone) && self::areaMatchesZone($area, $zone)) {
                $matches[] = $zone;
            }
        }

        return $matches;
    }

    /**
     * Resolve the authoritative active delivery zone for a customer location.
     *
     * Invariants:
     * 1. County and area must both be non-empty and validated together.
     * 2. Exactly one distinct active delivery zone must match.
     * 3. If multiple distinct zones match, throws DomainException for ambiguity.
     * 4. If an inactive zone matches, throws DomainException stating the zone is inactive.
     * 5. If no zone matches, throws DomainException stating delivery is unavailable.
     * 6. If a client supplied a zone ID, it must match the authoritative zone ID or throw DomainException.
     *
     * @throws DomainException
     */
    public static function resolveAuthoritativeZone(string $county, string $area, ?int $suppliedZoneId = null): array
    {
        $county = trim($county);
        $area = trim($area);

        if ($county === '' || $area === '') {
            throw new DomainException("Please select both your County and Area/Town for delivery.");
        }

        // 1. Search active matching zones
        $activeMatches = self::findMatchingZones($county, $area, true);

        // Check for multiple distinct active matches (ambiguity)
        if (count($activeMatches) > 1) {
            $uniqueIds = array_unique(array_map(fn($z) => (int)$z['id'], $activeMatches));
            if (count($uniqueIds) > 1) {
                throw new DomainException("Delivery location '{$county} - {$area}' is ambiguous as it matches multiple configured delivery zones. Please select a more specific area or neighborhood.");
            }
        }

        if (empty($activeMatches)) {
            // Check if matched an inactive zone
            $allMatches = self::findMatchingZones($county, $area, false);
            if (!empty($allMatches)) {
                $inactiveZone = $allMatches[0];
                throw new DomainException("The delivery zone for '{$inactiveZone['county']} - {$inactiveZone['area']}' is currently inactive. Please choose store pickup or contact support.");
            }

            throw new DomainException("Delivery is unavailable for '{$county} - {$area}'. Please choose a supported delivery area or select Store Pickup.");
        }

        $authoritativeZone = $activeMatches[0];

        // 2. Validate client-supplied zone ID if provided (prevent client tampering)
        if ($suppliedZoneId !== null && $suppliedZoneId > 0) {
            if ((int)$authoritativeZone['id'] !== $suppliedZoneId) {
                throw new DomainException("The provided delivery zone ID #{$suppliedZoneId} does not match the authoritative delivery zone for '{$county} - {$area}'. Delivery fee tampering was rejected.");
            }
        }

        return $authoritativeZone;
    }

    /**
     * Match a given county and area/neighborhood string to an active delivery zone.
     * Safe wrapper around resolveAuthoritativeZone returning null on mismatch.
     */
    public static function matchZoneByLocation(string $county, string $area): ?array
    {
        try {
            return self::resolveAuthoritativeZone($county, $area);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function createZone(array $data, int $actorId, string $ip): int
    {
        $county = trim($data['county'] ?? '');
        $area = trim($data['area'] ?? '');

        if ($county === '' || $area === '') {
            throw new InvalidArgumentException("County and area name are required.");
        }

        $fee = (float)($data['fee'] ?? 0);
        if ($fee < 0) {
            throw new InvalidArgumentException("Delivery fee cannot be negative.");
        }
        if (floor($fee) != $fee) {
            throw new InvalidArgumentException("Delivery fee must be a whole shilling amount (e.g. 200, not 200.50).");
        }

        $minDays = max(1, (int)($data['est_days_min'] ?? 1));
        $maxDays = max($minDays, (int)($data['est_days_max'] ?? $minDays));

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT id FROM delivery_zones WHERE county = :county AND area = :area LIMIT 1");
        $stmt->execute([':county' => $county, ':area' => $area]);
        if ($stmt->fetch()) {
            throw new InvalidArgumentException("A delivery zone for '{$county} - {$area}' already exists.");
        }

        $isActive = !empty($data['is_active']) ? 1 : 0;

        $stmt = $pdo->prepare("
            INSERT INTO delivery_zones (county, area, fee, est_days_min, est_days_max, is_active, created_at)
            VALUES (:county, :area, :fee, :min_days, :max_days, :active, NOW())
        ");
        $stmt->execute([
            ':county'   => $county,
            ':area'     => $area,
            ':fee'      => $fee,
            ':min_days' => $minDays,
            ':max_days' => $maxDays,
            ':active'   => $isActive,
        ]);

        $id = (int)$pdo->lastInsertId();

        AuditLogger::log($actorId, 'delivery_zone_create', 'delivery_zone', $id, [
            'county' => $county,
            'area'   => $area,
            'fee'    => $fee,
        ], $ip);

        return $id;
    }

    public static function updateZone(int $id, array $data, int $actorId, string $ip): void
    {
        $zone = self::getZone($id);
        if (!$zone) {
            throw new DomainException("Delivery zone not found.");
        }

        $county = trim($data['county'] ?? $zone['county']);
        $area = trim($data['area'] ?? $zone['area']);

        if ($county === '' || $area === '') {
            throw new InvalidArgumentException("County and area name are required.");
        }

        $fee = (float)($data['fee'] ?? $zone['fee']);
        if ($fee < 0) {
            throw new InvalidArgumentException("Delivery fee cannot be negative.");
        }
        if (floor($fee) != $fee) {
            throw new InvalidArgumentException("Delivery fee must be a whole shilling amount (e.g. 200, not 200.50).");
        }

        $minDays = max(1, (int)($data['est_days_min'] ?? $zone['est_days_min']));
        $maxDays = max($minDays, (int)($data['est_days_max'] ?? $zone['est_days_max']));

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT id FROM delivery_zones WHERE county = :county AND area = :area AND id != :id LIMIT 1");
        $stmt->execute([':county' => $county, ':area' => $area, ':id' => $id]);
        if ($stmt->fetch()) {
            throw new InvalidArgumentException("Another delivery zone for '{$county} - {$area}' already exists.");
        }

        $isActive = isset($data['is_active']) ? (!empty($data['is_active']) ? 1 : 0) : (int)$zone['is_active'];

        $stmt = $pdo->prepare("
            UPDATE delivery_zones 
            SET county = :county, area = :area, fee = :fee,
                est_days_min = :min_days, est_days_max = :max_days, is_active = :active
            WHERE id = :id
        ");
        $stmt->execute([
            ':county'   => $county,
            ':area'     => $area,
            ':fee'      => $fee,
            ':min_days' => $minDays,
            ':max_days' => $maxDays,
            ':active'   => $isActive,
            ':id'       => $id,
        ]);

        AuditLogger::log($actorId, 'delivery_zone_update', 'delivery_zone', $id, [
            'county' => $county,
            'area'   => $area,
            'fee'    => $fee,
        ], $ip);
    }

    public static function toggleZoneActive(int $id, int $actorId, string $ip): bool
    {
        $zone = self::getZone($id);
        if (!$zone) {
            throw new DomainException("Delivery zone not found.");
        }

        $newActive = $zone['is_active'] ? 0 : 1;
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("UPDATE delivery_zones SET is_active = :active WHERE id = :id");
        $stmt->execute([':active' => $newActive, ':id' => $id]);

        AuditLogger::log($actorId, 'delivery_zone_toggle_active', 'delivery_zone', $id, [
            'county'    => $zone['county'],
            'area'      => $zone['area'],
            'is_active' => $newActive,
        ], $ip);

        return (bool)$newActive;
    }

    public static function deleteZone(int $id, int $actorId, string $ip): void
    {
        $zone = self::getZone($id);
        if (!$zone) {
            throw new DomainException("Delivery zone not found.");
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE county = :county AND area = :area");
        $stmt->execute([':county' => $zone['county'], ':area' => $zone['area']]);
        $orderCount = (int)$stmt->fetchColumn();

        if ($orderCount > 0) {
            throw new DomainException("Cannot delete delivery zone '{$zone['county']} - {$zone['area']}' because {$orderCount} past order(s) reference it. Deactivate the zone instead.");
        }

        $stmt = $pdo->prepare("DELETE FROM delivery_zones WHERE id = :id");
        $stmt->execute([':id' => $id]);

        AuditLogger::log($actorId, 'delivery_zone_delete', 'delivery_zone', $id, [
            'county' => $zone['county'],
            'area'   => $zone['area'],
        ], $ip);
    }
}
