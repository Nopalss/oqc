<?php
/**
 * Action Handler: Delete Master Drawing Files
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    set_flash('error', 'ID Drawing tidak valid!');
    redirect('modules/master_drawings/index.php');
}

$pdo = getDB();

if ($pdo) {
    try {
        $stmtSelect = $pdo->prepare("SELECT * FROM master_drawings WHERE id = :id");
        $stmtSelect->execute([':id' => $id]);
        $drawing = $stmtSelect->fetch();

        if ($drawing) {
            // Delete physical 2D PDF file from disk
            if (!empty($drawing['drawing_2d_path'])) {
                $file2D = __DIR__ . '/../../' . $drawing['drawing_2d_path'];
                if (file_exists($file2D) && is_file($file2D)) {
                    @unlink($file2D);
                }
            }

            // Delete physical 3D STP file from disk
            if (!empty($drawing['drawing_3d_path'])) {
                $file3D = __DIR__ . '/../../' . $drawing['drawing_3d_path'];
                if (file_exists($file3D) && is_file($file3D)) {
                    @unlink($file3D);
                }
            }

            $stmtDelete = $pdo->prepare("DELETE FROM master_drawings WHERE id = :id");
            $stmtDelete->execute([':id' => $id]);
            set_flash('success', 'Berkas drawing fisik beserta data referensinya berhasil dihapus!');
        } else {
            set_flash('error', 'Data Drawing tidak ditemukan!');
        }
    } catch (PDOException $e) {
        set_flash('error', 'Gagal menghapus berkas drawing: ' . $e->getMessage());
    }
} else {
    set_flash('error', 'Database tidak terhubung!');
}

redirect('modules/master_drawings/index.php');
