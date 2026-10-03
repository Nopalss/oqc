<?php
/**
 * Action Handler: Store New User Data
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

require_menu_access('users');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/users/index.php');
}

$name     = sanitize($_POST['name'] ?? '');
$username = strtolower(trim(sanitize($_POST['username'] ?? '')));
$password = $_POST['password'] ?? '';
$role     = trim(sanitize($_POST['role'] ?? 'admin'));
$status   = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

if (empty($name) || empty($username) || empty($password)) {
    set_flash('danger', 'Nama, Username, dan Password wajib diisi!');
    redirect('modules/users/create.php');
}

$pdo = getDB();

if (!$pdo) {
    set_flash('danger', 'Koneksi database tidak tersedia.');
    redirect('modules/users/index.php');
}

try {
    // Check if username already exists
    $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE username = :username LIMIT 1");
    $stmtCheck->execute([':username' => $username]);
    if ($stmtCheck->fetch()) {
        set_flash('danger', "Username '{$username}' sudah terdaftar! Gunakan username lain.");
        redirect('modules/users/create.php');
    }

    // Verify role exists in roles table
    $stmtRole = $pdo->prepare("SELECT id FROM roles WHERE role_name = :role LIMIT 1");
    $stmtRole->execute([':role' => $role]);
    if (!$stmtRole->fetch()) {
        $role = 'admin'; // fallback to admin if not found
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("
        INSERT INTO users (name, username, password_hash, role, status, created_at, updated_at) 
        VALUES (:name, :username, :password_hash, :role, :status, NOW(), NOW())
    ");
    $stmt->execute([
        ':name'          => $name,
        ':username'      => $username,
        ':password_hash' => $passwordHash,
        ':role'          => $role,
        ':status'        => $status
    ]);

    set_flash('success', "User '{$name}' ({$username}) berhasil ditambahkan!");
} catch (PDOException $e) {
    set_flash('danger', 'Gagal menyimpan user: ' . $e->getMessage());
}

redirect('modules/users/index.php');
