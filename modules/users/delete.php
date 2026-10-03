<?php
/**
 * Action Handler: Delete User Data
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

require_menu_access('users');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    set_flash('danger', 'ID User tidak valid!');
    redirect('modules/users/index.php');
}

$curUser = current_user();
if ((int)$curUser['id'] === $id) {
    set_flash('danger', 'Anda tidak dapat menghapus akun Anda sendiri yang sedang aktif digunakan!');
    redirect('modules/users/index.php');
}

$pdo = getDB();

if ($pdo) {
    try {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
        $stmt->execute([':id' => $id]);
        set_flash('success', 'User berhasil dihapus!');
    } catch (PDOException $e) {
        set_flash('danger', 'Gagal menghapus user: ' . $e->getMessage());
    }
}

redirect('modules/users/index.php');
