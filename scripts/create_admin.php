<?php
/**
 * ShopWise Kenya - CLI Administrator Account Provisioning Utility
 *
 * Securely provisions a new administrative user from the command line.
 * Synchronizes users.role and user_roles junction table inside an atomic transaction.
 *
 * Security Invariants & Safeguards:
 * 1. CLI Execution Only: strictly terminates if invoked outside CLI runtime.
 * 2. Strict Input Validation: rejects invalid characters, HTML, and script markup in names.
 * 3. Email Uniqueness: verifies email is valid and not already registered.
 * 4. Password Policy: enforces 10+ chars, letter & number requirement, secure bcrypt hashing.
 * 5. RBAC Synchronization: populates users.role and user_roles junction table transactionally.
 * 6. Super Admin Protection: warns if active Super Admins already exist; requires --force or confirmation.
 * 7. Transactional Integrity: rolls back completely on any failure.
 * 8. Forensic Audit Logging: records operator event in audit_log.
 * 9. Dry-Run Mode: supports non-destructive inspection via --dry-run.
 *
 * Usage:
 *   Interactive:
 *     php scripts/create_admin.php
 *
 *   Non-Interactive / Scripted:
 *     php scripts/create_admin.php --first-name=Victor --last-name=Cheroben --email=admin@example.co.ke --password=SecurePass123 --role=super_admin --yes
 *     php scripts/create_admin.php --first-name=Victor --last-name=Cheroben --email=admin@example.co.ke --password=SecurePass123 --dry-run
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    fwrite(STDERR, "Error: This script must be executed via the command line (CLI) only.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Database\Database;
use App\Services\Permissions;
use App\Services\CustomerAuthService;

$options = getopt('', [
    'first-name:',
    'last-name:',
    'email:',
    'phone:',
    'password:',
    'role:',
    'dry-run',
    'yes',
    'force',
    'help',
]);

if (isset($options['help'])) {
    echo <<<HELP
ShopWise Kenya - Administrator Account Provisioning Utility

Usage:
  php scripts/create_admin.php [options]

Options:
  --first-name=<name>   Administrator first name (validated, no HTML/script tags)
  --last-name=<name>    Administrator last name (validated, no HTML/script tags)
  --email=<email>       Administrator work email address (unique)
  --phone=<phone>       Optional Kenyan phone number (e.g. 0712345678)
  --password=<pass>     Account password (min 10 chars, letter + number)
  --role=<role>         Role to assign: 'super_admin' (default) or 'admin'
  --dry-run             Simulate execution without modifying the database
  --yes                 Assume yes; proceed without interactive confirmation prompt
  --force               Allow creating super_admin even if one already exists
  --help                Display this help message

HELP;
    exit(0);
}

echo "==============================================================================\n";
echo "   SHOPWISE KENYA — ADMINISTRATOR ACCOUNT SETUP (CLI)\n";
echo "==============================================================================\n";

$pdo = Database::getInstance();
$isDryRun = isset($options['dry-run']);
$isForced = isset($options['force']);
$autoConfirm = isset($options['yes']);

// Check database connection & schema
$currentDb = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
echo "Active Database: {$currentDb}\n\n";

$stdin = fopen('php://stdin', 'r');

// Helper to prompt for hidden password
function readCliPassword(string $prompt = "Enter Password: "): string
{
    echo $prompt;
    $isTty = function_exists('posix_isatty') ? posix_isatty(STDIN) : (function_exists('stream_isatty') ? stream_isatty(STDIN) : false);

    if (!$isTty || strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        return trim(fgets(STDIN));
    }

    system('stty -echo');
    $pwd = trim(fgets(STDIN));
    system('stty echo');
    echo "\n";
    return $pwd;
}

// 1. First Name
$firstName = isset($options['first-name']) ? trim((string)$options['first-name']) : '';
if ($firstName !== '') {
    [$fnOk, $fnErr] = validate_person_name($firstName, 'First name');
    if (!$fnOk) {
        fwrite(STDERR, " [!] Error: {$fnErr}\n");
        exit(1);
    }
} else {
    do {
        echo "Enter First Name: ";
        $firstName = trim(fgets($stdin));
        [$fnOk, $fnErr] = validate_person_name($firstName, 'First name');
        if (!$fnOk) {
            echo " [!] {$fnErr}\n";
            $firstName = '';
        }
    } while ($firstName === '');
}

// 2. Last Name
$lastName = isset($options['last-name']) ? trim((string)$options['last-name']) : '';
if ($lastName !== '') {
    [$lnOk, $lnErr] = validate_person_name($lastName, 'Last name');
    if (!$lnOk) {
        fwrite(STDERR, " [!] Error: {$lnErr}\n");
        exit(1);
    }
} else {
    do {
        echo "Enter Last Name: ";
        $lastName = trim(fgets($stdin));
        [$lnOk, $lnErr] = validate_person_name($lastName, 'Last name');
        if (!$lnOk) {
            echo " [!] {$lnErr}\n";
            $lastName = '';
        }
    } while ($lastName === '');
}

// 3. Email Address
$email = isset($options['email']) ? strtolower(trim((string)$options['email'])) : '';
if ($email !== '') {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191) {
        fwrite(STDERR, " [!] Error: Invalid email address format.\n");
        exit(1);
    }
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    if ($stmt->fetch()) {
        fwrite(STDERR, " [!] Error: A user with this email address already exists (#" . $stmt->fetchColumn() . ").\n");
        exit(1);
    }
} else {
    do {
        echo "Enter Email Address: ";
        $email = strtolower(trim(fgets($stdin)));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191) {
            echo " [!] Invalid email address format. Please enter a valid email.\n";
            $email = '';
            continue;
        }

        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        if ($stmt->fetch()) {
            echo " [!] A user with this email address already exists. Please use a different email.\n";
            $email = '';
        }
    } while ($email === '');
}

// 4. Optional Phone Number
$phone = null;
if (isset($options['phone'])) {
    $rawPhone = trim((string)$options['phone']);
    if ($rawPhone !== '') {
        $phone = normalize_phone($rawPhone);
        if ($phone === null) {
            fwrite(STDERR, " [!] Warning: Phone number format was invalid; proceeding without phone.\n");
        }
    }
} else {
    echo "Enter Phone Number (e.g. 0712345678, or press Enter to skip): ";
    $rawPhone = trim(fgets($stdin));
    if ($rawPhone !== '') {
        $phone = normalize_phone($rawPhone);
        if ($phone === null) {
            echo " [i] Phone number format was invalid; proceeding without phone number.\n";
        }
    }
}

// 5. Role Selection
$requestedRole = isset($options['role']) ? strtolower(trim((string)$options['role'])) : 'super_admin';
if (!Permissions::isAdminRole($requestedRole)) {
    fwrite(STDERR, " [!] Error: Invalid administrative role '{$requestedRole}'. Allowed: 'super_admin', 'admin'.\n");
    exit(1);
}

// 6. Super Admin Safeguards
$hasUserRoles = (bool)$pdo->query("SHOW TABLES LIKE 'user_roles'")->fetchColumn();
if ($requestedRole === 'super_admin') {
    $existingSuperAdmins = 0;
    if ($hasUserRoles) {
        $existingSuperAdmins = (int)$pdo->query("
            SELECT COUNT(DISTINCT u.id) 
            FROM users u
            JOIN user_roles ur ON u.id = ur.user_id
            WHERE ur.role = 'super_admin' AND u.status = 'active'
        ")->fetchColumn();
    } else {
        $existingSuperAdmins = (int)$pdo->query("
            SELECT COUNT(*) FROM users WHERE role = 'super_admin' AND status = 'active'
        ")->fetchColumn();
    }

    if ($existingSuperAdmins > 0 && !$isForced) {
        echo " [!] Notice: {$existingSuperAdmins} active Super Administrator(s) already exist in the database.\n";
        if (!$autoConfirm && !$isDryRun) {
            echo "     Do you wish to create an additional Super Administrator? (y/N): ";
            $ans = strtolower(trim(fgets($stdin)));
            if ($ans !== 'y' && $ans !== 'yes') {
                echo "Aborted by operator. No changes were made.\n";
                exit(0);
            }
        } else {
            fwrite(STDERR, " [!] Safeguard: Active Super Administrator already exists. Pass --force to create additional Super Admins in non-interactive mode.\n");
            exit(1);
        }
    }
}

// 7. Password
$password = isset($options['password']) ? (string)$options['password'] : '';
if ($password !== '') {
    $policyErr = CustomerAuthService::validatePasswordPolicy($password, $email);
    if ($policyErr !== null) {
        fwrite(STDERR, " [!] Password Policy Error: {$policyErr}\n");
        exit(1);
    }
} else {
    do {
        $password = readCliPassword("Enter Password (min 10 characters, letters + numbers): ");
        $policyErr = CustomerAuthService::validatePasswordPolicy($password, $email);
        if ($policyErr !== null) {
            echo " [!] {$policyErr}\n";
            $password = '';
            continue;
        }

        $confirm = readCliPassword("Confirm Password: ");
        if ($password !== $confirm) {
            echo " [!] Passwords do not match. Please try again.\n";
            $password = '';
        }
    } while ($password === '');
}

// 8. Execution or Dry-Run
echo "\n--- Account Summary ---\n";
echo "Name:     {$firstName} {$lastName}\n";
echo "Email:    {$email}\n";
echo "Phone:    " . ($phone ?? 'None') . "\n";
echo "Role:     {$requestedRole}\n";
echo "Mode:     " . ($isDryRun ? "DRY-RUN (Simulated, no writes)" : "LIVE EXECUTION") . "\n";
echo "-----------------------\n\n";

if ($isDryRun) {
    echo "[DRY-RUN] Would execute INSERT INTO users with role '{$requestedRole}'.\n";
    if ($hasUserRoles) {
        echo "[DRY-RUN] Would execute INSERT INTO user_roles with role '{$requestedRole}'.\n";
    }
    echo "[DRY-RUN] Would record audit event 'admin.provision_cli' in audit_log.\n";
    echo "\n[✓] Dry-run completed successfully. No changes made.\n";
    exit(0);
}

if (!$autoConfirm) {
    echo "Type 'CONFIRM' to provision this account: ";
    $confirmation = trim(fgets($stdin));
    if ($confirmation !== 'CONFIRM') {
        echo "Aborted by operator. No changes were made.\n";
        exit(0);
    }
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);

try {
    $adminId = 0;
    Database::transaction(function (PDO $pdo) use ($firstName, $lastName, $email, $phone, $passwordHash, $requestedRole, $hasUserRoles, &$adminId) {
        // 1. Insert into users
        $stmt = $pdo->prepare("
            INSERT INTO users (role, first_name, last_name, email, phone, password_hash, status, email_verified_at, password_changed_at, created_at, updated_at)
            VALUES (:role, :first_name, :last_name, :email, :phone, :password_hash, 'active', NOW(), NOW(), NOW(), NOW())
        ");
        $stmt->execute([
            ':role'          => $requestedRole,
            ':first_name'    => $firstName,
            ':last_name'     => $lastName,
            ':email'         => $email,
            ':phone'         => $phone,
            ':password_hash' => $passwordHash,
        ]);
        $adminId = (int)$pdo->lastInsertId();

        // 2. Synchronize user_roles junction table
        if ($hasUserRoles) {
            $roleStmt = $pdo->prepare("
                INSERT INTO user_roles (user_id, role, assigned_at, assigned_by)
                VALUES (:uid, :role, NOW(), NULL)
                ON DUPLICATE KEY UPDATE assigned_at = NOW(), assigned_by = NULL
            ");
            $roleStmt->execute([
                ':uid'  => $adminId,
                ':role' => $requestedRole,
            ]);
        }

        // 3. Write structured audit_log entry
        $systemUser = function_exists('posix_getpwuid') && function_exists('posix_geteuid')
            ? (posix_getpwuid(posix_geteuid())['name'] ?? get_current_user())
            : get_current_user();

        $auditDetails = [
            'event'          => 'admin_provisioned_cli',
            'operator_type'  => 'cli_system_operator',
            'system_user'    => $systemUser,
            'cli_script'     => 'scripts/create_admin.php',
            'target_user_id' => $adminId,
            'email'          => $email,
            'assigned_role'  => $requestedRole,
            'timestamp'      => date('c'),
        ];

        $auditStmt = $pdo->prepare("
            INSERT INTO audit_log (actor_user_id, action, entity_type, entity_id, details, ip, created_at)
            VALUES (NULL, 'admin.provision_cli', 'user', :entity_id, :details, '127.0.0.1', NOW())
        ");
        $auditStmt->execute([
            ':entity_id' => $adminId,
            ':details'   => json_encode($auditDetails, JSON_THROW_ON_ERROR),
        ]);
    });

    echo "\n [✓] Administrator account provisioned successfully!\n";
    echo "     ID:    #{$adminId}\n";
    echo "     Name:  {$firstName} {$lastName}\n";
    echo "     Email: {$email}\n";
    echo "     Role:  {$requestedRole}\n";
    if ($hasUserRoles) {
        echo "     RBAC:  user_roles junction synchronized\n";
    }
    echo "     Audit: Logged to audit_log ('admin.provision_cli')\n\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\n [✗] Database error provisioning administrator: " . $e->getMessage() . "\n\n");
    exit(1);
}
