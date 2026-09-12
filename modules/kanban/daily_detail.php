<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

$dateParam = isset($_GET['date']) ? trim($_GET['date']) : '';

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)) {
    set_flash('error', 'Tanggal tidak valid.');
    redirect('modules/kanban/index.php');
}

$pdo     = getDB();
$batches = [];
$daySummary = [
    'total_batch'  => 0,
    'total_items'  => 0,
    'total_pcs'    => 0,
    'kanban_count' => 0,
];

if ($pdo) {
    try {
        // Fetch all Kanban batches on this date, ordered by time ascending
        $stmtBatches = $pdo->prepare(
            "SELECT * FROM kanban_batches
             WHERE DATE(imported_at) = :date AND plan_type = 'kanban'
             ORDER BY imported_at ASC"
        );
        $stmtBatches->execute([':date' => $dateParam]);
        $rawBatches = $stmtBatches->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rawBatches as $b) {
            // Fetch items for each batch (strictly Kanban items only)
            $stmtItems = $pdo->prepare(
                "SELECT * FROM kanban_items 
                 WHERE batch_id = :bid 
                   AND (plan_type IS NULL OR plan_type = 'kanban') 
                   AND (check_type IS NULL OR check_type != 'Safety Stock') 
                   AND (kanban_no IS NULL OR kanban_no NOT LIKE 'SS-%') 
                 ORDER BY id ASC"
            );
            $stmtItems->execute([':bid' => $b['id']]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            if (empty($items)) continue;

            $batchPcs = array_sum(array_column($items, 'qty'));

            $batches[] = [
                'batch'     => $b,
                'items'     => $items,
                'item_count'=> count($items),
                'total_pcs' => $batchPcs,
            ];

            // Accumulate summary
            $daySummary['total_batch']++;
            $daySummary['total_items'] += count($items);
            $daySummary['total_pcs']   += $batchPcs;
            $daySummary['kanban_count']++;
        }

    } catch (PDOException $e) {
        $batches = [];
    }
}

if (empty($batches)) {
    set_flash('error', 'Tidak ada data Planning Kanban pada tanggal yang dipilih.');
    redirect('modules/kanban/index.php');
}

$breadcrumbCategory = "DATA REFERENSI";
$pageTitle   = "Detail Planning Kanban — " . date('d F Y', strtotime($dateParam));
$pageSubtitle = "Rincian semua batch planning Kanban pada tanggal ini";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen min-w-0 w-full overflow-x-hidden transition-all duration-300">

    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-5 space-y-4 min-w-0 w-full overflow-x-hidden">

        <?= render_flash() ?>

        <!-- Top Bar: Back + Day Summary -->
        <div class="card p-3 md:p-4 bg-white border border-slate-200/80 rounded-xl shadow-xs">
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">

                <div style="display: flex; align-items: center; gap: 12px;">
                    <a href="<?= base_url('modules/kanban/index.php') ?>" class="btn-secondary py-1.5 px-3 text-xs">
                        &larr; Kembali ke Planning
                    </a>
                    <div>
                        <h2 class="text-sm font-extrabold text-slate-800">
                            <?= date('l, d F Y', strtotime($dateParam)) ?>
                        </h2>
                        <div class="flex items-center gap-3 mt-1 flex-wrap">
                            <span class="text-[11px] text-slate-500 font-medium">
                                <span class="font-bold text-slate-700"><?= (int)$daySummary['total_batch'] ?></span> Batch
                            </span>
                            <span class="text-[11px] text-slate-500 font-medium">
                                <span class="font-bold text-slate-700"><?= number_format($daySummary['total_items']) ?></span> Item
                            </span>
                            <span class="text-[11px] text-slate-500 font-medium">
                                <span class="font-bold text-slate-700"><?= number_format($daySummary['total_pcs']) ?></span> pcs total
                            </span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold bg-blue-100 text-blue-800 border border-blue-200">
                                Kanban (Kirim)
                            </span>
                        </div>
                    </div>
                </div>

                <a href="<?= base_url('modules/kanban/create.php') ?>" class="btn-primary py-1.5 px-3 text-xs">
                    <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                    Tambah Planning Baru
                </a>

            </div>
        </div>

        <!-- Live Search Box (searches across ALL tables on the page) -->
        <div class="card p-2.5 bg-white border border-slate-200/80 rounded-xl shadow-xs">
            <div style="position: relative; width: 100%;">
                <svg style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 14px; height: 14px; color: #94a3b8; pointer-events: none;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
                <input type="text" id="daily-search-input" oninput="filterDailyRows()"
                       placeholder="Cari cepat: Item Code, Kanban No, Deskripsi, Customer..." 
                       class="form-input py-1.5 text-xs" style="padding-left: 30px; width: 100%;">
            </div>
        </div>

        <!-- Batch Cards Loop -->
        <?php foreach ($batches as $bIdx => $entry): ?>
            <?php
            $b     = $entry['batch'];
            $items = $entry['items'];
            $isSafety = ($b['plan_type'] ?? 'kanban') === 'safety_stock';
            ?>

            <!-- Batch Header Card -->
            <div class="card overflow-hidden">

                <!-- Batch Header Bar -->
                <div class="px-4 py-3 border-b border-slate-200/80 flex items-center justify-between gap-3 flex-wrap"
                     style="background: <?= $isSafety ? 'linear-gradient(135deg, #f5f3ff 0%, #ede9fe 100%)' : 'linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%)' ?>;">

                    <div class="flex items-center gap-3 flex-wrap">

                        <!-- Type Badge -->
                        <?php if ($isSafety): ?>
                            <span style="display:inline-flex; align-items:center; padding:4px 10px; border-radius:6px; font-size:11px; font-weight:800; background:#7c3aed; color:#ffffff; letter-spacing:0.04em;">
                                Safety Stock
                            </span>
                        <?php else: ?>
                            <span style="display:inline-flex; align-items:center; padding:4px 10px; border-radius:6px; font-size:11px; font-weight:800; background:#2563eb; color:#ffffff; letter-spacing:0.04em;">
                                Kanban
                            </span>
                        <?php endif; ?>

                        <div>
                            <div class="flex items-center gap-2">
                                <span class="font-mono font-extrabold text-slate-800 text-sm">
                                    <?= htmlspecialchars($b['document_number'] ?? 'DOC-MNL') ?>
                                </span>
                                <?php if (($b['import_method'] ?? '') === 'excel_import'): ?>
                                    <span class="badge badge-blue">Import Excel</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">Input Manual</span>
                                <?php endif; ?>
                            </div>
                            <div class="text-[11px] text-slate-500 font-medium mt-0.5">
                                Input: <strong><?= date('H:i', strtotime($b['imported_at'])) ?> WIB</strong>
                                &bull; <strong><?= (int)$entry['item_count'] ?></strong> Item
                                &bull; <strong><?= number_format($entry['total_pcs']) ?></strong> pcs
                            </div>
                        </div>
                    </div>

                    <!-- Batch Actions -->
                    <div class="flex items-center gap-2">
                        <a href="<?= base_url('modules/kanban/edit_batch.php?batch_id=' . $b['id']) ?>"
                           class="btn-primary py-1 px-2.5 text-xs inline-flex items-center">
                            <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                            </svg>
                            Edit Batch
                        </a>
                        <button type="button"
                                onclick="confirmDelete('<?= base_url('modules/kanban/delete_batch.php?id=' . $b['id']) ?>', 'Dokumen <?= htmlspecialchars($b['document_number'] ?? '', ENT_QUOTES) ?>')"
                                class="btn-icon text-rose-600 hover:bg-rose-50" title="Hapus Batch Ini">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                            </svg>
                        </button>
                    </div>

                </div>

                <!-- Items Table — max 10 rows visible, rest scrollable -->
                <div style="overflow-x: auto; overflow-y: auto; max-height: 450px;">
                    <table class="w-full text-left text-xs text-slate-700 searchable-table min-w-[1100px]">
                        <thead style="position: sticky; top: 0; z-index: 2; background: #f8fafc;" class="text-slate-600 font-bold border-b border-slate-200/80 uppercase tracking-wider text-[10px]">
                            <tr>
                                <th class="px-4 py-2.5">No</th>
                                <th class="px-4 py-2.5">Kanban No</th>
                                <th class="px-4 py-2.5">Item Code</th>
                                <th class="px-4 py-2.5">Deskripsi Item</th>
                                <th class="px-4 py-2.5">Customer</th>
                                <th class="px-4 py-2.5">Qty</th>
                                <th class="px-4 py-2.5">Req Date</th>
                                <th class="px-4 py-2.5">ETA</th>
                                <th class="px-4 py-2.5">Storage Loc</th>
                                <th class="px-4 py-2.5 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (empty($items)): ?>
                                <tr>
                                    <td colspan="10" class="px-4 py-5 text-center text-slate-400">
                                        Tidak ada item pada batch ini.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($items as $i => $row): ?>
                                    <tr class="hover:bg-slate-50/80 transition-colors daily-item-row">
                                        <td class="px-4 py-2.5 font-semibold text-slate-400"><?= $i + 1 ?></td>
                                        <td class="px-4 py-2.5 font-extrabold text-blue-700 font-mono tracking-tight">
                                            <?= htmlspecialchars($row['kanban_no'] ?? '-') ?>
                                        </td>
                                        <td class="px-4 py-2.5 font-mono font-bold text-slate-800">
                                            <?= htmlspecialchars($row['item_code']) ?>
                                        </td>
                                        <td class="px-4 py-2.5 font-semibold text-slate-800">
                                            <?= htmlspecialchars($row['item_description']) ?>
                                            <?php if (!empty($row['check_type'])): ?>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-extrabold bg-amber-100 text-amber-800 border border-amber-300/80 ml-1.5" title="Instruksi Cek 100%">
                                                    <?= htmlspecialchars($row['check_type']) ?> Cek
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-2.5">
                                            <span class="badge badge-blue"><?= htmlspecialchars($row['customer'] ?? '-') ?></span>
                                        </td>
                                        <td class="px-4 py-2.5 font-extrabold text-slate-800">
                                            <?= number_format($row['qty']) ?> pcs
                                        </td>
                                        <td class="px-4 py-2.5 text-[11px] text-slate-700 font-medium">
                                            <?php
                                            if (!empty($row['req_date'])) {
                                                $t = strtotime($row['req_date']);
                                                echo (date('H:i:s', $t) !== '00:00:00')
                                                    ? date('d M Y, H:i', $t)
                                                    : date('d M Y', $t);
                                            } else {
                                                echo '-';
                                            }
                                            ?>
                                        </td>
                                        <td class="px-4 py-2.5 text-[11px] text-slate-700 font-medium">
                                            <?php
                                            if (!empty($row['eta'])) {
                                                $t = strtotime($row['eta']);
                                                echo (date('H:i:s', $t) !== '00:00:00')
                                                    ? date('d M Y, H:i', $t)
                                                    : date('d M Y', $t);
                                            } else {
                                                echo '-';
                                            }
                                            ?>
                                        </td>
                                        <td class="px-4 py-2.5 font-mono text-[11px]">
                                            <?= htmlspecialchars($row['str_loc'] ?? '-') ?>
                                        </td>
                                        <td class="px-4 py-2.5 text-right space-x-1">
                                            <a href="<?= base_url('modules/kanban/edit.php?id=' . $row['id']) ?>"
                                               class="btn-icon text-indigo-600 hover:bg-indigo-50" title="Edit Item">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                </svg>
                                            </a>
                                            <button type="button"
                                                    onclick="confirmDelete('<?= base_url('modules/kanban/delete.php?id=' . $row['id']) ?>', 'Kanban <?= htmlspecialchars($row['kanban_no'] ?? '', ENT_QUOTES) ?>')"
                                                    class="btn-icon text-rose-600 hover:bg-rose-50" title="Hapus Item">
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

            </div><!-- end batch card -->

        <?php endforeach; ?>

    </main>

    <script>
    function filterDailyRows() {
        var query = document.getElementById('daily-search-input').value.toLowerCase().trim();
        var rows   = document.querySelectorAll('tr.daily-item-row');

        rows.forEach(function(row) {
            var text = row.textContent.toLowerCase();
            row.style.display = (query === '' || text.indexOf(query) !== -1) ? '' : 'none';
        });
    }
    </script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
