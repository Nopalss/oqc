<?php
// ── 0. Dependensi Dasar & Session Cache Metadata (SEBELUM header.php menutup write lock) ──
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

$pdo = getDB();

// Cache metadata ringan (years & customers) per-jam agar tersimpan permanen di session
$_metaCacheKey = 'oqc_dash_meta_' . date('YmdH');
if (!isset($_SESSION[$_metaCacheKey]) && $pdo) {
    $_cache = [];
    try {
        $stmtYears = $pdo->query("SELECT DISTINCT YEAR(summary_date) AS y FROM oqc_daily_summary ORDER BY y ASC");
        $_cache['years'] = array_values(array_filter(array_map('intval', $stmtYears->fetchAll(PDO::FETCH_COLUMN))));
        if (empty($_cache['years'])) {
            $stmtYears = $pdo->query("SELECT DISTINCT YEAR(started_at) AS y FROM inspection_sessions WHERE started_at IS NOT NULL ORDER BY y ASC LIMIT 20");
            $_cache['years'] = array_values(array_filter(array_map('intval', $stmtYears->fetchAll(PDO::FETCH_COLUMN))));
        }
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

$breadcrumbCategory = "OPERASIONAL";
$pageTitle          = "Dashboard Laporan";
$pageSubtitle       = "Rekap hasil inspeksi outgoing quality control — PT. Surya Technology Industri";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

require_menu_access('dashboard');

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
$selectedTopRank    = isset($_GET['top_rank']) && in_array((int)$_GET['top_rank'], [3, 5, 10]) ? (int)$_GET['top_rank'] : 5;
$selectedDefectRank = isset($_GET['defect_rank']) && in_array((int)$_GET['defect_rank'], [1, 3]) ? (int)$_GET['defect_rank'] : 3;

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
    $startDate = getWorkCycleMonthStartDate($selectedYear, $selectedMonth);
    $endDate   = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $selectedYear, $selectedMonth)));
    $presetFilter = 'month_year';
    $filterTitleDesc = "Bulan " . ($monthNames[$selectedMonth] ?? $selectedMonth) . " " . $selectedYear . " (Siklus: " . date('d M', strtotime($startDate)) . " – " . date('d M Y', strtotime($endDate)) . ")";
    $trendSubtext = 'per tanggal (' . ($monthNames[$selectedMonth] ?? $selectedMonth) . ' ' . $selectedYear . ')';
} elseif ($filterType === 'custom' || (empty($_GET['preset']) && empty($filterType) && !empty($_GET['start_date']) && !empty($_GET['end_date']))) {
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
            $startDate = getWorkCycleWeekStartDate();
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
            $curY = (int)date('Y');
            $curM = (int)date('n');
            $startDate    = getWorkCycleMonthStartDate($curY, $curM);
            $endDate      = date('Y-m-d');
            $filterTitleDesc = "Bulan Ini (" . date('d M', strtotime($startDate)) . " – " . date('d M Y', strtotime($endDate)) . ")";
            $trendSubtext = 'per tanggal';
    }
}

// ── Fetch Master Customers List for Filter Dropdown (dari session cache) ────
$customerOptions = $_meta['customers'] ?? [];

// ── Load Global Target Settings (Target PPM) ────────────────────────────────
$dashboardTargetPpm = 0;
if ($pdo) {
    try {
        $stmtTgt = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key = 'dashboard_target_ppm' LIMIT 1");
        $dashboardTargetPpm = (float)($stmtTgt->fetchColumn() ?: 0);
    } catch (Exception $e) {}
}

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
$totalDistinctModelsNg = 0;
$totalDistinctPartsNg  = 0;

$STD_PALETTE    = ['#2563eb','#0ea5e9','#6366f1','#f59e0b','#10b981','#ef4444','#8b5cf6','#ec4899'];
$PASS_PALETTE   = ['#059669','#10b981','#34d399','#0284c7','#3b82f6','#6366f1','#8b5cf6','#06b6d4'];
$PALETTE        = ($selectedResult === 'passed') ? $PASS_PALETTE : $STD_PALETTE;

if ($pdo) {
    try {
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

        $isLongPeriod = ($presetFilter === 'all_years' || $daysDiff > 90);
        $rawDdsPartRows = [];
        $defectPartsMatrix = []; // [date_key => [defect_name => [ ['code' => ..., 'name' => ..., 'qty' => ...], ... ]]]

        // Ambil rincian Part Code & Part Name per jenis defect & tanggal untuk hover tooltip hanya jika rentang <= 90 hari
        // Ini menghemat alokasi memori PHP dan waktu eksekusi DB secara signifikan pada mode komparasi tahunan
        if (!$isLongPeriod) {
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

            foreach ($rawDdsPartRows as $pr) {
                $dk   = (string)$pr['date_key'];
                $dnm  = (string)$pr['defect_name'];
                $defectPartsMatrix[$dk][$dnm][] = [
                    'code' => $pr['part_code'],
                    'name' => $pr['part_name'],
                    'qty'  => (int)$pr['part_defect_qty']
                ];
            }
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

        $grandTotSample = 0;
        foreach ($activeDefectPeriodKeys as $dk) {
            $grandTotSample += (int)($trendMap[$dk]['sample'] ?? 0);
        }

        // ── Akumulasi Data untuk Grafik Batang Stacked (Bulanan saat filter Tahunan, Mingguan W1-W5 saat Bulanan) ──
        $isTahunanStacked = ($presetFilter === 'tahunan');
        $weeklyBucketsDef = [];

        if ($isTahunanStacked) {
            // Mode Tahunan: 6 Bulan ke Belakang dari bulan berjalan (Sunday Anchor)
            $mNamesShort = [
                1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
                7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
            ];
            $curMonthKey = sprintf('%04d-%02d', $selectedYear, $selectedMonth);
            $currentDateObj = new DateTime(sprintf('%04d-%02d-01', $selectedYear, $selectedMonth));
            $candidateMonths = [];

            for ($i = 5; $i >= 0; $i--) {
                $cursor = clone $currentDateObj;
                if ($i > 0) {
                    $cursor->modify("-{$i} months");
                }
                $cYear  = (int)$cursor->format('Y');
                $cMonth = (int)$cursor->format('n');
                $mKey   = $cursor->format('Y-m');

                $cycleStart = getWorkCycleMonthStartDate($cYear, $cMonth);
                $nextMonthDt = (clone $cursor)->modify('+1 month');
                $nextCycleStart = getWorkCycleMonthStartDate((int)$nextMonthDt->format('Y'), (int)$nextMonthDt->format('n'));
                $cycleEnd = date('Y-m-d', strtotime($nextCycleStart . ' -1 day'));

                $shortLabel = $mNamesShort[$cMonth] ?? $cursor->format('M');
                $candidateMonths[$mKey] = [
                    'year'        => $cYear,
                    'month'       => $cMonth,
                    'shortLabel'  => $shortLabel,
                    'label'       => $shortLabel . ' ' . $cYear,
                    'start_date'  => $cycleStart,
                    'end_date'    => $cycleEnd,
                    'is_current'  => ($mKey === $curMonthKey)
                ];
            }

            // Saring: Hanya tampilkan bulan yang memiliki aktivitas data, KECUALI bulan saat ini (wajib tampil)
            foreach ($candidateMonths as $mKey => $mInfo) {
                $hasMonthData = false;
                $mStart = $mInfo['start_date'];
                $mEnd   = $mInfo['end_date'];

                foreach ($trendMap as $dStr => $tData) {
                    // Cek berdasarkan rentang tanggal siklus kerja bulan tersebut (Sunday Anchor)
                    $isMatch = ($isTahunanStacked && strlen($dStr) === 7) 
                        ? ($dStr === $mKey) 
                        : ($dStr >= $mStart && $dStr <= $mEnd);
                    if ($isMatch) {
                        if (($tData['sample'] ?? 0) > 0 || ($tData['ng'] ?? 0) > 0) {
                            $hasMonthData = true;
                            break;
                        }
                    }
                }

                if (!$hasMonthData) {
                    foreach ($rawDdsRows as $dr) {
                        $dDate = (string)$dr['date_key'];
                        $isMatch = ($isTahunanStacked && strlen($dDate) === 7) 
                            ? ($dDate === $mKey) 
                            : ($dDate >= $mStart && $dDate <= $mEnd);
                        if ($isMatch) {
                            if ((int)$dr['total_defect'] > 0) {
                                $hasMonthData = true;
                                break;
                            }
                        }
                    }
                }

                if ($hasMonthData || $mInfo['is_current']) {
                    $weeklyBucketsDef[$mKey] = $mInfo;
                }
            }
        } else {
            // Mode Default: Mingguan (Sunday Anchor W1-W5)
            $weeklyBucketsDef = getWorkCycleWeeklyBuckets($selectedYear, $selectedMonth);
        }

        $stackedChartLabels = [];
        $weeklyDateMap = []; // [Key => ['start' => ..., 'end' => ..., 'label' => ...]]
        foreach ($weeklyBucketsDef as $wKey => $wInfo) {
            $stackedChartLabels[] = $wInfo['shortLabel'];
            $weeklyDateMap[$wKey] = $wInfo;
        }

        // Hitung total sample & total defect per jenis per bucket
        $weeklySampleTotals = [];
        $weeklyNgTotals     = [];
        $weeklyDefectQty    = []; // [Key => [defect_name => qty]]
        $weeklyDefectParts  = []; // [Key => [defect_name => [part_meta...]]]

        foreach ($weeklyBucketsDef as $wKey => $wInfo) {
            $wStart = $wInfo['start_date'];
            $wEnd   = $wInfo['end_date'];
            $wSample = 0;
            $wNg = 0;

            foreach ($trendMap as $dStr => $tData) {
                $isMatch = ($isTahunanStacked && strlen($dStr) === 7)
                    ? ($dStr === $wKey)
                    : ($dStr >= $wStart && $dStr <= $wEnd);

                if ($isMatch) {
                    $wSample += (int)($tData['sample'] ?? 0);
                    $wNg     += (int)($tData['ng'] ?? 0);
                }
            }
            $weeklySampleTotals[$wKey] = $wSample;
            $weeklyNgTotals[$wKey]     = $wNg;
            $weeklyDefectQty[$wKey]    = [];
            $weeklyDefectParts[$wKey]  = [];

            foreach ($rawDdsRows as $dr) {
                $dDate = (string)$dr['date_key'];
                $isMatch = ($isTahunanStacked && strlen($dDate) === 7)
                    ? ($dDate === $wKey)
                    : ($dDate >= $wStart && $dDate <= $wEnd);

                if ($isMatch) {
                    $dnm = (string)$dr['defect_name'];
                    $qty = (int)$dr['total_defect'];
                    $weeklyDefectQty[$wKey][$dnm] = ($weeklyDefectQty[$wKey][$dnm] ?? 0) + $qty;
                }
            }

            foreach ($rawDdsPartRows as $pr) {
                $dDate = (string)$pr['date_key'];
                $isMatch = ($isTahunanStacked && strlen($dDate) === 7)
                    ? ($dDate === $wKey)
                    : ($dDate >= $wStart && $dDate <= $wEnd);

                if ($isMatch) {
                    $dnm = (string)$pr['defect_name'];
                    $weeklyDefectParts[$wKey][$dnm][] = [
                        'code' => $pr['part_code'],
                        'name' => $pr['part_name'],
                        'qty'  => (int)$pr['part_defect_qty']
                    ];
                }
            }
        }

        // ── Tentukan Top N Defect PER-MINGGU (Dynamic Per-Week Ranking) ──
        $selectedDefectsPerWeek = []; // [wKey => [defect_name, ...]]
        $activeWeeklyDefectsMap = []; // [defect_name => true]

        foreach ($weeklyBucketsDef as $wKey => $wInfo) {
            $wDefects = $weeklyDefectQty[$wKey] ?? [];
            arsort($wDefects);
            // Ambil defect teratas yang mendominasi di minggu ini
            $topForWeek = array_slice(array_keys($wDefects), 0, $selectedDefectRank);
            $selectedDefectsPerWeek[$wKey] = $topForWeek;
            foreach ($topForWeek as $dnm) {
                if (($wDefects[$dnm] ?? 0) > 0) {
                    $activeWeeklyDefectsMap[$dnm] = true;
                }
            }
        }

        // Hitung total akumulasi defect per jenis di seluruh minggu aktif
        $weeklyDefectSummaryTotals = [];
        foreach ($weeklyBucketsDef as $wKey => $wInfo) {
            foreach (($weeklyDefectQty[$wKey] ?? []) as $dnm => $qty) {
                $weeklyDefectSummaryTotals[$dnm] = ($weeklyDefectSummaryTotals[$dnm] ?? 0) + (int)$qty;
            }
        }

        // Urutkan seluruh defect yang pernah juara di salah satu minggu (terbanyak di indeks awal agar berada di posisi paling dasar tumpukan bar)
        $topDefectTypesForChart = array_keys($activeWeeklyDefectsMap);
        usort($topDefectTypesForChart, function($a, $b) use ($weeklyDefectSummaryTotals, $defectTypeTotals) {
            $totA = $weeklyDefectSummaryTotals[$a] ?? ($defectTypeTotals[$a] ?? 0);
            $totB = $weeklyDefectSummaryTotals[$b] ?? ($defectTypeTotals[$b] ?? 0);
            if ($totA === $totB) {
                return strcmp($a, $b);
            }
            return $totB <=> $totA;
        });

        $stackedChartDatasetsPcs = [];
        $stackedChartDatasetsPpm = [];

        if ($selectedResult === 'passed') {
            $passedDataPcs   = [];
            $rejectedDataPcs = [];
            $passedDataPpm   = [];
            $rejectedDataPpm = [];

            foreach ($weeklyBucketsDef as $wKey => $wInfo) {
                $sample = (int)($weeklySampleTotals[$wKey] ?? 0);
                $ng     = (int)($weeklyNgTotals[$wKey] ?? 0);
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
                'borderRadius'    => 4
            ];
            $stackedChartDatasetsPcs[] = [
                'label'           => 'Rejected (' . $unitText . ')',
                'data'            => $rejectedDataPcs,
                'backgroundColor' => '#dc2626',
                'stack'           => 'res_stack',
                'borderRadius'    => 4
            ];

            $stackedChartDatasetsPpm[] = [
                'label'           => 'Passed (PPM)',
                'data'            => $passedDataPpm,
                'backgroundColor' => '#059669',
                'stack'           => 'res_stack',
                'borderRadius'    => 4
            ];
            $stackedChartDatasetsPpm[] = [
                'label'           => 'Rejected (PPM)',
                'data'            => $rejectedDataPpm,
                'backgroundColor' => '#dc2626',
                'stack'           => 'res_stack',
                'borderRadius'    => 4
            ];
        } else {
            // Assign warna tetap per jenis defect
            $defectColorMap = [];
            foreach ($topDefectTypesForChart as $cIdx => $dName) {
                $defectColorMap[$dName] = $STACKED_DEFECT_PALETTE[$cIdx % count($STACKED_DEFECT_PALETTE)];
            }

            // Layered Rank Stacking:
            // Lapisan 0 = Defect Terbesar #1 per minggu (paling bawah di sumbu 0)
            // Lapisan 1 = Defect Terbesar #2 per minggu (tengah)
            // Lapisan 2 = Defect Terbesar #3 per minggu (paling atas)
            $rankLayerLabels = [
                0 => 'Defect Terbesar #1',
                1 => 'Defect Terbesar #2',
                2 => 'Defect Terbesar #3',
                3 => 'Defect Terbesar #4',
                4 => 'Defect Terbesar #5'
            ];

            for ($rk = 0; $rk < $selectedDefectRank; $rk++) {
                $dataPtsPcs   = [];
                $dataPtsPpm   = [];
                $bgColors     = [];
                $partMetaPts  = [];
                $defectNames  = [];

                foreach ($weeklyBucketsDef as $wKey => $wInfo) {
                    $topForWeek = $selectedDefectsPerWeek[$wKey] ?? [];
                    $dname = $topForWeek[$rk] ?? null;

                    if ($dname !== null && ($weeklyDefectQty[$wKey][$dname] ?? 0) > 0) {
                        $qty    = (int)($weeklyDefectQty[$wKey][$dname] ?? 0);
                        $sample = (int)($weeklySampleTotals[$wKey] ?? 0);
                        $ppm    = ($sample > 0) ? round(($qty / $sample) * 1000000, 1) : 0;
                        $c      = $defectColorMap[$dname] ?? '#64748b';

                        $dataPtsPcs[]  = $qty;
                        $dataPtsPpm[]  = $ppm;
                        $bgColors[]    = $c;
                        $defectNames[] = $dname;
                        $partMetaPts[] = $weeklyDefectParts[$wKey][$dname] ?? [];
                    } else {
                        $dataPtsPcs[]  = 0;
                        $dataPtsPpm[]  = 0;
                        $bgColors[]    = 'transparent';
                        $defectNames[] = '';
                        $partMetaPts[] = [];
                    }
                }

                $layerName = $rankLayerLabels[$rk] ?? ('Peringkat Defect #' . ($rk + 1));
                $stackedChartDatasetsPcs[] = [
                    'label'           => $layerName,
                    'defect_names'    => $defectNames,
                    'data'            => $dataPtsPcs,
                    'raw_pcs'         => $dataPtsPcs,
                    'part_meta'       => $partMetaPts,
                    'backgroundColor' => $bgColors,
                    'stack'           => 'defect_stack',
                    'borderRadius'    => 4
                ];
                $stackedChartDatasetsPpm[] = [
                    'label'           => $layerName,
                    'defect_names'    => $defectNames,
                    'data'            => $dataPtsPpm,
                    'raw_pcs'         => $dataPtsPcs,
                    'part_meta'       => $partMetaPts,
                    'backgroundColor' => $bgColors,
                    'stack'           => 'defect_stack',
                    'borderRadius'    => 4
                ];
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
                                           SUM(s.passed_lots) AS total_ng,
                                           COUNT(s.id) AS total_cases
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
                                               SUM(s.rejected_lots) AS total_ng,
                                               SUM(s.rejected_lots) AS total_cases
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
                                               SUM(COALESCE(NULLIF(s.total_ng_pcs, 0), s.total_ng_samples, 0)) AS total_ng,
                                               COALESCE(SUM(s.rejected_lots), COUNT(DISTINCT s.id)) AS total_cases
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
        $modelCases = [];
        foreach ($stmtM->fetchAll() as $rM) {
            $modelLabels[] = htmlspecialchars($rM['model_name']);
            $modelCounts[] = (int)$rM['total_ng'];
            $modelCases[]  = max(1, (int)($rM['total_cases'] ?? 1));
        }

        // 6c. Total Distinct Parts & Models yang mengalami NG (tanpa pembatasan Top Rank)
        if ($selectedResult === 'passed') {
            $stmtCountP = $pdo->prepare("SELECT COUNT(DISTINCT s.part_id) FROM oqc_daily_summary s WHERE s.summary_date >= :sd AND s.summary_date < :ed_next {$sumCustCond} AND s.passed_lots > 0");
            $stmtCountM = $pdo->prepare("SELECT COUNT(DISTINCT COALESCE(m.name, mp.model, 'NO MODEL')) FROM oqc_daily_summary s LEFT JOIN master_parts mp ON s.part_id=mp.id LEFT JOIN master_models m ON s.model_id=m.id WHERE s.summary_date >= :sd AND s.summary_date < :ed_next {$sumCustCond} AND s.passed_lots > 0");
        } else {
            $colNgCheck = ($selectedUnit === 'lot') ? "s.rejected_lots > 0" : "(s.total_ng_pcs > 0 OR s.total_ng_samples > 0 OR s.rejected_lots > 0)";
            $stmtCountP = $pdo->prepare("SELECT COUNT(DISTINCT s.part_id) FROM oqc_daily_summary s WHERE s.summary_date >= :sd AND s.summary_date < :ed_next {$sumCustCond} AND {$colNgCheck}");
            $stmtCountM = $pdo->prepare("SELECT COUNT(DISTINCT COALESCE(m.name, mp.model, 'NO MODEL')) FROM oqc_daily_summary s LEFT JOIN master_parts mp ON s.part_id=mp.id LEFT JOIN master_models m ON s.model_id=m.id WHERE s.summary_date >= :sd AND s.summary_date < :ed_next {$sumCustCond} AND {$colNgCheck}");
        }
        $stmtCountP->execute($p);
        $totalDistinctPartsNg = (int)$stmtCountP->fetchColumn();

        $stmtCountM->execute($p);
        $totalDistinctModelsNg = (int)$stmtCountM->fetchColumn();

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

            if (!empty($top3DefectNames)) {
                $inPlaceholders = implode(',', array_fill(0, count($top3DefectNames), '?'));
                $sqlWP = "SELECT 
                            dt.name AS defect_name,
                            COALESCE(mp.part_name, 'UNKNOWN PART') AS part_name,
                            COALESCE(mp.part_code, '-') AS part_code,
                            COALESCE(m.name, mp.model, '-') AS model,
                            SUM(dds.lot_count) AS lot_case,
                            SUM(dds.qty_ng) AS ng_case
                        FROM oqc_daily_defect_summary dds
                        INNER JOIN defect_types dt ON dds.defect_type_id = dt.id
                        LEFT JOIN master_parts mp ON dds.part_id = mp.id
                        LEFT JOIN master_models m ON mp.model_id = m.id
                        WHERE dt.name IN ({$inPlaceholders})
                          AND dds.summary_date >= ? AND dds.summary_date < ? " . ($selectedCustomer !== '' ? " AND dds.customer = ? " : "") . "
                        GROUP BY dt.id, dt.name, mp.id, mp.part_name, mp.part_code, m.name, mp.model
                        ORDER BY ng_case DESC, lot_case DESC";

                $paramsWP = array_merge($top3DefectNames, [$startDate, $edNext]);
                if ($selectedCustomer !== '') {
                    $paramsWP[] = $selectedCustomer;
                }

                $stmtWP = $pdo->prepare($sqlWP);
                $stmtWP->execute($paramsWP);
                $allWpRows = $stmtWP->fetchAll(PDO::FETCH_ASSOC);

                foreach ($top3DefectNames as $dName) {
                    $worstPartsGrouped[$dName] = [];
                }
                foreach ($allWpRows as $wRow) {
                    $dName = $wRow['defect_name'];
                    if (isset($worstPartsGrouped[$dName]) && count($worstPartsGrouped[$dName]) < 3) {
                        $worstPartsGrouped[$dName][] = $wRow;
                    }
                }
            }

            $top3PartsNames = [];
            foreach ($worstPartsGrouped as $dName => $parts) {
                if (!empty($parts[0]['part_name'])) {
                    $top3PartsNames[] = htmlspecialchars($parts[0]['part_name']) . " (" . strtolower($dName) . ")";
                }
            }
            $top3PartsSummaryStr = !empty($top3PartsNames) ? implode(', ', $top3PartsNames) : 'Belum Ada Part Reject';
        }

        // ── 8. Multi-Horizon Quality PPM Trend (Annual -> Monthly -> Weekly/Daily Sunday Anchor) ──
        $initialSubResolution = 'weekly';
        $multiHorizonData = buildDashboardMultiHorizonTrend($pdo, [
            'ref_year'       => $selectedYear,
            'ref_month'      => $selectedMonth,
            'customer'       => $selectedCustomer,
            'unit'           => $selectedUnit,
            'sample_basis'   => $selectedSampleBasis,
            'result'         => $selectedResult,
            'sub_resolution' => $initialSubResolution
        ]);

    } catch (PDOException $e) {}
}

$jsMultiHorizon = json_encode($multiHorizonData ?? ['labels' => [], 'rates' => [], 'sections' => []]);

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
$jsModelCases   = json_encode($modelCases ?? []);
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

// Compute initial dashboard version fingerprint for realtime sync & caching
$initialDashboardVersion = '';
$rSumV = ['max_sum_id' => 0, 'last_sum_update' => '', 'sum_count' => 0];
$rSessV = ['max_sess_id' => 0, 'sess_count' => 0];

if ($pdo) {
    try {
        $stmtSumV = $pdo->prepare("SELECT 
            COALESCE(MAX(id), 0) AS max_sum_id,
            COALESCE(MAX(updated_at), '1970-01-01') AS last_sum_update,
            COUNT(id) AS sum_count
        FROM oqc_daily_summary
        WHERE summary_date >= :sd AND summary_date < :ed_next {$sumCustCond}");
        $stmtSumV->execute($p);
        $rSumV = $stmtSumV->fetch(PDO::FETCH_ASSOC) ?: $rSumV;

        $stmtSessV = $pdo->prepare("SELECT 
            COALESCE(MAX(id), 0) AS max_sess_id,
            COUNT(id) AS sess_count
        FROM inspection_sessions
        WHERE started_at >= :sd AND started_at < :ed_next");
        $stmtSessV->execute([':sd' => $startDate, ':ed_next' => $edNext]);
        $rSessV = $stmtSessV->fetch(PDO::FETCH_ASSOC) ?: $rSessV;
    } catch (Exception $eV) {}
}

$initialDashboardVersion = md5(implode('|', [
    $rSumV['max_sum_id'] ?? 0,
    $rSumV['last_sum_update'] ?? '',
    $rSumV['sum_count'] ?? 0,
    $rSessV['max_sess_id'] ?? 0,
    $rSessV['sess_count'] ?? 0,
    $startDate,
    $endDate,
    $selectedCustomer,
    $dashboardTargetPpm,
    $selectedDefectRank
]));
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
                        <div style="font-size:9.5px;color:#64748b;">Periode : <?= htmlspecialchars($filterTitleDesc) ?> | Target Customer : <?= htmlspecialchars($selectedCustomer !== '' ? $selectedCustomer : 'Semua Customer (All)') ?></div>
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
                                       . ($selectedResult !== 'rejected' ? '&result=' . urlencode($selectedResult) : '')
                                       . ($selectedDefectRank !== 3 ? '&defect_rank=' . $selectedDefectRank : '');
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

                <!-- Action Buttons: Live Sync Widget, Print Report & Export Excel -->
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <!-- Live / Pause Toggle Button -->
                    <button type="button" id="btnToggleLiveSync" onclick="toggleLiveSyncState()" 
                            style="border:1px solid #cbd5e1;background:#ffffff;cursor:pointer;padding:5px 10px;border-radius:8px;color:#0f172a;display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:700;box-shadow:0 1px 2px rgba(0,0,0,0.04);transition:all 0.15s;" 
                            title="Nyalakan / Jeda Pembaruan Otomatis Realtime">
                        <span id="dotLiveSyncIndicator" style="width:7px;height:7px;border-radius:50%;background:#10b981;display:inline-block;box-shadow:0 0 6px rgba(16,185,129,0.7);"></span>
                        <span id="txtLiveSyncStatus">Live</span>
                    </button>

                    <!-- Sync Button (Direct Refresh from Server) -->
                    <button type="button" id="btnLiveSyncManual" onclick="manualSyncNow()" 
                            style="border:1px solid #cbd5e1;background:#ffffff;cursor:pointer;padding:5px 12px;border-radius:8px;color:#1e293b;display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:700;box-shadow:0 1px 2px rgba(0,0,0,0.04);transition:all 0.15s;" 
                            onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='#ffffff'" 
                            title="Ambil &amp; Sinkronkan Data Terbaru Langsung dari Server">
                        <svg id="iconLiveSyncSpin" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
                        <span>Sync</span>
                    </button>

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
                ['key'=>'sample_inspected','dom_id'=>'kpi-val-sample_inspected','label'=>$isLotUnit ? 'Total Lot Inspected' : ('Total Sample Inspected' . $sampleBasisLabel),'val'=>number_format($kpi['total_inspected'] ?? 0),'unit'=>$isLotUnit ? 'lot' : 'pcs','sub'=>$isLotUnit ? 'Total box/lot discan' : $sampleBasisSub,'icon'=>'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z','iconColor'=>'#2563eb','bg'=>'#eff6ff'],
                ['key'=>'total_ng','dom_id'=>'kpi-val-total_ng','label'=>$isLotUnit ? 'Total Lot Reject' : 'Total NG Pcs','val'=>number_format($kpi['total_ng'] ?? 0),'unit'=>$isLotUnit ? 'lot' : 'pcs','sub'=>$isLotUnit ? 'Box ditemukan defect' : 'Fisik sampel cacat','icon'=>'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z','iconColor'=>'#dc2626','bg'=>'#fff1f2','valColor'=>'#dc2626'],
                ['key'=>'defect_rate','dom_id'=>'kpi-val-defect_rate','label'=>$isLotUnit ? 'Lot Reject Rate (%)' : 'Defect Rate (<span id="kpiMetricScaleLabel">PPM</span>)','val'=>$isLotUnit ? (number_format($kpi['pct_rate'] ?? 0, 2) . ' <span style="font-size:12px;font-weight:700;">%</span>') : ('<span id="kpiMetricRateVal">'.number_format($kpi['ppm_rate'] ?? 0, 1).'</span>'),'unit'=>'','sub'=>$isLotUnit ? 'Persentase penolakan lot' : 'Rasio defect sampling','icon'=>'M13 7h8m0 0v8m0-8l-8 8-4-4-6 6','iconColor'=>$ngRateColor,'bg'=>$ngRateBg,'valColor'=>$ngRateColor],
                ['key'=>'total_lot','dom_id'=>'kpi-val-total_lot','label'=>'Total LOT','val'=>number_format($kpi['total_lot'] ?? 0),'unit'=>'lot','sub'=>'Label / box discan','icon'=>'M4 6h16M4 10h16M4 14h16M4 18h16','iconColor'=>'#0284c7','bg'=>'#f0f9ff'],
                ['key'=>$isPassedMode ? 'passed_lot' : 'ng_lot','dom_id'=>'kpi-val-pass_reject_lot','label'=>$isPassedMode ? 'Total Passed Lot' : 'Total NG Lot','val'=>number_format($isPassedMode ? ($kpi['pass_count'] ?? 0) : ($kpi['rejected_count'] ?? 0)),'unit'=>'lot','sub'=>$isPassedMode ? 'Lot berstatus lulus' : 'Label lot NG','icon'=>$isPassedMode ? 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z' : 'M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636','iconColor'=>$isPassedMode ? '#059669' : '#e11d48','bg'=>$isPassedMode ? '#f0fdf4' : '#ffe4e6','valColor'=>$isPassedMode ? '#059669' : '#e11d48'],
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
                            <svg class="kpi-drilldown-arrow" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2.5" style="flex-shrink:0;"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                        </div>
                        <div style="font-size:20px;font-weight:900;color:<?= $kitem['valColor'] ?? '#0f172a' ?>;line-height:1.15;margin-top:2px;">
                            <span id="<?= $kitem['dom_id'] ?>"><?= $kitem['val'] ?></span><?php if($kitem['unit']): ?> <span style="font-size:11px;font-weight:500;color:#94a3b8;"><?= $kitem['unit'] ?></span><?php endif; ?>
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

        <!-- ── Chart Row 1: Multi-Horizon PPM Trend (67% Width) + Defect Matrix (33% Width) ─── -->
        <div style="display:grid;grid-template-columns:minmax(0, 2fr) minmax(0, 1fr);gap:12px;" id="dash-row-1">

            <!-- Tren Chart Multi-Horizon (Tahunan -> Bulanan -> Mingguan/Harian Sunday Anchor) -->
            <div class="card" style="padding:14px 16px;display:flex;flex-direction:column;min-width:0;">
                <div style="flex-shrink:0;margin-bottom:10px;display:flex;align-items:flex-start;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                    <div>
                        <div style="font-size:13px;font-weight:800;color:#1e293b;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                            <svg width="15" height="15" fill="none" stroke="#2563eb" stroke-width="2.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                            </svg>
                            <span><?= $isPassedMode ? 'Tren & Komparasi Kualitas Mutu Yield' : 'Tren & Komparasi Defect Rate' ?> (<span id="trendChartUnitText">PPM</span>)</span>
                            <span id="trendChartBadgeText" class="badge" style="background:#eff6ff;color:#2563eb;font-size:9.5px;font-weight:700;padding:2px 7px;border-radius:6px;border:1px solid #bfdbfe;">Parts Per Million</span>
                        </div>
                        <div id="trendChartSubDesc" style="font-size:10px;color:#64748b;margin-top:2px;">
                            <?= htmlspecialchars($multiHorizonData['sub_desc'] ?? 'Komparasi makro-ke-mikro: Tahunan → Bulanan → Mingguan.') ?><?= $selectedCustomer !== '' ? ' &middot; Customer: ' . htmlspecialchars($selectedCustomer) : '' ?>
                        </div>
                    </div>
                    
                    <div class="no-print" style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                        <!-- Global Target PPM Control -->
                        <div style="position:relative;">
                            <button type="button" id="btnSetPpmTarget" onclick="toggleTargetPpmModal(event)" 
                                    style="padding:3px 9px;font-size:10.5px;font-weight:700;border-radius:6px;border:1px solid #fecaca;cursor:pointer;display:inline-flex;align-items:center;gap:4px;transition:all 0.15s;background:#fef2f2;color:#dc2626;"
                                    title="Atur Target PPM Kualitas (Garis Merah)">
                                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                                <span>Target: <strong id="lblDisplayTargetPpm"><?= $dashboardTargetPpm > 0 ? number_format($dashboardTargetPpm) : 'Belum Diatur' ?></strong> PPM</span>
                            </button>

                            <!-- Target PPM Popover Modal -->
                            <div id="popoverTargetPpm" style="display:none;position:absolute;left:0;top:calc(100% + 6px);width:260px;background:#ffffff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1);z-index:90;padding:12px;">
                                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                                    <div style="font-size:11.5px;font-weight:800;color:#1e293b;display:flex;align-items:center;gap:5px;">
                                        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#ef4444;"></span>
                                        Target Batas PPM
                                    </div>
                                    <button type="button" onclick="closeTargetPpmModal(event)" style="border:none;background:transparent;color:#94a3b8;cursor:pointer;padding:2px;font-size:14px;line-height:1;">&times;</button>
                                </div>
                                <div style="font-size:10px;color:#64748b;margin-bottom:8px;line-height:1.4;">
                                    Masukkan nilai target maksimal defect rate (PPM). Nilai ini akan tampil sebagai garis batas merah di chart.
                                </div>
                                <div style="display:flex;align-items:center;gap:6px;margin-bottom:8px;">
                                    <input type="number" id="inputTargetPpm" min="0" step="any" 
                                           value="<?= $dashboardTargetPpm > 0 ? (float)$dashboardTargetPpm : '' ?>" 
                                           placeholder="Contoh: 100" 
                                           style="flex:1;padding:5px 8px;font-size:11px;border:1px solid #cbd5e1;border-radius:6px;font-weight:700;color:#0f172a;outline:none;" 
                                           onkeydown="if(event.key==='Enter') saveTargetPpm(event);">
                                    <span style="font-size:10.5px;font-weight:700;color:#64748b;">PPM</span>
                                </div>
                                <div style="display:flex;align-items:center;justify-content:flex-end;gap:6px;">
                                    <button type="button" onclick="saveTargetPpm(event, 0)" style="padding:4px 8px;font-size:10px;font-weight:600;border:1px solid #e2e8f0;border-radius:5px;background:#f8fafc;color:#64748b;cursor:pointer;">Reset</button>
                                    <button type="button" id="btnSaveTargetPpm" onclick="saveTargetPpm(event)" style="padding:4px 10px;font-size:10px;font-weight:700;border:none;border-radius:5px;background:#ef4444;color:#ffffff;cursor:pointer;box-shadow:0 1px 2px rgba(239,68,68,0.2);">Simpan</button>
                                </div>
                            </div>
                        </div>

                        <!-- Selector Resolusi Mikro: Mingguan (W1-W4) vs Harian Siklus Kerja -->
                        <div style="display:inline-flex;background:#f1f5f9;padding:2px;border-radius:8px;border:1px solid #e2e8f0;gap:2px;" title="Pilih Resolusi Periode Mikro Bulan Terpilih">
                            <button type="button" id="btnTrendResWeekly" onclick="switchTrendResolution('weekly')" 
                                    style="padding:3px 9px;font-size:10.5px;font-weight:800;border-radius:6px;border:none;cursor:pointer;transition:all 0.15s;background:#0f172a;color:#ffffff;box-shadow:0 1px 2px rgba(15,23,42,0.2);">
                                Resolusi Mingguan
                            </button>
                            <button type="button" id="btnTrendResDaily" onclick="switchTrendResolution('daily')" 
                                    style="padding:3px 9px;font-size:10.5px;font-weight:800;border-radius:6px;border:none;cursor:pointer;transition:all 0.15s;background:transparent;color:#64748b;">
                                Resolusi Harian
                            </button>
                        </div>

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
                        <div style="display:inline-flex;background:#f1f5f9;padding:2px;border-radius:8px;border:1px solid #e2e8f0;gap:2px;" title="Pilih Gaya Visualisasi Grafik">
                            <button type="button" id="btnChartTypeBar" onclick="switchTrendChartType('bar')" style="padding:3px 9px;font-size:10.5px;font-weight:700;border-radius:6px;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:4px;transition:all 0.15s;background:#ffffff;color:#2563eb;box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                                Batang
                            </button>
                            <button type="button" id="btnChartTypeLine" onclick="switchTrendChartType('line')" style="padding:3px 9px;font-size:10.5px;font-weight:700;border-radius:6px;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:4px;transition:all 0.15s;background:transparent;color:#64748b;">
                                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                                Garis
                            </button>
                        </div>
                    </div>
                </div>
                <div style="min-height:240px;height:240px;position:relative;width:100%;overflow:hidden;" id="trendChartScrollContainer">
                    <canvas id="chartTrendNG" style="width:100%;height:100%;"></canvas>
                </div>
            </div>

            <!-- Top N Defect Card (Stacked Bar Chart & Transpose Matrix Table) -->
            <div class="card" style="padding:14px 16px;display:flex;flex-direction:column;position:relative;min-width:0;">
                <div style="flex-shrink:0;margin-bottom:8px;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
                    <div>
                        <div style="font-size:13px;font-weight:800;color:#1e293b;display:flex;align-items:center;gap:6px;">
                            <?php
                            $cardDefectTitle = "Tren Defect Harian";
                            $cardSubPeriodText = "hari";
                            if ($isTahunanStacked) {
                                $cardDefectTitle = "Tren Defect Bulanan";
                                $cardSubPeriodText = "bulan";
                            } elseif ($presetFilter === 'bulanan' || $presetFilter === 'month_year') {
                                $cardDefectTitle = "Tren Defect Mingguan";
                                $cardSubPeriodText = "minggu";
                            }
                            if ($isPassedMode) {
                                $cardDefectTitle = str_replace("Defect", "Hasil Mutu", $cardDefectTitle);
                            }
                            ?>
                            <span id="defectCardMainTitle"><?= htmlspecialchars($cardDefectTitle) ?> (<span id="defectMetricScaleBadge">PCS</span>)</span>
                        </div>
                        <div style="font-size:10px;color:#94a3b8;margin-top:2px;" id="defectCardSubTitle">
                            <?= date('d M', strtotime($startDate)) ?> &ndash; <?= date('d M Y', strtotime($endDate)) ?> &middot; <span id="defectRankDisplayText"><?= $selectedDefectRank ?></span> besar/<?= $cardSubPeriodText ?> (Tabel <?= $isTahunanStacked ? 'Bulanan' : 'Harian' ?>)
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

                        <!-- Rank Filter Pills (1, 3) -->
                        <div style="display:flex;align-items:center;gap:2px;background:#f1f5f9;padding:2px;border-radius:8px;border:1px solid #e2e8f0;" title="Pilih Jumlah Peringkat Teratas">
                            <?php foreach ([1, 3] as $rNum): 
                                $isRActive = ($selectedDefectRank === $rNum);
                                $currParams = $_GET;
                                $currParams['preset'] = $presetFilter;
                                $currParams['defect_rank'] = $rNum;
                                $defectRankUrl = '?' . http_build_query($currParams);
                            ?>
                                <a href="<?= htmlspecialchars($defectRankUrl) ?>"
                                   id="btnDefectRank_<?= $rNum ?>"
                                   onclick="if (typeof switchDefectRank === 'function') { switchDefectRank(<?= $rNum ?>, event); }"
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
                                    style="padding:3px 8px;font-size:10px;font-weight:700;border-radius:5px;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:4px;transition:all 0.15s;background:transparent;color:#64748b;">
                                <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                                </svg>
                                Grafik
                            </button>
                            <button type="button" id="btnDefectViewTable" onclick="switchDefectTimelineView('table')"
                                    style="padding:3px 8px;font-size:10px;font-weight:800;border-radius:5px;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:4px;transition:all 0.15s;background:#ffffff;color:#2563eb;box-shadow:0 1px 2px rgba(0,0,0,0.05);">
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
                    <div id="defectTimelineChartView" style="height:100%;width:100%;max-width:100%;display:none;flex-direction:column;overflow:hidden;">
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
                            $numPoints = count($stackedChartLabels);
                            ?>
                            <div style="flex:1;min-height:0;width:100%;max-width:100%;overflow:hidden;position:relative;display:flex;flex-direction:column;">
                                <div style="flex:1;min-height:0;width:100%;max-width:100%;overflow:hidden;position:relative;">
                                    <canvas id="chartDefectStacked" style="width:100% !important;max-width:100% !important;height:100% !important;"></canvas>
                                </div>
                                <!-- Legend Chips Daftar Defect yang Muncul -->
                                <div id="defectStackedLegendChips" style="flex-shrink:0;padding-top:4px;display:flex;align-items:center;justify-content:center;gap:6px;flex-wrap:wrap;max-width:100%;min-height:24px;overflow:hidden;"></div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- 2. VIEW TABEL (Transposed Matrix Table: Rows = Jenis NG, Cols = Tanggal) -->
                    <div id="defectTimelineTableView" style="height:100%;width:100%;display:block;overflow:auto;border:1px solid #e2e8f0;border-radius:8px;">
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
                                    <tr style="background:#1e293b;color:#ffffff;font-size:9.5px;font-weight:800;letter-spacing:0.04em;text-transform:uppercase;position:sticky;top:0;z-index:4;">
                                        <th style="padding:6px 10px;width:130px;min-width:130px;max-width:130px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;border-bottom:1px solid #0f172a;position:sticky;left:0;background:#1e293b;z-index:5;" title="<?= $isPassedMode ? 'Status Hasil Mutu' : 'Jenis Cacat / Defect' ?>">
                                            <?= $isPassedMode ? 'STATUS HASIL' : 'JENIS NG' ?>
                                        </th>
                                        <?php 
                                        $colTotalTitle = $isTahunanStacked 
                                            ? ('JAN – ' . strtoupper($mNamesShort[$selectedMonth] ?? date('M')) . ' ' . $selectedYear)
                                            : 'TOTAL';
                                        ?>
                                        <th style="padding:6px 10px;width:95px;min-width:95px;text-align:right;white-space:nowrap;border-bottom:1px solid #0f172a;position:sticky;left:130px;background:#0f172a;color:#fbbf24;z-index:5;box-shadow:2px 0 4px -1px rgba(0,0,0,0.25);" title="<?= $isTahunanStacked ? 'Akumulasi total dari awal tahun s/d bulan ini (Sunday Anchor)' : 'Total periode terpilih' ?>">
                                            <?= htmlspecialchars($colTotalTitle) ?>
                                        </th>
                                        <?php foreach ($activeDefectPeriodKeys as $dk): ?>
                                            <th style="padding:6px 8px;text-align:right;white-space:nowrap;border-bottom:1px solid #0f172a;">
                                                <?= htmlspecialchars($stackedPeriodLabels[$dk] ?? $dk) ?>
                                            </th>
                                        <?php endforeach; ?>
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
                                            $rowVals = [];
                                            foreach ($activeDefectPeriodKeys as $dk) {
                                                $s = (int)($trendMap[$dk]['sample'] ?? 0);
                                                $n = (int)($trendMap[$dk]['ng'] ?? 0);
                                                $val = $isPassRow ? max(0, $s - $n) : $n;
                                                $rowTotalPcs += $val;
                                                $ppmVal = ($s > 0) ? round(($val / $s) * 1000000, 1) : 0;
                                                $rowVals[$dk] = ['val' => $val, 'ppm' => $ppmVal];
                                            }
                                            $rowTotalPpm = ($grandTotSample > 0) ? round(($rowTotalPcs / $grandTotSample) * 1000000, 1) : 0;
                                    ?>
                                        <tr style="border-bottom:1px solid #f1f5f9;background:#ffffff;" class="hover:bg-blue-50">
                                            <td style="padding:5px 10px;width:130px;min-width:130px;max-width:130px;font-weight:700;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;position:sticky;left:0;background:#ffffff;z-index:3;" title="<?= htmlspecialchars($rpm['label']) ?>">
                                                <span style="display:inline-block;width:7px;height:7px;border-radius:2px;background:<?= $rpm['color'] ?>;margin-right:4px;vertical-align:middle;"></span>
                                                <?= $rpm['label'] ?>
                                            </td>
                                            <td style="padding:5px 10px;width:95px;min-width:95px;text-align:right;font-family:monospace;font-weight:900;color:<?= $rpm['color'] ?>;position:sticky;left:130px;background:<?= $isPassRow ? '#f0fdf4' : '#fff1f2' ?>;z-index:3;box-shadow:2px 0 4px -1px rgba(0,0,0,0.08);">
                                                <span class="defect-metric-total-val" data-pcs="<?= $rowTotalPcs ?>" data-ppm="<?= $rowTotalPpm ?>"><?= number_format($rowTotalPcs) ?></span>
                                            </td>
                                            <?php foreach ($activeDefectPeriodKeys as $dk): 
                                                $val = $rowVals[$dk]['val'] ?? 0;
                                                $ppmVal = $rowVals[$dk]['ppm'] ?? 0;
                                            ?>
                                                <td style="padding:5px 8px;text-align:right;font-family:monospace;font-weight:700;color:<?= $val > 0 ? $rpm['color'] : '#cbd5e1' ?>;">
                                                    <span class="defect-metric-val" data-pcs="<?= $val ?>" data-ppm="<?= $ppmVal ?>"><?= $val > 0 ? number_format($val) : '-' ?></span>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php else: ?>
                                        <?php foreach ($topNDefectTypes as $didx => $dname): 
                                            $thColor = $STACKED_DEFECT_PALETTE[$didx % count($STACKED_DEFECT_PALETTE)];
                                            $rowTotalPcs = (int)($defectTypeTotals[$dname] ?? 0);
                                            $rowTotalPpm = ($grandTotSample > 0) ? round(($rowTotalPcs / $grandTotSample) * 1000000, 1) : 0;
                                        ?>
                                            <tr style="border-bottom:1px solid #f1f5f9;background:#ffffff;cursor:pointer;" class="hover:bg-red-50" onclick="handleDonutLegendClick('defect', '<?= htmlspecialchars($dname, ENT_QUOTES) ?>')" title="Klik untuk memfilter Model & Part yang terdampak <?= htmlspecialchars($dname) ?>">
                                                <td style="padding:5px 10px;width:130px;min-width:130px;max-width:130px;font-weight:700;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;position:sticky;left:0;background:#ffffff;z-index:3;" title="<?= htmlspecialchars($dname) ?>">
                                                    <span style="display:inline-block;width:7px;height:7px;border-radius:2px;background:<?= $thColor ?>;margin-right:4px;vertical-align:middle;"></span>
                                                    <?= htmlspecialchars($dname) ?>
                                                </td>
                                                <td style="padding:5px 10px;width:95px;min-width:95px;text-align:right;font-family:monospace;font-weight:900;color:#dc2626;position:sticky;left:130px;background:#fff1f2;z-index:3;box-shadow:2px 0 4px -1px rgba(0,0,0,0.08);">
                                                    <span class="defect-metric-total-val" data-pcs="<?= $rowTotalPcs ?>" data-ppm="<?= $rowTotalPpm ?>"><?= number_format($rowTotalPcs) ?></span>
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
                                                <td style="padding:5px 10px;width:130px;min-width:130px;max-width:130px;font-weight:700;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;position:sticky;left:0;background:#f8fafc;z-index:3;" title="Others (<?= count($otherNDefectTypes) ?> jenis lainnya)">
                                                    <span style="display:inline-block;width:7px;height:7px;border-radius:2px;background:#94a3b8;margin-right:4px;vertical-align:middle;"></span>
                                                    <?= 'Others (' . count($otherNDefectTypes) . ' jenis lainnya)' ?>
                                                </td>
                                                <td style="padding:5px 10px;width:95px;min-width:95px;text-align:right;font-family:monospace;font-weight:900;color:#64748b;position:sticky;left:130px;background:#f1f5f9;z-index:3;box-shadow:2px 0 4px -1px rgba(0,0,0,0.08);">
                                                    <span class="defect-metric-total-val" data-pcs="<?= $rowTotalOtherPcs ?>" data-ppm="<?= $rowTotalOtherPpm ?>"><?= number_format($rowTotalOtherPcs) ?></span>
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
                                            </tr>
                                        <?php endif; endif; ?>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot>
                                    <tr style="background:#f8fafc;font-weight:800;border-top:2px solid #cbd5e1;position:sticky;bottom:0;z-index:4;">
                                        <td style="padding:6px 10px;width:130px;min-width:130px;max-width:130px;color:#1e293b;font-size:10px;text-transform:uppercase;position:sticky;left:0;bottom:0;background:#f8fafc;z-index:5;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                            <?= $isTahunanStacked ? 'TOTAL (YTD)' : 'TOTAL' ?>
                                        </td>
                                        <?php 
                                        $grandSumPcs = 0;
                                        $colSums = [];
                                        foreach ($activeDefectPeriodKeys as $dk) {
                                            if ($isPassedMode) {
                                                $colPcs = (int)($trendMap[$dk]['sample'] ?? 0);
                                            } else {
                                                $colPcs = (int)($defectDateTotals[$dk] ?? 0);
                                            }
                                            $grandSumPcs += $colPcs;
                                            $colSample = (int)($trendMap[$dk]['sample'] ?? 0);
                                            $colPpm = ($colSample > 0) ? round(($colPcs / $colSample) * 1000000, 1) : 0;
                                            $colSums[$dk] = ['pcs' => $colPcs, 'ppm' => $colPpm];
                                        }
                                        $grandSumPpm = ($grandTotSample > 0) ? round(($grandSumPcs / $grandTotSample) * 1000000, 1) : 0;
                                        ?>
                                        <td style="padding:6px 10px;width:95px;min-width:95px;text-align:right;font-family:monospace;color:#dc2626;position:sticky;left:130px;bottom:0;background:#fee2e2;z-index:5;box-shadow:2px 0 4px -1px rgba(0,0,0,0.1);">
                                            <span class="defect-metric-total-val" data-pcs="<?= $grandSumPcs ?>" data-ppm="<?= $grandSumPpm ?>"><?= number_format($grandSumPcs) ?></span>
                                        </td>
                                        <?php foreach ($activeDefectPeriodKeys as $dk): 
                                            $colPcs = $colSums[$dk]['pcs'] ?? 0;
                                            $colPpm = $colSums[$dk]['ppm'] ?? 0;
                                        ?>
                                            <td style="padding:6px 8px;text-align:right;font-family:monospace;color:#0f172a;">
                                                <span class="defect-metric-total-val" data-pcs="<?= $colPcs ?>" data-ppm="<?= $colPpm ?>"><?= number_format($colPcs) ?></span>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                </tfoot>
                            </table>
                        <?php endif; ?>
                    </div>

                </div>
            </div>

        </div>

        <!-- ── Chart Row 2: Defect Donut + Model Pie + Part Pie (3 Distinct Visual Identities) ─────── -->
        <div style="display:grid;grid-template-columns:repeat(3, minmax(0, 1fr));gap:10px;" id="dash-row-2">

            <!-- Card 1: Breakdown Jenis Defect / Rasio Yield Status -->
            <div class="card" style="padding:14px 16px;display:flex;flex-direction:column;min-width:0;border-top:3px solid <?= $isPassedMode ? '#059669' : '#ef4444' ?>;">
                <div style="flex-shrink:0;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <div style="width:28px;height:28px;border-radius:8px;background:<?= $isPassedMode ? '#d1fae5' : '#fee2e2' ?>;color:<?= $isPassedMode ? '#059669' : '#dc2626' ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <?php if ($isPassedMode): ?>
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.45 1-1 1H7"/><path d="M14 14.66V17c0 .55.45 1 1 1h2"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
                            <?php else: ?>
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div style="font-size:13px;font-weight:800;color:#1e293b;" id="titleDonutDefect"><?= $isPassedMode ? 'Rasio Status Inspeksi (Yield)' : ('Top <span id="lblRankDefect">5</span> Jenis Defect') ?></div>
                            <div style="font-size:10px;color:#94a3b8;">Satuan: <?= strtolower($unitText) ?></div>
                        </div>
                    </div>
                    <!-- Pill Filter Rank Defect [3, 5, 10] -->
                    <?php if (!$isPassedMode): ?>
                    <div style="display:inline-flex;align-items:center;background:#f1f5f9;padding:2px;border-radius:6px;gap:2px;" id="pillsRankDefect">
                        <?php foreach ([3, 5, 10] as $rk): ?>
                            <button type="button" onclick="changeDonutRank('defect', <?= $rk ?>)" id="btnRankDefect_<?= $rk ?>" style="padding:2px 7px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:<?= $rk === 5 ? '#ef4444' : 'transparent' ?>;color:<?= $rk === 5 ? '#ffffff' : '#64748b' ?>;">
                                <?= $rk ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if (empty($defectCounts)): ?>
                    <div style="height:160px;display:flex;align-items:center;justify-content:center;color:#cbd5e1;font-size:11px;background:#f8fafc;border-radius:8px;border:1px dashed #e2e8f0;">Belum ada data</div>
                <?php else: ?>
                    <div style="display:flex;gap:12px;align-items:center;min-height:160px;">
                        <div style="width:142px;height:142px;flex-shrink:0;position:relative;display:flex;align-items:center;justify-content:center;">
                            <canvas id="chartDefect" style="width:100%;height:100%;cursor:pointer;"></canvas>
                            <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none;text-align:center;">
                                <span style="font-size:8.5px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:0.04em;"><?= $isPassedMode ? 'TOTAL' : 'TOTAL NG' ?></span>
                                <span id="donutDefectTotalVal" style="font-size:15px;font-weight:900;color:#0f172a;line-height:1.1;"><?= number_format($kpi['total_ng'] ?? 0) ?></span>
                                <span style="font-size:8.5px;font-weight:700;color:#64748b;"><?= strtolower($unitText) ?></span>
                            </div>
                        </div>
                        <div id="defectTypeLegendContainer" style="flex:1;min-width:0;display:flex;flex-direction:column;gap:5px;max-height:160px;overflow-y:auto;overflow-x:hidden;">
                            <?php $totalSampleSize = (int)($kpi['total_inspected'] ?? 0) ?: 1;
                            foreach ($defectLabels as $di => $dl):
                                $dpct = round($defectCounts[$di]/$totalSampleSize*100, 2);
                                $dc   = $DEFECT_PALETTE[$di%count($DEFECT_PALETTE)]; ?>
                            <div onclick="handleDonutLegendClick('defect', '<?= htmlspecialchars($dl, ENT_QUOTES) ?>')" data-legend-type="defect" data-legend-name="<?= htmlspecialchars($dl, ENT_QUOTES) ?>" style="display:flex;align-items:center;justify-content:space-between;font-size:10.5px;gap:6px;padding:4px 8px;border-radius:6px;background:#f8fafc;border-left:3.5px solid <?= $dc ?>;cursor:pointer;transition:all 0.15s ease-in-out;border-top:1px solid transparent;border-right:1px solid transparent;border-bottom:1px solid transparent;min-width:0;" class="hover:bg-red-50 hover:shadow-xs group">
                                <span style="display:flex;align-items:center;gap:5px;color:#334155;flex:1;min-width:0;overflow:hidden;">
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
            <div class="card" style="padding:14px 16px;display:flex;flex-direction:column;min-width:0;border-top:3px solid #6366f1;">
                <div style="flex-shrink:0;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <div style="width:28px;height:28px;border-radius:8px;background:#e0e7ff;color:#4f46e5;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
                        </div>
                        <div>
                            <div style="font-size:13px;font-weight:800;color:#1e293b;display:flex;align-items:center;gap:5px;flex-wrap:wrap;" id="titleDonutModel">
                                <span>Top <span id="lblRankModel">5</span> Model</span>
                                <span id="badgeHeaderModelFilter" style="display:none;background:#fee2e2;color:#dc2626;border:1px solid #fecaca;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:800;align-items:center;gap:4px;">
                                    <span id="textHeaderModelFilter">Defect</span>
                                    <button type="button" onclick="clearCascadeFilter('defect')" title="Hapus filter defect" style="background:none;border:none;cursor:pointer;color:#dc2626;font-weight:bold;padding:0 2px;line-height:1;">✕</button>
                                </span>
                                <span id="lblModelDefaultMode" style="color:#64748b;font-weight:700;">(<?= $isPassedMode ? 'Passed' : 'NG' ?>)</span>
                            </div>
                            <div id="subTitleDonutModel" style="font-size:10px;color:#94a3b8;">Satuan: <?= strtolower($unitText) ?></div>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                        <!-- Mode Switcher: PCS vs Frekuensi -->
                        <div style="display:inline-flex;background:#f1f5f9;padding:2px;border-radius:6px;border:1px solid #e2e8f0;gap:2px;" title="Pilih Dasar Satuan: Kuantitas Cacat (PCS) atau Frekuensi Kasus Temuan (x/kali)">
                            <button type="button" id="btnModelModePcs" onclick="switchModelMetricMode('pcs')" style="padding:2px 8px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:#6366f1;color:#ffffff;box-shadow:0 1px 2px rgba(99,102,241,0.25);transition:all 0.15s;">
                                PCS
                            </button>
                            <button type="button" id="btnModelModeFreq" onclick="switchModelMetricMode('freq')" style="padding:2px 8px;font-size:10px;font-weight:700;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;transition:all 0.15s;">
                                X
                            </button>
                        </div>

                        <!-- Pill Filter Rank Model [3, 5, 10] -->
                        <div style="display:inline-flex;align-items:center;background:#f1f5f9;padding:2px;border-radius:6px;gap:2px;" id="pillsRankModel">
                            <?php foreach ([3, 5, 10] as $rk): ?>
                                <button type="button" onclick="changeDonutRank('model', <?= $rk ?>)" id="btnRankModel_<?= $rk ?>" style="padding:2px 7px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:<?= $rk === 5 ? '#6366f1' : 'transparent' ?>;color:<?= $rk === 5 ? '#ffffff' : '#64748b' ?>;">
                                    <?= $rk ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php if (empty($modelCounts)): ?>
                    <div style="height:160px;display:flex;align-items:center;justify-content:center;color:#cbd5e1;font-size:11px;background:#f8fafc;border-radius:8px;border:1px dashed #e2e8f0;">Belum ada data per model</div>
                <?php else: ?>
                    <div style="display:flex;gap:12px;align-items:center;min-height:160px;">
                        <div style="width:142px;height:142px;flex-shrink:0;position:relative;display:flex;align-items:center;justify-content:center;">
                            <canvas id="chartModelPie" style="width:100%;height:100%;cursor:pointer;"></canvas>
                            <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none;text-align:center;">
                                <span style="font-size:8px;font-weight:800;color:#6366f1;text-transform:uppercase;letter-spacing:0.04em;">MODEL</span>
                                <div style="display:flex;align-items:baseline;justify-content:center;gap:2px;line-height:1;">
                                    <span id="donutModelTotalVal" style="font-size:15px;font-weight:900;color:#1e1b4b;"><?= $totalDistinctModelsNg ?></span>
                                    <span style="font-size:8.5px;font-weight:700;color:#64748b;">tipe</span>
                                </div>
                                <span id="donutModelQtyVal" style="font-size:8.5px;font-weight:800;color:#4338ca;margin-top:1px;">(<?= number_format(array_sum($modelCounts)) ?> <?= strtolower($unitText) ?>)</span>
                            </div>
                        </div>
                        <div id="modelLegendContainer" style="flex:1;min-width:0;display:flex;flex-direction:column;gap:5px;max-height:160px;overflow-y:auto;overflow-x:hidden;">
                            <?php foreach ($modelLabels as $mi => $ml):
                                $mpct = round($modelCounts[$mi]/$totalSampleSize*100, 2);
                                $mc   = $MODEL_PALETTE[$mi%count($MODEL_PALETTE)];
                                $mcases = $modelCases[$mi] ?? 0; ?>
                            <div onclick="handleDonutLegendClick('model', '<?= htmlspecialchars($ml, ENT_QUOTES) ?>')" data-legend-type="model" data-legend-name="<?= htmlspecialchars($ml, ENT_QUOTES) ?>" data-pcs="<?= (int)$modelCounts[$mi] ?>" data-cases="<?= (int)$mcases ?>" data-pct="<?= $mpct ?>" style="display:flex;align-items:center;justify-content:space-between;font-size:10.5px;gap:6px;padding:4px 8px;border-radius:6px;background:#f8fafc;border-left:3.5px solid <?= $mc ?>;cursor:pointer;transition:all 0.15s ease-in-out;border-top:1px solid transparent;border-right:1px solid transparent;border-bottom:1px solid transparent;min-width:0;" class="hover:bg-indigo-50 hover:shadow-xs group">
                                <span style="display:flex;align-items:center;gap:5px;color:#334155;flex:1;min-width:0;overflow:hidden;">
                                     <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:700;"><?= htmlspecialchars($ml) ?></span>
                                </span>
                                <span class="model-legend-val" style="font-weight:800;color:#0f172a;flex-shrink:0;"><?= number_format($modelCounts[$mi]) ?> <span style="font-weight:500;color:#64748b;"><?= strtolower($unitText) ?> (<?= $mpct ?>%)</span></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Card 3: Distribusi NG per Part -->
            <div class="card" style="padding:14px 16px;display:flex;flex-direction:column;min-width:0;border-top:3px solid #0d9488;">
                <div style="flex-shrink:0;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <div style="width:28px;height:28px;border-radius:8px;background:#ccfbf1;color:#0d9488;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
                        </div>
                        <div>
                            <div style="font-size:13px;font-weight:800;color:#1e293b;display:flex;align-items:center;gap:5px;flex-wrap:wrap;" id="titleDonutPart">
                                <span>Top <span id="lblRankPart">5</span> Part</span>
                                <span id="badgeHeaderPartFilter" style="display:none;background:#fee2e2;color:#dc2626;border:1px solid #fecaca;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:800;align-items:center;gap:4px;">
                                    <span id="textHeaderPartFilter">Defect</span>
                                    <button type="button" onclick="clearCascadeFilter('defect')" title="Hapus filter defect" style="background:none;border:none;cursor:pointer;color:#dc2626;font-weight:bold;padding:0 2px;line-height:1;">✕</button>
                                </span>
                                <span id="lblPartDefaultMode" style="color:#64748b;font-weight:700;">(<?= $isPassedMode ? 'Passed' : 'NG' ?>)</span>
                            </div>
                            <div id="subTitleDonutPart" style="font-size:10px;color:#94a3b8;">Satuan: <?= strtolower($unitText) ?></div>
                        </div>
                    </div>
                    <!-- Pill Filter Rank Part [3, 5, 10] -->
                    <div style="display:inline-flex;align-items:center;background:#f1f5f9;padding:2px;border-radius:6px;gap:2px;" id="pillsRankPart">
                        <?php foreach ([3, 5, 10] as $rk): ?>
                            <button type="button" onclick="changeDonutRank('part', <?= $rk ?>)" id="btnRankPart_<?= $rk ?>" style="padding:2px 7px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:<?= $rk === 5 ? '#0d9488' : 'transparent' ?>;color:<?= $rk === 5 ? '#ffffff' : '#64748b' ?>;">
                                <?= $rk ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php if (empty($pieCounts)): ?>
                    <div style="height:160px;display:flex;align-items:center;justify-content:center;color:#cbd5e1;font-size:11px;background:#f8fafc;border-radius:8px;border:1px dashed #e2e8f0;">Belum ada data per part</div>
                <?php else: ?>
                    <div style="display:flex;gap:12px;align-items:center;min-height:160px;">
                        <div style="width:142px;height:142px;flex-shrink:0;position:relative;display:flex;align-items:center;justify-content:center;">
                            <canvas id="chartPartPie" style="width:100%;height:100%;cursor:pointer;"></canvas>
                            <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none;text-align:center;">
                                <span style="font-size:8px;font-weight:800;color:#0d9488;text-transform:uppercase;letter-spacing:0.04em;">PART</span>
                                <div style="display:flex;align-items:baseline;justify-content:center;gap:2px;line-height:1;">
                                    <span id="donutPartTotalVal" style="font-size:15px;font-weight:900;color:#134e4a;"><?= $totalDistinctPartsNg ?></span>
                                    <span style="font-size:8.5px;font-weight:700;color:#64748b;">item</span>
                                </div>
                                <span id="donutPartQtyVal" style="font-size:8.5px;font-weight:800;color:#0f766e;margin-top:1px;">(<?= number_format(array_sum($pieCounts)) ?> <?= strtolower($unitText) ?>)</span>
                            </div>
                        </div>
                        <div id="partLegendContainer" style="flex:1;min-width:0;display:flex;flex-direction:column;gap:5px;max-height:160px;overflow-y:auto;overflow-x:hidden;">
                            <?php foreach ($pieLabels as $pi => $pl):
                                $ppct = round($pieCounts[$pi]/$totalSampleSize*100, 2);
                                $pc   = $PART_PALETTE[$pi%count($PART_PALETTE)]; ?>
                            <div onclick="handleDonutLegendClick('part', '<?= htmlspecialchars($pl, ENT_QUOTES) ?>')" data-legend-type="part" data-legend-name="<?= htmlspecialchars($pl, ENT_QUOTES) ?>" style="display:flex;align-items:center;justify-content:space-between;font-size:10.5px;gap:6px;padding:4px 8px;border-radius:6px;background:#f8fafc;border-left:3.5px solid <?= $pc ?>;cursor:pointer;transition:all 0.15s ease-in-out;border-top:1px solid transparent;border-right:1px solid transparent;border-bottom:1px solid transparent;min-width:0;" class="hover:bg-teal-50 hover:shadow-xs group">
                                <span style="display:flex;align-items:center;gap:5px;color:#334155;flex:1;min-width:0;overflow:hidden;">
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

        <!-- ── Section: Riwayat Temuan Cacat Terperinci (Cascading Drilldown Results) ─────── -->
        <div id="cascadeFindingsSection" class="card" style="display:none;margin-top:10px;padding:16px 18px;border-top:3px solid #2563eb;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid #f1f5f9;flex-wrap:wrap;gap:8px;">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <div style="width:28px;height:28px;border-radius:8px;background:#dbeafe;color:#2563eb;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                    </div>
                    <div>
                        <div style="font-size:13px;font-weight:800;color:#1e293b;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <span>Riwayat Temuan Cacat Inspeksi Terperinci</span>
                            <span id="badgeFindingsTotalCount" style="background:#1e1b4b;color:#ffffff;padding:2px 10px;border-radius:12px;font-size:10.5px;font-weight:800;letter-spacing:0.02em;box-shadow:0 1px 2px rgba(0,0,0,0.1);">0 Temuan</span>
                        </div>
                        <div style="margin-top:3px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;" id="descFindingsSubtitle">
                            <span style="font-size:11px;color:#64748b;font-weight:600;">Hasil filter interaktif per jenis cacat, model, dan part</span>
                        </div>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:6px;">
                    <input type="text" id="inputSearchFindings" placeholder="Cari part / model / customer / lot..." onkeyup="filterCascadeFindingsTable()" style="padding:4px 10px;font-size:11px;border:1px solid #cbd5e1;border-radius:6px;width:220px;outline:none;">
                    <button type="button" onclick="closeCascadeFindingsSection()" style="background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;padding:4px 8px;font-size:11px;font-weight:700;color:#64748b;cursor:pointer;">
                        ✕ Tutup
                    </button>
                </div>
            </div>

            <!-- Table Container with Horizontal Scroll -->
            <div style="overflow-x:auto;max-height:360px;border:1px solid #e2e8f0;border-radius:8px;">
                <table style="width:100%;border-collapse:collapse;font-size:11px;text-align:left;" id="tableCascadeFindings">
                    <thead>
                        <tr style="background:#1e293b;color:#ffffff;font-size:9.5px;font-weight:800;letter-spacing:0.04em;text-transform:uppercase;position:sticky;top:0;z-index:3;">
                            <th style="padding:8px 10px;white-space:nowrap;width:35px;">NO</th>
                            <th style="padding:8px 10px;white-space:nowrap;">TANGGAL & JAM</th>
                            <th style="padding:8px 10px;white-space:nowrap;">PART CODE & PART NAME</th>
                            <th style="padding:8px 10px;white-space:nowrap;">MODEL PRODUK</th>
                            <th style="padding:8px 10px;white-space:nowrap;">INSPEKSI</th>
                            <th style="padding:8px 10px;white-space:nowrap;">NO KANBAN / CUSTOMER</th>
                            <th style="padding:8px 10px;white-space:nowrap;">LOT NO / REF</th>
                            <th style="padding:8px 10px;white-space:nowrap;">QC INSPECTOR</th>
                            <th style="padding:8px 10px;text-align:right;white-space:nowrap;color:#fbbf24;">JUMLAH TEMUAN</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyCascadeFindings">
                        <tr>
                            <td colspan="9" style="padding:20px;text-align:center;color:#94a3b8;">Pilih jenis defect, model, atau part di atas untuk melihat riwayat temuan detail</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Page Break for Clean 2-Page Print Layout (Halaman 1: Ringkasan & Semua Grafik, Halaman 2: Detail Part & Evaluasi) -->
        <div class="print-page-break"></div>

        <!-- ── Section 3: II. WORST PART OQC vs BEST PERFORMANCE PART OQC ─────── -->
        <div id="sectionWorstBestPartsContainer" class="card" style="padding:16px 18px;">
            <?php if ($isPassedMode): ?>
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid #f1f5f9;">
                    <div>
                        <h2 style="font-size:14px;font-weight:900;color:#1e293b;letter-spacing:.02em;text-transform:uppercase;margin:0;display:flex;align-items:center;gap:6px;">
                            <span style="color:#059669;"></span> BEST PERFORMANCE PART OQC
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
                                    <th style="padding:8px 12px;text-align:center;">LOT CASE (X)</th>
                                    <th style="padding:8px 12px;text-align:center;">PASSED CASE </th>
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
                            <span style="color:#2563eb;"></span> WORST PART OQC
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
                                    <th style="padding:8px 12px;text-align:center;">LOT NG (X)</th>
                                    <th style="padding:8px 12px;text-align:center;">QTY NG (PCS)</th>
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
            .kpi-clickable-card .kpi-drilldown-arrow {
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
                font-size: 8pt !important;
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
                border-radius: 6px !important;
                background: #ffffff !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                margin-bottom: 6px !important;
            }

            /* 5 Kartu KPI utama terbagi 5 kolom rapi */
            #kpi-grid {
                display: grid !important;
                grid-template-columns: repeat(5, 1fr) !important;
                gap: 8px !important;
                margin-bottom: 8px !important;
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

            /* Baris Grafik 1: Tren & Top Defect Matrix (Diperbesar agar mengisi space) */
            #dash-row-1 {
                display: grid !important;
                grid-template-columns: 1.25fr 1fr !important;
                gap: 8px !important;
                margin-bottom: 8px !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }

            #dash-row-1 > .card {
                padding: 10px 12px !important;
                margin-bottom: 0 !important;
            }

            #trendChartScrollContainer {
                height: 255px !important;
                min-height: 255px !important;
            }

            #chartTrendNG {
                height: 255px !important;
                max-height: 255px !important;
            }

            #dash-row-1 div[style*="height:240px;min-height:240px"] {
                height: 255px !important;
                min-height: 255px !important;
            }

            /* Baris Grafik 2: 3 Donut Charts (Defect, Model, Part) */
            #dash-row-2 {
                display: grid !important;
                grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
                gap: 8px !important;
                margin-top: 0 !important;
                margin-bottom: 0 !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }

            #dash-row-2 > .card {
                padding: 8px 10px !important;
                margin-bottom: 0 !important;
                min-width: 0 !important;
            }

            #dash-row-2 div[style*="min-height:160px"] {
                min-height: 145px !important;
                display: flex !important;
                align-items: center !important;
                gap: 8px !important;
            }

            #dash-row-2 div[style*="width:142px;height:142px"] {
                width: 125px !important;
                height: 125px !important;
                flex-shrink: 0 !important;
            }

            #dash-row-2 canvas {
                width: 125px !important;
                height: 125px !important;
            }

            /* Tampilkan seluruh Top 10 secara penuh tanpa scrollbar */
            #dash-row-2 div[id*="LegendContainer"] {
                max-height: none !important;
                height: auto !important;
                overflow: visible !important;
                display: flex !important;
                flex-direction: column !important;
                gap: 2.5px !important;
            }

            #dash-row-2 div[id*="LegendContainer"] > div {
                padding: 1.5px 5px !important;
                font-size: 8.5px !important;
                border-radius: 4px !important;
                line-height: 1.25 !important;
            }

            #dash-row-2 div[id*="LegendContainer"] > div span {
                font-size: 8.5px !important;
            }

            /* Chart & Table Matrix */
            canvas {
                max-width: 100% !important;
            }

            #defectTimelineChartView {
                overflow: hidden !important;
                height: 255px !important;
                max-height: 255px !important;
            }

            #defectTimelineTableView {
                overflow: visible !important;
                height: 255px !important;
                max-height: 255px !important;
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

            /* Riwayat Temuan Cacat Inspeksi Terperinci (Mode Cetak: Tampilkan Full Tanpa Scroll) */
            #cascadeFindingsSection {
                page-break-inside: auto !important;
                break-inside: auto !important;
                margin-top: 10px !important;
            }
            #cascadeFindingsSection input,
            #cascadeFindingsSection button {
                display: none !important;
            }
            #cascadeFindingsSection div[style*="max-height:360px"],
            #cascadeFindingsSection div[style*="max-height: 360px"],
            #cascadeFindingsSection .overflow-x-auto {
                max-height: none !important;
                height: auto !important;
                overflow: visible !important;
                border: 1px solid #cbd5e1 !important;
            }
            #tableCascadeFindings {
                width: 100% !important;
                page-break-inside: auto !important;
            }
            #tableCascadeFindings thead tr {
                position: static !important;
            }
            #tableCascadeFindings tr {
                page-break-inside: avoid !important;
            }
        }
    </style>

    <script src="<?= base_url('assets/js/vendor/chart.min.js') ?>"></script>
    <script>
    window.OQC_DASH_CONFIG = {
        activeUnit: <?= json_encode($unitText) ?>,
        activeSampleBasis: <?= json_encode($selectedSampleBasis) ?>,
        isPassedMode: <?= $isPassedMode ? 'true' : 'false' ?>,
        totalInspectedKpi: <?= (int)($kpi['total_inspected'] ?? 0) ?>,
        totalNgKpi: <?= (int)($kpi['total_ng'] ?? 0) ?>,
        initialTotalModelsNg: <?= (int)$totalDistinctModelsNg ?>,
        initialTotalPartsNg: <?= (int)$totalDistinctPartsNg ?>,
        currentDashboardVersion: <?= json_encode($initialDashboardVersion) ?>,
        filterParams: {
            customer: <?= json_encode($selectedCustomer) ?>,
            unit: <?= json_encode($selectedUnit) ?>,
            sample_basis: <?= json_encode($selectedSampleBasis) ?>,
            result: <?= json_encode($selectedResult) ?>,
            filter_type: <?= json_encode($filterType) ?>,
            preset: <?= json_encode($presetFilter) ?>,
            month: <?= json_encode($selectedMonth) ?>,
            year: <?= json_encode($selectedYear) ?>,
            start_date: <?= json_encode($startDate) ?>,
            end_date: <?= json_encode($endDate) ?>,
            top_rank: <?= json_encode($selectedTopRank) ?>,
            defect_rank: <?= json_encode($selectedDefectRank) ?>
        },
        multiHorizonData: <?= $jsMultiHorizon ?>,
        trendLabelsData: <?= $jsLabels ?>,
        trendNGData: <?= $jsTrendNG ?>,
        trendSampleData: <?= $jsTrendSample ?>,
        globalTargetPpm: <?= (float)$dashboardTargetPpm ?>,
        modelCasesData: <?= $jsModelCases ?>,
        stackedDatasetsPcs: <?= $jsStackedDatasetsPcs ?>,
        stackedDatasetsPpm: <?= $jsStackedDatasetsPpm ?>,
        stackedLabels: <?= $jsStackedLabels ?>,
        defectLabels: <?= $jsDefectLabels ?>,
        defectCounts: <?= $jsDefectCounts ?>,
        defectColors: <?= $jsDefectColors ?>,
        modelLabels: <?= $jsModelLabels ?>,
        modelCounts: <?= $jsModelCounts ?>,
        modelColors: <?= $jsModelColors ?>,
        pieLabels: <?= $jsPieLabels ?>,
        pieCounts: <?= $jsPieCounts ?>,
        partColors: <?= $jsPartColors ?>
    };
    </script>
    <script src="<?= base_url('assets/js/modules/dashboard.js?v=' . filemtime(__DIR__ . '/../../assets/js/modules/dashboard.js')) ?>" defer></script>

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

            <!-- Controls: Sub-Tabs, Grouping Switcher, Live Search Bar, & CSV Export -->
            <div style="padding:8px 20px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <!-- Grouping Toggle: Per Part vs Per Customer -->
                    <div id="kpiModalGroupSwitcher" style="display:none;align-items:center;gap:4px;background:#e2e8f0;padding:3px;border-radius:8px;">
                        <button type="button" id="btnKpiGroupPart" onclick="switchKpiGroupingMode('part')" style="padding:3px 10px;font-size:10.5px;font-weight:800;border-radius:6px;border:none;cursor:pointer;transition:all 0.15s;background:#ffffff;color:#2563eb;box-shadow:0 1px 2px rgba(0,0,0,0.06);">Per Part</button>
                        <button type="button" id="btnKpiGroupCust" onclick="switchKpiGroupingMode('customer')" style="padding:3px 10px;font-size:10.5px;font-weight:800;border-radius:6px;border:none;cursor:pointer;transition:all 0.15s;background:transparent;color:#64748b;">Per Customer</button>
                    </div>

                    <!-- Tabs container (visible for total_ng) -->
                    <div id="kpiModalTabsContainer" style="display:none;align-items:center;gap:4px;background:#e2e8f0;padding:3px;border-radius:8px;">
                        <!-- Buttons injected by JS -->
                    </div>
                </div>
                
                <!-- Live Search Bar -->
                <div style="display:flex;align-items:center;gap:8px;flex:1;max-width:380px;min-width:200px;">
                    <div style="position:relative;width:100%;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2.5" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);pointer-events:none;"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                        <input type="text" id="kpiModalSearchInput" oninput="filterKpiModalTable(this.value)" placeholder="Ketik kata kunci untuk menyaring baris tabel..." style="width:100%;padding:5px 10px 5px 30px;font-size:11px;border-radius:7px;border:1px solid #cbd5e1;background:#ffffff;outline:none;" onfocus="this.style.borderColor='#2563eb'" onblur="this.style.borderColor='#cbd5e1'">
                    </div>
                </div>

                <!-- Export modal data to Excel -->
                <div>
                    <button type="button" id="btnExportKpiExcel" onclick="exportKpiModalToExcel()" style="display:inline-flex;align-items:center;gap:6px;padding:5px 12px;background:#059669;color:#ffffff;border:1px solid #047857;border-radius:7px;font-size:11px;font-weight:800;cursor:pointer;transition:all 0.15s;box-shadow:0 1px 2px rgba(5,150,105,0.25);" onmouseover="this.style.background='#047857'" onmouseout="this.style.background='#059669'">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        Unduh Excel
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
