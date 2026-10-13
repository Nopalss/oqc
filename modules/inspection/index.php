<?php
/**
 * Jadwal & Riwayat Scan Kanban (Kanban Schedule & Workboard)
 * Modul Outgoing Quality Control (OQC)
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

require_menu_access('inspection');

$pdo = getDB();

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

// ── Helper: Render Lot Status Badge (Matching Daily Report Detail) ─────────
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

        $currLotId = (int)($lot['lot_id'] ?? $lot['id'] ?? 0);
        $currSessId = (int)($lot['inspection_session_id'] ?? 0);

        // Fallback: Cek apakah lot (part + lot_no + ref_no) ini memiliki catatan NG/Rejected terdahulu di database
        if (!$isReinspect && !empty($lot['lot_number']) && $currLotId > 0 && $currSessId > 0) {
            static $prevRejectedCache = [];
            $cacheKey = $currSessId . '_' . $currLotId;
            if (!isset($prevRejectedCache[$cacheKey])) {
                try {
                    $pdoBadge = getDB();
                    if ($pdoBadge) {
                        $refStr = (string)($lot['ref_number'] ?? '');
                        $currTime = (string)($lot['created_at'] ?? $lot['scanned_at'] ?? date('Y-m-d H:i:s'));
                        $chkStmt = $pdoBadge->prepare("
                            SELECT 1 
                            FROM inspection_session_lots prev_isl
                            JOIN inspection_sessions prev_s ON prev_s.id = prev_isl.inspection_session_id
                            JOIN inspection_sessions curr_s ON curr_s.id = :curr_sid
                            WHERE prev_isl.lot_number = :lot_num
                              AND (
                                  (:ref_num1 != '' AND prev_isl.ref_number = :ref_num2)
                                  OR (:ref_num3 = '' AND (prev_isl.ref_number IS NULL OR prev_isl.ref_number = ''))
                              )
                              AND (
                                  (prev_s.part_id IS NOT NULL AND curr_s.part_id IS NOT NULL AND prev_s.part_id = curr_s.part_id)
                                  OR (prev_s.kanban_item_id IS NOT NULL AND curr_s.kanban_item_id IS NOT NULL AND prev_s.kanban_item_id = curr_s.kanban_item_id)
                                  OR (prev_s.part_id IS NULL AND prev_s.kanban_item_id IS NULL)
                              )
                              AND prev_isl.id != :curr_lid
                              AND prev_isl.created_at < :curr_created
                              AND (prev_isl.lot_result = 'rejected' OR prev_isl.ng_count > 0 OR prev_isl.lot_status IN ('ng_found', 'ng_quarantine', 'replaced'))
                            LIMIT 1
                        ");
                        $chkStmt->execute([
                            ':curr_sid'     => $currSessId,
                            ':lot_num'      => (string)$lot['lot_number'],
                            ':ref_num1'     => $refStr,
                            ':ref_num2'     => $refStr,
                            ':ref_num3'     => $refStr,
                            ':curr_lid'     => $currLotId,
                            ':curr_created' => $currTime
                        ]);
                        $prevRejectedCache[$cacheKey] = (bool)$chkStmt->fetchColumn();
                    } else {
                        $prevRejectedCache[$cacheKey] = false;
                    }
                } catch (Exception $e) {
                    $prevRejectedCache[$cacheKey] = false;
                }
            }
            if (!empty($prevRejectedCache[$cacheKey])) {
                $isReinspect = true;
            }
        }

        $isKanban = (($lot['inspection_type'] ?? '') === 'kanban');
        $isSs = !$isKanban && ((($lot['inspection_type'] ?? '') === 'safety_stock') || !empty($lot['is_safety_stock']) || $res === 'skipped');

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
            return '<span style="background-color: #f59e0b; color: #ffffff; font-weight: 800; font-size: 10px; padding: 2.5px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px; text-transform: uppercase; white-space: nowrap;">SEDANG BERJALAN</span>';
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
        return '<span style="background-color: #f59e0b; color: #ffffff; font-weight: 800; font-size: 10px; padding: 2.5px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px; text-transform: uppercase; white-space: nowrap;">SEDANG BERJALAN</span>';
    }
}

if (!function_exists('getLotRowClass')) {
    function getLotRowClass($lot) {
        $res = strtolower($lot['lot_result'] ?? '');
        $st  = strtolower($lot['lot_status'] ?? '');
        $remUpper = strtoupper(($lot['remarks'] ?? '') . ' ' . ($lot['action_notes'] ?? '') . ' ' . ($lot['reinspection_notes'] ?? '') . ' ' . ($lot['status_label'] ?? '') . ' ' . ($lot['status_badge'] ?? ''));

        $isInitial = !empty($lot['is_initial_attempt']) 
            || (($lot['attempt_type'] ?? '') === 'initial')
            || (strpos($remUpper, 'AWAL') !== false && strpos($remUpper, 'RE-INSPEKSI') === false);

        $hasNg = ((int)($lot['ng_count'] ?? 0) > 0 || (int)($lot['total_ng'] ?? 0) > 0 || !empty($lot['defects']));
        
        // 1. REJECTED: Any rejected result or active defect (unless explicitly passed/reinspected without active NG)
        if ($isInitial || $res === 'rejected' || $st === 'rejected' || $st === 'replaced' || ($lot['status_code'] ?? '') === 'rejected' || ($hasNg && $res !== 'passed' && $st !== 'reinspected')) {
            return 'lot-row-rejected';
        }

        $isReinspect = !empty($lot['is_reinspected_attempt']) 
            || !empty($lot['is_reinspected_lot']) 
            || !empty($lot['is_reinspection']) 
            || !empty($lot['has_reinspection_log'])
            || !empty($lot['has_subst_log'])
            || !empty($lot['has_sorted_ng'])
            || !empty($lot['has_prev_rejected'])
            || (($lot['attempt_type'] ?? '') === 'reinspection') 
            || (strpos($remUpper, 'RE-INSPEKSI') !== false);

        // 2. PASSED
        if ($res === 'passed' || ($lot['status_code'] ?? '') === 'passed' || $st === 'passed' || $st === 'completed' || ($isReinspect && $st === 'reinspected')) {
            return 'lot-row-passed';
        }

        // 3. SEDANG BERJALAN
        return 'lot-row-in_progress';
    }
}

// -------------------------------------------------------------------------
$currentUser = current_user();
$currentUserId = (int)$currentUser['id'];
$isAdminOrSupervisor = in_array($currentUser['role'] ?? '', ['supervisor', 'admin', 'superadmin', 'chief', 'manager'], true);
$inspectorFilterId = (isset($_GET['inspector_id']) && (int)$_GET['inspector_id'] > 0 && $isAdminOrSupervisor) ? (int)$_GET['inspector_id'] : 0;

$allowedTabs = ['kanban', 'safety_stock', 'my_inspections', 'my_lots', 'lot_details'];
$activeTab = (isset($_GET['tab']) && in_array($_GET['tab'], $allowedTabs, true)) ? $_GET['tab'] : 'kanban';
if ($activeTab === 'lot_details' || $activeTab === 'my_inspections') {
    $activeTab = 'my_lots';
}

// Filter & Parameters
// -------------------------------------------------------------------------
// Helper to parse full QR code scan strings (Z1<part_code>|Z2<lot>|Z3<qty>|Z4<line>|Z5<ref_no>)
if (!function_exists('parseQrScanString')) {
    function parseQrScanString($raw) {
        $res = [
            'Z1' => '', // Part Code
            'Z2' => '', // Lot Number
            'Z3' => '', // Qty / Cavity
            'Z4' => '', // Line
            'Z5' => '', // Ref Number / DID Number
            'is_qr' => false
        ];
        if (empty($raw)) return $res;
        $raw = trim($raw);
        if (strpos($raw, '|') !== false || strpos($raw, 'Z1') !== false || strpos($raw, 'Z2') !== false || strpos($raw, 'Z5') !== false) {
            $res['is_qr'] = true;
            $parts = explode('|', $raw);
            foreach ($parts as $p) {
                $p = trim($p);
                if (strpos($p, 'Z1') === 0) $res['Z1'] = trim(substr($p, 2));
                elseif (strpos($p, 'Z2') === 0) $res['Z2'] = trim(substr($p, 2));
                elseif (strpos($p, 'Z3') === 0) $res['Z3'] = trim(substr($p, 2));
                elseif (strpos($p, 'Z4') === 0) $res['Z4'] = trim(substr($p, 2));
                elseif (strpos($p, 'Z5') === 0) $res['Z5'] = trim(substr($p, 2));
            }
        }
        return $res;
    }
}

$period = isset($_GET['period']) ? trim(sanitize($_GET['period'])) : 'today';
$startDate = isset($_GET['start_date']) ? trim(sanitize($_GET['start_date'])) : '';
$endDate = isset($_GET['end_date']) ? trim(sanitize($_GET['end_date'])) : '';
$rawSearch = isset($_GET['search']) ? trim(sanitize($_GET['search'])) : '';
$qrScan = parseQrScanString($rawSearch);

// Auto-Redirect Handler for Full QR Scan on Kanban & Safety Stock Tabs
if ($qrScan['is_qr'] && $pdo) {
    if ($activeTab === 'kanban' && !empty($qrScan['Z1'])) {
        try {
            // Find FIFO active Kanban Item for this part code (uninspected/partial/in_progress)
            $stmtFindKb = $pdo->prepare("
                SELECT k.id 
                FROM kanban_items k
                WHERE k.item_code = :pcode
                  AND (k.plan_type IS NULL OR k.plan_type = 'kanban')
                  AND k.status NOT IN ('completed')
                ORDER BY 
                    CASE WHEN k.status IN ('in_progress', 'partial') THEN 0 ELSE 1 END ASC,
                    COALESCE(k.eta, k.req_date, k.created_at) ASC,
                    k.id ASC
                LIMIT 1
            ");
            $stmtFindKb->execute([':pcode' => $qrScan['Z1']]);
            $foundKbId = $stmtFindKb->fetchColumn();

            if ($foundKbId) {
                $targetUrl = base_url("modules/inspection/session.php?kanban_item_id={$foundKbId}&scanned_lot=" . urlencode($qrScan['Z2']) . "&scanned_ref=" . urlencode($qrScan['Z5']));
                redirect($targetUrl);
                exit;
            } else {
                // Fallback to Safety Stock if not found in active Kanban schedule
                $targetUrl = base_url("modules/inspection/session.php?scan_new=1&type=safety_stock&plan_type=safety_stock&part_code=" . urlencode($qrScan['Z1']) . "&lot_number=" . urlencode($qrScan['Z2']) . "&ref_number=" . urlencode($qrScan['Z5']));
                redirect($targetUrl);
                exit;
            }
        } catch (Exception $eKbRedirect) {}
    } elseif ($activeTab === 'safety_stock' && (!empty($qrScan['Z1']) || !empty($qrScan['Z2']))) {
        $targetUrl = base_url("modules/inspection/session.php?scan_new=1&type=safety_stock&plan_type=safety_stock&part_code=" . urlencode($qrScan['Z1']) . "&lot_number=" . urlencode($qrScan['Z2']) . "&ref_number=" . urlencode($qrScan['Z5']));
        redirect($targetUrl);
        exit;
    }
}

// Keep full search string intact (including full QR scan payload Z1..Z5)
$search = $rawSearch;

$statusFilter = isset($_GET['status']) ? trim(sanitize($_GET['status'])) : 'all';
$typeFilter = isset($_GET['inspection_type']) ? trim(sanitize($_GET['inspection_type'])) : 'all';
if (!in_array($typeFilter, ['all', 'kanban', 'safety_stock'], true)) {
    $typeFilter = 'all';
}
$sort = (isset($_GET['sort']) && $_GET['sort'] === 'asc') ? 'ASC' : 'DESC';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = isset($_GET['limit']) ? max(5, min(200, (int)$_GET['limit'])) : 25;
$offset = ($page - 1) * $limit;

if (!empty($startDate) || !empty($endDate)) {
    $period = 'custom';
}

$kanbans = [];
$ssKanbans = [];
$ssSessions = [];
$mySessions = [];
$myLots = [];
$totalItems = 0;
$totalPages = 1;
$kanbanTabCount = 0;
$safetyStockTabCount = 0;
$myInspectionsTabCount = 0;
$myLotsTabCount = 0;
$substitutionsBySession = [];
$ngRecordsByLot = [];
$defectsByLot = [];

// Stats default
$stats = [
    'total_kanban'         => 0,
    'uninspected_count'    => 0,
    'in_progress_count'    => 0,
    'completed_count'      => 0,
    'rejected_count'       => 0,
    'in_progress_sessions' => 0,
    // Safety Stock specific stats
    'total_count'          => 0,
    'passed_count'         => 0,
    'total_lots'           => 0,
    'in_progress_lots'     => 0,
    'passed_lots'          => 0,
    'rejected_lots'        => 0,
    'total_qty_pcs'        => 0,
    'in_progress_qty_pcs'  => 0,
    'passed_qty_pcs'       => 0,
    'rejected_qty_pcs'     => 0,
    // My Lots specific stats
    'total_ng_pcs'         => 0,
    'total_sample_pcs'     => 0
];

$sessionsByKanban = [];
$lotsBySession = [];
$activeSessionByKanban = [];

if ($pdo) {
    try {
        // =====================================================================
        // COMMON DATE PERIOD CRITERIA BUILDERS
        // =====================================================================
        $todayStart = date('Y-m-d 00:00:00');
        $todayEnd   = date('Y-m-d 23:59:59');
        $dayOfWeek  = (int)date('w'); // 0 = Sunday, 1 = Monday, ..., 6 = Saturday
        $wStart     = date('Y-m-d 00:00:00', strtotime("-{$dayOfWeek} days"));
        $wEnd       = date('Y-m-d 23:59:59', strtotime("+" . (6 - $dayOfWeek) . " days"));
        $mStart     = date('Y-m-01 00:00:00');
        $mEnd       = date('Y-m-t 23:59:59');

        // Session-based badge count cache (TTL 30s) to avoid 4 heavy aggregate queries on every single request
        $badgeCacheTtl = 30;
        $badgeCacheKey = 'badges_' . $currentUserId . '_' . $period . '_' . md5($startDate . '|' . $endDate);
        $cachedBadges = $_SESSION['oqc_badge_cache'][$badgeCacheKey] ?? null;
        $isBadgeCacheValid = ($cachedBadges && isset($cachedBadges['time']) && (time() - $cachedBadges['time'] < $badgeCacheTtl));

        // =====================================================================
        // 1. KANBAN BASE CONDITIONS & PARAMETERS
        // =====================================================================
        $kbBaseWhere = ["1=1"];
        $kbBaseParams = [];
        $kbBaseWhere[] = "(k.plan_type IS NULL OR k.plan_type = 'kanban')";
        $kbBaseWhere[] = "(k.check_type IS NULL OR k.check_type != 'Safety Stock')";
        $kbBaseWhere[] = "(k.kanban_no IS NULL OR k.kanban_no NOT LIKE 'SS-%')";
        $kbBaseWhere[] = "(k.str_loc IS NULL OR k.str_loc NOT IN ('WH-SS-OVERFLOW', 'WH-SS-SPLIT'))";

        if ($period === 'today') {
            $kbBaseWhere[] = "(
                (COALESCE(k.eta, k.req_date) BETWEEN :kb_t_start1 AND :kb_t_end1)
                OR (k.created_at BETWEEN :kb_t_start2 AND :kb_t_end2)
                OR EXISTS (
                    SELECT 1 FROM inspection_sessions s_today 
                    WHERE s_today.kanban_item_id = k.id 
                      AND s_today.started_at BETWEEN :kb_t_start3 AND :kb_t_end3
                )
                OR (
                    k.status NOT IN ('completed', 'rejected')
                    AND COALESCE(k.eta, k.req_date, k.created_at) < :kb_t_start4
                )
            )";
            $kbBaseParams[':kb_t_start1'] = $todayStart;
            $kbBaseParams[':kb_t_end1']   = $todayEnd;
            $kbBaseParams[':kb_t_start2'] = $todayStart;
            $kbBaseParams[':kb_t_end2']   = $todayEnd;
            $kbBaseParams[':kb_t_start3'] = $todayStart;
            $kbBaseParams[':kb_t_end3']   = $todayEnd;
            $kbBaseParams[':kb_t_start4'] = $todayStart;
        } elseif ($period === 'this_week') {
            $kbBaseWhere[] = "(
                (COALESCE(k.eta, k.req_date) BETWEEN :kb_w_start1 AND :kb_w_end1)
                OR (k.created_at BETWEEN :kb_w_start2 AND :kb_w_end2)
                OR EXISTS (
                    SELECT 1 FROM inspection_sessions s_week 
                    WHERE s_week.kanban_item_id = k.id 
                      AND s_week.started_at BETWEEN :kb_w_start3 AND :kb_w_end3
                )
                OR (
                    k.status NOT IN ('completed', 'rejected')
                    AND COALESCE(k.eta, k.req_date, k.created_at) < :kb_w_start4
                )
            )";
            $kbBaseParams[':kb_w_start1'] = $wStart;
            $kbBaseParams[':kb_w_end1']   = $wEnd;
            $kbBaseParams[':kb_w_start2'] = $wStart;
            $kbBaseParams[':kb_w_end2']   = $wEnd;
            $kbBaseParams[':kb_w_start3'] = $wStart;
            $kbBaseParams[':kb_w_end3']   = $wEnd;
            $kbBaseParams[':kb_w_start4'] = $wStart;
        } elseif ($period === 'this_month') {
            $kbBaseWhere[] = "(
                (COALESCE(k.eta, k.req_date) BETWEEN :kb_m_start1 AND :kb_m_end1)
                OR (k.created_at BETWEEN :kb_m_start2 AND :kb_m_end2)
                OR EXISTS (
                    SELECT 1 FROM inspection_sessions s_month 
                    WHERE s_month.kanban_item_id = k.id 
                      AND s_month.started_at BETWEEN :kb_m_start3 AND :kb_m_end3
                )
                OR (
                    k.status NOT IN ('completed', 'rejected')
                    AND COALESCE(k.eta, k.req_date, k.created_at) < :kb_m_start4
                )
            )";
            $kbBaseParams[':kb_m_start1'] = $mStart;
            $kbBaseParams[':kb_m_end1']   = $mEnd;
            $kbBaseParams[':kb_m_start2'] = $mStart;
            $kbBaseParams[':kb_m_end2']   = $mEnd;
            $kbBaseParams[':kb_m_start3'] = $mStart;
            $kbBaseParams[':kb_m_end3']   = $mEnd;
            $kbBaseParams[':kb_m_start4'] = $mStart;
        } elseif ($period === 'custom') {
            if (!empty($startDate) && !empty($endDate)) {
                $kbBaseWhere[] = "(
                    (COALESCE(k.eta, k.req_date) BETWEEN :kb_c_start1 AND :kb_c_end1)
                    OR (k.created_at BETWEEN :kb_c_start2 AND :kb_c_end2)
                    OR EXISTS (
                        SELECT 1 FROM inspection_sessions s_custom 
                        WHERE s_custom.kanban_item_id = k.id 
                          AND s_custom.started_at BETWEEN :kb_c_start3 AND :kb_c_end3
                    )
                )";
                $cStart = $startDate . ' 00:00:00';
                $cEnd   = $endDate . ' 23:59:59';
                $kbBaseParams[':kb_c_start1'] = $cStart;
                $kbBaseParams[':kb_c_end1']   = $cEnd;
                $kbBaseParams[':kb_c_start2'] = $cStart;
                $kbBaseParams[':kb_c_end2']   = $cEnd;
                $kbBaseParams[':kb_c_start3'] = $cStart;
                $kbBaseParams[':kb_c_end3']   = $cEnd;
            } elseif (!empty($startDate)) {
                $kbBaseWhere[] = "(
                    (COALESCE(k.eta, k.req_date) >= :kb_c_start1)
                    OR (k.created_at >= :kb_c_start2)
                    OR EXISTS (
                        SELECT 1 FROM inspection_sessions s_custom 
                        WHERE s_custom.kanban_item_id = k.id 
                          AND s_custom.started_at >= :kb_c_start3
                    )
                )";
                $cStart = $startDate . ' 00:00:00';
                $kbBaseParams[':kb_c_start1'] = $cStart;
                $kbBaseParams[':kb_c_start2'] = $cStart;
                $kbBaseParams[':kb_c_start3'] = $cStart;
            } elseif (!empty($endDate)) {
                $kbBaseWhere[] = "(
                    (COALESCE(k.eta, k.req_date) <= :kb_c_end1)
                    OR (k.created_at <= :kb_c_end2)
                    OR EXISTS (
                        SELECT 1 FROM inspection_sessions s_custom 
                        WHERE s_custom.kanban_item_id = k.id 
                          AND s_custom.started_at <= :kb_c_end3
                    )
                )";
                $cEnd = $endDate . ' 23:59:59';
                $kbBaseParams[':kb_c_end1'] = $cEnd;
                $kbBaseParams[':kb_c_end2'] = $cEnd;
                $kbBaseParams[':kb_c_end3'] = $cEnd;
            }
        }

        // =====================================================================
        // 2. SAFETY STOCK BASE CONDITIONS & PARAMETERS (MASTER KANBAN_ITEMS)
        // =====================================================================
        $ssBaseWhere = ["1=1"];
        $ssBaseParams = [];
        $ssBaseWhere[] = "(k.plan_type = 'safety_stock' OR k.check_type = 'Safety Stock' OR k.kanban_no LIKE 'SS-%' OR k.str_loc IN ('WH-SS-OVERFLOW', 'WH-SS-SPLIT') OR EXISTS (SELECT 1 FROM inspection_sessions s_ss WHERE s_ss.kanban_item_id = k.id AND s_ss.inspection_type = 'safety_stock'))";

        if ($period === 'today') {
            $ssBaseWhere[] = "(
                EXISTS (
                    SELECT 1 FROM inspection_sessions s_today 
                    WHERE s_today.kanban_item_id = k.id 
                      AND (s_today.started_at BETWEEN :ss_t_start1 AND :ss_t_end1 OR s_today.status = 'in_progress')
                )
                OR (
                    k.created_at BETWEEN :ss_t_start2 AND :ss_t_end2
                )
            )";
            $ssBaseParams[':ss_t_start1'] = $todayStart;
            $ssBaseParams[':ss_t_end1']   = $todayEnd;
            $ssBaseParams[':ss_t_start2'] = $todayStart;
            $ssBaseParams[':ss_t_end2']   = $todayEnd;
        } elseif ($period === 'this_week') {
            $ssBaseWhere[] = "(
                EXISTS (
                    SELECT 1 FROM inspection_sessions s_week 
                    WHERE s_week.kanban_item_id = k.id 
                      AND (s_week.started_at BETWEEN :ss_w_start1 AND :ss_w_end1 OR s_week.status = 'in_progress')
                )
                OR (
                    k.created_at BETWEEN :ss_w_start2 AND :ss_w_end2
                )
            )";
            $ssBaseParams[':ss_w_start1'] = $wStart;
            $ssBaseParams[':ss_w_end1']   = $wEnd;
            $ssBaseParams[':ss_w_start2'] = $wStart;
            $ssBaseParams[':ss_w_end2']   = $wEnd;
        } elseif ($period === 'this_month') {
            $ssBaseWhere[] = "(
                EXISTS (
                    SELECT 1 FROM inspection_sessions s_month 
                    WHERE s_month.kanban_item_id = k.id 
                      AND (s_month.started_at BETWEEN :ss_m_start1 AND :ss_m_end1 OR s_month.status = 'in_progress')
                )
                OR (
                    k.created_at BETWEEN :ss_m_start2 AND :ss_m_end2
                )
            )";
            $ssBaseParams[':ss_m_start1'] = $mStart;
            $ssBaseParams[':ss_m_end1']   = $mEnd;
            $ssBaseParams[':ss_m_start2'] = $mStart;
            $ssBaseParams[':ss_m_end2']   = $mEnd;
        } elseif ($period === 'custom') {
            if (!empty($startDate) && !empty($endDate)) {
                $ssBaseWhere[] = "(
                    EXISTS (
                        SELECT 1 FROM inspection_sessions s_custom 
                        WHERE s_custom.kanban_item_id = k.id 
                          AND (s_custom.started_at BETWEEN :ss_c_start1 AND :ss_c_end1 OR s_custom.status = 'in_progress')
                    )
                    OR (
                        k.created_at BETWEEN :ss_c_start2 AND :ss_c_end2
                    )
                )";
                $cStart = $startDate . ' 00:00:00';
                $cEnd   = $endDate . ' 23:59:59';
                $ssBaseParams[':ss_c_start1'] = $cStart;
                $ssBaseParams[':ss_c_end1']   = $cEnd;
                $ssBaseParams[':ss_c_start2'] = $cStart;
                $ssBaseParams[':ss_c_end2']   = $cEnd;
            } elseif (!empty($startDate)) {
                $ssBaseWhere[] = "(
                    EXISTS (
                        SELECT 1 FROM inspection_sessions s_custom 
                        WHERE s_custom.kanban_item_id = k.id 
                          AND (s_custom.started_at >= :ss_c_start1 OR s_custom.status = 'in_progress')
                    )
                    OR (
                        k.created_at >= :ss_c_start2
                    )
                )";
                $cStart = $startDate . ' 00:00:00';
                $ssBaseParams[':ss_c_start1'] = $cStart;
                $ssBaseParams[':ss_c_start2'] = $cStart;
            } elseif (!empty($endDate)) {
                $ssBaseWhere[] = "(
                    EXISTS (
                        SELECT 1 FROM inspection_sessions s_custom 
                        WHERE s_custom.kanban_item_id = k.id 
                          AND (s_custom.started_at <= :ss_c_end1 OR s_custom.status = 'in_progress')
                    )
                    OR (
                        k.created_at <= :ss_c_end2
                    )
                )";
                $cEnd = $endDate . ' 23:59:59';
                $ssBaseParams[':ss_c_end1'] = $cEnd;
                $ssBaseParams[':ss_c_end2'] = $cEnd;
            }
        }

        // =====================================================================
        // 3. RIWAYAT SAYA (INSPEKSI SAYA) BASE CONDITIONS & PARAMETERS
        // =====================================================================
        $myBaseWhere = ["s.inspector_id = :my_uid"];
        $myBaseParams = [':my_uid' => ($inspectorFilterId > 0 ? $inspectorFilterId : $currentUserId)];

        if ($period === 'today') {
            $myBaseWhere[] = "s.started_at BETWEEN :my_t_start AND :my_t_end";
            $myBaseParams[':my_t_start'] = $todayStart;
            $myBaseParams[':my_t_end']   = $todayEnd;
        } elseif ($period === 'this_week') {
            $myBaseWhere[] = "s.started_at BETWEEN :my_w_start AND :my_w_end";
            $myBaseParams[':my_w_start'] = $wStart;
            $myBaseParams[':my_w_end']   = $wEnd;
        } elseif ($period === 'this_month') {
            $myBaseWhere[] = "s.started_at BETWEEN :my_m_start AND :my_m_end";
            $myBaseParams[':my_m_start'] = $mStart;
            $myBaseParams[':my_m_end']   = $mEnd;
        } elseif ($period === 'custom') {
            if (!empty($startDate) && !empty($endDate)) {
                $myBaseWhere[] = "s.started_at BETWEEN :my_c_start AND :my_c_end";
                $myBaseParams[':my_c_start'] = $startDate . ' 00:00:00';
                $myBaseParams[':my_c_end']   = $endDate . ' 23:59:59';
            } elseif (!empty($startDate)) {
                $myBaseWhere[] = "s.started_at >= :my_c_start";
                $myBaseParams[':my_c_start'] = $startDate . ' 00:00:00';
            } elseif (!empty($endDate)) {
                $myBaseWhere[] = "s.started_at <= :my_c_end";
                $myBaseParams[':my_c_end']   = $endDate . ' 23:59:59';
            }
        }

        // =====================================================================
        // 4. RINCIAN PER LOT SAYA BASE CONDITIONS & PARAMETERS (ALIGN DENGAN EXPORT_EXCEL.PHP)
        // =====================================================================
        $myLotsBaseWhere = ["s.inspector_id = :mylot_uid"];
        $myLotsBaseParams = [':mylot_uid' => ($inspectorFilterId > 0 ? $inspectorFilterId : $currentUserId)];
        
        // Exclude virtual overflow and direct-pass lots
        $myLotsBaseWhere[] = "(isl.remarks IS NULL OR (
            isl.remarks NOT LIKE '%Alokasi Safety Stock%' 
            AND isl.remarks NOT LIKE '%Sisa Split Safety Stock%' 
            AND isl.remarks NOT LIKE '%Sisa Kelebihan Kanban%' 
            AND isl.remarks NOT LIKE '%Direct Close%'
        ))";
        $myLotsBaseWhere[] = "(isl.ref_number IS NULL OR (isl.ref_number != 'DIRECT-PASS' AND isl.ref_number NOT LIKE '%DIRECT-PASS%'))";
        $myLotsBaseWhere[] = "(s.approval_notes IS NULL OR s.approval_notes NOT LIKE '%Direct Close%')";
        $myLotsBaseWhere[] = "NOT EXISTS (
            SELECT 1 FROM inspection_session_lots isl_sisa 
            WHERE isl_sisa.inspection_session_id = s.id 
              AND (isl_sisa.remarks LIKE '%Sisa Kelebihan Kanban%' OR isl_sisa.remarks LIKE '%Sisa Split Safety Stock%')
        )";

        if ($period === 'today') {
            $myLotsBaseWhere[] = "s.started_at BETWEEN :mylot_t_start AND :mylot_t_end";
            $myLotsBaseParams[':mylot_t_start'] = $todayStart;
            $myLotsBaseParams[':mylot_t_end']   = $todayEnd;
        } elseif ($period === 'this_week') {
            $myLotsBaseWhere[] = "s.started_at BETWEEN :mylot_w_start AND :mylot_w_end";
            $myLotsBaseParams[':mylot_w_start'] = $wStart;
            $myLotsBaseParams[':mylot_w_end']   = $wEnd;
        } elseif ($period === 'this_month') {
            $myLotsBaseWhere[] = "s.started_at BETWEEN :mylot_m_start AND :mylot_m_end";
            $myLotsBaseParams[':mylot_m_start'] = $mStart;
            $myLotsBaseParams[':mylot_m_end']   = $mEnd;
        } elseif ($period === 'custom') {
            if (!empty($startDate) && !empty($endDate)) {
                $myLotsBaseWhere[] = "s.started_at BETWEEN :mylot_c_start AND :mylot_c_end";
                $myLotsBaseParams[':mylot_c_start'] = $startDate . ' 00:00:00';
                $myLotsBaseParams[':mylot_c_end']   = $endDate . ' 23:59:59';
            } elseif (!empty($startDate)) {
                $myLotsBaseWhere[] = "s.started_at >= :mylot_c_start";
                $myLotsBaseParams[':mylot_c_start'] = $startDate . ' 00:00:00';
            } elseif (!empty($endDate)) {
                $myLotsBaseWhere[] = "s.started_at <= :mylot_c_end";
                $myLotsBaseParams[':mylot_c_end']   = $endDate . ' 23:59:59';
            }
        }

        // =====================================================================
        // TAB 1: KANBAN DELIVERY WORKFLOW
        // =====================================================================
        if ($activeTab === 'kanban') {

            // Search text filter
            if ($search !== '') {
                $kbBaseWhere[] = "(k.kanban_no LIKE :kb_s1 OR k.item_code LIKE :kb_s2 OR k.item_description LIKE :kb_s3 OR k.customer LIKE :kb_s4 OR mp.part_name LIKE :kb_s5)";
                $kbBaseParams[':kb_s1'] = '%' . $search . '%';
                $kbBaseParams[':kb_s2'] = '%' . $search . '%';
                $kbBaseParams[':kb_s3'] = '%' . $search . '%';
                $kbBaseParams[':kb_s4'] = '%' . $search . '%';
                $kbBaseParams[':kb_s5'] = '%' . $search . '%';
            }

            // Calculate Kanban KPI Statistics
            $kbStatsWhereClause = implode(" AND ", $kbBaseWhere);
            $statsSql = "
                SELECT 
                    COUNT(DISTINCT k.id) as total_kanban,
                    SUM(CASE WHEN k.status = 'uninspected' THEN 1 ELSE 0 END) as uninspected_count,
                    SUM(CASE WHEN k.status IN ('in_progress', 'partial') THEN 1 ELSE 0 END) as in_progress_count,
                    SUM(CASE WHEN k.status = 'completed' THEN 1 ELSE 0 END) as completed_count,
                    SUM(CASE WHEN k.status = 'rejected' THEN 1 ELSE 0 END) as rejected_count
                FROM kanban_items k
                LEFT JOIN master_parts mp ON mp.part_code = k.item_code
                WHERE {$kbStatsWhereClause}
            ";
            $stmtStats = $pdo->prepare($statsSql);
            $stmtStats->execute($kbBaseParams);
            $statRow = $stmtStats->fetch(PDO::FETCH_ASSOC);
            if ($statRow) {
                $stats['total_kanban']      = (int)($statRow['total_kanban'] ?? 0);
                $stats['uninspected_count'] = (int)($statRow['uninspected_count'] ?? 0);
                $stats['in_progress_count'] = (int)($statRow['in_progress_count'] ?? 0);
                $stats['completed_count']   = (int)($statRow['completed_count'] ?? 0);
                $stats['rejected_count']    = (int)($statRow['rejected_count'] ?? 0);
            }
            $kanbanTabCount = $stats['total_kanban'];

            // Check overall in-progress sessions for shift handover alert
            $stmtInProgSess = $pdo->query("SELECT COUNT(*) FROM inspection_sessions WHERE status = 'in_progress'");
            $stats['in_progress_sessions'] = (int)$stmtInProgSess->fetchColumn();

            // Badge counts (cached for 30s in session to avoid 3 heavy aggregate queries on every page request)
            if ($isBadgeCacheValid) {
                $safetyStockTabCount   = (int)($cachedBadges['safety_stock'] ?? 0);
                $myInspectionsTabCount = (int)($cachedBadges['my_inspections'] ?? 0);
                $myLotsTabCount        = (int)($cachedBadges['my_lots'] ?? 0);
                $_SESSION['oqc_badge_cache'][$badgeCacheKey]['kanban'] = $kanbanTabCount;
            } else {
                // Quick count for Safety Stock Tab Badge
                $ssBadgeWhereClause = implode(" AND ", $ssBaseWhere);
                $stmtSSBadge = $pdo->prepare("SELECT COUNT(DISTINCT k.id) FROM kanban_items k LEFT JOIN master_parts mp ON mp.part_code = k.item_code WHERE {$ssBadgeWhereClause}");
                $stmtSSBadge->execute($ssBaseParams);
                $safetyStockTabCount = (int)$stmtSSBadge->fetchColumn();

                // Quick count for Riwayat Saya Tab Badge
                $myBadgeWhereClause = implode(" AND ", $myBaseWhere);
                $stmtMyBadge = $pdo->prepare("SELECT COUNT(DISTINCT s.id) FROM inspection_sessions s WHERE {$myBadgeWhereClause}");
                $stmtMyBadge->execute($myBaseParams);
                $myInspectionsTabCount = (int)$stmtMyBadge->fetchColumn();

                // Quick count for Rincian Per Lot Tab Badge
                $myLotsBadgeWhereClause = implode(" AND ", $myLotsBaseWhere);
                $stmtLotsBadge = $pdo->prepare("SELECT COUNT(DISTINCT isl.id) FROM inspection_session_lots isl JOIN inspection_sessions s ON s.id = isl.inspection_session_id WHERE {$myLotsBadgeWhereClause}");
                $stmtLotsBadge->execute($myLotsBaseParams);
                $myLotsTabCount = (int)$stmtLotsBadge->fetchColumn();

                $_SESSION['oqc_badge_cache'][$badgeCacheKey] = [
                    'time'           => time(),
                    'kanban'         => $kanbanTabCount,
                    'safety_stock'   => $safetyStockTabCount,
                    'my_inspections' => $myInspectionsTabCount,
                    'my_lots'        => $myLotsTabCount
                ];
            }

            // Build Table Filters for Kanban
            $tableWhere = $kbBaseWhere;
            $tableParams = $kbBaseParams;

            if ($statusFilter === 'uninspected') {
                $tableWhere[] = "k.status = 'uninspected' AND NOT EXISTS (SELECT 1 FROM inspection_sessions s_act WHERE s_act.kanban_item_id = k.id AND s_act.status = 'in_progress')";
            } elseif ($statusFilter === 'in_progress') {
                $tableWhere[] = "(k.status IN ('in_progress', 'partial') OR EXISTS (SELECT 1 FROM inspection_sessions s_act WHERE s_act.kanban_item_id = k.id AND s_act.status = 'in_progress'))";
            } elseif ($statusFilter === 'partial') {
                $tableWhere[] = "k.status = 'partial'";
            } elseif ($statusFilter === 'completed') {
                $tableWhere[] = "k.status = 'completed'";
            } elseif ($statusFilter === 'rejected') {
                $tableWhere[] = "(k.status = 'rejected' OR EXISTS (
                    SELECT 1 FROM inspection_sessions s_rej 
                    WHERE s_rej.kanban_item_id = k.id AND s_rej.status = 'rejected'
                ) OR EXISTS (
                    SELECT 1 FROM inspection_sessions s_lot
                    JOIN inspection_session_lots isl_rej ON isl_rej.inspection_session_id = s_lot.id
                    WHERE s_lot.kanban_item_id = k.id AND (isl_rej.lot_result = 'rejected' OR isl_rej.lot_status = 'rejected')
                ))";
            }

            $tableWhereClause = implode(" AND ", $tableWhere);

            // Count Total Records for Pagination
            $countSql = "
                SELECT COUNT(DISTINCT k.id) 
                FROM kanban_items k
                LEFT JOIN master_parts mp ON mp.part_code = k.item_code
                WHERE {$tableWhereClause}
            ";
            $stmtCount = $pdo->prepare($countSql);
            $stmtCount->execute($tableParams);
            $totalItems = (int)$stmtCount->fetchColumn();

            $totalPages = max(1, ceil($totalItems / $limit));
            if ($page > $totalPages) $page = $totalPages;
            $offset = ($page - 1) * $limit;

            // Fetch Kanban Schedule Items
            $sql = "
                SELECT k.*,
                       b.document_number as doc_no,
                       COALESCE(NULLIF(mp.part_name, ''), k.item_description) as display_part_name,
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
                       ), 0) AS total_passed_qty,
                       COUNT(s.id) AS total_sessions_count,
                       SUM(CASE WHEN s.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_sessions_count
                FROM kanban_items k
                LEFT JOIN kanban_batches b ON b.id = k.batch_id
                LEFT JOIN master_parts mp ON mp.part_code = k.item_code
                LEFT JOIN inspection_sessions s ON s.kanban_item_id = k.id
                WHERE {$tableWhereClause}
                GROUP BY k.id, b.document_number, mp.part_name
                ORDER BY 
                    CASE 
                        WHEN k.status = 'completed' OR total_passed_qty >= k.qty 
                        THEN 2 
                        WHEN DATE(COALESCE(k.eta, k.req_date, k.created_at)) < CURDATE()
                        THEN 0 
                        ELSE 1 
                    END ASC,
                    CASE WHEN k.eta IS NULL AND k.req_date IS NULL THEN 1 ELSE 0 END ASC,
                    COALESCE(k.eta, k.req_date, k.created_at) ASC,
                    k.id ASC
                LIMIT {$limit} OFFSET {$offset}
            ";
            $stmtKanban = $pdo->prepare($sql);
            $stmtKanban->execute($tableParams);
            $kanbans = $stmtKanban->fetchAll(PDO::FETCH_ASSOC);

            // Batch fetch session details and lots for the displayed kanbans
            $kanbanIds = array_column($kanbans, 'id');
            if (!empty($kanbanIds)) {
                $inKanbanIds = implode(',', array_map('intval', $kanbanIds));
                $sessSql = "
                    SELECT s.*,
                           COALESCE(u.name, did.pic, 'QC Inspector') AS inspector_name,
                           TIMESTAMPDIFF(MINUTE, s.started_at, COALESCE(s.closed_at, NOW())) AS duration_minutes
                    FROM inspection_sessions s
                    LEFT JOIN users u ON u.id = s.inspector_id
                    LEFT JOIN daily_inspection_data did ON did.id = s.did_id
                    WHERE s.kanban_item_id IN ($inKanbanIds)
                    ORDER BY s.id ASC
                ";
                $stmtSessions = $pdo->query($sessSql);
                $sessionRows = $stmtSessions->fetchAll(PDO::FETCH_ASSOC);

                $sessionIds = [];
                foreach ($sessionRows as $sRow) {
                    $kid = (int)$sRow['kanban_item_id'];
                    $sessionsByKanban[$kid][] = $sRow;
                    $sessionIds[] = (int)$sRow['id'];
                    if ($sRow['status'] === 'in_progress') {
                        $activeSessionByKanban[$kid] = $sRow;
                    }
                }

                if (!empty($sessionIds)) {
                    $inSessionIds = implode(',', $sessionIds);
                    $lotSql = "
                        SELECT id, inspection_session_id, lot_number, ref_number, qty, sample_size, lot_result, lot_status, remarks
                        FROM inspection_session_lots
                        WHERE inspection_session_id IN ($inSessionIds)
                        ORDER BY id ASC
                    ";
                    $stmtLots = $pdo->query($lotSql);
                    while ($lRow = $stmtLots->fetch(PDO::FETCH_ASSOC)) {
                        $sid = (int)$lRow['inspection_session_id'];
                        $lotsBySession[$sid][] = $lRow;
                    }
                }
            }

        // =====================================================================
        // TAB 2: SAFETY STOCK (CEK FISIK MURNI DARI GUDANG)
        // =====================================================================
        } elseif ($activeTab === 'safety_stock') {

            // Search text filter for Safety Stock
            if ($search !== '') {
                $ssBaseWhere[] = "(k.kanban_no LIKE :ss_s1 OR k.item_code LIKE :ss_s2 OR k.item_description LIKE :ss_s3 OR k.customer LIKE :ss_s4 OR mp.part_name LIKE :ss_s5 OR k.str_loc LIKE :ss_s6)";
                $ssBaseParams[':ss_s1'] = '%' . $search . '%';
                $ssBaseParams[':ss_s2'] = '%' . $search . '%';
                $ssBaseParams[':ss_s3'] = '%' . $search . '%';
                $ssBaseParams[':ss_s4'] = '%' . $search . '%';
                $ssBaseParams[':ss_s5'] = '%' . $search . '%';
                $ssBaseParams[':ss_s6'] = '%' . $search . '%';
            }

            // Build Session Period Filter for Safety Stock
            $sessWherePeriod = "";
            if ($period === 'today') {
                $sessWherePeriod = " AND (s.started_at BETWEEN " . $pdo->quote($todayStart) . " AND " . $pdo->quote($todayEnd) . " OR s.status = 'in_progress')";
            } elseif ($period === 'this_week') {
                $sessWherePeriod = " AND (s.started_at BETWEEN " . $pdo->quote($wStart) . " AND " . $pdo->quote($wEnd) . " OR s.status = 'in_progress')";
            } elseif ($period === 'this_month') {
                $sessWherePeriod = " AND (s.started_at BETWEEN " . $pdo->quote($mStart) . " AND " . $pdo->quote($mEnd) . " OR s.status = 'in_progress')";
            } elseif ($period === 'custom') {
                if (!empty($startDate) && !empty($endDate)) {
                    $sessWherePeriod = " AND (s.started_at BETWEEN " . $pdo->quote($startDate . ' 00:00:00') . " AND " . $pdo->quote($endDate . ' 23:59:59') . " OR s.status = 'in_progress')";
                } elseif (!empty($startDate)) {
                    $sessWherePeriod = " AND (s.started_at >= " . $pdo->quote($startDate . ' 00:00:00') . " OR s.status = 'in_progress')";
                } elseif (!empty($endDate)) {
                    $sessWherePeriod = " AND (s.started_at <= " . $pdo->quote($endDate . ' 23:59:59') . " OR s.status = 'in_progress')";
                }
            }

            // Calculate Safety Stock KPI Statistics (berdasarkan sesi fisik aktual di gudang)
            $ssStatsWhereClause = implode(" AND ", $ssBaseWhere);

            $statsSql = "
                SELECT 
                    -- Item (Kanban) counts
                    COUNT(DISTINCT k.id) as total_count,
                    COUNT(DISTINCT CASE WHEN s.status = 'in_progress' OR k.status IN ('in_progress', 'partial') THEN k.id END) as in_progress_count,
                    COUNT(DISTINCT CASE WHEN s.status = 'passed' OR k.status = 'completed' THEN k.id END) as passed_count,
                    COUNT(DISTINCT CASE WHEN s.status = 'rejected' OR k.status = 'rejected' OR isl.lot_result = 'rejected' THEN k.id END) as rejected_count,
                    
                    -- Lot counts
                    COUNT(DISTINCT isl.id) as total_lots,
                    COUNT(DISTINCT CASE WHEN s.status = 'in_progress' OR isl.lot_result = 'in_progress' THEN isl.id END) as in_progress_lots,
                    COUNT(DISTINCT CASE WHEN (isl.lot_result = 'passed' OR s.status = 'passed' OR k.status = 'completed') AND (isl.lot_result != 'rejected' OR isl.lot_result IS NULL) AND s.status != 'rejected' THEN isl.id END) as passed_lots,
                    COUNT(DISTINCT CASE WHEN isl.lot_result = 'rejected' OR s.status = 'rejected' THEN isl.id END) as rejected_lots,
                    
                    -- Qty (pcs) sums
                    COALESCE(SUM(isl.qty), 0) as total_qty_pcs,
                    COALESCE(SUM(CASE WHEN s.status = 'in_progress' OR isl.lot_result = 'in_progress' THEN isl.qty ELSE 0 END), 0) as in_progress_qty_pcs,
                    COALESCE(SUM(CASE WHEN (isl.lot_result = 'passed' OR s.status = 'passed' OR k.status = 'completed') AND (isl.lot_result != 'rejected' OR isl.lot_result IS NULL) AND s.status != 'rejected' THEN isl.qty ELSE 0 END), 0) as passed_qty_pcs,
                    COALESCE(SUM(CASE WHEN isl.lot_result = 'rejected' OR s.status = 'rejected' THEN isl.qty ELSE 0 END), 0) as rejected_qty_pcs
                FROM kanban_items k
                LEFT JOIN master_parts mp ON mp.part_code = k.item_code
                LEFT JOIN inspection_sessions s ON s.kanban_item_id = k.id {$sessWherePeriod}
                LEFT JOIN inspection_session_lots isl ON isl.inspection_session_id = s.id 
                    AND (isl.remarks IS NULL OR (isl.remarks NOT LIKE '%Sisa Split Safety Stock%' AND isl.remarks NOT LIKE '%Alokasi Safety Stock%'))
                WHERE {$ssStatsWhereClause}
            ";
            $stmtStats = $pdo->prepare($statsSql);
            $stmtStats->execute($ssBaseParams);
            $statRow = $stmtStats->fetch(PDO::FETCH_ASSOC);
            if ($statRow) {
                $stats['total_count']         = (int)($statRow['total_count'] ?? 0);
                $stats['in_progress_count']   = (int)($statRow['in_progress_count'] ?? 0);
                $stats['passed_count']        = (int)($statRow['passed_count'] ?? 0);
                $stats['rejected_count']      = (int)($statRow['rejected_count'] ?? 0);
                $stats['total_lots']          = (int)($statRow['total_lots'] ?? 0);
                $stats['in_progress_lots']    = (int)($statRow['in_progress_lots'] ?? 0);
                $stats['passed_lots']         = (int)($statRow['passed_lots'] ?? 0);
                $stats['rejected_lots']       = (int)($statRow['rejected_lots'] ?? 0);
                $stats['total_qty_pcs']       = (int)($statRow['total_qty_pcs'] ?? 0);
                $stats['in_progress_qty_pcs'] = (int)($statRow['in_progress_qty_pcs'] ?? 0);
                $stats['passed_qty_pcs']      = (int)($statRow['passed_qty_pcs'] ?? 0);
                $stats['rejected_qty_pcs']    = (int)($statRow['rejected_qty_pcs'] ?? 0);
            }
            $safetyStockTabCount = $stats['total_count'];

            // Badge counts (cached for 30s)
            if ($isBadgeCacheValid) {
                $kanbanTabCount        = (int)($cachedBadges['kanban'] ?? 0);
                $myInspectionsTabCount = (int)($cachedBadges['my_inspections'] ?? 0);
                $myLotsTabCount        = (int)($cachedBadges['my_lots'] ?? 0);
                $_SESSION['oqc_badge_cache'][$badgeCacheKey]['safety_stock'] = $safetyStockTabCount;
            } else {
                // Quick count for Kanban Tab Badge
                $kbBadgeWhereClause = implode(" AND ", $kbBaseWhere);
                $stmtKbBadge = $pdo->prepare("SELECT COUNT(DISTINCT k.id) FROM kanban_items k LEFT JOIN master_parts mp ON mp.part_code = k.item_code WHERE {$kbBadgeWhereClause}");
                $stmtKbBadge->execute($kbBaseParams);
                $kanbanTabCount = (int)$stmtKbBadge->fetchColumn();

                // Quick count for Riwayat Saya Tab Badge
                $myBadgeWhereClause = implode(" AND ", $myBaseWhere);
                $stmtMyBadge = $pdo->prepare("SELECT COUNT(DISTINCT s.id) FROM inspection_sessions s WHERE {$myBadgeWhereClause}");
                $stmtMyBadge->execute($myBaseParams);
                $myInspectionsTabCount = (int)$stmtMyBadge->fetchColumn();

                // Quick count for Rincian Per Lot Tab Badge
                $myLotsBadgeWhereClause = implode(" AND ", $myLotsBaseWhere);
                $stmtLotsBadge = $pdo->prepare("SELECT COUNT(DISTINCT isl.id) FROM inspection_session_lots isl JOIN inspection_sessions s ON s.id = isl.inspection_session_id WHERE {$myLotsBadgeWhereClause}");
                $stmtLotsBadge->execute($myLotsBaseParams);
                $myLotsTabCount = (int)$stmtLotsBadge->fetchColumn();

                $_SESSION['oqc_badge_cache'][$badgeCacheKey] = [
                    'time'           => time(),
                    'kanban'         => $kanbanTabCount,
                    'safety_stock'   => $safetyStockTabCount,
                    'my_inspections' => $myInspectionsTabCount,
                    'my_lots'        => $myLotsTabCount
                ];
            }

            // Build Table Filters for Safety Stock
            $tableWhere = $ssBaseWhere;
            $tableParams = $ssBaseParams;

            if ($statusFilter === 'uninspected') {
                $tableWhere[] = "k.status = 'uninspected' AND NOT EXISTS (SELECT 1 FROM inspection_sessions s_act WHERE s_act.kanban_item_id = k.id AND s_act.status = 'in_progress')";
            } elseif ($statusFilter === 'in_progress') {
                $tableWhere[] = "(k.status IN ('in_progress', 'partial') OR EXISTS (SELECT 1 FROM inspection_sessions s_act WHERE s_act.kanban_item_id = k.id AND s_act.status = 'in_progress'))";
            } elseif ($statusFilter === 'partial') {
                $tableWhere[] = "k.status = 'partial'";
            } elseif ($statusFilter === 'completed') {
                $tableWhere[] = "k.status = 'completed'";
            } elseif ($statusFilter === 'rejected') {
                $tableWhere[] = "(k.status = 'rejected' OR EXISTS (
                    SELECT 1 FROM inspection_sessions s_rej 
                    WHERE s_rej.kanban_item_id = k.id AND s_rej.status = 'rejected'
                ) OR EXISTS (
                    SELECT 1 FROM inspection_sessions s_lot
                    JOIN inspection_session_lots isl_rej ON isl_rej.inspection_session_id = s_lot.id
                    WHERE s_lot.kanban_item_id = k.id AND (isl_rej.lot_result = 'rejected' OR isl_rej.lot_status = 'rejected')
                ))";
            }

            $tableWhereClause = implode(" AND ", $tableWhere);

            // Count Total Grouped Parts for Pagination
            $countSql = "
                SELECT COUNT(DISTINCT k.item_code) 
                FROM kanban_items k
                LEFT JOIN master_parts mp ON mp.part_code = k.item_code
                WHERE {$tableWhereClause}
            ";
            $stmtCount = $pdo->prepare($countSql);
            $stmtCount->execute($tableParams);
            $totalItems = (int)$stmtCount->fetchColumn();

            $totalPages = max(1, ceil($totalItems / $limit));
            if ($page > $totalPages) $page = $totalPages;
            $offset = ($page - 1) * $limit;

            // Fetch Safety Stock Items Grouped by Part Code
            $sql = "
                SELECT k.item_code AS part_code,
                       COALESCE(NULLIF(mp.part_name, ''), MAX(k.item_description), k.item_code) AS display_part_name,
                       COALESCE(MAX(mm.name), MAX(mp.model), '-') AS model_name,
                       MAX(k.customer) AS customer,
                       MAX(k.str_loc) AS str_loc,
                       GROUP_CONCAT(DISTINCT k.id) AS kanban_ids,
                       COUNT(DISTINCT k.id) AS total_kanban_count,
                       MAX(s.started_at) AS latest_checked_at
                FROM kanban_items k
                LEFT JOIN master_parts mp ON mp.part_code = k.item_code
                LEFT JOIN master_models mm ON mm.id = mp.model_id
                LEFT JOIN inspection_sessions s ON s.kanban_item_id = k.id
                WHERE {$tableWhereClause}
                GROUP BY k.item_code
                ORDER BY COALESCE(MAX(s.started_at), MAX(k.created_at)) DESC
                LIMIT {$limit} OFFSET {$offset}
            ";
            $stmtSS = $pdo->prepare($sql);
            $stmtSS->execute($tableParams);
            $ssKanbans = $stmtSS->fetchAll(PDO::FETCH_ASSOC);

            // Batch fetch session details and lots grouped by Part Code
            $ssPartLots = [];
            $allKanbanIds = [];
            foreach ($ssKanbans as $pg) {
                if (!empty($pg['kanban_ids'])) {
                    $ids = explode(',', $pg['kanban_ids']);
                    foreach ($ids as $idVal) {
                        $allKanbanIds[] = (int)$idVal;
                    }
                }
            }

            if (!empty($allKanbanIds)) {
                $inKanbanIds = implode(',', array_unique($allKanbanIds));
                $lotSql = "
                    SELECT 
                        isl.id AS lot_id,
                        isl.inspection_session_id,
                        isl.lot_number,
                        isl.ref_number,
                        isl.qty,
                        isl.sample_size,
                        isl.lot_result,
                        isl.lot_status,
                        isl.remarks,
                        isl.scanned_qr_raw,
                        isl.action_noted_at,
                        isl.created_at,
                        isl.closed_at,
                        isl.replaced_by_lot_id,
                        s.started_at AS session_started_at,
                        s.closed_at AS session_closed_at,
                        s.status AS session_status,
                        s.parent_session_id,
                        s.is_reinspection,
                        s.reinspection_type,
                        s.reinspection_notes,
                        s.inspector_id,
                        (SELECT COUNT(*) FROM lot_substitution_log sub 
                         WHERE sub.ng_session_lot_id = isl.id 
                            OR sub.replacement_session_lot_id = isl.id
                            OR sub.reinspection_session_id = s.id) AS has_subst_log,
                        (SELECT COUNT(*) FROM inspection_ng_records ngr 
                         WHERE ngr.session_lot_id = isl.id 
                           AND ngr.is_sorted = 1) AS has_sorted_ng,
                        (SELECT COUNT(*) 
                         FROM inspection_session_lots prev_isl
                         JOIN inspection_sessions prev_s ON prev_s.id = prev_isl.inspection_session_id
                         WHERE prev_isl.lot_number = isl.lot_number
                           AND (
                               (isl.ref_number IS NOT NULL AND prev_isl.ref_number = isl.ref_number)
                               OR (isl.ref_number IS NULL)
                           )
                           AND prev_isl.id != isl.id
                           AND prev_isl.created_at < isl.created_at
                           AND (prev_isl.lot_result = 'rejected' OR prev_isl.ng_count > 0 OR prev_isl.lot_status IN ('ng_found', 'ng_quarantine', 'replaced'))
                        ) AS has_prev_rejected,
                        COALESCE(u.name, 'QC Inspector') AS inspector_name,
                        k.item_code AS part_code,
                        COALESCE(NULLIF(mp.part_name, ''), k.item_description, k.item_code) AS part_name,
                        COALESCE(mm.name, mp.model, '-') AS model_name
                    FROM inspection_session_lots isl
                    JOIN inspection_sessions s ON s.id = isl.inspection_session_id
                    JOIN kanban_items k ON k.id = s.kanban_item_id
                    LEFT JOIN master_parts mp ON mp.part_code = k.item_code
                    LEFT JOIN master_models mm ON mm.id = mp.model_id
                    LEFT JOIN users u ON u.id = s.inspector_id
                    WHERE s.kanban_item_id IN ($inKanbanIds)
                      {$sessWherePeriod}
                    ORDER BY isl.id DESC
                ";
                $stmtLots = $pdo->query($lotSql);
                $lotRows = $stmtLots->fetchAll(PDO::FETCH_ASSOC);

                $allSessionIds = array_unique(array_column($lotRows, 'inspection_session_id'));
                $defectsByLot = [];
                if (!empty($allSessionIds)) {
                    $inSessIds = implode(',', array_map('intval', $allSessionIds));
                    $ngSql = "
                        SELECT n.*, dt.name as defect_name, dt.code as defect_code,
                               COALESCE(n.is_sorted, 0) as is_sorted
                        FROM inspection_ng_records n
                        JOIN defect_types dt ON dt.id = n.defect_type_id
                        WHERE n.inspection_session_id IN ($inSessIds)
                          AND (n.is_cancelled IS NULL OR n.is_cancelled = 0)
                        ORDER BY n.id ASC
                    ";
                    $stmtNg = $pdo->query($ngSql);
                    while ($nRow = $stmtNg->fetch(PDO::FETCH_ASSOC)) {
                        $slotId = (int)($nRow['session_lot_id'] ?? 0);
                        if ($slotId > 0) {
                            $defectsByLot[$slotId][] = $nRow;
                        }
                    }
                }

                foreach ($lotRows as $lRow) {
                    $pCode = $lRow['part_code'];
                    $slotId = (int)$lRow['lot_id'];
                    $allDefects = $defectsByLot[$slotId] ?? [];
                    $activeDefects = array_values(array_filter($allDefects, function($df) {
                        return empty($df['is_sorted']);
                    }));
                    $lRow['defects'] = $activeDefects;
                    $lRow['all_defects'] = $allDefects;

                    $remUpper = strtoupper(($lRow['remarks'] ?? '') . ' ' . ($lRow['reinspection_notes'] ?? ''));
                    $isLotReinspected = !empty($lRow['is_reinspection'])
                        || !empty($lRow['parent_session_id'])
                        || !empty($lRow['reinspection_type'])
                        || (strtolower($lRow['lot_status'] ?? '') === 'reinspected')
                        || ((int)($lRow['has_subst_log'] ?? 0) > 0)
                        || ((int)($lRow['has_sorted_ng'] ?? 0) > 0)
                        || ((int)($lRow['has_prev_rejected'] ?? 0) > 0)
                        || (strpos($remUpper, 'RE-INSPEKSI') !== false)
                        || (strpos($remUpper, 'REINSPEKSI') !== false)
                        || (strpos($remUpper, 'RE-INSPECT') !== false)
                        || (strpos($remUpper, 'REINSPECT') !== false)
                        || (strpos($remUpper, 'PENGGANTI') !== false)
                        || (strpos($remUpper, 'SORTIR') !== false);

                    if ($isLotReinspected) {
                        $lRow['is_reinspected_lot'] = true;
                        $lRow['is_reinspection'] = true;
                        if (strtolower($lRow['lot_result'] ?? '') === 'rejected') {
                            $lRow['status_label'] = 'REJECTED (RE-INSPEKSI)';
                            $lRow['status_badge'] = 'REJECTED (RE-INSPEKSI)';
                        } elseif (strtolower($lRow['lot_result'] ?? '') === 'passed' || strtolower($lRow['lot_status'] ?? '') === 'reinspected') {
                            $lRow['status_label'] = 'PASSED (RE-INSPEKSI)';
                            $lRow['status_badge'] = 'PASSED (RE-INSPEKSI)';
                        }
                    }
                    $ssPartLots[$pCode][] = $lRow;
                }
            }

        // =====================================================================
        // TAB 3: RIWAYAT SAYA (INSPEKSI OLEH USER LOGIN)
        // =====================================================================
        } elseif ($activeTab === 'my_inspections') {

            // Search text filter for My Inspections
            if ($search !== '') {
                $qrSearchMy = parseQrScanString($search);
                if (!empty($qrSearchMy['is_qr'])) {
                    $myQrParts = [];
                    if (!empty($qrSearchMy['Z5'])) {
                        $myQrParts[] = "EXISTS (
                            SELECT 1 FROM inspection_session_lots isl_qr 
                            WHERE isl_qr.inspection_session_id = s.id 
                              AND (isl_qr.ref_number = :qr_ref OR isl_qr.ref_number LIKE :qr_ref_like)
                        )";
                        $myBaseParams[':qr_ref'] = $qrSearchMy['Z5'];
                        $myBaseParams[':qr_ref_like'] = '%' . $qrSearchMy['Z5'] . '%';
                    }
                    if (!empty($qrSearchMy['Z2'])) {
                        $myQrParts[] = "EXISTS (
                            SELECT 1 FROM inspection_session_lots isl_qrl 
                            WHERE isl_qrl.inspection_session_id = s.id 
                              AND isl_qrl.lot_number = :qr_lot
                        )";
                        $myBaseParams[':qr_lot'] = $qrSearchMy['Z2'];
                    }
                    if (!empty($qrSearchMy['Z1'])) {
                        $myQrParts[] = "(mp.part_code LIKE :qr_part1 OR did.part_code LIKE :qr_part2 OR k.item_code LIKE :qr_part3)";
                        $myBaseParams[':qr_part1'] = '%' . $qrSearchMy['Z1'] . '%';
                        $myBaseParams[':qr_part2'] = '%' . $qrSearchMy['Z1'] . '%';
                        $myBaseParams[':qr_part3'] = '%' . $qrSearchMy['Z1'] . '%';
                    }
                    if (!empty($myQrParts)) {
                        $myBaseWhere[] = "(" . implode(" AND ", $myQrParts) . ")";
                    }
                } else {
                    $myBaseWhere[] = "(
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
                    $myBaseParams[':my_s1'] = '%' . $search . '%';
                    $myBaseParams[':my_s2'] = '%' . $search . '%';
                    $myBaseParams[':my_s3'] = '%' . $search . '%';
                    $myBaseParams[':my_s4'] = '%' . $search . '%';
                    $myBaseParams[':my_s5'] = '%' . $search . '%';
                    $myBaseParams[':my_s6'] = '%' . $search . '%';
                    $myBaseParams[':my_s7'] = '%' . $search . '%';
                    $myBaseParams[':my_s8'] = '%' . $search . '%';
                    $myBaseParams[':my_s9'] = '%' . $search . '%';
                }
            }

            // Calculate My Inspections KPI Statistics
            $myStatsWhereClause = implode(" AND ", $myBaseWhere);
            $statsSql = "
                SELECT 
                    COUNT(DISTINCT s.id) as total_count,
                    SUM(CASE WHEN s.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_count,
                    SUM(CASE WHEN s.status = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
                    COALESCE(SUM(s.total_scanned_qty), 0) as total_qty_pcs
                FROM inspection_sessions s
                LEFT JOIN kanban_items k ON k.id = s.kanban_item_id
                LEFT JOIN master_parts mp ON (mp.id = s.part_id OR mp.part_code = k.item_code)
                LEFT JOIN master_models mm ON mm.id = mp.model_id
                LEFT JOIN daily_inspection_data did ON did.id = s.did_id
                WHERE {$myStatsWhereClause}
            ";
            $stmtStats = $pdo->prepare($statsSql);
            $stmtStats->execute($myBaseParams);
            $statRow = $stmtStats->fetch(PDO::FETCH_ASSOC);
            if ($statRow) {
                $stats['total_count']       = (int)($statRow['total_count'] ?? 0);
                $stats['in_progress_count'] = (int)($statRow['in_progress_count'] ?? 0);
                $stats['passed_count']      = (int)($statRow['passed_count'] ?? 0);
                $stats['rejected_count']    = (int)($statRow['rejected_count'] ?? 0);
                $stats['total_qty_pcs']     = (int)($statRow['total_qty_pcs'] ?? 0);
            }
            $myInspectionsTabCount = $stats['total_count'];

            // Badge counts (cached for 30s)
            if ($isBadgeCacheValid) {
                $kanbanTabCount      = (int)($cachedBadges['kanban'] ?? 0);
                $safetyStockTabCount = (int)($cachedBadges['safety_stock'] ?? 0);
                $myLotsTabCount      = (int)($cachedBadges['my_lots'] ?? 0);
                $_SESSION['oqc_badge_cache'][$badgeCacheKey]['my_inspections'] = $myInspectionsTabCount;
            } else {
                // Quick count for Kanban Tab Badge
                $kbBadgeWhereClause = implode(" AND ", $kbBaseWhere);
                $stmtKbBadge = $pdo->prepare("SELECT COUNT(DISTINCT k.id) FROM kanban_items k WHERE {$kbBadgeWhereClause}");
                $stmtKbBadge->execute($kbBaseParams);
                $kanbanTabCount = (int)$stmtKbBadge->fetchColumn();

                // Quick count for Safety Stock Tab Badge
                $ssBadgeWhereClause = implode(" AND ", $ssBaseWhere);
                $stmtSSBadge = $pdo->prepare("SELECT COUNT(DISTINCT k.id) FROM kanban_items k LEFT JOIN master_parts mp ON mp.part_code = k.item_code WHERE {$ssBadgeWhereClause}");
                $stmtSSBadge->execute($ssBaseParams);
                $safetyStockTabCount = (int)$stmtSSBadge->fetchColumn();

                // Quick count for Rincian Per Lot Tab Badge
                $myLotsBadgeWhereClause = implode(" AND ", $myLotsBaseWhere);
                $stmtLotsBadge = $pdo->prepare("SELECT COUNT(DISTINCT isl.id) FROM inspection_session_lots isl JOIN inspection_sessions s ON s.id = isl.inspection_session_id WHERE {$myLotsBadgeWhereClause}");
                $stmtLotsBadge->execute($myLotsBaseParams);
                $myLotsTabCount = (int)$stmtLotsBadge->fetchColumn();

                $_SESSION['oqc_badge_cache'][$badgeCacheKey] = [
                    'time'           => time(),
                    'kanban'         => $kanbanTabCount,
                    'safety_stock'   => $safetyStockTabCount,
                    'my_inspections' => $myInspectionsTabCount,
                    'my_lots'        => $myLotsTabCount
                ];
            }

            // Build Table Filters for My Inspections
            $tableWhere = $myBaseWhere;
            $tableParams = $myBaseParams;

            if ($statusFilter === 'in_progress') {
                $tableWhere[] = "s.status = 'in_progress'";
            } elseif ($statusFilter === 'completed') {
                $tableWhere[] = "s.status = 'passed'";
            } elseif ($statusFilter === 'rejected') {
                $tableWhere[] = "s.status = 'rejected'";
            }

            $tableWhereClause = implode(" AND ", $tableWhere);

            // Count Total Records for Pagination
            $countSql = "
                SELECT COUNT(DISTINCT s.id) 
                FROM inspection_sessions s
                LEFT JOIN kanban_items k ON k.id = s.kanban_item_id
                LEFT JOIN master_parts mp ON (mp.id = s.part_id OR mp.part_code = k.item_code)
                LEFT JOIN master_models mm ON mm.id = mp.model_id
                LEFT JOIN daily_inspection_data did ON did.id = s.did_id
                WHERE {$tableWhereClause}
            ";
            $stmtCount = $pdo->prepare($countSql);
            $stmtCount->execute($tableParams);
            $totalItems = (int)$stmtCount->fetchColumn();

            $totalPages = max(1, ceil($totalItems / $limit));
            if ($page > $totalPages) $page = $totalPages;
            $offset = ($page - 1) * $limit;

            // Fetch My Inspection Sessions
            $sql = "
                SELECT s.*,
                       COALESCE(mp.part_code, k.item_code, did.part_code, '-') AS part_number,
                       COALESCE(NULLIF(mp.part_name, ''), NULLIF(did.part_name, ''), k.item_description, '-') AS display_part_name,
                       COALESCE(mm.name, mp.model, '-') AS model_name,
                       COALESCE(u.name, did.pic, 'QC Inspector') AS inspector_name,
                       TIMESTAMPDIFF(MINUTE, s.started_at, COALESCE(s.closed_at, NOW())) AS duration_minutes,
                       k.kanban_no, k.customer, k.str_loc as storage_loc,
                       k.qty AS kanban_target_qty,
                       k.status AS kanban_status,
                       CASE 
                           WHEN s.kanban_item_id IS NOT NULL AND s.kanban_item_id > 0 THEN
                               (SELECT COUNT(*) FROM inspection_sessions s_seq 
                                 WHERE s_seq.kanban_item_id = s.kanban_item_id 
                                   AND (s_seq.started_at < s.started_at OR (s_seq.started_at = s.started_at AND s_seq.id <= s.id)))
                           WHEN s.did_id IS NOT NULL AND s.did_id > 0 THEN
                               (SELECT COUNT(*) FROM inspection_sessions s_seq 
                                 WHERE s_seq.did_id = s.did_id 
                                   AND (s_seq.started_at < s.started_at OR (s_seq.started_at = s.started_at AND s_seq.id <= s.id)))
                           WHEN s.part_id IS NOT NULL AND s.part_id > 0 THEN
                               (SELECT COUNT(*) FROM inspection_sessions s_seq 
                                 WHERE s_seq.part_id = s.part_id AND s_seq.inspection_type = 'safety_stock'
                                   AND (s_seq.started_at < s.started_at OR (s_seq.started_at = s.started_at AND s_seq.id <= s.id)))
                           ELSE 1
                       END AS kanban_session_number,
                       CASE 
                           WHEN s.kanban_item_id IS NOT NULL AND s.kanban_item_id > 0 THEN
                               COALESCE((
                                   SELECT SUM(
                                       CASE 
                                           WHEN EXISTS (SELECT 1 FROM inspection_session_lots isl_c WHERE isl_c.inspection_session_id = s2.id) THEN
                                               GREATEST(0, COALESCE((SELECT SUM(CASE WHEN isl2.lot_result IN ('passed', 'skipped') AND (isl2.lot_status IS NULL OR isl2.lot_status != 'replaced') THEN isl2.qty ELSE 0 END)
                                                         FROM inspection_session_lots isl2 WHERE isl2.inspection_session_id = s2.id), 0) - COALESCE(s2.excess_qty, 0))
                                           WHEN s2.status = 'passed' THEN
                                               GREATEST(0, s2.total_scanned_qty - COALESCE(s2.excess_qty, 0))
                                           ELSE 0
                                       END
                                   )
                                   FROM inspection_sessions s2
                                   WHERE s2.kanban_item_id = s.kanban_item_id
                               ), 0)
                           ELSE 0
                       END AS kanban_passed_qty
                FROM inspection_sessions s
                LEFT JOIN kanban_items k ON k.id = s.kanban_item_id
                LEFT JOIN master_parts mp ON (mp.id = s.part_id OR mp.part_code = k.item_code)
                LEFT JOIN master_models mm ON mm.id = mp.model_id
                LEFT JOIN daily_inspection_data did ON did.id = s.did_id
                LEFT JOIN users u ON u.id = s.inspector_id
                WHERE {$tableWhereClause}
                ORDER BY s.started_at {$sort}, s.id {$sort}
                LIMIT {$limit} OFFSET {$offset}
            ";
            $stmtMy = $pdo->prepare($sql);
            $stmtMy->execute($tableParams);
            $mySessions = $stmtMy->fetchAll(PDO::FETCH_ASSOC);

            // Batch fetch lots, defects, and substitution logs for the displayed sessions
            $sessionIds = array_column($mySessions, 'id');
            if (!empty($sessionIds)) {
                $inSessionIds = implode(',', array_map('intval', $sessionIds));

                // Lots
                $lotSql = "
                    SELECT id, inspection_session_id, lot_number, ref_number, qty, sample_size, lot_result, lot_status, remarks, scanned_qr_raw, action_noted_at, created_at, closed_at
                    FROM inspection_session_lots
                    WHERE inspection_session_id IN ($inSessionIds)
                    ORDER BY id ASC
                ";
                $stmtLots = $pdo->query($lotSql);
                while ($lRow = $stmtLots->fetch(PDO::FETCH_ASSOC)) {
                    $sid = (int)$lRow['inspection_session_id'];
                    $lotsBySession[$sid][] = $lRow;
                }

                // Substitution & sort logs
                $subSql = "
                    SELECT lsl.*, u.name as actioned_by_name
                    FROM lot_substitution_log lsl
                    LEFT JOIN users u ON u.id = lsl.actioned_by
                    WHERE lsl.original_session_id IN ($inSessionIds) 
                       OR lsl.reinspection_session_id IN ($inSessionIds)
                    ORDER BY lsl.id ASC
                ";
                $stmtSub = $pdo->query($subSql);
                while ($subRow = $stmtSub->fetch(PDO::FETCH_ASSOC)) {
                    $origSid = (int)($subRow['original_session_id'] ?? 0);
                    $reSid = (int)($subRow['reinspection_session_id'] ?? 0);
                    if ($origSid > 0) $substitutionsBySession[$origSid][] = $subRow;
                    if ($reSid > 0 && $reSid !== $origSid) $substitutionsBySession[$reSid][] = $subRow;
                }

                // NG Defect records
                $ngSql = "
                    SELECT n.*, dt.name as defect_name, dt.code as defect_code,
                           COALESCE(n.inspection_session_id, sp.inspection_session_id) as target_session_id
                    FROM inspection_ng_records n
                    LEFT JOIN inspection_samples sp ON sp.id = n.inspection_sample_id
                    JOIN defect_types dt ON dt.id = n.defect_type_id
                    WHERE (n.inspection_session_id IN ($inSessionIds) OR sp.inspection_session_id IN ($inSessionIds))
                      AND (n.is_cancelled IS NULL OR n.is_cancelled = 0)
                    ORDER BY n.id ASC
                ";
                $stmtNg = $pdo->query($ngSql);
                while ($nRow = $stmtNg->fetch(PDO::FETCH_ASSOC)) {
                    $slotId = (int)($nRow['session_lot_id'] ?? 0);
                    if ($slotId > 0) {
                        $ngRecordsByLot[$slotId][] = $nRow;
                    }
                }
            }

        // =====================================================================
        // TAB 4: RINCIAN PER LOT SAYA (FLAT LOT TABLE FOR CURRENT INSPECTOR)
        // 100% Identik dengan logic export_excel.php?tab=my_lots sehingga
        // lot yang di-reinspeksi muncul lengkap (Inspeksi Awal NG & Re-inspeksi)
        // =====================================================================
        } elseif ($activeTab === 'my_lots') {

            $targetInspectorId = ($inspectorFilterId > 0) ? $inspectorFilterId : $currentUserId;

            // 1. Build Query for Sessions (identik dengan export_excel.php)
            $sessWhere = ["s.inspector_id = :my_sess_uid"];
            $sessParams = [':my_sess_uid' => $targetInspectorId];

            $qrSearchMyLots = ($search !== '') ? parseQrScanString($search) : ['is_qr' => false];
            $isQrSearch = !empty($qrSearchMyLots['is_qr']);

            if (!$isQrSearch) {
                if ($period === 'today') {
                    $sessWhere[] = "s.started_at BETWEEN :my_t_start AND :my_t_end";
                    $sessParams[':my_t_start'] = $todayStart;
                    $sessParams[':my_t_end']   = $todayEnd;
                } elseif ($period === 'this_week') {
                    $sessWhere[] = "s.started_at BETWEEN :my_w_start AND :my_w_end";
                    $sessParams[':my_w_start'] = $wStart;
                    $sessParams[':my_w_end']   = $wEnd;
                } elseif ($period === 'this_month') {
                    $sessWhere[] = "s.started_at BETWEEN :my_m_start AND :my_m_end";
                    $sessParams[':my_m_start'] = $mStart;
                    $sessParams[':my_m_end']   = $mEnd;
                } elseif ($period === 'custom') {
                    if (!empty($startDate) && !empty($endDate)) {
                        $sessWhere[] = "s.started_at BETWEEN :my_c_start AND :my_c_end";
                        $sessParams[':my_c_start'] = $startDate . ' 00:00:00';
                        $sessParams[':my_c_end']   = $endDate . ' 23:59:59';
                    } elseif (!empty($startDate)) {
                        $sessWhere[] = "s.started_at >= :my_c_start";
                        $sessParams[':my_c_start'] = $startDate . ' 00:00:00';
                    } elseif (!empty($endDate)) {
                        $sessWhere[] = "s.started_at <= :my_c_end";
                        $sessParams[':my_c_end']   = $endDate . ' 23:59:59';
                    }
                }
            }

            // Exclude Direct Close Kanban virtual sessions from physical inspection report
            $sessWhere[] = "(s.approval_notes IS NULL OR s.approval_notes NOT LIKE '%Direct Close%')";
            $sessWhere[] = "NOT EXISTS (
                SELECT 1 FROM inspection_session_lots isl_dc 
                WHERE isl_dc.inspection_session_id = s.id 
                  AND (isl_dc.ref_number = 'DIRECT-PASS' OR isl_dc.ref_number LIKE '%DIRECT-PASS%' OR isl_dc.remarks LIKE '%Direct Close%')
            )";

            // Exclude Auto Safety Stock virtual sessions from leftover/excess kanban (hanya inspeksi fisik real)
            $sessWhere[] = "(k.remark IS NULL OR (
                k.remark NOT LIKE '%AUTO-SS-SESS-%' 
                AND k.remark NOT LIKE '%Auto Safety Stock Sisa Excess%' 
                AND k.remark NOT LIKE '%Sisa Excess Kanban%'
                AND k.remark NOT LIKE '%Sisa Stok Safety Stock (Split%'
            ))";
            $sessWhere[] = "NOT EXISTS (
                SELECT 1 FROM inspection_session_lots isl_sisa 
                WHERE isl_sisa.inspection_session_id = s.id 
                  AND (
                      isl_sisa.remarks LIKE '%Sisa Kelebihan Kanban%' 
                      OR isl_sisa.remarks LIKE '%Sisa Split Safety Stock%'
                  )
            )";

            // Filter jenis inspeksi (kanban / safety_stock)
            if ($typeFilter === 'safety_stock') {
                $sessWhere[] = "s.inspection_type = 'safety_stock'";
            } elseif ($typeFilter === 'kanban') {
                $sessWhere[] = "(s.inspection_type = 'kanban' OR s.inspection_type IS NULL)";
            }

            // Filter search jika ada
            if ($search !== '') {
                if ($isQrSearch) {
                    $qrParts = [];
                    if (!empty($qrSearchMyLots['Z5'])) {
                        $qrParts[] = "EXISTS (
                            SELECT 1 FROM inspection_session_lots isl_qr 
                            WHERE isl_qr.inspection_session_id = s.id 
                              AND (isl_qr.ref_number = :qr_ref OR isl_qr.ref_number LIKE :qr_ref_like)
                        )";
                        $sessParams[':qr_ref'] = $qrSearchMyLots['Z5'];
                        $sessParams[':qr_ref_like'] = '%' . $qrSearchMyLots['Z5'] . '%';
                    }
                    if (!empty($qrSearchMyLots['Z2'])) {
                        $qrParts[] = "EXISTS (
                            SELECT 1 FROM inspection_session_lots isl_qrl 
                            WHERE isl_qrl.inspection_session_id = s.id 
                              AND isl_qrl.lot_number = :qr_lot
                        )";
                        $sessParams[':qr_lot'] = $qrSearchMyLots['Z2'];
                    }
                    if (!empty($qrSearchMyLots['Z1'])) {
                        $qrParts[] = "(mp.part_code LIKE :qr_part1 OR did.part_code LIKE :qr_part2 OR k.item_code LIKE :qr_part3)";
                        $sessParams[':qr_part1'] = '%' . $qrSearchMyLots['Z1'] . '%';
                        $sessParams[':qr_part2'] = '%' . $qrSearchMyLots['Z1'] . '%';
                        $sessParams[':qr_part3'] = '%' . $qrSearchMyLots['Z1'] . '%';
                    }
                    if (!empty($qrParts)) {
                        $sessWhere[] = "(" . implode(" AND ", $qrParts) . ")";
                    }
                } else {
                    $sessWhere[] = "(
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
                    $sessParams[':my_s1'] = $like;
                    $sessParams[':my_s2'] = $like;
                    $sessParams[':my_s3'] = $like;
                    $sessParams[':my_s4'] = $like;
                    $sessParams[':my_s5'] = $like;
                    $sessParams[':my_s6'] = $like;
                    $sessParams[':my_s7'] = $like;
                    $sessParams[':my_s8'] = $like;
                    $sessParams[':my_s9'] = $like;
                }
            }

            $sessWhereClause = implode(" AND ", $sessWhere);
            $sessSql = "
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
                WHERE {$sessWhereClause}
                ORDER BY s.started_at {$sort}, s.id {$sort}
            ";
            $stmtSessions = $pdo->prepare($sessSql);
            $stmtSessions->execute($sessParams);
            $rawSessions = $stmtSessions->fetchAll(PDO::FETCH_ASSOC);

            $allLotsMap = [];
            $allNgMap = [];
            $substByLot = [];
            $flatLotRows = [];

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
                        isl.closed_at AS closed_at,
                        isl.action_noted_at,
                        isl.replaced_by_lot_id,
                        (SELECT COUNT(*) FROM lot_substitution_log sub 
                         WHERE sub.ng_session_lot_id = isl.id 
                            OR sub.replacement_session_lot_id = isl.id
                            OR sub.reinspection_session_id = isl.inspection_session_id) AS has_subst_log,
                        (SELECT COUNT(*) FROM inspection_ng_records ngr 
                         WHERE ngr.session_lot_id = isl.id 
                           AND ngr.is_sorted = 1) AS has_sorted_ng,
                        (SELECT COUNT(*) 
                         FROM inspection_session_lots prev_isl
                         JOIN inspection_sessions prev_s ON prev_s.id = prev_isl.inspection_session_id
                         WHERE prev_isl.lot_number = isl.lot_number
                           AND (
                               (isl.ref_number IS NOT NULL AND prev_isl.ref_number = isl.ref_number)
                               OR (isl.ref_number IS NULL)
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
                    WHERE (sub.original_session_id IN ({$inSessIds}) OR sub.reinspection_session_id IN ({$inSessIds}))
                    ORDER BY sub.id ASC
                ");
                if ($stmtSubst) {
                    while ($sub = $stmtSubst->fetch(PDO::FETCH_ASSOC)) {
                        if (!empty($sub['ng_session_lot_id'])) {
                            $substByLot[(int)$sub['ng_session_lot_id']][] = $sub;
                        }
                        if (!empty($sub['replacement_session_lot_id'])) {
                            $substByLot[(int)$sub['replacement_session_lot_id']][] = $sub;
                        }
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
                      AND ((ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0) OR ngr.is_sorted = 1)
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
                    if (!isset($rawLotsList[$sId])) continue;
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

                        // Fallback: If not matched to a specific lot_id/ref/lot_number, attach to the first REJECTED lot or first lot in the session
                        if (!$matched && !empty($rawLotsList[$sId])) {
                            $assigned = false;
                            foreach ($rawLotsList[$sId] as &$lotRef) {
                                $lRes = strtolower($lotRef['lot_result'] ?? '');
                                $lSt  = strtolower($lotRef['lot_status'] ?? '');
                                if ($lRes === 'rejected' || $lSt === 'rejected' || $lSt === 'ng_found' || $lSt === 'ng_quarantine') {
                                    $lotRef['defects'][] = $ng;
                                    $assigned = true;
                                    break;
                                }
                            }
                            unset($lotRef);
                            if (!$assigned) {
                                $rawLotsList[$sId][0]['defects'][] = $ng;
                            }
                        }
                    }
                }

                // Expand lots to represent both Initial and Reinspected attempts (identik export_excel.php)
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
                                        if ($actualLotResult === 'rejected' || ($roundNgCount >= $rejectLimit && $rejectLimit > 0)) {
                                            $att['lot_result'] = 'rejected';
                                            $att['status_badge'] = 'REJECTED (Re-inspeksi)';
                                            $att['status_label'] = 'REJECTED (RE-INSPEKSI)';
                                            $att['action_notes'] = $log['notes'] ?: 'Part NG disortir dan diuji ulang fisik, namun tetap ditemukan cacat/NG.';
                                        } elseif ($actualLotResult === 'passed' || strtolower($lRow['lot_status'] ?? '') === 'reinspected') {
                                            $att['lot_result'] = 'passed';
                                            $att['status_badge'] = 'PASSED (Re-inspeksi)';
                                            $att['status_label'] = 'PASSED (RE-INSPEKSI)';
                                            $att['action_notes'] = $log['notes'] ?: 'Part NG disortir dan ditukar dengan part bagus -> Dilakukan re-inspeksi sampel fisik dan lolos uji.';
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
                        }
                        $allLotsMap[$sId][] = $lRow;
                    }
                }

                // 4. Flatten all lots matching export_excel Sheet 1
                foreach ($rawSessions as $sess) {
                    $sId = (int)$sess['session_id'];
                    $sessLots = $allLotsMap[$sId] ?? [];

                    if (!empty($sessLots)) {
                        foreach ($sessLots as $sl) {
                            $refNo = trim($sl['ref_number'] ?? '');
                            $remarks = trim($sl['remarks'] ?? '');
                            if ($refNo === 'DIRECT-PASS' || stripos($refNo, 'DIRECT-PASS') !== false || stripos($remarks, 'Direct Close') !== false || stripos($remarks, 'Sisa Kelebihan Kanban') !== false || stripos($remarks, 'Sisa Split Safety Stock') !== false) {
                                continue;
                            }

                            $totalNg = (int)($sl['ng_count'] ?? 0);
                            $defectNames = [];
                            if (!empty($sl['defects'])) {
                                $sumDefectNg = 0;
                                foreach ($sl['defects'] as $df) {
                                    $q = (int)($df['qty_ng'] ?? 1);
                                    $sumDefectNg += $q;
                                    $dName = $df['defect_name'] ?? 'NG';
                                    $defectNames[$dName] = ($defectNames[$dName] ?? 0) + $q;
                                }
                                if ($sumDefectNg > 0) {
                                    $totalNg = max($totalNg, $sumDefectNg);
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
                            
                            $slRemarksUpper = strtoupper(($sl['remarks'] ?? '') . ' ' . ($sess['reinspection_notes'] ?? '') . ' ' . ($sess['approval_notes'] ?? ''));
                            $isReinspect = !empty($sl['is_reinspected_attempt']) 
                                || !empty($sl['is_reinspected_lot'])
                                || ($sl['attempt_type'] ?? '') === 'reinspection' 
                                || !empty($sess['is_reinspection']) 
                                || !empty($sess['parent_session_id']) 
                                || !empty($sess['reinspection_type'])
                                || $lStatus === 'reinspected'
                                || ((int)($sl['has_subst_log'] ?? 0) > 0)
                                || ((int)($sl['has_sorted_ng'] ?? 0) > 0)
                                || ((int)($sl['has_prev_rejected'] ?? 0) > 0)
                                || isset($substByLot[(int)$sl['lot_id']])
                                || strpos($slRemarksUpper, 'RE-INSPEKSI') !== false
                                || strpos($slRemarksUpper, 'REINSPEKSI') !== false
                                || strpos($slRemarksUpper, 'RE-INSPECT') !== false
                                || strpos($slRemarksUpper, 'REINSPECT') !== false
                                || strpos($slRemarksUpper, 'PENGGANTI') !== false
                                || strpos($slRemarksUpper, 'SORTIR') !== false;

                            $isInitial   = !empty($sl['is_initial_attempt']) || ($sl['attempt_type'] ?? '') === 'initial';

                            if ($isReinspect) {
                                $sl['is_reinspected_lot'] = true;
                                if ($lResult === 'rejected' || ($lTotalNg >= $lRejectNum && $lRejectNum > 0)) {
                                    $rowStatusCode = 'rejected';
                                    $rowStatusLabel = 'REJECTED (RE-INSPEKSI)';
                                } elseif ($lResult === 'passed' || $lStatus === 'reinspected') {
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

                            $isLotSs = (($sess['inspection_type'] ?? '') === 'safety_stock') 
                                || (empty($sess['kanban_item_id']) && !empty($sl['is_safety_stock']));
                            $lotInspectionType = $isLotSs ? 'safety_stock' : 'kanban';

                            $flatLotRows[] = [
                                'lot_id'             => (int)$sl['lot_id'],
                                'session_id'         => $sId,
                                'inspection_type'    => $lotInspectionType,
                                'session_started_at' => $sess['started_at'],
                                'session_closed_at'  => $sess['closed_at'] ?? null,
                                'scanned_at'         => $sl['scanned_at'] ?: $sess['started_at'],
                                'closed_at'          => $sl['closed_at'] ?? null,
                                'action_noted_at'    => $sl['action_noted_at'] ?? null,
                                'part_code'          => $sess['part_code'],
                                'part_name'          => $sess['part_name'],
                                'model_name'         => $sess['model_name'] ?: '-',
                                'lot_number'         => $sl['lot_number'] ?: '-',
                                'ref_number'         => $sl['ref_number'] ?: '-',
                                'qty_lot'            => (int)$sl['qty'],
                                'qty_sampling'       => (int)($sl['computed_sample_size'] ?? 0),
                                'sample_size'        => (int)($sl['computed_sample_size'] ?? 0),
                                'reject_number'      => $lRejectNum,
                                'total_ng'           => $lTotalNg,
                                'ng_count'           => $lTotalNg,
                                'defects'            => $sl['defects'] ?? [],
                                'defect_summary'     => $defStr,
                                'status_code'        => $rowStatusCode,
                                'status_label'       => $rowStatusLabel,
                                'status_badge'       => $rowStatusLabel,
                                'lot_result'         => ($rowStatusCode === 'passed') ? 'passed' : $sl['lot_result'],
                                'lot_status'         => ($isReinspect && $rowStatusCode === 'passed') ? 'reinspected' : ($sl['lot_status'] ?? ''),
                                'session_status'     => $sess['session_status'],
                                'inspector_name'     => $sess['inspector_name'],
                                'attempt_type'       => $sl['attempt_type'] ?? 'standard',
                                'attempt_label'      => $sl['attempt_label'] ?? '',
                                'is_reinspected_lot' => $isReinspect,
                                'is_reinspected_attempt' => !empty($sl['is_reinspected_attempt']),
                                'is_initial_attempt' => $isInitial,
                                'action_notes'       => $sl['action_notes'] ?? ($sess['reinspection_notes'] ?? ''),
                                'reinspection_type'  => $sess['reinspection_type'] ?? '',
                                'replaced_by_lot_id' => $sl['replaced_by_lot_id'] ?? null,
                                'remarks'            => $sl['remarks'] ?? '',
                                'has_subst_log'      => !empty($sl['has_subst_log']),
                                'has_sorted_ng'      => !empty($sl['has_sorted_ng']),
                            ];
                        }
                    }
                }
            }

            // Calculate KPI Stats across ALL flat lot rows
            $stats['total_count']       = count($flatLotRows);
            $stats['total_qty_pcs']     = 0;
            $stats['passed_count']      = 0;
            $stats['rejected_count']    = 0;
            $stats['total_ng_pcs']      = 0;
            $stats['total_sample_pcs']  = 0;

            foreach ($flatLotRows as $fl) {
                if (empty($fl['is_reinspected_attempt'])) {
                    $stats['total_qty_pcs'] += (int)$fl['qty_lot'];
                }
                if ($fl['status_code'] === 'passed') {
                    $stats['passed_count']++;
                } elseif ($fl['status_code'] === 'rejected') {
                    $stats['rejected_count']++;
                }
                $stats['total_ng_pcs']     += (int)$fl['total_ng'];
                $stats['total_sample_pcs'] += (int)$fl['qty_sampling'];
            }
            $myLotsTabCount = $stats['total_count'];

            // Filter status, jenis inspeksi, dan search keyword/QR for display
            $filteredLots = array_filter($flatLotRows, function($fl) use ($statusFilter, $typeFilter, $search, $isQrSearch, $qrSearchMyLots) {
                if ($typeFilter !== 'all' && ($fl['inspection_type'] ?? 'kanban') !== $typeFilter) {
                    return false;
                }
                if ($statusFilter === 'in_progress') {
                    if ($fl['status_code'] !== 'in_progress') return false;
                } elseif ($statusFilter === 'completed' || $statusFilter === 'passed') {
                    if ($fl['status_code'] !== 'passed') return false;
                } elseif ($statusFilter === 'rejected') {
                    if ($fl['status_code'] !== 'rejected') return false;
                }

                if ($search !== '') {
                    if ($isQrSearch) {
                        if (!empty($qrSearchMyLots['Z5'])) {
                            if (stripos($fl['ref_number'] ?? '', $qrSearchMyLots['Z5']) === false) {
                                return false;
                            }
                        }
                        if (!empty($qrSearchMyLots['Z2'])) {
                            if (trim((string)($fl['lot_number'] ?? '')) !== trim((string)$qrSearchMyLots['Z2'])) {
                                return false;
                            }
                        }
                        if (!empty($qrSearchMyLots['Z1'])) {
                            if (stripos($fl['part_code'] ?? '', $qrSearchMyLots['Z1']) === false) {
                                return false;
                            }
                        }
                    } else {
                        $match = (
                            stripos($fl['lot_number'] ?? '', $search) !== false ||
                            stripos($fl['ref_number'] ?? '', $search) !== false ||
                            stripos($fl['part_code'] ?? '', $search) !== false ||
                            stripos($fl['part_name'] ?? '', $search) !== false ||
                            stripos($fl['defect_summary'] ?? '', $search) !== false ||
                            stripos($fl['status_label'] ?? '', $search) !== false ||
                            stripos($fl['inspector_name'] ?? '', $search) !== false ||
                            (string)($fl['session_id'] ?? '') === $search
                        );
                        if (!$match) return false;
                    }
                }

                return true;
            });

            // Sort filtered lots
            usort($filteredLots, function($a, $b) use ($sort) {
                $timeA = strtotime($a['scanned_at'] ?? '1970-01-01');
                $timeB = strtotime($b['scanned_at'] ?? '1970-01-01');
                if ($timeA === $timeB) {
                    $lotIdA = (int)($a['lot_id'] ?? 0);
                    $lotIdB = (int)($b['lot_id'] ?? 0);
                    if ($lotIdA === $lotIdB) {
                        $attA = ($a['attempt_type'] === 'reinspection') ? 2 : 1;
                        $attB = ($b['attempt_type'] === 'reinspection') ? 2 : 1;
                        return ($sort === 'ASC') ? ($attA - $attB) : ($attB - $attA);
                    }
                    return ($sort === 'ASC') ? ($lotIdA - $lotIdB) : ($lotIdB - $lotIdA);
                }
                return ($sort === 'ASC') ? ($timeA - $timeB) : ($timeB - $timeA);
            });

            // Badge counts (cached for 30s)
            if ($isBadgeCacheValid) {
                $kanbanTabCount        = (int)($cachedBadges['kanban'] ?? 0);
                $safetyStockTabCount   = (int)($cachedBadges['safety_stock'] ?? 0);
                $myInspectionsTabCount = (int)($cachedBadges['my_inspections'] ?? 0);
                $_SESSION['oqc_badge_cache'][$badgeCacheKey]['my_lots'] = $myLotsTabCount;
            } else {
                $kbBadgeWhereClause = implode(" AND ", $kbBaseWhere);
                $stmtKbBadge = $pdo->prepare("SELECT COUNT(DISTINCT k.id) FROM kanban_items k WHERE {$kbBadgeWhereClause}");
                $stmtKbBadge->execute($kbBaseParams);
                $kanbanTabCount = (int)$stmtKbBadge->fetchColumn();

                $ssBadgeWhereClause = implode(" AND ", $ssBaseWhere);
                $stmtSSBadge = $pdo->prepare("SELECT COUNT(DISTINCT k.id) FROM kanban_items k LEFT JOIN master_parts mp ON mp.part_code = k.item_code WHERE {$ssBadgeWhereClause}");
                $stmtSSBadge->execute($ssBaseParams);
                $safetyStockTabCount = (int)$stmtSSBadge->fetchColumn();

                $myBadgeWhereClause = implode(" AND ", $myBaseWhere);
                $stmtMyBadge = $pdo->prepare("SELECT COUNT(DISTINCT s.id) FROM inspection_sessions s WHERE {$myBadgeWhereClause}");
                $stmtMyBadge->execute($myBaseParams);
                $myInspectionsTabCount = (int)$stmtMyBadge->fetchColumn();

                $_SESSION['oqc_badge_cache'][$badgeCacheKey] = [
                    'time'           => time(),
                    'kanban'         => $kanbanTabCount,
                    'safety_stock'   => $safetyStockTabCount,
                    'my_inspections' => $myInspectionsTabCount,
                    'my_lots'        => $myLotsTabCount
                ];
            }

            // Pagination
            $totalItems = count($filteredLots);
            $totalPages = max(1, ceil($totalItems / $limit));
            if ($page > $totalPages) $page = $totalPages;
            $offset = ($page - 1) * $limit;

            $myLots = array_slice($filteredLots, $offset, $limit);

            // Ensure clean defects array per row
            unset($defectsByLot);
        }

    } catch (PDOException $e) {
        $kanbans = [];
        $ssKanbans = [];
        $ssSessions = [];
        $mySessions = [];
    }
}

$breadcrumbCategory = "OPERASIONAL";
if ($activeTab === 'safety_stock') {
    $pageTitle = "Workboard Safety Stock (Cek Fisik Gudang)";
    $pageSubtitle = "Pemeriksaan fisik Safety Stock langsung dari gudang OQC (murni sampling AQL QC, tanpa sisa box/overflow)";
} elseif ($activeTab === 'my_inspections') {
    $pageTitle = "Riwayat Inspeksi Saya";
    $pageSubtitle = "Riwayat seluruh sesi inspeksi oleh " . htmlspecialchars($currentUser['name'] ?? 'Inspektur') . " beserta rincian box / lot dan temuan cacat";
} elseif ($activeTab === 'my_lots') {
    $pageTitle = "Rincian Per Lot Saya";
    $pageSubtitle = "Daftar seluruh lot / box hasil scan oleh " . htmlspecialchars($currentUser['name'] ?? 'Inspektur') . " (Sheet 1 Flat Table View)";
} else {
    $pageTitle = "Jadwal & Riwayat Scan Kanban";
    $pageSubtitle = "Pusat jadwal scan kanban harian dan riwayat pengerjaan per sesi inspeksi OQC";
}

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300 min-w-0 max-w-full overflow-x-hidden" style="min-width: 0; max-width: 100%; overflow-x: hidden;">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-5 space-y-3.5 min-w-0 max-w-full w-full overflow-x-hidden" style="min-width: 0; max-width: 100%;">
        
        <?= render_flash() ?>

        <style>
        /* ── KPI Highlight Cards (Compact & Single Row) ── */
        .kpi-grid-container {
            display: grid !important;
            grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
            gap: 8px !important;
            margin-top: 2px !important;
            margin-bottom: 2px !important;
        }
        .kpi-card {
            border-radius: 8px !important;
            padding: 8px 12px !important;
            display: block !important;
            transition: all 0.2s ease !important;
            box-shadow: 0 1px 2px rgba(0,0,0,0.04) !important;
            text-decoration: none !important;
        }
        .kpi-card:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.06) !important;
        }
        .kpi-card-total {
            background: linear-gradient(135deg, #e0f2fe 0%, #dbeafe 100%) !important;
            border: 1px solid #93c5fd !important;
            border-left: 4.5px solid #2563eb !important;
        }
        .kpi-card-uninspected {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%) !important;
            border: 1px solid #cbd5e1 !important;
            border-left: 4.5px solid #64748b !important;
        }
        .kpi-card-progress {
            background: linear-gradient(135deg, #fef3c7 0%, #ffedd5 100%) !important;
            border: 1px solid #fcd34d !important;
            border-left: 4.5px solid #f59e0b !important;
        }
        .kpi-card-completed {
            background: linear-gradient(135deg, #dcfce7 0%, #d1fae5 100%) !important;
            border: 1px solid #86efac !important;
            border-left: 4.5px solid #10b981 !important;
        }
        .kpi-card-rejected {
            background: linear-gradient(135deg, #ffe4e6 0%, #fecdd3 100%) !important;
            border: 1px solid #fda4af !important;
            border-left: 4.5px solid #e11d48 !important;
        }
        .kpi-card-ss {
            background: linear-gradient(135deg, #f3e8ff 0%, #ede9fe 100%) !important;
            border: 1px solid #d8b4fe !important;
            border-left: 4.5px solid #9333ea !important;
        }

        /* ── Workboard Table Row Status Colors ── */
        .row-status-overdue > td {
            background-color: #fff1f2 !important;
        }
        .row-status-overdue:hover > td {
            background-color: #ffe4e6 !important;
        }
        .row-status-overdue > td:first-child {
            border-left: 5px solid #e11d48 !important;
        }

        .row-status-active > td {
            background-color: #fffbeb !important;
        }
        .row-status-active:hover > td {
            background-color: #fef3c7 !important;
        }
        .row-status-active > td:first-child {
            border-left: 5px solid #d97706 !important;
        }

        .row-status-partial > td {
            background-color: #fef3c7 !important;
        }
        .row-status-partial:hover > td {
            background-color: #fde68a !important;
        }
        .row-status-partial > td:first-child {
            border-left: 5px solid #f59e0b !important;
        }

        .row-status-completed > td {
            background-color: #dcfce7 !important;
        }
        .row-status-completed:hover > td {
            background-color: #bbf7d0 !important;
        }
        .row-status-completed > td:first-child {
            border-left: 5px solid #10b981 !important;
        }

        .row-status-rejected > td {
            background-color: #fff1f2 !important;
        }
        .row-status-rejected:hover > td {
            background-color: #ffe4e6 !important;
        }
        .row-status-rejected > td:first-child {
            border-left: 5px solid #be123c !important;
        }

        .row-status-uninspected > td {
            background-color: #ffffff !important;
        }
        .row-status-uninspected:hover > td {
            background-color: #f8fafc !important;
        }
        .row-status-uninspected > td:first-child {
            border-left: 5px solid #cbd5e1 !important;
        }

        /* ── Sub-Table Session Row Status Colors (Accordion Detail Sesi) ── */
        .sub-row-passed > td {
            background-color: #f0fdf4 !important;
        }
        .sub-row-passed:hover > td {
            background-color: #dcfce7 !important;
        }
        .sub-row-passed > td:first-child {
            border-left: 4px solid #16a34a !important;
        }

        .sub-row-progress > td {
            background-color: #fffbeb !important;
        }
        .sub-row-progress:hover > td {
            background-color: #fef3c7 !important;
        }
        .sub-row-progress > td:first-child {
            border-left: 4px solid #d97706 !important;
        }

        .sub-row-rejected > td {
            background-color: #fff1f2 !important;
        }
        .sub-row-rejected:hover > td {
            background-color: #ffe4e6 !important;
        }
        .sub-row-rejected > td:first-child {
            border-left: 4px solid #dc2626 !important;
        }

        /* KPI numbers and label spacing */
        .kpi-stat-value {
            font-size: 20px !important;
            font-weight: 900 !important;
            line-height: 1 !important;
            display: inline-block !important;
        }
        .kpi-stat-unit {
            font-size: 11px !important;
            font-weight: 700 !important;
            margin-left: 4px !important;
            display: inline-block !important;
        }
        .kpi-card-my {
            background: linear-gradient(135deg, #e0e7ff 0%, #ede9fe 100%) !important;
            border: 1px solid #c7d2fe !important;
            border-left: 4.5px solid #4f46e5 !important;
        }

        /* ── Theme Background Fallback for Offline Tailwind (Purple & Indigo) ── */
        .bg-purple-600 {
            background-color: #9333ea !important;
        }
        .bg-purple-700 {
            background-color: #7e22ce !important;
        }
        .hover\:bg-purple-700:hover {
            background-color: #7e22ce !important;
        }
        .active\:bg-purple-800:active {
            background-color: #6b21a8 !important;
        }
        .bg-indigo-600 {
            background-color: #4f46e5 !important;
        }
        .bg-indigo-700 {
            background-color: #4338ca !important;
        }
        .hover\:bg-indigo-700:hover {
            background-color: #4338ca !important;
        }
        .active\:bg-indigo-800:active {
            background-color: #3730a3 !important;
        }

        /* ── My Lots Horizontal Scroll Styling ── */
        #my-lots-table-wrapper {
            scrollbar-width: thin;
            scrollbar-color: #94a3b8 #f1f5f9;
        }
        #my-lots-table-wrapper::-webkit-scrollbar {
            height: 10px;
        }
        #my-lots-table-wrapper::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 6px;
        }
        #my-lots-table-wrapper::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 6px;
            border: 2px solid #f1f5f9;
        }
        #my-lots-table-wrapper::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        /* ── Segmented Control Pill Tabs ── */
        .oqc-tab-container {
            display: inline-flex;
            align-items: center;
            background-color: #f1f5f9;
            padding: 4px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            gap: 4px;
            flex-wrap: wrap;
        }
        .oqc-tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 14px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 800;
            text-decoration: none;
            transition: all 0.15s ease-in-out;
            cursor: pointer;
            border: none;
            outline: none;
            line-height: 1.25;
            user-select: none;
        }
        .oqc-tab-btn.inactive {
            background-color: transparent;
            color: #64748b;
        }
        .oqc-tab-btn.inactive:hover {
            background-color: #e2e8f0;
            color: #0f172a;
        }
        .oqc-tab-btn.inactive .oqc-tab-badge {
            background-color: #e2e8f0;
            color: #475569;
        }
        .oqc-tab-btn.inactive:hover .oqc-tab-badge {
            background-color: #cbd5e1;
            color: #0f172a;
        }
        .oqc-tab-btn.active-kanban {
            background-color: #2563eb;
            color: #ffffff;
            box-shadow: 0 1px 3px rgba(37, 99, 235, 0.35);
        }
        .oqc-tab-btn.active-safety {
            background-color: #7c3aed;
            color: #ffffff;
            box-shadow: 0 1px 3px rgba(124, 58, 237, 0.35);
        }
        .oqc-tab-btn.active-mylots {
            background-color: #4f46e5;
            color: #ffffff;
            box-shadow: 0 1px 3px rgba(79, 70, 229, 0.35);
        }
        .oqc-tab-badge {
            padding: 2px 7.5px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 800;
            line-height: 1.2;
            transition: all 0.15s ease-in-out;
        }
        .oqc-tab-btn[class*="active-"] .oqc-tab-badge {
            background-color: rgba(255, 255, 255, 0.22);
            color: #ffffff;
        }

        /* ── Preset Period Buttons ── */
        .oqc-period-container {
            display: inline-flex;
            align-items: center;
            background-color: #f1f5f9;
            padding: 4px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            gap: 3px;
            flex-shrink: 0;
        }
        .oqc-period-btn {
            padding: 5px 12px;
            border-radius: 7px;
            font-size: 12px;
            font-weight: 800;
            cursor: pointer;
            border: none;
            outline: none;
            transition: all 0.15s ease-in-out;
            line-height: 1.25;
            user-select: none;
        }
        .oqc-period-btn.inactive {
            background-color: transparent;
            color: #64748b;
        }
        .oqc-period-btn.inactive:hover {
            background-color: #e2e8f0;
            color: #0f172a;
        }

        /* ── Lot Row Status Backgrounds & Accents ── */
        tr.lot-row-passed {
            background-color: #f0fdf4 !important;
            border-left: 3.5px solid #22c55e !important;
            transition: background-color 0.15s ease-in-out;
        }
        tr.lot-row-passed:hover {
            background-color: #dcfce7 !important;
        }
        tr.lot-row-rejected {
            background-color: #fff1f2 !important;
            border-left: 3.5px solid #ef4444 !important;
            transition: background-color 0.15s ease-in-out;
        }
        tr.lot-row-rejected:hover {
            background-color: #ffe4e6 !important;
        }
        tr.lot-row-in_progress {
            background-color: #fffbeb !important;
            border-left: 3.5px solid #f59e0b !important;
            transition: background-color 0.15s ease-in-out;
        }
        tr.lot-row-in_progress:hover {
            background-color: #fef3c7 !important;
        }
        </style>

        <?php
        $tabThemeBg = '#2563eb';
        $tabThemeBorder = '#1d4ed8';
        $tabFocusRing = 'focus:ring-blue-500';
        if ($activeTab === 'safety_stock') {
            $tabThemeBg = '#7c3aed';
            $tabThemeBorder = '#6d28d9';
            $tabFocusRing = 'focus:ring-purple-500';
        } elseif ($activeTab === 'my_lots') {
            $tabThemeBg = '#4f46e5';
            $tabThemeBorder = '#4338ca';
            $tabFocusRing = 'focus:ring-indigo-500';
        }
        ?>

        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <!-- TAB SWITCHER: KANBAN DELIVERY vs SAFETY STOCK vs RIWAYAT SAYA      -->
        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <div class="flex items-center justify-between gap-3 border-b border-slate-200 pb-3">
            <div class="oqc-tab-container">
                <!-- Tab 1: Kanban Delivery (Biru Khas) -->
                <?php $isKanban = ($activeTab === 'kanban'); ?>
                <a href="?tab=kanban&period=<?= urlencode($period) ?>" 
                   class="oqc-tab-btn <?= $isKanban ? 'active-kanban' : 'inactive' ?>">
                    <svg class="w-4 h-4 <?= $isKanban ? 'text-white' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
                    </svg>
                    <span>Kanban Delivery</span>
                    <span class="oqc-tab-badge">
                        <?= number_format($kanbanTabCount) ?>
                    </span>
                </a>

                <!-- Tab 2: Safety Stock (Ungu Khas) -->
                <?php $isSafety = ($activeTab === 'safety_stock'); ?>
                <a href="?tab=safety_stock&period=<?= urlencode($period) ?>" 
                   class="oqc-tab-btn <?= $isSafety ? 'active-safety' : 'inactive' ?>">
                    <svg class="w-4 h-4 <?= $isSafety ? 'text-white' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                    </svg>
                    <span>Safety Stock (Cek Fisik)</span>
                    <span class="oqc-tab-badge">
                        <?= number_format($safetyStockTabCount) ?>
                    </span>
                </a>

                <!-- Tab 3: Riwayat Saya (Indigo Khas) -->
                <?php $isMyLots = ($activeTab === 'my_lots'); ?>
                <a href="?tab=my_lots&period=<?= urlencode($period) ?>" 
                   class="oqc-tab-btn <?= $isMyLots ? 'active-mylots' : 'inactive' ?>">
                    <svg class="w-4 h-4 <?= $isMyLots ? 'text-white' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                    </svg>
                    <span>Riwayat Saya</span>
                    <span class="oqc-tab-badge">
                        <?= number_format($myLotsTabCount) ?>
                    </span>
                </a>
            </div>

            <div class="hidden sm:flex items-center gap-1.5 text-[11px] text-slate-500 font-medium">
                <?php if ($activeTab === 'safety_stock'): ?>
                    <span class="w-2 h-2 rounded-full bg-purple-500"></span>
                    <span>Hanya pemeriksaan fisik gudang & AQL (tanpa sisa box/overflow)</span>
                <?php elseif ($activeTab === 'my_inspections'): ?>
                    <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
                    <span>Riwayat seluruh sesi inspeksi oleh <?= htmlspecialchars($currentUser['name'] ?? 'Inspektur') ?></span>
                <?php elseif ($activeTab === 'my_lots'): ?>
                    <span class="w-2 h-2 rounded-full" style="background-color: #4f46e5;"></span>
                    <span>Daftar seluruh rincian box / lot yang diinspeksi oleh <?= htmlspecialchars($currentUser['name'] ?? 'Inspektur') ?> (Sheet 1 View)</span>
                <?php else: ?>
                    <span class="w-2 h-2 rounded-full bg-blue-500"></span>
                    <span>Jadwal pengiriman Kanban & Workboard harian</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <!-- TOOLBAR: PRESET PERIODE + SEARCH + FILTER + TOMBOL MULAI           -->
        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <div class="bg-white border border-slate-200/90 rounded-xl p-3 shadow-xs space-y-2.5">
            <!-- Baris 1: Preset Periode + Tombol Mulai Inspeksi Baru -->
            <div class="flex items-center justify-between gap-2.5 flex-wrap">
                <!-- Period Preset Pills -->
                <div class="oqc-period-container">
                    <?php
                    $periodButtons = [
                        'today' => 'Hari Ini',
                        'this_week' => 'Minggu Ini',
                        'this_month' => 'Bulan Ini',
                        'all' => 'Semua'
                    ];
                    foreach ($periodButtons as $pKey => $pLabel):
                        $isActivePeriod = ($period === $pKey);
                        $activePeriodStyle = $isActivePeriod 
                            ? "background-color: {$tabThemeBg}; color: #ffffff; border: 1px solid {$tabThemeBorder}; box-shadow: 0 1px 3px rgba(0,0,0,0.18);" 
                            : "";
                    ?>
                        <button type="button" onclick="setKanbanPeriod('<?= $pKey ?>')" 
                                style="<?= $activePeriodStyle ?>"
                                class="oqc-period-btn <?= $isActivePeriod ? 'active' : 'inactive' ?>">
                            <?= $pLabel ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="flex items-center gap-2 flex-wrap">
                    <?php if ($activeTab === 'kanban'): ?>
                        <!-- Real-Time Live Sync Indicator (Khusus Kanban Workboard) -->
                        <div id="live-sync-indicator" class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-emerald-50 border border-emerald-200 rounded-lg text-emerald-700 text-[11px] font-bold shadow-2xs" title="Sinkronisasi status otomatis antar-laptop setiap 4 detik">
                            <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                            <span id="live-sync-text">Live Sync</span>
                            <span id="live-sync-time" class="text-[9.5px] text-emerald-600 font-semibold"></span>
                        </div>

                        <!-- Tombol Action: Mulai Inspeksi Baru (Kanban) -->
                        <a href="<?= base_url('modules/inspection/session.php?scan_new=1') ?>" 
                           class="px-3.5 py-1.5 bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white font-extrabold text-xs rounded-lg shadow-xs transition-all inline-flex items-center gap-1.5 whitespace-nowrap">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"></path>
                            </svg>
                            <span>Mulai Inspeksi Baru</span>
                        </a>
                    <?php elseif ($activeTab === 'safety_stock'): ?>
                        <!-- Tombol Action: Cek Fisik Safety Stock -->
                        <a href="<?= base_url('modules/inspection/session.php?scan_new=1&type=safety_stock&plan_type=safety_stock') ?>" 
                           class="px-3.5 py-1.5 bg-purple-600 hover:bg-purple-700 active:bg-purple-800 text-white font-extrabold text-xs rounded-lg shadow-xs transition-all inline-flex items-center gap-1.5 whitespace-nowrap">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"></path>
                            </svg>
                            <span>Cek Fisik Safety Stock</span>
                        </a>
                    <?php else: ?>
                        <!-- Tombol Action: Export Excel (Riwayat Saya / Rincian Per Lot) -->
                        <?php
                        $exportParams = [
                            'tab'        => ($activeTab === 'my_lots' ? 'my_lots' : 'my_inspections'),
                            'period'     => $period,
                            'status'     => $statusFilter,
                            'search'     => $search,
                            'start_date' => $startDate,
                            'end_date'   => $endDate,
                            'sort'       => ($sort === 'ASC' ? 'asc' : 'desc')
                        ];
                        if (!empty($inspectorFilterId)) {
                            $exportParams['inspector_id'] = $inspectorFilterId;
                        }
                        $exportUrl = base_url('modules/inspection/export_excel.php?' . http_build_query(array_filter($exportParams, fn($v) => $v !== '' && $v !== null)));
                        ?>
                        <a href="<?= $exportUrl ?>" target="_blank"
                           class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white font-extrabold text-xs rounded-lg shadow-xs transition-all inline-flex items-center gap-1.5 whitespace-nowrap"
                           title="Export data ke file Excel format detail lot">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                            <span>Export Excel</span>
                        </a>

                        <!-- Tombol Action: Mulai Inspeksi Baru (Riwayat Saya / Lot) -->
                        <a href="<?= base_url('modules/inspection/session.php?scan_new=1') ?>" 
                           style="background-color: <?= $tabThemeBg ?>;"
                           class="px-3.5 py-1.5 hover:opacity-90 active:opacity-100 text-white font-extrabold text-xs rounded-lg shadow-xs transition-all inline-flex items-center gap-1.5 whitespace-nowrap">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"></path>
                            </svg>
                            <span>Mulai Inspeksi Baru</span>
                        </a>
                    <?php endif; ?>
                </div>

            </div>

            <!-- Baris 2: Search Input, Status Dropdown, Limit, Date Range, Filter & Reset -->
            <form id="kanbanFilterForm" action="" method="GET" class="flex items-center gap-2 text-xs flex-wrap border-t border-slate-100 pt-3">
                <input type="hidden" name="tab" id="kanbanTabInput" value="<?= htmlspecialchars($activeTab) ?>">
                <input type="hidden" name="period" id="kanbanPeriodInput" value="<?= htmlspecialchars($period) ?>">

                <!-- Search Field: Ruang nafas lega & teks tidak mepet -->
                <div class="relative" style="flex: 1 1 240px; min-width: 200px;">
                    <div style="position: absolute; left: 12px; top: 0; bottom: 0; display: flex; align-items: center; pointer-events: none;">
                        <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                    </div>
                    <input type="text" name="search" id="kanbanSearchInput" autofocus autocomplete="off" value="<?= htmlspecialchars($search) ?>" 
                           placeholder="<?= ($activeTab === 'safety_stock') ? 'Cari Sesi, Part, Inspektur...' : (($activeTab === 'my_inspections') ? 'Cari Sesi, Kanban, Part, Lot, Ref...' : (($activeTab === 'my_lots') ? 'Cari Lot No, Ref No, Part, Defect...' : 'Cari Kanban, Part, Customer... (Scan/Ketik)')) ?>" 
                           style="padding-left: 38px; padding-right: 14px; height: 38px; width: 100%;" 
                           class="w-full bg-slate-50 border border-slate-300 rounded-lg text-xs font-semibold text-slate-800 placeholder-slate-400 focus:bg-white focus:outline-none focus:ring-2 <?= $tabFocusRing ?> focus:border-transparent transition-all">
                </div>

                <?php if ($activeTab === 'my_lots'): ?>
                <!-- Jenis Inspeksi Filter (Khusus Tab Rincian Lot Saya) -->
                <div class="w-36 flex-shrink-0">
                    <select name="inspection_type" onchange="this.form.submit()" 
                            style="height: 38px;" 
                            class="w-full px-2.5 bg-slate-50 border border-slate-300 rounded-lg text-xs font-bold text-slate-700 focus:bg-white focus:outline-none focus:ring-2 <?= $tabFocusRing ?> transition-all cursor-pointer">
                        <option value="all" <?= ($typeFilter === 'all') ? 'selected' : '' ?>>Semua Jenis</option>
                        <option value="kanban" <?= ($typeFilter === 'kanban') ? 'selected' : '' ?>>Kanban</option>
                        <option value="safety_stock" <?= ($typeFilter === 'safety_stock') ? 'selected' : '' ?>>Safety Stock</option>
                    </select>
                </div>
                <?php endif; ?>

                <!-- Status Filter -->
                <div class="w-44 flex-shrink-0">
                    <select name="status" onchange="this.form.submit()" 
                            style="height: 38px;" 
                            class="w-full px-2.5 bg-slate-50 border border-slate-300 rounded-lg text-xs font-bold text-slate-700 focus:bg-white focus:outline-none focus:ring-2 <?= $tabFocusRing ?> transition-all cursor-pointer">
                        <?php if ($activeTab === 'safety_stock' || $activeTab === 'my_inspections' || $activeTab === 'my_lots'): ?>
                            <option value="all" <?= ($statusFilter === 'all') ? 'selected' : '' ?>>Semua Status</option>
                            <option value="in_progress" <?= ($statusFilter === 'in_progress') ? 'selected' : '' ?>>Sedang Dikerjakan</option>
                            <option value="completed" <?= ($statusFilter === 'completed' || $statusFilter === 'passed') ? 'selected' : '' ?>>Selesai (Passed)</option>
                            <option value="rejected" <?= ($statusFilter === 'rejected') ? 'selected' : '' ?>>Ada Reject</option>
                        <?php else: ?>
                            <option value="all" <?= ($statusFilter === 'all') ? 'selected' : '' ?>>Semua Status</option>
                            <option value="uninspected" <?= ($statusFilter === 'uninspected') ? 'selected' : '' ?>>Antrean (Belum Mulai)</option>
                            <option value="in_progress" <?= ($statusFilter === 'in_progress') ? 'selected' : '' ?>>Sedang Dikerjakan / Parsial</option>
                            <option value="partial" <?= ($statusFilter === 'partial') ? 'selected' : '' ?>>Hanya Parsial (Belum Penuh)</option>
                            <option value="completed" <?= ($statusFilter === 'completed') ? 'selected' : '' ?>>Selesai (Passed)</option>
                            <option value="rejected" <?= ($statusFilter === 'rejected') ? 'selected' : '' ?>>Ada Reject</option>
                        <?php endif; ?>
                    </select>
                </div>

                <?php if ($activeTab === 'my_inspections' || $activeTab === 'my_lots'): ?>
                <!-- Sort Order (Khusus Tab Riwayat Saya & Rincian Per Lot) -->
                <div style="height: 38px;" class="flex items-center gap-1.5 bg-slate-50 border border-slate-300 rounded-lg px-2.5 text-xs text-slate-600 flex-shrink-0">
                    <svg class="w-3.5 h-3.5 text-slate-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h13M3 8h9m-9 4h6m4 0l4-4m0 0l4 4m-4-4v12"/>
                    </svg>
                    <select name="sort" onchange="this.form.submit()" class="bg-transparent border-0 p-0 text-xs font-black text-slate-800 focus:outline-none cursor-pointer">
                        <option value="desc" <?= ($sort === 'DESC') ? 'selected' : '' ?>>Terbaru</option>
                        <option value="asc" <?= ($sort === 'ASC') ? 'selected' : '' ?>>Terlama</option>
                    </select>
                </div>
                <?php endif; ?>

                <!-- Rows per page (Limit Selector) -->
                <div style="height: 38px;" class="flex items-center gap-1.5 bg-slate-50 border border-slate-300 rounded-lg px-2.5 text-xs text-slate-600 flex-shrink-0">
                    <span class="text-[10px] font-bold text-slate-500">Baris:</span>
                    <select name="limit" onchange="this.form.submit()" class="bg-transparent border-0 p-0 text-xs font-black text-slate-800 focus:outline-none cursor-pointer">
                        <option value="10" <?= ($limit == 10) ? 'selected' : '' ?>>10</option>
                        <option value="25" <?= ($limit == 25) ? 'selected' : '' ?>>25</option>
                        <option value="50" <?= ($limit == 50) ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= ($limit == 100) ? 'selected' : '' ?>>100 (Semua)</option>
                    </select>
                </div>

                <!-- Date Range (Custom) -->
                <div style="height: 38px;" class="flex items-center space-x-1.5 bg-slate-50 border border-slate-300 rounded-lg px-2.5 text-xs text-slate-600 flex-shrink-0">
                    <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" class="bg-transparent border-0 p-0 text-xs text-slate-800 focus:outline-none font-bold">
                    <span class="text-slate-400 font-bold text-[10px]">s/d</span>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" class="bg-transparent border-0 p-0 text-xs text-slate-800 focus:outline-none font-bold">
                </div>

                <!-- Filter & Reset Buttons -->
                <button type="submit" style="height: 38px;" class="px-3.5 bg-slate-800 hover:bg-slate-900 active:bg-black text-white font-bold text-xs rounded-lg shadow-xs transition-all flex items-center gap-1.5 flex-shrink-0 cursor-pointer">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path>
                    </svg>
                    <span>Filter</span>
                </button>

                <?php if (!empty($search) || $statusFilter !== 'all' || ($typeFilter ?? 'all') !== 'all' || $period !== 'today' || !empty($startDate) || !empty($endDate) || (($activeTab === 'my_inspections' || $activeTab === 'my_lots') && $sort === 'ASC')): ?>
                    <a href="index.php?tab=<?= $activeTab ?>" style="height: 38px; display: inline-flex; align-items: center;" class="px-3 bg-rose-50 hover:bg-rose-100 text-rose-600 font-bold text-xs rounded-lg border border-rose-200 transition-all flex-shrink-0">
                        Reset
                    </a>
                <?php endif; ?>

            </form>

        </div>

        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <!-- KPI BAR: STATISTIK RINGKAS                                          -->
        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <!-- KPI BAR: STATISTIK RINGKAS                                          -->
        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <?php if ($activeTab === 'safety_stock'): ?>
            <!-- KPI SAFETY STOCK (CEK FISIK) -->
            <div class="kpi-grid-container">
                <!-- Stat 1: Total Sesi Cek Fisik -->
                <a href="?tab=safety_stock&status=all&period=<?= urlencode($period) ?>" 
                   class="kpi-card kpi-card-ss"
                   title="Klik untuk tampilkan semua item Safety Stock">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-purple-800 uppercase tracking-wider">TOTAL SAFETY STOCK</div>
                        <span class="w-5 h-5 rounded bg-purple-200/80 text-purple-700 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline flex-wrap gap-1">
                        <span class="kpi-stat-value text-purple-950"><?= number_format($stats['total_count']) ?></span>
                        <span class="kpi-stat-unit text-purple-700">Item</span>
                        <span class="text-[9.5px] text-purple-900 font-extrabold bg-purple-200/70 border border-purple-300/80 px-1.5 py-0.5 rounded ml-1"><?= number_format($stats['total_lots'] ?? 0) ?> Lot (<?= number_format($stats['total_qty_pcs'] ?? 0) ?> pcs)</span>
                    </div>
                </a>

                <!-- Stat 2: Berjalan -->
                <a href="?tab=safety_stock&status=in_progress&period=<?= urlencode($period) ?>" 
                   class="kpi-card kpi-card-progress"
                   title="Klik untuk filter Sedang Berjalan">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-amber-900 uppercase tracking-wider">BERJALAN</div>
                        <span class="w-5 h-5 rounded bg-amber-200/80 text-amber-800 flex items-center justify-center flex-shrink-0 relative">
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-ping absolute"></span>
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-600"></span>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline flex-wrap gap-1">
                        <span class="kpi-stat-value text-amber-950"><?= number_format($stats['in_progress_count']) ?></span>
                        <span class="kpi-stat-unit text-amber-700">Item</span>
                        <span class="text-[9.5px] text-amber-900 font-extrabold bg-amber-200/70 border border-amber-300/80 px-1.5 py-0.5 rounded ml-1"><?= number_format($stats['in_progress_lots'] ?? 0) ?> Lot (<?= number_format($stats['in_progress_qty_pcs'] ?? 0) ?> pcs)</span>
                    </div>
                </a>

                <!-- Stat 3: Passed (Selesai) -->
                <a href="?tab=safety_stock&status=completed&period=<?= urlencode($period) ?>" 
                   class="kpi-card kpi-card-completed"
                   title="Klik untuk filter Selesai">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-emerald-900 uppercase tracking-wider">SELESAI (PASSED)</div>
                        <span class="w-5 h-5 rounded bg-emerald-200/80 text-emerald-800 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline flex-wrap gap-1">
                        <span class="kpi-stat-value text-emerald-950"><?= number_format($stats['passed_count']) ?></span>
                        <span class="kpi-stat-unit text-emerald-700">Item</span>
                        <span class="text-[9.5px] text-emerald-900 font-extrabold bg-emerald-200/70 border border-emerald-300/80 px-1.5 py-0.5 rounded ml-1"><?= number_format($stats['passed_lots'] ?? 0) ?> Lot (<?= number_format($stats['passed_qty_pcs'] ?? 0) ?> pcs)</span>
                    </div>
                </a>

                <!-- Stat 4: Reject -->
                <a href="?tab=safety_stock&status=rejected&period=<?= urlencode($period) ?>" 
                   class="kpi-card kpi-card-rejected"
                   title="Klik untuk filter Rejected">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-rose-800 uppercase tracking-wider">REJECTED</div>
                        <span class="w-5 h-5 rounded bg-rose-200/80 text-rose-700 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline flex-wrap gap-1">
                        <span class="kpi-stat-value text-rose-950"><?= number_format($stats['rejected_count']) ?></span>
                        <span class="kpi-stat-unit text-rose-700">Item</span>
                        <span class="text-[9.5px] text-rose-900 font-extrabold bg-rose-200/70 border border-rose-300/80 px-1.5 py-0.5 rounded ml-1"><?= number_format($stats['rejected_lots'] ?? 0) ?> Lot (<?= number_format($stats['rejected_qty_pcs'] ?? 0) ?> pcs)</span>
                    </div>
                </a>
            </div>

        <?php elseif ($activeTab === 'my_lots'): ?>
            <!-- KPI RINCIAN PER LOT SAYA -->
            <div class="kpi-grid-container">
                <!-- Stat 1: Total Lot Saya -->
                <a href="?tab=my_lots&status=all&period=<?= urlencode($period) ?>" 
                   class="kpi-card" style="background: linear-gradient(135deg, #e0f2fe 0%, #e0e7ff 100%) !important; border: 1px solid #bae6fd !important; border-left: 4.5px solid #0284c7 !important;"
                   title="Klik untuk tampilkan semua lot inspeksi Anda">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-sky-900 uppercase tracking-wider">TOTAL LOT SAYA</div>
                        <span class="w-5 h-5 rounded bg-sky-200/80 text-sky-700 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline flex-wrap">
                        <span class="kpi-stat-value text-sky-950"><?= number_format($stats['total_count']) ?></span>
                        <span class="kpi-stat-unit text-sky-700">Lot/Box</span>
                        <span class="text-[9px] text-sky-800 font-extrabold bg-sky-200/60 px-1 py-0.2 rounded ml-1">(<?= number_format($stats['total_qty_pcs']) ?> pcs)</span>
                    </div>
                </a>

                <!-- Stat 2: Passed (OK) -->
                <a href="?tab=my_lots&status=completed&period=<?= urlencode($period) ?>" 
                   class="kpi-card kpi-card-completed"
                   title="Klik untuk filter Lot Passed">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-emerald-900 uppercase tracking-wider">LOT PASSED</div>
                        <span class="w-5 h-5 rounded bg-emerald-200/80 text-emerald-800 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline">
                        <span class="kpi-stat-value text-emerald-950"><?= number_format($stats['passed_count']) ?></span>
                        <span class="kpi-stat-unit text-emerald-700">Lot</span>
                    </div>
                </a>

                <!-- Stat 3: Rejected (NG) -->
                <a href="?tab=my_lots&status=rejected&period=<?= urlencode($period) ?>" 
                   class="kpi-card kpi-card-rejected"
                   title="Klik untuk filter Lot Rejected">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-rose-800 uppercase tracking-wider">LOT REJECTED</div>
                        <span class="w-5 h-5 rounded bg-rose-200/80 text-rose-700 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline flex-wrap">
                        <span class="kpi-stat-value text-rose-950"><?= number_format($stats['rejected_count']) ?></span>
                        <span class="kpi-stat-unit text-rose-700">Lot</span>
                        <?php if ($stats['total_ng_pcs'] > 0): ?>
                            <span class="text-[9px] text-rose-800 font-extrabold bg-rose-200/60 px-1 py-0.2 rounded ml-1">(<?= number_format($stats['total_ng_pcs']) ?> pcs NG)</span>
                        <?php endif; ?>
                    </div>
                </a>

                <!-- Stat 4: Total Sampling QTY -->
                <div class="kpi-card" style="background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%) !important; border: 1px solid #e2e8f0 !important; border-left: 4.5px solid #64748b !important;">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-slate-800 uppercase tracking-wider">TOTAL SAMPLING (AQL)</div>
                        <span class="w-5 h-5 rounded bg-slate-200 text-slate-700 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline">
                        <span class="kpi-stat-value text-slate-900"><?= number_format($stats['total_sample_pcs']) ?></span>
                        <span class="kpi-stat-unit text-slate-600">pcs</span>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- KPI KANBAN DELIVERY (RICH CARDS) -->
            <div class="kpi-grid-container">
                <!-- Stat 1: Total -->
                <a href="?tab=kanban&status=all&period=<?= urlencode($period) ?>" 
                   class="kpi-card kpi-card-total"
                   title="Klik untuk tampilkan semua status">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-blue-800 uppercase tracking-wider">TOTAL KANBAN</div>
                        <span class="w-5 h-5 rounded bg-blue-200/70 text-blue-700 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline">
                        <span class="kpi-stat-value text-blue-900"><?= number_format($stats['total_kanban']) ?></span>
                        <span class="kpi-stat-unit text-blue-700">Kanban</span>
                    </div>
                </a>

                <!-- Stat 2: Belum Mulai -->
                <a href="?tab=kanban&status=uninspected&period=<?= urlencode($period) ?>" 
                   class="kpi-card kpi-card-uninspected"
                   title="Klik untuk filter Belum Mulai">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-slate-700 uppercase tracking-wider">BELUM MULAI</div>
                        <span class="w-5 h-5 rounded bg-slate-200 text-slate-700 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline">
                        <span class="kpi-stat-value text-slate-800"><?= number_format($stats['uninspected_count']) ?></span>
                        <span class="kpi-stat-unit text-slate-600">Kanban</span>
                    </div>
                </a>

                <!-- Stat 3: Berjalan -->
                <a href="?tab=kanban&status=in_progress&period=<?= urlencode($period) ?>" 
                   class="kpi-card kpi-card-progress"
                   title="Klik untuk filter Sedang Berjalan">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-amber-900 uppercase tracking-wider">BERJALAN</div>
                        <span class="w-5 h-5 rounded bg-amber-200/80 text-amber-800 flex items-center justify-center flex-shrink-0 relative">
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-ping absolute"></span>
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-600"></span>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline">
                        <span class="kpi-stat-value text-amber-950"><?= number_format($stats['in_progress_count']) ?></span>
                        <span class="kpi-stat-unit text-amber-700">Kanban</span>
                    </div>
                </a>

                <!-- Stat 4: Selesai -->
                <a href="?tab=kanban&status=completed&period=<?= urlencode($period) ?>" 
                   class="kpi-card kpi-card-completed"
                   title="Klik untuk filter Selesai">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-emerald-900 uppercase tracking-wider">SELESAI</div>
                        <span class="w-5 h-5 rounded bg-emerald-200/80 text-emerald-800 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline">
                        <span class="kpi-stat-value text-emerald-950"><?= number_format($stats['completed_count']) ?></span>
                        <span class="kpi-stat-unit text-emerald-700">Kanban</span>
                    </div>
                </a>
            </div>
            <?php if ($stats['in_progress_sessions'] > 0): ?>
                <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-2.5 flex items-center justify-between gap-3">
                    <span class="text-xs font-semibold text-amber-800">
                        <?= $stats['in_progress_sessions'] ?> sesi inspeksi belum selesai
                    </span>
                    <?php if ($statusFilter !== 'in_progress'): ?>
                        <a href="?tab=kanban&status=in_progress" style="background-color: #d97706; color: #ffffff;" class="px-2.5 py-1 bg-amber-600 hover:bg-amber-700 text-white text-[11px] font-bold rounded-lg transition-all flex-shrink-0">
                            Lihat Sesi Aktif
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <!-- TABEL DATA: KANBAN DELIVERY vs SAFETY STOCK vs RIWAYAT SAYA        -->
        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <div class="card p-0 overflow-hidden border border-slate-200/90 rounded-xl shadow-xs" style="min-width: 0; max-width: 100%;">
            
            <?php if ($activeTab === 'my_inspections'): ?>
                <!-- ═══════════════════════════════════════════════════════════════ -->
                <!-- TAB 3: RIWAYAT SAYA (INSPEKSI USER LOGIN & RINCIAN LOT)        -->
                <!-- ═══════════════════════════════════════════════════════════════ -->
                <div class="w-full divide-y divide-slate-200">
                    <?php if (empty($mySessions)): ?>
                        <div class="px-4 py-16 text-center text-slate-400">
                            <svg class="w-12 h-12 text-slate-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                            </svg>
                            <p class="font-bold text-slate-700 text-sm">Tidak ada riwayat inspeksi Anda pada filter ini.</p>
                            <p class="text-xs text-slate-400 mt-1">Ubah filter periode di atas atau klik tombol <b>"Mulai Inspeksi Baru"</b> untuk mulai melakukan inspeksi.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($mySessions as $idx => $s): 
                            $sid = (int)$s['id'];
                            $displaySessionNum = (int)($s['kanban_session_number'] ?? 1);
                            $sLots = $lotsBySession[$sid] ?? [];
                            $totalLabels = count($sLots);

                            $isClosed = !empty($s['closed_at']);
                            $durationText = $isClosed ? (($s['duration_minutes'] > 0) ? ($s['duration_minutes'] . ' mnt') : '< 1 mnt') : 'Berjalan';
                            $shiftName = getShiftName($s['started_at']);

                            // Kanban Progress & Sisa Qty
                            $isSafetyStock = ($s['inspection_type'] === 'safety_stock');
                            $kbTargetQty = (int)($s['kanban_target_qty'] ?? 0);
                            $kbPassedQty = (int)($s['kanban_passed_qty'] ?? 0);
                            $kbRemainingQty = max(0, $kbTargetQty - $kbPassedQty);
                            $kbProgressPct = ($kbTargetQty > 0) ? min(100, round(($kbPassedQty / $kbTargetQty) * 100)) : 100;
                            $isKbCompleted = ($kbTargetQty > 0 && $kbPassedQty >= $kbTargetQty) || (($s['kanban_status'] ?? '') === 'completed');

                            // Status kelayakan mulai sesi baru untuk item ini (hanya untuk Kanban yang masih memiliki sisa kuota)
                            $canStartNewSession = false;
                            $newSessionUrl = '';
                            $newSessionTitle = '';
                            if (!$isSafetyStock && $s['status'] !== 'in_progress' && !empty($s['kanban_item_id']) && (!$isKbCompleted || $kbRemainingQty > 0)) {
                                $canStartNewSession = true;
                                $newSessionUrl = base_url('modules/inspection/session.php?scan_new=1&kanban_id=' . (int)$s['kanban_item_id']);
                                $newSessionTitle = 'Mulai sesi inspeksi baru untuk Kanban #' . ($s['kanban_no'] ?? '');
                            }

                            $sSubs = $substitutionsBySession[$sid] ?? [];
                            $subsByLotId = [];
                            foreach ($sSubs as $sub) {
                                if (!empty($sub['ng_session_lot_id'])) {
                                    $subsByLotId[(int)$sub['ng_session_lot_id']] = $sub;
                                }
                            }
                            $hasReinspection = false;
                            $isPendingReinspection = false;
                            foreach ($sLots as $lItem) {
                                $lStat = strtolower($lItem['lot_status'] ?? '');
                                if (in_array($lStat, ['replaced', 'sorted', 'reinspection'])) {
                                    $hasReinspection = true;
                                }
                                if ($lStat === 'pending_reinspection') {
                                    $isPendingReinspection = true;
                                    $hasReinspection = true;
                                }
                            }
                            if (!empty($sSubs)) {
                                $hasReinspection = true;
                            }
                        ?>
                            <?php
                                if ($s['status'] === 'passed') {
                                    $sessionCardStyle   = 'border: 1px solid #86efac; border-left: 5px solid #16a34a;';
                                    $sessionHeaderStyle = 'background-color: #f0fdf4; border-bottom: 1px solid #bbf7d0;';
                                    $statusBadgeClass   = 'bg-emerald-600 text-white border border-emerald-700 shadow-xs';
                                } elseif ($s['status'] === 'rejected') {
                                    $sessionCardStyle   = 'border: 1px solid #fca5a5; border-left: 5px solid #dc2626;';
                                    $sessionHeaderStyle = 'background-color: #fff1f2; border-bottom: 1px solid #fecdd3;';
                                    $statusBadgeClass   = 'bg-rose-600 text-white border border-rose-700 shadow-xs';
                                } else {
                                    $sessionCardStyle   = 'border: 1px solid #fcd34d; border-left: 5px solid #d97706;';
                                    $sessionHeaderStyle = 'background-color: #fffbeb; border-bottom: 1px solid #fde68a;';
                                    $statusBadgeClass   = 'bg-amber-500 text-white border border-amber-600 shadow-xs';
                                }
                                $displayPartName = preg_replace('/,([^\s])/', ', $1', $s['display_part_name'] ?? '');
                            ?>
                            <div style="<?= $sessionCardStyle ?>" class="bg-white rounded-xl overflow-hidden shadow-sm mb-4">
                                <!-- 2-Tier Structured Session Header (Enterprise UX Layout) -->
                                <div style="<?= $sessionHeaderStyle ?>" class="transition-colors">
                                    <!-- TIER 1: Identitas Utama & Keputusan Mutu -->
                                    <div style="padding: 12px 18px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                                        <!-- Sisi Kiri: Hero Subject & Unified Context Badges -->
                                        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                            <!-- Part Name (Hero Headline) -->
                                            <span style="font-size: 15px; font-weight: 900; color: #0f172a; letter-spacing: -0.01em; margin-right: 6px;" title="<?= htmlspecialchars($displayPartName) ?>">
                                                <?= htmlspecialchars($displayPartName) ?>
                                            </span>

                                            <!-- Part Number Badge -->
                                            <span style="display: inline-flex; align-items: center; height: 26px; padding: 0 10px; border-radius: 6px; font-family: ui-monospace, monospace; font-size: 11.5px; font-weight: 700; background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1;" title="Part Number">
                                                <?= htmlspecialchars($s['part_number']) ?>
                                            </span>

                                            <!-- Badge Kanban / Safety Stock (Warna Terang, Kuat, Solid & Dibedakan Tegas) -->
                                            <?php if ($isSafetyStock): ?>
                                                <!-- SAFETY STOCK: Solid Vivid Purple -->
                                                <span style="display: inline-flex; align-items: center; gap: 6px; height: 26px; padding: 0 11px; border-radius: 6px; font-size: 11.5px; font-weight: 900; background: #7c3aed; color: #ffffff; border: 1px solid #6d28d9; box-shadow: 0 1px 2px rgba(124, 58, 237, 0.25);">
                                                    <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                                    <span>SAFETY STOCK</span>
                                                </span>
                                            <?php else: ?>
                                                <!-- KANBAN: Solid Vivid Royal Blue -->
                                                <span style="display: inline-flex; align-items: center; gap: 6px; height: 26px; padding: 0 11px; border-radius: 6px; font-size: 11.5px; font-weight: 900; background: #2563eb; color: #ffffff; border: 1px solid #1d4ed8; box-shadow: 0 1px 2px rgba(37, 99, 235, 0.25);">
                                                    <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                                    <span>#<?= htmlspecialchars($s['kanban_no'] ?? '-') ?></span>
                                                </span>
                                            <?php endif; ?>

                                            <!-- Badge Sesi (Urutan Sesi per Kanban, disembunyikan untuk Safety Stock) -->
                                            <?php if (!$isSafetyStock): ?>
                                                <span style="display: inline-flex; align-items: center; gap: 4px; height: 26px; padding: 0 9px; border-radius: 6px; font-size: 11px; font-weight: 700; background: #1e293b; color: #ffffff; border: 1px solid #0f172a;" title="Sesi ke-<?= $displaySessionNum ?> untuk Kanban #<?= htmlspecialchars($s['kanban_no'] ?? '-') ?> (ID Sistem: #<?= $sid ?>)">
                                                    <span style="font-size: 9.5px; text-transform: uppercase; font-weight: 800; color: #94a3b8; letter-spacing: 0.04em;">SESI</span>
                                                    <span style="font-family: ui-monospace, monospace; font-weight: 900; color: #fbbf24;">#<?= $displaySessionNum ?></span>
                                                </span>
                                            <?php endif; ?>

                                            <!-- Tombol Sesi Baru (Mulai Sesi Berikutnya) -->
                                            <?php if ($canStartNewSession): ?>
                                                <a href="<?= $newSessionUrl ?>"
                                                   style="display: inline-flex; align-items: center; gap: 5px; height: 26px; padding: 0 10px; border-radius: 6px; font-size: 11px; font-weight: 800; background: #059669; color: #ffffff; border: 1px solid #047857; text-decoration: none; box-shadow: 0 1px 2px rgba(5, 150, 105, 0.25);"
                                                   class="hover:bg-emerald-700 transition-colors"
                                                   title="<?= htmlspecialchars($newSessionTitle, ENT_QUOTES) ?>">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M12 4v16m8-8H4"/></svg>
                                                    <span>Mulai Inspeksi</span>
                                                </a>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Sisi Kanan: Status Mutu & Tombol Aksi -->
                                        <div style="display: flex; align-items: center; gap: 8px; flex-shrink: 0;">
                                            <!-- Status Outcome Badge -->
                                            <?php if ($s['status'] === 'passed'): ?>
                                                <span style="display: inline-flex; align-items: center; gap: 5px; height: 28px; padding: 0 11px; border-radius: 6px; font-size: 12px; font-weight: 900; background: #16a34a; color: #ffffff; border: 1px solid #15803d; box-shadow: 0 1px 2px rgba(22, 163, 74, 0.2);">
                                                    <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                                    <span>PASSED</span>
                                                </span>
                                            <?php elseif ($s['status'] === 'rejected'): ?>
                                                <span style="display: inline-flex; align-items: center; gap: 5px; height: 28px; padding: 0 11px; border-radius: 6px; font-size: 12px; font-weight: 900; background: #dc2626; color: #ffffff; border: 1px solid #b91c1c; box-shadow: 0 1px 2px rgba(220, 38, 38, 0.2);">
                                                    <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>
                                                    <span>REJECTED</span>
                                                </span>
                                            <?php else: ?>
                                                <span style="display: inline-flex; align-items: center; gap: 6px; height: 28px; padding: 0 11px; border-radius: 6px; font-size: 12px; font-weight: 900; background: #d97706; color: #ffffff; border: 1px solid #b45309; box-shadow: 0 1px 2px rgba(217, 119, 6, 0.2);">
                                                    <span style="width: 7px; height: 7px; border-radius: 9999px; background: #ffffff;" class="animate-pulse"></span>
                                                    <span>BERJALAN</span>
                                                </span>
                                            <?php endif; ?>

                                            <!-- Re-inspeksi Tag jika ada -->
                                            <?php if ($isPendingReinspection): ?>
                                                <span style="display: inline-flex; align-items: center; gap: 5px; height: 28px; padding: 0 9px; border-radius: 6px; font-size: 11px; font-weight: 800; background: #fef3c7; color: #92400e; border: 1px solid #fcd34d;">
                                                    <span style="width: 6px; height: 6px; border-radius: 9999px; background: #d97706;" class="animate-pulse"></span>
                                                    <span>Perlu Re-inspeksi</span>
                                                </span>
                                            <?php elseif ($hasReinspection): ?>
                                                <span style="display: inline-flex; align-items: center; gap: 5px; height: 28px; padding: 0 9px; border-radius: 6px; font-size: 11px; font-weight: 800; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe;">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                                    <span>Ada Re-inspeksi</span>
                                                </span>
                                            <?php endif; ?>

                                            <!-- Primary Action Button -->
                                            <?php if ($s['status'] === 'in_progress'): ?>
                                                <a href="<?= base_url('modules/inspection/session.php?id=' . $sid) ?>"
                                                   style="display: inline-flex; align-items: center; gap: 5px; height: 28px; padding: 0 12px; border-radius: 6px; font-size: 11px; font-weight: 800; background: #d97706; color: #ffffff; border: 1px solid #b45309; text-decoration: none;"
                                                   class="hover:opacity-95 transition-opacity cursor-pointer">
                                                    <svg width="12" height="12" viewBox="0 0 20 20" fill="currentColor"><path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"/></svg>
                                                    <span>Lanjutkan</span>
                                                </a>
                                            <?php else: ?>
                                                <a href="<?= base_url('modules/inspection/session.php?id=' . $sid) ?>"
                                                   style="display: inline-flex; align-items: center; gap: 5px; height: 28px; padding: 0 12px; border-radius: 6px; font-size: 11px; font-weight: 800; background: #4f46e5; color: #ffffff; border: 1px solid #4338ca; text-decoration: none;"
                                                   class="hover:opacity-95 transition-opacity cursor-pointer">
                                                    <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                                    <span>Buka</span>
                                                </a>
                                                <?php if ($s['status'] === 'rejected'): ?>
                                                    <a href="<?= base_url('modules/inspection/print_rejection.php?session_id=' . $sid) ?>" target="_blank" rel="noopener"
                                                       style="display: inline-flex; align-items: center; gap: 5px; height: 28px; padding: 0 10px; border-radius: 6px; font-size: 11px; font-weight: 800; background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; text-decoration: none;"
                                                       class="hover:bg-rose-200 transition-colors">
                                                        <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                                                        <span>Cetak Reject</span>
                                                    </a>
                                                <?php endif; ?>
                                            <?php endif; ?>

                                            <!-- Toggle Lot Details -->
                                            <button type="button" onclick="toggleMyDetail(<?= $sid ?>)"
                                                    style="display: inline-flex; align-items: center; gap: 5px; height: 28px; padding: 0 10px; border-radius: 6px; font-size: 11px; font-weight: 800; background: #ffffff; color: #334155; border: 1px solid #cbd5e1; cursor: pointer;"
                                                    class="hover:bg-slate-50 transition-colors">
                                                <span>Lot</span>
                                                <span style="color: #64748b; font-weight: 700;">(<?= $totalLabels ?>)</span>
                                                <svg id="my-chevron-<?= $sid ?>" style="width: 12px; height: 12px; color: #94a3b8; transform: rotate(180deg); transition: transform 0.2s;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/></svg>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- TIER 2: Progress Order Kanban & Metrik Sesi (Operational Metadata Ribbon) -->
                                    <div style="background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 8px 18px; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; font-size: 11px;">
                                        <!-- Sisi Kiri: Progress Order Kanban / Safety Stock -->
                                        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                                            <?php if (!$isSafetyStock && $kbTargetQty > 0): ?>
                                                <!-- Label Progress -->
                                                <div style="display: flex; align-items: center; gap: 6px;">
                                                    <span style="width: 7px; height: 7px; border-radius: 9999px; background: <?= ($isKbCompleted || $kbRemainingQty <= 0) ? '#16a34a' : '#d97706' ?>; display: inline-block;"></span>
                                                    <span style="font-weight: 800; color: <?= ($isKbCompleted || $kbRemainingQty <= 0) ? '#15803d' : '#b45309' ?>; text-transform: uppercase; font-size: 10px; letter-spacing: 0.04em;">Progress:</span>
                                                </div>

                                                <!-- Mini Progress Bar (Guaranteed Width & Height) -->
                                                <div style="width: 120px; height: 8px; background: #e2e8f0; border-radius: 9999px; overflow: hidden; position: relative;" title="<?= $kbProgressPct ?>% Terpenuhi">
                                                    <div style="height: 100%; width: <?= $kbProgressPct ?>%; background: <?= ($isKbCompleted || $kbRemainingQty <= 0) ? '#16a34a' : '#d97706' ?>; border-radius: 9999px; transition: width 0.3s;"></div>
                                                </div>

                                                <!-- Qty Counter -->
                                                <div style="display: inline-flex; align-items: baseline; gap: 4px; font-family: ui-monospace, monospace;">
                                                    <span style="font-weight: 900; color: #0f172a; font-size: 12px;"><?= number_format($kbPassedQty) ?></span>
                                                    <span style="color: #64748b; font-size: 11px;">/ <?= number_format($kbTargetQty) ?> pcs</span>
                                                </div>

                                                <!-- Outcome Badge: Selesai atau Sisa Qty -->
                                                <?php if ($isKbCompleted || $kbRemainingQty <= 0): ?>
                                                    <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 6px; font-size: 10.5px; font-weight: 800; background: #16a34a; color: #ffffff;">
                                                        <svg width="11" height="11" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                                        <span>Tuntas (100%)</span>
                                                    </span>
                                                <?php else: ?>
                                                    <span style="display: inline-flex; align-items: center; gap: 5px; padding: 2px 8px; border-radius: 6px; font-size: 10.5px; font-weight: 800; background: #d97706; color: #ffffff;">
                                                        <span style="width: 6px; height: 6px; border-radius: 9999px; background: #ffffff;" class="animate-pulse"></span>
                                                        <span>Sisa: <?= number_format($kbRemainingQty) ?> pcs (<?= $kbProgressPct ?>%)</span>
                                                    </span>
                                                <?php endif; ?>

                                            <?php elseif ($isSafetyStock): ?>
                                                <!-- Safety Stock Banner -->
                                                <div style="display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 6px; font-size: 11px; font-weight: 800; background: #7c3aed; color: #ffffff;">
                                                    <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                                    <span>SAFETY STOCK: Buffer Gudang / Non-Kanban</span>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: #94a3b8; font-style: italic; font-size: 11px;">Tanpa target kuota Kanban</span>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Sisi Kanan: Metrik Sesi, Waktu & Shift -->
                                        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap; color: #475569;">
                                            <!-- Qty Sesi -->
                                            <div style="display: flex; align-items: center; gap: 5px;">
                                                <span style="font-weight: 700; color: #64748b;">Qty Sesi:</span>
                                                <span style="display: inline-flex; align-items: center; padding: 1px 7px; border-radius: 5px; font-family: ui-monospace, monospace; font-size: 11.5px; font-weight: 800; background: #ffffff; color: #0f172a; border: 1px solid #cbd5e1;">
                                                    <?= number_format($s['total_scanned_qty']) ?> pcs
                                                </span>
                                            </div>

                                            <span style="color: #cbd5e1; font-weight: bold;">•</span>

                                            <!-- Waktu & Tanggal -->
                                            <div style="display: flex; align-items: center; gap: 5px; color: #334155;">
                                                <svg width="12" height="12" style="color: #64748b;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                                <span style="font-weight: 600;"><?= date('d M Y', strtotime($s['started_at'])) ?>,</span>
                                                <span style="font-family: ui-monospace, monospace; font-weight: 800; color: #0f172a;"><?= date('H:i', strtotime($s['started_at'])) ?></span>
                                                <span style="font-size: 9.5px; color: #94a3b8;">WIB</span>
                                            </div>

                                            <!-- Shift -->
                                            <span style="display: inline-flex; align-items: center; padding: 1px 7px; border-radius: 5px; font-size: 10px; font-weight: 800; background: #e0e7ff; color: #3730a3; border: 1px solid #c7d2fe;">
                                                <?= htmlspecialchars($shiftName) ?>
                                            </span>

                                            <?php if ($isClosed): ?>
                                                <span style="color: #cbd5e1; font-weight: bold;">•</span>
                                                <span style="color: #64748b; font-size: 10.5px;" title="Durasi Pemeriksaan Sesi"><?= $durationText ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Sub-Tabel Rincian Box / Lot (Persis Desain Daily Report Detail) -->
                                <div id="my-detail-<?= $sid ?>" class="px-3.5 py-3 bg-slate-50/40 border-t border-slate-100">
                                    <?php
                                        $totalDisplayAttempts = 0;
                                        foreach ($sLots as $lTmp) {
                                            $lIdTmp = (int)$lTmp['id'];
                                            $subLogTmp = $subsByLotId[$lIdTmp] ?? null;
                                            $lotDefectsTmp = $ngRecordsByLot[$lIdTmp] ?? [];
                                            $isSortTmp = ($subLogTmp && $subLogTmp['action_type'] === 'sort_reinspect');
                                            if (!$isSortTmp) {
                                                foreach ($lotDefectsTmp as $dfTmp) {
                                                    if (!empty($dfTmp['is_sorted'])) { $isSortTmp = true; break; }
                                                }
                                            }
                                            $totalDisplayAttempts += $isSortTmp ? 2 : 1;
                                        }
                                    ?>
                                    <div class="flex items-center justify-between text-[10px] font-black text-slate-500 uppercase tracking-wider mb-2 px-0.5">
                                        <div class="flex items-center gap-1.5">
                                            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                            <span>Rincian Box / Lot yang Diperiksa (<?= $totalDisplayAttempts ?> <?= $totalDisplayAttempts > 1 ? 'Pemeriksaan' : 'Box' ?> &bull; <?= number_format($s['total_scanned_qty']) ?> pcs)</span>
                                        </div>
                                        <?php if (!$isSafetyStock && $kbTargetQty > 0): ?>
                                            <div class="text-[10px] font-bold text-slate-600 normal-case hidden sm:block">
                                                Akumulasi Kanban: <span class="font-mono font-black text-slate-800"><?= number_format($kbPassedQty) ?></span> / <span class="font-mono font-bold text-slate-600"><?= number_format($kbTargetQty) ?> pcs</span>
                                                <?php if ($kbRemainingQty > 0): ?>
                                                    &bull; <span class="text-rose-600 font-bold">Kurang <?= number_format($kbRemainingQty) ?> pcs</span>
                                                <?php else: ?>
                                                    &bull; <span class="text-emerald-600 font-bold">Target Terpenuhi</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (empty($sLots)): ?>
                                        <div class="py-5 text-center text-slate-400 text-xs italic bg-white rounded-lg border border-slate-200">
                                            Belum ada label/lot yang discan pada sesi ini.
                                        </div>
                                    <?php else: ?>
                                        <div style="overflow-x: auto; border: 1px solid #f1f5f9; border-radius: 8px; background: #ffffff;">
                                            <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
                                                <thead style="background: #f8fafc; color: #64748b; font-weight: 700; border-bottom: 1px solid #e2e8f0;">
                                                    <tr>
                                                        <th style="padding: 6px 10px; width: 30px; text-align: center;">#</th>
                                                        <th style="padding: 6px 10px; min-width: 130px; text-align: left;">Ref Number</th>
                                                        <th style="padding: 6px 10px; min-width: 120px; text-align: left;">Lot Number</th>
                                                        <th style="padding: 6px 10px; text-align: right; width: 85px;">Qty Box</th>
                                                        <th style="padding: 6px 10px; text-align: center; width: 95px;">Sampel (pcs)</th>
                                                        <th style="padding: 6px 10px; text-align: center; width: 75px;">NG</th>
                                                        <th style="padding: 6px 10px; text-align: center; width: 95px;">Status Lot</th>
                                                        <th style="padding: 6px 10px; min-width: 100px; text-align: left;">Waktu Scan</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php 
                                                    $lotRowNo = 1;
                                                    foreach ($sLots as $lItem): 
                                                        $lId = (int)$lItem['id'];
                                                        $subLog = $subsByLotId[$lId] ?? null;
                                                        $lotDefects = $ngRecordsByLot[$lId] ?? [];
                                                        $isSortReinspect = ($subLog && $subLog['action_type'] === 'sort_reinspect');
                                                        if (!$isSortReinspect) {
                                                            foreach ($lotDefects as $df) {
                                                                if (!empty($df['is_sorted'])) {
                                                                    $isSortReinspect = true;
                                                                    break;
                                                                }
                                                            }
                                                        }

                                                        $isSafetyStockLot = (($lItem['lot_result'] ?? '') === 'skipped') 
                                                            || (!empty($lItem['remarks']) && strpos($lItem['remarks'], 'Safety Stock') !== false);

                                                        if ($isSafetyStockLot) {
                                                            $sampleSize = 0;
                                                            $rejectNumber = 0;
                                                        } else {
                                                            $lotAql = getLotAqlFallback($lItem['qty'], $pdo);
                                                            $sampleSize = ($lItem['sample_size'] > 0) ? (int)$lItem['sample_size'] : (int)$lotAql['sample_size'];
                                                            $rejectNumber = (int)$lotAql['reject_number'];
                                                        }

                                                        if ($isSortReinspect):
                                                            $attempt1Defects = [];
                                                            $attempt2Defects = [];
                                                            foreach ($lotDefects as $df) {
                                                                if (!empty($df['is_sorted'])) {
                                                                    $attempt1Defects[] = $df;
                                                                } else {
                                                                    $attempt2Defects[] = $df;
                                                                }
                                                            }
                                                            if (empty($attempt1Defects) && !empty($lotDefects)) {
                                                                if ($lItem['lot_result'] === 'passed') {
                                                                    $attempt1Defects = $lotDefects;
                                                                    $attempt2Defects = [];
                                                                } else {
                                                                    $attempt1Defects = $lotDefects;
                                                                    $attempt2Defects = $lotDefects;
                                                                }
                                                            }

                                                            $att1NgCount = 0;
                                                            foreach ($attempt1Defects as $d) { $att1NgCount += (int)($d['qty_ng'] ?? 1); }
                                                            if ($att1NgCount === 0) $att1NgCount = 1;

                                                            $att2NgCount = 0;
                                                            foreach ($attempt2Defects as $d) { $att2NgCount += (int)($d['qty_ng'] ?? 1); }

                                                            $att1Time = !empty($attempt1Defects[0]['created_at']) ? $attempt1Defects[0]['created_at'] : $lItem['created_at'];
                                                            $att2Time = !empty($subLog['created_at']) ? $subLog['created_at'] : ($lItem['action_noted_at'] ?: $lItem['closed_at'] ?: $lItem['created_at']);
                                                            $isAtt2Passed = ($lItem['lot_result'] === 'passed');
                                                    ?>
                                                            <!-- Baris 1: Uji Awal (NG) -->
                                                            <tr style="border-bottom: 1px dashed #fca5a5; background: #fffbfb;">
                                                                <td style="padding: 6px 10px; text-align: center; color: #94a3b8; font-weight: 700;"><?= $lotRowNo++ ?></td>
                                                                <td style="padding: 6px 10px; font-family: monospace; font-weight: 800; color: #7c3aed;">
                                                                    <a href="<?= base_url('modules/inspection/session.php?id=' . $sid) ?>" class="hover:underline" title="Buka Sesi Inspeksi">
                                                                        <?= htmlspecialchars($lItem['ref_number'] ?: '-') ?>
                                                                    </a>
                                                                    <span style="display: inline-block; margin-left: 5px; font-family: sans-serif; font-size: 9px; font-weight: 800; background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; padding: 1px 5px; border-radius: 4px; vertical-align: middle;">
                                                                        UJI AWAL (NG)
                                                                    </span>
                                                                </td>
                                                                <td style="padding: 6px 10px; font-family: monospace; color: #334155;">
                                                                    <?= htmlspecialchars($lItem['lot_number'] ?: '-') ?>
                                                                </td>
                                                                <td style="padding: 6px 10px; text-align: right; font-family: monospace; font-weight: 700; color: #0f172a;">
                                                                    <?= number_format($lItem['qty']) ?> pcs
                                                                </td>
                                                                <td style="padding: 6px 10px; text-align: center; font-family: monospace; font-weight: 700; color: #2563eb;">
                                                                    <?= $sampleSize ?> pcs
                                                                </td>
                                                                <td style="padding: 6px 10px; text-align: center;">
                                                                    <span style="font-family: monospace; font-size: 11px; font-weight: 800; padding: 2px 7px; border-radius: 4px; background: #fef2f2; color: #dc2626; border: 1px solid #fecaca;">
                                                                        <?= $att1NgCount ?> / <?= $rejectNumber ?>
                                                                    </span>
                                                                </td>
                                                                <td style="padding: 6px 10px; text-align: center; vertical-align: middle;">
                                                                    <span style="font-family: sans-serif; font-size: 9px; font-weight: 800; background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; padding: 2px 7px; border-radius: 4px; display: inline-flex; align-items: center; justify-content: center; gap: 4px;">
                                                                        <svg width="10" height="10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>
                                                                        <span>NG / REJECTED</span>
                                                                    </span>
                                                                </td>
                                                                <td style="padding: 6px 10px; color: #64748b; font-family: monospace; font-size: 10.5px;">
                                                                    <div><?= date('d/m/Y', strtotime($att1Time)) ?></div>
                                                                    <div style="font-weight: 700; color: #334155;"><?= date('H:i \W\I\B', strtotime($att1Time)) ?></div>
                                                                </td>
                                                            </tr>

                                                            <!-- Baris Temuan Cacat pada Pemeriksaan Awal -->
                                                            <tr style="background: #fff5f5; border-bottom: 2px solid #fecaca;">
                                                                <td colspan="8" style="padding: 6px 10px 8px 12px;">
                                                                    <div style="display: flex; flex-direction: column; gap: 4px;">
                                                                        <div style="font-size: 10px; font-weight: 800; color: #991b1b; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 4px;">
                                                                            <svg style="width: 12px; height: 12px; color: #dc2626;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                                                            <span>TEMUAN CACAT PADA PEMERIKSAAN AWAL (<?= count($attempt1Defects) ?> DEFECT):</span>
                                                                        </div>
                                                                        <?php foreach ($attempt1Defects as $df): ?>
                                                                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 11px; background: #ffffff; padding: 4px 8px; border-radius: 5px; border: 1px solid #fee2e2; flex-wrap: wrap;">
                                                                                <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                                                                    <span style="font-weight: 800; color: #dc2626;">• <?= htmlspecialchars($df['defect_name'] ?? 'Defect') ?>:</span>
                                                                                    <span style="font-family: monospace; font-weight: 800; color: #b91c1c; background: #fee2e2; padding: 1px 6px; border-radius: 3px;">
                                                                                        <?= (int)($df['qty_ng'] ?? 1) ?> pcs
                                                                                    </span>
                                                                                    <?php if (!empty($df['remark'])): ?>
                                                                                        <span style="color: #64748b; font-style: italic; font-size: 10px;">(<?= htmlspecialchars($df['remark']) ?>)</span>
                                                                                    <?php endif; ?>
                                                                                </div>
                                                                                <div style="display: flex; align-items: center; gap: 10px; font-size: 10px; color: #475569;">
                                                                                    <span>
                                                                                        <span style="color: #64748b;">Inspector:</span>
                                                                                        <strong style="color: #0f172a;"><?= htmlspecialchars($s['inspector_name'] ?? 'Inspector') ?></strong>
                                                                                    </span>
                                                                                    <span style="background: #f1f5f9; color: #334155; padding: 2px 7px; border-radius: 4px; font-family: monospace; font-weight: 700; border: 1px solid #e2e8f0; font-size: 10px; display: inline-flex; align-items: center; gap: 4px;">
                                                                                        <span><?= !empty($df['created_at']) ? date('d/m/Y', strtotime($df['created_at'])) : date('d/m/Y', strtotime($att1Time)) ?></span>
                                                                                        <span style="color: #94a3b8;">•</span>
                                                                                        <span><?= !empty($df['created_at']) ? date('H:i \W\I\B', strtotime($df['created_at'])) : date('H:i \W\I\B', strtotime($att1Time)) ?></span>
                                                                                    </span>
                                                                                </div>
                                                                            </div>
                                                                        <?php endforeach; ?>
                                                                    </div>
                                                                </td>
                                                            </tr>

                                                            <!-- Baris 2: Re-inspeksi -->
                                                            <tr style="border-bottom: <?= $isAtt2Passed ? '1px dashed #86efac' : '1px dashed #fca5a5' ?>; background: <?= $isAtt2Passed ? '#f0fdf4' : '#fffbfb' ?>;">
                                                                <td style="padding: 6px 10px; text-align: center; color: #94a3b8; font-weight: 700;"><?= $lotRowNo++ ?></td>
                                                                <td style="padding: 6px 10px; font-family: monospace; font-weight: 800; color: #7c3aed;">
                                                                    <a href="<?= base_url('modules/inspection/session.php?id=' . $sid) ?>" class="hover:underline" title="Buka Sesi Inspeksi">
                                                                        <?= htmlspecialchars($lItem['ref_number'] ?: '-') ?>
                                                                    </a>
                                                                    <span style="display: inline-block; margin-left: 5px; font-family: sans-serif; font-size: 9px; font-weight: 800; <?= $isAtt2Passed ? 'background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0;' : 'background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca;' ?> padding: 1px 5px; border-radius: 4px; vertical-align: middle;">
                                                                        <?= $isAtt2Passed ? 'RE-INSPEKSI' : 'RE-INSPEKSI (NG)' ?>
                                                                    </span>
                                                                </td>
                                                                <td style="padding: 6px 10px; font-family: monospace; color: #334155;">
                                                                    <?= htmlspecialchars($lItem['lot_number'] ?: '-') ?>
                                                                </td>
                                                                <td style="padding: 6px 10px; text-align: right; font-family: monospace; font-weight: 700; color: #0f172a;">
                                                                    <?= number_format($lItem['qty']) ?> pcs
                                                                </td>
                                                                <td style="padding: 6px 10px; text-align: center; font-family: monospace; font-weight: 700; color: #2563eb;">
                                                                    <?= $sampleSize ?> pcs
                                                                </td>
                                                                <td style="padding: 6px 10px; text-align: center;">
                                                                    <span style="font-family: monospace; font-size: 11px; font-weight: 800; padding: 2px 7px; border-radius: 4px; <?= ($att2NgCount > 0) ? 'background: #fef2f2; color: #dc2626; border: 1px solid #fecaca;' : 'background: #f0fdf4; color: #16a34a; border: 1px solid #dcfce7;' ?>">
                                                                        <?= $att2NgCount ?> / <?= $rejectNumber ?>
                                                                    </span>
                                                                </td>
                                                                <td style="padding: 6px 10px; text-align: center; vertical-align: middle;">
                                                                    <?php if ($isAtt2Passed): ?>
                                                                        <span style="font-family: sans-serif; font-size: 9px; font-weight: 800; background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; padding: 2px 7px; border-radius: 4px; display: inline-flex; align-items: center; justify-content: center; gap: 4px;">
                                                                            <svg width="10" height="10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                                                            <span>PASSED</span>
                                                                        </span>
                                                                    <?php else: ?>
                                                                        <span style="font-family: sans-serif; font-size: 9px; font-weight: 800; background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; padding: 2px 7px; border-radius: 4px; display: inline-flex; align-items: center; justify-content: center; gap: 4px;">
                                                                            <svg width="10" height="10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>
                                                                            <span>REJECTED</span>
                                                                        </span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td style="padding: 6px 10px; color: #64748b; font-family: monospace; font-size: 10.5px;">
                                                                    <div><?= date('d/m/Y', strtotime($att2Time)) ?></div>
                                                                    <div style="font-weight: 700; color: #334155;"><?= date('H:i \W\I\B', strtotime($att2Time)) ?></div>
                                                                </td>
                                                            </tr>

                                                            <!-- Baris Hasil Verifikasi & Re-inspeksi -->
                                                            <tr style="background: <?= $isAtt2Passed ? '#f0fdf4' : '#fff1f2' ?>; border-bottom: 2px solid <?= $isAtt2Passed ? '#bbf7d0' : '#fecdd3' ?>;">
                                                                <td colspan="8" style="padding: 6px 10px 8px 12px;">
                                                                    <div style="display: flex; flex-direction: column; gap: 4px;">
                                                                        <div style="font-size: 10px; font-weight: 800; color: <?= $isAtt2Passed ? '#166534' : '#be123c' ?>; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 4px;">
                                                                            <?php if ($isAtt2Passed): ?>
                                                                                <svg style="width: 13px; height: 13px; color: #16a34a;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                                                <span>HASIL VERIFIKASI & RE-INSPEKSI: NOL CACAT / DEFECT (PASSED)</span>
                                                                            <?php else: ?>
                                                                                <svg style="width: 13px; height: 13px; color: #e11d48;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                                                                <span>HASIL VERIFIKASI & RE-INSPEKSI: TETAP DITEMUKAN CACAT / NG (REJECTED)</span>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 11px; background: #ffffff; padding: 5px 10px; border-radius: 5px; border: 1px solid <?= $isAtt2Passed ? '#dcfce7' : '#fecdd3' ?>; flex-wrap: wrap;">
                                                                            <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                                                                <span style="font-weight: 800; color: <?= $isAtt2Passed ? '#15803d' : '#be123c' ?>;">Tindakan:</span>
                                                                                <span style="color: #1e293b;">
                                                                                    <?php 
                                                                                        $actNotes = $subLog['notes'] ?? ($attempt1Defects[0]['sort_notes'] ?? '');
                                                                                        if (empty($actNotes)) {
                                                                                            $actNotes = $isAtt2Passed 
                                                                                                ? "Lot #{$lItem['lot_number']} (Ref: {$lItem['ref_number']}) disortir part NG-nya dan diuji ulang sampel fisik."
                                                                                                : "Lot #{$lItem['lot_number']} (Ref: {$lItem['ref_number']}) disortir part NG-nya dan diuji ulang fisik, namun tetap ditemukan cacat.";
                                                                                        }
                                                                                        echo htmlspecialchars($actNotes);
                                                                                    ?>
                                                                                </span>
                                                                            </div>
                                                                            <div style="display: flex; align-items: center; gap: 10px; font-size: 10px; color: #475569;">
                                                                                <span>
                                                                                    <span style="color: #64748b;">Inspector:</span>
                                                                                    <strong style="color: #0f172a;"><?= htmlspecialchars($subLog['actioned_by_name'] ?? ($s['inspector_name'] ?? 'Admin')) ?></strong>
                                                                                </span>
                                                                                <span style="background: #f1f5f9; color: #334155; padding: 2px 7px; border-radius: 4px; font-family: monospace; font-weight: 700; border: 1px solid #e2e8f0; font-size: 10px; display: inline-flex; align-items: center; gap: 4px;">
                                                                                    <span><?= date('d/m/Y', strtotime($att2Time)) ?></span>
                                                                                    <span style="color: #94a3b8;">•</span>
                                                                                    <span><?= date('H:i \W\I\B', strtotime($att2Time)) ?></span>
                                                                                </span>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </td>
                                                            </tr>

                                                    <?php else: 
                                                            $hasDefects = !empty($lotDefects);
                                                            $ngCount = (int)($lItem['ng_count'] ?? count($lotDefects));
                                                            $isReplaced = ($lItem['lot_status'] === 'replaced' || !empty($lItem['replaced_by_lot_id']));
                                                            $isReplacementBox = (isset($lItem['remarks']) && strpos($lItem['remarks'], 'Box Pengganti') !== false);
                                                    ?>
                                                            <tr style="border-bottom: <?= $hasDefects ? '1px dashed #fca5a5' : '1px solid #f8fafc' ?>; background: <?= $hasDefects ? '#fffbfb' : 'transparent' ?>;">
                                                                <td style="padding: 6px 10px; text-align: center; color: #94a3b8; font-weight: 700;"><?= $lotRowNo++ ?></td>
                                                                <td style="padding: 6px 10px; font-family: monospace; font-weight: 800; color: #7c3aed;">
                                                                    <a href="<?= base_url('modules/inspection/session.php?id=' . $sid) ?>" class="hover:underline" title="Buka Sesi Inspeksi">
                                                                        <?= htmlspecialchars($lItem['ref_number'] ?: '-') ?>
                                                                    </a>
                                                                    <?php if ($isReplacementBox): ?>
                                                                        <span style="display: inline-block; margin-left: 5px; font-family: sans-serif; font-size: 9px; font-weight: 800; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 1px 5px; border-radius: 4px; vertical-align: middle;">
                                                                            BOX PENGGANTI
                                                                        </span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td style="padding: 6px 10px; font-family: monospace; color: #334155;">
                                                                    <?= htmlspecialchars($lItem['lot_number'] ?: '-') ?>
                                                                </td>
                                                                <td style="padding: 6px 10px; text-align: right; font-family: monospace; font-weight: 700; color: #0f172a;">
                                                                    <?= number_format($lItem['qty']) ?> pcs
                                                                </td>
                                                                <td style="padding: 6px 10px; text-align: center; font-family: monospace; font-weight: 700; color: #2563eb;">
                                                                    <?php if (!empty($isSafetyStockLot)): ?>
                                                                        <span class="text-slate-600 font-bold">0 pcs</span>
                                                                        <div style="font-size: 8.5px; font-weight: 800; color: #059669; line-height: 1; margin-top: 1px;">Lolos SS</div>
                                                                    <?php else: ?>
                                                                        <?= $sampleSize ?> pcs
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td style="padding: 6px 10px; text-align: center;">
                                                                    <?php if (!empty($isSafetyStockLot)): ?>
                                                                        <span style="font-family: monospace; font-size: 11px; font-weight: 800; padding: 2px 7px; border-radius: 4px; background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0;" title="Lolos pengujian fisik di Safety Stock gudang">
                                                                            0 / 0
                                                                        </span>
                                                                    <?php else: ?>
                                                                        <span style="font-family: monospace; font-size: 11px; font-weight: 800; padding: 2px 7px; border-radius: 4px; <?= $ngCount > 0 ? 'background: #fef2f2; color: #dc2626; border: 1px solid #fecaca;' : 'background: #f0fdf4; color: #16a34a; border: 1px solid #dcfce7;' ?>">
                                                                            <?= $ngCount ?> / <?= $rejectNumber ?>
                                                                        </span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td style="padding: 6px 10px; text-align: center; vertical-align: middle;">
                                                                    <?php
    $lotResultStatus = $lItem['lot_result'] ?? 'in_progress';
    $lotStatusFlag = $lItem['lot_status'] ?? '';
    if ($isReplacementBox):
?>
                                                                         <span style="display: inline-block; padding: 2px 7px; border-radius: 4px; font-family: sans-serif; font-size: 9px; font-weight: 800; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe;">
                                                                            PENGGANTI
                                                                        </span>
                                                                    <?php elseif (!empty($isSafetyStockLot) || $lotResultStatus === 'skipped'): ?>
                                                                        <span style="display: inline-flex; align-items: center; justify-content: center; gap: 4px; padding: 2px 8px; border-radius: 4px; font-family: sans-serif; font-size: 9px; font-weight: 800; background: #059669; color: #ffffff; box-shadow: 0 1px 2px rgba(5, 150, 105, 0.35);" title="Box dialokasikan dari Safety Stock yang sudah lolos uji fisik gudang">
                                                                            <svg width="10" height="10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                                                            <span>PASSED (SAFETY STOCK)</span>
                                                                        </span>
                                                                    <?php elseif ($lotResultStatus === 'passed'): ?>
                                                                        <span style="display: inline-flex; align-items: center; justify-content: center; gap: 4px; padding: 2px 8px; border-radius: 4px; font-family: sans-serif; font-size: 9px; font-weight: 800; background: #16a34a; color: #ffffff; box-shadow: 0 1px 2px rgba(220, 38, 38, 0.35); background-color: #16a34a;">
                                                                            <svg width="10" height="10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                                                            <span>PASSED</span>
                                                                        </span>
                                                                    <?php elseif ($lotResultStatus === 'rejected' || $lotStatusFlag === 'replaced' || !empty($isReplaced)): ?>
                                                                        <span style="display: inline-flex; align-items: center; justify-content: center; gap: 4px; padding: 2px 8px; border-radius: 4px; font-family: sans-serif; font-size: 9px; font-weight: 800; background: #dc2626; color: #ffffff; box-shadow: 0 1px 2px rgba(220, 38, 38, 0.35);">
                                                                            <svg width="10" height="10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>
                                                                            <span>REJECTED</span>
                                                                        </span>
                                                                    <?php else: ?>
                                                                        <span style="display: inline-flex; align-items: center; justify-content: center; gap: 3.5px; padding: 2px 8px; border-radius: 4px; font-family: sans-serif; font-size: 9px; font-weight: 800; background: #d97706; color: #ffffff; box-shadow: 0 1px 2px rgba(217, 119, 6, 0.35);">
                                                                            <span style="width: 5px; height: 5px; border-radius: 9999px; background-color: #ffffff; display: inline-block;" class="animate-pulse"></span>
                                                                            <span>BERJALAN</span>
                                                                        </span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td style="padding: 6px 10px; color: #64748b; font-family: monospace; font-size: 10.5px;">
                                                                    <div><?= date('d/m/Y', strtotime($lItem['created_at'])) ?></div>
                                                                    <div style="font-weight: 700; color: #334155;"><?= date('H:i \W\I\B', strtotime($lItem['created_at'])) ?></div>
                                                                </td>
                                                            </tr>
                                                            <?php if ($hasDefects): ?>
                                                                <tr style="background: #fff5f5; border-bottom: 2px solid #fecaca;">
                                                                    <td colspan="8" style="padding: 6px 10px 8px 12px;">
                                                                        <div style="display: flex; flex-direction: column; gap: 4px;">
                                                                            <div style="font-size: 10px; font-weight: 800; color: #991b1b; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 4px;">
                                                                                <svg style="width: 12px; height: 12px; color: #dc2626;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                                                                <span>TEMUAN CACAT PADA PEMERIKSAAN AWAL (<?= count($lotDefects) ?> DEFECT):</span>
                                                                            </div>
                                                                            <?php foreach ($lotDefects as $df): ?>
                                                                                <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 11px; background: #ffffff; padding: 4px 8px; border-radius: 5px; border: 1px solid #fee2e2; flex-wrap: wrap;">
                                                                                    <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                                                                        <span style="font-weight: 800; color: #dc2626;">• <?= htmlspecialchars($df['defect_name'] ?? 'Defect') ?>:</span>
                                                                                        <span style="font-family: monospace; font-weight: 800; color: #b91c1c; background: #fee2e2; padding: 1px 6px; border-radius: 3px;">
                                                                                            <?= (int)($df['qty_ng'] ?? 1) ?> pcs
                                                                                        </span>
                                                                                        <?php if (!empty($df['remark'])): ?>
                                                                                            <span style="color: #64748b; font-style: italic; font-size: 10px;">(<?= htmlspecialchars($df['remark']) ?>)</span>
                                                                                        <?php endif; ?>
                                                                                    </div>
                                                                                    <div style="display: flex; align-items: center; gap: 10px; font-size: 10px; color: #475569;">
                                                                                        <span>
                                                                                            <span style="color: #64748b;">Inspector:</span>
                                                                                            <strong style="color: #0f172a;"><?= htmlspecialchars($s['inspector_name'] ?? 'Inspector') ?></strong>
                                                                                        </span>
                                                                                        <span style="background: #f1f5f9; color: #334155; padding: 2px 7px; border-radius: 4px; font-family: monospace; font-weight: 700; border: 1px solid #e2e8f0; font-size: 10px; display: inline-flex; align-items: center; gap: 4px;">
                                                                                            <span><?= !empty($df['created_at']) ? date('d/m/Y', strtotime($df['created_at'])) : date('d/m/Y', strtotime($lItem['created_at'])) ?></span>
                                                                                            <span style="color: #94a3b8;">•</span>
                                                                                            <span><?= !empty($df['created_at']) ? date('H:i \W\I\B', strtotime($df['created_at'])) : date('H:i \W\I\B', strtotime($lItem['created_at'])) ?></span>
                                                                                        </span>
                                                                                    </div>
                                                                                </div>
                                                                            <?php endforeach; ?>
                                                                        </div>
                                                                    </td>
                                                                </tr>
                                                            <?php endif; ?>
                                                    <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            <?php elseif ($activeTab === 'my_lots'): ?>
                <!-- ═══════════════════════════════════════════════════════════════ -->
                <!-- TABEL RINCIAN PER LOT SAYA (FLAT TABLE / SHEET 1 VIEW)         -->
                <!-- ═══════════════════════════════════════════════════════════════ -->
                <div class="w-full" style="min-width: 0; max-width: 100%;">
                    <div class="px-4 py-3 bg-slate-50 border-b border-slate-200 flex items-center justify-between flex-wrap gap-2">
                        <div class="flex items-center gap-2.5">
                            <span class="w-2.5 h-2.5 rounded-full bg-sky-500 animate-pulse"></span>
                            <div>
                                <h3 class="text-xs font-black text-slate-800 uppercase tracking-wider">
                                    Riwayat Inspeksi Lot Saya (Cek Fisik)
                                </h3>
                                <p class="text-[10.5px] text-slate-500 hidden sm:block mt-0.5">
                                    Daftar seluruh lot / box hasil pemeriksaan fisik aktual oleh Anda
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <!-- Navigasi Cepat Scroll Horizontal Kiri & Kanan -->
                            <div class="inline-flex items-center rounded-lg border border-slate-300/80 bg-white p-0.5 shadow-2xs">
                                <button type="button" 
                                        onclick="scrollMyLotsTable(-350)" 
                                        class="px-2.5 py-1 text-[11px] font-extrabold text-slate-700 hover:text-sky-700 hover:bg-sky-50 rounded transition-all inline-flex items-center gap-1 cursor-pointer select-none" 
                                        title="Geser tabel ke kiri">
                                    <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/></svg>
                                    <span>Scroll Kiri</span>
                                </button>
                                <div class="h-3.5 w-px bg-slate-200"></div>
                                <button type="button" 
                                        onclick="scrollMyLotsTable(350)" 
                                        class="px-2.5 py-1 text-[11px] font-extrabold text-slate-700 hover:text-sky-700 hover:bg-sky-50 rounded transition-all inline-flex items-center gap-1 cursor-pointer select-none" 
                                        title="Geser tabel ke kanan">
                                    <span>Scroll Kanan</span>
                                    <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                                </button>
                            </div>

                            <span class="text-[11px] font-extrabold text-sky-800 bg-sky-100 border border-sky-200 px-2.5 py-1 rounded-full whitespace-nowrap">
                                <?= number_format($totalItems) ?> Lot Fisik
                            </span>
                        </div>
                    </div>

                    <div id="my-lots-table-wrapper" class="overflow-x-auto w-full custom-scrollbar" style="width: 100%; max-width: 100%; -webkit-overflow-scrolling: touch;">
                        <table class="w-full text-left text-xs text-slate-700 table-auto" style="width: 100%; min-width: 1560px; border-collapse: collapse;">
                            <thead class="bg-slate-900 text-white font-bold text-[11px] uppercase tracking-wider select-none">
                                <tr>
                                    <th style="padding: 10px 10px; width: 45px; text-align: center;">#</th>
                                    <th style="padding: 10px 10px; text-align: center; width: 140px;">Waktu</th>
                                    <th style="padding: 10px 10px; text-align: center; width: 115px;">Jenis</th>
                                    <th style="padding: 10px 10px; width: 130px;">Part Code</th>
                                    <th style="padding: 10px 10px; min-width: 160px;">Part Name</th>
                                    <th style="padding: 10px 10px; text-align: center; width: 95px;">Model</th>
                                    <th style="padding: 10px 10px; width: 125px;">Lot No</th>
                                    <th style="padding: 10px 10px; width: 115px;">Ref No</th>
                                    <th style="padding: 10px 10px; text-align: right; width: 95px;">QTY Lot</th>
                                    <th style="padding: 10px 10px; text-align: center; width: 105px;">Sampling</th>
                                    <th style="padding: 10px 10px; text-align: center; width: 70px;">NG</th>
                                    <th style="padding: 10px 10px; min-width: 180px;">Defect Temuan</th>
                                    <th style="padding: 10px 10px; text-align: center; width: 130px;">Status Lot</th>
                                    <th style="padding: 10px 10px; text-align: center; width: 75px;">Sesi</th>
                                    <th style="padding: 10px 10px; text-align: center; width: 85px;">Shift</th>
                                    <th style="padding: 10px 10px; width: 130px;">Inspektur</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 text-[11px]">
                                <?php if (empty($myLots)): ?>
                                    <tr>
                                        <td colspan="16" class="py-14 text-center text-slate-400 bg-white">
                                            <div class="flex flex-col items-center justify-center gap-2">
                                                <svg class="w-12 h-12 text-slate-300 stroke-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                                                </svg>
                                                <span class="font-bold text-slate-600 text-sm">Tidak ada rincian lot yang sesuai filter</span>
                                                <span class="text-xs text-slate-400 max-w-sm">Silakan ubah rentang tanggal (Hari Ini, Pekan Ini, Bulan Ini, atau Semua) atau sesuaikan kata kunci pencarian.</span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php 
                                    $rowNum = $offset;
                                    foreach ($myLots as $idx => $rLot): 
                                        $rowNum++;
                                        $hasNg = ((int)($rLot['ng_count'] ?? 0) > 0 || (int)($rLot['total_ng'] ?? 0) > 0 || !empty($rLot['defects']));
                                        $isEven = ($idx % 2 === 0);
                                        $shiftName = getShiftName($rLot['scanned_at'] ?? $rLot['session_started_at']);
                                        $isReinspectLot = !empty($rLot['is_reinspected_lot']) || !empty($rLot['is_reinspection']) || in_array($rLot['lot_status'] ?? '', ['reinspected', 'replaced', 'ng_quarantine']);
                                        $isInitialAttempt = !empty($rLot['is_initial_attempt']) || (($rLot['attempt_type'] ?? '') === 'initial');
                                        
                                        // Build Defect Summary Badges (Matching daily_report/detail.php)
                                        $defList = !empty($rLot['defects']) ? $rLot['defects'] : [];
                                        if (!empty($defList)) {
                                            $defParts = [];
                                            foreach ($defList as $df) {
                                                $defParts[] = '<span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200/80">' . htmlspecialchars($df['defect_name']) . ' <b class="text-rose-900 font-extrabold">(' . (int)($df['qty_ng'] ?? 1) . ' pcs)</b></span>';
                                            }
                                            $defectHtml = implode(' ', $defParts);
                                        } elseif (!empty($rLot['defect_summary']) && $rLot['defect_summary'] !== '-') {
                                            $defectHtml = '<span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200/80">' . htmlspecialchars($rLot['defect_summary']) . '</span>';
                                        } else {
                                            $defectHtml = $hasNg 
                                                ? '<span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">Temuan Cacat (' . (int)max($rLot['ng_count'] ?? 0, $rLot['total_ng'] ?? 0) . ' pcs)</span>' 
                                                : '<span class="text-slate-400 font-medium">-</span>';
                                        }
                                    ?>
                                        <tr class="<?= getLotRowClass($rLot) ?>">
                                            <td style="padding: 9px 10px; text-align: center; font-weight: 700; color: #94a3b8;"><?= $rowNum ?></td>
                                            <td style="padding: 9px 10px; text-align: center; font-family: monospace; color: #334155; white-space: nowrap;">
                                                <?php
                                                $startTimeTs = !empty($rLot['scanned_at']) ? strtotime($rLot['scanned_at']) : (!empty($rLot['session_started_at']) ? strtotime($rLot['session_started_at']) : 0);
                                                
                                                $endTimeTs = 0;
                                                if (!empty($rLot['closed_at'])) {
                                                    $endTimeTs = strtotime($rLot['closed_at']);
                                                } elseif (!empty($rLot['action_noted_at'])) {
                                                    $endTimeTs = strtotime($rLot['action_noted_at']);
                                                } elseif (!empty($rLot['session_closed_at'])) {
                                                    $endTimeTs = strtotime($rLot['session_closed_at']);
                                                }
                                                
                                                $dateDisplay = $startTimeTs ? date('d/m/Y', $startTimeTs) : '-';
                                                $startTimeStr = $startTimeTs ? date('H:i', $startTimeTs) : '';
                                                $endTimeStr = $endTimeTs ? date('H:i', $endTimeTs) : '';
                                                
                                                $timeRangeStr = '-';
                                                if ($startTimeStr && $endTimeStr) {
                                                    $timeRangeStr = $startTimeStr . ' - ' . $endTimeStr . ' WIB';
                                                } elseif ($startTimeStr) {
                                                    $timeRangeStr = $startTimeStr . ' WIB';
                                                }

                                                // Calculate duration
                                                $durStr = '';
                                                if ($startTimeTs > 0 && $endTimeTs >= $startTimeTs) {
                                                    $diffSec = $endTimeTs - $startTimeTs;
                                                    $hrs = floor($diffSec / 3600);
                                                    $mins = floor(($diffSec % 3600) / 60);
                                                    $secs = $diffSec % 60;
                                                    if ($hrs > 0) {
                                                        $durStr = "{$hrs}j {$mins}m";
                                                    } elseif ($mins > 0) {
                                                        $durStr = "{$mins}m {$secs}s";
                                                    } else {
                                                        $durStr = "{$secs}s";
                                                    }
                                                }
                                                ?>
                                                <div style="font-weight: 800; color: #0f172a;"><?= $dateDisplay ?></div>
                                                <div style="font-size: 9.5px; color: #64748b; font-weight: 600; font-family: sans-serif; margin-top: 1px;"><?= $timeRangeStr ?></div>
                                                <?php if ($durStr !== ''): ?>
                                                    <div style="margin-top: 2px;">
                                                        <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[9.5px] font-extrabold bg-slate-100 text-slate-700 border border-slate-200" title="Durasi Inspeksi Lot">
                                                            ⏱️ <?= $durStr ?>
                                                        </span>
                                                    </div>
                                                <?php elseif (($rLot['status_code'] ?? '') === 'in_progress' || empty($endTimeTs)): ?>
                                                    <div style="margin-top: 2px;">
                                                        <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[9.5px] font-bold bg-amber-50 text-amber-700 border border-amber-200" title="Inspeksi Masih Berjalan">
                                                            ⏳ Aktif
                                                        </span>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding: 9px 10px; text-align: center; white-space: nowrap;">
                                                <?php if (($rLot['inspection_type'] ?? '') === 'safety_stock'): ?>
                                                    <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2.5px 8.5px; border-radius: 9999px; font-size: 10px; font-weight: 800; background-color: #f3e8ff; color: #6b21a8; border: 1px solid #d8b4fe; box-shadow: 0 1px 2px rgba(107,33,168,0.06);" title="Inspeksi Safety Stock Gudang">
                                                        <svg width="11" height="11" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: #9333ea;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                                        <span>Safety Stock</span>
                                                    </span>
                                                <?php else: ?>
                                                    <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2.5px 8.5px; border-radius: 9999px; font-size: 10px; font-weight: 800; background-color: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; box-shadow: 0 1px 2px rgba(29,78,216,0.06);" title="Inspeksi Jadwal Kanban">
                                                        <svg width="11" height="11" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: #2563eb;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                                        <span>Kanban</span>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding: 9px 10px; font-family: monospace; font-weight: 800; color: #0284c7; white-space: nowrap;">
                                                <?= htmlspecialchars($rLot['part_code']) ?>
                                            </td>
                                            <td style="padding: 9px 10px; font-weight: 700; color: #1e293b;">
                                                <?= htmlspecialchars($rLot['part_name']) ?>
                                            </td>
                                            <td style="padding: 9px 10px; text-align: center; font-weight: 700; color: #64748b;">
                                                <?= htmlspecialchars($rLot['model_name']) ?>
                                            </td>
                                            <td style="padding: 9px 10px; font-family: monospace; font-weight: 700; color: #334155; white-space: nowrap;">
                                                <?= htmlspecialchars($rLot['lot_number'] ?: '-') ?>
                                            </td>
                                            <td style="padding: 9px 10px; font-family: monospace; font-weight: 800; color: #7c3aed; white-space: nowrap;">
                                                <div><?= htmlspecialchars($rLot['ref_number'] ?: '-') ?></div>
                                                
                                            </td>
                                            <td style="padding: 9px 10px; text-align: right; font-family: monospace; font-weight: 800; color: #0f172a; white-space: nowrap;">
                                                <?= number_format($rLot['qty_lot']) ?> pcs
                                            </td>
                                            <td style="padding: 9px 10px; text-align: center; font-family: monospace; font-weight: 700; color: #2563eb; white-space: nowrap;">
                                                <?= !empty($rLot['is_safety_stock']) ? '<span style="color:#7c3aed;font-weight:800;">0 (Lolos SS)</span>' : ((int)$rLot['sample_size'] . ' pcs') ?>
                                            </td>
                                            <td style="padding: 9px 10px; text-align: center; white-space: nowrap;">
                                                <span style="font-family: monospace; font-size: 11px; font-weight: 800; padding: 2px 7px; border-radius: 4px; <?= $hasNg ? 'background: #fef2f2; color: #dc2626; border: 1px solid #fecaca;' : 'background: #f0fdf4; color: #16a34a; border: 1px solid #dcfce7;' ?>">
                                                    <?= (int)$rLot['ng_count'] ?>
                                                </span>
                                            </td>
                                            <td style="padding: 9px 10px;">
                                                <?= $defectHtml ?>
                                            </td>
                                            <td style="padding: 9px 10px; text-align: center; white-space: nowrap;">
                                                <?= renderLotStatusBadge($rLot) ?>
                                            </td>
                                            <td style="padding: 9px 10px; text-align: center; font-family: monospace; font-weight: 800; white-space: nowrap;">
                                                <a href="<?= base_url('modules/inspection/session.php?id=' . $rLot['session_id']) ?>" target="_blank" class="px-2 py-0.5 rounded <?= $isReinspectLot ? 'bg-amber-100 text-amber-900 border-amber-300 hover:bg-amber-200' : 'bg-blue-50 text-blue-700 hover:bg-blue-100 border-blue-200' ?> border inline-flex items-center gap-1 font-mono font-bold text-[11px]" title="<?= $isReinspectLot ? 'Buka Detail Sesi Re-Inspeksi' : 'Buka Detail Sesi' ?>">
                                                    <span>Detail</span>
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                                </a>
                                            </td>
                                            <td style="padding: 9px 10px; text-align: center; font-family: monospace; font-weight: 700; color: #475569; white-space: nowrap;">
                                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-slate-100 text-slate-700 border border-slate-200">
                                                    <?= htmlspecialchars($shiftName) ?>
                                                </span>
                                            </td>
                                            <td style="padding: 9px 10px; font-weight: 700; color: #334155; white-space: nowrap;">
                                                <div class="flex items-center gap-1.5" title="QC Inspector: <?= htmlspecialchars($rLot['inspector_name'] ?? 'QC Inspector') ?>">
                                                    <div class="w-5 h-5 rounded-full bg-purple-100 text-purple-700 font-extrabold flex items-center justify-center text-[10px] border border-purple-200 uppercase">
                                                        <?= mb_substr($rLot['inspector_name'] ?? 'Q', 0, 1) ?>
                                                    </div>
                                                    <span class="truncate max-w-[110px]"><?= htmlspecialchars($rLot['inspector_name'] ?? 'QC Inspector') ?></span>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php elseif ($activeTab === 'safety_stock'): ?>
                <!-- ═══════════════════════════════════════════════════════════════ -->
                <!-- TABEL WORKBOARD SAFETY STOCK (GROUPING PER PART & CHILD LOT TABLE) -->
                <!-- ═══════════════════════════════════════════════════════════════ -->
                <div class="w-full" style="min-width: 0; max-width: 100%;">
                    <div id="ss-table-wrapper" class="overflow-x-auto w-full custom-scrollbar" style="width: 100%; max-width: 100%; -webkit-overflow-scrolling: touch;">
                        <table class="w-full text-left text-xs text-slate-700 table-auto" style="width: 100%; min-width: 1300px; border-collapse: collapse;">
                            <thead class="bg-purple-900 text-white font-bold text-[11px] uppercase tracking-wider select-none">
                                <tr>
                                    <th style="padding: 10px 12px; width: 45px; text-align: center;">#</th>
                                    <th style="padding: 10px 12px; min-width: 200px;">Part Code & Name</th>
                                    <th style="padding: 10px 12px; width: 150px; text-align: center;">Model & Str Loc</th>
                                    <th style="padding: 10px 12px; width: 120px; text-align: center;">Total Lot</th>
                                    <th style="padding: 10px 12px; width: 120px; text-align: right;">Total Qty</th>
                                    <th style="padding: 10px 12px; width: 140px; text-align: center;">Status Akumulasi</th>
                                    <th style="padding: 10px 12px; width: 220px; text-align: right;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200">
                                <?php if (empty($ssKanbans)): ?>
                                    <tr>
                                        <td colspan="7" class="px-4 py-12 text-center text-slate-400">
                                            <svg class="w-10 h-10 text-slate-300 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                            </svg>
                                            <p class="font-bold text-slate-700 text-sm">Tidak ada data item Safety Stock pada filter ini.</p>
                                            <p class="text-xs text-slate-400 mt-1">Ubah filter tanggal di atas atau klik tombol <b>"+ Cek Fisik Safety Stock"</b> untuk memulai pemeriksaan gudang.</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($ssKanbans as $idx => $pg): 
                                        $pCode = $pg['part_code'];
                                        $pLots = $ssPartLots[$pCode] ?? [];
                                        $totalLots = count($pLots);
                                        $totalQty = 0;
                                        $hasActive = false;
                                        $hasNg = false;
                                        $passedCount = 0;
                                        foreach ($pLots as $pl) {
                                            $totalQty += (int)($pl['qty'] ?? 0);
                                            $res = strtolower($pl['lot_result'] ?? '');
                                            $st = strtolower($pl['lot_status'] ?? '');
                                            if ($res === 'in_progress' || $st === 'in_progress' || ($pl['session_status'] ?? '') === 'in_progress') {
                                                $hasActive = true;
                                            }
                                            if ($res === 'rejected' || $st === 'rejected' || !empty($pl['defects'])) {
                                                $hasNg = true;
                                            }
                                            if ($res === 'passed') {
                                                $passedCount++;
                                            }
                                        }
                                    ?>
                                        <!-- Part Group Master Row -->
                                        <tr class="hover:bg-purple-50/50 transition-colors bg-white font-semibold">
                                            <td class="text-center py-3.5 font-bold text-slate-400"><?= $offset + $idx + 1 ?></td>
                                            <td class="py-3.5 px-3">
                                                <span class="font-mono font-extrabold text-purple-700 text-sm block"><?= htmlspecialchars($pCode) ?></span>
                                                <span class="text-xs text-slate-800 font-bold block truncate max-w-xs" title="<?= htmlspecialchars($pg['display_part_name']) ?>"><?= htmlspecialchars($pg['display_part_name']) ?></span>
                                                <span class="text-[10px] text-slate-400 font-medium block">Customer: <?= htmlspecialchars($pg['customer'] ?: 'INTERNAL SAFETY STOCK') ?></span>
                                            </td>
                                            <td class="py-3.5 px-3 text-center">
                                                <span class="font-bold text-slate-700 text-xs block"><?= htmlspecialchars($pg['model_name']) ?></span>
                                                <span class="px-1.5 py-0.5 rounded text-[9.5px] font-semibold bg-slate-100 text-slate-600 border border-slate-200 inline-block mt-0.5">
                                                    <?= htmlspecialchars($pg['str_loc'] ?: 'WH-SS') ?>
                                                </span>
                                            </td>
                                            <td class="py-3.5 px-3 text-center">
                                                <span class="px-2.5 py-1 rounded-full text-xs font-black bg-purple-100 text-purple-800 border border-purple-200 inline-flex items-center gap-1">
                                                    📦 <?= $totalLots ?> Lot Inspek
                                                </span>
                                            </td>
                                            <td class="py-3.5 px-3 text-right font-black text-purple-950 text-sm">
                                                <?= number_format($totalQty) ?> <span class="text-xs font-medium text-slate-500">pcs</span>
                                            </td>
                                            <td class="py-3.5 px-3 text-center">
                                                <?php if ($hasActive): ?>
                                                    <span class="px-2.5 py-1 rounded-full text-[10.5px] font-extrabold bg-amber-500 text-white shadow-xs inline-flex items-center gap-1">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-white animate-pulse"></span>
                                                        Sedang Dikerjakan
                                                    </span>
                                                <?php elseif ($hasNg): ?>
                                                    <span class="px-2.5 py-1 rounded-full text-[10.5px] font-extrabold bg-rose-600 text-white shadow-xs inline-flex items-center gap-1">
                                                        ⚠️ Ada NG
                                                    </span>
                                                <?php elseif ($totalLots > 0): ?>
                                                    <span class="px-2.5 py-1 rounded-full text-[10.5px] font-extrabold bg-emerald-600 text-white shadow-xs inline-flex items-center gap-1">
                                                        ✓ PASSED (SS)
                                                    </span>
                                                <?php else: ?>
                                                    <span class="px-2.5 py-1 rounded-full text-[10.5px] font-bold bg-slate-100 text-slate-600 border border-slate-200 inline-flex items-center gap-1">
                                                        Belum Ada Lot
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-3.5 px-3 text-right">
                                                <div class="inline-flex items-center gap-1.5">
                                                    <a href="<?= base_url('modules/inspection/session.php?scan_new=1&type=safety_stock&plan_type=safety_stock&part_code=' . urlencode($pCode)) ?>"
                                                       class="px-2.5 py-1.5 bg-purple-600 hover:bg-purple-700 text-white font-extrabold text-xs rounded-lg shadow-xs inline-flex items-center gap-1 transition-all">
                                                        <span>+ Cek Fisik</span>
                                                    </a>
                                                    <button type="button" 
                                                            onclick="toggleSsPartDetail('ss-detail-<?= $idx ?>')"
                                                            class="px-2.5 py-1.5 bg-slate-100 hover:bg-purple-100 text-slate-700 hover:text-purple-800 font-extrabold text-xs rounded-lg border border-slate-300 hover:border-purple-300 inline-flex items-center gap-1 transition-all cursor-pointer">
                                                        <span>Lihat Lot (<?= $totalLots ?>)</span>
                                                        <svg class="w-3.5 h-3.5 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>

                                        <!-- Child Accordion Row (Sub-Tabel Lot Persis Riwayat Saya) -->
                                        <tr id="ss-detail-<?= $idx ?>" class="hidden bg-slate-50/80">
                                            <td colspan="7" class="p-3">
                                                <div class="bg-white rounded-xl border border-purple-200 shadow-sm overflow-hidden">
                                                    <div class="px-4 py-2.5 bg-purple-900 text-white flex items-center justify-between">
                                                        <div class="font-extrabold text-xs flex items-center gap-2">
                                                            <span class="w-2 h-2 rounded-full bg-purple-300 animate-ping"></span>
                                                            <span>RINCIAN LOT INSPEKSI SAFETY STOCK: <?= htmlspecialchars($pCode) ?> - <?= htmlspecialchars($pg['display_part_name']) ?></span>
                                                        </div>
                                                        <span class="text-[10px] font-bold bg-purple-800 px-2 py-0.5 rounded text-purple-100">
                                                            Total <?= $totalLots ?> Lot / Box
                                                        </span>
                                                    </div>

                                                    <div class="overflow-x-auto custom-scrollbar w-full" style="width: 100%; max-width: 100%; -webkit-overflow-scrolling: touch;">
                                                        <table class="w-full text-left text-xs text-slate-700 table-auto" style="width: 100%; min-width: 1450px; border-collapse: collapse;">
                                                            <thead class="bg-slate-900 text-white font-bold text-[10.5px] uppercase tracking-wider select-none">
                                                                <tr>
                                                                    <th style="padding: 9px 10px; width: 40px; text-align: center;">#</th>
                                                                    <th style="padding: 9px 10px; text-align: center; width: 140px;">WAKTU</th>
                                                                    <th style="padding: 9px 10px; width: 120px;">PART CODE</th>
                                                                    <th style="padding: 9px 10px; min-width: 150px;">PART NAME</th>
                                                                    <th style="padding: 9px 10px; text-align: center; width: 95px;">MODEL</th>
                                                                    <th style="padding: 9px 10px; width: 120px;">LOT NO</th>
                                                                    <th style="padding: 9px 10px; width: 110px;">REF NO</th>
                                                                    <th style="padding: 9px 10px; text-align: right; width: 90px;">QTY LOT</th>
                                                                    <th style="padding: 9px 10px; text-align: center; width: 100px;">SAMPLING</th>
                                                                    <th style="padding: 9px 10px; text-align: center; width: 65px;">NG</th>
                                                                    <th style="padding: 9px 10px; min-width: 170px;">DEFECT TEMUAN</th>
                                                                    <th style="padding: 9px 10px; text-align: center; width: 130px;">STATUS LOT</th>
                                                                    <th style="padding: 9px 10px; text-align: center; width: 80px;">SESI</th>
                                                                    <th style="padding: 9px 10px; text-align: center; width: 85px;">SHIFT</th>
                                                                    <th style="padding: 9px 10px; width: 130px;">INSPEKTUR</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody class="divide-y divide-slate-100 text-[11px]">
                                                                <?php if (empty($pLots)): ?>
                                                                    <tr>
                                                                        <td colspan="15" class="py-6 text-center text-slate-400 font-medium">
                                                                            Belum ada lot inspeksi yang dicatat untuk part ini.
                                                                        </td>
                                                                    </tr>
                                                                <?php else: ?>
                                                                    <?php foreach ($pLots as $lIdx => $rLot): 
                                                                        $hasNg = ((int)($rLot['ng_count'] ?? 0) > 0 || !empty($rLot['defects']));
                                                                        $defList = !empty($rLot['defects']) ? $rLot['defects'] : [];
                                                                        if (!empty($defList)) {
                                                                            $defParts = [];
                                                                            foreach ($defList as $df) {
                                                                                $defParts[] = '<span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200/80">' . htmlspecialchars($df['defect_name']) . ' <b class="text-rose-900 font-extrabold">(' . (int)($df['qty_ng'] ?? 1) . ' pcs)</b></span>';
                                                                            }
                                                                            $defectHtml = implode(' ', $defParts);
                                                                        } else {
                                                                            $defectHtml = '<span class="text-slate-400 font-medium">-</span>';
                                                                        }

                                                                        // Waktu calculation
                                                                        $startTimeTs = !empty($rLot['created_at']) ? strtotime($rLot['created_at']) : (!empty($rLot['session_started_at']) ? strtotime($rLot['session_started_at']) : 0);
                                                                        $endTimeTs = !empty($rLot['closed_at']) ? strtotime($rLot['closed_at']) : (!empty($rLot['action_noted_at']) ? strtotime($rLot['action_noted_at']) : (!empty($rLot['session_closed_at']) ? strtotime($rLot['session_closed_at']) : 0));
                                                                        
                                                                        $dateDisplay = $startTimeTs ? date('d/m/Y', $startTimeTs) : '-';
                                                                        $startTimeStr = $startTimeTs ? date('H:i', $startTimeTs) : '';
                                                                        $endTimeStr = $endTimeTs ? date('H:i', $endTimeTs) : '';
                                                                        $timeRangeStr = ($startTimeStr && $endTimeStr) ? ($startTimeStr . ' - ' . $endTimeStr . ' WIB') : ($startTimeStr ? ($startTimeStr . ' WIB') : '-');

                                                                        $durStr = '';
                                                                        if ($startTimeTs > 0 && $endTimeTs >= $startTimeTs) {
                                                                            $diffSec = $endTimeTs - $startTimeTs;
                                                                            $hrs = floor($diffSec / 3600);
                                                                            $mins = floor(($diffSec % 3600) / 60);
                                                                            $secs = $diffSec % 60;
                                                                            if ($hrs > 0) $durStr = "{$hrs}j {$mins}m";
                                                                            elseif ($mins > 0) $durStr = "{$mins}m {$secs}s";
                                                                            else $durStr = "{$secs}s";
                                                                        }
                                                                    ?>
                                                                        <tr class="<?= getLotRowClass($rLot) ?>">
                                                                            <td style="padding: 8px 10px; text-align: center; font-weight: 700; color: #94a3b8;"><?= $lIdx + 1 ?></td>
                                                                            <td style="padding: 8px 10px; text-align: center; font-family: monospace; color: #334155; white-space: nowrap;">
                                                                                <div style="font-weight: 800; color: #0f172a;"><?= $dateDisplay ?></div>
                                                                                <div style="font-size: 9.5px; color: #64748b; font-weight: 600; font-family: sans-serif; margin-top: 1px;"><?= $timeRangeStr ?></div>
                                                                                <?php if ($durStr !== ''): ?>
                                                                                    <div style="margin-top: 2px;">
                                                                                        <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[9.5px] font-extrabold bg-slate-100 text-slate-700 border border-slate-200">
                                                                                            ⏱️ <?= $durStr ?>
                                                                                        </span>
                                                                                    </div>
                                                                                <?php else: ?>
                                                                                    <div style="margin-top: 2px;">
                                                                                        <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[9.5px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                                                                            ⏳ Aktif
                                                                                        </span>
                                                                                    </div>
                                                                                <?php endif; ?>
                                                                            </td>
                                                                            <td style="padding: 8px 10px; font-family: monospace; font-weight: 800; color: #0284c7; white-space: nowrap;">
                                                                                <?= htmlspecialchars($rLot['part_code']) ?>
                                                                            </td>
                                                                            <td style="padding: 8px 10px; font-weight: 700; color: #1e293b;">
                                                                                <?= htmlspecialchars($rLot['part_name']) ?>
                                                                            </td>
                                                                            <td style="padding: 8px 10px; text-align: center; font-weight: 700; color: #64748b;">
                                                                                <?= htmlspecialchars($rLot['model_name']) ?>
                                                                            </td>
                                                                            <td style="padding: 8px 10px; font-family: monospace; font-weight: 700; color: #334155; white-space: nowrap;">
                                                                                <?= htmlspecialchars($rLot['lot_number'] ?: '-') ?>
                                                                            </td>
                                                                            <td style="padding: 8px 10px; font-family: monospace; font-weight: 800; color: #7c3aed; white-space: nowrap;">
                                                                                <?= htmlspecialchars($rLot['ref_number'] ?: '-') ?>
                                                                            </td>
                                                                            <td style="padding: 8px 10px; text-align: right; font-weight: 800; color: #0f172a; white-space: nowrap;">
                                                                                <?= number_format((int)($rLot['qty'] ?? 0)) ?> <span class="text-[10px] font-normal text-slate-500">pcs</span>
                                                                            </td>
                                                                            <td style="padding: 8px 10px; text-align: center; font-weight: 800; color: #2563eb; white-space: nowrap;">
                                                                                <?= number_format((int)($rLot['sample_size'] ?? 0)) ?> <span class="text-[10px] font-normal text-slate-500">pcs</span>
                                                                            </td>
                                                                            <td style="padding: 8px 10px; text-align: center; font-weight: 800; white-space: nowrap;">
                                                                                <?php if ($hasNg): ?>
                                                                                    <span class="inline-block px-2 py-0.5 rounded text-[10px] font-black bg-rose-100 text-rose-700 border border-rose-200">
                                                                                        <?= (int)count($rLot['defects']) ?>
                                                                                    </span>
                                                                                <?php else: ?>
                                                                                    <span class="inline-block px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                                                        0
                                                                                    </span>
                                                                                <?php endif; ?>
                                                                            </td>
                                                                            <td style="padding: 8px 10px; font-weight: 600;">
                                                                                <?= $defectHtml ?>
                                                                            </td>
                                                                            <td style="padding: 8px 10px; text-align: center; white-space: nowrap;">
                                                                                <?= renderLotStatusBadge($rLot) ?>
                                                                            </td>
                                                                            <td style="padding: 8px 10px; text-align: center; white-space: nowrap;">
                                                                                <a href="<?= base_url('modules/inspection/session.php?id=' . (int)$rLot['inspection_session_id']) ?>"
                                                                                   class="px-2 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded font-bold text-[10px] inline-flex items-center gap-1 border border-slate-300">
                                                                                    <span>Lihat</span>
                                                                                </a>
                                                                            </td>
                                                                            <td style="padding: 8px 10px; text-align: center; font-family: monospace; font-weight: 700; color: #475569; white-space: nowrap;">
                                                                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-slate-100 text-slate-700 border border-slate-200">
                                                                                    <?= htmlspecialchars(getShiftName($rLot['created_at'] ?? $rLot['session_started_at'])) ?>
                                                                                </span>
                                                                            </td>
                                                                            <td style="padding: 8px 10px; font-weight: 700; color: #334155; white-space: nowrap;">
                                                                                <div class="flex items-center gap-1.5" title="QC Inspector: <?= htmlspecialchars($rLot['inspector_name'] ?? 'QC Inspector') ?>">
                                                                                    <div class="w-5 h-5 rounded-full bg-purple-100 text-purple-700 font-extrabold flex items-center justify-center text-[10px] border border-purple-200 uppercase">
                                                                                        <?= mb_substr($rLot['inspector_name'] ?? 'Q', 0, 1) ?>
                                                                                    </div>
                                                                                    <span class="truncate max-w-[110px]"><?= htmlspecialchars($rLot['inspector_name'] ?? 'QC Inspector') ?></span>
                                                                                </div>
                                                                            </td>
                                                                        </tr>
                                                                    <?php endforeach; ?>
                                                                <?php endif; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php else: ?>
                <!-- ═══════════════════════════════════════════════════════════════ -->
                <!-- TABEL JADWAL KANBAN DELIVERY                                    -->
                <!-- ═══════════════════════════════════════════════════════════════ -->
                <div class="w-full">
                    <table class="w-full text-left text-xs text-slate-700 table-fixed" style="border-collapse: collapse;">
                        <colgroup>
                            <col style="width: 38px">
                            <col style="width: 19%">
                            <col style="width: 25%">
                            <col style="width: 16%">
                            <col style="width: 15%">
                            <col style="width: 11%">
                            <col>
                        </colgroup>
                        <thead class="bg-slate-50 text-slate-600 font-bold border-b border-slate-200 uppercase tracking-wider text-[10px]">
                            <tr>
                                <th class="text-center px-2 py-2.5">No</th>
                                <th class="px-3 py-2.5">Kanban</th>
                                <th class="px-3 py-2.5">Part</th>
                                <th class="px-2.5 py-2.5 whitespace-nowrap">Jadwal & ETA</th>
                                <th class="px-2.5 py-2.5 text-center whitespace-nowrap">Progres</th>
                                <th class="px-2.5 py-2.5 text-center whitespace-nowrap">Status</th>
                                <th class="px-3 py-2.5 text-right whitespace-nowrap">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (empty($kanbans)): ?>
                                <tr>
                                    <td colspan="7" class="px-4 py-10 text-center text-slate-400">
                                        <svg class="w-8 h-8 text-slate-300 mx-auto mb-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                                        </svg>
                                        <p class="font-bold text-slate-600 text-xs">Tidak ada data jadwal Kanban pada periode ini.</p>
                                        <p class="text-[11px] text-slate-400 mt-0.5">Ubah filter tanggal atau klik "Mulai Inspeksi Baru".</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($kanbans as $idx => $k): 
                                    $kid = (int)$k['id'];
                                    $kSessions = $sessionsByKanban[$kid] ?? [];
                                    $totalSessions = count($kSessions);
                                    $targetQty = (int)$k['qty'];
                                    $passedQty = (int)$k['total_passed_qty'];
                                    
                                    // Hitung passedQty dan deteksi reject dari data lot riil
                                    $calcPassedQty = 0;
                                    $hasAnyRejLot = false;
                                    foreach ($kSessions as $ks) {
                                        $ksId = (int)$ks['id'];
                                        $sLotsList = $lotsBySession[$ksId] ?? [];
                                        if (!empty($sLotsList)) {
                                            $sessLotPass = 0;
                                            foreach ($sLotsList as $sl) {
                                                $slRes = $sl['lot_result'] ?? '';
                                                $slSt = $sl['lot_status'] ?? '';
                                                if ($slRes === 'passed' && $slSt !== 'replaced') {
                                                    $sessLotPass += (int)($sl['qty'] ?? 0);
                                                }
                                                if ($slRes === 'rejected' || $slSt === 'rejected' || $slSt === 'ng_found') {
                                                    $hasAnyRejLot = true;
                                                }
                                            }
                                            $calcPassedQty += max(0, $sessLotPass - (int)($ks['excess_qty'] ?? 0));
                                        } elseif (($ks['status'] ?? '') === 'passed') {
                                            $calcPassedQty += max(0, (int)$ks['total_scanned_qty'] - (int)($ks['excess_qty'] ?? 0));
                                        }
                                        if (($ks['status'] ?? '') === 'rejected') {
                                            $hasAnyRejLot = true;
                                        }
                                    }
                                    if ($calcPassedQty > 0 || !empty($kSessions)) {
                                        $passedQty = max($passedQty, $calcPassedQty);
                                    }

                                    $progressPct = ($targetQty > 0) ? min(100, round(($passedQty / $targetQty) * 100)) : 0;
                                    $isFullyPassed = ($targetQty > 0 && $passedQty >= $targetQty);
                                    $hasRejectedSession = $hasAnyRejLot;
                                    
                                    $actSess = $activeSessionByKanban[$kid] ?? null;
                                    $hasActiveSession = !empty($actSess);
                                    $isOwnSession = ($hasActiveSession && (int)$actSess['inspector_id'] === $currentUserId);
                                    
                                    $is100Check = (strcasecmp($k['check_type'], '100%') === 0 || strpos($k['check_type'], '100') !== false);
                                    
                                    $schedDate = !empty($k['eta']) ? $k['eta'] : (!empty($k['req_date']) ? $k['req_date'] : $k['created_at']);
                                    $isOverdue = (!$isFullyPassed && $k['status'] !== 'completed' && !empty($schedDate) && date('Y-m-d', strtotime($schedDate)) < date('Y-m-d'));
                                    $isPartial = (!$isFullyPassed && $k['status'] !== 'completed' && ($k['status'] === 'partial' || ($passedQty > 0 && $passedQty < $targetQty)));
                                    $isCompleted = ($isFullyPassed || $k['status'] === 'completed');
                                    $isRejected = ($k['status'] === 'rejected' && $passedQty <= 0);

                                    if ($isOverdue) {
                                        $rowColorClass = 'row-status-overdue';
                                    } elseif ($hasActiveSession) {
                                        $rowColorClass = 'row-status-active';
                                    } elseif ($isPartial) {
                                        $rowColorClass = 'row-status-partial';
                                    } elseif ($isCompleted) {
                                        $rowColorClass = 'row-status-completed';
                                    } elseif ($isRejected) {
                                        $rowColorClass = 'row-status-rejected';
                                    } else {
                                        $rowColorClass = 'row-status-uninspected';
                                    }
                                ?>
                                    <!-- Kanban Master Row -->
                                    <tr id="kanban-row-<?= $kid ?>" 
                                        data-kanban-id="<?= $kid ?>" 
                                        data-kanban-no="<?= htmlspecialchars($k['kanban_no'] ?? '', ENT_QUOTES) ?>"
                                        data-item-code="<?= htmlspecialchars($k['item_code'] ?? '', ENT_QUOTES) ?>"
                                        data-part-name="<?= htmlspecialchars($k['display_part_name'] ?? '', ENT_QUOTES) ?>"
                                        data-customer="<?= htmlspecialchars($k['customer'] ?? 'PT. Indonesia Epson Industry', ENT_QUOTES) ?>"
                                        data-target-qty="<?= (int)$targetQty ?>"
                                        data-passed-qty="<?= (int)$passedQty ?>"
                                        class="transition-colors <?= $rowColorClass ?>" style="vertical-align: middle;">
                                        
                                        <!-- No -->
                                        <td class="w-10 text-center px-2 py-3 font-semibold text-slate-400">
                                            <?= $offset + $idx + 1 ?>
                                        </td>

                                        <!-- No Kanban & Customer -->
                                        <td class="px-3 py-3">
                                            <div class="flex items-center space-x-1.5">
                                                <span class="font-mono font-black text-slate-900 text-xs">
                                                    #<?= htmlspecialchars($k['kanban_no']) ?>
                                                </span>
                                                <?php if ($k['plan_type'] === 'safety_stock'): ?>
                                                    <span class="px-1.5 py-0.2 rounded text-[8.5px] font-bold bg-purple-100 text-purple-800 border border-purple-200">
                                                        SS
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-[11px] text-slate-500 font-medium truncate max-w-[160px]" title="<?= htmlspecialchars($k['customer'] ?? 'PT. Indonesia Epson Industry') ?>">
                                                <?= htmlspecialchars($k['customer'] ?? 'PT. Indonesia Epson Industry') ?>
                                            </div>
                                        </td>

                                        <!-- Part Code & Name -->
                                        <td class="px-3 py-3">
                                            <span class="font-mono font-extrabold text-blue-700 text-xs block">
                                                <?= htmlspecialchars($k['item_code']) ?>
                                            </span>
                                            <span class="text-[11px] text-slate-600 font-medium block truncate max-w-[210px]" title="<?= htmlspecialchars($k['display_part_name']) ?>">
                                                <?= htmlspecialchars($k['display_part_name']) ?>
                                            </span>
                                            <?php if ($is100Check): ?>
                                                <div class="mt-0.5">
                                                    <span class="px-1.5 py-0.2 rounded text-[8.5px] font-bold bg-amber-50 text-amber-800 border border-amber-200 inline-block leading-tight">
                                                        100% Cek
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Jadwal & ETA -->
                                        <td class="px-2.5 py-3 whitespace-nowrap">
                                            <div class="font-medium text-slate-700 text-[10px] flex items-center gap-1.5" title="Tanggal Request (Req Date)">
                                                <svg width="12" height="12" style="width: 12px; height: 12px; min-width: 12px; min-height: 12px;" class="text-slate-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                                <span class="<?= ($isOverdue && empty($k['eta'])) ? 'text-rose-700 font-bold' : '' ?>"><?= !empty($k['req_date']) ? date('d/m/Y H:i', strtotime($k['req_date'])) : '-' ?></span>
                                            </div>
                                            <?php if (!empty($k['eta'])): ?>
                                                <div class="text-[9.5px] font-semibold <?= $isOverdue ? 'text-rose-700 font-bold' : 'text-amber-700' ?> flex items-center gap-1.5 mt-0.5" title="Estimasi Kedatangan (ETA)">
                                                    <svg width="12" height="12" style="width: 12px; height: 12px; min-width: 12px; min-height: 12px;" class="<?= $isOverdue ? 'text-rose-500' : 'text-amber-500' ?> flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                                    <span><?= date('d/m/Y H:i', strtotime($k['eta'])) ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php 
                                                $isCreatedToday = (!empty($k['created_at']) && date('Y-m-d', strtotime($k['created_at'])) === date('Y-m-d'));
                                                $targetDeliveryDate = !empty($k['eta']) ? $k['eta'] : (!empty($k['req_date']) ? $k['req_date'] : null);
                                                $isDeliveryFuture = (!empty($targetDeliveryDate) && date('Y-m-d', strtotime($targetDeliveryDate)) > date('Y-m-d'));
                                            ?>
                                            <?php if ($isOverdue): ?>
                                                <?php
                                                    $schedTs = strtotime($schedDate);
                                                    $daysOver = max(1, (int)floor((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', $schedTs))) / 86400));
                                                ?>
                                                <div class="mt-1 flex items-center gap-1 flex-wrap">
                                                    <span class="px-1.5 py-0.5 rounded text-[8.5px] font-extrabold bg-rose-100 text-rose-800 border border-rose-300 inline-flex items-center gap-1 shadow-2xs" title="Perhatian: Jadwal pengiriman/ETA sudah terlewat! Prioritas Utama!">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-rose-600 animate-pulse"></span> OVERDUE
                                                    </span>
                                                    <span class="px-1.5 py-0.5 rounded text-[8.5px] font-bold bg-rose-50 text-rose-700 border border-rose-200 inline-block" title="Terlambat <?= $daysOver ?> hari dari target ETA">
                                                        Lewat <?= $daysOver ?> hari
                                                    </span>
                                                </div>
                                            <?php elseif ($isCreatedToday && $isDeliveryFuture): ?>
                                                <div class="mt-1 flex items-center gap-1 flex-wrap">
                                                    <span class="px-1.5 py-0.5 rounded text-[8.5px] font-extrabold bg-blue-50 text-blue-700 border border-blue-200 inline-flex items-center gap-1" title="Dibuat hari ini: <?= date('d/m/Y H:i', strtotime($k['created_at'])) ?>">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span> Dibuat Hari Ini
                                                    </span>
                                                    <span class="px-1.5 py-0.5 rounded text-[8.5px] font-extrabold bg-amber-50 text-amber-800 border border-amber-200 inline-block" title="Target Pengiriman">
                                                        Kirim: <?= (date('Y-m-d', strtotime($targetDeliveryDate)) === date('Y-m-d', strtotime('+1 day'))) ? 'Besok' : date('d/m', strtotime($targetDeliveryDate)) ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Target & Progres Scan -->
                                        <td class="px-2 py-3 text-center whitespace-nowrap">
                                            <div id="progress-container-<?= $kid ?>">
                                                <div class="font-mono font-black text-xs text-slate-800">
                                                    <span class="passed-qty-val"><?= number_format($passedQty) ?></span> <span class="text-slate-400 font-normal">/</span> <?= number_format($targetQty) ?> <span class="text-[10px] text-slate-500 font-medium">pcs</span>
                                                </div>
                                                <?php
                                                    if ($isFullyPassed || $progressPct >= 100) {
                                                        $barBg = 'background: linear-gradient(90deg, #10b981 0%, #059669 100%);';
                                                        $barLabel = '100% selesai';
                                                        $labelClass = 'text-emerald-700 font-extrabold';
                                                        $barFillWidth = 100;
                                                    } elseif ($hasActiveSession) {
                                                        $barBg = 'background: linear-gradient(90deg, #f59e0b 0%, #d97706 100%);';
                                                        $barLabel = ($progressPct > 0) ? ($progressPct . '% berjalan') : 'Mulai berjalan';
                                                        $labelClass = 'text-amber-800 font-black';
                                                        $barFillWidth = max(8, $progressPct);
                                                    } elseif ($progressPct > 0) {
                                                        $barBg = 'background: linear-gradient(90deg, #f59e0b 0%, #d97706 100%);';
                                                        $barLabel = $progressPct . '% parsial';
                                                        $labelClass = 'text-amber-800 font-extrabold';
                                                        $barFillWidth = $progressPct;
                                                    } else {
                                                        $barBg = 'background-color: #cbd5e1;';
                                                        $barLabel = 'Belum mulai';
                                                        $labelClass = 'text-slate-400 font-medium';
                                                        $barFillWidth = 0;
                                                    }
                                                ?>
                                                <div class="w-20 rounded-full overflow-hidden mx-auto my-1 bg-slate-200" style="height: 7px; border: 1px solid #cbd5e1;">
                                                    <div class="progress-bar-fill" style="height: 100%; width: <?= $barFillWidth ?>%; <?= $barBg ?> border-radius: 9999px; transition: width 0.3s ease;"></div>
                                                </div>
                                                <span class="text-[10px] <?= $labelClass ?> block leading-none progress-label">
                                                    <?= $barLabel ?>
                                                </span>
                                            </div>
                                        </td>

                                        <!-- Status Kanban (Real-Time Inspector Aware) -->
                                        <td class="px-2.5 py-3 text-center whitespace-nowrap">
                                            <div id="status-container-<?= $kid ?>">
                                                <?php if ($hasActiveSession): 
                                                    $actInspName = htmlspecialchars($actSess['inspector_name']);
                                                    $actDur = (int)($actSess['duration_minutes'] ?? 0);
                                                ?>
                                                    <span style="background-color: #d97706; color: #ffffff; font-weight: 800; font-size: 10.5px; padding: 3.5px 11px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 3px rgba(217, 119, 6, 0.4);">
                                                        <span style="width: 6px; height: 6px; border-radius: 9999px; background-color: #ffffff; display: inline-block;" class="animate-pulse"></span>
                                                        Sedang Dikerjakan
                                                    </span>
                                                    <span class="block text-[9px] text-amber-800 font-bold mt-0.5 truncate max-w-[135px] mx-auto" title="Dikerjakan oleh <?= $actInspName ?> (sejak <?= $actDur ?> mnt lalu)">
                                                        Oleh: <?= $actInspName ?>
                                                    </span>
                                                <?php elseif ($k['status'] === 'completed' || $isFullyPassed): ?>
                                                    <span style="background-color: #16a34a; color: #ffffff; font-weight: 800; font-size: 11px; padding: 3.5px 11px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 3px rgba(22, 163, 74, 0.4);">
                                                        <svg style="width: 11px; height: 11px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                                        Selesai
                                                    </span>
                                                <?php elseif ($isPartial || $k['status'] === 'partial' || ($passedQty > 0 && $passedQty < $targetQty)): ?>
                                                    <div class="flex flex-col items-center gap-1">
                                                        <span style="background-color: #d97706; color: #ffffff; font-weight: 800; font-size: 11px; padding: 3px 10px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 3px rgba(217, 119, 6, 0.35);">
                                                            <svg style="width: 11px; height: 11px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                            Parsial
                                                        </span>
                                                        <?php if ($hasRejectedSession): ?>
                                                            <span style="background-color: #dc2626; color: #ffffff; font-weight: 800; font-size: 9.5px; padding: 2px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3px; box-shadow: 0 1px 2px rgba(220, 38, 38, 0.35);">
                                                                <span style="width: 5px; height: 5px; border-radius: 9999px; background-color: #ffffff; display: inline-block;" class="animate-pulse"></span>
                                                                Ada Reject
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php elseif ($k['status'] === 'rejected'): ?>
                                                    <span style="background-color: #dc2626; color: #ffffff; font-weight: 800; font-size: 10.5px; padding: 3.5px 11px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 3px rgba(220, 38, 38, 0.4);">
                                                        <svg style="width: 11px; height: 11px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>
                                                        Reject
                                                    </span>
                                                <?php else: ?>
                                                    <span class="px-2.5 py-1 rounded-full text-[10.5px] font-bold bg-slate-100 text-slate-600 border border-slate-200 inline-flex items-center gap-1">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>
                                                        Belum Mulai
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>

                                        <!-- Aksi Kanban Row (Contextual: Pemilik vs Laptop Lain vs Mulai Baru) -->
                                        <td class="px-3 py-3 text-right whitespace-nowrap">
                                            <div class="inline-flex items-center space-x-1.5 justify-end">
                                                
                                                <div id="action-container-<?= $kid ?>">
                                                    <?php if ($hasActiveSession): 
                                                        $actSid = (int)$actSess['id'];
                                                        $actInspName = htmlspecialchars($actSess['inspector_name']);
                                                        if ($isOwnSession): ?>
                                                            <a href="<?= base_url('modules/inspection/session.php?id=' . $actSid) ?>" 
                                                               style="background-color: #d97706; color: #ffffff;"
                                                               class="px-3 py-1.5 bg-amber-600 hover:bg-amber-700 active:bg-amber-800 text-white font-bold text-xs rounded-md shadow-xs hover:shadow transition-all inline-flex items-center gap-1.5 whitespace-nowrap flex-nowrap"
                                                               title="Lanjutkan sesi inspeksi Anda">
                                                                <svg class="w-3 h-3 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor" style="fill: #ffffff;">
                                                                    <path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"/>
                                                                </svg>
                                                                <span>Lanjutkan</span>
                                                            </a>
                                                        <?php else: ?>
                                                            <a href="<?= base_url('modules/inspection/session.php?id=' . $actSid . '&view_mode=readonly') ?>" 
                                                               onclick="return confirmOpenSpectatorMode('<?= addslashes($actInspName) ?>')"
                                                               class="px-3 py-1.5 bg-slate-700 hover:bg-slate-800 text-white font-bold text-xs rounded-md shadow-xs hover:shadow transition-all inline-flex items-center gap-1.5 whitespace-nowrap flex-nowrap"
                                                               title="Buka sesi dalam Mode Pantau (Read-Only) tanpa mengganggu sesi yang sedang dikerjakan">
                                                                <svg class="w-3.5 h-3.5 text-amber-300 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                                                <span>Lihat Sesi</span>
                                                            </a>
                                                        <?php endif; ?>
                                                    <?php elseif (!$isFullyPassed && $k['status'] !== 'completed'): ?>
                                                        <?php $remainingQuotaToClose = max(0, $targetQty - $passedQty); ?>
                                                        <div class="inline-flex items-center gap-2 flex-nowrap">
                                                            <a href="<?= base_url('modules/inspection/close_kanban.php?kanban_id=' . $kid) ?>"
                                                               style="background-color: #ffffff; color: #475569; border: 1px solid #cbd5e1;"
                                                               onmouseover="this.style.borderColor='#86efac'; this.style.backgroundColor='#f0fdf4'; this.style.color='#15803d';"
                                                               onmouseout="this.style.borderColor='#cbd5e1'; this.style.backgroundColor='#ffffff'; this.style.color='#475569';"
                                                               class="px-2.5 py-1.5 font-bold text-xs rounded-md shadow-2xs transition-all inline-flex items-center gap-1 whitespace-nowrap flex-nowrap cursor-pointer" 
                                                               title="Tutup Kanban dengan input Lot fisik / scan">
                                                                <svg class="w-3.5 h-3.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                                                <span>Close</span>
                                                            </a>

                                                            <a href="<?= base_url('modules/inspection/session.php?scan_new=1&kanban_id=' . $kid) ?>" 
                                                               style="background-color: #2563eb; color: #ffffff;"
                                                               class="px-3.5 py-1.5 hover:bg-blue-700 active:bg-blue-800 text-white font-extrabold text-xs rounded-md shadow-xs hover:shadow transition-all inline-flex items-center gap-1.5 whitespace-nowrap flex-nowrap" 
                                                               title="Mulai inspeksi pengerjaan Kanban ini">
                                                                <svg class="w-3 h-3 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor" style="fill: #ffffff;">
                                                                    <path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"/>
                                                                </svg>
                                                                <span>Inspeksi</span>
                                                            </a>
                                                        </div>
                                                    <?php else: ?>
                                                        <!-- Selesai (tanpa label redundant) -->
                                                    <?php endif; ?>
                                                </div>

                                                <!-- Tombol Accordion Detail Sesi -->
                                                <button type="button" onclick="toggleKanbanDetail(<?= $kid ?>)" 
                                                        class="px-2 py-1 bg-slate-100 hover:bg-slate-200 active:bg-slate-300 text-slate-700 font-bold text-[11px] rounded-lg border border-slate-200 transition-all inline-flex items-center gap-0.5 cursor-pointer"
                                                        title="Buka / Tutup Rincian Sesi">
                                                    <span>Detail</span>
                                                    <span class="text-[10px] font-bold text-slate-500">(<?= $totalSessions ?>)</span>
                                                    <svg id="chevron-icon-<?= $kid ?>" class="w-3 h-3 transition-transform duration-200 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                                    </svg>
                                                </button>

                                            </div>
                                        </td>
                                    </tr>

                                    <!-- ═══════════════════════════════════════════════════════════ -->
                                    <!-- EXPANDABLE SUB-ROW: DETAIL SESI PER KANBAN (Mulai Sesi 1) -->
                                    <!-- ═══════════════════════════════════════════════════════════ -->
                                    <tr id="kanban-detail-<?= $kid ?>" class="hidden bg-slate-50/70 border-b border-slate-200">
                                        <td colspan="7" class="p-2.5 sm:p-3">
                                            <div class="bg-white border border-slate-200 rounded-lg p-3 shadow-2xs space-y-2.5">
                                                
                                                <div class="flex items-center justify-between border-b border-slate-100 pb-1.5">
                                                    <div class="flex items-center space-x-1.5">
                                                        <span class="w-2 h-2 rounded-full bg-blue-600"></span>
                                                        <h5 class="font-extrabold text-[11px] text-slate-900 uppercase tracking-wider">
                                                            Riwayat Sesi Kanban #<?= htmlspecialchars($k['kanban_no']) ?>
                                                        </h5>
                                                        <span class="text-[10.5px] text-slate-400 font-medium">
                                                            (Total <?= $totalSessions ?> Sesi)
                                                        </span>
                                                    </div>
                                                    <span class="text-[11px] font-semibold text-slate-500">
                                                        Target: <b class="text-slate-800"><?= number_format($targetQty) ?> pcs</b>
                                                    </span>
                                                </div>

                                                <?php if (empty($kSessions)): ?>
                                                    <div class="py-4 text-center text-slate-400 text-xs">
                                                        <p class="font-semibold text-slate-600">Belum ada sesi inspeksi untuk Kanban ini.</p>
                                                        <p class="text-[11px] text-slate-400 mt-0.5">Klik tombol <b>Mulai Inspeksi</b> di atas untuk memulai pemeriksaan pertama.</p>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="w-full overflow-x-auto">
                                                        <table class="w-full text-left text-xs table-fixed" style="border-collapse: collapse; min-width: 560px;">
                                                            <colgroup>
                                                                <col style="width: 64px">
                                                                <col style="width: 26%">
                                                                <col style="width: 18%">
                                                                <col style="width: 22%">
                                                                <col style="width: 12%">
                                                                <col>
                                                            </colgroup>
                                                            <thead class="bg-slate-50 text-slate-500 font-bold border-b border-slate-200 uppercase text-[9px]">
                                                                <tr>
                                                                    <th class="px-2.5 py-1.5 text-center">Sesi</th>
                                                                    <th class="px-2.5 py-1.5">Waktu & Shift</th>
                                                                    <th class="px-2.5 py-1.5">Inspektur</th>
                                                                    <th class="px-2.5 py-1.5 text-center">Qty & Lot</th>
                                                                    <th class="px-2.5 py-1.5 text-center">Status</th>
                                                                    <th class="px-2.5 py-1.5 text-right">Aksi</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody class="divide-y divide-slate-100">
                                                                <?php foreach ($kSessions as $sIdx => $s): 
                                                                    $sid = (int)$s['id'];
                                                                    $sessionNumber = $sIdx + 1;
                                                                    $sLots = $lotsBySession[$sid] ?? [];
                                                                    $shiftName = getShiftName($s['started_at']);
                                                                    $isClosed = !empty($s['closed_at']);
                                                                    $durationText = $isClosed ? (($s['duration_minutes'] > 0) ? ($s['duration_minutes'] . ' mnt') : '< 1 mnt') : 'Berjalan';
                                                                    $isReinsp = !empty($s['is_reinspection']);

                                                                    $subRowClass = 'sub-row-passed';
                                                                    if ($s['status'] === 'rejected') {
                                                                        $subRowClass = 'sub-row-rejected';
                                                                    } elseif ($s['status'] === 'in_progress') {
                                                                        $subRowClass = 'sub-row-progress';
                                                                    }
                                                                ?>
                                                                    <tr class="transition-colors <?= $subRowClass ?>" style="vertical-align: middle;">
                                                                        
                                                                        <!-- Sesi -->
                                                                        <td class="px-2.5 py-2 text-center whitespace-nowrap">
                                                                            <span class="font-extrabold text-blue-700 text-xs">Sesi <?= $sessionNumber ?></span>
                                                                            <?php if ($isReinsp): ?>
                                                                                <span class="block text-[8px] font-extrabold text-amber-700 bg-amber-100 rounded px-1 mt-0.5">RE-INSP</span>
                                                                            <?php endif; ?>
                                                                        </td>

                                                                        <!-- Waktu & Shift (Merged) -->
                                                                        <td class="px-2.5 py-2">
                                                                            <div class="font-bold text-slate-800 text-[11px] leading-tight">
                                                                                <?= date('d M Y, H:i', strtotime($s['started_at'])) ?><?php if (!empty($s['closed_at'])): ?> &ndash; <?= date('H:i', strtotime($s['closed_at'])) ?><?php endif; ?>
                                                                            </div>
                                                                            <div class="text-[9px] text-slate-400 mt-0.5"><?= htmlspecialchars($shiftName) ?> &middot; <?= $durationText ?></div>
                                                                        </td>

                                                                        <!-- Inspektur -->
                                                                        <td class="px-2.5 py-2 font-semibold text-slate-800 text-[11px] truncate" title="<?= htmlspecialchars($s['inspector_name']) ?>">
                                                                            <?= htmlspecialchars($s['inspector_name']) ?>
                                                                        </td>

                                                                        <!-- Qty & Lot -->
                                                                        <td class="px-2.5 py-2 text-center">
                                                                            <div class="font-mono font-black text-slate-900 text-xs"><?= number_format($s['total_scanned_qty']) ?> pcs</div>
                                                                            <div class="text-[9px] text-slate-400 font-mono">Sampel <?= $s['samples_checked'] ?>/<?= $s['sample_size'] ?></div>
                                                                            <?php if (!empty($sLots)): 
                                                                                $passedLotsCount = 0;
                                                                                $passedLotsQty = 0;
                                                                                $rejectedLotsCount = 0;
                                                                                $rejectedLotsQty = 0;
                                                                                $inProgLotsCount = 0;
                                                                                $inProgLotsQty = 0;
                                                                                $lotDetails = [];

                                                                                foreach ($sLots as $lIdx => $lItem) {
                                                                                    $lr = $lItem['lot_result'] ?? '';
                                                                                    $ls = $lItem['lot_status'] ?? 'ok';
                                                                                    $lQty = (int)($lItem['qty'] ?? 0);
                                                                                    $lNo = $lItem['lot_number'] ?? '-';
                                                                                    $lRef = $lItem['ref_number'] ?? ($lIdx + 1);

                                                                                    if ($lr === 'passed' && $ls !== 'replaced') {
                                                                                        $passedLotsCount++;
                                                                                        $passedLotsQty += $lQty;
                                                                                    } elseif ($lr === 'rejected' || $ls === 'rejected' || $ls === 'ng_found') {
                                                                                        $rejectedLotsCount++;
                                                                                        $rejectedLotsQty += $lQty;
                                                                                    } elseif ($lr === 'in_progress') {
                                                                                        $inProgLotsCount++;
                                                                                        $inProgLotsQty += $lQty;
                                                                                    }
                                                                                    $lRes = strtoupper($lr ?: $ls);
                                                                                    $lotDetails[] = "Ref #{$lRef}: Lot {$lNo} ({$lQty} pcs) - {$lRes}";
                                                                                }
                                                                                $lotTooltip = implode("\n", $lotDetails);
                                                                            ?>
                                                                                <div class="mt-1 flex flex-wrap items-center justify-center gap-1" title="<?= htmlspecialchars($lotTooltip) ?>">
                                                                                    <?php if ($passedLotsCount > 0): ?>
                                                                                        <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[9.5px] font-extrabold border border-emerald-300 bg-emerald-50 text-emerald-700 shadow-2xs">
                                                                                            <span class="text-[9px]">✓</span> <?= $passedLotsCount ?> Pass (<?= number_format($passedLotsQty) ?> pcs)
                                                                                        </span>
                                                                                    <?php endif; ?>
                                                                                    <?php if ($rejectedLotsCount > 0): ?>
                                                                                        <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[9.5px] font-extrabold border border-rose-300 bg-rose-50 text-rose-700 shadow-2xs">
                                                                                            <span class="text-[9px]">✕</span> <?= $rejectedLotsCount ?> NG (<?= number_format($rejectedLotsQty) ?> pcs)
                                                                                        </span>
                                                                                    <?php endif; ?>
                                                                                    <?php if ($inProgLotsCount > 0): ?>
                                                                                        <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[9.5px] font-extrabold border border-amber-300 bg-amber-50 text-amber-700 shadow-2xs">
                                                                                            <svg class="w-2.5 h-2.5 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> <?= $inProgLotsCount ?> Draft
                                                                                        </span>
                                                                                    <?php endif; ?>
                                                                                </div>
                                                                            <?php endif; ?>
                                                                        </td>

                                                                        <!-- Status -->
                                                                        <td class="px-2.5 py-2 text-center whitespace-nowrap">
                                                                            <?php if ($s['status'] === 'passed'): ?>
                                                                                <span style="background-color: #16a34a; color: #ffffff; font-weight: 800; font-size: 10px; padding: 2.5px 9px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px; box-shadow: 0 1px 3px rgba(22, 163, 74, 0.4);">
                                                                                    <svg style="width: 10px; height: 10px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                                                                    Passed
                                                                                </span>
                                                                            <?php elseif ($s['status'] === 'rejected'): ?>
                                                                                <span style="background-color: #dc2626; color: #ffffff; font-weight: 800; font-size: 10px; padding: 2.5px 9px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px; box-shadow: 0 1px 3px rgba(220, 38, 38, 0.4);">
                                                                                    <svg style="width: 10px; height: 10px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>
                                                                                    Rejected
                                                                                </span>
                                                                            <?php else: ?>
                                                                                <span style="background-color: #d97706; color: #ffffff; font-weight: 800; font-size: 10px; padding: 2.5px 9px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px; box-shadow: 0 1px 3px rgba(217, 119, 6, 0.4);">
                                                                                    <span style="width: 6px; height: 6px; border-radius: 9999px; background-color: #ffffff; display: inline-block;" class="animate-pulse"></span>
                                                                                    In Progress
                                                                                </span>
                                                                            <?php endif; ?>
                                                                        </td>

                                                                        <!-- Aksi -->
                                                                        <td class="px-2.5 py-2 text-right whitespace-nowrap">
                                                                            <div class="inline-flex items-center gap-1 justify-end">
                                                                                <?php if ($s['status'] === 'in_progress'): ?>
                                                                                    <a href="<?= base_url('modules/inspection/session.php?id=' . $sid) ?>"
                                                                                       style="background-color: #d97706; color: #ffffff;"
                                                                                       class="px-2.5 py-1 bg-amber-600 hover:bg-amber-700 text-white font-bold text-[10.5px] rounded shadow-xs transition-all inline-flex items-center gap-1">
                                                                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path></svg>
                                                                                        Lanjutkan
                                                                                    </a>
                                                                                <?php else: ?>
                                                                                    <a href="<?= base_url('modules/inspection/session.php?id=' . $sid) ?>"
                                                                                       class="px-2 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-[10.5px] rounded border border-slate-200 transition-all inline-flex items-center gap-1">
                                                                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                                                                        Detail
                                                                                    </a>
                                                                                    <?php if ($s['status'] === 'rejected'): ?>
                                                                                        <a href="<?= base_url('modules/inspection/print_rejection.php?session_id=' . $sid) ?>"
                                                                                           target="_blank" rel="noopener"
                                                                                           class="px-2 py-1 bg-rose-50 hover:bg-rose-100 text-rose-700 font-bold text-[10.5px] rounded border border-rose-200 transition-all inline-flex items-center gap-1">
                                                                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                                                                                            Cetak
                                                                                        </a>
                                                                                    <?php endif; ?>
                                                                                <?php endif; ?>
                                                                            </div>
                                                                        </td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
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
            <?php endif; ?>

            <!-- ═══════════════════════════════════════════════════════════════ -->
            <!-- PAGINATION BAR                                                -->
            <!-- ═══════════════════════════════════════════════════════════════ -->
            <?php if ($totalPages > 1): ?>
                <div class="px-4 py-2.5 bg-slate-50 border-t border-slate-200 flex items-center justify-between text-xs text-slate-600 flex-wrap gap-2">
                    <div>
                        Menampilkan <b><?= min($totalItems, $offset + 1) ?></b> s/d <b><?= min($totalItems, $offset + ($activeTab === 'safety_stock' ? count($ssKanbans) : ($activeTab === 'my_inspections' ? count($mySessions) : ($activeTab === 'my_lots' ? count($myLots) : count($kanbans))))) ?></b> dari total <b><?= $totalItems ?></b> <?= ($activeTab === 'safety_stock') ? 'Item Safety Stock' : (($activeTab === 'my_inspections') ? 'Sesi Riwayat Saya' : (($activeTab === 'my_lots') ? 'Lot / Box Saya' : 'Kanban')) ?>
                    </div>
                    <div class="inline-flex space-x-1">
                        <?php 
                        $queryParams = $_GET;
                        ?>
                        <?php if ($page > 1): 
                            $queryParams['page'] = $page - 1;
                        ?>
                            <a href="?<?= http_build_query($queryParams) ?>" class="px-2 py-0.5 bg-white border border-slate-200 rounded hover:bg-slate-100 font-bold text-[11px]">&laquo; Prev</a>
                        <?php endif; ?>

                        <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): 
                            $queryParams['page'] = $p;
                        ?>
                            <a href="?<?= http_build_query($queryParams) ?>" class="px-2 py-0.5 rounded font-bold text-[11px] <?= ($p === $page) ? ($activeTab === 'safety_stock' ? 'bg-purple-600 text-white' : ($activeTab === 'my_inspections' ? 'bg-indigo-600 text-white' : ($activeTab === 'my_lots' ? 'bg-sky-600 text-white' : 'bg-blue-600 text-white'))) : 'bg-white border border-slate-200 hover:bg-slate-100 text-slate-700' ?>">
                                <?= $p ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): 
                            $queryParams['page'] = $page + 1;
                        ?>
                            <a href="?<?= http_build_query($queryParams) ?>" class="px-2 py-0.5 bg-white border border-slate-200 rounded hover:bg-slate-100 font-bold text-[11px]">Next &raquo;</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

        </div>

    </main>

    <script>
    /**
     * Horizontal Scroll Helper for My Lots Flat Table
     */
    function scrollMyLotsTable(offset) {
        var wrapper = document.getElementById('my-lots-table-wrapper');
        if (wrapper) {
            wrapper.scrollBy({ left: offset, behavior: 'smooth' });
        }
    }

    // Attach horizontal scroll on mousewheel when hovering over table
    document.addEventListener('DOMContentLoaded', function() {
        var wrapper = document.getElementById('my-lots-table-wrapper');
        if (wrapper) {
            wrapper.addEventListener('wheel', function(e) {
                if (e.deltaY !== 0 && wrapper.scrollWidth > wrapper.clientWidth) {
                    var canScrollLeft = wrapper.scrollLeft > 0 && e.deltaY < 0;
                    var canScrollRight = (wrapper.scrollLeft < wrapper.scrollWidth - wrapper.clientWidth - 1) && e.deltaY > 0;
                    if (canScrollLeft || canScrollRight) {
                        e.preventDefault();
                        wrapper.scrollLeft += (e.deltaY * 1.2);
                    }
                }
            }, { passive: false });
        }
    });

    /**
     * Set Preset Period and Submit Filter Form
     */
    function setKanbanPeriod(p) {
        var input = document.getElementById('kanbanPeriodInput');
        if (input) input.value = p;
        var s = document.querySelector('input[name="start_date"]');
        var e = document.querySelector('input[name="end_date"]');
        if (s) s.value = '';
        if (e) e.value = '';
        var form = document.getElementById('kanbanFilterForm');
        if (form) form.submit();
    }

    /**
     * Smooth Accordion Toggle for My Inspection Session Details
     */
    function toggleMyDetail(sid) {
        var row = document.getElementById('my-detail-' + sid);
        var icon = document.getElementById('my-chevron-' + sid);
        if (!row) return;

        if (row.classList.contains('hidden')) {
            row.classList.remove('hidden');
            if (icon) icon.style.transform = 'rotate(180deg)';
        } else {
            row.classList.add('hidden');
            if (icon) icon.style.transform = 'rotate(0deg)';
        }
    }

    /**
     * Smooth Accordion Toggle for Kanban Session Details
     */
    function toggleKanbanDetail(kid) {
        var row = document.getElementById('kanban-detail-' + kid);
        var icon = document.getElementById('chevron-icon-' + kid);
        if (!row) return;

        if (row.classList.contains('hidden')) {
            row.classList.remove('hidden');
            if (icon) icon.style.transform = 'rotate(180deg)';
        } else {
            row.classList.add('hidden');
            if (icon) icon.style.transform = 'rotate(0deg)';
        }
    }

    /**
     * Smooth Accordion Toggle for Safety Stock Session Details & Part Groups
     */
    function toggleSsPartDetail(id) {
        var row = document.getElementById(id);
        if (!row) return;
        row.classList.toggle('hidden');
    }

    function toggleSsDetail(sid) {
        var row = document.getElementById('ss-detail-' + sid);
        var icon = document.getElementById('ss-chevron-' + sid);
        if (!row) return;

        if (row.classList.contains('hidden')) {
            row.classList.remove('hidden');
            if (icon) icon.style.transform = 'rotate(180deg)';
        } else {
            row.classList.add('hidden');
            if (icon) icon.style.transform = 'rotate(0deg)';
        }
    }

    /**
     * Confirmation when entering a session active by another inspector
     */
    function confirmOpenSpectatorMode(inspectorName) {
        return confirm("Kanban ini sedang aktif diinspeksi oleh " + inspectorName + ".\n\nAnda akan membuka sesi ini dalam MODE PANTAU (Read-Only) agar tidak mengganggu pengerjaan operator utama.\n\nLanjutkan membuka?");
    }

    /**
     * Auto-Focus & Barcode Scanner Integration for Search Input
     */
    (function initSearchScanner() {
        var searchInput = document.getElementById('kanbanSearchInput');
        if (!searchInput) return;

        // Function to focus and select all text (so scanning immediately overwrites previous value)
        function focusAndSelect() {
            try {
                searchInput.focus();
                if (searchInput.value.length > 0) {
                    searchInput.select();
                }
            } catch (e) {}
        }

        // 1. Initial focus on DOM ready & window load
        setTimeout(focusAndSelect, 80);
        window.addEventListener('load', function() {
            setTimeout(focusAndSelect, 150);
        });

        // 2. Parser for QR Code format (Z1<part_code>|Z2<lot>|...)
        function parseQrScanJs(val) {
            var obj = { Z1: '', Z2: '', Z3: '', Z4: '', Z5: '', is_qr: false };
            if (!val) return obj;
            var trimmed = val.trim();
            if (trimmed.indexOf('|') !== -1 || trimmed.indexOf('Z1') !== -1 || trimmed.indexOf('Z2') !== -1 || trimmed.indexOf('Z5') !== -1) {
                obj.is_qr = true;
                var parts = trimmed.split('|');
                for (var i = 0; i < parts.length; i++) {
                    var p = parts[i].trim();
                    if (p.indexOf('Z1') === 0) obj.Z1 = p.substring(2).trim();
                    else if (p.indexOf('Z2') === 0) obj.Z2 = p.substring(2).trim();
                    else if (p.indexOf('Z3') === 0) obj.Z3 = p.substring(2).trim();
                    else if (p.indexOf('Z4') === 0) obj.Z4 = p.substring(2).trim();
                    else if (p.indexOf('Z5') === 0) obj.Z5 = p.substring(2).trim();
                }
            }
            return obj;
        }

        // 3. Submit HANYA saat Enter (scanner keyboard-wedge mengetik karakter satu per satu
        //    lalu mengirim Enter di akhir). Jangan auto-submit saat mengetik, karena payload
        //    QR akan terpotong (misal baru "Z1" sudah ter-submit).
        var form = document.getElementById('kanbanFilterForm');
        var isSubmitting = false;

        function submitSearchForm() {
            if (!form || isSubmitting) return;
            isSubmitting = true;
            // Bersihkan karakter CR/LF/TAB sisa scanner sebelum dikirim ke backend
            searchInput.value = searchInput.value.replace(/[\r\n\t]+/g, '').trim();
            form.submit();
        }

        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.keyCode === 13) {
                e.preventDefault();
                submitSearchForm();
            }
        });

        // 4. Paste manual (Ctrl+V) payload QR lengkap -> auto-submit setelah teks tertempel
        searchInput.addEventListener('paste', function() {
            setTimeout(function() {
                var qr = parseQrScanJs(searchInput.value);
                if (qr.is_qr && (qr.Z5 || qr.Z2)) {
                    submitSearchForm();
                }
            }, 50);
        });

        // 5. Smart Keyboard / Scanner listener:
        // - Escape key: focus and select
        // - '/' key outside of inputs: focus search
        // - If scanner fires while focus is on table/body, redirect keystroke to searchInput
        document.addEventListener('keydown', function(e) {
            var activeEl = document.activeElement;
            var isInputActive = activeEl && (
                activeEl.tagName === 'INPUT' ||
                activeEl.tagName === 'TEXTAREA' ||
                activeEl.tagName === 'SELECT' ||
                activeEl.isContentEditable
            );

            if (e.key === 'Escape') {
                focusAndSelect();
                return;
            }

            if (e.key === '/' && !isInputActive) {
                e.preventDefault();
                focusAndSelect();
                return;
            }

            if (!isInputActive && !e.ctrlKey && !e.altKey && !e.metaKey && e.key.length === 1) {
                searchInput.focus();
            }
        });

        // 6. When clicking empty space or table background, re-focus search input
        document.addEventListener('click', function(e) {
            var target = e.target;
            if (target && !target.closest('a, button, input, select, textarea, label, [role="button"], details, summary')) {
                focusAndSelect();
            }
        });
    })();

    /**
     * High-Scale Real-Time Workboard Poller (Multi-Laptop Live Sync)
     * Queries lightweight state hash every 4 seconds.
     * Uses O(1) B-Tree point-lookups on server; zero reload or UI disruptions.
     */
    (function initRealTimeWorkboard() {
        var activeTab = '<?= $activeTab ?>';
        if (activeTab !== 'kanban') return;

        var lastLiveHash = '';
        var isPolling = false;
        var pollIntervalMs = 8000;
        var syncTimeElem = document.getElementById('live-sync-time');
        var syncIndicator = document.getElementById('live-sync-indicator');
        var baseUrl = '<?= base_url('modules/inspection/') ?>';

        function pollLiveStatus() {
            if (document.hidden) return;
            if (isPolling) return;

            var rows = document.querySelectorAll('[data-kanban-id]');
            if (!rows || rows.length === 0) return;

            var visibleIds = [];
            rows.forEach(function(r) {
                var kid = r.getAttribute('data-kanban-id');
                if (kid) visibleIds.push(kid);
            });

            if (visibleIds.length === 0) return;

            isPolling = true;
            var url = baseUrl + 'api/get_live_status.php?kanban_ids=' + encodeURIComponent(visibleIds.join(',')) + '&last_hash=' + encodeURIComponent(lastLiveHash);

            fetch(url, { credentials: 'same-origin' })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    isPolling = false;
                    if (!data || !data.success) return;

                    if (syncTimeElem && data.server_time) {
                        syncTimeElem.innerText = data.server_time;
                    }

                    // If server state hash is identical, skip DOM rendering (O(0) client work)
                    if (data.changed === false) {
                        return;
                    }

                    lastLiveHash = data.hash || '';
                    var updates = data.kanban_updates || {};

                    // Update each visible row
                    Object.keys(updates).forEach(function(kidStr) {
                        var info = updates[kidStr];
                        var kid = info.id;
                        var rowElem = document.getElementById('kanban-row-' + kid);
                        var statusContainer = document.getElementById('status-container-' + kid);
                        var actionContainer = document.getElementById('action-container-' + kid);
                        var progressContainer = document.getElementById('progress-container-' + kid);

                        if (!rowElem) return;

                        // 1. Row highlight & status border
                        var statusClasses = [
                            'row-status-overdue',
                            'row-status-active',
                            'row-status-partial',
                            'row-status-completed',
                            'row-status-rejected',
                            'row-status-uninspected'
                        ];
                        statusClasses.forEach(function(cls) { rowElem.classList.remove(cls); });

                        if (info.has_active_session) {
                            rowElem.classList.add('row-status-active');
                        } else if (info.status_type === 'completed' || info.is_fully_passed) {
                            rowElem.classList.add('row-status-completed');
                        } else if (info.status_type === 'partial' || (info.passed_qty > 0 && info.passed_qty < info.target_qty)) {
                            rowElem.classList.add('row-status-partial');
                        } else if (info.status_type === 'rejected') {
                            rowElem.classList.add('row-status-rejected');
                        } else {
                            rowElem.classList.add('row-status-uninspected');
                        }

                        // 2. Status Badge & Active Inspector Attribution
                        if (statusContainer) {
                            if (info.has_active_session) {
                                var inspName = info.active_inspector_name || 'QC Inspector';
                                var dur = info.duration_minutes || 0;
                                statusContainer.innerHTML = 
                                    '<span style="background-color: #d97706; color: #ffffff; font-weight: 800; font-size: 10.5px; padding: 3.5px 11px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 3px rgba(217, 119, 6, 0.4);">' +
                                        '<span style="width: 6px; height: 6px; border-radius: 9999px; background-color: #ffffff; display: inline-block;" class="animate-pulse"></span>' +
                                        'Sedang Dikerjakan' +
                                    '</span>' +
                                    '<span class="block text-[9px] text-amber-800 font-bold mt-0.5 truncate max-w-[135px] mx-auto" title="Dikerjakan oleh ' + escapeHtml(inspName) + ' (sejak ' + dur + ' mnt lalu)">' +
                                        'Oleh: ' + escapeHtml(inspName) +
                                    '</span>';
                            } else if (info.status_type === 'completed' || info.is_fully_passed) {
                                statusContainer.innerHTML = 
                                    '<span style="background-color: #16a34a; color: #ffffff; font-weight: 800; font-size: 11px; padding: 3.5px 11px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 3px rgba(22, 163, 74, 0.4);">' +
                                        '<svg style="width: 11px; height: 11px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>' +
                                        'Selesai' +
                                    '</span>';
                            } else if (info.status_type === 'partial' || (info.passed_qty > 0 && info.passed_qty < info.target_qty)) {
                                var rejectBadgeHtml = (info.has_rejected_session && !info.is_fully_passed && info.status_type !== 'completed')
                                    ? '<span style="background-color: #dc2626; color: #ffffff; font-weight: 800; font-size: 9.5px; padding: 2px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3px; box-shadow: 0 1px 2px rgba(220, 38, 38, 0.35); margin-top: 4px;"><span style="width: 5px; height: 5px; border-radius: 9999px; background-color: #ffffff; display: inline-block;" class="animate-pulse"></span>Ada Reject</span>'
                                    : '';
                                statusContainer.innerHTML = 
                                    '<div class="flex flex-col items-center gap-1">' +
                                        '<span style="background-color: #d97706; color: #ffffff; font-weight: 800; font-size: 11px; padding: 3px 10px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 3px rgba(217, 119, 6, 0.35);">' +
                                            '<svg style="width: 11px; height: 11px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>' +
                                            'Parsial' +
                                        '</span>' +
                                        rejectBadgeHtml +
                                    '</div>';
                            } else if (info.status_type === 'rejected') {
                                statusContainer.innerHTML = 
                                    '<span style="background-color: #dc2626; color: #ffffff; font-weight: 800; font-size: 10.5px; padding: 3.5px 11px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 3px rgba(220, 38, 38, 0.4);">' +
                                        '<svg style="width: 11px; height: 11px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>' +
                                        'Reject' +
                                    '</span>';
                            } else {
                                statusContainer.innerHTML = 
                                    '<span class="px-2.5 py-1 rounded-full text-[10.5px] font-bold bg-slate-100 text-slate-600 border border-slate-200 inline-flex items-center gap-1">' +
                                        '<span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>' +
                                        'Belum Mulai' +
                                    '</span>';
                            }
                        }

                        // 3. Action Buttons (Own session vs Spectator vs Start)
                        if (actionContainer) {
                            if (info.has_active_session) {
                                var actSid = info.active_session_id;
                                var inspName = info.active_inspector_name || 'QC Inspector';
                                if (info.is_own_session) {
                                    actionContainer.innerHTML = 
                                        '<a href="' + baseUrl + 'session.php?id=' + actSid + '" ' +
                                           'style="background-color: #d97706; color: #ffffff;" ' +
                                           'class="px-3 py-1.5 bg-amber-600 hover:bg-amber-700 active:bg-amber-800 text-white font-bold text-xs rounded-md shadow-xs hover:shadow transition-all inline-flex items-center gap-1.5 whitespace-nowrap flex-nowrap" ' +
                                           'title="Lanjutkan sesi inspeksi Anda">' +
                                            '<svg class="w-3 h-3 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor" style="fill: #ffffff;">' +
                                                '<path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"/>' +
                                            '</svg>' +
                                            '<span>Lanjutkan</span>' +
                                        '</a>';
                                } else {
                                    var safeName = inspName.replace(/'/g, "\\'");
                                    actionContainer.innerHTML = 
                                        '<a href="' + baseUrl + 'session.php?id=' + actSid + '&view_mode=readonly" ' +
                                           'onclick="return confirmOpenSpectatorMode(\'' + safeName + '\')" ' +
                                           'class="px-3 py-1.5 bg-slate-700 hover:bg-slate-800 text-white font-bold text-xs rounded-md shadow-xs hover:shadow transition-all inline-flex items-center gap-1.5 whitespace-nowrap flex-nowrap" ' +
                                           'title="Buka sesi dalam Mode Pantau (Read-Only) tanpa mengganggu sesi aktif">' +
                                            '<svg class="w-3.5 h-3.5 text-amber-300 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>' +
                                            '<span>Lihat Sesi</span>' +
                                        '</a>';
                                }
                            } else if (!info.is_fully_passed && info.status_type !== 'completed') {
                                var kNo = rowElem.getAttribute('data-kanban-no') || ('#' + kid);
                                var iCode = rowElem.getAttribute('data-item-code') || '';
                                var pName = rowElem.getAttribute('data-part-name') || '';
                                var cName = rowElem.getAttribute('data-customer') || 'PT. Indonesia Epson Industry';
                                var tQty = parseInt(rowElem.getAttribute('data-target-qty')) || 0;
                                var pQty = parseInt(info.passed_qty) || 0;
                                var remQty = Math.max(0, tQty - pQty);

                                actionContainer.innerHTML = 
                                    '<div class="inline-flex items-center gap-2 flex-nowrap">' +
                                        '<a href="' + baseUrl + 'close_kanban.php?kanban_id=' + kid + '" ' +
                                           'style="background-color: #ffffff; color: #475569; border: 1px solid #cbd5e1;" ' +
                                           'onmouseover="this.style.borderColor=\'#86efac\'; this.style.backgroundColor=\'#f0fdf4\'; this.style.color=\'#15803d\';" ' +
                                           'onmouseout="this.style.borderColor=\'#cbd5e1\'; this.style.backgroundColor=\'#ffffff\'; this.style.color=\'#475569\';" ' +
                                           'class="px-2.5 py-1.5 font-bold text-xs rounded-md shadow-2xs transition-all inline-flex items-center gap-1 whitespace-nowrap flex-nowrap cursor-pointer" ' +
                                           'title="Tutup Kanban dengan input Lot fisik / scan">' +
                                            '<svg class="w-3.5 h-3.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>' +
                                            '<span>Close</span>' +
                                        '</a>' +
                                        '<a href="' + baseUrl + 'session.php?scan_new=1&kanban_id=' + kid + '" ' +
                                           'style="background-color: #2563eb; color: #ffffff;" ' +
                                           'class="px-3.5 py-1.5 hover:bg-blue-700 active:bg-blue-800 text-white font-extrabold text-xs rounded-md shadow-xs hover:shadow transition-all inline-flex items-center gap-1.5 whitespace-nowrap flex-nowrap" ' +
                                           'title="Mulai inspeksi pengerjaan Kanban ini">' +
                                            '<svg class="w-3 h-3 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor" style="fill: #ffffff;">' +
                                                '<path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"/>' +
                                            '</svg>' +
                                            '<span>Inspeksi</span>' +
                                        '</a>' +
                                    '</div>';
                            } else {
                                actionContainer.innerHTML = '';
                            }
                        }

                        // 4. Progress Bar update
                        if (progressContainer) {
                            var passedSpan = progressContainer.querySelector('.passed-qty-val');
                            if (passedSpan) passedSpan.innerText = Number(info.passed_qty).toLocaleString();
                            var barFill = progressContainer.querySelector('.progress-bar-fill');
                            var barLbl = progressContainer.querySelector('.progress-label');
                            if (barFill && barLbl) {
                                if (info.is_fully_passed || info.progress_pct >= 100) {
                                    barFill.style.width = '100%';
                                    barFill.style.background = 'linear-gradient(90deg, #10b981 0%, #059669 100%)';
                                    barLbl.className = 'text-[10px] text-emerald-700 font-extrabold block leading-none progress-label';
                                    barLbl.innerText = '100% selesai';
                                } else if (info.has_active_session) {
                                    barFill.style.width = Math.max(8, info.progress_pct) + '%';
                                    barFill.style.background = 'linear-gradient(90deg, #f59e0b 0%, #d97706 100%)';
                                    barLbl.className = 'text-[10px] text-amber-800 font-black block leading-none progress-label';
                                    barLbl.innerText = (info.progress_pct > 0) ? (info.progress_pct + '% berjalan') : 'Mulai berjalan';
                                } else if (info.progress_pct > 0) {
                                    barFill.style.width = info.progress_pct + '%';
                                    barFill.style.background = 'linear-gradient(90deg, #f59e0b 0%, #d97706 100%)';
                                    barLbl.className = 'text-[10px] text-amber-800 font-extrabold block leading-none progress-label';
                                    barLbl.innerText = info.progress_pct + '% parsial';
                                } else {
                                    barFill.style.width = '0%';
                                    barFill.style.background = '#cbd5e1';
                                    barLbl.className = 'text-[10px] text-slate-400 font-medium block leading-none progress-label';
                                    barLbl.innerText = 'Belum mulai';
                                }
                            }
                        }

                        // 5. Relocate completed row to the bottom of the workboard
                        if (info.is_fully_passed || info.status_type === 'completed') {
                            var tbody = rowElem.parentNode;
                            if (tbody && rowElem !== tbody.lastElementChild) {
                                tbody.appendChild(rowElem);
                                var detailRow = document.getElementById('kanban-detail-' + kid);
                                if (detailRow) tbody.appendChild(detailRow);
                            }
                        }
                    });
                })
                .catch(function(err) {
                    isPolling = false;
                });
        }

        function escapeHtml(text) {
            var div = document.createElement('div');
            div.textContent = text || '';
            return div.innerHTML;
        }

        // Initial poller run and recurrent interval
        setTimeout(pollLiveStatus, 1500);
        setInterval(pollLiveStatus, pollIntervalMs);
    })();
    </script>

    <!-- MODAL DIRECT CLOSE KANBAN (OPTION A: DIRECT PASS) -->
    <div id="modalDirectCloseKanban" 
         style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; width: 100vw; height: 100vh; background-color: rgba(15, 23, 42, 0.65); z-index: 99999; display: none; align-items: center; justify-content: center; padding: 16px; box-sizing: border-box;"
         onclick="if(event.target === this) closeDirectCloseModal();">
        <div style="max-width: 520px; width: 100%; margin: auto; border: 1px solid #e2e8f0; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); border-radius: 16px; overflow: hidden; background-color: #ffffff;">
            
            <!-- Header Modal Solid Emerald -->
            <div style="background-color: #065f46; color: #ffffff; padding: 16px 22px;" class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div style="width: 38px; height: 38px; background-color: rgba(255, 255, 255, 0.18); border: 1px solid rgba(255, 255, 255, 0.25);" class="rounded-xl flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                        </svg>
                    </div>
                    <div>
                        <h3 style="font-size: 15px; font-weight: 800; color: #ffffff; line-height: 1.2;">Konfirmasi Direct Pass (Tutup Kanban)</h3>
                        <p style="font-size: 11px; color: #a7f3d0; margin-top: 2px;">Tutup Kanban tanpa scan fisik · Data otomatis masuk KPI PPM</p>
                    </div>
                </div>
                <button type="button" onclick="closeDirectCloseModal()" style="color: #ffffff; background-color: rgba(255,255,255,0.15);" class="w-8 h-8 rounded-lg hover:opacity-80 flex items-center justify-center font-bold text-sm cursor-pointer transition-opacity">
                    ✕
                </button>
            </div>

            <!-- Body Form -->
            <form id="formDirectCloseKanban" onsubmit="submitDirectClose(event)" class="p-6 space-y-4">
                <input type="hidden" id="dc_kanban_id" name="kanban_id" value="">

                <!-- Kartu Ringkasan Kanban & Part -->
                <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px;" class="space-y-2">
                    <div class="flex items-center justify-between">
                        <span style="font-size: 10px; font-weight: 800; color: #64748b; letter-spacing: 0.5px; text-transform: uppercase;">TARGET KANBAN</span>
                        <span id="dc_customer" style="font-size: 11px; font-weight: 700; color: #334155;" class="truncate max-w-[240px]">-</span>
                    </div>
                    <div style="border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;" class="flex items-center justify-between">
                        <span class="text-sm font-black text-slate-900 font-mono" id="dc_kanban_no">#---</span>
                        <span id="dc_part_code" style="background-color: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; font-family: monospace; font-weight: 800; font-size: 12px; padding: 2px 8px; border-radius: 6px;">---</span>
                    </div>
                    <div>
                        <span style="font-size: 10px; font-weight: 800; color: #64748b; letter-spacing: 0.5px; text-transform: uppercase; display: block;">NAMA PART</span>
                        <span id="dc_part_name" style="font-size: 12px; font-weight: 800; color: #0f172a; margin-top: 2px; display: block;" class="line-clamp-1">---</span>
                    </div>
                </div>

                <!-- Kalkulasi Sisa Qty & AQL Sample -->
                <div class="grid grid-cols-2 gap-3">
                    <div style="background-color: #fffbeb; border: 1px solid #fde68a; border-radius: 12px; padding: 12px;">
                        <span style="font-size: 10px; font-weight: 800; color: #92400e; letter-spacing: 0.5px; text-transform: uppercase; display: block;">QTY DITUTUP</span>
                        <div class="flex items-baseline space-x-1 mt-0.5">
                            <span id="dc_close_qty" style="font-size: 20px; font-weight: 900; color: #78350f; font-family: monospace;">0</span>
                            <span style="font-size: 11px; font-weight: 700; color: #b45309;">pcs</span>
                        </div>
                        <span id="dc_qty_note" style="font-size: 10px; font-weight: 600; color: #b45309; display: block; margin-top: 2px;">Full Qty Kanban</span>
                    </div>

                    <div style="background-color: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px; padding: 12px;">
                        <span style="font-size: 10px; font-weight: 800; color: #166534; letter-spacing: 0.5px; text-transform: uppercase; display: block;">SAMPLE AQL (PASSED)</span>
                        <div class="flex items-baseline space-x-1 mt-0.5">
                            <span id="dc_sample_size" style="font-size: 20px; font-weight: 900; color: #14532d; font-family: monospace;">0</span>
                            <span style="font-size: 11px; font-weight: 700; color: #15803d;">pcs</span>
                        </div>
                        <span style="font-size: 10px; font-weight: 600; color: #15803d; display: block; margin-top: 2px;">Level II · 0 NG (Masuk PPM)</span>
                    </div>
                </div>

                <!-- Penjelasan Transparansi Audit -->
                <div style="background-color: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 10px 12px;" class="flex items-start space-x-2.5 text-xs text-blue-900 leading-relaxed">
                    <svg style="width: 16px; height: 16px; color: #2563eb; flex-shrink: 0; margin-top: 2px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span style="font-size: 11px; color: #1e40af;">Sistem akan mencatat 1 Sesi Inspeksi Passed dengan 1 Lot Virtual agar <b>PPM Dashboard</b> dan riwayat audit mutu tetap 100% akurat.</span>
                </div>

                <!-- Input Catatan/Alasan Opsional -->
                <div>
                    <label for="dc_remarks" style="font-size: 11px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">
                        Alasan / Keterangan <span style="font-weight: 400; color: #94a3b8;">(Opsional)</span>
                    </label>
                    <input type="text" id="dc_remarks" name="remarks" maxlength="150"
                           placeholder="Misal: Instruksi Chief QC / Urgent Shipment / Skip-Lot"
                           style="width: 100%; font-size: 12px; font-weight: 500; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; outline: none; transition: border-color 0.2s;"
                           onfocus="this.style.borderColor='#059669';" onblur="this.style.borderColor='#cbd5e1';">
                </div>

                <!-- Tombol Aksi -->
                <div style="border-top: 1px solid #f1f5f9; padding-top: 12px;" class="flex items-center justify-end space-x-2">
                    <button type="button" onclick="closeDirectCloseModal()" 
                            style="background-color: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; font-size: 12px; font-weight: 700; padding: 8px 16px; border-radius: 8px;"
                            class="hover:bg-slate-200 cursor-pointer transition-colors">
                        Batal
                    </button>
                    <button type="submit" id="btnSubmitDirectClose" 
                            style="background-color: #059669; color: #ffffff; font-size: 12px; font-weight: 800; padding: 8px 18px; border-radius: 8px; box-shadow: 0 1px 3px rgba(5, 150, 105, 0.4);"
                            class="hover:opacity-95 active:scale-95 inline-flex items-center gap-1.5 cursor-pointer transition-all">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                        <span>Ya, Selesaikan Langsung</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function estimateAqlSampleSize(qty) {
        qty = parseInt(qty) || 0;
        if (qty <= 0) return 0;
        if (qty <= 8) return 2;
        if (qty <= 15) return 3;
        if (qty <= 25) return 5;
        if (qty <= 50) return 8;
        if (qty <= 90) return 13;
        if (qty <= 150) return 20;
        if (qty <= 280) return 32;
        if (qty <= 500) return 50;
        if (qty <= 1200) return 80;
        if (qty <= 3200) return 125;
        if (qty <= 10000) return 200;
        if (qty <= 35000) return 315;
        return 500;
    }

    function openDirectCloseModal(kid, kanbanNo, itemCode, partName, customer, remainingQty, targetQty) {
        document.getElementById('dc_kanban_id').value = kid;
        document.getElementById('dc_kanban_no').innerText = '#' + (kanbanNo || kid);
        document.getElementById('dc_customer').innerText = customer || '-';
        document.getElementById('dc_part_code').innerText = itemCode || '-';
        document.getElementById('dc_part_name').innerText = partName || '-';
        
        remainingQty = parseInt(remainingQty) || 0;
        targetQty = parseInt(targetQty) || 0;
        
        document.getElementById('dc_close_qty').innerText = Number(remainingQty).toLocaleString();
        if (remainingQty < targetQty && targetQty > 0) {
            document.getElementById('dc_qty_note').innerText = 'Sisa Kuota (Target: ' + Number(targetQty).toLocaleString() + ' pcs)';
        } else {
            document.getElementById('dc_qty_note').innerText = 'Full Qty Target Kanban';
        }
        
        var sampleEst = estimateAqlSampleSize(remainingQty);
        document.getElementById('dc_sample_size').innerText = Number(sampleEst).toLocaleString();
        document.getElementById('dc_remarks').value = '';
        
        var modal = document.getElementById('modalDirectCloseKanban');
        modal.style.display = 'flex';
    }

    function closeDirectCloseModal() {
        var modal = document.getElementById('modalDirectCloseKanban');
        modal.style.display = 'none';
    }

    function submitDirectClose(e) {
        e.preventDefault();
        var kid = document.getElementById('dc_kanban_id').value;
        var remarks = document.getElementById('dc_remarks').value;
        var btn = document.getElementById('btnSubmitDirectClose');
        
        if (!kid) return;
        
        var origBtnHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path></svg> <span>Menyimpan...</span>';
        
        var formData = new FormData();
        formData.append('kanban_id', kid);
        formData.append('remarks', remarks);
        
        fetch('<?= base_url('modules/inspection/api/close_kanban_direct.php') ?>', {
            method: 'POST',
            body: formData
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            btn.disabled = false;
            btn.innerHTML = origBtnHtml;
            
            if (data && data.success) {
                closeDirectCloseModal();
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Berhasil!',
                        text: data.message,
                        timer: 1600,
                        showConfirmButton: false
                    }).then(function() {
                        window.location.reload();
                    });
                } else {
                    alert(data.message);
                    window.location.reload();
                }
            } else {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal',
                        text: (data && data.message) ? data.message : 'Terjadi kesalahan sistem.'
                    });
                } else {
                    alert((data && data.message) ? data.message : 'Terjadi kesalahan sistem.');
                }
            }
        })
        .catch(function(err) {
            btn.disabled = false;
            btn.innerHTML = origBtnHtml;
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'error',
                    title: 'Error Jaringan',
                    text: 'Gagal menghubungi server. Periksa koneksi Anda.'
                });
            } else {
                alert('Gagal menghubungi server.');
            }
        });
    }

    function scrollMyLotsTable(amount) {
        var wrapper = document.getElementById('my-lots-table-wrapper') || document.getElementById('ss-table-wrapper');
        if (wrapper) {
            wrapper.scrollBy({ left: amount, behavior: 'smooth' });
        }
    }

    function toggleSsPartDetail(rowId) {
        var row = document.getElementById(rowId);
        if (row) {
            row.classList.toggle('hidden');
        }
    }
    </script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
