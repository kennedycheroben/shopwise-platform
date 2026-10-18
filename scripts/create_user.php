<?php
/**
 * ShopWise Kenya - User Account Provisioning Script (CLI)
 * Usage (interactive): php scripts/create_user.php [role: staff|admin|customer]
 * Usage (direct):      php scripts/create_user.php [role] [first_name] [last_name] [email] [password] [phone]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Error: CLI execution only.\n");
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Database\Database;

$role = $argv[1] ?? '';
if (!in_array($role, ['staff', 'admin', 'customer'], true)) {
    echo "Usage: php scripts/create_user.php <staff|admin|customer> [first_name] [last_name] [email] [password] [phone]\n";
    echo "Or enter role now [staff/admin/customer]: ";
    $role = strtolower(trim(fgets(STDIN)));
    if (!in_array($role, ['staff', 'admin', 'customer'], true)) {
        exit("Invalid role specified. Must be staff, admin, or customer.\n");
    }
}

$firstName = $argv[2] ?? '';
$lastName  = $argv[3] ?? '';
$email     = $argv[4] ?? '';
$password  = $argv[5] ?? '';
$phone     = $argv[6] ?? null;

$isTty = function_exists('posix_isatty') ? posix_isatty(STDIN) : (function_exists('stream_isatty') ? stream_isatty(STDIN) : false);

if ($firstName === '') {
    echo "Enter First Name: ";
    $firstName = trim(fgets(STDIN));
}

if ($lastName === '') {
    echo "Enter Last Name: ";
    $lastName = trim(fgets(STDIN));
}

if ($email === '') {
    echo "Enter Email Address: ";
    $email = strtolower(trim(fgets(STDIN)));
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit("Error: Invalid email address format.\n");
}

$pdo = Database::getInstance();
$check = $pdo->prepare("SELECT id FROM users WHERE email = :email");
$check->execute([':email' => $email]);
if ($check->fetch()) {
    exit("Error: A user with email '{$email}' already exists.\n");
}

if ($password === '') {
    echo "Enter Password (min 8 characters): ";
    if ($isTty && strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        system('stty -echo');
        $password = trim(fgets(STDIN));
        system('stty echo');
        echo "\n";
    } else {
        $password = trim(fgets(STDIN));
    }
}

if (strlen($password) < 8) {
    exit("Error: Password must be at least 8 characters long.\n");
}

if ($phone === null) {
    echo "Enter Phone (optional, e.g. 0712345678): ";
    $rawPhone = trim(fgets(STDIN));
    if ($rawPhone !== '') {
        $phone = normalize_phone($rawPhone);
    }
} else {
    $phone = normalize_phone((string)$phone);
}

$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("
    INSERT INTO users (role, first_name, last_name, email, phone, password_hash, status, email_verified_at, created_at)
    VALUES (:role, :first_name, :last_name, :email, :phone, :hash, 'active', NOW(), NOW())
");
$stmt->execute([
    ':role'       => $role,
    ':first_name' => $firstName,
    ':last_name'  => $lastName,
    ':email'      => $email,
    ':phone'      => $phone,
    ':hash'       => $hash,
]);

$userId = $pdo->lastInsertId();

echo "[✓] Successfully created {$role} account #{$userId} for {$firstName} {$lastName} ({$email}).\n";
