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

// Production Security Guard: Require authenticated user
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesi login Anda telah berakhir. Silakan login kembali.', 'unauthorized' => true, 'items' => []]);
    exit;
}
session_write_close();

$pdo = getDB();
$partCode = strtoupper(trim(sanitize($_GET['part_code'] ?? '')));

if (empty($partCode)) {
    echo json_encode(['success' => false, 'message' => 'Part Code wajib diisi', 'items' => []]);
    exit;
}

if (!$pdo) {
    http_response_code(500);
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
            AND (isl.lot_status IS NULL OR isl.lot_status IN ('ok', 'reinspected', 'passed', ''))
            AND (isl.lot_result IS NULL OR isl.lot_result != 'rejected')
            AND isl.qty > 0
        LEFT JOIN daily_inspection_data d ON d.id = s.did_id
        LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
        WHERE s.inspection_type = 'safety_stock'
          AND s.status = 'passed'
          AND (
              (isl.id IS NOT NULL AND (isl.lot_result != 'rejected' OR isl.lot_result IS NULL) AND (isl.lot_status IS NULL OR isl.lot_status IN ('ok', 'reinspected', 'passed', '')))
              OR (isl.id IS NULL AND s.status = 'passed')
          )
          AND (s.auto_fulfilled_by_session_id IS NULL OR s.auto_fulfilled_by_session_id = 0)
          AND COALESCE(d.part_code, ki.item_code, '') = :pcode
          AND NOT EXISTS (
              SELECT 1 
              FROM inspection_session_lots isl_used
              JOIN inspection_sessions s_used ON s_used.id = isl_used.inspection_session_id
              WHERE s_used.inspection_type != 'safety_stock'
                AND (isl_used.lot_result IS NULL OR isl_used.lot_result != 'rejected')
                AND (
                    (isl.ref_number IS NOT NULL AND isl.ref_number != '-' AND isl_used.ref_number = isl.ref_number)
                    OR (isl.scanned_qr_raw IS NOT NULL AND isl.scanned_qr_raw != '' AND isl_used.scanned_qr_raw = isl.scanned_qr_raw)
                )
          )
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

