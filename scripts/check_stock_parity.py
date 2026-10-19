#!/usr/bin/env python3
"""
ShopWise Kenya - Variant Stock Parity Checker
Calculates expected stock for each variant from database/seed.sql + database/demo_orders.sql
and validates against the active database state.
Exit code 0 on perfect parity, 1 on mismatch or extra variants.
"""
import re
import subprocess
import sys
import os

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(BASE_DIR)

MYSQL_CMD = "/opt/lampp/bin/mysql"
if not os.path.exists(MYSQL_CMD):
    MYSQL_CMD = "mysql"

# 1. Parse seed.sql for initial variant stocks
seed_path = os.path.join(BASE_DIR, "database/seed.sql")
with open(seed_path, "r", encoding="utf-8") as f:
    seed_content = f.read()

pattern = re.compile(
    r"\(\s*(\d+)\s*,\s*(\d+)\s*,\s*'([^']*)'\s*,\s*'([^']*)'\s*,\s*'([^']*)'\s*,\s*('[^']*'|NULL)\s*,\s*([^,]+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)"
)
seed_matches = pattern.findall(seed_content)

seed_stock = {}
for m in seed_matches:
    var_id = int(m[0])
    stock = int(m[7])
    seed_stock[var_id] = stock

print(f"Matched {len(seed_matches)} variants in seed.sql. Total initial seed stock: {sum(seed_stock.values())}")

# 2. Parse demo_orders.sql for inventory movements
demo_path = os.path.join(BASE_DIR, "database/demo_orders.sql")
with open(demo_path, "r", encoding="utf-8") as f:
    demo_content = f.read()

im_pattern = re.compile(r"\(\s*(\d+)\s*,\s*(-?\d+)\s*,\s*'([^']*)'\s*,\s*(\d+)\s*,")
im_matches = im_pattern.findall(demo_content)
demo_effects = {}
for m in im_matches:
    var_id = int(m[0])
    change = int(m[1])
    demo_effects[var_id] = demo_effects.get(var_id, 0) + change

print(f"Demo orders net inventory changes: {demo_effects}")

# 3. Query current variants and stock from DB
cmd = [MYSQL_CMD, "-u", "root", "shopwise_db", "-N", "-e", "SELECT id, stock_qty FROM product_variants ORDER BY id ASC;"]
try:
    res = subprocess.check_output(cmd).decode("utf-8")
except Exception as e:
    print(f"Failed to query MySQL: {e}", file=sys.stderr)
    sys.exit(1)

db_stock = {}
for line in res.strip().split("\n"):
    if line.strip():
        parts = line.split()
        db_stock[int(parts[0])] = int(parts[1])

print(f"DB variants count: {len(db_stock)}. DB Total stock: {sum(db_stock.values())}")

# 4. Check for unexpected variants (id > 66 or unknown)
extra_variants = [v for v in db_stock.keys() if v not in seed_stock]
if extra_variants:
    print(f"ERROR: Found {len(extra_variants)} unexpected variant(s) in DB not in seed.sql: {extra_variants}")

# 5. Check variant-by-variant parity
diffs = []
for var_id in sorted(seed_stock.keys()):
    initial = seed_stock[var_id]
    net_demo = demo_effects.get(var_id, 0)
    expected = initial + net_demo
    actual = db_stock.get(var_id)
    if actual is None:
        diffs.append((var_id, initial, net_demo, expected, "MISSING_IN_DB"))
    elif actual != expected:
        diffs.append((var_id, initial, net_demo, expected, actual))

if diffs or extra_variants or len(db_stock) != 66:
    print(f"FAILURE: {len(diffs)} variant(s) differ from expected:")
    for d in diffs:
        print(f"  Variant #{d[0]}: seed={d[1]}, demo_net={d[2]}, expected={d[3]}, actual_db={d[4]}")
    sys.exit(1)
else:
    expected_total = sum(seed_stock.values()) + sum(demo_effects.values())
    actual_total = sum(db_stock.values())
    print("SUCCESS: ALL 66 VARIANTS MATCH EXPECTED (seed.sql + demo_orders net inventory)!")
    print(f"Calculated Total Stock: {expected_total} == DB Total Stock: {actual_total}")
    sys.exit(0)
