<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/master_defects/index.php');
}

$pdo = getDB();
$name = trim(sanitize($_POST['name'] ?? ''));

if (empty($name)) {
    set_flash('error', 'Nama jenis defect wajib diisi!');
    redirect('modules/master_defects/create.php');
}

if (!$pdo) {
    set_flash('error', 'Gagal terhubung ke database server!');
    redirect('modules/master_defects/create.php');
}

try {
    // Check unique defect name (case insensitive)
    $stmtChk = $pdo->prepare("SELECT COUNT(*) FROM defect_types WHERE UPPER(name) = UPPER(:name)");
    $stmtChk->execute([':name' => $name]);
    if ((int)$stmtChk->fetchColumn() > 0) {
        set_flash('error', 'Jenis Defect dengan nama "' . htmlspecialchars($name) . '" sudah terdaftar!');
        redirect('modules/master_defects/create.php');
    }

    // Insert new defect type
    $stmtIns = $pdo->prepare("INSERT INTO defect_types (name, created_at) VALUES (:name, NOW())");
    $stmtIns->execute([':name' => $name]);

    set_flash('success', 'Jenis Defect "' . htmlspecialchars($name) . '" berhasil ditambahkan!');
    redirect('modules/master_defects/index.php');

} catch (PDOException $e) {
    set_flash('error', 'Gagal menyimpan Jenis Defect: ' . $e->getMessage());
    redirect('modules/master_defects/create.php');
}
