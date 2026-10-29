# ShopWise Platform - Testing & Data Integrity Rules

This document outlines mandatory rules and engineering standards governing all test scripts, acceptance runners, and debugging commands in the ShopWise Platform repository.

---

## 1. Audit Log Invariant & Deletion Rules
- **Rule 1.1: Never Delete Real Audit Rows.** Real business audit history (including demo or seed audit entries) must NEVER be deleted.
- **Rule 1.2: Strict ID-Bounded Cleanup.** Acceptance test scripts may delete ONLY audit log rows whose exact IDs were created by the script during its run, recorded dynamically at runtime (e.g. `WHERE id > $PRE_TEST_MAX_AUDIT_ID` or specific row IDs collected during execution).
- **Rule 1.3: No Guessed Thresholds.** Arbitrary thresholds such as `WHERE id > 20` or `WHERE id >= 500` are strictly forbidden.
- **Rule 1.4: Never Alter Test Baselines.** Test baselines must never be artificially adjusted or harmonized by deleting historical data.

---

## 2. Test Accounts & Email Safety
- **Rule 2.1: Throwaway Accounts Only.** All test accounts must be provisioned dynamically with random identifiers and use the `@test.local` domain (e.g., `test_admin_xyz123@test.local`).
- **Rule 2.2: Prohibition of Real Email Addresses.** Real, production, or personal email addresses (any domain other than `@test.local`) must NEVER appear in any test script, curl command, SQL statement, debug snippet, or documentation.
- **Rule 2.3: Targeted User Cleanup.** Scripts must clean up only their own provisioned throwaway users (matching their unique execution ID or matching `email LIKE '%@test.local'`).

---

## 3. Database Catalog & Data Parity
- **Rule 3.1: Catalog Invariant Assertions.** Every acceptance test script must snapshot and assert at exit that the product catalog has zero drift:
  - `products = 21`
  - `product_variants = 66`
  - `categories = 7`
- **Rule 3.2: Stock Ledger Invariant.** Live inventory (`product_variants.stock_qty`) must always match the ledger invariant:
  $$\text{Expected Stock} = \text{Seed Stock} + \sum (\text{inventory\_movements.change\_qty})$$
- **Rule 3.3: No Hand-Editing Data.** Developers and test runners must NEVER hand-edit `stock_qty`, `orders`, `audit_log`, or any data table to force a number match. Any discrepancy must be resolved via valid application transactions or an approved, auditable inventory movement.

---

## 4. Test Script Architecture & Cleanup Traps
- **Rule 4.1: Unconditional Cleanup Traps.** Every script must register an unconditional `trap cleanup EXIT INT TERM` to guarantee cleanup of temporary cookie jars, request bodies, temporary files, and throwaway database rows even if a test fails midway.
- **Rule 4.2: Defensive Variable Expansion.** When deleting temporary files, guard variables against empty strings (e.g. `if [ -n "${TMP_FILE:-}" ]; then rm -f "$TMP_FILE"; fi`) to prevent accidental path expansion.
- **Rule 4.3: Strict Error Handling.** Test runners must execute with `set -euo pipefail` where applicable, or explicitly check HTTP status codes and API payloads.
- **Rule 4.4: Never Weaken or Delete Tests.** Tests that fail must never be deleted, commented out, or weakened. If a test's assertion is overly broad or flawed, refine it to test the exact same invariant more precisely and document the rationale.

---

## 5. Dedicated Test Database & Checksum Integrity
- **Rule 5.1: Dedicated Disposable Test Database (`shopwise_test`).** All automated tests, acceptance runners, and QA scripts must execute strictly against `shopwise_test`. The working database (`shopwise_db`) is strictly protected and must never be read, written, dropped, truncated, or imported into by any test script.
- **Rule 5.2: Strict Database Name Guards.** Any script or helper performing database initialization, resets, or migrations must actively inspect the target database name and refuse execution if the database equals `shopwise_db` or does not end with `_test`.
- **Rule 5.3: Host and Environment Configuration Isolation.** Automated testing configuration (`config.testing.php`) is loaded by `config.php` ONLY when `app.environment = 'local'` AND (the HTTP request host is strictly `127.0.0.1` OR CLI environment variable `SHOPWISE_CONFIG=testing` is set). In production, testing configuration overrides are impossible.
- **Rule 5.4: Zero Checksum Drift Invariant.** The master test runner (`scripts/run_all_tests.sh`) records `CHECKSUM TABLE` for all 25 tables in `shopwise_db` and counts files in `public/uploads` before and after each test run. The test run fails immediately if even a single byte or file changed in `shopwise_db` or `public/uploads`.
- **Rule 5.5: Upload & Storage Isolation.** Test uploads are saved exclusively to the git-ignored `public/uploads_test/` directory, and test rate limits are saved to `app/storage/rate_limits_test/`. Both directories are secured with `.htaccess` and created with permissions `0755` (`0644` for files). Permissions `0777` are strictly prohibited across all code and documentation.
- **Rule 5.6: Mandatory Phase Backups.** Before beginning any new development phase or executing system maintenance, developers must create a timestamped database backup using `bash scripts/backup_db.sh`.

---

## 6. Truthful Reporting & Output Verification Standards
- **Rule 6.1: Real Command Outputs Only.** Reports and documentation may paste ONLY real, verbatim output produced by an executed command. Never fabricate, hallucinate, simulate, predict, or paraphrase outputs.
- **Rule 6.2: Mandatory Log File Attribution.** Any summary, status table, or metric claimed in reports must explicitly name the exact file path or execution log file it came from.
- **Rule 6.3: Unproven Claims Marked UNKNOWN.** Any fact, metric, or historical behavior that cannot be directly proven with an execution log or database query must be explicitly marked UNKNOWN.
