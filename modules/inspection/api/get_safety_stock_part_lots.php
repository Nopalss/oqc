<?php
/**
 * API Endpoint: On-Demand Lazy Load Safety Stock Lots for a Part Code
 * Mengambil dan merender HTML sub-tabel rincian lot fisik Safety Stock
 * secara on-demand ketika accordion part dibuka oleh pengguna.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/helper.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesi login telah berakhir.', 'unauthorized' => true]);
    exit;
}
session_write_close();

$pdo = getDB();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Koneksi database gagal.']);
    exit;
}

$partCode          = strtoupper(trim(sanitize($_GET['part_code'] ?? '')));
$period            = sanitize($_GET['period'] ?? 'all');
$startDate         = sanitize($_GET['start_date'] ?? '');
$endDate           = sanitize($_GET['end_date'] ?? '');
$inspectorFilterId = (int)($_GET['inspector_id'] ?? 0);
$idx               = (int)($_GET['idx'] ?? 0);

if (empty($partCode)) {
    echo json_encode(['success' => false, 'message' => 'Part Code wajib diisi.']);
    exit;
}

$currentUser = current_user();
$currentUserId = (int)$currentUser['id'];
$isAdminOrSupervisor = in_array(strtolower($currentUser['role'] ?? ''), ['admin', 'supervisor']);

// Date periods
$todayStart = date('Y-m-d 00:00:00');
$todayEnd   = date('Y-m-d 23:59:59');
$wStart     = date('Y-m-d 00:00:00', strtotime('monday this week'));
$wEnd       = date('Y-m-d 23:59:59', strtotime('sunday this week'));
$mStart     = date('Y-m-01 00:00:00');
$mEnd       = date('Y-m-t 23:59:59');

$sessWherePeriod = "";
if (!$isAdminOrSupervisor) {
    $sessWherePeriod = " AND s.inspector_id = " . (int)$currentUserId;
} elseif ($inspectorFilterId > 0) {
    $sessWherePeriod = " AND s.inspector_id = " . (int)$inspectorFilterId;
}

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

// Ensure rendering helper functions exist
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

        if ($isInitial) {
            return '<span style="background-color: #dc2626; color: #ffffff; font-weight: 800; font-size: 10px; padding: 2.5px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px; box-shadow: 0 1px 2px rgba(220, 38, 38, 0.3); text-transform: uppercase; white-space: nowrap;"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="stroke: #ffffff; flex-shrink: 0;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> REJECTED (AWAL)</span>';
        }

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

        if ($isReinspect) {
            if ($res === 'passed' || ($lot['status_code'] ?? '') === 'passed' || $st === 'passed' || $st === 'reinspected') {
                return '<span style="background-color: #16a34a; color: #ffffff; font-weight: 800; font-size: 10px; padding: 2.5px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px; box-shadow: 0 1px 2px rgba(22, 163, 74, 0.3); text-transform: uppercase; white-space: nowrap;"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="stroke: #ffffff; flex-shrink: 0;"><polyline points="20 6 9 17 4 12"/></svg> PASSED (RE-INSPEKSI)</span>';
            }
            return '<span style="background-color: #f59e0b; color: #ffffff; font-weight: 800; font-size: 10px; padding: 2.5px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px; text-transform: uppercase; white-space: nowrap;">SEDANG BERJALAN</span>';
        }

        if ($res === 'passed' || $res === 'skipped' || empty($res)) {
            return '<span style="background-color: #7c3aed; color: #ffffff; font-weight: 800; font-size: 9.5px; padding: 2.5px 8.5px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 2px rgba(124, 58, 237, 0.3); text-transform: uppercase; white-space: nowrap;"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="stroke: #ffffff; flex-shrink: 0;"><polyline points="20 6 9 17 4 12"/></svg> PASSED (SS)</span>';
        }

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

        if ($isReinspect) {
            return 'lot-row-reinspected';
        }

        return 'hover:bg-purple-50/40 transition-colors bg-white';
    }
}

// 1. Fetch lots for this specific part code
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
        s.ss_source,
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
    WHERE k.item_code = :pcode
      AND (k.plan_type = 'safety_stock' OR k.check_type = 'Safety Stock' OR k.kanban_no LIKE 'SS-%' OR k.str_loc IN ('WH-SS-OVERFLOW', 'WH-SS-SPLIT') OR s.inspection_type = 'safety_stock')
      AND (isl.remarks IS NULL OR (
          isl.remarks NOT LIKE '%Sisa Split Safety Stock%'
          AND isl.remarks NOT LIKE '%Alokasi Safety Stock%'
      ))
      {$sessWherePeriod}
    ORDER BY isl.id DESC
";

$stmtLots = $pdo->prepare($lotSql);
$stmtLots->execute([':pcode' => $partCode]);
$lotRows = $stmtLots->fetchAll(PDO::FETCH_ASSOC);

// 2. Batch fetch defects if any session lots exist
$allSessionIds = array_unique(array_filter(array_column($lotRows, 'inspection_session_id')));
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

$displayPartName = !empty($lotRows) ? ($lotRows[0]['part_name'] ?? $partCode) : $partCode;
$totalLots = count($lotRows);

// 3. Render subtable HTML
ob_start();
?>
<div class="px-4 py-2.5 bg-purple-900 text-white flex items-center justify-between flex-wrap gap-2">
    <div class="font-extrabold text-xs flex items-center gap-2">
        <span class="w-2 h-2 rounded-full bg-purple-300 animate-ping"></span>
        <span>RINCIAN LOT INSPEKSI SAFETY STOCK: <?= htmlspecialchars($partCode) ?> - <?= htmlspecialchars($displayPartName) ?></span>
    </div>
    <div class="flex items-center gap-2">
        <!-- Navigasi Cepat Scroll Horizontal Sub-Tabel -->
        <div class="inline-flex items-center rounded-lg bg-purple-950 p-0.5 border border-purple-700 shadow-xs">
            <button type="button" 
                    onclick="scrollSubTable('ss-subtable-scroll-<?= $idx ?>', -350)" 
                    class="px-2.5 py-1 text-[11px] font-extrabold text-purple-200 hover:text-white hover:bg-purple-800 rounded transition-all inline-flex items-center gap-1 cursor-pointer select-none" 
                    title="Geser tabel ke kiri">
                <svg class="w-3.5 h-3.5 text-purple-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/></svg>
                <span>Scroll Kiri</span>
            </button>
            <div class="h-3.5 w-px bg-purple-800"></div>
            <button type="button" 
                    onclick="scrollSubTable('ss-subtable-scroll-<?= $idx ?>', 350)" 
                    class="px-2.5 py-1 text-[11px] font-extrabold text-purple-200 hover:text-white hover:bg-purple-800 rounded transition-all inline-flex items-center gap-1 cursor-pointer select-none" 
                    title="Geser tabel ke kanan">
                <span>Scroll Kanan</span>
                <svg class="w-3.5 h-3.5 text-purple-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
            </button>
        </div>
        <span class="text-[10.5px] font-extrabold bg-purple-800 px-2.5 py-1 rounded-full text-purple-100 border border-purple-700">
            Total <?= $totalLots ?> Lot / Box
        </span>
    </div>
</div>

<div id="ss-subtable-scroll-<?= $idx ?>" class="overflow-x-auto custom-scrollbar w-full" style="width: 100%; max-width: 100%; -webkit-overflow-scrolling: touch;">
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
            <?php if (empty($lotRows)): ?>
                <tr>
                    <td colspan="15" class="py-6 text-center text-slate-400 font-medium">
                        Belum ada lot inspeksi yang dicatat untuk part ini.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($lotRows as $lIdx => $rLot): 
                    $slotId = (int)$rLot['lot_id'];
                    $allDefects = $defectsByLot[$slotId] ?? [];
                    $activeDefects = array_values(array_filter($allDefects, function($df) {
                        return empty($df['is_sorted']);
                    }));
                    $rLot['defects'] = $activeDefects;
                    $rLot['all_defects'] = $allDefects;

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
                            <div><?= htmlspecialchars($rLot['lot_number'] ?: '-') ?></div>
                            <div style="margin-top: 2px;">
                                <?php if (($rLot['ss_source'] ?? '') === 'kanban_excess' || ($rLot['ss_source'] ?? '') === 'split_leftover' || (!empty($rLot['remarks']) && stripos($rLot['remarks'], 'Kelebihan Kanban') !== false)): ?>
                                    <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[8.5px] font-extrabold bg-amber-50 text-amber-700 border border-amber-200">
                                        ♻️ Sisa Kanban
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[8.5px] font-bold bg-blue-50 text-blue-700 border border-blue-200">
                                        📥 Masuk Normal
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td style="padding: 8px 10px; font-family: monospace; font-weight: 800; color: #7c3aed; white-space: nowrap;">
                            <?= htmlspecialchars($rLot['ref_number'] ?: '-') ?>
                            <?php if (!empty($rLot['ref_number']) && ($rLot['ref_number'] === 'DIRECT-PASS' || strpos($rLot['ref_number'], 'DIRECT-PASS') !== false)): ?>
                                <span style="font-size: 8.5px; font-weight: 800; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 1px 4px; border-radius: 4px; display: inline-block; margin-left: 3px;">
                                    DIRECT
                                </span>
                            <?php elseif (!empty($rLot['remarks']) && stripos($rLot['remarks'], 'Tutup Kanban') !== false): ?>
                                <span style="font-size: 8.5px; font-weight: 800; background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; padding: 1px 4px; border-radius: 4px; display: inline-block; margin-left: 3px;">
                                    CLOSE KB
                                </span>
                            <?php endif; ?>
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
<?php
$subTableHtml = ob_get_clean();

echo json_encode([
    'success'    => true,
    'part_code'  => $partCode,
    'total_lots' => $totalLots,
    'html'       => $subTableHtml
]);
exit;
