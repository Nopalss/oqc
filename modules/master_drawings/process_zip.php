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

// Hanya role pengelola (admin, chief, inspektur/inspektor) yang diizinkan memproses import ZIP
if (!can_manage_drawings()) {
    set_flash('danger', 'Akses ditolak: Anda tidak memiliki izin untuk mengimpor berkas drawing.');
    redirect('modules/master_drawings/index.php');
    exit;
}

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

    // Fallback: Jika tidak ada segmen folder yang cocok di map, cek nama file
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

    // JIKA PART CODE BELUM TERDAFTAR DI DATABASE:
    // Auto-create part code tersebut ke tabel master_parts agar tidak ditolak
    if (!$matchedPart) {
        $detectedCode = '';
        $detectedName = '';

        if (!empty($folderSegments)) {
            $candidateFolder = trim(end($folderSegments));
            $detectedCode = extract_part_code_from_string($candidateFolder);
            
            // Coba ambil nama part dari sisa nama folder setelah underscore atau pemisah
            if (strpos($candidateFolder, '_') !== false) {
                $partsExplode = explode('_', $candidateFolder, 2);
                $detectedName = trim($partsExplode[1] ?? '');
            } elseif (strpos($candidateFolder, ' ') !== false) {
                $partsExplode = explode(' ', $candidateFolder, 2);
                $detectedName = trim($partsExplode[1] ?? '');
            }
        }

        if ($detectedCode === '') {
            $detectedCode = extract_part_code_from_string($fileName);
        }

        $detectedCode = strtoupper(trim($detectedCode));

        if ($detectedCode !== '') {
            if ($detectedName === '') {
                $detectedName = $detectedCode;
            }

            // Daftarkan part baru ke master_parts
            $currentUserId = $_SESSION['user_id'] ?? null;
            try {
                $stmtInsertPart = $pdo->prepare("INSERT INTO master_parts (part_code, part_name, aql_level, source, created_by, created_at) VALUES (:part_code, :part_name, '1.0', 'import_zip', :created_by, NOW())");
                $stmtInsertPart->execute([
                    ':part_code'  => $detectedCode,
                    ':part_name'  => $detectedName,
                    ':created_by' => $currentUserId
                ]);
                $newId = (int)$pdo->lastInsertId();

                $matchedPart = [
                    'id' => $newId,
                    'part_code' => $detectedCode,
                    'part_name' => $detectedName,
                    'is_new' => true,
                ];

                // Cache in-memory agar iterasi file berikutnya langsung mengenalinya
                $validPartsMap[$detectedCode] = $matchedPart;
            } catch (PDOException $e) {
                // Jika race condition atau part_code ternyata baru masuk
                $stmtFetch = $pdo->prepare("SELECT id, part_code, part_name FROM master_parts WHERE part_code = :pc");
                $stmtFetch->execute([':pc' => $detectedCode]);
                $exRow = $stmtFetch->fetch(PDO::FETCH_ASSOC);
                if ($exRow) {
                    $matchedPart = [
                        'id' => (int)$exRow['id'],
                        'part_code' => $exRow['part_code'],
                        'part_name' => $exRow['part_name'],
                        'is_new' => false,
                    ];
                    $validPartsMap[$detectedCode] = $matchedPart;
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
                'is_new' => !empty($partData['is_new']),
                'files' => [],
                'has_2d' => false,
                'has_3d' => false,
                'has_pi' => false,
                'has_qpoint' => false,
                'has_pac' => false,
                'has_packing' => false,
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
        } elseif ($docType === 'pi') {
            $processedParts[$officialCode]['has_pi'] = true;
        } elseif ($docType === 'qpoint') {
            $processedParts[$officialCode]['has_qpoint'] = true;
        } elseif ($docType === 'pac') {
            $processedParts[$officialCode]['has_pac'] = true;
        } elseif ($docType === 'standar_packing') {
            $processedParts[$officialCode]['has_packing'] = true;
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
$totalNewParts = 0;

foreach ($processedParts as $code => &$pInfo) {
    if (!empty($pInfo['is_new'])) {
        $totalNewParts++;
    }

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
unset($pInfo);

// Simpan laporan lengkap hasil import ke session agar bisa ditampilkan di halaman import_result.php
$_SESSION['import_zip_report'] = [
    'timestamp' => date('Y-m-d H:i:s'),
    'zip_name' => $originalName,
    'total_parts' => $totalPartsUpdated,
    'total_new_parts' => $totalNewParts,
    'total_files_saved' => $totalFilesSaved,
    'parts' => $processedParts,
    'skipped_entries' => $skippedEntries,
];

set_flash('success', "Import ZIP Selesai! {$totalPartsUpdated} Part diproses ({$totalNewParts} Part baru otomatis didaftarkan), {$totalFilesSaved} berkas tersimpan.");
redirect('modules/master_drawings/import_result.php');

