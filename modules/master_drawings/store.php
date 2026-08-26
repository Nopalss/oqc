<?php
/**
 * Action Handler: Store/Update Master Drawing (2D/3D)
 * Automatically deletes old physical files when new files are uploaded
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/master_drawings/index.php');
}

$part_id = filter_input(INPUT_POST, 'part_id', FILTER_VALIDATE_INT);

if (!$part_id) {
    set_flash('error', 'Part ID tidak valid!');
    redirect('modules/master_drawings/index.php');
}

$pdo = getDB();

if (!$pdo) {
    set_flash('error', 'Database tidak terhubung!');
    redirect('modules/master_drawings/index.php');
}

// Ensure target directories exist
$uploadDir2D = __DIR__ . '/../../uploads/drawings/2d/';
$uploadDir3D = __DIR__ . '/../../uploads/drawings/3d/';

if (!is_dir($uploadDir2D)) {
    mkdir($uploadDir2D, 0777, true);
}
if (!is_dir($uploadDir3D)) {
    mkdir($uploadDir3D, 0777, true);
}

// Fetch existing record if any
$stmtCheck = $pdo->prepare("SELECT * FROM master_drawings WHERE part_id = :part_id");
$stmtCheck->execute([':part_id' => $part_id]);
$existing = $stmtCheck->fetch();

$path2D = $existing['drawing_2d_path'] ?? null;
$path3D = $existing['drawing_3d_path'] ?? null;
$uploaded = false;

// Handle 2D File Upload (.pdf)
if (isset($_FILES['file_2d']) && $_FILES['file_2d']['error'] === UPLOAD_ERR_OK) {
    $ext = strtolower(pathinfo($_FILES['file_2d']['name'], PATHINFO_EXTENSION));
    if ($ext === 'pdf') {
        $filename2D = '2D_part_' . $part_id . '_' . time() . '.pdf';
        $targetPath = $uploadDir2D . $filename2D;
        if (move_uploaded_file($_FILES['file_2d']['tmp_name'], $targetPath)) {
            // Delete old physical 2D PDF file if exists
            if (!empty($existing['drawing_2d_path'])) {
                $oldFile2D = __DIR__ . '/../../' . $existing['drawing_2d_path'];
                if (file_exists($oldFile2D) && is_file($oldFile2D)) {
                    @unlink($oldFile2D);
                }
            }
            $path2D = 'uploads/drawings/2d/' . $filename2D;
            $uploaded = true;
        }
    } else {
        set_flash('warning', 'File 2D harus berformat PDF!');
    }
}

// Handle 3D File Upload (.stp / .step)
if (isset($_FILES['file_3d']) && $_FILES['file_3d']['error'] === UPLOAD_ERR_OK) {
    $ext = strtolower(pathinfo($_FILES['file_3d']['name'], PATHINFO_EXTENSION));
    if ($ext === 'stp' || $ext === 'step') {
        $filename3D = '3D_part_' . $part_id . '_' . time() . '.' . $ext;
        $targetPath = $uploadDir3D . $filename3D;
        if (move_uploaded_file($_FILES['file_3d']['tmp_name'], $targetPath)) {
            // Delete old physical 3D STP file if exists
            if (!empty($existing['drawing_3d_path'])) {
                $oldFile3D = __DIR__ . '/../../' . $existing['drawing_3d_path'];
                if (file_exists($oldFile3D) && is_file($oldFile3D)) {
                    @unlink($oldFile3D);
                }
            }
            $path3D = 'uploads/drawings/3d/' . $filename3D;
            $uploaded = true;
        }
    } else {
        set_flash('warning', 'File 3D harus berformat .STP atau .STEP!');
    }
}

if ($existing) {
    $stmtUpdate = $pdo->prepare("UPDATE master_drawings SET drawing_2d_path = :p2d, drawing_3d_path = :p3d, updated_at = NOW() WHERE part_id = :part_id");
    $stmtUpdate->execute([
        ':p2d' => $path2D,
        ':p3d' => $path3D,
        ':part_id' => $part_id
    ]);
    set_flash('success', 'File drawing berhasil diperbarui dan file lama telah dihapus otomatis!');
} else {
    if ($path2D || $path3D) {
        $stmtInsert = $pdo->prepare("INSERT INTO master_drawings (part_id, drawing_2d_path, drawing_3d_path, uploaded_by, created_at) VALUES (:part_id, :p2d, :p3d, 1, NOW())");
        $stmtInsert->execute([
            ':part_id' => $part_id,
            ':p2d' => $path2D,
            ':p3d' => $path3D
        ]);
        set_flash('success', 'File drawing berhasil diunggah!');
    } else {
        set_flash('warning', 'Tidak ada file yang dipilih untuk diunggah.');
    }
}

redirect('modules/master_drawings/index.php');
