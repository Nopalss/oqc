<?php
/**
 * Action Handler: Store New Role & Permissions
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

require_menu_access('roles');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/roles/index.php');
}

$displayName = trim($_POST['display_name'] ?? '');
$permissions = isset($_POST['permissions']) && is_array($_POST['permissions']) ? $_POST['permissions'] : [];

// Validation
if (empty($displayName)) {
    set_flash('danger', 'Nama Role wajib diisi!');
    redirect('modules/roles/create.php');
}

$pdo = getDB();

if (!$pdo) {
    set_flash('danger', 'Koneksi database tidak tersedia.');
    redirect('modules/roles/index.php');
}

// Auto-generate system role_name slug from displayName
$baseSlug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $displayName), '_'));
if (empty($baseSlug)) {
    $baseSlug = 'role_' . time();
}

$roleName = $baseSlug;
$suffix = 1;
while (true) {
    $stmtCheck = $pdo->prepare("SELECT id FROM roles WHERE role_name = :role_name LIMIT 1");
    $stmtCheck->execute([':role_name' => $roleName]);
    if (!$stmtCheck->fetch()) {
        break;
    }
    $suffix++;
    $roleName = $baseSlug . '_' . $suffix;
}

try {
    $pdo->beginTransaction();

    // 1. Insert Role
    $stmtInsertRole = $pdo->prepare("
        INSERT INTO roles (role_name, display_name, description, is_system, created_at)
        VALUES (:role_name, :display_name, NULL, 0, NOW())
    ");
    $stmtInsertRole->execute([
        ':role_name'    => $roleName,
        ':display_name' => $displayName
    ]);

    $roleId = (int)$pdo->lastInsertId();

    // 2. Insert Permissions
    if (!empty($permissions)) {
        $stmtInsertPerm = $pdo->prepare("
            INSERT INTO role_permissions (role_id, menu_key, can_view, created_at)
            VALUES (:role_id, :menu_key, 1, NOW())
        ");

        foreach ($permissions as $menuKey) {
            $cleanedKey = trim(sanitize($menuKey));
            if (!empty($cleanedKey)) {
                $stmtInsertPerm->execute([
                    ':role_id'  => $roleId,
                    ':menu_key' => $cleanedKey
                ]);
            }
        }
    }

    $pdo->commit();
    set_flash('success', "Role '{$displayName}' berhasil dibuat beserta izin aksesnya.");
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('danger', 'Gagal menyimpan role: ' . $e->getMessage());
}

redirect('modules/roles/index.php');
