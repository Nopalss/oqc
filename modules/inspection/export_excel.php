<?php
/**
 * Visual Excel Report Generator — Riwayat Inspeksi OQC (My Inspections / Inspector History View)
 * Mirroring format eksekutif modules/daily_report/export_excel.php:
 * 1. Sheet 1: Rincian Per Lot (Flat Data Table & Dynamic Defect Matrix Crosstab)
 * 2. Sheet 2: Kronologi Sesi Inspeksi (Urutan pengerjaan sesi di meja QC, sub-lots box breakdown, status approval)
 * 3. Sheet 3: Distribusi Cacat Mutu & Analitik Diagram (Top Defect Types, Top Models, Top Parts, Native Excel Pie Chart)
 *
 * PT. Surya Technology Industri — Outgoing Quality Control Division
 */

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';
require_once __DIR__ . '/../../vendor/autoload.php';

require_menu_access('inspection');

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

$currentUser = current_user();
$currentUserId = (int)($currentUser['id'] ?? 0);

// ── Parameter Filters ─────────────────────────────────────────────────────────
$tab          = sanitize($_GET['tab'] ?? 'my_inspections');
$period       = sanitize($_GET['period'] ?? 'today');
$startDate    = sanitize($_GET['start_date'] ?? '');
$endDate      = sanitize($_GET['end_date'] ?? '');
$search       = sanitize($_GET['search'] ?? '');
$statusFilter = sanitize($_GET['status'] ?? 'all');
$sort         = (isset($_GET['sort']) && strtolower($_GET['sort']) === 'asc') ? 'ASC' : 'DESC';

// Khusus riwayat inspektur yang login (atau inspektur spesifik jika difilter oleh supervisor/admin)
$targetInspectorId = $currentUserId;
if (isset($_GET['inspector_id']) && (int)$_GET['inspector_id'] > 0 && in_array($currentUser['role'] ?? '', ['supervisor', 'admin', 'superadmin'], true)) {
    $targetInspectorId = (int)$_GET['inspector_id'];
}

$targetInspectorName = $currentUser['name'] ?? 'Inspektur';
if ($targetInspectorId !== $currentUserId) {
    try {
        $stmtInspName = $pdo->prepare("SELECT name FROM users WHERE id = :id");
        $stmtInspName->execute([':id' => $targetInspectorId]);
        $fetchedName = $stmtInspName->fetchColumn();
        if ($fetchedName) {
            $targetInspectorName = $fetchedName;
        }
    } catch (Exception $e) {}
}

if (!empty($startDate) || !empty($endDate)) {
    $period = 'custom';
}

// ── Clean QR Code search pattern (Z1<part_code>|Z2<lot>|...) ──────────────────
if ($search !== '' && (strpos($search, 'Z1') !== false || strpos($search, '|') !== false)) {
    $parts = explode('|', $search);
    foreach ($parts as $p) {
        $p = trim($p);
        if (strpos($p, 'Z1') === 0) {
            $search = substr($p, 2);
            break;
        }
    }
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

// ── Date Boundaries ──────────────────────────────────────────────────────────
$todayStart = date('Y-m-d 00:00:00');
$todayEnd   = date('Y-m-d 23:59:59');
$wStart     = date('Y-m-d 00:00:00', strtotime('monday this week'));
$wEnd       = date('Y-m-d 23:59:59', strtotime('sunday this week'));
$mStart     = date('Y-m-01 00:00:00');
$mEnd       = date('Y-m-t 23:59:59');

if ($period === 'today') {
    $periodLabel = "Hari Ini (" . date('d F Y') . ")";
    $dateTitleLabel = date('Y-m-d');
} elseif ($period === 'this_week') {
    $periodLabel = "Minggu Ini (" . date('d/m/Y', strtotime($wStart)) . " s.d. " . date('d/m/Y', strtotime($wEnd)) . ")";
    $dateTitleLabel = "Minggu_" . date('W_Y');
} elseif ($period === 'this_month') {
    $periodLabel = "Bulan Ini (" . date('F Y') . ")";
    $dateTitleLabel = "Bulan_" . date('m_Y');
} elseif ($period === 'custom') {
    $periodLabel = "Periode " . ($startDate ? date('d/m/Y', strtotime($startDate)) : 'Awal') . " s.d. " . ($endDate ? date('d/m/Y', strtotime($endDate)) : 'Akhir');
    $dateTitleLabel = ($startDate ?: 'awal') . "_sd_" . ($endDate ?: 'akhir');
} else {
    $periodLabel = "Semua Riwayat Inspeksi";
    $dateTitleLabel = "Semua_Riwayat";
}

$tableWhere = ["s.inspector_id = :my_uid"];
$tableParams = [':my_uid' => $targetInspectorId];

if ($period === 'today') {
    $tableWhere[] = "s.started_at BETWEEN :my_t_start AND :my_t_end";
    $tableParams[':my_t_start'] = $todayStart;
    $tableParams[':my_t_end']   = $todayEnd;
} elseif ($period === 'this_week') {
    $tableWhere[] = "s.started_at BETWEEN :my_w_start AND :my_w_end";
    $tableParams[':my_w_start'] = $wStart;
    $tableParams[':my_w_end']   = $wEnd;
} elseif ($period === 'this_month') {
    $tableWhere[] = "s.started_at BETWEEN :my_m_start AND :my_m_end";
    $tableParams[':my_m_start'] = $mStart;
    $tableParams[':my_m_end']   = $mEnd;
} elseif ($period === 'custom') {
    if (!empty($startDate) && !empty($endDate)) {
        $tableWhere[] = "s.started_at BETWEEN :my_c_start AND :my_c_end";
        $tableParams[':my_c_start'] = $startDate . ' 00:00:00';
        $tableParams[':my_c_end']   = $endDate . ' 23:59:59';
    } elseif (!empty($startDate)) {
        $tableWhere[] = "s.started_at >= :my_c_start";
        $tableParams[':my_c_start'] = $startDate . ' 00:00:00';
    } elseif (!empty($endDate)) {
        $tableWhere[] = "s.started_at <= :my_c_end";
        $tableParams[':my_c_end']   = $endDate . ' 23:59:59';
    }
}

if ($search !== '') {
    $tableWhere[] = "(
        s.id LIKE :my_s1 
        OR mp.part_code LIKE :my_s2 
        OR mp.part_name LIKE :my_s3 
        OR did.part_code LIKE :my_s4 
        OR did.part_name LIKE :my_s5 
        OR k.kanban_no LIKE :my_s6 
        OR mm.name LIKE :my_s7 
        OR EXISTS (
            SELECT 1 FROM inspection_session_lots isl_srch 
            WHERE isl_srch.inspection_session_id = s.id 
              AND (isl_srch.lot_number LIKE :my_s8 OR isl_srch.ref_number LIKE :my_s9)
        )
    )";
    $like = '%' . $search . '%';
    $tableParams[':my_s1'] = $like;
    $tableParams[':my_s2'] = $like;
    $tableParams[':my_s3'] = $like;
    $tableParams[':my_s4'] = $like;
    $tableParams[':my_s5'] = $like;
    $tableParams[':my_s6'] = $like;
    $tableParams[':my_s7'] = $like;
    $tableParams[':my_s8'] = $like;
    $tableParams[':my_s9'] = $like;
}

if ($statusFilter === 'in_progress') {
    $tableWhere[] = "s.status = 'in_progress'";
} elseif ($statusFilter === 'completed') {
    $tableWhere[] = "s.status = 'passed'";
} elseif ($statusFilter === 'rejected') {
    $tableWhere[] = "s.status = 'rejected'";
}

// Exclude Direct Close Kanban virtual sessions from physical inspection report
$tableWhere[] = "(s.approval_notes IS NULL OR s.approval_notes NOT LIKE '%Direct Close%')";
$tableWhere[] = "NOT EXISTS (
    SELECT 1 FROM inspection_session_lots isl_dc 
    WHERE isl_dc.inspection_session_id = s.id 
      AND (isl_dc.ref_number = 'DIRECT-PASS' OR isl_dc.ref_number LIKE '%DIRECT-PASS%' OR isl_dc.remarks LIKE '%Direct Close%')
)";

// Exclude Auto Safety Stock virtual sessions from leftover/excess kanban (hanya inspeksi fisik real)
$tableWhere[] = "(k.remark IS NULL OR (
    k.remark NOT LIKE '%AUTO-SS-SESS-%' 
    AND k.remark NOT LIKE '%Auto Safety Stock Sisa Excess%' 
    AND k.remark NOT LIKE '%Sisa Excess Kanban%'
    AND k.remark NOT LIKE '%Sisa Stok Safety Stock (Split%'
))";
$tableWhere[] = "NOT EXISTS (
    SELECT 1 FROM inspection_session_lots isl_sisa 
    WHERE isl_sisa.inspection_session_id = s.id 
      AND (
          isl_sisa.remarks LIKE '%Sisa Kelebihan Kanban%' 
          OR isl_sisa.remarks LIKE '%Sisa Split Safety Stock%'
      )
)";

$tableWhereClause = implode(" AND ", $tableWhere);

$rawSessions     = [];
$sessionTimeline = [];
$daySummary = [
    'total_sesi'              => 0,
    'total_label'             => 0,
    'total_sampling'          => 0,
    'total_qty'               => 0,
    'total_ng'                => 0,
    'passed_sesi'             => 0,
    'rejected_sesi'           => 0,
    'in_progress_sesi'        => 0,
    'total_approved_sessions' => 0,
    'total_pending_sessions'  => 0,
];

$dailyDefectRows = [];
$dailyModelRows  = [];
$dailyPartRows   = [];

try {
    $sql = "
        SELECT 
            s.id AS session_id,
            s.inspection_type,
            s.kanban_item_id,
            s.did_id,
            s.part_id,
            s.sample_size,
            s.total_scanned_qty,
            s.excess_qty,
            s.use_safety_stock_qty,
            s.reject_number,
            s.samples_checked,
            s.ng_count,
            s.status AS session_status,
            s.auto_fulfilled_by_session_id,
            s.original_ss_session_id,
            s.parent_session_id,
            s.is_reinspection,
            s.reinspection_type,
            s.reinspection_notes,
            s.started_at,
            s.closed_at,
            s.is_approved,
            s.approved_by,
            s.approved_at,
            s.approval_notes,
            COALESCE(u.name, 'Inspector') AS inspector_name,
            COALESCE(u_app.name, '-') AS supervisor_name,
            COALESCE(k.kanban_no, '-') AS kanban_no,
            COALESCE(k.customer, 'INTERNAL') AS customer,
            k.item_code AS kanban_item_code,
            k.item_description AS kanban_item_desc,
            COALESCE(k.qty, 0) AS kanban_qty,
            did.part_code AS did_part_code,
            did.part_name AS did_part_name,
            did.lot_number AS did_lot_number,
            did.cavity AS did_cavity,
            did.pic AS did_pic,
            COALESCE(did.part_code, k.item_code, mp.part_code, '-') AS part_code,
            COALESCE(did.part_name, k.item_description, mp.part_name, '-') AS part_name,
            COALESCE(mm.name, mp.model, '-') AS model_name
        FROM inspection_sessions s
        LEFT JOIN users u ON u.id = s.inspector_id
        LEFT JOIN users u_app ON u_app.id = s.approved_by
        LEFT JOIN kanban_items k ON k.id = s.kanban_item_id
        LEFT JOIN daily_inspection_data did ON did.id = s.did_id
        LEFT JOIN master_parts mp ON (mp.id = s.part_id OR UPPER(mp.part_code) = UPPER(COALESCE(did.part_code, k.item_code)))
        LEFT JOIN master_models mm ON (mm.id = mp.model_id)
        WHERE {$tableWhereClause}
        ORDER BY s.started_at {$sort}, s.id {$sort}
    ";
    $stmtSessions = $pdo->prepare($sql);
    $stmtSessions->execute($tableParams);
    $rawSessions = $stmtSessions->fetchAll(PDO::FETCH_ASSOC);

    $sessMap = [];
    foreach ($rawSessions as $s) {
        $sessMap[(int)$s['session_id']] = $s;
    }

    $allLotsMap = [];
    $allNgMap = [];
    $allUnmatchedNgMap = [];
    $substByLot = [];
    $reinspectBySession = [];

    if (!empty($rawSessions)) {
        $sessIds = array_map(fn($s) => (int)$s['session_id'], $rawSessions);
        $inSessIds = implode(',', $sessIds);

        // Fetch Lots
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
              AND (isl.remarks IS NULL OR (isl.remarks NOT LIKE '%Sisa Split Safety Stock%' AND isl.remarks NOT LIKE '%Sisa Kelebihan Kanban%' AND isl.remarks NOT LIKE '%Direct Close%'))
              AND (isl.ref_number IS NULL OR (isl.ref_number != 'DIRECT-PASS' AND isl.ref_number NOT LIKE '%DIRECT-PASS%'))
            ORDER BY isl.id ASC
        ");
        $rawLotsList = [];
        if ($stmtLots) {
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
        }

        // Fetch Lot Substitutions / Reinspection Logs
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

        // Fetch NG records
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
        if ($stmtNg) {
            foreach ($stmtNg->fetchAll(PDO::FETCH_ASSOC) as $ngRow) {
                $sId = (int)$ngRow['inspection_session_id'];
                $allNgMap[$sId][] = $ngRow;
            }
        }

        // Distribute NG defects to lots
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

                        // Row 1: Initial Attempt
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
                    $lRow['is_replaced'] = true;
                }

                $sessInfo = $sessMap[$sId] ?? [];
                $isReinspectionSess = !empty($lRow['has_prev_rejected'])
                    || !empty($sessInfo['is_reinspection']) 
                    || !empty($sessInfo['reinspection_type']) 
                    || !empty($sessInfo['parent_session_id']) 
                    || !empty($lRow['is_reinspected_attempt']) 
                    || !empty($lRow['is_reinspected_lot'])
                    || (($lRow['attempt_type'] ?? '') === 'reinspection')
                    || (strtolower($lRow['lot_status'] ?? '') === 'reinspected')
                    || (isset($lRow['remarks']) && (strripos($lRow['remarks'], 're-inspeksi') !== false || strripos($lRow['remarks'], 'reinspeksi') !== false || strripos($lRow['remarks'], 're-inspect') !== false || strripos($lRow['remarks'], 'reinspect') !== false))
                    || (isset($sessInfo['reinspection_notes']) && !empty($sessInfo['reinspection_notes']))
                    || (isset($sessInfo['remarks']) && (strripos($sessInfo['remarks'], 're-inspeksi') !== false || strripos($sessInfo['remarks'], 'reinspeksi') !== false));

                // DB Fallback if not detected yet
                if (!$isReinspectionSess && !empty($lRow['lot_number']) && !empty($lRow['lot_id'])) {
                    static $prevRejectedExpCache = [];
                    $cacheKey = $sId . '_' . (int)$lRow['lot_id'];
                    if (!isset($prevRejectedExpCache[$cacheKey])) {
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
                            $prevRejectedExpCache[$cacheKey] = (bool)$chkStmt->fetchColumn();
                        } catch (Exception $e) {
                            $prevRejectedExpCache[$cacheKey] = false;
                        }
                    }
                    if (!empty($prevRejectedExpCache[$cacheKey])) {
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

        // Fetch Reinspection Stats
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

        // Build Analytics for Sheet 3
        try {
            // 1. Top Defect Types (NG Pcs)
            $stmtD = $pdo->prepare("
                SELECT dt.name AS defect_name, SUM(ngr.qty_ng) AS total_qty
                FROM inspection_ng_records ngr
                JOIN defect_types dt ON dt.id = ngr.defect_type_id
                WHERE ngr.inspection_session_id IN ({$inSessIds})
                  AND (ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)
                GROUP BY dt.id, dt.name
                HAVING total_qty > 0
                ORDER BY total_qty DESC
                LIMIT 10
            ");
            $stmtD->execute();
            $dailyDefectRows = $stmtD->fetchAll(PDO::FETCH_ASSOC);

            // 2. Top Models (NG Pcs)
            $stmtM = $pdo->prepare("
                SELECT COALESCE(mm.name, mp.model, 'Tanpa Model') AS model_name, SUM(ngr.qty_ng) AS total_qty
                FROM inspection_ng_records ngr
                JOIN inspection_sessions s ON s.id = ngr.inspection_session_id
                LEFT JOIN daily_inspection_data did ON did.id = s.did_id
                LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
                LEFT JOIN master_parts mp ON (mp.id = s.part_id OR UPPER(mp.part_code) = UPPER(COALESCE(did.part_code, ki.item_code)))
                LEFT JOIN master_models mm ON mm.id = mp.model_id
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
        } catch (PDOException $e) {}

        // Build Timeline
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
            } else {
                $daySummary['in_progress_sesi']++;
            }

            if (!empty($sess['is_approved']) && (int)$sess['is_approved'] === 1) {
                $daySummary['total_approved_sessions']++;
            } else {
                $daySummary['total_pending_sessions']++;
            }
        }
    }
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

// ── 2. Create Spreadsheet ─────────────────────────────────────────────────────
$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator("PT. Surya Technology Industri — OQC")
    ->setLastModifiedBy($targetInspectorName)
    ->setTitle("Laporan Riwayat Inspeksi " . $targetInspectorName . " - " . $dateTitleLabel)
    ->setSubject("OQC Inspector Inspection Detail Report")
    ->setDescription("Laporan Rincian Inspeksi OQC — Inspektur: " . $targetInspectorName . " — " . $periodLabel);

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
$sheet1->setCellValue('A2', 'LAPORAN RINCIAN INSPEKSI SAYA PER LOT / BOX (FLAT DATA TABLE & DEFECT MATRIX)');
$sheet1->getStyle('A2')->getFont()->setBold(true)->setSize(15)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('0F172A'));

$sheet1->mergeCells('A3:N3');
$sheet1->setCellValue('A3', "Inspektur: {$targetInspectorName}  |  Periode: {$periodLabel}  |  Dicetak: " . date('d/m/Y H:i') . " WIB oleh " . ($currentUser['name'] ?? 'System'));
$sheet1->getStyle('A3')->getFont()->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('475569'));
$sheet1->getRowDimension(3)->setRowHeight(22);

// 2. Fetch Defect Master List
$defectTypesMaster = [];
try {
    $stmtDefectsMaster = $pdo->query("SELECT id, name FROM defect_types ORDER BY id ASC");
    if ($stmtDefectsMaster) {
        $defectTypesMaster = $stmtDefectsMaster->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {}

// 3. Mapping NG records for lot rows
$ngByLotMap = [];
$ngBySessionMap = [];
if (!empty($rawSessions)) {
    foreach ($allNgMap as $sId => $ngList) {
        foreach ($ngList as $ngR) {
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
}

// 4. Build Flat Lot Rows List
$flatLotRows = [];
if (!empty($rawSessions)) {
    foreach ($rawSessions as $sess) {
        $sId = (int)$sess['session_id'];
        $sessLots = $allLotsMap[$sId] ?? [];

        if (!empty($sessLots)) {
            foreach ($sessLots as $sl) {
                // Skip DIRECT-PASS virtual lots and leftover/excess lots
                $refNo = trim($sl['ref_number'] ?? '');
                $remarks = trim($sl['remarks'] ?? '');
                if ($refNo === 'DIRECT-PASS' || stripos($refNo, 'DIRECT-PASS') !== false || stripos($remarks, 'Direct Close') !== false || stripos($remarks, 'Sisa Kelebihan Kanban') !== false || stripos($remarks, 'Sisa Split Safety Stock') !== false) {
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
                    static $prevRejectedFlatCache = [];
                    $cacheKey = $sId . '_' . (int)$sl['lot_id'];
                    if (!isset($prevRejectedFlatCache[$cacheKey])) {
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
                            $prevRejectedFlatCache[$cacheKey] = (bool)$chkStmt->fetchColumn();
                        } catch (Exception $e) {
                            $prevRejectedFlatCache[$cacheKey] = false;
                        }
                    }
                    if (!empty($prevRejectedFlatCache[$cacheKey])) {
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
    $sheet1->setCellValue("A{$rIdx}", "Tidak ada riwayat inspeksi yang ditemukan untuk filter yang dipilih.");
    $sheet1->getStyle("A{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $sheet1->getStyle("A{$rIdx}")->getFont()->setItalic(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('64748B'));
    $sheet1->getStyle("A{$rIdx}:O{$rIdx}")->applyFromArray($borderThin);
    $sheet1->getRowDimension($rIdx)->setRowHeight(30);
    $rIdx++;
} else {
    foreach ($flatLotRows as $rowItem) {
        $bgRowColor = ($rIdx % 2 === 0) ? 'F8FAFC' : 'FFFFFF';

        // A: Tanggal
        $sheet1->setCellValue("A{$rIdx}", date('d/m/Y', strtotime($rowItem['started_at'])));
        $sheet1->getStyle("A{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // B: Jenis (KANBAN / SAFETY STOCK)
        $sheet1->setCellValue("B{$rIdx}", $rowItem['jenis']);
        $sheet1->getStyle("B{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet1->getStyle("B{$rIdx}")->getFont()->setBold(true);
        if ($rowItem['jenis'] === 'SAFETY STOCK') {
            $sheet1->getStyle("B{$rIdx}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('7C3AED'));
        } else {
            $sheet1->getStyle("B{$rIdx}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('2563EB'));
        }

        // C: Part Code
        $sheet1->setCellValue("C{$rIdx}", $rowItem['part_code']);

        // D: Part Name
        $sheet1->setCellValue("D{$rIdx}", $rowItem['part_name']);

        // E: Model
        $sheet1->setCellValue("E{$rIdx}", $rowItem['model_name'] ?: '-');
        $sheet1->getStyle("E{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // F: Lot No
        $sheet1->setCellValue("F{$rIdx}", $rowItem['lot_number']);

        // G: Ref No
        $sheet1->setCellValue("G{$rIdx}", $rowItem['ref_number']);
        $sheet1->getStyle("G{$rIdx}")->getFont()->setBold(true);

        // H: QTY Lot
        $sheet1->setCellValue("H{$rIdx}", (int)$rowItem['qty_lot']);
        $sheet1->getStyle("H{$rIdx}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet1->getStyle("H{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet1->getStyle("H{$rIdx}")->getFont()->setBold(true);

        // I: QTY Sampling
        $sheet1->setCellValue("I{$rIdx}", (int)$rowItem['qty_sampling']);
        $sheet1->getStyle("I{$rIdx}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet1->getStyle("I{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        // J: NG
        $sheet1->setCellValue("J{$rIdx}", (int)$rowItem['total_ng']);
        $sheet1->getStyle("J{$rIdx}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet1->getStyle("J{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet1->getStyle("J{$rIdx}")->getFont()->setBold(true);
        if ($rowItem['total_ng'] > 0) {
            $sheet1->getStyle("J{$rIdx}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('DC2626'));
        } else {
            $sheet1->getStyle("J{$rIdx}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('16A34A'));
        }

        // K: Defect
        $sheet1->setCellValue("K{$rIdx}", $rowItem['defect_summary']);

        // L: Status
        $statusStr = $rowItem['status_label'] ?? strtoupper(str_replace('_', ' ', $rowItem['session_status']));
        $statusCode = $rowItem['status_code'] ?? $rowItem['session_status'];
        $sheet1->setCellValue("L{$rIdx}", $statusStr);
        $sheet1->getStyle("L{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet1->getStyle("L{$rIdx}")->getFont()->setBold(true);
        if ($statusCode === 'passed') {
            $sheet1->getStyle("L{$rIdx}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DCFCE7');
            $sheet1->getStyle("L{$rIdx}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('15803D'));
        } elseif ($statusCode === 'in_progress') {
            $sheet1->getStyle("L{$rIdx}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E0E7FF');
            $sheet1->getStyle("L{$rIdx}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('3730A3'));
        } elseif ($statusCode === 'skipped') {
            $sheet1->getStyle("L{$rIdx}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F1F5F9');
            $sheet1->getStyle("L{$rIdx}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('64748B'));
        } else {
            $sheet1->getStyle("L{$rIdx}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFE4E6');
            $sheet1->getStyle("L{$rIdx}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('BE123C'));
        }

        // M: Sesi
        $sheet1->setCellValue("M{$rIdx}", "Sesi #" . $rowItem['session_id']);
        $sheet1->getStyle("M{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // N: Shift
        $shiftStr = getShiftName($rowItem['started_at']);
        $sheet1->setCellValue("N{$rIdx}", $shiftStr);
        $sheet1->getStyle("N{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // O: Inspektor
        $sheet1->setCellValue("O{$rIdx}", $rowItem['inspector_name']);

        // Standard Zebra & Border for Cols A-O
        $sheet1->getStyle("A{$rIdx}:K{$rIdx}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($bgRowColor);
        $sheet1->getStyle("M{$rIdx}:O{$rIdx}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($bgRowColor);
        $sheet1->getStyle("A{$rIdx}:O{$rIdx}")->applyFromArray($borderThin);

        // Gap Cols P to T (16 to 20): Blank
        for ($gCol = 16; $gCol <= 20; $gCol++) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($gCol);
            $sheet1->setCellValue($colLetter . $rIdx, '');
        }

        // Dynamic Defect Matrix (Cols U+ -> 21+)
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
            $sheet1->getStyle("{$colLetter}{$totRow}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet1->getStyle("{$colLetter}{$totRow}")->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('991B1B'));
            $sheet1->getStyle("{$colLetter}{$totRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet1->getStyle("{$colLetter}{$totRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEE2E2');
            $sheet1->getStyle("{$colLetter}{$totRow}")->applyFromArray($borderThin);
            $sheet1->getStyle("{$colLetter}{$totRow}")->applyFromArray($borderBottomDouble);
        }
    }
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
$sheet1->getColumnDimension('O')->setWidth(22); // Inspektor


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
$sheet2->setCellValue('A2', 'KRONOLOGI SELURUH SESI INSPEKSI SAYA (ACTIVITY TIMELINE)');
$sheet2->getStyle('A2')->getFont()->setBold(true)->setSize(15)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('0F172A'));

$sheet2->mergeCells('A3:I3');
$sheet2->setCellValue('A3', "Inspektur: {$targetInspectorName}  |  Periode: {$periodLabel}  |  Total {$daySummary['total_sesi']} Sesi");
$sheet2->getStyle('A3')->getFont()->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('475569'));
$sheet2->getRowDimension(3)->setRowHeight(22);

// Table Header Row 5
$s2Headers = [
    'Sesi', 'Tanggal & Jam', 'Tipe', 'Item Kanban / Part', 
    'Inspektor', 'Total Qty', 'Status Sesi', 'Persetujuan Supervisor', 'Sampling / AQL'
];
foreach ($s2Headers as $idx => $hText) {
    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 1);
    $sheet2->setCellValue($colLetter . '5', $hText);
    $sheet2->getStyle($colLetter . '5')->getFont()->setBold(true)->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFF'));
    $sheet2->getStyle($colLetter . '5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1E293B');
    $sheet2->getStyle($colLetter . '5')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    if (in_array($hText, ['Sesi', 'Tanggal & Jam', 'Tipe', 'Status Sesi', 'Persetujuan Supervisor', 'Sampling / AQL'])) {
        $sheet2->getStyle($colLetter . '5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    } elseif ($hText === 'Total Qty') {
        $sheet2->getStyle($colLetter . '5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }
}
$sheet2->getRowDimension(5)->setRowHeight(24);

$s2Row = 6;
if (empty($sessionTimeline)) {
    $sheet2->mergeCells("A{$s2Row}:I{$s2Row}");
    $sheet2->setCellValue("A{$s2Row}", "Tidak ada riwayat sesi inspeksi.");
    $sheet2->getStyle("A{$s2Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $sheet2->getStyle("A{$s2Row}")->getFont()->setItalic(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('64748B'));
    $sheet2->getStyle("A{$s2Row}:I{$s2Row}")->applyFromArray($borderThin);
    $sheet2->getRowDimension($s2Row)->setRowHeight(30);
    $s2Row++;
} else {
    foreach ($sessionTimeline as $stIdx => $sess) {
        $isKb = (!empty($sess['kanban_item_id']) && (int)$sess['kanban_item_id'] > 0);
        $isReins = ((int)$sess['is_reinspection'] === 1 || (int)$sess['parent_session_id'] > 0);

        $sheet2->setCellValue("A{$s2Row}", "#" . $sess['session_id']);
        $sheet2->setCellValue("B{$s2Row}", date('d/m/Y H:i', strtotime($sess['started_at'])));
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

        // Alignments
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

        // Sub-lots breakdown
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
                $refNo = trim($bLot['ref_number'] ?? '');
                $remarks = trim($bLot['remarks'] ?? '');
                if ($refNo === 'DIRECT-PASS' || stripos($refNo, 'DIRECT-PASS') !== false || stripos($remarks, 'Direct Close') !== false) {
                    continue;
                }

                $sheet2->setCellValue("C{$s2Row}", $bIdx + 1);

                $lotDesc = $bLot['lot_number'] ?: '-';
                if (!empty($bLot['is_reinspected_attempt'])) {
                    $lotDesc .= ' [Re-inspeksi]';
                } elseif (!empty($bLot['is_initial_attempt'])) {
                    $lotDesc .= ' [Uji Awal - NG]';
                } elseif (!empty($bLot['is_replaced'])) {
                    $lotDesc .= ' [Digantikan]';
                }
                $sheet2->setCellValue("D{$s2Row}", $lotDesc);
                $sheet2->setCellValue("E{$s2Row}", $bLot['ref_number'] ?: '-');
                $sheet2->setCellValue("F{$s2Row}", (int)$bLot['qty']);
                $sheet2->getStyle("F{$s2Row}")->getNumberFormat()->setFormatCode('#,##0');
                $sheet2->setCellValue("G{$s2Row}", (int)($bLot['computed_sample_size'] ?? 0));
                $sheet2->getStyle("G{$s2Row}")->getNumberFormat()->setFormatCode('#,##0');
                
                $lotNgCount = (int)($bLot['ng_count'] ?? 0);
                if ($lotNgCount === 0 && !empty($bLot['defects'])) {
                    foreach ($bLot['defects'] as $df) {
                        $lotNgCount += (int)($df['qty_ng'] ?? 1);
                    }
                }
                $sheet2->setCellValue("H{$s2Row}", $lotNgCount);
                if ($lotNgCount > 0) {
                    $sheet2->getStyle("H{$s2Row}")->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('DC2626'));
                }

                $scanTime = !empty($bLot['scanned_at']) ? date('H:i \W\I\B', strtotime($bLot['scanned_at'])) : '-';
                $sheet2->setCellValue("I{$s2Row}", $scanTime);

                $sheet2->getStyle("C{$s2Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet2->getStyle("F{$s2Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $sheet2->getStyle("G{$s2Row}:I{$s2Row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet2->getStyle("B{$s2Row}:I{$s2Row}")->getFont()->setSize(9);
                $sheet2->getStyle("B{$s2Row}:I{$s2Row}")->applyFromArray($borderThin);
                $sheet2->getRowDimension($s2Row)->setRowHeight(19);
                $s2Row++;

                // Inline reinspection action notes
                if (!empty($bLot['is_reinspected_attempt'])) {
                    $sheet2->mergeCells("C{$s2Row}:I{$s2Row}");
                    $inspName = !empty($bLot['actioned_by_name']) ? $bLot['actioned_by_name'] : 'Inspector';
                    $actTime = !empty($bLot['action_at']) ? date('H:i \W\I\B', strtotime($bLot['action_at'])) : date('H:i \W\I\B', strtotime($bLot['scanned_at']));
                    $reinsText = "     [RE-INSPEKSI] Tindakan: " . (!empty($bLot['action_notes']) ? $bLot['action_notes'] : 'Part NG disortir dan ditukar dengan part bagus -> Dilakukan re-inspeksi sampel fisik dan lolos uji.') . " | Inspector: " . $inspName . " | Jam: " . $actTime;
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
                $uText = "     [DEFECT SESI] • " . $udf['defect_name'] . ": " . (int)$udf['qty_ng'] . " pcs | Ditemukan oleh: " . $inspectorText . " | Jam: " . $timeText . (!empty($udf['remark']) ? " | Ket: " . $udf['remark'] : "");
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
}

// Column widths Sheet 2
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
$sheet3->setCellValue('A2', 'DISTRIBUSI TEMUAN CACAT MUTU & ANALITIK (DEFECT BREAKDOWN)');
$sheet3->getStyle('A2')->getFont()->setBold(true)->setSize(15)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('0F172A'));

$sheet3->mergeCells('A3:K3');
$sheet3->setCellValue('A3', "Inspektur: {$targetInspectorName}  |  Periode: {$periodLabel}  |  Total Temuan Cacat: " . number_format($daySummary['total_ng']) . " pcs NG");
$sheet3->getStyle('A3')->getFont()->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('475569'));
$sheet3->getRowDimension(3)->setRowHeight(22);

if ($daySummary['total_ng'] === 0) {
    $sheet3->mergeCells('A5:K6');
    $sheet3->setCellValue('A5', 'KUALITAS PRODUKSI PRIMA: Seluruh pemeriksaan pada periode ini tidak menemukan cacat mutu (0 pcs NG / All Clean).');
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
            'chart_my_top_defects',
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
            'chart_my_top_models',
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
            'chart_my_top_parts',
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
$safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $targetInspectorName);
$fileName = "Laporan_Inspeksi_Saya_{$safeName}_{$dateTitleLabel}.xlsx";

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
