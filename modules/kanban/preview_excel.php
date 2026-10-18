<?php
/**
 * AJAX Endpoint: Preview & Parse Kanban Excel/CSV File
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';
require_once __DIR__ . '/xlsx_kanban_parser.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Silakan login terlebih dahulu.']);
    exit;
}

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
$dbKanbanMap = []; // Map kanban_no + item_code to existing DB record info

if (!empty($kanbanNos) && $pdo) {
    try {
        $placeholders = implode(',', array_fill(0, count($kanbanNos), '?'));
        $stmt = $pdo->prepare("
            SELECT k.id, k.kanban_no, k.item_code, k.req_date, k.qty, k.eta, k.str_loc, k.supply_area, k.status,
                   COALESCE((
                       SELECT SUM(
                           CASE 
                               WHEN EXISTS (SELECT 1 FROM inspection_session_lots isl_c WHERE isl_c.inspection_session_id = s2.id) THEN
                                   COALESCE((SELECT SUM(CASE WHEN isl2.lot_result IN ('passed', 'skipped') AND (isl2.lot_status IS NULL OR isl2.lot_status != 'replaced') THEN isl2.qty ELSE 0 END)
                                             FROM inspection_session_lots isl2 WHERE isl2.inspection_session_id = s2.id), 0) - COALESCE(s2.excess_qty, 0)
                               WHEN s2.status = 'passed' THEN (s2.total_scanned_qty - COALESCE(s2.excess_qty, 0))
                               ELSE 0
                           END
                       )
                       FROM inspection_sessions s2
                       WHERE s2.kanban_item_id = k.id
                   ), 0) AS passed_qty
            FROM kanban_items k
            WHERE k.kanban_no IN ($placeholders)
            ORDER BY k.id DESC
        ");
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

            // Tanggal permintaan (Req Date) & tanggal kedatangan (ETA) adalah penentu siklus pengiriman ke customer
            $dbReqDate = !empty($rDate) ? substr($rDate, 0, 10) : '';
            $dbEtaDate = !empty($rEta) ? substr($rEta, 0, 10) : '';
            $keySchedule = strtoupper(trim($dbR['kanban_no'])) . '|' . strtoupper(trim($dbR['item_code'])) . '|' . $dbReqDate . '|' . $dbEtaDate;
            if (!isset($dbKanbanMap[$keySchedule])) {
                $dbKanbanMap[$keySchedule] = [
                    'id'          => (int)$dbR['id'],
                    'qty'         => (int)$dbR['qty'],
                    'status'      => $dbR['status'] ?? 'uninspected',
                    'passed_qty'  => (int)$dbR['passed_qty'],
                    'req_date'    => $rDate,
                    'eta'         => $rEta
                ];
            }
        }
    } catch (PDOException $e) {
        $dbFingerprints = [];
        $dbKanbanMap = [];
    }
}

$seenInFile = [];
$totalNew = 0;
$totalDuplicate = 0;
$totalQtyChange = 0;

foreach ($result['rows'] as &$r) {
    $rDate = !empty($r['req_date']) ? date('Y-m-d H:i:s', strtotime($r['req_date'])) : '';
    $rEta  = !empty($r['eta']) ? date('Y-m-d H:i:s', strtotime($r['eta'])) : '';
    $rQty  = (int)($r['qty'] ?? 0);
    $rKno  = strtoupper(trim($r['kanban_no']));
    $rIcode = strtoupper(trim($r['item_code']));

    $fp = $rKno . '|' .
          $rIcode . '|' .
          $rDate . '|' .
          $rQty . '|' .
          $rEta . '|' .
          strtoupper(trim($r['str_loc'] ?? '')) . '|' .
          strtoupper(trim($r['supply_area'] ?? ''));
    $hash = md5($fp);

    // Kunci jadwal pengiriman: No Kanban + Part + Tanggal Req Date (YYYY-MM-DD) + Tanggal ETA (YYYY-MM-DD)
    $fileReqDate = !empty($rDate) ? substr($rDate, 0, 10) : '';
    $fileEtaDate = !empty($rEta) ? substr($rEta, 0, 10) : '';
    $keySchedule = $rKno . '|' . $rIcode . '|' . $fileReqDate . '|' . $fileEtaDate;

    if (isset($dbFingerprints[$hash])) {
        // Lapis 1A: Sama persis dengan data di database (termasuk Qty & tanggal)
        $r['is_duplicate'] = true;
        $r['is_qty_change'] = false;
        $r['duplicate_source'] = 'Sudah ada di database (Sama persis)';
        $totalDuplicate++;
    } elseif (isset($seenInFile[$hash])) {
        // Lapis 1B: Kembar identik di dalam file itu sendiri (antar sheet / baris)
        $r['is_duplicate'] = true;
        $r['is_qty_change'] = false;
        $r['duplicate_source'] = 'Kembar di file (Sheet: ' . $seenInFile[$hash] . ')';
        $totalDuplicate++;
    } elseif (isset($dbKanbanMap[$keySchedule])) {
        // Lapis 2: Siklus Jadwal (Req Date + ETA + Part) SAMA di DB, tapi kuantitasnya berbeda (REVISI QTY JADWAL INI)
        $existing = $dbKanbanMap[$keySchedule];
        if ($existing['qty'] !== $rQty) {
            $diff = $rQty - $existing['qty'];
            $r['is_duplicate'] = false;
            $r['is_qty_change'] = true;
            $r['existing_id'] = $existing['id'];
            $r['old_qty'] = $existing['qty'];
            $r['new_qty'] = $rQty;
            $r['qty_diff'] = $diff;
            $r['is_qty_decrease'] = ($diff < 0);
            $r['is_below_passed'] = ($rQty < $existing['passed_qty']);
            $r['current_status'] = $existing['status'];
            $r['passed_qty'] = $existing['passed_qty'];
            $r['default_action'] = 'update_qty'; // Rekomendasi default
            $totalQtyChange++;
        } else {
            // Jika Qty dan jadwal (Req Date & ETA) sama persis, anggap duplikat jadwal
            $r['is_duplicate'] = true;
            $r['is_qty_change'] = false;
            $r['duplicate_source'] = 'Jadwal Kanban (Req Date & ETA) sudah terdaftar di database';
            $totalDuplicate++;
        }
    } else {
        // Lapis 3: Tanggal Req Date beda, atau ETA beda, atau No kanban baru -> BARIS / LOT BARU!
        $r['is_duplicate'] = false;
        $r['is_qty_change'] = false;
        $seenInFile[$hash] = $r['sheet_name'] ?? 'kanban';
        $totalNew++;
    }
}
unset($r);

$result['total_new']        = $totalNew;
$result['total_duplicate']  = $totalDuplicate;
$result['total_qty_change'] = $totalQtyChange;

echo json_encode($result);
exit;
