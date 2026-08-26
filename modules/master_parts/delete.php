<?php
/**
 * Action Handler: Delete Master Part & Associated Physical Files
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    set_flash('error', 'ID Part tidak valid!');
    redirect('modules/master_parts/index.php');
}

$pdo = getDB();

if ($pdo) {
    try {
        // 1. Fetch & delete attached drawing files if any
        $stmtDraw = $pdo->prepare("SELECT * FROM master_drawings WHERE part_id = :part_id");
        $stmtDraw->execute([':part_id' => $id]);
        $drawing = $stmtDraw->fetch();

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

            // Delete drawing database record
            $stmtDelDraw = $pdo->prepare("DELETE FROM master_drawings WHERE part_id = :part_id");
            $stmtDelDraw->execute([':part_id' => $id]);
        }

        // 2. Delete Part record
        $stmt = $pdo->prepare("DELETE FROM master_parts WHERE id = :id");
        $stmt->execute([':id' => $id]);

        set_flash('success', 'Master Part beserta berkas drawing terkait berhasil dihapus!');
    } catch (PDOException $e) {
        set_flash('error', 'Gagal menghapus part! Error: ' . $e->getMessage());
    }
} else {
    set_flash('error', 'Database tidak terhubung!');
}

redirect('modules/master_parts/index.php');
