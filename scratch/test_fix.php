<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDB();

echo "=== TEST QUERY INDEX.PHP FOR 2026-09-10 ===\n";
$stmt = $pdo->query("
    SELECT
        DATE(ss.started_at) AS tanggal,
        COUNT(DISTINCT ss.id) AS total_sesi,
        COUNT(DISTINCT isl.id) AS total_label,
        COALESCE(SUM(isl.qty), 0) AS total_qty,
        COALESCE(SUM(ss.ng_count), 0) AS total_ng
    FROM inspection_sessions ss
    LEFT JOIN inspection_session_lots isl ON isl.inspection_session_id = ss.id
    LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
    LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
    WHERE DATE(ss.started_at) = '2026-09-10'
      AND (isl.remarks IS NULL OR isl.remarks NOT LIKE '%Sisa Split Safety Stock%')
      AND (ki.item_description IS NULL OR ki.item_description != 'Safety Stock Overflow')
    GROUP BY DATE(ss.started_at)
");
print_r($stmt->fetch(PDO::FETCH_ASSOC));

echo "\n=== TEST QUERY DETAIL.PHP SAFETY STOCK FOR 2026-09-10 ===\n";
$stmt = $pdo->query("
    SELECT
        isl.id AS lot_id,
        isl.ref_number,
        isl.lot_number,
        isl.qty,
        ss.id AS session_id,
        ss.inspection_type
    FROM inspection_session_lots isl
    JOIN inspection_sessions ss ON ss.id = isl.inspection_session_id
    LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
    LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
    WHERE DATE(ss.started_at) = '2026-09-10'
      AND ss.inspection_type = 'safety_stock'
      AND (isl.remarks IS NULL OR isl.remarks NOT LIKE '%Sisa Split Safety Stock%')
      AND (ki.item_description IS NULL OR ki.item_description != 'Safety Stock Overflow')
");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
