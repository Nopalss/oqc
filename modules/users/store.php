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
$role_id  = filter_input(INPUT_POST, 'role_id', FILTER_VALIDATE_INT);
$status   = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

if (empty($name) || empty($username) || empty($password) || !$role_id) {
    set_flash('danger', 'Nama, Username, Password, dan Role wajib diisi!');
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

    // Lookup role_name dari roles table berdasarkan id
    $stmtRole = $pdo->prepare("SELECT id, role_name FROM roles WHERE id = :id LIMIT 1");
    $stmtRole->execute([':id' => $role_id]);
    $roleRow = $stmtRole->fetch(PDO::FETCH_ASSOC);

    if (!$roleRow) {
        set_flash('danger', 'Role yang dipilih tidak valid!');
        redirect('modules/users/create.php');
    }

    $roleName = $roleRow['role_name'];
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("
        INSERT INTO users (name, username, password_hash, role, role_id, status, created_at, updated_at) 
        VALUES (:name, :username, :password_hash, :role, :role_id, :status, NOW(), NOW())
    ");
    $stmt->execute([
        ':name'          => $name,
        ':username'      => $username,
        ':password_hash' => $passwordHash,
        ':role'          => $roleName,
        ':role_id'       => (int)$role_id,
        ':status'        => $status
    ]);

    set_flash('success', "User '{$name}' ({$username}) berhasil ditambahkan dengan role {$roleName}!");
} catch (PDOException $e) {
    set_flash('danger', 'Gagal menyimpan user: ' . $e->getMessage());
}

redirect('modules/users/index.php');
