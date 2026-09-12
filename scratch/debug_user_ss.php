<?php
require_once __DIR__ . '/../config/database.php';

echo "=== INSPECTION SESSIONS (Safety Stock) ===\n";
$stmtS = $pdo->query("
    SELECT s.id, s.inspection_type, s.did_id, s.kanban_item_id, s.total_scanned_qty, s.status, s.auto_fulfilled_by_session_id,
           d.part_code as did_part_code, k.item_code as kanban_item_code
    FROM inspection_sessions s
    LEFT JOIN daily_inspection_data d ON d.id = s.did_id
    LEFT JOIN kanban_items k ON k.id = s.kanban_item_id
    WHERE s.inspection_type = 'safety_stock'
    ORDER BY s.id DESC
");
print_r($stmtS->fetchAll(PDO::FETCH_ASSOC));

echo "\n=== KANBAN ITEMS ===\n";
$stmtK = $pdo->query("SELECT id, plan_type, item_code, qty FROM kanban_items ORDER BY id DESC LIMIT 5");
print_r($stmtK->fetchAll(PDO::FETCH_ASSOC));
