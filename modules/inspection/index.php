<?php
/**
 * Riwayat & Daftar Sesi Scan Inspeksi OQC (FR-3 & FR-6)
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

$pdo = getDB();

$search = isset($_GET['search']) ? trim(sanitize($_GET['search'])) : '';
$statusFilter = isset($_GET['status']) ? trim(sanitize($_GET['status'])) : 'all';
$startDate = isset($_GET['start_date']) ? trim(sanitize($_GET['start_date'])) : date('Y-m-01');
$endDate = isset($_GET['end_date']) ? trim(sanitize($_GET['end_date'])) : date('Y-m-d');
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$offset = ($page - 1) * $limit;

$sessions = [];
$totalItems = 0;
$totalPages = 1;

if ($pdo) {
    try {
        // Build Filter Query
        $where = ["1=1"];
        $params = [];

        if ($search !== '') {
            $where[] = "(did.part_code LIKE :s1 OR did.part_name LIKE :s2 OR did.lot_number LIKE :s3 OR k.customer LIKE :s4)";
            $params[':s1'] = '%' . $search . '%';
            $params[':s2'] = '%' . $search . '%';
            $params[':s3'] = '%' . $search . '%';
            $params[':s4'] = '%' . $search . '%';
        }

        if ($statusFilter !== 'all') {
            $where[] = "s.status = :st";
            $params[':st'] = $statusFilter;
        }

        if (!empty($startDate)) {
            $where[] = "DATE(s.started_at) >= :start_date";
            $params[':start_date'] = $startDate;
        }

        if (!empty($endDate)) {
            $where[] = "DATE(s.started_at) <= :end_date";
            $params[':end_date'] = $endDate;
        }

        $whereClause = implode(" AND ", $where);

        // Count Total Records
        $countSql = "SELECT COUNT(DISTINCT s.id) 
                     FROM inspection_sessions s
                     JOIN daily_inspection_data did ON did.id = s.did_id
                     LEFT JOIN kanban_items k ON k.id = s.kanban_item_id
                     WHERE {$whereClause}";
        $stmtCount = $pdo->prepare($countSql);
        $stmtCount->execute($params);
        $totalItems = (int)$stmtCount->fetchColumn();

        $totalPages = max(1, ceil($totalItems / $limit));
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $limit;

        // Fetch Sessions List
        $sql = "SELECT s.*, 
                       did.part_code, did.part_name, did.lot_number, did.cavity, did.pic as did_pic,
                       k.kanban_no, k.customer
                FROM inspection_sessions s
                JOIN daily_inspection_data did ON did.id = s.did_id
                LEFT JOIN kanban_items k ON k.id = s.kanban_item_id
                WHERE {$whereClause}
                ORDER BY s.id DESC
                LIMIT {$limit} OFFSET {$offset}";
        $stmtSess = $pdo->prepare($sql);
        $stmtSess->execute($params);
        $sessions = $stmtSess->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        $sessions = [];
    }
}

$breadcrumbCategory = "OPERASIONAL";
$pageTitle = "Riwayat Scan & Inspeksi OQC";
$pageSubtitle = "Daftar seluruh sesi pemeriksaan outgoing quality control yang telah atau sedang berlangsung";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-5 space-y-4 min-w-0 w-full overflow-x-hidden">
        
        <?= render_flash() ?>

        <!-- Filter & Search Bar + Action Button (Strictly 1 Row) -->
        <div class="bg-white border border-slate-200/80 rounded-2xl p-3 shadow-xs overflow-x-auto">
            <div class="flex items-center justify-between gap-2.5 min-w-[920px]">
                
                <form action="" method="GET" class="flex items-center gap-2 text-xs flex-1">
                    <!-- Search Input -->
                    <div class="relative w-64 flex-shrink-0 flex items-center">
                        <div style="position: absolute; left: 12px; top: 0; bottom: 0; display: flex; align-items: center; pointer-events: none;">
                            <svg style="width: 15px; height: 15px; color: #94a3b8;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </div>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Cari Part Code, Lot No, Customer..." style="padding-left: 34px;" class="w-full pr-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all placeholder:text-slate-400">
                    </div>

                    <!-- Status Select -->
                    <div class="w-36 flex-shrink-0">
                        <select name="status" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-700 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
                            <option value="all" <?= ($statusFilter === 'all') ? 'selected' : '' ?>>Semua Status</option>
                            <option value="in_progress" <?= ($statusFilter === 'in_progress') ? 'selected' : '' ?>>In Progress</option>
                            <option value="passed" <?= ($statusFilter === 'passed') ? 'selected' : '' ?>>Passed</option>
                            <option value="rejected" <?= ($statusFilter === 'rejected') ? 'selected' : '' ?>>Rejected</option>
                        </select>
                    </div>

                    <!-- Date Range -->
                    <div class="flex items-center space-x-1 bg-slate-50 border border-slate-200 rounded-xl px-2.5 py-1 text-xs text-slate-600 flex-shrink-0">
                        <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" class="bg-transparent border-0 p-1 text-xs text-slate-700 focus:outline-none font-medium">
                        <span class="text-slate-400 font-bold text-[11px]">s/d</span>
                        <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" class="bg-transparent border-0 p-1 text-xs text-slate-700 focus:outline-none font-medium">
                    </div>

                    <!-- Submit & Reset -->
                    <button type="submit" class="px-3.5 py-2 bg-slate-800 hover:bg-slate-900 active:bg-black text-white font-bold text-xs rounded-xl shadow-xs transition-all flex items-center flex-shrink-0">
                        <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path>
                        </svg>
                        Filter
                    </button>
                    <?php if (!empty($search) || $statusFilter !== 'all' || $startDate !== date('Y-m-01') || $endDate !== date('Y-m-d')): ?>
                        <a href="index.php" class="px-3 py-2 bg-rose-50 hover:bg-rose-100 text-rose-600 font-semibold text-xs rounded-xl border border-rose-200 transition-all flex-shrink-0">Reset</a>
                    <?php endif; ?>
                </form>

                <!-- New Inspection Button -->
                <div class="flex-shrink-0">
                    <a href="<?= base_url('modules/inspection/create.php') ?>" class="whitespace-nowrap px-4 py-2 bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white font-bold text-xs rounded-xl shadow-sm hover:shadow-md transition-all duration-200 flex items-center justify-center space-x-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                        </svg>
                        <span>Mulai Scan / Inspeksi Baru</span>
                    </a>
                </div>

            </div>
        </div>

        <!-- History Table Container -->
        <div class="card p-0 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs text-slate-700 min-w-[1000px]">
                    <thead class="bg-slate-50 text-slate-600 font-bold border-b border-slate-200 uppercase tracking-wider text-[10px]">
                        <tr>
                            <th class="px-4 py-3">No</th>
                            <th class="px-4 py-3">Waktu Start</th>
                            <th class="px-4 py-3">Part Code & Nama</th>
                            <th class="px-4 py-3">Lot Number</th>
                            <th class="px-4 py-3">Customer</th>
                            <th class="px-4 py-3 text-center">Progress Sample</th>
                            <th class="px-4 py-3 text-center">Total NG</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($sessions)): ?>
                            <tr>
                                <td colspan="9" class="px-4 py-8 text-center text-slate-400">
                                    Belum ada data sesi inspeksi OQC tercatat. Klik tombol "+ Mulai Scan / Inspeksi Baru" untuk memulai.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($sessions as $idx => $s): ?>
                                <tr class="hover:bg-slate-50/80 transition-colors">
                                    <td class="px-4 py-3 font-semibold text-slate-400"><?= $offset + $idx + 1 ?></td>
                                    <td class="px-4 py-3 font-bold text-slate-800">
                                        <?= date('d M Y, H:i', strtotime($s['started_at'])) ?> WIB
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="font-mono font-extrabold text-blue-700 block"><?= htmlspecialchars($s['part_code']) ?></span>
                                        <span class="text-[11px] text-slate-500 font-medium"><?= htmlspecialchars($s['part_name']) ?></span>
                                    </td>
                                    <td class="px-4 py-3 font-mono font-bold text-slate-800">
                                        <?= htmlspecialchars($s['lot_number']) ?>
                                        <span class="text-[10px] text-slate-400 block font-sans">Cavity: <?= htmlspecialchars($s['cavity']) ?></span>
                                    </td>
                                    <td class="px-4 py-3 font-semibold text-slate-800">
                                        <?= htmlspecialchars($s['customer'] ?? 'PT. Astra Honda Motor') ?>
                                    </td>
                                    <td class="px-4 py-3 text-center font-bold font-mono">
                                        <?= $s['samples_checked'] ?> / <?= $s['sample_size'] ?> pcs
                                    </td>
                                    <td class="px-4 py-3 text-center font-extrabold font-mono <?= ($s['ng_count'] > 0) ? 'text-rose-600' : 'text-slate-700' ?>">
                                        <?= $s['ng_count'] ?> / <?= $s['reject_number'] ?>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <?php if ($s['status'] === 'passed'): ?>
                                            <span style="white-space: nowrap; display: inline-flex; align-items: center;" class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-200 shadow-2xs">
                                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-600 mr-1.5 flex-shrink-0"></span>
                                                PASSED
                                            </span>
                                        <?php elseif ($s['status'] === 'rejected'): ?>
                                            <span style="white-space: nowrap; display: inline-flex; align-items: center;" class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 text-rose-800 border border-rose-200 shadow-2xs">
                                                <span class="w-1.5 h-1.5 rounded-full bg-rose-600 mr-1.5 flex-shrink-0"></span>
                                                REJECTED
                                            </span>
                                        <?php else: ?>
                                            <span style="white-space: nowrap; display: inline-flex; align-items: center;" class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-blue-100 text-blue-800 border border-blue-200 shadow-2xs">
                                                <span class="w-1.5 h-1.5 rounded-full bg-blue-600 mr-1.5 flex-shrink-0 animate-pulse"></span>
                                                IN PROGRESS
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 text-right whitespace-nowrap">
                                        <div class="inline-flex items-center space-x-1.5 flex-nowrap justify-end">
                                            <!-- Workbench Icon Button -->
                                            <a href="<?= base_url('modules/inspection/session.php?id=' . $s['id']) ?>" 
                                               class="p-2 bg-blue-50 hover:bg-blue-100 active:bg-blue-200 text-blue-600 rounded-xl border border-blue-200/80 transition-all inline-flex items-center justify-center shadow-2xs hover:shadow-xs" 
                                               title="Buka Workbench Inspeksi">
                                                <svg style="width: 18px; height: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                                </svg>
                                            </a>

                                            <?php if ($s['status'] === 'rejected'): ?>
                                                <!-- Cetak Rejection Sheet Icon Button -->
                                                <a href="<?= base_url('modules/inspection/print_rejection.php?session_id=' . $s['id']) ?>" 
                                                   target="_blank" 
                                                   class="p-2 bg-rose-50 hover:bg-rose-100 active:bg-rose-200 text-rose-600 rounded-xl border border-rose-200/80 transition-all inline-flex items-center justify-center shadow-2xs hover:shadow-xs" 
                                                   title="Cetak Rejection Sheet">
                                                    <svg style="width: 18px; height: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                                                    </svg>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
