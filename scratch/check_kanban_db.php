<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDB();

echo "=== KANBAN ITEM #2 ===\n";
$stmt = $pdo->query("SELECT id, kanban_no, item_code, qty, status, created_at FROM kanban_items WHERE kanban_no = '2' OR id = 2");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n=== SESSIONS FOR KANBAN #2 ===\n";
$stmt2 = $pdo->query("SELECT id, kanban_item_id, inspection_type, total_scanned_qty, excess_qty, use_safety_stock_qty, status, started_at, closed_at FROM inspection_sessions WHERE kanban_item_id = 2 OR kanban_item_id IN (SELECT id FROM kanban_items WHERE kanban_no = '2')");
print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));

echo "\n=== SESSION LOTS FOR THESE SESSIONS ===\n";
$stmt3 = $pdo->query("SELECT isl.id, isl.inspection_session_id, isl.lot_number, isl.ref_number, isl.qty, isl.lot_result, isl.lot_status, isl.remarks 
FROM inspection_session_lots isl 
WHERE isl.inspection_session_id IN (SELECT id FROM inspection_sessions WHERE kanban_item_id = 2 OR kanban_item_id IN (SELECT id FROM kanban_items WHERE kanban_no = '2'))");
print_r($stmt3->fetchAll(PDO::FETCH_ASSOC));
