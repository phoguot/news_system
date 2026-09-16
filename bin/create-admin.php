<?php

declare(strict_types=1);

chdir(dirname(__DIR__));
require 'vendor/autoload.php';

use Admin\Model\User\UserConst;

$options = getopt('', ['email:', 'name:', 'username::', 'phone::']);
$email    = $options['email'] ?? null;
$name     = $options['name'] ?? null;
$username = $options['username'] ?? null;
$phone    = $options['phone'] ?? null;

if (!$email || !$name) {
    fwrite(STDERR, "Usage: php bin/create-admin.php --email=admin@vanlang.vn --name=\"Quan tri\" [--username=admin] [--phone=...]\n");
    fwrite(STDERR, "  Password will be prompted interactively (hidden input).\n");
    exit(1);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid email.\n");
    exit(1);
}

if ($username !== null && !preg_match(UserConst::USERNAME_PATTERN, (string) $username)) {
    fwrite(STDERR, "Invalid username: " . UserConst::ERROR_USERNAME_FORMAT . "\n");
    exit(1);
}

fwrite(STDOUT, "Password (min 8 chars): ");
if (DIRECTORY_SEPARATOR === '\\') {
    $password = trim(fgets(STDIN));
} else {
    system('stty -echo');
    $password = trim(fgets(STDIN));
    system('stty echo');
    fwrite(STDOUT, "\n");
}

if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

fwrite(STDOUT, "Confirm password: ");
if (DIRECTORY_SEPARATOR !== '\\') {
    system('stty -echo');
}
$confirm = trim(fgets(STDIN));
if (DIRECTORY_SEPARATOR !== '\\') {
    system('stty echo');
    fwrite(STDOUT, "\n");
}
if ($password !== $confirm) {
    fwrite(STDERR, "Passwords do not match.\n");
    exit(1);
}

$config = require 'config/autoload/global.php';
$local  = file_exists('config/autoload/local.php') ? require 'config/autoload/local.php' : [];
$dbCfg  = array_merge($config['db'] ?? [], $local['db'] ?? []);

try {
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $dbCfg['hostname'] ?? '127.0.0.1', $dbCfg['database'] ?? 'news_system');
    $pdo = new PDO($dsn, $dbCfg['username'] ?? 'root', $dbCfg['password'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
} catch (Throwable $e) {
    fwrite(STDERR, "DB connect failed: " . $e->getMessage() . "\n");
    exit(1);
}

$exists = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
if ((int) $exists >= 1) {
    fwrite(STDERR, "Refusing: users table already has {$exists} row(s). This system allows only 1 admin (see docs v1.5).\n");
    fwrite(STDERR, "Delete the existing user first if you really want to replace it.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $pdo->prepare("INSERT INTO users (fullName, email, username, passwordHash, phone, createdAt, updatedAt) VALUES (:fullName, :email, :username, :hash, :phone, NOW(), NOW())");
$stmt->execute([
    'fullName' => $name,
    'email'    => $email,
    'username' => $username === '' ? null : $username,
    'hash'     => $hash,
    'phone'    => $phone,
]);

fwrite(STDOUT, "Admin created: {$email}" . ($username ? " (username: {$username})" : '') . " (id " . $pdo->lastInsertId() . ")\n");
