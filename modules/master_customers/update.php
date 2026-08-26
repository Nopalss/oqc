<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/master_customers/index.php');
}

$pdo = getDB();
$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$name = trim(sanitize($_POST['name'] ?? ''));

if (!$pdo || !$id || empty($name)) {
    set_flash('error', 'Data input tidak valid!');
    redirect('modules/master_customers/index.php');
}

try {
    // Check duplicate name on other ID
    $stmtChk = $pdo->prepare("SELECT id FROM master_customers WHERE name = :name AND id != :id");
    $stmtChk->execute([':name' => $name, ':id' => $id]);
    if ($stmtChk->fetch()) {
        set_flash('error', 'Customer "' . htmlspecialchars($name) . '" sudah digunakan oleh data lain!');
        redirect('modules/master_customers/edit.php?id=' . $id);
    }

    $stmtUpd = $pdo->prepare("UPDATE master_customers SET name = :name, updated_at = NOW() WHERE id = :id");
    $stmtUpd->execute([':name' => $name, ':id' => $id]);

    set_flash('success', 'Berhasil memperbarui data Master Customer: ' . htmlspecialchars($name));
    redirect('modules/master_customers/index.php');

} catch (PDOException $e) {
    set_flash('error', 'Gagal memperbarui Master Customer: ' . $e->getMessage());
    redirect('modules/master_customers/edit.php?id=' . $id);
}
