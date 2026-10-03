<?php
/**
 * Official Excel Report Generator — Dashboard Laporan OQC
 * Generates clean XLSX Spreadsheet with KPI Summaries, Source Data Tables,
 * and Large Native Excel Charts (Bar, Line, Pie) with Data Labels & SQL strict compatibility.
 */

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Chart\Layout;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;

$pdo = getDB();

// ── Filter Resolvers ──────────────────────────────────────────────────────
$selectedCustomer = sanitize($_REQUEST['customer'] ?? '');
$selectedUnit     = sanitize($_REQUEST['unit'] ?? 'pcs');
if (!in_array($selectedUnit, ['pcs', 'lot'])) $selectedUnit = 'pcs';

$selectedResult = sanitize($_REQUEST['result'] ?? 'rejected');
if (!in_array($selectedResult, ['rejected', 'passed'])) $selectedResult = 'rejected';

$selectedMetricScale = strtolower(sanitize($_REQUEST['metric_scale'] ?? 'ppm'));
if (!in_array($selectedMetricScale, ['ppm', 'pct', '%', 'ppb'])) $selectedMetricScale = 'ppm';
if ($selectedMetricScale === 'ppb') $selectedMetricScale = 'pct';
$metricMultiplier    = ($selectedMetricScale === 'pct' || $selectedMetricScale === '%') ? 100 : 1000000;
$metricScaleLabel    = ($selectedMetricScale === 'pct' || $selectedMetricScale === '%') ? '%' : strtoupper($selectedMetricScale);
$selectedTopRank     = isset($_REQUEST['top_rank']) && in_array((int)$_REQUEST['top_rank'], [3, 5, 10]) ? (int)$_REQUEST['top_rank'] : 5;

$selectedDefectUnit  = strtolower(sanitize($_REQUEST['defect_unit'] ?? 'pcs'));
if (!in_array($selectedDefectUnit, ['pcs', 'ppm'])) $selectedDefectUnit = 'pcs';
$defectUnitLabel     = strtoupper($selectedDefectUnit);

$dbYears = [];
if ($pdo) {
    try {
        $stmtYears = $pdo->query("SELECT DISTINCT YEAR(started_at) AS y FROM inspection_sessions WHERE started_at IS NOT NULL ORDER BY y ASC");
        $dbYears = array_values(array_filter(array_map('intval', $stmtYears->fetchAll(PDO::FETCH_COLUMN))));
    } catch (Exception $e) {}
}
if (empty($dbYears)) {
    $dbYears = [(int)date('Y')];
}

$monthNames = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

$filterType = sanitize($_REQUEST['filter_type'] ?? '');
$selectedMonth = isset($_REQUEST['month']) && is_numeric($_REQUEST['month']) ? (int)$_REQUEST['month'] : (int)date('n');
$selectedYear  = isset($_REQUEST['year']) && is_numeric($_REQUEST['year']) ? (int)$_REQUEST['year'] : (int)date('Y');

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
} elseif ($filterType === 'month_year') {
    $startDate = sprintf('%04d-%02d-01', $selectedYear, $selectedMonth);
    $endDate   = date('Y-m-t', strtotime($startDate));
    $presetFilter = 'month_year';
    $filterTitleDesc = "Bulan " . ($monthNames[$selectedMonth] ?? $selectedMonth) . " " . $selectedYear;
} elseif ($filterType === 'custom' || (!empty($_REQUEST['start_date']) && !empty($_REQUEST['end_date']))) {
    $startDate    = sanitize($_REQUEST['start_date'] ?? date('Y-m-01'));
    $endDate      = sanitize($_REQUEST['end_date'] ?? date('Y-m-d'));
    $presetFilter = 'custom';
    $filterTitleDesc = date('d/m/Y', strtotime($startDate)) . ' s.d. ' . date('d/m/Y', strtotime($endDate));
} else {
    $presetFilter = sanitize($_REQUEST['preset'] ?? 'bulanan');
    switch ($presetFilter) {
        case 'hari_ini':
            $startDate = date('Y-m-d');
            $endDate   = date('Y-m-d');
            $filterTitleDesc = "Hari Ini (" . date('d/m/Y') . ")";
            break;
        case 'mingguan':
            $startDate = date('Y-m-d', strtotime('monday this week'));
            $endDate   = date('Y-m-d');
            $filterTitleDesc = "Minggu Ini (" . date('d/m', strtotime($startDate)) . " - " . date('d/m/Y', strtotime($endDate)) . ")";
            break;
        case 'tahunan':
            $startDate = date('Y-01-01');
            $endDate   = date('Y-m-d');
            $filterTitleDesc = "Tahun Ini (" . date('Y') . ")";
            break;
        default:
            $presetFilter = 'bulanan';
            $startDate = date('Y-m-01');
            $endDate   = date('Y-m-d');
            $filterTitleDesc = "Bulan Ini (" . date('M Y') . ")";
    }
}

// ── Data Fetch ──────────────────────────────────────────────────────────────
$kpi = ['total_inspected'=>0,'pass_rate'=>0,'total_ng'=>0,'total_lot'=>0,'pass_count'=>0,'rejected_count'=>0];
$trendMap = []; $defectRows = []; $modelRows = []; $partRows = [];
$worstPartsGrouped = []; $bestPartsPassed = [];

if ($pdo) {
    try {
        $edNext = date('Y-m-d', strtotime($endDate . ' +1 day'));
        $p = [':sd' => $startDate, ':ed_next' => $edNext];
        $sumCustCond = "";

        if ($selectedCustomer !== '') {
            $sumCustCond = " AND customer = :cust ";
            $p[':cust'] = $selectedCustomer;
        }

        // 1, 2, 3: Total Inspected, Lots, NG
        $stmtKpi = $pdo->prepare("SELECT 
                                    COALESCE(SUM(total_sample_size), 0) AS total_inspected,
                                    COALESCE(SUM(total_lots), 0) AS total_lot,
                                    COALESCE(SUM(passed_lots), 0) AS pass_count,
                                    COALESCE(SUM(rejected_lots), 0) AS rejected_count,
                                    COALESCE(SUM(total_ng_samples), 0) AS total_ng
                                  FROM oqc_daily_summary
                                  WHERE summary_date >= :sd AND summary_date < :ed_next {$sumCustCond}");
        $stmtKpi->execute($p);
        $rowKpi = $stmtKpi->fetch(PDO::FETCH_ASSOC);

        $kpi['total_inspected'] = (int)($rowKpi['total_inspected'] ?? 0);
        $kpi['total_lot']       = (int)($rowKpi['total_lot'] ?? 0);
        $kpi['pass_count']      = (int)($rowKpi['pass_count'] ?? 0);
        $kpi['rejected_count']  = (int)($rowKpi['rejected_count'] ?? 0);
        $kpi['pass_rate']       = $kpi['total_lot'] > 0 ? round($kpi['pass_count'] / $kpi['total_lot'] * 100, 1) : 0;
        $kpi['total_ng']        = (int)($rowKpi['total_ng'] ?? 0);

        // 4. Defect Rate Calculation (PPM / Persen)
        $kpi['ppm_rate']    = $kpi['total_inspected'] > 0 ? round(($kpi['total_ng'] / $kpi['total_inspected']) * 1000000, 1) : 0;
        $kpi['pct_rate']    = $kpi['total_inspected'] > 0 ? round(($kpi['total_ng'] / $kpi['total_inspected']) * 100, 2) : 0;
        $kpi['rate_active'] = ($selectedMetricScale === 'pct' || $selectedMetricScale === '%') ? $kpi['pct_rate'] : $kpi['ppm_rate'];
        $kpi['ng_rate']     = $kpi['rate_active'];

        // 4. Trend Data (PPM Defect Rate / Sampling Performance)
        $daysDiff = (strtotime($endDate) - strtotime($startDate)) / 86400;
        if ($presetFilter === 'all_years') {
            $minYear = min($dbYears);
            $maxYear = max($dbYears);
            if ($minYear === $maxYear) {
                $minYear = $maxYear - 1;
            }
            $yearsToDisplay = range($minYear, $maxYear);
            foreach ($yearsToDisplay as $yr) {
                $trendMap[(string)$yr] = ['label' => (string)$yr, 'sample' => 0, 'ng' => 0, 'val' => 0];
            }
            $qTrend = "SELECT YEAR(summary_date) AS k, 
                              COALESCE(SUM(total_sample_size), 0) AS total_sample,
                              COALESCE(SUM(total_ng_samples), 0) AS total_ng
                       FROM oqc_daily_summary
                       WHERE summary_date >= :sd AND summary_date < :ed_next {$sumCustCond}
                       GROUP BY k";
        } elseif ($presetFilter === 'tahunan' || $daysDiff > 90) {
            $sp = new DateTime(date('Y-m-01', strtotime($startDate)));
            $ep = new DateTime(date('Y-m-01', strtotime($endDate))); $ep->modify('+1 month');
            foreach (new DatePeriod($sp, DateInterval::createFromDateString('1 month'), $ep) as $dt)
                $trendMap[$dt->format('Y-m')] = ['label' => $dt->format('M Y'), 'sample' => 0, 'ng' => 0, 'val' => 0];
            $qTrend = "SELECT DATE_FORMAT(summary_date, '%Y-%m') AS k, 
                              COALESCE(SUM(total_sample_size), 0) AS total_sample,
                              COALESCE(SUM(total_ng_samples), 0) AS total_ng
                       FROM oqc_daily_summary
                       WHERE summary_date >= :sd AND summary_date < :ed_next {$sumCustCond}
                       GROUP BY k";
        } else {
            $sp = new DateTime($startDate); $ep = new DateTime($endDate); $ep->modify('+1 day');
            foreach (new DatePeriod($sp, DateInterval::createFromDateString('1 day'), $ep) as $dt)
                $trendMap[$dt->format('Y-m-d')] = ['label' => $dt->format('d M'), 'sample' => 0, 'ng' => 0, 'val' => 0];
            $qTrend = "SELECT summary_date AS k, 
                              COALESCE(SUM(total_sample_size), 0) AS total_sample,
                              COALESCE(SUM(total_ng_samples), 0) AS total_ng
                       FROM oqc_daily_summary
                       WHERE summary_date >= :sd AND summary_date < :ed_next {$sumCustCond}
                       GROUP BY k";
        }

        $stTrend = $pdo->prepare($qTrend);
        $stTrend->execute($p);
        foreach ($stTrend->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = (string)$r['k'];
            if (isset($trendMap[$k])) {
                $trendMap[$k]['sample'] = (int)$r['total_sample'];
                $trendMap[$k]['ng']     = (int)$r['total_ng'];
            }
        }

        // 3. Compute PPM / PPB
        foreach ($trendMap as $k => $item) {
            if ($selectedResult === 'passed') {
                $passedCount = max(0, $item['sample'] - $item['ng']);
                $trendMap[$k]['val'] = $item['sample'] > 0 ? round(($passedCount / $item['sample']) * $metricMultiplier, 1) : 0;
            } else {
                $trendMap[$k]['val'] = $item['sample'] > 0 ? round(($item['ng'] / $item['sample']) * $metricMultiplier, 1) : 0;
            }
        }

        // 5. Defect Breakdown
        if ($selectedResult === 'passed') {
            $passedPcs = max(0, $kpi['total_inspected'] - $kpi['total_ng']);
            $defectRows = [
                ['name' => 'Passed (Lulus)', 'total' => $selectedUnit === 'lot' ? $kpi['pass_count'] : $passedPcs],
                ['name' => 'Rejected (Ditolak)', 'total' => $selectedUnit === 'lot' ? $kpi['rejected_count'] : $kpi['total_ng']]
            ];
        } else {
            $qD = $selectedUnit === 'lot'
                ? "SELECT dt.name, COALESCE(SUM(dds.lot_count),0) AS total FROM oqc_daily_defect_summary dds INNER JOIN defect_types dt ON dds.defect_type_id=dt.id WHERE dds.summary_date >= :sd AND dds.summary_date < :ed_next {$sumCustCond} GROUP BY dt.id,dt.name ORDER BY total DESC LIMIT {$selectedTopRank}"
                : "SELECT dt.name, COALESCE(SUM(dds.qty_ng),0) AS total FROM oqc_daily_defect_summary dds INNER JOIN defect_types dt ON dds.defect_type_id=dt.id WHERE dds.summary_date >= :sd AND dds.summary_date < :ed_next {$sumCustCond} GROUP BY dt.id,dt.name ORDER BY total DESC LIMIT {$selectedTopRank}";
            $stD = $pdo->prepare($qD); $stD->execute($p); $defectRows = $stD->fetchAll(PDO::FETCH_ASSOC);
        }

        // 5b. Defect Breakdown Timeline Matrix (Transposed Matrix Data)
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

        // Filter timeline to active dates with records
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
        $topNDefectTypes = array_slice($distinctDefectTypes, 0, $selectedTopRank);

        $grandTotSample = 0;
        foreach ($activeDefectPeriodKeys as $dk) {
            $grandTotSample += (int)($trendMap[$dk]['sample'] ?? 0);
        }

        // 6. Model Breakdown (SQL strict mode safe)
        try {
            if ($selectedResult === 'passed') {
                $qM = "SELECT COALESCE(m.name, mp.model, 'NO MODEL') AS model_name, SUM(s.passed_lots) AS total FROM oqc_daily_summary s LEFT JOIN master_parts mp ON s.part_id=mp.id LEFT JOIN master_models m ON s.model_id=m.id WHERE s.summary_date >= :sd AND s.summary_date < :ed_next {$sumCustCond} GROUP BY m.id, model_name HAVING total > 0 ORDER BY total DESC LIMIT {$selectedTopRank}";
            } else {
                $qM = ($selectedUnit === 'lot')
                    ? "SELECT COALESCE(m.name, mp.model, 'NO MODEL') AS model_name, SUM(s.rejected_lots) AS total FROM oqc_daily_summary s LEFT JOIN master_parts mp ON s.part_id=mp.id LEFT JOIN master_models m ON s.model_id=m.id WHERE s.summary_date >= :sd AND s.summary_date < :ed_next {$sumCustCond} GROUP BY m.id, model_name HAVING total > 0 ORDER BY total DESC LIMIT {$selectedTopRank}"
                    : "SELECT COALESCE(m.name, mp.model, 'NO MODEL') AS model_name, SUM(s.total_ng_samples) AS total FROM oqc_daily_summary s LEFT JOIN master_parts mp ON s.part_id=mp.id LEFT JOIN master_models m ON s.model_id=m.id WHERE s.summary_date >= :sd AND s.summary_date < :ed_next {$sumCustCond} GROUP BY m.id, model_name HAVING total > 0 ORDER BY total DESC LIMIT {$selectedTopRank}";
            }
            $stM = $pdo->prepare($qM);
            $stM->execute($p);
            $rawModelRows = $stM->fetchAll(PDO::FETCH_ASSOC);
            $modelRows = [];
            foreach ($rawModelRows as $rm) {
                $modelRows[] = [
                    'name'  => $rm['model_name'],
                    'total' => (int)$rm['total']
                ];
            }
        } catch (PDOException $e) {
            error_log("Export Excel Model Query Error: " . $e->getMessage());
        }

        // 7. Part Breakdown (SQL strict mode safe)
        try {
            if ($selectedResult === 'passed') {
                $qP = "SELECT COALESCE(mp.part_code,'-') AS part_code, COALESCE(mp.part_name,'UNKNOWN PART') AS part_name, SUM(s.passed_lots) AS total FROM oqc_daily_summary s LEFT JOIN master_parts mp ON s.part_id=mp.id WHERE s.summary_date >= :sd AND s.summary_date < :ed_next {$sumCustCond} GROUP BY s.part_id, mp.part_code, mp.part_name HAVING total > 0 ORDER BY total DESC LIMIT {$selectedTopRank}";
            } else {
                $qP = ($selectedUnit === 'lot')
                    ? "SELECT COALESCE(mp.part_code,'-') AS part_code, COALESCE(mp.part_name,'UNKNOWN PART') AS part_name, SUM(s.rejected_lots) AS total FROM oqc_daily_summary s LEFT JOIN master_parts mp ON s.part_id=mp.id WHERE s.summary_date >= :sd AND s.summary_date < :ed_next {$sumCustCond} GROUP BY s.part_id, mp.part_code, mp.part_name HAVING total > 0 ORDER BY total DESC LIMIT {$selectedTopRank}"
                    : "SELECT COALESCE(mp.part_code,'-') AS part_code, COALESCE(mp.part_name,'UNKNOWN PART') AS part_name, SUM(s.total_ng_samples) AS total FROM oqc_daily_summary s LEFT JOIN master_parts mp ON s.part_id=mp.id WHERE s.summary_date >= :sd AND s.summary_date < :ed_next {$sumCustCond} GROUP BY s.part_id, mp.part_code, mp.part_name HAVING total > 0 ORDER BY total DESC LIMIT {$selectedTopRank}";
            }
            $stP = $pdo->prepare($qP);
            $stP->execute($p);
            $partRows = $stP->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Export Excel Part Query Error: " . $e->getMessage());
        }

        // 8. Worst / Best Parts (SQL strict mode safe)
        try {
            if ($selectedResult === 'passed') {
                $stB = $pdo->prepare("SELECT COALESCE(mp.part_name,'UNKNOWN PART') AS part_name, COALESCE(mp.part_code,'-') AS part_code, COALESCE(m.name,mp.model,'-') AS model, SUM(s.total_lots) AS lot_case, SUM(s.passed_lots) AS passed_lot_case FROM oqc_daily_summary s LEFT JOIN master_parts mp ON s.part_id=mp.id LEFT JOIN master_models m ON s.model_id=m.id WHERE s.summary_date >= :sd AND s.summary_date < :ed_next {$sumCustCond} GROUP BY mp.id, mp.part_name, mp.part_code, m.id, m.name, mp.model HAVING passed_lot_case>0 ORDER BY passed_lot_case DESC,lot_case DESC LIMIT 5");
                $stB->execute($p);
                $bestPartsPassed = $stB->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $stWD = $pdo->prepare("SELECT dt.name FROM oqc_daily_defect_summary dds INNER JOIN defect_types dt ON dds.defect_type_id=dt.id WHERE dds.summary_date >= :sd AND dds.summary_date < :ed_next {$sumCustCond} GROUP BY dt.id,dt.name ORDER BY COALESCE(SUM(dds.qty_ng),0) DESC LIMIT 3");
                $stWD->execute($p);
                $top3 = $stWD->fetchAll(PDO::FETCH_COLUMN);
                foreach ($top3 as $dn) {
                    $pD = array_merge($p, [':defname' => $dn]);
                    $stW = $pdo->prepare("SELECT COALESCE(mp.part_name,'UNKNOWN PART') AS part_name, COALESCE(mp.part_code,'-') AS part_code, COALESCE(m.name,mp.model,'-') AS model, SUM(dds.lot_count) AS lot_case, SUM(dds.lot_count) AS ng_case FROM oqc_daily_defect_summary dds INNER JOIN defect_types dt ON dds.defect_type_id=dt.id LEFT JOIN master_parts mp ON dds.part_id=mp.id LEFT JOIN master_models m ON mp.model_id=m.id WHERE dt.name=:defname AND dds.summary_date >= :sd AND dds.summary_date < :ed_next {$sumCustCond} GROUP BY mp.id, mp.part_name, mp.part_code, m.id, m.name, mp.model ORDER BY ng_case DESC,lot_case DESC LIMIT 3");
                    $stW->execute($pD);
                    $worstPartsGrouped[$dn] = $stW->fetchAll(PDO::FETCH_ASSOC);
                }
            }
        } catch (PDOException $e) {
            error_log("Export Excel Worst/Best Parts Query Error: " . $e->getMessage());
        }
    } catch (PDOException $e) {
        error_log("Export Excel General Query Error: " . $e->getMessage());
    }
}

$unitText   = ($selectedUnit === 'lot') ? 'Lot' : 'Pcs';
$statusText = strtoupper($selectedResult);

// ─────────────────────────────────────────────────────────────────────────────
// BUILD SPREADSHEET
// ─────────────────────────────────────────────────────────────────────────────
$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator("OQC System")
    ->setTitle("OQC Dashboard Report")
    ->setSubject("Laporan Dashboard Inspeksi OQC")
    ->setCompany("PT. Surya Technology Industri");

// ── Helper Styles ────────────────────────────────────────────────────────────
function applyStyle($ws, $range, array $s) { $ws->getStyle($range)->applyFromArray($s); }

function hdrStyle($bg, $fg='FFFFFF') {
    return [
        'font'      => ['bold'=>true,'color'=>['argb'=>'FF'.$fg],'size'=>11],
        'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FF'.$bg]],
        'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],
        'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['argb'=>'FFB0BEC5']]],
    ];
}

function dataStyle($bg='F8FAFC', $fg='1E293B', $bold=false, $align=Alignment::HORIZONTAL_LEFT) {
    return [
        'font'      => ['bold'=>$bold,'color'=>['argb'=>'FF'.$fg],'size'=>10],
        'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FF'.$bg]],
        'alignment' => ['horizontal'=>$align,'vertical'=>Alignment::VERTICAL_CENTER,'wrapText'=>true],
        'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['argb'=>'FFE2E8F0']]],
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// SHEET 1: SUMMARY (KPI + Info)
// ═══════════════════════════════════════════════════════════════════════════
$ws1 = $spreadsheet->getActiveSheet();
$ws1->setTitle("Summary & KPI");

$ws1->getRowDimension(1)->setRowHeight(40);
$ws1->getRowDimension(2)->setRowHeight(22);

foreach (['A'=>28,'B'=>24,'C'=>22,'D'=>22,'E'=>22,'F'=>22] as $col=>$w)
    $ws1->getColumnDimension($col)->setWidth($w);

$ws1->mergeCells('A1:F1');
$ws1->setCellValue('A1', 'PT. SURYA TECHNOLOGY INDUSTRI — QUALITY CONTROL DIVISION');
applyStyle($ws1,'A1',['font'=>['bold'=>true,'size'=>16,'color'=>['argb'=>'FF0F172A']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FFDBEAFE']],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],'borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['argb'=>'FF93C5FD']]]]);

$ws1->mergeCells('A2:F2');
$ws1->setCellValue('A2', 'REKAPITULASI DASHBOARD INSPEKSI OQC  |  PERIODE: '.strtoupper($filterTitleDesc).'  |  STATUS: '.$statusText.'  |  SATUAN: '.strtoupper($selectedUnit));
applyStyle($ws1,'A2',['font'=>['bold'=>true,'size'=>10,'color'=>['argb'=>'FF1E40AF']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FFEFF6FF']],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER]]);

$ws1->setCellValue('A4','METRIC KPI');
$ws1->setCellValue('B4','NILAI');
applyStyle($ws1,'A4:B4',hdrStyle('0F172A'));

$kpiData = [
    ['Total Inspected (Pcs)', number_format($kpi['total_inspected']).' pcs'],
    ['Total NG Inspected (Pcs)', number_format($kpi['total_ng']).' pcs'],
    ['Defect Rate ('.$metricScaleLabel.')', number_format($kpi['rate_active'], 1).' '.$metricScaleLabel],
    ['Total LOT Session', number_format($kpi['total_lot']).' lot'],
    ['Total NG Lot (Rejected)', number_format($kpi['rejected_count']).' lot'],
    ['Customer Filter', $selectedCustomer ?: 'Semua Customer'],
    ['Periode', $filterTitleDesc.' ('.date('d/m/Y', strtotime($startDate)).' s.d '.date('d/m/Y', strtotime($endDate)).')'],
    ['Dicetak pada', date('d/m/Y H:i:s').' WIB'],
];

$row = 5;
foreach ($kpiData as $i => [$label,$val]) {
    $ws1->setCellValue("A{$row}", $label);
    $ws1->setCellValue("B{$row}", $val);
    $rowBg = ($i % 2 === 0) ? 'F8FAFC' : 'FFFFFF';
    $valBg = $rowBg; $valFg = '1E293B'; $bold = false;
    if (strpos($label, 'Defect Rate') !== false) { $valFg = ($kpi['rate_active'] == 0) ? '059669' : (($kpi['rate_active'] <= 10000.0) ? 'D97706' : 'DC2626'); $bold = true; }
    if ($label === 'Total NG Inspected (Pcs)') { $valFg = 'DC2626'; $bold = true; }
    if ($label === 'Total NG Lot (Rejected)') { $valBg = 'FFF1F2'; $valFg = 'DC2626'; $bold = true; }
    applyStyle($ws1,"A{$row}",dataStyle($rowBg,'334155',true));
    applyStyle($ws1,"B{$row}",dataStyle($valBg,$valFg,$bold,Alignment::HORIZONTAL_RIGHT));
    $row++;
}

// ═══════════════════════════════════════════════════════════════════════════
// SHEET 2: DATA TREN (Source for Bar/Line Chart)
// ═══════════════════════════════════════════════════════════════════════════
$ws2 = $spreadsheet->createSheet();
$ws2->setTitle("Tren Waktu");

$ws2->getColumnDimension('A')->setWidth(8);
$ws2->getColumnDimension('B')->setWidth(18);
$ws2->getColumnDimension('C')->setWidth(22);
$ws2->getColumnDimension('D')->setWidth(18);
$ws2->getColumnDimension('E')->setWidth(22);
$ws2->getRowDimension(1)->setRowHeight(30);

$trendTitle = ($selectedResult === 'passed' ? 'PASSED / YIELD RATE ('.$metricScaleLabel.')' : 'DEFECT RATE ('.$metricScaleLabel.')') . ' PER WAKTU';
$ws2->mergeCells('A1:E1');
$ws2->setCellValue('A1', 'DATA TREN KUALITAS INSPEKSI — '.$trendTitle);
applyStyle($ws2,'A1',hdrStyle('0F172A'));

$ws2->setCellValue('A2','NO');
$ws2->setCellValue('B2','PERIODE');
$ws2->setCellValue('C2','SAMPLING (PCS)');
$ws2->setCellValue('D2','NG (PCS)');
$ws2->setCellValue('E2', $selectedResult === 'passed' ? 'YIELD ('.$metricScaleLabel.')' : 'DEFECT RATE ('.$metricScaleLabel.')');
applyStyle($ws2,'A2:E2',hdrStyle('334155'));

$tRow = 3; $tNo = 1;
$trendDataStartRow = $tRow;
foreach ($trendMap as $tVal) {
    $ws2->setCellValue("A{$tRow}", $tNo++);
    $ws2->setCellValue("B{$tRow}", $tVal['label']);
    $ws2->setCellValue("C{$tRow}", (int)$tVal['sample']);
    $ws2->setCellValue("D{$tRow}", (int)$tVal['ng']);
    $ws2->setCellValue("E{$tRow}", (float)$tVal['val']);
    $bg = ($tNo % 2 === 0) ? 'F8FAFC' : 'FFFFFF';
    applyStyle($ws2,"A{$tRow}",dataStyle($bg,'64748B',false,Alignment::HORIZONTAL_CENTER));
    applyStyle($ws2,"B{$tRow}",dataStyle($bg,'1E293B',true));
    applyStyle($ws2,"C{$tRow}",dataStyle($bg,'334155',false,Alignment::HORIZONTAL_RIGHT));
    applyStyle($ws2,"D{$tRow}",dataStyle($bg,'DC2626',false,Alignment::HORIZONTAL_RIGHT));
    applyStyle($ws2,"E{$tRow}",dataStyle($bg,'1D4ED8',true,Alignment::HORIZONTAL_RIGHT));
    $tRow++;
}
$trendDataEndRow = $tRow - 1;

// Bar/Line Chart: Tren Waktu
$trendCount = count($trendMap);
if ($trendCount > 0) {
    $chartType = ($trendCount <= 10) ? DataSeries::TYPE_BARCHART : DataSeries::TYPE_LINECHART;
    $barDir    = ($trendCount <= 10) ? DataSeries::DIRECTION_COL : null;

    $labelsRef  = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Tren Waktu'!\$B\${$trendDataStartRow}:\$B\${$trendDataEndRow}", null, $trendCount);
    $dataValues = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'Tren Waktu'!\$E\${$trendDataStartRow}:\$E\${$trendDataEndRow}", null, $trendCount);
    $seriesLabel = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Tren Waktu'!\$E\$2", null, 1);
    if ($selectedResult === 'passed') {
        $dataValues->setFillColor('059669');
    } else {
        $dataValues->setFillColor('2563EB');
    }

    $series = new DataSeries($chartType, $barDir ? DataSeries::GROUPING_CLUSTERED : DataSeries::GROUPING_STANDARD, [0], [$seriesLabel], [$labelsRef], [$dataValues]);
    if ($barDir) $series->setPlotDirection($barDir);

    $tLayout = new Layout();
    $tLayout->setShowVal(true);

    $plotArea  = new PlotArea($tLayout, [$series]);
    $legend    = new Legend(Legend::POSITION_BOTTOM, null, false);
    $chartObj  = new Chart('chartTrend', new Title($trendTitle), $legend, $plotArea, true);
    $chartObj->setTopLeftPosition('G2');
    $chartObj->setBottomRightPosition('P'.max(18, $trendDataEndRow + 3));
    $ws2->addChart($chartObj);
}

// ═══════════════════════════════════════════════════════════════════════════
// SHEET 3: TOP DEFECT MATRIKS (Transpose Timeline Matrix per Tanggal)
// ═══════════════════════════════════════════════════════════════════════════
$ws3 = $spreadsheet->createSheet();
$ws3->setTitle("Top Defect Matriks");

$numActiveDates = count($activeDefectPeriodKeys);
$totalColIdx = 2 + $numActiveDates + 1; // Col 1: NO, Col 2: JENIS NG, then dates, then TOTAL
$totalColLetter = Coordinate::stringFromColumnIndex($totalColIdx);

$ws3->getColumnDimension('A')->setWidth(8);
$ws3->getColumnDimension('B')->setWidth(30);
for ($i = 0; $i < $numActiveDates; $i++) {
    $cLetter = Coordinate::stringFromColumnIndex(3 + $i);
    $ws3->getColumnDimension($cLetter)->setWidth(14);
}
$ws3->getColumnDimension($totalColLetter)->setWidth(16);

$primaryUnit = $selectedDefectUnit; // 'pcs' or 'ppm'
$companionUnit = ($primaryUnit === 'ppm') ? 'pcs' : 'ppm';

$defTitle = ($selectedResult === 'passed')
    ? "TOP {$selectedTopRank} HASIL (" . strtoupper($primaryUnit) . ") — DISTRIBUSI PER TANGGAL"
    : "TOP {$selectedTopRank} DEFECT (" . strtoupper($primaryUnit) . ") — DISTRIBUSI PER TANGGAL";

if ($numActiveDates === 0 || (empty($topNDefectTypes) && $selectedResult !== 'passed')) {
    $ws3->mergeCells('A1:D1');
    $ws3->setCellValue('A1', $defTitle);
    applyStyle($ws3, 'A1', hdrStyle('0F172A'));
    $ws3->setCellValue('A2', 'Tidak ada catatan defect pada periode ini');
    applyStyle($ws3, 'A2:D2', dataStyle('F8FAFC', '64748B', false, Alignment::HORIZONTAL_CENTER));
} else {
    // Helper function to render a transpose matrix table
    $renderMatrixTable = function($sheet, $startRow, $metricUnit, $tblTitle, $isTopTbl) use (
        $selectedResult, $unitText, $topNDefectTypes, $activeDefectPeriodKeys, $stackedPeriodLabels,
        $defectMatrix, $defectTypeTotals, $trendMap, $grandTotSample, $totalColIdx, $totalColLetter
    ) {
        $r = $startRow;
        $sheet->mergeCells("A{$r}:{$totalColLetter}{$r}");
        $sheet->setCellValue("A{$r}", $tblTitle);
        applyStyle($sheet, "A{$r}", hdrStyle($isTopTbl ? '0F172A' : '334155'));
        $sheet->getRowDimension($r)->setRowHeight(26);
        $r++;

        // Header
        $sheet->setCellValue("A{$r}", "NO");
        $sheet->setCellValue("B{$r}", $selectedResult === 'passed' ? "STATUS HASIL" : "JENIS NG");
        foreach ($activeDefectPeriodKeys as $idx => $dk) {
            $colLet = Coordinate::stringFromColumnIndex(3 + $idx);
            $sheet->setCellValue("{$colLet}{$r}", $stackedPeriodLabels[$dk] ?? $dk);
        }
        $sheet->setCellValue("{$totalColLetter}{$r}", "TOTAL (" . strtoupper($metricUnit) . ")");
        applyStyle($sheet, "A{$r}:{$totalColLetter}{$r}", hdrStyle('1E293B'));
        $sheet->getRowDimension($r)->setRowHeight(22);
        $headerRow = $r;
        $r++;

        $dataStartRow = $r;

        if ($selectedResult === 'passed') {
            $passRows = [
                ['label' => 'Passed (Lulus)', 'key' => 'passed', 'color' => '059669', 'bg' => 'F0FDF4'],
                ['label' => 'Rejected (Ditolak)', 'key' => 'rejected', 'color' => 'DC2626', 'bg' => 'FFF1F2']
            ];
            foreach ($passRows as $pIdx => $prInfo) {
                $isPass = ($prInfo['key'] === 'passed');
                $sheet->setCellValue("A{$r}", $pIdx + 1);
                $sheet->setCellValue("B{$r}", $prInfo['label']);
                applyStyle($sheet, "A{$r}", dataStyle($prInfo['bg'], '64748B', false, Alignment::HORIZONTAL_CENTER));
                applyStyle($sheet, "B{$r}", dataStyle($prInfo['bg'], $prInfo['color'], true));

                $rowSumPcs = 0;
                foreach ($activeDefectPeriodKeys as $kIdx => $dk) {
                    $colLet = Coordinate::stringFromColumnIndex(3 + $kIdx);
                    $s = (int)($trendMap[$dk]['sample'] ?? 0);
                    $n = (int)($trendMap[$dk]['ng'] ?? 0);
                    $valPcs = $isPass ? max(0, $s - $n) : $n;
                    $rowSumPcs += $valPcs;
                    $valPpm = ($s > 0) ? round(($valPcs / $s) * 1000000, 1) : 0;
                    $cellVal = ($metricUnit === 'ppm') ? $valPpm : $valPcs;

                    $sheet->setCellValue("{$colLet}{$r}", $cellVal);
                    applyStyle($sheet, "{$colLet}{$r}", dataStyle($prInfo['bg'], '0F172A', false, Alignment::HORIZONTAL_RIGHT));
                }
                $rowSumPpm = ($grandTotSample > 0) ? round(($rowSumPcs / $grandTotSample) * 1000000, 1) : 0;
                $totVal = ($metricUnit === 'ppm') ? $rowSumPpm : $rowSumPcs;
                $sheet->setCellValue("{$totalColLetter}{$r}", $totVal);
                applyStyle($sheet, "{$totalColLetter}{$r}", dataStyle($prInfo['bg'], $prInfo['color'], true, Alignment::HORIZONTAL_RIGHT));
                $r++;
            }
        } else {
            foreach ($topNDefectTypes as $dIdx => $dname) {
                $bg = ($dIdx % 2 === 0) ? 'FFFFFF' : 'F8FAFC';
                $sheet->setCellValue("A{$r}", $dIdx + 1);
                $sheet->setCellValue("B{$r}", $dname);
                applyStyle($sheet, "A{$r}", dataStyle($bg, '64748B', false, Alignment::HORIZONTAL_CENTER));
                applyStyle($sheet, "B{$r}", dataStyle($bg, '1E293B', true));

                $rowSumPcs = (int)($defectTypeTotals[$dname] ?? 0);
                foreach ($activeDefectPeriodKeys as $kIdx => $dk) {
                    $colLet = Coordinate::stringFromColumnIndex(3 + $kIdx);
                    $valPcs = (int)($defectMatrix[$dk][$dname] ?? 0);
                    $sample = (int)($trendMap[$dk]['sample'] ?? 0);
                    $valPpm = ($sample > 0) ? round(($valPcs / $sample) * 1000000, 1) : 0;
                    $cellVal = ($metricUnit === 'ppm') ? $valPpm : $valPcs;

                    $sheet->setCellValue("{$colLet}{$r}", $cellVal);
                    $valColor = ($valPcs > 0) ? '0F172A' : '94A3B8';
                    applyStyle($sheet, "{$colLet}{$r}", dataStyle($bg, $valColor, ($valPcs > 0), Alignment::HORIZONTAL_RIGHT));
                }
                $rowSumPpm = ($grandTotSample > 0) ? round(($rowSumPcs / $grandTotSample) * 1000000, 1) : 0;
                $totVal = ($metricUnit === 'ppm') ? $rowSumPpm : $rowSumPcs;
                $sheet->setCellValue("{$totalColLetter}{$r}", $totVal);
                applyStyle($sheet, "{$totalColLetter}{$r}", dataStyle('FFF1F2', 'DC2626', true, Alignment::HORIZONTAL_RIGHT));
                $r++;
            }
        }
        $dataEndRow = $r - 1;

        // Footer Row: TOTAL
        $sheet->setCellValue("A{$r}", "TOTAL");
        $sheet->setCellValue("B{$r}", "");
        $sheet->mergeCells("A{$r}:B{$r}");
        applyStyle($sheet, "A{$r}:B{$r}", ['font'=>['bold'=>true,'color'=>['argb'=>'FF0F172A']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FFF1F5F9']],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],'borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['argb'=>'FFCBD5E1']]]]);

        $grandPcs = 0;
        foreach ($activeDefectPeriodKeys as $kIdx => $dk) {
            $colLet = Coordinate::stringFromColumnIndex(3 + $kIdx);
            if ($selectedResult === 'passed') {
                $colPcs = (int)($trendMap[$dk]['sample'] ?? 0);
            } else {
                $colPcs = 0;
                foreach ($topNDefectTypes as $dn) {
                    $colPcs += (int)($defectMatrix[$dk][$dn] ?? 0);
                }
            }
            $grandPcs += $colPcs;
            $smp = (int)($trendMap[$dk]['sample'] ?? 0);
            $colPpm = ($smp > 0) ? round(($colPcs / $smp) * 1000000, 1) : 0;
            $colVal = ($metricUnit === 'ppm') ? $colPpm : $colPcs;

            $sheet->setCellValue("{$colLet}{$r}", $colVal);
            applyStyle($sheet, "{$colLet}{$r}", dataStyle('F1F5F9', '0F172A', true, Alignment::HORIZONTAL_RIGHT));
        }
        $grandPpm = ($grandTotSample > 0) ? round(($grandPcs / $grandTotSample) * 1000000, 1) : 0;
        $grandVal = ($metricUnit === 'ppm') ? $grandPpm : $grandPcs;
        $sheet->setCellValue("{$totalColLetter}{$r}", $grandVal);
        applyStyle($sheet, "{$totalColLetter}{$r}", dataStyle('FEE2E2', 'DC2626', true, Alignment::HORIZONTAL_RIGHT));
        $footerRow = $r;
        $r++;

        return [$headerRow, $dataStartRow, $dataEndRow, $footerRow, $r];
    };

    // 1. Render Table 1: Primary Metric Unit
    $tbl1Title = "1. " . $defTitle;
    list($h1, $ds1, $de1, $f1, $nextR1) = $renderMatrixTable($ws3, 1, $primaryUnit, $tbl1Title, true);

    // 2. Render Table 2: Companion Metric Unit
    $r2 = $nextR1 + 2;
    $tbl2Title = "2. MATRIKS KOMPARASI (" . strtoupper($companionUnit) . ") — " . ($companionUnit === 'ppm' ? 'RASIO CACAT PARTS-PER-MILLION' : 'KUANTITAS FISIK UNIT');
    list($h2, $ds2, $de2, $f2, $nextR2) = $renderMatrixTable($ws3, $r2, $companionUnit, $tbl2Title, false);

    // 3. Native Excel Chart based on Table 1
    $dataSeriesArr = [];
    $xAxisLabelRef = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Top Defect Matriks'!\$C\${$h1}:\$" . Coordinate::stringFromColumnIndex(2 + $numActiveDates) . "\${$h1}", null, $numActiveDates);

    for ($rowIdx = $ds1; $rowIdx <= $de1; $rowIdx++) {
        $seriesTitleRef = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Top Defect Matriks'!\$B\${$rowIdx}", null, 1);
        $seriesDataRef  = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'Top Defect Matriks'!\$C\${$rowIdx}:\$" . Coordinate::stringFromColumnIndex(2 + $numActiveDates) . "\${$rowIdx}", null, $numActiveDates);
        $dataSeriesArr[] = [$seriesTitleRef, $seriesDataRef];
    }

    if (!empty($dataSeriesArr)) {
        $plotSeriesTitles = array_column($dataSeriesArr, 0);
        $plotSeriesValues = array_column($dataSeriesArr, 1);

        $dSeries = new DataSeries(
            DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_CLUSTERED,
            range(0, count($plotSeriesValues) - 1),
            $plotSeriesTitles,
            [$xAxisLabelRef],
            $plotSeriesValues
        );
        $dSeries->setPlotDirection(DataSeries::DIRECTION_COL);

        $dLayout = new Layout();
        $dLayout->setShowVal(true);

        $dPlotArea = new PlotArea($dLayout, [$dSeries]);
        $dLegend   = new Legend(Legend::POSITION_BOTTOM, null, false);
        $dChart    = new Chart('chartTopDefect', new Title($defTitle), $dLegend, $dPlotArea, true);

        $chartTopRow = $nextR2 + 2;
        $chartEndRow = $chartTopRow + 18;
        $chartEndCol = Coordinate::stringFromColumnIndex(max(10, $totalColIdx + 2));
        $dChart->setTopLeftPosition("A{$chartTopRow}");
        $dChart->setBottomRightPosition("{$chartEndCol}{$chartEndRow}");
        $ws3->addChart($dChart);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// SHEET 4: DISTRIBUSI MODEL (Source for Bar Chart)
// ═══════════════════════════════════════════════════════════════════════════
$ws4 = $spreadsheet->createSheet();
$ws4->setTitle("Distribusi Model");

$ws4->getColumnDimension('A')->setWidth(8);
$ws4->getColumnDimension('B')->setWidth(32);
$ws4->getColumnDimension('C')->setWidth(18);
$ws4->getColumnDimension('D')->setWidth(18);

$ws4->mergeCells('A1:D1');
$modelTitle = 'TOP ' . $selectedTopRank . ' MODEL (' . ($selectedResult === 'passed' ? 'PASSED' : 'NG') . ') (' . $unitText . ')';
$ws4->setCellValue('A1', $modelTitle);
applyStyle($ws4,'A1',hdrStyle('0F172A'));

$ws4->setCellValue('A2','NO'); $ws4->setCellValue('B2','NAMA MODEL');
$ws4->setCellValue('C2','TOTAL ('.$unitText.')'); $ws4->setCellValue('D2','PERSENTASE (%)');
applyStyle($ws4,'A2:D2',hdrStyle('334155'));

$mRow = 3; $mNo = 1;
$mStartRow = $mRow;
$totalMod  = array_sum(array_column($modelRows, 'total')) ?: 1;

if (empty($modelRows)) {
    $ws4->setCellValue("A3", 1);
    $ws4->setCellValue("B3", "Tidak ada data model");
    $ws4->setCellValue("C3", 0);
    $ws4->setCellValue("D3", 0);
    applyStyle($ws4,"A3:D3",dataStyle('F8FAFC','64748B',false,Alignment::HORIZONTAL_CENTER));
    $mRow = 4;
} else {
    foreach ($modelRows as $mr) {
        $mpct = round($mr['total'] / $totalMod * 100, 1);
        $ws4->setCellValue("A{$mRow}", $mNo++);
        $ws4->setCellValue("B{$mRow}", $mr['name']);
        $ws4->setCellValue("C{$mRow}", (int)$mr['total']);
        $ws4->setCellValue("D{$mRow}", $mpct);
        $bg = ($mNo % 2 === 0) ? 'FEF3C7' : 'FFFFFF';
        applyStyle($ws4,"A{$mRow}",dataStyle($bg,'64748B',false,Alignment::HORIZONTAL_CENTER));
        applyStyle($ws4,"B{$mRow}",dataStyle($bg,'1E293B',true));
        applyStyle($ws4,"C{$mRow}",dataStyle($bg,'B45309',true,Alignment::HORIZONTAL_RIGHT));
        applyStyle($ws4,"D{$mRow}",dataStyle('FFFBEB','92400E',true,Alignment::HORIZONTAL_CENTER));
        $mRow++;
    }
}
$mEndRow  = $mRow - 1;
$mCount   = $mEndRow - $mStartRow + 1;

// Bar Chart: Distribusi Model
if ($mCount > 0 && !empty($modelRows)) {
    $mLabelRef = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Distribusi Model'!\$B\${$mStartRow}:\$B\${$mEndRow}", null, $mCount);
    $mDataRef  = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'Distribusi Model'!\$C\${$mStartRow}:\$C\${$mEndRow}", null, $mCount);
    $mSLabel   = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Distribusi Model'!\$C\$2", null, 1);
    $mDataRef->setFillColor('F59E0B');

    $mSeries  = new DataSeries(DataSeries::TYPE_BARCHART, DataSeries::GROUPING_CLUSTERED, [0], [$mSLabel], [$mLabelRef], [$mDataRef]);
    $mSeries->setPlotDirection(DataSeries::DIRECTION_COL);
    
    $mLayout  = new Layout();
    $mLayout->setShowVal(true);

    $mPlot    = new PlotArea($mLayout, [$mSeries]);
    $mLegend  = new Legend(Legend::POSITION_BOTTOM, null, false);
    $mChart   = new Chart('chartModel', new Title($modelTitle), $mLegend, $mPlot, true);
    $mChart->setTopLeftPosition('F2');
    $mChart->setBottomRightPosition('P18');
    $ws4->addChart($mChart);
}

// ═══════════════════════════════════════════════════════════════════════════
// SHEET 5: DISTRIBUSI PART CODE
// ═══════════════════════════════════════════════════════════════════════════
$ws5 = $spreadsheet->createSheet();
$ws5->setTitle("Distribusi Part");

$ws5->getColumnDimension('A')->setWidth(8);
$ws5->getColumnDimension('B')->setWidth(32);
$ws5->getColumnDimension('C')->setWidth(20);
$ws5->getColumnDimension('D')->setWidth(18);
$ws5->getColumnDimension('E')->setWidth(18);

$partTitle = 'TOP ' . $selectedTopRank . ' PART CODE (' . ($selectedResult === 'passed' ? 'PASSED' : 'NG') . ') (' . $unitText . ')';
$ws5->mergeCells('A1:E1');
$ws5->setCellValue('A1', $partTitle);
applyStyle($ws5,'A1',hdrStyle('0F172A'));

$ws5->setCellValue('A2','NO'); $ws5->setCellValue('B2','NAMA PART'); $ws5->setCellValue('C2','KODE PART');
$ws5->setCellValue('D2','TOTAL ('.$unitText.')'); $ws5->setCellValue('E2','PERSENTASE (%)');
applyStyle($ws5,'A2:E2',hdrStyle('334155'));

$pRow = 3; $pNo = 1;
$pStartRow = $pRow;
$totalPart = array_sum(array_column($partRows, 'total')) ?: 1;

if (empty($partRows)) {
    $ws5->setCellValue("A3", 1);
    $ws5->setCellValue("B3", "Tidak ada data part");
    $ws5->setCellValue("C3", "-");
    $ws5->setCellValue("D3", 0);
    $ws5->setCellValue("E3", 0);
    applyStyle($ws5,"A3:E3",dataStyle('F8FAFC','64748B',false,Alignment::HORIZONTAL_CENTER));
    $pRow = 4;
} else {
    foreach ($partRows as $pr) {
        $ppct = round($pr['total'] / $totalPart * 100, 1);
        $bg = ($pNo % 2 === 0) ? 'EFF6FF' : 'FFFFFF';
        $ws5->setCellValue("A{$pRow}", $pNo++);
        $ws5->setCellValue("B{$pRow}", $pr['part_name'] ?? 'Unknown');
        $ws5->setCellValue("C{$pRow}", $pr['part_code'] ?? '-');
        $ws5->setCellValue("D{$pRow}", (int)$pr['total']);
        $ws5->setCellValue("E{$pRow}", $ppct);
        applyStyle($ws5,"A{$pRow}",dataStyle($bg,'64748B',false,Alignment::HORIZONTAL_CENTER));
        applyStyle($ws5,"B{$pRow}",dataStyle($bg,'1E293B',true));
        applyStyle($ws5,"C{$pRow}",dataStyle($bg,'475569',false,Alignment::HORIZONTAL_CENTER));
        applyStyle($ws5,"D{$pRow}",dataStyle($bg,'1D4ED8',true,Alignment::HORIZONTAL_RIGHT));
        applyStyle($ws5,"E{$pRow}",dataStyle('EFF6FF','1D4ED8',true,Alignment::HORIZONTAL_CENTER));
        $pRow++;
    }
}
$pEndRow = $pRow - 1;
$pCount  = $pEndRow - $pStartRow + 1;

// Bar Chart: Part Distribution (Top N)
$topNPartRows = array_slice($partRows, 0, $selectedTopRank);
if (!empty($topNPartRows)) {
    $topPartCount  = count($topNPartRows);
    $topPartEndRow = $pStartRow + $topPartCount - 1;
    $ptLabelRef   = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Distribusi Part'!\$B\${$pStartRow}:\$B\${$topPartEndRow}", null, $topPartCount);
    $ptDataRef    = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'Distribusi Part'!\$D\${$pStartRow}:\$D\${$topPartEndRow}", null, $topPartCount);
    $ptSLabel     = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Distribusi Part'!\$D\$2", null, 1);
    $ptDataRef->setFillColor('3B82F6');

    $ptSeries = new DataSeries(DataSeries::TYPE_BARCHART, DataSeries::GROUPING_CLUSTERED, [0], [$ptSLabel], [$ptLabelRef], [$ptDataRef]);
    $ptSeries->setPlotDirection(DataSeries::DIRECTION_BAR);

    $ptLayout = new Layout();
    $ptLayout->setShowVal(true);

    $ptPlot   = new PlotArea($ptLayout, [$ptSeries]);
    $ptChart  = new Chart('chartPart', new Title($partTitle), new Legend(Legend::POSITION_BOTTOM,null,false), $ptPlot, true);
    $ptChart->setTopLeftPosition('G2');
    $ptChart->setBottomRightPosition('Q18');
    $ws5->addChart($ptChart);
}

// ═══════════════════════════════════════════════════════════════════════════
// SHEET 6: WORST / BEST PARTS
// ═══════════════════════════════════════════════════════════════════════════
$ws6 = $spreadsheet->createSheet();
$ws6->setTitle($selectedResult === 'passed' ? "Best Parts" : "Worst Parts");

foreach (['A'=>8,'B'=>30,'C'=>18,'D'=>24,'E'=>16,'F'=>16] as $c=>$w) $ws6->getColumnDimension($c)->setWidth($w);

$ws6->mergeCells('A1:F1');
$ws6->setCellValue('A1', $selectedResult === 'passed' ? 'BEST PERFORMANCE PART OQC (TOP PASSED PARTS)' : 'WORST PART OQC (BREAKDOWN PER DEFECT AREA)');
applyStyle($ws6,'A1',hdrStyle($selectedResult === 'passed' ? '059669' : 'DC2626'));

$ws6->setCellValue('A2','NO'); $ws6->setCellValue('B2','PART NAME'); $ws6->setCellValue('C2','PART CODE');
$ws6->setCellValue('D2','MODEL'); $ws6->setCellValue('E2','TOTAL LOT'); $ws6->setCellValue('F2', $selectedResult === 'passed' ? 'PASSED LOT' : 'NG CASE');
applyStyle($ws6,'A2:F2',hdrStyle('334155'));

$w6Row = 3;
if ($selectedResult === 'passed') {
    if (empty($bestPartsPassed)) {
        $ws6->setCellValue("A3", 1);
        $ws6->setCellValue("B3", "Tidak ada data best part");
        $ws6->setCellValue("C3", "-");
        $ws6->setCellValue("D3", "-");
        $ws6->setCellValue("E3", 0);
        $ws6->setCellValue("F3", 0);
        applyStyle($ws6,"A3:F3",dataStyle('F8FAFC','64748B',false,Alignment::HORIZONTAL_CENTER));
    } else {
        foreach ($bestPartsPassed as $bi => $bp) {
            $ws6->setCellValue("A{$w6Row}", $bi + 1);
            $ws6->setCellValue("B{$w6Row}", $bp['part_name']);
            $ws6->setCellValue("C{$w6Row}", $bp['part_code']);
            $ws6->setCellValue("D{$w6Row}", $bp['model']);
            $ws6->setCellValue("E{$w6Row}", (int)$bp['lot_case']);
            $ws6->setCellValue("F{$w6Row}", (int)$bp['passed_lot_case']);
            $bg = ($bi % 2 === 0) ? 'F0FDF4' : 'FFFFFF';
            applyStyle($ws6,"A{$w6Row}",dataStyle($bg,'64748B',false,Alignment::HORIZONTAL_CENTER));
            applyStyle($ws6,"B{$w6Row}",dataStyle($bg,'1E293B',true));
            applyStyle($ws6,"C{$w6Row}",dataStyle($bg,'475569',false,Alignment::HORIZONTAL_CENTER));
            applyStyle($ws6,"D{$w6Row}",dataStyle($bg,'334155'));
            applyStyle($ws6,"E{$w6Row}",dataStyle($bg,'475569',false,Alignment::HORIZONTAL_CENTER));
            applyStyle($ws6,"F{$w6Row}",dataStyle('ECFDF5','059669',true,Alignment::HORIZONTAL_CENTER));
            $w6Row++;
        }
    }
} else {
    if (empty($worstPartsGrouped)) {
        $ws6->setCellValue("A3", 1);
        $ws6->setCellValue("B3", "Tidak ada data worst part");
        $ws6->setCellValue("C3", "-");
        $ws6->setCellValue("D3", "-");
        $ws6->setCellValue("E3", 0);
        $ws6->setCellValue("F3", 0);
        applyStyle($ws6,"A3:F3",dataStyle('F8FAFC','64748B',false,Alignment::HORIZONTAL_CENTER));
    } else {
        foreach ($worstPartsGrouped as $defCat => $wParts) {
            $ws6->mergeCells("A{$w6Row}:F{$w6Row}");
            $ws6->setCellValue("A{$w6Row}", 'DEFECT: '.strtoupper($defCat));
            applyStyle($ws6,"A{$w6Row}",['font'=>['bold'=>true,'color'=>['argb'=>'FFBE123C']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FFFFF1F2']],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_LEFT,'vertical'=>Alignment::VERTICAL_CENTER],'borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['argb'=>'FFFECDD3']]]]);
            $w6Row++;
            foreach ($wParts as $wi => $wp) {
                $ws6->setCellValue("A{$w6Row}", $wi + 1);
                $ws6->setCellValue("B{$w6Row}", $wp['part_name']);
                $ws6->setCellValue("C{$w6Row}", $wp['part_code']);
                $ws6->setCellValue("D{$w6Row}", $wp['model']);
                $ws6->setCellValue("E{$w6Row}", (int)$wp['lot_case']);
                $ws6->setCellValue("F{$w6Row}", (int)$wp['ng_case']);
                $bg = ($wi % 2 === 0) ? 'FFF1F2' : 'FFFFFF';
                applyStyle($ws6,"A{$w6Row}",dataStyle($bg,'64748B',false,Alignment::HORIZONTAL_CENTER));
                applyStyle($ws6,"B{$w6Row}",dataStyle($bg,'1E293B',true));
                applyStyle($ws6,"C{$w6Row}",dataStyle($bg,'475569',false,Alignment::HORIZONTAL_CENTER));
                applyStyle($ws6,"D{$w6Row}",dataStyle($bg,'334155'));
                applyStyle($ws6,"E{$w6Row}",dataStyle($bg,'475569',false,Alignment::HORIZONTAL_CENTER));
                applyStyle($ws6,"F{$w6Row}",dataStyle('FFF1F2','DC2626',true,Alignment::HORIZONTAL_CENTER));
                $w6Row++;
            }
        }
    }
}

// Set active sheet to Summary
$spreadsheet->setActiveSheetIndex(0);

// Output
$filenameDate = date('Ymd_His');
header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
header("Content-Disposition: attachment; filename=OQC_Dashboard_Report_{$filenameDate}.xlsx");
header("Cache-Control: max-age=0");
header("Pragma: no-cache");

$writer = new Xlsx($spreadsheet);
$writer->setIncludeCharts(true);
$writer->save("php://output");
exit;
