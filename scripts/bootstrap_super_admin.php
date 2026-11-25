<?php
/**
 * ShopWise Kenya - Super Administrator CLI Bootstrap Utility
 *
 * Promotes an existing active user account to Super Administrator.
 *
 * Security Invariants & Operational Safeguards:
 * 1. CLI Execution Only: strictly terminates if invoked outside CLI runtime.
 * 2. Preflight Schema Validation: verifies database schema and required tables/columns exist.
 * 3. Identity Verification: looks up user by ID or Email; verifies account is active.
 * 4. Repeated Bootstrap Protection: warns if a Super Admin already exists; requires --force.
 * 5. Atomic State Synchronization: transactionally updates users.role and user_roles junction table.
 * 6. Audit Logging: creates structured audit_log entry for forensic accountability.
 * 7. Dry-Run Mode: allows testing/inspection without database modification via --dry-run.
 * 8. Explicit Operator Confirmation: requires interactive confirmation or --yes flag.
 *
 * Usage:
 *   php scripts/bootstrap_super_admin.php --user-id=1015
 *   php scripts/bootstrap_super_admin.php --email=cheroben@gmail.com
 *   php scripts/bootstrap_super_admin.php --user-id=1015 --dry-run
 *   php scripts/bootstrap_super_admin.php --user-id=1015 --yes
 *   php scripts/bootstrap_super_admin.php --user-id=1015 --force --yes
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    fwrite(STDERR, "Error: This script must be executed via the command line (CLI) only.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Database\Database;
use App\Services\Permissions;

$options = getopt('', ['user-id:', 'email:', 'dry-run', 'yes', 'force', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
ShopWise Kenya - Super Admin Bootstrap Utility

Usage:
  php scripts/bootstrap_super_admin.php [options]

Options:
  --user-id=<id>     User ID to promote to Super Administrator
  --email=<email>    User email address to promote
  --dry-run          Simulate execution without modifying the database
  --yes              Assume yes; proceed without interactive confirmation prompt
  --force            Allow running even if one or more Super Admins already exist
  --help             Display this help message

HELP;
    exit(0);
}

echo "==============================================================================\n";
echo "   SHOPWISE KENYA — SUPER ADMINISTRATOR BOOTSTRAP UTILITY\n";
echo "==============================================================================\n";

$pdo = Database::getInstance();
$isDryRun = isset($options['dry-run']);
$isForced = isset($options['force']);
$autoConfirm = isset($options['yes']);

// 1. Preflight Schema Checks
echo "\n[1/5] Verifying database schema and configuration...\n";
$currentDb = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
echo "      Active Database: {$currentDb}\n";

$usersTableCheck = $pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
if (!$usersTableCheck) {
    fwrite(STDERR, " [!] Fatal Error: 'users' table does not exist in '{$currentDb}'.\n");
    exit(1);
}

// Check if user_roles table exists
$hasUserRoles = (bool)$pdo->query("SHOW TABLES LIKE 'user_roles'")->fetchColumn();
$hasRolesTable = (bool)$pdo->query("SHOW TABLES LIKE 'roles'")->fetchColumn();

if ($hasUserRoles) {
    echo "      Junction Table:  'user_roles' table is present (Multi-Role Enabled)\n";
} else {
    echo "      Junction Table:  'user_roles' table not found (Legacy Single-Role Mode)\n";
}

if ($hasRolesTable) {
    $superAdminRoleExists = $pdo->query("SELECT COUNT(*) FROM roles WHERE name = 'super_admin'")->fetchColumn();
    if ((int)$superAdminRoleExists === 0) {
        fwrite(STDERR, " [!] Warning: 'super_admin' role is not yet registered in 'roles' table.\n");
    }
}

// 2. Identify Target User Account
echo "\n[2/5] Locating target user account...\n";
$targetUserId = isset($options['user-id']) ? (int)$options['user-id'] : null;
$targetEmail  = isset($options['email']) ? strtolower(trim($options['email'])) : null;

if (!$targetUserId && !$targetEmail) {
    echo "Enter User ID or Email to promote: ";
    $input = trim(fgets(STDIN));
    if (is_numeric($input)) {
        $targetUserId = (int)$input;
    } elseif (filter_var($input, FILTER_VALIDATE_EMAIL)) {
        $targetEmail = strtolower($input);
    } else {
        fwrite(STDERR, " [!] Invalid input. Must provide a numeric User ID or valid Email address.\n");
        exit(1);
    }
}

if ($targetUserId) {
    $stmt = $pdo->prepare("SELECT id, first_name, last_name, email, role, status FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $targetUserId]);
} else {
    $stmt = $pdo->prepare("SELECT id, first_name, last_name, email, role, status FROM users WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $targetEmail]);
}

$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    $identifier = $targetUserId ? "#{$targetUserId}" : "'{$targetEmail}'";
    fwrite(STDERR, " [!] Fatal Error: User account {$identifier} was not found in '{$currentDb}'.\n");
    exit(1);
}

$userId = (int)$user['id'];
echo "      Found Account:   #{$userId} - {$user['first_name']} {$user['last_name']} ({$user['email']})\n";
echo "      Current Role:    {$user['role']}\n";
echo "      Account Status:  {$user['status']}\n";

// Eligibility verification
if ($user['status'] !== 'active') {
    fwrite(STDERR, " [!] Fatal Error: Account #{$userId} is currently '{$user['status']}'. Only 'active' accounts can be elevated.\n");
    exit(1);
}

// 3. Repeated Initialization Protection
echo "\n[3/5] Checking existing administrative privileges...\n";
$existingSuperAdminsCount = 0;
if ($hasUserRoles) {
    $cntStmt = $pdo->query("
        SELECT COUNT(DISTINCT user_id)
        FROM user_roles
        WHERE role IN ('super_admin', 'admin')
    ");
    $existingSuperAdminsCount = (int)$cntStmt->fetchColumn();
} else {
    $cntStmt = $pdo->query("
        SELECT COUNT(*)
        FROM users
        WHERE role IN ('super_admin', 'admin')
    ");
    $existingSuperAdminsCount = (int)$cntStmt->fetchColumn();
}

if ($existingSuperAdminsCount > 0) {
    echo "      Notice: {$existingSuperAdminsCount} Super Administrator / Admin account(s) already exist in '{$currentDb}'.\n";
    if (!$isForced) {
        fwrite(STDERR, " [!] Safety Guard: Repeated bootstrap prevented because active Super Administrator(s) already exist.\n");
        fwrite(STDERR, "     Use --force if you intentionally wish to add an additional Super Administrator.\n");
        exit(1);
    } else {
        echo "      Override: --force specified. Proceeding with elevation.\n";
    }
} else {
    echo "      Verified: Zero Super Administrators exist. Clean bootstrap initialization.\n";
}

// 4. Operator Confirmation
echo "\n[4/5] Elevation Confirmation\n";
echo "      Target User:     #{$userId} - {$user['first_name']} {$user['last_name']} ({$user['email']})\n";
echo "      New Role:        super_admin (Complete Platform Oversight)\n";
echo "      Execution Mode:  " . ($isDryRun ? "DRY-RUN (Simulated, no changes will be written)" : "LIVE EXECUTION") . "\n";

if (!$autoConfirm && !$isDryRun) {
    echo "\nType 'CONFIRM' to proceed with elevation: ";
    $confirmation = trim(fgets(STDIN));
    if ($confirmation !== 'CONFIRM') {
        echo "Aborted by operator. No changes were made.\n";
        exit(0);
    }
}

// 5. Atomic Transaction Execution
echo "\n[5/5] Executing role elevation...\n";

if ($isDryRun) {
    echo "      [DRY-RUN] Would execute: UPDATE users SET role = 'super_admin' WHERE id = {$userId};\n";
    if ($hasUserRoles) {
        echo "      [DRY-RUN] Would execute: INSERT INTO user_roles (user_id, role, assigned_at, assigned_by) VALUES ({$userId}, 'super_admin', NOW(), {$userId}) ON DUPLICATE KEY UPDATE assigned_at = NOW();\n";
    }
    echo "      [DRY-RUN] Would record audit event 'user.bootstrap_super_admin' in audit_log.\n";
    echo "\n==============================================================================\n";
    echo "  DRY-RUN COMPLETED SUCCESSFULLY — NO DATABASE WRITES PERFORMED\n";
    echo "==============================================================================\n";
    exit(0);
}

try {
    $pdo->beginTransaction();

    // A. Update primary users table
    $upUser = $pdo->prepare("
        UPDATE users 
        SET role = 'super_admin' 
        WHERE id = :id
    ");
    $upUser->execute([':id' => $userId]);

    // B. Synchronize user_roles junction table if present
    // Truthful attribution: assigned_by is NULL because this is an out-of-band CLI operator bootstrap
    if ($hasUserRoles) {
        $insRole = $pdo->prepare("
            INSERT INTO user_roles (user_id, role, assigned_at, assigned_by)
            VALUES (:uid, 'super_admin', NOW(), NULL)
            ON DUPLICATE KEY UPDATE assigned_at = NOW(), assigned_by = NULL
        ");
        $insRole->execute([
            ':uid' => $userId,
        ]);
    }

    // C. Write forensic audit log entry
    // Truthful attribution: actor_user_id is NULL because the action was executed by the out-of-band CLI operator
    $systemUser = function_exists('posix_getpwuid') && function_exists('posix_geteuid')
        ? (posix_getpwuid(posix_geteuid())['name'] ?? get_current_user())
        : get_current_user();

    $auditDetails = [
        'event'          => 'bootstrap_super_admin',
        'operator_type'  => 'cli_system_operator',
        'system_user'    => $systemUser,
        'cli_script'     => 'scripts/bootstrap_super_admin.php',
        'target_user_id' => $userId,
        'email'          => $user['email'],
        'previous_role'  => $user['role'],
        'assigned_role'  => 'super_admin',
        'timestamp'      => date('c'),
    ];

    $auditStmt = $pdo->prepare("
        INSERT INTO audit_log (actor_user_id, action, entity_type, entity_id, details, ip, created_at)
        VALUES (NULL, 'user.bootstrap_super_admin', 'user', :entity, :details, '127.0.0.1', NOW())
    ");
    $auditStmt->execute([
        ':entity'  => $userId,
        ':details' => json_encode($auditDetails, JSON_THROW_ON_ERROR),
    ]);

    $pdo->commit();

    echo "      [✓] users.role updated to 'super_admin'\n";
    if ($hasUserRoles) {
        echo "      [✓] user_roles junction table populated with 'super_admin'\n";
    }
    echo "      [✓] Audit log entry recorded: 'user.bootstrap_super_admin'\n";

    echo "\n==============================================================================\n";
    echo "  SUPER ADMINISTRATOR ELEVATION COMPLETED SUCCESSFULLY\n";
    echo "==============================================================================\n";
    echo "  User:  {$user['first_name']} {$user['last_name']} ({$user['email']})\n";
    echo "  ID:    #{$userId}\n";
    echo "  Role:  super_admin\n";
    echo "==============================================================================\n";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "\n [✗] Database Transaction Failed: " . $e->getMessage() . "\n");
    exit(1);
}
