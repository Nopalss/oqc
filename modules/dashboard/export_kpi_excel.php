<?php
/**
 * Official Excel Generator for KPI Drilldown Modal — Dashboard OQC
 * Generates beautifully formatted XLSX files matching the active modal view,
 * complete with corporate header, stat cards, custom column widths, and proper cell formats.
 */

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';
require_once __DIR__ . '/../../vendor/autoload.php';

require_menu_access('dashboard');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

// Verifikasi akses
if (!is_logged_in() || !can_access('dashboard')) {
    die("Akses ditolak!");
}

$title       = sanitize($_POST['title'] ?? 'Rincian KPI');
$subtitle    = sanitize($_POST['subtitle'] ?? '');
$period      = sanitize($_POST['period'] ?? '');
$activeTab   = sanitize($_POST['active_tab'] ?? '');
$statsJson   = $_POST['stats'] ?? '[]';
$headersJson = $_POST['headers'] ?? '[]';
$rowsJson    = $_POST['rows'] ?? '[]';

$stats   = json_decode($statsJson, true) ?: [];
$headers = json_decode($headersJson, true) ?: [];
$rows    = json_decode($rowsJson, true) ?: [];

if (empty($headers) || empty($rows)) {
    die("Tidak ada data untuk diexport ke Excel.");
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Rincian KPI');
$sheet->setShowGridLines(true);

$fontFamily = 'Arial';
$spreadsheet->getDefaultStyle()->getFont()->setName($fontFamily)->setSize(9);

// ── 1. Company Header ────────────────────────────────────────────────────────
$sheet->setCellValue('A1', 'PT. SURYA TECHNOLOGY INDUSTRI');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF0F172A'));

$sheet->setCellValue('A2', 'OUTGOING QUALITY CONTROL (OQC) DEPARTMENT — KPI ANALYTICS REPORT');
$sheet->getStyle('A2')->getFont()->setBold(true)->setSize(9.5)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF2563EB'));

$sheet->setCellValue('A3', strtoupper($title) . (!empty($period) ? ' (' . $period . ')' : ''));
$sheet->getStyle('A3')->getFont()->setBold(true)->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF0F172A'));

if (!empty($subtitle)) {
    $sheet->setCellValue('A4', $subtitle);
    $sheet->getStyle('A4')->getFont()->setItalic(true)->setSize(8.5)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF64748B'));
}

$userName = $_SESSION['user_name'] ?? 'Admin';
$sheet->setCellValue('A5', 'Dicetak oleh: ' . $userName . ' pada ' . date('d/m/Y H:i') . ' WIB');
$sheet->getStyle('A5')->getFont()->setSize(8)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF94A3B8'));

$curRow = 7;

// ── 2. Stat Summary Cards ───────────────────────────────────────────────────
if (!empty($stats)) {
    $colIdx = 1;
    foreach ($stats as $st) {
        $lbl = $st['label'] ?? '';
        $val = $st['value'] ?? '';
        if ($lbl === '' && $val === '') continue;

        $cStart = Coordinate::stringFromColumnIndex($colIdx);
        $cEnd   = Coordinate::stringFromColumnIndex($colIdx + 1);

        $sheet->mergeCells("{$cStart}{$curRow}:{$cEnd}{$curRow}");
        $sheet->setCellValue("{$cStart}{$curRow}", strtoupper($lbl));
        $sheet->getStyle("{$cStart}{$curRow}")->getFont()->setBold(true)->setSize(7.5)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF64748B'));
        $sheet->getStyle("{$cStart}{$curRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $valRow = $curRow + 1;
        $sheet->mergeCells("{$cStart}{$valRow}:{$cEnd}{$valRow}");
        $sheet->setCellValue("{$cStart}{$valRow}", $val);
        $sheet->getStyle("{$cStart}{$valRow}")->getFont()->setBold(true)->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF0F172A'));
        $sheet->getStyle("{$cStart}{$valRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Styling Card
        $sheet->getStyle("{$cStart}{$curRow}:{$cEnd}{$valRow}")->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FFF1F5F9']
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => 'FFCBD5E1']
                ]
            ]
        ]);

        $colIdx += 2;
        if ($colIdx > 8) break; // Maks 4 kartu stat
    }
    $curRow += 3;
}

// ── 3. Table Header ──────────────────────────────────────────────────────────
$headerRow = $curRow;
$totalCols = count($headers);
$lastColLetter = Coordinate::stringFromColumnIndex($totalCols);

foreach ($headers as $idx => $hdrText) {
    $colLetter = Coordinate::stringFromColumnIndex($idx + 1);
    $sheet->setCellValue("{$colLetter}{$headerRow}", $hdrText);
}

// Header styling: Navy Blue Background, White bold text
$sheet->getStyle("A{$headerRow}:{$lastColLetter}{$headerRow}")->applyFromArray([
    'font' => [
        'bold' => true,
        'size' => 9,
        'color' => ['argb' => 'FFFFFFFF']
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['argb' => 'FF1E293B']
    ],
    'alignment' => [
        'vertical' => Alignment::VERTICAL_CENTER,
        'wrapText' => true
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['argb' => 'FF0F172A']
        ]
    ]
]);
$sheet->getRowDimension($headerRow)->setRowHeight(24);

// ── 4. Table Rows (Data Body) ────────────────────────────────────────────────
$curRow++;
$startDataRow = $curRow;

foreach ($rows as $rIndex => $rowCells) {
    $isEven = ($rIndex % 2 === 1);
    $rowBg  = $isEven ? 'FFF8FAFC' : 'FFFFFFFF';

    foreach ($rowCells as $cIndex => $val) {
        $colLetter = Coordinate::stringFromColumnIndex($cIndex + 1);
        $cleanVal = trim((string)$val);

        // Bersihkan whitespace ekstra
        $cleanVal = preg_replace('/\s+/', ' ', $cleanVal);

        // Deteksi nilai persentase
        if (preg_match('/^([\d,.]+)\s*%$/', $cleanVal, $mPct)) {
            $numVal = (float)str_replace(',', '', $mPct[1]) / 100;
            $sheet->setCellValueExplicit("{$colLetter}{$curRow}", $numVal, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
            $sheet->getStyle("{$colLetter}{$curRow}")->getNumberFormat()->setFormatCode('0.0%');
            $sheet->getStyle("{$colLetter}{$curRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
        // Deteksi integer / ribuan (e.g. "1,250", "44")
        elseif (preg_match('/^[\d,]+$/', $cleanVal) && strlen($cleanVal) < 11 && !preg_match('/^0\d+/', $cleanVal)) {
            $intVal = (int)str_replace(',', '', $cleanVal);
            $sheet->setCellValueExplicit("{$colLetter}{$curRow}", $intVal, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
            $sheet->getStyle("{$colLetter}{$curRow}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle("{$colLetter}{$curRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
        // Deteksi float ribuan (e.g. "1,250.50")
        elseif (preg_match('/^[\d,]+\.\d+$/', $cleanVal) && strlen($cleanVal) < 14) {
            $floatVal = (float)str_replace(',', '', $cleanVal);
            $sheet->setCellValueExplicit("{$colLetter}{$curRow}", $floatVal, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
            $sheet->getStyle("{$colLetter}{$curRow}")->getNumberFormat()->setFormatCode('#,##0.0');
            $sheet->getStyle("{$colLetter}{$curRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
        // String / Kode Part / Text
        else {
            $sheet->setCellValueExplicit("{$colLetter}{$curRow}", $cleanVal, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            // Center align untuk No, Status, Hasil, dsb
            if ($cIndex === 0 && is_numeric($cleanVal)) {
                $sheet->getStyle("{$colLetter}{$curRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            } else {
                $sheet->getStyle("{$colLetter}{$curRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            }
        }
    }

    // Styling baris
    $sheet->getStyle("A{$curRow}:{$lastColLetter}{$curRow}")->applyFromArray([
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['argb' => $rowBg]
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['argb' => 'FFE2E8F0']
            ]
        ],
        'alignment' => [
            'vertical' => Alignment::VERTICAL_CENTER
        ]
    ]);
    $sheet->getRowDimension($curRow)->setRowHeight(19);

    $curRow++;
}

// ── 5. Auto-size columns ─────────────────────────────────────────────────────
for ($c = 1; $c <= $totalCols; $c++) {
    $colLetter = Coordinate::stringFromColumnIndex($c);
    $sheet->getColumnDimension($colLetter)->setAutoSize(true);
}

// ── 6. Stream Output ke Browser ─────────────────────────────────────────────
$safeTitle = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $title);
$filename  = 'OQC_' . $safeTitle . '_' . date('Ymd_His') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
