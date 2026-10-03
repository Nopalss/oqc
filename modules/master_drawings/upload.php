<?php
$breadcrumbCategory = "MASTER DATA";
$pageTitle = "Upload / Update Drawing (2D & 3D)";
$pageSubtitle = "Unggah file gambar 2D (PDF) atau 3D (.STP) untuk part pilihan";

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

    <main class="flex-1 p-3 md:p-6 space-y-4 max-w-3xl">
        
        <?= render_flash() ?>

        <div class="flex items-center space-x-3 mb-2 flex-wrap gap-2">
            <a href="<?= base_url('modules/master_drawings/index.php') ?>" class="btn-secondary py-1 px-2.5 text-xs">
                &larr; Kembali
            </a>
            <h2 class="text-sm font-bold text-slate-800">
                Upload File Drawing untuk Part: <span class="text-blue-700 font-mono font-extrabold"><?= htmlspecialchars($part['part_code']) ?></span> (<?= htmlspecialchars($part['part_name']) ?>)
            </h2>
        </div>

        <div class="card p-4 md:p-6 bg-white border border-slate-200/80 rounded-xl shadow-xs">
            <form action="<?= base_url('modules/master_drawings/store.php') ?>" method="POST" enctype="multipart/form-data" class="space-y-5">
                
                <input type="hidden" name="part_id" value="<?= htmlspecialchars($part['id']) ?>">

                <!-- Folder Destination Info -->
                <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl text-xs space-y-1">
                    <span class="font-bold text-slate-700 block">Lokasi Folder Penyimpanan:</span>
                    <p class="font-mono text-blue-700 text-[11px] break-all">
                        <?= htmlspecialchars($expectedFolder) ?>
                    </p>
                    <p class="text-[10px] text-slate-500">
                        Sistem akan otomatis membuat folder model dan folder part ini jika belum tersedia di server.
                    </p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    
                    <!-- File 2D PDF -->
                    <div class="space-y-2 p-3.5 bg-slate-50 border border-slate-200/80 rounded-xl">
                        <label class="form-label text-slate-800 flex items-center justify-between text-xs font-bold">
                            <span>Gambar 2D (Format .PDF)</span>
                            <?php if (!empty($drawing['drawing_2d_path'])): ?>
                                <span class="badge badge-success">Sudah Ada</span>
                            <?php else: ?>
                                <span class="badge badge-secondary">Belum Ada</span>
                            <?php endif; ?>
                        </label>
                        <input type="file" name="file_2d" accept=".pdf" class="form-input text-xs w-full">
                        <?php if (!empty($drawing['drawing_2d_path'])): ?>
                            <p class="text-[10px] text-slate-600 font-mono truncate mt-1">
                                File saat ini: <?= htmlspecialchars(basename($drawing['drawing_2d_path'])) ?>
                            </p>
                        <?php endif; ?>
                        <p class="text-[10px] text-slate-400">Pilih berkas PDF baru untuk mengunggah atau mengganti berkas 2D.</p>
                    </div>

                    <!-- File 3D STP -->
                    <div class="space-y-2 p-3.5 bg-slate-50 border border-slate-200/80 rounded-xl">
                        <label class="form-label text-slate-800 flex items-center justify-between text-xs font-bold">
                            <span>Gambar 3D (Format .STP / .STEP)</span>
                            <?php if (!empty($drawing['drawing_3d_path'])): ?>
                                <span class="badge badge-success">Sudah Ada</span>
                            <?php else: ?>
                                <span class="badge badge-secondary">Belum Ada</span>
                            <?php endif; ?>
                        </label>
                        <input type="file" name="file_3d" accept=".stp,.step" class="form-input text-xs w-full">
                        <?php if (!empty($drawing['drawing_3d_path'])): ?>
                            <p class="text-[10px] text-slate-600 font-mono truncate mt-1">
                                File saat ini: <?= htmlspecialchars(basename($drawing['drawing_3d_path'])) ?>
                            </p>
                        <?php endif; ?>
                        <p class="text-[10px] text-slate-400">Pilih berkas CAD 3D (.STP atau .STEP) untuk mengunggah atau mengganti berkas 3D.</p>
                    </div>

                </div>

                <!-- Submit Bar -->
                <div class="flex items-center justify-end space-x-2 pt-4 border-t border-slate-200">
                    <a href="<?= base_url('modules/master_drawings/index.php') ?>" class="btn-secondary py-1.5 px-3.5 text-xs">Batal</a>
                    <button type="submit" class="btn-primary py-1.5 px-4 text-xs font-bold">Simpan Berkas Drawing</button>
                </div>

            </form>
        </div>

    </main>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>

