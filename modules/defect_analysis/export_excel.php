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
} elseif ($filterType === 'month_year' || $presetFilter === 'month_year') {
    $startDate       = sprintf('%04d-%02d-01', $selectedYear, $selectedMonth);
    $endDate         = date('Y-m-t', strtotime($startDate));
    $presetFilter    = 'month_year';
    $filterTitleDesc = "Bulan " . ($monthNames[$selectedMonth] ?? $selectedMonth) . " " . $selectedYear;
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
            $presetFilter    = 'bulanan';
            $startDate       = date('Y-m-01');
            $endDate         = date('Y-m-d');
            $filterTitleDesc = "Bulan Ini (" . ($monthNames[(int)date('n')] ?? date('F')) . " " . date('Y') . ")";
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
        // 1. Timeline Data for Defect
        try {
            if ($isSingleDay) {
                $wHour = [
                    "(ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)",
                    "s.started_at >= :sd AND s.started_at < :ed_next"
                ];
                $pHour = [
                    ':sd'      => $startDate . ' 00:00:00',
                    ':ed_next' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'
                ];
                if ($defectId > 0) {
                    $wHour[] = "ngr.defect_type_id = :defId";
                    $pHour[':defId'] = $defectId;
                }
                if ($selectedCustomer !== '') {
                    $wHour[] = "COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') = :cust";
                    $pHour[':cust'] = $selectedCustomer;
                }
                $wHourSql = implode(' AND ', $wHour);
                $stT = $pdo->prepare("SELECT DATE_FORMAT(s.started_at, '%H:00') as time_key,
                                             COALESCE(SUM(ngr.qty_ng), 0) as total_pcs,
                                             COUNT(DISTINCT s.id) as total_lots
                                      FROM inspection_ng_records ngr
                                      INNER JOIN inspection_sessions s ON ngr.inspection_session_id = s.id
                                      LEFT JOIN kanban_items ki ON s.kanban_item_id = ki.id
                                      WHERE {$wHourSql}
                                      GROUP BY time_key");
                $stT->execute($pHour);
                foreach ($stT->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $tk = $r['time_key'];
                    if (isset($trendMap[$tk])) {
                        $trendMap[$tk]['pcs'] = (int)$r['total_pcs'];
                        $trendMap[$tk]['lot'] = (int)$r['total_lots'];
                    }
                }
            } else {
                $wSumT = ["dds.summary_date >= :sd AND dds.summary_date <= :ed"];
                $pSumT = [':sd' => $startDate, ':ed' => $endDate];
                if ($defectId > 0) {
                    $wSumT[] = "dds.defect_type_id = :defId";
                    $pSumT[':defId'] = $defectId;
                }
                if ($selectedCustomer !== '') {
                    $wSumT[] = "dds.customer = :cust";
                    $pSumT[':cust'] = $selectedCustomer;
                }
                $wSumTSql = implode(' AND ', $wSumT);

                if ($intervalType === 'tahun') {
                    $groupExpr = "DATE_FORMAT(dds.summary_date, '%Y')";
                } elseif ($intervalType === 'hari') {
                    $groupExpr = "dds.summary_date";
                } else {
                    $groupExpr = "DATE_FORMAT(dds.summary_date, '%Y-%m')";
                }

                $stSumT = $pdo->prepare("SELECT {$groupExpr} as time_key,
                                                COALESCE(SUM(dds.qty_ng), 0) as total_pcs,
                                                COALESCE(SUM(dds.lot_count), 0) as total_lots
                                         FROM oqc_daily_defect_summary dds
                                         WHERE {$wSumTSql}
                                         GROUP BY time_key");
                $stSumT->execute($pSumT);
                foreach ($stSumT->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $tk = $r['time_key'];
                    if (isset($trendMap[$tk])) {
                        $trendMap[$tk]['pcs'] += (int)$r['total_pcs'];
                        $trendMap[$tk]['lot'] += (int)$r['total_lots'];
                    }
                }

                // In-progress active session if range covers today
                if ($endDate >= date('Y-m-d')) {
                    $wActT = [
                        "s.status = 'in_progress'",
                        "(ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)",
                        "s.started_at >= :sd_act AND s.started_at < :ed_act_next"
                    ];
                    $pActT = [
                        ':sd_act'      => $startDate . ' 00:00:00',
                        ':ed_act_next' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'
                    ];
                    if ($defectId > 0) {
                        $wActT[] = "ngr.defect_type_id = :defId";
                        $pActT[':defId'] = $defectId;
                    }
                    if ($selectedCustomer !== '') {
                        $wActT[] = "COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') = :cust";
                        $pActT[':cust'] = $selectedCustomer;
                    }
                    $wActTSql = implode(' AND ', $wActT);

                    if ($intervalType === 'tahun') {
                        $groupAct = "DATE_FORMAT(s.started_at, '%Y')";
                    } elseif ($intervalType === 'hari') {
                        $groupAct = "DATE_FORMAT(s.started_at, '%Y-%m-%d')";
                    } else {
                        $groupAct = "DATE_FORMAT(s.started_at, '%Y-%m')";
                    }

                    $stActT = $pdo->prepare("SELECT {$groupAct} as time_key,
                                                    COALESCE(SUM(ngr.qty_ng), 0) as total_pcs,
                                                    COUNT(DISTINCT s.id) as total_lots
                                             FROM inspection_ng_records ngr
                                             INNER JOIN inspection_sessions s ON ngr.inspection_session_id = s.id
                                             LEFT JOIN kanban_items ki ON s.kanban_item_id = ki.id
                                             WHERE {$wActTSql}
                                             GROUP BY time_key");
                    $stActT->execute($pActT);
                    foreach ($stActT->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $tk = $r['time_key'];
                        if (isset($trendMap[$tk])) {
                            $trendMap[$tk]['pcs'] += (int)$r['total_pcs'];
                            $trendMap[$tk]['lot'] += (int)$r['total_lots'];
                        }
                    }
                }
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
        // 1. Timeline Data for Part
        try {
            if ($isSingleDay) {
                $wHour = [
                    "(ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)",
                    "s.started_at >= :sd AND s.started_at < :ed_next"
                ];
                $pHour = [
                    ':sd'      => $startDate . ' 00:00:00',
                    ':ed_next' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'
                ];
                if ($partId > 0) {
                    $wHour[] = "ngr.part_id = :pId";
                    $pHour[':pId'] = $partId;
                }
                if ($selectedCustomer !== '') {
                    $wHour[] = "COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') = :cust";
                    $pHour[':cust'] = $selectedCustomer;
                }
                $wHourSql = implode(' AND ', $wHour);
                $stT = $pdo->prepare("SELECT DATE_FORMAT(s.started_at, '%H:00') as time_key,
                                             COALESCE(SUM(ngr.qty_ng), 0) as total_pcs,
                                             COUNT(DISTINCT s.id) as total_lots
                                      FROM inspection_ng_records ngr
                                      INNER JOIN inspection_sessions s ON ngr.inspection_session_id = s.id
                                      LEFT JOIN kanban_items ki ON s.kanban_item_id = ki.id
                                      WHERE {$wHourSql}
                                      GROUP BY time_key");
                $stT->execute($pHour);
                foreach ($stT->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $tk = $r['time_key'];
                    if (isset($trendMap[$tk])) {
                        $trendMap[$tk]['pcs'] = (int)$r['total_pcs'];
                        $trendMap[$tk]['lot'] = (int)$r['total_lots'];
                    }
                }
            } else {
                $wSumT = ["dds.summary_date >= :sd AND dds.summary_date <= :ed"];
                $pSumT = [':sd' => $startDate, ':ed' => $endDate];
                if ($partId > 0) {
                    $wSumT[] = "dds.part_id = :pId";
                    $pSumT[':pId'] = $partId;
                }
                if ($selectedCustomer !== '') {
                    $wSumT[] = "dds.customer = :cust";
                    $pSumT[':cust'] = $selectedCustomer;
                }
                $wSumTSql = implode(' AND ', $wSumT);

                if ($intervalType === 'tahun') {
                    $groupExpr = "DATE_FORMAT(dds.summary_date, '%Y')";
                } elseif ($intervalType === 'hari') {
                    $groupExpr = "dds.summary_date";
                } else {
                    $groupExpr = "DATE_FORMAT(dds.summary_date, '%Y-%m')";
                }

                $stSumT = $pdo->prepare("SELECT {$groupExpr} as time_key,
                                                COALESCE(SUM(dds.qty_ng), 0) as total_pcs,
                                                COALESCE(SUM(dds.lot_count), 0) as total_lots
                                         FROM oqc_daily_defect_summary dds
                                         WHERE {$wSumTSql}
                                         GROUP BY time_key");
                $stSumT->execute($pSumT);
                foreach ($stSumT->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $tk = $r['time_key'];
                    if (isset($trendMap[$tk])) {
                        $trendMap[$tk]['pcs'] += (int)$r['total_pcs'];
                        $trendMap[$tk]['lot'] += (int)$r['total_lots'];
                    }
                }

                if ($endDate >= date('Y-m-d')) {
                    $wActT = [
                        "s.status = 'in_progress'",
                        "(ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)",
                        "s.started_at >= :sd_act AND s.started_at < :ed_act_next"
                    ];
                    $pActT = [
                        ':sd_act'      => $startDate . ' 00:00:00',
                        ':ed_act_next' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'
                    ];
                    if ($partId > 0) {
                        $wActT[] = "ngr.part_id = :pId";
                        $pActT[':pId'] = $partId;
                    }
                    if ($selectedCustomer !== '') {
                        $wActT[] = "COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') = :cust";
                        $pActT[':cust'] = $selectedCustomer;
                    }
                    $wActTSql = implode(' AND ', $wActT);

                    if ($intervalType === 'tahun') {
                        $groupAct = "DATE_FORMAT(s.started_at, '%Y')";
                    } elseif ($intervalType === 'hari') {
                        $groupAct = "DATE_FORMAT(s.started_at, '%Y-%m-%d')";
                    } else {
                        $groupAct = "DATE_FORMAT(s.started_at, '%Y-%m')";
                    }

                    $stActT = $pdo->prepare("SELECT {$groupAct} as time_key,
                                                    COALESCE(SUM(ngr.qty_ng), 0) as total_pcs,
                                                    COUNT(DISTINCT s.id) as total_lots
                                             FROM inspection_ng_records ngr
                                             INNER JOIN inspection_sessions s ON ngr.inspection_session_id = s.id
                                             LEFT JOIN kanban_items ki ON s.kanban_item_id = ki.id
                                             WHERE {$wActTSql}
                                             GROUP BY time_key");
                    $stActT->execute($pActT);
                    foreach ($stActT->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $tk = $r['time_key'];
                        if (isset($trendMap[$tk])) {
                            $trendMap[$tk]['pcs'] += (int)$r['total_pcs'];
                            $trendMap[$tk]['lot'] += (int)$r['total_lots'];
                        }
                    }
                }
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

foreach ($trendMap as $t) {
    $totalPcsAcc  += $t['pcs'];
    $totalLotsAcc += $t['lot'];
    $val = ($selectedUnit === 'lot') ? $t['lot'] : $t['pcs'];
    if ($val > $peakVal) {
        $peakVal   = $val;
        $peakLabel = $t['label'] . ' (' . number_format($val) . ' ' . strtoupper($selectedUnit) . ')';
    }
}
$metricAcc = ($selectedUnit === 'lot') ? $totalLotsAcc : $totalPcsAcc;
$avgPerInterval = ($activeIntervalCount > 0 && $metricAcc > 0) ? round($metricAcc / $activeIntervalCount, 1) : 0;

// Grand Metric for Distribution Percentages
$grandDistMetric = 0;
foreach ($distributionList as $di) {
    $grandDistMetric += ($selectedUnit === 'lot') ? $di['total_lots'] : $di['total_pcs'];
}

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

// KPI Card 2: Puncak Kasus (Peak)
$ws1->mergeCells('E4:H4');
$ws1->setCellValue('E4', 'PUNCAK KASUS (PEAK PERIOD)');
applyStyle($ws1, 'E4', ['font' => ['bold' => true, 'size' => 9, 'color' => ['argb' => 'FFB45309']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFFBEB']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);

$ws1->mergeCells('E5:H5');
$ws1->setCellValue('E5', $peakLabel);
applyStyle($ws1, 'E5', ['font' => ['bold' => true, 'size' => 12, 'color' => ['argb' => 'FFB45309']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFFBEB']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]]);

$ws1->mergeCells('E6:H6');
$ws1->setCellValue('E6', 'Periode dengan intensitas kasus tertinggi');
applyStyle($ws1, 'E6', ['font' => ['italic' => true, 'size' => 8, 'color' => ['argb' => 'FF92400E']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFFBEB']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);
applyStyle($ws1, 'E4:H6', ['borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FFFEF3C7']]]]);

// KPI Card 3: Rata-Rata per Periode
$ws1->mergeCells('I4:M4');
$ws1->setCellValue('I4', 'RATA-RATA TEMUAN PER INTERVAL');
applyStyle($ws1, 'I4', ['font' => ['bold' => true, 'size' => 9, 'color' => ['argb' => 'FF4338CA']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEEF2FF']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);

$ws1->mergeCells('I5:M5');
$ws1->setCellValue('I5', number_format($avgPerInterval, 1) . ' ' . strtoupper($selectedUnit));
applyStyle($ws1, 'I5', ['font' => ['bold' => true, 'size' => 18, 'color' => ['argb' => 'FF4338CA']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEEF2FF']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]]);

$ws1->mergeCells('I6:M6');
$ws1->setCellValue('I6', 'Rata-rata dari ' . $activeIntervalCount . ' interval pemantauan');
applyStyle($ws1, 'I6', ['font' => ['italic' => true, 'size' => 8, 'color' => ['argb' => 'FF3730A3']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEEF2FF']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);
applyStyle($ws1, 'I4:M6', ['borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FFE0E7FF']]]]);

// ── Left Side Panel: Timeline Data Table (Columns A to E, Rows 8+) ───────────
$leftSectionTitle = ($mode === 'overview') ? 'TREN KEJADIAN DEFECT' : 'TREN KEJADIAN PART';
$ws1->mergeCells('A8:E8');
$ws1->setCellValue('A8', $leftSectionTitle . ' SEPANJANG WAKTU');
applyStyle($ws1, 'A8', [
    'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2563EB']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1]
]);

$ws1->setCellValue('A9', 'NO');
$ws1->setCellValue('B9', 'INTERVAL / WAKTU');
$ws1->setCellValue('C9', 'TEMUAN (PCS)');
$ws1->setCellValue('D9', 'KASUS (LOT)');
$ws1->setCellValue('E9', 'AKTIF (' . strtoupper($selectedUnit) . ')');
applyStyle($ws1, 'A9:E9', hdrStyle('0F172A'));

$trRow = 10;
$trendStartRow = $trRow;
$tIndex = 1;

if (empty($trendMap)) {
    $ws1->mergeCells('A10:E10');
    $ws1->setCellValue('A10', 'Tidak ada data fluktuasi waktu pada filter ini.');
    applyStyle($ws1, 'A10:E10', dataStyle('F8FAFC', '94A3B8', false, Alignment::HORIZONTAL_CENTER));
    $trRow++;
} else {
    foreach ($trendMap as $t) {
        $valAct = ($selectedUnit === 'lot') ? $t['lot'] : $t['pcs'];
        $ws1->setCellValue("A{$trRow}", $tIndex++);
        $ws1->setCellValue("B{$trRow}", $t['label']);
        $ws1->setCellValue("C{$trRow}", (int)$t['pcs']);
        $ws1->setCellValue("D{$trRow}", (int)$t['lot']);
        $ws1->setCellValue("E{$trRow}", (int)$valAct);

        $bg = ($tIndex % 2 === 0) ? 'FFFFFF' : 'F8FAFC';
        applyStyle($ws1, "A{$trRow}", dataStyle($bg, '64748B', false, Alignment::HORIZONTAL_CENTER));
        applyStyle($ws1, "B{$trRow}", dataStyle($bg, '0F172A', true, Alignment::HORIZONTAL_LEFT));
        applyStyle($ws1, "C{$trRow}", dataStyle($bg, 'DC2626', false, Alignment::HORIZONTAL_RIGHT));
        applyStyle($ws1, "D{$trRow}", dataStyle($bg, '4338CA', false, Alignment::HORIZONTAL_RIGHT));
        applyStyle($ws1, "E{$trRow}", dataStyle($bg, 'BE123C', true, Alignment::HORIZONTAL_RIGHT));

        $ws1->getStyle("C{$trRow}")->getNumberFormat()->setFormatCode('#,##0');
        $ws1->getStyle("D{$trRow}")->getNumberFormat()->setFormatCode('#,##0');
        $ws1->getStyle("E{$trRow}")->getNumberFormat()->setFormatCode('#,##0');
        $trRow++;
    }
}
$trendEndRow = $trRow - 1;
$trendDataCount = count($trendMap);

// ── Right Side Panel: Distribution Data Table (Columns G to M, Rows 8+) ──────
$rightSectionTitle = ($mode === 'overview') ? 'SEBARAN PART TERDAMPAK' : 'SEBARAN JENIS DEFECT';
$ws1->mergeCells('G8:M8');
$ws1->setCellValue('G8', $rightSectionTitle . ' (TOP RANK & KONTRIBUSI)');
applyStyle($ws1, 'G8', [
    'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF059669']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1]
]);

$ws1->setCellValue('G9', 'NO');
$ws1->setCellValue('H9', ($mode === 'overview') ? 'PART CODE' : 'KODE DEFECT');
$ws1->setCellValue('I9', ($mode === 'overview') ? 'PART NAME' : 'NAMA DEFECT');
$ws1->setCellValue('J9', 'LABEL LENGKAP');
$ws1->setCellValue('K9', 'TEMUAN (PCS)');
$ws1->setCellValue('L9', 'KASUS (LOT)');
$ws1->setCellValue('M9', '% KONTRIBUSI');
applyStyle($ws1, 'G9:M9', hdrStyle('064E3B'));

$distRow = 10;
$distStartRow = $distRow;
$dIndex = 1;

if (empty($distributionList)) {
    $ws1->mergeCells('G10:M10');
    $ws1->setCellValue('G10', 'Tidak ada data sebaran pada filter ini.');
    applyStyle($ws1, 'G10:M10', dataStyle('F8FAFC', '94A3B8', false, Alignment::HORIZONTAL_CENTER));
    $distRow++;
} else {
    foreach ($distributionList as $di) {
        $valAct = ($selectedUnit === 'lot') ? $di['total_lots'] : $di['total_pcs'];
        $share = ($grandDistMetric > 0 && $valAct > 0) ? round(($valAct / $grandDistMetric) * 100, 1) : 0;

        $ws1->setCellValue("G{$distRow}", $dIndex++);
        $ws1->setCellValue("H{$distRow}", $di['item_code']);
        $ws1->setCellValue("I{$distRow}", $di['item_name']);
        $ws1->setCellValue("J{$distRow}", $di['full_label']);
        $ws1->setCellValue("K{$distRow}", (int)$di['total_pcs']);
        $ws1->setCellValue("L{$distRow}", (int)$di['total_lots']);
        $ws1->setCellValue("M{$distRow}", $share / 100);

        $bg = ($dIndex % 2 === 0) ? 'FFFFFF' : 'F8FAFC';
        applyStyle($ws1, "G{$distRow}", dataStyle($bg, '64748B', false, Alignment::HORIZONTAL_CENTER));
        applyStyle($ws1, "H{$distRow}", dataStyle($bg, '1D4ED8', true, Alignment::HORIZONTAL_LEFT));
        applyStyle($ws1, "I{$distRow}", dataStyle($bg, '0F172A', false, Alignment::HORIZONTAL_LEFT));
        applyStyle($ws1, "J{$distRow}", dataStyle($bg, '475569', false, Alignment::HORIZONTAL_LEFT));
        applyStyle($ws1, "K{$distRow}", dataStyle($bg, 'DC2626', false, Alignment::HORIZONTAL_RIGHT));
        applyStyle($ws1, "L{$distRow}", dataStyle($bg, '4338CA', false, Alignment::HORIZONTAL_RIGHT));
        applyStyle($ws1, "M{$distRow}", dataStyle($bg, '047857', true, Alignment::HORIZONTAL_RIGHT));

        $ws1->getStyle("K{$distRow}")->getNumberFormat()->setFormatCode('#,##0');
        $ws1->getStyle("L{$distRow}")->getNumberFormat()->setFormatCode('#,##0');
        $ws1->getStyle("M{$distRow}")->getNumberFormat()->setFormatCode('0.0%');
        $distRow++;
    }
}
$distEndRow = $distRow - 1;
$distDataCount = count($distributionList);

// Hide Column J (Helper label column used cleanly for Donut Chart labels)
$ws1->getColumnDimension('J')->setVisible(false);

// ── Native Excel Charts Placement below the data tables ──────────────────────
$chartStartRow = max($trendEndRow, $distEndRow) + 3;
$chartEndRow   = $chartStartRow + 18;

// 1. Native Excel Line / Column Chart for Timeline Trend (Placed on Left: Cols A to E)
if ($trendDataCount > 0 && $trendEndRow >= $trendStartRow) {
    try {
        $xAxisLabels = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'{$sheet1Title}'!\$B\${$trendStartRow}:\$B\${$trendEndRow}", null, $trendDataCount)
        ];
        $dataValues = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'{$sheet1Title}'!\$E\${$trendStartRow}:\$E\${$trendEndRow}", null, $trendDataCount)
        ];
        $seriesTitle = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'{$sheet1Title}'!\$E\$9", null, 1)
        ];

        $chartType = ($trendDataCount <= 12) ? DataSeries::TYPE_BARCHART : DataSeries::TYPE_LINECHART;
        $barDir    = ($trendDataCount <= 12) ? DataSeries::DIRECTION_COL : null;

        $seriesTrend = new DataSeries(
            $chartType,
            $barDir ? DataSeries::GROUPING_CLUSTERED : DataSeries::GROUPING_STANDARD,
            [0],
            $seriesTitle,
            $xAxisLabels,
            $dataValues
        );
        if ($barDir) $seriesTrend->setPlotDirection($barDir);

        $layoutTrend = new Layout();
        $layoutTrend->setShowVal(true);

        $plotAreaTrend = new PlotArea($layoutTrend, [$seriesTrend]);
        $legendTrend   = new Legend(Legend::POSITION_BOTTOM, null, false);
        $chartTitleTrendText = ($mode === 'overview') 
            ? ("Grafik Tren Fluktuasi Defect: " . $defectName)
            : ("Grafik Tren Fluktuasi Part: " . $partCode);
        $chartTitleTrend = new Title($chartTitleTrendText);
        $chartObjTrend   = new Chart('chart_trend_timeline', $chartTitleTrend, $legendTrend, $plotAreaTrend, true);

        $chartObjTrend->setTopLeftPosition("A{$chartStartRow}");
        $chartObjTrend->setBottomRightPosition("E{$chartEndRow}");
        $ws1->addChart($chartObjTrend);
    } catch (Exception $ex) {}
}

// 2. Native Excel Donut / Pie Chart for Distribution (Placed on Right: Cols G to M)
if ($distDataCount > 0 && $distEndRow >= $distStartRow) {
    try {
        $distValCol = ($selectedUnit === 'lot') ? 'L' : 'K';
        $xAxisLabelsDist = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'{$sheet1Title}'!\$J\${$distStartRow}:\$J\${$distEndRow}", null, $distDataCount)
        ];
        $dataValuesDist = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'{$sheet1Title}'!\${$distValCol}\${$distStartRow}:\${$distValCol}\${$distEndRow}", null, $distDataCount)
        ];
        $seriesTitleDist = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'{$sheet1Title}'!\${$distValCol}\$9", null, 1)
        ];

        $seriesDist = new DataSeries(
            DataSeries::TYPE_DOUGHNUTCHART,
            null,
            range(0, count($dataValuesDist) - 1),
            $seriesTitleDist,
            $xAxisLabelsDist,
            $dataValuesDist
        );

        $layoutDist = new Layout();
        $layoutDist->setShowVal(true);
        $layoutDist->setShowPercent(true);

        $plotAreaDist = new PlotArea($layoutDist, [$seriesDist]);
        $legendDist   = new Legend(Legend::POSITION_RIGHT, null, false);
        $chartTitleDistText = ($mode === 'overview') 
            ? ("Proporsi Sebaran Part Terdampak") 
            : ("Proporsi Sebaran Jenis Defect");
        $chartTitleDist = new Title($chartTitleDistText);
        $chartObjDist   = new Chart('chart_distribution_donut', $chartTitleDist, $legendDist, $plotAreaDist, true);

        $chartObjDist->setTopLeftPosition("G{$chartStartRow}");
        $chartObjDist->setBottomRightPosition("M{$chartEndRow}");
        $ws1->addChart($chartObjDist);
    } catch (Exception $ex) {}
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
