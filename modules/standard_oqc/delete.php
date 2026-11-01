<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

// Enforce permission
require_menu_access('standard_oqc');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/standard_oqc/index.php');
}

// Verify CSRF
csrf_verify();

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    set_flash('danger', 'ID Dokumen tidak valid.');
    redirect('modules/standard_oqc/index.php');
}

// KHUSUS DOKUMEN STANDARD UTAMA (ID 1) TIDAK BOLEH DIHAPUS
if ($id === 1) {
    set_flash('warning', 'Dokumen Standard Master (ID 1) dilindungi sistem dan tidak boleh dihapus. Anda hanya dapat memperbaruinya via Edit.');
    redirect('modules/standard_oqc/index.php');
}

$pdo = getDB();
if (!$pdo) {
    set_flash('danger', 'Koneksi database gagal.');
    redirect('modules/standard_oqc/index.php');
}

try {
    // Ambil info dokumen untuk menghapus file fisiknya
    $stmt = $pdo->prepare("SELECT * FROM standard_oqc WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($doc) {
        $filePath = __DIR__ . '/../../' . $doc['file_path'];
        if (!empty($doc['file_path']) && file_exists($filePath)) {
            @unlink($filePath);
        }

        // Hapus dari database
        $stmtDel = $pdo->prepare("DELETE FROM standard_oqc WHERE id = :id");
        $stmtDel->execute([':id' => $id]);

        set_flash('success', "Dokumen '{$doc['title']}' berhasil dihapus.");
    } else {
        set_flash('warning', 'Dokumen tidak ditemukan atau sudah dihapus.');
    }
} catch (PDOException $e) {
    set_flash('danger', 'Gagal menghapus dokumen: ' . $e->getMessage());
}

redirect('modules/standard_oqc/index.php');
