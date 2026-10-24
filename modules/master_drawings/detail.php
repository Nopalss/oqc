<?php
$breadcrumbCategory = "MASTER DATA";
$pageTitle = "Drawing Viewer (2D & 3D)";
$pageSubtitle = "Pratinjau acuan gambar teknik 2D (PDF) dan Tampilan Interactive 3D (.STP)";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
require_once __DIR__ . '/../../config/document_helper.php';

require_menu_access('master_drawings');

$part_id = (int)($_GET['part_id'] ?? 0);
$pdo = getDB();
$part = null;
$drawing = null;
$qcDocs = [];

if ($part_id && $pdo) {
    try {
        $stmtPart = $pdo->prepare("
            SELECT p.*, COALESCE(m.name, p.model, '') as model_name 
            FROM master_parts p 
            LEFT JOIN master_models m ON m.id = p.model_id 
            WHERE p.id = :id
        ");
        $stmtPart->execute([':id' => $part_id]);
        $part = $stmtPart->fetch();

        if ($part) {
            $stmtDraw = $pdo->prepare("SELECT * FROM master_drawings WHERE part_id = :part_id");
            $stmtDraw->execute([':part_id' => $part_id]);
            $drawing = $stmtDraw->fetch() ?: [
                'id' => null,
                'part_id' => $part['id'],
                'drawing_2d_path' => null,
                'drawing_3d_path' => null,
                'updated_at' => null
            ];

            // Priority: Resolve files directly from physical folders (checks uploads/parts/{PartCode}/ first)
            $fsDrawings = get_part_drawing_assets($part['part_code'] ?? '', $part['model_name'] ?? '');

            if (!empty($fsDrawings['drawing_2d_path'])) {
                $drawing['drawing_2d_path'] = $fsDrawings['drawing_2d_path'];
            } elseif (!empty($drawing['drawing_2d_path']) && !file_exists(__DIR__ . '/../../' . $drawing['drawing_2d_path'])) {
                $drawing['drawing_2d_path'] = null;
            }

            if (!empty($fsDrawings['drawing_3d_path'])) {
                $drawing['drawing_3d_path'] = $fsDrawings['drawing_3d_path'];
            } elseif (!empty($drawing['drawing_3d_path']) && !file_exists(__DIR__ . '/../../' . $drawing['drawing_3d_path'])) {
                $drawing['drawing_3d_path'] = null;
            }

            $drawing['folder_found'] = $fsDrawings['folder_found'] ?? null;

            // Retrieve QC Documents (PI, Q-Point, PAC, Standar Packing)
            $qcDocs = get_part_documents_summary($part['part_code'] ?? '');
        }
    } catch (PDOException $e) {
        $part = null;
    }
}

if (!$part) {
    set_flash('error', 'Data Part tidak ditemukan!');
    redirect('modules/master_parts/index.php');
}
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-5 space-y-4">
        
        <?= render_flash() ?>

        <!-- Clean Top Header Action Card -->
        <div class="card p-3 md:p-4 bg-white border border-slate-200/80 rounded-xl shadow-xs">
            <div class="flex items-center justify-between gap-3 flex-wrap">
                
                <div class="flex items-center gap-3 min-w-0">
                    <a href="<?= base_url('modules/master_drawings/index.php') ?>" class="btn-secondary py-1.5 px-3 text-xs flex-shrink-0">
                        &larr; Kembali
                    </a>
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-xs text-slate-500 font-medium">Part Code:</span>
                            <span class="text-sm font-extrabold text-blue-700 font-mono tracking-tight"><?= htmlspecialchars($part['part_code']) ?></span>
                            <?php if (!empty($part['model_name'])): ?>
                                <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-slate-100 text-slate-700 border border-slate-200">
                                    Model: <?= htmlspecialchars($part['model_name']) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <h2 class="text-xs font-semibold text-slate-700 mt-0.5 truncate"><?= htmlspecialchars($part['part_name']) ?></h2>
                        <?php if (!empty($drawing['folder_found'])): ?>
                            <p class="text-[10px] text-slate-400 font-mono truncate mt-0.5" title="<?= htmlspecialchars($drawing['folder_found']) ?>">
                                Folder: <?= htmlspecialchars(basename(dirname($drawing['folder_found'])) . '/' . basename($drawing['folder_found'])) ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="flex items-center gap-2 flex-wrap">
                    <a href="<?= base_url('modules/master_drawings/upload.php?part_id=' . $part['id']) ?>" class="btn-primary py-1.5 px-3 text-xs flex items-center whitespace-nowrap">
                        <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                        </svg>
                        Kelola / Upload Dokumen
                    </a>
                </div>

            </div>
        </div>

        <!-- QC Documents Overview Cards (PI, Q-Point, PAC, Standar Packing) -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            
            <!-- PI Card -->
            <?php $dPi = $qcDocs['pi'] ?? null; ?>
            <div class="card p-3 bg-white border border-slate-200/80 rounded-xl shadow-xs flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-[11px] font-extrabold text-indigo-700 flex items-center gap-1">
                            <span class="w-2 h-2 rounded-full bg-indigo-600"></span>
                            <span>PI</span>
                        </span>
                        <?php if (!empty($dPi['has_document'])): ?>
                            <span class="badge badge-success text-[10px]"><?= htmlspecialchars($dPi['latest']['version_label_full'] ?? 'Ada') ?></span>
                        <?php else: ?>
                            <span class="badge badge-secondary text-[10px]">Belum Ada</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-[10px] text-slate-500 font-medium">Process Inspection</p>
                </div>
                <div class="mt-2 pt-2 border-t border-slate-100 flex items-center justify-between">
                    <?php if (!empty($dPi['has_document']) && !empty($dPi['latest']['relative_url'])): ?>
                        <span class="text-[10px] font-mono text-slate-400 truncate max-w-[100px]"><?= htmlspecialchars($dPi['latest']['filename']) ?></span>
                        <a href="<?= base_url($dPi['latest']['relative_url']) ?>" target="_blank" class="text-[11px] font-bold text-indigo-600 hover:underline">Lihat PDF &nearr;</a>
                    <?php else: ?>
                        <span class="text-[10px] text-slate-400 italic">Tidak ada file</span>
                        <a href="<?= base_url('modules/master_drawings/upload.php?part_id=' . $part['id']) ?>" class="text-[10px] font-bold text-blue-600 hover:underline">+ Upload</a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Q-Point Card -->
            <?php $dQp = $qcDocs['qpoint'] ?? null; ?>
            <div class="card p-3 bg-white border border-slate-200/80 rounded-xl shadow-xs flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-[11px] font-extrabold text-amber-700 flex items-center gap-1">
                            <span class="w-2 h-2 rounded-full bg-amber-600"></span>
                            <span>Q-Point</span>
                        </span>
                        <?php if (!empty($dQp['has_document'])): ?>
                            <span class="badge badge-success text-[10px]"><?= htmlspecialchars($dQp['latest']['version_label_full'] ?? 'Ada') ?></span>
                        <?php else: ?>
                            <span class="badge badge-secondary text-[10px]">Belum Ada</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-[10px] text-slate-500 font-medium">Quality Point</p>
                </div>
                <div class="mt-2 pt-2 border-t border-slate-100 flex items-center justify-between">
                    <?php if (!empty($dQp['has_document']) && !empty($dQp['latest']['relative_url'])): ?>
                        <span class="text-[10px] font-mono text-slate-400 truncate max-w-[100px]"><?= htmlspecialchars($dQp['latest']['filename']) ?></span>
                        <a href="<?= base_url($dQp['latest']['relative_url']) ?>" target="_blank" class="text-[11px] font-bold text-amber-700 hover:underline">Lihat PDF &nearr;</a>
                    <?php else: ?>
                        <span class="text-[10px] text-slate-400 italic">Tidak ada file</span>
                        <a href="<?= base_url('modules/master_drawings/upload.php?part_id=' . $part['id']) ?>" class="text-[10px] font-bold text-blue-600 hover:underline">+ Upload</a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- PAC Card -->
            <?php $dPac = $qcDocs['pac'] ?? null; ?>
            <div class="card p-3 bg-white border border-slate-200/80 rounded-xl shadow-xs flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-[11px] font-extrabold text-emerald-700 flex items-center gap-1">
                            <span class="w-2 h-2 rounded-full bg-emerald-600"></span>
                            <span>PAC</span>
                        </span>
                        <?php if (!empty($dPac['has_document'])): ?>
                            <span class="badge badge-success text-[10px]"><?= htmlspecialchars($dPac['latest']['version_label_full'] ?? 'Ada') ?></span>
                        <?php else: ?>
                            <span class="badge badge-secondary text-[10px]">Belum Ada</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-[10px] text-slate-500 font-medium">Part Approval Certificate</p>
                </div>
                <div class="mt-2 pt-2 border-t border-slate-100 flex items-center justify-between">
                    <?php if (!empty($dPac['has_document']) && !empty($dPac['latest']['relative_url'])): ?>
                        <span class="text-[10px] font-mono text-slate-400 truncate max-w-[100px]"><?= htmlspecialchars($dPac['latest']['filename']) ?></span>
                        <a href="<?= base_url($dPac['latest']['relative_url']) ?>" target="_blank" class="text-[11px] font-bold text-emerald-700 hover:underline">Lihat PDF &nearr;</a>
                    <?php else: ?>
                        <span class="text-[10px] text-slate-400 italic">Tidak ada file</span>
                        <a href="<?= base_url('modules/master_drawings/upload.php?part_id=' . $part['id']) ?>" class="text-[10px] font-bold text-blue-600 hover:underline">+ Upload</a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Standar Packing Card -->
            <?php $dPack = $qcDocs['standar_packing'] ?? null; ?>
            <div class="card p-3 bg-white border border-slate-200/80 rounded-xl shadow-xs flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-[11px] font-extrabold text-orange-700 flex items-center gap-1">
                            <span class="w-2 h-2 rounded-full bg-orange-600"></span>
                            <span>Packing</span>
                        </span>
                        <?php if (!empty($dPack['has_document'])): ?>
                            <span class="badge badge-success text-[10px]"><?= htmlspecialchars($dPack['latest']['version_label_full'] ?? 'Ada') ?></span>
                        <?php else: ?>
                            <span class="badge badge-secondary text-[10px]">Belum Ada</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-[10px] text-slate-500 font-medium">Standar Packing</p>
                </div>
                <div class="mt-2 pt-2 border-t border-slate-100 flex items-center justify-between">
                    <?php if (!empty($dPack['has_document']) && !empty($dPack['latest']['relative_url'])): ?>
                        <span class="text-[10px] font-mono text-slate-400 truncate max-w-[100px]"><?= htmlspecialchars($dPack['latest']['filename']) ?></span>
                        <a href="<?= base_url($dPack['latest']['relative_url']) ?>" target="_blank" class="text-[11px] font-bold text-orange-700 hover:underline">Lihat PDF &nearr;</a>
                    <?php else: ?>
                        <span class="text-[10px] text-slate-400 italic">Tidak ada file</span>
                        <a href="<?= base_url('modules/master_drawings/upload.php?part_id=' . $part['id']) ?>" class="text-[10px] font-bold text-blue-600 hover:underline">+ Upload</a>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- Tabel Riwayat Berkas & Manajemen Versi (Multi-Version History Card) -->
        <?php
        // Kumpulkan semua file dari seluruh kategori dokumen untuk tabel riwayat
        $allVersionRows = [];
        $categoryLabels = [
            '2d'              => ['label' => 'Gambar 2D', 'badge' => 'bg-blue-100 text-blue-800 border-blue-200'],
            '3d'              => ['label' => 'Model 3D', 'badge' => 'bg-cyan-100 text-cyan-800 border-cyan-200'],
            'pi'              => ['label' => 'PI', 'badge' => 'bg-indigo-100 text-indigo-800 border-indigo-200'],
            'qpoint'          => ['label' => 'Q-Point', 'badge' => 'bg-amber-100 text-amber-800 border-amber-200'],
            'pac'             => ['label' => 'PAC', 'badge' => 'bg-emerald-100 text-emerald-800 border-emerald-200'],
            'standar_packing' => ['label' => 'Packing', 'badge' => 'bg-orange-100 text-orange-800 border-orange-200']
        ];

        foreach ($categoryLabels as $catKey => $catMeta) {
            $catGroup = $qcDocs[$catKey] ?? null;
            if ($catGroup && !empty($catGroup['versions'])) {
                foreach ($catGroup['versions'] as $vItem) {
                    $allVersionRows[] = array_merge($vItem, [
                        'doc_type' => $catKey,
                        'doc_label' => $catMeta['label'],
                        'badge_class' => $catMeta['badge']
                    ]);
                }
            }
        }

        // Urutkan riwayat file berdasarkan waktu modifikasi terbaru
        usort($allVersionRows, function($a, $b) {
            return ($b['modified_at'] ?? 0) - ($a['modified_at'] ?? 0);
        });
        ?>

        <div class="card p-4 md:p-5 bg-white border border-slate-200/80 rounded-2xl shadow-xs space-y-3">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3 flex-wrap gap-2">
                <div class="flex items-center gap-2">
                    <div class="w-7 h-7 rounded-lg bg-blue-50 text-blue-700 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div>
                        <h3 class="text-xs font-extrabold uppercase tracking-wider text-slate-800">Riwayat Berkas &amp; Manajemen Versi</h3>
                        <p class="text-[11px] text-slate-400">Daftar seluruh berkas drawing &amp; dokumen mutu yang tersimpan di direktori part</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-[10px] font-mono px-2.5 py-1 rounded bg-slate-100 text-slate-600 border border-slate-200">
                        Total: <strong><?= count($allVersionRows) ?></strong> berkas
                    </span>
                    <a href="<?= base_url('modules/master_drawings/upload.php?part_id=' . $part['id']) ?>" class="btn-secondary py-1 px-2 text-[11px] font-bold">
                        + Unggah Revisi Baru
                    </a>
                </div>
            </div>

            <?php if (empty($allVersionRows)): ?>
                <div class="p-6 text-center text-xs text-slate-400 italic bg-slate-50 rounded-xl border border-dashed border-slate-200">
                    Belum ada berkas fisik yang tersimpan di folder part ini. Silakan unggah berkas drawing atau dokumen mutu.
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead>
                            <tr class="bg-slate-50 text-slate-600 border-b border-slate-200 font-bold text-[11px]">
                                <th class="py-2.5 px-3">Tipe Dokumen</th>
                                <th class="py-2.5 px-3">Nama Berkas</th>
                                <th class="py-2.5 px-3">Versi / Status</th>
                                <th class="py-2.5 px-3">Ukuran</th>
                                <th class="py-2.5 px-3">Tanggal Upload / Modifikasi</th>
                                <th class="py-2.5 px-3 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-medium">
                            <?php foreach ($allVersionRows as $row): 
                                $fBytes = $row['file_size'] ?? 0;
                                $fSizeFormatted = ($fBytes >= 1048576) ? (round($fBytes / 1048576, 2) . ' MB') : (round($fBytes / 1024, 1) . ' KB');
                                $modTime = !empty($row['modified_at']) ? date('d M Y, H:i', $row['modified_at']) . ' WIB' : '-';
                                $fileUrl = base_url($row['relative_url'] ?? '');
                            ?>
                                <tr class="hover:bg-slate-50/70 transition-colors">
                                    <td class="py-2 px-3 whitespace-nowrap">
                                        <span class="px-2 py-0.5 rounded text-[10px] font-extrabold border <?= $row['badge_class'] ?>">
                                            <?= htmlspecialchars($row['doc_label']) ?>
                                        </span>
                                    </td>
                                    <td class="py-2 px-3 font-mono font-bold text-slate-800 whitespace-nowrap">
                                        <?= htmlspecialchars($row['filename']) ?>
                                    </td>
                                    <td class="py-2 px-3 whitespace-nowrap">
                                        <?php if (!empty($row['is_latest'])): ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                                <?= htmlspecialchars($row['version_label']) ?> (Terbaru)
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-100 text-slate-600 border border-slate-200">
                                                <?= htmlspecialchars($row['version_label']) ?> (Arsip)
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-2 px-3 text-slate-500 whitespace-nowrap font-mono text-[11px]">
                                        <?= $fSizeFormatted ?>
                                    </td>
                                    <td class="py-2 px-3 text-slate-600 whitespace-nowrap">
                                        <?= $modTime ?>
                                    </td>
                                    <td class="py-2 px-3 text-right whitespace-nowrap">
                                        <div class="inline-flex items-center gap-1.5 justify-end">
                                            <a href="<?= $fileUrl ?>" target="_blank" class="px-2 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 text-[10px] font-bold rounded transition-colors" title="Buka / Download File">
                                                Lihat &nearr;
                                            </a>
                                            <!-- Tombol Hapus Berkas Usang dengan Form CSRF -->
                                            <form action="<?= base_url('modules/master_drawings/delete_version.php') ?>" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus berkas <?= htmlspecialchars($row['filename']) ?>? Tindakan ini tidak dapat dibatalkan.');" class="inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="part_id" value="<?= htmlspecialchars($part['id']) ?>">
                                                <input type="hidden" name="filename" value="<?= htmlspecialchars($row['filename']) ?>">
                                                <button type="submit" class="px-2 py-1 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 hover:border-rose-300 text-[10px] font-bold rounded transition-colors" title="Hapus berkas versi ini">
                                                    Hapus
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Modern Multi-Tab Document & Drawing Viewer Card -->
        <div class="card p-4 md:p-5 bg-white border border-slate-200/80 rounded-2xl shadow-xs space-y-4">
            
            <!-- Navigation Tabs -->
            <div class="flex items-center justify-between border-b border-slate-200 pb-3 flex-wrap gap-2">
                <div class="flex items-center gap-1.5 overflow-x-auto py-1" id="doc-viewer-tabs">
                    <button type="button" data-tab="2d" class="tab-btn px-3 py-1.5 rounded-xl text-xs font-bold transition-all bg-blue-600 text-white shadow-xs">
                        Gambar 2D
                    </button>
                    <button type="button" data-tab="3d" class="tab-btn px-3 py-1.5 rounded-xl text-xs font-semibold text-slate-600 hover:bg-slate-100 transition-all">
                        Model 3D (.STP)
                    </button>
                    <button type="button" data-tab="pi" class="tab-btn px-3 py-1.5 rounded-xl text-xs font-semibold text-slate-600 hover:bg-slate-100 transition-all flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full bg-indigo-600"></span>
                        <span>PI</span>
                    </button>
                    <button type="button" data-tab="qpoint" class="tab-btn px-3 py-1.5 rounded-xl text-xs font-semibold text-slate-600 hover:bg-slate-100 transition-all flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full bg-amber-600"></span>
                        <span>Q-Point</span>
                    </button>
                    <button type="button" data-tab="pac" class="tab-btn px-3 py-1.5 rounded-xl text-xs font-semibold text-slate-600 hover:bg-slate-100 transition-all flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full bg-emerald-600"></span>
                        <span>PAC</span>
                    </button>
                    <button type="button" data-tab="packing" class="tab-btn px-3 py-1.5 rounded-xl text-xs font-semibold text-slate-600 hover:bg-slate-100 transition-all flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full bg-orange-600"></span>
                        <span>Standar Packing</span>
                    </button>
                </div>

                <!-- Right Action Bar & Version Selector -->
                <div class="flex items-center gap-2 flex-wrap">
                    <div id="version-selector-container" class="hidden items-center gap-1.5">
                        <span class="text-xs text-slate-500 font-semibold">Versi:</span>
                        <select id="doc-version-select" class="form-input py-1 px-2.5 text-xs font-bold text-slate-700 rounded-lg border-slate-300">
                        </select>
                    </div>

                    <a id="btn-fullscreen-doc" href="<?= !empty($drawing['drawing_2d_path']) ? base_url($drawing['drawing_2d_path']) : '#' ?>" target="_blank" class="btn-secondary py-1 px-2.5 text-xs font-semibold rounded-lg inline-flex items-center gap-1">
                        <span>Layar Penuh</span> &nearr;
                    </a>
                    <a id="btn-download-doc" href="<?= !empty($drawing['drawing_2d_path']) ? base_url($drawing['drawing_2d_path']) : '#' ?>" download class="btn-primary py-1 px-3 text-xs font-bold rounded-lg inline-flex items-center gap-1">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                        <span>Download</span>
                    </a>
                </div>
            </div>

            <!-- Viewer Container -->
            <div class="w-full bg-slate-900 rounded-xl border border-slate-800 overflow-hidden relative" style="height: 580px;">
                <!-- 1. PDF Frame (Shared for 2D, PI, Q-Point, PAC, Packing) -->
                <iframe id="pdf-viewer-frame" src="<?= !empty($drawing['drawing_2d_path']) ? base_url($drawing['drawing_2d_path']) : '' ?>" style="width: 100%; height: 100%; border: none;" class="<?= empty($drawing['drawing_2d_path']) ? 'hidden' : '' ?>"></iframe>

                <!-- 2. WebGL 3D Container -->
                <div id="cad-3d-viewport" data-stp-url="<?= !empty($drawing['drawing_3d_path']) ? base_url($drawing['drawing_3d_path']) : '' ?>" style="width: 100%; height: 100%;" class="hidden"></div>

                <!-- 3. Empty State -->
                <div id="viewer-empty-state" class="flex flex-col items-center justify-center h-full text-slate-400 p-6 text-center <?= !empty($drawing['drawing_2d_path']) ? 'hidden' : '' ?>">
                    <svg class="w-12 h-12 mb-2 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414A1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    <p class="text-xs font-medium" id="empty-state-text">Berkas belum diunggah untuk dokumen ini.</p>
                    <a href="<?= base_url('modules/master_drawings/upload.php?part_id=' . $part['id']) ?>" class="btn-primary py-1.5 px-3 text-xs mt-3">
                        + Upload Berkas
                    </a>
                </div>
            </div>

            <div id="3d-instruction-hint" class="text-[11px] text-slate-400 hidden items-center justify-between px-1">
                <span><b>Navigasi 3D</b>: Klik &amp; drag mouse untuk Putar (Rotate). Scroll mouse untuk Zoom. Klik kanan &amp; drag untuk Geser (Pan).</span>
            </div>

        </div>

    </main>

    <!-- Passing Data Dokumen ke Frontend JS -->
    <script>
    var PART_DOCUMENTS_DATA = {
        '2d': {
            label: 'Gambar 2D (.PDF)',
            is_3d: false,
            url: <?= json_encode(!empty($qcDocs['2d']['latest']['relative_url']) ? base_url($qcDocs['2d']['latest']['relative_url']) : (!empty($drawing['drawing_2d_path']) ? base_url($drawing['drawing_2d_path']) : null)) ?>,
            versions: <?= json_encode($qcDocs['2d']['versions'] ?? []) ?>
        },
        '3d': {
            label: 'Model 3D (.STP)',
            is_3d: true,
            url: <?= json_encode(!empty($qcDocs['3d']['latest']['relative_url']) ? base_url($qcDocs['3d']['latest']['relative_url']) : (!empty($drawing['drawing_3d_path']) ? base_url($drawing['drawing_3d_path']) : null)) ?>,
            versions: <?= json_encode($qcDocs['3d']['versions'] ?? []) ?>
        },
        'pi': {
            label: 'Process Inspection (PI)',
            is_3d: false,
            url: <?= json_encode(!empty($qcDocs['pi']['latest']['relative_url']) ? base_url($qcDocs['pi']['latest']['relative_url']) : null) ?>,
            versions: <?= json_encode($qcDocs['pi']['versions'] ?? []) ?>
        },
        'qpoint': {
            label: 'Quality Point (Q-Point)',
            is_3d: false,
            url: <?= json_encode(!empty($qcDocs['qpoint']['latest']['relative_url']) ? base_url($qcDocs['qpoint']['latest']['relative_url']) : null) ?>,
            versions: <?= json_encode($qcDocs['qpoint']['versions'] ?? []) ?>
        },
        'pac': {
            label: 'Part Approval Certificate (PAC)',
            is_3d: false,
            url: <?= json_encode(!empty($qcDocs['pac']['latest']['relative_url']) ? base_url($qcDocs['pac']['latest']['relative_url']) : null) ?>,
            versions: <?= json_encode($qcDocs['pac']['versions'] ?? []) ?>
        },
        'packing': {
            label: 'Standar Packing',
            is_3d: false,
            url: <?= json_encode(!empty($qcDocs['standar_packing']['latest']['relative_url']) ? base_url($qcDocs['standar_packing']['latest']['relative_url']) : null) ?>,
            versions: <?= json_encode($qcDocs['standar_packing']['versions'] ?? []) ?>
        }
    };

    document.addEventListener("DOMContentLoaded", function() {
        var tabButtons = document.querySelectorAll(".tab-btn");
        var pdfFrame = document.getElementById("pdf-viewer-frame");
        var cad3dViewport = document.getElementById("cad-3d-viewport");
        var emptyState = document.getElementById("viewer-empty-state");
        var emptyText = document.getElementById("empty-state-text");
        var btnFullscreen = document.getElementById("btn-fullscreen-doc");
        var btnDownload = document.getElementById("btn-download-doc");
        var versionContainer = document.getElementById("version-selector-container");
        var versionSelect = document.getElementById("doc-version-select");
        var hint3D = document.getElementById("3d-instruction-hint");

        var currentTab = '2d';
        var cadRendered = false;

        function buildDocUrl(path) {
            if (!path) return '';
            if (path.indexOf('http://') === 0 || path.indexOf('https://') === 0) {
                return path;
            }
            var base = (window.APP_BASE_URL || "<?= base_url() ?>").replace(/\/+$/, '');
            var cleanPath = path.replace(/^\/+/, '');
            return base + '/' + cleanPath;
        }

        function switchDocTab(tabKey) {
            currentTab = tabKey;
            var docData = PART_DOCUMENTS_DATA[tabKey];

            // Update Tab Button Styles
            tabButtons.forEach(function(btn) {
                if (btn.getAttribute("data-tab") === tabKey) {
                    btn.className = "tab-btn px-3 py-1.5 rounded-xl text-xs font-bold transition-all bg-blue-600 text-white shadow-xs flex items-center gap-1.5";
                } else {
                    btn.className = "tab-btn px-3 py-1.5 rounded-xl text-xs font-semibold text-slate-600 hover:bg-slate-100 transition-all flex items-center gap-1.5";
                }
            });

            // Handle Version Selector
            versionSelect.innerHTML = "";
            if (docData.versions && docData.versions.length > 1) {
                versionContainer.classList.remove("hidden");
                versionContainer.classList.add("flex");
                docData.versions.forEach(function(v, idx) {
                    var opt = document.createElement("option");
                    opt.value = buildDocUrl(v.relative_url);
                    opt.textContent = v.version_label_full + " (" + v.filename + ")";
                    if (idx === 0) opt.selected = true;
                    versionSelect.appendChild(opt);
                });
            } else {
                versionContainer.classList.add("hidden");
                versionContainer.classList.remove("flex");
            }

            // Display Content
            if (docData.is_3d) {
                pdfFrame.classList.add("hidden");
                hint3D.classList.remove("hidden");
                hint3D.classList.add("flex");

                var stpUrl = buildDocUrl(docData.url);
                if (stpUrl) {
                    cad3dViewport.classList.remove("hidden");
                    emptyState.classList.add("hidden");
                    btnFullscreen.href = stpUrl;
                    btnDownload.href = stpUrl;
                    btnFullscreen.classList.remove("hidden");
                    btnDownload.classList.remove("hidden");

                    if (!cadRendered && typeof renderCadStep === "function") {
                        renderCadStep("cad-3d-viewport", stpUrl);
                        cadRendered = true;
                    }
                } else {
                    cad3dViewport.classList.add("hidden");
                    emptyState.classList.remove("hidden");
                    emptyText.textContent = "Model 3D (.STP) belum diunggah untuk part ini.";
                    btnFullscreen.classList.add("hidden");
                    btnDownload.classList.add("hidden");
                }
            } else {
                cad3dViewport.classList.add("hidden");
                hint3D.classList.add("hidden");
                hint3D.classList.remove("flex");

                var rawPath = (docData.versions && docData.versions.length > 0 && docData.versions[0].relative_url)
                    ? docData.versions[0].relative_url
                    : docData.url;

                var activeUrl = buildDocUrl(rawPath);

                if (activeUrl) {
                    pdfFrame.classList.remove("hidden");
                    pdfFrame.src = activeUrl;
                    emptyState.classList.add("hidden");
                    btnFullscreen.href = activeUrl;
                    btnDownload.href = activeUrl;
                    btnFullscreen.classList.remove("hidden");
                    btnDownload.classList.remove("hidden");
                } else {
                    pdfFrame.classList.add("hidden");
                    emptyState.classList.remove("hidden");
                    emptyText.textContent = "Berkas " + docData.label + " belum diunggah untuk part ini.";
                    btnFullscreen.classList.add("hidden");
                    btnDownload.classList.add("hidden");
                }
            }
        }

        tabButtons.forEach(function(btn) {
            btn.addEventListener("click", function() {
                switchDocTab(this.getAttribute("data-tab"));
            });
        });

        versionSelect.addEventListener("change", function() {
            var selectedUrl = this.value;
            if (selectedUrl) {
                btnFullscreen.href = selectedUrl;
                btnDownload.href = selectedUrl;

                var docData = PART_DOCUMENTS_DATA[currentTab];
                if (docData && docData.is_3d) {
                    if (typeof renderCadStep === "function") {
                        renderCadStep("cad-3d-viewport", selectedUrl);
                    }
                } else {
                    pdfFrame.src = selectedUrl;
                }
            }
        });

        // Initialize default tab (2D)
        switchDocTab('2d');
    });
    </script>

<?php 
$loadCadViewer = true;
require_once __DIR__ . '/../../layouts/footer.php'; 
?>

