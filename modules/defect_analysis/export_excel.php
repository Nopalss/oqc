<?php
/**
 * Official Visual Excel Report Generator — Analisis Defect & Part OQC (1:1 WYSIWYG Mirror)
 * Generates an executive-grade, chart-rich XLSX spreadsheet mirroring the active web dashboard tab:
 * 
 * Skenario 1 (Tab Analisis & Tren Defect / mode=overview):
 * - Sheet 1: 🎯 Analisis & Tren Defect (Banner, 3 KPI Cards, Timeline Data & Chart, Top Part Distribution & Donut Chart)
 * - Sheet 2: 📋 Riwayat Temuan Inspeksi (Filtered by defect_id, Full Audit Trail Log, Excel SUM Formula)
 * 
 * Skenario 2 (Tab Analisis & Tren Part / mode=part_overview):
 * - Sheet 1: 🎯 Analisis & Tren Part (Banner, 3 KPI Cards, Timeline Data & Chart, Top Defect Distribution & Donut Chart)
 * - Sheet 2: 📋 Riwayat Temuan Inspeksi (Filtered by part_id, Full Audit Trail Log, Excel SUM Formula)
 *
 * PT. Surya Technology Industri — Outgoing Quality Control Division
 */

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';
require_once __DIR__ . '/../../vendor/autoload.php';

// Security Guard: Auth & RBAC access
require_login();
require_menu_access('defect_analysis');

// Performance & Memory Safeguard for Large Dataset XLSX Generation
@ini_set('memory_limit', '512M');
@set_time_limit(180);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Chart\Layout;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;

$pdo = getDB();

// ── 1. Query Parameters & Resolvers ──────────────────────────────────────────
$mode = sanitize($_GET['mode'] ?? 'overview');
if (!in_array($mode, ['overview', 'part_overview'])) {
    $mode = 'overview';
}

$selectedUnit = sanitize($_GET['unit'] ?? 'pcs');
if (!in_array($selectedUnit, ['pcs', 'lot'])) {
    $selectedUnit = 'pcs';
}

$selectedSampleBasis = sanitize($_GET['sample_basis'] ?? 'lot');
if (!in_array($selectedSampleBasis, ['lot', 'session'])) {
    $selectedSampleBasis = 'lot';
}

$selectedTopRank = sanitize($_GET['top_rank'] ?? '5');
if (!in_array($selectedTopRank, ['3', '5', '10', 'all'])) {
    $selectedTopRank = '5';
}

$selectedCustomer = sanitize($_GET['customer'] ?? '');
$presetFilter     = sanitize($_GET['preset'] ?? 'bulanan');
$filterType       = sanitize($_GET['filter_type'] ?? '');
$selectedMonth    = isset($_GET['month']) && is_numeric($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$selectedYear     = isset($_GET['year']) && is_numeric($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

$defectId = isset($_GET['defect_id']) && is_numeric($_GET['defect_id']) ? (int)$_GET['defect_id'] : 0;
$partId   = isset($_GET['part_id']) && is_numeric($_GET['part_id']) ? (int)$_GET['part_id'] : 0;

$trendMode     = sanitize($_GET['trend_mode'] ?? 'multi_horizon');
$subResolution = sanitize($_GET['sub_resolution'] ?? 'weekly');

$monthNames = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

// Available years in DB (optimized using MIN/MAX index lookup)
$dbYears = [];
if ($pdo) {
    try {
        $stmtYears = $pdo->query("SELECT MIN(started_at) as min_dt, MAX(started_at) as max_dt FROM inspection_sessions WHERE started_at IS NOT NULL");
        $minMax = $stmtYears->fetch(PDO::FETCH_ASSOC);
        if ($minMax && !empty($minMax['min_dt']) && !empty($minMax['max_dt'])) {
            $minY = (int)date('Y', strtotime($minMax['min_dt']));
            $maxY = (int)date('Y', strtotime($minMax['max_dt']));
            for ($y = $minY; $y <= $maxY; $y++) {
                $dbYears[] = $y;
            }
        }
    } catch (Exception $e) {}
}
if (empty($dbYears)) {
    $dbYears = [(int)date('Y')];
}

// Date range resolution
$customStartDate = sanitize($_GET['start_date'] ?? '');
$customEndDate   = sanitize($_GET['end_date'] ?? '');

if (!empty($customStartDate) && !empty($customEndDate)) {
    $startDate       = $customStartDate;
    $endDate         = $customEndDate;
    $presetFilter    = 'custom';
    $filterTitleDesc = date('d/m/Y', strtotime($startDate)) . ' s.d. ' . date('d/m/Y', strtotime($endDate));
} elseif ($filterType === 'all_years' || $presetFilter === 'all_years') {
    $minYear         = min($dbYears);
    $maxYear         = max($dbYears);
    if ($minYear === $maxYear) {
        $minYear = $maxYear - 1;
    }
    $startDate       = sprintf('%04d-01-01', $minYear);
    $endDate         = sprintf('%04d-12-31', $maxYear);
    $presetFilter    = 'all_years';
    $filterTitleDesc = "Semua Tahun (" . $minYear . " – " . $maxYear . ")";
} elseif ($filterType === 'specific_year' || $presetFilter === 'specific_year') {
    $startDate       = sprintf('%04d-01-01', $selectedYear);
    $endDate         = ($selectedYear === (int)date('Y')) ? date('Y-m-d') : sprintf('%04d-12-31', $selectedYear);
    $presetFilter    = 'specific_year';
    $filterTitleDesc = "Tahun " . $selectedYear;
} elseif ($filterType === 'month_year' || $presetFilter === 'month_year' || $presetFilter === 'bulanan' || empty($presetFilter)) {
    $presetFilter = 'month_year';
    $monthStart   = sprintf('%04d-%02d-01', $selectedYear, $selectedMonth);
    $firstDayDt   = new DateTime($monthStart);
    $firstDayDow  = (int)$firstDayDt->format('N'); // 1 = Mon ... 7 = Sun

    // Cari Hari Minggu pertama di bulan ini (Sunday Anchor standar pabrik)
    $w1Sun = clone $firstDayDt;
    if ($firstDayDow !== 7) {
        $w1Sun->modify('-' . $firstDayDow . ' days');
    }
    if ($w1Sun->format('m') !== $firstDayDt->format('m')) {
        $w1Sun->modify('+7 days');
    }

    $startDate       = $w1Sun->format('Y-m-d');
    $endDate         = date('Y-m-t', strtotime($monthStart));
    $filterTitleDesc = date('d/m/Y', strtotime($startDate)) . ' s.d. ' . date('d/m/Y', strtotime($endDate));
} else {
    switch ($presetFilter) {
        case 'hari_ini':
            $startDate       = date('Y-m-d');
            $endDate         = date('Y-m-d');
            $filterTitleDesc = "Hari Ini (" . date('d/m/Y') . ")";
            break;
        case 'mingguan':
            $startDate       = date('Y-m-d', strtotime('monday this week'));
            $endDate         = date('Y-m-d');
            $filterTitleDesc = "Minggu Ini (" . date('d/m', strtotime($startDate)) . " - " . date('d/m/Y', strtotime($endDate)) . ")";
            break;
        case 'tahunan':
            $startDate       = date('Y-01-01');
            $endDate         = date('Y-m-d');
            $filterTitleDesc = "Tahun Ini (" . date('Y') . ")";
            break;
        default:
            $monthStart      = date('Y-m-01');
            $firstDayDt      = new DateTime($monthStart);
            $firstDayDow     = (int)$firstDayDt->format('N');
            $w1Sun = clone $firstDayDt;
            if ($firstDayDow !== 7) {
                $w1Sun->modify('-' . $firstDayDow . ' days');
            }
            if ($w1Sun->format('m') !== $firstDayDt->format('m')) {
                $w1Sun->modify('+7 days');
            }
            $startDate       = $w1Sun->format('Y-m-d');
            $endDate         = date('Y-m-t', strtotime($monthStart));
            $filterTitleDesc = date('d/m/Y', strtotime($startDate)) . ' s.d. ' . date('d/m/Y', strtotime($endDate));
    }
}

// ── 2. Automatic Smart Selection for Rank 1 if not specified ─────────────────
if ($pdo) {
    if ($mode === 'overview' && $defectId <= 0) {
        // Find Top 1 Defect
        try {
            $wDef = ["dds.summary_date >= :sd AND dds.summary_date <= :ed"];
            $pDef = [':sd' => $startDate, ':ed' => $endDate];
            if ($selectedCustomer !== '') {
                $wDef[] = "dds.customer = :cust";
                $pDef[':cust'] = $selectedCustomer;
            }
            $wDefSql = implode(' AND ', $wDef);
            $metricCol = ($selectedUnit === 'lot') ? "SUM(dds.lot_count)" : "SUM(dds.qty_ng)";
            $stDefTop = $pdo->prepare("SELECT dds.defect_type_id, {$metricCol} as total_metric
                                       FROM oqc_daily_defect_summary dds
                                       WHERE {$wDefSql}
                                       GROUP BY dds.defect_type_id
                                       ORDER BY total_metric DESC
                                       LIMIT 1");
            $stDefTop->execute($pDef);
            $topD = $stDefTop->fetch(PDO::FETCH_ASSOC);
            if ($topD && !empty($topD['defect_type_id'])) {
                $defectId = (int)$topD['defect_type_id'];
            }
            // Fallback to active sessions if no summary rows
            if ($defectId <= 0) {
                $wActD = [
                    "s.status = 'in_progress'",
                    "(ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)",
                    "s.started_at >= :sd AND s.started_at < :ed_next"
                ];
                $pActD = [
                    ':sd'      => $startDate . ' 00:00:00',
                    ':ed_next' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'
                ];
                if ($selectedCustomer !== '') {
                    $wActD[] = "COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') = :cust";
                    $pActD[':cust'] = $selectedCustomer;
                }
                $wActDSql = implode(' AND ', $wActD);
                $stActDef = $pdo->prepare("SELECT ngr.defect_type_id, SUM(ngr.qty_ng) as total_metric
                                           FROM inspection_ng_records ngr
                                           INNER JOIN inspection_sessions s ON ngr.inspection_session_id = s.id
                                           LEFT JOIN kanban_items ki ON s.kanban_item_id = ki.id
                                           WHERE {$wActDSql}
                                           GROUP BY ngr.defect_type_id
                                           ORDER BY total_metric DESC
                                           LIMIT 1");
                $stActDef->execute($pActD);
                $actD = $stActDef->fetch(PDO::FETCH_ASSOC);
                if ($actD && !empty($actD['defect_type_id'])) {
                    $defectId = (int)$actD['defect_type_id'];
                }
            }
        } catch (Exception $e) {}
    } elseif ($mode === 'part_overview' && $partId <= 0) {
        // Find Top 1 Part
        try {
            $wPart = ["dds.summary_date >= :sd AND dds.summary_date <= :ed"];
            $pPart = [':sd' => $startDate, ':ed' => $endDate];
            if ($selectedCustomer !== '') {
                $wPart[] = "dds.customer = :cust";
                $pPart[':cust'] = $selectedCustomer;
            }
            $wPartSql = implode(' AND ', $wPart);
            $metricCol = ($selectedUnit === 'lot') ? "SUM(dds.lot_count)" : "SUM(dds.qty_ng)";
            $stPartTop = $pdo->prepare("SELECT dds.part_id, {$metricCol} as total_metric
                                        FROM oqc_daily_defect_summary dds
                                        WHERE {$wPartSql}
                                        GROUP BY dds.part_id
                                        ORDER BY total_metric DESC
                                        LIMIT 1");
            $stPartTop->execute($pPart);
            $topP = $stPartTop->fetch(PDO::FETCH_ASSOC);
            if ($topP && !empty($topP['part_id'])) {
                $partId = (int)$topP['part_id'];
            }
            if ($partId <= 0) {
                $wActP = [
                    "s.status = 'in_progress'",
                    "(ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)",
                    "s.started_at >= :sd AND s.started_at < :ed_next"
                ];
                $pActP = [
                    ':sd'      => $startDate . ' 00:00:00',
                    ':ed_next' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'
                ];
                if ($selectedCustomer !== '') {
                    $wActP[] = "COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') = :cust";
                    $pActP[':cust'] = $selectedCustomer;
                }
                $wActPSql = implode(' AND ', $wActP);
                $stActPart = $pdo->prepare("SELECT ngr.part_id, SUM(ngr.qty_ng) as total_metric
                                            FROM inspection_ng_records ngr
                                            INNER JOIN inspection_sessions s ON ngr.inspection_session_id = s.id
                                            LEFT JOIN kanban_items ki ON s.kanban_item_id = ki.id
                                            WHERE {$wActPSql}
                                            GROUP BY ngr.part_id
                                            ORDER BY total_metric DESC
                                            LIMIT 1");
                $stActPart->execute($pActP);
                $actP = $stActPart->fetch(PDO::FETCH_ASSOC);
                if ($actP && !empty($actP['part_id'])) {
                    $partId = (int)$actP['part_id'];
                }
            }
        } catch (Exception $e) {}
    }
}

// Fetch Identity Names
$defectName = 'Semua Defect';
$partCode   = 'Semua Part';
$partName   = '-';
$modelName  = 'Semua Model';

if ($pdo) {
    if ($defectId > 0) {
        $stD = $pdo->prepare("SELECT name FROM defect_types WHERE id = :id");
        $stD->execute([':id' => $defectId]);
        $defectName = $stD->fetchColumn() ?: ('Defect #' . $defectId);

        // Ambil target defect spesifik atau global
        $stThreshDef = $pdo->prepare("SELECT threshold_val FROM defect_threshold_targets WHERE target_type = 'specific_defect' AND reference_id = :id LIMIT 1");
        $stThreshDef->execute([':id' => $defectId]);
        $dThreshVal = $stThreshDef->fetchColumn();
        if ($dThreshVal === false || $dThreshVal === null) {
            $stThreshGlobDef = $pdo->prepare("SELECT threshold_val FROM defect_threshold_targets WHERE target_type = 'global_defect' AND reference_id IS NULL LIMIT 1");
            $stThreshGlobDef->execute();
            $dThreshVal = $stThreshGlobDef->fetchColumn();
        }
        $defectThreshold = ($dThreshVal !== false && $dThreshVal !== null) ? (float)$dThreshVal : null;
    } else {
        $defectThreshold = null;
    }
    if ($partId > 0) {
        $stP = $pdo->prepare("SELECT mp.part_code, mp.part_name, COALESCE(mm.name, mp.model, 'General Model') as model_name 
                              FROM master_parts mp 
                              LEFT JOIN master_models mm ON mp.model_id = mm.id 
                              WHERE mp.id = :id");
        $stP->execute([':id' => $partId]);
        $pRow = $stP->fetch(PDO::FETCH_ASSOC);
        if ($pRow) {
            $partCode  = $pRow['part_code'];
            $partName  = $pRow['part_name'];
            $modelName = $pRow['model_name'];
        }

        // Ambil target part spesifik atau global
        $stThresh = $pdo->prepare("SELECT threshold_val FROM defect_threshold_targets WHERE target_type = 'specific_part' AND reference_id = :id LIMIT 1");
        $stThresh->execute([':id' => $partId]);
        $pThreshVal = $stThresh->fetchColumn();
        if ($pThreshVal === false || $pThreshVal === null) {
            $stThreshGlob = $pdo->prepare("SELECT threshold_val FROM defect_threshold_targets WHERE target_type = 'global_part' AND reference_id IS NULL LIMIT 1");
            $stThreshGlob->execute();
            $pThreshVal = $stThreshGlob->fetchColumn();
        }
        $partThreshold = ($pThreshVal !== false && $pThreshVal !== null) ? (float)$pThreshVal : null;
    } else {
        $partThreshold = null;
    }
}

// ── 3. Timeframe Intervals Generator ─────────────────────────────────────────
$isSingleDay = ($startDate === $endDate);
$daysDiff = (strtotime($endDate) - strtotime($startDate)) / 86400;
$trendMap = [];
$intervalType = 'bulan';

if ($isSingleDay) {
    $intervalType = 'jam';
    for ($h = 6; $h <= 22; $h++) {
        $hKey = sprintf('%02d:00', $h);
        $trendMap[$hKey] = ['label' => $hKey, 'pcs' => 0, 'lot' => 0];
    }
} elseif ($filterType === 'all_years' || ($filterType === 'custom' && $daysDiff > 730)) {
    $intervalType = 'tahun';
    $minY = (int)date('Y', strtotime($startDate));
    $maxY = (int)date('Y', strtotime($endDate));
    for ($yr = $minY; $yr <= $maxY; $yr++) {
        $yKey = (string)$yr;
        $trendMap[$yKey] = ['label' => $yKey, 'pcs' => 0, 'lot' => 0];
    }
} elseif ($daysDiff <= 90) {
    $intervalType = 'hari';
    $startPeriod = new DateTime($startDate);
    $endPeriod   = new DateTime($endDate);
    $endPeriod->modify('+1 day');
    $interval    = new DateInterval('P1D');
    $period      = new DatePeriod($startPeriod, $interval, $endPeriod);

    foreach ($period as $dt) {
        $dKey = $dt->format('Y-m-d');
        $lbl  = $dt->format('d M');
        $trendMap[$dKey] = ['label' => $lbl, 'pcs' => 0, 'lot' => 0];
    }
} else {
    $intervalType = 'bulan';
    $startPeriod = new DateTime(date('Y-m-01', strtotime($startDate)));
    $endPeriod   = new DateTime(date('Y-m-01', strtotime($endDate)));
    $endPeriod->modify('+1 month');
    $interval    = DateInterval::createFromDateString('1 month');
    $period      = new DatePeriod($startPeriod, $interval, $endPeriod);

    $mNamesShort = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
        7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
    ];

    foreach ($period as $dt) {
        $mKey = $dt->format('Y-m');
        $lbl  = ($mNamesShort[(int)$dt->format('n')] ?? $dt->format('M')) . ' ' . $dt->format('y');
        $trendMap[$mKey] = ['label' => $lbl, 'pcs' => 0, 'lot' => 0];
    }
}

// ── 4. Query Data: Timeline, Distribution & Audit Log ────────────────────────
$distributionList = [];
$auditRecords     = [];

if ($pdo) {
    if ($mode === 'overview') {
        // ── Skenario 1: Overview Mode (Focus on Defect) ───────────────────────
        // 1. Timeline Data for Defect (Multi-Horizon Support: Tahunan -> Bulanan -> Mingguan/Harian)
        try {
            $isPartMode = false;
            $itemId     = $defectId;
            $itemCol    = "dds.defect_type_id";
            $refYear    = $selectedYear ?: (int)date('Y');
            $refMonth   = $selectedMonth ?: (int)date('n');

            // 1a. HORIZON TAHUNAN (Dinamis: Tahun dengan riwayat data s/d refYear)
            $wYDetect = [
                "{$itemCol} = :itemId",
                "YEAR(dds.summary_date) <= :refYear"
            ];
            $pYDetect = [
                ':itemId'  => $itemId,
                ':refYear' => $refYear
            ];
            if ($selectedCustomer !== '') {
                $wYDetect[] = "dds.customer = :cust";
                $pYDetect[':cust'] = $selectedCustomer;
            }
            $stDetectY = $pdo->prepare("SELECT DISTINCT YEAR(dds.summary_date) AS yr
                                        FROM oqc_daily_defect_summary dds
                                        WHERE " . implode(' AND ', $wYDetect) . "
                                        ORDER BY yr ASC");
            $stDetectY->execute($pYDetect);
            $detectedYears = $stDetectY->fetchAll(PDO::FETCH_COLUMN);

            if (empty($detectedYears)) {
                $years = [$refYear - 2, $refYear - 1, $refYear];
            } else {
                if (count($detectedYears) > 4) {
                    $years = array_slice($detectedYears, -4);
                } else {
                    $years = $detectedYears;
                }
                if (!in_array($refYear, $years)) {
                    $years[] = $refYear;
                    sort($years);
                }
            }

            $yearlyMap = [];
            foreach ($years as $yr) {
                $yearlyMap[(string)$yr] = ['pcs' => 0, 'lot' => 0];
            }

            $minYear = min($years);
            $maxYear = max($years);
            $wY = [
                "{$itemCol} = :itemId",
                "dds.summary_date >= :minY_d AND dds.summary_date <= :maxY_d"
            ];
            $pY = [
                ':itemId' => $itemId,
                ':minY_d' => sprintf('%04d-01-01', $minYear),
                ':maxY_d' => sprintf('%04d-12-31', $maxYear)
            ];
            if ($selectedCustomer !== '') {
                $wY[] = "dds.customer = :cust";
                $pY[':cust'] = $selectedCustomer;
            }
            $stY = $pdo->prepare("SELECT 
                                    DATE_FORMAT(dds.summary_date, '%Y') AS y_key,
                                    COALESCE(SUM(dds.qty_ng), 0) AS total_pcs,
                                    COALESCE(SUM(dds.lot_count), 0) AS total_lots
                                  FROM oqc_daily_defect_summary dds
                                  WHERE " . implode(' AND ', $wY) . "
                                  GROUP BY y_key");
            $stY->execute($pY);
            foreach ($stY->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $yk = $r['y_key'];
                if (isset($yearlyMap[$yk])) {
                    $yearlyMap[$yk]['pcs'] = (int)$r['total_pcs'];
                    $yearlyMap[$yk]['lot'] = (int)$r['total_lots'];
                }
            }

            // 1b. HORIZON BULANAN (Jan s/d Bulan Berjalan / 12 Bulan)
            $maxMonth = ($refYear === (int)date('Y')) ? (int)date('n') : 12;
            $mNamesShort = [
                1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
                7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
            ];
            $monthlyMap = [];
            for ($m = 1; $m <= $maxMonth; $m++) {
                $monthlyMap[$m] = ['pcs' => 0, 'lot' => 0];
            }

            $maxMonthLastDay = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $refYear, $maxMonth)));
            $wM = [
                "{$itemCol} = :itemId",
                "dds.summary_date >= :minM_d AND dds.summary_date <= :maxM_d"
            ];
            $pM = [
                ':itemId' => $itemId,
                ':minM_d' => sprintf('%04d-01-01', $refYear),
                ':maxM_d' => $maxMonthLastDay
            ];
            if ($selectedCustomer !== '') {
                $wM[] = "dds.customer = :cust";
                $pM[':cust'] = $selectedCustomer;
            }
            $stM = $pdo->prepare("SELECT 
                                    MONTH(dds.summary_date) AS m_num,
                                    COALESCE(SUM(dds.qty_ng), 0) AS total_pcs,
                                    COALESCE(SUM(dds.lot_count), 0) AS total_lots
                                  FROM oqc_daily_defect_summary dds
                                  WHERE " . implode(' AND ', $wM) . "
                                  GROUP BY m_num");
            $stM->execute($pM);
            foreach ($stM->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $mn = (int)$r['m_num'];
                if (isset($monthlyMap[$mn])) {
                    $monthlyMap[$mn]['pcs'] = (int)$r['total_pcs'];
                    $monthlyMap[$mn]['lot'] = (int)$r['total_lots'];
                }
            }

            // 1c. HORIZON MIKRO: Mingguan (Sunday Anchor) atau Harian
            $monthStart = sprintf('%04d-%02d-01', $refYear, $refMonth);
            $firstDayDt = new DateTime($monthStart);
            $w1Sun = clone $firstDayDt;
            if ($w1Sun->format('w') != 0) {
                $w1Sun->modify('next Sunday');
            }
            if ($w1Sun->format('m') !== $firstDayDt->format('m')) {
                $w1Sun->modify('+7 days');
            }

            $monthEndStr = date('Y-m-t', strtotime($monthStart));
            $microMap = [];

            if ($subResolution === 'daily') {
                $monthEndDt = new DateTime($monthEndStr);
                $curDay = clone $w1Sun;
                while ($curDay <= $monthEndDt) {
                    $dStr = $curDay->format('Y-m-d');
                    $dDayNum = $curDay->format('d');
                    $microMap[$dStr] = [
                        'label'    => $dDayNum . ' ' . $mNamesShort[$refMonth],
                        'category' => 'Harian (' . $mNamesShort[$refMonth] . ')',
                        'pcs'      => 0,
                        'lot'      => 0
                    ];
                    $curDay->modify('+1 day');
                }

                if (!empty($microMap)) {
                    $wD = [
                        "{$itemCol} = :itemId",
                        "dds.summary_date >= :dStart AND dds.summary_date <= :dEnd"
                    ];
                    $pD = [
                        ':itemId' => $itemId,
                        ':dStart' => $w1Sun->format('Y-m-d'),
                        ':dEnd'   => $monthEndStr
                    ];
                    if ($selectedCustomer !== '') {
                        $wD[] = "dds.customer = :cust";
                        $pD[':cust'] = $selectedCustomer;
                    }
                    $stD = $pdo->prepare("SELECT 
                                            dds.summary_date,
                                            COALESCE(SUM(dds.qty_ng), 0) AS total_pcs,
                                            COALESCE(SUM(dds.lot_count), 0) AS total_lots
                                          FROM oqc_daily_defect_summary dds
                                          WHERE " . implode(' AND ', $wD) . "
                                          GROUP BY dds.summary_date");
                    $stD->execute($pD);
                    foreach ($stD->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $sDate = $r['summary_date'];
                        if (isset($microMap[$sDate])) {
                            $microMap[$sDate]['pcs'] = (int)$r['total_pcs'];
                            $microMap[$sDate]['lot'] = (int)$r['total_lots'];
                        }
                    }
                }
            } else {
                // Resolusi Mingguan (W1 - W4)
                $monthEndDt = new DateTime($monthEndStr);
                $curSun = clone $w1Sun;
                $wIdx = 1;
                $weeklyBuckets = [];

                while ($curSun <= $monthEndDt) {
                    $wKey = 'W' . $wIdx;
                    $wStart = $curSun->format('Y-m-d');
                    $wEndDt = clone $curSun;
                    $wEndDt->modify('+6 days');
                    if ($wEndDt > $monthEndDt) {
                        $wEndDt = clone $monthEndDt;
                    }
                    $wEnd = $wEndDt->format('Y-m-d');

                    $wLabel = $wKey . ' (' . $curSun->format('d') . '-' . $wEndDt->format('d') . ' ' . $curSun->format('M') . ')';

                    $weeklyBuckets[$wKey] = [
                        'label'      => $wLabel,
                        'category'   => 'Mingguan (' . $mNamesShort[$refMonth] . ')',
                        'pcs'        => 0,
                        'lot'        => 0,
                        'start_date' => $wStart,
                        'end_date'   => $wEnd
                    ];

                    $curSun->modify('+7 days');
                    $wIdx++;
                }

                if (!empty($weeklyBuckets)) {
                    $lastBucketKey = 'W' . ($wIdx - 1);
                    $wEffStart = $weeklyBuckets['W1']['start_date'];
                    $wEffEnd   = $weeklyBuckets[$lastBucketKey]['end_date'];

                    $wW = [
                        "{$itemCol} = :itemId",
                        "dds.summary_date >= :ws AND dds.summary_date <= :we"
                    ];
                    $pW = [
                        ':itemId' => $itemId,
                        ':ws'     => $wEffStart,
                        ':we'     => $wEffEnd
                    ];
                    if ($selectedCustomer !== '') {
                        $wW[] = "dds.customer = :cust";
                        $pW[':cust'] = $selectedCustomer;
                    }
                    $stW = $pdo->prepare("SELECT 
                                            dds.summary_date,
                                            COALESCE(SUM(dds.qty_ng), 0) AS total_pcs,
                                            COALESCE(SUM(dds.lot_count), 0) AS total_lots
                                          FROM oqc_daily_defect_summary dds
                                          WHERE " . implode(' AND ', $wW) . "
                                          GROUP BY dds.summary_date");
                    $stW->execute($pW);
                    foreach ($stW->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $sDate = $r['summary_date'];
                        $pcs = (int)$r['total_pcs'];
                        $lot = (int)$r['total_lots'];
                        foreach ($weeklyBuckets as $wbKey => &$wb) {
                            if ($sDate >= $wb['start_date'] && $sDate <= $wb['end_date']) {
                                $wb['pcs'] += $pcs;
                                $wb['lot'] += $lot;
                                break;
                            }
                        }
                        unset($wb);
                    }
                    $microMap = $weeklyBuckets;
                }
            }

            // Rakit kembali $trendMap Multi-Horizon komposit untuk Defect
            $trendMap = [];
            foreach ($yearlyMap as $yrKey => $yv) {
                $trendMap["Y_{$yrKey}"] = [
                    'category' => 'Tahunan',
                    'label'    => "Tahun {$yrKey}",
                    'pcs'      => $yv['pcs'],
                    'lot'      => $yv['lot']
                ];
            }
            foreach ($monthlyMap as $mNum => $mv) {
                $trendMap["M_{$mNum}"] = [
                    'category' => 'Bulanan (' . $refYear . ')',
                    'label'    => "Bulan {$mNamesShort[$mNum]}",
                    'pcs'      => $mv['pcs'],
                    'lot'      => $mv['lot']
                ];
            }
            foreach ($microMap as $microKey => $mcv) {
                $trendMap["MIC_{$microKey}"] = [
                    'category' => $mcv['category'],
                    'label'    => $mcv['label'],
                    'pcs'      => $mcv['pcs'],
                    'lot'      => $mcv['lot']
                ];
            }

        } catch (Exception $e) {}

        // 2. Distribution Data: Top Parts for this Defect
        try {
            $wPartSum = ["dds.summary_date >= :sd AND dds.summary_date <= :ed"];
            $pPartSum = [':sd' => $startDate, ':ed' => $endDate];
            if ($defectId > 0) {
                $wPartSum[] = "dds.defect_type_id = :defId";
                $pPartSum[':defId'] = $defectId;
            }
            if ($selectedCustomer !== '') {
                $wPartSum[] = "dds.customer = :cust";
                $pPartSum[':cust'] = $selectedCustomer;
            }
            $wPartSumSql = implode(' AND ', $wPartSum);
            $stPSum = $pdo->prepare("SELECT mp.id, mp.part_code, mp.part_name,
                                            COALESCE(SUM(dds.qty_ng), 0) as total_pcs,
                                            COALESCE(SUM(dds.lot_count), 0) as total_lots
                                     FROM oqc_daily_defect_summary dds
                                     INNER JOIN master_parts mp ON dds.part_id = mp.id
                                     WHERE {$wPartSumSql}
                                     GROUP BY mp.id, mp.part_code, mp.part_name");
            $stPSum->execute($pPartSum);
            $rowsPSum = $stPSum->fetchAll(PDO::FETCH_ASSOC);

            $rowsPAct = [];
            if ($endDate >= date('Y-m-d')) {
                $wPAct = [
                    "s.status = 'in_progress'",
                    "(ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)",
                    "s.started_at >= :sd_act AND s.started_at < :ed_act_next"
                ];
                $pPAct = [
                    ':sd_act'      => $startDate . ' 00:00:00',
                    ':ed_act_next' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'
                ];
                if ($defectId > 0) {
                    $wPAct[] = "ngr.defect_type_id = :defId";
                    $pPAct[':defId'] = $defectId;
                }
                if ($selectedCustomer !== '') {
                    $wPAct[] = "COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') = :cust";
                    $pPAct[':cust'] = $selectedCustomer;
                }
                $wPActSql = implode(' AND ', $wPAct);
                $stPAct = $pdo->prepare("SELECT mp.id, mp.part_code, mp.part_name,
                                                COALESCE(SUM(ngr.qty_ng), 0) as total_pcs,
                                                COUNT(DISTINCT s.id) as total_lots
                                         FROM inspection_ng_records ngr
                                         INNER JOIN inspection_sessions s ON ngr.inspection_session_id = s.id
                                         INNER JOIN master_parts mp ON ngr.part_id = mp.id
                                         LEFT JOIN kanban_items ki ON s.kanban_item_id = ki.id
                                         WHERE {$wPActSql}
                                         GROUP BY mp.id, mp.part_code, mp.part_name");
                $stPAct->execute($pPAct);
                $rowsPAct = $stPAct->fetchAll(PDO::FETCH_ASSOC);
            }

            $mergedParts = [];
            foreach ($rowsPSum as $r) {
                $id = (int)$r['id'];
                $mergedParts[$id] = [
                    'item_id'    => $id,
                    'item_code'  => $r['part_code'],
                    'item_name'  => $r['part_name'],
                    'full_label' => $r['part_code'] . ' - ' . $r['part_name'],
                    'total_pcs'  => (int)$r['total_pcs'],
                    'total_lots' => (int)$r['total_lots']
                ];
            }
            foreach ($rowsPAct as $r) {
                $id = (int)$r['id'];
                if (!isset($mergedParts[$id])) {
                    $mergedParts[$id] = [
                        'item_id'    => $id,
                        'item_code'  => $r['part_code'],
                        'item_name'  => $r['part_name'],
                        'full_label' => $r['part_code'] . ' - ' . $r['part_name'],
                        'total_pcs'  => 0,
                        'total_lots' => 0
                    ];
                }
                $mergedParts[$id]['total_pcs']  += (int)$r['total_pcs'];
                $mergedParts[$id]['total_lots'] += (int)$r['total_lots'];
            }

            $rawDist = array_values($mergedParts);
            usort($rawDist, function($a, $b) use ($selectedUnit) {
                $vA = ($selectedUnit === 'lot') ? $a['total_lots'] : $a['total_pcs'];
                $vB = ($selectedUnit === 'lot') ? $b['total_lots'] : $b['total_pcs'];
                if ($vA === $vB) return $b['total_pcs'] <=> $a['total_pcs'];
                return $vB <=> $vA;
            });

            $topLimit = is_numeric($selectedTopRank) ? (int)$selectedTopRank : 0;
            if ($topLimit > 0 && count($rawDist) > $topLimit) {
                $distributionList = array_slice($rawDist, 0, $topLimit);
                $otherRows = array_slice($rawDist, $topLimit);
                $oPcs  = array_sum(array_column($otherRows, 'total_pcs'));
                $oLots = array_sum(array_column($otherRows, 'total_lots'));
                $distributionList[] = [
                    'item_id'    => null,
                    'item_code'  => 'LAINNYA',
                    'item_name'  => 'Part Lainnya (' . count($otherRows) . ' part)',
                    'full_label' => 'Part Lainnya (' . count($otherRows) . ' part)',
                    'total_pcs'  => $oPcs,
                    'total_lots' => $oLots,
                    'is_other'   => true
                ];
            } else {
                $distributionList = $rawDist;
            }
        } catch (Exception $e) {}

        // 3. Audit Trail Records for Defect
        try {
            $wAud = [
                "(ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)",
                "s.started_at >= :sd AND s.started_at < :ed_next"
            ];
            $pAud = [
                ':sd'      => $startDate . ' 00:00:00',
                ':ed_next' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'
            ];
            if ($defectId > 0) {
                $wAud[] = "ngr.defect_type_id = :defId";
                $pAud[':defId'] = $defectId;
            }
            if ($selectedCustomer !== '') {
                $wAud[] = "COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') = :cust";
                $pAud[':cust'] = $selectedCustomer;
            }
            $wAudSql = implode(' AND ', $wAud);

            $stAud = $pdo->prepare("SELECT 
                                        ngr.id AS ng_id,
                                        s.started_at,
                                        s.inspection_type,
                                        u.name AS inspector_name,
                                        COALESCE(ki.kanban_no, '-') AS kanban_no,
                                        COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') AS customer_name,
                                        COALESCE(mp.part_code, '-') AS part_code,
                                        COALESCE(mp.part_name, 'Unknown Part') AS part_name,
                                        COALESCE(NULLIF(TRIM(mm.name), ''), NULLIF(TRIM(mp.model), ''), 'General Model') AS model_name,
                                        dt.name AS defect_name,
                                        COALESCE(ngr.qty_ng, 1) AS qty_ng,
                                        COALESCE(NULLIF(ngr.lot_number, ''), NULLIF(isl.lot_number, ''), '-') AS lot_number,
                                        COALESCE(NULLIF(ngr.ref_number, ''), NULLIF(isl.ref_number, ''), '-') AS ref_number
                                    FROM inspection_ng_records ngr
                                    INNER JOIN inspection_sessions s ON ngr.inspection_session_id = s.id
                                    INNER JOIN defect_types dt ON ngr.defect_type_id = dt.id
                                    LEFT JOIN inspection_session_lots isl ON ngr.session_lot_id = isl.id
                                    LEFT JOIN master_parts mp ON ngr.part_id = mp.id
                                    LEFT JOIN master_models mm ON mp.model_id = mm.id
                                    LEFT JOIN kanban_items ki ON s.kanban_item_id = ki.id
                                    LEFT JOIN users u ON s.inspector_id = u.id
                                    WHERE {$wAudSql}
                                    ORDER BY s.started_at DESC, ngr.id DESC
                                    LIMIT 3000");
            $stAud->execute($pAud);
            $auditRecords = $stAud->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
    } else {
        // ── Skenario 2: Part Overview Mode (Focus on Part) ───────────────────
        // 1. Timeline Data for Part (Multi-Horizon Support: Tahunan -> Bulanan -> Mingguan/Harian)
        try {
            $isPartMode = true;
            $itemId     = $partId;
            $itemCol    = "dds.part_id";
            $refYear    = $selectedYear ?: (int)date('Y');
            $refMonth   = $selectedMonth ?: (int)date('n');

            // 1a. HORIZON TAHUNAN (Dinamis: Tahun dengan riwayat data s/d refYear)
            $wYDetect = [
                "{$itemCol} = :itemId",
                "YEAR(dds.summary_date) <= :refYear"
            ];
            $pYDetect = [
                ':itemId'  => $itemId,
                ':refYear' => $refYear
            ];
            if ($selectedCustomer !== '') {
                $wYDetect[] = "dds.customer = :cust";
                $pYDetect[':cust'] = $selectedCustomer;
            }
            $stDetectY = $pdo->prepare("SELECT DISTINCT YEAR(dds.summary_date) AS yr
                                        FROM oqc_daily_defect_summary dds
                                        WHERE " . implode(' AND ', $wYDetect) . "
                                        ORDER BY yr ASC");
            $stDetectY->execute($pYDetect);
            $detectedYears = $stDetectY->fetchAll(PDO::FETCH_COLUMN);

            if (empty($detectedYears)) {
                $years = [$refYear - 2, $refYear - 1, $refYear];
            } else {
                if (count($detectedYears) > 4) {
                    $years = array_slice($detectedYears, -4);
                } else {
                    $years = $detectedYears;
                }
                if (!in_array($refYear, $years)) {
                    $years[] = $refYear;
                    sort($years);
                }
            }

            $yearlyMap = [];
            foreach ($years as $yr) {
                $yearlyMap[(string)$yr] = ['pcs' => 0, 'lot' => 0];
            }

            $minYear = min($years);
            $maxYear = max($years);
            $wY = [
                "{$itemCol} = :itemId",
                "dds.summary_date >= :minY_d AND dds.summary_date <= :maxY_d"
            ];
            $pY = [
                ':itemId' => $itemId,
                ':minY_d' => sprintf('%04d-01-01', $minYear),
                ':maxY_d' => sprintf('%04d-12-31', $maxYear)
            ];
            if ($selectedCustomer !== '') {
                $wY[] = "dds.customer = :cust";
                $pY[':cust'] = $selectedCustomer;
            }
            $stY = $pdo->prepare("SELECT 
                                    DATE_FORMAT(dds.summary_date, '%Y') AS y_key,
                                    COALESCE(SUM(dds.qty_ng), 0) AS total_pcs,
                                    COALESCE(SUM(dds.lot_count), 0) AS total_lots
                                  FROM oqc_daily_defect_summary dds
                                  WHERE " . implode(' AND ', $wY) . "
                                  GROUP BY y_key");
            $stY->execute($pY);
            foreach ($stY->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $yk = $r['y_key'];
                if (isset($yearlyMap[$yk])) {
                    $yearlyMap[$yk]['pcs'] = (int)$r['total_pcs'];
                    $yearlyMap[$yk]['lot'] = (int)$r['total_lots'];
                }
            }

            // 1b. HORIZON BULANAN (Jan s/d Bulan Berjalan / 12 Bulan)
            $maxMonth = ($refYear === (int)date('Y')) ? (int)date('n') : 12;
            $mNamesShort = [
                1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
                7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
            ];
            $monthlyMap = [];
            for ($m = 1; $m <= $maxMonth; $m++) {
                $monthlyMap[$m] = ['pcs' => 0, 'lot' => 0];
            }

            $maxMonthLastDay = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $refYear, $maxMonth)));
            $wM = [
                "{$itemCol} = :itemId",
                "dds.summary_date >= :minM_d AND dds.summary_date <= :maxM_d"
            ];
            $pM = [
                ':itemId' => $itemId,
                ':minM_d' => sprintf('%04d-01-01', $refYear),
                ':maxM_d' => $maxMonthLastDay
            ];
            if ($selectedCustomer !== '') {
                $wM[] = "dds.customer = :cust";
                $pM[':cust'] = $selectedCustomer;
            }
            $stM = $pdo->prepare("SELECT 
                                    MONTH(dds.summary_date) AS m_num,
                                    COALESCE(SUM(dds.qty_ng), 0) AS total_pcs,
                                    COALESCE(SUM(dds.lot_count), 0) AS total_lots
                                  FROM oqc_daily_defect_summary dds
                                  WHERE " . implode(' AND ', $wM) . "
                                  GROUP BY m_num");
            $stM->execute($pM);
            foreach ($stM->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $mn = (int)$r['m_num'];
                if (isset($monthlyMap[$mn])) {
                    $monthlyMap[$mn]['pcs'] = (int)$r['total_pcs'];
                    $monthlyMap[$mn]['lot'] = (int)$r['total_lots'];
                }
            }

            // 1c. HORIZON MIKRO (Sunday Anchor: Mingguan atau Harian Siklus Kerja)
            $monthStart  = sprintf('%04d-%02d-01', $refYear, $refMonth);
            $firstDayDt  = new DateTime($monthStart);
            $firstDayDow = (int)$firstDayDt->format('N'); // 1 = Mon ... 7 = Sun

            $w1Sun = clone $firstDayDt;
            if ($firstDayDow !== 7) {
                $w1Sun->modify('-' . $firstDayDow . ' days');
            }
            if ($w1Sun->format('m') !== $firstDayDt->format('m')) {
                $w1Sun->modify('+7 days');
            }

            $monthEndStr = date('Y-m-t', strtotime($monthStart));
            $microMap = [];

            if ($subResolution === 'daily') {
                $monthEndDt = new DateTime($monthEndStr);
                $curDay = clone $w1Sun;
                while ($curDay <= $monthEndDt) {
                    $dStr = $curDay->format('Y-m-d');
                    $dDayNum = $curDay->format('d');
                    $microMap[$dStr] = [
                        'label'    => $dDayNum . ' ' . $mNamesShort[$refMonth],
                        'category' => 'Harian (' . $mNamesShort[$refMonth] . ')',
                        'pcs'      => 0,
                        'lot'      => 0
                    ];
                    $curDay->modify('+1 day');
                }

                if (!empty($microMap)) {
                    $wD = [
                        "{$itemCol} = :itemId",
                        "dds.summary_date >= :dStart AND dds.summary_date <= :dEnd"
                    ];
                    $pD = [
                        ':itemId' => $itemId,
                        ':dStart' => $w1Sun->format('Y-m-d'),
                        ':dEnd'   => $monthEndStr
                    ];
                    if ($selectedCustomer !== '') {
                        $wD[] = "dds.customer = :cust";
                        $pD[':cust'] = $selectedCustomer;
                    }
                    $stD = $pdo->prepare("SELECT 
                                            dds.summary_date,
                                            COALESCE(SUM(dds.qty_ng), 0) AS total_pcs,
                                            COALESCE(SUM(dds.lot_count), 0) AS total_lots
                                          FROM oqc_daily_defect_summary dds
                                          WHERE " . implode(' AND ', $wD) . "
                                          GROUP BY dds.summary_date");
                    $stD->execute($pD);
                    foreach ($stD->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $sDate = $r['summary_date'];
                        if (isset($microMap[$sDate])) {
                            $microMap[$sDate]['pcs'] = (int)$r['total_pcs'];
                            $microMap[$sDate]['lot'] = (int)$r['total_lots'];
                        }
                    }
                }
            } else {
                // Mingguan W1..W5
                $weeklyBuckets = [];
                $curSun = clone $w1Sun;
                $wIdx = 1;
                $targetMonth = $firstDayDt->format('m');

                while ($curSun->format('m') === $targetMonth && $wIdx <= 6) {
                    $curSat = clone $curSun;
                    $curSat->modify('+6 days');

                    $sStr = $curSun->format('Y-m-d');
                    $eStr = $curSat->format('Y-m-d');
                    $sLabel = $curSun->format('d');
                    $eLabel = $curSat->format('d');
                    $sMonth = $curSun->format('M');
                    $eMonth = $curSat->format('M');
                    $lblText = ($sMonth === $eMonth) ? "W{$wIdx} ({$sLabel}-{$eLabel} {$sMonth})" : "W{$wIdx} ({$sLabel} {$sMonth}-{$eLabel} {$eMonth})";

                    $weeklyBuckets["W{$wIdx}"] = [
                        'label'      => $lblText,
                        'category'   => 'Mingguan (' . $mNamesShort[$refMonth] . ')',
                        'start_date' => $sStr,
                        'end_date'   => $eStr,
                        'pcs'        => 0,
                        'lot'        => 0
                    ];
                    $curSun->modify('+7 days');
                    $wIdx++;
                }

                if (!empty($weeklyBuckets)) {
                    $lastBucketKey = 'W' . ($wIdx - 1);
                    $wEffStart = $weeklyBuckets['W1']['start_date'];
                    $wEffEnd   = $weeklyBuckets[$lastBucketKey]['end_date'];

                    $wW = [
                        "{$itemCol} = :itemId",
                        "dds.summary_date >= :ws AND dds.summary_date <= :we"
                    ];
                    $pW = [
                        ':itemId' => $itemId,
                        ':ws'     => $wEffStart,
                        ':we'     => $wEffEnd
                    ];
                    if ($selectedCustomer !== '') {
                        $wW[] = "dds.customer = :cust";
                        $pW[':cust'] = $selectedCustomer;
                    }
                    $stW = $pdo->prepare("SELECT 
                                            dds.summary_date,
                                            COALESCE(SUM(dds.qty_ng), 0) AS total_pcs,
                                            COALESCE(SUM(dds.lot_count), 0) AS total_lots
                                          FROM oqc_daily_defect_summary dds
                                          WHERE " . implode(' AND ', $wW) . "
                                          GROUP BY dds.summary_date");
                    $stW->execute($pW);
                    foreach ($stW->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $sDate = $r['summary_date'];
                        $pcs = (int)$r['total_pcs'];
                        $lot = (int)$r['total_lots'];
                        foreach ($weeklyBuckets as $wbKey => &$wb) {
                            if ($sDate >= $wb['start_date'] && $sDate <= $wb['end_date']) {
                                $wb['pcs'] += $pcs;
                                $wb['lot'] += $lot;
                                break;
                            }
                        }
                        unset($wb);
                    }
                    $microMap = $weeklyBuckets;
                }
            }

            // Rakit kembali $trendMap Multi-Horizon komposit
            $trendMap = [];
            foreach ($yearlyMap as $yrKey => $yv) {
                $trendMap["Y_{$yrKey}"] = [
                    'category' => 'Tahunan',
                    'label'    => "Tahun {$yrKey}",
                    'pcs'      => $yv['pcs'],
                    'lot'      => $yv['lot']
                ];
            }
            foreach ($monthlyMap as $mNum => $mv) {
                $trendMap["M_{$mNum}"] = [
                    'category' => 'Bulanan (' . $refYear . ')',
                    'label'    => "Bulan {$mNamesShort[$mNum]}",
                    'pcs'      => $mv['pcs'],
                    'lot'      => $mv['lot']
                ];
            }
            foreach ($microMap as $microKey => $mcv) {
                $trendMap["MIC_{$microKey}"] = [
                    'category' => $mcv['category'],
                    'label'    => $mcv['label'],
                    'pcs'      => $mcv['pcs'],
                    'lot'      => $mcv['lot']
                ];
            }

        } catch (Exception $e) {}

        // 2. Distribution Data: Top Defects for this Part
        try {
            $wDefSum = ["dds.summary_date >= :sd AND dds.summary_date <= :ed"];
            $pDefSum = [':sd' => $startDate, ':ed' => $endDate];
            if ($partId > 0) {
                $wDefSum[] = "dds.part_id = :pId";
                $pDefSum[':pId'] = $partId;
            }
            if ($selectedCustomer !== '') {
                $wDefSum[] = "dds.customer = :cust";
                $pDefSum[':cust'] = $selectedCustomer;
            }
            $wDefSumSql = implode(' AND ', $wDefSum);
            $stDSum = $pdo->prepare("SELECT dt.id, dt.name,
                                            COALESCE(SUM(dds.qty_ng), 0) as total_pcs,
                                            COALESCE(SUM(dds.lot_count), 0) as total_lots
                                     FROM oqc_daily_defect_summary dds
                                     INNER JOIN defect_types dt ON dds.defect_type_id = dt.id
                                     WHERE {$wDefSumSql}
                                     GROUP BY dt.id, dt.name");
            $stDSum->execute($pDefSum);
            $rowsDSum = $stDSum->fetchAll(PDO::FETCH_ASSOC);

            $rowsDAct = [];
            if ($endDate >= date('Y-m-d')) {
                $wDAct = [
                    "s.status = 'in_progress'",
                    "(ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)",
                    "s.started_at >= :sd_act AND s.started_at < :ed_act_next"
                ];
                $pDAct = [
                    ':sd_act'      => $startDate . ' 00:00:00',
                    ':ed_act_next' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'
                ];
                if ($partId > 0) {
                    $wDAct[] = "ngr.part_id = :pId";
                    $pDAct[':pId'] = $partId;
                }
                if ($selectedCustomer !== '') {
                    $wDAct[] = "COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') = :cust";
                    $pDAct[':cust'] = $selectedCustomer;
                }
                $wDActSql = implode(' AND ', $wDAct);
                $stDAct = $pdo->prepare("SELECT dt.id, dt.name,
                                                COALESCE(SUM(ngr.qty_ng), 0) as total_pcs,
                                                COUNT(DISTINCT s.id) as total_lots
                                         FROM inspection_ng_records ngr
                                         INNER JOIN inspection_sessions s ON ngr.inspection_session_id = s.id
                                         INNER JOIN defect_types dt ON ngr.defect_type_id = dt.id
                                         LEFT JOIN kanban_items ki ON s.kanban_item_id = ki.id
                                         WHERE {$wDActSql}
                                         GROUP BY dt.id, dt.name");
                $stDAct->execute($pDAct);
                $rowsDAct = $stDAct->fetchAll(PDO::FETCH_ASSOC);
            }

            $mergedDefects = [];
            foreach ($rowsDSum as $r) {
                $id = (int)$r['id'];
                $mergedDefects[$id] = [
                    'item_id'    => $id,
                    'item_code'  => $r['name'],
                    'item_name'  => $r['name'],
                    'full_label' => $r['name'],
                    'total_pcs'  => (int)$r['total_pcs'],
                    'total_lots' => (int)$r['total_lots']
                ];
            }
            foreach ($rowsDAct as $r) {
                $id = (int)$r['id'];
                if (!isset($mergedDefects[$id])) {
                    $mergedDefects[$id] = [
                        'item_id'    => $id,
                        'item_code'  => $r['name'],
                        'item_name'  => $r['name'],
                        'full_label' => $r['name'],
                        'total_pcs'  => 0,
                        'total_lots' => 0
                    ];
                }
                $mergedDefects[$id]['total_pcs']  += (int)$r['total_pcs'];
                $mergedDefects[$id]['total_lots'] += (int)$r['total_lots'];
            }

            $rawDist = array_values($mergedDefects);
            usort($rawDist, function($a, $b) use ($selectedUnit) {
                $vA = ($selectedUnit === 'lot') ? $a['total_lots'] : $a['total_pcs'];
                $vB = ($selectedUnit === 'lot') ? $b['total_lots'] : $b['total_pcs'];
                if ($vA === $vB) return $b['total_pcs'] <=> $a['total_pcs'];
                return $vB <=> $vA;
            });

            $topLimit = is_numeric($selectedTopRank) ? (int)$selectedTopRank : 0;
            if ($topLimit > 0 && count($rawDist) > $topLimit) {
                $distributionList = array_slice($rawDist, 0, $topLimit);
                $otherRows = array_slice($rawDist, $topLimit);
                $oPcs  = array_sum(array_column($otherRows, 'total_pcs'));
                $oLots = array_sum(array_column($otherRows, 'total_lots'));
                $distributionList[] = [
                    'item_id'    => null,
                    'item_code'  => 'LAINNYA',
                    'item_name'  => 'Defect Lainnya (' . count($otherRows) . ' jenis)',
                    'full_label' => 'Defect Lainnya (' . count($otherRows) . ' jenis)',
                    'total_pcs'  => $oPcs,
                    'total_lots' => $oLots,
                    'is_other'   => true
                ];
            } else {
                $distributionList = $rawDist;
            }
        } catch (Exception $e) {}

        // 3. Audit Trail Records for Part
        try {
            $wAud = [
                "(ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)",
                "s.started_at >= :sd AND s.started_at < :ed_next"
            ];
            $pAud = [
                ':sd'      => $startDate . ' 00:00:00',
                ':ed_next' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'
            ];
            if ($partId > 0) {
                $wAud[] = "ngr.part_id = :pId";
                $pAud[':pId'] = $partId;
            }
            if ($selectedCustomer !== '') {
                $wAud[] = "COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') = :cust";
                $pAud[':cust'] = $selectedCustomer;
            }
            $wAudSql = implode(' AND ', $wAud);

            $stAud = $pdo->prepare("SELECT 
                                        ngr.id AS ng_id,
                                        s.started_at,
                                        s.inspection_type,
                                        u.name AS inspector_name,
                                        COALESCE(ki.kanban_no, '-') AS kanban_no,
                                        COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') AS customer_name,
                                        COALESCE(mp.part_code, '-') AS part_code,
                                        COALESCE(mp.part_name, 'Unknown Part') AS part_name,
                                        COALESCE(NULLIF(TRIM(mm.name), ''), NULLIF(TRIM(mp.model), ''), 'General Model') AS model_name,
                                        dt.name AS defect_name,
                                        COALESCE(ngr.qty_ng, 1) AS qty_ng,
                                        COALESCE(NULLIF(ngr.lot_number, ''), NULLIF(isl.lot_number, ''), '-') AS lot_number,
                                        COALESCE(NULLIF(ngr.ref_number, ''), NULLIF(isl.ref_number, ''), '-') AS ref_number
                                    FROM inspection_ng_records ngr
                                    INNER JOIN inspection_sessions s ON ngr.inspection_session_id = s.id
                                    INNER JOIN defect_types dt ON ngr.defect_type_id = dt.id
                                    LEFT JOIN inspection_session_lots isl ON ngr.session_lot_id = isl.id
                                    LEFT JOIN master_parts mp ON ngr.part_id = mp.id
                                    LEFT JOIN master_models mm ON mp.model_id = mm.id
                                    LEFT JOIN kanban_items ki ON s.kanban_item_id = ki.id
                                    LEFT JOIN users u ON s.inspector_id = u.id
                                    WHERE {$wAudSql}
                                    ORDER BY s.started_at DESC, ngr.id DESC
                                    LIMIT 3000");
            $stAud->execute($pAud);
            $auditRecords = $stAud->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
    }
}

// ── 5. Compute Executive KPIs (Matching Web Cards) ───────────────────────────
$totalPcsAcc  = 0;
$totalLotsAcc = 0;
$peakVal      = 0;
$peakLabel    = 'Nihil / 0 Cacat';
$activeIntervalCount = count($trendMap);

// Hitung total PCS dan LOT aktual dari rentang filter aktif (bukan menjumlahkan multi-horizon yang redundan)
foreach ($distributionList as $di) {
    $totalPcsAcc  += (int)$di['total_pcs'];
    $totalLotsAcc += (int)$di['total_lots'];
}

// Cari interval kasus tertinggi (peak period) dari horizon tren
foreach ($trendMap as $t) {
    $val = ($selectedUnit === 'lot') ? $t['lot'] : $t['pcs'];
    if ($val > $peakVal) {
        $peakVal   = $val;
        $peakLabel = $t['label'] . ' (' . number_format($val) . ' ' . strtoupper($selectedUnit) . ')';
    }
}
$metricAcc = ($selectedUnit === 'lot') ? $totalLotsAcc : $totalPcsAcc;
$avgPerInterval = ($activeIntervalCount > 0 && $metricAcc > 0) ? round($metricAcc / $activeIntervalCount, 1) : 0;

// Grand Metric for Distribution Percentages
$grandDistMetric = $metricAcc;

// ── 6. Setup Spreadsheet & Formatting Functions ──────────────────────────────
$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator("OQC Quality Control System")
    ->setTitle($mode === 'overview' ? "Laporan Analisis & Tren Defect" : "Laporan Analisis & Tren Part")
    ->setSubject("OQC Defect Analysis Report")
    ->setCompany("PT. Surya Technology Industri");

function applyStyle($ws, $range, array $s) {
    $ws->getStyle($range)->applyFromArray($s);
}

function hdrStyle($bg = '0F172A', $fg = 'FFFFFF') {
    return [
        'font'      => ['bold' => true, 'color' => ['argb' => 'FF' . $fg], 'size' => 10],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . $bg]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF94A3B8']]],
    ];
}

function dataStyle($bg = 'FFFFFF', $fg = '1E293B', $bold = false, $align = Alignment::HORIZONTAL_LEFT) {
    return [
        'font'      => ['bold' => $bold, 'color' => ['argb' => 'FF' . $fg], 'size' => 10],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . $bg]],
        'alignment' => ['horizontal' => $align, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFE2E8F0']]],
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// SHEET 1: 🎯 DASBOR VISUAL EKSEKUTIF (1:1 Web Overview)
// ═══════════════════════════════════════════════════════════════════════════
$ws1 = $spreadsheet->getActiveSheet();
$sheet1Title = ($mode === 'overview') ? "🎯 Analisis & Tren Defect" : "🎯 Analisis & Tren Part";
$ws1->setTitle($sheet1Title);

// Column Widths
$ws1->getColumnDimension('A')->setWidth(6);
$ws1->getColumnDimension('B')->setWidth(20);
$ws1->getColumnDimension('C')->setWidth(15);
$ws1->getColumnDimension('D')->setWidth(15);
$ws1->getColumnDimension('E')->setWidth(17);
$ws1->getColumnDimension('F')->setWidth(4);  // Divider gap

$ws1->getColumnDimension('G')->setWidth(6);
$ws1->getColumnDimension('H')->setWidth(22);
$ws1->getColumnDimension('I')->setWidth(24);
$ws1->getColumnDimension('J')->setWidth(15);
$ws1->getColumnDimension('K')->setWidth(15);
$ws1->getColumnDimension('L')->setWidth(15);
$ws1->getColumnDimension('M')->setWidth(15);

// Header Banner
$ws1->getRowDimension(1)->setRowHeight(36);
$ws1->getRowDimension(2)->setRowHeight(24);
$ws1->mergeCells('A1:M1');
$ws1->setCellValue('A1', 'PT. SURYA TECHNOLOGY INDUSTRI — OUTGOING QUALITY CONTROL DIVISION');
applyStyle($ws1, 'A1', [
    'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FF0F172A']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFDBEAFE']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF93C5FD']]]
]);

$ws1->mergeCells('A2:M2');
if ($mode === 'overview') {
    $subHeader = "LAPORAN ANALISIS & TREN DEFECT  |  DEFECT: " . strtoupper($defectName) . "  |  PERIODE: " . strtoupper($filterTitleDesc) . "  |  CUSTOMER: " . strtoupper($selectedCustomer ?: 'SEMUA') . "  |  SATUAN: " . strtoupper($selectedUnit);
} else {
    $subHeader = "LAPORAN ANALISIS & TREN PART  |  PART: " . strtoupper($partCode . ' - ' . $partName) . "  |  MODEL: " . strtoupper($modelName) . "  |  PERIODE: " . strtoupper($filterTitleDesc) . "  |  CUSTOMER: " . strtoupper($selectedCustomer ?: 'SEMUA') . "  |  SATUAN: " . strtoupper($selectedUnit);
}
$ws1->setCellValue('A2', $subHeader);
applyStyle($ws1, 'A2', [
    'font'      => ['bold' => true, 'size' => 9, 'color' => ['argb' => 'FF1E40AF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEFF6FF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]
]);

// ── 3 Executive KPI Cards (Rows 4 to 6) ──────────────────────────────────────
$ws1->getRowDimension(4)->setRowHeight(18);
$ws1->getRowDimension(5)->setRowHeight(32);
$ws1->getRowDimension(6)->setRowHeight(18);

// KPI Card 1: Total Temuan Defect / Total Cacat Part
$kpi1Title = ($mode === 'overview') ? 'TOTAL TEMUAN DEFECT' : 'TOTAL CACAT PART';
$ws1->mergeCells('A4:D4');
$ws1->setCellValue('A4', $kpi1Title);
applyStyle($ws1, 'A4', ['font' => ['bold' => true, 'size' => 9, 'color' => ['argb' => 'FFBE123C']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFF1F2']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);

$ws1->mergeCells('A5:D5');
$ws1->setCellValue('A5', number_format($metricAcc));
applyStyle($ws1, 'A5', ['font' => ['bold' => true, 'size' => 20, 'color' => ['argb' => 'FFBE123C']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFF1F2']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]]);

$ws1->mergeCells('A6:D6');
$ws1->setCellValue('A6', strtoupper($selectedUnit) . ' akumulasi (' . number_format($totalLotsAcc) . ' insiden lot)');
applyStyle($ws1, 'A6', ['font' => ['italic' => true, 'size' => 8, 'color' => ['argb' => 'FF9F1239']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFF1F2']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);
applyStyle($ws1, 'A4:D6', ['borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FFFECDD3']]]]);

if ($mode === 'overview') {
    // ═══════════════════════════════════════════════════════════════════════
    // MODE OVERVIEW (DEFECT FOCUS: 100% Stacked Full Width Multi-Horizon)
    // ═══════════════════════════════════════════════════════════════════════

    // KPI Card 2: Batas Target Defect
    $targetDesc = ($defectThreshold !== null) ? (number_format($defectThreshold) . ' ' . strtoupper($selectedUnit)) : 'Belum Diset';
    $isDefectOver = ($defectThreshold !== null && $metricAcc > $defectThreshold);
    $targetStatus = ($defectThreshold === null) ? 'Belum Diatur' : ($isDefectOver ? '⚠️ Over Target' : '✓ Aman');

    $ws1->mergeCells('E4:H4');
    $ws1->setCellValue('E4', 'BATAS TARGET DEFECT');
    applyStyle($ws1, 'E4', ['font' => ['bold' => true, 'size' => 9, 'color' => ['argb' => $isDefectOver ? 'FFB91C1C' : 'FF047857']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $isDefectOver ? 'FFFEF2F2' : 'FFECFDF5']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);

    $ws1->mergeCells('E5:H5');
    $ws1->setCellValue('E5', $targetDesc);
    applyStyle($ws1, 'E5', ['font' => ['bold' => true, 'size' => 16, 'color' => ['argb' => $isDefectOver ? 'FFB91C1C' : 'FF047857']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $isDefectOver ? 'FFFEF2F2' : 'FFECFDF5']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]]);

    $ws1->mergeCells('E6:H6');
    $ws1->setCellValue('E6', 'Status Kepatuhan: ' . $targetStatus);
    applyStyle($ws1, 'E6', ['font' => ['bold' => true, 'italic' => true, 'size' => 8, 'color' => ['argb' => $isDefectOver ? 'FF991B1B' : 'FF065F46']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $isDefectOver ? 'FFFEF2F2' : 'FFECFDF5']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);
    applyStyle($ws1, 'E4:H6', ['borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => $isDefectOver ? 'FFFECACA' : 'FFA7F3D0']]]]);

    // KPI Card 3: Puncak Kasus (Peak)
    $ws1->mergeCells('I4:M4');
    $ws1->setCellValue('I4', 'PUNCAK KASUS (PEAK PERIOD)');
    applyStyle($ws1, 'I4', ['font' => ['bold' => true, 'size' => 9, 'color' => ['argb' => 'FF4338CA']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEEF2FF']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);

    $ws1->mergeCells('I5:M5');
    $ws1->setCellValue('I5', $peakLabel);
    applyStyle($ws1, 'I5', ['font' => ['bold' => true, 'size' => 12, 'color' => ['argb' => 'FF4338CA']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEEF2FF']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]]);

    $ws1->mergeCells('I6:M6');
    $ws1->setCellValue('I6', 'Interval kasus tertinggi pada defect ini');
    applyStyle($ws1, 'I6', ['font' => ['italic' => true, 'size' => 8, 'color' => ['argb' => 'FF3730A3']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEEF2FF']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);
    applyStyle($ws1, 'I4:M6', ['borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FFE0E7FF']]]]);

    // ── BAGIAN 1 (ATAS): TREN FLUKTUASI MULTI-HORIZON 100% WIDTH ─────────
    $ws1->mergeCells('A8:M8');
    $ws1->setCellValue('A8', '1. TREN & KOMPARASI KEJADIAN DEFECT (MULTI-HORIZON: TAHUNAN → BULANAN → MINGGUAN/HARIAN)');
    applyStyle($ws1, 'A8', [
        'font'      => ['bold' => true, 'size' => 11, 'color' => ['argb' => 'FFFFFFFF']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1E293B']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1]
    ]);

    $ws1->setCellValue('A9', 'NO');
    $ws1->mergeCells('B9:G9');
    $ws1->setCellValue('B9', 'PERIODE / WAKTU');
    $ws1->mergeCells('H9:I9');
    $ws1->setCellValue('H9', 'TEMUAN (PCS)');
    $ws1->mergeCells('J9:K9');
    $ws1->setCellValue('J9', 'KASUS (LOT)');
    $ws1->mergeCells('L9:M9');
    $ws1->setCellValue('L9', 'TARGET (' . strtoupper($selectedUnit) . ')');
    applyStyle($ws1, 'A9:M9', hdrStyle('0F172A'));

    $mhRow = 10;
    $mhStartRow = $mhRow;
    $mhIndex = 1;

    foreach ($trendMap as $tk => $tv) {
        $ws1->setCellValue("A{$mhRow}", $mhIndex++);
        
        $ws1->mergeCells("B{$mhRow}:G{$mhRow}");
        $ws1->setCellValue("B{$mhRow}", $tv['label']);

        $ws1->mergeCells("H{$mhRow}:I{$mhRow}");
        $ws1->setCellValue("H{$mhRow}", (int)$tv['pcs']);

        $ws1->mergeCells("J{$mhRow}:K{$mhRow}");
        $ws1->setCellValue("J{$mhRow}", (int)$tv['lot']);

        $ws1->mergeCells("L{$mhRow}:M{$mhRow}");
        if ($defectThreshold !== null) {
            $ws1->setCellValue("L{$mhRow}", (float)$defectThreshold);
        } else {
            $ws1->setCellValue("L{$mhRow}", null);
        }

        // Highlight warna zona horizon
        $bg = 'FFFFFF';
        if (strpos($tv['category'], 'Tahunan') !== false) {
            $bg = 'F8FAFC';
        } elseif (strpos($tv['category'], 'Bulanan') !== false) {
            $bg = 'EFF6FF';
        } else {
            $bg = 'ECFDF5';
        }

        applyStyle($ws1, "A{$mhRow}", dataStyle($bg, '64748B', false, Alignment::HORIZONTAL_CENTER));
        applyStyle($ws1, "B{$mhRow}:G{$mhRow}", dataStyle($bg, '0F172A', true, Alignment::HORIZONTAL_LEFT));
        applyStyle($ws1, "H{$mhRow}:I{$mhRow}", dataStyle($bg, 'DC2626', false, Alignment::HORIZONTAL_RIGHT));
        applyStyle($ws1, "J{$mhRow}:K{$mhRow}", dataStyle($bg, '4338CA', false, Alignment::HORIZONTAL_RIGHT));
        applyStyle($ws1, "L{$mhRow}:M{$mhRow}", dataStyle($bg, 'BE123C', true, Alignment::HORIZONTAL_RIGHT));

        $ws1->getStyle("H{$mhRow}")->getNumberFormat()->setFormatCode('#,##0');
        $ws1->getStyle("J{$mhRow}")->getNumberFormat()->setFormatCode('#,##0');
        if ($defectThreshold !== null) {
            $ws1->getStyle("L{$mhRow}")->getNumberFormat()->setFormatCode('#,##0');
        }
        $mhRow++;
    }
    $mhEndRow = $mhRow - 1;
    $mhDataCount = count($trendMap);

    // Chart Multi-Horizon Combo / Dual Series (Full Width Kolom A s.d. M)
    $chartMhStartRow = $mhEndRow + 2;
    $chartMhEndRow   = $chartMhStartRow + 17;

    if ($mhDataCount > 0 && $mhEndRow >= $mhStartRow) {
        try {
            $xAxisLabels = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'{$sheet1Title}'!\$B\${$mhStartRow}:\$B\${$mhEndRow}", null, $mhDataCount)];
            
            // Series 1: Bar Temuan Aktual (Membaca Kolom H jika PCS, atau Kolom J jika LOT)
            $actualCol = ($selectedUnit === 'lot') ? 'J' : 'H';
            $seriesBarTitle  = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'{$sheet1Title}'!\${$actualCol}\$9", null, 1)];
            $seriesBarValues = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'{$sheet1Title}'!\${$actualCol}\${$mhStartRow}:\${$actualCol}\${$mhEndRow}", null, $mhDataCount)];
            $seriesMhBar = new DataSeries(DataSeries::TYPE_BARCHART, DataSeries::GROUPING_CLUSTERED, [0], $seriesBarTitle, $xAxisLabels, $seriesBarValues);
            $seriesMhBar->setPlotDirection(DataSeries::DIRECTION_COL);

            $plotSeriesList = [$seriesMhBar];

            // Series 2: Garis Batas Target (Kolom L) jika target diatur
            if ($defectThreshold !== null) {
                $seriesLineTitle  = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'{$sheet1Title}'!\$L\$9", null, 1)];
                $seriesLineValues = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'{$sheet1Title}'!\$L\${$mhStartRow}:\$L\${$mhEndRow}", null, $mhDataCount)];
                $seriesMhLine = new DataSeries(DataSeries::TYPE_LINECHART, DataSeries::GROUPING_STANDARD, [0], $seriesLineTitle, $xAxisLabels, $seriesLineValues);
                $plotSeriesList[] = $seriesMhLine;
            }

            $layoutMh = new Layout();
            $layoutMh->setShowVal(true);
            $plotAreaMh = new PlotArea($layoutMh, $plotSeriesList);
            $legendMh   = new Legend(Legend::POSITION_BOTTOM, null, false);
            $chartTitleMh = new Title("Grafik Tren & Komparasi Multi-Horizon Defect: " . $defectName);
            $chartObjMh   = new Chart('chart_defect_multi_horizon', $chartTitleMh, $legendMh, $plotAreaMh, true);
            $chartObjMh->setTopLeftPosition("A{$chartMhStartRow}");
            $chartObjMh->setBottomRightPosition("M{$chartMhEndRow}");
            $ws1->addChart($chartObjMh);
        } catch (Exception $ex) {}
    }

    // ── BAGIAN 2 (BAWAH): SEBARAN PART TERDAMPAK + KONTRIBUSI 100% WIDTH ─
    $distHeaderRow = $chartMhEndRow + 3;
    $ws1->mergeCells("A{$distHeaderRow}:M{$distHeaderRow}");
    $ws1->setCellValue("A{$distHeaderRow}", '2. SEBARAN PART TERDAMPAK PADA DEFECT TERPILIH (TOP RANK & KONTRIBUSI)');
    applyStyle($ws1, "A{$distHeaderRow}", [
        'font'      => ['bold' => true, 'size' => 11, 'color' => ['argb' => 'FFFFFFFF']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF059669']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1]
    ]);

    $distSubHdrRow = $distHeaderRow + 1;
    $ws1->setCellValue("A{$distSubHdrRow}", 'NO');
    $ws1->mergeCells("B{$distSubHdrRow}:C{$distSubHdrRow}");
    $ws1->setCellValue("B{$distSubHdrRow}", 'PART CODE');
    $ws1->mergeCells("D{$distSubHdrRow}:G{$distSubHdrRow}");
    $ws1->setCellValue("D{$distSubHdrRow}", 'PART NAME');
    $ws1->mergeCells("H{$distSubHdrRow}:I{$distSubHdrRow}");
    $ws1->setCellValue("H{$distSubHdrRow}", 'TEMUAN (PCS)');
    $ws1->mergeCells("J{$distSubHdrRow}:K{$distSubHdrRow}");
    $ws1->setCellValue("J{$distSubHdrRow}", 'KASUS (LOT)');
    $ws1->mergeCells("L{$distSubHdrRow}:M{$distSubHdrRow}");
    $ws1->setCellValue("L{$distSubHdrRow}", '% KONTRIBUSI');
    applyStyle($ws1, "A{$distSubHdrRow}:M{$distSubHdrRow}", hdrStyle('064E3B'));

    $dRow = $distSubHdrRow + 1;
    $dIndex = 1;

    if (empty($distributionList)) {
        $ws1->mergeCells("A{$dRow}:M{$dRow}");
        $ws1->setCellValue("A{$dRow}", 'Tidak ada temuan part untuk defect ini.');
        applyStyle($ws1, "A{$dRow}:M{$dRow}", dataStyle('F8FAFC', '94A3B8', false, Alignment::HORIZONTAL_CENTER));
        $dRow++;
    } else {
        foreach ($distributionList as $di) {
            $valAct = ($selectedUnit === 'lot') ? $di['total_lots'] : $di['total_pcs'];
            $share = ($grandDistMetric > 0 && $valAct > 0) ? round(($valAct / $grandDistMetric) * 100, 1) : 0;

            $bg = ($dIndex % 2 === 0) ? 'FFFFFF' : 'F8FAFC';
            $ws1->setCellValue("A{$dRow}", $dIndex++);

            $ws1->mergeCells("B{$dRow}:C{$dRow}");
            $ws1->setCellValue("B{$dRow}", $di['item_code']);

            $ws1->mergeCells("D{$dRow}:G{$dRow}");
            $ws1->setCellValue("D{$dRow}", $di['item_name']);

            $ws1->mergeCells("H{$dRow}:I{$dRow}");
            $ws1->setCellValue("H{$dRow}", (int)$di['total_pcs']);

            $ws1->mergeCells("J{$dRow}:K{$dRow}");
            $ws1->setCellValue("J{$dRow}", (int)$di['total_lots']);

            $ws1->mergeCells("L{$dRow}:M{$dRow}");
            $ws1->setCellValue("L{$dRow}", $share / 100);

            applyStyle($ws1, "A{$dRow}", dataStyle($bg, '64748B', false, Alignment::HORIZONTAL_CENTER));
            applyStyle($ws1, "B{$dRow}:C{$dRow}", dataStyle($bg, '1D4ED8', true, Alignment::HORIZONTAL_LEFT));
            applyStyle($ws1, "D{$dRow}:G{$dRow}", dataStyle($bg, '0F172A', false, Alignment::HORIZONTAL_LEFT));
            applyStyle($ws1, "H{$dRow}:I{$dRow}", dataStyle($bg, 'DC2626', false, Alignment::HORIZONTAL_RIGHT));
            applyStyle($ws1, "J{$dRow}:K{$dRow}", dataStyle($bg, '4338CA', false, Alignment::HORIZONTAL_RIGHT));
            applyStyle($ws1, "L{$dRow}:M{$dRow}", dataStyle($bg, '047857', true, Alignment::HORIZONTAL_RIGHT));

            $ws1->getStyle("H{$dRow}")->getNumberFormat()->setFormatCode('#,##0');
            $ws1->getStyle("J{$dRow}")->getNumberFormat()->setFormatCode('#,##0');
            $ws1->getStyle("L{$dRow}")->getNumberFormat()->setFormatCode('0.0%');
            $dRow++;
        }
    }

} else {
    // ═══════════════════════════════════════════════════════════════════════
    // MODE PART OVERVIEW (PART FOCUS: 100% Stacked Full Width Mirrored)
    // ═══════════════════════════════════════════════════════════════════════

    // KPI Card 2: Batas Target Part
    $targetDesc = ($partThreshold !== null) ? (number_format($partThreshold) . ' ' . strtoupper($selectedUnit)) : 'Belum Diset';
    $isPartOver = ($partThreshold !== null && $metricAcc > $partThreshold);
    $targetStatus = ($partThreshold === null) ? 'Belum Diatur' : ($isPartOver ? '⚠️ Over Target' : '✓ Aman');

    $ws1->mergeCells('E4:H4');
    $ws1->setCellValue('E4', 'BATAS TARGET PART');
    applyStyle($ws1, 'E4', ['font' => ['bold' => true, 'size' => 9, 'color' => ['argb' => $isPartOver ? 'FFB91C1C' : 'FF047857']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $isPartOver ? 'FFFEF2F2' : 'FFECFDF5']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);

    $ws1->mergeCells('E5:H5');
    $ws1->setCellValue('E5', $targetDesc);
    applyStyle($ws1, 'E5', ['font' => ['bold' => true, 'size' => 16, 'color' => ['argb' => $isPartOver ? 'FFB91C1C' : 'FF047857']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $isPartOver ? 'FFFEF2F2' : 'FFECFDF5']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]]);

    $ws1->mergeCells('E6:H6');
    $ws1->setCellValue('E6', 'Status Kepatuhan: ' . $targetStatus);
    applyStyle($ws1, 'E6', ['font' => ['bold' => true, 'italic' => true, 'size' => 8, 'color' => ['argb' => $isPartOver ? 'FF991B1B' : 'FF065F46']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $isPartOver ? 'FFFEF2F2' : 'FFECFDF5']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);
    applyStyle($ws1, 'E4:H6', ['borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => $isPartOver ? 'FFFECACA' : 'FFA7F3D0']]]]);

    // KPI Card 3: Puncak Kasus (Peak)
    $ws1->mergeCells('I4:M4');
    $ws1->setCellValue('I4', 'PUNCAK KASUS (PEAK PERIOD)');
    applyStyle($ws1, 'I4', ['font' => ['bold' => true, 'size' => 9, 'color' => ['argb' => 'FF4338CA']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEEF2FF']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);

    $ws1->mergeCells('I5:M5');
    $ws1->setCellValue('I5', $peakLabel);
    applyStyle($ws1, 'I5', ['font' => ['bold' => true, 'size' => 12, 'color' => ['argb' => 'FF4338CA']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEEF2FF']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]]);

    $ws1->mergeCells('I6:M6');
    $ws1->setCellValue('I6', 'Interval kasus tertinggi pada part ini');
    applyStyle($ws1, 'I6', ['font' => ['italic' => true, 'size' => 8, 'color' => ['argb' => 'FF3730A3']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEEF2FF']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);
    applyStyle($ws1, 'I4:M6', ['borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FFE0E7FF']]]]);

    // ── BAGIAN 1 (ATAS): TREN FLUKTUASI MULTI-HORIZON 100% WIDTH ─────────
    $ws1->mergeCells('A8:M8');
    $ws1->setCellValue('A8', '1. TREN FLUKTUASI CACAT PART (MULTI-HORIZON: TAHUNAN → BULANAN → MINGGUAN/HARIAN)');
    applyStyle($ws1, 'A8', [
        'font'      => ['bold' => true, 'size' => 11, 'color' => ['argb' => 'FFFFFFFF']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1E293B']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1]
    ]);

    $ws1->setCellValue('A9', 'NO');
    $ws1->mergeCells('B9:G9');
    $ws1->setCellValue('B9', 'PERIODE / WAKTU');
    $ws1->mergeCells('H9:I9');
    $ws1->setCellValue('H9', 'TEMUAN (PCS)');
    $ws1->mergeCells('J9:K9');
    $ws1->setCellValue('J9', 'TEMUAN (LOT)');
    $ws1->mergeCells('L9:M9');
    $ws1->setCellValue('L9', 'TARGET (' . strtoupper($selectedUnit) . ')');
    applyStyle($ws1, 'A9:M9', hdrStyle('0F172A'));

    $mhRow = 10;
    $mhStartRow = $mhRow;
    $mhIndex = 1;

    foreach ($trendMap as $tk => $tv) {
        $ws1->setCellValue("A{$mhRow}", $mhIndex++);
        
        $ws1->mergeCells("B{$mhRow}:G{$mhRow}");
        $ws1->setCellValue("B{$mhRow}", $tv['label']);

        $ws1->mergeCells("H{$mhRow}:I{$mhRow}");
        $ws1->setCellValue("H{$mhRow}", (int)$tv['pcs']);

        $ws1->mergeCells("J{$mhRow}:K{$mhRow}");
        $ws1->setCellValue("J{$mhRow}", (int)$tv['lot']);

        $ws1->mergeCells("L{$mhRow}:M{$mhRow}");
        if ($partThreshold !== null) {
            $ws1->setCellValue("L{$mhRow}", (float)$partThreshold);
        } else {
            $ws1->setCellValue("L{$mhRow}", '-');
        }

        // Highlight warna zona horizon
        $bg = 'FFFFFF';
        if (strpos($tv['category'], 'Tahunan') !== false) {
            $bg = 'F8FAFC';
        } elseif (strpos($tv['category'], 'Bulanan') !== false) {
            $bg = 'EFF6FF';
        } else {
            $bg = 'ECFDF5';
        }

        applyStyle($ws1, "A{$mhRow}", dataStyle($bg, '64748B', false, Alignment::HORIZONTAL_CENTER));
        applyStyle($ws1, "B{$mhRow}:G{$mhRow}", dataStyle($bg, '0F172A', true, Alignment::HORIZONTAL_LEFT));
        applyStyle($ws1, "H{$mhRow}:I{$mhRow}", dataStyle($bg, 'DC2626', false, Alignment::HORIZONTAL_RIGHT));
        applyStyle($ws1, "J{$mhRow}:K{$mhRow}", dataStyle($bg, '4338CA', false, Alignment::HORIZONTAL_RIGHT));
        applyStyle($ws1, "L{$mhRow}:M{$mhRow}", dataStyle($bg, 'E11D48', true, Alignment::HORIZONTAL_RIGHT));

        $ws1->getStyle("H{$mhRow}")->getNumberFormat()->setFormatCode('#,##0');
        $ws1->getStyle("J{$mhRow}")->getNumberFormat()->setFormatCode('#,##0');
        if ($partThreshold !== null) {
            $ws1->getStyle("L{$mhRow}")->getNumberFormat()->setFormatCode('#,##0');
        }
        $mhRow++;
    }
    $mhEndRow = $mhRow - 1;
    $mhDataCount = count($trendMap);

    // Chart Multi-Horizon Combo (Bar Aktual + Line Target Batas)
    $chartMhStartRow = $mhEndRow + 2;
    $chartMhEndRow   = $chartMhStartRow + 17;

    if ($mhDataCount > 0 && $mhEndRow >= $mhStartRow) {
        try {
            $xAxisLabels = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'{$sheet1Title}'!\$B\${$mhStartRow}:\$B\${$mhEndRow}", null, $mhDataCount)];
            $actualCol = ($selectedUnit === 'lot') ? 'J' : 'H';
            $barValues   = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'{$sheet1Title}'!\${$actualCol}\${$mhStartRow}:\${$actualCol}\${$mhEndRow}", null, $mhDataCount)];
            $barTitle    = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'{$sheet1Title}'!\${$actualCol}\$9", null, 1)];

            $seriesBar = new DataSeries(
                DataSeries::TYPE_BARCHART,
                DataSeries::GROUPING_CLUSTERED,
                [0],
                $barTitle,
                $xAxisLabels,
                $barValues
            );
            $seriesBar->setPlotDirection(DataSeries::DIRECTION_COL);

            $plotSeriesList = [$seriesBar];

            if ($partThreshold !== null) {
                $lineValues = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'{$sheet1Title}'!\$L\${$mhStartRow}:\$L\${$mhEndRow}", null, $mhDataCount)];
                $lineTitle  = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'{$sheet1Title}'!\$L\$9", null, 1)];
                $seriesLine = new DataSeries(
                    DataSeries::TYPE_LINECHART,
                    DataSeries::GROUPING_STANDARD,
                    [0],
                    $lineTitle,
                    null,
                    $lineValues
                );
                $plotSeriesList[] = $seriesLine;
            }

            $layoutMh = new Layout();
            $layoutMh->setShowVal(true);
            $plotAreaMh = new PlotArea($layoutMh, $plotSeriesList);
            $legendMh   = new Legend(Legend::POSITION_BOTTOM, null, false);
            $chartTitleMh = new Title("Grafik Multi-Horizon: " . $partCode . " (" . $partName . ")" . ($partThreshold !== null ? " [Target: {$partThreshold}]" : ""));
            $chartObjMh   = new Chart('chart_part_multi_horizon', $chartTitleMh, $legendMh, $plotAreaMh, true);
            $chartObjMh->setTopLeftPosition("A{$chartMhStartRow}");
            $chartObjMh->setBottomRightPosition("M{$chartMhEndRow}");
            $ws1->addChart($chartObjMh);
        } catch (Exception $ex) {}
    }

    // ── BAGIAN 2 (BAWAH): SEBARAN JENIS DEFECT + BATAS TARGET 100% WIDTH ─
    $distHeaderRow = $chartMhEndRow + 3;
    $ws1->mergeCells("A{$distHeaderRow}:M{$distHeaderRow}");
    $ws1->setCellValue("A{$distHeaderRow}", '2. SEBARAN JENIS DEFECT PADA PART TERPILIH (LENGKAP DENGAN BATAS TARGET)');
    applyStyle($ws1, "A{$distHeaderRow}", [
        'font'      => ['bold' => true, 'size' => 11, 'color' => ['argb' => 'FFFFFFFF']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF059669']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1]
    ]);

    $distSubHdrRow = $distHeaderRow + 1;
    $ws1->setCellValue("A{$distSubHdrRow}", 'NO');
    $ws1->mergeCells("B{$distSubHdrRow}:E{$distSubHdrRow}");
    $ws1->setCellValue("B{$distSubHdrRow}", 'JENIS DEFECT');
    $ws1->mergeCells("F{$distSubHdrRow}:G{$distSubHdrRow}");
    $ws1->setCellValue("F{$distSubHdrRow}", 'TOTAL TEMUAN (' . strtoupper($selectedUnit) . ')');
    $ws1->mergeCells("H{$distSubHdrRow}:I{$distSubHdrRow}");
    $ws1->setCellValue("H{$distSubHdrRow}", 'KASUS (LOT)');
    $ws1->setCellValue("J{$distSubHdrRow}", '% KONTRIBUSI');
    $ws1->mergeCells("K{$distSubHdrRow}:L{$distSubHdrRow}");
    $ws1->setCellValue("K{$distSubHdrRow}", 'BATAS TARGET');
    $ws1->setCellValue("M{$distSubHdrRow}", 'STATUS EVALUASI');
    applyStyle($ws1, "A{$distSubHdrRow}:M{$distSubHdrRow}", hdrStyle('064E3B'));

    $dRow = $distSubHdrRow + 1;
    $dIndex = 1;

    if (empty($distributionList)) {
        $ws1->mergeCells("A{$dRow}:M{$dRow}");
        $ws1->setCellValue("A{$dRow}", 'Tidak ada temuan defect pada part ini.');
        applyStyle($ws1, "A{$dRow}:M{$dRow}", dataStyle('F8FAFC', '94A3B8', false, Alignment::HORIZONTAL_CENTER));
        $dRow++;
    } else {
        foreach ($distributionList as $di) {
            $valAct = ($selectedUnit === 'lot') ? $di['total_lots'] : $di['total_pcs'];
            $share = ($grandDistMetric > 0 && $valAct > 0) ? round(($valAct / $grandDistMetric) * 100, 1) : 0;

            // Evaluasi target part
            $targetText = '-';
            $evalText   = '-';
            $evalBg     = 'FFFFFF';
            $evalFg     = '64748B';

            if ($partThreshold !== null) {
                $targetText = number_format($partThreshold) . ' ' . strtoupper($selectedUnit);
                if ($valAct > $partThreshold) {
                    $evalText = '⚠️ Over';
                    $evalBg   = 'FEF2F2';
                    $evalFg   = '991B1B';
                } else {
                    $evalText = '✓ Aman';
                    $evalBg   = 'ECFDF5';
                    $evalFg   = '065F46';
                }
            }

            $bg = ($dIndex % 2 === 0) ? 'FFFFFF' : 'F8FAFC';
            $ws1->setCellValue("A{$dRow}", $dIndex++);

            $ws1->mergeCells("B{$dRow}:E{$dRow}");
            $ws1->setCellValue("B{$dRow}", $di['item_name']);

            $ws1->mergeCells("F{$dRow}:G{$dRow}");
            $ws1->setCellValue("F{$dRow}", (int)$di['total_pcs']);

            $ws1->mergeCells("H{$dRow}:I{$dRow}");
            $ws1->setCellValue("H{$dRow}", (int)$di['total_lots']);

            $ws1->setCellValue("J{$dRow}", $share / 100);

            $ws1->mergeCells("K{$dRow}:L{$dRow}");
            $ws1->setCellValue("K{$dRow}", $targetText);

            $ws1->setCellValue("M{$dRow}", $evalText);

            applyStyle($ws1, "A{$dRow}", dataStyle($bg, '64748B', false, Alignment::HORIZONTAL_CENTER));
            applyStyle($ws1, "B{$dRow}:E{$dRow}", dataStyle($bg, '0F172A', true, Alignment::HORIZONTAL_LEFT));
            applyStyle($ws1, "F{$dRow}:G{$dRow}", dataStyle($bg, 'DC2626', false, Alignment::HORIZONTAL_RIGHT));
            applyStyle($ws1, "H{$dRow}:I{$dRow}", dataStyle($bg, '4338CA', false, Alignment::HORIZONTAL_RIGHT));
            applyStyle($ws1, "J{$dRow}", dataStyle($bg, '047857', true, Alignment::HORIZONTAL_RIGHT));
            applyStyle($ws1, "K{$dRow}:L{$dRow}", dataStyle($bg, '0F172A', true, Alignment::HORIZONTAL_CENTER));
            applyStyle($ws1, "M{$dRow}", dataStyle($evalBg, $evalFg, true, Alignment::HORIZONTAL_CENTER));

            $ws1->getStyle("F{$dRow}")->getNumberFormat()->setFormatCode('#,##0');
            $ws1->getStyle("H{$dRow}")->getNumberFormat()->setFormatCode('#,##0');
            $ws1->getStyle("J{$dRow}")->getNumberFormat()->setFormatCode('0.0%');
            $dRow++;
        }
    }
}


// ═══════════════════════════════════════════════════════════════════════════
// SHEET 2: 📋 RIWAYAT TEMUAN INSPEKSI (Detail Audit Trail Log)
// ═══════════════════════════════════════════════════════════════════════════
$ws2 = $spreadsheet->createSheet();
$ws2->setTitle("📋 Riwayat Temuan");

// Column Widths
$ws2->getColumnDimension('A')->setWidth(6);
$ws2->getColumnDimension('B')->setWidth(20);
$ws2->getColumnDimension('C')->setWidth(18);
$ws2->getColumnDimension('D')->setWidth(28);
$ws2->getColumnDimension('E')->setWidth(20);
$ws2->getColumnDimension('F')->setWidth(24);
$ws2->getColumnDimension('G')->setWidth(15);
$ws2->getColumnDimension('H')->setWidth(16);
$ws2->getColumnDimension('I')->setWidth(16);
$ws2->getColumnDimension('J')->setWidth(16);
$ws2->getColumnDimension('K')->setWidth(16);
$ws2->getColumnDimension('L')->setWidth(20);
$ws2->getColumnDimension('M')->setWidth(16);

// Header Banner
$ws2->getRowDimension(1)->setRowHeight(32);
$ws2->getRowDimension(2)->setRowHeight(22);
$ws2->mergeCells('A1:M1');
$ws2->setCellValue('A1', 'DAFTAR RIWAYAT TEMUAN INSPEKSI (AUDIT TRAIL LOG) — OQC SYSTEM');
applyStyle($ws2, 'A1', [
    'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF065F46']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF047857']]]
]);

$ws2->mergeCells('A2:M2');
if ($mode === 'overview') {
    $auditSubHeader = "TARGET DEFECT: " . strtoupper($defectName) . "  |  PERIODE: " . strtoupper($filterTitleDesc) . "  |  CUSTOMER: " . strtoupper($selectedCustomer ?: 'SEMUA CUSTOMER');
} else {
    $auditSubHeader = "TARGET PART: " . strtoupper($partCode . ' - ' . $partName) . "  |  MODEL: " . strtoupper($modelName) . "  |  PERIODE: " . strtoupper($filterTitleDesc) . "  |  CUSTOMER: " . strtoupper($selectedCustomer ?: 'SEMUA CUSTOMER');
}
$ws2->setCellValue('A2', $auditSubHeader);
applyStyle($ws2, 'A2', [
    'font'      => ['bold' => true, 'size' => 9, 'color' => ['argb' => 'FF065F46']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFECFDF5']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]
]);

// Table Column Headers
$ws2->setCellValue('A4', 'NO');
$ws2->setCellValue('B4', 'TANGGAL & JAM');
$ws2->setCellValue('C4', 'PART CODE');
$ws2->setCellValue('D4', 'PART NAME');
$ws2->setCellValue('E4', 'MODEL PRODUK');
$ws2->setCellValue('F4', 'JENIS DEFECT');
$ws2->setCellValue('G4', 'INSPEKSI');
$ws2->setCellValue('H4', 'NO KANBAN');
$ws2->setCellValue('I4', 'CUSTOMER');
$ws2->setCellValue('J4', 'LOT NUMBER');
$ws2->setCellValue('K4', 'REF NUMBER');
$ws2->setCellValue('L4', 'INSPECTOR');
$ws2->setCellValue('M4', 'JUMLAH (PCS)');
applyStyle($ws2, 'A4:M4', hdrStyle('0F172A'));
$ws2->freezePane('A5'); // Freeze row 4 header so it stays fixed on scroll

$aRow = 5;
$auditStartRow = $aRow;

if (empty($auditRecords)) {
    $ws2->mergeCells('A5:M5');
    $ws2->setCellValue('A5', 'Tidak ada riwayat catatan temuan inspeksi pada filter ini.');
    applyStyle($ws2, 'A5:M5', dataStyle('F8FAFC', '94A3B8', false, Alignment::HORIZONTAL_CENTER));
    $aRow++;
} else {
    foreach ($auditRecords as $ai => $a) {
        $no = $ai + 1;
        $ws2->setCellValue("A{$aRow}", $no);
        $ws2->setCellValue("B{$aRow}", date('d/m/Y H:i', strtotime($a['started_at'])) . ' WIB');
        $ws2->setCellValue("C{$aRow}", $a['part_code']);
        $ws2->setCellValue("D{$aRow}", $a['part_name']);
        $ws2->setCellValue("E{$aRow}", $a['model_name']);
        $ws2->setCellValue("F{$aRow}", $a['defect_name']);
        $ws2->setCellValue("G{$aRow}", strtoupper($a['inspection_type']));
        $ws2->setCellValue("H{$aRow}", $a['kanban_no']);
        $ws2->setCellValue("I{$aRow}", $a['customer_name']);
        $ws2->setCellValue("J{$aRow}", $a['lot_number']);
        $ws2->setCellValue("K{$aRow}", $a['ref_number']);
        $ws2->setCellValue("L{$aRow}", $a['inspector_name']);
        $ws2->setCellValue("M{$aRow}", (int)$a['qty_ng']);

        $bg = ($ai % 2 === 0) ? 'FFFFFF' : 'F8FAFC';
        applyStyle($ws2, "A{$aRow}", dataStyle($bg, '64748B', false, Alignment::HORIZONTAL_CENTER));
        applyStyle($ws2, "B{$aRow}", dataStyle($bg, '334155', false, Alignment::HORIZONTAL_CENTER));
        applyStyle($ws2, "C{$aRow}", dataStyle($bg, '1D4ED8', true, Alignment::HORIZONTAL_LEFT));
        applyStyle($ws2, "D{$aRow}", dataStyle($bg, '0F172A', false, Alignment::HORIZONTAL_LEFT));
        applyStyle($ws2, "E{$aRow}", dataStyle($bg, '475569', false, Alignment::HORIZONTAL_LEFT));
        applyStyle($ws2, "F{$aRow}", dataStyle($bg, 'BE123C', true, Alignment::HORIZONTAL_LEFT));
        applyStyle($ws2, "G{$aRow}", dataStyle($bg, '0F172A', false, Alignment::HORIZONTAL_CENTER));
        applyStyle($ws2, "H{$aRow}", dataStyle($bg, '0F172A', false, Alignment::HORIZONTAL_CENTER));
        applyStyle($ws2, "I{$aRow}", dataStyle($bg, '334155', false, Alignment::HORIZONTAL_LEFT));
        applyStyle($ws2, "J{$aRow}", dataStyle($bg, '0F172A', false, Alignment::HORIZONTAL_CENTER));
        applyStyle($ws2, "K{$aRow}", dataStyle($bg, '475569', false, Alignment::HORIZONTAL_CENTER));
        applyStyle($ws2, "L{$aRow}", dataStyle($bg, '0F172A', false, Alignment::HORIZONTAL_LEFT));
        applyStyle($ws2, "M{$aRow}", dataStyle($bg, 'DC2626', true, Alignment::HORIZONTAL_RIGHT));

        $ws2->getStyle("M{$aRow}")->getNumberFormat()->setFormatCode('#,##0');
        $aRow++;
    }
}
$auditEndRow = $aRow - 1;

// Total Summary Row on Audit Sheet with Excel SUM Formula
$ws2->mergeCells("A{$aRow}:L{$aRow}");
$ws2->setCellValue("A{$aRow}", "TOTAL TEMUAN CACAT (PCS)");
$ws2->setCellValue("M{$aRow}", "=SUM(M{$auditStartRow}:M{$auditEndRow})");
applyStyle($ws2, "A{$aRow}:L{$aRow}", dataStyle('0F172A', 'FFFFFF', true, Alignment::HORIZONTAL_RIGHT));
applyStyle($ws2, "M{$aRow}", dataStyle('0F172A', 'FFFFFF', true, Alignment::HORIZONTAL_RIGHT));
$ws2->getStyle("M{$aRow}")->getNumberFormat()->setFormatCode('#,##0');

// ── 7. Set Active Sheet & Stream XLSX Output ─────────────────────────────────
$spreadsheet->setActiveSheetIndex(0);

$filenameDate = date('Ymd_His');
if ($mode === 'overview') {
    $cleanDefect = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $defectName);
    $filename = "Analisis_Defect_{$cleanDefect}_{$filenameDate}.xlsx";
} else {
    $cleanPart = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $partCode);
    $filename = "Analisis_Part_{$cleanPart}_{$filenameDate}.xlsx";
}

header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
header("Content-Disposition: attachment; filename={$filename}");
header("Cache-Control: max-age=0");
header("Pragma: no-cache");

$writer = new Xlsx($spreadsheet);
$writer->setIncludeCharts(true);
$writer->save("php://output");
exit;
