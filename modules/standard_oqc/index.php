<?php
$pageTitle = "Standard OQC";
$breadcrumbCategory = "DATA REFERENSI";
$pageSubtitle = "Manajemen Dokumen Standard, Prosedur Kerja, dan Work Instruction (WI)";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

// Enforce permission
require_menu_access('standard_oqc');

$pdo = getDB();

// Filters & Search
$search = trim($_GET['search'] ?? '');
$category = trim($_GET['category'] ?? '');

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "title LIKE :search";
    $params[':search'] = "%{$search}%";
}

$whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Fetch documents
$documents = [];
if ($pdo) {
    try {
        $sql = "SELECT s.*, u.name as uploader_name 
                FROM standard_oqc s
                LEFT JOIN users u ON s.uploaded_by = u.id
                {$whereClause}
                ORDER BY s.updated_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        set_flash('danger', 'Gagal memuat data dokumen: ' . $e->getMessage());
    }
}

// Helper format size
function formatBytes($bytes, $precision = 1) {
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-6 space-y-5 max-w-7xl w-full mx-auto">
        
        <?= render_flash() ?>

        <!-- Action Header -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-white p-4 rounded-2xl shadow-xs border border-slate-200">
            <div>
                <h1 class="text-base font-bold text-slate-800 flex items-center gap-2">
                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    Daftar Standard & Prosedur OQC
                </h1>
                <p class="text-xs text-slate-500 mt-0.5">Akses cepat file Work Instruction (WI) dan Standard Prosedur Pemeriksaan</p>
            </div>
            
            <div class="flex items-center gap-2">
                <a href="<?= base_url('modules/standard_oqc/create.php') ?>" class="btn-primary py-2 px-3 text-xs font-semibold inline-flex items-center gap-1.5 rounded-xl shadow-xs hover:shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                    <span>Upload Standard Baru</span>
                </a>
            </div>
        </div>

        <!-- Filter Card -->
        <div class="bg-white p-4 rounded-2xl shadow-xs border border-slate-200">
            <form method="GET" action="<?= base_url('modules/standard_oqc/index.php') ?>" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                <div style="position: relative; flex: 1; min-width: 260px;">
                    <svg style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; color: #94a3b8; pointer-events: none;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                    <input type="text" name="search" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>" 
                           placeholder="Cari nama prosedur / dokumen standard..." 
                           style="width: 100%; padding: 8px 12px 8px 36px; font-size: 12px; border: 1px solid #cbd5e1; border-radius: 10px; outline: none; background-color: #f8fafc; color: #1e293b;"
                           onfocus="this.style.borderColor='#3b82f6'; this.style.backgroundColor='#ffffff';"
                           onblur="this.style.borderColor='#cbd5e1'; this.style.backgroundColor='#f8fafc';">
                </div>

                <div style="display: flex; align-items: center; gap: 6px;">
                    <button type="submit" class="btn-primary py-2 px-4 text-xs font-semibold rounded-xl text-center">
                        Cari
                    </button>
                    <?php if ($search !== ''): ?>
                        <a href="<?= base_url('modules/standard_oqc/index.php') ?>" class="btn-secondary py-2 px-3 text-xs font-semibold rounded-xl text-center" title="Reset Pencarian">
                            Reset
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Documents List -->
        <div class="bg-white rounded-2xl shadow-xs border border-slate-200 overflow-hidden">
            <?php if (empty($documents)): ?>
                <div class="p-12 text-center">
                    <div class="w-16 h-16 mx-auto mb-3 text-slate-300 bg-slate-50 rounded-full flex items-center justify-center">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                    </div>
                    <h3 class="text-sm font-bold text-slate-700">Belum Ada Dokumen Standard</h3>
                    <p class="text-xs text-slate-400 mt-1">Silakan upload petunjuk kerja atau prosedur pertama Anda.</p>
                    <div class="mt-4">
                        <a href="<?= base_url('modules/standard_oqc/create.php') ?>" class="btn-primary py-1.5 px-4 text-xs font-semibold rounded-xl inline-flex items-center gap-1.5">
                            + Upload Dokumen
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-50 border-b border-slate-200 text-slate-600 font-bold uppercase text-[10px] tracking-wider">
                            <tr>
                                <th class="py-3 px-4 w-12 text-center">No</th>
                                <th class="py-3 px-4">Nama Dokumen / Prosedur</th>
                                <th class="py-3 px-4 w-28">Ukuran</th>
                                <th class="py-3 px-4 w-40">Terakhir Update</th>
                                <th class="py-3 px-4 w-36 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            <?php foreach ($documents as $idx => $doc): ?>
                                <?php
                                $fileUrl = base_url($doc['file_path']);
                                ?>
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <td class="py-3 px-4 text-center text-slate-400 font-medium"><?= $idx + 1 ?></td>
                                    <td class="py-3 px-4">
                                        <div class="font-bold text-slate-900 text-sm flex items-center gap-1.5">
                                            <svg class="w-4 h-4 text-rose-500 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4zm2 6a1 1 0 011-1h6a1 1 0 110 2H7a1 1 0 01-1-1zm1 3a1 1 0 100 2h6a1 1 0 100-2H7z" clip-rule="evenodd"></path>
                                            </svg>
                                            <span><?= htmlspecialchars($doc['title'], ENT_QUOTES) ?></span>
                                        </div>
                                    </td>
                                    <td class="py-3 px-4 text-slate-500 font-mono text-[11px]">
                                        <?= formatBytes((int)($doc['file_size'] ?? 0)) ?>
                                    </td>
                                    <td class="py-3 px-4 text-slate-500 text-[11px]">
                                        <div><?= date('d M Y H:i', strtotime($doc['updated_at'])) ?></div>
                                        <?php if (!empty($doc['uploader_name'])): ?>
                                            <div class="text-[10px] text-slate-400">oleh <?= htmlspecialchars($doc['uploader_name'], ENT_QUOTES) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <div class="flex items-center justify-center gap-1.5">
                                            <!-- Preview / Buka PDF (Tab Baru) -->
                                            <a href="<?= $fileUrl ?>" target="_blank"
                                               class="p-1.5 text-blue-600 hover:text-blue-800 hover:bg-blue-50 rounded-lg transition-colors" 
                                               title="Lihat / Buka PDF di Tab Baru">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                                </svg>
                                            </a>

                                            <!-- Download PDF -->
                                            <a href="<?= $fileUrl ?>" download="<?= htmlspecialchars($doc['file_name_original'], ENT_QUOTES) ?>" 
                                               class="p-1.5 text-emerald-600 hover:text-emerald-800 hover:bg-emerald-50 rounded-lg transition-colors" 
                                               title="Download PDF">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                                                </svg>
                                            </a>

                                            <!-- Edit / Update Dokumen -->
                                            <a href="<?= base_url('modules/standard_oqc/edit.php?id=' . $doc['id']) ?>" 
                                               class="p-1.5 text-amber-600 hover:text-amber-800 hover:bg-amber-50 rounded-lg transition-colors" 
                                               title="Edit / Ganti Dokumen">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                </svg>
                                            </a>

                                            <!-- Tombol Hapus (Khusus Dokumen selain ID 1) -->
                                            <?php if ((int)$doc['id'] !== 1): ?>
                                                <button type="button" 
                                                        onclick="confirmDelete('<?= $doc['id'] ?>', '<?= htmlspecialchars(addslashes($doc['title']), ENT_QUOTES) ?>')"
                                                        class="p-1.5 text-rose-600 hover:text-rose-800 hover:bg-rose-50 rounded-lg transition-colors" 
                                                        title="Hapus Dokumen">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                    </svg>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>

<!-- Form Hapus Tersembunyi (CSRF Safe) -->
<form id="deleteForm" method="POST" action="<?= base_url('modules/standard_oqc/delete.php') ?>" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="id" id="deleteId">
</form>

<script>
function confirmDelete(id, title) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Hapus Dokumen?',
            text: `Apakah Anda yakin ingin menghapus "${title}"? File PDF terkait akan dihapus permanen dari server.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e11d48',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('deleteId').value = id;
                document.getElementById('deleteForm').submit();
            }
        });
    } else {
        if (confirm(`Apakah Anda yakin ingin menghapus "${title}"? File fisik akan dihapus dari server.`)) {
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteForm').submit();
        }
    }
}
</script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
