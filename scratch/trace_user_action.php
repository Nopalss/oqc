<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDB();

$stmt = $pdo->query("
    SELECT s.id as sess_id, s.kanban_item_id, ki.kanban_no, s.inspection_type, 
           s.total_scanned_qty, s.excess_qty, s.use_safety_stock_qty, s.status as sess_status,
           s.auto_fulfilled_by_session_id,
           isl.id as lot_id, isl.lot_number, isl.ref_number, isl.qty as lot_qty, isl.remarks, isl.lot_status
    FROM inspection_sessions s
    LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
    LEFT JOIN inspection_session_lots isl ON isl.inspection_session_id = s.id
    ORDER BY s.id ASC
");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
