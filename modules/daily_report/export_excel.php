<?php
/**
 * Visual Excel Report Generator — Detail Laporan Harian OQC (Supervisor Executive View)
 * Mirroring tampilan web modules/daily_report/detail.php:
 * 1. Sheet 1: Kanban & Safety Stock (KPI Cards Persetujuan Supervisor, Jalur Kanban & SS, Sub-sesi, Child Lots, Defect Breakdown)
 * 2. Sheet 2: Kronologi Sesi Inspeksi (Urutan pelaksanaan di meja QC dari pagi ke sore, Kolom Persetujuan Supervisor)
 * 3. Sheet 3: Distribusi Cacat Mutu & Analitik Diagram (Top Defect Types, Top Models, Top Parts, Native Excel Pie Chart)
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
        {$searchWhere}
        ORDER BY ss.started_at ASC, ss.id ASC
    ");
    $stmtSessions->execute($params);
    $rawSessions = $stmtSessions->fetchAll(PDO::FETCH_ASSOC);

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
        $hour = (int)date('H', strtotime($timestamp));
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
                $isReinspect = !empty($sl['is_reinspected_attempt']) || ($sl['attempt_type'] ?? '') === 'reinspection';
                $isInitial   = !empty($sl['is_initial_attempt']) || ($sl['attempt_type'] ?? '') === 'initial';

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
                    $rowStatusLabel = ($lStatus === 'replaced') ? 'REJECTED (DIGANTI)' : 'REJECTED';
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

                $flatLotRows[] = [
                    'started_at'     => $sess['started_at'],
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

            $flatLotRows[] = [
                'started_at'     => $sess['started_at'],
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
    'Tanggal', 'Part Code', 'Part Name', 'Model', 'Lot No', 'Ref No', 
    'QTY Lot', 'QTY Sampling', 'NG', 'Defect', 'Status', 
    'Sesi', 'Shift', 'Inspektor'
];

foreach ($coreHeaders as $idx => $hText) {
    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 1);
    $sheet1->setCellValue($colLetter . $hRow, $hText);
    $sheet1->getStyle($colLetter . $hRow)->getFont()->setBold(true)->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFF'));
    $sheet1->getStyle($colLetter . $hRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0F172A');
    $sheet1->getStyle($colLetter . $hRow)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    if (in_array($hText, ['Tanggal', 'Model', 'Status', 'Sesi', 'Shift', 'NG'])) {
        $sheet1->getStyle($colLetter . $hRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    } elseif (in_array($hText, ['QTY Lot', 'QTY Sampling'])) {
        $sheet1->getStyle($colLetter . $hRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }
}

// 5 Gap Spacer Columns (Cols 15 to 19 -> O to S)
for ($gCol = 15; $gCol <= 19; $gCol++) {
    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($gCol);
    $sheet1->setCellValue($colLetter . $hRow, '');
    $sheet1->getColumnDimension($colLetter)->setWidth(4);
}

// Dynamic Defect Matrix Headers (Cols 20+ -> T+)
$defectColIndexMap = [];
$cIdx = 20;
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
foreach ($flatLotRows as $rowItem) {
    $bgRowColor = ($rIdx % 2 === 0) ? 'F8FAFC' : 'FFFFFF';

    // A: Tanggal
    $sheet1->setCellValue("A{$rIdx}", date('d/m/Y', strtotime($rowItem['started_at'])));
    $sheet1->getStyle("A{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // B: Part Code
    $sheet1->setCellValue("B{$rIdx}", $rowItem['part_code']);

    // C: Part Name
    $sheet1->setCellValue("C{$rIdx}", $rowItem['part_name']);

    // D: Model
    $sheet1->setCellValue("D{$rIdx}", $rowItem['model_name'] ?: '-');
    $sheet1->getStyle("D{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // E: Lot No
    $sheet1->setCellValue("E{$rIdx}", $rowItem['lot_number']);

    // F: Ref No
    $sheet1->setCellValue("F{$rIdx}", $rowItem['ref_number']);
    $sheet1->getStyle("F{$rIdx}")->getFont()->setBold(true);

    // G: QTY Lot
    $sheet1->setCellValue("G{$rIdx}", (int)$rowItem['qty_lot']);
    $sheet1->getStyle("G{$rIdx}")->getNumberFormat()->setFormatCode('#,##0');
    $sheet1->getStyle("G{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet1->getStyle("G{$rIdx}")->getFont()->setBold(true);

    // H: QTY Sampling
    $sheet1->setCellValue("H{$rIdx}", (int)$rowItem['qty_sampling']);
    $sheet1->getStyle("H{$rIdx}")->getNumberFormat()->setFormatCode('#,##0');
    $sheet1->getStyle("H{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    // I: NG (nilai langsung yang didapat)
    $sheet1->setCellValue("I{$rIdx}", (int)$rowItem['total_ng']);
    $sheet1->getStyle("I{$rIdx}")->getNumberFormat()->setFormatCode('#,##0');
    $sheet1->getStyle("I{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet1->getStyle("I{$rIdx}")->getFont()->setBold(true);
    if ($rowItem['total_ng'] > 0) {
        $sheet1->getStyle("I{$rIdx}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('DC2626'));
    } else {
        $sheet1->getStyle("I{$rIdx}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('16A34A'));
    }

    // J: Defect
    $sheet1->setCellValue("J{$rIdx}", $rowItem['defect_summary']);

    // K: Status
    $statusStr = $rowItem['status_label'] ?? strtoupper(str_replace('_', ' ', $rowItem['session_status']));
    $statusCode = $rowItem['status_code'] ?? $rowItem['session_status'];
    $sheet1->setCellValue("K{$rIdx}", $statusStr);
    $sheet1->getStyle("K{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet1->getStyle("K{$rIdx}")->getFont()->setBold(true);
    if ($statusCode === 'passed') {
        $sheet1->getStyle("K{$rIdx}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DCFCE7');
        $sheet1->getStyle("K{$rIdx}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('15803D'));
    } elseif ($statusCode === 'in_progress') {
        $sheet1->getStyle("K{$rIdx}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E0E7FF');
        $sheet1->getStyle("K{$rIdx}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('3730A3'));
    } elseif ($statusCode === 'skipped') {
        $sheet1->getStyle("K{$rIdx}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F1F5F9');
        $sheet1->getStyle("K{$rIdx}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('64748B'));
    } else {
        $sheet1->getStyle("K{$rIdx}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFE4E6');
        $sheet1->getStyle("K{$rIdx}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('BE123C'));
    }

    // L: Sesi
    $sheet1->setCellValue("L{$rIdx}", "Sesi #" . $rowItem['session_id']);
    $sheet1->getStyle("L{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // M: Shift
    $shiftStr = getShiftName($rowItem['started_at']);
    $sheet1->setCellValue("M{$rIdx}", $shiftStr);
    $sheet1->getStyle("M{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // N: Inspektor
    $sheet1->setCellValue("N{$rIdx}", $rowItem['inspector_name']);

    // Standard Zebra & Border for Cols A-N
    $sheet1->getStyle("A{$rIdx}:J{$rIdx}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($bgRowColor);
    $sheet1->getStyle("L{$rIdx}:N{$rIdx}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($bgRowColor);
    $sheet1->getStyle("A{$rIdx}:N{$rIdx}")->applyFromArray($borderThin);

    // Gap Cols O to S: Blank
    for ($gCol = 15; $gCol <= 19; $gCol++) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($gCol);
        $sheet1->setCellValue($colLetter . $rIdx, '');
    }

    // Dynamic Defect Matrix (Cols T+)
    foreach ($defectTypesMaster as $dtM) {
        $dtId = (int)$dtM['id'];
        $colLetter = $defectColIndexMap[$dtId] ?? '';
        if ($colLetter !== '') {
            $ngQtyForType = (int)($rowItem['ng_by_type'][$dtId] ?? 0);
            if ($ngQtyForType > 0) {
                $sheet1->setCellValue($colLetter . $rIdx, $ngQtyForType);
                $sheet1->getStyle($colLetter . $rIdx)->getNumberFormat()->setFormatCode('#,##0');
                $sheet1->getStyle($colLetter . $rIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $sheet1->getStyle($colLetter . $rIdx)->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('991B1B'));
                $sheet1->getStyle($colLetter . $rIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEE2E2');
            } else {
                $sheet1->setCellValue($colLetter . $rIdx, '');
            }
            $sheet1->getStyle($colLetter . $rIdx)->applyFromArray($borderThin);
        }
    }

    $sheet1->getRowDimension($rIdx)->setRowHeight(21);
    $rIdx++;
}

// Auto-size core columns A to N
$sheet1->getColumnDimension('A')->setWidth(13);
$sheet1->getColumnDimension('B')->setWidth(20);
$sheet1->getColumnDimension('C')->setWidth(30);
$sheet1->getColumnDimension('D')->setWidth(18); // Model
$sheet1->getColumnDimension('E')->setWidth(18); // Lot No
$sheet1->getColumnDimension('F')->setWidth(22); // Ref No
$sheet1->getColumnDimension('G')->setWidth(14); // QTY Lot
$sheet1->getColumnDimension('H')->setWidth(16); // QTY Sampling
$sheet1->getColumnDimension('I')->setWidth(12); // NG
$sheet1->getColumnDimension('J')->setWidth(26); // Defect
$sheet1->getColumnDimension('K')->setWidth(24); // Status
$sheet1->getColumnDimension('L')->setWidth(12); // Sesi
$sheet1->getColumnDimension('M')->setWidth(12); // Shift
$sheet1->getColumnDimension('N')->setWidth(22); // Inspektor



// ═════════════════════════════════════════════════════════════════════════════
// SHEET 2: KRONOLOGI SESI INSPEKSI (TIMELINE VIEW)
// ═════════════════════════════════════════════════════════════════════════════
$sheet2 = $spreadsheet->createSheet();
$sheet2->setTitle('Kronologi Sesi');
$sheet2->setShowGridLines(true);

// Header Sheet 2
$sheet2->mergeCells('A1:I1');
$sheet2->setCellValue('A1', 'PT. SURYA TECHNOLOGY INDUSTRI — OUTGOING QUALITY CONTROL DIVISION');
$sheet2->getStyle('A1')->getFont()->setBold(true)->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('64748B'));

$sheet2->mergeCells('A2:I2');
$sheet2->setCellValue('A2', 'KRONOLOGI SELURUH SESI INSPEKSI HARIAN (ACTIVITY TIMELINE)');
$sheet2->getStyle('A2')->getFont()->setBold(true)->setSize(15)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('0F172A'));

$sheet2->mergeCells('A3:I3');
$sheet2->setCellValue('A3', "| {$dayNameId}, {$formattedDateId}  |  Total {$daySummary['total_sesi']} Sesi");
$sheet2->getStyle('A3')->getFont()->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('475569'));
$sheet2->getRowDimension(3)->setRowHeight(22);

$s2Row = 5;

// Table Header Row (Sheet 2)
$headersT = ['Sesi #', 'Jam (WIB)', 'Jalur', 'Identitas Item (Kanban / Part)', 'Inspector QC', 'Qty Diperiksa', 'Status Sesi', 'Persetujuan Supervisor', 'Sampling AQL'];
$colsT = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'];
foreach ($headersT as $i => $hText) {
    $sheet2->setCellValue($colsT[$i] . $s2Row, $hText);
}
$sheet2->getStyle("A{$s2Row}:I{$s2Row}")->getFont()->setBold(true)->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFF'));
$sheet2->getStyle("A{$s2Row}:I{$s2Row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0F172A');
$sheet2->getStyle("A{$s2Row}:I{$s2Row}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
$sheet2->getStyle("A{$s2Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet2->getStyle("B{$s2Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet2->getStyle("C{$s2Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet2->getStyle("F{$s2Row}:I{$s2Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet2->getRowDimension($s2Row)->setRowHeight(24);
$s2Row++;

foreach ($sessionTimeline as $stIdx => $sess) {
    $isKb = ($sess['inspection_type'] === 'kanban');
    $isReins = ((int)$sess['is_reinspection'] === 1 || (int)$sess['parent_session_id'] > 0);
    $sTime = !empty($sess['started_at']) ? date('H:i', strtotime($sess['started_at'])) : '-';
    $eTime = !empty($sess['closed_at']) ? date('H:i', strtotime($sess['closed_at'])) : 'Sekarang';
    $timeRange = "{$sTime} - {$eTime} WIB";

    // Master session row
    $sheet2->setCellValue("A{$s2Row}", "#" . $sess['session_id']);
    $sheet2->setCellValue("B{$s2Row}", $timeRange);
    $sheet2->setCellValue("C{$s2Row}", $isKb ? 'Kanban' : 'Safety Stock');
    $itemDesc = $isKb ? ("Kanban #" . $sess['kanban_no'] . " | " . $sess['part_code'] . " - " . $sess['part_name']) : ($sess['part_code'] . " - " . $sess['part_name'] . " (Lot: " . $sess['did_lot_number'] . ")");
    if ($isReins) {
        $itemDesc .= " [Re-inspeksi]";
    }
    $sheet2->setCellValue("D{$s2Row}", $itemDesc);
    $sheet2->setCellValue("E{$s2Row}", $sess['inspector_name']);
    $sheet2->setCellValue("F{$s2Row}", number_format($sess['computed_qty']) . " pcs (" . $sess['box_count'] . " Box)");
    
    $sessStatusStr = strtoupper(str_replace('_', ' ', $sess['session_status']));
    $sheet2->setCellValue("G{$s2Row}", $sessStatusStr);

    // Approval status
    $isApp = (!empty($sess['is_approved']) && (int)$sess['is_approved'] === 1);
    if ($isApp) {
        $appTime = !empty($sess['approved_at']) ? date('H:i', strtotime($sess['approved_at'])) : '-';
        $sheet2->setCellValue("H{$s2Row}", "DISETUJUI ({$sess['supervisor_name']} - {$appTime})");
        $sheet2->getStyle("H{$s2Row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DCFCE7');
        $sheet2->getStyle("H{$s2Row}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('15803D'))->setBold(true)->setSize(9);
    } else {
        $sheet2->setCellValue("H{$s2Row}", "BELUM DI-ACC");
        $sheet2->getStyle("H{$s2Row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEF3C7');
        $sheet2->getStyle("H{$s2Row}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('92400E'))->setBold(true)->setSize(9);
    }

    $sheet2->setCellValue("I{$s2Row}", $sess['sample_size'] . " pcs (NG: " . (int)($sess['ng_count'] ?? 0) . " / " . max(1, (int)$sess['reject_number']) . ")");

    // Styles
    $sheet2->getStyle("A{$s2Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet2->getStyle("B{$s2Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet2->getStyle("C{$s2Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet2->getStyle("D{$s2Row}")->getFont()->setBold(true);
    $sheet2->getStyle("F{$s2Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet2->getStyle("F{$s2Row}")->getFont()->setBold(true);
    $sheet2->getStyle("G{$s2Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet2->getStyle("G{$s2Row}")->getFont()->setBold(true);
    $sheet2->getStyle("H{$s2Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet2->getStyle("I{$s2Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // Status coloring
    if ($sess['session_status'] === 'passed') {
        $sheet2->getStyle("G{$s2Row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DCFCE7');
        $sheet2->getStyle("G{$s2Row}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('15803D'));
    } elseif ($sess['session_status'] === 'in_progress') {
        $sheet2->getStyle("G{$s2Row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E0E7FF');
        $sheet2->getStyle("G{$s2Row}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('3730A3'));
    } else {
        $sheet2->getStyle("G{$s2Row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFE4E6');
        $sheet2->getStyle("G{$s2Row}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('BE123C'));
    }

    $sheet2->getStyle("A{$s2Row}:I{$s2Row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($stIdx % 2 === 0 ? 'F8FAFC' : 'FFFFFF');
    $sheet2->getStyle("A{$s2Row}:I{$s2Row}")->applyFromArray($borderThin);
    $sheet2->getRowDimension($s2Row)->setRowHeight(22);
    $s2Row++;

    // Approval note if present
    if (!empty($sess['approval_notes'])) {
        $sheet2->mergeCells("B{$s2Row}:I{$s2Row}");
        $sheet2->setCellValue("B{$s2Row}", "     Catatan Persetujuan Supervisor: " . $sess['approval_notes']);
        $sheet2->getStyle("B{$s2Row}")->getFont()->setItalic(true)->setSize(9)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('065F46'));
        $sheet2->getStyle("B{$s2Row}:I{$s2Row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('ECFDF5');
        $sheet2->getStyle("B{$s2Row}:I{$s2Row}")->applyFromArray($borderThin);
        $sheet2->getRowDimension($s2Row)->setRowHeight(18);
        $s2Row++;
    }

    // Sub-lots
    if (!empty($sess['lots'])) {
        $sheet2->setCellValue("B{$s2Row}", "     Rincian Box / Lot:");
        $sheet2->setCellValue("C{$s2Row}", "No");
        $sheet2->setCellValue("D{$s2Row}", "Lot Number");
        $sheet2->setCellValue("E{$s2Row}", "Ref Number");
        $sheet2->setCellValue("F{$s2Row}", "Qty (pcs)");
        $sheet2->setCellValue("G{$s2Row}", "Sampel (pcs)");
        $sheet2->setCellValue("H{$s2Row}", "NG");
        $sheet2->setCellValue("I{$s2Row}", "Waktu Scan");

        $sheet2->getStyle("B{$s2Row}:I{$s2Row}")->getFont()->setBold(true)->setSize(8)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('64748B'));
        $sheet2->getStyle("B{$s2Row}:I{$s2Row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F1F5F9');
        $sheet2->getStyle("B{$s2Row}:I{$s2Row}")->applyFromArray($borderThin);
        $sheet2->getStyle("C{$s2Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet2->getStyle("F{$s2Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet2->getStyle("G{$s2Row}:I{$s2Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet2->getRowDimension($s2Row)->setRowHeight(20);
        $s2Row++;

        foreach ($sess['lots'] as $bIdx => $bLot) {
            $sheet2->setCellValue("C{$s2Row}", $bIdx + 1);

            $lotDesc = $bLot['lot_number'] ?: '-';
            if (!empty($bLot['is_reinspected_attempt'])) {
                $lotDesc .= ' [Re-inspeksi]';
            } elseif (!empty($bLot['is_initial_attempt'])) {
                $lotDesc .= ' [Uji Awal - NG]';
            } elseif (!empty($bLot['is_replaced'])) {
                $lotDesc .= ' [Digantikan]';
            } elseif (!empty($bLot['is_replacement_box'])) {
                $lotDesc .= ' [Box Pengganti]';
            }
            $sheet2->setCellValue("D{$s2Row}", $lotDesc);
            $sheet2->setCellValue("E{$s2Row}", $bLot['ref_number'] ?: '-');
            $sheet2->setCellValue("F{$s2Row}", (int)$bLot['qty']);
            $sheet2->setCellValue("G{$s2Row}", (int)($bLot['computed_sample_size'] ?? 0) . ' pcs');

            $lotNgTotal = 0;
            if (!empty($bLot['defects'])) {
                foreach ($bLot['defects'] as $df) {
                    $lotNgTotal += (int)$df['qty_ng'];
                }
            }
            $sheet2->setCellValue("H{$s2Row}", $lotNgTotal);

            $sheet2->setCellValue("I{$s2Row}", !empty($bLot['scanned_at']) ? date('H:i', strtotime($bLot['scanned_at'])) . ' WIB' : '-');

            $sheet2->getStyle("C{$s2Row}:I{$s2Row}")->getFont()->setSize(9);
            $sheet2->getStyle("C{$s2Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet2->getStyle("E{$s2Row}")->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('0284C7'));
            $sheet2->getStyle("F{$s2Row}")->getFont()->setBold(true);
            $sheet2->getStyle("F{$s2Row}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet2->getStyle("F{$s2Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet2->getStyle("G{$s2Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet2->getStyle("H{$s2Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet2->getStyle("H{$s2Row}")->getFont()->setBold(true);
            if ($lotNgTotal > 0) {
                $sheet2->getStyle("H{$s2Row}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('DC2626'));
            } else {
                $sheet2->getStyle("H{$s2Row}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('16A34A'));
            }
            $sheet2->getStyle("I{$s2Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet2->getStyle("C{$s2Row}:I{$s2Row}")->applyFromArray($borderThin);
            $sheet2->getRowDimension($s2Row)->setRowHeight(20);
            $s2Row++;

            // Inline defects directly under this lot row
            if (!empty($bLot['defects'])) {
                foreach ($bLot['defects'] as $df) {
                    $sheet2->mergeCells("C{$s2Row}:I{$s2Row}");
                    $inspectorText = !empty($df['inspector_name']) ? $df['inspector_name'] : 'Inspector';
                    $timeText = !empty($df['defect_created_at']) ? date('H:i \W\I\B', strtotime($df['defect_created_at'])) : '-';
                    $defectText = "     [DEFECT] • " . $df['defect_name'] . ": " . (int)$df['qty_ng'] . " pcs | Ditemukan oleh: " . $inspectorText . " | Jam: " . $timeText . (!empty($df['remark']) ? " | Ket: " . $df['remark'] : "");
                    $sheet2->setCellValue("C{$s2Row}", $defectText);
                    $sheet2->getStyle("C{$s2Row}")->getFont()->setSize(9)->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('991B1B'));
                    $sheet2->getStyle("C{$s2Row}:I{$s2Row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF5F5');
                    $sheet2->getStyle("C{$s2Row}:I{$s2Row}")->applyFromArray($borderThin);
                    $sheet2->getRowDimension($s2Row)->setRowHeight(19);
                    $s2Row++;
                }
            }

            // Inline reinspection action notes directly under this lot row
            if (!empty($bLot['is_reinspected_attempt'])) {
                $sheet2->mergeCells("C{$s2Row}:I{$s2Row}");
                $inspName = !empty($bLot['actioned_by_name']) ? $bLot['actioned_by_name'] : 'Inspector';
                $actTime = !empty($bLot['action_at']) ? date('H:i \W\I\B', strtotime($bLot['action_at'])) : date('H:i \W\I\B', strtotime($bLot['scanned_at']));
                $reinsText = "     [RE-INSPEKSI] Tindakan: " . ($bLot['action_notes'] ?: 'Part NG disortir dan ditukar dengan part bagus -> Dilakukan re-inspeksi sampel fisik dan lolos uji.') . " | Inspector: " . $inspName . " | Jam: " . $actTime;
                $sheet2->setCellValue("C{$s2Row}", $reinsText);
                $sheet2->getStyle("C{$s2Row}")->getFont()->setSize(9)->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('166534'));
                $sheet2->getStyle("C{$s2Row}:I{$s2Row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F0FDF4');
                $sheet2->getStyle("C{$s2Row}:I{$s2Row}")->applyFromArray($borderThin);
                $sheet2->getRowDimension($s2Row)->setRowHeight(19);
                $s2Row++;
            }
        }
    }

    // Unmatched defects or clean status
    if (!empty($sess['unmatched_defects'])) {
        foreach ($sess['unmatched_defects'] as $udf) {
            $sheet2->mergeCells("B{$s2Row}:I{$s2Row}");
            $inspectorText = !empty($udf['inspector_name']) ? $udf['inspector_name'] : 'Inspector';
            $timeText = !empty($udf['defect_created_at']) ? date('H:i \W\I\B', strtotime($udf['defect_created_at'])) : '-';
            $uText = "     [DEFECT SESI TANPA MAPPING LOT] • " . $udf['defect_name'] . ": " . (int)$udf['qty_ng'] . " pcs | Ditemukan oleh: " . $inspectorText . " | Jam: " . $timeText . (!empty($udf['remark']) ? " | Ket: " . $udf['remark'] : "");
            $sheet2->setCellValue("B{$s2Row}", $uText);
            $sheet2->getStyle("B{$s2Row}")->getFont()->setSize(9)->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('991B1B'));
            $sheet2->getStyle("B{$s2Row}:I{$s2Row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF5F5');
            $sheet2->getStyle("B{$s2Row}:I{$s2Row}")->applyFromArray($borderThin);
            $sheet2->getRowDimension($s2Row)->setRowHeight(19);
            $s2Row++;
        }
    } elseif (empty($sess['ng_records'])) {
        $sheet2->mergeCells("B{$s2Row}:I{$s2Row}");
        $sheet2->setCellValue("B{$s2Row}", "     All Samples OK: Seluruh sampel memenuhi standar mutu (0 cacat ditemukan)");
        $sheet2->getStyle("B{$s2Row}")->getFont()->setSize(8)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('166534'));
        $sheet2->getStyle("B{$s2Row}:I{$s2Row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F0FDF4');
        $sheet2->getStyle("B{$s2Row}:I{$s2Row}")->applyFromArray($borderThin);
        $sheet2->getRowDimension($s2Row)->setRowHeight(18);
        $s2Row++;
    }
}

// Auto-size columns for Sheet 2
$sheet2->getColumnDimension('A')->setWidth(10);
$sheet2->getColumnDimension('B')->setWidth(20);
$sheet2->getColumnDimension('C')->setWidth(16);
$sheet2->getColumnDimension('D')->setWidth(38);
$sheet2->getColumnDimension('E')->setWidth(22);
$sheet2->getColumnDimension('F')->setWidth(22);
$sheet2->getColumnDimension('G')->setWidth(16);
$sheet2->getColumnDimension('H')->setWidth(28);
$sheet2->getColumnDimension('I')->setWidth(24);


// ═════════════════════════════════════════════════════════════════════════════
// SHEET 3: DISTRIBUSI CACAT MUTU & DIAGRAM ANALITIK
// ═════════════════════════════════════════════════════════════════════════════
$sheet3 = $spreadsheet->createSheet();
$sheet3->setTitle('Distribusi Cacat Mutu');
$sheet3->setShowGridLines(true);

// Header Sheet 3
$sheet3->mergeCells('A1:K1');
$sheet3->setCellValue('A1', 'PT. SURYA TECHNOLOGY INDUSTRI — OUTGOING QUALITY CONTROL DIVISION');
$sheet3->getStyle('A1')->getFont()->setBold(true)->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('64748B'));

$sheet3->mergeCells('A2:K2');
$sheet3->setCellValue('A2', 'DISTRIBUSI TEMUAN CACAT MUTU & ANALITIK HARIAN (DEFECT BREAKDOWN)');
$sheet3->getStyle('A2')->getFont()->setBold(true)->setSize(15)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('0F172A'));

$sheet3->mergeCells('A3:K3');
$sheet3->setCellValue('A3', "Hari, Tanggal: {$dayNameId}, {$formattedDateId}  |  Total Temuan Cacat: " . number_format($daySummary['total_ng']) . " pcs NG");
$sheet3->getStyle('A3')->getFont()->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('475569'));
$sheet3->getRowDimension(3)->setRowHeight(22);

if ($daySummary['total_ng'] === 0) {
    $sheet3->mergeCells('A5:K6');
    $sheet3->setCellValue('A5', 'KUALITAS PRODUKSI PRIMA: Seluruh pemeriksaan pada tanggal ini tidak menemukan cacat mutu (0 pcs NG / All Clean).');
    $sheet3->getStyle('A5')->getFont()->setBold(true)->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('166534'));
    $sheet3->getStyle('A5:K6')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F0FDF4');
    $sheet3->getStyle('A5:K6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $sheet3->getStyle('A5:K6')->applyFromArray($borderThin);
} else {
    $s3Row = 5;

    // ── Bagian 1: Peringkat Jenis Defect (Top Defect Types) ───────────────
    $sec1Start = $s3Row;
    $sheet3->mergeCells("A{$s3Row}:D{$s3Row}");
    $sheet3->setCellValue("A{$s3Row}", "1. PERINGKAT JENIS DEFECT (TOP DEFECT TYPES)");
    $sheet3->getStyle("A{$s3Row}")->getFont()->setBold(true)->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFF'));
    $sheet3->getStyle("A{$s3Row}:D{$s3Row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DC2626');
    $sheet3->getStyle("A{$s3Row}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1);
    $sheet3->getRowDimension($s3Row)->setRowHeight(24);
    $s3Row++;

    $sheet3->setCellValue("A{$s3Row}", "No");
    $sheet3->setCellValue("B{$s3Row}", "Jenis Cacat (Defect Name)");
    $sheet3->setCellValue("C{$s3Row}", "Kuantitas (pcs)");
    $sheet3->setCellValue("D{$s3Row}", "Persentase (%)");
    $sheet3->getStyle("A{$s3Row}:D{$s3Row}")->getFont()->setBold(true)->setSize(9)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('991B1B'));
    $sheet3->getStyle("A{$s3Row}:D{$s3Row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEE2E2');
    $sheet3->getStyle("A{$s3Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet3->getStyle("C{$s3Row}:D{$s3Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet3->getStyle("A{$s3Row}:D{$s3Row}")->applyFromArray($borderThin);
    $s3Row++;

    $chartStartDefectRow = $s3Row;
    $totalDefectQty = (int)array_sum(array_column($dailyDefectRows, 'total_qty'));
    $totalDefectDiv = max(1, $totalDefectQty);
    $dIdx = 1;
    foreach ($dailyDefectRows as $dRow) {
        $pct = round(($dRow['total_qty'] / $totalDefectDiv) * 100, 1);
        $sheet3->setCellValue("A{$s3Row}", $dIdx++);
        $sheet3->setCellValue("B{$s3Row}", $dRow['defect_name']);
        $sheet3->setCellValue("C{$s3Row}", (int)$dRow['total_qty']);
        $sheet3->setCellValue("D{$s3Row}", $pct . "%");

        $sheet3->getStyle("A{$s3Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet3->getStyle("B{$s3Row}")->getFont()->setBold(true);
        $sheet3->getStyle("C{$s3Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet3->getStyle("C{$s3Row}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet3->getStyle("D{$s3Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet3->getStyle("A{$s3Row}:D{$s3Row}")->applyFromArray($borderThin);
        $sheet3->getRowDimension($s3Row)->setRowHeight(19);
        $s3Row++;
    }
    $chartEndDefectRow = $s3Row - 1;

    // Native Excel Pie Chart untuk Top Defect Types
    if (!empty($dailyDefectRows) && $chartEndDefectRow >= $chartStartDefectRow) {
        $dataSeriesLabels1 = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Distribusi Cacat Mutu'!\$C\$" . ($sec1Start + 1), null, 1),
        ];
        $xAxisTickValues1 = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Distribusi Cacat Mutu'!\$B\${$chartStartDefectRow}:\$B\${$chartEndDefectRow}", null, count($dailyDefectRows)),
        ];
        $dataSeriesValues1 = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'Distribusi Cacat Mutu'!\$C\${$chartStartDefectRow}:\$C\${$chartEndDefectRow}", null, count($dailyDefectRows)),
        ];

        $series1 = new DataSeries(
            DataSeries::TYPE_PIECHART,
            null,
            range(0, count($dataSeriesValues1) - 1),
            $dataSeriesLabels1,
            $xAxisTickValues1,
            $dataSeriesValues1
        );

        $plotArea1 = new PlotArea(null, [$series1]);
        $legend1 = new Legend(Legend::POSITION_RIGHT, null, false);
        $title1 = new Title('Proporsi Jenis Cacat (Top Defect Types)');

        $chart1 = new Chart(
            'chart_top_defects',
            $title1,
            $legend1,
            $plotArea1,
            true,
            DataSeries::EMPTY_AS_GAP
        );

        $chart1EndRow = $sec1Start + 13;
        $chart1->setTopLeftPosition("F{$sec1Start}");
        $chart1->setBottomRightPosition("K{$chart1EndRow}");
        $sheet3->addChart($chart1);

        $s3Row = max($chartEndDefectRow, $chart1EndRow) + 3;
    } else {
        $s3Row += 2;
    }

    // ── Bagian 2: Peringkat Model Terdampak (Top Models) ───────────────────
    $sec2Start = $s3Row;
    $sheet3->mergeCells("A{$s3Row}:D{$s3Row}");
    $sheet3->setCellValue("A{$s3Row}", "2. PERINGKAT MODEL TERDAMPAK (TOP MODELS)");
    $sheet3->getStyle("A{$s3Row}")->getFont()->setBold(true)->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFF'));
    $sheet3->getStyle("A{$s3Row}:D{$s3Row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2563EB');
    $sheet3->getStyle("A{$s3Row}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1);
    $sheet3->getRowDimension($s3Row)->setRowHeight(24);
    $s3Row++;

    $sheet3->setCellValue("A{$s3Row}", "No");
    $sheet3->setCellValue("B{$s3Row}", "Nama Model");
    $sheet3->setCellValue("C{$s3Row}", "Kuantitas Cacat (pcs)");
    $sheet3->setCellValue("D{$s3Row}", "Persentase (%)");
    $sheet3->getStyle("A{$s3Row}:D{$s3Row}")->getFont()->setBold(true)->setSize(9)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('1E40AF'));
    $sheet3->getStyle("A{$s3Row}:D{$s3Row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DBEAFE');
    $sheet3->getStyle("A{$s3Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet3->getStyle("C{$s3Row}:D{$s3Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet3->getStyle("A{$s3Row}:D{$s3Row}")->applyFromArray($borderThin);
    $s3Row++;

    $chartStartModelRow = $s3Row;
    $totalModelQty = (int)array_sum(array_column($dailyModelRows, 'total_qty'));
    $totalModelDiv = max(1, $totalModelQty);
    $mIdx = 1;
    foreach ($dailyModelRows as $mRow) {
        $pct = round(($mRow['total_qty'] / $totalModelDiv) * 100, 1);
        $sheet3->setCellValue("A{$s3Row}", $mIdx++);
        $sheet3->setCellValue("B{$s3Row}", $mRow['model_name']);
        $sheet3->setCellValue("C{$s3Row}", (int)$mRow['total_qty']);
        $sheet3->setCellValue("D{$s3Row}", $pct . "%");

        $sheet3->getStyle("A{$s3Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet3->getStyle("B{$s3Row}")->getFont()->setBold(true);
        $sheet3->getStyle("C{$s3Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet3->getStyle("C{$s3Row}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet3->getStyle("D{$s3Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet3->getStyle("A{$s3Row}:D{$s3Row}")->applyFromArray($borderThin);
        $sheet3->getRowDimension($s3Row)->setRowHeight(19);
        $s3Row++;
    }
    $chartEndModelRow = $s3Row - 1;

    // Native Excel Pie Chart untuk Top Models
    if (!empty($dailyModelRows) && $chartEndModelRow >= $chartStartModelRow) {
        $dataSeriesLabels2 = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Distribusi Cacat Mutu'!\$C\$" . ($sec2Start + 1), null, 1),
        ];
        $xAxisTickValues2 = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Distribusi Cacat Mutu'!\$B\${$chartStartModelRow}:\$B\${$chartEndModelRow}", null, count($dailyModelRows)),
        ];
        $dataSeriesValues2 = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'Distribusi Cacat Mutu'!\$C\${$chartStartModelRow}:\$C\${$chartEndModelRow}", null, count($dailyModelRows)),
        ];

        $series2 = new DataSeries(
            DataSeries::TYPE_PIECHART,
            null,
            range(0, count($dataSeriesValues2) - 1),
            $dataSeriesLabels2,
            $xAxisTickValues2,
            $dataSeriesValues2
        );

        $plotArea2 = new PlotArea(null, [$series2]);
        $legend2 = new Legend(Legend::POSITION_RIGHT, null, false);
        $title2 = new Title('Proporsi Model Terdampak (Top Models)');

        $chart2 = new Chart(
            'chart_top_models',
            $title2,
            $legend2,
            $plotArea2,
            true,
            DataSeries::EMPTY_AS_GAP
        );

        $chart2EndRow = $sec2Start + 13;
        $chart2->setTopLeftPosition("F{$sec2Start}");
        $chart2->setBottomRightPosition("K{$chart2EndRow}");
        $sheet3->addChart($chart2);

        $s3Row = max($chartEndModelRow, $chart2EndRow) + 3;
    } else {
        $s3Row += 2;
    }

    // ── Bagian 3: Peringkat Part Terdampak (Top Parts) ─────────────────────
    $sec3Start = $s3Row;
    $sheet3->mergeCells("A{$s3Row}:E{$s3Row}");
    $sheet3->setCellValue("A{$s3Row}", "3. PERINGKAT PART TERDAMPAK (TOP PARTS)");
    $sheet3->getStyle("A{$s3Row}")->getFont()->setBold(true)->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFF'));
    $sheet3->getStyle("A{$s3Row}:E{$s3Row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('059669');
    $sheet3->getStyle("A{$s3Row}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1);
    $sheet3->getRowDimension($s3Row)->setRowHeight(24);
    $s3Row++;

    $sheet3->setCellValue("A{$s3Row}", "No");
    $sheet3->setCellValue("B{$s3Row}", "Kode Part");
    $sheet3->setCellValue("C{$s3Row}", "Nama Part");
    $sheet3->setCellValue("D{$s3Row}", "Kuantitas Cacat (pcs)");
    $sheet3->setCellValue("E{$s3Row}", "Persentase (%)");
    $sheet3->getStyle("A{$s3Row}:E{$s3Row}")->getFont()->setBold(true)->setSize(9)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('065F46'));
    $sheet3->getStyle("A{$s3Row}:E{$s3Row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D1FAE5');
    $sheet3->getStyle("A{$s3Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet3->getStyle("D{$s3Row}:E{$s3Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet3->getStyle("A{$s3Row}:E{$s3Row}")->applyFromArray($borderThin);
    $s3Row++;

    $chartStartPartRow = $s3Row;
    $totalPartQty = (int)array_sum(array_column($dailyPartRows, 'total_qty'));
    $totalPartDiv = max(1, $totalPartQty);
    $pIdx = 1;
    foreach ($dailyPartRows as $pRow) {
        $pct = round(($pRow['total_qty'] / $totalPartDiv) * 100, 1);
        $sheet3->setCellValue("A{$s3Row}", $pIdx++);
        $sheet3->setCellValue("B{$s3Row}", $pRow['part_code']);
        $sheet3->setCellValue("C{$s3Row}", $pRow['part_name']);
        $sheet3->setCellValue("D{$s3Row}", (int)$pRow['total_qty']);
        $sheet3->setCellValue("E{$s3Row}", $pct . "%");

        $sheet3->getStyle("A{$s3Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet3->getStyle("B{$s3Row}")->getFont()->setBold(true);
        $sheet3->getStyle("D{$s3Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet3->getStyle("D{$s3Row}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet3->getStyle("E{$s3Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet3->getStyle("A{$s3Row}:E{$s3Row}")->applyFromArray($borderThin);
        $sheet3->getRowDimension($s3Row)->setRowHeight(19);
        $s3Row++;
    }
    $chartEndPartRow = $s3Row - 1;

    // Native Excel Pie Chart untuk Top Parts
    if (!empty($dailyPartRows) && $chartEndPartRow >= $chartStartPartRow) {
        $dataSeriesLabels3 = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Distribusi Cacat Mutu'!\$D\$" . ($sec3Start + 1), null, 1),
        ];
        $xAxisTickValues3 = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Distribusi Cacat Mutu'!\$C\${$chartStartPartRow}:\$C\${$chartEndPartRow}", null, count($dailyPartRows)),
        ];
        $dataSeriesValues3 = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'Distribusi Cacat Mutu'!\$D\${$chartStartPartRow}:\$D\${$chartEndPartRow}", null, count($dailyPartRows)),
        ];

        $series3 = new DataSeries(
            DataSeries::TYPE_PIECHART,
            null,
            range(0, count($dataSeriesValues3) - 1),
            $dataSeriesLabels3,
            $xAxisTickValues3,
            $dataSeriesValues3
        );

        $plotArea3 = new PlotArea(null, [$series3]);
        $legend3 = new Legend(Legend::POSITION_RIGHT, null, false);
        $title3 = new Title('Proporsi Part Terdampak (Top Parts)');

        $chart3 = new Chart(
            'chart_top_parts',
            $title3,
            $legend3,
            $plotArea3,
            true,
            DataSeries::EMPTY_AS_GAP
        );

        $chart3EndRow = $sec3Start + 13;
        $chart3->setTopLeftPosition("G{$sec3Start}");
        $chart3->setBottomRightPosition("L{$chart3EndRow}");
        $sheet3->addChart($chart3);

        $s3Row = max($chartEndPartRow, $chart3EndRow) + 3;
    } else {
        $s3Row += 2;
    }
}

// Column dimensions Sheet 3
$sheet3->getColumnDimension('A')->setWidth(7);
$sheet3->getColumnDimension('B')->setWidth(26);
$sheet3->getColumnDimension('C')->setWidth(30);
$sheet3->getColumnDimension('D')->setWidth(22);
$sheet3->getColumnDimension('E')->setWidth(18);
$sheet3->getColumnDimension('F')->setWidth(16);
$sheet3->getColumnDimension('G')->setWidth(16);
$sheet3->getColumnDimension('H')->setWidth(16);
$sheet3->getColumnDimension('I')->setWidth(16);
$sheet3->getColumnDimension('J')->setWidth(16);
$sheet3->getColumnDimension('K')->setWidth(16);
$sheet3->getColumnDimension('L')->setWidth(16);

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
