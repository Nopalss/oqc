<?php
$breadcrumbCategory = "MASTER DATA";
$pageTitle = "Laporan Hasil Import Berkas ZIP";
$pageSubtitle = "Ringkasan ekstraksi berkas drawing dan dokumen mutu serta kelengkapan dokumen per Part Code";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

require_menu_access('master_drawings');

$report = $_SESSION['import_zip_report'] ?? null;
if (!$report) {
    set_flash('info', 'Tidak ada data laporan import ZIP terbaru.');
    redirect('modules/master_drawings/index.php');
}

$parts = $report['parts'] ?? [];
$totalParts = count($parts);
$totalNew = 0;
$totalComplete = 0;
$totalIncomplete = 0;

$processedRows = [];

foreach ($parts as $code => $p) {
    $isNew = !empty($p['is_new']);
    if ($isNew) $totalNew++;

    $has2D = !empty($p['has_2d']);
    $has3D = !empty($p['has_3d']);
    $hasPI = !empty($p['has_pi']);
    $hasQP = !empty($p['has_qpoint']);
    $hasPAC = !empty($p['has_pac']);
    $hasPack = !empty($p['has_packing']);

    $missing = [];
    if (!$has2D) $missing[] = '2D';
    if (!$has3D) $missing[] = '3D';
    if (!$hasPI) $missing[] = 'PI';
    if (!$hasQP) $missing[] = 'Q-Point';
    if (!$hasPAC) $missing[] = 'PAC';
    if (!$hasPack) $missing[] = 'Packing';

    $isComplete = empty($missing);
    if ($isComplete) {
        $totalComplete++;
    } else {
        $totalIncomplete++;
    }

    $processedRows[] = [
        'part_id' => $p['part_id'],
        'part_code' => $code,
        'part_name' => $p['part_name'],
        'is_new' => $isNew,
        'has_2d' => $has2D,
        'has_3d' => $has3D,
        'has_pi' => $hasPI,
        'has_qpoint' => $hasQP,
        'has_pac' => $hasPAC,
        'has_packing' => $hasPack,
        'is_complete' => $isComplete,
        'missing' => $missing,
        'file_count' => count($p['files'] ?? [])
    ];
}
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-6 space-y-5 max-w-6xl">
        
        <?= render_flash() ?>

        <!-- Header Card -->
        <div class="card p-4 md:p-5 bg-white border border-slate-200/80 rounded-2xl shadow-xs">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-700 flex items-center justify-center font-bold text-sm flex-shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div>
                        <h1 class="text-sm font-extrabold text-slate-800">Laporan Hasil Ekstraksi Berkas ZIP</h1>
                        <p class="text-xs text-slate-400 mt-0.5">Berkas: <span class="font-mono font-semibold text-slate-600"><?= htmlspecialchars($report['zip_name']) ?></span> &bull; Waktu: <span class="text-slate-600"><?= $report['timestamp'] ?></span></p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <a href="<?= base_url('modules/master_drawings/import_zip.php') ?>" class="btn-secondary py-1.5 px-3 text-xs font-semibold rounded-lg">
                        Import ZIP Lain
                    </a>
                    <a href="<?= base_url('modules/master_drawings/index.php') ?>" class="btn-primary py-1.5 px-4 text-xs font-bold rounded-lg inline-flex items-center gap-1.5 shadow-xs">
                        <span>Lihat Master Drawing</span>
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                    </a>
                </div>
            </div>
        </div>

        <!-- 4 Metric Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3.5">
            <div class="card p-3.5 bg-white border border-slate-200/80 rounded-xl shadow-xs">
                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block">Total Part Diproses</span>
                <div class="mt-1 flex items-baseline justify-between">
                    <span class="text-xl font-extrabold text-slate-800"><?= $totalParts ?></span>
                    <span class="text-[11px] text-slate-500 font-medium"><?= (int)$report['total_files_saved'] ?> berkas tersimpan</span>
                </div>
            </div>

            <div class="card p-3.5 bg-white border border-blue-200/80 rounded-xl shadow-xs">
                <span class="text-[11px] font-bold text-blue-600 uppercase tracking-wider block">Part Baru Didaftarkan</span>
                <div class="mt-1 flex items-baseline justify-between">
                    <span class="text-xl font-extrabold text-blue-700"><?= $totalNew ?></span>
                    <span class="text-[11px] text-blue-600 font-medium">Auto-register di DB</span>
                </div>
            </div>

            <div class="card p-3.5 bg-white border border-emerald-200/80 rounded-xl shadow-xs">
                <span class="text-[11px] font-bold text-emerald-600 uppercase tracking-wider block">Dokumen Lengkap 100%</span>
                <div class="mt-1 flex items-baseline justify-between">
                    <span class="text-xl font-extrabold text-emerald-700"><?= $totalComplete ?></span>
                    <span class="text-[11px] text-emerald-600 font-medium">6/6 jenis berkas</span>
                </div>
            </div>

            <div class="card p-3.5 bg-white border border-amber-200/80 rounded-xl shadow-xs">
                <span class="text-[11px] font-bold text-amber-600 uppercase tracking-wider block">Belum Lengkap</span>
                <div class="mt-1 flex items-baseline justify-between">
                    <span class="text-xl font-extrabold text-amber-700"><?= $totalIncomplete ?></span>
                    <span class="text-[11px] text-amber-600 font-medium">Ada berkas kurang</span>
                </div>
            </div>
        </div>

        <!-- Table Card with Local Filter -->
        <div class="card bg-white border border-slate-200/80 rounded-2xl shadow-xs overflow-hidden">
            <div class="p-3.5 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-slate-50/50">
                <div class="flex items-center gap-2">
                    <span class="text-xs font-extrabold text-slate-800 uppercase tracking-wider">Rincian Dokumen per Part Code</span>
                    <span class="px-2 py-0.5 text-[10px] font-bold rounded-full bg-slate-200 text-slate-700" id="filter-counter"><?= $totalParts ?> Part</span>
                </div>
                
                <div class="flex items-center gap-2 flex-wrap">
                    <div class="inline-flex rounded-lg border border-slate-200 bg-white p-0.5 text-xs font-semibold">
                        <button type="button" class="btn-filter-tab px-2.5 py-1 rounded-md text-blue-700 bg-blue-50 font-bold" data-filter="all">Semua</button>
                        <button type="button" class="btn-filter-tab px-2.5 py-1 rounded-md text-slate-600 hover:text-slate-900" data-filter="incomplete">Belum Lengkap</button>
                        <button type="button" class="btn-filter-tab px-2.5 py-1 rounded-md text-slate-600 hover:text-slate-900" data-filter="new">Baru Ditambahkan</button>
                        <button type="button" class="btn-filter-tab px-2.5 py-1 rounded-md text-slate-600 hover:text-slate-900" data-filter="complete">Lengkap</button>
                    </div>

                    <input type="text" id="table-search" placeholder="Cari Part Code / Nama..." class="form-input py-1 px-2.5 text-xs rounded-lg w-48">
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs border-collapse">
                    <thead>
                        <tr class="bg-slate-50 text-slate-500 font-bold border-b border-slate-200/80 uppercase text-[10px] tracking-wider">
                            <th class="py-2.5 px-3">Part Code &amp; Nama</th>
                            <th class="py-2.5 px-2.5 text-center">Status DB</th>
                            <th class="py-2.5 px-2 text-center">2D (PDF)</th>
                            <th class="py-2.5 px-2 text-center">3D (STP)</th>
                            <th class="py-2.5 px-2 text-center">PI</th>
                            <th class="py-2.5 px-2 text-center">Q-Point</th>
                            <th class="py-2.5 px-2 text-center">PAC</th>
                            <th class="py-2.5 px-2 text-center">Packing</th>
                            <th class="py-2.5 px-3">Status Kelengkapan</th>
                            <th class="py-2.5 px-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-medium text-slate-700" id="report-table-body">
                        <?php foreach ($processedRows as $row): ?>
                            <tr class="hover:bg-slate-50/80 transition-colors part-row" 
                                data-new="<?= $row['is_new'] ? '1' : '0' ?>" 
                                data-complete="<?= $row['is_complete'] ? '1' : '0' ?>"
                                data-code="<?= strtolower(htmlspecialchars($row['part_code'])) ?>"
                                data-name="<?= strtolower(htmlspecialchars($row['part_name'])) ?>">
                                
                                <td class="py-2.5 px-3">
                                    <div class="font-mono font-extrabold text-blue-700 text-xs"><?= htmlspecialchars($row['part_code']) ?></div>
                                    <div class="text-[11px] text-slate-500 truncate max-w-[200px]" title="<?= htmlspecialchars($row['part_name']) ?>"><?= htmlspecialchars($row['part_name']) ?></div>
                                </td>

                                <td class="py-2.5 px-2.5 text-center">
                                    <?php if ($row['is_new']): ?>
                                        <span class="inline-block px-2 py-0.5 rounded text-[10px] font-bold bg-blue-100 text-blue-800 border border-blue-200">Baru Dibuat</span>
                                    <?php else: ?>
                                        <span class="inline-block px-2 py-0.5 rounded text-[10px] font-semibold bg-slate-100 text-slate-600 border border-slate-200">Terdaftar</span>
                                    <?php endif; ?>
                                </td>

                                <td class="py-2.5 px-2 text-center">
                                    <?= $row['has_2d'] ? '<span class="text-emerald-700 font-bold text-xs">ADA</span>' : '<span class="text-slate-300 text-xs">-</span>' ?>
                                </td>
                                <td class="py-2.5 px-2 text-center">
                                    <?= $row['has_3d'] ? '<span class="text-emerald-700 font-bold text-xs">ADA</span>' : '<span class="text-slate-300 text-xs">-</span>' ?>
                                </td>
                                <td class="py-2.5 px-2 text-center">
                                    <?= $row['has_pi'] ? '<span class="text-emerald-700 font-bold text-xs">ADA</span>' : '<span class="text-slate-300 text-xs">-</span>' ?>
                                </td>
                                <td class="py-2.5 px-2 text-center">
                                    <?= $row['has_qpoint'] ? '<span class="text-emerald-700 font-bold text-xs">ADA</span>' : '<span class="text-slate-300 text-xs">-</span>' ?>
                                </td>
                                <td class="py-2.5 px-2 text-center">
                                    <?= $row['has_pac'] ? '<span class="text-emerald-700 font-bold text-xs">ADA</span>' : '<span class="text-slate-300 text-xs">-</span>' ?>
                                </td>
                                <td class="py-2.5 px-2 text-center">
                                    <?= $row['has_packing'] ? '<span class="text-emerald-700 font-bold text-xs">ADA</span>' : '<span class="text-slate-300 text-xs">-</span>' ?>
                                </td>

                                <td class="py-2.5 px-3">
                                    <?php if ($row['is_complete']): ?>
                                        <span class="inline-flex items-center gap-1 text-[11px] font-bold text-emerald-700">
                                            <span>Lengkap (6/6)</span>
                                        </span>
                                    <?php else: ?>
                                        <div class="space-y-0.5">
                                            <span class="inline-block text-[11px] font-bold text-amber-700">Kurang:</span>
                                            <span class="text-[10px] text-slate-500 font-mono"><?= implode(', ', $row['missing']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td class="py-2.5 px-3 text-right">
                                    <a href="<?= base_url('modules/master_drawings/index.php?search=' . urlencode($row['part_code'])) ?>" class="text-[11px] font-bold text-blue-600 hover:text-blue-800 hover:underline">
                                        Buka Detail
                                    </a>
                                </td>

                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Empty row when filter returns nothing -->
            <div id="no-filter-match" class="p-8 text-center text-xs text-slate-400 hidden">
                Tidak ada data Part yang cocok dengan filter pencarian.
            </div>
        </div>

    </main>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var tabs = document.querySelectorAll('.btn-filter-tab');
    var rows = document.querySelectorAll('.part-row');
    var searchInput = document.getElementById('table-search');
    var counter = document.getElementById('filter-counter');
    var noMatch = document.getElementById('no-filter-match');

    var currentFilter = 'all';

    function applyFilter() {
        var query = searchInput.value.trim().toLowerCase();
        var visibleCount = 0;

        rows.forEach(function(row) {
            var isNew = row.getAttribute('data-new') === '1';
            var isComplete = row.getAttribute('data-complete') === '1';
            var code = row.getAttribute('data-code');
            var name = row.getAttribute('data-name');

            var filterMatch = true;
            if (currentFilter === 'incomplete') {
                filterMatch = !isComplete;
            } else if (currentFilter === 'new') {
                filterMatch = isNew;
            } else if (currentFilter === 'complete') {
                filterMatch = isComplete;
            }

            var textMatch = (query === '') || (code.indexOf(query) !== -1) || (name.indexOf(query) !== -1);

            if (filterMatch && textMatch) {
                row.classList.remove('hidden');
                visibleCount++;
            } else {
                row.classList.add('hidden');
            }
        });

        counter.textContent = visibleCount + ' Part';
        if (visibleCount === 0) {
            noMatch.classList.remove('hidden');
        } else {
            noMatch.classList.add('hidden');
        }
    }

    tabs.forEach(function(btn) {
        btn.addEventListener('click', function() {
            tabs.forEach(function(t) {
                t.className = 'btn-filter-tab px-2.5 py-1 rounded-md text-slate-600 hover:text-slate-900';
            });
            btn.className = 'btn-filter-tab px-2.5 py-1 rounded-md text-blue-700 bg-blue-50 font-bold';
            currentFilter = btn.getAttribute('data-filter');
            applyFilter();
        });
    });

    searchInput.addEventListener('input', applyFilter);
});
</script>
