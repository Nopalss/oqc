<?php
/**
 * API: Drilldown Data (Cascading Funnel Engine)
 * Mengembalikan data distribusi bertingkat (Defects -> Models -> Parts -> History) dalam format JSON.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/helper.php';

// Global Security Guard: Enforce login & RBAC menu access for API
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesi login telah berakhir atau tidak valid. Silakan login kembali.']);
    exit;
}
if (!can_access('defect_analysis')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki hak akses ke modul analisis defect.']);
    exit;
}

// Release session lock immediately to allow non-blocking concurrent AJAX calls
session_write_close();

$pdo = getDB();
if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$action      = sanitize($_GET['action'] ?? 'get_defects');
$unit        = sanitize($_GET['unit'] ?? 'pcs');
if (!in_array($unit, ['pcs', 'lot', 'ppm'])) {
    $unit = 'pcs';
}

$sampleBasis = sanitize($_GET['sample_basis'] ?? 'lot');
if (!in_array($sampleBasis, ['lot', 'session'])) {
    $sampleBasis = 'lot';
}

$sampleCol   = ($sampleBasis === 'session') 
    ? "COALESCE(NULLIF(SUM(total_sample_size_session), 0), SUM(total_sample_size), 0)" 
    : "COALESCE(NULLIF(SUM(total_sample_size_lot), 0), SUM(total_sample_size), 0)";

$topRank     = sanitize($_GET['top_rank'] ?? '5');
$customer = sanitize($_GET['customer'] ?? '');
$defectId = isset($_GET['defect_id']) && is_numeric($_GET['defect_id']) ? (int)$_GET['defect_id'] : 0;
$modelId  = isset($_GET['model_id']) && is_numeric($_GET['model_id']) ? (int)$_GET['model_id'] : null;
$partId   = isset($_GET['part_id']) && is_numeric($_GET['part_id']) ? (int)$_GET['part_id'] : null;

// Date filters
$filterType    = sanitize($_GET['filter_type'] ?? '');
$preset        = sanitize($_GET['preset'] ?? '');
$selectedMonth = isset($_GET['month']) && is_numeric($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$selectedYear  = isset($_GET['year']) && is_numeric($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

if ($filterType === 'all_years' || $preset === 'all_years') {
    $filterType = 'all_years';
    try {
        $stmtMin = $pdo->query("SELECT MIN(started_at) FROM inspection_sessions WHERE started_at IS NOT NULL");
        $minDate = $stmtMin->fetchColumn();
        $minYear = $minDate ? (int)date('Y', strtotime($minDate)) : ((int)date('Y') - 1);
        if (!$minYear || $minYear > (int)date('Y')) {
            $minYear = (int)date('Y') - 1;
        }
    } catch (Exception $e) {
        $minYear = (int)date('Y') - 1;
    }
    // Ensure we cover at least 2 years prior up to current year for multi-year comparison
    $curYear = (int)date('Y');
    $minYear = min($minYear, $curYear - 2);

    $startDate = sprintf('%04d-01-01', $minYear);
    $endDate   = date('Y-12-31');

} elseif ($filterType === 'specific_year' || $preset === 'specific_year') {
    $filterType = 'specific_year';
    $startDate  = sprintf('%04d-01-01', $selectedYear);
    $endDate    = ($selectedYear === (int)date('Y')) ? date('Y-m-d') : sprintf('%04d-12-31', $selectedYear);

} elseif ($filterType === 'month_year' || $preset === 'month_year' || $preset === 'bulanan' || empty($preset)) {
    $filterType = 'month_year';
    $monthStart = sprintf('%04d-%02d-01', $selectedYear, $selectedMonth);
    $firstDayDt = new DateTime($monthStart);
    $firstDayDow = (int)$firstDayDt->format('N'); // 1 = Mon ... 7 = Sun

    // Cari Hari Minggu pertama di bulan ini (Sunday Anchor standar pabrik)
    $w1Sun = clone $firstDayDt;
    if ($firstDayDow !== 7) {
        $w1Sun->modify('-' . $firstDayDow . ' days');
    }
    if ($w1Sun->format('m') !== $firstDayDt->format('m')) {
        $w1Sun->modify('+7 days');
    }

    $startDate = $w1Sun->format('Y-m-d');
    $endDate   = date('Y-m-t', strtotime($monthStart));

} elseif ($filterType === 'custom' || (!empty($_GET['start_date']) && !empty($_GET['end_date']))) {
    $filterType = 'custom';
    $startDate  = sanitize($_GET['start_date'] ?? date('Y-m-01'));
    $endDate    = sanitize($_GET['end_date'] ?? date('Y-m-d'));

} else {
    switch ($preset) {
        case 'hari_ini':
            $startDate = date('Y-m-d');
            $endDate   = date('Y-m-d');
            break;
        case 'mingguan':
            $startDate = date('Y-m-d', strtotime('monday this week'));
            $endDate   = date('Y-m-d');
            break;
        case 'tahunan':
            $startDate = date('Y-01-01');
            $endDate   = date('Y-m-d');
            break;
        default:
            $startDate = date('Y-m-01');
            $endDate   = date('Y-m-d');
    }
}

// Common WHERE base
$where = [
    "(ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)",
    "s.started_at >= :sd AND s.started_at < :ed_next"
];
$params = [
    ':sd'      => $startDate . ' 00:00:00',
    ':ed_next' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'
];

if ($customer !== '') {
    $where[] = "COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') = :cust";
    $params[':cust'] = $customer;
}

// Curated Chromatic Palettes per Funnel Level (High-Contrast, Modern Industrial)
$paletteDefects = [
    '#f43f5e', '#f97316', '#f59e0b', '#8b5cf6', '#06b6d4',
    '#ec4899', '#ef4444', '#7c3aed', '#eab308', '#d946ef',
    '#3b82f6', '#14b8a6', '#f472b6', '#fb923c', '#a855f7'
];

$paletteModels = [
    '#4f46e5', '#2563eb', '#0284c7', '#7c3aed', '#0891b2',
    '#6366f1', '#1d4ed8', '#38bdf8', '#9333ea', '#475569',
    '#0ea5e9', '#6d28d9', '#3b82f6', '#818cf8', '#0369a1'
];

$paletteParts = [
    '#059669', '#0d9488', '#10b981', '#0284c7', '#84cc16',
    '#047857', '#14b8a6', '#65a30d', '#16a34a', '#115e59',
    '#15803d', '#0f766e', '#22c55e', '#2dd4bf', '#4d7c0f'
];

function formatItemsWithTopLimit($rawItems, $topRank, $unit, $palette) {
    $grandPcs  = 0;
    $grandLots = 0;
    $grandFreq = 0;
    foreach ($rawItems as $r) {
        $grandPcs  += (int)($r['total_pcs'] ?? 0);
        $grandLots += (int)($r['total_lots'] ?? 0);
        $grandFreq += (int)($r['total_freq'] ?? $r['total_lots'] ?? 0);
    }
    
    if ($unit === 'lot') {
        $grandMetric = $grandLots;
    } elseif ($unit === 'freq') {
        $grandMetric = $grandFreq;
    } elseif ($unit === 'ppm') {
        $grandMetric = $grandPcs; // base sum
    } else {
        $grandMetric = $grandPcs;
    }

    // Sort by metric
    usort($rawItems, function($a, $b) use ($unit) {
        if ($unit === 'lot') {
            $valA = (int)($a['total_lots'] ?? 0);
            $valB = (int)($b['total_lots'] ?? 0);
        } elseif ($unit === 'freq') {
            $valA = (int)($a['total_freq'] ?? $a['total_lots'] ?? 0);
            $valB = (int)($b['total_freq'] ?? $b['total_lots'] ?? 0);
        } elseif ($unit === 'ppm') {
            $valA = (float)($a['ppm'] ?? 0);
            $valB = (float)($b['ppm'] ?? 0);
        } else {
            $valA = (int)($a['total_pcs'] ?? 0);
            $valB = (int)($b['total_pcs'] ?? 0);
        }
        if ($valA === $valB) {
            return ($b['total_pcs'] ?? 0) <=> ($a['total_pcs'] ?? 0);
        }
        return $valB <=> $valA;
    });

    $topLimit = is_numeric($topRank) ? (int)$topRank : 0;
    $displayItems = [];

    if ($topLimit > 0 && count($rawItems) > $topLimit) {
        $primarySlices = array_slice($rawItems, 0, $topLimit);
        $otherSlices   = array_slice($rawItems, $topLimit);
        
        $otherPcs  = 0;
        $otherLots = 0;
        $otherFreq = 0;
        $otherSamplePop = 0;
        foreach ($otherSlices as $oth) {
            $otherPcs       += (int)($oth['total_pcs'] ?? 0);
            $otherLots      += (int)($oth['total_lots'] ?? 0);
            $otherFreq      += (int)($oth['total_freq'] ?? $oth['total_lots'] ?? 0);
            $otherSamplePop += (int)($oth['sample_pop'] ?? 0);
        }
        $otherPpm = ($otherSamplePop > 0) ? round(($otherPcs / $otherSamplePop) * 1000000, 1) : 0;

        $displayItems = $primarySlices;
        $displayItems[] = [
            'item_id'    => null,
            'item_name'  => 'Lainnya (' . count($otherSlices) . ' lainnya)',
            'total_pcs'  => $otherPcs,
            'total_lots' => $otherLots,
            'total_freq' => $otherFreq,
            'sample_pop' => $otherSamplePop,
            'ppm'        => $otherPpm,
            'is_other'   => true
        ];
    } else {
        $displayItems = $rawItems;
    }

    $labels = [];
    $data   = [];
    $colors = [];
    $items  = [];

    foreach ($displayItems as $idx => $it) {
        if ($unit === 'lot') {
            $val = (int)($it['total_lots'] ?? 0);
        } elseif ($unit === 'freq') {
            $val = (int)($it['total_freq'] ?? $it['total_lots'] ?? 0);
        } elseif ($unit === 'ppm') {
            $val = (float)($it['ppm'] ?? 0);
        } else {
            $val = (int)($it['total_pcs'] ?? 0);
        }

        $share = ($grandMetric > 0 && $val > 0) ? round(($val / $grandMetric) * 100, 1) : 0;
        $color = isset($it['is_other']) ? '#94a3b8' : ($palette[$idx % count($palette)]);

        $labels[] = $it['item_name'];
        $data[]   = $val;
        $colors[] = $color;

        $items[] = [
            'item_id'    => $it['item_id'],
            'item_name'  => $it['item_name'],
            'part_code'  => $it['part_code'] ?? null,
            'part_name'  => $it['part_name'] ?? null,
            'total_pcs'  => (int)($it['total_pcs'] ?? 0),
            'total_lots' => (int)($it['total_lots'] ?? 0),
            'total_freq' => (int)($it['total_freq'] ?? $it['total_lots'] ?? 0),
            'sample_pop' => (int)($it['sample_pop'] ?? 0),
            'ppm'        => (float)($it['ppm'] ?? 0),
            'val'        => $val,
            'percentage' => $share,
            'color'      => $color,
            'is_other'   => isset($it['is_other'])
        ];
    }

    $topVal = 0;
    if (!empty($rawItems)) {
        if ($unit === 'lot') {
            $topVal = (int)($rawItems[0]['total_lots'] ?? 0);
        } elseif ($unit === 'freq') {
            $topVal = (int)($rawItems[0]['total_freq'] ?? $rawItems[0]['total_lots'] ?? 0);
        } elseif ($unit === 'ppm') {
            $topVal = (float)($rawItems[0]['ppm'] ?? 0);
        } else {
            $topVal = (int)($rawItems[0]['total_pcs'] ?? 0);
        }
    }

    return [
        'items'        => $items,
        'chartLabels'  => $labels,
        'chartData'    => $data,
        'chartColors'  => $colors,
        'grandMetric'  => $grandMetric,
        'grandPcs'     => $grandPcs,
        'grandLots'    => $grandLots,
        'grandFreq'    => $grandFreq,
        'unit'         => strtoupper($unit),
        'topItemName'  => !empty($rawItems) ? $rawItems[0]['item_name'] : '-',
        'topItemVal'   => $topVal,
    ];
}

$subResolution = sanitize($_GET['sub_resolution'] ?? ($_GET['trend_interval'] ?? 'weekly'));

function getTimeframeIntervals($startDate, $endDate, $filterType, $subResolution = 'weekly', $refYear = null, $refMonth = null) {
    $isSingleDay = ($startDate === $endDate);
    $daysDiff = (strtotime($endDate) - strtotime($startDate)) / 86400;
    $trendMap = [];
    $intervalType = 'bulan';
    $dateFormat = '%Y-%m';

    if ($isSingleDay) {
        $intervalType = 'jam';
        for ($h = 6; $h <= 22; $h++) {
            $hKey = sprintf('%02d:00', $h);
            $trendMap[$hKey] = ['label' => $hKey, 'key' => $hKey];
        }
        $dateFormat = '%H:00';
    } elseif ($filterType === 'all_years' || ($filterType === 'custom' && $daysDiff > 730)) {
        $intervalType = 'tahun';
        $minY = (int)date('Y', strtotime($startDate));
        $maxY = (int)date('Y', strtotime($endDate));
        for ($yr = $minY; $yr <= $maxY; $yr++) {
            $yKey = (string)$yr;
            $trendMap[$yKey] = ['label' => $yKey, 'key' => $yKey];
        }
        $dateFormat = '%Y';
    } elseif ($filterType === 'month_year' && $subResolution === 'weekly') {
        // MODE MINGGUAN SIKLUS PABRIK (W1 - W4/W5 Minggu ke Sabtu)
        $intervalType = 'minggu';
        $y = $refYear ?: (int)date('Y', strtotime($startDate));
        $m = $refMonth ?: (int)date('n', strtotime($startDate));
        $mNames = [
            1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
            7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
        ];

        $curSun = new DateTime($startDate); // Sudah w1Sun
        $targetMonth = sprintf('%02d', $m);
        $wIdx = 1;

        $todayStr = date('Y-m-d');
        $isCurrentMonth = ($y === (int)date('Y') && $m === (int)date('n'));

        while ($curSun->format('m') === $targetMonth && $wIdx <= 6) {
            $curSat = clone $curSun;
            $curSat->modify('+6 days');

            $sStr = $curSun->format('Y-m-d');
            // Jika bulan berjalan, jangan tampilkan minggu yang belum dimulai
            if ($isCurrentMonth && $sStr > $todayStr) {
                break;
            }

            $eStr = $curSat->format('Y-m-d');
            $sDay = $curSun->format('d');
            $eDay = $curSat->format('d');
            $sMon = $curSun->format('M');
            $eMon = $curSat->format('M');

            $lbl = "W{$wIdx} ({$sDay}-{$eDay} {$sMon})";
            $trendMap["W{$wIdx}"] = [
                'label'      => $lbl,
                'shortLabel' => "W{$wIdx}",
                'key'        => "W{$wIdx}",
                'start_date' => $sStr,
                'end_date'   => $eStr
            ];

            $curSun->modify('+7 days');
            $wIdx++;
        }
        $dateFormat = 'custom_week';
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
            $trendMap[$dKey] = ['label' => $lbl, 'key' => $dKey];
        }
        $dateFormat = '%Y-%m-%d';
    } else {
        $intervalType = 'bulan';
        $startPeriod = new DateTime(date('Y-m-01', strtotime($startDate)));
        $endPeriod   = new DateTime(date('Y-m-01', strtotime($endDate)));
        $endPeriod->modify('+1 month');
        $interval    = DateInterval::createFromDateString('1 month');
        $period      = new DatePeriod($startPeriod, $interval, $endPeriod);

        $mNames = [
            1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
            7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
        ];

        foreach ($period as $dt) {
            $mKey = $dt->format('Y-m');
            $lbl  = ($mNames[(int)$dt->format('n')] ?? $dt->format('M')) . ' ' . $dt->format('y');
            $trendMap[$mKey] = ['label' => $lbl, 'key' => $mKey];
        }
        $dateFormat = '%Y-%m';
    }

    return [
        'isSingleDay'  => $isSingleDay,
        'intervalType' => $intervalType,
        'dateFormat'   => $dateFormat,
        'trendMap'     => $trendMap
    ];
}

function getLevelStackedTrend($pdo, $startDate, $endDate, $filterType, $customer, $topItems, $levelType, $defectId = 0, $modelId = null, $unit = 'pcs', $palette = [], $subResolution = 'weekly', $refYear = null, $refMonth = null, $topRank = 'all') {
    if (empty($topItems)) {
        return ['labels' => [], 'datasets' => [], 'rawMatrix' => []];
    }

    $tf = getTimeframeIntervals($startDate, $endDate, $filterType, $subResolution, $refYear, $refMonth);
    $dateFormat   = $tf['dateFormat'];
    $trendMap     = $tf['trendMap'];
    $isWeeklyCustom = ($dateFormat === 'custom_week');

    $colMap = [
        'defect' => 'dds.defect_type_id',
        'model'  => 'dds.model_id',
        'part'   => 'dds.part_id'
    ];
    $targetCol = $colMap[$levelType] ?? 'dds.defect_type_id';

    $wSum = ["dds.summary_date >= :sd AND dds.summary_date <= :ed"];
    $pSum = [':sd' => $startDate, ':ed' => $endDate];

    if ($customer !== '') {
        $wSum[] = "dds.customer = :cust";
        $pSum[':cust'] = $customer;
    }

    if ($levelType === 'model' && $defectId > 0) {
        $wSum[] = "dds.defect_type_id = :defId";
        $pSum[':defId'] = $defectId;
    } elseif ($levelType === 'part') {
        if ($defectId > 0) {
            $wSum[] = "dds.defect_type_id = :defId";
            $pSum[':defId'] = $defectId;
        }
        if ($modelId !== null) {
            if ($modelId > 0) {
                $wSum[] = "dds.model_id = :mId";
                $pSum[':mId'] = $modelId;
            } else {
                $wSum[] = "(dds.model_id IS NULL OR dds.model_id = 0)";
            }
        }
    }

    $wSumSql = implode(' AND ', $wSum);

    // Metadata dictionary of all known items for this level
    $itemMetaMap = [];
    foreach ($topItems as $ti) {
        if (isset($ti['is_other']) && $ti['is_other']) continue;
        $k = ($ti['item_id'] !== null) ? (string)$ti['item_id'] : 'null';
        $itemMetaMap[$k] = $ti;
    }

    // bucketValues[time_key][item_key] = value
    // bucketSamples[time_key][item_key] = sample count (used for PPM)
    $bucketValues = [];
    $bucketSamples = [];
    foreach ($trendMap as $tk => $tMeta) {
        $bucketValues[$tk] = [];
        $bucketSamples[$tk] = [];
    }

    if ($isWeeklyCustom) {
        $sqlSum = "SELECT 
                    dds.summary_date,
                    COALESCE({$targetCol}, 0) AS item_key,
                    COALESCE(SUM(dds.qty_ng), 0) AS total_pcs,
                    COALESCE(SUM(dds.lot_count), 0) AS total_lots
                   FROM oqc_daily_defect_summary dds
                   WHERE {$wSumSql}
                   GROUP BY dds.summary_date, item_key";
        $stSum = $pdo->prepare($sqlSum);
        $stSum->execute($pSum);
        foreach ($stSum->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $sDate = $r['summary_date'];
            $ik = (string)$r['item_key'];
            $val = ($unit === 'lot' || $unit === 'freq') ? (int)$r['total_lots'] : (int)$r['total_pcs'];
            foreach ($trendMap as $wKey => $wMeta) {
                if ($sDate >= $wMeta['start_date'] && $sDate <= $wMeta['end_date']) {
                    $bucketValues[$wKey][$ik] = ($bucketValues[$wKey][$ik] ?? 0) + $val;
                    break;
                }
            }
        }

        // Jika levelType adalah part dan unit ppm, kumpulkan total sampling part per minggu
        if ($levelType === 'part' && $unit === 'ppm') {
            $wSamp = ["ods.summary_date >= :sd AND ods.summary_date <= :ed"];
            $pSamp = [':sd' => $startDate, ':ed' => $endDate];
            if ($customer !== '') {
                $wSamp[] = "ods.customer = :cust";
                $pSamp[':cust'] = $customer;
            }
            $sqlSamp = "SELECT ods.summary_date, ods.part_id AS item_key,
                               COALESCE(NULLIF(SUM(ods.total_sample_size_lot), 0), SUM(ods.total_sample_size), 0) AS total_sample
                        FROM oqc_daily_summary ods
                        WHERE " . implode(' AND ', $wSamp) . "
                        GROUP BY ods.summary_date, ods.part_id";
            $stSamp = $pdo->prepare($sqlSamp);
            $stSamp->execute($pSamp);
            foreach ($stSamp->fetchAll(PDO::FETCH_ASSOC) as $rSamp) {
                $sDate = $rSamp['summary_date'];
                $ik = (string)$rSamp['item_key'];
                $sCount = (int)$rSamp['total_sample'];
                foreach ($trendMap as $wKey => $wMeta) {
                    if ($sDate >= $wMeta['start_date'] && $sDate <= $wMeta['end_date']) {
                        $bucketSamples[$wKey][$ik] = ($bucketSamples[$wKey][$ik] ?? 0) + $sCount;
                        break;
                    }
                }
            }
        }
    } else {
        $sqlSum = "SELECT 
                    DATE_FORMAT(dds.summary_date, '{$dateFormat}') AS time_key,
                    COALESCE({$targetCol}, 0) AS item_key,
                    COALESCE(SUM(dds.qty_ng), 0) AS total_pcs,
                    COALESCE(SUM(dds.lot_count), 0) AS total_lots
                   FROM oqc_daily_defect_summary dds
                   WHERE {$wSumSql}
                   GROUP BY time_key, item_key";
        
        $stSum = $pdo->prepare($sqlSum);
        $stSum->execute($pSum);
        foreach ($stSum->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $tk = $r['time_key'];
            $ik = (string)$r['item_key'];
            $val = ($unit === 'lot' || $unit === 'freq') ? (int)$r['total_lots'] : (int)$r['total_pcs'];
            if (isset($bucketValues[$tk])) {
                $bucketValues[$tk][$ik] = ($bucketValues[$tk][$ik] ?? 0) + $val;
            }
        }

        // Jika levelType adalah part dan unit ppm, kumpulkan total sampling part per time_key
        if ($levelType === 'part' && $unit === 'ppm') {
            $wSamp = ["ods.summary_date >= :sd AND ods.summary_date <= :ed"];
            $pSamp = [':sd' => $startDate, ':ed' => $endDate];
            if ($customer !== '') {
                $wSamp[] = "ods.customer = :cust";
                $pSamp[':cust'] = $customer;
            }
            $sqlSamp = "SELECT DATE_FORMAT(ods.summary_date, '{$dateFormat}') AS time_key,
                               ods.part_id AS item_key,
                               COALESCE(NULLIF(SUM(ods.total_sample_size_lot), 0), SUM(ods.total_sample_size), 0) AS total_sample
                        FROM oqc_daily_summary ods
                        WHERE " . implode(' AND ', $wSamp) . "
                        GROUP BY time_key, ods.part_id";
            $stSamp = $pdo->prepare($sqlSamp);
            $stSamp->execute($pSamp);
            foreach ($stSamp->fetchAll(PDO::FETCH_ASSOC) as $rSamp) {
                $tk = $rSamp['time_key'];
                $ik = (string)$rSamp['item_key'];
                $sCount = (int)$rSamp['total_sample'];
                if (isset($bucketSamples[$tk])) {
                    $bucketSamples[$tk][$ik] = ($bucketSamples[$tk][$ik] ?? 0) + $sCount;
                }
            }
        }
    }

    // Jika mode unit ppm pada level part, konversi defect PCS menjadi PPM spesifik per interval
    if ($levelType === 'part' && $unit === 'ppm') {
        foreach ($bucketValues as $tk => $itemsArr) {
            foreach ($itemsArr as $ik => $defPcs) {
                $samp = $bucketSamples[$tk][$ik] ?? 0;
                $bucketValues[$tk][$ik] = ($samp > 0) ? round(($defPcs / $samp) * 1000000, 1) : 0;
            }
        }
    }

    // Determine candidate item keys
    $rankLimit = (is_numeric($topRank) && (int)$topRank > 0) ? (int)$topRank : 0;
    $selectedItemKeys = [];

    if ($rankLimit > 0) {
        // PER-INTERVAL / PER-WEEK RANKING:
        // Ambil Top-N item untuk masing-masing interval (W1, W2, ...) lalu gabungkan (union)
        foreach ($trendMap as $tk => $tMeta) {
            $itemsInBucket = $bucketValues[$tk] ?? [];
            if (!empty($itemsInBucket)) {
                // Filter hanya yang memiliki temuan > 0
                $positiveItems = array_filter($itemsInBucket, function($v) { return $v > 0; });
                arsort($positiveItems);
                $topSlice = array_slice(array_keys($positiveItems), 0, $rankLimit);
                foreach ($topSlice as $k) {
                    $selectedItemKeys[$k] = true;
                }
            }
        }
    } else {
        // Tampilkan semua item yang ada temuan atau ada di topItems
        foreach ($topItems as $ti) {
            if (isset($ti['is_other']) && $ti['is_other']) continue;
            $k = ($ti['item_id'] !== null) ? (string)$ti['item_id'] : 'null';
            $selectedItemKeys[$k] = true;
        }
    }

    // Pastikan metadata terisi jika ada item yang muncul di interval mingguan
    $missingKeys = [];
    foreach (array_keys($selectedItemKeys) as $k) {
        if (!isset($itemMetaMap[$k]) && $k !== 'null' && (int)$k > 0) {
            $missingKeys[] = (int)$k;
        }
    }
    if (!empty($missingKeys)) {
        if ($levelType === 'model') {
            $inClause = implode(',', array_fill(0, count($missingKeys), '?'));
            $stM = $pdo->prepare("SELECT id, name FROM master_models WHERE id IN ({$inClause})");
            $stM->execute($missingKeys);
            foreach ($stM->fetchAll(PDO::FETCH_ASSOC) as $rowM) {
                $mk = (string)$rowM['id'];
                $itemMetaMap[$mk] = [
                    'item_id'   => (int)$rowM['id'],
                    'item_name' => $rowM['name'] ?: 'Model #' . $rowM['id'],
                    'val'       => 0
                ];
            }
        } elseif ($levelType === 'part') {
            $inClause = implode(',', array_fill(0, count($missingKeys), '?'));
            $stP = $pdo->prepare("SELECT id, part_code, part_name, CONCAT(part_code, ' - ', part_name) AS full_name FROM master_parts WHERE id IN ({$inClause})");
            $stP->execute($missingKeys);
            foreach ($stP->fetchAll(PDO::FETCH_ASSOC) as $rowP) {
                $pk = (string)$rowP['id'];
                $itemMetaMap[$pk] = [
                    'item_id'   => (int)$rowP['id'],
                    'item_name' => $rowP['full_name'],
                    'part_code' => $rowP['part_code'],
                    'part_name' => $rowP['part_name'],
                    'val'       => 0
                ];
            }
        }
    }

    $labels   = [];
    $timeKeys = [];
    foreach ($trendMap as $tk => $tMeta) {
        $labels[]   = $tMeta['label'];
        $timeKeys[] = $tk;
    }

    $datasets  = [];
    $rawMatrix = [];
    $itemIndex = 0;

    foreach (array_keys($selectedItemKeys) as $ik) {
        $meta  = $itemMetaMap[$ik] ?? [
            'item_id'   => is_numeric($ik) ? (int)$ik : null,
            'item_name' => ($ik === '0' || $ik === 'null') ? 'General / Unspecified' : "Item #{$ik}",
            'part_code' => '',
            'part_name' => ''
        ];
        $color = $meta['color'] ?? ($palette[$itemIndex % count($palette)] ?? '#2563eb');
        $name  = $meta['item_name'];

        $dataArr = [];
        foreach ($timeKeys as $tk) {
            $dataArr[] = $bucketValues[$tk][$ik] ?? 0;
        }

        $datasets[] = [
            'label'           => $name,
            'item_id'         => $meta['item_id'] ?? (is_numeric($ik) ? (int)$ik : null),
            'part_code'       => $meta['part_code'] ?? '',
            'part_name'       => $meta['part_name'] ?? '',
            'data'            => $dataArr,
            'backgroundColor' => $color,
            'borderColor'     => $color,
            'borderWidth'     => 2,
            'borderRadius'    => 4,
            'stack'           => 'stack1'
        ];
        $itemIndex++;
    }

    // Urutkan datasets: Terbanyak di indeks awal agar menjadi tumpukan paling bawah di bar chart
    usort($datasets, function($a, $b) {
        $sumA = array_sum($a['data'] ?? []);
        $sumB = array_sum($b['data'] ?? []);
        if ($sumA === $sumB) return 0;
        return ($sumB <=> $sumA);
    });

    foreach ($timeKeys as $i => $tk) {
        $rowTotal = 0;
        $rowVals  = [];
        foreach ($datasets as $ds) {
            $v = $ds['data'][$i] ?? 0;
            $rowVals[] = $v;
            $rowTotal += $v;
        }
        $rawMatrix[] = [
            'label'    => $labels[$i],
            'time_key' => $tk,
            'values'   => $rowVals,
            'total'    => $rowTotal
        ];
    }

    return [
        'labels'    => $labels,
        'datasets'  => $datasets,
        'rawMatrix' => $rawMatrix
    ];
}

try {
    if ($action === 'get_defects') {
        // 1. Query pre-aggregated daily summaries (indexed, sub-millisecond)
        $wSum = ["dds.summary_date >= :sd AND dds.summary_date <= :ed"];
        $pSum = [':sd' => $startDate, ':ed' => $endDate];
        if ($customer !== '') {
            $wSum[] = "dds.customer = :cust";
            $pSum[':cust'] = $customer;
        }
        $wSumSql = implode(' AND ', $wSum);

        $sqlSum = "SELECT 
                    dt.id AS item_id,
                    dt.name AS item_name,
                    COALESCE(SUM(dds.qty_ng), 0) AS total_pcs,
                    COALESCE(SUM(dds.lot_count), 0) AS total_lots
                   FROM oqc_daily_defect_summary dds
                   INNER JOIN defect_types dt ON dds.defect_type_id = dt.id
                   WHERE {$wSumSql}
                   GROUP BY dt.id, dt.name";
        $stSum = $pdo->prepare($sqlSum);
        $stSum->execute($pSum);
        $rowsSum = $stSum->fetchAll(PDO::FETCH_ASSOC);

        $raw = [];
        foreach ($rowsSum as $r) {
            $raw[] = [
                'item_id'    => (int)$r['item_id'],
                'item_name'  => $r['item_name'],
                'total_pcs'  => (int)$r['total_pcs'],
                'total_lots' => (int)$r['total_lots']
            ];
        }

        usort($raw, function($a, $b) use ($unit) {
            $vA = ($unit === 'lot') ? $a['total_lots'] : $a['total_pcs'];
            $vB = ($unit === 'lot') ? $b['total_lots'] : $b['total_pcs'];
            if ($vA === $vB) return $b['total_pcs'] <=> $a['total_pcs'];
            return $vB <=> $vA;
        });

        $res = formatItemsWithTopLimit($raw, $topRank, $unit, $paletteDefects);
        $res['trend']   = getLevelStackedTrend($pdo, $startDate, $endDate, $filterType, $customer, $res['items'], 'defect', 0, null, $unit, $paletteDefects);
        $res['success'] = true;
        echo json_encode($res);
        exit;

    } elseif ($action === 'get_models') {
        if ($defectId <= 0) {
            echo json_encode(['success' => false, 'message' => 'defect_id required']);
            exit;
        }

        $modelMetric = sanitize($_GET['model_metric'] ?? 'pcs');
        if (!in_array($modelMetric, ['pcs', 'freq'])) {
            $modelMetric = 'pcs';
        }

        // 1. Query pre-aggregated daily summaries (uses idx_dds_perf)
        $wSum = [
            "dds.summary_date >= :sd AND dds.summary_date <= :ed",
            "dds.defect_type_id = :defId"
        ];
        $pSum = [':sd' => $startDate, ':ed' => $endDate, ':defId' => $defectId];
        if ($customer !== '') {
            $wSum[] = "dds.customer = :cust";
            $pSum[':cust'] = $customer;
        }
        $wSumSql = implode(' AND ', $wSum);

        $sqlSum = "SELECT 
                    COALESCE(dds.model_id, 0) AS item_id,
                    COALESCE(NULLIF(TRIM(mm.name), ''), 'General / Unspecified Model') AS item_name,
                    COALESCE(SUM(dds.qty_ng), 0) AS total_pcs,
                    COALESCE(SUM(dds.lot_count), 0) AS total_lots
                   FROM oqc_daily_defect_summary dds
                   LEFT JOIN master_models mm ON dds.model_id = mm.id
                   WHERE {$wSumSql}
                   GROUP BY item_id, item_name";
        $stSum = $pdo->prepare($sqlSum);
        $stSum->execute($pSum);
        $rowsSum = $stSum->fetchAll(PDO::FETCH_ASSOC);

        $raw = [];
        foreach ($rowsSum as $r) {
            $raw[] = [
                'item_id'    => (int)$r['item_id'],
                'item_name'  => $r['item_name'],
                'total_pcs'  => (int)$r['total_pcs'],
                'total_lots' => (int)$r['total_lots'],
                'total_freq' => (int)$r['total_lots']
            ];
        }

        // Fetch active defect name
        $stmtD = $pdo->prepare("SELECT name FROM defect_types WHERE id = :id");
        $stmtD->execute([':id' => $defectId]);
        $defectName = $stmtD->fetchColumn() ?: 'Defect #' . $defectId;

        $targetUnit = ($modelMetric === 'freq') ? 'freq' : 'pcs';
        $res = formatItemsWithTopLimit($raw, $topRank, $targetUnit, $paletteModels);
        $res['unit'] = ($modelMetric === 'freq') ? 'kali' : 'PCS';
        $res['trend']   = getLevelStackedTrend($pdo, $startDate, $endDate, $filterType, $customer, $res['items'], 'model', $defectId, null, $targetUnit, $paletteModels, $subResolution, $selectedYear, $selectedMonth, $topRank);
        $res['success'] = true;
        $res['defectName'] = $defectName;
        $res['modelMetric'] = $modelMetric;
        echo json_encode($res);
        exit;

    } elseif ($action === 'get_parts') {
        if ($defectId <= 0) {
            echo json_encode(['success' => false, 'message' => 'defect_id required']);
            exit;
        }

        $partMetric = sanitize($_GET['part_metric'] ?? ($unit === 'ppm' ? 'ppm' : 'pcs'));
        if (!in_array($partMetric, ['pcs', 'ppm', 'lot'])) {
            $partMetric = 'pcs';
        }

        // 1. Query pre-aggregated daily summaries
        $wSum = [
            "dds.summary_date >= :sd AND dds.summary_date <= :ed",
            "dds.defect_type_id = :defId"
        ];
        $pSum = [':sd' => $startDate, ':ed' => $endDate, ':defId' => $defectId];
        if ($modelId !== null) {
            if ($modelId > 0) {
                $wSum[] = "dds.model_id = :mId";
                $pSum[':mId'] = $modelId;
            } else {
                $wSum[] = "(dds.model_id IS NULL OR dds.model_id = 0)";
            }
        }
        if ($customer !== '') {
            $wSum[] = "dds.customer = :cust";
            $pSum[':cust'] = $customer;
        }
        $wSumSql = implode(' AND ', $wSum);

        $sqlSum = "SELECT 
                    mp.id AS item_id,
                    CONCAT(mp.part_code, ' - ', mp.part_name) AS item_name,
                    mp.part_code,
                    mp.part_name,
                    COALESCE(SUM(dds.qty_ng), 0) AS total_pcs,
                    COALESCE(SUM(dds.lot_count), 0) AS total_lots
                   FROM oqc_daily_defect_summary dds
                   INNER JOIN master_parts mp ON dds.part_id = mp.id
                   WHERE {$wSumSql}
                   GROUP BY mp.id, mp.part_code, mp.part_name";
        $stSum = $pdo->prepare($sqlSum);
        $stSum->execute($pSum);
        $rowsSum = $stSum->fetchAll(PDO::FETCH_ASSOC);

        // 2. Query total sampling populasi spesifik per part dari oqc_daily_summary
        $wSamp = ["ods.summary_date >= :sd AND ods.summary_date <= :ed"];
        $pSamp = [':sd' => $startDate, ':ed' => $endDate];
        if ($customer !== '') {
            $wSamp[] = "ods.customer = :cust";
            $pSamp[':cust'] = $customer;
        }
        $sampleColExp = ($sampleBasis === 'session')
            ? "COALESCE(NULLIF(SUM(ods.total_sample_size_session), 0), SUM(ods.total_sample_size), 0)"
            : "COALESCE(NULLIF(SUM(ods.total_sample_size_lot), 0), SUM(ods.total_sample_size), 0)";

        $sqlSamp = "SELECT ods.part_id, {$sampleColExp} AS sample_pop
                    FROM oqc_daily_summary ods
                    WHERE " . implode(' AND ', $wSamp) . "
                    GROUP BY ods.part_id";
        $stSamp = $pdo->prepare($sqlSamp);
        $stSamp->execute($pSamp);
        $partSampleMap = $stSamp->fetchAll(PDO::FETCH_KEY_PAIR);

        $raw = [];
        foreach ($rowsSum as $r) {
            $pId = (int)$r['item_id'];
            $defPcs = (int)$r['total_pcs'];
            $sampPop = (int)($partSampleMap[$pId] ?? 0);
            $ppmVal = ($sampPop > 0) ? round(($defPcs / $sampPop) * 1000000, 1) : 0;

            $raw[] = [
                'item_id'    => $pId,
                'item_name'  => $r['item_name'],
                'part_code'  => $r['part_code'],
                'part_name'  => $r['part_name'],
                'total_pcs'  => $defPcs,
                'total_lots' => (int)$r['total_lots'],
                'sample_pop' => $sampPop,
                'ppm'        => $ppmVal
            ];
        }

        // Model name
        $modelName = 'Semua Model';
        if ($modelId !== null) {
            if ($modelId > 0) {
                $stmtM = $pdo->prepare("SELECT name FROM master_models WHERE id = :id");
                $stmtM->execute([':id' => $modelId]);
                $modelName = $stmtM->fetchColumn() ?: 'Model #' . $modelId;
            } else {
                $modelName = 'General / Unspecified Model';
            }
        }

        $targetUnit = $partMetric;
        $res = formatItemsWithTopLimit($raw, $topRank, $targetUnit, $paletteParts);
        $res['unit'] = ($partMetric === 'ppm') ? 'PPM' : (($partMetric === 'lot') ? 'LOT' : 'PCS');
        $res['partMetric'] = $partMetric;
        $res['trend']   = getLevelStackedTrend($pdo, $startDate, $endDate, $filterType, $customer, $res['items'], 'part', $defectId, $modelId, $targetUnit, $paletteParts, $subResolution, $selectedYear, $selectedMonth, $topRank);
        $res['success'] = true;
        $res['modelName'] = $modelName;

        // Attach threshold targets for parts in table
        $stmtGlobPart = $pdo->prepare("SELECT threshold_val FROM defect_threshold_targets WHERE target_type = 'global_part' AND reference_id IS NULL LIMIT 1");
        $stmtGlobPart->execute();
        $globPartVal = $stmtGlobPart->fetchColumn();
        $globPartVal = ($globPartVal !== false) ? (float)$globPartVal : null;

        $stmtSpecPart = $pdo->prepare("SELECT reference_id, threshold_val FROM defect_threshold_targets WHERE target_type = 'specific_part'");
        $stmtSpecPart->execute();
        $specPartMap = $stmtSpecPart->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach ($res['items'] as &$it) {
            $itemId = $it['item_id'] ?? null;
            if ($itemId && isset($specPartMap[$itemId])) {
                $it['threshold_val'] = (float)$specPartMap[$itemId];
                $it['is_specific_threshold'] = true;
            } elseif ($globPartVal !== null) {
                $it['threshold_val'] = $globPartVal;
                $it['is_specific_threshold'] = false;
            } else {
                $it['threshold_val'] = null;
                $it['is_specific_threshold'] = false;
            }
        }
        unset($it);

        echo json_encode($res);
        exit;

    } elseif ($action === 'get_history') {
        $whereH = [
            "(ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)",
            "s.started_at >= :sd AND s.started_at < :ed_next"
        ];
        $paramsH = [
            ':sd'      => $startDate . ' 00:00:00',
            ':ed_next' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'
        ];

        if ($customer !== '') {
            $whereH[] = "COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') = :cust";
            $paramsH[':cust'] = $customer;
        }

        if ($defectId > 0) {
            $whereH[] = "ngr.defect_type_id = :defId";
            $paramsH[':defId'] = $defectId;
        }

        if ($modelId !== null) {
            if ($modelId > 0) {
                $whereH[] = "mp.model_id = :mId";
                $paramsH[':mId'] = $modelId;
            } else {
                $whereH[] = "(mp.model_id IS NULL OR mp.model_id = 0)";
            }
        }

        if ($partId !== null && $partId > 0) {
            $whereH[] = "ngr.part_id = :pId";
            $paramsH[':pId'] = $partId;
        }

        $wSqlH = implode(' AND ', $whereH);

        // Direct join to inspection_sessions via ngr.inspection_session_id (NO join to inspection_samples)
        $sqlHist = "SELECT 
                        ngr.id AS ng_id,
                        s.started_at,
                        s.inspection_type,
                        s.status AS session_status,
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
                    WHERE {$wSqlH}
                    ORDER BY s.started_at DESC, ngr.id DESC
                    LIMIT 300";

        $stmtH = $pdo->prepare($sqlHist);
        $stmtH->execute($paramsH);
        $rows = $stmtH->fetchAll(PDO::FETCH_ASSOC);

        // Quick count of total records without LIMIT 300 to inform UI if capped
        $sqlCount = "SELECT COUNT(*) FROM inspection_ng_records ngr
                     INNER JOIN inspection_sessions s ON ngr.inspection_session_id = s.id
                     LEFT JOIN master_parts mp ON ngr.part_id = mp.id
                     LEFT JOIN kanban_items ki ON s.kanban_item_id = ki.id
                     WHERE {$wSqlH}";
        $stmtCount = $pdo->prepare($sqlCount);
        $stmtCount->execute($paramsH);
        $totalRecords = (int)$stmtCount->fetchColumn();

        // Format items array for clean client-side rendering
        $formattedItems = [];
        foreach ($rows as $row) {
            $isKanban = ($row['inspection_type'] === 'kanban');
            $dt = strtotime($row['started_at']);
            $formattedItems[] = [
                'ng_id'          => (int)$row['ng_id'],
                'date_formatted' => date('d M Y', $dt),
                'time_formatted' => date('H:i', $dt) . ' WIB',
                'part_code'      => $row['part_code'],
                'part_name'      => $row['part_name'],
                'model_name'     => $row['model_name'],
                'inspection_type'=> $row['inspection_type'],
                'type_label'     => $isKanban ? 'Kanban' : 'Safety Stock',
                'is_kanban'      => $isKanban,
                'kanban_no'      => $row['kanban_no'],
                'customer_name'  => $row['customer_name'],
                'lot_number'     => $row['lot_number'] ?: '-',
                'ref_number'     => $row['ref_number'] ?: '-',
                'inspector_name' => $row['inspector_name'] ?: 'System',
                'qty_ng'         => (int)$row['qty_ng']
            ];
        }

        echo json_encode([
            'success'       => true,
            'count'         => count($formattedItems),
            'total_count'   => $totalRecords,
            'is_capped'     => ($totalRecords > count($formattedItems)),
            'items'         => $formattedItems
        ]);
        exit;

    } elseif ($action === 'get_trend_timeline') {
        if ($defectId <= 0 && ($partId === null || $partId <= 0)) {
            echo json_encode(['success' => false, 'message' => 'defect_id or part_id required']);
            exit;
        }

        // Build WHERE for the timeline
        $whereTimeline = [
            "(ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)",
            "s.started_at >= :sd AND s.started_at < :ed_next"
        ];
        $paramsTimeline = [
            ':sd'      => $startDate . ' 00:00:00',
            ':ed_next' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'
        ];
        if ($defectId > 0) {
            $whereTimeline[] = "ngr.defect_type_id = :defId";
            $paramsTimeline[':defId'] = $defectId;
        }

        if ($customer !== '') {
            $whereTimeline[] = "COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') = :cust";
            $paramsTimeline[':cust'] = $customer;
        }

        if ($partId !== null && $partId > 0) {
            $whereTimeline[] = "s.part_id = :pId";
            $paramsTimeline[':pId'] = $partId;
        } elseif ($modelId !== null) {
            if ($modelId > 0) {
                $whereTimeline[] = "mp.model_id = :mId";
                $paramsTimeline[':mId'] = $modelId;
            } else {
                $whereTimeline[] = "(mp.model_id IS NULL OR mp.model_id = 0)";
            }
        }

        $wTimelineSql = implode(' AND ', $whereTimeline);

        // Determine timeframe intervals
        $isSingleDay = ($startDate === $endDate);
        $daysDiff = (strtotime($endDate) - strtotime($startDate)) / 86400;

        $trendMap = [];
        $intervalType = 'bulan';

        if ($isSingleDay) {
            // Hourly interval 06:00 to 22:00 (queries inspection_ng_records directly via indexed session_id)
            $intervalType = 'jam';
            for ($h = 6; $h <= 22; $h++) {
                $hKey = sprintf('%02d:00', $h);
                $trendMap[$hKey] = ['label' => $hKey, 'pcs' => 0, 'lot' => 0];
            }

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
            if ($partId !== null && $partId > 0) {
                $wHour[] = "ngr.part_id = :pId";
                $pHour[':pId'] = $partId;
            } elseif ($modelId !== null && $modelId > 0) {
                $wHour[] = "mp.model_id = :mId";
                $pHour[':mId'] = $modelId;
            }
            if ($customer !== '') {
                $wHour[] = "COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') = :cust";
                $pHour[':cust'] = $customer;
            }
            $wHourSql = implode(' AND ', $wHour);

            $sqlT = "SELECT 
                        DATE_FORMAT(s.started_at, '%H:00') as time_key,
                        COALESCE(SUM(ngr.qty_ng), 0) as total_pcs,
                        COUNT(DISTINCT s.id) as total_lots
                    FROM inspection_ng_records ngr
                    INNER JOIN inspection_sessions s ON ngr.inspection_session_id = s.id
                    LEFT JOIN master_parts mp ON ngr.part_id = mp.id
                    LEFT JOIN kanban_items ki ON s.kanban_item_id = ki.id
                    WHERE {$wHourSql}
                    GROUP BY time_key";
            $stmtT = $pdo->prepare($sqlT);
            $stmtT->execute($pHour);
            foreach ($stmtT->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $tk = $r['time_key'];
                if (isset($trendMap[$tk])) {
                    $trendMap[$tk]['pcs'] = (int)$r['total_pcs'];
                    $trendMap[$tk]['lot'] = (int)$r['total_lots'];
                }
            }
        } else {
            // Multi-day timeline using pre-aggregated summary table (super-fast)
            $wSumT = [
                "dds.summary_date >= :sd AND dds.summary_date <= :ed"
            ];
            $pSumT = [
                ':sd'    => $startDate,
                ':ed'    => $endDate
            ];
            if ($defectId > 0) {
                $wSumT[] = "dds.defect_type_id = :defId";
                $pSumT[':defId'] = $defectId;
            }
            if ($partId !== null && $partId > 0) {
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
            if ($customer !== '') {
                $wSumT[] = "dds.customer = :cust";
                $pSumT[':cust'] = $customer;
            }
            $wSumTSql = implode(' AND ', $wSumT);

            $trendInterval = isset($_GET['trend_interval']) ? trim($_GET['trend_interval']) : 'daily';
            $dailyMode     = isset($_GET['daily_mode']) ? trim($_GET['daily_mode']) : 'work_cycle'; // 'work_cycle' (default) | 'full_month'

            if ($filterType === 'all_years' || ($filterType === 'custom' && $daysDiff > 730)) {
                $intervalType = 'tahun';
                $minY = (int)date('Y', strtotime($startDate));
                $maxY = (int)date('Y', strtotime($endDate));
                for ($yr = $minY; $yr <= $maxY; $yr++) {
                    $trendMap[(string)$yr] = ['label' => (string)$yr, 'pcs' => 0, 'lot' => 0];
                }
                $dateFormat = '%Y';
            } elseif ($trendInterval === 'weekly' && $daysDiff <= 35) {
                // Mingguan Operasional Industri 6 Hari Kerja (Aturan Sunday Anchor: Minggu - Sabtu)
                $intervalType = 'minggu';
                $monthStart = date('Y-m-01', strtotime($startDate));
                $firstDayDt = new DateTime($monthStart);
                $firstDayDow = (int)$firstDayDt->format('N'); // 1 = Mon, ..., 7 = Sun

                // 1. Cari hari Minggu pembuka dari minggu yang memuat tanggal 1
                $w1Sun = clone $firstDayDt;
                if ($firstDayDow !== 7) {
                    $w1Sun->modify('-' . $firstDayDow . ' days');
                }

                // 2. Aturan Sunday Anchor: Jika hari Minggu jatuh di bulan sebelumnya,
                // maka Senin - Sabtu (termasuk tgl 1 awal bulan) adalah milik bulan sebelumnya.
                // Minggu pertama (W1) bulan ini baru mulai pada hari Minggu berikutnya di bulan ini.
                if ($w1Sun->format('m') !== $firstDayDt->format('m')) {
                    $w1Sun->modify('+7 days');
                }

                // 3. Susun bucket mingguan (Minggu - Sabtu, 7 hari penuh)
                $weeklyBuckets = [];
                $curSun = clone $w1Sun;
                $wIdx = 1;
                $targetMonth = $firstDayDt->format('m');

                $todayStr = date('Y-m-d');
                $isCurrentMonth = ((int)$firstDayDt->format('Y') === (int)date('Y') && (int)$firstDayDt->format('n') === (int)date('n'));

                while ($curSun->format('m') === $targetMonth && $wIdx <= 6) {
                    $curSat = clone $curSun;
                    $curSat->modify('+6 days'); // Sabtu

                    $sStr = $curSun->format('Y-m-d');
                    // Jika bulan berjalan, jangan tampilkan minggu yang belum dimulai
                    if ($isCurrentMonth && $sStr > $todayStr) {
                        break;
                    }

                    $eStr = $curSat->format('Y-m-d');
                    $sLabel = $curSun->format('d');
                    $eLabel = $curSat->format('d');
                    $sMonth = $curSun->format('M');
                    $eMonth = $curSat->format('M');

                    if ($sMonth === $eMonth) {
                        $lblText = "W{$wIdx} ({$sLabel}-{$eLabel} {$sMonth})";
                    } else {
                        $lblText = "W{$wIdx} ({$sLabel} {$sMonth} - {$eLabel} {$eMonth})";
                    }

                    $weeklyBuckets["W{$wIdx}"] = [
                        'key'        => "W{$wIdx}",
                        'label'      => $lblText,
                        'start_date' => $sStr,
                        'end_date'   => $eStr
                    ];
                    $trendMap["W{$wIdx}"] = ['label' => $lblText, 'pcs' => 0, 'lot' => 0];

                    $curSun->modify('+7 days');
                    $wIdx++;
                }

                // 4. Rentang tanggal efektif query database
                $effectiveStart = !empty($weeklyBuckets['W1']) ? $weeklyBuckets['W1']['start_date'] : $startDate;
                $lastBucketKey = 'W' . ($wIdx - 1);
                $effectiveEnd   = !empty($weeklyBuckets[$lastBucketKey]) ? $weeklyBuckets[$lastBucketKey]['end_date'] : $endDate;

                $wSumTWeekly = [
                    "dds.summary_date >= :w_sd AND dds.summary_date <= :w_ed"
                ];
                $pSumTWeekly = [
                    ':w_sd' => $effectiveStart,
                    ':w_ed' => $effectiveEnd
                ];
                if ($defectId > 0) {
                    $wSumTWeekly[] = "dds.defect_type_id = :w_defId";
                    $pSumTWeekly[':w_defId'] = $defectId;
                }
                if ($partId !== null && $partId > 0) {
                    $wSumTWeekly[] = "dds.part_id = :w_pId";
                    $pSumTWeekly[':w_pId'] = $partId;
                } elseif ($modelId !== null) {
                    if ($modelId > 0) {
                        $wSumTWeekly[] = "dds.model_id = :w_mId";
                        $pSumTWeekly[':w_mId'] = $modelId;
                    } else {
                        $wSumTWeekly[] = "(dds.model_id IS NULL OR dds.model_id = 0)";
                    }
                }
                if ($customer !== '') {
                    $wSumTWeekly[] = "dds.customer = :w_cust";
                    $pSumTWeekly[':w_cust'] = $customer;
                }
                $wSumTWeeklySql = implode(' AND ', $wSumTWeekly);

                $sqlSumT = "SELECT 
                                dds.summary_date,
                                COALESCE(SUM(dds.qty_ng), 0) as total_pcs,
                                COALESCE(SUM(dds.lot_count), 0) as total_lots
                            FROM oqc_daily_defect_summary dds
                            WHERE {$wSumTWeeklySql}
                            GROUP BY dds.summary_date";
                $stmtSumT = $pdo->prepare($sqlSumT);
                $stmtSumT->execute($pSumTWeekly);
                foreach ($stmtSumT->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $sDate = $r['summary_date'];

                    foreach ($weeklyBuckets as $wbKey => $wb) {
                        if ($sDate >= $wb['start_date'] && $sDate <= $wb['end_date']) {
                            $trendMap[$wbKey]['pcs'] += (int)$r['total_pcs'];
                            $trendMap[$wbKey]['lot'] += (int)$r['total_lots'];
                            break;
                        }
                    }
                }

                $dateFormat = 'weekly_bucket';
            } elseif ($daysDiff <= 90) {
                $intervalType = 'hari';

                // Tentukan tanggal mulai harian: jika mode work_cycle (default) pada rentang bulanan,
                // potong tanggal awal yang merupakan sisa minggu bulan sebelumnya
                $dailyStartDate = $startDate;
                $isMonthlyView = ($daysDiff <= 35 && (int)date('d', strtotime($startDate)) === 1);
                if ($isMonthlyView && $dailyMode === 'work_cycle') {
                    $firstDayDt = new DateTime(date('Y-m-01', strtotime($startDate)));
                    $firstDayDow = (int)$firstDayDt->format('N');
                    $w1Sun = clone $firstDayDt;
                    if ($firstDayDow !== 7) {
                        $w1Sun->modify('-' . $firstDayDow . ' days');
                    }
                    if ($w1Sun->format('m') !== $firstDayDt->format('m')) {
                        $w1Sun->modify('+7 days');
                    }
                    $dailyStartDate = $w1Sun->format('Y-m-d');
                }

                $startPeriod = new DateTime($dailyStartDate);
                $endPeriod   = new DateTime($endDate);
                if ($startPeriod > $endPeriod) {
                    $startPeriod = clone $endPeriod;
                }
                $endPeriod->modify('+1 day');
                $interval    = new DateInterval('P1D');
                $period      = new DatePeriod($startPeriod, $interval, $endPeriod);

                foreach ($period as $dt) {
                    $dKey = $dt->format('Y-m-d');
                    $lbl  = $dt->format('d M');
                    $trendMap[$dKey] = ['label' => $lbl, 'pcs' => 0, 'lot' => 0];
                }
                $dateFormat = '%Y-%m-%d';
            } else {
                $intervalType = 'bulan';
                $startPeriod = new DateTime(date('Y-m-01', strtotime($startDate)));
                $endPeriod   = new DateTime(date('Y-m-01', strtotime($endDate)));
                $endPeriod->modify('+1 month');
                $interval    = DateInterval::createFromDateString('1 month');
                $period      = new DatePeriod($startPeriod, $interval, $endPeriod);

                $mNames = [
                    1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
                    7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
                ];

                foreach ($period as $dt) {
                    $mKey = $dt->format('Y-m');
                    $lbl  = ($mNames[(int)$dt->format('n')] ?? $dt->format('M')) . ' ' . $dt->format('y');
                    $trendMap[$mKey] = ['label' => $lbl, 'pcs' => 0, 'lot' => 0];
                }
                $dateFormat = '%Y-%m';
            }

            if ($dateFormat !== 'weekly_bucket') {
                $sqlSumT = "SELECT 
                                DATE_FORMAT(dds.summary_date, '{$dateFormat}') as time_key,
                                COALESCE(SUM(dds.qty_ng), 0) as total_pcs,
                                COALESCE(SUM(dds.lot_count), 0) as total_lots
                            FROM oqc_daily_defect_summary dds
                            WHERE {$wSumTSql}
                            GROUP BY time_key";
                $stmtSumT = $pdo->prepare($sqlSumT);
                $stmtSumT->execute($pSumT);
                foreach ($stmtSumT->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $tk = $r['time_key'];
                    if (isset($trendMap[$tk])) {
                        $trendMap[$tk]['pcs'] = (int)$r['total_pcs'];
                        $trendMap[$tk]['lot'] = (int)$r['total_lots'];
                    }
                }
            }

            // Merge active in-progress sessions if range covers today
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
                if ($partId !== null && $partId > 0) {
                    $wActT[] = "ngr.part_id = :pId";
                    $pActT[':pId'] = $partId;
                } elseif ($modelId !== null && $modelId > 0) {
                    $wActT[] = "mp.model_id = :mId";
                    $pActT[':mId'] = $modelId;
                }
                if ($customer !== '') {
                    $wActT[] = "COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') = :cust_act";
                    $pActT[':cust_act'] = $customer;
                }
                $wActTSql = implode(' AND ', $wActT);

                $dateActFormat = ($dateFormat === 'weekly_bucket') ? '%Y-%m-%d' : $dateFormat;
                $sqlActT = "SELECT 
                                DATE_FORMAT(s.started_at, '{$dateActFormat}') as time_key,
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
                    if ($dateFormat === 'weekly_bucket') {
                        $sDate = $r['time_key'];

                        foreach ($weeklyBuckets as $wbKey => $wb) {
                            if ($sDate >= $wb['start_date'] && $sDate <= $wb['end_date']) {
                                $trendMap[$wbKey]['pcs'] += (int)$r['total_pcs'];
                                $trendMap[$wbKey]['lot'] += (int)$r['total_lots'];
                                break;
                            }
                        }
                    } else {
                        $tk = $r['time_key'];
                        if (isset($trendMap[$tk])) {
                            $trendMap[$tk]['pcs'] += (int)$r['total_pcs'];
                            $trendMap[$tk]['lot'] += (int)$r['total_lots'];
                        }
                    }
                }
            }
        }

        // Calculate KPIs and format chart arrays
        $chartLabels = [];
        $chartCounts = [];
        $grandPcs    = 0;
        $grandLots   = 0;
        $peakVal     = -1;
        $peakLabel   = 'Nihil / 0 Cacat';
        $activeIntervals = count($trendMap);

        foreach ($trendMap as $t) {
            $chartLabels[] = $t['label'];
            $val = ($unit === 'lot') ? $t['lot'] : $t['pcs'];
            $chartCounts[] = $val;
            $grandPcs     += $t['pcs'];
            $grandLots    += $t['lot'];

            if ($val > $peakVal && $val > 0) {
                $peakVal   = $val;
                $peakLabel = $t['label'] . " (" . number_format($val) . " " . strtoupper($unit) . ")";
            }
        }

        $grandMetric = ($unit === 'lot') ? $grandLots : $grandPcs;
        
        // Rata-rata adaptif: jika > 0 tapi < 0.1 gunakan 2 desimal (agar tidak jadi 0 bulat)
        if ($activeIntervals > 0 && $grandMetric > 0) {
            $rawAvg = $grandMetric / $activeIntervals;
            if ($rawAvg < 0.1) {
                $avgMetric = round($rawAvg, 2);
            } else {
                $avgMetric = round($rawAvg, 1);
            }
        } else {
            $avgMetric = 0;
        }

        // Fetch meta names
        $defectName = '';
        if ($defectId > 0) {
            $stD = $pdo->prepare("SELECT name FROM defect_types WHERE id = :id");
            $stD->execute([':id' => $defectId]);
            $defectName = $stD->fetchColumn() ?: '';
        }

        $modelName = '';
        if ($modelId !== null && $modelId > 0) {
            $stM = $pdo->prepare("SELECT name FROM master_models WHERE id = :id");
            $stM->execute([':id' => $modelId]);
            $modelName = $stM->fetchColumn() ?: '';
        }

        $partCode = '';
        $partName = '';
        if ($partId !== null && $partId > 0) {
            $stP = $pdo->prepare("SELECT part_code, part_name FROM master_parts WHERE id = :id");
            $stP->execute([':id' => $partId]);
            $pRow = $stP->fetch(PDO::FETCH_ASSOC);
            if ($pRow) {
                $partCode = $pRow['part_code'];
                $partName = $pRow['part_name'];
            }
        }

        echo json_encode([
            'success'       => true,
            'labels'        => $chartLabels,
            'counts'        => $chartCounts,
            'interval_type' => $intervalType,
            'kpis'          => [
                'grandMetric'     => $grandMetric,
                'grandPcs'        => $grandPcs,
                'grandLots'       => $grandLots,
                'peakLabel'       => $peakLabel,
                'avgMetric'       => $avgMetric,
                'activeIntervals' => $activeIntervals,
                'intervalType'    => $intervalType,
                'unit'            => strtoupper($unit),
                'unitLabel'       => ($unit === 'lot' ? 'Lot' : 'Pcs')
            ],
            'meta'        => [
                'defectName' => $defectName,
                'modelName'  => $modelName,
                'partCode'   => $partCode,
                'partName'   => $partName
            ]
        ]);
        exit;

    } elseif ($action === 'get_multi_horizon_trend') {
        if ($defectId <= 0 && $partId <= 0) {
            echo json_encode(['success' => false, 'message' => 'defect_id or part_id required']);
            exit;
        }

        $isPartMode = ($partId > 0 && $defectId <= 0);
        $itemCol    = $isPartMode ? "dds.part_id" : "dds.defect_type_id";
        $itemId     = $isPartMode ? $partId : $defectId;

        $refYear  = $selectedYear ?: (int)date('Y');
        $refMonth = $selectedMonth ?: (int)date('n');

        // ── 1. HORIZON TAHUNAN (Dinamis: Hanya tahun yang ada rekam data defect, maks 4 tahun terakhir s/d refYear) ──
        $wYDetect = [
            "{$itemCol} = :itemId",
            "YEAR(dds.summary_date) <= :refYear"
        ];
        $pYDetect = [
            ':itemId'  => $itemId,
            ':refYear' => $refYear
        ];
        if ($customer !== '') {
            $wYDetect[] = "dds.customer = :cust";
            $pYDetect[':cust'] = $customer;
        }
        $sqlDetectY = "SELECT DISTINCT YEAR(dds.summary_date) AS yr
                       FROM oqc_daily_defect_summary dds
                       WHERE " . implode(' AND ', $wYDetect) . "
                       ORDER BY yr ASC";
        $stDetectY = $pdo->prepare($sqlDetectY);
        $stDetectY->execute($pYDetect);
        $detectedYears = $stDetectY->fetchAll(PDO::FETCH_COLUMN);

        // Jika data kosong atau kurang dari 3, berikan rolling default 3 tahun terakhir (refYear-2, refYear-1, refYear)
        if (empty($detectedYears)) {
            $years = [$refYear - 2, $refYear - 1, $refYear];
        } else {
            // Ambil maksimal 4 tahun terakhir agar visual tetap proporsional dan tidak berjejal jika sudah 10+ tahun
            if (count($detectedYears) > 4) {
                $years = array_slice($detectedYears, -4);
            } else {
                $years = $detectedYears;
            }
            // Pastikan refYear minimal ikut ada dalam list
            if (!in_array($refYear, $years)) {
                $years[] = $refYear;
                sort($years);
            }
        }

        $yearlyCounts = [];
        foreach ($years as $yr) {
            $yearlyCounts[(string)$yr] = 0;
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
        if ($customer !== '') {
            $wY[] = "dds.customer = :cust";
            $pY[':cust'] = $customer;
        }
        $sqlY = "SELECT 
                    DATE_FORMAT(dds.summary_date, '%Y') AS y_key,
                    COALESCE(SUM(dds.qty_ng), 0) AS total_pcs,
                    COALESCE(SUM(dds.lot_count), 0) AS total_lots
                 FROM oqc_daily_defect_summary dds
                 WHERE " . implode(' AND ', $wY) . "
                 GROUP BY y_key";
        $stY = $pdo->prepare($sqlY);
        $stY->execute($pY);
        foreach ($stY->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $yk = $r['y_key'];
            if (isset($yearlyCounts[$yk])) {
                $yearlyCounts[$yk] = ($unit === 'lot') ? (int)$r['total_lots'] : (int)$r['total_pcs'];
            }
        }

        $yearlyBreakdown = [];
        // Jika PPM: Ambil total sample populasi tahunan dari oqc_daily_summary
        if ($unit === 'ppm') {
            $wYSample = [
                "summary_date >= :minY_d AND summary_date <= :maxY_d"
            ];
            $pYSample = [
                ':minY_d' => sprintf('%04d-01-01', $minYear),
                ':maxY_d' => sprintf('%04d-12-31', $maxYear)
            ];
            if ($customer !== '') {
                $wYSample[] = "customer = :cust";
                $pYSample[':cust'] = $customer;
            }
            $sampleColExp = ($sampleBasis === 'session')
                ? "COALESCE(NULLIF(SUM(total_sample_size_session), 0), SUM(total_sample_size), 0)"
                : "COALESCE(NULLIF(SUM(total_sample_size_lot), 0), SUM(total_sample_size), 0)";

            $sqlYSample = "SELECT 
                            DATE_FORMAT(summary_date, '%Y') AS y_key,
                            {$sampleColExp} AS sample_pop
                           FROM oqc_daily_summary
                           WHERE " . implode(' AND ', $wYSample) . "
                           GROUP BY y_key";
            $stYSample = $pdo->prepare($sqlYSample);
            $stYSample->execute($pYSample);
            $sampleYMap = $stYSample->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($yearlyCounts as $yrStr => &$yVal) {
                $pop = (int)($sampleYMap[$yrStr] ?? 0);
                $defQty = $yVal;
                $calcPpm = ($pop > 0) ? round(($defQty / $pop) * 1000000, 1) : 0;
                $yearlyBreakdown[$yrStr] = [
                    'defect_count' => $defQty,
                    'sample_pop'   => $pop,
                    'ppm'          => $calcPpm
                ];
                $yVal = $calcPpm;
            }
            unset($yVal);
        }

        // ── 2. HORIZON BULANAN (Rolling s/d 12 Bulan Terakhir dari Bulan Terpilih, Mulai dari Data Pertama) ──
        $mNames = [
            1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
            7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
        ];

        // Cari tanggal data terawal di sistem untuk menentukan batas awal bulan yang ada datanya
        $firstDataDate = '2026-10-01'; // Default batas operasional sistem OQC
        try {
            $stmtFirst = $pdo->query("SELECT MIN(summary_date) FROM oqc_daily_summary");
            $dbMinDate = $stmtFirst->fetchColumn();
            if ($dbMinDate) {
                $firstDataDate = $dbMinDate;
            } else {
                $stmtFirstSess = $pdo->query("SELECT MIN(DATE(started_at)) FROM inspection_sessions WHERE started_at IS NOT NULL");
                $dbMinSess = $stmtFirstSess->fetchColumn();
                if ($dbMinSess) $firstDataDate = $dbMinSess;
            }
        } catch (Exception $e) {}

        $earliestMonthKey = date('Y-m', strtotime($firstDataDate));

        // Bangun jendela maksimal 12 bulan terakhir yang berakhir di ($refYear, $refMonth)
        $endPeriodDt = new DateTime(sprintf('%04d-%02d-01', $refYear, $refMonth));
        $targetRollingMonths = [];

        // Hitung mundur 12 bulan
        for ($i = 11; $i >= 0; $i--) {
            $dtCursor = clone $endPeriodDt;
            if ($i > 0) {
                $dtCursor->modify("-{$i} months");
            }
            $mKey = $dtCursor->format('Y-m');
            // Jangan sertakan bulan sebelum sistem mulai ada data
            if ($mKey >= $earliestMonthKey) {
                $targetRollingMonths[$mKey] = [
                    'year'      => (int)$dtCursor->format('Y'),
                    'month'     => (int)$dtCursor->format('n'),
                    'monthName' => $mNames[(int)$dtCursor->format('n')],
                    'count'     => 0
                ];
            }
        }

        // Jika karena suatu hal kosong, minimal masukkan bulan terpilih
        if (empty($targetRollingMonths)) {
            $mKeySel = $endPeriodDt->format('Y-m');
            $targetRollingMonths[$mKeySel] = [
                'year'      => $refYear,
                'month'     => $refMonth,
                'monthName' => $mNames[$refMonth],
                'count'     => 0
            ];
        }

        $minRollDate = array_key_first($targetRollingMonths) . '-01';
        $maxRollDate = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $refYear, $refMonth)));

        $wM = [
            "{$itemCol} = :itemId",
            "dds.summary_date >= :minM_d AND dds.summary_date <= :maxM_d"
        ];
        $pM = [
            ':itemId' => $itemId,
            ':minM_d' => $minRollDate,
            ':maxM_d' => $maxRollDate
        ];
        if ($customer !== '') {
            $wM[] = "dds.customer = :cust";
            $pM[':cust'] = $customer;
        }

        $sqlM = "SELECT 
                    DATE_FORMAT(dds.summary_date, '%Y-%m') AS ym_key,
                    COALESCE(SUM(dds.qty_ng), 0) AS total_pcs,
                    COALESCE(SUM(dds.lot_count), 0) AS total_lots
                 FROM oqc_daily_defect_summary dds
                 WHERE " . implode(' AND ', $wM) . "
                 GROUP BY ym_key";
        $stM = $pdo->prepare($sqlM);
        $stM->execute($pM);
        foreach ($stM->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ym = $r['ym_key'];
            if (isset($targetRollingMonths[$ym])) {
                $targetRollingMonths[$ym]['count'] = ($unit === 'lot') ? (int)$r['total_lots'] : (int)$r['total_pcs'];
            }
        }

        $monthlyBreakdown = [];
        // Jika PPM: Ambil total sample populasi bulanan dari oqc_daily_summary
        if ($unit === 'ppm') {
            $wMSample = [
                "summary_date >= :minM_d AND summary_date <= :maxM_d"
            ];
            $pMSample = [
                ':minM_d' => $minRollDate,
                ':maxM_d' => $maxRollDate
            ];
            if ($customer !== '') {
                $wMSample[] = "customer = :cust";
                $pMSample[':cust'] = $customer;
            }
            $sampleColExp = ($sampleBasis === 'session')
                ? "COALESCE(NULLIF(SUM(total_sample_size_session), 0), SUM(total_sample_size), 0)"
                : "COALESCE(NULLIF(SUM(total_sample_size_lot), 0), SUM(total_sample_size), 0)";

            $sqlMSample = "SELECT 
                            DATE_FORMAT(summary_date, '%Y-%m') AS ym_key,
                            {$sampleColExp} AS sample_pop
                           FROM oqc_daily_summary
                           WHERE " . implode(' AND ', $wMSample) . "
                           GROUP BY ym_key";
            $stMSample = $pdo->prepare($sqlMSample);
            $stMSample->execute($pMSample);
            $sampleMMap = $stMSample->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($targetRollingMonths as $ymKey => &$mInfo) {
                $pop = (int)($sampleMMap[$ymKey] ?? 0);
                $defQty = $mInfo['count'];
                $calcPpm = ($pop > 0) ? round(($defQty / $pop) * 1000000, 1) : 0;
                $monthlyBreakdown[$ymKey] = [
                    'defect_count' => $defQty,
                    'sample_pop'   => $pop,
                    'ppm'          => $calcPpm
                ];
                $mInfo['count'] = $calcPpm;
            }
            unset($mInfo);
        }

        $subResolution = isset($_GET['sub_resolution']) ? trim($_GET['sub_resolution']) : 'weekly';

        // ── 3. HORIZON KE-3 (Aturan Sunday Anchor: Mingguan atau Harian Siklus Kerja) ──
        $monthStart = sprintf('%04d-%02d-01', $refYear, $refMonth);
        $firstDayDt = new DateTime($monthStart);
        $firstDayDow = (int)$firstDayDt->format('N'); // 1 = Mon ... 7 = Sun

        // Cari Hari Minggu pembuka siklus W1
        $w1Sun = clone $firstDayDt;
        if ($firstDayDow !== 7) {
            $w1Sun->modify('-' . $firstDayDow . ' days');
        }
        // Sunday Anchor: Jika jatuh di bulan sebelumnya, W1 mulai pada Minggu pertama di bulan ini
        if ($w1Sun->format('m') !== $firstDayDt->format('m')) {
            $w1Sun->modify('+7 days');
        }

        $monthEndStr = date('Y-m-t', strtotime($monthStart));
        $w1SunDayStr = $w1Sun->format('d');
        $mLastDayStr = date('d', strtotime($monthEndStr));

        $microLabels    = [];
        $microCounts    = [];
        $microTooltips  = [];
        $microBreakdown = [];
        $sectionTitle   = '';
        $sectionSub     = '';

        if ($subResolution === 'daily') {
            // MODE HARIAN (per tanggal tgl 04 s/d akhir bulan)
            $sectionTitle = 'HARIAN SIKLUS (' . strtoupper($mNames[$refMonth]) . ')';
            $sectionSub   = "Tgl {$w1SunDayStr} - {$mLastDayStr} {$mNames[$refMonth]}";

            $monthEndDt  = new DateTime($monthEndStr);
            $dailyBuckets = [];
            $curDay = clone $w1Sun;
            while ($curDay <= $monthEndDt) {
                $dStr = $curDay->format('Y-m-d');
                $dDayNum = $curDay->format('d');
                $dDowShort = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'][(int)$curDay->format('w')];

                $dailyBuckets[$dStr] = [
                    'dayNum'   => $dDayNum,
                    'tooltip'  => "{$dDowShort}, {$dDayNum} " . $mNames[$refMonth] . " {$refYear}",
                    'count'    => 0
                ];
                $curDay->modify('+1 day');
            }

            if (!empty($dailyBuckets)) {
                $wD = [
                    "{$itemCol} = :itemId",
                    "dds.summary_date >= :dStart AND dds.summary_date <= :dEnd"
                ];
                $pD = [
                    ':itemId' => $itemId,
                    ':dStart' => $w1Sun->format('Y-m-d'),
                    ':dEnd'   => $monthEndStr
                ];
                if ($customer !== '') {
                    $wD[] = "dds.customer = :cust";
                    $pD[':cust'] = $customer;
                }
                $sqlD = "SELECT 
                            dds.summary_date,
                            COALESCE(SUM(dds.qty_ng), 0) AS total_pcs,
                            COALESCE(SUM(dds.lot_count), 0) AS total_lots
                         FROM oqc_daily_defect_summary dds
                         WHERE " . implode(' AND ', $wD) . "
                         GROUP BY dds.summary_date";
                $stD = $pdo->prepare($sqlD);
                $stD->execute($pD);
                foreach ($stD->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $sDate = $r['summary_date'];
                    if (isset($dailyBuckets[$sDate])) {
                        $dailyBuckets[$sDate]['count'] = ($unit === 'lot') ? (int)$r['total_lots'] : (int)$r['total_pcs'];
                    }
                }

                // Jika PPM: Ambil sample per tanggal
                if ($unit === 'ppm') {
                    $wDSample = ["summary_date >= :dStart AND summary_date <= :dEnd"];
                    $pDSample = [':dStart' => $w1Sun->format('Y-m-d'), ':dEnd' => $monthEndStr];
                    if ($customer !== '') {
                        $wDSample[] = "customer = :cust";
                        $pDSample[':cust'] = $customer;
                    }
                    $sampleColExp = ($sampleBasis === 'session')
                        ? "COALESCE(NULLIF(SUM(total_sample_size_session), 0), SUM(total_sample_size), 0)"
                        : "COALESCE(NULLIF(SUM(total_sample_size_lot), 0), SUM(total_sample_size), 0)";

                    $sqlDSample = "SELECT summary_date, {$sampleColExp} AS sample_pop 
                                   FROM oqc_daily_summary 
                                   WHERE " . implode(' AND ', $wDSample) . " 
                                   GROUP BY summary_date";
                    $stDSample = $pdo->prepare($sqlDSample);
                    $stDSample->execute($pDSample);
                    $sampleDMap = $stDSample->fetchAll(PDO::FETCH_KEY_PAIR);
                    foreach ($dailyBuckets as $dStr => &$db) {
                        $pop = (int)($sampleDMap[$dStr] ?? 0);
                        $defQty = $db['count'];
                        $calcPpm = ($pop > 0) ? round(($defQty / $pop) * 1000000, 1) : 0;
                        $microBreakdown[$dStr] = [
                            'defect_count' => $defQty,
                            'sample_pop'   => $pop,
                            'ppm'          => $calcPpm
                        ];
                        $db['count'] = $calcPpm;
                    }
                    unset($db);
                }
            }

            foreach ($dailyBuckets as $dKey => $db) {
                $microLabels[]   = $db['dayNum'];
                $microCounts[]   = $db['count'];
                $microTooltips[] = $db['tooltip'];
            }

        } else {
            // MODE MINGGUAN (DEFAULT: W1 - W4/W5 dengan aturan Sunday Anchor)
            $sectionTitle = 'MINGGUAN (' . strtoupper($mNames[$refMonth]) . ')';
            $sectionSub   = 'Siklus Minggu - Sabtu';

            $weeklyBuckets = [];
            $curSun = clone $w1Sun;
            $wIdx = 1;
            $targetMonth = $firstDayDt->format('m');

            $todayStr = date('Y-m-d');
            $isCurrentMonth = ($refYear === (int)date('Y') && $refMonth === (int)date('n'));

            while ($curSun->format('m') === $targetMonth && $wIdx <= 6) {
                $curSat = clone $curSun;
                $curSat->modify('+6 days');

                $sStr = $curSun->format('Y-m-d');
                // Jika bulan berjalan, jangan tampilkan minggu yang belum dimulai
                if ($isCurrentMonth && $sStr > $todayStr) {
                    break;
                }

                $eStr = $curSat->format('Y-m-d');
                $sLabel = $curSun->format('d');
                $eLabel = $curSat->format('d');
                $sMonth = $curSun->format('M');
                $eMonth = $curSat->format('M');

                $lblText = ($sMonth === $eMonth) ? "W{$wIdx} ({$sLabel}-{$eLabel} {$sMonth})" : "W{$wIdx} ({$sLabel} {$sMonth}-{$eLabel} {$eMonth})";

                $weeklyBuckets["W{$wIdx}"] = [
                    'label'      => $lblText,
                    'shortLabel' => "W{$wIdx}",
                    'start_date' => $sStr,
                    'end_date'   => $eStr,
                    'count'      => 0
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
                if ($customer !== '') {
                    $wW[] = "dds.customer = :cust";
                    $pW[':cust'] = $customer;
                }
                $sqlW = "SELECT 
                            dds.summary_date,
                            COALESCE(SUM(dds.qty_ng), 0) AS total_pcs,
                            COALESCE(SUM(dds.lot_count), 0) AS total_lots
                         FROM oqc_daily_defect_summary dds
                         WHERE " . implode(' AND ', $wW) . "
                         GROUP BY dds.summary_date";
                $stW = $pdo->prepare($sqlW);
                $stW->execute($pW);
                foreach ($stW->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $sDate = $r['summary_date'];
                    $val = ($unit === 'lot') ? (int)$r['total_lots'] : (int)$r['total_pcs'];
                    foreach ($weeklyBuckets as $wbKey => &$wb) {
                        if ($sDate >= $wb['start_date'] && $sDate <= $wb['end_date']) {
                            $wb['count'] += $val;
                            break;
                        }
                    }
                    unset($wb);
                }

                // Jika PPM: Ambil sample per rentang mingguan
                if ($unit === 'ppm') {
                    $wWSample = ["summary_date >= :ws AND summary_date <= :we"];
                    $pWSample = [':ws' => $wEffStart, ':we' => $wEffEnd];
                    if ($customer !== '') {
                        $wWSample[] = "customer = :cust";
                        $pWSample[':cust'] = $customer;
                    }
                    $sampleColExp = ($sampleBasis === 'session')
                        ? "COALESCE(NULLIF(SUM(total_sample_size_session), 0), SUM(total_sample_size), 0)"
                        : "COALESCE(NULLIF(SUM(total_sample_size_lot), 0), SUM(total_sample_size), 0)";

                    $sqlWSample = "SELECT summary_date, {$sampleColExp} AS sample_pop 
                                   FROM oqc_daily_summary 
                                   WHERE " . implode(' AND ', $wWSample) . " 
                                   GROUP BY summary_date";
                    $stWSample = $pdo->prepare($sqlWSample);
                    $stWSample->execute($pWSample);
                    $sampleWRows = $stWSample->fetchAll(PDO::FETCH_ASSOC);

                    $sampleWBuckets = [];
                    foreach ($weeklyBuckets as $wbKey => $wb) {
                        $sampleWBuckets[$wbKey] = 0;
                    }
                    foreach ($sampleWRows as $sr) {
                        $sd = $sr['summary_date'];
                        $pop = (int)$sr['sample_pop'];
                        foreach ($weeklyBuckets as $wbKey => $wb) {
                            if ($sd >= $wb['start_date'] && $sd <= $wb['end_date']) {
                                $sampleWBuckets[$wbKey] += $pop;
                                break;
                            }
                        }
                    }
                    foreach ($weeklyBuckets as $wbKey => &$wb) {
                        $pop = $sampleWBuckets[$wbKey] ?? 0;
                        $defQty = $wb['count'];
                        $calcPpm = ($pop > 0) ? round(($defQty / $pop) * 1000000, 1) : 0;
                        $microBreakdown[$wbKey] = [
                            'defect_count' => $defQty,
                            'sample_pop'   => $pop,
                            'ppm'          => $calcPpm
                        ];
                        $wb['count'] = $calcPpm;
                    }
                    unset($wb);
                }
            }

            foreach ($weeklyBuckets as $wbKey => $wb) {
                $microLabels[]   = $wb['shortLabel'];
                $microCounts[]   = $wb['count'];
                $microTooltips[] = $wb['label'];
            }
        }

        // ── 4. RAKIT COMPOSITE LABELS & DATASETS DENGAN COLOR PALETTE ZONA ──
        $allLabels    = [];
        $allCounts    = [];
        $barColors    = [];
        $borderCols   = [];
        $tooltips     = [];
        $allBreakdown = [];

        // Zona 1: Tahunan (Dark Navy / Slate)
        $idxStartYear = count($allLabels);
        foreach ($yearlyCounts as $yrStr => $cnt) {
            $allLabels[]    = $yrStr;
            $allCounts[]    = $cnt;
            $barColors[]    = '#1e293b'; // Slate 800
            $borderCols[]   = '#0f172a';
            $tooltips[]     = "Tahun {$yrStr}";
            $allBreakdown[] = $yearlyBreakdown[$yrStr] ?? null;
        }
        $idxEndYear = count($allLabels) - 1;

        // Zona 2: Bulanan (Royal Blue)
        $idxStartMonth = count($allLabels);
        $firstRollMonthLabel = '';
        $lastRollMonthLabel  = '';
        foreach ($targetRollingMonths as $ymKey => $mInfo) {
            $mLabel = $mInfo['monthName'];
            if (count($targetRollingMonths) > 1 && $mInfo['year'] !== $refYear) {
                $mLabel .= ' \'' . substr((string)$mInfo['year'], 2);
            }
            if ($firstRollMonthLabel === '') $firstRollMonthLabel = $mLabel;
            $lastRollMonthLabel = $mLabel;

            $allLabels[]    = $mLabel;
            $allCounts[]    = $mInfo['count'];
            $barColors[]    = '#2563eb'; // Blue 600
            $borderCols[]   = '#1d4ed8';
            $tooltips[]     = "Bulan {$mInfo['monthName']} {$mInfo['year']}";
            $allBreakdown[] = $monthlyBreakdown[$ymKey] ?? null;
        }
        $idxEndMonth = count($allLabels) - 1;

        // Zona 3: Mingguan atau Harian (Emerald / Teal)
        $idxStartMicro = count($allLabels);
        $microKeys = array_keys(($subResolution === 'daily') ? $dailyBuckets : $weeklyBuckets);
        foreach ($microLabels as $mIdx => $mLbl) {
            $allLabels[]    = $mLbl;
            $allCounts[]    = $microCounts[$mIdx];
            $barColors[]    = '#059669'; // Emerald 600
            $borderCols[]   = '#047857';
            $tooltips[]     = $microTooltips[$mIdx];
            $mKey = $microKeys[$mIdx] ?? null;
            $allBreakdown[] = ($mKey && isset($microBreakdown[$mKey])) ? $microBreakdown[$mKey] : null;
        }
        $idxEndMicro = count($allLabels) - 1;

        // Fetch Meta Item Name (Defect Name atau Part Info)
        if ($isPartMode) {
            $stP = $pdo->prepare("SELECT part_code, part_name FROM master_parts WHERE id = :id");
            $stP->execute([':id' => $partId]);
            $pRow = $stP->fetch(PDO::FETCH_ASSOC);
            $itemName = $pRow ? ($pRow['part_code'] . ' - ' . $pRow['part_name']) : '';
        } else {
            $stD = $pdo->prepare("SELECT name FROM defect_types WHERE id = :id");
            $stD->execute([':id' => $defectId]);
            $itemName = $stD->fetchColumn() ?: '';
        }

        $monthSectionSub = ($firstRollMonthLabel === $lastRollMonthLabel)
            ? "{$firstRollMonthLabel} {$refYear}"
            : "{$firstRollMonthLabel} - {$lastRollMonthLabel}";

        echo json_encode([
            'success'     => true,
            'labels'      => $allLabels,
            'counts'      => $allCounts,
            'barColors'   => $barColors,
            'borderColors'=> $borderCols,
            'tooltips'    => $tooltips,
            'breakdown'   => $allBreakdown,
            'sections'    => [
                [
                    'name'       => 'TAHUNAN',
                    'sub'        => count($years) . ' Tahun Terakhir',
                    'startIndex' => $idxStartYear,
                    'endIndex'   => $idxEndYear,
                    'color'      => '#1e293b'
                ],
                [
                    'name'       => "BULANAN",
                    'sub'        => $monthSectionSub,
                    'startIndex' => $idxStartMonth,
                    'endIndex'   => $idxEndMonth,
                    'color'      => '#2563eb'
                ],
                [
                    'name'       => $sectionTitle,
                    'sub'        => $sectionSub,
                    'startIndex' => $idxStartMicro,
                    'endIndex'   => $idxEndMicro,
                    'color'      => '#059669'
                ]
            ],
            'meta'        => [
                'isPartMode'    => $isPartMode,
                'defectName'    => $itemName,
                'partName'      => $itemName,
                'year'          => $refYear,
                'month'         => $refMonth,
                'monthName'     => $mNames[$refMonth],
                'subResolution' => $subResolution,
                'unit'          => strtoupper($unit),
                'unitLabel'     => ($unit === 'lot' ? 'Lot' : ($unit === 'ppm' ? 'PPM' : 'Pcs'))
            ]
        ]);
        exit;

    } elseif ($action === 'get_part_ranking') {
        // 1. Query pre-aggregated daily summaries grouped by part (uses idx_dds_part_date)
        $wSum = ["dds.summary_date >= :sd AND dds.summary_date <= :ed"];
        $pSum = [':sd' => $startDate, ':ed' => $endDate];
        if ($customer !== '') {
            $wSum[] = "dds.customer = :cust";
            $pSum[':cust'] = $customer;
        }
        $wSumSql = implode(' AND ', $wSum);

        $sqlSum = "SELECT 
                    mp.id AS item_id,
                    mp.part_code,
                    mp.part_name,
                    CONCAT(mp.part_code, ' - ', mp.part_name) AS item_name,
                    COALESCE(SUM(dds.qty_ng), 0) AS total_pcs,
                    COALESCE(SUM(dds.lot_count), 0) AS total_lots
                   FROM oqc_daily_defect_summary dds
                   INNER JOIN master_parts mp ON dds.part_id = mp.id
                   WHERE {$wSumSql}
                   GROUP BY mp.id, mp.part_code, mp.part_name";
        $stSum = $pdo->prepare($sqlSum);
        $stSum->execute($pSum);
        $rowsSum = $stSum->fetchAll(PDO::FETCH_ASSOC);

        // 2. Query active in-progress sessions if range covers today
        $rowsAct = [];
        if ($endDate >= date('Y-m-d')) {
            $wAct = [
                "s.status = 'in_progress'",
                "(ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)",
                "s.started_at >= :sd_act AND s.started_at < :ed_act_next"
            ];
            $pAct = [
                ':sd_act'      => $startDate . ' 00:00:00',
                ':ed_act_next' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'
            ];
            if ($customer !== '') {
                $wAct[] = "COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') = :cust_act";
                $pAct[':cust_act'] = $customer;
            }
            $wActSql = implode(' AND ', $wAct);

            $sqlAct = "SELECT 
                        mp.id AS item_id,
                        mp.part_code,
                        mp.part_name,
                        CONCAT(mp.part_code, ' - ', mp.part_name) AS item_name,
                        COALESCE(SUM(ngr.qty_ng), 0) AS total_pcs,
                        COUNT(DISTINCT s.id) AS total_lots
                       FROM inspection_ng_records ngr
                       INNER JOIN inspection_sessions s ON ngr.inspection_session_id = s.id
                       INNER JOIN master_parts mp ON ngr.part_id = mp.id
                       LEFT JOIN kanban_items ki ON s.kanban_item_id = ki.id
                       WHERE {$wActSql}
                       GROUP BY mp.id, mp.part_code, mp.part_name";
            $stAct = $pdo->prepare($sqlAct);
            $stAct->execute($pAct);
            $rowsAct = $stAct->fetchAll(PDO::FETCH_ASSOC);
        }

        // Merge summary and active
        $merged = [];
        foreach ($rowsSum as $r) {
            $id = (int)$r['item_id'];
            $merged[$id] = [
                'item_id'    => $id,
                'part_code'  => $r['part_code'],
                'part_name'  => $r['part_name'],
                'item_name'  => $r['item_name'],
                'total_pcs'  => (int)$r['total_pcs'],
                'total_lots' => (int)$r['total_lots']
            ];
        }
        foreach ($rowsAct as $r) {
            $id = (int)$r['item_id'];
            if (!isset($merged[$id])) {
                $merged[$id] = [
                    'item_id'    => $id,
                    'part_code'  => $r['part_code'],
                    'part_name'  => $r['part_name'],
                    'item_name'  => $r['item_name'],
                    'total_pcs'  => 0,
                    'total_lots' => 0
                ];
            }
            $merged[$id]['total_pcs'] += (int)$r['total_pcs'];
            $merged[$id]['total_lots'] += (int)$r['total_lots'];
        }

        $raw = array_values($merged);
        usort($raw, function($a, $b) use ($unit) {
            $vA = ($unit === 'lot') ? $a['total_lots'] : $a['total_pcs'];
            $vB = ($unit === 'lot') ? $b['total_lots'] : $b['total_pcs'];
            if ($vA === $vB) return $b['total_pcs'] <=> $a['total_pcs'];
            return $vB <=> $vA;
        });

        $res = formatItemsWithTopLimit($raw, $topRank, $unit, $paletteParts);
        $res['success'] = true;
        echo json_encode($res);
        exit;

    } elseif ($action === 'get_part_stacked_trend') {
        // 1. Get Top N parts for this period (optionally filtered by defect_type_id)
        $wSum = ["dds.summary_date >= :sd AND dds.summary_date <= :ed"];
        $pSum = [':sd' => $startDate, ':ed' => $endDate];
        if ($defectId > 0) {
            $wSum[] = "dds.defect_type_id = :defId";
            $pSum[':defId'] = $defectId;
        }
        if ($customer !== '') {
            $wSum[] = "dds.customer = :cust";
            $pSum[':cust'] = $customer;
        }
        $wSumSql = implode(' AND ', $wSum);

        $sqlTop = "SELECT 
                    mp.id,
                    mp.part_code,
                    mp.part_name,
                    COALESCE(SUM(dds.qty_ng), 0) AS total_ng
                   FROM oqc_daily_defect_summary dds
                   INNER JOIN master_parts mp ON dds.part_id = mp.id
                   WHERE {$wSumSql}
                   GROUP BY mp.id, mp.part_code, mp.part_name
                   ORDER BY total_ng DESC";
        $stTop = $pdo->prepare($sqlTop);
        $stTop->execute($pSum);
        $allTopParts = $stTop->fetchAll(PDO::FETCH_ASSOC);

        $limitParts = is_numeric($topRank) ? (int)$topRank : 5;
        if ($limitParts < 1 || $limitParts > 15) $limitParts = 5;
        $topParts = array_slice($allTopParts, 0, $limitParts);

        if (empty($topParts)) {
            echo json_encode([
                'success'     => true,
                'labels'      => [],
                'datasets'    => [],
                'rawMatrix'   => [],
                'partsHeader' => [],
                'unit'        => strtoupper($unit)
            ]);
            exit;
        }

        $topPartIds = array_column($topParts, 'id');
        $placeholders = implode(',', array_fill(0, count($topPartIds), '?'));

        // 2. Build timeframe intervals
        $tf = getTimeframeIntervals($startDate, $endDate, $filterType, $subResolution, $selectedYear, $selectedMonth);
        $intervalType   = $tf['intervalType'];
        $dateFormat     = $tf['dateFormat'];
        $trendMap       = $tf['trendMap']; // keys: time_key => ['label' => ..., 'key' => ...]
        $isWeeklyCustom = ($dateFormat === 'custom_week');

        // 3. Initialize data structure: partId => [ time_key => pcs ]
        $partSeries = [];
        $sampleSeries = []; // time_key => [ partId => total_sample ]
        foreach ($topParts as $idx => $tp) {
            $pid = (int)$tp['id'];
            $partSeries[$pid] = [];
            foreach ($trendMap as $tk => $tMeta) {
                $partSeries[$pid][$tk] = 0;
            }
        }

        // 4. Query defect summary for these top parts
        $wSumT = [
            "dds.summary_date >= ? AND dds.summary_date <= ?",
            "dds.part_id IN ({$placeholders})"
        ];
        $pSumT = array_merge([$startDate, $endDate], $topPartIds);
        if ($defectId > 0) {
            $wSumT[] = "dds.defect_type_id = ?";
            $pSumT[] = $defectId;
        }
        if ($customer !== '') {
            $wSumT[] = "dds.customer = ?";
            $pSumT[] = $customer;
        }
        $wSumTSql = implode(' AND ', $wSumT);

        if ($isWeeklyCustom) {
            $sqlT = "SELECT 
                        dds.summary_date,
                        dds.part_id,
                        COALESCE(SUM(dds.qty_ng), 0) AS total_ng
                     FROM oqc_daily_defect_summary dds
                     WHERE {$wSumTSql}
                     GROUP BY dds.summary_date, dds.part_id";
            $stT = $pdo->prepare($sqlT);
            $stT->execute($pSumT);
            foreach ($stT->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $sDate = $row['summary_date'];
                $pid   = (int)$row['part_id'];
                foreach ($trendMap as $wKey => $wMeta) {
                    if ($sDate >= $wMeta['start_date'] && $sDate <= $wMeta['end_date']) {
                        if (isset($partSeries[$pid][$wKey])) {
                            $partSeries[$pid][$wKey] += (int)$row['total_ng'];
                        }
                        break;
                    }
                }
            }
        } else {
            $sqlT = "SELECT 
                        DATE_FORMAT(dds.summary_date, '{$dateFormat}') AS time_key,
                        dds.part_id,
                        COALESCE(SUM(dds.qty_ng), 0) AS total_ng
                     FROM oqc_daily_defect_summary dds
                     WHERE {$wSumTSql}
                     GROUP BY time_key, dds.part_id";
            $stT = $pdo->prepare($sqlT);
            $stT->execute($pSumT);
            foreach ($stT->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $tk  = $row['time_key'];
                $pid = (int)$row['part_id'];
                if (isset($partSeries[$pid][$tk])) {
                    $partSeries[$pid][$tk] += (int)$row['total_ng'];
                }
            }
        }

        // If unit is PPM, query total sample size from oqc_daily_summary
        if ($unit === 'ppm') {
            $wSamp = [
                "summary_date >= ? AND summary_date <= ?",
                "part_id IN ({$placeholders})"
            ];
            $pSamp = array_merge([$startDate, $endDate], $topPartIds);
            if ($customer !== '') {
                $wSamp[] = "customer = ?";
                $pSamp[] = $customer;
            }
            $wSampSql = implode(' AND ', $wSamp);

            $sqlSamp = "SELECT 
                            DATE_FORMAT(summary_date, '{$dateFormat}') AS time_key,
                            part_id,
                            {$sampleCol} AS total_sample
                        FROM oqc_daily_summary
                        WHERE {$wSampSql}
                        GROUP BY time_key, part_id";
            $stSamp = $pdo->prepare($sqlSamp);
            $stSamp->execute($pSamp);
            foreach ($stSamp->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $tk  = $row['time_key'];
                $pid = (int)$row['part_id'];
                if (!isset($sampleSeries[$tk])) {
                    $sampleSeries[$tk] = [];
                }
                $sampleSeries[$tk][$pid] = (int)$row['total_sample'];
            }
        }

        // 5. Query active sessions for today if applicable
        if ($endDate >= date('Y-m-d')) {
            $wActT = [
                "s.status = 'in_progress'",
                "(ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)",
                "s.started_at >= ? AND s.started_at < ?",
                "ngr.part_id IN ({$placeholders})"
            ];
            $pActT = array_merge([
                $startDate . ' 00:00:00',
                date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'
            ], $topPartIds);
            if ($defectId > 0) {
                $wActT[] = "ngr.defect_type_id = ?";
                $pActT[] = $defectId;
            }
            if ($customer !== '') {
                $wActT[] = "COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') = ?";
                $pActT[] = $customer;
            }
            $wActTSql = implode(' AND ', $wActT);

            $sqlActT = "SELECT 
                            DATE_FORMAT(s.started_at, '{$dateFormat}') AS time_key,
                            ngr.part_id,
                            COALESCE(SUM(ngr.qty_ng), 0) AS total_ng
                        FROM inspection_ng_records ngr
                        INNER JOIN inspection_sessions s ON ngr.inspection_session_id = s.id
                        LEFT JOIN kanban_items ki ON s.kanban_item_id = ki.id
                        WHERE {$wActTSql}
                        GROUP BY time_key, ngr.part_id";
            $stActT = $pdo->prepare($sqlActT);
            $stActT->execute($pActT);
            foreach ($stActT->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $tk  = $row['time_key'];
                $pid = (int)$row['part_id'];
                if (isset($partSeries[$pid][$tk])) {
                    $partSeries[$pid][$tk] += (int)$row['total_ng'];
                }
            }
        }

        // 6. Build labels, datasets, and rawMatrix
        $labels   = [];
        $timeKeys = [];
        foreach ($trendMap as $tk => $tMeta) {
            $labels[]   = $tMeta['label'];
            $timeKeys[] = $tk;
        }

        $datasets = [];
        $partsHeader = [];
        foreach ($topParts as $idx => $tp) {
            $pid   = (int)$tp['id'];
            $color = $paletteParts[$idx % count($paletteParts)];
            $code  = $tp['part_code'];
            $name  = $tp['part_name'];

            $dataArr = [];
            foreach ($timeKeys as $tk) {
                $rawPcs = $partSeries[$pid][$tk] ?? 0;
                if ($unit === 'ppm') {
                    $sTotal = $sampleSeries[$tk][$pid] ?? 0;
                    $val = ($sTotal > 0 && $rawPcs > 0) ? (int)round(($rawPcs / $sTotal) * 1000000) : 0;
                } else {
                    $val = $rawPcs;
                }
                $dataArr[] = $val;
            }

            $datasets[] = [
                'label'           => $code,
                'part_name'       => $name,
                'part_id'         => $pid,
                'data'            => $dataArr,
                'backgroundColor' => $color,
                'borderColor'     => '#ffffff',
                'borderWidth'     => 1,
                'borderRadius'    => 4,
                'stack'           => 'stack1'
            ];

            $partsHeader[] = [
                'part_id'   => $pid,
                'part_code' => $code,
                'part_name' => $name,
                'color'     => $color
            ];
        }

        // Build table matrix rows
        $rawMatrix = [];
        foreach ($timeKeys as $i => $tk) {
            $rowTotal = 0;
            $rowVals  = [];
            foreach ($datasets as $ds) {
                $v = $ds['data'][$i] ?? 0;
                $rowVals[$ds['label']] = $v;
                $rowTotal += $v;
            }
            $rawMatrix[] = [
                'label'  => $labels[$i],
                'values' => $rowVals,
                'total'  => $rowTotal
            ];
        }

        echo json_encode([
            'success'     => true,
            'labels'      => $labels,
            'datasets'    => $datasets,
            'rawMatrix'   => $rawMatrix,
            'partsHeader' => $partsHeader,
            'unit'        => strtoupper($unit),
            'interval'    => $intervalType
        ]);
        exit;

    } elseif ($action === 'get_part_defects') {
        if ($partId <= 0) {
            echo json_encode(['success' => false, 'message' => 'part_id required']);
            exit;
        }

        // 1. Query pre-aggregated daily defect summaries for this part
        $wSum = [
            "dds.summary_date >= :sd AND dds.summary_date <= :ed",
            "dds.part_id = :pId"
        ];
        $pSum = [':sd' => $startDate, ':ed' => $endDate, ':pId' => $partId];
        if ($customer !== '') {
            $wSum[] = "dds.customer = :cust";
            $pSum[':cust'] = $customer;
        }
        $wSumSql = implode(' AND ', $wSum);

        $sqlSum = "SELECT 
                    dt.id AS item_id,
                    dt.name AS item_name,
                    COALESCE(SUM(dds.qty_ng), 0) AS total_pcs,
                    COALESCE(SUM(dds.lot_count), 0) AS total_lots
                   FROM oqc_daily_defect_summary dds
                   INNER JOIN defect_types dt ON dds.defect_type_id = dt.id
                   WHERE {$wSumSql}
                   GROUP BY dt.id, dt.name";
        $stSum = $pdo->prepare($sqlSum);
        $stSum->execute($pSum);
        $rowsSum = $stSum->fetchAll(PDO::FETCH_ASSOC);

        // 2. Active in-progress sessions if range covers today
        $rowsAct = [];
        if ($endDate >= date('Y-m-d')) {
            $wAct = [
                "s.status = 'in_progress'",
                "(ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)",
                "ngr.part_id = :pId",
                "s.started_at >= :sd_act AND s.started_at < :ed_act_next"
            ];
            $pAct = [
                ':pId'         => $partId,
                ':sd_act'      => $startDate . ' 00:00:00',
                ':ed_act_next' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'
            ];
            if ($customer !== '') {
                $wAct[] = "COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') = :cust_act";
                $pAct[':cust_act'] = $customer;
            }
            $wActSql = implode(' AND ', $wAct);

            $sqlAct = "SELECT 
                        dt.id AS item_id,
                        dt.name AS item_name,
                        COALESCE(SUM(ngr.qty_ng), 0) AS total_pcs,
                        COUNT(DISTINCT s.id) AS total_lots
                       FROM inspection_ng_records ngr
                       INNER JOIN inspection_sessions s ON ngr.inspection_session_id = s.id
                       INNER JOIN defect_types dt ON ngr.defect_type_id = dt.id
                       LEFT JOIN kanban_items ki ON s.kanban_item_id = ki.id
                       WHERE {$wActSql}
                       GROUP BY dt.id, dt.name";
            $stAct = $pdo->prepare($sqlAct);
            $stAct->execute($pAct);
            $rowsAct = $stAct->fetchAll(PDO::FETCH_ASSOC);
        }

        // Merge
        $merged = [];
        foreach ($rowsSum as $r) {
            $id = (int)$r['item_id'];
            $merged[$id] = [
                'item_id'    => $id,
                'item_name'  => $r['item_name'],
                'total_pcs'  => (int)$r['total_pcs'],
                'total_lots' => (int)$r['total_lots']
            ];
        }
        foreach ($rowsAct as $r) {
            $id = (int)$r['item_id'];
            if (!isset($merged[$id])) {
                $merged[$id] = [
                    'item_id'    => $id,
                    'item_name'  => $r['item_name'],
                    'total_pcs'  => 0,
                    'total_lots' => 0
                ];
            }
            $merged[$id]['total_pcs'] += (int)$r['total_pcs'];
            $merged[$id]['total_lots'] += (int)$r['total_lots'];
        }

        $raw = array_values($merged);
        usort($raw, function($a, $b) use ($unit) {
            $vA = ($unit === 'lot') ? $a['total_lots'] : $a['total_pcs'];
            $vB = ($unit === 'lot') ? $b['total_lots'] : $b['total_pcs'];
            if ($vA === $vB) return $b['total_pcs'] <=> $a['total_pcs'];
            return $vB <=> $vA;
        });

        // Part and model details
        $stPart = $pdo->prepare("SELECT mp.part_code, mp.part_name, mm.name AS model_name 
                                 FROM master_parts mp 
                                 LEFT JOIN master_models mm ON mp.model_id = mm.id 
                                 WHERE mp.id = :pId");
        $stPart->execute([':pId' => $partId]);
        $partMeta = $stPart->fetch(PDO::FETCH_ASSOC) ?: ['part_code' => '-', 'part_name' => '-', 'model_name' => '-'];

        $res = formatItemsWithTopLimit($raw, $topRank, $unit, $paletteDefects);
        $res['success']   = true;
        $res['part_code'] = $partMeta['part_code'];
        $res['part_name'] = $partMeta['part_name'];
        $res['model_name'] = $partMeta['model_name'] ?: 'General Model';

        // Tab Analisis Part tidak menggunakan target jenis defect
        foreach ($res['items'] as &$it) {
            $it['threshold_val'] = null;
            $it['is_specific_threshold'] = false;
        }
        unset($it);

        echo json_encode($res);
        exit;

    } elseif ($action === 'get_part_defect_stacked_trend') {
        if ($partId <= 0) {
            echo json_encode(['success' => false, 'message' => 'part_id required']);
            exit;
        }

        // Part and model details
        $stPart = $pdo->prepare("SELECT mp.part_code, mp.part_name, mm.name AS model_name 
                                 FROM master_parts mp 
                                 LEFT JOIN master_models mm ON mp.model_id = mm.id 
                                 WHERE mp.id = :pid LIMIT 1");
        $stPart->execute([':pid' => $partId]);
        $partMeta = $stPart->fetch(PDO::FETCH_ASSOC) ?: ['part_code' => '', 'part_name' => '', 'model_name' => ''];

        // 1. Build timeframe intervals
        $tf = getTimeframeIntervals($startDate, $endDate, $filterType, $subResolution, $selectedYear, $selectedMonth);
        $intervalType   = $tf['intervalType'];
        $dateFormat     = $tf['dateFormat'];
        $trendMap       = $tf['trendMap'];
        $isWeeklyCustom = ($dateFormat === 'custom_week');

        // Query all defects for this part in the date range
        $wSumT = [
            "dds.summary_date >= :sd AND dds.summary_date <= :ed",
            "dds.part_id = :pid"
        ];
        $pSumT = [':sd' => $startDate, ':ed' => $endDate, ':pid' => $partId];
        if ($customer !== '') {
            $wSumT[] = "dds.customer = :cust";
            $pSumT[':cust'] = $customer;
        }
        $wSumTSql = implode(' AND ', $wSumT);

        $sqlAll = "SELECT 
                    dt.id,
                    dt.name AS defect_name,
                    COALESCE(SUM(dds.qty_ng), 0) AS total_ng
                   FROM oqc_daily_defect_summary dds
                   INNER JOIN defect_types dt ON dds.defect_type_id = dt.id
                   WHERE {$wSumTSql}
                   GROUP BY dt.id, dt.name
                   ORDER BY total_ng DESC";
        $stAll = $pdo->prepare($sqlAll);
        $stAll->execute($pSumT);
        $allDefects = $stAll->fetchAll(PDO::FETCH_ASSOC);

        if (empty($allDefects)) {
            echo json_encode([
                'success'       => true,
                'part_code'     => $partMeta['part_code'],
                'part_name'     => $partMeta['part_name'],
                'model_name'    => $partMeta['model_name'],
                'labels'        => [],
                'datasets'      => [],
                'rawMatrix'     => [],
                'defectsHeader' => [],
                'unit'          => strtoupper($unit)
            ]);
            exit;
        }

        $defectMetaMap = [];
        foreach ($allDefects as $d) {
            $defectMetaMap[(int)$d['id']] = $d['defect_name'];
        }

        // bucketDefectValues[time_key][defect_id] = total_ng
        $bucketDefectValues = [];
        foreach ($trendMap as $tk => $tMeta) {
            $bucketDefectValues[$tk] = [];
        }

        if ($isWeeklyCustom) {
            $sqlT = "SELECT 
                        dds.summary_date,
                        dds.defect_type_id,
                        COALESCE(SUM(dds.qty_ng), 0) AS total_ng
                     FROM oqc_daily_defect_summary dds
                     WHERE {$wSumTSql}
                     GROUP BY dds.summary_date, dds.defect_type_id";
            $stT = $pdo->prepare($sqlT);
            $stT->execute($pSumT);
            foreach ($stT->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $sDate = $row['summary_date'];
                $did   = (int)$row['defect_type_id'];
                $val   = (int)$row['total_ng'];
                foreach ($trendMap as $wKey => $wMeta) {
                    if ($sDate >= $wMeta['start_date'] && $sDate <= $wMeta['end_date']) {
                        $bucketDefectValues[$wKey][$did] = ($bucketDefectValues[$wKey][$did] ?? 0) + $val;
                        break;
                    }
                }
            }
        } else {
            $sqlT = "SELECT 
                        DATE_FORMAT(dds.summary_date, '{$dateFormat}') AS time_key,
                        dds.defect_type_id,
                        COALESCE(SUM(dds.qty_ng), 0) AS total_ng
                     FROM oqc_daily_defect_summary dds
                     WHERE {$wSumTSql}
                     GROUP BY time_key, dds.defect_type_id";
            $stT = $pdo->prepare($sqlT);
            $stT->execute($pSumT);
            foreach ($stT->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $tk  = $row['time_key'];
                $did = (int)$row['defect_type_id'];
                $val = (int)$row['total_ng'];
                if (isset($bucketDefectValues[$tk])) {
                    $bucketDefectValues[$tk][$did] = ($bucketDefectValues[$tk][$did] ?? 0) + $val;
                }
            }
        }

        // Active sessions for today if applicable
        if ($endDate >= date('Y-m-d')) {
            $wActT = [
                "s.status = 'in_progress'",
                "(ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)",
                "s.started_at >= :sd_act AND s.started_at < :ed_act",
                "ngr.part_id = :pid"
            ];
            $pActT = [
                ':sd_act' => $startDate . ' 00:00:00',
                ':ed_act' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00',
                ':pid'    => $partId
            ];
            if ($customer !== '') {
                $wActT[] = "COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') = :cust";
                $pActT[':cust'] = $customer;
            }
            $wActTSql = implode(' AND ', $wActT);

            if ($isWeeklyCustom) {
                $sqlActT = "SELECT 
                                DATE(s.started_at) AS session_date,
                                ngr.defect_type_id,
                                dt.name AS defect_name,
                                COALESCE(SUM(ngr.qty_ng), 0) AS total_ng
                            FROM inspection_ng_records ngr
                            INNER JOIN inspection_sessions s ON ngr.inspection_session_id = s.id
                            INNER JOIN defect_types dt ON ngr.defect_type_id = dt.id
                            LEFT JOIN kanban_items ki ON s.kanban_item_id = ki.id
                            WHERE {$wActTSql}
                            GROUP BY session_date, ngr.defect_type_id, dt.name";
                $stActT = $pdo->prepare($sqlActT);
                $stActT->execute($pActT);
                foreach ($stActT->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $sDate = $row['session_date'];
                    $did   = (int)$row['defect_type_id'];
                    $val   = (int)$row['total_ng'];
                    $defectMetaMap[$did] = $row['defect_name'];
                    foreach ($trendMap as $wKey => $wMeta) {
                        if ($sDate >= $wMeta['start_date'] && $sDate <= $wMeta['end_date']) {
                            $bucketDefectValues[$wKey][$did] = ($bucketDefectValues[$wKey][$did] ?? 0) + $val;
                            break;
                        }
                    }
                }
            } else {
                $sqlActT = "SELECT 
                                DATE_FORMAT(s.started_at, '{$dateFormat}') AS time_key,
                                ngr.defect_type_id,
                                dt.name AS defect_name,
                                COALESCE(SUM(ngr.qty_ng), 0) AS total_ng
                            FROM inspection_ng_records ngr
                            INNER JOIN inspection_sessions s ON ngr.inspection_session_id = s.id
                            INNER JOIN defect_types dt ON ngr.defect_type_id = dt.id
                            LEFT JOIN kanban_items ki ON s.kanban_item_id = ki.id
                            WHERE {$wActTSql}
                            GROUP BY time_key, ngr.defect_type_id, dt.name";
                $stActT = $pdo->prepare($sqlActT);
                $stActT->execute($pActT);
                foreach ($stActT->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $tk  = $row['time_key'];
                    $did = (int)$row['defect_type_id'];
                    $val = (int)$row['total_ng'];
                    $defectMetaMap[$did] = $row['defect_name'];
                    if (isset($bucketDefectValues[$tk])) {
                        $bucketDefectValues[$tk][$did] = ($bucketDefectValues[$tk][$did] ?? 0) + $val;
                    }
                }
            }
        }

        // If unit is PPM, query sample size for this part
        $sampleSeries = [];
        if ($unit === 'ppm') {
            $wSamp = [
                "summary_date >= :sd AND summary_date <= :ed",
                "part_id = :pid"
            ];
            $pSamp = [':sd' => $startDate, ':ed' => $endDate, ':pid' => $partId];
            if ($customer !== '') {
                $wSamp[] = "customer = :cust";
                $pSamp[':cust'] = $customer;
            }
            $wSampSql = implode(' AND ', $wSamp);

            if ($isWeeklyCustom) {
                $sqlSamp = "SELECT summary_date, {$sampleCol} AS total_sample
                            FROM oqc_daily_summary
                            WHERE {$wSampSql}
                            GROUP BY summary_date";
                $stSamp = $pdo->prepare($sqlSamp);
                $stSamp->execute($pSamp);
                foreach ($stSamp->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $sDate = $row['summary_date'];
                    foreach ($trendMap as $wKey => $wMeta) {
                        if ($sDate >= $wMeta['start_date'] && $sDate <= $wMeta['end_date']) {
                            $sampleSeries[$wKey] = ($sampleSeries[$wKey] ?? 0) + (int)$row['total_sample'];
                            break;
                        }
                    }
                }
            } else {
                $sqlSamp = "SELECT 
                                DATE_FORMAT(summary_date, '{$dateFormat}') AS time_key,
                                {$sampleCol} AS total_sample
                            FROM oqc_daily_summary
                            WHERE {$wSampSql}
                            GROUP BY time_key";
                $stSamp = $pdo->prepare($sqlSamp);
                $stSamp->execute($pSamp);
                foreach ($stSamp->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $tk = $row['time_key'];
                    $sampleSeries[$tk] = (int)$row['total_sample'];
                }
            }
        }

        // Filter Top Defect IDs (Per-Interval / Per-Week ranking)
        $rankLimit = (is_numeric($topRank) && (int)$topRank > 0) ? (int)$topRank : 0;
        $selectedDefectIds = [];

        if ($rankLimit > 0) {
            foreach ($trendMap as $tk => $tMeta) {
                $defsInBucket = $bucketDefectValues[$tk] ?? [];
                if (!empty($defsInBucket)) {
                    $positiveDefs = array_filter($defsInBucket, function($v) { return $v > 0; });
                    arsort($positiveDefs);
                    $topSlice = array_slice(array_keys($positiveDefs), 0, $rankLimit);
                    foreach ($topSlice as $dId) {
                        $selectedDefectIds[$dId] = true;
                    }
                }
            }
            // Fallback: Jika semua interval bernilai 0, ambil top keseluruhan
            if (empty($selectedDefectIds)) {
                $topSlice = array_slice($allDefects, 0, $rankLimit);
                foreach ($topSlice as $td) {
                    $selectedDefectIds[(int)$td['id']] = true;
                }
            }
        } else {
            foreach ($allDefects as $td) {
                $selectedDefectIds[(int)$td['id']] = true;
            }
        }

        // Build labels, datasets, and rawMatrix
        $labels   = [];
        $timeKeys = [];
        foreach ($trendMap as $tk => $tMeta) {
            $labels[]   = $tMeta['label'];
            $timeKeys[] = $tk;
        }

        $datasets = [];
        $defectsHeader = [];
        $defIdx = 0;

        foreach (array_keys($selectedDefectIds) as $did) {
            $name  = $defectMetaMap[$did] ?? "Defect #{$did}";
            $color = $paletteDefects[$defIdx % count($paletteDefects)];

            $dataArr   = [];
            $rawPcsArr = [];
            foreach ($timeKeys as $tk) {
                $rawPcs = $bucketDefectValues[$tk][$did] ?? 0;
                $rawPcsArr[] = $rawPcs;
                if ($unit === 'ppm') {
                    $sTotal = $sampleSeries[$tk] ?? 0;
                    $val = ($sTotal > 0 && $rawPcs > 0) ? (int)round(($rawPcs / $sTotal) * 1000000) : 0;
                } else {
                    $val = $rawPcs;
                }
                $dataArr[] = $val;
            }

            $datasets[] = [
                'label'           => $name,
                'defect_id'       => $did,
                'data'            => $dataArr,
                'raw_pcs'         => $rawPcsArr,
                'part_code'       => $partMeta['part_code'],
                'part_name'       => $partMeta['part_name'],
                'backgroundColor' => $color,
                'borderColor'     => '#ffffff',
                'borderWidth'     => 1,
                'borderRadius'    => 4,
                'stack'           => 'stack1'
            ];

            $defectsHeader[] = [
                'defect_id'   => $did,
                'defect_name' => $name,
                'color'       => $color
            ];
            $defIdx++;
        }

        // Urutkan datasets: Terbanyak di indeks awal agar menjadi tumpukan paling bawah di bar chart
        usort($datasets, function($a, $b) {
            $sumA = array_sum($a['data'] ?? []);
            $sumB = array_sum($b['data'] ?? []);
            if ($sumA === $sumB) return 0;
            return ($sumB <=> $sumA);
        });

        // Build matrix table rows
        $rawMatrix = [];
        foreach ($timeKeys as $i => $tk) {
            $rowTotal = 0;
            $rowVals  = [];
            foreach ($datasets as $ds) {
                $v = $ds['data'][$i] ?? 0;
                $rowVals[$ds['label']] = $v;
                $rowTotal += $v;
            }
            $rawMatrix[] = [
                'label'  => $labels[$i],
                'values' => $rowVals,
                'total'  => $rowTotal
            ];
        }

        echo json_encode([
            'success'       => true,
            'part_code'     => $partMeta['part_code'],
            'part_name'     => $partMeta['part_name'],
            'model_name'    => $partMeta['model_name'],
            'labels'        => $labels,
            'datasets'      => $datasets,
            'rawMatrix'     => $rawMatrix,
            'defectsHeader' => $defectsHeader,
            'unit'          => strtoupper($unit),
            'interval'      => $intervalType
        ]);
        exit;

    } elseif ($action === 'get_part_history') {
        if ($partId <= 0) {
            echo json_encode(['success' => false, 'message' => 'part_id required']);
            exit;
        }

        $whereH = [
            "(ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)",
            "ngr.part_id = :pId",
            "s.started_at >= :sd AND s.started_at < :ed_next"
        ];
        $paramsH = [
            ':pId'     => $partId,
            ':sd'      => $startDate . ' 00:00:00',
            ':ed_next' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'
        ];

        if ($customer !== '') {
            $whereH[] = "COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') = :cust";
            $paramsH[':cust'] = $customer;
        }

        $filteredDefectName = '';
        if ($defectId > 0) {
            $whereH[] = "ngr.defect_type_id = :defId";
            $paramsH[':defId'] = $defectId;

            $stDef = $pdo->prepare("SELECT name FROM defect_types WHERE id = :dId");
            $stDef->execute([':dId' => $defectId]);
            $filteredDefectName = $stDef->fetchColumn() ?: 'Defect #' . $defectId;
        }

        $wSqlH = implode(' AND ', $whereH);

        $sqlHist = "SELECT 
                        ngr.id AS ng_id,
                        s.started_at,
                        s.inspection_type,
                        s.status AS session_status,
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
                    WHERE {$wSqlH}
                    ORDER BY s.started_at DESC, ngr.id DESC
                    LIMIT 300";

        $stmtH = $pdo->prepare($sqlHist);
        $stmtH->execute($paramsH);
        $rows = $stmtH->fetchAll(PDO::FETCH_ASSOC);

        // Quick count of total records without LIMIT 300
        $sqlCount = "SELECT COUNT(*) FROM inspection_ng_records ngr
                     INNER JOIN inspection_sessions s ON ngr.inspection_session_id = s.id
                     LEFT JOIN master_parts mp ON ngr.part_id = mp.id
                     LEFT JOIN kanban_items ki ON s.kanban_item_id = ki.id
                     WHERE {$wSqlH}";
        $stmtCount = $pdo->prepare($sqlCount);
        $stmtCount->execute($paramsH);
        $totalRecords = (int)$stmtCount->fetchColumn();

        // Format items array for clean client-side rendering
        $formattedItems = [];
        foreach ($rows as $row) {
            $isKanban = ($row['inspection_type'] === 'kanban');
            $dt = strtotime($row['started_at']);
            $formattedItems[] = [
                'ng_id'          => (int)$row['ng_id'],
                'date_formatted' => date('d M Y', $dt),
                'time_formatted' => date('H:i', $dt) . ' WIB',
                'part_code'      => $row['part_code'],
                'part_name'      => $row['part_name'],
                'model_name'     => $row['model_name'],
                'defect_name'    => $row['defect_name'],
                'inspection_type'=> $row['inspection_type'],
                'type_label'     => $isKanban ? 'Kanban' : 'Safety Stock',
                'is_kanban'      => $isKanban,
                'kanban_no'      => $row['kanban_no'],
                'customer_name'  => $row['customer_name'],
                'lot_number'     => $row['lot_number'] ?: '-',
                'ref_number'     => $row['ref_number'] ?: '-',
                'inspector_name' => $row['inspector_name'] ?: 'System',
                'qty_ng'         => (int)$row['qty_ng']
            ];
        }

        echo json_encode([
            'success'            => true,
            'count'              => count($formattedItems),
            'total_count'        => $totalRecords,
            'is_capped'          => ($totalRecords > count($formattedItems)),
            'items'              => $formattedItems,
            'filteredDefectName' => $filteredDefectName
        ]);
        exit;
    } elseif ($action === 'get_threshold') {
        $mode   = sanitize($_GET['mode'] ?? 'overview');
        $itemId = isset($_GET['item_id']) && is_numeric($_GET['item_id']) ? (int)$_GET['item_id'] : 0;

        $globalType   = ($mode === 'overview') ? 'global_defect' : 'global_part';
        $specificType = ($mode === 'overview') ? 'specific_defect' : 'specific_part';

        // Check specific threshold
        $specificVal = null;
        if ($itemId > 0) {
            $stmtSpec = $pdo->prepare("SELECT threshold_val FROM defect_threshold_targets WHERE target_type = :tt AND reference_id = :refId LIMIT 1");
            $stmtSpec->execute([':tt' => $specificType, ':refId' => $itemId]);
            $specificRow = $stmtSpec->fetch(PDO::FETCH_ASSOC);
            if ($specificRow !== false) {
                $specificVal = (float)$specificRow['threshold_val'];
            }
        }

        // Check global threshold
        $globalVal = null;
        $stmtGlob = $pdo->prepare("SELECT threshold_val FROM defect_threshold_targets WHERE target_type = :tt AND reference_id IS NULL LIMIT 1");
        $stmtGlob->execute([':tt' => $globalType]);
        $globalRow = $stmtGlob->fetch(PDO::FETCH_ASSOC);
        if ($globalRow !== false) {
            $globalVal = (float)$globalRow['threshold_val'];
        }

        $effectiveThreshold = null;
        $isSpecific = false;

        if ($specificVal !== null) {
            $effectiveThreshold = $specificVal;
            $isSpecific = true;
        } elseif ($globalVal !== null) {
            $effectiveThreshold = $globalVal;
            $isSpecific = false;
        }

        echo json_encode([
            'success'          => true,
            'threshold'        => $effectiveThreshold,
            'is_specific'      => $isSpecific,
            'specific_val'     => $specificVal,
            'global_val'       => $globalVal
        ]);
        exit;

    } elseif ($action === 'get_all_item_thresholds') {
        $mode = sanitize($_GET['mode'] ?? 'overview');
        $globalType   = ($mode === 'overview') ? 'global_defect' : 'global_part';
        $specificType = ($mode === 'overview') ? 'specific_defect' : 'specific_part';

        // 1. Fetch global value
        $stmtGlob = $pdo->prepare("SELECT threshold_val FROM defect_threshold_targets WHERE target_type = :tt AND reference_id IS NULL LIMIT 1");
        $stmtGlob->execute([':tt' => $globalType]);
        $globalRow = $stmtGlob->fetch(PDO::FETCH_ASSOC);
        $globalVal = ($globalRow !== false) ? (float)$globalRow['threshold_val'] : null;

        // 2. Fetch all items with their specific threshold
        $items = [];
        if ($mode === 'overview') {
            $sql = "SELECT dt.id, dt.name, dtt.threshold_val AS specific_val
                    FROM defect_types dt
                    LEFT JOIN defect_threshold_targets dtt 
                           ON dtt.target_type = :tt AND dtt.reference_id = dt.id
                    ORDER BY dt.name ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':tt' => $specificType]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $hasSpec = ($row['specific_val'] !== null);
                $currVal = $hasSpec ? (float)$row['specific_val'] : $globalVal;
                $items[] = [
                    'id'          => (int)$row['id'],
                    'name'        => $row['name'],
                    'code'        => '',
                    'current_val' => $currVal,
                    'is_specific' => $hasSpec,
                    'has_target'  => ($currVal !== null)
                ];
            }
        } else {
            $sql = "SELECT mp.id, mp.part_code, mp.part_name, dtt.threshold_val AS specific_val
                    FROM master_parts mp
                    LEFT JOIN defect_threshold_targets dtt 
                           ON dtt.target_type = :tt AND dtt.reference_id = mp.id
                    ORDER BY mp.part_code ASC, mp.part_name ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':tt' => $specificType]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $hasSpec = ($row['specific_val'] !== null);
                $currVal = $hasSpec ? (float)$row['specific_val'] : $globalVal;
                $items[] = [
                    'id'          => (int)$row['id'],
                    'name'        => $row['part_name'],
                    'code'        => $row['part_code'],
                    'current_val' => $currVal,
                    'is_specific' => $hasSpec,
                    'has_target'  => ($currVal !== null)
                ];
            }
        }

        echo json_encode([
            'success'    => true,
            'mode'       => $mode,
            'global_val' => $globalVal,
            'items'      => $items
        ]);
        exit;

    } elseif ($action === 'save_threshold') {
        // Enforce POST method for mutation
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Metode request harus POST']);
            exit;
        }

        $inputData = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        $mode    = sanitize($inputData['mode'] ?? 'overview');
        $itemId  = isset($inputData['item_id']) && is_numeric($inputData['item_id']) ? (int)$inputData['item_id'] : 0;
        $scope   = sanitize($inputData['scope'] ?? 'specific'); // 'specific' | 'all' | 'batch'
        $itemIds = isset($inputData['item_ids']) && is_array($inputData['item_ids']) ? array_map('intval', $inputData['item_ids']) : [];
        $doClear = !empty($inputData['clear']);
        $rawVal  = isset($inputData['value']) ? (float)$inputData['value'] : 0;

        $targetType = ($mode === 'overview') ? 'specific_defect' : 'specific_part';
        $globalTargetType = ($mode === 'overview') ? 'global_defect' : 'global_part';

        $userId = $_SESSION['user']['id'] ?? null;

        // CASE 1: BATCH ITEMS SAVE / CLEAR
        if ($scope === 'batch') {
            if (empty($itemIds)) {
                echo json_encode(['success' => false, 'message' => 'Tidak ada item yang dipilih untuk batch target']);
                exit;
            }

            if ($doClear || $rawVal <= 0) {
                // Delete specific target for all selected item_ids
                $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
                $stmtDel = $pdo->prepare("DELETE FROM defect_threshold_targets WHERE target_type = ? AND reference_id IN ($placeholders)");
                $params = array_merge([$targetType], $itemIds);
                $stmtDel->execute($params);

                echo json_encode(['success' => true, 'message' => count($itemIds) . ' item batas target berhasil dihapus / dikembalikan ke default']);
                exit;
            } else {
                // Batch Upsert
                $stmtUpsert = $pdo->prepare("INSERT INTO defect_threshold_targets (target_type, reference_id, threshold_val, created_by, updated_at)
                                             VALUES (?, ?, ?, ?, NOW())
                                             ON DUPLICATE KEY UPDATE threshold_val = ?, updated_at = NOW()");
                $pdo->beginTransaction();
                try {
                    foreach ($itemIds as $bId) {
                        if ($bId > 0) {
                            $stmtUpsert->execute([$targetType, $bId, $rawVal, $userId, $rawVal]);
                        }
                    }
                    $pdo->commit();
                } catch (Exception $eBatch) {
                    $pdo->rollBack();
                    throw $eBatch;
                }

                echo json_encode([
                    'success'   => true,
                    'message'   => count($itemIds) . ' item berhasil disetel ke target: ' . $rawVal,
                    'threshold' => $rawVal,
                    'count'     => count($itemIds)
                ]);
                exit;
            }
        }

        // CASE 2: SINGLE SPECIFIC OR ALL (GLOBAL)
        if ($scope === 'all') {
            $targetType  = $globalTargetType;
            $referenceId = null;
        } else {
            $referenceId = $itemId;
            if (!$referenceId || $referenceId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Item ID tidak valid untuk target spesifik']);
                exit;
            }
        }

        if ($doClear || $rawVal <= 0) {
            if ($referenceId === null) {
                $stmtDel = $pdo->prepare("DELETE FROM defect_threshold_targets WHERE target_type = :tt AND reference_id IS NULL");
                $stmtDel->execute([':tt' => $targetType]);
            } else {
                $stmtDel = $pdo->prepare("DELETE FROM defect_threshold_targets WHERE target_type = :tt AND reference_id = :refId");
                $stmtDel->execute([':tt' => $targetType, ':refId' => $referenceId]);
            }
            echo json_encode(['success' => true, 'message' => 'Batas target berhasil dihapus', 'threshold' => null]);
            exit;
        }

        if ($referenceId === null) {
            $stmtSave = $pdo->prepare("INSERT INTO defect_threshold_targets (target_type, reference_id, threshold_val, created_by, updated_at) 
                                       VALUES (:tt, NULL, :val, :uid, NOW())
                                       ON DUPLICATE KEY UPDATE threshold_val = :val2, updated_at = NOW()");
            $stmtSave->execute([
                ':tt'   => $targetType,
                ':val'  => $rawVal,
                ':uid'  => $userId,
                ':val2' => $rawVal
            ]);
        } else {
            $stmtSave = $pdo->prepare("INSERT INTO defect_threshold_targets (target_type, reference_id, threshold_val, created_by, updated_at) 
                                       VALUES (:tt, :refId, :val, :uid, NOW())
                                       ON DUPLICATE KEY UPDATE threshold_val = :val2, updated_at = NOW()");
            $stmtSave->execute([
                ':tt'    => $targetType,
                ':refId' => $referenceId,
                ':val'   => $rawVal,
                ':uid'   => $userId,
                ':val2'  => $rawVal
            ]);
        }

        echo json_encode([
            'success'   => true, 
            'message'   => 'Batas target berhasil disimpan', 
            'threshold' => $rawVal,
            'scope'     => $scope
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
