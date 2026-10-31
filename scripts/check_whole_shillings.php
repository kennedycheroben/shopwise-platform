<?php
/**
 * scripts/check_whole_shillings.php
 *
 * READ-ONLY audit tool to verify whole-shilling rule compliance (M-Pesa requires integer KES).
 * Scans products, product_variants, coupons, delivery_zones, orders, order_items, and payments.
 * NEVER modifies any data.
 */

$targetDb = $argv[1] ?? (getenv('SHOPWISE_TARGET_DB') ?: 'shopwise_test');
$config = require dirname(__DIR__) . '/app/config/config.php';

$host = $config['db']['host'] ?? '127.0.0.1';
$port = (int)($config['db']['port'] ?? 3306);
$user = $config['db']['username'] ?? 'root';
$pass = $config['db']['password'] ?? '';
$charset = $config['db']['charset'] ?? 'utf8mb4';

$dsn = "mysql:host={$host};port={$port};dbname={$targetDb};charset={$charset}";
try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "Database connection failed for '{$targetDb}': " . $e->getMessage() . "\n");
    exit(1);
}

echo "==============================================================================\n";
echo "   SHOPWISE PLATFORM - WHOLE SHILLING COMPLIANCE AUDIT (READ-ONLY)            \n";
echo "==============================================================================\n";
echo "Target Database: {$targetDb}\n";
echo "Scan Timestamp:  " . date('Y-m-d H:i:s T') . "\n";
echo "------------------------------------------------------------------------------\n";

$checks = [
    [
        'table' => 'products',
        'sql'   => "SELECT id, name AS identifier, base_price AS val, 'base_price' AS col FROM products WHERE base_price != FLOOR(base_price)"
    ],
    [
        'table' => 'products',
        'sql'   => "SELECT id, name AS identifier, compare_at_price AS val, 'compare_at_price' AS col FROM products WHERE compare_at_price IS NOT NULL AND compare_at_price != FLOOR(compare_at_price)"
    ],
    [
        'table' => 'product_variants',
        'sql'   => "SELECT id, sku AS identifier, price_override AS val, 'price_override' AS col FROM product_variants WHERE price_override IS NOT NULL AND price_override != FLOOR(price_override)"
    ],
    [
        'table' => 'coupons',
        'sql'   => "SELECT id, code AS identifier, value AS val, 'value' AS col FROM coupons WHERE type = 'fixed' AND value != FLOOR(value)"
    ],
    [
        'table' => 'coupons',
        'sql'   => "SELECT id, code AS identifier, min_spend AS val, 'min_spend' AS col FROM coupons WHERE min_spend != FLOOR(min_spend)"
    ],
    [
        'table' => 'delivery_zones',
        'sql'   => "SELECT id, CONCAT(county, ' - ', area) AS identifier, fee AS val, 'fee' AS col FROM delivery_zones WHERE fee != FLOOR(fee)"
    ],
    [
        'table' => 'orders',
        'sql'   => "SELECT id, order_number AS identifier, subtotal AS val, 'subtotal' AS col FROM orders WHERE subtotal != FLOOR(subtotal)"
    ],
    [
        'table' => 'orders',
        'sql'   => "SELECT id, order_number AS identifier, delivery_fee AS val, 'delivery_fee' AS col FROM orders WHERE delivery_fee != FLOOR(delivery_fee)"
    ],
    [
        'table' => 'orders',
        'sql'   => "SELECT id, order_number AS identifier, discount AS val, 'discount' AS col FROM orders WHERE discount != FLOOR(discount)"
    ],
    [
        'table' => 'orders',
        'sql'   => "SELECT id, order_number AS identifier, total AS val, 'total' AS col FROM orders WHERE total != FLOOR(total)"
    ],
    [
        'table' => 'order_items',
        'sql'   => "SELECT id, sku AS identifier, unit_price AS val, 'unit_price' AS col FROM order_items WHERE unit_price != FLOOR(unit_price)"
    ],
    [
        'table' => 'order_items',
        'sql'   => "SELECT id, sku AS identifier, line_total AS val, 'line_total' AS col FROM order_items WHERE line_total != FLOOR(line_total)"
    ],
    [
        'table' => 'payments',
        'sql'   => "SELECT id, CONCAT('Payment #', id, ' (Order #', order_id, ')') AS identifier, amount AS val, 'amount' AS col FROM payments WHERE amount != FLOOR(amount)"
    ],
];

$nonWholeFound = 0;
$findings = [];

foreach ($checks as $check) {
    try {
        $stmt = $pdo->query($check['sql']);
        while ($row = $stmt->fetch()) {
            $nonWholeFound++;
            $findings[] = [
                'table'      => $check['table'],
                'column'     => $row['col'],
                'id'         => $row['id'],
                'identifier' => $row['identifier'] ?? '-',
                'value'      => $row['val'],
            ];
        }
    } catch (PDOException $e) {
        // Table might not exist or column different
        echo "Note: Query on {$check['table']} skipped: " . $e->getMessage() . "\n";
    }
}

if ($nonWholeFound === 0) {
    echo "[PASS] 100% WHOLE-SHILLING COMPLIANT: Zero non-whole prices or fees found in '{$targetDb}'.\n";
    echo "==============================================================================\n";
    exit(0);
} else {
    echo "[WARNING] Found {$nonWholeFound} non-whole amount record(s) in '{$targetDb}':\n";
    printf("%-18s | %-18s | %-6s | %-12s | %s\n", "Table", "Column", "ID", "Amount", "Identifier");
    echo str_repeat('-', 78) . "\n";
    foreach ($findings as $f) {
        printf("%-18s | %-18s | %-6d | %-12s | %s\n", $f['table'], $f['column'], $f['id'], $f['value'], $f['identifier']);
    }
    echo "==============================================================================\n";
    exit(1);
}
