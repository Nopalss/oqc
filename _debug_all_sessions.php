<?php
require __DIR__ . '/config/app.php';
require __DIR__ . '/config/database.php';
$pdo = getDB();

echo "=== ALL INSPECTION SESSIONS IN DB ===\n";
$st = $pdo->query("
    SELECT ss.id AS session_id, ss.inspection_type, ss.status, ss.did_id, ss.kanban_item_id, ss.part_id,
           ss.sample_size, ss.samples_checked, ss.ng_count, ss.started_at, ss.closed_at,
           ki.id AS ki_id, ki.item_code AS ki_item_code, ki.item_description AS ki_desc,
           did.id AS did_id_real, did.part_code AS did_part_code, did.part_name AS did_part_name
    FROM inspection_sessions ss
    LEFT JOIN kanban_items ki ON ss.kanban_item_id = ki.id
    LEFT JOIN daily_inspection_data did ON ss.did_id = did.id
");
$sessions = $st->fetchAll(PDO::FETCH_ASSOC);
print_r($sessions);

echo "\n=== MONITORING INDEX SQL EXECUTED ===\n";
$startDate = date('Y-01-01');
$endDate   = date('Y-m-d');
$whereStr = "DATE(COALESCE(b.imported_at, ki.created_at)) BETWEEN '2026-01-01' AND '2026-12-31'";

$mainSql = "SELECT 
                ki.id AS item_id, ki.item_code, ki.item_description, ki.qty AS planned_qty, 
                ki.customer, ki.plan_type, ki.kanban_no, ki.req_date,
                COALESCE(b.document_number, 'DOC-LEGACY') AS document_number, 
                COALESCE(b.vendor, 'Legacy Input') AS vendor, 
                COALESCE(b.imported_at, ki.created_at) AS plan_date,
                COUNT(ss.id) AS session_count,
                MAX(ss.id) AS last_session_id,
                SUM(CASE WHEN ss.status = 'rejected' THEN 1 ELSE 0 END) AS count_rejected,
                SUM(CASE WHEN ss.status = 'in_progress' THEN 1 ELSE 0 END) AS count_in_progress,
                SUM(CASE WHEN ss.status = 'passed' THEN 1 ELSE 0 END) AS count_passed
            FROM kanban_items ki
            LEFT JOIN kanban_batches b ON ki.batch_id = b.id
            LEFT JOIN inspection_sessions ss ON (ss.kanban_item_id = ki.id OR ss.did_id = ki.id)
            GROUP BY ki.id, ki.item_code, ki.item_description, ki.qty, ki.customer, ki.plan_type, ki.kanban_no, ki.req_date, b.document_number, b.vendor, b.imported_at, ki.created_at
            ORDER BY ki.id DESC";

$stMain = $pdo->query($mainSql);
$items = $stMain->fetchAll(PDO::FETCH_ASSOC);

echo "Total items in monitoring index: " . count($items) . "\n";
foreach ($items as $it) {
    if ($it['session_count'] > 0) {
        echo "Item ID: {$it['item_id']} | Code: {$it['item_code']} | Status: passed={$it['count_passed']}, rej={$it['count_rejected']}, prog={$it['count_in_progress']} | Last Session ID: {$it['last_session_id']}\n";
    }
}
