<?php
/**
 * ShopWise Kenya - CLI Administrator Provisioning Script
 * Usage: php scripts/create_admin.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Error: This script must be run from the command line (CLI) only.\n");
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Database\Database;

echo "\n=======================================================\n";
echo "    ShopWise Kenya - Administrator Account Setup\n";
echo "=======================================================\n\n";

$stdin = fopen('php://stdin', 'r');

// 1. First Name
do {
    echo "Enter First Name: ";
    $firstName = trim(fgets($stdin));
    if ($firstName === '') {
        echo " [!] First name cannot be empty.\n";
    }
} while ($firstName === '');

// 2. Last Name
do {
    echo "Enter Last Name: ";
    $lastName = trim(fgets($stdin));
    if ($lastName === '') {
        echo " [!] Last name cannot be empty.\n";
    }
} while ($lastName === '');

// 3. Email Address
$pdo = Database::getInstance();
do {
    echo "Enter Email Address: ";
    $email = strtolower(trim(fgets($stdin)));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo " [!] Invalid email address format. Please enter a valid email.\n";
        continue;
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
    $stmt->execute([':email' => $email]);
    if ($stmt->fetch()) {
        echo " [!] A user with this email address already exists. Please use a different email.\n";
        $email = '';
    }
} while ($email === '');

// 4. Optional Phone Number
echo "Enter Phone Number (e.g. 0712345678, or press Enter to skip): ";
$rawPhone = trim(fgets($stdin));
$phone = null;
if ($rawPhone !== '') {
    $phone = normalize_phone($rawPhone);
    if ($phone === null) {
        echo " [i] Phone number format was invalid; proceeding without phone number.\n";
    }
}

// 5. Password with hidden/masked prompt (supports interactive TTY and automated pipes)
function readHiddenPassword(string $prompt = "Enter Password: "): string
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

do {
    $password = readHiddenPassword("Enter Password (min 8 characters): ");
    if (strlen($password) < 8) {
        echo " [!] Password must be at least 8 characters long.\n";
        continue;
    }

    $confirm = readHiddenPassword("Confirm Password: ");
    if ($password !== $confirm) {
        echo " [!] Passwords do not match. Please try again.\n";
        $password = '';
    }
} while ($password === '');

// 6. Create Admin Account
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

try {
    $stmt = $pdo->prepare("
        INSERT INTO users (role, first_name, last_name, email, phone, password_hash, status, email_verified_at, created_at)
        VALUES ('admin', :first_name, :last_name, :email, :phone, :password_hash, 'active', NOW(), NOW())
    ");
    $stmt->execute([
        ':first_name'    => $firstName,
        ':last_name'     => $lastName,
        ':email'         => $email,
        ':phone'         => $phone,
        ':password_hash' => $passwordHash,
    ]);

    $adminId = $pdo->lastInsertId();

    // Log to audit table
    $auditStmt = $pdo->prepare("
        INSERT INTO audit_log (actor_user_id, action, entity_type, entity_id, details, ip, created_at)
        VALUES (:actor_id, 'admin_provisioned_cli', 'user', :entity_id, :details, '127.0.0.1', NOW())
    ");
    $auditStmt->execute([
        ':actor_id'  => $adminId,
        ':entity_id' => $adminId,
        ':details'   => json_encode(['email' => $email, 'role' => 'admin', 'source' => 'cli']),
    ]);

    echo "\n [✓] Administrator account created successfully!\n";
    echo "     Name:  {$firstName} {$lastName}\n";
    echo "     Email: {$email}\n";
    echo "     Role:  admin\n";
    echo "     ID:    #{$adminId}\n\n";
} catch (Throwable $e) {
    echo "\n [✗] Database error creating administrator: " . $e->getMessage() . "\n\n";
    exit(1);
}
