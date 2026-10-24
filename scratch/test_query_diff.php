<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDB();

$kSqlIndex = "
    SELECT k.id, k.kanban_no, k.qty as target_qty, k.status as db_status,
           COALESCE((
               SELECT SUM(
                   CASE 
                       WHEN EXISTS (SELECT 1 FROM inspection_session_lots isl_c WHERE isl_c.inspection_session_id = s2.id) THEN
                           COALESCE((SELECT SUM(CASE WHEN isl2.lot_result IN ('passed', 'skipped') AND (isl2.lot_status IS NULL OR isl2.lot_status != 'replaced') THEN isl2.qty ELSE 0 END)
                                     FROM inspection_session_lots isl2 WHERE isl2.inspection_session_id = s2.id), 0) - COALESCE(s2.excess_qty, 0)
                       WHEN s2.status = 'passed' THEN
                           (s2.total_scanned_qty - COALESCE(s2.excess_qty, 0))
                       ELSE 0
                   END
               )
               FROM inspection_sessions s2
               WHERE s2.kanban_item_id = k.id
           ), 0) AS total_passed_qty_index
    FROM kanban_items k
    WHERE k.id = 3
";
$row1 = $pdo->query($kSqlIndex)->fetch(PDO::FETCH_ASSOC);
echo "=== QUERY INDEX.PHP ===\n";
print_r($row1);

$kSqlLive = "
    SELECT k.id, k.status, k.qty as target_qty,
           COALESCE((
               SELECT SUM(
                   CASE 
                       WHEN EXISTS (SELECT 1 FROM inspection_session_lots isl_c WHERE isl_c.inspection_session_id = s2.id) THEN
                           COALESCE((SELECT SUM(CASE WHEN isl2.lot_result IN ('passed', 'skipped') AND (isl2.lot_status IS NULL OR isl2.lot_status != 'replaced') THEN isl2.qty ELSE 0 END)
                                     FROM inspection_session_lots isl2 WHERE isl2.inspection_session_id = s2.id), 0)
                       WHEN s2.status = 'passed' THEN
                           s2.total_scanned_qty
                       ELSE 0
                   END
               )
               FROM inspection_sessions s2
               WHERE s2.kanban_item_id = k.id
           ), 0) AS total_passed_qty_live
    FROM kanban_items k
    WHERE k.id = 3
";
$row2 = $pdo->query($kSqlLive)->fetch(PDO::FETCH_ASSOC);
echo "=== QUERY GET_LIVE_STATUS.PHP ===\n";
print_r($row2);

echo "=== SESSIONS & EXCESS FOR KANBAN ID 3 ===\n";
$sess = $pdo->query("SELECT id, kanban_item_id, total_scanned_qty, excess_qty, status FROM inspection_sessions WHERE kanban_item_id = 3")->fetchAll(PDO::FETCH_ASSOC);
print_r($sess);
