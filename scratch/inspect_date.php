<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDB();

echo "=== INSPECTION_SESSIONS FOR 2026-09-10 ===\n";
$stmt = $pdo->query("
    SELECT s.*, did.part_code AS did_part_code, did.lot_number AS did_lot_number, ki.item_code AS ki_item_code, ki.kanban_no
    FROM inspection_sessions s
    LEFT JOIN daily_inspection_data did ON did.id = s.did_id
    LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
    WHERE DATE(s.started_at) = '2026-09-10'
");
$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($sessions);

echo "\n=== INSPECTION_SESSION_LOTS FOR 2026-09-10 ===\n";
$stmt = $pdo->query("
    SELECT isl.*, s.inspection_type, s.started_at
    FROM inspection_session_lots isl
    JOIN inspection_sessions s ON s.id = isl.inspection_session_id
    WHERE DATE(s.started_at) = '2026-09-10'
");
$lots = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($lots);
