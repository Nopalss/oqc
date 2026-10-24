<?php
/**
 * Visual Excel Report Generator — Detail Laporan Harian OQC (Supervisor Executive View)
 * Mirroring tampilan web modules/daily_report/detail.php:
 * 1. Sheet 1: Rincian Per Lot (Flat Data Table & Defect Matrix Crosstab)
 *
 * PT. Surya Technology Industri — Outgoing Quality Control Division
 */

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;

$pdo = getDB();
if (!$pdo) {
    die("Koneksi database gagal");
}

$startDate = sanitize($_GET['start_date'] ?? $_GET['date'] ?? date('Y-m-01'));
$endDate   = sanitize($_GET['end_date']   ?? $_GET['date'] ?? date('Y-m-d'));
$search    = sanitize($_GET['search']     ?? '');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
    $startDate = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    $endDate = date('Y-m-d');
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

// ── 1. Fetch Data ─────────────────────────────────────────────────────────────
$rawSessions     = [];
$sessionTimeline = [];
$kanbanGroups    = [];
$ssGroups        = [];

$daySummary = [
    'total_sesi'              => 0,
    'total_kanban_items'      => 0,
    'total_ss_items'          => 0,
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

try {
    $params = [':sd' => $startDate, ':ed' => $endDate, ':sd_chk' => $startDate, ':ed_chk' => $endDate];
    $searchWhere = "";
    if (!empty($search)) {
        $searchWhere = " AND (
            did.part_code LIKE :s1 
            OR did.lot_number LIKE :s2 
            OR did.part_name LIKE :s3 
            OR ki.item_code LIKE :s4 
            OR ki.item_description LIKE :s5 
            OR ki.kanban_no LIKE :s6 
            OR mp.part_code LIKE :s7 
            OR mp.part_name LIKE :s8 
            OR EXISTS (
                SELECT 1 FROM inspection_session_lots isl_s 
                WHERE isl_s.inspection_session_id = ss.id 
                  AND (isl_s.ref_number LIKE :s9 OR isl_s.lot_number LIKE :s10)
            )
        )";
        $like = '%' . $search . '%';
        $params[':s1'] = $like; $params[':s2'] = $like; $params[':s3'] = $like;
        $params[':s4'] = $like; $params[':s5'] = $like; $params[':s6'] = $like;
        $params[':s7'] = $like; $params[':s8'] = $like; $params[':s9'] = $like;
        $params[':s10'] = $like;
    }

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
            COALESCE(did.part_name, ki.item_description, mp.part_name, '-') AS part_name,
            COALESCE(mm.name, mp.model, '-') AS model_name
        FROM inspection_sessions ss
        LEFT JOIN users u ON u.id = ss.inspector_id
        LEFT JOIN users u_app ON u_app.id = ss.approved_by
        LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
        LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
        LEFT JOIN master_parts mp ON (mp.id = ss.part_id OR UPPER(mp.part_code) = UPPER(COALESCE(did.part_code, ki.item_code)))
        LEFT JOIN master_models mm ON (mm.id = mp.model_id)
        WHERE (DATE(ss.started_at) BETWEEN :sd AND :ed OR EXISTS (
            SELECT 1 FROM inspection_session_lots isl_chk 
            WHERE isl_chk.inspection_session_id = ss.id AND DATE(isl_chk.created_at) BETWEEN :sd_chk AND :ed_chk
        ))
        AND (ss.original_ss_session_id IS NULL OR ss.original_ss_session_id = 0)
        AND (ki.remark IS NULL OR (ki.remark NOT LIKE '%AUTO-SS-SESS-%' AND ki.remark NOT LIKE '%Split%'))
        AND (ss.approval_notes IS NULL OR ss.approval_notes NOT LIKE '%Direct Close%')
        AND NOT EXISTS (
            SELECT 1 FROM inspection_session_lots isl_dc 
            WHERE isl_dc.inspection_session_id = ss.id 
              AND (isl_dc.ref_number = 'DIRECT-PASS' OR isl_dc.ref_number LIKE '%DIRECT-PASS%' OR isl_dc.remarks LIKE '%Direct Close%')
        )
        {$searchWhere}
        ORDER BY ss.started_at ASC, ss.id ASC
    ");
    $stmtSessions->execute($params);
    $rawSessions = $stmtSessions->fetchAll(PDO::FETCH_ASSOC);

    $sessions = [];
    foreach ($rawSessions as $s) {
        $sessions[(int)$s['session_id']] = $s;
    }

    if (!empty($rawSessions)) {
        $sessIds = array_map(fn($s) => (int)$s['session_id'], $rawSessions);
        $inSessIds = implode(',', $sessIds);

        // B. Fetch lots
        $allLotsMap = [];
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
                isl.replaced_by_lot_id,
                (SELECT COUNT(*) 
                 FROM inspection_session_lots prev_isl
                 JOIN inspection_sessions prev_s ON prev_s.id = prev_isl.inspection_session_id
                 WHERE prev_isl.lot_number = isl.lot_number
                   AND (
                       (isl.ref_number IS NOT NULL AND isl.ref_number != '' AND prev_isl.ref_number = isl.ref_number)
                       OR ((isl.ref_number IS NULL OR isl.ref_number = '') AND (prev_isl.ref_number IS NULL OR prev_isl.ref_number = ''))
                   )
                   AND prev_isl.id != isl.id
                   AND prev_isl.created_at < isl.created_at
                   AND (prev_isl.lot_result = 'rejected' OR prev_isl.ng_count > 0 OR prev_isl.lot_status IN ('ng_found', 'ng_quarantine', 'replaced'))
                ) AS has_prev_rejected
            FROM inspection_session_lots isl
            WHERE isl.inspection_session_id IN ({$inSessIds})
              AND (isl.remarks IS NULL OR (isl.remarks NOT LIKE '%Sisa Split Safety Stock%' AND isl.remarks NOT LIKE '%Sisa Kelebihan Kanban%'))
              AND (isl.ref_number IS NULL OR (isl.ref_number != 'DIRECT-PASS' AND isl.ref_number NOT LIKE '%DIRECT-PASS%'))
            ORDER BY isl.id ASC
        ");
        $rawLotsList = [];
        $sessionsWithLots = [];
        foreach ($stmtLots->fetchAll(PDO::FETCH_ASSOC) as $lRow) {
            $sId = (int)$lRow['inspection_session_id'];
            $sessionsWithLots[$sId] = true;
            $lRow['defects'] = [];

            $isSafetyStock = (($lRow['lot_result'] ?? '') === 'skipped') || (empty($lRow['sample_size']) && isset($lRow['remarks']) && stripos($lRow['remarks'], 'Alokasi Safety Stock') !== false);
            $lotQty = (int)($lRow['qty'] ?? 0);
            $lSampleSize = (int)($lRow['sample_size'] ?? 0);
            $lRejectNum = isset($lRow['reject_number']) ? (int)$lRow['reject_number'] : 0;
            
            if ($isSafetyStock) {
                $lSampleSize = 0;
                $lRejectNum = 0;
            } elseif ($lSampleSize <= 0 || $lRejectNum <= 0) {
                $aqlFb = getLotAqlFallback($lotQty, $pdo);
                if ($lSampleSize <= 0) $lSampleSize = $aqlFb['sample_size'];
                if ($lRejectNum <= 0) $lRejectNum = $aqlFb['reject_number'];
            }
            $lRow['is_safety_stock'] = $isSafetyStock;
            $lRow['computed_sample_size'] = $lSampleSize;
            $lRow['computed_reject_number'] = $lRejectNum;

            $rawLotsList[$sId][] = $lRow;
        }

        // B2. Fetch lot substitution / reinspection logs
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

        // C. Fetch NG records
        $allNgMap = [];
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
              AND ((ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0) OR ngr.is_sorted = 1)
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
                    $sortLogs = [];
                    foreach ($substByLot[$lotId] as $log) {
                        if (($log['action_type'] ?? '') === 'sort_reinspect') {
                            $sortLogs[] = $log;
                        }
                    }

                    // Sort logs chronologically by action_at / created_at ASC
                    usort($sortLogs, function($a, $b) {
                        return strtotime($a['action_at'] ?: $a['created_at']) <=> strtotime($b['action_at'] ?: $b['created_at']);
                    });

                    if (!empty($sortLogs)) {
                        $numLogs = count($sortLogs);
                        $firstLogTime = $sortLogs[0]['action_at'] ?: $sortLogs[0]['created_at'];

                        // Row 1: Initial Attempt (Always Rejected/NG)
                        $initialDefects = array_values(array_filter($lotDefects, function($df) use ($firstLogTime) {
                            $dfTime = $df['defect_created_at'] ?? ($df['created_at'] ?? null);
                            if (!empty($df['is_sorted'])) {
                                if ($firstLogTime && $dfTime && $dfTime >= $firstLogTime) {
                                    return false;
                                }
                                return true;
                            }
                            return (!$firstLogTime || ($dfTime && $dfTime < $firstLogTime));
                        }));

                        $initialNgCount = 0;
                        foreach ($initialDefects as $id) {
                            $initialNgCount += (int)($id['qty_ng'] ?? 1);
                        }
                        if ($initialNgCount === 0 && !empty($initialDefects)) {
                            $initialNgCount = count($initialDefects);
                        }

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
                        $allLotsMap[$sId][] = $attempt1;

                        // Subsequent Re-inspection Attempts (Ronde 2, Ronde 3, dst.)
                        for ($k = 0; $k < $numLogs; $k++) {
                            $log = $sortLogs[$k];
                            $startTime = $log['action_at'] ?: $log['created_at'];
                            $endTime = isset($sortLogs[$k + 1]) ? ($sortLogs[$k + 1]['action_at'] ?: $sortLogs[$k + 1]['created_at']) : null;
                            $isLatest = ($k === $numLogs - 1);
                            $roundNum = $k + 2;

                            $roundDefects = array_values(array_filter($lotDefects, function($df) use ($startTime, $endTime, $firstLogTime) {
                                $dfTime = $df['defect_created_at'] ?? ($df['created_at'] ?? null);
                                if (!empty($df['is_sorted']) && $dfTime && $dfTime < $firstLogTime) {
                                    return false;
                                }
                                if ($startTime && $dfTime && $dfTime < $startTime) return false;
                                if ($endTime && $dfTime && $dfTime >= $endTime) return false;
                                return true;
                            }));

                            $roundNgCount = 0;
                            foreach ($roundDefects as $rd) {
                                $roundNgCount += (int)($rd['qty_ng'] ?? 1);
                            }
                            if ($roundNgCount === 0 && !empty($roundDefects)) {
                                $roundNgCount = count($roundDefects);
                            }

                            $actualLotResult = strtolower($lRow['lot_result'] ?? '');
                            $rejectLimit = (int)($lRow['computed_reject_number'] ?? 1);

                            $att = $lRow;
                            $att['attempt_type'] = 'reinspection';
                            $att['attempt_label'] = "Re-inspeksi (Ronde {$roundNum})";
                            $att['scanned_at'] = $startTime ?: ($lRow['action_noted_at'] ?: $lRow['scanned_at']);
                            $att['is_reinspected_attempt'] = true;
                            $att['is_reinspected_lot'] = true;
                            $att['action_type'] = $log['action_type'];
                            $att['actioned_by_name'] = $log['actioned_by_name'];
                            $att['action_at'] = $startTime;
                            $att['defects'] = $roundDefects;
                            $att['ng_count'] = $roundNgCount;

                            if ($isLatest) {
                                if ($actualLotResult === 'passed') {
                                    $att['lot_result'] = 'passed';
                                    $att['status_badge'] = 'PASSED (Re-inspeksi)';
                                    $att['status_label'] = 'PASSED (RE-INSPEKSI)';
                                    $att['action_notes'] = $log['notes'] ?: 'Part NG disortir dan ditukar dengan part bagus -> Dilakukan re-inspeksi sampel fisik dan lolos uji.';
                                } elseif ($actualLotResult === 'rejected' || ($roundNgCount >= $rejectLimit && $rejectLimit > 0)) {
                                    $att['lot_result'] = 'rejected';
                                    $att['status_badge'] = 'REJECTED (Re-inspeksi)';
                                    $att['status_label'] = 'REJECTED (RE-INSPEKSI)';
                                    $att['action_notes'] = $log['notes'] ?: 'Part NG disortir dan diuji ulang fisik, namun tetap ditemukan cacat/NG.';
                                } else {
                                    $att['lot_result'] = 'in_progress';
                                    $att['status_badge'] = 'IN PROGRESS (Re-inspeksi)';
                                    $att['status_label'] = 'IN PROGRESS (RE-INSPEKSI)';
                                    $att['action_notes'] = $log['notes'] ?: 'Dalam proses re-inspeksi fisik.';
                                }
                            } else {
                                // Earlier re-inspection attempt that failed
                                $att['lot_result'] = 'rejected';
                                $att['status_badge'] = 'REJECTED (Re-inspeksi)';
                                $att['status_label'] = 'REJECTED (RE-INSPEKSI)';
                                $att['action_notes'] = $log['notes'] ?: 'Part NG disortir dan diuji ulang fisik, namun tetap ditemukan cacat/NG.';
                            }

                            $allLotsMap[$sId][] = $att;
                        }
                        continue;
                    }
                }

                if ($lRow['lot_status'] === 'replaced') {
                    $lRow['attempt_type'] = 'standard';
                    $lRow['is_replaced'] = true;
                    $lRow['status_badge'] = 'REJECTED';
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

                // Check if session or lot is a re-inspection
                $sess = $sessions[$sId] ?? [];
                $isReinspectionSess = !empty($lRow['has_prev_rejected'])
                    || !empty($sess['is_reinspection']) 
                    || !empty($sess['reinspection_type']) 
                    || !empty($sess['parent_session_id']) 
                    || !empty($lRow['is_reinspected_attempt']) 
                    || !empty($lRow['is_reinspected_lot'])
                    || (($lRow['attempt_type'] ?? '') === 'reinspection')
                    || (strtolower($lRow['lot_status'] ?? '') === 'reinspected')
                    || (isset($lRow['remarks']) && (strripos($lRow['remarks'], 're-inspeksi') !== false || strripos($lRow['remarks'], 'reinspeksi') !== false || strripos($lRow['remarks'], 're-inspect') !== false || strripos($lRow['remarks'], 'reinspect') !== false))
                    || (isset($sess['reinspection_notes']) && !empty($sess['reinspection_notes']))
                    || (isset($sess['remarks']) && (strripos($sess['remarks'], 're-inspeksi') !== false || strripos($sess['remarks'], 'reinspeksi') !== false));

                // DB Fallback if not detected yet
                if (!$isReinspectionSess && !empty($lRow['lot_number']) && !empty($lRow['lot_id'])) {
                    static $prevRejectedDrCache = [];
                    $cacheKey = $sId . '_' . (int)$lRow['lot_id'];
                    if (!isset($prevRejectedDrCache[$cacheKey])) {
                        try {
                            $refStr = (string)($lRow['ref_number'] ?? '');
                            $currTime = (string)($lRow['created_at'] ?? $lRow['scanned_at'] ?? date('Y-m-d H:i:s'));
                            $chkStmt = $pdo->prepare("
                                SELECT 1 
                                FROM inspection_session_lots prev_isl
                                WHERE prev_isl.lot_number = :lot_num
                                  AND (
                                      (:ref_num1 != '' AND prev_isl.ref_number = :ref_num2)
                                      OR (:ref_num3 = '' AND (prev_isl.ref_number IS NULL OR prev_isl.ref_number = ''))
                                  )
                                  AND prev_isl.id != :curr_lid
                                  AND prev_isl.created_at < :curr_created
                                  AND (prev_isl.lot_result = 'rejected' OR prev_isl.ng_count > 0 OR prev_isl.lot_status IN ('ng_found', 'ng_quarantine', 'replaced'))
                                LIMIT 1
                            ");
                            $chkStmt->execute([
                                ':lot_num'      => (string)$lRow['lot_number'],
                                ':ref_num1'     => $refStr,
                                ':ref_num2'     => $refStr,
                                ':ref_num3'     => $refStr,
                                ':curr_lid'     => (int)$lRow['lot_id'],
                                ':curr_created' => $currTime
                            ]);
                            $prevRejectedDrCache[$cacheKey] = (bool)$chkStmt->fetchColumn();
                        } catch (Exception $e) {
                            $prevRejectedDrCache[$cacheKey] = false;
                        }
                    }
                    if (!empty($prevRejectedDrCache[$cacheKey])) {
                        $isReinspectionSess = true;
                    }
                }

                if ($isReinspectionSess) {
                    $lRow['attempt_type'] = 'reinspection';
                    $lRow['is_reinspected_attempt'] = true;
                    $lRow['is_reinspected_lot'] = true;
                    $lRow['status_badge'] = ($lRow['lot_result'] === 'passed') ? 'PASSED (RE-INSPEKSI)' : (($lRow['lot_result'] === 'rejected') ? 'REJECTED (RE-INSPEKSI)' : 'IN PROGRESS (RE-INSPEKSI)');
                    $lRow['status_label'] = ($lRow['lot_result'] === 'passed') ? 'PASSED (RE-INSPEKSI)' : (($lRow['lot_result'] === 'rejected') ? 'REJECTED (RE-INSPEKSI)' : 'IN PROGRESS (RE-INSPEKSI)');
                } else {
                    $lRow['attempt_type'] = 'standard';
                }
                $allLotsMap[$sId][] = $lRow;
            }
        }

        // C2. Fetch Top Defect Types, Models, and Parts for this date
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
            error_log("Daily detail excel donut charts query error: " . $e->getMessage());
        }

        // C3. Fetch Reinspection Stats (Workload)
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

        // D. Build Timeline (Mode B)
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

            // Approval status counter
            if (!empty($sess['is_approved']) && (int)$sess['is_approved'] === 1) {
                $daySummary['total_approved_sessions']++;
            } else {
                $daySummary['total_pending_sessions']++;
            }
        }

        $daySummary['is_all_approved'] = ($daySummary['total_sesi'] > 0 && $daySummary['total_pending_sessions'] === 0);

        // E. Build Kanban & Safety Stock Groupings (Mode A)
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
                        'passed_qty'   => 0,
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
                if ($sess['session_status'] === 'passed') {
                    $kanbanGroups[$groupKey]['passed_qty'] += $sessTotalQty;
                }

            } else {
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
                        'passed_qty'   => 0,
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
                if ($sess['session_status'] === 'passed') {
                    $ssGroups[$groupKey]['passed_qty'] += $sessTotalQty;
                }
            }
        }

        // Post-Process Mode A: Tag Session Labels & Final Status
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
    die("Database Error: " . $e->getMessage());
}

if ($startDate === $endDate) {
    $formattedDateId = date('d F Y', strtotime($startDate));
    $dayNameId = [
        'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa',
        'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'
    ][date('l', strtotime($startDate))] ?? date('l', strtotime($startDate));
    $dateTitleLabel = $startDate;
} else {
    $formattedDateId = date('d/m/Y', strtotime($startDate)) . ' s.d. ' . date('d/m/Y', strtotime($endDate));
    $dayNameId = 'Periode Filter';
    $dateTitleLabel = $startDate . "_sd_" . $endDate;
}

$currentUser = current_user();

// ── 2. Create Spreadsheet ─────────────────────────────────────────────────────
$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator("PT. Surya Technology Industri — OQC")
    ->setLastModifiedBy($currentUser['name'] ?? 'System')
    ->setTitle("Laporan Detail Review OQC " . $dateTitleLabel)
    ->setSubject("OQC Daily Inspection Detail Report")
    ->setDescription("Laporan Detail Inspeksi Harian OQC — Tanggal " . $dateTitleLabel);

// Helper Styles
$borderThin = [
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => 'CBD5E1'],
        ],
    ],
];
$borderBottomDouble = [
    'borders' => [
        'bottom' => [
            'borderStyle' => Border::BORDER_DOUBLE,
            'color' => ['rgb' => '94A3B8'],
        ],
    ],
];

if (!function_exists('getShiftName')) {
    function getShiftName($timestamp) {
        if (empty($timestamp)) return 'Shift 1';
        $ts = is_numeric($timestamp) ? (int)$timestamp : strtotime($timestamp);
        if (!$ts) return 'Shift 1';

        $dayOfWeek = (int)date('N', $ts); // 1 = Senin, ..., 6 = Sabtu, 7 = Minggu
        $hour      = (int)date('H', $ts);

        // KONDISI KHUSUS: HARI SABTU (Setengah Hari / 5 Jam per shift)
        if ($dayOfWeek === 6) {
            if ($hour >= 7 && $hour < 12) {
                return 'Shift 1';
            } elseif ($hour >= 12 && $hour < 17) {
                return 'Shift 2';
            } elseif ($hour >= 17 && $hour < 22) {
                return 'Shift 3';
            } else {
                return 'Shift 3';
            }
        }

        // KONDISI REGULER: SENIN - JUMAT & MINGGU
        if ($hour >= 7 && $hour < 15) {
            return 'Shift 1';
        } elseif ($hour >= 15 && $hour < 23) {
            return 'Shift 2';
        } else {
            return 'Shift 3';
        }
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// SHEET 1: RINCIAN PER LOT (FLAT DATA TABLE & DEFECT MATRIX CROSSTAB)
// ═════════════════════════════════════════════════════════════════════════════
$sheet1 = $spreadsheet->getActiveSheet();
$sheet1->setTitle('Rincian Per Lot');
$sheet1->setShowGridLines(true);

// 1. Header Title & Company
$sheet1->mergeCells('A1:N1');
$sheet1->setCellValue('A1', 'PT. SURYA TECHNOLOGY INDUSTRI — OUTGOING QUALITY CONTROL DIVISION');
$sheet1->getStyle('A1')->getFont()->setBold(true)->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('64748B'));

$sheet1->mergeCells('A2:N2');
$sheet1->setCellValue('A2', 'LAPORAN RINCIAN INSPEKSI HARIAN PER LOT / BOX (FLAT DATA TABLE & DEFECT MATRIX)');
$sheet1->getStyle('A2')->getFont()->setBold(true)->setSize(15)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('0F172A'));

$sheet1->mergeCells('A3:N3');
$sheet1->setCellValue('A3', "Hari, Tanggal: {$dayNameId}, {$formattedDateId}  |  Dicetak: " . date('d/m/Y H:i') . " WIB oleh " . ($currentUser['name'] ?? 'System Administrator'));
$sheet1->getStyle('A3')->getFont()->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('475569'));
$sheet1->getRowDimension(3)->setRowHeight(22);

// 2. Fetch Defect Master List
$defectTypesMaster = [];
try {
    $stmtDefectsMaster = $pdo->query("SELECT id, name FROM defect_types ORDER BY id ASC");
    if ($stmtDefectsMaster) {
        $defectTypesMaster = $stmtDefectsMaster->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Error fetching defect types master: " . $e->getMessage());
}

// 3. Fetch All NG Records for sessions on this date mapped by lot_id and session_id
$ngByLotMap = [];
$ngBySessionMap = [];

if (!empty($rawSessions)) {
    $sessIds = array_map(fn($s) => (int)$s['session_id'], $rawSessions);
    $inSessIds = implode(',', $sessIds);

    try {
        $stmtAllNg = $pdo->query("
            SELECT 
                ngr.id,
                ngr.inspection_session_id,
                ngr.session_lot_id,
                ngr.defect_type_id,
                ngr.qty_ng,
                dt.name AS defect_name
            FROM inspection_ng_records ngr
            INNER JOIN defect_types dt ON ngr.defect_type_id = dt.id
            WHERE ngr.inspection_session_id IN ({$inSessIds})
              AND (ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)
        ");
        if ($stmtAllNg) {
            foreach ($stmtAllNg->fetchAll(PDO::FETCH_ASSOC) as $ngR) {
                $sId = (int)$ngR['inspection_session_id'];
                $lId = (int)($ngR['session_lot_id'] ?? 0);
                $dtId = (int)$ngR['defect_type_id'];
                $qNg = (int)$ngR['qty_ng'];
                $dName = $ngR['defect_name'];

                if ($lId > 0) {
                    if (!isset($ngByLotMap[$lId])) {
                        $ngByLotMap[$lId] = ['total_ng' => 0, 'by_type' => [], 'defect_names' => []];
                    }
                    $ngByLotMap[$lId]['total_ng'] += $qNg;
                    $ngByLotMap[$lId]['by_type'][$dtId] = ($ngByLotMap[$lId]['by_type'][$dtId] ?? 0) + $qNg;
                    $ngByLotMap[$lId]['defect_names'][$dName] = ($ngByLotMap[$lId]['defect_names'][$dName] ?? 0) + $qNg;
                }

                if (!isset($ngBySessionMap[$sId])) {
                    $ngBySessionMap[$sId] = ['total_ng' => 0, 'by_type' => [], 'defect_names' => []];
                }
                $ngBySessionMap[$sId]['total_ng'] += $qNg;
                $ngBySessionMap[$sId]['by_type'][$dtId] = ($ngBySessionMap[$sId]['by_type'][$dtId] ?? 0) + $qNg;
                $ngBySessionMap[$sId]['defect_names'][$dName] = ($ngBySessionMap[$sId]['defect_names'][$dName] ?? 0) + $qNg;
            }
        }
    } catch (PDOException $e) {
        error_log("Error fetching NG records for Sheet 1: " . $e->getMessage());
    }
}

// 4. Build Flat Lot Rows List
$flatLotRows = [];
if (!empty($rawSessions)) {
    foreach ($rawSessions as $sess) {
        $sId = (int)$sess['session_id'];
        $sessLots = $allLotsMap[$sId] ?? [];

        if (!empty($sessLots)) {
            foreach ($sessLots as $sl) {
                $refNo = trim($sl['ref_number'] ?? '');
                $remarks = trim($sl['remarks'] ?? '');

                // Filter hanya lot yang diinspeksi secara real / fisik (hanya skip yang bypassed skipped / virtual split)
                $isBypassedSkipped = (($sl['lot_result'] ?? '') === 'skipped');
                $isVirtualOrExcluded = stripos($remarks, 'Sisa Split') !== false
                    || stripos($remarks, 'Sisa Kelebihan') !== false
                    || $refNo === 'DIRECT-PASS' 
                    || stripos($refNo, 'DIRECT-PASS') !== false 
                    || stripos($remarks, 'Direct Close') !== false;

                if ($isBypassedSkipped || $isVirtualOrExcluded) {
                    continue;
                }

                $lId = (int)$sl['lot_id'];

                $ngByType = [];
                $defectNames = [];
                $totalNg = 0;

                if (!empty($sl['defects'])) {
                    foreach ($sl['defects'] as $df) {
                        $q = (int)($df['qty_ng'] ?? 1);
                        $totalNg += $q;
                        $dtId = (int)($df['defect_type_id'] ?? 0);
                        if ($dtId > 0) {
                            $ngByType[$dtId] = ($ngByType[$dtId] ?? 0) + $q;
                        }
                        $dName = $df['defect_name'] ?? 'NG';
                        $defectNames[$dName] = ($defectNames[$dName] ?? 0) + $q;
                    }
                } elseif (empty($sl['is_reinspected_attempt'])) {
                    $fallbackNg = $ngByLotMap[$lId] ?? null;
                    if ($fallbackNg) {
                        $totalNg = (int)$fallbackNg['total_ng'];
                        $ngByType = $fallbackNg['by_type'];
                        $defectNames = $fallbackNg['defect_names'];
                    }
                }

                $defStr = '-';
                if (!empty($defectNames)) {
                    $parts = [];
                    foreach ($defectNames as $dn => $dq) {
                        $parts[] = "{$dn} ({$dq} pcs)";
                    }
                    $defStr = implode(', ', $parts);
                }

                $lRejectNum = (int)($sl['computed_reject_number'] ?? 1);
                $lTotalNg   = $totalNg;
                $lResult    = strtolower($sl['lot_result'] ?? '');
                $lStatus    = strtolower($sl['lot_status'] ?? '');
                $isReinspect = !empty($sl['has_prev_rejected'])
                    || !empty($sl['is_reinspected_attempt']) 
                    || !empty($sl['is_reinspected_lot']) 
                    || !empty($sl['is_reinspection']) 
                    || (($sl['attempt_type'] ?? '') === 'reinspection')
                    || (!empty($sess['is_reinspection']))
                    || (!empty($sess['reinspection_type']))
                    || (!empty($sess['parent_session_id']))
                    || (!empty($sess['reinspection_notes']))
                    || (isset($sl['remarks']) && (strripos($sl['remarks'], 're-inspeksi') !== false || strripos($sl['remarks'], 'reinspeksi') !== false || strripos($sl['remarks'], 're-inspect') !== false || strripos($sl['remarks'], 'reinspect') !== false))
                    || (isset($sess['remarks']) && (strripos($sess['remarks'], 're-inspeksi') !== false || strripos($sess['remarks'], 'reinspeksi') !== false))
                    || (strtolower($sl['lot_status'] ?? '') === 'reinspected')
                    || (isset($sl['status_badge']) && stripos($sl['status_badge'], 'RE-INSPEKSI') !== false)
                    || (isset($sl['status_label']) && stripos($sl['status_label'], 'RE-INSPEKSI') !== false);

                // DB Fallback
                if (!$isReinspect && !empty($sl['lot_number']) && !empty($sl['lot_id'])) {
                    static $prevRejectedFlatDrCache = [];
                    $cacheKey = $sId . '_' . (int)$sl['lot_id'];
                    if (!isset($prevRejectedFlatDrCache[$cacheKey])) {
                        try {
                            $refStr = (string)($sl['ref_number'] ?? '');
                            $currTime = (string)($sl['created_at'] ?? $sl['scanned_at'] ?? date('Y-m-d H:i:s'));
                            $chkStmt = $pdo->prepare("
                                SELECT 1 
                                FROM inspection_session_lots prev_isl
                                WHERE prev_isl.lot_number = :lot_num
                                  AND (
                                      (:ref_num1 != '' AND prev_isl.ref_number = :ref_num2)
                                      OR (:ref_num3 = '' AND (prev_isl.ref_number IS NULL OR prev_isl.ref_number = ''))
                                  )
                                  AND prev_isl.id != :curr_lid
                                  AND prev_isl.created_at < :curr_created
                                  AND (prev_isl.lot_result = 'rejected' OR prev_isl.ng_count > 0 OR prev_isl.lot_status IN ('ng_found', 'ng_quarantine', 'replaced'))
                                LIMIT 1
                            ");
                            $chkStmt->execute([
                                ':lot_num'      => (string)$sl['lot_number'],
                                ':ref_num1'     => $refStr,
                                ':ref_num2'     => $refStr,
                                ':ref_num3'     => $refStr,
                                ':curr_lid'     => (int)$sl['lot_id'],
                                ':curr_created' => $currTime
                            ]);
                            $prevRejectedFlatDrCache[$cacheKey] = (bool)$chkStmt->fetchColumn();
                        } catch (Exception $e) {
                            $prevRejectedFlatDrCache[$cacheKey] = false;
                        }
                    }
                    if (!empty($prevRejectedFlatDrCache[$cacheKey])) {
                        $isReinspect = true;
                    }
                }

                $isInitial = !empty($sl['is_initial_attempt']) || ($sl['attempt_type'] ?? '') === 'initial';

                if ($isReinspect) {
                    if ($lResult === 'rejected' || ($lTotalNg >= $lRejectNum && $lRejectNum > 0)) {
                        $rowStatusCode = 'rejected';
                        $rowStatusLabel = 'REJECTED (RE-INSPEKSI)';
                    } elseif ($lResult === 'passed') {
                        $rowStatusCode = 'passed';
                        $rowStatusLabel = 'PASSED (RE-INSPEKSI)';
                    } else {
                        $rowStatusCode = 'in_progress';
                        $rowStatusLabel = 'IN PROGRESS (RE-INSPEKSI)';
                    }
                } elseif ($isInitial) {
                    $rowStatusCode = 'rejected';
                    $rowStatusLabel = 'REJECTED';
                } elseif ($lResult === 'rejected' || ($lTotalNg >= $lRejectNum && $lRejectNum > 0)) {
                    $rowStatusCode = 'rejected';
                    $rowStatusLabel = 'REJECTED';
                } elseif ($lResult === 'passed') {
                    $rowStatusCode = 'passed';
                    $rowStatusLabel = 'PASSED';
                } elseif ($lResult === 'skipped') {
                    $rowStatusCode = 'skipped';
                    $rowStatusLabel = 'SKIPPED';
                } else {
                    if ($sess['session_status'] === 'passed') {
                        $rowStatusCode = 'passed';
                        $rowStatusLabel = 'PASSED';
                    } elseif ($sess['session_status'] === 'rejected') {
                        $rowStatusCode = 'rejected';
                        $rowStatusLabel = 'REJECTED';
                    } else {
                        $rowStatusCode = 'in_progress';
                        $rowStatusLabel = 'IN PROGRESS';
                    }
                }

                $isSs = ($sess['inspection_type'] === 'safety_stock') 
                    || (empty($sess['kanban_item_id']) && (!empty($sess['original_ss_session_id']) || !empty($sl['is_safety_stock'])));
                $jenisLabel = $isSs ? 'SAFETY STOCK' : 'KANBAN';

                $flatLotRows[] = [
                    'started_at'     => $sess['started_at'],
                    'jenis'          => $jenisLabel,
                    'part_code'      => $sess['part_code'],
                    'part_name'      => $sess['part_name'],
                    'model_name'     => $sess['model_name'] ?: '-',
                    'lot_number'     => $sl['lot_number'] ?: '-',
                    'ref_number'     => $sl['ref_number'] ?: '-',
                    'qty_lot'        => (int)$sl['qty'],
                    'qty_sampling'   => (int)($sl['computed_sample_size'] ?? 0),
                    'reject_number'  => $lRejectNum,
                    'total_ng'       => $lTotalNg,
                    'defect_summary' => $defStr,
                    'status_code'    => $rowStatusCode,
                    'status_label'   => $rowStatusLabel,
                    'session_status' => $sess['session_status'],
                    'session_id'     => $sId,
                    'inspector_name' => $sess['inspector_name'],
                    'ng_by_type'     => $ngByType
                ];
            }
        } else {
            // Jika sesi sudah punya catatan lot di DB (artinya semua lotnya SS/skipped), jangan buat fallback dummy
            if (!empty($sessionsWithLots[$sId])) {
                continue;
            }
            // Skip direct close sessions from fallback row
            if (!empty($sess['approval_notes']) && stripos($sess['approval_notes'], 'Direct Close') !== false) {
                continue;
            }
            if (!empty($sess['auto_fulfilled_by_session_id'])) {
                continue;
            }
            if ((int)($sess['sample_size'] ?? 0) <= 0 && (int)($sess['total_scanned_qty'] ?? 0) <= 0) {
                continue;
            }

            // Fallback for session without session_lots entry
            $ngData = $ngBySessionMap[$sId] ?? ['total_ng' => 0, 'by_type' => [], 'defect_names' => []];
            $defStr = '-';
            if (!empty($ngData['defect_names'])) {
                $parts = [];
                foreach ($ngData['defect_names'] as $dn => $dq) {
                    $parts[] = "{$dn} ({$dq} pcs)";
                }
                $defStr = implode(', ', $parts);
            }

            $sRejectNum = (int)($sess['reject_number'] ?? 1);
            $sTotalNg   = (int)$ngData['total_ng'];

            if ($sTotalNg >= $sRejectNum && $sRejectNum > 0) {
                $rowStatusCode = 'rejected';
                $rowStatusLabel = 'REJECTED';
            } elseif ($sess['session_status'] === 'passed') {
                $rowStatusCode = 'passed';
                $rowStatusLabel = 'PASSED';
            } elseif ($sess['session_status'] === 'rejected') {
                $rowStatusCode = 'rejected';
                $rowStatusLabel = 'REJECTED';
            } else {
                $rowStatusCode = 'in_progress';
                $rowStatusLabel = 'IN PROGRESS';
            }

            $isSs = ($sess['inspection_type'] === 'safety_stock') 
                || (empty($sess['kanban_item_id']) && !empty($sess['original_ss_session_id']));
            $jenisLabel = $isSs ? 'SAFETY STOCK' : 'KANBAN';

            $flatLotRows[] = [
                'started_at'     => $sess['started_at'],
                'jenis'          => $jenisLabel,
                'part_code'      => $sess['part_code'],
                'part_name'      => $sess['part_name'],
                'model_name'     => $sess['model_name'] ?: '-',
                'lot_number'     => $sess['did_lot_number'] ?: '-',
                'ref_number'     => '-',
                'qty_lot'        => (int)($sess['total_scanned_qty'] ?? 0),
                'qty_sampling'   => (int)($sess['sample_size'] ?? 0),
                'reject_number'  => $sRejectNum,
                'total_ng'       => $sTotalNg,
                'defect_summary' => $defStr,
                'status_code'    => $rowStatusCode,
                'status_label'   => $rowStatusLabel,
                'session_status' => $sess['session_status'],
                'session_id'     => $sId,
                'inspector_name' => $sess['inspector_name'],
                'ng_by_type'     => $ngData['by_type']
            ];
        }
    }
}

// 5. Render Headers (Row 5)
$hRow = 5;
$coreHeaders = [
    'Tanggal', 'Jenis', 'Part Code', 'Part Name', 'Model', 'Lot No', 'Ref No', 
    'QTY Lot', 'QTY Sampling', 'NG', 'Defect', 'Status', 
    'Sesi', 'Shift', 'Inspektor'
];

foreach ($coreHeaders as $idx => $hText) {
    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 1);
    $sheet1->setCellValue($colLetter . $hRow, $hText);
    $sheet1->getStyle($colLetter . $hRow)->getFont()->setBold(true)->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFF'));
    $sheet1->getStyle($colLetter . $hRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0F172A');
    $sheet1->getStyle($colLetter . $hRow)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    if (in_array($hText, ['Tanggal', 'Jenis', 'Model', 'Status', 'Sesi', 'Shift', 'NG'])) {
        $sheet1->getStyle($colLetter . $hRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    } elseif (in_array($hText, ['QTY Lot', 'QTY Sampling'])) {
        $sheet1->getStyle($colLetter . $hRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }
}

// 5 Gap Spacer Columns (Cols 16 to 20 -> P to T)
for ($gCol = 16; $gCol <= 20; $gCol++) {
    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($gCol);
    $sheet1->setCellValue($colLetter . $hRow, '');
    $sheet1->getColumnDimension($colLetter)->setWidth(4);
}

// Dynamic Defect Matrix Headers (Cols 21+ -> U+)
$defectColIndexMap = [];
$cIdx = 21;
foreach ($defectTypesMaster as $dtM) {
    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($cIdx);
    $defectColIndexMap[$dtM['id']] = $colLetter;
    $sheet1->setCellValue($colLetter . $hRow, $dtM['name']);
    $sheet1->getStyle($colLetter . $hRow)->getFont()->setBold(true)->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFF'));
    $sheet1->getStyle($colLetter . $hRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('334155');
    $sheet1->getStyle($colLetter . $hRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $sheet1->getColumnDimension($colLetter)->setWidth(16);
    $cIdx++;
}

$sheet1->getRowDimension($hRow)->setRowHeight(24);

// 6. Render Data Rows (Starting Row 6)
$rIdx = 6;
if (empty($flatLotRows)) {
    $sheet1->mergeCells("A{$rIdx}:O{$rIdx}");
    $sheet1->setCellValue("A{$rIdx}", "Tidak ada data inspeksi yang ditemukan untuk rentang tanggal yang dipilih.");
    $sheet1->getStyle("A{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $sheet1->getStyle("A{$rIdx}")->getFont()->setItalic(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('64748B'));
    $sheet1->getStyle("A{$rIdx}:O{$rIdx}")->applyFromArray($borderThin);
    $sheet1->getRowDimension($rIdx)->setRowHeight(30);
    $rIdx++;
} else {
    $lastDefectCol = !empty($defectTypesMaster) ? \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($cIdx - 1) : '';
    $firstDataRow = 6;

    foreach ($flatLotRows as $rowItem) {
        $bgRowColor = ($rIdx % 2 === 0) ? 'F8FAFC' : 'FFFFFF';

        // A: Tanggal
        $sheet1->setCellValue("A{$rIdx}", date('d/m/Y', strtotime($rowItem['started_at'])));

        // B: Jenis (KANBAN / SAFETY STOCK)
        $sheet1->setCellValue("B{$rIdx}", $rowItem['jenis']);
        if ($rowItem['jenis'] === 'SAFETY STOCK') {
            $sheet1->getStyle("B{$rIdx}")->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('7C3AED'));
        } else {
            $sheet1->getStyle("B{$rIdx}")->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('2563EB'));
        }

        // C: Part Code
        $sheet1->setCellValue("C{$rIdx}", $rowItem['part_code']);

        // D: Part Name
        $sheet1->setCellValue("D{$rIdx}", $rowItem['part_name']);

        // E: Model
        $sheet1->setCellValue("E{$rIdx}", $rowItem['model_name'] ?: '-');

        // F: Lot No
        $sheet1->setCellValue("F{$rIdx}", $rowItem['lot_number']);

        // G: Ref No
        $sheet1->setCellValue("G{$rIdx}", $rowItem['ref_number']);

        // H: QTY Lot
        $sheet1->setCellValue("H{$rIdx}", (int)$rowItem['qty_lot']);

        // I: QTY Sampling
        $sheet1->setCellValue("I{$rIdx}", (int)$rowItem['qty_sampling']);

        // J: NG (nilai langsung yang didapat)
        $sheet1->setCellValue("J{$rIdx}", (int)$rowItem['total_ng']);
        if ($rowItem['total_ng'] > 0) {
            $sheet1->getStyle("J{$rIdx}")->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('DC2626'));
        } else {
            $sheet1->getStyle("J{$rIdx}")->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('16A34A'));
        }

        // K: Defect
        $sheet1->setCellValue("K{$rIdx}", $rowItem['defect_summary']);

        // L: Status
        $statusStr = $rowItem['status_label'] ?? strtoupper(str_replace('_', ' ', $rowItem['session_status']));
        $statusCode = $rowItem['status_code'] ?? $rowItem['session_status'];
        $sheet1->setCellValue("L{$rIdx}", $statusStr);
        if ($statusCode === 'passed') {
            $sheet1->getStyle("L{$rIdx}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DCFCE7');
            $sheet1->getStyle("L{$rIdx}")->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('15803D'));
        } elseif ($statusCode === 'in_progress') {
            $sheet1->getStyle("L{$rIdx}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E0E7FF');
            $sheet1->getStyle("L{$rIdx}")->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('3730A3'));
        } elseif ($statusCode === 'skipped') {
            $sheet1->getStyle("L{$rIdx}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F1F5F9');
            $sheet1->getStyle("L{$rIdx}")->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('64748B'));
        } else {
            $sheet1->getStyle("L{$rIdx}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFE4E6');
            $sheet1->getStyle("L{$rIdx}")->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('BE123C'));
        }

        // M: Sesi
        $sheet1->setCellValue("M{$rIdx}", "Sesi #" . $rowItem['session_id']);

        // N: Shift
        $shiftStr = getShiftName($rowItem['started_at']);
        $sheet1->setCellValue("N{$rIdx}", $shiftStr);

        // O: Inspektor
        $sheet1->setCellValue("O{$rIdx}", $rowItem['inspector_name']);

        // Zebra striping for even rows
        if ($rIdx % 2 === 0) {
            $sheet1->getStyle("A{$rIdx}:K{$rIdx}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8FAFC');
            $sheet1->getStyle("M{$rIdx}:O{$rIdx}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8FAFC');
        }

        // Dynamic Defect Matrix (Cols U+ -> 21+)
        if (!empty($rowItem['ng_by_type'])) {
            foreach ($rowItem['ng_by_type'] as $dtId => $ngQtyForType) {
                if ($ngQtyForType > 0 && isset($defectColIndexMap[$dtId])) {
                    $colLetter = $defectColIndexMap[$dtId];
                    $sheet1->setCellValue($colLetter . $rIdx, $ngQtyForType);
                    $sheet1->getStyle($colLetter . $rIdx)->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('991B1B'));
                    $sheet1->getStyle($colLetter . $rIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEE2E2');
                }
            }
        }

        $sheet1->getRowDimension($rIdx)->setRowHeight(21);
        $rIdx++;
    }

    $lastDataRow = $rIdx - 1;

    // ── Bulk Styling for Sheet 1 (One-Shot Call for 30x Faster Performance) ──
    $sheet1->getStyle("A{$firstDataRow}:O{$lastDataRow}")->applyFromArray($borderThin);
    $sheet1->getStyle("A{$firstDataRow}:O{$lastDataRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $sheet1->getStyle("A{$firstDataRow}:B{$lastDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet1->getStyle("E{$firstDataRow}:E{$lastDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet1->getStyle("G{$firstDataRow}:G{$lastDataRow}")->getFont()->setBold(true);
    $sheet1->getStyle("H{$firstDataRow}:I{$lastDataRow}")->getNumberFormat()->setFormatCode('#,##0');
    $sheet1->getStyle("H{$firstDataRow}:I{$lastDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet1->getStyle("H{$firstDataRow}:H{$lastDataRow}")->getFont()->setBold(true);
    $sheet1->getStyle("J{$firstDataRow}:J{$lastDataRow}")->getNumberFormat()->setFormatCode('#,##0');
    $sheet1->getStyle("J{$firstDataRow}:J{$lastDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet1->getStyle("L{$firstDataRow}:N{$lastDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    if (!empty($lastDefectCol)) {
        $sheet1->getStyle("U{$firstDataRow}:{$lastDefectCol}{$lastDataRow}")->applyFromArray($borderThin);
        $sheet1->getStyle("U{$firstDataRow}:{$lastDefectCol}{$lastDataRow}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet1->getStyle("U{$firstDataRow}:{$lastDefectCol}{$lastDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT)->setVertical(Alignment::VERTICAL_CENTER);
    }

    // ── Summary Grand Total Row ──────────────────────────────────────────────
    $totRow = $rIdx;
    $sheet1->setCellValue("A{$totRow}", 'TOTAL');
    $sheet1->mergeCells("A{$totRow}:G{$totRow}");
    $sheet1->getStyle("A{$totRow}")->getFont()->setBold(true)->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('0F172A'));
    $sheet1->getStyle("A{$totRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    
    // SUM Qty Lot (Col H)
    $sheet1->setCellValue("H{$totRow}", "=SUM(H6:H" . ($totRow - 1) . ")");
    $sheet1->getStyle("H{$totRow}")->getNumberFormat()->setFormatCode('#,##0');
    $sheet1->getStyle("H{$totRow}")->getFont()->setBold(true);
    $sheet1->getStyle("H{$totRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    // SUM Qty Sampling (Col I)
    $sheet1->setCellValue("I{$totRow}", "=SUM(I6:I" . ($totRow - 1) . ")");
    $sheet1->getStyle("I{$totRow}")->getNumberFormat()->setFormatCode('#,##0');
    $sheet1->getStyle("I{$totRow}")->getFont()->setBold(true);
    $sheet1->getStyle("I{$totRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    // SUM NG (Col J)
    $sheet1->setCellValue("J{$totRow}", "=SUM(J6:J" . ($totRow - 1) . ")");
    $sheet1->getStyle("J{$totRow}")->getNumberFormat()->setFormatCode('#,##0');
    $sheet1->getStyle("J{$totRow}")->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('DC2626'));
    $sheet1->getStyle("J{$totRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $sheet1->getStyle("A{$totRow}:O{$totRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F1F5F9');
    $sheet1->getStyle("A{$totRow}:O{$totRow}")->applyFromArray($borderThin);
    $sheet1->getStyle("A{$totRow}:O{$totRow}")->applyFromArray($borderBottomDouble);

    // Defect Matrix Totals (Cols U+)
    foreach ($defectTypesMaster as $dtM) {
        $dtId = (int)$dtM['id'];
        $colLetter = $defectColIndexMap[$dtId] ?? '';
        if ($colLetter !== '') {
            $sheet1->setCellValue("{$colLetter}{$totRow}", "=SUM({$colLetter}6:{$colLetter}" . ($totRow - 1) . ")");
        }
    }
    if (!empty($lastDefectCol)) {
        $sheet1->getStyle("U{$totRow}:{$lastDefectCol}{$totRow}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet1->getStyle("U{$totRow}:{$lastDefectCol}{$totRow}")->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('991B1B'));
        $sheet1->getStyle("U{$totRow}:{$lastDefectCol}{$totRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet1->getStyle("U{$totRow}:{$lastDefectCol}{$totRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEE2E2');
        $sheet1->getStyle("U{$totRow}:{$lastDefectCol}{$totRow}")->applyFromArray($borderThin);
        $sheet1->getStyle("U{$totRow}:{$lastDefectCol}{$totRow}")->applyFromArray($borderBottomDouble);
    }
    $sheet1->getRowDimension($totRow)->setRowHeight(24);
    $sheet1->getRowDimension($totRow)->setRowHeight(24);
}

// Auto-size core columns A to O
$sheet1->getColumnDimension('A')->setWidth(13); // Tanggal
$sheet1->getColumnDimension('B')->setWidth(16); // Jenis
$sheet1->getColumnDimension('C')->setWidth(20); // Part Code
$sheet1->getColumnDimension('D')->setWidth(30); // Part Name
$sheet1->getColumnDimension('E')->setWidth(18); // Model
$sheet1->getColumnDimension('F')->setWidth(18); // Lot No
$sheet1->getColumnDimension('G')->setWidth(22); // Ref No
$sheet1->getColumnDimension('H')->setWidth(14); // QTY Lot
$sheet1->getColumnDimension('I')->setWidth(16); // QTY Sampling
$sheet1->getColumnDimension('J')->setWidth(12); // NG
$sheet1->getColumnDimension('K')->setWidth(26); // Defect
$sheet1->getColumnDimension('L')->setWidth(26); // Status
$sheet1->getColumnDimension('M')->setWidth(12); // Sesi
$sheet1->getColumnDimension('N')->setWidth(12); // Shift
$sheet1->getColumnDimension('O')->setWidth(22); // Inspektor



// Set Sheet 1 as active default
$spreadsheet->setActiveSheetIndex(0);

// ── 3. Output to Browser / File ────────────────────────────────────────────────
$fileName = "Laporan_OQC_Detail_" . ($dateTitleLabel ?? date('Y-m-d')) . ".xlsx";

if (!empty($exportSavePath)) {
    $writer = new Xlsx($spreadsheet);
    $writer->setIncludeCharts(true);
    $writer->save($exportSavePath);
    return;
}

if (ob_get_length()) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);
$writer->setIncludeCharts(true);
$writer->save('php://output');
exit;
