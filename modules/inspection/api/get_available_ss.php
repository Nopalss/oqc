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
$partCode = sanitize($_GET['part_code'] ?? '');

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
            s.id AS session_id,
            s.started_at,
            s.total_scanned_qty AS available_qty,
            COALESCE(d.part_code, ki.item_code, '-') AS part_code,
            COALESCE(d.part_name, ki.item_description, '-') AS part_name,
            COALESCE(d.lot_number, 'LOT-SS') AS lot_number,
            (
                SELECT GROUP_CONCAT(DISTINCT isl.ref_number SEPARATOR ', ')
                FROM inspection_session_lots isl
                WHERE isl.inspection_session_id = s.id AND isl.ref_number IS NOT NULL AND isl.ref_number != ''
            ) AS ref_numbers
        FROM inspection_sessions s
        LEFT JOIN daily_inspection_data d ON d.id = s.did_id
        LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
        WHERE s.inspection_type = 'safety_stock'
          AND s.status = 'passed'
          AND (s.auto_fulfilled_by_session_id IS NULL OR s.auto_fulfilled_by_session_id = 0)
          AND UPPER(COALESCE(d.part_code, ki.item_code, '')) = UPPER(:pcode)
        ORDER BY s.started_at ASC, s.id ASC
    ");
    $stmt->execute([':pcode' => $partCode]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format output data
    foreach ($items as &$it) {
        $it['available_qty'] = (int)$it['available_qty'];
        $it['session_id']    = (int)$it['session_id'];
        $it['ref_numbers']   = $it['ref_numbers'] ?: '-';
        $it['formatted_date'] = !empty($it['started_at']) ? date('d M Y, H:i', strtotime($it['started_at'])) . ' WIB' : '-';
    }

    echo json_encode(['success' => true, 'items' => $items, 'lots' => $items]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Gagal memuat data Safety Stock: ' . $e->getMessage(), 'items' => []]);
}
