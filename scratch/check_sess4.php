<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDB();

$stmt = $pdo->query("SELECT s.id, s.kanban_item_id, s.total_scanned_qty, s.excess_qty, s.status, isl.id as lot_id, isl.qty as lot_qty, isl.lot_result FROM inspection_sessions s LEFT JOIN inspection_session_lots isl ON isl.inspection_session_id = s.id WHERE s.id = 4");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
