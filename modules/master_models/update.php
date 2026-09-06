<?php
/**
 * Action Handler: Update Master Model
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/master_models/index.php');
}

$id   = (int)($_POST['id'] ?? 0);
$name = strtoupper(trim(sanitize($_POST['name'] ?? '')));

if ($id <= 0 || empty($name)) {
    set_flash('error', 'ID dan Nama Model Wajib Diisi.');
    redirect('modules/master_models/index.php');
}

$pdo = getDB();
if ($pdo) {
    try {
        // Check duplicate name on other records
        $stmtChk = $pdo->prepare("SELECT id FROM master_models WHERE UPPER(name) = :name AND id != :id");
        $stmtChk->execute([':name' => $name, ':id' => $id]);
        if ($stmtChk->fetch()) {
            set_flash('error', "Nama Model '{$name}' sudah digunakan oleh data lain.");
            redirect("modules/master_models/edit.php?id={$id}");
        }

        $stmtUpd = $pdo->prepare("UPDATE master_models SET name = :name, updated_at = NOW() WHERE id = :id");
        $stmtUpd->execute([':name' => $name, ':id' => $id]);

        set_flash('success', "Master Model '{$name}' berhasil diperbarui.");
        redirect('modules/master_models/index.php');

    } catch (PDOException $e) {
        set_flash('error', 'Gagal memperbarui Master Model: ' . $e->getMessage());
        redirect("modules/master_models/edit.php?id={$id}");
    }
} else {
    set_flash('error', 'Koneksi database tidak tersedia.');
    redirect('modules/master_models/index.php');
}
