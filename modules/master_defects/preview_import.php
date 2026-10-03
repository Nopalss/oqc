<?php
/**
 * AJAX Endpoint: Parse, Validate, and Preview Master Defect Excel/CSV Import
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

if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'File Excel/CSV tidak valid atau gagal diunggah!']);
    exit;
}

$tmpPath  = $_FILES['excel_file']['tmp_name'];
$fileName = $_FILES['excel_file']['name'];
$ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

if (!in_array($ext, ['xlsx', 'csv', 'xls'])) {
    echo json_encode(['success' => false, 'message' => 'Ekstensi file harus berupa .xlsx, .csv, atau .xls']);
    exit;
}

$rawRows = [];

try {
    if ($ext === 'csv') {
        $handle = fopen($tmpPath, 'r');
        if (!$handle) {
            throw new Exception('Gagal membuka file CSV!');
        }

        // Check & strip UTF-8 BOM
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        // Detect delimiter
        $firstLine = fgets($handle);
        rewind($handle);
        if ($bom === "\xEF\xBB\xBF") {
            fseek($handle, 3);
        }

        $delimiter = ',';
        $commaCount     = substr_count($firstLine, ',');
        $semicolonCount = substr_count($firstLine, ';');
        $tabCount       = substr_count($firstLine, "\t");
        if ($semicolonCount > $commaCount && $semicolonCount > $tabCount) {
            $delimiter = ';';
        } elseif ($tabCount > $commaCount && $tabCount > $semicolonCount) {
            $delimiter = "\t";
        }

        $rowIdx = 1;
        $colLetters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $mapped = [];
            foreach ($row as $cIdx => $cVal) {
                $letter = $colLetters[$cIdx] ?? ('COL_' . $cIdx);
                $strVal = trim((string)$cVal);
                if (!mb_check_encoding($strVal, 'UTF-8')) {
                    $strVal = mb_convert_encoding($strVal, 'UTF-8', 'ISO-8859-1, Windows-1252');
                }
                $mapped[$letter] = $strVal;
            }
            $rawRows[$rowIdx] = $mapped;
            $rowIdx++;
        }
        fclose($handle);

    } else {
        $reader = IOFactory::createReaderForFile($tmpPath);
        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(true);
        }
        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }
        $spreadsheet = $reader->load($tmpPath);
        $worksheet   = $spreadsheet->getActiveSheet();

        $excelData = $worksheet->toArray(null, false, false, true);
        if (is_array($excelData)) {
            $rawRows = $excelData;
        }
    }

    if (empty($rawRows)) {
        throw new Exception('File Excel / CSV kosong atau tidak memiliki data!');
    }

    // 1. Detect Header Row
    $headerRowIdx = null;
    $colMap = ['code' => null, 'defect_name' => null];

    foreach ($rawRows as $rIdx => $rowCols) {
        $hasCode = false;
        $hasName = false;

        foreach ($rowCols as $colLetter => $cellVal) {
            $clean = strtolower(trim((string)$cellVal));
            $cleanNoPunct = preg_replace('/[^a-z0-9]/', '', $clean);

            if (in_array($cleanNoPunct, ['code', 'kode', 'defectcode', 'kodedefect', 'kddefect', 'codeid'])) {
                $colMap['code'] = $colLetter;
                $hasCode = true;
            } elseif (in_array($cleanNoPunct, ['defectname', 'namadefect', 'defect', 'name', 'nama', 'namajenisdefect', 'cacat', 'jeniscacat'])) {
                $colMap['defect_name'] = $colLetter;
                $hasName = true;
            }
        }

        if ($hasName) {
            $headerRowIdx = $rIdx;
            break;
        }
    }

    // Fallback if header row not identified by keyword
    if ($headerRowIdx === null) {
        $headerRowIdx = 1;
        $colLetters = array_keys(reset($rawRows));
        $colMap['code']        = $colLetters[0] ?? 'A';
        $colMap['defect_name'] = $colLetters[1] ?? 'B';
    } else {
        if (!$colMap['code']) {
            $colLetters = array_keys($rawRows[$headerRowIdx]);
            $colMap['code'] = ($colMap['defect_name'] === ($colLetters[0] ?? '')) ? ($colLetters[1] ?? 'B') : ($colLetters[0] ?? 'A');
        }
    }

    // 2. Load Existing Database Defect Records for Fast In-Memory Lookup
    $pdo = getDB();
    if (!$pdo) {
        throw new Exception('Koneksi ke database server gagal!');
    }

    $dbCodes = [];
    $dbNames = [];

    $stmtExisting = $pdo->query("SELECT id, code, name FROM defect_types");
    while ($exist = $stmtExisting->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($exist['code'])) {
            $dbCodes[strtoupper(trim($exist['code']))] = (int)$exist['id'];
        }
        if (!empty($exist['name'])) {
            $dbNames[strtoupper(trim($exist['name']))] = (int)$exist['id'];
        }
    }

    // 3. Process & Validate Rows
    $seenCodesInFile = [];
    $seenNamesInFile = [];

    $resultRows = [];
    $totalCount = 0;
    $validCount = 0;
    $dupDbCount = 0;
    $dupFileCount = 0;
    $invalidCount = 0;

    foreach ($rawRows as $rIdx => $rowCols) {
        if ($rIdx <= $headerRowIdx) {
            continue; // Skip header and above
        }

        $codeVal = trim((string)($rowCols[$colMap['code']] ?? ''));
        $nameVal = trim((string)($rowCols[$colMap['defect_name']] ?? ''));

        // Skip completely empty rows
        if ($codeVal === '' && $nameVal === '') {
            continue;
        }

        $totalCount++;
        $upperCode = strtoupper($codeVal);
        $upperName = strtoupper($nameVal);

        $status = 'valid';
        $notes = [];

        // Validation 1: Defect Name is Required
        if ($nameVal === '') {
            $status = 'invalid';
            $notes[] = 'Nama Defect tidak boleh kosong';
            $invalidCount++;
        }
        // Validation 2: Duplicate Defect Name in Database
        elseif (isset($dbNames[$upperName])) {
            $status = 'duplicate_db';
            $notes[] = 'Nama Defect "' . htmlspecialchars($nameVal) . '" sudah terdaftar di database';
            $dupDbCount++;
        }
        // Validation 3: Duplicate Defect Name within File
        elseif (isset($seenNamesInFile[$upperName])) {
            $status = 'duplicate_file';
            $notes[] = 'Nama Defect kembar dengan baris #' . $seenNamesInFile[$upperName] . ' di file ini';
            $dupFileCount++;
        } else {
            // Valid new row (Kode boleh sama dengan baris lain)
            $validCount++;
            $seenNamesInFile[$upperName] = $rIdx;
        }

        $resultRows[] = [
            'row_num'     => $rIdx,
            'code'        => $codeVal,
            'defect_name' => $nameVal,
            'status'      => $status,
            'notes'       => implode(', ', $notes),
        ];
    }

    echo json_encode([
        'success'  => true,
        'fileName' => $fileName,
        'summary'  => [
            'total'          => $totalCount,
            'valid'          => $validCount,
            'duplicate_db'   => $dupDbCount,
            'duplicate_file' => $dupFileCount,
            'invalid'        => $invalidCount,
        ],
        'rows' => $resultRows,
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
