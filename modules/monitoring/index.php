<?php
/**
 * Monitoring Pekerjaan Supervisor OQC
 * Memantau realisasi pekerjaan inspeksi harian terhadap planning Kanban & Safety Stock.
 * Mode: 100% Offline-first with inline CSS layout fallback.
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

$pdo = getDB();

// ── Filter Parameters ────────────────────────────────────────────────────────
$startDate = isset($_GET['start_date']) ? sanitize($_GET['start_date']) : date('Y-m-01');
$endDate   = isset($_GET['end_date'])   ? sanitize($_GET['end_date'])   : date('Y-m-d');
$search    = sanitize($_GET['search'] ?? '');
$customer  = sanitize($_GET['customer'] ?? '');
$statusFilter = sanitize($_GET['status'] ?? 'all');
$planType   = sanitize($_GET['plan_type'] ?? '');

$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT) ?: 15;
if (!in_array($limit, [10, 15, 25, 50, 100])) $limit = 15;

$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// ── Data Fetching ─────────────────────────────────────────────────────────────
$items = [];
$totalItems = 0;
$totalPages = 1;

$summary = [
    'total_plan'   => 0,
    'not_started'  => 0,
    'in_progress'  => 0,
    'passed'       => 0,
    'rejected'     => 0,
    'total_pcs'    => 0,
];

$customersList = [];

if ($pdo) {
    try {
        // Fetch Customers dropdown options
        $stCust = $pdo->query("SELECT DISTINCT customer FROM kanban_items WHERE customer IS NOT NULL AND customer != '' ORDER BY customer ASC");
        $customersList = $stCust->fetchAll(PDO::FETCH_COLUMN);

        // Build WHERE clause
        $where = ["DATE(COALESCE(b.imported_at, ki.created_at)) BETWEEN :sd AND :ed"];
        $params = [':sd' => $startDate, ':ed' => $endDate];

        if (!empty($search)) {
            $where[] = "(ki.item_code LIKE :s1 OR ki.item_description LIKE :s2 OR ki.kanban_no LIKE :s3 OR b.document_number LIKE :s4)";
            $params[':s1'] = '%' . $search . '%';
            $params[':s2'] = '%' . $search . '%';
            $params[':s3'] = '%' . $search . '%';
            $params[':s4'] = '%' . $search . '%';
        }

        if (!empty($customer)) {
            $where[] = "ki.customer = :customer";
            $params[':customer'] = $customer;
        }

        if (!empty($planType)) {
            $where[] = "ki.plan_type = :plan_type";
            $params[':plan_type'] = $planType;
        }

        $whereStr = implode(" AND ", $where);

        // Fetch All Matching Items for Summary Calculation
        $sumSql = "SELECT 
                    ki.id AS item_id,
                    ki.qty AS planned_qty,
                    COUNT(ss.id) AS session_count,
                    SUM(CASE WHEN ss.status = 'rejected' THEN 1 ELSE 0 END) AS count_rejected,
                    SUM(CASE WHEN ss.status = 'in_progress' THEN 1 ELSE 0 END) AS count_in_progress,
                    SUM(CASE WHEN ss.status = 'passed' THEN 1 ELSE 0 END) AS count_passed
                   FROM kanban_items ki
                   LEFT JOIN kanban_batches b ON ki.batch_id = b.id
                   LEFT JOIN inspection_sessions ss ON ss.kanban_item_id = ki.id
                   WHERE {$whereStr}
                   GROUP BY ki.id, ki.qty";
        $stSum = $pdo->prepare($sumSql);
        $stSum->execute($params);
        $allSumRows = $stSum->fetchAll(PDO::FETCH_ASSOC);

        $summary['total_plan'] = count($allSumRows);
        foreach ($allSumRows as $sr) {
            $summary['total_pcs'] += (int)$sr['planned_qty'];
            if ($sr['session_count'] == 0) {
                $summary['not_started']++;
            } elseif ($sr['count_rejected'] > 0) {
                $summary['rejected']++;
            } elseif ($sr['count_in_progress'] > 0) {
                $summary['in_progress']++;
            } elseif ($sr['count_passed'] > 0) {
                $summary['passed']++;
            } else {
                $summary['not_started']++;
            }
        }

        // Apply Status Filter in HAVING clause for paginated list if specified
        $havingStr = "";
        if ($statusFilter === 'not_started') {
            $havingStr = " HAVING session_count = 0 ";
        } elseif ($statusFilter === 'in_progress') {
            $havingStr = " HAVING count_in_progress > 0 AND count_rejected = 0 ";
        } elseif ($statusFilter === 'passed') {
            $havingStr = " HAVING count_passed > 0 AND count_in_progress = 0 AND count_rejected = 0 ";
        } elseif ($statusFilter === 'rejected') {
            $havingStr = " HAVING count_rejected > 0 ";
        }

        // Count Total Items after HAVING status filter
        $countSql = "SELECT COUNT(*) FROM (
                        SELECT ki.id,
                               COUNT(ss.id) AS session_count,
                               SUM(CASE WHEN ss.status = 'rejected' THEN 1 ELSE 0 END) AS count_rejected,
                               SUM(CASE WHEN ss.status = 'in_progress' THEN 1 ELSE 0 END) AS count_in_progress,
                               SUM(CASE WHEN ss.status = 'passed' THEN 1 ELSE 0 END) AS count_passed
                        FROM kanban_items ki
                        LEFT JOIN kanban_batches b ON ki.batch_id = b.id
                        LEFT JOIN inspection_sessions ss ON ss.kanban_item_id = ki.id
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

        // Main Query with Priority Sorting (In Progress > Belum > Rejected > Passed)
        $mainSql = "SELECT 
                        ki.id AS item_id, ki.item_code, ki.item_description, ki.qty AS planned_qty, 
                        ki.customer, ki.plan_type, ki.kanban_no, ki.req_date,
                        COALESCE(b.document_number, 'DOC-LEGACY') AS document_number, 
                        COALESCE(b.vendor, 'Legacy Input') AS vendor, 
                        COALESCE(b.imported_at, ki.created_at) AS plan_date,
                        COUNT(ss.id) AS session_count,
                        MAX(ss.id) AS last_session_id,
                        SUM(CASE WHEN ss.status = 'rejected' THEN 1 ELSE 0 END) AS count_rejected,
                        SUM(CASE WHEN ss.status = 'in_progress' THEN 1 ELSE 0 END) AS count_in_progress,
                        SUM(CASE WHEN ss.status = 'passed' THEN 1 ELSE 0 END) AS count_passed,
                        MAX(ss.samples_checked) AS samples_checked,
                        MAX(ss.sample_size) AS sample_size,
                        MAX(ss.ng_count) AS ng_count,
                        MAX(ss.reject_number) AS reject_number,
                        MIN(ss.started_at) AS first_started_at,
                        MAX(ss.closed_at) AS last_closed_at,
                        COALESCE(MAX(u.name), MAX(did.pic), '-') AS inspector_name,
                        MAX(did.lot_number) AS lot_number
                    FROM kanban_items ki
                    LEFT JOIN kanban_batches b ON ki.batch_id = b.id
                    LEFT JOIN inspection_sessions ss ON ss.kanban_item_id = ki.id
                    LEFT JOIN users u ON ss.inspector_id = u.id
                    LEFT JOIN daily_inspection_data did ON ss.did_id = did.id
                    WHERE {$whereStr}
                    GROUP BY ki.id, ki.item_code, ki.item_description, ki.qty, ki.customer, ki.plan_type, ki.kanban_no, ki.req_date, b.document_number, b.vendor, b.imported_at, ki.created_at
                    {$havingStr}
                    ORDER BY 
                        CASE 
                            WHEN SUM(CASE WHEN ss.status = 'in_progress' THEN 1 ELSE 0 END) > 0 THEN 1
                            WHEN COUNT(ss.id) = 0 THEN 2
                            WHEN SUM(CASE WHEN ss.status = 'rejected' THEN 1 ELSE 0 END) > 0 THEN 3
                            ELSE 4 
                        END ASC,
                        ki.id DESC
                    LIMIT :limit OFFSET :offset";

        $stMain = $pdo->prepare($mainSql);
        foreach ($params as $k => $v) {
            $stMain->bindValue($k, $v);
        }
        $stMain->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stMain->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stMain->execute();
        $items = $stMain->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        $items = [];
    }
}

$breadcrumbCategory = "OPERASIONAL";
$pageTitle = "Monitoring Pekerjaan Supervisor";
$pageSubtitle = "Pemantauan realisasi pekerjaan harian tim QC terhadap planning Kanban & Safety Stock";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen min-w-0 w-full overflow-x-hidden transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-4 space-y-3 min-w-0 w-full overflow-x-hidden">
        
        <?= render_flash() ?>

        <!-- 1. Live Refresh Bar & Header Card -->
        <div style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); border-radius: 12px; padding: 12px 16px; color: #ffffff; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="background-color: #2563eb; padding: 8px; border-radius: 10px; color: #ffffff; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                </div>
                <div>
                    <h2 style="font-size: 13px; font-weight: 800; margin: 0; line-height: 1.2;">Monitoring Realisasi Pekerjaan QC</h2>
                    <p style="font-size: 11px; color: #cbd5e1; font-weight: 500; margin-top: 3px;">
                        Periode Planning: <b style="color: #93c5fd;"><?= date('d M Y', strtotime($startDate)) ?></b> 
                        s.d <b style="color: #93c5fd;"><?= date('d M Y', strtotime($endDate)) ?></b>
                    </p>
                </div>
            </div>

            <!-- Live Auto Refresh Controls -->
            <div style="display: flex; align-items: center; gap: 8px; background-color: rgba(30,41,59,0.9); border: 1px solid #334155; border-radius: 10px; padding: 6px 12px; font-size: 11px;">
                <span style="width: 8px; height: 8px; border-radius: 9999px; background-color: #34d399; display: inline-block;" class="animate-ping"></span>
                <span style="color: #cbd5e1; font-weight: 500; white-space: nowrap;">Auto Refresh: <b id="countdown-timer" style="color: #34d399; font-family: monospace;">30</b>s</span>
                <button type="button" id="toggle-refresh-btn" onclick="toggleAutoRefresh()" style="background-color: #334155; color: #ffffff; border-radius: 4px; padding: 2px 8px; font-size: 10px; font-weight: 700; border: none; cursor: pointer;">
                    Pause
                </button>
            </div>
        </div>

        <!-- 2. STRICT 1-ROW 5 KPI CARDS (Inline Flex Grid fallback guarantees 1 row) -->
        <div style="width: 100%; overflow-x: auto; padding-bottom: 2px;">
            <div style="display: flex; align-items: stretch; gap: 10px; width: 100%; min-width: 800px;">
                
                <!-- Card 1: Total Plan -->
                <div style="flex: 1; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 14px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                    <div style="display: flex; align-items: center; justify-content: space-between;">
                        <span style="font-size: 10px; font-weight: 800; color: #94a3b8; letter-spacing: 0.5px;">TOTAL PLANNING</span>
                        <span style="width: 8px; height: 8px; border-radius: 9999px; background-color: #3b82f6; display: inline-block;"></span>
                    </div>
                    <div style="display: flex; align-items: baseline; gap: 4px; margin-top: 4px;">
                        <span style="font-size: 20px; font-weight: 900; color: #1e293b; line-height: 1;"><?= number_format($summary['total_plan']) ?></span>
                        <span style="font-size: 11px; font-weight: 700; color: #64748b;">item</span>
                    </div>
                    <div style="font-size: 10px; color: #64748b; font-weight: 500; margin-top: 6px; border-top: 1px solid #f1f5f9; padding-top: 4px;">
                        Target: <b style="color: #1e293b;"><?= number_format($summary['total_pcs']) ?> pcs</b>
                    </div>
                </div>

                <!-- Card 2: Belum Dikerjakan -->
                <div style="flex: 1; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 14px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                    <div style="display: flex; align-items: center; justify-content: space-between;">
                        <span style="font-size: 10px; font-weight: 800; color: #64748b; letter-spacing: 0.5px;">BELUM DIKERJAKAN</span>
                        <span style="width: 8px; height: 8px; border-radius: 9999px; background-color: #94a3b8; display: inline-block;"></span>
                    </div>
                    <div style="display: flex; align-items: baseline; gap: 4px; margin-top: 4px;">
                        <span style="font-size: 20px; font-weight: 900; color: #475569; line-height: 1;"><?= number_format($summary['not_started']) ?></span>
                        <span style="font-size: 11px; font-weight: 700; color: #64748b;">item</span>
                    </div>
                    <div style="font-size: 10px; color: #64748b; font-weight: 500; margin-top: 6px; border-top: 1px solid #f1f5f9; padding-top: 4px;">
                        Status: <b style="color: #475569;">Menunggu Scan</b>
                    </div>
                </div>

                <!-- Card 3: In Progress -->
                <div style="flex: 1; background-color: #fffbe6; border: 1px solid #ffe58f; border-radius: 12px; padding: 10px 14px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                    <div style="display: flex; align-items: center; justify-content: space-between;">
                        <span style="font-size: 10px; font-weight: 800; color: #92400e; letter-spacing: 0.5px;">IN PROGRESS</span>
                        <span style="width: 8px; height: 8px; border-radius: 9999px; background-color: #d97706; display: inline-block;"></span>
                    </div>
                    <div style="display: flex; align-items: baseline; gap: 4px; margin-top: 4px;">
                        <span style="font-size: 20px; font-weight: 900; color: #78350f; line-height: 1;"><?= number_format($summary['in_progress']) ?></span>
                        <span style="font-size: 11px; font-weight: 700; color: #92400e;">item</span>
                    </div>
                    <div style="font-size: 10px; color: #92400e; font-weight: 500; margin-top: 6px; border-top: 1px solid #fef08a; padding-top: 4px;">
                        Keterangan: <b style="color: #78350f;">Oper Shift</b>
                    </div>
                </div>

                <!-- Card 4: Passed -->
                <div style="flex: 1; background-color: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px; padding: 10px 14px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                    <div style="display: flex; align-items: center; justify-content: space-between;">
                        <span style="font-size: 10px; font-weight: 800; color: #166534; letter-spacing: 0.5px;">PASSED (LULUS)</span>
                        <span style="width: 8px; height: 8px; border-radius: 9999px; background-color: #16a34a; display: inline-block;"></span>
                    </div>
                    <div style="display: flex; align-items: baseline; gap: 4px; margin-top: 4px;">
                        <span style="font-size: 20px; font-weight: 900; color: #14532d; line-height: 1;"><?= number_format($summary['passed']) ?></span>
                        <span style="font-size: 11px; font-weight: 700; color: #166534;">item</span>
                    </div>
                    <div style="font-size: 10px; color: #166534; font-weight: 500; margin-top: 6px; border-top: 1px solid #dcfce7; padding-top: 4px;">
                        Keterangan: <b style="color: #166534;">Selesai OK</b>
                    </div>
                </div>

                <!-- Card 5: Rejected -->
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

            </div>
        </div>

        <!-- 3. Filter Bar (Strict 1 Row Flex Container) -->
        <div style="background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 14px; overflow-x: auto; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
            <form action="" method="GET" style="display: flex; align-items: center; gap: 8px; min-width: 880px;">
                <!-- Search Input -->
                <div style="width: 200px; flex-shrink: 0;">
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Cari Part Code, Nama..." style="width: 100%; padding: 6px 10px; background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 12px; color: #1e293b; font-weight: 500; outline: none;">
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
                    <option value="all" <?= ($statusFilter === 'all') ? 'selected' : '' ?>>Semua Status</option>
                    <option value="in_progress" <?= ($statusFilter === 'in_progress') ? 'selected' : '' ?>>In Progress</option>
                    <option value="not_started" <?= ($statusFilter === 'not_started') ? 'selected' : '' ?>>Belum Dikerjakan</option>
                    <option value="passed" <?= ($statusFilter === 'passed') ? 'selected' : '' ?>>Passed</option>
                    <option value="rejected" <?= ($statusFilter === 'rejected') ? 'selected' : '' ?>>Rejected</option>
                </select>

                <!-- Plan Type Filter -->
                <select name="plan_type" onchange="this.form.submit()" style="width: 125px; padding: 6px 10px; background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 12px; color: #334155; font-weight: 600; outline: none; flex-shrink: 0;">
                    <option value="">Tipe Planning</option>
                    <option value="kanban" <?= ($planType === 'kanban') ? 'selected' : '' ?>>Kanban</option>
                    <option value="safety_stock" <?= ($planType === 'safety_stock') ? 'selected' : '' ?>>Safety Stock</option>
                </select>

                <!-- Date Range -->
                <div style="display: flex; align-items: center; gap: 4px; background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 4px 8px; font-size: 12px; flex-shrink: 0;">
                    <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" style="background: transparent; border: none; font-size: 12px; font-weight: 600; color: #334155; outline: none;">
                    <span style="color: #94a3b8; font-weight: 700; font-size: 11px;">s/d</span>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" style="background: transparent; border: none; font-size: 12px; font-weight: 600; color: #334155; outline: none;">
                </div>

                <button type="submit" style="background-color: #0f172a; color: #ffffff; font-weight: 700; font-size: 12px; border-radius: 8px; padding: 6px 14px; border: none; cursor: pointer; flex-shrink: 0;">
                    Filter
                </button>
                <?php if (!empty($search) || !empty($customer) || $statusFilter !== 'all' || !empty($planType) || $startDate !== date('Y-m-01') || $endDate !== date('Y-m-d')): ?>
                    <a href="index.php" style="background-color: #fff1f2; color: #e11d48; font-weight: 700; font-size: 12px; border-radius: 8px; padding: 6px 12px; border: 1px solid #fecdd3; text-decoration: none; flex-shrink: 0;">Reset</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- 4. Table Container with Horizontal Scrollbar & Compact Layout -->
        <div style="background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
            <div style="overflow-x: auto; width: 100%;">
                <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 12px; color: #334155;">
                    <thead style="background-color: #f8fafc; border-bottom: 1px solid #e2e8f0; text-transform: uppercase; font-size: 10px; font-weight: 800; color: #64748b; letter-spacing: 0.5px;">
                        <tr>
                            <th style="padding: 10px 12px; width: 40px;">No</th>
                            <th style="padding: 10px 12px; width: 110px;">Tanggal Plan</th>
                            <th style="padding: 10px 12px;">Part Code & Nama</th>
                            <th style="padding: 10px 12px; width: 140px;">Customer</th>
                            <th style="padding: 10px 12px; text-align: center; width: 90px;">Target Qty</th>
                            <th style="padding: 10px 12px; width: 90px;">Tipe Plan</th>
                            <th style="padding: 10px 12px; width: 120px;">Status Realisasi</th>
                            <th style="padding: 10px 12px; text-align: center; width: 100px;">Progress Sample</th>
                            <th style="padding: 10px 12px; text-align: center; width: 80px;">NG Count</th>
                            <th style="padding: 10px 12px; width: 120px;">Inspektur / PIC QC</th>
                            <th style="padding: 10px 12px; width: 110px;">Waktu Inspeksi</th>
                            <th style="padding: 10px 12px; text-align: right; width: 80px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                            <tr>
                                <td colspan="12" style="padding: 32px 16px; text-align: center; color: #94a3b8; font-size: 12px;">
                                    Belum ada item planning pada filter tanggal yang dipilih. Silakan ubah filter tanggal atau klik "Reset".
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($items as $idx => $it): ?>
                                <?php
                                $sessCount = (int)$it['session_count'];
                                $cRej       = (int)$it['count_rejected'];
                                $cInProg    = (int)$it['count_in_progress'];
                                $cPass      = (int)$it['count_passed'];

                                if ($sessCount == 0) {
                                    $stType = 'not_started';
                                } elseif ($cRej > 0) {
                                    $stType = 'rejected';
                                } elseif ($cInProg > 0) {
                                    $stType = 'in_progress';
                                } elseif ($cPass > 0) {
                                    $stType = 'passed';
                                } else {
                                    $stType = 'not_started';
                                }
                                $rowBg = ($stType === 'in_progress') ? '#fffbe6' : (($idx % 2 === 1) ? '#f8fafc' : '#ffffff');
                                ?>
                                <tr style="background-color: <?= $rowBg ?>; border-bottom: 1px solid #f1f5f9;">
                                    <!-- No -->
                                    <td style="padding: 8px 12px; color: #94a3b8; font-weight: 600;"><?= $offset + $idx + 1 ?></td>

                                    <!-- Tanggal Plan -->
                                    <td style="padding: 8px 12px; font-weight: 700; color: #1e293b; white-space: nowrap;">
                                        <?= date('d M Y', strtotime($it['plan_date'])) ?>
                                        <span style="font-size: 10px; color: #94a3b8; font-weight: 400; display: block; overflow: hidden; text-overflow: ellipsis; max-width: 110px;"><?= htmlspecialchars($it['document_number']) ?></span>
                                    </td>

                                    <!-- Part Code & Nama -->
                                    <td style="padding: 8px 12px;">
                                        <span style="font-family: monospace; font-weight: 800; color: #1d4ed8; display: block; font-size: 12px;"><?= htmlspecialchars($it['item_code']) ?></span>
                                        <span style="font-size: 11px; color: #475569; font-weight: 500; display: block; max-width: 220px; word-break: break-word;" title="<?= htmlspecialchars($it['item_description']) ?>"><?= htmlspecialchars($it['item_description']) ?></span>
                                        <?php if (!empty($it['kanban_no'])): ?>
                                            <span style="font-size: 10px; color: #94a3b8; font-family: monospace; display: block; margin-top: 2px;">Kanban: <?= htmlspecialchars($it['kanban_no']) ?></span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Customer -->
                                    <td style="padding: 8px 12px; font-weight: 600; color: #1e293b;">
                                        <span style="display: block; max-width: 130px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($it['customer'] ?? '-') ?>"><?= htmlspecialchars($it['customer'] ?? '-') ?></span>
                                    </td>

                                    <!-- Target Qty -->
                                    <td style="padding: 8px 12px; text-align: center; font-weight: 800; font-family: monospace; color: #0f172a; white-space: nowrap;">
                                        <?= number_format($it['planned_qty']) ?> <span style="font-size: 10px; font-weight: 400; color: #94a3b8;">pcs</span>
                                    </td>

                                    <!-- Tipe Plan -->
                                    <td style="padding: 8px 12px; white-space: nowrap;">
                                        <?php if ($it['plan_type'] === 'safety_stock'): ?>
                                            <span style="background-color: #f3e8ff; color: #6b21a8; border: 1px solid #e9d5ff; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: 700;">Safety Stock</span>
                                        <?php else: ?>
                                            <span style="background-color: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: 700;">Kanban</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Status Realisasi -->
                                    <td style="padding: 8px 12px; white-space: nowrap;">
                                        <?php if ($stType === 'passed'): ?>
                                            <span style="white-space: nowrap; display: inline-flex; align-items: center; background-color: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; padding: 2px 8px; border-radius: 9999px; font-size: 10px; font-weight: 700;">
                                                <span style="width: 5px; height: 5px; border-radius: 9999px; background-color: #059669; margin-right: 5px; display: inline-block;"></span>
                                                PASSED
                                            </span>
                                        <?php elseif ($stType === 'rejected'): ?>
                                            <span style="white-space: nowrap; display: inline-flex; align-items: center; background-color: #ffe4e6; color: #9f1239; border: 1px solid #fecdd3; padding: 2px 8px; border-radius: 9999px; font-size: 10px; font-weight: 700;">
                                                <span style="width: 5px; height: 5px; border-radius: 9999px; background-color: #e11d48; margin-right: 5px; display: inline-block;"></span>
                                                REJECTED
                                            </span>
                                        <?php elseif ($stType === 'in_progress'): ?>
                                            <span style="white-space: nowrap; display: inline-flex; align-items: center; background-color: #fffbe6; color: #92400e; border: 1px solid #ffe58f; padding: 2px 8px; border-radius: 9999px; font-size: 10px; font-weight: 700;">
                                                <span style="width: 5px; height: 5px; border-radius: 9999px; background-color: #d97706; margin-right: 5px; display: inline-block;"></span>
                                                IN PROGRESS
                                            </span>
                                        <?php else: ?>
                                            <span style="white-space: nowrap; display: inline-flex; align-items: center; background-color: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; padding: 2px 8px; border-radius: 9999px; font-size: 10px; font-weight: 700;">
                                                <span style="width: 5px; height: 5px; border-radius: 9999px; background-color: #94a3b8; margin-right: 5px; display: inline-block;"></span>
                                                BELUM DIKERJAKAN
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Progress Sample -->
                                    <td style="padding: 8px 12px; text-align: center; font-weight: 700; font-family: monospace; white-space: nowrap;">
                                        <?php if ($sessCount > 0): ?>
                                            <?= (int)$it['samples_checked'] ?> / <?= (int)$it['sample_size'] ?> pcs
                                        <?php else: ?>
                                            <span style="color: #94a3b8; font-weight: 400;">-</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- NG Count -->
                                    <td style="padding: 8px 12px; text-align: center; font-weight: 800; font-family: monospace; white-space: nowrap; color: <?= ($it['ng_count'] > 0) ? '#e11d48' : '#334155' ?>;">
                                        <?php if ($sessCount > 0): ?>
                                            <?= (int)$it['ng_count'] ?> / <?= (int)$it['reject_number'] ?>
                                        <?php else: ?>
                                            <span style="color: #94a3b8; font-weight: 400;">-</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Inspektur / PIC QC -->
                                    <td style="padding: 8px 12px; font-weight: 600; color: #1e293b; white-space: nowrap;">
                                        <?php if ($it['inspector_name'] !== '-'): ?>
                                            <div style="display: flex; align-items: center; gap: 6px;">
                                                <span style="width: 20px; height: 20px; border-radius: 9999px; background-color: #dbeafe; color: #1d4ed8; font-weight: 700; font-size: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                                    <?= strtoupper(substr($it['inspector_name'], 0, 1)) ?>
                                                </span>
                                                <span style="overflow: hidden; text-overflow: ellipsis; max-width: 90px;" title="<?= htmlspecialchars($it['inspector_name']) ?>"><?= htmlspecialchars($it['inspector_name']) ?></span>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: #94a3b8; font-weight: 400; font-style: italic;">Belum Ada</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Waktu Inspeksi -->
                                    <td style="padding: 8px 12px; font-size: 11px; color: #64748b; font-weight: 500; white-space: nowrap;">
                                        <?php if (!empty($it['first_started_at'])): ?>
                                            <?= date('H:i', strtotime($it['first_started_at'])) ?>
                                            <?php if (!empty($it['last_closed_at'])): ?>
                                                &ndash; <?= date('H:i', strtotime($it['last_closed_at'])) ?>
                                            <?php endif; ?> WIB
                                        <?php else: ?>
                                            <span style="color: #94a3b8; font-weight: 400;">-</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Aksi -->
                                    <td style="padding: 8px 12px; text-align: right; white-space: nowrap;">
                                        <a href="<?= base_url('modules/monitoring/detail.php?id=' . $it['item_id']) ?>" 
                                           style="padding: 5px 12px; background-color: #eff6ff; color: #1d4ed8; font-weight: 700; font-size: 11px; border-radius: 8px; border: 1px solid #bfdbfe; display: inline-flex; align-items: center; gap: 5px; text-decoration: none;" 
                                           title="Lihat Detail Realisasi Inspeksi">
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

            <!-- Pagination Footer -->
            <div style="padding: 10px 14px; border-top: 1px solid #f1f5f9;">
                <?= render_pagination($page, $totalPages, $totalItems, $limit) ?>
            </div>

        </div>

    </main>

    <script>
    // Realtime Auto-Refresh 30 Seconds Countdown
    var timeLeft = 30;
    var timerRunning = true;
    var timerElem = document.getElementById('countdown-timer');
    var btnElem   = document.getElementById('toggle-refresh-btn');

    var intervalId = setInterval(function() {
        if (!timerRunning) return;
        timeLeft--;
        if (timerElem) timerElem.innerText = timeLeft;
        if (timeLeft <= 0) {
            window.location.reload();
        }
    }, 1000);

    function toggleAutoRefresh() {
        timerRunning = !timerRunning;
        if (btnElem) {
            btnElem.innerText = timerRunning ? 'Pause' : 'Resume';
            btnElem.style.backgroundColor = timerRunning ? '#334155' : '#059669';
        }
    }
    </script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
