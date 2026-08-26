<?php
/**
 * Action Handler: Store New User Data
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/users/index.php');
}

$name = sanitize($_POST['name'] ?? '');
$email = sanitize($_POST['email'] ?? '');
$role = sanitize($_POST['role'] ?? 'Operator');
$status = sanitize($_POST['status'] ?? 'Aktif');

if (empty($name) || empty($email)) {
    set_flash('error', 'Nama dan Email wajib diisi!');
    redirect('modules/users/create.php');
}

$pdo = getDB();

if ($pdo) {
    try {
        $stmt = $pdo->prepare("INSERT INTO users (name, email, role, status, created_at) VALUES (:name, :email, :role, :status, NOW())");
        $stmt->execute([
            ':name' => $name,
            ':email' => $email,
            ':role' => $role,
            ':status' => $status
        ]);
        set_flash('success', 'User berhasil ditambahkan ke Database!');
    } catch (PDOException $e) {
        // Fallback store in session mock
        storeInSessionMock($name, $email, $role, $status);
    }
} else {
    storeInSessionMock($name, $email, $role, $status);
}

function storeInSessionMock($name, $email, $role, $status) {
    if (!isset($_SESSION['users_mock'])) {
        $_SESSION['users_mock'] = [];
    }
    $newId = count($_SESSION['users_mock']) > 0 ? max(array_keys($_SESSION['users_mock'])) + 1 : 1;
    $_SESSION['users_mock'][$newId] = [
        'id' => $newId,
        'name' => $name,
        'email' => $email,
        'role' => $role,
        'status' => $status,
        'created_at' => date('Y-m-d H:i:s')
    ];
    set_flash('success', 'User berhasil ditambahkan!');
}

redirect('modules/users/index.php');
