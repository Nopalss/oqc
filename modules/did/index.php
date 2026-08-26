<?php
$breadcrumbCategory = "DATA REFERENSI";
$pageTitle = "Riwayat Upload & Entry DID (Daily Inspection Data)";
$pageSubtitle = "Daftar histori sesi pengunggahan & penginputan status pengecekan dimensi lot produksi per tanggal";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

$pdo = getDB();
$batches = [];
$search = sanitize($_GET['search'] ?? '');
$startDate = sanitize($_GET['start_date'] ?? '');
$endDate = sanitize($_GET['end_date'] ?? '');

// Dynamic Limit
$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT) ?: 10;
if (!in_array($limit, [5, 10, 25, 50, 100])) {
    $limit = 10;
}

$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;
$totalItems = 0;
$totalPages = 1;

if ($pdo) {
    try {
        // Migration check: If unbatched rows exist, auto-create a legacy batch
        $stmtUnbatched = $pdo->query("SELECT COUNT(*) FROM daily_inspection_data WHERE batch_id IS NULL");
        $unbatchedCount = (int)$stmtUnbatched->fetchColumn();
        if ($unbatchedCount > 0) {
            $pdo->exec("INSERT INTO did_batches (batch_name, import_method, total_items, created_at) 
                        SELECT 'Legacy Import DID Batch', 'manual', COUNT(*), MIN(created_at) 
                        FROM daily_inspection_data WHERE batch_id IS NULL");
            $newBatchId = $pdo->lastInsertId();
            $pdo->exec("UPDATE daily_inspection_data SET batch_id = {$newBatchId} WHERE batch_id IS NULL");
        }

        // Count Total Batches
        $countSql = "SELECT COUNT(*) FROM did_batches b WHERE 1=1";
        $countParams = [];

        if (!empty($search)) {
            $countSql .= " AND (b.batch_name LIKE :search)";
            $countParams[':search'] = '%' . $search . '%';
        }

        if (!empty($startDate)) {
            $countSql .= " AND DATE(b.created_at) >= :start_date";
            $countParams[':start_date'] = $startDate;
        }

        if (!empty($endDate)) {
            $countSql .= " AND DATE(b.created_at) <= :end_date";
            $countParams[':end_date'] = $endDate;
        }

        $stmtCount = $pdo->prepare($countSql);
        $stmtCount->execute($countParams);
        $totalItems = (int) $stmtCount->fetchColumn();

        $totalPages = ceil($totalItems / $limit);
        if ($totalPages < 1) $totalPages = 1;
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $limit;

        // Fetch Batches with item breakdown stats
        $sql = "SELECT b.*, 
                       (SELECT COUNT(*) FROM daily_inspection_data d WHERE d.batch_id = b.id) as real_total,
                       (SELECT COUNT(*) FROM daily_inspection_data d WHERE d.batch_id = b.id AND d.status_inspect = 'OK') as count_ok,
                       (SELECT COUNT(*) FROM daily_inspection_data d WHERE d.batch_id = b.id AND d.status_inspect = 'NG') as count_ng
                FROM did_batches b 
                WHERE 1=1";
        $params = [];

        if (!empty($search)) {
            $sql .= " AND (b.batch_name LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }

        if (!empty($startDate)) {
            $sql .= " AND DATE(b.created_at) >= :start_date";
            $params[':start_date'] = $startDate;
        }

        if (!empty($endDate)) {
            $sql .= " AND DATE(b.created_at) <= :end_date";
            $params[':end_date'] = $endDate;
        }

        $sql .= " ORDER BY b.id DESC LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();

        $batches = $stmt->fetchAll();
    } catch (PDOException $e) {
        $batches = [];
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
                    <div style="position: relative; width: 220px; flex-shrink: 0;">
                        <svg style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 14px; height: 14px; color: #94a3b8; pointer-events: none;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                               placeholder="Cari Riwayat Batch..." class="form-input py-1 text-xs" style="padding-left: 30px; width: 100%;">
                    </div>

                    <!-- Date Range -->
                    <div style="display: flex; align-items: center; gap: 4px; flex-shrink: 0;">
                        <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" class="form-input py-1 text-xs" style="width: 125px;">
                        <span class="text-xs text-slate-400">s/d</span>
                        <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" class="form-input py-1 text-xs" style="width: 125px;">
                    </div>

                    <!-- Limit Selector -->
                    <select name="limit" onchange="this.form.submit()" class="form-input py-1 text-xs font-semibold text-slate-700" style="width: 90px; flex-shrink: 0;">
                        <option value="5" <?= ($limit == 5) ? 'selected' : '' ?>>5 / hal</option>
                        <option value="10" <?= ($limit == 10) ? 'selected' : '' ?>>10 / hal</option>
                        <option value="25" <?= ($limit == 25) ? 'selected' : '' ?>>25 / hal</option>
                        <option value="50" <?= ($limit == 50) ? 'selected' : '' ?>>50 / hal</option>
                    </select>

                    <button type="submit" class="btn-secondary py-1 px-2.5 text-xs" style="flex-shrink: 0;">Filter</button>
                    <?php if (!empty($search) || !empty($startDate) || !empty($endDate) || $limit != 10): ?>
                        <a href="<?= base_url('modules/did/index.php') ?>" class="text-[11px] text-rose-600 font-semibold hover:underline" style="flex-shrink: 0;">Reset</a>
                    <?php endif; ?>
                </form>

                <div style="flex-shrink: 0;">
                    <a href="<?= base_url('modules/did/create.php') ?>" class="btn-primary py-1 px-3 text-xs" style="white-space: nowrap;">
                        <svg class="w-3.5 h-3.5 mr-1 inline-block" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                        </svg>
                        Upload / Input DID 
                    </a>
                </div>

            </div>
        </div>

        <!-- History Batches Table Card -->
        <div class="card p-0 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs text-slate-700">
                    <thead class="bg-slate-50 text-slate-600 font-bold border-b border-slate-200/80 uppercase tracking-wider text-[10px]">
                        <tr>
                            <th class="px-4 py-3">No</th>
                            <th class="px-4 py-3">Tanggal Entry</th>
                            <th class="px-4 py-3">Nama Berkas / Referensi Sesi</th>
                            <th class="px-4 py-3">Total Lot Item</th>
                            <th class="px-4 py-3">Ringkasan Status Dimensi</th>
                            <th class="px-4 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($batches)): ?>
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-slate-400">
                                    Belum ada riwayat penginputan DID. Silakan tambah data baru.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($batches as $index => $b): ?>
                                <tr class="hover:bg-slate-50/80 transition-colors">
                                    <td class="px-4 py-3 font-semibold text-slate-400"><?= $offset + $index + 1 ?></td>
                                    <td class="px-4 py-3 font-bold text-slate-800">
                                        <?= date('d M Y', strtotime($b['created_at'])) ?>
                                    </td>
                                    <td class="px-4 py-3 font-medium text-slate-800">
                                        <?= htmlspecialchars($b['batch_name']) ?>
                                    </td>
                                    <td class="px-4 py-3 font-extrabold text-blue-700 font-mono">
                                        <?= number_format($b['real_total']) ?> Lot Item
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center space-x-1.5">
                                            <span class="badge badge-success font-semibold">✓ <?= $b['count_ok'] ?> OK</span>
                                            <?php if ($b['count_ng'] > 0): ?>
                                                <span class="badge badge-danger font-semibold">⚠ <?= $b['count_ng'] ?> NG</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-right space-x-1">
                                        <!-- Detail Button -->
                                        <a href="<?= base_url('modules/did/detail_batch.php?batch_id=' . $b['id']) ?>" 
                                           class="btn-secondary py-1 px-2.5 text-xs inline-flex items-center" title="Lihat Rincian Item Lot">
                                            <svg class="w-3.5 h-3.5 mr-1 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                            </svg>
                                            Detail Item
                                        </a>

                                        <!-- Delete Batch Button -->
                                        <button type="button" 
                                                onclick="confirmDelete('<?= base_url('modules/did/delete_batch.php?id=' . $b['id']) ?>', 'Riwayat Batch <?= htmlspecialchars($b['batch_name'], ENT_QUOTES) ?>')"
                                                class="btn-icon text-rose-600 hover:bg-rose-50" title="Hapus Seluruh Sesi Batch Ini">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                            </svg>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Single Line Pagination Footer -->
            <?= render_pagination($page, $totalPages, $totalItems, $limit) ?>

        </div>

    </main>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
