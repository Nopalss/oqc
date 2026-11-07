<?php
$breadcrumbCategory = "MASTER DATA";
$pageTitle          = "Master Data Model";
$pageSubtitle       = "Kelola master nama model produk (1 Model ➔ Banyak Part) — PT. Surya Technology Industri";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

require_menu_access('master_models');

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
                            <th class="p-3 text-center w-32">Aksi</th>
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
                                            <button type="button" 
                                                    id="btn-toggle-<?= $m['id'] ?>"
                                                    onclick="toggleModelParts(<?= $m['id'] ?>, '<?= htmlspecialchars($m['name'], ENT_QUOTES) ?>')"
                                                    class="p-1.5 rounded-lg text-emerald-600 hover:bg-emerald-50 transition-colors inline-flex items-center" 
                                                    title="Lihat Daftar Part">
                                                <svg id="chevron-<?= $m['id'] ?>" class="w-4 h-4" style="transition: transform 0.2s ease-in-out;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                                </svg>
                                            </button>
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
                                <!-- Sub-row Expandable (Lazy Loaded) -->
                                <tr id="parts-row-<?= $m['id'] ?>" class="hidden bg-slate-50/70" data-loaded="false" data-expanded="false">
                                    <td colspan="5" class="p-0 border-b border-slate-200">
                                        <div id="parts-container-<?= $m['id'] ?>" class="p-4 pl-12 bg-slate-50/50">
                                            <!-- Konten diisi asinkron via AJAX -->
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

<style>
@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
</style>

<script>
const modelPartsCache = {};

function toggleModelParts(modelId, modelName) {
    const row = document.getElementById('parts-row-' + modelId);
    const chevron = document.getElementById('chevron-' + modelId);
    const container = document.getElementById('parts-container-' + modelId);

    if (!row || !chevron || !container) return;

    const isExpanded = row.getAttribute('data-expanded') === 'true';

    if (isExpanded) {
        // Collapse sub-row
        row.classList.add('hidden');
        row.setAttribute('data-expanded', 'false');
        chevron.style.transform = 'rotate(0deg)';
    } else {
        // Expand sub-row
        row.classList.remove('hidden');
        row.setAttribute('data-expanded', 'true');
        chevron.style.transform = 'rotate(180deg)';

        const isLoaded = row.getAttribute('data-loaded') === 'true';
        if (!isLoaded) {
            // Lazy load via AJAX
            container.innerHTML = `
                <div class="flex items-center space-x-2 text-slate-500 py-3">
                    <svg class="w-4 h-4 text-emerald-600" style="animation: spin 1s linear infinite;" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                    </svg>
                    <span class="text-xs font-medium">Memuat daftar part untuk model <strong>${escapeHtml(modelName)}</strong>...</span>
                </div>
            `;

            fetch('<?= base_url("modules/master_models/get_parts.php") ?>?model_id=' + modelId)
                .then(response => {
                    if (!response.ok) {
                        return response.json().then(json => { throw new Error(json.message || 'HTTP error ' + response.status); });
                    }
                    return response.json();
                })
                .then(res => {
                    if (!res.success) {
                        throw new Error(res.message || 'Gagal memuat part');
                    }

                    row.setAttribute('data-loaded', 'true');
                    modelPartsCache[modelId] = res.data;
                    renderModelParts(modelId, modelName, res.data);
                })
                .catch(err => {
                    container.innerHTML = `
                        <div class="py-2.5 px-3 bg-red-50 border border-red-200 rounded-lg text-red-700 text-xs flex items-center justify-between">
                            <span>Gagal memuat data part: ${escapeHtml(err.message)}</span>
                            <button type="button" onclick="retryLoadParts(${modelId}, '${escapeHtml(modelName)}')" class="font-bold underline text-red-800 hover:text-red-900 ml-2">Coba Lagi</button>
                        </div>
                    `;
                });
        }
    }
}

function retryLoadParts(modelId, modelName) {
    const row = document.getElementById('parts-row-' + modelId);
    if (row) {
        row.setAttribute('data-loaded', 'false');
        row.setAttribute('data-expanded', 'false');
        toggleModelParts(modelId, modelName);
    }
}

function escapeHtml(text) {
    if (!text) return '';
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.toString().replace(/[&<>"']/g, m => map[m]);
}

function renderModelParts(modelId, modelName, parts) {
    const container = document.getElementById('parts-container-' + modelId);
    if (!container) return;

    if (!parts || parts.length === 0) {
        container.innerHTML = `
            <div class="py-3 px-4 bg-amber-50 border border-amber-200 rounded-xl text-amber-800 text-xs flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                <div class="flex items-center space-x-2">
                    <svg class="w-4 h-4 text-amber-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span>Belum ada part yang dialokasikan ke model <strong>${escapeHtml(modelName)}</strong>.</span>
                </div>
                <a href="<?= base_url('modules/master_parts/create.php') ?>" class="inline-flex items-center space-x-1 text-xs font-bold text-amber-900 bg-amber-100 hover:bg-amber-200 px-3 py-1.5 rounded-lg transition-colors w-fit">
                    <span>+ Tambah Part Baru</span>
                </a>
            </div>
        `;
        return;
    }

    let rowsHtml = '';
    parts.forEach((p, i) => {
        let aqlBadgeClass = 'bg-blue-100 text-blue-700 border-blue-200';
        if (p.aql_level === 'G-I') {
            aqlBadgeClass = 'bg-purple-100 text-purple-700 border-purple-200';
        } else if (p.aql_level === 'G-III') {
            aqlBadgeClass = 'bg-orange-100 text-orange-700 border-orange-200';
        }

        rowsHtml += `
            <tr class="hover:bg-slate-50/80 transition-colors">
                <td class="p-2 text-center text-slate-400 font-mono text-[11px]">${i + 1}</td>
                <td class="p-2 font-mono font-bold text-slate-700 text-xs">${escapeHtml(p.part_code)}</td>
                <td class="p-2 font-semibold text-slate-800 text-xs">${escapeHtml(p.part_name)}</td>
                <td class="p-2 text-center">
                    <span class="inline-block px-2 py-0.5 rounded text-[10px] font-bold border ${aqlBadgeClass}">
                        ${escapeHtml(p.aql_level || 'G-II')}
                    </span>
                </td>
                <td class="p-2 text-center">
                    <a href="<?= base_url('modules/master_parts/edit.php?id=') ?>${p.id}" 
                       target="_blank"
                       class="text-blue-600 hover:text-blue-800 font-bold hover:underline inline-flex items-center space-x-1 text-[11px]" 
                       title="Edit Part di tab baru">
                        <span>Edit</span>
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                        </svg>
                    </a>
                </td>
            </tr>
        `;
    });

    container.innerHTML = `
        <div class="space-y-2">
            <div class="flex items-center justify-between pb-1">
                <div class="flex items-center space-x-2">
                    <span class="inline-block w-2 h-2 rounded-full bg-emerald-500"></span>
                    <span class="text-xs font-bold text-slate-700">Daftar Part Terdaftar (${parts.length} Part)</span>
                </div>
                <a href="<?= base_url('modules/master_parts/index.php') ?>" class="text-[11px] text-blue-600 hover:underline font-semibold flex items-center space-x-1">
                    <span>Lihat di Master Part</span>
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </a>
            </div>
            <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-slate-100 text-slate-600 uppercase text-[10px] tracking-wider border-b border-slate-200">
                        <tr>
                            <th class="p-2 text-center w-10">No</th>
                            <th class="p-2 w-36">Part Code</th>
                            <th class="p-2">Part Name</th>
                            <th class="p-2 text-center w-24">AQL Level</th>
                            <th class="p-2 text-center w-20">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        ${rowsHtml}
                    </tbody>
                </table>
            </div>
        </div>
    `;
}

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
