<?php
/**
 * Action Handler: Delete User Data
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    set_flash('error', 'ID User tidak ditemukan!');
    redirect('modules/users/index.php');
}

$pdo = getDB();

if ($pdo) {
    try {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
        $stmt->execute([':id' => $id]);
        set_flash('success', 'User berhasil dihapus dari database!');
    } catch (PDOException $e) {
        deleteSessionMock($id);
    }
} else {
    deleteSessionMock($id);
}

function deleteSessionMock($id) {
    if (isset($_SESSION['users_mock'][$id])) {
        unset($_SESSION['users_mock'][$id]);
        set_flash('success', 'User berhasil dihapus!');
    } else {
        set_flash('error', 'Data User tidak ditemukan!');
    }
}

redirect('modules/users/index.php');
