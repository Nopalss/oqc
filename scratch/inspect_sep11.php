<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDB();

echo "=== SESSION #6 (11 SEP 2026) ===\n";
$stmt = $pdo->query("
    SELECT s.*, did.part_code AS did_part_code, ki.item_code AS ki_item_code, ki.item_description
    FROM inspection_sessions s
    LEFT JOIN daily_inspection_data did ON did.id = s.did_id
    LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
    WHERE DATE(s.started_at) = '2026-09-11'
");
$s = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($s);

echo "\n=== INSPECTION_SESSION_LOTS FOR 11 SEP 2026 ===\n";
$stmt = $pdo->query("
    SELECT isl.*
    FROM inspection_session_lots isl
    WHERE isl.inspection_session_id IN (SELECT id FROM inspection_sessions WHERE DATE(started_at) = '2026-09-11')
");
$l = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($l);
