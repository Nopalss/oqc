<?php
/**
 * API: Check Lot Status & Previous Quality Defect History
 * Memeriksa apakah lot number + part code + ref number pernah tercatat NG / Rejected
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

$partCode  = trim($_GET['part_code'] ?? '');
$lotNumber = trim($_GET['lot_number'] ?? '');
$refNumber = trim($_GET['ref_number'] ?? '');

if (empty($lotNumber)) {
    echo json_encode(['success' => false, 'message' => 'Lot number kosong']);
    exit;
}

try {
    // 1. Cek apakah ada riwayat NG pada inspection_session_lots
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

    // 2. Cek juga di tabel Daily Inspection Data (DID) jika status inspect sebelumnya NG
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
        'success'           => true,
        'is_previously_ng'  => $isPreviouslyNg,
        'ng_details'        => $ngDetails,
        'lot_number'        => $lotNumber,
        'part_code'         => $partCode
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
    ]);
}
