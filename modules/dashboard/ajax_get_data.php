<?php
/**
 * AJAX Data Endpoint for Realtime Dashboard Live Sync
 * Fetches updated KPIs, chart datasets, and tables without full page reloads.
 */
@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';
require_once __DIR__ . '/../../config/summary_helper.php';

// Release PHP session lock immediately so other requests are not blocked
$hasAccess = can_access('dashboard');
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if (!$hasAccess) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$pdo = getDB();
if (!$pdo) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit;
}

// ── Filter Resolvers ────────────────────────────────────────────────────────
$selectedCustomer    = sanitize($_GET['customer'] ?? '');
$selectedUnit        = sanitize($_GET['unit'] ?? 'pcs');
if (!in_array($selectedUnit, ['pcs', 'lot'])) {
    $selectedUnit = 'pcs';
}

$selectedSampleBasis = sanitize($_GET['sample_basis'] ?? 'lot');
if (!in_array($selectedSampleBasis, ['lot', 'session'])) {
    $selectedSampleBasis = 'lot';
}

$selectedResult      = sanitize($_GET['result'] ?? 'rejected');
if (!in_array($selectedResult, ['rejected', 'passed'])) {
    $selectedResult = 'rejected';
}

$monthNames = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

$filterType      = sanitize($_GET['filter_type'] ?? '');
$selectedMonth   = isset($_GET['month']) && is_numeric($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$selectedYear    = isset($_GET['year']) && is_numeric($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$selectedTopRank = isset($_GET['top_rank']) && in_array((int)$_GET['top_rank'], [3, 5, 10]) ? (int)$_GET['top_rank'] : 3;

// Fetch distinct years
$dbYears = [];
try {
    $stmtYears = $pdo->query("SELECT DISTINCT YEAR(summary_date) AS y FROM oqc_daily_summary ORDER BY y ASC");
    $dbYears = array_values(array_filter(array_map('intval', $stmtYears->fetchAll(PDO::FETCH_COLUMN))));
} catch (Exception $e) {}
if (empty($dbYears)) {
    $dbYears = [(int)date('Y')];
}

if ($filterType === 'all_years') {
    $minYear = min($dbYears);
    $maxYear = max($dbYears);
    if ($minYear === $maxYear) {
        $minYear = $maxYear - 1;
    }
    $startDate = sprintf('%04d-01-01', $minYear);
    $endDate   = sprintf('%04d-12-31', $maxYear);
    $presetFilter = 'all_years';
    $trendSubtext = 'komparasi total per tahun (YoY)';
} elseif ($filterType === 'month_year') {
    $startDate = sprintf('%04d-%02d-01', $selectedYear, $selectedMonth);
    $endDate   = date('Y-m-t', strtotime($startDate));
    $presetFilter = 'month_year';
    $trendSubtext = 'per tanggal (' . ($monthNames[$selectedMonth] ?? $selectedMonth) . ' ' . $selectedYear . ')';
} elseif ($filterType === 'custom' || (!empty($_GET['start_date']) && !empty($_GET['end_date']))) {
    $startDate    = sanitize($_GET['start_date'] ?? date('Y-m-01'));
    $endDate      = sanitize($_GET['end_date'] ?? date('Y-m-d'));
    $presetFilter = 'custom';
    $trendSubtext = 'per tanggal';
} else {
    $presetFilter = sanitize($_GET['preset'] ?? 'bulanan');
    switch ($presetFilter) {
        case 'hari_ini':
            $startDate = date('Y-m-d');
            $endDate   = date('Y-m-d');
            $trendSubtext = 'per jam / hari';
            break;
        case 'mingguan':
            $startDate = date('Y-m-d', strtotime('monday this week'));
            $endDate   = date('Y-m-d');
            $trendSubtext = 'per hari';
            break;
        case 'tahunan':
            $startDate = date('Y-01-01');
            $endDate   = date('Y-m-d');
            $trendSubtext = 'per bulan';
            break;
        default:
            $presetFilter = 'bulanan';
            $startDate    = date('Y-m-01');
            $endDate      = date('Y-m-d');
            $trendSubtext = 'per tanggal';
    }
}

$unitText = ($selectedUnit === 'lot') ? 'Lot' : 'Pcs';

$kpi = [
    'total_inspected' => 0,
    'total_ng'        => 0,
    'ng_rate'         => 0,
    'pct_rate'        => 0,
    'ppm_rate'        => 0,
    'total_lot'       => 0,
    'pass_count'      => 0,
    'rejected_count'  => 0,
    'pass_rate'       => 0,
];

$trendLabels  = [];
$trendPPM     = [];
$trendNG      = [];
$trendSample  = [];
$trendPCT     = [];

$defectLabels = [];
$defectCounts = [];
$pieLabels    = [];
$pieCounts    = [];
$modelLabels  = [];
$modelCounts  = [];
$worstPartsGrouped = [];
$bestPartsPassed   = [];
$top3PartsSummaryStr = '';

$edNext = date('Y-m-d', strtotime($endDate . ' +1 day'));
$p = [':sd' => $startDate, ':ed_next' => $edNext];
$sumCustCond = '';
if ($selectedCustomer !== '') {
    $sumCustCond = " AND customer = :cust ";
    $p[':cust'] = $selectedCustomer;
}

try {
    // 1. KPI Rollup
    $stmtKpi = $pdo->prepare("SELECT 
                                COALESCE(SUM(total_sample_size), 0) AS total_sample_size,
                                COALESCE(SUM(total_sample_size_lot), 0) AS total_sample_size_lot,
                                COALESCE(SUM(total_sample_size_session), 0) AS total_sample_size_session,
                                COALESCE(SUM(total_lots), 0) AS total_lots,
                                COALESCE(SUM(passed_lots), 0) AS passed_lots,
                                COALESCE(SUM(rejected_lots), 0) AS rejected_lots,
                                COALESCE(SUM(total_ng_pcs), 0) AS total_ng_pcs,
                                COALESCE(SUM(total_ng_samples), 0) AS total_ng_samples
                              FROM oqc_daily_summary
                              WHERE summary_date >= :sd AND summary_date < :ed_next {$sumCustCond}");
    $stmtKpi->execute($p);
    $rowKpi = $stmtKpi->fetch(PDO::FETCH_ASSOC);

    $totalSampleLot     = (int)($rowKpi['total_sample_size_lot'] ?? 0);
    if ($totalSampleLot <= 0) $totalSampleLot = (int)($rowKpi['total_sample_size'] ?? 0);
    $totalSampleSession = (int)($rowKpi['total_sample_size_session'] ?? 0);
    if ($totalSampleSession <= 0) $totalSampleSession = (int)($rowKpi['total_sample_size'] ?? 0);

    $effectiveSampleSize = ($selectedSampleBasis === 'session') ? $totalSampleSession : $totalSampleLot;

    $totalLots       = (int)($rowKpi['total_lots'] ?? 0);
    $passedLots      = (int)($rowKpi['passed_lots'] ?? 0);
    $rejectedLots    = (int)($rowKpi['rejected_lots'] ?? 0);
    $totalNgPcs      = (int)($rowKpi['total_ng_pcs'] ?? 0);
    $totalNgSamples  = (int)($rowKpi['total_ng_samples'] ?? 0);
    $effectiveNgPcs  = max($totalNgPcs, $totalNgSamples);

    $kpi['total_lot']       = $totalLots;
    $kpi['pass_count']      = $passedLots;
    $kpi['rejected_count']  = $rejectedLots;
    $kpi['pass_rate']       = $totalLots > 0 ? round(($passedLots / $totalLots) * 100, 1) : 0;

    if ($selectedUnit === 'lot') {
        $kpi['total_inspected'] = $totalLots;
        $kpi['total_ng']        = $rejectedLots;
        $kpi['pct_rate']        = $totalLots > 0 ? round(($rejectedLots / $totalLots) * 100, 2) : 0;
        $kpi['ppm_rate']        = $totalLots > 0 ? round(($rejectedLots / $totalLots) * 1000000, 1) : 0;
        $kpi['ng_rate']         = $kpi['pct_rate'];
    } else {
        $kpi['total_inspected'] = $effectiveSampleSize;
        $kpi['total_ng']        = $effectiveNgPcs;
        $kpi['ppm_rate']        = $effectiveSampleSize > 0 ? round(($effectiveNgPcs / $effectiveSampleSize) * 1000000, 1) : 0;
        $kpi['pct_rate']        = $effectiveSampleSize > 0 ? round(($effectiveNgPcs / $effectiveSampleSize) * 100, 2) : 0;
        $kpi['ng_rate']         = $kpi['ppm_rate'];
    }

    // 2. Trend Calculations
    $daysDiff = (strtotime($endDate) - strtotime($startDate)) / 86400;

    if ($presetFilter === 'all_years') {
        $trendMap = [];
        $yearsToDisplay = range($minYear, $maxYear);
        foreach ($yearsToDisplay as $yr) {
            $trendMap[(string)$yr] = ['label' => (string)$yr, 'sample' => 0, 'ng' => 0, 'ppm' => 0, 'pct' => 0];
        }

        $stmtTrend = $pdo->prepare("SELECT YEAR(summary_date) AS yr_key, 
                                           COALESCE(SUM(total_sample_size), 0) AS total_sample_size,
                                           COALESCE(SUM(total_sample_size_lot), 0) AS total_sample_size_lot,
                                           COALESCE(SUM(total_sample_size_session), 0) AS total_sample_size_session,
                                           COALESCE(SUM(total_lots), 0) AS total_lots,
                                           COALESCE(SUM(passed_lots), 0) AS passed_lots,
                                           COALESCE(SUM(rejected_lots), 0) AS rejected_lots,
                                           COALESCE(SUM(total_ng_pcs), 0) AS total_ng_pcs,
                                           COALESCE(SUM(total_ng_samples), 0) AS total_ng_samples
                                    FROM oqc_daily_summary
                                    WHERE summary_date >= :sd AND summary_date < :ed_next {$sumCustCond}
                                    GROUP BY yr_key");
        $stmtTrend->execute($p);
        foreach ($stmtTrend->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $yk = (string)$r['yr_key'];
            if (isset($trendMap[$yk])) {
                if ($selectedUnit === 'lot') {
                    $trendMap[$yk]['sample'] = (int)$r['total_lots'];
                    $trendMap[$yk]['ng']     = (int)$r['rejected_lots'];
                } else {
                    $sLot  = (int)$r['total_sample_size_lot'] ?: (int)$r['total_sample_size'];
                    $sSess = (int)$r['total_sample_size_session'] ?: (int)$r['total_sample_size'];
                    $trendMap[$yk]['sample'] = ($selectedSampleBasis === 'session') ? $sSess : $sLot;
                    $trendMap[$yk]['ng']     = max((int)$r['total_ng_pcs'], (int)$r['total_ng_samples']);
                }
            }
        }
    } elseif ($presetFilter === 'tahunan' || $daysDiff > 90) {
        $trendMap     = [];
        $startPeriod  = new DateTime(date('Y-m-01', strtotime($startDate)));
        $endPeriod    = new DateTime(date('Y-m-01', strtotime($endDate)));
        $endPeriod->modify('+1 month');
        $interval     = DateInterval::createFromDateString('1 month');
        $period       = new DatePeriod($startPeriod, $interval, $endPeriod);

        foreach ($period as $dt) {
            $key = $dt->format('Y-m');
            $lbl = $dt->format('M Y');
            $trendMap[$key] = ['label' => $lbl, 'sample' => 0, 'ng' => 0, 'ppm' => 0, 'pct' => 0];
        }

        $stmtTrend = $pdo->prepare("SELECT DATE_FORMAT(summary_date, '%Y-%m') AS month_key, 
                                           COALESCE(SUM(total_sample_size), 0) AS total_sample_size,
                                           COALESCE(SUM(total_sample_size_lot), 0) AS total_sample_size_lot,
                                           COALESCE(SUM(total_sample_size_session), 0) AS total_sample_size_session,
                                           COALESCE(SUM(total_lots), 0) AS total_lots,
                                           COALESCE(SUM(passed_lots), 0) AS passed_lots,
                                           COALESCE(SUM(rejected_lots), 0) AS rejected_lots,
                                           COALESCE(SUM(total_ng_pcs), 0) AS total_ng_pcs,
                                           COALESCE(SUM(total_ng_samples), 0) AS total_ng_samples
                                    FROM oqc_daily_summary
                                    WHERE summary_date >= :sd AND summary_date < :ed_next {$sumCustCond}
                                    GROUP BY month_key");
        $stmtTrend->execute($p);
        foreach ($stmtTrend->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $mk = $r['month_key'];
            if (isset($trendMap[$mk])) {
                if ($selectedUnit === 'lot') {
                    $trendMap[$mk]['sample'] = (int)$r['total_lots'];
                    $trendMap[$mk]['ng']     = (int)$r['rejected_lots'];
                } else {
                    $sLot  = (int)$r['total_sample_size_lot'] ?: (int)$r['total_sample_size'];
                    $sSess = (int)$r['total_sample_size_session'] ?: (int)$r['total_sample_size'];
                    $trendMap[$mk]['sample'] = ($selectedSampleBasis === 'session') ? $sSess : $sLot;
                    $trendMap[$mk]['ng']     = max((int)$r['total_ng_pcs'], (int)$r['total_ng_samples']);
                }
            }
        }
    } else {
        $trendMap     = [];
        $startPeriod  = new DateTime($startDate);
        $endPeriod    = new DateTime($endDate);
        $endPeriod->modify('+1 day');
        $interval     = DateInterval::createFromDateString('1 day');
        $period       = new DatePeriod($startPeriod, $interval, $endPeriod);

        foreach ($period as $dt) {
            $key = $dt->format('Y-m-d');
            $lbl = $dt->format('d M');
            $trendMap[$key] = ['label' => $lbl, 'sample' => 0, 'ng' => 0, 'ppm' => 0, 'pct' => 0];
        }

        $stmtTrend = $pdo->prepare("SELECT summary_date AS tgl, 
                                           COALESCE(SUM(total_sample_size), 0) AS total_sample_size,
                                           COALESCE(SUM(total_sample_size_lot), 0) AS total_sample_size_lot,
                                           COALESCE(SUM(total_sample_size_session), 0) AS total_sample_size_session,
                                           COALESCE(SUM(total_lots), 0) AS total_lots,
                                           COALESCE(SUM(passed_lots), 0) AS passed_lots,
                                           COALESCE(SUM(rejected_lots), 0) AS rejected_lots,
                                           COALESCE(SUM(total_ng_pcs), 0) AS total_ng_pcs,
                                           COALESCE(SUM(total_ng_samples), 0) AS total_ng_samples
                                    FROM oqc_daily_summary
                                    WHERE summary_date >= :sd AND summary_date < :ed_next {$sumCustCond}
                                    GROUP BY summary_date");
        $stmtTrend->execute($p);
        foreach ($stmtTrend->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $tgl = $r['tgl'];
            if (isset($trendMap[$tgl])) {
                if ($selectedUnit === 'lot') {
                    $trendMap[$tgl]['sample'] = (int)$r['total_lots'];
                    $trendMap[$tgl]['ng']     = (int)$r['rejected_lots'];
                } else {
                    $sLot  = (int)$r['total_sample_size_lot'] ?: (int)$r['total_sample_size'];
                    $sSess = (int)$r['total_sample_size_session'] ?: (int)$r['total_sample_size'];
                    $trendMap[$tgl]['sample'] = ($selectedSampleBasis === 'session') ? $sSess : $sLot;
                    $trendMap[$tgl]['ng']     = max((int)$r['total_ng_pcs'], (int)$r['total_ng_samples']);
                }
            }
        }
    }

    foreach ($trendMap as $item) {
        $sp  = (int)$item['sample'];
        $ng  = (int)$item['ng'];
        $cnt = ($selectedResult === 'passed') ? max(0, $sp - $ng) : $ng;
        $ppm = ($sp > 0) ? round(($cnt / $sp) * 1000000, 1) : 0;
        $pct = ($sp > 0) ? round(($cnt / $sp) * 100, 2) : 0;

        $trendLabels[] = $item['label'];
        $trendSample[] = $sp;
        $trendNG[]     = $ng;
        $trendPPM[]    = $ppm;
        $trendPCT[]    = $pct;
    }

    // 3. Defect Types Breakdown
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
        $colDef = ($selectedUnit === 'lot') ? "dds.lot_count" : "dds.qty_ng";
        $stmt = $pdo->prepare("SELECT dt.name, COALESCE(SUM({$colDef}), 0) AS total 
                                FROM oqc_daily_defect_summary dds 
                                INNER JOIN defect_types dt ON dds.defect_type_id=dt.id 
                                WHERE dds.summary_date >= :sd AND dds.summary_date < :ed_next {$sumCustCond}
                                GROUP BY dt.id, dt.name 
                                ORDER BY total DESC 
                                LIMIT {$selectedTopRank}");
        $stmt->execute($p);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $defectLabels[] = $r['name'];
            $defectCounts[] = (int)$r['total'];
        }
    }

    // 4. Defect Breakdown Timeline Matrix (Stacked Bar Chart)
    $stackedPeriodKeys   = array_keys($trendMap);
    $stackedPeriodLabels = [];
    foreach ($trendMap as $k => $v) {
        $stackedPeriodLabels[$k] = $v['label'];
    }

    if ($presetFilter === 'all_years') {
        $dateExpr = "CAST(YEAR(dds.summary_date) AS CHAR)";
    } elseif ($presetFilter === 'tahunan' || $daysDiff > 90) {
        $dateExpr = "DATE_FORMAT(dds.summary_date, '%Y-%m')";
    } else {
        $dateExpr = "DATE_FORMAT(dds.summary_date, '%Y-%m-%d')";
    }

    $metricCol = ($selectedUnit === 'lot') ? "COALESCE(SUM(dds.lot_count), 0)" : "COALESCE(SUM(dds.qty_ng), 0)";

    $stmtDdsTimeline = $pdo->prepare("SELECT {$dateExpr} AS date_key, 
                                             dt.name AS defect_name, 
                                             {$metricCol} AS total_defect
                                      FROM oqc_daily_defect_summary dds
                                      INNER JOIN defect_types dt ON dds.defect_type_id = dt.id
                                      WHERE dds.summary_date >= :sd AND dds.summary_date < :ed_next {$sumCustCond}
                                      GROUP BY date_key, dt.id, dt.name
                                      ORDER BY date_key ASC, total_defect DESC");
    $stmtDdsTimeline->execute($p);
    $rawDdsRows = $stmtDdsTimeline->fetchAll(PDO::FETCH_ASSOC);

    $stmtDdsParts = $pdo->prepare("SELECT {$dateExpr} AS date_key, 
                                          dt.name AS defect_name, 
                                          COALESCE(mp.part_code, '-') AS part_code,
                                          COALESCE(mp.part_name, 'UNKNOWN PART') AS part_name,
                                          {$metricCol} AS part_defect_qty
                                   FROM oqc_daily_defect_summary dds
                                   INNER JOIN defect_types dt ON dds.defect_type_id = dt.id
                                   LEFT JOIN master_parts mp ON dds.part_id = mp.id
                                   WHERE dds.summary_date >= :sd AND dds.summary_date < :ed_next {$sumCustCond}
                                   GROUP BY date_key, dt.id, dt.name, mp.id, mp.part_code, mp.part_name
                                   HAVING part_defect_qty > 0
                                   ORDER BY date_key ASC, part_defect_qty DESC");
    $stmtDdsParts->execute($p);
    $rawDdsPartRows = $stmtDdsParts->fetchAll(PDO::FETCH_ASSOC);

    $defectPartsMatrix = [];
    foreach ($rawDdsPartRows as $pr) {
        $dk   = (string)$pr['date_key'];
        $dnm  = (string)$pr['defect_name'];
        $defectPartsMatrix[$dk][$dnm][] = [
            'code' => $pr['part_code'],
            'name' => $pr['part_name'],
            'qty'  => (int)$pr['part_defect_qty']
        ];
    }

    $distinctDefectTypes = [];
    $defectMatrix        = [];
    $defectDateTotals    = [];
    $defectTypeTotals    = [];

    foreach ($rawDdsRows as $dr) {
        $dk   = (string)$dr['date_key'];
        $dnm  = (string)$dr['defect_name'];
        $qty  = (int)$dr['total_defect'];
        if ($qty <= 0) continue;

        if (!in_array($dnm, $distinctDefectTypes)) {
            $distinctDefectTypes[] = $dnm;
        }
        if (!isset($defectMatrix[$dk])) {
            $defectMatrix[$dk] = [];
        }
        $defectMatrix[$dk][$dnm] = ($defectMatrix[$dk][$dnm] ?? 0) + $qty;
        $defectDateTotals[$dk]   = ($defectDateTotals[$dk] ?? 0) + $qty;
        $defectTypeTotals[$dnm]  = ($defectTypeTotals[$dnm] ?? 0) + $qty;
    }

    usort($distinctDefectTypes, function($a, $b) use ($defectTypeTotals) {
        return ($defectTypeTotals[$b] ?? 0) <=> ($defectTypeTotals[$a] ?? 0);
    });

    $STACKED_DEFECT_PALETTE = ['#f97316', '#64748b', '#eab308', '#0284c7', '#8b5cf6', '#ec4899', '#10b981', '#f43f5e', '#6366f1', '#14b8a6'];

    $activeDefectPeriodKeys = [];
    if ($selectedResult === 'passed') {
        foreach ($stackedPeriodKeys as $dk) {
            if (($trendMap[$dk]['sample'] ?? 0) > 0) {
                $activeDefectPeriodKeys[] = $dk;
            }
        }
    } else {
        foreach ($stackedPeriodKeys as $dk) {
            if (($defectDateTotals[$dk] ?? 0) > 0) {
                $activeDefectPeriodKeys[] = $dk;
            }
        }
    }

    // ── Per-Day Top N Defect Selection (Tanpa 'Others', Defect Asli Hari Tersebut) ──
    $selectedDefectsPerDay = []; // [dk => [defect_name, ...]]
    $activeDailyDefectsMap = []; // [defect_name => true]

    foreach ($activeDefectPeriodKeys as $dk) {
        $dayDefects = $defectMatrix[$dk] ?? [];
        arsort($dayDefects);
        // Ambil Top N defect terbesar yang muncul di hari ini
        $topForDay = array_slice(array_keys($dayDefects), 0, $selectedTopRank);
        $selectedDefectsPerDay[$dk] = $topForDay;
        foreach ($topForDay as $dnm) {
            $activeDailyDefectsMap[$dnm] = true;
        }
    }

    // Urutkan jenis defect yang terpilih berdasarkan total frekuensi kemunculannya
    $topNDefectTypes = array_keys($activeDailyDefectsMap);
    usort($topNDefectTypes, function($a, $b) use ($defectTypeTotals) {
        return ($defectTypeTotals[$b] ?? 0) <=> ($defectTypeTotals[$a] ?? 0);
    });

    $otherNDefectTypes = []; // Hapus kategori Others

    $stackedChartLabels = [];
    foreach ($activeDefectPeriodKeys as $dk) {
        $stackedChartLabels[] = $stackedPeriodLabels[$dk] ?? $dk;
    }

    $stackedChartDatasetsPcs = [];
    $stackedChartDatasetsPpm = [];

    if ($selectedResult === 'passed') {
        $passedDataPcs   = [];
        $rejectedDataPcs = [];
        $passedDataPpm   = [];
        $rejectedDataPpm = [];

        foreach ($activeDefectPeriodKeys as $dk) {
            $sample = (int)($trendMap[$dk]['sample'] ?? 0);
            $ng     = (int)($trendMap[$dk]['ng'] ?? 0);
            $passed = max(0, $sample - $ng);

            $passedDataPcs[]   = $passed;
            $rejectedDataPcs[] = $ng;

            $ppmP = ($sample > 0) ? round(($passed / $sample) * 1000000, 1) : 0;
            $ppmR = ($sample > 0) ? round(($ng / $sample) * 1000000, 1) : 0;
            $passedDataPpm[]   = $ppmP;
            $rejectedDataPpm[] = $ppmR;
        }

        $stackedChartDatasetsPcs[] = [
            'label'           => 'Passed (' . $unitText . ')',
            'data'            => $passedDataPcs,
            'backgroundColor' => '#059669',
            'stack'           => 'res_stack',
            'borderRadius'    => 3
        ];
        $stackedChartDatasetsPcs[] = [
            'label'           => 'Rejected (' . $unitText . ')',
            'data'            => $rejectedDataPcs,
            'backgroundColor' => '#dc2626',
            'stack'           => 'res_stack',
            'borderRadius'    => 3
        ];

        $stackedChartDatasetsPpm[] = [
            'label'           => 'Passed (PPM)',
            'data'            => $passedDataPpm,
            'backgroundColor' => '#059669',
            'stack'           => 'res_stack',
            'borderRadius'    => 3
        ];
        $stackedChartDatasetsPpm[] = [
            'label'           => 'Rejected (PPM)',
            'data'            => $rejectedDataPpm,
            'backgroundColor' => '#dc2626',
            'stack'           => 'res_stack',
            'borderRadius'    => 3
        ];
    } else {
        foreach ($topNDefectTypes as $idx => $dname) {
            $c = $STACKED_DEFECT_PALETTE[$idx % count($STACKED_DEFECT_PALETTE)];
            $dataPtsPcs  = [];
            $dataPtsPpm  = [];
            $partMetaPts = [];

            foreach ($activeDefectPeriodKeys as $dk) {
                $isTopThisDay = in_array($dname, $selectedDefectsPerDay[$dk] ?? []);
                $qty    = $isTopThisDay ? (int)($defectMatrix[$dk][$dname] ?? 0) : 0;
                $sample = (int)($trendMap[$dk]['sample'] ?? 0);
                $ppm    = ($sample > 0) ? round(($qty / $sample) * 1000000, 1) : 0;

                $dataPtsPcs[]  = $qty;
                $dataPtsPpm[]  = $ppm;
                $partMetaPts[] = $isTopThisDay ? ($defectPartsMatrix[$dk][$dname] ?? []) : [];
            }

            $stackedChartDatasetsPcs[] = [
                'label'           => $dname,
                'data'            => $dataPtsPcs,
                'raw_pcs'         => $dataPtsPcs,
                'part_meta'       => $partMetaPts,
                'backgroundColor' => $c,
                'stack'           => 'defect_stack',
                'borderRadius'    => 3
            ];
            $stackedChartDatasetsPpm[] = [
                'label'           => $dname,
                'data'            => $dataPtsPpm,
                'raw_pcs'         => $dataPtsPcs,
                'part_meta'       => $partMetaPts,
                'backgroundColor' => $c,
                'stack'           => 'defect_stack',
                'borderRadius'    => 3
            ];
        }
    }

    // 5. Top Parts Distribution
    if ($selectedResult === 'passed') {
        $stmt = $pdo->prepare("SELECT mp.part_code, mp.part_name, 
                                      SUM(s.passed_lots) AS total_ng 
                               FROM oqc_daily_summary s 
                               LEFT JOIN master_parts mp ON s.part_id=mp.id 
                               WHERE s.summary_date >= :sd AND s.summary_date < :ed_next {$sumCustCond} 
                               GROUP BY s.part_id, mp.part_code, mp.part_name 
                               HAVING total_ng > 0
                               ORDER BY total_ng DESC 
                               LIMIT {$selectedTopRank}");
    } else {
        $colPart = ($selectedUnit === 'lot') ? "SUM(s.rejected_lots)" : "SUM(COALESCE(NULLIF(s.total_ng_pcs, 0), s.total_ng_samples, 0))";
        $stmt = $pdo->prepare("SELECT mp.part_code, mp.part_name, 
                                      {$colPart} AS total_ng 
                               FROM oqc_daily_summary s 
                               LEFT JOIN master_parts mp ON s.part_id=mp.id 
                               WHERE s.summary_date >= :sd AND s.summary_date < :ed_next {$sumCustCond} 
                               GROUP BY s.part_id, mp.part_code, mp.part_name 
                               HAVING total_ng > 0
                               ORDER BY total_ng DESC 
                               LIMIT {$selectedTopRank}");
    }
    $stmt->execute($p);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pieLabels[] = $r['part_name'] ?? $r['part_code'] ?? 'Unknown';
        $pieCounts[] = (int)$r['total_ng'];
    }

    // 6. Top Models Distribution
    if ($selectedResult === 'passed') {
        $stmtM = $pdo->prepare("SELECT COALESCE(m.name, mp.model, 'NO MODEL') AS model_name, 
                                       SUM(s.passed_lots) AS total_ng 
                                FROM oqc_daily_summary s 
                                LEFT JOIN master_parts mp ON s.part_id=mp.id 
                                LEFT JOIN master_models m ON s.model_id=m.id 
                                WHERE s.summary_date >= :sd AND s.summary_date < :ed_next {$sumCustCond} 
                                GROUP BY m.id, model_name 
                                HAVING total_ng > 0
                                ORDER BY total_ng DESC 
                                LIMIT {$selectedTopRank}");
    } else {
        $colModel = ($selectedUnit === 'lot') ? "SUM(s.rejected_lots)" : "SUM(COALESCE(NULLIF(s.total_ng_pcs, 0), s.total_ng_samples, 0))";
        $stmtM = $pdo->prepare("SELECT COALESCE(m.name, mp.model, 'NO MODEL') AS model_name, 
                                       {$colModel} AS total_ng 
                                FROM oqc_daily_summary s 
                                LEFT JOIN master_parts mp ON s.part_id=mp.id 
                                LEFT JOIN master_models m ON s.model_id=m.id 
                                WHERE s.summary_date >= :sd AND s.summary_date < :ed_next {$sumCustCond} 
                                GROUP BY m.id, model_name 
                                HAVING total_ng > 0
                                ORDER BY total_ng DESC 
                                LIMIT {$selectedTopRank}");
    }
    $stmtM->execute($p);
    foreach ($stmtM->fetchAll(PDO::FETCH_ASSOC) as $rM) {
        $modelLabels[] = $rM['model_name'];
        $modelCounts[] = (int)$rM['total_ng'];
    }

    // 7. Worst Parts Table Data
    if ($selectedResult === 'passed') {
        $stmtBest = $pdo->prepare("SELECT 
                    COALESCE(mp.part_name, 'UNKNOWN PART') AS part_name,
                    COALESCE(mp.part_code, '-') AS part_code,
                    COALESCE(m.name, mp.model, '-') AS model,
                    SUM(s.total_lots) AS lot_case,
                    SUM(s.passed_lots) AS passed_lot_case
                FROM oqc_daily_summary s
                LEFT JOIN master_parts mp ON s.part_id = mp.id
                LEFT JOIN master_models m ON s.model_id = m.id
                WHERE s.summary_date >= :sd AND s.summary_date < :ed_next {$sumCustCond} 
                GROUP BY mp.id, mp.part_name, mp.part_code, m.name, mp.model
                HAVING passed_lot_case > 0
                ORDER BY passed_lot_case DESC, lot_case DESC
                LIMIT 5");
        $stmtBest->execute($p);
        $bestPartsPassed = $stmtBest->fetchAll(PDO::FETCH_ASSOC);

        $top3BestNames = [];
        foreach ($bestPartsPassed as $bP) {
            if (!empty($bP['part_name'])) {
                $top3BestNames[] = $bP['part_name'];
                if (count($top3BestNames) >= 3) break;
            }
        }
        $top3PartsSummaryStr = !empty($top3BestNames) ? implode(', ', $top3BestNames) : 'Belum Ada Data Sesi Passed';
    } else {
        $stmtWPDefs = $pdo->prepare("SELECT dt.name 
                                     FROM oqc_daily_defect_summary dds 
                                     INNER JOIN defect_types dt ON dds.defect_type_id=dt.id 
                                     WHERE dds.summary_date >= :sd AND dds.summary_date < :ed_next {$sumCustCond} 
                                     GROUP BY dt.id, dt.name 
                                     ORDER BY COALESCE(SUM(dds.qty_ng),0) DESC 
                                     LIMIT 3");
        $stmtWPDefs->execute($p);
        $top3DefectNames = $stmtWPDefs->fetchAll(PDO::FETCH_COLUMN);

        foreach ($top3DefectNames as $defName) {
            $pDef = array_merge($p, [':defname' => $defName]);
            $stmtWP = $pdo->prepare("SELECT 
                        COALESCE(mp.part_name, 'UNKNOWN PART') AS part_name,
                        COALESCE(mp.part_code, '-') AS part_code,
                        COALESCE(m.name, mp.model, '-') AS model,
                        SUM(dds.lot_count) AS lot_case,
                        SUM(dds.qty_ng) AS ng_case
                    FROM oqc_daily_defect_summary dds
                    INNER JOIN defect_types dt ON dds.defect_type_id = dt.id
                    LEFT JOIN master_parts mp ON dds.part_id = mp.id
                    LEFT JOIN master_models m ON mp.model_id = m.id
                    WHERE dt.name = :defname
                      AND dds.summary_date >= :sd AND dds.summary_date < :ed_next {$sumCustCond} 
                    GROUP BY mp.id, mp.part_name, mp.part_code, m.name, mp.model
                    ORDER BY ng_case DESC, lot_case DESC
                    LIMIT 3");
            $stmtWP->execute($pDef);
            $worstPartsGrouped[$defName] = $stmtWP->fetchAll(PDO::FETCH_ASSOC);
        }

        $top3PartsNames = [];
        foreach ($worstPartsGrouped as $dName => $parts) {
            if (!empty($parts[0]['part_name'])) {
                $top3PartsNames[] = $parts[0]['part_name'] . " (" . strtolower($dName) . ")";
            }
        }
        $top3PartsSummaryStr = !empty($top3PartsNames) ? implode(', ', $top3PartsNames) : 'Belum Ada Part Reject';
    }

    // 8. Re-compute version fingerprint
    $stmtSum = $pdo->prepare("SELECT 
        COALESCE(MAX(id), 0) AS max_sum_id,
        COALESCE(MAX(updated_at), '1970-01-01') AS last_sum_update,
        COUNT(id) AS sum_count
    FROM oqc_daily_summary
    WHERE summary_date >= :sd AND summary_date < :ed_next {$sumCustCond}");
    $stmtSum->execute($p);
    $rSum = $stmtSum->fetch(PDO::FETCH_ASSOC);

    $stmtSess = $pdo->prepare("SELECT 
        COALESCE(MAX(id), 0) AS max_sess_id,
        COUNT(id) AS sess_count
    FROM inspection_sessions
    WHERE started_at >= :sd AND started_at < :ed_next");
    $stmtSess->execute([':sd' => $startDate, ':ed_next' => $edNext]);
    $rSess = $stmtSess->fetch(PDO::FETCH_ASSOC);

    // Get target PPM setting
    $targetPpm = 0;
    try {
        $stmtTgt = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key = 'dashboard_target_ppm' LIMIT 1");
        $targetPpm = (float)($stmtTgt->fetchColumn() ?: 0);
    } catch (Exception $e) {}

    $rawToken = implode('|', [
        $rSum['max_sum_id'] ?? 0,
        $rSum['last_sum_update'] ?? '',
        $rSum['sum_count'] ?? 0,
        $rSess['max_sess_id'] ?? 0,
        $rSess['sess_count'] ?? 0,
        $startDate,
        $endDate,
        $selectedCustomer,
        $targetPpm
    ]);
    $currentVersion = md5($rawToken);

    // Return complete payload
    echo json_encode([
        'status'         => 'success',
        'version'        => $currentVersion,
        'server_time'    => date('H:i:s'),
        'unit'           => $selectedUnit,
        'unit_text'      => $unitText,
        'target_ppm'     => $targetPpm,
        'sample_basis'   => $selectedSampleBasis,
        'is_passed'      => ($selectedResult === 'passed'),
        'kpi'            => $kpi,
        'trend'          => [
            'labels'  => $trendLabels,
            'sample'  => $trendSample,
            'ng'      => $trendNG,
            'ppm'     => $trendPPM,
            'pct'     => $trendPCT,
            'subtext' => $trendSubtext
        ],
        'defect_types'   => [
            'labels' => $defectLabels,
            'counts' => $defectCounts
        ],
        'stacked'        => [
            'labels'       => $stackedChartLabels,
            'datasets_pcs' => $stackedChartDatasetsPcs,
            'datasets_ppm' => $stackedChartDatasetsPpm,
            'matrix'       => $defectMatrix,
            'date_totals'  => $defectDateTotals,
            'distinct_defs'=> $topNDefectTypes,
            'period_keys'  => $activeDefectPeriodKeys,
            'period_labels'=> $stackedPeriodLabels
        ],
        'pie_part'       => [
            'labels' => $pieLabels,
            'counts' => $pieCounts
        ],
        'pie_model'      => [
            'labels' => $modelLabels,
            'counts' => $modelCounts
        ],
        'worst_parts'    => [
            'is_passed'   => ($selectedResult === 'passed'),
            'summary_str' => $top3PartsSummaryStr,
            'best'        => $bestPartsPassed,
            'grouped'     => $worstPartsGrouped
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Failed to query dashboard live data: ' . $e->getMessage()
    ]);
}
