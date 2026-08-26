<?php
$breadcrumbCategory = "DATA REFERENSI";
$pageTitle = "Planning Inspeksi (Kanban & Safety Stock)";
$pageSubtitle = "Ringkasan jadwal planning inspeksi per tanggal";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

$pdo = getDB();
$days = [];
$search    = sanitize($_GET['search'] ?? '');
$planType  = sanitize($_GET['plan_type'] ?? '');
$startDate = sanitize($_GET['start_date'] ?? '');
$endDate   = sanitize($_GET['end_date'] ?? '');

// Dynamic Limit
$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT) ?: 10;
if (!in_array($limit, [5, 10, 25, 50, 100])) {
    $limit = 10;
}

$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
if ($page < 1) $page = 1;
$offset      = ($page - 1) * $limit;
$totalItems  = 0;
$totalPages  = 1;

if ($pdo) {
    try {
        // Migration: group orphan items into a legacy batch
        $stmtUnbatched = $pdo->query("SELECT COUNT(*) FROM kanban_items WHERE batch_id IS NULL OR batch_id = 0");
        $unbatchedCount = (int)$stmtUnbatched->fetchColumn();
        if ($unbatchedCount > 0) {
            $pdo->exec("INSERT INTO kanban_batches (vendor, document_number, import_method, imported_at)
                        VALUES ('Legacy Input', 'DOC-LEGACY', 'manual', NOW())");
            $newBatchId = $pdo->lastInsertId();
            $pdo->exec("UPDATE kanban_items SET batch_id = {$newBatchId} WHERE batch_id IS NULL OR batch_id = 0");
        }

        // Build WHERE conditions (filter applies to batches, not grouped dates)
        $whereClauses  = ['1=1'];
        $countParams   = [];

        if (!empty($search)) {
            $whereClauses[] = "(b.vendor LIKE :s1 OR b.document_number LIKE :s2)";
            $countParams[':s1'] = '%' . $search . '%';
            $countParams[':s2'] = '%' . $search . '%';
        }
        if (!empty($planType)) {
            $whereClauses[] = "b.plan_type = :plan_type";
            $countParams[':plan_type'] = $planType;
        }
        if (!empty($startDate)) {
            $whereClauses[] = "DATE(b.imported_at) >= :start_date";
            $countParams[':start_date'] = $startDate;
        }
        if (!empty($endDate)) {
            $whereClauses[] = "DATE(b.imported_at) <= :end_date";
            $countParams[':end_date'] = $endDate;
        }

        $whereStr = implode(' AND ', $whereClauses);

        // Count distinct dates
        $countSql = "SELECT COUNT(DISTINCT DATE(b.imported_at))
                     FROM kanban_batches b
                     WHERE {$whereStr}";
        $stmtCount = $pdo->prepare($countSql);
        $stmtCount->execute($countParams);
        $totalItems = (int) $stmtCount->fetchColumn();

        $totalPages = max(1, ceil($totalItems / $limit));
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $limit;

        // Fetch grouped-by-date summary — LEFT JOIN kanban_items so COUNT/SUM work cleanly in GROUP BY
        $sql = "SELECT
                    DATE(b.imported_at)                                           AS plan_date,
                    COUNT(DISTINCT b.id)                                          AS batch_count,
                    SUM(CASE WHEN b.plan_type = 'kanban'       THEN 1 ELSE 0 END) AS kanban_count,
                    SUM(CASE WHEN b.plan_type = 'safety_stock' THEN 1 ELSE 0 END) AS safety_count,
                    COUNT(ki.id)                                                  AS total_items,
                    COALESCE(SUM(ki.qty), 0)                                      AS total_pcs,
                    MIN(b.imported_at)                                            AS first_import,
                    MAX(b.imported_at)                                            AS last_import
                FROM kanban_batches b
                LEFT JOIN kanban_items ki ON ki.batch_id = b.id
                WHERE {$whereStr}
                GROUP BY DATE(b.imported_at)
                ORDER BY plan_date DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        foreach ($countParams as $k => $v) {
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
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-5 space-y-3">
        
        <?= render_flash() ?>

        <!-- Filter & Search Bar + Action Button (Strictly 1 Row) -->
        <div class="card p-3 overflow-x-auto">
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; min-width: 850px;">
                
                <form action="" method="GET" style="display: flex; align-items: center; gap: 6px; flex: 1;">
                    <!-- Search Input -->
                    <div style="position: relative; width: 210px; flex-shrink: 0;">
                        <svg style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 14px; height: 14px; color: #94a3b8; pointer-events: none;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                               placeholder="Cari Vendor / Nomor Dokumen..." class="form-input py-1 text-xs" style="padding-left: 30px; width: 100%;">
                    </div>

                    <!-- Plan Type Selector -->
                    <select name="plan_type" onchange="this.form.submit()" class="form-input py-1 text-xs font-semibold text-slate-700" style="width: 150px; flex-shrink: 0;">
                        <option value="">Semua Planning</option>
                        <option value="kanban"       <?= ($planType === 'kanban')       ? 'selected' : '' ?>>Kanban (Kirim)</option>
                        <option value="safety_stock" <?= ($planType === 'safety_stock') ? 'selected' : '' ?>>Safety Stock</option>
                    </select>

                    <!-- Date Range -->
                    <div style="display: flex; align-items: center; gap: 4px; flex-shrink: 0;">
                        <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" class="form-input py-1 text-xs" style="width: 130px;">
                        <span class="text-xs text-slate-400">s/d</span>
                        <input type="date" name="end_date"   value="<?= htmlspecialchars($endDate) ?>"   class="form-input py-1 text-xs" style="width: 130px;">
                    </div>

                    <!-- Limit Selector -->
                    <select name="limit" onchange="this.form.submit()" class="form-input py-1 text-xs font-semibold text-slate-700" style="width: 85px; flex-shrink: 0;">
                        <option value="5"  <?= ($limit == 5)  ? 'selected' : '' ?>>5 / hal</option>
                        <option value="10" <?= ($limit == 10) ? 'selected' : '' ?>>10 / hal</option>
                        <option value="25" <?= ($limit == 25) ? 'selected' : '' ?>>25 / hal</option>
                        <option value="50" <?= ($limit == 50) ? 'selected' : '' ?>>50 / hal</option>
                    </select>

                    <button type="submit" class="btn-secondary py-1 px-2.5 text-xs" style="flex-shrink: 0;">Filter</button>
                    <?php if (!empty($search) || !empty($planType) || !empty($startDate) || !empty($endDate) || $limit != 10): ?>
                        <a href="<?= base_url('modules/kanban/index.php') ?>" class="text-[11px] text-rose-600 font-semibold hover:underline" style="flex-shrink: 0;">Reset</a>
                    <?php endif; ?>
                </form>

                <div style="flex-shrink: 0;">
                    <a href="<?= base_url('modules/kanban/create.php') ?>" class="btn-primary py-1 px-3 text-xs" style="white-space: nowrap;">
                        <svg class="w-3.5 h-3.5 mr-1 inline-block" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                        </svg>
                        Tambah Planning
                    </a>
                </div>

            </div>
        </div>

        <!-- Daily Planning Summary Table -->
        <div class="card p-0 overflow-hidden">
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
                                    Belum ada data Planning Inspeksi. Silakan tambah data baru.
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
                                        <div class="flex flex-col gap-1">
                                            <?php if ($d['kanban_count'] > 0): ?>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold bg-blue-100 text-blue-800 border border-blue-200 w-fit">
                                                    Kanban
                                                    <span class="ml-1 bg-blue-200 text-blue-800 rounded px-1 text-[10px]"><?= (int)$d['kanban_count'] ?>x</span>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($d['safety_count'] > 0): ?>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold bg-purple-100 text-purple-800 border border-purple-200 w-fit">
                                                    Safety Stock
                                                    <span class="ml-1 bg-purple-200 text-purple-800 rounded px-1 text-[10px]"><?= (int)$d['safety_count'] ?>x</span>
                                                </span>
                                            <?php endif; ?>
                                        </div>
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
                                        <a href="<?= base_url('modules/kanban/daily_detail.php?date=' . urlencode($d['plan_date'])) ?>"
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
