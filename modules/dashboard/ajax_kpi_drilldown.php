<?php
/**
 * Backend API untuk Fitur Drill-Down Modal KPI Dashboard OQC
 * Mengambil data rincian analitik secara instan sesuai metrik dan filter aktif.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

// Verifikasi sesi / login
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Sesi telah berakhir. Silakan muat ulang halaman.'
    ]);
    exit;
}

$pdo = getDB();
if (!$pdo) {
    echo json_encode([
        'success' => false,
        'message' => 'Gagal terhubung ke basis data.'
    ]);
    exit;
}

$kpiType             = sanitize($_GET['kpi_type'] ?? '');
$selectedCustomer    = sanitize($_GET['customer'] ?? '');
$selectedUnit        = sanitize($_GET['unit'] ?? 'pcs');
if (!in_array($selectedUnit, ['pcs', 'lot'])) $selectedUnit = 'pcs';

$selectedSampleBasis = sanitize($_GET['sample_basis'] ?? 'lot');
if (!in_array($selectedSampleBasis, ['lot', 'session'])) $selectedSampleBasis = 'lot';

$selectedResult      = sanitize($_GET['result'] ?? 'rejected');
if (!in_array($selectedResult, ['rejected', 'passed'])) $selectedResult = 'rejected';

$metricScale         = sanitize($_GET['metric_scale'] ?? 'ppm');
if (!in_array($metricScale, ['ppm', 'pct'])) $metricScale = 'ppm';

$filterType          = sanitize($_GET['filter_type'] ?? '');
$selectedMonth       = isset($_GET['month']) && is_numeric($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$selectedYear        = isset($_GET['year']) && is_numeric($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

$monthNames = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

// Resolusi Tanggal Mulai dan Selesai
if ($filterType === 'all_years') {
    $dbYears = [];
    try {
        $stmtYears = $pdo->query("SELECT DISTINCT YEAR(started_at) AS y FROM inspection_sessions WHERE started_at IS NOT NULL ORDER BY y ASC");
        $dbYears = array_values(array_filter(array_map('intval', $stmtYears->fetchAll(PDO::FETCH_COLUMN))));
    } catch (Exception $e) {}
    if (empty($dbYears)) $dbYears = [(int)date('Y')];

    $minYear = min($dbYears);
    $maxYear = max($dbYears);
    if ($minYear === $maxYear) $minYear = $maxYear - 1;

    $startDate = sprintf('%04d-01-01', $minYear);
    $endDate   = sprintf('%04d-12-31', $maxYear);
    $filterPeriodLabel = ($minYear !== $maxYear) ? ("Komparasi Semua Tahun (" . $minYear . " - " . $maxYear . ")") : ("Tahun " . $minYear);
} elseif ($filterType === 'month_year') {
    $startDate = sprintf('%04d-%02d-01', $selectedYear, $selectedMonth);
    $endDate   = date('Y-m-t', strtotime($startDate));
    $filterPeriodLabel = "Bulan " . ($monthNames[$selectedMonth] ?? $selectedMonth) . " " . $selectedYear;
} elseif ($filterType === 'custom' || (!empty($_GET['start_date']) && !empty($_GET['end_date']))) {
    $startDate = sanitize($_GET['start_date'] ?? date('Y-m-01'));
    $endDate   = sanitize($_GET['end_date'] ?? date('Y-m-d'));
    $filterPeriodLabel = date('d M Y', strtotime($startDate)) . ' s.d. ' . date('d M Y', strtotime($endDate));
} else {
    $presetFilter = sanitize($_GET['preset'] ?? 'bulanan');
    switch ($presetFilter) {
        case 'hari_ini':
            $startDate = date('Y-m-d');
            $endDate   = date('Y-m-d');
            $filterPeriodLabel = "Hari Ini (" . date('d M Y') . ")";
            break;
        case 'mingguan':
            $startDate = date('Y-m-d', strtotime('monday this week'));
            $endDate   = date('Y-m-d');
            $filterPeriodLabel = "Minggu Ini (" . date('d M', strtotime($startDate)) . " - " . date('d M Y', strtotime($endDate)) . ")";
            break;
        case 'tahunan':
            $startDate = date('Y-01-01');
            $endDate   = date('Y-m-d');
            $filterPeriodLabel = "Tahun Ini (" . date('Y') . ")";
            break;
        default:
            $startDate = date('Y-m-01');
            $endDate   = date('Y-m-d');
            $filterPeriodLabel = "Bulan Ini (" . ($monthNames[(int)date('n')] ?? date('F')) . " " . date('Y') . ")";
    }
}

$nextDay = date('Y-m-d', strtotime($endDate . ' +1 day'));
$paramsSummary = [
    ':sd'      => $startDate,
    ':ed_next' => $nextDay
];

$custCondSummary = "";
if ($selectedCustomer !== '') {
    $custCondSummary = " AND s.customer = :cust ";
    $paramsSummary[':cust'] = $selectedCustomer;
}

try {
    $response = [
        'success'           => true,
        'kpi_type'          => $kpiType,
        'period_label'      => $filterPeriodLabel,
        'customer_label'    => $selectedCustomer !== '' ? $selectedCustomer : 'Semua Customer',
        'unit'              => $selectedUnit,
        'sample_basis'      => $selectedSampleBasis,
        'result_mode'       => $selectedResult,
        'metric_scale'      => $metricScale,
        'stats'             => [],
        'data'              => []
    ];

    switch ($kpiType) {

        // =====================================================================
        // 1. TOTAL SAMPLE INSPECTED
        // =====================================================================
        case 'sample_inspected':
            $response['title']    = 'Rincian Total Sampel Diperiksa';
            $response['subtitle'] = 'Daftar part dan volume sampel fisik yang telah diuji oleh tim OQC';

            $sampleCol = ($selectedSampleBasis === 'session')
                ? "COALESCE(NULLIF(s.total_sample_size_session, 0), s.total_sample_size, 0)"
                : "COALESCE(NULLIF(s.total_sample_size_lot, 0), s.total_sample_size, 0)";

            $sql = "SELECT 
                        s.part_id,
                        COALESCE(mp.part_code, '-') AS part_code,
                        COALESCE(mp.part_name, 'Unknown Part') AS part_name,
                        COALESCE(mm.name, mp.model, '-') AS model,
                        COALESCE(NULLIF(s.customer, ''), '-') AS customer,
                        SUM(s.total_sessions) AS total_sessions,
                        SUM(s.total_lots) AS total_lots,
                        SUM({$sampleCol}) AS total_sample_checked
                    FROM oqc_daily_summary s
                    LEFT JOIN master_parts mp ON s.part_id = mp.id
                    LEFT JOIN master_models mm ON mp.model_id = mm.id
                    WHERE s.summary_date >= :sd AND s.summary_date < :ed_next {$custCondSummary}
                    GROUP BY s.part_id, mp.part_code, mp.part_name, mm.name, mp.model, s.customer
                    HAVING total_sample_checked > 0
                    ORDER BY total_sample_checked DESC, total_lots DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($paramsSummary);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $grandTotalSample = 0;
            $grandTotalLots   = 0;
            $grandTotalSess   = 0;

            foreach ($rows as $r) {
                $grandTotalSample += (int)$r['total_sample_checked'];
                $grandTotalLots   += (int)$r['total_lots'];
                $grandTotalSess   += (int)$r['total_sessions'];
            }

            foreach ($rows as &$item) {
                $samp = (int)$item['total_sample_checked'];
                $item['percent_share'] = $grandTotalSample > 0 ? round(($samp / $grandTotalSample) * 100, 1) : 0;
            }
            unset($item);

            $response['stats'] = [
                ['label' => 'Total Sampel Diperiksa', 'value' => number_format($grandTotalSample) . ' pcs', 'color' => '#2563eb'],
                ['label' => 'Total Box / Lot',       'value' => number_format($grandTotalLots) . ' lot',   'color' => '#0284c7'],
                ['label' => 'Jumlah Part Berbeda',   'value' => number_format(count($rows)) . ' item',     'color' => '#0d9488'],
                ['label' => 'Total Sesi Inspeksi',   'value' => number_format($grandTotalSess) . ' sesi',  'color' => '#475569'],
            ];
            $response['data'] = $rows;
            break;

        // =====================================================================
        // 2. TOTAL NG PCS
        // =====================================================================
        case 'total_ng':
            $response['title']    = 'Rincian Temuan Barang Cacat (Total NG Pcs)';
            $response['subtitle'] = 'Analisis kontributor cacat per part, jenis defect, dan log temuan lapangan';

            $sampleCol = ($selectedSampleBasis === 'session')
                ? "COALESCE(NULLIF(s.total_sample_size_session, 0), s.total_sample_size, 0)"
                : "COALESCE(NULLIF(s.total_sample_size_lot, 0), s.total_sample_size, 0)";

            // Tab 1: Rincian per Part
            $sqlParts = "SELECT 
                            s.part_id,
                            COALESCE(mp.part_code, '-') AS part_code,
                            COALESCE(mp.part_name, 'Unknown Part') AS part_name,
                            COALESCE(mm.name, mp.model, '-') AS model,
                            COALESCE(NULLIF(s.customer, ''), '-') AS customer,
                            SUM({$sampleCol}) AS total_sample_checked,
                            SUM(COALESCE(NULLIF(s.total_ng_pcs, 0), s.total_ng_samples, 0)) AS total_ng_pcs,
                            SUM(s.rejected_lots) AS rejected_lots
                         FROM oqc_daily_summary s
                         LEFT JOIN master_parts mp ON s.part_id = mp.id
                         LEFT JOIN master_models mm ON mp.model_id = mm.id
                         WHERE s.summary_date >= :sd AND s.summary_date < :ed_next {$custCondSummary}
                         GROUP BY s.part_id, mp.part_code, mp.part_name, mm.name, mp.model, s.customer
                         HAVING total_ng_pcs > 0
                         ORDER BY total_ng_pcs DESC, total_sample_checked DESC";

            $stmtParts = $pdo->prepare($sqlParts);
            $stmtParts->execute($paramsSummary);
            $partsRows = $stmtParts->fetchAll(PDO::FETCH_ASSOC);

            $grandTotalNg = 0;
            foreach ($partsRows as $pr) {
                $grandTotalNg += (int)$pr['total_ng_pcs'];
            }

            foreach ($partsRows as &$pItem) {
                $ng = (int)$pItem['total_ng_pcs'];
                $sm = (int)$pItem['total_sample_checked'];
                $pItem['ppm'] = $sm > 0 ? round(($ng / $sm) * 1000000, 1) : 0;
                $pItem['pct'] = $sm > 0 ? round(($ng / $sm) * 100, 2) : 0;
                $pItem['percent_share'] = $grandTotalNg > 0 ? round(($ng / $grandTotalNg) * 100, 1) : 0;
            }
            unset($pItem);

            // Tab 2: Rincian per Jenis Defect
            $sqlDefects = "SELECT 
                                dt.id AS defect_type_id,
                                COALESCE(dt.code, '-') AS defect_code,
                                dt.name AS defect_name,
                                SUM(dds.qty_ng) AS total_qty_ng,
                                SUM(dds.lot_count) AS total_lots_affected
                           FROM oqc_daily_defect_summary dds
                           INNER JOIN defect_types dt ON dds.defect_type_id = dt.id
                           WHERE dds.summary_date >= :sd AND dds.summary_date < :ed_next 
                           " . ($selectedCustomer !== '' ? " AND dds.customer = :cust " : "") . "
                           GROUP BY dt.id, dt.code, dt.name
                           HAVING total_qty_ng > 0
                           ORDER BY total_qty_ng DESC, total_lots_affected DESC";

            $stmtDefs = $pdo->prepare($sqlDefects);
            $stmtDefs->execute($paramsSummary);
            $defectRows = $stmtDefs->fetchAll(PDO::FETCH_ASSOC);

            $grandDefectQty = 0;
            foreach ($defectRows as $dr) {
                $grandDefectQty += (int)$dr['total_qty_ng'];
            }
            foreach ($defectRows as &$dItem) {
                $dqty = (int)$dItem['total_qty_ng'];
                $dItem['percent_share'] = $grandDefectQty > 0 ? round(($dqty / $grandDefectQty) * 100, 1) : 0;
            }
            unset($dItem);

            // Tab 3: Log Riwayat Temuan Cacat Lapangan
            $sqlLog = "SELECT 
                            ngr.id,
                            ngr.created_at,
                            ss.id AS session_id,
                            ss.started_at,
                            COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') AS customer,
                            COALESCE(mp.part_code, '-') AS part_code,
                            COALESCE(mp.part_name, '-') AS part_name,
                            COALESCE(mm.name, mp.model, '-') AS model,
                            COALESCE(NULLIF(TRIM(isl.lot_number), ''), ngr.lot_number, '-') AS lot_number,
                            COALESCE(NULLIF(TRIM(isl.ref_number), ''), ngr.ref_number, '-') AS ref_number,
                            dt.name AS defect_name,
                            ngr.qty_ng,
                            ngr.remark,
                            ngr.is_sorted,
                            ngr.sort_notes
                       FROM inspection_ng_records ngr
                       INNER JOIN inspection_sessions ss ON ngr.inspection_session_id = ss.id
                       INNER JOIN defect_types dt ON ngr.defect_type_id = dt.id
                       LEFT JOIN inspection_session_lots isl ON ngr.session_lot_id = isl.id
                       LEFT JOIN kanban_items ki ON ss.kanban_item_id = ki.id
                       LEFT JOIN master_parts mp ON ss.part_id = mp.id
                       LEFT JOIN master_models mm ON mp.model_id = mm.id
                       WHERE (ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)
                         AND ss.started_at >= :sd AND ss.started_at < :ed_next
                         " . ($selectedCustomer !== '' ? " AND (ki.customer = :cust OR (:cust = 'INTERNAL' AND (ki.customer IS NULL OR ki.customer = ''))) " : "") . "
                       ORDER BY ngr.created_at DESC, ngr.id DESC
                       LIMIT 100";

            $stmtLog = $pdo->prepare($sqlLog);
            $stmtLog->execute($paramsSummary);
            $logRows = $stmtLog->fetchAll(PDO::FETCH_ASSOC);

            $response['stats'] = [
                ['label' => 'Total Fisik Cacat',    'value' => number_format($grandTotalNg) . ' pcs',     'color' => '#dc2626'],
                ['label' => 'Part Terdampak Cacat', 'value' => number_format(count($partsRows)) . ' part', 'color' => '#d97706'],
                ['label' => 'Variasi Jenis Defect', 'value' => number_format(count($defectRows)) . ' jenis','color' => '#7c3aed'],
                ['label' => 'Total Kasus Temuan',   'value' => number_format(count($logRows)) . ' catatan', 'color' => '#475569'],
            ];

            $response['data'] = [
                'by_part'   => $partsRows,
                'by_defect' => $defectRows,
                'by_log'    => $logRows
            ];
            break;

        // =====================================================================
        // 3. DEFECT RATE (%) / PPM
        // =====================================================================
        case 'defect_rate':
            $response['title']    = 'Peringkat Tingkat Kerusakan Mutu (Defect Rate)';
            $response['subtitle'] = 'Analisis rasio kegagalan per part diurutkan dari kualitas paling berisiko';

            $sampleCol = ($selectedSampleBasis === 'session')
                ? "COALESCE(NULLIF(s.total_sample_size_session, 0), s.total_sample_size, 0)"
                : "COALESCE(NULLIF(s.total_sample_size_lot, 0), s.total_sample_size, 0)";

            $sqlRate = "SELECT 
                            s.part_id,
                            COALESCE(mp.part_code, '-') AS part_code,
                            COALESCE(mp.part_name, 'Unknown Part') AS part_name,
                            COALESCE(mm.name, mp.model, '-') AS model,
                            COALESCE(NULLIF(s.customer, ''), '-') AS customer,
                            SUM({$sampleCol}) AS total_sample_checked,
                            SUM(COALESCE(NULLIF(s.total_ng_pcs, 0), s.total_ng_samples, 0)) AS total_ng_pcs,
                            SUM(s.total_lots) AS total_lots,
                            SUM(s.rejected_lots) AS rejected_lots
                        FROM oqc_daily_summary s
                        LEFT JOIN master_parts mp ON s.part_id = mp.id
                        LEFT JOIN master_models mm ON mp.model_id = mm.id
                        WHERE s.summary_date >= :sd AND s.summary_date < :ed_next {$custCondSummary}
                        GROUP BY s.part_id, mp.part_code, mp.part_name, mm.name, mp.model, s.customer
                        HAVING total_sample_checked > 0
                        ORDER BY (SUM(COALESCE(NULLIF(s.total_ng_pcs, 0), s.total_ng_samples, 0)) / SUM({$sampleCol})) DESC, total_ng_pcs DESC";

            $stmtRate = $pdo->prepare($sqlRate);
            $stmtRate->execute($paramsSummary);
            $rateRows = $stmtRate->fetchAll(PDO::FETCH_ASSOC);

            $criticalCount = 0;
            $warningCount  = 0;
            $safeCount     = 0;

            foreach ($rateRows as &$rItem) {
                $sm = (int)$rItem['total_sample_checked'];
                $ng = (int)$rItem['total_ng_pcs'];

                $ppm = ($sm > 0) ? round(($ng / $sm) * 1000000, 1) : 0;
                $pct = ($sm > 0) ? round(($ng / $sm) * 100, 2) : 0;

                $rItem['ppm'] = $ppm;
                $rItem['pct'] = $pct;

                if ($ppm > 10000) {
                    $rItem['status'] = 'Kritis';
                    $rItem['status_badge'] = 'kritis';
                    $criticalCount++;
                } elseif ($ppm > 0) {
                    $rItem['status'] = 'Waspada';
                    $rItem['status_badge'] = 'waspada';
                    $warningCount++;
                } else {
                    $rItem['status'] = 'Aman (Nol Defect)';
                    $rItem['status_badge'] = 'aman';
                    $safeCount++;
                }
            }
            unset($rItem);

            $response['stats'] = [
                ['label' => 'Total Part Dianalisis', 'value' => number_format(count($rateRows)) . ' part', 'color' => '#2563eb'],
                ['label' => 'Status Kritis (>1%)',   'value' => number_format($criticalCount) . ' part',   'color' => '#dc2626'],
                ['label' => 'Status Waspada',        'value' => number_format($warningCount) . ' part',    'color' => '#d97706'],
                ['label' => 'Status Aman (0 Defect)','value' => number_format($safeCount) . ' part',       'color' => '#059669'],
            ];
            $response['data'] = $rateRows;
            break;

        // =====================================================================
        // 4. TOTAL LOT
        // =====================================================================
        case 'total_lot':
            $response['title']    = 'Rincian Volume Box / Lot Diperiksa';
            $response['subtitle'] = 'Komparasi lot lolos vs lot ditolak berdasarkan part dan customer';

            $sqlLots = "SELECT 
                            s.part_id,
                            COALESCE(mp.part_code, '-') AS part_code,
                            COALESCE(mp.part_name, 'Unknown Part') AS part_name,
                            COALESCE(mm.name, mp.model, '-') AS model,
                            COALESCE(NULLIF(s.customer, ''), '-') AS customer,
                            SUM(s.total_lots) AS total_lots,
                            SUM(s.passed_lots) AS passed_lots,
                            SUM(s.rejected_lots) AS rejected_lots
                        FROM oqc_daily_summary s
                        LEFT JOIN master_parts mp ON s.part_id = mp.id
                        LEFT JOIN master_models mm ON mp.model_id = mm.id
                        WHERE s.summary_date >= :sd AND s.summary_date < :ed_next {$custCondSummary}
                        GROUP BY s.part_id, mp.part_code, mp.part_name, mm.name, mp.model, s.customer
                        HAVING total_lots > 0
                        ORDER BY total_lots DESC, rejected_lots DESC";

            $stmtLots = $pdo->prepare($sqlLots);
            $stmtLots->execute($paramsSummary);
            $lotSummaryRows = $stmtLots->fetchAll(PDO::FETCH_ASSOC);

            $totAllLots = 0;
            $totPassed  = 0;
            $totReject  = 0;

            foreach ($lotSummaryRows as &$lItem) {
                $t = (int)$lItem['total_lots'];
                $p = (int)$lItem['passed_lots'];
                $r = (int)$lItem['rejected_lots'];

                $totAllLots += $t;
                $totPassed  += $p;
                $totReject  += $r;

                $lItem['pass_rate'] = ($t > 0) ? round(($p / $t) * 100, 1) : 0;
                $lItem['ng_rate']   = ($t > 0) ? round(($r / $t) * 100, 1) : 0;
            }
            unset($lItem);

            $overallPassRate = ($totAllLots > 0) ? round(($totPassed / $totAllLots) * 100, 1) : 0;

            $response['stats'] = [
                ['label' => 'Total Box / Lot Discan','value' => number_format($totAllLots) . ' lot', 'color' => '#0284c7'],
                ['label' => 'Lot Lolos (Passed)',    'value' => number_format($totPassed) . ' lot',  'color' => '#059669'],
                ['label' => 'Lot Ditolak (NG)',      'value' => number_format($totReject) . ' lot',  'color' => '#dc2626'],
                ['label' => 'Rata-rata Kelulusan',   'value' => $overallPassRate . ' %',             'color' => $overallPassRate >= 95 ? '#059669' : '#d97706'],
            ];
            $response['data'] = $lotSummaryRows;
            break;

        // =====================================================================
        // 5. TOTAL NG LOT & TOTAL PASSED LOT
        // =====================================================================
        case 'ng_lot':
        case 'passed_lot':
            $isPassedModeReq = ($kpiType === 'passed_lot');
            $response['title']    = $isPassedModeReq ? 'Daftar Rincian Box / Lot Lolos (Passed)' : 'Daftar Rincian Box / Lot Ditolak (NG)';
            $response['subtitle'] = $isPassedModeReq 
                ? 'Informasi lengkap box/lot fisik yang memenuhi standar spesifikasi mutu'
                : 'Daftar lot yang dikarantina, disortir, atau diganti akibat temuan cacat';

            $condStatus = $isPassedModeReq 
                ? "isl.lot_result = 'passed' AND isl.lot_status = 'ok'"
                : "(isl.lot_result = 'rejected' OR isl.lot_status IN ('ng_found', 'ng_quarantine', 'replaced') OR active_ng_cnt > 0)";

            $sqlDetailLots = "SELECT 
                                isl.id,
                                isl.inspection_session_id,
                                ss.started_at,
                                COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') AS customer,
                                COALESCE(mp.part_code, '-') AS part_code,
                                COALESCE(mp.part_name, '-') AS part_name,
                                COALESCE(mm.name, mp.model, '-') AS model,
                                isl.lot_number,
                                isl.ref_number,
                                isl.qty,
                                isl.sample_size,
                                isl.lot_result,
                                isl.lot_status,
                                isl.remarks,
                                COUNT(CASE WHEN (ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0) THEN ngr.id END) AS active_ng_cnt,
                                GROUP_CONCAT(DISTINCT CONCAT(dt.name, ' (', ngr.qty_ng, ')') SEPARATOR ', ') AS defect_breakdown
                              FROM inspection_session_lots isl
                              INNER JOIN inspection_sessions ss ON isl.inspection_session_id = ss.id
                              LEFT JOIN kanban_items ki ON ss.kanban_item_id = ki.id
                              LEFT JOIN master_parts mp ON ss.part_id = mp.id
                              LEFT JOIN master_models mm ON mp.model_id = mm.id
                              LEFT JOIN inspection_ng_records ngr ON ngr.session_lot_id = isl.id AND (ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)
                              LEFT JOIN defect_types dt ON ngr.defect_type_id = dt.id
                              WHERE ss.started_at >= :sd AND ss.started_at < :ed_next
                                AND (ki.remark IS NULL OR (ki.remark NOT LIKE '%AUTO-SS-SESS-%' AND ki.remark NOT LIKE '%Split%'))
                                AND (isl.remarks IS NULL OR (isl.remarks NOT LIKE 'Sisa Kelebihan Kanban%' AND isl.remarks NOT LIKE 'Alokasi Safety Stock%'))
                                " . ($selectedCustomer !== '' ? " AND (ki.customer = :cust OR (:cust = 'INTERNAL' AND (ki.customer IS NULL OR ki.customer = ''))) " : "") . "
                              GROUP BY isl.id, isl.inspection_session_id, ss.started_at, ki.customer, mp.part_code, mp.part_name, mm.name, mp.model, isl.lot_number, isl.ref_number, isl.qty, isl.sample_size, isl.lot_result, isl.lot_status, isl.remarks
                              HAVING {$condStatus}
                              ORDER BY ss.started_at DESC, isl.id DESC
                              LIMIT 150";

            $stmtDetailLots = $pdo->prepare($sqlDetailLots);
            $stmtDetailLots->execute($paramsSummary);
            $detailLotRows = $stmtDetailLots->fetchAll(PDO::FETCH_ASSOC);

            $totQtyPieces = 0;
            foreach ($detailLotRows as $dlr) {
                $totQtyPieces += (int)$dlr['qty'];
            }

            $response['stats'] = [
                ['label' => $isPassedModeReq ? 'Total Lot Lolos' : 'Total Lot Ditolak', 'value' => number_format(count($detailLotRows)) . ' box', 'color' => $isPassedModeReq ? '#059669' : '#dc2626'],
                ['label' => 'Total Qty Barang Fisik', 'value' => number_format($totQtyPieces) . ' pcs', 'color' => '#1e293b'],
                ['label' => 'Periode Data',           'value' => $filterPeriodLabel,                     'color' => '#475569'],
            ];
            $response['data'] = $detailLotRows;
            break;

        default:
            echo json_encode([
                'success' => false,
                'message' => 'Jenis metrik KPI tidak valid.'
            ]);
            exit;
    }

    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
    ]);
    exit;
}
