<?php
/**
 * API: Dashboard Cascading Funnel Engine (Defect -> Model -> Part -> History)
 * Sub-millisecond response using indexed oqc_daily_defect_summary and inspection_ng_records
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';
require_once __DIR__ . '/../../config/summary_helper.php';

// Global Security Guard
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesi login tidak valid. Silakan login kembali.']);
    exit;
}

// Release session lock immediately to allow non-blocking concurrent AJAX calls
session_write_close();

$pdo = getDB();
if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$action     = sanitize($_GET['action'] ?? 'cascade');
$defectName = trim($_GET['defect_name'] ?? '');
$modelName  = trim($_GET['model_name'] ?? '');
$partId     = isset($_GET['part_id']) && is_numeric($_GET['part_id']) ? (int)$_GET['part_id'] : 0;
$partName   = trim($_GET['part_name'] ?? '');
$rankDefect = isset($_GET['rank_defect']) && in_array((int)$_GET['rank_defect'], [3, 5, 10]) ? (int)$_GET['rank_defect'] : 5;
$rankModel  = isset($_GET['rank_model']) && in_array((int)$_GET['rank_model'], [3, 5, 10]) ? (int)$_GET['rank_model'] : 5;
$rankPart   = isset($_GET['rank_part']) && in_array((int)$_GET['rank_part'], [3, 5, 10]) ? (int)$_GET['rank_part'] : 5;
$customer   = trim($_GET['customer'] ?? '');
$unit       = sanitize($_GET['unit'] ?? 'pcs');
if (!in_array($unit, ['pcs', 'lot'])) $unit = 'pcs';

// Date boundaries
$startDate  = sanitize($_GET['start_date'] ?? date('Y-m-01'));
$endDate    = sanitize($_GET['end_date'] ?? date('Y-m-d'));
$endDateNext = date('Y-m-d', strtotime($endDate . ' +1 day'));

$whereSum   = ["dds.summary_date >= :sd AND dds.summary_date < :ed_next"];
$paramsSum  = [':sd' => $startDate, ':ed_next' => $endDateNext];

if ($customer !== '') {
    $whereSum[] = "dds.customer = :cust";
    $paramsSum[':cust'] = $customer;
}

$DEFECT_PALETTE = ['#ef4444', '#f59e0b', '#8b5cf6', '#06b6d4', '#ec4899', '#3b82f6', '#10b981', '#f97316', '#64748b', '#14b8a6'];
$MODEL_PALETTE  = ['#6366f1', '#3b82f6', '#0ea5e9', '#06b6d4', '#14b8a6', '#10b981', '#84cc16', '#eab308', '#f97316', '#ec4899'];
$PART_PALETTE   = ['#0d9488', '#10b981', '#059669', '#0284c7', '#3b82f6', '#6366f1', '#8b5cf6', '#d97706', '#f59e0b', '#dc2626'];

// ══════════════════════════════════════════════════════════════════════════════
// 1. ACTION: CASCADE DEFECTS, MODELS & PARTS
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'cascade') {
    // 0. Query Defects (Dinamis sesuai rank_defect)
    $colDef = ($unit === 'lot') ? "dds.lot_count" : "dds.qty_ng";
    $wSqlDef = implode(' AND ', $whereSum);
    $sqlDef = "SELECT dt.name AS defect_name,
                      COALESCE(SUM({$colDef}), 0) AS total_ng
               FROM oqc_daily_defect_summary dds
               INNER JOIN defect_types dt ON dds.defect_type_id = dt.id
               WHERE {$wSqlDef}
               GROUP BY dt.id, dt.name
               HAVING total_ng > 0
               ORDER BY total_ng DESC
               LIMIT {$rankDefect}";
    $stmtD = $pdo->prepare($sqlDef);
    $stmtD->execute($paramsSum);
    $defectRows = $stmtD->fetchAll(PDO::FETCH_ASSOC);

    $defectLabels = [];
    $defectCounts = [];
    foreach ($defectRows as $dr) {
        $defectLabels[] = $dr['defect_name'];
        $defectCounts[] = (int)$dr['total_ng'];
    }

    // A. Query Models
    $whereModel = $whereSum;
    $paramsModel = $paramsSum;

    if ($defectName !== '') {
        $whereModel[] = "dt.name = :def_name";
        $paramsModel[':def_name'] = $defectName;
    }

    $wSqlModel = implode(' AND ', $whereModel);
    $colModelVal = ($unit === 'lot') ? "dds.lot_count" : "dds.qty_ng";
    $sqlModel = "SELECT COALESCE(mm.name, mp.model, 'NO MODEL') AS model_name,
                        COALESCE(SUM({$colModelVal}), 0) AS total_ng,
                        COALESCE(SUM(dds.lot_count), COUNT(dds.id)) AS total_cases
                 FROM oqc_daily_defect_summary dds
                 INNER JOIN defect_types dt ON dds.defect_type_id = dt.id
                 LEFT JOIN master_parts mp ON dds.part_id = mp.id
                 LEFT JOIN master_models mm ON mp.model_id = mm.id
                 WHERE {$wSqlModel}
                 GROUP BY model_name
                 HAVING total_ng > 0
                 ORDER BY total_ng DESC
                 LIMIT {$rankModel}";

    $stmtM = $pdo->prepare($sqlModel);
    $stmtM->execute($paramsModel);
    $modelRows = $stmtM->fetchAll(PDO::FETCH_ASSOC);

    $modelLabels = [];
    $modelCounts = [];
    $modelCases  = [];
    foreach ($modelRows as $mr) {
        $modelLabels[] = $mr['model_name'];
        $modelCounts[] = (int)$mr['total_ng'];
        $modelCases[]  = max(1, (int)($mr['total_cases'] ?? 1));
    }

    // B. Query Parts
    $wherePart = $whereSum;
    $paramsPart = $paramsSum;

    if ($defectName !== '') {
        $wherePart[] = "dt.name = :def_name_p";
        $paramsPart[':def_name_p'] = $defectName;
    }
    if ($modelName !== '') {
        $wherePart[] = "COALESCE(mm.name, mp.model, 'NO MODEL') = :model_name_p";
        $paramsPart[':model_name_p'] = $modelName;
    }

    $wSqlPart = implode(' AND ', $wherePart);
    $sqlPart = "SELECT mp.id AS part_id,
                       COALESCE(mp.part_code, '-') AS part_code,
                       COALESCE(mp.part_name, 'UNKNOWN PART') AS part_name,
                       SUM(dds.qty_ng) AS total_ng
                FROM oqc_daily_defect_summary dds
                INNER JOIN defect_types dt ON dds.defect_type_id = dt.id
                LEFT JOIN master_parts mp ON dds.part_id = mp.id
                LEFT JOIN master_models mm ON mp.model_id = mm.id
                WHERE {$wSqlPart}
                GROUP BY mp.id, mp.part_code, mp.part_name
                HAVING total_ng > 0
                ORDER BY total_ng DESC
                LIMIT {$rankPart}";

    $stmtP = $pdo->prepare($sqlPart);
    $stmtP->execute($paramsPart);
    $partRows = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    $partLabels = [];
    $partCounts = [];
    $partIds    = [];
    foreach ($partRows as $pr) {
        $partLabels[] = $pr['part_name'];
        $partCounts[] = (int)$pr['total_ng'];
        $partIds[]    = (int)$pr['part_id'];
    }

    // C. Hitung total distinct models dan total distinct parts tanpa batas LIMIT rank
    $sqlTotalModels = "SELECT COUNT(DISTINCT COALESCE(mm.name, mp.model, 'NO MODEL'))
                       FROM oqc_daily_defect_summary dds
                       INNER JOIN defect_types dt ON dds.defect_type_id = dt.id
                       LEFT JOIN master_parts mp ON dds.part_id = mp.id
                       LEFT JOIN master_models mm ON mp.model_id = mm.id
                       WHERE {$wSqlModel} AND {$colModelVal} > 0";
    $stmtTotM = $pdo->prepare($sqlTotalModels);
    $stmtTotM->execute($paramsModel);
    $totalModelsCount = (int)$stmtTotM->fetchColumn();

    $colPartVal = ($unit === 'lot') ? "dds.lot_count" : "dds.qty_ng";
    $sqlTotalParts = "SELECT COUNT(DISTINCT dds.part_id)
                      FROM oqc_daily_defect_summary dds
                      INNER JOIN defect_types dt ON dds.defect_type_id = dt.id
                      LEFT JOIN master_parts mp ON dds.part_id = mp.id
                      LEFT JOIN master_models mm ON mp.model_id = mm.id
                      WHERE {$wSqlPart} AND {$colPartVal} > 0";
    $stmtTotP = $pdo->prepare($sqlTotalParts);
    $stmtTotP->execute($paramsPart);
    $totalPartsCount = (int)$stmtTotP->fetchColumn();

    echo json_encode([
        'success'            => true,
        'defect_name'        => $defectName,
        'model_name'         => $modelName,
        'total_models_count' => $totalModelsCount,
        'total_parts_count'  => $totalPartsCount,
        'defects'      => [
            'labels' => $defectLabels,
            'counts' => $defectCounts,
            'colors' => array_slice($DEFECT_PALETTE, 0, count($defectLabels))
        ],
        'models'       => [
            'labels' => $modelLabels,
            'counts' => $modelCounts,
            'cases'  => $modelCases,
            'colors' => array_slice($MODEL_PALETTE, 0, count($modelLabels))
        ],
        'parts'        => [
            'labels' => $partLabels,
            'counts' => $partCounts,
            'ids'    => $partIds,
            'colors' => array_slice($PART_PALETTE, 0, count($partLabels))
        ]
    ]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// 2. ACTION: GET DETAILED FINDINGS TABLE (Ketika Part / Defect diklik)
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'get_findings') {
    $whereH = [
        "s.started_at >= :sd_dt",
        "s.started_at <= :ed_dt"
    ];
    $paramsH = [
        ':sd_dt' => $startDate . ' 00:00:00',
        ':ed_dt' => $endDate . ' 23:59:59'
    ];

    if ($customer !== '') {
        $whereH[] = "COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') = :cust_h";
        $paramsH[':cust_h'] = $customer;
    }

    if ($defectName !== '') {
        $whereH[] = "dt.name = :def_h";
        $paramsH[':def_h'] = $defectName;
    }

    if ($modelName !== '') {
        $whereH[] = "COALESCE(NULLIF(TRIM(mm.name), ''), NULLIF(TRIM(mp.model), ''), 'NO MODEL') = :model_h";
        $paramsH[':model_h'] = $modelName;
    }

    if ($partId > 0) {
        $whereH[] = "ngr.part_id = :part_id_h";
        $paramsH[':part_id_h'] = $partId;
    } elseif ($partName !== '') {
        $whereH[] = "mp.part_name = :part_name_h";
        $paramsH[':part_name_h'] = $partName;
    }

    $wSqlH = implode(' AND ', $whereH);
    $sqlHist = "SELECT 
                    ngr.id AS ng_id,
                    s.started_at,
                    s.inspection_type,
                    COALESCE(u.name, '-') AS inspector_name,
                    COALESCE(ki.kanban_no, '-') AS kanban_no,
                    COALESCE(NULLIF(TRIM(ki.customer), ''), 'INTERNAL') AS customer_name,
                    COALESCE(mp.part_code, '-') AS part_code,
                    COALESCE(mp.part_name, 'Unknown Part') AS part_name,
                    COALESCE(NULLIF(TRIM(mm.name), ''), NULLIF(TRIM(mp.model), ''), 'General Model') AS model_name,
                    dt.name AS defect_name,
                    COALESCE(ngr.qty_ng, 1) AS qty_ng,
                    COALESCE(NULLIF(ngr.lot_number, ''), NULLIF(isl.lot_number, ''), '-') AS lot_number
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
    $findings = $stmtH->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'     => true,
        'count'       => count($findings),
        'defect_name' => $defectName,
        'model_name'  => $modelName,
        'part_name'   => $partName,
        'findings'    => $findings
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Action tidak dikenal']);
