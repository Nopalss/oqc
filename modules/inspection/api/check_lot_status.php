<?php
/**
 * API: Check Lot Status, Double-Use Protection & Quality Defect History
 * Memeriksa:
 * 1. Apakah lot number + part code + ref number sudah pernah PASSED di Kanban lain (Double-Use Protection)
 * 2. Apakah pernah tercatat NG / Rejected sebelumnya
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/helper.php';

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$pdo = getDB();
if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'Gagal koneksi database']);
    exit;
}

$partCode      = trim($_GET['part_code'] ?? '');
$lotNumber     = trim($_GET['lot_number'] ?? '');
$refNumber     = trim($_GET['ref_number'] ?? '');
$currentKanban = (int)($_GET['current_kanban_id'] ?? 0);

if (empty($lotNumber)) {
    echo json_encode(['success' => false, 'message' => 'Lot number kosong']);
    exit;
}

try {
    // 1. CEK STATUS SAFETY STOCK (GUDANG OVERFLOW)
    $isSafetyStock = false;
    $isExhausted = false;
    $ssAvailableQty = 0;
    $ssSessionId = 0;
    $ssLotId = 0;
    $ssDetails = '';

    if (!empty($refNumber) && !empty($partCode)) {
        // Cek apakah ref_number ini pernah masuk ke Safety Stock
        $stmtSS = $pdo->prepare("
            SELECT isl.id AS lot_id, s.id AS session_id, isl.lot_number, isl.ref_number,
                   COALESCE(isl.qty, s.total_scanned_qty, 0) AS available_qty,
                   s.auto_fulfilled_by_session_id, s.status AS session_status,
                   s.closed_at
            FROM inspection_session_lots isl
            JOIN inspection_sessions s ON s.id = isl.inspection_session_id
            LEFT JOIN daily_inspection_data did ON did.id = s.did_id
            LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
            LEFT JOIN master_parts mp ON mp.id = s.part_id
            WHERE s.inspection_type = 'safety_stock'
              AND UPPER(isl.ref_number) = UPPER(:ref)
              AND (UPPER(did.part_code) = UPPER(:p1) OR UPPER(ki.item_code) = UPPER(:p2) OR UPPER(mp.part_code) = UPPER(:p3))
            ORDER BY isl.id DESC
            LIMIT 1
        ");
        $stmtSS->execute([
            ':ref' => $refNumber,
            ':p1'  => $partCode,
            ':p2'  => $partCode,
            ':p3'  => $partCode
        ]);
        $ssRow = $stmtSS->fetch(PDO::FETCH_ASSOC);

        if ($ssRow) {
            $isSafetyStock = true;
            $avail = (int)$ssRow['available_qty'];
            $isFulfilled = (!empty($ssRow['auto_fulfilled_by_session_id']) && (int)$ssRow['auto_fulfilled_by_session_id'] > 0);

            if ($avail <= 0 || $isFulfilled || $ssRow['session_status'] !== 'passed') {
                $isExhausted = true;
                $ssAvailableQty = 0;
                $ssDetails = "Label Safety Stock Ref #{$refNumber} ini SUDAH HABIS TERPAKAI (sisa 0 pcs) di gudang!";
            } else {
                $ssAvailableQty = $avail;
                $ssSessionId = (int)$ssRow['session_id'];
                $ssLotId = (int)$ssRow['lot_id'];
                $ssDetails = "Label Safety Stock Gudang Aktif (Tersedia {$avail} pcs).";
            }
        }
    }

    // 2. CEK PROTEKSI DOUBLE-USE: Apakah label fisik (Ref Number) ini sudah PASSED di Kanban LAIN?
    // Catatan: Hanya dicek jika Ref Number sudah diisi/discan. Lot harian boleh sama antar-box/Kanban.
    // Jika ini label Safety Stock aktif, JANGAN tolak sebagai double-use Kanban lain!
    $alreadyUsedOther = false;
    $usedDetails = '';

    if (!$isSafetyStock && !empty($partCode) && !empty($refNumber)) {
        // Cek apakah Ref Number fisik untuk Part Code ini sudah pernah PASSED di Kanban lain
        $checkUsedSql = "
            SELECT isl.id, isl.lot_number, isl.ref_number, isl.qty,
                   s.id as session_id, s.kanban_item_id, s.closed_at,
                   k.kanban_no, COALESCE(mp.part_code, k.item_code) as part_code
            FROM inspection_session_lots isl
            JOIN inspection_sessions s ON s.id = isl.inspection_session_id
            JOIN kanban_items k ON k.id = s.kanban_item_id
            LEFT JOIN master_parts mp ON mp.id = s.part_id
            WHERE (UPPER(COALESCE(mp.part_code, k.item_code)) = UPPER(:pcode) OR UPPER(k.item_code) = UPPER(:pcode2))
              AND isl.lot_result = 'passed'
              AND (isl.lot_status IS NULL OR isl.lot_status != 'replaced')
              AND s.status = 'passed'
              AND s.inspection_type = 'kanban'
              AND s.kanban_item_id != :curr_kid
              AND UPPER(isl.ref_number) = UPPER(:ref_num)
            ORDER BY isl.id DESC LIMIT 1
        ";

        $paramsUsed = [
            ':pcode'    => $partCode,
            ':pcode2'   => $partCode,
            ':curr_kid' => $currentKanban,
            ':ref_num'  => $refNumber
        ];

        $stmtUsed = $pdo->prepare($checkUsedSql);
        $stmtUsed->execute($paramsUsed);
        $usedRow = $stmtUsed->fetch(PDO::FETCH_ASSOC);

        if ($usedRow) {
            $alreadyUsedOther = true;
            $kNo = $usedRow['kanban_no'] ?: ('#' . $usedRow['kanban_item_id']);
            $usedRef = $usedRow['ref_number'] ?: '-';
            $usedLot = $usedRow['lot_number'] ?: '-';
            $usedDetails = "Label dengan Part: {$partCode}, Lot: '{$usedLot}', Ref: '{$usedRef}' SUDAH DIGUNAKAN & PASSED pada Kanban {$kNo} (Sesi #{$usedRow['session_id']}).";
            $usedInfo = [
                'part_code'  => $partCode,
                'lot_number' => $usedLot,
                'ref_number' => $usedRef,
                'kanban_no'  => $kNo,
                'session_id' => (int)$usedRow['session_id']
            ];
        }
    }

    // 2. CEK RIWAYAT NG PADA inspection_session_lots
    $sql = "
        SELECT isl.id, isl.lot_number, isl.ref_number, isl.lot_result, isl.lot_status, isl.remarks,
               s.id as session_id, s.started_at,
               COALESCE(mp.part_code, k.item_code) as resolved_part_code
        FROM inspection_session_lots isl
        JOIN inspection_sessions s ON s.id = isl.inspection_session_id
        LEFT JOIN kanban_items k ON k.id = s.kanban_item_id
        LEFT JOIN master_parts mp ON mp.id = s.part_id
        WHERE isl.lot_number = :lot_num
          AND (isl.lot_result = 'rejected' OR isl.lot_status IN ('rejected', 'ng_found', 'ng_quarantine', 'replaced'))
    ";
    $params = [':lot_num' => $lotNumber];

    if (!empty($partCode)) {
        $sql .= " AND (mp.part_code = :pcode1 OR k.item_code = :pcode2 OR mp.part_code IS NULL)";
        $params[':pcode1'] = $partCode;
        $params[':pcode2'] = $partCode;
    }

    if (!empty($refNumber)) {
        $sql .= " AND (isl.ref_number = :ref_num OR isl.ref_number IS NULL OR isl.ref_number = '')";
        $params[':ref_num'] = $refNumber;
    }

    $sql .= " ORDER BY isl.id DESC LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $prevNg = $stmt->fetch(PDO::FETCH_ASSOC);

    // 3. Cek juga di tabel Daily Inspection Data (DID) jika status inspect sebelumnya NG
    $didNg = null;
    if (!$prevNg && !empty($partCode)) {
        $didSql = "
            SELECT did.id, did.lot_number, did.status_inspect, did.inspecting_date, did.remark
            FROM daily_inspection_data did
            WHERE did.lot_number = :lot_num
              AND did.part_code = :pcode
              AND (did.status_inspect = 'NG' OR did.status_inspect LIKE '%NG%')
            ORDER BY did.id DESC LIMIT 1
        ";
        $stmtDid = $pdo->prepare($didSql);
        $stmtDid->execute([':lot_num' => $lotNumber, ':pcode' => $partCode]);
        $didNg = $stmtDid->fetch(PDO::FETCH_ASSOC);
    }

    $isPreviouslyNg = false;
    $ngDetails = '';

    if ($prevNg) {
        $isPreviouslyNg = true;
        $ngDetails = "Tercatat NG pada Sesi Inspeksi #" . $prevNg['session_id'] . " (" . ($prevNg['started_at'] ?? '-') . "). Catatan: " . ($prevNg['remarks'] ?: 'Kualitas Rejected');
    } elseif ($didNg) {
        $isPreviouslyNg = true;
        $ngDetails = "Tercatat NG di data pengecekan dimensi DID tanggal " . ($didNg['inspecting_date'] ?? '-') . ". Catatan: " . ($didNg['remark'] ?: 'Status Dimensi NG');
    }

    echo json_encode([
        'success'                     => true,
        'is_safety_stock'             => $isSafetyStock,
        'is_exhausted'                => $isExhausted,
        'ss_available_qty'            => $ssAvailableQty,
        'ss_session_id'               => $ssSessionId,
        'ss_lot_id'                   => $ssLotId,
        'ss_details'                  => $ssDetails,
        'already_used_other_kanban'   => $alreadyUsedOther,
        'used_details'                => $usedDetails,
        'used_info'                   => $usedInfo ?? null,
        'is_previously_ng'            => $isPreviouslyNg,
        'ng_details'                  => $ngDetails,
        'lot_number'                  => $lotNumber,
        'ref_number'                  => $refNumber,
        'part_code'                   => $partCode
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
    ]);
}
