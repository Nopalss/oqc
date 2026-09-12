<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

$id = (int)($_GET['id'] ?? 0);
$pdo = getDB();

if ($id <= 0) {
    set_flash('error', 'ID Jenis Defect tidak valid!');
    redirect('modules/master_defects/index.php');
}

if (!$pdo) {
    set_flash('error', 'Gagal terhubung ke database server!');
    redirect('modules/master_defects/index.php');
}

try {
    // 1. Fetch target defect record
    $stmtFetch = $pdo->prepare("SELECT name FROM defect_types WHERE id = :id LIMIT 1");
    $stmtFetch->execute([':id' => $id]);
    $defectName = $stmtFetch->fetchColumn();

    if (!$defectName) {
        set_flash('error', 'Data Jenis Defect tidak ditemukan!');
        redirect('modules/master_defects/index.php');
    }

    // 2. Check FK constraint: is this defect used in inspection_ng_records?
    $stmtChkNg = $pdo->prepare("SELECT COUNT(*) FROM inspection_ng_records WHERE defect_type_id = :id AND (is_cancelled IS NULL OR is_cancelled = 0)");
    $stmtChkNg->execute([':id' => $id]);
    $ngUsageCount = (int)$stmtChkNg->fetchColumn();

    if ($ngUsageCount > 0) {
        set_flash('error', 'Jenis Defect "' . htmlspecialchars($defectName) . '" TIDAK BISA DIHAPUS karena sedang digunakan pada ' . $ngUsageCount . ' catatan temuan NG inspeksi!');
        redirect('modules/master_defects/index.php');
    }

    // 3. Delete defect record
    $stmtDel = $pdo->prepare("DELETE FROM defect_types WHERE id = :id");
    $stmtDel->execute([':id' => $id]);

    set_flash('success', 'Jenis Defect "' . htmlspecialchars($defectName) . '" berhasil dihapus!');
    redirect('modules/master_defects/index.php');

} catch (PDOException $e) {
    set_flash('error', 'Gagal menghapus Jenis Defect: ' . $e->getMessage());
    redirect('modules/master_defects/index.php');
}
