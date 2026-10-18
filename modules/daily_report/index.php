<?php
/**
 * Laporan Inspeksi Harian - Index
 * Dual-View:
 * 1. Tab Sesi Inspeksi (Timeline Kronologis Sesi - DEFAULT VIEW)
 * 2. Tab Ringkasan Per Hari (Rekapitulasi Harian)
 *
 * PT. Surya Technology Industri — Outgoing Quality Control Division
 */
$breadcrumbCategory = "OPERASIONAL";
$pageTitle = "Laporan Inspeksi Harian";
$pageSubtitle = "Ringkasan aktivitas inspeksi per sesi dan per hari: label yang dipakai, total qty, sampling & temuan NG";

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

require_menu_access('daily_report');

$pdo = getDB();

$presetFilter  = sanitize($_GET['preset']     ?? 'mingguan');
$search        = sanitize($_GET['search']     ?? '');
$startDate     = sanitize($_GET['start_date'] ?? '');
$endDate       = sanitize($_GET['end_date']   ?? '');
$activeTab     = sanitize($_GET['tab']        ?? 'session'); // default: session

$selectedMonth = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT) ?: (int)date('m');
$selectedYear  = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT)  ?: (int)date('Y');
$inspectorId   = filter_input(INPUT_GET, 'inspector_id', FILTER_VALIDATE_INT) ?: 0;

$inspectorsList = [];
if ($pdo) {
    try {
        $stmtInsp = $pdo->query("
            SELECT DISTINCT u.id, u.name 
            FROM inspection_sessions ss 
            JOIN users u ON u.id = ss.inspector_id 
            ORDER BY u.name ASC
        ");
        $inspectorsList = $stmtInsp->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $inspectorsList = [];
    }
}

// Standard preset dates calculation if start_date/end_date empty or driving preset
if (empty($startDate) || empty($endDate)) {
    if ($presetFilter === 'hari_ini') {
        $startDate = date('Y-m-d');
        $endDate   = date('Y-m-d');
    } elseif ($presetFilter === 'mingguan') {
        $startDate = date('Y-m-d', strtotime('monday this week'));
        $endDate   = date('Y-m-d', strtotime('sunday this week'));
    } elseif ($presetFilter === 'bulanan') {
        $startDate = date('Y-m-01');
        $endDate   = date('Y-m-d');
    } elseif ($presetFilter === 'tahunan') {
        $startDate = date('Y-01-01');
        $endDate   = date('Y-12-31');
    } elseif ($presetFilter === 'month_year') {
        $mStr = sprintf('%02d', $selectedMonth);
        $startDate = "{$selectedYear}-{$mStr}-01";
        $endDate   = date('Y-m-t', strtotime($startDate));
    } elseif ($presetFilter === 'specific_year') {
        $startDate = "{$selectedYear}-01-01";
        $endDate   = "{$selectedYear}-12-31";
    } elseif ($presetFilter === 'all_years') {
        $startDate = '2000-01-01';
        $endDate   = '2099-12-31';
    } else {
        $presetFilter = 'mingguan';
        $startDate    = date('Y-m-d', strtotime('monday this week'));
        $endDate      = date('Y-m-d', strtotime('sunday this week'));
    }
}

$monthNames = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];
$availableYears = range((int)date('Y'), 2024);

$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT) ?: 15;
if (!in_array($limit, [10, 15, 25, 50, 100])) $limit = 15;
$page = max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1);

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

// ── Helper: Render Non-Transparent Solid Status Badge for Lot/Label ──────────
if (!function_exists('renderLotStatusBadge')) {
    function renderLotStatusBadge($lot) {
        $res = strtolower($lot['lot_result'] ?? '');
        $st = strtolower($lot['lot_status'] ?? '');
        $remUpper = strtoupper(($lot['remarks'] ?? '') . ' ' . ($lot['action_notes'] ?? '') . ' ' . ($lot['reinspection_notes'] ?? '') . ' ' . ($lot['status_label'] ?? '') . ' ' . ($lot['status_badge'] ?? ''));

        $isInitial = !empty($lot['is_initial_attempt']) 
            || (($lot['attempt_type'] ?? '') === 'initial')
            || (strpos($remUpper, 'AWAL') !== false && strpos($remUpper, 'RE-INSPEKSI') === false);

        $isReinspect = !empty($lot['is_reinspected_attempt']) 
            || !empty($lot['is_reinspected_lot']) 
            || !empty($lot['is_reinspection']) 
            || !empty($lot['has_reinspection_log'])
            || !empty($lot['has_subst_log'])
            || !empty($lot['has_sorted_ng'])
            || !empty($lot['has_prev_rejected'])
            || (($lot['attempt_type'] ?? '') === 'reinspection') 
            || (!empty($lot['reinspection_type']) && $lot['reinspection_type'] !== '')
            || (!empty($lot['parent_session_id']) && (int)$lot['parent_session_id'] > 0)
            || (!empty($lot['status_label']) && strpos($lot['status_label'], 'RE-INSPEKSI') !== false)
            || (!empty($lot['status_badge']) && strpos($lot['status_badge'], 'RE-INSPEKSI') !== false)
            || strpos($remUpper, 'RE-INSPEKSI') !== false
            || strpos($remUpper, 'REINSPEKSI') !== false
            || strpos($remUpper, 'RE-INSPECT') !== false
            || strpos($remUpper, 'REINSPECT') !== false
            || strpos($remUpper, 'PENGGANTI') !== false
            || strpos($remUpper, 'SORTIR') !== false
            || $st === 'reinspected';

        $isKanban = (($lot['inspection_type'] ?? '') === 'kanban');
        $isSs = !$isKanban && (!empty($lot['is_safety_stock']) || (($lot['inspection_type'] ?? '') === 'safety_stock') || $res === 'skipped');

        // 1. Initial Attempt check (First NG before sort/reinspect)
        if ($isInitial) {
            return '<span style="background-color: #dc2626; color: #ffffff; font-weight: 800; font-size: 10px; padding: 2.5px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px; box-shadow: 0 1px 2px rgba(220, 38, 38, 0.3); text-transform: uppercase; white-space: nowrap;"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="stroke: #ffffff; flex-shrink: 0;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> REJECTED (AWAL)</span>';
        }

        // 2. Rejected check (QUALITY RESULT IS REJECTED)
        $isRejected = ($res === 'rejected') 
            || ($st === 'rejected') 
            || ($st === 'replaced') 
            || (($lot['status_code'] ?? '') === 'rejected')
            || ((int)($lot['total_ng'] ?? 0) > 0 && $res !== 'passed' && $st !== 'reinspected')
            || (!empty($lot['defects']) && $res !== 'passed' && $st !== 'reinspected');

        if ($isRejected) {
            if ($isReinspect) {
                return '<span style="background-color: #dc2626; color: #ffffff; font-weight: 800; font-size: 10px; padding: 2.5px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px; box-shadow: 0 1px 2px rgba(220, 38, 38, 0.3); text-transform: uppercase; white-space: nowrap;"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="stroke: #ffffff; flex-shrink: 0;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> REJECTED (RE-INSPEKSI)</span>';
            }
            return '<span style="background-color: #dc2626; color: #ffffff; font-weight: 800; font-size: 10px; padding: 2.5px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px; box-shadow: 0 1px 2px rgba(220, 38, 38, 0.3); text-transform: uppercase; white-space: nowrap;"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="stroke: #ffffff; flex-shrink: 0;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> REJECTED</span>';
        }

        // 3. Reinspection Passed check
        if ($isReinspect) {
            if ($res === 'passed' || ($lot['status_code'] ?? '') === 'passed' || $st === 'passed' || $st === 'reinspected') {
                return '<span style="background-color: #16a34a; color: #ffffff; font-weight: 800; font-size: 10px; padding: 2.5px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px; box-shadow: 0 1px 2px rgba(22, 163, 74, 0.3); text-transform: uppercase; white-space: nowrap;"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="stroke: #ffffff; flex-shrink: 0;"><polyline points="20 6 9 17 4 12"/></svg> PASSED (RE-INSPEKSI)</span>';
            }
            return '<span style="background-color: #d97706; color: #ffffff; font-weight: 800; font-size: 10px; padding: 2.5px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px; box-shadow: 0 1px 2px rgba(217, 119, 6, 0.3); text-transform: uppercase; white-space: nowrap;">BERJALAN</span>';
        }

        // 4. Safety Stock Passed check
        if ($isSs && ($res === 'passed' || $res === 'skipped' || empty($res))) {
            return '<span style="background-color: #7c3aed; color: #ffffff; font-weight: 800; font-size: 9.5px; padding: 2.5px 8.5px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 2px rgba(124, 58, 237, 0.3); text-transform: uppercase; white-space: nowrap;"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="stroke: #ffffff; flex-shrink: 0;"><polyline points="20 6 9 17 4 12"/></svg> PASSED (SS)</span>';
        }

        // 5. Standard Passed check
        if ($res === 'passed' || ($lot['status_code'] ?? '') === 'passed' || $st === 'passed') {
            return '<span style="background-color: #16a34a; color: #ffffff; font-weight: 800; font-size: 10px; padding: 2.5px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px; box-shadow: 0 1px 2px rgba(22, 163, 74, 0.3); text-transform: uppercase; white-space: nowrap;"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="stroke: #ffffff; flex-shrink: 0;"><polyline points="20 6 9 17 4 12"/></svg> PASSED</span>';
        }

        // 6. In Progress
        return '<span style="background-color: #d97706; color: #ffffff; font-weight: 800; font-size: 10px; padding: 2.5px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px; box-shadow: 0 1px 2px rgba(217, 119, 6, 0.3); text-transform: uppercase; white-space: nowrap;">BERJALAN</span>';
    }
}

// Data structures
$dailySummary = [
    'total_sesi'              => 0,
    'passed_sesi'             => 0,
    'rejected_sesi'           => 0,
    'total_kanban_sesi'       => 0,
    'total_ss_sesi'           => 0,
    'total_kanban_items'      => 0,
    'total_ss_items'          => 0,
    'total_items'             => 0,
    'total_sampling'          => 0,
    'total_label'             => 0,
    'total_qty'               => 0,
    'total_ng'                => 0,
    'total_approved_sessions' => 0,
    'total_pending_sessions'  => 0,
    'is_all_approved'         => false
];

$sessionTimeline = [];
$allLotsMap = [];
$allNgMap = [];
$allUnmatchedNgMap = [];

$rows       = [];
$totalItems = 0;
$totalPages = 1;

if ($pdo) {
    try {
        // Base WHERE for filtering inspection sessions
        $where  = [
            "(ss.original_ss_session_id IS NULL OR ss.original_ss_session_id = 0)",
            "(ki.remark IS NULL OR (ki.remark NOT LIKE '%AUTO-SS-SESS-%' AND ki.remark NOT LIKE '%Split%'))"
        ];
        $params = [];

        if ($presetFilter !== 'all_years' && !empty($startDate) && !empty($endDate)) {
            $where[] = "DATE(ss.started_at) BETWEEN :sd AND :ed";
            $params[':sd'] = $startDate;
            $params[':ed'] = $endDate;
        }

        if ($inspectorId > 0) {
            $where[] = "ss.inspector_id = :insp_id";
            $params[':insp_id'] = $inspectorId;
        }

        if (!empty($search)) {
            $where[] = "(
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

        $whereStr = implode(' AND ', $where);

        // ═══════════════════════════════════════════════════════════════════
        // 1. QUERY TAB SESI INSPEKSI & 5 KPI CARDS
        // ═══════════════════════════════════════════════════════════════════
        $sqlSessions = "
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
                COALESCE(did.part_name, ki.item_description, mp.part_name, '-') AS part_name,
                COALESCE(mm.name, mp.model, '-') AS model_name
            FROM inspection_sessions ss
            LEFT JOIN `lines` l ON l.id = ss.line_id
            LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
            LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
            LEFT JOIN master_parts mp ON (mp.id = ss.part_id OR UPPER(mp.part_code) = UPPER(COALESCE(did.part_code, ki.item_code)))
            LEFT JOIN master_models mm ON mm.id = mp.model_id
            LEFT JOIN users u ON u.id = ss.inspector_id
            LEFT JOIN users u_app ON u_app.id = ss.approved_by
            WHERE {$whereStr}
            ORDER BY ss.started_at DESC, ss.id DESC
        ";

        $stSess = $pdo->prepare($sqlSessions);
        $stSess->execute($params);
        $rawSessions = $stSess->fetchAll(PDO::FETCH_ASSOC);

        $kanbanItemKeys = [];
        $ssItemKeys     = [];

        if (!empty($rawSessions)) {
            $sessionIds = array_column($rawSessions, 'session_id');
            $inSessIds  = implode(',', array_map('intval', $sessionIds));

            // Scanned lots query
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
                    isl.closed_at AS closed_at,
                    isl.action_noted_at,
                    isl.replaced_by_lot_id,
                    (SELECT COUNT(*) FROM lot_substitution_log sub 
                     WHERE sub.ng_session_lot_id = isl.id 
                        OR sub.replacement_session_lot_id = isl.id) AS has_subst_log,
                    (SELECT COUNT(*) FROM inspection_ng_records ngr 
                     WHERE ngr.session_lot_id = isl.id 
                       AND ngr.is_sorted = 1) AS has_sorted_ng
                FROM inspection_session_lots isl
                WHERE isl.inspection_session_id IN ({$inSessIds})
                  AND (isl.remarks IS NULL OR (isl.remarks NOT LIKE '%Sisa Split Safety Stock%' AND isl.remarks NOT LIKE 'Sisa Kelebihan Kanban%'))
                ORDER BY isl.id ASC
            ");
            $rawLotsList = [];
            foreach ($stmtLots->fetchAll(PDO::FETCH_ASSOC) as $lRow) {
                $sId = (int)$lRow['inspection_session_id'];
                $lRow['defects'] = [];

                $isSafetyStock = ($lRow['lot_result'] === 'skipped') || (empty($lRow['sample_size']) && isset($lRow['remarks']) && strpos($lRow['remarks'], 'Alokasi Safety Stock') !== false);
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

            // Defect NG query
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

            // Distribute defects to lots
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

            // Populate lots into map
            foreach ($rawLotsList as $sId => $lotItems) {
                foreach ($lotItems as $lRow) {
                    $allLotsMap[$sId][] = $lRow;
                }
            }

            // Reinspection Stats (Workload)
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

            // Build Timeline list and calculate 5 KPI totals
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

                // Unique Items tally & Session Type tally
                if ($sess['inspection_type'] === 'kanban') {
                    $dailySummary['total_kanban_sesi']++;
                    $kKey = $sess['kanban_item_id'] ?: ($sess['part_code'] . '_' . $sess['kanban_no']);
                    $kanbanItemKeys[$kKey] = true;
                } else {
                    $dailySummary['total_ss_sesi']++;
                    $sKey = strtoupper($sess['part_code']) . '_' . strtoupper($sess['did_lot_number'] ?: 'NOLOT');
                    $ssItemKeys[$sKey] = true;
                }

                // 5 KPI Accumulators
                $dailySummary['total_sesi']++;
                if ($sess['session_status'] === 'passed') {
                    $dailySummary['passed_sesi']++;
                } elseif ($sess['session_status'] === 'rejected') {
                    $dailySummary['rejected_sesi']++;
                }
                $dailySummary['total_label']    += $boxCount;
                $dailySummary['total_sampling'] += $sessEffectiveSample;
                $dailySummary['total_qty']      += $sessTotalQty;
                $dailySummary['total_ng']       += $sessNgCount;

                if (!empty($sess['is_approved']) && (int)$sess['is_approved'] === 1) {
                    $dailySummary['total_approved_sessions']++;
                } else {
                    $dailySummary['total_pending_sessions']++;
                }
            }

            $dailySummary['total_kanban_items'] = count($kanbanItemKeys);
            $dailySummary['total_ss_items']     = count($ssItemKeys);
            $dailySummary['total_items']        = $dailySummary['total_kanban_items'] + $dailySummary['total_ss_items'];
            $dailySummary['is_all_approved']    = ($dailySummary['total_sesi'] > 0 && $dailySummary['total_pending_sessions'] === 0);
        }

        // ═══════════════════════════════════════════════════════════════════
        // 2. QUERY TAB RINGKASAN PER HARI (REKAP HARIAN)
        // ═══════════════════════════════════════════════════════════════════
        $sqlDailyGroup = "SELECT
                    DATE(ss.started_at) AS tanggal,
                    COUNT(ss.id) AS total_sesi,
                    COALESCE(SUM(ss.sample_size + COALESCE(reinst_stats.reinspect_sample_sum, 0)), 0) AS total_sampling,
                    COALESCE(SUM(CASE WHEN ss.is_approved = 1 THEN 1 ELSE 0 END), 0) AS total_approved,
                    COALESCE(SUM(GREATEST(COALESCE(ss.ng_count, 0), COALESCE(ng_stats.ng_qty_sum, 0))), 0) AS total_ng,
                    COALESCE(SUM(COALESCE(lot_stats.lot_count, 0) + COALESCE(reinst_stats.reinspect_count, 0)), 0) AS total_label,
                    COALESCE(SUM(lot_stats.lot_qty), 0) AS total_qty,
                    GROUP_CONCAT(DISTINCT ss.inspection_type ORDER BY ss.inspection_type SEPARATOR ',') AS jenis_inspeksi
                FROM inspection_sessions ss
                LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
                LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
                LEFT JOIN master_parts mp ON (mp.id = ss.part_id OR UPPER(mp.part_code) = UPPER(COALESCE(did.part_code, ki.item_code)))
                LEFT JOIN (
                    SELECT 
                        inspection_session_id, 
                        COUNT(id) AS lot_count, 
                        SUM(qty) AS lot_qty 
                    FROM inspection_session_lots 
                    WHERE (remarks IS NULL OR (remarks NOT LIKE '%Sisa Split Safety Stock%' AND remarks NOT LIKE 'Sisa Kelebihan Kanban%'))
                    GROUP BY inspection_session_id
                ) lot_stats ON lot_stats.inspection_session_id = ss.id
                LEFT JOIN (
                    SELECT 
                        sub.original_session_id,
                        COUNT(*) AS reinspect_count,
                        SUM(COALESCE(isl.sample_size, 0)) AS reinspect_sample_sum
                    FROM lot_substitution_log sub
                    LEFT JOIN inspection_session_lots isl ON isl.id = sub.ng_session_lot_id
                    WHERE sub.action_type = 'sort_reinspect'
                    GROUP BY sub.original_session_id
                ) reinst_stats ON reinst_stats.original_session_id = ss.id
                LEFT JOIN (
                    SELECT 
                        inspection_session_id,
                        COALESCE(SUM(qty_ng), 0) AS ng_qty_sum
                    FROM inspection_ng_records
                    WHERE (is_cancelled IS NULL OR is_cancelled = 0)
                    GROUP BY inspection_session_id
                ) ng_stats ON ng_stats.inspection_session_id = ss.id
                WHERE {$whereStr}
                GROUP BY DATE(ss.started_at)
                ORDER BY DATE(ss.started_at) DESC";

        $stAll = $pdo->prepare($sqlDailyGroup);
        $stAll->execute($params);
        $allRows = $stAll->fetchAll(PDO::FETCH_ASSOC);

        $totalItems = count($allRows);
        $totalPages = max(1, ceil($totalItems / $limit));
        if ($page > $totalPages) $page = $totalPages;
        $rows = array_slice($allRows, ($page - 1) * $limit, $limit);

    } catch (PDOException $e) {
        set_flash('error', 'Gagal memuat laporan: ' . $e->getMessage());
    }
}

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen min-w-0 w-full overflow-x-hidden transition-all duration-300">

    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-4 space-y-3 min-w-0 w-full overflow-x-hidden">

        <?= render_flash() ?>

        <style>
        /* ── Excel-Style Column Header Filters (100% Solid & High-Contrast) ── */
        .th-filter-wrapper {
            display: inline-flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            position: relative;
            gap: 4px;
        }
        .th-filter-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 20px;
            height: 20px;
            border-radius: 4px;
            color: #334155 !important;
            cursor: pointer;
            transition: all 0.15s ease;
            background-color: #ffffff !important;
            border: 1.5px solid #cbd5e1 !important;
            flex-shrink: 0;
            padding: 0;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
        }
        .th-filter-btn:hover {
            color: #1d4ed8 !important;
            background-color: #dbeafe !important;
            border-color: #3b82f6 !important;
        }
        .th-filter-btn.active {
            color: #ffffff !important;
            background-color: #2563eb !important;
            border-color: #1d4ed8 !important;
            box-shadow: 0 1px 3px rgba(37, 99, 235, 0.4);
        }
        .excel-filter-dropdown {
            position: absolute;
            top: calc(100% + 5px);
            z-index: 60;
            background-color: #ffffff !important;
            border: 1.5px solid #94a3b8 !important;
            border-radius: 10px;
            box-shadow: 0 10px 25px -3px rgba(0, 0, 0, 0.2), 0 4px 6px -2px rgba(0, 0, 0, 0.1);
            width: 245px;
            padding: 10px;
            text-transform: none;
            letter-spacing: normal;
            font-weight: normal;
            color: #0f172a;
            display: none;
            text-align: left;
        }
        .excel-filter-dropdown.align-right {
            right: 0;
            left: auto;
        }
        .excel-filter-dropdown.align-left {
            left: 0;
            right: auto;
        }
        .excel-filter-search {
            width: 100%;
            padding: 6px 10px;
            border: 1.5px solid #cbd5e1;
            border-radius: 6px;
            font-size: 11px;
            outline: none;
            margin-bottom: 8px;
            background-color: #ffffff;
            color: #0f172a;
        }
        .excel-filter-search:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 2px #bfdbfe;
        }
        /* Tombol Cepat: Pilih Semua & Hapus Pilihan (Solid Pill Buttons) */
        .excel-quick-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3.5px 8px;
            border-radius: 5px;
            font-size: 10.5px;
            font-weight: 800;
            cursor: pointer;
            border: 1px solid #cbd5e1;
            background-color: #f1f5f9;
            color: #1e293b;
            line-height: 1.2;
            transition: all 0.15s ease;
        }
        .excel-quick-btn:hover {
            background-color: #e2e8f0;
            border-color: #94a3b8;
            color: #0f172a;
        }
        /* Tombol Reset Header Mini (Solid Red Pill) */
        .excel-reset-btn {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 2.5px 7px;
            border-radius: 5px;
            font-size: 10px;
            font-weight: 800;
            cursor: pointer;
            border: 1px solid #fecdd3;
            background-color: #ffe4e6;
            color: #9f1239;
            line-height: 1.2;
            transition: all 0.15s ease;
        }
        .excel-reset-btn:hover {
            background-color: #fecdd3;
            border-color: #fda4af;
            color: #881337;
        }
        .excel-filter-list {
            max-height: 170px;
            overflow-y: auto;
            border: 1.5px solid #e2e8f0;
            background-color: #f8fafc;
            border-radius: 6px;
            padding: 4px;
            font-size: 11px;
        }
        .excel-filter-item {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 4px 6px;
            border-radius: 4px;
            cursor: pointer;
            user-select: none;
            font-weight: 600;
            color: #1e293b;
        }
        .excel-filter-item:hover {
            background-color: #e2e8f0;
        }
        .excel-filter-item input[type="checkbox"] {
            cursor: pointer;
            width: 14px;
            height: 14px;
            border-radius: 3px;
            accent-color: #2563eb;
        }
        .excel-filter-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 10px;
            padding-top: 8px;
            border-top: 1.5px solid #f1f5f9;
        }
        /* Tombol Footer: Tutup & Terapkan */
        .excel-btn-close {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 5px 12px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 800;
            cursor: pointer;
            border: 1.5px solid #cbd5e1;
            background-color: #f1f5f9;
            color: #334155;
            transition: all 0.15s ease;
        }
        .excel-btn-close:hover {
            background-color: #e2e8f0;
            border-color: #94a3b8;
            color: #0f172a;
        }
        .excel-btn-apply {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            justify-content: center;
            padding: 5px 14px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 800;
            cursor: pointer;
            border: 1.5px solid #1d4ed8;
            background-color: #2563eb;
            color: #ffffff;
            box-shadow: 0 1px 2px rgba(37, 99, 235, 0.4);
            transition: all 0.15s ease;
        }
        .excel-btn-apply:hover {
            background-color: #1d4ed8;
            border-color: #1e40af;
        }
        .excel-btn-apply:active {
            transform: scale(0.97);
        }
        </style>

        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <!-- 1. HEADER BANNER WITH EXECUTIVE 5 KPI CARDS RIBBON -->
        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <div style="background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%); border-radius: 14px; padding: 16px 20px; color: #fff; box-shadow: 0 4px 16px rgba(15,23,42,0.18);">
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="background: rgba(59,130,246,0.22); border: 1px solid rgba(59,130,246,0.35); padding: 10px; border-radius: 12px; flex-shrink: 0;">
                        <svg style="width: 22px; height: 22px; color: #93c5fd;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                        </svg>
                    </div>
                    <div>
                        <div style="font-size: 10px; font-weight: 800; color: #93c5fd; text-transform: uppercase; letter-spacing: 0.6px;">Outgoing Quality Control &bull; Executive Monitoring</div>
                        <h1 style="font-size: 16px; font-weight: 900; margin: 2px 0 0 0; line-height: 1.2; letter-spacing: -0.2px;">Laporan Inspeksi Harian</h1>
                        <p style="font-size: 11px; color: #cbd5e1; font-weight: 500; margin: 2px 0 0 0;">
                            Periode: <b><?= date('d M Y', strtotime($startDate)) ?></b> s/d <b><?= date('d M Y', strtotime($endDate)) ?></b>
                            <?php if (!empty($search)): ?> &bull; Filter: "<?= htmlspecialchars($search) ?>"<?php endif; ?>
                        </p>
                    </div>
                </div>
                
                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                    <a href="<?= base_url('modules/daily_report/export_excel.php?' . http_build_query([
                        'start_date' => $startDate,
                        'end_date'   => $endDate,
                        'search'     => $search
                    ])) ?>" target="_blank" style="background: #16a34a; border: 1px solid #15803d; color: #ffffff; border-radius: 9999px; padding: 7px 16px; font-size: 11px; font-weight: 800; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; box-shadow: 0 2px 8px rgba(22,163,74,0.35); transition: all 0.2s;" onmouseover="this.style.background='#15803d'" onmouseout="this.style.background='#16a34a'">
                        <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        Export Excel
                    </a>
                    <a href="<?= base_url('modules/daily_report/index.php') ?>" style="background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.18); color: #e2e8f0; border-radius: 9999px; padding: 7px 14px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; text-decoration: none;">
                        <svg style="width: 13px; height: 13px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                        Refresh
                    </a>
                </div>
            </div>

            <!-- 5 KPI CARDS RIBBON GRID -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px; margin-top: 14px;">
                <!-- KPI 1: Total Sesi -->
                <div style="background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.12); border-radius: 12px; padding: 10px 14px;">
                    <div style="font-size: 10px; color: #94a3b8; font-weight: 800; text-transform: uppercase; letter-spacing: 0.3px;">TOTAL SESI INSPEKSI</div>
                    <div style="font-size: 20px; font-weight: 900; color: #fff; margin-top: 2px; font-family: monospace; line-height: 1.1;">
                        <?= number_format($dailySummary['total_sesi']) ?> <span style="font-size: 11px; font-weight: 600; color: #94a3b8;">sesi</span>
                    </div>
                    <div style="font-size: 10px; color: #38bdf8; font-weight: 700; margin-top: 2px;">
                        <?= $dailySummary['passed_sesi'] ?> Pass &bull; <?= $dailySummary['rejected_sesi'] ?> Reject
                    </div>
                </div>

                <!-- KPI 2: Kanban / SS Sesi -->
                <div style="background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.12); border-radius: 12px; padding: 10px 14px;">
                    <div style="font-size: 10px; color: #94a3b8; font-weight: 800; text-transform: uppercase; letter-spacing: 0.3px;">KANBAN / SS SESI</div>
                    <div style="font-size: 20px; font-weight: 900; color: #fff; margin-top: 2px; font-family: monospace; line-height: 1.1;">
                        <?= number_format($dailySummary['total_sesi']) ?> <span style="font-size: 11px; font-weight: 600; color: #94a3b8;">sesi</span>
                    </div>
                    <div style="font-size: 10px; color: #c4b5fd; font-weight: 700; margin-top: 2px;">
                        <?= $dailySummary['total_kanban_sesi'] ?> Kanban &bull; <?= $dailySummary['total_ss_sesi'] ?> Safety Stock
                    </div>
                </div>

                <!-- KPI 3: Total Sampling -->
                <div style="background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.12); border-radius: 12px; padding: 10px 14px;">
                    <div style="font-size: 10px; color: #94a3b8; font-weight: 800; text-transform: uppercase; letter-spacing: 0.3px;">TOTAL SAMPLING</div>
                    <div style="font-size: 20px; font-weight: 900; color: #fff; margin-top: 2px; font-family: monospace; line-height: 1.1;">
                        <?= number_format($dailySummary['total_sampling']) ?> <span style="font-size: 11px; font-weight: 600; color: #94a3b8;">pcs</span>
                    </div>
                    <div style="font-size: 10px; color: #94a3b8; font-weight: 600; margin-top: 2px;">
                        Dari <?= number_format($dailySummary['total_label']) ?> box label
                    </div>
                </div>

                <!-- KPI 4: Temuan Cacat (NG) -->
                <div style="background: <?= $dailySummary['total_ng'] > 0 ? 'rgba(239,68,68,0.22)' : 'rgba(255,255,255,0.08)' ?>; border: 1px solid <?= $dailySummary['total_ng'] > 0 ? 'rgba(239,68,68,0.4)' : 'rgba(255,255,255,0.12)' ?>; border-radius: 12px; padding: 10px 14px;">
                    <div style="font-size: 10px; color: <?= $dailySummary['total_ng'] > 0 ? '#fca5a5' : '#94a3b8' ?>; font-weight: 800; text-transform: uppercase; letter-spacing: 0.3px;">TEMUAN CACAT (NG)</div>
                    <div style="font-size: 20px; font-weight: 900; color: <?= $dailySummary['total_ng'] > 0 ? '#fca5a5' : '#fff' ?>; margin-top: 2px; font-family: monospace; line-height: 1.1;">
                        <?= number_format($dailySummary['total_ng']) ?> <span style="font-size: 11px; font-weight: 600;"><?= $dailySummary['total_ng'] === 0 ? 'All OK' : 'pcs NG' ?></span>
                    </div>
                    <div style="font-size: 10px; color: <?= $dailySummary['total_ng'] > 0 ? '#fecdd3' : '#4ade80' ?>; font-weight: 700; margin-top: 2px;">
                        <?= $dailySummary['total_ng'] > 0 ? 'Cacat terdeteksi' : 'Kualitas produksi prima' ?>
                    </div>
                </div>

                <!-- KPI 5: Persetujuan Supervisor -->
                <div style="background: <?= ($dailySummary['total_sesi'] > 0 && $dailySummary['is_all_approved']) ? 'rgba(16,185,129,0.22)' : 'rgba(245,158,11,0.22)' ?>; border: 1px solid <?= ($dailySummary['total_sesi'] > 0 && $dailySummary['is_all_approved']) ? 'rgba(16,185,129,0.4)' : 'rgba(245,158,11,0.4)' ?>; border-radius: 12px; padding: 10px 14px;">
                    <div style="font-size: 10px; color: <?= ($dailySummary['total_sesi'] > 0 && $dailySummary['is_all_approved']) ? '#a7f3d0' : '#fde68a' ?>; font-weight: 800; text-transform: uppercase; letter-spacing: 0.3px;">PERSETUJUAN SUPERVISOR</div>
                    <div style="font-size: 20px; font-weight: 900; color: #fff; margin-top: 2px; font-family: monospace; line-height: 1.1;">
                        <?= $dailySummary['total_approved_sessions'] ?> / <?= $dailySummary['total_sesi'] ?> <span style="font-size: 11px; font-weight: 600;">Sesi</span>
                    </div>
                    <div style="font-size: 10px; color: <?= ($dailySummary['total_sesi'] > 0 && $dailySummary['is_all_approved']) ? '#6ee7b7' : '#fcd34d' ?>; font-weight: 700; margin-top: 2px;">
                        <?php if ($dailySummary['total_sesi'] === 0): ?>
                            Tidak ada aktivitas
                        <?php elseif ($dailySummary['is_all_approved']): ?>
                            Lengkap Disetujui (100%)
                        <?php else: ?>
                            <?= $dailySummary['total_pending_sessions'] ?> Sesi Menunggu ACC
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <!-- 2. FILTER BAR WITH QUICK PRESET DATE PILLS -->
        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 14px; box-shadow: 0 1px 4px rgba(0,0,0,0.03);" class="space-y-3">
            
            <!-- Quick Date Preset Pills Bar -->
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;">
                <div id="groupPresetPills" style="display: flex; align-items: center; gap: 3px; background: #f1f5f9; padding: 3px; border-radius: 9px; border: 1px solid #e2e8f0; flex-wrap: wrap;">
                    <?php
                    $pills = [
                        'hari_ini'      => 'Hari Ini',
                        'mingguan'      => 'Minggu Ini',
                        'bulanan'       => 'Bulan Ini',
                        'tahunan'       => 'Tahun Ini',
                        'month_year'    => 'Pilih Bulan',
                        'specific_year' => 'Pilih Tahun',
                        'all_years'     => 'Semua Tahun',
                        'custom'        => 'Rentang Custom'
                    ];
                    foreach ($pills as $pKey => $pLabel):
                        $isActive = ($presetFilter === $pKey);
                        $bgStyle = $isActive ? 'background: #2563eb; color: #ffffff; box-shadow: 0 1px 4px rgba(37,99,235,0.3);' : 'background: transparent; color: #64748b;';
                    ?>
                        <button type="button" class="preset-pill-btn" data-preset="<?= $pKey ?>" onclick="handlePresetClick('<?= $pKey ?>')"
                                style="padding: 5px 12px; border-radius: 6px; font-size: 11px; font-weight: 700; border: none; cursor: pointer; transition: all 0.2s; <?= $bgStyle ?>">
                            <?= $pLabel ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <!-- Dynamic Sub-Filter Dropdowns (Pilih Bulan / Pilih Tahun) -->
                <div id="containerSubFilter" style="display: <?= in_array($presetFilter, ['month_year', 'specific_year']) ? 'flex' : 'none' ?>; align-items: center; gap: 6px; background: #f8fafc; padding: 3px 8px; border-radius: 8px; border: 1px solid #cbd5e1;">
                    
                    <!-- Sub-Box 1: Month & Year Picker -->
                    <div id="boxFilterMonthYear" style="display: <?= $presetFilter === 'month_year' ? 'flex' : 'none' ?>; align-items: center; gap: 5px;">
                        <select id="subSelectMonth" class="form-input text-xs" style="padding: 4px 8px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 11px; font-weight: 600;">
                            <?php foreach ($monthNames as $mNum => $mName): ?>
                                <option value="<?= $mNum ?>" <?= $selectedMonth === $mNum ? 'selected' : '' ?>><?= $mName ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="subSelectMonthYear" class="form-input text-xs" style="padding: 4px 8px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 11px; font-weight: 600;">
                            <?php foreach ($availableYears as $y): ?>
                                <option value="<?= $y ?>" <?= $selectedYear === (int)$y ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" onclick="applyMonthYearFilter()" style="padding: 4px 10px; background: #2563eb; color: #ffffff; font-size: 11px; font-weight: 700; border-radius: 6px; border: none; cursor: pointer;">
                            Terapkan
                        </button>
                    </div>

                    <!-- Sub-Box 2: Specific Year Picker -->
                    <div id="boxFilterSpecificYear" style="display: <?= $presetFilter === 'specific_year' ? 'flex' : 'none' ?>; align-items: center; gap: 5px;">
                        <select id="subSelectYear" class="form-input text-xs" style="padding: 4px 8px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 11px; font-weight: 600;">
                            <?php foreach ($availableYears as $y): ?>
                                <option value="<?= $y ?>" <?= $selectedYear === (int)$y ? 'selected' : '' ?>>Tahun <?= $y ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" onclick="applySpecificYearFilter()" style="padding: 4px 10px; background: #2563eb; color: #ffffff; font-size: 11px; font-weight: 700; border-radius: 6px; border: none; cursor: pointer;">
                            Terapkan
                        </button>
                    </div>

                </div>
            </div>

            <!-- Form Filter Inputs -->
            <form id="formDailyFilter" action="<?= base_url('modules/daily_report/index.php') ?>" method="GET">
                <input type="hidden" name="preset" id="inputPreset" value="<?= htmlspecialchars($presetFilter) ?>">
                <input type="hidden" name="month" id="inputMonth" value="<?= $selectedMonth ?>">
                <input type="hidden" name="year" id="inputYear" value="<?= $selectedYear ?>">
                <input type="hidden" name="tab" id="inputTab" value="<?= htmlspecialchars($activeTab) ?>">

                <div style="display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end;">
                    <div style="flex: 1 1 240px; min-width: 180px;">
                        <label style="font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px; display: block; margin-bottom: 4px;">Cari</label>
                        <div style="position: relative;">
                            <svg style="position: absolute; left: 9px; top: 50%; transform: translateY(-50%); width: 13px; height: 13px; color: #94a3b8;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0"/></svg>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Part Code, Lot No, Ref No, Kanban..."
                                   style="width: 100%; padding: 7px 10px 7px 30px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 12px; color: #1e293b; font-weight: 500; outline: none; box-sizing: border-box;">
                        </div>
                    </div>

                    <div style="flex: 0 1 140px; min-width: 120px;">
                        <label style="font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px; display: block; margin-bottom: 4px;">Dari</label>
                        <input type="date" id="inputStartDate" name="start_date" value="<?= htmlspecialchars($startDate) ?>" style="width: 100%; padding: 7px 8px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 11px; color: #334155; font-weight: 600; outline: none;">
                    </div>

                    <div style="flex: 0 1 140px; min-width: 120px;">
                        <label style="font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px; display: block; margin-bottom: 4px;">Sampai</label>
                        <input type="date" id="inputEndDate" name="end_date" value="<?= htmlspecialchars($endDate) ?>" style="width: 100%; padding: 7px 8px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 11px; color: #334155; font-weight: 600; outline: none;">
                    </div>

                    <div style="flex: 0 1 180px; min-width: 150px;">
                        <label style="font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px; display: block; margin-bottom: 4px;">Inspector QC</label>
                        <select name="inspector_id" onchange="document.getElementById('formDailyFilter').submit()"
                                style="width: 100%; padding: 7px 10px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 11px; color: #334155; font-weight: 700; outline: none; cursor: pointer;">
                            <option value="0">-- Semua Inspector --</option>
                            <?php foreach ($inspectorsList as $insp): ?>
                                <option value="<?= (int)$insp['id'] ?>" <?= $inspectorId === (int)$insp['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($insp['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="display: flex; gap: 6px; align-items: flex-end; flex-shrink: 0;">
                        <button type="submit" style="padding: 7px 16px; background: #2563eb; color: #fff; font-weight: 700; font-size: 12px; border-radius: 8px; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 5px;">
                            <svg style="width: 13px; height: 13px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0"/></svg>
                            Cari
                        </button>
                        <a href="<?= base_url('modules/daily_report/index.php') ?>" style="padding: 7px 12px; background: #f1f5f9; color: #64748b; font-weight: 700; font-size: 12px; border-radius: 8px; border: 1px solid #cbd5e1; text-decoration: none;">Reset</a>
                    </div>
                </div>
            </form>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <!-- 3. TAB CONTROLLER: BERDASARKAN SESI INSPEKSI VS RINGKASAN PER HARI -->
        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <div class="card p-3 bg-white border border-slate-200/80 rounded-xl shadow-xs">
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;">
                
                <!-- Dual-View Pill Buttons -->
                <div style="display: inline-flex; align-items: center; background: #f1f5f9; padding: 4px; border-radius: 12px; border: 1px solid #e2e8f0; gap: 4px;">
                    <!-- Tab 1: Berdasarkan Sesi Inspeksi (DEFAULT VIEW) -->
                    <button type="button" id="tab-btn-session" onclick="switchReportTab('session')"
                            style="padding: 7px 16px; font-size: 12px; font-weight: 800; border-radius: 9px; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 7px; transition: all 0.2s;"
                            class="<?= $activeTab === 'session' ? 'bg-blue-600 text-white shadow-xs' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-200/70' ?>">
                        <svg style="width: 15px; height: 15px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Berdasarkan Sesi Inspeksi
                        <span style="background: <?= $activeTab === 'session' ? 'rgba(255,255,255,0.25)' : '#e2e8f0' ?>; color: <?= $activeTab === 'session' ? '#fff' : '#475569' ?>; padding: 1px 7px; border-radius: 9999px; font-size: 10px;">
                            <?= count($sessionTimeline) ?> Sesi
                        </span>
                    </button>

                    <!-- Tab 2: Ringkasan Per Hari -->
                    <button type="button" id="tab-btn-daily" onclick="switchReportTab('daily')"
                            style="padding: 7px 16px; font-size: 12px; font-weight: 800; border-radius: 9px; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 7px; transition: all 0.2s;"
                            class="<?= $activeTab === 'daily' ? 'bg-blue-600 text-white shadow-xs' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-200/70' ?>">
                        <svg style="width: 15px; height: 15px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        Ringkasan Per Hari
                        <span style="background: <?= $activeTab === 'daily' ? 'rgba(255,255,255,0.25)' : '#e2e8f0' ?>; color: <?= $activeTab === 'daily' ? '#fff' : '#475569' ?>; padding: 1px 7px; border-radius: 9999px; font-size: 10px;">
                            <?= $totalItems ?> Hari
                        </span>
                    </button>
                </div>

                <div style="font-size: 11px; color: #64748b; font-weight: 600;">
                    <span id="tab-desc-text">
                        <?= $activeTab === 'session' ? 'Menampilkan kronologi seluruh sesi inspeksi secara langsung' : 'Menampilkan rekapitulasi performa inspeksi dikelompokkan per hari' ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <!-- TAB PANE 1: BERDASARKAN SESI INSPEKSI (DEFAULT ACTIVE VIEW) -->
        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <div id="pane-tab-session" style="display: <?= $activeTab === 'session' ? 'block' : 'none' ?>;" class="space-y-4">
            <div style="background: #fff; border: 1px solid #cbd5e1; border-radius: 14px; overflow: hidden; box-shadow: 0 4px 14px rgba(15,23,42,0.05);">
                
                <div style="padding: 12px 18px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span style="background: #0f172a; color: #fff; width: 28px; height: 28px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center;">
                            <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </span>
                        <div>
                            <h2 style="font-size: 13px; font-weight: 900; color: #0f172a; margin: 0;">Kronologi Seluruh Sesi Inspeksi</h2>
                            <p style="font-size: 11px; color: #64748b; margin: 0; font-weight: 600;">Urutan pelaksanaan inspeksi di meja QC (Kanban & Safety Stock)</p>
                        </div>
                    </div>
                    
                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                        <!-- Toggle: Sembunyikan / Tampilkan Sesi yang Sudah di-ACC -->
                        <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; background: #fff; border: 1px solid #cbd5e1; padding: 4px 10px; border-radius: 8px; font-size: 11px; font-weight: 700; color: #334155; user-select: none;">
                            <input type="checkbox" id="toggleShowApproved" onchange="filterApprovedSessions(this.checked)" style="cursor: pointer; width: 14px; height: 14px; accent-color: #2563eb;">
                            <span>Tampilkan Sesi Disetujui</span>
                        </label>

                        <!-- Tombol ACC All (Setujui Semua Sesi Pending) -->
                        <?php if ($dailySummary['total_pending_sessions'] > 0): ?>
                            <button type="button" id="btnApproveAllVisible" onclick="confirmApproveAllVisible()"
                                    style="display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; background: linear-gradient(135deg, #16a34a 0%, #15803d 100%); color: #ffffff; border: 1px solid #166534; border-radius: 8px; font-size: 11px; font-weight: 800; cursor: pointer; box-shadow: 0 1px 3px rgba(22, 163, 74, 0.35); transition: all 0.2s;"
                                    title="Setujui seluruh sesi inspeksi yang belum di-ACC pada filter saat ini">
                                <svg style="width: 13px; height: 13px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                                </svg>
                                <span>Setujui Semua Sesi (ACC All)</span>
                                <span style="background: rgba(255,255,255,0.25); color: #fff; padding: 1px 6px; border-radius: 9999px; font-size: 10px;">
                                    <?= $dailySummary['total_pending_sessions'] ?>
                                </span>
                            </button>
                        <?php else: ?>
                            <span style="display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; border-radius: 8px; font-size: 11px; font-weight: 800;">
                                <svg style="width: 12px; height: 12px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                Semua Sesi Sudah di-ACC
                            </span>
                        <?php endif; ?>

                        <span id="badgeTotalSessionCount" style="font-size: 11px; font-weight: 800; background: #e2e8f0; color: #334155; padding: 4px 12px; border-radius: 9999px;">
                            Total <?= count($sessionTimeline) ?> Sesi
                        </span>
                    </div>
                </div>

                <!-- Indikator Filter Excel Aktif Bar -->
                <div id="excel-filter-active-bar" style="display: none; background: #eff6ff; border-bottom: 1.5px solid #bfdbfe; padding: 7px 16px; align-items: center; justify-content: space-between; font-size: 11px;">
                    <div style="display: flex; align-items: center; gap: 6px; color: #1e40af; font-weight: 700;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                        <span id="excel-filter-summary-text">Menyaring data tabel...</span>
                    </div>
                    <button type="button" onclick="resetAllExcelFilters()" style="background: #ffffff; border: 1px solid #bfdbfe; color: #1d4ed8; font-weight: 800; font-size: 10.5px; padding: 2.5px 9px; border-radius: 5px; cursor: pointer; transition: all 0.15s;" onmouseover="this.style.background='#dbeafe'" onmouseout="this.style.background='#ffffff'">
                        Reset Semua Filter
                    </button>
                </div>

                <div style="overflow-x: auto;">
                    <table class="w-full text-left" style="border-collapse: collapse; font-size: 12px; min-width: 960px;">
                        <thead style="background: #f8fafc; border-bottom: 2px solid #cbd5e1; font-size: 10px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">
                            <tr>
                                <!-- Kolom Ceklis & Sesi # -->
                                <th style="padding: 11px 10px; width: 85px; text-align: center;">
                                    <div style="display: flex; align-items: center; justify-content: center; gap: 6px;">
                                        <input type="checkbox" id="checkAllSessions" onchange="toggleSelectAllSessionRows(this.checked)"
                                               title="Pilih Semua Sesi yang Belum di-ACC"
                                               style="cursor: pointer; width: 15px; height: 15px; accent-color: #2563eb; border-radius: 3px;">
                                        <span>Sesi #</span>
                                    </div>
                                </th>
                                
                                <!-- Kolom WAKTU & TANGGAL -->
                                <th style="padding: 11px 14px; width: 145px; position: relative;">
                                    <div class="th-filter-wrapper">
                                        <span>Waktu & Tanggal</span>
                                        <button type="button" class="th-filter-btn" data-col="date" title="Filter Tanggal" onclick="toggleExcelFilter(event, 'date')">
                                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                        </button>
                                    </div>
                                    <div id="filter-dropdown-date" class="excel-filter-dropdown align-left">
                                        <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                            <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER TANGGAL</span>
                                            <button type="button" class="excel-reset-btn" onclick="resetSingleColFilter('date')" title="Reset filter">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                <span>Reset</span>
                                            </button>
                                        </div>
                                        <input type="text" class="excel-filter-search" placeholder="Cari tanggal..." oninput="onSearchFilterOptions('date', this.value)">
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllFilter('date', true)">
                                                <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Pilih Semua</span>
                                            </button>
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllFilter('date', false)">
                                                <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Hapus Pilihan</span>
                                            </button>
                                        </div>
                                        <div class="excel-filter-list" id="filter-list-date"></div>
                                        <div class="excel-filter-actions">
                                            <span class="text-[10px] text-slate-500 font-bold filter-count-info"></span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="excel-btn-close" onclick="closeAllExcelFilters()">Tutup</button>
                                                <button type="button" class="excel-btn-apply" onclick="applyExcelFilters()">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </th>

                                <!-- Kolom JALUR -->
                                <th style="padding: 11px 14px; width: 105px; text-align: center; position: relative;">
                                    <div class="th-filter-wrapper">
                                        <span>Jalur</span>
                                        <button type="button" class="th-filter-btn" data-col="jalur" title="Filter Jalur" onclick="toggleExcelFilter(event, 'jalur')">
                                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                        </button>
                                    </div>
                                    <div id="filter-dropdown-jalur" class="excel-filter-dropdown align-left">
                                        <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                            <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER JALUR</span>
                                            <button type="button" class="excel-reset-btn" onclick="resetSingleColFilter('jalur')" title="Reset filter">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                <span>Reset</span>
                                            </button>
                                        </div>
                                        <input type="text" class="excel-filter-search" placeholder="Cari jalur..." oninput="onSearchFilterOptions('jalur', this.value)">
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllFilter('jalur', true)">
                                                <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Pilih Semua</span>
                                            </button>
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllFilter('jalur', false)">
                                                <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Hapus Pilihan</span>
                                            </button>
                                        </div>
                                        <div class="excel-filter-list" id="filter-list-jalur"></div>
                                        <div class="excel-filter-actions">
                                            <span class="text-[10px] text-slate-500 font-bold filter-count-info"></span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="excel-btn-close" onclick="closeAllExcelFilters()">Tutup</button>
                                                <button type="button" class="excel-btn-apply" onclick="applyExcelFilters()">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </th>

                                <!-- Kolom IDENTITAS ITEM -->
                                <th style="padding: 11px 14px; min-width: 220px; position: relative;">
                                    <div class="th-filter-wrapper">
                                        <span>Identitas Item (Kanban / Part)</span>
                                        <button type="button" class="th-filter-btn" data-col="item" title="Filter Item" onclick="toggleExcelFilter(event, 'item')">
                                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                        </button>
                                    </div>
                                    <div id="filter-dropdown-item" class="excel-filter-dropdown align-left" style="width: 270px;">
                                        <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                            <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER ITEM / PART</span>
                                            <button type="button" class="excel-reset-btn" onclick="resetSingleColFilter('item')" title="Reset filter">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                <span>Reset</span>
                                            </button>
                                        </div>
                                        <input type="text" class="excel-filter-search" placeholder="Cari part / kanban / customer..." oninput="onSearchFilterOptions('item', this.value)">
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllFilter('item', true)">
                                                <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Pilih Semua</span>
                                            </button>
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllFilter('item', false)">
                                                <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Hapus Pilihan</span>
                                            </button>
                                        </div>
                                        <div class="excel-filter-list" id="filter-list-item"></div>
                                        <div class="excel-filter-actions">
                                            <span class="text-[10px] text-slate-500 font-bold filter-count-info"></span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="excel-btn-close" onclick="closeAllExcelFilters()">Tutup</button>
                                                <button type="button" class="excel-btn-apply" onclick="applyExcelFilters()">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </th>

                                <!-- Kolom INSPECTOR QC -->
                                <th style="padding: 11px 14px; min-width: 130px; position: relative;">
                                    <div class="th-filter-wrapper">
                                        <span>Inspector QC</span>
                                        <button type="button" class="th-filter-btn" data-col="inspector" title="Filter Inspector" onclick="toggleExcelFilter(event, 'inspector')">
                                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                        </button>
                                    </div>
                                    <div id="filter-dropdown-inspector" class="excel-filter-dropdown align-left">
                                        <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                            <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER INSPECTOR</span>
                                            <button type="button" class="excel-reset-btn" onclick="resetSingleColFilter('inspector')" title="Reset filter">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                <span>Reset</span>
                                            </button>
                                        </div>
                                        <input type="text" class="excel-filter-search" placeholder="Cari inspector..." oninput="onSearchFilterOptions('inspector', this.value)">
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllFilter('inspector', true)">
                                                <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Pilih Semua</span>
                                            </button>
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllFilter('inspector', false)">
                                                <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Hapus Pilihan</span>
                                            </button>
                                        </div>
                                        <div class="excel-filter-list" id="filter-list-inspector"></div>
                                        <div class="excel-filter-actions">
                                            <span class="text-[10px] text-slate-500 font-bold filter-count-info"></span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="excel-btn-close" onclick="closeAllExcelFilters()">Tutup</button>
                                                <button type="button" class="excel-btn-apply" onclick="applyExcelFilters()">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </th>

                                <!-- KOLOM 1: LOT & QTY (BARANG) -->
                                <th style="padding: 11px 14px; text-align: center; width: 120px;">Lot & Qty</th>

                                <!-- KOLOM 2: SAMPLING (PEMERIKSAAN QC) -->
                                <th style="padding: 11px 14px; text-align: center; width: 95px;">Sampling</th>

                                <!-- Kolom STATUS -->
                                <th style="padding: 11px 14px; text-align: center; width: 95px; position: relative;">
                                    <div class="th-filter-wrapper">
                                        <span>Status</span>
                                        <button type="button" class="th-filter-btn" data-col="status" title="Filter Status" onclick="toggleExcelFilter(event, 'status')">
                                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                        </button>
                                    </div>
                                    <div id="filter-dropdown-status" class="excel-filter-dropdown align-right">
                                        <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                            <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER STATUS</span>
                                            <button type="button" class="excel-reset-btn" onclick="resetSingleColFilter('status')" title="Reset filter">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                <span>Reset</span>
                                            </button>
                                        </div>
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllFilter('status', true)">
                                                <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Pilih Semua</span>
                                            </button>
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllFilter('status', false)">
                                                <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Hapus Pilihan</span>
                                            </button>
                                        </div>
                                        <div class="excel-filter-list" id="filter-list-status"></div>
                                        <div class="excel-filter-actions">
                                            <span class="text-[10px] text-slate-500 font-bold filter-count-info"></span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="excel-btn-close" onclick="closeAllExcelFilters()">Tutup</button>
                                                <button type="button" class="excel-btn-apply" onclick="applyExcelFilters()">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </th>

                                <!-- Kolom PERSETUJUAN SUPERVISOR -->
                                <th style="padding: 11px 14px; text-align: center; width: 155px; position: relative;">
                                    <div class="th-filter-wrapper">
                                        <span>Persetujuan Supervisor</span>
                                        <button type="button" class="th-filter-btn" data-col="approval" title="Filter Persetujuan" onclick="toggleExcelFilter(event, 'approval')">
                                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                        </button>
                                    </div>
                                    <div id="filter-dropdown-approval" class="excel-filter-dropdown align-right">
                                        <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                            <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER PERSETUJUAN</span>
                                            <button type="button" class="excel-reset-btn" onclick="resetSingleColFilter('approval')" title="Reset filter">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                <span>Reset</span>
                                            </button>
                                        </div>
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllFilter('approval', true)">
                                                <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Pilih Semua</span>
                                            </button>
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllFilter('approval', false)">
                                                <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Hapus Pilihan</span>
                                            </button>
                                        </div>
                                        <div class="excel-filter-list" id="filter-list-approval"></div>
                                        <div class="excel-filter-actions">
                                            <span class="text-[10px] text-slate-500 font-bold filter-count-info"></span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="excel-btn-close" onclick="closeAllExcelFilters()">Tutup</button>
                                                <button type="button" class="excel-btn-apply" onclick="applyExcelFilters()">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </th>

                                <th style="padding: 11px 14px; text-align: center; width: 95px;">Rincian</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($sessionTimeline)): ?>
                                <tr>
                                    <td colspan="11" style="padding: 50px 24px; text-align: center;">
                                        <div style="display: flex; flex-direction: column; align-items: center; gap: 8px;">
                                            <svg style="width: 44px; height: 44px; color: #cbd5e1;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                            </svg>
                                            <span style="font-weight: 800; color: #334155; font-size: 14px;">Belum Ada Sesi Inspeksi</span>
                                            <span style="font-size: 12px; color: #64748b; max-width: 360px; line-height: 1.5; text-align: center;">Tidak ada aktivitas sesi inspeksi pada rentang tanggal atau kata kunci yang dipilih.</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($sessionTimeline as $stIdx => $sess): 
                                    $isKb = ($sess['inspection_type'] === 'kanban');
                                    $isReins = ((int)$sess['is_reinspection'] === 1 || (int)$sess['parent_session_id'] > 0);
                                    $isApp = (!empty($sess['is_approved']) && (int)$sess['is_approved'] === 1);
                                    $sDate = !empty($sess['started_at']) ? date('d M Y', strtotime($sess['started_at'])) : '-';
                                    $sTime = !empty($sess['started_at']) ? date('H:i', strtotime($sess['started_at'])) : '-';
                                    $eTime = !empty($sess['closed_at']) ? date('H:i', strtotime($sess['closed_at'])) : 'Sekarang';
                                    $fDate = $sDate;
                                    $fJalur = $isKb ? 'Kanban' : 'Safety Stock';
                                    $fItemPart = trim($sess['part_code'] . ' - ' . $sess['part_name']);
                                    $fInspector = trim($sess['inspector_name']);
                                    $fStatus = strtoupper($sess['session_status'] ?? 'IN PROG');
                                    $fApproval = $isApp ? 'DISETUJUI' : 'BELUM DI-ACC';
                                ?>
                                <tr class="review-row hover:bg-slate-50 transition-colors session-data-row <?= $isApp ? 'row-approved-session' : 'row-pending-session' ?>"
                                    data-approved="<?= $isApp ? '1' : '0' ?>"
                                    data-session-id="<?= $sess['session_id'] ?>"
                                    data-filter-date="<?= htmlspecialchars($fDate) ?>"
                                    data-filter-jalur="<?= htmlspecialchars($fJalur) ?>"
                                    data-filter-item="<?= htmlspecialchars($fItemPart) ?>"
                                    data-filter-inspector="<?= htmlspecialchars($fInspector) ?>"
                                    data-filter-status="<?= htmlspecialchars($fStatus) ?>"
                                    data-filter-approval="<?= htmlspecialchars($fApproval) ?>"
                                    style="border-bottom: 1px solid #f1f5f9; cursor: pointer; <?= $isApp ? 'display: none;' : '' ?>"
                                    onclick="toggleAccordion('acc-timeline-<?= $sess['session_id'] ?>')">
                                    
                                    <!-- Ceklis & Sesi # -->
                                    <td style="padding: 11px 10px; text-align: center;" onclick="event.stopPropagation();">
                                        <div style="display: flex; align-items: center; justify-content: center; gap: 6px;">
                                            <?php if (!$isApp): ?>
                                                <input type="checkbox" class="bulk-session-cb" value="<?= $sess['session_id'] ?>"
                                                       data-qty="<?= (int)$sess['computed_qty'] ?>"
                                                       onchange="onSessionCheckChange()"
                                                       title="Pilih sesi #<?= $sess['session_id'] ?> untuk disetujui"
                                                       style="cursor: pointer; width: 15px; height: 15px; accent-color: #2563eb; border-radius: 3px;">
                                            <?php else: ?>
                                                <span title="Sesi sudah disetujui (ACC)" style="width: 15px; height: 15px; display: inline-flex; align-items: center; justify-content: center; color: #16a34a;">
                                                    <svg style="width: 13px; height: 13px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                                </span>
                                            <?php endif; ?>
                                            <span style="font-family: monospace; font-weight: 900; font-size: 11px; color: #0f172a; background: #f1f5f9; padding: 2px 6px; border-radius: 5px; border: 1px solid #e2e8f0;">
                                                #<?= $sess['session_id'] ?>
                                            </span>
                                        </div>
                                    </td>

                                    <td style="padding: 11px 14px;">
                                        <div style="font-weight: 800; color: #0f172a; font-size: 11px; white-space: nowrap;">
                                            <?= $sTime ?> - <?= $eTime ?> WIB
                                        </div>
                                        <div style="font-size: 10px; color: #64748b; font-weight: 600; margin-top: 1px;">
                                            <?= $sDate ?>
                                        </div>
                                    </td>

                                    <td style="padding: 11px 14px; text-align: center;">
                                        <?php if ($isKb): ?>
                                            <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2.5px 8.5px; border-radius: 9999px; font-size: 10px; font-weight: 800; background-color: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; box-shadow: 0 1px 2px rgba(29,78,216,0.06);">
                                                <svg width="11" height="11" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: #2563eb;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                                <span>Kanban</span>
                                            </span>
                                        <?php else: ?>
                                            <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2.5px 8.5px; border-radius: 9999px; font-size: 10px; font-weight: 800; background-color: #f3e8ff; color: #6b21a8; border: 1px solid #d8b4fe; box-shadow: 0 1px 2px rgba(107,33,168,0.06);">
                                                <svg width="11" height="11" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: #9333ea;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                                <span>Safety Stock</span>
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td style="padding: 11px 14px;">
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
                                        <?php if (!empty($sess['approval_notes']) && stripos($sess['approval_notes'], 'Direct Close') !== false): ?>
                                            <span style="font-size: 9px; font-weight: 800; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 1px 5px; border-radius: 4px; display: inline-block; margin-top: 2px;" title="<?= htmlspecialchars($sess['approval_notes']) ?>">
                                                Tutup Langsung
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td style="padding: 11px 14px;">
                                        <div style="font-weight: 700; color: #1e293b; font-size: 11px;">
                                            <?= htmlspecialchars($sess['inspector_name']) ?>
                                        </div>
                                        <div style="font-size: 10px; color: #1d4ed8; font-weight: 800; margin-top: 2px;">
                                            <?= htmlspecialchars($sess['line_name'] ?: 'Line 1') ?>
                                        </div>
                                    </td>

                                    <!-- KOLOM 1: LOT & QTY (BARANG) -->
                                    <td style="padding: 11px 14px; text-align: center;">
                                        <div style="display: flex; flex-direction: column; align-items: center; gap: 3px;">
                                            <span style="display: inline-block; background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; font-weight: 800; font-size: 11px; padding: 1.5px 8px; border-radius: 9999px; line-height: 1.2;">
                                                <?= (int)$sess['box_count'] ?> Lot
                                            </span>
                                            <span style="font-family: monospace; font-weight: 900; color: #0f172a; font-size: 11.5px;">
                                                <?= number_format($sess['computed_qty']) ?> pcs
                                            </span>
                                        </div>
                                    </td>

                                    <!-- KOLOM 2: SAMPLING (PEMERIKSAAN QC) -->
                                    <td style="padding: 11px 14px; text-align: center;">
                                        <span style="font-family: monospace; font-weight: 800; font-size: 12px; color: #0284c7; background: #f0f9ff; border: 1px solid #e0f2fe; padding: 3px 8px; border-radius: 6px; display: inline-block;">
                                            <?= number_format((int)($sess['effective_sample_size'] ?? $sess['sample_size'])) ?> pcs
                                        </span>
                                    </td>

                                    <td style="padding: 11px 14px; text-align: center;">
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

                                    <!-- Persetujuan Supervisor -->
                                    <td style="padding: 11px 14px; text-align: center;" onclick="event.stopPropagation();">
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

                                    <td style="padding: 11px 14px; text-align: center;">
                                        <button type="button" class="btn-accordion-toggle" style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 5px 10px; font-size: 11px; font-weight: 700; color: #334155; display: inline-flex; align-items: center; gap: 4px;">
                                            <span class="btn-text">Detail</span>
                                            <svg class="caret-icon" style="width: 12px; height: 12px; transition: transform 0.2s;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                        </button>
                                    </td>
                                </tr>

                                <!-- Sub-Row Accordion Detail Sesi -->
                                <tr id="acc-timeline-<?= $sess['session_id'] ?>" class="accordion-sub-row hidden" data-parent-session-id="<?= $sess['session_id'] ?>" style="background: #fafafa;">
                                    <td colspan="10" style="padding: 14px 18px 18px 24px; border-bottom: 2px solid #e2e8f0;">
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
                                                            <th style="padding: 7px 12px; text-align: center; min-width: 130px;">Status Lot</th>
                                                            <th style="padding: 7px 12px; min-width: 110px;">Waktu Scan</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php if (empty($sess['lots'])): ?>
                                                            <tr><td colspan="8" style="padding: 8px; text-align: center; color: #94a3b8;">Tidak ada catatan rincian box.</td></tr>
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
                                                            ?>
                                                                <tr style="border-bottom: <?= $hasDefects ? '1px dashed #fca5a5' : ($isReinspect ? '1px dashed #86efac' : '1px solid #f8fafc') ?>; background: <?= $hasDefects ? '#fffbfb' : ($isReinspect ? '#f0fdf4' : 'transparent') ?>;">
                                                                    <td style="padding: 7px 12px; text-align: center; color: #94a3b8; font-weight: 700;"><?= $bIdx + 1 ?></td>
                                                                    <td style="padding: 7px 12px; font-family: monospace; color: #334155;">
                                                                        <?= htmlspecialchars($bLot['lot_number'] ?: '-') ?>
                                                                    </td>
                                                                    <td style="padding: 7px 12px; font-family: monospace; font-weight: 800; color: #0284c7;">
                                                                        <?= htmlspecialchars($bLot['ref_number'] ?: '-') ?>
                                                                    </td>
                                                                    <td style="padding: 7px 12px; text-align: right; font-family: monospace; font-weight: 700; color: #0f172a;">
                                                                        <?= number_format($bLot['qty']) ?> pcs
                                                                    </td>
                                                                    <td style="padding: 7px 12px; text-align: center; font-family: monospace; font-weight: 700; color: #2563eb;">
                                                                        <?= !empty($bLot['is_safety_stock']) ? '<span style="color:#7c3aed;font-weight:800;">0 (Lolos SS)</span>' : ((int)$bLot['computed_sample_size'] . ' pcs') ?>
                                                                    </td>
                                                                    <td style="padding: 7px 12px; text-align: center;">
                                                                        <span style="font-family: monospace; font-size: 11px; font-weight: 800; padding: 2px 7px; border-radius: 4px; <?= $lotNgTotal > 0 ? 'background: #fef2f2; color: #dc2626; border: 1px solid #fecaca;' : 'background: #f0fdf4; color: #16a34a; border: 1px solid #dcfce7;' ?>">
                                                                            <?= $lotNgTotal ?> / <?= (int)$bLot['computed_reject_number'] ?>
                                                                        </span>
                                                                    </td>
                                                                    <td style="padding: 7px 12px; text-align: center;">
                                                                        <?= renderLotStatusBadge($bLot) ?>
                                                                    </td>
                                                                    <td style="padding: 7px 12px; color: #64748b;">
                                                                        <?= date('H:i', strtotime($bLot['scanned_at'])) ?> WIB
                                                                    </td>
                                                                </tr>
                                                                <?php if ($hasDefects): ?>
                                                                    <tr style="background: #fff5f5; border-bottom: 2px solid #fecaca;">
                                                                        <td colspan="8" style="padding: 6px 12px 8px 16px;">
                                                                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                                                                <div style="font-size: 10px; font-weight: 800; color: #991b1b; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 4px;">
                                                                                    <svg style="width: 12px; height: 12px; color: #dc2626;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                                                                    <span>Temuan Cacat pada Box / Lot Ini (<?= count($bLot['defects']) ?> defect):</span>
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
                                                            <?php endforeach; ?>
                                                        <?php endif; ?>
                                                    </tbody>
                                                </table>
                                            </div>

                                            <!-- Defects box (unmatched) -->
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
                                                <div style="font-size: 11px; color: #166534; background: #f0fdf4; border: 1px solid #dcfce7; border-radius: 6px; padding: 5px 10px; display: inline-flex; align-items: center; gap: 4px;">
                                                    <svg style="width: 13px; height: 13px; color: #16a34a;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                                    <span><b>All Samples OK:</b> Seluruh sampel lot memenuhi standar, tidak ditemukan cacat.</span>
                                                </div>
                                            <?php endif; ?>

                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <!-- TAB PANE 2: RINGKASAN PER HARI (REKAP HARIAN) -->
        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <div id="pane-tab-daily" style="display: <?= $activeTab === 'daily' ? 'block' : 'none' ?>;" class="space-y-4">
            <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,0.04);">
                <div style="padding: 12px 16px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <h2 style="font-size: 12px; font-weight: 800; color: #334155; text-transform: uppercase; letter-spacing: 0.5px; margin: 0;">Ringkasan Per Hari</h2>
                        <span style="padding: 2px 9px; background: #dbeafe; color: #1d4ed8; font-size: 11px; font-weight: 800; border-radius: 9999px;"><?= number_format($totalItems) ?> Hari</span>
                    </div>
                    <form method="GET" action="<?= base_url('modules/daily_report/index.php') ?>" style="display: flex; align-items: center; gap: 6px; font-size: 12px;">
                        <input type="hidden" name="preset" value="<?= htmlspecialchars($presetFilter) ?>">
                        <input type="hidden" name="start_date" value="<?= htmlspecialchars($startDate) ?>">
                        <input type="hidden" name="end_date" value="<?= htmlspecialchars($endDate) ?>">
                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                        <input type="hidden" name="tab" value="daily">
                        <span style="color: #64748b; font-weight: 500;">Tampilkan:</span>
                        <select name="limit" onchange="this.form.submit()" style="padding: 4px 8px; background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; font-weight: 700; color: #334155; outline: none;">
                            <?php foreach ([10, 15, 25, 50, 100] as $lv): ?>
                                <option value="<?= $lv ?>" <?= ($limit == $lv) ? 'selected' : '' ?>><?= $lv ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>

                <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 12px; color: #334155; min-width: 720px;">
                        <thead style="background: #fff; border-bottom: 2px solid #e2e8f0; font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px;">
                            <tr>
                                <th style="padding: 10px 14px; width: 40px; text-align: center;">No</th>
                                <th style="padding: 10px 14px; min-width: 130px;">Tanggal</th>
                                <th style="padding: 10px 14px; text-align: center; width: 75px;">Sesi</th>
                                <th style="padding: 10px 14px; text-align: center; width: 95px;">Total Label</th>
                                <th style="padding: 10px 14px; text-align: center; width: 115px;">Total Sampling</th>
                                <th style="padding: 10px 14px; text-align: center; width: 85px;">Total NG</th>
                                <th style="padding: 10px 14px; text-align: center; min-width: 140px;">Persetujuan Spv</th>
                                <th style="padding: 10px 14px; min-width: 130px;">Jenis Inspeksi</th>
                                <th style="padding: 10px 14px; text-align: right; width: 80px;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="9" style="padding: 52px 24px; text-align: center;">
                                        <div style="display: flex; flex-direction: column; align-items: center; gap: 8px;">
                                             <svg style="width: 44px; height: 44px; color: #cbd5e1;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                            </svg>
                                            <span style="font-weight: 800; color: #334155; font-size: 14px;">Belum Ada Data Inspeksi</span>
                                            <span style="font-size: 12px; color: #64748b; max-width: 360px; line-height: 1.5; text-align: center;">Tidak ada aktivitas inspeksi pada rentang tanggal atau kata kunci yang dipilih.</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rows as $idx => $row): ?>
                                    <?php
                                        $rowNum   = (($page - 1) * $limit) + $idx + 1;
                                        $tgl      = $row['tanggal'];
                                        $totalNg  = (int)$row['total_ng'];
                                        $types    = array_filter(explode(',', $row['jenis_inspeksi'] ?? ''));
                                        $isWeek   = in_array(date('N', strtotime($tgl)), [6, 7]);
                                        $appCount = (int)$row['total_approved'];
                                        $sessCount = (int)$row['total_sesi'];
                                        $isAllApp = ($sessCount > 0 && $appCount === $sessCount);
                                    ?>
                                    <tr style="border-bottom: 1px solid #f1f5f9;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                                        <td style="padding: 11px 14px; text-align: center; font-weight: 700; color: #94a3b8; font-size: 11px;"><?= $rowNum ?></td>
                                        <td style="padding: 11px 14px;">
                                            <div style="font-weight: 800; color: #1e293b; font-size: 13px;"><?= date('d M Y', strtotime($tgl)) ?></div>
                                            <div style="font-size: 10px; color: <?= $isWeek ? '#dc2626' : '#64748b' ?>; font-weight: 600; margin-top: 1px;"><?= date('l', strtotime($tgl)) ?></div>
                                        </td>
                                        <td style="padding: 11px 14px; text-align: center;">
                                            <span style="font-weight: 800; color: #0f172a; font-size: 13px;"><?= (int)$row['total_sesi'] ?></span>
                                            <div style="font-size: 10px; color: #94a3b8; font-weight: 600;">sesi</div>
                                        </td>
                                        <td style="padding: 11px 14px; text-align: center;">
                                            <span style="display: inline-block; background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; font-weight: 800; font-size: 13px; padding: 2px 12px; border-radius: 9999px;"><?= number_format((int)$row['total_label']) ?></span>
                                            <div style="font-size: 10px; color: #94a3b8; font-weight: 600; margin-top: 2px;">label</div>
                                        </td>
                                        <td style="padding: 11px 14px; text-align: center;">
                                            <span style="font-family: monospace; font-weight: 900; font-size: 13px; color: #0f172a;"><?= number_format((int)$row['total_sampling']) ?></span>
                                            <div style="font-size: 10px; color: #94a3b8; font-weight: 600;">pcs</div>
                                        </td>
                                        <td style="padding: 11px 14px; text-align: center;">
                                            <?php if ($totalNg > 0): ?>
                                                <span style="display: inline-block; background: #ffe4e6; color: #be123c; border: 1px solid #fecdd3; font-weight: 900; font-size: 13px; padding: 2px 12px; border-radius: 9999px;"><?= $totalNg ?></span>
                                                <div style="font-size: 10px; color: #be123c; font-weight: 600; margin-top: 2px;">defect</div>
                                            <?php else: ?>
                                                <span style="font-size: 12px; color: #22c55e; font-weight: 700;">OK</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 11px 14px; text-align: center;">
                                            <?php if ($sessCount === 0): ?>
                                                <span style="font-size: 11px; color: #94a3b8;">-</span>
                                            <?php elseif ($isAllApp): ?>
                                                <span style="display: inline-flex; align-items: center; gap: 4px; background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; font-weight: 800; font-size: 12px; padding: 3px 10px; border-radius: 9999px;">
                                                    <svg style="width: 12px; height: 12px; color: #10b981;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                                    <?= $appCount ?> / <?= $sessCount ?> Sesi
                                                </span>
                                                <div style="font-size: 9px; color: #059669; font-weight: 700; margin-top: 2px;">Lengkap (100%)</div>
                                            <?php elseif ($appCount > 0): ?>
                                                <span style="display: inline-flex; align-items: center; gap: 4px; background: #fffbeb; color: #b45309; border: 1px solid #fde68a; font-weight: 800; font-size: 12px; padding: 3px 10px; border-radius: 9999px;">
                                                    <svg style="width: 12px; height: 12px; color: #f59e0b;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                    <?= $appCount ?> / <?= $sessCount ?> Sesi
                                                </span>
                                                <div style="font-size: 9px; color: #d97706; font-weight: 700; margin-top: 2px;"><?= ($sessCount - $appCount) ?> Sesi Pending</div>
                                            <?php else: ?>
                                                <span style="display: inline-flex; align-items: center; gap: 4px; background: #f8fafc; color: #64748b; border: 1px solid #e2e8f0; font-weight: 800; font-size: 12px; padding: 3px 10px; border-radius: 9999px;">
                                                    0 / <?= $sessCount ?> Sesi
                                                </span>
                                                <div style="font-size: 9px; color: #94a3b8; font-weight: 600; margin-top: 2px;">Menunggu ACC</div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 11px 14px;">
                                            <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                                                <?php foreach ($types as $t): ?>
                                                    <?php if ($t === 'safety_stock'): ?>
                                                        <span style="font-size: 10px; font-weight: 700; color: #0369a1; background: #e0f2fe; border: 1px solid #bae6fd; padding: 2px 8px; border-radius: 9999px;">Safety Stock</span>
                                                    <?php elseif ($t === 'kanban'): ?>
                                                        <span style="font-size: 10px; font-weight: 700; color: #7c3aed; background: #ede9fe; border: 1px solid #c4b5fd; padding: 2px 8px; border-radius: 9999px;">Kanban</span>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                        <td style="padding: 11px 14px; text-align: right;">
                                            <a href="<?= base_url('modules/daily_report/detail.php?date=' . urlencode($tgl)) ?>"
                                               style="display: inline-flex; align-items: center; gap: 5px; padding: 6px 12px; background: #eff6ff; color: #1d4ed8; font-weight: 700; font-size: 11px; border-radius: 8px; border: 1px solid #bfdbfe; text-decoration: none; white-space: nowrap;"
                                               onmouseover="this.style.background='#dbeafe'" onmouseout="this.style.background='#eff6ff'">
                                                <svg style="width: 13px; height: 13px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                                </svg>
                                                Detail
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): echo render_pagination($page, $totalPages, $totalItems, $limit); endif; ?>
            </div>
        </div>

        <!-- FLOATING BULK ACTION BOTTOM BAR (SOLID STYLING - CENTER BOTTOM) -->
        <div id="bulk-session-action-bar" 
             style="display: none; position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); z-index: 9998; background-color: #0f172a; color: #ffffff; border: 1px solid #334155; border-radius: 14px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.4), 0 8px 10px -6px rgba(0, 0, 0, 0.3); padding: 10px 18px;"
             class="items-center gap-4">
            <div class="flex items-center space-x-3">
                <span style="background-color: #2563eb; color: #ffffff; font-size: 11px; font-weight: 800; padding: 4px 10px; border-radius: 9999px;" class="tracking-wide">
                    <span id="bulk-session-selected-count">0</span> SESI TERPILIH
                </span>
                <div class="text-xs text-slate-300 font-medium">
                    Total Qty: <span id="bulk-session-selected-qty" class="text-white font-mono font-bold text-sm">0 pcs</span>
                </div>
            </div>
            <div class="h-5 w-px bg-slate-700"></div>
            <div class="flex items-center space-x-2">
                <button type="button" onclick="clearBulkSessionSelection()" 
                        style="background-color: #334155; color: #cbd5e1; border: 1px solid #475569; font-size: 11px; font-weight: 700; padding: 7px 12px; border-radius: 8px;"
                        class="hover:bg-slate-600 hover:text-white cursor-pointer transition-colors">
                    Batal Pilih
                </button>
                <button type="button" onclick="confirmApproveSelected()" 
                        style="background-color: #059669; color: #ffffff; border: 1px solid #047857; font-size: 12px; font-weight: 800; padding: 7px 16px; border-radius: 8px; box-shadow: 0 2px 4px rgba(5,150,105,0.4);"
                        class="hover:opacity-95 active:scale-95 inline-flex items-center gap-1.5 cursor-pointer transition-all">
                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                    <span>Setujui Terpilih Sekaligus (ACC)</span>
                </button>
            </div>
        </div>

    </main>

    <script>
    // Tab Switcher Handler
    function switchReportTab(tabKey) {
        const paneSession = document.getElementById('pane-tab-session');
        const paneDaily   = document.getElementById('pane-tab-daily');
        const btnSession  = document.getElementById('tab-btn-session');
        const btnDaily    = document.getElementById('tab-btn-daily');
        const inputTab    = document.getElementById('inputTab');
        const descText    = document.getElementById('tab-desc-text');

        inputTab.value = tabKey;

        if (tabKey === 'session') {
            paneSession.style.display = 'block';
            paneDaily.style.display   = 'none';

            btnSession.className = 'bg-blue-600 text-white shadow-xs';
            btnDaily.className   = 'text-slate-600 hover:text-slate-900 hover:bg-slate-200/70';

            descText.textContent = 'Menampilkan kronologi seluruh sesi inspeksi secara langsung';
        } else {
            paneSession.style.display = 'none';
            paneDaily.style.display   = 'block';

            btnDaily.className   = 'bg-blue-600 text-white shadow-xs';
            btnSession.className = 'text-slate-600 hover:text-slate-900 hover:bg-slate-200/70';

            descText.textContent = 'Menampilkan rekapitulasi performa inspeksi dikelompokkan per hari';
        }

        // Update URL state without full page reload
        try {
            const url = new URL(window.location);
            url.searchParams.set('tab', tabKey);
            window.history.replaceState({}, '', url);
        } catch (e) {}
    }

    // Filter Approved Sessions (Hide/Show Sesi yang Sudah di-ACC)
    function filterApprovedSessions(showApproved) {
        try {
            localStorage.setItem('oqc_show_approved_sessions', showApproved ? '1' : '0');
        } catch (e) {}

        const approvedRows = document.querySelectorAll('.row-approved-session');
        const badgeCount = document.getElementById('badgeTotalSessionCount');
        const totalRows = document.querySelectorAll('.session-data-row').length;
        const pendingRows = document.querySelectorAll('.row-pending-session').length;

        approvedRows.forEach(function(row) {
            row.style.display = showApproved ? '' : 'none';
            // Sembunyikan accordion sub-row jika induknya disembunyikan
            if (!showApproved) {
                const sId = row.getAttribute('data-session-id');
                const subRow = document.getElementById('acc-timeline-' + sId);
                if (subRow && !subRow.classList.contains('hidden')) {
                    subRow.classList.add('hidden');
                    const caret = row.querySelector('.caret-icon');
                    const btnText = row.querySelector('.btn-text');
                    if (caret) caret.style.transform = 'rotate(0deg)';
                    if (btnText) btnText.textContent = 'Detail';
                }
            }
        });

        if (badgeCount) {
            if (showApproved) {
                badgeCount.textContent = 'Total ' + totalRows + ' Sesi';
            } else {
                badgeCount.textContent = pendingRows + ' Sesi Menunggu ACC (' + totalRows + ' Total)';
            }
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const toggle = document.getElementById('toggleShowApproved');
        if (toggle) {
            // Default: tidak dicentang (0), artinya sesi yang sudah disetujui langsung disembunyikan
            const savedState = localStorage.getItem('oqc_show_approved_sessions');
            const shouldShow = (savedState === '1');
            toggle.checked = shouldShow;
            filterApprovedSessions(shouldShow);
        }
    });

    // Accordion Toggle Handler
    function toggleAccordion(rowId) {
        const subRow = document.getElementById(rowId);
        if (!subRow) return;

        const isHidden = subRow.classList.contains('hidden');
        if (isHidden) {
            subRow.classList.remove('hidden');
        } else {
            subRow.classList.add('hidden');
        }

        const parentRow = subRow.previousElementSibling;
        if (parentRow) {
            const caret = parentRow.querySelector('.caret-icon');
            const btnText = parentRow.querySelector('.btn-text');
            if (caret) {
                caret.style.transform = isHidden ? 'rotate(180deg)' : 'rotate(0deg)';
            }
            if (btnText) {
                btnText.textContent = isHidden ? 'Tutup' : 'Detail';
            }
        }
    }

    // Preset Date Filters Handler
    function handlePresetClick(presetKey) {
        const inputPreset = document.getElementById('inputPreset');
        const containerSubFilter = document.getElementById('containerSubFilter');
        const boxMonthYear = document.getElementById('boxFilterMonthYear');
        const boxSpecificYear = document.getElementById('boxFilterSpecificYear');
        const inputStartDate = document.getElementById('inputStartDate');
        const inputEndDate = document.getElementById('inputEndDate');

        inputPreset.value = presetKey;

        if (presetKey === 'month_year') {
            containerSubFilter.style.display = 'flex';
            boxMonthYear.style.display = 'flex';
            boxSpecificYear.style.display = 'none';
            return;
        } else if (presetKey === 'specific_year') {
            containerSubFilter.style.display = 'flex';
            boxMonthYear.style.display = 'none';
            boxSpecificYear.style.display = 'flex';
            return;
        } else {
            containerSubFilter.style.display = 'none';
            boxMonthYear.style.display = 'none';
            boxSpecificYear.style.display = 'none';
        }

        // Clear start/end dates so backend calculates based on preset
        if (presetKey !== 'custom') {
            inputStartDate.value = '';
            inputEndDate.value = '';
        }

        document.getElementById('formDailyFilter').submit();
    }

    function applyMonthYearFilter() {
        const m = document.getElementById('subSelectMonth').value;
        const y = document.getElementById('subSelectMonthYear').value;
        document.getElementById('inputMonth').value = m;
        document.getElementById('inputYear').value = y;
        document.getElementById('inputPreset').value = 'month_year';
        document.getElementById('inputStartDate').value = '';
        document.getElementById('inputEndDate').value = '';
        document.getElementById('formDailyFilter').submit();
    }

    function applySpecificYearFilter() {
        const y = document.getElementById('subSelectYear').value;
        document.getElementById('inputYear').value = y;
        document.getElementById('inputPreset').value = 'specific_year';
        document.getElementById('inputStartDate').value = '';
        document.getElementById('inputEndDate').value = '';
        document.getElementById('formDailyFilter').submit();
    }

    // ═══════════════════════════════════════════════════════════════════
    // SUPERVISOR APPROVAL (ACC) HANDLERS
    // ═══════════════════════════════════════════════════════════════════
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
                    date: '<?= htmlspecialchars($startDate) ?>'
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
                    date: '<?= htmlspecialchars($startDate) ?>'
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
                    date: '<?= htmlspecialchars($startDate) ?>'
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
                    date: '<?= htmlspecialchars($startDate) ?>'
                });
            }
        });
    }

    function confirmApproveAllVisible() {
        const pendingRows = document.querySelectorAll('.row-pending-session');
        const pendingCount = pendingRows.length;

        if (pendingCount === 0) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'info',
                    title: 'Tidak Ada Sesi Pending',
                    text: 'Seluruh sesi pada filter saat ini telah disetujui (ACC).'
                });
            } else {
                alert('Tidak ada sesi yang memerlukan persetujuan.');
            }
            return;
        }

        // Kumpulkan session_id dari seluruh baris yang masih pending
        const pendingIds = [];
        pendingRows.forEach(function(r) {
            const sid = r.getAttribute('data-session-id');
            if (sid) pendingIds.push(sid);
        });

        const filterContext = '<?= !empty($startDate) && !empty($endDate) ? date("d/m/Y", strtotime($startDate)) . " - " . date("d/m/Y", strtotime($endDate)) : "" ?>';

        if (typeof Swal === 'undefined') {
            if (confirm('Apakah Anda yakin ingin menyetujui seluruh (' + pendingCount + ') sesi inspeksi yang tampil?')) {
                submitApproval({
                    action: 'approve_all_filtered',
                    session_ids: pendingIds.join(','),
                    start_date: '<?= htmlspecialchars($startDate) ?>',
                    end_date: '<?= htmlspecialchars($endDate) ?>',
                    inspector_id: '<?= (int)$inspectorId ?>',
                    notes: 'Persetujuan massal oleh supervisor'
                });
            }
            return;
        }

        Swal.fire({
            title: 'Setujui Semua Sesi (ACC All)',
            html: 'Anda akan memberikan persetujuan (ACC) sekaligus untuk <b>' + pendingCount + ' sesi inspeksi</b> yang belum di-ACC' + (filterContext ? ' pada periode <b>' + filterContext + '</b>' : '') + '.<br><br><span style="font-size: 12px; color: #64748b;">Pastikan seluruh hasil pemeriksaan dan temuan NG telah ditinjau dengan seksama.</span>',
            icon: 'question',
            input: 'text',
            inputLabel: 'Catatan Persetujuan Massal (Opsional)',
            inputPlaceholder: 'Tuliskan catatan supervisor bila diperlukan...',
            showCancelButton: true,
            confirmButtonColor: '#16a34a',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Ya, Setujui Semua (' + pendingCount + ' Sesi)',
            cancelButtonText: 'Batal'
        }).then(function(result) {
            if (result.isConfirmed) {
                submitApproval({
                    action: 'approve_all_filtered',
                    session_ids: pendingIds.join(','),
                    start_date: '<?= htmlspecialchars($startDate) ?>',
                    end_date: '<?= htmlspecialchars($endDate) ?>',
                    inspector_id: '<?= (int)$inspectorId ?>',
                    notes: result.value || 'Persetujuan massal oleh supervisor'
                });
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════════
    // BULK CHECKBOX APPROVAL HANDLERS (FLOATING BOTTOM BAR)
    // ═══════════════════════════════════════════════════════════════════
    function onSessionCheckChange() {
        const checkboxes = document.querySelectorAll('.bulk-session-cb');
        const checkedBoxes = document.querySelectorAll('.bulk-session-cb:checked');
        const count = checkedBoxes.length;

        let totalQty = 0;
        checkedBoxes.forEach(function(cb) {
            totalQty += parseInt(cb.getAttribute('data-qty') || 0, 10);
        });

        const actionBar = document.getElementById('bulk-session-action-bar');
        const countSpan = document.getElementById('bulk-session-selected-count');
        const qtySpan   = document.getElementById('bulk-session-selected-qty');
        const checkAll  = document.getElementById('checkAllSessions');

        if (countSpan) countSpan.textContent = count;
        if (qtySpan) qtySpan.textContent = totalQty.toLocaleString('id-ID') + ' pcs';

        if (actionBar) {
            actionBar.style.display = (count > 0) ? 'inline-flex' : 'none';
        }

        if (checkAll) {
            // Cek hanya checkbox yang sedang tampak (visible)
            const visibleCheckboxes = Array.from(checkboxes).filter(function(cb) {
                const tr = cb.closest('tr.session-data-row');
                return tr && tr.style.display !== 'none';
            });
            const visibleChecked = visibleCheckboxes.filter(function(cb) { return cb.checked; });
            
            checkAll.checked = (visibleCheckboxes.length > 0 && visibleChecked.length === visibleCheckboxes.length);
            checkAll.indeterminate = (visibleChecked.length > 0 && visibleChecked.length < visibleCheckboxes.length);
        }
    }

    function clearBulkSessionSelection() {
        const checkboxes = document.querySelectorAll('.bulk-session-cb');
        checkboxes.forEach(function(cb) {
            cb.checked = false;
        });
        const checkAll = document.getElementById('checkAllSessions');
        if (checkAll) {
            checkAll.checked = false;
            checkAll.indeterminate = false;
        }
        onSessionCheckChange();
    }

    function toggleSelectAllSessionRows(checkState) {
        const checkboxes = document.querySelectorAll('.bulk-session-cb');
        checkboxes.forEach(function(cb) {
            const tr = cb.closest('tr.session-data-row');
            // Hanya centang baris yang saat ini terlihat (lolos filter)
            if (tr && tr.style.display !== 'none') {
                cb.checked = checkState;
            }
        });
        onSessionCheckChange();
    }

    function confirmApproveSelected() {
        const checkedBoxes = document.querySelectorAll('.bulk-session-cb:checked');
        const selectedIds = [];
        let totalQty = 0;
        checkedBoxes.forEach(function(cb) {
            if (cb.value) {
                selectedIds.push(cb.value);
                totalQty += parseInt(cb.getAttribute('data-qty') || 0, 10);
            }
        });

        if (selectedIds.length === 0) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'info',
                    title: 'Pilih Sesi Terlebih Dahulu',
                    text: 'Silakan beri centang pada kotak ceklis sesi yang ingin disetujui (ACC).'
                });
            } else {
                alert('Pilih sesi terlebih dahulu.');
            }
            return;
        }

        const count = selectedIds.length;

        if (typeof Swal === 'undefined') {
            if (confirm('Apakah Anda yakin ingin menyetujui (' + count + ') sesi inspeksi terpilih (Total Qty: ' + totalQty + ' pcs)?')) {
                submitApproval({
                    action: 'approve_all_filtered',
                    session_ids: selectedIds.join(','),
                    start_date: '<?= htmlspecialchars($startDate) ?>',
                    end_date: '<?= htmlspecialchars($endDate) ?>',
                    notes: 'Disetujui secara bersamaan oleh supervisor'
                });
            }
            return;
        }

        Swal.fire({
            title: 'Setujui Sesi Terpilih (ACC Bulk)',
            html: 'Anda akan memberikan persetujuan (ACC) sekaligus untuk <b>' + count + ' sesi inspeksi</b> yang Anda centang.<br>' +
                  '<div style="margin-top: 8px; font-size: 13px; font-weight: 700; color: #1e40af; background: #eff6ff; padding: 6px 12px; border-radius: 8px; border: 1px solid #bfdbfe;">Total Barang: ' + totalQty.toLocaleString('id-ID') + ' pcs</div>' +
                  '<br><span style="font-size: 12px; color: #64748b;">Pastikan seluruh hasil pemeriksaan dan lot dari sesi terpilih telah Anda tinjau.</span>',
            icon: 'question',
            input: 'text',
            inputLabel: 'Catatan Persetujuan (Opsional)',
            inputPlaceholder: 'Tuliskan catatan supervisor bila diperlukan...',
            showCancelButton: true,
            confirmButtonColor: '#059669',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Ya, Setujui (' + count + ' Sesi)',
            cancelButtonText: 'Batal'
        }).then(function(result) {
            if (result.isConfirmed) {
                submitApproval({
                    action: 'approve_all_filtered',
                    session_ids: selectedIds.join(','),
                    start_date: '<?= htmlspecialchars($startDate) ?>',
                    end_date: '<?= htmlspecialchars($endDate) ?>',
                    notes: result.value || 'Disetujui secara bersamaan oleh supervisor'
                });
            }
        });
    }
    // EXCEL-STYLE COLUMN HEADER FILTERS ENGINE (DAILY REPORT SESSION TIMELINE)
    // ══════════════════════════════════════════════════════════════════════════
    var ExcelFilterState = {
        activeCol: null,
        filters: {
            date: new Set(),
            jalur: new Set(),
            item: new Set(),
            inspector: new Set(),
            status: new Set(),
            approval: new Set()
        },
        options: {
            date: [],
            jalur: [],
            item: [],
            inspector: [],
            status: [],
            approval: []
        }
    };

    function initExcelFilters() {
        var rows = document.querySelectorAll('tr.session-data-row');
        if (!rows || rows.length === 0) return;

        var sets = {
            date: new Set(),
            jalur: new Set(),
            item: new Set(),
            inspector: new Set(),
            status: new Set(),
            approval: new Set()
        };

        rows.forEach(function(r) {
            var d = (r.getAttribute('data-filter-date') || '').trim();
            var j = (r.getAttribute('data-filter-jalur') || '').trim();
            var it = (r.getAttribute('data-filter-item') || '').trim();
            var ins = (r.getAttribute('data-filter-inspector') || '').trim();
            var st = (r.getAttribute('data-filter-status') || '').trim();
            var ap = (r.getAttribute('data-filter-approval') || '').trim();

            if (d) sets.date.add(d);
            if (j) sets.jalur.add(j);
            if (it) sets.item.add(it);
            if (ins) sets.inspector.add(ins);
            if (st) sets.status.add(st);
            if (ap) sets.approval.add(ap);
        });

        ['date', 'jalur', 'item', 'inspector', 'status', 'approval'].forEach(function(col) {
            ExcelFilterState.options[col] = Array.from(sets[col]).sort();
            renderFilterCheckboxes(col);
        });
    }

    function renderFilterCheckboxes(col) {
        var container = document.getElementById('filter-list-' + col);
        if (!container) return;

        var options = ExcelFilterState.options[col] || [];
        var activeSet = ExcelFilterState.filters[col];
        var isAllActive = (activeSet.size === 0);

        container.innerHTML = '';
        if (options.length === 0) {
            container.innerHTML = '<div style="color: #94a3b8; padding: 6px; text-align: center; font-size: 10px;">Tidak ada opsi</div>';
            return;
        }

        options.forEach(function(opt) {
            var isChecked = isAllActive || activeSet.has(opt);
            var itemDiv = document.createElement('label');
            itemDiv.className = 'excel-filter-item';
            itemDiv.setAttribute('data-val', opt.toLowerCase());

            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.value = opt;
            cb.checked = isChecked;

            var span = document.createElement('span');
            span.textContent = opt;
            span.style.overflow = 'hidden';
            span.style.textOverflow = 'ellipsis';
            span.style.whiteSpace = 'nowrap';
            span.title = opt;

            itemDiv.appendChild(cb);
            itemDiv.appendChild(span);
            container.appendChild(itemDiv);
        });

        updateFilterCountInfo(col);
    }

    function updateFilterCountInfo(col) {
        var dropdown = document.getElementById('filter-dropdown-' + col);
        if (!dropdown) return;
        var info = dropdown.querySelector('.filter-count-info');
        if (!info) return;

        var checkboxes = dropdown.querySelectorAll('.excel-filter-list input[type="checkbox"]');
        var checkedCount = 0;
        checkboxes.forEach(function(cb) {
            if (cb.checked) checkedCount++;
        });
        info.textContent = checkedCount + ' / ' + checkboxes.length + ' dipilih';
    }

    function toggleExcelFilter(event, col) {
        event.stopPropagation();
        var dropdown = document.getElementById('filter-dropdown-' + col);
        if (!dropdown) return;

        var isVisible = (dropdown.style.display === 'block');
        closeAllExcelFilters();

        if (!isVisible) {
            dropdown.style.display = 'block';
            ExcelFilterState.activeCol = col;
            var searchInput = dropdown.querySelector('.excel-filter-search');
            if (searchInput) {
                searchInput.value = '';
                onSearchFilterOptions(col, '');
                setTimeout(function() { searchInput.focus(); }, 50);
            }
        }
    }

    function closeAllExcelFilters() {
        document.querySelectorAll('.excel-filter-dropdown').forEach(function(dd) {
            dd.style.display = 'none';
        });
        ExcelFilterState.activeCol = null;
    }

    function onSearchFilterOptions(col, keyword) {
        var container = document.getElementById('filter-list-' + col);
        if (!container) return;
        var kw = (keyword || '').toLowerCase().trim();
        var items = container.querySelectorAll('.excel-filter-item');
        items.forEach(function(item) {
            var val = item.getAttribute('data-val') || '';
            if (!kw || val.indexOf(kw) !== -1) {
                item.style.display = 'flex';
            } else {
                item.style.display = 'none';
            }
        });
    }

    function toggleSelectAllFilter(col, selectAll) {
        var container = document.getElementById('filter-list-' + col);
        if (!container) return;
        var checkboxes = container.querySelectorAll('input[type="checkbox"]');
        checkboxes.forEach(function(cb) {
            if (cb.closest('.excel-filter-item').style.display !== 'none') {
                cb.checked = selectAll;
            }
        });
        updateFilterCountInfo(col);
    }

    function applyExcelFilters() {
        var cols = ['date', 'jalur', 'item', 'inspector', 'status', 'approval'];

        cols.forEach(function(col) {
            var container = document.getElementById('filter-list-' + col);
            if (!container) return;

            var checkboxes = container.querySelectorAll('input[type="checkbox"]');
            var allCount = checkboxes.length;
            var checkedValues = [];

            checkboxes.forEach(function(cb) {
                if (cb.checked) {
                    checkedValues.push(cb.value);
                }
            });

            var btn = document.querySelector('.th-filter-btn[data-col="' + col + '"]');

            if (checkedValues.length === 0 || checkedValues.length === allCount) {
                ExcelFilterState.filters[col].clear();
                if (btn) btn.classList.remove('active');
            } else {
                ExcelFilterState.filters[col] = new Set(checkedValues);
                if (btn) btn.classList.add('active');
            }
        });

        closeAllExcelFilters();
        executeDailyReportFiltering();
    }

    function resetSingleColFilter(col) {
        ExcelFilterState.filters[col].clear();
        var btn = document.querySelector('.th-filter-btn[data-col="' + col + '"]');
        if (btn) btn.classList.remove('active');
        renderFilterCheckboxes(col);
        closeAllExcelFilters();
        executeDailyReportFiltering();
    }

    function resetAllExcelFilters() {
        ['date', 'jalur', 'item', 'inspector', 'status', 'approval'].forEach(function(col) {
            ExcelFilterState.filters[col].clear();
            var btn = document.querySelector('.th-filter-btn[data-col="' + col + '"]');
            if (btn) btn.classList.remove('active');
            renderFilterCheckboxes(col);
        });
        closeAllExcelFilters();
        executeDailyReportFiltering();
    }

    function executeDailyReportFiltering() {
        var rows = document.querySelectorAll('tr.session-data-row');
        if (!rows || rows.length === 0) return;

        var showApprovedToggle = document.getElementById('toggleShowApproved');
        var allowApproved = showApprovedToggle ? showApprovedToggle.checked : true;

        var activeFilterCols = [];
        ['date', 'jalur', 'item', 'inspector', 'status', 'approval'].forEach(function(c) {
            if (ExcelFilterState.filters[c].size > 0) activeFilterCols.push(c);
        });

        var visibleCount = 0;
        var totalRows = rows.length;

        rows.forEach(function(r) {
            var sId = r.getAttribute('data-session-id');
            var subRow = document.getElementById('acc-timeline-' + sId);
            var isApproved = (r.getAttribute('data-approved') === '1');

            // 1. Cek toggle approval
            if (!allowApproved && isApproved) {
                r.style.display = 'none';
                if (subRow) subRow.classList.add('hidden');
                return;
            }

            // 2. Cek Excel Filter per kolom
            var d = (r.getAttribute('data-filter-date') || '').trim();
            var j = (r.getAttribute('data-filter-jalur') || '').trim();
            var it = (r.getAttribute('data-filter-item') || '').trim();
            var ins = (r.getAttribute('data-filter-inspector') || '').trim();
            var st = (r.getAttribute('data-filter-status') || '').trim();
            var ap = (r.getAttribute('data-filter-approval') || '').trim();

            var match = true;
            if (ExcelFilterState.filters.date.size > 0 && !ExcelFilterState.filters.date.has(d)) match = false;
            if (match && ExcelFilterState.filters.jalur.size > 0 && !ExcelFilterState.filters.jalur.has(j)) match = false;
            if (match && ExcelFilterState.filters.item.size > 0 && !ExcelFilterState.filters.item.has(it)) match = false;
            if (match && ExcelFilterState.filters.inspector.size > 0 && !ExcelFilterState.filters.inspector.has(ins)) match = false;
            if (match && ExcelFilterState.filters.status.size > 0 && !ExcelFilterState.filters.status.has(st)) match = false;
            if (match && ExcelFilterState.filters.approval.size > 0 && !ExcelFilterState.filters.approval.has(ap)) match = false;

            if (match) {
                r.style.display = '';
                visibleCount++;
            } else {
                r.style.display = 'none';
                if (subRow) subRow.classList.add('hidden');
            }
        });

        // Update indikator bar filter
        var indicatorBar = document.getElementById('excel-filter-active-bar');
        var summaryText = document.getElementById('excel-filter-summary-text');
        var badgeCount = document.getElementById('badgeTotalSessionCount');

        if (activeFilterCols.length > 0) {
            if (indicatorBar) indicatorBar.style.display = 'flex';
            if (summaryText) {
                summaryText.innerText = 'Menyaring ' + visibleCount + ' dari ' + totalRows + ' sesi (' + activeFilterCols.join(', ') + ')';
            }
            if (badgeCount) {
                badgeCount.innerText = visibleCount + ' Sesi Tersaring (' + totalRows + ' Total)';
            }
        } else {
            if (indicatorBar) indicatorBar.style.display = 'none';
            if (badgeCount) {
                if (!allowApproved) {
                    var pendingCount = document.querySelectorAll('.row-pending-session').length;
                    badgeCount.innerText = pendingCount + ' Sesi Menunggu ACC (' + totalRows + ' Total)';
                } else {
                    badgeCount.innerText = 'Total ' + totalRows + ' Sesi';
                }
            }
        }

        // Sinkronisasi status checkbox dan tombol approve terpilih saat tabel disaring
        if (typeof onSessionCheckChange === 'function') {
            onSessionCheckChange();
        }
    }

    // Inisialisasi Excel Filters saat halaman selesai dimuat
    document.addEventListener('DOMContentLoaded', function() {
        initExcelFilters();

        // Integrasikan dengan toggleShowApproved
        var toggle = document.getElementById('toggleShowApproved');
        if (toggle) {
            toggle.addEventListener('change', function() {
                executeDailyReportFiltering();
            });
        }
    });

    // Event listener: Klik di luar untuk menutup dropdown
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.excel-filter-dropdown') && !e.target.closest('.th-filter-btn')) {
            closeAllExcelFilters();
        }
    });

    // Event listener: Tombol Escape untuk menutup dropdown
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeAllExcelFilters();
        }
    });
    </script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
