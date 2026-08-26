<?php
$breadcrumbCategory = "OPERASIONAL";
$pageTitle          = "STI Survival (OQC Data)";
$pageSubtitle       = "Laporan Kinerja OQC & Analisis Defect — PT. Surya Technology Industri";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

$pdo = getDB();

// ── Fetch Target PPM from DB ────────────────────────────────────────────────
$targetPpm = 10000;
if ($pdo) {
    try {
        $stmtP = $pdo->prepare("SELECT setting_value FROM `system_settings` WHERE `setting_key` = 'target_ppm'");
        $stmtP->execute();
        $val = $stmtP->fetchColumn();
        if ($val !== false && is_numeric($val)) {
            $targetPpm = (int)$val;
        }
    } catch (Exception $e) {}
}

// ── Date range resolver ────────────────────────────────────────────────────
$presetFilter = sanitize($_GET['preset'] ?? 'bulanan');

if (!empty($_GET['start_date']) && !empty($_GET['end_date'])) {
    $startDate    = sanitize($_GET['start_date']);
    $endDate      = sanitize($_GET['end_date']);
    $presetFilter = 'custom';
} else {
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

// ── Query Data for STI Survival ─────────────────────────────────────────────
$monthlyPerformance = []; // ['month_label' => 'Jul 26', 'total_inspect_pcs' => 1000, 'ng_pcs' => 10, 'ppm' => 10000, 'lot_ok' => 50, 'lot_ng' => 2, 'total_lot' => 52]
$recentMonthsSummary = ['months' => [], 'lot_ok' => [], 'lot_ng' => [], 'total_lot' => []];
$top10Defects       = []; // ['name' => 'TRIMMING', 'count' => 1631, 'pct' => 29]
$worstPartsGrouped   = []; // ['defect_name' => [ ['part_name'=>'...', 'part_code'=>'...', 'model'=>'...', 'lot_case'=>10, 'ng_case'=>2] ]]
$totalDefectCount   = 0;
$kpiTotals          = ['total_lot' => 0, 'lot_ok' => 0, 'lot_ng' => 0, 'total_sample_pcs' => 0, 'total_ng_pcs' => 0, 'current_ppm' => 0];

if ($pdo) {
    try {
        $params = [':sd' => $startDate, ':ed' => $endDate];

        // 1. Overall KPI totals for selected date range
        $stmtK = $pdo->prepare("SELECT 
                    COUNT(*) AS total_lot,
                    SUM(status = 'passed') AS lot_ok,
                    SUM(status = 'rejected') AS lot_ng,
                    COALESCE(SUM(sample_size), 0) AS total_sample_pcs,
                    COALESCE(SUM(ng_count), 0) AS total_ng_pcs
                FROM inspection_sessions 
                WHERE DATE(started_at) BETWEEN :sd AND :ed");
        $stmtK->execute($params);
        $kRow = $stmtK->fetch();
        if ($kRow) {
            $kpiTotals['total_lot']        = (int)$kRow['total_lot'];
            $kpiTotals['lot_ok']           = (int)$kRow['lot_ok'];
            $kpiTotals['lot_ng']           = (int)$kRow['lot_ng'];
            $kpiTotals['total_sample_pcs'] = (int)$kRow['total_sample_pcs'];
            $kpiTotals['total_ng_pcs']     = (int)$kRow['total_ng_pcs'];
            $kpiTotals['current_ppm']      = $kpiTotals['total_sample_pcs'] > 0 
                ? (int)round(($kpiTotals['total_ng_pcs'] / $kpiTotals['total_sample_pcs']) * 1000000) 
                : 0;
        }

        // 2. Monthly Trend Data (Last 6-12 months for STI OQC Performance Chart)
        $stmtM = $pdo->prepare("SELECT 
                    DATE_FORMAT(started_at, '%b \'%y') AS month_label,
                    DATE_FORMAT(started_at, '%Y-%m') AS ym,
                    COUNT(*) AS total_lot,
                    SUM(status = 'passed') AS lot_ok,
                    SUM(status = 'rejected') AS lot_ng,
                    COALESCE(SUM(sample_size), 0) AS sample_pcs,
                    COALESCE(SUM(ng_count), 0) AS ng_pcs
                FROM inspection_sessions 
                WHERE started_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                GROUP BY ym, month_label
                ORDER BY ym ASC");
        $stmtM->execute();
        $mRows = $stmtM->fetchAll();

        foreach ($mRows as $mr) {
            $sPcs  = (int)$mr['sample_pcs'];
            $ngPcs = (int)$mr['ng_pcs'];
            $ppmVal = $sPcs > 0 ? (int)round(($ngPcs / $sPcs) * 1000000) : 0;
            $monthlyPerformance[] = [
                'label'       => $mr['month_label'],
                'ppm'         => $ppmVal,
                'lot_ok'      => (int)$mr['lot_ok'],
                'lot_ng'      => (int)$mr['lot_ng'],
                'total_lot'   => (int)$mr['total_lot'],
                'target'      => $targetPpm
            ];
        }

        // Slice last 4 months for top-right lot table
        $last4 = array_slice($monthlyPerformance, -4);
        foreach ($last4 as $l4) {
            $recentMonthsSummary['months'][]    = $l4['label'];
            $recentMonthsSummary['lot_ok'][]    = $l4['lot_ok'];
            $recentMonthsSummary['lot_ng'][]    = $l4['lot_ng'];
            $recentMonthsSummary['total_lot'][] = $l4['total_lot'];
        }

        // 3. Top 10 Worst Defects (PPM / Quantity & %)
        $stmtD = $pdo->prepare("SELECT 
                    dt.name AS defect_name, 
                    COALESCE(SUM(ngr.qty_ng), 0) AS defect_count
                FROM inspection_ng_records ngr
                INNER JOIN defect_types dt ON ngr.defect_type_id = dt.id
                INNER JOIN inspection_samples s ON ngr.inspection_sample_id = s.id
                INNER JOIN inspection_sessions ss ON s.inspection_session_id = ss.id
                WHERE DATE(s.checked_at) BETWEEN :sd AND :ed
                GROUP BY dt.id, dt.name
                ORDER BY defect_count DESC
                LIMIT 10");
        $stmtD->execute($params);
        $dRows = $stmtD->fetchAll();

        $totalDefectCount = array_sum(array_column($dRows, 'defect_count')) ?: 1;
        foreach ($dRows as $dr) {
            $cnt = (int)$dr['defect_count'];
            $top10Defects[] = [
                'name'  => strtoupper($dr['defect_name']),
                'count' => $cnt,
                'pct'   => round(($cnt / $totalDefectCount) * 100, 1)
            ];
        }

        // 4. Worst Part OQC (Grouped by Top 3 Defect Types)
        $top3DefectNames = array_slice(array_column($top10Defects, 'name'), 0, 3);
        
        foreach ($top3DefectNames as $defName) {
            $stmtWP = $pdo->prepare("SELECT 
                        COALESCE(mp.part_name, 'UNKNOWN PART') AS part_name,
                        COALESCE(mp.part_code, '-') AS part_code,
                        COALESCE(mp.model, '-') AS model,
                        COUNT(DISTINCT ss.id) AS lot_case,
                        SUM(CASE WHEN ss.status = 'rejected' THEN 1 ELSE 0 END) AS ng_case
                    FROM inspection_ng_records ngr
                    INNER JOIN defect_types dt ON ngr.defect_type_id = dt.id
                    INNER JOIN inspection_samples s ON ngr.inspection_sample_id = s.id
                    INNER JOIN inspection_sessions ss ON s.inspection_session_id = ss.id
                    LEFT JOIN master_parts mp ON ss.part_id = mp.id
                    WHERE UPPER(dt.name) = :defname
                      AND DATE(s.checked_at) BETWEEN :sd AND :ed
                    GROUP BY mp.id, mp.part_name, mp.part_code, mp.model
                    ORDER BY ng_case DESC, lot_case DESC
                    LIMIT 3");
            $stmtWP->execute([':defname' => $defName, ':sd' => $startDate, ':ed' => $endDate]);
            $worstPartsGrouped[$defName] = $stmtWP->fetchAll();
        }

    } catch (PDOException $e) {}
}

// Format chart JSONs
$chartMonths = json_encode(array_column($monthlyPerformance, 'label'));
$chartPpm    = json_encode(array_column($monthlyPerformance, 'ppm'));
$chartTarget = json_encode(array_column($monthlyPerformance, 'target'));

$pieLabels = json_encode(array_column($top10Defects, 'name'));
$pieCounts = json_encode(array_column($top10Defects, 'count'));
$pieColors = json_encode(['#2563eb','#d97706','#dc2626','#059669','#8b5cf6','#ec4899','#6366f1','#14b8a6','#f97316','#64748b']);

// Automatic Summary Strings
$top3DefectListStr = !empty($top10Defects) ? implode(', ', array_slice(array_column($top10Defects, 'name'), 0, 3)) : 'Belum Ada Cacat';
$top3PartsNames = [];
foreach ($worstPartsGrouped as $dName => $parts) {
    if (!empty($parts[0]['part_name'])) {
        $top3PartsNames[] = htmlspecialchars($parts[0]['part_name']) . " (" . strtolower($dName) . ")";
    }
}
$top3PartsSummaryStr = !empty($top3PartsNames) ? implode(', ', $top3PartsNames) : 'Belum Ada Part Reject';
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col transition-all duration-300 min-h-screen bg-slate-100">
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <!-- Presentation Header Banner (Matching STI Survival Red Banner Theme) -->
    <div class="bg-gradient-to-r from-red-700 via-red-600 to-red-800 text-white px-6 py-3 shadow-md flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center space-x-3">
            <div class="bg-white/20 p-2 rounded-lg backdrop-blur-sm">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                </svg>
            </div>
            <div>
                <h1 class="text-lg font-black tracking-wider uppercase leading-none">STI Internal Defect (OQC DATA)</h1>
                <span class="text-xs font-semibold text-red-100 tracking-widest block mt-0.5">PERFORMANCE ANALYSIS & SURVIVAL METRICS</span>
            </div>
        </div>
        <div class="flex items-center space-x-3">
            <span class="bg-red-900/60 border border-red-400/40 text-red-100 px-3 py-1 rounded-md text-xs font-mono font-bold tracking-wider">
                STI SURVIVAL
            </span>
            <button onclick="document.getElementById('modalTargetPpm').classList.remove('hidden')" 
                    class="bg-white text-red-700 hover:bg-red-50 font-bold px-3 py-1 rounded-md text-xs shadow transition-all flex items-center space-x-1">
                <span>⚙️ Target PPM: <?= number_format($targetPpm) ?></span>
            </button>
        </div>
    </div>

    <!-- Main Container -->
    <div class="p-4 space-y-4 flex-1">

        <?= render_flash() ?>

        <!-- Filter & Summary Bar -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-3 flex flex-wrap items-center justify-between gap-3">
            <!-- Filter Presets -->
            <div class="flex items-center gap-1 bg-slate-100 p-1 rounded-lg">
                <?php $presets = ['hari_ini'=>'Hari Ini','mingguan'=>'Mingguan','bulanan'=>'Bulanan','tahunan'=>'Tahunan'];
                foreach ($presets as $k => $lbl): $active = ($presetFilter === $k); ?>
                    <a href="?preset=<?= $k ?>"
                       class="px-3 py-1 rounded-md text-xs font-bold transition-all <?= $active ? 'bg-red-600 text-white shadow' : 'text-slate-600 hover:bg-slate-200' ?>">
                        <?= $lbl ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Date Range Form -->
            <form action="" method="GET" class="flex items-center space-x-2">
                <span class="text-xs font-semibold text-slate-500">Periode:</span>
                <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" class="form-input text-xs border-slate-300 rounded-md py-1 px-2">
                <span class="text-slate-400">&ndash;</span>
                <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" class="form-input text-xs border-slate-300 rounded-md py-1 px-2">
                <button type="submit" class="bg-slate-800 hover:bg-slate-900 text-white text-xs font-bold px-3 py-1 rounded-md transition-all">
                    Terapkan
                </button>
            </form>

            <!-- Overall PPM Quick Stat -->
            <div class="flex items-center space-x-4 border-l border-slate-200 pl-4">
                <div class="text-right">
                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">PPM Saat Ini</span>
                    <span class="text-base font-black <?= $kpiTotals['current_ppm'] <= $targetPpm ? 'text-emerald-600' : 'text-red-600' ?>">
                        <?= number_format($kpiTotals['current_ppm']) ?> PPM
                    </span>
                </div>
                <div class="text-right">
                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Total Lot</span>
                    <span class="text-base font-black text-slate-800">
                        <?= number_format($kpiTotals['total_lot']) ?> Lot
                    </span>
                </div>
            </div>
        </div>

        <!-- TOP SECTION: OQC PERFORMANCE (LEFT) & LOT SUMMARY TABLE (RIGHT) -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

            <!-- Top Left (2 Cols): STI OQC PERFORMANCE (PPM Trend Bar + Target Line) -->
            <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-slate-200 p-4 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <h2 class="text-sm font-black text-slate-800 tracking-wide uppercase flex items-center gap-2">
                            <span class="w-2 h-4 bg-red-600 rounded-full inline-block"></span>
                            STI OQC PERFORMANCE
                        </h2>
                        <span class="text-xs font-semibold text-slate-500">Satuan: PPM (Parts Per Million)</span>
                    </div>

                    <!-- Chart Container -->
                    <div class="relative w-full h-56">
                        <canvas id="chartOqcPerformance"></canvas>
                    </div>
                </div>

                <!-- Table Under Chart (Matching Presentation Slide Table) -->
                <div class="mt-3 overflow-x-auto border border-slate-200 rounded-lg">
                    <table class="w-full text-center text-xs border-collapse">
                        <thead>
                            <tr class="bg-slate-800 text-white font-bold text-[10px] tracking-wider uppercase">
                                <th class="p-1.5 border-r border-slate-700 text-left pl-3">Metrik</th>
                                <?php foreach ($monthlyPerformance as $mp): ?>
                                    <th class="p-1.5 border-r border-slate-700"><?= htmlspecialchars($mp['label']) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 font-mono text-[11px]">
                            <tr class="bg-emerald-50/50">
                                <td class="p-1.5 font-sans font-bold text-emerald-800 text-left pl-3 flex items-center gap-1">
                                    <span class="w-2.5 h-2.5 bg-emerald-500 rounded-sm inline-block"></span> PPM
                                </td>
                                <?php foreach ($monthlyPerformance as $mp): ?>
                                    <td class="p-1.5 font-bold <?= $mp['ppm'] <= $targetPpm ? 'text-emerald-700' : 'text-red-600 font-black' ?>">
                                        <?= number_format($mp['ppm']) ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                            <tr class="bg-red-50/50">
                                <td class="p-1.5 font-sans font-bold text-red-800 text-left pl-3 flex items-center gap-1">
                                    <span class="w-2.5 h-2.5 bg-red-500 rounded-full inline-block"></span> Target
                                </td>
                                <?php foreach ($monthlyPerformance as $mp): ?>
                                    <td class="p-1.5 text-red-700 font-semibold"><?= number_format($mp['target']) ?></td>
                                <?php endforeach; ?>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Top Right (1 Col): Detection Ability Box & Recent Months Lot Table -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 flex flex-col justify-between space-y-4">
                
                <!-- Blue Gradient Status Box (Matching Slide Top Right) -->
                <div class="bg-gradient-to-br from-blue-50 to-indigo-100 border border-blue-200 rounded-xl p-4 shadow-inner">
                    <span class="text-[10px] font-extrabold text-blue-600 uppercase tracking-widest block mb-1">ANALYSIS TREND</span>
                    <p class="text-xs text-slate-700 leading-relaxed font-semibold">
                        OQC Detection ability trend has been 
                        <span class="font-black <?= $kpiTotals['current_ppm'] <= $targetPpm ? 'text-emerald-700' : 'text-red-600' ?>">
                            <?= $kpiTotals['current_ppm'] <= $targetPpm ? 'MAINTAINED UNDER TARGET' : 'ABOVE TARGET THRESHOLD' ?>
                        </span> 
                        for the period <span class="font-bold text-slate-900"><?= date('M Y', strtotime($startDate)) ?> &ndash; <?= date('M Y', strtotime($endDate)) ?></span>.
                    </p>
                </div>

                <!-- Lot Summary Table (Matching Slide Top Right Table) -->
                <div>
                    <h3 class="text-xs font-black text-slate-700 uppercase tracking-wider mb-2 flex items-center justify-between">
                        <span>REKAPITULASI LOT</span>
                        <span class="text-[10px] text-slate-400 font-normal">4 Bulan Terakhir</span>
                    </h3>
                    <div class="border border-slate-200 rounded-lg overflow-hidden">
                        <table class="w-full text-xs text-center border-collapse">
                            <thead>
                                <tr class="bg-slate-900 text-white font-bold text-[10px] uppercase">
                                    <th class="p-2 text-left pl-3">Item</th>
                                    <?php foreach ($recentMonthsSummary['months'] as $m): ?>
                                        <th class="p-2"><?= htmlspecialchars($m) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 font-mono">
                                <tr>
                                    <td class="p-2 font-sans font-bold text-slate-700 text-left pl-3">Lot OK</td>
                                    <?php foreach ($recentMonthsSummary['lot_ok'] as $ok): ?>
                                        <td class="p-2 text-emerald-600 font-bold"><?= number_format($ok) ?></td>
                                    <?php endforeach; ?>
                                </tr>
                                <tr>
                                    <td class="p-2 font-sans font-bold text-slate-700 text-left pl-3">Lot NG</td>
                                    <?php foreach ($recentMonthsSummary['lot_ng'] as $ng): ?>
                                        <td class="p-2 text-red-600 font-bold"><?= number_format($ng) ?></td>
                                    <?php endforeach; ?>
                                </tr>
                                <tr class="bg-slate-100 font-black">
                                    <td class="p-2 font-sans text-slate-900 text-left pl-3">Total Lot Inspect</td>
                                    <?php foreach ($recentMonthsSummary['total_lot'] as $tot): ?>
                                        <td class="p-2 text-slate-900"><?= number_format($tot) ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

        </div>

        <!-- BOTTOM SECTION: WORST DEFECT PIE (LEFT) & WORST PART TABLE (RIGHT) -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

            <!-- Bottom Left: I. Worst Defect OQC (Pie Chart + Breakdown) -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between mb-3 border-b border-slate-100 pb-2">
                        <h2 class="text-sm font-black text-slate-800 tracking-wide uppercase flex items-center gap-2">
                            <span class="text-red-600 font-black">I.</span> WORST DEFECT OQC
                        </h2>
                        <span class="text-xs bg-red-100 text-red-700 font-bold px-2 py-0.5 rounded-full">
                            TOP 10 WORST DEFECT
                        </span>
                    </div>

                    <?php if (empty($top10Defects)): ?>
                        <div class="py-12 text-center text-slate-400 text-xs bg-slate-50 rounded-lg border border-dashed border-slate-200">
                            Belum ada data rekaman defect pada periode ini.
                        </div>
                    <?php else: ?>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 items-center">
                            <!-- Pie Chart -->
                            <div class="relative h-48 w-full flex items-center justify-center">
                                <canvas id="chartDefectPie"></canvas>
                            </div>

                            <!-- List Breakdown -->
                            <div class="space-y-1.5 max-h-52 overflow-y-auto pr-1">
                                <?php foreach ($top10Defects as $idx => $d): 
                                    $clr = json_decode($pieColors)[$idx % 10]; ?>
                                    <div class="flex items-center justify-between text-xs p-1.5 rounded-md hover:bg-slate-50 transition-colors border border-slate-100">
                                        <div class="flex items-center space-x-2 min-w-0">
                                            <span class="w-3 h-3 rounded-full flex-shrink-0" style="background-color: <?= $clr ?>"></span>
                                            <span class="font-bold text-slate-700 truncate"><?= htmlspecialchars($d['name']) ?></span>
                                        </div>
                                        <div class="font-mono font-bold text-slate-900 flex-shrink-0 space-x-1">
                                            <span><?= number_format($d['count']) ?></span>
                                            <span class="text-[10px] text-slate-400 font-normal">(<?= $d['pct'] ?>%)</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Conclusion Bar (Matching Slide Bottom Left) -->
                <div class="mt-4 bg-blue-50 border-l-4 border-blue-600 p-2.5 rounded-r-lg">
                    <span class="text-xs font-black text-blue-900">Conclusion : </span>
                    <span class="text-xs font-semibold text-blue-800">
                        3 worst defects area <span class="font-black underline text-red-600"><?= htmlspecialchars($top3DefectListStr) ?></span>
                    </span>
                </div>
            </div>

            <!-- Bottom Right: II. Worst Part OQC (Table grouped by Top Defects) -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between mb-3 border-b border-slate-100 pb-2">
                        <h2 class="text-sm font-black text-slate-800 tracking-wide uppercase flex items-center gap-2">
                            <span class="text-red-600 font-black">II.</span> WORST PART OQC
                        </h2>
                        <span class="text-xs text-slate-400 font-medium">Breakdown per Defect Area</span>
                    </div>

                    <?php if (empty($worstPartsGrouped)): ?>
                        <div class="py-12 text-center text-slate-400 text-xs bg-slate-50 rounded-lg border border-dashed border-slate-200">
                            Belum ada data part terburuk pada periode ini.
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto max-h-56 overflow-y-auto border border-slate-200 rounded-lg">
                            <table class="w-full text-xs text-left border-collapse">
                                <thead class="bg-slate-800 text-white font-bold text-[10px] uppercase sticky top-0">
                                    <tr>
                                        <th class="p-2 text-center w-8">No</th>
                                        <th class="p-2">Part Name</th>
                                        <th class="p-2">Part Code</th>
                                        <th class="p-2">Model</th>
                                        <th class="p-2 text-center">Lot Case</th>
                                        <th class="p-2 text-center">NG Case</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200">
                                    <?php foreach ($worstPartsGrouped as $defectCategory => $parts): ?>
                                        <!-- Category Subheader -->
                                        <tr class="bg-red-50/80">
                                            <td colspan="6" class="p-1.5 px-3 font-black text-red-700 text-[11px] uppercase tracking-wider border-y border-red-200">
                                                🚨 <?= htmlspecialchars($defectCategory) ?>
                                            </td>
                                        </tr>
                                        <?php if (empty($parts)): ?>
                                            <tr>
                                                <td colspan="6" class="p-2 text-center text-slate-400 italic text-[11px]">Tidak ada rekaman part</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($parts as $pIdx => $p): ?>
                                                <tr class="hover:bg-slate-50 font-mono text-[11px]">
                                                    <td class="p-1.5 text-center font-sans font-bold text-slate-400"><?= $pIdx + 1 ?></td>
                                                    <td class="p-1.5 font-sans font-bold text-slate-800"><?= htmlspecialchars($p['part_name']) ?></td>
                                                    <td class="p-1.5 text-slate-600"><?= htmlspecialchars($p['part_code']) ?></td>
                                                    <td class="p-1.5 text-slate-600 font-sans"><?= htmlspecialchars($p['model']) ?></td>
                                                    <td class="p-1.5 text-center font-bold text-slate-700"><?= number_format($p['lot_case']) ?></td>
                                                    <td class="p-1.5 text-center font-black text-red-600 bg-red-50/50"><?= number_format($p['ng_case']) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Conclusion Bar (Matching Slide Bottom Right) -->
                <div class="mt-4 bg-blue-50 border-l-4 border-blue-600 p-2.5 rounded-r-lg">
                    <span class="text-xs font-black text-blue-900">Conclusion : </span>
                    <span class="text-xs font-semibold text-blue-800">
                        3 worst parts are <span class="font-bold text-slate-900"><?= htmlspecialchars($top3PartsSummaryStr) ?></span>
                    </span>
                </div>
            </div>

        </div>

    </div>

    <!-- MODAL EDIT TARGET PPM -->
    <div id="modalTargetPpm" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center hidden">
        <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6 border border-slate-100 transform transition-all">
            <div class="flex items-center justify-between mb-4 border-b border-slate-100 pb-3">
                <h3 class="text-base font-bold text-slate-800 flex items-center gap-2">
                    <span>⚙️</span> Pengaturan Target PPM
                </h3>
                <button type="button" onclick="document.getElementById('modalTargetPpm').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 font-bold">
                    ✕
                </button>
            </div>
            <form action="<?= base_url('modules/performance_report/update_target.php') ?>" method="POST" class="space-y-4">
                <input type="hidden" name="redirect_url" value="modules/performance_report/index.php?preset=<?= urlencode($presetFilter) ?>">
                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">
                        Batas Maksimal Target PPM
                    </label>
                    <div class="relative">
                        <input type="number" name="target_ppm" value="<?= $targetPpm ?>" required min="0" max="1000000"
                               class="form-input w-full rounded-xl border-slate-300 pr-16 font-mono font-bold text-slate-900 text-sm focus:border-red-500 focus:ring-red-500">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-bold text-slate-400">PPM</span>
                    </div>
                    <p class="text-[11px] text-slate-500 mt-1.5">
                        Default standar STI adalah <strong class="text-slate-800">10,000 PPM</strong>. Garis batas target di grafik akan otomatis menyesuaikan.
                    </p>
                </div>
                <div class="flex items-center justify-end space-x-2 pt-2">
                    <button type="button" onclick="document.getElementById('modalTargetPpm').classList.add('hidden')" class="btn-secondary text-xs px-4 py-2">
                        Batal
                    </button>
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white font-bold text-xs px-5 py-2 rounded-xl shadow-md transition-all">
                        Simpan Target
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Chart.js Setup -->
    <script src="<?= base_url('assets/js/vendor/chart.min.js') ?>"></script>
    <script>
    Chart.defaults.font.family = "'system-ui', '-apple-system', 'Segoe UI', sans-serif";

    // 1. OQC Performance Mixed Bar + Line Chart
    var ctxPerf = document.getElementById('chartOqcPerformance');
    if (ctxPerf) {
        new Chart(ctxPerf, {
            type: 'bar',
            data: {
                labels: <?= $chartMonths ?>,
                datasets: [
                    {
                        type: 'line',
                        label: 'Target PPM',
                        data: <?= $chartTarget ?>,
                        borderColor: '#ef4444',
                        borderWidth: 2.5,
                        pointBackgroundColor: '#ef4444',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        fill: false,
                        tension: 0
                    },
                    {
                        type: 'bar',
                        label: 'PPM Observed',
                        data: <?= $chartPpm ?>,
                        backgroundColor: '#10b981',
                        hoverBackgroundColor: '#059669',
                        borderRadius: 4,
                        barPercentage: 0.5
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        callbacks: {
                            label: function(c) {
                                return c.dataset.label + ': ' + Number(c.raw).toLocaleString() + ' PPM';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 10, weight: 'bold' }, color: '#475569' }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: '#f1f5f9' },
                        ticks: {
                            font: { size: 10 },
                            color: '#64748b',
                            callback: function(v) { return Number(v).toLocaleString() + ' PPM'; }
                        }
                    }
                }
            }
        });
    }

    // 2. Worst Defect Pie Chart
    var ctxPie = document.getElementById('chartDefectPie');
    if (ctxPie) {
        new Chart(ctxPie, {
            type: 'pie',
            data: {
                labels: <?= $pieLabels ?>,
                datasets: [{
                    data: <?= $pieCounts ?>,
                    backgroundColor: <?= $pieColors ?>,
                    borderWidth: 2,
                    borderColor: '#ffffff',
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(c) {
                                var total = c.dataset.data.reduce(function(a, b){ return a + b; }, 0);
                                var pct = Math.round((c.raw / total) * 100);
                                return ' ' + c.label + ': ' + Number(c.raw).toLocaleString() + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    }
    </script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
