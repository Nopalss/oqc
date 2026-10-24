<?php
/**
 * Modul Safety Stock - Detail Realisasi Safety Stock
 * Halaman rincian stok safety stock per part, label box / lot fisik di gudang,
 * serta riwayat alokasi pemotongan Kanban.
 */
$breadcrumbCategory = "OPERASIONAL";
$pageTitle = "Detail Safety Stock";
$pageSubtitle = "Rincian label box di gudang, sisa stok fisik, dan riwayat pemakaian memotong Kanban";

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

require_menu_access('safety_stock');

$currentUser = current_user();
$isAdmin = ($currentUser['role'] === 'admin');

$partCodeParam = sanitize($_GET['part_code'] ?? '');
$sessionIdParam = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$pdo = getDB();
$partInfo = null;
$sessionLots = [];
$kanbanUsage = [];
$defects = [];

// Filter & Pagination Parameters for Table 1 (Box di Gudang)
$boxSearch    = sanitize($_GET['search_box'] ?? '');
$boxStatus    = sanitize($_GET['lot_status'] ?? '');
$boxStartDate = sanitize($_GET['box_start_date'] ?? '');
$boxEndDate   = sanitize($_GET['box_end_date'] ?? '');

$boxLimit = filter_input(INPUT_GET, 'box_limit', FILTER_VALIDATE_INT) ?: 10;
if (!in_array($boxLimit, [10, 25, 50, 100])) {
    $boxLimit = 10;
}
$boxPage = filter_input(INPUT_GET, 'box_page', FILTER_VALIDATE_INT) ?: 1;
if ($boxPage < 1) $boxPage = 1;

$totalFilteredLots = 0;
$totalBoxPages     = 1;
$totalActiveBoxes  = 0;

// Filter & Pagination Parameters for Table 2 (Riwayat Pemakaian Kanban)
// Menggunakan filter default Hari Ini agar tidak query jutaan data lama saat load awal
$historyPeriod    = sanitize($_GET['hist_period'] ?? 'today'); // 'today', '7days', 'month', 'all', 'custom'
$historySearch    = sanitize($_GET['hist_search'] ?? '');
$historyStartDate = sanitize($_GET['hist_start_date'] ?? date('Y-m-d'));
$historyEndDate   = sanitize($_GET['hist_end_date'] ?? date('Y-m-d'));

if ($historyPeriod === 'today') {
    $historyStartDate = date('Y-m-d');
    $historyEndDate   = date('Y-m-d');
} elseif ($historyPeriod === '7days') {
    $historyStartDate = date('Y-m-d', strtotime('-6 days'));
    $historyEndDate   = date('Y-m-d');
} elseif ($historyPeriod === 'month') {
    $historyStartDate = date('Y-m-01');
    $historyEndDate   = date('Y-m-d');
} elseif ($historyPeriod === 'all') {
    $historyStartDate = '';
    $historyEndDate   = '';
}

$historyLimit = filter_input(INPUT_GET, 'hist_limit', FILTER_VALIDATE_INT) ?: 10;
if (!in_array($historyLimit, [5, 10, 25, 50])) {
    $historyLimit = 10;
}
$historyPage = filter_input(INPUT_GET, 'hist_page', FILTER_VALIDATE_INT) ?: 1;
if ($historyPage < 1) $historyPage = 1;

$totalFilteredHistory = 0;
$totalHistoryPages    = 1;

$totalInitialStock = 0;
$totalUsedQty      = 0;
$availableStock    = 0;
$hasInProgress     = false;
$hasRejected       = false;

if ($pdo) {
    try {
        // Fallback: If part_code is empty but session id is passed, lookup part_code from session
        if (empty($partCodeParam) && $sessionIdParam > 0) {
            $stLookup = $pdo->prepare("
                SELECT ss.id, did.part_code AS did_pcode, ki.item_code AS ki_pcode
                FROM inspection_sessions ss
                LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
                LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
                WHERE ss.id = :id
            ");
            $stLookup->execute([':id' => $sessionIdParam]);
            $lk = $stLookup->fetch(PDO::FETCH_ASSOC);
            if ($lk) {
                $partCodeParam = $lk['did_pcode'] ?: $lk['ki_pcode'];
            }
        }

        if (!empty($partCodeParam)) {
            // 1. Fetch Part Metadata
            $stPart = $pdo->prepare("
                SELECT 
                    COALESCE(did.part_code, ki.item_code, :pcode) AS item_code,
                    COALESCE(did.part_name, ki.item_description, 'Part Safety Stock') AS item_description,
                    COALESCE(ki.customer, 'INTERNAL SAFETY STOCK') AS customer,
                    COALESCE(ki.str_loc, 'WH-SAFETY') AS str_loc
                FROM inspection_sessions ss
                LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
                LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
                WHERE ss.inspection_type = 'safety_stock'
                  AND (did.part_code = :p1 OR ki.item_code = :p2)
                LIMIT 1
            ");
            $stPart->execute([
                ':pcode' => $partCodeParam,
                ':p1'    => $partCodeParam,
                ':p2'    => $partCodeParam
            ]);
            $partInfo = $stPart->fetch(PDO::FETCH_ASSOC);

            if ($partInfo) {
                // 2. High-Performance Aggregate Metrics (Direct B-Tree Index Scan based on latest box state)
                $stMetrics = $pdo->prepare("
                    SELECT 
                        COUNT(DISTINCT latest_isl.ref_key) AS total_boxes,
                        COALESCE(SUM(latest_isl.qty), 0) AS total_initial,
                        COALESCE(SUM(CASE WHEN ss.auto_fulfilled_by_session_id > 0 THEN latest_isl.qty ELSE 0 END), 0) AS total_used,
                        COALESCE(SUM(CASE 
                            WHEN (ss.auto_fulfilled_by_session_id IS NULL OR ss.auto_fulfilled_by_session_id = 0) 
                                 AND ss.status = 'passed' 
                            THEN latest_isl.qty ELSE 0 END), 0) AS available_stock,
                        COALESCE(SUM(CASE 
                            WHEN (ss.auto_fulfilled_by_session_id IS NULL OR ss.auto_fulfilled_by_session_id = 0) 
                                 AND ss.status = 'in_progress' 
                            THEN 1 ELSE 0 END), 0) AS in_progress_count,
                        COALESCE(SUM(CASE 
                            WHEN (ss.auto_fulfilled_by_session_id IS NULL OR ss.auto_fulfilled_by_session_id = 0) 
                                 AND ss.status = 'rejected' 
                            THEN 1 ELSE 0 END), 0) AS rejected_count
                    FROM (
                        SELECT isl.id, isl.inspection_session_id, isl.qty,
                               COALESCE(NULLIF(TRIM(isl.ref_number), ''), CONCAT('LOT-', isl.id)) AS ref_key
                        FROM inspection_session_lots isl
                        JOIN inspection_sessions ss2 ON ss2.id = isl.inspection_session_id
                        LEFT JOIN daily_inspection_data did2 ON did2.id = ss2.did_id
                        LEFT JOIN kanban_items ki2 ON ki2.id = ss2.kanban_item_id
                        WHERE ss2.inspection_type = 'safety_stock'
                          AND (did2.part_code = :p1 OR ki2.item_code = :p2)
                          AND (isl.lot_status IS NULL OR isl.lot_status IN ('ok', 'reinspected', 'passed', ''))
                          AND isl.id IN (
                              SELECT MAX(isl3.id)
                              FROM inspection_session_lots isl3
                              JOIN inspection_sessions ss3 ON ss3.id = isl3.inspection_session_id
                              LEFT JOIN daily_inspection_data did3 ON did3.id = ss3.did_id
                              LEFT JOIN kanban_items ki3 ON ki3.id = ss3.kanban_item_id
                              WHERE ss3.inspection_type = 'safety_stock'
                                AND (did3.part_code = :p3 OR ki3.item_code = :p4)
                                AND (isl3.lot_status IS NULL OR isl3.lot_status IN ('ok', 'reinspected', 'passed', ''))
                              GROUP BY COALESCE(NULLIF(TRIM(isl3.ref_number), ''), CONCAT('LOT-', isl3.id))
                          )
                    ) latest_isl
                    JOIN inspection_sessions ss ON ss.id = latest_isl.inspection_session_id
                ");
                $stMetrics->execute([
                    ':p1' => $partCodeParam,
                    ':p2' => $partCodeParam,
                    ':p3' => $partCodeParam,
                    ':p4' => $partCodeParam
                ]);
                $metrics = $stMetrics->fetch(PDO::FETCH_ASSOC);

                $totalInitialStock = (int)($metrics['total_initial'] ?? 0);
                $totalUsedQty      = (int)($metrics['total_used'] ?? 0);
                $availableStock    = (int)($metrics['available_stock'] ?? 0);
                $hasInProgress     = ((int)($metrics['in_progress_count'] ?? 0) > 0);
                $hasRejected       = ((int)($metrics['rejected_count'] ?? 0) > 0);

                // 3. Count Total Active Boxes in Warehouse (All-time active for KPI Card)
                $stActiveBox = $pdo->prepare("
                    SELECT COUNT(DISTINCT latest_isl.ref_key)
                    FROM (
                        SELECT isl.id, isl.inspection_session_id,
                               COALESCE(NULLIF(TRIM(isl.ref_number), ''), CONCAT('LOT-', isl.id)) AS ref_key
                        FROM inspection_session_lots isl
                        JOIN inspection_sessions ss2 ON ss2.id = isl.inspection_session_id
                        LEFT JOIN daily_inspection_data did2 ON did2.id = ss2.did_id
                        LEFT JOIN kanban_items ki2 ON ki2.id = ss2.kanban_item_id
                        WHERE ss2.inspection_type = 'safety_stock'
                          AND (did2.part_code = :p1 OR ki2.item_code = :p2)
                          AND (ss2.auto_fulfilled_by_session_id IS NULL OR ss2.auto_fulfilled_by_session_id = 0)
                          AND (isl.lot_status IS NULL OR isl.lot_status IN ('ok', 'reinspected', 'passed', ''))
                          AND isl.id IN (
                              SELECT MAX(isl3.id)
                              FROM inspection_session_lots isl3
                              JOIN inspection_sessions ss3 ON ss3.id = isl3.inspection_session_id
                              LEFT JOIN daily_inspection_data did3 ON did3.id = ss3.did_id
                              LEFT JOIN kanban_items ki3 ON ki3.id = ss3.kanban_item_id
                              WHERE ss3.inspection_type = 'safety_stock'
                                AND (did3.part_code = :p3 OR ki3.item_code = :p4)
                                AND (isl3.lot_status IS NULL OR isl3.lot_status IN ('ok', 'reinspected', 'passed', ''))
                              GROUP BY COALESCE(NULLIF(TRIM(isl3.ref_number), ''), CONCAT('LOT-', isl3.id))
                          )
                    ) latest_isl
                    JOIN inspection_sessions ss ON ss.id = latest_isl.inspection_session_id
                ");
                $stActiveBox->execute([
                    ':p1' => $partCodeParam,
                    ':p2' => $partCodeParam,
                    ':p3' => $partCodeParam,
                    ':p4' => $partCodeParam
                ]);
                $totalActiveBoxes = (int)$stActiveBox->fetchColumn();

                // 4. Build Filtered Query for Table 1 (Active Boxes in Warehouse, latest per physical box)
                $lotWhere = [
                    "ss.inspection_type = 'safety_stock'",
                    "(did.part_code = :p1 OR ki.item_code = :p2)",
                    "(ss.auto_fulfilled_by_session_id IS NULL OR ss.auto_fulfilled_by_session_id = 0)",
                    "(isl.lot_status IS NULL OR isl.lot_status IN ('ok', 'reinspected', 'passed', ''))",
                    "isl.id IN (
                        SELECT MAX(isl2.id)
                        FROM inspection_session_lots isl2
                        JOIN inspection_sessions ss2 ON ss2.id = isl2.inspection_session_id
                        LEFT JOIN daily_inspection_data did2 ON did2.id = ss2.did_id
                        LEFT JOIN kanban_items ki2 ON ki2.id = ss2.kanban_item_id
                        WHERE ss2.inspection_type = 'safety_stock'
                          AND (did2.part_code = :sub_p1 OR ki2.item_code = :sub_p2)
                          AND (isl2.lot_status IS NULL OR isl2.lot_status IN ('ok', 'reinspected', 'passed', ''))
                        GROUP BY COALESCE(NULLIF(TRIM(isl2.ref_number), ''), CONCAT('LOT-', isl2.id))
                    )"
                ];
                $lotParams = [
                    ':p1'     => $partCodeParam,
                    ':p2'     => $partCodeParam,
                    ':sub_p1' => $partCodeParam,
                    ':sub_p2' => $partCodeParam
                ];

                // Search Filter: support Ref No, Lot No, or QR format (Z1...|Z2...|Z5...)
                if (!empty($boxSearch)) {
                    $qrRef = '';
                    $qrLot = '';
                    if (strpos($boxSearch, '|') !== false || str_starts_with($boxSearch, 'Z1') || str_starts_with($boxSearch, 'Z5')) {
                        $parts = explode('|', $boxSearch);
                        foreach ($parts as $p) {
                            $p = trim($p);
                            if (str_starts_with($p, 'Z5')) $qrRef = substr($p, 2);
                            if (str_starts_with($p, 'Z2')) $qrLot = substr($p, 2);
                        }
                    }

                    if (!empty($qrRef) && !empty($qrLot)) {
                        $lotWhere[] = "(isl.ref_number LIKE :qrRef AND isl.lot_number LIKE :qrLot)";
                        $lotParams[':qrRef'] = '%' . $qrRef . '%';
                        $lotParams[':qrLot'] = '%' . $qrLot . '%';
                    } elseif (!empty($qrRef)) {
                        $lotWhere[] = "isl.ref_number LIKE :qrRef";
                        $lotParams[':qrRef'] = '%' . $qrRef . '%';
                    } elseif (!empty($qrLot)) {
                        $lotWhere[] = "isl.lot_number LIKE :qrLot";
                        $lotParams[':qrLot'] = '%' . $qrLot . '%';
                    } else {
                        $lotWhere[] = "(isl.ref_number LIKE :s1 OR isl.lot_number LIKE :s2)";
                        $lotParams[':s1'] = '%' . $boxSearch . '%';
                        $lotParams[':s2'] = '%' . $boxSearch . '%';
                    }
                }

                // Lot Status Filter (passed / in_progress / rejected)
                if (!empty($boxStatus)) {
                    $statMap = ($boxStatus === 'available') ? 'passed' : $boxStatus;
                    $lotWhere[] = "ss.status = :boxStatus";
                    $lotParams[':boxStatus'] = $statMap;
                }

                // Optional Date Range Filter
                if (!empty($boxStartDate) && !empty($boxEndDate)) {
                    $lotWhere[] = "DATE(ss.started_at) BETWEEN :bsd AND :bed";
                    $lotParams[':bsd'] = $boxStartDate;
                    $lotParams[':bed'] = $boxEndDate;
                }

                $whereLotsStr = implode(" AND ", $lotWhere);

                // Count Filtered Lots for Pagination
                $stCount = $pdo->prepare("
                    SELECT COUNT(isl.id)
                    FROM inspection_session_lots isl
                    JOIN inspection_sessions ss ON ss.id = isl.inspection_session_id
                    LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
                    LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
                    WHERE {$whereLotsStr}
                ");
                $stCount->execute($lotParams);
                $totalFilteredLots = (int)$stCount->fetchColumn();

                $totalBoxPages = max(1, (int)ceil($totalFilteredLots / $boxLimit));
                if ($boxPage > $totalBoxPages) {
                    $boxPage = $totalBoxPages;
                }
                $boxOffset = ($boxPage - 1) * $boxLimit;

                // Paginated Fetch of Active Boxes
                $stLots = $pdo->prepare("
                    SELECT isl.*, 
                           ss.started_at AS scanned_at, 
                           ss.status AS session_status, 
                           ss.auto_fulfilled_by_session_id,
                           ss.is_reinspection,
                           ss.reinspection_type,
                           ss.parent_session_id,
                           ss.reinspection_notes,
                           COALESCE(u.name, did.pic, 'Inspector QC') AS inspector_name
                    FROM inspection_session_lots isl
                    JOIN inspection_sessions ss ON ss.id = isl.inspection_session_id
                    LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
                    LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
                    LEFT JOIN users u ON u.id = ss.inspector_id
                    WHERE {$whereLotsStr}
                    ORDER BY isl.id DESC
                    LIMIT " . (int)$boxLimit . " OFFSET " . (int)$boxOffset
                );
                $stLots->execute($lotParams);
                $sessionLots = $stLots->fetchAll(PDO::FETCH_ASSOC);

                // Fallback for legacy session without individual session_lots rows
                if (empty($sessionLots) && $totalFilteredLots === 0 && empty($boxSearch) && empty($boxStatus) && empty($boxStartDate)) {
                    $stFallback = $pdo->prepare("
                        SELECT ss.id, ss.total_scanned_qty, ss.started_at, ss.status,
                               did.lot_number AS did_lot,
                               COALESCE(u.name, did.pic, 'Inspector QC') AS inspector_name
                        FROM inspection_sessions ss
                        LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
                        LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
                        LEFT JOIN users u ON u.id = ss.inspector_id
                        WHERE ss.inspection_type = 'safety_stock'
                          AND (did.part_code = :p1 OR ki.item_code = :p2)
                          AND (ss.auto_fulfilled_by_session_id IS NULL OR ss.auto_fulfilled_by_session_id = 0)
                        ORDER BY ss.id DESC
                    ");
                    $stFallback->execute([':p1' => $partCodeParam, ':p2' => $partCodeParam]);
                    $fbSessions = $stFallback->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($fbSessions as $fb) {
                        $sessionLots[] = [
                            'id'                           => 0,
                            'inspection_session_id'        => $fb['id'],
                            'ref_number'                   => '-',
                            'lot_number'                   => $fb['did_lot'] ?: 'LOT-SS',
                            'qty'                          => (int)$fb['total_scanned_qty'],
                            'scanned_at'                   => $fb['started_at'],
                            'session_status'               => $fb['status'],
                            'auto_fulfilled_by_session_id' => 0,
                            'inspector_name'               => $fb['inspector_name'],
                            'remarks'                      => 'Sesi Utuh'
                        ];
                    }
                    if (!empty($sessionLots)) {
                        $totalFilteredLots = count($sessionLots);
                        $totalActiveBoxes  = count($sessionLots);
                    }
                }

                // 5. Fetch Kanban Usage History with Server-Side Filter & Pagination (Table 2)
                $khWhere = [
                    "s_ss.inspection_type = 'safety_stock'",
                    "(did.part_code = :p1 OR ki_ss.item_code = :p2)",
                    "s_ss.auto_fulfilled_by_session_id > 0"
                ];
                $khParams = [
                    ':p1' => $partCodeParam,
                    ':p2' => $partCodeParam
                ];

                // Tanggal Filter (Default: Hari Ini)
                if (!empty($historyStartDate) && !empty($historyEndDate)) {
                    $khWhere[] = "DATE(s_kanban.started_at) BETWEEN :h_sd AND :h_ed";
                    $khParams[':h_sd'] = $historyStartDate;
                    $khParams[':h_ed'] = $historyEndDate;
                }

                // Search / Barcode Filter
                if (!empty($historySearch)) {
                    $khQrRef = '';
                    $khQrLot = '';
                    if (strpos($historySearch, '|') !== false || str_starts_with($historySearch, 'Z1') || str_starts_with($historySearch, 'Z5')) {
                        $parts = explode('|', $historySearch);
                        foreach ($parts as $p) {
                            $p = trim($p);
                            if (str_starts_with($p, 'Z5')) $khQrRef = substr($p, 2);
                            if (str_starts_with($p, 'Z2')) $khQrLot = substr($p, 2);
                        }
                    }

                    if (!empty($khQrRef) && !empty($khQrLot)) {
                        $khWhere[] = "((isl_k.ref_number LIKE :qrRef OR isl_ss.ref_number LIKE :qrRef2) AND (isl_k.lot_number LIKE :qrLot OR isl_ss.lot_number LIKE :qrLot2))";
                        $khParams[':qrRef']  = '%' . $khQrRef . '%';
                        $khParams[':qrRef2'] = '%' . $khQrRef . '%';
                        $khParams[':qrLot']  = '%' . $khQrLot . '%';
                        $khParams[':qrLot2'] = '%' . $khQrLot . '%';
                    } elseif (!empty($khQrRef)) {
                        $khWhere[] = "(isl_k.ref_number LIKE :qrRef OR isl_ss.ref_number LIKE :qrRef2)";
                        $khParams[':qrRef']  = '%' . $khQrRef . '%';
                        $khParams[':qrRef2'] = '%' . $khQrRef . '%';
                    } elseif (!empty($khQrLot)) {
                        $khWhere[] = "(isl_k.lot_number LIKE :qrLot OR isl_ss.lot_number LIKE :qrLot2)";
                        $khParams[':qrLot']  = '%' . $khQrLot . '%';
                        $khParams[':qrLot2'] = '%' . $khQrLot . '%';
                    } else {
                        $khWhere[] = "(ki.kanban_no LIKE :ks1 OR isl_k.ref_number LIKE :ks2 OR isl_ss.ref_number LIKE :ks3 OR isl_k.lot_number LIKE :ks4 OR isl_ss.lot_number LIKE :ks5)";
                        $khParams[':ks1'] = '%' . $historySearch . '%';
                        $khParams[':ks2'] = '%' . $historySearch . '%';
                        $khParams[':ks3'] = '%' . $historySearch . '%';
                        $khParams[':ks4'] = '%' . $historySearch . '%';
                        $khParams[':ks5'] = '%' . $historySearch . '%';
                    }
                }

                $khWhereStr = implode(" AND ", $khWhere);

                // Count Total Filtered History
                $stCountKh = $pdo->prepare("
                    SELECT COUNT(s_ss.id)
                    FROM inspection_sessions s_ss
                    JOIN inspection_sessions s_kanban ON s_kanban.id = s_ss.auto_fulfilled_by_session_id
                    LEFT JOIN daily_inspection_data did ON did.id = s_ss.did_id
                    LEFT JOIN kanban_items ki_ss ON ki_ss.id = s_ss.kanban_item_id
                    LEFT JOIN kanban_items ki ON ki.id = s_kanban.kanban_item_id
                    LEFT JOIN inspection_session_lots isl_ss ON (
                        isl_ss.inspection_session_id = s_ss.id
                        AND isl_ss.id = (SELECT MIN(x.id) FROM inspection_session_lots x WHERE x.inspection_session_id = s_ss.id)
                    )
                    LEFT JOIN inspection_session_lots isl_k ON (
                        isl_k.inspection_session_id = s_kanban.id
                        AND isl_k.id = (
                            SELECT MIN(x2.id) FROM inspection_session_lots x2
                            WHERE x2.inspection_session_id = s_kanban.id
                              AND (
                                  x2.remarks LIKE CONCAT('%Sesi #', s_ss.id, '%')
                                  OR (isl_ss.ref_number IS NOT NULL AND isl_ss.ref_number != '' AND x2.ref_number = isl_ss.ref_number)
                              )
                        )
                    )
                    WHERE {$khWhereStr}
                ");
                $stCountKh->execute($khParams);
                $totalFilteredHistory = (int)$stCountKh->fetchColumn();

                $totalHistoryPages = max(1, (int)ceil($totalFilteredHistory / $historyLimit));
                if ($historyPage > $totalHistoryPages) {
                    $historyPage = $totalHistoryPages;
                }
                $historyOffset = ($historyPage - 1) * $historyLimit;

                $stmtKanban = $pdo->prepare("
                    SELECT 
                        s_kanban.id AS kanban_session_id,
                        s_kanban.started_at AS used_at,
                        s_kanban.closed_at AS closed_at,
                        s_kanban.status AS session_status,
                        s_kanban.samples_checked,
                        s_kanban.sample_size,
                        s_kanban.ng_count,
                        s_ss.id AS ss_session_id,
                        -- Qty terpakai = qty fisik label lot kanban - kelebihan (excess) kanban,
                        -- dibatasi maksimal saldo sesi SS tersebut.
                        LEAST(
                            s_ss.total_scanned_qty,
                            GREATEST(0, COALESCE(isl_k.qty, s_kanban.total_scanned_qty) - COALESCE(s_kanban.excess_qty, 0))
                        ) AS used_qty,
                        COALESCE(
                            (
                                SELECT isl_k2.qty 
                                FROM inspection_session_lots isl_k2
                                WHERE isl_k2.inspection_session_id = s_kanban.id
                                  AND isl_k2.ref_number = isl_ss.ref_number
                                  AND isl_k2.ref_number IS NOT NULL
                                  AND isl_k2.ref_number != ''
                                  AND (isl_k2.lot_status IS NULL OR isl_k2.lot_status != 'replaced')
                                ORDER BY isl_k2.id ASC
                                LIMIT 1
                            ),
                            -- Priority 2: Fallback via remarks (path use_safety_stock_qty)
                            (
                                SELECT isl_k3.qty
                                FROM inspection_session_lots isl_k3
                                WHERE isl_k3.inspection_session_id = s_kanban.id
                                  AND isl_k3.remarks LIKE CONCAT('%Sesi #', s_ss.id, '%')
                                  AND (isl_k3.lot_status IS NULL OR isl_k3.lot_status != 'replaced')
                                ORDER BY isl_k3.id ASC
                                LIMIT 1
                            ),
                            -- Priority 3: Fallback ke kalkulasi lama (untuk data historis)
                            CASE 
                                WHEN s_kanban.excess_qty > 0 AND s_ss.total_scanned_qty >= (s_kanban.total_scanned_qty - s_kanban.excess_qty)
                                THEN GREATEST(0, s_kanban.total_scanned_qty - s_kanban.excess_qty)
                                ELSE s_ss.total_scanned_qty
                            END
                        ) AS label_qty_legacy,
                        COALESCE(isl_k.ref_number, isl_ss.ref_number, '-') AS ref_number,
                        COALESCE(isl_k.lot_number, isl_ss.lot_number, '-') AS lot_number,
                        ki.kanban_no,
                        ki.customer,
                        COALESCE(u.name, 'Inspector QC') AS inspector_name
                    FROM inspection_sessions s_ss
                    JOIN inspection_sessions s_kanban ON s_kanban.id = s_ss.auto_fulfilled_by_session_id
                    LEFT JOIN daily_inspection_data did ON did.id = s_ss.did_id
                    LEFT JOIN kanban_items ki_ss ON ki_ss.id = s_ss.kanban_item_id
                    LEFT JOIN kanban_items ki ON ki.id = s_kanban.kanban_item_id
                    LEFT JOIN users u ON u.id = s_kanban.inspector_id
                    LEFT JOIN inspection_session_lots isl_ss ON (
                        isl_ss.inspection_session_id = s_ss.id
                        AND isl_ss.id = (SELECT MIN(x.id) FROM inspection_session_lots x WHERE x.inspection_session_id = s_ss.id)
                    )
                    LEFT JOIN inspection_session_lots isl_k ON (
                        isl_k.inspection_session_id = s_kanban.id
                        AND isl_k.id = (
                            SELECT MIN(x2.id) FROM inspection_session_lots x2
                            WHERE x2.inspection_session_id = s_kanban.id
                              AND (
                                  x2.remarks LIKE CONCAT('%Sesi #', s_ss.id, '%')
                                  OR (isl_ss.ref_number IS NOT NULL AND isl_ss.ref_number != '' AND x2.ref_number = isl_ss.ref_number)
                              )
                        )
                    )
                    WHERE {$khWhereStr}
                    ORDER BY s_kanban.id DESC, s_ss.id ASC
                    LIMIT " . (int)$historyLimit . " OFFSET " . (int)$historyOffset
                );
                $stmtKanban->execute($khParams);
                $kanbanUsage = $stmtKanban->fetchAll(PDO::FETCH_ASSOC);

                // 6. Fetch Defect / NG Records (Table 3)
                $stmtDef = $pdo->prepare("
                    SELECT ng.*, dt.name AS defect_type_name, sm.sample_number, sm.inspection_session_id, ss.started_at, ss.closed_at,
                           (SELECT GROUP_CONCAT(DISTINCT isl.ref_number SEPARATOR ', ') 
                            FROM inspection_session_lots isl 
                            WHERE isl.inspection_session_id = ss.id) AS ref_number,
                           COALESCE(u.name, did.pic, '-') AS inspector_name
                    FROM inspection_ng_records ng
                    JOIN inspection_samples sm ON sm.id = ng.inspection_sample_id
                    JOIN inspection_sessions ss ON ss.id = sm.inspection_session_id
                    LEFT JOIN daily_inspection_data did ON ss.did_id = did.id
                    LEFT JOIN kanban_items ki_ss ON ki_ss.id = ss.kanban_item_id
                    LEFT JOIN users u ON ss.inspector_id = u.id
                    LEFT JOIN defect_types dt ON ng.defect_type_id = dt.id
                    WHERE ss.inspection_type = 'safety_stock'
                      AND (did.part_code = :p1 OR ki_ss.item_code = :p2)
                    ORDER BY ng.id ASC
                    LIMIT 50
                ");
                $stmtDef->execute([':p1' => $partCodeParam, ':p2' => $partCodeParam]);
                $defects = $stmtDef->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } catch (PDOException $e) {
        set_flash('error', 'Gagal memuat detail Safety Stock: ' . $e->getMessage());
    }
}

if (!$partInfo) {
    set_flash('error', 'Data Part Safety Stock tidak ditemukan.');
    redirect('modules/safety_stock/index.php');
}

// Compute Overall Badge
if ($availableStock > 0) {
    $overallBadge = '<span style="background-color: #dcfce7; color: #166534; border: 1px solid #bbf7d0; padding: 4px 12px; border-radius: 9999px; font-weight: 800; font-size: 11px;">PASSED</span>';
} elseif ($totalUsedQty > 0 && $availableStock === 0) {
    $overallBadge = '<span style="background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 4px 12px; border-radius: 9999px; font-weight: 800; font-size: 11px;">HABIS TERPAKAI</span>';
} elseif ($hasInProgress) {
    $overallBadge = '<span style="background-color: #fef3c7; color: #92400e; border: 1px solid #fde68a; padding: 4px 12px; border-radius: 9999px; font-weight: 800; font-size: 11px;">IN PROGRESS</span>';
} elseif ($hasRejected) {
    $overallBadge = '<span style="background-color: #fee2e2; color: #991b1b; border: 1px solid #fecaca; padding: 4px 12px; border-radius: 9999px; font-weight: 800; font-size: 11px;">DEFECT</span>';
} else {
    $overallBadge = '<span style="background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 4px 12px; border-radius: 9999px; font-weight: 800; font-size: 11px;">SELESAI</span>';
}

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<!-- Main Content Wrapper with Sidebar Offset (md:pl-64) -->
<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen min-w-0 w-full overflow-x-hidden transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-4 space-y-4 min-w-0 w-full overflow-x-hidden">
        
        <?= render_flash() ?>

        <!-- 1. Header Banner Card -->
        <div style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); border-radius: 12px; padding: 14px 18px; color: #ffffff; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <div style="display: flex; align-items: center; gap: 12px;">
                <a href="<?= base_url('modules/safety_stock/index.php') ?>" style="background-color: #334155; color: #ffffff; border-radius: 8px; padding: 7px 12px; font-size: 11px; font-weight: 700; border: none; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;" title="Kembali ke Monitoring Safety Stock">
                    <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                    </svg>
                    <span>Kembali</span>
                </a>
                <div>
                    <h2 style="font-size: 15px; font-weight: 800; margin: 0; line-height: 1.2; display: flex; align-items: center; gap: 8px;">
                        <span style="font-family: monospace; color: #60a5fa;"><?= htmlspecialchars($partInfo['item_code']) ?></span>
                        <span style="color: #64748b;">|</span>
                        <span style="color: #f1f5f9; font-weight: 600; font-size: 14px;"><?= htmlspecialchars($partInfo['item_description']) ?></span>
                    </h2>
                    <p style="font-size: 11px; color: #94a3b8; margin-top: 3px;">
                        Customer: <b style="color: #ffffff;"><?= htmlspecialchars($partInfo['customer']) ?></b>
                        | Lokasi WH: <b style="color: #cbd5e1;"><?= htmlspecialchars($partInfo['str_loc']) ?></b>
                    </p>
                </div>
            </div>

            <div>
                <?= $overallBadge ?>
            </div>
        </div>

        <!-- 2. KPI SUMMARY CARDS (4 Cards Grid) -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;">
            
            <div style="background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                <span style="font-size: 10px; font-weight: 800; color: #64748b; letter-spacing: 0.5px; text-transform: uppercase;">Total Stok Masuk</span>
                <div style="font-size: 20px; font-weight: 900; color: #0f172a; margin-top: 4px; font-family: monospace;">
                    <?= number_format($totalInitialStock) ?> <span style="font-size: 11px; font-weight: 700; color: #64748b;">pcs</span>
                </div>
            </div>

            <div style="background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                <span style="font-size: 10px; font-weight: 800; color: #64748b; letter-spacing: 0.5px; text-transform: uppercase;">Terpakai Kanban</span>
                <div style="font-size: 20px; font-weight: 900; color: #7c3aed; margin-top: 4px; font-family: monospace;">
                    <?= number_format($totalUsedQty) ?> <span style="font-size: 11px; font-weight: 700; color: #64748b;">pcs</span>
                </div>
            </div>

            <div style="background-color: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px; padding: 12px 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                <span style="font-size: 10px; font-weight: 800; color: #166534; letter-spacing: 0.5px; text-transform: uppercase;">Sisa Stok Gudang</span>
                <div style="font-size: 20px; font-weight: 900; color: #15803d; margin-top: 4px; font-family: monospace;">
                    <?= number_format($availableStock) ?> <span style="font-size: 11px; font-weight: 700; color: #166534;">pcs</span>
                </div>
            </div>

            <div style="background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                <span style="font-size: 10px; font-weight: 800; color: #64748b; letter-spacing: 0.5px; text-transform: uppercase;">Total Kemasan Box Fisik</span>
                <div style="font-size: 20px; font-weight: 900; color: #334155; margin-top: 4px; font-family: monospace;">
                    <?= number_format($totalActiveBoxes) ?> <span style="font-size: 11px; font-weight: 700; color: #64748b;">box aktif</span>
                </div>
            </div>

        </div>

        <!-- 3. TABEL 1: DAFTAR LABEL BOX & LOT FISIK DI GUDANG -->
        <div style="background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
            
            <div style="padding: 12px 16px; background-color: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <h3 style="font-size: 12px; font-weight: 800; color: #0f172a; text-transform: uppercase; letter-spacing: 0.5px; margin: 0; display: flex; align-items: center; gap: 6px;">
                        <svg style="width: 16px; height: 16px; color: #2563eb;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                        </svg>
                        <span>Daftar Label Box &amp; Lot Fisik di Gudang</span>
                    </h3>
                    <span style="padding: 2px 8px; background-color: #dbeafe; color: #1d4ed8; font-size: 11px; font-weight: 800; border-radius: 9999px;">
                        <?= number_format($totalFilteredLots) ?> Box
                    </span>
                </div>

                <?php if ($isAdmin): ?>
                    <span style="font-size: 11px; color: #64748b; font-weight: 500;">
                        Mode Admin: Penyesuaian stok fisik (Edit Qty / Hapus) aktif
                    </span>
                <?php endif; ?>
            </div>

            <!-- SERVER-SIDE SEARCH, QR SCAN & LOT STATUS FILTER TOOLBAR -->
            <form method="GET" action="<?= base_url('modules/safety_stock/detail.php') ?>" id="formFilterBox" style="padding: 10px 16px; background-color: #ffffff; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin: 0;">
                <input type="hidden" name="part_code" value="<?= htmlspecialchars($partInfo['item_code']) ?>">
                <input type="hidden" name="box_page" value="1">

                <div style="display: flex; align-items: center; gap: 8px; flex: 1; min-width: 240px; max-width: 420px; position: relative;">
                    <span style="position: absolute; left: 10px; color: #64748b; display: flex; align-items: center; pointer-events: none;">
                        <svg style="width: 15px; height: 15px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"></path>
                        </svg>
                    </span>
                    <input type="text" name="search_box" id="scanSearchBoxInput" 
                           value="<?= htmlspecialchars($boxSearch) ?>"
                           onkeydown="handleScanKeydown(event)"
                           placeholder="Scan Barcode QR (Z1...) atau Cari Ref / Lot..." 
                           style="width: 100%; padding: 7px 10px 7px 32px; background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 12px; font-family: monospace; font-weight: 600; color: #0f172a; outline: none;"
                           autocomplete="off">
                </div>

                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <!-- Filter Status Lot -->
                    <select name="lot_status" id="filterLotStatus" onchange="this.form.submit()" 
                            style="padding: 6px 10px; background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 12px; color: #334155; font-weight: 600; outline: none;">
                        <option value="">Semua Status Lot</option>
                        <option value="passed" <?= ($boxStatus === 'passed' || $boxStatus === 'available') ? 'selected' : '' ?>>PASSED</option>
                        <option value="in_progress" <?= ($boxStatus === 'in_progress') ? 'selected' : '' ?>>IN PROGRESS</option>
                        <option value="rejected" <?= ($boxStatus === 'rejected') ? 'selected' : '' ?>>REJECTED</option>
                    </select>

                    <!-- Filter Tanggal Masuk (Opsional) -->
                    <div style="display: inline-flex; align-items: center; gap: 4px; font-size: 11px; color: #64748b;">
                        <span>Tgl:</span>
                        <input type="date" name="box_start_date" value="<?= htmlspecialchars($boxStartDate) ?>" 
                               title="Dari Tanggal Masuk"
                               style="padding: 5px 8px; background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 11px; color: #334155; outline: none;">
                        <span>-</span>
                        <input type="date" name="box_end_date" value="<?= htmlspecialchars($boxEndDate) ?>" 
                               title="Sampai Tanggal Masuk"
                               style="padding: 5px 8px; background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 11px; color: #334155; outline: none;">
                    </div>

                    <!-- Limit Selector -->
                    <select name="box_limit" onchange="this.form.submit()" 
                            style="padding: 6px 8px; background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 12px; color: #334155; font-weight: 600; outline: none;" title="Jumlah data per halaman">
                        <option value="10" <?= ($boxLimit === 10) ? 'selected' : '' ?>>10 / hal</option>
                        <option value="25" <?= ($boxLimit === 25) ? 'selected' : '' ?>>25 / hal</option>
                        <option value="50" <?= ($boxLimit === 50) ? 'selected' : '' ?>>50 / hal</option>
                        <option value="100" <?= ($boxLimit === 100) ? 'selected' : '' ?>>100 / hal</option>
                    </select>

                    <button type="submit" 
                            style="padding: 6px 14px; background-color: #2563eb; color: #ffffff; font-size: 11px; font-weight: 700; border-radius: 8px; border: none; cursor: pointer;">
                        Cari
                    </button>

                    <?php if (!empty($boxSearch) || !empty($boxStatus) || !empty($boxStartDate) || !empty($boxEndDate) || $boxLimit !== 10): ?>
                        <a href="<?= base_url('modules/safety_stock/detail.php?part_code=' . urlencode($partInfo['item_code'])) ?>" 
                           style="padding: 6px 12px; background-color: #f1f5f9; color: #475569; font-size: 11px; font-weight: 700; border-radius: 8px; border: 1px solid #cbd5e1; text-decoration: none; display: inline-flex; align-items: center;">
                            Reset
                        </a>
                    <?php endif; ?>

                    <!-- Feedback Badge Scan / Search -->
                    <div id="scanFeedbackBadge" style="display: none; font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 6px; align-items: center; gap: 4px;"></div>
                </div>
            </form>

            <div style="overflow-x-auto;">
                <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 12px; color: #334155;">
                    <thead style="background-color: #ffffff; border-bottom: 1px solid #e2e8f0; font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase;">
                        <tr>
                            <th style="padding: 10px 14px; width: 40px; text-align: center;">No</th>
                            <th style="padding: 10px 14px; min-width: 130px;">Ref Number (Box)</th>
                            <th style="padding: 10px 14px; min-width: 120px;">Lot Number</th>
                            <th style="padding: 10px 14px; text-align: right; width: 110px;">Kuantiti Box</th>
                            <th style="padding: 10px 14px; min-width: 165px; white-space: nowrap;">Status Box</th>
                            <th style="padding: 10px 14px; min-width: 130px;">Waktu Masuk</th>
                            <th style="padding: 10px 14px; min-width: 130px;">Inspektur / PIC</th>
                            <?php if ($isAdmin): ?>
                                <th style="padding: 10px 14px; text-align: right; width: 130px;">Aksi Admin</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sessionLots)): ?>
                            <tr>
                                <td colspan="<?= $isAdmin ? 8 : 7 ?>" style="padding: 32px; text-align: center; color: #94a3b8;">
                                    Tidak ada data label box yang sesuai dengan kriteria pencarian / filter status.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($sessionLots as $idx => $lot): ?>
                                <?php 
                                    $sessStatus  = $lot['session_status'] ?? 'passed';
                                    $lotQty      = (int)$lot['qty'];

                                    $isReinspectionSess = !empty($lot['is_reinspection']) 
                                        || !empty($lot['reinspection_type']) 
                                        || (!empty($lot['parent_session_id']) && (int)$lot['parent_session_id'] > 0)
                                        || !empty($lot['is_reinspected_lot'])
                                        || (($lot['lot_status'] ?? '') === 'reinspected')
                                        || (isset($lot['remarks']) && (strripos($lot['remarks'], 're-inspeksi') !== false || strripos($lot['remarks'], 'reinspeksi') !== false))
                                        || (isset($lot['reinspection_notes']) && !empty($lot['reinspection_notes']));

                                    if ($sessStatus === 'in_progress') {
                                        $boxBadge = $isReinspectionSess
                                            ? '<div style="display: inline-flex; align-items: center; gap: 4px; white-space: nowrap;"><span style="background-color: #fef3c7; color: #92400e; border: 1px solid #fde68a; padding: 2px 8px; border-radius: 9999px; font-weight: 700; font-size: 10px;">IN PROGRESS</span><span style="background-color: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 1.5px 6px; border-radius: 4px; font-weight: 800; font-size: 9px;">RE-INSPEKSI</span></div>'
                                            : '<span style="background-color: #fef3c7; color: #92400e; border: 1px solid #fde68a; padding: 2px 8px; border-radius: 9999px; font-weight: 700; font-size: 10px; white-space: nowrap; display: inline-block;">IN PROGRESS</span>';
                                    } elseif ($sessStatus === 'rejected') {
                                        $boxBadge = $isReinspectionSess
                                            ? '<div style="display: inline-flex; align-items: center; gap: 4px; white-space: nowrap;"><span style="background-color: #fee2e2; color: #991b1b; border: 1px solid #fecaca; padding: 2px 8px; border-radius: 9999px; font-weight: 700; font-size: 10px;">REJECTED</span><span style="background-color: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 1.5px 6px; border-radius: 4px; font-weight: 800; font-size: 9px;">RE-INSPEKSI</span></div>'
                                            : '<span style="background-color: #fee2e2; color: #991b1b; border: 1px solid #fecaca; padding: 2px 8px; border-radius: 9999px; font-weight: 700; font-size: 10px; white-space: nowrap; display: inline-block;">REJECTED</span>';
                                    } else {
                                        $boxBadge = $isReinspectionSess
                                            ? '<div style="display: inline-flex; align-items: center; gap: 4px; white-space: nowrap;"><span style="background-color: #dcfce7; color: #166534; border: 1px solid #bbf7d0; padding: 2px 8px; border-radius: 9999px; font-weight: 700; font-size: 10px;">PASSED</span><span style="background-color: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 1.5px 6px; border-radius: 4px; font-weight: 800; font-size: 9px;">RE-INSPEKSI</span></div>'
                                            : '<span style="background-color: #dcfce7; color: #166534; border: 1px solid #bbf7d0; padding: 2px 8px; border-radius: 9999px; font-weight: 700; font-size: 10px; white-space: nowrap; display: inline-block;">PASSED</span>';
                                    }

                                    $dateStr = !empty($lot['scanned_at']) ? date('d M Y, H:i', strtotime($lot['scanned_at'])) : '-';
                                    $lotId   = (int)$lot['id'];
                                    $sessId  = (int)$lot['inspection_session_id'];
                                    $refNo   = $lot['ref_number'] ?: '-';
                                    $lotNum  = $lot['lot_number'] ?: '-';
                                    $rowNum  = ($boxPage - 1) * $boxLimit + $idx + 1;
                                ?>
                                <tr style="border-bottom: 1px solid #f1f5f9; transition: background-color 0.2s ease;">
                                    <td style="padding: 10px 14px; text-align: center; font-weight: 700; color: #64748b;"><?= $rowNum ?></td>
                                    
                                    <!-- Ref Number -->
                                    <td style="padding: 10px 14px;">
                                        <span style="font-family: monospace; font-weight: 800; color: #1d4ed8; background-color: #eff6ff; padding: 2px 8px; border-radius: 6px; border: 1px solid #dbeafe; font-size: 11px;">
                                            <?= htmlspecialchars($refNo) ?>
                                        </span>
                                    </td>

                                    <!-- Lot Number -->
                                    <td style="padding: 10px 14px;">
                                        <span style="font-family: monospace; font-weight: 700; color: #334155; background-color: #f8fafc; padding: 2px 8px; border-radius: 6px; border: 1px solid #e2e8f0; font-size: 11px;">
                                            <?= htmlspecialchars($lotNum) ?>
                                        </span>
                                    </td>

                                    <!-- Kuantiti Box -->
                                    <td style="padding: 10px 14px; text-align: right;">
                                        <span style="font-family: monospace; font-weight: 800; color: #0f172a; font-size: 13px;">
                                            <?= number_format($lotQty) ?>
                                        </span>
                                        <span style="font-size: 10px; color: #64748b;"> pcs</span>
                                    </td>

                                    <!-- Status Box -->
                                    <td style="padding: 10px 14px; white-space: nowrap;">
                                        <?= $boxBadge ?>
                                    </td>

                                    <!-- Waktu Masuk -->
                                    <td style="padding: 10px 14px; color: #64748b; font-size: 11px;">
                                        <?= $dateStr ?>
                                    </td>

                                    <!-- PIC / Inspektur -->
                                    <td style="padding: 10px 14px; font-weight: 600; color: #334155;">
                                        <?= htmlspecialchars($lot['inspector_name']) ?>
                                    </td>

                                    <!-- Aksi Admin -->
                                    <?php if ($isAdmin): ?>
                                        <td style="padding: 10px 14px; text-align: right; white-space: nowrap;">
                                            <div style="display: inline-flex; align-items: center; gap: 4px;">
                                                <button type="button" 
                                                        onclick="openEditModal(<?= $lotId ?>, <?= $sessId ?>, '<?= htmlspecialchars($refNo, ENT_QUOTES) ?>', <?= $lotQty ?>)" 
                                                        style="padding: 4px 8px; background-color: #eff6ff; color: #1d4ed8; font-weight: 700; font-size: 10px; border-radius: 6px; border: 1px solid #bfdbfe; cursor: pointer;" 
                                                        title="Edit Kuantiti Fisik">
                                                    Edit
                                                </button>
                                                <button type="button" 
                                                        onclick="openDeleteModal(<?= $lotId ?>, <?= $sessId ?>, '<?= htmlspecialchars($refNo, ENT_QUOTES) ?>', <?= $lotQty ?>)" 
                                                        style="padding: 4px 8px; background-color: #fee2e2; color: #991b1b; font-weight: 700; font-size: 10px; border-radius: 6px; border: 1px solid #fecaca; cursor: pointer;" 
                                                        title="Hapus Label dari Gudang">
                                                    Hapus
                                                </button>
                                            </div>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- SERVER-SIDE PAGINATION FOOTER FOR TABLE 1 -->
            <?php if ($totalBoxPages > 1): ?>
                <div style="padding: 12px 16px; border-top: 1px solid #e2e8f0; background-color: #f8fafc; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; font-size: 12px;">
                    <div style="color: #64748b;">
                        Menampilkan halaman <b style="color: #1e293b;"><?= $boxPage ?></b> dari <b style="color: #1e293b;"><?= $totalBoxPages ?></b> (Total <?= number_format($totalFilteredLots) ?> box)
                    </div>

                    <div style="display: flex; align-items: center; gap: 4px;">
                        <?php 
                            $qParams = $_GET; 
                            $prevBoxPage = max(1, $boxPage - 1);
                            $nextBoxPage = min($totalBoxPages, $boxPage + 1);
                            
                            $qParams['box_page'] = $prevBoxPage;
                            $prevUrl = base_url('modules/safety_stock/detail.php?') . http_build_query($qParams);
                            
                            $qParams['box_page'] = $nextBoxPage;
                            $nextUrl = base_url('modules/safety_stock/detail.php?') . http_build_query($qParams);
                        ?>

                        <a href="<?= $prevUrl ?>" style="padding: 5px 10px; background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; color: #334155; font-weight: 700; font-size: 11px; text-decoration: none; <?= ($boxPage <= 1) ? 'pointer-events-none opacity-50' : '' ?>">
                            &laquo; Prev
                        </a>

                        <?php for ($bp = 1; $bp <= $totalBoxPages; $bp++): ?>
                            <?php 
                                if ($bp > 1 && $bp < $totalBoxPages && abs($bp - $boxPage) > 2) continue;
                                $qParams['box_page'] = $bp;
                                $bpUrl = base_url('modules/safety_stock/detail.php?') . http_build_query($qParams);
                            ?>
                            <a href="<?= $bpUrl ?>" style="padding: 5px 10px; border-radius: 6px; font-weight: 800; font-size: 11px; text-decoration: none; <?= ($bp == $boxPage) ? 'background-color: #2563eb; color: #ffffff; border: 1px solid #2563eb;' : 'background-color: #ffffff; color: #334155; border: 1px solid #cbd5e1;' ?>">
                                <?= $bp ?>
                            </a>
                        <?php endfor; ?>

                        <a href="<?= $nextUrl ?>" style="padding: 5px 10px; background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; color: #334155; font-weight: 700; font-size: 11px; text-decoration: none; <?= ($boxPage >= $totalBoxPages) ? 'pointer-events-none opacity-50' : '' ?>">
                            Next &raquo;
                        </a>
                    </div>
                </div>
            <?php endif; ?>

        </div>

        <!-- 4. TABEL 2: RIWAYAT PEMAKAIAN MEMOTONG KANBAN -->
        <div style="background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
            
            <div style="padding: 12px 16px; background-color: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <h3 style="font-size: 12px; font-weight: 800; color: #0f172a; text-transform: uppercase; letter-spacing: 0.5px; margin: 0; display: flex; align-items: center; gap: 6px;">
                        <svg style="width: 16px; height: 16px; color: #7c3aed;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                        </svg>
                        <span>Riwayat Pemakaian Memotong Kanban</span>
                    </h3>
                    <span style="padding: 2px 8px; background-color: #f3e8ff; color: #7e22ce; font-size: 11px; font-weight: 800; border-radius: 9999px;">
                        <?= number_format($totalFilteredHistory) ?> Alokasi
                    </span>
                </div>
            </div>

            <!-- SERVER-SIDE FILTER & SCAN TOOLBAR UNTUK RIWAYAT KANBAN -->
            <form method="GET" action="<?= base_url('modules/safety_stock/detail.php') ?>" id="formFilterHistory" style="padding: 10px 16px; background-color: #ffffff; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin: 0;">
                <input type="hidden" name="part_code" value="<?= htmlspecialchars($partInfo['item_code']) ?>">
                <input type="hidden" name="box_page" value="<?= (int)$boxPage ?>">
                <input type="hidden" name="search_box" value="<?= htmlspecialchars($boxSearch) ?>">
                <input type="hidden" name="lot_status" value="<?= htmlspecialchars($boxStatus) ?>">
                <input type="hidden" name="hist_page" value="1">

                <div style="display: flex; align-items: center; gap: 8px; flex: 1; min-width: 240px; max-width: 400px; position: relative;">
                    <span style="position: absolute; left: 10px; color: #64748b; display: flex; align-items: center; pointer-events: none;">
                        <svg style="width: 15px; height: 15px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                    </span>
                    <input type="text" name="hist_search" id="scanHistorySearchInput"
                           value="<?= htmlspecialchars($historySearch) ?>"
                           onkeydown="handleHistoryScanKeydown(event)"
                           placeholder="Scan Barcode (Z1...) / Cari Ref, Lot, No Kanban..."
                           style="width: 100%; padding: 7px 10px 7px 32px; background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 12px; font-family: monospace; font-weight: 600; color: #0f172a; outline: none;"
                           autocomplete="off">
                </div>

                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <!-- Periode Cepat -->
                    <select name="hist_period" id="selectHistPeriod" onchange="handlePeriodChange(this)"
                            style="padding: 6px 10px; background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 12px; color: #334155; font-weight: 600; outline: none;">
                        <option value="today" <?= ($historyPeriod === 'today') ? 'selected' : '' ?>>Hari Ini</option>
                        <option value="7days" <?= ($historyPeriod === '7days') ? 'selected' : '' ?>>7 Hari Terakhir</option>
                        <option value="month" <?= ($historyPeriod === 'month') ? 'selected' : '' ?>>Bulan Ini</option>
                        <option value="custom" <?= ($historyPeriod === 'custom') ? 'selected' : '' ?>>Kustom Tanggal</option>
                        <option value="all" <?= ($historyPeriod === 'all') ? 'selected' : '' ?>>Semua Waktu</option>
                    </select>

                    <!-- Rentang Tanggal Manual -->
                    <div id="histDateRangeWrap" style="display: <?= ($historyPeriod === 'custom') ? 'inline-flex' : 'none' ?>; align-items: center; gap: 4px; font-size: 11px; color: #64748b;">
                        <span>Tgl:</span>
                        <input type="date" name="hist_start_date" id="histStartDateInput" value="<?= htmlspecialchars($historyStartDate) ?>"
                               style="padding: 5px 8px; background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 11px; color: #334155; outline: none;">
                        <span>-</span>
                        <input type="date" name="hist_end_date" id="histEndDateInput" value="<?= htmlspecialchars($historyEndDate) ?>"
                               style="padding: 5px 8px; background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 11px; color: #334155; outline: none;">
                    </div>

                    <!-- Limit Selector -->
                    <select name="hist_limit" onchange="this.form.submit()"
                            style="padding: 6px 8px; background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 12px; color: #334155; font-weight: 600; outline: none;">
                        <option value="5" <?= ($historyLimit === 5) ? 'selected' : '' ?>>5 / hal</option>
                        <option value="10" <?= ($historyLimit === 10) ? 'selected' : '' ?>>10 / hal</option>
                        <option value="25" <?= ($historyLimit === 25) ? 'selected' : '' ?>>25 / hal</option>
                        <option value="50" <?= ($historyLimit === 50) ? 'selected' : '' ?>>50 / hal</option>
                    </select>

                    <button type="submit"
                            style="padding: 6px 14px; background-color: #7c3aed; color: #ffffff; font-size: 11px; font-weight: 700; border-radius: 8px; border: none; cursor: pointer;">
                        Cari
                    </button>

                    <?php if (!empty($historySearch) || $historyPeriod !== 'today'): ?>
                        <a href="<?= base_url('modules/safety_stock/detail.php?part_code=' . urlencode($partInfo['item_code']) . '&hist_period=today') ?>"
                           style="padding: 6px 12px; background-color: #f1f5f9; color: #475569; font-size: 11px; font-weight: 700; border-radius: 8px; border: 1px solid #cbd5e1; text-decoration: none; display: inline-flex; align-items: center;">
                            Reset
                        </a>
                    <?php endif; ?>
                </div>
            </form>

            <div style="overflow-x-auto;">
                <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 12px; color: #334155;">
                    <thead style="background-color: #ffffff; border-bottom: 1px solid #e2e8f0; font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase;">
                        <tr>
                            <th style="padding: 10px 14px; width: 40px; text-align: center;">No</th>
                            <th style="padding: 10px 14px; min-width: 140px;">Target No. Kanban</th>
                            <th style="padding: 10px 14px; min-width: 150px;">Customer Tujuan</th>
                            <th style="padding: 10px 14px; min-width: 120px;">Ref / Lot Digunakan</th>
                            <th style="padding: 10px 14px; text-align: right; width: 110px;">Qty Terpakai</th>
                            <th style="padding: 10px 14px; min-width: 150px;">Waktu Pemakaian</th>
                            <th style="padding: 10px 14px; min-width: 130px;">Inspektur QC</th>
                            <th style="padding: 10px 14px; width: 100px;">Status</th>
                            <th style="padding: 10px 14px; text-align: right; width: 90px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($kanbanUsage)): ?>
                            <tr>
                                <td colspan="9" style="padding: 28px; text-align: center; color: #94a3b8;">
                                    Tidak ada data pemakaian pada periode ini.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($kanbanUsage as $kIdx => $ku): ?>
                                <?php 
                                    $kSessStatus = $ku['session_status'] ?? 'passed';
                                    if ($kSessStatus === 'passed') {
                                        $kBadge = '<span style="background-color: #dcfce7; color: #166534; border: 1px solid #bbf7d0; padding: 2px 8px; border-radius: 9999px; font-weight: 700; font-size: 10px;">PASSED</span>';
                                    } elseif ($kSessStatus === 'rejected') {
                                        $kBadge = '<span style="background-color: #fee2e2; color: #991b1b; border: 1px solid #fecaca; padding: 2px 8px; border-radius: 9999px; font-weight: 700; font-size: 10px;">REJECTED</span>';
                                    } else {
                                        $kBadge = '<span style="background-color: #fef3c7; color: #92400e; border: 1px solid #fde68a; padding: 2px 8px; border-radius: 9999px; font-weight: 700; font-size: 10px;">IN PROGRESS</span>';
                                    }

                                    $kStart = !empty($ku['used_at']) ? date('d M Y, H:i', strtotime($ku['used_at'])) : '-';
                                    $kEnd   = !empty($ku['closed_at']) ? date('H:i', strtotime($ku['closed_at'])) : 'In Progress';
                                    $hRowNum = ($historyPage - 1) * $historyLimit + $kIdx + 1;
                                ?>
                                <tr style="border-bottom: 1px solid #f1f5f9;">
                                    <td style="padding: 10px 14px; text-align: center; font-weight: 700; color: #64748b;"><?= $hRowNum ?></td>
                                    
                                    <!-- No Kanban -->
                                    <td style="padding: 10px 14px; font-weight: 800; color: #0f172a; font-family: monospace;">
                                        <?= htmlspecialchars($ku['kanban_no'] ?: 'KANBAN-DIRECT') ?>
                                    </td>

                                    <!-- Customer -->
                                    <td style="padding: 10px 14px; font-weight: 600; color: #334155;">
                                        <?= htmlspecialchars($ku['customer'] ?: 'CUSTOMER') ?>
                                    </td>

                                    <!-- Ref / Lot -->
                                    <td style="padding: 10px 14px;">
                                        <span style="font-family: monospace; font-weight: 700; color: #1d4ed8; font-size: 11px;">
                                            <?= htmlspecialchars($ku['ref_number']) ?>
                                        </span>
                                    </td>

                                    <!-- Qty Terpakai -->
                                    <td style="padding: 10px 14px; text-align: right;">
                                        <span style="font-family: monospace; font-weight: 800; color: #7c3aed; font-size: 13px;">
                                            <?= number_format((int)$ku['used_qty']) ?>
                                        </span>
                                        <span style="font-size: 10px; color: #64748b;"> pcs</span>
                                    </td>

                                    <!-- Waktu Pemakaian -->
                                    <td style="padding: 10px 14px; color: #475569; font-size: 11px;">
                                        <span><?= $kStart ?></span>
                                        <span style="color: #94a3b8; margin: 0 2px;">s/d</span>
                                        <b style="color: #0f172a;"><?= $kEnd ?></b>
                                    </td>

                                    <!-- Inspektur QC -->
                                    <td style="padding: 10px 14px; font-weight: 600; color: #334155;">
                                        <?= htmlspecialchars($ku['inspector_name']) ?>
                                    </td>

                                    <!-- Status -->
                                    <td style="padding: 10px 14px;">
                                        <?= $kBadge ?>
                                    </td>

                                    <!-- Aksi Link Sesi Kanban -->
                                    <td style="padding: 10px 14px; text-align: right; white-space: nowrap;">
                                        <a href="<?= base_url('modules/inspection/session.php?id=' . $ku['kanban_session_id']) ?>" 
                                           style="padding: 4px 10px; background-color: #2563eb; color: #ffffff; font-weight: 700; font-size: 10px; border-radius: 6px; text-decoration: none; display: inline-flex; align-items: center; gap: 3px;" 
                                           title="Buka Sesi Inspeksi Kanban">
                                            <span>Lihat Sesi</span>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- SERVER-SIDE PAGINATION FOOTER FOR TABLE 2 -->
            <?php if ($totalHistoryPages > 1): ?>
                <div style="padding: 12px 16px; border-top: 1px solid #e2e8f0; background-color: #f8fafc; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; font-size: 12px;">
                    <div style="color: #64748b;">
                        Halaman <b style="color: #1e293b;"><?= $historyPage ?></b> dari <b style="color: #1e293b;"><?= $totalHistoryPages ?></b> (Total <?= number_format($totalFilteredHistory) ?> data)
                    </div>

                    <div style="display: flex; align-items: center; gap: 4px;">
                        <?php 
                            $hParams = $_GET; 
                            $prevHistPage = max(1, $historyPage - 1);
                            $nextHistPage = min($totalHistoryPages, $historyPage + 1);
                            
                            $hParams['hist_page'] = $prevHistPage;
                            $prevHistUrl = base_url('modules/safety_stock/detail.php?') . http_build_query($hParams);
                            
                            $hParams['hist_page'] = $nextHistPage;
                            $nextHistUrl = base_url('modules/safety_stock/detail.php?') . http_build_query($hParams);
                        ?>

                        <a href="<?= $prevHistUrl ?>" style="padding: 5px 10px; background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; color: #334155; font-weight: 700; font-size: 11px; text-decoration: none; <?= ($historyPage <= 1) ? 'pointer-events-none opacity-50' : '' ?>">
                            &laquo; Prev
                        </a>

                        <?php for ($hp = 1; $hp <= $totalHistoryPages; $hp++): ?>
                            <?php 
                                if ($hp > 1 && $hp < $totalHistoryPages && abs($hp - $historyPage) > 2) continue;
                                $hParams['hist_page'] = $hp;
                                $hpUrl = base_url('modules/safety_stock/detail.php?') . http_build_query($hParams);
                            ?>
                            <a href="<?= $hpUrl ?>" style="padding: 5px 10px; border-radius: 6px; font-weight: 800; font-size: 11px; text-decoration: none; <?= ($hp == $historyPage) ? 'background-color: #7c3aed; color: #ffffff; border: 1px solid #7c3aed;' : 'background-color: #ffffff; color: #334155; border: 1px solid #cbd5e1;' ?>">
                                <?= $hp ?>
                            </a>
                        <?php endfor; ?>

                        <a href="<?= $nextHistUrl ?>" style="padding: 5px 10px; background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; color: #334155; font-weight: 700; font-size: 11px; text-decoration: none; <?= ($historyPage >= $totalHistoryPages) ? 'pointer-events-none opacity-50' : '' ?>">
                            Next &raquo;
                        </a>
                    </div>
                </div>
            <?php endif; ?>

        </div>

        <!-- 5. TABEL 3: CATATAN DEFECT / NG (Jika Ada) -->
        <?php if (!empty($defects)): ?>
            <div style="background-color: #ffffff; border: 1px solid #fecaca; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                <div style="padding: 12px 16px; background-color: #fff1f2; border-bottom: 1px solid #fecaca; display: flex; align-items: center; justify-content: space-between;">
                    <h3 style="font-size: 12px; font-weight: 800; color: #991b1b; text-transform: uppercase; letter-spacing: 0.5px; margin: 0;">
                        Catatan Temuan Defect / NG
                    </h3>
                    <span style="font-size: 11px; color: #991b1b; font-weight: 700;"><?= count($defects) ?> Catatan</span>
                </div>

                <div style="overflow-x-auto;">
                    <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 12px; color: #334155;">
                        <thead style="background-color: #ffffff; border-bottom: 1px solid #fecaca; font-size: 10px; font-weight: 800; color: #991b1b; text-transform: uppercase;">
                            <tr>
                                <th style="padding: 10px 14px; width: 40px; text-align: center;">No</th>
                                <th style="padding: 10px 14px; width: 140px;">Ref Number</th>
                                <th style="padding: 10px 14px; min-width: 150px;">Jenis Defect</th>
                                <th style="padding: 10px 14px; min-width: 130px;">Inspektur</th>
                                <th style="padding: 10px 14px; text-align: right; width: 100px;">Qty NG</th>
                                <th style="padding: 10px 14px;">Catatan Inspektur</th>
                                <th style="padding: 10px 14px; width: 130px;">Waktu Temuan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($defects as $dIdx => $df): ?>
                                <tr style="border-bottom: 1px solid #fee2e2;">
                                    <td style="padding: 10px 14px; text-align: center; font-weight: 700; color: #991b1b;"><?= $dIdx + 1 ?></td>
                                    <td style="padding: 10px 14px;">
                                        <span style="font-family: monospace; font-weight: 800; color: #991b1b; background-color: #fee2e2; padding: 2px 8px; border-radius: 6px; font-size: 11px;">
                                            <?= htmlspecialchars($df['ref_number'] ?: '-') ?>
                                        </span>
                                    </td>
                                    <td style="padding: 10px 14px; font-weight: 800; color: #991b1b;">
                                        <?= htmlspecialchars($df['defect_type_name'] ?? 'Defect') ?>
                                        <span style="font-size: 10px; color: #64748b; font-weight: 500; display: block;">Sampel #<?= (int)$df['sample_number'] ?></span>
                                    </td>
                                    <td style="padding: 10px 14px; font-weight: 600; color: #334155;">
                                        <?= htmlspecialchars($df['inspector_name'] ?: '-') ?>
                                    </td>
                                    <td style="padding: 10px 14px; text-align: right; font-family: monospace; font-weight: 800; color: #991b1b;">
                                        <?= (int)($df['qty_ng'] ?? 1) ?> pcs
                                    </td>
                                    <td style="padding: 10px 14px; color: #475569;">
                                        <?= htmlspecialchars($df['remark'] ?: '-') ?>
                                    </td>
                                    <td style="padding: 10px 14px; color: #64748b; font-size: 11px;">
                                        <?= date('d M Y, H:i', strtotime($df['started_at'])) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

    </main>

    <!-- MODAL EDIT QTY (KHUSUS ADMIN) -->
    <?php if ($isAdmin): ?>
    <div id="modalEditQty" style="display: none; position: fixed; inset: 0; z-index: 50; background-color: rgba(15, 23, 42, 0.6); align-items: center; justify-content: center; padding: 16px;">
        <div style="background-color: #ffffff; border-radius: 12px; max-width: 420px; width: 100%; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); overflow: hidden; border: 1px solid #e2e8f0;">
            <div style="padding: 14px 18px; background-color: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between;">
                <h4 style="font-size: 13px; font-weight: 800; color: #0f172a; margin: 0;">Edit Kuantiti Fisik Box</h4>
                <button type="button" onclick="closeEditModal()" style="color: #94a3b8; border: none; background: none; font-size: 18px; cursor: pointer; line-height: 1;">&times;</button>
            </div>
            
            <form action="<?= base_url('modules/safety_stock/action.php') ?>" method="POST" style="padding: 18px;">
                <input type="hidden" name="action" value="update_qty">
                <input type="hidden" name="lot_id" id="editLotId" value="">
                <input type="hidden" name="session_id" id="editSessionId" value="">
                <input type="hidden" name="part_code" value="<?= htmlspecialchars($partInfo['item_code']) ?>">

                <div style="margin-bottom: 14px;">
                    <label style="display: block; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 4px;">Ref Number / Label</label>
                    <div id="editRefText" style="font-family: monospace; font-weight: 800; color: #1d4ed8; font-size: 13px;">-</div>
                </div>

                <div style="margin-bottom: 18px;">
                    <label style="display: block; font-size: 11px; font-weight: 700; color: #334155; margin-bottom: 6px;">Kuantiti Fisik Riil (pcs):</label>
                    <input type="number" name="new_qty" id="editQtyInput" min="1" required style="width: 100%; padding: 8px 12px; background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; font-family: monospace; font-weight: 700; color: #0f172a; outline: none;">
                    <p style="font-size: 11px; color: #64748b; margin-top: 4px;">Masukkan jumlah pcs aktual yang ada di rak/gudang.</p>
                </div>

                <div style="display: flex; align-items: center; justify-content: flex-end; gap: 8px;">
                    <button type="button" onclick="closeEditModal()" style="padding: 7px 14px; background-color: #f1f5f9; color: #475569; font-weight: 700; font-size: 12px; border-radius: 8px; border: 1px solid #cbd5e1; cursor: pointer;">
                        Batal
                    </button>
                    <button type="submit" style="padding: 7px 16px; background-color: #2563eb; color: #ffffff; font-weight: 700; font-size: 12px; border-radius: 8px; border: none; cursor: pointer;">
                        Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL HAPUS LABEL (KHUSUS ADMIN) -->
    <div id="modalDeleteLot" style="display: none; position: fixed; inset: 0; z-index: 50; background-color: rgba(15, 23, 42, 0.6); align-items: center; justify-content: center; padding: 16px;">
        <div style="background-color: #ffffff; border-radius: 12px; max-width: 420px; width: 100%; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); overflow: hidden; border: 1px solid #e2e8f0;">
            <div style="padding: 14px 18px; background-color: #fff1f2; border-bottom: 1px solid #fecaca; display: flex; align-items: center; justify-content: space-between;">
                <h4 style="font-size: 13px; font-weight: 800; color: #991b1b; margin: 0;">Konfirmasi Hapus Label Box</h4>
                <button type="button" onclick="closeDeleteModal()" style="color: #94a3b8; border: none; background: none; font-size: 18px; cursor: pointer; line-height: 1;">&times;</button>
            </div>
            
            <form action="<?= base_url('modules/safety_stock/action.php') ?>" method="POST" style="padding: 18px;">
                <input type="hidden" name="action" value="delete_lot">
                <input type="hidden" name="lot_id" id="deleteLotId" value="">
                <input type="hidden" name="session_id" id="deleteSessionId" value="">
                <input type="hidden" name="part_code" value="<?= htmlspecialchars($partInfo['item_code']) ?>">

                <p style="font-size: 12px; color: #334155; line-height: 1.5; margin: 0 0 14px 0;">
                    Apakah Anda yakin ingin menghapus label box <b id="deleteRefText" style="color: #1d4ed8; font-family: monospace;">-</b> (<span id="deleteQtyText" style="font-weight: 700;">-</span> pcs) dari stok gudang?
                </p>
                <div style="padding: 8px 12px; background-color: #fef2f2; border-radius: 8px; border: 1px solid #fecaca; font-size: 11px; color: #991b1b; margin-bottom: 16px;">
                    Perhatian: Data label ini akan dihapus dari stok gudang agar tidak terjadi selisih stok fisik vs sistem.
                </div>

                <div style="display: flex; align-items: center; justify-content: flex-end; gap: 8px;">
                    <button type="button" onclick="closeDeleteModal()" style="padding: 7px 14px; background-color: #f1f5f9; color: #475569; font-weight: 700; font-size: 12px; border-radius: 8px; border: 1px solid #cbd5e1; cursor: pointer;">
                        Batal
                    </button>
                    <button type="submit" style="padding: 7px 16px; background-color: #dc2626; color: #ffffff; font-weight: 700; font-size: 12px; border-radius: 8px; border: none; cursor: pointer;">
                        Ya, Hapus
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openEditModal(lotId, sessionId, refNo, qty) {
        document.getElementById('editLotId').value = lotId;
        document.getElementById('editSessionId').value = sessionId;
        document.getElementById('editRefText').textContent = refNo;
        document.getElementById('editQtyInput').value = qty;
        var modal = document.getElementById('modalEditQty');
        modal.style.display = 'flex';
    }

    function closeEditModal() {
        document.getElementById('modalEditQty').style.display = 'none';
    }

    function openDeleteModal(lotId, sessionId, refNo, qty) {
        document.getElementById('deleteLotId').value = lotId;
        document.getElementById('deleteSessionId').value = sessionId;
        document.getElementById('deleteRefText').textContent = refNo;
        document.getElementById('deleteQtyText').textContent = qty;
        var modal = document.getElementById('modalDeleteLot');
        modal.style.display = 'flex';
    }

    function closeDeleteModal() {
        document.getElementById('modalDeleteLot').style.display = 'none';
    }

    // Close modal on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeEditModal();
            closeDeleteModal();
        }
    });
    </script>
    <?php endif; ?>

    <script>
    /**
     * QR Code Barcode Format Parser (formatqr.md)
     * Format: Z1partCode|Z2lotNumber|Z3qty|Z4line|Z5refNumber
     */
    function parseBarcodeQR(code) {
        var obj = {};
        if (!code) return obj;
        var parts = code.split('|');
        parts.forEach(function(p) {
            p = p.trim();
            if (p.startsWith('Z1')) obj.Z1 = p.substring(2);
            if (p.startsWith('Z2')) obj.Z2 = p.substring(2);
            if (p.startsWith('Z3')) {
                var valZ3 = parseInt(p.substring(2), 10);
                if (!isNaN(valZ3) && valZ3 > 0) obj.Z3 = valZ3;
            }
            if (p.startsWith('Z4')) obj.Z4 = p.substring(2);
            if (p.startsWith('Z5')) obj.Z5 = p.substring(2);
        });
        return obj;
    }

    function handleScanKeydown(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            var inputEl = document.getElementById('scanSearchBoxInput');
            if (!inputEl) return;
            var val = inputEl.value.trim();
            var currentPartCode = '<?= htmlspecialchars($partInfo['item_code']) ?>'.toUpperCase();
            var feedbackBadge = document.getElementById('scanFeedbackBadge');

            if (val.includes('|') || val.startsWith('Z1') || val.startsWith('Z2') || val.startsWith('Z5')) {
                var qr = parseBarcodeQR(val);
                if (qr.Z1 && qr.Z1.toUpperCase() !== currentPartCode) {
                    if (feedbackBadge) {
                        feedbackBadge.style.display = 'inline-flex';
                        feedbackBadge.style.backgroundColor = '#fee2e2';
                        feedbackBadge.style.color = '#991b1b';
                        feedbackBadge.style.border = '1px solid #fecaca';
                        feedbackBadge.textContent = 'Perhatian: QR untuk Part ' + qr.Z1 + ', beda dengan part ini';
                    }
                    return;
                }
                // Pre-fill with Z5 (Ref No) or Z2 (Lot No)
                if (qr.Z5) {
                    inputEl.value = qr.Z5;
                } else if (qr.Z2) {
                    inputEl.value = qr.Z2;
                }
            }

            document.getElementById('formFilterBox').submit();
        }
    }

    function handlePeriodChange(selectEl) {
        var wrap = document.getElementById('histDateRangeWrap');
        if (selectEl.value === 'custom') {
            if (wrap) wrap.style.display = 'inline-flex';
        } else {
            if (wrap) wrap.style.display = 'none';
            document.getElementById('formFilterHistory').submit();
        }
    }

    function handleHistoryScanKeydown(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            var inputEl = document.getElementById('scanHistorySearchInput');
            if (!inputEl) return;
            var val = inputEl.value.trim();

            if (val.includes('|') || val.startsWith('Z1') || val.startsWith('Z2') || val.startsWith('Z5')) {
                var qr = parseBarcodeQR(val);
                if (qr.Z5) {
                    inputEl.value = qr.Z5;
                } else if (qr.Z2) {
                    inputEl.value = qr.Z2;
                }
            }

            document.getElementById('formFilterHistory').submit();
        }
    }
    </script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
