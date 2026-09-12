<?php
require_once __DIR__ . '/../config/database.php';

echo "=== TEST QUERY 1 (Original with bug) ===\n";
$stmt1 = $pdo->query("
    SELECT k.id as k_id, k.item_code, k.plan_type, ss.id as ss_id, ss.inspection_type
    FROM kanban_items k
    JOIN kanban_batches b ON b.id = k.batch_id
    LEFT JOIN inspection_sessions ss ON (ss.kanban_item_id = k.id OR ss.did_id = k.id)
    WHERE ss.id IS NULL
");
print_r($stmt1->fetchAll(PDO::FETCH_ASSOC));

echo "\n=== TEST QUERY 2 (Fixed JOIN) ===\n";
$stmt2 = $pdo->query("
    SELECT k.id as k_id, k.item_code, k.plan_type, ss.id as ss_id,
           (SELECT COALESCE(SUM(s.total_scanned_qty), 0)
            FROM inspection_sessions s
            LEFT JOIN daily_inspection_data d ON d.id = s.did_id
            LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
            WHERE s.inspection_type = 'safety_stock'
              AND s.status = 'passed'
              AND (s.auto_fulfilled_by_session_id IS NULL OR s.auto_fulfilled_by_session_id = 0)
              AND (UPPER(d.part_code) = UPPER(k.item_code) OR UPPER(ki.item_code) = UPPER(k.item_code))) AS avail_ss_qty
    FROM kanban_items k
    JOIN kanban_batches b ON b.id = k.batch_id
    LEFT JOIN inspection_sessions ss ON ss.kanban_item_id = k.id
    WHERE ss.id IS NULL
");
print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));
