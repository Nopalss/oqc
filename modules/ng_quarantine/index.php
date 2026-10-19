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
$statusHandling = sanitize($_GET['status_handling'] ?? 'all');
$search         = trim(sanitize($_GET['search'] ?? ''));

$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = max(10, (int)($_GET['limit'] ?? 15));

// Date filter clause
$todayStart = date('Y-m-d 00:00:00');
$todayEnd   = date('Y-m-d 23:59:59');

$wStart = date('Y-m-d 00:00:00', strtotime('monday this week'));
$wEnd   = date('Y-m-d 23:59:59', strtotime('sunday this week'));

$mStart = date('Y-m-01 00:00:00');
$mEnd   = date('Y-m-t 23:59:59');

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

// Period filter
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
$statsTotal = 0;
$statsPending = 0;
$statsDisposed = 0;
$statsInProgress = 0;
$statsResolvedPassed = 0;
$statsResolvedReplaced = 0;

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
    $sessSt    = strtolower($latestRow['session_status'] ?? '');

    // Check if any subsequent row or latest status is passed/replaced/closed
    if ($resStatus === 'passed' || $lotSt === 'passed') {
        $handlingCode  = 'resolved_passed';
        $handlingLabel = ($roundCount > 1) ? "LULUS RE-INSPEKSI (RONDE {$roundCount})" : 'LULUS INSPEKSI';
        $badgeClass    = 'bg-emerald-100 text-emerald-800 border-emerald-300';
        $rowClass      = 'font-medium';
        $rowStyle      = 'background-color: #f0fdf4; border-left: 4px solid #22c55e;';
        $statsResolvedPassed++;
    } elseif ($groupClosure !== null) {
        $handlingCode  = 'closed_external';
        $handlingLabel = 'DISPOSISI SELESAI (DIVISI)';
        $badgeClass    = 'bg-sky-100 text-sky-800 border-sky-300';
        $rowClass      = 'font-medium';
        $rowStyle      = 'background-color: #f0f9ff; border-left: 4px solid #0284c7;';
        $statsDisposed++;
    } elseif ($resStatus === 'rejected') {
        $handlingCode  = ($roundCount > 1) ? 'in_progress' : 'pending';
        $handlingLabel = ($roundCount > 1) ? "RE-INSPEKSI RONDE {$roundCount} (MASIH NG)" : 'BELUM DITANGANI (NG)';
        $badgeClass    = 'bg-rose-100 text-rose-800 border-rose-300';
        $rowClass      = 'font-medium';
        $rowStyle      = 'background-color: #fff1f2; border-left: 4px solid #f43f5e;';
        if ($roundCount > 1) {
            $statsInProgress++;
        } else {
            $statsPending++;
        }
    } else {
        $handlingCode  = 'pending';
        $handlingLabel = 'BELUM DITANGANI (PENDING)';
        $badgeClass    = 'bg-rose-100 text-rose-800 border-rose-300';
        $rowClass      = 'font-medium';
        $rowStyle      = 'background-color: #fff1f2; border-left: 4px solid #f43f5e;';
        $statsPending++;
    }

    $latestRow['handling_code']  = $handlingCode;
    $latestRow['handling_label'] = $handlingLabel;
    $latestRow['badge_class']    = $badgeClass;
    $latestRow['row_class']      = $rowClass;
    $latestRow['row_style']      = $rowStyle;

    // Filter status handling if specified
    if ($statusHandling !== 'all' && $handlingCode !== $statusHandling) {
        continue;
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
            <div class="flex items-center gap-2 self-start sm:self-auto">
                <a href="<?= base_url('modules/inspection/index.php') ?>" 
                   class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-extrabold text-xs rounded-xl border border-slate-300 transition-all inline-flex items-center gap-1.5 shadow-sm">
                    <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    <span>Ke Workboard Inspeksi</span>
                </a>
            </div>
        </div>

        <!-- 5 Top KPI Summary Cards (Aligned Horizontal Row with Colored Background Tints) -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem;" class="w-full">
            <!-- Total Lot NG -->
            <div style="background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 16px; padding: 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); display: flex; flex-direction: column; justify-content: space-between;">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <span style="font-size: 11px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Total Lot NG</span>
                    <div style="width: 36px; height: 36px; border-radius: 12px; background-color: #e2e8f0; display: flex; align-items: center; justify-content: center;">
                        <svg style="width: 20px; height: 20px; color: #334155;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                        </svg>
                    </div>
                </div>
                <div style="margin-top: 12px; display: flex; align-items: baseline; justify-content: space-between;">
                    <span style="font-size: 28px; font-weight: 900; color: #0f172a; line-height: 1;"><?= number_format($statsTotal) ?></span>
                    <span style="font-size: 11px; font-weight: 700; color: #64748b;">Record Terdeteksi</span>
                </div>
            </div>

            <!-- Belum Ditangani (Pending Urgent) -->
            <div style="background-color: #fff1f2; border: 1px solid #fecdd3; border-radius: 16px; padding: 18px; box-shadow: 0 1px 3px rgba(225,29,72,0.06); display: flex; flex-direction: column; justify-content: space-between;">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <span style="font-size: 11px; font-weight: 800; color: #be123c; text-transform: uppercase; letter-spacing: 0.5px;">Belum Ditangani</span>
                    <span style="padding: 2px 8px; border-radius: 9999px; font-size: 10px; font-weight: 900; background-color: #e11d48; color: #ffffff;">Urgent</span>
                </div>
                <div style="margin-top: 12px; display: flex; align-items: baseline; justify-content: space-between;">
                    <span style="font-size: 28px; font-weight: 900; color: #e11d48; line-height: 1;"><?= number_format($statsPending) ?></span>
                    <span style="font-size: 11px; font-weight: 700; color: #be123c;">Butuh Re-Inspeksi</span>
                </div>
            </div>

            <!-- Disposisi Selesai (Divisi Terkait) -->
            <div style="background-color: #f0f9ff; border: 1px solid #bae6fd; border-radius: 16px; padding: 18px; box-shadow: 0 1px 3px rgba(2,132,199,0.06); display: flex; flex-direction: column; justify-content: space-between;">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <span style="font-size: 11px; font-weight: 800; color: #0369a1; text-transform: uppercase; letter-spacing: 0.5px;">Disposisi Divisi</span>
                    <span style="padding: 2px 8px; border-radius: 9999px; font-size: 10px; font-weight: 900; background-color: #0284c7; color: #ffffff;">Memo OK</span>
                </div>
                <div style="margin-top: 12px; display: flex; align-items: baseline; justify-content: space-between;">
                    <span style="font-size: 28px; font-weight: 900; color: #0284c7; line-height: 1;"><?= number_format($statsDisposed) ?></span>
                    <span style="font-size: 11px; font-weight: 700; color: #0369a1;">Selesai Disortir</span>
                </div>
            </div>

            <!-- Dalam Proses Re-Inspeksi -->
            <div style="background-color: #fffbeb; border: 1px solid #fde68a; border-radius: 16px; padding: 18px; box-shadow: 0 1px 3px rgba(217,119,6,0.06); display: flex; flex-direction: column; justify-content: space-between;">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <span style="font-size: 11px; font-weight: 800; color: #b45309; text-transform: uppercase; letter-spacing: 0.5px;">Sedang Process</span>
                    <span style="padding: 2px 8px; border-radius: 9999px; font-size: 10px; font-weight: 900; background-color: #d97706; color: #ffffff;">Active</span>
                </div>
                <div style="margin-top: 12px; display: flex; align-items: baseline; justify-content: space-between;">
                    <span style="font-size: 28px; font-weight: 900; color: #d97706; line-height: 1;"><?= number_format($statsInProgress) ?></span>
                    <span style="font-size: 11px; font-weight: 700; color: #b45309;">Dalam Pengerjaan</span>
                </div>
            </div>

            <!-- Resolved / Passed -->
            <div style="background-color: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 16px; padding: 18px; box-shadow: 0 1px 3px rgba(22,163,74,0.06); display: flex; flex-direction: column; justify-content: space-between;">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <span style="font-size: 11px; font-weight: 800; color: #15803d; text-transform: uppercase; letter-spacing: 0.5px;">Resolved / Passed</span>
                    <span style="padding: 2px 8px; border-radius: 9999px; font-size: 10px; font-weight: 900; background-color: #16a34a; color: #ffffff;">Complete</span>
                </div>
                <div style="margin-top: 12px; display: flex; align-items: baseline; justify-content: space-between;">
                    <span style="font-size: 28px; font-weight: 900; color: #15803d; line-height: 1;"><?= number_format($statsResolvedPassed) ?></span>
                    <span style="font-size: 11px; font-weight: 700; color: #15803d;">Lulus Re-Inspeksi</span>
                </div>
            </div>
        </div>

        <!-- Filter & Search Bar Section (Clean Light Slate Background Card) -->
        <div style="background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 16px; padding: 14px 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <form method="GET" action="" style="display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px;" class="w-full">
                
                <!-- Period Filter -->
                <div style="flex: 1 1 180px; min-width: 140px;">
                    <label style="display: block; font-size: 11px; font-weight: 800; color: #475569; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;">Periode Waktu</label>
                    <select name="period" onchange="this.form.submit()" style="width: 100%; padding: 8px 12px; background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 12px; font-weight: 700; color: #1e293b; outline: none;">
                        <option value="all" <?= $period === 'all' ? 'selected' : '' ?>>Semua Waktu</option>
                        <option value="today" <?= $period === 'today' ? 'selected' : '' ?>>Hari Ini</option>
                        <option value="this_week" <?= $period === 'this_week' ? 'selected' : '' ?>>Minggu Ini</option>
                        <option value="this_month" <?= $period === 'this_month' ? 'selected' : '' ?>>Bulan Ini</option>
                        <option value="custom" <?= $period === 'custom' ? 'selected' : '' ?>>Custom Range</option>
                    </select>
                </div>

                <!-- Status Handling Filter -->
                <div style="flex: 1 1 200px; min-width: 160px;">
                    <label style="display: block; font-size: 11px; font-weight: 800; color: #475569; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;">Status Penanganan</label>
                    <select name="status_handling" onchange="this.form.submit()" style="width: 100%; padding: 8px 12px; background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 12px; font-weight: 700; color: #1e293b; outline: none;">
                        <option value="all" <?= $statusHandling === 'all' ? 'selected' : '' ?>>Semua Status</option>
                        <option value="pending" <?= $statusHandling === 'pending' ? 'selected' : '' ?>>Belum Ditangani (Pending)</option>
                        <option value="closed_external" <?= $statusHandling === 'closed_external' ? 'selected' : '' ?>>Disposisi Selesai (Divisi)</option>
                        <option value="in_progress" <?= $statusHandling === 'in_progress' ? 'selected' : '' ?>>Sedang Re-Inspeksi</option>
                        <option value="resolved_passed" <?= $statusHandling === 'resolved_passed' ? 'selected' : '' ?>>Re-Inspeksi Passed</option>
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
                        <span>Filter Data</span>
                    </button>
                    <a href="<?= base_url('modules/ng_quarantine/index.php') ?>" style="background-color: #ffffff; color: #475569; border: 1px solid #cbd5e1; padding: 8px 12px; border-radius: 10px; font-size: 12px; font-weight: 800; text-decoration: none; display: inline-flex; align-items: center;" title="Reset Filter">
                        <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                    </a>
                </div>

                <!-- Custom Range Date Pickers (If Selected) -->
                <?php if ($period === 'custom'): ?>
                    <div style="flex: 1 1 100%; display: flex; gap: 12px; margin-top: 6px; padding-top: 10px; border-top: 1px dashed #cbd5e1;">
                        <div style="flex: 1;">
                            <label style="display: block; font-size: 10px; font-weight: 800; color: #64748b; margin-bottom: 2px;">Dari Tanggal:</label>
                            <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" style="width: 100%; padding: 6px 10px; background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 11px; font-weight: 700;">
                        </div>
                        <div style="flex: 1;">
                            <label style="display: block; font-size: 10px; font-weight: 800; color: #64748b; margin-bottom: 2px;">Sampai Tanggal:</label>
                            <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" style="width: 100%; padding: 6px 10px; background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 11px; font-weight: 700;">
                        </div>
                    </div>
                <?php endif; ?>

            </form>
        </div>

        <!-- Main Data Table Container (Native Horizontal Touch Scroll) -->
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-5 py-4 bg-slate-900 text-white flex items-center justify-between">
                <div class="font-extrabold text-xs tracking-wide flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full bg-rose-500 animate-ping"></span>
                    <span>DAFTAR DOKUMEN LOT NG & STATUS KARANTINA</span>
                </div>
                <span class="text-[11px] font-bold bg-slate-800 px-3 py-1 rounded-full text-slate-300 border border-slate-700">
                    Menampilkan <?= count($ngLots) ?> dari <?= $totalFiltered ?> Lot NG
                </span>
            </div>

            <div class="overflow-x-auto w-full custom-scrollbar" style="width: 100%; max-width: 100%; -webkit-overflow-scrolling: touch;">
                <table class="w-full text-left text-xs text-slate-700 table-auto" style="width: 100%; min-width: 1550px; border-collapse: collapse;">
                    <thead class="bg-slate-100 text-slate-700 font-extrabold text-[11px] uppercase tracking-wider select-none border-b border-slate-200">
                        <tr>
                            <th style="padding: 11px 12px; width: 45px; text-align: center;">#</th>
                            <th style="padding: 11px 12px; text-align: center; width: 140px;">WAKTU TEMUAN</th>
                            <th style="padding: 11px 12px; width: 130px;">PART CODE</th>
                            <th style="padding: 11px 12px; min-width: 160px;">PART NAME</th>
                            <th style="padding: 11px 12px; text-align: center; width: 95px;">MODEL</th>
                            <th style="padding: 11px 12px; width: 125px;">LOT NO</th>
                            <th style="padding: 11px 12px; width: 115px;">REF NO</th>
                            <th style="padding: 11px 12px; text-align: right; width: 95px;">QTY LOT</th>
                            <th style="padding: 11px 12px; text-align: center; width: 70px;">NG</th>
                            <th style="padding: 11px 12px; min-width: 190px;">TEMUAN CACAT</th>
                            <th style="padding: 11px 12px; width: 130px;">INSPEKTUR AWAL</th>
                            <th style="padding: 11px 12px; text-align: center; width: 180px;">STATUS PENANGANAN</th>
                            <th style="padding: 11px 12px; text-align: center; width: 150px;">AKSI</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-[11px]">
                        <?php if (empty($ngLots)): ?>
                            <tr>
                                <td colspan="13" class="py-14 text-center text-slate-400 bg-white">
                                    <div class="flex flex-col items-center justify-center gap-2">
                                        <svg class="w-12 h-12 text-slate-300 stroke-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        <span class="font-bold text-slate-700 text-sm">Tidak ada record Lot NG yang ditemukan</span>
                                        <span class="text-xs text-slate-400 max-w-sm">Semua lot dalam kondisi PASSED atau ubah filter pencarian di atas.</span>
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
                            ?>
                                <tr style="<?= $rLot['row_style'] ?? '' ?>" class="<?= $rLot['row_class'] ?? 'font-medium' ?> transition-colors">
                                    <!-- # Index -->
                                    <td style="padding: 10px 12px; text-align: center; font-weight: 700; color: #94a3b8;"><?= $rowNum ?></td>

                                    <!-- Waktu Temuan -->
                                    <td style="padding: 10px 12px; text-align: center; font-family: monospace; white-space: nowrap;">
                                        <div style="font-weight: 800; color: #0f172a;"><?= $dateDisplay ?></div>
                                        <div style="font-size: 9.5px; color: #64748b; font-weight: 600; font-family: sans-serif; margin-top: 1px;"><?= $timeDisplay ?></div>
                                        <div style="margin-top: 2px;">
                                            <span class="inline-block px-1.5 py-0.5 rounded text-[9.5px] font-bold bg-slate-100 text-slate-600 border border-slate-200">
                                                <?= htmlspecialchars($shiftName) ?>
                                            </span>
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

                                    <!-- Status Penanganan -->
                                    <td style="padding: 10px 12px; text-align: center; white-space: nowrap;">
                                        <span class="px-2.5 py-1 rounded-full text-[10.5px] font-black border inline-flex items-center gap-1 <?= $rLot['badge_class'] ?>">
                                            <?= $rLot['handling_label'] ?>
                                        </span>
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
                                            <?php if ($rLot['handling_code'] !== 'resolved_passed'): ?>
                                                <!-- Tombol Disposisi / Tutup Karantina Divisi -->
                                                <button type="button" 
                                                        onclick="openDispositionModal(<?= (int)$rLot['lot_id'] ?>, '<?= htmlspecialchars(addslashes($rLot['lot_number'] ?: '-')) ?>', '<?= htmlspecialchars(addslashes($rLot['part_code'] . ' - ' . $rLot['part_name'])) ?>', '<?= htmlspecialchars(addslashes($rLot['closure_info']['notes'] ?? '')) ?>')"
                                                        style="background-color: #0284c7; color: #ffffff; padding: 6px 10px; border-radius: 8px; font-weight: 800; font-size: 11px; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 2px rgba(2,132,199,0.2);" 
                                                        title="<?= $rLot['closure_info'] ? 'Update Catatan Disposisi' : 'Catat Penutupan Karantina dari Divisi Lain' ?>">
                                                    <svg style="width: 13px; height: 13px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                    </svg>
                                                    <span><?= $rLot['closure_info'] ? 'Edit Disposisi' : 'Disposisi' ?></span>
                                                </button>

                                                <a href="<?= base_url('modules/inspection/session.php?scan_new=1&plan_type=safety_stock&part_code=' . urlencode($rLot['part_code']) . '&lot_number=' . urlencode($rLot['lot_number']) . '&ref_number=' . urlencode($rLot['ref_number'])) ?>" 
                                                   style="background-color: #7c3aed; color: #ffffff; padding: 6px 10px; border-radius: 8px; font-weight: 800; font-size: 11px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 2px rgba(124,58,237,0.2);" 
                                                   title="Mulai Re-inspeksi Safety Stock Workbench">
                                                    <svg style="width: 13px; height: 13px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                                    </svg>
                                                    <span>+ Re-Inspek</span>
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($rLot['handling_code'] !== 'resolved_passed'): ?>
                                                <a href="<?= base_url('modules/inspection/print_rejection.php?id=' . ($rLot['rejected_session_id'] ?? $rLot['inspection_session_id']) . '&session_lot_id=' . ($rLot['rejected_lot_id'] ?? $rLot['lot_id'])) ?>" 
                                                   target="_blank"
                                                   style="background-color: #d97706; color: #ffffff; padding: 6px 10px; border-radius: 8px; font-weight: 800; font-size: 11px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 2px rgba(217,119,6,0.2);" 
                                                   title="Cetak Rejection Sheet">
                                                    <svg style="width: 13px; height: 13px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                                                    </svg>
                                                    <span>Cetak</span>
                                                </a>
                                            <?php endif; ?>
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
function openDispositionModal(lotId, lotNumber, partInfo, existingNotes) {
    Swal.fire({
        title: '<div class="text-left font-black text-slate-800 text-base flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-sky-500"></span>Disposisi & Penutupan Karantina</div>',
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
                <div class="p-2.5 bg-sky-50 border border-sky-200 rounded-xl text-[11px] text-sky-800 font-medium">
                    Gunakan fitur ini jika barang NG telah selesai disortir/diperbaiki oleh divisi terkait (produksi/gudang) berdasarkan memo atau laporan verbal.
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Catatan Penutupan / Hasil Sortir Divisi <span class="text-rose-500">*</span></label>
                    <textarea id="swal-disposition-notes" rows="4" class="w-full p-2.5 bg-white border border-slate-300 rounded-xl text-xs text-slate-800 focus:ring-2 focus:ring-sky-500 focus:outline-none" placeholder="Contoh: Telah disortir oleh tim Produksi, 50 pcs OK dan disimpan di Buffer Rack A-02 sesuai Memo #123...">${existingNotes || ''}</textarea>
                </div>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Simpan Disposisi',
        cancelButtonText: 'Batal',
        confirmButtonColor: '#0284c7',
        cancelButtonColor: '#64748b',
        focusConfirm: false,
        preConfirm: () => {
            const notes = document.getElementById('swal-disposition-notes').value.trim();
            if (!notes) {
                Swal.showValidationMessage('Catatan penanganan wajib diisi!');
                return false;
            }
            return { lotId, notes };
        }
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Menyimpan...',
                text: 'Mencatat penutupan karantina ke database',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            const formData = new FormData();
            formData.append('lot_id', result.value.lotId);
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
</script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
