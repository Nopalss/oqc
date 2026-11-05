<?php
$breadcrumbCategory = "DATA REFERENSI";
$pageTitle = "Daftar Planning Kanban";
$pageSubtitle = "Ringkasan jadwal pengiriman Kanban per tanggal";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

require_menu_access('kanban');

$pdo = getDB();
$days = [];

// Filter Parameters
$period           = sanitize($_GET['period'] ?? 'today');
$selectedCustomer = sanitize($_GET['customer'] ?? '');
$selectedMonth    = (int)($_GET['month'] ?? 0);
$selectedYear     = (int)($_GET['year'] ?? 0);
$startDate        = sanitize($_GET['start_date'] ?? '');
$endDate          = sanitize($_GET['end_date'] ?? '');

// Dynamic Limit
$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT) ?: ($period === 'today' ? 50 : 10);
if (!in_array($limit, [5, 10, 25, 50, 100, 200, 500])) {
    $limit = ($period === 'today' ? 50 : 10);
}

$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
if ($page < 1) $page = 1;
$offset     = ($page - 1) * $limit;
$totalItems = 0;
$totalPages = 1;

// Specific parameter for 'today' mode: batch selection filter ('all' or specific batch id)
$selectedBatch = sanitize($_GET['batch_id'] ?? 'all');
$viewMode      = sanitize($_GET['view'] ?? '');
$todayBatches  = [];
$todayItems    = [];
$safetyStockRecommendations = [];
$partSafetyStockMap         = [];
$pagedRecommendations      = [];
$totalSsItems               = 0;
$totalSsPages               = 1;

// Fetch Customers List for Filter Dropdown
$customersList = [];
$availableYears = [];

if ($pdo) {
    try {
        $stmtCust = $pdo->query("SELECT DISTINCT name FROM master_customers WHERE name IS NOT NULL AND name != '' ORDER BY name ASC");
        $customersList = $stmtCust->fetchAll(PDO::FETCH_COLUMN);
        if (empty($customersList)) {
            $stmtCust2 = $pdo->query("SELECT DISTINCT customer FROM kanban_items WHERE customer IS NOT NULL AND customer != '' ORDER BY customer ASC");
            $customersList = $stmtCust2->fetchAll(PDO::FETCH_COLUMN);
        }
    } catch (Exception $eCust) {}

    try {
        $stmtY = $pdo->query("SELECT DISTINCT YEAR(imported_at) as yr FROM kanban_batches WHERE imported_at IS NOT NULL ORDER BY yr DESC");
        $availableYears = array_filter($stmtY->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $eY) {}
    if (empty($availableYears)) {
        $availableYears = [(int)date('Y')];
    }
}

// KPI Statistics Data Initialization
$kpiStats = [
    'total_batches'    => 0,
    'total_items'      => 0,
    'total_pcs'        => 0,
    'completed_items'  => 0,
    'pending_items'    => 0,
    'completed_pcs'    => 0,
    'completion_rate'  => 0,
    'pending_rate'     => 0
];

if ($pdo) {
    try {
        // Build WHERE conditions (Strictly Kanban batches & items)
        $whereClauses = ["b.plan_type = 'kanban'"];
        $queryParams  = [];

        // Customer Filter
        if (!empty($selectedCustomer)) {
            $whereClauses[] = "ki.customer = :customer";
            $queryParams[':customer'] = $selectedCustomer;
        }

        // Expression for Planning Date: Murni menggunakan Tanggal Input/Import batch
        $dateExpr = "DATE(b.imported_at)";

        // Quick Period Preset & Custom Date Range Logic
        if ($period === 'today') {
            $whereClauses[] = "{$dateExpr} = CURDATE()";
        } elseif ($period === 'this_week') {
            $whereClauses[] = "YEARWEEK({$dateExpr}, 1) = YEARWEEK(CURDATE(), 1)";
        } elseif ($period === 'this_month') {
            $whereClauses[] = "YEAR({$dateExpr}) = YEAR(CURDATE()) AND MONTH({$dateExpr}) = MONTH(CURDATE())";
        } elseif ($period === 'this_year') {
            $whereClauses[] = "YEAR({$dateExpr}) = YEAR(CURDATE())";
        } else {
            if ($selectedMonth > 0) {
                $whereClauses[] = "MONTH({$dateExpr}) = :month";
                $queryParams[':month'] = $selectedMonth;
            }
            if ($selectedYear > 0) {
                $whereClauses[] = "YEAR({$dateExpr}) = :year";
                $queryParams[':year'] = $selectedYear;
            }
            if (!empty($startDate)) {
                $whereClauses[] = "{$dateExpr} >= :start_date";
                $queryParams[':start_date'] = $startDate;
            }
            if (!empty($endDate)) {
                $whereClauses[] = "{$dateExpr} <= :end_date";
                $queryParams[':end_date'] = $endDate;
            }
        }

        $whereStr = implode(' AND ', $whereClauses);

        // 1. Fetch Aggregated KPI Summary
        $sqlKpi = "SELECT
                        COUNT(DISTINCT b.id) AS total_batches,
                        COUNT(ki.id) AS total_items,
                        COALESCE(SUM(ki.qty), 0) AS total_pcs,
                        COUNT(CASE WHEN ki.status = 'completed' THEN 1 END) AS completed_items,
                        COUNT(CASE WHEN ki.status != 'completed' OR ki.status IS NULL THEN 1 END) AS pending_items,
                        COALESCE(SUM(CASE WHEN ki.status = 'completed' THEN ki.qty ELSE 0 END), 0) AS completed_pcs
                   FROM kanban_batches b
                   LEFT JOIN kanban_items ki ON (ki.batch_id = b.id AND (ki.plan_type IS NULL OR ki.plan_type = 'kanban') AND (ki.check_type IS NULL OR ki.check_type != 'Safety Stock') AND (ki.kanban_no IS NULL OR ki.kanban_no NOT LIKE 'SS-%'))
                   WHERE {$whereStr}";
        $stmtKpi = $pdo->prepare($sqlKpi);
        $stmtKpi->execute($queryParams);
        $resKpi = $stmtKpi->fetch(PDO::FETCH_ASSOC);

        if ($resKpi) {
            $kpiStats['total_batches']    = (int)$resKpi['total_batches'];
            $kpiStats['total_items']      = (int)$resKpi['total_items'];
            $kpiStats['total_pcs']        = (int)$resKpi['total_pcs'];
            $kpiStats['completed_items']  = (int)$resKpi['completed_items'];
            $kpiStats['pending_items']    = (int)$resKpi['pending_items'];
            $kpiStats['completed_pcs']    = (int)$resKpi['completed_pcs'];

            if ($kpiStats['total_items'] > 0) {
                $kpiStats['completion_rate'] = round(($kpiStats['completed_items'] / $kpiStats['total_items']) * 100, 1);
                $kpiStats['pending_rate']    = round(($kpiStats['pending_items'] / $kpiStats['total_items']) * 100, 1);
            } else {
                $kpiStats['completion_rate'] = 0;
                $kpiStats['pending_rate']    = 0;
            }
        }

        if ($period === 'today') {
            // =====================================================================
            // MODE HARI INI: BREAKDOWN BATCH & DAFTAR ITEM KANBAN LANGSUNG
            // =====================================================================
            
            // 2A. Ambil seluruh batch yang di-import hari ini
            $stmtTB = $pdo->prepare("
                SELECT b.id, b.document_number, b.vendor, b.imported_at, b.import_method,
                       COUNT(k.id) as item_count,
                       COALESCE(SUM(k.qty), 0) as total_pcs,
                       SUM(CASE WHEN k.status = 'completed' THEN 1 ELSE 0 END) as completed_count,
                       SUM(CASE WHEN k.status IN ('in_progress', 'partial', 'rejected') THEN 1 ELSE 0 END) as in_progress_count
                FROM kanban_batches b
                JOIN kanban_items k ON k.batch_id = b.id
                WHERE b.plan_type = 'kanban'
                  AND DATE(b.imported_at) = CURDATE()
                  AND (k.plan_type IS NULL OR k.plan_type = 'kanban')
                  AND (k.check_type IS NULL OR k.check_type != 'Safety Stock')
                  AND (k.kanban_no IS NULL OR k.kanban_no NOT LIKE 'SS-%')
                GROUP BY b.id, b.document_number, b.vendor, b.imported_at, b.import_method
                ORDER BY b.imported_at ASC, b.id ASC
            ");
            $stmtTB->execute();
            $todayBatches = $stmtTB->fetchAll(PDO::FETCH_ASSOC);

            // Validasi $selectedBatch: jika batch_id yang dipilih bukan 'all' dan tidak ada di $todayBatches, default ke 'all'
            if ($selectedBatch !== 'all') {
                $batchExists = false;
                foreach ($todayBatches as $tb) {
                    if ((string)$tb['id'] === (string)$selectedBatch) {
                        $batchExists = true;
                        break;
                    }
                }
                if (!$batchExists && !empty($todayBatches)) {
                    $selectedBatch = 'all';
                }
            }

            // 2B. Build query filter untuk items hari ini
            $itemWhere = [
                "b.plan_type = 'kanban'",
                "DATE(b.imported_at) = CURDATE()",
                "(ki.plan_type IS NULL OR ki.plan_type = 'kanban')",
                "(ki.check_type IS NULL OR ki.check_type != 'Safety Stock')",
                "(ki.kanban_no IS NULL OR ki.kanban_no NOT LIKE 'SS-%')"
            ];
            $itemParams = [];

            if (!empty($selectedCustomer)) {
                $itemWhere[] = "ki.customer = :cust_today";
                $itemParams[':cust_today'] = $selectedCustomer;
            }

            if ($selectedBatch !== 'all') {
                $itemWhere[] = "ki.batch_id = :bid_today";
                $itemParams[':bid_today'] = (int)$selectedBatch;
            }

            $itemWhereStr = implode(' AND ', $itemWhere);

            // Count total items for pagination
            $stmtItemCount = $pdo->prepare("
                SELECT COUNT(ki.id)
                FROM kanban_items ki
                JOIN kanban_batches b ON b.id = ki.batch_id
                WHERE {$itemWhereStr}
            ");
            $stmtItemCount->execute($itemParams);
            $totalItems = (int)$stmtItemCount->fetchColumn();

            $totalPages = max(1, ceil($totalItems / $limit));
            if ($page > $totalPages) $page = $totalPages;
            $offset = ($page - 1) * $limit;

            // Fetch kanban items detail for today
            $stmtItems = $pdo->prepare("
                SELECT ki.*, 
                       b.document_number, b.vendor, b.imported_at, b.import_method,
                       mp.part_name as master_part_name,
                       COALESCE(
                           (SELECT SUM(
                               CASE 
                                   WHEN EXISTS (SELECT 1 FROM inspection_session_lots isl_c WHERE isl_c.inspection_session_id = s2.id) THEN
                                       COALESCE((SELECT SUM(CASE WHEN isl2.lot_result IN ('passed', 'skipped') AND (isl2.lot_status IS NULL OR isl2.lot_status != 'replaced') THEN isl2.qty ELSE 0 END)
                                                 FROM inspection_session_lots isl2 WHERE isl2.inspection_session_id = s2.id), 0) - COALESCE(s2.excess_qty, 0)
                                   WHEN s2.status = 'passed' THEN (s2.total_scanned_qty - COALESCE(s2.excess_qty, 0))
                                   ELSE 0
                               END
                           )
                           FROM inspection_sessions s2
                           WHERE s2.kanban_item_id = ki.id), 0
                       ) AS passed_qty,
                       (SELECT COUNT(*) FROM inspection_sessions s_cnt WHERE s_cnt.kanban_item_id = ki.id) as session_count,
                       (SELECT 1 FROM inspection_sessions s_act WHERE s_act.kanban_item_id = ki.id AND s_act.status = 'in_progress' LIMIT 1) as has_active_session,
                       (SELECT u.name FROM inspection_sessions s_act JOIN users u ON u.id = s_act.inspector_id WHERE s_act.kanban_item_id = ki.id AND s_act.status = 'in_progress' LIMIT 1) as active_inspector_name
                FROM kanban_items ki
                JOIN kanban_batches b ON b.id = ki.batch_id
                LEFT JOIN master_parts mp ON mp.part_code = ki.item_code
                WHERE {$itemWhereStr}
                ORDER BY ki.id ASC
                LIMIT :limit OFFSET :offset
            ");
            foreach ($itemParams as $k => $v) {
                $stmtItems->bindValue($k, $v);
            }
            $stmtItems->bindValue(':limit',  (int)$limit,  PDO::PARAM_INT);
            $stmtItems->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
            $stmtItems->execute();
            $todayItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            // =====================================================================
            // 2C. FETCH SAFETY STOCK AVAILABLE LOTS & KALKULASI FIFO UNTUK KANBAN
            // =====================================================================
            try {
                // Ambil seluruh box/lot safety stock yang berstatus passed dan belum terpakai
                $stSsLots = $pdo->query("
                    SELECT 
                        isl.id AS lot_id,
                        isl.inspection_session_id,
                        isl.lot_number,
                        isl.ref_number,
                        COALESCE(isl.qty, ss.total_scanned_qty, 0) AS qty,
                        ss.started_at,
                        isl.created_at,
                        UPPER(TRIM(COALESCE(did.part_code, ki_ss.item_code, ''))) AS part_code
                    FROM inspection_session_lots isl
                    JOIN inspection_sessions ss ON ss.id = isl.inspection_session_id
                    LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
                    LEFT JOIN kanban_items ki_ss ON ki_ss.id = ss.kanban_item_id
                    WHERE ss.inspection_type = 'safety_stock'
                      AND ss.status = 'passed'
                      AND (ss.auto_fulfilled_by_session_id IS NULL OR ss.auto_fulfilled_by_session_id = 0)
                      AND (isl.lot_status IS NULL OR isl.lot_status != 'action_closed')
                      AND (isl.lot_result IS NULL OR isl.lot_result != 'rejected')
                    ORDER BY COALESCE(ss.started_at, isl.created_at) ASC, isl.id ASC
                ");
                $allSsLots = $stSsLots->fetchAll(PDO::FETCH_ASSOC);

                $ssLotsPool = [];
                foreach ($allSsLots as $sl) {
                    $pCode = $sl['part_code'];
                    if (empty($pCode)) continue;
                    if (!isset($ssLotsPool[$pCode])) {
                        $ssLotsPool[$pCode] = [];
                        $partSafetyStockMap[$pCode] = 0;
                    }
                    $ssLotsPool[$pCode][] = [
                        'lot_id'                => $sl['lot_id'],
                        'inspection_session_id' => $sl['inspection_session_id'],
                        'lot_number'            => $sl['lot_number'],
                        'ref_number'            => $sl['ref_number'],
                        'original_qty'          => (int)$sl['qty'],
                        'remaining_qty'         => (int)$sl['qty'],
                        'started_at'            => $sl['started_at'],
                        'created_at'            => $sl['created_at'],
                    ];
                    $partSafetyStockMap[$pCode] += (int)$sl['qty'];
                }

                // Ambil seluruh item kanban kandidat untuk kalkulasi safety stock
                $ssTargetWhere = [
                    "ki.status != 'completed'",
                    "b.plan_type = 'kanban'",
                    "DATE(b.imported_at) = CURDATE()",
                    "(ki.plan_type IS NULL OR ki.plan_type = 'kanban')",
                    "(ki.check_type IS NULL OR ki.check_type != 'Safety Stock')",
                    "(ki.kanban_no IS NULL OR ki.kanban_no NOT LIKE 'SS-%')"
                ];
                $ssTargetParams = [];
                if (!empty($selectedCustomer)) {
                    $ssTargetWhere[] = "ki.customer = :ss_cust";
                    $ssTargetParams[':ss_cust'] = $selectedCustomer;
                }
                if ($selectedBatch !== 'all') {
                    $ssTargetWhere[] = "ki.batch_id = :ss_bid";
                    $ssTargetParams[':ss_bid'] = (int)$selectedBatch;
                }
                $ssTargetWhereStr = implode(' AND ', $ssTargetWhere);

                $stmtSsItems = $pdo->prepare("
                    SELECT ki.*, 
                           b.document_number, b.vendor, b.imported_at, b.import_method,
                           mp.part_name as master_part_name,
                           COALESCE(
                               (SELECT SUM(
                                   CASE 
                                       WHEN EXISTS (SELECT 1 FROM inspection_session_lots isl_c WHERE isl_c.inspection_session_id = s2.id) THEN
                                           COALESCE((SELECT SUM(CASE WHEN isl2.lot_result IN ('passed', 'skipped') AND (isl2.lot_status IS NULL OR isl2.lot_status != 'replaced') THEN isl2.qty ELSE 0 END)
                                                     FROM inspection_session_lots isl2 WHERE isl2.inspection_session_id = s2.id), 0) - COALESCE(s2.excess_qty, 0)
                                       WHEN s2.status = 'passed' THEN (s2.total_scanned_qty - COALESCE(s2.excess_qty, 0))
                                       ELSE 0
                                   END
                               )
                               FROM inspection_sessions s2
                               WHERE s2.kanban_item_id = ki.id), 0
                           ) AS passed_qty
                    FROM kanban_items ki
                    JOIN kanban_batches b ON b.id = ki.batch_id
                    LEFT JOIN master_parts mp ON mp.part_code = ki.item_code
                    WHERE {$ssTargetWhereStr}
                    ORDER BY ki.id ASC
                ");
                $stmtSsItems->execute($ssTargetParams);
                $allSsKanbanCandidates = $stmtSsItems->fetchAll(PDO::FETCH_ASSOC);

                // Dynamic FIFO Depletion Pool Allocation:
                // Setiap box yang dialokasikan ke suatu Kanban langsung dipotong dari pool sehingga
                // Kanban berikutnya tidak akan pernah memanggil lot/box yang sama jika sudah habis!
                foreach ($allSsKanbanCandidates as $ck) {
                    $pCode = strtoupper(trim($ck['item_code']));
                    if (empty($ssLotsPool[$pCode])) continue;

                    // Hitung sisa stok di pool untuk part ini
                    $currentPool = &$ssLotsPool[$pCode];
                    $poolRemainingQty = 0;
                    foreach ($currentPool as $pItem) {
                        $poolRemainingQty += $pItem['remaining_qty'];
                    }
                    if ($poolRemainingQty <= 0) {
                        continue; // Stok Safety Stock part ini sudah habis terpakai Kanban sebelumnya
                    }

                    $demand = (int)$ck['qty'];
                    $passed = (int)$ck['passed_qty'];
                    $needed = max(0, $demand - $passed);

                    // Jika Kanban sudah selesai (kuota passed >= demand) atau status completed, lewati (jangan tawarkan SS)
                    if ($needed <= 0 || ($ck['status'] ?? '') === 'completed') {
                        continue;
                    }

                    $targetCalc = $needed;

                    $remDemand = $targetCalc;
                    $allocatedBoxes = [];
                    $uniqueLotNumbers = [];
                    $totalAllocatedThisKanban = 0;

                    foreach ($currentPool as &$l) {
                        if ($remDemand <= 0) break;
                        if ($l['remaining_qty'] <= 0) continue;

                        $take = min($l['remaining_qty'], $remDemand);
                        $isSplit = ($take < $l['remaining_qty']) || ($l['remaining_qty'] < $l['original_qty']);

                        $uniqueLotNumbers[$l['lot_number']] = true;
                        $allocatedBoxes[] = [
                            'lot_number' => $l['lot_number'],
                            'ref_number' => $l['ref_number'],
                            'take_qty'   => $take,
                            'box_qty'    => $l['original_qty'],
                            'is_split'   => ($take < $l['original_qty']),
                            'date'       => $l['started_at'] ?: $l['created_at']
                        ];

                        $l['remaining_qty'] -= $take;
                        $remDemand -= $take;
                        $totalAllocatedThisKanban += $take;
                    }
                    unset($l); // putuskan referensi loop

                    if (!empty($allocatedBoxes)) {
                        $fulfillType = ($totalAllocatedThisKanban >= $targetCalc) ? 'full' : 'partial';
                        $fulfillPct  = ($targetCalc > 0) ? min(100, round(($totalAllocatedThisKanban / $targetCalc) * 100)) : 0;

                        $safetyStockRecommendations[] = [
                            'item_id'      => $ck['id'],
                            'kanban_no'    => $ck['kanban_no'],
                            'customer'     => $ck['customer'] ?? 'PT. Indonesia Epson Industry',
                            'item_code'    => $pCode,
                            'item_name'    => !empty($ck['item_description']) ? $ck['item_description'] : (!empty($ck['master_part_name']) ? $ck['master_part_name'] : '-'),
                            'demand'       => $demand,
                            'passed_qty'   => $passed,
                            'needed_qty'   => $needed,
                            'status'       => $ck['status'] ?? 'uninspected',
                            'imported_at'  => $ck['imported_at'],
                            'ss_total'     => $totalAllocatedThisKanban,
                            'fulfill_type' => $fulfillType,
                            'fulfill_pct'  => $fulfillPct,
                            'box_count'    => count($allocatedBoxes),
                            'lot_count'    => count($uniqueLotNumbers),
                            'boxes'        => $allocatedBoxes
                        ];
                    }
                }

                $totalSsItems = count($safetyStockRecommendations);
                $totalSsPages = max(1, ceil($totalSsItems / $limit));
                if ($viewMode === 'safety_stock') {
                    $totalItems = $totalSsItems;
                    $totalPages = $totalSsPages;
                    if ($page > $totalPages) $page = $totalPages;
                    $offset = ($page - 1) * $limit;
                    $pagedRecommendations = array_slice($safetyStockRecommendations, $offset, $limit);
                }
            } catch (Exception $eSs) {
                // Silently fallback if error
            }

        } else {
            // =====================================================================
            // MODE PERIODE LAIN (MINGGU INI, BULAN INI, TAHUN INI, DLL)
            // =====================================================================

            // 2. Count distinct dates for pagination
            $countSql = "SELECT COUNT(DISTINCT {$dateExpr})
                         FROM kanban_batches b
                         LEFT JOIN kanban_items ki ON (ki.batch_id = b.id AND (ki.plan_type IS NULL OR ki.plan_type = 'kanban'))
                         WHERE {$whereStr}";
            $stmtCount = $pdo->prepare($countSql);
            $stmtCount->execute($queryParams);
            $totalItems = (int) $stmtCount->fetchColumn();

            $totalPages = max(1, ceil($totalItems / $limit));
            if ($page > $totalPages) $page = $totalPages;
            $offset = ($page - 1) * $limit;

            // 3. Fetch grouped-by-date summary
            $sql = "SELECT
                        {$dateExpr} AS plan_date,
                        COUNT(DISTINCT b.id) AS batch_count,
                        COUNT(ki.id) AS total_items,
                        COALESCE(SUM(ki.qty), 0) AS total_pcs,
                        MIN(b.imported_at) AS first_import,
                        MAX(b.imported_at) AS last_import
                    FROM kanban_batches b
                    LEFT JOIN kanban_items ki ON (ki.batch_id = b.id AND (ki.plan_type IS NULL OR ki.plan_type = 'kanban') AND (ki.check_type IS NULL OR ki.check_type != 'Safety Stock') AND (ki.kanban_no IS NULL OR ki.kanban_no NOT LIKE 'SS-%'))
                    WHERE {$whereStr}
                    GROUP BY {$dateExpr}
                    ORDER BY plan_date DESC
                    LIMIT :limit OFFSET :offset";

            $stmt = $pdo->prepare($sql);
            foreach ($queryParams as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->bindValue(':limit',  (int)$limit,  PDO::PARAM_INT);
            $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
            $stmt->execute();
            $days = $stmt->fetchAll();
        }

    } catch (PDOException $e) {
        $days = [];
    }
}

$monthsList = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-5 space-y-4">
        
        <?= render_flash() ?>

        <!-- Quick Period Preset Pills Header -->
        <div class="card p-3 flex flex-wrap items-center justify-between gap-3 bg-white shadow-2xs border border-slate-200/80 rounded-xl">
            
            <!-- Pill Buttons for Quick Date Ranges -->
            <div class="flex items-center space-x-1.5 bg-slate-100 p-1 rounded-xl flex-wrap">
                <a href="<?= base_url('modules/kanban/index.php?period=today' . (!empty($selectedCustomer) ? '&customer=' . urlencode($selectedCustomer) : '')) ?>"
                   class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all <?= ($period === 'today') ? 'bg-blue-600 text-white shadow-xs' : 'text-slate-600 hover:bg-slate-200/70 hover:text-slate-900' ?>">
                    Hari Ini
                </a>
                <a href="<?= base_url('modules/kanban/index.php?period=this_week' . (!empty($selectedCustomer) ? '&customer=' . urlencode($selectedCustomer) : '')) ?>"
                   class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all <?= ($period === 'this_week') ? 'bg-blue-600 text-white shadow-xs' : 'text-slate-600 hover:bg-slate-200/70 hover:text-slate-900' ?>">
                    Minggu Ini
                </a>
                <a href="<?= base_url('modules/kanban/index.php?period=this_month' . (!empty($selectedCustomer) ? '&customer=' . urlencode($selectedCustomer) : '')) ?>"
                   class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all <?= ($period === 'this_month') ? 'bg-blue-600 text-white shadow-xs' : 'text-slate-600 hover:bg-slate-200/70 hover:text-slate-900' ?>">
                    Bulan Ini
                </a>
                <a href="<?= base_url('modules/kanban/index.php?period=this_year' . (!empty($selectedCustomer) ? '&customer=' . urlencode($selectedCustomer) : '')) ?>"
                   class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all <?= ($period === 'this_year') ? 'bg-blue-600 text-white shadow-xs' : 'text-slate-600 hover:bg-slate-200/70 hover:text-slate-900' ?>">
                    Tahun Ini
                </a>
            </div>

            <!-- Action Button: Add New Planning -->
            <div>
                <a href="<?= base_url('modules/kanban/create.php') ?>" class="btn-primary py-1.5 px-3.5 text-xs font-bold flex items-center shadow-xs">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                    <span>Tambah Planning</span>
                </a>
            </div>
        </div>

        <!-- Filter & Search Toolbar (Customer, Month, Year, Date Range, Limit) -->
        <div class="card p-3 bg-white shadow-2xs border border-slate-200/80 rounded-xl">
            <form action="" method="GET" class="flex flex-wrap items-center gap-2.5">
                <input type="hidden" name="period" value="<?= htmlspecialchars($period) ?>">

                <!-- Customer Dropdown Filter -->
                <div class="flex items-center space-x-1.5">
                    <label class="text-xs font-bold text-slate-500 flex items-center">
                        <svg class="w-3.5 h-3.5 mr-1 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5m0 0h4m-4 0V11m0 0h4m-4 0H7"></path>
                        </svg>
                        Customer:
                    </label>
                    <select name="customer" onchange="this.form.submit()" class="form-input py-1 px-2.5 text-xs font-semibold text-slate-700 rounded-lg border-slate-300">
                        <option value="">-- Semua Customer --</option>
                        <?php foreach ($customersList as $cust): ?>
                            <option value="<?= htmlspecialchars($cust) ?>" <?= ($selectedCustomer === $cust) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cust) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Month Selector Filter -->
                <div class="flex items-center space-x-1.5">
                    <label class="text-xs font-bold text-slate-500">Bulan:</label>
                    <select name="month" onchange="this.form.submit()" class="form-input py-1 px-2.5 text-xs font-semibold text-slate-700 rounded-lg border-slate-300">
                        <option value="0">-- Semua Bulan --</option>
                        <?php foreach ($monthsList as $mNum => $mName): ?>
                            <option value="<?= $mNum ?>" <?= ($selectedMonth === $mNum) ? 'selected' : '' ?>>
                                <?= $mName ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Year Selector Filter -->
                <div class="flex items-center space-x-1.5">
                    <label class="text-xs font-bold text-slate-500">Tahun:</label>
                    <select name="year" onchange="this.form.submit()" class="form-input py-1 px-2.5 text-xs font-semibold text-slate-700 rounded-lg border-slate-300">
                        <option value="0">-- Semua Tahun --</option>
                        <?php foreach ($availableYears as $yr): ?>
                            <option value="<?= $yr ?>" <?= ($selectedYear === (int)$yr) ? 'selected' : '' ?>>
                                <?= $yr ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Date Range Custom Inputs -->
                <div class="flex items-center space-x-1.5 border-l border-slate-200 pl-2">
                    <span class="text-xs font-bold text-slate-500">Tanggal:</span>
                    <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" class="form-input py-1 px-2 text-xs text-slate-700 rounded-lg" style="width: 125px;">
                    <span class="text-xs text-slate-400">s/d</span>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" class="form-input py-1 px-2 text-xs text-slate-700 rounded-lg" style="width: 125px;">
                </div>

                <!-- Limit Selector -->
                <div class="flex items-center space-x-1 border-l border-slate-200 pl-2">
                    <select name="limit" onchange="this.form.submit()" class="form-input py-1 px-2 text-xs font-semibold text-slate-700 rounded-lg border-slate-300">
                        <option value="5" <?= ($limit == 5) ? 'selected' : '' ?>>5 / hal</option>
                        <option value="10" <?= ($limit == 10) ? 'selected' : '' ?>>10 / hal</option>
                        <option value="25" <?= ($limit == 25) ? 'selected' : '' ?>>25 / hal</option>
                        <option value="50" <?= ($limit == 50) ? 'selected' : '' ?>>50 / hal</option>
                    </select>
                </div>

                <!-- Action Filter & Reset -->
                <div class="flex items-center space-x-1.5">
                    <button type="submit" class="btn-secondary py-1 px-3 text-xs font-bold">Filter</button>
                    <?php if ($period !== 'today' || !empty($selectedCustomer) || $selectedMonth > 0 || $selectedYear > 0 || !empty($startDate) || !empty($endDate) || $limit != 10): ?>
                        <a href="<?= base_url('modules/kanban/index.php') ?>" class="text-xs text-rose-600 font-bold hover:underline px-2">Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- KPI Summary Cards Grid (4 Columns Single Row Desktop, Distinct Color Identities) -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; width: 100%;">
            
            <!-- Card 1: Total Planning Items (Soft Blue Theme) -->
            <div style="background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); border: 1px solid #bfdbfe; border-radius: 14px; padding: 14px; box-shadow: 0 1px 3px rgba(37,99,235,0.06); display: flex; items-center; justify-content: space-between; min-width: 0;">
                <div style="min-width: 0;">
                    <span style="color: #1e40af;" class="text-[10px] font-extrabold uppercase tracking-wider block">TOTAL PLANNING ITEMS</span>
                    <div style="color: #1e3a8a;" class="text-2xl font-black mt-1 leading-none">
                        <?= number_format($kpiStats['total_items']) ?> <span class="text-xs font-bold text-blue-700">Item</span>
                    </div>
                    <span style="color: #3b82f6;" class="text-[11px] font-bold mt-2 block">
                        Dari <?= number_format($kpiStats['total_batches']) ?> Batch Dokumen
                    </span>
                </div>
                <div style="background-color: #2563eb; color: #ffffff;" class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 shadow-xs ml-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
                    </svg>
                </div>
            </div>

            <!-- Card 2: Total Target Qty Pcs (Soft Indigo Theme) -->
            <div style="background: linear-gradient(135deg, #f5f3ff 0%, #ede9fe 100%); border: 1px solid #ddd6fe; border-radius: 14px; padding: 14px; box-shadow: 0 1px 3px rgba(79,70,229,0.06); display: flex; items-center; justify-content: space-between; min-width: 0;">
                <div style="min-width: 0;">
                    <span style="color: #3730a3;" class="text-[10px] font-extrabold uppercase tracking-wider block">TOTAL TARGET QTY</span>
                    <div style="color: #312e81;" class="text-2xl font-black mt-1 leading-none">
                        <?= number_format($kpiStats['total_pcs']) ?> <span class="text-xs font-bold text-indigo-700">pcs</span>
                    </div>
                    <span style="color: #6366f1;" class="text-[11px] font-bold mt-2 block">
                        Total Volume Kuantitas
                    </span>
                </div>
                <div style="background-color: #4f46e5; color: #ffffff;" class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 shadow-xs ml-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                    </svg>
                </div>
            </div>

            <!-- Card 3: Completed Items (Soft Emerald/Green Theme) -->
            <div style="background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%); border: 1px solid #a7f3d0; border-radius: 14px; padding: 14px; box-shadow: 0 1px 3px rgba(5,150,105,0.06); display: flex; items-center; justify-content: space-between; min-width: 0;">
                <div style="width: 100%; min-width: 0;" class="mr-2">
                    <span style="color: #065f46;" class="text-[10px] font-extrabold uppercase tracking-wider block">ITEM SELESAI (COMPLETED)</span>
                    <div class="flex items-center space-x-2 mt-1">
                        <span style="color: #047857;" class="text-2xl font-black leading-none"><?= number_format($kpiStats['completed_items']) ?></span>
                        <span style="background-color: #059669; color: #ffffff; padding: 2px 7px; font-size: 11px; font-weight: 800; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; line-height: 1;">
                            <?= $kpiStats['completion_rate'] ?>%
                        </span>
                    </div>
                    <!-- Mini Progress Bar -->
                    <div style="background-color: #a7f3d0;" class="w-full rounded-full h-2 mt-2 overflow-hidden">
                        <div style="background-color: #059669; width: <?= min(100, $kpiStats['completion_rate']) ?>%;" class="h-2 rounded-full transition-all duration-300"></div>
                    </div>
                </div>
                <div style="background-color: #059669; color: #ffffff;" class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 shadow-xs">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
            </div>

            <!-- Card 4: Pending / In-Progress Items (Soft Amber/Orange Theme) -->
            <div style="background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); border: 1px solid #fde68a; border-radius: 14px; padding: 14px; box-shadow: 0 1px 3px rgba(217,119,6,0.06); display: flex; items-center; justify-content: space-between; min-width: 0;">
                <div style="width: 100%; min-width: 0;" class="mr-2">
                    <span style="color: #92400e;" class="text-[10px] font-extrabold uppercase tracking-wider block">ITEM BELUM SELESAI</span>
                    <div class="flex items-center space-x-2 mt-1">
                        <span style="color: #b45309;" class="text-2xl font-black leading-none"><?= number_format($kpiStats['pending_items']) ?></span>
                        <span style="background-color: #d97706; color: #ffffff; padding: 2px 7px; font-size: 11px; font-weight: 800; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; line-height: 1;">
                            <?= $kpiStats['pending_rate'] ?>%
                        </span>
                    </div>
                    <!-- Mini Progress Bar -->
                    <div style="background-color: #fde68a;" class="w-full rounded-full h-2 mt-2 overflow-hidden">
                        <div style="background-color: #d97706; width: <?= min(100, $kpiStats['pending_rate']) ?>%;" class="h-2 rounded-full transition-all duration-300"></div>
                    </div>
                </div>
                <div style="background-color: #d97706; color: #ffffff;" class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 shadow-xs">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
            </div>

        </div>

        <?php if ($period === 'today'): ?>
            <!-- ===================================================================== -->
            <!-- MODE HARI INI: GELOMBANG / BATCH PILLS & BREAKDOWN ITEM KANBAN        -->
            <!-- ===================================================================== -->
            
            <!-- GELOMBANG / BATCH TABS (Mirip Modules Inspection) -->
            <div class="card p-3 bg-white border border-slate-200/80 rounded-xl shadow-2xs">
                <div class="flex items-center justify-between gap-2 flex-wrap mb-2">
                    <div class="text-[11px] font-extrabold text-slate-700 uppercase tracking-wider flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                        </svg>
                        <span>GELOMBANG / BATCH (<?= date('d M Y') ?>):</span>
                    </div>
                    <span class="text-[10px] text-slate-400 font-medium">Klik tab batch untuk menyaring daftar part sesuai lembar fisik batch</span>
                </div>

                <div class="flex items-center gap-2 flex-wrap">
                    <?php 
                    $batchNum = 1;
                    $totalTodayParts = 0;
                    foreach ($todayBatches as $tb) {
                        $totalTodayParts += (int)$tb['item_count'];
                    }
                    ?>

                    <?php if (empty($todayBatches)): ?>
                        <span class="text-xs text-slate-400 font-semibold italic py-1">Belum ada batch Kanban yang di-import hari ini.</span>
                    <?php else: ?>
                        <!-- Pill Tiap Batch -->
                        <?php foreach ($todayBatches as $tb): 
                            $isActiveBatch = ($viewMode !== 'safety_stock' && (string)$selectedBatch === (string)$tb['id']);
                            $timeStr = date('H:i', strtotime($tb['imported_at']));
                            $cCount = (int)$tb['completed_count'];
                            $iCount = (int)$tb['item_count'];
                            $isBatchCompleted = ($iCount > 0 && $cCount >= $iCount);
                            $batchLabel = 'Batch ' . $batchNum++ . ' (' . $timeStr . ')';

                            if ($isActiveBatch) {
                                $btnStyle = 'background-color: #2563eb; color: #ffffff; border: 1px solid #1d4ed8; box-shadow: 0 2px 6px rgba(37,99,235,0.3);';
                                $badgeStyle = 'background-color: rgba(255,255,255,0.22); color: #ffffff; border: 1px solid rgba(255,255,255,0.35);';
                            } else {
                                $btnStyle = 'background-color: #f8fafc; color: #334155; border: 1px solid #cbd5e1;';
                                if ($isBatchCompleted) {
                                    $badgeStyle = 'background-color: #d1fae5; color: #065f46; border: 1px solid #a7f3d0;';
                                } else {
                                    $badgeStyle = 'background-color: #ffffff; color: #334155; border: 1px solid #e2e8f0;';
                                }
                            }
                        ?>
                            <a href="<?= base_url('modules/kanban/index.php?period=today&batch_id=' . $tb['id'] . (!empty($selectedCustomer) ? '&customer=' . urlencode($selectedCustomer) : '') . ($limit != 50 ? '&limit=' . $limit : '')) ?>"
                               class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-bold transition-all"
                               style="<?= $btnStyle ?>">
                                <span><?= htmlspecialchars($batchLabel) ?></span>
                                <span class="px-2 py-0.5 rounded text-[10px] font-extrabold" style="<?= $badgeStyle ?>">
                                    <?= $cCount ?>/<?= $iCount ?> Selesai
                                </span>
                            </a>
                        <?php endforeach; ?>

                        <!-- Pill Semua Batch Tanggal Ini -->
                        <?php 
                            $isAllActive = ($viewMode !== 'safety_stock' && $selectedBatch === 'all'); 
                            if ($isAllActive) {
                                $allBtnStyle = 'background-color: #1d4ed8; color: #ffffff; border: 1px solid #1e40af; box-shadow: 0 2px 6px rgba(29,78,216,0.35);';
                                $allBadgeStyle = 'background-color: rgba(255,255,255,0.22); color: #ffffff; border: 1px solid rgba(255,255,255,0.35);';
                            } else {
                                $allBtnStyle = 'background-color: #f8fafc; color: #334155; border: 1px solid #cbd5e1;';
                                $allBadgeStyle = 'background-color: #ffffff; color: #334155; border: 1px solid #e2e8f0;';
                            }
                        ?>
                        <a href="<?= base_url('modules/kanban/index.php?period=today&batch_id=all' . (!empty($selectedCustomer) ? '&customer=' . urlencode($selectedCustomer) : '') . ($limit != 50 ? '&limit=' . $limit : '')) ?>"
                           class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-bold transition-all"
                           style="<?= $allBtnStyle ?>">
                            <span>Semua Batch Tanggal Ini</span>
                            <span class="px-2 py-0.5 rounded text-[10px] font-extrabold" style="<?= $allBadgeStyle ?>">
                                <?= $totalTodayParts ?> Part
                            </span>
                        </a>

                        <!-- Pill Rekomendasi Safety Stock (FIFO) -->
                        <?php 
                            $isSsActive = ($viewMode === 'safety_stock'); 
                            if ($isSsActive) {
                                $ssBtnStyle = 'background-color: #7c3aed; color: #ffffff; border: 1px solid #6d28d9; box-shadow: 0 2px 6px rgba(124,58,237,0.35);';
                                $ssBadgeStyle = 'background-color: rgba(255,255,255,0.25); color: #ffffff; border: 1px solid rgba(255,255,255,0.4);';
                                $ssIconColor = '#ffffff';
                            } else {
                                $ssBtnStyle = 'background-color: #f5f3ff; color: #6d28d9; border: 1px solid #ddd6fe;';
                                $ssBadgeStyle = 'background-color: #ede9fe; color: #5b21b6; border: 1px solid #c4b5fd;';
                                $ssIconColor = '#7c3aed';
                            }
                        ?>
                        <a href="<?= base_url('modules/kanban/index.php?period=today&view=safety_stock' . (!empty($selectedCustomer) ? '&customer=' . urlencode($selectedCustomer) : '') . ($selectedBatch !== 'all' ? '&batch_id=' . urlencode($selectedBatch) : '') . ($limit != 50 ? '&limit=' . $limit : '')) ?>"
                           class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-bold transition-all"
                           style="<?= $ssBtnStyle ?>"
                           title="Lihat daftar Kanban yang bisa dipotong menggunakan Safety Stock di gudang (urutan FIFO)">
                            <svg class="w-3.5 h-3.5" style="color: <?= $ssIconColor ?>;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                            </svg>
                            <span>Rekomendasi Safety Stock (FIFO)</span>
                            <span class="px-2 py-0.5 rounded text-[10px] font-black" style="<?= $ssBadgeStyle ?>">
                                <?= count($safetyStockRecommendations) ?> Kanban Ready
                            </span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- TABEL BREAKDOWN ITEM KANBAN HARI INI (GAYA WORKBOARD INSPEKSI) -->
            <style>
                .row-status-overdue > td {
                    background-color: #fff1f2 !important;
                }
                .row-status-overdue:hover > td {
                    background-color: #ffe4e6 !important;
                }
                .row-status-overdue > td:first-child {
                    border-left: 5px solid #e11d48 !important;
                }

                .row-status-active > td {
                    background-color: #fffbeb !important;
                }
                .row-status-active:hover > td {
                    background-color: #fef3c7 !important;
                }
                .row-status-active > td:first-child {
                    border-left: 5px solid #d97706 !important;
                }

                .row-status-partial > td {
                    background-color: #fef3c7 !important;
                }
                .row-status-partial:hover > td {
                    background-color: #fde68a !important;
                }
                .row-status-partial > td:first-child {
                    border-left: 5px solid #f59e0b !important;
                }

                .row-status-completed > td {
                    background-color: #dcfce7 !important;
                }
                .row-status-completed:hover > td {
                    background-color: #bbf7d0 !important;
                }
                .row-status-completed > td:first-child {
                    border-left: 5px solid #10b981 !important;
                }

                .row-status-rejected > td {
                    background-color: #fff1f2 !important;
                }
                .row-status-rejected:hover > td {
                    background-color: #ffe4e6 !important;
                }
                .row-status-rejected > td:first-child {
                    border-left: 5px solid #be123c !important;
                }

                .row-status-uninspected > td {
                    background-color: #ffffff;
                }
                .row-status-uninspected:hover > td {
                    background-color: #f8fafc;
                }
                .row-status-uninspected > td:first-child {
                    border-left: 5px solid #cbd5e1;
                }
            </style>

            <?php if ($viewMode === 'safety_stock'): ?>
                <!-- ===================================================================== -->
                <!-- TABEL REKOMENDASI SAFETY STOCK (FIFO)                                 -->
                <!-- ===================================================================== -->
                <div class="card p-0 overflow-hidden bg-white rounded-xl shadow-2xs" style="border: 1px solid #c7d2fe;">
                    <div class="px-4 py-3.5 flex items-center justify-between flex-wrap gap-2" style="background: linear-gradient(to right, #eef2ff, #f8fafc); border-bottom: 1px solid #e0e7ff;">
                        <div class="flex items-center gap-2.5">
                            <span class="inline-flex p-2 rounded-lg text-white shadow-xs" style="background-color: #4f46e5;">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                </svg>
                            </span>
                            <div>
                                <h2 class="text-xs font-black uppercase tracking-wider flex items-center gap-1.5" style="color: #1e1b4b;">
                                    <span>Rekomendasi Safety Stock (FIFO) &ndash; <?= date('d M Y') ?></span>
                                </h2>
                                <p class="text-[11px] font-medium mt-0.5" style="color: #4338ca;">
                                    Daftar order Kanban yang part-nya tersedia di rak stok pengaman gudang. Box diurutkan secara First-In, First-Out (tanggal masuk tertua).
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-[11px] font-bold text-slate-600 bg-white px-3 py-1.5 rounded-lg border border-slate-200 shadow-2xs">
                                Menampilkan <b><?= count($pagedRecommendations) ?></b> dari <b><?= number_format($totalSsItems) ?></b> Kanban Siap Pakai
                            </span>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs text-slate-700" style="border-collapse: collapse; min-width: 980px;">
                            <thead class="bg-slate-50 text-slate-600 font-bold border-b border-slate-200 uppercase tracking-wider text-[10px]">
                                <tr>
                                    <th class="text-center px-2 py-3" style="width: 45px;">No</th>
                                    <th class="px-3 py-3" style="width: 170px;">Kanban & Customer</th>
                                    <th class="px-3 py-3" style="width: 200px;">Part (Kode & Nama)</th>
                                    <th class="px-3 py-3" style="width: 170px;">Kebutuhan vs Stok Rak</th>
                                    <th class="px-3 py-3">Rekomendasi Box Fisik yang Harus Diambil (Urut FIFO)</th>
                                    <th class="px-3 py-3 text-center" style="width: 120px;">Status Kanban</th>
                                    <th class="px-3 py-3 text-right" style="width: 70px;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if (empty($pagedRecommendations)): ?>
                                    <tr>
                                        <td colspan="7" class="px-4 py-12 text-center text-slate-400">
                                            <div class="flex flex-col items-center justify-center">
                                                <svg class="w-10 h-10 text-slate-300 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                                </svg>
                                                <span class="font-bold text-slate-600 text-sm">Tidak Ada Kanban yang Memiliki Safety Stock</span>
                                                <span class="text-xs text-slate-400 mt-1">Seluruh part pada batch atau tanggal ini belum memiliki stok pengaman passed di gudang.</span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($pagedRecommendations as $idx => $rec): 
                                        $rNum = $offset + $idx + 1;
                                    ?>
                                        <tr class="hover:bg-slate-50/70 transition-colors" style="vertical-align: top;">
                                            <td class="text-center px-2 py-3 font-semibold text-slate-400 font-mono text-xs">
                                                <?= $rNum ?>
                                            </td>
                                            
                                            <!-- Kanban & Customer -->
                                            <td class="px-3 py-3">
                                                <div class="font-mono font-black text-slate-900 text-xs">
                                                    #<?= htmlspecialchars($rec['kanban_no']) ?>
                                                </div>
                                                <div class="text-[11px] text-slate-500 font-medium truncate max-w-[160px]" title="<?= htmlspecialchars($rec['customer']) ?>">
                                                    <?= htmlspecialchars($rec['customer']) ?>
                                                </div>
                                                <?php if (!empty($rec['imported_at'])): ?>
                                                    <div class="text-[10px] text-slate-400 font-semibold mt-0.5">
                                                        Import: <?= date('H:i', strtotime($rec['imported_at'])) ?> WIB
                                                    </div>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Part -->
                                            <td class="px-3 py-3">
                                                <span class="font-mono font-extrabold text-blue-700 text-xs block">
                                                    <?= htmlspecialchars($rec['item_code']) ?>
                                                </span>
                                                <span class="text-[11px] text-slate-700 font-semibold block leading-tight mt-0.5">
                                                    <?= htmlspecialchars($rec['item_name']) ?>
                                                </span>
                                            </td>

                                            <!-- Kebutuhan vs Stok SS -->
                                            <td class="px-3 py-3">
                                                <div class="text-xs">
                                                    <div class="text-slate-500 text-[10px] font-semibold uppercase">Kebutuhan Order:</div>
                                                    <div class="font-mono font-black text-slate-800 text-xs">
                                                        <?= number_format($rec['needed_qty']) ?> pcs
                                                        <?php if ($rec['passed_qty'] > 0): ?>
                                                            <span class="text-[10px] text-slate-500 font-normal block">(Total: <?= number_format($rec['demand']) ?>, Sudah Passed: <?= number_format($rec['passed_qty']) ?>)</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="text-xs mt-1.5 pt-1.5 border-t border-slate-100">
                                                    <div class="text-slate-500 text-[10px] font-semibold uppercase">Stok SS Ready:</div>
                                                    <div class="font-mono font-black text-xs" style="color: #4338ca;">
                                                        <?= number_format($rec['ss_total']) ?> pcs
                                                    </div>
                                                </div>
                                                <div class="mt-2">
                                                    <?php if ($rec['fulfill_type'] === 'full'): ?>
                                                        <span style="background-color: #dcfce7; color: #166534; border: 1px solid #bbf7d0; padding: 3px 8px; border-radius: 9999px; font-weight: 800; font-size: 10px; white-space: nowrap; display: inline-flex; align-items: center; gap: 3px;">
                                                            <span>🟢</span> 100% CUKUP DARI SS
                                                        </span>
                                                    <?php else: ?>
                                                        <span style="background-color: #fef3c7; color: #b45309; border: 1px solid #fde68a; padding: 3px 8px; border-radius: 9999px; font-weight: 800; font-size: 10px; white-space: nowrap; display: inline-flex; align-items: center; gap: 3px;">
                                                            <span>🟡</span> PARSIAL (<?= $rec['fulfill_pct'] ?>%)
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>

                                            <!-- Rekomendasi Box Fisik FIFO -->
                                            <td class="px-3 py-3">
                                                <div style="font-size: 11px; font-weight: 800; color: #334155; margin-bottom: 6px;">
                                                    Ambil <b><?= $rec['box_count'] ?> Box Fisik</b> (dari <?= $rec['lot_count'] ?> Lot Berbeda):
                                                </div>
                                                <div class="space-y-1.5">
                                                    <?php foreach ($rec['boxes'] as $bIdx => $bx): ?>
                                                        <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-left: 3px solid #4f46e5; border-radius: 6px; padding: 6px 10px; font-size: 11px;">
                                                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 6px; flex-wrap: wrap;">
                                                                <span style="font-weight: 800; color: #1e293b;">
                                                                    <span style="background-color: #4f46e5; color: #ffffff; padding: 1px 5px; border-radius: 4px; font-size: 9px; font-weight: 900; margin-right: 4px;">Box #<?= $bIdx + 1 ?></span>
                                                                    Lot: <span style="font-family: monospace; color: #0f172a;"><?= htmlspecialchars($bx['lot_number']) ?></span>
                                                                </span>
                                                                <span style="font-weight: 800; <?= $bx['is_split'] ? 'color: #d97706;' : 'color: #16a34a;' ?>">
                                                                    <?= $bx['is_split'] ? '✂️ Ambil ' . number_format($bx['take_qty']) . ' dari ' . number_format($bx['box_qty']) . ' pcs' : '📦 Ambil ' . number_format($bx['take_qty']) . ' pcs (Utuh)' ?>
                                                                </span>
                                                            </div>
                                                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 6px; margin-top: 3px; font-size: 10px; color: #64748b;">
                                                                <span>Ref Barcode: <b style="font-family: monospace; color: #334155;"><?= htmlspecialchars($bx['ref_number']) ?></b></span>
                                                                <span>Masuk SS: <b><?= date('d M Y H:i', strtotime($bx['date'])) ?></b></span>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </td>

                                            <!-- Status Kanban -->
                                            <td class="px-3 py-3 text-center whitespace-nowrap">
                                                <?php if ($rec['status'] === 'completed'): ?>
                                                    <span style="background-color: #16a34a; color: #ffffff; font-weight: 800; font-size: 10px; padding: 3px 9px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3px;">
                                                        Selesai
                                                    </span>
                                                <?php elseif ($rec['status'] === 'in_progress'): ?>
                                                    <span style="background-color: #d97706; color: #ffffff; font-weight: 800; font-size: 10px; padding: 3px 9px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3px;">
                                                        Sedang Dikerjakan
                                                    </span>
                                                <?php elseif ($rec['status'] === 'partial'): ?>
                                                    <span style="background-color: #d97706; color: #ffffff; font-weight: 800; font-size: 10px; padding: 3px 9px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3px;">
                                                        Parsial
                                                    </span>
                                                <?php else: ?>
                                                    <span style="background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; font-weight: 800; font-size: 10px; padding: 3px 9px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3px;">
                                                        Belum Mulai
                                                    </span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Aksi -->
                                            <td class="px-3 py-3 text-right whitespace-nowrap">
                                                <div class="inline-flex items-center justify-end gap-1">
                                                    <a href="<?= base_url('modules/kanban/edit.php?id=' . $rec['item_id']) ?>" 
                                                       class="p-1 text-blue-600 hover:bg-blue-50 rounded-md transition-colors" title="Edit Data Kanban">
                                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                        </svg>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination Footer -->
                    <?= render_pagination($page, $totalPages, $totalItems, $limit) ?>
                </div>
            <?php else: ?>
                <!-- TABEL BREAKDOWN ITEM KANBAN HARI INI (GAYA WORKBOARD INSPEKSI) -->
                <div class="card p-0 overflow-hidden bg-white border border-slate-200/80 rounded-xl shadow-2xs">
                <div class="px-4 py-3 bg-slate-50/80 border-b border-slate-200 flex items-center justify-between flex-wrap gap-2">
                    <div class="flex items-center gap-2">
                        <span class="inline-flex p-1 rounded-md bg-blue-100 text-blue-700">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                            </svg>
                        </span>
                        <h2 class="text-xs font-extrabold text-slate-800 uppercase tracking-wider">
                            Rincian Item Kanban Hari Ini (<?= date('d M Y') ?>)
                        </h2>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-[11px] font-bold text-slate-500">
                            Menampilkan <?= count($todayItems) ?> dari <?= number_format($totalItems) ?> Item
                        </span>
                        <?php if (!empty($todayBatches)): ?>
                            <a href="<?= base_url('modules/kanban/daily_detail.php?date=' . date('Y-m-d')) ?>" class="text-[11px] font-bold text-blue-600 hover:underline flex items-center">
                                Buka Full Detail &rarr;
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs text-slate-700 table-fixed" style="border-collapse: collapse; min-width: 1050px;">
                        <colgroup>
                            <col style="width: 50px;">
                            <col style="width: 20%;">
                            <col style="width: 24%;">
                            <col style="width: 17%;">
                            <col style="width: 14%;">
                            <col style="width: 12%;">
                            <col style="width: 13%;">
                        </colgroup>
                        <thead class="bg-slate-50 text-slate-600 font-bold border-b border-slate-200/80 uppercase tracking-wider text-[10px]">
                            <tr>
                                <th class="text-center px-2 py-3">No</th>
                                <th class="px-3 py-3">Kanban</th>
                                <th class="px-3 py-3">Part</th>
                                <th class="px-3 py-3">Jadwal & ETA</th>
                                <th class="px-2 py-3 text-center">Progres</th>
                                <th class="px-2 py-3 text-center">Status</th>
                                <th class="px-3 py-3 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (empty($todayItems)): ?>
                                <tr>
                                    <td colspan="7" class="px-4 py-8 text-center text-slate-400">
                                        Tidak ada item Kanban untuk filter batch/hari ini.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($todayItems as $idx => $it): 
                                    $kid = (int)$it['id'];
                                    $targetQty = (int)($it['qty'] ?? 0);
                                    $passedQty = (int)($it['passed_qty'] ?? 0);
                                    $progressPct = ($targetQty > 0) ? min(100, round(($passedQty / $targetQty) * 100)) : 0;
                                    $isFullyPassed = ($targetQty > 0 && $passedQty >= $targetQty);
                                    $st = $it['status'] ?? 'uninspected';

                                    $hasActiveSession = !empty($it['has_active_session']);
                                    $actInspName = htmlspecialchars($it['active_inspector_name'] ?? '');

                                    $schedDate = !empty($it['eta']) ? $it['eta'] : (!empty($it['req_date']) ? $it['req_date'] : $it['created_at']);
                                    $isOverdue = (!$isFullyPassed && $st !== 'completed' && !empty($schedDate) && date('Y-m-d', strtotime($schedDate)) < date('Y-m-d'));
                                    $isPartial = (!$isFullyPassed && $st !== 'completed' && ($st === 'partial' || ($passedQty > 0 && $passedQty < $targetQty)));
                                    $isCompleted = ($isFullyPassed || $st === 'completed');
                                    $isRejected = ($st === 'rejected' && $passedQty <= 0);

                                    $is100Check = (!empty($it['check_type']) && (strcasecmp($it['check_type'], '100%') === 0 || strpos($it['check_type'], '100') !== false));

                                    if ($isOverdue) {
                                        $rowColorClass = 'row-status-overdue';
                                    } elseif ($hasActiveSession) {
                                        $rowColorClass = 'row-status-active';
                                    } elseif ($isPartial) {
                                        $rowColorClass = 'row-status-partial';
                                    } elseif ($isCompleted) {
                                        $rowColorClass = 'row-status-completed';
                                    } elseif ($isRejected) {
                                        $rowColorClass = 'row-status-rejected';
                                    } else {
                                        $rowColorClass = 'row-status-uninspected';
                                    }

                                    $importMethod = strtolower($it['import_method'] ?? 'manual');
                                    $isImport = ($importMethod === 'excel_import' || $importMethod === 'excel' || $importMethod === 'csv');
                                    $partDesc = !empty($it['item_description']) ? $it['item_description'] : (!empty($it['master_part_name']) ? $it['master_part_name'] : '-');
                                ?>
                                    <tr class="transition-colors <?= $rowColorClass ?>" style="vertical-align: middle;">
                                        
                                        <!-- No Urut -->
                                        <td class="text-center px-2 py-3 font-semibold text-slate-500 font-mono text-xs">
                                            <?= $offset + $idx + 1 ?>
                                        </td>
                                        
                                        <!-- KANBAN: #No & Customer -->
                                        <td class="px-3 py-3">
                                            <div class="flex items-center space-x-1.5">
                                                <span class="font-mono font-black text-slate-900 text-xs">
                                                    #<?= htmlspecialchars($it['kanban_no']) ?>
                                                </span>
                                            </div>
                                            <div class="text-[11px] text-slate-500 font-medium truncate max-w-[200px]" title="<?= htmlspecialchars($it['customer'] ?? 'PT. Indonesia Epson Industry') ?>">
                                                <?= htmlspecialchars($it['customer'] ?? 'PT. Indonesia Epson Industry') ?>
                                            </div>
                                            <div class="mt-1 flex items-center gap-1 flex-wrap">
                                                <?php if ($isImport): ?>
                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-extrabold bg-teal-50 text-teal-700 border border-teal-200 uppercase tracking-wider leading-none" title="Dibuat melalui Import File Batch">
                                                        IMPORT
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-extrabold bg-slate-100 text-slate-600 border border-slate-200 uppercase tracking-wider leading-none" title="Dibuat melalui Input Form Manual">
                                                        MANUAL
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>

                                        <!-- PART: Item Code & Name -->
                                        <td class="px-3 py-3">
                                            <span class="font-mono font-extrabold text-blue-700 text-xs block">
                                                <?= htmlspecialchars($it['item_code']) ?>
                                            </span>
                                            <span class="text-[11px] text-slate-600 font-medium block truncate max-w-[240px]" title="<?= htmlspecialchars($partDesc) ?>">
                                                <?= htmlspecialchars($partDesc) ?>
                                            </span>
                                            <?php if ($is100Check): ?>
                                                <div class="mt-0.5">
                                                    <span class="px-1.5 py-0.2 rounded text-[8.5px] font-bold bg-amber-50 text-amber-800 border border-amber-200 inline-block leading-tight">
                                                        100% Cek
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                            <?php 
                                                $pCodeUpper = strtoupper(trim($it['item_code']));
                                                $ssAvailQty = $partSafetyStockMap[$pCodeUpper] ?? 0;
                                            ?>
                                            <?php if ($ssAvailQty > 0 && !$isCompleted): ?>
                                                <div class="mt-1">
                                                    <a href="<?= base_url('modules/kanban/index.php?period=today&view=safety_stock' . (!empty($selectedCustomer) ? '&customer=' . urlencode($selectedCustomer) : '')) ?>" 
                                                       class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[9px] font-extrabold transition-colors shadow-2xs" 
                                                       style="background-color: #eef2ff; color: #4338ca; border: 1px solid #c7d2fe;"
                                                       title="Part ini memiliki stok pengaman ready di rak gudang (Klik untuk lihat rekomendasi FIFO)">
                                                        <span>📦 Ready SS: <?= number_format($ssAvailQty) ?> pcs</span>
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- JADWAL & ETA -->
                                        <td class="px-3 py-3 whitespace-nowrap">
                                            <div class="font-medium text-slate-700 text-[10px] flex items-center gap-1.5" title="Tanggal Request (Req Date)">
                                                <svg width="12" height="12" style="width: 12px; height: 12px; min-width: 12px; min-height: 12px;" class="text-slate-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                                <span class="<?= ($isOverdue && empty($it['eta'])) ? 'text-rose-700 font-bold' : '' ?>"><?= !empty($it['req_date']) ? date('d/m/Y H:i', strtotime($it['req_date'])) : '-' ?></span>
                                            </div>
                                            <?php if (!empty($it['eta'])): ?>
                                                <div class="text-[9.5px] font-semibold <?= $isOverdue ? 'text-rose-700 font-bold' : 'text-amber-700' ?> flex items-center gap-1.5 mt-0.5" title="Estimasi Kedatangan (ETA)">
                                                    <svg width="12" height="12" style="width: 12px; height: 12px; min-width: 12px; min-height: 12px;" class="<?= $isOverdue ? 'text-rose-500' : 'text-amber-500' ?> flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                                    <span><?= date('d/m/Y H:i', strtotime($it['eta'])) ?></span>
                                                </div>
                                            <?php endif; ?>

                                            <?php if ($isOverdue): ?>
                                                <?php
                                                    $schedTs = strtotime($schedDate);
                                                    $daysOver = max(1, (int)floor((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', $schedTs))) / 86400));
                                                ?>
                                                <div class="mt-1 flex items-center gap-1 flex-wrap">
                                                    <span class="px-1.5 py-0.5 rounded text-[8.5px] font-extrabold bg-rose-100 text-rose-800 border border-rose-300 inline-flex items-center gap-1 shadow-2xs">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-rose-600 animate-pulse"></span> OVERDUE
                                                    </span>
                                                    <span class="px-1.5 py-0.5 rounded text-[8.5px] font-bold bg-rose-50 text-rose-700 border border-rose-200 inline-block">
                                                        Lewat <?= $daysOver ?> hari
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- PROGRES: 0 / 62 pcs -->
                                        <td class="px-2 py-3 text-center whitespace-nowrap">
                                            <div class="font-mono font-black text-xs text-slate-800">
                                                <span><?= number_format($passedQty) ?></span> <span class="text-slate-400 font-normal">/</span> <?= number_format($targetQty) ?> <span class="text-[10px] text-slate-500 font-medium">pcs</span>
                                            </div>
                                            <?php
                                                if ($isFullyPassed || $progressPct >= 100) {
                                                    $barBg = 'background: linear-gradient(90deg, #10b981 0%, #059669 100%);';
                                                    $barLabel = '100% selesai';
                                                    $labelClass = 'text-emerald-700 font-extrabold';
                                                    $barFillWidth = 100;
                                                } elseif ($hasActiveSession) {
                                                    $barBg = 'background: linear-gradient(90deg, #f59e0b 0%, #d97706 100%);';
                                                    $barLabel = ($progressPct > 0) ? ($progressPct . '% berjalan') : 'Mulai berjalan';
                                                    $labelClass = 'text-amber-800 font-black';
                                                    $barFillWidth = max(8, $progressPct);
                                                } elseif ($progressPct > 0) {
                                                    $barBg = 'background: linear-gradient(90deg, #f59e0b 0%, #d97706 100%);';
                                                    $barLabel = $progressPct . '% parsial';
                                                    $labelClass = 'text-amber-800 font-extrabold';
                                                    $barFillWidth = $progressPct;
                                                } else {
                                                    $barBg = 'background-color: #cbd5e1;';
                                                    $barLabel = 'Belum mulai';
                                                    $labelClass = 'text-slate-400 font-medium';
                                                    $barFillWidth = 0;
                                                }
                                            ?>
                                            <div class="w-20 rounded-full overflow-hidden mx-auto my-1 bg-slate-200" style="height: 7px; border: 1px solid #cbd5e1;">
                                                <div style="height: 100%; width: <?= $barFillWidth ?>%; <?= $barBg ?> border-radius: 9999px;"></div>
                                            </div>
                                            <span class="text-[10px] <?= $labelClass ?> block leading-none">
                                                <?= $barLabel ?>
                                            </span>
                                        </td>

                                        <!-- STATUS -->
                                        <td class="px-2 py-3 text-center whitespace-nowrap">
                                            <?php if ($hasActiveSession): ?>
                                                <span style="background-color: #d97706; color: #ffffff; font-weight: 800; font-size: 10px; padding: 3px 9px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 3px rgba(217, 119, 6, 0.4);">
                                                    <span style="width: 5px; height: 5px; border-radius: 9999px; background-color: #ffffff; display: inline-block;" class="animate-pulse"></span>
                                                    Sedang Dikerjakan
                                                </span>
                                            <?php elseif ($isCompleted): ?>
                                                <span style="background-color: #16a34a; color: #ffffff; font-weight: 800; font-size: 10.5px; padding: 3px 10px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 3px rgba(22, 163, 74, 0.4);">
                                                    <svg style="width: 10px; height: 10px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                                    Selesai
                                                </span>
                                            <?php elseif ($isPartial): ?>
                                                <span style="background-color: #d97706; color: #ffffff; font-weight: 800; font-size: 10.5px; padding: 2.5px 9px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px;">
                                                    Parsial
                                                </span>
                                            <?php elseif ($isRejected): ?>
                                                <span style="background-color: #dc2626; color: #ffffff; font-weight: 800; font-size: 10.5px; padding: 3px 9px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px;">
                                                    Reject
                                                </span>
                                            <?php else: ?>
                                                <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-slate-100 text-slate-600 border border-slate-200 inline-flex items-center gap-1">
                                                    Belum Mulai
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- AKSI (Close, Edit, Hapus) - Tanpa Tombol Inspeksi -->
                                        <td class="px-3 py-3 text-right whitespace-nowrap">
                                            <div class="inline-flex items-center justify-end gap-1.5">
                                                <?php if (!$isCompleted): ?>
                                                    <a href="<?= base_url('modules/inspection/close_kanban.php?kanban_id=' . $kid) ?>"
                                                       style="background-color: #ffffff; color: #475569; border: 1.5px solid #cbd5e1;"
                                                       onmouseover="this.style.borderColor='#86efac'; this.style.backgroundColor='#f0fdf4'; this.style.color='#15803d';"
                                                       onmouseout="this.style.borderColor='#cbd5e1'; this.style.backgroundColor='#ffffff'; this.style.color='#475569';"
                                                       class="px-2.5 py-1 font-extrabold text-xs rounded-md shadow-2xs transition-all inline-flex items-center gap-1 cursor-pointer"
                                                       title="Tutup / Selesaikan Kanban">
                                                        <svg class="w-3 h-3 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                                        <span>Close</span>
                                                    </a>
                                                <?php endif; ?>

                                                <a href="<?= base_url('modules/kanban/edit.php?id=' . $it['id']) ?>" 
                                                   class="p-1 text-blue-600 hover:bg-blue-50 rounded-md transition-colors" title="Edit Data Kanban">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                    </svg>
                                                </a>

                                                <button type="button" 
                                                        onclick="confirmPostDelete('<?= base_url('modules/kanban/delete.php') ?>', <?= (int)$it['id'] ?>, 'Kanban No <?= htmlspecialchars($it['kanban_no'] ?? '', ENT_QUOTES) ?>', '<?= generate_csrf_token() ?>')"
                                                        class="p-1 text-rose-600 hover:bg-rose-50 rounded-md transition-colors" title="Hapus Item">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                    </svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Footer -->
                <?= render_pagination($page, $totalPages, $totalItems, $limit) ?>
            </div>
            <?php endif; ?>

        <?php else: ?>
            <!-- ===================================================================== -->
            <!-- MODE PERIODE LAIN (MINGGU INI, BULAN INI, TAHUN INI): REKAP PER TGL   -->
            <!-- ===================================================================== -->
            <div class="card p-0 overflow-hidden bg-white border border-slate-200/80 rounded-xl shadow-2xs">
                <div class="px-4 py-3 bg-slate-50/80 border-b border-slate-200 flex items-center justify-between">
                    <h2 class="text-xs font-extrabold text-slate-800 uppercase tracking-wider flex items-center">
                        <svg class="w-4 h-4 mr-1.5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                        Ringkasan Jadwal Kanban Per Tanggal
                    </h2>
                    <span class="text-[11px] font-bold text-slate-500">
                        Total: <?= number_format($totalItems) ?> Hari Terdaftar
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs text-slate-700">
                        <thead class="bg-slate-50 text-slate-600 font-bold border-b border-slate-200/80 uppercase tracking-wider text-[10px]">
                            <tr>
                                <th class="px-4 py-3">No</th>
                                <th class="px-4 py-3">Tanggal Input / Import</th>
                                <th class="px-4 py-3">Tipe Planning</th>
                                <th class="px-4 py-3">Jumlah Batch</th>
                                <th class="px-4 py-3">Total Item</th>
                                <th class="px-4 py-3">Total Qty (pcs)</th>
                                <th class="px-4 py-3">Waktu Input</th>
                                <th class="px-4 py-3 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (empty($days)): ?>
                                <tr>
                                    <td colspan="8" class="px-4 py-8 text-center text-slate-400">
                                        Belum ada data Planning Inspeksi yang sesuai dengan filter.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($days as $index => $d): ?>
                                    <tr class="hover:bg-slate-50/80 transition-colors">
                                        <td class="px-4 py-3 font-semibold text-slate-400"><?= $offset + $index + 1 ?></td>

                                        <!-- Tanggal -->
                                        <td class="px-4 py-3">
                                            <div class="font-extrabold text-slate-800 text-sm">
                                                <?= date('d M Y', strtotime($d['plan_date'])) ?>
                                            </div>
                                            <div class="text-[10px] text-slate-400 font-medium mt-0.5">
                                                <?= date('l', strtotime($d['plan_date'])) ?>
                                            </div>
                                        </td>

                                        <!-- Tipe Planning Badges -->
                                        <td class="px-4 py-3">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold bg-blue-100 text-blue-800 border border-blue-200 w-fit">
                                                Kanban (Kirim)
                                            </span>
                                        </td>

                                        <!-- Jumlah Batch -->
                                        <td class="px-4 py-3 font-bold text-slate-700">
                                            <?= (int)$d['batch_count'] ?> Batch
                                        </td>

                                        <!-- Total Item -->
                                        <td class="px-4 py-3 font-bold text-slate-700">
                                            <?= number_format($d['total_items']) ?> Item
                                        </td>

                                        <!-- Total Qty -->
                                        <td class="px-4 py-3 font-extrabold text-slate-800">
                                            <?= number_format($d['total_pcs'] ?? 0) ?> pcs
                                        </td>

                                        <!-- Waktu Input Range -->
                                        <td class="px-4 py-3 text-[11px] text-slate-500 font-medium">
                                            <?php if ($d['first_import'] === $d['last_import']): ?>
                                                <?= date('H:i', strtotime($d['first_import'])) ?> WIB
                                            <?php else: ?>
                                                <?= date('H:i', strtotime($d['first_import'])) ?> &ndash; <?= date('H:i', strtotime($d['last_import'])) ?> WIB
                                            <?php endif; ?>
                                        </td>

                                        <!-- Aksi -->
                                        <td class="px-4 py-3 text-right">
                                            <a href="<?= base_url('modules/kanban/daily_detail.php?date=' . urlencode($d['plan_date']) . (!empty($selectedCustomer) ? '&customer=' . urlencode($selectedCustomer) : '')) ?>"
                                               class="btn-secondary py-1 px-2.5 text-xs inline-flex items-center" title="Lihat Detail Planning Hari Ini">
                                                <svg class="w-3.5 h-3.5 mr-1 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                                </svg>
                                                Detail Hari
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Footer -->
                <?= render_pagination($page, $totalPages, $totalItems, $limit) ?>
            </div>
        <?php endif; ?>

    </main>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
