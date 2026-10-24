<?php
/**
 * Action Handler: Store/Update Master Drawing (2D/3D)
 * Automatically creates Model and Part directories based on filesystem conventions
 * Target: uploads/drawings/[ModelName]/[PartCode] _ [PartName]/
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

$currentUser = function_exists('current_user') ? current_user() : null;
$createdById = !empty($currentUser['id']) ? (int)$currentUser['id'] : 1;

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

// 2. Siapkan Direktori Terpusat Part: uploads/parts/{PartCode}/
$rootAppDir = realpath(__DIR__ . '/../..');
$unifiedPartDir = $rootAppDir . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'parts' . DIRECTORY_SEPARATOR . $cleanPartCode;
if (!is_dir($unifiedPartDir)) {
    mkdir($unifiedPartDir, 0777, true);
}

// 3. Fetch existing database record (if any)
$stmtCheck = $pdo->prepare("SELECT * FROM master_drawings WHERE part_id = :part_id");
$stmtCheck->execute([':part_id' => $part_id]);
$existing = $stmtCheck->fetch();

$path2D = $existing['drawing_2d_path'] ?? null;
$path3D = $existing['drawing_3d_path'] ?? null;
$uploadedAny = false;

// Handle 2D File Upload (.pdf) -> Simpan ke uploads/parts/{PartCode}/ dengan preservasi versi
if (isset($_FILES['file_2d']) && $_FILES['file_2d']['error'] === UPLOAD_ERR_OK) {
    $origName = $_FILES['file_2d']['name'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext === 'pdf') {
        $destFilename2D = resolve_safe_document_upload_name($cleanPartCode, 'drawing_2d', $origName, $unifiedPartDir);
        $destPath2D = $unifiedPartDir . DIRECTORY_SEPARATOR . $destFilename2D;
        if (move_uploaded_file($_FILES['file_2d']['tmp_name'], $destPath2D)) {
            $path2D = 'uploads/parts/' . $cleanPartCode . '/' . $destFilename2D;
            $uploadedAny = true;
        }
    } else {
        set_flash('warning', 'Berkas 2D harus berformat PDF!');
    }
}

// Handle 3D File Upload (.stp / .step) -> Simpan ke uploads/parts/{PartCode}/ dengan preservasi versi
if (isset($_FILES['file_3d']) && $_FILES['file_3d']['error'] === UPLOAD_ERR_OK) {
    $origName = $_FILES['file_3d']['name'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext === 'stp' || $ext === 'step') {
        $destFilename3D = resolve_safe_document_upload_name($cleanPartCode, 'drawing_3d', $origName, $unifiedPartDir);
        $destPath3D = $unifiedPartDir . DIRECTORY_SEPARATOR . $destFilename3D;
        if (move_uploaded_file($_FILES['file_3d']['tmp_name'], $destPath3D)) {
            $path3D = 'uploads/parts/' . $cleanPartCode . '/' . $destFilename3D;
            $uploadedAny = true;
        }
    } else {
        set_flash('warning', 'Berkas 3D harus berformat STP atau STEP!');
    }
}

// Handle 4 QC Documents (PI, Q-Point, PAC, Standar Packing) -> Multi-Versi Cerdas & Tidak Menimpa Versi Lama
$qcDocConfigs = [
    'file_pi'              => ['type' => 'pi',              'label' => 'Process Inspection (PI)'],
    'file_qpoint'          => ['type' => 'qpoint',          'label' => 'Quality Point (Q-Point)'],
    'file_pac'             => ['type' => 'pac',             'label' => 'Part Approval Certificate (PAC)'],
    'file_standar_packing' => ['type' => 'standar_packing', 'label' => 'Standar Packing']
];

foreach ($qcDocConfigs as $inputKey => $cfg) {
    if (isset($_FILES[$inputKey]) && $_FILES[$inputKey]['error'] === UPLOAD_ERR_OK) {
        $origName = $_FILES[$inputKey]['name'];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if ($ext === 'pdf') {
            $safeDocName = resolve_safe_document_upload_name($cleanPartCode, $cfg['type'], $origName, $unifiedPartDir);
            $destDocPath = $unifiedPartDir . DIRECTORY_SEPARATOR . $safeDocName;
            if (move_uploaded_file($_FILES[$inputKey]['tmp_name'], $destDocPath)) {
                $uploadedAny = true;
            }
        } else {
            set_flash('warning', 'Berkas ' . $cfg['label'] . ' harus berformat PDF!');
        }
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
            VALUES (:part_id, :p2d, :p3d, :uploaded_by, NOW(), NOW())
        ");
        $stmtInsert->execute([
            ':part_id'     => $part_id,
            ':p2d'         => $path2D,
            ':p3d'         => $path3D,
            ':uploaded_by' => $createdById
        ]);
    }
    set_flash('success', 'Berkas drawing dan dokumen mutu berhasil disimpan!');
} else {
    if (!isset($_SESSION['flash'])) {
        set_flash('warning', 'Tidak ada berkas yang dipilih untuk diunggah.');
    }
}

redirect('modules/master_drawings/detail.php?part_id=' . $part_id);

