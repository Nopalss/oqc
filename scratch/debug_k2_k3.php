<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDB();

echo "--- KANBAN ITEMS ---\n";
$stmt = $pdo->query("SELECT id, batch_id, kanban_no, item_code, qty, status FROM kanban_items WHERE id IN (2, 3)");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "--- SESSIONS ---\n";
$stmt2 = $pdo->query("SELECT id, kanban_item_id, inspection_type, total_scanned_qty, excess_qty, status FROM inspection_sessions WHERE kanban_item_id IN (2, 3)");
print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));

echo "--- LOTS ---\n";
$stmt3 = $pdo->query("SELECT isl.id, isl.inspection_session_id, isl.lot_number, isl.ref_number, isl.qty, isl.lot_result, isl.lot_status 
FROM inspection_session_lots isl 
WHERE isl.inspection_session_id IN (SELECT id FROM inspection_sessions WHERE kanban_item_id IN (2, 3))");
print_r($stmt3->fetchAll(PDO::FETCH_ASSOC));
