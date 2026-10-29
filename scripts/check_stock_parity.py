#!/usr/bin/env python3
"""
ShopWise Kenya - Variant Stock Parity Checker
Verifies the LEDGER invariant across all variants in product_variants:
- For seeded variants:
    expected = seed.sql initial stock + SUM(inventory_movements.change_qty WHERE reason != 'initial')
    (Note: seed.sql records initial intake as reason='initial'; if present in movements,
     SUM(all movements) equals seed_initial + SUM(non_initial movements))
- For unseeded / dynamically created variants:
    expected = SUM(all inventory_movements.change_qty)
- Any variant where product_variants.stock_qty differs from ledger expected is reported as a mismatch.
Exits 0 on perfect ledger parity, 1 on any discrepancy.
"""
import os
import re
import subprocess
import json
import sys

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(BASE_DIR)

# 1. Parse seed.sql for initial variant stocks
seed_path = os.path.join(BASE_DIR, "database/seed.sql")
seed_stock = {}
if os.path.exists(seed_path):
    with open(seed_path, "r", encoding="utf-8") as f:
        seed_content = f.read()
    pattern = re.compile(
        r"\(\s*(\d+)\s*,\s*(\d+)\s*,\s*'([^']*)'\s*,\s*'([^']*)'\s*,\s*'([^']*)'\s*,\s*('[^']*'|NULL)\s*,\s*([^,]+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)"
    )
    for m in pattern.findall(seed_content):
        var_id = int(m[0])
        stock = int(m[7])
        seed_stock[var_id] = stock

# 2. Query live database using PHP bootstrap / PDO
php_bin = "/opt/lampp/bin/php" if os.path.exists("/opt/lampp/bin/php") else "php"
query_code = r"""
require 'app/bootstrap.php';
$pdo = App\Database\Database::getInstance();

$variants = $pdo->query("SELECT id, sku, stock_qty FROM product_variants ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$movements = $pdo->query("SELECT variant_id, change_qty, reason FROM inventory_movements")->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['variants' => $variants, 'movements' => $movements]);
"""

try:
    env = dict(os.environ)
    if "SHOPWISE_CONFIG" not in env:
        env["SHOPWISE_CONFIG"] = "testing"
    proc = subprocess.run([php_bin, "-r", query_code], env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True)
    data = json.loads(proc.stdout.decode("utf-8"))
except Exception as e:
    print(f"Error querying database via PHP bootstrap: {e}", file=sys.stderr)
    sys.exit(1)

variants = data["variants"]
movements = data["movements"]

# Aggregate movements per variant
mov_totals = {}
mov_initial = {}
mov_other = {}
for m in movements:
    vid = int(m["variant_id"])
    qty = int(m["change_qty"])
    mov_totals[vid] = mov_totals.get(vid, 0) + qty
    if m["reason"] == "initial":
        mov_initial[vid] = mov_initial.get(vid, 0) + qty
    else:
        mov_other[vid] = mov_other.get(vid, 0) + qty

print("=" * 80)
print("Stock Ledger Invariant Verification (scripts/check_stock_parity.py)")
print("=" * 80)
print(f"Total variants evaluated: {len(variants)}")

diffs = []
total_live_stock = 0
total_expected_stock = 0

for v in variants:
    vid = int(v["id"])
    actual_stock = int(v["stock_qty"])
    sku = v["sku"]
    total_live_stock += actual_stock

    if vid in seed_stock:
        seed_init = seed_stock[vid]
        other_delta = mov_other.get(vid, 0)
        expected_stock = seed_init + other_delta
    else:
        expected_stock = mov_totals.get(vid, 0)

    total_expected_stock += expected_stock

    if actual_stock != expected_stock:
        diffs.append({
            "id": vid,
            "sku": sku,
            "expected": expected_stock,
            "actual": actual_stock,
            "seed_init": seed_stock.get(vid, "N/A"),
            "movements_total": mov_totals.get(vid, 0),
        })

print(f"DB Total Live Stock:     {total_live_stock}")
print(f"Ledger Expected Stock:   {total_expected_stock}")
print(f"Ledger Discrepancies:    {len(diffs)}")

if diffs:
    print("\nFAILURE: Ledger discrepancies detected (stock change without inventory movement):")
    for d in diffs:
        print(f"  Variant #{d['id']} ({d['sku']}): Expected {d['expected']} vs Actual DB {d['actual']} (seed={d['seed_init']}, mov_total={d['movements_total']})")
    sys.exit(1)
else:
    print(f"\nSUCCESS: ALL {len(variants)} VARIANTS MATCH LEDGER INVARIANT PERFECTLY!")
    print(f"Total Live Stock ({total_live_stock}) == Total Ledger Stock ({total_expected_stock})")
    sys.exit(0)
