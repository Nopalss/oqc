<?php
/**
 * Action Handler: Update Existing User Data
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

require_menu_access('users');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/users/index.php');
}

$id       = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$name     = sanitize($_POST['name'] ?? '');
$username = strtolower(trim(sanitize($_POST['username'] ?? '')));
$password = $_POST['password'] ?? '';
$role_id  = filter_input(INPUT_POST, 'role_id', FILTER_VALIDATE_INT);
$status   = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

if (!$id || empty($name) || empty($username) || !$role_id) {
    set_flash('danger', 'Data input tidak valid! Pastikan Role dipilih.');
    redirect('modules/users/index.php');
}

$pdo = getDB();

if (!$pdo) {
    set_flash('danger', 'Koneksi database tidak tersedia.');
    redirect('modules/users/index.php');
}

try {
    // Check if username is used by another user
    $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE username = :username AND id != :id LIMIT 1");
    $stmtCheck->execute([':username' => $username, ':id' => $id]);
    if ($stmtCheck->fetch()) {
        set_flash('danger', "Username '{$username}' sudah digunakan oleh pengguna lain!");
        redirect('modules/users/edit.php?id=' . $id);
    }

    // Lookup role_name dari roles table berdasarkan id
    $stmtRole = $pdo->prepare("SELECT id, role_name FROM roles WHERE id = :id LIMIT 1");
    $stmtRole->execute([':id' => $role_id]);
    $roleRow = $stmtRole->fetch(PDO::FETCH_ASSOC);

    if (!$roleRow) {
        set_flash('danger', 'Role yang dipilih tidak valid!');
        redirect('modules/users/edit.php?id=' . $id);
    }

    $roleName = $roleRow['role_name'];

    if (!empty($password)) {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("
            UPDATE users 
            SET name = :name, username = :username, password_hash = :password_hash,
                role = :role, role_id = :role_id, status = :status, updated_at = NOW() 
            WHERE id = :id
        ");
        $stmt->execute([
            ':name'          => $name,
            ':username'      => $username,
            ':password_hash' => $passwordHash,
            ':role'          => $roleName,
            ':role_id'       => (int)$role_id,
            ':status'        => $status,
            ':id'            => $id
        ]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE users 
            SET name = :name, username = :username,
                role = :role, role_id = :role_id, status = :status, updated_at = NOW() 
            WHERE id = :id
        ");
        $stmt->execute([
            ':name'     => $name,
            ':username' => $username,
            ':role'     => $roleName,
            ':role_id'  => (int)$role_id,
            ':status'   => $status,
            ':id'       => $id
        ]);
    }

    // If current logged-in user updated their own record, update session
    $cur = current_user();
    if ((int)$cur['id'] === (int)$id) {
        $_SESSION['user_name'] = $name;
        $_SESSION['username']  = $username;
        if ($_SESSION['user_role'] !== $roleName) {
            $_SESSION['user_role'] = $roleName;
            load_user_permissions($pdo, $roleName);
        }
    }

    set_flash('success', "Data User '{$name}' berhasil diperbarui dengan role {$roleName}!");
} catch (PDOException $e) {
    set_flash('danger', 'Gagal memperbarui data user: ' . $e->getMessage());
}

redirect('modules/users/index.php');
