<?php
/**
 * Laporan Inspeksi Harian - Detail (Supervisor Executive View)
 * Dual-Perspective Hierarchical Review:
 * 1. Mode A: Berdasarkan Kanban & Part (Order Fulfillment View - Multi-Cicilan & Re-inspeksi)
 * 2. Mode B: Berdasarkan Sesi Inspeksi (Session Activity Timeline View)
 *
 * PT. Surya Technology Industri — Outgoing Quality Control Division
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

$pdo = getDB();

// ── API Backward Compatibility: get_lot_history ────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_lot_history' && $pdo) {
    header('Content-Type: application/json');
    $pCode   = sanitize($_GET['part_code'] ?? '');
    $lNum    = sanitize($_GET['lot_number'] ?? '');
    $kbId    = (int)($_GET['kanban_item_id'] ?? 0);
    $currSid = (int)($_GET['session_id'] ?? 0);

    $history = [];
    try {
        if ($kbId > 0) {
            $stmtH = $pdo->prepare("
                SELECT s.id, s.started_at, s.status, s.samples_checked, s.sample_size, s.ng_count, s.reject_number,
                       s.inspection_type, s.total_scanned_qty, s.parent_session_id, s.reinspection_notes, s.is_reinspection,
                       did.lot_number, did.cavity, did.pic as did_pic,
                       k.kanban_no, k.customer,
                       COALESCE(NULLIF(mp.part_name, ''), did.part_name) as display_part_name,
                       u.name as inspector_name
                FROM inspection_sessions s
                JOIN daily_inspection_data did ON did.id = s.did_id
                LEFT JOIN kanban_items k ON k.id = s.kanban_item_id
                LEFT JOIN master_parts mp ON (mp.id = s.part_id OR UPPER(mp.part_code) = UPPER(did.part_code))
                LEFT JOIN users u ON u.id = s.inspector_id
                WHERE (s.kanban_item_id = :kb_id OR s.id = :curr_sid1 OR s.parent_session_id = :curr_sid2)
                  AND NOT EXISTS (
                      SELECT 1 FROM inspection_session_lots isl_chk 
                      WHERE isl_chk.inspection_session_id = s.id 
                        AND isl_chk.remarks LIKE '%Sisa Split Safety Stock%'
                  )
                ORDER BY s.id DESC
            ");
            $stmtH->execute([
                ':kb_id'     => $kbId,
                ':curr_sid1' => $currSid,
                ':curr_sid2' => $currSid
            ]);
            $history = $stmtH->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmtH = $pdo->prepare("
                SELECT s.id, s.started_at, s.status, s.samples_checked, s.sample_size, s.ng_count, s.reject_number,
                       s.inspection_type, s.total_scanned_qty, s.parent_session_id, s.reinspection_notes, s.is_reinspection,
                       did.lot_number, did.cavity, did.pic as did_pic,
                       k.kanban_no, k.customer,
                       COALESCE(NULLIF(mp.part_name, ''), did.part_name) as display_part_name,
                       u.name as inspector_name
                FROM inspection_sessions s
                JOIN daily_inspection_data did ON did.id = s.did_id
                LEFT JOIN kanban_items k ON k.id = s.kanban_item_id
                LEFT JOIN master_parts mp ON (mp.id = s.part_id OR UPPER(mp.part_code) = UPPER(did.part_code))
                LEFT JOIN users u ON u.id = s.inspector_id
                WHERE UPPER(did.part_code) = UPPER(:pcode)
                  AND (did.lot_number = :lnum OR s.id = :curr_sid1 OR s.parent_session_id = :curr_sid2)
                  AND NOT EXISTS (
                      SELECT 1 FROM inspection_session_lots isl_chk 
                      WHERE isl_chk.inspection_session_id = s.id 
                        AND isl_chk.remarks LIKE '%Sisa Split Safety Stock%'
                  )
                ORDER BY s.id DESC
            ");
            $stmtH->execute([
                ':pcode'     => $pCode,
                ':lnum'      => $lNum,
                ':curr_sid1' => $currSid,
                ':curr_sid2' => $currSid
            ]);
            $history = $stmtH->fetchAll(PDO::FETCH_ASSOC);
        }

        foreach ($history as &$item) {
            $stmtDef = $pdo->prepare("
                SELECT dt.name as defect_name, SUM(n.qty_ng) as total_qty_ng
                FROM inspection_ng_records n
                JOIN defect_types dt ON dt.id = n.defect_type_id
                WHERE n.inspection_session_id = :sid AND (n.is_cancelled IS NULL OR n.is_cancelled = 0)
                GROUP BY n.defect_type_id, dt.name
                ORDER BY total_qty_ng DESC
            ");
            $stmtDef->execute([':sid' => $item['id']]);
            $item['defects'] = $stmtDef->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($item);

    } catch (PDOException $e) {}

    echo json_encode(['success' => true, 'history' => $history]);
    exit;
}

$dateParam = sanitize($_GET['date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)) {
    $dateParam = date('Y-m-d');
}

// ── Helper: AQL Level II Lookup for Lot Fallback ───────────────────────────
if (!function_exists('getLotAqlFallback')) {
    function getLotAqlFallback($qty, $pdo = null) {
        static $aqlTable = null;
        $qty = (int)$qty;
        if ($qty <= 0) return ['sample_size' => 0, 'reject_number' => 1];
        
        if ($aqlTable === null && $pdo) {
            try {
                $stmt = $pdo->query("SELECT qty_min, qty_max, sample_size, reject_number FROM aql_standards WHERE inspection_level IN ('G-II', 'Level II', 'II') ORDER BY qty_min ASC");
                $aqlTable = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $aqlTable = [];
            }
        }
        
        if (!empty($aqlTable)) {
            foreach ($aqlTable as $row) {
                if ($qty >= (int)$row['qty_min'] && $qty <= (int)$row['qty_max']) {
                    return [
                        'sample_size' => (int)$row['sample_size'],
                        'reject_number' => (int)$row['reject_number']
                    ];
                }
            }
        }
        
        // Standard ISO 2859-1 / MIL-STD-105E General Inspection Level II
        if ($qty <= 8) return ['sample_size' => min(2, $qty), 'reject_number' => 1];
        if ($qty <= 15) return ['sample_size' => min(3, $qty), 'reject_number' => 1];
        if ($qty <= 25) return ['sample_size' => min(5, $qty), 'reject_number' => 1];
        if ($qty <= 50) return ['sample_size' => min(8, $qty), 'reject_number' => 1];
        if ($qty <= 90) return ['sample_size' => min(13, $qty), 'reject_number' => 1];
        if ($qty <= 150) return ['sample_size' => min(20, $qty), 'reject_number' => 1];
        if ($qty <= 280) return ['sample_size' => min(32, $qty), 'reject_number' => 1];
        if ($qty <= 500) return ['sample_size' => min(50, $qty), 'reject_number' => 1];
        if ($qty <= 1200) return ['sample_size' => min(80, $qty), 'reject_number' => 2];
        if ($qty <= 3200) return ['sample_size' => min(125, $qty), 'reject_number' => 2];
        if ($qty <= 10000) return ['sample_size' => min(200, $qty), 'reject_number' => 3];
        return ['sample_size' => min(315, $qty), 'reject_number' => 4];
    }
}

// ── 1. Data Structures Initialization ──────────────────────────────────────
$kanbanGroups   = []; // Grouped by Kanban Item
$ssGroups       = []; // Grouped by Safety Stock (Part + Lot)
$sessionTimeline = []; // Grouped by Session in chronological order
$allLotsMap     = []; // session_id => [lots]
$allNgMap       = []; // session_id => [ng records]

$daySummary = [
    'total_kanban_items'      => 0,
    'total_ss_items'          => 0,
    'total_sesi'              => 0,
    'total_label'             => 0,
    'total_sampling'          => 0,
    'total_qty'               => 0,
    'total_ng'                => 0,
    'passed_sesi'             => 0,
    'rejected_sesi'           => 0,
    'first_pass_yield'        => 100,
    'total_approved_sessions' => 0,
    'total_pending_sessions'  => 0,
    'is_all_approved'         => false
];

if ($pdo) {
    try {
        // ── A. Fetch all inspection sessions for this date ─────────────────
        $stmtSessions = $pdo->prepare("
            SELECT 
                ss.id AS session_id,
                ss.inspection_type,
                ss.kanban_item_id,
                ss.did_id,
                ss.part_id,
                ss.sample_size,
                ss.total_scanned_qty,
                ss.excess_qty,
                ss.use_safety_stock_qty,
                ss.reject_number,
                ss.samples_checked,
                ss.ng_count,
                ss.status AS session_status,
                ss.auto_fulfilled_by_session_id,
                ss.original_ss_session_id,
                ss.parent_session_id,
                ss.is_reinspection,
                ss.reinspection_type,
                ss.reinspection_notes,
                ss.started_at,
                ss.closed_at,
                ss.is_approved,
                ss.approved_by,
                ss.approved_at,
                ss.approval_notes,
                ss.line_id,
                COALESCE(ss.line_name, l.name, 'Line 1') AS line_name,
                COALESCE(u.name, 'System') AS inspector_name,
                COALESCE(u_app.name, '-') AS supervisor_name,
                -- Kanban details
                COALESCE(ki.kanban_no, '-') AS kanban_no,
                COALESCE(ki.customer, 'INTERNAL') AS customer,
                ki.item_code AS kanban_item_code,
                ki.item_description AS kanban_item_desc,
                COALESCE(ki.qty, 0) AS kanban_qty,
                -- DID details
                did.part_code AS did_part_code,
                did.part_name AS did_part_name,
                did.lot_number AS did_lot_number,
                did.cavity AS did_cavity,
                did.pic AS did_pic,
                -- Master Part details
                COALESCE(did.part_code, ki.item_code, mp.part_code, '-') AS part_code,
                COALESCE(did.part_name, ki.item_description, mp.part_name, '-') AS part_name
            FROM inspection_sessions ss
            LEFT JOIN `lines` l ON l.id = ss.line_id
            LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
            LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
            LEFT JOIN master_parts mp ON (mp.id = ss.part_id OR UPPER(mp.part_code) = UPPER(COALESCE(did.part_code, ki.item_code)))
            LEFT JOIN users u ON u.id = ss.inspector_id
            LEFT JOIN users u_app ON u_app.id = ss.approved_by
            WHERE (DATE(ss.started_at) = :tgl OR EXISTS (
                SELECT 1 FROM inspection_session_lots isl_chk 
                WHERE isl_chk.inspection_session_id = ss.id AND DATE(isl_chk.created_at) = :tgl_chk
            ))
            AND (ss.original_ss_session_id IS NULL OR ss.original_ss_session_id = 0)
            AND (ki.remark IS NULL OR (ki.remark NOT LIKE '%AUTO-SS-SESS-%' AND ki.remark NOT LIKE '%Split%'))
            ORDER BY ss.started_at ASC, ss.id ASC
        ");
        $stmtSessions->execute([':tgl' => $dateParam, ':tgl_chk' => $dateParam]);
        $rawSessions = $stmtSessions->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($rawSessions)) {
            $sessionIds = array_column($rawSessions, 'session_id');
            $inSessIds = implode(',', array_map('intval', $sessionIds));

            // ── B. Fetch all scanned box labels for these sessions ─────────
            $stmtLots = $pdo->query("
                SELECT 
                    isl.id AS lot_id,
                    isl.inspection_session_id,
                    isl.ref_number,
                    isl.lot_number,
                    isl.qty,
                    isl.sample_size,
                    isl.reject_number,
                    isl.accept_number,
                    isl.lot_result,
                    isl.lot_status,
                    isl.remarks,
                    isl.created_at AS scanned_at,
                    isl.action_noted_at,
                    isl.replaced_by_lot_id
                FROM inspection_session_lots isl
                WHERE isl.inspection_session_id IN ({$inSessIds})
                  AND (isl.remarks IS NULL OR (isl.remarks NOT LIKE '%Sisa Split Safety Stock%' AND isl.remarks NOT LIKE 'Sisa Kelebihan Kanban%' AND isl.remarks NOT LIKE 'Alokasi Safety Stock%'))
                ORDER BY isl.id ASC
            ");
            $rawLotsList = [];
            foreach ($stmtLots->fetchAll(PDO::FETCH_ASSOC) as $lRow) {
                $sId = (int)$lRow['inspection_session_id'];
                $lRow['defects'] = [];

                $lotQty = (int)($lRow['qty'] ?? 0);
                $lSampleSize = (int)($lRow['sample_size'] ?? 0);
                $lRejectNum = isset($lRow['reject_number']) ? (int)$lRow['reject_number'] : 0;
                if ($lSampleSize <= 0 || $lRejectNum <= 0) {
                    $aqlFb = getLotAqlFallback($lotQty, $pdo);
                    if ($lSampleSize <= 0) $lSampleSize = $aqlFb['sample_size'];
                    if ($lRejectNum <= 0) $lRejectNum = $aqlFb['reject_number'];
                }
                $lRow['computed_sample_size'] = $lSampleSize;
                $lRow['computed_reject_number'] = $lRejectNum;

                $rawLotsList[$sId][] = $lRow;
            }

            // ── B2. Fetch lot substitution / reinspection logs ─────────────
            $substByLot = [];
            $stmtSubst = $pdo->query("
                SELECT 
                    sub.id,
                    sub.original_session_id,
                    sub.ng_session_lot_id,
                    sub.action_type,
                    sub.replacement_session_lot_id,
                    sub.reinspection_session_id,
                    sub.actioned_by,
                    sub.notes,
                    sub.created_at AS action_at,
                    COALESCE(u.name, 'Inspector') AS actioned_by_name
                FROM lot_substitution_log sub
                LEFT JOIN users u ON u.id = sub.actioned_by
                WHERE sub.original_session_id IN ({$inSessIds})
                ORDER BY sub.id ASC
            ");
            if ($stmtSubst) {
                while ($sub = $stmtSubst->fetch(PDO::FETCH_ASSOC)) {
                    $substByLot[(int)$sub['ng_session_lot_id']][] = $sub;
                }
            }

            // ── C. Fetch all defect NG records for these sessions ──────────
            $stmtNg = $pdo->query("
                SELECT 
                    ngr.id,
                    ngr.inspection_session_id,
                    ngr.session_lot_id,
                    ngr.part_id,
                    ngr.defect_type_id,
                    ngr.qty_ng,
                    ngr.remark,
                    COALESCE(ngr.is_sorted, 0) AS is_sorted,
                    ngr.created_at AS defect_created_at,
                    dt.name AS defect_name,
                    COALESCE(NULLIF(ngr.lot_number, ''), isl.lot_number, '-') AS lot_number,
                    COALESCE(NULLIF(ngr.ref_number, ''), isl.ref_number, '-') AS ref_number,
                    COALESCE(u.name, 'Inspector') AS inspector_name
                FROM inspection_ng_records ngr
                INNER JOIN defect_types dt ON ngr.defect_type_id = dt.id
                LEFT JOIN inspection_session_lots isl ON ngr.session_lot_id = isl.id
                LEFT JOIN inspection_sessions ss ON ngr.inspection_session_id = ss.id
                LEFT JOIN users u ON u.id = ss.inspector_id
                WHERE ngr.inspection_session_id IN ({$inSessIds})
                  AND (ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)
                ORDER BY ngr.id ASC
            ");
            foreach ($stmtNg->fetchAll(PDO::FETCH_ASSOC) as $ngRow) {
                $sId = (int)$ngRow['inspection_session_id'];
                $allNgMap[$sId][] = $ngRow;
            }

            // Distribute NG defects to their corresponding lots in raw lots list
            $allUnmatchedNgMap = [];
            foreach ($allNgMap as $sId => $ngList) {
                if (!isset($rawLotsList[$sId])) {
                    $allUnmatchedNgMap[$sId] = $ngList;
                    continue;
                }
                foreach ($ngList as $ng) {
                    $matched = false;
                    foreach ($rawLotsList[$sId] as &$lotRef) {
                        $isMatched = false;
                        if (!empty($ng['session_lot_id']) && (int)$ng['session_lot_id'] === (int)$lotRef['lot_id']) {
                            $isMatched = true;
                        } elseif (empty($ng['session_lot_id'])) {
                            if (!empty($ng['ref_number']) && $ng['ref_number'] !== '-' && $ng['ref_number'] === $lotRef['ref_number']) {
                                $isMatched = true;
                            } elseif (!empty($ng['lot_number']) && $ng['lot_number'] !== '-' && $ng['lot_number'] === $lotRef['lot_number']) {
                                $isMatched = true;
                            }
                        }
                        if ($isMatched) {
                            $lotRef['defects'][] = $ng;
                            $matched = true;
                            break;
                        }
                    }
                    unset($lotRef);
                    if (!$matched) {
                        $allUnmatchedNgMap[$sId][] = $ng;
                    }
                }
            }

            // Expand lots to represent both Initial and Reinspected attempts
            foreach ($rawLotsList as $sId => $lotItems) {
                foreach ($lotItems as $lRow) {
                    $lotId = (int)$lRow['lot_id'];
                    $hasSubst = isset($substByLot[$lotId]);
                    $lotDefects = $lRow['defects'] ?? [];

                    if ($hasSubst) {
                        $sortLog = null;
                        foreach ($substByLot[$lotId] as $log) {
                            if ($log['action_type'] === 'sort_reinspect') {
                                $sortLog = $log;
                                break;
                            }
                        }

                        if ($sortLog) {
                            // Separate defects between Initial Attempt and Reinspection Attempt
                            $initialDefects = [];
                            $reinspectDefects = [];

                            foreach ($lotDefects as $df) {
                                if (!empty($df['is_sorted'])) {
                                    $initialDefects[] = $df;
                                } else {
                                    if (!empty($sortLog['action_at']) && !empty($df['defect_created_at']) && $df['defect_created_at'] < $sortLog['action_at']) {
                                        $initialDefects[] = $df;
                                    } else {
                                        $reinspectDefects[] = $df;
                                    }
                                }
                            }

                            if (empty($initialDefects) && !empty($lotDefects)) {
                                $actualRes = strtolower($lRow['lot_result'] ?? '');
                                if ($actualRes === 'passed') {
                                    $initialDefects = $lotDefects;
                                    $reinspectDefects = [];
                                } else {
                                    $initialDefects = $lotDefects;
                                }
                            }

                            $initialNgCount = 0;
                            foreach ($initialDefects as $id) {
                                $initialNgCount += (int)($id['qty_ng'] ?? 1);
                            }
                            if ($initialNgCount === 0 && !empty($initialDefects)) {
                                $initialNgCount = count($initialDefects);
                            }

                            $reinspectNgCount = 0;
                            foreach ($reinspectDefects as $rd) {
                                $reinspectNgCount += (int)($rd['qty_ng'] ?? 1);
                            }
                            if ($reinspectNgCount === 0 && !empty($reinspectDefects)) {
                                $reinspectNgCount = count($reinspectDefects);
                            }

                            $actualLotResult = strtolower($lRow['lot_result'] ?? '');
                            $rejectLimit = (int)($lRow['computed_reject_number'] ?? 1);

                            // Row 1: Initial Attempt (Always Rejected/NG)
                            $attempt1 = $lRow;
                            $attempt1['attempt_type'] = 'initial';
                            $attempt1['attempt_label'] = 'Inspeksi Awal';
                            $attempt1['lot_result'] = 'rejected';
                            $attempt1['defects'] = $initialDefects;
                            $attempt1['ng_count'] = $initialNgCount;
                            $attempt1['status_badge'] = 'REJECTED (Awal)';
                            $attempt1['status_label'] = 'REJECTED';
                            $attempt1['is_initial_attempt'] = true;
                            $attempt1['has_subsequent_reinspect'] = true;

                            // Row 2: Reinspection Attempt (Dynamic: Passed or Rejected or In Progress)
                            $attempt2 = $lRow;
                            $attempt2['attempt_type'] = 'reinspection';
                            $attempt2['attempt_label'] = 'Re-inspeksi';
                            $attempt2['scanned_at'] = $sortLog['action_at'] ?: ($lRow['action_noted_at'] ?: $lRow['scanned_at']);
                            $attempt2['is_reinspected_attempt'] = true;
                            $attempt2['action_type'] = 'sort_reinspect';
                            $attempt2['actioned_by_name'] = $sortLog['actioned_by_name'];
                            $attempt2['action_at'] = $sortLog['action_at'];

                            if ($actualLotResult === 'rejected' || ($reinspectNgCount >= $rejectLimit && $rejectLimit > 0)) {
                                $attempt2['lot_result'] = 'rejected';
                                $attempt2['defects'] = $reinspectDefects;
                                $attempt2['ng_count'] = $reinspectNgCount;
                                $attempt2['status_badge'] = 'REJECTED (Re-inspeksi)';
                                $attempt2['status_label'] = 'REJECTED (RE-INSPEKSI)';
                                $attempt2['action_notes'] = $sortLog['notes'] ?: 'Part NG disortir dan diuji ulang fisik, namun tetap ditemukan cacat/NG.';
                            } elseif ($actualLotResult === 'passed') {
                                $attempt2['lot_result'] = 'passed';
                                $attempt2['defects'] = [];
                                $attempt2['ng_count'] = 0;
                                $attempt2['status_badge'] = 'PASSED (Re-inspeksi)';
                                $attempt2['status_label'] = 'PASSED (RE-INSPEKSI)';
                                $attempt2['action_notes'] = $sortLog['notes'] ?: 'Part NG disortir dan ditukar dengan part bagus -> Dilakukan re-inspeksi sampel fisik dan lolos uji.';
                            } else {
                                $attempt2['lot_result'] = 'in_progress';
                                $attempt2['defects'] = $reinspectDefects;
                                $attempt2['ng_count'] = $reinspectNgCount;
                                $attempt2['status_badge'] = 'IN PROGRESS (Re-inspeksi)';
                                $attempt2['status_label'] = 'IN PROGRESS (RE-INSPEKSI)';
                                $attempt2['action_notes'] = $sortLog['notes'] ?: 'Dalam proses re-inspeksi fisik.';
                            }

                            $allLotsMap[$sId][] = $attempt1;
                            $allLotsMap[$sId][] = $attempt2;
                            continue;
                        }
                    }

                    // Check if replaced
                    if ($lRow['lot_status'] === 'replaced') {
                        $lRow['attempt_type'] = 'standard';
                        $lRow['is_replaced'] = true;
                        $lRow['status_badge'] = 'DIGANTIKAN';
                        $allLotsMap[$sId][] = $lRow;
                        continue;
                    }

                    if (isset($lRow['remarks']) && strpos($lRow['remarks'], 'Box Pengganti') !== false) {
                        $lRow['attempt_type'] = 'standard';
                        $lRow['is_replacement_box'] = true;
                        $lRow['status_badge'] = 'BOX PENGGANTI';
                        $allLotsMap[$sId][] = $lRow;
                        continue;
                    }

                    // Normal lot
                    $lRow['attempt_type'] = 'standard';
                    $allLotsMap[$sId][] = $lRow;
                }
            }

            // ── C2. Fetch Top Defect Types, Models, and Parts for this date ──
            $dailyDefectRows = [];
            $dailyModelRows  = [];
            $dailyPartRows   = [];

            try {
                // 1. Top Defect Types (Pcs)
                $stmtD = $pdo->prepare("
                    SELECT dt.name AS defect_name, SUM(ngr.qty_ng) AS total_qty
                    FROM inspection_ng_records ngr
                    JOIN defect_types dt ON ngr.defect_type_id = dt.id
                    WHERE ngr.inspection_session_id IN ({$inSessIds})
                      AND (ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)
                    GROUP BY dt.id, dt.name
                    ORDER BY total_qty DESC
                    LIMIT 10
                ");
                $stmtD->execute();
                $dailyDefectRows = $stmtD->fetchAll(PDO::FETCH_ASSOC);

                // 2. Top Models (NG Pcs)
                $stmtM = $pdo->prepare("
                    SELECT COALESCE(mm.name, mp.model, 'NO MODEL') AS model_name, SUM(ngr.qty_ng) AS total_qty
                    FROM inspection_ng_records ngr
                    JOIN inspection_sessions s ON s.id = ngr.inspection_session_id
                    LEFT JOIN master_parts mp ON (mp.id = s.part_id)
                    LEFT JOIN master_models mm ON (mm.id = mp.model_id)
                    WHERE ngr.inspection_session_id IN ({$inSessIds})
                      AND (ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)
                    GROUP BY model_name
                    HAVING total_qty > 0
                    ORDER BY total_qty DESC
                    LIMIT 10
                ");
                $stmtM->execute();
                $dailyModelRows = $stmtM->fetchAll(PDO::FETCH_ASSOC);

                // 3. Top Parts (NG Pcs)
                $stmtP = $pdo->prepare("
                    SELECT COALESCE(mp.part_name, did.part_name, ki.item_description, 'Unknown') AS part_name,
                           COALESCE(mp.part_code, did.part_code, ki.item_code, '-') AS part_code,
                           SUM(ngr.qty_ng) AS total_qty
                    FROM inspection_ng_records ngr
                    JOIN inspection_sessions s ON s.id = ngr.inspection_session_id
                    LEFT JOIN daily_inspection_data did ON did.id = s.did_id
                    LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
                    LEFT JOIN master_parts mp ON (mp.id = s.part_id OR UPPER(mp.part_code) = UPPER(COALESCE(did.part_code, ki.item_code)))
                    WHERE ngr.inspection_session_id IN ({$inSessIds})
                      AND (ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)
                    GROUP BY s.part_id, part_name, part_code
                    HAVING total_qty > 0
                    ORDER BY total_qty DESC
                    LIMIT 10
                ");
                $stmtP->execute();
                $dailyPartRows = $stmtP->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                error_log("Daily detail donut charts query error: " . $e->getMessage());
            }

            // ── C3. Fetch Reinspection Stats (Workload) ───────────────────
            $reinspectBySession = [];
            $stmtReinspect = $pdo->query("
                SELECT 
                    sub.original_session_id,
                    COUNT(*) AS reinspect_count,
                    SUM(COALESCE(isl.sample_size, 0)) AS reinspect_sample_sum
                FROM lot_substitution_log sub
                LEFT JOIN inspection_session_lots isl ON isl.id = sub.ng_session_lot_id
                WHERE sub.original_session_id IN ({$inSessIds})
                  AND sub.action_type = 'sort_reinspect'
                GROUP BY sub.original_session_id
            ");
            if ($stmtReinspect) {
                while ($rRow = $stmtReinspect->fetch(PDO::FETCH_ASSOC)) {
                    $reinspectBySession[(int)$rRow['original_session_id']] = [
                        'count'   => (int)$rRow['reinspect_count'],
                        'samples' => (int)$rRow['reinspect_sample_sum']
                    ];
                }
            }

            // ── D. Build Chronological Session Timeline (Mode B) ───────────
            foreach ($rawSessions as $sess) {
                $sId = (int)$sess['session_id'];
                $sessLots = $allLotsMap[$sId] ?? [];
                $sessNgs  = $allNgMap[$sId] ?? [];

                $rInfo = $reinspectBySession[$sId] ?? ['count' => 0, 'samples' => 0];
                $extraLots    = (int)$rInfo['count'];
                $extraSamples = (int)$rInfo['samples'];

                $sessTotalQty = 0;
                $sessEffectiveSample = 0;
                foreach ($sessLots as $sl) {
                    if (empty($sl['is_reinspected_attempt'])) {
                        $sessTotalQty += (int)$sl['qty'];
                    }
                    $sessEffectiveSample += (int)($sl['computed_sample_size'] ?? 0);
                }
                if ($sessTotalQty === 0) {
                    $sessTotalQty = (int)$sess['total_scanned_qty'];
                }
                if ($sessEffectiveSample === 0) {
                    $sessEffectiveSample = (int)($sess['sample_size'] ?? 0) + $extraSamples;
                }
                $boxCount = !empty($sessLots) ? count($sessLots) : (1 + $extraLots);

                $sessNgCount = 0;
                foreach ($sessNgs as $sn) {
                    $sessNgCount += (int)($sn['qty_ng'] ?? 1);
                }
                if ($sessNgCount === 0) {
                    $sessNgCount = (int)$sess['ng_count'];
                }

                $timelineItem = $sess;
                $timelineItem['lots'] = $sessLots;
                $timelineItem['ng_records'] = $sessNgs;
                $timelineItem['unmatched_defects'] = $allUnmatchedNgMap[$sId] ?? [];
                $timelineItem['computed_qty'] = $sessTotalQty;
                $timelineItem['box_count'] = $boxCount;
                $timelineItem['effective_sample_size'] = $sessEffectiveSample;
                $timelineItem['reinspect_count'] = $extraLots;
                $timelineItem['reinspect_samples'] = $extraSamples;

                $sessionTimeline[] = $timelineItem;

                // KPI Counter
                $daySummary['total_sesi']++;
                $daySummary['total_label']    += $boxCount;
                $daySummary['total_sampling'] += $sessEffectiveSample;
                $daySummary['total_qty']      += $sessTotalQty;
                $daySummary['total_ng']       += $sessNgCount;
                if ($sess['session_status'] === 'passed') {
                    $daySummary['passed_sesi']++;
                } elseif ($sess['session_status'] === 'rejected') {
                    $daySummary['rejected_sesi']++;
                }

                // Approval Counter
                if (!empty($sess['is_approved']) && (int)$sess['is_approved'] === 1) {
                    $daySummary['total_approved_sessions']++;
                } else {
                    $daySummary['total_pending_sessions']++;
                }
            }

            $daySummary['is_all_approved'] = ($daySummary['total_sesi'] > 0 && $daySummary['total_pending_sessions'] === 0);

            // ── E. Build Grouped Kanban & Safety Stock Trees (Mode A) ───────
            foreach ($rawSessions as $sess) {
                $sId = (int)$sess['session_id'];
                $sessLots = $allLotsMap[$sId] ?? [];
                $sessNgs  = $allNgMap[$sId] ?? [];

                $rInfo = $reinspectBySession[$sId] ?? ['count' => 0, 'samples' => 0];
                $extraLots = (int)$rInfo['count'];
                $extraSamples = (int)$rInfo['samples'];

                $sessTotalQty = 0;
                $sessEffectiveSample = 0;
                foreach ($sessLots as $sl) {
                    if (empty($sl['is_reinspected_attempt'])) {
                        $sessTotalQty += (int)$sl['qty'];
                    }
                    $sessEffectiveSample += (int)($sl['computed_sample_size'] ?? 0);
                }
                if ($sessTotalQty === 0) {
                    $sessTotalQty = (int)$sess['total_scanned_qty'];
                }
                if ($sessEffectiveSample === 0) {
                    $sessEffectiveSample = (int)($sess['sample_size'] ?? 0) + $extraSamples;
                }
                $boxCount = !empty($sessLots) ? count($sessLots) : (1 + $extraLots);

                $sessNgCount = 0;
                foreach ($sessNgs as $sn) {
                    $sessNgCount += (int)($sn['qty_ng'] ?? 1);
                }
                if ($sessNgCount === 0) {
                    $sessNgCount = (int)$sess['ng_count'];
                }

                $sessPayload = $sess;
                $sessPayload['lots'] = $sessLots;
                $sessPayload['ng_records'] = $sessNgs;
                $sessPayload['unmatched_defects'] = $allUnmatchedNgMap[$sId] ?? [];
                $sessPayload['computed_qty'] = $sessTotalQty;
                $sessPayload['box_count'] = $boxCount;
                $sessPayload['effective_sample_size'] = $sessEffectiveSample;
                $sessPayload['reinspect_count'] = $extraLots;
                $sessPayload['reinspect_samples'] = $extraSamples;

                if ($sess['inspection_type'] === 'kanban') {
                    $groupKey = 'KB_' . ((int)$sess['kanban_item_id'] > 0 ? $sess['kanban_item_id'] : ($sess['part_code'] . '_' . $sess['kanban_no']));

                    if (!isset($kanbanGroups[$groupKey])) {
                        $kanbanGroups[$groupKey] = [
                            'item_key'     => $groupKey,
                            'type'         => 'kanban',
                            'kanban_no'    => $sess['kanban_no'],
                            'customer'     => $sess['customer'],
                            'part_code'    => $sess['part_code'],
                            'part_name'    => $sess['part_name'],
                            'target_qty'   => (int)$sess['kanban_qty'],
                            'total_qty'    => 0,
                            'total_boxes'  => 0,
                            'total_ng'     => 0,
                            'sessions'     => [],
                            'final_status' => 'passed'
                        ];
                    }

                    $kanbanGroups[$groupKey]['sessions'][] = $sessPayload;
                    $kanbanGroups[$groupKey]['total_qty']   += $sessTotalQty;
                    $kanbanGroups[$groupKey]['total_boxes'] += $boxCount;
                    $kanbanGroups[$groupKey]['total_ng']    += $sessNgCount;

                } else {
                    // Safety Stock Grouping
                    $groupKey = 'SS_' . strtoupper($sess['part_code']) . '___' . strtoupper($sess['did_lot_number'] ?: 'NOLOT');

                    if (!isset($ssGroups[$groupKey])) {
                        $ssGroups[$groupKey] = [
                            'item_key'     => $groupKey,
                            'type'         => 'safety_stock',
                            'part_code'    => $sess['part_code'],
                            'part_name'    => $sess['part_name'],
                            'lot_number'   => $sess['did_lot_number'] ?: '-',
                            'cavity'       => $sess['did_cavity'] ?: '-',
                            'target_qty'   => 0,
                            'total_qty'    => 0,
                            'total_boxes'  => 0,
                            'total_ng'     => 0,
                            'sessions'     => [],
                            'final_status' => 'passed'
                        ];
                    }

                    $ssGroups[$groupKey]['sessions'][] = $sessPayload;
                    $ssGroups[$groupKey]['total_qty']   += $sessTotalQty;
                    $ssGroups[$groupKey]['total_boxes'] += $boxCount;
                    $ssGroups[$groupKey]['total_ng']    += $sessNgCount;
                }
            }

            // Post-Process Mode A: Tag Session Labels (Cicilan vs Re-inspeksi) & Final Status
            foreach ($kanbanGroups as &$kb) {
                $sessCount = count($kb['sessions']);
                $hasReject = false;
                $hasInProgress = false;
                $passedQty = 0;

                foreach ($kb['sessions'] as $idx => &$sItem) {
                    $isReinspect = ((int)$sItem['is_reinspection'] === 1 || (int)$sItem['parent_session_id'] > 0);
                    if ($isReinspect) {
                        $sItem['session_badge_type'] = 'reinspection';
                        $sItem['session_badge_text'] = 'Re-inspeksi (Setelah Reject)';
                    } elseif ($sessCount > 1) {
                        $sItem['session_badge_type'] = 'partial';
                        $sItem['session_badge_text'] = 'Parsial #' . ($idx + 1);
                    } else {
                        $sItem['session_badge_type'] = 'normal';
                        $sItem['session_badge_text'] = 'Inspeksi Lengkap';
                    }

                    if ($sItem['session_status'] === 'in_progress') {
                        $hasInProgress = true;
                    } elseif ($sItem['session_status'] === 'rejected') {
                        $hasReject = true;
                    } elseif ($sItem['session_status'] === 'passed') {
                        $passedQty += (int)$sItem['computed_qty'];
                    }
                }
                unset($sItem);

                $kb['passed_qty'] = $passedQty;

                // Determine final item status
                $latestSess = end($kb['sessions']);
                if ($hasInProgress) {
                    $kb['final_status'] = 'in_progress';
                } elseif ($latestSess['session_status'] === 'rejected') {
                    $kb['final_status'] = 'rejected';
                } elseif ($kb['target_qty'] > 0 && $passedQty < $kb['target_qty']) {
                    $kb['final_status'] = 'partial';
                } else {
                    $kb['final_status'] = 'passed';
                }
            }
            unset($kb);

            foreach ($ssGroups as &$ss) {
                $sessCount = count($ss['sessions']);
                $hasInProgress = false;
                $ssPassedQty = 0;
                foreach ($ss['sessions'] as $idx => &$sItem) {
                    $isReinspect = ((int)$sItem['is_reinspection'] === 1 || (int)$sItem['parent_session_id'] > 0);
                    if ($isReinspect) {
                        $sItem['session_badge_type'] = 'reinspection';
                        $sItem['session_badge_text'] = 'Re-inspeksi (Sortir Ulang)';
                    } elseif ($sessCount > 1) {
                        $sItem['session_badge_type'] = 'partial';
                        $sItem['session_badge_text'] = 'Batch #' . ($idx + 1);
                    } else {
                        $sItem['session_badge_type'] = 'normal';
                        $sItem['session_badge_text'] = 'Inspeksi Standar';
                    }

                    if ($sItem['session_status'] === 'in_progress') {
                        $hasInProgress = true;
                    } elseif ($sItem['session_status'] === 'passed') {
                        $ssPassedQty += (int)$sItem['computed_qty'];
                    }
                }
                unset($sItem);

                $ss['passed_qty'] = $ssPassedQty;

                $latestSess = end($ss['sessions']);
                if ($hasInProgress) {
                    $ss['final_status'] = 'in_progress';
                } else {
                    $ss['final_status'] = $latestSess['session_status'];
                }
            }
            unset($ss);

            $daySummary['total_kanban_items'] = count($kanbanGroups);
            $daySummary['total_ss_items']     = count($ssGroups);

            // First Pass Yield calculation
            $firstSessionPass = 0;
            $allFirstSessions = array_merge(
                array_map(fn($g) => $g['sessions'][0]['session_status'] ?? '', $kanbanGroups),
                array_map(fn($g) => $g['sessions'][0]['session_status'] ?? '', $ssGroups)
            );
            $totalFirstSessions = count($allFirstSessions);
            if ($totalFirstSessions > 0) {
                foreach ($allFirstSessions as $st) {
                    if ($st === 'passed') $firstSessionPass++;
                }
                $daySummary['first_pass_yield'] = round(($firstSessionPass / $totalFirstSessions) * 100, 1);
            }
        }

    } catch (PDOException $e) {
        set_flash('error', 'Gagal memuat rincian inspeksi: ' . $e->getMessage());
    }
}

// Color palettes & JSON datasets for Daily Donut Charts
$DAILY_DEFECT_PALETTE = ['#ef4444', '#f97316', '#f59e0b', '#8b5cf6', '#ec4899', '#06b6d4', '#10b981', '#3b82f6', '#64748b', '#a855f7'];
$DAILY_MODEL_PALETTE  = ['#6366f1', '#8b5cf6', '#3b82f6', '#0284c7', '#a855f7', '#0ea5e9', '#ec4899', '#f59e0b', '#10b981', '#64748b'];
$DAILY_PART_PALETTE   = ['#0d9488', '#10b981', '#06b6d4', '#14b8a6', '#059669', '#3b82f6', '#8b5cf6', '#f59e0b', '#ef4444', '#64748b'];

$jsonDailyDefects = json_encode($dailyDefectRows ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$jsonDailyModels  = json_encode($dailyModelRows ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$jsonDailyParts   = json_encode($dailyPartRows ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$jsonDefectColors = json_encode($DAILY_DEFECT_PALETTE);
$jsonModelColors  = json_encode($DAILY_MODEL_PALETTE);
$jsonPartColors   = json_encode($DAILY_PART_PALETTE);

$pageTitle = "Detail Review Inspeksi " . date('d M Y', strtotime($dateParam));

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300">

    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-5 space-y-4">

        <?= render_flash() ?>

        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <!-- 1. EXECUTIVE KPI BANNER RIBBON -->
        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <div style="background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%); border-radius: 16px; padding: 18px 22px; color: #fff; box-shadow: 0 10px 25px -5px rgba(15,23,42,0.25);">
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 14px;">
                    <div style="background: rgba(59,130,246,0.25); border: 1px solid rgba(59,130,246,0.4); padding: 12px; border-radius: 14px; flex-shrink: 0;">
                        <svg style="width: 24px; height: 24px; color: #93c5fd;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                        </svg>
                    </div>
                    <div>
                        <div style="font-size: 11px; font-weight: 800; color: #93c5fd; text-transform: uppercase; letter-spacing: 0.8px;">Supervisor Inspection Review</div>
                        <h1 style="font-size: 18px; font-weight: 900; margin: 2px 0 0 0; line-height: 1.2;">Laporan Rincian Inspeksi Harian</h1>
                        <p style="font-size: 12px; color: #cbd5e1; font-weight: 600; margin: 3px 0 0 0;">
                            <?= date('l, d F Y', strtotime($dateParam)) ?>
                        </p>
                    </div>
                </div>

                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <?php if ($daySummary['total_sesi'] > 0): ?>
                        <?php if ($daySummary['is_all_approved']): ?>
                            <span style="background: rgba(16,185,129,0.2); border: 1px solid rgba(16,185,129,0.4); color: #6ee7b7; border-radius: 10px; padding: 8px 14px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;">
                                <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                                </svg>
                                Seluruh Sesi Telah Disetujui
                            </span>
                        <?php else: ?>
                            <button type="button" onclick="confirmApproveAll('<?= htmlspecialchars($dateParam) ?>', <?= (int)$daySummary['total_pending_sessions'] ?>)"
                                    style="background: #2563eb; border: 1px solid #3b82f6; color: #fff; border-radius: 10px; padding: 8px 16px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; transition: all 0.2s;"
                                    class="hover:bg-blue-700 shadow-xs"
                                    title="Berikan persetujuan supervisor sekaligus untuk seluruh sesi inspeksi tanggal ini">
                                <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                Setujui Semua Sesi (ACC All)
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>

                    <a href="<?= base_url('modules/daily_report/export_excel.php?date=' . urlencode($dateParam)) ?>" 
                       style="background: #059669; border: 1px solid #10b981; color: #fff; border-radius: 10px; padding: 8px 16px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; transition: all 0.2s;"
                       class="hover:bg-emerald-700 shadow-xs"
                       title="Export Laporan Rincian Inspeksi Harian ke Excel (.xlsx)">
                        <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        Export Excel (.xlsx)
                    </a>
                    <a href="<?= base_url('modules/daily_report/index.php') ?>" style="background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.2); color: #fff; border-radius: 10px; padding: 8px 16px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; transition: all 0.2s;">
                        <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                        </svg>
                        Kembali ke Ringkasan
                    </a>
                </div>
            </div>

            <!-- 5 KPI Cards Metric Grid -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px; margin-top: 18px;">
                <div style="background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.12); border-radius: 12px; padding: 12px 14px;">
                    <div style="font-size: 10px; color: #94a3b8; font-weight: 700; text-transform: uppercase;">Total Sesi Inspeksi</div>
                    <div style="font-size: 22px; font-weight: 900; color: #fff; margin-top: 2px; font-family: monospace;">
                        <?= $daySummary['total_sesi'] ?> <span style="font-size: 11px; font-weight: 600; color: #94a3b8;">sesi</span>
                    </div>
                    <div style="font-size: 10px; color: #38bdf8; font-weight: 700; margin-top: 2px;">
                        <?= $daySummary['passed_sesi'] ?> Pass · <?= $daySummary['rejected_sesi'] ?> Reject
                    </div>
                </div>

                <div style="background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.12); border-radius: 12px; padding: 12px 14px;">
                    <div style="font-size: 10px; color: #94a3b8; font-weight: 700; text-transform: uppercase;">Kanban / SS Item</div>
                    <div style="font-size: 22px; font-weight: 900; color: #fff; margin-top: 2px; font-family: monospace;">
                        <?= $daySummary['total_kanban_items'] + $daySummary['total_ss_items'] ?> <span style="font-size: 11px; font-weight: 600; color: #94a3b8;">item</span>
                    </div>
                    <div style="font-size: 10px; color: #c4b5fd; font-weight: 700; margin-top: 2px;">
                        <?= $daySummary['total_kanban_items'] ?> Kanban · <?= $daySummary['total_ss_items'] ?> Safety Stock
                    </div>
                </div>

                <div style="background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.12); border-radius: 12px; padding: 12px 14px;">
                    <div style="font-size: 10px; color: #94a3b8; font-weight: 700; text-transform: uppercase;">Total Sampling</div>
                    <div style="font-size: 22px; font-weight: 900; color: #fff; margin-top: 2px; font-family: monospace;">
                        <?= number_format($daySummary['total_sampling']) ?> <span style="font-size: 11px; font-weight: 600; color: #94a3b8;">pcs</span>
                    </div>
                    <div style="font-size: 10px; color: #94a3b8; font-weight: 600; margin-top: 2px;">
                        Dari <?= number_format($daySummary['total_label']) ?> box label
                    </div>
                </div>

                <div style="background: <?= $daySummary['total_ng'] > 0 ? 'rgba(239,68,68,0.22)' : 'rgba(255,255,255,0.08)' ?>; border: 1px solid <?= $daySummary['total_ng'] > 0 ? 'rgba(239,68,68,0.4)' : 'rgba(255,255,255,0.12)' ?>; border-radius: 12px; padding: 12px 14px;">
                    <div style="font-size: 10px; color: <?= $daySummary['total_ng'] > 0 ? '#fca5a5' : '#94a3b8' ?>; font-weight: 700; text-transform: uppercase;">Temuan Cacat (NG)</div>
                    <div style="font-size: 22px; font-weight: 900; color: <?= $daySummary['total_ng'] > 0 ? '#fca5a5' : '#fff' ?>; margin-top: 2px; font-family: monospace;">
                        <?= number_format($daySummary['total_ng']) ?> <span style="font-size: 11px; font-weight: 600;"><?= $daySummary['total_ng'] === 0 ? 'All OK' : 'pcs NG' ?></span>
                    </div>
                    <div style="font-size: 10px; color: <?= $daySummary['total_ng'] > 0 ? '#fecdd3' : '#4ade80' ?>; font-weight: 700; margin-top: 2px;">
                        <?= $daySummary['total_ng'] > 0 ? 'Cacat terdeteksi' : 'Kualitas produksi prima' ?>
                    </div>
                </div>

                <!-- Kartu KPI 5: Persetujuan Supervisor -->
                <div style="background: <?= ($daySummary['total_sesi'] > 0 && $daySummary['is_all_approved']) ? 'rgba(16,185,129,0.22)' : 'rgba(245,158,11,0.22)' ?>; border: 1px solid <?= ($daySummary['total_sesi'] > 0 && $daySummary['is_all_approved']) ? 'rgba(16,185,129,0.4)' : 'rgba(245,158,11,0.4)' ?>; border-radius: 12px; padding: 12px 14px;">
                    <div style="font-size: 10px; color: <?= ($daySummary['total_sesi'] > 0 && $daySummary['is_all_approved']) ? '#a7f3d0' : '#fde68a' ?>; font-weight: 700; text-transform: uppercase;">Persetujuan Supervisor</div>
                    <div style="font-size: 22px; font-weight: 900; color: #fff; margin-top: 2px; font-family: monospace;">
                        <?= $daySummary['total_approved_sessions'] ?> / <?= $daySummary['total_sesi'] ?> <span style="font-size: 11px; font-weight: 600;">Sesi</span>
                    </div>
                    <div style="font-size: 10px; color: <?= ($daySummary['total_sesi'] > 0 && $daySummary['is_all_approved']) ? '#6ee7b7' : '#fcd34d' ?>; font-weight: 700; margin-top: 2px;">
                        <?php if ($daySummary['total_sesi'] === 0): ?>
                            Tidak ada aktivitas
                        <?php elseif ($daySummary['is_all_approved']): ?>
                            Lengkap Disetujui (100%)
                        <?php else: ?>
                            <?= $daySummary['total_pending_sessions'] ?> Sesi Menunggu ACC
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($daySummary['total_sesi'] > 0 && !$daySummary['is_all_approved']): ?>
            <!-- Banner Peringatan Formal: Sesi Belum di-ACC -->
            <div style="background: #fffbeb; border: 1px solid #fde68a; border-left: 4px solid #f59e0b; border-radius: 10px; padding: 12px 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="width: 32px; height: 32px; border-radius: 8px; background: #fef3c7; color: #d97706; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                        <svg style="width: 18px; height: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                    </div>
                    <div>
                        <div style="font-size: 13px; font-weight: 800; color: #92400e;">Pemberitahuan: Persetujuan Supervisor Belum Lengkap</div>
                        <div style="font-size: 11px; color: #b45309; margin-top: 2px; line-height: 1.4;">
                            Terdapat <strong><?= $daySummary['total_pending_sessions'] ?> dari <?= $daySummary['total_sesi'] ?> sesi inspeksi</strong> pada tanggal ini yang belum mendapatkan persetujuan resmi (ACC) dari Supervisor. Mohon lakukan verifikasi hasil pemeriksaan sebelum menyetujui.
                        </div>
                    </div>
                </div>
                <div>
                    <button type="button" onclick="confirmApproveAll('<?= htmlspecialchars($dateParam) ?>', <?= (int)$daySummary['total_pending_sessions'] ?>)"
                            style="background: #d97706; border: 1px solid #b45309; color: #fff; border-radius: 8px; padding: 7px 14px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; transition: all 0.2s;"
                            class="hover:bg-amber-700 shadow-xs">
                        <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Setujui Seluruh Sesi Sekarang
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <!-- 1B. DONUT CHARTS: TOP DEFECT, MODEL & PART (DAILY BREAKDOWN) -->
        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <div class="space-y-2">
            <!-- Filter Bar: 5 Besar vs 10 Besar -->
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; padding: 2px 4px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="font-size: 11px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 0.6px;">Distribusi Cacat Harian</span>
                    <span style="font-size: 10px; color: #94a3b8; font-weight: 600;">(Berdasarkan temuan tanggal <?= date('d M Y', strtotime($dateParam)) ?>)</span>
                </div>
                <div style="display: inline-flex; align-items: center; background: #f1f5f9; padding: 3px; border-radius: 10px; border: 1px solid #e2e8f0; gap: 3px;">
                    <button type="button" id="btn-daily-rank-5" onclick="switchDailyTopRank(5)"
                            style="padding: 4px 12px; font-size: 11px; font-weight: 800; border-radius: 7px; border: none; cursor: pointer; transition: all 0.2s;"
                            class="bg-blue-600 text-white shadow-xs">
                        5 Besar
                    </button>
                    <button type="button" id="btn-daily-rank-10" onclick="switchDailyTopRank(10)"
                            style="padding: 4px 12px; font-size: 11px; font-weight: 700; border-radius: 7px; border: none; cursor: pointer; color: #64748b; background: transparent; transition: all 0.2s;"
                            class="hover:text-slate-900 hover:bg-slate-200/60">
                        10 Besar
                    </button>
                </div>
            </div>

            <!-- 3 Columns Donut Grid -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 12px;" id="daily-donut-grid">
                <!-- Card 1: Top N Jenis Defect -->
                <div class="card bg-white rounded-xl border border-slate-200/80 shadow-xs" style="padding: 14px 16px; display: flex; flex-direction: column; border-top: 3.5px solid #ef4444;">
                    <div style="flex-shrink: 0; margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                        <div style="width: 28px; height: 28px; border-radius: 8px; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/>
                                <line x1="12" y1="9" x2="12" y2="13"/>
                                <line x1="12" y1="17" x2="12.01" y2="17"/>
                            </svg>
                        </div>
                        <div>
                            <div id="title-card-defect" style="font-size: 13px; font-weight: 800; color: #1e293b;">Top 5 Jenis Defect</div>
                            <div style="font-size: 10px; color: #94a3b8;">Distribusi kuantitas cacat (pcs)</div>
                        </div>
                    </div>
                    <div id="container-donut-defect" style="display: flex; gap: 12px; align-items: center; min-height: 160px;">
                        <div style="width: 130px; height: 130px; flex-shrink: 0; position: relative; display: flex; align-items: center; justify-content: center;">
                            <canvas id="chartDailyDefect" style="width: 100%; height: 100%;"></canvas>
                            <div style="position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; pointer-events: none; text-align: center;">
                                <span style="font-size: 8.5px; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.04em;">TOTAL NG</span>
                                <span id="center-val-defect" style="font-size: 15px; font-weight: 900; color: #0f172a; line-height: 1.1;">0</span>
                                <span style="font-size: 8.5px; font-weight: 700; color: #64748b;">pcs</span>
                            </div>
                        </div>
                        <div id="legend-list-defect" style="flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 5px; max-height: 160px; overflow-y: auto;">
                            <!-- Populated via JS -->
                        </div>
                    </div>
                    <div id="empty-donut-defect" style="display: none; height: 160px; align-items: center; justify-content: center; color: #94a3b8; font-size: 11px; font-weight: 600; background: #f8fafc; border-radius: 8px; border: 1px dashed #e2e8f0;">
                        Tidak ada temuan cacat pada tanggal ini
                    </div>
                </div>

                <!-- Card 2: Top N Model (NG) -->
                <div class="card bg-white rounded-xl border border-slate-200/80 shadow-xs" style="padding: 14px 16px; display: flex; flex-direction: column; border-top: 3.5px solid #6366f1;">
                    <div style="flex-shrink: 0; margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                        <div style="width: 28px; height: 28px; border-radius: 8px; background: #e0e7ff; color: #4f46e5; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/>
                                <path d="m3.3 7 8.7 5 8.7-5"/>
                                <path d="M12 22V12"/>
                            </svg>
                        </div>
                        <div>
                            <div id="title-card-model" style="font-size: 13px; font-weight: 800; color: #1e293b;">Top 5 Model (NG)</div>
                            <div style="font-size: 10px; color: #94a3b8;">Proporsi berdasarkan model produk (pcs)</div>
                        </div>
                    </div>
                    <div id="container-donut-model" style="display: flex; gap: 12px; align-items: center; min-height: 160px;">
                        <div style="width: 130px; height: 130px; flex-shrink: 0; position: relative; display: flex; align-items: center; justify-content: center;">
                            <canvas id="chartDailyModel" style="width: 100%; height: 100%;"></canvas>
                            <div style="position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; pointer-events: none; text-align: center;">
                                <span style="font-size: 8.5px; font-weight: 800; color: #6366f1; text-transform: uppercase; letter-spacing: 0.04em;">MODEL</span>
                                <span id="center-val-model" style="font-size: 15px; font-weight: 900; color: #1e1b4b; line-height: 1.1;">0</span>
                                <span style="font-size: 8.5px; font-weight: 700; color: #64748b;">tipe</span>
                            </div>
                        </div>
                        <div id="legend-list-model" style="flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 5px; max-height: 160px; overflow-y: auto;">
                            <!-- Populated via JS -->
                        </div>
                    </div>
                    <div id="empty-donut-model" style="display: none; height: 160px; align-items: center; justify-content: center; color: #94a3b8; font-size: 11px; font-weight: 600; background: #f8fafc; border-radius: 8px; border: 1px dashed #e2e8f0;">
                        Tidak ada data model pada tanggal ini
                    </div>
                </div>

                <!-- Card 3: Top N Part (NG) -->
                <div class="card bg-white rounded-xl border border-slate-200/80 shadow-xs" style="padding: 14px 16px; display: flex; flex-direction: column; border-top: 3.5px solid #0d9488;">
                    <div style="flex-shrink: 0; margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                        <div style="width: 28px; height: 28px; border-radius: 8px; background: #ccfbf1; color: #0d9488; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <polygon points="12 2 2 7 12 12 22 7 12 2"/>
                                <polyline points="2 17 12 22 22 17"/>
                                <polyline points="2 12 12 17 22 12"/>
                            </svg>
                        </div>
                        <div>
                            <div id="title-card-part" style="font-size: 13px; font-weight: 800; color: #1e293b;">Top 5 Part (NG)</div>
                            <div style="font-size: 10px; color: #94a3b8;">Proporsi berdasarkan part (pcs)</div>
                        </div>
                    </div>
                    <div id="container-donut-part" style="display: flex; gap: 12px; align-items: center; min-height: 160px;">
                        <div style="width: 130px; height: 130px; flex-shrink: 0; position: relative; display: flex; align-items: center; justify-content: center;">
                            <canvas id="chartDailyPart" style="width: 100%; height: 100%;"></canvas>
                            <div style="position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; pointer-events: none; text-align: center;">
                                <span style="font-size: 8.5px; font-weight: 800; color: #0d9488; text-transform: uppercase; letter-spacing: 0.04em;">PART</span>
                                <span id="center-val-part" style="font-size: 15px; font-weight: 900; color: #134e4a; line-height: 1.1;">0</span>
                                <span style="font-size: 8.5px; font-weight: 700; color: #64748b;">item</span>
                            </div>
                        </div>
                        <div id="legend-list-part" style="flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 5px; max-height: 160px; overflow-y: auto;">
                            <!-- Populated via JS -->
                        </div>
                    </div>
                    <div id="empty-donut-part" style="display: none; height: 160px; align-items: center; justify-content: center; color: #94a3b8; font-size: 11px; font-weight: 600; background: #f8fafc; border-radius: 8px; border: 1px dashed #e2e8f0;">
                        Tidak ada data part pada tanggal ini
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <!-- 2. CONTROL BAR: DUAL VIEW MODE SWITCHER & GLOBAL CONTROLS -->
        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <div class="card p-3 bg-white border border-slate-200/80 rounded-xl shadow-xs">
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                
                <!-- Dual-View Pill Buttons -->
                <div style="display: inline-flex; align-items: center; background: #f1f5f9; padding: 4px; border-radius: 12px; border: 1px solid #e2e8f0; gap: 4px;">
                    <button type="button" id="btn-view-kanban" onclick="switchReviewMode('kanban')"
                            style="padding: 7px 16px; font-size: 12px; font-weight: 800; border-radius: 9px; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 7px; transition: all 0.2s;"
                            class="active-tab-btn bg-blue-600 text-white shadow-xs">
                        <svg style="width: 15px; height: 15px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                        </svg>
                        Berdasarkan Kanban & Part
                        <span style="background: rgba(255,255,255,0.25); color: #fff; padding: 1px 7px; border-radius: 9999px; font-size: 10px;">
                            <?= count($kanbanGroups) + count($ssGroups) ?>
                        </span>
                    </button>

                    <button type="button" id="btn-view-session" onclick="switchReviewMode('session')"
                            style="padding: 7px 16px; font-size: 12px; font-weight: 800; border-radius: 9px; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 7px; transition: all 0.2s;"
                            class="inactive-tab-btn text-slate-600 hover:text-slate-900 hover:bg-slate-200/70">
                        <svg style="width: 15px; height: 15px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Berdasarkan Sesi Inspeksi
                        <span style="background: #e2e8f0; color: #475569; padding: 1px 7px; border-radius: 9999px; font-size: 10px;">
                            <?= count($sessionTimeline) ?> Sesi
                        </span>
                    </button>
                </div>

                <!-- Search Input & Global Expand/Collapse -->
                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <div style="position: relative; width: 280px; max-width: 100%;">
                        <svg style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 14px; height: 14px; color: #94a3b8; pointer-events: none;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        <input type="text" id="review-search" placeholder="Cari Kanban, Part, Lot, Ref, Inspector..."
                               style="width: 100%; padding: 7px 10px 7px 32px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 9px; font-size: 12px; color: #1e293b; font-weight: 600; outline: none;">
                    </div>

                    <button type="button" onclick="toggleAllAccordions()" id="btn-toggle-all"
                            style="padding: 7px 13px; font-size: 11px; font-weight: 700; color: #334155; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 9px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px;">
                        <svg style="width: 13px; height: 13px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                        <span id="txt-toggle-all">Expand All</span>
                    </button>
                </div>
            </div>
        </div>

        <?php if (empty($rawSessions)): ?>
            <!-- EMPTY STATE -->
            <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 60px 24px; text-align: center; box-shadow: 0 1px 4px rgba(0,0,0,0.03);">
                <div style="display: flex; flex-direction: column; align-items: center; gap: 12px;">
                    <div style="background: #f1f5f9; padding: 16px; border-radius: 9999px;">
                        <svg style="width: 48px; height: 48px; color: #94a3b8;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                        </svg>
                    </div>
                    <span style="font-weight: 800; color: #0f172a; font-size: 16px;">Tidak Ada Aktivitas Inspeksi</span>
                    <span style="font-size: 12px; color: #64748b; max-width: 420px; line-height: 1.5;">
                        Tidak ditemukan catatan sesi inspeksi OQC yang berjalan atau diinput pada tanggal <b><?= date('d M Y', strtotime($dateParam)) ?></b>.
                    </span>
                    <a href="<?= base_url('modules/daily_report/index.php') ?>" style="padding: 9px 20px; background: #2563eb; color: #fff; font-weight: 700; font-size: 12px; border-radius: 10px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; margin-top: 6px;">
                        Kembali ke Laporan Harian
                    </a>
                </div>
            </div>
        <?php else: ?>

            <!-- ═══════════════════════════════════════════════════════════════ -->
            <!-- 3. VIEW MODE A: GROUPED BY KANBAN & SAFETY STOCK -->
            <!-- ═══════════════════════════════════════════════════════════════ -->
            <div id="container-view-kanban" class="space-y-4">

                <!-- A1. KANBAN DELIVERIES SECTION -->
                <?php if (!empty($kanbanGroups)): ?>
                <div style="background: #fff; border: 1px solid #c4b5fd; border-radius: 14px; overflow: hidden; box-shadow: 0 4px 14px rgba(124,58,237,0.06);">
                    <div style="padding: 12px 18px; background: #faf5ff; border-bottom: 1px solid #ede9fe; display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span style="background: #7c3aed; color: #fff; width: 28px; height: 28px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center;"><svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg></span>
                            <div>
                                <h2 style="font-size: 13px; font-weight: 900; color: #581c87; margin: 0;">Jalur Pengiriman Kanban (Customer Orders)</h2>
                                <p style="font-size: 11px; color: #7e22ce; margin: 0; font-weight: 600;">Status pemenuhan kartu delivery kanban, akumulasi inspeksi parsial & re-inspeksi</p>
                            </div>
                        </div>
                        <span style="font-size: 11px; font-weight: 800; background: #ede9fe; color: #6b21a8; border: 1px solid #ddd6fe; padding: 3px 12px; border-radius: 9999px;">
                            <?= count($kanbanGroups) ?> Kartu Kanban
                        </span>
                    </div>

                    <div style="overflow-x: auto;">
                        <table class="w-full text-left" style="border-collapse: collapse; font-size: 12px; min-width: 880px;">
                            <thead style="background: #fdfcff; border-bottom: 2px solid #ede9fe; font-size: 10px; font-weight: 800; color: #6b21a8; text-transform: uppercase; letter-spacing: 0.5px;">
                                <tr>
                                    <th style="padding: 11px 16px; width: 40px; text-align: center;">No</th>
                                    <th style="padding: 11px 16px; min-width: 240px;">Identitas Kanban & Part</th>
                                    <th style="padding: 11px 16px; min-width: 140px;">Customer</th>
                                    <th style="padding: 11px 16px; text-align: center; width: 150px;">Realisasi vs Target</th>
                                    <th style="padding: 11px 16px; text-align: center; width: 140px;">Riwayat Inspeksi</th>
                                    <th style="padding: 11px 16px; text-align: center; width: 120px;">Status Akhir</th>
                                    <th style="padding: 11px 16px; text-align: center; width: 110px;">Rincian</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $kIdx = 1;
                                foreach ($kanbanGroups as $kgKey => $kg): 
                                    $targetQ = (int)$kg['target_qty'];
                                    $realQ   = (int)($kg['passed_qty'] ?? 0);
                                    $pct     = $targetQ > 0 ? min(100, round(($realQ / $targetQ) * 100)) : 100;
                                    $sessCnt = count($kg['sessions']);
                                ?>
                                <!-- Level 1: Kanban Master Row -->
                                <tr class="review-row hover:bg-purple-50/40 transition-colors" style="border-bottom: 1px solid #f1f5f9; cursor: pointer;" onclick="toggleAccordion('acc-<?= $kgKey ?>')">
                                    <td style="padding: 12px 16px; text-align: center; font-weight: 800; color: #94a3b8;"><?= $kIdx++ ?></td>
                                    
                                    <td style="padding: 12px 16px;">
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <span style="font-family: monospace; font-weight: 900; font-size: 12px; color: #7c3aed; background: #f5f3ff; border: 1px solid #ddd6fe; padding: 2px 8px; border-radius: 6px;">
                                                <?= htmlspecialchars($kg['kanban_no']) ?>
                                            </span>
                                        </div>
                                        <div style="font-weight: 800; color: #0f172a; font-size: 12px; font-family: monospace; margin-top: 3px;">
                                            <?= htmlspecialchars($kg['part_code']) ?>
                                        </div>
                                        <div style="font-size: 11px; color: #64748b; font-weight: 600;">
                                            <?= htmlspecialchars($kg['part_name']) ?>
                                        </div>
                                    </td>

                                    <td style="padding: 12px 16px;">
                                        <div style="font-weight: 700; color: #334155; font-size: 12px;">
                                            <?= htmlspecialchars($kg['customer']) ?>
                                        </div>
                                    </td>

                                    <td style="padding: 12px 16px; text-align: center;">
                                        <div style="font-family: monospace; font-weight: 900; font-size: 13px; color: #0f172a;">
                                            <?= number_format($realQ) ?> / <?= number_format($targetQ) ?> <span style="font-size: 10px; color: #64748b;">pcs</span>
                                        </div>
                                        <!-- Progress Bar -->
                                        <div style="background: #e2e8f0; height: 5px; border-radius: 9999px; overflow: hidden; margin-top: 5px;">
                                            <div style="background: <?= $pct >= 100 ? '#10b981' : '#f59e0b' ?>; width: <?= $pct ?>%; height: 100%;"></div>
                                        </div>
                                        <span style="font-size: 10px; font-weight: 700; color: <?= $pct >= 100 ? '#059669' : '#d97706' ?>; margin-top: 2px; display: block;">
                                            <?= $pct ?>% Terpenuhi
                                        </span>
                                    </td>

                                    <td style="padding: 12px 16px; text-align: center;">
                                        <span style="display: inline-block; font-size: 11px; font-weight: 800; color: #475569; background: #f1f5f9; border: 1px solid #cbd5e1; padding: 2px 9px; border-radius: 6px;">
                                            <?= $sessCnt ?> Sesi
                                        </span>
                                        <?php if ($kg['total_ng'] > 0): ?>
                                            <div style="font-size: 10px; font-weight: 800; color: #e11d48; margin-top: 3px;">
                                                Total <?= $kg['total_ng'] ?> NG
                                            </div>
                                        <?php else: ?>
                                            <div style="font-size: 10px; font-weight: 700; color: #16a34a; margin-top: 3px;">
                                                Clean (0 NG)
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td style="padding: 12px 16px; text-align: center;">
                                        <?php if ($kg['final_status'] === 'in_progress'): ?>
                                            <span style="display: inline-flex; align-items: center; gap: 4px; background: #e0e7ff; color: #3730a3; border: 1px solid #c7d2fe; font-size: 11px; font-weight: 900; padding: 4px 10px; border-radius: 9999px;">
                                                IN PROGRESS
                                            </span>
                                        <?php elseif ($kg['final_status'] === 'passed'): ?>
                                            <span style="display: inline-flex; align-items: center; gap: 4px; background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; font-size: 11px; font-weight: 900; padding: 4px 10px; border-radius: 9999px;">
                                                PASSED
                                            </span>
                                        <?php elseif ($kg['final_status'] === 'rejected'): ?>
                                            <span style="display: inline-flex; align-items: center; gap: 4px; background: #ffe4e6; color: #be123c; border: 1px solid #fecdd3; font-size: 11px; font-weight: 900; padding: 4px 10px; border-radius: 9999px;">
                                                REJECTED
                                            </span>
                                        <?php else: ?>
                                            <span style="display: inline-flex; align-items: center; gap: 4px; background: #fef3c7; color: #92400e; border: 1px solid #fde68a; font-size: 11px; font-weight: 900; padding: 4px 10px; border-radius: 9999px;">
                                                PARTIAL
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td style="padding: 12px 16px; text-align: center;">
                                        <button type="button" class="btn-accordion-toggle" style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 5px 10px; font-size: 11px; font-weight: 700; color: #334155; display: inline-flex; align-items: center; gap: 4px;">
                                            <span class="btn-text">Rincian</span>
                                            <svg class="caret-icon" style="width: 12px; height: 12px; transition: transform 0.2s;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                        </button>
                                    </td>
                                </tr>

                                <!-- Level 2: Nested Sub-Sessions Accordion Container -->
                                <tr id="acc-<?= $kgKey ?>" class="accordion-sub-row hidden" style="background: #fafafa;">
                                    <td colspan="7" style="padding: 14px 18px 18px 24px; border-bottom: 2px solid #e2e8f0;">
                                        <div style="border-left: 3px solid #8b5cf6; padding-left: 16px;" class="space-y-4">
                                            <div style="font-size: 11px; font-weight: 800; color: #6b21a8; text-transform: uppercase; letter-spacing: 0.5px;">
                                                Daftar Sesi Inspeksi Terkait Kartu Kanban Ini (<?= $sessCnt ?> Sesi)
                                            </div>

                                            <?php foreach ($kg['sessions'] as $sSubIdx => $sSub): ?>
                                                <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                                                    <!-- Sesi Sub-Header -->
                                                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; padding-bottom: 10px; border-bottom: 1px solid #f1f5f9;">
                                                        <div style="display: flex; align-items: center; gap: 8px;">
                                                            <span style="font-size: 12px; font-weight: 900; color: #0f172a;" title="ID Database: #<?= $sSub['session_id'] ?>">
                                                                Sesi #<?= $sSubIdx + 1 ?>
                                                            </span>

                                                            <!-- Sesi Badge Tag (Cicilan vs Re-inspeksi) -->
                                                            <?php if ($sSub['session_badge_type'] === 'reinspection'): ?>
                                                                <span style="font-size: 10px; font-weight: 800; background: #fef3c7; color: #92400e; border: 1px solid #fde68a; padding: 2px 8px; border-radius: 6px;">
                                                                    <?= $sSub['session_badge_text'] ?>
                                                                </span>
                                                            <?php elseif ($sSub['session_badge_type'] === 'partial'): ?>
                                                                <span style="font-size: 10px; font-weight: 800; background: #ede9fe; color: #6b21a8; border: 1px solid #ddd6fe; padding: 2px 8px; border-radius: 6px;">
                                                                    <?= $sSub['session_badge_text'] ?>
                                                                </span>
                                                            <?php else: ?>
                                                                <span style="font-size: 10px; font-weight: 700; background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; padding: 2px 8px; border-radius: 6px;">
                                                                    <?= $sSub['session_badge_text'] ?>
                                                                </span>
                                                            <?php endif; ?>

                                                            <!-- Line Badge -->
                                                            <span style="font-size: 10px; font-weight: 800; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 2px 8px; border-radius: 6px;">
                                                                <?= htmlspecialchars($sSub['line_name'] ?: 'Line 1') ?>
                                                            </span>

                                                            <?php 
                                                            $startTime = !empty($sSub['started_at']) ? date('H:i', strtotime($sSub['started_at'])) : '-';
                                                            $endTime   = !empty($sSub['closed_at']) ? date('H:i', strtotime($sSub['closed_at'])) : 'Sekarang';
                                                            $timeRange = $startTime . ' - ' . $endTime;
                                                            ?>
                                                            <span style="font-size: 11px; color: #64748b;">
                                                                Jam <b><?= $timeRange ?> WIB</b> oleh <b><?= htmlspecialchars($sSub['inspector_name']) ?></b>
                                                            </span>
                                                        </div>

                                                        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                                            <span style="font-size: 11px; font-weight: 800; color: #0f172a; font-family: monospace;">
                                                                Sample: <?= $sSub['sample_size'] ?><?= !empty($sSub['reinspect_samples']) ? " (+{$sSub['reinspect_samples']} reinspeksi)" : "" ?> pcs &middot; NG: <span style="color: <?= ((int)($sSub['ng_count'] ?? 0) > 0) ? '#dc2626' : '#16a34a' ?>;"><?= (int)($sSub['ng_count'] ?? 0) ?> / <?= max(1, (int)$sSub['reject_number']) ?></span>
                                                            </span>
                                                            <span style="font-size: 11px; font-weight: 900; padding: 2px 8px; border-radius: 9999px; <?= $sSub['session_status'] === 'passed' ? 'background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;' : 'background:#ffe4e6;color:#be123c;border:1px solid #fecdd3;' ?>">
                                                                <?= strtoupper($sSub['session_status']) ?>
                                                            </span>

                                                            <!-- Approval Badge & Action Button -->
                                                            <?php if (!empty($sSub['is_approved']) && (int)$sSub['is_approved'] === 1): ?>
                                                                <div style="display: inline-flex; align-items: center; gap: 5px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 6px; padding: 2px 8px;">
                                                                    <svg style="width: 12px; height: 12px; color: #059669;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                                                                    </svg>
                                                                    <span style="font-size: 10px; font-weight: 800; color: #065f46;">
                                                                        ACC: <?= htmlspecialchars($sSub['supervisor_name']) ?> (<?= !empty($sSub['approved_at']) ? date('H:i', strtotime($sSub['approved_at'])) : '-' ?>)
                                                                    </span>
                                                                    <button type="button" onclick="confirmUnapprove(<?= (int)$sSub['session_id'] ?>)" 
                                                                            title="Batalkan Persetujuan Supervisor"
                                                                            style="background: transparent; border: none; cursor: pointer; padding: 0 2px; color: #94a3b8; font-size: 10px; font-weight: 700; text-decoration: underline;"
                                                                            class="hover:text-red-600">
                                                                        Batal
                                                                    </button>
                                                                </div>
                                                            <?php else: ?>
                                                                <div style="display: inline-flex; align-items: center; gap: 5px;">
                                                                    <span style="font-size: 10px; font-weight: 800; background: #fffbeb; color: #b45309; border: 1px solid #fde68a; padding: 2px 7px; border-radius: 6px;">
                                                                        BELUM DI-ACC
                                                                    </span>
                                                                    <button type="button" onclick="confirmApproveSingle(<?= (int)$sSub['session_id'] ?>)"
                                                                            style="background: #2563eb; border: 1px solid #1d4ed8; color: #fff; font-size: 10px; font-weight: 800; padding: 3px 10px; border-radius: 6px; cursor: pointer; transition: all 0.15s;"
                                                                            class="hover:bg-blue-700 shadow-xs">
                                                                        Setujui Sesi
                                                                    </button>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>

                                                    <!-- Re-inspection Notes if available -->
                                                    <?php if (!empty($sSub['reinspection_notes'])): ?>
                                                        <div style="background: #fefce8; border: 1px solid #fef08a; border-radius: 8px; padding: 6px 10px; margin-top: 8px; font-size: 11px; color: #854d0e;">
                                                            <b>Catatan Re-inspeksi:</b> <?= htmlspecialchars($sSub['reinspection_notes']) ?>
                                                        </div>
                                                    <?php endif; ?>

                                                    <!-- Level 3: Lots Table in this Session -->
                                                    <div style="margin-top: 10px;">
                                                        <div style="font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; margin-bottom: 4px;">
                                                            Rincian Box / Lot yang Diperiksa (<?= count($sSub['lots']) ?> <?= count($sSub['lots']) > 1 ? 'Pemeriksaan' : 'Box' ?> · <?= number_format($sSub['computed_qty']) ?> pcs):
                                                        </div>
                                                        <div style="overflow-x: auto; border: 1px solid #f1f5f9; border-radius: 8px;">
                                                            <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
                                                                <thead style="background: #f8fafc; color: #64748b; font-weight: 700; border-bottom: 1px solid #e2e8f0;">
                                                                    <tr>
                                                                        <th style="padding: 6px 10px; width: 30px; text-align: center;">#</th>
                                                                        <th style="padding: 6px 10px; min-width: 130px;">Ref Number</th>
                                                                        <th style="padding: 6px 10px; min-width: 120px;">Lot Number</th>
                                                                        <th style="padding: 6px 10px; text-align: right; width: 85px;">Qty Box</th>
                                                                        <th style="padding: 6px 10px; text-align: center; width: 95px;">Sampel (pcs)</th>
                                                                        <th style="padding: 6px 10px; text-align: center; width: 75px;">NG</th>
                                                                        <th style="padding: 6px 10px; min-width: 100px;">Waktu Scan</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    <?php if (empty($sSub['lots'])): ?>
                                                                        <tr><td colspan="7" style="padding: 8px; text-align: center; color: #94a3b8;">Tidak ada catatan box spesifik.</td></tr>
                                                                    <?php else: ?>
                                                                        <?php foreach ($sSub['lots'] as $lIdx => $lot): 
                                                                            $hasDefects = !empty($lot['defects']);
                                                                            $lotNgTotal = 0;
                                                                            if ($hasDefects) {
                                                                                foreach ($lot['defects'] as $df) { $lotNgTotal += (int)$df['qty_ng']; }
                                                                            } else {
                                                                                $lotNgTotal = (int)($lot['ng_count'] ?? 0);
                                                                            }
                                                                            $isReinspect = !empty($lot['is_reinspected_attempt']);
                                                                            $isInitial = !empty($lot['is_initial_attempt']);
                                                                            $isReplaced = (!empty($lot['lot_status']) && $lot['lot_status'] === 'replaced') || !empty($lot['is_replaced']);
                                                                            $isReplacementBox = !empty($lot['is_replacement_box']) || (isset($lot['remarks']) && strpos($lot['remarks'], 'Box Pengganti') !== false);
                                                                        ?>
                                                                            <tr style="border-bottom: <?= $hasDefects ? '1px dashed #fca5a5' : ($isReinspect ? '1px dashed #86efac' : '1px solid #f8fafc') ?>; background: <?= $hasDefects ? '#fffbfb' : ($isReinspect ? '#f0fdf4' : 'transparent') ?>;">
                                                                                <td style="padding: 6px 10px; text-align: center; color: #94a3b8; font-weight: 700;"><?= $lIdx + 1 ?></td>
                                                                                <td style="padding: 6px 10px; font-family: monospace; font-weight: 800; color: #7c3aed;">
                                                                                    <?= htmlspecialchars($lot['ref_number'] ?: '-') ?>
                                                                                    <?php if ($isReinspect): ?>
                                                                                        <span style="display: inline-block; margin-left: 5px; font-family: sans-serif; font-size: 9px; font-weight: 800; <?= ($lot['lot_result'] === 'rejected') ? 'background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca;' : 'background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0;' ?> padding: 1px 5px; border-radius: 4px; vertical-align: middle;">
                                                                                            <?= ($lot['lot_result'] === 'rejected') ? 'RE-INSPEKSI (NG)' : 'RE-INSPEKSI' ?>
                                                                                        </span>
                                                                                    <?php elseif ($isInitial): ?>
                                                                                        <span style="display: inline-block; margin-left: 5px; font-family: sans-serif; font-size: 9px; font-weight: 800; background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; padding: 1px 5px; border-radius: 4px; vertical-align: middle;">
                                                                                            UJI AWAL (NG)
                                                                                        </span>
                                                                                    <?php elseif ($isReplaced): ?>
                                                                                        <span style="display: inline-block; margin-left: 5px; font-family: sans-serif; font-size: 9px; font-weight: 800; background: #fffbeb; color: #b45309; border: 1px solid #fde68a; padding: 1px 5px; border-radius: 4px; vertical-align: middle;">
                                                                                            DIGANTIKAN
                                                                                        </span>
                                                                                    <?php elseif ($isReplacementBox): ?>
                                                                                        <span style="display: inline-block; margin-left: 5px; font-family: sans-serif; font-size: 9px; font-weight: 800; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 1px 5px; border-radius: 4px; vertical-align: middle;">
                                                                                            BOX PENGGANTI
                                                                                        </span>
                                                                                    <?php endif; ?>
                                                                                </td>
                                                                                <td style="padding: 6px 10px; font-family: monospace; color: #334155;">
                                                                                    <?= htmlspecialchars($lot['lot_number'] ?: '-') ?>
                                                                                </td>
                                                                                <td style="padding: 6px 10px; text-align: right; font-family: monospace; font-weight: 700; color: #0f172a;">
                                                                                    <?= number_format($lot['qty']) ?> pcs
                                                                                </td>
                                                                                <td style="padding: 6px 10px; text-align: center; font-family: monospace; font-weight: 700; color: #2563eb;">
                                                                                    <?= (int)$lot['computed_sample_size'] ?> pcs
                                                                                </td>
                                                                                <td style="padding: 6px 10px; text-align: center;">
                                                                                    <span style="font-family: monospace; font-size: 11px; font-weight: 800; padding: 2px 7px; border-radius: 4px; <?= $lotNgTotal > 0 ? 'background: #fef2f2; color: #dc2626; border: 1px solid #fecaca;' : 'background: #f0fdf4; color: #16a34a; border: 1px solid #dcfce7;' ?>">
                                                                                        <?= $lotNgTotal ?> / <?= (int)$lot['computed_reject_number'] ?>
                                                                                    </span>
                                                                                </td>
                                                                                <td style="padding: 6px 10px; color: #64748b;">
                                                                                    <?= date('H:i', strtotime($lot['scanned_at'])) ?> WIB
                                                                                </td>
                                                                            </tr>
                                                                            <?php if ($hasDefects): ?>
                                                                                <tr style="background: #fff5f5; border-bottom: 2px solid #fecaca;">
                                                                                    <td colspan="7" style="padding: 6px 10px 8px 12px;">
                                                                                        <div style="display: flex; flex-direction: column; gap: 4px;">
                                                                                            <div style="font-size: 10px; font-weight: 800; color: #991b1b; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 4px;">
                                                                                                <svg style="width: 12px; height: 12px; color: #dc2626;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                                                                                <span><?= !empty($lot['is_reinspected_attempt']) ? 'Temuan Cacat pada Pemeriksaan Ulang (Re-inspeksi):' : 'Temuan Cacat pada Pemeriksaan Awal (' . count($lot['defects']) . ' defect):' ?></span>
                                                                                            </div>
                                                                                            <?php foreach ($lot['defects'] as $df): ?>
                                                                                                <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 11px; background: #ffffff; padding: 4px 8px; border-radius: 5px; border: 1px solid #fee2e2; flex-wrap: wrap;">
                                                                                                    <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                                                                                        <span style="font-weight: 800; color: #dc2626;">• <?= htmlspecialchars($df['defect_name']) ?>:</span>
                                                                                                        <span style="font-family: monospace; font-weight: 800; color: #b91c1c; background: #fee2e2; padding: 1px 6px; border-radius: 3px;">
                                                                                                            <?= (int)$df['qty_ng'] ?> pcs
                                                                                                        </span>
                                                                                                        <?php if (!empty($df['remark'])): ?>
                                                                                                            <span style="color: #64748b; font-style: italic; font-size: 10px;">
                                                                                                                (<?= htmlspecialchars($df['remark']) ?>)
                                                                                                            </span>
                                                                                                        <?php endif; ?>
                                                                                                    </div>
                                                                                                    <div style="display: flex; align-items: center; gap: 10px; font-size: 10px; color: #475569;">
                                                                                                        <span>
                                                                                                            <span style="color: #64748b;">Inspector:</span> 
                                                                                                            <strong style="color: #0f172a;"><?= htmlspecialchars($df['inspector_name'] ?? 'Inspector') ?></strong>
                                                                                                        </span>
                                                                                                        <span style="background: #f1f5f9; color: #334155; padding: 1px 6px; border-radius: 4px; font-family: monospace; font-weight: 700; border: 1px solid #e2e8f0;">
                                                                                                            <?= !empty($df['defect_created_at']) ? date('H:i \W\I\B', strtotime($df['defect_created_at'])) : '-' ?>
                                                                                                        </span>
                                                                                                    </div>
                                                                                                </div>
                                                                                            <?php endforeach; ?>
                                                                                        </div>
                                                                                    </td>
                                                                                </tr>
                                                                            <?php endif; ?>
                                                                            <?php if ($isReinspect): ?>
                                                                                <tr style="background: <?= $lot['lot_result'] === 'rejected' ? '#fff1f2' : '#f0fdf4' ?>; border-bottom: 2px solid <?= $lot['lot_result'] === 'rejected' ? '#fecdd3' : '#bbf7d0' ?>;">
                                                                                    <td colspan="7" style="padding: 6px 10px 8px 12px;">
                                                                                        <div style="display: flex; flex-direction: column; gap: 4px;">
                                                                                            <div style="font-size: 10px; font-weight: 800; color: <?= $lot['lot_result'] === 'rejected' ? '#be123c' : '#166534' ?>; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 4px;">
                                                                                                <?php if ($lot['lot_result'] === 'rejected'): ?>
                                                                                                    <svg style="width: 13px; height: 13px; color: #e11d48;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                                                                                    <span>Hasil Verifikasi & Re-inspeksi: Tetap Ditemukan Cacat / NG (REJECTED)</span>
                                                                                                <?php else: ?>
                                                                                                    <svg style="width: 13px; height: 13px; color: #16a34a;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                                                                    <span>Hasil Verifikasi & Re-inspeksi: Nol Cacat / Defect (PASSED)</span>
                                                                                                <?php endif; ?>
                                                                                            </div>
                                                                                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 11px; background: #ffffff; padding: 5px 10px; border-radius: 5px; border: 1px solid <?= $lot['lot_result'] === 'rejected' ? '#fecdd3' : '#dcfce7' ?>; flex-wrap: wrap;">
                                                                                                <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                                                                                    <span style="font-weight: 800; color: <?= $lot['lot_result'] === 'rejected' ? '#be123c' : '#15803d' ?>;">Tindakan:</span>
                                                                                                    <span style="color: #1e293b;"><?= htmlspecialchars($lot['action_notes'] ?: ($lot['lot_result'] === 'rejected' ? 'Part NG disortir dan diuji ulang fisik, namun tetap ditemukan cacat/NG.' : 'Part NG disortir dan ditukar dengan part bagus -> Dilakukan re-inspeksi sampel fisik dan lolos uji.')) ?></span>
                                                                                                </div>
                                                                                                <div style="display: flex; align-items: center; gap: 10px; font-size: 10px; color: #475569;">
                                                                                                    <span>
                                                                                                        <span style="color: #64748b;">Inspector:</span> 
                                                                                                        <strong style="color: #0f172a;"><?= htmlspecialchars($lot['actioned_by_name'] ?? 'Inspector') ?></strong>
                                                                                                    </span>
                                                                                                    <span style="background: #f1f5f9; color: #334155; padding: 1px 6px; border-radius: 4px; font-family: monospace; font-weight: 700; border: 1px solid #e2e8f0;">
                                                                                                        <?= !empty($lot['action_at']) ? date('H:i \W\I\B', strtotime($lot['action_at'])) : date('H:i \W\I\B', strtotime($lot['scanned_at'])) ?>
                                                                                                    </span>
                                                                                                </div>
                                                                                            </div>
                                                                                        </div>
                                                                                    </td>
                                                                                </tr>
                                                                            <?php endif; ?>
                                                                        <?php endforeach; ?>
                                                                    <?php endif; ?>
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    </div>

                                                    <!-- Level 3: Summary or Unmatched Defect NG Box in this Session -->
                                                    <?php if (!empty($sSub['unmatched_defects'])): ?>
                                                        <div style="margin-top: 10px; background: #fff5f5; border: 1px solid #fed7d7; border-radius: 8px; padding: 8px 12px;">
                                                            <div style="font-size: 11px; font-weight: 800; color: #9b2c2c; display: flex; align-items: center; gap: 5px; margin-bottom: 6px;">
                                                                <svg style="width: 13px; height: 13px; color: #dc2626;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                                                <span>Temuan Cacat Sesi (Tanpa Mapping Lot):</span>
                                                            </div>
                                                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                                                <?php foreach ($sSub['unmatched_defects'] as $ng): ?>
                                                                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 11px; background: #fff; padding: 5px 8px; border-radius: 6px; border: 1px solid #fee2e2; flex-wrap: wrap;">
                                                                        <div style="display: flex; align-items: center; gap: 6px;">
                                                                            <span style="font-weight: 800; color: #dc2626;">• <?= htmlspecialchars($ng['defect_name']) ?>:</span>
                                                                            <span style="font-family: monospace; font-weight: 800; color: #b91c1c; background: #fee2e2; padding: 1px 6px; border-radius: 3px;"><?= (int)$ng['qty_ng'] ?> pcs</span>
                                                                            <span style="color: #64748b; font-size: 10px;">(Lot: <?= htmlspecialchars($ng['lot_number']) ?>, Ref: <?= htmlspecialchars($ng['ref_number']) ?>)</span>
                                                                        </div>
                                                                        <div style="display: flex; align-items: center; gap: 10px; font-size: 10px; color: #475569;">
                                                                            <span><span style="color: #64748b;">Inspector:</span> <strong style="color: #0f172a;"><?= htmlspecialchars($ng['inspector_name'] ?? 'Inspector') ?></strong></span>
                                                                            <span style="background: #f1f5f9; color: #334155; padding: 1px 6px; border-radius: 4px; font-family: monospace; font-weight: 700; border: 1px solid #e2e8f0;"><?= !empty($ng['defect_created_at']) ? date('H:i \W\I\B', strtotime($ng['defect_created_at'])) : '-' ?></span>
                                                                        </div>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </div>
                                                    <?php elseif (empty($sSub['ng_records'])): ?>
                                                        <div style="margin-top: 8px; font-size: 11px; color: #166534; background: #f0fdf4; border: 1px solid #dcfce7; border-radius: 6px; padding: 4px 10px; display: inline-flex; align-items: center; gap: 4px;">
                                                            <svg style="width: 13px; height: 13px; color: #16a34a;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                                            <span><b>All Samples OK:</b> Seluruh sampel lot memenuhi standar, tidak ditemukan cacat.</span>
                                                        </div>
                                                    <?php endif; ?>

                                                </div>
                                            <?php endforeach; ?>

                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- A2. SAFETY STOCK SECTION -->
                <?php if (!empty($ssGroups)): ?>
                <div style="background: #fff; border: 1px solid #bae6fd; border-radius: 14px; overflow: hidden; box-shadow: 0 4px 14px rgba(3,105,161,0.06);">
                    <div style="padding: 12px 18px; background: #f0f9ff; border-bottom: 1px solid #e0f2fe; display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span style="background: #0284c7; color: #fff; width: 28px; height: 28px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center;"><svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg></span>
                            <div>
                                <h2 style="font-size: 13px; font-weight: 900; color: #0369a1; margin: 0;">Jalur Safety Stock (Gudang Buffer Internal)</h2>
                                <p style="font-size: 11px; color: #0284c7; margin: 0; font-weight: 600;">Pemeriksaan lot produksi masuk untuk stok buffer cadangan pabrik</p>
                            </div>
                        </div>
                        <span style="font-size: 11px; font-weight: 800; background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; padding: 3px 12px; border-radius: 9999px;">
                            <?= count($ssGroups) ?> Lot Item
                        </span>
                    </div>

                    <div style="overflow-x: auto;">
                        <table class="w-full text-left" style="border-collapse: collapse; font-size: 12px; min-width: 880px;">
                            <thead style="background: #fbfdff; border-bottom: 2px solid #e0f2fe; font-size: 10px; font-weight: 800; color: #0369a1; text-transform: uppercase; letter-spacing: 0.5px;">
                                <tr>
                                    <th style="padding: 11px 16px; width: 40px; text-align: center;">No</th>
                                    <th style="padding: 11px 16px; min-width: 240px;">Identitas Part & Lot </th>
                                    <th style="padding: 11px 16px; text-align: center; width: 80px;">Cavity</th>
                                    <th style="padding: 11px 16px; text-align: center; width: 140px;">Total Fisik</th>
                                    <th style="padding: 11px 16px; text-align: center; width: 140px;">Riwayat Sesi</th>
                                    <th style="padding: 11px 16px; text-align: center; width: 120px;">Status Akhir</th>
                                    <th style="padding: 11px 16px; text-align: center; width: 110px;">Rincian</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $sIdx = 1;
                                foreach ($ssGroups as $ssKey => $ss): 
                                    $sessCnt = count($ss['sessions']);
                                ?>
                                <tr class="review-row hover:bg-sky-50/40 transition-colors" style="border-bottom: 1px solid #f1f5f9; cursor: pointer;" onclick="toggleAccordion('acc-<?= $ssKey ?>')">
                                    <td style="padding: 12px 16px; text-align: center; font-weight: 800; color: #94a3b8;"><?= $sIdx++ ?></td>
                                    
                                    <td style="padding: 12px 16px;">
                                        <div style="font-weight: 800; color: #0369a1; font-size: 12px; font-family: monospace;">
                                            <?= htmlspecialchars($ss['part_code']) ?>
                                        </div>
                                        <div style="font-size: 11px; color: #64748b; font-weight: 600;">
                                            <?= htmlspecialchars($ss['part_name']) ?>
                                        </div>
                                        <div style="font-size: 11px; font-family: monospace; color: #334155; margin-top: 3px;">
                                            Lot: <b><?= htmlspecialchars($ss['lot_number']) ?></b>
                                        </div>
                                    </td>

                                    <td style="padding: 12px 16px; text-align: center; font-weight: 800; color: #475569;">
                                        <?= htmlspecialchars($ss['cavity']) ?>
                                    </td>

                                    <td style="padding: 12px 16px; text-align: center;">
                                        <div style="font-family: monospace; font-weight: 900; font-size: 13px; color: #0f172a;">
                                            <?= number_format($ss['passed_qty'] ?? 0) ?> <span style="font-size: 10px; color: #64748b;">pcs</span>
                                        </div>
                                        <span style="font-size: 10px; color: #64748b; font-weight: 600;">
                                            <?= $ss['total_boxes'] ?> box label
                                        </span>
                                    </td>

                                    <td style="padding: 12px 16px; text-align: center;">
                                        <span style="display: inline-block; font-size: 11px; font-weight: 800; color: #0369a1; background: #e0f2fe; border: 1px solid #bae6fd; padding: 2px 9px; border-radius: 6px;">
                                            <?= $sessCnt ?> Sesi
                                        </span>
                                        <?php if ($ss['total_ng'] > 0): ?>
                                            <div style="font-size: 10px; font-weight: 800; color: #e11d48; margin-top: 3px;">
                                                Total <?= $ss['total_ng'] ?> NG
                                            </div>
                                        <?php else: ?>
                                            <div style="font-size: 10px; font-weight: 700; color: #16a34a; margin-top: 3px;">
                                                Clean (0 NG)
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td style="padding: 12px 16px; text-align: center;">
                                        <?php if ($ss['final_status'] === 'in_progress'): ?>
                                            <span style="display: inline-flex; align-items: center; gap: 4px; background: #e0e7ff; color: #3730a3; border: 1px solid #c7d2fe; font-size: 11px; font-weight: 900; padding: 4px 10px; border-radius: 9999px;">
                                                IN PROGRESS
                                            </span>
                                        <?php elseif ($ss['final_status'] === 'passed'): ?>
                                            <span style="display: inline-flex; align-items: center; gap: 4px; background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; font-size: 11px; font-weight: 900; padding: 4px 10px; border-radius: 9999px;">
                                                PASSED
                                            </span>
                                        <?php elseif ($ss['final_status'] === 'rejected'): ?>
                                            <span style="display: inline-flex; align-items: center; gap: 4px; background: #ffe4e6; color: #be123c; border: 1px solid #fecdd3; font-size: 11px; font-weight: 900; padding: 4px 10px; border-radius: 9999px;">
                                                REJECTED
                                            </span>
                                        <?php else: ?>
                                            <span style="display: inline-flex; align-items: center; gap: 4px; background: #fef3c7; color: #92400e; border: 1px solid #fde68a; font-size: 11px; font-weight: 900; padding: 4px 10px; border-radius: 9999px;">
                                                PARTIAL
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td style="padding: 12px 16px; text-align: center;">
                                        <button type="button" class="btn-accordion-toggle" style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 5px 10px; font-size: 11px; font-weight: 700; color: #334155; display: inline-flex; align-items: center; gap: 4px;">
                                            <span class="btn-text">Rincian</span>
                                            <svg class="caret-icon" style="width: 12px; height: 12px; transition: transform 0.2s;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                        </button>
                                    </td>
                                </tr>

                                <!-- Sub-Sessions for Safety Stock -->
                                <tr id="acc-<?= $ssKey ?>" class="accordion-sub-row hidden" style="background: #fafafa;">
                                    <td colspan="7" style="padding: 14px 18px 18px 24px; border-bottom: 2px solid #e2e8f0;">
                                        <div style="border-left: 3px solid #0284c7; padding-left: 16px;" class="space-y-4">
                                            <div style="font-size: 11px; font-weight: 800; color: #0369a1; text-transform: uppercase; letter-spacing: 0.5px;">
                                                Daftar Sesi Inspeksi Safety Stock (<?= $sessCnt ?> Sesi)
                                            </div>

                                            <?php foreach ($ss['sessions'] as $sSubIdx => $sSub): ?>
                                                <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                                                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; padding-bottom: 10px; border-bottom: 1px solid #f1f5f9;">
                                                        <div style="display: flex; align-items: center; gap: 8px;">
                                                            <span style="font-size: 12px; font-weight: 900; color: #0f172a;">
                                                                Sesi #<?= $sSubIdx + 1 ?>
                                                            </span>
                                                            <span style="font-size: 10px; font-weight: 800; background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; padding: 2px 8px; border-radius: 6px;">
                                                                <?= $sSub['session_badge_text'] ?>
                                                            </span>

                                                            <!-- Line Badge -->
                                                            <span style="font-size: 10px; font-weight: 800; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 2px 8px; border-radius: 6px;">
                                                                <?= htmlspecialchars($sSub['line_name'] ?: 'Line 1') ?>
                                                            </span>

                                                            <?php 
                                                            $startTime = !empty($sSub['started_at']) ? date('H:i', strtotime($sSub['started_at'])) : '-';
                                                            $endTime   = !empty($sSub['closed_at']) ? date('H:i', strtotime($sSub['closed_at'])) : 'Sekarang';
                                                            $timeRange = $startTime . ' - ' . $endTime;
                                                            ?>
                                                            <span style="font-size: 11px; color: #64748b;">
                                                                Jam <b><?= $timeRange ?> WIB</b> oleh <b><?= htmlspecialchars($sSub['inspector_name']) ?></b>
                                                            </span>
                                                        </div>

                                                        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                                            <span style="font-size: 11px; font-weight: 800; color: #0f172a; font-family: monospace;">
                                                                Sample: <?= $sSub['sample_size'] ?> pcs &middot; NG: <span style="color: <?= ((int)($sSub['ng_count'] ?? 0) > 0) ? '#dc2626' : '#16a34a' ?>;"><?= (int)($sSub['ng_count'] ?? 0) ?> / <?= max(1, (int)$sSub['reject_number']) ?></span>
                                                            </span>
                                                            <span style="font-size: 11px; font-weight: 900; padding: 2px 8px; border-radius: 9999px; <?= $sSub['session_status'] === 'passed' ? 'background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;' : 'background:#ffe4e6;color:#be123c;border:1px solid #fecdd3;' ?>">
                                                                <?= strtoupper($sSub['session_status']) ?>
                                                            </span>

                                                            <!-- Approval Badge & Action Button -->
                                                            <?php if (!empty($sSub['is_approved']) && (int)$sSub['is_approved'] === 1): ?>
                                                                <div style="display: inline-flex; align-items: center; gap: 5px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 6px; padding: 2px 8px;">
                                                                    <svg style="width: 12px; height: 12px; color: #059669;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                                                                    </svg>
                                                                    <span style="font-size: 10px; font-weight: 800; color: #065f46;">
                                                                        ACC: <?= htmlspecialchars($sSub['supervisor_name']) ?> (<?= !empty($sSub['approved_at']) ? date('H:i', strtotime($sSub['approved_at'])) : '-' ?>)
                                                                    </span>
                                                                    <button type="button" onclick="confirmUnapprove(<?= (int)$sSub['session_id'] ?>)" 
                                                                            title="Batalkan Persetujuan Supervisor"
                                                                            style="background: transparent; border: none; cursor: pointer; padding: 0 2px; color: #94a3b8; font-size: 10px; font-weight: 700; text-decoration: underline;"
                                                                            class="hover:text-red-600">
                                                                        Batal
                                                                    </button>
                                                                </div>
                                                            <?php else: ?>
                                                                <div style="display: inline-flex; align-items: center; gap: 5px;">
                                                                    <span style="font-size: 10px; font-weight: 800; background: #fffbeb; color: #b45309; border: 1px solid #fde68a; padding: 2px 7px; border-radius: 6px;">
                                                                        BELUM DI-ACC
                                                                    </span>
                                                                    <button type="button" onclick="confirmApproveSingle(<?= (int)$sSub['session_id'] ?>)"
                                                                            style="background: #2563eb; border: 1px solid #1d4ed8; color: #fff; font-size: 10px; font-weight: 800; padding: 3px 10px; border-radius: 6px; cursor: pointer; transition: all 0.15s;"
                                                                            class="hover:bg-blue-700 shadow-xs">
                                                                        Setujui Sesi
                                                                    </button>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>

                                                    <!-- Lots Table -->
                                                    <div style="margin-top: 10px;">
                                                        <div style="overflow-x: auto; border: 1px solid #f1f5f9; border-radius: 8px;">
                                                            <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
                                                                <thead style="background: #f8fafc; color: #64748b; font-weight: 700; border-bottom: 1px solid #e2e8f0;">
                                                                    <tr>
                                                                        <th style="padding: 6px 10px; width: 30px; text-align: center;">#</th>
                                                                        <th style="padding: 6px 10px; min-width: 120px;">Lot Number</th>
                                                                        <th style="padding: 6px 10px; min-width: 120px;">Ref Number</th>
                                                                        <th style="padding: 6px 10px; text-align: right; width: 85px;">Qty</th>
                                                                        <th style="padding: 6px 10px; text-align: center; width: 95px;">Sampel (pcs)</th>
                                                                        <th style="padding: 6px 10px; text-align: center; width: 75px;">NG</th>
                                                                        <th style="padding: 6px 10px; min-width: 100px;">Waktu Scan</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    <?php if (empty($sSub['lots'])): ?>
                                                                        <tr><td colspan="7" style="padding: 8px; text-align: center; color: #94a3b8;">Tidak ada catatan box spesifik.</td></tr>
                                                                    <?php else: ?>
                                                                        <?php foreach ($sSub['lots'] as $lIdx => $lot): 
                                                                            $hasDefects = !empty($lot['defects']);
                                                                            $lotNgTotal = 0;
                                                                            if ($hasDefects) {
                                                                                foreach ($lot['defects'] as $df) { $lotNgTotal += (int)$df['qty_ng']; }
                                                                            } else {
                                                                                $lotNgTotal = (int)($lot['ng_count'] ?? 0);
                                                                            }
                                                                        ?>
                                                                            <tr style="border-bottom: <?= $hasDefects ? '1px dashed #fca5a5' : '1px solid #f8fafc' ?>; background: <?= $hasDefects ? '#fffbfb' : 'transparent' ?>;">
                                                                                <td style="padding: 6px 10px; text-align: center; color: #94a3b8; font-weight: 700;"><?= $lIdx + 1 ?></td>
                                                                                <td style="padding: 6px 10px; font-family: monospace; color: #334155;">
                                                                                    <?= htmlspecialchars($lot['lot_number'] ?: '-') ?>
                                                                                </td>
                                                                                <td style="padding: 6px 10px; font-family: monospace; font-weight: 800; color: #0284c7;">
                                                                                    <?= htmlspecialchars($lot['ref_number'] ?: '-') ?>
                                                                                </td>
                                                                                <td style="padding: 6px 10px; text-align: right; font-family: monospace; font-weight: 700; color: #0f172a;">
                                                                                    <?= number_format($lot['qty']) ?> pcs
                                                                                </td>
                                                                                <td style="padding: 6px 10px; text-align: center; font-family: monospace; font-weight: 700; color: #2563eb;">
                                                                                    <?= (int)$lot['computed_sample_size'] ?> pcs
                                                                                </td>
                                                                                <td style="padding: 6px 10px; text-align: center;">
                                                                                    <span style="font-family: monospace; font-size: 11px; font-weight: 800; padding: 2px 7px; border-radius: 4px; <?= $lotNgTotal > 0 ? 'background: #fef2f2; color: #dc2626; border: 1px solid #fecaca;' : 'background: #f0fdf4; color: #16a34a; border: 1px solid #dcfce7;' ?>">
                                                                                        <?= $lotNgTotal ?> / <?= (int)$lot['computed_reject_number'] ?>
                                                                                    </span>
                                                                                </td>
                                                                                <td style="padding: 6px 10px; color: #64748b;">
                                                                                    <?= date('H:i', strtotime($lot['scanned_at'])) ?> WIB
                                                                                </td>
                                                                            </tr>
                                                                            <?php if ($hasDefects): ?>
                                                                                <tr style="background: #fff5f5; border-bottom: 2px solid #fecaca;">
                                                                                    <td colspan="7" style="padding: 6px 10px 8px 12px;">
                                                                                        <div style="display: flex; flex-direction: column; gap: 4px;">
                                                                                            <div style="font-size: 10px; font-weight: 800; color: #991b1b; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 4px;">
                                                                                                <svg style="width: 12px; height: 12px; color: #dc2626;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                                                                                <span>Temuan Cacat pada Box / Lot Ini (<?= count($lot['defects']) ?> defect):</span>
                                                                                            </div>
                                                                                            <?php foreach ($lot['defects'] as $df): ?>
                                                                                                <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 11px; background: #ffffff; padding: 4px 8px; border-radius: 5px; border: 1px solid #fee2e2; flex-wrap: wrap;">
                                                                                                    <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                                                                                        <span style="font-weight: 800; color: #dc2626;">• <?= htmlspecialchars($df['defect_name']) ?>:</span>
                                                                                                        <span style="font-family: monospace; font-weight: 800; color: #b91c1c; background: #fee2e2; padding: 1px 6px; border-radius: 3px;">
                                                                                                            <?= (int)$df['qty_ng'] ?> pcs
                                                                                                        </span>
                                                                                                        <?php if (!empty($df['remark'])): ?>
                                                                                                            <span style="color: #64748b; font-style: italic; font-size: 10px;">
                                                                                                                (<?= htmlspecialchars($df['remark']) ?>)
                                                                                                            </span>
                                                                                                        <?php endif; ?>
                                                                                                    </div>
                                                                                                    <div style="display: flex; align-items: center; gap: 10px; font-size: 10px; color: #475569;">
                                                                                                        <span>
                                                                                                            <span style="color: #64748b;">Inspector:</span> 
                                                                                                            <strong style="color: #0f172a;"><?= htmlspecialchars($df['inspector_name'] ?? 'Inspector') ?></strong>
                                                                                                        </span>
                                                                                                        <span style="background: #f1f5f9; color: #334155; padding: 1px 6px; border-radius: 4px; font-family: monospace; font-weight: 700; border: 1px solid #e2e8f0;">
                                                                                                            <?= !empty($df['defect_created_at']) ? date('H:i \W\I\B', strtotime($df['defect_created_at'])) : '-' ?>
                                                                                                        </span>
                                                                                                    </div>
                                                                                                </div>
                                                                                            <?php endforeach; ?>
                                                                                        </div>
                                                                                    </td>
                                                                                </tr>
                                                                            <?php endif; ?>
                                                                        <?php endforeach; ?>
                                                                    <?php endif; ?>
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    </div>

                                                    <!-- Defect Box -->
                                                    <?php if (!empty($sSub['unmatched_defects'])): ?>
                                                        <div style="margin-top: 10px; background: #fff5f5; border: 1px solid #fed7d7; border-radius: 8px; padding: 8px 12px;">
                                                            <div style="font-size: 11px; font-weight: 800; color: #9b2c2c; display: flex; align-items: center; gap: 5px; margin-bottom: 6px;">
                                                                <svg style="width: 13px; height: 13px; color: #dc2626;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                                                <span>Temuan Cacat Sesi (Tanpa Mapping Lot):</span>
                                                            </div>
                                                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                                                <?php foreach ($sSub['unmatched_defects'] as $ng): ?>
                                                                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 11px; background: #fff; padding: 5px 8px; border-radius: 6px; border: 1px solid #fee2e2; flex-wrap: wrap;">
                                                                        <div style="display: flex; align-items: center; gap: 6px;">
                                                                            <span style="font-weight: 800; color: #dc2626;">• <?= htmlspecialchars($ng['defect_name']) ?>:</span>
                                                                            <span style="font-family: monospace; font-weight: 800; color: #b91c1c; background: #fee2e2; padding: 1px 6px; border-radius: 3px;"><?= (int)$ng['qty_ng'] ?> pcs</span>
                                                                            <span style="color: #64748b; font-size: 10px;">(Lot: <?= htmlspecialchars($ng['lot_number']) ?>, Ref: <?= htmlspecialchars($ng['ref_number']) ?>)</span>
                                                                        </div>
                                                                        <div style="display: flex; align-items: center; gap: 10px; font-size: 10px; color: #475569;">
                                                                            <span><span style="color: #64748b;">Inspector:</span> <strong style="color: #0f172a;"><?= htmlspecialchars($ng['inspector_name'] ?? 'Inspector') ?></strong></span>
                                                                            <span style="background: #f1f5f9; color: #334155; padding: 1px 6px; border-radius: 4px; font-family: monospace; font-weight: 700; border: 1px solid #e2e8f0;"><?= !empty($ng['defect_created_at']) ? date('H:i \W\I\B', strtotime($ng['defect_created_at'])) : '-' ?></span>
                                                                        </div>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </div>
                                                    <?php elseif (empty($sSub['ng_records'])): ?>
                                                        <div style="margin-top: 8px; font-size: 11px; color: #166534; background: #f0fdf4; border: 1px solid #dcfce7; border-radius: 6px; padding: 4px 10px; display: inline-flex; align-items: center; gap: 4px;">
                                                            <svg style="width: 13px; height: 13px; color: #16a34a;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                                            <span><b>All Samples OK:</b> Seluruh sampel lot memenuhi standar, tidak ditemukan cacat.</span>
                                                        </div>
                                                    <?php endif; ?>

                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

            </div>


            <!-- ═══════════════════════════════════════════════════════════════ -->
            <!-- 4. VIEW MODE B: GROUPED BY INSPECTION SESSION (TIMELINE) -->
            <!-- ═══════════════════════════════════════════════════════════════ -->
            <div id="container-view-session" class="space-y-4 hidden">
                <div style="background: #fff; border: 1px solid #cbd5e1; border-radius: 14px; overflow: hidden; box-shadow: 0 4px 14px rgba(15,23,42,0.05);">
                    <div style="padding: 12px 18px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span style="background: #0f172a; color: #fff; width: 28px; height: 28px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center;"><svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></span>
                            <div>
                                <h2 style="font-size: 13px; font-weight: 900; color: #0f172a; margin: 0;">Kronologi Seluruh Sesi Inspeksi Hari Ini</h2>
                                <p style="font-size: 11px; color: #64748b; margin: 0; font-weight: 600;">Urutan pelaksanaan inspeksi di meja QC dari pagi hingga sore (Kanban & Safety Stock)</p>
                            </div>
                        </div>
                        <span style="font-size: 11px; font-weight: 800; background: #e2e8f0; color: #334155; padding: 3px 12px; border-radius: 9999px;">
                            Total <?= count($sessionTimeline) ?> Sesi
                        </span>
                    </div>

                    <div style="overflow-x: auto;">
                        <table class="w-full text-left" style="border-collapse: collapse; font-size: 12px; min-width: 920px;">
                            <thead style="background: #f8fafc; border-bottom: 2px solid #cbd5e1; font-size: 10px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">
                                <tr>
                                    <th style="padding: 11px 16px; width: 80px; text-align: center;">Sesi #</th>
                                    <th style="padding: 11px 16px; width: 140px;">Jam</th>
                                    <th style="padding: 11px 16px; width: 110px; text-align: center;">Jalur</th>
                                    <th style="padding: 11px 16px; min-width: 220px;">Identitas Item (Kanban / Part)</th>
                                    <th style="padding: 11px 16px; min-width: 130px;">Inspector QC</th>
                                    <th style="padding: 11px 16px; text-align: center; width: 120px;">Qty </th>
                                    <th style="padding: 11px 16px; text-align: center; width: 110px;">Status</th>
                                    <th style="padding: 11px 16px; text-align: center; width: 150px;">Persetujuan Supervisor</th>
                                    <th style="padding: 11px 16px; text-align: center; width: 110px;">Rincian</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sessionTimeline as $stIdx => $sess): 
                                    $isKb = ($sess['inspection_type'] === 'kanban');
                                    $isReins = ((int)$sess['is_reinspection'] === 1 || (int)$sess['parent_session_id'] > 0);
                                    $sTime = !empty($sess['started_at']) ? date('H:i', strtotime($sess['started_at'])) : '-';
                                    $eTime = !empty($sess['closed_at']) ? date('H:i', strtotime($sess['closed_at'])) : 'Sekarang';
                                ?>
                                <tr class="review-row hover:bg-slate-50 transition-colors" style="border-bottom: 1px solid #f1f5f9; cursor: pointer;" onclick="toggleAccordion('acc-timeline-<?= $sess['session_id'] ?>')">
                                    <td style="padding: 12px 16px; text-align: center;">
                                        <span style="font-family: monospace; font-weight: 900; font-size: 12px; color: #0f172a; background: #f1f5f9; padding: 2px 7px; border-radius: 5px; border: 1px solid #e2e8f0;">
                                            #<?= $sess['session_id'] ?>
                                        </span>
                                    </td>

                                    <td style="padding: 12px 16px;">
                                        <div style="font-weight: 800; color: #0f172a; font-size: 11px; white-space: nowrap;">
                                            <?= $sTime ?> - <?= $eTime ?> WIB
                                        </div>
                                    </td>

                                    <td style="padding: 12px 16px; text-align: center;">
                                        <?php if ($isKb): ?>
                                            <span style="display: inline-block; font-size: 10px; font-weight: 900; background: #ede9fe; color: #6b21a8; border: 1px solid #c4b5fd; padding: 2px 8px; border-radius: 6px; text-transform: uppercase;">
                                                Kanban
                                            </span>
                                        <?php else: ?>
                                            <span style="display: inline-block; font-size: 10px; font-weight: 900; background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; padding: 2px 8px; border-radius: 6px; text-transform: uppercase;">
                                                Safety Stock
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td style="padding: 12px 16px;">
                                        <?php if ($isKb && !empty($sess['kanban_no']) && $sess['kanban_no'] !== '-'): ?>
                                            <span style="font-family: monospace; font-weight: 900; font-size: 11px; color: #7c3aed; background: #f5f3ff; border: 1px solid #ddd6fe; padding: 1px 6px; border-radius: 4px;">
                                                <?= htmlspecialchars($sess['kanban_no']) ?>
                                            </span>
                                            <span style="font-size: 11px; color: #475569; font-weight: 700; margin-left: 4px;">(<?= htmlspecialchars($sess['customer']) ?>)</span>
                                        <?php endif; ?>
                                        <div style="font-weight: 800; color: #0f172a; font-family: monospace; font-size: 11px; margin-top: 2px;">
                                            <?= htmlspecialchars($sess['part_code']) ?>
                                        </div>
                                        <div style="font-size: 10px; color: #64748b; font-weight: 600;">
                                            <?= htmlspecialchars($sess['part_name']) ?>
                                        </div>
                                        <?php if ($isReins): ?>
                                            <span style="font-size: 9px; font-weight: 800; background: #fef3c7; color: #92400e; border: 1px solid #fde68a; padding: 1px 5px; border-radius: 4px; display: inline-block; margin-top: 2px;">
                                                Re-inspeksi
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td style="padding: 12px 16px;">
                                        <div style="font-weight: 700; color: #1e293b; font-size: 11px;">
                                            <?= htmlspecialchars($sess['inspector_name']) ?>
                                        </div>
                                        <div style="font-size: 10px; color: #1d4ed8; font-weight: 800; margin-top: 2px;">
                                            <?= htmlspecialchars($sess['line_name'] ?: 'Line 1') ?>
                                        </div>
                                    </td>

                                    <td style="padding: 12px 16px; text-align: center;">
                                        <div style="font-family: monospace; font-weight: 900; color: #0f172a; font-size: 12px;">
                                            <?= number_format($sess['computed_qty']) ?> pcs
                                        </div>
                                        <span style="font-size: 10px; color: #64748b; font-weight: 600;">
                                            <?= $sess['box_count'] ?> Box
                                        </span>
                                    </td>

                                    <td style="padding: 12px 16px; text-align: center;">
                                        <?php if ($sess['session_status'] === 'passed'): ?>
                                            <span style="display: inline-flex; align-items: center; gap: 3px; background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; font-size: 11px; font-weight: 900; padding: 3px 8px; border-radius: 9999px;">
                                                PASS
                                            </span>
                                        <?php elseif ($sess['session_status'] === 'rejected'): ?>
                                            <span style="display: inline-flex; align-items: center; gap: 3px; background: #ffe4e6; color: #be123c; border: 1px solid #fecdd3; font-size: 11px; font-weight: 900; padding: 3px 8px; border-radius: 9999px;">
                                                REJECT
                                            </span>
                                        <?php else: ?>
                                            <span style="display: inline-flex; align-items: center; gap: 3px; background: #fef3c7; color: #92400e; border: 1px solid #fde68a; font-size: 11px; font-weight: 900; padding: 3px 8px; border-radius: 9999px;">
                                                IN PROG
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Kolom Persetujuan Supervisor -->
                                    <td style="padding: 12px 16px; text-align: center;" onclick="event.stopPropagation();">
                                        <?php if (!empty($sess['is_approved']) && (int)$sess['is_approved'] === 1): ?>
                                            <div style="display: inline-flex; flex-direction: column; align-items: center; gap: 2px;">
                                                <span style="display: inline-flex; align-items: center; gap: 3px; background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; font-size: 10px; font-weight: 800; padding: 2px 7px; border-radius: 6px;">
                                                    <svg style="width: 11px; height: 11px; color: #059669;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                                                    </svg>
                                                    DISETUJUI
                                                </span>
                                                <span style="font-size: 10px; color: #64748b; font-weight: 600;">
                                                    <?= htmlspecialchars($sess['supervisor_name']) ?> (<?= !empty($sess['approved_at']) ? date('H:i', strtotime($sess['approved_at'])) : '-' ?>)
                                                </span>
                                                <button type="button" onclick="confirmUnapprove(<?= (int)$sess['session_id'] ?>)"
                                                        style="background: transparent; border: none; font-size: 10px; color: #94a3b8; cursor: pointer; text-decoration: underline; margin-top: 1px;"
                                                        class="hover:text-red-600">
                                                    Batalkan
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <div style="display: inline-flex; flex-direction: column; align-items: center; gap: 4px;">
                                                <span style="display: inline-flex; align-items: center; gap: 3px; background: #fffbeb; color: #b45309; border: 1px solid #fde68a; font-size: 10px; font-weight: 800; padding: 2px 7px; border-radius: 6px;">
                                                    BELUM DI-ACC
                                                </span>
                                                <button type="button" onclick="confirmApproveSingle(<?= (int)$sess['session_id'] ?>)"
                                                        style="background: #2563eb; border: 1px solid #1d4ed8; color: #fff; font-size: 10px; font-weight: 800; padding: 3px 8px; border-radius: 6px; cursor: pointer; transition: all 0.15s;"
                                                        class="hover:bg-blue-700 shadow-xs">
                                                    Setujui Sesi
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td style="padding: 12px 16px; text-align: center;">
                                        <button type="button" class="btn-accordion-toggle" style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 5px 10px; font-size: 11px; font-weight: 700; color: #334155; display: inline-flex; align-items: center; gap: 4px;">
                                            <span class="btn-text">Detail</span>
                                            <svg class="caret-icon" style="width: 12px; height: 12px; transition: transform 0.2s;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                        </button>
                                    </td>
                                </tr>

                                <!-- Child Row: Lots and Defects for this Session -->
                                <tr id="acc-timeline-<?= $sess['session_id'] ?>" class="accordion-sub-row hidden" style="background: #fafafa;">
                                    <td colspan="9" style="padding: 14px 18px 18px 24px; border-bottom: 2px solid #e2e8f0;">
                                        <div style="border-left: 3px solid #0f172a; padding-left: 16px;" class="space-y-3">
                                            
                                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;">
                                                <div style="font-size: 11px; font-weight: 800; color: #0f172a; text-transform: uppercase;">
                                                    Rincian Box / Lot yang Digunakan pada Sesi #<?= $sess['session_id'] ?> (<?= count($sess['lots']) ?> <?= count($sess['lots']) > 1 ? 'Pemeriksaan' : 'Box' ?>):
                                                </div>
                                                <div style="font-size: 11px; font-weight: 700; color: #475569;">
                                                    Sample : <b><?= (int)($sess['effective_sample_size'] ?? $sess['sample_size']) ?> pcs</b> &middot; NG: <b style="color: <?= ((int)($sess['ng_count'] ?? 0) > 0) ? '#dc2626' : '#16a34a' ?>;"><?= (int)($sess['ng_count'] ?? 0) ?> / <?= max(1, (int)$sess['reject_number']) ?></b>
                                                </div>
                                            </div>

                                            <?php if (!empty($sess['reinspection_notes'])): ?>
                                                <div style="background: #fefce8; border: 1px solid #fef08a; border-radius: 8px; padding: 6px 10px; font-size: 11px; color: #854d0e;">
                                                    <b>Catatan Re-inspeksi:</b> <?= htmlspecialchars($sess['reinspection_notes']) ?>
                                                </div>
                                            <?php endif; ?>

                                            <!-- Lots list table -->
                                            <div style="overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff;">
                                                <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
                                                    <thead style="background: #f8fafc; color: #64748b; font-weight: 700; border-bottom: 1px solid #e2e8f0;">
                                                        <tr>
                                                            <th style="padding: 7px 12px; width: 35px; text-align: center;">No</th>
                                                            <th style="padding: 7px 12px; min-width: 130px;">Lot Number</th>
                                                            <th style="padding: 7px 12px; min-width: 130px;">Ref Number</th>
                                                            <th style="padding: 7px 12px; text-align: right; width: 90px;">Qty</th>
                                                            <th style="padding: 7px 12px; text-align: center; width: 100px;">Sampel (pcs)</th>
                                                            <th style="padding: 7px 12px; text-align: center; width: 75px;">NG</th>
                                                            <th style="padding: 7px 12px; min-width: 110px;">Waktu Scan</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php if (empty($sess['lots'])): ?>
                                                            <tr><td colspan="7" style="padding: 8px; text-align: center; color: #94a3b8;">Tidak ada catatan rincian box.</td></tr>
                                                        <?php else: ?>
                                                            <?php foreach ($sess['lots'] as $bIdx => $bLot): 
                                                                $hasDefects = !empty($bLot['defects']);
                                                                $lotNgTotal = 0;
                                                                if ($hasDefects) {
                                                                    foreach ($bLot['defects'] as $df) { $lotNgTotal += (int)$df['qty_ng']; }
                                                                } else {
                                                                    $lotNgTotal = (int)($bLot['ng_count'] ?? 0);
                                                                }
                                                                $isReinspect = !empty($bLot['is_reinspected_attempt']);
                                                                $isInitial = !empty($bLot['is_initial_attempt']);
                                                                $isReplaced = (!empty($bLot['lot_status']) && $bLot['lot_status'] === 'replaced') || !empty($bLot['is_replaced']);
                                                                $isReplacementBox = !empty($bLot['is_replacement_box']) || (isset($bLot['remarks']) && strpos($bLot['remarks'], 'Box Pengganti') !== false);
                                                            ?>
                                                                <tr style="border-bottom: <?= $hasDefects ? '1px dashed #fca5a5' : ($isReinspect ? '1px dashed #86efac' : '1px solid #f8fafc') ?>; background: <?= $hasDefects ? '#fffbfb' : ($isReinspect ? '#f0fdf4' : 'transparent') ?>;">
                                                                    <td style="padding: 7px 12px; text-align: center; color: #94a3b8; font-weight: 700;"><?= $bIdx + 1 ?></td>
                                                                    <td style="padding: 7px 12px; font-family: monospace; color: #334155;">
                                                                        <?= htmlspecialchars($bLot['lot_number'] ?: '-') ?>
                                                                    </td>
                                                                    <td style="padding: 7px 12px; font-family: monospace; font-weight: 800; color: #0284c7;">
                                                                        <?= htmlspecialchars($bLot['ref_number'] ?: '-') ?>
                                                                        <?php if ($isReinspect): ?>
                                                                            <span style="display: inline-block; margin-left: 5px; font-family: sans-serif; font-size: 9px; font-weight: 800; background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; padding: 1px 5px; border-radius: 4px; vertical-align: middle;">
                                                                                RE-INSPEKSI
                                                                            </span>
                                                                        <?php elseif ($isInitial): ?>
                                                                            <span style="display: inline-block; margin-left: 5px; font-family: sans-serif; font-size: 9px; font-weight: 800; background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; padding: 1px 5px; border-radius: 4px; vertical-align: middle;">
                                                                                UJI AWAL (NG)
                                                                            </span>
                                                                        <?php elseif ($isReplaced): ?>
                                                                            <span style="display: inline-block; margin-left: 5px; font-family: sans-serif; font-size: 9px; font-weight: 800; background: #fffbeb; color: #b45309; border: 1px solid #fde68a; padding: 1px 5px; border-radius: 4px; vertical-align: middle;">
                                                                                DIGANTIKAN
                                                                            </span>
                                                                        <?php elseif ($isReplacementBox): ?>
                                                                            <span style="display: inline-block; margin-left: 5px; font-family: sans-serif; font-size: 9px; font-weight: 800; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 1px 5px; border-radius: 4px; vertical-align: middle;">
                                                                                BOX PENGGANTI
                                                                            </span>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                    <td style="padding: 7px 12px; text-align: right; font-family: monospace; font-weight: 700; color: #0f172a;">
                                                                        <?= number_format($bLot['qty']) ?> pcs
                                                                    </td>
                                                                    <td style="padding: 7px 12px; text-align: center; font-family: monospace; font-weight: 700; color: #2563eb;">
                                                                        <?= (int)$bLot['computed_sample_size'] ?> pcs
                                                                    </td>
                                                                    <td style="padding: 7px 12px; text-align: center;">
                                                                        <span style="font-family: monospace; font-size: 11px; font-weight: 800; padding: 2px 7px; border-radius: 4px; <?= $lotNgTotal > 0 ? 'background: #fef2f2; color: #dc2626; border: 1px solid #fecaca;' : 'background: #f0fdf4; color: #16a34a; border: 1px solid #dcfce7;' ?>">
                                                                            <?= $lotNgTotal ?> / <?= (int)$bLot['computed_reject_number'] ?>
                                                                        </span>
                                                                    </td>
                                                                    <td style="padding: 7px 12px; color: #64748b;">
                                                                        <?= date('H:i', strtotime($bLot['scanned_at'])) ?> WIB
                                                                    </td>
                                                                </tr>
                                                                <?php if ($hasDefects): ?>
                                                                    <tr style="background: #fff5f5; border-bottom: 2px solid #fecaca;">
                                                                        <td colspan="7" style="padding: 6px 12px 8px 16px;">
                                                                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                                                                <div style="font-size: 10px; font-weight: 800; color: #991b1b; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 4px;">
                                                                                    <svg style="width: 12px; height: 12px; color: #dc2626;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                                                                    <span>Temuan Cacat pada Pemeriksaan Awal (<?= count($bLot['defects']) ?> defect):</span>
                                                                                </div>
                                                                                <?php foreach ($bLot['defects'] as $df): ?>
                                                                                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 11px; background: #ffffff; padding: 5px 10px; border-radius: 5px; border: 1px solid #fee2e2; flex-wrap: wrap;">
                                                                                        <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                                                                            <span style="font-weight: 800; color: #dc2626;">• <?= htmlspecialchars($df['defect_name']) ?>:</span>
                                                                                            <span style="font-family: monospace; font-weight: 800; color: #b91c1c; background: #fee2e2; padding: 1px 6px; border-radius: 3px;">
                                                                                                <?= (int)$df['qty_ng'] ?> pcs
                                                                                            </span>
                                                                                            <?php if (!empty($df['remark'])): ?>
                                                                                                <span style="color: #64748b; font-style: italic; font-size: 10px;">
                                                                                                    (<?= htmlspecialchars($df['remark']) ?>)
                                                                                                </span>
                                                                                            <?php endif; ?>
                                                                                        </div>
                                                                                        <div style="display: flex; align-items: center; gap: 10px; font-size: 10px; color: #475569;">
                                                                                            <span>
                                                                                                <span style="color: #64748b;">Inspector:</span> 
                                                                                                <strong style="color: #0f172a;"><?= htmlspecialchars($df['inspector_name'] ?? 'Inspector') ?></strong>
                                                                                            </span>
                                                                                            <span style="background: #f1f5f9; color: #334155; padding: 1px 6px; border-radius: 4px; font-family: monospace; font-weight: 700; border: 1px solid #e2e8f0;">
                                                                                                <?= !empty($df['defect_created_at']) ? date('H:i \W\I\B', strtotime($df['defect_created_at'])) : '-' ?>
                                                                                            </span>
                                                                                        </div>
                                                                                    </div>
                                                                                <?php endforeach; ?>
                                                                            </div>
                                                                        </td>
                                                                    </tr>
                                                                <?php endif; ?>
                                                                <?php if ($isReinspect): ?>
                                                                    <tr style="background: #f0fdf4; border-bottom: 2px solid #bbf7d0;">
                                                                        <td colspan="7" style="padding: 6px 12px 8px 16px;">
                                                                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                                                                <div style="font-size: 10px; font-weight: 800; color: #166534; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 4px;">
                                                                                    <svg style="width: 13px; height: 13px; color: #16a34a;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                                                    <span>Hasil Verifikasi & Re-inspeksi: Nol Cacat / Defect (PASSED)</span>
                                                                                </div>
                                                                                <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 11px; background: #ffffff; padding: 5px 10px; border-radius: 5px; border: 1px solid #dcfce7; flex-wrap: wrap;">
                                                                                    <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                                                                        <span style="font-weight: 800; color: #15803d;">Tindakan:</span>
                                                                                        <span style="color: #1e293b;"><?= htmlspecialchars($bLot['action_notes'] ?: 'Part NG disortir dan ditukar dengan part bagus -> Dilakukan re-inspeksi sampel fisik dan lolos uji.') ?></span>
                                                                                    </div>
                                                                                    <div style="display: flex; align-items: center; gap: 10px; font-size: 10px; color: #475569;">
                                                                                        <span>
                                                                                            <span style="color: #64748b;">Inspector:</span> 
                                                                                            <strong style="color: #0f172a;"><?= htmlspecialchars($bLot['actioned_by_name'] ?? 'Inspector') ?></strong>
                                                                                        </span>
                                                                                        <span style="background: #f1f5f9; color: #334155; padding: 1px 6px; border-radius: 4px; font-family: monospace; font-weight: 700; border: 1px solid #e2e8f0;">
                                                                                            <?= !empty($bLot['action_at']) ? date('H:i \W\I\B', strtotime($bLot['action_at'])) : date('H:i \W\I\B', strtotime($bLot['scanned_at'])) ?>
                                                                                        </span>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                        </td>
                                                                    </tr>
                                                                <?php endif; ?>
                                                            <?php endforeach; ?>
                                                        <?php endif; ?>
                                                    </tbody>
                                                </table>
                                            </div>

                                            <!-- Defects box -->
                                            <?php if (!empty($sess['unmatched_defects'])): ?>
                                                <div style="background: #fff5f5; border: 1px solid #fed7d7; border-radius: 8px; padding: 10px 14px;">
                                                    <div style="font-size: 11px; font-weight: 800; color: #9b2c2c; margin-bottom: 6px; display: flex; align-items: center; gap: 5px;">
                                                        <svg style="width: 13px; height: 13px; color: #dc2626;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                                        <span>Temuan Cacat Sesi (Tanpa Mapping Lot):</span>
                                                    </div>
                                                    <div style="display: flex; flex-direction: column; gap: 4px;">
                                                        <?php foreach ($sess['unmatched_defects'] as $ng): ?>
                                                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 11px; background: #fff; padding: 6px 10px; border-radius: 6px; border: 1px solid #fee2e2; flex-wrap: wrap;">
                                                                <div style="display: flex; align-items: center; gap: 6px;">
                                                                    <span style="font-weight: 800; color: #dc2626;">• <?= htmlspecialchars($ng['defect_name']) ?>:</span>
                                                                    <span style="font-family: monospace; font-weight: 900; color: #b91c1c; background: #fee2e2; padding: 1px 6px; border-radius: 3px;"><?= (int)$ng['qty_ng'] ?> pcs</span>
                                                                    <span style="color: #64748b; font-size: 10px;">(Posisi Lot: <?= htmlspecialchars($ng['lot_number']) ?>, Ref: <?= htmlspecialchars($ng['ref_number']) ?>)</span>
                                                                </div>
                                                                <div style="display: flex; align-items: center; gap: 10px; font-size: 10px; color: #475569;">
                                                                    <span><span style="color: #64748b;">Inspector:</span> <strong style="color: #0f172a;"><?= htmlspecialchars($ng['inspector_name'] ?? 'Inspector') ?></strong></span>
                                                                    <span style="background: #f1f5f9; color: #334155; padding: 1px 6px; border-radius: 4px; font-family: monospace; font-weight: 700; border: 1px solid #e2e8f0;"><?= !empty($ng['defect_created_at']) ? date('H:i \W\I\B', strtotime($ng['defect_created_at'])) : '-' ?></span>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php elseif (empty($sess['ng_records'])): ?>
                                                <div style="font-size: 11px; color: #166534; background: #f0fdf4; border: 1px solid #dcfce7; border-radius: 6px; padding: 6px 12px; display: inline-flex; align-items: center; gap: 5px;">
                                                    <svg style="width: 13px; height: 13px; color: #16a34a;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                                    <span><b>All Samples OK:</b> Seluruh sample memenuhi standar mutu (0 cacat ditemukan).</span>
                                                </div>
                                            <?php endif; ?>

                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <?php endif; ?>

    </main>

<!-- Client-side Interactive Logic (Dual-View Tabs, Accordion Toggle, & Realtime Search) -->
<script>
var isAllExpanded = false;

function switchReviewMode(mode) {
    var btnK = document.getElementById('btn-view-kanban');
    var btnS = document.getElementById('btn-view-session');
    var conK = document.getElementById('container-view-kanban');
    var conS = document.getElementById('container-view-session');

    if (mode === 'kanban') {
        btnK.className = 'active-tab-btn bg-blue-600 text-white shadow-xs';
        btnK.style.background = '#2563eb';
        btnK.style.color = '#ffffff';

        btnS.className = 'inactive-tab-btn text-slate-600 hover:text-slate-900 hover:bg-slate-200/70';
        btnS.style.background = 'transparent';
        btnS.style.color = '#475569';

        conK.classList.remove('hidden');
        conS.classList.add('hidden');
    } else {
        btnS.className = 'active-tab-btn bg-blue-600 text-white shadow-xs';
        btnS.style.background = '#2563eb';
        btnS.style.color = '#ffffff';

        btnK.className = 'inactive-tab-btn text-slate-600 hover:text-slate-900 hover:bg-slate-200/70';
        btnK.style.background = 'transparent';
        btnK.style.color = '#475569';

        conS.classList.remove('hidden');
        conK.classList.add('hidden');
    }
}

function toggleAccordion(accId) {
    var row = document.getElementById(accId);
    if (!row) return;

    var isHidden = row.classList.contains('hidden');
    var parentRow = row.previousElementSibling;
    var caret = parentRow ? parentRow.querySelector('.caret-icon') : null;
    var btnText = parentRow ? parentRow.querySelector('.btn-text') : null;

    if (isHidden) {
        row.classList.remove('hidden');
        if (caret) caret.style.transform = 'rotate(180deg)';
        if (btnText) btnText.textContent = 'Tutup';
    } else {
        row.classList.add('hidden');
        if (caret) caret.style.transform = 'rotate(0deg)';
        if (btnText) btnText.textContent = 'Rincian';
    }
}

function toggleAllAccordions() {
    isAllExpanded = !isAllExpanded;
    var subRows = document.querySelectorAll('.accordion-sub-row');
    var carets  = document.querySelectorAll('.caret-icon');
    var btnTexts = document.querySelectorAll('.btn-text');
    var txtBtn  = document.getElementById('txt-toggle-all');

    subRows.forEach(function(r) {
        if (isAllExpanded) {
            r.classList.remove('hidden');
        } else {
            r.classList.add('hidden');
        }
    });

    carets.forEach(function(c) {
        c.style.transform = isAllExpanded ? 'rotate(180deg)' : 'rotate(0deg)';
    });

    btnTexts.forEach(function(t) {
        t.textContent = isAllExpanded ? 'Tutup' : 'Rincian';
    });

    if (txtBtn) {
        txtBtn.textContent = isAllExpanded ? 'Collapse All' : 'Expand All';
    }
}

// Universal Search Filter
document.addEventListener('DOMContentLoaded', function () {
    var searchInput = document.getElementById('review-search');
    if (!searchInput) return;

    searchInput.addEventListener('input', function () {
        var term = this.value.toLowerCase().trim();

        var rows = document.querySelectorAll('.review-row');
        rows.forEach(function (r) {
            var text = r.textContent.toLowerCase();
            var nextAcc = r.nextElementSibling;
            if (nextAcc && nextAcc.classList.contains('accordion-sub-row')) {
                text += ' ' + nextAcc.textContent.toLowerCase();
            }

            var match = (term === '' || text.indexOf(term) !== -1);
            r.style.display = match ? '' : 'none';
            if (nextAcc && nextAcc.classList.contains('accordion-sub-row')) {
                if (!match) {
                    nextAcc.style.display = 'none';
                } else {
                    nextAcc.style.display = '';
                }
            }
        });
    });
});
</script>

<!-- Offline Chart.js Vendor -->
<script src="<?= base_url('assets/js/vendor/chart.min.js') ?>"></script>

<script>
// ── Daily Donut Charts Controller ─────────────────────────────────────────────
var rawDailyDefects = <?= $jsonDailyDefects ?>;
var rawDailyModels  = <?= $jsonDailyModels ?>;
var rawDailyParts   = <?= $jsonDailyParts ?>;
var dailyDefectColors = <?= $jsonDefectColors ?>;
var dailyModelColors  = <?= $jsonModelColors ?>;
var dailyPartColors   = <?= $jsonPartColors ?>;

var chartDailyDefectInstance = null;
var chartDailyModelInstance  = null;
var chartDailyPartInstance   = null;

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function switchDailyTopRank(rank) {
    rank = parseInt(rank) || 5;
    localStorage.setItem('oqc_daily_top_rank', rank);

    // Update Filter Buttons Styling
    var btn5  = document.getElementById('btn-daily-rank-5');
    var btn10 = document.getElementById('btn-daily-rank-10');

    if (btn5 && btn10) {
        if (rank === 10) {
            btn10.className = 'bg-blue-600 text-white shadow-xs';
            btn10.style.background = '#2563eb';
            btn10.style.color = '#ffffff';
            btn10.style.fontWeight = '800';

            btn5.className = 'hover:text-slate-900 hover:bg-slate-200/60';
            btn5.style.background = 'transparent';
            btn5.style.color = '#64748b';
            btn5.style.fontWeight = '700';
        } else {
            btn5.className = 'bg-blue-600 text-white shadow-xs';
            btn5.style.background = '#2563eb';
            btn5.style.color = '#ffffff';
            btn5.style.fontWeight = '800';

            btn10.className = 'hover:text-slate-900 hover:bg-slate-200/60';
            btn10.style.background = 'transparent';
            btn10.style.color = '#64748b';
            btn10.style.fontWeight = '700';
        }
    }

    // Update Card Titles
    var titleDefect = document.getElementById('title-card-defect');
    var titleModel  = document.getElementById('title-card-model');
    var titlePart   = document.getElementById('title-card-part');

    if (titleDefect) titleDefect.textContent = 'Top ' + rank + ' Jenis Defect';
    if (titleModel)  titleModel.textContent  = 'Top ' + rank + ' Model (NG)';
    if (titlePart)   titlePart.textContent   = 'Top ' + rank + ' Part (NG)';

    // Slice Data Arrays
    var slicedDefects = rawDailyDefects.slice(0, rank);
    var slicedModels  = rawDailyModels.slice(0, rank);
    var slicedParts   = rawDailyParts.slice(0, rank);

    // 1. Render Defect Donut
    var conDefect   = document.getElementById('container-donut-defect');
    var emptyDefect = document.getElementById('empty-donut-defect');
    var centerDefect = document.getElementById('center-val-defect');
    var listDefect  = document.getElementById('legend-list-defect');

    if (slicedDefects.length === 0) {
        if (conDefect) conDefect.style.display = 'none';
        if (emptyDefect) emptyDefect.style.display = 'flex';
    } else {
        if (conDefect) conDefect.style.display = 'flex';
        if (emptyDefect) emptyDefect.style.display = 'none';

        var totalD = slicedDefects.reduce(function(acc, x) { return acc + Number(x.total_qty || 0); }, 0);
        if (centerDefect) centerDefect.textContent = totalD.toLocaleString();

        var labelsD = slicedDefects.map(function(x) { return x.defect_name; });
        var countsD = slicedDefects.map(function(x) { return Number(x.total_qty || 0); });
        var colorsD = slicedDefects.map(function(_, idx) { return dailyDefectColors[idx % dailyDefectColors.length]; });

        if (chartDailyDefectInstance) {
            chartDailyDefectInstance.data.labels = labelsD;
            chartDailyDefectInstance.data.datasets[0].data = countsD;
            chartDailyDefectInstance.data.datasets[0].backgroundColor = colorsD;
            chartDailyDefectInstance.update();
        } else {
            var canvasD = document.getElementById('chartDailyDefect');
            if (canvasD) {
                chartDailyDefectInstance = new Chart(canvasD, {
                    type: 'doughnut',
                    data: {
                        labels: labelsD,
                        datasets: [{
                            data: countsD,
                            backgroundColor: colorsD,
                            borderWidth: 2.5,
                            borderColor: '#ffffff',
                            hoverOffset: 4,
                            borderRadius: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '66%',
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: function(c) {
                                        var t = c.dataset.data.reduce(function(a, b) { return a + b; }, 0) || 1;
                                        return ' ' + c.label + ': ' + Number(c.raw).toLocaleString() + ' pcs (' + Math.round((c.raw / t) * 100) + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            }
        }

        // Render Legend List
        if (listDefect) {
            var htmlD = '';
            slicedDefects.forEach(function(item, idx) {
                var qty = Number(item.total_qty || 0);
                var pct = totalD > 0 ? (qty / totalD * 100).toFixed(1) : '0';
                var col = dailyDefectColors[idx % dailyDefectColors.length];
                htmlD += '<div style="display:flex;align-items:center;justify-content:space-between;font-size:10.5px;gap:6px;padding:3px 6px;border-radius:6px;background:#f8fafc;border-left:2.5px solid ' + col + ';">' +
                    '<span style="display:flex;align-items:center;gap:5px;color:#334155;flex:1;min-width:0;">' +
                        '<span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:700;" title="' + escapeHtml(item.defect_name) + '">' + escapeHtml(item.defect_name) + '</span>' +
                    '</span>' +
                    '<span style="font-weight:800;color:#0f172a;flex-shrink:0;">' + qty.toLocaleString() + ' <span style="font-weight:500;color:#64748b;">pcs (' + pct + '%)</span></span>' +
                '</div>';
            });
            listDefect.innerHTML = htmlD;
        }
    }

    // 2. Render Model Donut
    var conModel   = document.getElementById('container-donut-model');
    var emptyModel = document.getElementById('empty-donut-model');
    var centerModel = document.getElementById('center-val-model');
    var listModel  = document.getElementById('legend-list-model');

    if (slicedModels.length === 0) {
        if (conModel) conModel.style.display = 'none';
        if (emptyModel) emptyModel.style.display = 'flex';
    } else {
        if (conModel) conModel.style.display = 'flex';
        if (emptyModel) emptyModel.style.display = 'none';

        var totalM = slicedModels.reduce(function(acc, x) { return acc + Number(x.total_qty || 0); }, 0);
        if (centerModel) centerModel.textContent = slicedModels.length;

        var labelsM = slicedModels.map(function(x) { return x.model_name; });
        var countsM = slicedModels.map(function(x) { return Number(x.total_qty || 0); });
        var colorsM = slicedModels.map(function(_, idx) { return dailyModelColors[idx % dailyModelColors.length]; });

        if (chartDailyModelInstance) {
            chartDailyModelInstance.data.labels = labelsM;
            chartDailyModelInstance.data.datasets[0].data = countsM;
            chartDailyModelInstance.data.datasets[0].backgroundColor = colorsM;
            chartDailyModelInstance.update();
        } else {
            var canvasM = document.getElementById('chartDailyModel');
            if (canvasM) {
                chartDailyModelInstance = new Chart(canvasM, {
                    type: 'doughnut',
                    data: {
                        labels: labelsM,
                        datasets: [{
                            data: countsM,
                            backgroundColor: colorsM,
                            borderWidth: 2.5,
                            borderColor: '#ffffff',
                            hoverOffset: 4,
                            borderRadius: 3
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '52%',
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: function(c) {
                                        var t = c.dataset.data.reduce(function(a, b) { return a + b; }, 0) || 1;
                                        return ' Model ' + c.label + ': ' + Number(c.raw).toLocaleString() + ' pcs (' + Math.round((c.raw / t) * 100) + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            }
        }

        // Render Model Legend List
        if (listModel) {
            var htmlM = '';
            slicedModels.forEach(function(item, idx) {
                var qty = Number(item.total_qty || 0);
                var pct = totalM > 0 ? (qty / totalM * 100).toFixed(1) : '0';
                var col = dailyModelColors[idx % dailyModelColors.length];
                htmlM += '<div style="display:flex;align-items:center;justify-content:space-between;font-size:10.5px;gap:6px;padding:3px 6px;border-radius:6px;background:#f8fafc;border-left:2.5px solid ' + col + ';">' +
                    '<span style="display:flex;align-items:center;gap:5px;color:#334155;flex:1;min-width:0;">' +
                        '<span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:700;" title="' + escapeHtml(item.model_name) + '">' + escapeHtml(item.model_name) + '</span>' +
                    '</span>' +
                    '<span style="font-weight:800;color:#0f172a;flex-shrink:0;">' + qty.toLocaleString() + ' <span style="font-weight:500;color:#64748b;">pcs (' + pct + '%)</span></span>' +
                '</div>';
            });
            listModel.innerHTML = htmlM;
        }
    }

    // 3. Render Part Donut
    var conPart   = document.getElementById('container-donut-part');
    var emptyPart = document.getElementById('empty-donut-part');
    var centerPart = document.getElementById('center-val-part');
    var listPart  = document.getElementById('legend-list-part');

    if (slicedParts.length === 0) {
        if (conPart) conPart.style.display = 'none';
        if (emptyPart) emptyPart.style.display = 'flex';
    } else {
        if (conPart) conPart.style.display = 'flex';
        if (emptyPart) emptyPart.style.display = 'none';

        var totalP = slicedParts.reduce(function(acc, x) { return acc + Number(x.total_qty || 0); }, 0);
        if (centerPart) centerPart.textContent = slicedParts.length;

        var labelsP = slicedParts.map(function(x) { return x.part_name || x.part_code || 'Unknown'; });
        var countsP = slicedParts.map(function(x) { return Number(x.total_qty || 0); });
        var colorsP = slicedParts.map(function(_, idx) { return dailyPartColors[idx % dailyPartColors.length]; });

        if (chartDailyPartInstance) {
            chartDailyPartInstance.data.labels = labelsP;
            chartDailyPartInstance.data.datasets[0].data = countsP;
            chartDailyPartInstance.data.datasets[0].backgroundColor = colorsP;
            chartDailyPartInstance.update();
        } else {
            var canvasP = document.getElementById('chartDailyPart');
            if (canvasP) {
                chartDailyPartInstance = new Chart(canvasP, {
                    type: 'doughnut',
                    data: {
                        labels: labelsP,
                        datasets: [{
                            data: countsP,
                            backgroundColor: colorsP,
                            borderWidth: 2,
                            borderColor: '#ffffff',
                            hoverOffset: 4,
                            borderRadius: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '74%',
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: function(c) {
                                        var t = c.dataset.data.reduce(function(a, b) { return a + b; }, 0) || 1;
                                        return ' Part ' + c.label + ': ' + Number(c.raw).toLocaleString() + ' pcs (' + Math.round((c.raw / t) * 100) + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            }
        }

        // Render Part Legend List
        if (listPart) {
            var htmlP = '';
            slicedParts.forEach(function(item, idx) {
                var qty = Number(item.total_qty || 0);
                var pct = totalP > 0 ? (qty / totalP * 100).toFixed(1) : '0';
                var col = dailyPartColors[idx % dailyPartColors.length];
                var dispPart = item.part_name || item.part_code || 'Unknown';
                htmlP += '<div style="display:flex;align-items:center;justify-content:space-between;font-size:10.5px;gap:6px;padding:3px 6px;border-radius:6px;background:#f8fafc;border-left:2.5px solid ' + col + ';">' +
                    '<span style="display:flex;align-items:center;gap:5px;color:#334155;flex:1;min-width:0;">' +
                        '<span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:700;" title="' + escapeHtml(dispPart) + '">' + escapeHtml(dispPart) + '</span>' +
                    '</span>' +
                    '<span style="font-weight:800;color:#0f172a;flex-shrink:0;">' + qty.toLocaleString() + ' <span style="font-weight:500;color:#64748b;">pcs (' + pct + '%)</span></span>' +
                '</div>';
            });
            listPart.innerHTML = htmlP;
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    var savedRank = parseInt(localStorage.getItem('oqc_daily_top_rank')) || 5;
    switchDailyTopRank(savedRank);
});

// ═══════════════════════════════════════════════════════════════════════════
// SUPERVISOR APPROVAL (ACC) HANDLERS
// ═══════════════════════════════════════════════════════════════════════════
function submitApproval(payload) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Memproses Persetujuan...',
            text: 'Mohon tunggu, sistem sedang memperbarui basis data.',
            allowOutsideClick: false,
            didOpen: function() {
                Swal.showLoading();
            }
        });
    }

    var formData = new FormData();
    for (var key in payload) {
        formData.append(key, payload[key]);
    }
    formData.append('ajax', '1');

    fetch('<?= base_url("modules/daily_report/approve.php") ?>', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
    })
    .then(function(res) {
        return res.json();
    })
    .then(function(res) {
        if (res.success) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'success',
                    title: 'Berhasil',
                    text: res.message,
                    confirmButtonColor: '#2563eb'
                }).then(function() {
                    window.location.reload();
                });
            } else {
                alert(res.message);
                window.location.reload();
            }
        } else {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal',
                    text: res.message || 'Terjadi kesalahan sistem saat memperbarui status persetujuan.',
                    confirmButtonColor: '#2563eb'
                });
            } else {
                alert(res.message || 'Gagal memproses persetujuan.');
            }
        }
    })
    .catch(function(err) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'error',
                title: 'Gangguan Jaringan',
                text: 'Tidak dapat menghubungi server. Silakan coba beberapa saat lagi.',
                confirmButtonColor: '#2563eb'
            });
        } else {
            alert('Tidak dapat menghubungi server.');
        }
    });
}

function confirmApproveSingle(sessionId) {
    if (typeof Swal === 'undefined') {
        if (confirm('Apakah Anda yakin ingin menyetujui (ACC) Sesi #' + sessionId + '?')) {
            submitApproval({
                action: 'approve_single',
                session_id: sessionId,
                notes: 'Disetujui oleh supervisor',
                date: '<?= htmlspecialchars($dateParam) ?>'
            });
        }
        return;
    }

    Swal.fire({
        title: 'Persetujuan Sesi Inspeksi',
        text: 'Apakah Anda yakin ingin memberikan persetujuan (ACC) atas hasil pemeriksaan Sesi #' + sessionId + '?',
        icon: 'question',
        input: 'text',
        inputLabel: 'Catatan Persetujuan (Opsional)',
        inputPlaceholder: 'Tuliskan catatan supervisor bila diperlukan...',
        showCancelButton: true,
        confirmButtonColor: '#2563eb',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Ya, Setujui Sesi',
        cancelButtonText: 'Batal'
    }).then(function(result) {
        if (result.isConfirmed) {
            submitApproval({
                action: 'approve_single',
                session_id: sessionId,
                notes: result.value || 'Disetujui oleh supervisor',
                date: '<?= htmlspecialchars($dateParam) ?>'
            });
        }
    });
}

function confirmUnapprove(sessionId) {
    if (typeof Swal === 'undefined') {
        if (confirm('Apakah Anda yakin ingin membatalkan persetujuan atas Sesi #' + sessionId + '?')) {
            submitApproval({
                action: 'unapprove_single',
                session_id: sessionId,
                date: '<?= htmlspecialchars($dateParam) ?>'
            });
        }
        return;
    }

    Swal.fire({
        title: 'Batalkan Persetujuan',
        text: 'Apakah Anda yakin ingin membatalkan persetujuan atas Sesi #' + sessionId + '? Status akan kembali menjadi belum di-ACC.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Ya, Batalkan Persetujuan',
        cancelButtonText: 'Kembali'
    }).then(function(result) {
        if (result.isConfirmed) {
            submitApproval({
                action: 'unapprove_single',
                session_id: sessionId,
                date: '<?= htmlspecialchars($dateParam) ?>'
            });
        }
    });
}

function confirmApproveAll(dateStr, pendingCount) {
    if (typeof Swal === 'undefined') {
        if (confirm('Apakah Anda yakin ingin menyetujui seluruh (' + pendingCount + ') sesi inspeksi pada tanggal ' + dateStr + '?')) {
            submitApproval({
                action: 'approve_all_date',
                date: dateStr,
                notes: 'Persetujuan massal harian oleh supervisor'
            });
        }
        return;
    }

    Swal.fire({
        title: 'Persetujuan Seluruh Sesi (ACC All)',
        html: 'Anda akan memberikan persetujuan sekaligus untuk <b>' + pendingCount + ' sesi inspeksi</b> yang belum di-ACC pada tanggal <b>' + dateStr + '</b>.<br><br>Pastikan seluruh hasil pemeriksaan dan temuan cacat telah ditinjau dengan seksama.',
        icon: 'question',
        input: 'text',
        inputLabel: 'Catatan Persetujuan Massal (Opsional)',
        inputPlaceholder: 'Tuliskan catatan supervisor bila diperlukan...',
        showCancelButton: true,
        confirmButtonColor: '#2563eb',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Ya, Setujui Semua (' + pendingCount + ' Sesi)',
        cancelButtonText: 'Batal'
    }).then(function(result) {
        if (result.isConfirmed) {
            submitApproval({
                action: 'approve_all_date',
                date: dateStr,
                notes: result.value || 'Persetujuan massal harian oleh supervisor'
            });
        }
    });
}
</script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
