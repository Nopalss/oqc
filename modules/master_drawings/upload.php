<?php
$breadcrumbCategory = "MASTER DATA";
$pageTitle = "Upload / Update Drawing (2D & 3D)";
$pageSubtitle = "Unggah file gambar 2D (PDF) atau 3D (.STP) untuk part pilihan";

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
                'drawing_2d_path' => null,
                'drawing_3d_path' => null
            ];

            // Resolve physical folder and files
            $fsDrawings = get_part_drawing_assets($part['part_code'] ?? '', $part['model_name'] ?? '');
            if (!empty($fsDrawings['drawing_2d_path'])) {
                $drawing['drawing_2d_path'] = $fsDrawings['drawing_2d_path'];
            }
            if (!empty($fsDrawings['drawing_3d_path'])) {
                $drawing['drawing_3d_path'] = $fsDrawings['drawing_3d_path'];
            }
            $targetFolderInfo = $fsDrawings['folder_found'] ?? null;

            // Summary of 4 QC Documents (PI, Q-Point, PAC, Standar Packing)
            $qcDocs = get_part_documents_summary($part['part_code'] ?? '');
        }
    } catch (PDOException $e) {
        $part = null;
    }
}

if (!$part) {
    set_flash('error', 'Part tidak ditemukan!');
    redirect('modules/master_drawings/index.php');
}

$modelClean = !empty($part['model_name']) ? str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', trim($part['model_name'])) : 'GENERAL';
$expectedFolder = 'uploads/drawings/' . $modelClean . '/' . trim($part['part_code']) . ' _ ' . str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', trim($part['part_name']));
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-6 space-y-5 max-w-5xl">
        
        <?= render_flash() ?>

        <!-- Modern Top Bar / Header -->
        <div class="card p-4 md:p-5 bg-white border border-slate-200/80 rounded-2xl shadow-xs">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div class="flex items-center gap-3">
                    <a href="<?= base_url('modules/master_drawings/index.php') ?>" class="btn-secondary py-1.5 px-3 text-xs inline-flex items-center gap-1.5 rounded-lg border border-slate-300 hover:bg-slate-100 transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                        <span>Kembali</span>
                    </a>
                    <div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-xs text-slate-500 font-medium">Part Code:</span>
                            <span class="text-base font-black text-blue-700 font-mono tracking-tight"><?= htmlspecialchars($part['part_code']) ?></span>
                            <?php if (!empty($part['model_name'])): ?>
                                <span class="text-[11px] font-bold px-2.5 py-0.5 rounded-md bg-slate-100 text-slate-700 border border-slate-200">
                                    Model: <?= htmlspecialchars($part['model_name']) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <h1 class="text-sm font-semibold text-slate-800 mt-0.5 truncate"><?= htmlspecialchars($part['part_name']) ?></h1>
                    </div>
                </div>

                <!-- Fast Info Chip -->
                <div class="inline-flex items-center gap-2 px-3 py-1.5 bg-blue-50/70 border border-blue-200/60 rounded-xl text-blue-800 text-[11px]">
                    <svg class="w-4 h-4 text-blue-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span>Subfolder part & kompresi PDF otomatis di browser</span>
                </div>
            </div>
        </div>

        <!-- Form Upload -->
        <form id="form-upload-drawings" action="<?= base_url('modules/master_drawings/store.php') ?>" method="POST" enctype="multipart/form-data" class="space-y-5">
            <?= csrf_field() ?>
            <input type="hidden" name="part_id" value="<?= htmlspecialchars($part['id']) ?>">

            <!-- SECTION 1: CAD DRAWINGS (2D & 3D) -->
            <div class="card p-5 bg-white border border-slate-200/80 rounded-2xl shadow-xs space-y-4">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <div class="flex items-center gap-2">
                        <div class="w-7 h-7 rounded-lg bg-blue-100 text-blue-700 flex items-center justify-center">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        </div>
                        <div>
                            <h2 class="text-xs font-extrabold uppercase tracking-wider text-slate-800">Berkas CAD Drawing Utama</h2>
                            <p class="text-[11px] text-slate-400">Gambar teknis 2D (PDF) dan Model 3D Solid CAD (.STP/.STEP)</p>
                        </div>
                    </div>
                    <span class="text-[10px] font-mono px-2 py-0.5 rounded bg-slate-100 text-slate-600 border border-slate-200 hidden md:inline-block">
                        Folder: <?= htmlspecialchars($expectedFolder) ?>
                    </span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    
                    <!-- 1. File 2D PDF -->
                    <div class="rounded-xl border border-blue-200/80 bg-blue-50/20 p-4 flex flex-col justify-between space-y-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="w-2.5 h-2.5 rounded-full bg-blue-600"></span>
                                <span class="text-xs font-bold text-slate-800">Gambar 2D (.PDF)</span>
                            </div>
                            <?php if (!empty($drawing['drawing_2d_path'])): ?>
                                <span class="badge badge-success text-[10px] py-0.5 px-2">Tersedia</span>
                            <?php else: ?>
                                <span class="badge badge-secondary text-[10px] py-0.5 px-2">Belum Ada</span>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($drawing['drawing_2d_path'])): ?>
                            <div class="flex items-center justify-between p-2.5 bg-white border border-blue-200 rounded-lg text-xs">
                                <div class="min-w-0 pr-2">
                                    <p class="text-[11px] font-mono font-bold text-slate-800 truncate" title="<?= htmlspecialchars(basename($drawing['drawing_2d_path'])) ?>">
                                        <?= htmlspecialchars(basename($drawing['drawing_2d_path'])) ?>
                                    </p>
                                    <p class="text-[10px] text-slate-400">Berkas aktif saat ini</p>
                                </div>
                                <a href="<?= base_url($drawing['drawing_2d_path']) ?>" target="_blank" class="px-2.5 py-1 bg-blue-50 text-blue-700 hover:bg-blue-100 rounded-md text-[11px] font-bold transition-colors flex-shrink-0">
                                    Pratinjau
                                </a>
                            </div>
                        <?php endif; ?>

                        <!-- Custom Modern Dropzone -->
                        <div class="dropzone-box group relative border-2 border-dashed border-blue-300 hover:border-blue-500 bg-white hover:bg-blue-50/40 rounded-xl p-4 text-center cursor-pointer transition-all">
                            <input type="file" name="file_2d" id="file_2d" accept=".pdf" class="sr-only file-drop-input pdf-compress-input" data-doc-label="Gambar 2D">
                            <div class="dropzone-empty space-y-1.5">
                                <svg class="w-8 h-8 mx-auto text-blue-400 group-hover:text-blue-600 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                                <p class="text-xs font-bold text-slate-700">Pilih atau Drag file 2D (.PDF)</p>
                                <p class="text-[10px] text-slate-400">Otomatis dikompresi di browser</p>
                            </div>
                            <div class="dropzone-selected hidden space-y-1">
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-blue-100 text-blue-800 text-[11px] font-bold">
                                    <svg class="w-3.5 h-3.5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    <span class="file-name-label truncate max-w-[180px]">file.pdf</span>
                                </span>
                                <p class="text-[10px] text-slate-400 file-size-label">0 KB</p>
                            </div>
                        </div>
                    </div>

                    <!-- 2. File 3D STP -->
                    <div class="rounded-xl border border-slate-200/90 bg-slate-50/30 p-4 flex flex-col justify-between space-y-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="w-2.5 h-2.5 rounded-full bg-slate-600"></span>
                                <span class="text-xs font-bold text-slate-800">Model 3D (.STP / .STEP)</span>
                            </div>
                            <?php if (!empty($drawing['drawing_3d_path'])): ?>
                                <span class="badge badge-success text-[10px] py-0.5 px-2">Tersedia</span>
                            <?php else: ?>
                                <span class="badge badge-secondary text-[10px] py-0.5 px-2">Belum Ada</span>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($drawing['drawing_3d_path'])): ?>
                            <div class="flex items-center justify-between p-2.5 bg-white border border-slate-200 rounded-lg text-xs">
                                <div class="min-w-0 pr-2">
                                    <p class="text-[11px] font-mono font-bold text-slate-800 truncate" title="<?= htmlspecialchars(basename($drawing['drawing_3d_path'])) ?>">
                                        <?= htmlspecialchars(basename($drawing['drawing_3d_path'])) ?>
                                    </p>
                                    <p class="text-[10px] text-slate-400">Tersedia di Viewer Interaktif</p>
                                </div>
                                <span class="px-2 py-1 bg-slate-100 text-slate-600 rounded-md text-[10px] font-bold flex-shrink-0">
                                    Aktif
                                </span>
                            </div>
                        <?php endif; ?>

                        <!-- Custom Modern Dropzone -->
                        <div class="dropzone-box group relative border-2 border-dashed border-slate-300 hover:border-slate-500 bg-white hover:bg-slate-50 rounded-xl p-4 text-center cursor-pointer transition-all">
                            <input type="file" name="file_3d" id="file_3d" accept=".stp,.step" class="sr-only file-drop-input" data-doc-label="Model 3D">
                            <div class="dropzone-empty space-y-1.5">
                                <svg class="w-8 h-8 mx-auto text-slate-400 group-hover:text-slate-600 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                <p class="text-xs font-bold text-slate-700">Pilih atau Drag file 3D (.STP/.STEP)</p>
                                <p class="text-[10px] text-slate-400">Format solid 3D CAD</p>
                            </div>
                            <div class="dropzone-selected hidden space-y-1">
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-slate-200 text-slate-800 text-[11px] font-bold">
                                    <svg class="w-3.5 h-3.5 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    <span class="file-name-label truncate max-w-[180px]">file.stp</span>
                                </span>
                                <p class="text-[10px] text-slate-400 file-size-label">0 KB</p>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- SECTION 2: QC DOCUMENTS & STANDARDS -->
            <div class="card p-5 bg-white border border-slate-200/80 rounded-2xl shadow-xs space-y-4">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <div class="flex items-center gap-2">
                        <div class="w-7 h-7 rounded-lg bg-emerald-100 text-emerald-700 flex items-center justify-center">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414A1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        </div>
                        <div>
                            <h2 class="text-xs font-extrabold uppercase tracking-wider text-slate-800">Dokumen Kontrol Mutu & Standar (.PDF)</h2>
                            <p class="text-[11px] text-slate-400">Acuan inspeksi workbench: PI, Q-Point, PAC, dan Standar Packing</p>
                        </div>
                    </div>
                    <span class="text-[10px] font-mono px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 border border-emerald-200 hidden md:inline-block">
                        uploads/parts/<?= htmlspecialchars($part['part_code']) ?>/
                    </span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    
                    <!-- 1. Process Inspection (PI) -->
                    <?php $piInfo = $qcDocs['pi'] ?? null; ?>
                    <div class="rounded-xl border border-indigo-200/80 bg-indigo-50/20 p-4 flex flex-col justify-between space-y-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="w-2.5 h-2.5 rounded-full bg-indigo-600"></span>
                                <span class="text-xs font-bold text-slate-800">Process Inspection (PI)</span>
                            </div>
                            <?php if (!empty($piInfo['has_document'])): ?>
                                <span class="badge badge-success text-[10px] py-0.5 px-2"><?= htmlspecialchars($piInfo['latest']['version_label_full'] ?? 'Ada') ?></span>
                            <?php else: ?>
                                <span class="badge badge-secondary text-[10px] py-0.5 px-2">Belum Ada</span>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($piInfo['has_document']) && !empty($piInfo['latest']['relative_url'])): ?>
                            <div class="flex items-center justify-between p-2.5 bg-white border border-indigo-200 rounded-lg text-xs">
                                <div class="min-w-0 pr-2">
                                    <p class="text-[11px] font-mono font-bold text-slate-800 truncate" title="<?= htmlspecialchars($piInfo['latest']['filename']) ?>">
                                        <?= htmlspecialchars($piInfo['latest']['filename']) ?>
                                    </p>
                                    <p class="text-[10px] text-slate-400">Berkas aktif saat ini</p>
                                </div>
                                <a href="<?= base_url($piInfo['latest']['relative_url']) ?>" target="_blank" class="px-2.5 py-1 bg-indigo-50 text-indigo-700 hover:bg-indigo-100 rounded-md text-[11px] font-bold transition-colors flex-shrink-0">
                                    Pratinjau
                                </a>
                            </div>
                        <?php endif; ?>

                        <!-- Custom Modern Dropzone -->
                        <div class="dropzone-box group relative border-2 border-dashed border-indigo-300 hover:border-indigo-500 bg-white hover:bg-indigo-50/40 rounded-xl p-3.5 text-center cursor-pointer transition-all">
                            <input type="file" name="file_pi" id="file_pi" accept=".pdf" class="sr-only file-drop-input pdf-compress-input" data-doc-label="Process Inspection (PI)">
                            <div class="dropzone-empty space-y-1">
                                <svg class="w-7 h-7 mx-auto text-indigo-400 group-hover:text-indigo-600 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414A1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                <p class="text-xs font-bold text-slate-700">Pilih atau Drag file PI (.PDF)</p>
                                <p class="text-[10px] text-slate-400">Kompresi client-side otomatis</p>
                            </div>
                            <div class="dropzone-selected hidden space-y-1">
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-indigo-100 text-indigo-800 text-[11px] font-bold">
                                    <svg class="w-3.5 h-3.5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    <span class="file-name-label truncate max-w-[180px]">file.pdf</span>
                                </span>
                                <p class="text-[10px] text-slate-400 file-size-label">0 KB</p>
                            </div>
                        </div>
                    </div>

                    <!-- 2. Q-Point -->
                    <?php $qpInfo = $qcDocs['qpoint'] ?? null; ?>
                    <div class="rounded-xl border border-amber-200/80 bg-amber-50/20 p-4 flex flex-col justify-between space-y-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="w-2.5 h-2.5 rounded-full bg-amber-600"></span>
                                <span class="text-xs font-bold text-slate-800">Quality Point (Q-Point)</span>
                            </div>
                            <?php if (!empty($qpInfo['has_document'])): ?>
                                <span class="badge badge-success text-[10px] py-0.5 px-2"><?= htmlspecialchars($qpInfo['latest']['version_label_full'] ?? 'Ada') ?></span>
                            <?php else: ?>
                                <span class="badge badge-secondary text-[10px] py-0.5 px-2">Belum Ada</span>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($qpInfo['has_document']) && !empty($qpInfo['latest']['relative_url'])): ?>
                            <div class="flex items-center justify-between p-2.5 bg-white border border-amber-200 rounded-lg text-xs">
                                <div class="min-w-0 pr-2">
                                    <p class="text-[11px] font-mono font-bold text-slate-800 truncate" title="<?= htmlspecialchars($qpInfo['latest']['filename']) ?>">
                                        <?= htmlspecialchars($qpInfo['latest']['filename']) ?>
                                    </p>
                                    <p class="text-[10px] text-slate-400">Berkas aktif saat ini</p>
                                </div>
                                <a href="<?= base_url($qpInfo['latest']['relative_url']) ?>" target="_blank" class="px-2.5 py-1 bg-amber-50 text-amber-800 hover:bg-amber-100 rounded-md text-[11px] font-bold transition-colors flex-shrink-0">
                                    Pratinjau
                                </a>
                            </div>
                        <?php endif; ?>

                        <!-- Custom Modern Dropzone -->
                        <div class="dropzone-box group relative border-2 border-dashed border-amber-300 hover:border-amber-500 bg-white hover:bg-amber-50/40 rounded-xl p-3.5 text-center cursor-pointer transition-all">
                            <input type="file" name="file_qpoint" id="file_qpoint" accept=".pdf" class="sr-only file-drop-input pdf-compress-input" data-doc-label="Quality Point (Q-Point)">
                            <div class="dropzone-empty space-y-1">
                                <svg class="w-7 h-7 mx-auto text-amber-500 group-hover:text-amber-600 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                <p class="text-xs font-bold text-slate-700">Pilih atau Drag file Q-Point (.PDF)</p>
                                <p class="text-[10px] text-slate-400">Kompresi client-side otomatis</p>
                            </div>
                            <div class="dropzone-selected hidden space-y-1">
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-amber-100 text-amber-900 text-[11px] font-bold">
                                    <svg class="w-3.5 h-3.5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    <span class="file-name-label truncate max-w-[180px]">file.pdf</span>
                                </span>
                                <p class="text-[10px] text-slate-400 file-size-label">0 KB</p>
                            </div>
                        </div>
                    </div>

                    <!-- 3. PAC -->
                    <?php $pacInfo = $qcDocs['pac'] ?? null; ?>
                    <div class="rounded-xl border border-emerald-200/80 bg-emerald-50/20 p-4 flex flex-col justify-between space-y-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="w-2.5 h-2.5 rounded-full bg-emerald-600"></span>
                                <span class="text-xs font-bold text-slate-800">Part Approval Certificate (PAC)</span>
                            </div>
                            <?php if (!empty($pacInfo['has_document'])): ?>
                                <span class="badge badge-success text-[10px] py-0.5 px-2"><?= htmlspecialchars($pacInfo['latest']['version_label_full'] ?? 'Ada') ?></span>
                            <?php else: ?>
                                <span class="badge badge-secondary text-[10px] py-0.5 px-2">Belum Ada</span>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($pacInfo['has_document']) && !empty($pacInfo['latest']['relative_url'])): ?>
                            <div class="flex items-center justify-between p-2.5 bg-white border border-emerald-200 rounded-lg text-xs">
                                <div class="min-w-0 pr-2">
                                    <p class="text-[11px] font-mono font-bold text-slate-800 truncate" title="<?= htmlspecialchars($pacInfo['latest']['filename']) ?>">
                                        <?= htmlspecialchars($pacInfo['latest']['filename']) ?>
                                    </p>
                                    <p class="text-[10px] text-slate-400">Berkas aktif saat ini</p>
                                </div>
                                <a href="<?= base_url($pacInfo['latest']['relative_url']) ?>" target="_blank" class="px-2.5 py-1 bg-emerald-50 text-emerald-800 hover:bg-emerald-100 rounded-md text-[11px] font-bold transition-colors flex-shrink-0">
                                    Pratinjau
                                </a>
                            </div>
                        <?php endif; ?>

                        <!-- Custom Modern Dropzone -->
                        <div class="dropzone-box group relative border-2 border-dashed border-emerald-300 hover:border-emerald-500 bg-white hover:bg-emerald-50/40 rounded-xl p-3.5 text-center cursor-pointer transition-all">
                            <input type="file" name="file_pac" id="file_pac" accept=".pdf" class="sr-only file-drop-input pdf-compress-input" data-doc-label="PAC">
                            <div class="dropzone-empty space-y-1">
                                <svg class="w-7 h-7 mx-auto text-emerald-500 group-hover:text-emerald-600 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/></svg>
                                <p class="text-xs font-bold text-slate-700">Pilih atau Drag file PAC (.PDF)</p>
                                <p class="text-[10px] text-slate-400">Kompresi client-side otomatis</p>
                            </div>
                            <div class="dropzone-selected hidden space-y-1">
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-100 text-emerald-900 text-[11px] font-bold">
                                    <svg class="w-3.5 h-3.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    <span class="file-name-label truncate max-w-[180px]">file.pdf</span>
                                </span>
                                <p class="text-[10px] text-slate-400 file-size-label">0 KB</p>
                            </div>
                        </div>
                    </div>

                    <!-- 4. Standar Packing -->
                    <?php $spInfo = $qcDocs['standar_packing'] ?? null; ?>
                    <div class="rounded-xl border border-orange-200/80 bg-orange-50/20 p-4 flex flex-col justify-between space-y-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="w-2.5 h-2.5 rounded-full bg-orange-600"></span>
                                <span class="text-xs font-bold text-slate-800">Standar Packing</span>
                            </div>
                            <?php if (!empty($spInfo['has_document'])): ?>
                                <span class="badge badge-success text-[10px] py-0.5 px-2"><?= htmlspecialchars($spInfo['latest']['version_label_full'] ?? 'Ada') ?></span>
                            <?php else: ?>
                                <span class="badge badge-secondary text-[10px] py-0.5 px-2">Belum Ada</span>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($spInfo['has_document']) && !empty($spInfo['latest']['relative_url'])): ?>
                            <div class="flex items-center justify-between p-2.5 bg-white border border-orange-200 rounded-lg text-xs">
                                <div class="min-w-0 pr-2">
                                    <p class="text-[11px] font-mono font-bold text-slate-800 truncate" title="<?= htmlspecialchars($spInfo['latest']['filename']) ?>">
                                        <?= htmlspecialchars($spInfo['latest']['filename']) ?>
                                    </p>
                                    <p class="text-[10px] text-slate-400">Berkas aktif saat ini</p>
                                </div>
                                <a href="<?= base_url($spInfo['latest']['relative_url']) ?>" target="_blank" class="px-2.5 py-1 bg-orange-50 text-orange-800 hover:bg-orange-100 rounded-md text-[11px] font-bold transition-colors flex-shrink-0">
                                    Pratinjau
                                </a>
                            </div>
                        <?php endif; ?>

                        <!-- Custom Modern Dropzone -->
                        <div class="dropzone-box group relative border-2 border-dashed border-orange-300 hover:border-orange-500 bg-white hover:bg-orange-50/40 rounded-xl p-3.5 text-center cursor-pointer transition-all">
                            <input type="file" name="file_standar_packing" id="file_standar_packing" accept=".pdf" class="sr-only file-drop-input pdf-compress-input" data-doc-label="Standar Packing">
                            <div class="dropzone-empty space-y-1">
                                <svg class="w-7 h-7 mx-auto text-orange-500 group-hover:text-orange-600 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                <p class="text-xs font-bold text-slate-700">Pilih atau Drag file Packing (.PDF)</p>
                                <p class="text-[10px] text-slate-400">Kompresi client-side otomatis</p>
                            </div>
                            <div class="dropzone-selected hidden space-y-1">
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-orange-100 text-orange-900 text-[11px] font-bold">
                                    <svg class="w-3.5 h-3.5 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    <span class="file-name-label truncate max-w-[180px]">file.pdf</span>
                                </span>
                                <p class="text-[10px] text-slate-400 file-size-label">0 KB</p>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Action Bar -->
            <div class="card p-4 bg-white border border-slate-200/80 rounded-2xl shadow-xs flex items-center justify-between gap-3">
                <a href="<?= base_url('modules/master_drawings/index.php') ?>" class="btn-secondary py-2 px-4 text-xs font-semibold rounded-xl">
                    Batal
                </a>
                <button type="submit" id="btn-submit-upload" class="btn-primary py-2 px-6 text-xs font-bold rounded-xl inline-flex items-center gap-2 shadow-xs hover:shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    <span>Simpan Berkas Dokumen</span>
                </button>
            </div>

        </form>

    </main>

    <!-- Client-Side PDF Compression Modal Overlay -->
    <div id="compression-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-2xs z-50 flex items-center justify-center p-4 hidden">
        <div class="bg-white rounded-2xl shadow-xl border border-slate-200 max-w-md w-full p-6 text-center space-y-4">
            <div class="w-12 h-12 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center mx-auto">
                <svg class="w-6 h-6 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                </svg>
            </div>
            <div>
                <h4 class="text-sm font-extrabold text-slate-900" id="compress-modal-title">Mengompresi Berkas PDF...</h4>
                <p class="text-xs text-slate-500 mt-1" id="compress-modal-desc">Memproses dan mengoptimasi halaman dokumen di browser sebelum diunggah.</p>
            </div>
            <div class="w-full bg-slate-100 rounded-full h-2.5 overflow-hidden">
                <div id="compress-progress-bar" class="bg-blue-600 h-2.5 rounded-full transition-all duration-200" style="width: 5%;"></div>
            </div>
            <p class="text-[11px] font-mono font-bold text-slate-600 truncate" id="compress-modal-status">Menyiapkan berkas...</p>
        </div>
    </div>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>

<!-- Local PDF Processing Engines (100% Offline) -->
<script src="<?= base_url('assets/js/vendor/pdf.min.js') ?>"></script>
<script src="<?= base_url('assets/js/vendor/pdf-lib.min.js') ?>"></script>
<script src="<?= base_url('assets/js/modules/pdf_compressor.js') ?>"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var form = document.getElementById('form-upload-drawings');
    var btnSubmit = document.getElementById('btn-submit-upload');
    var compModal = document.getElementById('compression-modal');
    var compBar = document.getElementById('compress-progress-bar');
    var compStatus = document.getElementById('compress-modal-status');
    var compTitle = document.getElementById('compress-modal-title');

    // Interactive Dropzone Event Handlers
    function formatBytes(bytes) {
        if (!bytes || bytes === 0) return '0 Bytes';
        var k = 1024;
        var sizes = ['Bytes', 'KB', 'MB', 'GB'];
        var i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }

    var dropboxes = document.querySelectorAll('.dropzone-box');
    dropboxes.forEach(function(box) {
        var input = box.querySelector('.file-drop-input');
        var emptyState = box.querySelector('.dropzone-empty');
        var selectedState = box.querySelector('.dropzone-selected');
        var nameLabel = box.querySelector('.file-name-label');
        var sizeLabel = box.querySelector('.file-size-label');

        if (!input) return;

        // Click on dropzone to trigger input
        box.addEventListener('click', function(e) {
            if (e.target !== input) {
                input.click();
            }
        });

        // Drag & drop highlight
        ['dragenter', 'dragover'].forEach(function(eventName) {
            box.addEventListener(eventName, function(e) {
                e.preventDefault();
                e.stopPropagation();
                box.classList.add('ring-2', 'ring-blue-500', 'bg-blue-50/50');
            });
        });

        ['dragleave', 'drop'].forEach(function(eventName) {
            box.addEventListener(eventName, function(e) {
                e.preventDefault();
                e.stopPropagation();
                box.classList.remove('ring-2', 'ring-blue-500', 'bg-blue-50/50');
            });
        });

        // Drop event
        box.addEventListener('drop', function(e) {
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                input.files = e.dataTransfer.files;
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });

        // Input change
        input.addEventListener('change', function() {
            if (input.files && input.files[0]) {
                var file = input.files[0];
                if (nameLabel) nameLabel.textContent = file.name;
                if (sizeLabel) sizeLabel.textContent = formatBytes(file.size);
                if (emptyState) emptyState.classList.add('hidden');
                if (selectedState) selectedState.classList.remove('hidden');
            } else {
                if (emptyState) emptyState.classList.remove('hidden');
                if (selectedState) selectedState.classList.add('hidden');
            }
        });
    });

    form.addEventListener('submit', async function(e) {
        e.preventDefault();

        var pdfInputs = form.querySelectorAll('.pdf-compress-input');
        var filesToProcess = [];

        pdfInputs.forEach(function(input) {
            if (input.files && input.files[0]) {
                filesToProcess.push({
                    input: input,
                    file: input.files[0],
                    docLabel: input.getAttribute('data-doc-label') || 'Dokumen'
                });
            }
        });

        // If no PDF files are selected (or only 3D STP is selected), submit directly
        if (filesToProcess.length === 0) {
            form.submit();
            return;
        }

        // Show compression modal
        compModal.classList.remove('hidden');
        btnSubmit.disabled = true;

        var formData = new FormData(form);

        try {
            for (var i = 0; i < filesToProcess.length; i++) {
                var item = filesToProcess[i];
                compTitle.textContent = 'Mengompresi ' + item.docLabel + ' (' + (i + 1) + '/' + filesToProcess.length + ')...';

                var compressedFile = await window.OqcPdfCompressor.compressPdfFile(
                    item.file,
                    function(percent, text) {
                        compBar.style.width = percent + '%';
                        compStatus.textContent = text;
                    }
                );

                // Replace the file inside FormData payload
                formData.set(item.input.name, compressedFile);
            }

            compTitle.textContent = 'Mengunggah berkas ke server...';
            compBar.style.width = '100%';
            compStatus.textContent = 'Menyimpan berkas ke direktori part...';

            // Submit via fetch to support compressed Blob/File payload smoothly
            var response = await fetch(form.action, {
                method: 'POST',
                body: formData
            });

            if (response.redirected) {
                window.location.href = response.url;
            } else {
                window.location.href = '<?= base_url("modules/master_drawings/detail.php?part_id=" . $part["id"]) ?>';
            }
        } catch (err) {
            console.error('Error saat kompresi/upload PDF:', err);
            compModal.classList.add('hidden');
            btnSubmit.disabled = false;
            alert('Terjadi kesalahan saat memproses dokumen: ' + (err.message || err));
        }
    });
});
</script>

