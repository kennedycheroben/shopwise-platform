<?php
/**
 * ShopWise Kenya - Reusable Local Development Account Configurator
 *
 * Configures an authorized local development account with runtime-supplied inputs.
 * Strictly restricted to local development environment.
 *
 * Security & Integrity Invariants:
 * 1. CLI Execution Only: strictly terminates if invoked outside CLI runtime.
 * 2. Environment & Database Guard: confirms active environment is strictly 'local'
 *    and database matches local development database before modifying anything.
 * 3. Runtime Inputs: accepts email, first_name, last_name, and password via CLI
 *    options or STDIN. Zero hardcoded credentials or email defaults.
 * 4. Password Security: hashes password with password_hash(..., PASSWORD_DEFAULT).
 *    Never logs, prints, or exposes the password.
 * 5. Field Preservation: updates only names, password_hash, updated_at, and role;
 *    leaves email_verified_at, phone, status, and other fields unchanged.
 * 6. Audit Logging: records forensic audit entry.
 * 7. Verification: authenticates against AdminAuthService and verifies Super Admin role.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    fwrite(STDERR, "Error: This script must be executed via CLI only.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Database\Database;
use App\Services\AdminAuthService;
use App\Services\Permissions;

$options = getopt('', [
    'email:',
    'first-name:',
    'last-name:',
    'password:',
    'password-stdin',
    'role:',
    'help',
]);

if (isset($options['help'])) {
    echo <<<HELP
ShopWise Kenya - Local Development Account Configurator

Usage:
  php scripts/configure_dev_account.php [options]

Options:
  --email=<email>           Account email address
  --first-name=<name>       Account first name
  --last-name=<name>        Account last name
  --password=<pass>         Account password (or use --password-stdin)
  --password-stdin          Read password securely from STDIN
  --role=<role>             Administrative role to assign (default: super_admin)
  --help                    Show this help message

HELP;
    exit(0);
}

// 1. Environment & Database Guard
$appEnv = $config['app']['environment'] ?? '';
if ($appEnv !== 'local') {
    fwrite(STDERR, "FATAL: This configuration tool is strictly restricted to local development environments.\n" .
                   "       Current environment: '{$appEnv}'. Aborting.\n");
    exit(1);
}

$pdo = Database::getInstance();
$activeDb = $pdo->query("SELECT DATABASE()")->fetchColumn();
$configuredDb = $config['db']['database'] ?? '';

if ($activeDb !== $configuredDb || $activeDb === 'shopwise_test') {
    fwrite(STDERR, "FATAL: Database mismatch or test database detected.\n" .
                   "       Active DB: '{$activeDb}', Configured DB: '{$configuredDb}'. Aborting.\n");
    exit(1);
}

echo "==============================================================================\n";
echo "   ShopWise Kenya - Development Account Configurator\n";
echo "==============================================================================\n";
echo "Target Environment: {$appEnv}\n";
echo "Active Database:    {$activeDb}\n\n";

// 2. Read runtime inputs
$email = trim((string)($options['email'] ?? ''));
if ($email === '') {
    echo "Enter account email: ";
    $email = trim((string)fgets(STDIN));
}

$firstName = trim((string)($options['first-name'] ?? ''));
if ($firstName === '') {
    echo "Enter first name: ";
    $firstName = trim((string)fgets(STDIN));
}

$lastName = trim((string)($options['last-name'] ?? ''));
if ($lastName === '') {
    echo "Enter last name: ";
    $lastName = trim((string)fgets(STDIN));
}

$password = '';
if (isset($options['password-stdin'])) {
    $password = trim((string)stream_get_contents(STDIN));
} elseif (isset($options['password'])) {
    $password = (string)$options['password'];
} else {
    echo "Enter temporary password: ";
    $password = trim((string)fgets(STDIN));
}

// 3. Validate inputs
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "FATAL: A valid email address is required.\n");
    exit(1);
}
if ($firstName === '' || $lastName === '') {
    fwrite(STDERR, "FATAL: Both first name and last name are required.\n");
    exit(1);
}
if ($password === '') {
    fwrite(STDERR, "FATAL: Password cannot be empty.\n");
    exit(1);
}

$targetRole = trim((string)($options['role'] ?? 'super_admin'));
$validRoles = array_keys(Permissions::matrix());
if (!in_array($targetRole, $validRoles, true)) {
    fwrite(STDERR, "FATAL: Invalid role '{$targetRole}'.\n");
    exit(1);
}

// 4. Query existing account
$stmt = $pdo->prepare("SELECT id, email, first_name, last_name, role, status, email_verified_at, phone FROM users WHERE email = :email");
$stmt->execute([':email' => $email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    fwrite(STDERR, "FATAL: Account with email '{$email}' does not exist in database '{$activeDb}'.\n");
    exit(1);
}

$userId = (int)$user['id'];
echo "[1/3] Located existing user account #{$userId} ({$user['email']})\n";
echo "      Current status: {$user['status']}, Email verification: " . ($user['email_verified_at'] ? 'Verified' : 'Pending') . "\n";

// 5. Update names, password hash, and role atomically
echo "[2/3] Updating account attributes and elevating role to '{$targetRole}'...\n";
$hash = password_hash($password, PASSWORD_DEFAULT);

$pdo->beginTransaction();
try {
    // A. Update primary users table (only names, password_hash, role, updated_at)
    $stmt = $pdo->prepare("
        UPDATE users
        SET first_name = :first_name,
            last_name = :last_name,
            password_hash = :hash,
            role = :role,
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':first_name' => $firstName,
        ':last_name'  => $lastName,
        ':hash'       => $hash,
        ':role'       => $targetRole,
        ':id'         => $userId,
    ]);

    // B. Ensure role in user_roles table
    $pdo->prepare("
        INSERT INTO user_roles (user_id, role, assigned_at)
        VALUES (:uid, :role, NOW())
        ON DUPLICATE KEY UPDATE assigned_at = NOW()
    ")->execute([
        ':uid'  => $userId,
        ':role' => $targetRole,
    ]);

    // C. Record audit log entry
    $pdo->prepare("
        INSERT INTO audit_log (actor_user_id, action, entity_type, entity_id, details, ip, created_at)
        VALUES (:actor, 'user.configure_dev_account', 'user', :id, :details, '127.0.0.1', NOW())
    ")->execute([
        ':actor'   => $userId,
        ':id'      => $userId,
        ':details' => json_encode([
            'email'      => $email,
            'role'       => $targetRole,
            'first_name' => $firstName,
            'last_name'  => $lastName,
        ]),
    ]);

    $pdo->commit();
    echo "      ✓ Account attributes successfully updated.\n";
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "FATAL: Database error during account configuration: " . $e->getMessage() . "\n");
    exit(1);
}

// 6. Verify authentication and Super Admin role locally
echo "[3/3] Verifying local authentication and role permissions...\n";

// Clear any failed login attempt lockouts for this identifier so verification runs cleanly
$pdo->prepare("DELETE FROM login_attempts WHERE identifier = :ident AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)")
    ->execute([':ident' => $email]);

[$authOk, $authPayload] = AdminAuthService::login($email, $password, '127.0.0.1');

$assignedRoles = Permissions::rolesForUser($userId, true);
$hasTargetRole = in_array($targetRole, $assignedRoles, true);

if ($authOk && $hasTargetRole) {
    echo "\n==============================================================================\n";
    echo "  SUCCESS: DEVELOPMENT ACCOUNT CONFIGURED AND VERIFIED LOCALLY\n";
    echo "==============================================================================\n";
    echo "  Database:           {$activeDb}\n";
    echo "  User ID:            #{$userId}\n";
    echo "  Email:              {$email}\n";
    echo "  Name:               {$firstName} {$lastName}\n";
    echo "  Assigned Roles:     " . implode(', ', $assignedRoles) . "\n";
    echo "  Primary Role:       {$targetRole}\n";
    echo "  Account Status:     {$user['status']}\n";
    echo "  Email Verification: " . ($user['email_verified_at'] ? 'Verified' : 'Pending (Unchanged)') . "\n";
    echo "  Local Auth Test:    PASSED (Admin credentials verified via AdminAuthService)\n";
    echo "==============================================================================\n";
    exit(0);
} else {
    $authErr = is_array($authPayload) ? ($authPayload['message'] ?? 'Authentication failed') : (string)$authPayload;
    fwrite(STDERR, "\nERROR: Post-configuration verification failed.\n" .
                   "       Auth Success: " . ($authOk ? 'YES' : "NO ({$authErr})") . "\n" .
                   "       Target Role:  " . ($hasTargetRole ? 'YES' : 'NO') . "\n");
    exit(1);
}
