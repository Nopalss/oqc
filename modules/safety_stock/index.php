<?php
/**
 * Modul Safety Stock - Monitoring Realisasi Safety Stock
 * Halaman khusus untuk memantau data barang Safety Stock / Internal Stock yang telah diinspeksi OQC.
 */
$breadcrumbCategory = "OPERASIONAL";
$pageTitle = "Monitoring Realisasi Safety Stock";
$pageSubtitle = "Daftar & status barang Safety Stock / Internal Stock yang telah diinspeksi OQC dan memiliki Lot Number";

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

$pdo = getDB();

// Default Filter Values
$startDate    = sanitize($_GET['start_date'] ?? date('Y-m-01'));
$endDate      = sanitize($_GET['end_date'] ?? date('Y-m-d'));
$search       = sanitize($_GET['search'] ?? '');
$customer     = sanitize($_GET['customer'] ?? '');
$statusFilter = sanitize($_GET['status'] ?? '');

// Dynamic Pagination Limit
$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT) ?: 10;
if (!in_array($limit, [5, 10, 25, 50, 100])) {
    $limit = 10;
}
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
if ($page < 1) $page = 1;

$summary = [
    'total_plan'   => 0,
    'total_pcs'    => 0,
    'passed'       => 0,
    'rejected'     => 0,
    'in_progress'  => 0
];

$items = [];
$totalItems = 0;
$totalPages = 1;
$customersList = [];

if ($pdo) {
    try {
        // Fetch Master Customers for dropdown filter
        $stCust = $pdo->query("SELECT DISTINCT name FROM master_customers ORDER BY name ASC");
        $customersList = $stCust->fetchAll(PDO::FETCH_COLUMN);

        // Build WHERE clause strictly for Safety Stock / Internal Stock items
        $where = ["(ki.plan_type = 'safety_stock' OR b.plan_type = 'safety_stock' OR ss.inspection_type = 'safety_stock' OR ki.kanban_no LIKE 'SS%' OR UPPER(ki.customer) LIKE '%SAFETY%' OR UPPER(ki.customer) LIKE '%INTERNAL%' OR UPPER(b.vendor) LIKE '%INTERNAL%' OR UPPER(b.vendor) LIKE '%SAFETY%')"];
        $params = [];

        // Apply Date Range Filter if set
        if (!empty($startDate) && !empty($endDate)) {
            $where[] = "DATE(COALESCE(ss.started_at, b.imported_at, ki.created_at)) BETWEEN :sd AND :ed";
            $params[':sd'] = $startDate;
            $params[':ed'] = $endDate;
        }

        if (!empty($search)) {
            $where[] = "(ki.item_code LIKE :s1 OR ki.item_description LIKE :s2 OR ki.kanban_no LIKE :s3 OR did.lot_number LIKE :s4)";
            $params[':s1'] = '%' . $search . '%';
            $params[':s2'] = '%' . $search . '%';
            $params[':s3'] = '%' . $search . '%';
            $params[':s4'] = '%' . $search . '%';
        }

        if (!empty($customer)) {
            $where[] = "ki.customer = :customer";
            $params[':customer'] = $customer;
        }

        $whereStr = implode(" AND ", $where);

        // Fetch All Matching Items for KPI Summary Calculation (STRICTLY HAVING session_count > 0)
        $sumSql = "SELECT 
                    ki.id AS item_id,
                    ki.qty AS planned_qty,
                    COUNT(ss.id) AS session_count,
                    SUM(CASE WHEN ss.status = 'rejected' THEN 1 ELSE 0 END) AS count_rejected,
                    SUM(CASE WHEN ss.status = 'in_progress' THEN 1 ELSE 0 END) AS count_in_progress,
                    SUM(CASE WHEN ss.status = 'passed' THEN 1 ELSE 0 END) AS count_passed
                   FROM kanban_items ki
                   LEFT JOIN kanban_batches b ON ki.batch_id = b.id
                   JOIN inspection_sessions ss ON (ss.kanban_item_id = ki.id OR ss.did_id = ki.id)
                   LEFT JOIN daily_inspection_data did ON ss.did_id = did.id
                   WHERE {$whereStr}
                   GROUP BY ki.id, ki.qty
                   HAVING session_count > 0";
        $stSum = $pdo->prepare($sumSql);
        $stSum->execute($params);
        $allSumRows = $stSum->fetchAll(PDO::FETCH_ASSOC);

        $summary['total_plan'] = count($allSumRows);
        foreach ($allSumRows as $sr) {
            $summary['total_pcs'] += (int)$sr['planned_qty'];
            if ($sr['count_rejected'] > 0) {
                $summary['rejected']++;
            } elseif ($sr['count_in_progress'] > 0) {
                $summary['in_progress']++;
            } elseif ($sr['count_passed'] > 0) {
                $summary['passed']++;
            }
        }

        // Apply Status Filter in HAVING clause (ALWAYS session_count > 0)
        $havingStr = " HAVING session_count > 0 ";
        if ($statusFilter === 'in_progress') {
            $havingStr .= " AND count_in_progress > 0 AND count_rejected = 0 ";
        } elseif ($statusFilter === 'passed') {
            $havingStr .= " AND count_passed > 0 AND count_in_progress = 0 AND count_rejected = 0 ";
        } elseif ($statusFilter === 'rejected') {
            $havingStr .= " AND count_rejected > 0 ";
        }

        // Count Total Paginated Items
        $countSql = "SELECT COUNT(*) FROM (
                        SELECT ki.id,
                               COUNT(ss.id) AS session_count,
                               SUM(CASE WHEN ss.status = 'rejected' THEN 1 ELSE 0 END) AS count_rejected,
                               SUM(CASE WHEN ss.status = 'in_progress' THEN 1 ELSE 0 END) AS count_in_progress,
                               SUM(CASE WHEN ss.status = 'passed' THEN 1 ELSE 0 END) AS count_passed
                        FROM kanban_items ki
                        LEFT JOIN kanban_batches b ON ki.batch_id = b.id
                        JOIN inspection_sessions ss ON (ss.kanban_item_id = ki.id OR ss.did_id = ki.id)
                        LEFT JOIN daily_inspection_data did ON ss.did_id = did.id
                        WHERE {$whereStr}
                        GROUP BY ki.id
                        {$havingStr}
                     ) t";
        $stCount = $pdo->prepare($countSql);
        $stCount->execute($params);
        $totalItems = (int)$stCount->fetchColumn();

        $totalPages = max(1, ceil($totalItems / $limit));
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $limit;

        // Main Paginated Query
        $mainSql = "SELECT 
                        ki.id AS item_id, ki.item_code, ki.item_description, ki.qty AS planned_qty, 
                        ki.customer, ki.plan_type, ki.kanban_no, ki.req_date, ki.str_loc, ki.supply_area,
                        COALESCE(b.document_number, 'DOC-SAFETY-STOCK') AS document_number, 
                        COALESCE(b.vendor, 'Safety Stock Storage') AS vendor, 
                        COALESCE(b.imported_at, ki.created_at) AS plan_date,
                        COUNT(ss.id) AS session_count,
                        MAX(ss.id) AS last_session_id,
                        SUM(CASE WHEN ss.status = 'rejected' THEN 1 ELSE 0 END) AS count_rejected,
                        SUM(CASE WHEN ss.status = 'in_progress' THEN 1 ELSE 0 END) AS count_in_progress,
                        SUM(CASE WHEN ss.status = 'passed' THEN 1 ELSE 0 END) AS count_passed,
                        MAX(ss.samples_checked) AS samples_checked,
                        MAX(ss.sample_size) AS sample_size,
                        MAX(ss.ng_count) AS ng_count,
                        MIN(ss.started_at) AS first_started_at,
                        MAX(ss.closed_at) AS last_closed_at,
                        COALESCE(MAX(u.name), MAX(did.pic), '-') AS inspector_name,
                        COALESCE(MAX(did.lot_number), '-') AS lot_number
                    FROM kanban_items ki
                    LEFT JOIN kanban_batches b ON ki.batch_id = b.id
                    JOIN inspection_sessions ss ON (ss.kanban_item_id = ki.id OR ss.did_id = ki.id)
                    LEFT JOIN users u ON ss.inspector_id = u.id
                    LEFT JOIN daily_inspection_data did ON ss.did_id = did.id
                    WHERE {$whereStr}
                    GROUP BY ki.id, ki.item_code, ki.item_description, ki.qty, ki.customer, ki.plan_type, ki.kanban_no, ki.str_loc, ki.supply_area, ki.req_date, b.document_number, b.vendor, b.imported_at, ki.created_at
                    {$havingStr}
                    ORDER BY 
                        CASE 
                            WHEN SUM(CASE WHEN ss.status = 'in_progress' THEN 1 ELSE 0 END) > 0 THEN 1
                            WHEN SUM(CASE WHEN ss.status = 'rejected' THEN 1 ELSE 0 END) > 0 THEN 2
                            ELSE 3 
                        END ASC,
                        ki.id DESC
                    LIMIT :limit OFFSET :offset";
        $stMain = $pdo->prepare($mainSql);
        foreach ($params as $k => $v) {
            $stMain->bindValue($k, $v);
        }
        $stMain->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stMain->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stMain->execute();
        $items = $stMain->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        set_flash('error', 'Gagal memuat data Safety Stock: ' . $e->getMessage());
    }
}

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<!-- Main Content Wrapper with Sidebar Offset (md:pl-64) -->
<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen min-w-0 w-full overflow-x-hidden transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-4 space-y-3 min-w-0 w-full overflow-x-hidden">
        
        <?= get_flash() ?>

        <!-- 1. Header Banner Card -->
        <div style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); border-radius: 12px; padding: 12px 16px; color: #ffffff; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="background-color: #2563eb; padding: 8px; border-radius: 10px; color: #ffffff; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                    </svg>
                </div>
                <div>
                    <h2 style="font-size: 13px; font-weight: 800; margin: 0; line-height: 1.2;">Monitoring Realisasi Safety Stock</h2>
                    <p style="font-size: 11px; color: #cbd5e1; font-weight: 500; margin-top: 3px;">
                        Daftar & status barang Safety Stock / Internal Stock yang telah diinspeksi OQC dan memiliki Lot Number
                    </p>
                </div>
            </div>

            <a href="<?= base_url('modules/safety_stock/index.php') ?>" style="background-color: #334155; color: #ffffff; border-radius: 8px; padding: 6px 12px; font-size: 11px; font-weight: 700; border: none; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;" title="Refresh Data">
                <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                </svg>
                <span>Refresh Data</span>
            </a>
        </div>

        <!-- 2. STRICT 1-ROW 4 KPI CARDS (HANYA UNTUK ITEM YANG SUDAH DIINSPEKSI) -->
        <div style="width: 100%; overflow-x: auto; padding-bottom: 2px;">
            <div style="display: flex; align-items: stretch; gap: 10px; width: 100%; min-width: 700px;">
                
                <!-- Card 1: Total Part Safety Stock Inspected -->
                <div style="flex: 1; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 14px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                    <div style="display: flex; align-items: center; justify-content: space-between;">
                        <span style="font-size: 10px; font-weight: 800; color: #64748b; letter-spacing: 0.5px;">SAFETY STOCK DICEK</span>
                        <span style="width: 8px; height: 8px; border-radius: 9999px; background-color: #2563eb; display: inline-block;"></span>
                    </div>
                    <div style="display: flex; align-items: baseline; gap: 4px; margin-top: 4px;">
                        <span style="font-size: 20px; font-weight: 900; color: #0f172a; line-height: 1;"><?= number_format($summary['total_plan']) ?></span>
                        <span style="font-size: 11px; font-weight: 700; color: #64748b;">part</span>
                    </div>
                    <div style="font-size: 10px; color: #64748b; font-weight: 500; margin-top: 6px; border-top: 1px solid #f1f5f9; padding-top: 4px;">
                        Status: <b style="color: #1e293b;">Sudah Diinspeksi</b>
                    </div>
                </div>

                <!-- Card 2: Total Qty Stock (Pcs) -->
                <div style="flex: 1; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 14px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                    <div style="display: flex; align-items: center; justify-content: space-between;">
                        <span style="font-size: 10px; font-weight: 800; color: #64748b; letter-spacing: 0.5px;">TOTAL QTY STOCK</span>
                        <span style="width: 8px; height: 8px; border-radius: 9999px; background-color: #16a34a; display: inline-block;"></span>
                    </div>
                    <div style="display: flex; align-items: baseline; gap: 4px; margin-top: 4px;">
                        <span style="font-size: 20px; font-weight: 900; color: #0f172a; line-height: 1;"><?= number_format($summary['total_pcs']) ?></span>
                        <span style="font-size: 11px; font-weight: 700; color: #64748b;">pcs</span>
                    </div>
                    <div style="font-size: 10px; color: #64748b; font-weight: 500; margin-top: 6px; border-top: 1px solid #f1f5f9; padding-top: 4px;">
                        Gudang: <b style="color: #16a34a;">Stock WH</b>
                    </div>
                </div>

                <!-- Card 3: PASSED Inspeksi -->
                <div style="flex: 1; background-color: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px; padding: 10px 14px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                    <div style="display: flex; align-items: center; justify-content: space-between;">
                        <span style="font-size: 10px; font-weight: 800; color: #166534; letter-spacing: 0.5px;">PASSED (LOLOS)</span>
                        <span style="width: 8px; height: 8px; border-radius: 9999px; background-color: #16a34a; display: inline-block;"></span>
                    </div>
                    <div style="display: flex; align-items: baseline; gap: 4px; margin-top: 4px;">
                        <span style="font-size: 20px; font-weight: 900; color: #14532d; line-height: 1;"><?= number_format($summary['passed']) ?></span>
                        <span style="font-size: 11px; font-weight: 700; color: #166534;">item</span>
                    </div>
                    <div style="font-size: 10px; color: #166534; font-weight: 500; margin-top: 6px; border-top: 1px solid #dcfce7; padding-top: 4px;">
                        Keterangan: <b style="color: #14532d;">OK</b>
                    </div>
                </div>

                <!-- Card 4: REJECTED (NG) -->
                <div style="flex: 1; background-color: #fff1f2; border: 1px solid #fecdd3; border-radius: 12px; padding: 10px 14px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                    <div style="display: flex; align-items: center; justify-content: space-between;">
                        <span style="font-size: 10px; font-weight: 800; color: #9f1239; letter-spacing: 0.5px;">REJECTED (NG)</span>
                        <span style="width: 8px; height: 8px; border-radius: 9999px; background-color: #e11d48; display: inline-block;"></span>
                    </div>
                    <div style="display: flex; align-items: baseline; gap: 4px; margin-top: 4px;">
                        <span style="font-size: 20px; font-weight: 900; color: #881337; line-height: 1;"><?= number_format($summary['rejected']) ?></span>
                        <span style="font-size: 11px; font-weight: 700; color: #9f1239;">item</span>
                    </div>
                    <div style="font-size: 10px; color: #9f1239; font-weight: 500; margin-top: 6px; border-top: 1px solid #ffe4e6; padding-top: 4px;">
                        Keterangan: <b style="color: #9f1239;">Defect</b>
                    </div>
                </div>

                <!-- Card 5: IN PROGRESS / Oper Shift -->
                <div style="flex: 1; background-color: #fffbe6; border: 1px solid #ffe58f; border-radius: 12px; padding: 10px 14px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                    <div style="display: flex; align-items: center; justify-content: space-between;">
                        <span style="font-size: 10px; font-weight: 800; color: #92400e; letter-spacing: 0.5px;">IN PROGRESS</span>
                        <span style="width: 8px; height: 8px; border-radius: 9999px; background-color: #d97706; display: inline-block;"></span>
                    </div>
                    <div style="display: flex; align-items: baseline; gap: 4px; margin-top: 4px;">
                        <span style="font-size: 20px; font-weight: 900; color: #78350f; line-height: 1;"><?= number_format($summary['in_progress']) ?></span>
                        <span style="font-size: 11px; font-weight: 700; color: #92400e;">item</span>
                    </div>
                    <div style="font-size: 10px; color: #92400e; font-weight: 500; margin-top: 6px; border-top: 1px solid #fef3c7; padding-top: 4px;">
                        Status: <b style="color: #78350f;">Oper Shift</b>
                    </div>
                </div>

            </div>
        </div>

        <!-- 3. STRICT 1-ROW FILTER BAR (Inputs Side-by-Side) -->
        <div style="background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 14px; overflow-x: auto; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
            <form action="<?= base_url('modules/safety_stock/index.php') ?>" method="GET" style="display: flex; align-items: center; gap: 8px; min-width: 880px;">
                
                <!-- Search Input -->
                <div style="width: 210px; flex-shrink: 0;">
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Cari Part Code, Lot, Customer..." style="width: 100%; padding: 6px 10px; background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 12px; color: #1e293b; font-weight: 500; outline: none;">
                </div>

                <!-- Customer Filter -->
                <select name="customer" onchange="this.form.submit()" style="width: 150px; padding: 6px 10px; background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 12px; color: #334155; font-weight: 600; outline: none; flex-shrink: 0;">
                    <option value="">Semua Customer</option>
                    <?php foreach ($customersList as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>" <?= ($customer === $c) ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                    <?php endforeach; ?>
                </select>

                <!-- Status Filter -->
                <select name="status" onchange="this.form.submit()" style="width: 140px; padding: 6px 10px; background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 12px; color: #334155; font-weight: 600; outline: none; flex-shrink: 0;">
                    <option value="">Semua Status</option>
                    <option value="passed" <?= ($statusFilter === 'passed') ? 'selected' : '' ?>>Passed</option>
                    <option value="rejected" <?= ($statusFilter === 'rejected') ? 'selected' : '' ?>>Rejected</option>
                    <option value="in_progress" <?= ($statusFilter === 'in_progress') ? 'selected' : '' ?>>In Progress</option>
                </select>

                <!-- Start Date -->
                <div style="display: flex; align-items: center; gap: 4px; flex-shrink: 0;">
                    <span style="font-size: 11px; font-weight: 700; color: #64748b;">Dari:</span>
                    <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" style="padding: 5px 8px; background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 11px; color: #334155; font-weight: 600; outline: none;">
                </div>

                <!-- End Date -->
                <div style="display: flex; align-items: center; gap: 4px; flex-shrink: 0;">
                    <span style="font-size: 11px; font-weight: 700; color: #64748b;">s/d:</span>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" style="padding: 5px 8px; background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 11px; color: #334155; font-weight: 600; outline: none;">
                </div>

                <!-- Action Buttons -->
                <button type="submit" style="padding: 6px 14px; background-color: #2563eb; color: #ffffff; font-weight: 700; font-size: 12px; border-radius: 8px; border: none; cursor: pointer; flex-shrink: 0;">
                    Cari
                </button>

                <a href="<?= base_url('modules/safety_stock/index.php') ?>" style="padding: 6px 10px; background-color: #f1f5f9; color: #64748b; font-weight: 700; font-size: 12px; border-radius: 8px; border: 1px solid #cbd5e1; text-decoration: none; flex-shrink: 0;">
                    Reset
                </a>

            </form>
        </div>

        <!-- 4. MAIN TABLE REALISASI SAFETY STOCK -->
        <div style="background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
            
            <div style="padding: 12px 16px; background-color: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <h2 style="font-size: 12px; font-weight: 800; color: #334155; text-transform: uppercase; letter-spacing: 0.5px; margin: 0;">
                        Daftar Realisasi Safety Stock
                    </h2>
                    <span style="padding: 2px 8px; background-color: #dbeafe; color: #1d4ed8; font-size: 11px; font-weight: 800; border-radius: 9999px;">
                        <?= number_format($totalItems) ?> Record
                    </span>
                </div>

                <!-- Dynamic Limit Dropdown -->
                <form method="GET" action="<?= base_url('modules/safety_stock/index.php') ?>" style="display: flex; align-items: center; gap: 8px; font-size: 12px;">
                    <input type="hidden" name="start_date" value="<?= htmlspecialchars($startDate) ?>">
                    <input type="hidden" name="end_date" value="<?= htmlspecialchars($endDate) ?>">
                    <input type="hidden" name="customer" value="<?= htmlspecialchars($customer) ?>">
                    <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
                    <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">

                    <span style="color: #64748b; font-weight: 500;">Tampilkan:</span>
                    <select name="limit" onchange="this.form.submit()" style="padding: 4px 8px; background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; font-weight: 700; color: #334155; outline: none;">
                        <option value="5" <?= ($limit == 5) ? 'selected' : '' ?>>5</option>
                        <option value="10" <?= ($limit == 10) ? 'selected' : '' ?>>10</option>
                        <option value="25" <?= ($limit == 25) ? 'selected' : '' ?>>25</option>
                        <option value="50" <?= ($limit == 50) ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= ($limit == 100) ? 'selected' : '' ?>>100</option>
                    </select>
                </form>
            </div>

            <div style="overflow-x-auto;">
                <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 12px; color: #334155;">
                    <thead style="background-color: #ffffff; border-bottom: 1px solid #e2e8f0; font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase;">
                        <tr>
                            <th style="padding: 10px 14px; width: 40px; text-align: center;">No</th>
                            <th style="padding: 10px 14px; min-width: 180px;">Kode & Nama Part</th>
                            <th style="padding: 10px 14px; min-width: 140px;">Lot Number</th>
                            <th style="padding: 10px 14px; min-width: 140px;">Customer</th>
                            <th style="padding: 10px 14px; text-align: center; width: 110px;">Qty Stock</th>
                            <th style="padding: 10px 14px; width: 110px;">Lokasi Gudang</th>
                            <th style="padding: 10px 14px; width: 120px;">Status Inspeksi</th>
                            <th style="padding: 10px 14px; min-width: 130px;">Inspektur / PIC</th>
                            <th style="padding: 10px 14px; min-width: 130px;">Tgl Inspeksi</th>
                            <th style="padding: 10px 14px; text-align: right; width: 90px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                            <tr>
                                <td colspan="10" style="padding: 48px; text-align: center; color: #94a3b8;">
                                    <div style="display: flex; flex-direction: column; align-items: center; justify-content: center;">
                                        <svg style="width: 48px; height: 48px; color: #cbd5e1; margin-bottom: 8px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                        </svg>
                                        <span style="font-weight: 800; color: #334155; font-size: 14px;">Belum Ada Data Realisasi Safety Stock</span>
                                        <span style="font-size: 12px; color: #64748b; margin-top: 4px; max-width: 480px; line-height: 1.4;">
                                            Halaman ini khusus menampilkan barang <b>Safety Stock / Internal Stock</b> yang <b>telah diinspeksi OQC</b> dan memiliki <b>Lot Number</b>. Item Safety Stock yang sudah mulai diinspeksi (berstatus Passed/Rejected/In Progress) akan otomatis muncul di sini.
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($items as $idx => $it): ?>
                                <?php 
                                    $rowNum = $offset + $idx + 1;
                                    $hasRejected  = ((int)$it['count_rejected'] > 0);
                                    $hasInProg    = ((int)$it['count_in_progress'] > 0);
                                    $hasPassed    = ((int)$it['count_passed'] > 0);

                                    if ($hasRejected) {
                                        $statusBadge = '<span style="background-color: #ffe4e6; color: #9f1239; border: 1px solid #fecdd3; padding: 2px 8px; border-radius: 9999px; font-weight: 700; font-size: 10px;">REJECTED</span>';
                                    } elseif ($hasInProg) {
                                        $statusBadge = '<span style="background-color: #fffbe6; color: #92400e; border: 1px solid #ffe58f; padding: 2px 8px; border-radius: 9999px; font-weight: 700; font-size: 10px;">IN PROGRESS</span>';
                                    } elseif ($hasPassed) {
                                        $statusBadge = '<span style="background-color: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; padding: 2px 8px; border-radius: 9999px; font-weight: 700; font-size: 10px;">PASSED</span>';
                                    } else {
                                        $statusBadge = '<span style="background-color: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; padding: 2px 8px; border-radius: 9999px; font-weight: 700; font-size: 10px;">PASSED</span>';
                                    }
                                ?>
                                <tr style="border-bottom: 1px solid #f1f5f9;">
                                    <td style="padding: 10px 14px; text-align: center; font-weight: 700; color: #64748b;"><?= $rowNum ?></td>
                                    
                                    <!-- Kode & Nama Part -->
                                    <td style="padding: 10px 14px;">
                                        <div style="font-weight: 800; color: #1d4ed8; font-family: monospace; font-size: 12px; margin-bottom: 2px;">
                                            <?= htmlspecialchars($it['item_code']) ?>
                                        </div>
                                        <div style="font-weight: 700; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 220px;" title="<?= htmlspecialchars($it['item_description']) ?>">
                                            <?= htmlspecialchars($it['item_description']) ?>
                                        </div>
                                    </td>

                                    <!-- Lot Number -->
                                    <td style="padding: 10px 14px;">
                                        <span style="font-family: monospace; font-weight: 700; color: #334155; background-color: #f1f5f9; padding: 2px 8px; border-radius: 4px; border: 1px solid #e2e8f0; display: inline-block; font-size: 11px;">
                                            <?= htmlspecialchars($it['lot_number'] ?: '-') ?>
                                        </span>
                                    </td>

                                    <!-- Customer -->
                                    <td style="padding: 10px 14px; font-weight: 600; color: #334155;">
                                        <?= htmlspecialchars($it['customer'] ?: '-') ?>
                                    </td>

                                    <!-- Qty Stock -->
                                    <td style="padding: 10px 14px; text-align: center; font-family: monospace; font-weight: 900; color: #0f172a;">
                                        <?= number_format($it['planned_qty']) ?> pcs
                                    </td>

                                    <!-- Lokasi Gudang -->
                                    <td style="padding: 10px 14px; font-family: monospace; font-weight: 600; color: #475569;">
                                        <?= htmlspecialchars($it['str_loc'] ?: 'WH-SAFETY') ?>
                                    </td>

                                    <!-- Status Inspeksi -->
                                    <td style="padding: 10px 14px;">
                                        <?= $statusBadge ?>
                                    </td>

                                    <!-- Inspektur / PIC -->
                                    <td style="padding: 10px 14px; font-weight: 600; color: #334155;">
                                        <div style="display: flex; align-items: center; gap: 6px;">
                                            <span style="width: 20px; height: 20px; border-radius: 9999px; background-color: #dbeafe; color: #1d4ed8; font-weight: 800; font-size: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                                <?= strtoupper(substr($it['inspector_name'] ?: 'Q', 0, 1)) ?>
                                            </span>
                                            <span style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 110px;"><?= htmlspecialchars($it['inspector_name'] ?: '-') ?></span>
                                        </div>
                                    </td>

                                    <!-- Tanggal Inspeksi -->
                                    <td style="padding: 10px 14px; color: #64748b; font-weight: 500; font-size: 11px;">
                                        <?php if (!empty($it['first_started_at'])): ?>
                                            <?= date('d M Y, H:i', strtotime($it['first_started_at'])) ?>
                                        <?php else: ?>
                                            <span style="color: #94a3b8;">-</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Aksi -->
                                    <td style="padding: 10px 14px; text-align: right; white-space: nowrap;">
                                        <a href="<?= base_url('modules/safety_stock/detail.php?id=' . $it['item_id']) ?>" 
                                           style="padding: 6px 12px; background-color: #eff6ff; color: #1d4ed8; font-weight: 700; font-size: 11px; border-radius: 8px; border: 1px solid #bfdbfe; display: inline-flex; align-items: center; gap: 4px; text-decoration: none;" 
                                           title="Lihat Detail Realisasi Safety Stock">
                                            <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                            </svg>
                                            <span>Detail</span>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- PAGINATION FOOTER -->
            <?php if ($totalPages > 1): ?>
                <div style="padding: 12px 16px; border-top: 1px solid #e2e8f0; background-color: #f8fafc; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; font-size: 12px;">
                    <div style="color: #64748b;">
                        Menampilkan halaman <b style="color: #1e293b;"><?= $page ?></b> dari <b style="color: #1e293b;"><?= $totalPages ?></b> (Total <?= number_format($totalItems) ?> item)
                    </div>

                    <div style="display: flex; align-items: center; gap: 4px;">
                        <?php 
                            $queryParams = $_GET; 
                            $prevPage = max(1, $page - 1);
                            $nextPage = min($totalPages, $page + 1);
                            $queryParams['page'] = $prevPage;
                            $prevUrl = base_url('modules/safety_stock/index.php?') . http_build_query($queryParams);
                            $queryParams['page'] = $nextPage;
                            $nextUrl = base_url('modules/safety_stock/index.php?') . http_build_query($queryParams);
                        ?>

                        <a href="<?= $prevUrl ?>" style="padding: 6px 12px; background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; color: #334155; font-weight: 700; text-decoration: none; <?= ($page <= 1) ? 'pointer-events-none opacity-50' : '' ?>">
                            &laquo; Prev
                        </a>

                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                            <?php 
                                if ($p > 1 && $p < $totalPages && abs($p - $page) > 2) continue;
                                $queryParams['page'] = $p;
                                $pUrl = base_url('modules/safety_stock/index.php?') . http_build_query($queryParams);
                            ?>
                            <a href="<?= $pUrl ?>" style="padding: 6px 12px; border-radius: 6px; font-weight: 800; text-decoration: none; <?= ($p == $page) ? 'background-color: #2563eb; color: #ffffff; border: 1px solid #2563eb;' : 'background-color: #ffffff; color: #334155; border: 1px solid #cbd5e1;' ?>">
                                <?= $p ?>
                            </a>
                        <?php endfor; ?>

                        <a href="<?= $nextUrl ?>" style="padding: 6px 12px; background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; color: #334155; font-weight: 700; text-decoration: none; <?= ($page >= $totalPages) ? 'pointer-events-none opacity-50' : '' ?>">
                            Next &raquo;
                        </a>
                    </div>
                </div>
            <?php endif; ?>

        </div>

    </main>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
