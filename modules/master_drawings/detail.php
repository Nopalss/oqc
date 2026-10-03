<?php
$breadcrumbCategory = "MASTER DATA";
$pageTitle = "Drawing Viewer (2D & 3D)";
$pageSubtitle = "Pratinjau acuan gambar teknik 2D (PDF) dan Tampilan Interactive 3D (.STP)";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

$part_id = (int)($_GET['part_id'] ?? 0);
$pdo = getDB();
$part = null;
$drawing = null;

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

            // Priority: Resolve files directly from physical folders
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
                        <?= (!empty($drawing['drawing_2d_path']) || !empty($drawing['drawing_3d_path'])) ? 'Ganti File Berkas' : 'Upload Berkas Drawing' ?>
                    </a>
                </div>

            </div>
        </div>

        <!-- 2D PDF Drawing Viewer Card (Full Width Landscape Format) -->
        <div class="card p-3 md:p-4 bg-white border border-slate-200/80 rounded-xl shadow-xs space-y-3">
            <div class="flex items-center justify-between border-b border-slate-100 pb-2.5 flex-wrap gap-2">
                <div class="flex items-center gap-2">
                    <h3 class="font-bold text-xs text-slate-800 flex items-center">
                        <svg class="w-4 h-4 mr-1.5 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                        </svg>
                        Gambar Teknik 2D (PDF Document)
                    </h3>
                    <?php if (!empty($drawing['drawing_2d_path'])): ?>
                        <span class="text-[10px] font-mono text-slate-500 bg-slate-100 px-2 py-0.5 rounded border border-slate-200 truncate max-w-[200px]">
                            <?= htmlspecialchars(basename($drawing['drawing_2d_path'])) ?>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="flex items-center gap-2">
                    <?php if (!empty($drawing['drawing_2d_path'])): ?>
                        <a href="<?= base_url($drawing['drawing_2d_path']) ?>" download class="btn-primary py-1 px-3 text-xs flex items-center font-bold">
                            <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                            </svg>
                            Download PDF
                        </a>
                        <a href="<?= base_url($drawing['drawing_2d_path']) ?>" target="_blank" class="btn-secondary py-1 px-2.5 text-xs font-semibold">
                            Buka Layar Penuh &nearr;
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="w-full bg-slate-900 rounded-xl border border-slate-800 overflow-hidden" style="height: 560px;">
                <?php if (!empty($drawing['drawing_2d_path'])): ?>
                    <iframe src="<?= base_url($drawing['drawing_2d_path']) ?>" style="width: 100%; height: 100%; border: none;"></iframe>
                <?php else: ?>
                    <div class="flex flex-col items-center justify-center h-full text-slate-400 p-6 text-center">
                        <svg class="w-12 h-12 mb-2 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 01-2-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <p class="text-xs font-medium">Gambar 2D (.PDF) belum diunggah atau tidak ditemukan di folder part ini.</p>
                        <a href="<?= base_url('modules/master_drawings/upload.php?part_id=' . $part['id']) ?>" class="btn-primary py-1.5 px-3 text-xs mt-3">
                            + Upload Drawing 2D
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 3D STP CAD Model Container Card (Interactive WebGL Canvas) -->
        <div class="card p-3 md:p-4 bg-white border border-slate-200/80 rounded-xl shadow-xs space-y-3">
            <div class="flex items-center justify-between border-b border-slate-100 pb-2.5 flex-wrap gap-2">
                <div class="flex items-center gap-2">
                    <h3 class="font-bold text-xs text-slate-800 flex items-center">
                        <svg class="w-4 h-4 mr-1.5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                        </svg>
                        Interactive 3D WebGL Model View (.STP CAD)
                    </h3>
                    <?php if (!empty($drawing['drawing_3d_path'])): ?>
                        <span class="text-[10px] font-mono text-slate-500 bg-slate-100 px-2 py-0.5 rounded border border-slate-200 truncate max-w-[200px]">
                            <?= htmlspecialchars(basename($drawing['drawing_3d_path'])) ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="flex items-center gap-2">
                    <?php if (!empty($drawing['drawing_3d_path'])): ?>
                        <span class="badge badge-success">WebGL 3D Active</span>
                        <a href="<?= base_url($drawing['drawing_3d_path']) ?>" download class="btn-primary py-1 px-3 text-xs flex items-center font-bold">
                            <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                            </svg>
                            Download .STP
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="w-full bg-slate-900 rounded-xl border border-slate-800 overflow-hidden" style="height: 480px; position: relative;">
                <?php if (!empty($drawing['drawing_3d_path'])): ?>
                    <!-- WebGL Canvas Container -->
                    <div id="cad-3d-viewport" data-stp-url="<?= base_url($drawing['drawing_3d_path']) ?>" style="width: 100%; height: 100%;"></div>
                <?php else: ?>
                    <div class="flex flex-col items-center justify-center h-full text-slate-400 p-6 text-center">
                        <svg class="w-10 h-10 mb-2 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                        </svg>
                        <p class="text-xs font-medium">Gambar 3D (.STP) belum diunggah atau tidak ditemukan di folder part ini.</p>
                        <a href="<?= base_url('modules/master_drawings/upload.php?part_id=' . $part['id']) ?>" class="btn-primary py-1.5 px-3 text-xs mt-3">
                            + Upload Drawing 3D (.STP)
                        </a>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($drawing['drawing_3d_path'])): ?>
                <div class="text-[11px] text-slate-400 flex items-center justify-between px-1">
                    <span><b>Petunjuk Navigasi 3D</b>: Klik &amp; drag mouse untuk memutar model (Rotate). Scroll mouse untuk Zoom in/out. Klik kanan &amp; drag untuk menggeser (Pan).</span>
                </div>
            <?php endif; ?>
        </div>

    </main>

    <?php if (!empty($drawing['drawing_3d_path'])): ?>
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        if (typeof renderCadStep === "function") {
            renderCadStep("cad-3d-viewport", "<?= base_url($drawing['drawing_3d_path']) ?>");
        }
    });
    </script>
    <?php endif; ?>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>

