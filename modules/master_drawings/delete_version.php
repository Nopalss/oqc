<?php
/**
 * Action Handler: Delete Specific Document / Drawing Version File
 * Securely deletes a specific file from uploads/parts/{PartCode}/{filename}
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';
require_once __DIR__ . '/../../config/document_helper.php';

require_menu_access('master_drawings');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/master_drawings/index.php');
}

csrf_verify();

$part_id = (int)($_POST['part_id'] ?? 0);
$rawFilename = trim($_POST['filename'] ?? '');

if (!$part_id || empty($rawFilename)) {
    set_flash('error', 'Parameter penghapusan berkas tidak valid!');
    redirect('modules/master_drawings/index.php');
}

// Keamanan: Cegah Directory Traversal Attack
$safeFilename = basename($rawFilename);
if ($safeFilename !== $rawFilename || $safeFilename === '.' || $safeFilename === '..') {
    set_flash('error', 'Nama berkas tidak aman atau tidak valid!');
    redirect('modules/master_drawings/detail.php?part_id=' . $part_id);
}

$pdo = getDB();
if (!$pdo) {
    set_flash('error', 'Database tidak terhubung!');
    redirect('modules/master_drawings/detail.php?part_id=' . $part_id);
}

// 1. Fetch Part Info
$stmtPart = $pdo->prepare("SELECT id, part_code, part_name FROM master_parts WHERE id = :id");
$stmtPart->execute([':id' => $part_id]);
$part = $stmtPart->fetch(PDO::FETCH_ASSOC);

if (!$part) {
    set_flash('error', 'Part tidak ditemukan!');
    redirect('modules/master_drawings/index.php');
}

$cleanPartCode = trim($part['part_code']);
$rootAppDir = realpath(__DIR__ . '/../..');
$targetFile = $rootAppDir . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'parts' . DIRECTORY_SEPARATOR . $cleanPartCode . DIRECTORY_SEPARATOR . $safeFilename;

if (!file_exists($targetFile) || !is_file($targetFile)) {
    set_flash('error', 'Berkas "' . htmlspecialchars($safeFilename) . '" tidak ditemukan di server.');
    redirect('modules/master_drawings/detail.php?part_id=' . $part_id);
}

// 2. Hapus berkas fisik
if (@unlink($targetFile)) {
    // 3. Cek apakah berkas yang dihapus tercatat di master_drawings (2D atau 3D)
    $stmtDraw = $pdo->prepare("SELECT id, drawing_2d_path, drawing_3d_path FROM master_drawings WHERE part_id = :part_id");
    $stmtDraw->execute([':part_id' => $part_id]);
    $drawRow = $stmtDraw->fetch(PDO::FETCH_ASSOC);

    if ($drawRow) {
        $p2d = $drawRow['drawing_2d_path'];
        $p3d = $drawRow['drawing_3d_path'];
        $needDbUpdate = false;

        if ($p2d && basename($p2d) === $safeFilename) {
            // Cari apakah masih ada file 2D lain di folder
            $sum = get_part_documents_summary($cleanPartCode);
            $p2d = (!empty($sum['2d']['has_document']) && !empty($sum['2d']['latest'])) ? $sum['2d']['latest']['relative_url'] : null;
            $needDbUpdate = true;
        }

        if ($p3d && basename($p3d) === $safeFilename) {
            // Cari apakah masih ada file 3D lain di folder
            $sum = get_part_documents_summary($cleanPartCode);
            $p3d = (!empty($sum['3d']['has_document']) && !empty($sum['3d']['latest'])) ? $sum['3d']['latest']['relative_url'] : null;
            $needDbUpdate = true;
        }

        if ($needDbUpdate) {
            $stmtUp = $pdo->prepare("UPDATE master_drawings SET drawing_2d_path = :p2d, drawing_3d_path = :p3d, updated_at = NOW() WHERE part_id = :part_id");
            $stmtUp->execute([
                ':p2d' => $p2d,
                ':p3d' => $p3d,
                ':part_id' => $part_id
            ]);
        }
    }

    set_flash('success', 'Berkas "' . htmlspecialchars($safeFilename) . '" berhasil dihapus dari server!');
} else {
    set_flash('error', 'Gagal menghapus berkas dari server. Periksa hak akses file.');
}

redirect('modules/master_drawings/detail.php?part_id=' . $part_id);
