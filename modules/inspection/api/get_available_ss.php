<?php
/**
 * API Endpoint: Get Available Safety Stock Sessions for a Part Code (FIFO Sorted)
 * Endpoint ini mengembalikan daftar sesi Safety Stock berstatus PASSED yang tersedia
 * untuk dipotong/dialokasikan ke Kanban berdasarkan Part Code.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/helper.php';

$pdo = getDB();
$partCode = strtoupper(trim(sanitize($_GET['part_code'] ?? '')));

if (empty($partCode)) {
    echo json_encode(['success' => false, 'message' => 'Part Code wajib diisi', 'items' => []]);
    exit;
}

if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'Koneksi database gagal', 'items' => []]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT
            isl.id AS lot_id,
            s.id AS session_id,
            s.did_id,
            s.part_id,
            s.started_at,
            COALESCE(isl.qty, s.total_scanned_qty) AS available_qty,
            COALESCE(d.part_code, ki.item_code, '-') AS part_code,
            COALESCE(d.part_name, ki.item_description, '-') AS part_name,
            COALESCE(isl.lot_number, d.lot_number, 'LOT-SS') AS lot_number,
            COALESCE(isl.ref_number, '-') AS ref_number,
            COALESCE(isl.ref_number, '-') AS ref_numbers,
            isl.scanned_qr_raw
        FROM inspection_sessions s
        LEFT JOIN inspection_session_lots isl ON isl.inspection_session_id = s.id 
            AND (isl.lot_status = 'ok' OR isl.lot_status IS NULL)
            AND (isl.lot_result IS NULL OR isl.lot_result IN ('passed', 'skipped'))
            AND isl.qty > 0
        LEFT JOIN daily_inspection_data d ON d.id = s.did_id
        LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
        WHERE s.inspection_type = 'safety_stock'
          AND s.status != 'in_progress'
          AND (
              (isl.id IS NOT NULL AND (isl.lot_result IN ('passed', 'skipped') OR (isl.lot_result IS NULL AND s.status = 'passed')) AND (isl.lot_status = 'ok' OR isl.lot_status IS NULL))
              OR (isl.id IS NULL AND s.status = 'passed')
          )
          AND (s.auto_fulfilled_by_session_id IS NULL OR s.auto_fulfilled_by_session_id = 0)
          AND COALESCE(d.part_code, ki.item_code, '') = :pcode
        ORDER BY s.started_at ASC, s.id ASC, isl.id ASC
    ");
    $stmt->execute([':pcode' => $partCode]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format output data
    foreach ($items as &$it) {
        $it['available_qty']  = (int)$it['available_qty'];
        $it['session_id']     = (int)$it['session_id'];
        $it['lot_id']         = !empty($it['lot_id']) ? (int)$it['lot_id'] : ('s_' . $it['session_id']);
        $it['did_id']         = (int)($it['did_id'] ?? 0);
        $it['part_id']        = (int)($it['part_id'] ?? 0);
        $it['lot_number']     = $it['lot_number'] ?: 'LOT-SS';
        $it['ref_number']     = $it['ref_number'] ?: '-';
        $it['ref_numbers']    = $it['ref_number'];
        $it['formatted_date'] = !empty($it['started_at']) ? date('d M Y, H:i', strtotime($it['started_at'])) . ' WIB' : '-';
    }

    echo json_encode(['success' => true, 'items' => $items, 'lots' => $items]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Gagal memuat data Safety Stock: ' . $e->getMessage(), 'items' => []]);
}

