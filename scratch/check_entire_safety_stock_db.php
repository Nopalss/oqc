<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDB();

echo "=== 1. ALL SAFETY STOCK INSPECTION SESSIONS ===\n";
$st1 = $pdo->query("
    SELECT ss.id AS session_id, ss.inspection_type, ss.did_id, ss.kanban_item_id, ss.total_scanned_qty, ss.status,
           did.part_code AS did_part_code, did.part_name AS did_part_name, did.lot_number AS did_lot_number,
           ki.item_code AS ki_item_code, ki.item_description AS ki_item_desc, ki.kanban_no AS ki_kanban_no
    FROM inspection_sessions ss
    LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
    LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
    WHERE ss.inspection_type = 'safety_stock'
    ORDER BY ss.id DESC
");
print_r($st1->fetchAll(PDO::FETCH_ASSOC));

echo "\n=== 2. GROUPED QUERY OUTPUT AS RUN BY INDEX.PHP ===\n";
$startDate = date('Y-m-01');
$endDate   = date('Y-m-d');
$whereStr = "ss.inspection_type = 'safety_stock' AND DATE(COALESCE(ss.started_at, did.created_at)) BETWEEN '$startDate' AND '$endDate'";

$groupedSql = "SELECT 
                COALESCE(did.part_code, ki.item_code, '-') AS part_code,
                MIN(COALESCE(did.part_name, ki.item_description, 'Part Safety Stock')) AS part_name,
                COALESCE(did.lot_number, '-') AS lot_number,
                MIN(COALESCE(ki.customer, 'INTERNAL SAFETY STOCK')) AS customer,
                MIN(COALESCE(ki.str_loc, 'WH-SAFETY')) AS str_loc,
                MAX(ss.id) AS max_session_id,
                MAX(ss.started_at) AS last_started_at,
                CASE 
                    WHEN SUM(CASE WHEN ss.status = 'in_progress' THEN 1 ELSE 0 END) > 0 THEN 'in_progress'
                    WHEN SUM(CASE WHEN ss.status = 'rejected' THEN 1 ELSE 0 END) > 0 THEN 'rejected'
                    ELSE 'passed'
                END AS group_status,
                SUM(ss.total_scanned_qty) AS total_initial_qty,
                SUM(CASE WHEN ss.auto_fulfilled_by_session_id > 0 THEN ss.total_scanned_qty ELSE 0 END) AS total_used_qty,
                SUM(CASE WHEN (ss.auto_fulfilled_by_session_id IS NULL OR ss.auto_fulfilled_by_session_id = 0) AND ss.status = 'passed' THEN ss.total_scanned_qty ELSE 0 END) AS available_qty,
                GROUP_CONCAT(DISTINCT COALESCE(u.name, did.pic, 'Inspector QC') SEPARATOR ', ') AS inspector_names
               FROM inspection_sessions ss
               LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
               LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
               LEFT JOIN users u ON u.id = ss.inspector_id
               WHERE {$whereStr}
               GROUP BY COALESCE(did.part_code, ki.item_code, '-'), COALESCE(did.lot_number, '-')";

$stGroup = $pdo->query($groupedSql);
$items = $stGroup->fetchAll(PDO::FETCH_ASSOC);
print_r($items);

echo "\n=== 3. SIMULATING LINK GENERATION IN INDEX.PHP ===\n";
foreach ($items as $it) {
    $url = 'modules/safety_stock/detail.php?part_code=' . urlencode($it['part_code']) . '&lot_number=' . urlencode($it['lot_number']) . '&id=' . $it['max_session_id'];
    echo "Generated URL: " . $url . "\n";
}
