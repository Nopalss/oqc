<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/master_customers/index.php');
}

$pdo = getDB();
if (!$pdo) {
    set_flash('error', 'Database tidak terhubung!');
    redirect('modules/master_customers/index.php');
}

$name = trim(sanitize($_POST['name'] ?? ''));

if (empty($name)) {
    set_flash('error', 'Nama Customer / PT tidak boleh kosong!');
    redirect('modules/master_customers/create.php');
}

try {
    // Check duplicate
    $stmtChk = $pdo->prepare("SELECT id FROM master_customers WHERE name = :name");
    $stmtChk->execute([':name' => $name]);
    if ($stmtChk->fetch()) {
        set_flash('error', 'Customer "' . htmlspecialchars($name) . '" sudah terdaftar sebelumnya!');
        redirect('modules/master_customers/create.php');
    }

    $stmtInsert = $pdo->prepare("INSERT INTO master_customers (name, created_at) VALUES (:name, NOW())");
    $stmtInsert->execute([':name' => $name]);

    set_flash('success', 'Berhasil menambahkan Master Customer baru: ' . htmlspecialchars($name));
    redirect('modules/master_customers/index.php');

} catch (PDOException $e) {
    set_flash('error', 'Gagal menyimpan Master Customer: ' . $e->getMessage());
    redirect('modules/master_customers/create.php');
}
