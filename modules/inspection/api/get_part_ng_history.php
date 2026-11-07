<?php
/**
 * API Endpoint: Get NG History & Top 5 Defect Ranking for a Part Code
 * Endpoint ini mengembalikan data riwayat temuan defect NG dan ranking Top 5
 * defect terbanyak khusus untuk Part Code yang sedang diinspeksi.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/helper.php';

// Authentication Guard
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Sesi login telah berakhir. Silakan login kembali.',
        'top5' => [],
        'logs' => [],
        'summary' => [
            'total_occurrences' => 0,
            'total_qty_ng' => 0
        ]
    ]);
    exit;
}

session_write_close();

$pdo = getDB();
$partCode = trim(sanitize($_GET['part_code'] ?? ''));

if (empty($partCode)) {
    echo json_encode([
        'success' => false,
        'message' => 'Part Code wajib disertakan.',
        'top5' => [],
        'logs' => [],
        'summary' => [
            'total_occurrences' => 0,
            'total_qty_ng' => 0
        ]
    ]);
    exit;
}

if (!$pdo) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Gagal terhubung ke database.',
        'top5' => [],
        'logs' => [],
        'summary' => [
            'total_occurrences' => 0,
            'total_qty_ng' => 0
        ]
    ]);
    exit;
}

try {
    // 1. Ambil Total Keseluruhan Defect untuk Part ini (untuk dasar hitung % Pareto)
    $stmtTotal = $pdo->prepare("
        SELECT 
            COUNT(n.id) as total_occurrences,
            COALESCE(SUM(n.qty_ng), 0) as total_qty_ng
        FROM inspection_ng_records n
        JOIN inspection_sessions s ON s.id = n.inspection_session_id
        LEFT JOIN daily_inspection_data did ON did.id = s.did_id
        LEFT JOIN master_parts mp ON mp.id = COALESCE(n.part_id, s.part_id)
        LEFT JOIN kanban_items k ON k.id = s.kanban_item_id
        WHERE (n.is_cancelled IS NULL OR n.is_cancelled = 0)
          AND (
              mp.part_code = :pcode1 
              OR did.part_code = :pcode2 
              OR k.item_code = :pcode3
          )
    ");
    $stmtTotal->execute([
        ':pcode1' => $partCode,
        ':pcode2' => $partCode,
        ':pcode3' => $partCode
    ]);
    $summaryRow = $stmtTotal->fetch(PDO::FETCH_ASSOC);
    $totalOccurrences = (int)($summaryRow['total_occurrences'] ?? 0);
    $totalQtyNg = (int)($summaryRow['total_qty_ng'] ?? 0);

    // 2. Query Top 5 Defect Ranking
    $top5 = [];
    if ($totalQtyNg > 0) {
        $stmtTop5 = $pdo->prepare("
            SELECT 
                dt.id as defect_type_id,
                dt.name as defect_name,
                dt.code as defect_code,
                SUM(n.qty_ng) as total_qty,
                COUNT(n.id) as occurrence_count
            FROM inspection_ng_records n
            JOIN defect_types dt ON dt.id = n.defect_type_id
            JOIN inspection_sessions s ON s.id = n.inspection_session_id
            LEFT JOIN daily_inspection_data did ON did.id = s.did_id
            LEFT JOIN master_parts mp ON mp.id = COALESCE(n.part_id, s.part_id)
            LEFT JOIN kanban_items k ON k.id = s.kanban_item_id
            WHERE (n.is_cancelled IS NULL OR n.is_cancelled = 0)
              AND (
                  mp.part_code = :pcode1 
                  OR did.part_code = :pcode2 
                  OR k.item_code = :pcode3
              )
            GROUP BY dt.id, dt.name, dt.code
            ORDER BY total_qty DESC, occurrence_count DESC
            LIMIT 5
        ");
        $stmtTop5->execute([
            ':pcode1' => $partCode,
            ':pcode2' => $partCode,
            ':pcode3' => $partCode
        ]);
        $rawTop5 = $stmtTop5->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rawTop5 as $idx => $r) {
            $tQty = (int)$r['total_qty'];
            $pct = ($totalQtyNg > 0) ? round(($tQty / $totalQtyNg) * 100, 1) : 0;
            $top5[] = [
                'rank' => $idx + 1,
                'defect_type_id' => (int)$r['defect_type_id'],
                'defect_name' => $r['defect_name'],
                'defect_code' => $r['defect_code'],
                'total_qty' => $tQty,
                'occurrence_count' => (int)$r['occurrence_count'],
                'percentage' => $pct
            ];
        }
    }

    // 3. Query Log Riwayat Temuan Box / Lot (30 Terakhir)
    $stmtLogs = $pdo->prepare("
        SELECT 
            n.id as ng_id,
            COALESCE(n.ref_number, isl.ref_number, '-') as ref_number,
            COALESCE(n.lot_number, isl.lot_number, did.lot_number, '-') as lot_number,
            n.qty_ng,
            COALESCE(n.remark, '') as ng_remark,
            n.created_at as ng_date,
            dt.name as defect_name,
            dt.code as defect_code,
            s.id as session_id,
            s.inspection_type,
            COALESCE(s.line_name, '-') as line_name,
            COALESCE(u.name, 'Inspector QC') as inspector_name,
            k.kanban_no,
            isl.lot_result,
            isl.lot_status
        FROM inspection_ng_records n
        JOIN defect_types dt ON dt.id = n.defect_type_id
        JOIN inspection_sessions s ON s.id = n.inspection_session_id
        LEFT JOIN daily_inspection_data did ON did.id = s.did_id
        LEFT JOIN master_parts mp ON mp.id = COALESCE(n.part_id, s.part_id)
        LEFT JOIN kanban_items k ON k.id = s.kanban_item_id
        LEFT JOIN users u ON u.id = s.inspector_id
        LEFT JOIN inspection_session_lots isl ON isl.id = n.session_lot_id
        WHERE (n.is_cancelled IS NULL OR n.is_cancelled = 0)
          AND (
              mp.part_code = :pcode1 
              OR did.part_code = :pcode2 
              OR k.item_code = :pcode3
          )
        ORDER BY n.created_at DESC, n.id DESC
        LIMIT 30
    ");
    $stmtLogs->execute([
        ':pcode1' => $partCode,
        ':pcode2' => $partCode,
        ':pcode3' => $partCode
    ]);
    $logs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'part_code' => $partCode,
        'summary' => [
            'total_occurrences' => $totalOccurrences,
            'total_qty_ng' => $totalQtyNg
        ],
        'top5' => $top5,
        'logs' => $logs
    ], JSON_UNESCAPED_UNICODE);
    exit;

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Kesalahan database: ' . $e->getMessage(),
        'top5' => [],
        'logs' => [],
        'summary' => [
            'total_occurrences' => 0,
            'total_qty_ng' => 0
        ]
    ]);
    exit;
}

