<?php
$breadcrumbCategory = "MASTER DATA";
$pageTitle = "Upload / Update Drawing (2D & 3D)";
$pageSubtitle = "Unggah file gambar 2D (PDF) atau 3D (.STP) untuk part pilihan";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

$part_id = filter_input(INPUT_GET, 'part_id', FILTER_VALIDATE_INT);
$pdo = getDB();
$part = null;
$drawing = null;

if ($part_id && $pdo) {
    try {
        $stmtPart = $pdo->prepare("SELECT * FROM master_parts WHERE id = :id");
        $stmtPart->execute([':id' => $part_id]);
        $part = $stmtPart->fetch();

        if ($part) {
            $stmtDraw = $pdo->prepare("SELECT * FROM master_drawings WHERE part_id = :part_id");
            $stmtDraw->execute([':part_id' => $part_id]);
            $drawing = $stmtDraw->fetch();
        }
    } catch (PDOException $e) {
        $part = null;
    }
}

if (!$part) {
    set_flash('error', 'Part tidak ditemukan!');
    redirect('modules/master_drawings/index.php');
}
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-4 md:p-6 space-y-4 max-w-3xl">
        
        <?= render_flash() ?>

        <div class="flex items-center space-x-3 mb-2">
            <a href="<?= base_url('modules/master_drawings/index.php') ?>" class="btn-secondary py-1 px-2.5 text-xs">
                &larr; Kembali
            </a>
            <h2 class="text-sm font-bold text-slate-800">
                Upload File Drawing untuk Part: <span class="text-blue-700 font-mono font-extrabold"><?= htmlspecialchars($part['part_code']) ?></span> (<?= htmlspecialchars($part['part_name']) ?>)
            </h2>
        </div>

        <div class="card">
            <form action="<?= base_url('modules/master_drawings/store.php') ?>" method="POST" enctype="multipart/form-data" class="space-y-6">
                
                <input type="hidden" name="part_id" value="<?= htmlspecialchars($part['id']) ?>">

                <!-- Info Box Partial Upload -->
                <div class="p-3 bg-blue-50 border border-blue-200/80 rounded-xl text-xs text-blue-800">
                    <strong>Catatan Partial Upload (PRD FR-5):</strong> Anda boleh mengunggah salah satu file terlebih dahulu (misal: 2D PDF saja), dan file 3D (.STP) dapat diunggah menyusul kapan saja.
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    
                    <!-- File 2D PDF -->
                    <div class="space-y-2 p-4 bg-slate-50 border border-slate-200/80 rounded-xl">
                        <label class="form-label text-slate-800 flex items-center justify-between">
                            <span>Gambar 2D (Format .PDF)</span>
                            <?php if (!empty($drawing['drawing_2d_path'])): ?>
                                <span class="badge badge-success">File Ada</span>
                            <?php endif; ?>
                        </label>
                        <input type="file" name="file_2d" accept=".pdf" class="form-input text-xs">
                        <?php if (!empty($drawing['drawing_2d_path'])): ?>
                            <p class="text-[10px] text-slate-500 font-mono truncate mt-1">
                                Existing: <?= htmlspecialchars(basename($drawing['drawing_2d_path'])) ?>
                            </p>
                        <?php endif; ?>
                        <p class="text-[10px] text-slate-400">File PDF dokumen teknis 2D.</p>
                    </div>

                    <!-- File 3D STP -->
                    <div class="space-y-2 p-4 bg-slate-50 border border-slate-200/80 rounded-xl">
                        <label class="form-label text-slate-800 flex items-center justify-between">
                            <span>Gambar 3D (Format .STP / .STEP)</span>
                            <?php if (!empty($drawing['drawing_3d_path'])): ?>
                                <span class="badge badge-success">File Ada</span>
                            <?php endif; ?>
                        </label>
                        <input type="file" name="file_3d" accept=".stp,.step" class="form-input text-xs">
                        <?php if (!empty($drawing['drawing_3d_path'])): ?>
                            <p class="text-[10px] text-slate-500 font-mono truncate mt-1">
                                Existing: <?= htmlspecialchars(basename($drawing['drawing_3d_path'])) ?>
                            </p>
                        <?php endif; ?>
                        <p class="text-[10px] text-slate-400">File CAD 3D (.STP atau .STEP).</p>
                    </div>

                </div>

                <!-- Submit Bar -->
                <div class="flex items-center justify-end space-x-2 pt-4 border-t border-slate-200">
                    <a href="<?= base_url('modules/master_drawings/index.php') ?>" class="btn-secondary">Batal</a>
                    <button type="submit" class="btn-primary">Simpan Berkas Drawing</button>
                </div>

            </form>
        </div>

    </main>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
