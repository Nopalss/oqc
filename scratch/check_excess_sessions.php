<?php
require __DIR__ . '/../config/database.php';

$rows = $pdo->query("
    SELECT k.id, k.kanban_no, k.qty as target_qty, k.status as kanban_status,
           s.id as sess_id, s.status as sess_status, s.total_scanned_qty, s.excess_qty,
           COALESCE((SELECT SUM(CASE WHEN isl2.lot_result IN ('passed','skipped') AND (isl2.lot_status IS NULL OR isl2.lot_status != 'replaced') THEN isl2.qty ELSE 0 END)
                     FROM inspection_session_lots isl2 WHERE isl2.inspection_session_id = s.id), 0) as lot_sum,
           COALESCE((
               SELECT SUM(
                   CASE
                       WHEN EXISTS (SELECT 1 FROM inspection_session_lots isl_c WHERE isl_c.inspection_session_id = s2.id) THEN
                           COALESCE((SELECT SUM(CASE WHEN isl2b.lot_result IN ('passed', 'skipped') AND (isl2b.lot_status IS NULL OR isl2b.lot_status != 'replaced') THEN isl2b.qty ELSE 0 END)
                                     FROM inspection_session_lots isl2b WHERE isl2b.inspection_session_id = s2.id), 0)
                       WHEN s2.status = 'passed' THEN s2.total_scanned_qty
                       ELSE 0
                   END
               )
               FROM inspection_sessions s2
               WHERE s2.kanban_item_id = k.id
           ), 0) AS total_passed_qty
    FROM kanban_items k
    JOIN inspection_sessions s ON s.kanban_item_id = k.id
    WHERE s.excess_qty > 0
    ORDER BY s.id DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    echo "Tidak ada sesi dengan excess_qty > 0\n";
    // Check recent sessions anyway
    $rows2 = $pdo->query("
        SELECT k.id, k.kanban_no, k.qty as target_qty, k.status,
               s.id as sess_id, s.status as s_status, s.total_scanned_qty, s.excess_qty,
               COALESCE((SELECT SUM(CASE WHEN isl2.lot_result IN ('passed','skipped') AND (isl2.lot_status IS NULL OR isl2.lot_status != 'replaced') THEN isl2.qty ELSE 0 END)
                         FROM inspection_session_lots isl2 WHERE isl2.inspection_session_id = s.id), 0) as lot_sum
        FROM kanban_items k
        JOIN inspection_sessions s ON s.kanban_item_id = k.id
        ORDER BY s.id DESC LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    echo "Recent 5 sessions:\n";
    foreach ($rows2 as $r) {
        echo "  kanban={$r['kanban_no']} target={$r['target_qty']} k.status={$r['status']} sess_id={$r['sess_id']} s.status={$r['s_status']} scanned={$r['total_scanned_qty']} excess={$r['excess_qty']} lot_sum={$r['lot_sum']}\n";
    }
} else {
    foreach ($rows as $r) {
        echo "kanban_id={$r['id']} kanban_no={$r['kanban_no']} target={$r['target_qty']} k.status={$r['kanban_status']}\n";
        echo "  sess_id={$r['sess_id']} s.status={$r['sess_status']} scanned={$r['total_scanned_qty']} excess={$r['excess_qty']} lot_sum={$r['lot_sum']}\n";
        echo "  total_passed_qty (subquery) = {$r['total_passed_qty']}\n";
        echo "  => Ini yang ditampilkan di index.php\n\n";
    }
}
