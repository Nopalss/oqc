<?php
$breadcrumbCategory = "OPERASIONAL";
$pageTitle          = "Dashboard Laporan";
$pageSubtitle       = "Rekap hasil inspeksi outgoing quality control — PT. Surya Technology Industri";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

$pdo = getDB();

// ── Filter Resolvers (Date, Customer, Unit, & Result) ────────────────────────
$selectedCustomer = sanitize($_GET['customer'] ?? '');
$selectedUnit     = sanitize($_GET['unit'] ?? 'pcs');
if (!in_array($selectedUnit, ['pcs', 'lot'])) {
    $selectedUnit = 'pcs';
}

$selectedResult = sanitize($_GET['result'] ?? 'rejected');
if (!in_array($selectedResult, ['rejected', 'passed'])) {
    $selectedResult = 'rejected';
}

if (!empty($_GET['start_date']) && !empty($_GET['end_date'])) {
    $startDate    = sanitize($_GET['start_date']);
    $endDate      = sanitize($_GET['end_date']);
    $presetFilter = 'custom';
} else {
    $presetFilter = sanitize($_GET['preset'] ?? 'bulanan');
    switch ($presetFilter) {
        case 'hari_ini':
            $startDate = date('Y-m-d');
            $endDate   = date('Y-m-d');
            break;
        case 'mingguan':
            $startDate = date('Y-m-d', strtotime('monday this week'));
            $endDate   = date('Y-m-d');
            break;
        case 'tahunan':
            $startDate = date('Y-01-01');
            $endDate   = date('Y-m-d');
            break;
        default:
            $presetFilter = 'bulanan';
            $startDate    = date('Y-m-01');
            $endDate      = date('Y-m-d');
    }
}

// ── Fetch Master Customers List for Filter Dropdown ────────────────────────
$customerOptions = [];
if ($pdo) {
    try {
        $stmtCust = $pdo->query("SELECT DISTINCT name FROM master_customers UNION SELECT DISTINCT customer FROM kanban_items WHERE customer IS NOT NULL AND customer != '' ORDER BY name ASC");
        $customerOptions = array_filter(array_map('trim', $stmtCust->fetchAll(PDO::FETCH_COLUMN)));
    } catch (Exception $e) {}
}

// ── Init metric variables ──────────────────────────────────────────────────
$kpi = ['total_inspected' => 0, 'pass_rate' => 0, 'total_ng' => 0, 'total_lot' => 0,
        'pass_count' => 0, 'rejected_count' => 0];
$trendLabels       = [];
$trendNG           = [];
$defectLabels      = [];
$defectCounts      = [];
$top5Parts         = [];
$pieLabels         = [];
$pieCounts         = [];
$modelLabels       = [];
$modelCounts       = [];
$worstPartsGrouped = [];

$STD_PALETTE    = ['#2563eb','#0ea5e9','#6366f1','#f59e0b','#10b981','#ef4444','#8b5cf6','#ec4899'];
$PASS_PALETTE   = ['#059669','#10b981','#34d399','#0284c7','#3b82f6','#6366f1','#8b5cf6','#06b6d4'];
$PALETTE        = ($selectedResult === 'passed') ? $PASS_PALETTE : $STD_PALETTE;

if ($pdo) {
    try {
        $p = [':sd' => $startDate, ':ed' => $endDate];
        $custJoin = "";
        $custCond = "";

        if ($selectedCustomer !== '') {
            $custJoin = " LEFT JOIN kanban_items ki ON ss.kanban_item_id = ki.id ";
            $custCond = " AND ki.customer = :cust ";
            $p[':cust'] = $selectedCustomer;
        }

        // 1. Total Inspected Samples
        $stmt = $pdo->prepare("SELECT COUNT(s.id) FROM inspection_samples s INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id {$custJoin} WHERE DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond}");
        $stmt->execute($p); $kpi['total_inspected'] = (int)$stmt->fetchColumn();

        // 2. Total Lot Inspection Sessions
        $stmt = $pdo->prepare("SELECT COUNT(*) AS tl, SUM(ss.status='passed') AS pc, SUM(ss.status='rejected') AS rc FROM inspection_sessions ss {$custJoin} WHERE DATE(ss.started_at) BETWEEN :sd AND :ed {$custCond}");
        $stmt->execute($p); $row = $stmt->fetch();
        $kpi['total_lot']      = (int)($row['tl'] ?? 0);
        $kpi['pass_count']     = (int)($row['pc'] ?? 0);
        $kpi['rejected_count'] = (int)($row['rc'] ?? 0);
        $kpi['pass_rate']      = $kpi['total_lot'] > 0 ? round($kpi['pass_count'] / $kpi['total_lot'] * 100, 1) : 0;

        // 3. Total NG Pcs
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(ngr.qty_ng),0) FROM inspection_ng_records ngr INNER JOIN inspection_samples s ON ngr.inspection_sample_id=s.id INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id {$custJoin} WHERE DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond}");
        $stmt->execute($p); $kpi['total_ng'] = (int)$stmt->fetchColumn();

        // 4. Dynamic Trend (Pcs vs Lot & Passed vs Rejected Mode)
        $daysDiff = (strtotime($endDate) - strtotime($startDate)) / 86400;

        if ($presetFilter === 'tahunan' || $daysDiff > 90) {
            $trendSubtext = 'per bulan';
            $trendMap     = [];

            $startPeriod  = new DateTime(date('Y-m-01', strtotime($startDate)));
            $endPeriod    = new DateTime(date('Y-m-01', strtotime($endDate)));
            $endPeriod->modify('+1 month');
            $interval     = DateInterval::createFromDateString('1 month');
            $period       = new DatePeriod($startPeriod, $interval, $endPeriod);

            foreach ($period as $dt) {
                $key = $dt->format('Y-m');
                $lbl = $dt->format('M Y');
                $trendMap[$key] = ['label' => $lbl, 'ng' => 0];
            }

            if ($selectedResult === 'passed') {
                if ($selectedUnit === 'lot') {
                    $stmtT = $pdo->prepare("SELECT DATE_FORMAT(ss.started_at, '%Y-%m') AS month_key, 
                                                   COUNT(DISTINCT CASE WHEN ss.status = 'passed' THEN ss.id END) AS ng 
                                            FROM inspection_sessions ss 
                                            {$custJoin} 
                                            WHERE DATE(ss.started_at) BETWEEN :sd AND :ed {$custCond} 
                                            GROUP BY month_key 
                                            ORDER BY month_key ASC");
                } else {
                    $stmtT = $pdo->prepare("SELECT DATE_FORMAT(s.checked_at, '%Y-%m') AS month_key, 
                                                   COUNT(s.id) AS ng 
                                            FROM inspection_samples s 
                                            INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id 
                                            {$custJoin} 
                                            WHERE ss.status = 'passed' AND DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond} 
                                            GROUP BY month_key 
                                            ORDER BY month_key ASC");
                }
            } else {
                if ($selectedUnit === 'lot') {
                    $stmtT = $pdo->prepare("SELECT DATE_FORMAT(ss.started_at, '%Y-%m') AS month_key, 
                                                   COUNT(DISTINCT CASE WHEN ss.status = 'rejected' THEN ss.id END) AS ng 
                                            FROM inspection_sessions ss 
                                            {$custJoin} 
                                            WHERE DATE(ss.started_at) BETWEEN :sd AND :ed {$custCond} 
                                            GROUP BY month_key 
                                            ORDER BY month_key ASC");
                } else {
                    $stmtT = $pdo->prepare("SELECT DATE_FORMAT(s.checked_at, '%Y-%m') AS month_key, COALESCE(SUM(ngr.qty_ng),0) AS ng 
                                            FROM inspection_samples s 
                                            INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id 
                                            {$custJoin}
                                            LEFT JOIN inspection_ng_records ngr ON ngr.inspection_sample_id=s.id 
                                            WHERE DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond} 
                                            GROUP BY month_key 
                                            ORDER BY month_key ASC");
                }
            }
            $stmtT->execute($p);
            foreach ($stmtT->fetchAll() as $r) {
                if (isset($trendMap[$r['month_key']])) {
                    $trendMap[$r['month_key']]['ng'] = (int)$r['ng'];
                }
            }

            foreach ($trendMap as $item) {
                $trendLabels[] = $item['label'];
                $trendNG[]     = $item['ng'];
            }
        } else {
            $trendSubtext = 'per tanggal';
            $trendMap     = [];

            $startPeriod  = new DateTime($startDate);
            $endPeriod    = new DateTime($endDate);
            $endPeriod->modify('+1 day');
            $interval     = DateInterval::createFromDateString('1 day');
            $period       = new DatePeriod($startPeriod, $interval, $endPeriod);

            foreach ($period as $dt) {
                $key = $dt->format('Y-m-d');
                $lbl = $dt->format('d M');
                $trendMap[$key] = ['label' => $lbl, 'ng' => 0];
            }

            if ($selectedResult === 'passed') {
                if ($selectedUnit === 'lot') {
                    $stmtT = $pdo->prepare("SELECT DATE(ss.started_at) AS tgl, 
                                                   COUNT(DISTINCT CASE WHEN ss.status = 'passed' THEN ss.id END) AS ng 
                                            FROM inspection_sessions ss 
                                            {$custJoin} 
                                            WHERE DATE(ss.started_at) BETWEEN :sd AND :ed {$custCond} 
                                            GROUP BY DATE(ss.started_at) 
                                            ORDER BY tgl ASC");
                } else {
                    $stmtT = $pdo->prepare("SELECT DATE(s.checked_at) AS tgl, 
                                                   COUNT(s.id) AS ng 
                                            FROM inspection_samples s 
                                            INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id 
                                            {$custJoin} 
                                            WHERE ss.status = 'passed' AND DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond} 
                                            GROUP BY DATE(s.checked_at) 
                                            ORDER BY tgl ASC");
                }
            } else {
                if ($selectedUnit === 'lot') {
                    $stmtT = $pdo->prepare("SELECT DATE(ss.started_at) AS tgl, 
                                                   COUNT(DISTINCT CASE WHEN ss.status = 'rejected' THEN ss.id END) AS ng 
                                            FROM inspection_sessions ss 
                                            {$custJoin} 
                                            WHERE DATE(ss.started_at) BETWEEN :sd AND :ed {$custCond} 
                                            GROUP BY DATE(ss.started_at) 
                                            ORDER BY tgl ASC");
                } else {
                    $stmtT = $pdo->prepare("SELECT DATE(s.checked_at) AS tgl, COALESCE(SUM(ngr.qty_ng),0) AS ng 
                                            FROM inspection_samples s 
                                            INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id 
                                            {$custJoin}
                                            LEFT JOIN inspection_ng_records ngr ON ngr.inspection_sample_id=s.id 
                                            WHERE DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond} 
                                            GROUP BY DATE(s.checked_at) 
                                            ORDER BY tgl ASC");
                }
            }
            $stmtT->execute($p);
            foreach ($stmtT->fetchAll() as $r) {
                if (isset($trendMap[$r['tgl']])) {
                    $trendMap[$r['tgl']]['ng'] = (int)$r['ng'];
                }
            }

            foreach ($trendMap as $item) {
                $trendLabels[] = $item['label'];
                $trendNG[]     = $item['ng'];
            }
        }

        // 5. Defect Types Breakdown (or Overall Yield Ratio in PASSED mode)
        if ($selectedResult === 'passed') {
            if ($selectedUnit === 'lot') {
                $defectLabels = ['Passed (Lulus)', 'Rejected (Ditolak)'];
                $defectCounts = [$kpi['pass_count'], $kpi['rejected_count']];
            } else {
                $passedPcs   = max(0, $kpi['total_inspected'] - $kpi['total_ng']);
                $rejectedPcs = $kpi['total_ng'];
                $defectLabels = ['Passed (Lulus)', 'Rejected (Ditolak)'];
                $defectCounts = [$passedPcs, $rejectedPcs];
            }
        } else {
            if ($selectedUnit === 'lot') {
                $stmt = $pdo->prepare("SELECT dt.name, COUNT(DISTINCT s.inspection_session_id) AS total 
                                        FROM inspection_ng_records ngr 
                                        INNER JOIN defect_types dt ON ngr.defect_type_id=dt.id 
                                        INNER JOIN inspection_samples s ON ngr.inspection_sample_id=s.id 
                                        INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id 
                                        {$custJoin} 
                                        WHERE DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond} 
                                        GROUP BY dt.id,dt.name 
                                        ORDER BY total DESC");
            } else {
                $stmt = $pdo->prepare("SELECT dt.name, COALESCE(SUM(ngr.qty_ng),0) AS total 
                                        FROM inspection_ng_records ngr 
                                        INNER JOIN defect_types dt ON ngr.defect_type_id=dt.id 
                                        INNER JOIN inspection_samples s ON ngr.inspection_sample_id=s.id 
                                        INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id 
                                        {$custJoin} 
                                        WHERE DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond} 
                                        GROUP BY dt.id,dt.name 
                                        ORDER BY total DESC");
            }
            $stmt->execute($p);
            foreach ($stmt->fetchAll() as $r) { $defectLabels[] = $r['name']; $defectCounts[] = (int)$r['total']; }
        }

        // 6. Top 5 Part (Passed vs Rejected Mode)
        if ($selectedResult === 'passed') {
            if ($selectedUnit === 'lot') {
                $stmt = $pdo->prepare("SELECT mp.part_code, mp.part_name, COUNT(DISTINCT CASE WHEN ss.status = 'passed' THEN ss.id END) AS total_ng 
                                        FROM inspection_sessions ss 
                                        LEFT JOIN master_parts mp ON ss.part_id=mp.id 
                                        {$custJoin} 
                                        WHERE DATE(ss.started_at) BETWEEN :sd AND :ed {$custCond} 
                                        GROUP BY ss.part_id,mp.part_code,mp.part_name 
                                        ORDER BY total_ng DESC 
                                        LIMIT 5");
            } else {
                $stmt = $pdo->prepare("SELECT mp.part_code, mp.part_name, COUNT(s.id) AS total_ng 
                                        FROM inspection_samples s 
                                        INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id 
                                        LEFT JOIN master_parts mp ON ss.part_id=mp.id 
                                        {$custJoin} 
                                        WHERE ss.status = 'passed' AND DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond} 
                                        GROUP BY ss.part_id,mp.part_code,mp.part_name 
                                        ORDER BY total_ng DESC 
                                        LIMIT 5");
            }
        } else {
            if ($selectedUnit === 'lot') {
                $stmt = $pdo->prepare("SELECT mp.part_code, mp.part_name, COUNT(DISTINCT CASE WHEN ss.status = 'rejected' THEN ss.id END) AS total_ng 
                                        FROM inspection_ng_records ngr 
                                        INNER JOIN inspection_samples s ON ngr.inspection_sample_id=s.id 
                                        INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id 
                                        LEFT JOIN master_parts mp ON ss.part_id=mp.id 
                                        {$custJoin} 
                                        WHERE DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond} 
                                        GROUP BY ss.part_id,mp.part_code,mp.part_name 
                                        ORDER BY total_ng DESC 
                                        LIMIT 5");
            } else {
                $stmt = $pdo->prepare("SELECT mp.part_code, mp.part_name, COALESCE(SUM(ngr.qty_ng),0) AS total_ng 
                                        FROM inspection_ng_records ngr 
                                        INNER JOIN inspection_samples s ON ngr.inspection_sample_id=s.id 
                                        INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id 
                                        LEFT JOIN master_parts mp ON ss.part_id=mp.id 
                                        {$custJoin} 
                                        WHERE DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond} 
                                        GROUP BY ss.part_id,mp.part_code,mp.part_name 
                                        ORDER BY total_ng DESC 
                                        LIMIT 5");
            }
        }
        $stmt->execute($p); $top5Parts = $stmt->fetchAll();
        foreach ($top5Parts as $r) { $pieLabels[] = htmlspecialchars($r['part_name'] ?? $r['part_code'] ?? 'Unknown'); $pieCounts[] = (int)$r['total_ng']; }

        // 6b. Distribusi per Model (Passed vs Rejected Mode)
        if ($selectedResult === 'passed') {
            if ($selectedUnit === 'lot') {
                $stmtM = $pdo->prepare("SELECT COALESCE(m.name, mp.model, 'NO MODEL') AS model_name, 
                                               COUNT(DISTINCT CASE WHEN ss.status = 'passed' THEN ss.id END) AS total_ng 
                                        FROM inspection_sessions ss 
                                        LEFT JOIN master_parts mp ON ss.part_id=mp.id 
                                        LEFT JOIN master_models m ON mp.model_id=m.id 
                                        {$custJoin} 
                                        WHERE DATE(ss.started_at) BETWEEN :sd AND :ed {$custCond} 
                                        GROUP BY m.id, model_name 
                                        ORDER BY total_ng DESC");
            } else {
                $stmtM = $pdo->prepare("SELECT COALESCE(m.name, mp.model, 'NO MODEL') AS model_name, 
                                               COUNT(s.id) AS total_ng 
                                        FROM inspection_samples s 
                                        INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id 
                                        LEFT JOIN master_parts mp ON ss.part_id=mp.id 
                                        LEFT JOIN master_models m ON mp.model_id=m.id 
                                        {$custJoin} 
                                        WHERE ss.status = 'passed' AND DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond} 
                                        GROUP BY m.id, model_name 
                                        ORDER BY total_ng DESC");
            }
        } else {
            if ($selectedUnit === 'lot') {
                $stmtM = $pdo->prepare("SELECT COALESCE(m.name, mp.model, 'NO MODEL') AS model_name, 
                                               COUNT(DISTINCT CASE WHEN ss.status = 'rejected' THEN ss.id END) AS total_ng 
                                        FROM inspection_ng_records ngr 
                                        INNER JOIN inspection_samples s ON ngr.inspection_sample_id=s.id 
                                        INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id 
                                        LEFT JOIN master_parts mp ON ss.part_id=mp.id 
                                        LEFT JOIN master_models m ON mp.model_id=m.id 
                                        {$custJoin} 
                                        WHERE DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond} 
                                        GROUP BY m.id, model_name 
                                        ORDER BY total_ng DESC");
            } else {
                $stmtM = $pdo->prepare("SELECT COALESCE(m.name, mp.model, 'NO MODEL') AS model_name, 
                                               COALESCE(SUM(ngr.qty_ng),0) AS total_ng 
                                        FROM inspection_ng_records ngr 
                                        INNER JOIN inspection_samples s ON ngr.inspection_sample_id=s.id 
                                        INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id 
                                        LEFT JOIN master_parts mp ON ss.part_id=mp.id 
                                        LEFT JOIN master_models m ON mp.model_id=m.id 
                                        {$custJoin} 
                                        WHERE DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond} 
                                        GROUP BY m.id, model_name 
                                        ORDER BY total_ng DESC");
            }
        }
        $stmtM->execute($p);
        foreach ($stmtM->fetchAll() as $rM) {
            $modelLabels[] = htmlspecialchars($rM['model_name']);
            $modelCounts[] = (int)$rM['total_ng'];
        }

        // 7. Section II Table: Worst Parts (Rejected) vs Best Performance Parts (Passed)
        if ($selectedResult === 'passed') {
            $stmtBest = $pdo->prepare("SELECT 
                        COALESCE(mp.part_name, 'UNKNOWN PART') AS part_name,
                        COALESCE(mp.part_code, '-') AS part_code,
                        COALESCE(m.name, mp.model, '-') AS model,
                        COUNT(DISTINCT ss.id) AS lot_case,
                        COUNT(DISTINCT CASE WHEN ss.status = 'passed' THEN ss.id END) AS passed_lot_case
                    FROM inspection_sessions ss
                    LEFT JOIN master_parts mp ON ss.part_id = mp.id
                    LEFT JOIN master_models m ON mp.model_id = m.id
                    {$custJoin}
                    WHERE DATE(ss.started_at) BETWEEN :sd AND :ed {$custCond}
                    GROUP BY mp.id, mp.part_name, mp.part_code, m.name, mp.model
                    HAVING passed_lot_case > 0
                    ORDER BY passed_lot_case DESC, lot_case DESC
                    LIMIT 5");
            $stmtBest->execute($p);
            $bestPartsPassed = $stmtBest->fetchAll();

            $top3BestNames = [];
            foreach ($bestPartsPassed as $bP) {
                if (!empty($bP['part_name'])) {
                    $top3BestNames[] = htmlspecialchars($bP['part_name']);
                    if (count($top3BestNames) >= 3) break;
                }
            }
            $top3PartsSummaryStr = !empty($top3BestNames) ? implode(', ', $top3BestNames) : 'Belum Ada Data Sesi Passed';
        } else {
            $stmtWPDefs = $pdo->prepare("SELECT dt.name 
                                         FROM inspection_ng_records ngr 
                                         INNER JOIN defect_types dt ON ngr.defect_type_id=dt.id 
                                         INNER JOIN inspection_samples s ON ngr.inspection_sample_id=s.id 
                                         INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id 
                                         {$custJoin} 
                                         WHERE DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond} 
                                         GROUP BY dt.id, dt.name 
                                         ORDER BY COALESCE(SUM(ngr.qty_ng),0) DESC 
                                         LIMIT 3");
            $stmtWPDefs->execute($p);
            $top3DefectNames = $stmtWPDefs->fetchAll(PDO::FETCH_COLUMN);

            foreach ($top3DefectNames as $defName) {
                $pDef = array_merge($p, [':defname' => $defName]);
                $stmtWP = $pdo->prepare("SELECT 
                            COALESCE(mp.part_name, 'UNKNOWN PART') AS part_name,
                            COALESCE(mp.part_code, '-') AS part_code,
                            COALESCE(m.name, mp.model, '-') AS model,
                            COUNT(DISTINCT ss.id) AS lot_case,
                            COUNT(DISTINCT CASE WHEN ss.status = 'rejected' THEN ss.id END) AS ng_case
                        FROM inspection_ng_records ngr
                        INNER JOIN defect_types dt ON ngr.defect_type_id = dt.id
                        INNER JOIN inspection_samples s ON ngr.inspection_sample_id = s.id
                        INNER JOIN inspection_sessions ss ON s.inspection_session_id = ss.id
                        LEFT JOIN master_parts mp ON ss.part_id = mp.id
                        LEFT JOIN master_models m ON mp.model_id = m.id
                        {$custJoin}
                        WHERE dt.name = :defname
                          AND DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond}
                        GROUP BY mp.id, mp.part_name, mp.part_code, m.name, mp.model
                        ORDER BY ng_case DESC, lot_case DESC
                        LIMIT 3");
                $stmtWP->execute($pDef);
                $worstPartsGrouped[$defName] = $stmtWP->fetchAll();
            }

            $top3PartsNames = [];
            foreach ($worstPartsGrouped as $dName => $parts) {
                if (!empty($parts[0]['part_name'])) {
                    $top3PartsNames[] = htmlspecialchars($parts[0]['part_name']) . " (" . strtolower($dName) . ")";
                }
            }
            $top3PartsSummaryStr = !empty($top3PartsNames) ? implode(', ', $top3PartsNames) : 'Belum Ada Part Reject';
        }

    } catch (PDOException $e) {}
}

$jsLabels       = json_encode($trendLabels);
$jsTrendNG      = json_encode($trendNG);
$jsDefectLabels = json_encode($defectLabels);
$jsDefectCounts = json_encode($defectCounts);
$defectColors   = ($selectedResult === 'passed') ? ['#059669', '#dc2626'] : $PALETTE;
$jsDefectColors = json_encode(array_values(array_slice($defectColors, 0, max(count($defectLabels), 1))));
$jsModelLabels  = json_encode($modelLabels);
$jsModelCounts  = json_encode($modelCounts);
$jsModelColors  = json_encode(array_values(array_slice($PALETTE, 0, max(count($modelLabels), 1))));
$jsPieLabels    = json_encode($pieLabels);
$jsPieCounts    = json_encode($pieCounts);
$jsPieColors    = json_encode(array_values(array_slice($PALETTE, 0, max(count($pieLabels), 1))));

$passColor      = $kpi['pass_rate'] >= 90 ? '#059669' : ($kpi['pass_rate'] >= 70 ? '#d97706' : '#dc2626');
$rankColors     = ($selectedResult === 'passed') ? ['#059669','#10b981','#34d399','#0284c7','#3b82f6'] : ['#f59e0b','#94a3b8','#b45309','#3b82f6','#6366f1'];
$unitText       = $selectedUnit === 'lot' ? 'Lot' : 'Pcs';
$isPassedMode   = ($selectedResult === 'passed');
$lineColor      = $isPassedMode ? '#059669' : '#2563eb';
$lineBgColor    = $isPassedMode ? 'rgba(5,150,105,0.08)' : 'rgba(37,99,235,0.07)';
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col transition-all duration-300 min-h-screen bg-slate-100">
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <!-- Dashboard body: spacious layout with natural scrolling -->
    <div id="dash-body" class="flex-1 p-4 space-y-4 overflow-y-auto">

        <?= render_flash() ?>

        <!-- ── Filter Bar (Refined 2-Row Structured Layout) ─────────────────── -->
        <div class="card" style="padding:12px 16px;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.03);display:flex;flex-direction:column;gap:10px;">
            
            <!-- ROW 1: Presets, Unit Toggle, Result Toggle, & Export Excel Button -->
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding-bottom:10px;border-bottom:1px solid #f1f5f9;">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <!-- Preset Date Pills -->
                    <div style="display:flex;align-items:center;gap:3px;background:#f1f5f9;padding:3px;border-radius:9px;border:1px solid #e2e8f0;">
                        <?php $presets = ['hari_ini'=>'Hari Ini','mingguan'=>'Mingguan','bulanan'=>'Bulanan','tahunan'=>'Tahunan'];
                        foreach ($presets as $k => $lbl): 
                            $active = ($presetFilter === $k); 
                            $presetUrl = "?preset=" . $k 
                                       . ($selectedCustomer !== '' ? '&customer=' . urlencode($selectedCustomer) : '')
                                       . ($selectedUnit !== 'pcs' ? '&unit=' . urlencode($selectedUnit) : '')
                                       . ($selectedResult !== 'rejected' ? '&result=' . urlencode($selectedResult) : '');
                        ?>
                            <a href="<?= $presetUrl ?>"
                               style="padding:4px 11px;border-radius:6px;font-size:11px;font-weight:700;text-decoration:none;transition:all 0.2s;
                                      background:<?= $active ? '#2563eb' : 'transparent' ?>;
                                      color:<?= $active ? '#ffffff' : '#64748b' ?>;
                                      <?= $active ? 'box-shadow:0 1px 3px rgba(37,99,235,0.25);' : '' ?>">
                                <?= $lbl ?>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <!-- Unit Segmented Toggle (Pcs vs Lot) -->
                    <div style="display:flex;align-items:center;gap:3px;background:#f1f5f9;padding:3px;border-radius:9px;border:1px solid #e2e8f0;" title="Pilih Satuan Analisis Data">
                        <?php 
                        $unitPcsUrl = "?unit=pcs" . ($selectedResult !== 'rejected' ? '&result=' . $selectedResult : '') . ($presetFilter !== 'custom' ? '&preset=' . $presetFilter : '') . ($selectedCustomer !== '' ? '&customer=' . urlencode($selectedCustomer) : '') . (!empty($_GET['start_date']) ? '&start_date=' . urlencode($_GET['start_date']) . '&end_date=' . urlencode($_GET['end_date']) : '');
                        $unitLotUrl = "?unit=lot" . ($selectedResult !== 'rejected' ? '&result=' . $selectedResult : '') . ($presetFilter !== 'custom' ? '&preset=' . $presetFilter : '') . ($selectedCustomer !== '' ? '&customer=' . urlencode($selectedCustomer) : '') . (!empty($_GET['start_date']) ? '&start_date=' . urlencode($_GET['start_date']) . '&end_date=' . urlencode($_GET['end_date']) : '');
                        $isPcs = ($selectedUnit === 'pcs');
                        $isLot = ($selectedUnit === 'lot');
                        ?>
                        <a href="<?= $unitPcsUrl ?>" 
                           style="padding:4px 11px;border-radius:6px;font-size:11px;font-weight:800;text-decoration:none;transition:all 0.2s;
                                  background:<?= $isPcs ? '#1e293b' : 'transparent' ?>;
                                  color:<?= $isPcs ? '#ffffff' : '#64748b' ?>;
                                  <?= $isPcs ? 'box-shadow:0 1px 3px rgba(30,41,59,0.25);' : '' ?>">
                            PCS
                        </a>
                        <a href="<?= $unitLotUrl ?>" 
                           style="padding:4px 11px;border-radius:6px;font-size:11px;font-weight:800;text-decoration:none;transition:all 0.2s;
                                  background:<?= $isLot ? '#d97706' : 'transparent' ?>;
                                  color:<?= $isLot ? '#ffffff' : '#64748b' ?>;
                                  <?= $isLot ? 'box-shadow:0 1px 3px rgba(217,119,6,0.3);' : '' ?>">
                            LOT
                        </a>
                    </div>

                    <!-- Result Segmented Toggle (Rejected vs Passed) -->
                    <div style="display:flex;align-items:center;gap:3px;background:#f1f5f9;padding:3px;border-radius:9px;border:1px solid #e2e8f0;" title="Pilih Hasil Inspeksi Diagram">
                        <?php 
                        $resRejectedUrl = "?result=rejected" . ($selectedUnit !== 'pcs' ? '&unit=' . $selectedUnit : '') . ($presetFilter !== 'custom' ? '&preset=' . $presetFilter : '') . ($selectedCustomer !== '' ? '&customer=' . urlencode($selectedCustomer) : '') . (!empty($_GET['start_date']) ? '&start_date=' . urlencode($_GET['start_date']) . '&end_date=' . urlencode($_GET['end_date']) : '');
                        $resPassedUrl   = "?result=passed"   . ($selectedUnit !== 'pcs' ? '&unit=' . $selectedUnit : '') . ($presetFilter !== 'custom' ? '&preset=' . $presetFilter : '') . ($selectedCustomer !== '' ? '&customer=' . urlencode($selectedCustomer) : '') . (!empty($_GET['start_date']) ? '&start_date=' . urlencode($_GET['start_date']) . '&end_date=' . urlencode($_GET['end_date']) : '');
                        ?>
                        <a href="<?= $resRejectedUrl ?>" 
                           style="padding:4px 11px;border-radius:6px;font-size:11px;font-weight:800;text-decoration:none;transition:all 0.2s;
                                  background:<?= $selectedResult === 'rejected' ? '#dc2626' : 'transparent' ?>;
                                  color:<?= $selectedResult === 'rejected' ? '#ffffff' : '#64748b' ?>;
                                  <?= $selectedResult === 'rejected' ? 'box-shadow:0 1px 3px rgba(220,38,38,0.25);' : '' ?>">
                            REJECTED
                        </a>
                        <a href="<?= $resPassedUrl ?>" 
                           style="padding:4px 11px;border-radius:6px;font-size:11px;font-weight:800;text-decoration:none;transition:all 0.2s;
                                  background:<?= $selectedResult === 'passed' ? '#059669' : 'transparent' ?>;
                                  color:<?= $selectedResult === 'passed' ? '#ffffff' : '#64748b' ?>;
                                  <?= $selectedResult === 'passed' ? 'box-shadow:0 1px 3px rgba(5,150,105,0.3);' : '' ?>">
                            PASSED
                        </a>
                    </div>
                </div>

                <!-- Export Excel Action Button -->
                <button type="button" onclick="exportDashboardExcel(this)" 
                        style="display:inline-flex;align-items:center;gap:6px;padding:5px 14px;background:#059669;color:#ffffff;font-size:11px;font-weight:800;border-radius:8px;border:none;cursor:pointer;box-shadow:0 1px 3px rgba(5,150,105,0.3);transition:all 0.2s;"
                        onmouseover="this.style.background='#047857'" onmouseout="this.style.background='#059669'">
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    Export Excel
                </button>
            </div>

            <!-- ROW 2: Customer Filter, Custom Date Range, Terapkan, & Reset Button -->
            <form action="" method="GET" onsubmit="if(this.start_date.value && this.end_date.value){ if(this.preset) this.preset.disabled = true; }" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:0;">
                <?php if ($presetFilter !== 'custom'): ?>
                    <input type="hidden" name="preset" value="<?= htmlspecialchars($presetFilter) ?>">
                <?php endif; ?>
                <input type="hidden" name="unit" value="<?= htmlspecialchars($selectedUnit) ?>">
                <input type="hidden" name="result" value="<?= htmlspecialchars($selectedResult) ?>">

                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <!-- Customer Filter -->
                    <div style="display:flex;align-items:center;gap:6px;">
                        <span style="font-size:11.5px;font-weight:700;color:#475569;">Customer:</span>
                        <select name="customer" onchange="this.form.submit()" class="form-input text-xs" style="padding:4px 10px;border-radius:7px;font-weight:600;min-width:160px;border:1px solid #cbd5e1;">
                            <option value="">-- Semua Customer --</option>
                            <?php foreach ($customerOptions as $cOpt): ?>
                                <option value="<?= htmlspecialchars($cOpt) ?>" <?= $selectedCustomer === $cOpt ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cOpt) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Custom Date Range -->
                    <div style="display:flex;align-items:center;gap:6px;">
                        <span style="font-size:11.5px;font-weight:700;color:#475569;">Periode:</span>
                        <input type="date" name="start_date" value="<?= $presetFilter === 'custom' ? htmlspecialchars($startDate) : '' ?>" class="form-input text-xs" style="width:120px;padding:4px 8px;border-radius:7px;border:1px solid #cbd5e1;">
                        <span style="color:#94a3b8;font-weight:bold;">&ndash;</span>
                        <input type="date" name="end_date"   value="<?= $presetFilter === 'custom' ? htmlspecialchars($endDate) : '' ?>"   class="form-input text-xs" style="width:120px;padding:4px 8px;border-radius:7px;border:1px solid #cbd5e1;">
                        <button type="submit" class="btn-secondary text-xs font-semibold" style="padding:4px 12px;border-radius:7px;">Terapkan</button>
                    </div>
                </div>

                <!-- Reset Button (cleanly aligned on the right of Row 2) -->
                <div>
                    <?php if ($selectedCustomer !== '' || $selectedUnit !== 'pcs' || $selectedResult !== 'rejected' || $presetFilter === 'custom'): ?>
                        <a href="index.php" class="text-xs text-rose-600 font-extrabold hover:underline" style="padding:4px 10px;border:1px solid #fecdd3;border-radius:7px;background:#fff1f2;text-decoration:none;display:inline-flex;align-items:center;gap:4px;">
                            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            Reset Filter
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- ── 4 KPI Cards ───────────────────────────────── -->
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;" id="kpi-grid">

            <?php
            $kpis = [
                ['label'=>'Total Inspected','val'=>number_format($kpi['total_inspected']),'unit'=>'pcs','sub'=>'Sample dicek','icon'=>'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z','iconColor'=>'#2563eb','bg'=>'#eff6ff'],
                ['label'=>'Pass Rate','val'=>$kpi['pass_rate'].'%','unit'=>'','sub'=>$kpi['pass_count'].' passed / '.$kpi['total_lot'].' lot','icon'=>'M5 13l4 4L19 7','iconColor'=>'#059669','bg'=>'#f0fdf4','valColor'=>$passColor],
                ['label'=>'Total NG','val'=>number_format($kpi['total_ng']),'unit'=>'pcs','sub'=>$kpi['rejected_count'].' lot rejected','icon'=>'M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z','iconColor'=>'#dc2626','bg'=>'#fff1f2'],
                ['label'=>'Total Lot','val'=>number_format($kpi['total_lot']),'unit'=>'lot','sub'=>'Sesi inspeksi','icon'=>'M4 6h16M4 10h16M4 14h16M4 18h16','iconColor'=>'#0284c7','bg'=>'#f0f9ff'],
            ];
            foreach ($kpis as $kitem): ?>
            <div class="card" style="padding:12px 14px;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <div style="width:40px;height:40px;border-radius:10px;background:<?= $kitem['bg'] ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <svg width="20" height="20" fill="none" stroke="<?= $kitem['iconColor'] ?>" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="<?= $kitem['icon'] ?>"/></svg>
                    </div>
                    <div style="min-width:0;">
                        <div style="font-size:9.5px;font-weight:700;color:#94a3b8;letter-spacing:.05em;text-transform:uppercase;"><?= $kitem['label'] ?></div>
                        <div style="font-size:20px;font-weight:900;color:<?= $kitem['valColor'] ?? '#0f172a' ?>;line-height:1.15;">
                            <?= $kitem['val'] ?><?php if($kitem['unit']): ?> <span style="font-size:11px;font-weight:500;color:#94a3b8;"><?= $kitem['unit'] ?></span><?php endif; ?>
                        </div>
                        <div style="font-size:9.5px;color:#64748b;margin-top:2px;"><?= $kitem['sub'] ?></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

        </div>

        <!-- ── Chart Row 1: Line (Height Enlarged) + Top5 ─── -->
        <div style="display:grid;grid-template-columns:2fr 1fr;gap:10px;" id="dash-row-1">

            <!-- Tren Chart (Enlarged Height) -->
            <div class="card" style="padding:14px 16px;display:flex;flex-direction:column;">
                <div style="flex-shrink:0;margin-bottom:10px;">
                    <div style="font-size:13px;font-weight:800;color:#1e293b;">
                        <?php if ($isPassedMode): ?>
                            <?= $selectedUnit === 'lot' ? 'Tren Total Lot Passed (Lulus)' : 'Tren Total Pcs Inspected Passed' ?>
                        <?php else: ?>
                            <?= $selectedUnit === 'lot' ? 'Tren Total Lot Rejected (Ditolak)' : 'Tren Total Pcs NG' ?>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:10px;color:#94a3b8;"><?= date('d M Y', strtotime($startDate)) ?> &ndash; <?= date('d M Y', strtotime($endDate)) ?> &middot; <?= htmlspecialchars($trendSubtext ?? 'per tanggal') ?><?= $selectedCustomer !== '' ? ' &middot; Customer: ' . htmlspecialchars($selectedCustomer) : '' ?></div>
                </div>
                <div style="min-height:240px;height:240px;position:relative;">
                    <?php if (empty($trendLabels)): ?>
                        <div style="height:100%;display:flex;align-items:center;justify-content:center;color:#cbd5e1;font-size:11px;background:#f8fafc;border-radius:8px;border:1px dashed #e2e8f0;">Belum ada data inspeksi</div>
                    <?php else: ?>
                        <canvas id="chartTrendNG" style="width:100%;height:100%;"></canvas>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Top 5 Part -->
            <div class="card" style="padding:14px 16px;display:flex;flex-direction:column;">
                <div style="flex-shrink:0;margin-bottom:10px;">
                    <div style="font-size:13px;font-weight:800;color:#1e293b;">
                        <?php if ($isPassedMode): ?>
                            <?= $selectedUnit === 'lot' ? 'Top 5 Part (Lot Passed)' : 'Top 5 Part (Pcs Passed)' ?>
                        <?php else: ?>
                            <?= $selectedUnit === 'lot' ? 'Top 5 Part (Lot Rejected)' : 'Top 5 Part (Pcs NG)' ?>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:10px;color:#94a3b8;"><?= date('d M', strtotime($startDate)) ?> &ndash; <?= date('d M Y', strtotime($endDate)) ?></div>
                </div>
                <div style="flex:1;min-height:0;overflow-y:auto;">
                    <?php if (empty($top5Parts)): ?>
                        <div style="padding:40px 0;text-align:center;color:#cbd5e1;font-size:11px;">Belum ada data part</div>
                    <?php else: ?>
                        <div style="display:flex;flex-direction:column;gap:12px;">
                            <?php $maxNG = max(array_column($top5Parts,'total_ng')) ?: 1;
                            foreach ($top5Parts as $i => $pt):
                                $pct = round($pt['total_ng']/$maxNG*100);
                                $rc  = $rankColors[$i] ?? '#cbd5e1'; ?>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <div style="width:24px;height:24px;border-radius:7px;background:<?= $rc ?>;color:#fff;font-weight:800;font-size:10.5px;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><?= $i+1 ?></div>
                                <div style="flex:1;min-width:0;">
                                    <div style="font-weight:700;font-size:11px;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($pt['part_name'] ?? 'Unknown') ?></div>
                                    <div style="font-size:9.5px;color:#94a3b8;font-family:monospace;"><?= htmlspecialchars($pt['part_code'] ?? '-') ?></div>
                                    <div style="height:4px;background:#f1f5f9;border-radius:99px;margin-top:4px;overflow:hidden;">
                                        <div style="height:100%;width:<?= $pct ?>%;background:<?= $rc ?>;border-radius:99px;"></div>
                                    </div>
                                </div>
                                <div style="font-weight:900;font-size:13px;color:<?= $isPassedMode ? '#059669' : '#dc2626' ?>;flex-shrink:0;"><?= number_format($pt['total_ng']) ?> <span style="font-size:9.5px;font-weight:600;color:#94a3b8;"><?= strtolower($unitText) ?></span></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- ── Chart Row 2: Defect Donut + Model Pie + Part Pie (3 Equal Cards) ─────── -->
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;" id="dash-row-2">

            <!-- Breakdown Defect / Rasio Yield Status -->
            <div class="card" style="padding:14px 16px;display:flex;flex-direction:column;">
                <div style="flex-shrink:0;margin-bottom:10px;">
                    <div style="font-size:13px;font-weight:800;color:#1e293b;"><?= $isPassedMode ? 'Rasio Status Inspeksi (Yield)' : 'Breakdown Jenis Defect' ?></div>
                    <div style="font-size:10px;color:#94a3b8;"><?= $isPassedMode ? 'Perbandingan hasil passed vs rejected' : ($selectedUnit === 'lot' ? 'Distribusi lot terinfeksi cacat' : 'Distribusi kuantitas cacat (pcs)') ?></div>
                </div>
                <?php if (empty($defectCounts)): ?>
                    <div style="height:160px;display:flex;align-items:center;justify-content:center;color:#cbd5e1;font-size:11px;background:#f8fafc;border-radius:8px;border:1px dashed #e2e8f0;">Belum ada data</div>
                <?php else: ?>
                    <div style="display:flex;gap:12px;align-items:center;min-height:160px;">
                        <div style="width:125px;height:125px;flex-shrink:0;position:relative;">
                            <canvas id="chartDefect" style="width:100%;height:100%;"></canvas>
                        </div>
                        <div style="flex:1;min-width:0;display:flex;flex-direction:column;gap:5px;max-height:160px;overflow-y:auto;">
                            <?php $totalD = array_sum($defectCounts) ?: 1;
                            $defectPalette = ($selectedResult === 'passed') ? ['#059669', '#dc2626'] : $PALETTE;
                            foreach ($defectLabels as $di => $dl):
                                $dpct = round($defectCounts[$di]/$totalD*100,1);
                                $dc   = $defectPalette[$di%count($defectPalette)]; ?>
                            <div style="display:flex;align-items:center;justify-content:space-between;font-size:10.5px;gap:6px;padding:3px 6px;border-radius:6px;background:#f8fafc;">
                                <span style="display:flex;align-items:center;gap:5px;color:#334155;flex:1;min-width:0;">
                                    <span style="width:8px;height:8px;border-radius:50%;background:<?= $dc ?>;flex-shrink:0;display:inline-block;"></span>
                                    <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:600;"><?= htmlspecialchars($dl) ?></span>
                                </span>
                                <span style="font-weight:800;color:#0f172a;flex-shrink:0;"><?= number_format($defectCounts[$di]) ?> <span style="font-weight:500;color:#64748b;"><?= strtolower($unitText) ?> (<?= $dpct ?>%)</span></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Distribusi per Model -->
            <div class="card" style="padding:14px 16px;display:flex;flex-direction:column;">
                <div style="flex-shrink:0;margin-bottom:10px;">
                    <div style="font-size:13px;font-weight:800;color:#1e293b;">
                        <?php if ($isPassedMode): ?>
                            <?= $selectedUnit === 'lot' ? 'Distribusi Lot Passed per Model' : 'Distribusi Passed per Model' ?>
                        <?php else: ?>
                            <?= $selectedUnit === 'lot' ? 'Distribusi Lot Rejected per Model' : 'Distribusi NG per Model' ?>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:10px;color:#94a3b8;">Proporsi berdasarkan model produk (<?= strtolower($unitText) ?>)</div>
                </div>
                <?php if (empty($modelCounts)): ?>
                    <div style="height:160px;display:flex;align-items:center;justify-content:center;color:#cbd5e1;font-size:11px;background:#f8fafc;border-radius:8px;border:1px dashed #e2e8f0;">Belum ada data per model</div>
                <?php else: ?>
                    <div style="display:flex;gap:12px;align-items:center;min-height:160px;">
                        <div style="width:125px;height:125px;flex-shrink:0;position:relative;">
                            <canvas id="chartModelPie" style="width:100%;height:100%;"></canvas>
                        </div>
                        <div style="flex:1;min-width:0;display:flex;flex-direction:column;gap:5px;max-height:160px;overflow-y:auto;">
                            <?php $totalM = array_sum($modelCounts) ?: 1;
                            foreach ($modelLabels as $mi => $ml):
                                $mpct = round($modelCounts[$mi]/$totalM*100,1);
                                $mc   = $PALETTE[$mi%count($PALETTE)]; ?>
                            <div style="display:flex;align-items:center;justify-content:space-between;font-size:10.5px;gap:6px;padding:3px 6px;border-radius:6px;background:#f8fafc;">
                                <span style="display:flex;align-items:center;gap:5px;color:#334155;flex:1;min-width:0;">
                                    <span style="width:8px;height:8px;border-radius:50%;background:<?= $mc ?>;flex-shrink:0;display:inline-block;"></span>
                                    <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:600;"><?= htmlspecialchars($ml) ?></span>
                                </span>
                                <span style="font-weight:800;color:#0f172a;flex-shrink:0;"><?= number_format($modelCounts[$mi]) ?> <span style="font-weight:500;color:#64748b;"><?= strtolower($unitText) ?> (<?= $mpct ?>%)</span></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Distribusi per Part -->
            <div class="card" style="padding:14px 16px;display:flex;flex-direction:column;">
                <div style="flex-shrink:0;margin-bottom:10px;">
                    <div style="font-size:13px;font-weight:800;color:#1e293b;">
                        <?php if ($isPassedMode): ?>
                            <?= $selectedUnit === 'lot' ? 'Distribusi Lot Passed per Part' : 'Distribusi Passed per Part' ?>
                        <?php else: ?>
                            <?= $selectedUnit === 'lot' ? 'Distribusi Lot Rejected per Part' : 'Distribusi NG per Part' ?>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:10px;color:#94a3b8;">Proporsi berdasarkan part (<?= strtolower($unitText) ?>)</div>
                </div>
                <?php if (empty($pieCounts)): ?>
                    <div style="height:160px;display:flex;align-items:center;justify-content:center;color:#cbd5e1;font-size:11px;background:#f8fafc;border-radius:8px;border:1px dashed #e2e8f0;">Belum ada data per part</div>
                <?php else: ?>
                    <div style="display:flex;gap:12px;align-items:center;min-height:160px;">
                        <div style="width:125px;height:125px;flex-shrink:0;position:relative;">
                            <canvas id="chartPartPie" style="width:100%;height:100%;"></canvas>
                        </div>
                        <div style="flex:1;min-width:0;display:flex;flex-direction:column;gap:5px;max-height:160px;overflow-y:auto;">
                            <?php $totalP = array_sum($pieCounts) ?: 1;
                            foreach ($pieLabels as $pi => $pl):
                                $ppct = round($pieCounts[$pi]/$totalP*100,1);
                                $pc   = $PALETTE[$pi%count($PALETTE)]; ?>
                            <div style="display:flex;align-items:center;justify-content:space-between;font-size:10.5px;gap:6px;padding:3px 6px;border-radius:6px;background:#f8fafc;">
                                <span style="display:flex;align-items:center;gap:5px;color:#334155;flex:1;min-width:0;">
                                    <span style="width:8px;height:8px;border-radius:50%;background:<?= $pc ?>;flex-shrink:0;display:inline-block;"></span>
                                    <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:600;"><?= htmlspecialchars($pl) ?></span>
                                </span>
                                <span style="font-weight:800;color:#0f172a;flex-shrink:0;"><?= number_format($pieCounts[$pi]) ?> <span style="font-weight:500;color:#64748b;"><?= strtolower($unitText) ?> (<?= $ppct ?>%)</span></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

        </div>

        <!-- ── Section 3: II. WORST PART OQC vs BEST PERFORMANCE PART OQC ─────── -->
        <div class="card" style="padding:16px 18px;">
            <?php if ($isPassedMode): ?>
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid #f1f5f9;">
                    <div>
                        <h2 style="font-size:14px;font-weight:900;color:#1e293b;letter-spacing:.02em;text-transform:uppercase;margin:0;display:flex;align-items:center;gap:6px;">
                            <span style="color:#059669;">II.</span> BEST PERFORMANCE PART OQC
                        </h2>
                    </div>
                    <span style="font-size:11px;font-weight:600;color:#059669;">Top Part & Model Performa Lulus Terbaik</span>
                </div>

                <?php if (empty($bestPartsPassed)): ?>
                    <div style="padding:30px 0;text-align:center;color:#cbd5e1;font-size:11px;background:#f8fafc;border-radius:8px;border:1px dashed #e2e8f0;">
                        Belum ada data part lulus pada periode ini.
                    </div>
                <?php else: ?>
                    <div style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:12px;">
                        <table style="width:100%;border-collapse:collapse;font-size:11px;text-align:left;">
                            <thead>
                                <tr style="background:#065f46;color:#ffffff;font-weight:800;font-size:10px;text-transform:uppercase;letter-spacing:.05em;">
                                    <th style="padding:8px 12px;text-align:center;width:45px;">NO</th>
                                    <th style="padding:8px 12px;">PART NAME</th>
                                    <th style="padding:8px 12px;">PART CODE</th>
                                    <th style="padding:8px 12px;">MODEL</th>
                                    <th style="padding:8px 12px;text-align:center;">LOT CASE</th>
                                    <th style="padding:8px 12px;text-align:center;">PASSED CASE</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr style="background:#ecfdf5;border-top:1px solid #a7f3d0;border-bottom:1px solid #a7f3d0;">
                                    <td colspan="6" style="padding:6px 12px;font-weight:900;color:#047857;font-size:11px;text-transform:uppercase;letter-spacing:.04em;">
                                        🏆 TOP PASSED PART & MODEL PERFORMANCE
                                    </td>
                                </tr>
                                <?php foreach ($bestPartsPassed as $bIdx => $bP): ?>
                                    <tr style="border-bottom:1px solid #f1f5f9;" class="hover:bg-slate-50">
                                        <td style="padding:8px 12px;text-align:center;font-weight:700;color:#94a3b8;"><?= $bIdx + 1 ?></td>
                                        <td style="padding:8px 12px;font-weight:800;color:#0f172a;"><?= htmlspecialchars($bP['part_name']) ?></td>
                                        <td style="padding:8px 12px;font-family:monospace;color:#475569;"><?= htmlspecialchars($bP['part_code']) ?></td>
                                        <td style="padding:8px 12px;color:#475569;"><?= htmlspecialchars($bP['model']) ?></td>
                                        <td style="padding:8px 12px;text-align:center;font-family:monospace;font-weight:700;color:#334155;"><?= number_format($bP['lot_case']) ?></td>
                                        <td style="padding:8px 12px;text-align:center;font-family:monospace;font-weight:900;color:#059669;background:#ecfdf5;"><?= number_format($bP['passed_lot_case']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Emerald Conclusion Box -->
                    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:10px 14px;">
                        <span style="font-size:12px;font-weight:900;color:#166534;">Conclusion : </span>
                        <span style="font-size:12px;font-weight:700;color:#15803d;">
                            3 best performing parts are <span style="font-weight:900;color:#0f172a;"><?= htmlspecialchars($top3PartsSummaryStr) ?></span>
                        </span>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid #f1f5f9;">
                    <div>
                        <h2 style="font-size:14px;font-weight:900;color:#1e293b;letter-spacing:.02em;text-transform:uppercase;margin:0;display:flex;align-items:center;gap:6px;">
                            <span style="color:#2563eb;">II.</span> WORST PART OQC
                        </h2>
                    </div>
                    <span style="font-size:11px;font-weight:600;color:#94a3b8;">Breakdown per Defect Area</span>
                </div>

                <?php if (empty($worstPartsGrouped)): ?>
                    <div style="padding:30px 0;text-align:center;color:#cbd5e1;font-size:11px;background:#f8fafc;border-radius:8px;border:1px dashed #e2e8f0;">
                        Belum ada data part terburuk pada periode ini.
                    </div>
                <?php else: ?>
                    <div style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:12px;">
                        <table style="width:100%;border-collapse:collapse;font-size:11px;text-align:left;">
                            <thead>
                                <tr style="background:#1e293b;color:#ffffff;font-weight:800;font-size:10px;text-transform:uppercase;letter-spacing:.05em;">
                                    <th style="padding:8px 12px;text-align:center;width:45px;">NO</th>
                                    <th style="padding:8px 12px;">PART NAME</th>
                                    <th style="padding:8px 12px;">PART CODE</th>
                                    <th style="padding:8px 12px;">MODEL</th>
                                    <th style="padding:8px 12px;text-align:center;">LOT CASE</th>
                                    <th style="padding:8px 12px;text-align:center;">NG CASE</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($worstPartsGrouped as $defectCategory => $parts): ?>
                                    <!-- Defect Category Group Header -->
                                    <tr style="background:#fff1f2;border-top:1px solid #fecdd3;border-bottom:1px solid #fecdd3;">
                                        <td colspan="6" style="padding:6px 12px;font-weight:900;color:#be123c;font-size:11px;text-transform:uppercase;letter-spacing:.04em;">
                                            🚨 <?= htmlspecialchars($defectCategory) ?>
                                        </td>
                                    </tr>
                                    <?php if (empty($parts)): ?>
                                        <tr>
                                            <td colspan="6" style="padding:8px 12px;text-align:center;color:#94a3b8;font-style:italic;">Tidak ada rekaman part</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($parts as $pIdx => $p): ?>
                                            <tr style="border-bottom:1px solid #f1f5f9;" class="hover:bg-slate-50">
                                                <td style="padding:8px 12px;text-align:center;font-weight:700;color:#94a3b8;"><?= $pIdx + 1 ?></td>
                                                <td style="padding:8px 12px;font-weight:800;color:#0f172a;"><?= htmlspecialchars($p['part_name']) ?></td>
                                                <td style="padding:8px 12px;font-family:monospace;color:#475569;"><?= htmlspecialchars($p['part_code']) ?></td>
                                                <td style="padding:8px 12px;color:#475569;"><?= htmlspecialchars($p['model']) ?></td>
                                                <td style="padding:8px 12px;text-align:center;font-family:monospace;font-weight:700;color:#334155;"><?= number_format($p['lot_case']) ?></td>
                                                <td style="padding:8px 12px;text-align:center;font-family:monospace;font-weight:900;color:#dc2626;background:#fff1f2;"><?= number_format($p['ng_case']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Soft Blue Conclusion Box (Matching Presentation Slide) -->
                    <div style="background:#eff6ff;border:1px solid #dbeafe;border-radius:10px;padding:10px 14px;">
                        <span style="font-size:12px;font-weight:900;color:#1e40af;">Conclusion : </span>
                        <span style="font-size:12px;font-weight:700;color:#1d4ed8;">
                            3 worst parts are <span style="font-weight:900;color:#0f172a;"><?= htmlspecialchars($top3PartsSummaryStr) ?></span>
                        </span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

    </div><!-- end dash-body -->

    <!-- Mobile fallback -->
    <style>
        @media (max-width: 1024px) {
            #main-content-wrapper { height:auto !important; overflow:auto !important; }
            #dash-body { overflow:auto !important; }
            #kpi-grid   { grid-template-columns: repeat(2,1fr) !important; }
            #dash-row-1,#dash-row-2 { grid-template-columns: 1fr !important; flex: none !important; }
        }
    </style>

    <script src="<?= base_url('assets/js/vendor/chart.min.js') ?>"></script>
    <script>
    Chart.defaults.font.family = "'system-ui','Segoe UI',sans-serif";
    Chart.defaults.color       = '#94a3b8';
    var activeUnit             = '<?= $unitText ?>';
    var isPassedMode           = <?= $isPassedMode ? 'true' : 'false' ?>;

    <?php if (!empty($trendLabels)): ?>
    new Chart(document.getElementById('chartTrendNG'), {
        type: 'line',
        data: {
            labels: <?= $jsLabels ?>,
            datasets: [{ 
                label: isPassedMode ? (activeUnit === 'Lot' ? 'Total Lot Passed' : 'Total Pcs Passed') : (activeUnit === 'Lot' ? 'Total Lot Rejected' : 'Total Pcs NG'), 
                data: <?= $jsTrendNG ?>, 
                borderColor: '<?= $lineColor ?>', 
                backgroundColor: '<?= $lineBgColor ?>', 
                borderWidth: 2.5, 
                pointBackgroundColor: '<?= $lineColor ?>', 
                pointBorderColor: '#fff', 
                pointBorderWidth: 2, 
                pointRadius: 3, 
                pointHoverRadius: 5, 
                fill: true, 
                tension: 0.4 
            }]
        },
        options: {
            responsive:true, maintainAspectRatio:false,
            plugins:{ legend:{display:false}, tooltip:{mode:'index',intersect:false} },
            scales:{
                x:{ grid:{display:false}, ticks:{font:{size:10,weight:'600'},color:'#64748b'} },
                y:{ beginAtZero:true, grid:{color:'#f1f5f9'}, ticks:{font:{size:10},color:'#64748b',precision:0} }
            }
        }
    });
    <?php endif; ?>

    <?php if (!empty($defectCounts)): ?>
    new Chart(document.getElementById('chartDefect'), {
        type:'doughnut',
        data:{ labels:<?= $jsDefectLabels ?>, datasets:[{ data:<?= $jsDefectCounts ?>, backgroundColor:<?= $jsDefectColors ?>, borderWidth:2, borderColor:'#fff', hoverOffset:4 }] },
        options:{ responsive:true, maintainAspectRatio:false, cutout:'58%', plugins:{ legend:{display:false}, tooltip:{ callbacks:{ label:function(c){ var t=c.dataset.data.reduce(function(a,b){return a+b;},0); return ' '+c.raw+' '+activeUnit.toLowerCase()+' ('+Math.round(c.raw/t*100)+'%)'; } } } } }
    });
    <?php endif; ?>

    <?php if (!empty($modelCounts)): ?>
    new Chart(document.getElementById('chartModelPie'), {
        type:'pie',
        data:{ labels:<?= $jsModelLabels ?>, datasets:[{ data:<?= $jsModelCounts ?>, backgroundColor:<?= $jsModelColors ?>, borderWidth:2, borderColor:'#fff', hoverOffset:4 }] },
        options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{display:false}, tooltip:{ callbacks:{ label:function(c){ var t=c.dataset.data.reduce(function(a,b){return a+b;},0); return ' '+c.raw+' '+activeUnit.toLowerCase()+' ('+Math.round(c.raw/t*100)+'%)'; } } } } }
    });
    <?php endif; ?>

    <?php if (!empty($pieCounts)): ?>
    new Chart(document.getElementById('chartPartPie'), {
        type:'pie',
        data:{ labels:<?= $jsPieLabels ?>, datasets:[{ data:<?= $jsPieCounts ?>, backgroundColor:<?= $jsPieColors ?>, borderWidth:2, borderColor:'#fff', hoverOffset:4 }] },
        options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{display:false}, tooltip:{ callbacks:{ label:function(c){ var t=c.dataset.data.reduce(function(a,b){return a+b;},0); return ' '+c.raw+' '+activeUnit.toLowerCase()+' ('+Math.round(c.raw/t*100)+'%)'; } } } } }
    });
    <?php endif; ?>

    function exportDashboardExcel(btn) {
        var origText = btn ? btn.innerHTML : '';
        if (btn) {
            btn.innerHTML = '⏳ Menyiapkan Excel...';
            btn.disabled = true;
        }

        var form = document.createElement('form');
        form.method = 'POST';
        form.action = 'export_excel.php';
        form.style.display = 'none';

        var params = {
            preset: '<?= htmlspecialchars($presetFilter) ?>',
            customer: '<?= htmlspecialchars($selectedCustomer) ?>',
            unit: '<?= htmlspecialchars($selectedUnit) ?>',
            result: '<?= htmlspecialchars($selectedResult) ?>',
            start_date: '<?= htmlspecialchars($_GET['start_date'] ?? '') ?>',
            end_date: '<?= htmlspecialchars($_GET['end_date'] ?? '') ?>'
        };

        for (var k in params) {
            if (params.hasOwnProperty(k)) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = k;
                input.value = params[k];
                form.appendChild(input);
            }
        }

        document.body.appendChild(form);
        form.submit();
        setTimeout(function() {
            document.body.removeChild(form);
            if (btn) {
                btn.innerHTML = origText;
                btn.disabled = false;
            }
        }, 1500);
    }
    </script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
