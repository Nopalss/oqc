<?php
/**
 * Modul Safety Stock - Monitoring Realisasi Safety Stock
 * Halaman pemantauan data barang Safety Stock / Internal Stock berbasis Per Part.
 */
$breadcrumbCategory = "OPERASIONAL";
$pageTitle = "Monitoring Safety Stock";
$pageSubtitle = "Daftar stok pengaman per part, ketersediaan box di gudang, dan alokasi pemotongan Kanban";

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

require_menu_access('safety_stock');

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
    'total_parts'     => 0,
    'total_boxes'     => 0,
    'total_initial'   => 0,
    'total_used'      => 0,
    'total_available' => 0
];

$items = [];
$totalItems = 0;
$totalPages = 1;
$customersList = [];
$boxMap = [];

if ($pdo) {
    try {
        // Fetch Master Customers for dropdown filter
        $stCust = $pdo->query("SELECT DISTINCT name FROM master_customers WHERE UPPER(name) NOT LIKE '%SAFETY STOCK%' AND UPPER(name) NOT LIKE '%INTERNAL STOCK%' ORDER BY name ASC");
        $customersList = $stCust->fetchAll(PDO::FETCH_COLUMN);

        // Build WHERE clause strictly for Safety Stock inspection sessions
        $where = ["ss.inspection_type = 'safety_stock'"];
        $params = [];

        // Apply Date Range Filter if set
        if (!empty($startDate) && !empty($endDate)) {
            $where[] = "DATE(COALESCE(ss.started_at, did.created_at)) BETWEEN :sd AND :ed";
            $params[':sd'] = $startDate;
            $params[':ed'] = $endDate;
        }

        if (!empty($search)) {
            $where[] = "(did.part_code LIKE :s1 OR did.part_name LIKE :s2 OR ki.item_code LIKE :s3 OR ki.item_description LIKE :s4)";
            $params[':s1'] = '%' . $search . '%';
            $params[':s2'] = '%' . $search . '%';
            $params[':s3'] = '%' . $search . '%';
            $params[':s4'] = '%' . $search . '%';
        }

        if (!empty($customer)) {
            $where[] = "(ki.customer = :customer OR UPPER(:cust_chk) = 'INTERNAL SAFETY STOCK')";
            $params[':customer'] = $customer;
            $params[':cust_chk'] = $customer;
        }

        $whereStr = implode(" AND ", $where);

        // Grouped SQL Query to fetch Safety Stock grouped strictly by Part Code
        $groupedSql = "SELECT 
                        UPPER(COALESCE(did.part_code, ki.item_code, '-')) AS part_code,
                        MIN(COALESCE(did.part_name, ki.item_description, 'Part Safety Stock')) AS part_name,
                        MIN(COALESCE(ki.customer, 'INTERNAL SAFETY STOCK')) AS customer,
                        MAX(ss.started_at) AS last_started_at,
                        MAX(ss.id) AS max_session_id,
                        COUNT(DISTINCT ss.id) AS session_count,
                        SUM(ss.total_scanned_qty) AS total_initial_qty,
                        SUM(CASE WHEN ss.auto_fulfilled_by_session_id > 0 THEN ss.total_scanned_qty ELSE 0 END) AS total_used_qty,
                        SUM(CASE WHEN (ss.auto_fulfilled_by_session_id IS NULL OR ss.auto_fulfilled_by_session_id = 0) AND ss.status = 'passed' THEN ss.total_scanned_qty ELSE 0 END) AS available_qty,
                        CASE 
                            WHEN SUM(CASE WHEN ss.status = 'in_progress' THEN 1 ELSE 0 END) > 0 THEN 'in_progress'
                            WHEN SUM(CASE WHEN (ss.auto_fulfilled_by_session_id IS NULL OR ss.auto_fulfilled_by_session_id = 0) AND ss.status = 'passed' THEN ss.total_scanned_qty ELSE 0 END) > 0 THEN 'available'
                            WHEN SUM(CASE WHEN ss.status = 'rejected' THEN 1 ELSE 0 END) > 0 THEN 'rejected'
                            ELSE 'exhausted'
                        END AS part_status,
                        COUNT(DISTINCT COALESCE(NULLIF(did.lot_number, ''), ki.kanban_no)) AS lot_count
                       FROM inspection_sessions ss
                       LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
                       LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
                       WHERE {$whereStr}
                       GROUP BY UPPER(COALESCE(did.part_code, ki.item_code, '-'))
                       ORDER BY 
                          CASE 
                              WHEN SUM(CASE WHEN (ss.auto_fulfilled_by_session_id IS NULL OR ss.auto_fulfilled_by_session_id = 0) AND ss.status = 'passed' THEN ss.total_scanned_qty ELSE 0 END) > 0 THEN 1
                              WHEN SUM(CASE WHEN ss.status = 'in_progress' THEN 1 ELSE 0 END) > 0 THEN 2
                              ELSE 3 
                          END ASC,
                          MAX(ss.id) DESC";

        $stAll = $pdo->prepare($groupedSql);
        $stAll->execute($params);
        $allParts = $stAll->fetchAll(PDO::FETCH_ASSOC);

        // Fetch distinct Box counts per Part Code based on latest physical scanned box records (latest per ref_number)
        $stmtBoxes = $pdo->query("
            SELECT 
                UPPER(TRIM(COALESCE(did.part_code, ki.item_code, ''))) AS pcode,
                COUNT(DISTINCT latest_isl.ref_key) AS total_boxes,
                COUNT(DISTINCT CASE 
                    WHEN (ss.auto_fulfilled_by_session_id IS NULL OR ss.auto_fulfilled_by_session_id = 0) 
                    THEN latest_isl.ref_key
                    ELSE NULL
                END) AS active_boxes
            FROM (
                SELECT isl.id, isl.inspection_session_id,
                       COALESCE(NULLIF(TRIM(isl.ref_number), ''), CONCAT('LOT-', isl.id)) AS ref_key
                FROM inspection_session_lots isl
                JOIN inspection_sessions ss2 ON ss2.id = isl.inspection_session_id
                WHERE ss2.inspection_type = 'safety_stock'
                  AND (isl.lot_status IS NULL OR isl.lot_status IN ('ok', 'reinspected', 'passed', ''))
                  AND isl.id IN (
                      SELECT MAX(isl3.id)
                      FROM inspection_session_lots isl3
                      JOIN inspection_sessions ss3 ON ss3.id = isl3.inspection_session_id
                      WHERE ss3.inspection_type = 'safety_stock'
                        AND (isl3.lot_status IS NULL OR isl3.lot_status IN ('ok', 'reinspected', 'passed', ''))
                      GROUP BY COALESCE(NULLIF(TRIM(isl3.ref_number), ''), CONCAT('LOT-', isl3.id))
                  )
            ) latest_isl
            JOIN inspection_sessions ss ON ss.id = latest_isl.inspection_session_id
            LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
            LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
            WHERE ss.inspection_type = 'safety_stock'
            GROUP BY UPPER(TRIM(COALESCE(did.part_code, ki.item_code, '')))
        ");
        if ($stmtBoxes) {
            while ($bRow = $stmtBoxes->fetch(PDO::FETCH_ASSOC)) {
                $boxMap[$bRow['pcode']] = $bRow;
            }
        }

        // Apply Status Filter if specified
        if (!empty($statusFilter)) {
            $allParts = array_filter($allParts, function($p) use ($statusFilter) {
                if ($statusFilter === 'available') return (int)$p['available_qty'] > 0;
                if ($statusFilter === 'exhausted') return (int)$p['available_qty'] === 0 && (int)$p['total_used_qty'] > 0;
                if ($statusFilter === 'in_progress') return $p['part_status'] === 'in_progress';
                if ($statusFilter === 'rejected') return $p['part_status'] === 'rejected';
                return true;
            });
            $allParts = array_values($allParts);
        }

        // Compute Summary KPIs
        $summary['total_parts'] = count($allParts);
        foreach ($allParts as $p) {
            $pCode = strtoupper(trim($p['part_code']));
            $bInfo = $boxMap[$pCode] ?? null;
            $boxCount = ($bInfo && (int)$bInfo['total_boxes'] > 0) ? (int)$bInfo['total_boxes'] : max(1, (int)$p['session_count']);

            $summary['total_boxes']     += $boxCount;
            $summary['total_initial']   += (int)$p['total_initial_qty'];
            $summary['total_used']      += (int)$p['total_used_qty'];
            $summary['total_available'] += (int)$p['available_qty'];
        }

        // Pagination for Grouped Results
        $totalItems = count($allParts);
        $totalPages = max(1, ceil($totalItems / $limit));
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $limit;

        $items = array_slice($allParts, $offset, $limit);

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
        
        <?= render_flash() ?>

        <!-- 1. Header Banner Card -->
        <div style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); border-radius: 12px; padding: 14px 18px; color: #ffffff; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="background-color: #2563eb; padding: 10px; border-radius: 10px; color: #ffffff; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <svg style="width: 22px; height: 22px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                    </svg>
                </div>
                <div>
                    <h2 style="font-size: 14px; font-weight: 800; margin: 0; line-height: 1.2;">Monitoring Safety Stock</h2>
                    <p style="font-size: 11px; color: #cbd5e1; font-weight: 500; margin-top: 3px;">
                        Daftar stok pengaman per part, ketersediaan box di gudang, dan alokasi pemotongan Kanban
                    </p>
                </div>
            </div>

            <a href="<?= base_url('modules/safety_stock/index.php') ?>" style="background-color: #334155; color: #ffffff; border-radius: 8px; padding: 7px 14px; font-size: 11px; font-weight: 700; border: none; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;" title="Refresh Data">
                <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                </svg>
                <span>Refresh Data</span>
            </a>
        </div>

        <!-- 2. KPI CARDS (4 Cards Grid) -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;">
            
            <!-- Card 1: Total Part Safety Stock -->
            <div style="background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <span style="font-size: 10px; font-weight: 800; color: #64748b; letter-spacing: 0.5px; text-transform: uppercase;">Total Part</span>
                    <span style="width: 8px; height: 8px; border-radius: 9999px; background-color: #2563eb; display: inline-block;"></span>
                </div>
                <div style="display: flex; align-items: baseline; gap: 4px; margin-top: 4px;">
                    <span style="font-size: 22px; font-weight: 900; color: #0f172a; line-height: 1;"><?= number_format($summary['total_parts']) ?></span>
                    <span style="font-size: 11px; font-weight: 700; color: #64748b;">part</span>
                </div>
                <div style="font-size: 10px; color: #64748b; font-weight: 500; margin-top: 6px; border-top: 1px solid #f1f5f9; padding-top: 4px;">
                    Kategori: <b style="color: #1e293b;">Master Safety Stock</b>
                </div>
            </div>

            <!-- Card 2: Total Stok Masuk -->
            <div style="background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <span style="font-size: 10px; font-weight: 800; color: #64748b; letter-spacing: 0.5px; text-transform: uppercase;">Total Stok Masuk</span>
                    <span style="width: 8px; height: 8px; border-radius: 9999px; background-color: #0284c7; display: inline-block;"></span>
                </div>
                <div style="display: flex; align-items: baseline; gap: 4px; margin-top: 4px;">
                    <span style="font-size: 22px; font-weight: 900; color: #0f172a; line-height: 1;"><?= number_format($summary['total_initial']) ?></span>
                    <span style="font-size: 11px; font-weight: 700; color: #64748b;">pcs</span>
                </div>
                <div style="font-size: 10px; color: #64748b; font-weight: 500; margin-top: 6px; border-top: 1px solid #f1f5f9; padding-top: 4px;">
                    Akumulasi: <b style="color: #0284c7;">Hasil Inspeksi OQC</b>
                </div>
            </div>

            <!-- Card 3: Terpakai Memotong Kanban -->
            <div style="background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <span style="font-size: 10px; font-weight: 800; color: #64748b; letter-spacing: 0.5px; text-transform: uppercase;">Terpakai Kanban</span>
                    <span style="width: 8px; height: 8px; border-radius: 9999px; background-color: #7c3aed; display: inline-block;"></span>
                </div>
                <div style="display: flex; align-items: baseline; gap: 4px; margin-top: 4px;">
                    <span style="font-size: 22px; font-weight: 900; color: #7c3aed; line-height: 1;"><?= number_format($summary['total_used']) ?></span>
                    <span style="font-size: 11px; font-weight: 700; color: #64748b;">pcs</span>
                </div>
                <div style="font-size: 10px; color: #64748b; font-weight: 500; margin-top: 6px; border-top: 1px solid #f1f5f9; padding-top: 4px;">
                    Alokasi: <b style="color: #7c3aed;">Pemenuhan Order</b>
                </div>
            </div>

            <!-- Card 4: Sisa Stok di Gudang -->
            <div style="background-color: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px; padding: 12px 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <span style="font-size: 10px; font-weight: 800; color: #166534; letter-spacing: 0.5px; text-transform: uppercase;">Sisa Stok Gudang</span>
                    <span style="width: 8px; height: 8px; border-radius: 9999px; background-color: #16a34a; display: inline-block;"></span>
                </div>
                <div style="display: flex; align-items: baseline; gap: 4px; margin-top: 4px;">
                    <span style="font-size: 22px; font-weight: 900; color: #15803d; line-height: 1;"><?= number_format($summary['total_available']) ?></span>
                    <span style="font-size: 11px; font-weight: 700; color: #166534;">pcs</span>
                </div>
                <div style="font-size: 10px; color: #166534; font-weight: 500; margin-top: 6px; border-top: 1px solid #dcfce7; padding-top: 4px;">
                    Status: <b style="color: #15803d;">Tersedia & Siap Pakai</b>
                </div>
            </div>

        </div>

        <!-- 3. FILTER BAR -->
        <div style="background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 14px; overflow-x: auto; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
            <form action="<?= base_url('modules/safety_stock/index.php') ?>" method="GET" style="display: flex; align-items: center; gap: 8px; min-width: 880px;">
                
                <!-- Search Input -->
                <div style="width: 230px; flex-shrink: 0;">
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Cari Kode atau Nama Part..." style="width: 100%; padding: 6px 10px; background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 12px; color: #1e293b; font-weight: 500; outline: none;">
                </div>

                <!-- Customer Filter -->
                <select name="customer" onchange="this.form.submit()" style="width: 160px; padding: 6px 10px; background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 12px; color: #334155; font-weight: 600; outline: none; flex-shrink: 0;">
                    <option value="">Semua Customer</option>
                    <?php foreach ($customersList as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>" <?= ($customer === $c) ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                    <?php endforeach; ?>
                </select>

                <!-- Status Filter -->
                <select name="status" onchange="this.form.submit()" style="width: 150px; padding: 6px 10px; background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 12px; color: #334155; font-weight: 600; outline: none; flex-shrink: 0;">
                    <option value="">Semua Status</option>
                    <option value="available" <?= ($statusFilter === 'available') ? 'selected' : '' ?>>Tersedia di WH</option>
                    <option value="exhausted" <?= ($statusFilter === 'exhausted') ? 'selected' : '' ?>>Habis Terpakai</option>
                    <option value="in_progress" <?= ($statusFilter === 'in_progress') ? 'selected' : '' ?>>In Progress</option>
                    <option value="rejected" <?= ($statusFilter === 'rejected') ? 'selected' : '' ?>>Defect / Reject</option>
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

        <!-- 4. MAIN TABLE REALISASI SAFETY STOCK PER PART -->
        <div style="background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
            
            <div style="padding: 12px 16px; background-color: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <h2 style="font-size: 12px; font-weight: 800; color: #334155; text-transform: uppercase; letter-spacing: 0.5px; margin: 0;">
                        Daftar Safety Stock per Part
                    </h2>
                    <span style="padding: 2px 8px; background-color: #dbeafe; color: #1d4ed8; font-size: 11px; font-weight: 800; border-radius: 9999px;">
                        <?= number_format($totalItems) ?> Part
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
                            <th style="padding: 10px 14px; min-width: 140px;">Customer</th>
                            <th style="padding: 10px 14px; text-align: center; width: 110px;">Kemasan Box</th>
                            <th style="padding: 10px 14px; text-align: right; width: 110px;">Stok Masuk</th>
                            <th style="padding: 10px 14px; text-align: right; width: 110px;">Terpakai</th>
                            <th style="padding: 10px 14px; text-align: right; width: 120px;">Sisa di WH</th>
                            <th style="padding: 10px 14px; width: 110px;">Status</th>
                            <th style="padding: 10px 14px; min-width: 130px;">Tgl Terakhir</th>
                            <th style="padding: 10px 14px; text-align: right; width: 90px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                            <tr>
                                <td colspan="10" style="padding: 48px; text-align: center; color: #94a3b8;">
                                    <div style="display: flex; flex-direction: column; align-items: center; justify-content: center;">
                                        <svg style="width: 44px; height: 44px; color: #cbd5e1; margin-bottom: 8px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                        </svg>
                                        <span style="font-weight: 800; color: #334155; font-size: 14px;">Belum Ada Data Safety Stock</span>
                                        <span style="font-size: 12px; color: #64748b; margin-top: 4px; max-width: 460px; line-height: 1.4;">
                                            Data safety stock yang diinspeksi OQC akan otomatis dikelompokkan per part di halaman ini.
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($items as $idx => $it): ?>
                                <?php 
                                    $rowNum = $offset + $idx + 1;
                                    $pCode = strtoupper(trim($it['part_code']));
                                    $bInfo = $boxMap[$pCode] ?? null;
                                    $totalBoxes = ($bInfo && (int)$bInfo['total_boxes'] > 0) ? (int)$bInfo['total_boxes'] : max(1, (int)$it['session_count']);
                                    $activeBoxes = ($bInfo && isset($bInfo['active_boxes']) && (int)$bInfo['total_boxes'] > 0) ? (int)$bInfo['active_boxes'] : $totalBoxes;

                                    $totalInitialQty = (int)$it['total_initial_qty'];
                                    $totalUsedQty    = (int)$it['total_used_qty'];
                                    $availQty        = max(0, (int)$it['available_qty']);

                                    // Status Badge Logic
                                    $pStatus = $it['part_status'];
                                    if ($availQty > 0) {
                                        $statusBadge = '<span style="background-color: #dcfce7; color: #166534; border: 1px solid #bbf7d0; padding: 2px 8px; border-radius: 9999px; font-weight: 800; font-size: 10px;">TERSEDIA</span>';
                                    } elseif ($totalUsedQty > 0 && $availQty === 0) {
                                        $statusBadge = '<span style="background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 2px 8px; border-radius: 9999px; font-weight: 800; font-size: 10px;">HABIS</span>';
                                    } elseif ($pStatus === 'in_progress') {
                                        $statusBadge = '<span style="background-color: #fef3c7; color: #92400e; border: 1px solid #fde68a; padding: 2px 8px; border-radius: 9999px; font-weight: 800; font-size: 10px;">IN PROGRESS</span>';
                                    } elseif ($pStatus === 'rejected') {
                                        $statusBadge = '<span style="background-color: #fee2e2; color: #991b1b; border: 1px solid #fecaca; padding: 2px 8px; border-radius: 9999px; font-weight: 800; font-size: 10px;">DEFECT</span>';
                                    } else {
                                        $statusBadge = '<span style="background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 2px 8px; border-radius: 9999px; font-weight: 800; font-size: 10px;">SELESAI</span>';
                                    }
                                ?>
                                <tr style="border-bottom: 1px solid #f1f5f9;">
                                    <td style="padding: 10px 14px; text-align: center; font-weight: 700; color: #64748b;"><?= $rowNum ?></td>
                                    
                                    <!-- Kode & Nama Part -->
                                    <td style="padding: 10px 14px;">
                                        <div style="font-weight: 800; color: #1d4ed8; font-family: monospace; font-size: 13px; margin-bottom: 2px;">
                                            <?= htmlspecialchars($it['part_code']) ?>
                                        </div>
                                        <div style="font-weight: 600; color: #1e293b; font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 220px;" title="<?= htmlspecialchars($it['part_name']) ?>">
                                            <?= htmlspecialchars($it['part_name']) ?>
                                        </div>
                                    </td>

                                    <!-- Customer -->
                                    <td style="padding: 10px 14px;">
                                        <span style="font-weight: 600; color: #475569; font-size: 11px;">
                                            <?= htmlspecialchars($it['customer'] ?: 'INTERNAL SAFETY STOCK') ?>
                                        </span>
                                    </td>

                                    <!-- Kemasan Box -->
                                    <td style="padding: 10px 14px; text-align: center;">
                                        <span style="font-weight: 800; color: #1e293b; background-color: #f8fafc; padding: 3px 10px; border-radius: 9999px; border: 1px solid #cbd5e1; font-size: 11px; display: inline-block;">
                                            <?= number_format($totalBoxes) ?> Box
                                        </span>
                                    </td>

                                    <!-- Stok Masuk -->
                                    <td style="padding: 10px 14px; text-align: right;">
                                        <span style="font-family: monospace; font-weight: 700; color: #475569; font-size: 12px;">
                                            <?= number_format($totalInitialQty) ?>
                                        </span>
                                        <span style="font-size: 10px; color: #94a3b8;"> pcs</span>
                                    </td>

                                    <!-- Terpakai Kanban -->
                                    <td style="padding: 10px 14px; text-align: right;">
                                        <span style="font-family: monospace; font-weight: 800; color: #7c3aed; font-size: 12px;">
                                            <?= number_format($totalUsedQty) ?>
                                        </span>
                                        <span style="font-size: 10px; color: #94a3b8;"> pcs</span>
                                    </td>

                                    <!-- Sisa di WH -->
                                    <td style="padding: 10px 14px; text-align: right;">
                                        <span style="font-family: monospace; font-weight: 900; color: <?= ($availQty > 0) ? '#15803d' : '#94a3b8' ?>; font-size: 13px;">
                                            <?= number_format($availQty) ?>
                                        </span>
                                        <span style="font-size: 10px; color: <?= ($availQty > 0) ? '#166534' : '#94a3b8' ?>;"> pcs</span>
                                    </td>

                                    <!-- Status -->
                                    <td style="padding: 10px 14px;">
                                        <?= $statusBadge ?>
                                    </td>

                                    <!-- Tanggal Terakhir -->
                                    <td style="padding: 10px 14px; color: #64748b; font-weight: 500; font-size: 11px;">
                                        <?php if (!empty($it['last_started_at'])): ?>
                                            <?= date('d M Y, H:i', strtotime($it['last_started_at'])) ?>
                                        <?php else: ?>
                                            <span style="color: #94a3b8;">-</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Aksi -->
                                    <td style="padding: 10px 14px; text-align: right; white-space: nowrap;">
                                        <a href="<?= base_url('modules/safety_stock/detail.php?part_code=' . urlencode($it['part_code'])) ?>" 
                                           style="padding: 6px 12px; background-color: #eff6ff; color: #1d4ed8; font-weight: 700; font-size: 11px; border-radius: 8px; border: 1px solid #bfdbfe; display: inline-flex; align-items: center; gap: 4px; text-decoration: none;" 
                                           title="Lihat Detail Box & Pemakaian Kanban">
                                            <svg style="width: 13px; height: 13px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
                        Menampilkan halaman <b style="color: #1e293b;"><?= $page ?></b> dari <b style="color: #1e293b;"><?= $totalPages ?></b> (Total <?= number_format($totalItems) ?> part)
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
