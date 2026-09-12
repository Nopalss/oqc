<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

$pdo = getDB();
$inQueryInt = '1,2,3,5,6,8,9,11,13';

$sql = "
    SELECT 
        s_kanban.id AS kanban_session_id,
        s_kanban.started_at AS used_at,
        s_kanban.closed_at AS closed_at,
        s_kanban.status AS session_status,
        s_kanban.samples_checked,
        s_kanban.sample_size,
        s_kanban.ng_count,
        s_ss.id AS ss_session_id,
        COALESCE(isl_k.ref_number, (SELECT isl_ss.ref_number FROM inspection_session_lots isl_ss WHERE isl_ss.inspection_session_id = s_ss.id LIMIT 1), '-') AS ref_number,
        COALESCE(isl_k.qty, s_ss.total_scanned_qty, 0) AS used_qty,
        ki.kanban_no,
        ki.customer,
        COALESCE(u.name, 'Inspector QC') AS inspector_name
    FROM inspection_sessions s_ss
    JOIN inspection_sessions s_kanban ON s_kanban.id = s_ss.auto_fulfilled_by_session_id
    LEFT JOIN kanban_items ki ON ki.id = s_kanban.kanban_item_id
    LEFT JOIN users u ON u.id = s_kanban.inspector_id
    LEFT JOIN inspection_session_lots isl_k ON (isl_k.inspection_session_id = s_kanban.id AND (isl_k.remarks LIKE CONCAT('%Sesi #', s_ss.id, '%') OR isl_k.remarks LIKE CONCAT('%Session #', s_ss.id, '%')))
    WHERE s_ss.id IN ({$inQueryInt}) AND s_ss.auto_fulfilled_by_session_id > 0
    ORDER BY s_kanban.id DESC, s_ss.id ASC
";

$stmt = $pdo->query($sql);
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
