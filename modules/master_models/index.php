<?php
$breadcrumbCategory = "MASTER DATA";
$pageTitle          = "Master Data Model";
$pageSubtitle       = "Kelola master nama model produk (1 Model ➔ Banyak Part) — PT. Surya Technology Industri";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

$pdo = getDB();

$search = sanitize($_GET['search'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 10;
$offset = ($page - 1) * $limit;

$models     = [];
$totalRows  = 0;
$totalPages = 1;

if ($pdo) {
    try {
        $params = [];
        $where  = " WHERE 1=1 ";

        if ($search !== '') {
            $where .= " AND m.name LIKE :search ";
            $params[':search'] = '%' . $search . '%';
        }

        // Count total
        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM master_models m {$where}");
        $stmtCount->execute($params);
        $totalRows  = (int)$stmtCount->fetchColumn();
        $totalPages = max(1, ceil($totalRows / $limit));

        // Fetch items with count of assigned parts
        $sql = "SELECT m.*, COUNT(p.id) AS total_parts 
                FROM master_models m 
                LEFT JOIN master_parts p ON p.model_id = m.id 
                {$where} 
                GROUP BY m.id 
                ORDER BY m.name ASC 
                LIMIT {$limit} OFFSET {$offset}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $models = $stmt->fetchAll();

    } catch (PDOException $e) {
        set_flash('error', 'Gagal memuat data master model: ' . $e->getMessage());
    }
}
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col transition-all duration-300 min-h-screen bg-slate-100">
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <div class="p-6 space-y-6 flex-1">
        
        <?= render_flash() ?>

        <!-- Page Header & Action Bar -->
        <div class="card p-4 flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
            <div>
                <h1 class="text-lg font-extrabold text-slate-800 tracking-tight">Master Data Model Produk</h1>
                <p class="text-xs text-slate-500 mt-0.5">Kelola daftar nama model produk secara terpusat.</p>
            </div>
            
            <div class="flex items-center space-x-3 w-full md:w-auto justify-end">
                <a href="<?= base_url('modules/master_models/create.php') ?>" class="btn-primary py-2 px-4 text-xs font-bold flex items-center space-x-1.5 shadow-md">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                    <span>Tambah Model Baru</span>
                </a>
            </div>
        </div>

        <!-- Search & Table Card -->
        <div class="card p-4 space-y-4">
            
            <!-- Search Bar -->
            <form action="" method="GET" class="flex flex-col sm:flex-row items-center justify-between gap-3">
                <div class="relative w-full sm:w-80">
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                           placeholder="Cari nama model..." 
                           class="form-input text-xs pl-9 pr-4 py-2 rounded-xl w-full border-slate-300">
                    <svg class="w-4 h-4 text-slate-400 absolute left-3 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                </div>
                <div class="flex items-center space-x-2 w-full sm:w-auto justify-end">
                    <button type="submit" class="btn-secondary py-2 px-4 text-xs font-bold">Cari</button>
                    <?php if ($search !== ''): ?>
                        <a href="<?= base_url('modules/master_models/index.php') ?>" class="text-xs text-red-600 font-semibold hover:underline">Reset</a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Table -->
            <div class="overflow-x-auto border border-slate-200 rounded-xl">
                <table class="w-full text-xs text-left border-collapse">
                    <thead class="bg-slate-900 text-white font-bold uppercase text-[10px] tracking-wider">
                        <tr>
                            <th class="p-3 text-center w-12">No</th>
                            <th class="p-3">Nama Model Produk</th>
                            <th class="p-3 text-center">Jumlah Part Terkait</th>
                            <th class="p-3">Tanggal Dibuat</th>
                            <th class="p-3 text-center w-28">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 font-sans">
                        <?php if (empty($models)): ?>
                            <tr>
                                <td colspan="5" class="p-8 text-center text-slate-400 italic">
                                    Belum ada data master model.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($models as $idx => $m): ?>
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <td class="p-3 text-center font-bold text-slate-400"><?= $offset + $idx + 1 ?></td>
                                    <td class="p-3 font-extrabold text-slate-800 tracking-wide text-sm">
                                        <?= htmlspecialchars($m['name']) ?>
                                    </td>
                                    <td class="p-3 text-center font-mono font-bold">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold <?= $m['total_parts'] > 0 ? 'bg-blue-100 text-blue-800' : 'bg-slate-100 text-slate-500' ?>">
                                            <?= number_format($m['total_parts']) ?> Part
                                        </span>
                                    </td>
                                    <td class="p-3 text-slate-500 font-mono text-[11px]">
                                        <?= date('d M Y, H:i', strtotime($m['created_at'])) ?>
                                    </td>
                                    <td class="p-3 text-center">
                                        <div class="flex items-center justify-center space-x-2">
                                            <a href="<?= base_url('modules/master_models/edit.php?id=' . $m['id']) ?>" 
                                               class="p-1.5 rounded-lg text-blue-600 hover:bg-blue-50 transition-colors" title="Edit Model">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                </svg>
                                            </a>
                                            <button type="button" 
                                                    onclick="confirmDelete('<?= base_url('modules/master_models/delete.php?id=' . $m['id']) ?>', 'Model <?= htmlspecialchars($m['name'], ENT_QUOTES) ?>')"
                                                    class="p-1.5 rounded-lg text-red-600 hover:bg-red-50 transition-colors" title="Hapus Model">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="flex items-center justify-between pt-2">
                    <span class="text-xs text-slate-500">Menampilkan <?= $offset + 1 ?> &ndash; <?= min($totalRows, $offset + $limit) ?> dari <?= $totalRows ?> model</span>
                    <div class="flex items-center space-x-1">
                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                            <a href="?page=<?= $p ?>&search=<?= urlencode($search) ?>" 
                               class="px-3 py-1 rounded-lg text-xs font-bold <?= $p === $page ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                                <?= $p ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                </div>
            <?php endif; ?>

        </div>

    </div>

<script>
function confirmDelete(deleteUrl, name) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Hapus Model?',
            text: 'Anda yakin ingin menghapus ' + name + '? Part yang menggunakan model ini akan tetap aman (model reset ke unassigned).',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Ya, Hapus',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = deleteUrl;
            }
        });
    } else {
        if (confirm('Anda yakin ingin menghapus ' + name + '?')) {
            window.location.href = deleteUrl;
        }
    }
}
</script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
