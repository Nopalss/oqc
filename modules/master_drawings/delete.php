<?php
/**
 * Action Handler: Delete Master Drawing Files
 * Supports deletion via part_id or drawing id, removing physical files safely
 * Protected with RBAC, CSRF token, and POST method
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

require_menu_access('master_drawings');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/master_drawings/index.php');
}

csrf_verify();

$part_id = filter_input(INPUT_POST, 'part_id', FILTER_VALIDATE_INT);
$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

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

                // Hapus berkas dan folder terpusat part di uploads/parts/{PartCode}/
                $cleanPCode = trim($part['part_code']);
                if ($cleanPCode !== '') {
                    $rootAppDir = realpath(__DIR__ . '/../..');
                    $unifiedPartDir = $rootAppDir ? ($rootAppDir . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'parts' . DIRECTORY_SEPARATOR . $cleanPCode) : null;
                    if ($unifiedPartDir && is_dir($unifiedPartDir)) {
                        $partFiles = glob($unifiedPartDir . DIRECTORY_SEPARATOR . '*');
                        if ($partFiles) {
                            foreach ($partFiles as $pf) {
                                if (is_file($pf)) {
                                    @unlink($pf);
                                }
                            }
                        }
                        @rmdir($unifiedPartDir);
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
