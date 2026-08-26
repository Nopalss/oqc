<?php
$breadcrumbCategory = "DATA MASTER";
$pageTitle = "Master Data Customer / PT Tujuan";
$pageSubtitle = "Kelola data daftar pelanggan / perusahaan tujuan pengiriman untuk item Kanban & OQC";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

$pdo = getDB();
$customers = [];
$search = sanitize($_GET['search'] ?? '');

$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT) ?: 10;
if (!in_array($limit, [5, 10, 25, 50, 100])) $limit = 10;

$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;
$totalItems = 0;
$totalPages = 1;

if ($pdo) {
    try {
        $countSql = "SELECT COUNT(*) FROM master_customers WHERE 1=1";
        $countParams = [];

        if (!empty($search)) {
            $countSql .= " AND name LIKE :search";
            $countParams[':search'] = '%' . $search . '%';
        }

        $stmtCount = $pdo->prepare($countSql);
        $stmtCount->execute($countParams);
        $totalItems = (int)$stmtCount->fetchColumn();

        $totalPages = ceil($totalItems / $limit);
        if ($totalPages < 1) $totalPages = 1;
        if ($page > $totalPages) $page = $totalPages;

        $sql = "SELECT * FROM master_customers WHERE 1=1";
        $params = [];

        if (!empty($search)) {
            $sql .= " AND name LIKE :search";
            $params[':search'] = '%' . $search . '%';
        }

        $sql .= " ORDER BY name ASC LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();

        $customers = $stmt->fetchAll();
    } catch (PDOException $e) {
        $customers = [];
    }
}
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-5 space-y-3">
        
        <?= render_flash() ?>

        <!-- Action & Filter Bar -->
        <div class="card p-2.5 bg-white border border-slate-200/80 rounded-xl shadow-xs">
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap;">
                
                <form action="" method="GET" style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap; flex: 1;">
                    <div style="position: relative; width: 260px; max-width: 100%;">
                        <svg style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 14px; height: 14px; color: #94a3b8; pointer-events: none;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                               placeholder="Cari Nama Customer / PT..." class="form-input py-1 text-xs" style="padding-left: 30px; width: 100%;">
                    </div>

                    <select name="limit" onchange="this.form.submit()" class="form-input py-1 text-xs font-semibold text-slate-700" style="width: 90px;">
                        <option value="5" <?= ($limit == 5) ? 'selected' : '' ?>>5 / hal</option>
                        <option value="10" <?= ($limit == 10) ? 'selected' : '' ?>>10 / hal</option>
                        <option value="25" <?= ($limit == 25) ? 'selected' : '' ?>>25 / hal</option>
                        <option value="50" <?= ($limit == 50) ? 'selected' : '' ?>>50 / hal</option>
                    </select>

                    <button type="submit" class="btn-secondary py-1 px-2.5 text-xs">Filter</button>
                    <?php if (!empty($search) || $limit != 10): ?>
                        <a href="<?= base_url('modules/master_customers/index.php') ?>" class="text-[11px] text-rose-600 font-semibold hover:underline">Reset</a>
                    <?php endif; ?>
                </form>

                <div>
                    <a href="<?= base_url('modules/master_customers/create.php') ?>" class="btn-primary py-1 px-3 text-xs flex items-center">
                        <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                        </svg>
                        + Tambah Customer Baru
                    </a>
                </div>

            </div>
        </div>

        <!-- Table Card -->
        <div class="card p-0 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs text-slate-700">
                    <thead class="bg-slate-50 text-slate-600 font-bold border-b border-slate-200/80 uppercase tracking-wider text-[10px]">
                        <tr>
                            <th class="px-4 py-3 text-center w-12">No</th>
                            <th class="px-4 py-3">Nama Customer / Perusahaan PT</th>
                            <th class="px-4 py-3">Tanggal Terdaftar</th>
                            <th class="px-4 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($customers)): ?>
                            <tr>
                                <td colspan="4" class="px-4 py-8 text-center text-slate-400">
                                    Belum ada data Customer. Silakan tambah data baru.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($customers as $index => $c): ?>
                                <tr class="hover:bg-slate-50/80 transition-colors">
                                    <td class="px-4 py-3 text-center font-semibold text-slate-400"><?= $offset + $index + 1 ?></td>
                                    <td class="px-4 py-3 font-bold text-slate-800 flex items-center gap-2">
                                        <div class="w-7 h-7 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center font-bold text-xs flex-shrink-0 border border-blue-100">
                                            🏢
                                        </div>
                                        <span><?= htmlspecialchars($c['name']) ?></span>
                                    </td>
                                    <td class="px-4 py-3 text-slate-500 text-[11px]">
                                        <?= date('d M Y, H:i', strtotime($c['created_at'])) ?>
                                    </td>
                                    <td class="px-4 py-3 text-right space-x-1">
                                        <a href="<?= base_url('modules/master_customers/edit.php?id=' . $c['id']) ?>" 
                                           class="btn-icon text-indigo-600 hover:bg-indigo-50" title="Edit Customer">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                            </svg>
                                        </a>
                                        <button type="button" 
                                                onclick="confirmDelete('<?= base_url('modules/master_customers/delete.php?id=' . $c['id']) ?>', 'Customer <?= htmlspecialchars($c['name'], ENT_QUOTES) ?>')"
                                                class="btn-icon text-rose-600 hover:bg-rose-50" title="Hapus Customer">
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

            <?= render_pagination($page, $totalPages, $totalItems, $limit) ?>

        </div>

    </main>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
