<?php
/**
 * Action Handler: Update Existing User Data
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/users/index.php');
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$name = sanitize($_POST['name'] ?? '');
$email = sanitize($_POST['email'] ?? '');
$role = sanitize($_POST['role'] ?? 'Operator');
$status = sanitize($_POST['status'] ?? 'Aktif');

if (!$id || empty($name) || empty($email)) {
    set_flash('error', 'Data tidak valid!');
    redirect('modules/users/index.php');
}

$pdo = getDB();

if ($pdo) {
    try {
        $stmt = $pdo->prepare("UPDATE users SET name = :name, email = :email, role = :role, status = :status WHERE id = :id");
        $stmt->execute([
            ':name' => $name,
            ':email' => $email,
            ':role' => $role,
            ':status' => $status,
            ':id' => $id
        ]);
        set_flash('success', 'Data User berhasil diperbarui!');
    } catch (PDOException $e) {
        updateSessionMock($id, $name, $email, $role, $status);
    }
} else {
    updateSessionMock($id, $name, $email, $role, $status);
}

function updateSessionMock($id, $name, $email, $role, $status) {
    if (isset($_SESSION['users_mock'][$id])) {
        $_SESSION['users_mock'][$id]['name'] = $name;
        $_SESSION['users_mock'][$id]['email'] = $email;
        $_SESSION['users_mock'][$id]['role'] = $role;
        $_SESSION['users_mock'][$id]['status'] = $status;
        set_flash('success', 'Data User berhasil diperbarui!');
    } else {
        set_flash('error', 'Gagal memperbarui data user!');
    }
}

redirect('modules/users/index.php');
