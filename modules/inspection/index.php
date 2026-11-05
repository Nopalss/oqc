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

        // Fallback: Manfaatkan flag has_prev_rejected yang sudah di-fetch oleh batch query (tanpa query N+1)
        if (!$isReinspect && !empty($lot['has_prev_rejected'])) {
            $isReinspect = true;
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

// Normalize role: cek role session maupun roles table agar tahan terhadap perbedaan huruf besar/kecil
$rawRole = strtolower(trim($currentUser['role'] ?? ''));
$roleNamesList = [$rawRole];

if ($pdo && !empty($currentUser['role_id'])) {
    try {
        $stmtRoleCheck = $pdo->prepare("SELECT role_name, display_name FROM roles WHERE id = :rid LIMIT 1");
        $stmtRoleCheck->execute([':rid' => (int)$currentUser['role_id']]);
        $rRow = $stmtRoleCheck->fetch(PDO::FETCH_ASSOC);
        if ($rRow) {
            $roleNamesList[] = strtolower(trim($rRow['role_name'] ?? ''));
            $roleNamesList[] = strtolower(trim($rRow['display_name'] ?? ''));
        }
    } catch (Exception $eRCheck) {}
}

$adminRoles = ['admin', 'superadmin', 'supervisor', 'chief', 'manager', 'lead', 'leader'];
$isAdminOrSupervisor = false;
foreach ($roleNamesList as $rCheck) {
    if ($rCheck !== '' && in_array($rCheck, $adminRoles, true)) {
        $isAdminOrSupervisor = true;
        break;
    }
}

$inspectorFilterId = (isset($_GET['inspector_id']) && (int)$_GET['inspector_id'] > 0 && $isAdminOrSupervisor) ? (int)$_GET['inspector_id'] : 0;

$allowedTabs = ['kanban', 'safety_stock', 'my_inspections', 'my_lots', 'lot_details'];
$activeTab = (isset($_GET['tab']) && in_array($_GET['tab'], $allowedTabs, true)) ? $_GET['tab'] : 'kanban';
if ($activeTab === 'lot_details' || $activeTab === 'my_inspections') {
    $activeTab = 'my_lots';
}

$viewMode = isset($_GET['view']) ? trim(sanitize($_GET['view'])) : '';
$safetyStockRecommendations = [];
$partSafetyStockMap         = [];
$pagedRecommendations       = [];
$totalSsItems               = 0;
$totalSsPages               = 1;

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
$sourceFilter = isset($_GET['source']) ? trim(sanitize($_GET['source'])) : 'all';
if (!in_array($sourceFilter, ['all', 'manual', 'import'], true)) {
    $sourceFilter = 'all';
}

// Inisialisasi tanggal & range periode
$todayStart = date('Y-m-d 00:00:00');
$todayEnd   = date('Y-m-d 23:59:59');
$dayOfWeek  = (int)date('w');
$wStart     = date('Y-m-d 00:00:00', strtotime("-{$dayOfWeek} days"));
$wEnd       = date('Y-m-d 23:59:59', strtotime("+" . (6 - $dayOfWeek) . " days"));
$mStart     = date('Y-m-01 00:00:00');
$mEnd       = date('Y-m-t 23:59:59');

$periodDates = [];
$todayBatches = [];
$pendingYesterdayCount = 0;
$selectedDate = isset($_GET['selected_date']) ? trim(sanitize($_GET['selected_date'])) : '';

if ($pdo) {
    // 1. Ambil daftar tanggal aktif yang memiliki aktivitas Kanban dalam periode terpilih
    try {
        $dateCond = "1=1";
        $dateParams = [];
        if ($period === 'today') {
            $dateCond = "DATE(COALESCE(b.imported_at, k.created_at)) = CURDATE()";
        } elseif ($period === 'this_week') {
            $dateCond = "DATE(COALESCE(b.imported_at, k.created_at)) BETWEEN :p_w_start AND :p_w_end";
            $dateParams[':p_w_start'] = date('Y-m-d', strtotime($wStart));
            $dateParams[':p_w_end']   = date('Y-m-d', strtotime($wEnd));
        } elseif ($period === 'this_month') {
            $dateCond = "DATE(COALESCE(b.imported_at, k.created_at)) BETWEEN :p_m_start AND :p_m_end";
            $dateParams[':p_m_start'] = date('Y-m-d', strtotime($mStart));
            $dateParams[':p_m_end']   = date('Y-m-d', strtotime($mEnd));
        } elseif ($period === 'custom' && !empty($startDate) && !empty($endDate)) {
            $dateCond = "DATE(COALESCE(b.imported_at, k.created_at)) BETWEEN :p_c_start AND :p_c_end";
            $dateParams[':p_c_start'] = $startDate;
            $dateParams[':p_c_end']   = $endDate;
        }

        $dateSql = "
            SELECT DATE(COALESCE(b.imported_at, k.created_at)) as work_date,
                   COUNT(k.id) as item_count,
                   SUM(CASE WHEN k.status = 'completed' THEN 1 ELSE 0 END) as completed_count
            FROM kanban_items k
            LEFT JOIN kanban_batches b ON b.id = k.batch_id
            WHERE (k.plan_type IS NULL OR k.plan_type = 'kanban')
              AND (k.check_type IS NULL OR k.check_type != 'Safety Stock')
              AND (k.kanban_no IS NULL OR k.kanban_no NOT LIKE 'SS-%')
              AND {$dateCond}
            GROUP BY work_date
            ORDER BY work_date DESC
        ";
        $stmtD = $pdo->prepare($dateSql);
        $stmtD->execute($dateParams);
        $periodDates = $stmtD->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $eDates) {
        $periodDates = [];
    }

    // Tentukan tanggal terpilih default:
    // Khusus preset 'today', selalu prioritaskan dan kunci ke tanggal hari ini secara tegas
    if ($period === 'today') {
        if (empty($selectedDate) || $selectedDate !== date('Y-m-d')) {
            $selectedDate = date('Y-m-d');
        }
    } elseif (empty($selectedDate)) {
        if (!empty($periodDates)) {
            $selectedDate = $periodDates[0]['work_date'];
        } else {
            $selectedDate = date('Y-m-d');
        }
    }

    // 2. Ambil daftar batch khusus untuk tanggal terpilih ($selectedDate)
    try {
        $batchSql = "
            SELECT b.id, b.document_number, b.vendor, b.imported_at, b.import_method,
                   COUNT(k.id) as item_count,
                   SUM(CASE WHEN k.status = 'completed' THEN 1 ELSE 0 END) as completed_count,
                   SUM(CASE WHEN k.status IN ('in_progress', 'partial', 'rejected') THEN 1 ELSE 0 END) as in_progress_count
            FROM kanban_batches b
            JOIN kanban_items k ON k.batch_id = b.id
            WHERE (k.plan_type IS NULL OR k.plan_type = 'kanban')
              AND (k.check_type IS NULL OR k.check_type != 'Safety Stock')
              AND (k.kanban_no IS NULL OR k.kanban_no NOT LIKE 'SS-%')
              AND (
                  DATE(b.imported_at) = :b_sel_date 
                  OR DATE(k.created_at) = :b_sel_date2
              )
            GROUP BY b.id, b.document_number, b.vendor, b.imported_at, b.import_method
            ORDER BY b.imported_at ASC, b.id ASC
        ";
        $stmtB = $pdo->prepare($batchSql);
        $stmtB->execute([
            ':b_sel_date'  => $selectedDate,
            ':b_sel_date2' => $selectedDate
        ]);
        $todayBatches = $stmtB->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $eBatches) {
        $todayBatches = [];
    }

    // 3. Hitung sisa/tunggakan sebelum tanggal terpilih yang belum selesai
    try {
        $stmtPy = $pdo->prepare("
            SELECT COUNT(DISTINCT k.id)
            FROM kanban_items k
            WHERE (k.plan_type IS NULL OR k.plan_type = 'kanban')
              AND (k.check_type IS NULL OR k.check_type != 'Safety Stock')
              AND (k.kanban_no IS NULL OR k.kanban_no NOT LIKE 'SS-%')
              AND DATE(k.created_at) < :py_date
              AND k.status IN ('uninspected', 'in_progress', 'partial', 'rejected')
        ");
        $stmtPy->execute([':py_date' => $selectedDate]);
        $pendingYesterdayCount = (int)$stmtPy->fetchColumn();
    } catch (Exception $ePy) {
        $pendingYesterdayCount = 0;
    }
}

// Default penentuan batch: jika belum ada parameter batch_id di URL dan ada batch di tanggal ini, default ke batch pertama
$scopeFilter = isset($_GET['scope']) ? trim(sanitize($_GET['scope'])) : '';
// Bila pengguna berada di preset 'today', cegah status remain/pending_yesterday terbawa kecuali jika tab kanban secara eksplisit meminta
if ($period === 'today' && !isset($_GET['scope'])) {
    $scopeFilter = 'today';
}
if (!in_array($scopeFilter, ['all', 'today', 'pending_yesterday'], true)) {
    $scopeFilter = '';
}

if (isset($_GET['batch_id'])) {
    $batchFilter = trim(sanitize($_GET['batch_id']));
} else {
    $batchFilter = 'all';
}

if (empty($scopeFilter)) {
    $scopeFilter = 'today';
}

$orderFilter = isset($_GET['order_by']) ? trim(sanitize($_GET['order_by'])) : 'paper';
if (!in_array($orderFilter, ['paper', 'priority'], true)) {
    $orderFilter = 'paper';
}
$typeFilter = isset($_GET['inspection_type']) ? trim(sanitize($_GET['inspection_type'])) : 'all';
if (!in_array($typeFilter, ['all', 'kanban', 'safety_stock'], true)) {
    $typeFilter = 'all';
}
$sort = (isset($_GET['sort']) && $_GET['sort'] === 'asc') ? 'ASC' : 'DESC';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = isset($_GET['limit']) ? max(5, min(500, (int)$_GET['limit'])) : 50;
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
$itemPositionInBatch = [];

// Stats default
$stats = [
    'total_kanban'         => 0,
    'uncompleted_count'    => 0,
    'uninspected_count'    => 0,
    'partial_count'        => 0,
    'in_progress_count'    => 0,
    'completed_count'      => 0,
    'remain_count'         => 0,
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
        $isForceRefresh = isset($_GET['force_refresh']) && $_GET['force_refresh'] == '1';
        if ($isForceRefresh && isset($_SESSION['oqc_badge_cache'])) {
            unset($_SESSION['oqc_badge_cache']);
        }

        $badgeCacheTtl = 30;
        $badgeCacheKey = 'badges_' . $currentUserId . '_' . $period . '_' . md5($startDate . '|' . $endDate);
        $cachedBadges = (!$isForceRefresh) ? ($_SESSION['oqc_badge_cache'][$badgeCacheKey] ?? null) : null;
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
                (k.created_at BETWEEN :kb_t_start1 AND :kb_t_end1)
                OR (
                    k.created_at < :kb_t_start2
                    AND (
                        k.status IN ('uninspected', 'in_progress', 'partial', 'rejected')
                        OR EXISTS (
                            SELECT 1 FROM inspection_sessions s_today 
                            WHERE s_today.kanban_item_id = k.id 
                              AND s_today.started_at BETWEEN :kb_t_start3 AND :kb_t_end3
                        )
                        OR EXISTS (
                            SELECT 1 FROM inspection_sessions s_act
                            WHERE s_act.kanban_item_id = k.id AND s_act.status = 'in_progress'
                        )
                    )
                    AND (
                        k.status != 'completed'
                        OR EXISTS (
                            SELECT 1 FROM inspection_sessions s_act
                            WHERE s_act.kanban_item_id = k.id AND s_act.status = 'in_progress'
                        )
                    )
                )
            )";
            $kbBaseParams[':kb_t_start1'] = $todayStart;
            $kbBaseParams[':kb_t_end1']   = $todayEnd;
            $kbBaseParams[':kb_t_start2'] = $todayStart;
            $kbBaseParams[':kb_t_start3'] = $todayStart;
            $kbBaseParams[':kb_t_end3']   = $todayEnd;
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
            $ssBaseWhere[] = "EXISTS (
                SELECT 1 FROM inspection_sessions s_today 
                JOIN inspection_session_lots isl_chk ON isl_chk.inspection_session_id = s_today.id
                WHERE s_today.kanban_item_id = k.id 
                  AND s_today.inspector_id = :ss_t_uid
                  AND (s_today.started_at BETWEEN :ss_t_start1 AND :ss_t_end1 OR s_today.status = 'in_progress')
            )";
            $ssBaseParams[':ss_t_uid']    = $currentUserId;
            $ssBaseParams[':ss_t_start1'] = $todayStart;
            $ssBaseParams[':ss_t_end1']   = $todayEnd;
        } elseif ($period === 'this_week') {
            $ssBaseWhere[] = "EXISTS (
                SELECT 1 FROM inspection_sessions s_week 
                JOIN inspection_session_lots isl_chk ON isl_chk.inspection_session_id = s_week.id
                WHERE s_week.kanban_item_id = k.id 
                  AND s_week.inspector_id = :ss_w_uid
                  AND (s_week.started_at BETWEEN :ss_w_start1 AND :ss_w_end1 OR s_week.status = 'in_progress')
            )";
            $ssBaseParams[':ss_w_uid']    = $currentUserId;
            $ssBaseParams[':ss_w_start1'] = $wStart;
            $ssBaseParams[':ss_w_end1']   = $wEnd;
        } elseif ($period === 'this_month') {
            $ssBaseWhere[] = "EXISTS (
                SELECT 1 FROM inspection_sessions s_month 
                JOIN inspection_session_lots isl_chk ON isl_chk.inspection_session_id = s_month.id
                WHERE s_month.kanban_item_id = k.id 
                  AND s_month.inspector_id = :ss_m_uid
                  AND (s_month.started_at BETWEEN :ss_m_start1 AND :ss_m_end1 OR s_month.status = 'in_progress')
            )";
            $ssBaseParams[':ss_m_uid']    = $currentUserId;
            $ssBaseParams[':ss_m_start1'] = $mStart;
            $ssBaseParams[':ss_m_end1']   = $mEnd;
        } elseif ($period === 'custom') {
            if (!empty($startDate) && !empty($endDate)) {
                $ssBaseWhere[] = "EXISTS (
                    SELECT 1 FROM inspection_sessions s_custom 
                    JOIN inspection_session_lots isl_chk ON isl_chk.inspection_session_id = s_custom.id
                    WHERE s_custom.kanban_item_id = k.id 
                      AND s_custom.inspector_id = :ss_c_uid
                      AND (s_custom.started_at BETWEEN :ss_c_start1 AND :ss_c_end1 OR s_custom.status = 'in_progress')
                )";
                $cStart = $startDate . ' 00:00:00';
                $cEnd   = $endDate . ' 23:59:59';
                $ssBaseParams[':ss_c_uid']    = $currentUserId;
                $ssBaseParams[':ss_c_start1'] = $cStart;
                $ssBaseParams[':ss_c_end1']   = $cEnd;
            } elseif (!empty($startDate)) {
                $ssBaseWhere[] = "EXISTS (
                    SELECT 1 FROM inspection_sessions s_custom 
                    JOIN inspection_session_lots isl_chk ON isl_chk.inspection_session_id = s_custom.id
                    WHERE s_custom.kanban_item_id = k.id 
                      AND s_custom.inspector_id = :ss_c_uid
                      AND (s_custom.started_at >= :ss_c_start1 OR s_custom.status = 'in_progress')
                )";
                $cStart = $startDate . ' 00:00:00';
                $ssBaseParams[':ss_c_uid']    = $currentUserId;
                $ssBaseParams[':ss_c_start1'] = $cStart;
            } elseif (!empty($endDate)) {
                $ssBaseWhere[] = "EXISTS (
                    SELECT 1 FROM inspection_sessions s_custom 
                    JOIN inspection_session_lots isl_chk ON isl_chk.inspection_session_id = s_custom.id
                    WHERE s_custom.kanban_item_id = k.id 
                      AND s_custom.inspector_id = :ss_c_uid
                      AND (s_custom.started_at <= :ss_c_end1 OR s_custom.status = 'in_progress')
                )";
                $cEnd = $endDate . ' 23:59:59';
                $ssBaseParams[':ss_c_uid']  = $currentUserId;
                $ssBaseParams[':ss_c_end1'] = $cEnd;
            }
        } else {
            // Period 'all' atau periode lainnya: wajib ada lot yang diinspeksi oleh user login
            $ssBaseWhere[] = "EXISTS (
                SELECT 1 FROM inspection_sessions s_all 
                JOIN inspection_session_lots isl_chk ON isl_chk.inspection_session_id = s_all.id
                WHERE s_all.kanban_item_id = k.id 
                  AND s_all.inspector_id = :ss_all_uid
            )";
            $ssBaseParams[':ss_all_uid'] = $currentUserId;
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
        
        // Exclude virtual overflow and sisa excess lots (tetap sertakan lot hasil penutupan kanban)
        $myLotsBaseWhere[] = "(isl.remarks IS NULL OR (
            isl.remarks NOT LIKE '%Alokasi Safety Stock%' 
            AND isl.remarks NOT LIKE '%Sisa Split Safety Stock%' 
            AND isl.remarks NOT LIKE '%Sisa Kelebihan Kanban%' 
        ))";
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

            // Sumber Pembuatan Filter (Manual / Import) untuk Kanban
            if ($sourceFilter === 'manual') {
                $kbBaseWhere[] = "(b.import_method = 'manual' OR b.import_method IS NULL OR b.import_method = '')";
            } elseif ($sourceFilter === 'import') {
                $kbBaseWhere[] = "b.import_method = 'excel_import'";
            }

            // Calculate Kanban KPI Statistics (Sinkron dengan Gelombang Tanggal & Batch yang dipilih)
            $kbStatsWhere = [
                "1=1",
                "(k.plan_type IS NULL OR k.plan_type = 'kanban')",
                "(k.check_type IS NULL OR k.check_type != 'Safety Stock')",
                "(k.kanban_no IS NULL OR k.kanban_no NOT LIKE 'SS-%')",
                "(k.str_loc IS NULL OR k.str_loc NOT IN ('WH-SS-OVERFLOW', 'WH-SS-SPLIT'))"
            ];
            $kbStatsParams = [];

            // KPI Summary selalu mencerminkan populasi gelombang kerja/tanggal terpilih secara stabil ($selectedDate)
            // Mengklik kartu Remain (scope=pending_yesterday) hanya menyaring baris tabel kerja di bawah, angka KPI atas tetap utuh.
            $kbStatsWhere[] = "DATE(COALESCE(b.imported_at, k.created_at)) = :kpi_sel_date";
            $kbStatsParams[':kpi_sel_date'] = $selectedDate;

            // Sinkronisasi filter batch terpilih hanya jika tidak sedang memfilter remain/pending_yesterday
            if ($scopeFilter !== 'pending_yesterday' && $batchFilter !== 'all' && (int)$batchFilter > 0) {
                $kbStatsWhere[] = "k.batch_id = :kpi_batch_id";
                $kbStatsParams[':kpi_batch_id'] = (int)$batchFilter;
            }

            // Search filter
            if ($search !== '') {
                $kbStatsWhere[] = "(k.kanban_no LIKE :kpi_s1 OR k.item_code LIKE :kpi_s2 OR k.item_description LIKE :kpi_s3 OR k.customer LIKE :kpi_s4 OR mp.part_name LIKE :kpi_s5)";
                $kbStatsParams[':kpi_s1'] = '%' . $search . '%';
                $kbStatsParams[':kpi_s2'] = '%' . $search . '%';
                $kbStatsParams[':kpi_s3'] = '%' . $search . '%';
                $kbStatsParams[':kpi_s4'] = '%' . $search . '%';
                $kbStatsParams[':kpi_s5'] = '%' . $search . '%';
            }

            // Sumber Pembuatan Filter
            if ($sourceFilter === 'manual') {
                $kbStatsWhere[] = "(b.import_method = 'manual' OR b.import_method IS NULL OR b.import_method = '')";
            } elseif ($sourceFilter === 'import') {
                $kbStatsWhere[] = "b.import_method = 'excel_import'";
            }

            $kbStatsWhereClause = implode(" AND ", $kbStatsWhere);
            $statsSql = "
                SELECT 
                    COUNT(DISTINCT k.id) as total_kanban,
                    SUM(CASE WHEN k.status = 'uninspected' AND NOT EXISTS (
                        SELECT 1 FROM inspection_sessions s_chk WHERE s_chk.kanban_item_id = k.id AND s_chk.status IN ('in_progress', 'rejected')
                    ) THEN 1 ELSE 0 END) as uninspected_count,
                    SUM(CASE 
                        WHEN (k.status = 'partial' OR (COALESCE((
                            SELECT SUM(CASE 
                                WHEN EXISTS (SELECT 1 FROM inspection_session_lots isl_c WHERE isl_c.inspection_session_id = s2.id) THEN
                                    GREATEST(0, COALESCE((SELECT SUM(CASE WHEN isl2.lot_result IN ('passed', 'skipped') AND (isl2.lot_status IS NULL OR isl2.lot_status != 'replaced') THEN isl2.qty ELSE 0 END)
                                              FROM inspection_session_lots isl2 WHERE isl2.inspection_session_id = s2.id), 0) - COALESCE(s2.excess_qty, 0))
                                WHEN s2.status = 'passed' THEN GREATEST(0, (s2.total_scanned_qty - COALESCE(s2.excess_qty, 0)))
                                ELSE 0
                            END) FROM inspection_sessions s2 WHERE s2.kanban_item_id = k.id
                        ), 0) > 0 AND COALESCE((
                            SELECT SUM(CASE 
                                WHEN EXISTS (SELECT 1 FROM inspection_session_lots isl_c WHERE isl_c.inspection_session_id = s2.id) THEN
                                    GREATEST(0, COALESCE((SELECT SUM(CASE WHEN isl2.lot_result IN ('passed', 'skipped') AND (isl2.lot_status IS NULL OR isl2.lot_status != 'replaced') THEN isl2.qty ELSE 0 END)
                                              FROM inspection_session_lots isl2 WHERE isl2.inspection_session_id = s2.id), 0) - COALESCE(s2.excess_qty, 0))
                                WHEN s2.status = 'passed' THEN GREATEST(0, (s2.total_scanned_qty - COALESCE(s2.excess_qty, 0)))
                                ELSE 0
                            END) FROM inspection_sessions s2 WHERE s2.kanban_item_id = k.id
                        ), 0) < k.qty))
                        AND k.status != 'completed'
                        THEN 1 ELSE 0 END) as partial_count,
                    SUM(CASE 
                        WHEN (k.status != 'completed')
                             AND EXISTS (SELECT 1 FROM inspection_sessions s_chk WHERE s_chk.kanban_item_id = k.id AND s_chk.status = 'in_progress')
                        THEN 1 ELSE 0 END) as in_progress_count,
                    SUM(CASE 
                        WHEN (k.status = 'completed' OR (COALESCE((
                            SELECT SUM(CASE 
                                WHEN EXISTS (SELECT 1 FROM inspection_session_lots isl_c WHERE isl_c.inspection_session_id = s2.id) THEN
                                    GREATEST(0, COALESCE((SELECT SUM(CASE WHEN isl2.lot_result IN ('passed', 'skipped') AND (isl2.lot_status IS NULL OR isl2.lot_status != 'replaced') THEN isl2.qty ELSE 0 END)
                                              FROM inspection_session_lots isl2 WHERE isl2.inspection_session_id = s2.id), 0) - COALESCE(s2.excess_qty, 0))
                                WHEN s2.status = 'passed' THEN GREATEST(0, (s2.total_scanned_qty - COALESCE(s2.excess_qty, 0)))
                                ELSE 0
                            END) FROM inspection_sessions s2 WHERE s2.kanban_item_id = k.id
                        ), 0) >= k.qty AND k.qty > 0))
                        AND NOT EXISTS (
                            SELECT 1 FROM inspection_sessions s_chk WHERE s_chk.kanban_item_id = k.id AND s_chk.status = 'in_progress'
                        ) THEN 1 ELSE 0 END) as completed_count,
                    SUM(CASE 
                        WHEN (k.status != 'completed')
                             AND NOT (COALESCE((
                                 SELECT SUM(CASE 
                                     WHEN EXISTS (SELECT 1 FROM inspection_session_lots isl_c WHERE isl_c.inspection_session_id = s2.id) THEN
                                         GREATEST(0, COALESCE((SELECT SUM(CASE WHEN isl2.lot_result IN ('passed', 'skipped') AND (isl2.lot_status IS NULL OR isl2.lot_status != 'replaced') THEN isl2.qty ELSE 0 END)
                                                   FROM inspection_session_lots isl2 WHERE isl2.inspection_session_id = s2.id), 0) - COALESCE(s2.excess_qty, 0))
                                     WHEN s2.status = 'passed' THEN GREATEST(0, (s2.total_scanned_qty - COALESCE(s2.excess_qty, 0)))
                                     ELSE 0
                                 END) FROM inspection_sessions s2 WHERE s2.kanban_item_id = k.id
                             ), 0) >= k.qty AND k.qty > 0)
                             AND (
                                 k.status = 'rejected' OR EXISTS (
                                     SELECT 1 FROM inspection_sessions s_rej WHERE s_rej.kanban_item_id = k.id AND s_rej.status = 'rejected'
                                 ) OR EXISTS (
                                     SELECT 1 FROM inspection_sessions s_lot
                                     JOIN inspection_session_lots isl_rej ON isl_rej.inspection_session_id = s_lot.id
                                     WHERE s_lot.kanban_item_id = k.id AND (isl_rej.lot_result = 'rejected' OR isl_rej.lot_status = 'rejected')
                                 )
                             ) THEN 1 ELSE 0 END) as rejected_count,
                    SUM(CASE WHEN DATE(k.created_at) = CURDATE() THEN 1 ELSE 0 END) as kanban_today_count,
                    SUM(CASE WHEN DATE(k.created_at) < CURDATE() THEN 1 ELSE 0 END) as kanban_carryover_count,
                    SUM(CASE WHEN DATE(k.created_at) = CURDATE() AND k.status = 'uninspected' THEN 1 ELSE 0 END) as kanban_today_uninspected,
                    SUM(CASE WHEN DATE(k.created_at) = CURDATE() AND k.status = 'completed' THEN 1 ELSE 0 END) as kanban_today_completed
                FROM kanban_items k
                LEFT JOIN kanban_batches b ON b.id = k.batch_id
                LEFT JOIN master_parts mp ON mp.part_code = k.item_code
                WHERE {$kbStatsWhereClause}
            ";
            $stmtStats = $pdo->prepare($statsSql);
            $stmtStats->execute($kbStatsParams);
            $statRow = $stmtStats->fetch(PDO::FETCH_ASSOC);
            if ($statRow) {
                $stats['total_kanban']            = (int)($statRow['total_kanban'] ?? 0);
                $stats['uninspected_count']       = (int)($statRow['uninspected_count'] ?? 0);
                $stats['partial_count']           = (int)($statRow['partial_count'] ?? 0);
                $stats['uncompleted_count']       = $stats['uninspected_count'] + $stats['partial_count'];
                $stats['in_progress_count']       = (int)($statRow['in_progress_count'] ?? 0);
                $stats['completed_count']         = (int)($statRow['completed_count'] ?? 0);
                $stats['remain_count']            = (int)$pendingYesterdayCount;
                $stats['rejected_count']          = (int)($statRow['rejected_count'] ?? 0);
                $stats['kanban_today_count']      = (int)($statRow['kanban_today_count'] ?? 0);
                $stats['kanban_carryover_count']  = (int)($statRow['kanban_carryover_count'] ?? 0);
                $stats['kanban_today_uninspected']= (int)($statRow['kanban_today_uninspected'] ?? 0);
                $stats['kanban_today_completed']  = (int)($statRow['kanban_today_completed'] ?? 0);
            }

            // Hitung sesi in_progress global (termasuk kanban remain/kemarin) agar kartu Sedang Dikerjakan selalu akurat
            try {
                $stmtGlobalInProgress = $pdo->prepare("
                    SELECT COUNT(DISTINCT k.id)
                    FROM kanban_items k
                    JOIN inspection_sessions s_act ON s_act.kanban_item_id = k.id AND s_act.status = 'in_progress'
                    WHERE (k.plan_type IS NULL OR k.plan_type = 'kanban')
                      AND (k.check_type IS NULL OR k.check_type != 'Safety Stock')
                      AND (k.kanban_no IS NULL OR k.kanban_no NOT LIKE 'SS-%')
                      AND (k.str_loc IS NULL OR k.str_loc NOT IN ('WH-SS-OVERFLOW', 'WH-SS-SPLIT'))
                      AND k.status != 'completed'
                ");
                $stmtGlobalInProgress->execute();
                $globalInProgressCount = (int)$stmtGlobalInProgress->fetchColumn();
                if ($globalInProgressCount > $stats['in_progress_count']) {
                    $stats['in_progress_count'] = $globalInProgressCount;
                }
            } catch (Exception $eGip) {
                // fallback to $stats['in_progress_count']
            }

            // Hitung total Lot NG pada Kanban yang terfilter
            $ngLotsSql = "
                SELECT COUNT(DISTINCT isl.id) 
                FROM inspection_session_lots isl
                JOIN inspection_sessions s ON s.id = isl.inspection_session_id
                JOIN kanban_items k ON k.id = s.kanban_item_id
                LEFT JOIN kanban_batches b ON b.id = k.batch_id
                LEFT JOIN master_parts mp ON mp.part_code = k.item_code
                WHERE {$kbStatsWhereClause}
                  AND (
                      isl.lot_result = 'rejected'
                      OR isl.lot_status IN ('rejected', 'ng_quarantine', 'ng_found', 'replaced')
                      OR EXISTS (
                          SELECT 1 FROM inspection_ng_records ngr 
                          WHERE ngr.session_lot_id = isl.id AND (ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)
                      )
                  )
            ";
            $stmtNgLots = $pdo->prepare($ngLotsSql);
            $stmtNgLots->execute($kbStatsParams);
            $stats['ng_lots_count'] = (int)$stmtNgLots->fetchColumn();

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

            // Anti-Session Lock: Release session write lock after badge caching so concurrent browser requests never wait
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            // Build Table Filters for Kanban (Bersih dari carry-over clause sisa hari lampau agar urutan baris murni)
            $tableWhere = [
                "1=1",
                "(k.plan_type IS NULL OR k.plan_type = 'kanban')",
                "(k.check_type IS NULL OR k.check_type != 'Safety Stock')",
                "(k.kanban_no IS NULL OR k.kanban_no NOT LIKE 'SS-%')",
                "(k.str_loc IS NULL OR k.str_loc NOT IN ('WH-SS-OVERFLOW', 'WH-SS-SPLIT'))"
            ];
            $tableParams = [];

            // Scope filter: today vs pending_yesterday berdasarkan $selectedDate
            // Pengecualian: jika filter status 'in_progress', sertakan juga sesi yang sedang aktif berjalan lintas tanggal
            if ($statusFilter === 'in_progress') {
                $tableWhere[] = "(DATE(COALESCE(b.imported_at, k.created_at)) = :kb_filter_sel_date OR EXISTS (SELECT 1 FROM inspection_sessions s_act2 WHERE s_act2.kanban_item_id = k.id AND s_act2.status = 'in_progress'))";
                $tableParams[':kb_filter_sel_date'] = $selectedDate;
            } elseif ($scopeFilter === 'today') {
                $tableWhere[] = "DATE(COALESCE(b.imported_at, k.created_at)) = :kb_filter_sel_date";
                $tableParams[':kb_filter_sel_date'] = $selectedDate;
            } elseif ($scopeFilter === 'pending_yesterday') {
                $tableWhere[] = "DATE(COALESCE(b.imported_at, k.created_at)) < :kb_filter_py_date AND k.status IN ('uninspected', 'in_progress', 'partial', 'rejected')";
                $tableParams[':kb_filter_py_date'] = $selectedDate;
            }

            // Batch ID Filter (jika filter in_progress dan batchFilter spesifik, jangan hilangkan kanban yang sedang aktif dikerjakan)
            if ($batchFilter !== 'all' && (int)$batchFilter > 0) {
                if ($statusFilter === 'in_progress') {
                    $tableWhere[] = "(k.batch_id = :kb_filter_batch_id OR EXISTS (SELECT 1 FROM inspection_sessions s_act3 WHERE s_act3.kanban_item_id = k.id AND s_act3.status = 'in_progress'))";
                } else {
                    $tableWhere[] = "k.batch_id = :kb_filter_batch_id";
                }
                $tableParams[':kb_filter_batch_id'] = (int)$batchFilter;
            }

            if ($statusFilter === 'uncompleted') {
                $tableWhere[] = "k.status != 'completed' 
                    AND NOT (COALESCE((
                        SELECT SUM(CASE 
                            WHEN EXISTS (SELECT 1 FROM inspection_session_lots isl_c WHERE isl_c.inspection_session_id = s2.id) THEN
                                GREATEST(0, COALESCE((SELECT SUM(CASE WHEN isl2.lot_result IN ('passed', 'skipped') AND (isl2.lot_status IS NULL OR isl2.lot_status != 'replaced') THEN isl2.qty ELSE 0 END)
                                          FROM inspection_session_lots isl2 WHERE isl2.inspection_session_id = s2.id), 0) - COALESCE(s2.excess_qty, 0))
                            WHEN s2.status = 'passed' THEN GREATEST(0, (s2.total_scanned_qty - COALESCE(s2.excess_qty, 0)))
                            ELSE 0
                        END) FROM inspection_sessions s2 WHERE s2.kanban_item_id = k.id
                    ), 0) >= k.qty AND k.qty > 0)";
            } elseif ($statusFilter === 'uninspected') {
                $tableWhere[] = "k.status = 'uninspected' AND NOT EXISTS (SELECT 1 FROM inspection_sessions s_act WHERE s_act.kanban_item_id = k.id AND s_act.status = 'in_progress')";
            } elseif ($statusFilter === 'in_progress') {
                $tableWhere[] = "k.status != 'completed' 
                    AND EXISTS (SELECT 1 FROM inspection_sessions s_act WHERE s_act.kanban_item_id = k.id AND s_act.status = 'in_progress')";
            } elseif ($statusFilter === 'partial') {
                $tableWhere[] = "k.status = 'partial' AND k.status != 'completed' AND NOT (COALESCE((
                    SELECT SUM(CASE 
                        WHEN EXISTS (SELECT 1 FROM inspection_session_lots isl_c WHERE isl_c.inspection_session_id = s2.id) THEN
                            GREATEST(0, COALESCE((SELECT SUM(CASE WHEN isl2.lot_result IN ('passed', 'skipped') AND (isl2.lot_status IS NULL OR isl2.lot_status != 'replaced') THEN isl2.qty ELSE 0 END)
                                      FROM inspection_session_lots isl2 WHERE isl2.inspection_session_id = s2.id), 0) - COALESCE(s2.excess_qty, 0))
                        WHEN s2.status = 'passed' THEN GREATEST(0, (s2.total_scanned_qty - COALESCE(s2.excess_qty, 0)))
                        ELSE 0
                    END) FROM inspection_sessions s2 WHERE s2.kanban_item_id = k.id
                ), 0) >= k.qty AND k.qty > 0)";
            } elseif ($statusFilter === 'completed') {
                $tableWhere[] = "(k.status = 'completed' OR (COALESCE((
                    SELECT SUM(CASE 
                        WHEN EXISTS (SELECT 1 FROM inspection_session_lots isl_c WHERE isl_c.inspection_session_id = s2.id) THEN
                            GREATEST(0, COALESCE((SELECT SUM(CASE WHEN isl2.lot_result IN ('passed', 'skipped') AND (isl2.lot_status IS NULL OR isl2.lot_status != 'replaced') THEN isl2.qty ELSE 0 END)
                                      FROM inspection_session_lots isl2 WHERE isl2.inspection_session_id = s2.id), 0) - COALESCE(s2.excess_qty, 0))
                        WHEN s2.status = 'passed' THEN GREATEST(0, (s2.total_scanned_qty - COALESCE(s2.excess_qty, 0)))
                        ELSE 0
                    END) FROM inspection_sessions s2 WHERE s2.kanban_item_id = k.id
                ), 0) >= k.qty AND k.qty > 0))";
            } elseif ($statusFilter === 'rejected') {
                $tableWhere[] = "k.status != 'completed' 
                    AND NOT (COALESCE((
                        SELECT SUM(CASE 
                            WHEN EXISTS (SELECT 1 FROM inspection_session_lots isl_c WHERE isl_c.inspection_session_id = s2.id) THEN
                                GREATEST(0, COALESCE((SELECT SUM(CASE WHEN isl2.lot_result IN ('passed', 'skipped') AND (isl2.lot_status IS NULL OR isl2.lot_status != 'replaced') THEN isl2.qty ELSE 0 END)
                                          FROM inspection_session_lots isl2 WHERE isl2.inspection_session_id = s2.id), 0) - COALESCE(s2.excess_qty, 0))
                            WHEN s2.status = 'passed' THEN GREATEST(0, (s2.total_scanned_qty - COALESCE(s2.excess_qty, 0)))
                            ELSE 0
                        END) FROM inspection_sessions s2 WHERE s2.kanban_item_id = k.id
                    ), 0) >= k.qty AND k.qty > 0)
                    AND (k.status = 'rejected' OR EXISTS (
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
                LEFT JOIN kanban_batches b ON b.id = k.batch_id
                LEFT JOIN master_parts mp ON mp.part_code = k.item_code
                WHERE {$tableWhereClause}
            ";
            $stmtCount = $pdo->prepare($countSql);
            $stmtCount->execute($tableParams);
            $totalItems = (int)$stmtCount->fetchColumn();

            $totalPages = max(1, ceil($totalItems / $limit));
            if ($page > $totalPages) $page = $totalPages;
            $offset = ($page - 1) * $limit;

            // Ordering Logic: 'paper' (Default urutan cetak kertas) vs 'priority'
            if ($orderFilter === 'priority') {
                $kanbanOrderBy = "
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
                ";
            } else {
                // Paper Sequence: Kanban belum selesai di atas, yang sudah selesai otomatis turun ke paling bawah
                $kanbanOrderBy = "
                    CASE 
                        WHEN k.status = 'completed' OR total_passed_qty >= k.qty THEN 1 
                        ELSE 0 
                    END ASC,
                    COALESCE(b.imported_at, k.created_at) ASC,
                    COALESCE(k.batch_id, 0) ASC,
                    k.id ASC
                ";
            }

            // Fetch Kanban Schedule Items
            $sql = "
                SELECT k.*,
                       b.document_number as doc_no,
                       b.import_method,
                       b.imported_at as batch_imported_at,
                       COALESCE(NULLIF(mp.part_name, ''), k.item_description) as display_part_name,
                       COALESCE((
                           SELECT SUM(
                               CASE 
                                   WHEN EXISTS (SELECT 1 FROM inspection_session_lots isl_c WHERE isl_c.inspection_session_id = s2.id) THEN
                                       GREATEST(0, COALESCE((SELECT SUM(CASE WHEN isl2.lot_result IN ('passed', 'skipped') AND (isl2.lot_status IS NULL OR isl2.lot_status != 'replaced') THEN isl2.qty ELSE 0 END)
                                                 FROM inspection_session_lots isl2 WHERE isl2.inspection_session_id = s2.id), 0))
                                   WHEN s2.status = 'passed' THEN
                                       s2.total_scanned_qty
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
                GROUP BY k.id, b.document_number, b.import_method, b.imported_at, mp.part_name
                ORDER BY {$kanbanOrderBy}
                LIMIT {$limit} OFFSET {$offset}
            ";
            $stmtKanban = $pdo->prepare($sql);
            $stmtKanban->execute($tableParams);
            $kanbans = $stmtKanban->fetchAll(PDO::FETCH_ASSOC);

            // Hitung nomor urut relatif per-batch untuk Kanban yang ditampilkan
            $batchIdsInPage = array_unique(array_filter(array_column($kanbans, 'batch_id')));
            if (!empty($batchIdsInPage)) {
                $inBatchStr = implode(',', array_map('intval', $batchIdsInPage));
                try {
                    $posStmt = $pdo->query("
                        SELECT k.id, k.batch_id,
                               (SELECT COUNT(*) FROM kanban_items k2 
                                WHERE k2.batch_id = k.batch_id 
                                  AND (k2.plan_type IS NULL OR k2.plan_type = 'kanban')
                                  AND (k2.check_type IS NULL OR k2.check_type != 'Safety Stock')
                                  AND (k2.kanban_no IS NULL OR k2.kanban_no NOT LIKE 'SS-%')
                                  AND k2.id <= k.id) as pos_in_batch
                        FROM kanban_items k
                        WHERE k.batch_id IN ({$inBatchStr})
                    ");
                    if ($posStmt) {
                        while ($pRow = $posStmt->fetch(PDO::FETCH_ASSOC)) {
                            $itemPositionInBatch[(int)$pRow['id']] = (int)$pRow['pos_in_batch'];
                        }
                    }
                } catch (Exception $ePos) {}
            }

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
            // REKOMENDASI SAFETY STOCK (FIFO) UNTUK TAB KANBAN
            // =====================================================================
            try {
                // Ambil seluruh box/lot safety stock yang berstatus passed dan belum terpakai (Single indexed query, < 25ms)
                $stSsLots = $pdo->query("
                    SELECT 
                        isl.id AS lot_id,
                        isl.inspection_session_id,
                        isl.lot_number,
                        isl.ref_number,
                        COALESCE(isl.qty, ss.total_scanned_qty, 0) AS qty,
                        ss.started_at,
                        isl.created_at,
                        UPPER(TRIM(COALESCE(did.part_code, ki_ss.item_code, ''))) AS part_code
                    FROM inspection_session_lots isl
                    JOIN inspection_sessions ss ON ss.id = isl.inspection_session_id
                    LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
                    LEFT JOIN kanban_items ki_ss ON ki_ss.id = ss.kanban_item_id
                    WHERE ss.inspection_type = 'safety_stock'
                      AND ss.status = 'passed'
                      AND (ss.auto_fulfilled_by_session_id IS NULL OR ss.auto_fulfilled_by_session_id = 0)
                      AND (isl.lot_status IS NULL OR isl.lot_status != 'action_closed')
                      AND (isl.lot_result IS NULL OR isl.lot_result != 'rejected')
                    ORDER BY COALESCE(ss.started_at, isl.created_at) ASC, isl.id ASC
                ");
                $allSsLots = $stSsLots->fetchAll(PDO::FETCH_ASSOC);

                $ssLotsPool = [];
                foreach ($allSsLots as $sl) {
                    $pCode = $sl['part_code'];
                    if (empty($pCode)) continue;
                    if (!isset($ssLotsPool[$pCode])) {
                        $ssLotsPool[$pCode] = [];
                        $partSafetyStockMap[$pCode] = 0;
                    }
                    $ssLotsPool[$pCode][] = [
                        'lot_id'                => $sl['lot_id'],
                        'inspection_session_id' => $sl['inspection_session_id'],
                        'lot_number'            => $sl['lot_number'],
                        'ref_number'            => $sl['ref_number'],
                        'original_qty'          => (int)$sl['qty'],
                        'remaining_qty'         => (int)$sl['qty'],
                        'started_at'            => $sl['started_at'],
                        'created_at'            => $sl['created_at'],
                    ];
                    $partSafetyStockMap[$pCode] += (int)$sl['qty'];
                }

                // Ambil kandidat Kanban untuk target tanggal terpilih ($selectedDate) dan batch ($batchFilter)
                $ssTargetWhere = [
                    "k.status != 'completed'",
                    "(k.plan_type IS NULL OR k.plan_type = 'kanban')",
                    "(k.check_type IS NULL OR k.check_type != 'Safety Stock')",
                    "(k.kanban_no IS NULL OR k.kanban_no NOT LIKE 'SS-%')",
                    "(k.str_loc IS NULL OR k.str_loc NOT IN ('WH-SS-OVERFLOW', 'WH-SS-SPLIT'))"
                ];
                $ssTargetParams = [];

                if ($scopeFilter === 'pending_yesterday') {
                    $ssTargetWhere[] = "DATE(COALESCE(b.imported_at, k.created_at)) < :ss_py_date AND k.status IN ('uninspected', 'in_progress', 'partial', 'rejected')";
                    $ssTargetParams[':ss_py_date'] = $selectedDate;
                } else {
                    $ssTargetWhere[] = "DATE(COALESCE(b.imported_at, k.created_at)) = :ss_sel_date";
                    $ssTargetParams[':ss_sel_date'] = $selectedDate;
                }

                if ($scopeFilter !== 'pending_yesterday' && $batchFilter !== 'all' && (int)$batchFilter > 0) {
                    $ssTargetWhere[] = "k.batch_id = :ss_batch_id";
                    $ssTargetParams[':ss_batch_id'] = (int)$batchFilter;
                }

                if ($search !== '') {
                    $ssTargetWhere[] = "(k.kanban_no LIKE :ss_s1 OR k.item_code LIKE :ss_s2 OR k.item_description LIKE :ss_s3 OR k.customer LIKE :ss_s4)";
                    $ssTargetParams[':ss_s1'] = '%' . $search . '%';
                    $ssTargetParams[':ss_s2'] = '%' . $search . '%';
                    $ssTargetParams[':ss_s3'] = '%' . $search . '%';
                    $ssTargetParams[':ss_s4'] = '%' . $search . '%';
                }

                $ssTargetWhereStr = implode(' AND ', $ssTargetWhere);

                $stmtSsItems = $pdo->prepare("
                    SELECT k.*, 
                           b.document_number as doc_no, b.vendor, b.imported_at as batch_imported_at, b.import_method,
                           mp.part_name as master_part_name,
                           COALESCE((
                               SELECT SUM(
                                   CASE 
                                       WHEN EXISTS (SELECT 1 FROM inspection_session_lots isl_c WHERE isl_c.inspection_session_id = s2.id) THEN
                                           GREATEST(0, COALESCE((SELECT SUM(CASE WHEN isl2.lot_result IN ('passed', 'skipped') AND (isl2.lot_status IS NULL OR isl2.lot_status != 'replaced') THEN isl2.qty ELSE 0 END)
                                                     FROM inspection_session_lots isl2 WHERE isl2.inspection_session_id = s2.id), 0))
                                       WHEN s2.status = 'passed' THEN
                                           s2.total_scanned_qty
                                       ELSE 0
                                   END
                               )
                               FROM inspection_sessions s2
                               WHERE s2.kanban_item_id = k.id
                           ), 0) AS total_passed_qty
                    FROM kanban_items k
                    LEFT JOIN kanban_batches b ON b.id = k.batch_id
                    LEFT JOIN master_parts mp ON mp.part_code = k.item_code
                    WHERE {$ssTargetWhereStr}
                    ORDER BY k.id ASC
                ");
                $stmtSsItems->execute($ssTargetParams);
                $allSsKanbanCandidates = $stmtSsItems->fetchAll(PDO::FETCH_ASSOC);

                // Dynamic FIFO Depletion Pool Allocation:
                // Setiap box yang dialokasikan ke suatu Kanban langsung dipotong dari pool sehingga
                // Kanban berikutnya tidak akan pernah memanggil lot/box yang sama jika sudah habis!
                foreach ($allSsKanbanCandidates as $ck) {
                    $pCode = strtoupper(trim($ck['item_code']));
                    if (empty($ssLotsPool[$pCode])) continue;

                    $currentPool = &$ssLotsPool[$pCode];
                    $poolRemainingQty = 0;
                    foreach ($currentPool as $pItem) {
                        $poolRemainingQty += $pItem['remaining_qty'];
                    }
                    if ($poolRemainingQty <= 0) {
                        continue;
                    }

                    $demand = (int)$ck['qty'];
                    $passed = (int)$ck['total_passed_qty'];
                    $needed = max(0, $demand - $passed);

                    // Jika Kanban sudah selesai (kuota passed >= demand) atau status completed, lewati (jangan tawarkan SS)
                    if ($needed <= 0 || ($ck['status'] ?? '') === 'completed') {
                        continue;
                    }

                    $targetCalc = $needed;

                    $remDemand = $targetCalc;
                    $allocatedBoxes = [];
                    $uniqueLotNumbers = [];
                    $totalAllocatedThisKanban = 0;

                    foreach ($currentPool as &$l) {
                        if ($remDemand <= 0) break;
                        if ($l['remaining_qty'] <= 0) continue;

                        $take = min($l['remaining_qty'], $remDemand);
                        $isSplit = ($take < $l['remaining_qty']) || ($l['remaining_qty'] < $l['original_qty']);

                        $uniqueLotNumbers[$l['lot_number']] = true;
                        $allocatedBoxes[] = [
                            'lot_number' => $l['lot_number'],
                            'ref_number' => $l['ref_number'],
                            'take_qty'   => $take,
                            'box_qty'    => $l['original_qty'],
                            'is_split'   => ($take < $l['original_qty']),
                            'date'       => $l['started_at'] ?: $l['created_at']
                        ];

                        $l['remaining_qty'] -= $take;
                        $remDemand -= $take;
                        $totalAllocatedThisKanban += $take;
                    }
                    unset($l);

                    if (!empty($allocatedBoxes)) {
                        $fulfillType = ($totalAllocatedThisKanban >= $targetCalc) ? 'full' : 'partial';
                        $fulfillPct  = ($targetCalc > 0) ? min(100, round(($totalAllocatedThisKanban / $targetCalc) * 100)) : 0;

                        $safetyStockRecommendations[] = [
                            'item_id'      => $ck['id'],
                            'kanban_no'    => $ck['kanban_no'],
                            'customer'     => $ck['customer'] ?? 'PT. Indonesia Epson Industry',
                            'item_code'    => $pCode,
                            'item_name'    => !empty($ck['item_description']) ? $ck['item_description'] : (!empty($ck['master_part_name']) ? $ck['master_part_name'] : '-'),
                            'demand'       => $demand,
                            'passed_qty'   => $passed,
                            'needed_qty'   => $needed,
                            'status'       => $ck['status'] ?? 'uninspected',
                            'imported_at'  => $ck['batch_imported_at'] ?: $ck['created_at'],
                            'ss_total'     => $totalAllocatedThisKanban,
                            'fulfill_type' => $fulfillType,
                            'fulfill_pct'  => $fulfillPct,
                            'box_count'    => count($allocatedBoxes),
                            'lot_count'    => count($uniqueLotNumbers),
                            'boxes'        => $allocatedBoxes
                        ];
                    }
                }

                $totalSsItems = count($safetyStockRecommendations);
                $totalSsPages = max(1, ceil($totalSsItems / $limit));

                if ($viewMode === 'safety_stock') {
                    $totalItems = $totalSsItems;
                    $totalPages = $totalSsPages;
                    if ($page > $totalPages) $page = $totalPages;
                    $offset = ($page - 1) * $limit;
                    $pagedRecommendations = array_slice($safetyStockRecommendations, $offset, $limit);
                }
            } catch (Exception $eSs) {
                // Fallback aman
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

            // Build Session Period Filter for Safety Stock (khusus user yang sedang login)
            $sessWherePeriod = " AND s.inspector_id = " . (int)$currentUserId;
            if ($period === 'today') {
                $sessWherePeriod .= " AND (s.started_at BETWEEN " . $pdo->quote($todayStart) . " AND " . $pdo->quote($todayEnd) . " OR s.status = 'in_progress')";
            } elseif ($period === 'this_week') {
                $sessWherePeriod .= " AND (s.started_at BETWEEN " . $pdo->quote($wStart) . " AND " . $pdo->quote($wEnd) . " OR s.status = 'in_progress')";
            } elseif ($period === 'this_month') {
                $sessWherePeriod .= " AND (s.started_at BETWEEN " . $pdo->quote($mStart) . " AND " . $pdo->quote($mEnd) . " OR s.status = 'in_progress')";
            } elseif ($period === 'custom') {
                if (!empty($startDate) && !empty($endDate)) {
                    $sessWherePeriod .= " AND (s.started_at BETWEEN " . $pdo->quote($startDate . ' 00:00:00') . " AND " . $pdo->quote($endDate . ' 23:59:59') . " OR s.status = 'in_progress')";
                } elseif (!empty($startDate)) {
                    $sessWherePeriod .= " AND (s.started_at >= " . $pdo->quote($startDate . ' 00:00:00') . " OR s.status = 'in_progress')";
                } elseif (!empty($endDate)) {
                    $sessWherePeriod .= " AND (s.started_at <= " . $pdo->quote($endDate . ' 23:59:59') . " OR s.status = 'in_progress')";
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

            // Anti-Session Lock: Release session write lock after badge caching so concurrent browser requests never wait
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            // Build Table Filters for Safety Stock
            $tableWhere = $ssBaseWhere;
            $tableParams = $ssBaseParams;

            if ($statusFilter === 'uninspected') {
                $tableWhere[] = "k.status = 'uninspected' AND NOT EXISTS (SELECT 1 FROM inspection_sessions s_act WHERE s_act.kanban_item_id = k.id AND s_act.status = 'in_progress')";
            } elseif ($statusFilter === 'in_progress') {
                $tableWhere[] = "EXISTS (SELECT 1 FROM inspection_sessions s_act WHERE s_act.kanban_item_id = k.id AND s_act.status = 'in_progress')";
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
                      AND s.inspector_id = {$currentUserId}
                      -- Sembunyikan sesi virtual (sisa split / alokasi) agar hanya inspeksi fisik asli yang tampil
                      AND (isl.remarks IS NULL OR (
                          isl.remarks NOT LIKE '%Sisa Split Safety Stock%'
                          AND isl.remarks NOT LIKE '%Sisa Kelebihan Kanban%'
                          AND isl.remarks NOT LIKE '%Alokasi Safety Stock%'
                      ))
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

            // Ambil data Part Safety Stock yang KELUAR / DIPAKAI oleh Kanban (untuk Tabel 2)
            $ssUsedWhere = [
                "s.inspector_id = :ss_u_uid",
                "s.inspection_type != 'safety_stock'",
                "(
                    isl.remarks LIKE '%Alokasi Safety Stock%' 
                    OR isl.lot_status = 'safety_stock_alloc'
                    OR s.use_safety_stock_qty > 0
                    OR EXISTS (
                        SELECT 1 
                        FROM inspection_session_lots isl_ss
                        JOIN inspection_sessions s_ss ON s_ss.id = isl_ss.inspection_session_id
                        LEFT JOIN daily_inspection_data d_ss ON d_ss.id = s_ss.did_id
                        LEFT JOIN kanban_items k_ss ON k_ss.id = s_ss.kanban_item_id
                        LEFT JOIN master_parts mp_ss ON mp_ss.id = s_ss.part_id
                        WHERE s_ss.inspection_type = 'safety_stock'
                          AND s_ss.id != s.id
                          AND isl_ss.ref_number IS NOT NULL AND isl_ss.ref_number != '' AND isl_ss.ref_number != '-'
                          AND isl_ss.ref_number = isl.ref_number
                          AND isl_ss.lot_number = isl.lot_number
                          AND COALESCE(d_ss.part_code, k_ss.item_code, mp_ss.part_code) = COALESCE(k.item_code, mp.part_code)
                    )
                )"
            ];
            $ssUsedParams = [
                ':ss_u_uid' => (int)$currentUserId
            ];

            if ($period === 'today') {
                $ssUsedWhere[] = "s.started_at BETWEEN :ss_u_t_start AND :ss_u_t_end";
                $ssUsedParams[':ss_u_t_start'] = $todayStart;
                $ssUsedParams[':ss_u_t_end']   = $todayEnd;
            } elseif ($period === 'this_week') {
                $ssUsedWhere[] = "s.started_at BETWEEN :ss_u_w_start AND :ss_u_w_end";
                $ssUsedParams[':ss_u_w_start'] = $wStart;
                $ssUsedParams[':ss_u_w_end']   = $wEnd;
            } elseif ($period === 'this_month') {
                $ssUsedWhere[] = "s.started_at BETWEEN :ss_u_m_start AND :ss_u_m_end";
                $ssUsedParams[':ss_u_m_start'] = $mStart;
                $ssUsedParams[':ss_u_m_end']   = $mEnd;
            } elseif ($period === 'custom') {
                if (!empty($startDate) && !empty($endDate)) {
                    $ssUsedWhere[] = "s.started_at BETWEEN :ss_u_c_start AND :ss_u_c_end";
                    $ssUsedParams[':ss_u_c_start'] = $startDate . ' 00:00:00';
                    $ssUsedParams[':ss_u_c_end']   = $endDate . ' 23:59:59';
                }
            }

            $ssUsedWhereSql = implode(" AND ", $ssUsedWhere);
            $ssUsedSql = "
                SELECT 
                    isl.id AS lot_id,
                    isl.lot_number,
                    isl.ref_number,
                    -- Qty yang benar-benar diambil dari SS (bukan qty fisik label box)
                    CASE
                        WHEN COALESCE(s.use_safety_stock_qty, 0) > 0 THEN s.use_safety_stock_qty
                        ELSE GREATEST(0, isl.qty - COALESCE(s.excess_qty, 0))
                    END AS qty_used,
                    isl.qty AS label_qty,
                    isl.remarks,
                    isl.lot_result,
                    isl.lot_status,
                    isl.created_at AS allocated_at,
                    s.id AS session_id,
                    s.status AS kanban_session_status,
                    s.started_at AS session_started_at,
                    k.id AS kanban_item_id,
                    k.kanban_no,
                    COALESCE(k.customer, 'PT. Indonesia Epson Industry') AS customer,
                    COALESCE(k.item_code, mp.part_code, '-') AS part_code,
                    COALESCE(k.item_description, mp.part_name, '-') AS part_name,
                    COALESCE(mm.name, mp.model, '-') AS model_name,
                    COALESCE(u.name, 'Operator') AS operator_name
                FROM inspection_session_lots isl
                JOIN inspection_sessions s ON s.id = isl.inspection_session_id
                LEFT JOIN kanban_items k ON k.id = s.kanban_item_id
                LEFT JOIN master_parts mp ON (mp.id = s.part_id OR UPPER(mp.part_code) = UPPER(k.item_code))
                LEFT JOIN master_models mm ON mm.id = mp.model_id
                LEFT JOIN users u ON u.id = s.inspector_id
                WHERE {$ssUsedWhereSql}
                ORDER BY isl.id DESC
            ";
            $stmtSsUsed = $pdo->prepare($ssUsedSql);
            $stmtSsUsed->execute($ssUsedParams);
            $ssUsedLots = $stmtSsUsed->fetchAll(PDO::FETCH_ASSOC);
            $stats['total_used_qty']   = array_sum(array_column($ssUsedLots, 'qty_used'));
            $stats['total_used_count'] = count($ssUsedLots);

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

            // Anti-Session Lock: Release session write lock after badge caching so concurrent browser requests never wait
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
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
                                                         FROM inspection_session_lots isl2 WHERE isl2.inspection_session_id = s2.id), 0) - COALESCE(s2.excess_qty, 0)))
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
                      AND (isl.remarks IS NULL OR (isl.remarks NOT LIKE '%Sisa Split Safety Stock%' AND isl.remarks NOT LIKE '%Sisa Kelebihan Kanban%'))
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
                            if (stripos($remarks, 'Sisa Kelebihan Kanban') !== false || stripos($remarks, 'Sisa Split Safety Stock') !== false) {
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

            // Anti-Session Lock: Release session write lock after badge caching so concurrent browser requests never wait
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
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

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 1. KPI METRICS, QUICK DATE RANGES, & FILTER BAR                -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <?php require_once __DIR__ . '/components/stats_and_filter.php'; ?>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- 2. DATA TABLE CONTAINER & ACTIVE TAB VIEW                       -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div class="card p-0 border border-slate-200/90 rounded-xl shadow-xs <?= ($activeTab === 'kanban' || empty($activeTab) || $activeTab === 'safety_stock') ? 'overflow-visible' : 'overflow-hidden' ?>" style="min-width: 0; max-width: 100%;">
            <?php
            if ($activeTab === 'my_inspections') {
                require_once __DIR__ . '/components/tab_my_inspections.php';
            } elseif ($activeTab === 'my_lots') {
                require_once __DIR__ . '/components/tab_my_lots.php';
            } elseif ($activeTab === 'safety_stock') {
                require_once __DIR__ . '/components/tab_safety_stock.php';
            } else {
                require_once __DIR__ . '/components/tab_kanban.php';
            }
            ?>
        </div>

    </main>


    <!-- Modals & Client-side Script Components -->
    <?php require_once __DIR__ . '/components/modal_kanban_direct_close.php'; ?>
    <?php require_once __DIR__ . '/components/inspection_scripts.php'; ?>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>