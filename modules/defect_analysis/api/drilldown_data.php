<?php
/**
 * API: Drilldown Data (Cascading Funnel Engine)
 * Mengembalikan data distribusi bertingkat (Defects -> Models -> Parts -> History) dalam format JSON.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/helper.php';
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

} elseif ($filterType === 'month_year' || $preset === 'month_year') {
    $filterType = 'month_year';
    $startDate  = sprintf('%04d-%02d-01', $selectedYear, $selectedMonth);
    $endDate    = date('Y-m-t', strtotime($startDate));

} elseif ($filterType === 'custom' || (!empty($_GET['start_date']) && !empty($_GET['end_date']))) {
    $filterType = 'custom';
    $startDate  = sanitize($_GET['start_date'] ?? date('Y-m-01'));
    $endDate    = sanitize($_GET['end_date'] ?? date('Y-m-d'));

} else {
    if (empty($preset)) {
        $preset = 'bulanan';
    }
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
            $preset    = 'bulanan';
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
    foreach ($rawItems as $r) {
        $grandPcs  += (int)$r['total_pcs'];
        $grandLots += (int)$r['total_lots'];
    }
    $grandMetric = ($unit === 'lot') ? $grandLots : $grandPcs;

    // Sort by metric
    usort($rawItems, function($a, $b) use ($unit) {
        $valA = ($unit === 'lot') ? (int)$a['total_lots'] : (int)$a['total_pcs'];
        $valB = ($unit === 'lot') ? (int)$b['total_lots'] : (int)$b['total_pcs'];
        return $valB <=> $valA;
    });

    $topLimit = is_numeric($topRank) ? (int)$topRank : 0;
    $displayItems = [];

    if ($topLimit > 0 && count($rawItems) > $topLimit) {
        $primarySlices = array_slice($rawItems, 0, $topLimit);
        $otherSlices   = array_slice($rawItems, $topLimit);
        
        $otherPcs  = 0;
        $otherLots = 0;
        foreach ($otherSlices as $oth) {
            $otherPcs  += (int)$oth['total_pcs'];
            $otherLots += (int)$oth['total_lots'];
        }

        $displayItems = $primarySlices;
        $displayItems[] = [
            'item_id'    => null,
            'item_name'  => 'Lainnya (' . count($otherSlices) . ' lainnya)',
            'total_pcs'  => $otherPcs,
            'total_lots' => $otherLots,
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
        $val = ($unit === 'lot') ? (int)$it['total_lots'] : (int)$it['total_pcs'];
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
            'total_pcs'  => (int)$it['total_pcs'],
            'total_lots' => (int)$it['total_lots'],
            'val'        => $val,
            'percentage' => $share,
            'color'      => $color,
            'is_other'   => isset($it['is_other'])
        ];
    }

    return [
        'items'        => $items,
        'chartLabels'  => $labels,
        'chartData'    => $data,
        'chartColors'  => $colors,
        'grandMetric'  => $grandMetric,
        'grandPcs'     => $grandPcs,
        'grandLots'    => $grandLots,
        'unit'         => strtoupper($unit),
        'topItemName'  => !empty($rawItems) ? $rawItems[0]['item_name'] : '-',
        'topItemVal'   => !empty($rawItems) ? (($unit === 'lot') ? (int)$rawItems[0]['total_lots'] : (int)$rawItems[0]['total_pcs']) : 0,
    ];
}

function getTimeframeIntervals($startDate, $endDate, $filterType) {
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

function getLevelStackedTrend($pdo, $startDate, $endDate, $filterType, $customer, $topItems, $levelType, $defectId = 0, $modelId = null, $unit = 'pcs', $palette = []) {
    if (empty($topItems)) {
        return ['labels' => [], 'datasets' => [], 'rawMatrix' => []];
    }

    $tf = getTimeframeIntervals($startDate, $endDate, $filterType);
    $dateFormat   = $tf['dateFormat'];
    $trendMap     = $tf['trendMap'];

    $colMap = [
        'defect' => 'dds.defect_type_id',
        'model'  => 'dds.model_id',
        'part'   => 'dds.part_id'
    ];
    $targetCol = $colMap[$levelType] ?? 'dds.defect_type_id';

    $itemSeries = [];
    foreach ($topItems as $idx => $ti) {
        if (isset($ti['is_other']) && $ti['is_other']) continue;
        $k = ($ti['item_id'] !== null) ? (string)$ti['item_id'] : 'null';
        $itemSeries[$k] = [];
        foreach ($trendMap as $tk => $tMeta) {
            $itemSeries[$k][$tk] = 0;
        }
    }

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
        if (isset($itemSeries[$ik][$tk])) {
            $val = ($unit === 'lot') ? (int)$r['total_lots'] : (int)$r['total_pcs'];
            $itemSeries[$ik][$tk] += $val;
        }
    }

    if ($endDate >= date('Y-m-d')) {
        $actColMap = [
            'defect' => 'ngr.defect_type_id',
            'model'  => 'COALESCE(mp.model_id, 0)',
            'part'   => 'ngr.part_id'
        ];
        $actCol = $actColMap[$levelType];

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
        if ($levelType === 'model' && $defectId > 0) {
            $wAct[] = "ngr.defect_type_id = :defId_act";
            $pAct[':defId_act'] = $defectId;
        } elseif ($levelType === 'part') {
            if ($defectId > 0) {
                $wAct[] = "ngr.defect_type_id = :defId_act";
                $pAct[':defId_act'] = $defectId;
            }
            if ($modelId !== null) {
                if ($modelId > 0) {
                    $wAct[] = "mp.model_id = :mId_act";
                    $pAct[':mId_act'] = $modelId;
                } else {
                    $wAct[] = "(mp.model_id IS NULL OR mp.model_id = 0)";
                }
            }
        }
        $wActSql = implode(' AND ', $wAct);
        $sqlAct = "SELECT 
                    DATE_FORMAT(s.started_at, '{$dateFormat}') AS time_key,
                    {$actCol} AS item_key,
                    COALESCE(SUM(ngr.qty_ng), 0) AS total_pcs,
                    COUNT(DISTINCT s.id) AS total_lots
                   FROM inspection_ng_records ngr
                   INNER JOIN inspection_sessions s ON ngr.inspection_session_id = s.id
                   LEFT JOIN master_parts mp ON ngr.part_id = mp.id
                   LEFT JOIN kanban_items ki ON s.kanban_item_id = ki.id
                   WHERE {$wActSql}
                   GROUP BY time_key, item_key";
        $stAct = $pdo->prepare($sqlAct);
        $stAct->execute($pAct);
        foreach ($stAct->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $tk = $r['time_key'];
            $ik = (string)$r['item_key'];
            if (isset($itemSeries[$ik][$tk])) {
                $val = ($unit === 'lot') ? (int)$r['total_lots'] : (int)$r['total_pcs'];
                $itemSeries[$ik][$tk] += $val;
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

    foreach ($topItems as $idx => $ti) {
        if (isset($ti['is_other']) && $ti['is_other']) continue;
        $ik    = ($ti['item_id'] !== null) ? (string)$ti['item_id'] : 'null';
        $color = $ti['color'] ?? ($palette[$idx % count($palette)] ?? '#2563eb');
        $name  = $ti['item_name'];

        $dataArr = [];
        foreach ($timeKeys as $tk) {
            $dataArr[] = $itemSeries[$ik][$tk] ?? 0;
        }

        $datasets[] = [
            'label'           => $name,
            'item_id'         => $ti['item_id'],
            'data'            => $dataArr,
            'backgroundColor' => $color,
            'borderColor'     => $color,
            'borderWidth'     => 2,
            'borderRadius'    => 4,
            'stack'           => 'stack1'
        ];
    }

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

        // 2. Query active 'in_progress' sessions if date range includes today
        $rowsAct = [];
        if ($endDate >= date('Y-m-d')) {
            $pAct = [
                ':sd_act'      => $startDate . ' 00:00:00',
                ':ed_act_next' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'
            ];
            $custActSql = "";
            if ($customer !== '') {
                $custActSql = "AND COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') = :cust_act";
                $pAct[':cust_act'] = $customer;
            }
            $sqlAct = "SELECT 
                        dt.id AS item_id,
                        dt.name AS item_name,
                        COALESCE(SUM(ngr.qty_ng), 0) AS total_pcs,
                        COUNT(DISTINCT s.id) AS total_lots
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

        // Merge summary and active
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

        // 2. Query active 'in_progress' sessions if range covers today
        $rowsAct = [];
        if ($endDate >= date('Y-m-d')) {
            $pAct = [
                ':defId'       => $defectId,
                ':sd_act'      => $startDate . ' 00:00:00',
                ':ed_act_next' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'
            ];
            $custActSql = "";
            if ($customer !== '') {
                $custActSql = "AND COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') = :cust_act";
                $pAct[':cust_act'] = $customer;
            }
            $sqlAct = "SELECT 
                        COALESCE(mp.model_id, 0) AS item_id,
                        COALESCE(NULLIF(TRIM(mm.name), ''), NULLIF(TRIM(mp.model), ''), 'General / Unspecified Model') AS item_name,
                        COALESCE(SUM(ngr.qty_ng), 0) AS total_pcs,
                        COUNT(DISTINCT s.id) AS total_lots
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
                       GROUP BY item_id, item_name";
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

        // Fetch active defect name
        $stmtD = $pdo->prepare("SELECT name FROM defect_types WHERE id = :id");
        $stmtD->execute([':id' => $defectId]);
        $defectName = $stmtD->fetchColumn() ?: 'Defect #' . $defectId;

        $res = formatItemsWithTopLimit($raw, $topRank, $unit, $paletteModels);
        $res['trend']   = getLevelStackedTrend($pdo, $startDate, $endDate, $filterType, $customer, $res['items'], 'model', $defectId, null, $unit, $paletteModels);
        $res['success'] = true;
        $res['defectName'] = $defectName;
        echo json_encode($res);
        exit;

    } elseif ($action === 'get_parts') {
        if ($defectId <= 0) {
            echo json_encode(['success' => false, 'message' => 'defect_id required']);
            exit;
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

        // 2. Active in-progress sessions if any
        $rowsAct = [];
        if ($endDate >= date('Y-m-d')) {
            $wAct = [
                "s.status = 'in_progress'",
                "(ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)",
                "ngr.defect_type_id = :defId",
                "s.started_at >= :sd_act AND s.started_at < :ed_act_next"
            ];
            $pAct = [
                ':defId'       => $defectId,
                ':sd_act'      => $startDate . ' 00:00:00',
                ':ed_act_next' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'
            ];
            if ($modelId !== null) {
                if ($modelId > 0) {
                    $wAct[] = "mp.model_id = :mId";
                    $pAct[':mId'] = $modelId;
                } else {
                    $wAct[] = "(mp.model_id IS NULL OR mp.model_id = 0)";
                }
            }
            if ($customer !== '') {
                $wAct[] = "COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') = :cust_act";
                $pAct[':cust_act'] = $customer;
            }
            $wActSql = implode(' AND ', $wAct);
            $sqlAct = "SELECT 
                        mp.id AS item_id,
                        CONCAT(mp.part_code, ' - ', mp.part_name) AS item_name,
                        mp.part_code,
                        mp.part_name,
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

        // Merge
        $merged = [];
        foreach ($rowsSum as $r) {
            $id = (int)$r['item_id'];
            $merged[$id] = [
                'item_id'    => $id,
                'item_name'  => $r['item_name'],
                'part_code'  => $r['part_code'],
                'part_name'  => $r['part_name'],
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
                    'part_code'  => $r['part_code'],
                    'part_name'  => $r['part_name'],
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

        $res = formatItemsWithTopLimit($raw, $topRank, $unit, $paletteParts);
        $res['trend']   = getLevelStackedTrend($pdo, $startDate, $endDate, $filterType, $customer, $res['items'], 'part', $defectId, $modelId, $unit, $paletteParts);
        $res['success'] = true;
        $res['modelName'] = $modelName;
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
                    LIMIT 150";

        $stmtH = $pdo->prepare($sqlHist);
        $stmtH->execute($paramsH);
        $rows = $stmtH->fetchAll(PDO::FETCH_ASSOC);

        // Generate HTML rows for instant injection
        ob_start();
        if (empty($rows)): ?>
            <tr>
                <td colspan="10" style="padding:32px;text-align:center;color:#94a3b8;font-weight:600;">
                    Tidak ada catatan riwayat temuan cacat pada filter yang dipilih.
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($rows as $idx => $row): 
                $isKanban = ($row['inspection_type'] === 'kanban');
            ?>
                <tr style="border-bottom:1px solid #f1f5f9;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                    <td style="padding:10px 14px;text-align:center;color:#94a3b8;font-weight:700;">
                        <?= $idx + 1 ?>
                    </td>
                    <td style="padding:10px 14px;">
                        <div style="font-weight:800;color:#0f172a;font-size:11px;">
                            <?= date('d M Y', strtotime($row['started_at'])) ?>
                        </div>
                        <span style="font-size:10px;color:#64748b;font-weight:600;">
                            <?= date('H:i', strtotime($row['started_at'])) ?> WIB
                        </span>
                    </td>
                    <td style="padding:10px 14px;">
                        <div style="font-weight:800;color:#0f172a;font-size:12px;">
                            <?= htmlspecialchars($row['part_code']) ?>
                        </div>
                        <span style="font-size:11px;color:#64748b;">
                            <?= htmlspecialchars($row['part_name']) ?>
                        </span>
                    </td>
                    <td style="padding:10px 14px;">
                        <span style="display:inline-block;padding:2px 7px;border-radius:5px;background:#f1f5f9;color:#334155;border:1px solid #e2e8f0;font-size:11px;font-weight:700;">
                            <?= htmlspecialchars($row['model_name']) ?>
                        </span>
                    </td>
                    <td style="padding:10px 14px;">
                        <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:6px;font-size:11px;font-weight:800;background:#fff1f2;color:#be123c;border:1px solid #ffe4e6;">
                            <?= htmlspecialchars($row['defect_name']) ?>
                        </span>
                    </td>
                    <td style="padding:10px 14px;text-align:center;">
                        <?php if ($isKanban): ?>
                            <span style="display:inline-block;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:800;background:#eff6ff;color:#1d4ed8;border:1px solid #dbeafe;text-transform:uppercase;">
                                Kanban
                            </span>
                        <?php else: ?>
                            <span style="display:inline-block;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:800;background:#f5f3ff;color:#6d28d9;border:1px solid #ede9fe;text-transform:uppercase;">
                                Safety Stock
                            </span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:10px 14px;">
                        <span style="font-weight:800;color:#0f172a;font-family:monospace;background:#f1f5f9;padding:2px 7px;border-radius:4px;border:1px solid #e2e8f0;font-size:11px;display:inline-block;">
                            <?= htmlspecialchars($row['kanban_no']) ?>
                        </span>
                        <div style="font-weight:700;color:#334155;font-size:11px;margin-top:3px;">
                            <?= htmlspecialchars($row['customer_name']) ?>
                        </div>
                    </td>
                    <td style="padding:10px 14px;">
                        <div style="font-weight:800;color:#0f172a;font-size:12px;font-family:monospace;">
                            <?= htmlspecialchars($row['lot_number'] ?: '-') ?>
                        </div>
                        <div style="font-size:10px;color:#64748b;font-weight:600;margin-top:2px;font-family:monospace;">
                            <?= htmlspecialchars($row['ref_number'] ?: '-') ?>
                        </div>
                    </td>
                    <td style="padding:10px 14px;">
                        <div style="font-weight:700;color:#0f172a;font-size:11px;">
                            <?= htmlspecialchars($row['inspector_name'] ?? 'System') ?>
                        </div>
                    </td>
                    <td style="padding:10px 14px;text-align:right;">
                        <div style="font-weight:900;color:#b91c1c;font-size:13px;">
                            <?= number_format($row['qty_ng']) ?> Pcs
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif;
        $html = ob_get_clean();

        echo json_encode([
            'success'   => true,
            'count'     => count($rows),
            'html'      => $html
        ]);
        exit;

    } elseif ($action === 'get_trend_timeline') {
        if ($defectId <= 0) {
            echo json_encode(['success' => false, 'message' => 'defect_id required']);
            exit;
        }

        // Build WHERE for the timeline
        $whereTimeline = [
            "(ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)",
            "s.started_at >= :sd AND s.started_at < :ed_next",
            "ngr.defect_type_id = :defId"
        ];
        $paramsTimeline = [
            ':sd'      => $startDate . ' 00:00:00',
            ':ed_next' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00',
            ':defId'   => $defectId
        ];

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
                "ngr.defect_type_id = :defId",
                "s.started_at >= :sd AND s.started_at < :ed_next"
            ];
            $pHour = [
                ':defId'   => $defectId,
                ':sd'      => $startDate . ' 00:00:00',
                ':ed_next' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'
            ];
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
                "dds.summary_date >= :sd AND dds.summary_date <= :ed",
                "dds.defect_type_id = :defId"
            ];
            $pSumT = [
                ':sd'    => $startDate,
                ':ed'    => $endDate,
                ':defId' => $defectId
            ];
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

            if ($filterType === 'all_years' || ($filterType === 'custom' && $daysDiff > 730)) {
                $intervalType = 'tahun';
                $minY = (int)date('Y', strtotime($startDate));
                $maxY = (int)date('Y', strtotime($endDate));
                for ($yr = $minY; $yr <= $maxY; $yr++) {
                    $trendMap[(string)$yr] = ['label' => (string)$yr, 'pcs' => 0, 'lot' => 0];
                }
                $dateFormat = '%Y';
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

                $sqlActT = "SELECT 
                                DATE_FORMAT(s.started_at, '{$dateFormat}') as time_key,
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
        $avgMetric   = ($activeIntervals > 0 && $grandMetric > 0) ? round($grandMetric / $activeIntervals, 1) : 0;

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
        // 1. Get Top N parts for this period
        $wSum = ["dds.summary_date >= :sd AND dds.summary_date <= :ed"];
        $pSum = [':sd' => $startDate, ':ed' => $endDate];
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
        $tf = getTimeframeIntervals($startDate, $endDate, $filterType);
        $intervalType = $tf['intervalType'];
        $dateFormat   = $tf['dateFormat'];
        $trendMap     = $tf['trendMap']; // keys: time_key => ['label' => ..., 'key' => ...]

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
        if ($customer !== '') {
            $wSumT[] = "dds.customer = ?";
            $pSumT[] = $customer;
        }
        $wSumTSql = implode(' AND ', $wSumT);

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

        // 1. Get Top N defects for this part in this period
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

        $sqlTop = "SELECT 
                    dt.id,
                    dt.name AS defect_name,
                    COALESCE(SUM(dds.qty_ng), 0) AS total_ng
                   FROM oqc_daily_defect_summary dds
                   INNER JOIN defect_types dt ON dds.defect_type_id = dt.id
                   WHERE {$wSumSql}
                   GROUP BY dt.id, dt.name
                   ORDER BY total_ng DESC";
        $stTop = $pdo->prepare($sqlTop);
        $stTop->execute($pSum);
        $allTopDefects = $stTop->fetchAll(PDO::FETCH_ASSOC);

        $limitDefs = is_numeric($topRank) ? (int)$topRank : 3;
        if ($limitDefs < 1 || $limitDefs > 15) $limitDefs = 3;
        $topDefects   = array_slice($allTopDefects, 0, $limitDefs);
        $otherDefects = array_slice($allTopDefects, $limitDefs);

        if (empty($topDefects)) {
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

        $allDefIds    = array_column($allTopDefects, 'id');
        $topDefIds    = array_column($topDefects, 'id');
        $topDefIdMap  = array_flip($topDefIds);
        $placeholders = implode(',', array_fill(0, count($allDefIds), '?'));

        // 2. Build timeframe intervals
        $tf = getTimeframeIntervals($startDate, $endDate, $filterType);
        $intervalType = $tf['intervalType'];
        $dateFormat   = $tf['dateFormat'];
        $trendMap     = $tf['trendMap'];

        // 3. Initialize defect series: defId => [ time_key => pcs ]
        $defectSeries = [];
        $sampleSeries = []; // time_key => total_sample for this part
        foreach ($topDefects as $idx => $td) {
            $did = (int)$td['id'];
            $defectSeries[$did] = [];
            foreach ($trendMap as $tk => $tMeta) {
                $defectSeries[$did][$tk] = 0;
            }
        }
        if (!empty($otherDefects)) {
            $defectSeries['others'] = [];
            foreach ($trendMap as $tk => $tMeta) {
                $defectSeries['others'][$tk] = 0;
            }
        }

        // 4. Query defect summary for this part and all defects
        $wSumT = [
            "dds.summary_date >= ? AND dds.summary_date <= ?",
            "dds.part_id = ?",
            "dds.defect_type_id IN ({$placeholders})"
        ];
        $pSumT = array_merge([$startDate, $endDate, $partId], $allDefIds);
        if ($customer !== '') {
            $wSumT[] = "dds.customer = ?";
            $pSumT[] = $customer;
        }
        $wSumTSql = implode(' AND ', $wSumT);

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
            if (isset($topDefIdMap[$did])) {
                if (isset($defectSeries[$did][$tk])) {
                    $defectSeries[$did][$tk] += (int)$row['total_ng'];
                }
            } elseif (!empty($otherDefects) && isset($defectSeries['others'][$tk])) {
                $defectSeries['others'][$tk] += (int)$row['total_ng'];
            }
        }

        // If unit is PPM, query sample size for this part
        if ($unit === 'ppm') {
            $wSamp = [
                "summary_date >= ? AND summary_date <= ?",
                "part_id = ?"
            ];
            $pSamp = [$startDate, $endDate, $partId];
            if ($customer !== '') {
                $wSamp[] = "customer = ?";
                $pSamp[] = $customer;
            }
            $wSampSql = implode(' AND ', $wSamp);

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

        // 5. Active sessions for today if applicable
        if ($endDate >= date('Y-m-d')) {
            $wActT = [
                "s.status = 'in_progress'",
                "(ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)",
                "s.started_at >= ? AND s.started_at < ?",
                "ngr.part_id = ?",
                "ngr.defect_type_id IN ({$placeholders})"
            ];
            $pActT = array_merge([
                $startDate . ' 00:00:00',
                date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00',
                $partId
            ], $allDefIds);
            if ($customer !== '') {
                $wActT[] = "COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') = ?";
                $pActT[] = $customer;
            }
            $wActTSql = implode(' AND ', $wActT);

            $sqlActT = "SELECT 
                            DATE_FORMAT(s.started_at, '{$dateFormat}') AS time_key,
                            ngr.defect_type_id,
                            COALESCE(SUM(ngr.qty_ng), 0) AS total_ng
                        FROM inspection_ng_records ngr
                        INNER JOIN inspection_sessions s ON ngr.inspection_session_id = s.id
                        LEFT JOIN kanban_items ki ON s.kanban_item_id = ki.id
                        WHERE {$wActTSql}
                        GROUP BY time_key, ngr.defect_type_id";
            $stActT = $pdo->prepare($sqlActT);
            $stActT->execute($pActT);
            foreach ($stActT->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $tk  = $row['time_key'];
                $did = (int)$row['defect_type_id'];
                if (isset($topDefIdMap[$did])) {
                    if (isset($defectSeries[$did][$tk])) {
                        $defectSeries[$did][$tk] += (int)$row['total_ng'];
                    }
                } elseif (!empty($otherDefects) && isset($defectSeries['others'][$tk])) {
                    $defectSeries['others'][$tk] += (int)$row['total_ng'];
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
        $defectsHeader = [];
        foreach ($topDefects as $idx => $td) {
            $did   = (int)$td['id'];
            $color = $paletteDefects[$idx % count($paletteDefects)];
            $name  = $td['defect_name'];

            $dataArr   = [];
            $rawPcsArr = [];
            foreach ($timeKeys as $tk) {
                $rawPcs = $defectSeries[$did][$tk] ?? 0;
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
        }

        // Tambahkan Others jika ada defect di luar Top N
        if (!empty($otherDefects) && isset($defectSeries['others'])) {
            $otherRawPcsArr = [];
            $otherDataArr   = [];
            foreach ($timeKeys as $tk) {
                $rawPcs = $defectSeries['others'][$tk] ?? 0;
                $otherRawPcsArr[] = $rawPcs;
                if ($unit === 'ppm') {
                    $sTotal = $sampleSeries[$tk] ?? 0;
                    $val = ($sTotal > 0 && $rawPcs > 0) ? (int)round(($rawPcs / $sTotal) * 1000000) : 0;
                } else {
                    $val = $rawPcs;
                }
                $otherDataArr[] = $val;
            }

            $otherLabel = 'Others (' . count($otherDefects) . ' jenis lainnya)';
            $datasets[] = [
                'label'           => $otherLabel,
                'defect_id'       => 0,
                'is_others'       => true,
                'data'            => $otherDataArr,
                'raw_pcs'         => $otherRawPcsArr,
                'part_code'       => $partMeta['part_code'],
                'part_name'       => $partMeta['part_name'],
                'backgroundColor' => '#94a3b8',
                'borderColor'     => '#ffffff',
                'borderWidth'     => 1,
                'borderRadius'    => 4,
                'stack'           => 'stack1'
            ];

            $defectsHeader[] = [
                'defect_id'   => 0,
                'defect_name' => $otherLabel,
                'color'       => '#94a3b8'
            ];
        }

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
                    LIMIT 200";

        $stmtH = $pdo->prepare($sqlHist);
        $stmtH->execute($paramsH);
        $rows = $stmtH->fetchAll(PDO::FETCH_ASSOC);

        ob_start();
        if (empty($rows)): ?>
            <tr>
                <td colspan="10" style="padding:32px;text-align:center;color:#94a3b8;font-weight:600;">
                    Tidak ada catatan riwayat temuan defect pada filter ini.
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($rows as $idx => $row): 
                $isKanban = ($row['inspection_type'] === 'kanban');
            ?>
                <tr style="border-bottom:1px solid #f1f5f9;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                    <td style="padding:10px 14px;text-align:center;color:#94a3b8;font-weight:700;">
                        <?= $idx + 1 ?>
                    </td>
                    <td style="padding:10px 14px;">
                        <div style="font-weight:800;color:#0f172a;font-size:11px;">
                            <?= date('d M Y', strtotime($row['started_at'])) ?>
                        </div>
                        <span style="font-size:10px;color:#64748b;font-weight:600;">
                            <?= date('H:i', strtotime($row['started_at'])) ?> WIB
                        </span>
                    </td>
                    <td style="padding:10px 14px;">
                        <div style="font-weight:800;color:#0f172a;font-size:12px;font-family:monospace;">
                            <?= htmlspecialchars($row['part_code']) ?>
                        </div>
                        <span style="font-size:11px;color:#64748b;">
                            <?= htmlspecialchars($row['part_name']) ?>
                        </span>
                    </td>
                    <td style="padding:10px 14px;">
                        <span style="display:inline-block;padding:2px 7px;border-radius:5px;background:#f1f5f9;color:#334155;border:1px solid #e2e8f0;font-size:11px;font-weight:700;">
                            <?= htmlspecialchars($row['model_name']) ?>
                        </span>
                    </td>
                    <td style="padding:10px 14px;">
                        <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:6px;font-size:11px;font-weight:800;background:#fff1f2;color:#be123c;border:1px solid #ffe4e6;">
                            <?= htmlspecialchars($row['defect_name']) ?>
                        </span>
                    </td>
                    <td style="padding:10px 14px;text-align:center;">
                        <?php if ($isKanban): ?>
                            <span style="display:inline-block;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:800;background:#eff6ff;color:#1d4ed8;border:1px solid #dbeafe;text-transform:uppercase;">
                                Kanban
                            </span>
                        <?php else: ?>
                            <span style="display:inline-block;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:800;background:#f5f3ff;color:#6d28d9;border:1px solid #ede9fe;text-transform:uppercase;">
                                Safety Stock
                            </span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:10px 14px;">
                        <span style="font-weight:800;color:#0f172a;font-family:monospace;background:#f1f5f9;padding:2px 7px;border-radius:4px;border:1px solid #e2e8f0;font-size:11px;display:inline-block;">
                            <?= htmlspecialchars($row['kanban_no']) ?>
                        </span>
                        <div style="font-weight:700;color:#334155;font-size:11px;margin-top:3px;">
                            <?= htmlspecialchars($row['customer_name']) ?>
                        </div>
                    </td>
                    <td style="padding:10px 14px;">
                        <div style="font-weight:800;color:#0f172a;font-size:12px;font-family:monospace;">
                            <?= htmlspecialchars($row['lot_number'] ?: '-') ?>
                        </div>
                        <div style="font-size:10px;color:#64748b;font-weight:600;margin-top:2px;font-family:monospace;">
                            <?= htmlspecialchars($row['ref_number'] ?: '-') ?>
                        </div>
                    </td>
                    <td style="padding:10px 14px;">
                        <div style="font-weight:700;color:#0f172a;font-size:11px;">
                            <?= htmlspecialchars($row['inspector_name'] ?? 'System') ?>
                        </div>
                    </td>
                    <td style="padding:10px 14px;text-align:right;">
                        <div style="font-weight:900;color:#b91c1c;font-size:13px;">
                            <?= number_format($row['qty_ng']) ?> Pcs
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif;
        $html = ob_get_clean();

        echo json_encode([
            'success'            => true,
            'count'              => count($rows),
            'html'               => $html,
            'filteredDefectName' => $filteredDefectName
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
