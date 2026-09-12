<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

$pdo = getDB();
$partCodeParam = '1';
$lotNumberParam = '1';
$cleanLot = '1';

$stmtSess = $pdo->prepare("
    SELECT ss.*, 
           u.name AS inspector_user_name, u.username AS inspector_username,
           did.pic AS did_pic, did.lot_number AS did_lot, did.part_name AS did_part_name, did.part_code AS did_part_code,
           ki.customer AS ki_customer, ki.str_loc AS ki_str_loc, ki.item_description AS ki_desc
    FROM inspection_sessions ss
    LEFT JOIN users u ON ss.inspector_id = u.id
    LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
    LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
    WHERE ss.inspection_type = 'safety_stock'
      AND UPPER(COALESCE(did.part_code, ki.item_code, '')) = UPPER(:pcode)
    ORDER BY ss.id ASC
");
$stmtSess->execute([':pcode' => $partCodeParam]);
$sessions = $stmtSess->fetchAll(PDO::FETCH_ASSOC);

$sessionIds = array_column($sessions, 'id');
$inQueryInt = implode(',', array_map('intval', $sessionIds));

$stmtLots = $pdo->prepare("
    SELECT isl.*, ss.started_at AS scanned_at, ss.status AS session_status, ss.auto_fulfilled_by_session_id,
           COALESCE(u.name, did.pic, 'Inspector QC') AS inspector_name
    FROM inspection_session_lots isl
    JOIN inspection_sessions ss ON ss.id = isl.inspection_session_id
    LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
    LEFT JOIN users u ON u.id = ss.inspector_id
    WHERE ss.id IN ({$inQueryInt})
    ORDER BY isl.id ASC
");
$stmtLots->execute();
$sessionLots = $stmtLots->fetchAll(PDO::FETCH_ASSOC);

$stmtKanban = $pdo->prepare("
    SELECT 
        s_kanban.id AS kanban_session_id,
        s_kanban.started_at AS used_at,
        s_kanban.closed_at AS closed_at,
        s_kanban.status AS session_status,
        s_kanban.auto_fulfilled_by_session_id,
        ki.kanban_no,
        ki.customer,
        COALESCE(u.name, 'Inspector QC') AS inspector_name,
        COALESCE(MAX(isl_k.ref_number), MAX(isl_ss.ref_number), '-') AS ref_number,
        COALESCE(MAX(isl_k.qty), MAX(s_ss.total_scanned_qty), 0) AS used_qty
    FROM inspection_sessions s_kanban
    LEFT JOIN kanban_items ki ON ki.id = s_kanban.kanban_item_id
    LEFT JOIN users u ON u.id = s_kanban.inspector_id
    LEFT JOIN inspection_sessions s_ss ON s_ss.id = s_kanban.auto_fulfilled_by_session_id
    LEFT JOIN inspection_session_lots isl_ss ON isl_ss.inspection_session_id = s_ss.id
    LEFT JOIN inspection_session_lots isl_k ON (isl_k.inspection_session_id = s_kanban.id AND (UPPER(isl_k.lot_number) = UPPER(:l1) OR UPPER(isl_k.lot_number) = UPPER(:l2)))
    WHERE s_kanban.auto_fulfilled_by_session_id IN ({$inQueryInt})
       OR (s_kanban.inspection_type = 'kanban' AND isl_k.id IS NOT NULL AND (isl_k.remarks LIKE '%Safety Stock%' OR s_kanban.auto_fulfilled_by_session_id > 0))
    GROUP BY s_kanban.id, s_kanban.started_at, s_kanban.closed_at, s_kanban.status, s_kanban.samples_checked, s_kanban.sample_size, s_kanban.ng_count, s_kanban.auto_fulfilled_by_session_id, ki.kanban_no, ki.customer, u.name
    ORDER BY s_kanban.id DESC
");
$stmtKanban->execute([':l1' => $lotNumberParam, ':l2' => $cleanLot]);
$kanbanUsage = $stmtKanban->fetchAll(PDO::FETCH_ASSOC);

echo "=== SESSIONS ===\n";
print_r($sessions);
echo "=== SESSION LOTS ===\n";
print_r($sessionLots);
echo "=== KANBAN USAGE ===\n";
print_r($kanbanUsage);
