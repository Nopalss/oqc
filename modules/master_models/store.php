<?php
/**
 * Action Handler: Store New Master Model
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/master_models/index.php');
}

$name = strtoupper(trim(sanitize($_POST['name'] ?? '')));

if (empty($name)) {
    set_flash('error', 'Nama Model Wajib Diisi.');
    redirect('modules/master_models/create.php');
}

$pdo = getDB();
if ($pdo) {
    try {
        // Check duplicate name
        $stmtChk = $pdo->prepare("SELECT id FROM master_models WHERE UPPER(name) = :name");
        $stmtChk->execute([':name' => $name]);
        if ($stmtChk->fetch()) {
            set_flash('error', "Nama Model '{$name}' sudah ada di database.");
            redirect('modules/master_models/create.php');
        }

        $stmtIns = $pdo->prepare("INSERT INTO master_models (name, created_at) VALUES (:name, NOW())");
        $stmtIns->execute([':name' => $name]);

        set_flash('success', "Master Model '{$name}' berhasil ditambahkan.");
        redirect('modules/master_models/index.php');

    } catch (PDOException $e) {
        set_flash('error', 'Gagal menyimpan Master Model: ' . $e->getMessage());
        redirect('modules/master_models/create.php');
    }
} else {
    set_flash('error', 'Koneksi database tidak tersedia.');
    redirect('modules/master_models/index.php');
}
