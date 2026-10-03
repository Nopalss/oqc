<?php
/**
 * AJAX Endpoint: Preview & Parse Kanban Excel/CSV File
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';
require_once __DIR__ . '/xlsx_kanban_parser.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method tidak diizinkan!']);
    exit;
}

if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'File Excel/CSV tidak valid atau gagal diunggah!']);
    exit;
}

$tmpPath = $_FILES['excel_file']['tmp_name'];
$fileName = $_FILES['excel_file']['name'];
$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

if (!in_array($ext, ['xlsx', 'csv', 'xls'])) {
    echo json_encode(['success' => false, 'message' => 'Ekstensi file harus berupa .xlsx, .csv, atau .xls']);
    exit;
}

// Parse file
$result = xlsx_parse_kanban($tmpPath);

if (!$result['success']) {
    echo json_encode($result);
    exit;
}

// Attach original filename
$result['filename'] = $fileName;

// ----------------------------------------------------
// Deduplication Check (7 Criteria: kanban_no, item_code, req_date, qty, eta, str_loc, supply_area)
// ----------------------------------------------------
$pdo = getDB();
$kanbanNos = array_filter(array_unique(array_column($result['rows'], 'kanban_no')));

$dbFingerprints = [];
if (!empty($kanbanNos) && $pdo) {
    try {
        $placeholders = implode(',', array_fill(0, count($kanbanNos), '?'));
        $stmt = $pdo->prepare("SELECT id, kanban_no, item_code, req_date, qty, eta, str_loc, supply_area FROM kanban_items WHERE kanban_no IN ($placeholders)");
        $stmt->execute(array_values($kanbanNos));
        $dbRows = $stmt->fetchAll();

        foreach ($dbRows as $dbR) {
            $rDate = !empty($dbR['req_date']) ? date('Y-m-d H:i:s', strtotime($dbR['req_date'])) : '';
            $rEta  = !empty($dbR['eta']) ? date('Y-m-d H:i:s', strtotime($dbR['eta'])) : '';
            $fp = strtoupper(trim($dbR['kanban_no'])) . '|' .
                  strtoupper(trim($dbR['item_code'])) . '|' .
                  $rDate . '|' .
                  (int)$dbR['qty'] . '|' .
                  $rEta . '|' .
                  strtoupper(trim($dbR['str_loc'] ?? '')) . '|' .
                  strtoupper(trim($dbR['supply_area'] ?? ''));
            $dbFingerprints[md5($fp)] = true;
        }
    } catch (PDOException $e) {
        $dbFingerprints = [];
    }
}

$seenInFile = [];
$totalNew = 0;
$totalDuplicate = 0;

foreach ($result['rows'] as &$r) {
    $rDate = !empty($r['req_date']) ? date('Y-m-d H:i:s', strtotime($r['req_date'])) : '';
    $rEta  = !empty($r['eta']) ? date('Y-m-d H:i:s', strtotime($r['eta'])) : '';
    $fp = strtoupper(trim($r['kanban_no'])) . '|' .
          strtoupper(trim($r['item_code'])) . '|' .
          $rDate . '|' .
          (int)$r['qty'] . '|' .
          $rEta . '|' .
          strtoupper(trim($r['str_loc'] ?? '')) . '|' .
          strtoupper(trim($r['supply_area'] ?? ''));
    $hash = md5($fp);

    if (isset($dbFingerprints[$hash])) {
        $r['is_duplicate'] = true;
        $r['duplicate_source'] = 'Sudah ada di database';
        $totalDuplicate++;
    } elseif (isset($seenInFile[$hash])) {
        $r['is_duplicate'] = true;
        $r['duplicate_source'] = 'Kembar di file Excel';
        $totalDuplicate++;
    } else {
        $r['is_duplicate'] = false;
        $r['duplicate_source'] = null;
        $seenInFile[$hash] = true;
        $totalNew++;
    }
}
unset($r);

$result['total_new']       = $totalNew;
$result['total_duplicate'] = $totalDuplicate;

echo json_encode($result);
exit;
