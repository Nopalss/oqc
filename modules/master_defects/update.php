<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/master_defects/index.php');
}

$id = (int)($_POST['id'] ?? 0);
$name = trim(sanitize($_POST['name'] ?? ''));
$pdo = getDB();

if ($id <= 0 || empty($name)) {
    set_flash('error', 'ID dan Nama jenis defect wajib diisi!');
    redirect('modules/master_defects/index.php');
}

if (!$pdo) {
    set_flash('error', 'Gagal terhubung ke database server!');
    redirect('modules/master_defects/index.php');
}

try {
    // Check if defect record exists
    $stmtChkExist = $pdo->prepare("SELECT COUNT(*) FROM defect_types WHERE id = :id");
    $stmtChkExist->execute([':id' => $id]);
    if ((int)$stmtChkExist->fetchColumn() === 0) {
        set_flash('error', 'Data Jenis Defect tidak ditemukan!');
        redirect('modules/master_defects/index.php');
    }

    // Check unique defect name (excluding self)
    $stmtChk = $pdo->prepare("SELECT COUNT(*) FROM defect_types WHERE UPPER(name) = UPPER(:name) AND id != :id");
    $stmtChk->execute([':name' => $name, ':id' => $id]);
    if ((int)$stmtChk->fetchColumn() > 0) {
        set_flash('error', 'Jenis Defect dengan nama "' . htmlspecialchars($name) . '" sudah digunakan oleh data lain!');
        redirect('modules/master_defects/edit.php?id=' . $id);
    }

    // Update defect record
    $stmtUpd = $pdo->prepare("UPDATE defect_types SET name = :name WHERE id = :id");
    $stmtUpd->execute([':name' => $name, ':id' => $id]);

    set_flash('success', 'Jenis Defect "' . htmlspecialchars($name) . '" berhasil diperbarui!');
    redirect('modules/master_defects/index.php');

} catch (PDOException $e) {
    set_flash('error', 'Gagal memperbarui Jenis Defect: ' . $e->getMessage());
    redirect('modules/master_defects/edit.php?id=' . $id);
}
