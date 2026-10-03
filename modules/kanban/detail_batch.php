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
$itemSessionsMap = [];

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

            $sql = "SELECT k.*, 
                           COALESCE((
                               SELECT SUM(s.total_scanned_qty - COALESCE(s.excess_qty, 0)) 
                               FROM inspection_sessions s 
                               WHERE s.kanban_item_id = k.id AND s.status = 'passed'
                           ), 0) AS total_passed_qty,
                           (
                               SELECT COUNT(s.id) 
                               FROM inspection_sessions s 
                               WHERE s.kanban_item_id = k.id
                           ) AS total_sessions_count
                    FROM kanban_items k 
                    WHERE k.batch_id = :batch_id 
                      AND (k.plan_type IS NULL OR k.plan_type = 'kanban') 
                      AND (k.check_type IS NULL OR k.check_type != 'Safety Stock') 
                      AND (k.kanban_no IS NULL OR k.kanban_no NOT LIKE 'SS-%')";
            $params = [':batch_id' => $batch_id];

            if ($search !== '') {
                $sql .= " AND (k.kanban_no LIKE :search OR k.item_code LIKE :search OR k.item_description LIKE :search OR k.customer LIKE :search OR k.str_loc LIKE :search OR k.remark LIKE :search)";
                $params[':search'] = '%' . $search . '%';
            }

            $sql .= " ORDER BY k.id ASC";

            $stmtItems = $pdo->prepare($sql);
            $stmtItems->execute($params);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            // Fetch session audit history for all items in batch
            if (!empty($items)) {
                $itemIds = array_column($items, 'id');
                $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
                $stmtSess = $pdo->prepare("
                    SELECT s.id, s.kanban_item_id, s.status, s.started_at, s.closed_at, s.total_scanned_qty, s.samples_checked, s.sample_size, s.ng_count, s.inspection_type,
                           COALESCE(u.name, did.pic, 'QC Inspector') AS inspector_name,
                           (SELECT COUNT(*) FROM inspection_session_lots WHERE inspection_session_id = s.id) AS total_lots
                    FROM inspection_sessions s
                    LEFT JOIN users u ON u.id = s.inspector_id
                    LEFT JOIN daily_inspection_data did ON did.id = s.did_id
                    WHERE s.kanban_item_id IN ($placeholders)
                    ORDER BY s.id ASC
                ");
                $stmtSess->execute($itemIds);
                $allSess = $stmtSess->fetchAll(PDO::FETCH_ASSOC);

                foreach ($allSess as $sRow) {
                    $kId = (int)$sRow['kanban_item_id'];
                    if (!isset($itemSessionsMap[$kId])) {
                        $itemSessionsMap[$kId] = [];
                    }
                    $itemSessionsMap[$kId][] = $sRow;
                }
            }
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
$pageSubtitle = "Daftar rincian item jadwal kirim Kanban pada dokumen batch ini beserta riwayat cicilan inspeksi";

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
                        <p class="text-xs text-slate-500 mt-1 flex items-center gap-2 flex-wrap">
                            <span>🏢 Vendor: <b class="font-mono text-slate-700"><?= htmlspecialchars($batch['vendor'] ?? '-') ?></b></span>
                            <?php if (!empty($batch['print_datetime'])): ?>
                                <span>• 🕒 Print Time: <b class="font-mono text-slate-700"><?= date('Y-m-d H:i:s', strtotime($batch['print_datetime'])) ?></b></span>
                            <?php endif; ?>
                            <span>• Waktu Input: <b><?= date('d M Y, H:i', strtotime($batch['imported_at'])) ?> WIB</b></span>
                            <span>• Total: <b><?= count($items) ?> Item</b></span>
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
                <table class="w-full text-left text-xs text-slate-700 min-w-[1250px]">
                    <thead class="bg-slate-50 text-slate-600 font-bold border-b border-slate-200/80 uppercase tracking-wider text-[10px]">
                        <tr>
                            <th class="px-4 py-3">No</th>
                            <th class="px-4 py-3">Kanban No</th>
                            <th class="px-4 py-3">Item Code</th>
                            <th class="px-4 py-3">Item Description</th>
                            <th class="px-4 py-3">Customer</th>
                            <th class="px-4 py-3 text-center">Progress Realisasi Qty</th>
                            <th class="px-4 py-3 text-center">Status Inspeksi</th>
                            <th class="px-4 py-3">Req Date</th>
                            <th class="px-4 py-3">ETA</th>
                            <th class="px-4 py-3">Storage Loc</th>
                            <th class="px-4 py-3 text-center">Riwayat Sesi</th>
                            <th class="px-4 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($items)): ?>
                            <tr>
                                <td colspan="12" class="px-4 py-8 text-center text-slate-400">
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
                            <?php foreach ($items as $index => $row): 
                                $tQty = (int)$row['qty'];
                                $pQty = (int)$row['total_passed_qty'];
                                $remQty = max(0, $tQty - $pQty);
                                $pct = min(100, (int)round(($pQty / max(1, $tQty)) * 100));
                                $sessList = $itemSessionsMap[(int)$row['id']] ?? [];
                                $sessCount = count($sessList);
                            ?>
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
                                    <!-- Target vs Realisasi Progress -->
                                    <td class="px-4 py-3 text-center min-w-[150px]">
                                        <div class="font-extrabold text-slate-800">
                                            <span class="text-blue-700"><?= number_format($pQty) ?></span> / <?= number_format($tQty) ?> pcs
                                        </div>
                                        <div class="w-full bg-slate-200 rounded-full h-1.5 mt-1.5 overflow-hidden">
                                            <div class="bg-blue-600 h-1.5 rounded-full transition-all duration-500" style="width: <?= $pct ?>%;"></div>
                                        </div>
                                        <div class="text-[10px] text-slate-500 font-medium mt-0.5">
                                            <?= $pct ?>% Terpenuhi <?= ($remQty > 0 && $pQty > 0) ? '(Sisa: ' . number_format($remQty) . ' pcs)' : '' ?>
                                        </div>
                                    </td>
                                    <!-- Status Inspeksi Badge -->
                                    <td class="px-4 py-3 text-center">
                                        <?php 
                                        $kbStatus = $row['status'] ?? '';
                                        if (empty($kbStatus)) {
                                            if ($pQty >= $tQty && $tQty > 0) $kbStatus = 'completed';
                                            elseif ($pQty > 0) $kbStatus = 'partial';
                                            else $kbStatus = 'uninspected';
                                        }
                                        ?>
                                        <?php if ($kbStatus === 'in_progress'): ?>
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-md text-[11px] font-extrabold bg-indigo-100 text-indigo-800 border border-indigo-300">
                                                Sedang Diinspeksi
                                            </span>
                                        <?php elseif ($kbStatus === 'completed'): ?>
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-md text-[11px] font-extrabold bg-emerald-100 text-emerald-800 border border-emerald-300">
                                                Terpenuhi (Penuh)
                                            </span>
                                        <?php elseif ($kbStatus === 'partial'): ?>
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-md text-[11px] font-extrabold bg-amber-100 text-amber-800 border border-amber-300">
                                                Parsial (Bertahap)
                                            </span>
                                        <?php elseif ($kbStatus === 'rejected'): ?>
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-md text-[11px] font-extrabold bg-rose-100 text-rose-800 border border-rose-300">
                                                Reject Mutu
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-md text-[11px] font-semibold bg-slate-100 text-slate-600 border border-slate-200">
                                                Belum Diinspeksi
                                            </span>
                                        <?php endif; ?>
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
                                    <!-- Riwayat Sesi Accordion Button -->
                                    <td class="px-4 py-3 text-center">
                                        <?php if ($sessCount > 0): ?>
                                            <button type="button" 
                                                    onclick="toggleSessionHistory(<?= $row['id'] ?>)" 
                                                    class="inline-flex items-center px-2.5 py-1 text-[11px] font-bold text-indigo-700 bg-indigo-50 border border-indigo-200 rounded-lg hover:bg-indigo-100 transition-colors">
                                                📋 <?= $sessCount ?> Sesi Inspeksi
                                                <svg id="chevron-<?= $row['id'] ?>" class="w-3.5 h-3.5 ml-1 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                                </svg>
                                            </button>
                                        <?php else: ?>
                                            <span class="text-[11px] text-slate-400 italic">Belum Ada Sesi</span>
                                        <?php endif; ?>
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

                                <!-- Expandable Session History Drawer Row -->
                                <?php if ($sessCount > 0): ?>
                                    <tr id="sess-row-<?= $row['id'] ?>" class="hidden bg-slate-50/90 border-b border-slate-200">
                                        <td colspan="12" class="p-3 md:px-6 md:py-4">
                                            <div class="bg-white border border-slate-200 rounded-xl p-3 shadow-xs">
                                                <div class="flex items-center justify-between pb-2 mb-2 border-b border-slate-100">
                                                    <h4 class="text-xs font-bold text-slate-800 flex items-center gap-1.5">
                                                        <span>📜 Riwayat Sesi Inspeksi</span>
                                                        <span class="badge badge-blue text-[10px]">Kanban: <?= htmlspecialchars($row['kanban_no']) ?></span>
                                                    </h4>
                                                    <span class="text-[11px] text-slate-500 font-medium">Total Terpenuhi: <b class="text-blue-700"><?= number_format($pQty) ?> / <?= number_format($tQty) ?> pcs</b></span>
                                                </div>
                                                <table class="w-full text-left text-xs text-slate-700">
                                                    <thead class="bg-slate-100 text-slate-600 font-bold text-[10px] uppercase">
                                                        <tr>
                                                            <th class="px-3 py-2">Sesi #</th>
                                                            <th class="px-3 py-2">QC Inspector</th>
                                                            <th class="px-3 py-2">Waktu Mulai</th>
                                                            <th class="px-3 py-2">Waktu Selesai</th>
                                                            <th class="px-3 py-2 text-right">Qty Inspeksi</th>
                                                            <th class="px-3 py-2 text-center">Label / Box Scanned</th>
                                                            <th class="px-3 py-2 text-center">Status Sesi</th>
                                                            <th class="px-3 py-2 text-right">Aksi</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-slate-100">
                                                        <?php foreach ($sessList as $sIdx => $sItem): ?>
                                                            <tr class="hover:bg-slate-50">
                                                                <td class="px-3 py-2 font-bold text-indigo-700 font-mono">
                                                                    Sesi #<?= $sIdx + 1 ?> <span class="text-[10px] text-slate-400">(ID: <?= $sItem['id'] ?>)</span>
                                                                </td>
                                                                <td class="px-3 py-2 font-semibold text-slate-800">
                                                                    👤 <?= htmlspecialchars($sItem['inspector_name']) ?>
                                                                </td>
                                                                <td class="px-3 py-2 text-[11px] text-slate-600">
                                                                    🕒 <?= date('d M Y, H:i:s', strtotime($sItem['started_at'])) ?>
                                                                </td>
                                                                <td class="px-3 py-2 text-[11px] text-slate-600">
                                                                    <?= !empty($sItem['closed_at']) ? '🏁 ' . date('d M Y, H:i:s', strtotime($sItem['closed_at'])) : '<span class="text-amber-600 italic font-semibold">Sedang Berlangsung</span>' ?>
                                                                </td>
                                                                <td class="px-3 py-2 text-right font-extrabold text-blue-700">
                                                                    <?= number_format($sItem['total_scanned_qty']) ?> pcs
                                                                </td>
                                                                <td class="px-3 py-2 text-center font-semibold text-slate-700">
                                                                    📦 <?= (int)$sItem['total_lots'] ?> Box
                                                                </td>
                                                                <td class="px-3 py-2 text-center">
                                                                    <?php if ($sItem['status'] === 'passed'): ?>
                                                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-extrabold bg-emerald-100 text-emerald-800">
                                                                            ✓ PASSED (Lolos)
                                                                        </span>
                                                                    <?php elseif ($sItem['status'] === 'rejected'): ?>
                                                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-extrabold bg-rose-100 text-rose-800">
                                                                            ✕ REJECTED (Ditolak)
                                                                        </span>
                                                                    <?php else: ?>
                                                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-extrabold bg-amber-100 text-amber-800">
                                                                            ⏳ IN PROGRESS
                                                                        </span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td class="px-3 py-2 text-right">
                                                                    <a href="<?= base_url('modules/inspection/session.php?id=' . $sItem['id']) ?>" 
                                                                       class="btn-secondary py-0.5 px-2 text-[10px] font-bold">
                                                                        Buka Workbench &rarr;
                                                                    </a>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
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
            // Skip inner session drawer rows in filter count
            if (rows[i].id && rows[i].id.indexOf('sess-row-') === 0) continue;

            var text = rows[i].textContent.toLowerCase();
            if (query === '' || text.indexOf(query) !== -1) {
                rows[i].style.display = '';
            } else {
                rows[i].style.display = 'none';
            }
        }
    }

    function toggleSessionHistory(itemId) {
        var row = document.getElementById('sess-row-' + itemId);
        var chevron = document.getElementById('chevron-' + itemId);
        if (!row) return;

        if (row.classList.contains('hidden')) {
            row.classList.remove('hidden');
            if (chevron) chevron.style.transform = 'rotate(180deg)';
        } else {
            row.classList.add('hidden');
            if (chevron) chevron.style.transform = 'rotate(0deg)';
        }
    }
    </script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
