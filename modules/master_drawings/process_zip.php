<?php
/**
 * Handler Ekstraksi & Import Berkas Drawing & Dokumen Mutu Massal via ZIP
 * Menangani ratusan folder part (uploads/parts/{PartCode}/) dalam satu file ZIP.
 */
@ini_set('memory_limit', '512M');
@set_time_limit(300);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';
require_once __DIR__ . '/../../config/document_helper.php';

require_menu_access('master_drawings');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/master_drawings/index.php');
}

csrf_verify();

$pdo = getDB();
if (!$pdo) {
    set_flash('error', 'Koneksi database gagal terhubung.');
    redirect('modules/master_drawings/index.php');
}

if (!isset($_FILES['file_zip']) || $_FILES['file_zip']['error'] !== UPLOAD_ERR_OK) {
    set_flash('error', 'Silakan pilih berkas ZIP yang valid untuk diunggah.');
    redirect('modules/master_drawings/index.php');
}

$uploadedFile = $_FILES['file_zip']['tmp_name'];
$originalName = $_FILES['file_zip']['name'];
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

if ($ext !== 'zip') {
    set_flash('error', 'Format berkas harus berupa .ZIP!');
    redirect('modules/master_drawings/index.php');
}

$zip = new ZipArchive();
if ($zip->open($uploadedFile) !== true) {
    set_flash('error', 'Gagal membuka berkas ZIP. Pastikan arsip ZIP tidak korup.');
    redirect('modules/master_drawings/index.php');
}

$rootAppDir = realpath(__DIR__ . '/../..');
$targetPartsBase = $rootAppDir . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'parts';
if (!is_dir($targetPartsBase)) {
    @mkdir($targetPartsBase, 0777, true);
}

// Ambil index Part Code yang terdaftar di database untuk validasi & update
$stmtAllParts = $pdo->query("SELECT id, part_code, part_name FROM master_parts");
$validPartsMap = [];
while ($pRow = $stmtAllParts->fetch(PDO::FETCH_ASSOC)) {
    $codeClean = strtoupper(trim($pRow['part_code']));
    $validPartsMap[$codeClean] = [
        'id' => (int)$pRow['id'],
        'part_code' => $pRow['part_code'],
        'part_name' => $pRow['part_name'],
    ];
}

$processedParts = [];
$totalFilesSaved = 0;
$skippedEntries = [];

$numFiles = $zip->numFiles;

for ($i = 0; $i < $numFiles; $i++) {
    $entryName = $zip->getNameIndex($i);
    // Abaikan metadata OS (seperti __MACOSX / .DS_Store / Thumbs.db)
    if (strpos($entryName, '__MACOSX') !== false || strpos($entryName, '.DS_Store') !== false || strpos($entryName, 'Thumbs.db') !== false) {
        continue;
    }

    // Normalisasi separator path
    $entryClean = str_replace('\\', '/', $entryName);
    $entryClean = trim($entryClean, '/');
    if ($entryClean === '') continue;

    $pathParts = explode('/', $entryClean);
    $fileName = end($pathParts);
    if ($fileName === '' || substr($entryName, -1) === '/') {
        // Ini adalah entri direktori
        continue;
    }

    // Resolusi Cerdas Part Code:
    // 1. Periksa segmen folder dari yang paling dalam (terdekat dengan file) hingga yang terluar
    // 2. Fallback: Deteksi dari awalan nama file itu sendiri (misal: "1768125-00.pdf" -> "1768125")
    $matchedPart = null;
    $folderSegments = array_slice($pathParts, 0, -1);
    
    // Scan folder segments dari belakang (bottom-up traversal)
    for ($k = count($folderSegments) - 1; $k >= 0; $k--) {
        $seg = trim($folderSegments[$k]);
        if ($seg === '') continue;

        // Cek langsung
        $segUpper = strtoupper($seg);
        if (isset($validPartsMap[$segUpper])) {
            $matchedPart = $validPartsMap[$segUpper];
            break;
        }

        // Cek dengan token extractor
        $extracted = strtoupper(extract_part_code_from_string($seg));
        if ($extracted !== '' && isset($validPartsMap[$extracted])) {
            $matchedPart = $validPartsMap[$extracted];
            break;
        }

        // Cek jika ada pola seperti "1768125_..."
        $firstWord = strtoupper(explode('_', $seg)[0]);
        if (isset($validPartsMap[$firstWord])) {
            $matchedPart = $validPartsMap[$firstWord];
            break;
        }
        $firstDash = strtoupper(explode('-', $seg)[0]);
        if (isset($validPartsMap[$firstDash])) {
            $matchedPart = $validPartsMap[$firstDash];
            break;
        }
        $firstSpace = strtoupper(explode(' ', $seg)[0]);
        if (isset($validPartsMap[$firstSpace])) {
            $matchedPart = $validPartsMap[$firstSpace];
            break;
        }
    }

    // Fallback: Jika tidak ada segmen folder yang cocok, cek nama file
    if (!$matchedPart) {
        $fileClean = strtoupper(extract_part_code_from_string($fileName));
        if ($fileClean !== '' && isset($validPartsMap[$fileClean])) {
            $matchedPart = $validPartsMap[$fileClean];
        } else {
            $fileFirstWord = strtoupper(explode('_', $fileName)[0]);
            if (isset($validPartsMap[$fileFirstWord])) {
                $matchedPart = $validPartsMap[$fileFirstWord];
            } else {
                $fileFirstDash = strtoupper(explode('-', $fileName)[0]);
                if (isset($validPartsMap[$fileFirstDash])) {
                    $matchedPart = $validPartsMap[$fileFirstDash];
                }
            }
        }
    }

    if (!$matchedPart) {
        $skipKey = count($folderSegments) > 0 ? implode('/', $folderSegments) : 'Root Files';
        $skippedEntries[$skipKey] = ($skippedEntries[$skipKey] ?? 0) + 1;
        continue;
    }

    $partData = $matchedPart;
    $partId = $partData['id'];
    $officialCode = $partData['part_code'];

    // Klasifikasikan jenis dokumen
    $docType = classify_part_file_type($fileName);
    if ($docType === 'unknown') {
        continue;
    }

    // Siapkan direktori penyimpanan: uploads/parts/{OfficialCode}/
    $partDir = $targetPartsBase . DIRECTORY_SEPARATOR . $officialCode;
    if (!is_dir($partDir)) {
        @mkdir($partDir, 0777, true);
    }

    // Tentukan nama file yang rapi di server
    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $destFileName = $fileName;

    // Baca konten file dari ZIP stream
    $stream = $zip->getStream($entryName);
    if (!$stream) continue;

    $destPath = $partDir . DIRECTORY_SEPARATOR . $destFileName;
    $out = fopen($destPath, 'wb');
    if ($out) {
        while (!feof($stream)) {
            fwrite($out, fread($stream, 8192));
        }
        fclose($out);
        fclose($stream);
        $totalFilesSaved++;

        if (!isset($processedParts[$officialCode])) {
            $processedParts[$officialCode] = [
                'part_id' => $partId,
                'part_name' => $partData['part_name'],
                'files' => [],
                'has_2d' => false,
                'has_3d' => false,
            ];
        }

        $relPath = 'uploads/parts/' . $officialCode . '/' . $destFileName;
        $processedParts[$officialCode]['files'][] = [
            'type' => $docType,
            'file_name' => $destFileName,
            'rel_path' => $relPath
        ];

        if ($docType === 'drawing_2d') {
            $processedParts[$officialCode]['has_2d'] = $relPath;
        } elseif ($docType === 'drawing_3d') {
            $processedParts[$officialCode]['has_3d'] = $relPath;
        }
    } else {
        fclose($stream);
    }
}

$zip->close();

// Update database master_drawings untuk part yang berhasil diproses
$stmtCheckDraw = $pdo->prepare("SELECT id, drawing_2d_path, drawing_3d_path FROM master_drawings WHERE part_id = :part_id");
$stmtInsertDraw = $pdo->prepare("INSERT INTO master_drawings (part_id, drawing_2d_path, drawing_3d_path, updated_at) VALUES (:part_id, :path_2d, :path_3d, NOW())");
$stmtUpdateDraw = $pdo->prepare("UPDATE master_drawings SET drawing_2d_path = COALESCE(:path_2d, drawing_2d_path), drawing_3d_path = COALESCE(:path_3d, drawing_3d_path), updated_at = NOW() WHERE part_id = :part_id");

$totalPartsUpdated = count($processedParts);

foreach ($processedParts as $code => $pInfo) {
    $pId = $pInfo['part_id'];
    $path2D = $pInfo['has_2d'] ?: null;
    $path3D = $pInfo['has_3d'] ?: null;

    if ($path2D || $path3D) {
        $stmtCheckDraw->execute([':part_id' => $pId]);
        $existing = $stmtCheckDraw->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $stmtUpdateDraw->execute([
                ':path_2d' => $path2D,
                ':path_3d' => $path3D,
                ':part_id' => $pId
            ]);
        } else {
            $stmtInsertDraw->execute([
                ':part_id' => $pId,
                ':path_2d' => $path2D,
                ':path_3d' => $path3D
            ]);
        }
    }
}

$msg = "Import ZIP Berhasil! Sebanyak {$totalPartsUpdated} Part diproses, {$totalFilesSaved} berkas dokumen tersimpan ke uploads/parts/.";
if (!empty($skippedEntries)) {
    $skippedCount = count($skippedEntries);
    $msg .= " ({$skippedCount} folder dilewati karena Part Code tidak terdaftar).";
}

set_flash('success', $msg);
redirect('modules/master_drawings/index.php');
