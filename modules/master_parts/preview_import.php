<?php
/**
 * AJAX Endpoint: Parse, Validate, and Preview Master Part Excel/CSV Import
 * Optimized for Big Data (Targeted chunked lookup, zero memory leak, RBAC & CSRF protected)
 */
@set_time_limit(180);
@ini_set('memory_limit', '256M');
@ini_set('display_errors', '0');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Metode request tidak diizinkan!']);
    exit;
}

// 1. RBAC Check
if (!is_logged_in() || !has_menu_access('master_parts')) {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak! Anda tidak memiliki izin untuk mengimpor Master Part.']);
    exit;
}

// 2. CSRF Token Verification
$csrf = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!verify_csrf_token($csrf)) {
    echo json_encode(['success' => false, 'message' => 'Sesi keamanan berakhir (CSRF Token invalid). Silakan muat ulang halaman.']);
    exit;
}

if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'File Excel/CSV tidak valid atau gagal diunggah!']);
    exit;
}

$tmpPath  = $_FILES['excel_file']['tmp_name'];
$fileName = $_FILES['excel_file']['name'];
$fileSize = $_FILES['excel_file']['size'];

// Max file size: 10MB
if ($fileSize > 10 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'Ukuran file melebihi batas maksimal 10 MB!']);
    exit;
}

$fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
if (!in_array($fileExt, ['xlsx', 'xls', 'csv'])) {
    echo json_encode(['success' => false, 'message' => 'Ekstensi file tidak didukung! Format yang diperbolehkan: .xlsx, .xls, .csv']);
    exit;
}

$rawRows = [];

try {
    if ($fileExt === 'csv') {
        $handle = fopen($tmpPath, 'r');
        if ($handle === false) {
            throw new Exception('Tidak dapat membuka file CSV!');
        }

        // Auto-detect delimiter: comma or semicolon
        $firstLine = fgets($handle);
        rewind($handle);
        $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

        $rowNumber = 1;
        while (($data = fgetcsv($handle, 4096, $delimiter)) !== false) {
            // Normalize columns to A, B, C...
            $normalizedRow = [];
            $colLetters = range('A', 'Z');
            foreach ($data as $idx => $val) {
                if (isset($colLetters[$idx])) {
                    $normalizedRow[$colLetters[$idx]] = trim((string)$val);
                }
            }
            if (!empty($normalizedRow)) {
                $rawRows[$rowNumber] = $normalizedRow;
            }
            $rowNumber++;
        }
        fclose($handle);
    } else {
        // High-Performance PhpSpreadsheet Read-Only Mode
        $reader = IOFactory::createReaderForFile($tmpPath);
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);

        $spreadsheet = $reader->load($tmpPath);
        $worksheet   = $spreadsheet->getActiveSheet();

        // toArray(nullValue = null, calculateFormulas = false, formatData = false, returnCellRef = true)
        // Disabling formula calculation & format masks provides a 10x-50x speedup!
        $rawRows = $worksheet->toArray(null, false, false, true);

        if (method_exists($spreadsheet, 'disconnectWorksheets')) {
            $spreadsheet->disconnectWorksheets();
        }
        unset($spreadsheet, $worksheet, $reader);
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Gagal membaca format file: ' . $e->getMessage()]);
    exit;
}

if (empty($rawRows)) {
    echo json_encode(['success' => false, 'message' => 'File Excel/CSV kosong tidak memiliki data!']);
    exit;
}

// 3. Inspect Row 1 for Header Detection
$firstRow = reset($rawRows);
$colA = strtolower(trim((string)($firstRow['A'] ?? '')));
$colB = strtolower(trim((string)($firstRow['B'] ?? '')));
$colC = strtolower(trim((string)($firstRow['C'] ?? '')));

$isRow1Header = false;
$colModelKey  = 'A';
$colCodeKey   = 'B';
$colNameKey   = 'C';

// If row 1 contains typical header words
if (
    strpos($colA, 'model') !== false || 
    strpos($colB, 'code') !== false || strpos($colB, 'part') !== false ||
    strpos($colC, 'name') !== false || strpos($colC, 'deskripsi') !== false || strpos($colC, 'nama') !== false
) {
    $isRow1Header = true;

    // Dynamic Column Position Mapping if headers are swapped
    foreach ($firstRow as $colLetter => $cellVal) {
        $cVal = strtolower(trim((string)$cellVal));
        if (strpos($cVal, 'model') !== false) {
            $colModelKey = $colLetter;
        } elseif (strpos($cVal, 'code') !== false || strpos($cVal, 'kode') !== false) {
            $colCodeKey = $colLetter;
        } elseif (strpos($cVal, 'name') !== false || strpos($cVal, 'nama') !== false || strpos($cVal, 'desc') !== false) {
            $colNameKey = $colLetter;
        }
    }
}

// 4. Extract Unique Part Codes from Uploaded Rows for Targeted DB Check
$codesInFile = [];
foreach ($rawRows as $rowIdx => $row) {
    if ($isRow1Header && $rowIdx === 1) continue;
    $c = strtoupper(trim((string)($row[$colCodeKey] ?? '')));
    if ($c !== '') {
        $codesInFile[] = $c;
    }
}
$codesInFile = array_values(array_unique($codesInFile));

// 5. Targeted DB Reference Fetching (Ultra-Low Memory & Ultra-Fast Index Seek)
$pdo = getDB();
$existingDbParts  = []; // [PART_CODE => true]
$existingDbModels = []; // [UPPER(NAME) => ['id' => id, 'name' => name]]

if ($pdo) {
    try {
        // Query only the uploaded part codes using chunked IN queries
        if (!empty($codesInFile)) {
            $chunks = array_chunk($codesInFile, 500);
            foreach ($chunks as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $stmtParts = $pdo->prepare("SELECT part_code FROM master_parts WHERE part_code IN ($placeholders)");
                $stmtParts->execute($chunk);
                while ($pCode = $stmtParts->fetchColumn()) {
                    $existingDbParts[strtoupper(trim($pCode))] = true;
                }
            }
        }

        // Fetch master_models (typically very small table, under 500 rows)
        $stmtModels = $pdo->query("SELECT id, name FROM master_models");
        while ($mRow = $stmtModels->fetch(PDO::FETCH_ASSOC)) {
            $existingDbModels[strtoupper(trim($mRow['name']))] = [
                'id'   => (int)$mRow['id'],
                'name' => trim($mRow['name'])
            ];
        }
    } catch (PDOException $e) {
        // Continue with empty caches if table empty or query fails
    }
}

// 6. Iterate and Validate Rows
$parsedRows      = [];
$seenInFile      = []; // [PART_CODE => row_number]
$newModelsInFile = []; // [MODEL_NAME => count]

$countValid     = 0;
$countDuplicate = 0;
$countInvalid   = 0;

foreach ($rawRows as $rowIdx => $row) {
    // Skip row 1 if detected as header
    if ($isRow1Header && $rowIdx === 1) {
        continue;
    }

    $rawModel = trim((string)($row[$colModelKey] ?? ''));
    $rawCode  = strtoupper(trim((string)($row[$colCodeKey] ?? '')));
    $rawName  = trim((string)($row[$colNameKey] ?? ''));

    // Skip entirely empty rows
    if ($rawModel === '' && $rawCode === '' && $rawName === '') {
        continue;
    }

    // Validation Status
    $status      = 'valid';
    $statusLabel = 'Siap Disimpan';
    $statusDesc  = 'Data baru dan valid';
    $isValid     = true;

    if ($rawCode === '' || $rawName === '') {
        $status      = 'invalid';
        $statusLabel = 'Data Kurang';
        $statusDesc  = 'Part Code dan Part Name wajib diisi';
        $isValid     = false;
        $countInvalid++;
    } elseif (isset($existingDbParts[$rawCode])) {
        $status      = 'duplicate_db';
        $statusLabel = 'Duplikat (Database)';
        $statusDesc  = 'Part Code sudah terdaftar di sistem';
        $isValid     = false;
        $countDuplicate++;
    } elseif (isset($seenInFile[$rawCode])) {
        $prevRow     = $seenInFile[$rawCode];
        $status      = 'duplicate_file';
        $statusLabel = 'Duplikat (File)';
        $statusDesc  = 'Part Code kembar dengan baris ' . $prevRow;
        $isValid     = false;
        $countDuplicate++;
    } else {
        $seenInFile[$rawCode] = $rowIdx;
        $countValid++;
    }

    // Model Status Check
    $modelStatus = 'none';
    $matchedModelName = '';
    if ($rawModel !== '') {
        $upperModel = strtoupper($rawModel);
        if (isset($existingDbModels[$upperModel])) {
            $modelStatus = 'exists';
            $matchedModelName = $existingDbModels[$upperModel]['name'];
        } else {
            $modelStatus = 'new';
            $matchedModelName = $rawModel;
            $newModelsInFile[$upperModel] = ($newModelsInFile[$upperModel] ?? 0) + 1;
        }
    }

    $parsedRows[] = [
        'row_number'   => (int)$rowIdx,
        'model'        => $matchedModelName ?: $rawModel,
        'model_status' => $modelStatus,
        'part_code'    => $rawCode,
        'part_name'    => $rawName,
        'aql_level'    => 'G-II', // Default G-II requested by user
        'status'       => $status,
        'status_label' => $statusLabel,
        'status_desc'  => $statusDesc,
        'is_valid'     => $isValid
    ];
}

// Sort: Put invalid & duplicates at top for immediate attention, or keep natural order
$response = [
    'success'         => true,
    'total_rows'      => count($parsedRows),
    'valid_count'     => $countValid,
    'duplicate_count' => $countDuplicate,
    'invalid_count'   => $countInvalid,
    'new_models_count'=> count($newModelsInFile),
    'new_models_list' => array_keys($newModelsInFile),
    'rows'            => $parsedRows
];

echo json_encode($response);
