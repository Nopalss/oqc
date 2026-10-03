<?php
/**
 * Action Handler: Update Existing Role & Permissions
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

require_menu_access('roles');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/roles/index.php');
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$displayName = trim($_POST['display_name'] ?? '');
$description = trim($_POST['description'] ?? '');
$permissions = isset($_POST['permissions']) && is_array($_POST['permissions']) ? $_POST['permissions'] : [];

if (!$id || empty($displayName)) {
    set_flash('danger', 'Data input tidak valid!');
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

    $isSystem = ((int)$role['is_system'] === 1);
    $isAdmin = ($role['role_name'] === 'admin');

    $pdo->beginTransaction();

    // 1. Update display_name (unless admin which keeps default name)
    if (!$isAdmin) {
        $stmtUpdate = $pdo->prepare("
            UPDATE roles 
            SET display_name = :display_name, updated_at = NOW()
            WHERE id = :id
        ");
        $stmtUpdate->execute([
            ':display_name' => $displayName,
            ':id'           => $id
        ]);
    }

    // 3. Update permissions (if not admin role)
    if (!$isAdmin) {
        $stmtDeletePerms = $pdo->prepare("DELETE FROM role_permissions WHERE role_id = :role_id");
        $stmtDeletePerms->execute([':role_id' => $id]);

        if (!empty($permissions)) {
            $stmtInsertPerm = $pdo->prepare("
                INSERT INTO role_permissions (role_id, menu_key, can_view, created_at)
                VALUES (:role_id, :menu_key, 1, NOW())
            ");

            foreach ($permissions as $menuKey) {
                $cleanedKey = trim(sanitize($menuKey));
                if (!empty($cleanedKey)) {
                    $stmtInsertPerm->execute([
                        ':role_id'  => $id,
                        ':menu_key' => $cleanedKey
                    ]);
                }
            }
        }
    }

    $pdo->commit();

    // If the currently logged-in user has this role, refresh session permissions immediately!
    $curUser = current_user();
    if ($curUser['role'] === $role['role_name']) {
        if ($curUser['role'] !== $newRoleName) {
            $_SESSION['user_role'] = $newRoleName;
        }
        load_user_permissions($pdo, $newRoleName);
    }

    set_flash('success', "Role '{$displayName}' berhasil diperbarui.");
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('danger', 'Gagal memperbarui role: ' . $e->getMessage());
}

redirect('modules/roles/index.php');
