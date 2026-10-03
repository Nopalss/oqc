<?php
/**
 * Official Visual Excel Report Generator — Analisis Defect & Deep-Dive OQC
 * Generates an executive-grade, chart-rich XLSX spreadsheet mirroring the web dashboard:
 * 1. Sheet 1: 🎯 Analisis Part & Tren (4 KPI Cards, Timeline Data, Native Line/Bar Chart)
 * 2. Sheet 2: 🍩 Funnel Distribusi Cacat (3 Pie Charts: Defect, Model, Part)
 * 3. Sheet 3: 📋 Riwayat Temuan (Audit Trail detail log with Excel formulas)
 *
 * PT. Surya Technology Industri — Outgoing Quality Control Division
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
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Chart\Layout;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;

$pdo = getDB();

// ── 1. Query Parameters & Active Drilldown Resolvers ────────────────────────
$selectedUnit     = sanitize($_GET['unit'] ?? 'pcs');
if (!in_array($selectedUnit, ['pcs', 'lot'])) {
    $selectedUnit = 'pcs';
}

$selectedSampleBasis = sanitize($_GET['sample_basis'] ?? 'lot');
if (!in_array($selectedSampleBasis, ['lot', 'session'])) {
    $selectedSampleBasis = 'lot';
}

$selectedTopRank  = sanitize($_GET['top_rank'] ?? '5');
if (!in_array($selectedTopRank, ['3', '5', '10', 'all'])) {
    $selectedTopRank = '5';
}

$selectedCustomer = sanitize($_GET['customer'] ?? '');
$presetFilter     = sanitize($_GET['preset'] ?? 'bulanan');
$filterType       = sanitize($_GET['filter_type'] ?? '');
$selectedMonth    = isset($_GET['month']) && is_numeric($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$selectedYear     = isset($_GET['year']) && is_numeric($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Drilldown selections
$defectId = isset($_GET['defect_id']) && is_numeric($_GET['defect_id']) ? (int)$_GET['defect_id'] : 0;
$modelId  = isset($_GET['model_id']) && is_numeric($_GET['model_id']) ? (int)$_GET['model_id'] : null;
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

// ── 2. Fallback Auto-Detection if URL accessed directly without drilldown ────
if ($pdo && ($defectId <= 0 || $partId <= 0)) {
    // Pick the highest defect in current date range
    $sdParam = $startDate . ' 00:00:00';
    $edParam = date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00';
    if ($defectId <= 0 || $modelId === null || $partId <= 0) {
        if (function_exists('set_flash') && function_exists('redirect')) {
            set_flash('danger', 'Ekspor Excel Analisis Cacat mewajibkan Anda memilih Jenis Defect, Model Produk, dan Part Code secara lengkap terlebih dahulu.');
            redirect(base_url('modules/defect_analysis/index.php'));
        } else {
            echo "<script>alert('Silakan pilih jenis defect, model produk, dan part code terlebih dahulu.'); window.history.back();</script>";
        }
        exit;
    }
}

// Fetch Active Names for Context Header
$defectName = 'Defect #' . $defectId;
$modelName  = ($modelId > 0) ? ('Model #' . $modelId) : 'General Model';
$partCode   = 'PART-' . $partId;
$partName   = 'Part #' . $partId;

if ($pdo) {
    if ($defectId > 0) {
        $stD = $pdo->prepare("SELECT name FROM defect_types WHERE id = :id");
        $stD->execute([':id' => $defectId]);
        $defectName = $stD->fetchColumn() ?: $defectName;
    }
    if ($modelId > 0) {
        $stM = $pdo->prepare("SELECT name FROM master_models WHERE id = :id");
        $stM->execute([':id' => $modelId]);
        $modelName = $stM->fetchColumn() ?: $modelName;
    } elseif ($modelId === 0) {
        $modelName = 'General / Unspecified Model';
    }
    if ($partId > 0) {
        $stP = $pdo->prepare("SELECT part_code, part_name FROM master_parts WHERE id = :id");
        $stP->execute([':id' => $partId]);
        $pRow = $stP->fetch(PDO::FETCH_ASSOC);
        if ($pRow) {
            $partCode = $pRow['part_code'];
            $partName = $pRow['part_name'];
        }
    }
}

// Common WHERE base
$whereBase = [
    "(ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)",
    "s.started_at >= :sd AND s.started_at < :ed_next"
];
$paramsBase = [
    ':sd'      => $startDate . ' 00:00:00',
    ':ed_next' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'
];
if ($selectedCustomer !== '') {
    $whereBase[] = "COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') = :cust";
    $paramsBase[':cust'] = $selectedCustomer;
}

// ── 3. Data Extraction ──────────────────────────────────────────────────────
$funnelDefects = [];
$funnelModels  = [];
$funnelParts   = [];
$trendMap      = [];
$auditRecords  = [];

$partPcsTotal  = 0;
$partLotsTotal = 0;
$topLimit = is_numeric($selectedTopRank) ? (int)$selectedTopRank : 0;

if ($pdo) {
    // ── A. Funnel Level 1: All Defects (Hybrid Summary + Active) ─────────────
    try {
        $wSum = ["dds.summary_date >= :sd AND dds.summary_date <= :ed"];
        $pSum = [':sd' => $startDate, ':ed' => $endDate];
        if ($selectedCustomer !== '') {
            $wSum[] = "dds.customer = :cust";
            $pSum[':cust'] = $selectedCustomer;
        }
        $wSumSql = implode(' AND ', $wSum);

        $sqlSum = "SELECT 
                    dt.id,
                    dt.name,
                    COALESCE(SUM(dds.qty_ng), 0) AS pcs,
                    COALESCE(SUM(dds.lot_count), 0) AS lots
                   FROM oqc_daily_defect_summary dds
                   INNER JOIN defect_types dt ON dds.defect_type_id = dt.id
                   WHERE {$wSumSql}
                   GROUP BY dt.id, dt.name";
        $stSum = $pdo->prepare($sqlSum);
        $stSum->execute($pSum);
        $rowsSum = $stSum->fetchAll(PDO::FETCH_ASSOC);

        $rowsAct = [];
        if ($endDate >= date('Y-m-d')) {
            $pAct = [
                ':sd_act'      => $startDate . ' 00:00:00',
                ':ed_act_next' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'
            ];
            $custActSql = "";
            if ($selectedCustomer !== '') {
                $custActSql = "AND COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') = :cust_act";
                $pAct[':cust_act'] = $selectedCustomer;
            }
            $sqlAct = "SELECT 
                        dt.id,
                        dt.name,
                        COALESCE(SUM(ngr.qty_ng), 0) AS pcs,
                        COUNT(DISTINCT s.id) AS lots
                       FROM inspection_ng_records ngr
                       INNER JOIN inspection_sessions s ON ngr.inspection_session_id = s.id
                       INNER JOIN defect_types dt ON ngr.defect_type_id = dt.id
                       LEFT JOIN kanban_items ki ON s.kanban_item_id = ki.id
                       WHERE s.status = 'in_progress'
                         AND (ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)
                         AND s.started_at >= :sd_act AND s.started_at < :ed_act_next
                         {$custActSql}
                       GROUP BY dt.id, dt.name";
            $stAct = $pdo->prepare($sqlAct);
            $stAct->execute($pAct);
            $rowsAct = $stAct->fetchAll(PDO::FETCH_ASSOC);
        }

        $mergedD = [];
        foreach ($rowsSum as $r) {
            $id = (int)$r['id'];
            $mergedD[$id] = [
                'id'   => $id,
                'name' => $r['name'],
                'pcs'  => (int)$r['pcs'],
                'lots' => (int)$r['lots']
            ];
        }
        foreach ($rowsAct as $r) {
            $id = (int)$r['id'];
            if (!isset($mergedD[$id])) {
                $mergedD[$id] = ['id' => $id, 'name' => $r['name'], 'pcs' => 0, 'lots' => 0];
            }
            $mergedD[$id]['pcs']  += (int)$r['pcs'];
            $mergedD[$id]['lots'] += (int)$r['lots'];
        }

        $rawD = array_values($mergedD);
        usort($rawD, function($a, $b) use ($selectedUnit) {
            $vA = ($selectedUnit === 'lot') ? $a['lots'] : $a['pcs'];
            $vB = ($selectedUnit === 'lot') ? $b['lots'] : $b['pcs'];
            if ($vA === $vB) return $b['pcs'] <=> $a['pcs'];
            return $vB <=> $vA;
        });

        if ($topLimit > 0 && count($rawD) > $topLimit) {
            $funnelDefects = array_slice($rawD, 0, $topLimit);
            $oth = array_slice($rawD, $topLimit);
            $oPcs = array_sum(array_column($oth, 'pcs'));
            $oLots = array_sum(array_column($oth, 'lots'));
            $funnelDefects[] = ['id' => 0, 'name' => 'Lainnya (' . count($oth) . ' jenis)', 'pcs' => $oPcs, 'lots' => $oLots];
        } else {
            $funnelDefects = $rawD;
        }
    } catch (Exception $e) {}

    // ── B. Funnel Level 2: Models for Selected Defect ───────────────────────
    try {
        $wSumM = [
            "dds.summary_date >= :sd AND dds.summary_date <= :ed",
            "dds.defect_type_id = :defId"
        ];
        $pSumM = [':sd' => $startDate, ':ed' => $endDate, ':defId' => $defectId];
        if ($selectedCustomer !== '') {
            $wSumM[] = "dds.customer = :cust";
            $pSumM[':cust'] = $selectedCustomer;
        }
        $wSumMSql = implode(' AND ', $wSumM);

        $sqlM = "SELECT 
                    COALESCE(dds.model_id, 0) AS id,
                    COALESCE(NULLIF(TRIM(mm.name), ''), 'General / Unspecified Model') AS name,
                    COALESCE(SUM(dds.qty_ng), 0) AS pcs,
                    COALESCE(SUM(dds.lot_count), 0) AS lots
                 FROM oqc_daily_defect_summary dds
                 LEFT JOIN master_models mm ON dds.model_id = mm.id
                 WHERE {$wSumMSql}
                 GROUP BY id, name";
        $stM = $pdo->prepare($sqlM);
        $stM->execute($pSumM);
        $rowsSumM = $stM->fetchAll(PDO::FETCH_ASSOC);

        $rowsActM = [];
        if ($endDate >= date('Y-m-d')) {
            $pActM = [
                ':defId'       => $defectId,
                ':sd_act'      => $startDate . ' 00:00:00',
                ':ed_act_next' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'
            ];
            $custActSql = "";
            if ($selectedCustomer !== '') {
                $custActSql = "AND COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') = :cust_act";
                $pActM[':cust_act'] = $selectedCustomer;
            }
            $sqlActM = "SELECT 
                        COALESCE(mp.model_id, 0) AS id,
                        COALESCE(NULLIF(TRIM(mm.name), ''), NULLIF(TRIM(mp.model), ''), 'General / Unspecified Model') AS name,
                        COALESCE(SUM(ngr.qty_ng), 0) AS pcs,
                        COUNT(DISTINCT s.id) AS lots
                       FROM inspection_ng_records ngr
                       INNER JOIN inspection_sessions s ON ngr.inspection_session_id = s.id
                       LEFT JOIN master_parts mp ON ngr.part_id = mp.id
                       LEFT JOIN master_models mm ON mp.model_id = mm.id
                       LEFT JOIN kanban_items ki ON s.kanban_item_id = ki.id
                       WHERE s.status = 'in_progress'
                         AND (ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)
                         AND ngr.defect_type_id = :defId
                         AND s.started_at >= :sd_act AND s.started_at < :ed_act_next
                         {$custActSql}
                       GROUP BY id, name";
            $stActM = $pdo->prepare($sqlActM);
            $stActM->execute($pActM);
            $rowsActM = $stActM->fetchAll(PDO::FETCH_ASSOC);
        }

        $mergedM = [];
        foreach ($rowsSumM as $r) {
            $id = (int)$r['id'];
            $mergedM[$id] = [
                'id'   => $id,
                'name' => $r['name'],
                'pcs'  => (int)$r['pcs'],
                'lots' => (int)$r['lots']
            ];
        }
        foreach ($rowsActM as $r) {
            $id = (int)$r['id'];
            if (!isset($mergedM[$id])) {
                $mergedM[$id] = ['id' => $id, 'name' => $r['name'], 'pcs' => 0, 'lots' => 0];
            }
            $mergedM[$id]['pcs']  += (int)$r['pcs'];
            $mergedM[$id]['lots'] += (int)$r['lots'];
        }

        $rawM = array_values($mergedM);
        usort($rawM, function($a, $b) use ($selectedUnit) {
            $vA = ($selectedUnit === 'lot') ? $a['lots'] : $a['pcs'];
            $vB = ($selectedUnit === 'lot') ? $b['lots'] : $b['pcs'];
            if ($vA === $vB) return $b['pcs'] <=> $a['pcs'];
            return $vB <=> $vA;
        });

        if ($topLimit > 0 && count($rawM) > $topLimit) {
            $funnelModels = array_slice($rawM, 0, $topLimit);
            $othM = array_slice($rawM, $topLimit);
            $oMPcs = array_sum(array_column($othM, 'pcs'));
            $oMLots = array_sum(array_column($othM, 'lots'));
            $funnelModels[] = ['id' => 0, 'name' => 'Lainnya (' . count($othM) . ' model)', 'pcs' => $oMPcs, 'lots' => $oMLots];
        } else {
            $funnelModels = $rawM;
        }
    } catch (Exception $e) {}

    // ── C. Funnel Level 3: Parts for Selected Defect & Model ────────────────
    try {
        $wSumP = [
            "dds.summary_date >= :sd AND dds.summary_date <= :ed",
            "dds.defect_type_id = :defId"
        ];
        $pSumP = [':sd' => $startDate, ':ed' => $endDate, ':defId' => $defectId];
        if ($modelId !== null) {
            if ($modelId > 0) {
                $wSumP[] = "dds.model_id = :mId";
                $pSumP[':mId'] = $modelId;
            } else {
                $wSumP[] = "(dds.model_id IS NULL OR dds.model_id = 0)";
            }
        }
        if ($selectedCustomer !== '') {
            $wSumP[] = "dds.customer = :cust";
            $pSumP[':cust'] = $selectedCustomer;
        }
        $wSumPSql = implode(' AND ', $wSumP);

        $sqlP = "SELECT 
                    mp.id,
                    CONCAT(mp.part_code, ' - ', mp.part_name) AS name,
                    COALESCE(SUM(dds.qty_ng), 0) AS pcs,
                    COALESCE(SUM(dds.lot_count), 0) AS lots
                 FROM oqc_daily_defect_summary dds
                 INNER JOIN master_parts mp ON dds.part_id = mp.id
                 WHERE {$wSumPSql}
                 GROUP BY mp.id, mp.part_code, mp.part_name";
        $stP = $pdo->prepare($sqlP);
        $stP->execute($pSumP);
        $rowsSumP = $stP->fetchAll(PDO::FETCH_ASSOC);

        $rowsActP = [];
        if ($endDate >= date('Y-m-d')) {
            $wActP = [
                "s.status = 'in_progress'",
                "(ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)",
                "ngr.defect_type_id = :defId",
                "s.started_at >= :sd_act AND s.started_at < :ed_act_next"
            ];
            $pActP = [
                ':defId'       => $defectId,
                ':sd_act'      => $startDate . ' 00:00:00',
                ':ed_act_next' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'
            ];
            if ($modelId !== null) {
                if ($modelId > 0) {
                    $wActP[] = "mp.model_id = :mId";
                    $pActP[':mId'] = $modelId;
                } else {
                    $wActP[] = "(mp.model_id IS NULL OR mp.model_id = 0)";
                }
            }
            if ($selectedCustomer !== '') {
                $wActP[] = "COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') = :cust_act";
                $pActP[':cust_act'] = $selectedCustomer;
            }
            $wActPSql = implode(' AND ', $wActP);
            $sqlActP = "SELECT 
                        mp.id,
                        CONCAT(mp.part_code, ' - ', mp.part_name) AS name,
                        COALESCE(SUM(ngr.qty_ng), 0) AS pcs,
                        COUNT(DISTINCT s.id) AS lots
                       FROM inspection_ng_records ngr
                       INNER JOIN inspection_sessions s ON ngr.inspection_session_id = s.id
                       INNER JOIN master_parts mp ON ngr.part_id = mp.id
                       LEFT JOIN kanban_items ki ON s.kanban_item_id = ki.id
                       WHERE {$wActPSql}
                       GROUP BY mp.id, mp.part_code, mp.part_name";
            $stActP = $pdo->prepare($sqlActP);
            $stActP->execute($pActP);
            $rowsActP = $stActP->fetchAll(PDO::FETCH_ASSOC);
        }

        $mergedP = [];
        foreach ($rowsSumP as $r) {
            $id = (int)$r['id'];
            $mergedP[$id] = [
                'id'   => $id,
                'name' => $r['name'],
                'pcs'  => (int)$r['pcs'],
                'lots' => (int)$r['lots']
            ];
        }
        foreach ($rowsActP as $r) {
            $id = (int)$r['id'];
            if (!isset($mergedP[$id])) {
                $mergedP[$id] = ['id' => $id, 'name' => $r['name'], 'pcs' => 0, 'lots' => 0];
            }
            $mergedP[$id]['pcs']  += (int)$r['pcs'];
            $mergedP[$id]['lots'] += (int)$r['lots'];
        }

        $rawP = array_values($mergedP);
        usort($rawP, function($a, $b) use ($selectedUnit) {
            $vA = ($selectedUnit === 'lot') ? $a['lots'] : $a['pcs'];
            $vB = ($selectedUnit === 'lot') ? $b['lots'] : $b['pcs'];
            if ($vA === $vB) return $b['pcs'] <=> $a['pcs'];
            return $vB <=> $vA;
        });

        if ($topLimit > 0 && count($rawP) > $topLimit) {
            $funnelParts = array_slice($rawP, 0, $topLimit);
            $othP = array_slice($rawP, $topLimit);
            $oPPcs = array_sum(array_column($othP, 'pcs'));
            $oPLots = array_sum(array_column($othP, 'lots'));
            $funnelParts[] = ['id' => 0, 'name' => 'Lainnya (' . count($othP) . ' part)', 'pcs' => $oPPcs, 'lots' => $oPLots];
        } else {
            $funnelParts = $rawP;
        }
    } catch (Exception $e) {}

    // ── D. Deep-Dive Timeline Trend for Part (Hybrid) ────────────────────────
    try {
        $isSingleDay = ($startDate === $endDate);
        $daysDiff = (strtotime($endDate) - strtotime($startDate)) / 86400;

        if ($isSingleDay) {
            for ($h = 6; $h <= 22; $h++) {
                $hKey = sprintf('%02d:00', $h);
                $trendMap[$hKey] = ['label' => $hKey, 'pcs' => 0, 'lot' => 0];
            }
            $wHour = [
                "(ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)",
                "ngr.defect_type_id = :defId",
                "s.started_at >= :sd AND s.started_at < :ed_next"
            ];
            $pHour = [
                ':defId'   => $defectId,
                ':sd'      => $startDate . ' 00:00:00',
                ':ed_next' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'
            ];
            if ($partId > 0) {
                $wHour[] = "ngr.part_id = :pId";
                $pHour[':pId'] = $partId;
            } elseif ($modelId !== null && $modelId > 0) {
                $wHour[] = "mp.model_id = :mId";
                $pHour[':mId'] = $modelId;
            }
            if ($selectedCustomer !== '') {
                $wHour[] = "COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') = :cust";
                $pHour[':cust'] = $selectedCustomer;
            }
            $wHourSql = implode(' AND ', $wHour);

            $sqlT = "SELECT DATE_FORMAT(s.started_at, '%H:00') as time_key,
                            COALESCE(SUM(ngr.qty_ng), 0) as total_pcs,
                            COUNT(DISTINCT s.id) as total_lots
                     FROM inspection_ng_records ngr
                     INNER JOIN inspection_sessions s ON ngr.inspection_session_id = s.id
                     LEFT JOIN master_parts mp ON ngr.part_id = mp.id
                     LEFT JOIN kanban_items ki ON s.kanban_item_id = ki.id
                     WHERE {$wHourSql}
                     GROUP BY time_key";
            $stT = $pdo->prepare($sqlT);
            $stT->execute($pHour);
            foreach ($stT->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $tk = $r['time_key'];
                if (isset($trendMap[$tk])) {
                    $trendMap[$tk]['pcs'] = (int)$r['total_pcs'];
                    $trendMap[$tk]['lot'] = (int)$r['total_lots'];
                }
            }
        } else {
            // Multi-day timeline using pre-aggregated summary table
            $wSumT = [
                "dds.summary_date >= :sd AND dds.summary_date <= :ed",
                "dds.defect_type_id = :defId"
            ];
            $pSumT = [
                ':sd'    => $startDate,
                ':ed'    => $endDate,
                ':defId' => $defectId
            ];
            if ($partId > 0) {
                $wSumT[] = "dds.part_id = :pId";
                $pSumT[':pId'] = $partId;
            } elseif ($modelId !== null) {
                if ($modelId > 0) {
                    $wSumT[] = "dds.model_id = :mId";
                    $pSumT[':mId'] = $modelId;
                } else {
                    $wSumT[] = "(dds.model_id IS NULL OR dds.model_id = 0)";
                }
            }
            if ($selectedCustomer !== '') {
                $wSumT[] = "dds.customer = :cust";
                $pSumT[':cust'] = $selectedCustomer;
            }
            $wSumTSql = implode(' AND ', $wSumT);

            if ($filterType === 'all_years' || ($filterType === 'custom' && $daysDiff > 730)) {
                $minY = (int)date('Y', strtotime($startDate));
                $maxY = (int)date('Y', strtotime($endDate));
                for ($yr = $minY; $yr <= $maxY; $yr++) {
                    $trendMap[(string)$yr] = ['label' => (string)$yr, 'pcs' => 0, 'lot' => 0];
                }
                $dateFormat = '%Y';
            } elseif ($daysDiff <= 90) {
                $sp = new DateTime($startDate);
                $ep = new DateTime($endDate);
                $ep->modify('+1 day');
                foreach (new DatePeriod($sp, new DateInterval('P1D'), $ep) as $dt) {
                    $trendMap[$dt->format('Y-m-d')] = ['label' => $dt->format('d M'), 'pcs' => 0, 'lot' => 0];
                }
                $dateFormat = '%Y-%m-%d';
            } else {
                $sp = new DateTime(date('Y-m-01', strtotime($startDate)));
                $ep = new DateTime(date('Y-m-01', strtotime($endDate)));
                $ep->modify('+1 month');
                foreach (new DatePeriod($sp, DateInterval::createFromDateString('1 month'), $ep) as $dt) {
                    $trendMap[$dt->format('Y-m')] = ['label' => $dt->format('M y'), 'pcs' => 0, 'lot' => 0];
                }
                $dateFormat = '%Y-%m';
            }

            $sqlSumT = "SELECT DATE_FORMAT(dds.summary_date, '{$dateFormat}') as time_key,
                               COALESCE(SUM(dds.qty_ng), 0) as total_pcs,
                               COALESCE(SUM(dds.lot_count), 0) as total_lots
                        FROM oqc_daily_defect_summary dds
                        WHERE {$wSumTSql}
                        GROUP BY time_key";
            $stSumT = $pdo->prepare($sqlSumT);
            $stSumT->execute($pSumT);
            foreach ($stSumT->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $tk = $r['time_key'];
                if (isset($trendMap[$tk])) {
                    $trendMap[$tk]['pcs'] += (int)$r['total_pcs'];
                    $trendMap[$tk]['lot'] += (int)$r['total_lots'];
                }
            }

            // Merge active in-progress sessions if range covers today
            if ($endDate >= date('Y-m-d')) {
                $wActT = [
                    "s.status = 'in_progress'",
                    "(ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)",
                    "ngr.defect_type_id = :defId",
                    "s.started_at >= :sd_act AND s.started_at < :ed_act_next"
                ];
                $pActT = [
                    ':defId'       => $defectId,
                    ':sd_act'      => $startDate . ' 00:00:00',
                    ':ed_act_next' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'
                ];
                if ($partId > 0) {
                    $wActT[] = "ngr.part_id = :pId";
                    $pActT[':pId'] = $partId;
                } elseif ($modelId !== null && $modelId > 0) {
                    $wActT[] = "mp.model_id = :mId";
                    $pActT[':mId'] = $modelId;
                }
                if ($selectedCustomer !== '') {
                    $wActT[] = "COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') = :cust_act";
                    $pActT[':cust_act'] = $selectedCustomer;
                }
                $wActTSql = implode(' AND ', $wActT);

                $sqlActT = "SELECT DATE_FORMAT(s.started_at, '{$dateFormat}') as time_key,
                                   COALESCE(SUM(ngr.qty_ng), 0) as total_pcs,
                                   COUNT(DISTINCT s.id) as total_lots
                            FROM inspection_ng_records ngr
                            INNER JOIN inspection_sessions s ON ngr.inspection_session_id = s.id
                            LEFT JOIN master_parts mp ON ngr.part_id = mp.id
                            LEFT JOIN kanban_items ki ON s.kanban_item_id = ki.id
                            WHERE {$wActTSql}
                            GROUP BY time_key";
                $stActT = $pdo->prepare($sqlActT);
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

    // ── E. Detailed Audit Trail Log for Part (Direct Indexed Join) ───────────
    try {
        $wAudit = [
            "(ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)",
            "s.started_at >= :sd AND s.started_at < :ed_next"
        ];
        $pAudit = [
            ':sd'      => $startDate . ' 00:00:00',
            ':ed_next' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'
        ];
        if ($defectId > 0) {
            $wAudit[] = "ngr.defect_type_id = :dId";
            $pAudit[':dId'] = $defectId;
        }
        if ($partId > 0) {
            $wAudit[] = "ngr.part_id = :pId";
            $pAudit[':pId'] = $partId;
        } elseif ($modelId !== null) {
            if ($modelId > 0) {
                $wAudit[] = "mp.model_id = :mId";
                $pAudit[':mId'] = $modelId;
            } else {
                $wAudit[] = "(mp.model_id IS NULL OR mp.model_id = 0)";
            }
        }
        if ($selectedCustomer !== '') {
            $wAudit[] = "COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') = :cust";
            $pAudit[':cust'] = $selectedCustomer;
        }
        $wAuditSql = implode(' AND ', $wAudit);

        $sqlAudit = "SELECT 
                        ngr.id AS ng_id,
                        s.started_at,
                        s.inspection_type,
                        s.status AS session_status,
                        COALESCE(u.name, 'System') AS inspector_name,
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
                     WHERE {$wAuditSql}
                     ORDER BY s.started_at DESC, ngr.id DESC
                     LIMIT 3000";
        $stA = $pdo->prepare($sqlAudit);
        $stA->execute($pAudit);
        $auditRecords = $stA->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// Compute Deep-Dive Part KPIs
$peakVal   = 0;
$peakLabel = 'Nihil / 0 Cacat';
$activeIntervalCount = count($trendMap);

foreach ($trendMap as $t) {
    $partPcsTotal  += $t['pcs'];
    $partLotsTotal += $t['lot'];
    $val = ($selectedUnit === 'lot') ? $t['lot'] : $t['pcs'];
    if ($val > $peakVal) {
        $peakVal = $val;
        $peakLabel = $t['label'] . ' (' . number_format($val) . ' ' . strtoupper($selectedUnit) . ')';
    }
}
$partMetricActive = ($selectedUnit === 'lot') ? $partLotsTotal : $partPcsTotal;
$partAvgPerInterval = ($activeIntervalCount > 0 && $partMetricActive > 0) ? round($partMetricActive / $activeIntervalCount, 1) : 0;

// ── 4. Spreadsheet Styling Helpers ──────────────────────────────────────────
$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator("OQC Quality Control System")
    ->setTitle("Laporan Analisis Mendalam Defect & Part OQC")
    ->setSubject("Analisis Cacat Mutu - PT. Surya Technology Industri")
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
// SHEET 1: 🎯 ANALISIS PART & TREN (Mencerminkan Deep-Dive Panel di Web)
// ═══════════════════════════════════════════════════════════════════════════
$ws1 = $spreadsheet->getActiveSheet();
$ws1->setTitle("🎯 Analisis Part & Tren");

$ws1->getColumnDimension('A')->setWidth(6);
$ws1->getColumnDimension('B')->setWidth(20);
$ws1->getColumnDimension('C')->setWidth(18);
$ws1->getColumnDimension('D')->setWidth(16);
$ws1->getColumnDimension('E')->setWidth(18);

// Header Banner
$ws1->getRowDimension(1)->setRowHeight(38);
$ws1->getRowDimension(2)->setRowHeight(24);
$ws1->mergeCells('A1:P1');
$ws1->setCellValue('A1', 'PT. SURYA TECHNOLOGY INDUSTRI — QUALITY CONTROL DIVISION');
applyStyle($ws1, 'A1', [
    'font'      => ['bold' => true, 'size' => 15, 'color' => ['argb' => 'FF0F172A']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFDBEAFE']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF93C5FD']]]
]);

$ws1->mergeCells('A2:P2');
$ws1->setCellValue('A2', 'LAPORAN ANALISIS MENDALAM DEFECT & PART  |  PART: ' . strtoupper($partCode . ' - ' . $partName) . '  |  DEFECT: ' . strtoupper($defectName) . '  |  MODEL: ' . strtoupper($modelName) . '  |  BASIS: ' . strtoupper($selectedSampleBasis === 'session' ? 'PER SESI' : 'PER LOT'));
applyStyle($ws1, 'A2', [
    'font'      => ['bold' => true, 'size' => 9, 'color' => ['argb' => 'FF1E40AF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEFF6FF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]
]);

// ── 4 Executive KPI Cards (Rows 4 to 6) ─────────────────────────────────────
$ws1->getRowDimension(4)->setRowHeight(18);
$ws1->getRowDimension(5)->setRowHeight(32);
$ws1->getRowDimension(6)->setRowHeight(18);

// KPI Card 1: Total Cacat Part
$ws1->mergeCells('A4:C4');
$ws1->setCellValue('A4', 'TOTAL CACAT PART');
applyStyle($ws1, 'A4', ['font' => ['bold' => true, 'size' => 9, 'color' => ['argb' => 'FFBE123C']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFF1F2']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);

$ws1->mergeCells('A5:C5');
$ws1->setCellValue('A5', number_format($partMetricActive));
applyStyle($ws1, 'A5', ['font' => ['bold' => true, 'size' => 20, 'color' => ['argb' => 'FFBE123C']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFF1F2']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]]);

$ws1->mergeCells('A6:C6');
$ws1->setCellValue('A6', strtoupper($selectedUnit) . ' total akumulasi');
applyStyle($ws1, 'A6', ['font' => ['italic' => true, 'size' => 8, 'color' => ['argb' => 'FF9F1239']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFF1F2']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);
applyStyle($ws1, 'A4:C6', ['borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FFFECDD3']]]]);

// KPI Card 2: Puncak Lonjakan Cacat
$ws1->mergeCells('E4:G4');
$ws1->setCellValue('E4', 'PUNCAK LONJAKAN CACAT');
applyStyle($ws1, 'E4', ['font' => ['bold' => true, 'size' => 9, 'color' => ['argb' => 'FFB45309']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFFBEB']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);

$ws1->mergeCells('E5:G5');
$ws1->setCellValue('E5', $peakLabel);
applyStyle($ws1, 'E5', ['font' => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFB45309']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFFBEB']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]]);

$ws1->mergeCells('E6:G6');
$ws1->setCellValue('E6', 'Frekuensi insiden tertinggi');
applyStyle($ws1, 'E6', ['font' => ['italic' => true, 'size' => 8, 'color' => ['argb' => 'FF92400E']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFFBEB']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);
applyStyle($ws1, 'E4:G6', ['borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FFFEF3C7']]]]);

// KPI Card 3: Rata-rata per Interval
$ws1->mergeCells('I4:K4');
$ws1->setCellValue('I4', 'RATA-RATA PER INTERVAL');
applyStyle($ws1, 'I4', ['font' => ['bold' => true, 'size' => 9, 'color' => ['argb' => 'FF4338CA']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEEF2FF']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);

$ws1->mergeCells('I5:K5');
$ws1->setCellValue('I5', number_format($partAvgPerInterval, 1) . ' ' . strtoupper($selectedUnit));
applyStyle($ws1, 'I5', ['font' => ['bold' => true, 'size' => 18, 'color' => ['argb' => 'FF4338CA']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEEF2FF']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]]);

$ws1->mergeCells('I6:K6');
$ws1->setCellValue('I6', 'Dari ' . $activeIntervalCount . ' interval pengamatan');
applyStyle($ws1, 'I6', ['font' => ['italic' => true, 'size' => 8, 'color' => ['argb' => 'FF3730A3']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEEF2FF']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);
applyStyle($ws1, 'I4:K6', ['borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FFE0E7FF']]]]);

// KPI Card 4: Kasus Lot Terdampak
$ws1->mergeCells('M4:O4');
$ws1->setCellValue('M4', 'KASUS LOT TERDAMPAK');
applyStyle($ws1, 'M4', ['font' => ['bold' => true, 'size' => 9, 'color' => ['argb' => 'FF6B21A8']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFAF5FF']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);

$ws1->mergeCells('M5:O5');
$ws1->setCellValue('M5', number_format($partLotsTotal) . ' Lot');
applyStyle($ws1, 'M5', ['font' => ['bold' => true, 'size' => 18, 'color' => ['argb' => 'FF6B21A8']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFAF5FF']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]]);

$ws1->mergeCells('M6:O6');
$ws1->setCellValue('M6', 'Total insiden di line OQC');
applyStyle($ws1, 'M6', ['font' => ['italic' => true, 'size' => 8, 'color' => ['argb' => 'FF581C87']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFAF5FF']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);
applyStyle($ws1, 'M4:O6', ['borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FFF3E8FF']]]]);

// ── Timeline Data Table & Chart (Row 8+) ────────────────────────────────────
$ws1->mergeCells('A8:E8');
$ws1->setCellValue('A8', 'DATA TREN KEJADIAN CACAT SEPANJANG WAKTU');
applyStyle($ws1, 'A8', [
    'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2563EB']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1]
]);

$ws1->setCellValue('A9', 'NO');
$ws1->setCellValue('B9', 'INTERVAL / TANGGAL');
$ws1->setCellValue('C9', 'CACAT (PCS)');
$ws1->setCellValue('D9', 'KASUS (LOT)');
$ws1->setCellValue('E9', 'METRIK (' . strtoupper($selectedUnit) . ')');
applyStyle($ws1, 'A9:E9', hdrStyle('0F172A'));

$trRow = 10;
$trendStartRow = $trRow;
$tIndex = 1;

if (empty($trendMap)) {
    $ws1->mergeCells('A10:E10');
    $ws1->setCellValue('A10', 'Tidak ada riwayat fluktuasi waktu pada filter ini.');
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

// Native Excel Line/Column Chart for Timeline Trend
if ($trendDataCount > 0 && $trendEndRow >= $trendStartRow) {
    try {
        $xAxisLabels = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'🎯 Analisis Part & Tren'!\$B\${$trendStartRow}:\$B\${$trendEndRow}", null, $trendDataCount)
        ];
        $dataValues = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'🎯 Analisis Part & Tren'!\$E\${$trendStartRow}:\$E\${$trendEndRow}", null, $trendDataCount)
        ];
        $seriesTitle = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'🎯 Analisis Part & Tren'!\$E\$9", null, 1)
        ];

        // Choose Line Chart or Bar Chart based on interval count
        $chartType = ($trendDataCount <= 12) ? DataSeries::TYPE_BARCHART : DataSeries::TYPE_LINECHART;
        $barDir    = ($trendDataCount <= 12) ? DataSeries::DIRECTION_COL : null;

        $series = new DataSeries(
            $chartType,
            $barDir ? DataSeries::GROUPING_CLUSTERED : DataSeries::GROUPING_STANDARD,
            [0],
            $seriesTitle,
            $xAxisLabels,
            $dataValues
        );
        if ($barDir) $series->setPlotDirection($barDir);

        $layout = new Layout();
        $layout->setShowVal(true);

        $plotArea = new PlotArea($layout, [$series]);
        $legend   = new Legend(Legend::POSITION_BOTTOM, null, false);
        $chartTitle = new Title("Grafik Tren Kejadian Cacat: " . $defectName . " (" . $partCode . ")");
        $chartObj = new Chart('chart_timeline_part', $chartTitle, $legend, $plotArea, true);

        // Position Chart from Column G to P (Rows 8 to max 26)
        $chartObj->setTopLeftPosition('G8');
        $chartObj->setBottomRightPosition('P' . max(25, $trendEndRow + 2));
        $ws1->addChart($chartObj);
    } catch (Exception $chartEx) {}
}


// ═══════════════════════════════════════════════════════════════════════════
// SHEET 2: 🍩 FUNNEL DISTRIBUSI CACAT (3 Pie Charts: Defect, Model, Part)
// ═══════════════════════════════════════════════════════════════════════════
$ws2 = $spreadsheet->createSheet();
$ws2->setTitle("🍩 Funnel Distribusi");

$ws2->getColumnDimension('A')->setWidth(6);
$ws2->getColumnDimension('B')->setWidth(26);
$ws2->getColumnDimension('C')->setWidth(16);
$ws2->getColumnDimension('D')->setWidth(16);
$ws2->getColumnDimension('E')->setWidth(4);

// Header Banner
$ws2->getRowDimension(1)->setRowHeight(32);
$ws2->mergeCells('A1:O1');
$ws2->setCellValue('A1', 'POHON FUNNEL SEBARAN CACAT MUTU (DEFECT → MODEL → PART) — ' . strtoupper($filterTitleDesc));
applyStyle($ws2, 'A1', [
    'font'      => ['bold' => true, 'size' => 12, 'color' => ['argb' => 'FFFFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1E293B']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]
]);

// ── Funnel 1: Distribusi Jenis Defect ────────────────────────────────────────
$ws2->mergeCells('A3:D3');
$ws2->setCellValue('A3', '1. DISTRIBUSI JENIS DEFECT (LEVEL 1)');
applyStyle($ws2, 'A3', hdrStyle('2563EB'));

$ws2->setCellValue('A4', 'NO');
$ws2->setCellValue('B4', 'JENIS DEFECT');
$ws2->setCellValue('C4', 'QTY (' . strtoupper($selectedUnit) . ')');
$ws2->setCellValue('D4', 'KONTRIBUSI');
applyStyle($ws2, 'A4:D4', hdrStyle('0F172A'));

$f1Row = 5;
$f1Start = $f1Row;
$totD1 = array_sum(array_column($funnelDefects, $selectedUnit === 'lot' ? 'lots' : 'pcs')) ?: 1;

foreach ($funnelDefects as $i => $fd) {
    $v = ($selectedUnit === 'lot') ? (int)$fd['lots'] : (int)$fd['pcs'];
    $ws2->setCellValue("A{$f1Row}", $i + 1);
    $ws2->setCellValue("B{$f1Row}", $fd['name']);
    $ws2->setCellValue("C{$f1Row}", $v);
    $ws2->setCellValue("D{$f1Row}", round($v / $totD1, 3));

    $bg = ($i % 2 === 0) ? 'FFFFFF' : 'F8FAFC';
    applyStyle($ws2, "A{$f1Row}", dataStyle($bg, '64748B', false, Alignment::HORIZONTAL_CENTER));
    applyStyle($ws2, "B{$f1Row}", dataStyle($bg, '0F172A', true, Alignment::HORIZONTAL_LEFT));
    applyStyle($ws2, "C{$f1Row}", dataStyle($bg, 'BE123C', true, Alignment::HORIZONTAL_RIGHT));
    applyStyle($ws2, "D{$f1Row}", dataStyle($bg, '0F172A', false, Alignment::HORIZONTAL_RIGHT));

    $ws2->getStyle("C{$f1Row}")->getNumberFormat()->setFormatCode('#,##0');
    $ws2->getStyle("D{$f1Row}")->getNumberFormat()->setFormatCode('0.0%');
    $f1Row++;
}
$f1End = $f1Row - 1;

// Native Pie Chart 1: Jenis Defect
if (!empty($funnelDefects) && $f1End >= $f1Start) {
    try {
        $lbl1 = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'🍩 Funnel Distribusi'!\$B\${$f1Start}:\$B\${$f1End}", null, count($funnelDefects))];
        $val1 = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'🍩 Funnel Distribusi'!\$C\${$f1Start}:\$C\${$f1End}", null, count($funnelDefects))];
        $ser1 = new DataSeries(DataSeries::TYPE_PIECHART, DataSeries::GROUPING_STANDARD, [0], [], $lbl1, $val1);

        $lay1 = new Layout();
        $lay1->setShowPercent(true);
        $lay1->setShowCatName(true);

        $plot1  = new PlotArea($lay1, [$ser1]);
        $leg1   = new Legend(Legend::POSITION_RIGHT, null, false);
        $chart1 = new Chart('chart_funnel_1', new Title("Peringkat Jenis Defect (Level 1)"), $leg1, $plot1, true);
        $chart1->setTopLeftPosition('F3');
        $chart1->setBottomRightPosition('O17');
        $ws2->addChart($chart1);
    } catch (Exception $e) {}
}

// ── Funnel 2: Distribusi Model Produk (Defect Terpilih) ──────────────────────
$f2HeaderRow = 19;
$ws2->mergeCells("A{$f2HeaderRow}:D{$f2HeaderRow}");
$ws2->setCellValue("A{$f2HeaderRow}", '2. MODEL TERDAMPAK: ' . strtoupper($defectName) . ' (LEVEL 2)');
applyStyle($ws2, "A{$f2HeaderRow}", hdrStyle('4F46E5'));

$ws2->setCellValue("A" . ($f2HeaderRow + 1), 'NO');
$ws2->setCellValue("B" . ($f2HeaderRow + 1), 'MODEL PRODUK');
$ws2->setCellValue("C" . ($f2HeaderRow + 1), 'QTY (' . strtoupper($selectedUnit) . ')');
$ws2->setCellValue("D" . ($f2HeaderRow + 1), 'KONTRIBUSI');
applyStyle($ws2, "A" . ($f2HeaderRow + 1) . ":D" . ($f2HeaderRow + 1), hdrStyle('0F172A'));

$f2Row = $f2HeaderRow + 2;
$f2Start = $f2Row;
$totM2 = array_sum(array_column($funnelModels, $selectedUnit === 'lot' ? 'lots' : 'pcs')) ?: 1;

foreach ($funnelModels as $j => $fm) {
    $vM = ($selectedUnit === 'lot') ? (int)$fm['lots'] : (int)$fm['pcs'];
    $ws2->setCellValue("A{$f2Row}", $j + 1);
    $ws2->setCellValue("B{$f2Row}", $fm['name']);
    $ws2->setCellValue("C{$f2Row}", $vM);
    $ws2->setCellValue("D{$f2Row}", round($vM / $totM2, 3));

    $bg = ($j % 2 === 0) ? 'FFFFFF' : 'F8FAFC';
    applyStyle($ws2, "A{$f2Row}", dataStyle($bg, '64748B', false, Alignment::HORIZONTAL_CENTER));
    applyStyle($ws2, "B{$f2Row}", dataStyle($bg, '0F172A', true, Alignment::HORIZONTAL_LEFT));
    applyStyle($ws2, "C{$f2Row}", dataStyle($bg, '4338CA', true, Alignment::HORIZONTAL_RIGHT));
    applyStyle($ws2, "D{$f2Row}", dataStyle($bg, '0F172A', false, Alignment::HORIZONTAL_RIGHT));

    $ws2->getStyle("C{$f2Row}")->getNumberFormat()->setFormatCode('#,##0');
    $ws2->getStyle("D{$f2Row}")->getNumberFormat()->setFormatCode('0.0%');
    $f2Row++;
}
$f2End = $f2Row - 1;

// Native Pie Chart 2: Model Produk
if (!empty($funnelModels) && $f2End >= $f2Start) {
    try {
        $lbl2 = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'🍩 Funnel Distribusi'!\$B\${$f2Start}:\$B\${$f2End}", null, count($funnelModels))];
        $val2 = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'🍩 Funnel Distribusi'!\$C\${$f2Start}:\$C\${$f2End}", null, count($funnelModels))];
        $ser2 = new DataSeries(DataSeries::TYPE_PIECHART, DataSeries::GROUPING_STANDARD, [0], [], $lbl2, $val2);

        $lay2 = new Layout();
        $lay2->setShowPercent(true);
        $lay2->setShowCatName(true);

        $plot2  = new PlotArea($lay2, [$ser2]);
        $leg2   = new Legend(Legend::POSITION_RIGHT, null, false);
        $chart2 = new Chart('chart_funnel_2', new Title("Sebaran Model: " . $defectName), $leg2, $plot2, true);
        $chart2->setTopLeftPosition('F19');
        $chart2->setBottomRightPosition('O33');
        $ws2->addChart($chart2);
    } catch (Exception $e) {}
}

// ── Funnel 3: Distribusi Part Code (Model Terpilih) ─────────────────────────
$f3HeaderRow = 35;
$ws2->mergeCells("A{$f3HeaderRow}:D{$f3HeaderRow}");
$ws2->setCellValue("A{$f3HeaderRow}", '3. PART TERDAMPAK: ' . strtoupper($modelName) . ' (LEVEL 3)');
applyStyle($ws2, "A{$f3HeaderRow}", hdrStyle('059669'));

$ws2->setCellValue("A" . ($f3HeaderRow + 1), 'NO');
$ws2->setCellValue("B" . ($f3HeaderRow + 1), 'PART CODE & NAME');
$ws2->setCellValue("C" . ($f3HeaderRow + 1), 'QTY (' . strtoupper($selectedUnit) . ')');
$ws2->setCellValue("D" . ($f3HeaderRow + 1), 'KONTRIBUSI');
applyStyle($ws2, "A" . ($f3HeaderRow + 1) . ":D" . ($f3HeaderRow + 1), hdrStyle('0F172A'));

$f3Row = $f3HeaderRow + 2;
$f3Start = $f3Row;
$totP3 = array_sum(array_column($funnelParts, $selectedUnit === 'lot' ? 'lots' : 'pcs')) ?: 1;

foreach ($funnelParts as $k => $fp) {
    $vP = ($selectedUnit === 'lot') ? (int)$fp['lots'] : (int)$fp['pcs'];
    $ws2->setCellValue("A{$f3Row}", $k + 1);
    $ws2->setCellValue("B{$f3Row}", $fp['name']);
    $ws2->setCellValue("C{$f3Row}", $vP);
    $ws2->setCellValue("D{$f3Row}", round($vP / $totP3, 3));

    $bg = ($k % 2 === 0) ? 'FFFFFF' : 'F8FAFC';
    applyStyle($ws2, "A{$f3Row}", dataStyle($bg, '64748B', false, Alignment::HORIZONTAL_CENTER));
    applyStyle($ws2, "B{$f3Row}", dataStyle($bg, '0F172A', true, Alignment::HORIZONTAL_LEFT));
    applyStyle($ws2, "C{$f3Row}", dataStyle($bg, '065F46', true, Alignment::HORIZONTAL_RIGHT));
    applyStyle($ws2, "D{$f3Row}", dataStyle($bg, '0F172A', false, Alignment::HORIZONTAL_RIGHT));

    $ws2->getStyle("C{$f3Row}")->getNumberFormat()->setFormatCode('#,##0');
    $ws2->getStyle("D{$f3Row}")->getNumberFormat()->setFormatCode('0.0%');
    $f3Row++;
}
$f3End = $f3Row - 1;

// Native Pie Chart 3: Part Code
if (!empty($funnelParts) && $f3End >= $f3Start) {
    try {
        $lbl3 = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'🍩 Funnel Distribusi'!\$B\${$f3Start}:\$B\${$f3End}", null, count($funnelParts))];
        $val3 = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'🍩 Funnel Distribusi'!\$C\${$f3Start}:\$C\${$f3End}", null, count($funnelParts))];
        $ser3 = new DataSeries(DataSeries::TYPE_PIECHART, DataSeries::GROUPING_STANDARD, [0], [], $lbl3, $val3);

        $lay3 = new Layout();
        $lay3->setShowPercent(true);
        $lay3->setShowCatName(true);

        $plot3  = new PlotArea($lay3, [$ser3]);
        $leg3   = new Legend(Legend::POSITION_RIGHT, null, false);
        $chart3 = new Chart('chart_funnel_3', new Title("Sebaran Part: " . $modelName), $leg3, $plot3, true);
        $chart3->setTopLeftPosition('F35');
        $chart3->setBottomRightPosition('O49');
        $ws2->addChart($chart3);
    } catch (Exception $e) {}
}


// ═══════════════════════════════════════════════════════════════════════════
// SHEET 3: 📋 RIWAYAT TEMUAN (Detailed Audit Trail Log for Part)
// ═══════════════════════════════════════════════════════════════════════════
$ws3 = $spreadsheet->createSheet();
$ws3->setTitle("📋 Riwayat Temuan");

$ws3->getColumnDimension('A')->setWidth(6);   // No
$ws3->getColumnDimension('B')->setWidth(18);  // Tanggal & Jam
$ws3->getColumnDimension('C')->setWidth(18);  // Part Code
$ws3->getColumnDimension('D')->setWidth(26);  // Part Name
$ws3->getColumnDimension('E')->setWidth(18);  // Model
$ws3->getColumnDimension('F')->setWidth(20);  // Defect Type
$ws3->getColumnDimension('G')->setWidth(14);  // Tipe Inspeksi
$ws3->getColumnDimension('H')->setWidth(16);  // No Kanban
$ws3->getColumnDimension('I')->setWidth(22);  // Customer
$ws3->getColumnDimension('J')->setWidth(16);  // Lot Number
$ws3->getColumnDimension('K')->setWidth(16);  // Ref Number
$ws3->getColumnDimension('L')->setWidth(18);  // QC Inspector
$ws3->getColumnDimension('M')->setWidth(15);  // Qty Temuan

// Header Banner
$ws3->getRowDimension(1)->setRowHeight(32);
$ws3->mergeCells('A1:M1');
$ws3->setCellValue('A1', 'CATATAN KRONOLOGIS TEMUAN CACAT MUTU (AUDIT TRAIL LOG)');
applyStyle($ws3, 'A1', [
    'font'      => ['bold' => true, 'size' => 12, 'color' => ['argb' => 'FFFFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF059669']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]
]);

$ws3->mergeCells('A2:M2');
$ws3->setCellValue('A2', 'PART: ' . strtoupper($partCode . ' - ' . $partName) . '  |  DEFECT: ' . strtoupper($defectName) . '  |  PERIODE: ' . strtoupper($filterTitleDesc) . '  |  CUSTOMER: ' . strtoupper($selectedCustomer ?: 'SEMUA CUSTOMER'));
applyStyle($ws3, 'A2', [
    'font'      => ['bold' => true, 'size' => 9, 'color' => ['argb' => 'FF065F46']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFECFDF5']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]
]);

// Table Headers
$ws3->setCellValue('A4', 'NO');
$ws3->setCellValue('B4', 'TANGGAL & JAM');
$ws3->setCellValue('C4', 'PART CODE');
$ws3->setCellValue('D4', 'PART NAME');
$ws3->setCellValue('E4', 'MODEL PRODUK');
$ws3->setCellValue('F4', 'JENIS DEFECT');
$ws3->setCellValue('G4', 'INSPEKSI');
$ws3->setCellValue('H4', 'NO KANBAN');
$ws3->setCellValue('I4', 'CUSTOMER');
$ws3->setCellValue('J4', 'LOT NUMBER');
$ws3->setCellValue('K4', 'REF NUMBER');
$ws3->setCellValue('L4', 'INSPECTOR');
$ws3->setCellValue('M4', 'JUMLAH (PCS)');
applyStyle($ws3, 'A4:M4', hdrStyle('0F172A'));
$ws3->freezePane('A5'); // Freeze panes on row 5 so header stays fixed on scroll

$aRow = 5;
$auditStartRow = $aRow;

if (empty($auditRecords)) {
    $ws3->mergeCells('A5:M5');
    $ws3->setCellValue('A5', 'Tidak ada riwayat catatan temuan cacat pada filter ini.');
    applyStyle($ws3, 'A5:M5', dataStyle('F8FAFC', '94A3B8', false, Alignment::HORIZONTAL_CENTER));
    $aRow++;
} else {
    foreach ($auditRecords as $ai => $a) {
        $no = $ai + 1;
        $ws3->setCellValue("A{$aRow}", $no);
        $ws3->setCellValue("B{$aRow}", date('d/m/Y H:i', strtotime($a['started_at'])) . ' WIB');
        $ws3->setCellValue("C{$aRow}", $a['part_code']);
        $ws3->setCellValue("D{$aRow}", $a['part_name']);
        $ws3->setCellValue("E{$aRow}", $a['model_name']);
        $ws3->setCellValue("F{$aRow}", $a['defect_name']);
        $ws3->setCellValue("G{$aRow}", strtoupper($a['inspection_type']));
        $ws3->setCellValue("H{$aRow}", $a['kanban_no']);
        $ws3->setCellValue("I{$aRow}", $a['customer_name']);
        $ws3->setCellValue("J{$aRow}", $a['lot_number']);
        $ws3->setCellValue("K{$aRow}", $a['ref_number']);
        $ws3->setCellValue("L{$aRow}", $a['inspector_name']);
        $ws3->setCellValue("M{$aRow}", (int)$a['qty_ng']);

        $bg = ($ai % 2 === 0) ? 'FFFFFF' : 'F8FAFC';
        applyStyle($ws3, "A{$aRow}", dataStyle($bg, '64748B', false, Alignment::HORIZONTAL_CENTER));
        applyStyle($ws3, "B{$aRow}", dataStyle($bg, '334155', false, Alignment::HORIZONTAL_CENTER));
        applyStyle($ws3, "C{$aRow}", dataStyle($bg, '1D4ED8', true, Alignment::HORIZONTAL_LEFT));
        applyStyle($ws3, "D{$aRow}", dataStyle($bg, '0F172A', false, Alignment::HORIZONTAL_LEFT));
        applyStyle($ws3, "E{$aRow}", dataStyle($bg, '475569', false, Alignment::HORIZONTAL_LEFT));
        applyStyle($ws3, "F{$aRow}", dataStyle($bg, 'BE123C', true, Alignment::HORIZONTAL_LEFT));
        applyStyle($ws3, "G{$aRow}", dataStyle($bg, '0F172A', false, Alignment::HORIZONTAL_CENTER));
        applyStyle($ws3, "H{$aRow}", dataStyle($bg, '0F172A', false, Alignment::HORIZONTAL_CENTER));
        applyStyle($ws3, "I{$aRow}", dataStyle($bg, '334155', false, Alignment::HORIZONTAL_LEFT));
        applyStyle($ws3, "J{$aRow}", dataStyle($bg, '0F172A', false, Alignment::HORIZONTAL_CENTER));
        applyStyle($ws3, "K{$aRow}", dataStyle($bg, '475569', false, Alignment::HORIZONTAL_CENTER));
        applyStyle($ws3, "L{$aRow}", dataStyle($bg, '0F172A', false, Alignment::HORIZONTAL_LEFT));
        applyStyle($ws3, "M{$aRow}", dataStyle($bg, 'DC2626', true, Alignment::HORIZONTAL_RIGHT));

        $ws3->getStyle("M{$aRow}")->getNumberFormat()->setFormatCode('#,##0');
        $aRow++;
    }
}
$auditEndRow = $aRow - 1;

// Total Summary Row on Audit Sheet
$ws3->mergeCells("A{$aRow}:L{$aRow}");
$ws3->setCellValue("A{$aRow}", "TOTAL TEMUAN CACAT (PCS)");
$ws3->setCellValue("M{$aRow}", "=SUM(M{$auditStartRow}:M{$auditEndRow})");
applyStyle($ws3, "A{$aRow}:L{$aRow}", dataStyle('0F172A', 'FFFFFF', true, Alignment::HORIZONTAL_RIGHT));
applyStyle($ws3, "M{$aRow}", dataStyle('0F172A', 'FFFFFF', true, Alignment::HORIZONTAL_RIGHT));
$ws3->getStyle("M{$aRow}")->getNumberFormat()->setFormatCode('#,##0');

// ── 5. Set Active Sheet & Stream Output ──────────────────────────────────────
$spreadsheet->setActiveSheetIndex(0);

$filenameDate = date('Ymd_His');
$safePartCode = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $partCode);
header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
header("Content-Disposition: attachment; filename=Analisis_Defect_{$safePartCode}_{$filenameDate}.xlsx");
header("Cache-Control: max-age=0");
header("Pragma: no-cache");

$writer = new Xlsx($spreadsheet);
$writer->setIncludeCharts(true);
$writer->save("php://output");
exit;
