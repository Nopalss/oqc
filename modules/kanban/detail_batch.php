<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

// Accept batch_id or id parameter cleanly
$batch_id = (int)($_GET['batch_id'] ?? $_GET['id'] ?? 0);
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$pdo = getDB();
$batch = null;
$items = [];

if ($pdo) {
    try {
        if ($batch_id > 0) {
            $stmtBatch = $pdo->prepare("SELECT * FROM kanban_batches WHERE id = :id");
            $stmtBatch->execute([':id' => $batch_id]);
            $batch = $stmtBatch->fetch(PDO::FETCH_ASSOC);
        }

        // Automatic fallback: if batch_id is 0 or invalid, load latest batch document
        if (!$batch) {
            $stmtLatest = $pdo->query("SELECT * FROM kanban_batches ORDER BY id DESC LIMIT 1");
            $batch = $stmtLatest->fetch(PDO::FETCH_ASSOC);
        }

        if ($batch) {
            $batch_id = (int)$batch['id'];

            $sql = "SELECT * FROM kanban_items WHERE batch_id = :batch_id";
            $params = [':batch_id' => $batch_id];

            if ($search !== '') {
                $sql .= " AND (kanban_no LIKE :search OR item_code LIKE :search OR item_description LIKE :search OR customer LIKE :search OR str_loc LIKE :search OR remark LIKE :search)";
                $params[':search'] = '%' . $search . '%';
            }

            $sql .= " ORDER BY id ASC";

            $stmtItems = $pdo->prepare($sql);
            $stmtItems->execute($params);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        $batch = null;
    }
}

if (!$batch) {
    set_flash('error', 'Belum ada dokumen batch Kanban terdaftar!');
    redirect('modules/kanban/index.php');
}

$breadcrumbCategory = "DATA REFERENSI";
$pageTitle = "Rincian Item Kanban (Jadwal Kirim)";
$pageSubtitle = "Daftar rincian item jadwal kirim Kanban pada dokumen batch ini";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen min-w-0 w-full overflow-x-hidden transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-5 space-y-4 min-w-0 w-full overflow-x-hidden">
        
        <?= render_flash() ?>

        <!-- Action & Metadata Card Header -->
        <div class="card p-3 md:p-4 bg-white border border-slate-200/80 rounded-xl shadow-xs">
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <a href="<?= base_url('modules/kanban/index.php') ?>" class="btn-secondary py-1.5 px-3 text-xs">
                        &larr; Kembali ke Riwayat Batch
                    </a>
                    <div>
                        <div class="flex items-center space-x-2">
                            <h2 class="text-sm font-bold text-slate-800">
                                Dokumen No: <span class="font-mono text-blue-700 font-extrabold"><?= htmlspecialchars($batch['document_number'] ?? 'DOC-MNL') ?></span>
                            </h2>
                            <?php if ($batch['import_method'] === 'excel_import'): ?>
                                <span class="badge badge-blue">📊 Import Excel</span>
                            <?php else: ?>
                                <span class="badge badge-secondary">📝 Input Manual</span>
                            <?php endif; ?>
                        </div>
                        <p class="text-xs text-slate-500 mt-0.5">
                            Vendor: <b><?= htmlspecialchars($batch['vendor'] ?? '-') ?></b> • Waktu: <b><?= date('d M Y, H:i', strtotime($batch['imported_at'])) ?> WIB</b> • Total: <b><?= count($items) ?> Item</b>
                        </p>
                    </div>
                </div>

                <!-- Edit Batch Button -->
                <div>
                    <a href="<?= base_url('modules/kanban/edit_batch.php?batch_id=' . $batch['id']) ?>" class="btn-primary py-1.5 px-3 text-xs">
                        <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                        </svg>
                        Edit / Tambah Baris Sesi Ini
                    </a>
                </div>
            </div>
        </div>

        <!-- Instant Real-Time Search Box -->
        <div class="card p-2.5 bg-white border border-slate-200/80 rounded-xl shadow-xs">
            <div style="width: 100%;">
                <input type="text" id="detail-search-input" onkeyup="filterDetailTableRows()"
                       placeholder="Cari cepat Item Code, Kanban No, Deskripsi, Customer, Str Loc..." class="form-input py-1.5 px-3 text-xs" style="width: 100%;">
            </div>
        </div>

        <!-- Table Card Container -->
        <div class="card p-0 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs text-slate-700 min-w-[1200px]">
                    <thead class="bg-slate-50 text-slate-600 font-bold border-b border-slate-200/80 uppercase tracking-wider text-[10px]">
                        <tr>
                            <th class="px-4 py-3">No</th>
                            <th class="px-4 py-3">Kanban No</th>
                            <th class="px-4 py-3">Item Code</th>
                            <th class="px-4 py-3">Item Description</th>
                            <th class="px-4 py-3">Customer</th>
                            <th class="px-4 py-3">Qty</th>
                            <th class="px-4 py-3">Req Date</th>
                            <th class="px-4 py-3">ETA</th>
                            <th class="px-4 py-3">Storage Loc</th>
                            <th class="px-4 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($items)): ?>
                            <tr>
                                <td colspan="10" class="px-4 py-8 text-center text-slate-400">
                                    <?php if ($search !== ''): ?>
                                        <div class="space-y-1">
                                            <p class="font-semibold text-slate-600">Tidak ada item Kanban yang cocok dengan pencarian "<b><?= htmlspecialchars($search) ?></b>" pada dokumen ini.</p>
                                            <div class="pt-2">
                                                <a href="detail_batch.php?batch_id=<?= (int)$batch['id'] ?>" class="btn-secondary py-1 px-3 text-xs inline-block">
                                                    Reset Pencarian
                                                </a>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        Belum ada item pada dokumen batch ini.
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($items as $index => $row): ?>
                                <tr class="hover:bg-slate-50/80 transition-colors">
                                    <td class="px-4 py-3 font-semibold text-slate-400"><?= $index + 1 ?></td>
                                    <td class="px-4 py-3 font-extrabold text-blue-700 font-mono tracking-tight">
                                        <?= htmlspecialchars($row['kanban_no']) ?>
                                    </td>
                                    <td class="px-4 py-3 font-mono font-bold text-slate-800">
                                        <?= htmlspecialchars($row['item_code']) ?>
                                    </td>
                                    <td class="px-4 py-3 font-semibold text-slate-800">
                                        <?= htmlspecialchars($row['item_description']) ?>
                                        <?php if (!empty($row['check_type'])): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-extrabold bg-amber-100 text-amber-800 border border-amber-300/80 ml-1.5" title="Instruksi Cek 100%">
                                                <?= htmlspecialchars($row['check_type']) ?> Cek
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="badge badge-blue"><?= htmlspecialchars($row['customer'] ?? 'Surya Tech') ?></span>
                                    </td>
                                    <td class="px-4 py-3 font-extrabold text-slate-800">
                                        <?= number_format($row['qty']) ?> pcs
                                    </td>
                                    <td class="px-4 py-3 text-[11px] text-slate-700 font-medium">
                                        <?= !empty($row['req_date']) ? (date('H:i:s', strtotime($row['req_date'])) !== '00:00:00' ? date('d M Y, H:i', strtotime($row['req_date'])) : date('d M Y', strtotime($row['req_date']))) : '-' ?>
                                    </td>
                                    <td class="px-4 py-3 text-[11px] text-slate-700 font-medium">
                                        <?= !empty($row['eta']) ? (date('H:i:s', strtotime($row['eta'])) !== '00:00:00' ? date('d M Y, H:i', strtotime($row['eta'])) : date('d M Y', strtotime($row['eta']))) : '-' ?>
                                    </td>
                                    <td class="px-4 py-3 font-mono text-[11px]">
                                        <?= htmlspecialchars($row['str_loc'] ?? '-') ?>
                                    </td>
                                    <td class="px-4 py-3 text-right space-x-1">
                                        <!-- Edit Item -->
                                        <a href="<?= base_url('modules/kanban/edit.php?id=' . $row['id']) ?>" 
                                           class="btn-icon text-indigo-600 hover:bg-indigo-50" title="Edit Item">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                            </svg>
                                        </a>
                                        <!-- Delete Item -->
                                        <button type="button" 
                                                onclick="confirmDelete('<?= base_url('modules/kanban/delete.php?id=' . $row['id']) ?>', 'Kanban <?= htmlspecialchars($row['kanban_no'], ENT_QUOTES) ?>')"
                                                class="btn-icon text-rose-600 hover:bg-rose-50" title="Hapus Item Ini">
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
        </div>

    </main>

    <script>
    function filterDetailTableRows() {
        var query = document.getElementById('detail-search-input').value.toLowerCase().trim();
        var tbody = document.querySelector('tbody.divide-y');
        if (!tbody) return;
        var rows = tbody.querySelectorAll('tr');

        for (var i = 0; i < rows.length; i++) {
            var text = rows[i].textContent.toLowerCase();
            if (query === '' || text.indexOf(query) !== -1) {
                rows[i].style.display = '';
            } else {
                rows[i].style.display = 'none';
            }
        }
    }
    </script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
