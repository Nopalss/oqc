<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDB();
$stmt = $pdo->query("SELECT ss.id, ss.inspection_type, ss.did_id, ss.kanban_item_id, did.part_code, did.lot_number, ki.item_code, ki.kanban_no 
                     FROM inspection_sessions ss 
                     LEFT JOIN daily_inspection_data did ON did.id = ss.did_id 
                     LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id 
                     ORDER BY ss.id DESC LIMIT 10");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
