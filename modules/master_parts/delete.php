<?php
/**
 * Action Handler: Delete Master Part & Associated Physical Files
 * Protected with RBAC, CSRF token, and Foreign Key Integrity Checks
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

require_menu_access('master_parts');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/master_parts/index.php');
}

csrf_verify();

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    set_flash('error', 'ID Part tidak valid!');
    redirect('modules/master_parts/index.php');
}

$pdo = getDB();

if ($pdo) {
    try {
        // 1. Fetch Part info
        $stmtPart = $pdo->prepare("SELECT id, part_code FROM master_parts WHERE id = :id");
        $stmtPart->execute([':id' => $id]);
        $part = $stmtPart->fetch();

        if (!$part) {
            set_flash('error', 'Data Master Part tidak ditemukan!');
            redirect('modules/master_parts/index.php');
        }

        $partCode = $part['part_code'];

        // 2. Pre-check Foreign Key / Transaction Integrity
        // Prevent fatal error 1451 and protect historical inspection logs
        $stmtCheckSession = $pdo->prepare("SELECT 1 FROM inspection_sessions WHERE part_id = :id LIMIT 1");
        $stmtCheckSession->execute([':id' => $id]);
        if ($stmtCheckSession->fetch()) {
            set_flash('error', "Part '{$partCode}' tidak dapat dihapus karena memiliki riwayat sesi inspeksi di sistem OQC!");
            redirect('modules/master_parts/index.php');
        }

        $stmtCheckSummary = $pdo->prepare("SELECT 1 FROM oqc_daily_summary WHERE part_id = :id LIMIT 1");
        $stmtCheckSummary->execute([':id' => $id]);
        if ($stmtCheckSummary->fetch()) {
            set_flash('error', "Part '{$partCode}' tidak dapat dihapus karena tercatat di ringkasan harian (Daily Summary)!");
            redirect('modules/master_parts/index.php');
        }

        $stmtCheckDaily = $pdo->prepare("SELECT 1 FROM daily_inspection_data WHERE part_code = :part_code LIMIT 1");
        $stmtCheckDaily->execute([':part_code' => $partCode]);
        if ($stmtCheckDaily->fetch()) {
            set_flash('error', "Part '{$partCode}' tidak dapat dihapus karena tercatat pada data inspeksi harian (Daily Inspection Data)!");
            redirect('modules/master_parts/index.php');
        }

        // 3. Fetch drawing info for physical file cleanup after successful DB commit
        $stmtDraw = $pdo->prepare("SELECT * FROM master_drawings WHERE part_id = :part_id");
        $stmtDraw->execute([':part_id' => $id]);
        $drawing = $stmtDraw->fetch();

        $pdo->beginTransaction();

        // Delete drawing record
        if ($drawing) {
            $stmtDelDraw = $pdo->prepare("DELETE FROM master_drawings WHERE part_id = :part_id");
            $stmtDelDraw->execute([':part_id' => $id]);
        }

        // Delete master_part record
        $stmtDelPart = $pdo->prepare("DELETE FROM master_parts WHERE id = :id");
        $stmtDelPart->execute([':id' => $id]);

        $pdo->commit();

        // 4. Safe physical file cleanup after DB transaction succeeds
        if ($drawing) {
            if (!empty($drawing['drawing_2d_path'])) {
                $file2D = __DIR__ . '/../../' . $drawing['drawing_2d_path'];
                if (file_exists($file2D) && is_file($file2D)) {
                    @unlink($file2D);
                }
            }
            if (!empty($drawing['drawing_3d_path'])) {
                $file3D = __DIR__ . '/../../' . $drawing['drawing_3d_path'];
                if (file_exists($file3D) && is_file($file3D)) {
                    @unlink($file3D);
                }
            }
        }

        set_flash('success', "Master Part '{$partCode}' beserta berkas drawing terkait berhasil dihapus!");
    } catch (PDOException $e) {
        if ($pdo && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'Gagal menghapus part! Error: ' . $e->getMessage());
    }
} else {
    set_flash('error', 'Database tidak terhubung!');
}

redirect('modules/master_parts/index.php');
