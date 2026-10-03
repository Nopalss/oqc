<?php
/**
 * Action Handler: Delete Custom Role
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

require_menu_access('roles');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/roles/index.php');
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    set_flash('danger', 'ID Role tidak valid!');
    redirect('modules/roles/index.php');
}

$pdo = getDB();

if (!$pdo) {
    set_flash('danger', 'Koneksi database tidak tersedia.');
    redirect('modules/roles/index.php');
}

try {
    $stmt = $pdo->prepare("SELECT * FROM roles WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $role = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$role) {
        set_flash('danger', 'Role tidak ditemukan!');
        redirect('modules/roles/index.php');
    }

    if ((int)$role['is_system'] === 1) {
        set_flash('danger', "Role sistem '{$role['display_name']}' dilindungi dan tidak dapat dihapus!");
        redirect('modules/roles/index.php');
    }

    // Check if any users are assigned to this role
    $stmtUserCount = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = :role_name");
    $stmtUserCount->execute([':role_name' => $role['role_name']]);
    $assignedUsers = (int)$stmtUserCount->fetchColumn();

    if ($assignedUsers > 0) {
        set_flash('danger', "Tidak dapat menghapus role '{$role['display_name']}' karena masih digunakan oleh {$assignedUsers} pengguna aktif. Alihkan role pengguna tersebut terlebih dahulu.");
        redirect('modules/roles/index.php');
    }

    $pdo->beginTransaction();

    // Delete permissions (FK ON DELETE CASCADE will also handle it, but explicit delete ensures clarity)
    $stmtDelPerms = $pdo->prepare("DELETE FROM role_permissions WHERE role_id = :id");
    $stmtDelPerms->execute([':id' => $id]);

    // Delete role
    $stmtDelRole = $pdo->prepare("DELETE FROM roles WHERE id = :id");
    $stmtDelRole->execute([':id' => $id]);

    $pdo->commit();
    set_flash('success', "Role '{$role['display_name']}' berhasil dihapus.");
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('danger', 'Gagal menghapus role: ' . $e->getMessage());
}

redirect('modules/roles/index.php');
