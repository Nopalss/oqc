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

if (!empty($_REQUEST['start_date']) && !empty($_REQUEST['end_date'])) {
    $startDate    = sanitize($_REQUEST['start_date']);
    $endDate      = sanitize($_REQUEST['end_date']);
    $presetFilter = 'custom';
} else {
    $presetFilter = sanitize($_REQUEST['preset'] ?? 'bulanan');
    switch ($presetFilter) {
        case 'hari_ini':  $startDate = date('Y-m-d'); $endDate = date('Y-m-d'); break;
        case 'mingguan':  $startDate = date('Y-m-d', strtotime('monday this week')); $endDate = date('Y-m-d'); break;
        case 'tahunan':   $startDate = date('Y-01-01'); $endDate = date('Y-m-d'); break;
        default: $presetFilter = 'bulanan'; $startDate = date('Y-m-01'); $endDate = date('Y-m-d');
    }
}

// ── Data Fetch ──────────────────────────────────────────────────────────────
$kpi = ['total_inspected'=>0,'pass_rate'=>0,'total_ng'=>0,'total_lot'=>0,'pass_count'=>0,'rejected_count'=>0];
$trendMap = []; $defectRows = []; $modelRows = []; $partRows = [];
$worstPartsGrouped = []; $bestPartsPassed = [];

if ($pdo) {
    try {
        $p = [':sd' => $startDate, ':ed' => $endDate];
        $custJoin = ""; $custCond = "";
        if ($selectedCustomer !== '') {
            $custJoin = " LEFT JOIN kanban_items ki ON ss.kanban_item_id = ki.id ";
            $custCond = " AND ki.customer = :cust ";
            $p[':cust'] = $selectedCustomer;
        }

        // 1. Total Inspected
        $stmt = $pdo->prepare("SELECT COUNT(s.id) FROM inspection_samples s INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id {$custJoin} WHERE DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond}");
        $stmt->execute($p); $kpi['total_inspected'] = (int)$stmt->fetchColumn();

        // 2. Lot Sessions
        $stmt = $pdo->prepare("SELECT COUNT(*) AS tl, SUM(ss.status='passed') AS pc, SUM(ss.status='rejected') AS rc FROM inspection_sessions ss {$custJoin} WHERE DATE(ss.started_at) BETWEEN :sd AND :ed {$custCond}");
        $stmt->execute($p); $row = $stmt->fetch();
        $kpi['total_lot']      = (int)($row['tl'] ?? 0);
        $kpi['pass_count']     = (int)($row['pc'] ?? 0);
        $kpi['rejected_count'] = (int)($row['rc'] ?? 0);
        $kpi['pass_rate']      = $kpi['total_lot'] > 0 ? round($kpi['pass_count'] / $kpi['total_lot'] * 100, 1) : 0;

        // 3. Total NG
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(ngr.qty_ng),0) FROM inspection_ng_records ngr INNER JOIN inspection_samples s ON ngr.inspection_sample_id=s.id INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id {$custJoin} WHERE DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond}");
        $stmt->execute($p); $kpi['total_ng'] = (int)$stmt->fetchColumn();

        // 4. Trend Data
        $daysDiff = (strtotime($endDate) - strtotime($startDate)) / 86400;
        if ($presetFilter === 'tahunan' || $daysDiff > 90) {
            $sp = new DateTime(date('Y-m-01', strtotime($startDate)));
            $ep = new DateTime(date('Y-m-01', strtotime($endDate))); $ep->modify('+1 month');
            foreach (new DatePeriod($sp, DateInterval::createFromDateString('1 month'), $ep) as $dt)
                $trendMap[$dt->format('Y-m')] = ['label' => $dt->format('M Y'), 'val' => 0];
            $fmt = '%Y-%m';
        } else {
            $sp = new DateTime($startDate); $ep = new DateTime($endDate); $ep->modify('+1 day');
            foreach (new DatePeriod($sp, DateInterval::createFromDateString('1 day'), $ep) as $dt)
                $trendMap[$dt->format('Y-m-d')] = ['label' => $dt->format('d M'), 'val' => 0];
            $fmt = null;
        }

        if ($selectedResult === 'passed') {
            if ($selectedUnit === 'lot') {
                $qT = $fmt
                    ? "SELECT DATE_FORMAT(ss.started_at, '{$fmt}') AS k, COUNT(DISTINCT CASE WHEN ss.status='passed' THEN ss.id END) AS total FROM inspection_sessions ss {$custJoin} WHERE DATE(ss.started_at) BETWEEN :sd AND :ed {$custCond} GROUP BY k ORDER BY k ASC"
                    : "SELECT DATE(ss.started_at) AS k, COUNT(DISTINCT CASE WHEN ss.status='passed' THEN ss.id END) AS total FROM inspection_sessions ss {$custJoin} WHERE DATE(ss.started_at) BETWEEN :sd AND :ed {$custCond} GROUP BY k ORDER BY k ASC";
            } else {
                $qT = $fmt
                    ? "SELECT DATE_FORMAT(s.checked_at, '{$fmt}') AS k, COUNT(s.id) AS total FROM inspection_samples s INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id {$custJoin} WHERE ss.status='passed' AND DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond} GROUP BY k ORDER BY k ASC"
                    : "SELECT DATE(s.checked_at) AS k, COUNT(s.id) AS total FROM inspection_samples s INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id {$custJoin} WHERE ss.status='passed' AND DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond} GROUP BY k ORDER BY k ASC";
            }
        } else {
            if ($selectedUnit === 'lot') {
                $qT = $fmt
                    ? "SELECT DATE_FORMAT(ss.started_at, '{$fmt}') AS k, COUNT(DISTINCT CASE WHEN ss.status='rejected' THEN ss.id END) AS total FROM inspection_sessions ss {$custJoin} WHERE DATE(ss.started_at) BETWEEN :sd AND :ed {$custCond} GROUP BY k ORDER BY k ASC"
                    : "SELECT DATE(ss.started_at) AS k, COUNT(DISTINCT CASE WHEN ss.status='rejected' THEN ss.id END) AS total FROM inspection_sessions ss {$custJoin} WHERE DATE(ss.started_at) BETWEEN :sd AND :ed {$custCond} GROUP BY k ORDER BY k ASC";
            } else {
                $qT = $fmt
                    ? "SELECT DATE_FORMAT(s.checked_at, '{$fmt}') AS k, COALESCE(SUM(ngr.qty_ng),0) AS total FROM inspection_samples s INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id {$custJoin} LEFT JOIN inspection_ng_records ngr ON ngr.inspection_sample_id=s.id WHERE DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond} GROUP BY k ORDER BY k ASC"
                    : "SELECT DATE(s.checked_at) AS k, COALESCE(SUM(ngr.qty_ng),0) AS total FROM inspection_samples s INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id {$custJoin} LEFT JOIN inspection_ng_records ngr ON ngr.inspection_sample_id=s.id WHERE DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond} GROUP BY k ORDER BY k ASC";
            }
        }
        $stT = $pdo->prepare($qT); $stT->execute($p);
        foreach ($stT->fetchAll() as $r) {
            if (isset($trendMap[$r['k']])) $trendMap[$r['k']]['val'] = (int)$r['total'];
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
                ? "SELECT dt.name, COUNT(DISTINCT s.inspection_session_id) AS total FROM inspection_ng_records ngr INNER JOIN defect_types dt ON ngr.defect_type_id=dt.id INNER JOIN inspection_samples s ON ngr.inspection_sample_id=s.id INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id {$custJoin} WHERE DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond} GROUP BY dt.id,dt.name ORDER BY total DESC"
                : "SELECT dt.name, COALESCE(SUM(ngr.qty_ng),0) AS total FROM inspection_ng_records ngr INNER JOIN defect_types dt ON ngr.defect_type_id=dt.id INNER JOIN inspection_samples s ON ngr.inspection_sample_id=s.id INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id {$custJoin} WHERE DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond} GROUP BY dt.id,dt.name ORDER BY total DESC";
            $stD = $pdo->prepare($qD); $stD->execute($p); $defectRows = $stD->fetchAll(PDO::FETCH_ASSOC);
        }

        // 6. Model Breakdown (SQL strict mode safe)
        $qM = ($selectedResult === 'passed')
            ? ($selectedUnit === 'lot'
                ? "SELECT COALESCE(m.name, mp.model, 'NO MODEL') AS name, COUNT(DISTINCT CASE WHEN ss.status='passed' THEN ss.id END) AS total FROM inspection_sessions ss LEFT JOIN master_parts mp ON ss.part_id=mp.id LEFT JOIN master_models m ON mp.model_id=m.id {$custJoin} WHERE DATE(ss.started_at) BETWEEN :sd AND :ed {$custCond} GROUP BY COALESCE(m.name, mp.model, 'NO MODEL') ORDER BY total DESC"
                : "SELECT COALESCE(m.name, mp.model, 'NO MODEL') AS name, COUNT(s.id) AS total FROM inspection_samples s INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id LEFT JOIN master_parts mp ON ss.part_id=mp.id LEFT JOIN master_models m ON mp.model_id=m.id {$custJoin} WHERE ss.status='passed' AND DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond} GROUP BY COALESCE(m.name, mp.model, 'NO MODEL') ORDER BY total DESC")
            : ($selectedUnit === 'lot'
                ? "SELECT COALESCE(m.name, mp.model, 'NO MODEL') AS name, COUNT(DISTINCT CASE WHEN ss.status='rejected' THEN ss.id END) AS total FROM inspection_ng_records ngr INNER JOIN inspection_samples s ON ngr.inspection_sample_id=s.id INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id LEFT JOIN master_parts mp ON ss.part_id=mp.id LEFT JOIN master_models m ON mp.model_id=m.id {$custJoin} WHERE DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond} GROUP BY COALESCE(m.name, mp.model, 'NO MODEL') ORDER BY total DESC"
                : "SELECT COALESCE(m.name, mp.model, 'NO MODEL') AS name, COALESCE(SUM(ngr.qty_ng),0) AS total FROM inspection_ng_records ngr INNER JOIN inspection_samples s ON ngr.inspection_sample_id=s.id INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id LEFT JOIN master_parts mp ON ss.part_id=mp.id LEFT JOIN master_models m ON mp.model_id=m.id {$custJoin} WHERE DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond} GROUP BY COALESCE(m.name, mp.model, 'NO MODEL') ORDER BY total DESC");
        $stM = $pdo->prepare($qM); $stM->execute($p); $modelRows = $stM->fetchAll(PDO::FETCH_ASSOC);

        // 7. Part Breakdown (SQL strict mode safe)
        $qP = ($selectedResult === 'passed')
            ? ($selectedUnit === 'lot'
                ? "SELECT COALESCE(mp.part_code,'-') AS part_code, COALESCE(mp.part_name,'UNKNOWN PART') AS part_name, COUNT(DISTINCT CASE WHEN ss.status='passed' THEN ss.id END) AS total FROM inspection_sessions ss LEFT JOIN master_parts mp ON ss.part_id=mp.id {$custJoin} WHERE DATE(ss.started_at) BETWEEN :sd AND :ed {$custCond} GROUP BY mp.id, mp.part_code, mp.part_name ORDER BY total DESC"
                : "SELECT COALESCE(mp.part_code,'-') AS part_code, COALESCE(mp.part_name,'UNKNOWN PART') AS part_name, COUNT(s.id) AS total FROM inspection_samples s INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id LEFT JOIN master_parts mp ON ss.part_id=mp.id {$custJoin} WHERE ss.status='passed' AND DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond} GROUP BY mp.id, mp.part_code, mp.part_name ORDER BY total DESC")
            : ($selectedUnit === 'lot'
                ? "SELECT COALESCE(mp.part_code,'-') AS part_code, COALESCE(mp.part_name,'UNKNOWN PART') AS part_name, COUNT(DISTINCT CASE WHEN ss.status='rejected' THEN ss.id END) AS total FROM inspection_ng_records ngr INNER JOIN inspection_samples s ON ngr.inspection_sample_id=s.id INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id LEFT JOIN master_parts mp ON ss.part_id=mp.id {$custJoin} WHERE DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond} GROUP BY mp.id, mp.part_code, mp.part_name ORDER BY total DESC"
                : "SELECT COALESCE(mp.part_code,'-') AS part_code, COALESCE(mp.part_name,'UNKNOWN PART') AS part_name, COALESCE(SUM(ngr.qty_ng),0) AS total FROM inspection_ng_records ngr INNER JOIN inspection_samples s ON ngr.inspection_sample_id=s.id INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id LEFT JOIN master_parts mp ON ss.part_id=mp.id {$custJoin} WHERE DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond} GROUP BY mp.id, mp.part_code, mp.part_name ORDER BY total DESC");
        $stP = $pdo->prepare($qP); $stP->execute($p); $partRows = $stP->fetchAll(PDO::FETCH_ASSOC);

        // 8. Worst / Best Parts (SQL strict mode safe)
        if ($selectedResult === 'passed') {
            $stB = $pdo->prepare("SELECT COALESCE(mp.part_name,'UNKNOWN PART') AS part_name, COALESCE(mp.part_code,'-') AS part_code, COALESCE(m.name,mp.model,'-') AS model, COUNT(DISTINCT ss.id) AS lot_case, COUNT(DISTINCT CASE WHEN ss.status='passed' THEN ss.id END) AS passed_lot_case FROM inspection_sessions ss LEFT JOIN master_parts mp ON ss.part_id=mp.id LEFT JOIN master_models m ON mp.model_id=m.id {$custJoin} WHERE DATE(ss.started_at) BETWEEN :sd AND :ed {$custCond} GROUP BY mp.id, mp.part_name, mp.part_code, m.id, m.name, mp.model HAVING passed_lot_case>0 ORDER BY passed_lot_case DESC,lot_case DESC LIMIT 5");
            $stB->execute($p); $bestPartsPassed = $stB->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stWD = $pdo->prepare("SELECT dt.name FROM inspection_ng_records ngr INNER JOIN defect_types dt ON ngr.defect_type_id=dt.id INNER JOIN inspection_samples s ON ngr.inspection_sample_id=s.id INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id {$custJoin} WHERE DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond} GROUP BY dt.id,dt.name ORDER BY COALESCE(SUM(ngr.qty_ng),0) DESC LIMIT 3");
            $stWD->execute($p); $top3 = $stWD->fetchAll(PDO::FETCH_COLUMN);
            foreach ($top3 as $dn) {
                $pD = array_merge($p, [':defname' => $dn]);
                $stW = $pdo->prepare("SELECT COALESCE(mp.part_name,'UNKNOWN PART') AS part_name, COALESCE(mp.part_code,'-') AS part_code, COALESCE(m.name,mp.model,'-') AS model, COUNT(DISTINCT ss.id) AS lot_case, COUNT(DISTINCT CASE WHEN ss.status='rejected' THEN ss.id END) AS ng_case FROM inspection_ng_records ngr INNER JOIN defect_types dt ON ngr.defect_type_id=dt.id INNER JOIN inspection_samples s ON ngr.inspection_sample_id=s.id INNER JOIN inspection_sessions ss ON s.inspection_session_id=ss.id LEFT JOIN master_parts mp ON ss.part_id=mp.id LEFT JOIN master_models m ON mp.model_id=m.id {$custJoin} WHERE dt.name=:defname AND DATE(s.checked_at) BETWEEN :sd AND :ed {$custCond} GROUP BY mp.id, mp.part_name, mp.part_code, m.id, m.name, mp.model ORDER BY ng_case DESC,lot_case DESC LIMIT 3");
                $stW->execute($pD); $worstPartsGrouped[$dn] = $stW->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } catch (PDOException $e) {}
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
$ws1->setTitle("📊 Summary & KPI");

$ws1->getRowDimension(1)->setRowHeight(40);
$ws1->getRowDimension(2)->setRowHeight(22);

foreach (['A'=>28,'B'=>24,'C'=>22,'D'=>22,'E'=>22,'F'=>22] as $col=>$w)
    $ws1->getColumnDimension($col)->setWidth($w);

$ws1->mergeCells('A1:F1');
$ws1->setCellValue('A1', 'PT. SURYA TECHNOLOGY INDUSTRI — QUALITY CONTROL DIVISION');
applyStyle($ws1,'A1',['font'=>['bold'=>true,'size'=>16,'color'=>['argb'=>'FF0F172A']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FFDBEAFE']],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],'borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['argb'=>'FF93C5FD']]]]);

$ws1->mergeCells('A2:F2');
$ws1->setCellValue('A2', 'REKAPITULASI DASHBOARD INSPEKSI OQC  |  PERIODE: '.date('d/m/Y', strtotime($startDate)).' s.d '.date('d/m/Y', strtotime($endDate)).'  |  STATUS: '.$statusText.'  |  SATUAN: '.strtoupper($selectedUnit));
applyStyle($ws1,'A2',['font'=>['bold'=>true,'size'=>10,'color'=>['argb'=>'FF1E40AF']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FFEFF6FF']],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER]]);

$ws1->setCellValue('A4','METRIC KPI');
$ws1->setCellValue('B4','NILAI');
applyStyle($ws1,'A4:B4',hdrStyle('0F172A'));

$kpiData = [
    ['Total Inspected (Pcs)', number_format($kpi['total_inspected']).' pcs'],
    ['Pass Rate (%)', $kpi['pass_rate'].'%'],
    ['Total NG (Pcs)', number_format($kpi['total_ng']).' pcs'],
    ['Total Lot Session', number_format($kpi['total_lot']).' lot'],
    ['Passed Lot', number_format($kpi['pass_count']).' lot'],
    ['Rejected Lot', number_format($kpi['rejected_count']).' lot'],
    ['Customer Filter', $selectedCustomer ?: 'Semua Customer'],
    ['Periode', date('d/m/Y', strtotime($startDate)).' s.d '.date('d/m/Y', strtotime($endDate))],
    ['Dicetak pada', date('d/m/Y H:i:s').' WIB'],
];

$row = 5;
foreach ($kpiData as $i => [$label,$val]) {
    $ws1->setCellValue("A{$row}", $label);
    $ws1->setCellValue("B{$row}", $val);
    $rowBg = ($i % 2 === 0) ? 'F8FAFC' : 'FFFFFF';
    $valBg = $rowBg; $valFg = '1E293B'; $bold = false;
    if ($label === 'Pass Rate (%)') { $valFg = $kpi['pass_rate'] >= 90 ? '059669' : ($kpi['pass_rate'] >= 70 ? 'D97706' : 'DC2626'); $bold = true; }
    if ($label === 'Total NG (Pcs)') { $valFg = 'DC2626'; $bold = true; }
    if ($label === 'Passed Lot') { $valBg = 'F0FDF4'; $valFg = '059669'; $bold = true; }
    if ($label === 'Rejected Lot') { $valBg = 'FFF1F2'; $valFg = 'DC2626'; $bold = true; }
    applyStyle($ws1,"A{$row}",dataStyle($rowBg,'334155',true));
    applyStyle($ws1,"B{$row}",dataStyle($valBg,$valFg,$bold,Alignment::HORIZONTAL_RIGHT));
    $row++;
}

// ═══════════════════════════════════════════════════════════════════════════
// SHEET 2: DATA TREN (Source for Bar/Line Chart)
// ═══════════════════════════════════════════════════════════════════════════
$ws2 = $spreadsheet->createSheet();
$ws2->setTitle("📈 Tren Waktu");

$ws2->getColumnDimension('A')->setWidth(8);
$ws2->getColumnDimension('B')->setWidth(20);
$ws2->getColumnDimension('C')->setWidth(20);
$ws2->getRowDimension(1)->setRowHeight(30);

$trendTitle = ($selectedResult === 'passed' ? 'PASSED' : 'REJECTED / NG') . ' PER WAKTU ('.$unitText.')';
$ws2->mergeCells('A1:C1');
$ws2->setCellValue('A1', 'DATA TREN INSPEKSI — '.$trendTitle);
applyStyle($ws2,'A1',hdrStyle('0F172A'));

$ws2->setCellValue('A2','NO'); $ws2->setCellValue('B2','PERIODE'); $ws2->setCellValue('C2','JUMLAH ('.$unitText.')');
applyStyle($ws2,'A2:C2',hdrStyle('334155'));

$tRow = 3; $tNo = 1;
$trendDataStartRow = $tRow;
foreach ($trendMap as $tVal) {
    $ws2->setCellValue("A{$tRow}", $tNo++);
    $ws2->setCellValue("B{$tRow}", $tVal['label']);
    $ws2->setCellValue("C{$tRow}", (int)$tVal['val']);
    $bg = ($tNo % 2 === 0) ? 'F8FAFC' : 'FFFFFF';
    applyStyle($ws2,"A{$tRow}",dataStyle($bg,'64748B',false,Alignment::HORIZONTAL_CENTER));
    applyStyle($ws2,"B{$tRow}",dataStyle($bg,'1E293B',true));
    applyStyle($ws2,"C{$tRow}",dataStyle($bg,'1D4ED8',true,Alignment::HORIZONTAL_RIGHT));
    $tRow++;
}
$trendDataEndRow = $tRow - 1;

// Bar/Line Chart: Tren Waktu
$trendCount = count($trendMap);
if ($trendCount > 0) {
    $chartType = ($trendCount <= 10) ? DataSeries::TYPE_BARCHART : DataSeries::TYPE_LINECHART;
    $barDir    = ($trendCount <= 10) ? DataSeries::DIRECTION_COL : null;

    $labelsRef  = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'📈 Tren Waktu'!\$B\${$trendDataStartRow}:\$B\${$trendDataEndRow}", null, $trendCount);
    $dataValues = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'📈 Tren Waktu'!\$C\${$trendDataStartRow}:\$C\${$trendDataEndRow}", null, $trendCount);
    $seriesLabel = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'📈 Tren Waktu'!\$C\$2", null, 1);
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
    $chartObj->setTopLeftPosition('E2');
    $chartObj->setBottomRightPosition('O'.max(18, $trendDataEndRow + 3));
    $ws2->addChart($chartObj);
}

// ═══════════════════════════════════════════════════════════════════════════
// SHEET 3: BREAKDOWN DEFECT (Source for Pie Chart)
// ═══════════════════════════════════════════════════════════════════════════
$ws3 = $spreadsheet->createSheet();
$ws3->setTitle("🍩 Breakdown Defect");

$ws3->getColumnDimension('A')->setWidth(8);
$ws3->getColumnDimension('B')->setWidth(32);
$ws3->getColumnDimension('C')->setWidth(18);
$ws3->getColumnDimension('D')->setWidth(18);

$ws3->mergeCells('A1:D1');
$defTitle = ($selectedResult === 'passed') ? 'BREAKDOWN STATUS YIELD (PASSED/REJECTED)' : 'BREAKDOWN JENIS DEFECT / NG';
$ws3->setCellValue('A1', $defTitle.' ('.$unitText.')');
applyStyle($ws3,'A1',hdrStyle('0F172A'));

$ws3->setCellValue('A2','NO'); $ws3->setCellValue('B2','JENIS DEFECT / KATEGORI');
$ws3->setCellValue('C2','TOTAL ('.$unitText.')'); $ws3->setCellValue('D2','PERSENTASE (%)');
applyStyle($ws3,'A2:D2',hdrStyle('334155'));

$dRow = 3; $dNo = 1;
$defStartRow = $dRow;
$totalDef = array_sum(array_column($defectRows, 'total')) ?: 1;

if (empty($defectRows)) {
    $ws3->setCellValue("A3", 1);
    $ws3->setCellValue("B3", "Tidak ada data NG");
    $ws3->setCellValue("C3", 0);
    $ws3->setCellValue("D3", 0);
    applyStyle($ws3,"A3:D3",dataStyle('F8FAFC','64748B',false,Alignment::HORIZONTAL_CENTER));
    $dRow = 4;
} else {
    foreach ($defectRows as $dr) {
        $dpct = round($dr['total'] / $totalDef * 100, 1);
        $ws3->setCellValue("A{$dRow}", $dNo++);
        $ws3->setCellValue("B{$dRow}", $dr['name']);
        $ws3->setCellValue("C{$dRow}", (int)$dr['total']);
        $ws3->setCellValue("D{$dRow}", $dpct);
        $bg = ($dNo % 2 === 0) ? 'FFF1F2' : 'FFFFFF';
        applyStyle($ws3,"A{$dRow}",dataStyle($bg,'64748B',false,Alignment::HORIZONTAL_CENTER));
        applyStyle($ws3,"B{$dRow}",dataStyle($bg,'1E293B',true));
        applyStyle($ws3,"C{$dRow}",dataStyle($bg,'DC2626',true,Alignment::HORIZONTAL_RIGHT));
        applyStyle($ws3,"D{$dRow}",dataStyle('FEF2F2','BE123C',true,Alignment::HORIZONTAL_CENTER));
        $dRow++;
    }
}
$defEndRow = $dRow - 1;
$defCount  = $defEndRow - $defStartRow + 1;

// Pie Chart: Breakdown Defect with Labels & Large Size
if ($defCount > 0) {
    $dLabelRef = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'🍩 Breakdown Defect'!\$B\${$defStartRow}:\$B\${$defEndRow}", null, $defCount);
    $dDataRef  = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'🍩 Breakdown Defect'!\$C\${$defStartRow}:\$C\${$defEndRow}", null, $defCount);
    $dSLabel   = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'🍩 Breakdown Defect'!\$C\$2", null, 1);

    $dSeries  = new DataSeries(DataSeries::TYPE_PIECHART, DataSeries::GROUPING_STANDARD, [0], [$dSLabel], [$dLabelRef], [$dDataRef]);
    
    $dLayout  = new Layout();
    $dLayout->setShowVal(true);
    $dLayout->setShowCatName(true);
    $dLayout->setShowPercent(true);

    $dPlot    = new PlotArea($dLayout, [$dSeries]);
    $dLegend  = new Legend(Legend::POSITION_RIGHT, null, false);
    $dChart   = new Chart('chartDefect', new Title($defTitle), $dLegend, $dPlot, true);
    $dChart->setTopLeftPosition('F2');
    $dChart->setBottomRightPosition('P18'); // Large chart frame so pie is big
    $ws3->addChart($dChart);
}

// ═══════════════════════════════════════════════════════════════════════════
// SHEET 4: DISTRIBUSI MODEL (Source for Bar Chart)
// ═══════════════════════════════════════════════════════════════════════════
$ws4 = $spreadsheet->createSheet();
$ws4->setTitle("📐 Distribusi Model");

$ws4->getColumnDimension('A')->setWidth(8);
$ws4->getColumnDimension('B')->setWidth(32);
$ws4->getColumnDimension('C')->setWidth(18);
$ws4->getColumnDimension('D')->setWidth(18);

$ws4->mergeCells('A1:D1');
$modelTitle = 'DISTRIBUSI HASIL INSPEKSI PER MODEL PRODUK ('.$unitText.')';
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
if ($mCount > 0) {
    $mLabelRef = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'📐 Distribusi Model'!\$B\${$mStartRow}:\$B\${$mEndRow}", null, $mCount);
    $mDataRef  = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'📐 Distribusi Model'!\$C\${$mStartRow}:\$C\${$mEndRow}", null, $mCount);
    $mSLabel   = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'📐 Distribusi Model'!\$C\$2", null, 1);
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
$ws5->setTitle("🔩 Distribusi Part");

$ws5->getColumnDimension('A')->setWidth(8);
$ws5->getColumnDimension('B')->setWidth(32);
$ws5->getColumnDimension('C')->setWidth(20);
$ws5->getColumnDimension('D')->setWidth(18);
$ws5->getColumnDimension('E')->setWidth(18);

$ws5->mergeCells('A1:E1');
$ws5->setCellValue('A1','DISTRIBUSI HASIL INSPEKSI PER PART CODE ('.$unitText.')');
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

// Bar Chart: Part Distribution (Top 10)
$top10Rows = array_slice($partRows, 0, 10);
if (!empty($top10Rows)) {
    $top10Count   = count($top10Rows);
    $top10EndRow  = $pStartRow + $top10Count - 1;
    $ptLabelRef   = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'🔩 Distribusi Part'!\$B\${$pStartRow}:\$B\${$top10EndRow}", null, $top10Count);
    $ptDataRef    = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'🔩 Distribusi Part'!\$D\${$pStartRow}:\$D\${$top10EndRow}", null, $top10Count);
    $ptSLabel     = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'🔩 Distribusi Part'!\$D\$2", null, 1);
    $ptDataRef->setFillColor('3B82F6');

    $ptSeries = new DataSeries(DataSeries::TYPE_BARCHART, DataSeries::GROUPING_CLUSTERED, [0], [$ptSLabel], [$ptLabelRef], [$ptDataRef]);
    $ptSeries->setPlotDirection(DataSeries::DIRECTION_BAR);

    $ptLayout = new Layout();
    $ptLayout->setShowVal(true);

    $ptPlot   = new PlotArea($ptLayout, [$ptSeries]);
    $ptChart  = new Chart('chartPart', new Title('TOP 10 PART CODE ('.$unitText.')'), new Legend(Legend::POSITION_BOTTOM,null,false), $ptPlot, true);
    $ptChart->setTopLeftPosition('G2');
    $ptChart->setBottomRightPosition('Q18');
    $ws5->addChart($ptChart);
}

// ═══════════════════════════════════════════════════════════════════════════
// SHEET 6: WORST / BEST PARTS
// ═══════════════════════════════════════════════════════════════════════════
$ws6 = $spreadsheet->createSheet();
$ws6->setTitle($selectedResult === 'passed' ? "🏆 Best Parts" : "🚨 Worst Parts");

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
            $ws6->setCellValue("A{$w6Row}", '🚨 DEFECT: '.strtoupper($defCat));
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
