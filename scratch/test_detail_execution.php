<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDB();

$itemId         = 5;
$partCodeParam  = '1';
$lotNumberParam = '1';

$item = null;
$sessions = [];
$sessionLots = [];
$kanbanUsage = [];
$defects = [];

if ($pdo) {
    try {
        if ($itemId > 0) {
            $stLookup = $pdo->prepare("
                SELECT ss.id, ss.did_id, ss.kanban_item_id,
                       did.part_code AS did_pcode, did.lot_number AS did_lotnum,
                       ki.item_code AS ki_pcode, ki.kanban_no AS ki_kanban_no
                FROM inspection_sessions ss
                LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
                LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
                WHERE ss.id = :id
            ");
            $stLookup->execute([':id' => $itemId]);
            $lk = $stLookup->fetch(PDO::FETCH_ASSOC);

            if ($lk) {
                if (empty($partCodeParam)) {
                    $partCodeParam = $lk['did_pcode'] ?: $lk['ki_pcode'];
                }
                if (empty($lotNumberParam)) {
                    $rawLot = $lk['did_lotnum'] ?: $lk['ki_kanban_no'];
                    $lotNumberParam = preg_replace('/^SS-/i', '', $rawLot);
                }
            }
        }

        $cleanLot = preg_replace('/^SS-/i', '', $lotNumberParam);

        if (!empty($partCodeParam) || !empty($lotNumberParam) || $itemId > 0) {
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
                  AND (
                      (UPPER(COALESCE(did.part_code, ki.item_code, '')) = UPPER(:pcode))
                      OR ss.id = :direct_id1
                  )
                  AND (
                      UPPER(COALESCE(did.lot_number, '')) = UPPER(:lotnum)
                      OR UPPER(COALESCE(did.lot_number, '')) = UPPER(:clean_lot)
                      OR UPPER(COALESCE(ki.kanban_no, '')) = UPPER(:lotnum2)
                      OR UPPER(COALESCE(ki.kanban_no, '')) = UPPER(CONCAT('SS-', :clean_lot2))
                      OR ss.id = :direct_id2
                  )
                ORDER BY ss.id ASC
            ");
            $stmtSess->execute([
                ':pcode'      => $partCodeParam,
                ':lotnum'     => $lotNumberParam,
                ':clean_lot'  => $cleanLot,
                ':lotnum2'    => $lotNumberParam,
                ':clean_lot2' => $cleanLot,
                ':direct_id1' => $itemId,
                ':direct_id2' => $itemId
            ]);
            $sessions = $stmtSess->fetchAll(PDO::FETCH_ASSOC);

            if (empty($sessions) && $itemId > 0) {
                $stmtFallback = $pdo->prepare("
                    SELECT ss.*, 
                           u.name AS inspector_user_name, u.username AS inspector_username,
                           did.pic AS did_pic, did.lot_number AS did_lot, did.part_name AS did_part_name, did.part_code AS did_part_code,
                           ki.customer AS ki_customer, ki.str_loc AS ki_str_loc, ki.item_description AS ki_desc
                    FROM inspection_sessions ss
                    LEFT JOIN users u ON ss.inspector_id = u.id
                    LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
                    LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
                    WHERE ss.id = :id
                ");
                $stmtFallback->execute([':id' => $itemId]);
                $sessions = $stmtFallback->fetchAll(PDO::FETCH_ASSOC);
            }

            if (!empty($sessions)) {
                $firstSess = $sessions[0];
                $item = [
                    'item_code'        => $firstSess['did_part_code'] ?: ($firstSess['part_code'] ?? ($partCodeParam ?: '-')),
                    'item_description' => $firstSess['did_part_name'] ?: ($firstSess['ki_desc'] ?? 'Part Safety Stock'),
                    'lot_number'       => $firstSess['did_lot'] ?: ($lotNumberParam ?: '-'),
                    'customer'         => $firstSess['ki_customer'] ?: 'INTERNAL SAFETY STOCK',
                    'str_loc'          => $firstSess['ki_str_loc'] ?: 'WH-SAFETY'
                ];

                $sessionIds = array_column($sessions, 'id');
                $inQueryInt = implode(',', array_map('intval', $sessionIds));

                // 2. Fetch Breakdown Label Scan (Ref Numbers)
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

                // 3. Fetch Kanban Usage History
                $stmtKanban = $pdo->prepare("
                    SELECT 
                        s_kanban.id AS kanban_session_id,
                        s_kanban.started_at AS used_at,
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
                    GROUP BY s_kanban.id, s_kanban.started_at, s_kanban.auto_fulfilled_by_session_id, ki.kanban_no, ki.customer, u.name
                    ORDER BY s_kanban.id DESC
                ");
                $stmtKanban->execute([
                    ':l1' => $lotNumberParam,
                    ':l2' => $cleanLot
                ]);
                $kanbanUsage = $stmtKanban->fetchAll(PDO::FETCH_ASSOC);

                // 4. Fetch Defect / NG Records
                $stmtDef = $pdo->prepare("
                    SELECT ng.*, dt.name AS defect_type_name, sm.sample_number, sm.inspection_session_id, ss.started_at, ss.closed_at,
                           COALESCE(u.name, did.pic, '-') AS inspector_name
                    FROM inspection_ng_records ng
                    JOIN inspection_samples sm ON ng.inspection_sample_id = sm.id
                    JOIN inspection_sessions ss ON sm.inspection_session_id = ss.id
                    LEFT JOIN users u ON ss.inspector_id = u.id
                    LEFT JOIN daily_inspection_data did ON ss.did_id = did.id
                    LEFT JOIN defect_types dt ON ng.defect_type_id = dt.id
                    WHERE sm.inspection_session_id IN ({$inQueryInt})
                    ORDER BY ng.id ASC
                ");
                $stmtDef->execute();
                $defects = $stmtDef->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } catch (PDOException $e) {
        echo "CATCH PDOException: " . $e->getMessage() . "\n";
        $item = null;
    }
}

if (!$item) {
    echo "FINAL RESULT: \$item IS NULL! REDIRECTING!\n";
} else {
    echo "FINAL RESULT: SUCCESS! \$item IS VALID!\n";
    echo "Sessions count: " . count($sessions) . "\n";
    echo "SessionLots count: " . count($sessionLots) . "\n";
    echo "KanbanUsage count: " . count($kanbanUsage) . "\n";
}
