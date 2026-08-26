<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

$pdo = getDB();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$pdo || !$id) {
    set_flash('error', 'ID Customer tidak valid!');
    redirect('modules/master_customers/index.php');
}

try {
    $stmtDel = $pdo->prepare("DELETE FROM master_customers WHERE id = :id");
    $stmtDel->execute([':id' => $id]);

    set_flash('success', 'Data Master Customer berhasil dihapus!');
} catch (PDOException $e) {
    set_flash('error', 'Gagal menghapus Customer: ' . $e->getMessage());
}

redirect('modules/master_customers/index.php');
