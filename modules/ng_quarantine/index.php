<?php
/**
 * Modul Tracking & Traceability Lot NG (Karantina OQC)
 * File: modules/ng_quarantine/index.php
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

if (!is_logged_in()) {
    redirect('modules/auth/login.php');
}

$pdo = getDB();
$pageTitle = "Tracking & Karantina Lot NG";

// -----------------------------------------------------------------------------
// Parameters & Filter Input
// -----------------------------------------------------------------------------
$period         = $_GET['period'] ?? 'all';
$startDate      = sanitize($_GET['start_date'] ?? '');
$endDate        = sanitize($_GET['end_date'] ?? '');
$selectedMonth  = (int)($_GET['month'] ?? date('n'));
$selectedYear   = (int)($_GET['year'] ?? date('Y'));
$statusHandling = sanitize($_GET['status_handling'] ?? 'all');
$agingFilter    = sanitize($_GET['aging'] ?? 'all');
$search         = trim(sanitize($_GET['search'] ?? ''));

$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = max(10, (int)($_GET['limit'] ?? 15));

// Date filter bounds calculation
$todayStart = date('Y-m-d 00:00:00');
$todayEnd   = date('Y-m-d 23:59:59');

$wStart = date('Y-m-d 00:00:00', strtotime('monday this week'));
$wEnd   = date('Y-m-d 23:59:59', strtotime('sunday this week'));

$mStart = date('Y-m-01 00:00:00');
$mEnd   = date('Y-m-t 23:59:59');

$yStart = date('Y-01-01 00:00:00');
$yEnd   = date('Y-12-31 23:59:59');

// Specific month-year bounds
$safeMonth = sprintf('%02d', max(1, min(12, $selectedMonth)));
$safeYear  = max(2020, min(2035, $selectedYear));
$myStart   = "{$safeYear}-{$safeMonth}-01 00:00:00";
$myEndDay  = date('t', strtotime("{$safeYear}-{$safeMonth}-01"));
$myEnd     = "{$safeYear}-{$safeMonth}-{$myEndDay} 23:59:59";

$whereConditions = [];
$queryParams     = [];

// Scope query to NG lots (lots with NG records or rejected status)
$whereConditions[] = "(
    isl.lot_result = 'rejected' 
    OR isl.lot_status IN ('rejected', 'ng_quarantine')
    OR EXISTS (
        SELECT 1 FROM inspection_ng_records ngr 
        WHERE ngr.session_lot_id = isl.id 
          AND (ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)
    )
)";

// Exclude direct pass dummy records
$whereConditions[] = "(isl.ref_number IS NULL OR (isl.ref_number != 'DIRECT-PASS' AND isl.ref_number NOT LIKE '%DIRECT-PASS%'))";
$whereConditions[] = "(s.approval_notes IS NULL OR s.approval_notes NOT LIKE '%Direct Close%')";

// Period filter logic
if ($period === 'today') {
    $whereConditions[] = "s.started_at BETWEEN :t_start AND :t_end";
    $queryParams[':t_start'] = $todayStart;
    $queryParams[':t_end']   = $todayEnd;
} elseif ($period === 'this_week') {
    $whereConditions[] = "s.started_at BETWEEN :w_start AND :w_end";
    $queryParams[':w_start'] = $wStart;
    $queryParams[':w_end']   = $wEnd;
} elseif ($period === 'this_month') {
    $whereConditions[] = "s.started_at BETWEEN :m_start AND :m_end";
    $queryParams[':m_start'] = $mStart;
    $queryParams[':m_end']   = $mEnd;
} elseif ($period === 'this_year') {
    $whereConditions[] = "s.started_at BETWEEN :y_start AND :y_end";
    $queryParams[':y_start'] = $yStart;
    $queryParams[':y_end']   = $yEnd;
} elseif ($period === 'month_year') {
    $whereConditions[] = "s.started_at BETWEEN :my_start AND :my_end";
    $queryParams[':my_start'] = $myStart;
    $queryParams[':my_end']   = $myEnd;
} elseif ($period === 'custom') {
    if (!empty($startDate) && !empty($endDate)) {
        $whereConditions[] = "s.started_at BETWEEN :c_start AND :c_end";
        $queryParams[':c_start'] = $startDate . ' 00:00:00';
        $queryParams[':c_end']   = $endDate . ' 23:59:59';
    } elseif (!empty($startDate)) {
        $whereConditions[] = "s.started_at >= :c_start";
        $queryParams[':c_start'] = $startDate . ' 00:00:00';
    } elseif (!empty($endDate)) {
        $whereConditions[] = "s.started_at <= :c_end";
        $queryParams[':c_end']   = $endDate . ' 23:59:59';
    }
}

$whereClause = implode(" AND ", $whereConditions);

// Select Query for NG Lots
$sql = "
    SELECT 
        isl.id AS lot_id,
        isl.inspection_session_id,
        isl.lot_number,
        isl.ref_number,
        isl.qty AS qty_lot,
        isl.sample_size,
        isl.lot_result,
        isl.lot_status,
        isl.replaced_by_lot_id,
        isl.remarks,
        isl.chief_notes AS action_notes,
        isl.action_noted_at,
        isl.created_at AS lot_created_at,
        isl.scanned_qr_raw,
        isl.closed_at AS lot_closed_at,
        s.id AS session_id,
        s.started_at AS session_started_at,
        s.closed_at AS session_closed_at,
        s.status AS session_status,
        s.inspector_id,
        s.chief_notes,
        s.reinspection_type,
        COALESCE(u.name, 'QC Inspector') AS inspector_name,
        k.id AS kanban_id,
        k.item_code AS part_code,
        COALESCE(NULLIF(mp.part_name, ''), k.item_description, k.item_code) AS part_name,
        COALESCE(mm.name, mp.model, '-') AS model_name,
        k.customer,
        k.str_loc
    FROM inspection_session_lots isl
    JOIN inspection_sessions s ON s.id = isl.inspection_session_id
    JOIN kanban_items k ON k.id = s.kanban_item_id
    LEFT JOIN master_parts mp ON mp.part_code = k.item_code
    LEFT JOIN master_models mm ON mm.id = mp.model_id
    LEFT JOIN users u ON u.id = s.inspector_id
    WHERE {$whereClause}
    ORDER BY isl.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($queryParams);
$allNgLots = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Collect all unique NG groups from $allNgLots
$ngGroupKeys = [];
$refNumbers = [];
$unreferencedNgLots = [];

foreach ($allNgLots as $row) {
    $pCode = strtoupper(trim($row['part_code'] ?? ''));
    $lNum  = strtoupper(trim($row['lot_number'] ?? ''));
    $rNum  = strtoupper(trim($row['ref_number'] ?? ''));
    $groupKey = $pCode . '|' . $lNum . '|' . $rNum;
    $ngGroupKeys[$groupKey] = true;

    if ($rNum !== '') {
        $refNumbers[] = $row['ref_number'];
    } else {
        $unreferencedNgLots[] = [
            'part_code'  => $row['part_code'],
            'lot_number' => $row['lot_number']
        ];
    }
}
$refNumbers = array_filter(array_unique($refNumbers));

// Query subsequent rounds ONLY for these specific ref_numbers (and unreferenced lots)
$allSessionsForLots = $allNgLots;

if (!empty($refNumbers) || !empty($unreferencedNgLots)) {
    $whereParts = [];
    $params = [];

    if (!empty($refNumbers)) {
        $pRef = implode(',', array_fill(0, count($refNumbers), '?'));
        $whereParts[] = "isl.ref_number IN ($pRef)";
        foreach ($refNumbers as $r) $params[] = $r;
    }

    foreach ($unreferencedNgLots as $uLot) {
        $whereParts[] = "(k.item_code = ? AND isl.lot_number = ? AND (isl.ref_number IS NULL OR isl.ref_number = ''))";
        $params[] = $uLot['part_code'];
        $params[] = $uLot['lot_number'];
    }

    $whereRelStr = "(" . implode(" OR ", $whereParts) . ")";

    $sqlAllRounds = "
        SELECT 
            isl.id AS lot_id,
            isl.inspection_session_id,
            isl.lot_number,
            isl.ref_number,
            isl.qty AS qty_lot,
            isl.sample_size,
            isl.lot_result,
            isl.lot_status,
            isl.replaced_by_lot_id,
            isl.remarks,
            isl.chief_notes AS action_notes,
            isl.action_noted_at,
            isl.created_at AS lot_created_at,
            isl.scanned_qr_raw,
            isl.closed_at AS lot_closed_at,
            s.id AS session_id,
            s.started_at AS session_started_at,
            s.closed_at AS session_closed_at,
            s.status AS session_status,
            s.inspector_id,
            s.chief_notes,
            s.reinspection_type,
            COALESCE(u.name, 'QC Inspector') AS inspector_name,
            k.id AS kanban_id,
            k.item_code AS part_code,
            COALESCE(NULLIF(mp.part_name, ''), k.item_description, k.item_code) AS part_name,
            COALESCE(mm.name, mp.model, '-') AS model_name,
            k.customer,
            k.str_loc
        FROM inspection_session_lots isl
        JOIN inspection_sessions s ON s.id = isl.inspection_session_id
        JOIN kanban_items k ON k.id = s.kanban_item_id
        LEFT JOIN master_parts mp ON mp.part_code = k.item_code
        LEFT JOIN master_models mm ON mm.id = mp.model_id
        LEFT JOIN users u ON u.id = s.inspector_id
        WHERE {$whereRelStr}
          AND (isl.ref_number IS NULL OR (isl.ref_number != 'DIRECT-PASS' AND isl.ref_number NOT LIKE '%DIRECT-PASS%'))
          AND (s.approval_notes IS NULL OR s.approval_notes NOT LIKE '%Direct Close%')
        ORDER BY isl.id DESC
    ";

    $stmtRounds = $pdo->prepare($sqlAllRounds);
    $stmtRounds->execute($params);
    $fetchedRounds = $stmtRounds->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($fetchedRounds)) {
        $allSessionsForLots = $fetchedRounds;
    }
}

// Fetch Defect Details for all returned lots across all rounds
$allLotIds = array_column($allSessionsForLots, 'lot_id');
$defectsByLot = [];
if (!empty($allLotIds)) {
    $inLotIds = implode(',', array_map('intval', array_unique($allLotIds)));
    $ngSql = "
        SELECT n.*, dt.name AS defect_name, dt.code AS defect_code
        FROM inspection_ng_records n
        JOIN defect_types dt ON dt.id = n.defect_type_id
        WHERE n.session_lot_id IN ($inLotIds)
          AND (n.is_cancelled IS NULL OR n.is_cancelled = 0)
        ORDER BY n.id ASC
    ";
    $stmtNg = $pdo->query($ngSql);
    while ($nRow = $stmtNg->fetch(PDO::FETCH_ASSOC)) {
        $lId = (int)$nRow['session_lot_id'];
        $defectsByLot[$lId][] = $nRow;
    }
}

// Fetch Administrative Quarantine Closures from new table lot_quarantine_closures
$closuresByLot = [];
if (!empty($allLotIds)) {
    $inLotIds = implode(',', array_map('intval', array_unique($allLotIds)));
    $closureSql = "
        SELECT lqc.*, u.name AS closed_by_name
        FROM lot_quarantine_closures lqc
        LEFT JOIN users u ON u.id = lqc.closed_by
        WHERE lqc.session_lot_id IN ($inLotIds)
        ORDER BY lqc.id DESC
    ";
    $stmtClosure = $pdo->query($closureSql);
    while ($cRow = $stmtClosure->fetch(PDO::FETCH_ASSOC)) {
        $lId = (int)$cRow['session_lot_id'];
        if (!isset($closuresByLot[$lId])) {
            $closuresByLot[$lId] = $cRow;
        }
    }
}

// Check Re-inspections & calculate statuses with Lot Grouping (1 baris per unique Part + Lot No + Ref No)
$groupedNgLotsMap = [];
foreach ($allSessionsForLots as $row) {
    $pCode = strtoupper(trim($row['part_code'] ?? ''));
    $lNum  = strtoupper(trim($row['lot_number'] ?? ''));
    $rNum  = strtoupper(trim($row['ref_number'] ?? ''));
    $groupKey = $pCode . '|' . $lNum . '|' . $rNum;

    // STRICT CHECK: Only process groups that were part of actual NG lots!
    if (!isset($ngGroupKeys[$groupKey])) {
        continue;
    }

    if (!isset($groupedNgLotsMap[$groupKey])) {
        $groupedNgLotsMap[$groupKey] = [];
    }
    $groupedNgLotsMap[$groupKey][] = $row;
}

$processedNgLots = [];
$statsTotal       = 0;
$statsOpen        = 0;
$statsClosed      = 0;
$statsAction      = 0;
$statsAbolished   = 0;
$statsAging1to3   = 0;
$statsAging4to6   = 0;
$statsAging7plus  = 0;

foreach ($groupedNgLotsMap as $groupKey => $groupRows) {
    // $groupRows is ordered by isl.id DESC (latest inspection session is first)
    $latestRow  = $groupRows[0];
    $roundCount = count($groupRows);
    
    // Find the session & lot where the NG actually occurred (for Rejection Sheet printing)
    $rejectedSessionId = (int)$latestRow['inspection_session_id'];
    $rejectedLotId     = (int)$latestRow['lot_id'];
    foreach ($groupRows as $gRow) {
        $gLotId = (int)$gRow['lot_id'];
        if (strtolower($gRow['lot_result'] ?? '') === 'rejected' || !empty($defectsByLot[$gLotId])) {
            $rejectedSessionId = (int)$gRow['inspection_session_id'];
            $rejectedLotId     = $gLotId;
            break;
        }
    }
    $latestRow['rejected_session_id'] = $rejectedSessionId;
    $latestRow['rejected_lot_id']     = $rejectedLotId;
    
    // Check if any lot in this group has administrative closure record in lot_quarantine_closures
    $groupClosure = null;
    foreach ($groupRows as $gRow) {
        $gLotId = (int)$gRow['lot_id'];
        if (isset($closuresByLot[$gLotId])) {
            $groupClosure = $closuresByLot[$gLotId];
            break;
        }
    }
    $latestRow['closure_info'] = $groupClosure;

    // Aggregate defects across ALL rounds in this group
    $aggregatedDefects = [];
    $totalNgCount = 0;
    foreach ($groupRows as $gRow) {
        $lId = (int)$gRow['lot_id'];
        $rowDefects = $defectsByLot[$lId] ?? [];
        foreach ($rowDefects as $df) {
            $qtyNg = (int)($df['qty_ng'] ?? 1);
            $defName = $df['defect_name'] ?? 'Defect';
            if (!isset($aggregatedDefects[$defName])) {
                $aggregatedDefects[$defName] = 0;
            }
            $aggregatedDefects[$defName] += $qtyNg;
            $totalNgCount += $qtyNg;
        }
    }

    $defParts = [];
    foreach ($aggregatedDefects as $defName => $qtyNg) {
        $defParts[] = '<span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">' 
            . htmlspecialchars($defName) 
            . ' <b class="text-rose-900 font-extrabold">(' . $qtyNg . ' pcs)</b></span>';
    }
    $latestRow['defect_html'] = !empty($defParts) ? implode(' ', $defParts) : '<span class="text-rose-600 font-bold">Defect Terdeteksi (' . (int)($latestRow['qty_lot']) . ' pcs)</span>';
    $latestRow['ng_count']    = $totalNgCount > 0 ? $totalNgCount : (int)($latestRow['qty_lot'] ?? 0);
    $latestRow['round_count'] = $roundCount;

    // Check statuses
    $resStatus = strtolower($latestRow['lot_result'] ?? '');
    $lotSt     = strtolower($latestRow['lot_status'] ?? '');

    // Determine closure type and lifecycle
    $closureType = $groupClosure ? ($groupClosure['closure_type'] ?? 'action') : '';
    if ($lotSt === 'abolished') {
        $closureType = 'abolish';
    } elseif ($lotSt === 'action_closed') {
        $closureType = 'action';
    }

    $isClosed = ($groupClosure !== null || in_array($lotSt, ['abolished', 'action_closed'], true));

    // Calculate Aging Days
    $findingTs = !empty($latestRow['lot_created_at']) 
        ? strtotime($latestRow['lot_created_at']) 
        : strtotime($latestRow['session_started_at']);
    $diffDays = max(1, (int)ceil((time() - $findingTs) / 86400));
    $latestRow['aging_days'] = $diffDays;

    if ($isClosed) {
        $statusLifecycle = 'CLOSED';
        if ($closureType === 'abolish') {
            $actionType = 'ABOLISH';
            $statsAbolished++;
        } else {
            $actionType = 'ACTION';
            $statsAction++;
        }
        $statsClosed++;
        $rowStyle = 'background-color: #f8fafc; border-left: 4px solid #94a3b8;';
        $agingBracket = 'closed';
    } else {
        $statusLifecycle = 'OPEN';
        $actionType      = 'WAITING';
        $statsOpen++;
        $rowStyle = 'background-color: #fff1f2; border-left: 4px solid #f43f5e;';

        if ($diffDays <= 3) {
            $agingBracket = '1-3';
            $statsAging1to3++;
        } elseif ($diffDays <= 6) {
            $agingBracket = '4-6';
            $statsAging4to6++;
        } else {
            $agingBracket = '7plus';
            $statsAging7plus++;
        }
    }

    $latestRow['status_lifecycle'] = $statusLifecycle;
    $latestRow['action_type']      = $actionType;
    $latestRow['aging_bracket']    = $agingBracket;
    $latestRow['is_closed']        = $isClosed;
    $latestRow['is_abolished']     = ($actionType === 'ABOLISH');
    $latestRow['row_style']        = $rowStyle;
    $latestRow['row_class']        = 'font-medium';

    // Filter status handling if specified
    if ($statusHandling !== 'all') {
        if ($statusHandling === 'open' && $statusLifecycle !== 'OPEN') {
            continue;
        } elseif ($statusHandling === 'closed' && $statusLifecycle !== 'CLOSED') {
            continue;
        } elseif ($statusHandling === 'action' && $actionType !== 'ACTION') {
            continue;
        } elseif ($statusHandling === 'abolish' && $actionType !== 'ABOLISH') {
            continue;
        } elseif ($statusHandling === 'waiting' && $actionType !== 'WAITING') {
            continue;
        }
    }

    // Filter Aging if specified (Only applies to OPEN lots)
    if ($agingFilter !== 'all') {
        if ($statusLifecycle !== 'OPEN' || $agingBracket !== $agingFilter) {
            continue;
        }
    }

    // Search Filter Text
    if ($search !== '') {
        $sTerm = strtolower($search);
        $match = (
            strpos(strtolower($latestRow['part_code']), $sTerm) !== false ||
            strpos(strtolower($latestRow['part_name']), $sTerm) !== false ||
            strpos(strtolower($latestRow['model_name']), $sTerm) !== false ||
            strpos(strtolower($latestRow['lot_number']), $sTerm) !== false ||
            strpos(strtolower($latestRow['ref_number']), $sTerm) !== false ||
            strpos(strtolower($latestRow['inspector_name']), $sTerm) !== false
        );
        if (!$match) continue;
    }

    $processedNgLots[] = $latestRow;
}

$statsTotal = count($groupedNgLotsMap);

// Pagination Slice
$totalFiltered = count($processedNgLots);
$totalPages   = max(1, ceil($totalFiltered / $limit));
if ($page > $totalPages) $page = $totalPages;
$offset       = ($page - 1) * $limit;
$ngLots = array_slice($processedNgLots, $offset, $limit);

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<!-- Main Content Wrapper with Sidebar Offset (md:pl-64) -->
<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen min-w-0 w-full overflow-x-hidden transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-4 sm:p-6 lg:p-8 space-y-6 min-w-0 w-full overflow-x-hidden">
        <div class="max-w-7xl mx-auto space-y-6">

        <!-- Page Header -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
            <div>
                <div class="flex items-center gap-2">
                    <span class="px-2.5 py-1 rounded-md bg-rose-100 text-rose-800 font-extrabold text-xs uppercase tracking-wider border border-rose-200">
                        Karantina & Traceability
                    </span>
                    <h1 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight">Monitoring Lot NG</h1>
                </div>
                <p class="text-xs sm:text-sm text-slate-500 mt-1 font-medium">
                    Pantau status penanganan, audit trail temuan cacat, dan progres re-inspeksi lot rejected.
                </p>
            </div>
            <div class="flex items-center gap-2 self-start sm:self-auto flex-wrap">
                <!-- Tombol Export Excel -->
                <a href="<?= base_url('modules/ng_quarantine/export_excel.php?' . http_build_query($_GET)) ?>" 
                   target="_blank"
                   style="background-color: #16a34a; color: #ffffff;"
                   class="px-4 py-2 hover:bg-emerald-700 active:bg-emerald-800 font-extrabold text-xs rounded-xl border border-emerald-600 transition-all inline-flex items-center gap-1.5 shadow-sm"
                   title="Export Data Lot NG yang tersaring ke Microsoft Excel (.xlsx)">
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    <span>Export Excel</span>
                </a>

                <a href="<?= base_url('modules/inspection/index.php') ?>" 
                   class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-extrabold text-xs rounded-xl border border-slate-300 transition-all inline-flex items-center gap-1.5 shadow-sm">
                    <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    <span>Ke Workboard Inspeksi</span>
                </a>
            </div>
        </div>

        <!-- Helper URL generator for quick card filtering -->
        <?php
        function getFilterCardUrl($targetStatus) {
            $params = $_GET;
            $params['status_handling'] = $targetStatus;
            $params['aging'] = 'all'; // reset aging on main status switch
            $params['page'] = 1;
            return '?' . http_build_query($params);
        }

        function getFilterAgingUrl($targetAging) {
            $params = $_GET;
            $params['status_handling'] = 'open'; // aging specifically applies to OPEN lots
            $params['aging'] = $targetAging;
            $params['page'] = 1;
            return '?' . http_build_query($params);
        }
        ?>

        <!-- 5 Top KPI Summary Cards (Interactive Quick Filters) -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 0.85rem;" class="w-full">
            <!-- Total Lot NG (All) -->
            <?php $isActiveAll = ($statusHandling === 'all' && $agingFilter === 'all'); ?>
            <a href="<?= getFilterCardUrl('all') ?>" 
               style="background-color: <?= $isActiveAll ? '#ffffff' : '#f8fafc' ?>; border: <?= $isActiveAll ? '2.5px solid #0f172a' : '1px solid #cbd5e1' ?>; border-radius: 16px; padding: 16px; box-shadow: <?= $isActiveAll ? '0 4px 12px rgba(15,23,42,0.12)' : '0 1px 3px rgba(0,0,0,0.04)' ?>; display: flex; flex-direction: column; justify-content: space-between; text-decoration: none; cursor: pointer; transition: all 0.15s ease;"
               onmouseover="this.style.transform='translateY(-2px)';" onmouseout="this.style.transform='translateY(0)';"
               title="Klik untuk filter: Tampilkan Semua Lot NG">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <span style="font-size: 11px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Total Lot NG</span>
                    <div style="width: 32px; height: 32px; border-radius: 10px; background-color: #e2e8f0; display: flex; align-items: center; justify-content: center;">
                        <svg style="width: 18px; height: 18px; color: #334155;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                        </svg>
                    </div>
                </div>
                <div style="margin-top: 10px; display: flex; align-items: baseline; justify-content: space-between;">
                    <span style="font-size: 26px; font-weight: 900; color: #0f172a; line-height: 1;"><?= number_format($statsTotal) ?></span>
                    <span style="font-size: 10.5px; font-weight: 700; color: #64748b;">Record NG</span>
                </div>
            </a>

            <!-- Status OPEN (Pending / Waiting) -->
            <?php $isActiveOpen = ($statusHandling === 'open' && $agingFilter === 'all'); ?>
            <a href="<?= getFilterCardUrl('open') ?>" 
               style="background-color: <?= $isActiveOpen ? '#fff5f6' : '#fff1f2' ?>; border: <?= $isActiveOpen ? '2.5px solid #e11d48' : '1px solid #fecdd3' ?>; border-radius: 16px; padding: 16px; box-shadow: <?= $isActiveOpen ? '0 4px 12px rgba(225,29,72,0.18)' : '0 1px 3px rgba(225,29,72,0.06)' ?>; display: flex; flex-direction: column; justify-content: space-between; text-decoration: none; cursor: pointer; transition: all 0.15s ease;"
               onmouseover="this.style.transform='translateY(-2px)';" onmouseout="this.style.transform='translateY(0)';"
               title="Klik untuk filter: Hanya Status OPEN (Belum Ditangani)">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <span style="font-size: 11px; font-weight: 800; color: #be123c; text-transform: uppercase; letter-spacing: 0.5px;">Status OPEN</span>
                    <span style="padding: 2px 7px; border-radius: 9999px; font-size: 9.5px; font-weight: 900; background-color: #e11d48; color: #ffffff;">Waiting</span>
                </div>
                <div style="margin-top: 10px; display: flex; align-items: baseline; justify-content: space-between;">
                    <span style="font-size: 26px; font-weight: 900; color: #e11d48; line-height: 1;"><?= number_format($statsOpen) ?></span>
                    <span style="font-size: 10.5px; font-weight: 700; color: #be123c;">Belum Ditangani</span>
                </div>
            </a>

            <!-- Status CLOSED -->
            <?php $isActiveClosed = ($statusHandling === 'closed'); ?>
            <a href="<?= getFilterCardUrl('closed') ?>" 
               style="background-color: <?= $isActiveClosed ? '#f3fbf6' : '#f0fdf4' ?>; border: <?= $isActiveClosed ? '2.5px solid #16a34a' : '1px solid #bbf7d0' ?>; border-radius: 16px; padding: 16px; box-shadow: <?= $isActiveClosed ? '0 4px 12px rgba(22,163,74,0.18)' : '0 1px 3px rgba(22,163,74,0.06)' ?>; display: flex; flex-direction: column; justify-content: space-between; text-decoration: none; cursor: pointer; transition: all 0.15s ease;"
               onmouseover="this.style.transform='translateY(-2px)';" onmouseout="this.style.transform='translateY(0)';"
               title="Klik untuk filter: Hanya Status CLOSED (Sudah Ditangani)">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <span style="font-size: 11px; font-weight: 800; color: #15803d; text-transform: uppercase; letter-spacing: 0.5px;">Status CLOSED</span>
                    <span style="padding: 2px 7px; border-radius: 9999px; font-size: 9.5px; font-weight: 900; background-color: #16a34a; color: #ffffff;">Selesai</span>
                </div>
                <div style="margin-top: 10px; display: flex; align-items: baseline; justify-content: space-between;">
                    <span style="font-size: 26px; font-weight: 900; color: #15803d; line-height: 1;"><?= number_format($statsClosed) ?></span>
                    <span style="font-size: 10.5px; font-weight: 700; color: #15803d;">Sudah Ditangani</span>
                </div>
            </a>

            <!-- Action Selesai (Divisi Terkait) -->
            <?php $isActiveAction = ($statusHandling === 'action'); ?>
            <a href="<?= getFilterCardUrl('action') ?>" 
               style="background-color: <?= $isActiveAction ? '#f5fbff' : '#f0f9ff' ?>; border: <?= $isActiveAction ? '2.5px solid #0284c7' : '1px solid #bae6fd' ?>; border-radius: 16px; padding: 16px; box-shadow: <?= $isActiveAction ? '0 4px 12px rgba(2,132,199,0.18)' : '0 1px 3px rgba(2,132,199,0.06)' ?>; display: flex; flex-direction: column; justify-content: space-between; text-decoration: none; cursor: pointer; transition: all 0.15s ease;"
               onmouseover="this.style.transform='translateY(-2px)';" onmouseout="this.style.transform='translateY(0)';"
               title="Klik untuk filter: Hanya Tindakan ACTION (Sortir Divisi)">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <span style="font-size: 11px; font-weight: 800; color: #0369a1; text-transform: uppercase; letter-spacing: 0.5px;">Action</span>
                    <span style="padding: 2px 7px; border-radius: 9999px; font-size: 9.5px; font-weight: 900; background-color: #0284c7; color: #ffffff;">Sortir Divisi</span>
                </div>
                <div style="margin-top: 10px; display: flex; align-items: baseline; justify-content: space-between;">
                    <span style="font-size: 26px; font-weight: 900; color: #0284c7; line-height: 1;"><?= number_format($statsAction) ?></span>
                    <span style="font-size: 10.5px; font-weight: 700; color: #0369a1;">Disortir OK</span>
                </div>
            </a>

            <!-- Abolished (Scrap / Dibuang) -->
            <?php $isActiveAbolish = ($statusHandling === 'abolish'); ?>
            <a href="<?= getFilterCardUrl('abolish') ?>" 
               style="background-color: <?= $isActiveAbolish ? '#fff5fa' : '#fdf2f8' ?>; border: <?= $isActiveAbolish ? '2.5px solid #db2777' : '1px solid #fbcfe8' ?>; border-radius: 16px; padding: 16px; box-shadow: <?= $isActiveAbolish ? '0 4px 12px rgba(219,39,119,0.18)' : '0 1px 3px rgba(219,39,119,0.06)' ?>; display: flex; flex-direction: column; justify-content: space-between; text-decoration: none; cursor: pointer; transition: all 0.15s ease;"
               onmouseover="this.style.transform='translateY(-2px)';" onmouseout="this.style.transform='translateY(0)';"
               title="Klik untuk filter: Hanya Tindakan ABOLISH (Scrap/Dibuang)">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <span style="font-size: 11px; font-weight: 800; color: #9d174d; text-transform: uppercase; letter-spacing: 0.5px;">Abolish</span>
                    <span style="padding: 2px 7px; border-radius: 9999px; font-size: 9.5px; font-weight: 900; background-color: #db2777; color: #ffffff;">Scrap</span>
                </div>
                <div style="margin-top: 10px; display: flex; align-items: baseline; justify-content: space-between;">
                    <span style="font-size: 26px; font-weight: 900; color: #9d174d; line-height: 1;"><?= number_format($statsAbolished) ?></span>
                    <span style="font-size: 10.5px; font-weight: 700; color: #9d174d;">Dibuang Permanen</span>
                </div>
            </a>
        </div>

        <!-- 3 Batas Waktu / Aging KPI Cards Khusus Status OPEN -->
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 12px 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.02);" class="w-full">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                <div style="display: flex; align-items: center; gap: 6px;">
                    <svg style="width: 14px; height: 14px; color: #64748b;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span style="font-size: 11px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Batas Waktu Penanganan Lot (Status OPEN):</span>
                </div>
                <span style="font-size: 10.5px; font-weight: 700; color: #94a3b8;">Klik kartu durasi untuk filter cepat</span>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.75rem;" class="w-full">
                <!-- 1 - 3 HARI -->
                <?php $isActive1to3 = ($statusHandling === 'open' && $agingFilter === '1-3'); ?>
                <a href="<?= getFilterAgingUrl('1-3') ?>" 
                   style="background-color: <?= $isActive1to3 ? '#eff6ff' : '#f8fafc' ?>; border: <?= $isActive1to3 ? '2px solid #2563eb' : '1px solid #cbd5e1' ?>; border-radius: 12px; padding: 12px 14px; display: flex; align-items: center; justify-content: space-between; text-decoration: none; cursor: pointer; transition: all 0.15s ease;"
                   onmouseover="this.style.transform='translateY(-2px)';" onmouseout="this.style.transform='translateY(0)';"
                   title="Filter: Lot OPEN yang berumur 1 - 3 Hari">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div style="width: 32px; height: 32px; border-radius: 8px; background: #e0e7ff; display: flex; align-items: center; justify-content: center;">
                            <svg style="width: 16px; height: 16px; color: #4338ca;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div>
                            <div style="font-size: 12px; font-weight: 900; color: #1e293b;">1 - 3 HARI</div>
                            <div style="font-size: 10px; font-weight: 600; color: #64748b;">Masa Awal Temuan</div>
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <span style="font-size: 20px; font-weight: 900; color: #1e293b;"><?= number_format($statsAging1to3) ?></span>
                        <span style="font-size: 10px; font-weight: 700; color: #64748b; margin-left: 2px;">Lot</span>
                    </div>
                </a>

                <!-- 4 - 6 HARI -->
                <?php $isActive4to6 = ($statusHandling === 'open' && $agingFilter === '4-6'); ?>
                <a href="<?= getFilterAgingUrl('4-6') ?>" 
                   style="background-color: <?= $isActive4to6 ? '#fefce8' : '#fffbeb' ?>; border: <?= $isActive4to6 ? '2px solid #d97706' : '1px solid #fde68a' ?>; border-radius: 12px; padding: 12px 14px; display: flex; align-items: center; justify-content: space-between; text-decoration: none; cursor: pointer; transition: all 0.15s ease;"
                   onmouseover="this.style.transform='translateY(-2px)';" onmouseout="this.style.transform='translateY(0)';"
                   title="Filter: Lot OPEN yang berumur 4 - 6 Hari">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div style="width: 32px; height: 32px; border-radius: 8px; background: #fef3c7; display: flex; align-items: center; justify-content: center;">
                            <svg style="width: 16px; height: 16px; color: #d97706;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                        </div>
                        <div>
                            <div style="font-size: 12px; font-weight: 900; color: #b45309;">4 - 6 HARI</div>
                            <div style="font-size: 10px; font-weight: 600; color: #92400e;">Tertunda Menengah</div>
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <span style="font-size: 20px; font-weight: 900; color: #b45309;"><?= number_format($statsAging4to6) ?></span>
                        <span style="font-size: 10px; font-weight: 700; color: #92400e; margin-left: 2px;">Lot</span>
                    </div>
                </a>

                <!-- ≥ 7 HARI -->
                <?php $isActive7plus = ($statusHandling === 'open' && $agingFilter === '7plus'); ?>
                <a href="<?= getFilterAgingUrl('7plus') ?>" 
                   style="background-color: <?= $isActive7plus ? '#fff1f2' : '#fef2f2' ?>; border: <?= $isActive7plus ? '2px solid #dc2626' : '1px solid #fecaca' ?>; border-radius: 12px; padding: 12px 14px; display: flex; align-items: center; justify-content: space-between; text-decoration: none; cursor: pointer; transition: all 0.15s ease;"
                   onmouseover="this.style.transform='translateY(-2px)';" onmouseout="this.style.transform='translateY(0)';"
                   title="Filter: Lot OPEN yang berumur ≥ 7 Hari">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div style="width: 32px; height: 32px; border-radius: 8px; background: #fee2e2; display: flex; align-items: center; justify-content: center;">
                            <svg style="width: 16px; height: 16px; color: #dc2626;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div>
                            <div style="font-size: 12px; font-weight: 900; color: #dc2626;">≥ 7 HARI</div>
                            <div style="font-size: 10px; font-weight: 600; color: #991b1b;">Mangkrak ≥ 1 Minggu</div>
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <span style="font-size: 20px; font-weight: 900; color: #dc2626;"><?= number_format($statsAging7plus) ?></span>
                        <span style="font-size: 10px; font-weight: 700; color: #991b1b; margin-left: 2px;">Lot</span>
                    </div>
                </a>
            </div>
        </div>

        <!-- Helper URL generator for quick date pill filter -->
        <?php
        function getDatePresetUrl($targetPeriod) {
            $params = $_GET;
            $params['period'] = $targetPeriod;
            $params['page'] = 1;
            return '?' . http_build_query($params);
        }
        ?>

        <!-- Filter & Search Bar Section (Clean Modern Card with Segmented Pill Controls) -->
        <div style="background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 16px; padding: 16px 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.03);" class="space-y-3.5">
            
            <!-- ROW 1: Segmented Pill Tab Bar (Gaya Modern Sesuai Mockup) -->
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; padding-bottom: 12px; border-bottom: 1px solid #f1f5f9;">
                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <span style="font-size: 11px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; margin-right: 2px;">
                        Periode:
                    </span>
                    
                    <!-- Segmented Pill Container -->
                    <div style="display: inline-flex; align-items: center; gap: 3px; background-color: #f1f5f9; padding: 3px; border-radius: 10px; border: 1px solid #e2e8f0; flex-wrap: wrap;">
                        <?php 
                        $pillPresets = [
                            'today'      => 'Hari Ini',
                            'this_week'  => 'Minggu Ini',
                            'this_month' => 'Bulan Ini',
                            'this_year'  => 'Tahun Ini',
                            'month_year' => 'Pilih Bulan & Tahun',
                            'custom'     => 'Rentang Tanggal',
                            'all'        => 'Semua'
                        ];
                        foreach ($pillPresets as $pKey => $pLabel): 
                            $isPillActive = ($period === $pKey);
                        ?>
                            <a href="<?= getDatePresetUrl($pKey) ?>"
                               style="padding: 5px 12px; border-radius: 7px; font-size: 11px; font-weight: 800; text-decoration: none; transition: all 0.15s ease; white-space: nowrap;
                                      background-color: <?= $isPillActive ? '#2563eb' : 'transparent' ?>;
                                      color: <?= $isPillActive ? '#ffffff' : '#64748b' ?>;
                                      <?= $isPillActive ? 'box-shadow: 0 2px 4px rgba(37,99,235,0.3);' : '' ?>"
                               onmouseover="if (!<?= $isPillActive ? 'true' : 'false' ?>) this.style.color='#0f172a';"
                               onmouseout="if (!<?= $isPillActive ? 'true' : 'false' ?>) this.style.color='#64748b';">
                                <?= $pLabel ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Info Label Periode Aktif -->
                <div style="font-size: 11px; font-weight: 700; color: #64748b; display: flex; align-items: center; gap: 5px;">
                    <span style="width: 6px; height: 6px; border-radius: 9999px; background-color: #2563eb;"></span>
                    <span>Aktif: 
                        <strong class="text-slate-800">
                            <?php 
                            if ($period === 'today') echo "Hari Ini (" . date('d/m/Y') . ")";
                            elseif ($period === 'this_week') echo "Minggu Ini (" . date('d/m', strtotime('monday this week')) . " - " . date('d/m/Y', strtotime('sunday this week')) . ")";
                            elseif ($period === 'this_month') echo "Bulan Ini (" . date('F Y') . ")";
                            elseif ($period === 'this_year') echo "Tahun Ini (" . date('Y') . ")";
                            elseif ($period === 'month_year') echo "Bulan " . date('F', mktime(0, 0, 0, $safeMonth, 10)) . " {$safeYear}";
                            elseif ($period === 'custom') echo ($startDate ? date('d/m/Y', strtotime($startDate)) : '...') . " s/d " . ($endDate ? date('d/m/Y', strtotime($endDate)) : '...');
                            else echo "Semua Waktu";
                            ?>
                        </strong>
                    </span>
                </div>
            </div>

            <!-- ROW 2: Form Filter (Status, Aging, Pencarian & Advance Date Sub-Panel) -->
            <form method="GET" action="" style="display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px;" class="w-full">
                <!-- Keep Period Parameter -->
                <input type="hidden" name="period" value="<?= htmlspecialchars($period) ?>">

                <!-- Status Handling Filter -->
                <div style="flex: 1 1 180px; min-width: 150px;">
                    <label style="display: block; font-size: 11px; font-weight: 800; color: #475569; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;">Status / Tindakan</label>
                    <select name="status_handling" onchange="this.form.submit()" style="width: 100%; padding: 8px 12px; background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 12px; font-weight: 700; color: #1e293b; outline: none;">
                        <option value="all" <?= ($statusHandling === 'all' && $agingFilter === 'all') ? 'selected' : '' ?>>Semua Status</option>
                        <option value="open" <?= ($statusHandling === 'open' && $agingFilter === 'all') ? 'selected' : '' ?>>Status: OPEN (Semua)</option>
                        <option value="closed" <?= $statusHandling === 'closed' ? 'selected' : '' ?>>Status: CLOSED (Sudah Ditangani)</option>
                        <option value="action" <?= $statusHandling === 'action' ? 'selected' : '' ?>>Action: ACTION (Sortir Divisi)</option>
                        <option value="abolish" <?= $statusHandling === 'abolish' ? 'selected' : '' ?>>Action: ABOLISH (Scrap/Dibuang)</option>
                    </select>
                </div>

                <!-- Aging Filter Dropdown -->
                <div style="flex: 1 1 180px; min-width: 150px;">
                    <label style="display: block; font-size: 11px; font-weight: 800; color: #475569; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;">Batas Waktu (Aging)</label>
                    <select name="aging" onchange="this.form.submit()" style="width: 100%; padding: 8px 12px; background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 12px; font-weight: 700; color: #1e293b; outline: none;">
                        <option value="all" <?= $agingFilter === 'all' ? 'selected' : '' ?>>Semua Batas Waktu</option>
                        <option value="1-3" <?= $agingFilter === '1-3' ? 'selected' : '' ?>>OPEN: 1 - 3 Hari</option>
                        <option value="4-6" <?= $agingFilter === '4-6' ? 'selected' : '' ?>>OPEN: 4 - 6 Hari</option>
                        <option value="7plus" <?= $agingFilter === '7plus' ? 'selected' : '' ?>>OPEN: ≥ 7 Hari</option>
                    </select>
                </div>

                <!-- Search Input -->
                <div style="flex: 2 1 240px; min-width: 200px;">
                    <label style="display: block; font-size: 11px; font-weight: 800; color: #475569; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;">Cari Lot / Part / Inspektur</label>
                    <div style="position: relative; width: 100%;">
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Ketik Part Code, Lot No, Ref No..." style="width: 100%; padding: 8px 12px 8px 34px; background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 12px; font-weight: 700; color: #0f172a; outline: none;">
                        <svg style="width: 16px; height: 16px; color: #94a3b8; position: absolute; left: 10px; top: 10px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                    </div>
                </div>

                <!-- Filter Actions -->
                <div style="flex: 0 0 auto; display: flex; align-items: center; gap: 8px;">
                    <button type="submit" style="background-color: #e11d48; color: #ffffff; padding: 8px 16px; border-radius: 10px; font-size: 12px; font-weight: 800; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 1px 2px rgba(225,29,72,0.2);">
                        <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
                        </svg>
                        <span>Terapkan</span>
                    </button>
                    <a href="<?= base_url('modules/ng_quarantine/index.php') ?>" style="background-color: #ffffff; color: #475569; border: 1px solid #cbd5e1; padding: 8px 12px; border-radius: 10px; font-size: 12px; font-weight: 800; text-decoration: none; display: inline-flex; align-items: center;" title="Reset Semua Filter">
                        <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                    </a>
                </div>

                <!-- SUB-PANEL 1: Pilih Bulan & Tahun Tertentu -->
                <?php if ($period === 'month_year'): ?>
                    <div style="flex: 1 1 100%; display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 6px; padding: 12px 14px; background-color: #eff6ff; border: 1px solid #bfdbfe; border-radius: 12px;">
                        <span style="font-size: 11px; font-weight: 800; color: #1e40af; text-transform: uppercase;">Pilih Bulan & Tahun:</span>
                        
                        <!-- Month Select -->
                        <div style="min-width: 140px;">
                            <select name="month" style="width: 100%; padding: 6px 10px; background-color: #ffffff; border: 1px solid #93c5fd; border-radius: 8px; font-size: 11px; font-weight: 700; color: #1e3a8a; outline: none;">
                                <?php 
                                $monthsList = [
                                    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
                                    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
                                    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
                                ];
                                foreach ($monthsList as $mNum => $mTxt): 
                                ?>
                                    <option value="<?= $mNum ?>" <?= ((int)$safeMonth === $mNum) ? 'selected' : '' ?>><?= $mTxt ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Year Select -->
                        <div style="min-width: 110px;">
                            <select name="year" style="width: 100%; padding: 6px 10px; background-color: #ffffff; border: 1px solid #93c5fd; border-radius: 8px; font-size: 11px; font-weight: 700; color: #1e3a8a; outline: none;">
                                <?php 
                                $curYear = (int)date('Y');
                                for ($yr = $curYear + 1; $yr >= 2023; $yr--): 
                                ?>
                                    <option value="<?= $yr ?>" <?= ((int)$safeYear === $yr) ? 'selected' : '' ?>><?= $yr ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <button type="submit" style="background-color: #2563eb; color: #ffffff; font-size: 11px; font-weight: 800; padding: 6px 14px; border-radius: 8px; border: none; cursor: pointer;">
                            Tampilkan Data Bulan
                        </button>
                    </div>
                <?php endif; ?>

                <!-- SUB-PANEL 2: Custom Range Date Pickers (Dari - Sampai) -->
                <?php if ($period === 'custom'): ?>
                    <div style="flex: 1 1 100%; display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px; margin-top: 6px; padding: 12px 14px; background-color: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 12px;">
                        <div style="flex: 1 1 160px;">
                            <label style="display: block; font-size: 10px; font-weight: 800; color: #64748b; margin-bottom: 3px; text-transform: uppercase;">Dari Tanggal:</label>
                            <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" style="width: 100%; padding: 6px 10px; background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 11px; font-weight: 700; color: #0f172a; outline: none;">
                        </div>
                        <div style="flex: 1 1 160px;">
                            <label style="display: block; font-size: 10px; font-weight: 800; color: #64748b; margin-bottom: 3px; text-transform: uppercase;">Sampai Tanggal:</label>
                            <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" style="width: 100%; padding: 6px 10px; background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 11px; font-weight: 700; color: #0f172a; outline: none;">
                        </div>
                        <button type="submit" style="background-color: #0f172a; color: #ffffff; font-size: 11px; font-weight: 800; padding: 7px 16px; border-radius: 8px; border: none; cursor: pointer;">
                            Terapkan Rentang
                        </button>
                    </div>
                <?php endif; ?>

            </form>
        </div>

        <!-- Styling Khusus Excel-Style Column Filters (High Z-Index & Overflow Protection) -->
        <style>
            .th-filter-wrapper {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                position: relative;
            }
            .th-filter-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 19px;
                height: 19px;
                border-radius: 4px;
                background-color: #f1f5f9;
                border: 1px solid #cbd5e1;
                color: #64748b;
                cursor: pointer;
                padding: 0;
                transition: all 0.15s ease;
                outline: none;
            }
            .th-filter-btn:hover {
                background-color: #e2e8f0;
                color: #0f172a;
                border-color: #94a3b8;
            }
            .th-filter-btn.active {
                background-color: #e11d48 !important;
                border-color: #be123c !important;
                color: #ffffff !important;
                box-shadow: 0 1px 3px rgba(225, 29, 72, 0.4);
            }
            /* High z-index popover dropdown FIXED positioning dengan scroll internal agar tidak pernah kepotong batas layar */
            .excel-filter-dropdown {
                position: fixed !important;
                z-index: 99999999 !important;
                background-color: #ffffff !important;
                border: 1.5px solid #94a3b8 !important;
                border-radius: 12px;
                box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.35), 0 10px 10px -5px rgba(0, 0, 0, 0.2) !important;
                width: 250px;
                max-width: 90vw;
                max-height: 85vh;
                padding: 10px;
                text-transform: none;
                letter-spacing: normal;
                font-weight: normal;
                color: #0f172a;
                display: none;
                text-align: left;
                box-sizing: border-box;
                overflow-y: auto;
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
                box-sizing: border-box;
            }
            .excel-filter-search:focus {
                border-color: #e11d48;
                box-shadow: 0 0 0 2px #fecdd3;
            }
            .excel-quick-btn {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                padding: 3.5px 8px;
                border-radius: 5px;
                font-size: 10px;
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
                color: #881337;
            }
            .excel-filter-list {
                max-height: 175px;
                overflow-y: auto;
                border: 1px solid #e2e8f0;
                border-radius: 6px;
                padding: 4px;
                margin-bottom: 8px;
                background-color: #f8fafc;
            }
            .excel-filter-item {
                display: flex;
                align-items: center;
                gap: 7px;
                padding: 4px 6px;
                border-radius: 4px;
                font-size: 11px;
                cursor: pointer;
                color: #1e293b;
                user-select: none;
                transition: background-color 0.1s ease;
            }
            .excel-filter-item:hover {
                background-color: #e2e8f0;
            }
            .excel-filter-item input[type="checkbox"] {
                cursor: pointer;
                width: 14px;
                height: 14px;
                border-radius: 3px;
                accent-color: #e11d48;
            }
            .excel-filter-actions {
                display: flex;
                align-items: center;
                justify-content: space-between;
                border-top: 1px solid #f1f5f9;
                padding-top: 8px;
            }
            .excel-btn-close {
                padding: 4px 9px;
                font-size: 11px;
                font-weight: 700;
                border-radius: 6px;
                background-color: #f1f5f9;
                border: 1px solid #cbd5e1;
                color: #475569;
                cursor: pointer;
            }
            .excel-btn-close:hover {
                background-color: #e2e8f0;
                color: #0f172a;
            }
            .excel-btn-apply {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                padding: 4px 11px;
                font-size: 11px;
                font-weight: 800;
                border-radius: 6px;
                background-color: #e11d48;
                border: none;
                color: #ffffff;
                cursor: pointer;
                box-shadow: 0 1px 2px rgba(225,29,72,0.3);
            }
            .excel-btn-apply:hover {
                background-color: #be123c;
            }
            th.th-filter-active-cell {
                z-index: 999999 !important;
                position: relative !important;
            }
        </style>

        <!-- Main Data Table Container (Native Horizontal Touch Scroll) -->
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm" style="overflow: visible;">
            <div class="px-5 py-4 bg-slate-900 text-white flex items-center justify-between rounded-t-2xl">
                <div class="font-extrabold text-xs tracking-wide flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full bg-rose-500 animate-ping"></span>
                    <span>DAFTAR DOKUMEN LOT NG & STATUS KARANTINA</span>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" id="btn-reset-excel-filters" onclick="resetAllQuarantineExcelFilters()" style="display: none; font-size: 10px; font-weight: 800; color: #e11d48; background-color: #ffe4e6; border: 1px solid #fecdd3; padding: 3px 8px; border-radius: 6px; cursor: pointer;" title="Reset semua filter kolom Excel yang sedang aktif">
                        ↺ Reset Filter Kolom
                    </button>
                    <span id="quarantine-row-counter" class="text-[11px] font-bold bg-slate-800 px-3 py-1 rounded-full text-slate-300 border border-slate-700">
                        Menampilkan <?= count($ngLots) ?> dari <?= $totalFiltered ?> Lot NG
                    </span>
                </div>
            </div>

            <div class="overflow-x-auto w-full custom-scrollbar" style="width: 100%; max-width: 100%; -webkit-overflow-scrolling: touch; overflow-y: visible;">
                <table class="w-full text-left text-xs text-slate-700 table-auto" style="width: 100%; min-width: 1650px; border-collapse: collapse;">
                    <thead class="bg-slate-100 text-slate-700 font-extrabold text-[11px] uppercase tracking-wider select-none border-b border-slate-200">
                        <tr>
                            <th style="padding: 11px 12px; width: 45px; text-align: center;">#</th>
                            
                            <!-- WAKTU TEMUAN -->
                            <th style="padding: 11px 12px; text-align: center; width: 155px; position: relative;">
                                <div class="th-filter-wrapper justify-center">
                                    <span>WAKTU TEMUAN</span>
                                    <button type="button" class="th-filter-btn" data-col="waktu" title="Filter Waktu Temuan" onclick="toggleQuarantineExcelFilter(event, 'waktu')">
                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                    </button>
                                </div>
                                <div id="filter-dropdown-waktu" class="excel-filter-dropdown align-left">
                                    <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                        <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER WAKTU</span>
                                        <button type="button" class="excel-reset-btn" onclick="resetSingleQuarantineFilter('waktu')">Reset</button>
                                    </div>
                                    <input type="text" class="excel-filter-search" placeholder="Cari tanggal..." oninput="onSearchQuarantineFilterOptions('waktu', this.value)">
                                    <div class="flex items-center gap-1.5 mb-2">
                                        <button type="button" class="excel-quick-btn" onclick="toggleSelectAllQuarantineFilter('waktu', true)">✓ Semua</button>
                                        <button type="button" class="excel-quick-btn" onclick="toggleSelectAllQuarantineFilter('waktu', false)">✕ Kosongkan</button>
                                    </div>
                                    <div class="excel-filter-list" id="filter-list-waktu"></div>
                                    <div class="excel-filter-actions">
                                        <button type="button" class="excel-btn-close" onclick="closeAllQuarantineExcelFilters()">Tutup</button>
                                        <button type="button" class="excel-btn-apply" onclick="applyQuarantineExcelFilters()">Terapkan</button>
                                    </div>
                                </div>
                            </th>

                            <!-- PART CODE -->
                            <th style="padding: 11px 12px; width: 140px; position: relative;">
                                <div class="th-filter-wrapper">
                                    <span>PART CODE</span>
                                    <button type="button" class="th-filter-btn" data-col="part_code" title="Filter Part Code" onclick="toggleQuarantineExcelFilter(event, 'part_code')">
                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                    </button>
                                </div>
                                <div id="filter-dropdown-part_code" class="excel-filter-dropdown align-left">
                                    <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                        <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER PART CODE</span>
                                        <button type="button" class="excel-reset-btn" onclick="resetSingleQuarantineFilter('part_code')">Reset</button>
                                    </div>
                                    <input type="text" class="excel-filter-search" placeholder="Cari part code..." oninput="onSearchQuarantineFilterOptions('part_code', this.value)">
                                    <div class="flex items-center gap-1.5 mb-2">
                                        <button type="button" class="excel-quick-btn" onclick="toggleSelectAllQuarantineFilter('part_code', true)">✓ Semua</button>
                                        <button type="button" class="excel-quick-btn" onclick="toggleSelectAllQuarantineFilter('part_code', false)">✕ Kosongkan</button>
                                    </div>
                                    <div class="excel-filter-list" id="filter-list-part_code"></div>
                                    <div class="excel-filter-actions">
                                        <button type="button" class="excel-btn-close" onclick="closeAllQuarantineExcelFilters()">Tutup</button>
                                        <button type="button" class="excel-btn-apply" onclick="applyQuarantineExcelFilters()">Terapkan</button>
                                    </div>
                                </div>
                            </th>

                            <!-- PART NAME -->
                            <th style="padding: 11px 12px; min-width: 170px; position: relative;">
                                <div class="th-filter-wrapper">
                                    <span>PART NAME</span>
                                    <button type="button" class="th-filter-btn" data-col="part_name" title="Filter Part Name" onclick="toggleQuarantineExcelFilter(event, 'part_name')">
                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                    </button>
                                </div>
                                <div id="filter-dropdown-part_name" class="excel-filter-dropdown align-left">
                                    <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                        <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER PART NAME</span>
                                        <button type="button" class="excel-reset-btn" onclick="resetSingleQuarantineFilter('part_name')">Reset</button>
                                    </div>
                                    <input type="text" class="excel-filter-search" placeholder="Cari part name..." oninput="onSearchQuarantineFilterOptions('part_name', this.value)">
                                    <div class="flex items-center gap-1.5 mb-2">
                                        <button type="button" class="excel-quick-btn" onclick="toggleSelectAllQuarantineFilter('part_name', true)">✓ Semua</button>
                                        <button type="button" class="excel-quick-btn" onclick="toggleSelectAllQuarantineFilter('part_name', false)">✕ Kosongkan</button>
                                    </div>
                                    <div class="excel-filter-list" id="filter-list-part_name"></div>
                                    <div class="excel-filter-actions">
                                        <button type="button" class="excel-btn-close" onclick="closeAllQuarantineExcelFilters()">Tutup</button>
                                        <button type="button" class="excel-btn-apply" onclick="applyQuarantineExcelFilters()">Terapkan</button>
                                    </div>
                                </div>
                            </th>

                            <!-- MODEL -->
                            <th style="padding: 11px 12px; text-align: center; width: 110px; position: relative;">
                                <div class="th-filter-wrapper justify-center">
                                    <span>MODEL</span>
                                    <button type="button" class="th-filter-btn" data-col="model" title="Filter Model" onclick="toggleQuarantineExcelFilter(event, 'model')">
                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                    </button>
                                </div>
                                <div id="filter-dropdown-model" class="excel-filter-dropdown align-left">
                                    <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                        <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER MODEL</span>
                                        <button type="button" class="excel-reset-btn" onclick="resetSingleQuarantineFilter('model')">Reset</button>
                                    </div>
                                    <input type="text" class="excel-filter-search" placeholder="Cari model..." oninput="onSearchQuarantineFilterOptions('model', this.value)">
                                    <div class="flex items-center gap-1.5 mb-2">
                                        <button type="button" class="excel-quick-btn" onclick="toggleSelectAllQuarantineFilter('model', true)">✓ Semua</button>
                                        <button type="button" class="excel-quick-btn" onclick="toggleSelectAllQuarantineFilter('model', false)">✕ Kosongkan</button>
                                    </div>
                                    <div class="excel-filter-list" id="filter-list-model"></div>
                                    <div class="excel-filter-actions">
                                        <button type="button" class="excel-btn-close" onclick="closeAllQuarantineExcelFilters()">Tutup</button>
                                        <button type="button" class="excel-btn-apply" onclick="applyQuarantineExcelFilters()">Terapkan</button>
                                    </div>
                                </div>
                            </th>

                            <!-- LOT NO -->
                            <th style="padding: 11px 12px; width: 135px; position: relative;">
                                <div class="th-filter-wrapper">
                                    <span>LOT NO</span>
                                    <button type="button" class="th-filter-btn" data-col="lot_no" title="Filter Lot No" onclick="toggleQuarantineExcelFilter(event, 'lot_no')">
                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                    </button>
                                </div>
                                <div id="filter-dropdown-lot_no" class="excel-filter-dropdown align-left">
                                    <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                        <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER LOT NO</span>
                                        <button type="button" class="excel-reset-btn" onclick="resetSingleQuarantineFilter('lot_no')">Reset</button>
                                    </div>
                                    <input type="text" class="excel-filter-search" placeholder="Cari lot no..." oninput="onSearchQuarantineFilterOptions('lot_no', this.value)">
                                    <div class="flex items-center gap-1.5 mb-2">
                                        <button type="button" class="excel-quick-btn" onclick="toggleSelectAllQuarantineFilter('lot_no', true)">✓ Semua</button>
                                        <button type="button" class="excel-quick-btn" onclick="toggleSelectAllQuarantineFilter('lot_no', false)">✕ Kosongkan</button>
                                    </div>
                                    <div class="excel-filter-list" id="filter-list-lot_no"></div>
                                    <div class="excel-filter-actions">
                                        <button type="button" class="excel-btn-close" onclick="closeAllQuarantineExcelFilters()">Tutup</button>
                                        <button type="button" class="excel-btn-apply" onclick="applyQuarantineExcelFilters()">Terapkan</button>
                                    </div>
                                </div>
                            </th>

                            <!-- REF NO -->
                            <th style="padding: 11px 12px; width: 130px; position: relative;">
                                <div class="th-filter-wrapper">
                                    <span>REF NO</span>
                                    <button type="button" class="th-filter-btn" data-col="ref_no" title="Filter Ref No" onclick="toggleQuarantineExcelFilter(event, 'ref_no')">
                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                    </button>
                                </div>
                                <div id="filter-dropdown-ref_no" class="excel-filter-dropdown align-left">
                                    <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                        <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER REF NO</span>
                                        <button type="button" class="excel-reset-btn" onclick="resetSingleQuarantineFilter('ref_no')">Reset</button>
                                    </div>
                                    <input type="text" class="excel-filter-search" placeholder="Cari ref no..." oninput="onSearchQuarantineFilterOptions('ref_no', this.value)">
                                    <div class="flex items-center gap-1.5 mb-2">
                                        <button type="button" class="excel-quick-btn" onclick="toggleSelectAllQuarantineFilter('ref_no', true)">✓ Semua</button>
                                        <button type="button" class="excel-quick-btn" onclick="toggleSelectAllQuarantineFilter('ref_no', false)">✕ Kosongkan</button>
                                    </div>
                                    <div class="excel-filter-list" id="filter-list-ref_no"></div>
                                    <div class="excel-filter-actions">
                                        <button type="button" class="excel-btn-close" onclick="closeAllQuarantineExcelFilters()">Tutup</button>
                                        <button type="button" class="excel-btn-apply" onclick="applyQuarantineExcelFilters()">Terapkan</button>
                                    </div>
                                </div>
                            </th>

                            <th style="padding: 11px 12px; text-align: right; width: 95px;">QTY LOT</th>
                            <th style="padding: 11px 12px; text-align: center; width: 70px;">NG</th>

                            <!-- TEMUAN CACAT -->
                            <th style="padding: 11px 12px; min-width: 190px; position: relative;">
                                <div class="th-filter-wrapper">
                                    <span>TEMUAN CACAT</span>
                                    <button type="button" class="th-filter-btn" data-col="cacat" title="Filter Cacat" onclick="toggleQuarantineExcelFilter(event, 'cacat')">
                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                    </button>
                                </div>
                                <div id="filter-dropdown-cacat" class="excel-filter-dropdown align-left">
                                    <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                        <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER TEMUAN CACAT</span>
                                        <button type="button" class="excel-reset-btn" onclick="resetSingleQuarantineFilter('cacat')">Reset</button>
                                    </div>
                                    <input type="text" class="excel-filter-search" placeholder="Cari nama defect..." oninput="onSearchQuarantineFilterOptions('cacat', this.value)">
                                    <div class="flex items-center gap-1.5 mb-2">
                                        <button type="button" class="excel-quick-btn" onclick="toggleSelectAllQuarantineFilter('cacat', true)">✓ Semua</button>
                                        <button type="button" class="excel-quick-btn" onclick="toggleSelectAllQuarantineFilter('cacat', false)">✕ Kosongkan</button>
                                    </div>
                                    <div class="excel-filter-list" id="filter-list-cacat"></div>
                                    <div class="excel-filter-actions">
                                        <button type="button" class="excel-btn-close" onclick="closeAllQuarantineExcelFilters()">Tutup</button>
                                        <button type="button" class="excel-btn-apply" onclick="applyQuarantineExcelFilters()">Terapkan</button>
                                    </div>
                                </div>
                            </th>

                            <!-- INSPEKTUR AWAL -->
                            <th style="padding: 11px 12px; width: 140px; position: relative;">
                                <div class="th-filter-wrapper">
                                    <span>INSPEKTUR AWAL</span>
                                    <button type="button" class="th-filter-btn" data-col="inspector" title="Filter Inspektur" onclick="toggleQuarantineExcelFilter(event, 'inspector')">
                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                    </button>
                                </div>
                                <div id="filter-dropdown-inspector" class="excel-filter-dropdown align-right">
                                    <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                        <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER INSPEKTUR</span>
                                        <button type="button" class="excel-reset-btn" onclick="resetSingleQuarantineFilter('inspector')">Reset</button>
                                    </div>
                                    <input type="text" class="excel-filter-search" placeholder="Cari nama inspektur..." oninput="onSearchQuarantineFilterOptions('inspector', this.value)">
                                    <div class="flex items-center gap-1.5 mb-2">
                                        <button type="button" class="excel-quick-btn" onclick="toggleSelectAllQuarantineFilter('inspector', true)">✓ Semua</button>
                                        <button type="button" class="excel-quick-btn" onclick="toggleSelectAllQuarantineFilter('inspector', false)">✕ Kosongkan</button>
                                    </div>
                                    <div class="excel-filter-list" id="filter-list-inspector"></div>
                                    <div class="excel-filter-actions">
                                        <button type="button" class="excel-btn-close" onclick="closeAllQuarantineExcelFilters()">Tutup</button>
                                        <button type="button" class="excel-btn-apply" onclick="applyQuarantineExcelFilters()">Terapkan</button>
                                    </div>
                                </div>
                            </th>

                            <!-- STATUS -->
                            <th style="padding: 11px 12px; text-align: center; width: 110px; position: relative;">
                                <div class="th-filter-wrapper justify-center">
                                    <span>STATUS</span>
                                    <button type="button" class="th-filter-btn" data-col="status" title="Filter Status" onclick="toggleQuarantineExcelFilter(event, 'status')">
                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                    </button>
                                </div>
                                <div id="filter-dropdown-status" class="excel-filter-dropdown align-right">
                                    <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                        <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER STATUS</span>
                                        <button type="button" class="excel-reset-btn" onclick="resetSingleQuarantineFilter('status')">Reset</button>
                                    </div>
                                    <input type="text" class="excel-filter-search" placeholder="Cari status..." oninput="onSearchQuarantineFilterOptions('status', this.value)">
                                    <div class="flex items-center gap-1.5 mb-2">
                                        <button type="button" class="excel-quick-btn" onclick="toggleSelectAllQuarantineFilter('status', true)">✓ Semua</button>
                                        <button type="button" class="excel-quick-btn" onclick="toggleSelectAllQuarantineFilter('status', false)">✕ Kosongkan</button>
                                    </div>
                                    <div class="excel-filter-list" id="filter-list-status"></div>
                                    <div class="excel-filter-actions">
                                        <button type="button" class="excel-btn-close" onclick="closeAllQuarantineExcelFilters()">Tutup</button>
                                        <button type="button" class="excel-btn-apply" onclick="applyQuarantineExcelFilters()">Terapkan</button>
                                    </div>
                                </div>
                            </th>

                            <!-- ACTION -->
                            <th style="padding: 11px 12px; text-align: center; width: 130px; position: relative;">
                                <div class="th-filter-wrapper justify-center">
                                    <span>ACTION</span>
                                    <button type="button" class="th-filter-btn" data-col="action" title="Filter Action" onclick="toggleQuarantineExcelFilter(event, 'action')">
                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                    </button>
                                </div>
                                <div id="filter-dropdown-action" class="excel-filter-dropdown align-right">
                                    <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                        <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER ACTION</span>
                                        <button type="button" class="excel-reset-btn" onclick="resetSingleQuarantineFilter('action')">Reset</button>
                                    </div>
                                    <input type="text" class="excel-filter-search" placeholder="Cari action..." oninput="onSearchQuarantineFilterOptions('action', this.value)">
                                    <div class="flex items-center gap-1.5 mb-2">
                                        <button type="button" class="excel-quick-btn" onclick="toggleSelectAllQuarantineFilter('action', true)">✓ Semua</button>
                                        <button type="button" class="excel-quick-btn" onclick="toggleSelectAllQuarantineFilter('action', false)">✕ Kosongkan</button>
                                    </div>
                                    <div class="excel-filter-list" id="filter-list-action"></div>
                                    <div class="excel-filter-actions">
                                        <button type="button" class="excel-btn-close" onclick="closeAllQuarantineExcelFilters()">Tutup</button>
                                        <button type="button" class="excel-btn-apply" onclick="applyQuarantineExcelFilters()">Terapkan</button>
                                    </div>
                                </div>
                            </th>

                            <th style="padding: 11px 12px; text-align: center; width: 150px;">AKSI</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-[11px]">
                        <?php if (empty($ngLots)): ?>
                            <tr>
                                <td colspan="14" class="py-14 text-center text-slate-400 bg-white">
                                    <div class="flex flex-col items-center justify-center gap-2">
                                        <svg class="w-12 h-12 text-slate-300 stroke-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        <span class="font-bold text-slate-700 text-sm">Tidak ada record Lot NG yang ditemukan</span>
                                        <span class="text-xs text-slate-400 max-w-sm">Semua lot dalam kondisi normal atau ubah filter pencarian di atas.</span>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php 
                            $rowNum = $offset;
                            foreach ($ngLots as $idx => $rLot): 
                                $rowNum++;
                                $startTimeTs = !empty($rLot['lot_created_at']) ? strtotime($rLot['lot_created_at']) : strtotime($rLot['session_started_at']);
                                $dateDisplay = date('d/m/Y', $startTimeTs);
                                $timeDisplay = date('H:i', $startTimeTs) . ' WIB';
                                $shiftName   = getShiftName($rLot['session_started_at']);
                                
                                // Extract plain text defect names for excel filter
                                $defectNames = [];
                                if (!empty($rLot['defects'])) {
                                    foreach ($rLot['defects'] as $df) {
                                        $dName = trim($df['defect_name'] ?? '');
                                        if ($dName !== '') $defectNames[] = $dName;
                                    }
                                }
                                $defectNamesStr = implode(', ', array_unique($defectNames)) ?: 'Tidak Ada Defect';
                            ?>
                                <tr id="quarantine-row-<?= (int)$rLot['lot_id'] ?>" 
                                    style="<?= $rLot['row_style'] ?? '' ?>" 
                                    class="quarantine-data-row <?= $rLot['row_class'] ?? 'font-medium' ?> transition-colors"
                                    data-filter-waktu="<?= htmlspecialchars($dateDisplay) ?>"
                                    data-filter-part_code="<?= htmlspecialchars(strtoupper(trim($rLot['part_code']))) ?>"
                                    data-filter-part_name="<?= htmlspecialchars(trim($rLot['part_name'])) ?>"
                                    data-filter-model="<?= htmlspecialchars(strtoupper(trim($rLot['model_name']))) ?>"
                                    data-filter-lot_no="<?= htmlspecialchars(strtoupper(trim($rLot['lot_number'] ?: '-'))) ?>"
                                    data-filter-ref_no="<?= htmlspecialchars(strtoupper(trim($rLot['ref_number'] ?: '-'))) ?>"
                                    data-filter-cacat="<?= htmlspecialchars($defectNamesStr) ?>"
                                    data-filter-inspector="<?= htmlspecialchars(trim($rLot['inspector_name'])) ?>"
                                    data-filter-status="<?= htmlspecialchars($rLot['status_lifecycle']) ?>"
                                    data-filter-action="<?= htmlspecialchars($rLot['action_type']) ?>">
                                    <!-- # Index -->
                                    <td style="padding: 10px 12px; text-align: center; font-weight: 700; color: #94a3b8;"><?= $rowNum ?></td>

                                    <!-- Waktu Temuan & Aging -->
                                    <td style="padding: 10px 12px; text-align: center; font-family: monospace; white-space: nowrap;">
                                        <div style="font-weight: 800; color: #0f172a;"><?= $dateDisplay ?></div>
                                        <div style="font-size: 9.5px; color: #64748b; font-weight: 600; font-family: sans-serif; margin-top: 1px;"><?= $timeDisplay ?></div>
                                        <div style="margin-top: 3px; display: flex; flex-direction: column; align-items: center; gap: 2px;">
                                            <span class="inline-block px-1.5 py-0.5 rounded text-[9px] font-bold bg-slate-100 text-slate-600 border border-slate-200">
                                                <?= htmlspecialchars($shiftName) ?>
                                            </span>
                                            <?php if (!$rLot['is_closed']): ?>
                                                <?php if ($rLot['aging_bracket'] === '1-3'): ?>
                                                    <span style="display: inline-flex; align-items: center; gap: 3px; padding: 1px 6px; border-radius: 4px; font-size: 9px; font-weight: 800; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe;" title="Durasi penanganan: <?= $rLot['aging_days'] ?> hari">
                                                        <svg style="width: 10px; height: 10px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                                        Hari ke-<?= $rLot['aging_days'] ?>
                                                    </span>
                                                <?php elseif ($rLot['aging_bracket'] === '4-6'): ?>
                                                    <span style="display: inline-flex; align-items: center; gap: 3px; padding: 1px 6px; border-radius: 4px; font-size: 9px; font-weight: 800; background: #fffbeb; color: #b45309; border: 1px solid #fde68a;" title="Durasi penanganan: <?= $rLot['aging_days'] ?> hari">
                                                        <svg style="width: 10px; height: 10px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                                                        Hari ke-<?= $rLot['aging_days'] ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span style="display: inline-flex; align-items: center; gap: 3px; padding: 1px 6px; border-radius: 4px; font-size: 9px; font-weight: 900; background: #fef2f2; color: #dc2626; border: 1px solid #fecaca;" title="Mangkrak: <?= $rLot['aging_days'] ?> hari">
                                                        <svg style="width: 10px; height: 10px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                                        Hari ke-<?= $rLot['aging_days'] ?>
                                                    </span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <!-- Part Code -->
                                    <td style="padding: 10px 12px; font-family: monospace; font-weight: 800; color: #0284c7; white-space: nowrap;">
                                        <?= htmlspecialchars($rLot['part_code']) ?>
                                    </td>

                                    <!-- Part Name -->
                                    <td style="padding: 10px 12px; font-weight: 700; color: #1e293b;">
                                        <?= htmlspecialchars($rLot['part_name']) ?>
                                        <div class="text-[9.5px] text-slate-400 font-medium">Cust: <?= htmlspecialchars($rLot['customer'] ?: 'INTERNAL') ?></div>
                                    </td>

                                    <!-- Model -->
                                    <td style="padding: 10px 12px; text-align: center; font-weight: 700; color: #64748b;">
                                        <?= htmlspecialchars($rLot['model_name']) ?>
                                    </td>

                                    <!-- Lot No -->
                                    <td style="padding: 10px 12px; font-family: monospace; font-weight: 700; color: #334155; white-space: nowrap;">
                                        <?= htmlspecialchars($rLot['lot_number'] ?: '-') ?>
                                    </td>

                                    <!-- Ref No -->
                                    <td style="padding: 10px 12px; font-family: monospace; font-weight: 800; color: #7c3aed; white-space: nowrap;">
                                        <div><?= htmlspecialchars($rLot['ref_number'] ?: '-') ?></div>
                                    </td>

                                    <!-- Qty Lot -->
                                    <td style="padding: 10px 12px; text-align: right; font-family: monospace; font-weight: 800; color: #0f172a; white-space: nowrap;">
                                        <?= number_format((int)$rLot['qty_lot']) ?> pcs
                                    </td>

                                    <!-- NG Qty -->
                                    <td style="padding: 10px 12px; text-align: center; white-space: nowrap;">
                                        <span style="font-family: monospace; font-size: 11px; font-weight: 800; padding: 2px 7px; border-radius: 4px; background: #fef2f2; color: #dc2626; border: 1px solid #fecaca;">
                                            <?= (int)$rLot['ng_count'] ?>
                                        </span>
                                    </td>

                                    <!-- Defect List -->
                                    <td style="padding: 10px 12px;">
                                        <?= $rLot['defect_html'] ?>
                                    </td>

                                    <!-- Inspektur Awal -->
                                    <td style="padding: 10px 12px; font-weight: 700; color: #334155; white-space: nowrap;">
                                        <div class="flex items-center gap-1.5" title="QC Inspector: <?= htmlspecialchars($rLot['inspector_name']) ?>">
                                            <div class="w-5 h-5 rounded-full bg-rose-100 text-rose-700 font-extrabold flex items-center justify-center text-[10px] border border-rose-200 uppercase">
                                                <?= mb_substr($rLot['inspector_name'], 0, 1) ?>
                                            </div>
                                            <span class="truncate max-w-[110px]"><?= htmlspecialchars($rLot['inspector_name']) ?></span>
                                        </div>
                                    </td>

                                    <!-- Kolom 1: STATUS (OPEN / CLOSED) -->
                                    <td style="padding: 10px 12px; text-align: center; white-space: nowrap;">
                                        <?php if ($rLot['status_lifecycle'] === 'CLOSED'): ?>
                                            <span style="display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 9999px; font-size: 10.5px; font-weight: 900; background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0;">
                                                <span style="width: 6px; height: 6px; border-radius: 9999px; background: #10b981;"></span>
                                                CLOSED
                                            </span>
                                        <?php else: ?>
                                            <span style="display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 9999px; font-size: 10.5px; font-weight: 900; background: #fff1f2; color: #e11d48; border: 1px solid #fecdd3;">
                                                <span style="width: 6px; height: 6px; border-radius: 9999px; background: #f43f5e;" class="animate-pulse"></span>
                                                OPEN
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Kolom 2: ACTION (ACTION / ABOLISH / WAITING) -->
                                    <td style="padding: 10px 12px; text-align: center; white-space: nowrap;">
                                        <?php if ($rLot['action_type'] === 'ACTION'): ?>
                                            <span style="display: inline-block; padding: 3px 10px; border-radius: 9999px; font-size: 10.5px; font-weight: 900; background: #f0f9ff; color: #0284c7; border: 1px solid #bae6fd;">
                                                ACTION
                                            </span>
                                        <?php elseif ($rLot['action_type'] === 'ABOLISH'): ?>
                                            <span style="display: inline-block; padding: 3px 10px; border-radius: 9999px; font-size: 10.5px; font-weight: 900; background: #fdf2f8; color: #db2777; border: 1px solid #fbcfe8;">
                                                ABOLISH
                                            </span>
                                        <?php else: ?>
                                            <span style="display: inline-block; padding: 3px 10px; border-radius: 9999px; font-size: 10.5px; font-weight: 800; background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0;">
                                                WAITING
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Aksi -->
                                    <td style="padding: 10px 12px; text-align: center; white-space: nowrap;">
                                        <div style="display: inline-flex; align-items: center; gap: 6px;">
                                            <a href="<?= base_url('modules/ng_quarantine/detail.php?id=' . $rLot['lot_id']) ?>" 
                                               style="background-color: #2563eb; color: #ffffff; padding: 6px 10px; border-radius: 8px; font-weight: 800; font-size: 11px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 2px rgba(37,99,235,0.2);" 
                                               title="Lihat Detail Traceability & Audit Trail Lot">
                                                <svg style="width: 13px; height: 13px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                                </svg>
                                                <span>Detail</span>
                                            </a>
                                            
                                            <?php if (!$rLot['is_closed']): ?>
                                                <!-- Tombol Re-Inspek (Action / Abolish) -->
                                                <button type="button" 
                                                        onclick="openReinspectChoiceModal(<?= (int)$rLot['lot_id'] ?>, '<?= htmlspecialchars(addslashes($rLot['lot_number'] ?: '-')) ?>', '<?= htmlspecialchars(addslashes($rLot['part_code'] . ' - ' . $rLot['part_name'])) ?>', '<?= htmlspecialchars(addslashes($rLot['closure_info']['notes'] ?? '')) ?>')"
                                                        style="background-color: #7c3aed; color: #ffffff; padding: 6px 11px; border-radius: 8px; font-weight: 800; font-size: 11px; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 2px rgba(124,58,237,0.25);" 
                                                        title="Tindakan Lot Karantina: Action (Ditangani) atau Abolish (Scrap)">
                                                    <svg style="width: 13px; height: 13px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                    </svg>
                                                    <span>Re-Inspek</span>
                                                </button>
                                            <?php else: ?>
                                                <!-- Status Penutupan / Edit Action Notes Jika Sudah Ditangani -->
                                                <button type="button" 
                                                        onclick="openActionModal(<?= (int)$rLot['lot_id'] ?>, '<?= htmlspecialchars(addslashes($rLot['lot_number'] ?: '-')) ?>', '<?= htmlspecialchars(addslashes($rLot['part_code'] . ' - ' . $rLot['part_name'])) ?>', '<?= $rLot['is_abolished'] ? 'abolish' : 'action' ?>', '<?= htmlspecialchars(addslashes($rLot['closure_info']['notes'] ?? '')) ?>')"
                                                        style="background-color: <?= $rLot['is_abolished'] ? '#475569' : '#0284c7' ?>; color: #ffffff; padding: 6px 9px; border-radius: 8px; font-weight: 800; font-size: 11px; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;"
                                                        title="Lihat / Edit Catatan <?= $rLot['is_abolished'] ? 'Abolish' : 'Action' ?>">
                                                    <svg style="width: 12px; height: 12px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                    </svg>
                                                    <span><?= $rLot['is_abolished'] ? 'Edit Abolish' : 'Edit Action' ?></span>
                                                </button>
                                            <?php endif; ?>

                                            <a href="<?= base_url('modules/inspection/print_rejection.php?id=' . ($rLot['rejected_session_id'] ?? $rLot['inspection_session_id']) . '&session_lot_id=' . ($rLot['rejected_lot_id'] ?? $rLot['lot_id'])) ?>" 
                                               target="_blank"
                                               style="background-color: #d97706; color: #ffffff; padding: 6px 10px; border-radius: 8px; font-weight: 800; font-size: 11px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 2px rgba(217,119,6,0.2);" 
                                               title="Cetak Rejection Sheet">
                                                <svg style="width: 13px; height: 13px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                                                </svg>
                                                <span>Cetak</span>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Bar -->
            <?php if ($totalPages > 1): ?>
                <div class="px-5 py-3.5 bg-slate-50 border-t border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
                    <div class="font-bold text-slate-500">
                        Halaman <span class="text-slate-800 font-extrabold"><?= $page ?></span> dari <span class="text-slate-800 font-extrabold"><?= $totalPages ?></span>
                    </div>
                    <div class="flex items-center gap-1">
                        <?php if ($page > 1): ?>
                            <a href="?period=<?= urlencode($period) ?>&status_handling=<?= urlencode($statusHandling) ?>&search=<?= urlencode($search) ?>&page=<?= $page - 1 ?>" class="px-3 py-1.5 bg-white border border-slate-300 rounded-lg font-bold text-slate-700 hover:bg-slate-100 transition-all">← Prev</a>
                        <?php endif; ?>

                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                            <?php if ($p == $page): ?>
                                <span class="px-3 py-1.5 bg-rose-600 text-white rounded-lg font-black"><?= $p ?></span>
                            <?php elseif ($p == 1 || $p == $totalPages || abs($p - $page) <= 2): ?>
                                <a href="?period=<?= urlencode($period) ?>&status_handling=<?= urlencode($statusHandling) ?>&search=<?= urlencode($search) ?>&page=<?= $p ?>" class="px-3 py-1.5 bg-white border border-slate-300 rounded-lg font-bold text-slate-700 hover:bg-slate-100 transition-all"><?= $p ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="?period=<?= urlencode($period) ?>&status_handling=<?= urlencode($statusHandling) ?>&search=<?= urlencode($search) ?>&page=<?= $page + 1 ?>" class="px-3 py-1.5 bg-white border border-slate-300 rounded-lg font-bold text-slate-700 hover:bg-slate-100 transition-all">Next →</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    </div>
</main>

<script>
/**
 * Dialog Pilihan Aksi Re-Inspek: Action vs Abolish
 */
function openReinspectChoiceModal(lotId, lotNumber, partInfo, existingNotes) {
    Swal.fire({
        title: '<div style="text-align: left; font-size: 15px; font-weight: 900; color: #0f172a; display: flex; align-items: center; gap: 8px;"><span style="width: 10px; height: 10px; border-radius: 9999px; background: #7c3aed; display: inline-block;"></span>Tindakan Penanganan Lot NG</div>',
        html: `
            <div style="text-align: left; font-size: 12px; display: flex; flex-direction: column; gap: 14px; padding-top: 4px;">
                <!-- Lot Summary Header Card -->
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 14px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                        <span style="color: #64748b; font-size: 11px; font-weight: 700; text-transform: uppercase;">Lot Number:</span>
                        <span style="font-family: monospace; font-weight: 800; color: #0f172a; font-size: 12px;">${lotNumber}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="color: #64748b; font-size: 11px; font-weight: 700; text-transform: uppercase;">Part:</span>
                        <span style="font-weight: 700; color: #1e293b; font-size: 11.5px; text-align: right; max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${partInfo}</span>
                    </div>
                </div>

                <!-- 2 Modern Action / Abolish Selection Cards -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <!-- ACTION Button Card -->
                    <button type="button" 
                            onclick="Swal.close(); openActionModal(${lotId}, '${lotNumber}', '${partInfo}', 'action', '${existingNotes || ''}');" 
                            style="display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; padding: 18px 12px; background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%); color: #ffffff; border: none; border-radius: 14px; cursor: pointer; box-shadow: 0 4px 12px rgba(2, 132, 199, 0.25); transition: transform 0.15s ease, box-shadow 0.15s ease;"
                            onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 16px rgba(2, 132, 199, 0.35)';"
                            onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 12px rgba(2, 132, 199, 0.25)';">
                        <div style="width: 42px; height: 42px; border-radius: 12px; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center;">
                            <svg style="width: 24px; height: 24px; color: #ffffff;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <span style="font-size: 14px; font-weight: 900; letter-spacing: 0.5px;">ACTION</span>
                    </button>
                    
                    <!-- ABOLISH Button Card -->
                    <button type="button" 
                            onclick="Swal.close(); openActionModal(${lotId}, '${lotNumber}', '${partInfo}', 'abolish', '${existingNotes || ''}');" 
                            style="display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; padding: 18px 12px; background: linear-gradient(135deg, #e11d48 0%, #be123c 100%); color: #ffffff; border: none; border-radius: 14px; cursor: pointer; box-shadow: 0 4px 12px rgba(225, 29, 72, 0.25); transition: transform 0.15s ease, box-shadow 0.15s ease;"
                            onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 16px rgba(225, 29, 72, 0.35)';"
                            onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 12px rgba(225, 29, 72, 0.25)';">
                        <div style="width: 42px; height: 42px; border-radius: 12px; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center;">
                            <svg style="width: 24px; height: 24px; color: #ffffff;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                        </div>
                        <span style="font-size: 14px; font-weight: 900; letter-spacing: 0.5px;">ABOLISH</span>
                    </button>
                </div>
            </div>
        `,
        showConfirmButton: false,
        showCancelButton: true,
        cancelButtonText: 'Tutup',
        cancelButtonColor: '#64748b',
        customClass: {
            popup: 'rounded-2xl shadow-2xl'
        }
    });
}

/**
 * Modal Input Keterangan Penanganan (Action / Abolish)
 */
function openActionModal(lotId, lotNumber, partInfo, closureType, existingNotes) {
    const isAbolish = (closureType === 'abolish');
    const modalTitle = isAbolish 
        ? '<div class="text-left font-black text-slate-800 text-base flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-rose-600"></span>Form Abolish Lot (Scrap / Dibuang)</div>'
        : '<div class="text-left font-black text-slate-800 text-base flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-sky-500"></span>Form Action Selesai (Penanganan Divisi)</div>';
    
    const bannerInfo = isAbolish
        ? `<div class="p-2.5 bg-rose-50 border border-rose-200 rounded-xl text-[11px] text-rose-800 font-medium">
             <b>Peringatan:</b> Lot yang di-Abolish akan ditandai sebagai SCRAP dan ditutup permanen. Lot ini selamanya tidak akan bisa di-scan kembali pada sesi inspeksi OQC.
           </div>`
        : `<div class="p-2.5 bg-sky-50 border border-sky-200 rounded-xl text-[11px] text-sky-800 font-medium">
             Gunakan opsi ini jika barang NG telah selesai disortir/diperbaiki oleh divisi terkait berdasarkan memo/laporan penanganan internal.
           </div>`;

    const labelText = isAbolish ? 'Keterangan / Alasan Pembuangan (Scrap)' : 'Catatan Penanganan / Hasil Sortir Divisi';
    const placeholderText = isAbolish 
        ? 'Contoh: Diputuskan scrap/abolish sesuai instruksi Engineering & QA memo #99 karena crack kritis...'
        : 'Contoh: Telah disortir oleh tim Produksi, 50 pcs OK dan disimpan di Buffer Rack A-02 sesuai Memo #123...';

    const confirmBtnColor = isAbolish ? '#dc2626' : '#0284c7';
    const confirmBtnText  = isAbolish ? 'Konfirmasi Abolish' : 'Simpan Action';

    Swal.fire({
        title: modalTitle,
        html: `
            <div class="text-left text-xs space-y-3 pt-2">
                <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl space-y-1">
                    <div class="flex justify-between">
                        <span class="text-slate-500 font-bold">Lot Number:</span>
                        <span class="font-mono font-extrabold text-slate-800">${lotNumber}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-500 font-bold">Part:</span>
                        <span class="font-bold text-slate-800">${partInfo}</span>
                    </div>
                </div>
                ${bannerInfo}
                <div>
                    <label class="block font-bold text-slate-700 mb-1">${labelText} <span class="text-rose-500">*</span></label>
                    <textarea id="swal-action-notes" rows="4" class="w-full p-2.5 bg-white border border-slate-300 rounded-xl text-xs text-slate-800 focus:ring-2 focus:ring-sky-500 focus:outline-none" placeholder="${placeholderText}">${existingNotes || ''}</textarea>
                </div>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: confirmBtnText,
        cancelButtonText: 'Kembali',
        confirmButtonColor: confirmBtnColor,
        cancelButtonColor: '#64748b',
        focusConfirm: false,
        preConfirm: () => {
            const notes = document.getElementById('swal-action-notes').value.trim();
            if (!notes) {
                Swal.showValidationMessage('Keterangan penanganan wajib diisi!');
                return false;
            }
            return { lotId, notes, closureType };
        }
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Menyimpan...',
                text: 'Mencatat status penanganan ke database',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            const formData = new FormData();
            formData.append('lot_id', result.value.lotId);
            formData.append('closure_type', result.value.closureType);
            formData.append('action_notes', result.value.notes);

            fetch('<?= base_url('modules/ng_quarantine/close_action.php') ?>', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Berhasil Disimpan',
                        text: data.message,
                        timer: 1800,
                        showConfirmButton: false
                    }).then(() => {
                        window.location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal Menyimpan',
                        text: data.message || 'Terjadi kesalahan pada sistem.'
                    });
                }
            })
            .catch(err => {
                Swal.fire({
                    icon: 'error',
                    title: 'Kesalahan Jaringan',
                    text: 'Tidak dapat menghubungi server: ' + err.message
                });
            });
        }
    });
}

// ── EXCEL-STYLE COLUMN FILTERS FOR QUARANTINE TABLE ───────────────────────
const QuarantineExcelFilter = {
    cols: ['waktu', 'part_code', 'part_name', 'model', 'lot_no', 'ref_no', 'cacat', 'inspector', 'status', 'action'],
    filters: {},
    activeCol: null
};

// Initialize filter set for all columns
QuarantineExcelFilter.cols.forEach(col => {
    QuarantineExcelFilter.filters[col] = new Set();
});

function initQuarantineExcelFilters() {
    QuarantineExcelFilter.cols.forEach(col => {
        populateQuarantineFilterOptions(col);
    });
}

function populateQuarantineFilterOptions(col) {
    const rows = document.querySelectorAll('tr.quarantine-data-row');
    const container = document.getElementById('filter-list-' + col);
    if (!container) return;

    const uniqueValues = new Set();
    rows.forEach(r => {
        const rawVal = r.getAttribute('data-filter-' + col) || '';
        if (col === 'cacat') {
            // Defect can be comma-separated
            rawVal.split(',').forEach(item => {
                const trimmed = item.trim();
                if (trimmed) uniqueValues.add(trimmed);
            });
        } else {
            const trimmed = rawVal.trim();
            if (trimmed) uniqueValues.add(trimmed);
        }
    });

    const sortedValues = Array.from(uniqueValues).sort((a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }));

    let html = '';
    sortedValues.forEach(val => {
        const isChecked = QuarantineExcelFilter.filters[col].size === 0 || QuarantineExcelFilter.filters[col].has(val);
        const safeVal = val.replace(/"/g, '&quot;');
        html += `
            <label class="excel-filter-item" data-val="${safeVal.toLowerCase()}">
                <input type="checkbox" value="${safeVal}" ${isChecked ? 'checked' : ''} onchange="onQuarantineCheckboxChange('${col}')">
                <span class="truncate" title="${safeVal}">${safeVal}</span>
            </label>
        `;
    });

    if (sortedValues.length === 0) {
        html = '<div class="text-[11px] text-slate-400 p-2 text-center italic">Tidak ada data</div>';
    }

    container.innerHTML = html;
}

function toggleQuarantineExcelFilter(event, col) {
    event.stopPropagation();
    const btn = event.currentTarget;
    const dropdown = document.getElementById('filter-dropdown-' + col);
    if (!dropdown || !btn) return;

    const isVisible = (dropdown.style.display === 'block');
    closeAllQuarantineExcelFilters();

    if (!isVisible) {
        // Pindahkan dropdown ke body jika belum, agar lepas dari overflow:hidden / scroll containers
        if (dropdown.parentElement !== document.body) {
            document.body.appendChild(dropdown);
        }

        dropdown.style.display = 'block';
        QuarantineExcelFilter.activeCol = col;

        // Hitung posisi tombol di viewport
        const rect = btn.getBoundingClientRect();
        const dropdownWidth = 250;
        let leftPos = rect.left;
        let topPos = rect.bottom + 6;

        // Cegah dropdown tembus ke kanan layar
        if (leftPos + dropdownWidth > window.innerWidth - 15) {
            leftPos = window.innerWidth - dropdownWidth - 15;
        }
        if (leftPos < 10) leftPos = 10;

        dropdown.style.left = leftPos + 'px';

        // Hitung ruang vertikal yang tersedia di bawah tombol
        const spaceBelow = window.innerHeight - rect.bottom - 20;
        const spaceAbove = rect.top - 20;

        // Jika ruang di bawah kurang dari 280px dan ruang di atas lebih luas, flip ke atas
        if (spaceBelow < 280 && spaceAbove > spaceBelow) {
            dropdown.style.maxHeight = Math.min(spaceAbove, 420) + 'px';
            dropdown.style.top = Math.max(10, rect.top - dropdown.offsetHeight - 6) + 'px';
        } else {
            dropdown.style.maxHeight = Math.min(spaceBelow, 450) + 'px';
            dropdown.style.top = topPos + 'px';
        }

        const searchInput = dropdown.querySelector('.excel-filter-search');
        if (searchInput) {
            searchInput.value = '';
            onSearchQuarantineFilterOptions(col, '');
            setTimeout(() => { searchInput.focus(); }, 50);
        }
    }
}

function closeAllQuarantineExcelFilters() {
    document.querySelectorAll('.excel-filter-dropdown').forEach(dd => {
        dd.style.display = 'none';
    });
    QuarantineExcelFilter.activeCol = null;
}

function onSearchQuarantineFilterOptions(col, keyword) {
    const container = document.getElementById('filter-list-' + col);
    if (!container) return;
    const kw = (keyword || '').toLowerCase().trim();
    const items = container.querySelectorAll('.excel-filter-item');
    items.forEach(item => {
        const val = item.getAttribute('data-val') || '';
        if (!kw || val.indexOf(kw) !== -1) {
            item.style.display = 'flex';
        } else {
            item.style.display = 'none';
        }
    });
}

function toggleSelectAllQuarantineFilter(col, selectAll) {
    const container = document.getElementById('filter-list-' + col);
    if (!container) return;
    const checkboxes = container.querySelectorAll('input[type="checkbox"]');
    checkboxes.forEach(cb => {
        const parentItem = cb.closest('.excel-filter-item');
        if (!parentItem || parentItem.style.display !== 'none') {
            cb.checked = selectAll;
        }
    });
}

function onQuarantineCheckboxChange(col) {
    // Optional live reaction
}

function applyQuarantineExcelFilters() {
    QuarantineExcelFilter.cols.forEach(col => {
        const container = document.getElementById('filter-list-' + col);
        if (!container) return;

        const checkboxes = container.querySelectorAll('input[type="checkbox"]');
        const allCount = checkboxes.length;
        const checkedValues = [];

        checkboxes.forEach(cb => {
            if (cb.checked) {
                checkedValues.push(cb.value);
            }
        });

        const btn = document.querySelector('.th-filter-btn[data-col="' + col + '"]');

        if (checkedValues.length === 0 || checkedValues.length === allCount) {
            QuarantineExcelFilter.filters[col].clear();
            if (btn) btn.classList.remove('active');
        } else {
            QuarantineExcelFilter.filters[col] = new Set(checkedValues);
            if (btn) btn.classList.add('active');
        }
    });

    closeAllQuarantineExcelFilters();
    executeQuarantineRowFiltering();
}

function resetSingleQuarantineFilter(col) {
    QuarantineExcelFilter.filters[col].clear();
    const btn = document.querySelector('.th-filter-btn[data-col="' + col + '"]');
    if (btn) btn.classList.remove('active');
    populateQuarantineFilterOptions(col);
    closeAllQuarantineExcelFilters();
    executeQuarantineRowFiltering();
}

function resetAllQuarantineExcelFilters() {
    QuarantineExcelFilter.cols.forEach(col => {
        QuarantineExcelFilter.filters[col].clear();
        const btn = document.querySelector('.th-filter-btn[data-col="' + col + '"]');
        if (btn) btn.classList.remove('active');
        populateQuarantineFilterOptions(col);
    });
    closeAllQuarantineExcelFilters();
    executeQuarantineRowFiltering();
}

function executeQuarantineRowFiltering() {
    const rows = document.querySelectorAll('tr.quarantine-data-row');
    if (!rows || rows.length === 0) return;

    const activeCols = QuarantineExcelFilter.cols.filter(c => QuarantineExcelFilter.filters[c].size > 0);
    const resetAllBtn = document.getElementById('btn-reset-excel-filters');
    if (resetAllBtn) {
        resetAllBtn.style.display = (activeCols.length > 0) ? 'inline-flex' : 'none';
    }

    let visibleCount = 0;

    rows.forEach(row => {
        let matchesAll = true;

        for (let i = 0; i < activeCols.length; i++) {
            const col = activeCols[i];
            const filterSet = QuarantineExcelFilter.filters[col];
            const rowVal = row.getAttribute('data-filter-' + col) || '';

            if (col === 'cacat') {
                const rowDefects = rowVal.split(',').map(s => s.trim());
                let hasMatch = false;
                for (let d = 0; d < rowDefects.length; d++) {
                    if (filterSet.has(rowDefects[d])) {
                        hasMatch = true;
                        break;
                    }
                }
                if (!hasMatch) {
                    matchesAll = false;
                    break;
                }
            } else {
                if (!filterSet.has(rowVal.trim())) {
                    matchesAll = false;
                    break;
                }
            }
        }

        if (matchesAll) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    const counter = document.getElementById('quarantine-row-counter');
    if (counter) {
        if (activeCols.length > 0) {
            counter.innerHTML = `Menampilkan <span class="text-rose-400 font-black">${visibleCount}</span> dari ${rows.length} Lot NG (Tersaring)`;
        } else {
            counter.innerText = `Menampilkan ${rows.length} dari <?= $totalFiltered ?> Lot NG`;
        }
    }
}

// Global click & esc listener to close dropdowns
document.addEventListener('click', function(e) {
    if (!e.target.closest('.excel-filter-dropdown') && !e.target.closest('.th-filter-btn')) {
        closeAllQuarantineExcelFilters();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeAllQuarantineExcelFilters();
    }
});

// Run filter initialization when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    initQuarantineExcelFilters();
});
</script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>

