<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDB();

echo "=== AVAILABLE PASSED SAFETY STOCK SESSIONS ===\n";
$stmt = $pdo->query("
    SELECT
        s.id AS session_id,
        s.started_at,
        s.total_scanned_qty,
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
    ORDER BY s.id ASC
");
$ssList = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($ssList);
