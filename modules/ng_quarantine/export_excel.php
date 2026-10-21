<?php
/**
 * Official Excel Report Generator — Monitoring Lot NG & Status Karantina OQC
 * File: modules/ng_quarantine/export_excel.php
 * Format: Clean Microsoft Excel (.xlsx) using PhpOffice\PhpSpreadsheet
 */

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';
require_once __DIR__ . '/../../vendor/autoload.php';

if (!is_logged_in()) {
    redirect('modules/auth/login.php');
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

$pdo = getDB();

// -----------------------------------------------------------------------------
// Parameters & Filter Input (Synchronized with index.php)
// -----------------------------------------------------------------------------
$period         = $_GET['period'] ?? 'all';
$startDate      = sanitize($_GET['start_date'] ?? '');
$endDate        = sanitize($_GET['end_date'] ?? '');
$selectedMonth  = (int)($_GET['month'] ?? date('n'));
$selectedYear   = (int)($_GET['year'] ?? date('Y'));
$statusHandling = sanitize($_GET['status_handling'] ?? 'all');
$agingFilter    = sanitize($_GET['aging'] ?? 'all');
$search         = trim(sanitize($_GET['search'] ?? ''));

// Date filter bounds calculation
$todayStart = date('Y-m-d 00:00:00');
$todayEnd   = date('Y-m-d 23:59:59');

$wStart = date('Y-m-d 00:00:00', strtotime('monday this week'));
$wEnd   = date('Y-m-d 23:59:59', strtotime('sunday this week'));

$mStart = date('Y-m-01 00:00:00');
$mEnd   = date('Y-m-t 23:59:59');

$yStart = date('Y-01-01 00:00:00');
$yEnd   = date('Y-12-31 23:59:59');

$safeMonth = sprintf('%02d', max(1, min(12, $selectedMonth)));
$safeYear  = max(2020, min(2035, $selectedYear));
$myStart   = "{$safeYear}-{$safeMonth}-01 00:00:00";
$myEndDay  = date('t', strtotime("{$safeYear}-{$safeMonth}-01"));
$myEnd     = "{$safeYear}-{$safeMonth}-{$myEndDay} 23:59:59";

$whereConditions = [];
$queryParams     = [];

// Scope query to NG lots (lots with NG records or rejected status)
$whereConditions[] = "(
    isl.lot_result = 'rejected' 
    OR isl.lot_status IN ('rejected', 'ng_quarantine')
    OR EXISTS (
        SELECT 1 FROM inspection_ng_records ngr 
        WHERE ngr.session_lot_id = isl.id 
          AND (ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)
    )
)";

// Exclude direct pass dummy records
$whereConditions[] = "(isl.ref_number IS NULL OR (isl.ref_number != 'DIRECT-PASS' AND isl.ref_number NOT LIKE '%DIRECT-PASS%'))";
$whereConditions[] = "(s.approval_notes IS NULL OR s.approval_notes NOT LIKE '%Direct Close%')";

// Period filter logic
$periodLabel = "Semua Waktu";
if ($period === 'today') {
    $whereConditions[] = "s.started_at BETWEEN :t_start AND :t_end";
    $queryParams[':t_start'] = $todayStart;
    $queryParams[':t_end']   = $todayEnd;
    $periodLabel = "Hari Ini (" . date('d/m/Y') . ")";
} elseif ($period === 'this_week') {
    $whereConditions[] = "s.started_at BETWEEN :w_start AND :w_end";
    $queryParams[':w_start'] = $wStart;
    $queryParams[':w_end']   = $wEnd;
    $periodLabel = "Minggu Ini (" . date('d/m', strtotime('monday this week')) . " - " . date('d/m/Y', strtotime('sunday this week')) . ")";
} elseif ($period === 'this_month') {
    $whereConditions[] = "s.started_at BETWEEN :m_start AND :m_end";
    $queryParams[':m_start'] = $mStart;
    $queryParams[':m_end']   = $mEnd;
    $periodLabel = "Bulan Ini (" . date('F Y') . ")";
} elseif ($period === 'this_year') {
    $whereConditions[] = "s.started_at BETWEEN :y_start AND :y_end";
    $queryParams[':y_start'] = $yStart;
    $queryParams[':y_end']   = $yEnd;
    $periodLabel = "Tahun Ini (" . date('Y') . ")";
} elseif ($period === 'month_year') {
    $whereConditions[] = "s.started_at BETWEEN :my_start AND :my_end";
    $queryParams[':my_start'] = $myStart;
    $queryParams[':my_end']   = $myEnd;
    $periodLabel = "Bulan " . date('F', mktime(0, 0, 0, (int)$safeMonth, 10)) . " {$safeYear}";
} elseif ($period === 'custom') {
    if (!empty($startDate) && !empty($endDate)) {
        $whereConditions[] = "s.started_at BETWEEN :c_start AND :c_end";
        $queryParams[':c_start'] = $startDate . ' 00:00:00';
        $queryParams[':c_end']   = $endDate . ' 23:59:59';
        $periodLabel = date('d/m/Y', strtotime($startDate)) . " s/d " . date('d/m/Y', strtotime($endDate));
    } elseif (!empty($startDate)) {
        $whereConditions[] = "s.started_at >= :c_start";
        $queryParams[':c_start'] = $startDate . ' 00:00:00';
        $periodLabel = "Mulai " . date('d/m/Y', strtotime($startDate));
    } elseif (!empty($endDate)) {
        $whereConditions[] = "s.started_at <= :c_end";
        $queryParams[':c_end']   = $endDate . ' 23:59:59';
        $periodLabel = "Sampai " . date('d/m/Y', strtotime($endDate));
    }
}

$whereClause = implode(" AND ", $whereConditions);

$sql = "
    SELECT 
        isl.id AS lot_id,
        isl.inspection_session_id,
        isl.lot_number,
        isl.ref_number,
        isl.qty AS qty_lot,
        isl.sample_size,
        isl.lot_result,
        isl.lot_status,
        isl.replaced_by_lot_id,
        isl.remarks,
        isl.chief_notes AS action_notes,
        isl.action_noted_at,
        isl.created_at AS lot_created_at,
        isl.scanned_qr_raw,
        isl.closed_at AS lot_closed_at,
        s.id AS session_id,
        s.started_at AS session_started_at,
        s.closed_at AS session_closed_at,
        s.status AS session_status,
        s.inspector_id,
        s.chief_notes,
        s.reinspection_type,
        COALESCE(u.name, 'QC Inspector') AS inspector_name,
        k.id AS kanban_id,
        k.item_code AS part_code,
        COALESCE(NULLIF(mp.part_name, ''), k.item_description, k.item_code) AS part_name,
        COALESCE(mm.name, mp.model, '-') AS model_name,
        k.customer,
        k.str_loc
    FROM inspection_session_lots isl
    JOIN inspection_sessions s ON s.id = isl.inspection_session_id
    JOIN kanban_items k ON k.id = s.kanban_item_id
    LEFT JOIN master_parts mp ON mp.part_code = k.item_code
    LEFT JOIN master_models mm ON mm.id = mp.model_id
    LEFT JOIN users u ON u.id = s.inspector_id
    WHERE {$whereClause}
    ORDER BY isl.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($queryParams);
$allNgLots = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Collect all unique NG groups from $allNgLots
$ngGroupKeys = [];
$refNumbers = [];
$unreferencedNgLots = [];

foreach ($allNgLots as $row) {
    $pCode = strtoupper(trim($row['part_code'] ?? ''));
    $lNum  = strtoupper(trim($row['lot_number'] ?? ''));
    $rNum  = strtoupper(trim($row['ref_number'] ?? ''));
    $groupKey = $pCode . '|' . $lNum . '|' . $rNum;
    $ngGroupKeys[$groupKey] = true;

    if (!empty($rNum)) {
        $refNumbers[] = $row['ref_number'];
    } else {
        $unreferencedNgLots[] = [
            'part_code' => $row['part_code'],
            'lot_number' => $row['lot_number']
        ];
    }
}
$refNumbers = array_filter(array_unique($refNumbers));
$allSessionsForLots = $allNgLots;

if (!empty($refNumbers) || !empty($unreferencedNgLots)) {
    $whereParts = [];
    $params = [];

    if (!empty($refNumbers)) {
        $pRef = implode(',', array_fill(0, count($refNumbers), '?'));
        $whereParts[] = "isl.ref_number IN ($pRef)";
        foreach ($refNumbers as $r) $params[] = $r;
    }

    foreach ($unreferencedNgLots as $uLot) {
        $whereParts[] = "(k.item_code = ? AND isl.lot_number = ? AND (isl.ref_number IS NULL OR isl.ref_number = ''))";
        $params[] = $uLot['part_code'];
        $params[] = $uLot['lot_number'];
    }

    $whereRelStr = "(" . implode(" OR ", $whereParts) . ")";

    $sqlAllRounds = "
        SELECT 
            isl.id AS lot_id,
            isl.inspection_session_id,
            isl.lot_number,
            isl.ref_number,
            isl.qty AS qty_lot,
            isl.sample_size,
            isl.lot_result,
            isl.lot_status,
            isl.replaced_by_lot_id,
            isl.remarks,
            isl.chief_notes AS action_notes,
            isl.action_noted_at,
            isl.created_at AS lot_created_at,
            isl.scanned_qr_raw,
            isl.closed_at AS lot_closed_at,
            s.id AS session_id,
            s.started_at AS session_started_at,
            s.closed_at AS session_closed_at,
            s.status AS session_status,
            s.inspector_id,
            s.chief_notes,
            s.reinspection_type,
            COALESCE(u.name, 'QC Inspector') AS inspector_name,
            k.id AS kanban_id,
            k.item_code AS part_code,
            COALESCE(NULLIF(mp.part_name, ''), k.item_description, k.item_code) AS part_name,
            COALESCE(mm.name, mp.model, '-') AS model_name,
            k.customer,
            k.str_loc
        FROM inspection_session_lots isl
        JOIN inspection_sessions s ON s.id = isl.inspection_session_id
        JOIN kanban_items k ON k.id = s.kanban_item_id
        LEFT JOIN master_parts mp ON mp.part_code = k.item_code
        LEFT JOIN master_models mm ON mm.id = mp.model_id
        LEFT JOIN users u ON u.id = s.inspector_id
        WHERE {$whereRelStr}
          AND (isl.ref_number IS NULL OR (isl.ref_number != 'DIRECT-PASS' AND isl.ref_number NOT LIKE '%DIRECT-PASS%'))
          AND (s.approval_notes IS NULL OR s.approval_notes NOT LIKE '%Direct Close%')
        ORDER BY isl.id DESC
    ";

    $stmtRounds = $pdo->prepare($sqlAllRounds);
    $stmtRounds->execute($params);
    $fetchedRounds = $stmtRounds->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($fetchedRounds)) {
        $allSessionsForLots = $fetchedRounds;
    }
}

// Fetch Defect Details
$allLotIds = array_column($allSessionsForLots, 'lot_id');
$defectsByLot = [];
if (!empty($allLotIds)) {
    $inLotIds = implode(',', array_map('intval', array_unique($allLotIds)));
    $ngSql = "
        SELECT n.*, dt.name AS defect_name, dt.code AS defect_code
        FROM inspection_ng_records n
        JOIN defect_types dt ON dt.id = n.defect_type_id
        WHERE n.session_lot_id IN ($inLotIds)
          AND (n.is_cancelled IS NULL OR n.is_cancelled = 0)
        ORDER BY n.id ASC
    ";
    $stmtNg = $pdo->query($ngSql);
    while ($nRow = $stmtNg->fetch(PDO::FETCH_ASSOC)) {
        $lId = (int)$nRow['session_lot_id'];
        $defectsByLot[$lId][] = $nRow;
    }
}

// Fetch Closures
$closuresByLot = [];
if (!empty($allLotIds)) {
    $inLotIds = implode(',', array_map('intval', array_unique($allLotIds)));
    $closureSql = "
        SELECT lqc.*, u.name AS closed_by_name
        FROM lot_quarantine_closures lqc
        LEFT JOIN users u ON u.id = lqc.closed_by
        WHERE lqc.session_lot_id IN ($inLotIds)
        ORDER BY lqc.id DESC
    ";
    $stmtClosure = $pdo->query($closureSql);
    while ($cRow = $stmtClosure->fetch(PDO::FETCH_ASSOC)) {
        $lId = (int)$cRow['session_lot_id'];
        if (!isset($closuresByLot[$lId])) {
            $closuresByLot[$lId] = $cRow;
        }
    }
}

// Lot Grouping
$groupedNgLotsMap = [];
foreach ($allSessionsForLots as $row) {
    $pCode = strtoupper(trim($row['part_code'] ?? ''));
    $lNum  = strtoupper(trim($row['lot_number'] ?? ''));
    $rNum  = strtoupper(trim($row['ref_number'] ?? ''));
    $groupKey = $pCode . '|' . $lNum . '|' . $rNum;

    if (!isset($ngGroupKeys[$groupKey])) continue;

    if (!isset($groupedNgLotsMap[$groupKey])) {
        $groupedNgLotsMap[$groupKey] = [];
    }
    $groupedNgLotsMap[$groupKey][] = $row;
}

$processedNgLots = [];
$statsTotal       = 0;
$statsOpen        = 0;
$statsClosed      = 0;
$statsAction      = 0;
$statsAbolished   = 0;
$statsAging1to3   = 0;
$statsAging4to6   = 0;
$statsAging7plus  = 0;

foreach ($groupedNgLotsMap as $groupKey => $groupRows) {
    $latestRow  = $groupRows[0];
    $roundCount = count($groupRows);

    $groupClosure = null;
    foreach ($groupRows as $gRow) {
        $gLotId = (int)$gRow['lot_id'];
        if (isset($closuresByLot[$gLotId])) {
            $groupClosure = $closuresByLot[$gLotId];
            break;
        }
    }
    $latestRow['closure_info'] = $groupClosure;

    // Aggregate defects
    $aggregatedDefects = [];
    $totalNgCount = 0;
    foreach ($groupRows as $gRow) {
        $lId = (int)$gRow['lot_id'];
        $rowDefects = $defectsByLot[$lId] ?? [];
        foreach ($rowDefects as $df) {
            $qtyNg = (int)($df['qty_ng'] ?? 1);
            $defName = $df['defect_name'] ?? 'Defect';
            if (!isset($aggregatedDefects[$defName])) {
                $aggregatedDefects[$defName] = 0;
            }
            $aggregatedDefects[$defName] += $qtyNg;
            $totalNgCount += $qtyNg;
        }
    }

    $defTextArr = [];
    foreach ($aggregatedDefects as $defName => $qtyNg) {
        $defTextArr[] = $defName . " (" . $qtyNg . " pcs)";
    }
    $latestRow['defect_text'] = !empty($defTextArr) ? implode(', ', $defTextArr) : 'Defect Terdeteksi (' . (int)($latestRow['qty_lot']) . ' pcs)';
    $latestRow['ng_count']    = $totalNgCount > 0 ? $totalNgCount : (int)($latestRow['qty_lot'] ?? 0);

    $lotSt = strtolower($latestRow['lot_status'] ?? '');
    $closureType = $groupClosure ? ($groupClosure['closure_type'] ?? 'action') : '';
    if ($lotSt === 'abolished') $closureType = 'abolish';
    elseif ($lotSt === 'action_closed') $closureType = 'action';

    $isClosed = ($groupClosure !== null || in_array($lotSt, ['abolished', 'action_closed'], true));

    $findingTs = !empty($latestRow['lot_created_at']) 
        ? strtotime($latestRow['lot_created_at']) 
        : strtotime($latestRow['session_started_at']);
    $diffDays = max(1, (int)ceil((time() - $findingTs) / 86400));
    $latestRow['aging_days'] = $diffDays;

    if ($isClosed) {
        $statusLifecycle = 'CLOSED';
        if ($closureType === 'abolish') {
            $actionType = 'ABOLISH';
            $statsAbolished++;
        } else {
            $actionType = 'ACTION';
            $statsAction++;
        }
        $statsClosed++;
        $agingBracket = 'closed';
    } else {
        $statusLifecycle = 'OPEN';
        $actionType      = 'WAITING';
        $statsOpen++;

        if ($diffDays <= 3) {
            $agingBracket = '1-3';
            $statsAging1to3++;
        } elseif ($diffDays <= 6) {
            $agingBracket = '4-6';
            $statsAging4to6++;
        } else {
            $agingBracket = '7plus';
            $statsAging7plus++;
        }
    }

    $latestRow['status_lifecycle'] = $statusLifecycle;
    $latestRow['action_type']      = $actionType;
    $latestRow['aging_bracket']    = $agingBracket;
    $latestRow['is_closed']        = $isClosed;

    // Apply Filter Status Handling
    if ($statusHandling === 'open' && $statusLifecycle !== 'OPEN') continue;
    if ($statusHandling === 'closed' && $statusLifecycle !== 'CLOSED') continue;
    if ($statusHandling === 'action' && $actionType !== 'ACTION') continue;
    if ($statusHandling === 'abolish' && $actionType !== 'ABOLISH') continue;

    // Apply Aging Filter
    if ($agingFilter !== 'all') {
        if ($statusLifecycle !== 'OPEN' || $agingBracket !== $agingFilter) continue;
    }

    // Apply Search
    if ($search !== '') {
        $sTerm = strtolower($search);
        $match = (
            strpos(strtolower($latestRow['part_code']), $sTerm) !== false ||
            strpos(strtolower($latestRow['part_name']), $sTerm) !== false ||
            strpos(strtolower($latestRow['model_name']), $sTerm) !== false ||
            strpos(strtolower($latestRow['lot_number']), $sTerm) !== false ||
            strpos(strtolower($latestRow['ref_number']), $sTerm) !== false ||
            strpos(strtolower($latestRow['inspector_name']), $sTerm) !== false
        );
        if (!$match) continue;
    }

    $processedNgLots[] = $latestRow;
}

$statsTotal = count($groupedNgLotsMap);

// -----------------------------------------------------------------------------
// Build Spreadsheet Document using PhpSpreadsheet
// -----------------------------------------------------------------------------
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Monitoring Lot NG');

// Page Setup
$sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
$sheet->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);

// 1. Report Title & Meta
$sheet->setCellValue('A1', 'LAPORAN MONITORING LOT NG & STATUS KARANTINA');
$sheet->getStyle('A1')->getFont()->setSize(16)->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF0F172A'));

$sheet->setCellValue('A2', 'OUTGOING QUALITY CONTROL (OQC) SYSTEM');
$sheet->getStyle('A2')->getFont()->setSize(11)->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF475569'));

$exportDate = date('d/m/Y H:i') . ' WIB';
$cUser = current_user();
$userName = !empty($cUser['name']) ? $cUser['name'] : (!empty($cUser['username']) ? $cUser['username'] : 'QC Staff');

$sheet->setCellValue('A3', "Periode Filter: {$periodLabel}   |   Dicetak Pada: {$exportDate}   |   Oleh: {$userName}");
$sheet->getStyle('A3')->getFont()->setSize(9.5)->setItalic(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF64748B'));

// 2. Summary KPI Box Table (Row 5 - 7)
$sheet->setCellValue('A5', 'RINGKASAN KPI STATUS KARANTINA:');
$sheet->getStyle('A5')->getFont()->setSize(10)->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF1E293B'));

$kpiHeaders = [
    'B5' => 'TOTAL LOT NG',
    'D5' => 'STATUS OPEN (WAITING)',
    'F5' => 'STATUS CLOSED',
    'H5' => 'ACTION (SORTIR OK)',
    'J5' => 'ABOLISH (SCRAP)',
    'L5' => 'AGING 1-3 HARI',
    'N5' => 'AGING 4-6 HARI',
    'P5' => 'AGING ≥ 7 HARI',
];

$kpiValues = [
    'B6' => $statsTotal,
    'D6' => $statsOpen,
    'F6' => $statsClosed,
    'H6' => $statsAction,
    'J6' => $statsAbolished,
    'L6' => $statsAging1to3,
    'N6' => $statsAging4to6,
    'P6' => $statsAging7plus,
];

foreach ($kpiHeaders as $cell => $lbl) {
    $colLetter = substr($cell, 0, 1);
    $nextCol = chr(ord($colLetter) + 1);
    $sheet->mergeCells("{$colLetter}5:{$nextCol}5");
    $sheet->mergeCells("{$colLetter}6:{$nextCol}6");
    
    $sheet->setCellValue("{$colLetter}5", $lbl);
    $sheet->setCellValue("{$colLetter}6", $kpiValues["{$colLetter}6"] ?? 0);
    
    $sheet->getStyle("{$colLetter}5:{$nextCol}5")->applyFromArray([
        'font' => ['bold' => true, 'size' => 8.5, 'color' => ['rgb' => '475569']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F1F5F9']]
    ]);
    
    $sheet->getStyle("{$colLetter}6:{$nextCol}6")->applyFromArray([
        'font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => '0F172A']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFFFF']]
    ]);

    $sheet->getStyle("{$colLetter}5:{$nextCol}6")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
}

// 3. Main Data Table Header (Row 9)
$headers = [
    'A9' => 'NO',
    'B9' => 'TANGGAL',
    'C9' => 'JAM',
    'D9' => 'SHIFT',
    'E9' => 'PART CODE',
    'F9' => 'PART NAME',
    'G9' => 'MODEL',
    'H9' => 'CUSTOMER',
    'I9' => 'LOT NO',
    'J9' => 'REF NO',
    'K9' => 'QTY LOT (PCS)',
    'L9' => 'QTY NG (PCS)',
    'M9' => 'TEMUAN CACAT (DEFECTS)',
    'N9' => 'INSPEKTUR AWAL',
    'O9' => 'STATUS',
    'P9' => 'ACTION',
    'Q9' => 'DURASI AGING',
    'R9' => 'KATEGORI AGING',
    'S9' => 'CATATAN PENANGANAN / ALASAN ABOLISH'
];

foreach ($headers as $cell => $text) {
    $sheet->setCellValue($cell, $text);
}

$sheet->getStyle('A9:S9')->applyFromArray([
    'font' => ['bold' => true, 'size' => 9.5, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F172A']]
]);
$sheet->getRowDimension(9)->setRowHeight(28);

// 4. Fill Data Rows
$rowIdx = 10;
$no = 1;

foreach ($processedNgLots as $lot) {
    $startTimeTs = !empty($lot['lot_created_at']) ? strtotime($lot['lot_created_at']) : strtotime($lot['session_started_at']);
    $dateOnly    = date('d/m/Y', $startTimeTs);
    $timeOnly    = date('H:i', $startTimeTs) . ' WIB';
    $shift       = getShiftName($lot['session_started_at']);

    $notes = !empty($lot['closure_info']['notes']) ? $lot['closure_info']['notes'] : (!empty($lot['action_notes']) ? $lot['action_notes'] : '-');

    $agingText = !$lot['is_closed'] ? "Hari ke-" . $lot['aging_days'] : "Selesai";
    $agingKat  = "";
    if (!$lot['is_closed']) {
        if ($lot['aging_bracket'] === '1-3') $agingKat = "1 - 3 Hari";
        elseif ($lot['aging_bracket'] === '4-6') $agingKat = "4 - 6 Hari";
        else $agingKat = "≥ 7 Hari";
    } else {
        $agingKat = "Closed";
    }

    $sheet->setCellValue('A' . $rowIdx, $no);
    $sheet->setCellValue('B' . $rowIdx, $dateOnly);
    $sheet->setCellValue('C' . $rowIdx, $timeOnly);
    $sheet->setCellValue('D' . $rowIdx, $shift);
    $sheet->setCellValueExplicit('E' . $rowIdx, $lot['part_code'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValue('F' . $rowIdx, $lot['part_name']);
    $sheet->setCellValue('G' . $rowIdx, $lot['model_name']);
    $sheet->setCellValue('H' . $rowIdx, $lot['customer'] ?: 'INTERNAL');
    $sheet->setCellValueExplicit('I' . $rowIdx, $lot['lot_number'] ?: '-', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('J' . $rowIdx, $lot['ref_number'] ?: '-', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValue('K' . $rowIdx, (int)$lot['qty_lot']);
    $sheet->setCellValue('L' . $rowIdx, (int)$lot['ng_count']);
    $sheet->setCellValue('M' . $rowIdx, $lot['defect_text']);
    $sheet->setCellValue('N' . $rowIdx, $lot['inspector_name']);
    $sheet->setCellValue('O' . $rowIdx, $lot['status_lifecycle']);
    $sheet->setCellValue('P' . $rowIdx, $lot['action_type']);
    $sheet->setCellValue('Q' . $rowIdx, $agingText);
    $sheet->setCellValue('R' . $rowIdx, $agingKat);
    $sheet->setCellValue('S' . $rowIdx, $notes);

    // Styling & Alignment per column
    $sheet->getStyle('A' . $rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('B' . $rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('C' . $rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('D' . $rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('G' . $rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('H' . $rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('O' . $rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('P' . $rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('Q' . $rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('R' . $rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // Number formats
    $sheet->getStyle('K' . $rowIdx)->getNumberFormat()->setFormatCode('#,##0');
    $sheet->getStyle('L' . $rowIdx)->getNumberFormat()->setFormatCode('#,##0');
    $sheet->getStyle('K' . $rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle('L' . $rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    // Highlight STATUS cell (Column O)
    if ($lot['status_lifecycle'] === 'CLOSED') {
        $sheet->getStyle('O' . $rowIdx)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => '047857']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D1FAE5']]
        ]);
    } else {
        $sheet->getStyle('O' . $rowIdx)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'BE123C']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFE4E6']]
        ]);
    }

    // Highlight ACTION cell (Column P)
    if ($lot['action_type'] === 'ABOLISH') {
        $sheet->getStyle('P' . $rowIdx)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => '9D174D']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FCE7F3']]
        ]);
    } elseif ($lot['action_type'] === 'ACTION') {
        $sheet->getStyle('P' . $rowIdx)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => '0369A1']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E0F2FE']]
        ]);
    } else {
        $sheet->getStyle('P' . $rowIdx)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => '64748B']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F1F5F9']]
        ]);
    }

    // Aging Category highlight (Column R)
    if ($lot['aging_bracket'] === '7plus' && !$lot['is_closed']) {
        $sheet->getStyle('R' . $rowIdx)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'B91C1C']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEE2E2']]
        ]);
    } elseif ($lot['aging_bracket'] === '4-6' && !$lot['is_closed']) {
        $sheet->getStyle('R' . $rowIdx)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'B45309']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEF3C7']]
        ]);
    }

    $sheet->getStyle('A' . $rowIdx . ':S' . $rowIdx)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E2E8F0');
    $sheet->getStyle('A' . $rowIdx . ':S' . $rowIdx)->getFont()->setSize(9);

    $rowIdx++;
    $no++;
}

// If no records found
if ($no === 1) {
    $sheet->mergeCells('A10:S11');
    $sheet->setCellValue('A10', 'Tidak ada data Lot NG yang sesuai dengan filter pencarian.');
    $sheet->getStyle('A10:S11')->applyFromArray([
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        'font' => ['italic' => true, 'size' => 10, 'color' => ['rgb' => '94A3B8']]
    ]);
}

// Auto-size columns width
foreach (range('A', 'S') as $colCol) {
    $sheet->getColumnDimension($colCol)->setAutoSize(true);
}

// Clean output buffer before sending headers
if (ob_get_length()) ob_end_clean();

$cleanPeriod = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $period);
$filename = 'Laporan_Karantina_Lot_NG_' . date('Ymd_His') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: no-cache');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
