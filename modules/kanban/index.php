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
$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT) ?: 10;
if (!in_array($limit, [5, 10, 25, 50, 100])) {
    $limit = 10;
}

$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
if ($page < 1) $page = 1;
$offset     = ($page - 1) * $limit;
$totalItems = 0;
$totalPages = 1;

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

        // Expression for Planning Date (prioritize Request Delivery Date -> ETA Date -> Import Date fallback)
        $dateExpr = "COALESCE(
            CASE WHEN ki.req_date IS NOT NULL AND ki.req_date > '1000-01-01' THEN DATE(ki.req_date) ELSE NULL END,
            CASE WHEN ki.eta IS NOT NULL AND ki.eta > '1000-01-01' THEN DATE(ki.eta) ELSE NULL END,
            DATE(b.imported_at)
        )";

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

        <!-- Daily Planning Summary Table -->
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
                            <th class="px-4 py-3">Tanggal Inspeksi</th>
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

    </main>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
