<?php
/**
 * Action Handler: Store/Update Master Drawing (2D/3D)
 * Automatically creates Model and Part directories based on filesystem conventions
 * Target: uploads/drawings/[ModelName]/[PartCode] _ [PartName]/
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/master_drawings/index.php');
}

$part_id = (int)($_POST['part_id'] ?? 0);

if (!$part_id) {
    set_flash('error', 'Part ID tidak valid!');
    redirect('modules/master_drawings/index.php');
}

$pdo = getDB();

if (!$pdo) {
    set_flash('error', 'Database tidak terhubung!');
    redirect('modules/master_drawings/index.php');
}

// 1. Fetch Part & Model details
$stmtPart = $pdo->prepare("
    SELECT p.*, COALESCE(m.name, p.model, '') as model_name 
    FROM master_parts p 
    LEFT JOIN master_models m ON m.id = p.model_id 
    WHERE p.id = :id
");
$stmtPart->execute([':id' => $part_id]);
$part = $stmtPart->fetch();

if (!$part) {
    set_flash('error', 'Data part tidak ditemukan!');
    redirect('modules/master_drawings/index.php');
}

$cleanPartCode = trim((string)$part['part_code']);
$rawPartName   = trim((string)$part['part_name']);
$cleanPartName = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $rawPartName);

$rawModelName  = trim((string)$part['model_name']);
$cleanModel    = !empty($rawModelName) ? str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $rawModelName) : 'GENERAL';

// Base Drawings Directory
$rootAppDir = realpath(__DIR__ . '/../..');
$baseDir = $rootAppDir . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'drawings';
if (!is_dir($baseDir)) {
    mkdir($baseDir, 0777, true);
}

// 2. Locate or Create Target Model Directory
$modelDir = $baseDir . DIRECTORY_SEPARATOR . $cleanModel;
if (!is_dir($modelDir)) {
    // Check case-insensitive match first
    $subdirs = glob($baseDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
    $foundModelDir = null;
    if ($subdirs) {
        foreach ($subdirs as $sd) {
            if (strcasecmp(basename($sd), $cleanModel) === 0) {
                $foundModelDir = $sd;
                break;
            }
        }
    }
    if ($foundModelDir) {
        $modelDir = $foundModelDir;
    } else {
        mkdir($modelDir, 0777, true);
    }
}

// 3. Locate or Create Target Part Directory inside Model Directory
$targetPartDir = find_part_folder_in_dir($modelDir, $cleanPartCode);

// If not in model dir, check across other model folders (maybe part was placed elsewhere)
if (!$targetPartDir) {
    $existingFs = get_part_drawing_assets($cleanPartCode, $cleanModel);
    if (!empty($existingFs['folder_found']) && is_dir($existingFs['folder_found'])) {
        $targetPartDir = $existingFs['folder_found'];
    }
}

// If still not found, create new part folder
if (!$targetPartDir || !is_dir($targetPartDir)) {
    $folderName = $cleanPartCode . ' _ ' . $cleanPartName;
    $targetPartDir = $modelDir . DIRECTORY_SEPARATOR . $folderName;
    if (!is_dir($targetPartDir)) {
        mkdir($targetPartDir, 0777, true);
    }
}

// 4. Fetch existing database record (if any)
$stmtCheck = $pdo->prepare("SELECT * FROM master_drawings WHERE part_id = :part_id");
$stmtCheck->execute([':part_id' => $part_id]);
$existing = $stmtCheck->fetch();

$path2D = $existing['drawing_2d_path'] ?? null;
$path3D = $existing['drawing_3d_path'] ?? null;
$uploadedAny = false;

// Handle 2D File Upload (.pdf)
if (isset($_FILES['file_2d']) && $_FILES['file_2d']['error'] === UPLOAD_ERR_OK) {
    $ext = strtolower(pathinfo($_FILES['file_2d']['name'], PATHINFO_EXTENSION));
    if ($ext === 'pdf') {
        // Clean up old pdf files in target folder if different name
        $existingFiles = glob($targetPartDir . DIRECTORY_SEPARATOR . '*.pdf');
        if ($existingFiles) {
            foreach ($existingFiles as $ef) {
                if (is_file($ef)) {
                    @unlink($ef);
                }
            }
        }

        $destFilename2D = $cleanPartCode . '.pdf';
        $destPath2D = $targetPartDir . DIRECTORY_SEPARATOR . $destFilename2D;
        if (move_uploaded_file($_FILES['file_2d']['tmp_name'], $destPath2D)) {
            $rel2D = str_replace($rootAppDir . DIRECTORY_SEPARATOR, '', $destPath2D);
            $path2D = str_replace('\\', '/', $rel2D);
            $uploadedAny = true;
        }
    } else {
        set_flash('warning', 'Berkas 2D harus berformat PDF!');
    }
}

// Handle 3D File Upload (.stp / .step)
if (isset($_FILES['file_3d']) && $_FILES['file_3d']['error'] === UPLOAD_ERR_OK) {
    $ext = strtolower(pathinfo($_FILES['file_3d']['name'], PATHINFO_EXTENSION));
    if ($ext === 'stp' || $ext === 'step') {
        // Clean up old stp/step files in target folder if different name
        $existingStp = glob($targetPartDir . DIRECTORY_SEPARATOR . '*.{stp,step}', GLOB_BRACE);
        if ($existingStp) {
            foreach ($existingStp as $es) {
                if (is_file($es)) {
                    @unlink($es);
                }
            }
        }

        $destFilename3D = $cleanPartCode . '.' . $ext;
        $destPath3D = $targetPartDir . DIRECTORY_SEPARATOR . $destFilename3D;
        if (move_uploaded_file($_FILES['file_3d']['tmp_name'], $destPath3D)) {
            $rel3D = str_replace($rootAppDir . DIRECTORY_SEPARATOR, '', $destPath3D);
            $path3D = str_replace('\\', '/', $rel3D);
            $uploadedAny = true;
        }
    } else {
        set_flash('warning', 'Berkas 3D harus berformat .STP atau .STEP!');
    }
}

// 5. Update or Insert Database Record for Synchronization
if ($uploadedAny) {
    // If paths are still null, double check if physical files exist in target folder
    $resolvedFs = get_part_drawing_assets($cleanPartCode, $cleanModel);
    if (!empty($resolvedFs['drawing_2d_path'])) $path2D = $resolvedFs['drawing_2d_path'];
    if (!empty($resolvedFs['drawing_3d_path'])) $path3D = $resolvedFs['drawing_3d_path'];

    if ($existing) {
        $stmtUpdate = $pdo->prepare("
            UPDATE master_drawings 
            SET drawing_2d_path = :p2d, drawing_3d_path = :p3d, updated_at = NOW() 
            WHERE part_id = :part_id
        ");
        $stmtUpdate->execute([
            ':p2d' => $path2D,
            ':p3d' => $path3D,
            ':part_id' => $part_id
        ]);
    } else {
        $stmtInsert = $pdo->prepare("
            INSERT INTO master_drawings (part_id, drawing_2d_path, drawing_3d_path, uploaded_by, created_at, updated_at) 
            VALUES (:part_id, :p2d, :p3d, 1, NOW(), NOW())
        ");
        $stmtInsert->execute([
            ':part_id' => $part_id,
            ':p2d' => $path2D,
            ':p3d' => $path3D
        ]);
    }
    set_flash('success', 'Berkas drawing berhasil disimpan ke dalam folder part!');
} else {
    if (!isset($_SESSION['flash'])) {
        set_flash('warning', 'Tidak ada berkas yang dipilih untuk diunggah.');
    }
}

redirect('modules/master_drawings/detail.php?part_id=' . $part_id);

