<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

// Use $_GET directly — PDO prepared statements prevent SQL injection, sanitize only for display
$batch_id = isset($_GET['batch_id']) ? (int)$_GET['batch_id'] : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';

$pdo = getDB();
$batch = null;
$items = [];

if ($batch_id > 0 && $pdo) {
    // Step 1: fetch the batch header (separate try-catch so failure here truly = not found)
    try {
        $stmtBatch = $pdo->prepare("SELECT * FROM did_batches WHERE id = :id");
        $stmtBatch->execute([':id' => $batch_id]);
        $batch = $stmtBatch->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $batch = null;
    }

    // Step 2: fetch items (separate try-catch — failure here must NOT null $batch)
    if ($batch) {
        try {
            $sql = "SELECT * FROM daily_inspection_data WHERE batch_id = :batch_id";
            $params = [':batch_id' => $batch_id];

            if ($search !== '') {
                // Use :search1 and :search2 — PDO does NOT allow same named param twice
                $sql .= " AND (part_code LIKE :search1 OR part_name LIKE :search2)";
                $params[':search1'] = '%' . $search . '%';
                $params[':search2'] = '%' . $search . '%';
            }

            if ($statusFilter !== '') {
                $sql .= " AND status_inspect = :status";
                $params[':status'] = $statusFilter;
            }

            $sql .= " ORDER BY id ASC";

            $stmtItems = $pdo->prepare($sql);
            $stmtItems->execute($params);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Items query failed — show empty table, do NOT redirect
            $items = [];
        }
    }
}

// Redirect ONLY if batch_id is completely missing or non-existent in database
if (!$batch) {
    set_flash('error', 'Riwayat DID Batch tidak ditemukan!');
    redirect('modules/did/index.php');
}

$breadcrumbCategory = "DATA REFERENSI";
$pageTitle = "Rincian Item DID (Daily Inspection Data)";
$pageSubtitle = "Daftar rincian lot part yang di-input pada sesi histori ini";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-5 space-y-4">
        
        <?= render_flash() ?>

        <!-- Action & Metadata Header Card -->
        <div class="card p-3 md:p-4 bg-white border border-slate-200/80 rounded-xl shadow-xs">
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <a href="<?= base_url('modules/did/index.php') ?>" class="btn-secondary py-1.5 px-3 text-xs">
                        &larr; Kembali ke Riwayat Batch
                    </a>
                    <div>
                        <div class="flex items-center space-x-2">
                            <h2 class="text-sm font-bold text-slate-800"><?= htmlspecialchars($batch['batch_name']) ?></h2>
                            <?php if ($batch['import_method'] === 'excel_import'): ?>
                                <span class="badge badge-blue">📊 Import Excel</span>
                            <?php else: ?>
                                <span class="badge badge-secondary">📝 Input Manual</span>
                            <?php endif; ?>
                        </div>
                        <p class="text-xs text-slate-500 mt-0.5">
                            Waktu Input: <b><?= date('d M Y, H:i', strtotime($batch['created_at'])) ?> WIB</b> • Total: <b><?= count($items) ?> Lot Item</b>
                        </p>
                    </div>
                </div>

                <!-- Edit Batch Button -->
                <div>
                    <a href="<?= base_url('modules/did/edit_batch.php?batch_id=' . $batch['id']) ?>" class="btn-primary py-1.5 px-3 text-xs">
                        <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                        </svg>
                        Edit / Tambah Baris Sesi Ini
                    </a>
                </div>
            </div>
        </div>

        <!-- Filter Bar — JS-driven navigation (no HTML form needed) -->
        <div class="card p-2.5 bg-white border border-slate-200/80 rounded-xl shadow-xs">
            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">

                <!-- Search Input Part Code / Part Name -->
                <div style="position: relative; width: 280px; max-width: 100%;">
                    <svg style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 14px; height: 14px; color: #94a3b8; pointer-events: none;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                    <input type="text" id="search-input" value="<?= htmlspecialchars($search) ?>" 
                           placeholder="Cari Part Code / Nama Part..." class="form-input py-1 text-xs" style="padding-left: 30px; width: 100%;"
                           onkeydown="if(event.key==='Enter'){doFilter();}">
                </div>

                <!-- Status Filter -->
                <select id="status-filter" class="form-input py-1 text-xs" style="width: 120px;">
                    <option value="">-- Status --</option>
                    <option value="OK" <?= ($statusFilter === 'OK') ? 'selected' : '' ?>>Status OK</option>
                    <option value="NG" <?= ($statusFilter === 'NG') ? 'selected' : '' ?>>Status NG</option>
                </select>

                <button type="button" onclick="doFilter()" class="btn-secondary py-1 px-3 text-xs font-bold cursor-pointer">Cari</button>

                <?php if ($search !== '' || $statusFilter !== ''): ?>
                    <a href="<?= base_url('modules/did/detail_batch.php') ?>?batch_id=<?= (int)$batch['id'] ?>" class="text-[11px] text-rose-600 font-semibold hover:underline">Reset Filter</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Table Card Container -->
        <div class="card p-0 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs text-slate-700">
                    <thead class="bg-slate-50 text-slate-600 font-bold border-b border-slate-200/80 uppercase tracking-wider text-[10px]">
                        <tr>
                            <th class="px-4 py-3">No</th>
                            <th class="px-4 py-3">Part Code</th>
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">Lot No</th>
                            <th class="px-4 py-3 text-center">Cav</th>
                            <th class="px-4 py-3">Date</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">PIC</th>
                            <th class="px-4 py-3">Remark</th>
                            <th class="px-4 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($items)): ?>
                            <tr>
                                <td colspan="10" class="px-4 py-8 text-center text-slate-400">
                                    <?php if ($search !== '' || $statusFilter !== ''): ?>
                                        <div class="space-y-1">
                                            <p class="font-semibold text-slate-600">Tidak ada item lot yang cocok dengan pencarian "<b><?= htmlspecialchars($search) ?></b>" pada batch ini.</p>
                                            <div class="pt-2">
                                                <a href="detail_batch.php?batch_id=<?= (int)$batch['id'] ?>" class="btn-secondary py-1 px-3 text-xs inline-block">
                                                    Reset Pencarian
                                                </a>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        Belum ada item lot pada batch ini.
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($items as $index => $row): ?>
                                <tr class="hover:bg-slate-50/80 transition-colors">
                                    <td class="px-4 py-3 font-semibold text-slate-400"><?= $index + 1 ?></td>
                                    <td class="px-4 py-3 font-extrabold text-blue-700 font-mono tracking-tight">
                                        <?= htmlspecialchars($row['part_code']) ?>
                                    </td>
                                    <td class="px-4 py-3 font-semibold text-slate-800">
                                        <?= htmlspecialchars($row['part_name']) ?>
                                    </td>
                                    <td class="px-4 py-3 font-mono font-bold text-slate-700">
                                        <?= htmlspecialchars($row['lot_number']) ?>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <span class="badge badge-secondary font-mono font-bold px-2 py-0.5"><?= htmlspecialchars($row['cavity']) ?></span>
                                    </td>
                                    <td class="px-4 py-3 text-[11px] text-slate-500">
                                        <?= date('d M Y', strtotime($row['inspecting_date'])) ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php if ($row['status_inspect'] === 'OK'): ?>
                                            <span class="badge badge-success">✓ OK</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger">⚠ NG</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 font-medium text-slate-700">
                                        <?= htmlspecialchars($row['pic']) ?>
                                    </td>
                                    <td class="px-4 py-3 text-slate-700 font-medium text-xs">
                                        <?= htmlspecialchars($row['remark'] ?? '-') ?>
                                    </td>
                                    <td class="px-4 py-3 text-right space-x-1">
                                        <!-- Edit Item -->
                                        <a href="<?= base_url('modules/did/edit.php?id=' . $row['id']) ?>" 
                                           class="btn-icon text-indigo-600 hover:bg-indigo-50" title="Edit Item">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                            </svg>
                                        </a>
                                        <!-- Delete Item -->
                                        <button type="button" 
                                                onclick="confirmDelete('<?= base_url('modules/did/delete.php?id=' . $row['id']) ?>', 'Item Lot <?= htmlspecialchars($row['lot_number'], ENT_QUOTES) ?>')"
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
    var BATCH_ID = <?= (int)$batch['id'] ?>;
    var BASE = '<?= base_url('modules/did/detail_batch.php') ?>';

    function doFilter() {
        var search = document.getElementById('search-input').value;
        var status = document.getElementById('status-filter').value;
        var url = BASE + '?batch_id=' + BATCH_ID;
        if (search.trim() !== '') url += '&search=' + encodeURIComponent(search.trim());
        if (status !== '') url += '&status=' + encodeURIComponent(status);
        window.location.href = url;
    }
    </script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
