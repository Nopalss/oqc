<?php
$breadcrumbCategory = "OPERASIONAL";
$pageTitle          = "Dashboard Laporan";
$pageSubtitle       = "Rekap hasil inspeksi outgoing quality control — PT. Surya Technology Industri";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

require_menu_access('dashboard');

$pdo = getDB();

// ── Filter Resolvers (Date, Customer, Unit, Sample Basis & Result) ───────────
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

// ── Session Cache untuk metadata ringan (years & customers) ─────────────────
// Cache per-jam agar tidak query DB setiap page load
$_metaCacheKey = 'oqc_dash_meta_' . date('YmdH');
if (!isset($_SESSION[$_metaCacheKey]) && $pdo) {
    $_cache = [];
    try {
        $stmtYears = $pdo->query("SELECT DISTINCT YEAR(started_at) AS y FROM inspection_sessions WHERE started_at IS NOT NULL ORDER BY y ASC");
        $_cache['years'] = array_values(array_filter(array_map('intval', $stmtYears->fetchAll(PDO::FETCH_COLUMN))));
    } catch (Exception $e) { $_cache['years'] = []; }
    try {
        $stmtCust = $pdo->query("SELECT DISTINCT name FROM master_customers UNION SELECT DISTINCT customer FROM kanban_items WHERE customer IS NOT NULL AND customer != '' ORDER BY name ASC");
        $_cache['customers'] = array_values(array_filter(array_map('trim', $stmtCust->fetchAll(PDO::FETCH_COLUMN))));
    } catch (Exception $e) { $_cache['customers'] = []; }
    $_SESSION[$_metaCacheKey] = $_cache;
    // Hapus cache lama (lebih dari 2 jam yang lalu)
    foreach ($_SESSION as $_k => $_v) {
        if (strpos($_k, 'oqc_dash_meta_') === 0 && $_k !== $_metaCacheKey) {
            unset($_SESSION[$_k]);
        }
    }
}
$_meta = $_SESSION[$_metaCacheKey] ?? ['years' => [], 'customers' => []];

$dbYears = !empty($_meta['years']) ? $_meta['years'] : [(int)date('Y')];

$availableYears = $dbYears;
$currentYear = (int)date('Y');
if (!in_array($currentYear, $availableYears)) {
    $availableYears[] = $currentYear;
}
rsort($availableYears);
$availableYears = array_values(array_unique(array_map('intval', $availableYears)));

$filterType = sanitize($_GET['filter_type'] ?? '');
$selectedMonth = isset($_GET['month']) && is_numeric($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$selectedYear  = isset($_GET['year']) && is_numeric($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$selectedTopRank = isset($_GET['top_rank']) && in_array((int)$_GET['top_rank'], [3, 5, 10]) ? (int)$_GET['top_rank'] : 3;

if ($filterType === 'all_years') {
    $minYear = min($dbYears);
    $maxYear = max($dbYears);
    if ($minYear === $maxYear) {
        $minYear = $maxYear - 1;
    }
    $startDate = sprintf('%04d-01-01', $minYear);
    $endDate   = sprintf('%04d-12-31', $maxYear);
    $presetFilter = 'all_years';
    $filterTitleDesc = ($minYear !== $maxYear) ? ("Komparasi Semua Tahun (" . $minYear . " – " . $maxYear . ")") : ("Komparasi Tahunan (" . $minYear . ")");
    $trendSubtext = 'komparasi total per tahun (YoY)';
} elseif ($filterType === 'month_year') {
    $startDate = sprintf('%04d-%02d-01', $selectedYear, $selectedMonth);
    $endDate   = date('Y-m-t', strtotime($startDate));
    $presetFilter = 'month_year';
    $filterTitleDesc = "Bulan " . ($monthNames[$selectedMonth] ?? $selectedMonth) . " " . $selectedYear;
    $trendSubtext = 'per tanggal (' . ($monthNames[$selectedMonth] ?? $selectedMonth) . ' ' . $selectedYear . ')';
} elseif ($filterType === 'custom' || (!empty($_GET['start_date']) && !empty($_GET['end_date']))) {
    $startDate    = sanitize($_GET['start_date'] ?? date('Y-m-01'));
    $endDate      = sanitize($_GET['end_date'] ?? date('Y-m-d'));
    $presetFilter = 'custom';
    $filterType   = 'custom';
    $filterTitleDesc = date('d M Y', strtotime($startDate)) . ' – ' . date('d M Y', strtotime($endDate));
    $trendSubtext = 'per tanggal';
} else {
    $presetFilter = sanitize($_GET['preset'] ?? 'bulanan');
    switch ($presetFilter) {
        case 'hari_ini':
            $startDate = date('Y-m-d');
            $endDate   = date('Y-m-d');
            $filterTitleDesc = "Hari Ini (" . date('d M Y') . ")";
            $trendSubtext = 'per jam / hari';
            break;
        case 'mingguan':
            $startDate = date('Y-m-d', strtotime('monday this week'));
            $endDate   = date('Y-m-d');
            $filterTitleDesc = "Minggu Ini (" . date('d M', strtotime($startDate)) . " – " . date('d M Y', strtotime($endDate)) . ")";
            $trendSubtext = 'per hari';
            break;
        case 'tahunan':
            $startDate = date('Y-01-01');
            $endDate   = date('Y-m-d');
            $filterTitleDesc = "Tahun Ini (" . date('Y') . ")";
            $trendSubtext = 'per bulan';
            break;
        default:
            $presetFilter = 'bulanan';
            $startDate    = date('Y-m-01');
            $endDate      = date('Y-m-d');
            $filterTitleDesc = "Bulan Ini (" . ($monthNames[(int)date('n')] ?? date('F')) . " " . date('Y') . ")";
            $trendSubtext = 'per tanggal';
    }
}

// ── Fetch Master Customers List for Filter Dropdown (dari session cache) ────
$customerOptions = $_meta['customers'] ?? [];

// ── Init metric variables ──────────────────────────────────────────────────
$kpi = [
    'total_inspected' => 0,
    'total_ng'        => 0,
    'ng_rate'         => 0,
    'ppm_rate'        => 0,
    'ppb_rate'        => 0,
    'total_lot'       => 0,
    'pass_count'      => 0,
    'rejected_count'  => 0,
    'pass_rate'       => 0,
];
$trendLabels       = [];
$trendPPM          = [];
$trendNG           = [];
$trendSample       = [];
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
        // Auto-heal / self-backfill if summary is empty
        $chkSum = (int)$pdo->query("SELECT COUNT(*) FROM oqc_daily_summary")->fetchColumn();
        if ($chkSum === 0) {
            backfillAllDailySummaries($pdo);
        }

        $edNext = date('Y-m-d', strtotime($endDate . ' +1 day'));
        $p = [':sd' => $startDate, ':ed_next' => $edNext];
        $sumCustCond = "";

        if ($selectedCustomer !== '') {
            $sumCustCond = " AND customer = :cust ";
            $p[':cust'] = $selectedCustomer;
        }

        // 1, 2, 3: Fetch all KPIs in a single instant query from oqc_daily_summary
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
            // Mode Pcs (Sampling PPM by selected basis)
            $kpi['total_inspected'] = $effectiveSampleSize;
            $kpi['total_ng']        = $effectiveNgPcs;
            $kpi['ppm_rate']        = $effectiveSampleSize > 0 ? round(($effectiveNgPcs / $effectiveSampleSize) * 1000000, 1) : 0;
            $kpi['pct_rate']        = $effectiveSampleSize > 0 ? round(($effectiveNgPcs / $effectiveSampleSize) * 100, 2) : 0;
            $kpi['ng_rate']         = $kpi['ppm_rate'];
        }

        // Dynamic Trend (PPM Defect Rate / Sampling Performance / Lot Reject Rate)
        $daysDiff = (strtotime($endDate) - strtotime($startDate)) / 86400;

        if ($presetFilter === 'all_years') {
            $trendSubtext = 'komparasi total per tahun (YoY)';
            $trendMap     = [];

            $minYear = min($dbYears);
            $maxYear = max($dbYears);
            if ($minYear === $maxYear) {
                $minYear = $maxYear - 1;
            }
            $yearsToDisplay = range($minYear, $maxYear);

            foreach ($yearsToDisplay as $yr) {
                $yrStr = (string)$yr;
                $trendMap[$yrStr] = ['label' => $yrStr, 'sample' => 0, 'ng' => 0, 'ppm' => 0];
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
            foreach ($stmtTrend->fetchAll() as $r) {
                $yk = (string)$r['yr_key'];
                if (isset($trendMap[$yk])) {
                    if ($selectedUnit === 'lot') {
                        $trendMap[$yk]['sample'] = (int)$r['total_lots'];
                        $trendMap[$yk]['ng']     = (int)$r['rejected_lots'];
                        if ($selectedResult === 'passed') {
                            $trendMap[$yk]['ppm'] = $trendMap[$yk]['sample'] > 0 ? round(((int)$r['passed_lots'] / $trendMap[$yk]['sample']) * 100, 1) : 0;
                        } else {
                            $trendMap[$yk]['ppm'] = $trendMap[$yk]['sample'] > 0 ? round(($trendMap[$yk]['ng'] / $trendMap[$yk]['sample']) * 100, 2) : 0;
                        }
                    } else {
                        $sLot  = (int)$r['total_sample_size_lot'] ?: (int)$r['total_sample_size'];
                        $sSess = (int)$r['total_sample_size_session'] ?: (int)$r['total_sample_size'];
                        $trendMap[$yk]['sample'] = ($selectedSampleBasis === 'session') ? $sSess : $sLot;
                        $trendMap[$yk]['ng']     = max((int)$r['total_ng_pcs'], (int)$r['total_ng_samples']);
                        if ($selectedResult === 'passed') {
                            $passedCount = max(0, $trendMap[$yk]['sample'] - $trendMap[$yk]['ng']);
                            $trendMap[$yk]['ppm'] = $trendMap[$yk]['sample'] > 0 ? round(($passedCount / $trendMap[$yk]['sample']) * 1000000, 1) : 0;
                        } else {
                            $trendMap[$yk]['ppm'] = $trendMap[$yk]['sample'] > 0 ? round(($trendMap[$yk]['ng'] / $trendMap[$yk]['sample']) * 1000000, 1) : 0;
                        }
                    }
                }
            }

            foreach ($trendMap as $item) {
                $trendLabels[] = $item['label'];
                $trendSample[] = $item['sample'];
                $trendNG[]     = $item['ng'];
                $trendPPM[]    = $item['ppm'];
            }
        } elseif ($presetFilter === 'tahunan' || $daysDiff > 90) {
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
                $trendMap[$key] = ['label' => $lbl, 'sample' => 0, 'ng' => 0, 'ppm' => 0];
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
            foreach ($stmtTrend->fetchAll() as $r) {
                $mk = $r['month_key'];
                if (isset($trendMap[$mk])) {
                    if ($selectedUnit === 'lot') {
                        $trendMap[$mk]['sample'] = (int)$r['total_lots'];
                        $trendMap[$mk]['ng']     = (int)$r['rejected_lots'];
                        if ($selectedResult === 'passed') {
                            $trendMap[$mk]['ppm'] = $trendMap[$mk]['sample'] > 0 ? round(((int)$r['passed_lots'] / $trendMap[$mk]['sample']) * 100, 1) : 0;
                        } else {
                            $trendMap[$mk]['ppm'] = $trendMap[$mk]['sample'] > 0 ? round(($trendMap[$mk]['ng'] / $trendMap[$mk]['sample']) * 100, 2) : 0;
                        }
                    } else {
                        $sLot  = (int)$r['total_sample_size_lot'] ?: (int)$r['total_sample_size'];
                        $sSess = (int)$r['total_sample_size_session'] ?: (int)$r['total_sample_size'];
                        $trendMap[$mk]['sample'] = ($selectedSampleBasis === 'session') ? $sSess : $sLot;
                        $trendMap[$mk]['ng']     = max((int)$r['total_ng_pcs'], (int)$r['total_ng_samples']);
                        if ($selectedResult === 'passed') {
                            $passedCount = max(0, $trendMap[$mk]['sample'] - $trendMap[$mk]['ng']);
                            $trendMap[$mk]['ppm'] = $trendMap[$mk]['sample'] > 0 ? round(($passedCount / $trendMap[$mk]['sample']) * 1000000, 1) : 0;
                        } else {
                            $trendMap[$mk]['ppm'] = $trendMap[$mk]['sample'] > 0 ? round(($trendMap[$mk]['ng'] / $trendMap[$mk]['sample']) * 1000000, 1) : 0;
                        }
                    }
                }
            }

            foreach ($trendMap as $item) {
                $trendLabels[] = $item['label'];
                $trendSample[] = $item['sample'];
                $trendNG[]     = $item['ng'];
                $trendPPM[]    = $item['ppm'];
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
                $trendMap[$key] = ['label' => $lbl, 'sample' => 0, 'ng' => 0, 'ppm' => 0];
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
            foreach ($stmtTrend->fetchAll() as $r) {
                $tgl = $r['tgl'];
                if (isset($trendMap[$tgl])) {
                    if ($selectedUnit === 'lot') {
                        $trendMap[$tgl]['sample'] = (int)$r['total_lots'];
                        $trendMap[$tgl]['ng']     = (int)$r['rejected_lots'];
                        if ($selectedResult === 'passed') {
                            $trendMap[$tgl]['ppm'] = $trendMap[$tgl]['sample'] > 0 ? round(((int)$r['passed_lots'] / $trendMap[$tgl]['sample']) * 100, 1) : 0;
                        } else {
                            $trendMap[$tgl]['ppm'] = $trendMap[$tgl]['sample'] > 0 ? round(($trendMap[$tgl]['ng'] / $trendMap[$tgl]['sample']) * 100, 2) : 0;
                        }
                    } else {
                        $sLot  = (int)$r['total_sample_size_lot'] ?: (int)$r['total_sample_size'];
                        $sSess = (int)$r['total_sample_size_session'] ?: (int)$r['total_sample_size'];
                        $trendMap[$tgl]['sample'] = ($selectedSampleBasis === 'session') ? $sSess : $sLot;
                        $trendMap[$tgl]['ng']     = max((int)$r['total_ng_pcs'], (int)$r['total_ng_samples']);
                        if ($selectedResult === 'passed') {
                            $passedCount = max(0, $trendMap[$tgl]['sample'] - $trendMap[$tgl]['ng']);
                            $trendMap[$tgl]['ppm'] = $trendMap[$tgl]['sample'] > 0 ? round(($passedCount / $trendMap[$tgl]['sample']) * 1000000, 1) : 0;
                        } else {
                            $trendMap[$tgl]['ppm'] = $trendMap[$tgl]['sample'] > 0 ? round(($trendMap[$tgl]['ng'] / $trendMap[$tgl]['sample']) * 1000000, 1) : 0;
                        }
                    }
                }
            }

            foreach ($trendMap as $item) {
                $trendLabels[] = $item['label'];
                $trendSample[] = $item['sample'];
                $trendNG[]     = $item['ng'];
                $trendPPM[]    = $item['ppm'];
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
                $stmt = $pdo->prepare("SELECT dt.name, COALESCE(SUM(dds.lot_count), 0) AS total 
                                        FROM oqc_daily_defect_summary dds 
                                        INNER JOIN defect_types dt ON dds.defect_type_id=dt.id 
                                        WHERE dds.summary_date >= :sd AND dds.summary_date < :ed_next {$sumCustCond}
                                        GROUP BY dt.id, dt.name 
                                        ORDER BY total DESC 
                                        LIMIT {$selectedTopRank}");
            } else {
                $stmt = $pdo->prepare("SELECT dt.name, COALESCE(SUM(dds.qty_ng), 0) AS total 
                                        FROM oqc_daily_defect_summary dds 
                                        INNER JOIN defect_types dt ON dds.defect_type_id=dt.id 
                                        WHERE dds.summary_date >= :sd AND dds.summary_date < :ed_next {$sumCustCond}
                                        GROUP BY dt.id, dt.name 
                                        ORDER BY total DESC 
                                        LIMIT {$selectedTopRank}");
            }
            $stmt->execute($p);
            foreach ($stmt->fetchAll() as $r) { $defectLabels[] = $r['name']; $defectCounts[] = (int)$r['total']; }
        }

        // 5b. Defect Breakdown Timeline Matrix (Stacked Bar Chart & Table Matrix)
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

        // Ambil rincian Part Code & Part Name per jenis defect & tanggal untuk hover tooltip
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

        $defectPartsMatrix = []; // [date_key => [defect_name => [ ['code' => ..., 'name' => ..., 'qty' => ...], ... ]]]
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
        $defectMatrix        = []; // [date_key => [defect_name => qty]]
        $defectDateTotals    = []; // [date_key => total]
        $defectTypeTotals    = []; // [defect_name => total]

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

        // Filter timeline to active dates with records so the chart shows meaningful bars
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

        // Limit to Top N defect types based on $selectedTopRank (3, 5, or 10)
        $topNDefectTypes   = array_slice($distinctDefectTypes, 0, $selectedTopRank);
        $otherNDefectTypes = array_slice($distinctDefectTypes, $selectedTopRank);

        $stackedChartLabels = [];
        foreach ($activeDefectPeriodKeys as $dk) {
            $stackedChartLabels[] = $stackedPeriodLabels[$dk] ?? $dk;
        }

        $grandTotSample = 0;
        foreach ($activeDefectPeriodKeys as $dk) {
            $grandTotSample += (int)($trendMap[$dk]['sample'] ?? 0);
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
                    $qty    = (int)($defectMatrix[$dk][$dname] ?? 0);
                    $sample = (int)($trendMap[$dk]['sample'] ?? 0);
                    $ppm    = ($sample > 0) ? round(($qty / $sample) * 1000000, 1) : 0;

                    $dataPtsPcs[]  = $qty;
                    $dataPtsPpm[]  = $ppm;
                    $partMetaPts[] = $defectPartsMatrix[$dk][$dname] ?? [];
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

            // Tambahkan baris Others jika ada defect di luar Top N
            if (!empty($otherNDefectTypes)) {
                $otherPtsPcs   = [];
                $otherPtsPpm   = [];
                $otherMetaPts  = [];
                $hasOtherData  = false;

                foreach ($activeDefectPeriodKeys as $dk) {
                    $otherQty = 0;
                    $combinedOtherParts = [];
                    foreach ($otherNDefectTypes as $odname) {
                        $otherQty += (int)($defectMatrix[$dk][$odname] ?? 0);
                        if (!empty($defectPartsMatrix[$dk][$odname])) {
                            foreach ($defectPartsMatrix[$dk][$odname] as $pItem) {
                                $combinedOtherParts[] = $pItem;
                            }
                        }
                    }
                    if ($otherQty > 0) $hasOtherData = true;

                    $sample = (int)($trendMap[$dk]['sample'] ?? 0);
                    $ppm    = ($sample > 0) ? round(($otherQty / $sample) * 1000000, 1) : 0;

                    $otherPtsPcs[]  = $otherQty;
                    $otherPtsPpm[]  = $ppm;
                    $otherMetaPts[] = $combinedOtherParts;
                }

                if ($hasOtherData) {
                    $otherLabel = 'Others (' . count($otherNDefectTypes) . ' jenis lainnya)';
                    $stackedChartDatasetsPcs[] = [
                        'label'           => $otherLabel,
                        'data'            => $otherPtsPcs,
                        'raw_pcs'         => $otherPtsPcs,
                        'part_meta'       => $otherMetaPts,
                        'backgroundColor' => '#94a3b8',
                        'stack'           => 'defect_stack',
                        'borderRadius'    => 3
                    ];
                    $stackedChartDatasetsPpm[] = [
                        'label'           => $otherLabel,
                        'data'            => $otherPtsPpm,
                        'raw_pcs'         => $otherPtsPcs,
                        'part_meta'       => $otherMetaPts,
                        'backgroundColor' => '#94a3b8',
                        'stack'           => 'defect_stack',
                        'borderRadius'    => 3
                    ];
                }
            }
        }

        // 6. Top Part (Passed vs Rejected Mode) - Dynamic Rank Filter
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
            if ($selectedUnit === 'lot') {
                $stmt = $pdo->prepare("SELECT mp.part_code, mp.part_name, 
                                               SUM(s.rejected_lots) AS total_ng 
                                        FROM oqc_daily_summary s 
                                        LEFT JOIN master_parts mp ON s.part_id=mp.id 
                                        WHERE s.summary_date >= :sd AND s.summary_date < :ed_next {$sumCustCond} 
                                        GROUP BY s.part_id, mp.part_code, mp.part_name 
                                        HAVING total_ng > 0
                                        ORDER BY total_ng DESC 
                                        LIMIT {$selectedTopRank}");
            } else {
                $stmt = $pdo->prepare("SELECT mp.part_code, mp.part_name, 
                                               SUM(COALESCE(NULLIF(s.total_ng_pcs, 0), s.total_ng_samples, 0)) AS total_ng 
                                        FROM oqc_daily_summary s 
                                        LEFT JOIN master_parts mp ON s.part_id=mp.id 
                                        WHERE s.summary_date >= :sd AND s.summary_date < :ed_next {$sumCustCond} 
                                        GROUP BY s.part_id, mp.part_code, mp.part_name 
                                        HAVING total_ng > 0
                                        ORDER BY total_ng DESC 
                                        LIMIT {$selectedTopRank}");
            }
        }
        $stmt->execute($p); $top5Parts = $stmt->fetchAll();
        foreach ($top5Parts as $r) { $pieLabels[] = htmlspecialchars($r['part_name'] ?? $r['part_code'] ?? 'Unknown'); $pieCounts[] = (int)$r['total_ng']; }

        // 6b. Distribusi per Model (Passed vs Rejected Mode) - Dynamic Rank Filter
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
            if ($selectedUnit === 'lot') {
                $stmtM = $pdo->prepare("SELECT COALESCE(m.name, mp.model, 'NO MODEL') AS model_name, 
                                               SUM(s.rejected_lots) AS total_ng 
                                        FROM oqc_daily_summary s 
                                        LEFT JOIN master_parts mp ON s.part_id=mp.id 
                                        LEFT JOIN master_models m ON s.model_id=m.id 
                                        WHERE s.summary_date >= :sd AND s.summary_date < :ed_next {$sumCustCond} 
                                        GROUP BY m.id, model_name 
                                        HAVING total_ng > 0
                                        ORDER BY total_ng DESC 
                                        LIMIT {$selectedTopRank}");
            } else {
                $stmtM = $pdo->prepare("SELECT COALESCE(m.name, mp.model, 'NO MODEL') AS model_name, 
                                               SUM(COALESCE(NULLIF(s.total_ng_pcs, 0), s.total_ng_samples, 0)) AS total_ng 
                                        FROM oqc_daily_summary s 
                                        LEFT JOIN master_parts mp ON s.part_id=mp.id 
                                        LEFT JOIN master_models m ON s.model_id=m.id 
                                        WHERE s.summary_date >= :sd AND s.summary_date < :ed_next {$sumCustCond} 
                                        GROUP BY m.id, model_name 
                                        HAVING total_ng > 0
                                        ORDER BY total_ng DESC 
                                        LIMIT {$selectedTopRank}");
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
$jsTrendPPM     = json_encode($trendPPM);
$jsTrendNG      = json_encode($trendNG);
$jsTrendSample  = json_encode($trendSample);

// ── Distinct Curated Palettes for Circular Charts ─────────────────────────
$DEFECT_PALETTE = ($selectedResult === 'passed') 
    ? ['#059669', '#dc2626', '#3b82f6', '#f59e0b', '#8b5cf6', '#06b6d4', '#10b981', '#6366f1'] 
    : ['#ef4444', '#f59e0b', '#8b5cf6', '#06b6d4', '#ec4899', '#3b82f6', '#10b981', '#f97316', '#64748b', '#14b8a6'];

$MODEL_PALETTE  = ['#6366f1', '#0ea5e9', '#3b82f6', '#8b5cf6', '#475569', '#06b6d4', '#2563eb', '#1e293b', '#64748b', '#0284c7'];

$PART_PALETTE   = ['#0d9488', '#10b981', '#f59e0b', '#f43f5e', '#7c3aed', '#0284c7', '#d97706', '#64748b', '#ec4899', '#3b82f6'];

$jsDefectLabels = json_encode($defectLabels);
$jsDefectCounts = json_encode($defectCounts);
$jsDefectColors = json_encode(array_values(array_slice($DEFECT_PALETTE, 0, max(count($defectLabels), 1))));

$jsModelLabels  = json_encode($modelLabels);
$jsModelCounts  = json_encode($modelCounts);
$jsModelColors  = json_encode(array_values(array_slice($MODEL_PALETTE, 0, max(count($modelLabels), 1))));

$jsPieLabels    = json_encode($pieLabels);
$jsPieCounts    = json_encode($pieCounts);
$jsPartColors   = json_encode(array_values(array_slice($PART_PALETTE, 0, max(count($pieLabels), 1))));

$jsStackedLabels      = json_encode($stackedChartLabels);
$jsStackedDatasetsPcs = json_encode($stackedChartDatasetsPcs);
$jsStackedDatasetsPpm = json_encode($stackedChartDatasetsPpm);

$passColor      = ($kpi['pass_rate'] ?? 0) >= 90 ? '#059669' : (($kpi['pass_rate'] ?? 0) >= 70 ? '#d97706' : '#dc2626');
$ngRateColor    = (($kpi['ppm_rate'] ?? 0) == 0) ? '#059669' : ((($kpi['ppm_rate'] ?? 0) <= 10000.0) ? '#d97706' : '#dc2626');
$ngRateBg       = (($kpi['ppm_rate'] ?? 0) == 0) ? '#f0fdf4' : ((($kpi['ppm_rate'] ?? 0) <= 10000.0) ? '#fffbeb' : '#fff1f2');

// Distinct rank badge colors up to 10 ranks
$rankColors     = ($selectedResult === 'passed') 
    ? ['#059669','#10b981','#34d399','#0284c7','#3b82f6','#6366f1','#8b5cf6','#06b6d4','#14b8a6','#047857'] 
    : ['#f59e0b','#64748b','#d97706','#3b82f6','#8b5cf6','#ec4899','#06b6d4','#10b981','#6366f1','#0284c7'];

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

        <!-- ── Official Report Header (Visible Only on Print) ──────────────── -->
        <div class="print-only" style="margin-bottom:14px;border-bottom:2px solid #0f172a;padding-bottom:10px;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <!-- Company Logo / Badge -->
                    <div style="width:44px;height:44px;background:#0f172a;color:#ffffff;border-radius:8px;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:16px;letter-spacing:1px;flex-shrink:0;">
                        OQC
                    </div>
                    <div>
                        <div style="font-size:16px;font-weight:900;color:#0f172a;letter-spacing:0.03em;text-transform:uppercase;">PT. SURYA TECHNOLOGY INDUSTRI</div>
                        <div style="font-size:11px;font-weight:700;color:#2563eb;letter-spacing:0.02em;">OUTGOING QUALITY CONTROL (OQC) DEPARTMENT</div>
                        <div style="font-size:9.5px;color:#64748b;">Quality Assurance &amp; Inspection Monitoring System &middot; Official Quality Performance Report</div>
                    </div>
                </div>
                <div style="text-align:right;">
                    <div style="display:inline-block;padding:3px 10px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:6px;font-size:9.5px;font-weight:800;color:#1e293b;">
                        DOC REF: OQC-REP-<?= date('Ym') ?>
                    </div>
                    <div style="font-size:9px;color:#64748b;margin-top:4px;">
                        Dicetak: <strong><?= date('d M Y, H:i') ?> WIB</strong>
                    </div>
                    <div style="font-size:9px;color:#64748b;">
                        Oleh: <strong><?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?></strong>
                    </div>
                </div>
            </div>

            <!-- Report Title & Parameter Summary Bar -->
            <div style="margin-top:10px;padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;display:grid;grid-template-columns:repeat(4,1fr);gap:10px;font-size:10px;">
                <div>
                    <span style="color:#64748b;font-weight:600;display:block;">Periode Laporan:</span>
                    <strong style="color:#0f172a;font-size:11px;"><?= htmlspecialchars($filterTitleDesc) ?></strong>
                </div>
                <div>
                    <span style="color:#64748b;font-weight:600;display:block;">Target Customer:</span>
                    <strong style="color:#0f172a;font-size:11px;"><?= htmlspecialchars($selectedCustomer !== '' ? $selectedCustomer : 'Semua Customer (All)') ?></strong>
                </div>
                <div>
                    <span style="color:#64748b;font-weight:600;display:block;">Satuan &amp; Basis Sampel:</span>
                    <strong style="color:#0f172a;font-size:11px;"><?= strtoupper($selectedUnit) ?> &middot; Basis <?= ($selectedSampleBasis === 'session') ? 'Per Sesi' : 'Per Lot' ?></strong>
                </div>
                <div>
                    <span style="color:#64748b;font-weight:600;display:block;">Kategori Hasil:</span>
                    <strong style="color:<?= $isPassedMode ? '#059669' : '#dc2626' ?>;font-size:11px;">
                        <?= $isPassedMode ? 'PASSED (Lulus Mutu)' : 'REJECTED (Temuan Defect)' ?>
                    </strong>
                </div>
            </div>
        </div>

        <!-- ── Filter Bar (Refined 2-Row Structured Layout) ─────────────────── -->
        <div class="card no-print" style="padding:12px 16px;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.03);display:flex;flex-direction:column;gap:10px;">
            
            <!-- ROW 1: Presets, Unit Toggle, Sample Basis Toggle, Result Toggle, & Export/Print Actions -->
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
                                       . ($selectedSampleBasis !== 'lot' ? '&sample_basis=' . urlencode($selectedSampleBasis) : '')
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

                    <!-- Unit Segmented Toggle (Pcs vs Lot) [Hidden] -->
                    <div style="display:none;align-items:center;gap:3px;background:#f1f5f9;padding:3px;border-radius:9px;border:1px solid #e2e8f0;" title="Pilih Satuan Analisis Data">
                        <?php 
                        $unitPcsUrl = "?unit=pcs" . ($selectedSampleBasis !== 'lot' ? '&sample_basis=' . $selectedSampleBasis : '') . ($selectedResult !== 'rejected' ? '&result=' . $selectedResult : '') . ($presetFilter !== 'custom' ? '&preset=' . $presetFilter : '') . ($selectedCustomer !== '' ? '&customer=' . urlencode($selectedCustomer) : '') . (!empty($_GET['start_date']) ? '&start_date=' . urlencode($_GET['start_date']) . '&end_date=' . urlencode($_GET['end_date']) : '');
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

                    <?php if ($selectedUnit === 'pcs'): ?>
                    <!-- Sample Basis Segmented Toggle (Per Lot vs Per Sesi) -->
                    <div style="display:flex;align-items:center;gap:3px;background:#f1f5f9;padding:3px;border-radius:9px;border:1px solid #e2e8f0;" title="Pilih Dasar Perhitungan Ukuran Sampel AQL">
                        <span style="font-size:10px;font-weight:700;color:#64748b;padding:0 4px;">Basis Sampel:</span>
                        <?php 
                        $basisLotUrl = "?unit=pcs&sample_basis=lot" . ($selectedResult !== 'rejected' ? '&result=' . $selectedResult : '') . ($presetFilter !== 'custom' ? '&preset=' . $presetFilter : '') . ($selectedCustomer !== '' ? '&customer=' . urlencode($selectedCustomer) : '') . (!empty($_GET['start_date']) ? '&start_date=' . urlencode($_GET['start_date']) . '&end_date=' . urlencode($_GET['end_date']) : '');
                        $basisSessUrl = "?unit=pcs&sample_basis=session" . ($selectedResult !== 'rejected' ? '&result=' . $selectedResult : '') . ($presetFilter !== 'custom' ? '&preset=' . $presetFilter : '') . ($selectedCustomer !== '' ? '&customer=' . urlencode($selectedCustomer) : '') . (!empty($_GET['start_date']) ? '&start_date=' . urlencode($_GET['start_date']) . '&end_date=' . urlencode($_GET['end_date']) : '');
                        $isBasisLot = ($selectedSampleBasis === 'lot');
                        $isBasisSess = ($selectedSampleBasis === 'session');
                        ?>
                        <a href="<?= $basisLotUrl ?>" 
                           style="padding:4px 9px;border-radius:6px;font-size:11px;font-weight:800;text-decoration:none;transition:all 0.2s;
                                  background:<?= $isBasisLot ? '#0284c7' : 'transparent' ?>;
                                  color:<?= $isBasisLot ? '#ffffff' : '#64748b' ?>;
                                  <?= $isBasisLot ? 'box-shadow:0 1px 3px rgba(2,132,199,0.25);' : '' ?>">
                            Per Lot
                        </a>
                        <a href="<?= $basisSessUrl ?>" 
                           style="padding:4px 9px;border-radius:6px;font-size:11px;font-weight:800;text-decoration:none;transition:all 0.2s;
                                  background:<?= $isBasisSess ? '#0284c7' : 'transparent' ?>;
                                  color:<?= $isBasisSess ? '#ffffff' : '#64748b' ?>;
                                  <?= $isBasisSess ? 'box-shadow:0 1px 3px rgba(2,132,199,0.25);' : '' ?>">
                            Per Sesi
                        </a>
                    </div>
                    <?php endif; ?>

                    <!-- Result Segmented Toggle (Rejected vs Passed) [Hidden] -->
                    <div style="display:none;align-items:center;gap:3px;background:#f1f5f9;padding:3px;border-radius:9px;border:1px solid #e2e8f0;" title="Pilih Hasil Inspeksi Diagram">
                        <?php 
                        $resRejectedUrl = "?result=rejected" . ($selectedUnit !== 'pcs' ? '&unit=' . $selectedUnit : '') . ($selectedSampleBasis !== 'lot' ? '&sample_basis=' . $selectedSampleBasis : '') . ($presetFilter !== 'custom' ? '&preset=' . $presetFilter : '') . ($selectedCustomer !== '' ? '&customer=' . urlencode($selectedCustomer) : '') . (!empty($_GET['start_date']) ? '&start_date=' . urlencode($_GET['start_date']) . '&end_date=' . urlencode($_GET['end_date']) : '');
                        $resPassedUrl   = "?result=passed"   . ($selectedUnit !== 'pcs' ? '&unit=' . $selectedUnit : '') . ($selectedSampleBasis !== 'lot' ? '&sample_basis=' . $selectedSampleBasis : '') . ($presetFilter !== 'custom' ? '&preset=' . $presetFilter : '') . ($selectedCustomer !== '' ? '&customer=' . urlencode($selectedCustomer) : '') . (!empty($_GET['start_date']) ? '&start_date=' . urlencode($_GET['start_date']) . '&end_date=' . urlencode($_GET['end_date']) : '');
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

                <!-- Action Buttons: Print Report & Export Excel -->
                <div style="display:flex;align-items:center;gap:8px;">
                    <!-- Print Report Action Button -->
                    <button type="button" onclick="printDashboardReport()" 
                            style="display:inline-flex;align-items:center;gap:6px;padding:5px 14px;background:#2563eb;color:#ffffff;font-size:11px;font-weight:800;border-radius:8px;border:none;cursor:pointer;box-shadow:0 1px 3px rgba(37,99,235,0.3);transition:all 0.2s;"
                            onmouseover="this.style.background='#1d4ed8'" onmouseout="this.style.background='#2563eb'"
                            title="Cetak laporan dashboard langsung ke printer / simpan PDF">
                        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                        </svg>
                        Cetak Laporan
                    </button>

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
            </div>

            <!-- ROW 2: Customer Filter, Filter Mode (Bulan&Tahun, Rentang Tahun, Kustom Tanggal), Terapkan, & Reset Button -->
            <form action="" method="GET" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:0;">
                <input type="hidden" name="unit" value="<?= htmlspecialchars($selectedUnit) ?>">
                <input type="hidden" name="sample_basis" value="<?= htmlspecialchars($selectedSampleBasis) ?>">
                <input type="hidden" name="result" value="<?= htmlspecialchars($selectedResult) ?>">

                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <!-- Customer Filter -->
                    <div style="display:flex;align-items:center;gap:5px;">
                        <span style="font-size:11px;font-weight:700;color:#475569;">Customer:</span>
                        <select name="customer" onchange="this.form.submit()" class="form-input text-xs" style="padding:4px 8px;border-radius:7px;font-weight:600;min-width:140px;border:1px solid #cbd5e1;">
                            <option value="">-- Semua Customer --</option>
                            <?php foreach ($customerOptions as $cOpt): ?>
                                <option value="<?= htmlspecialchars($cOpt) ?>" <?= $selectedCustomer === $cOpt ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cOpt) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Filter Mode Selector Dropdown -->
                    <div style="display:flex;align-items:center;gap:5px;">
                        <span style="font-size:11px;font-weight:700;color:#475569;">Pilih Periode:</span>
                        <select name="filter_type" id="filterTypeSelect" onchange="switchDateFilterMode(this.value)" class="form-input text-xs" style="padding:4px 8px;border-radius:7px;font-weight:700;border:1px solid #cbd5e1;background:#f8fafc;color:#1e293b;">
                            <option value="preset" <?= in_array($presetFilter, ['hari_ini','mingguan','bulanan','tahunan']) ? 'selected' : '' ?>>Preset Cepat</option>
                            <option value="month_year" <?= $presetFilter === 'month_year' ? 'selected' : '' ?>>Pilih Bulan & Tahun</option>
                            <option value="all_years" <?= $presetFilter === 'all_years' ? 'selected' : '' ?>>Komparasi Semua Tahun (YoY)</option>
                            <option value="custom" <?= $presetFilter === 'custom' ? 'selected' : '' ?>>Kustom Tanggal</option>
                        </select>
                    </div>

                    <!-- Container 1: Month & Year Selector -->
                    <div id="containerMonthYear" style="display:<?= $presetFilter === 'month_year' ? 'inline-flex' : 'none' ?>;align-items:center;gap:5px;">
                        <select name="month" class="form-input text-xs" style="padding:4px 8px;border-radius:7px;border:1px solid #cbd5e1;font-weight:600;">
                            <?php foreach ($monthNames as $mNum => $mName): ?>
                                <option value="<?= $mNum ?>" <?= $selectedMonth === $mNum ? 'selected' : '' ?>><?= $mName ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="year" class="form-input text-xs" style="padding:4px 8px;border-radius:7px;border:1px solid #cbd5e1;font-weight:600;">
                            <?php foreach ($availableYears as $y): ?>
                                <option value="<?= $y ?>" <?= $selectedYear === (int)$y ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn-secondary text-xs font-semibold" style="padding:4px 12px;border-radius:7px;">Terapkan</button>
                    </div>

                    <!-- Container 3: Custom Date Range -->
                    <div id="containerCustomDate" style="display:<?= $presetFilter === 'custom' ? 'inline-flex' : 'none' ?>;align-items:center;gap:5px;">
                        <input type="date" name="start_date" value="<?= $presetFilter === 'custom' ? htmlspecialchars($startDate) : '' ?>" class="form-input text-xs" style="width:120px;padding:4px 8px;border-radius:7px;border:1px solid #cbd5e1;">
                        <span style="color:#94a3b8;font-weight:bold;">&ndash;</span>
                        <input type="date" name="end_date"   value="<?= $presetFilter === 'custom' ? htmlspecialchars($endDate) : '' ?>"   class="form-input text-xs" style="width:120px;padding:4px 8px;border-radius:7px;border:1px solid #cbd5e1;">
                        <button type="submit" class="btn-secondary text-xs font-semibold" style="padding:4px 12px;border-radius:7px;">Terapkan</button>
                    </div>
                </div>

                <!-- Reset Button -->
                <div>
                    <?php if ($selectedCustomer !== '' || $selectedUnit !== 'pcs' || $selectedSampleBasis !== 'lot' || $selectedResult !== 'rejected' || !in_array($presetFilter, ['bulanan']) || $filterType !== ''): ?>
                        <a href="index.php" class="text-xs text-rose-600 font-extrabold hover:underline" style="padding:4px 10px;border:1px solid #fecdd3;border-radius:7px;background:#fff1f2;text-decoration:none;display:inline-flex;align-items:center;gap:4px;">
                            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            Reset Filter
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- ── 5 KPI Cards (Clickable Drilldown) ───────────────────────── -->
        <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:10px;" id="kpi-grid">

            <?php
            $isLotUnit = ($selectedUnit === 'lot');
            $sampleBasisLabel = ($selectedSampleBasis === 'session') ? ' (Basis Sesi)' : ' (Basis Lot)';
            $sampleBasisSub = ($selectedSampleBasis === 'session') ? 'Sampel kanban dicek' : 'Sample fisik dicek';
            $kpis = [
                ['key'=>'sample_inspected','label'=>$isLotUnit ? 'Total Lot Inspected' : ('Total Sample Inspected' . $sampleBasisLabel),'val'=>number_format($kpi['total_inspected'] ?? 0),'unit'=>$isLotUnit ? 'lot' : 'pcs','sub'=>$isLotUnit ? 'Total box/lot discan' : $sampleBasisSub,'icon'=>'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z','iconColor'=>'#2563eb','bg'=>'#eff6ff'],
                ['key'=>'total_ng','label'=>$isLotUnit ? 'Total Lot Reject' : 'Total NG Pcs','val'=>number_format($kpi['total_ng'] ?? 0),'unit'=>$isLotUnit ? 'lot' : 'pcs','sub'=>$isLotUnit ? 'Box ditemukan defect' : 'Fisik sampel cacat','icon'=>'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z','iconColor'=>'#dc2626','bg'=>'#fff1f2','valColor'=>'#dc2626'],
                ['key'=>'defect_rate','label'=>$isLotUnit ? 'Lot Reject Rate (%)' : 'Defect Rate (<span id="kpiMetricScaleLabel">PPM</span>)','val'=>$isLotUnit ? (number_format($kpi['pct_rate'] ?? 0, 2) . ' <span style="font-size:12px;font-weight:700;">%</span>') : ('<span id="kpiMetricRateVal">'.number_format($kpi['ppm_rate'] ?? 0, 1).'</span>'),'unit'=>'','sub'=>$isLotUnit ? 'Persentase penolakan lot' : 'Rasio defect sampling','icon'=>'M13 7h8m0 0v8m0-8l-8 8-4-4-6 6','iconColor'=>$ngRateColor,'bg'=>$ngRateBg,'valColor'=>$ngRateColor],
                ['key'=>'total_lot','label'=>'Total LOT','val'=>number_format($kpi['total_lot'] ?? 0),'unit'=>'lot','sub'=>'Label / box discan','icon'=>'M4 6h16M4 10h16M4 14h16M4 18h16','iconColor'=>'#0284c7','bg'=>'#f0f9ff'],
                ['key'=>$isPassedMode ? 'passed_lot' : 'ng_lot','label'=>$isPassedMode ? 'Total Passed Lot' : 'Total NG Lot','val'=>number_format($isPassedMode ? ($kpi['pass_count'] ?? 0) : ($kpi['rejected_count'] ?? 0)),'unit'=>'lot','sub'=>$isPassedMode ? 'Lot berstatus lulus' : 'Label lot NG','icon'=>$isPassedMode ? 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z' : 'M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636','iconColor'=>$isPassedMode ? '#059669' : '#e11d48','bg'=>$isPassedMode ? '#f0fdf4' : '#ffe4e6','valColor'=>$isPassedMode ? '#059669' : '#e11d48'],
            ];
            foreach ($kpis as $kitem): ?>
            <div class="card kpi-clickable-card" onclick="openKpiDrilldownModal('<?= $kitem['key'] ?>')" 
                 style="padding:12px 14px;cursor:pointer;transition:all 0.2s cubic-bezier(0.4,0,0.2,1);position:relative;border:1px solid #e2e8f0;"
                 onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 8px 16px rgba(0,0,0,0.06)';this.style.borderColor='#cbd5e1';"
                 onmouseout="this.style.transform='translateY(0)';this.style.boxShadow='0 1px 3px rgba(0,0,0,0.03)';this.style.borderColor='#e2e8f0';"
                 title="Klik untuk melihat rincian data">
                <div style="display:flex;align-items:center;gap:12px;">
                    <div style="width:40px;height:40px;border-radius:10px;background:<?= $kitem['bg'] ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <svg width="20" height="20" fill="none" stroke="<?= $kitem['iconColor'] ?>" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="<?= $kitem['icon'] ?>"/></svg>
                    </div>
                    <div style="min-width:0;flex:1;">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:4px;">
                            <div style="font-size:9.5px;font-weight:700;color:#94a3b8;letter-spacing:.05em;text-transform:uppercase;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= $kitem['label'] ?></div>
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2.5" style="flex-shrink:0;"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                        </div>
                        <div style="font-size:20px;font-weight:900;color:<?= $kitem['valColor'] ?? '#0f172a' ?>;line-height:1.15;margin-top:2px;">
                            <?= $kitem['val'] ?><?php if($kitem['unit']): ?> <span style="font-size:11px;font-weight:500;color:#94a3b8;"><?= $kitem['unit'] ?></span><?php endif; ?>
                        </div>
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-top:2px;">
                            <span style="font-size:9.5px;color:#64748b;"><?= $kitem['sub'] ?></span>
                            <span style="font-size:8.5px;font-weight:700;color:#2563eb;text-decoration:underline;">Detail</span>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

        </div>

        <!-- ── Chart Row 1: Line (Height Enlarged) + Top5 ─── -->
        <div style="display:grid;grid-template-columns:minmax(0, 1.25fr) minmax(0, 1fr);gap:12px;" id="dash-row-1">

            <!-- Tren Chart (Formal Title, PPM/PPB Scale Switcher & Bar/Line Switcher) -->
            <div class="card" style="padding:14px 16px;display:flex;flex-direction:column;min-width:0;">
                <div style="flex-shrink:0;margin-bottom:10px;display:flex;align-items:flex-start;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                    <div>
                        <div style="font-size:13px;font-weight:800;color:#1e293b;display:flex;align-items:center;gap:6px;">
                            <span><?= $isPassedMode ? 'Tren Kualitas Mutu Yield' : 'Tren Kualitas Defect Rate' ?> (<span id="trendChartUnitText">PPM</span>)</span>
                            <span id="trendChartBadgeText" class="badge" style="background:#eff6ff;color:#2563eb;font-size:9.5px;font-weight:700;padding:2px 7px;border-radius:6px;border:1px solid #bfdbfe;">Parts Per Million</span>
                        </div>
                        <div style="font-size:10px;color:#94a3b8;margin-top:2px;">
                            <?= htmlspecialchars($filterTitleDesc ?? (date('d M Y', strtotime($startDate)) . ' – ' . date('d M Y', strtotime($endDate)))) ?> &middot; Agregasi <?= htmlspecialchars($trendSubtext ?? 'per tanggal') ?><?= $selectedCustomer !== '' ? ' &middot; Customer: ' . htmlspecialchars($selectedCustomer) : '' ?>
                        </div>
                    </div>
                    
                    <div class="no-print" style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                        <!-- Metric Scale Switcher (PPM vs %) -->
                        <div style="display:inline-flex;background:#f1f5f9;padding:2px;border-radius:8px;border:1px solid #e2e8f0;gap:2px;" title="Pilih Skala Pengukuran Kualitas">
                            <button type="button" id="btnMetricPPM" onclick="switchTrendMetricScale('ppm')" style="padding:3px 9px;font-size:10.5px;font-weight:800;border-radius:6px;border:none;cursor:pointer;transition:all 0.15s;background:#ffffff;color:#2563eb;box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                                PPM
                            </button>
                            <button type="button" id="btnMetricPCT" onclick="switchTrendMetricScale('pct')" style="padding:3px 9px;font-size:10.5px;font-weight:800;border-radius:6px;border:none;cursor:pointer;transition:all 0.15s;background:transparent;color:#64748b;">
                                %
                            </button>
                        </div>

                        <!-- Bar / Line Switcher Controls -->
                        <div style="display:inline-flex;background:#f1f5f9;padding:2px;border-radius:8px;border:1px solid #e2e8f0;gap:2px;">
                            <button type="button" id="btnChartTypeLine" onclick="switchTrendChartType('line')" style="padding:3px 9px;font-size:10.5px;font-weight:700;border-radius:6px;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:4px;transition:all 0.15s;background:#ffffff;color:#2563eb;box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                                Line
                            </button>
                            <button type="button" id="btnChartTypeBar" onclick="switchTrendChartType('bar')" style="padding:3px 9px;font-size:10.5px;font-weight:700;border-radius:6px;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:4px;transition:all 0.15s;background:transparent;color:#64748b;">
                                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                                Bar
                            </button>
                        </div>
                    </div>
                </div>
                <div style="min-height:240px;height:240px;position:relative;">
                    <?php if (empty($trendLabels)): ?>
                        <div style="height:100%;display:flex;align-items:center;justify-content:center;color:#cbd5e1;font-size:11px;background:#f8fafc;border-radius:8px;border:1px dashed #e2e8f0;">Belum ada data inspeksi</div>
                    <?php else: ?>
                        <canvas id="chartTrendNG" style="width:100%;height:100%;"></canvas>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Top N Defect Card (Stacked Bar Chart & Transpose Matrix Table) -->
            <div class="card" style="padding:14px 16px;display:flex;flex-direction:column;position:relative;min-width:0;">
                <div style="flex-shrink:0;margin-bottom:8px;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
                    <div>
                        <div style="font-size:13px;font-weight:800;color:#1e293b;display:flex;align-items:center;gap:6px;">
                            <span><?= $isPassedMode ? "Top {$selectedTopRank} Hasil" : "Top {$selectedTopRank} Defect" ?> (<span id="defectMetricScaleBadge">PCS</span>)</span>
                        </div>
                        <div style="font-size:10px;color:#94a3b8;margin-top:2px;">
                            <?= date('d M', strtotime($startDate)) ?> &ndash; <?= date('d M Y', strtotime($endDate)) ?>
                        </div>
                    </div>

                    <!-- Right Controls: [PCS|PPM], [3|5|10], [Grafik|Tabel] -->
                    <div class="no-print" style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                        <!-- Metric Switcher (PCS vs PPM) -->
                        <div style="display:inline-flex;background:#f1f5f9;padding:2px;border-radius:8px;border:1px solid #e2e8f0;gap:2px;" title="Pilih Satuan Metrik Defect">
                            <button type="button" id="btnDefectUnitPcs" onclick="switchDefectMetricUnit('pcs')"
                                    style="padding:3px 8px;font-size:10px;font-weight:800;border-radius:5px;border:none;cursor:pointer;transition:all 0.15s;background:#ffffff;color:#2563eb;box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                                PCS
                            </button>
                            <button type="button" id="btnDefectUnitPpm" onclick="switchDefectMetricUnit('ppm')"
                                    style="padding:3px 8px;font-size:10px;font-weight:700;border-radius:5px;border:none;cursor:pointer;transition:all 0.15s;background:transparent;color:#64748b;">
                                PPM
                            </button>
                        </div>

                        <!-- Rank Filter Pills (3, 5, 10) -->
                        <div style="display:flex;align-items:center;gap:2px;background:#f1f5f9;padding:2px;border-radius:8px;border:1px solid #e2e8f0;" title="Pilih Jumlah Peringkat Teratas">
                            <?php foreach ([3, 5, 10] as $rNum): 
                                $isRActive = ($selectedTopRank === $rNum);
                                $topRankUrl = '?' . http_build_query(array_merge($_GET, ['top_rank' => $rNum]));
                            ?>
                                <a href="<?= htmlspecialchars($topRankUrl) ?>"
                                   style="padding:2px 7px;border-radius:5px;font-size:10px;font-weight:800;text-decoration:none;transition:all 0.2s;
                                          background:<?= $isRActive ? '#2563eb' : 'transparent' ?>;
                                          color:<?= $isRActive ? '#ffffff' : '#64748b' ?>;
                                          <?= $isRActive ? 'box-shadow:0 1px 2px rgba(37,99,235,0.25);' : '' ?>">
                                    <?= $rNum ?>
                                </a>
                            <?php endforeach; ?>
                        </div>

                        <!-- View Switcher (Grafik vs Tabel) -->
                        <div style="display:inline-flex;background:#f1f5f9;padding:2px;border-radius:8px;border:1px solid #e2e8f0;gap:2px;" title="Pilih Mode Tampilan">
                            <button type="button" id="btnDefectViewChart" onclick="switchDefectTimelineView('chart')"
                                    style="padding:3px 8px;font-size:10px;font-weight:800;border-radius:5px;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:4px;transition:all 0.15s;background:#ffffff;color:#2563eb;box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                                <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                                </svg>
                                Grafik
                            </button>
                            <button type="button" id="btnDefectViewTable" onclick="switchDefectTimelineView('table')"
                                    style="padding:3px 8px;font-size:10px;font-weight:700;border-radius:5px;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:4px;transition:all 0.15s;background:transparent;color:#64748b;">
                                <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M3 14h18m-9-4v8m-7 4h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                </svg>
                                Tabel
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Content Area: Exactly 240px to align with Trend Chart -->
                <div style="height:240px;min-height:240px;position:relative;">
                    
                    <!-- 1. VIEW GRAFIK (Stacked Bar Chart with Horizontal Scroll Container) -->
                    <div id="defectTimelineChartView" style="height:100%;width:100%;display:flex;flex-direction:column;">
                        <?php if (empty($distinctDefectTypes) && !$isPassedMode): ?>
                            <div style="height:100%;display:flex;align-items:center;justify-content:center;color:#cbd5e1;font-size:11px;background:#f8fafc;border-radius:8px;border:1px dashed #e2e8f0;">
                                Tidak ada catatan defect pada periode ini
                            </div>
                        <?php elseif (empty($activeDefectPeriodKeys)): ?>
                            <div style="height:100%;display:flex;align-items:center;justify-content:center;color:#cbd5e1;font-size:11px;background:#f8fafc;border-radius:8px;border:1px dashed #e2e8f0;">
                                Tidak ada catatan defect pada periode ini
                            </div>
                        <?php else: ?>
                            <?php 
                            $numPoints = count($activeDefectPeriodKeys);
                            $minCanvasWidth = ($numPoints > 7) ? ($numPoints * 48) : '100%';
                            $needsScroll = ($numPoints > 7);
                            ?>
                            <div style="flex:1;min-height:0;width:100%;overflow-x:<?= $needsScroll ? 'auto' : 'hidden' ?>;overflow-y:hidden;position:relative;">
                                <div style="min-width:<?= is_numeric($minCanvasWidth) ? ($minCanvasWidth . 'px') : $minCanvasWidth ?>;width:100%;height:100%;position:relative;">
                                    <canvas id="chartDefectStacked"></canvas>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- 2. VIEW TABEL (Transposed Matrix Table: Rows = Jenis NG, Cols = Tanggal) -->
                    <div id="defectTimelineTableView" style="height:100%;width:100%;display:none;overflow:auto;border:1px solid #e2e8f0;border-radius:8px;">
                        <?php if (empty($distinctDefectTypes) && !$isPassedMode): ?>
                            <div style="height:100%;display:flex;align-items:center;justify-content:center;color:#cbd5e1;font-size:11px;background:#f8fafc;">
                                Tidak ada catatan defect pada periode ini
                            </div>
                        <?php elseif (empty($activeDefectPeriodKeys)): ?>
                            <div style="height:100%;display:flex;align-items:center;justify-content:center;color:#cbd5e1;font-size:11px;background:#f8fafc;">
                                Tidak ada catatan defect pada periode ini
                            </div>
                        <?php else: ?>
                            <table style="width:100%;border-collapse:collapse;font-size:10.5px;text-align:left;">
                                <thead>
                                    <tr style="background:#1e293b;color:#ffffff;font-size:9.5px;font-weight:800;letter-spacing:0.04em;text-transform:uppercase;position:sticky;top:0;z-index:3;">
                                        <th style="padding:6px 10px;white-space:nowrap;border-bottom:1px solid #0f172a;position:sticky;left:0;background:#1e293b;z-index:4;">
                                            <?= $isPassedMode ? 'STATUS HASIL' : 'JENIS NG' ?>
                                        </th>
                                        <?php foreach ($activeDefectPeriodKeys as $dk): ?>
                                            <th style="padding:6px 8px;text-align:right;white-space:nowrap;border-bottom:1px solid #0f172a;">
                                                <?= htmlspecialchars($stackedPeriodLabels[$dk] ?? $dk) ?>
                                            </th>
                                        <?php endforeach; ?>
                                        <th style="padding:6px 10px;text-align:right;white-space:nowrap;border-bottom:1px solid #0f172a;background:#0f172a;color:#fbbf24;">TOTAL</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($isPassedMode): 
                                        $rowsPassedMode = [
                                            ['key' => 'passed', 'label' => 'Passed (Lulus)', 'color' => '#059669'],
                                            ['key' => 'rejected', 'label' => 'Rejected (Ditolak)', 'color' => '#dc2626']
                                        ];
                                        foreach ($rowsPassedMode as $rpm):
                                            $isPassRow = ($rpm['key'] === 'passed');
                                            $rowTotalPcs = 0;
                                    ?>
                                        <tr style="border-bottom:1px solid #f1f5f9;background:#ffffff;" class="hover:bg-blue-50">
                                            <td style="padding:5px 10px;font-weight:700;color:#1e293b;white-space:nowrap;position:sticky;left:0;background:#ffffff;z-index:2;">
                                                <span style="display:inline-block;width:7px;height:7px;border-radius:2px;background:<?= $rpm['color'] ?>;margin-right:4px;vertical-align:middle;"></span>
                                                <?= $rpm['label'] ?>
                                            </td>
                                            <?php foreach ($activeDefectPeriodKeys as $dk): 
                                                $s = (int)($trendMap[$dk]['sample'] ?? 0);
                                                $n = (int)($trendMap[$dk]['ng'] ?? 0);
                                                $val = $isPassRow ? max(0, $s - $n) : $n;
                                                $rowTotalPcs += $val;
                                                $ppmVal = ($s > 0) ? round(($val / $s) * 1000000, 1) : 0;
                                            ?>
                                                <td style="padding:5px 8px;text-align:right;font-family:monospace;font-weight:700;color:<?= $val > 0 ? $rpm['color'] : '#cbd5e1' ?>;">
                                                    <span class="defect-metric-val" data-pcs="<?= $val ?>" data-ppm="<?= $ppmVal ?>"><?= $val > 0 ? number_format($val) : '-' ?></span>
                                                </td>
                                            <?php endforeach; ?>
                                            <?php 
                                            $rowTotalPpm = ($grandTotSample > 0) ? round(($rowTotalPcs / $grandTotSample) * 1000000, 1) : 0;
                                            ?>
                                            <td style="padding:5px 10px;text-align:right;font-family:monospace;font-weight:900;color:<?= $rpm['color'] ?>;background:<?= $isPassRow ? '#f0fdf4' : '#fff1f2' ?>;">
                                                <span class="defect-metric-total-val" data-pcs="<?= $rowTotalPcs ?>" data-ppm="<?= $rowTotalPpm ?>"><?= number_format($rowTotalPcs) ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php else: ?>
                                        <?php foreach ($topNDefectTypes as $didx => $dname): 
                                            $thColor = $STACKED_DEFECT_PALETTE[$didx % count($STACKED_DEFECT_PALETTE)];
                                            $rowTotalPcs = (int)($defectTypeTotals[$dname] ?? 0);
                                            $rowTotalPpm = ($grandTotSample > 0) ? round(($rowTotalPcs / $grandTotSample) * 1000000, 1) : 0;
                                        ?>
                                            <tr style="border-bottom:1px solid #f1f5f9;background:#ffffff;" class="hover:bg-blue-50">
                                                <td style="padding:5px 10px;font-weight:700;color:#1e293b;white-space:nowrap;position:sticky;left:0;background:#ffffff;z-index:2;">
                                                    <span style="display:inline-block;width:7px;height:7px;border-radius:2px;background:<?= $thColor ?>;margin-right:4px;vertical-align:middle;"></span>
                                                    <?= htmlspecialchars($dname) ?>
                                                </td>
                                                <?php foreach ($activeDefectPeriodKeys as $dk): 
                                                    $valPcs = (int)($defectMatrix[$dk][$dname] ?? 0);
                                                    $sample = (int)($trendMap[$dk]['sample'] ?? 0);
                                                    $valPpm = ($sample > 0) ? round(($valPcs / $sample) * 1000000, 1) : 0;
                                                ?>
                                                    <td style="padding:5px 8px;text-align:right;font-family:monospace;font-weight:<?= $valPcs > 0 ? '700' : '400' ?>;color:<?= $valPcs > 0 ? '#0f172a' : '#cbd5e1' ?>;">
                                                        <span class="defect-metric-val" data-pcs="<?= $valPcs ?>" data-ppm="<?= $valPpm ?>"><?= $valPcs > 0 ? number_format($valPcs) : '-' ?></span>
                                                    </td>
                                                <?php endforeach; ?>
                                                <td style="padding:5px 10px;text-align:right;font-family:monospace;font-weight:900;color:#dc2626;background:#fff1f2;">
                                                    <span class="defect-metric-total-val" data-pcs="<?= $rowTotalPcs ?>" data-ppm="<?= $rowTotalPpm ?>"><?= number_format($rowTotalPcs) ?></span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (!empty($otherNDefectTypes)): 
                                            $rowTotalOtherPcs = 0;
                                            foreach ($otherNDefectTypes as $odn) {
                                                $rowTotalOtherPcs += (int)($defectTypeTotals[$odn] ?? 0);
                                            }
                                            if ($rowTotalOtherPcs > 0):
                                                $rowTotalOtherPpm = ($grandTotSample > 0) ? round(($rowTotalOtherPcs / $grandTotSample) * 1000000, 1) : 0;
                                        ?>
                                            <tr style="border-bottom:1px solid #f1f5f9;background:#f8fafc;" class="hover:bg-slate-100">
                                                <td style="padding:5px 10px;font-weight:700;color:#64748b;white-space:nowrap;position:sticky;left:0;background:#f8fafc;z-index:2;">
                                                    <span style="display:inline-block;width:7px;height:7px;border-radius:2px;background:#94a3b8;margin-right:4px;vertical-align:middle;"></span>
                                                    <?= 'Others (' . count($otherNDefectTypes) . ' jenis lainnya)' ?>
                                                </td>
                                                <?php foreach ($activeDefectPeriodKeys as $dk): 
                                                    $valPcs = 0;
                                                    foreach ($otherNDefectTypes as $odn) {
                                                        $valPcs += (int)($defectMatrix[$dk][$odn] ?? 0);
                                                    }
                                                    $sample = (int)($trendMap[$dk]['sample'] ?? 0);
                                                    $valPpm = ($sample > 0) ? round(($valPcs / $sample) * 1000000, 1) : 0;
                                                ?>
                                                    <td style="padding:5px 8px;text-align:right;font-family:monospace;font-weight:<?= $valPcs > 0 ? '700' : '400' ?>;color:<?= $valPcs > 0 ? '#64748b' : '#cbd5e1' ?>;">
                                                        <span class="defect-metric-val" data-pcs="<?= $valPcs ?>" data-ppm="<?= $valPpm ?>"><?= $valPcs > 0 ? number_format($valPcs) : '-' ?></span>
                                                    </td>
                                                <?php endforeach; ?>
                                                <td style="padding:5px 10px;text-align:right;font-family:monospace;font-weight:900;color:#64748b;background:#f1f5f9;">
                                                    <span class="defect-metric-total-val" data-pcs="<?= $rowTotalOtherPcs ?>" data-ppm="<?= $rowTotalOtherPpm ?>"><?= number_format($rowTotalOtherPcs) ?></span>
                                                </td>
                                            </tr>
                                        <?php endif; endif; ?>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot>
                                    <tr style="background:#f8fafc;font-weight:800;border-top:2px solid #cbd5e1;position:sticky;bottom:0;z-index:2;">
                                        <td style="padding:6px 10px;color:#1e293b;font-size:10px;text-transform:uppercase;position:sticky;left:0;background:#f8fafc;z-index:2;">TOTAL</td>
                                        <?php 
                                        $grandSumPcs = 0;
                                        foreach ($activeDefectPeriodKeys as $dk): 
                                            if ($isPassedMode) {
                                                $colPcs = (int)($trendMap[$dk]['sample'] ?? 0);
                                            } else {
                                                $colPcs = (int)($defectDateTotals[$dk] ?? 0);
                                            }
                                            $grandSumPcs += $colPcs;
                                            $colSample = (int)($trendMap[$dk]['sample'] ?? 0);
                                            $colPpm = ($colSample > 0) ? round(($colPcs / $colSample) * 1000000, 1) : 0;
                                        ?>
                                            <td style="padding:6px 8px;text-align:right;font-family:monospace;color:#0f172a;">
                                                <span class="defect-metric-total-val" data-pcs="<?= $colPcs ?>" data-ppm="<?= $colPpm ?>"><?= number_format($colPcs) ?></span>
                                            </td>
                                        <?php endforeach; ?>
                                        <?php 
                                        $grandSumPpm = ($grandTotSample > 0) ? round(($grandSumPcs / $grandTotSample) * 1000000, 1) : 0;
                                        ?>
                                        <td style="padding:6px 10px;text-align:right;font-family:monospace;color:#dc2626;background:#fee2e2;">
                                            <span class="defect-metric-total-val" data-pcs="<?= $grandSumPcs ?>" data-ppm="<?= $grandSumPpm ?>"><?= number_format($grandSumPcs) ?></span>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        <?php endif; ?>
                    </div>

                </div>
            </div>

        </div>

        <!-- Page Break for Clean 2-Page Print Layout -->
        <div class="print-page-break"></div>

        <!-- ── Chart Row 2: Defect Donut + Model Pie + Part Pie (3 Distinct Visual Identities) ─────── -->
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;" id="dash-row-2">

            <!-- Card 1: Breakdown Jenis Defect / Rasio Yield Status -->
            <div class="card" style="padding:14px 16px;display:flex;flex-direction:column;border-top:3px solid <?= $isPassedMode ? '#059669' : '#ef4444' ?>;">
                <div style="flex-shrink:0;margin-bottom:10px;display:flex;align-items:center;gap:8px;">
                    <div style="width:28px;height:28px;border-radius:8px;background:<?= $isPassedMode ? '#d1fae5' : '#fee2e2' ?>;color:<?= $isPassedMode ? '#059669' : '#dc2626' ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <?php if ($isPassedMode): ?>
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.45 1-1 1H7"/><path d="M14 14.66V17c0 .55.45 1 1 1h2"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
                        <?php else: ?>
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div style="font-size:13px;font-weight:800;color:#1e293b;"><?= $isPassedMode ? 'Rasio Status Inspeksi (Yield)' : ('Top ' . $selectedTopRank . ' Jenis Defect') ?></div>
                        <div style="font-size:10px;color:#94a3b8;"><?= $isPassedMode ? 'Perbandingan hasil passed vs rejected' : ($selectedUnit === 'lot' ? 'Distribusi lot terinfeksi cacat' : 'Distribusi kuantitas cacat (pcs)') ?></div>
                    </div>
                </div>
                <?php if (empty($defectCounts)): ?>
                    <div style="height:160px;display:flex;align-items:center;justify-content:center;color:#cbd5e1;font-size:11px;background:#f8fafc;border-radius:8px;border:1px dashed #e2e8f0;">Belum ada data</div>
                <?php else: ?>
                    <div style="display:flex;gap:12px;align-items:center;min-height:160px;">
                        <div style="width:130px;height:130px;flex-shrink:0;position:relative;display:flex;align-items:center;justify-content:center;">
                            <canvas id="chartDefect" style="width:100%;height:100%;"></canvas>
                            <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none;text-align:center;">
                                <span style="font-size:8.5px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:0.04em;"><?= $isPassedMode ? 'TOTAL' : 'TOTAL NG' ?></span>
                                <span style="font-size:15px;font-weight:900;color:#0f172a;line-height:1.1;"><?= number_format(array_sum($defectCounts)) ?></span>
                                <span style="font-size:8.5px;font-weight:700;color:#64748b;"><?= strtolower($unitText) ?></span>
                            </div>
                        </div>
                        <div style="flex:1;min-width:0;display:flex;flex-direction:column;gap:5px;max-height:160px;overflow-y:auto;">
                            <?php $totalD = array_sum($defectCounts) ?: 1;
                            foreach ($defectLabels as $di => $dl):
                                $dpct = round($defectCounts[$di]/$totalD*100,1);
                                $dc   = $DEFECT_PALETTE[$di%count($DEFECT_PALETTE)]; ?>
                            <div style="display:flex;align-items:center;justify-content:space-between;font-size:10.5px;gap:6px;padding:3px 6px;border-radius:6px;background:#f8fafc;border-left:2.5px solid <?= $dc ?>;">
                                <span style="display:flex;align-items:center;gap:5px;color:#334155;flex:1;min-width:0;">
                                    <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:700;"><?= htmlspecialchars($dl) ?></span>
                                </span>
                                <span style="font-weight:800;color:#0f172a;flex-shrink:0;"><?= number_format($defectCounts[$di]) ?> <span style="font-weight:500;color:#64748b;"><?= strtolower($unitText) ?> (<?= $dpct ?>%)</span></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Card 2: Distribusi NG per Model -->
            <div class="card" style="padding:14px 16px;display:flex;flex-direction:column;border-top:3px solid #6366f1;">
                <div style="flex-shrink:0;margin-bottom:10px;display:flex;align-items:center;gap:8px;">
                    <div style="width:28px;height:28px;border-radius:8px;background:#e0e7ff;color:#4f46e5;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
                    </div>
                    <div>
                        <div style="font-size:13px;font-weight:800;color:#1e293b;">
                            <?php if ($isPassedMode): ?>
                                <?= "Top {$selectedTopRank} Model (Passed)" ?>
                            <?php else: ?>
                                <?= "Top {$selectedTopRank} Model (NG)" ?>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:10px;color:#94a3b8;">Proporsi berdasarkan model produk (<?= strtolower($unitText) ?>)</div>
                    </div>
                </div>
                <?php if (empty($modelCounts)): ?>
                    <div style="height:160px;display:flex;align-items:center;justify-content:center;color:#cbd5e1;font-size:11px;background:#f8fafc;border-radius:8px;border:1px dashed #e2e8f0;">Belum ada data per model</div>
                <?php else: ?>
                    <div style="display:flex;gap:12px;align-items:center;min-height:160px;">
                        <div style="width:130px;height:130px;flex-shrink:0;position:relative;display:flex;align-items:center;justify-content:center;">
                            <canvas id="chartModelPie" style="width:100%;height:100%;"></canvas>
                            <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none;text-align:center;">
                                <span style="font-size:8.5px;font-weight:800;color:#6366f1;text-transform:uppercase;letter-spacing:0.04em;">MODEL</span>
                                <span style="font-size:15px;font-weight:900;color:#1e1b4b;line-height:1.1;"><?= count($modelCounts) ?></span>
                                <span style="font-size:8.5px;font-weight:700;color:#64748b;">tipe</span>
                            </div>
                        </div>
                        <div style="flex:1;min-width:0;display:flex;flex-direction:column;gap:5px;max-height:160px;overflow-y:auto;">
                            <?php $totalM = array_sum($modelCounts) ?: 1;
                            foreach ($modelLabels as $mi => $ml):
                                $mpct = round($modelCounts[$mi]/$totalM*100,1);
                                $mc   = $MODEL_PALETTE[$mi%count($MODEL_PALETTE)]; ?>
                            <div style="display:flex;align-items:center;justify-content:space-between;font-size:10.5px;gap:6px;padding:3px 6px;border-radius:6px;background:#f8fafc;border-left:2.5px solid <?= $mc ?>;">
                                <span style="display:flex;align-items:center;gap:5px;color:#334155;flex:1;min-width:0;">
                                    <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:700;"><?= htmlspecialchars($ml) ?></span>
                                </span>
                                <span style="font-weight:800;color:#0f172a;flex-shrink:0;"><?= number_format($modelCounts[$mi]) ?> <span style="font-weight:500;color:#64748b;"><?= strtolower($unitText) ?> (<?= $mpct ?>%)</span></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Card 3: Distribusi NG per Part -->
            <div class="card" style="padding:14px 16px;display:flex;flex-direction:column;border-top:3px solid #0d9488;">
                <div style="flex-shrink:0;margin-bottom:10px;display:flex;align-items:center;gap:8px;">
                    <div style="width:28px;height:28px;border-radius:8px;background:#ccfbf1;color:#0d9488;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
                    </div>
                    <div>
                        <div style="font-size:13px;font-weight:800;color:#1e293b;">
                            <?php if ($isPassedMode): ?>
                                <?= "Top {$selectedTopRank} Part (Passed)" ?>
                            <?php else: ?>
                                <?= "Top {$selectedTopRank} Part (NG)" ?>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:10px;color:#94a3b8;">Proporsi berdasarkan part (<?= strtolower($unitText) ?>)</div>
                    </div>
                </div>
                <?php if (empty($pieCounts)): ?>
                    <div style="height:160px;display:flex;align-items:center;justify-content:center;color:#cbd5e1;font-size:11px;background:#f8fafc;border-radius:8px;border:1px dashed #e2e8f0;">Belum ada data per part</div>
                <?php else: ?>
                    <div style="display:flex;gap:12px;align-items:center;min-height:160px;">
                        <div style="width:130px;height:130px;flex-shrink:0;position:relative;display:flex;align-items:center;justify-content:center;">
                            <canvas id="chartPartPie" style="width:100%;height:100%;"></canvas>
                            <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none;text-align:center;">
                                <span style="font-size:8.5px;font-weight:800;color:#0d9488;text-transform:uppercase;letter-spacing:0.04em;">PART</span>
                                <span style="font-size:15px;font-weight:900;color:#134e4a;line-height:1.1;"><?= count($pieCounts) ?></span>
                                <span style="font-size:8.5px;font-weight:700;color:#64748b;">item</span>
                            </div>
                        </div>
                        <div style="flex:1;min-width:0;display:flex;flex-direction:column;gap:5px;max-height:160px;overflow-y:auto;">
                            <?php $totalP = array_sum($pieCounts) ?: 1;
                            foreach ($pieLabels as $pi => $pl):
                                $ppct = round($pieCounts[$pi]/$totalP*100,1);
                                $pc   = $PART_PALETTE[$pi%count($PART_PALETTE)]; ?>
                            <div style="display:flex;align-items:center;justify-content:space-between;font-size:10.5px;gap:6px;padding:3px 6px;border-radius:6px;background:#f8fafc;border-left:2.5px solid <?= $pc ?>;">
                                <span style="display:flex;align-items:center;gap:5px;color:#334155;flex:1;min-width:0;">
                                    <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:700;"><?= htmlspecialchars($pl) ?></span>
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
                                        <div style="display:inline-flex;align-items:center;gap:6px;">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.45 1-1 1H7"/><path d="M14 14.66V17c0 .55.45 1 1 1h2"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
                                            <span>TOP PASSED PART &amp; MODEL PERFORMANCE</span>
                                        </div>
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
                                            <div style="display:inline-flex;align-items:center;gap:6px;">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                                <span><?= htmlspecialchars($defectCategory) ?></span>
                                            </div>
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

        <!-- ── Print Footer Note ───────────────────────────────────────────── -->
        <div class="print-only" style="margin-top:12px;padding-top:8px;border-top:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;font-size:8.5px;color:#94a3b8;">
            <span>* Laporan ini dihasilkan secara otomatis oleh OQC Quality Control System — PT. Surya Technology Industri</span>
            <span>Halaman 2 / 2</span>
        </div>

    </div><!-- end dash-body -->

    <!-- Mobile, Responsive & Print CSS Engine -->
    <style>
        .print-only { display: none !important; }
        .print-page-break { display: none !important; }

        @media (max-width: 1200px) {
            #kpi-grid { grid-template-columns: repeat(3, 1fr) !important; }
        }
        @media (max-width: 1024px) {
            #main-content-wrapper { height:auto !important; overflow:auto !important; }
            #dash-body { overflow:auto !important; }
            #kpi-grid   { grid-template-columns: repeat(2, 1fr) !important; }
            #dash-row-1,#dash-row-2 { grid-template-columns: 1fr !important; flex: none !important; }
        }
        @media (max-width: 640px) {
            #kpi-grid { grid-template-columns: 1fr !important; }
        }

        /* ── Optimalisasi Cetak Dokumen A4 Landscape ─────────────────────── */
        @media print {
            @page {
                size: A4 landscape;
                margin: 7mm 8mm 7mm 8mm;
            }

            /* Sembunyikan elemen navigasi web, sidebar, header web, dan tombol aksi */
            #sidebar,
            nav,
            header,
            .navbar,
            #main-content-wrapper > nav,
            .no-print,
            button,
            .badge,
            #filterTypeSelect,
            #modalKpiDrilldown,
            .kpi-clickable-card span[style*="underline"],
            .kpi-clickable-card svg {
                display: none !important;
            }

            /* Tampilkan elemen khusus dokumen cetak */
            .print-only {
                display: block !important;
            }
            .print-page-break {
                display: block !important;
                page-break-after: always !important;
                break-after: page !important;
                height: 0 !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            /* Reset halaman ke kanvas putih cetak */
            html, body {
                background: #ffffff !important;
                color: #0f172a !important;
                font-size: 8.5pt !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                height: auto !important;
                min-height: auto !important;
                overflow: visible !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            #main-content-wrapper {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                min-height: auto !important;
                height: auto !important;
                background: #ffffff !important;
                position: static !important;
                display: block !important;
            }

            #dash-body {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                overflow: visible !important;
                display: block !important;
            }

            /* Kartu laporan pada lembar fisik */
            .card {
                box-shadow: none !important;
                border: 1px solid #cbd5e1 !important;
                border-radius: 8px !important;
                background: #ffffff !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                margin-bottom: 8px !important;
            }

            /* 5 Kartu KPI utama terbagi 5 kolom rapi */
            #kpi-grid {
                display: grid !important;
                grid-template-columns: repeat(5, 1fr) !important;
                gap: 8px !important;
                margin-bottom: 10px !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }

            .kpi-clickable-card {
                cursor: default !important;
                transform: none !important;
                box-shadow: none !important;
                border: 1px solid #cbd5e1 !important;
                padding: 6px 8px !important;
            }

            /* Baris Grafik 1: Tren & Top Defect Matrix */
            #dash-row-1 {
                display: grid !important;
                grid-template-columns: 1.25fr 1fr !important;
                gap: 10px !important;
                margin-bottom: 0 !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }

            /* Baris Grafik 2: 3 Donut Charts */
            #dash-row-2 {
                display: grid !important;
                grid-template-columns: repeat(3, 1fr) !important;
                gap: 10px !important;
                margin-top: 6px !important;
                margin-bottom: 10px !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }

            /* Chart & Table Matrix */
            canvas {
                max-width: 100% !important;
            }

            #defectTimelineChartView,
            #defectTimelineTableView {
                overflow: visible !important;
                height: auto !important;
                max-height: none !important;
            }

            /* Tabel evaluasi part */
            table {
                page-break-inside: auto !important;
                width: 100% !important;
            }
            tr {
                page-break-inside: avoid !important;
                page-break-after: auto !important;
            }
            thead {
                display: table-header-group !important;
            }
        }
    </style>

    <script src="<?= base_url('assets/js/vendor/chart.min.js') ?>"></script>
    <script>
    Chart.defaults.font.family = "'system-ui','Segoe UI',sans-serif";
    Chart.defaults.color       = '#94a3b8';
    var activeUnit             = '<?= $unitText ?>';
    var activeSampleBasis      = '<?= $selectedSampleBasis ?>';
    var isPassedMode           = <?= $isPassedMode ? 'true' : 'false' ?>;

    var trendChartInstance     = null;
    var trendLabelsData        = <?= $jsLabels ?>;
    var trendNGData            = <?= $jsTrendNG ?>;
    var trendSampleData        = <?= $jsTrendSample ?>;
    var currentChartType       = localStorage.getItem('oqc_trend_chart_type') || 'line';
    var currentMetricScale     = localStorage.getItem('oqc_trend_metric_scale') || 'ppm';
    if (currentMetricScale === 'ppb') currentMetricScale = 'pct';

    function calculateTrendMetricData(scale) {
        var isPct = (scale === 'pct' || scale === 'percent' || scale === '%');
        var multiplier = isPct ? 100 : 1000000;
        return trendLabelsData.map(function(_, idx) {
            var sample = Number(trendSampleData[idx] || 0);
            var ng = Number(trendNGData[idx] || 0);
            if (sample <= 0) return 0;
            var count = isPassedMode ? Math.max(0, sample - ng) : ng;
            var val = (count / sample) * multiplier;
            return isPct ? Math.round(val * 100) / 100 : Math.round(val * 10) / 10;
        });
    }

    function initTrendChart(type, scale) {
        var ctx = document.getElementById('chartTrendNG');
        if (!ctx) return;
        
        type  = type || currentChartType;
        scale = scale || currentMetricScale;

        if (trendChartInstance) {
            trendChartInstance.destroy();
        }

        var isBar       = (type === 'bar');
        var isPct       = (scale === 'pct' || scale === 'percent' || scale === '%');
        var scaleUnit   = isPct ? '%' : 'PPM';
        var metricData  = calculateTrendMetricData(scale);

        var datasetConfig = {
            label: isPassedMode ? ('Yield Rate (' + scaleUnit + ')') : ('Defect Rate (' + scaleUnit + ')'),
            data: metricData,
            borderColor: '<?= $lineColor ?>',
            backgroundColor: isBar ? '<?= $lineColor ?>' : '<?= $lineBgColor ?>',
            borderWidth: isBar ? 0 : 2.5,
            borderRadius: isBar ? 5 : 0,
            barPercentage: 0.55,
            categoryPercentage: 0.8,
            pointBackgroundColor: '<?= $lineColor ?>',
            pointBorderColor: '#fff',
            pointBorderWidth: 2,
            pointRadius: isBar ? 0 : 3.5,
            pointHoverRadius: 6,
            fill: !isBar,
            tension: 0.35
        };

        trendChartInstance = new Chart(ctx, {
            type: type,
            data: {
                labels: trendLabelsData,
                datasets: [datasetConfig]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        backgroundColor: '#0f172a',
                        titleColor: '#f8fafc',
                        bodyColor: '#e2e8f0',
                        padding: 10,
                        cornerRadius: 8,
                        callbacks: {
                            label: function(context) {
                                var val = Number(context.raw || 0).toLocaleString(undefined, {minimumFractionDigits: isPct ? 1 : 0, maximumFractionDigits: isPct ? 2 : 1});
                                return (isPassedMode ? ' Yield Rate: ' : ' Defect Rate: ') + val + (isPct ? '%' : ' PPM');
                            },
                            afterLabel: function(context) {
                                var idx = context.dataIndex;
                                var ng = Number(trendNGData[idx] || 0).toLocaleString();
                                var sp = Number(trendSampleData[idx] || 0).toLocaleString();
                                if (activeUnit.toLowerCase() === 'lot') {
                                    return ' (' + ng + (isPassedMode ? ' lot pass / ' : ' lot reject / ') + sp + ' lot dicek)';
                                } else {
                                    var basisText = (activeSampleBasis === 'session') ? ' sample sesi' : ' sample lot';
                                    return ' (' + ng + (isPassedMode ? ' pass / ' : ' defect / ') + sp + basisText + ' dicek)';
                                }
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 10, weight: '600' }, color: '#64748b' }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: '#f1f5f9' },
                        ticks: {
                            font: { size: 10 },
                            color: '#64748b',
                            callback: function(value) {
                                return Number(value).toLocaleString() + (isPct ? '%' : '');
                            }
                        }
                    }
                }
            }
        });

        updateChartSwitcherUI(type);
        updateMetricSwitcherUI(scale);
    }

    function switchTrendChartType(type) {
        currentChartType = type;
        localStorage.setItem('oqc_trend_chart_type', type);
        initTrendChart(type, currentMetricScale);
    }

    function updateKpiMetricCard(scale) {
        var totalInspected = <?= (int)$kpi['total_inspected'] ?>;
        var totalNG = <?= (int)$kpi['total_ng'] ?>;
        var isPct = (scale === 'pct' || scale === 'percent' || scale === '%');
        var mult = isPct ? 100 : 1000000;
        var rate = totalInspected > 0 ? (totalNG / totalInspected) * mult : 0;
        
        var valEl = document.getElementById('kpiMetricRateVal');
        if (valEl) valEl.textContent = rate.toLocaleString(undefined, {minimumFractionDigits: 1, maximumFractionDigits: isPct ? 2 : 1});

        var scaleUnitEl = document.getElementById('kpiMetricScaleLabel');
        if (scaleUnitEl) scaleUnitEl.textContent = (isPct ? '%' : 'PPM');
    }

    function switchTrendMetricScale(scale) {
        currentMetricScale = scale;
        localStorage.setItem('oqc_trend_metric_scale', scale);
        
        var isPct = (scale === 'pct' || scale === 'percent' || scale === '%');
        var titleUnit = document.getElementById('trendChartUnitText');
        if (titleUnit) titleUnit.textContent = (isPct ? '%' : 'PPM');

        var badgeText = document.getElementById('trendChartBadgeText');
        if (badgeText) badgeText.textContent = (isPct ? 'Persentase (%)' : 'Parts Per Million');

        updateKpiMetricCard(scale);
        initTrendChart(currentChartType, scale);
    }

    function updateChartSwitcherUI(type) {
        var btnLine = document.getElementById('btnChartTypeLine');
        var btnBar  = document.getElementById('btnChartTypeBar');
        if (!btnLine || !btnBar) return;

        if (type === 'line') {
            btnLine.style.background = '#ffffff';
            btnLine.style.color = '#2563eb';
            btnLine.style.boxShadow = '0 1px 2px rgba(0,0,0,0.08)';
            btnBar.style.background = 'transparent';
            btnBar.style.color = '#64748b';
            btnBar.style.boxShadow = 'none';
        } else {
            btnBar.style.background = '#ffffff';
            btnBar.style.color = '#2563eb';
            btnBar.style.boxShadow = '0 1px 2px rgba(0,0,0,0.08)';
            btnLine.style.background = 'transparent';
            btnLine.style.color = '#64748b';
            btnLine.style.boxShadow = 'none';
        }
    }

    function updateMetricSwitcherUI(scale) {
        var btnPPM = document.getElementById('btnMetricPPM');
        var btnPCT = document.getElementById('btnMetricPCT') || document.getElementById('btnMetricPPB');
        if (!btnPPM || !btnPCT) return;

        var isPct = (scale === 'pct' || scale === 'percent' || scale === '%');
        if (isPct) {
            btnPCT.style.background = '#ffffff';
            btnPCT.style.color = '#2563eb';
            btnPCT.style.boxShadow = '0 1px 2px rgba(0,0,0,0.08)';
            btnPPM.style.background = 'transparent';
            btnPPM.style.color = '#64748b';
            btnPPM.style.boxShadow = 'none';
        } else {
            btnPPM.style.background = '#ffffff';
            btnPPM.style.color = '#2563eb';
            btnPPM.style.boxShadow = '0 1px 2px rgba(0,0,0,0.08)';
            btnPCT.style.background = 'transparent';
            btnPCT.style.color = '#64748b';
            btnPCT.style.boxShadow = 'none';
        }
    }

    // Initialize KPI Card and Chart with current metric scale
    updateKpiMetricCard(currentMetricScale);

    <?php if (!empty($trendLabels)): ?>
    var initBadge = document.getElementById('trendChartBadgeText');
    var isPctInit = (currentMetricScale === 'pct' || currentMetricScale === 'percent' || currentMetricScale === '%');
    if (initBadge && isPctInit) {
        initBadge.textContent = 'Persentase (%)';
    }
    var initUnit = document.getElementById('trendChartUnitText');
    if (initUnit && isPctInit) {
        initUnit.textContent = '%';
    }
    initTrendChart(currentChartType, currentMetricScale);
    <?php endif; ?>

    // ── Defect Timeline Stacked Chart & View/Metric Switcher ─────────────────
    var defectStackedChartInstance = null;
    var currentDefectViewMode      = localStorage.getItem('oqc_defect_view_mode') || 'chart';
    var currentDefectMetricUnit    = localStorage.getItem('oqc_defect_metric_unit') || 'pcs';

    function initDefectStackedChart() {
        var ctx = document.getElementById('chartDefectStacked');
        if (!ctx) return;

        if (defectStackedChartInstance) {
            defectStackedChartInstance.destroy();
        }

        var stackedDatasets = (currentDefectMetricUnit === 'ppm') ? <?= $jsStackedDatasetsPpm ?> : <?= $jsStackedDatasetsPcs ?>;
        var stackedLabels   = <?= $jsStackedLabels ?>;

        defectStackedChartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: stackedLabels,
                datasets: stackedDatasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                maxBarThickness: 45,
                categoryPercentage: 0.7,
                barPercentage: 0.6,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom',
                        labels: {
                            boxWidth: 9,
                            boxHeight: 9,
                            font: { size: 9.5, weight: '600' },
                            padding: 6,
                            usePointStyle: true,
                            pointStyle: 'rectRounded'
                        }
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        backgroundColor: '#0f172a',
                        titleColor: '#f8fafc',
                        bodyColor: '#e2e8f0',
                        footerColor: '#38bdf8',
                        footerFont: { weight: '800' },
                        padding: 10,
                        cornerRadius: 8,
                        filter: function(tooltipItem) {
                            return Number(tooltipItem.raw || 0) > 0;
                        },
                        itemSort: function(a, b) {
                            return Number(b.raw || 0) - Number(a.raw || 0);
                        },
                        callbacks: {
                            label: function(context) {
                                var val = Number(context.raw || 0).toLocaleString();
                                var unitStr = (currentDefectMetricUnit === 'ppm') ? 'PPM' : activeUnit.toLowerCase();
                                var ds = context.dataset;
                                var lines = [' ' + ds.label + ': ' + val + ' ' + unitStr];

                                var partMeta = (ds.part_meta && ds.part_meta[context.dataIndex]) ? ds.part_meta[context.dataIndex] : null;
                                if (partMeta && Array.isArray(partMeta) && partMeta.length > 0) {
                                    partMeta.forEach(function(pm) {
                                        var pCode = pm.code || '-';
                                        var pName = pm.name || '-';
                                        var pQty  = Number(pm.qty || 0).toLocaleString();
                                        lines.push('    └ Part: ' + pCode + ' (' + pName + ') • NG: ' + pQty + ' ' + activeUnit.toLowerCase());
                                    });
                                }
                                return lines;
                            },
                            footer: function(tooltipItems) {
                                var sum = 0;
                                tooltipItems.forEach(function(item) {
                                    sum += Number(item.raw || 0);
                                });
                                var unitStr = (currentDefectMetricUnit === 'ppm') ? 'PPM' : activeUnit.toLowerCase();
                                return 'Total: ' + sum.toLocaleString() + ' ' + unitStr;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        stacked: true,
                        grid: { display: false },
                        ticks: {
                            font: { size: 9, weight: '600' },
                            color: '#64748b',
                            maxRotation: 45,
                            autoSkip: false
                        }
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        grid: { color: '#f1f5f9' },
                        ticks: {
                            font: { size: 9 },
                            color: '#64748b',
                            precision: 0,
                            callback: function(val) {
                                return Number(val).toLocaleString() + (currentDefectMetricUnit === 'ppm' ? ' PPM' : '');
                            }
                        }
                    }
                }
            }
        });
    }

    function switchDefectMetricUnit(unit) {
        currentDefectMetricUnit = unit;
        localStorage.setItem('oqc_defect_metric_unit', unit);

        var btnPcs = document.getElementById('btnDefectUnitPcs');
        var btnPpm = document.getElementById('btnDefectUnitPpm');
        var badge  = document.getElementById('defectMetricScaleBadge');

        if (badge) badge.textContent = (unit === 'ppm' ? 'PPM' : 'PCS');

        if (btnPcs && btnPpm) {
            if (unit === 'ppm') {
                btnPpm.style.background = '#ffffff';
                btnPpm.style.color = '#2563eb';
                btnPpm.style.fontWeight = '800';
                btnPpm.style.boxShadow = '0 1px 2px rgba(0,0,0,0.05)';

                btnPcs.style.background = 'transparent';
                btnPcs.style.color = '#64748b';
                btnPcs.style.fontWeight = '700';
                btnPcs.style.boxShadow = 'none';
            } else {
                btnPcs.style.background = '#ffffff';
                btnPcs.style.color = '#2563eb';
                btnPcs.style.fontWeight = '800';
                btnPcs.style.boxShadow = '0 1px 2px rgba(0,0,0,0.05)';

                btnPpm.style.background = 'transparent';
                btnPpm.style.color = '#64748b';
                btnPpm.style.fontWeight = '700';
                btnPpm.style.boxShadow = 'none';
            }
        }

        // Update Chart
        if (defectStackedChartInstance) {
            var newDatasets = (unit === 'ppm') ? <?= $jsStackedDatasetsPpm ?> : <?= $jsStackedDatasetsPcs ?>;
            defectStackedChartInstance.data.datasets = newDatasets;
            defectStackedChartInstance.update();
        }

        // Update Table Cells
        var cells = document.querySelectorAll('.defect-metric-val');
        cells.forEach(function(cell) {
            var pcsVal = Number(cell.getAttribute('data-pcs') || 0);
            var ppmVal = Number(cell.getAttribute('data-ppm') || 0);
            if (unit === 'ppm') {
                cell.textContent = ppmVal > 0 ? (ppmVal.toLocaleString(undefined, {minimumFractionDigits: 1, maximumFractionDigits: 1})) : '-';
            } else {
                cell.textContent = pcsVal > 0 ? pcsVal.toLocaleString() : '-';
            }
        });

        var totCells = document.querySelectorAll('.defect-metric-total-val');
        totCells.forEach(function(cell) {
            var pcsVal = Number(cell.getAttribute('data-pcs') || 0);
            var ppmVal = Number(cell.getAttribute('data-ppm') || 0);
            if (unit === 'ppm') {
                cell.textContent = ppmVal.toLocaleString(undefined, {minimumFractionDigits: 1, maximumFractionDigits: 1});
            } else {
                cell.textContent = pcsVal.toLocaleString();
            }
        });
    }

    function switchDefectTimelineView(mode) {
        currentDefectViewMode = mode;
        localStorage.setItem('oqc_defect_view_mode', mode);

        var chartEl  = document.getElementById('defectTimelineChartView');
        var tableEl  = document.getElementById('defectTimelineTableView');
        var btnChart = document.getElementById('btnDefectViewChart');
        var btnTable = document.getElementById('btnDefectViewTable');

        if (!chartEl || !tableEl || !btnChart || !btnTable) return;

        if (mode === 'table') {
            chartEl.style.display = 'none';
            tableEl.style.display = 'block';

            btnTable.style.background = '#ffffff';
            btnTable.style.color = '#2563eb';
            btnTable.style.fontWeight = '800';
            btnTable.style.boxShadow = '0 1px 2px rgba(0,0,0,0.05)';

            btnChart.style.background = 'transparent';
            btnChart.style.color = '#64748b';
            btnChart.style.fontWeight = '700';
            btnChart.style.boxShadow = 'none';
        } else {
            tableEl.style.display = 'none';
            chartEl.style.display = 'flex';

            btnChart.style.background = '#ffffff';
            btnChart.style.color = '#2563eb';
            btnChart.style.fontWeight = '800';
            btnChart.style.boxShadow = '0 1px 2px rgba(0,0,0,0.05)';

            btnTable.style.background = 'transparent';
            btnTable.style.color = '#64748b';
            btnTable.style.fontWeight = '700';
            btnTable.style.boxShadow = 'none';

            if (defectStackedChartInstance) {
                defectStackedChartInstance.resize();
            }
        }
    }

    <?php if (!empty($stackedChartLabels)): ?>
    initDefectStackedChart();
    switchDefectMetricUnit(currentDefectMetricUnit);
    switchDefectTimelineView(currentDefectViewMode);
    <?php endif; ?>

    <?php if (!empty($defectCounts)): ?>
    new Chart(document.getElementById('chartDefect'), {
        type:'doughnut',
        data:{ 
            labels:<?= $jsDefectLabels ?>, 
            datasets:[{ 
                data:<?= $jsDefectCounts ?>, 
                backgroundColor:<?= $jsDefectColors ?>, 
                borderWidth:2.5, 
                borderColor:'#ffffff', 
                hoverOffset:5,
                borderRadius:4
            }] 
        },
        options:{ 
            responsive:true, 
            maintainAspectRatio:false, 
            cutout:'66%', 
            plugins:{ 
                legend:{display:false}, 
                tooltip:{ 
                    callbacks:{ 
                        label:function(c){ 
                            var t=c.dataset.data.reduce(function(a,b){return a+b;},0); 
                            return ' '+c.label+': '+c.raw+' '+activeUnit.toLowerCase()+' ('+Math.round(c.raw/t*100)+'%)'; 
                        } 
                    } 
                } 
            } 
        }
    });
    <?php endif; ?>

    <?php if (!empty($modelCounts)): ?>
    new Chart(document.getElementById('chartModelPie'), {
        type:'doughnut',
        data:{ 
            labels:<?= $jsModelLabels ?>, 
            datasets:[{ 
                data:<?= $jsModelCounts ?>, 
                backgroundColor:<?= $jsModelColors ?>, 
                borderWidth:2.5, 
                borderColor:'#ffffff', 
                hoverOffset:5,
                borderRadius:3
            }] 
        },
        options:{ 
            responsive:true, 
            maintainAspectRatio:false, 
            cutout:'52%', 
            plugins:{ 
                legend:{display:false}, 
                tooltip:{ 
                    callbacks:{ 
                        label:function(c){ 
                            var t=c.dataset.data.reduce(function(a,b){return a+b;},0); 
                            return ' Model '+c.label+': '+c.raw+' '+activeUnit.toLowerCase()+' ('+Math.round(c.raw/t*100)+'%)'; 
                        } 
                    } 
                } 
            } 
        }
    });
    <?php endif; ?>

    <?php if (!empty($pieCounts)): ?>
    new Chart(document.getElementById('chartPartPie'), {
        type:'doughnut',
        data:{ 
            labels:<?= $jsPieLabels ?>, 
            datasets:[{ 
                data:<?= $jsPieCounts ?>, 
                backgroundColor:<?= $jsPartColors ?>, 
                borderWidth:2, 
                borderColor:'#ffffff', 
                hoverOffset:5,
                borderRadius:4
            }] 
        },
        options:{ 
            responsive:true, 
            maintainAspectRatio:false, 
            cutout:'74%', 
            plugins:{ 
                legend:{display:false}, 
                tooltip:{ 
                    callbacks:{ 
                        label:function(c){ 
                            var t=c.dataset.data.reduce(function(a,b){return a+b;},0); 
                            return ' Part '+c.label+': '+c.raw+' '+activeUnit.toLowerCase()+' ('+Math.round(c.raw/t*100)+'%)'; 
                        } 
                    } 
                } 
            } 
        }
    });
    <?php endif; ?>

    function switchDateFilterMode(mode) {
        var cMY = document.getElementById('containerMonthYear');
        var cCD = document.getElementById('containerCustomDate');

        if (cMY) cMY.style.display = (mode === 'month_year') ? 'inline-flex' : 'none';
        if (cCD) cCD.style.display = (mode === 'custom') ? 'inline-flex' : 'none';

        var custParam = <?= !empty($selectedCustomer) ? json_encode('&customer=' . urlencode($selectedCustomer)) : "''" ?>;
        var unitParam = '&unit=' + encodeURIComponent('<?= $selectedUnit ?>');
        var resParam  = '&result=' + encodeURIComponent('<?= $selectedResult ?>');

        if (mode === 'all_years') {
            window.location.href = '?filter_type=all_years' + unitParam + resParam + custParam;
        } else if (mode === 'preset') {
            window.location.href = '?preset=bulanan' + unitParam + resParam + custParam;
        }
    }

    function printDashboardReport() {
        // Refresh & sesuaikan ukuran canvas Chart.js sebelum dialog cetak aktif
        if (trendChartInstance) {
            trendChartInstance.resize();
        }
        if (defectStackedChartInstance) {
            defectStackedChartInstance.resize();
        }
        setTimeout(function() {
            window.print();
        }, 150);
    }

    function exportDashboardExcel(btn) {
        var origText = btn ? btn.innerHTML : '';
        if (btn) {
            btn.innerHTML = '<svg class="w-3.5 h-3.5 animate-spin" style="display:inline-block;vertical-align:-2px;margin-right:6px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10" stroke-opacity="0.25"/><path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/></svg>Menyiapkan Excel...';
            btn.disabled = true;
        }

        var form = document.createElement('form');
        form.method = 'POST';
        form.action = 'export_excel.php';
        form.style.display = 'none';

        var params = {
            preset: '<?= htmlspecialchars($presetFilter) ?>',
            filter_type: '<?= htmlspecialchars($filterType) ?>',
            month: '<?= htmlspecialchars($selectedMonth) ?>',
            year: '<?= htmlspecialchars($selectedYear) ?>',
            customer: '<?= htmlspecialchars($selectedCustomer) ?>',
            unit: '<?= htmlspecialchars($selectedUnit) ?>',
            result: '<?= htmlspecialchars($selectedResult) ?>',
            top_rank: '<?= htmlspecialchars($selectedTopRank) ?>',
            metric_scale: currentMetricScale,
            defect_unit: currentDefectMetricUnit,
            start_date: '<?= htmlspecialchars($startDate) ?>',
            end_date: '<?= htmlspecialchars($endDate) ?>'
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

    // ── Universal KPI Drilldown Modal System ──────────────────────────────────
    var kpiDrilldownCache = null;
    var activeKpiTab      = null;

    function openKpiDrilldownModal(kpiType) {
        var modal = document.getElementById('modalKpiDrilldown');
        if (!modal) return;

        // Reset search input and container
        var searchInput = document.getElementById('kpiModalSearchInput');
        if (searchInput) searchInput.value = '';

        var statsContainer = document.getElementById('kpiModalStatsContainer');
        if (statsContainer) statsContainer.innerHTML = '';

        var tabsContainer = document.getElementById('kpiModalTabsContainer');
        if (tabsContainer) {
            tabsContainer.innerHTML = '';
            tabsContainer.style.display = 'none';
        }

        var bodyEl = document.getElementById('kpiModalBody');
        if (bodyEl) {
            bodyEl.innerHTML = '<div style="padding:48px 16px;text-align:center;color:#64748b;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;">' +
                               '<svg class="w-7 h-7 animate-spin" style="color:#2563eb;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10" stroke-opacity="0.25"/><path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/></svg>' +
                               '<span style="font-size:12.5px;font-weight:700;color:#334155;">Memuat rincian data analitik...</span>' +
                               '</div>';
        }

        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        // Set initial headers based on type
        var accentBar = document.getElementById('kpiModalAccentBar');
        var colorMap = {
            'sample_inspected': '#2563eb',
            'total_ng':         '#dc2626',
            'defect_rate':      '#d97706',
            'total_lot':        '#0284c7',
            'ng_lot':           '#e11d48',
            'passed_lot':       '#059669'
        };
        if (accentBar) accentBar.style.background = colorMap[kpiType] || '#2563eb';

        // Build query string matching current active filters
        var qs = new URLSearchParams(window.location.search);
        qs.set('kpi_type', kpiType);
        qs.set('metric_scale', currentMetricScale);

        fetch('ajax_kpi_drilldown.php?' + qs.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        })
        .then(function(json) {
            if (!json.success) {
                if (bodyEl) bodyEl.innerHTML = '<div style="padding:40px;text-align:center;color:#dc2626;font-size:12px;font-weight:700;">' + (json.message || 'Gagal memuat data.') + '</div>';
                return;
            }
            kpiDrilldownCache = json;
            renderKpiDrilldownContent(json);
        })
        .catch(function(err) {
            if (bodyEl) {
                bodyEl.innerHTML = '<div style="padding:40px;text-align:center;color:#dc2626;font-size:12px;font-weight:700;">Terjadi kesalahan saat memuat data: ' + err.message + '</div>';
            }
        });
    }

    function closeKpiDrilldownModal() {
        var modal = document.getElementById('modalKpiDrilldown');
        if (modal) modal.style.display = 'none';
        document.body.style.overflow = '';
        kpiDrilldownCache = null;
    }

    // Close on ESC key or backdrop click
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeKpiDrilldownModal();
    });
    var modalBackdropEl = document.getElementById('modalKpiDrilldown');
    if (modalBackdropEl) {
        modalBackdropEl.addEventListener('click', function(e) {
            if (e.target === this) closeKpiDrilldownModal();
        });
    }

    function renderKpiDrilldownContent(json) {
        var titleEl    = document.getElementById('kpiModalTitle');
        var subEl      = document.getElementById('kpiModalSubtitle');
        var badgePer   = document.getElementById('kpiModalBadgePeriod');
        var statsEl    = document.getElementById('kpiModalStatsContainer');
        var tabsEl     = document.getElementById('kpiModalTabsContainer');
        var bodyEl     = document.getElementById('kpiModalBody');

        if (titleEl)  titleEl.textContent  = json.title || 'Rincian KPI';
        if (subEl)    subEl.textContent    = json.subtitle || '';
        if (badgePer) badgePer.textContent = (json.period_label || '') + (json.customer_label && json.customer_label !== 'Semua Customer' ? ' &middot; ' + json.customer_label : '');

        // Render Stats Pills
        if (statsEl && json.stats && json.stats.length) {
            var statsHtml = '';
            json.stats.forEach(function(st) {
                statsHtml += '<div style="background:#f8fafc;border:1px solid #e2e8f0;padding:6px 12px;border-radius:8px;display:flex;align-items:center;gap:8px;font-size:11px;">' +
                             '<span style="color:#64748b;font-weight:600;">' + st.label + ':</span>' +
                             '<span style="font-weight:800;color:' + (st.color || '#0f172a') + ';">' + st.value + '</span>' +
                             '</div>';
            });
            statsEl.innerHTML = statsHtml;
        }

        // Handle Tabs for total_ng
        if (json.kpi_type === 'total_ng') {
            if (tabsEl) {
                tabsEl.style.display = 'inline-flex';
                activeKpiTab = activeKpiTab || 'by_part';
                var tabs = [
                    { key: 'by_part',   label: 'Rincian per Part' },
                    { key: 'by_defect', label: 'Rincian per Defect' },
                    { key: 'by_log',    label: 'Log Temuan Lapangan' }
                ];
                var tabsHtml = '';
                tabs.forEach(function(tb) {
                    var isAct = (tb.key === activeKpiTab);
                    tabsHtml += '<button type="button" onclick="switchKpiModalTab(\'' + tb.key + '\')" ' +
                                'style="padding:3px 10px;font-size:10.5px;font-weight:800;border-radius:6px;border:none;cursor:pointer;transition:all 0.15s;' +
                                (isAct ? 'background:#ffffff;color:#dc2626;box-shadow:0 1px 2px rgba(0,0,0,0.06);' : 'background:transparent;color:#64748b;') + '">' +
                                tb.label + '</button>';
                });
                tabsEl.innerHTML = tabsHtml;
            }
            renderTotalNgTable(activeKpiTab, json.data);
            return;
        }

        // Other KPI Cards render direct table
        switch (json.kpi_type) {
            case 'sample_inspected':
                renderSampleInspectedTable(json.data);
                break;
            case 'defect_rate':
                renderDefectRateTable(json.data, json.metric_scale);
                break;
            case 'total_lot':
                renderTotalLotTable(json.data);
                break;
            case 'ng_lot':
            case 'passed_lot':
                renderDetailLotTable(json.data, json.kpi_type === 'passed_lot');
                break;
            default:
                if (bodyEl) bodyEl.innerHTML = '<div style="padding:20px;text-align:center;color:#64748b;">Format data belum didukung.</div>';
        }
    }

    function switchKpiModalTab(tabKey) {
        activeKpiTab = tabKey;
        if (kpiDrilldownCache) {
            renderKpiDrilldownContent(kpiDrilldownCache);
        }
    }

    // ── Table Renderers ───────────────────────────────────────────────────────
    function renderSampleInspectedTable(rows) {
        var bodyEl = document.getElementById('kpiModalBody');
        if (!bodyEl) return;
        if (!rows || !rows.length) {
            bodyEl.innerHTML = renderEmptyState('Tidak ada data sampel diperiksa pada periode ini.');
            updateFooterCount(0, 0);
            return;
        }
        var html = '<table id="kpiDrilldownDataTable" style="width:100%;border-collapse:collapse;font-size:11px;text-align:left;">' +
                   '<thead><tr style="background:#1e293b;color:#ffffff;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;position:sticky;top:0;z-index:2;">' +
                   '<th style="padding:8px 10px;text-align:center;width:40px;">NO</th>' +
                   '<th style="padding:8px 10px;">KODE PART</th>' +
                   '<th style="padding:8px 10px;">NAMA PART</th>' +
                   '<th style="padding:8px 10px;">MODEL</th>' +
                   '<th style="padding:8px 10px;">CUSTOMER</th>' +
                   '<th style="padding:8px 10px;text-align:center;">TOTAL SESI</th>' +
                   '<th style="padding:8px 10px;text-align:center;">TOTAL LOT</th>' +
                   '<th style="padding:8px 10px;text-align:right;">SAMPEL DICEK (PCS)</th>' +
                   '<th style="padding:8px 10px;text-align:right;">KONTRIBUSI</th>' +
                   '</tr></thead><tbody>';

        rows.forEach(function(r, idx) {
            html += '<tr class="kpi-row-hover" style="border-bottom:1px solid #f1f5f9;">' +
                    '<td style="padding:7px 10px;text-align:center;font-weight:700;color:#94a3b8;">' + (idx + 1) + '</td>' +
                    '<td style="padding:7px 10px;font-family:monospace;font-weight:700;color:#1e293b;">' + escapeHtml(r.part_code) + '</td>' +
                    '<td style="padding:7px 10px;font-weight:800;color:#0f172a;">' + escapeHtml(r.part_name) + '</td>' +
                    '<td style="padding:7px 10px;color:#475569;">' + escapeHtml(r.model) + '</td>' +
                    '<td style="padding:7px 10px;color:#475569;">' + escapeHtml(r.customer) + '</td>' +
                    '<td style="padding:7px 10px;text-align:center;font-weight:700;color:#334155;">' + Number(r.total_sessions || 0).toLocaleString() + '</td>' +
                    '<td style="padding:7px 10px;text-align:center;font-weight:700;color:#334155;">' + Number(r.total_lots || 0).toLocaleString() + '</td>' +
                    '<td style="padding:7px 10px;text-align:right;font-family:monospace;font-weight:900;color:#2563eb;background:#eff6ff;">' + Number(r.total_sample_checked || 0).toLocaleString() + '</td>' +
                    '<td style="padding:7px 10px;text-align:right;font-weight:700;color:#64748b;">' + (r.percent_share || 0) + '%</td>' +
                    '</tr>';
        });

        html += '</tbody></table>';
        bodyEl.innerHTML = html;
        updateFooterCount(rows.length, rows.length);
    }

    function renderTotalNgTable(tabKey, dataObj) {
        var bodyEl = document.getElementById('kpiModalBody');
        if (!bodyEl) return;
        dataObj = dataObj || {};

        if (tabKey === 'by_part') {
            var rows = dataObj.by_part || [];
            if (!rows.length) {
                bodyEl.innerHTML = renderEmptyState('Tidak ada temuan part reject pada periode ini.');
                updateFooterCount(0, 0);
                return;
            }
            var html = '<table id="kpiDrilldownDataTable" style="width:100%;border-collapse:collapse;font-size:11px;text-align:left;">' +
                       '<thead><tr style="background:#1e293b;color:#ffffff;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;position:sticky;top:0;z-index:2;">' +
                       '<th style="padding:8px 10px;text-align:center;width:40px;">NO</th>' +
                       '<th style="padding:8px 10px;">KODE PART</th>' +
                       '<th style="padding:8px 10px;">NAMA PART</th>' +
                       '<th style="padding:8px 10px;">MODEL</th>' +
                       '<th style="padding:8px 10px;">CUSTOMER</th>' +
                       '<th style="padding:8px 10px;text-align:right;">SAMPEL DICEK</th>' +
                       '<th style="padding:8px 10px;text-align:right;">TOTAL NG (PCS)</th>' +
                       '<th style="padding:8px 10px;text-align:right;">DEFECT RATE</th>' +
                       '<th style="padding:8px 10px;text-align:right;">KONTRIBUSI</th>' +
                       '</tr></thead><tbody>';

            rows.forEach(function(r, idx) {
                html += '<tr class="kpi-row-hover" style="border-bottom:1px solid #f1f5f9;">' +
                        '<td style="padding:7px 10px;text-align:center;font-weight:700;color:#94a3b8;">' + (idx + 1) + '</td>' +
                        '<td style="padding:7px 10px;font-family:monospace;font-weight:700;color:#1e293b;">' + escapeHtml(r.part_code) + '</td>' +
                        '<td style="padding:7px 10px;font-weight:800;color:#0f172a;">' + escapeHtml(r.part_name) + '</td>' +
                        '<td style="padding:7px 10px;color:#475569;">' + escapeHtml(r.model) + '</td>' +
                        '<td style="padding:7px 10px;color:#475569;">' + escapeHtml(r.customer) + '</td>' +
                        '<td style="padding:7px 10px;text-align:right;font-family:monospace;color:#64748b;">' + Number(r.total_sample_checked || 0).toLocaleString() + '</td>' +
                        '<td style="padding:7px 10px;text-align:right;font-family:monospace;font-weight:900;color:#dc2626;background:#fff1f2;">' + Number(r.total_ng_pcs || 0).toLocaleString() + '</td>' +
                        '<td style="padding:7px 10px;text-align:right;font-family:monospace;font-weight:800;color:#b91c1c;">' + Number(r.ppm || 0).toLocaleString() + ' PPM</td>' +
                        '<td style="padding:7px 10px;text-align:right;font-weight:700;color:#64748b;">' + (r.percent_share || 0) + '%</td>' +
                        '</tr>';
            });
            html += '</tbody></table>';
            bodyEl.innerHTML = html;
            updateFooterCount(rows.length, rows.length);

        } else if (tabKey === 'by_defect') {
            var rows = dataObj.by_defect || [];
            if (!rows.length) {
                bodyEl.innerHTML = renderEmptyState('Tidak ada data jenis cacat pada periode ini.');
                updateFooterCount(0, 0);
                return;
            }
            var html = '<table id="kpiDrilldownDataTable" style="width:100%;border-collapse:collapse;font-size:11px;text-align:left;">' +
                       '<thead><tr style="background:#1e293b;color:#ffffff;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;position:sticky;top:0;z-index:2;">' +
                       '<th style="padding:8px 10px;text-align:center;width:40px;">NO</th>' +
                       '<th style="padding:8px 10px;">KODE DEFECT</th>' +
                       '<th style="padding:8px 10px;">JENIS CACAT / DEFECT</th>' +
                       '<th style="padding:8px 10px;text-align:right;">TOTAL NG (PCS)</th>' +
                       '<th style="padding:8px 10px;text-align:center;">LOT TERDAMPAK</th>' +
                       '<th style="padding:8px 10px;text-align:right;">KONTRIBUSI</th>' +
                       '</tr></thead><tbody>';

            rows.forEach(function(r, idx) {
                html += '<tr class="kpi-row-hover" style="border-bottom:1px solid #f1f5f9;">' +
                        '<td style="padding:7px 10px;text-align:center;font-weight:700;color:#94a3b8;">' + (idx + 1) + '</td>' +
                        '<td style="padding:7px 10px;font-family:monospace;font-weight:700;color:#475569;">' + escapeHtml(r.defect_code) + '</td>' +
                        '<td style="padding:7px 10px;font-weight:800;color:#0f172a;">' + escapeHtml(r.defect_name) + '</td>' +
                        '<td style="padding:7px 10px;text-align:right;font-family:monospace;font-weight:900;color:#dc2626;background:#fff1f2;">' + Number(r.total_qty_ng || 0).toLocaleString() + '</td>' +
                        '<td style="padding:7px 10px;text-align:center;font-weight:700;color:#334155;">' + Number(r.total_lots_affected || 0).toLocaleString() + ' lot</td>' +
                        '<td style="padding:7px 10px;text-align:right;font-weight:700;color:#64748b;">' + (r.percent_share || 0) + '%</td>' +
                        '</tr>';
            });
            html += '</tbody></table>';
            bodyEl.innerHTML = html;
            updateFooterCount(rows.length, rows.length);

        } else if (tabKey === 'by_log') {
            var rows = dataObj.by_log || [];
            if (!rows.length) {
                bodyEl.innerHTML = renderEmptyState('Tidak ada catatan temuan inspeksi.');
                updateFooterCount(0, 0);
                return;
            }
            var html = '<table id="kpiDrilldownDataTable" style="width:100%;border-collapse:collapse;font-size:11px;text-align:left;">' +
                       '<thead><tr style="background:#1e293b;color:#ffffff;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;position:sticky;top:0;z-index:2;">' +
                       '<th style="padding:8px 10px;text-align:center;width:40px;">NO</th>' +
                       '<th style="padding:8px 10px;">TANGGAL / WAKTU</th>' +
                       '<th style="padding:8px 10px;">NO LOT / KANBAN</th>' +
                       '<th style="padding:8px 10px;">PART</th>' +
                       '<th style="padding:8px 10px;">JENIS CACAT</th>' +
                       '<th style="padding:8px 10px;text-align:center;">QTY NG</th>' +
                       '<th style="padding:8px 10px;">CATATAN / STATUS SORTIR</th>' +
                       '</tr></thead><tbody>';

            rows.forEach(function(r, idx) {
                var sortStatus = (r.is_sorted == 1) 
                    ? '<span style="display:inline-block;padding:2px 7px;font-size:9.5px;font-weight:800;border-radius:5px;background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;">Telah Disortir</span>'
                    : '<span style="display:inline-block;padding:2px 7px;font-size:9.5px;font-weight:800;border-radius:5px;background:#fee2e2;color:#b91c1c;border:1px solid #fecdd3;">Perlu Sortir</span>';

                html += '<tr class="kpi-row-hover" style="border-bottom:1px solid #f1f5f9;">' +
                        '<td style="padding:7px 10px;text-align:center;font-weight:700;color:#94a3b8;">' + (idx + 1) + '</td>' +
                        '<td style="padding:7px 10px;white-space:nowrap;color:#475569;font-weight:600;">' + escapeHtml(r.created_at || r.started_at) + '</td>' +
                        '<td style="padding:7px 10px;font-family:monospace;font-weight:700;color:#1e293b;">' + escapeHtml(r.lot_number) + (r.ref_number && r.ref_number !== '-' ? ' <span style="font-size:9.5px;color:#64748b;">(' + escapeHtml(r.ref_number) + ')</span>' : '') + '</td>' +
                        '<td style="padding:7px 10px;">' +
                            '<div style="font-weight:800;color:#0f172a;">' + escapeHtml(r.part_name) + '</div>' +
                            '<div style="font-size:9.5px;color:#64748b;font-family:monospace;">' + escapeHtml(r.part_code) + ' &middot; ' + escapeHtml(r.model) + '</div>' +
                        '</td>' +
                        '<td style="padding:7px 10px;font-weight:800;color:#b91c1c;">' + escapeHtml(r.defect_name) + '</td>' +
                        '<td style="padding:7px 10px;text-align:center;font-family:monospace;font-weight:900;color:#dc2626;background:#fff1f2;">' + Number(r.qty_ng || 1) + '</td>' +
                        '<td style="padding:7px 10px;">' +
                            '<div style="display:flex;align-items:center;gap:6px;">' + sortStatus + ' <span style="color:#64748b;">' + escapeHtml(r.remark || r.sort_notes || '-') + '</span></div>' +
                        '</td>' +
                        '</tr>';
            });
            html += '</tbody></table>';
            bodyEl.innerHTML = html;
            updateFooterCount(rows.length, rows.length);
        }
    }

    function renderDefectRateTable(rows, scale) {
        var bodyEl = document.getElementById('kpiModalBody');
        if (!bodyEl) return;
        if (!rows || !rows.length) {
            bodyEl.innerHTML = renderEmptyState('Tidak ada data kualitas per part.');
            updateFooterCount(0, 0);
            return;
        }

        var isPct = (scale === 'pct' || scale === 'percent' || scale === '%');
        var rateLabel = isPct ? 'DEFECT RATE (%)' : 'DEFECT RATE (PPM)';

        var html = '<table id="kpiDrilldownDataTable" style="width:100%;border-collapse:collapse;font-size:11px;text-align:left;">' +
                   '<thead><tr style="background:#1e293b;color:#ffffff;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;position:sticky;top:0;z-index:2;">' +
                   '<th style="padding:8px 10px;text-align:center;width:40px;">NO</th>' +
                   '<th style="padding:8px 10px;">KODE PART</th>' +
                   '<th style="padding:8px 10px;">NAMA PART</th>' +
                   '<th style="padding:8px 10px;">MODEL</th>' +
                   '<th style="padding:8px 10px;">CUSTOMER</th>' +
                   '<th style="padding:8px 10px;text-align:right;">SAMPEL DICEK</th>' +
                   '<th style="padding:8px 10px;text-align:right;">TOTAL NG (PCS)</th>' +
                   '<th style="padding:8px 10px;text-align:right;">' + rateLabel + '</th>' +
                   '<th style="padding:8px 10px;text-align:center;">STATUS MUTU</th>' +
                   '</tr></thead><tbody>';

        rows.forEach(function(r, idx) {
            var badgeHtml = '';
            if (r.status_badge === 'kritis') {
                badgeHtml = '<span style="display:inline-block;padding:2px 8px;font-size:10px;font-weight:800;border-radius:5px;background:#fee2e2;color:#b91c1c;border:1px solid #fecdd3;">Kritis</span>';
            } else if (r.status_badge === 'waspada') {
                badgeHtml = '<span style="display:inline-block;padding:2px 8px;font-size:10px;font-weight:800;border-radius:5px;background:#fef3c7;color:#b45309;border:1px solid #fde68a;">Waspada</span>';
            } else {
                badgeHtml = '<span style="display:inline-block;padding:2px 8px;font-size:10px;font-weight:800;border-radius:5px;background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;">Aman</span>';
            }

            var rateValStr = isPct 
                ? (Number(r.pct || 0).toLocaleString(undefined, {minimumFractionDigits: 1, maximumFractionDigits: 2}) + ' %')
                : (Number(r.ppm || 0).toLocaleString() + ' PPM');

            html += '<tr class="kpi-row-hover" style="border-bottom:1px solid #f1f5f9;">' +
                    '<td style="padding:7px 10px;text-align:center;font-weight:700;color:#94a3b8;">' + (idx + 1) + '</td>' +
                    '<td style="padding:7px 10px;font-family:monospace;font-weight:700;color:#1e293b;">' + escapeHtml(r.part_code) + '</td>' +
                    '<td style="padding:7px 10px;font-weight:800;color:#0f172a;">' + escapeHtml(r.part_name) + '</td>' +
                    '<td style="padding:7px 10px;color:#475569;">' + escapeHtml(r.model) + '</td>' +
                    '<td style="padding:7px 10px;color:#475569;">' + escapeHtml(r.customer) + '</td>' +
                    '<td style="padding:7px 10px;text-align:right;font-family:monospace;color:#64748b;">' + Number(r.total_sample_checked || 0).toLocaleString() + '</td>' +
                    '<td style="padding:7px 10px;text-align:right;font-family:monospace;font-weight:900;color:' + (r.total_ng_pcs > 0 ? '#dc2626' : '#64748b') + ';">' + Number(r.total_ng_pcs || 0).toLocaleString() + '</td>' +
                    '<td style="padding:7px 10px;text-align:right;font-family:monospace;font-weight:900;color:' + (r.ppm > 10000 ? '#b91c1c' : (r.ppm > 0 ? '#b45309' : '#059669')) + ';">' + rateValStr + '</td>' +
                    '<td style="padding:7px 10px;text-align:center;">' + badgeHtml + '</td>' +
                    '</tr>';
        });

        html += '</tbody></table>';
        bodyEl.innerHTML = html;
        updateFooterCount(rows.length, rows.length);
    }

    function renderTotalLotTable(rows) {
        var bodyEl = document.getElementById('kpiModalBody');
        if (!bodyEl) return;
        if (!rows || !rows.length) {
            bodyEl.innerHTML = renderEmptyState('Tidak ada data volume lot.');
            updateFooterCount(0, 0);
            return;
        }

        var html = '<table id="kpiDrilldownDataTable" style="width:100%;border-collapse:collapse;font-size:11px;text-align:left;">' +
                   '<thead><tr style="background:#1e293b;color:#ffffff;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;position:sticky;top:0;z-index:2;">' +
                   '<th style="padding:8px 10px;text-align:center;width:40px;">NO</th>' +
                   '<th style="padding:8px 10px;">KODE PART</th>' +
                   '<th style="padding:8px 10px;">NAMA PART</th>' +
                   '<th style="padding:8px 10px;">MODEL</th>' +
                   '<th style="padding:8px 10px;">CUSTOMER</th>' +
                   '<th style="padding:8px 10px;text-align:center;">TOTAL LOT</th>' +
                   '<th style="padding:8px 10px;text-align:center;">LOT LOLOS</th>' +
                   '<th style="padding:8px 10px;text-align:center;">LOT REJECT</th>' +
                   '<th style="padding:8px 10px;text-align:right;">% KELULUSAN</th>' +
                   '</tr></thead><tbody>';

        rows.forEach(function(r, idx) {
            var passRate = Number(r.pass_rate || 0);
            var passColor = passRate >= 95 ? '#059669' : (passRate >= 80 ? '#d97706' : '#dc2626');

            html += '<tr class="kpi-row-hover" style="border-bottom:1px solid #f1f5f9;">' +
                    '<td style="padding:7px 10px;text-align:center;font-weight:700;color:#94a3b8;">' + (idx + 1) + '</td>' +
                    '<td style="padding:7px 10px;font-family:monospace;font-weight:700;color:#1e293b;">' + escapeHtml(r.part_code) + '</td>' +
                    '<td style="padding:7px 10px;font-weight:800;color:#0f172a;">' + escapeHtml(r.part_name) + '</td>' +
                    '<td style="padding:7px 10px;color:#475569;">' + escapeHtml(r.model) + '</td>' +
                    '<td style="padding:7px 10px;color:#475569;">' + escapeHtml(r.customer) + '</td>' +
                    '<td style="padding:7px 10px;text-align:center;font-family:monospace;font-weight:900;color:#0284c7;background:#f0f9ff;">' + Number(r.total_lots || 0).toLocaleString() + '</td>' +
                    '<td style="padding:7px 10px;text-align:center;font-family:monospace;font-weight:700;color:#059669;">' + Number(r.passed_lots || 0).toLocaleString() + '</td>' +
                    '<td style="padding:7px 10px;text-align:center;font-family:monospace;font-weight:700;color:' + (r.rejected_lots > 0 ? '#dc2626' : '#94a3b8') + ';">' + Number(r.rejected_lots || 0).toLocaleString() + '</td>' +
                    '<td style="padding:7px 10px;text-align:right;font-family:monospace;font-weight:900;color:' + passColor + ';">' + passRate + '%</td>' +
                    '</tr>';
        });

        html += '</tbody></table>';
        bodyEl.innerHTML = html;
        updateFooterCount(rows.length, rows.length);
    }

    function renderDetailLotTable(rows, isPassed) {
        var bodyEl = document.getElementById('kpiModalBody');
        if (!bodyEl) return;
        if (!rows || !rows.length) {
            bodyEl.innerHTML = renderEmptyState(isPassed ? 'Tidak ada box/lot lolos.' : 'Tidak ada box/lot berstatus reject.');
            updateFooterCount(0, 0);
            return;
        }

        var html = '<table id="kpiDrilldownDataTable" style="width:100%;border-collapse:collapse;font-size:11px;text-align:left;">' +
                   '<thead><tr style="background:#1e293b;color:#ffffff;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;position:sticky;top:0;z-index:2;">' +
                   '<th style="padding:8px 10px;text-align:center;width:40px;">NO</th>' +
                   '<th style="padding:8px 10px;">WAKTU SESI</th>' +
                   '<th style="padding:8px 10px;">NO LOT</th>' +
                   '<th style="padding:8px 10px;">NO KANBAN</th>' +
                   '<th style="padding:8px 10px;">PART</th>' +
                   '<th style="padding:8px 10px;text-align:center;">QTY BOX</th>' +
                   '<th style="padding:8px 10px;text-align:center;">SAMPEL</th>' +
                   (isPassed ? '<th style="padding:8px 10px;text-align:center;">STATUS HASIL</th>' : '<th style="padding:8px 10px;">TEMUAN DEFECT</th><th style="padding:8px 10px;text-align:center;">STATUS LOT</th>') +
                   '</tr></thead><tbody>';

        rows.forEach(function(r, idx) {
            var lotStatusBadge = '';
            if (isPassed) {
                lotStatusBadge = '<span style="display:inline-block;padding:2px 7px;font-size:9.5px;font-weight:800;border-radius:5px;background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;">Passed OK</span>';
            } else {
                if (r.lot_status === 'replaced') {
                    lotStatusBadge = '<span style="display:inline-block;padding:2px 7px;font-size:9.5px;font-weight:800;border-radius:5px;background:#fef3c7;color:#b45309;border:1px solid #fde68a;">Replaced (Diganti)</span>';
                } else if (r.lot_status === 'ng_quarantine') {
                    lotStatusBadge = '<span style="display:inline-block;padding:2px 7px;font-size:9.5px;font-weight:800;border-radius:5px;background:#fee2e2;color:#b91c1c;border:1px solid #fecdd3;">Karantina NG</span>';
                } else {
                    lotStatusBadge = '<span style="display:inline-block;padding:2px 7px;font-size:9.5px;font-weight:800;border-radius:5px;background:#ffe4e6;color:#e11d48;border:1px solid #fecdd3;">Reject</span>';
                }
            }

            html += '<tr class="kpi-row-hover" style="border-bottom:1px solid #f1f5f9;">' +
                    '<td style="padding:7px 10px;text-align:center;font-weight:700;color:#94a3b8;">' + (idx + 1) + '</td>' +
                    '<td style="padding:7px 10px;white-space:nowrap;color:#475569;font-weight:600;">' + escapeHtml(r.started_at) + '</td>' +
                    '<td style="padding:7px 10px;font-family:monospace;font-weight:800;color:#1e293b;">' + escapeHtml(r.lot_number) + '</td>' +
                    '<td style="padding:7px 10px;font-family:monospace;color:#64748b;">' + escapeHtml(r.ref_number || '-') + '</td>' +
                    '<td style="padding:7px 10px;">' +
                        '<div style="font-weight:800;color:#0f172a;">' + escapeHtml(r.part_name) + '</div>' +
                        '<div style="font-size:9.5px;color:#64748b;font-family:monospace;">' + escapeHtml(r.part_code) + ' &middot; ' + escapeHtml(r.model) + '</div>' +
                    '</td>' +
                    '<td style="padding:7px 10px;text-align:center;font-family:monospace;font-weight:700;color:#334155;">' + Number(r.qty || 0).toLocaleString() + '</td>' +
                    '<td style="padding:7px 10px;text-align:center;font-family:monospace;color:#64748b;">' + Number(r.sample_size || 0).toLocaleString() + '</td>' +
                    (isPassed 
                        ? '<td style="padding:7px 10px;text-align:center;">' + lotStatusBadge + '</td>'
                        : '<td style="padding:7px 10px;font-weight:800;color:#dc2626;">' + escapeHtml(r.defect_breakdown || r.remarks || 'Temuan Cacat') + '</td><td style="padding:7px 10px;text-align:center;">' + lotStatusBadge + '</td>') +
                    '</tr>';
        });

        html += '</tbody></table>';
        bodyEl.innerHTML = html;
        updateFooterCount(rows.length, rows.length);
    }

    // ── Helper Utilities ──────────────────────────────────────────────────────
    function renderEmptyState(msg) {
        return '<div style="padding:48px 16px;text-align:center;color:#94a3b8;font-size:12px;background:#f8fafc;border-radius:10px;border:1px dashed #e2e8f0;">' +
               '<div style="font-weight:700;color:#64748b;">' + escapeHtml(msg) + '</div>' +
               '</div>';
    }

    function escapeHtml(str) {
        if (!str) return '-';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function updateFooterCount(visibleCount, totalCount) {
        var fc = document.getElementById('kpiModalFooterCount');
        if (fc) {
            if (visibleCount === totalCount) {
                fc.textContent = 'Menampilkan total ' + totalCount + ' baris data';
            } else {
                fc.textContent = 'Menampilkan ' + visibleCount + ' dari total ' + totalCount + ' baris data';
            }
        }
    }

    function filterKpiModalTable(query) {
        var table = document.getElementById('kpiDrilldownDataTable');
        if (!table) return;
        query = (query || '').toLowerCase().trim();
        var rows = table.querySelectorAll('tbody tr');
        var visible = 0;

        rows.forEach(function(r) {
            var text = r.textContent.toLowerCase();
            if (!query || text.indexOf(query) !== -1) {
                r.style.display = '';
                visible++;
            } else {
                r.style.display = 'none';
            }
        });

        updateFooterCount(visible, rows.length);
    }

    function exportKpiModalToCsv() {
        var table = document.getElementById('kpiDrilldownDataTable');
        if (!table) {
            alert('Tidak ada data tabel yang dapat diunduh.');
            return;
        }

        var csv = [];
        var rows = table.querySelectorAll('tr');

        rows.forEach(function(r) {
            if (r.style.display === 'none') return; // Lewati yang terfilter
            var cols = r.querySelectorAll('th, td');
            var rowData = [];
            cols.forEach(function(c) {
                var text = (c.innerText || c.textContent || '').replace(/"/g, '""').trim();
                rowData.push('"' + text + '"');
            });
            if (rowData.length) {
                csv.push(rowData.join(','));
            }
        });

        var csvString = csv.join('\r\n');
        var blob = new Blob(["\uFEFF" + csvString], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        var kpiTitle = kpiDrilldownCache ? (kpiDrilldownCache.title || 'KPI_Rincian') : 'KPI_Rincian';
        var filename = 'OQC_' + kpiTitle.replace(/[^a-zA-Z0-9]/g, '_') + '_' + new Date().toISOString().slice(0, 10) + '.csv';

        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }
    </script>

    <!-- ── Universal KPI Drilldown Modal Container ──────────────────────────── -->
    <div id="modalKpiDrilldown" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.65);backdrop-filter:blur(4px);z-index:9999;align-items:center;justify-content:center;padding:16px;">
        <div style="background:#ffffff;border-radius:16px;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);width:100%;max-width:1150px;max-height:90vh;display:flex;flex-direction:column;border:1px solid #e2e8f0;overflow:hidden;animation:kpiModalFadeIn 0.2s ease-out;">
            
            <!-- Modal Header -->
            <div style="padding:14px 20px;border-bottom:1px solid #f1f5f9;display:flex;align-items:flex-start;justify-content:space-between;gap:16px;background:#f8fafc;">
                <div>
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <span id="kpiModalAccentBar" style="width:4px;height:18px;border-radius:2px;background:#2563eb;"></span>
                        <h3 id="kpiModalTitle" style="font-size:15px;font-weight:900;color:#0f172a;margin:0;">Rincian Metrik KPI</h3>
                        <span id="kpiModalBadgePeriod" style="background:#eff6ff;color:#2563eb;font-size:10px;font-weight:700;padding:2px 8px;border-radius:6px;border:1px solid #bfdbfe;">Periode</span>
                    </div>
                    <div id="kpiModalSubtitle" style="font-size:11px;color:#64748b;margin-top:2px;">Keterangan detail metrik</div>
                </div>
                <button type="button" onclick="closeKpiDrilldownModal()" style="background:#f1f5f9;border:none;border-radius:8px;width:32px;height:32px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#64748b;transition:all 0.15s;" onmouseover="this.style.background='#e2e8f0';this.style.color='#0f172a'" onmouseout="this.style.background='#f1f5f9';this.style.color='#64748b'">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>

            <!-- KPI Quick Stat Pills (Dynamic) -->
            <div id="kpiModalStatsContainer" style="padding:8px 20px;background:#ffffff;border-bottom:1px solid #f1f5f9;display:flex;gap:8px;flex-wrap:wrap;">
                <!-- Rendered by JS -->
            </div>

            <!-- Controls: Sub-Tabs, Live Search Bar, & CSV Export -->
            <div style="padding:8px 20px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                <!-- Tabs container (visible for total_ng) -->
                <div id="kpiModalTabsContainer" style="display:none;align-items:center;gap:4px;background:#e2e8f0;padding:3px;border-radius:8px;">
                    <!-- Buttons injected by JS -->
                </div>
                
                <!-- Live Search Bar -->
                <div style="display:flex;align-items:center;gap:8px;flex:1;max-width:380px;min-width:200px;">
                    <div style="position:relative;width:100%;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2.5" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);pointer-events:none;"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                        <input type="text" id="kpiModalSearchInput" oninput="filterKpiModalTable(this.value)" placeholder="Ketik kata kunci untuk menyaring baris tabel..." style="width:100%;padding:5px 10px 5px 30px;font-size:11px;border-radius:7px;border:1px solid #cbd5e1;background:#ffffff;outline:none;" onfocus="this.style.borderColor='#2563eb'" onblur="this.style.borderColor='#cbd5e1'">
                    </div>
                </div>

                <!-- Export modal data to CSV -->
                <div>
                    <button type="button" onclick="exportKpiModalToCsv()" style="display:inline-flex;align-items:center;gap:5px;padding:5px 12px;background:#ffffff;color:#334155;border:1px solid #cbd5e1;border-radius:7px;font-size:11px;font-weight:700;cursor:pointer;transition:all 0.15s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='#ffffff'">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        Unduh CSV
                    </button>
                </div>
            </div>

            <!-- Modal Body / Table Scroll Area -->
            <div id="kpiModalBody" style="padding:14px 20px;overflow-y:auto;overflow-x:auto;flex:1;min-height:220px;max-height:calc(90vh - 230px);background:#ffffff;">
                <!-- Table or loading state rendered here -->
            </div>

            <!-- Modal Footer -->
            <div style="padding:10px 20px;background:#f8fafc;border-top:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;font-size:11px;color:#64748b;">
                <div id="kpiModalFooterCount" style="font-weight:600;">Menampilkan 0 data</div>
                <button type="button" onclick="closeKpiDrilldownModal()" style="padding:5px 16px;background:#e2e8f0;color:#334155;font-weight:700;font-size:11px;border-radius:7px;border:none;cursor:pointer;transition:all 0.15s;" onmouseover="this.style.background='#cbd5e1'" onmouseout="this.style.background='#e2e8f0'">
                    Tutup
                </button>
            </div>

        </div>
    </div>

    <style>
    @keyframes kpiModalFadeIn {
        from { opacity: 0; transform: scale(0.97); }
        to { opacity: 1; transform: scale(1); }
    }
    .kpi-row-hover:hover {
        background-color: #f8fafc !important;
    }
    </style>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
