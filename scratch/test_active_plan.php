<?php
require_once __DIR__ . '/../config/database.php';

$stmtPlan = $pdo->query("
    SELECT k.id, k.item_code, k.plan_type, k.qty,
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
    ORDER BY k.id DESC
");
$items = $stmtPlan->fetchAll(PDO::FETCH_ASSOC);

echo "Active Planning Items:\n";
print_r($items);
