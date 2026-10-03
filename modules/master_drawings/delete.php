<?php
/**
 * Action Handler: Delete Master Drawing Files
 * Supports deletion via part_id or drawing id, removing physical files from folder
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

$part_id = filter_input(INPUT_GET, 'part_id', FILTER_VALIDATE_INT);
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$part_id && !$id) {
    set_flash('error', 'Parameter drawing atau part tidak valid!');
    redirect('modules/master_drawings/index.php');
}

$pdo = getDB();

if ($pdo) {
    try {
        if ($part_id) {
            $stmtPart = $pdo->prepare("
                SELECT p.*, COALESCE(m.name, p.model, '') as model_name 
                FROM master_parts p 
                LEFT JOIN master_models m ON m.id = p.model_id 
                WHERE p.id = :id
            ");
            $stmtPart->execute([':id' => $part_id]);
            $part = $stmtPart->fetch();

            if ($part) {
                // Delete physical files found in folder
                $fs = get_part_drawing_assets($part['part_code'], $part['model_name']);
                if (!empty($fs['drawing_2d_path'])) {
                    $file2D = __DIR__ . '/../../' . $fs['drawing_2d_path'];
                    if (file_exists($file2D) && is_file($file2D)) {
                        @unlink($file2D);
                    }
                }
                if (!empty($fs['drawing_3d_path'])) {
                    $file3D = __DIR__ . '/../../' . $fs['drawing_3d_path'];
                    if (file_exists($file3D) && is_file($file3D)) {
                        @unlink($file3D);
                    }
                }

                // Delete database record if exists
                $stmtDel = $pdo->prepare("DELETE FROM master_drawings WHERE part_id = :part_id");
                $stmtDel->execute([':part_id' => $part_id]);

                set_flash('success', 'Berkas drawing untuk part ini berhasil dihapus!');
            } else {
                set_flash('error', 'Part tidak ditemukan!');
            }
        } elseif ($id) {
            $stmtSelect = $pdo->prepare("SELECT * FROM master_drawings WHERE id = :id");
            $stmtSelect->execute([':id' => $id]);
            $drawing = $stmtSelect->fetch();

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

                $stmtDelete = $pdo->prepare("DELETE FROM master_drawings WHERE id = :id");
                $stmtDelete->execute([':id' => $id]);
                set_flash('success', 'Berkas drawing fisik beserta data referensinya berhasil dihapus!');
            } else {
                set_flash('error', 'Data Drawing tidak ditemukan!');
            }
        }
    } catch (PDOException $e) {
        set_flash('error', 'Gagal menghapus berkas drawing: ' . $e->getMessage());
    }
} else {
    set_flash('error', 'Database tidak terhubung!');
}

redirect('modules/master_drawings/index.php');

